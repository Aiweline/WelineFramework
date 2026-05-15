<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Console\Doc;

use Weline\DeveloperWorkspace\Service\Document\DocumentTranslationTaskService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;

class Translate extends CommandAbstract
{
    public const dir = 'Console\\Doc';

    public function execute(array $args = [], array $data = [])
    {
        /** @var DocumentTranslationTaskService $service */
        $service = ObjectManager::getInstance(DocumentTranslationTaskService::class);
        $action = (string)($args['action'] ?? $args[1] ?? $args[0] ?? 'run');
        if ($action === 'doc:translate') {
            $action = 'run';
        }

        if (isset($args['--scan-adapter'])) {
            $result = $service->scanAdapter();
            $this->printer->printing(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        if ($action === 'enqueue') {
            $result = $service->enqueueMissingAndStale();
        } elseif ($action === 'retry') {
            $result = ['retry_reset' => $service->retryFailed()];
        } elseif ($action === 'status') {
            $result = $service->getOverview();
        } else {
            $enqueue = $service->enqueueMissingAndStale();
            $run = $service->processBatch();
            $result = ['enqueue' => $enqueue, 'run' => $run];
        }

        $this->printer->printing(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function tip(): string
    {
        return __('Manage DeveloperWorkspace document AI translation jobs.');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'doc:translate [run|enqueue|retry|status]',
            $this->tip(),
            [
                '--scan-adapter' => __('Scan AI scenario adapters before running.'),
            ],
            [
                'php bin/w doc:translate run' => __('Enqueue and process a batch.'),
                'php bin/w doc:translate enqueue' => __('Only enqueue missing or stale translations.'),
                'php bin/w doc:translate status' => __('Print translation overview.'),
            ],
            []
        );
    }
}
