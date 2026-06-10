<?php

declare(strict_types=1);

namespace Weline\Social\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Social\Service\SocialAccountService;
use Weline\Social\Service\SocialCreativeService;
use Weline\Social\Service\SocialPlatformIconService;
use Weline\Social\Service\SocialPlatformRegistry;
use Weline\Social\Service\SocialPublishService;
use Weline\Social\Service\SocialWebsiteAccountService;

class SocialManagementQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SocialPlatformRegistry $registry,
        private readonly SocialAccountService $accountService,
        private readonly SocialCreativeService $creativeService,
        private readonly SocialPublishService $publishService,
        private readonly SocialPlatformIconService $iconService,
        private readonly SocialWebsiteAccountService $websiteAccountService
    ) {
    }

    public function getProviderName(): string
    {
        return 'welineSocial';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'listPlatforms' => $this->iconService->enrichDefinitions($this->registry->listDefinitions(!empty($params['force_reload']))),
            'getPlatform' => $this->getPlatform($params),
            'listAccounts' => $this->accountService->listAccounts(),
            'getAccount' => $this->accountService->getAccountSafeArray((int)($params['account_id'] ?? 0)),
            'listWidgetAccounts' => $this->accountService->listWidgetAccounts($params),
            'startAuthorization' => $this->startAuthorization($params),
            'saveCredentialAccount' => $this->accountService->saveCredentialAccount($params),
            'testAccount' => $this->accountService->testAccount((int)($params['account_id'] ?? 0)),
            'disableAccount' => $this->accountService->disableAccount((int)($params['account_id'] ?? 0)),
            'listWebsites' => $this->websiteAccountService->listWebsites(),
            'listScopes' => $this->websiteAccountService->listScopes(),
            'listWebsiteAccountRelations' => $this->websiteAccountService->listRelations($params),
            'listScopeAccountRelations' => $this->websiteAccountService->listScopeRelations($params),
            'saveWebsiteAccountDefaults' => $this->websiteAccountService->saveWebsiteAccountDefaults($params),
            'saveScopeAccountDefaults' => $this->websiteAccountService->saveScopeAccountDefaults($params),
            'getWebsiteDefaultAccounts' => $this->websiteAccountService->getWebsiteDefaultAccounts(
                (int)($params['website_id'] ?? 0),
                !empty($params['publish_only'])
            ),
            'getScopeDefaultAccounts' => $this->websiteAccountService->getScopeDefaultAccounts($params, !empty($params['publish_only'])),
            'resolvePublishAccounts' => $this->websiteAccountService->resolvePublishAccounts($params),
            'generateCreative' => $this->creativeService->generateCreative($params),
            'createPublishBatch' => $this->publishService->createPublishBatch($params),
            'getPublishBatchStatus' => $this->publishService->getBatchStatus((int)($params['batch_id'] ?? 0)),
            default => throw new \InvalidArgumentException(
                (string)__('Weline Social 查询器不支持的操作：%{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'welineSocial',
            'name' => __('融媒体管理查询'),
            'description' => __('提供 Weline_Social 平台、账户、AI 创意和发布批次操作。'),
            'module' => 'Weline_Social',
            'operations' => [
                ['name' => 'listPlatforms', 'description' => __('列出平台注册表。'), 'frontend' => true, 'mode' => 'read', 'graph' => true, 'params' => ['force_reload' => ['type' => 'bool']]],
                ['name' => 'getPlatform', 'description' => __('获取单个平台定义。'), 'frontend' => true, 'mode' => 'read', 'graph' => true, 'params' => ['platform_code' => ['type' => 'string', 'required' => true, 'max_length' => 64]]],
                ['name' => 'listAccounts', 'description' => __('列出已连接账户。'), 'frontend' => false, 'mode' => 'read', 'params' => []],
                ['name' => 'getAccount', 'description' => __('获取社媒账户详情。'), 'frontend' => false, 'mode' => 'read', 'params' => ['account_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
                ['name' => 'listWidgetAccounts', 'description' => __('列出可展示的社媒账户。'), 'frontend' => true, 'mode' => 'read', 'graph' => true, 'params' => ['platforms' => ['type' => 'list', 'max_items' => 100], 'limit' => ['type' => 'int', 'min' => 1, 'max' => 100]]],
                ['name' => 'startAuthorization', 'description' => __('生成平台授权地址。'), 'frontend' => false, 'mode' => 'write', 'params' => ['platform_code' => ['type' => 'string', 'required' => true, 'max_length' => 64], 'redirect_uri' => ['type' => 'string', 'max_length' => 2048], 'state' => ['type' => 'string', 'max_length' => 128], 'account_context' => ['type' => 'map']]],
                ['name' => 'saveCredentialAccount', 'description' => __('保存官方凭据账户。'), 'frontend' => false, 'mode' => 'write', 'params' => ['account_id' => ['type' => 'int', 'min' => 0], 'platform_code' => ['type' => 'string', 'required' => true, 'max_length' => 64], 'account_name' => ['type' => 'string', 'required' => true, 'max_length' => 150], 'auth_mode' => ['type' => 'string', 'max_length' => 64], 'profile_url' => ['type' => 'string', 'max_length' => 512], 'widget_enabled' => ['type' => 'bool'], 'publish_enabled' => ['type' => 'bool'], 'sort_order' => ['type' => 'int'], 'credentials' => ['type' => 'map'], 'scopes' => ['type' => 'list', 'max_items' => 50], 'token_expires_at' => ['type' => 'string', 'max_length' => 32], 'remote_account_id' => ['type' => 'string', 'max_length' => 150], 'remote_account_name' => ['type' => 'string', 'max_length' => 190]]],
                ['name' => 'testAccount', 'description' => __('检测账户凭据。'), 'frontend' => false, 'mode' => 'write', 'params' => ['account_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
                ['name' => 'disableAccount', 'description' => __('禁用社媒账户。'), 'frontend' => false, 'mode' => 'write', 'params' => ['account_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
                ['name' => 'listWebsites', 'description' => __('列出可配置社媒默认账户的站点。'), 'frontend' => false, 'mode' => 'read', 'params' => []],
                ['name' => 'listScopes', 'description' => __('列出可配置社媒默认账户的两级范围。'), 'frontend' => false, 'mode' => 'read', 'params' => []],
                ['name' => 'listWebsiteAccountRelations', 'description' => __('列出站点与社媒账户关系。'), 'frontend' => false, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'min' => 1], 'include_disabled' => ['type' => 'bool']]],
                ['name' => 'listScopeAccountRelations', 'description' => __('列出范围与社媒账户关系。'), 'frontend' => false, 'mode' => 'read', 'params' => ['scope_type' => ['type' => 'string', 'max_length' => 32], 'scope_id' => ['type' => 'int', 'min' => 1], 'child_scope_type' => ['type' => 'string', 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'include_disabled' => ['type' => 'bool']]],
                ['name' => 'saveWebsiteAccountDefaults', 'description' => __('保存站点默认社媒账户。'), 'frontend' => false, 'mode' => 'write', 'params' => ['website_id' => ['type' => 'int', 'required' => true, 'min' => 1], 'account_ids' => ['type' => 'list', 'max_items' => 100], 'sort_orders' => ['type' => 'map']]],
                ['name' => 'saveScopeAccountDefaults', 'description' => __('保存范围默认社媒账户。'), 'frontend' => false, 'mode' => 'write', 'params' => ['scope_type' => ['type' => 'string', 'required' => true, 'max_length' => 32], 'scope_id' => ['type' => 'int', 'required' => true, 'min' => 1], 'child_scope_type' => ['type' => 'string', 'required' => true, 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'account_ids' => ['type' => 'list', 'max_items' => 100], 'sort_orders' => ['type' => 'map']]],
                ['name' => 'getWebsiteDefaultAccounts', 'description' => __('获取单个站点默认社媒账户。'), 'frontend' => false, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'required' => true, 'min' => 1], 'publish_only' => ['type' => 'bool']]],
                ['name' => 'getScopeDefaultAccounts', 'description' => __('获取单个范围默认社媒账户。'), 'frontend' => false, 'mode' => 'read', 'params' => ['scope_type' => ['type' => 'string', 'required' => true, 'max_length' => 32], 'scope_id' => ['type' => 'int', 'required' => true, 'min' => 1], 'child_scope_type' => ['type' => 'string', 'required' => true, 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'publish_only' => ['type' => 'bool']]],
                ['name' => 'resolvePublishAccounts', 'description' => __('按范围、站点或全部站解析默认发布账户。'), 'frontend' => false, 'mode' => 'read', 'params' => ['scope_type' => ['type' => 'string', 'max_length' => 32], 'scope_id' => ['type' => 'int', 'min' => 1], 'child_scope_type' => ['type' => 'string', 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'website_id' => ['type' => 'int', 'min' => 1], 'website_ids' => ['type' => 'list', 'max_items' => 500], 'all_sites' => ['type' => 'bool']]],
                ['name' => 'generateCreative', 'description' => __('生成融媒体创意。'), 'frontend' => false, 'mode' => 'write', 'params' => ['title' => ['type' => 'string', 'max_length' => 190], 'prompt' => ['type' => 'string', 'max_length' => 4000], 'platforms' => ['type' => 'list', 'max_items' => 100], 'fake_mode' => ['type' => 'bool'], 'use_ai' => ['type' => 'bool'], 'assets' => ['type' => 'list', 'max_items' => 50]]],
                ['name' => 'createPublishBatch', 'description' => __('创建一键多平台发布批次。'), 'frontend' => false, 'mode' => 'write', 'params' => ['draft_id' => ['type' => 'int', 'required' => true, 'min' => 1], 'account_ids' => ['type' => 'list', 'max_items' => 100], 'scope_type' => ['type' => 'string', 'max_length' => 32], 'scope_id' => ['type' => 'int', 'min' => 1], 'child_scope_type' => ['type' => 'string', 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'website_id' => ['type' => 'int', 'min' => 1], 'website_ids' => ['type' => 'list', 'max_items' => 500], 'all_sites' => ['type' => 'bool'], 'content_kind' => ['type' => 'string', 'max_length' => 32], 'title' => ['type' => 'string', 'max_length' => 190], 'scheduled_at' => ['type' => 'string', 'max_length' => 32], 'fake_mode' => ['type' => 'bool']]],
                ['name' => 'getPublishBatchStatus', 'description' => __('获取发布批次状态。'), 'frontend' => false, 'mode' => 'read', 'params' => ['batch_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getPlatform(array $params): array
    {
        $platformCode = \strtolower(\trim((string)($params['platform_code'] ?? $params['platform'] ?? '')));
        $provider = $this->registry->getProvider($platformCode);
        if ($provider === null) {
            throw new \InvalidArgumentException((string)__('未知社媒平台：%{1}', [$platformCode]));
        }

        return $this->iconService->enrichDefinition($provider->getDefinition());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function startAuthorization(array $params): array
    {
        $platformCode = \strtolower(\trim((string)($params['platform_code'] ?? $params['platform'] ?? '')));
        $provider = $this->registry->getProvider($platformCode);
        if ($provider === null) {
            throw new \InvalidArgumentException((string)__('未知社媒平台：%{1}', [$platformCode]));
        }

        $redirectUri = (string)($params['redirect_uri'] ?? '');
        $state = (string)($params['state'] ?? \bin2hex(\random_bytes(8)));
        $url = $provider->buildAuthorizationUrl(
            \is_array($params['account_context'] ?? null) ? $params['account_context'] : [],
            $redirectUri,
            $state
        );

        return [
            'success' => $url !== null,
            'authorization_url' => $url,
            'state' => $state,
            'message' => $url !== null
                ? (string)__('授权地址已生成。')
                : (string)__('该平台当前不支持自动授权地址生成。'),
        ];
    }
}
