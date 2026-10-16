<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;

/**
 * 按邮箱账号外投 smarthost 全量重建 Stalwart outbound route（sender 匹配）。
 * 主机/端口/凭据落在 MailAccount；域级字段仅作无数据回退。
 * fake / 无管理凭据：只返回拟写载荷，不调远端。
 */
final class MailOutboundRelaySyncService
{
    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   applied: bool,
     *   self_ref_warning: bool,
     *   routes: list<array<string, mixed>>,
     *   strategy_route: array<string, mixed>
     * }
     */
    public function syncAll(): array
    {
        $built = $this->buildPayload();
        $selfRef = !empty($built['self_ref_warning']);
        /** @var StalwartManagementAdapter $adapter */
        $adapter = ObjectManager::getInstance(StalwartManagementAdapter::class);
        if (!$adapter->hasManagementCredential()) {
            return [
                'success' => true,
                'message' => (string)__('已生成外投路由载荷（无 Stalwart 管理凭据，未下发）'),
                'applied' => false,
                'self_ref_warning' => $selfRef,
                'routes' => $built['routes'],
                'strategy_route' => $built['strategy_route'],
            ];
        }
        try {
            $adapter->applyOutboundRelayRoutes($built['routes'], $built['strategy_route']);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__('外投路由下发失败：%{1}', [$e->getMessage()]),
                'applied' => false,
                'self_ref_warning' => $selfRef,
                'routes' => $built['routes'],
                'strategy_route' => $built['strategy_route'],
            ];
        }

        return [
            'success' => true,
            'message' => $selfRef
                ? (string)__('外投路由已下发（检测到可能本机自指，外投未必可达）')
                : (string)__('外投路由已下发'),
            'applied' => true,
            'self_ref_warning' => $selfRef,
            'routes' => $built['routes'],
            'strategy_route' => $built['strategy_route'],
        ];
    }

    /**
     * @return array{
     *   routes: list<array<string, mixed>>,
     *   strategy_route: array<string, mixed>,
     *   self_ref_warning: bool
     * }
     */
    public function buildPayload(): array
    {
        /** @var MailDomain $domainModel */
        $domainModel = ObjectManager::getInstance(MailDomain::class);
        /** @var MailAccount $accountModel */
        $accountModel = ObjectManager::getInstance(MailAccount::class);
        /** @var MailOutboundRelaySecretService $secrets */
        $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);

        $domainLookup = [];
        foreach ($domainModel->clear()->select()->fetch() as $domain) {
            if ($domain instanceof MailDomain && (int)$domain->getId() > 0) {
                $domainLookup[(int)$domain->getId()] = $domain;
            }
        }

        $accounts = $accountModel->clear()
            ->where(MailAccount::schema_fields_STATUS, 'active')
            ->where(MailAccount::schema_fields_OUTBOUND_RELAY_ENABLED, 1)
            ->select()
            ->fetch();

        /** @var array<string, array{route_name: string, relay: array<string, mixed>}> $routeByKey */
        $routeByKey = [];
        $match = [];
        $selfRef = false;

        foreach ($accounts as $acct) {
            if (!$acct instanceof MailAccount) {
                continue;
            }
            $accountId = (int)$acct->getId();
            $email = strtolower(trim((string)$acct->getData(MailAccount::schema_fields_EMAIL)));
            if ($accountId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $domainId = (int)$acct->getData(MailAccount::schema_fields_DOMAIN_ID);
            $domain = $domainLookup[$domainId] ?? null;
            if (!$domain instanceof MailDomain
                || (string)$domain->getData(MailDomain::schema_fields_STATUS) !== 'active'
            ) {
                continue;
            }

            $host = strtolower(trim((string)$acct->getData(MailAccount::schema_fields_OUTBOUND_RELAY_HOST)));
            $port = (int)$acct->getData(MailAccount::schema_fields_OUTBOUND_RELAY_PORT);
            $user = trim((string)$acct->getData(MailAccount::schema_fields_OUTBOUND_RELAY_USERNAME));
            $tls = strtolower(trim((string)$acct->getData(MailAccount::schema_fields_OUTBOUND_RELAY_TLS))) ?: 'starttls';
            $sealed = trim((string)$acct->getData(MailAccount::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED));

            // 旧域级 smarthost 回退（仅当账号未填主机）
            if ($host === '') {
                $host = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_HOST)));
                if ($port <= 0) {
                    $port = (int)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_PORT);
                }
                if ($user === '') {
                    $user = trim((string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_USERNAME));
                }
                if ($sealed === '') {
                    $sealed = trim((string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED));
                }
                if ($tls === 'starttls') {
                    $domainTls = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_TLS)));
                    if ($domainTls !== '') {
                        $tls = $domainTls;
                    }
                }
            }
            if ($host === '') {
                continue;
            }
            if ($port <= 0) {
                $port = 587;
            }
            if (!in_array($tls, ['starttls', 'smtps', 'none'], true)) {
                $tls = 'starttls';
            }

            $password = '';
            if ($sealed !== '') {
                try {
                    $password = $secrets->open($sealed);
                } catch (\Throwable) {
                    $password = '';
                }
            }

            $mailHost = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_HOSTNAME)));
            $domainName = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME)));
            if ($this->looksSelfRef($host, $mailHost, $domainName)) {
                $selfRef = true;
            }

            $routeKey = strtolower($host) . '|' . $port . '|' . $user . '|' . $tls;
            if (!isset($routeByKey[$routeKey])) {
                $routeName = 'weline-outbound-acct-' . substr(sha1($routeKey), 0, 12);
                $routeByKey[$routeKey] = [
                    'route_name' => $routeName,
                    'relay' => [
                        '@type' => 'Relay',
                        'name' => $routeName,
                        'address' => $host,
                        'port' => $port,
                        'protocol' => 'smtp',
                        'implicitTls' => $tls === 'smtps',
                        'authUsername' => $user,
                        'authSecret' => $password !== ''
                            ? ['@type' => 'Value', 'secret' => $password]
                            : ['@type' => 'None'],
                    ],
                ];
            }
            $routeName = $routeByKey[$routeKey]['route_name'];
            $match[] = [
                'if' => "sender == '" . addcslashes($email, "'\\") . "'",
                'then' => "'" . $routeName . "'",
            ];
        }

        $match[] = [
            'if' => "is_local_domain('', rcpt_domain)",
            'then' => "'local'",
        ];

        $routes = [];
        foreach ($routeByKey as $entry) {
            $routes[] = $entry['relay'];
        }

        return [
            'routes' => $routes,
            'strategy_route' => [
                'match' => $match,
                'else' => "'mx'",
            ],
            'self_ref_warning' => $selfRef,
        ];
    }

    private function looksSelfRef(string $host, string $mailHost, string $domainName): bool
    {
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return true;
        }
        if ($mailHost !== '' && $host === $mailHost) {
            return true;
        }
        if ($domainName !== '' && ($host === 'smtp.' . $domainName || $host === 'mail.' . $domainName)) {
            return true;
        }

        return false;
    }
}
