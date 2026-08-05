<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Http\Url;
use Weline\Framework\Session\SessionFactory;
use Weline\Websites\Model\AiSitePlanDraft;
use Weline\Websites\Service\WebsiteAgentService;

final class SiteBuilderWorkbenchQueryHandler
{
    public const COMMANDS = [
        'preparePlan',
        'preparePlanRevision',
        'completePlan',
        'confirmPlan',
        'listLocalPool',
        'reserveLocalPool',
        'selectDomain',
        'createSession',
        'recommendDomain',
        'checkDomain',
        'mergeScope',
        'replaceScope',
        'setStage',
        'deleteSession',
        'saveVirtualTheme',
        'savePageTypeLayout',
        'saveVirtualComponent',
        'appendMessage',
        'startDomainPurchase',
        'domainPurchaseStatus',
        'workspaceEvents',
        'generateVirtualTheme',
    ];

    public function __construct(
        private readonly PlanDraftService $drafts,
        private readonly PlanGenerationService $plans,
        private readonly ProviderWorkbenchService $providerWorkbench,
        private readonly SessionService $sessions,
        private readonly EventStreamService $events,
        private readonly MessageService $messages,
        private readonly VirtualThemeWorkbenchService $virtualThemes,
        private readonly DomainPurchaseWorkbenchService $domainPurchases,
        private readonly WebsiteAgentService $websiteAgent,
        private readonly Url $url,
    ) {
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function execute(array $params): array
    {
        $command = trim((string)($params['command'] ?? ''));
        if (!in_array($command, self::COMMANDS, true)) {
            throw new \InvalidArgumentException((string)__('未知 Site Builder 工作台命令：%{1}', [$command]));
        }
        $payload = is_array($params['payload'] ?? null) ? $params['payload'] : [];
        foreach (['url', 'method', 'headers'] as $transportKey) {
            if (array_key_exists($transportKey, $params) || array_key_exists($transportKey, $payload)) {
                throw new \InvalidArgumentException((string)__('Site Builder 工作台不接受通用传输参数'));
            }
        }
        $adminId = (int)SessionFactory::getInstance()->createBackendSession()->getUserId();
        if ($adminId <= 0) {
            return $this->failure((string)__('需要登录'));
        }

        try {
            return match ($command) {
                'preparePlan' => $this->preparePlan($payload, $adminId, false),
                'preparePlanRevision' => $this->preparePlan($payload, $adminId, true),
                'completePlan' => $this->completePlan($payload, $adminId),
                'confirmPlan' => $this->confirmPlan($payload, $adminId),
                'listLocalPool' => $this->listLocalPool($payload, $adminId),
                'reserveLocalPool' => $this->reserveLocalPool($payload, $adminId),
                'selectDomain' => $this->selectDomain($payload, $adminId),
                'createSession' => $this->createSession($payload, $adminId),
                'recommendDomain' => $this->recommendDomain($payload),
                'checkDomain' => $this->checkDomain($payload),
                'mergeScope' => $this->mutateScope($payload, $adminId, true),
                'replaceScope' => $this->mutateScope($payload, $adminId, false),
                'setStage' => $this->setStage($payload, $adminId),
                'deleteSession' => $this->deleteSession($payload, $adminId),
                'saveVirtualTheme' => $this->saveVirtualTheme($payload, $adminId),
                'savePageTypeLayout' => $this->savePageTypeLayout($payload, $adminId),
                'saveVirtualComponent' => $this->saveVirtualComponent($payload, $adminId),
                'appendMessage' => $this->appendMessage($payload, $adminId),
                'startDomainPurchase' => $this->startDomainPurchase($payload, $adminId),
                'domainPurchaseStatus' => $this->domainPurchaseStatus($payload, $adminId),
                'workspaceEvents' => $this->workspaceEvents($payload, $adminId),
                'generateVirtualTheme' => $this->generateVirtualTheme($payload, $adminId),
            };
        } catch (\Throwable $throwable) {
            return $this->failure(trim($throwable->getMessage()) ?: (string)__('Site Builder 工作台操作失败'));
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function preparePlan(array $input, int $adminId, bool $revision): array
    {
        $publicId = trim((string)($input['draft_public_id'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $message = trim((string)($input['message'] ?? $description));
        $references = $this->stringList($input['reference_urls'] ?? []);
        $draft = $publicId !== '' ? $this->drafts->loadByPublicId($publicId, $adminId) : null;
        if ($revision && (!$draft instanceof AiSitePlanDraft || $message === '')) {
            return $this->failure((string)__('方案修订参数无效'));
        }
        if (!$draft instanceof AiSitePlanDraft) {
            if ($description === '' && $references === []) {
                return $this->failure((string)__('请先填写建站需求或参考地址'));
            }
            $draft = $this->drafts->createDraft(
                $adminId,
                trim((string)($input['provider_code'] ?? 'websites_default')) ?: 'websites_default',
                [
                    'initial_description' => $description,
                    'description' => $description,
                    'reference_urls' => $references,
                    'chat_messages' => [],
                    'fake_mode' => $this->boolValue($input['fake_mode'] ?? false) ? 1 : 0,
                ],
                trim((string)($input['build_mode'] ?? '')),
            );
        }
        $payload = $draft->getPayloadArray();
        if ($description !== '') {
            $payload['description'] = $description;
            $payload['initial_description'] = $payload['initial_description'] ?? $description;
        }
        if ($references !== []) {
            $payload['reference_urls'] = $references;
        }
        foreach (['content_locale', 'plan_locale'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }
        $languages = $this->stringList($input['language_codes'] ?? []);
        if ($languages !== []) {
            $payload['language_codes'] = $languages;
        }
        $payload['pending_plan_action'] = $revision ? 'revise' : 'generate';
        $payload['pending_plan_message'] = $message;
        if ($this->boolValue($input['fake_mode'] ?? false)) {
            $payload['fake_mode'] = 1;
        }
        $this->drafts->savePayload($draft->getId(), $adminId, $payload);

        return [
            'success' => true,
            'draft_public_id' => $draft->getPublicId(),
            'stream_url' => '/websites/backend/site-builder-plan/stream-sse?draft_public_id=' . rawurlencode($draft->getPublicId()),
            'draft' => $this->drafts->buildDraftView($draft->getPublicId(), $adminId),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function completePlan(array $input, int $adminId): array
    {
        $publicId = trim((string)($input['draft_public_id'] ?? ''));
        $draft = $publicId !== '' ? $this->drafts->loadByPublicId($publicId, $adminId) : null;
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->failure((string)__('方案草稿不存在'));
        }
        $payload = $draft->getPayloadArray();
        if ($this->boolValue($input['fake_mode'] ?? false)) {
            $payload['fake_mode'] = 1;
        }
        $message = trim((string)($payload['pending_plan_message'] ?? $payload['description'] ?? ''));
        $events = [['event' => 'start', 'data' => ['message' => (string)__('开始生成方案')]]];
        $plan = $this->plans->generatePlan(
            $payload,
            $message,
            static function (string $event, array $data) use (&$events): void {
                $events[] = ['event' => $event, 'data' => $data];
            },
        );
        $version = $this->drafts->appendPlanVersion(
            $draft->getId(),
            $adminId,
            $plan,
            (string)($payload['pending_plan_action'] ?? 'generate'),
            $message,
        );
        if ($version === null || $version->getId() <= 0) {
            return $this->failure((string)__('方案未能持久化'));
        }
        $fresh = $this->drafts->loadByPublicId($publicId, $adminId);
        $latest = $fresh instanceof AiSitePlanDraft ? $fresh->getPayloadArray() : $payload;
        $latest['pending_plan_message'] = '';
        $latest['pending_plan_action'] = '';
        $latest['current_plan'] = $plan;
        $latest['current_plan_version_id'] = $version->getId();
        $this->drafts->savePayload($draft->getId(), $adminId, $latest);
        $result = [
            'success' => true,
            'draft_public_id' => $publicId,
            'version_id' => $version->getId(),
            'plan' => $plan,
            'draft' => $this->drafts->buildDraftView($publicId, $adminId),
        ];
        $events[] = ['event' => 'plan_completed', 'data' => ['plan' => $plan]];
        $events[] = ['event' => 'done', 'data' => $result];
        $result['events'] = $events;
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function confirmPlan(array $input, int $adminId): array
    {
        $draft = $this->loadDraft($input, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->failure((string)__('方案草稿不存在'));
        }
        if (!$this->drafts->confirmDraft($draft->getId(), $adminId)) {
            return $this->failure((string)__('请先生成方案'));
        }
        return ['success' => true, 'message' => (string)__('方案已确认'), 'draft' => $this->drafts->buildDraftView($draft->getPublicId(), $adminId)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function listLocalPool(array $input, int $adminId): array
    {
        $draft = $this->loadDraft($input, $adminId);
        if (!$draft instanceof AiSitePlanDraft) {
            return $this->failure((string)__('方案草稿不存在'));
        }
        return [
            'success' => true,
            'items' => $this->drafts->listAvailableLocalPoolDomains(
                $draft->getId(),
                $adminId,
                trim((string)($input['search'] ?? '')),
                max(1, min(100, (int)($input['limit'] ?? 50))),
            ),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function reserveLocalPool(array $input, int $adminId): array
    {
        $draft = $this->loadDraft($input, $adminId);
        $poolId = (int)($input['pool_id'] ?? 0);
        if (!$draft instanceof AiSitePlanDraft || $poolId <= 0) {
            return $this->failure((string)__('本地域名池参数无效'));
        }
        return $this->drafts->reserveLocalPoolDomain($draft->getId(), $adminId, $poolId, (int)($input['account_id'] ?? 0));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function selectDomain(array $input, int $adminId): array
    {
        $draft = $this->loadDraft($input, $adminId);
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        $source = trim((string)($input['domain_source'] ?? ''));
        if (!$draft instanceof AiSitePlanDraft || $domain === '' || $source === '') {
            return $this->failure((string)__('域名选择参数无效'));
        }
        $this->drafts->bindDomainSelection(
            $draft->getId(),
            $adminId,
            $domain,
            $source,
            0,
            (int)($input['account_id'] ?? 0),
            ['site_ready' => $source === AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL ? 1 : 0],
        );
        return ['success' => true, 'message' => (string)__('域名已保存'), 'draft' => $this->drafts->buildDraftView($draft->getPublicId(), $adminId)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function createSession(array $input, int $adminId): array
    {
        $draft = $this->loadDraft($input, $adminId);
        if (!$draft instanceof AiSitePlanDraft || $draft->getStatus() !== AiSitePlanDraft::STATUS_CONFIRMED || $draft->getSelectedDomain() === '') {
            return $this->failure((string)__('请先确认方案并选择域名'));
        }
        $payload = $draft->getPayloadArray();
        $plan = is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : [];
        $providerCode = trim($draft->getProviderCode()) ?: 'websites_default';
        $scope = [
            'created_from' => 'site_builder_plan_draft',
            'plan_draft_public_id' => $draft->getPublicId(),
            'plan_version_id' => $draft->getCurrentVersionId(),
            'confirmed_plan' => 1,
            'user_description' => (string)($payload['description'] ?? $payload['initial_description'] ?? ''),
            'brief_description' => (string)($plan['brief_description'] ?? $payload['description'] ?? ''),
            'site_title' => (string)($plan['site_title'] ?? ''),
            'site_tagline' => (string)($plan['site_tagline'] ?? ''),
            'build_mode' => (string)($plan['build_mode'] ?? $draft->getBuildMode()),
            'page_types' => is_array($plan['page_types'] ?? null) ? $plan['page_types'] : ['home_page', 'about_page', 'contact_page'],
            'site_plan' => $plan,
            'selected_domain' => $draft->getSelectedDomain(),
            'target_domain' => $draft->getSelectedDomain(),
            'selected_domain_source' => $draft->getSelectedDomainSource(),
            'registrar_account_id' => $draft->getRegistrarAccountId(),
            'fake_mode' => !empty($payload['fake_mode']) ? 1 : 0,
        ];
        $config = $this->providerWorkbench->buildWorkbenchConfig($providerCode, $adminId, null, $scope);
        $session = $this->sessions->createSession(
            $providerCode,
            $adminId,
            is_array($config['scope'] ?? null) ? $config['scope'] : $scope,
            is_array($config['provider_state'] ?? null) ? $config['provider_state'] : [],
            (string)($config['initial_stage'] ?? 'prepare'),
        );
        $this->sessions->bindDomain($session->getId(), $adminId, $draft->getSelectedDomain(), $draft->getRegistrarAccountId());
        $description = trim((string)($payload['description'] ?? ''));
        if ($description !== '') {
            $this->messages->appendMessage($session->getId(), $adminId, 'user', $description, 'brief');
        }
        if ($draft->getSelectedDomainSource() !== AiSitePlanDraft::DOMAIN_SOURCE_LOCAL_POOL) {
            $this->domainPurchases->queuePurchase($session->getId(), $adminId, $scope);
        }
        $this->drafts->markConverted($draft->getId(), $adminId);
        return [
            'success' => true,
            'message' => (string)__('工作区会话已创建'),
            'public_id' => $session->getPublicId(),
            'workspace_url' => $this->url->getBackendUrl('websites/backend/site-builder-agent/workspace', ['public_id' => $session->getPublicId()]),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function recommendDomain(array $input): array
    {
        $description = trim((string)($input['description'] ?? ''));
        $preferred = strtolower(trim((string)($input['domain'] ?? '')));
        if ($this->boolValue($input['fake_mode'] ?? false)) {
            $seed = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $description ?: $preferred));
            $seed = trim($seed, '-') ?: 'demo-site';
            $domains = [substr($seed, 0, 40) . '.weline.test', substr($seed, 0, 36) . '-shop.weline.test'];
            return ['success' => true, 'domain' => $domains[0], 'candidate_domains' => $domains, 'message' => (string)__('已生成本地域名建议')];
        }
        return $this->websiteAgent->recommendAvailableDomain(
            $description,
            (int)($input['account_id'] ?? 0),
            $preferred,
            $this->boolValue($input['defer_availability_check'] ?? false),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function checkDomain(array $input): array
    {
        $domain = strtolower(trim((string)($input['domain'] ?? '')));
        if ($domain === '') {
            return $this->failure((string)__('请填写域名')) + ['available' => false];
        }
        if (str_ends_with($domain, '.weline.test') || $this->boolValue($input['fake_mode'] ?? false)) {
            return ['success' => true, 'available' => true, 'domain' => $domain, 'message' => (string)__('测试域名可用')];
        }
        $results = $this->websiteAgent->checkCandidateAvailability((int)($input['account_id'] ?? 0), [$domain]);
        $result = $results[$domain] ?? reset($results);
        return is_array($result)
            ? ['success' => true, 'available' => !empty($result['available']), 'domain' => $domain, 'message' => (string)($result['message'] ?? '')]
            : $this->failure((string)__('域名可用性检查失败')) + ['available' => false];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function mutateScope(array $input, int $adminId, bool $merge): array
    {
        $session = $this->loadSession($input, $adminId);
        if ($session === null) {
            return $this->failure((string)__('会话不存在或无权限'));
        }
        $scope = $this->arrayValue($input['scope'] ?? $input['scope_patch'] ?? []);
        $ok = $merge
            ? $this->sessions->mergeScope($session->getId(), $adminId, $scope)
            : $this->sessions->replaceScope($session->getId(), $adminId, $scope);
        return $ok ? ['success' => true, 'scope' => $scope] : $this->failure((string)__('保存工作区范围失败'));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function setStage(array $input, int $adminId): array
    {
        $session = $this->loadSession($input, $adminId);
        $stage = trim((string)($input['stage'] ?? ''));
        if ($session === null || preg_match('/^[a-z0-9_-]{1,64}$/', $stage) !== 1) {
            return $this->failure((string)__('阶段参数无效'));
        }
        return $this->sessions->setStage($session->getId(), $adminId, $stage)
            ? ['success' => true, 'stage' => $stage]
            : $this->failure((string)__('更新阶段失败'));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function deleteSession(array $input, int $adminId): array
    {
        $publicId = trim((string)($input['public_id'] ?? ''));
        return $publicId !== '' && $this->sessions->deleteSessionByPublicId($publicId, $adminId)
            ? ['success' => true, 'public_id' => $publicId]
            : $this->failure((string)__('删除会话失败'));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function saveVirtualTheme(array $input, int $adminId): array
    {
        $publicId = trim((string)($input['public_id'] ?? ''));
        return $this->virtualThemes->saveVirtualThemeByPublicId($publicId, $adminId, [
            'weline_theme_id' => (int)($input['weline_theme_id'] ?? 0),
            'virtual_theme_name' => trim((string)($input['virtual_theme_name'] ?? '')),
            'theme_style_direction' => trim((string)($input['theme_style_direction'] ?? '')),
            'theme_color_scheme' => trim((string)($input['theme_color_scheme'] ?? '')),
            'page_types' => $this->arrayValue($input['page_types'] ?? []),
            'page_type_layouts' => $this->arrayValue($input['page_type_layouts'] ?? []),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function savePageTypeLayout(array $input, int $adminId): array
    {
        return $this->virtualThemes->savePageTypeLayoutByPublicId(
            trim((string)($input['public_id'] ?? '')),
            $adminId,
            trim((string)($input['page_type'] ?? '')),
            $this->arrayValue($input['layout'] ?? []),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function saveVirtualComponent(array $input, int $adminId): array
    {
        return $this->virtualThemes->saveVirtualComponentByPublicId(
            trim((string)($input['public_id'] ?? '')),
            $adminId,
            [
                'weline_theme_id' => (int)($input['weline_theme_id'] ?? 0),
                'component_code' => trim((string)($input['component_code'] ?? '')),
                'name' => trim((string)($input['name'] ?? '')),
                'category' => trim((string)($input['category'] ?? 'content')),
                'description' => trim((string)($input['description'] ?? '')),
                'template_content' => (string)($input['template_content'] ?? ''),
                'meta' => $this->arrayValue($input['meta'] ?? []),
            ],
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function appendMessage(array $input, int $adminId): array
    {
        $session = $this->loadSession($input, $adminId);
        $content = trim((string)($input['content'] ?? ''));
        if ($session === null || $content === '') {
            return $this->failure((string)__('消息参数无效'));
        }
        $role = trim((string)($input['role'] ?? 'user'));
        if (!in_array($role, ['user', 'assistant', 'system'], true)) {
            $role = 'user';
        }
        $type = trim((string)($input['message_type'] ?? 'note')) ?: 'note';
        if (!$this->messages->appendMessage($session->getId(), $adminId, $role, $content, $type)) {
            return $this->failure((string)__('保存消息失败'));
        }
        return ['success' => true, 'messages' => $this->messages->listForSession($session->getId(), $adminId, 150)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function startDomainPurchase(array $input, int $adminId): array
    {
        $session = $this->loadSession($input, $adminId);
        if ($session === null) {
            return $this->failure((string)__('会话不存在或无权限'));
        }
        $result = $this->domainPurchases->queuePurchase(
            $session->getId(),
            $adminId,
            $this->arrayValue($input['scope_patch'] ?? []),
        );
        if (!empty($result['success'])) {
            $result['stream_url'] = '/websites/backend/site-builder-agent/domain-purchase-sse?public_id='
                . rawurlencode($session->getPublicId())
                . '&execution_token=' . rawurlencode((string)($result['stream_token'] ?? ''));
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function domainPurchaseStatus(array $input, int $adminId): array
    {
        $session = $this->loadSession($input, $adminId);
        if ($session === null) {
            return $this->failure((string)__('会话不存在或无权限'));
        }
        $token = trim((string)($input['execution_token'] ?? ''));
        if ($token === '') {
            return ['success' => true, 'state' => $this->domainPurchases->buildViewState($session), 'events' => []];
        }
        $events = [];
        $result = $this->domainPurchases->executeQueuedPurchase(
            $session->getId(),
            $adminId,
            $token,
            static function (string $event, array $data) use (&$events): void {
                $events[] = ['event' => $event, 'data' => $data];
            },
        );
        $events[] = ['event' => !empty($result['success']) ? 'done' : 'error', 'data' => $result];
        $result['events'] = $events;
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function workspaceEvents(array $input, int $adminId): array
    {
        $session = $this->loadSession($input, $adminId);
        if ($session === null) {
            return $this->failure((string)__('会话不存在或无权限'));
        }
        $after = max(0, (int)($input['last_event_id'] ?? 0));
        $rows = $this->events->listEventsAfterId($session->getId(), $adminId, $after, 100);
        $events = [];
        foreach ($rows as $row) {
            $data = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $data['event_id'] = (int)($row['event_id'] ?? 0);
            $data['stage_code'] = (string)($row['stage_code'] ?? '');
            $events[] = ['event' => (string)($row['event_type'] ?? 'message'), 'data' => $data];
        }
        return ['success' => true, 'events' => $events, 'last_event_id' => $rows === [] ? $after : (int)end($rows)['event_id']];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function generateVirtualTheme(array $input, int $adminId): array
    {
        $session = $this->loadSession($input, $adminId);
        if ($session === null) {
            return $this->failure((string)__('会话不存在或无权限'));
        }
        $scope = $session->getScopeArray();
        $pageTypes = $this->stringList($scope['page_types'] ?? ['home_page', 'about_page', 'contact_page']);
        if ($pageTypes === []) {
            $pageTypes = ['home_page', 'about_page', 'contact_page'];
        }
        $layouts = $this->arrayValue($scope['page_type_layouts'] ?? []);
        $result = $this->virtualThemes->saveVirtualThemeByPublicId($session->getPublicId(), $adminId, [
            'weline_theme_id' => (int)($scope['weline_theme_id'] ?? 0),
            'virtual_theme_name' => trim((string)($scope['virtual_theme_name'] ?? 'AI Site Theme')) ?: 'AI Site Theme',
            'theme_style_direction' => trim((string)($scope['theme_style_direction'] ?? '')),
            'theme_color_scheme' => trim((string)($scope['theme_color_scheme'] ?? '')),
            'page_types' => $pageTypes,
            'page_type_layouts' => $layouts,
        ]);
        if (!empty($result['success'])) {
            $this->sessions->mergeScope($session->getId(), $adminId, ['virtual_theme_auto_generated' => 1]);
        }
        $message = (string)($result['message'] ?? (!empty($result['success']) ? __('虚拟主题已生成') : __('虚拟主题生成失败')));
        $result['events'] = [
            ['event' => 'progress', 'data' => ['message' => $message]],
            ['event' => !empty($result['success']) ? 'done' : 'error', 'data' => ['success' => !empty($result['success']), 'message' => $message]],
        ];
        return $result;
    }

    /** @param array<string,mixed> $input */
    private function loadDraft(array $input, int $adminId): ?AiSitePlanDraft
    {
        $publicId = trim((string)($input['draft_public_id'] ?? ''));
        return $publicId === '' ? null : $this->drafts->loadByPublicId($publicId, $adminId);
    }

    /** @param array<string,mixed> $input */
    private function loadSession(array $input, int $adminId): ?\Weline\Websites\Model\AiSiteBuilderSession
    {
        $publicId = trim((string)($input['public_id'] ?? ''));
        return $publicId === '' ? null : $this->sessions->loadByPublicId($publicId, $adminId);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,\r\n]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function boolValue(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array{success:false,message:string} */
    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
