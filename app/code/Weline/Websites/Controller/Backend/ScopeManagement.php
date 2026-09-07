<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\StoreChannelAdminService;
use Weline\Websites\Service\WebsiteSelectOptions;

final class ScopeManagement extends BackendController
{
    public function __construct(private readonly StoreChannelAdminService $admin)
    {
    }

    #[Acl('Weline_Websites::store_management', '商店管理', 'store', '管理 Store', 'Weline_Websites::website_service')]
    public function stores(): string
    {
        return $this->renderSection('stores');
    }

    #[Acl('Weline_Websites::sales_channel_management', '渠道管理', 'branch', '管理 Sales Channel', 'Weline_Websites::website_service')]
    public function channels(): string
    {
        return $this->renderSection('channels');
    }

    #[Acl('Weline_Websites::store_management', '编辑商店', 'edit', '编辑 Store')]
    public function editStore(): string
    {
        $storeId = max(0, (int)$this->request->getGet('store_id', 0));
        $row = null;
        $error = '';
        try {
            $row = $this->admin->getStore($storeId);
            if ($row === null) {
                throw new \InvalidArgumentException(__('商店不存在'));
            }
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setCode(404);
            $error = (string)$exception->getMessage();
        }
        $this->assign('section', 'stores');
        $this->assign('entity', $row ?? []);
        $this->assign('error', $error);
        $this->assign('website_id', (int)($row['website_id'] ?? 0));
        return (string)$this->fetch('edit-store');
    }

    #[Acl('Weline_Websites::sales_channel_management', '编辑渠道', 'edit', '编辑 Sales Channel')]
    public function editChannel(): string
    {
        $channelId = max(0, (int)$this->request->getGet('channel_id', 0));
        $row = null;
        $error = '';
        try {
            $row = $this->admin->getChannel($channelId);
            if ($row === null) {
                throw new \InvalidArgumentException(__('渠道不存在'));
            }
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setCode(404);
            $error = (string)$exception->getMessage();
        }
        $this->assign('section', 'channels');
        $this->assign('entity', $row ?? []);
        $this->assign('error', $error);
        $this->assign('website_id', (int)($row['website_id'] ?? 0));
        return (string)$this->fetch('edit-channel');
    }

    #[Acl('Weline_Websites::store_management', '创建商店', 'plus', '创建 Store')]
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

    #[Acl('Weline_Websites::sales_channel_management', '创建渠道', 'plus', '创建 Sales Channel')]
    public function postCreateChannel(): string
    {
        $websiteId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $this->admin->createChannel(
                $websiteId,
                $this->postNonNegativeInt('store_id', 0),
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

    #[Acl('Weline_Websites::store_management', '保存商店', 'save', '保存 Store')]
    public function postUpdateStore(): string
    {
        $storeId = 0;
        $websiteId = 0;
        try {
            $storeId = $this->postNonNegativeInt('store_id', 0);
            $before = $this->admin->getStore($storeId);
            if ($before === null) {
                throw new \InvalidArgumentException(__('商店不存在'));
            }
            $websiteId = (int)$before['website_id'];
            $postData = $this->request->getPost();
            if (!is_array($postData)) {
                $postData = [];
            }
            $updated = $this->admin->updateStore(
                $storeId,
                $this->postString('name', 128),
                $this->postString('store_mode', 16),
                trim((string)$this->request->getPost('url', '')) ?: null,
            );
            $this->dispatchScopeSaveAfter('store', [
                'store_id' => $storeId,
                'website_id' => $websiteId,
                'store' => $updated->toArray(),
                'before' => $before,
                'post_data' => $postData,
                'action' => 'edit',
            ]);
            $this->getMessageManager()->addSuccess(__('商店已保存'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('保存商店失败：%{1}', [$exception->getMessage()]));
        }
        if ($storeId > 0) {
            return (string)$this->redirect(
                'websites/backend/scope-management/edit-store',
                ['store_id' => $storeId],
            );
        }
        return (string)$this->redirect(
            'websites/backend/scope-management/stores',
            ['website_id' => $websiteId],
        );
    }

    #[Acl('Weline_Websites::sales_channel_management', '保存渠道', 'save', '保存 Sales Channel')]
    public function postUpdateChannel(): string
    {
        $channelId = 0;
        $websiteId = 0;
        try {
            $channelId = $this->postNonNegativeInt('channel_id', 0);
            $before = $this->admin->getChannel($channelId);
            if ($before === null) {
                throw new \InvalidArgumentException(__('渠道不存在'));
            }
            $websiteId = (int)$before['website_id'];
            $postData = $this->request->getPost();
            if (!is_array($postData)) {
                $postData = [];
            }
            $updated = $this->admin->updateChannel(
                $channelId,
                $this->postString('name', 128),
            );
            $this->dispatchScopeSaveAfter('channel', [
                'channel_id' => $channelId,
                'store_id' => (int)$updated->storeId,
                'website_id' => $websiteId,
                'channel' => $updated->toArray(),
                'before' => $before,
                'post_data' => $postData,
                'action' => 'edit',
            ]);
            $this->getMessageManager()->addSuccess(__('渠道已保存'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('保存渠道失败：%{1}', [$exception->getMessage()]));
        }
        if ($channelId > 0) {
            return (string)$this->redirect(
                'websites/backend/scope-management/edit-channel',
                ['channel_id' => $channelId],
            );
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
        $pack = WebsiteSelectOptions::forSelect((string)$websiteId);
        $this->assign('websiteSelectValue', (string)$websiteId);
        $this->assign('websiteSelectDisplay', $pack['display']);
        $this->assign('websiteSelectOptionsJson', $pack['options_json']);
        return (string)$this->fetch('index');
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function dispatchScopeSaveAfter(string $scope, array $eventData): void
    {
        $eventName = $scope === 'channel'
            ? 'Weline_Websites::channel_save_after'
            : 'Weline_Websites::store_save_after';
        ObjectManager::getInstance(EventsManager::class)->dispatch($eventName, $eventData);
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
