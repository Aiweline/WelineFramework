<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Websites\Model\AiSiteBuilderSession as WebsitesAiSiteBuilderSession;
use Weline\Websites\Service\AiWorkbench\DomainPurchaseWorkbenchService as WebsitesDomainPurchaseWorkbenchService;
use Weline\Websites\Service\AiWorkbench\SessionService as WebsitesSessionService;

final class AiSiteAgentWorkspaceBridgeService
{
    public function __construct(
        private readonly AiSiteAgentSessionService $sessionService,
        private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService,
        private readonly Url $url,
        private readonly FrameworkQueryService $frameworkQueryService,
        private readonly WebsitesSessionService $websitesSessionService,
        private readonly WebsitesDomainPurchaseWorkbenchService $domainPurchaseWorkbenchService,
    ) {
    }

    /**
     * @return list<array{account_id:int,label:string,registrar_name:string,registrar_code:string,account_name:string}>
     */
    public function buildRegistrarAccountOptions(): array
    {
        $rows = $this->frameworkQueryService->execute('websites', 'getRegistrarAccounts', ['status' => 'active']);
        return $this->buildRegistrarAccountOptionsFromRows(\is_array($rows) ? $rows : [], \defined('DEV') && DEV);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array{account_id:int,label:string,registrar_name:string,registrar_code:string,account_name:string}>
     */
    public function buildRegistrarAccountOptionsFromRows(array $rows, bool $includeLocalDemo = false): array
    {
        $options = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $accountId = (int)($row['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }

            $registrarName = \trim((string)($row['registrar_name'] ?? $row['registrar_code'] ?? ''));
            $accountName = \trim((string)($row['account_name'] ?? ''));
            $label = $registrarName !== ''
                ? $registrarName . ($accountName !== '' ? (' - ' . $accountName) : '')
                : ($accountName !== '' ? $accountName : (string)__('未命名账号'));

            $options[] = [
                'account_id' => $accountId,
                'label' => $label,
                'registrar_name' => $registrarName,
                'registrar_code' => (string)($row['registrar_code'] ?? ''),
                'account_name' => $accountName,
            ];
        }

        if (!$includeLocalDemo) {
            return $options;
        }

        foreach ($options as $option) {
            if ((int)($option['account_id'] ?? 0) === 900001 || (string)($option['registrar_code'] ?? '') === 'local_demo') {
                return $options;
            }
        }

        \array_unshift($options, [
            'account_id' => 900001,
            'label' => (string)__('本地供应商 - 本地默认账号'),
            'registrar_name' => (string)__('本地供应商'),
            'registrar_code' => 'local_demo',
            'account_name' => (string)__('本地默认账号'),
        ]);

        return $options;
    }

    /**
     * @param array<string, mixed> $linkedScope
     * @param array<string, mixed> $viewScope
     * @param list<array{account_id:int,label:string,registrar_name:string,registrar_code:string,account_name:string}> $registrarAccounts
     * @return array{
     *   recommended_domain_list:list<string>,
     *   recommended_registrar_label:string,
     *   preferred_registrar_account_id:int
     * }
     */
    public function buildWorkspaceRegistrarSelection(
        array $linkedScope,
        array $viewScope,
        array $registrarAccounts,
        bool $isDevMode
    ): array {
        $recommendedDomainList = $this->normalizeStringList($linkedScope['recommended_domain_list'] ?? []);
        if ($recommendedDomainList === []) {
            $recommendedDomainList = $this->normalizeStringList($viewScope['recommended_domain_list'] ?? []);
        }

        $recommendedRegistrarLabel = \trim((string)($linkedScope['recommended_registrar_label'] ?? $viewScope['recommended_registrar_label'] ?? ''));
        $preferredRegistrarAccountId = (int)($linkedScope['preferred_registrar_account_id'] ?? $linkedScope['registrar_account_id'] ?? $viewScope['preferred_registrar_account_id'] ?? $viewScope['registrar_account_id'] ?? 0);

        if ($isDevMode) {
            foreach ($registrarAccounts as $account) {
                if ((int)($account['account_id'] ?? 0) !== 900001) {
                    continue;
                }
                $preferredRegistrarAccountId = 900001;
                break;
            }
        } elseif ($preferredRegistrarAccountId <= 0) {
            $preferredRegistrarAccountId = 0;
        }

        if ($recommendedRegistrarLabel === '' && $preferredRegistrarAccountId > 0) {
            foreach ($registrarAccounts as $account) {
                if ((int)($account['account_id'] ?? 0) !== $preferredRegistrarAccountId) {
                    continue;
                }
                $recommendedRegistrarLabel = \trim((string)($account['label'] ?? ''));
                break;
            }
        }

        return [
            'recommended_domain_list' => $recommendedDomainList,
            'recommended_registrar_label' => $recommendedRegistrarLabel,
            'preferred_registrar_account_id' => $preferredRegistrarAccountId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDomainPurchaseState(WebsitesAiSiteBuilderSession $session): array
    {
        $state = $this->domainPurchaseWorkbenchService->buildViewState($session);
        return \is_array($state) ? $state : [];
    }

    public function ensureLinkedWebsitesMirrorSession(AiSiteAgentSession $session, int $adminId): ?WebsitesAiSiteBuilderSession
    {
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScope($session)
        );
        $linkedPublicId = \trim((string)($scope['handoff_workspace_public_id'] ?? ''));
        $linkedSession = $linkedPublicId !== ''
            ? $this->websitesSessionService->loadByPublicId($linkedPublicId, $adminId)
            : null;
        $linkedScope = $this->buildLinkedWebsitesScopeFromPageBuilderSession($session);

        if (!$linkedSession instanceof WebsitesAiSiteBuilderSession) {
            $linkedSession = $this->websitesSessionService->createSession('pagebuilder', $adminId, $linkedScope, [], 'prepare');
            $this->sessionService->mergeScope($session->getId(), $adminId, [
                'handoff_workspace_public_id' => $linkedSession->getPublicId(),
                'provider_handoff_mode' => 'pagebuilder_native_workspace',
            ]);

            return $linkedSession;
        }

        $this->websitesSessionService->mergeScope($linkedSession->getId(), $adminId, $linkedScope);

        return $this->websitesSessionService->loadById($linkedSession->getId(), $adminId) ?? $linkedSession;
    }

    public function syncPageBuilderScopeFromLinkedWebsitesSession(
        AiSiteAgentSession $pageBuilderSession,
        WebsitesAiSiteBuilderSession $websitesSession,
        int $adminId
    ): void {
        $scope = $websitesSession->getScopeArray();
        $siteProfileManual = \is_array($scope['site_profile_manual'] ?? null) ? $scope['site_profile_manual'] : [];
        $patch = [
            'handoff_workspace_public_id' => $websitesSession->getPublicId(),
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

        $this->sessionService->mergeScope($pageBuilderSession->getId(), $adminId, $patch);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildLinkedWebsitesScopeFromPageBuilderSession(AiSiteAgentSession $session): array
    {
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScope($session)
        );
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
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $normalized = \trim((string)$item);
            if ($normalized === '') {
                continue;
            }
            $items[] = $normalized;
        }

        return \array_values(\array_unique($items));
    }
}
