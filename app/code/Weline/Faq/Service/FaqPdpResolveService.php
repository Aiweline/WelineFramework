<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Model\FaqItem;
use Weline\Framework\Manager\ObjectManager;

/**
 * Sole PDP/SEO FAQ resolution path: template pack cascade + product cascade + merge.
 */
final class FaqPdpResolveService
{
    public const SOURCE_WEBSITE = 'website';
    public const SOURCE_STORE = 'store';
    public const SOURCE_CHANNEL = 'channel';
    public const SOURCE_PRODUCT = 'product';

    public const CONFIG_MERGE = 'faq/pdp/merge_enabled';
    public const CONFIG_PACK = 'faq/pdp/active_pack';

    /** Exact locale match must beat website/store empty-locale inheritance. */
    private const SCORE_LOCALE_MATCH = 10000;

    /**
     * @param array{
     *   website_id?:int,
     *   store_code?:string,
     *   channel_code?:string,
     *   locale_code?:string,
     *   product_uuid?:string,
     *   merge_enabled?:bool|null,
     *   active_pack?:string|null
     * } $context
     * @return list<array<string,mixed>>
     */
    public function resolveForPdp(array $context): array
    {
        $websiteId = max(0, (int)($context['website_id'] ?? 0));
        $storeCode = strtolower(trim((string)($context['store_code'] ?? '')));
        $channelCode = strtolower(trim((string)($context['channel_code'] ?? '')));
        $localeCode = $this->resolveLocaleCode(trim((string)($context['locale_code'] ?? '')));
        $productUuid = trim((string)($context['product_uuid'] ?? ''));

        $mergeEnabled = array_key_exists('merge_enabled', $context) && $context['merge_enabled'] !== null
            ? (bool)$context['merge_enabled']
            : $this->readMergeEnabled($websiteId, $storeCode, $channelCode);
        $activePack = array_key_exists('active_pack', $context) && $context['active_pack'] !== null
            ? FaqTemplatePacks::normalize((string)$context['active_pack'])
            : $this->readActivePack($websiteId, $storeCode, $channelCode);

        $defaults = $this->cascadeEntity(
            TemplateFaqTypeProvider::TYPE_CODE,
            $activePack,
            $websiteId,
            $storeCode,
            $channelCode,
            $localeCode,
        );
        $products = $productUuid === ''
            ? []
            : $this->cascadeEntity(
                'product',
                $productUuid,
                $websiteId,
                $storeCode,
                $channelCode,
                $localeCode,
                self::SOURCE_PRODUCT,
            );

        return $this->mergeSets($defaults, $products, $mergeEnabled);
    }

    /**
     * Pure merge of already-cascaded sets (UT entry).
     *
     * @param list<array<string,mixed>> $defaults
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    public function mergeSets(array $defaults, array $products, bool $mergeEnabled): array
    {
        if (!$mergeEnabled) {
            return $products !== [] ? array_values($products) : array_values($defaults);
        }

        return array_values(array_merge($defaults, $products));
    }

    /**
     * Pure cascade over mapped rows (UT entry).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function cascadeRows(
        array $rows,
        int $websiteId,
        string $storeCode,
        string $channelCode,
        string $localeCode = '',
        ?string $forceSource = null,
    ): array {
        $storeCode = strtolower(trim($storeCode));
        $channelCode = strtolower(trim($channelCode));
        $localeCode = trim($localeCode);

        $best = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!$this->rowApplies($row, $websiteId, $storeCode, $channelCode, $localeCode)) {
                continue;
            }
            $key = trim((string)($row['faq_key'] ?? ''));
            if ($key === '') {
                $key = 'id:' . (int)($row['faq_id'] ?? 0);
            }
            $score = $this->specificityScore($row, $websiteId, $storeCode, $channelCode, $localeCode);
            $prev = $best[$key] ?? null;
            if ($prev !== null && (int)$prev['_score'] >= $score) {
                continue;
            }
            $source = $forceSource ?? $this->sourceLabel($row, $storeCode, $channelCode);
            $best[$key] = $row + [
                'faq_key' => $key,
                'source' => $source,
                '_score' => $score,
            ];
        }

        $out = [];
        foreach ($best as $row) {
            $status = strtolower(trim((string)($row['status'] ?? FaqItem::STATUS_ENABLED)));
            if ($status === FaqItem::STATUS_DISABLED) {
                continue;
            }
            unset($row['_score']);
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            $sa = (int)($a['sort_order'] ?? 0);
            $sb = (int)($b['sort_order'] ?? 0);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return ((int)($a['faq_id'] ?? 0)) <=> ((int)($b['faq_id'] ?? 0));
        });

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function cascadeEntity(
        string $typeCode,
        string $entityUuid,
        int $websiteId,
        string $storeCode,
        string $channelCode,
        string $localeCode,
        ?string $forceSource = null,
    ): array {
        $rows = $this->loadRows($typeCode, $entityUuid, $websiteId, $localeCode);

        return $this->cascadeRows($rows, $websiteId, $storeCode, $channelCode, $localeCode, $forceSource);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRows(string $typeCode, string $entityUuid, int $websiteId, string $localeCode): array
    {
        try {
            /** @var FaqItem $model */
            $model = ObjectManager::getInstance(FaqItem::class);
            $query = $model->clear()
                ->where(FaqItem::schema_fields_TYPE_CODE, $typeCode)
                ->where(FaqItem::schema_fields_ENTITY_UUID, $entityUuid);
            if ($websiteId > 0) {
                $query->where(FaqItem::schema_fields_WEBSITE_ID, [0, $websiteId], 'IN');
            }
            $localeCode = trim($localeCode);
            if ($localeCode !== '') {
                $query->where(FaqItem::schema_fields_LOCALE_CODE, ['', $localeCode], 'IN');
            }
            $raw = $query
                ->order(FaqItem::schema_fields_SORT_ORDER, 'ASC')
                ->order(FaqItem::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'faq_id' => (int)($row[FaqItem::schema_fields_ID] ?? 0),
                    'website_id' => (int)($row[FaqItem::schema_fields_WEBSITE_ID] ?? 0),
                    'store_code' => strtolower(trim((string)($row[FaqItem::schema_fields_STORE_CODE] ?? ''))),
                    'channel_code' => strtolower(trim((string)($row[FaqItem::schema_fields_CHANNEL_CODE] ?? ''))),
                    'locale_code' => (string)($row[FaqItem::schema_fields_LOCALE_CODE] ?? ''),
                    'type_code' => (string)($row[FaqItem::schema_fields_TYPE_CODE] ?? ''),
                    'entity_uuid' => (string)($row[FaqItem::schema_fields_ENTITY_UUID] ?? ''),
                    'faq_key' => (string)($row[FaqItem::schema_fields_FAQ_KEY] ?? ''),
                    'question' => (string)($row[FaqItem::schema_fields_QUESTION] ?? ''),
                    'answer' => (string)($row[FaqItem::schema_fields_ANSWER] ?? ''),
                    'sort_order' => (int)($row[FaqItem::schema_fields_SORT_ORDER] ?? 0),
                    'status' => (string)($row[FaqItem::schema_fields_STATUS] ?? FaqItem::STATUS_ENABLED),
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowApplies(
        array $row,
        int $websiteId,
        string $storeCode,
        string $channelCode,
        string $localeCode,
    ): bool {
        $rowWebsite = (int)($row['website_id'] ?? 0);
        if ($websiteId > 0 && $rowWebsite !== 0 && $rowWebsite !== $websiteId) {
            return false;
        }
        $rowStore = strtolower(trim((string)($row['store_code'] ?? '')));
        $rowChannel = strtolower(trim((string)($row['channel_code'] ?? '')));
        if ($rowChannel !== '') {
            if ($channelCode === '' || $rowChannel !== $channelCode || $rowStore !== $storeCode) {
                return false;
            }
        } elseif ($rowStore !== '') {
            if ($storeCode === '' || $rowStore !== $storeCode) {
                return false;
            }
        }
        $rowLocale = trim((string)($row['locale_code'] ?? ''));
        if ($localeCode !== '' && $rowLocale !== '' && $rowLocale !== $localeCode) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function specificityScore(
        array $row,
        int $websiteId,
        string $storeCode,
        string $channelCode,
        string $localeCode,
    ): int {
        $score = 0;
        $rowWebsite = (int)($row['website_id'] ?? 0);
        if ($rowWebsite === $websiteId && $websiteId > 0) {
            $score += 100;
        } elseif ($rowWebsite === 0) {
            $score += 10;
        }
        $rowStore = strtolower(trim((string)($row['store_code'] ?? '')));
        $rowChannel = strtolower(trim((string)($row['channel_code'] ?? '')));
        if ($rowChannel !== '' && $rowChannel === $channelCode && $rowStore === $storeCode) {
            $score += 1000;
        } elseif ($rowStore !== '' && $rowStore === $storeCode && $rowChannel === '') {
            $score += 500;
        }
        $rowLocale = trim((string)($row['locale_code'] ?? ''));
        if ($localeCode !== '' && $rowLocale === $localeCode) {
            $score += self::SCORE_LOCALE_MATCH;
        } elseif ($rowLocale === '') {
            $score += 1;
        }

        return $score;
    }

    private function resolveLocaleCode(string $localeCode): string
    {
        $localeCode = trim($localeCode);
        if ($localeCode !== '') {
            return $localeCode;
        }
        try {
            if (class_exists(\Weline\Framework\App\State::class)) {
                $localeCode = trim((string)\Weline\Framework\App\State::getLangLocal());
                if ($localeCode === '') {
                    $localeCode = trim((string)\Weline\Framework\App\State::getLang());
                }
            }
        } catch (\Throwable) {
            $localeCode = '';
        }
        if ($localeCode === '' && class_exists(\Weline\Framework\Runtime\RequestContext::class)) {
            try {
                $localeCode = trim((string)(\Weline\Framework\Runtime\RequestContext::locale() ?? ''));
            } catch (\Throwable) {
                $localeCode = '';
            }
        }

        return $localeCode;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function sourceLabel(array $row, string $storeCode, string $channelCode): string
    {
        $rowChannel = strtolower(trim((string)($row['channel_code'] ?? '')));
        $rowStore = strtolower(trim((string)($row['store_code'] ?? '')));
        if ($rowChannel !== '') {
            return self::SOURCE_CHANNEL;
        }
        if ($rowStore !== '') {
            return self::SOURCE_STORE;
        }

        return self::SOURCE_WEBSITE;
    }

    private function readMergeEnabled(int $websiteId, string $storeCode, string $channelCode): bool
    {
        $raw = $this->readConfig(self::CONFIG_MERGE, $websiteId, $storeCode, $channelCode);
        if ($raw === null) {
            return true;
        }

        return !in_array(strtolower(trim($raw)), ['0', 'false', 'off', 'no'], true);
    }

    private function readActivePack(int $websiteId, string $storeCode, string $channelCode): string
    {
        $raw = $this->readConfig(self::CONFIG_PACK, $websiteId, $storeCode, $channelCode);

        return FaqTemplatePacks::normalize($raw ?? FaqTemplatePacks::DEFAULT);
    }

    private function readConfig(string $key, int $websiteId, string $storeCode, string $channelCode): ?string
    {
        if (!function_exists('w_query')) {
            return null;
        }
        try {
            $params = [
                'path' => $key,
                'module' => 'Weline_Faq',
                'area' => 'frontend',
            ];
            if ($websiteId > 0 || $storeCode !== '' || $channelCode !== '') {
                $params['website_code'] = $this->websiteCodeForId($websiteId);
                $params['store_code'] = $storeCode;
                $params['channel_code'] = $channelCode;
            }
            $value = w_query('system_config', 'getConfig', $params);
            if (is_array($value)) {
                $value = $value['value'] ?? ($value['result'] ?? null);
            }
            if ($value === null || $value === '') {
                return null;
            }

            return is_scalar($value) ? (string)$value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function websiteCodeForId(int $websiteId): string
    {
        if ($websiteId <= 0) {
            return '';
        }
        try {
            if (function_exists('w_query')) {
                $options = w_query('websites', 'getWebsiteSelectOptions', []);
                if (is_array($options)) {
                    foreach ($options as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        if ((int)($row['value'] ?? 0) === $websiteId) {
                            $code = trim((string)($row['code'] ?? $row['website_code'] ?? ''));
                            if ($code !== '') {
                                return $code;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return (string)$websiteId;
    }
}
