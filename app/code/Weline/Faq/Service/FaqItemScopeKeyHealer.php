<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Model\FaqItem;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * Backfill empty faq_key before UNIQUE index so legacy site hub rows can upgrade.
 */
final class FaqItemScopeKeyHealer
{
    public function heal(?Printing $printing = null): int
    {
        $printing ??= ObjectManager::getInstance(Printing::class);
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        try {
            $rows = $model->clear()->select()->fetchArray();
        } catch (\Throwable $e) {
            $printing->note(__('FaqItemScopeKeyHealer: skip (%{1})', [$e->getMessage()]));

            return 0;
        }
        if (!is_array($rows) || $rows === []) {
            $printing->note(__('FaqItemScopeKeyHealer: no rows'));

            return 0;
        }

        $updated = 0;
        $used = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $faqId = (int)($row[FaqItem::schema_fields_ID] ?? 0);
            if ($faqId <= 0) {
                continue;
            }
            $type = strtolower(trim((string)($row[FaqItem::schema_fields_TYPE_CODE] ?? '')));
            $entity = trim((string)($row[FaqItem::schema_fields_ENTITY_UUID] ?? ''));
            $websiteId = (int)($row[FaqItem::schema_fields_WEBSITE_ID] ?? 0);
            $store = strtolower(trim((string)($row[FaqItem::schema_fields_STORE_CODE] ?? '')));
            $channel = strtolower(trim((string)($row[FaqItem::schema_fields_CHANNEL_CODE] ?? '')));
            $locale = trim((string)($row[FaqItem::schema_fields_LOCALE_CODE] ?? ''));
            $key = strtolower(trim((string)($row[FaqItem::schema_fields_FAQ_KEY] ?? '')));
            $scopePrefix = implode('|', [$type, $entity, (string)$websiteId, $store, $channel, $locale]);

            if ($key === '') {
                $question = trim((string)($row[FaqItem::schema_fields_QUESTION] ?? ''));
                $key = $this->slug($question);
                if ($key === '') {
                    $key = 'legacy_' . $faqId;
                }
            }

            $candidate = $key;
            $n = 1;
            while (isset($used[$scopePrefix . '|' . $candidate])) {
                $candidate = substr($key, 0, 50) . '_' . $n;
                $n++;
            }
            $used[$scopePrefix . '|' . $candidate] = true;

            if ($candidate === (string)($row[FaqItem::schema_fields_FAQ_KEY] ?? '')) {
                continue;
            }

            try {
                $model->clear()->load($faqId);
                if ((int)$model->getData(FaqItem::schema_fields_ID) !== $faqId) {
                    continue;
                }
                $model->setData(FaqItem::schema_fields_FAQ_KEY, $candidate);
                if ($store === '' && $model->getData(FaqItem::schema_fields_STORE_CODE) === null) {
                    $model->setData(FaqItem::schema_fields_STORE_CODE, '');
                }
                if ($channel === '' && $model->getData(FaqItem::schema_fields_CHANNEL_CODE) === null) {
                    $model->setData(FaqItem::schema_fields_CHANNEL_CODE, '');
                }
                $model->setData(FaqItem::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
                $model->save();
                $updated++;
            } catch (\Throwable $e) {
                $printing->warning(__('FaqItemScopeKeyHealer: faq_id=%{1} %{2}', [$faqId, $e->getMessage()]));
            }
        }

        $printing->note(__('FaqItemScopeKeyHealer: updated=%{1}', [$updated]));

        return $updated;
    }

    private function slug(string $question): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $question) ?? '');
        $slug = trim($slug, '_');
        if ($slug === '') {
            // Prefer stable ascii key for CJK questions.
            return 'q_' . substr(sha1($question), 0, 12);
        }

        return substr($slug, 0, 64);
    }
}
