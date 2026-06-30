<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendRestController;

#[Acl('Weline_Websites::rest_v1_provisioning', '编排REST接口', 'mdi-api', 'Websites 编排 REST V1 接口', 'Weline_Websites::provisioning')]
class Provisioning extends BackendRestController
{
    #[Acl('Weline_Websites::rest_v1_provisioning_start', '启动编排', 'mdi-play')]
    public function postStart(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $domain = \strtolower(\trim((string)($params['domain'] ?? $this->request->getParam('domain', ''))));
            $accountId = (int)($params['registrar_account_id'] ?? $this->request->getParam('registrar_account_id', 0));
            if ($domain === '') {
                return $this->error('domain 不能为空', '', 422);
            }
            if ($accountId <= 0) {
                return $this->error('registrar_account_id 不能为空', '', 422);
            }

            $queryParams = [
                'domain' => $domain,
                'registrar_account_id' => $accountId,
                'years' => (int)($params['years'] ?? 1),
                'website_id' => (int)($params['website_id'] ?? 0),
                'auto_create_site' => $this->toBool($params['auto_create_site'] ?? false) ? 'yes' : 'no',
                'dns_vendor' => (string)($params['dns_vendor'] ?? ''),
                'dns_account_id' => (int)($params['dns_account_id'] ?? 0),
                'cdn_vendor' => (string)($params['cdn_vendor'] ?? ''),
                'cdn_account_id' => (int)($params['cdn_account_id'] ?? 0),
                'apply_ssl' => $this->toBool($params['apply_ssl'] ?? true, true),
                'skip_purchase' => $this->toBool($params['skip_purchase'] ?? false),
            ];

            $result = $this->executeQuery('startProvisioning', $queryParams);
            if (!($result['success'] ?? false)) {
                return $this->error((string)($result['message'] ?? '启动编排失败'), $result, 422);
            }

            return $this->success('配置编排已启动', $result);
        } catch (\Throwable $e) {
            return $this->exception($e, '启动配置编排失败');
        }
    }

    #[Acl('Weline_Websites::rest_v1_provisioning_status', '查询编排状态', 'mdi-progress-clock')]
    public function postStatus(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $orderId = (int)($params['order_id'] ?? $this->request->getParam('order_id', 0));
            $domain = \strtolower(\trim((string)($params['domain'] ?? $this->request->getParam('domain', ''))));

            if ($orderId <= 0 && $domain === '') {
                return $this->error('order_id 或 domain 至少提供一个', '', 422);
            }

            $order = $orderId > 0
                ? $this->executeQuery('getOrder', ['order_id' => $orderId])
                : $this->executeQuery('getOrderByDomain', ['domain' => $domain]);

            if (!$order || !\is_array($order) || (int)($order['order_id'] ?? 0) <= 0) {
                return $this->error('未找到配置订单', ['order_id' => $orderId, 'domain' => $domain], 404);
            }

            $steps = $this->executeQuery('getOrderSteps', ['order_id' => (int)$order['order_id']]);
            return $this->success('获取配置状态成功', [
                'order' => $order,
                'steps' => \is_array($steps) ? $steps : [],
            ]);
        } catch (\Throwable $e) {
            return $this->exception($e, '获取配置状态失败');
        }
    }

    private function toBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        $v = \strtolower(\trim((string)$value));
        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    protected function executeQuery(string $operation, array $params): mixed
    {
        return w_query('websites', $operation, $params);
    }
}
