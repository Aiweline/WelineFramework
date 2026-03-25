<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSiteBuilderSession;
use Weline\Websites\Service\DomainLifecycleOrchestrationService;
use Weline\Websites\Service\DomainPurchaseService;

class DomainPurchaseWorkbenchService
{
    private const STATE_KEY = 'domain_purchase';
    private const STALE_RUNNING_SECONDS = 2700;
    private const WAIT_TIMEOUT_SECONDS = 2700;
    private const POLL_INTERVAL_MICROSECONDS = 5000000;

    public function __construct(
        private readonly SessionService $sessionService,
        private readonly EventStreamService $eventStreamService,
        private readonly DomainPurchaseService $domainPurchaseService,
        private readonly DomainLifecycleOrchestrationService $domainLifecycleService,
    ) {
    }

    /**
     * @return array{
     *   status:string,
     *   status_label:string,
     *   stage:string,
     *   stage_label:string,
     *   message:string,
     *   domain:string,
     *   registrar_account_id:int,
     *   order_id:int,
     *   purchase_order_id:int,
     *   execution_token:string,
     *   updated_at:string,
     *   started_at:string,
     *   finished_at:string,
     *   can_start:bool,
     *   is_running:bool,
     *   is_completed:bool,
     *   is_failed:bool,
     *   needs_resume:bool
     * }
     */
    public function buildViewState(AiSiteBuilderSession $session): array
    {
        $scope = $session->getScopeArray();
        $providerState = $session->getProviderStateArray();
        $runtime = $this->getRuntimeState($providerState);

        $scopeDomain = $this->normalizeDomain((string)($scope['target_domain'] ?? $scope['selected_domain'] ?? $session->getSelectedDomain()));
        $scopeRegistrarAccountId = (int)($scope['registrar_account_id'] ?? $scope['preferred_registrar_account_id'] ?? $session->getRegistrarAccountId());
        $runtimeDomain = $this->normalizeDomain((string)($runtime['domain'] ?? ''));
        $runtimeRegistrarAccountId = (int)($runtime['registrar_account_id'] ?? 0);

        $status = $this->normalizeStatus((string)($runtime['status'] ?? ($scope['domain_purchase_status'] ?? 'idle')));
        $stage = $this->normalizeStage((string)($runtime['stage'] ?? ($scope['domain_purchase_stage'] ?? 'purchase')));

        if (\in_array($status, ['completed', 'failed'], true)) {
            $runtimeHasIdentity = $runtimeDomain !== '' || $runtimeRegistrarAccountId > 0;
            $scopeChanged = $scopeDomain !== ''
                && $scopeRegistrarAccountId > 0
                && $runtimeHasIdentity
                && ($scopeDomain !== $runtimeDomain || $scopeRegistrarAccountId !== $runtimeRegistrarAccountId);
            if ($scopeChanged) {
                $status = 'idle';
                $stage = 'purchase';
                $runtime = [];
                $runtimeDomain = '';
                $runtimeRegistrarAccountId = 0;
            }
        }

        $isRunning = \in_array($status, ['queued', 'running'], true);
        $domain = $isRunning && $runtimeDomain !== '' ? $runtimeDomain : ($scopeDomain !== '' ? $scopeDomain : $runtimeDomain);
        $registrarAccountId = $isRunning && $runtimeRegistrarAccountId > 0
            ? $runtimeRegistrarAccountId
            : ($scopeRegistrarAccountId > 0 ? $scopeRegistrarAccountId : $runtimeRegistrarAccountId);

        $message = \trim((string)($runtime['message'] ?? ($scope['domain_purchase_message'] ?? '')));
        if ($message === '') {
            $message = match ($status) {
                'queued' => (string)__('域名购买已排队，等待启动'),
                'running' => (string)__('域名购买流程进行中'),
                'completed' => (string)__('域名购买与域名接入已完成'),
                'failed' => (string)__('域名购买流程失败'),
                default => (string)__('准备启动域名购买'),
            };
        }

        $statusLabels = $this->getStatusLabels();
        $stageLabels = $this->getStageLabels();

        return [
            'status' => $status,
            'status_label' => $statusLabels[$status] ?? $statusLabels['idle'],
            'stage' => $stage,
            'stage_label' => $stageLabels[$stage] ?? $stageLabels['purchase'],
            'message' => $message,
            'domain' => $domain,
            'registrar_account_id' => $registrarAccountId,
            'order_id' => (int)($runtime['order_id'] ?? ($scope['domain_purchase_order_id'] ?? 0)),
            'purchase_order_id' => (int)($runtime['purchase_order_id'] ?? 0),
            'execution_token' => (string)($runtime['execution_token'] ?? ''),
            'updated_at' => (string)($runtime['updated_at'] ?? ''),
            'started_at' => (string)($runtime['started_at'] ?? ''),
            'finished_at' => (string)($runtime['finished_at'] ?? ''),
            'can_start' => $domain !== '' && $registrarAccountId > 0 && !\in_array($status, ['queued', 'running', 'completed'], true),
            'is_running' => \in_array($status, ['queued', 'running'], true),
            'is_completed' => $status === 'completed',
            'is_failed' => $status === 'failed',
            'needs_resume' => $status === 'queued' && (string)($runtime['execution_token'] ?? '') !== '',
        ];
    }

    /**
     * @param array<string, mixed> $scopePatch
     * @return array{
     *   success:bool,
     *   message:string,
     *   state?:array<string, mixed>,
     *   startable?:bool,
     *   stream_token?:string
     * }
     */
    public function queuePurchase(int $sessionId, int $adminUserId, array $scopePatch = []): array
    {
        $session = $this->sessionService->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return [
                'success' => false,
                'message' => (string)__('工作区会话不存在或无权限'),
            ];
        }

        if ($scopePatch !== []) {
            $scope = \array_replace($session->getScopeArray(), $scopePatch);
            $this->applyScopeToSession($session, $scope);
            $session->save();
            $session = $this->reloadSession($sessionId, $adminUserId) ?? $session;
        }

        $state = $this->buildViewState($session);
        $scope = $session->getScopeArray();
        $domain = $this->normalizeDomain((string)($scope['target_domain'] ?? $scope['selected_domain'] ?? $state['domain']));
        $registrarAccountId = (int)($scope['registrar_account_id'] ?? $scope['preferred_registrar_account_id'] ?? $state['registrar_account_id']);

        if (!$this->isValidDomain($domain)) {
            return [
                'success' => false,
                'message' => (string)__('请先填写有效的目标域名'),
            ];
        }

        if ($registrarAccountId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('请先选择域名购买服务商账号'),
            ];
        }

        if ($state['status'] === 'running' && !$this->isStateStale($state)) {
            return [
                'success' => true,
                'message' => (string)__('域名购买流程已在后台运行'),
                'state' => $state,
                'startable' => false,
                'stream_token' => (string)($state['execution_token'] ?? ''),
            ];
        }

        if ($state['status'] === 'completed') {
            return [
                'success' => true,
                'message' => (string)__('当前域名购买流程已完成'),
                'state' => $state,
                'startable' => false,
                'stream_token' => (string)($state['execution_token'] ?? ''),
            ];
        }

        if ($state['status'] === 'queued' && !$this->isStateStale($state)) {
            return [
                'success' => true,
                'message' => (string)__('域名购买已排队，正在尝试恢复执行'),
                'state' => $state,
                'startable' => true,
                'stream_token' => (string)($state['execution_token'] ?? ''),
            ];
        }

        $token = $this->generateExecutionToken();
        $runtime = [
            'status' => 'queued',
            'stage' => 'purchase',
            'message' => (string)__('已排队购买 %{domain}，后台流程即将启动', ['domain' => $domain]),
            'domain' => $domain,
            'registrar_account_id' => $registrarAccountId,
            'order_id' => 0,
            'purchase_order_id' => 0,
            'execution_token' => $token,
            'updated_at' => $this->now(),
            'started_at' => '',
            'finished_at' => '',
        ];

        $this->persistRuntime(
            $session,
            $runtime,
            $this->buildScopePatchFromRuntime($runtime)
        );
        $this->appendRuntimeEvent(
            $session,
            $adminUserId,
            'domain_purchase_requested',
            $runtime,
            AiSiteBuilderEvent::LEVEL_INFO
        );

        $fresh = $this->reloadSession($sessionId, $adminUserId) ?? $session;
        return [
            'success' => true,
            'message' => $runtime['message'],
            'state' => $this->buildViewState($fresh),
            'startable' => true,
            'stream_token' => $token,
        ];
    }

    /**
     * @param null|callable(string, array<string, mixed>):void $emit
     * @return array{success:bool,completed:bool,message:string,state?:array<string, mixed>}
     */
    public function executeQueuedPurchase(
        int $sessionId,
        int $adminUserId,
        string $executionToken,
        ?callable $emit = null
    ): array {
        $session = $this->sessionService->loadById($sessionId, $adminUserId);
        if ($session === null) {
            return [
                'success' => false,
                'completed' => false,
                'message' => (string)__('工作区会话不存在或无权限'),
            ];
        }

        $state = $this->buildViewState($session);
        if ($executionToken === '' || ((string)($state['execution_token'] ?? '') !== '' && (string)($state['execution_token'] ?? '') !== $executionToken)) {
            return [
                'success' => false,
                'completed' => false,
                'message' => (string)__('域名购买执行令牌已过期，请重新发起购买'),
            ];
        }

        if ($state['status'] === 'completed') {
            return [
                'success' => true,
                'completed' => true,
                'message' => (string)__('当前域名购买流程已完成'),
                'state' => $state,
            ];
        }

        if ($state['status'] === 'running' && !$this->isStateStale($state)) {
            $this->emit($emit, 'info', [
                'message' => (string)__('域名购买流程已在后台运行'),
                'status' => $state['status'],
                'stage' => $state['stage'],
                'stage_label' => $state['stage_label'],
                'domain' => $state['domain'],
                'order_id' => $state['order_id'],
            ]);

            return [
                'success' => true,
                'completed' => false,
                'message' => (string)__('域名购买流程已在后台运行'),
                'state' => $state,
            ];
        }

        $runtime = [
            'status' => 'running',
            'stage' => 'purchase',
            'message' => (string)__('开始购买域名 %{domain}', ['domain' => $state['domain']]),
            'domain' => $state['domain'],
            'registrar_account_id' => $state['registrar_account_id'],
            'execution_token' => $executionToken,
            'updated_at' => $this->now(),
            'started_at' => $state['started_at'] !== '' ? $state['started_at'] : $this->now(),
            'finished_at' => '',
        ];

        $this->persistRuntime($session, $runtime, $this->buildScopePatchFromRuntime($runtime));
        $session = $this->reloadSession($sessionId, $adminUserId) ?? $session;
        $state = $this->buildViewState($session);

        $this->emit($emit, 'start', [
            'message' => (string)__('域名购买长连接已启动'),
            'domain' => $state['domain'],
            'status' => $state['status'],
            'stage' => $state['stage'],
            'stage_label' => $state['stage_label'],
        ]);
        $this->appendRuntimeEvent(
            $session,
            $adminUserId,
            'domain_purchase_started',
            $runtime,
            AiSiteBuilderEvent::LEVEL_INFO
        );

        if ($this->isFakeMode($session)) {
            return $this->runFakePurchaseFlow($session, $adminUserId, $emit);
        }

        return $this->runRealPurchaseFlow($session, $adminUserId, $emit);
    }

    /**
     * @param null|callable(string, array<string, mixed>):void $emit
     * @return array{success:bool,completed:bool,message:string,state?:array<string, mixed>}
     */
    private function runFakePurchaseFlow(
        AiSiteBuilderSession $session,
        int $adminUserId,
        ?callable $emit
    ): array {
        $state = $this->buildViewState($session);
        $timeline = [
            [
                'sleep' => 300000,
                'runtime' => [
                    'status' => 'running',
                    'stage' => 'purchase',
                    'message' => (string)__('Local demo: simulated domain purchase and bootstrap resources'),
                ],
                'event_type' => 'domain_purchase_progress',
                'level' => AiSiteBuilderEvent::LEVEL_INFO,
            ],
            [
                'sleep' => 300000,
                'runtime' => [
                    'status' => 'running',
                    'stage' => 'resolve',
                    'message' => (string)__('Local demo: waiting for simulated DNS propagation'),
                ],
                'event_type' => 'domain_purchase_progress',
                'level' => 'warning',
            ],
            [
                'sleep' => 300000,
                'runtime' => [
                    'status' => 'completed',
                    'stage' => 'completed',
                    'message' => (string)__('Local demo: simulated DNS resolution and certificate issuance'),
                    'order_id' => 990001,
                    'purchase_order_id' => 880001,
                    'execution_token' => '',
                    'finished_at' => $this->now(),
                ],
                'event_type' => 'domain_purchase_completed',
                'level' => 'success',
            ],
        ];

        foreach ($timeline as $item) {
            \usleep((int)($item['sleep'] ?? 0));

            $patch = \array_replace([
                'domain' => $state['domain'],
                'registrar_account_id' => $state['registrar_account_id'],
                'updated_at' => $this->now(),
                'started_at' => $state['started_at'] !== '' ? $state['started_at'] : $this->now(),
            ], \is_array($item['runtime'] ?? null) ? $item['runtime'] : []);

            $this->persistRuntime($session, $patch, $this->buildScopePatchFromRuntime($patch));
            $session = $this->reloadSession($session->getId(), $adminUserId) ?? $session;
            $state = $this->buildViewState($session);

            $this->appendRuntimeEvent(
                $session,
                $adminUserId,
                (string)($item['event_type'] ?? 'domain_purchase_progress'),
                $patch,
                (string)($item['level'] ?? AiSiteBuilderEvent::LEVEL_INFO)
            );
            $this->emit($emit, 'progress', [
                'message' => $state['message'],
                'status' => $state['status'],
                'stage' => $state['stage'],
                'stage_label' => $state['stage_label'],
                'domain' => $state['domain'],
                'order_id' => $state['order_id'],
            ]);
        }

        return [
            'success' => true,
            'completed' => true,
            'message' => (string)__('Local demo: domain purchase flow completed'),
            'state' => $state,
        ];
    }

    /**
     * @param null|callable(string, array<string, mixed>):void $emit
     * @return array{success:bool,completed:bool,message:string,state?:array<string, mixed>}
     */
    private function runRealPurchaseFlow(
        AiSiteBuilderSession $session,
        int $adminUserId,
        ?callable $emit
    ): array {
        $state = $this->buildViewState($session);
        $domain = (string)($state['domain'] ?? '');
        $registrarAccountId = (int)($state['registrar_account_id'] ?? 0);

        if (!$this->isValidDomain($domain)) {
            return $this->failPurchase($session, $adminUserId, (string)__('请先填写有效的目标域名'), 'purchase', $emit);
        }
        if ($registrarAccountId <= 0) {
            return $this->failPurchase($session, $adminUserId, (string)__('请先选择域名购买服务商账号'), 'purchase', $emit);
        }

        $this->emit($emit, 'progress', [
            'message' => (string)__('正在提交域名购买请求'),
            'status' => $state['status'],
            'stage' => 'purchase',
            'stage_label' => $this->getStageLabels()['purchase'],
            'domain' => $domain,
        ]);

        try {
            $purchaseResult = $this->domainPurchaseService->createAndProcessOrder(
                $registrarAccountId,
                [[
                    'domain' => $domain,
                    'years' => 1,
                    'website_id' => 0,
                    'auto_create_site' => 'no',
                    'resolve_to_local' => 'no',
                    'start_lifecycle' => '0',
                    'subdomains' => ['@', 'www'],
                ]],
                false
            );
        } catch (\Throwable $throwable) {
            return $this->failPurchase($session, $adminUserId, $throwable->getMessage(), 'purchase', $emit);
        }

        if (!($purchaseResult['success'] ?? false)) {
            return $this->failPurchase(
                $session,
                $adminUserId,
                (string)($purchaseResult['message'] ?? __('域名购买失败')),
                'purchase',
                $emit
            );
        }

        $purchaseOrderId = (int)($purchaseResult['order_id'] ?? 0);
        $registeredPatch = [
            'status' => 'running',
            'stage' => 'dns',
            'message' => (string)__('域名购买成功，正在推进 DNS 与证书流程'),
            'domain' => $domain,
            'registrar_account_id' => $registrarAccountId,
            'purchase_order_id' => $purchaseOrderId,
            'updated_at' => $this->now(),
        ];
        $this->persistRuntime($session, $registeredPatch, $this->buildScopePatchFromRuntime($registeredPatch));
        $session = $this->reloadSession($session->getId(), $adminUserId) ?? $session;
        $this->appendRuntimeEvent($session, $adminUserId, 'domain_purchase_progress', $registeredPatch, 'success');
        $this->emit($emit, 'progress', [
            'message' => $registeredPatch['message'],
            'status' => 'running',
            'stage' => 'dns',
            'stage_label' => $this->getStageLabels()['dns'],
            'domain' => $domain,
            'purchase_order_id' => $purchaseOrderId,
        ]);

        try {
            $lifecycleResult = $this->domainLifecycleService->startPurchasedLifecycle($domain, $registrarAccountId, [
                'website_id' => 0,
                'auto_create_site' => 'no',
                'resolve_to_local' => true,
                'apply_ssl' => true,
                'subdomains' => ['@', 'www'],
            ]);
        } catch (\Throwable $throwable) {
            return $this->failPurchase($session, $adminUserId, $throwable->getMessage(), 'dns', $emit);
        }

        $lifecycleOrderId = (int)($lifecycleResult['order_id'] ?? 0);
        if ($lifecycleOrderId <= 0) {
            $statusResult = $this->domainLifecycleService->getDomainLifecycleStatus($domain);
            if (($statusResult['success'] ?? false) && \is_array($statusResult['data']['order'] ?? null)) {
                $lifecycleOrderId = (int)($statusResult['data']['order']['order_id'] ?? 0);
            }
        }
        if ($lifecycleOrderId <= 0) {
            return $this->failPurchase(
                $session,
                $adminUserId,
                (string)($lifecycleResult['message'] ?? __('生命周期订单创建失败')),
                'dns',
                $emit
            );
        }

        $lifecyclePatch = [
            'status' => 'running',
            'stage' => 'dns',
            'message' => (string)__('生命周期订单已建立，正在持续推进域名接入'),
            'domain' => $domain,
            'registrar_account_id' => $registrarAccountId,
            'order_id' => $lifecycleOrderId,
            'purchase_order_id' => $purchaseOrderId,
            'updated_at' => $this->now(),
        ];
        $this->persistRuntime($session, $lifecyclePatch, $this->buildScopePatchFromRuntime($lifecyclePatch));
        $session = $this->reloadSession($session->getId(), $adminUserId) ?? $session;

        $lastSignature = '';
        $startedAt = \time();

        while ((\time() - $startedAt) < self::WAIT_TIMEOUT_SECONDS) {
            $statusResult = $this->domainLifecycleService->getDomainLifecycleStatus($domain);
            if (($statusResult['success'] ?? false) && \is_array($statusResult['data']['order'] ?? null)) {
                $order = $statusResult['data']['order'];
                $stage = $this->normalizeStage((string)($order['lifecycle_stage'] ?? 'dns'));
                $status = $stage === 'completed' ? 'completed' : ($stage === 'failed' ? 'failed' : 'running');
                $message = $this->buildLifecycleMessage($stage, $order);
                $runtimePatch = [
                    'status' => $status,
                    'stage' => $stage,
                    'message' => $message,
                    'domain' => $domain,
                    'registrar_account_id' => $registrarAccountId,
                    'order_id' => (int)($order['order_id'] ?? $lifecycleOrderId),
                    'purchase_order_id' => $purchaseOrderId,
                    'updated_at' => $this->now(),
                ];
                if ($status === 'completed') {
                    $runtimePatch['execution_token'] = '';
                    $runtimePatch['finished_at'] = $this->now();
                }
                if ($status === 'failed') {
                    $runtimePatch['execution_token'] = '';
                    $runtimePatch['finished_at'] = $this->now();
                }

                $signature = $status . '|' . $stage . '|' . $message . '|' . (string)($runtimePatch['order_id'] ?? 0);
                if ($signature !== $lastSignature) {
                    $this->persistRuntime($session, $runtimePatch, $this->buildScopePatchFromRuntime($runtimePatch));
                    $session = $this->reloadSession($session->getId(), $adminUserId) ?? $session;
                    $this->appendRuntimeEvent(
                        $session,
                        $adminUserId,
                        $status === 'completed' ? 'domain_purchase_completed' : ($status === 'failed' ? 'domain_purchase_failed' : 'domain_purchase_progress'),
                        $runtimePatch,
                        $status === 'completed' ? 'success' : ($status === 'failed' ? 'error' : AiSiteBuilderEvent::LEVEL_INFO)
                    );
                    $lastSignature = $signature;
                }

                $this->emit($emit, 'progress', [
                    'message' => $message,
                    'status' => $status,
                    'stage' => $stage,
                    'stage_label' => $this->getStageLabels()[$stage] ?? $stage,
                    'domain' => $domain,
                    'order_id' => (int)($runtimePatch['order_id'] ?? 0),
                ]);

                if ($status === 'completed') {
                    return [
                        'success' => true,
                        'completed' => true,
                        'message' => $message,
                        'state' => $this->buildViewState($session),
                    ];
                }
                if ($status === 'failed') {
                    return [
                        'success' => false,
                        'completed' => true,
                        'message' => $message,
                        'state' => $this->buildViewState($session),
                    ];
                }
            }

            $processResult = $this->domainLifecycleService->processOrder($lifecycleOrderId);
            if (($processResult['success'] ?? false) === false) {
                return $this->failPurchase(
                    $session,
                    $adminUserId,
                    (string)($processResult['message'] ?? __('域名生命周期推进失败')),
                    'failed',
                    $emit
                );
            }

            \usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        return $this->failPurchase(
            $session,
            $adminUserId,
            (string)__('域名购买流程等待超时，请稍后在工作台继续查看进度'),
            'failed',
            $emit
        );
    }

    /**
     * @param array<string, mixed> $order
     */
    /**
     * @param array<string, mixed> $providerState
     * @return array<string, mixed>
     */
    private function getRuntimeState(array $providerState): array
    {
        $runtime = $providerState[self::STATE_KEY] ?? [];
        return \is_array($runtime) ? $runtime : [];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return '';
        }

        if (\str_contains($domain, '://')) {
            $parsedHost = \parse_url($domain, \PHP_URL_HOST);
            if (\is_string($parsedHost) && $parsedHost !== '') {
                $domain = $parsedHost;
            }
        }

        if (\str_contains($domain, '/')) {
            $domain = \explode('/', $domain, 2)[0];
        }

        if (\substr_count($domain, ':') === 1) {
            $domain = \explode(':', $domain, 2)[0];
        }

        return \trim($domain, ". \t\n\r\0\x0B");
    }

    private function isValidDomain(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return false;
        }

        return \filter_var($domain, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isStateStale(array $state): bool
    {
        $timestamp = $this->parseTimestamp((string)($state['updated_at'] ?? ''));
        if ($timestamp <= 0) {
            $timestamp = $this->parseTimestamp((string)($state['started_at'] ?? ''));
        }

        if ($timestamp <= 0) {
            return true;
        }

        return (\time() - $timestamp) >= self::STALE_RUNNING_SECONDS;
    }

    private function generateExecutionToken(): string
    {
        try {
            return \bin2hex(\random_bytes(16));
        } catch (\Throwable) {
            return \str_replace('.', '', \uniqid('domain_purchase_', true));
        }
    }

    private function reloadSession(int $sessionId, int $adminUserId): ?AiSiteBuilderSession
    {
        return $this->sessionService->loadById($sessionId, $adminUserId);
    }

    private function isFakeMode(AiSiteBuilderSession $session): bool
    {
        $scope = $session->getScopeArray();
        return !empty($scope['fake_mode']) || (string)($scope['build_execution_mode'] ?? '') === 'local_fake_demo';
    }

    private function now(): string
    {
        return \date('Y-m-d H:i:s');
    }

    private function normalizeStatus(string $status): string
    {
        $status = \strtolower(\trim($status));
        return match ($status) {
            'queued', 'running', 'completed', 'failed', 'idle' => $status,
            'success', 'done' => 'completed',
            'error' => 'failed',
            default => 'idle',
        };
    }

    private function normalizeStage(string $stage): string
    {
        $stage = \strtolower(\trim($stage));
        return match ($stage) {
            'purchase', 'dns', 'resolve', 'verify', 'cdn', 'ssl', 'completed', 'failed' => $stage,
            'success', 'done' => 'completed',
            'error' => 'failed',
            default => 'purchase',
        };
    }

    /**
     * @return array<string, string>
     */
    private function getStatusLabels(): array
    {
        return [
            'idle' => (string)__('待启动'),
            'queued' => (string)__('已排队'),
            'running' => (string)__('进行中'),
            'completed' => (string)__('已完成'),
            'failed' => (string)__('失败'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getStageLabels(): array
    {
        return [
            'purchase' => (string)__('购买域名'),
            'dns' => (string)__('切换 DNS'),
            'resolve' => (string)__('等待解析'),
            'verify' => (string)__('访问验证'),
            'cdn' => (string)__('切换 CDN'),
            'ssl' => (string)__('申请 HTTPS'),
            'completed' => (string)__('域名完备'),
            'failed' => (string)__('流程失败'),
        ];
    }

    private function parseTimestamp(string $value): int
    {
        $value = \trim($value);
        if ($value === '') {
            return 0;
        }

        $timestamp = \strtotime($value);
        return $timestamp === false ? 0 : $timestamp;
    }

    private function buildLifecycleMessage(string $stage, array $order): string
    {
        $errorMessage = \trim((string)($order['error_message'] ?? ''));
        if ($stage === 'failed' && $errorMessage !== '') {
            return $errorMessage;
        }

        return match ($stage) {
            'purchase' => (string)__('域名已提交购买，等待同步到本地'),
            'dns' => (string)__('域名已购买，正在切换 DNS'),
            'resolve' => (string)__('DNS 已切换，等待解析生效到当前服务器'),
            'verify' => (string)__('解析已生效，正在验证域名可访问性'),
            'cdn' => (string)__('访问验证已通过，正在切换 CDN'),
            'ssl' => (string)__('访问验证已通过，正在申请 HTTPS 证书'),
            'completed' => (string)__('域名购买、解析与证书流程已完成'),
            'failed' => $errorMessage !== '' ? $errorMessage : (string)__('域名接入流程失败'),
            default => (string)__('域名接入流程进行中'),
        };
    }

    /**
     * @param array<string, mixed> $runtimePatch
     * @param array<string, mixed> $scopePatch
     */
    private function persistRuntime(AiSiteBuilderSession $session, array $runtimePatch, array $scopePatch = []): void
    {
        $providerState = $session->getProviderStateArray();
        $runtime = $this->getRuntimeState($providerState);
        $providerState[self::STATE_KEY] = \array_replace($runtime, $runtimePatch);

        $scope = \array_replace($session->getScopeArray(), $scopePatch);
        $this->applyScopeToSession($session, $scope);
        $session->setProviderStateArray($providerState);
        $session->save();
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyScopeToSession(AiSiteBuilderSession $session, array $scope): void
    {
        $session->setScopeArray($scope);

        $domain = $this->normalizeDomain((string)($scope['target_domain'] ?? $scope['selected_domain'] ?? $session->getSelectedDomain()));
        $registrarAccountId = (int)($scope['registrar_account_id'] ?? $scope['preferred_registrar_account_id'] ?? $session->getRegistrarAccountId());
        if ($domain !== '') {
            $session->setData(AiSiteBuilderSession::schema_fields_SELECTED_DOMAIN, $domain);
        }
        if ($registrarAccountId > 0) {
            $session->setData(AiSiteBuilderSession::schema_fields_REGISTRAR_ACCOUNT_ID, $registrarAccountId);
        }
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    private function buildScopePatchFromRuntime(array $runtime): array
    {
        $stage = $this->normalizeStage((string)($runtime['stage'] ?? 'purchase'));
        $status = $this->normalizeStatus((string)($runtime['status'] ?? 'idle'));
        $domain = $this->normalizeDomain((string)($runtime['domain'] ?? ''));
        $registrarAccountId = (int)($runtime['registrar_account_id'] ?? 0);
        $message = \trim((string)($runtime['message'] ?? ''));

        $scopePatch = [
            'domain_purchase_status' => $status,
            'domain_purchase_stage' => $stage,
            'domain_purchase_stage_label' => $this->getStageLabels()[$stage] ?? $stage,
            'domain_purchase_message' => $message,
            'domain_purchase_order_id' => (int)($runtime['order_id'] ?? 0),
            'domain_setup_status' => $stage,
        ];
        if ($domain !== '') {
            $scopePatch['target_domain'] = $domain;
            $scopePatch['selected_domain'] = $domain;
        }
        if ($registrarAccountId > 0) {
            $scopePatch['preferred_registrar_account_id'] = $registrarAccountId;
            $scopePatch['registrar_account_id'] = $registrarAccountId;
        }

        switch ($stage) {
            case 'purchase':
                $scopePatch['dns_status'] = $status === 'failed' ? 'failed' : 'awaiting_dns';
                $scopePatch['certificate_status'] = $status === 'failed' ? 'failed' : 'awaiting_certificate';
                break;
            case 'dns':
                $scopePatch['dns_status'] = $status === 'failed' ? 'failed' : 'switching_dns';
                $scopePatch['certificate_status'] = 'awaiting_certificate';
                break;
            case 'resolve':
                $scopePatch['dns_status'] = $status === 'failed' ? 'failed' : 'propagating';
                $scopePatch['certificate_status'] = 'awaiting_certificate';
                break;
            case 'verify':
                $scopePatch['dns_status'] = 'ready';
                $scopePatch['certificate_status'] = 'awaiting_certificate';
                break;
            case 'cdn':
                $scopePatch['dns_status'] = 'ready';
                $scopePatch['certificate_status'] = 'awaiting_certificate';
                break;
            case 'ssl':
                $scopePatch['dns_status'] = 'ready';
                $scopePatch['certificate_status'] = $status === 'failed' ? 'failed' : 'issuing';
                break;
            case 'completed':
                $scopePatch['dns_status'] = 'ready';
                $scopePatch['certificate_status'] = 'issued';
                break;
            case 'failed':
                $scopePatch['dns_status'] = 'failed';
                $scopePatch['certificate_status'] = 'failed';
                break;
        }

        return $scopePatch;
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function appendRuntimeEvent(
        AiSiteBuilderSession $session,
        int $adminUserId,
        string $eventType,
        array $runtime,
        string $level
    ): void {
        $status = $this->normalizeStatus((string)($runtime['status'] ?? 'idle'));
        $stage = $this->normalizeStage((string)($runtime['stage'] ?? 'purchase'));

        $payload = [
            'channel' => self::STATE_KEY,
            'message' => (string)($runtime['message'] ?? ''),
            'status' => $status,
            'status_label' => $this->getStatusLabels()[$status] ?? '',
            'stage' => $stage,
            'stage_label' => $this->getStageLabels()[$stage] ?? '',
            'domain' => $this->normalizeDomain((string)($runtime['domain'] ?? '')),
            'registrar_account_id' => (int)($runtime['registrar_account_id'] ?? 0),
            'order_id' => (int)($runtime['order_id'] ?? 0),
            'purchase_order_id' => (int)($runtime['purchase_order_id'] ?? 0),
            'updated_at' => (string)($runtime['updated_at'] ?? ''),
        ];

        $this->eventStreamService->appendEvent(
            $session->getId(),
            $adminUserId,
            \trim($session->getCurrentStage()) !== '' ? $session->getCurrentStage() : 'prepare',
            $eventType,
            $payload,
            $level
        );
    }

    /**
     * @param null|callable(string, array<string, mixed>):void $emit
     * @return array{success:bool,completed:bool,message:string,state?:array<string, mixed>}
     */
    private function failPurchase(
        AiSiteBuilderSession $session,
        int $adminUserId,
        string $message,
        string $stage,
        ?callable $emit
    ): array {
        $scope = $session->getScopeArray();
        $runtime = [
            'status' => 'failed',
            'stage' => $this->normalizeStage($stage),
            'message' => \trim($message) !== '' ? $message : (string)__('域名购买流程失败'),
            'domain' => $this->normalizeDomain((string)($scope['target_domain'] ?? $session->getSelectedDomain())),
            'registrar_account_id' => (int)($scope['registrar_account_id'] ?? $session->getRegistrarAccountId()),
            'execution_token' => '',
            'updated_at' => $this->now(),
            'finished_at' => $this->now(),
        ];

        $this->persistRuntime($session, $runtime, $this->buildScopePatchFromRuntime($runtime));
        $session = $this->reloadSession($session->getId(), $adminUserId) ?? $session;
        $this->appendRuntimeEvent($session, $adminUserId, 'domain_purchase_failed', $runtime, 'error');
        $this->emit($emit, 'error', [
            'message' => $runtime['message'],
            'status' => 'failed',
            'stage' => $runtime['stage'],
            'stage_label' => $this->getStageLabels()[$runtime['stage']] ?? $runtime['stage'],
            'domain' => $runtime['domain'],
        ]);

        return [
            'success' => false,
            'completed' => true,
            'message' => $runtime['message'],
            'state' => $this->buildViewState($session),
        ];
    }

    /**
     * @param null|callable(string, array<string, mixed>):void $emit
     * @param array<string, mixed> $payload
     */
    private function emit(?callable $emit, string $event, array $payload): void
    {
        if ($emit !== null) {
            $emit($event, $payload);
        }
    }

}
