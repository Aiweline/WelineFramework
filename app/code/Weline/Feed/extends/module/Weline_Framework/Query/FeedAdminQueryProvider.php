<?php

declare(strict_types=1);

namespace Weline\Feed\Extends\Module\Weline_Framework\Query;

use Weline\Feed\Service\FeedAdminCatalog;
use Weline\Feed\Service\FeedOperatorConnectionStatus;
use Weline\Feed\Service\FeedScopeMailboxService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class FeedAdminQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'feed_admin';
    }

    /**
     * @param array<string, string> $secrets
     */
    public function connectionStatus(string $channelCode, array $secrets): string
    {
        return FeedOperatorConnectionStatus::resolve($channelCode, $secrets);
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            return match ($operation) {
                'listFeeds' => $this->catalog()->listFeeds($params),
                'saveFeed' => $this->catalog()->saveFeed($params),
                'setFeedEnabled' => $this->catalog()->setEnabled($params),
                'archiveFeed' => $this->catalog()->archiveFeed($params),
                'generateFeed' => $this->catalog()->generateFeed($params),
                'listCategoryMaps' => $this->catalog()->listCategoryMaps($params),
                'saveCategoryMap' => $this->catalog()->saveCategoryMap($params),
                'deleteCategoryMap' => $this->catalog()->deleteCategoryMap($params),
                'getScopeFeedMailbox' => $this->mailbox()->getScopeFeedMailbox($params),
                'ensureScopeFeedMailbox' => $this->mailbox()->ensureScopeFeedMailbox($params),
                'resetScopeFeedMailboxPassword' => $this->mailbox()->resetScopeFeedMailboxPassword($params),
                default => throw new \InvalidArgumentException('feed_operation_unknown'),
            };
        } catch (\Throwable $e) {
            if ($e instanceof \LogicException && $e->getMessage() === 'feed_missing_secret_marked_authorized') {
                throw $e;
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getMessage(),
                'connection_status' => $this->failureStatus($params),
            ];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'feed_admin',
            'name' => '购物目录',
            'description' => '购物目录列表、保存、启停、归档或删除、生成、分类映射',
            'module' => 'Weline_Feed',
            'operations' => [
                $this->op('listFeeds', '列出购物目录', 'read', [
                    ['name' => 'q', 'type' => 'string', 'required' => false, 'description' => '名称'],
                    ['name' => 'channel_code', 'type' => 'string', 'required' => false, 'description' => '渠道码'],
                    ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '网站'],
                    ['name' => 'archived', 'type' => 'int', 'required' => false, 'description' => '0 在用，1 归档'],
                    ['name' => 'page', 'type' => 'int', 'required' => false, 'description' => '页码'],
                    ['name' => 'page_size', 'type' => 'int', 'required' => false, 'description' => '每页条数'],
                ]),
                $this->op('saveFeed', '保存购物目录', 'write', [
                    ['name' => 'definition_id', 'type' => 'int', 'required' => false, 'description' => '空则新增'],
                    ['name' => 'title', 'type' => 'string', 'required' => true, 'description' => '名称'],
                    ['name' => 'channel_code', 'type' => 'string', 'required' => true, 'description' => '新增必填，编辑不可改'],
                    ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '编辑一条时的网站'],
                    ['name' => 'website_ids', 'type' => 'string', 'required' => false, 'description' => '新增时的站点，逗号分隔'],
                    ['name' => 'locale', 'type' => 'string', 'required' => false, 'description' => '编辑一条时的语言'],
                    ['name' => 'country', 'type' => 'string', 'required' => false, 'description' => '国家'],
                    ['name' => 'currency', 'type' => 'string', 'required' => false, 'description' => '编辑一条时的币种'],
                    ['name' => 'enabled', 'type' => 'int', 'required' => false, 'description' => '1 启用'],
                    ['name' => 'connection_mode', 'type' => 'string', 'required' => false, 'description' => '默认 feed_url'],
                    ['name' => 'client_id', 'type' => 'string', 'required' => false, 'description' => '只写，空则保留'],
                    ['name' => 'client_secret', 'type' => 'string', 'required' => false, 'description' => '只写，空则保留'],
                    ['name' => 'developer_token', 'type' => 'string', 'required' => false, 'description' => 'Microsoft，只写'],
                    ['name' => 'api_key', 'type' => 'string', 'required' => false, 'description' => '只写，空则保留'],
                    ['name' => 'shipping', 'type' => 'string', 'required' => false, 'description' => '账号级运费'],
                ]),
                $this->op('setFeedEnabled', '启用或停用', 'write', [
                    ['name' => 'definition_id', 'type' => 'int', 'required' => true, 'description' => '目录'],
                    ['name' => 'enabled', 'type' => 'int', 'required' => true, 'description' => '1 启用，0 停用'],
                ]),
                $this->op('archiveFeed', '归档或删除', 'write', [
                    ['name' => 'definition_id', 'type' => 'int', 'required' => true, 'description' => '目录'],
                ]),
                $this->op('generateFeed', '生成目录文件', 'write', [
                    ['name' => 'definition_id', 'type' => 'int', 'required' => true, 'description' => '目录'],
                ]),
                $this->op('listCategoryMaps', '读取站点分类映射', 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'description' => '网站'],
                    ['name' => 'vocabulary', 'type' => 'string', 'required' => false, 'description' => 'google、yandex 或 naver'],
                    ['name' => 'locale', 'type' => 'string', 'required' => false, 'description' => '分类名语言'],
                ]),
                $this->op('saveCategoryMap', '保存站点分类映射', 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'description' => '网站'],
                    ['name' => 'store_category_id', 'type' => 'int', 'required' => true, 'description' => '店内分类'],
                    ['name' => 'vocabulary', 'type' => 'string', 'required' => true, 'description' => 'google、yandex 或 naver'],
                    ['name' => 'map_value', 'type' => 'string', 'required' => true, 'description' => '渠道分类值'],
                    ['name' => 'map_label', 'type' => 'string', 'required' => false, 'description' => 'Yandex 分类名'],
                    ['name' => 'locale', 'type' => 'string', 'required' => false, 'description' => '用来核对店内分类名'],
                ]),
                $this->op('deleteCategoryMap', '删除一条分类映射', 'write', [
                    ['name' => 'map_id', 'type' => 'int', 'required' => true, 'description' => '映射'],
                ]),
                $this->op('getScopeFeedMailbox', '读取当前站点的 FE 专用邮箱状态', 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '网站'],
                ]),
                $this->op('ensureScopeFeedMailbox', '一键开通当前站点的 FE 专用邮箱', 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '网站'],
                ]),
                $this->op('resetScopeFeedMailboxPassword', '重置当前站点 FE 专用邮箱密码并返回一次明文', 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '网站'],
                    ['name' => 'password', 'type' => 'string', 'required' => false, 'description' => '留空自动生成'],
                ]),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $params
     * @return array<string, mixed>
     */
    private function op(string $name, string $description, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'frontend' => true,
            'auth' => 'backend',
            'backend' => true,
            'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Feed::catalog_list'],
            'mode' => $mode,
            'params' => $params,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function failureStatus(array $params): string
    {
        $channel = trim((string)($params['channel_code'] ?? ''));
        if ($channel === '') {
            return FeedOperatorConnectionStatus::DISCONNECTED;
        }
        $secrets = [];
        foreach (['client_id', 'client_secret', 'developer_token', 'api_key'] as $field) {
            if (array_key_exists($field, $params)) {
                $secrets[$field] = trim((string)$params[$field]);
            }
        }

        return $this->connectionStatus($channel, $secrets);
    }

    private function catalog(): FeedAdminCatalog
    {
        $catalog = ObjectManager::getInstance(FeedAdminCatalog::class);
        if (!$catalog instanceof FeedAdminCatalog) {
            throw new \RuntimeException('feed_admin_catalog_missing');
        }

        return $catalog;
    }

    private function mailbox(): FeedScopeMailboxService
    {
        return ObjectManager::getInstance(FeedScopeMailboxService::class);
    }
}
