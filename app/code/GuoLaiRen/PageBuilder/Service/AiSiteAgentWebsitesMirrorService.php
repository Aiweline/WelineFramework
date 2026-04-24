<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\AiSiteBuilderSession as WebsitesAiSiteBuilderSession;
use Weline\Websites\Service\AiWorkbench\SessionService as WebsitesSessionService;

/**
 * AiSiteAgentWebsitesMirrorService
 *
 * 从 `AiSiteAgent` 控制器抽出的 **Websites 镜像会话协调服务**。本轮迁移三个与
 * PageBuilder ↔ Websites 双向镜像相关的方法（R4.3）：
 *
 *  - `ensureMirrorSession`：按 PageBuilder 会话 scope 里的
 *    `handoff_workspace_public_id` 定位或创建对应的 Websites AI 建站会话，
 *    并把新建的 public_id 回写到 PageBuilder 侧 scope，完成 handoff 契约。
 *  - `buildScopeFromSource`：从 PageBuilder 会话的 scope 归一化出一个可直接
 *    写入 Websites 会话 scope 的关联字段集合（纯数据，最易单测）。
 *  - `syncScopeBack`：把 Websites 侧回流的站点档案 / 域名采购状态 merge 回
 *    PageBuilder 侧 scope，保持双端字段语义一致。
 *
 * 抽出动机：
 *  - 这三个方法在控制器里已形成一个清晰的"镜像会话协调"子域，独立语义很强；
 *  - `buildScopeFromSource` 为纯函数，可直接 characterization 测试锁定
 *    字段映射（handoff_source / provider_handoff_mode / recommended_domain_list /
 *    pagebuilder_workspace_url 等）；
 *  - 让后续继续拆域名采购编排 / 后端 SSE 回流链路时，可以直接复用本服务，
 *    而不必回到控制器 private 范围内穿针引线。
 *
 * 重要：方法签名、输入输出 shape 必须与 AiSiteAgent 原私有方法一致，
 * 以便控制器薄壳转发向后兼容；调整任何字段映射请同步更新测试。
 *
 * TODO(集成测试)：`ensureMirrorSession` / `syncScopeBack` 依赖
 * `AiSiteAgentSessionService` 与 `WebsitesSessionService` 的真实 DB 读写，
 * 当前仅覆盖 `buildScopeFromSource` 的纯函数 characterization，DB 路径
 * 留待集成测试补齐（不在本轮 R4.3 单测作用域内）。
 */
class AiSiteAgentWebsitesMirrorService
{
    private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService;
    private readonly AiSiteAgentSessionService $sessionService;
    private readonly Url $url;

    public function __construct(
        ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        ?AiSiteAgentSessionService $sessionService = null,
        ?Url $url = null,
    ) {
        $this->scopeCompatibilityService = $scopeCompatibilityService
            ?? ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        $this->sessionService = $sessionService
            ?? ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $this->url = $url ?? ObjectManager::getInstance(Url::class);
    }

    /**
     * 按 PageBuilder 会话的 `handoff_workspace_public_id` 定位 Websites 侧镜像会话；
     * 不存在则创建一个新的 `pagebuilder` 源 Websites AI 建站会话，并把 public_id
     * 写回 PageBuilder 侧 scope（`handoff_workspace_public_id` + `provider_handoff_mode`）。
     * 已存在则把最新 scope merge 进 Websites 会话，再重新 load 一次保证返回值是最新快照。
     */
    public function ensureMirrorSession(AiSiteAgentSession $session, int $adminId): ?WebsitesAiSiteBuilderSession
    {
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $linkedPublicId = \trim((string)($scope['handoff_workspace_public_id'] ?? ''));
        $websitesSessionService = $this->getWebsitesSessionService();
        $linkedSession = $linkedPublicId !== ''
            ? $websitesSessionService->loadByPublicId($linkedPublicId, $adminId)
            : null;
        $linkedScope = $this->buildScopeFromSource($session);

        if (!$linkedSession instanceof WebsitesAiSiteBuilderSession) {
            $linkedSession = $websitesSessionService->createSession('pagebuilder', $adminId, $linkedScope, [], 'prepare');
            $this->sessionService->mergeScope($session->getId(), $adminId, [
                'handoff_workspace_public_id' => $linkedSession->getPublicId(),
                'provider_handoff_mode' => 'pagebuilder_native_workspace',
            ]);

            return $linkedSession;
        }

        $websitesSessionService->mergeScope($linkedSession->getId(), $adminId, $linkedScope);

        return $websitesSessionService->loadById($linkedSession->getId(), $adminId) ?? $linkedSession;
    }

    /**
     * 从 PageBuilder 会话 scope 归一化出"可直接写入 Websites 会话 scope"的字段集合。
     * 纯函数，无副作用，可 characterization 测试锁定字段映射。
     *
     * @return array<string, mixed>
     */
    public function buildScopeFromSource(AiSiteAgentSession $session): array
    {
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $targetDomain = \strtolower(\trim((string)($scope['target_domain'] ?? '')));
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $preferredRegistrarAccountId = (int)($scope['preferred_registrar_account_id'] ?? $scope['registrar_account_id'] ?? 0);
        $recommendedRegistrarLabel = \trim((string)($scope['recommended_registrar_label'] ?? ''));
        $recommendedDomainList = $this->normalizeStringList($scope['recommended_domain_list'] ?? []);
        if ($recommendedDomainList === [] && $targetDomain !== '') {
            $recommendedDomainList[] = $targetDomain;
        }

        return [
            'handoff_source' => 'pagebuilder_native_workspace',
            'provider_handoff_mode' => 'pagebuilder_native_workspace',
            'pagebuilder_workspace_public_id' => $session->getPublicId(),
            'pagebuilder_workspace_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $session->getPublicId()]),
            'site_title' => (string)($scope['site_title'] ?? ''),
            'site_tagline' => (string)($scope['site_tagline'] ?? ''),
            'default_locale' => \trim((string)($scope['default_locale'] ?? $scope['default_language'] ?? '')),
            'plan_locale' => \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? '')),
            'brief_description' => $brief,
            'user_description' => $brief !== '' ? $brief : (string)($scope['user_description'] ?? ''),
            'target_domain' => $targetDomain,
            'selected_domain' => $targetDomain,
            'preferred_registrar_account_id' => $preferredRegistrarAccountId,
            'registrar_account_id' => $preferredRegistrarAccountId,
            'recommended_registrar_label' => $recommendedRegistrarLabel,
            'recommended_domain_list' => $recommendedDomainList,
            'page_types' => \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [],
            'recommended_pages' => \is_array($scope['recommended_pages'] ?? null) ? $scope['recommended_pages'] : (\is_array($scope['page_types'] ?? null) ? $scope['page_types'] : []),
            'fake_mode' => !empty($scope['fake_mode']) ? 1 : 0,
            'site_ready' => (int)($scope['site_ready'] ?? 1),
        ];
    }

    /**
     * 把 Websites 侧会话的 scope（站点档案 + 域名采购状态）merge 回 PageBuilder 会话，
     * 保持双端在 domain / profile / 采购进度字段上的语义一致。
     * 同时维护 `site_profile_manual`（手工填写标记）与
     * `AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY`（page_types 自定义标记）。
     */
    public function syncScopeBack(
        AiSiteAgentSession $pbSession,
        WebsitesAiSiteBuilderSession $wsSession,
        int $adminId
    ): void {
        $scope = $wsSession->getScopeArray();
        $siteProfileManual = \is_array($scope['site_profile_manual'] ?? null) ? $scope['site_profile_manual'] : [];
        $patch = [
            'handoff_workspace_public_id' => $wsSession->getPublicId(),
            'provider_handoff_mode' => 'pagebuilder_native_workspace',
        ];

        foreach ([
            'target_domain',
            'selected_domain',
            'preferred_registrar_account_id',
            'registrar_account_id',
            'recommended_registrar_label',
            'recommended_domain_list',
            'fake_mode',
            'site_ready',
            'domain_purchase_status',
            'domain_purchase_stage',
            'domain_purchase_stage_label',
            'domain_purchase_message',
            'domain_purchase_order_id',
        ] as $field) {
            if (\array_key_exists($field, $scope)) {
                $patch[$field] = $scope[$field];
            }
        }

        foreach ([
            'site_title',
            'site_tagline',
            'brief_description',
            'user_description',
            'default_locale',
            'plan_locale',
            'locales',
            'page_types',
            'recommended_pages',
        ] as $field) {
            if (\array_key_exists($field, $scope)) {
                $patch[$field] = $scope[$field];
            }
        }

        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale', 'plan_locale'] as $manualField) {
            if (!\array_key_exists($manualField, $patch)) {
                continue;
            }
            $value = $patch[$manualField];
            $siteProfileManual[$manualField] = \is_scalar($value)
                ? \trim((string)$value) !== ''
                : !empty($value);
        }

        if (\array_key_exists('page_types', $patch) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $patch)) {
            $patch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = !empty($patch['page_types']) ? 1 : 0;
        }
        if ($siteProfileManual !== []) {
            $patch['site_profile_manual'] = $siteProfileManual;
        }

        $this->sessionService->mergeScope($pbSession->getId(), $adminId, $patch);
    }

    /**
     * 返回 Websites 侧 AI 工作台 Session 服务。
     * 保持与原控制器一致的 ObjectManager 惰性解析行为，避免把 Websites 模块作为
     * 硬依赖引入本服务构造参数（Websites 在本仓库里属于"可选模块"语义）。
     */
    private function getWebsitesSessionService(): WebsitesSessionService
    {
        return ObjectManager::getInstance(WebsitesSessionService::class);
    }

    /**
     * 与 AiSiteAgent 控制器内同名私有方法一致的字符串列表归一化：
     *  - 数组原样迭代；
     *  - 字符串尝试 json_decode，否则按 `\r\n,;` 切分；
     *  - 非 scalar 元素丢弃；
     *  - 去重 + 去空。
     *
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        $values = [];
        if (\is_array($raw)) {
            $values = $raw;
        } elseif (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            $values = \is_array($decoded) ? $decoded : (\preg_split('/[\r\n,;]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $item = \trim((string)$value);
            if ($item === '' || \in_array($item, $normalized, true)) {
                continue;
            }
            $normalized[] = $item;
        }

        return $normalized;
    }
}
