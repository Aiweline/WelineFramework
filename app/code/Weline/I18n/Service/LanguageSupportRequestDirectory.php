<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestDirectoryInterface;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestWorkflowInterface;
use Weline\I18n\Model\LanguageSupportRequest;
use Weline\I18n\Model\LanguageSupportRequestItem;
use Weline\I18n\Model\Locale;

final class LanguageSupportRequestDirectory implements
    LanguageSupportRequestDirectoryInterface,
    LanguageSupportRequestWorkflowInterface
{
    public function __construct(
        private readonly LanguageSupportRequest $requests,
        private readonly LanguageSupportRequestItem $items,
        private readonly Locale $locales,
    ) {
    }

    public function readyForWebsite(int $websiteId): array
    {
        if ($websiteId < 0) {
            return [];
        }
        $rows = (clone $this->items)->clearData()->clearQuery()
            ->where(LanguageSupportRequestItem::schema_fields_WEBSITE_ID, $websiteId)
            ->where(LanguageSupportRequestItem::schema_fields_STATUS, LanguageSupportRequestItem::STATUS_READY)
            ->order(LanguageSupportRequestItem::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();

        $aggregated = [];
        foreach ((array)$rows as $row) {
            $locale = (string)($row[LanguageSupportRequestItem::schema_fields_LOCALE] ?? '');
            if ($locale === '') {
                continue;
            }
            if (!isset($aggregated[$locale])) {
                $aggregated[$locale] = [
                    'locale_code' => $locale,
                    'country_code' => (string)($row[LanguageSupportRequestItem::schema_fields_COUNTRY] ?? ''),
                    'request_count' => 0,
                    'latest_requested_at' => (string)($row[LanguageSupportRequestItem::schema_fields_CREATED_AT] ?? ''),
                    'item_ids' => [],
                ];
            }
            $aggregated[$locale]['request_count']++;
            $aggregated[$locale]['item_ids'][] = (int)($row[LanguageSupportRequestItem::schema_fields_ID] ?? 0);
        }
        return \array_values($aggregated);
    }

    public function adminList(array $filters = []): array
    {
        $itemQuery = (clone $this->items)->clearData()->clearQuery();
        foreach ([
            LanguageSupportRequestItem::schema_fields_STATUS => 'status',
            LanguageSupportRequestItem::schema_fields_WEBSITE_ID => 'website_id',
            LanguageSupportRequestItem::schema_fields_COUNTRY => 'country_code',
            LanguageSupportRequestItem::schema_fields_LOCALE => 'locale_code',
        ] as $field => $filterKey) {
            $value = $filters[$filterKey] ?? null;
            if ($value !== null && $value !== '') {
                $itemQuery->where($field, $field === LanguageSupportRequestItem::schema_fields_WEBSITE_ID ? (int)$value : (string)$value);
            }
        }
        if (!empty($filters['date_from'])) {
            $itemQuery->where(LanguageSupportRequestItem::schema_fields_CREATED_AT, ['>=', (string)$filters['date_from'] . ' 00:00:00']);
        }
        if (!empty($filters['date_to'])) {
            $itemQuery->where(LanguageSupportRequestItem::schema_fields_CREATED_AT, ['<=', (string)$filters['date_to'] . ' 23:59:59']);
        }
        $rows = $itemQuery
            ->order(LanguageSupportRequestItem::schema_fields_ID, 'DESC')
            ->limit(500)
            ->select()
            ->fetchArray();

        $headerIds = [];
        foreach ((array)$rows as $row) {
            $headerIds[(int)($row[LanguageSupportRequestItem::schema_fields_REQUEST_ID] ?? 0)] = true;
        }
        $headers = [];
        if ($headerIds !== []) {
            $headerRows = (clone $this->requests)->clearData()->clearQuery()
                ->where(LanguageSupportRequest::schema_fields_ID, \array_keys($headerIds), 'IN')
                ->select()
                ->fetchArray();
            foreach ((array)$headerRows as $row) {
                $headers[(int)($row[LanguageSupportRequest::schema_fields_ID] ?? 0)] = $row;
            }
        }

        $items = [];
        $summary = [];
        $aggregateMap = [];
        foreach ((array)$rows as $row) {
            $requestId = (int)($row[LanguageSupportRequestItem::schema_fields_REQUEST_ID] ?? 0);
            $header = $headers[$requestId] ?? [];
            $status = (string)($row[LanguageSupportRequestItem::schema_fields_STATUS] ?? '');
            $summary[$status] = ($summary[$status] ?? 0) + 1;
            $websiteId = (int)($row[LanguageSupportRequestItem::schema_fields_WEBSITE_ID] ?? 0);
            $localeCode = (string)($row[LanguageSupportRequestItem::schema_fields_LOCALE] ?? '');
            $aggregateKey = $websiteId . '|' . $localeCode;
            $applicantKey = !empty($header[LanguageSupportRequest::schema_fields_CUSTOMER_ID])
                ? 'customer:' . (int)$header[LanguageSupportRequest::schema_fields_CUSTOMER_ID]
                : 'email:' . \strtolower((string)($header[LanguageSupportRequest::schema_fields_EMAIL] ?? ''));
            if (!isset($aggregateMap[$aggregateKey])) {
                $aggregateMap[$aggregateKey] = [
                    'website_id' => $websiteId,
                    'locale_code' => $localeCode,
                    'country_code' => (string)($row[LanguageSupportRequestItem::schema_fields_COUNTRY] ?? ''),
                    'applicants' => [],
                    'request_count' => 0,
                    'latest_requested_at' => '',
                ];
            }
            $aggregateMap[$aggregateKey]['applicants'][$applicantKey] = true;
            $aggregateMap[$aggregateKey]['request_count']++;
            $createdAt = (string)($row[LanguageSupportRequestItem::schema_fields_CREATED_AT] ?? '');
            if ($createdAt > $aggregateMap[$aggregateKey]['latest_requested_at']) {
                $aggregateMap[$aggregateKey]['latest_requested_at'] = $createdAt;
            }
            $items[] = [
                'item_id' => (int)($row[LanguageSupportRequestItem::schema_fields_ID] ?? 0),
                'request_id' => $requestId,
                'public_id' => (string)($header[LanguageSupportRequest::schema_fields_PUBLIC_ID] ?? ''),
                'website_id' => $websiteId,
                'customer_id' => isset($header[LanguageSupportRequest::schema_fields_CUSTOMER_ID])
                    ? (int)$header[LanguageSupportRequest::schema_fields_CUSTOMER_ID]
                    : null,
                'name' => (string)($header[LanguageSupportRequest::schema_fields_NAME] ?? ''),
                'email' => (string)($header[LanguageSupportRequest::schema_fields_EMAIL] ?? ''),
                'source_domain' => (string)($header[LanguageSupportRequest::schema_fields_SOURCE_DOMAIN] ?? ''),
                'locale_code' => $localeCode,
                'country_code' => (string)($row[LanguageSupportRequestItem::schema_fields_COUNTRY] ?? ''),
                'status' => $status,
                'review_note' => (string)($row[LanguageSupportRequestItem::schema_fields_REVIEW_NOTE] ?? ''),
                'created_at' => (string)($row[LanguageSupportRequestItem::schema_fields_CREATED_AT] ?? ''),
                'reviewed_at' => (string)($row[LanguageSupportRequestItem::schema_fields_REVIEWED_AT] ?? ''),
                'ready_at' => (string)($row[LanguageSupportRequestItem::schema_fields_READY_AT] ?? ''),
            ];
        }

        $aggregates = [];
        foreach ($aggregateMap as $aggregate) {
            $aggregate['applicant_count'] = \count($aggregate['applicants']);
            unset($aggregate['applicants']);
            $aggregates[] = $aggregate;
        }

        return [
            'items' => $items,
            'aggregates' => $aggregates,
            'summary' => $summary,
            'total' => \count($items),
        ];
    }

    public function review(array $itemIds, string $status, int $reviewerId, string $note = ''): int
    {
        $status = \strtolower(\trim($status));
        if (!\in_array($status, [
            LanguageSupportRequestItem::STATUS_PENDING,
            LanguageSupportRequestItem::STATUS_ACCEPTED,
            LanguageSupportRequestItem::STATUS_REJECTED,
        ], true)) {
            throw new \InvalidArgumentException((string)__('不允许将语言申请直接变更为该状态'));
        }
        $itemIds = \array_values(\array_unique(\array_filter(\array_map('intval', $itemIds), static fn(int $id): bool => $id > 0)));
        if ($itemIds === []) {
            throw new \InvalidArgumentException((string)__('请选择要审核的语言申请'));
        }

        $now = \date('Y-m-d H:i:s');
        $count = 0;
        foreach ($itemIds as $itemId) {
            $item = clone $this->items;
            $item->clearData()->clearQuery()->where(LanguageSupportRequestItem::schema_fields_ID, $itemId)->find()->fetch();
            if (!$item->getId()) {
                continue;
            }
            $item->setData(LanguageSupportRequestItem::schema_fields_STATUS, $status)
                ->setData(LanguageSupportRequestItem::schema_fields_REVIEWED_BY, $reviewerId)
                ->setData(LanguageSupportRequestItem::schema_fields_REVIEW_NOTE, \mb_substr(\trim($note), 0, 1000))
                ->setData(LanguageSupportRequestItem::schema_fields_REVIEWED_AT, $now)
                ->setData(LanguageSupportRequestItem::schema_fields_READY_AT, null)
                ->setData(LanguageSupportRequestItem::schema_fields_ASSIGNED_AT, null)
                ->setData(LanguageSupportRequestItem::schema_fields_UPDATED_AT, $now)
                ->save();
            $count++;
        }
        if ($status === LanguageSupportRequestItem::STATUS_ACCEPTED) {
            $this->recalculateReady();
        }
        return $count;
    }

    public function markAssigned(int $websiteId, array $locales, int $reviewerId): int
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('网站 ID 无效'));
        }
        $locales = \array_values(\array_unique(\array_filter(\array_map('strval', $locales))));
        if ($locales === []) {
            return 0;
        }
        $rows = (clone $this->items)->clearData()->clearQuery()
            ->where(LanguageSupportRequestItem::schema_fields_WEBSITE_ID, $websiteId)
            ->where(LanguageSupportRequestItem::schema_fields_LOCALE, $locales, 'IN')
            ->where(LanguageSupportRequestItem::schema_fields_STATUS, LanguageSupportRequestItem::STATUS_READY)
            ->select()
            ->fetchArray();
        $now = \date('Y-m-d H:i:s');
        $count = 0;
        foreach ((array)$rows as $row) {
            $item = clone $this->items;
            $item->clearData()->clearQuery()
                ->where(LanguageSupportRequestItem::schema_fields_ID, (int)$row[LanguageSupportRequestItem::schema_fields_ID])
                ->find()
                ->fetch();
            if (!$item->getId()) {
                continue;
            }
            $item->setData(LanguageSupportRequestItem::schema_fields_STATUS, LanguageSupportRequestItem::STATUS_ASSIGNED)
                ->setData(LanguageSupportRequestItem::schema_fields_REVIEWED_BY, $reviewerId)
                ->setData(LanguageSupportRequestItem::schema_fields_ASSIGNED_AT, $now)
                ->setData(LanguageSupportRequestItem::schema_fields_UPDATED_AT, $now)
                ->save();
            $count++;
        }
        return $count;
    }

    public function recalculateReady(?array $locales = null): int
    {
        $query = (clone $this->items)->clearData()->clearQuery()
            ->where(LanguageSupportRequestItem::schema_fields_STATUS, [
                LanguageSupportRequestItem::STATUS_ACCEPTED,
                LanguageSupportRequestItem::STATUS_READY,
            ], 'IN');
        if ($locales !== null && $locales !== []) {
            $query->where(LanguageSupportRequestItem::schema_fields_LOCALE, $locales, 'IN');
        }
        $rows = $query->select()->fetchArray();
        $changed = 0;
        $now = \date('Y-m-d H:i:s');
        foreach ((array)$rows as $row) {
            $locale = (string)($row[LanguageSupportRequestItem::schema_fields_LOCALE] ?? '');
            $ready = $this->localeIsRuntimeReady($locale);
            $target = $ready
                ? LanguageSupportRequestItem::STATUS_READY
                : LanguageSupportRequestItem::STATUS_ACCEPTED;
            if ((string)($row[LanguageSupportRequestItem::schema_fields_STATUS] ?? '') === $target) {
                continue;
            }
            $item = clone $this->items;
            $item->clearData()->clearQuery()
                ->where(LanguageSupportRequestItem::schema_fields_ID, (int)$row[LanguageSupportRequestItem::schema_fields_ID])
                ->find()
                ->fetch();
            if (!$item->getId()) {
                continue;
            }
            $item->setData(LanguageSupportRequestItem::schema_fields_STATUS, $target)
                ->setData(LanguageSupportRequestItem::schema_fields_READY_AT, $ready ? $now : null)
                ->setData(LanguageSupportRequestItem::schema_fields_UPDATED_AT, $now)
                ->save();
            $changed++;
        }
        return $changed;
    }

    private function localeIsRuntimeReady(string $localeCode): bool
    {
        if ($localeCode === '') {
            return false;
        }
        $locale = clone $this->locales;
        $locale->clearData()->clearQuery()
            ->where(Locale::schema_fields_CODE, $localeCode)
            ->where(Locale::schema_fields_IS_INSTALL, 1)
            ->where(Locale::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        if (!$locale->getId()) {
            return false;
        }
        return \is_file(BP . 'generated' . DS . 'language' . DS . $localeCode . '.php')
            || \is_file(BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'I18n' . DS . 'i18n' . DS . $localeCode . '.csv');
    }
}
