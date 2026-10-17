<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

final class StalwartManagementAdapter
{
    private const KEY_FILE = '/etc/weline/mail-admin.key';
    private const CREDENTIAL_FILE = '/etc/weline/mail-admin.credential';
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:18080';
    private const CORE_CAPABILITY = 'urn:ietf:params:jmap:core';
    private const MANAGEMENT_CAPABILITY = 'urn:stalwart:jmap';

    public function hasManagementCredential(): bool
    {
        return is_readable(self::KEY_FILE) && is_readable(self::CREDENTIAL_FILE);
    }

    public function createManagementCredentialPayload(
        string $username,
        string $password,
        string $baseUrl = self::DEFAULT_BASE_URL
    ): string {
        $username = trim($username);
        if ($username === '' || strlen($username) > 190) {
            throw new \InvalidArgumentException('Stalwart 管理账号无效。');
        }
        if (strlen($password) < 16) {
            throw new \InvalidArgumentException('Stalwart 管理密码至少需要 16 位。');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = $nonce . sodium_crypto_secretbox($password, $nonce, $this->managementKey());

        return json_encode([
            'version' => 1,
            'username' => $username,
            'base_url' => $this->normalizeLoopbackBaseUrl($baseUrl),
            'sealed_secret' => base64_encode($sealed),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUserObject(
        string $localPart,
        string $domainId,
        string $password,
        int $quotaMb
    ): array {
        $localPart = strtolower(trim($localPart));
        $domainId = trim($domainId);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $localPart)) {
            throw new \InvalidArgumentException('邮箱账号名称格式无效。');
        }
        if ($domainId === '') {
            throw new \InvalidArgumentException('Stalwart 邮箱域名标识无效。');
        }
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('邮箱密码至少需要 12 位。');
        }
        $quotaMb = max(128, min(1048576, $quotaMb));

        return [
            '@type' => 'User',
            'name' => $localPart,
            'domainId' => $domainId,
            'credentials' => (object)[
                '0' => ['@type' => 'Password', 'secret' => $password],
            ],
            'memberGroupIds' => (object)[],
            'roles' => ['@type' => 'User'],
            'permissions' => ['@type' => 'Inherit'],
            'quotas' => (object)['maxDiskQuota' => $quotaMb * 1024 * 1024],
            'aliases' => (object)[],
            'encryptionAtRest' => ['@type' => 'Disabled'],
        ];
    }

    public function accountExists(string $mailbox): bool
    {
        [$localPart, $domainName] = $this->splitMailbox($mailbox);
        $domainId = $this->domainId($domainName);
        return $this->accountId($localPart, $domainId) !== null;
    }

    /**
     * 列出某域在本机 Stalwart 中已存在的账号本地部分（不含 @域）。
     *
     * @return list<string>
     */
    public function listLocalPartsForDomain(string $domainName): array
    {
        $domainName = strtolower(trim($domainName));
        if ($domainName === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domainName)) {
            return [];
        }
        try {
            $domainId = $this->domainId($domainName);
        } catch (\Throwable) {
            return [];
        }
        $result = $this->managementCall('x:Account/query', [
            'filter' => ['domainId' => $domainId],
            'limit' => 200,
        ]);
        $ids = $result['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $idList = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $idList[] = $id;
            }
        }
        if ($idList === []) {
            return [];
        }
        $get = $this->managementCall('x:Account/get', [
            'ids' => $idList,
            'properties' => ['name', 'domainId'],
        ]);
        $list = $get['list'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = strtolower(trim((string)($item['name'] ?? '')));
            if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $name)) {
                continue;
            }
            $out[$name] = $name;
        }

        return array_values($out);
    }

    public function provisionAccount(string $mailbox, string $password, int $quotaMb): string
    {
        [$localPart, $domainName] = $this->splitMailbox($mailbox);
        $domainId = $this->domainId($domainName);
        if ($this->accountId($localPart, $domainId) !== null) {
            throw new \DomainException('该邮箱已存在于 Stalwart。');
        }

        $result = $this->managementCall('x:Account/set', [
            'create' => [
                'mailbox' => $this->buildUserObject($localPart, $domainId, $password, $quotaMb),
            ],
        ]);
        $id = (string)($result['created']['mailbox']['id'] ?? '');
        if ($id === '') {
            $type = (string)($result['notCreated']['mailbox']['type'] ?? '');
            throw new \RuntimeException('Stalwart 邮箱开通失败' . ($type !== '' ? '：' . $type : '。'));
        }
        return $id;
    }

    /**
     * 确保 Stalwart 侧域名存在；已存在则返回其 id。
     */
    public function ensureDomain(string $domainName): string
    {
        $domainName = strtolower(trim($domainName));
        if ($domainName === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domainName)) {
            throw new \InvalidArgumentException('域名格式无效。');
        }
        try {
            return $this->domainId($domainName);
        } catch (\DomainException) {
            // continue create
        }
        $result = $this->managementCall('x:Domain/set', [
            'create' => [
                'domain' => [
                    '@type' => 'Domain',
                    'name' => $domainName,
                ],
            ],
        ]);
        $id = (string)($result['created']['domain']['id'] ?? '');
        if ($id === '') {
            $type = (string)($result['notCreated']['domain']['type'] ?? '');
            throw new \RuntimeException('Stalwart 域名开通失败' . ($type !== '' ? '：' . $type : '。'));
        }

        return $id;
    }

    /**
     * 将域名池/Server 管理的 PEM 导入本机 Stalwart（destroy-by-SAN → create Text → 双 Reload）。
     * 形状钉死为生产已验证会话，禁止 Stalwart 自建 ACME 双轨。
     *
     * @return array{success:bool,hostname:string,cert_id:string,destroyed:list<string>,message:string}
     */
    public function importManagedHostnameCertificate(
        string $hostname,
        string $fullchainPem,
        string $privateKeyPem,
    ): array {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if ($hostname === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $hostname)) {
            throw new \InvalidArgumentException('主机名无效。');
        }
        $fullchainPem = trim($fullchainPem);
        $privateKeyPem = trim($privateKeyPem);
        if ($fullchainPem === '' || $privateKeyPem === ''
            || !str_contains($fullchainPem, 'BEGIN CERTIFICATE')
            || !str_contains($privateKeyPem, 'PRIVATE KEY')
        ) {
            throw new \InvalidArgumentException('证书或私钥 PEM 不完整。');
        }

        $destroy = $this->certificateIdsCoveringHostname($hostname);
        $createKey = preg_replace('/[^a-z0-9]+/', '_', $hostname) ?: 'host';
        $setArgs = [
            'create' => [
                $createKey => [
                    '@type' => 'Certificate',
                    'certificate' => ['@type' => 'Text', 'value' => $fullchainPem],
                    'privateKey' => ['@type' => 'Text', 'secret' => $privateKeyPem],
                ],
            ],
        ];
        if ($destroy !== []) {
            $setArgs['destroy'] = $destroy;
        }

        $setResult = $this->managementCall('x:Certificate/set', $setArgs, 30);
        $certId = (string)($setResult['created'][$createKey]['id'] ?? '');
        if ($certId === '' && isset($setResult['notCreated'][$createKey])) {
            $type = (string)($setResult['notCreated'][$createKey]['type'] ?? '');
            throw new \RuntimeException('Stalwart 证书导入失败' . ($type !== '' ? '：' . $type : '。'));
        }
        if ($certId === '') {
            // 部分版本 create 键回显不同；按 SAN 再查一次
            $ids = $this->certificateIdsCoveringHostname($hostname);
            $certId = (string)($ids[0] ?? '');
        }
        if ($certId === '') {
            throw new \RuntimeException('Stalwart 证书导入后未返回证书 id。');
        }

        $reloadResult = $this->managementCall('x:Action/set', [
            'create' => [
                'reload' => ['@type' => 'ReloadSettings'],
                'tls' => ['@type' => 'ReloadTlsCertificates'],
            ],
        ], 30);
        if (isset($reloadResult['notCreated']['reload']) || isset($reloadResult['notCreated']['tls'])) {
            $detail = (string)(
                ($reloadResult['notCreated']['tls']['type'] ?? null)
                ?? ($reloadResult['notCreated']['reload']['type'] ?? '')
            );
            throw new \RuntimeException('Stalwart 证书 Reload 失败' . ($detail !== '' ? '：' . $detail : '。'));
        }

        return [
            'success' => true,
            'hostname' => $hostname,
            'cert_id' => $certId,
            'destroyed' => $destroy,
            'message' => (string)__('已导入 Stalwart 证书'),
        ];
    }

    /**
     * @return list<string>
     */
    public function certificateIdsCoveringHostname(string $hostname): array
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if ($hostname === '') {
            return [];
        }
        $credential = $this->managementCredential();
        $response = $this->requestJson(
            $credential['base_url'] . '/jmap/',
            $credential['username'],
            $credential['password'],
            [
                'using' => [self::CORE_CAPABILITY, self::MANAGEMENT_CAPABILITY],
                'methodCalls' => [
                    ['x:Certificate/query', ['filter' => null], 'q'],
                    [
                        'x:Certificate/get',
                        [
                            '#ids' => [
                                'resultOf' => 'q',
                                'name' => 'x:Certificate/query',
                                'path' => '/ids',
                            ],
                            'properties' => ['id', 'subjectAlternativeNames'],
                        ],
                        'g',
                    ],
                ],
            ],
            30
        );
        $list = [];
        foreach (($response['methodResponses'] ?? []) as $mr) {
            if (!is_array($mr) || ($mr[0] ?? '') !== 'x:Certificate/get') {
                continue;
            }
            $items = $mr[1]['list'] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $sans = $item['subjectAlternativeNames'] ?? [];
                if ($sans instanceof \stdClass) {
                    $sans = (array)$sans;
                }
                if (!is_array($sans)) {
                    continue;
                }
                $hit = isset($sans[$hostname])
                    || in_array($hostname, array_map('strval', array_keys($sans)), true)
                    || in_array($hostname, array_map('strval', array_values($sans)), true);
                if (!$hit) {
                    continue;
                }
                $id = trim((string)($item['id'] ?? ''));
                if ($id !== '') {
                    $list[] = $id;
                }
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * 外投 Relay/route：落盘审计 + JMAP 写入 MtaRoute / MtaOutboundStrategy，并 ReloadSettings。
     * 中继端口禁止 25（Stalwart Relay 缺省即 25，会直连 MX）；缺省/非法/25 → 587。
     *
     * @param list<array<string, mixed>> $routes
     * @param array<string, mixed> $strategyRoute
     */
    public function applyOutboundRelayRoutes(array $routes, array $strategyRoute): void
    {
        $normalizedRoutes = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $normalizedRoutes[] = $this->normalizeRelayRoute($route);
        }

        $dir = rtrim((string)BP, '/\\') . '/var/mail';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法写入 var/mail 外投路由目录。');
        }
        $payload = [
            'updated_at' => gmdate('c'),
            'routes' => $normalizedRoutes,
            'strategy_route' => $strategyRoute,
        ];
        $path = $dir . '/outbound-relay-routes.json';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($path, $json) === false) {
            throw new \RuntimeException('外投路由载荷落盘失败。');
        }

        foreach ($normalizedRoutes as $index => $route) {
            $this->upsertMtaRelayRoute($route, 'relay-' . $index);
        }

        $strategyResult = $this->managementCall('x:MtaOutboundStrategy/set', [
            'update' => [
                'singleton' => [
                    'route' => $this->normalizeStrategyRouteExpression($strategyRoute),
                ],
            ],
        ]);
        if (isset($strategyResult['notUpdated']['singleton'])) {
            $type = (string)($strategyResult['notUpdated']['singleton']['type'] ?? '');
            throw new \RuntimeException('Stalwart 外投策略更新失败' . ($type !== '' ? '：' . $type : '。'));
        }

        $reloadResult = $this->managementCall('x:Action/set', [
            'create' => [
                'reload' => ['@type' => 'ReloadSettings'],
            ],
        ]);
        if (!isset($reloadResult['created']['reload']) && isset($reloadResult['notCreated']['reload'])) {
            $type = (string)($reloadResult['notCreated']['reload']['type'] ?? '');
            throw new \RuntimeException('Stalwart ReloadSettings 失败' . ($type !== '' ? '：' . $type : '。'));
        }
    }

    /**
     * Stalwart Expression.match 必须是对象 map；PHP list/JSON 数组会 invalidPatch。
     *
     * @param array<string, mixed> $strategyRoute
     * @return array<string, mixed>
     */
    private function normalizeStrategyRouteExpression(array $strategyRoute): array
    {
        $else = (string)($strategyRoute['else'] ?? "'mx'");
        if ($else === '' || $else === 'mx') {
            $else = "'mx'";
        }
        $rawMatch = $strategyRoute['match'] ?? [];
        if ($rawMatch instanceof \stdClass) {
            $rawMatch = (array)$rawMatch;
        }
        if (!is_array($rawMatch)) {
            $rawMatch = [];
        }
        $matchMap = [];
        $i = 0;
        foreach ($rawMatch as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $if = trim((string)($rule['if'] ?? ''));
            $then = trim((string)($rule['then'] ?? ''));
            if ($if === '' || $then === '') {
                continue;
            }
            // 兼容旧两参写法 → 当前 Stalwart 单参。
            if ($if === "is_local_domain('', rcpt_domain)") {
                $if = 'is_local_domain(rcpt_domain)';
            }
            $matchMap[(string)$i] = ['if' => $if, 'then' => $then];
            $i++;
        }

        return [
            'match' => (object)$matchMap,
            'else' => $else,
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    private function normalizeRelayRoute(array $route): array
    {
        $name = trim((string)($route['name'] ?? ''));
        $address = strtolower(trim((string)($route['address'] ?? '')));
        if ($name === '' || $address === '') {
            throw new \InvalidArgumentException('外投 Relay 路由缺少 name/address。');
        }
        $port = (int)($route['port'] ?? 587);
        if ($port < 1 || $port > 65535 || $port === 25) {
            $port = 587;
        }
        $authSecret = $route['authSecret'] ?? ['@type' => 'None'];
        if (!is_array($authSecret)) {
            $authSecret = ['@type' => 'None'];
        }

        return [
            '@type' => 'Relay',
            'name' => $name,
            'address' => $address,
            'port' => $port,
            'protocol' => (string)($route['protocol'] ?? 'smtp') ?: 'smtp',
            'implicitTls' => (bool)($route['implicitTls'] ?? false),
            'allowInvalidCerts' => (bool)($route['allowInvalidCerts'] ?? true),
            'authUsername' => trim((string)($route['authUsername'] ?? '')),
            'authSecret' => $authSecret,
        ];
    }

    /**
     * @param array<string, mixed> $route
     */
    private function upsertMtaRelayRoute(array $route, string $createKey): void
    {
        $name = (string)$route['name'];
        $query = $this->managementCall('x:MtaRoute/query', [
            'filter' => ['name' => $name],
            'limit' => 1,
        ]);
        $existingId = (string)($query['ids'][0] ?? '');
        $fields = [
            'address' => $route['address'],
            'port' => (int)$route['port'],
            'protocol' => $route['protocol'],
            'implicitTls' => (bool)$route['implicitTls'],
            'allowInvalidCerts' => (bool)($route['allowInvalidCerts'] ?? true),
            'authUsername' => $route['authUsername'],
            'authSecret' => $route['authSecret'],
        ];

        if ($existingId !== '') {
            $result = $this->managementCall('x:MtaRoute/set', [
                'update' => [
                    $existingId => $fields,
                ],
            ]);
            if (isset($result['notUpdated'][$existingId])) {
                $type = (string)($result['notUpdated'][$existingId]['type'] ?? '');
                throw new \RuntimeException('Stalwart Relay 路由更新失败（' . $name . '）' . ($type !== '' ? '：' . $type : '。'));
            }
            return;
        }

        $createObject = array_merge(['@type' => 'Relay', 'name' => $name], $fields);
        $result = $this->managementCall('x:MtaRoute/set', [
            'create' => [
                $createKey => $createObject,
            ],
        ]);
        $createdId = (string)($result['created'][$createKey]['id'] ?? '');
        if ($createdId === '') {
            $type = (string)($result['notCreated'][$createKey]['type'] ?? '');
            throw new \RuntimeException('Stalwart Relay 路由创建失败（' . $name . '）' . ($type !== '' ? '：' . $type : '。'));
        }
    }

    public function provisionOrResetAccount(string $mailbox, string $password, int $quotaMb): string
    {
        [$localPart, $domainName] = $this->splitMailbox($mailbox);
        $domainId = $this->domainId($domainName);
        $accountId = $this->accountId($localPart, $domainId);
        if ($accountId === null) {
            return $this->provisionAccount($mailbox, $password, $quotaMb);
        }

        $object = $this->buildUserObject($localPart, $domainId, $password, $quotaMb);
        $result = $this->managementCall('x:Account/set', [
            'update' => [
                $accountId => [
                    'credentials' => $object['credentials'],
                    'quotas' => $object['quotas'],
                ],
            ],
        ]);
        if (isset($result['notUpdated'][$accountId])) {
            $type = (string)($result['notUpdated'][$accountId]['type'] ?? '');
            throw new \RuntimeException('Stalwart 邮箱密码重置失败' . ($type !== '' ? '：' . $type : '。'));
        }
        return $accountId;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitMailbox(string $mailbox): array
    {
        $mailbox = strtolower(trim($mailbox));
        if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('邮箱地址格式不正确。');
        }
        [$localPart, $domain] = explode('@', $mailbox, 2);
        return [$localPart, $domain];
    }

    private function domainId(string $domainName): string
    {
        $result = $this->managementCall('x:Domain/query', [
            'filter' => ['name' => strtolower(trim($domainName))],
            'limit' => 1,
        ]);
        $domainId = (string)($result['ids'][0] ?? '');
        if ($domainId === '') {
            throw new \DomainException('该域名尚未在 Stalwart 中开通。');
        }
        return $domainId;
    }

    private function accountId(string $localPart, string $domainId): ?string
    {
        $result = $this->managementCall('x:Account/query', [
            'filter' => ['name' => $localPart, 'domainId' => $domainId],
            'limit' => 1,
        ]);
        $id = (string)($result['ids'][0] ?? '');
        return $id !== '' ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function managementCall(string $method, array $arguments, int $timeoutSeconds = 12): array
    {
        $credential = $this->managementCredential();
        $response = $this->requestJson(
            $credential['base_url'] . '/jmap/',
            $credential['username'],
            $credential['password'],
            [
                'using' => [self::CORE_CAPABILITY, self::MANAGEMENT_CAPABILITY],
                'methodCalls' => [[$method, $arguments, 'management']],
            ],
            $timeoutSeconds
        );
        $methodResponse = $response['methodResponses'][0] ?? null;
        if (!is_array($methodResponse) || !isset($methodResponse[0], $methodResponse[1])) {
            throw new \RuntimeException('Stalwart 管理接口返回格式无效。');
        }
        if ((string)$methodResponse[0] === 'error') {
            $type = is_array($methodResponse[1]) ? (string)($methodResponse[1]['type'] ?? '') : '';
            throw new \RuntimeException('Stalwart 管理操作失败' . ($type !== '' ? '：' . $type : '。'));
        }
        if ((string)$methodResponse[0] !== $method || !is_array($methodResponse[1])) {
            throw new \RuntimeException('Stalwart 管理接口响应不匹配。');
        }
        return $methodResponse[1];
    }

    /**
     * @return array{username:string,password:string,base_url:string}
     */
    private function managementCredential(): array
    {
        if (!$this->hasManagementCredential()) {
            throw new \RuntimeException('Stalwart 管理凭据尚未配置。');
        }
        $raw = file_get_contents(self::CREDENTIAL_FILE);
        try {
            $credential = json_decode((string)$raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Stalwart 管理凭据格式无效。');
        }
        $username = trim((string)($credential['username'] ?? ''));
        $sealed = base64_decode((string)($credential['sealed_secret'] ?? ''), true);
        if ($username === '' || !is_string($sealed) || strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stalwart 管理凭据内容无效。');
        }
        $nonce = substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $password = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->managementKey());
        if (!is_string($password) || $password === '') {
            throw new \RuntimeException('Stalwart 管理凭据无法解密。');
        }
        return [
            'username' => $username,
            'password' => $password,
            'base_url' => $this->normalizeLoopbackBaseUrl((string)($credential['base_url'] ?? '')),
        ];
    }

    private function managementKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }
        $raw = file_get_contents(self::KEY_FILE);
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Stalwart 管理密钥无效。');
        }
        return $key;
    }

    private function normalizeLoopbackBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            throw new \RuntimeException('Stalwart 管理地址无效。');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string)($parts['path'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)
            || !in_array($host, ['127.0.0.1', '::1'], true)
            || $port < 1 || $port > 65535
            || ($path !== '' && $path !== '/')
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('Stalwart 管理地址必须使用本机回环地址。');
        }
        $displayHost = $host === '::1' ? '[::1]' : $host;
        return $scheme . '://' . $displayHost . ':' . $port;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(
        string $url,
        string $username,
        string $password,
        array $payload,
        int $timeoutSeconds = 12,
    ): array {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!is_array($parts)
            || !in_array($host, ['127.0.0.1', '::1'], true)
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new \RuntimeException('Stalwart 管理请求被限制为本机回环地址。');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL 扩展不可用。');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('无法初始化 Stalwart 管理请求。');
        }
        $timeoutSeconds = max(3, min(60, $timeoutSeconds));
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Stalwart 管理认证或请求失败（HTTP ' . $status . '）。');
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Stalwart 管理接口返回了无效数据。');
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Stalwart 管理接口返回格式无效。');
        }
        return $decoded;
    }
}
