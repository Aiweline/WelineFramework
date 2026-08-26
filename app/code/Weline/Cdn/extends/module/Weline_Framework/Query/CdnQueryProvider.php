<?php
declare(strict_types=1);

namespace Weline\Cdn\Extends\Module\Weline_Framework\Query;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\CdnAdminQueryService;
use Weline\Cdn\Service\MediaUrlCowResolver;
use Weline\Cdn\Service\ScopedAccountBindingService;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CdnQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly AdapterResolver $adapterResolver,
        private readonly AccountManager $accountManager,
        private readonly Account $accountModel,
        private readonly Domain $domainModel,
        private readonly CdnAdminQueryService $adminQueryService,
        private readonly ScopedAccountBindingService $scopedAccountBindingService,
        private readonly MediaUrlCowResolver $mediaUrlCowResolver,
    ) {
    }

    public function getProviderName(): string
    {
        return 'cdn';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getAdapters'        => $this->getAdapters(),
            'getAccounts'        => $this->getAccounts($params),
            'getAccount'         => $this->getAccount($params),
            'saveAccount'        => $this->saveAccount($params),
            'deleteAccount'      => $this->deleteAccount($params),
            'setDefaultAccount'  => $this->setDefaultAccount($params),
            'getDefaultAccount'  => $this->getDefaultAccount($params),
            'getDomains'         => $this->getDomains($params),
            'deleteDomain'       => $this->deleteDomain($params),
            'toggleDomainEnable' => $this->adminQueryService->toggleDomainEnable($params),
            'clearDomainCache'   => $this->adminQueryService->clearDomainCache($params),
            'saveDomain'         => $this->adminQueryService->saveDomain($params),
            'executeWarmup'      => $this->adminQueryService->executeWarmup($params),
            'toggleWarmupEnable' => $this->adminQueryService->toggleWarmupEnable($params),
            'deleteWarmupUrl'    => $this->adminQueryService->deleteWarmupUrl($params),
            'deleteAttackLog'    => $this->adminQueryService->deleteAttackLog($params),
            'batchDeleteAttackLogs' => $this->adminQueryService->batchDeleteAttackLogs($params),
            'cleanupAttackLogs'  => $this->adminQueryService->cleanupAttackLogs($params),
            'collectApiRules'    => $this->adminQueryService->collectApiRules($params),
            'toggleApiRule'      => $this->adminQueryService->toggleApiRule($params),
            'deleteApiRule'      => $this->adminQueryService->deleteApiRule($params),
            'getGlobalRules'     => $this->adminQueryService->getGlobalRules($params),
            'getDomainRules'     => $this->adminQueryService->getDomainRules($params),
            'saveGlobalRules'    => $this->adminQueryService->saveGlobalRules($params),
            'saveDomainRules'    => $this->adminQueryService->saveDomainRules($params),
            'importDomainRules'  => $this->adminQueryService->importDomainRules($params),
            'pushDomainRules'    => $this->adminQueryService->pushDomainRules($params),
            'listEnabledDomains' => $this->adminQueryService->listEnabledDomains($params),
            'ensureZone'         => $this->ensureZone($params),
            'testConnection'     => $this->testConnection($params),
            'getAdapterInfo'     => $this->getAdapterInfo($params),
            'bindAccountToScope' => $this->bindAccountToScope($params),
            'resolveBinding'     => $this->resolveBinding($params),
            'restoreScopeInheritance' => $this->restoreScopeInheritance($params),
            'resolveCowMediaUrl' => $this->resolveCowMediaUrl($params),
            'resolveAuthorizedAccount' => $this->resolveAuthorizedAccountOp($params),
            'reconcileMailDns' => $this->reconcileMailDnsOp($params),
            default => throw new \InvalidArgumentException(
                (string)__('CDN 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider'    => 'cdn',
            'name'        => __('CDN 查询'),
            'description' => __('提供 CDN 适配器、账户管理、域名绑定等能力'),
            'module'      => 'Weline_Cdn',
            'operations'  => [
                [
                    'name'        => 'getAdapters',
                    'description' => __('获取所有可用的 CDN 适配器'),
                    'params'      => [],
                ],
                [
                    'name'        => 'getAccounts',
                    'description' => __('获取 CDN 账户列表'),
                    'params'      => [
                        ['name' => 'adapter', 'type' => 'string|null', 'required' => false, 'description' => __('按适配器过滤')],
                        ['name' => 'status', 'type' => 'string|null', 'required' => false, 'description' => __('按状态过滤')],
                    ],
                ],
                [
                    'name'        => 'getAccount',
                    'description' => __('获取单个 CDN 账户详情'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'saveAccount',
                    'description' => __('创建或更新 CDN 账户'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_account_save'],
                    'mode'        => 'write',
                    'graph'       => false,
                    'params'      => [
                        'account_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'description' => __('账户 ID（更新时必填）')],
                        'adapter' => ['type' => 'string', 'required' => true, 'max_length' => 50, 'description' => __('适配器代码')],
                        'name' => ['type' => 'string', 'required' => true, 'max_length' => 128, 'description' => __('账户名称')],
                        'description' => ['type' => 'string', 'required' => false, 'max_length' => 65535, 'description' => __('账户描述')],
                        'credentials' => ['type' => 'array', 'required' => false, 'max_items' => 32, 'description' => __('凭证信息')],
                        'is_default' => ['type' => 'bool', 'required' => false, 'description' => __('是否设为默认')],
                        'status' => ['type' => 'string', 'required' => false, 'max_length' => 20, 'description' => __('状态')],
                    ],
                    'returns'     => ['type' => 'array'],
                ],
                [
                    'name'        => 'deleteAccount',
                    'description' => __('删除 CDN 账户'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'setDefaultAccount',
                    'description' => __('设置默认账户'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'getDefaultAccount',
                    'description' => __('获取指定适配器的默认账户'),
                    'params'      => [
                        ['name' => 'adapter', 'type' => 'string', 'required' => true, 'description' => __('适配器代码')],
                    ],
                ],
                [
                    'name'        => 'getDomains',
                    'description' => __('获取账户关联的域名列表'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'saveDomain',
                    'description' => __('创建或更新 CDN 域名绑定'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_domain_save'],
                    'mode'        => 'write',
                    'graph'       => false,
                    'params'      => [
                        'domain_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'description' => __('域名 ID（更新时必填）')],
                        'site_id' => ['type' => 'int', 'required' => true, 'min' => 0, 'description' => __('网站 ID')],
                        'adapter' => ['type' => 'string', 'required' => true, 'max_length' => 50, 'description' => __('适配器代码')],
                        'domain_name' => ['type' => 'string', 'required' => true, 'max_length' => 255, 'description' => __('域名名称')],
                        'zone_id' => ['type' => 'string', 'required' => true, 'max_length' => 128, 'description' => __('CDN Zone ID')],
                        'account_id' => ['type' => 'int', 'required' => false, 'min' => 1, 'description' => __('关联账户 ID')],
                        'inherit_default' => ['type' => 'bool', 'required' => false, 'description' => __('是否继承默认账户')],
                        'warmup_interval_seconds' => ['type' => 'int', 'required' => false, 'min' => 60, 'description' => __('预热间隔秒数')],
                        'enabled' => ['type' => 'bool', 'required' => false, 'description' => __('是否启用')],
                    ],
                    'returns'     => ['type' => 'array'],
                ],
                [
                    'name'        => 'deleteDomain',
                    'description' => __('删除 CDN 域名绑定'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_domain_delete'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => true, 'description' => __('域名 ID')],
                    ],
                ],
                [
                    'name'        => 'executeWarmup',
                    'description' => __('执行 CDN 预热任务'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_warmup_execute'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 1000],
                    ],
                    'returns'     => ['type' => 'array'],
                ],
                [
                    'name'        => 'toggleWarmupEnable',
                    'description' => __('启用或禁用 CDN 预热 URL'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_warmup_toggle_enable'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'enabled', 'type' => 'int', 'required' => true, 'min' => 0, 'max' => 1],
                    ],
                    'returns'     => ['type' => 'array'],
                ],
                [
                    'name'        => 'deleteWarmupUrl',
                    'description' => __('删除 CDN 预热 URL'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_warmup_delete'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'id', 'type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns'     => ['type' => 'array'],
                ],
                [
                    'name'        => 'collectApiRules',
                    'description' => __('重新收集 CDN API 规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_api_rules_collect'],
                    'mode'        => 'write',
                    'params'      => [],
                ],
                [
                    'name'        => 'toggleApiRule',
                    'description' => __('启用或禁用 CDN API 规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_api_rules_toggle'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'id', 'type' => 'int', 'required' => true, 'min' => 1, 'description' => __('规则 ID')],
                        ['name' => 'enabled', 'type' => 'int', 'required' => true, 'min' => 0, 'max' => 1, 'description' => __('启用状态')],
                    ],
                ],
                [
                    'name'        => 'deleteApiRule',
                    'description' => __('删除 CDN API 规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_api_rules_delete'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'id', 'type' => 'int', 'required' => true, 'min' => 1, 'description' => __('规则 ID')],
                    ],
                ],
                [
                    'name'        => 'getGlobalRules',
                    'description' => __('读取 CDN 全局规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_rules_list'],
                    'mode'        => 'read',
                    'params'      => [],
                ],
                [
                    'name'        => 'getDomainRules',
                    'description' => __('读取 CDN 域名规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_rules_list'],
                    'mode'        => 'read',
                    'params'      => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => true, 'min' => 1],
                    ],
                ],
                [
                    'name'        => 'saveGlobalRules',
                    'description' => __('保存 CDN 全局规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_rules_global_save'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'rules', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name'        => 'saveDomainRules',
                    'description' => __('保存 CDN 域名规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_rules_domain_save'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => true, 'min' => 1],
                        ['name' => 'rules', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name'        => 'importDomainRules',
                    'description' => __('从 CDN 导入域名规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_rules_import_do'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => true, 'min' => 1],
                    ],
                ],
                [
                    'name'        => 'pushDomainRules',
                    'description' => __('推送 CDN 域名规则'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_rules_push'],
                    'mode'        => 'write',
                    'params'      => [
                        ['name' => 'domain_id', 'type' => 'int', 'required' => true, 'min' => 1],
                    ],
                ],
                [
                    'name'        => 'listEnabledDomains',
                    'description' => __('读取可推送的 CDN 域名'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_rules_list'],
                    'mode'        => 'read',
                    'params'      => [],
                ],
                [
                    'name'        => 'ensureZone',
                    'description' => __('确保域名在 CDN 中存在（创建或返回已有 Zone）'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'testConnection',
                    'description' => __('测试账户连接'),
                    'params'      => [
                        ['name' => 'account_id', 'type' => 'int', 'required' => true, 'description' => __('账户 ID')],
                    ],
                ],
                [
                    'name'        => 'getAdapterInfo',
                    'description' => __('获取适配器详细信息'),
                    'params'      => [
                        ['name' => 'adapter', 'type' => 'string', 'required' => true, 'description' => __('适配器代码')],
                    ],
                ],
                [
                    'name'        => 'bindAccountToScope',
                    'description' => __('将 CDN/媒体账户绑定到 Scope（TEST-P1D-02）'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_account_save'],
                    'mode'        => 'write',
                    'params'      => [
                        'account_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'adapter' => ['type' => 'string', 'required' => true, 'max_length' => 50],
                        'media_base_url' => ['type' => 'string', 'required' => false, 'max_length' => 1024],
                        'global_alias' => ['type' => 'string', 'required' => false, 'max_length' => 191],
                        'scope_kind' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'website_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                ],
                [
                    'name'        => 'resolveBinding',
                    'description' => __('解析 Scope 授权账户绑定（跨请求 DB）'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_account_list'],
                    'mode'        => 'read',
                    'params'      => [
                        'adapter' => ['type' => 'string', 'required' => true, 'max_length' => 50],
                        'scope_kind' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'website_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                ],
                [
                    'name'        => 'restoreScopeInheritance',
                    'description' => __('删除本 Scope 覆盖，恢复父级/Global 绑定'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_account_save'],
                    'mode'        => 'write',
                    'params'      => [
                        'adapter' => ['type' => 'string', 'required' => true, 'max_length' => 50],
                        'scope_kind' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'website_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                ],
                [
                    'name'        => 'resolveCowMediaUrl',
                    'description' => __('解析 Scope COW 媒体 URL'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_account_list'],
                    'mode'        => 'read',
                    'params'      => [
                        'path' => ['type' => 'string', 'required' => true, 'max_length' => 512],
                        'shared_base_url' => ['type' => 'string', 'required' => false, 'max_length' => 1024],
                        'scope_kind' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'website_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                ],
                [
                    'name'        => 'resolveAuthorizedAccount',
                    'description' => __('解析 Scope 授权账户（脱敏，无 credentials）'),
                    'frontend'    => true,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_account_list'],
                    'mode'        => 'read',
                    'params'      => [
                        'adapter' => ['type' => 'string', 'required' => true, 'max_length' => 50],
                        'scope_kind' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'website_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                ],
                [
                    'name'        => 'reconcileMailDns',
                    'description' => __('预览或同步当前企业邮箱域名的 Cloudflare DNS'),
                    'frontend'    => false,
                    'auth'        => 'backend',
                    'backend'     => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Cdn::cdn_account_manager'],
                    'mode'        => 'write',
                    'graph'       => false,
                    'params'      => [
                        'domain' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                        'desired_records' => ['type' => 'array', 'required' => true],
                        'dns_only_hosts' => ['type' => 'array', 'required' => true],
                        'apply' => ['type' => 'bool', 'required' => false],
                    ],
                    'returns'     => ['type' => 'array'],
                ],
            ],
        ];
    }

    private function getAdapters(): array
    {
        $adapters = [];
        foreach ($this->adapterResolver->getAllAdapters() as $adapter) {
            $adapters[] = [
                'code'        => $adapter->getAdapterCode(),
                'name'        => $adapter->getAdapterName(),
                'description' => $adapter->getDescription(),
            ];
        }
        return $adapters;
    }

    private function getAccounts(array $params): array
    {
        $model = clone $this->accountModel;
        $model->clearQuery();

        $adapter = $params['adapter'] ?? null;
        $status = $params['status'] ?? null;

        if ($adapter !== null && $adapter !== '') {
            $model->where(Account::schema_fields_ADAPTER, (string)$adapter);
        }
        if ($status !== null && $status !== '') {
            $model->where(Account::schema_fields_STATUS, (string)$status);
        }

        $records = $model->select()->fetchArray();
        $accounts = [];
        foreach ($records as $record) {
            $accounts[] = [
                'account_id'  => (int)($record[Account::schema_fields_ACCOUNT_ID] ?? 0),
                'adapter'     => (string)($record[Account::schema_fields_ADAPTER] ?? ''),
                'name'        => (string)($record[Account::schema_fields_NAME] ?? ''),
                'description' => (string)($record[Account::schema_fields_DESCRIPTION] ?? ''),
                'is_default'  => (bool)($record[Account::schema_fields_IS_DEFAULT] ?? false),
                'status'      => (string)($record[Account::schema_fields_STATUS] ?? ''),
            ];
        }
        return $accounts;
    }

    private function getAccount(array $params): ?array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return null;
        }

        $account = clone $this->accountModel;
        $account->clearQuery();
        $account->load($accountId);

        if (!$account->getId()) {
            return null;
        }

        return [
            'account_id'  => (int)$account->getData(Account::schema_fields_ACCOUNT_ID),
            'adapter'     => (string)$account->getData(Account::schema_fields_ADAPTER),
            'name'        => (string)$account->getData(Account::schema_fields_NAME),
            'description' => (string)$account->getData(Account::schema_fields_DESCRIPTION),
            'is_default'  => (bool)$account->getData(Account::schema_fields_IS_DEFAULT),
            'status'      => (string)$account->getData(Account::schema_fields_STATUS),
            // TASK-P1D-002：响应永不含明文 credentials / secret_ref
            'has_credentials' => $account->getCredentialsArray() !== [],
        ];
    }

    private function saveAccount(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $adapter = (string)($params['adapter'] ?? '');
        $name = (string)($params['name'] ?? '');

        if ($adapter === '' || $name === '') {
            return ['success' => false, 'message' => (string)__('适配器代码和账户名称不能为空')];
        }

        $adapterInstance = $this->adapterResolver->getAdapter($adapter);
        if ($adapterInstance === null) {
            return ['success' => false, 'message' => (string)__('未找到 CDN 适配器：%{1}', $adapter)];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();

            if ($accountId > 0) {
                $account->load($accountId);
                if (!$account->getId()) {
                    return ['success' => false, 'message' => (string)__('账户不存在：%{1}', (string)$accountId)];
                }
            }

            $account->setData(Account::schema_fields_ADAPTER, $adapter);
            $account->setData(Account::schema_fields_NAME, $name);

            if (isset($params['description'])) {
                $account->setData(Account::schema_fields_DESCRIPTION, (string)$params['description']);
            }
            if (isset($params['credentials']) && is_array($params['credentials'])) {
                $account->setCredentialsArray($params['credentials']);
            }
            if (isset($params['status'])) {
                $account->setData(Account::schema_fields_STATUS, (string)$params['status']);
            }

            $account->save();

            if (isset($params['is_default']) && $params['is_default']) {
                $this->accountManager->setDefaultAccount((int)$account->getId());
            }

            $action = $accountId > 0 ? __('更新') : __('创建');
            return [
                'success'    => true,
                'message'    => (string)__('账户%{1}成功', (string)$action),
                'account_id' => (int)$account->getId(),
            ];
        } catch (\Throwable $e) {
            w_log_error((string)__('保存账户失败：%{1}', $e->getMessage()), [], 'cdn_query');
            return ['success' => false, 'message' => (string)__('保存失败：%{1}', $e->getMessage())];
        }
    }

    private function deleteAccount(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? $params['id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => (string)__('账户 ID 无效')];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->load($accountId);

            if (!$account->getId()) {
                return ['success' => false, 'message' => (string)__('账户不存在')];
            }

            $account->delete();
            return ['success' => true, 'message' => (string)__('账户已删除')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string)__('删除失败：%{1}', $e->getMessage())];
        }
    }

    private function deleteDomain(array $params): array
    {
        $domainId = (int)($params['domain_id'] ?? $params['id'] ?? 0);
        if ($domainId <= 0) {
            return ['success' => false, 'message' => (string)__('域名ID不能为空')];
        }

        try {
            $domain = clone $this->domainModel;
            $domain->clearQuery();
            $domain->load($domainId);
            if (!$domain->getId()) {
                return ['success' => false, 'message' => (string)__('域名不存在')];
            }
            $domain->delete()->fetch();
            return ['success' => true, 'message' => (string)__('域名删除成功')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string)__('删除失败：%{1}', $e->getMessage())];
        }
    }

    private function setDefaultAccount(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => (string)__('账户 ID 无效')];
        }

        try {
            $this->accountManager->setDefaultAccount($accountId);
            return ['success' => true, 'message' => (string)__('已设为默认账户')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getDefaultAccount(array $params): ?array
    {
        $adapter = (string)($params['adapter'] ?? '');
        if ($adapter === '') {
            return null;
        }

        $account = $this->accountManager->getDefaultAccount($adapter);
        if ($account === null) {
            return null;
        }

        return [
            'account_id'  => (int)$account->getData(Account::schema_fields_ACCOUNT_ID),
            'adapter'     => (string)$account->getData(Account::schema_fields_ADAPTER),
            'name'        => (string)$account->getData(Account::schema_fields_NAME),
            'is_default'  => true,
            'status'      => (string)$account->getData(Account::schema_fields_STATUS),
        ];
    }

    private function getDomains(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return [];
        }

        return $this->accountManager->getAccountDomains($accountId);
    }

    private function ensureZone(array $params): array
    {
        $domain = (string)($params['domain'] ?? '');
        $accountId = (int)($params['account_id'] ?? 0);

        if ($domain === '' || $accountId <= 0) {
            return ['success' => false, 'message' => (string)__('域名和账户 ID 不能为空')];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->load($accountId);

            if (!$account->getId()) {
                return ['success' => false, 'message' => (string)__('账户不存在')];
            }

            $adapterCode = (string)$account->getData(Account::schema_fields_ADAPTER);
            $adapter = $this->adapterResolver->getAdapter($adapterCode);

            if ($adapter === null) {
                return ['success' => false, 'message' => (string)__('适配器不存在：%{1}', $adapterCode)];
            }

            $credentials = $account->getCredentialsArray();
            $zoneInfo = $adapter->ensureZone($domain, $credentials);

            return [
                'success' => true,
                'zone_id' => $zoneInfo['zone_id'] ?? '',
                'data'    => $zoneInfo,
            ];
        } catch (\Throwable $e) {
            w_log_error((string)__('ensureZone 失败：%{1}', $e->getMessage()), [], 'cdn_query');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function testConnection(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return ['success' => false, 'message' => (string)__('账户 ID 无效')];
        }

        try {
            $account = clone $this->accountModel;
            $account->clearQuery();
            $account->load($accountId);

            if (!$account->getId()) {
                return ['success' => false, 'message' => (string)__('账户不存在')];
            }

            $adapterCode = (string)$account->getData(Account::schema_fields_ADAPTER);
            $adapter = $this->adapterResolver->getAdapter($adapterCode);

            if ($adapter === null) {
                return ['success' => false, 'message' => (string)__('适配器不存在')];
            }

            $credentials = $account->getCredentialsArray();

            if (empty($credentials)) {
                return ['success' => false, 'message' => (string)__('账户凭证为空')];
            }

            return ['success' => true, 'message' => (string)__('账户配置有效')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getAdapterInfo(array $params): ?array
    {
        $adapterCode = (string)($params['adapter'] ?? '');
        if ($adapterCode === '') {
            return null;
        }

        $adapter = $this->adapterResolver->getAdapter($adapterCode);
        if ($adapter === null) {
            return null;
        }

        return [
            'code'        => $adapter->getAdapterCode(),
            'name'        => $adapter->getAdapterName(),
            'description' => $adapter->getDescription(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function bindAccountToScope(array $params): array
    {
        try {
            $binding = $this->accountManager->bindAccountToScope(
                (int)($params['account_id'] ?? 0),
                $this->scopeFromParams($params),
                (string)($params['adapter'] ?? ''),
                (string)($params['media_base_url'] ?? ''),
                (string)($params['global_alias'] ?? ''),
            );

            return [
                'success' => true,
                'binding' => $this->projectBinding($binding),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'cdn_bind_failed',
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolveBinding(array $params): array
    {
        try {
            $hit = $this->scopedAccountBindingService->resolve(
                $this->scopeFromParams($params),
                (string)($params['adapter'] ?? ''),
            );

            return [
                'success' => true,
                'binding' => $hit === null ? null : $this->projectBinding($hit),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'binding' => null,
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function restoreScopeInheritance(array $params): array
    {
        try {
            $restored = $this->accountManager->restoreScopeInheritance(
                $this->scopeFromParams($params),
                (string)($params['adapter'] ?? ''),
            );

            return [
                'success' => true,
                'restored' => $restored,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'restored' => false,
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolveCowMediaUrl(array $params): array
    {
        try {
            $scope = $this->scopeFromParams($params);
            $path = (string)($params['path'] ?? '');
            $shared = (string)($params['shared_base_url'] ?? '/pub/media');
            if ($shared === '') {
                $shared = '/pub/media';
            }
            $url = $this->mediaUrlCowResolver->resolveCowMediaUrl($path, $scope, $shared);

            return [
                'success' => true,
                'url' => $url,
                'is_cow_override' => $this->mediaUrlCowResolver->isCowOverride($scope, $shared),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'url' => null,
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    /**
     * Provider-owned write command; credentials never cross the module boundary.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function reconcileMailDnsOp(array $params): array
    {
        try {
            $desired = $params['desired_records'] ?? [];
            $dnsOnlyHosts = $params['dns_only_hosts'] ?? [];
            if (!is_array($desired) || !is_array($dnsOnlyHosts)) {
                throw new \InvalidArgumentException((string)__('邮箱 DNS 参数格式无效。'));
            }

            $manager = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Cdn\Api\MailDnsManagerInterface::class
            );

            return $manager->reconcile(
                (string)($params['domain'] ?? ''),
                $desired,
                $dnsOnlyHosts,
                (bool)($params['apply'] ?? false),
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage(),
                'operation_count' => 0,
                'operations' => [],
                'residual_changes' => [],
            ];
        }
    }

    private function resolveAuthorizedAccountOp(array $params): array
    {
        try {
            $account = $this->accountManager->resolveAuthorizedAccount(
                (string)($params['adapter'] ?? ''),
                $this->scopeFromParams($params),
            );
            if ($account === null) {
                return ['success' => true, 'account' => null];
            }

            $projected = [
                'account_id' => (int)$account->getData(Account::schema_fields_ACCOUNT_ID),
                'adapter' => (string)$account->getData(Account::schema_fields_ADAPTER),
                'name' => (string)$account->getData(Account::schema_fields_NAME),
                'description' => (string)$account->getData(Account::schema_fields_DESCRIPTION),
                'is_default' => (bool)$account->getData(Account::schema_fields_IS_DEFAULT),
                'status' => (string)$account->getData(Account::schema_fields_STATUS),
                'has_credentials' => $account->getCredentialsArray() !== [],
            ];
            $this->assertNoSecretLeak($projected);

            return ['success' => true, 'account' => $projected];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'account' => null,
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function scopeFromParams(array $params): ScopeIdentity
    {
        $kind = \strtolower(\trim((string)($params['scope_kind'] ?? ScopeIdentity::KIND_WEBSITE)));
        $websiteId = (int)($params['website_id'] ?? 0);
        $websiteCode = \trim((string)($params['website_code'] ?? 'default'));
        if ($websiteCode === '') {
            $websiteCode = 'default';
        }
        $storeCode = \trim((string)($params['store_code'] ?? ''));
        $channelCode = \trim((string)($params['channel_code'] ?? ''));
        $storeMode = \strtolower(\trim((string)($params['store_mode'] ?? ScopeIdentity::MODE_NORMAL)));
        if ($storeMode === '') {
            $storeMode = ScopeIdentity::MODE_NORMAL;
        }

        return match ($kind) {
            ScopeIdentity::KIND_GLOBAL => ScopeIdentity::global(),
            ScopeIdentity::KIND_STORE => ScopeIdentity::store($websiteId, $websiteCode, $storeCode, $storeMode),
            ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                $websiteId,
                $websiteCode,
                $storeCode,
                $channelCode,
                $storeMode,
            ),
            default => ScopeIdentity::website($websiteId, $websiteCode),
        };
    }

    /**
     * @param array<string, mixed> $binding
     * @return array<string, mixed>
     */
    private function projectBinding(array $binding): array
    {
        $projected = [
            'account_id' => (int)($binding['account_id'] ?? 0),
            'adapter' => (string)($binding['adapter'] ?? ''),
            'media_base_url' => (string)($binding['media_base_url'] ?? ''),
            'global_alias' => (string)($binding['global_alias'] ?? ''),
            'storage_scope' => (string)($binding['storage_scope'] ?? ''),
            'store_mode' => (string)($binding['store_mode'] ?? ''),
        ];
        if (isset($binding['source_kind'])) {
            $projected['source_kind'] = (string)$binding['source_kind'];
        }
        $this->assertNoSecretLeak($projected);

        return $projected;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertNoSecretLeak(array $payload): void
    {
        foreach (['credentials', 'secret_ref'] as $forbidden) {
            if (\array_key_exists($forbidden, $payload)) {
                throw new \RuntimeException('cdn_binding_response_secret_leak');
            }
        }
    }
}
