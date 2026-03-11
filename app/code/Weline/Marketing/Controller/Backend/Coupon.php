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
use Weline\Marketing\Service\CouponService;

/**
 * 优惠券管理控制器
 */
#[Acl('Weline_Marketing::coupon', '优惠券管理', 'mdi-ticket-percent', '优惠券管理', 'Weline_Backend::marketing_group')]
class Coupon extends BackendController
{
    /**
     * 优惠券列表
     */
    #[Acl('Weline_Marketing::coupon_list', '优惠券列表', 'mdi-format-list-bulleted', '查看优惠券列表')]
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
}

