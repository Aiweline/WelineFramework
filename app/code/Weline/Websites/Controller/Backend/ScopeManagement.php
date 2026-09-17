<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\StoreChannelAdminService;
use Weline\Websites\Service\WebsiteScopeTreeService;

final class ScopeManagement extends BackendController
{
    public function __construct(private readonly StoreChannelAdminService $admin)
    {
    }

    #[Acl('Weline_Websites::store_management', '商店管理', 'store', '管理 Store', 'Weline_Websites::website_service')]
    public function stores(): string
    {
        return (string)$this->redirect('websites/admin/website', ['focus' => 'stores']);
    }

    #[Acl('Weline_Websites::sales_channel_management', '渠道管理', 'branch', '管理 Sales Channel', 'Weline_Websites::website_service')]
    public function channels(): string
    {
        return (string)$this->redirect('websites/admin/website', ['focus' => 'channels']);
    }

    #[Acl('Weline_Websites::store_management', '编辑商店', 'edit', '编辑 Store')]
    public function editStore(): string
    {
        $storeId = max(0, (int)$this->request->getGet('store_id', 0));
        return (string)$this->redirect(
            'websites/admin/website',
            ['node' => WebsiteScopeTreeService::formatNode('store', $storeId)],
        );
    }

    #[Acl('Weline_Websites::sales_channel_management', '编辑渠道', 'edit', '编辑 Sales Channel')]
    public function editChannel(): string
    {
        $channelId = max(0, (int)$this->request->getGet('channel_id', 0));
        return (string)$this->redirect(
            'websites/admin/website',
            ['node' => WebsiteScopeTreeService::formatNode('channel', $channelId)],
        );
    }

    #[Acl('Weline_Websites::store_management', '创建商店', 'plus', '创建 Store')]
    public function postCreateStore(): string
    {
        $websiteId = 0;
        $newStoreId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $created = $this->admin->createStore(
                $websiteId,
                $this->postString('code', 64),
                $this->postString('name', 128),
                $this->postString('store_mode', 16),
                trim((string)$this->request->getPost('url', '')) ?: null,
            );
            $newStoreId = (int)$created->id;
            $this->getMessageManager()->addSuccess(__('商店已创建'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('创建商店失败：%{1}', [$exception->getMessage()]));
        }
        return $this->redirectAfterMutation(
            $newStoreId > 0
                ? WebsiteScopeTreeService::formatNode('store', $newStoreId)
                : WebsiteScopeTreeService::formatNode('website', $websiteId),
            'websites/backend/scope-management/stores',
            ['website_id' => $websiteId],
        );
    }

    #[Acl('Weline_Websites::sales_channel_management', '创建渠道', 'plus', '创建 Sales Channel')]
    public function postCreateChannel(): string
    {
        $websiteId = 0;
        $storeId = 0;
        $newChannelId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $storeId = $this->postNonNegativeInt('store_id', 0);
            $created = $this->admin->createChannel(
                $websiteId,
                $storeId,
                $this->postString('code', 64),
                $this->postString('name', 128),
            );
            $newChannelId = (int)$created->id;
            $this->getMessageManager()->addSuccess(__('渠道已创建'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('创建渠道失败：%{1}', [$exception->getMessage()]));
        }
        return $this->redirectAfterMutation(
            $newChannelId > 0
                ? WebsiteScopeTreeService::formatNode('channel', $newChannelId)
                : WebsiteScopeTreeService::formatNode('store', $storeId),
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
        return $this->redirectAfterMutation(
            $storeId > 0
                ? WebsiteScopeTreeService::formatNode('store', $storeId)
                : '',
            $storeId > 0
                ? 'websites/backend/scope-management/edit-store'
                : 'websites/backend/scope-management/stores',
            $storeId > 0
                ? ['store_id' => $storeId]
                : ['website_id' => $websiteId],
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
        return $this->redirectAfterMutation(
            $channelId > 0
                ? WebsiteScopeTreeService::formatNode('channel', $channelId)
                : '',
            $channelId > 0
                ? 'websites/backend/scope-management/edit-channel'
                : 'websites/backend/scope-management/channels',
            $channelId > 0
                ? ['channel_id' => $channelId]
                : ['website_id' => $websiteId],
        );
    }

    /**
     * @param array<string, scalar> $legacyParams
     */
    private function redirectAfterMutation(string $treeNode, string $legacyPath, array $legacyParams): string
    {
        $returnTo = trim((string)$this->request->getPost('return_to', ''));
        if ($returnTo === 'tree' || $returnTo === '') {
            // 默认回树：列表入口已重定向，写路径也统一回树深链。
            $node = trim((string)$this->request->getPost('return_node', ''));
            if ($node === '' || WebsiteScopeTreeService::parseNode($node) === null) {
                $node = $treeNode;
            }
            $postedReturnUrl = trim((string)$this->request->getPost('return_url', ''));
            if ($postedReturnUrl !== '' && $this->isSafeWebsitesTreeReturnUrl($postedReturnUrl)) {
                return (string)$this->redirect($this->withTreeNodeQuery($postedReturnUrl, $node));
            }
            $params = [];
            if ($node !== '') {
                $params['node'] = $node;
            }
            // 不可依赖空的 `*` 展开（偶发缺 websites frontName → 404）。
            return (string)$this->redirect('websites/admin/website', $params);
        }
        return (string)$this->redirect($legacyPath, $legacyParams);
    }

    private function withTreeNodeQuery(string $url, string $node): string
    {
        if ($node === '') {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        $query['node'] = $node;
        $rebuild = '';
        if (!empty($parts['scheme'])) {
            $rebuild .= $parts['scheme'] . '://';
        }
        if (!empty($parts['host'])) {
            $rebuild .= $parts['host'];
            if (isset($parts['port'])) {
                $rebuild .= ':' . (int)$parts['port'];
            }
        }
        $rebuild .= (string)($parts['path'] ?? '');
        $rebuild .= '?' . http_build_query($query);

        return $rebuild;
    }

    private function isSafeWebsitesTreeReturnUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);

            return (bool)preg_match('#/websites/admin/website(?:/index)?$#', rtrim($path, '/'));
        }

        if (!preg_match('#^https?://#i', $url)) {
            return (bool)preg_match('#^websites/admin/website(?:/index)?(?:\?|$)#', $url);
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        try {
            $reqHost = (string)(parse_url(
                (string)$this->request->getUrlBuilder()->getCurrentUrl([], false),
                PHP_URL_HOST
            ) ?: '');
        } catch (\Throwable) {
            $reqHost = '';
        }
        if ($reqHost === '' || strcasecmp((string)$parts['host'], $reqHost) !== 0) {
            return false;
        }

        $path = rtrim((string)($parts['path'] ?? ''), '/');

        return (bool)preg_match('#/websites/admin/website(?:/index)?$#', $path);
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

    private function postNonNegativeInt(string $key, int $default): int
    {
        $raw = trim((string)$this->request->getPost($key, (string)$default));
        if ($raw === '' || !ctype_digit($raw)) {
            throw new \InvalidArgumentException(__('%{1} 必须是非负整数', [$key]));
        }
        return (int)$raw;
    }
}
