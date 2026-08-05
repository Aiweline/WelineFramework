<?php

declare(strict_types=1);

namespace Weline\Inventory\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Inventory\Service\InventoryAdminMutationService;
use Weline\Inventory\Service\InventoryAdminViewService;

final class Inventory extends BackendController
{
    private const TITLES = [
        'stocks' => '库存',
        'adjustments' => '库存调整',
        'warehouses' => '仓库',
        'authorizations' => '仓库授权',
        'reservations' => '库存预占',
        'leases' => '预占租约',
        'ledger' => '库存账本',
        'migration' => '库存迁移',
    ];

    public function __construct(
        private readonly InventoryAdminViewService $adminView,
        private readonly InventoryAdminMutationService $mutations,
    ) {
    }

    #[Acl('Weline_Inventory::commerce:inventory:stocks', '库存', 'mdi-warehouse', '查看库存投影')]
    public function stocks(): string { return $this->renderSection('stocks'); }

    #[Acl('Weline_Inventory::commerce:inventory:adjustments', '库存调整', 'mdi-tune-variant', '查看库存调整事件')]
    public function adjustments(): string { return $this->renderSection('adjustments'); }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '仓库', 'mdi-home-city-outline', '查看仓库')]
    public function warehouses(): string { return $this->renderSection('warehouses'); }

    #[Acl('Weline_Inventory::commerce:inventory:authorizations', '仓库授权', 'mdi-shield-account-outline', '查看仓库授权')]
    public function authorizations(): string { return $this->renderSection('authorizations'); }

    #[Acl('Weline_Inventory::commerce:inventory:reservations', '库存预占', 'mdi-lock-clock', '查看库存预占')]
    public function reservations(): string { return $this->renderSection('reservations'); }

    #[Acl('Weline_Inventory::commerce:inventory:leases', '预占租约', 'mdi-timer-lock-outline', '查看预占租约')]
    public function leases(): string { return $this->renderSection('leases'); }

    #[Acl('Weline_Inventory::commerce:inventory:ledger', '库存账本', 'mdi-book-open-variant', '查看库存不可变账本')]
    public function ledger(): string { return $this->renderSection('ledger'); }

    #[Acl('Weline_Inventory::commerce:inventory:migration', '库存迁移', 'mdi-database-sync-outline', '查看库存迁移状态')]
    public function migration(): string { return $this->renderSection('migration'); }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '创建仓库', 'mdi-home-city-outline', '创建仓库')]
    public function postCreateWarehouse(): string
    {
        return $this->handleMutation('warehouses', function (int $websiteId): void {
            $this->mutations->createWarehouse(
                $websiteId,
                $this->postString('warehouse_code', 64),
                $this->postString('name', 128),
                $this->postString('mode', 16),
                $this->postString('warehouse_type', 16),
            );
        }, '仓库已创建');
    }

    #[Acl('Weline_Inventory::commerce:inventory:authorizations', '创建仓库授权', 'mdi-shield-account-outline', '创建仓库授权')]
    public function postAuthorizeWarehouse(): string
    {
        return $this->handleMutation('authorizations', function (int $websiteId): void {
            $this->mutations->authorizeWarehouse(
                $websiteId,
                $this->postPositiveInt('store_id'),
                $this->postPositiveInt('warehouse_id'),
                (string)$this->request->getPost('is_default', '0') === '1',
            );
        }, '仓库授权已创建');
    }

    #[Acl('Weline_Inventory::commerce:inventory:adjustments', '提交库存调整', 'mdi-tune-variant', '设置商品可用库存')]
    public function postAdjustStock(): string
    {
        return $this->handleMutation('adjustments', function (int $websiteId): void {
            $this->mutations->setOnHand(
                $websiteId,
                $this->postPositiveInt('store_id'),
                $this->postPositiveInt('offer_id'),
                $this->postNonNegativeInt('on_hand_minor', 0),
                $this->postString('command_id', 96),
                $this->postString('strategy', 16),
            );
        }, '库存调整已提交');
    }

    private function renderSection(string $section): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $rows = [];
        $columns = [];
        $error = '';
        try {
            $result = $this->adminView->load($section, $websiteId);
            $rows = $result['rows'];
            $columns = $result['columns'];
        } catch (\Throwable) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('数据读取失败，请检查库存模块状态与数据库连接');
        }
        $this->assign('title', __(self::TITLES[$section]));
        $this->assign('section', $section);
        $this->assign('website_id', $websiteId);
        $this->assign('rows', $rows);
        $this->assign('columns', $columns);
        $this->assign('error', $error);
        return (string)$this->fetch('index');
    }

    private function handleMutation(string $section, callable $mutation, string $success): string
    {
        $websiteId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $mutation($websiteId);
            $this->getMessageManager()->addSuccess(__($success));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('操作失败：%{1}', [$exception->getMessage()]));
        }

        return (string)$this->redirect('*/backend/inventory/' . $section . '?website_id=' . $websiteId);
    }

    private function postString(string $key, int $maxLength): string
    {
        $value = trim((string)$this->request->getPost($key, ''));
        if ($value === '' || strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(__('%{1} 不能为空且最多 %{2} 字符', [$key, $maxLength]));
        }
        return $value;
    }

    private function postPositiveInt(string $key): int
    {
        $value = $this->postNonNegativeInt($key, 0);
        if ($value <= 0) {
            throw new \InvalidArgumentException(__('%{1} 必须是正整数', [$key]));
        }
        return $value;
    }

    private function postNonNegativeInt(string $key, int $default): int
    {
        $raw = trim((string)$this->request->getPost($key, (string)$default));
        if ($raw === '' || !ctype_digit($raw)) {
            throw new \InvalidArgumentException(__('%{1} 必须是非负整数', [$key]));
        }
        return (int)$raw;
    }
}
