<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Backend\Coupon;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Promotion\Model\Coupon;
use Weline\Framework\Manager\ObjectManager;

/**
 * 保存优惠券控制器
 */
class Save extends BackendController
{
    /**
     * 保存优惠券
     */
    public function index(): string
    {
        try {
            /** @var Coupon $coupon */
            $coupon = ObjectManager::getInstance(Coupon::class);
            
            $couponId = (int)($this->request->getParam('coupon_id') ?? 0);
            if ($couponId) {
                $coupon->load($couponId);
            }
            
            $couponData = [
                Coupon::fields_code => $this->request->getParam('code') ?? '',
                Coupon::fields_name => $this->request->getParam('name') ?? '',
                Coupon::fields_type => $this->request->getParam('type') ?? 'fixed',
                Coupon::fields_discount_amount => (float)($this->request->getParam('discount_amount') ?? 0),
                Coupon::fields_discount_percent => (float)($this->request->getParam('discount_percent') ?? 0),
                Coupon::fields_minimum_amount => (float)($this->request->getParam('minimum_amount') ?? 0),
                Coupon::fields_maximum_discount => (float)($this->request->getParam('maximum_discount') ?? 0),
                Coupon::fields_start_date => $this->request->getParam('start_date') ?? '',
                Coupon::fields_end_date => $this->request->getParam('end_date') ?? '',
                Coupon::fields_usage_limit => (int)($this->request->getParam('usage_limit') ?? 0),
                Coupon::fields_is_active => (int)($this->request->getParam('is_active') ?? 1),
                Coupon::fields_updated_at => date('Y-m-d H:i:s'),
            ];
            
            $coupon->setData($couponData);
            
            if (!$coupon->getId()) {
                $coupon->setCreatedAt(date('Y-m-d H:i:s'));
            }
            
            $coupon->save();
            
            return $this->fetchJson(['success' => true, 'message' => __('优惠券保存成功')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
