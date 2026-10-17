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

        // fetch()/模型实例在部分 runtime 下会落成数组行；统一走 fetchArray，避免 instanceof 全跳过 → 空路由。
        $domainLookup = [];
        foreach ($domainModel->clear()->select()->fetchArray() as $domainRow) {
            if (!is_array($domainRow)) {
                continue;
            }
            $domainId = (int)($domainRow[MailDomain::schema_fields_ID]
                ?? $domainRow['domain_id']
                ?? 0);
            if ($domainId <= 0) {
                continue;
            }
            $domainLookup[$domainId] = $domainRow;
        }

        $accounts = $accountModel->clear()
            ->where(MailAccount::schema_fields_STATUS, 'active')
            ->where(MailAccount::schema_fields_OUTBOUND_RELAY_ENABLED, 1)
            ->select()
            ->fetchArray();

        /** @var array<string, array{route_name: string, relay: array<string, mixed>}> $routeByKey */
        $routeByKey = [];
        $match = [];
        $selfRef = false;

        foreach ($accounts as $acct) {
            if (!is_array($acct)) {
                continue;
            }
            $accountId = (int)($acct[MailAccount::schema_fields_ID]
                ?? $acct['account_id']
                ?? 0);
            $email = strtolower(trim((string)($acct[MailAccount::schema_fields_EMAIL] ?? $acct['email'] ?? '')));
            if ($accountId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $domainId = (int)($acct[MailAccount::schema_fields_DOMAIN_ID]
                ?? $acct['domain_id']
                ?? 0);
            $domain = $domainLookup[$domainId] ?? null;
            if (!is_array($domain)
                || (string)($domain[MailDomain::schema_fields_STATUS] ?? $domain['status'] ?? '') !== 'active'
            ) {
                continue;
            }

            $host = strtolower(trim((string)($acct[MailAccount::schema_fields_OUTBOUND_RELAY_HOST]
                ?? $acct['outbound_relay_host']
                ?? '')));
            $port = (int)($acct[MailAccount::schema_fields_OUTBOUND_RELAY_PORT]
                ?? $acct['outbound_relay_port']
                ?? 0);
            $user = trim((string)($acct[MailAccount::schema_fields_OUTBOUND_RELAY_USERNAME]
                ?? $acct['outbound_relay_username']
                ?? ''));
            $tls = strtolower(trim((string)($acct[MailAccount::schema_fields_OUTBOUND_RELAY_TLS]
                ?? $acct['outbound_relay_tls']
                ?? ''))) ?: 'starttls';
            $sealed = trim((string)($acct[MailAccount::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED]
                ?? $acct['outbound_relay_password_sealed']
                ?? ''));

            // 旧域级 smarthost 回退（仅当账号未填主机）
            if ($host === '') {
                $host = strtolower(trim((string)($domain[MailDomain::schema_fields_OUTBOUND_RELAY_HOST]
                    ?? $domain['outbound_relay_host']
                    ?? '')));
                if ($port <= 0) {
                    $port = (int)($domain[MailDomain::schema_fields_OUTBOUND_RELAY_PORT]
                        ?? $domain['outbound_relay_port']
                        ?? 0);
                }
                if ($user === '') {
                    $user = trim((string)($domain[MailDomain::schema_fields_OUTBOUND_RELAY_USERNAME]
                        ?? $domain['outbound_relay_username']
                        ?? ''));
                }
                if ($sealed === '') {
                    $sealed = trim((string)($domain[MailDomain::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED]
                        ?? $domain['outbound_relay_password_sealed']
                        ?? ''));
                }
                if ($tls === 'starttls') {
                    $domainTls = strtolower(trim((string)($domain[MailDomain::schema_fields_OUTBOUND_RELAY_TLS]
                        ?? $domain['outbound_relay_tls']
                        ?? '')));
                    if ($domainTls !== '') {
                        $tls = $domainTls;
                    }
                }
            }
            if ($host === '') {
                continue;
            }
            // 中继禁止 25：Stalwart Relay 缺省端口即 25，会误走直连 MX。
            if ($port <= 0 || $port === 25) {
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

            $mailHost = strtolower(trim((string)($domain[MailDomain::schema_fields_HOSTNAME]
                ?? $domain['hostname']
                ?? '')));
            $domainName = strtolower(trim((string)($domain[MailDomain::schema_fields_DOMAIN_NAME]
                ?? $domain['domain_name']
                ?? '')));
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
                        // 共享中继主机常见 CN/SAN 与 smtp.{site} 不一致（例：mail.aiweline.com）。
                        'allowInvalidCerts' => true,
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
            'if' => 'is_local_domain(rcpt_domain)',
            'then' => "'local'",
        ];

        $routes = [];
        $primaryRelayName = '';
        foreach ($routeByKey as $entry) {
            $routes[] = $entry['relay'];
            if ($primaryRelayName === '' && is_string($entry['route_name'] ?? null) && $entry['route_name'] !== '') {
                $primaryRelayName = (string)$entry['route_name'];
            }
        }

        // Stalwart Expression.match 走对象 map（"0","1",…）；JSON 数组会 invalidPatch。
        $matchMap = [];
        foreach (array_values($match) as $i => $rule) {
            $matchMap[(string)$i] = $rule;
        }

        // 生产默认：已配置认证中继时 else 必须走中继，禁止回落直连 MX:25（云主机出站 25 常被墙）。
        $else = $primaryRelayName !== ''
            ? "'" . addcslashes($primaryRelayName, "'\\") . "'"
            : "'mx'";

        return [
            'routes' => $routes,
            'strategy_route' => [
                'match' => (object)$matchMap,
                'else' => $else,
            ],
            'self_ref_warning' => $selfRef,
        ];
    }

    private function looksSelfRef(string $host, string $mailHost, string $domainName): bool
    {
        unset($domainName); // smtp.{domain} 常指向他机中继，不能单凭主机名判自指
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return true;
        }
        if ($mailHost !== '' && $host === $mailHost) {
            return true;
        }

        return false;
    }
}
