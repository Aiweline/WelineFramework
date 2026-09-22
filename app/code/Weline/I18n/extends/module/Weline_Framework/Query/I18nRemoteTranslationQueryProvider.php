<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\I18n\Service\RemoteCollectTaskService;
use Weline\I18n\Service\RemoteDictionaryAssistService;

/**
 * 远程协助翻译 Query 核（pending / ingest / collect）。
 */
final class I18nRemoteTranslationQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'i18n_remote_translation';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'remoteTranslationPending' => $this->pending($params),
            'remoteTranslationIngest' => $this->ingest($params),
            'remoteTranslationCollectStart' => $this->collectStart($params),
            'remoteTranslationCollectStatus' => $this->collectStatus($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported i18n_remote_translation operation: %{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'i18n_remote_translation',
            'name' => (string)__('远程协助翻译'),
            'description' => (string)__('远程取未译、录入译文、触发词典收集与状态轮询'),
            'module' => 'Weline_I18n',
            'operations' => [
                [
                    'name' => 'remoteTranslationPending',
                    'description' => (string)__('分页取未译词条'),
                    'frontend' => false,
                    'external' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_I18n::rest_v1_remote_translation_pending',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true],
                        ['name' => 'locales', 'type' => 'list', 'required' => true],
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                        ['name' => 'cursor', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'remoteTranslationIngest',
                    'description' => (string)__('录入译文（冲突跳过）并 publish'),
                    'frontend' => false,
                    'external' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_I18n::rest_v1_remote_translation_ingest',
                    ],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true],
                        ['name' => 'items', 'type' => 'list', 'required' => true],
                    ],
                ],
                [
                    'name' => 'remoteTranslationCollectStart',
                    'description' => (string)__('启动远程词典收集（禁 AI 入队）'),
                    'frontend' => false,
                    'external' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_I18n::rest_v1_remote_translation_collect_start',
                    ],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'owner_key', 'type' => 'string', 'required' => true],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'remoteTranslationCollectStatus',
                    'description' => (string)__('轮询远程词典收集状态'),
                    'frontend' => false,
                    'external' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_I18n::rest_v1_remote_translation_collect_status',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'task_id', 'type' => 'string', 'required' => true],
                        ['name' => 'owner_key', 'type' => 'string', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function pending(array $params): array
    {
        $locales = $params['locales'] ?? [];
        if (!is_array($locales)) {
            $locales = [];
        }
        $cursor = $params['cursor'] ?? null;
        $cursor = is_string($cursor) ? $cursor : null;

        return $this->assist()->pending(
            (int)($params['website_id'] ?? -1),
            array_map(static fn ($c): string => (string)$c, $locales),
            (int)($params['limit'] ?? RemoteDictionaryAssistService::LIMIT_DEFAULT),
            $cursor,
        );
    }

    /** @param array<string,mixed> $params */
    private function ingest(array $params): array
    {
        $items = $params['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        return $this->assist()->ingest(
            (int)($params['website_id'] ?? -1),
            $items,
        );
    }

    /** @param array<string,mixed> $params */
    private function collectStart(array $params): array
    {
        return $this->collect()->start(
            (string)($params['owner_key'] ?? ''),
            (int)($params['website_id'] ?? 0),
        );
    }

    /** @param array<string,mixed> $params */
    private function collectStatus(array $params): array
    {
        return $this->collect()->status(
            (string)($params['task_id'] ?? ''),
            (string)($params['owner_key'] ?? ''),
        );
    }

    private function assist(): RemoteDictionaryAssistService
    {
        return ObjectManager::getInstance(RemoteDictionaryAssistService::class);
    }

    private function collect(): RemoteCollectTaskService
    {
        return ObjectManager::getInstance(RemoteCollectTaskService::class);
    }
}
