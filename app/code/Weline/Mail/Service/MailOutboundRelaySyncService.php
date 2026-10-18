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
 * 优先级（用户拍板）：
 * 1) local：本域收件进本地投递
 * 2) 导流规则 → 具体 Smtp 账户（收件域规则 / 账号外投 / hub 绑定；端口 587/465，禁 25）
 * 3) 无匹配规则 → 自家域名 Smtp（MailDomain.hostname / Smtp self，587/465）
 * 4) 都没有 → 才兜底 mx（25）
 *
 * 边缘站（汉服）：导流/账号外投指向 mail.aiweline.com hub——跨机中继，不是自环。
 * Hub 站（weline）：本机 hostname 不可作 else（会自环）；无可达域名 Smtp 时 else 可升为已配置
 * weline-hub-*（如 QQ:465）作云主机最终出站；再没有才 mx:25。
 *
 * not_to_do：边缘站把跨机 hub 误判自环；有可达域名 Smtp/导流却走 mx；中继端口写 25。
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

        // QQ SMTP 硬约束：MAIL FROM 必须等于授权用户；禁止 rcpt_domain==qq.com 无条件导流
        // （contact@外域 → smtp.qq.com 会 501；已发送≠已投递）。
        if (isset($hubSet['weline-hub-qq-smtp'])) {
            $qqAuth = strtolower($adapter->getRelayAuthUsername('weline-hub-qq-smtp'));
            if ($qqAuth !== '' && filter_var($qqAuth, FILTER_VALIDATE_EMAIL)) {
                unset($bindings['qq.com']);
                $bindings['sender:' . $qqAuth] = 'weline-hub-qq-smtp';
                $this->saveHubBindings($bindings);
            }
        }

        // 绑定表：domain → route；或 sender:email → route（QQ 等强制 From=auth 的中继）。
        foreach ($bindings as $key => $routeName) {
            $routeName = trim((string)$routeName);
            $key = trim((string)$key);
            if ($routeName === '' || $key === '') {
                continue;
            }
            if ($existingHubs !== [] && !isset($hubSet[$routeName])) {
                continue;
            }
            if (str_starts_with($key, 'sender:')) {
                $email = strtolower(trim(substr($key, 7)));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $if = "sender == '" . addcslashes($email, "'\\") . "'";
            } else {
                $domain = $this->normalizeMatchDomain($key);
                if ($domain === '') {
                    continue;
                }
                if ($domain === 'qq.com' && $routeName === 'weline-hub-qq-smtp') {
                    continue;
                }
                $if = "rcpt_domain == '" . addcslashes($domain, "'\\") . "'";
            }
            if (isset($coveredIf[$if])) {
                continue;
            }
            $match[(string)count($match)] = [
                'if' => $if,
                'then' => "'" . addcslashes($routeName, "'\\") . "'",
            ];
            $coveredIf[$if] = true;
        }

        $built['strategy_route']['match'] = (object)$match;
        // 本机已有 weline-hub-*（Hub 站）：无可达域名 Smtp 时 else 从 mx 升为 hub 出站（边缘站通常无此 hub）。
        $built['strategy_route']['else'] = $this->preferConfiguredHubOverMxElse(
            (string)($built['strategy_route']['else'] ?? "'mx'"),
            $existingHubs
        );

        return $built;
    }

    /**
     * Hub 站最终出站：else 仍是 mx 且已配置 weline-hub-* → 改用 hub（587/465），避免云拦 :25。
     * 边缘站指向 mail.aiweline.com 走导流/账号外投/域名 Smtp，不靠本函数。
     *
     * @param list<string> $existingHubs
     */
    private function preferConfiguredHubOverMxElse(string $else, array $existingHubs): string
    {
        $else = trim($else);
        if ($else === '' || $else === 'mx') {
            $else = "'mx'";
        }
        if ($else !== "'mx'" || $existingHubs === []) {
            return $else;
        }
        $preferred = '';
        foreach ($existingHubs as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            if ($name === 'weline-hub-qq-smtp') {
                return "'" . addcslashes($name, "'\\") . "'";
            }
            if ($preferred === '') {
                $preferred = $name;
            }
        }
        if ($preferred === '') {
            return "'mx'";
        }

        return "'" . addcslashes($preferred, "'\\") . "'";
    }

    /**
     * @return array<string, string> match_domain|sender:email => route_name
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
        foreach ($raw as $key => $routeName) {
            $key = trim((string)$key);
            $routeName = trim((string)$routeName);
            if ($routeName === '' || $key === '') {
                continue;
            }
            if (str_starts_with($key, 'sender:')) {
                $email = strtolower(trim(substr($key, 7)));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $out['sender:' . $email] = $routeName;
                }
                continue;
            }
            $domain = $this->normalizeMatchDomain($key);
            if ($domain !== '') {
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
        foreach ($bindings as $key => $routeName) {
            $key = trim((string)$key);
            $routeName = trim((string)$routeName);
            if ($routeName === '' || $key === '') {
                continue;
            }
            if (str_starts_with($key, 'sender:')) {
                $email = strtolower(trim(substr($key, 7)));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $clean['sender:' . $email] = $routeName;
                }
                continue;
            }
            $domain = $this->normalizeMatchDomain($key);
            if ($domain !== '') {
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

        // A1：local → 导流规则（rcpt / sender）→ 再挂自家域名 Smtp（按 sender_domain）
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

        // A2：无导流匹配时走可达的域名/外部 Smtp（587/465）。本机 hostname≠自环目标；
        // 跨机 hub（如 mail.aiweline.com）算合法 else。都不可达才 mx（25）。
        $else = "'mx'";
        $domainSmtpMatches = $this->buildDomainSmtpSenderMatches($domainLookup, $routeByKey, $selfRef);
        foreach ($domainSmtpMatches as $rule) {
            $match[] = $rule;
        }
        $selfSmtp = $this->resolveDefaultSelfSmtpRelay($domainLookup);
        if ($selfSmtp !== null) {
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
     * 自家域名 Smtp：每个 active 域 hostname（非本机）→ sender_domain == 该域 走 587/465。
     *
     * @param array<int, array<string, mixed>> $domainLookup
     * @param array<string, array{route_name: string, relay: array<string, mixed>}> $routeByKey
     * @return list<array{if:string,then:string}>
     */
    private function buildDomainSmtpSenderMatches(array $domainLookup, array &$routeByKey, bool &$selfRef): array
    {
        $out = [];
        $seenDomain = [];
        foreach ($domainLookup as $domain) {
            if ((string)($domain[MailDomain::schema_fields_STATUS] ?? $domain['status'] ?? '') !== 'active') {
                continue;
            }
            $domainName = strtolower(trim((string)($domain[MailDomain::schema_fields_DOMAIN_NAME]
                ?? $domain['domain_name']
                ?? '')));
            if ($domainName === '' || isset($seenDomain[$domainName])) {
                continue;
            }
            $host = strtolower(trim((string)($domain[MailDomain::schema_fields_HOSTNAME]
                ?? $domain['hostname']
                ?? '')));
            if ($host === '') {
                $host = 'mail.' . $domainName;
            }
            if ($this->isLocalOutboundHost($host)) {
                $selfRef = true;
                continue;
            }
            $seenDomain[$domainName] = true;
            $submission = $this->resolveReachableSubmissionEndpoint($host);
            if ($submission === null) {
                continue;
            }
            $routeName = $this->ensureRelayRoute(
                $routeByKey,
                $host,
                $submission['port'],
                '',
                $submission['tls'],
                '',
                'weline-outbound-domain-'
            );
            $out[] = [
                'if' => "sender_domain == '" . addcslashes($domainName, "'\\") . "'",
                'then' => "'" . $routeName . "'",
            ];
        }

        return $out;
    }

    /**
     * 本机 Smtp 默认外投：优先 Smtp 目录 self 服务；否则首个非本机 MailDomain.hostname。
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
            $fallbackExternal = null;
            foreach ($catalog->listServices() as $svc) {
                if (!$svc instanceof SmtpService) {
                    continue;
                }
                if ((string)$svc->getData(SmtpService::schema_fields_STATUS) !== 'active') {
                    continue;
                }
                $mode = (string)$svc->getData(SmtpService::schema_fields_MODE);
                $host = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
                if ($host === '' || $this->isLocalOutboundHost($host)) {
                    continue;
                }
                $submission = $this->resolveReachableSubmissionEndpoint($host);
                if ($submission === null) {
                    continue;
                }
                $candidate = [
                    'host' => $host,
                    'port' => $submission['port'],
                    'user' => trim((string)$svc->getData(SmtpService::schema_fields_USERNAME)),
                    'tls' => $submission['tls'],
                    'password' => '',
                ];
                // 优先本机 Smtp 目录里的「非本机」self；否则用 external hub（如 mail.aiweline.com）。
                if ($mode === SmtpService::MODE_SELF) {
                    return $candidate;
                }
                if ($fallbackExternal === null && $mode === SmtpService::MODE_EXTERNAL) {
                    $fallbackExternal = $candidate;
                }
            }
            if ($fallbackExternal !== null) {
                return $fallbackExternal;
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
            if ($this->isLocalOutboundHost($host)) {
                continue;
            }
            $submission = $this->resolveReachableSubmissionEndpoint($host);
            if ($submission === null) {
                continue;
            }

            return [
                'host' => $host,
                'port' => $submission['port'],
                'user' => '',
                'tls' => $submission['tls'],
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
     * 外投目标是否本机：回环、本机网卡 IP、或与本机公开 mail 主机（STALWART_PUBLIC_URL）同 A 记录。
     * 禁止「凡 MailDomain.hostname 一律自指」——边缘站 hostname 对 hub 是远端中继。
     */
    private function isLocalOutboundHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $this->looksSelfRefHost($host)) {
            return true;
        }
        $localIps = $this->localIdentityIpv4s();
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return isset($localIps[$host]);
        }
        foreach ($this->resolveIpv4s($host) as $ip) {
            if (isset($localIps[$ip])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function localIdentityIpv4s(): array
    {
        static $localIps = null;
        if ($localIps !== null) {
            return $localIps;
        }
        $localIps = ['127.0.0.1' => true];
        foreach (@net_get_interfaces() ?: [] as $iface) {
            foreach (($iface['unicast'] ?? []) as $addr) {
                $candidate = (string)($addr['address'] ?? '');
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $localIps[$candidate] = true;
                }
            }
        }
        foreach ($this->resolveIpv4s($this->publicMailHostname()) as $ip) {
            $localIps[$ip] = true;
        }

        return $localIps;
    }

    private function publicMailHostname(): string
    {
        $candidates = [];
        $envFile = '/etc/stalwart/stalwart.env';
        if (is_readable($envFile)) {
            $raw = (string)@file_get_contents($envFile);
            if (preg_match('/^STALWART_PUBLIC_URL=(.+)$/m', $raw, $m)) {
                $candidates[] = trim($m[1], " \t\"'");
            }
        }
        $envUrl = (string)(getenv('STALWART_PUBLIC_URL') ?: '');
        if ($envUrl !== '') {
            $candidates[] = $envUrl;
        }
        foreach ($candidates as $url) {
            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
            if ($host !== '') {
                return $host;
            }
        }

        return 'mail.aiweline.com';
    }

    /**
     * @return list<string>
     */
    private function resolveIpv4s(string $host): array
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$host];
        }
        $out = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $row) {
            $ip = (string)($row['ip'] ?? '');
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $out[] = $ip;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 探测域名 Smtp 可达提交口：587/starttls 优先，否则 465/smtps。
     *
     * @return array{port:int,tls:string}|null
     */
    private function resolveReachableSubmissionEndpoint(string $host): ?array
    {
        if ($this->tcpPortOpen($host, 587)) {
            return ['port' => 587, 'tls' => 'starttls'];
        }
        if ($this->tcpPortOpen($host, 465)) {
            return ['port' => 465, 'tls' => 'smtps'];
        }

        return null;
    }

    private function tcpPortOpen(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 3.0);
        if (!is_resource($fp)) {
            return false;
        }
        fclose($fp);

        return true;
    }

    private function looksSelfRef(string $host, string $mailHost, string $domainName): bool
    {
        unset($domainName, $mailHost);

        return $this->isLocalOutboundHost($host);
    }
}
