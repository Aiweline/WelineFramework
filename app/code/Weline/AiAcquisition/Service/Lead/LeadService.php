<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Lead;

use InvalidArgumentException;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Service\Customer\LeadCustomerSync;
use Weline\AiAcquisition\Service\Tag\TagCatalog;
use Weline\AiAcquisition\Service\Catalog\ContinentCatalog;
use Weline\AiAcquisition\Service\Catalog\CountryCatalog;
use Weline\Framework\Manager\ObjectManager;

/**
 * 线索服务：归一化入库、按去重哈希合并、列表筛选、统计与批量维护。
 *
 * 所有写入字段均来自 Lead 模型常量；业务校验失败统一抛 InvalidArgumentException。
 */
final class LeadService
{
    public function __construct(
        private readonly Lead $leadModel,
    ) {
    }

    /**
     * 保存线索：按 dedupe_hash 命中则合并，否则新增。
     *
     * @param array<string,mixed> $data 允许携带 run_id，会记录进 raw_json 而不写入列。
     * @return array{lead:array<string,mixed>,created:bool}
     */
    public function save(array $data, ?int $runId = null): array
    {
        $normalized = LeadNormalizer::normalize($data);
        if ($runId === null && isset($data['run_id']) && is_numeric($data['run_id'])) {
            $runId = (int)$data['run_id'];
        }

        $rawPayload = $data;
        if ($runId !== null && $runId > 0) {
            $rawPayload['run_id'] = $runId;
        }
        $rawJson = $this->encodeJson($rawPayload);
        $now = time();

        $editId = max(0, (int)($data['id'] ?? 0));
        if ($editId > 0) {
            $model = $this->newModel();
            $model->load($editId);
            if (!$model->getId()) {
                throw new InvalidArgumentException('线索不存在：' . $editId);
            }
            $seenCount = max(1, (int)$model->getData(Lead::schema_fields_SEEN_COUNT));
            foreach ($normalized as $field => $value) {
                $model->setData($field, $value);
            }
            $model->setData(Lead::schema_fields_SEEN_COUNT, $seenCount);
            if (
                (string)$model->getData(Lead::schema_fields_AI_INFERRED_AT) === '0'
                && (
                    trim((string)$model->getData(Lead::schema_fields_AI_PREFERENCE)) !== ''
                    || trim((string)$model->getData(Lead::schema_fields_AI_BUSINESS_DIRECTION)) !== ''
                    || trim((string)$model->getData(Lead::schema_fields_AI_REASON)) !== ''
                )
            ) {
                $model->setData(Lead::schema_fields_AI_INFERRED_AT, $now);
            }
            $model->setData(Lead::schema_fields_LAST_SEEN_AT, $now);
            $model->setData(Lead::schema_fields_RAW_JSON, $rawJson);
            $model->save();

            $lead = $this->find($editId);
            if ($lead !== null) {
                $this->syncCustomer($lead);
            }

            return ['lead' => $lead ?? $normalized, 'created' => false];
        }

        $hash = (string)$normalized[Lead::schema_fields_DEDUPE_HASH];
        $existing = $this->findByDedupe($hash);

        if ($existing !== null) {
            $leadId = (int)($existing[Lead::schema_fields_ID] ?? 0);
            if ($leadId <= 0) {
                throw new InvalidArgumentException('已存在的线索缺少主键，无法合并。');
            }

            $model = $this->newModel();
            $model->setData(Lead::schema_fields_ID, $leadId);
            $existingTags = TagCatalog::normalize($existing[Lead::schema_fields_TAGS] ?? []);
            $mergedTags = TagCatalog::dedupeByGroup(TagCatalog::merge($existingTags, $normalized[Lead::schema_fields_TAGS] ?? []));
            $model->setData(Lead::schema_fields_TAGS, $mergedTags === [] ? null : json_encode($mergedTags, JSON_UNESCAPED_UNICODE));
            $normalized[Lead::schema_fields_TAGS] = $mergedTags;
            $this->applyMergeFields($model, $normalized);
            $existingStatus = trim((string)($existing[Lead::schema_fields_STATUS] ?? ''));
            if ($existingStatus === '') {
                $model->setData(Lead::schema_fields_STATUS, $normalized[Lead::schema_fields_STATUS]);
            }
            $model->setData(
                Lead::schema_fields_SEEN_COUNT,
                max(1, (int)($existing[Lead::schema_fields_SEEN_COUNT] ?? 1)) + 1
            );
            $model->setData(Lead::schema_fields_LAST_SEEN_AT, $now);
            $model->setData(Lead::schema_fields_UPDATED_AT, $now);
            $model->setData(Lead::schema_fields_RAW_JSON, $rawJson);
            $model->save();

            $lead = $this->find($leadId) ?? $existing;
            $this->syncCustomer($lead);

            return ['lead' => $lead, 'created' => false];
        }

        $model = $this->newModel();
        foreach ($normalized as $field => $value) {
            $model->setData($field, $value);
        }
        $model->setData(Lead::schema_fields_SEEN_COUNT, 1);
        $model->setData(Lead::schema_fields_LAST_SEEN_AT, $now);
        $model->setData(Lead::schema_fields_CREATED_AT, $now);
        $model->setData(Lead::schema_fields_UPDATED_AT, $now);
        $model->setData(Lead::schema_fields_RAW_JSON, $rawJson);
        $model->save();

        $leadId = (int)$model->getId();
        $lead = $leadId > 0 ? $this->find($leadId) : null;
        if ($lead === null) {
            $lead = $normalized;
            $lead[Lead::schema_fields_ID] = $leadId;
        }
        $this->syncCustomer($lead);

        return ['lead' => $lead, 'created' => true];
    }

    /**
     * 合格线索（评分 ≥6 且有邮箱）自动转为正式客户并同步标签。
     */
    private function syncCustomer(array $lead): void
    {
        try {
            ObjectManager::getInstance(LeadCustomerSync::class)->syncFromLead($lead);
        } catch (\Throwable $throwable) {
            w_log_error('[AiAcquisition] customer sync failed: ' . $throwable->getMessage());
        }
    }

    /**
     * 列表查询。
     *
     * @param array<string,mixed> $filters 支持 keyword/continent/country/status/source_platform/min_score/website_id
     * @return array{items:array<int,array<string,mixed>>,pagination:mixed,total:int}
     */
    public function listing(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $model = $this->newModel();
        $this->applyListingFilters($model, $filters);

        $rows = $model
            ->order(Lead::schema_fields_UPDATED_AT, 'DESC')
            ->pagination(max(1, $page), max(1, $pageSize))
            ->select();

        $pagination = $rows->getPaginationState();
        $items = $rows->fetchArray();
        $total = (int)($pagination['totalSize'] ?? 0);

        return [
            'items' => \is_array($items) ? $items : [],
            'pagination' => $pagination,
            'total' => $total,
        ];
    }

    /**
     * 线索统计。
     *
     * @return array{total:int,qualified:int,by_status:array<string,int>,by_continent:array<string,int>}
     */
    public function stats(): array
    {
        $total = (int)$this->newModel()->total();

        $byStatus = [];
        foreach (array_keys(Lead::STATUSES) as $status) {
            $byStatus[$status] = (int)$this->newModel()
                ->where(Lead::schema_fields_STATUS, $status)
                ->total();
        }

        $byContinent = [];
        $continentRows = $this->newModel()
            ->fields(Lead::schema_fields_CONTINENT_CODE)
            ->group(Lead::schema_fields_CONTINENT_CODE)
            ->select()
            ->fetchArray();
        foreach ($continentRows as $row) {
            $code = trim((string)($row[Lead::schema_fields_CONTINENT_CODE] ?? ''));
            if ($code === '') {
                $code = ContinentCatalog::UNKNOWN;
            }
            if (isset($byContinent[$code])) {
                continue;
            }
            $byContinent[$code] = (int)$this->newModel()
                ->where(Lead::schema_fields_CONTINENT_CODE, $code)
                ->total();
        }

        $qualified = 0;
        foreach ([Lead::STATUS_QUALIFIED, Lead::STATUS_CONTACTED, Lead::STATUS_REPLIED, Lead::STATUS_CONVERTED] as $status) {
            $qualified += $byStatus[$status] ?? 0;
        }

        return [
            'total' => $total,
            'qualified' => $qualified,
            'by_status' => $byStatus,
            'by_continent' => $byContinent,
        ];
    }

    /**
     * 批量更新状态，返回实际命中的记录数。
     *
     * @param array<int|string> $ids
     */
    public function bulkStatus(array $ids, string $status): int
    {
        $status = strtolower(trim($status));
        if (!isset(Lead::STATUSES[$status])) {
            throw new InvalidArgumentException('无效的线索状态：' . $status);
        }

        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return 0;
        }

        $affected = $this->countByIds($ids);
        if ($affected === 0) {
            return 0;
        }

        $this->newModel()
            ->where(Lead::schema_fields_ID, $ids, 'IN')
            ->update([
                Lead::schema_fields_STATUS => $status,
                Lead::schema_fields_UPDATED_AT => time(),
            ])
            ->fetch();

        return $affected;
    }

    /**
     * 批量删除，返回实际命中的记录数。
     *
     * @param array<int|string> $ids
     */
    public function bulkDelete(array $ids): int
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return 0;
        }

        $affected = $this->countByIds($ids);
        if ($affected === 0) {
            return 0;
        }

        $this->newModel()
            ->where(Lead::schema_fields_ID, $ids, 'IN')
            ->delete();

        return $affected;
    }

    /**
     * 删除单条线索。
     */
    public function delete(int $id): bool
    {
        if ($id <= 0 || $this->find($id) === null) {
            return false;
        }

        $model = $this->newModel();
        $model->setData(Lead::schema_fields_ID, $id);
        $model->delete();

        return true;
    }

    /**
     * 按去重哈希查询。
     *
     * @return array<string,mixed>|null
     */
    public function findByDedupe(string $hash): ?array
    {
        $hash = trim($hash);
        if ($hash === '') {
            return null;
        }

        $row = $this->newModel()
            ->where(Lead::schema_fields_DEDUPE_HASH, $hash)
            ->find()
            ->fetchArray();

        return \is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * 按主键查询。
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->newModel()
            ->where(Lead::schema_fields_ID, $id)
            ->find()
            ->fetchArray();

        return \is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function applyListingFilters(Lead $model, array $filters): void
    {
        $keyword = $this->filterText($filters['keyword'] ?? '');
        if ($keyword !== '') {
            // 先取命中的 ID 再回主查询，避免多个 LIKE 用 OR 串联时突破其他筛选条件。
            $like = '%' . $keyword . '%';
            $idRows = $this->newModel()
                ->fields(Lead::schema_fields_ID)
                ->where(Lead::schema_fields_COMPANY_NAME, $like, 'LIKE', 'OR')
                ->where(Lead::schema_fields_EMAIL, $like, 'LIKE', 'OR')
                ->where(Lead::schema_fields_SOURCE_URL, $like, 'LIKE', 'OR')
                ->where(Lead::schema_fields_COMPANY_DOMAIN, $like, 'LIKE')
                ->select()
                ->fetchArray();

            $ids = [];
            foreach ($idRows as $row) {
                $id = (int)($row[Lead::schema_fields_ID] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            if ($ids === []) {
                $model->where(Lead::schema_fields_ID, 0);
            } else {
                $model->where(Lead::schema_fields_ID, array_values($ids), 'IN');
            }
        }

        $tag = $this->filterText($filters['tag'] ?? '');
        if ($tag !== '') {
            $model->where(Lead::schema_fields_TAGS, '%' . $tag . '%', 'LIKE');
        }

        $continent = ContinentCatalog::normalize($this->filterText($filters['continent'] ?? ''));
        if ($continent !== ContinentCatalog::UNKNOWN) {
            $model->where(Lead::schema_fields_CONTINENT_CODE, $continent);
        } elseif ($this->filterText($filters['continent'] ?? '') !== '') {
            throw new InvalidArgumentException('无效的洲筛选值。');
        }

        $countryRaw = $this->filterText($filters['country'] ?? '');
        if ($countryRaw !== '') {
            $country = CountryCatalog::resolve($countryRaw);
            if ($country['country_code'] === CountryCatalog::UNKNOWN_CODE) {
                throw new InvalidArgumentException('无效的国家筛选值：' . $countryRaw);
            }
            $model->where(Lead::schema_fields_COUNTRY_CODE, $country['country_code']);
        }

        $status = strtolower($this->filterText($filters['status'] ?? ''));
        if ($status !== '') {
            if (!isset(Lead::STATUSES[$status])) {
                throw new InvalidArgumentException('无效的线索状态：' . $status);
            }
            $model->where(Lead::schema_fields_STATUS, $status);
        }

        $sourcePlatform = strtolower($this->filterText($filters['source_platform'] ?? ''));
        if ($sourcePlatform !== '') {
            $model->where(Lead::schema_fields_SOURCE_PLATFORM, $sourcePlatform);
        }
        $entryChannel = strtolower($this->filterText($filters['entry_channel'] ?? ''));
        if ($entryChannel !== '') {
            if (!isset(Lead::ENTRY_CHANNELS[$entryChannel])) {
                throw new InvalidArgumentException('无效的录入渠道筛选值：' . $entryChannel);
            }
            $model->where(Lead::schema_fields_ENTRY_CHANNEL, $entryChannel);
        }

        $minScore = $filters['min_score'] ?? null;
        if ($minScore !== null && $minScore !== '') {
            if (!is_numeric($minScore)) {
                throw new InvalidArgumentException('min_score 必须是数字。');
            }
            $model->where(Lead::schema_fields_AI_SCORE, max(0.0, min(10.0, (float)$minScore)), '>=');
        }

        $websiteId = $filters['website_id'] ?? null;
        if ($websiteId !== null && $websiteId !== '') {
            if (!is_numeric($websiteId)) {
                throw new InvalidArgumentException('website_id 必须是整数。');
            }
            $model->where(Lead::schema_fields_WEBSITE_ID, max(0, (int)$websiteId));
        }
    }

    /**
     * 合并时只写入有意义的非空值，避免空字段覆盖已有证据。
     *
     * @param array<string,mixed> $normalized
     */
    private function applyMergeFields(Lead $model, array $normalized): void
    {
        foreach ($normalized as $field => $value) {
            if (\in_array($field, [
                Lead::schema_fields_STATUS,
                Lead::schema_fields_SEEN_COUNT,
            ], true)) {
                continue;
            }
            if ($field === Lead::schema_fields_SOURCE_PLATFORM && $value === 'web') {
                continue;
            }
            if ($field === Lead::schema_fields_ENTRY_CHANNEL && $value === Lead::ENTRY_CHANNEL_DEFAULT) {
                continue;
            }
            if ($field === Lead::schema_fields_CONTINENT_CODE && $value === ContinentCatalog::UNKNOWN) {
                continue;
            }
            if ($field === Lead::schema_fields_COUNTRY_CODE && $value === CountryCatalog::UNKNOWN_CODE) {
                continue;
            }
            if ($field === Lead::schema_fields_COUNTRY_NAME && $value === CountryCatalog::UNKNOWN_NAME) {
                continue;
            }
            if ($field === Lead::schema_fields_EMAIL_STATUS && $value === Lead::EMAIL_UNKNOWN) {
                continue;
            }
            if (!$this->isMeaningful($value)) {
                continue;
            }
            $model->setData($field, $value);
        }
    }

    private function isMeaningful(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (\is_string($value)) {
            return trim($value) !== '';
        }
        if (\is_int($value) || \is_float($value)) {
            return $value > 0;
        }

        return true;
    }

    private function newModel(): Lead
    {
        return (clone $this->leadModel)->clearData()->reset();
    }

    /**
     * @param array<int|string> $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                throw new InvalidArgumentException('线索 ID 必须是数字。');
            }
            $id = (int)$id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param list<int> $ids
     */
    private function countByIds(array $ids): int
    {
        return (int)$this->newModel()
            ->where(Lead::schema_fields_ID, $ids, 'IN')
            ->total();
    }

    private function filterText(mixed $value): string
    {
        if (\is_string($value)) {
            return trim($value);
        }
        if (\is_int($value) || \is_float($value)) {
            return (string)$value;
        }

        return '';
    }

    private function encodeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('线索原始数据无法序列化为 JSON。');
        }

        return $json;
    }
}
