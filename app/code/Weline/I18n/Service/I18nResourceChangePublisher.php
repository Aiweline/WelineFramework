<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

final class I18nResourceChangePublisher
{
    public function __construct(
        private readonly LocaleDictionary $dictionary,
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    public function connection(): ConnectionFactory
    {
        return $this->dictionary->getConnection();
    }

    /** @param array<string,mixed> $payload */
    public function publishAction(string $action, array $payload): ResourceChange
    {
        [$resourceType, $resourceId] = $this->identity($action, $payload);
        $locale = $this->locale($payload);
        $summary = [
            'action' => $action,
            'locale' => $locale,
            'word_sha256' => isset($payload['word'])
                ? hash('sha256', (string)$payload['word'])
                : '',
            'item_count' => $this->itemCount($payload),
            'payload_keys' => $this->safePayloadKeys($payload),
        ];
        $revision = $this->revisions->next($resourceType, $resourceId);
        $change = $this->changes->create(
            resourceType: $resourceType,
            resourceId: $resourceId,
            action: 'upsert',
            revision: $revision,
            websiteId: 0,
            websiteCode: 'default',
            before: [],
            after: $summary,
            changedFields: $summary['payload_keys'],
            impact: [
                'namespaces' => [$this->namespacePath->global('i18n', [$locale])],
            ],
            origin: ['entry' => 'i18n.admin.' . $action],
        );
        w_changed($change);
        return $change;
    }

    /** @param array<string,mixed> $payload @return array{0:string,1:string} */
    private function identity(string $action, array $payload): array
    {
        $locale = $this->locale($payload);
        if (str_starts_with($action, 'country-')) {
            return ['i18n_country', $this->codeOrBatch($payload, 'code', $action)];
        }
        if (str_starts_with($action, 'locale-') || str_starts_with($action, 'localization-')) {
            return ['i18n_locale', $this->codeOrBatch($payload, 'code', $action)];
        }
        if (in_array($action, [
            'dictionary-import',
            'dictionary-clear-locale',
            'dictionary-clear-all',
            'dictionary-collect',
            'word-push',
            'ai-export-modules',
        ], true)) {
            return ['i18n_pack', $locale];
        }
        $word = (string)($payload['word'] ?? $payload['md5'] ?? 'batch');
        return ['i18n_dictionary', $locale . '|' . hash('sha256', $word)];
    }

    /** @param array<string,mixed> $payload */
    private function locale(array $payload): string
    {
        $locale = trim((string)($payload['locale_code'] ?? $payload['locale'] ?? $payload['target_code'] ?? 'default'));
        return $locale !== '' ? str_replace('-', '_', $locale) : 'default';
    }

    /** @param array<string,mixed> $payload */
    private function codeOrBatch(array $payload, string $key, string $action): string
    {
        $code = trim((string)($payload[$key] ?? $payload['locale_code'] ?? ''));
        if ($code !== '') {
            return $code;
        }
        return 'batch:' . hash('sha256', $action . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string,mixed> $payload */
    private function itemCount(array $payload): int
    {
        foreach (['items', 'codes', 'words', 'modules'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                return count($payload[$key]);
            }
        }
        return 1;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function safePayloadKeys(array $payload): array
    {
        $blocked = ['password', 'secret', 'token', 'api_key', 'translate', 'translation', 'content', 'csv_content'];
        $keys = [];
        foreach (array_keys($payload) as $key) {
            $key = strtolower(trim((string)$key));
            if ($key !== '' && !in_array($key, $blocked, true)) {
                $keys[$key] = $key;
            }
        }
        $keys = array_values($keys);
        sort($keys, SORT_STRING);
        return $keys === [] ? ['action'] : $keys;
    }
}
