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

/**
 * 优惠券管理控制器
 */
#[Acl('Weline_Marketing::coupon', '优惠券管理', 'circle', '优惠券管理', 'Weline_Backend::marketing_group')]
class Coupon extends BackendController
{
    /**
     * 优惠券列表
     */
    #[Acl('Weline_Marketing::coupon_list', '优惠券列表', 'list', '查看优惠券列表')]
    public function index(): string
    {
        try {
            /** @var CouponModel $coupon */
            $coupon = ObjectManager::getInstance(CouponModel::class);
            
            if ($search = $this->request->getGet('search')) {
                $coupon->where('code', "%{$search}%", 'like');
            }
            
            $coupon->pagination()->select()->fetch();
            $this->assign('coupons', $coupon->getItems());
            $this->assign('pagination', $coupon->getPagination());
            
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载优惠券列表失败：%{1}', $e->getMessage()));
            $this->assign('coupons', []);
            return $this->fetch();
        }
    }

    #[Acl('Weline_Marketing::coupon_add', '添加优惠券', 'plus', '打开优惠券新建表单')]
    public function getAdd(): string
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

        return $this->fetch('form');
    }

    #[Acl('Weline_Marketing::coupon_save', '保存优惠券', 'save', '保存优惠券')]
    public function postSave(): string
    {
        try {
            /** @var CouponService $service */
            $service = ObjectManager::getInstance(CouponService::class);
            $service->createCoupon([
                CouponModel::schema_fields_RULE_ID => (int)$this->request->getPost('rule_id', 0),
                CouponModel::schema_fields_CODE => trim((string)$this->request->getPost('code', '')),
                CouponModel::schema_fields_TYPE => trim((string)$this->request->getPost('type', '')),
                CouponModel::schema_fields_DISCOUNT_VALUE => (float)$this->request->getPost('discount_value', 0),
                CouponModel::schema_fields_MIN_AMOUNT => (float)$this->request->getPost('min_amount', 0),
                CouponModel::schema_fields_USAGE_LIMIT => (int)$this->request->getPost('usage_limit', 0),
                CouponModel::schema_fields_CUSTOMER_LIMIT => (int)$this->request->getPost('customer_limit', 1),
                CouponModel::schema_fields_STATUS => trim((string)$this->request->getPost('status', CouponModel::STATUS_ACTIVE)),
            ]);
            Message::success(__('优惠券保存成功'));
        } catch (\Throwable $exception) {
            Message::error(__('保存优惠券失败：%{1}', $exception->getMessage()));

            return $this->redirect('marketing/backend/coupon/getAdd');
        }

        return $this->redirect('marketing/backend/coupon/index');
    }
}
