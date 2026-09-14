<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Api\FaqSeoFactsInterface;
use Weline\Faq\Model\FaqItem;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

final class FaqService implements FaqSeoFactsInterface
{
    public function __construct(
        private readonly FaqTypeRegistry $types,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForEntity(
        string $typeCode,
        string $entityUuid,
        int $websiteId = 0,
        string $localeCode = '',
        bool $enabledOnly = true,
    ): array {
        $resolved = $this->resolveStorageKey($typeCode, $entityUuid);
        if ($resolved === null) {
            return [];
        }

        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $query = $model->clear()
            ->where(FaqItem::schema_fields_TYPE_CODE, $resolved['type_code'])
            ->where(FaqItem::schema_fields_ENTITY_UUID, $resolved['entity_uuid']);
        if ($websiteId > 0) {
            $query->where(FaqItem::schema_fields_WEBSITE_ID, [0, $websiteId], 'IN');
        }
        $localeCode = trim($localeCode);
        if ($localeCode !== '') {
            $query->where(FaqItem::schema_fields_LOCALE_CODE, ['', $localeCode], 'IN');
        }
        if ($enabledOnly) {
            $query->where(FaqItem::schema_fields_STATUS, FaqItem::STATUS_ENABLED);
        }
        $rows = $query
            ->order(FaqItem::schema_fields_SORT_ORDER, 'ASC')
            ->order(FaqItem::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $items = [];
        if (!is_array($rows)) {
            return [];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,pagination:array<string,mixed>}
     */
    public function listing(
        ?int $websiteId = null,
        string $typeCode = '',
        string $status = '',
        string $search = '',
        int $page = 1,
        int $pageSize = 30,
        string $storeCode = '',
        string $channelCode = '',
    ): array {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $query = $model->clear();
        if ($websiteId !== null) {
            $query->where(FaqItem::schema_fields_WEBSITE_ID, max(0, $websiteId));
        }
        $typeCode = strtolower(trim($typeCode));
        if ($typeCode !== '') {
            $query->where(FaqItem::schema_fields_TYPE_CODE, $typeCode);
        }
        $storeCode = strtolower(trim($storeCode));
        if ($storeCode !== '') {
            $query->where(FaqItem::schema_fields_STORE_CODE, $storeCode);
        }
        $channelCode = strtolower(trim($channelCode));
        if ($channelCode !== '') {
            $query->where(FaqItem::schema_fields_CHANNEL_CODE, $channelCode);
        }
        $status = strtolower(trim($status));
        if ($status !== '' && in_array($status, [FaqItem::STATUS_ENABLED, FaqItem::STATUS_DISABLED], true)) {
            $query->where(FaqItem::schema_fields_STATUS, $status);
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(FaqItem::schema_fields_QUESTION, $like, 'like')
                ->where(FaqItem::schema_fields_ENTITY_UUID, $like, 'like', 'OR')
                ->where(FaqItem::schema_fields_FAQ_KEY, $like, 'like', 'OR');
        }
        $query->order(FaqItem::schema_fields_SORT_ORDER, 'ASC')
            ->order(FaqItem::schema_fields_ID, 'DESC')
            ->pagination($page, $pageSize);
        $rows = $query->select()->fetchArray();
        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $items[] = $this->mapRow($row);
                }
            }
        }
        $pagination = $model->getPaginationState();
        $total = (int)($pagination['totalSize'] ?? count($items));

        return [
            'items' => $items,
            'total' => $total,
            'pagination' => is_array($pagination) ? $pagination : [],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function save(array $data): array
    {
        $faqId = max(0, (int)($data['faq_id'] ?? $data['id'] ?? 0));
        $typeCode = strtolower(trim((string)($data['type_code'] ?? '')));
        $entityUuid = trim((string)($data['entity_uuid'] ?? ''));
        $question = trim(strip_tags((string)($data['question'] ?? '')));
        $answer = trim((string)($data['answer'] ?? ''));
        $status = strtolower(trim((string)($data['status'] ?? FaqItem::STATUS_ENABLED)));
        $storeCode = strtolower(trim((string)($data['store_code'] ?? '')));
        $channelCode = strtolower(trim((string)($data['channel_code'] ?? '')));
        $faqKey = strtolower(trim((string)($data['faq_key'] ?? '')));
        if (!in_array($status, [FaqItem::STATUS_ENABLED, FaqItem::STATUS_DISABLED], true)) {
            $status = FaqItem::STATUS_ENABLED;
        }
        if ($typeCode === '' || $entityUuid === '') {
            throw new \InvalidArgumentException((string)__('类型与实体 UUID 不能为空。'));
        }
        if ($question === '' || $answer === '') {
            throw new \InvalidArgumentException((string)__('问题与答案不能为空。'));
        }
        if ($channelCode !== '' && $storeCode === '') {
            throw new \InvalidArgumentException((string)__('填写渠道时必须同时指定店铺。'));
        }
        if ($faqKey === '') {
            $faqKey = $this->slugFaqKey($question);
        }
        $resolved = $this->resolveStorageKey($typeCode, $entityUuid);
        if ($resolved === null) {
            throw new \InvalidArgumentException((string)__('无法解析 FAQ 实体：%{1}/%{2}', [$typeCode, $entityUuid]));
        }

        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $now = date('Y-m-d H:i:s');
        if ($faqId > 0) {
            $model->clear()->load($faqId);
            if ((int)$model->getData(FaqItem::schema_fields_ID) !== $faqId) {
                throw new \InvalidArgumentException((string)__('FAQ 条目不存在。'));
            }
        } else {
            $model->clear()->setData(FaqItem::schema_fields_CREATED_AT, $now);
        }

        $model->setData(FaqItem::schema_fields_WEBSITE_ID, max(0, (int)($data['website_id'] ?? 0)));
        $model->setData(FaqItem::schema_fields_STORE_CODE, $storeCode);
        $model->setData(FaqItem::schema_fields_CHANNEL_CODE, $channelCode);
        $model->setData(FaqItem::schema_fields_LOCALE_CODE, trim((string)($data['locale_code'] ?? '')));
        $model->setData(FaqItem::schema_fields_TYPE_CODE, $resolved['type_code']);
        $model->setData(FaqItem::schema_fields_ENTITY_UUID, $resolved['entity_uuid']);
        $model->setData(FaqItem::schema_fields_FAQ_KEY, $faqKey);
        $model->setData(FaqItem::schema_fields_QUESTION, $question);
        $model->setData(FaqItem::schema_fields_ANSWER, $answer);
        $model->setData(FaqItem::schema_fields_SORT_ORDER, max(0, (int)($data['sort_order'] ?? 0)));
        $model->setData(FaqItem::schema_fields_STATUS, $status);
        $model->setData(FaqItem::schema_fields_UPDATED_AT, $now);
        $model->save();

        $row = $this->mapRow($model->getData());
        $this->dispatchIndexEvent('save', $row);

        return $row;
    }

    public function delete(int $faqId): void
    {
        $faqId = max(0, $faqId);
        if ($faqId <= 0) {
            throw new \InvalidArgumentException((string)__('FAQ ID 无效。'));
        }
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $model->clear()->load($faqId);
        if ((int)$model->getData(FaqItem::schema_fields_ID) !== $faqId) {
            throw new \InvalidArgumentException((string)__('FAQ 条目不存在。'));
        }
        $row = $this->mapRow($model->getData());
        $model->delete();
        $this->dispatchIndexEvent('delete', $row);
    }

    public function get(int $faqId): ?array
    {
        $faqId = max(0, $faqId);
        if ($faqId <= 0) {
            return null;
        }
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $model->clear()->load($faqId);
        if ((int)$model->getData(FaqItem::schema_fields_ID) !== $faqId) {
            return null;
        }

        return $this->mapRow($model->getData());
    }

    public function seoFaqsForEntity(
        string $typeCode,
        string $entityUuid,
        int $websiteId = 0,
        string $localeCode = '',
    ): array {
        $out = [];
        foreach ($this->listForEntity($typeCode, $entityUuid, $websiteId, $localeCode, true) as $item) {
            $question = trim((string)($item['question'] ?? ''));
            $answer = trim(strip_tags((string)($item['answer'] ?? '')));
            if ($question === '' || $answer === '') {
                continue;
            }
            $out[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $out;
    }

    /**
     * PDP/SEO shared FAQ facts via FaqPdpResolveService.
     *
     * @param array{
     *   website_id?:int,
     *   store_code?:string,
     *   channel_code?:string,
     *   locale_code?:string,
     *   product_uuid?:string,
     *   merge_enabled?:bool|null,
     *   active_pack?:string|null
     * } $context
     * @return list<array{question:string,answer:string}>
     */
    public function seoFaqsForPdp(array $context): array
    {
        $out = [];
        $items = ObjectManager::getInstance(FaqPdpResolveService::class)->resolveForPdp($context);
        foreach ($items as $item) {
            $question = trim((string)($item['question'] ?? ''));
            $answer = trim(strip_tags((string)($item['answer'] ?? '')));
            if ($question === '' || $answer === '') {
                continue;
            }
            $out[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $out;
    }

    /**
     * Seed site hub FAQs for required locales (zh_Hans_CN + en_US). Empty locale migrates to zh.
     * Idempotent per faq_key (hub_0..hub_N), so partial legacy rows do not block bilingual backfill.
     */
    public function seedSiteHubFaqs(int $websiteId = 0, string $localeCode = ''): int
    {
        $websiteId = max(0, $websiteId);
        $localeCode = trim($localeCode);
        if ($localeCode === '') {
            $created = 0;
            $created += $this->migrateEmptySiteHubLocaleToZhHans($websiteId);
            foreach (FaqTemplateSeedService::requiredLocales() as $locale) {
                $created += $this->seedSiteHubFaqs($websiteId, $locale);
            }
            $created += $this->pruneLegacySiteHubSlugRows($websiteId);

            return $created;
        }

        $hub = ObjectManager::getInstance(FaqHubContent::class);
        $created = 0;
        $sort = 0;
        foreach ($hub->faqsForLocale($localeCode) as $row) {
            $faqKey = 'hub_' . $sort;
            if ($this->siteHubKeyExistsForLocale($websiteId, $localeCode, $faqKey)) {
                $sort++;
                continue;
            }
            $this->save([
                'website_id' => $websiteId,
                'locale_code' => $localeCode,
                'type_code' => 'site',
                'entity_uuid' => SiteFaqTypeProvider::ENTITY_UUID,
                'faq_key' => $faqKey,
                'question' => (string)($row['q'] ?? ''),
                'answer' => (string)($row['a'] ?? ''),
                'sort_order' => $sort++,
                'status' => FaqItem::STATUS_ENABLED,
            ]);
            $created++;
        }

        return $created;
    }

    private function migrateEmptySiteHubLocaleToZhHans(int $websiteId): int
    {
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $updated = 0;
        try {
            $rows = $model->clear()
                ->where(FaqItem::schema_fields_TYPE_CODE, 'site')
                ->where(FaqItem::schema_fields_ENTITY_UUID, SiteFaqTypeProvider::ENTITY_UUID)
                ->where(FaqItem::schema_fields_WEBSITE_ID, $websiteId)
                ->where(FaqItem::schema_fields_LOCALE_CODE, '')
                ->select()
                ->fetchArray();
            if (!is_array($rows)) {
                return 0;
            }
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row[FaqItem::schema_fields_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $faqKey = strtolower(trim((string)($row[FaqItem::schema_fields_FAQ_KEY] ?? '')));
                if ($faqKey !== '' && $this->siteHubKeyExistsForLocale($websiteId, FaqTemplateSeedService::LOCALE_ZH, $faqKey)) {
                    $model->clear()->load($id);
                    if ((int)$model->getData(FaqItem::schema_fields_ID) === $id) {
                        $model->delete();
                        $updated++;
                    }
                    continue;
                }
                $model->clear()->load($id);
                if ((int)$model->getData(FaqItem::schema_fields_ID) !== $id) {
                    continue;
                }
                $model->setData(FaqItem::schema_fields_LOCALE_CODE, FaqTemplateSeedService::LOCALE_ZH);
                $model->setData(FaqItem::schema_fields_UPDATED_AT, $now);
                $model->save();
                $updated++;
            }
        } catch (\Throwable) {
            return $updated;
        }

        return $updated;
    }

    /**
     * After hub_0..hub_N exist for zh+en, drop legacy slug-key site rows (q_*) to avoid duplicates.
     */
    private function pruneLegacySiteHubSlugRows(int $websiteId): int
    {
        $hubReady = true;
        foreach (FaqTemplateSeedService::requiredLocales() as $locale) {
            for ($i = 0; $i < 8; $i++) {
                if (!$this->siteHubKeyExistsForLocale($websiteId, $locale, 'hub_' . $i)) {
                    $hubReady = false;
                    break 2;
                }
            }
        }
        if (!$hubReady) {
            return 0;
        }

        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $removed = 0;
        try {
            $rows = $model->clear()
                ->where(FaqItem::schema_fields_TYPE_CODE, 'site')
                ->where(FaqItem::schema_fields_ENTITY_UUID, SiteFaqTypeProvider::ENTITY_UUID)
                ->where(FaqItem::schema_fields_WEBSITE_ID, $websiteId)
                ->select()
                ->fetchArray();
            if (!is_array($rows)) {
                return 0;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $faqKey = strtolower(trim((string)($row[FaqItem::schema_fields_FAQ_KEY] ?? '')));
                if ($faqKey === '' || str_starts_with($faqKey, 'hub_')) {
                    continue;
                }
                $id = (int)($row[FaqItem::schema_fields_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $model->clear()->load($id);
                if ((int)$model->getData(FaqItem::schema_fields_ID) === $id) {
                    $model->delete();
                    $removed++;
                }
            }
        } catch (\Throwable) {
            return $removed;
        }

        return $removed;
    }

    private function siteHubKeyExistsForLocale(int $websiteId, string $localeCode, string $faqKey): bool
    {
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        try {
            $row = $model->clear()
                ->where(FaqItem::schema_fields_TYPE_CODE, 'site')
                ->where(FaqItem::schema_fields_ENTITY_UUID, SiteFaqTypeProvider::ENTITY_UUID)
                ->where(FaqItem::schema_fields_WEBSITE_ID, $websiteId)
                ->where(FaqItem::schema_fields_LOCALE_CODE, $localeCode)
                ->where(FaqItem::schema_fields_FAQ_KEY, $faqKey)
                ->find()
                ->fetch();
            if (is_object($row) && method_exists($row, 'getData')) {
                return (int)$row->getData(FaqItem::schema_fields_ID) > 0;
            }
            if (is_array($row)) {
                return (int)($row[FaqItem::schema_fields_ID] ?? 0) > 0;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * @return array{type_code:string,entity_id:int,entity_uuid:string}|null
     */
    private function resolveStorageKey(string $typeCode, string $entityUuid): ?array
    {
        $typeCode = strtolower(trim($typeCode));
        $entityUuid = trim($entityUuid);
        if ($typeCode === '' || $entityUuid === '') {
            return null;
        }
        if (!$this->types->has($typeCode)) {
            return [
                'type_code' => $typeCode,
                'entity_id' => 0,
                'entity_uuid' => $entityUuid,
            ];
        }
        $resolved = $this->types->get($typeCode)->resolveEntity($entityUuid);
        if ($resolved === null) {
            return null;
        }

        return [
            'type_code' => $typeCode,
            'entity_id' => (int)$resolved['entity_id'],
            'entity_uuid' => (string)$resolved['entity_uuid'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'faq_id' => (int)($row[FaqItem::schema_fields_ID] ?? 0),
            'website_id' => (int)($row[FaqItem::schema_fields_WEBSITE_ID] ?? 0),
            'store_code' => (string)($row[FaqItem::schema_fields_STORE_CODE] ?? ''),
            'channel_code' => (string)($row[FaqItem::schema_fields_CHANNEL_CODE] ?? ''),
            'locale_code' => (string)($row[FaqItem::schema_fields_LOCALE_CODE] ?? ''),
            'type_code' => (string)($row[FaqItem::schema_fields_TYPE_CODE] ?? ''),
            'entity_uuid' => (string)($row[FaqItem::schema_fields_ENTITY_UUID] ?? ''),
            'faq_key' => (string)($row[FaqItem::schema_fields_FAQ_KEY] ?? ''),
            'question' => (string)($row[FaqItem::schema_fields_QUESTION] ?? ''),
            'answer' => (string)($row[FaqItem::schema_fields_ANSWER] ?? ''),
            'sort_order' => (int)($row[FaqItem::schema_fields_SORT_ORDER] ?? 0),
            'status' => (string)($row[FaqItem::schema_fields_STATUS] ?? FaqItem::STATUS_ENABLED),
            'created_at' => (string)($row[FaqItem::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[FaqItem::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    private function slugFaqKey(string $question): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $question) ?? '');
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'faq_' . substr(sha1($question), 0, 10);
        }

        return substr($slug, 0, 64);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function dispatchIndexEvent(string $action, array $row): void
    {
        try {
            $payload = [
                'action' => $action,
                'faq_item' => $row,
            ];
            ObjectManager::getInstance(EventsManager::class)
                ->dispatch('Weline_Faq::item_search_index_changed', $payload);
        } catch (\Throwable) {
            // Search optional.
        }
    }
}
