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
     * Admin listing with optional filters.
     *
     * @return array{items:list<array<string,mixed>>,total:int,pagination:array<string,mixed>}
     */
    public function listing(
        ?int $websiteId = null,
        string $typeCode = '',
        string $status = '',
        string $search = '',
        int $page = 1,
        int $pageSize = 30,
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
        $status = strtolower(trim($status));
        if ($status !== '' && in_array($status, [FaqItem::STATUS_ENABLED, FaqItem::STATUS_DISABLED], true)) {
            $query->where(FaqItem::schema_fields_STATUS, $status);
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(FaqItem::schema_fields_QUESTION, $like, 'like')
                ->where(FaqItem::schema_fields_ENTITY_UUID, $like, 'like', 'OR');
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
        if (!in_array($status, [FaqItem::STATUS_ENABLED, FaqItem::STATUS_DISABLED], true)) {
            $status = FaqItem::STATUS_ENABLED;
        }
        if ($typeCode === '' || $entityUuid === '') {
            throw new \InvalidArgumentException((string)__('类型与实体 UUID 不能为空。'));
        }
        if ($question === '' || $answer === '') {
            throw new \InvalidArgumentException((string)__('问题与答案不能为空。'));
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
        $model->setData(FaqItem::schema_fields_LOCALE_CODE, trim((string)($data['locale_code'] ?? '')));
        $model->setData(FaqItem::schema_fields_TYPE_CODE, $resolved['type_code']);
        $model->setData(FaqItem::schema_fields_ENTITY_UUID, $resolved['entity_uuid']);
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
     * Seed hub FAQs from FaqHubContent when no site rows exist for website.
     */
    public function seedSiteHubFaqs(int $websiteId = 0, string $localeCode = ''): int
    {
        $existing = $this->listForEntity('site', SiteFaqTypeProvider::ENTITY_UUID, $websiteId, $localeCode, false);
        if ($existing !== []) {
            return 0;
        }
        $hub = ObjectManager::getInstance(FaqHubContent::class);
        $created = 0;
        $sort = 0;
        foreach ($hub->faqs() as $row) {
            $this->save([
                'website_id' => $websiteId,
                'locale_code' => $localeCode,
                'type_code' => 'site',
                'entity_uuid' => SiteFaqTypeProvider::ENTITY_UUID,
                'question' => (string)($row['q'] ?? ''),
                'answer' => (string)($row['a'] ?? ''),
                'sort_order' => $sort++,
                'status' => FaqItem::STATUS_ENABLED,
            ]);
            $created++;
        }

        return $created;
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
            'locale_code' => (string)($row[FaqItem::schema_fields_LOCALE_CODE] ?? ''),
            'type_code' => (string)($row[FaqItem::schema_fields_TYPE_CODE] ?? ''),
            'entity_uuid' => (string)($row[FaqItem::schema_fields_ENTITY_UUID] ?? ''),
            'question' => (string)($row[FaqItem::schema_fields_QUESTION] ?? ''),
            'answer' => (string)($row[FaqItem::schema_fields_ANSWER] ?? ''),
            'sort_order' => (int)($row[FaqItem::schema_fields_SORT_ORDER] ?? 0),
            'status' => (string)($row[FaqItem::schema_fields_STATUS] ?? FaqItem::STATUS_ENABLED),
            'created_at' => (string)($row[FaqItem::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[FaqItem::schema_fields_UPDATED_AT] ?? ''),
        ];
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
