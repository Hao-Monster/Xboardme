<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use Illuminate\Support\Collection;

class KnowledgeAttachmentBindingService
{
    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function sync(
        Knowledge $knowledge,
        string $body,
        int $uploaderUserId,
        ?string $draftToken
    ): array {
        $uuids = $this->extractUuids($body);
        $draftToken = $draftToken ? strtolower($draftToken) : null;

        /** @var Collection<int, KnowledgeAttachment> $referenced */
        $referenced = $uuids === []
            ? collect()
            : KnowledgeAttachment::withTrashed()
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn(KnowledgeAttachment $attachment) => strtolower($attachment->uuid));

        if ($referenced->count() !== count($uuids)) {
            throw new ApiException('正文引用了不存在的附件。', 422);
        }

        foreach ($uuids as $uuid) {
            /** @var KnowledgeAttachment $attachment */
            $attachment = $referenced->get($uuid);
            $this->assertCanBind($attachment, $knowledge, $uploaderUserId, $draftToken);

            if ($attachment->trashed()) {
                $attachment->restore();
            }
            $attachment->knowledge_id = $knowledge->id;
            $attachment->draft_token = null;
            $attachment->saveOrFail();
        }

        $detached = KnowledgeAttachment::where('knowledge_id', $knowledge->id)
            ->when($uuids !== [], fn($query) => $query->whereNotIn('uuid', $uuids))
            ->lockForUpdate()
            ->get();
        foreach ($detached as $attachment) {
            $attachment->delete();
        }

        return $uuids;
    }

    public function trashForKnowledge(Knowledge $knowledge): int
    {
        $attachments = KnowledgeAttachment::where('knowledge_id', $knowledge->id)
            ->lockForUpdate()
            ->get();
        foreach ($attachments as $attachment) {
            $attachment->delete();
        }

        return $attachments->count();
    }

    public function extractUuids(string $body): array
    {
        $pattern = $this->placeholderPattern();
        preg_match_all($pattern, $body, $matches);

        $bodyWithoutValidPlaceholders = preg_replace($pattern, '', $body);
        if (
            $bodyWithoutValidPlaceholders === null ||
            stripos($bodyWithoutValidPlaceholders, KnowledgeAttachment::URI_PREFIX) !== false
        ) {
            throw new ApiException('正文中的附件占位符格式错误。', 422);
        }

        $uuids = collect($matches[1] ?? [])
            ->map(fn(string $uuid) => strtolower($uuid))
            ->unique()
            ->values()
            ->all();
        $maximum = max(1, (int) config('knowledge_attachments.max_attachments_per_article', 100));
        if (count($uuids) > $maximum) {
            throw new ApiException("单篇知识文章最多允许引用 {$maximum} 个附件。", 422);
        }

        return $uuids;
    }

    /**
     * Repair attachment links which were accidentally wrapped in a second
     * Markdown link by older editor/upload integrations.
     *
     * Example:
     * [download]([download](knowledge-attachment://uuid))
     * becomes:
     * [download](knowledge-attachment://uuid)
     *
     * The expression is deliberately limited to our private attachment URI
     * scheme, so ordinary nested-looking article content is left untouched.
     */
    public function normalizeMarkup(string $body): string
    {
        $uuid = self::UUID_PATTERN;
        $uri = preg_quote(KnowledgeAttachment::URI_PREFIX, '~') . $uuid;
        $label = '[^\\r\\n\\]]*';
        $pattern = '~(?<outer>!?\\[' . $label . '\\])\\(\\s*!?\\[' . $label
            . '\\]\\(\\s*(?<uri>' . $uri . ')\\s*\\)\\s*\\)~i';

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $normalized = preg_replace_callback(
                $pattern,
                static fn(array $matches): string => $matches['outer'] . '(' . $matches['uri'] . ')',
                $body
            );
            if ($normalized === null || $normalized === $body) {
                break;
            }
            $body = $normalized;
        }

        return $body;
    }

    public function placeholderPattern(): string
    {
        return '~' . preg_quote(KnowledgeAttachment::URI_PREFIX, '~')
            . '(' . self::UUID_PATTERN . ')(?=$|[\s)\]}>"\'])~i';
    }

    private function assertCanBind(
        KnowledgeAttachment $attachment,
        Knowledge $knowledge,
        int $uploaderUserId,
        ?string $draftToken
    ): void {
        if ($attachment->status !== KnowledgeAttachment::STATUS_READY) {
            throw new ApiException('正文引用的附件尚未准备完成。', 422);
        }

        if ((int) $attachment->knowledge_id === (int) $knowledge->id) {
            return;
        }

        $ownsDraft = $attachment->knowledge_id === null
            && !$attachment->trashed()
            && (int) $attachment->uploader_user_id === $uploaderUserId
            && $draftToken !== null
            && is_string($attachment->draft_token)
            && hash_equals(strtolower($attachment->draft_token), $draftToken);
        if (!$ownsDraft) {
            throw new ApiException('无权将该附件绑定到当前知识文章。', 422);
        }
    }
}
