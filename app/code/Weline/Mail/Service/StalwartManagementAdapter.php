<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailDomain;

final class StalwartManagementAdapter
{
    /** @deprecated 保留字面量供契约/旧引用；读写请走 resolve* 双路径 */
    private const KEY_FILE = '/etc/weline/mail-admin.key';
    /** @deprecated 保留字面量供契约/旧引用；读写请走 resolve* 双路径 */
    private const CREDENTIAL_FILE = '/etc/weline/mail-admin.credential';
    private const ETC_KEY_FILE = '/etc/weline/mail-admin.key';
    private const ETC_CREDENTIAL_FILE = '/etc/weline/mail-admin.credential';
    private const VAR_KEY_REL = 'var/mail/mail-admin.key';
    private const VAR_CREDENTIAL_REL = 'var/mail/mail-admin.credential';
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:18080';
    private const CORE_CAPABILITY = 'urn:ietf:params:jmap:core';
    private const MANAGEMENT_CAPABILITY = 'urn:stalwart:jmap';

    public function hasManagementCredential(): bool
    {
        return $this->resolveReadableCredentialPair() !== null;
    }

    public function createManagementCredentialPayload(
        string $username,
        string $password,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $keyBytes = null
    ): string {
        $username = trim($username);
        if ($username === '' || strlen($username) > 190) {
            throw new \InvalidArgumentException('Stalwart 管理账号无效。');
        }
        if (strlen($password) < 16) {
            throw new \InvalidArgumentException('Stalwart 管理密码至少需要 16 位。');
        }

        $key = $keyBytes ?? $this->managementKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = $nonce . sodium_crypto_secretbox($password, $nonce, $key);

        return json_encode([
            'version' => 1,
            'username' => $username,
            'base_url' => $this->normalizeLoopbackBaseUrl($baseUrl),
            'sealed_secret' => base64_encode($sealed),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 写入本机 mail-admin 管理凭据（/etc 与 var 双路径；优先可写目录）。
     * 对齐 StalwartEngineAdapter::upsertReaderCredential。
     */
    public function upsertManagementCredential(
        string $username,
        string $password,
        string $baseUrl = self::DEFAULT_BASE_URL
    ): void {
        $pair = $this->resolveWritableCredentialPair();
        $key = $this->ensureManagementKeyAt($pair['key']);
        $payload = $this->createManagementCredentialPayload($username, $password, $baseUrl, $key);
        $credDir = dirname($pair['credential']);
        if (!is_dir($credDir) && !@mkdir($credDir, 0700, true) && !is_dir($credDir)) {
            throw new \RuntimeException('无法创建 Stalwart 管理凭据目录。');
        }
        if (@file_put_contents($pair['credential'], $payload) === false) {
            throw new \RuntimeException('无法写入 Stalwart 管理凭据。');
        }
        @chmod($pair['credential'], 0600);
        // 目录保持可遍历，便于同机 Web SAPI / WLS worker（同用户）is_readable
        @chmod(dirname($pair['credential']), 0750);
        @chmod($pair['key'], 0600);
    }

    /**
     * 轻量探测管理 API（绑定后验收；须已出 bootstrap）。
     */
    public function probeManagementApi(): void
    {
        $this->managementCall('x:Domain/query', ['limit' => 1]);
    }

    /**
     * 认证是否已通（recovery 生效）。bootstrap 中 Domain 仍 forbidden，但 Bootstrap/get 可用。
     */
    public function probeManagementAuth(): void
    {
        try {
            $this->probeManagementApi();

            return;
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, '401') || str_contains($msg, '认证')) {
                throw $e;
            }
        }
        $this->managementCall('x:Bootstrap/get', []);
    }

    /**
     * 若仍在 bootstrap：用本机 /opt/stalwart 路径提交 Bootstrap/set。
     * 返回 true 表示刚提交、通常需要重启服务后再 probe Domain。
     */
    public function completeBootstrapIfNeeded(): bool
    {
        try {
            $this->probeManagementApi();

            return false;
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (!str_contains($msg, 'forbidden') && !str_contains($msg, 'bootstrap')) {
                throw $e;
            }
        }

        $installDir = '/opt/stalwart';
        $dataPath = $installDir . '/data/';
        $logPath = $installDir . '/logs/';
        // Stalwart 0.16 拒绝无点主机名（如云厂商短 hostname）；须 FQDN。
        [$serverHostname, $defaultDomain] = $this->resolveBootstrapHostnames();

        $result = $this->managementCall('x:Bootstrap/set', [
            'update' => [
                'singleton' => [
                    '@type' => 'Bootstrap',
                    'serverHostname' => $serverHostname,
                    'defaultDomain' => $defaultDomain,
                    'requestTlsCertificate' => false,
                    'generateDkimKeys' => true,
                    'dataStore' => [
                        '@type' => 'RocksDb',
                        'path' => $dataPath,
                        'blobSize' => 16834,
                        'bufferSize' => 134217728,
                        'poolWorkers' => null,
                        'cacheSize' => 134217728,
                    ],
                    'blobStore' => ['@type' => 'Default'],
                    'searchStore' => ['@type' => 'Default'],
                    'inMemoryStore' => ['@type' => 'Default'],
                    'directory' => ['@type' => 'Internal'],
                    'tracer' => [
                        '@type' => 'Log',
                        'path' => $logPath,
                        'prefix' => 'stalwart',
                        'rotate' => 'daily',
                        'ansi' => true,
                        'multiline' => false,
                        'enable' => true,
                        'level' => 'info',
                        'lossy' => false,
                        'events' => (object)[],
                        'eventsPolicy' => 'exclude',
                    ],
                    'dnsServer' => ['@type' => 'Manual'],
                ],
            ],
        ], 30);

        if (!isset($result['updated']['singleton']) && !isset($result['updated'])) {
            $err = $result['notUpdated']['singleton']['description']
                ?? $result['notUpdated']['singleton']['type']
                ?? '';
            throw new \RuntimeException(
                'Stalwart Bootstrap 提交失败' . ($err !== '' ? '：' . (string)$err : '。')
            );
        }

        return true;
    }

    /**
     * Bootstrap 用 FQDN：优先库内真实邮域，其次 env public_host/ssl_domain，最后仅当本机名含点才用。
     *
     * @return array{0:string,1:string} [serverHostname, defaultDomain]
     */
    private function resolveBootstrapHostnames(): array
    {
        $defaultDomain = $this->resolveBootstrapDefaultDomain();
        $serverHostname = 'mail.' . $defaultDomain;

        return [$serverHostname, $defaultDomain];
    }

    private function resolveBootstrapDefaultDomain(): string
    {
        try {
            /** @var MailDomain $domainModel */
            $domainModel = ObjectManager::getInstance(MailDomain::class);
            $rows = $domainModel->reset()->select()->fetchArray() ?: [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = strtolower(trim((string)($row['domain_name'] ?? $row['name'] ?? '')));
                if ($name === '' || str_ends_with($name, '.invalid') || !str_contains($name, '.')) {
                    continue;
                }

                return $name;
            }
        } catch (\Throwable) {
            // fall through to env / hostname
        }

        try {
            $env = Env::getInstance();
            foreach (['wls.public_host', 'wls.ssl_domain'] as $key) {
                $host = strtolower(trim((string)$env->getConfig($key, '')));
                $host = preg_replace('/^www\./', '', $host) ?? $host;
                if ($host !== '' && str_contains($host, '.') && !str_ends_with($host, '.invalid')) {
                    return $host;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        $host = gethostname();
        $host = is_string($host) ? strtolower(trim($host)) : '';
        if ($host !== '' && str_contains($host, '.') && !str_ends_with($host, '.invalid')) {
            return $host;
        }

        throw new \RuntimeException(
            '无法解析 Bootstrap 默认域：请先在企业邮局添加真实域名（含点的 FQDN），或配置 wls.public_host。'
        );
    }

    /**
     * 打开已密封的管理密码（供一键绑定在 probe 401 时用同密重写 recovery）。
     */
    public function openManagementPassword(): string
    {
        return $this->managementCredential()['password'];
    }

    /**
     * @return array{username:string,password:string,base_url:string}
     */
    public function readManagementCredential(): array
    {
        return $this->managementCredential();
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

    /**
     * 列出本机 Stalwart 中应对 DNS 发布的 DKIM 公钥（SoT：active/pending/retiring）。
     *
     * @return list<array{selector:string,public_key:string,algorithm:string,stage:string}>
     */
    public function listPublishableDkimDnsRecords(string $domainName): array
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

        $query = $this->managementCall('x:DkimSignature/query', [
            'filter' => ['domainId' => $domainId],
            'limit' => 50,
        ]);
        $ids = $query['ids'] ?? [];
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

        $get = $this->managementCall('x:DkimSignature/get', [
            'ids' => $idList,
            'properties' => ['selector', 'publicKey', 'stage', 'domainId', '@type'],
        ]);
        $list = $get['list'] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $publishableStages = ['active' => true, 'pending' => true, 'retiring' => true];
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $stage = strtolower(trim((string)($item['stage'] ?? '')));
            if (!isset($publishableStages[$stage])) {
                continue;
            }
            $selector = strtolower(trim((string)($item['selector'] ?? '')));
            $publicKey = trim((string)($item['publicKey'] ?? ''));
            if ($selector === '' || $publicKey === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $selector)) {
                continue;
            }
            $type = (string)($item['@type'] ?? '');
            $algorithm = stripos($type, 'ed25519') !== false ? 'ed25519' : 'rsa';
            $out[] = [
                'selector' => $selector,
                'public_key' => $publicKey,
                'algorithm' => $algorithm,
                'stage' => $stage,
            ];
        }

        return $out;
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
    /**
     * 读取已存在 Relay 的 authUsername（如 QQ SMTP 授权账号）。
     */
    public function getRelayAuthUsername(string $routeName): string
    {
        $routeName = trim($routeName);
        if ($routeName === '') {
            return '';
        }
        $query = $this->managementCall('x:MtaRoute/query', [
            'filter' => ['name' => $routeName],
            'limit' => 1,
        ]);
        $id = (string)($query['ids'][0] ?? '');
        if ($id === '') {
            return '';
        }
        $got = $this->managementCall('x:MtaRoute/get', [
            'ids' => [$id],
            'properties' => ['name', 'authUsername', '@type'],
        ]);
        foreach (($got['list'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['@type'] ?? '') !== 'Relay') {
                continue;
            }

            return strtolower(trim((string)($row['authUsername'] ?? '')));
        }

        return '';
    }

    /**
     * 列出 Stalwart 中名称带指定前缀的 Relay 路由名（用于保留已配置 hub 导流）。
     *
     * @return list<string>
     */
    public function listRelayRouteNamesByPrefix(string $prefix): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }
        $query = $this->managementCall('x:MtaRoute/query', [
            'filter' => (object)[],
            'limit' => 200,
        ]);
        $ids = $query['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $got = $this->managementCall('x:MtaRoute/get', [
            'ids' => array_values($ids),
            'properties' => ['name', '@type'],
        ]);
        $names = [];
        foreach (($got['list'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['@type'] ?? '') !== 'Relay') {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '' && str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

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

        // Hub 最终出站 else=QQ 时：已认证且 From≠授权用户 → 只改写信封 MAIL FROM（QQ 501：须等于授权用户）。
        // 信头 From 保留品牌址；勿再对齐成 QQ（实测信封对齐后 QQ 250 接受品牌 Header From）。
        $this->applyQqHubEnvelopeFromRewriteIfElse($strategyRoute);

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
     * else 为 QQ hub 时：只改写信封 MAIL FROM→授权用户；Header From 保留品牌址。
     *
     * QQ SMTP 硬约束（已证实）：MAIL FROM 必须等于授权用户，否则 501
     * 「Mail from address must be same as authorization user.」
     * Header From 可保留品牌邮箱（2026-10-19 实测 QQ 250）；旧注释「须改信头/550」作废。
     *
     * @param array<string, mixed> $strategyRoute
     */
    private function applyQqHubEnvelopeFromRewriteIfElse(array $strategyRoute): void
    {
        $else = trim((string)($strategyRoute['else'] ?? ''));
        if ($else === 'weline-hub-qq-smtp') {
            $else = "'weline-hub-qq-smtp'";
        }
        if ($else !== "'weline-hub-qq-smtp'") {
            try {
                $this->managementCall('x:MtaStageMail/set', [
                    'update' => [
                        'singleton' => [
                            'rewrite' => (object)['match' => (object)[], 'else' => 'false'],
                        ],
                    ],
                ]);
                $this->managementCall('x:MtaStageAuth/set', [
                    'update' => [
                        'singleton' => [
                            'mustMatchSender' => (object)['match' => (object)[], 'else' => 'true'],
                        ],
                    ],
                ]);
                $this->clearQqHubHeaderFromAlignSieve();
            } catch (\Throwable) {
            }

            return;
        }
        $qqAuth = $this->getRelayAuthUsername('weline-hub-qq-smtp');
        if ($qqAuth === '' || !filter_var($qqAuth, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        // Stalwart 0.16 表达式字符串字面量必须用双引号；单引号会被拆成非法标识符，rewrite 静默不生效 → QQ 501。
        $quoted = '"' . addcslashes($qqAuth, '"\\') . '"';
        $result = $this->managementCall('x:MtaStageMail/set', [
            'update' => [
                'singleton' => [
                    'rewrite' => (object)[
                        'match' => (object)[
                            '0' => [
                                'if' => "sender != {$quoted} && !is_empty(authenticated_as)",
                                'then' => $quoted,
                            ],
                        ],
                        'else' => 'false',
                    ],
                ],
            ],
        ]);
        if (isset($result['notUpdated']['singleton'])) {
            $type = (string)($result['notUpdated']['singleton']['type'] ?? '');
            throw new \RuntimeException('Stalwart MAIL FROM 改写更新失败' . ($type !== '' ? '：' . $type : '。'));
        }
        // 信封改写后 sender≠authenticated_as；Hub QQ 路径关闭 mustMatchSender（587 仍强制 AUTH）。
        $authResult = $this->managementCall('x:MtaStageAuth/set', [
            'update' => [
                'singleton' => [
                    'mustMatchSender' => (object)['match' => (object)[], 'else' => 'false'],
                ],
            ],
        ]);
        if (isset($authResult['notUpdated']['singleton'])) {
            $type = (string)($authResult['notUpdated']['singleton']['type'] ?? '');
            throw new \RuntimeException('Stalwart mustMatchSender 更新失败' . ($type !== '' ? '：' . $type : '。'));
        }
        $this->applyQqHubHeaderFromAlignSieve($qqAuth);
    }

    private const QQ_HUB_FROM_ALIGN_SIEVE = 'weline-qq-align-from';

    /**
     * DATA 阶段 Sieve：信封已是 QQ 授权用户时，不改 Header From（保留品牌址），
     * 仅在缺 Reply-To 时补上原 From，方便对方回信落到品牌邮箱。
     */
    private function applyQqHubHeaderFromAlignSieve(string $qqAuth): void
    {
        $qqAuth = strtolower(trim($qqAuth));
        if ($qqAuth === '' || !filter_var($qqAuth, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        // 禁止再把 Header From 改成 QQ：对方会看到 aiweline@qq.com，与后台已发送不一致。
        $contents = 'require ["editheader", "envelope", "variables"];' . "\n\n"
            . '# QQ: envelope MAIL FROM already auth user; keep Header From as brand.' . "\n"
            . 'if envelope :is "from" "' . $qqAuth . '" {' . "\n"
            . '  if not header :contains "From" "' . $qqAuth . '" {' . "\n"
            . '    if not exists "Reply-To" {' . "\n"
            . '      if header :matches "From" "*" {' . "\n"
            . '        set "origfrom" "${1}";' . "\n"
            . '        addheader :last "Reply-To" "${origfrom}";' . "\n"
            . '      }' . "\n"
            . '    }' . "\n"
            . '  }' . "\n"
            . '}' . "\n";

        $query = $this->managementCall('x:SieveSystemScript/query', [
            'filter' => ['name' => self::QQ_HUB_FROM_ALIGN_SIEVE],
            'limit' => 5,
        ]);
        $ids = [];
        foreach ((array)($query['ids'] ?? []) as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $payload = [
            'name' => self::QQ_HUB_FROM_ALIGN_SIEVE,
            'description' => 'QQ hub: keep brand Header From; Reply-To only if missing',
            'isActive' => true,
            'contents' => $contents,
        ];
        if ($ids !== []) {
            $set = $this->managementCall('x:SieveSystemScript/set', [
                'update' => [$ids[0] => $payload],
            ]);
            if (isset($set['notUpdated'][$ids[0]])) {
                $type = (string)($set['notUpdated'][$ids[0]]['type'] ?? '');
                throw new \RuntimeException('Stalwart QQ From 对齐 Sieve 更新失败' . ($type !== '' ? '：' . $type : '。'));
            }
        } else {
            $set = $this->managementCall('x:SieveSystemScript/set', [
                'create' => ['qqAlignFrom' => $payload],
            ]);
            if (empty($set['created']['qqAlignFrom']['id'])) {
                $type = (string)($set['notCreated']['qqAlignFrom']['type'] ?? '');
                throw new \RuntimeException('Stalwart QQ From 对齐 Sieve 创建失败' . ($type !== '' ? '：' . $type : '。'));
            }
        }

        $quotedName = '"' . addcslashes(self::QQ_HUB_FROM_ALIGN_SIEVE, '"\\') . '"';
        $stage = $this->managementCall('x:MtaStageData/set', [
            'update' => [
                'singleton' => [
                    'script' => (object)[
                        'match' => (object)[
                            '0' => [
                                'if' => '!is_empty(authenticated_as)',
                                'then' => $quotedName,
                            ],
                        ],
                        'else' => 'false',
                    ],
                ],
            ],
        ]);
        if (isset($stage['notUpdated']['singleton'])) {
            $type = (string)($stage['notUpdated']['singleton']['type'] ?? '');
            throw new \RuntimeException('Stalwart DATA Sieve 挂载失败' . ($type !== '' ? '：' . $type : '。'));
        }
    }

    private function clearQqHubHeaderFromAlignSieve(): void
    {
        try {
            $this->managementCall('x:MtaStageData/set', [
                'update' => [
                    'singleton' => [
                        'script' => (object)['match' => (object)[], 'else' => 'false'],
                    ],
                ],
            ]);
        } catch (\Throwable) {
        }
        try {
            $query = $this->managementCall('x:SieveSystemScript/query', [
                'filter' => ['name' => self::QQ_HUB_FROM_ALIGN_SIEVE],
                'limit' => 5,
            ]);
            $destroy = [];
            foreach ((array)($query['ids'] ?? []) as $id) {
                $id = trim((string)$id);
                if ($id !== '') {
                    $destroy[] = $id;
                }
            }
            if ($destroy !== []) {
                $this->managementCall('x:SieveSystemScript/set', [
                    'destroy' => $destroy,
                ]);
            }
        } catch (\Throwable) {
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
        // else 可以是本机 Smtp Relay 名（587/465）或兜底 'mx'；禁止空串默成奇怪值。
        $else = trim((string)($strategyRoute['else'] ?? "'mx'"));
        if ($else === '' || $else === 'mx') {
            $else = "'mx'";
        } elseif ($else[0] !== "'") {
            $else = "'" . addcslashes($else, "'\\") . "'";
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
        $authUser = trim((string)($route['authUsername'] ?? ''));
        $out = [
            '@type' => 'Relay',
            'name' => $name,
            'address' => $address,
            'port' => $port,
            'protocol' => (string)($route['protocol'] ?? 'smtp') ?: 'smtp',
            'implicitTls' => (bool)($route['implicitTls'] ?? false),
            'allowInvalidCerts' => (bool)($route['allowInvalidCerts'] ?? true),
        ];
        // 空用户名 / None 密钥勿下发：Stalwart 对 authUsername="" 或无意义 authSecret 会 invalidPatch。
        if ($authUser !== '') {
            $out['authUsername'] = $authUser;
        }
        $authSecret = $route['authSecret'] ?? null;
        if (is_array($authSecret)
            && (($authSecret['@type'] ?? '') === 'Value')
            && trim((string)($authSecret['secret'] ?? '')) !== ''
        ) {
            $out['authSecret'] = [
                '@type' => 'Value',
                'secret' => (string)$authSecret['secret'],
            ];
        }

        return $out;
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
        ];
        $authUser = trim((string)($route['authUsername'] ?? ''));
        if ($authUser !== '') {
            $fields['authUsername'] = $authUser;
        }
        if (isset($route['authSecret']) && is_array($route['authSecret'])) {
            $fields['authSecret'] = $route['authSecret'];
        }

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
     * 删除本机 Stalwart 账户（幂等：不存在则成功）。
     */
    public function deleteAccount(string $mailbox): void
    {
        [$localPart, $domainName] = $this->splitMailbox($mailbox);
        try {
            $domainId = $this->domainId($domainName);
        } catch (\DomainException) {
            return;
        }
        $accountId = $this->accountId($localPart, $domainId);
        if ($accountId === null || $accountId === '') {
            return;
        }
        $result = $this->managementCall('x:Account/set', [
            'destroy' => [$accountId],
        ]);
        if (isset($result['notDestroyed'][$accountId])) {
            $type = (string)($result['notDestroyed'][$accountId]['type'] ?? '');
            throw new \RuntimeException('Stalwart 账户删除失败' . ($type !== '' ? '：' . $type : '。'));
        }
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
        // JMAP 方法参数必须是 JSON object；PHP 空 array 会编成 [] → Stalwart 直接 HTTP 400 notRequest。
        $argumentObject = $arguments === [] ? new \stdClass() : $arguments;
        $response = $this->requestJson(
            $credential['base_url'] . '/jmap/',
            $credential['username'],
            $credential['password'],
            [
                'using' => [self::CORE_CAPABILITY, self::MANAGEMENT_CAPABILITY],
                'methodCalls' => [[$method, $argumentObject, 'management']],
            ],
            $timeoutSeconds
        );
        $methodResponse = $response['methodResponses'][0] ?? null;
        if (!is_array($methodResponse) || !isset($methodResponse[0], $methodResponse[1])) {
            throw new \RuntimeException('Stalwart 管理接口返回格式无效。');
        }
        if ((string)$methodResponse[0] === 'error') {
            $type = is_array($methodResponse[1]) ? (string)($methodResponse[1]['type'] ?? '') : '';
            $description = is_array($methodResponse[1])
                ? (string)($methodResponse[1]['description'] ?? '')
                : '';
            $detail = $type;
            if ($description !== '') {
                $detail .= ($detail !== '' ? ' — ' : '') . $description;
            }
            throw new \RuntimeException('Stalwart 管理操作失败' . ($detail !== '' ? '：' . $detail : '。'));
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
        $pair = $this->resolveReadableCredentialPair();
        if ($pair === null) {
            throw new \RuntimeException('Stalwart 管理凭据尚未配置。');
        }
        $raw = file_get_contents($pair['credential']);
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
        $password = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->readManagementKeyFile($pair['key']));
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
        $pair = $this->resolveReadableCredentialPair();
        if ($pair === null) {
            throw new \RuntimeException('Stalwart 管理密钥无效。');
        }

        return $this->readManagementKeyFile($pair['key']);
    }

    private function readManagementKeyFile(string $keyFile): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }
        $raw = file_get_contents($keyFile);
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Stalwart 管理密钥无效。');
        }

        return $key;
    }

    /**
     * 项目根绝对路径（CLI/Web SAPI 共用；禁止相对路径导致 Web 读不到 var/mail）。
     */
    private function projectBasePath(): string
    {
        $bp = defined('BP') ? (string)BP : '';
        $bp = rtrim($bp, '/\\');
        if ($bp !== '') {
            $real = realpath($bp);
            if (is_string($real) && $real !== '') {
                return $real;
            }

            return $bp;
        }
        // Service → Mail → Weline → code → app → 仓库根
        $fallback = dirname(__DIR__, 5);
        $real = realpath($fallback);

        return is_string($real) && $real !== '' ? $real : rtrim($fallback, '/\\');
    }

    /**
     * @return list<array{key:string,credential:string}>
     */
    private function credentialPathCandidates(): array
    {
        $bp = $this->projectBasePath();
        $varKey = $bp . '/' . self::VAR_KEY_REL;
        $varCred = $bp . '/' . self::VAR_CREDENTIAL_REL;

        return [
            ['key' => self::ETC_KEY_FILE, 'credential' => self::ETC_CREDENTIAL_FILE],
            ['key' => $varKey, 'credential' => $varCred],
            // 字面量常量保留，确保与旧路径判定一致
            ['key' => self::KEY_FILE, 'credential' => self::CREDENTIAL_FILE],
        ];
    }

    /**
     * @return array{key:string,credential:string}|null
     */
    private function resolveReadableCredentialPair(): ?array
    {
        $seen = [];
        foreach ($this->credentialPathCandidates() as $pair) {
            $sig = $pair['key'] . "\0" . $pair['credential'];
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            if (is_readable($pair['key']) && is_readable($pair['credential'])) {
                return $pair;
            }
        }

        return null;
    }

    /**
     * 写入优先：可写 /etc/weline，否则项目 var/mail。
     *
     * @return array{key:string,credential:string}
     */
    private function resolveWritableCredentialPair(): array
    {
        $etcDir = dirname(self::ETC_KEY_FILE);
        if ($this->directoryIsWritableOrCreatable($etcDir)) {
            return [
                'key' => self::ETC_KEY_FILE,
                'credential' => self::ETC_CREDENTIAL_FILE,
            ];
        }
        $bp = $this->projectBasePath();
        $varKey = $bp . '/' . self::VAR_KEY_REL;
        $varCred = $bp . '/' . self::VAR_CREDENTIAL_REL;
        $varDir = dirname($varKey);
        if (!$this->directoryIsWritableOrCreatable($varDir)) {
            throw new \RuntimeException(
                '无法写入 Stalwart 管理凭据（/etc/weline 与 ' . $varDir . ' 均不可写；Web/CLI 须同用户可读 BP/var/mail）。'
            );
        }

        return ['key' => $varKey, 'credential' => $varCred];
    }

    private function directoryIsWritableOrCreatable(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        $parent = dirname($dir);
        if (!is_dir($parent) || !is_writable($parent)) {
            return false;
        }

        return @mkdir($dir, 0700, true) || is_dir($dir);
    }

    private function ensureManagementKeyAt(string $keyFile): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }
        if (is_readable($keyFile)) {
            $raw = file_get_contents($keyFile);
            $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        }
        $dir = dirname($keyFile);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建 Stalwart 管理密钥目录。');
        }
        $generated = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        if (@file_put_contents($keyFile, base64_encode($generated)) === false) {
            throw new \RuntimeException('无法写入 Stalwart 管理密钥。');
        }
        @chmod($keyFile, 0600);

        return $generated;
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
        $curlErrno = (int)curl_errno($handle);
        $curlError = trim((string)curl_error($handle));
        curl_close($handle);
        if ($body === false || $status === 0) {
            $detail = $curlError !== '' ? $curlError : ('errno=' . $curlErrno);
            throw new \RuntimeException(
                'Stalwart 管理连接失败（HTTP 0）：' . $detail . '。服务可能正在重启，请稍后重试一键配置。'
            );
        }
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
