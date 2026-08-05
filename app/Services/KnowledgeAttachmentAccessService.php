<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\KnowledgeAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeAttachmentAccessService
{
    public function __construct(private KnowledgeAttachmentBindingService $bindingService)
    {
    }

    public function signedUrl(
        KnowledgeAttachment $attachment,
        ?string $requestedDisposition = null
    ): string {
        if ($attachment->status !== KnowledgeAttachment::STATUS_READY || $attachment->trashed()) {
            throw new ApiException('附件当前不可用。', 410);
        }

        $disposition = $this->resolveDisposition($attachment, $requestedDisposition);
        $ttl = max(1, (int) config('knowledge_attachments.signed_url_ttl_minutes', 120));

        return URL::temporarySignedRoute(
            'knowledge.attachments.read',
            now()->addMinutes($ttl),
            ['attachmentUuid' => $attachment->uuid, 'disposition' => $disposition]
        );
    }

    public function replacePlaceholders(string $body, int $knowledgeId): string
    {
        $uuids = $this->bindingService->extractUuids($body);
        if ($uuids === []) {
            return $body;
        }

        $attachments = KnowledgeAttachment::query()
            ->where('knowledge_id', $knowledgeId)
            ->where('status', KnowledgeAttachment::STATUS_READY)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy(fn(KnowledgeAttachment $attachment) => strtolower($attachment->uuid));

        return preg_replace_callback(
            $this->bindingService->placeholderPattern(),
            function (array $matches) use ($attachments): string {
                $attachment = $attachments->get(strtolower($matches[1]));
                return $attachment
                    ? $this->signedUrl($attachment)
                    : 'about:blank#attachment-unavailable';
            },
            $body
        ) ?? $body;
    }

    public function stream(Request $request, string $attachmentUuid): Response
    {
        $attachment = KnowledgeAttachment::where('uuid', $attachmentUuid)
            ->where('status', KnowledgeAttachment::STATUS_READY)
            ->first();
        if (!$attachment) {
            throw new ApiException('附件不存在或已失效。', 404);
        }

        $disk = Storage::disk(config('knowledge_attachments.disk'));
        if (!$disk->exists($attachment->storage_path)) {
            throw new ApiException('附件文件不存在。', 404);
        }

        $size = (int) $disk->size($attachment->storage_path);
        if ($size < 1 || $size !== (int) $attachment->size) {
            throw new ApiException('附件文件校验失败。', 410);
        }

        $disposition = $this->resolveDisposition(
            $attachment,
            (string) $request->query('disposition', '')
        );
        $headers = $this->responseHeaders($attachment, $disposition, $size);
        $range = $this->parseRange((string) $request->header('Range', ''), $size);
        if ($range === false) {
            return response('', 416, array_merge($headers, [
                'Content-Range' => 'bytes */' . $size,
                'Content-Length' => '0',
            ]));
        }

        [$start, $end] = $range ?? [0, $size - 1];
        $length = $end - $start + 1;
        $status = $range === null ? 200 : 206;
        $headers['Content-Length'] = (string) $length;
        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        if ($request->isMethod('HEAD')) {
            return response('', $status, $headers);
        }

        $absolutePath = $disk->path($attachment->storage_path);

        return new StreamedResponse(function () use ($absolutePath, $start, $length): void {
            $stream = fopen($absolutePath, 'rb');
            if ($stream === false) {
                return;
            }

            try {
                if ($start > 0 && fseek($stream, $start) !== 0) {
                    return;
                }
                $remaining = $length;
                while ($remaining > 0 && !feof($stream)) {
                    $chunk = fread($stream, min(8192, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }

    public function resolveDisposition(
        KnowledgeAttachment $attachment,
        ?string $requestedDisposition = null
    ): string {
        if (strtolower((string) $requestedDisposition) === 'attachment') {
            return 'attachment';
        }

        $mimeType = strtolower($attachment->mime_type ?: 'application/octet-stream');
        $inlineTypes = array_merge(
            (array) config('knowledge_attachments.inline_image_mime_types', []),
            (array) config('knowledge_attachments.inline_video_mime_types', [])
        );

        return in_array($mimeType, $inlineTypes, true) ? 'inline' : 'attachment';
    }

    private function responseHeaders(
        KnowledgeAttachment $attachment,
        string $disposition,
        int $size
    ): array {
        $originalName = $this->safeOriginalName($attachment->original_name);
        $fallbackName = $this->asciiFallbackName($originalName);
        $contentType = $disposition === 'inline'
            ? strtolower($attachment->mime_type ?: 'application/octet-stream')
            : 'application/octet-stream';

        return [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=300',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $originalName, $fallbackName),
            'Content-Type' => $contentType,
            'Cross-Origin-Resource-Policy' => 'same-site',
            'ETag' => '"' . $attachment->sha256 . '"',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Download-Options' => 'noopen',
            'X-Knowledge-Attachment-Size' => (string) $size,
            'Vary' => 'Range',
        ];
    }

    private function parseRange(string $header, int $size): array|false|null
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (str_contains($header, ',') || preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) !== 1) {
            return false;
        }

        $startValue = $matches[1];
        $endValue = $matches[2];
        if ($startValue === '' && $endValue === '') {
            return false;
        }

        if ($startValue === '') {
            $suffixLength = (int) $endValue;
            if ($suffixLength < 1) {
                return false;
            }
            return [max(0, $size - $suffixLength), $size - 1];
        }

        $start = (int) $startValue;
        if ($start >= $size) {
            return false;
        }
        $end = $endValue === '' ? $size - 1 : min((int) $endValue, $size - 1);
        if ($end < $start) {
            return false;
        }

        return [$start, $end];
    }

    private function safeOriginalName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '_', trim($name)) ?? 'attachment';
        $name = str_replace(['\\', '/'], '_', $name);
        $name = trim($name, ". \t\n\r\0\x0B");
        if ($name === '') {
            return 'attachment';
        }

        return Str::limit($name, 180, '');
    }

    private function asciiFallbackName(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($name)) ?? 'attachment';
        $fallback = trim($fallback, '._-');
        return $fallback !== '' ? Str::limit($fallback, 150, '') : 'attachment';
    }
}
