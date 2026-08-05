<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Websites\Service\StoreChannelAdminService;

final class ScopeManagement extends BackendController
{
    public function __construct(private readonly StoreChannelAdminService $admin)
    {
    }

    #[Acl('Weline_Websites::store_management', '商店管理', 'mdi-storefront-outline', '管理 Store', 'Weline_Websites::website_service')]
    public function stores(): string
    {
        return $this->renderSection('stores');
    }

    #[Acl('Weline_Websites::sales_channel_management', '渠道管理', 'mdi-source-branch', '管理 Sales Channel', 'Weline_Websites::website_service')]
    public function channels(): string
    {
        return $this->renderSection('channels');
    }

    #[Acl('Weline_Websites::store_management', '创建商店', 'mdi-store-plus-outline', '创建 Store')]
    public function postCreateStore(): string
    {
        $websiteId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $this->admin->createStore(
                $websiteId,
                $this->postString('code', 64),
                $this->postString('name', 128),
                $this->postString('store_mode', 16),
                trim((string)$this->request->getPost('url', '')) ?: null,
            );
            $this->getMessageManager()->addSuccess(__('商店已创建'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('创建商店失败：%{1}', [$exception->getMessage()]));
        }
        return (string)$this->redirect(
            'websites/backend/scope-management/stores',
            ['website_id' => $websiteId],
        );
    }

    #[Acl('Weline_Websites::sales_channel_management', '创建渠道', 'mdi-source-branch-plus', '创建 Sales Channel')]
    public function postCreateChannel(): string
    {
        $websiteId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $this->admin->createChannel(
                $websiteId,
                $this->postPositiveInt('store_id'),
                $this->postString('code', 64),
                $this->postString('name', 128),
            );
            $this->getMessageManager()->addSuccess(__('渠道已创建'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('创建渠道失败：%{1}', [$exception->getMessage()]));
        }
        return (string)$this->redirect(
            'websites/backend/scope-management/channels',
            ['website_id' => $websiteId],
        );
    }

    private function renderSection(string $section): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $rows = [];
        $stores = [];
        $error = '';
        try {
            $stores = $this->admin->listStores($websiteId);
            $rows = $section === 'stores' ? $stores : $this->admin->listChannels($websiteId);
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('Scope 数据读取失败：%{1}', [$exception->getMessage()]);
        }
        $this->assign('section', $section);
        $this->assign('website_id', $websiteId);
        $this->assign('rows', $rows);
        $this->assign('stores', $stores);
        $this->assign('error', $error);
        return (string)$this->fetch('index');
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
