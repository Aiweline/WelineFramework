<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendRestController;

#[Acl('Weline_Websites::rest_v1_domain_registrar', '域名注册REST接口', 'mdi-api', 'Websites 域名注册 REST V1 接口', 'Weline_Websites::domain_service')]
class DomainRegistrar extends BackendRestController
{
    #[Acl('Weline_Websites::rest_v1_domain_registrar_check', '检查域名可用性', 'mdi-magnify')]
    public function postCheck(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $accountId = (int)($params['account_id'] ?? $this->request->getParam('account_id', 0));
            $domains = $params['domains'] ?? $this->request->getParam('domains', []);
            if (\is_string($domains)) {
                $domains = \array_values(\array_filter(\array_map('trim', \explode(',', $domains))));
            }
            if ($accountId <= 0) {
                return $this->error('account_id 不能为空', '', 422);
            }
            if (!\is_array($domains) || $domains === []) {
                return $this->error('domains 不能为空', '', 422);
            }

            $result = $this->executeQuery('checkAvailability', [
                'account_id' => $accountId,
                'domains' => $domains,
            ]);
            return $this->success('域名可用性检查完成', ['items' => $result, 'total' => \count((array)$result)]);
        } catch (\Throwable $e) {
            return $this->exception($e, '域名可用性检查失败');
        }
    }

    #[Acl('Weline_Websites::rest_v1_domain_registrar_purchase', '购买域名', 'mdi-cash')]
    public function postPurchase(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $accountId = (int)($params['account_id'] ?? $this->request->getParam('account_id', 0));
            $domain = \strtolower(\trim((string)($params['domain'] ?? $this->request->getParam('domain', ''))));
            if ($accountId <= 0) {
                return $this->error('account_id 不能为空', '', 422);
            }
            if ($domain === '') {
                return $this->error('domain 不能为空', '', 422);
            }

            $item = [
                'domain' => $domain,
                'years' => (int)($params['years'] ?? 1),
                'website_id' => (int)($params['website_id'] ?? 0),
                'auto_create_site' => $this->toBool($params['auto_create_site'] ?? false) ? 'yes' : 'no',
            ];
            foreach ([
                'resolve_to_local',
                'subdomains',
                'dns_choice',
                'dns_provider',
                'dns_account_id',
                'dns_nameservers',
                'cdn_choice',
                'cdn_provider',
                'cdn_account_id',
                'start_lifecycle',
                'purchase_contact',
                'user_client_ip',
            ] as $optionalKey) {
                if (\array_key_exists($optionalKey, $params)) {
                    $item[$optionalKey] = $params[$optionalKey];
                }
            }

            $queryParams = [
                'account_id' => $accountId,
                'items' => [$item],
            ];
            foreach (['auto_resolve', 'resolve_to_local', 'subdomains', 'client_ip', 'purchase_contact'] as $optionalKey) {
                if (\array_key_exists($optionalKey, $params)) {
                    $queryParams[$optionalKey] = $params[$optionalKey];
                }
            }

            $result = $this->executeQuery('purchaseDomain', $queryParams);
            if (!($result['success'] ?? false)) {
                return $this->error((string)($result['message'] ?? '域名购买失败'), $result, 422);
            }

            return $this->success('域名购买请求完成', $result);
        } catch (\Throwable $e) {
            return $this->exception($e, '域名购买失败');
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
