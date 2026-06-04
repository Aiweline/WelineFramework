<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSitePlanDraft;
use Weline\Websites\Service\AiWorkbench\DomainPurchaseWorkbenchService;
use Weline\Websites\Service\AiWorkbench\EventStreamService;
use Weline\Websites\Service\AiWorkbench\MessageService;
use Weline\Websites\Service\AiWorkbench\PlanDraftService;
use Weline\Websites\Service\AiWorkbench\PlanGenerationService;
use Weline\Websites\Service\AiWorkbench\ProviderRegistry;
use Weline\Websites\Service\AiWorkbench\ProviderWorkbenchService;
use Weline\Websites\Service\AiWorkbench\SessionService;

#[Acl('Weline_Websites::site_builder_plan', 'AI Site Plan Flow', 'mdi mdi-sitemap', 'Draft-first AI site planning flow', 'Weline_Websites::site_builder_agent')]
class SiteBuilderPlan extends BackendController
{
    public function __construct(
        private readonly PlanDraftService $planDraftService,
        private readonly PlanGenerationService $planGenerationService,
        private readonly ProviderRegistry $providerRegistry,
        private readonly ProviderWorkbenchService $providerWorkbenchService,
        private readonly SessionService $sessionService,
        private readonly EventStreamService $eventStreamService,
        private readonly MessageService $messageService,
        private readonly DomainPurchaseWorkbenchService $domainPurchaseWorkbenchService,
        private readonly Url $url,
    ) {
    }

    #[Acl('Weline_Websites::site_builder_agent_create_session', 'Generate Draft Site Plan', 'mdi mdi-lightning-bolt-outline', 'Create or update a site planning draft', 'Weline_Websites::site_builder_agent')]
    public function postGenerate(): string
    {
        $adminId = $this->getAdminId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('Login required')]);
        }

        $description = \trim((string)$this->getRequestBodyValue('description', ''));
        $providerCode = \trim((string)$this->getRequestBodyValue('provider_code', 'pagebuilder'));
        $draftPublicId = \trim((string)$this->getRequestBodyValue('draft_public_id', ''));
        $references = $this->normalizeReferenceUrls($this->getRequestBodyValue('reference_urls', ''));
        $buildMode = $this->normalizeBuildMode((string)$this->getRequestBodyValue('build_mode', ''));

        if ($description === '' && $references === []) {
            return $this->fetchJson(['success' => false, 'message' => __('Please provide a site request or reference URL first')]);
        }

        $draft = $draftPublicId !== ''
            ? $this->planDraftService->loadByPublicId($draftPublicId, $adminId)
            : null;
        if ($draft === null) {
            $draft = $this->planDraftService->createDraft($adminId, $providerCode, [
                'initial_description' => $description,
                'description' => $description,
                'reference_urls' => $references,
                'chat_messages' => [],
                'fake_mode' => $this->isFakeModeRequested() ? 1 : 0,
            ], $buildMode);
        }

        $payload = $draft->getPayloadArray();
        $payload['initial_description'] = $payload['initial_description'] ?? $description;
        $payload['description'] = $description !== '' ? $description : (string)($payload['description'] ?? '');
        $payload['reference_urls'] = $references !== [] ? $references : $this->normalizeReferenceUrls($payload['reference_urls'] ?? []);
        $payload['pending_plan_message'] = $description !== '' ? $description : (string)($payload['description'] ?? '');
        $payload['pending_plan_action'] = 'generate';
        $payload['build_mode'] = $buildMode;
        $payload['fake_mode'] = $this->isFakeModeRequested() ? 1 : (int)($payload['fake_mode'] ?? 0);
        $payload['chat_messages'] = $this->appendDraftMessage($payload['chat_messages'] ?? [], 'user', $description);
        $this->planDraftService->savePayload($draft->getId(), $adminId, $payload);

        return $this->fetchJson([
            'success' => true,
            'draft_public_id' => $draft->getPublicId(),
            'stream_url' => $this->url->getBackendUrl('websites/backend/site-builder-plan/stream-sse', ['draft_public_id' => $draft->getPublicId()]),
            'draft' => $this->planDraftService->buildDraftView($draft->getPublicId(), $adminId),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_create_session', 'Revise Draft Site Plan', 'mdi mdi-comment-edit-outline', 'Revise an existing site planning draft', 'Weline_Websites::site_builder_agent')]
    public function postRevise(): string
    {
        $adminId = $this->getAdminId();
        $draftPublicId = \trim((string)$this->getRequestBodyValue('draft_public_id', ''));
        $message = \trim((string)$this->getRequestBodyValue('message', ''));
        $references = $this->normalizeReferenceUrls($this->getRequestBodyValue('reference_urls', ''));

        if ($adminId <= 0 || $draftPublicId === '' || $message === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid draft revision payload')]);
        }

        $draft = $this->planDraftService->loadByPublicId($draftPublicId, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->fetchJson(['success' => false, 'message' => __('Plan draft not found')]);
        }

        $payload = $draft->getPayloadArray();
        if ($references !== []) {
            $payload['reference_urls'] = $references;
        }
        $payload['pending_plan_message'] = $message;
        $payload['pending_plan_action'] = 'revise';
        $payload['chat_messages'] = $this->appendDraftMessage($payload['chat_messages'] ?? [], 'user', $message);
        $this->planDraftService->savePayload($draft->getId(), $adminId, $payload);

        return $this->fetchJson([
            'success' => true,
            'draft_public_id' => $draft->getPublicId(),
            'stream_url' => $this->url->getBackendUrl('websites/backend/site-builder-plan/stream-sse', ['draft_public_id' => $draft->getPublicId()]),
            'draft' => $this->planDraftService->buildDraftView($draft->getPublicId(), $adminId),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_stream', 'Stream Draft Site Plan', 'mdi mdi-access-point', 'Stream site plan generation', 'Weline_Websites::site_builder_agent')]
    public function getStream(): void
    {
        $this->handleStreamSse();
    }

    #[Acl('Weline_Websites::site_builder_agent_stream', 'Stream Draft Site Plan SSE', 'mdi mdi-access-point-network', 'Stream site plan generation via SSE endpoint', 'Weline_Websites::site_builder_agent')]
    public function getStreamSse(): void
    {
        $this->handleStreamSse();
    }

    private function handleStreamSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->start();

        $adminId = $this->getAdminId();
        $draftPublicId = \trim((string)$this->request->getGet('draft_public_id', ''));
        if ($adminId <= 0 || $draftPublicId === '') {
            $sse->sendError((string)__('Invalid plan stream parameters'));
            $sse->complete(['success' => false]);
            return;
        }

        $draft = $this->planDraftService->loadByPublicId($draftPublicId, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            $sse->sendError((string)__('Plan draft not found'));
            $sse->complete(['success' => false]);
            return;
        }

        $payload = $draft->getPayloadArray();
        $pendingMessage = \trim((string)($payload['pending_plan_message'] ?? $payload['description'] ?? ''));
        $sse->sendEvent('start', [
            'message' => (string)__('Plan generation started'),
            'draft_public_id' => $draft->getPublicId(),
        ]);
        if (\is_array($payload['current_plan'] ?? null)) {
            $sse->sendEvent('snapshot', [
                'message' => (string)__('Loaded current draft plan'),
                'plan' => $payload['current_plan'],
                'draft_public_id' => $draft->getPublicId(),
            ]);
        }

        try {
            $plan = $this->planGenerationService->generatePlan(
                $payload,
                $pendingMessage,
                static function (string $eventType, array $data) use ($sse): void {
                    if ($sse->isAlive()) {
                        $sse->sendEvent($eventType, $data);
                    }
                }
            );
        } catch (\Throwable $throwable) {
            $message = (string)$throwable->getMessage();
            $sse->sendError($message !== '' ? $message : (string)__('Plan generation failed'));
            $sse->complete([
                'success' => false,
                'draft_public_id' => $draft->getPublicId(),
                'message' => $message,
            ]);
            return;
        }

        $version = $this->planDraftService->appendPlanVersion(
            $draft->getId(),
            $adminId,
            $plan,
            (string)($payload['pending_plan_action'] ?? 'generate'),
            $pendingMessage
        );

        $latestPayload = $draft->getPayloadArray();
        $latestPayload['pending_plan_message'] = '';
        $latestPayload['pending_plan_action'] = '';
        $latestPayload['current_plan'] = $plan;
        $latestPayload['build_mode'] = (string)($plan['build_mode'] ?? $draft->getBuildMode());
        $latestPayload['chat_messages'] = $this->appendDraftMessage(
            $latestPayload['chat_messages'] ?? [],
            'assistant',
            (string)($plan['plan_markdown'] ?? '')
        );
        $this->planDraftService->savePayload($draft->getId(), $adminId, $latestPayload);

        $sse->sendEvent('done', [
            'success' => true,
            'message' => (string)__('Plan generation finished'),
            'draft_public_id' => $draft->getPublicId(),
            'version_id' => $version?->getId() ?? 0,
            'plan' => $plan,
            'draft' => $this->planDraftService->buildDraftView($draft->getPublicId(), $adminId),
        ]);
        $sse->complete([
            'success' => true,
            'draft_public_id' => $draft->getPublicId(),
            'plan' => $plan,
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_set_stage', 'Confirm Draft Site Plan', 'mdi mdi-check-circle-outline', 'Confirm the latest generated plan draft', 'Weline_Websites::site_builder_agent')]
    public function postConfirm(): string
    {
        $adminId = $this->getAdminId();
        $draftPublicId = \trim((string)$this->getRequestBodyValue('draft_public_id', ''));
        if ($adminId <= 0 || $draftPublicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid plan confirmation request')]);
        }

        $draft = $this->planDraftService->loadByPublicId($draftPublicId, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->fetchJson(['success' => false, 'message' => __('Plan draft not found')]);
        }

        if (!$this->planDraftService->confirmDraft($draft->getId(), $adminId)) {
            return $this->fetchJson(['success' => false, 'message' => __('Please generate a plan before confirming it')]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('Plan confirmed'),
            'draft' => $this->planDraftService->buildDraftView($draft->getPublicId(), $adminId),
        ]);
    }

    #[Acl('Weline_Websites::domain_pool_api_list', 'List Local Pool Domains For Draft', 'mdi mdi-database-search-outline', 'List local domain pool options for the site plan draft', 'Weline_Websites::site_builder_agent')]
    public function getLocalPool(): string
    {
        $adminId = $this->getAdminId();
        $draftPublicId = \trim((string)$this->request->getGet('draft_public_id', ''));
        $search = \trim((string)$this->request->getGet('search', ''));
        $limit = (int)$this->request->getGet('limit', 50);

        if ($adminId <= 0 || $draftPublicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid local pool request')]);
        }

        $draft = $this->planDraftService->loadByPublicId($draftPublicId, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->fetchJson(['success' => false, 'message' => __('Plan draft not found')]);
        }

        return $this->fetchJson([
            'success' => true,
            'items' => $this->planDraftService->listAvailableLocalPoolDomains($draft->getId(), $adminId, $search, $limit),
        ]);
    }

    #[Acl('Weline_Websites::domain_pool_api_list', 'Reserve Local Pool Domain', 'mdi mdi-lock-outline', 'Reserve a local pool domain for the site plan draft', 'Weline_Websites::site_builder_agent')]
    public function postReserveLocalPool(): string
    {
        $adminId = $this->getAdminId();
        $draftPublicId = \trim((string)$this->getRequestBodyValue('draft_public_id', ''));
        $poolId = (int)$this->getRequestBodyValue('pool_id', 0);
        $accountId = (int)$this->getRequestBodyValue('account_id', 0);

        if ($adminId <= 0 || $draftPublicId === '' || $poolId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid local pool reserve request')]);
        }

        $draft = $this->planDraftService->loadByPublicId($draftPublicId, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->fetchJson(['success' => false, 'message' => __('Plan draft not found')]);
        }

        $result = $this->planDraftService->reserveLocalPoolDomain($draft->getId(), $adminId, $poolId, $accountId);
        if (empty($result['success'])) {
            return $this->fetchJson($result);
        }

        return $this->fetchJson(\array_replace($result, [
            'draft' => $this->planDraftService->buildDraftView($draft->getPublicId(), $adminId),
        ]));
    }

    #[Acl('Weline_Websites::site_builder_agent_check_domain', 'Select Draft Domain', 'mdi mdi-domain', 'Persist a domain choice on the current site plan draft', 'Weline_Websites::site_builder_agent')]
    public function postSelectDomain(): string
    {
        $adminId = $this->getAdminId();
        $draftPublicId = \trim((string)$this->getRequestBodyValue('draft_public_id', ''));
        $domain = \strtolower(\trim((string)$this->getRequestBodyValue('domain', '')));
        $domainSource = \trim((string)$this->getRequestBodyValue('domain_source', ''));
        $accountId = (int)$this->getRequestBodyValue('account_id', 0);

        if ($adminId <= 0 || $draftPublicId === '' || $domain === '' || $domainSource === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid domain selection payload')]);
        }

        $draft = $this->planDraftService->loadByPublicId($draftPublicId, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->fetchJson(['success' => false, 'message' => __('Plan draft not found')]);
        }

        $siteReady = $domainSource === AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL ? 1 : 0;
        $this->planDraftService->bindDomainSelection(
            $draft->getId(),
            $adminId,
            $domain,
            $domainSource,
            0,
            $accountId,
            ['site_ready' => $siteReady]
        );

        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('Domain selected: %{domain}', ['domain' => $domain]),
            'draft' => $this->planDraftService->buildDraftView($draft->getPublicId(), $adminId),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_create_session', 'Create Session From Draft', 'mdi mdi-rocket-launch-outline', 'Create the formal site builder session from a confirmed draft', 'Weline_Websites::site_builder_agent')]
    public function postCreateSession(): string
    {
        $adminId = $this->getAdminId();
        $draftPublicId = \trim((string)$this->getRequestBodyValue('draft_public_id', ''));
        if ($adminId <= 0 || $draftPublicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid session creation payload')]);
        }

        $draft = $this->planDraftService->loadByPublicId($draftPublicId, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->fetchJson(['success' => false, 'message' => __('Plan draft not found')]);
        }
        if ($draft->getStatus() !== AiSitePlanDraft::STATUS_CONFIRMED) {
            return $this->fetchJson(['success' => false, 'message' => __('Please confirm the plan before creating a workspace session')]);
        }
        if ($draft->getSelectedDomain() === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Please select a domain before creating a workspace session')]);
        }

        $providerCode = $draft->getProviderCode() !== '' ? $draft->getProviderCode() : 'pagebuilder';
        $provider = $this->providerRegistry->getProvider($providerCode);
        if ($provider === null || !$provider->isEnabled()) {
            return $this->fetchJson(['success' => false, 'message' => __('Unknown or disabled provider: %{code}', ['code' => $providerCode])]);
        }

        $payload = $draft->getPayloadArray();
        $scope = $this->buildSessionScopeFromDraft($draft, $payload);
        $providerConfig = $this->providerWorkbenchService->buildWorkbenchConfig(
            $providerCode,
            $adminId,
            null,
            $scope,
            [
                'draft' => [
                    'public_id' => $draft->getPublicId(),
                    'current_version_id' => $draft->getCurrentVersionId(),
                ],
            ],
            [
                'source' => 'site_builder_plan_draft',
                'draft_public_id' => $draft->getPublicId(),
            ]
        );

        try {
            $session = $this->sessionService->createSession(
                $providerCode,
                $adminId,
                \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [],
                \is_array($providerConfig['provider_state'] ?? null) ? $providerConfig['provider_state'] : [],
                (string)($providerConfig['initial_stage'] ?? 'prepare')
            );
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => __('Failed to create workspace session: %{message}', ['message' => $throwable->getMessage()])]);
        }

        $this->sessionService->bindDomain($session->getId(), $adminId, $draft->getSelectedDomain(), $draft->getRegistrarAccountId());
        $this->eventStreamService->appendEvent(
            $session->getId(),
            $adminId,
            (string)($providerConfig['initial_stage'] ?? 'prepare'),
            'session_created_from_draft',
            [
                'draft_public_id' => $draft->getPublicId(),
                'provider_code' => $providerCode,
                'selected_domain' => $draft->getSelectedDomain(),
                'selected_domain_source' => $draft->getSelectedDomainSource(),
            ],
            AiSiteBuilderEvent::LEVEL_INFO
        );
        $description = \trim((string)($payload['description'] ?? $payload['initial_description'] ?? ''));
        if ($description !== '') {
            $this->messageService->appendMessage($session->getId(), $adminId, 'user', $description, 'brief');
        }
        if (\is_array($payload['current_plan'] ?? null) && \trim((string)($payload['current_plan']['plan_markdown'] ?? '')) !== '') {
            $this->messageService->appendMessage(
                $session->getId(),
                $adminId,
                'assistant',
                (string)$payload['current_plan']['plan_markdown'],
                'plan'
            );
        }

        if ($draft->getSelectedDomainSource() !== AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL) {
            $this->domainPurchaseWorkbenchService->queuePurchase($session->getId(), $adminId, [
                'target_domain' => $draft->getSelectedDomain(),
                'selected_domain' => $draft->getSelectedDomain(),
                'selected_domain_source' => $draft->getSelectedDomainSource(),
                'selected_pool_id' => $draft->getSelectedPoolId(),
                'preferred_registrar_account_id' => $draft->getRegistrarAccountId(),
                'registrar_account_id' => $draft->getRegistrarAccountId(),
                'site_ready' => 0,
                'fake_mode' => !empty($payload['fake_mode']) ? 1 : 0,
            ]);
        }

        $this->planDraftService->markConverted($draft->getId(), $adminId);
        $workspaceUrl = $providerCode === 'pagebuilder'
            ? $this->url->getBackendUrl('websites/backend/site-builder-agent/pagebuilder-handoff', ['public_id' => $session->getPublicId()])
            : $this->url->getBackendUrl('websites/backend/site-builder-agent/workspace', ['public_id' => $session->getPublicId()]);

        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('Workspace session created'),
            'public_id' => $session->getPublicId(),
            'workspace_url' => $workspaceUrl,
            'linked_domain_source' => $draft->getSelectedDomainSource(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildSessionScopeFromDraft(AiSitePlanDraft $draft, array $payload): array
    {
        $plan = \is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : [];
        $buildMode = $this->normalizeBuildMode((string)($plan['build_mode'] ?? $draft->getBuildMode()));
        $workspaceTrack = $buildMode === 'pagebuilder_html' ? 'html_blocks' : 'virtual_theme';
        $siteReady = $draft->getSelectedDomainSource() === AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL ? 1 : 0;

        $scope = [
            'created_from' => 'site_builder_plan_draft',
            'provider_code' => $draft->getProviderCode(),
            'plan_draft_public_id' => $draft->getPublicId(),
            'plan_version_id' => $draft->getCurrentVersionId(),
            'confirmed_plan' => 1,
            'user_description' => (string)($payload['description'] ?? $payload['initial_description'] ?? ''),
            'brief_description' => (string)($plan['brief_description'] ?? $payload['description'] ?? ''),
            'site_title' => (string)($plan['site_title'] ?? ''),
            'site_tagline' => (string)($plan['site_tagline'] ?? ''),
            'build_mode' => $buildMode,
            'workspace_track' => $workspaceTrack,
            'page_types' => \is_array($plan['page_types'] ?? null) ? $plan['page_types'] : ['home_page', 'about_page', 'contact_page'],
            'page_types_user_customized' => 1,
            'seo_keywords' => \is_array($plan['seo_keywords'] ?? null) ? $plan['seo_keywords'] : [],
            'site_plan' => $plan,
            'reference_urls' => \is_array($payload['reference_urls'] ?? null) ? $payload['reference_urls'] : [],
            'selected_domain' => $draft->getSelectedDomain(),
            'target_domain' => $draft->getSelectedDomain(),
            'selected_domain_source' => $draft->getSelectedDomainSource(),
            'selected_pool_id' => $draft->getSelectedPoolId(),
            'preferred_registrar_account_id' => $draft->getRegistrarAccountId(),
            'registrar_account_id' => $draft->getRegistrarAccountId(),
            'site_ready' => $siteReady,
            'fake_mode' => !empty($payload['fake_mode']) ? 1 : 0,
        ];

        return $this->attachPageBuilderStageOneRouteContract($scope, $plan);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function attachPageBuilderStageOneRouteContract(array $scope, array $plan): array
    {
        if ((string)($scope['provider_code'] ?? '') !== 'pagebuilder') {
            return $scope;
        }

        $contractServiceClass = 'GuoLaiRen\\PageBuilder\\Service\\AiSiteStageOneContractService';
        if (!\class_exists($contractServiceClass)) {
            return $scope;
        }

        $pageTypes = $this->normalizeStringList($scope['page_types'] ?? $plan['page_types'] ?? []);
        if ($pageTypes === []) {
            return $scope;
        }

        $contentLocale = \trim((string)($scope['content_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $contentLocale));

        try {
            $contractService = ObjectManager::getInstance($contractServiceClass);
            if (!\is_object($contractService) || !\method_exists($contractService, 'build')) {
                return $scope;
            }
            $stageOneContract = $contractService->build(
                $scope,
                $pageTypes,
                $planLocale,
                $contentLocale,
                'site_builder_plan_draft'
            );
        } catch (\Throwable) {
            return $scope;
        }

        if (!\is_array($stageOneContract)) {
            return $scope;
        }

        if (\is_array($stageOneContract['page_route_contract'] ?? null)) {
            $scope['page_route_contract'] = $stageOneContract['page_route_contract'];
            $scope['site_plan'] = \is_array($scope['site_plan'] ?? null) ? $scope['site_plan'] : $plan;
            $scope['site_plan']['page_route_contract'] = $stageOneContract['page_route_contract'];
        }
        if (\is_array($stageOneContract['navigation_address_rules'] ?? null)) {
            $scope['navigation_address_rules'] = $stageOneContract['navigation_address_rules'];
        }

        return $scope;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $value = \trim((string)$item);
            if ($value !== '' && !\in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeReferenceUrls(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[\r\n,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        } else {
            $items = [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $value = \trim((string)$item);
            if ($value === '' || \in_array($value, $result, true)) {
                continue;
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param mixed $raw
     * @return list<array{role:string,content:string}>
     */
    private function appendDraftMessage(mixed $raw, string $role, string $content): array
    {
        $messages = [];
        if (\is_array($raw)) {
            foreach ($raw as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $msgRole = \trim((string)($item['role'] ?? 'user'));
                $msgContent = \trim((string)($item['content'] ?? ''));
                if ($msgContent === '') {
                    continue;
                }
                $messages[] = [
                    'role' => $msgRole !== '' ? $msgRole : 'user',
                    'content' => $msgContent,
                ];
            }
        }

        $content = \trim($content);
        if ($content !== '') {
            $messages[] = [
                'role' => \trim($role) !== '' ? \trim($role) : 'user',
                'content' => $content,
            ];
        }

        return $messages;
    }

    private function normalizeBuildMode(string $buildMode): string
    {
        $buildMode = \trim($buildMode);

        return $buildMode === 'pagebuilder_html' ? 'pagebuilder_html' : 'pagebuilder_style';
    }

    private function getRequestBodyValue(string $key, mixed $default = null): mixed
    {
        $value = $this->request->getPost($key, null);
        if ($value !== null) {
            return $value;
        }

        return $this->request->getParam($key, $default);
    }

    private function getAdminId(): int
    {
        return (int)$this->getLoginUserId();
    }

    private function isFakeModeRequested(): bool
    {
        $queryValue = $this->request->getGet('fake_mode', null);
        if ($queryValue !== null) {
            return $this->isTruthyFlag($queryValue);
        }

        return $this->isTruthyFlag($this->getRequestBodyValue('fake_mode', false));
    }

    private function isTruthyFlag(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (\is_string($value)) {
            return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
