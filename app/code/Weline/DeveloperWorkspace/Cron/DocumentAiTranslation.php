<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\DeveloperWorkspace\Service\Document\DocumentTranslationTaskService;

class DocumentAiTranslation implements CronTaskInterface
{
    public function __construct(private DocumentTranslationTaskService $taskService)
    {
    }

    public function name(): string
    {
        return 'DeveloperWorkspace document AI translation';
    }

    public function execute_name(): string
    {
        return 'developer_workspace_document_ai_translation';
    }

    public function tip(): string
    {
        return 'Enqueue and process missing or stale DeveloperWorkspace document translations.';
    }

    public function cron_time(): string
    {
        return '*/15 * * * *';
    }

    public function execute(): string
    {
        $enqueue = $this->taskService->enqueueMissingAndStale();
        $run = $this->taskService->processBatch();

        return sprintf(
            'Document AI translation: enqueued=%d skipped=%d processed=%d translated=%d failed=%d blocked=%d',
            (int)($enqueue['created'] ?? 0),
            (int)($enqueue['skipped'] ?? 0),
            (int)($run['processed'] ?? 0),
            (int)($run['translated'] ?? 0),
            (int)($run['failed'] ?? 0),
            (int)($run['blocked'] ?? 0)
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }
}
