<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Async\TaskStatus;

class AiTranslationQueueService
{
    public const QUEUE_CLASS = 'Weline\\I18n\\Queue\\AiTranslateQueue';

    public function __construct(
        private readonly AiTranslationConfig $config
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function enqueueEnabledLocales(string $requestedBy = 'auto', bool $force = false): array
    {
        $queueIds = [];
        foreach ($this->config->getEnabledLocaleCodes() as $localeCode) {
            $queueId = $this->enqueueLocale($localeCode, [], $requestedBy, $force);
            if ($queueId > 0) {
                $queueIds[$localeCode] = $queueId;
            }
        }

        return $queueIds;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function enqueueLocale(
        string $localeCode,
        array $overrides = [],
        string $requestedBy = 'manual',
        bool $force = false,
        bool $deduplicate = true
    ): int {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if (!$this->config->isTranslatableLocale($localeCode)) {
            return 0;
        }

        $bizKey = $this->buildBizKey($localeCode);
        if ($deduplicate && !$force) {
            $existing = $this->getLatestQueueByBizKey($bizKey);
            if ($existing && in_array((string)($existing['status'] ?? ''), [TaskStatus::PENDING, TaskStatus::RUNNING], true)) {
                return (int)($existing['queue_id'] ?? 0);
            }
        }

        $content = array_merge([
            'locale_code' => $localeCode,
            'source_locale' => $this->config->getSourceLocale(),
            'batch_size' => $this->config->getBatchSize($localeCode),
            'strategy' => $this->config->getStrategy($localeCode),
            'publish' => $this->config->shouldAutoPublish(),
            'force' => $force,
            'requested_by' => $requestedBy,
            'manual' => $requestedBy === 'manual' || !empty($overrides['manual']),
        ], $overrides);

        $result = w_query('queue', 'create', [
            'class' => self::QUEUE_CLASS,
            'name' => (string)__('I18n AI翻译 %{1}', [$localeCode]),
            'module' => AiTranslationConfig::MODULE,
            'content' => $content,
            'status' => TaskStatus::PENDING,
            'auto' => true,
            'biz_key' => $bizKey,
        ]);

        $queue = $this->normalizeQueueRow($result);

        return (int)($queue['queue_id'] ?? 0);
    }

    public function enqueueContinuation(string $localeCode, array $currentContent): int
    {
        return $this->enqueueLocale(
            $localeCode,
            [
                'source_locale' => (string)($currentContent['source_locale'] ?? $this->config->getSourceLocale()),
                'batch_size' => (int)($currentContent['batch_size'] ?? $this->config->getBatchSize($localeCode)),
                'strategy' => (string)($currentContent['strategy'] ?? $this->config->getStrategy($localeCode)),
                'publish' => (bool)($currentContent['publish'] ?? $this->config->shouldAutoPublish()),
                'force' => false,
                'manual' => !empty($currentContent['manual']),
            ],
            'continuation',
            false,
            false
        );
    }

    public function buildBizKey(string $localeCode): string
    {
        return 'i18n:ai_translation:' . trim(str_replace('-', '_', $localeCode));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLatestQueueByBizKey(string $bizKey): ?array
    {
        try {
            $row = w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]);
        } catch (\Throwable) {
            return null;
        }

        return $this->normalizeQueueRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeQueueRow(mixed $row): ?array
    {
        if (is_array($row)) {
            return $row;
        }

        if (is_object($row) && method_exists($row, 'getData')) {
            $data = $row->getData();
            return is_array($data) ? $data : null;
        }

        return null;
    }
}
