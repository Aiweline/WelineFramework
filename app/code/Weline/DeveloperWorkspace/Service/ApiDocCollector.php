<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service;

use Weline\Api\Api\Documentation\ApiDocumentationProviderInterface;
use Weline\Framework\Event\EventsManager;

class ApiDocCollector
{
    public const EVENT_COLLECT_AFTER = 'Weline_DeveloperWorkspace::api_doc_collect_after';

    public function __construct(
        private readonly ApiDocumentationProviderInterface $apiDocService,
        private readonly EventsManager $eventsManager
    ) {
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function generateAll(bool $force = false): array
    {
        $apis = $this->apiDocService->generateAll($force);
        if (!\is_array($apis)) {
            $apis = [];
        }

        $payload = [
            'apis' => $apis,
            'force' => $force,
            'source' => 'developer_workspace',
        ];
        $this->eventsManager->dispatch(self::EVENT_COLLECT_AFTER, $payload);

        return \is_array($payload['apis'] ?? null) ? $payload['apis'] : $apis;
    }
}
