<?php

declare(strict_types=1);

namespace Weline\Review\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Review\Model\ProductReview;
use Weline\Review\Service\ReviewAdminService;

#[Acl('Weline_Review::root', '评论管理', 'mdi-comment-text-multiple-outline', '管理网站评论', 'Weline_Backend::commerce:catalog:group')]
final class Review extends BackendController
{
    public function __construct(private readonly ReviewAdminService $reviews)
    {
    }

    #[Acl('Weline_Review::list', '评论列表', 'mdi-format-list-bulleted', '查看所有评论', 'Weline_Review::root')]
    public function index(): string
    {
        $status = strtolower(trim((string)$this->request->getGet('status', '')));
        if ($status !== '' && !in_array($status, $this->reviews->statuses(), true)) {
            $status = '';
        }
        $websiteId = $this->optionalFilterIdFromQuery('website_id');
        $storeId = $this->optionalFilterIdFromQuery('store_id');
        $page = max(1, (int)$this->request->getGet('page', 1));
        $result = $this->reviews->listing($status, $page, 30, $websiteId, $storeId);

        $websiteSelect = $this->buildWebsiteSelect($websiteId);
        $storeSelect = $this->buildStoreSelect($websiteId, $storeId);

        $this->assign('reviews', $result['items']);
        $this->assign('pagination', $result['pagination']);
        $this->assign('total', $result['total']);
        $this->assign('status_filter', $status);
        $this->assign('website_id_filter', $websiteId === null ? '' : (string)$websiteId);
        $this->assign('store_id_filter', $storeId === null ? '' : (string)$storeId);
        $this->assign('allowed_statuses', $this->reviews->statuses());
        $this->assign('websiteSelectValue', $websiteSelect['value']);
        $this->assign('websiteSelectDisplay', $websiteSelect['display']);
        $this->assign('websiteSelectOptionsJson', $websiteSelect['options_json']);
        $this->assign('storeSelectValue', $storeSelect['value']);
        $this->assign('storeSelectDisplay', $storeSelect['display']);
        $this->assign('storeSelectOptionsJson', $storeSelect['options_json']);

        return (string)$this->fetch();
    }

    #[Acl('Weline_Review::moderate', '审核评论', 'mdi-shield-check-outline', '通过或拒绝评论', 'Weline_Review::root')]
    public function postModerate(): string
    {
        $reviewId = max(0, (int)$this->request->getPost('review_id', 0));
        $targetStatus = strtolower(trim((string)$this->request->getPost('status', '')));
        $statusFilter = strtolower(trim((string)$this->request->getPost('status_filter', '')));
        if ($statusFilter !== '' && !in_array($statusFilter, $this->reviews->statuses(), true)) {
            $statusFilter = '';
        }
        $websiteFilter = $this->optionalFilterIdFromPost('website_id_filter');
        $storeFilter = $this->optionalFilterIdFromPost('store_id_filter');

        try {
            $this->reviews->moderate($reviewId, $targetStatus);
            $message = $targetStatus === ProductReview::STATUS_APPROVED
                ? __('评论已通过。')
                : __('评论已拒绝。');
            $this->getMessageManager()->addSuccess($message);
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('审核操作失败：%{1}', [$exception->getMessage()]));
        }

        $query = [];
        if ($statusFilter !== '') {
            $query['status'] = $statusFilter;
        }
        if ($websiteFilter !== null) {
            $query['website_id'] = (string)$websiteFilter;
        }
        if ($storeFilter !== null) {
            $query['store_id'] = (string)$storeFilter;
        }
        $redirect = '*/backend/review';
        if ($query !== []) {
            $redirect .= '?' . http_build_query($query);
        }

        return (string)$this->redirect($redirect);
    }

    /**
     * 从 GET Query 读取可空非负整型筛选（保留 0；勿走可能被空串占位的 Request::_data）。
     */
    private function optionalFilterIdFromQuery(string $key): ?int
    {
        if (!$this->request->hasGet($key)) {
            return null;
        }

        return $this->optionalNonNegativeInt($this->request->getParameterBag()->getQuery($key, ''));
    }

    /**
     * 从 POST 读取可空非负整型筛选（保留 0）。
     */
    private function optionalFilterIdFromPost(string $key): ?int
    {
        $bag = $this->request->getPost();
        if (!is_array($bag) || !array_key_exists($key, $bag)) {
            return null;
        }

        return $this->optionalNonNegativeInt($bag[$key]);
    }

    private function optionalNonNegativeInt(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $raw)) {
            return null;
        }

        return max(0, (int)$raw);
    }

    /**
     * @return array{value:string,display:string,options_json:string}
     */
    private function buildWebsiteSelect(?int $websiteId): array
    {
        $options = [];
        try {
            $queried = w_query('websites', 'getWebsiteSelectOptions', []);
            if (is_array($queried)) {
                foreach ($queried as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $value = trim((string)($row['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $options[] = [
                        'value' => $value,
                        'label' => trim((string)($row['label'] ?? $value)),
                        'meta' => trim((string)($row['meta'] ?? '')),
                    ];
                }
            }
        } catch (\Throwable) {
            $options = [];
        }

        $value = $websiteId === null ? '' : (string)$websiteId;
        $display = '';
        foreach ($options as $option) {
            if ((string)($option['value'] ?? '') !== $value) {
                continue;
            }
            $display = trim((string)($option['label'] ?? ''));
            if ($display === '') {
                $display = '#' . $value;
            }
            break;
        }
        if ($display === '' && $value !== '') {
            $display = '#' . $value;
        }

        return [
            'value' => $value,
            'display' => $display,
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];
    }

    /**
     * @return array{value:string,display:string,options_json:string}
     */
    private function buildStoreSelect(?int $websiteId, ?int $storeId): array
    {
        $options = [];
        $websiteIds = [];
        if ($websiteId !== null) {
            $websiteIds[] = $websiteId;
        } else {
            try {
                $websites = w_query('websites', 'getWebsiteList', []);
                if (is_array($websites)) {
                    foreach ($websites as $website) {
                        if (!is_array($website)) {
                            continue;
                        }
                        $id = (int)($website['website_id'] ?? $website['id'] ?? -1);
                        if ($id >= 0) {
                            $websiteIds[] = $id;
                        }
                    }
                }
            } catch (\Throwable) {
                $websiteIds = [];
            }
        }

        foreach ($websiteIds as $scopeWebsiteId) {
            try {
                $stores = w_query('websites', 'getStoreCatalogV1', ['website_id' => $scopeWebsiteId]);
            } catch (\Throwable) {
                $stores = [];
            }
            if (!is_array($stores)) {
                continue;
            }
            foreach ($stores as $store) {
                if (!is_array($store)) {
                    continue;
                }
                $id = (int)($store['store_id'] ?? $store['id'] ?? -1);
                if ($id < 0) {
                    continue;
                }
                $name = trim((string)($store['name'] ?? ''));
                $code = trim((string)($store['code'] ?? ''));
                $label = $name !== '' ? $name : ($code !== '' ? $code : ('#' . $id));
                if ($websiteId === null && $code !== '') {
                    $label .= ' (' . $code . ')';
                }
                $options[] = [
                    'value' => (string)$id,
                    'label' => $label,
                    'meta' => $code,
                ];
            }
        }

        $value = $storeId === null ? '' : (string)$storeId;
        $display = '';
        foreach ($options as $option) {
            if ((string)($option['value'] ?? '') !== $value) {
                continue;
            }
            $display = trim((string)($option['label'] ?? ''));
            if ($display === '') {
                $display = '#' . $value;
            }
            break;
        }
        if ($display === '' && $value !== '') {
            $display = '#' . $value;
        }

        return [
            'value' => $value,
            'display' => $display,
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];
    }
}
