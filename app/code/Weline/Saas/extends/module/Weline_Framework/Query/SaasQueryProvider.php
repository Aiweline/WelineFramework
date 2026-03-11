<?php
declare(strict_types=1);

namespace Weline\Saas\Extends\Module\Weline_Framework\Query;

use Weline\Framework\App\Env;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Saas\Model\ProvisioningOrder;
use Weline\Saas\Model\ProvisioningStep;
use Weline\Saas\Service\DomainLifecycleOrchestrationService;
use Weline\Saas\Service\DomainProvisioningService;

class SaasQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly DomainProvisioningService $provisioningService,
        private readonly DomainLifecycleOrchestrationService $lifecycleService,
        private readonly ProvisioningOrder $orderModel,
        private readonly ProvisioningStep $stepModel
    ) {
    }

    public function getProviderName(): string
    {
        return 'saas';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'startProvisioning'   => $this->startProvisioning($params),
            'startPurchasedLifecycle' => $this->startPurchasedLifecycle($params),
            'getOrder'            => $this->getOrder($params),
            'getOrderByDomain'    => $this->getOrderByDomain($params),
            'getDomainLifecycleStatus' => $this->getDomainLifecycleStatus($params),
            'getOrders'           => $this->getOrders($params),
            'processOrder'        => $this->processOrder($params),
            'runStepDns'          => $this->runStepDns($params),
            'runStepCdn'          => $this->runStepCdn($params),
            'runStepSsl'          => $this->runStepSsl($params),
            'switchNameservers'   => $this->switchNameservers($params),
            'getOrderSteps'       => $this->getOrderSteps($params),
            'getPublicIp'         => $this->provisioningService->getPublicIp(),
            default => throw new \InvalidArgumentException(
                (string)__('SaaS 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider'    => 'saas',
            'name'        => __('SaaS 配置编排查询'),
            'description' => __('提供一站式配置编排能力：域名购买、DNS 绑定、CDN 绑定、SSL 证书申请'),
            'module'      => 'Weline_Saas',
            'operations'  => [
                [
                    'name'        => 'startProvisioning',
                    'description' => __('启动配置流程：购买域名'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                        ['name' => 'registrar_account_id', 'type' => 'int', 'required' => true, 'description' => __('域名商账号 ID')],
                        ['name' => 'years', 'type' => 'int', 'required' => false, 'description' => __('购买年限，默认 1')],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => __('网站 ID')],
                        ['name' => 'auto_create_site', 'type' => 'string', 'required' => false, 'description' => __('是否自动创建站点 yes/no')],
                        ['name' => 'dns_vendor', 'type' => 'string', 'required' => false, 'description' => __('DNS 供应商')],
                        ['name' => 'dns_account_id', 'type' => 'int', 'required' => false, 'description' => __('DNS 账户 ID')],
                        ['name' => 'cdn_vendor', 'type' => 'string', 'required' => false, 'description' => __('CDN 供应商')],
                        ['name' => 'cdn_account_id', 'type' => 'int', 'required' => false, 'description' => __('CDN 账户 ID')],
                        ['name' => 'apply_ssl', 'type' => 'bool', 'required' => false, 'description' => __('是否申请 SSL 证书')],
                    ],
                ],
                [
                    'name'        => 'startPurchasedLifecycle',
                    'description' => __('为已购买域名启动生命周期编排'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                        ['name' => 'registrar_account_id', 'type' => 'int', 'required' => true, 'description' => __('域名商账号 ID')],
                        ['name' => 'resolve_to_local', 'type' => 'string|bool', 'required' => false, 'description' => __('是否自动解析到本服务器')],
                        ['name' => 'subdomains', 'type' => 'array|string', 'required' => false, 'description' => __('子域名列表')],
                        ['name' => 'dns_choice', 'type' => 'string', 'required' => false, 'description' => __('DNS 策略')],
                        ['name' => 'dns_nameservers', 'type' => 'string', 'required' => false, 'description' => __('自定义 Nameserver')],
                        ['name' => 'cdn_choice', 'type' => 'string', 'required' => false, 'description' => __('CDN 策略')],
                        ['name' => 'apply_ssl', 'type' => 'bool', 'required' => false, 'description' => __('是否申请 SSL 证书')],
                    ],
                ],
                [
                    'name'        => 'getOrder',
                    'description' => __('获取配置订单详情'),
                    'params'      => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true, 'description' => __('订单 ID')],
                    ],
                ],
                [
                    'name'        => 'getOrderByDomain',
                    'description' => __('根据域名获取配置订单'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('域名')],
                    ],
                ],
                [
                    'name'        => 'getDomainLifecycleStatus',
                    'description' => __('按根域名获取生命周期状态详情'),
                    'params'      => [
                        ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => __('根域名')],
                    ],
                ],
                [
                    'name'        => 'getOrders',
                    'description' => __('获取配置订单列表'),
                    'params'      => [
                        ['name' => 'status', 'type' => 'string|null', 'required' => false, 'description' => __('按状态过滤')],
                        ['name' => 'domain', 'type' => 'string|null', 'required' => false, 'description' => __('按域名模糊过滤')],
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'description' => __('页码')],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false, 'description' => __('每页数量')],
                    ],
                ],
                [
                    'name'        => 'processOrder',
                    'description' => __('推进生命周期订单到下一状态'),
                    'params'      => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true, 'description' => __('订单 ID')],
                    ],
                ],
                [
                    'name'        => 'runStepDns',
                    'description' => __('执行 DNS 绑定步骤'),
                    'params'      => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true, 'description' => __('订单 ID')],
                    ],
                ],
                [
                    'name'        => 'runStepCdn',
                    'description' => __('执行 CDN 绑定步骤'),
                    'params'      => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true, 'description' => __('订单 ID')],
                        ['name' => 'cdn_vendor', 'type' => 'string|null', 'required' => false, 'description' => __('CDN 供应商')],
                        ['name' => 'cdn_account_id', 'type' => 'int|null', 'required' => false, 'description' => __('CDN 账户 ID')],
                    ],
                ],
                [
                    'name'        => 'runStepSsl',
                    'description' => __('执行 SSL 证书申请步骤'),
                    'params'      => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true, 'description' => __('订单 ID')],
                    ],
                ],
                [
                    'name'        => 'switchNameservers',
                    'description' => __('切换域名 NS 到 CDN 供应商'),
                    'params'      => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true, 'description' => __('订单 ID')],
                        ['name' => 'nameservers', 'type' => 'array', 'required' => true, 'description' => __('NS 列表')],
                    ],
                ],
                [
                    'name'        => 'getOrderSteps',
                    'description' => __('获取订单的步骤记录'),
                    'params'      => [
                        ['name' => 'order_id', 'type' => 'int', 'required' => true, 'description' => __('订单 ID')],
                    ],
                ],
                [
                    'name'        => 'getPublicIp',
                    'description' => __('获取当前服务器公网 IP'),
                    'params'      => [],
                ],
            ],
        ];
    }

    private function startProvisioning(array $params): array
    {
        $domain = (string)($params['domain'] ?? '');
        $registrarAccountId = (int)($params['registrar_account_id'] ?? 0);

        if ($domain === '' || $registrarAccountId <= 0) {
            return ['success' => false, 'message' => (string)__('域名和域名商账号 ID 不能为空')];
        }

        $options = [
            'years'           => (int)($params['years'] ?? 1),
            'website_id'      => (int)($params['website_id'] ?? 0),
            'auto_create_site' => (string)($params['auto_create_site'] ?? 'no'),
            'dns_vendor'      => (string)($params['dns_vendor'] ?? ''),
            'dns_account_id'  => (int)($params['dns_account_id'] ?? 0),
            'cdn_vendor'      => (string)($params['cdn_vendor'] ?? ''),
            'cdn_account_id'  => (int)($params['cdn_account_id'] ?? 0),
            'apply_ssl'       => $params['apply_ssl'] ?? true,
        ];

        return $this->lifecycleService->startProvisioning($domain, $registrarAccountId, $options);
    }

    private function startPurchasedLifecycle(array $params): array
    {
        $domain = (string)($params['domain'] ?? '');
        $registrarAccountId = (int)($params['registrar_account_id'] ?? 0);
        if ($domain === '' || $registrarAccountId <= 0) {
            return ['success' => false, 'message' => (string)__('域名和域名商账号 ID 不能为空')];
        }

        return $this->lifecycleService->startPurchasedLifecycle($domain, $registrarAccountId, $params);
    }

    private function getOrder(array $params): ?array
    {
        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $order = clone $this->orderModel;
        $order->load($orderId);

        if (!$order->getOrderId()) {
            return null;
        }

        return $this->formatOrder($order);
    }

    private function getOrderByDomain(array $params): ?array
    {
        $domain = (string)($params['domain'] ?? '');
        if ($domain === '') {
            return null;
        }

        $order = $this->lifecycleService->getOrderByDomain($domain);
        if ($order === null) {
            return null;
        }

        return $this->formatOrder($order);
    }

    private function getDomainLifecycleStatus(array $params): array
    {
        $domain = (string)($params['domain'] ?? '');
        if ($domain === '') {
            return ['success' => false, 'message' => (string)__('域名不能为空')];
        }

        return $this->lifecycleService->getDomainLifecycleStatus($domain);
    }

    private function getOrders(array $params): array
    {
        $status = $params['status'] ?? null;
        $domain = isset($params['domain']) ? trim((string)$params['domain']) : '';
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);

        $model = clone $this->orderModel;
        $model->clearQuery();

        if ($status !== null && $status !== '') {
            $model->where(ProvisioningOrder::schema_fields_STATUS, (string)$status);
        }
        if ($domain !== '') {
            $model->where(ProvisioningOrder::schema_fields_DOMAIN, '%' . $domain . '%', 'LIKE');
        }

        $model->order(ProvisioningOrder::schema_fields_ORDER_ID, 'DESC');
        $model->pagination($page, $pageSize);

        $records = $model->select()->fetchArray();
        $orders = [];
        $field = static function (array $record, string $key): string {
            if (isset($record[$key]) && $record[$key] !== null && $record[$key] !== '') {
                return (string) $record[$key];
            }
            $withAlias = 'main_table.' . $key;
            if (isset($record[$withAlias]) && $record[$withAlias] !== null && $record[$withAlias] !== '') {
                return (string) $record[$withAlias];
            }
            return isset($record[$key]) ? (string) $record[$key] : (isset($record[$withAlias]) ? (string) $record[$withAlias] : '');
        };
        foreach ($records as $record) {
            $record = is_array($record) ? $record : [];
            $oid = $record[ProvisioningOrder::schema_fields_ORDER_ID] ?? $record['main_table.' . ProvisioningOrder::schema_fields_ORDER_ID] ?? 0;
            $orders[] = [
                'order_id'      => (int) $oid,
                'domain'        => $field($record, ProvisioningOrder::schema_fields_DOMAIN),
                'status'        => $field($record, ProvisioningOrder::schema_fields_STATUS),
                'current_step'  => $field($record, ProvisioningOrder::schema_fields_CURRENT_STEP),
                'error_message' => $field($record, ProvisioningOrder::schema_fields_ERROR_MESSAGE),
                'created_at'    => $field($record, ProvisioningOrder::schema_fields_CREATED_AT),
                'updated_at'    => $field($record, ProvisioningOrder::schema_fields_UPDATED_AT),
            ];
        }

        return [
            'items'     => $orders,
            'page'      => $page,
            'page_size' => $pageSize,
            'total'     => $model->getTotal(),
        ];
    }

    private function runStepDns(array $params): array
    {
        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return ['success' => false, 'message' => (string)__('订单 ID 无效')];
        }

        return $this->provisioningService->runStepDns($orderId);
    }

    private function processOrder(array $params): array
    {
        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return ['success' => false, 'message' => (string)__('订单 ID 无效')];
        }

        return $this->lifecycleService->processOrder($orderId);
    }

    private function runStepCdn(array $params): array
    {
        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return ['success' => false, 'message' => (string)__('订单 ID 无效')];
        }

        $cdnVendor = isset($params['cdn_vendor']) ? (string)$params['cdn_vendor'] : null;
        $cdnAccountId = isset($params['cdn_account_id']) ? (int)$params['cdn_account_id'] : null;

        return $this->provisioningService->runStepCdn($orderId, $cdnVendor, $cdnAccountId);
    }

    private function runStepSsl(array $params): array
    {
        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return ['success' => false, 'message' => (string)__('订单 ID 无效')];
        }

        return $this->provisioningService->runStepSsl($orderId);
    }

    private function switchNameservers(array $params): array
    {
        $orderId = (int)($params['order_id'] ?? 0);
        $nameservers = (array)($params['nameservers'] ?? []);

        if ($orderId <= 0 || $nameservers === []) {
            return ['success' => false, 'message' => (string)__('订单 ID 和 NS 列表不能为空')];
        }

        return $this->provisioningService->switchNameservers($orderId, $nameservers);
    }

    private function getOrderSteps(array $params): array
    {
        $orderId = (int)($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            return [];
        }

        $model = clone $this->stepModel;
        $model->clearQuery();
        $model->where(ProvisioningStep::schema_fields_PROVISIONING_ORDER_ID, $orderId);
        $model->order(ProvisioningStep::schema_fields_STEP_ID, 'ASC');

        $records = $model->select()->fetchArray();
        $steps = [];
        foreach ($records as $record) {
            $steps[] = [
                'step_id'       => (int)($record[ProvisioningStep::schema_fields_STEP_ID] ?? 0),
                'step_name'     => (string)($record[ProvisioningStep::schema_fields_STEP_NAME] ?? ''),
                'status'        => (string)($record[ProvisioningStep::schema_fields_STATUS] ?? ''),
                'vendor'        => (string)($record[ProvisioningStep::schema_fields_VENDOR] ?? ''),
                'account_id'    => (int)($record[ProvisioningStep::schema_fields_ACCOUNT_ID] ?? 0),
                'error_message' => (string)($record[ProvisioningStep::schema_fields_ERROR_MESSAGE] ?? ''),
                'result_json'   => (string)($record[ProvisioningStep::schema_fields_RESULT_JSON] ?? ''),
            ];
        }
        return $steps;
    }

    private function formatOrder(ProvisioningOrder $order): array
    {
        return [
            'order_id'             => $order->getOrderId(),
            'domain'               => $order->getDomain(),
            'status'               => (string)$order->getData(ProvisioningOrder::schema_fields_STATUS),
            'current_step'         => (string)$order->getData(ProvisioningOrder::schema_fields_CURRENT_STEP),
            'registrar_account_id' => (int)$order->getData(ProvisioningOrder::schema_fields_REGISTRAR_ACCOUNT_ID),
            'dns_vendor'           => (string)$order->getData(ProvisioningOrder::schema_fields_DNS_VENDOR),
            'dns_account_id'       => (int)$order->getData(ProvisioningOrder::schema_fields_DNS_ACCOUNT_ID),
            'cdn_vendor'           => (string)$order->getData(ProvisioningOrder::schema_fields_CDN_VENDOR),
            'cdn_account_id'       => (int)$order->getData(ProvisioningOrder::schema_fields_CDN_ACCOUNT_ID),
            'website_id'           => (int)$order->getData(ProvisioningOrder::schema_fields_WEBSITE_ID),
            'apply_ssl'            => (bool)$order->getData(ProvisioningOrder::schema_fields_APPLY_SSL),
            'error_message'        => (string)$order->getData(ProvisioningOrder::schema_fields_ERROR_MESSAGE),
        ];
    }
}
