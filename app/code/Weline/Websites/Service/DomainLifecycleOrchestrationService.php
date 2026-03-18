<?php
declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\ProvisioningOrder;
use Weline\Websites\Model\ProvisioningStep;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainParserService;
use Weline\Websites\Service\DomainPoolResolveService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\HealthCheckService;

/**
 * 根域生命周期编排服务。
 *
 * 统一管理：
 * - 已购买域名的后续状态跟踪
 * - 根域与 @/www 子域解析校验
 * - 访问验证与 HTTPS 申请
 * - 与现有 ProvisioningOrder / ProvisioningStep 的协作
 */
class DomainLifecycleOrchestrationService
{
    public function __construct(
        private readonly DomainProvisioningService $provisioningService,
        private readonly ProvisioningOrder $orderModel,
        private readonly ProvisioningStep $stepModel,
        private readonly Domain $domainModel,
        private readonly DomainPool $domainPoolModel,
        private readonly DomainResolveService $domainResolveService,
        private readonly DomainPoolResolveService $domainPoolResolveService,
        private readonly HealthCheckService $healthCheckService
    ) {
    }

    public function startProvisioning(string $domain, int $registrarAccountId, array $options = []): array
    {
        return $this->provisioningService->startProvisioning($domain, $registrarAccountId, $options);
    }

    /**
     * 购买成功后，直接从 DNS/解析步骤开始接管后续流程。
     */
    public function startPurchasedLifecycle(string $domain, int $registrarAccountId, array $options = []): array
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '' || $registrarAccountId <= 0) {
            return [
                'success' => false,
                'message' => __('域名和账号不能为空'),
            ];
        }

        $options = $this->normalizeLifecycleOptions($options);
        $order = $this->getOrderByDomain($domain);

        if ($order === null || $order->getStatus() === ProvisioningOrder::STATUS_FAILED) {
            $result = $this->provisioningService->startProvisioning($domain, $registrarAccountId, [
                'website_id' => (int) ($options['website_id'] ?? 0),
                'auto_create_site' => (string) ($options['auto_create_site'] ?? 'no'),
                'apply_ssl' => (bool) $options['apply_ssl'],
                'skip_purchase' => true,
                'domain_owned' => true,
            ]);

            if (!($result['success'] ?? false)) {
                return $result;
            }

            $order = $this->requireOrder((int) ($result['order_id'] ?? 0));
            if ($order === null) {
                return [
                    'success' => false,
                    'message' => __('生命周期订单创建成功，但无法读取订单'),
                ];
            }
        }

        $this->saveLifecycleOptions($order->getOrderId(), $options);
        $this->recordPurchaseSuccessIfMissing($order->getOrderId(), $registrarAccountId, $options);
        $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_STEP_DNS);
        $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_DNS);
        $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
        $order->save();

        $processResult = $this->processOrder($order->getOrderId());
        $processResult['order_id'] = $order->getOrderId();
        return $processResult;
    }

    public function processPendingOrders(int $limit = 20, ?string $domainFilter = null): array
    {
        $q = $this->orderModel->clearQuery()
            ->where(ProvisioningOrder::schema_fields_STATUS, [
                ProvisioningOrder::STATUS_STEP_PURCHASE,
                ProvisioningOrder::STATUS_STEP_DNS,
                ProvisioningOrder::STATUS_STEP_RESOLVE,
                ProvisioningOrder::STATUS_STEP_VERIFY,
                ProvisioningOrder::STATUS_STEP_CDN,
                ProvisioningOrder::STATUS_STEP_SSL,
            ], 'IN')
            ->order(ProvisioningOrder::schema_fields_UPDATED_AT, 'ASC');
        if ($domainFilter !== null && $domainFilter !== '') {
            $q->where(ProvisioningOrder::schema_fields_DOMAIN, \strtolower(\trim($domainFilter)));
        }
        $rows = $q->limit($limit)->select()->fetchArray();

        $processed = 0;
        $completed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $orderId = (int) ($row[ProvisioningOrder::schema_fields_ORDER_ID] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $processed++;
            $result = $this->processOrder($orderId);
            if (($result['success'] ?? false) && (($result['completed'] ?? false) || ($result['status'] ?? '') === ProvisioningOrder::STATUS_COMPLETED)) {
                $completed++;
            } elseif (($result['success'] ?? false) === false) {
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'completed' => $completed,
            'failed' => $failed,
        ];
    }

    public function processOrder(int $orderId): array
    {
        $order = $this->requireOrder($orderId);
        if ($order === null) {
            return ['success' => false, 'message' => __('配置订单不存在')];
        }

        $options = $this->getLifecycleOptions($orderId);
        $rootDomain = $this->loadRootDomain($order->getDomain(), (int) $order->getData(ProvisioningOrder::schema_fields_REGISTRAR_ACCOUNT_ID));

        if ($rootDomain === null) {
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_STEP_PURCHASE);
            $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_PURCHASE);
            $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
            $order->save();
            $this->recordProgress($orderId, ProvisioningOrder::STEP_PURCHASE, ProvisioningStep::STATUS_RUNNING, 'lifecycle', 0, [], __('等待根域名同步到本地'));
            return ['success' => true, 'completed' => false, 'message' => __('等待根域名同步到本地')];
        }

        $subdomains = $this->normalizeSubdomains($options['subdomains'] ?? ['@', 'www']);
        $this->recordProgress($orderId, ProvisioningOrder::STEP_DNS, ProvisioningStep::STATUS_RUNNING, 'lifecycle', 0, [
            'subdomains' => $subdomains,
            'resolve_to_local' => (bool) $options['resolve_to_local'],
        ]);

        $rootResolveBefore = $this->domainResolveService->checkResolve($rootDomain);
        if ($options['resolve_to_local'] && !(($rootResolveBefore['resolved'] ?? false) && ($rootResolveBefore['is_local'] ?? false))) {
            $resolveResult = $this->domainResolveService->autoResolveToLocal($rootDomain, $subdomains);
            if (!($resolveResult['success'] ?? false) && !empty($resolveResult['errors'])) {
                $this->recordProgress($orderId, ProvisioningOrder::STEP_DNS, ProvisioningStep::STATUS_FAILED, 'lifecycle', 0, $resolveResult, \implode('; ', $resolveResult['errors']));
            }
        }

        $rootResolve = $this->domainResolveService->checkResolve($rootDomain);
        $pools = $this->loadRootPoolDomains($order->getDomain());
        $poolChecks = [];
        $allResolvedLocal = (bool) ($rootResolve['resolved'] ?? false) && (bool) ($rootResolve['is_local'] ?? false);

        foreach ($pools as $pool) {
            $poolChecks[] = $this->domainPoolResolveService->checkResolve($pool);
        }

        foreach ($poolChecks as $check) {
            if (!(($check['resolved'] ?? false) && ($check['is_local'] ?? false))) {
                $allResolvedLocal = false;
            }
        }

        if (!$allResolvedLocal) {
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_STEP_RESOLVE);
            $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_RESOLVE);
            $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
            $order->save();
            $this->recordProgress($orderId, ProvisioningOrder::STEP_RESOLVE, ProvisioningStep::STATUS_RUNNING, 'lifecycle', 0, [
                'root' => $rootResolve,
                'pool_checks' => $poolChecks,
            ], __('等待根域/@/www 解析到当前服务器'));

            return [
                'success' => true,
                'completed' => false,
                'status' => ProvisioningOrder::STATUS_STEP_RESOLVE,
                'message' => __('等待根域/@/www 解析到当前服务器'),
            ];
        }

        $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_STEP_VERIFY);
        $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_VERIFY);
        $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
        $order->save();

        $domainsToVerify = \array_unique(\array_merge([$order->getDomain()], \array_map(
            static fn(DomainPool $pool): string => $pool->getDomain(),
            $pools
        )));
        $verification = $this->verifyDomains($domainsToVerify);
        $allAccessible = !\in_array(false, \array_map(
            static fn(array $result): bool => (bool) ($result['accessible'] ?? false),
            $verification
        ), true);

        if (!$allAccessible) {
            $this->recordProgress($orderId, ProvisioningOrder::STEP_VERIFY, ProvisioningStep::STATUS_RUNNING, 'lifecycle', 0, $verification, __('等待域名可访问'));
            return [
                'success' => true,
                'completed' => false,
                'status' => ProvisioningOrder::STATUS_STEP_VERIFY,
                'message' => __('等待域名可访问'),
            ];
        }

        $this->recordProgress($orderId, ProvisioningOrder::STEP_VERIFY, ProvisioningStep::STATUS_SUCCESS, 'lifecycle', 0, $verification);

        if ($options['apply_ssl']) {
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_STEP_SSL);
            $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_SSL);
            $order->save();

            $sslResult = $this->provisioningService->runStepSsl($orderId);
            if (!($sslResult['success'] ?? false)) {
                $this->recordProgress($orderId, ProvisioningOrder::STEP_SSL, ProvisioningStep::STATUS_RUNNING, 'lifecycle', 0, $sslResult, $sslResult['message'] ?? __('等待 HTTPS 生效'));
                return [
                    'success' => true,
                    'completed' => false,
                    'status' => ProvisioningOrder::STATUS_STEP_SSL,
                    'message' => $sslResult['message'] ?? __('等待 HTTPS 生效'),
                ];
            }
        }

        $this->markCompleted($order);

        return [
            'success' => true,
            'completed' => true,
            'status' => ProvisioningOrder::STATUS_COMPLETED,
            'message' => __('域名全流程已完成'),
        ];
    }

    public function getOrderByDomain(string $domain): ?ProvisioningOrder
    {
        return $this->provisioningService->getOrderByDomain($domain);
    }

    public function getOrderSteps(int $orderId): array
    {
        return $this->stepModel->clearQuery()
            ->where(ProvisioningStep::schema_fields_PROVISIONING_ORDER_ID, $orderId)
            ->order(ProvisioningStep::schema_fields_STEP_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    public function getDomainLifecycleStatus(string $domain): array
    {
        $domain = \strtolower(\trim($domain));
        $order = $this->getOrderByDomain($domain);
        // 若精确匹配未找到，尝试按根域再查（用户可能输入 www.example.com 而订单存的是 example.com）
        if ($order === null && \class_exists(DomainParserService::class)) {
            try {
                $parser = ObjectManager::getInstance(DomainParserService::class);
                $rootDomain = $parser->parseRootDomain($domain);
                if ($rootDomain !== '' && $rootDomain !== $domain) {
                    $order = $this->getOrderByDomain($rootDomain);
                }
            } catch (\Throwable) {
                // 解析失败时保持 order 为 null
            }
        }
        if ($order === null) {
            return [
                'success' => false,
                'message' => __('未找到该域名的生命周期订单。若通过域名购买功能购买，请确认购买时已勾选「启动全流程状态跟踪」，且 Websites 一站式配置已启用。'),
            ];
        }

        $status = $order->getStatus();
        $currentStep = (string) ($order->getCurrentStep() ?? '');
        $stage = $this->getLifecycleStage($status, $currentStep);

        $rootDomain = $this->loadRootDomain($order->getDomain(), (int) $order->getData(ProvisioningOrder::schema_fields_REGISTRAR_ACCOUNT_ID));
        $poolRows = $this->domainPoolModel->getDomainsByRoot($order->getDomain());

        return [
            'success' => true,
            'data' => [
                'order' => [
                    'order_id' => $order->getOrderId(),
                    'domain' => $order->getDomain(),
                    'status' => $status,
                    'current_step' => $currentStep,
                    'lifecycle_stage' => $stage['key'],
                    'lifecycle_stage_label' => $stage['label'],
                    'error_message' => (string) $order->getData(ProvisioningOrder::schema_fields_ERROR_MESSAGE),
                    'created_at' => (string) $order->getData(ProvisioningOrder::schema_fields_CREATED_AT),
                    'updated_at' => (string) $order->getData(ProvisioningOrder::schema_fields_UPDATED_AT),
                ],
                'root_domain' => $rootDomain ? $rootDomain->getData() : null,
                'pool' => $poolRows,
                'steps' => $this->getOrderSteps($order->getOrderId()),
            ],
        ];
    }

    /**
     * 根据订单 status / current_step 返回展示用阶段（key + 已翻译 label）
     * 用于前端按阶段显示：正在注册 → 切换Dns中 → 解析域名记录中 → 申请HTTPS中 → 正常
     */
    public function getLifecycleStage(string $status, string $currentStep): array
    {
        $step = $currentStep !== '' ? $currentStep : $status;
        $map = [
            ProvisioningOrder::STEP_PURCHASE => ['key' => 'purchase', 'label' => __('正在注册')],
            ProvisioningOrder::STEP_DNS => ['key' => 'dns', 'label' => __('切换Dns中')],
            ProvisioningOrder::STEP_RESOLVE => ['key' => 'resolve', 'label' => __('解析域名记录中')],
            ProvisioningOrder::STEP_VERIFY => ['key' => 'verify', 'label' => __('等待访问验证')],
            ProvisioningOrder::STEP_CDN => ['key' => 'cdn', 'label' => __('切换CDN中')],
            ProvisioningOrder::STEP_SSL => ['key' => 'ssl', 'label' => __('申请HTTPS中')],
            ProvisioningOrder::STATUS_COMPLETED => ['key' => 'completed', 'label' => __('正常')],
            ProvisioningOrder::STATUS_FAILED => ['key' => 'failed', 'label' => __('失败')],
        ];
        if (isset($map[$step])) {
            return $map[$step];
        }
        if ($status === ProvisioningOrder::STATUS_COMPLETED) {
            return $map[ProvisioningOrder::STATUS_COMPLETED];
        }
        if ($status === ProvisioningOrder::STATUS_FAILED) {
            return $map[ProvisioningOrder::STATUS_FAILED];
        }
        return ['key' => 'unknown', 'label' => __('处理中')];
    }

    public function markCertificateIssued(string $domain): void
    {
        $order = $this->getOrderByDomain($domain);
        if ($order === null) {
            return;
        }

        $this->recordProgress($order->getOrderId(), ProvisioningOrder::STEP_SSL, ProvisioningStep::STATUS_SUCCESS, 'lifecycle', 0, [
            'domain' => $domain,
        ]);
        $this->markCompleted($order);
    }

    private function requireOrder(int $orderId): ?ProvisioningOrder
    {
        if ($orderId <= 0) {
            return null;
        }

        $order = clone $this->orderModel;
        $order->load($orderId);
        return $order->getOrderId() > 0 ? $order : null;
    }

    private function loadRootDomain(string $domain, int $accountId): ?Domain
    {
        $model = clone $this->domainModel;
        $model->loadByDomainAndAccount($domain, $accountId);
        return $model->getDomainId() > 0 ? $model : null;
    }

    /**
     * @return DomainPool[]
     */
    private function loadRootPoolDomains(string $rootDomain): array
    {
        $rows = $this->domainPoolModel->getDomainsByRoot($rootDomain);
        $items = [];
        foreach ($rows as $row) {
            $pool = clone $this->domainPoolModel;
            $pool->setData($row);
            $items[] = $pool;
        }
        return $items;
    }

    private function normalizeLifecycleOptions(array $options): array
    {
        $resolveToLocal = !isset($options['resolve_to_local'])
            || !\in_array((string) $options['resolve_to_local'], ['0', 'false', 'no'], true);
        $applySsl = !isset($options['apply_ssl'])
            || !\in_array((string) $options['apply_ssl'], ['0', 'false', 'no'], true);

        return [
            'website_id' => (int) ($options['website_id'] ?? 0),
            'auto_create_site' => (string) ($options['auto_create_site'] ?? 'no'),
            'resolve_to_local' => $resolveToLocal,
            'subdomains' => $this->normalizeSubdomains($options['subdomains'] ?? ['@', 'www']),
            'dns_choice' => (string) ($options['dns_choice'] ?? 'follow_registrar'),
            'dns_provider' => (string) ($options['dns_provider'] ?? ''),
            'dns_account_id' => (int) ($options['dns_account_id'] ?? 0),
            'dns_nameservers' => (string) ($options['dns_nameservers'] ?? ''),
            'cdn_choice' => (string) ($options['cdn_choice'] ?? 'follow_registrar'),
            'cdn_provider' => (string) ($options['cdn_provider'] ?? ''),
            'cdn_account_id' => (int) ($options['cdn_account_id'] ?? 0),
            'apply_ssl' => $applySsl,
        ];
    }

    private function normalizeSubdomains(array|string $subdomains): array
    {
        if (!\is_array($subdomains)) {
            $subdomains = \array_map('trim', \explode(',', (string) $subdomains));
        }

        $subdomains = \array_values(\array_filter(\array_map(
            static fn(string $item): string => \trim($item),
            $subdomains
        )));

        if ($subdomains === []) {
            return ['@', 'www'];
        }

        return $subdomains;
    }

    private function saveLifecycleOptions(int $orderId, array $options): void
    {
        $this->recordProgress($orderId, 'lifecycle_options', ProvisioningStep::STATUS_SUCCESS, 'lifecycle', 0, $options);
    }

    private function getLifecycleOptions(int $orderId): array
    {
        $step = clone $this->stepModel;
        $step->clearQuery()
            ->where(ProvisioningStep::schema_fields_PROVISIONING_ORDER_ID, $orderId)
            ->where(ProvisioningStep::schema_fields_STEP_NAME, 'lifecycle_options')
            ->order(ProvisioningStep::schema_fields_STEP_ID, 'DESC')
            ->find()
            ->fetch();

        $json = (string) $step->getData(ProvisioningStep::schema_fields_RESULT_JSON);
        if ($json !== '') {
            $decoded = \json_decode($json, true);
            if (\is_array($decoded)) {
                return $this->normalizeLifecycleOptions($decoded);
            }
        }

        return $this->normalizeLifecycleOptions([]);
    }

    private function recordPurchaseSuccessIfMissing(int $orderId, int $accountId, array $options): void
    {
        $existing = clone $this->stepModel;
        $existing->clearQuery()
            ->where(ProvisioningStep::schema_fields_PROVISIONING_ORDER_ID, $orderId)
            ->where(ProvisioningStep::schema_fields_STEP_NAME, ProvisioningOrder::STEP_PURCHASE)
            ->where(ProvisioningStep::schema_fields_STATUS, ProvisioningStep::STATUS_SUCCESS)
            ->find()
            ->fetch();

        if ($existing->getStepId() > 0) {
            return;
        }

        $this->recordProgress($orderId, ProvisioningOrder::STEP_PURCHASE, ProvisioningStep::STATUS_SUCCESS, 'registrar', $accountId, [
            'message' => 'purchase_confirmed',
            'options' => $options,
        ]);
    }

    private function verifyDomains(array $domains): array
    {
        $results = [];
        foreach ($domains as $domain) {
            $check = $this->healthCheckService->checkDomain($domain, false);
            $results[] = [
                'domain' => $domain,
                'accessible' => ($check['status'] ?? '') === \Weline\Websites\Model\WebsiteDomain::HEALTH_HEALTHY,
                'code' => $check['code'] ?? null,
                'message' => $check['message'] ?? '',
            ];
        }

        return $results;
    }

    private function markCompleted(ProvisioningOrder $order): void
    {
        $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_COMPLETED);
        $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, '');
        $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
        $order->save();
    }

    private function markFailed(ProvisioningOrder $order, string $message): void
    {
        $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_FAILED);
        $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, $message);
        $order->save();
        $this->recordProgress($order->getOrderId(), (string) ($order->getCurrentStep() ?: 'lifecycle'), ProvisioningStep::STATUS_FAILED, 'lifecycle', 0, [], $message);
    }

    private function recordProgress(
        int $orderId,
        string $stepName,
        string $status,
        string $vendor,
        int $accountId,
        array $result = [],
        string $errorMessage = ''
    ): void {
        $step = clone $this->stepModel;
        $step->clearData();
        $step->setData(ProvisioningStep::schema_fields_PROVISIONING_ORDER_ID, $orderId);
        $step->setData(ProvisioningStep::schema_fields_STEP_NAME, $stepName);
        $step->setData(ProvisioningStep::schema_fields_STATUS, $status);
        $step->setData(ProvisioningStep::schema_fields_VENDOR, $vendor);
        $step->setData(ProvisioningStep::schema_fields_ACCOUNT_ID, $accountId);
        $step->setData(ProvisioningStep::schema_fields_RESULT_JSON, $result === [] ? '' : \json_encode($result, JSON_UNESCAPED_UNICODE));
        $step->setData(ProvisioningStep::schema_fields_ERROR_MESSAGE, $errorMessage);
        $step->save();
    }
}
