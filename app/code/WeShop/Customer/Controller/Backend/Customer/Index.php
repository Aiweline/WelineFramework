<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Backend\Customer;

use WeShop\Customer\Model\Customer;
use Weline\Admin\Controller\BaseController;

/**
 * 客户管理后台控制器
 */
class Index extends BaseController
{
    public function index(): string
    {
        /** @var Customer $customerModel */
        $customerModel = \Weline\Framework\Manager\ObjectManager::getInstance(Customer::class);

        // 获取客户统计数据
        $totalCount = $customerModel->clear()->count();
        $activeCount = $customerModel->clear()->where('is_active', 1)->count();
        $inactiveCount = $customerModel->clear()->where('is_active', 0)->count();

        // 今日新增
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $todayCount = $customerModel->clear()
            ->where(Customer::schema_fields_CREATED_AT, $todayStart, '>=')
            ->where(Customer::schema_fields_CREATED_AT, $todayEnd, '<=')
            ->count();

        // 获取客户列表
        $search = trim((string) $this->getRequest()->getGet('search', ''));
        $page = max(1, (int) $this->getRequest()->getGet('page', 1));
        $pageSize = 20;

        $query = $customerModel->clear()->select();

        if ($search !== '') {
            $search = htmlspecialchars($search, ENT_QUOTES, 'UTF-8');
            $query = $query->where(Customer::schema_fields_EMAIL, '%' . $search . '%', 'LIKE')
                ->where(Customer::schema_fields_FIRST_NAME, '%' . $search . '%', 'LIKE', 'OR')
                ->where(Customer::schema_fields_LAST_NAME, '%' . $search . '%', 'LIKE', 'OR');
        }

        $customers = $query->order('customer_id DESC')
            ->pagination($page, $pageSize)
            ->fetch();

        // 获取分页HTML
        $paginationQuery = $customerModel->clear()->select();
        if ($search !== '') {
            $paginationQuery = $paginationQuery->where(Customer::schema_fields_EMAIL, '%' . $search . '%', 'LIKE')
                ->where(Customer::schema_fields_FIRST_NAME, '%' . $search . '%', 'LIKE', 'OR')
                ->where(Customer::schema_fields_LAST_NAME, '%' . $search . '%', 'LIKE', 'OR');
        }
        $pagination = $paginationQuery->order('customer_id DESC')
            ->pagination($page, $pageSize)
            ->fetch()
            ->getPaginationHtml();

        $this->assign('title', (string) __('Customer Management'));
        $this->assign('total_count', $totalCount);
        $this->assign('active_count', $activeCount);
        $this->assign('inactive_count', $inactiveCount);
        $this->assign('today_count', $todayCount);
        $this->assign('customers', $customers);
        $this->assign('pagination', $pagination);

        return $this->fetch('customer/index');
    }
}
