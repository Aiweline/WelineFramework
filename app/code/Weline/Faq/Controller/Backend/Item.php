<?php

declare(strict_types=1);

namespace Weline\Faq\Controller\Backend;

use Weline\Faq\Model\FaqItem;
use Weline\Faq\Service\FaqService;
use Weline\Faq\Service\FaqTypeRegistry;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Faq::item', 'FAQ 条目', 'mdi-help-circle-outline', '管理万能 FAQ 条目', 'Weline_Backend::cms_group')]
final class Item extends BackendController
{
    public function __construct(
        private readonly FaqService $faqs,
        private readonly FaqTypeRegistry $types,
    ) {
    }

    #[Acl('Weline_Faq::item_listing', 'FAQ 条目列表', 'mdi-format-list-bulleted', '查看 FAQ 条目')]
    public function getListing(): string
    {
        $websiteId = $this->optionalFilterIdFromQuery('website_id');
        $typeCode = strtolower(trim((string)$this->request->getGet('type_code', '')));
        $status = strtolower(trim((string)$this->request->getGet('status', '')));
        $search = trim((string)$this->request->getGet('search', ''));
        $page = max(1, (int)$this->request->getGet('page', 1));
        $result = $this->faqs->listing($websiteId, $typeCode, $status, $search, $page, 30);

        $this->assign('items', $result['items']);
        $this->assign('total', $result['total']);
        $this->assign('pagination', $result['pagination']);
        $this->assign('website_id', $websiteId === null ? '' : (string)$websiteId);
        $this->assign('type_code', $typeCode);
        $this->assign('status', $status);
        $this->assign('search', $search);
        $this->assign('type_codes', $this->types->codes());
        $this->assign('websites', $this->loadWebsiteOptions());
        $this->assign('listing_url', $this->getUrl('faq/backend/item/listing'));
        $this->assign('edit_url', $this->getUrl('faq/backend/item/edit'));
        $this->assign('new_url', $this->getUrl('faq/backend/item/edit'));
        $this->assign('save_url', $this->getUrl('faq/backend/item/save'));
        $this->assign('delete_url', $this->getUrl('faq/backend/item/delete'));

        return $this->fetch('Weline_Faq::templates/Backend/Item/listing.phtml');
    }

    #[Acl('Weline_Faq::item_edit', '编辑 FAQ 条目', 'mdi-pencil', '新建或编辑 FAQ 条目')]
    public function getEdit(): string
    {
        $faqId = max(0, (int)$this->request->getGet('faq_id', 0));
        $item = $faqId > 0 ? $this->faqs->get($faqId) : null;
        if ($faqId > 0 && $item === null) {
            $this->getMessageManager()->addError((string)__('FAQ 条目不存在。'));

            return $this->redirect($this->getUrl('faq/backend/item/listing'));
        }

        $this->assign('item', $item ?? [
            'faq_id' => 0,
            'website_id' => 0,
            'store_code' => '',
            'channel_code' => '',
            'locale_code' => '',
            'type_code' => 'site',
            'entity_uuid' => 'site',
            'faq_key' => '',
            'question' => '',
            'answer' => '',
            'sort_order' => 0,
            'status' => FaqItem::STATUS_ENABLED,
        ]);
        $this->assign('template_packs', \Weline\Faq\Service\FaqTemplatePacks::codes());
        $this->assign('type_codes', $this->types->codes());
        $this->assign('websites', $this->loadWebsiteOptions());
        $this->assign('listing_url', $this->getUrl('faq/backend/item/listing'));
        $this->assign('save_url', $this->getUrl('faq/backend/item/save'));

        return $this->fetch('Weline_Faq::templates/Backend/Item/edit.phtml');
    }

    #[Acl('Weline_Faq::item_save', '保存 FAQ 条目', 'mdi-content-save', '保存 FAQ 条目')]
    public function postSave(): string
    {
        try {
            $saved = $this->faqs->save([
                'faq_id' => (int)$this->request->getPost('faq_id', 0),
                'website_id' => (int)$this->request->getPost('website_id', 0),
                'store_code' => (string)$this->request->getPost('store_code', ''),
                'channel_code' => (string)$this->request->getPost('channel_code', ''),
                'locale_code' => (string)$this->request->getPost('locale_code', ''),
                'type_code' => (string)$this->request->getPost('type_code', ''),
                'entity_uuid' => (string)$this->request->getPost('entity_uuid', ''),
                'faq_key' => (string)$this->request->getPost('faq_key', ''),
                'question' => (string)$this->request->getPost('question', ''),
                'answer' => (string)$this->request->getPost('answer', ''),
                'sort_order' => (int)$this->request->getPost('sort_order', 0),
                'status' => (string)$this->request->getPost('status', FaqItem::STATUS_ENABLED),
            ]);
            $this->getMessageManager()->addSuccess((string)__('FAQ 条目已保存。'));

            return $this->redirect($this->getUrl('faq/backend/item/edit', [
                'faq_id' => (int)($saved['faq_id'] ?? 0),
            ]));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());

            return $this->redirect($this->getUrl('faq/backend/item/listing'));
        }
    }

    #[Acl('Weline_Faq::item_delete', '删除 FAQ 条目', 'mdi-delete', '删除 FAQ 条目')]
    public function postDelete(): string
    {
        try {
            $this->faqs->delete(max(0, (int)$this->request->getPost('faq_id', 0)));
            $this->getMessageManager()->addSuccess((string)__('FAQ 条目已删除。'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect($this->getUrl('faq/backend/item/listing'));
    }

    private function optionalFilterIdFromQuery(string $key): ?int
    {
        if (!$this->request->hasGet($key)) {
            return null;
        }
        $raw = trim((string)$this->request->getParameterBag()->getQuery($key, ''));
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            return null;
        }

        return max(0, (int)$raw);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadWebsiteOptions(): array
    {
        try {
            $queried = w_query('websites', 'getWebsiteSelectOptions', []);
            if (!is_array($queried)) {
                return [];
            }
            $out = [];
            foreach ($queried as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $value = trim((string)($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $out[] = [
                    'website_id' => (int)$value,
                    'label' => trim((string)($row['label'] ?? $value)),
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
