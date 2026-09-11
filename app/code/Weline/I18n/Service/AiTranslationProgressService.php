<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;

/**
 * Aggregates per-locale progress for dictionary / LocalModel / module-CSV export.
 * LocalModel has no cheap per-locale COUNT; board uses shared queue status + result parse.
 */
final class AiTranslationProgressService
{
    public const TYPE_DICTIONARY = 'dictionary';
    public const TYPE_LOCAL_MODEL = 'local_model';
    public const TYPE_MODULE_EXPORT = 'module_export';

    public function __construct(
        private readonly LocaleDictionary $localeDictionary,
        private readonly LocalModelTranslationQueueService $localModelQueueService,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $localeRows from AiTranslation::buildLocaleRows
     * @return array{
     *   types: list<array{id:string,label:string}>,
     *   locales: list<array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   local_model: array<string, mixed>
     * }
     */
    public function buildBoard(array $localeRows, int $sourceWordTotal): array
    {
        $sourceWordTotal = max(0, $sourceWordTotal);
        $localeCodes = [];
        foreach ($localeRows as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code !== '') {
                $localeCodes[] = $code;
            }
        }

        $modulePendingByLocale = $this->countModuleExportPendingByLocale($localeCodes);
        $localModel = $this->summarizeLocalModelQueue();

        $types = [
            ['id' => self::TYPE_DICTIONARY, 'label' => (string)__('词典')],
            ['id' => self::TYPE_LOCAL_MODEL, 'label' => (string)__('LocalModel')],
            ['id' => self::TYPE_MODULE_EXPORT, 'label' => (string)__('模块 CSV')],
        ];

        $locales = [];
        $dictDoneSum = 0;
        $dictNeedSum = 0;
        $modulePendingSum = 0;

        foreach ($localeRows as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $isSource = !empty($row['is_source']);
            $translated = (int)($row['translated'] ?? 0);
            $pending = (int)($row['pending'] ?? 0);
            $aiTranslated = (int)($row['ai_translated'] ?? 0);
            $dictTotal = $isSource ? max($translated, $sourceWordTotal) : max($sourceWordTotal, $translated + $pending);
            $dictDone = $isSource ? $dictTotal : max(0, $dictTotal - $pending);
            $dictPct = $this->pct($dictDone, $dictTotal);

            $modulePending = (int)($modulePendingByLocale[$code] ?? 0);
            $modulePendingSum += $modulePending;

            if (!$isSource) {
                $dictDoneSum += $dictDone;
                $dictNeedSum += $dictTotal;
            }

            $queueLabel = $this->humanizeQueueStatus(
                (string)($row['queue_status'] ?? ''),
                (string)($row['queue_result'] ?? ''),
                (string)($row['queue_result_summary'] ?? ''),
            );

            $locales[] = [
                'code' => $code,
                'name' => (string)($row['name'] ?? $code),
                'is_source' => $isSource,
                'enabled' => !empty($row['enabled']),
                'types' => [
                    self::TYPE_DICTIONARY => [
                        'done' => $dictDone,
                        'total' => $dictTotal,
                        'pending' => $isSource ? 0 : $pending,
                        'ai_translated' => $aiTranslated,
                        'pct' => $dictPct,
                        'status' => $isSource ? 'source' : ($pending > 0 ? 'work' : 'done'),
                    ],
                    self::TYPE_LOCAL_MODEL => [
                        'done' => null,
                        'total' => null,
                        'pending' => $localModel['remaining'],
                        'pct' => $localModel['pct'],
                        'status' => $localModel['status'],
                        'shared' => true,
                        'label' => $localModel['label'],
                    ],
                    self::TYPE_MODULE_EXPORT => [
                        'done' => null,
                        'total' => null,
                        'pending' => $modulePending,
                        'pct' => $modulePending > 0 ? 0 : 100,
                        'status' => $modulePending > 0 ? 'work' : 'done',
                    ],
                ],
                'queue' => [
                    'id' => (int)($row['queue_id'] ?? 0),
                    'status' => (string)($row['queue_status'] ?? ''),
                    'is_error' => !empty($row['queue_is_error']),
                    'busy' => $queueLabel['busy'],
                    'label' => $queueLabel['label'],
                    'detail' => $queueLabel['detail'],
                ],
            ];
        }

        $dictOverallPct = $this->pct($dictDoneSum, $dictNeedSum);

        return [
            'types' => $types,
            'locales' => $locales,
            'summary' => [
                'source_words' => $sourceWordTotal,
                'dictionary_pct' => $dictOverallPct,
                'dictionary_done' => $dictDoneSum,
                'dictionary_total' => $dictNeedSum,
                'module_pending_total' => $modulePendingSum,
                'local_model_status' => $localModel['status'],
                'local_model_label' => $localModel['label'],
            ],
            'local_model' => $localModel,
        ];
    }

    /**
     * @return array{label:string,detail:string,busy:bool}
     */
    public function humanizeQueueStatus(string $status, string $result, string $summary = ''): array
    {
        $status = strtolower(trim($status));
        $blob = trim($result !== '' ? $result : $summary);
        $busy = stripos($blob, 'AI_TRANSLATION_BUSY') !== false
            || stripos($blob, '繁忙') !== false;
        $remaining = $this->extractIntAfter($blob, '剩余=');
        $batchOk = $this->extractIntAfter($blob, '本批=');

        if ($busy) {
            return [
                'label' => (string)__('模型忙碌'),
                'detail' => $remaining !== null
                    ? (string)__('词典待补约 %{1}', [(string)$remaining])
                    : (string)__('等待本地模型空闲'),
                'busy' => true,
            ];
        }

        if (in_array($status, ['error', 'failed', 'fail'], true)) {
            return [
                'label' => (string)__('失败'),
                'detail' => $this->shortDetail($summary !== '' ? $summary : $blob),
                'busy' => false,
            ];
        }

        if ($status === 'done' || $status === 'success') {
            if ($remaining !== null && $remaining > 0) {
                return [
                    'label' => (string)__('待续跑'),
                    'detail' => (string)__('剩余 %{1}', [(string)$remaining]),
                    'busy' => false,
                ];
            }
            if ($batchOk !== null && $batchOk > 0) {
                return [
                    'label' => (string)__('本轮完成'),
                    'detail' => (string)__('本批 %{1}', [(string)$batchOk]),
                    'busy' => false,
                ];
            }

            return [
                'label' => (string)__('空闲'),
                'detail' => '',
                'busy' => false,
            ];
        }

        if (in_array($status, ['pending', 'running', 'processing', 'process'], true)) {
            return [
                'label' => (string)__('运行中'),
                'detail' => $remaining !== null
                    ? (string)__('剩余 %{1}', [(string)$remaining])
                    : '',
                'busy' => false,
            ];
        }

        if ($status === '' && $blob === '') {
            return [
                'label' => (string)__('无队列'),
                'detail' => '',
                'busy' => false,
            ];
        }

        return [
            'label' => $status !== '' ? $status : (string)__('未知'),
            'detail' => $this->shortDetail($summary !== '' ? $summary : $blob),
            'busy' => $busy,
        ];
    }

    /**
     * @param list<string> $localeCodes
     * @return array<string, int>
     */
    private function countModuleExportPendingByLocale(array $localeCodes): array
    {
        $out = [];
        foreach ($localeCodes as $code) {
            $out[$code] = 0;
        }
        if ($localeCodes === []) {
            return $out;
        }

        try {
            $rows = $this->localeDictionary->clear()->reset()
                ->fields(LocaleDictionary::schema_fields_LOCALE_CODE . ', COUNT(*) AS cnt')
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCodes, 'IN')
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->where(LocaleDictionary::schema_fields_EXPORTED_AT, null)
                ->where(LocaleDictionary::schema_fields_SOURCE_MODULE, '', '!=')
                ->group(LocaleDictionary::schema_fields_LOCALE_CODE)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return $out;
        }

        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[LocaleDictionary::schema_fields_LOCALE_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = (int)($row['cnt'] ?? 0);
        }

        return $out;
    }

    /**
     * @return array{status:string,label:string,remaining:?int,pct:?int,queue_id:int,busy:bool}
     */
    private function summarizeLocalModelQueue(): array
    {
        $queueId = 0;
        $status = '';
        $result = '';
        try {
            $queueId = $this->localModelQueueService->findActiveFamilyQueueId();
            $latest = $this->localModelQueueService->getLatestFamilyQueue();
            if (is_array($latest)) {
                if ($queueId <= 0) {
                    $queueId = (int)($latest['queue_id'] ?? $latest['id'] ?? 0);
                }
                $status = strtolower(trim((string)($latest['status'] ?? '')));
                $result = trim((string)($latest['result'] ?? ''));
            }
        } catch (\Throwable) {
        }

        $remaining = $this->extractIntAfter($result, '剩余=');
        if ($remaining === null) {
            $remaining = $this->extractIntAfter($result, '剩余=>');
        }
        $busy = stripos($result, 'BUSY') !== false || stripos($result, '繁忙') !== false;
        $active = $queueId > 0 && !in_array($status, ['done', 'success', 'error', 'failed', 'fail', ''], true);

        if ($busy) {
            return [
                'status' => 'busy',
                'label' => (string)__('模型忙碌'),
                'remaining' => $remaining,
                'pct' => null,
                'queue_id' => $queueId,
                'busy' => true,
            ];
        }
        if ($active) {
            return [
                'status' => 'running',
                'label' => (string)__('运行中'),
                'remaining' => $remaining,
                'pct' => null,
                'queue_id' => $queueId,
                'busy' => false,
            ];
        }
        if ($remaining !== null && $remaining > 1) {
            return [
                'status' => 'work',
                'label' => (string)__('有待办'),
                'remaining' => $remaining,
                'pct' => null,
                'queue_id' => $queueId,
                'busy' => false,
            ];
        }

        return [
            'status' => 'idle',
            'label' => (string)__('空闲'),
            'remaining' => $remaining,
            'pct' => $remaining !== null && $remaining <= 1 ? 100 : null,
            'queue_id' => $queueId,
            'busy' => false,
        ];
    }

    private function pct(int $done, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int)max(0, min(100, (int)round(($done / $total) * 100)));
    }

    private function extractIntAfter(string $blob, string $marker): ?int
    {
        if ($blob === '' || $marker === '') {
            return null;
        }
        $pos = mb_stripos($blob, $marker);
        if ($pos === false) {
            return null;
        }
        $tail = mb_substr($blob, $pos + mb_strlen($marker));
        if (preg_match('/^\\s*(\\d+)/u', $tail, $m) !== 1) {
            return null;
        }

        return (int)$m[1];
    }

    private function shortDetail(string $text): string
    {
        $text = trim(preg_replace('/\\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > 72 ? (mb_substr($text, 0, 72) . '…') : $text;
        }

        return strlen($text) > 72 ? (substr($text, 0, 72) . '…') : $text;
    }
}
