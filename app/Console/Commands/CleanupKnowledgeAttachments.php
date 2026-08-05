<?php

namespace App\Console\Commands;

use App\Services\KnowledgeAttachmentCleanupService;
use Illuminate\Console\Command;

class CleanupKnowledgeAttachments extends Command
{
    protected $signature = 'knowledge-attachments:cleanup';

    protected $description = 'Clean expired knowledge attachment uploads, drafts, trash, and working files';

    public function handle(KnowledgeAttachmentCleanupService $cleanupService): int
    {
        $result = $cleanupService->cleanup();
        $this->info(sprintf(
            'Knowledge attachment cleanup: %d sessions, %d drafts, %d attachments, %d orphan files.',
            $result['upload_sessions'],
            $result['drafts_trashed'],
            $result['attachments_purged'],
            $result['orphan_files']
        ));

        return self::SUCCESS;
    }
}
