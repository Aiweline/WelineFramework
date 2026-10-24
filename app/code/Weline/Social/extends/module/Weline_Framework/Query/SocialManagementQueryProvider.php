<?php

declare(strict_types=1);

namespace Weline\Social\Extends\Module\Weline_Framework\Query;

use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Social\Model\SocialAutopilotPlan;
use Weline\Social\Service\SocialAccountService;
use Weline\Social\Service\SocialAutopilotService;
use Weline\Social\Service\SocialCreativeService;
use Weline\Social\Service\SocialOauthService;
use Weline\Social\Service\SocialPlatformIconService;
use Weline\Social\Service\SocialPlatformRegistry;
use Weline\Social\Service\SocialPublishService;
use Weline\Social\Service\SocialReviewService;
use Weline\Social\Service\SocialScopeMailboxService;
use Weline\Social\Service\SocialScopePhoneConfig;
use Weline\Social\Service\SocialWebsiteAccountService;

class SocialManagementQueryProvider implements QueryProviderInterface
{
    private const MEDIA_CONTENT_TYPES = ['text', 'image', 'video'];
    private const VIDEO_SOURCES = ['image_slideshow', 'ai_video'];

    public function __construct(
        private readonly SocialPlatformRegistry $registry,
        private readonly SocialAccountService $accountService,
        private readonly SocialCreativeService $creativeService,
        private readonly SocialPublishService $publishService,
        private readonly SocialPlatformIconService $iconService,
        private readonly SocialWebsiteAccountService $websiteAccountService,
        private readonly SocialOauthService $oauthService,
        private readonly SocialScopeMailboxService $scopeMailboxService,
        private readonly SocialAutopilotService $autopilotService,
        private readonly SocialReviewService $reviewService,
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
            'completeAuthorization' => $this->oauthService->complete($params),
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
            'listAutopilotPlan' => $this->getAutopilotPlan((int)($params['website_id'] ?? 0)),
            'saveAutopilotPlan' => $this->saveAutopilotPlan($params),
            'listMediaModels' => $this->listMediaModels(),
            'listExtensionJobs' => $this->autopilotService->listExtensionJobs(
                (int)($params['website_id'] ?? 0),
                (int)($params['limit'] ?? 20)
            ),
            'listReviewQueue' => $this->autopilotService->listReviewQueue((int)($params['limit'] ?? 20)),
            'getAutopilotRunStatus' => $this->autopilotService->getRunStatus((int)($params['website_id'] ?? 0)),
            'approveReview' => $this->approveReview($params),
            'rejectReview' => $this->rejectReview($params),
            'createExtensionGrantCode' => $this->createExtensionGrantCode($params),
            'getScopeSocialMailbox' => $this->scopeMailboxService->getScopeSocialMailbox($params),
            'ensureScopeSocialMailbox' => $this->scopeMailboxService->ensureScopeSocialMailbox($params),
            'resetScopeSocialMailboxPassword' => $this->scopeMailboxService->resetScopeSocialMailboxPassword($params),
            'probeScopeSocialMailbox' => $this->scopeMailboxService->probeScopeSocialMailbox($params),
            'getScopeSocialPhone' => ObjectManager::getInstance(SocialScopePhoneConfig::class)->getPhone((int)($params['website_id'] ?? $params['scope_id'] ?? 0), (string)($params['scope_code'] ?? '')),
            'saveScopeSocialPhone' => ObjectManager::getInstance(SocialScopePhoneConfig::class)->savePhone((int)($params['website_id'] ?? $params['scope_id'] ?? 0), (array)($params['data'] ?? $params), (string)($params['scope_code'] ?? '')),
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
            'description' => __('提供 Weline_Social 平台、账户、AI 创意、发布批次与日更自动运营操作。'),
            'module' => 'Weline_Social',
            'operations' => \array_map(
                static function (array $operation): array {
                    $sourceId = ($operation['name'] ?? '') === 'completeAuthorization'
                        ? 'Weline_Social::social_oauth_callback'
                        : (($operation['mode'] ?? '') === 'read'
                            ? 'Weline_Social::social_index'
                            : 'Weline_Social::social');
                    return $operation + [
                        'backend_acl' => [
                            'kind' => 'source',
                            'source_id' => $sourceId,
                        ],
                    ];
                },
                [
                ['name' => 'listPlatforms', 'description' => __('列出平台注册表。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'graph' => true, 'params' => ['force_reload' => ['type' => 'bool']]],
                ['name' => 'getPlatform', 'description' => __('获取单个平台定义。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'graph' => true, 'params' => ['platform_code' => ['type' => 'string', 'required' => true, 'max_length' => 64]]],
                ['name' => 'listAccounts', 'description' => __('列出已连接账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => []],
                ['name' => 'getAccount', 'description' => __('获取社媒账户详情。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['account_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
                ['name' => 'listWidgetAccounts', 'description' => __('列出可展示的社媒账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'graph' => true, 'params' => ['platforms' => ['type' => 'list', 'max_items' => 100], 'limit' => ['type' => 'int', 'min' => 1, 'max' => 100], 'website_id' => ['type' => 'int', 'min' => 0]]],
                ['name' => 'startAuthorization', 'description' => __('生成平台授权地址。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['platform_code' => ['type' => 'string', 'required' => true, 'max_length' => 64], 'redirect_uri' => ['type' => 'string', 'max_length' => 2048], 'state' => ['type' => 'string', 'max_length' => 128], 'account_context' => ['type' => 'map'], 'website_id' => ['type' => 'int', 'min' => 0], 'scope_type' => ['type' => 'string', 'max_length' => 32], 'scope_id' => ['type' => 'int', 'min' => 0], 'child_scope_type' => ['type' => 'string', 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'scope_key' => ['type' => 'string', 'max_length' => 128], 'scope_code' => ['type' => 'string', 'max_length' => 64]]],
                ['name' => 'completeAuthorization', 'description' => __('完成平台授权回调并保存账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['code' => ['type' => 'string', 'max_length' => 2048], 'state' => ['type' => 'string', 'required' => true, 'max_length' => 128], 'error' => ['type' => 'string', 'max_length' => 255], 'error_description' => ['type' => 'string', 'max_length' => 1024]]],
                ['name' => 'saveCredentialAccount', 'description' => __('保存官方凭据账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['account_id' => ['type' => 'int', 'min' => 0], 'platform_code' => ['type' => 'string', 'required' => true, 'max_length' => 64], 'account_name' => ['type' => 'string', 'required' => true, 'max_length' => 150], 'auth_mode' => ['type' => 'string', 'max_length' => 64], 'profile_url' => ['type' => 'string', 'max_length' => 512], 'widget_enabled' => ['type' => 'bool'], 'publish_enabled' => ['type' => 'bool'], 'sort_order' => ['type' => 'int'], 'credentials' => ['type' => 'map'], 'scopes' => ['type' => 'list', 'max_items' => 50], 'token_expires_at' => ['type' => 'string', 'max_length' => 32], 'remote_account_id' => ['type' => 'string', 'max_length' => 150], 'remote_account_name' => ['type' => 'string', 'max_length' => 190]]],
                ['name' => 'testAccount', 'description' => __('检测账户凭据。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['account_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
                ['name' => 'disableAccount', 'description' => __('禁用社媒账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['account_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
                ['name' => 'listWebsites', 'description' => __('列出可配置社媒默认账户的站点。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => []],
                ['name' => 'listScopes', 'description' => __('列出可配置社媒默认账户的两级范围。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => []],
                ['name' => 'listWebsiteAccountRelations', 'description' => __('列出站点与社媒账户关系。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'min' => 0], 'include_disabled' => ['type' => 'bool']]],
                ['name' => 'listScopeAccountRelations', 'description' => __('列出范围与社媒账户关系。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['scope_type' => ['type' => 'string', 'max_length' => 32], 'scope_id' => ['type' => 'int', 'min' => 0], 'child_scope_type' => ['type' => 'string', 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'include_disabled' => ['type' => 'bool']]],
                ['name' => 'saveWebsiteAccountDefaults', 'description' => __('保存站点默认社媒账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['website_id' => ['type' => 'int', 'required' => true, 'min' => 0], 'account_ids' => ['type' => 'list', 'max_items' => 100], 'sort_orders' => ['type' => 'map']]],
                ['name' => 'saveScopeAccountDefaults', 'description' => __('保存范围默认社媒账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['scope_type' => ['type' => 'string', 'required' => true, 'max_length' => 32], 'scope_id' => ['type' => 'int', 'required' => true, 'min' => 0], 'child_scope_type' => ['type' => 'string', 'required' => true, 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'account_ids' => ['type' => 'list', 'max_items' => 100], 'sort_orders' => ['type' => 'map']]],
                ['name' => 'getWebsiteDefaultAccounts', 'description' => __('获取单个站点默认社媒账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'required' => true, 'min' => 0], 'publish_only' => ['type' => 'bool']]],
                ['name' => 'getScopeDefaultAccounts', 'description' => __('获取单个范围默认社媒账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['scope_type' => ['type' => 'string', 'required' => true, 'max_length' => 32], 'scope_id' => ['type' => 'int', 'required' => true, 'min' => 0], 'child_scope_type' => ['type' => 'string', 'required' => true, 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'publish_only' => ['type' => 'bool']]],
                ['name' => 'resolvePublishAccounts', 'description' => __('按范围、站点或全部站解析默认发布账户。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['scope_type' => ['type' => 'string', 'max_length' => 32], 'scope_id' => ['type' => 'int', 'min' => 0], 'child_scope_type' => ['type' => 'string', 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'website_id' => ['type' => 'int', 'min' => 0], 'website_ids' => ['type' => 'list', 'max_items' => 500], 'all_sites' => ['type' => 'bool']]],
                ['name' => 'generateCreative', 'description' => __('生成融媒体创意。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['title' => ['type' => 'string', 'max_length' => 190], 'prompt' => ['type' => 'string', 'max_length' => 4000], 'platforms' => ['type' => 'list', 'max_items' => 100], 'fake_mode' => ['type' => 'bool'], 'use_ai' => ['type' => 'bool'], 'assets' => ['type' => 'list', 'max_items' => 50]]],
                ['name' => 'createPublishBatch', 'description' => __('创建一键多平台发布批次。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['draft_id' => ['type' => 'int', 'required' => true, 'min' => 1], 'account_ids' => ['type' => 'list', 'max_items' => 100], 'scope_type' => ['type' => 'string', 'max_length' => 32], 'scope_id' => ['type' => 'int', 'min' => 0], 'child_scope_type' => ['type' => 'string', 'max_length' => 32], 'child_scope_id' => ['type' => 'int', 'min' => 0], 'website_id' => ['type' => 'int', 'min' => 0], 'website_ids' => ['type' => 'list', 'max_items' => 500], 'all_sites' => ['type' => 'bool'], 'content_kind' => ['type' => 'string', 'max_length' => 32], 'title' => ['type' => 'string', 'max_length' => 190], 'scheduled_at' => ['type' => 'string', 'max_length' => 32], 'fake_mode' => ['type' => 'bool']]],
                ['name' => 'getPublishBatchStatus', 'description' => __('获取发布批次状态。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['batch_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
                ['name' => 'listAutopilotPlan', 'description' => __('读取站点日更自动运营计划（缺省 enabled=0）。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'min' => 0]]],
                ['name' => 'saveAutopilotPlan', 'description' => __('保存站点日更自动运营计划（只写计划表，不触发外发）。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['website_id' => ['type' => 'int', 'required' => true, 'min' => 0], 'enabled' => ['type' => 'bool'], 'timezone' => ['type' => 'string', 'max_length' => 64], 'slots' => ['type' => 'list', 'max_items' => 24], 'mix' => ['type' => 'map'], 'topic_ratio' => ['type' => 'map'], 'auto_platform_codes' => ['type' => 'list', 'max_items' => 100], 'review_video' => ['type' => 'bool'], 'quota' => ['type' => 'map'], 'mode' => ['type' => 'string', 'max_length' => 16], 'max_daily' => ['type' => 'int', 'min' => 1, 'max' => 50], 'daily_count' => ['type' => 'int', 'min' => 1, 'max' => 20], 'content_types' => ['type' => 'list', 'max_items' => 3], 'image_model_code' => ['type' => 'string', 'max_length' => 191], 'video_model_code' => ['type' => 'string', 'max_length' => 191], 'video_source' => ['type' => 'string', 'max_length' => 24]]],
                ['name' => 'listMediaModels', 'description' => __('列出可用于图片/视频生成的有效模型与默认文字模型。'), 'frontend' => false, 'backend' => true, 'external' => false, 'mode' => 'read', 'params' => []],
                ['name' => 'listExtensionJobs', 'description' => __('列出浏览器扩展待发任务。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'min' => 0], 'limit' => ['type' => 'int', 'min' => 1, 'max' => 100]]],
                ['name' => 'listReviewQueue', 'description' => __('列出待人工审片的草稿。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['limit' => ['type' => 'int', 'min' => 1, 'max' => 100]]],
                ['name' => 'getAutopilotRunStatus', 'description' => __('读取日更运行状态与 needs_user 阻塞项。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'required' => true, 'min' => 0]]],
                ['name' => 'approveReview', 'description' => __('审片通过并按通道路由发布草稿。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['draft_id' => ['type' => 'int', 'required' => true, 'min' => 1]]],
                ['name' => 'rejectReview', 'description' => __('驳回审片并记录重生成次数。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['draft_id' => ['type' => 'int', 'required' => true, 'min' => 1], 'reason' => ['type' => 'string', 'required' => true, 'max_length' => 255]]],
                ['name' => 'createExtensionGrantCode', 'description' => __('生成浏览器扩展一次性授权码（5 分钟有效）。'), 'frontend' => true, 'backend' => true, 'external' => true, 'mode' => 'write', 'params' => ['website_id' => ['type' => 'int', 'min' => 0]]],
                ['name' => 'getScopeSocialMailbox', 'description' => __('获取当前网站范围的社媒注册专用邮箱状态。'), 'frontend' => true, 'backend' => true, 'external' => false, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'min' => 0], 'scope_id' => ['type' => 'int', 'min' => 0], 'scope_key' => ['type' => 'string', 'max_length' => 128]]],
                ['name' => 'ensureScopeSocialMailbox', 'description' => __('一键开通当前网站范围的社媒注册专用邮箱。'), 'frontend' => true, 'backend' => true, 'external' => false, 'mode' => 'write', 'params' => ['website_id' => ['type' => 'int', 'min' => 0], 'scope_id' => ['type' => 'int', 'min' => 0], 'scope_key' => ['type' => 'string', 'max_length' => 128]]],
                ['name' => 'resetScopeSocialMailboxPassword', 'description' => __('重置当前网站范围社媒专用邮箱密码并返回一次明文。'), 'frontend' => true, 'backend' => true, 'external' => false, 'mode' => 'write', 'params' => ['website_id' => ['type' => 'int', 'min' => 0], 'scope_id' => ['type' => 'int', 'min' => 0], 'scope_key' => ['type' => 'string', 'max_length' => 128], 'password' => ['type' => 'string', 'max_length' => 128]]],
                ['name' => 'probeScopeSocialMailbox', 'description' => __('开通（若缺）社媒邮箱并向本站 contact 发探测信，确认能收到后再允许注册平台。'), 'frontend' => true, 'backend' => true, 'external' => false, 'mode' => 'write', 'params' => ['website_id' => ['type' => 'int', 'min' => 0], 'scope_id' => ['type' => 'int', 'min' => 0], 'scope_key' => ['type' => 'string', 'max_length' => 128]]],
                ['name' => 'getScopeSocialPhone', 'description' => __('获取当前站点范围的社媒专用手机号。'), 'frontend' => true, 'backend' => true, 'external' => false, 'mode' => 'read', 'params' => ['website_id' => ['type' => 'int', 'min' => 0], 'scope_id' => ['type' => 'int', 'min' => 0], 'scope_code' => ['type' => 'string', 'max_length' => 64]]],
                ['name' => 'saveScopeSocialPhone', 'description' => __('保存当前站点范围的社媒专用手机号（一站一号，换号须全站社媒同步）。'), 'frontend' => true, 'backend' => true, 'external' => false, 'mode' => 'write', 'params' => ['website_id' => ['type' => 'int', 'min' => 0], 'scope_id' => ['type' => 'int', 'min' => 0], 'scope_code' => ['type' => 'string', 'max_length' => 64], 'data' => ['type' => 'map']]],
                ],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAutopilotPlan(int $websiteId): array
    {
        $plan = $this->autopilotService->getPlan($websiteId);
        if (($plan['exists'] ?? false) !== true) {
            return $plan;
        }

        try {
            $model = $this->newPlanModel();
            $model->reset()
                ->where(SocialAutopilotPlan::schema_fields_WEBSITE_ID, $websiteId)
                ->find()
                ->fetch();
            if ((int)$model->getId() < 1) {
                return $plan;
            }
            $data = $model->getData();

            return \array_merge($plan, $this->normalizePlanMediaRow(\is_array($data) ? $data : []));
        } catch (\Throwable) {
            return $plan;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function saveAutopilotPlan(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? -1);
        if ($websiteId < 0) {
            return ['success' => false, 'error' => 'invalid_website'];
        }

        $current = $this->currentPlanMedia($websiteId);
        $next = $this->mergePlanMediaInput($params, $current);
        $validation = $this->validateMediaModelSelection($next);
        if (($validation['success'] ?? false) !== true) {
            return $validation;
        }

        $result = $this->autopilotService->savePlan($params);
        if (($result['success'] ?? false) !== true) {
            return $result;
        }

        try {
            $model = $this->newPlanModel();
            $model->reset()
                ->where(SocialAutopilotPlan::schema_fields_WEBSITE_ID, $websiteId)
                ->find()
                ->fetch();
            if ((int)$model->getId() < 1) {
                return ['success' => false, 'error' => 'save_failed'];
            }
            $model->setData(SocialAutopilotPlan::schema_fields_DAILY_COUNT, (int)$next['daily_count'])
                ->setData(SocialAutopilotPlan::schema_fields_CONTENT_TYPES_JSON, $this->encodeContentTypes((array)$next['content_types']))
                ->setData(SocialAutopilotPlan::schema_fields_IMAGE_MODEL_CODE, (string)$next['image_model_code'])
                ->setData(SocialAutopilotPlan::schema_fields_VIDEO_MODEL_CODE, (string)$next['video_model_code'])
                ->setData(SocialAutopilotPlan::schema_fields_VIDEO_SOURCE, (string)$next['video_source'])
                ->save();
            $result['plan'] = $this->getAutopilotPlan($websiteId);

            return $result;
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'error' => 'save_failed',
                'message' => $throwable->getMessage(),
            ];
        }
    }

    private function newPlanModel(): SocialAutopilotPlan
    {
        return ObjectManager::getInstance(SocialAutopilotPlan::class);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizePlanMediaRow(array $row): array
    {
        $defaults = SocialAutopilotPlan::defaults();
        $dailyCount = (int)($row[SocialAutopilotPlan::schema_fields_DAILY_COUNT] ?? $defaults['daily_count']);
        $videoSource = $this->normalizeVideoSource(
            $row[SocialAutopilotPlan::schema_fields_VIDEO_SOURCE] ?? '',
            (string)$defaults['video_source']
        );

        return [
            'daily_count' => \max(1, \min(20, $dailyCount)),
            'content_types' => $this->normalizeContentTypes(
                $row[SocialAutopilotPlan::schema_fields_CONTENT_TYPES_JSON] ?? null
            ),
            'image_model_code' => \trim((string)($row[SocialAutopilotPlan::schema_fields_IMAGE_MODEL_CODE] ?? '')),
            'video_model_code' => \trim((string)($row[SocialAutopilotPlan::schema_fields_VIDEO_MODEL_CODE] ?? '')),
            'video_source' => $videoSource,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function mergePlanMediaInput(array $params, array $current): array
    {
        $next = $current;
        if (\array_key_exists('daily_count', $params)) {
            $next['daily_count'] = \max(1, \min(20, (int)$params['daily_count']));
        }
        if (\array_key_exists('content_types', $params)) {
            $next['content_types'] = $this->normalizeContentTypes($params['content_types']);
        }
        foreach (['image_model_code', 'video_model_code'] as $key) {
            if (\array_key_exists($key, $params)) {
                $next[$key] = \trim((string)$params[$key]);
            }
        }
        if (\array_key_exists('video_source', $params)) {
            $next['video_source'] = $this->normalizeVideoSource(
                $params['video_source'],
                (string)($current['video_source'] ?? 'image_slideshow')
            );
        }

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentPlanMedia(int $websiteId): array
    {
        $plan = $this->autopilotService->getPlan($websiteId);

        return [
            'daily_count' => \max(1, \min(20, (int)($plan['daily_count'] ?? 1))),
            'content_types' => $this->normalizeContentTypes($plan['content_types'] ?? null),
            'image_model_code' => \trim((string)($plan['image_model_code'] ?? '')),
            'video_model_code' => \trim((string)($plan['video_model_code'] ?? '')),
            'video_source' => $this->normalizeVideoSource($plan['video_source'] ?? ''),
        ];
    }

    /**
     * 生成模型校验：图片必须选可用图片模型；ai_video 必须选可用视频模型。
     *
     * @param array<string, mixed> $media
     * @param array<string, mixed>|null $models 测试注入；生产不传，实时取 listMediaModels
     * @return array{success:bool,error?:string}
     */
    private function validateMediaModelSelection(array $media, ?array $models = null): array
    {
        $contentTypes = $this->normalizeContentTypes($media['content_types'] ?? null);
        $videoSource = $this->normalizeVideoSource($media['video_source'] ?? '');
        $needImage = \in_array('image', $contentTypes, true);
        $needVideo = \in_array('video', $contentTypes, true) && $videoSource === 'ai_video';
        if (!$needImage && !$needVideo) {
            return ['success' => true];
        }

        $models ??= $this->listMediaModels();
        if ($needImage) {
            $imageModelCode = \trim((string)($media['image_model_code'] ?? ''));
            if ($imageModelCode === '' || !$this->mediaModelExists($models, 'image', $imageModelCode)) {
                return ['success' => false, 'error' => 'image_model_required'];
            }
        }
        if ($needVideo) {
            $videoModelCode = \trim((string)($media['video_model_code'] ?? ''));
            if ($videoModelCode === '' || !$this->mediaModelExists($models, 'video', $videoModelCode)) {
                return ['success' => false, 'error' => 'video_model_required'];
            }
        }

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $models
     */
    private function mediaModelExists(array $models, string $kind, string $code): bool
    {
        foreach ((array)($models[$kind] ?? []) as $model) {
            if (\is_array($model) && \trim((string)($model['code'] ?? '')) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function normalizeContentTypes(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($raw)) {
            $raw = [];
        }

        $types = [];
        foreach ($raw as $type) {
            $type = \strtolower(\trim((string)$type));
            if (\in_array($type, self::MEDIA_CONTENT_TYPES, true) && !\in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types === [] ? ['text'] : $types;
    }

    private function normalizeVideoSource(mixed $raw, string $fallback = 'image_slideshow'): string
    {
        $source = \strtolower(\trim((string)$raw));

        return \in_array($source, self::VIDEO_SOURCES, true) ? $source : $fallback;
    }

    /**
     * @param string[] $types
     */
    private function encodeContentTypes(array $types): string
    {
        $encoded = \json_encode(\array_values($types), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return \is_string($encoded) ? $encoded : '["text"]';
    }

    /**
     * @return array<string, mixed>
     */
    private function listMediaModels(): array
    {
        $textEmpty = ['code' => '', 'name' => ''];
        $result = ['success' => true, 'image' => [], 'video' => [], 'text_default' => $textEmpty];

        try {
            $rows = ObjectManager::getInstance(AiModel::class)->reset()
                ->where(AiModel::schema_fields_IS_ACTIVE, 1)
                ->order(AiModel::schema_fields_IS_DEFAULT, 'DESC')
                ->order(AiModel::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return $result;
        }
        if (!\is_array($rows)) {
            return $result;
        }

        $seenImage = [];
        $seenVideo = [];
        $textFallback = null;
        $textDefault = null;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row[AiModel::schema_fields_MODEL_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = \trim((string)($row[AiModel::schema_fields_NAME] ?? ''));
            $name = $name !== '' ? $name : $code;
            $supplier = \trim((string)($row[AiModel::schema_fields_SUPPLIER] ?? ''));
            $modality = \strtolower(\trim((string)($row[AiModel::schema_fields_PRIMARY_MODALITY] ?? '')));
            $capabilities = $this->decodeModelCapabilities($row[AiModel::schema_fields_CAPABILITIES] ?? null);
            $option = ['code' => $code, 'name' => $name, 'supplier' => $supplier];

            $isImage = $modality === AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE
                || \in_array('image_generation', $capabilities, true)
                || \in_array('image_output', $capabilities, true);
            $isVideo = $modality === AiModel::PRIMARY_MODALITY_TEXT_TO_VIDEO
                || \in_array('video_generation', $capabilities, true)
                || \in_array('video_output', $capabilities, true);
            if ($isImage && !isset($seenImage[$code])) {
                $seenImage[$code] = true;
                $result['image'][] = $option;
            }
            if ($isVideo && !isset($seenVideo[$code])) {
                $seenVideo[$code] = true;
                $result['video'][] = $option;
            }
            if ($modality !== AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT) {
                continue;
            }
            $textOption = ['code' => $code, 'name' => $name];
            if ($textFallback === null) {
                $textFallback = $textOption;
            }
            if ((int)($row[AiModel::schema_fields_IS_DEFAULT] ?? 0) === 1 && $textDefault === null) {
                $textDefault = $textOption;
            }
        }

        $result['text_default'] = $textDefault ?? $textFallback ?? $textEmpty;

        return $result;
    }

    /**
     * @return string[]
     */
    private function decodeModelCapabilities(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = \trim($raw);
            if ($raw === '') {
                return [];
            }
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : \array_map('trim', \explode(',', $raw));
        }
        if (!\is_array($raw)) {
            return [];
        }

        $capabilities = [];
        foreach ($raw as $key => $value) {
            if (\is_string($key)) {
                if ((bool)$value) {
                    $capabilities[] = \strtolower($key);
                }
                continue;
            }
            if (\is_string($value)) {
                $value = \strtolower(\trim($value));
                if ($value !== '') {
                    $capabilities[] = $value;
                }
            }
        }

        return \array_values(\array_unique($capabilities));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function createExtensionGrantCode(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? 0);
        $secret = \trim((string)Env::get('wls.social_extension_secret', ''));

        return self::buildExtensionGrantCode($websiteId, $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildExtensionGrantCode(int $websiteId, string $secret): array
    {
        $secret = \trim($secret);
        if ($secret === '') {
            return ['success' => false, 'error' => 'grant_not_configured'];
        }
        $exp = \time() + 300;
        $code = 'sx1.'.$exp.'.'.\hash_hmac('sha256', $exp.'|'.$websiteId, $secret);

        return [
            'success' => true,
            'code' => $code,
            'expires_at' => $exp,
            'ttl' => 300,
            'website_id' => $websiteId,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function approveReview(array $params): array
    {
        $draftId = (int)($params['draft_id'] ?? 0);
        if ($draftId < 1) {
            return ['success' => false, 'error' => 'invalid_draft'];
        }
        $review = $this->reviewService->approve($draftId, $this->currentUserId());
        if (($review['ok'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($review['error'] ?? 'approve_failed'),
                'review' => $review,
            ];
        }
        $publish = $this->autopilotService->publishReviewedDraft($draftId);

        return [
            'success' => ($publish['ok'] ?? false) === true,
            'review' => $review,
            'publish' => $publish,
            'error' => (string)($publish['error'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rejectReview(array $params): array
    {
        $draftId = (int)($params['draft_id'] ?? 0);
        $reason = \trim((string)($params['reason'] ?? ''));
        if ($draftId < 1) {
            return ['success' => false, 'error' => 'invalid_draft'];
        }
        if ($reason === '') {
            return ['success' => false, 'error' => 'missing_reason'];
        }
        $review = $this->reviewService->reject($draftId, $this->currentUserId(), $reason);
        if (($review['ok'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($review['error'] ?? 'reject_failed'),
                'review' => $review,
            ];
        }
        $regeneration = $this->autopilotService->markRejectedForRegeneration($draftId);

        return [
            'success' => true,
            'review' => $review,
            'regeneration' => $regeneration,
            'error' => ($regeneration['ok'] ?? false) === true
                ? ''
                : (string)($regeneration['error'] ?? 'regeneration_failed'),
        ];
    }

    private function currentUserId(): int
    {
        try {
            $session = ObjectManager::getInstance(AuthenticatedSessionInterface::class);

            return (int)($session->getLoginUserID() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
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
        $accountContext = \is_array($params['account_context'] ?? null) ? $params['account_context'] : [];
        foreach (['website_id', 'scope_type', 'scope_id', 'child_scope_type', 'child_scope_id', 'scope_key', 'scope_code', 'website_code'] as $key) {
            if (\array_key_exists($key, $params) && !\array_key_exists($key, $accountContext)) {
                $accountContext[$key] = $params[$key];
            }
        }

        return $this->oauthService->start(
            \strtolower(\trim((string)($params['platform_code'] ?? $params['platform'] ?? ''))),
            $accountContext,
            isset($params['redirect_uri']) ? (string)$params['redirect_uri'] : null
        );
    }
}
