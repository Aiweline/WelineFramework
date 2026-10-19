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
 * 2) 有收件域/账号分流 → 走该分流 SMTP（显式规则，不是兜底）
 * 3) 无分流 → 本域名 Smtp（跨机可达的 self/域名中继 587/465）
 * 4) 再没有 → 才兜底 mx（邮局本机 :25 直投）
 *
 * QQ/Hub 不得作全局 else，也不得用 retry_num 顶替 :25。
 * QQ 仅允许 sender==授权用户 的显式绑定。本域 mail./smtp. 禁止无认证自环。
 *
 * not_to_do：QQ/Hub 作全局 else；retry→Hub 当 :25 兜底；本域 submission 无认证自环；中继端口写 25。
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
        try {
            $built = $this->buildPayload();
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__('外投路由载荷构建失败：%{1}', [$e->getMessage()]),
                'applied' => false,
                'self_ref_warning' => false,
                'routes' => [],
                'strategy_route' => [],
            ];
        }
        $selfRef = !empty($built['self_ref_warning']);
        /** @var StalwartManagementAdapter $adapter */
        $adapter = ObjectManager::getInstance(StalwartManagementAdapter::class);
        if (!$adapter->hasManagementCredential()) {
            // 无引擎管理凭据时：若载荷里已有需下发的 Relay，不得假成功（一键/导流保存会吃这个坑）。
            if ($built['routes'] !== []) {
                return [
                    'success' => false,
                    'message' => (string)__('有外投/Hub 导流配置，但 Stalwart 管理凭据未就绪，无法下发'),
                    'applied' => false,
                    'self_ref_warning' => $selfRef,
                    'routes' => $built['routes'],
                    'strategy_route' => $built['strategy_route'],
                ];
            }

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

        // 一键/同步后探针：带账号的中继必须 AUTH 成功，否则 Hub 密码错也会假成功。
        try {
            $this->assertAuthenticatedRelaysReachable($built['routes']);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__(
                    '外投路由已写入引擎，但中继认证探针失败：%{1}',
                    [$e->getMessage()]
                ),
                'applied' => true,
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
     * @param list<array<string, mixed>> $routes
     */
    private function assertAuthenticatedRelaysReachable(array $routes): void
    {
        $seen = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $host = strtolower(trim((string)($route['address'] ?? '')));
            $port = (int)($route['port'] ?? 0);
            $user = trim((string)($route['authUsername'] ?? ''));
            $secret = '';
            $authSecret = $route['authSecret'] ?? null;
            if (is_array($authSecret) && (($authSecret['@type'] ?? '') === 'Value')) {
                $secret = (string)($authSecret['secret'] ?? '');
            }
            if ($host === '' || $user === '' || $secret === '') {
                continue;
            }
            $port = $this->normalizeSubmissionPort($port);
            $key = $host . '|' . $port . '|' . strtolower($user);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->probeSmtpAuthLogin($host, $port, $user, $secret);
        }
    }

    private function probeSmtpAuthLogin(string $host, int $port, string $user, string $password): void
    {
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            8.0
        );
        if (!is_resource($fp)) {
            throw new \RuntimeException(
                (string)__('无法连接中继 %{1}:%{2}（%{3}）', [$host, (string)$port, $errstr !== '' ? $errstr : (string)$errno])
            );
        }
        stream_set_timeout($fp, 10);
        $read = static function ($fp): string {
            $data = '';
            while (($line = fgets($fp, 512)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }

            return $data;
        };
        try {
            $read($fp);
            fwrite($fp, "EHLO weline-relay-probe\r\n");
            $read($fp);
            if ($port !== 465) {
                fwrite($fp, "STARTTLS\r\n");
                $tlsResp = $read($fp);
                if (!str_starts_with(trim($tlsResp), '220')) {
                    throw new \RuntimeException((string)__('中继 %{1} STARTTLS 失败', [$host]));
                }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException((string)__('中继 %{1} TLS 握手失败', [$host]));
                }
                fwrite($fp, "EHLO weline-relay-probe\r\n");
                $read($fp);
            }
            fwrite($fp, "AUTH LOGIN\r\n");
            $auth = trim($read($fp));
            if (!str_starts_with($auth, '334')) {
                throw new \RuntimeException((string)__('中继 %{1} 不接受 AUTH LOGIN：%{2}', [$host, $auth]));
            }
            fwrite($fp, base64_encode($user) . "\r\n");
            $read($fp);
            fwrite($fp, base64_encode($password) . "\r\n");
            $passResp = trim($read($fp));
            if (!str_starts_with($passResp, '235')) {
                throw new \RuntimeException(
                    (string)__(
                        '中继 %{1} 用户 %{2} 认证失败（%{3}）。请确认 Hub 上该账号密码与导流密封一致。',
                        [$host, $user, $passResp]
                    )
                );
            }
            fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    /**
     * 解析中继认证密码（一键/同步必须带上库内 Hub，禁止静默空 authSecret）。
     *
     * 顺序：密封 open → 旧钥解开则重封回库 → 解不开/无密封时，若用户名是邮箱则回退同址
     * MailAccount.password_sealed（Hub 提交口令通常=邮箱口令）并重封写回中继列。
     *
     * @param callable(string $resealed): void|null $persistSealed
     */
    private function resolveRelayAuthPassword(
        MailOutboundRelaySecretService $secrets,
        string $sealed,
        string $username,
        string $contextLabel,
        ?callable $persistSealed = null
    ): string {
        $sealed = trim($sealed);
        $username = trim($username);
        $password = '';
        $openedFromSealed = false;

        if ($sealed !== '') {
            try {
                $password = $secrets->open($sealed);
                $openedFromSealed = $password !== '';
                $this->persistPendingReseal($secrets, $persistSealed);
            } catch (\Throwable) {
                $password = '';
                $openedFromSealed = false;
            }
        }

        if ($password === '' && $username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $fallback = $this->openMailAccountPassword($secrets, strtolower($username));
            if ($fallback !== '') {
                $password = $fallback;
                try {
                    $resealed = $secrets->seal($password);
                    if ($persistSealed !== null) {
                        $persistSealed($resealed);
                    }
                } catch (\Throwable) {
                    // 下发仍可用明文；重封失败不阻断一键。
                }
            }
        }

        if ($password !== '') {
            return $password;
        }

        if ($sealed !== '' && !$openedFromSealed) {
            throw new \RuntimeException(
                (string)__(
                    '%{1}：中继密码无法解密，且未能用同址邮箱账户密码回退。请在后台重新填写 Hub/中继密码并保存后再一键配置。',
                    [$contextLabel]
                )
            );
        }

        return '';
    }

    /**
     * 旧钥解开后的项目钥重封写回库。
     *
     * @param callable(string $resealed): void|null $persistSealed
     */
    private function persistPendingReseal(
        MailOutboundRelaySecretService $secrets,
        ?callable $persistSealed
    ): void {
        $resealed = $secrets->consumePendingReseal();
        if ($resealed === null || $resealed === '' || $persistSealed === null) {
            return;
        }
        try {
            $persistSealed($resealed);
        } catch (\Throwable) {
            // 重封写库失败不阻断下发。
        }
    }

    private function openMailAccountPassword(
        MailOutboundRelaySecretService $secrets,
        string $email
    ): string {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        try {
            /** @var MailAccount $account */
            $account = ObjectManager::getInstance(MailAccount::class)
                ->clear()
                ->where(MailAccount::schema_fields_EMAIL, $email)
                ->find()
                ->fetch();
            if (!$account instanceof MailAccount || !(int)$account->getId()) {
                return '';
            }
            $sealed = trim((string)$account->getData(MailAccount::schema_fields_PASSWORD_SEALED));
            if ($sealed === '') {
                return '';
            }

            return $secrets->open($sealed);
        } catch (\Throwable) {
            return '';
        }
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
        // else 只保留 buildPayload 结果（域名 Smtp 或 mx）；禁止用 Hub/QQ 顶替 :25 兜底。
        $built['strategy_route']['else'] = $this->preferConfiguredHubOverMxElse(
            (string)($built['strategy_route']['else'] ?? "'mx'"),
            $existingHubs
        );

        return $built;
    }

    /**
     * 规范化 else：保留 buildPayload 的域名 Smtp / mx。
     * 禁止把 else 抬成任何 weline-hub-*（含 QQ）——Hub/QQ 只能出现在显式导流或 sender 绑定，不得作 :25 兜底。
     *
     * @param list<string> $existingHubs 保留参数以兼容调用方；故意不用于抬 else。
     */
    private function preferConfiguredHubOverMxElse(string $else, array $existingHubs): string
    {
        unset($existingHubs);
        $else = trim($else);
        if ($else === '' || $else === 'mx') {
            return "'mx'";
        }
        // 防御：历史策略若已是 hub/qq else，强制回退 mx（下次 sync 清掉窜 From 路径）。
        $bare = $else;
        if ($bare !== '' && $bare[0] === "'" && str_ends_with($bare, "'")) {
            $bare = substr($bare, 1, -1);
        }
        if (str_starts_with($bare, 'weline-hub-')) {
            return "'mx'";
        }

        return $else;
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
            $ruleId = (int)($rule[MailOutboundRouteRule::schema_fields_ID]
                ?? $rule['rule_id']
                ?? 0);
            // 库内 Hub 导流：密封优先；解不开则回退同址邮箱口令并重封（一键不得丢分流）。
            $password = $this->resolveRelayAuthPassword(
                $secrets,
                $sealed,
                $user,
                (string)__('收件域导流 %{1} → %{2}', [$matchDomain, $host]),
                $ruleId > 0
                    ? static function (string $resealed) use ($ruleId): void {
                        $m = ObjectManager::getInstance(MailOutboundRouteRule::class)->clear()->load($ruleId);
                        if ((int)$m->getId() === $ruleId) {
                            $m->setData(MailOutboundRouteRule::schema_fields_RELAY_PASSWORD_SEALED, $resealed)
                                ->save();
                        }
                    }
                    : null
            );
            if ($user !== '' && $password === '') {
                throw new \RuntimeException(
                    (string)__(
                        '收件域导流 %{1} → %{2} 需要中继密码，但库中无可用密封/账户密码；请在后台重新填写并保存。',
                        [$matchDomain, $host]
                    )
                );
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
            $sealedFromDomain = false;

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
                    $sealedFromDomain = $sealed !== '';
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

            $authUser = $user !== '' ? $user : $email;
            $password = $this->resolveRelayAuthPassword(
                $secrets,
                $sealed,
                $authUser,
                (string)__('账号外投 %{1} → %{2}', [$email, $host]),
                static function (string $resealed) use ($accountId, $domainId, $sealedFromDomain): void {
                    if ($sealedFromDomain && $domainId > 0) {
                        $d = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
                        if ((int)$d->getId() === $domainId) {
                            $d->setData(MailDomain::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED, $resealed)
                                ->save();
                        }

                        return;
                    }
                    $a = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
                    if ((int)$a->getId() === $accountId) {
                        $a->setData(MailAccount::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED, $resealed)
                            ->save();
                    }
                }
            );
            if ($authUser !== '' && $password === '') {
                throw new \RuntimeException(
                    (string)__(
                        '账号外投 %{1} → %{2} 需要中继密码，但库中无可用密封/账户密码；请在后台重新填写并保存。',
                        [$email, $host]
                    )
                );
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
                $authUser,
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

        // A1：local → 导流规则（rcpt / sender）→ 账号外投。禁止 retry→Hub 顶替 :25 兜底。
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

        // A2：无导流匹配时走可达跨机 Smtp；本域 submission 不作自环。都没有 → mx（25）。
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
     * 跨机域名 Smtp：active 域 hostname 指向远端中继时 → sender_domain 走 587/465。
     * 本域 mail./smtp. submission（消息已在此机提交）禁止无认证自环 Relay（会 503 永久失败）。
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
            if ($this->isOwnDomainSubmissionHost($host, $domainLookup) || $this->isLocalOutboundHost($host)) {
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
     * 默认 else：仅跨机、带认证的 Smtp self 可作 Relay；本域 smtp.{域} 已是提交入口，出站应 mx。
     * 禁止 MODE_EXTERNAL Hub 抬成全局 else（非 qq 后缀会进 Hub，From 硬成 QQ）。
     *
     * @param array<int, array<string, mixed>> $domainLookup
     * @return array{host:string,port:int,user:string,tls:string,password:string}|null
     */
    private function resolveDefaultSelfSmtpRelay(array $domainLookup): ?array
    {
        try {
            /** @var SmtpServiceCatalogService $catalog */
            $catalog = ObjectManager::getInstance(SmtpServiceCatalogService::class);
            /** @var MailOutboundRelaySecretService $secrets */
            $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
            foreach ($catalog->listServices() as $svc) {
                if (!$svc instanceof SmtpService) {
                    continue;
                }
                if ((string)$svc->getData(SmtpService::schema_fields_STATUS) !== 'active') {
                    continue;
                }
                $mode = (string)$svc->getData(SmtpService::schema_fields_MODE);
                if ($mode !== SmtpService::MODE_SELF) {
                    continue;
                }
                $host = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
                if ($host === ''
                    || $this->isOwnDomainSubmissionHost($host, $domainLookup)
                    || $this->isLocalOutboundHost($host)
                ) {
                    continue;
                }
                $user = trim((string)$svc->getData(SmtpService::schema_fields_USERNAME));
                $sealed = trim((string)$svc->getData(SmtpService::schema_fields_PASSWORD_SEALED));
                // 无认证跨机 self 会 503；本域 submission 已在上方跳过。
                if ($user === '') {
                    continue;
                }
                $password = '';
                try {
                    $password = $this->resolveRelayAuthPassword(
                        $secrets,
                        $sealed,
                        $user,
                        (string)__('Smtp self else %{1}', [$host]),
                        null
                    );
                } catch (\Throwable) {
                    $password = '';
                }
                if ($password === '') {
                    continue;
                }
                $submission = $this->resolveReachableSubmissionEndpoint($host);
                if ($submission === null) {
                    continue;
                }

                return [
                    'host' => $host,
                    'port' => $submission['port'],
                    'user' => $user,
                    'tls' => $submission['tls'],
                    'password' => $password,
                ];
            }
        } catch (\Throwable) {
            // Smtp 模块不可用：无跨机带认证 self 则 else=mx。
        }

        return null;
    }

    /**
     * 本域 submission 主机：mail.{域} / smtp.{域} / MailDomain.hostname——消息已在此提交，出站应 mx，禁止自环 Relay。
     *
     * @param array<int, array<string, mixed>> $domainLookup
     */
    private function isOwnDomainSubmissionHost(string $host, array $domainLookup): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        foreach ($domainLookup as $domain) {
            if ((string)($domain[MailDomain::schema_fields_STATUS] ?? $domain['status'] ?? '') !== 'active') {
                continue;
            }
            $domainName = strtolower(trim((string)($domain[MailDomain::schema_fields_DOMAIN_NAME]
                ?? $domain['domain_name']
                ?? '')));
            $hostname = strtolower(trim((string)($domain[MailDomain::schema_fields_HOSTNAME]
                ?? $domain['hostname']
                ?? '')));
            if ($domainName === '') {
                continue;
            }
            if ($host === $hostname
                || $host === 'mail.' . $domainName
                || $host === 'smtp.' . $domainName
            ) {
                return true;
            }
        }

        return false;
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
