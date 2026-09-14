<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;
use Weline\Marketing\Model\Coupon\Coupon as CouponModel;
use Weline\Marketing\Model\Rule\Rule as RuleModel;
use Weline\Marketing\Service\CouponService;
use Weline\Marketing\Service\MarketingBaseCurrencyAmount;

/**
 * 优惠券管理控制器
 */
#[Acl('Weline_Marketing::commerce:marketing:coupons', '万能优惠券', 'circle', '万能优惠券管理', 'Weline_Backend::marketing_group')]
class Coupon extends BackendController
{
    /**
     * 优惠券列表
     */
    #[Acl('Weline_Marketing::commerce:marketing:coupons_index', '万能优惠券列表', 'list', '查看万能优惠券列表')]
    public function index(): string
    {
        try {
            /** @var \Weline\Marketing\Service\CouponSourceAttribution $attribution */
            $attribution = ObjectManager::getInstance(\Weline\Marketing\Service\CouponSourceAttribution::class);
            $attribution->backfillMissing(500);
            $sourceOptions = $attribution->listFilterOptions();

            $search = trim((string)$this->request->getGet('search', ''));
            $sourceType = trim((string)$this->request->getGet('source_type', ''));
            $status = trim((string)$this->request->getGet('status', ''));
            $type = trim((string)$this->request->getGet('type', ''));
            $page = max(1, (int)$this->request->getGet('page', 1));
            $pageSize = max(1, min(100, (int)$this->request->getGet('pageSize', 20)));

            /** @var CouponModel $coupon */
            $coupon = ObjectManager::getInstance(CouponModel::class, [], false);
            $coupon->clear();

            if ($search !== '') {
                $coupon->where(CouponModel::schema_fields_CODE, '%' . $search . '%', 'like');
            }
            if ($sourceType !== '') {
                $coupon->where(CouponModel::schema_fields_SOURCE_TYPE, $sourceType);
            }
            if ($status !== '') {
                $coupon->where(CouponModel::schema_fields_STATUS, $status);
            }
            if ($type !== '') {
                $coupon->where(CouponModel::schema_fields_TYPE, $type);
            }

            $filterParams = array_filter([
                'search' => $search,
                'source_type' => $sourceType,
                'status' => $status,
                'type' => $type,
                'pageSize' => (string)$pageSize,
            ], static fn($v) => $v !== null && $v !== '');

            $coupon
                ->order(CouponModel::schema_fields_ID, 'DESC')
                ->pagination($page, $pageSize, $filterParams)
                ->select()
                ->fetch();

            $items = $coupon->getItems() ?: [];
            $enriched = [];
            foreach ($items as $row) {
                if ($row instanceof CouponModel) {
                    $row = $row->getData();
                } elseif (!is_array($row)) {
                    $row = (array)$row;
                }
                $meta = $attribution->describe($row);
                $row['source_label'] = $meta['label'];
                $row['source_tone'] = $meta['tone'];
                $enriched[] = $row;
            }

            $this->assign('coupons', $enriched);
            $this->assign('pagination', $coupon->getPagination());
            $this->assign('filter_search', $search);
            $this->assign('filter_source_type', $sourceType);
            $this->assign('filter_status', $status);
            $this->assign('filter_type', $type);
            $this->assign('source_options', $sourceOptions);
            $this->assign(
                'base_currency',
                ObjectManager::getInstance(MarketingBaseCurrencyAmount::class)->baseCurrency()
            );

            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载优惠券列表失败：%{1}', $e->getMessage()));
            $this->assign('coupons', []);
            $this->assign('pagination', '');
            $this->assign('filter_search', '');
            $this->assign('filter_source_type', '');
            $this->assign('filter_status', '');
            $this->assign('filter_type', '');
            $this->assign('source_options', []);
            $this->assign('base_currency', 'CNY');
            return $this->fetch();
        }
    }

    #[Acl('Weline_Marketing::commerce:marketing:coupons_add', '添加万能优惠券', 'plus', '打开万能优惠券新建表单')]
    public function getAdd(): string
    {
        return $this->renderForm();
    }

    #[Acl('Weline_Marketing::commerce:marketing:coupons_add', '编辑万能优惠券', 'edit', '编辑万能优惠券')]
    public function getEdit(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        /** @var CouponModel $coupon */
        $coupon = ObjectManager::getInstance(CouponModel::class);
        $coupon->load($id);
        if (!$coupon->getId()) {
            Message::error(__('优惠券不存在'));
            return $this->redirect('marketing/backend/coupon/index');
        }

        return $this->renderForm($coupon);
    }

    private function renderForm(?CouponModel $coupon = null): string
    {
        try {
            /** @var RuleModel $rules */
            $rules = ObjectManager::getInstance(RuleModel::class);
            $rules->order(RuleModel::schema_fields_ID, 'DESC')->select()->fetch();
            $this->assign('rules', $rules->getItems());
        } catch (\Throwable $exception) {
            Message::error(__('加载优惠券表单失败：%{1}', $exception->getMessage()));
            $this->assign('rules', []);
        }

        $this->assign('coupon', $coupon);
        $this->assign(
            'base_currency',
            ObjectManager::getInstance(MarketingBaseCurrencyAmount::class)->baseCurrency()
        );
        /** @var \Weline\Marketing\Service\CouponSourceAttribution $attribution */
        $attribution = ObjectManager::getInstance(\Weline\Marketing\Service\CouponSourceAttribution::class);
        $this->assign(
            'coupon_source',
            $coupon !== null ? $attribution->describe($coupon) : [
                'label' => (string)__('后台手工'),
                'tone' => 'neutral',
                'source_type' => CouponModel::SOURCE_TYPE_MANUAL,
            ]
        );

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::commerce:marketing:coupons_save', '保存万能优惠券', 'save', '保存万能优惠券')]
    public function postSave(): string
    {
        $id = (int)$this->request->getPost('id', 0);
        $payload = [
            CouponModel::schema_fields_RULE_ID => (int)$this->request->getPost('rule_id', 0),
            CouponModel::schema_fields_CODE => trim((string)$this->request->getPost('code', '')),
            CouponModel::schema_fields_TYPE => trim((string)$this->request->getPost('type', '')),
            CouponModel::schema_fields_DISCOUNT_VALUE => (float)$this->request->getPost('discount_value', 0),
            CouponModel::schema_fields_MIN_AMOUNT => (float)$this->request->getPost('min_amount', 0),
            CouponModel::schema_fields_USAGE_LIMIT => (int)$this->request->getPost('usage_limit', 0),
            CouponModel::schema_fields_CUSTOMER_LIMIT => (int)$this->request->getPost('customer_limit', 1),
            CouponModel::schema_fields_STATUS => trim((string)$this->request->getPost('status', CouponModel::STATUS_ACTIVE)),
            CouponModel::schema_fields_START_DATE => trim((string)$this->request->getPost('start_date', '')),
            CouponModel::schema_fields_END_DATE => trim((string)$this->request->getPost('end_date', '')),
        ];

        try {
            /** @var CouponService $service */
            $service = ObjectManager::getInstance(CouponService::class);
            if ($id > 0) {
                $service->updateCoupon($id, $payload);
                Message::success(__('优惠券更新成功'));
            } else {
                $service->createCoupon($payload);
                Message::success(__('优惠券保存成功'));
            }
        } catch (\Throwable $exception) {
            Message::error(__('保存优惠券失败：%{1}', $exception->getMessage()));

            return $id > 0
                ? $this->redirect('marketing/backend/coupon/edit', ['id' => $id])
                : $this->redirect('marketing/backend/coupon/add');
        }

        return $this->redirect('marketing/backend/coupon/index');
    }
}
