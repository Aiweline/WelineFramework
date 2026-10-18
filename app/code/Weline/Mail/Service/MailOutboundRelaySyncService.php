<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;
use Weline\Mail\Model\MailOutboundRouteRule;
use Weline\Smtp\Model\SmtpService;
use Weline\Smtp\Service\SmtpServiceCatalogService;

/**
 * 全量重建 Stalwart outbound route。
 *
 * 优先级（规则 > 默认）：
 * 1) local：本域收件进本地投递
 * 2) 收件域导流（配置了中继就按规则：587/465，禁 25）
 * 3) 账号 sender 外投
 * 4) else：本机 Smtp 服务（587/465）作默认外投
 * 5) 无本机 Smtp 时才兜底 mx（25）
 *
 * not_to_do：把 else 固定成 mx；把单个账号外投抬成全局 else；中继端口写 25。
 */
final class MailOutboundRelaySyncService
{
    private const HUB_BINDINGS_FILE = 'var/mail/outbound-hub-bindings.json';

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
        // 规则优先：库内导流 + 已配置 hub 绑定（如 weline-hub-qq-smtp）不得被 else 覆盖。
        $built = $this->mergeHubBindingsIntoPayload($built, $adapter);
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
     * 把已存在的 weline-hub-* 中继按绑定表挂回策略 match（不改动中继本体/密码）。
     *
     * @param array{
     *   routes: list<array<string, mixed>>,
     *   strategy_route: array<string, mixed>,
     *   self_ref_warning: bool
     * } $built
     * @return array{
     *   routes: list<array<string, mixed>>,
     *   strategy_route: array<string, mixed>,
     *   self_ref_warning: bool
     * }
     */
    private function mergeHubBindingsIntoPayload(array $built, StalwartManagementAdapter $adapter): array
    {
        $bindings = $this->loadHubBindings();
        $existingHubs = $adapter->listRelayRouteNamesByPrefix('weline-hub-');
        if ($existingHubs === [] && $bindings === []) {
            return $built;
        }

        $match = $built['strategy_route']['match'] ?? [];
        if ($match instanceof \stdClass) {
            $match = (array)$match;
        }
        if (!is_array($match)) {
            $match = [];
        }
        $coveredIf = [];
        foreach ($match as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $if = trim((string)($rule['if'] ?? ''));
            if ($if !== '') {
                $coveredIf[$if] = true;
            }
        }

        $hubSet = array_fill_keys($existingHubs, true);
        foreach ($bindings as $domain => $routeName) {
            $domain = $this->normalizeMatchDomain((string)$domain);
            $routeName = trim((string)$routeName);
            if ($domain === '' || $routeName === '') {
                continue;
            }
            if (!isset($hubSet[$routeName]) && !str_starts_with($routeName, 'weline-hub-')) {
                continue;
            }
            // 绑定指向的 hub 必须仍在 Stalwart；否则跳过，避免策略挂死名。
            if ($existingHubs !== [] && !isset($hubSet[$routeName])) {
                continue;
            }
            $if = "rcpt_domain == '" . addcslashes($domain, "'\\") . "'";
            if (isset($coveredIf[$if])) {
                continue;
            }
            $match[(string)count($match)] = [
                'if' => $if,
                'then' => "'" . addcslashes($routeName, "'\\") . "'",
            ];
            $coveredIf[$if] = true;
        }

        // 无绑定文件时：若仅有一个 qq hub，按约定挂 qq.com（用户已配好的特殊导流）。
        if ($bindings === [] && isset($hubSet['weline-hub-qq-smtp'])) {
            $if = "rcpt_domain == 'qq.com'";
            if (!isset($coveredIf[$if])) {
                $match[(string)count($match)] = [
                    'if' => $if,
                    'then' => "'weline-hub-qq-smtp'",
                ];
                $this->saveHubBindings(['qq.com' => 'weline-hub-qq-smtp']);
            }
        }

        $built['strategy_route']['match'] = (object)$match;

        return $built;
    }

    /**
     * @return array<string, string> match_domain => route_name
     */
    private function loadHubBindings(): array
    {
        $path = rtrim((string)BP, '/\\') . '/' . self::HUB_BINDINGS_FILE;
        if (!is_readable($path)) {
            return [];
        }
        $raw = json_decode((string)@file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $domain => $routeName) {
            $domain = $this->normalizeMatchDomain((string)$domain);
            $routeName = trim((string)$routeName);
            if ($domain !== '' && $routeName !== '') {
                $out[$domain] = $routeName;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $bindings
     */
    public function saveHubBindings(array $bindings): void
    {
        $path = rtrim((string)BP, '/\\') . '/' . self::HUB_BINDINGS_FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        $clean = [];
        foreach ($bindings as $domain => $routeName) {
            $domain = $this->normalizeMatchDomain((string)$domain);
            $routeName = trim((string)$routeName);
            if ($domain !== '' && $routeName !== '') {
                $clean[$domain] = $routeName;
            }
        }
        @file_put_contents(
            $path,
            json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
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
        /** @var MailOutboundRouteRule $ruleModel */
        $ruleModel = ObjectManager::getInstance(MailOutboundRouteRule::class);
        /** @var MailOutboundRelaySecretService $secrets */
        $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);

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

        /** @var array<string, array{route_name: string, relay: array<string, mixed>}> $routeByKey */
        $routeByKey = [];
        $rcptMatches = [];
        $senderMatches = [];
        $selfRef = false;

        $rules = $ruleModel->clear()
            ->where(MailOutboundRouteRule::schema_fields_ENABLED, 1)
            ->order(MailOutboundRouteRule::schema_fields_PRIORITY, 'ASC')
            ->order(MailOutboundRouteRule::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $matchDomain = $this->normalizeMatchDomain(
                (string)($rule[MailOutboundRouteRule::schema_fields_MATCH_DOMAIN]
                    ?? $rule['match_domain']
                    ?? '')
            );
            if ($matchDomain === '') {
                continue;
            }
            $host = strtolower(trim((string)($rule[MailOutboundRouteRule::schema_fields_RELAY_HOST]
                ?? $rule['relay_host']
                ?? '')));
            if ($host === '') {
                continue;
            }
            $port = $this->normalizeSubmissionPort(
                (int)($rule[MailOutboundRouteRule::schema_fields_RELAY_PORT]
                    ?? $rule['relay_port']
                    ?? 587)
            );
            $user = trim((string)($rule[MailOutboundRouteRule::schema_fields_RELAY_USERNAME]
                ?? $rule['relay_username']
                ?? ''));
            $tls = $this->normalizeTls(
                (string)($rule[MailOutboundRouteRule::schema_fields_RELAY_TLS]
                    ?? $rule['relay_tls']
                    ?? ''),
                $port
            );
            $sealed = trim((string)($rule[MailOutboundRouteRule::schema_fields_RELAY_PASSWORD_SEALED]
                ?? $rule['relay_password_sealed']
                ?? ''));
            $password = '';
            if ($sealed !== '') {
                try {
                    $password = $secrets->open($sealed);
                } catch (\Throwable) {
                    $password = '';
                }
            }
            if ($this->looksSelfRefHost($host)) {
                $selfRef = true;
            }
            $routeName = $this->ensureRelayRoute(
                $routeByKey,
                $host,
                $port,
                $user,
                $tls,
                $password,
                'weline-outbound-rcpt-'
            );
            $rcptMatches[] = [
                'if' => "rcpt_domain == '" . addcslashes($matchDomain, "'\\") . "'",
                'then' => "'" . $routeName . "'",
            ];
        }

        $accounts = $accountModel->clear()
            ->where(MailAccount::schema_fields_STATUS, 'active')
            ->where(MailAccount::schema_fields_OUTBOUND_RELAY_ENABLED, 1)
            ->select()
            ->fetchArray();

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
            $port = $this->normalizeSubmissionPort($port);
            $tls = $this->normalizeTls($tls, $port);

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

            $routeName = $this->ensureRelayRoute(
                $routeByKey,
                $host,
                $port,
                $user,
                $tls,
                $password,
                'weline-outbound-acct-'
            );
            // 仅该发件账号走外投；不得抬成全局 else。
            $senderMatches[] = [
                'if' => "sender == '" . addcslashes($email, "'\\") . "'",
                'then' => "'" . $routeName . "'",
            ];
        }

        // A1：local → rcpt 分流 → sender 外投
        $match = [];
        $match[] = [
            'if' => 'is_local_domain(rcpt_domain)',
            'then' => "'local'",
        ];
        foreach ($rcptMatches as $rule) {
            $match[] = $rule;
        }
        foreach ($senderMatches as $rule) {
            $match[] = $rule;
        }

        // A2：else 默认本机 Smtp（587/465）；无本机 Smtp 才兜底 mx（25）。
        $else = "'mx'";
        $selfSmtp = $this->resolveDefaultSelfSmtpRelay($domainLookup);
        if ($selfSmtp !== null) {
            if ($this->looksSelfRefHost($selfSmtp['host'])
                || $this->hostMatchesAnyMailDomain($selfSmtp['host'], $domainLookup)
            ) {
                $selfRef = true;
            }
            $selfRouteName = $this->ensureRelayRoute(
                $routeByKey,
                $selfSmtp['host'],
                $selfSmtp['port'],
                $selfSmtp['user'],
                $selfSmtp['tls'],
                $selfSmtp['password'],
                'weline-outbound-self-'
            );
            $else = "'" . $selfRouteName . "'";
        }

        $routes = [];
        foreach ($routeByKey as $entry) {
            $routes[] = $entry['relay'];
        }

        $matchMap = [];
        foreach (array_values($match) as $i => $rule) {
            $matchMap[(string)$i] = $rule;
        }

        return [
            'routes' => $routes,
            'strategy_route' => [
                'match' => (object)$matchMap,
                'else' => $else,
            ],
            'self_ref_warning' => $selfRef,
        ];
    }

    /**
     * 本机 Smtp 默认外投：优先 Smtp 目录 self 服务；否则 MailDomain.hostname。
     * 端口只允许 587/465（25→587）。
     *
     * @param array<int, array<string, mixed>> $domainLookup
     * @return array{host:string,port:int,user:string,tls:string,password:string}|null
     */
    private function resolveDefaultSelfSmtpRelay(array $domainLookup): ?array
    {
        try {
            /** @var SmtpServiceCatalogService $catalog */
            $catalog = ObjectManager::getInstance(SmtpServiceCatalogService::class);
            foreach ($catalog->listServices() as $svc) {
                if (!$svc instanceof SmtpService) {
                    continue;
                }
                if ((string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF) {
                    continue;
                }
                if ((string)$svc->getData(SmtpService::schema_fields_STATUS) !== 'active') {
                    continue;
                }
                $host = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
                if ($host === '') {
                    continue;
                }
                $port = $this->normalizeSubmissionPort((int)$svc->getData(SmtpService::schema_fields_PORT));
                $tls = $this->normalizeTls((string)$svc->getData(SmtpService::schema_fields_TLS), $port);

                return [
                    'host' => $host,
                    'port' => $port,
                    'user' => '',
                    'tls' => $tls,
                    'password' => '',
                ];
            }
        } catch (\Throwable) {
            // Smtp 模块不可用时退回 MailDomain。
        }

        foreach ($domainLookup as $domain) {
            if ((string)($domain[MailDomain::schema_fields_STATUS] ?? $domain['status'] ?? '') !== 'active') {
                continue;
            }
            $host = strtolower(trim((string)($domain[MailDomain::schema_fields_HOSTNAME]
                ?? $domain['hostname']
                ?? '')));
            if ($host === '') {
                $root = strtolower(trim((string)($domain[MailDomain::schema_fields_DOMAIN_NAME]
                    ?? $domain['domain_name']
                    ?? '')));
                if ($root === '') {
                    continue;
                }
                $host = 'mail.' . $root;
            }

            return [
                'host' => $host,
                'port' => 587,
                'user' => '',
                'tls' => 'starttls',
                'password' => '',
            ];
        }

        return null;
    }

    /**
     * @param array<string, array{route_name: string, relay: array<string, mixed>}> $routeByKey
     */
    private function ensureRelayRoute(
        array &$routeByKey,
        string $host,
        int $port,
        string $user,
        string $tls,
        string $password,
        string $namePrefix
    ): string {
        $port = $this->normalizeSubmissionPort($port);
        $tls = $this->normalizeTls($tls, $port);
        $routeKey = strtolower($host) . '|' . $port . '|' . $user . '|' . $tls;
        if (!isset($routeByKey[$routeKey])) {
            $routeName = $namePrefix . substr(sha1($routeKey), 0, 12);
            $relay = [
                '@type' => 'Relay',
                'name' => $routeName,
                'address' => $host,
                'port' => $port,
                'protocol' => 'smtp',
                'implicitTls' => $tls === 'smtps',
                'allowInvalidCerts' => true,
            ];
            if ($user !== '') {
                $relay['authUsername'] = $user;
            }
            if ($password !== '') {
                $relay['authSecret'] = ['@type' => 'Value', 'secret' => $password];
            }
            $routeByKey[$routeKey] = [
                'route_name' => $routeName,
                'relay' => $relay,
            ];
        }

        return $routeByKey[$routeKey]['route_name'];
    }

    public function normalizeMatchDomain(string $raw): string
    {
        $domain = strtolower(trim($raw));
        $domain = ltrim($domain, '@');
        $domain = rtrim($domain, '.');
        if ($domain === '' || str_contains($domain, ' ') || str_contains($domain, '@')) {
            return '';
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $domain)) {
            return '';
        }

        return $domain;
    }

    /** 中继/提交端口：禁止 25；非法 → 587；465 保留。 */
    public function normalizeSubmissionPort(int $port): int
    {
        if ($port === 465) {
            return 465;
        }
        if ($port <= 0 || $port === 25 || $port > 65535) {
            return 587;
        }

        return $port;
    }

    private function normalizeTls(string $tls, int $port): string
    {
        $tls = strtolower(trim($tls));
        if ($port === 465 && ($tls === '' || $tls === 'starttls')) {
            return 'smtps';
        }
        if (!in_array($tls, ['starttls', 'smtps', 'none'], true)) {
            return 'starttls';
        }

        return $tls;
    }

    private function looksSelfRefHost(string $host): bool
    {
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    /**
     * @param array<int, array<string, mixed>> $domainLookup
     */
    private function hostMatchesAnyMailDomain(string $host, array $domainLookup): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        foreach ($domainLookup as $domain) {
            $mailHost = strtolower(trim((string)($domain[MailDomain::schema_fields_HOSTNAME]
                ?? $domain['hostname']
                ?? '')));
            if ($mailHost !== '' && $host === $mailHost) {
                return true;
            }
        }

        return false;
    }

    private function looksSelfRef(string $host, string $mailHost, string $domainName): bool
    {
        unset($domainName);
        if ($this->looksSelfRefHost($host)) {
            return true;
        }
        if ($mailHost !== '' && $host === $mailHost) {
            return true;
        }

        return false;
    }
}
