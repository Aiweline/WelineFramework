<?php
declare(strict_types=1);

/**
 * Cloudflare 域名商/DNS 服务适配器
 *
 * 通过 Cloudflare API 实现 DNS 记录管理、域名列表获取、Nameserver 切换等功能。
 * Cloudflare API 文档：https://developers.cloudflare.com/api/
 *
 * 主要用途：
 * - DNS 记录管理（A、AAAA、CNAME、MX、TXT 等）
 * - 域名 Nameserver 切换（将域名切换到 Cloudflare DNS）
 * - 域名列表获取（查看托管在 Cloudflare 的域名）
 *
 * 可用性：Registrar API GET /accounts/{id}/registrar/domains/{domain}（需 Token 含 Registrar 读权限）。
 * 新注下单：官方公开 API 仅含 List/Get/Update；购买接口若返回失败，请至控制台完成注册或使用支持 API 购买的注册商。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Adapter;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Adapter\Concern\DefaultDnsZoneOriginMatchTrait;
use Weline\Websites\Adapter\Concern\DnsCdnZoneRecordsProviderTrait;
use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Adapter\Concern\DomainRegistrarAccountDefaultsTrait;
use Weline\Websites\Adapter\Concern\RegistrarBatchCheckAvailabilityTrait;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;

class CloudflareRegistrar implements DomainRegistrarInterface
{
    use DomainRegistrarAccountDefaultsTrait;
    use DnsCdnZoneRecordsProviderTrait;
    use DefaultDnsZoneOriginMatchTrait;
    use RegistrarBatchCheckAvailabilityTrait;
    private const API_BASE_URL = 'https://api.cloudflare.com/client/v4';
    private const REQUEST_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;

    public function getRegistrarCode(): string
    {
        return 'cloudflare';
    }

    public function getRegistrarName(): string
    {
        return 'Cloudflare';
    }

    public function getDescription(): string
    {
        return __(
            'Cloudflare：权威 DNS 与 CDN（代理、规则、缓存）同属一个 Zone，须使用同一 API Token。'
            . ' 一站式配置中 DNS 切到 Cloudflare 后，CDN 步骤只能继续走 Cloudflare，不可用其他 CDN 供应商另绑。'
            . ' 加速依赖 DNS 记录开启「代理状态」(橙云)。'
        );
    }

    /**
     * 是否要求 DNS 与 CDN 使用同一套凭据/Zone（CF 专属；其他供应商返回 false 即可扩展）。
     */
    public function isDnsCdnCoupledProvider(): bool
    {
        return true;
    }

    /**
     * 用当前 Token 解析根域 Zone ID（与 Weline_Cdn Cloudflare::ensureZone 查询一致，供 DNS 已托管后的 CDN 步骤使用）。
     *
     * @return array{success: bool, zone_id?: string, message?: string}
     */
    public function resolveZoneIdForDomain(string $domain, array $credentials): array
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名为空')];
        }
        try {
            $this->validateCredentials($credentials);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'success' => false,
                'message' => $this->zoneMissingViaApiMessage($domain),
            ];
        }

        return ['success' => true, 'zone_id' => $zoneId];
    }

    /**
     * @inheritDoc
     */
    public function normalizeProvisioningDnsCdnAccounts(array $context): array
    {
        $dnsV = \strtolower(\trim((string) ($context['dns_vendor'] ?? '')));
        $cdnV = \strtolower(\trim((string) ($context['cdn_vendor'] ?? '')));
        $dnsId = (int) ($context['dns_account_id'] ?? 0);
        $cdnId = (int) ($context['cdn_account_id'] ?? 0);

        if ($dnsV !== 'cloudflare' && $cdnV !== 'cloudflare') {
            return [];
        }

        $dnsIsCf = $dnsV === 'cloudflare' && $dnsId > 0;
        $cdnIsCf = $cdnV === 'cloudflare' && $cdnId > 0;

        if (($dnsV === 'cloudflare' && $dnsId <= 0 && !$cdnIsCf) || ($cdnV === 'cloudflare' && $cdnId <= 0 && !$dnsIsCf)) {
            return ['_error' => __('Cloudflare：请至少填写 DNS（域名商）或 CDN 侧有效的 Cloudflare 账户 ID。')];
        }

        if (!$dnsIsCf && !$cdnIsCf) {
            return ['_error' => __('Cloudflare：账户 ID 无效，请检查 DNS 或 CDN 配置。')];
        }

        $dnsToken = '';
        if ($dnsIsCf) {
            $acc = ObjectManager::getInstance(DomainRegistrarAccount::class);
            $acc->clearQuery()->load($dnsId);
            if ($acc->getAccountId() <= 0 || \strtolower(\trim((string) $acc->getRegistrarCode())) !== 'cloudflare') {
                return ['_error' => __('Cloudflare DNS（域名商）账户无效或非 Cloudflare 渠道。')];
            }
            $dnsToken = \trim((string) ($acc->getCredentials()['api_secret'] ?? ''));
        }

        $cdnToken = '';
        if ($cdnIsCf) {
            if (!\function_exists('w_query')) {
                return ['_error' => __('Cloudflare CDN 账户校验需要 CDN 模块。')];
            }
            $info = w_query('cdn', 'getAccount', ['account_id' => $cdnId]);
            if (!\is_array($info) || ($info['adapter'] ?? '') !== 'cloudflare') {
                return ['_error' => __('Cloudflare CDN 账户无效。')];
            }
            $cdnToken = \trim((string) (($info['credentials'] ?? [])['api_token'] ?? ''));
        }

        if ($dnsIsCf && $cdnIsCf) {
            if ($dnsToken === '' || $cdnToken === '' || !\hash_equals($dnsToken, $cdnToken)) {
                return ['_error' => __('Cloudflare：DNS（域名商）与 CDN 须为同一 API Token。')];
            }

            return [
                'dns_vendor' => 'cloudflare',
                'dns_account_id' => $dnsId,
                'cdn_vendor' => 'cloudflare',
                'cdn_account_id' => $cdnId,
            ];
        }

        if ($dnsIsCf) {
            $cdnAccId = $this->findCloudflareCdnAccountIdByApiToken($dnsToken);
            if ($cdnAccId <= 0) {
                return ['_error' => __('Cloudflare：请在「CDN 管理」添加与当前 DNS 账户相同 API Token 的 Cloudflare 账户。')];
            }

            return [
                'dns_vendor' => 'cloudflare',
                'dns_account_id' => $dnsId,
                'cdn_vendor' => 'cloudflare',
                'cdn_account_id' => $cdnAccId,
            ];
        }

        $regAccId = $this->findCloudflareRegistrarAccountIdByApiToken($cdnToken);
        if ($regAccId <= 0) {
            return ['_error' => __('Cloudflare：请在「域名管理」添加与当前 CDN 账户相同 API Token 的 Cloudflare DNS 账户。')];
        }

        return [
            'dns_vendor' => 'cloudflare',
            'dns_account_id' => $regAccId,
            'cdn_vendor' => 'cloudflare',
            'cdn_account_id' => $cdnId,
        ];
    }

    private function findCloudflareRegistrarAccountIdByApiToken(string $token): int
    {
        if ($token === '') {
            return 0;
        }
        $reg = ObjectManager::getInstance(DomainRegistrar::class);
        $reg->clearQuery()->where(DomainRegistrar::schema_fields_CODE, 'cloudflare')->find()->fetch();
        if ($reg->getRegistrarId() <= 0) {
            return 0;
        }
        $accM = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $rows = $accM->clearQuery()
            ->where(DomainRegistrarAccount::schema_fields_REGISTRAR_ID, $reg->getRegistrarId())
            ->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        foreach ($rows as $row) {
            $aid = (int) ($row[DomainRegistrarAccount::schema_fields_ID] ?? 0);
            if ($aid <= 0) {
                continue;
            }
            $acc = ObjectManager::getInstance(DomainRegistrarAccount::class);
            $acc->clearQuery()->load($aid);
            if ($acc->getAccountId() <= 0) {
                continue;
            }
            $t = \trim((string) ($acc->getCredentials()['api_secret'] ?? ''));
            if ($t !== '' && \hash_equals($token, $t)) {
                return $acc->getAccountId();
            }
        }

        return 0;
    }

    private function findCloudflareCdnAccountIdByApiToken(string $token): int
    {
        if ($token === '' || !\function_exists('w_query')) {
            return 0;
        }
        $list = w_query('cdn', 'getAccounts', ['adapter' => 'cloudflare']);
        if (!\is_array($list)) {
            return 0;
        }
        foreach ($list as $a) {
            $id = (int) ($a['account_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $info = w_query('cdn', 'getAccount', ['account_id' => $id]);
            if (!\is_array($info) || ($info['adapter'] ?? '') !== 'cloudflare') {
                continue;
            }
            $t = \trim((string) (($info['credentials'] ?? [])['api_token'] ?? ''));
            if ($t !== '' && \hash_equals($token, $t)) {
                return $id;
            }
        }

        return 0;
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'api_token',
                'label' => __('API Token'),
                'type' => 'password',
                'required' => true,
                'required_on_create' => true,
                'placeholder' => __('Cloudflare API Token（区域资源须 All zones）'),
                'mapping' => 'api_secret',
                'help_text' => __(
                    '创建 Token 时「区域资源 / Zone resources」请选择「All zones（所有区域）」。若选「指定区域」且未包含全部站点，本系统可能无法列出 Zone，证书 DNS-01 与解析同步会失败。'
                ),
            ],
            [
                'name' => 'account_id',
                'label' => __('Account ID'),
                'type' => 'text',
                'required' => true,
                'placeholder' => __('必填：登录 CF 控制台 → 任意域名 → Overview → 右下角 API 区域'),
                'mapping' => 'extra_config.account_id',
            ],
        ];
    }

    public function getConfigHelp(): array
    {
        return [
            'help_url' => 'https://dash.cloudflare.com/profile/api-tokens',
            'help_title' => __('Cloudflare API 配置获取指南'),
            'purchase_help_steps' => [
                __('【查可注册性 / 同步 CF 注册域名】必选：Account → Registrar → Read。无此项则批量购买前无法调用 Registrar API 判断域名是否可新注。'),
                __('【DNS / 证书 / 自动解析】区域资源请固定选「All zones（所有区域）」，勿用「指定区域」仅勾选部分域名——否则 API 可能返回空 Zone 列表，与控制台能看到站点无关。权限仍需：Account Settings Read、Zone Read、Zone Edit、DNS Edit。'),
                __('【新注扣款】Cloudflare 当前公开 API 无稳定下单接口；本系统「购买」可能失败，需在控制台 Registrar → Register domains 完成注册；若未来开放 API，一般还需 Registrar → Edit。'),
                __('【账号要求】控制台须已验证邮箱；不支持 IDN（国际化域名）新注。'),
            ],
            'help_steps' => [
                __('【获取 API Token】'),
                __('1. 登录 Cloudflare 控制台：https://dash.cloudflare.com/'),
                __('2. 点击右上角头像 → My Profile（我的个人资料）'),
                __('3. 左侧菜单选择 API Tokens（API 令牌）'),
                __('4. 点击 Create Token → Create Custom Token'),
                __('5. 权限（DNS/建站）：Account Settings Read、Zone Read、Zone Edit、DNS Edit；若要用本系统查购买可用性并同步注册域名：再加 Registrar Read（见上方蓝色框）。'),
                __('6. 【必看】区域资源（Zone resources）请选择「All zones」——本系统依赖 API 枚举/操作 Zone；选「指定区域」且漏选站点会导致 GET /zones 无结果、证书 DNS-01 添加 TXT 失败。'),
                __('7. Create Token 后复制填入「API Token」。'),
                __('【Account ID】可选；不填时凭 Account Settings Read 自动解析。'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('/user/tokens/verify', 'GET', [], $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('API 验证失败');
            throw new \RuntimeException(__('Cloudflare API 连接失败：%{1}', [$errorMsg]));
        }

        return true;
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return [
                'available' => false,
                'domain' => '',
                'price' => 0.0,
                'currency' => 'USD',
                'premium' => false,
                'message' => __('域名不能为空'),
            ];
        }

        $accountId = $this->getAccountId($credentials);
        if ($accountId === '') {
            return [
                'available' => false,
                'domain' => $domain,
                'price' => 0.0,
                'currency' => 'USD',
                'premium' => false,
                'message' => __('请配置 Account ID 或授予 Token「Account → Account Settings → Read」以解析账户'),
            ];
        }

        $path = '/accounts/' . $accountId . '/registrar/domains/' . \rawurlencode($domain);
        $response = $this->makeRequest($path, 'GET', [], $credentials);

        if (($response['success'] ?? false) && \is_array($response['result'] ?? null)) {
            $r = $response['result'];
            $supportedTld = (bool) ($r['supported_tld'] ?? false);
            $canRegister = (bool) ($r['can_register'] ?? false);
            $currentRegistrar = \strtolower(\trim((string) ($r['current_registrar'] ?? '')));
            // 已在其他注册商注册 → 新注不可用（可转入 CF，本系统「购买」指新注）
            if ($currentRegistrar !== '' && $currentRegistrar !== 'cloudflare') {
                $canRegister = false;
            }
            $available = $supportedTld && $canRegister;
            $price = $this->extractRegistrarPriceFromLookup($r);
            $premium = (bool) ($r['premium'] ?? $r['is_premium'] ?? false);
            $msg = $available
                ? __('域名在 Cloudflare Registrar 可新注（以下单结果为准）')
                : (!$supportedTld
                    ? __('该后缀当前不受 Cloudflare Registrar 支持')
                    : ($currentRegistrar !== '' && $currentRegistrar !== 'cloudflare'
                        ? __('域名已在其他注册商注册，请使用转入或换名')
                        : __('域名不可新注或已被注册')));

            return [
                'available' => $available,
                'domain' => $domain,
                'price' => $price,
                'currency' => 'USD',
                'premium' => $premium,
                'message' => $msg,
            ];
        }

        $errors = $response['errors'] ?? [];
        $errMsg = !empty($errors) ? (string) ($errors[0]['message'] ?? '') : '';
        if ($errMsg !== '' && !\str_contains(\strtolower($errMsg), 'not found')) {
            return [
                'available' => false,
                'domain' => $domain,
                'price' => 0.0,
                'currency' => 'USD',
                'premium' => false,
                'message' => __('Registrar 查询失败：%{1}（请确认 Token 含 Account → Registrar → Read）', [$errMsg]),
            ];
        }

        return $this->checkAvailabilityDnsFallback($domain);
    }

    public function purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array
    {
        $this->validateCredentials($credentials);
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return ['success' => false, 'domain' => '', 'message' => __('域名不能为空')];
        }

        $accountId = $this->getAccountId($credentials);
        if ($accountId === '') {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => __('无法解析 Account ID，无法提交注册'),
            ];
        }

        $contact = $this->buildCloudflareRegistrantContact($contactInfo);
        if ($contact === null) {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => __('请填写完整注册人信息：名、姓、邮箱、电话、地址、城市、州/省、邮编、国家（两位代码）'),
            ];
        }

        $years = \max(1, \min(10, $years));
        $privacy = !isset($contactInfo['privacy']) || (bool) $contactInfo['privacy'];

        $payload = [
            'domain' => $domain,
            'name' => $domain,
            'years' => $years,
            'period' => $years,
            'registrant_contact' => $contact,
            'privacy' => $privacy,
            'auto_renew' => true,
        ];

        $endpoints = [
            '/accounts/' . $accountId . '/registrar/domains',
            '/accounts/' . $accountId . '/registrar/domains/' . \rawurlencode($domain) . '/register',
            '/accounts/' . $accountId . '/registrar/domains/' . \rawurlencode($domain),
        ];

        $lastMsg = '';
        foreach ($endpoints as $ep) {
            $body = $ep === $endpoints[0]
                ? $payload
                : [
                    'years' => $years,
                    'period' => $years,
                    'registrant_contact' => $contact,
                    'privacy' => $privacy,
                    'auto_renew' => true,
                ];
            $response = $this->makeRequest($ep, 'POST', $body, $credentials);
            if ($response['success'] ?? false) {
                $res = $response['result'] ?? [];
                $resArr = \is_array($res) ? $res : [];
                $price = $this->extractRegistrarPriceFromLookup($resArr);
                $orderId = $resArr['id'] ?? $resArr['name'] ?? $domain;

                return [
                    'success' => true,
                    'domain' => $domain,
                    'order_id' => (string) $orderId,
                    'price' => $price,
                    'currency' => 'USD',
                    'message' => __('域名注册请求已提交'),
                ];
            }
            $errors = $response['errors'] ?? [];
            $lastMsg = !empty($errors) ? (string) ($errors[0]['message'] ?? '') : __('请求失败');
            $code = (int) ($errors[0]['code'] ?? 0);
            if ($code === 7003 || \str_contains(\strtolower($lastMsg), 'not allowed') || \str_contains(\strtolower($lastMsg), 'method')) {
                continue;
            }
        }

        return [
            'success' => false,
            'domain' => $domain,
            'message' => __(
                'Cloudflare 公开 API 未提供稳定的新注下单接口（常见返回：方法不允许或路由不存在）。'
                . ' 请至控制台「Registrar → Register domains」完成购买：https://dash.cloudflare.com/ ，'
                . ' 或使用 GName/GoDaddy 等支持 API 购买的注册商。最后错误：%{1}',
                [$lastMsg]
            ),
        ];
    }

    /**
     * 获取在 Cloudflare Registrar 购买的域名列表（真正注册的域名）
     *
     * 使用 Cloudflare Registrar API：/accounts/{account_id}/registrar/domains
     * 只返回在 Cloudflare 域名注册服务购买的域名
     */
    public function getDomainList(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $accountId = $this->resolveAccountId($credentials);
        if ($accountId === '') {
            throw new \RuntimeException(__('Cloudflare Account ID 未配置，无法获取注册域名列表'));
        }

        $domains = [];
        $page = 1;
        $perPage = 50;

        do {
            $params = [
                'page' => $page,
                'per_page' => $perPage,
            ];

            $response = $this->makeRequest("/accounts/{$accountId}/registrar/domains", 'GET', $params, $credentials);

            if (!($response['success'] ?? false)) {
                $errors = $response['errors'] ?? [];
                $errorCode = $errors[0]['code'] ?? 0;

                // 1000 错误码表示没有域名，返回空列表
                if ($errorCode === 1000 || \str_contains($errors[0]['message'] ?? '', 'not found')) {
                    return [];
                }

                $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('获取注册域名列表失败');
                throw new \RuntimeException(__('Cloudflare Registrar API 错误：%{1}', [$errorMsg]));
            }

            foreach ($response['result'] ?? [] as $domain) {
                $domainName = $domain['name'] ?? '';
                if ($domainName === '') {
                    continue;
                }

                $domains[] = [
                    'domain' => $domainName,
                    'status' => $this->mapRegistrarStatus($domain['status'] ?? ''),
                    'expires_at' => $domain['expires_at'] ?? '',
                    'auto_renew' => (bool) ($domain['auto_renew'] ?? false),
                    'nameservers' => [],
                    'locked' => (bool) ($domain['locked'] ?? false),
                    'registrar' => 'cloudflare',
                    'is_registered_at_cloudflare' => true,
                ];
            }

            $totalCount = $response['result_info']['total_count'] ?? \count($domains);
            $totalPages = (int) \ceil($totalCount / $perPage);
            $page++;
        } while ($page <= $totalPages);

        return $domains;
    }

    /**
     * 获取托管在 Cloudflare 的所有域名（zones），包括在其他注册商购买的
     *
     * 使用 Cloudflare Zones API：/zones
     * 用于 DNS 管理、CDN 配置等场景，不用于根域名同步
     */
    public function getHostedDomainList(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $domains = [];
        $page = 1;
        $perPage = 50;

        do {
            $params = [
                'page' => $page,
                'per_page' => $perPage,
                'status' => 'active',
            ];

            $response = $this->makeRequest('/zones', 'GET', $params, $credentials);

            if (!($response['success'] ?? false)) {
                $errors = $response['errors'] ?? [];
                $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('获取域名列表失败');
                throw new \RuntimeException(__('Cloudflare Zones API 错误：%{1}', [$errorMsg]));
            }

            foreach ($response['result'] ?? [] as $zone) {
                $domains[] = [
                    'domain' => $zone['name'] ?? '',
                    'status' => $this->mapZoneStatus($zone['status'] ?? ''),
                    'expires_at' => '',
                    'auto_renew' => false,
                    'nameservers' => $zone['name_servers'] ?? [],
                    'zone_id' => $zone['id'] ?? '',
                    'plan' => $zone['plan']['name'] ?? 'Free',
                    'is_registered_at_cloudflare' => false,
                ];
            }

            $totalPages = $response['result_info']['total_pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);

        return $domains;
    }

    /**
     * 映射 Cloudflare Registrar 域名状态
     */
    private function mapRegistrarStatus(string $status): string
    {
        return match (\strtolower($status)) {
            'active' => 'active',
            'pending' => 'pending',
            'expired' => 'expired',
            'redemption' => 'redemption',
            'deleted' => 'deleted',
            default => $status ?: 'unknown',
        };
    }

    public function getDomainDetail(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'domain' => $domain,
                'status' => 'not_found',
                'message' => $this->zoneMissingViaApiMessage($domain),
            ];
        }

        $response = $this->makeRequest("/zones/{$zoneId}", 'GET', [], $credentials);

        if (!($response['success'] ?? false)) {
            return [
                'domain' => $domain,
                'status' => 'error',
                'message' => __('获取域名详情失败'),
            ];
        }

        $zone = $response['result'] ?? [];

        return [
            'domain' => $zone['name'] ?? $domain,
            'status' => $this->mapZoneStatus($zone['status'] ?? ''),
            'nameservers' => $zone['name_servers'] ?? [],
            'original_nameservers' => $zone['original_name_servers'] ?? [],
            'plan' => $zone['plan']['name'] ?? 'Free',
            'zone_id' => (string) ($zone['id'] ?? ''),
            'paused' => $zone['paused'] ?? false,
            'type' => $zone['type'] ?? 'full',
        ];
    }

    public function supportsDnsManagement(): bool
    {
        return true;
    }

    public function getDnsRecords(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            throw new \RuntimeException($this->zoneMissingViaApiMessage($domain));
        }

        $records = [];
        $page = 1;
        $perPage = 100;

        do {
            $params = [
                'page' => $page,
                'per_page' => $perPage,
            ];

            $response = $this->makeRequest("/zones/{$zoneId}/dns_records", 'GET', $params, $credentials);

            if (!($response['success'] ?? false)) {
                $errors = $response['errors'] ?? [];
                $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('获取 DNS 记录失败');
                throw new \RuntimeException(__('Cloudflare API 错误：%{1}', [$errorMsg]));
            }

            foreach ($response['result'] ?? [] as $record) {
                $host = $record['name'] ?? '';
                if (\str_ends_with($host, '.' . $domain)) {
                    $host = \substr($host, 0, -\strlen('.' . $domain));
                }
                if ($host === $domain) {
                    $host = '@';
                }

                $records[] = [
                    'record_id' => $record['id'] ?? '',
                    'type' => $record['type'] ?? '',
                    'host' => $host,
                    'value' => $record['content'] ?? '',
                    'ttl' => (int) ($record['ttl'] ?? 1),
                    'priority' => (int) ($record['priority'] ?? 0),
                    'proxied' => $record['proxied'] ?? false,
                ];
            }

            $totalPages = $response['result_info']['total_pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);

        return $records;
    }

    /**
     * 创建 DNS 记录：官方 https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/create/
     * 同源 CDN：A/AAAA/CNAME 默认开启代理（橙云），解析与 CDN 一并生效；TXT/MX 等不可代理。
     * 开启代理时 TTL 须为 1（Auto），否则 API 会报错或忽略。
     */
    public function addDnsRecord(string $domain, array $record, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'success' => false,
                'message' => $this->zoneMissingViaApiMessage($domain),
            ];
        }

        $host = $record['host'] ?? '@';
        $name = $host === '@' ? $domain : ($host . '.' . $domain);

        $recordType = \strtoupper((string) ($record['type'] ?? 'A'));
        $proxied = $this->resolveProxiedValue($recordType, $record);
        $data = [
            'type' => $recordType,
            'name' => $name,
            'content' => $record['value'] ?? '',
            'ttl' => $proxied ? 1 : (int) ($record['ttl'] ?? 1),
            'proxied' => $proxied,
        ];

        if (\in_array($data['type'], ['MX', 'SRV'], true)) {
            $data['priority'] = (int) ($record['priority'] ?? 10);
        }

        $response = $this->makeRequest("/zones/{$zoneId}/dns_records", 'POST', $data, $credentials);

        $dnsResponse = [
            'provider' => 'cloudflare',
            'success' => (bool)($response['success'] ?? false),
            'result_id' => (string)($response['result']['id'] ?? ''),
            'errors' => $response['errors'] ?? [],
        ];

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('添加记录失败');
            // Cloudflare 返回“记录已存在/identical record”时，视为成功（搬迁时目标已存在即达成目的）
            if (\stripos($errorMsg, 'already exists') !== false || \stripos($errorMsg, 'identical record') !== false) {
                $existing = $this->getDnsRecordByNameAndType($zoneId, $name, $recordType, $credentials);
                return [
                    'success' => true,
                    'record_id' => $existing,
                    'zone_id' => $zoneId,
                    'message' => $existing !== '' ? __('DNS 记录已存在，复用现有记录') : __('DNS 记录已存在（与现有记录相同）'),
                    'dns_response' => $dnsResponse,
                ];
            }
            return [
                'success' => false,
                'message' => $errorMsg,
                'dns_response' => $dnsResponse,
            ];
        }

        return [
            'success' => true,
            'record_id' => $response['result']['id'] ?? '',
            'zone_id' => $zoneId,
            'message' => __('DNS 记录添加成功'),
            'dns_response' => $dnsResponse,
        ];
    }

    /**
     * 按 name 与 type 查询一条 DNS 记录的 id（用于“已存在”时复用）
     */
    private function getDnsRecordByNameAndType(string $zoneId, string $name, string $type, array $credentials): string
    {
        $response = $this->makeRequest("/zones/{$zoneId}/dns_records", 'GET', ['name' => $name, 'type' => $type], $credentials);
        if (!($response['success'] ?? false)) {
            return '';
        }
        $result = $response['result'] ?? [];
        $first = \is_array($result) && isset($result[0]) ? $result[0] : null;
        return $first && isset($first['id']) ? (string) $first['id'] : '';
    }

    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'success' => false,
                'message' => $this->zoneMissingViaApiMessage($domain),
            ];
        }

        $host = $record['host'] ?? '@';
        $name = $host === '@' ? $domain : ($host . '.' . $domain);

        $recordType = \strtoupper((string) ($record['type'] ?? 'A'));
        $proxied = $this->resolveProxiedValue($recordType, $record);
        $data = [
            'type' => $recordType,
            'name' => $name,
            'content' => $record['value'] ?? '',
            'ttl' => $proxied ? 1 : (int) ($record['ttl'] ?? 1),
            'proxied' => $proxied,
        ];

        if (\in_array($data['type'], ['MX', 'SRV'], true)) {
            $data['priority'] = (int) ($record['priority'] ?? 10);
        }

        $response = $this->makeRequest("/zones/{$zoneId}/dns_records/{$recordId}", 'PUT', $data, $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('更新记录失败');
            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }

        return [
            'success' => true,
            'message' => __('DNS 记录更新成功'),
        ];
    }

    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'success' => false,
                'message' => $this->zoneMissingViaApiMessage($domain),
            ];
        }

        $response = $this->makeRequest("/zones/{$zoneId}/dns_records/{$recordId}", 'DELETE', [], $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('删除记录失败');
            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }

        return [
            'success' => true,
            'message' => __('DNS 记录删除成功'),
        ];
    }

    public function batchAddDnsRecords(string $domain, array $records, array $credentials): array
    {
        $total = \count($records);
        w_log_info(__('[CF] batchAddDnsRecords 开始：domain=%{1}, 记录数=%{2}', [$domain, (string) $total]), [], 'dns_cdn_switch');

        $added = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $record) {
            $result = $this->addDnsRecord($domain, $record, $credentials);
            if ($result['success']) {
                $added++;
            } else {
                $failed++;
                $errors[] = ($record['host'] ?? '@') . ': ' . ($result['message'] ?? __('未知错误'));
            }
        }

        w_log_info(__('[CF] batchAddDnsRecords 完成：domain=%{1}, 成功=%{2}, 失败=%{3}', [$domain, (string) $added, (string) $failed]), [], 'dns_cdn_switch');
        if ($errors !== []) {
            w_log_warning(__('[CF] batchAddDnsRecords 失败详情：%{1}', [\implode('; ', $errors)]), [], 'dns_cdn_switch');
        }

        return [
            'success' => $failed === 0,
            'added' => $added,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public function updateNameservers(string $domain, array $nameservers, array $credentials): array
    {
        return [
            'success' => false,
            'message' => __(
                'Cloudflare 账户在此仅为 DNS 托管：域名若未在 Cloudflare 注册，则注册局委派 NS 只能在域名注册商处修改。请把注册商处的 NS 改为 Cloudflare 为该域名分配的地址；不得用 DNS 托管 API 替代注册商改委派。'
            ),
        ];
    }

    /**
     * @inheritDoc
     *
     * Cloudflare 需要先将域名添加到 Zone，然后获取分配的 Nameserver。
     * 如果域名已存在，直接返回已分配的 Nameserver；否则尝试添加并返回。
     */
    public function getProviderNameservers(array $credentials, string $domain = ''): array
    {
        if ($domain === '') {
            return [
                'success' => false,
                'nameservers' => [],
                'message' => __('Cloudflare 需要指定域名才能获取分配的 Nameserver'),
            ];
        }

        $this->validateCredentials($credentials);
        w_log_info(__('[CF] getProviderNameservers：domain=%{1}', [$domain]), [], 'dns_cdn_switch');

        $detail = $this->getDomainDetail($domain, $credentials);

        if (isset($detail['nameservers']) && !empty($detail['nameservers'])) {
            w_log_info(__('[CF] 域名已在 Zone 中，ns=%{1}', [\implode(', ', $detail['nameservers'])]), [], 'dns_cdn_switch');
            return [
                'success' => true,
                'nameservers' => $detail['nameservers'],
                'message' => __('域名已在 Cloudflare 中，使用已分配的 Nameserver'),
            ];
        }

        w_log_info(__('[CF] 域名不在 Zone 中，自动添加'), [], 'dns_cdn_switch');
        $addResult = $this->addZone($domain, $credentials);

        if ($addResult['success'] && !empty($addResult['nameservers'])) {
            return [
                'success' => true,
                'nameservers' => $addResult['nameservers'],
                'message' => __('域名已添加到 Cloudflare'),
            ];
        }

        w_log_error(__('[CF] getProviderNameservers 失败：%{1}', [$addResult['message'] ?? '']), [], 'dns_cdn_switch');
        return [
            'success' => false,
            'nameservers' => [],
            'message' => $addResult['message'] ?? __('无法获取 Cloudflare Nameserver'),
        ];
    }

    /**
     * 获取 Cloudflare 分配的 Nameservers（兼容旧方法）
     */
    public function getCloudflareNameservers(string $domain, array $credentials): array
    {
        $detail = $this->getDomainDetail($domain, $credentials);
        return $detail['nameservers'] ?? [];
    }

    /**
     * 添加域名到 Cloudflare（创建 Zone）
     *
     * @param string $domain 域名
     * @param array $credentials API 凭据
     * @return array{success: bool, zone_id?: string, nameservers?: array, message?: string}
     */
    public function addZone(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);
        w_log_info(__('[CF] addZone 请求：domain=%{1}', [$domain]), [], 'dns_cdn_switch');

        $accountId = $this->resolveAccountId($credentials);
        if ($accountId === '') {
            $accountId = $this->getAccountId($credentials);
        }

        if ($accountId === '') {
            w_log_error(__('[CF] addZone 失败：无法获取 Account ID'), [], 'dns_cdn_switch');
            return [
                'success' => false,
                'message' => __('无法自动获取 Cloudflare Account ID。') . "\n\n" .
                    __('【解决方案】') . "\n" .
                    __('方案一：创建 Token 时添加「Account → Account Settings → Read」权限') . "\n" .
                    __('方案二：手动填写 Account ID：') . "\n" .
                    __('  1. 登录 Cloudflare 控制台 https://dash.cloudflare.com') . "\n" .
                    __('  2. 进入任意已添加的域名') . "\n" .
                    __('  3. 在右下角「API」区域找到「Account ID」') . "\n" .
                    __('  4. 复制并填入本系统的账户配置中'),
            ];
        }

        $data = [
            'name' => $domain,
            'account' => ['id' => $accountId],
            'type' => 'full',
        ];

        w_log_info(__('[CF] 创建 Zone：account_id=%{1}, type=full', [$accountId]), [], 'dns_cdn_switch');
        $response = $this->makeRequest('/zones', 'POST', $data, $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('添加域名失败');
            w_log_error(__('[CF] addZone 失败：%{1}, errors=%{2}', [$errorMsg, \json_encode($errors, JSON_UNESCAPED_UNICODE)]), [], 'dns_cdn_switch');
            if (\stripos($errorMsg, 'zone.create') !== false || \stripos($errorMsg, 'permission') !== false) {
                $errorMsg .= "\n\n" . __('【解决方法】添加新站点需要 Zone Edit 且区域资源为「所有区域」：') . "\n"
                    . __('1. 登录 https://dash.cloudflare.com → 我的个人资料 → API 令牌，编辑当前 Token') . "\n"
                    . __('2. 权限中确保有 Zone → Zone → Edit（控制台可能显示为「区域」→「区域」→「编辑」）') . "\n"
                    . __('3. 「区域资源」必须选 All zones（所有区域）；只选「指定区域」时无法创建新站点，会报此错误');
            }
            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }

        $zone = $response['result'] ?? [];
        $ns = $zone['name_servers'] ?? [];
        w_log_info(__('[CF] addZone 成功：zone_id=%{1}, ns=%{2}', [$zone['id'] ?? '', \implode(', ', $ns)]), [], 'dns_cdn_switch');

        return [
            'success' => true,
            'zone_id' => $zone['id'] ?? '',
            'nameservers' => $ns,
            'message' => __('域名已添加到 Cloudflare，请将 Nameserver 切换到：%{1}', [\implode(', ', $ns)]),
        ];
    }

    /**
     * GET /zones?name= 无结果时的用户说明（控制台可见 ≠ Token 能列出 Zone）。
     */
    private function zoneMissingViaApiMessage(string $domain): string
    {
        return (string) __(
            '在当前 Cloudflare API Token 下经多次 GET /zones?name= 重试后仍未解析到域名「%{1}」的 Zone。API 非强一致，刚改 NS / 新站 pending→active 时常见；可稍后重试。若在控制台能看到该站点，多为 Token 与登录账户不一致、权限未含 Zone → Read、或「区域资源」未包含该域名；DNS 与 CDN 若均用 Cloudflare 须使用同一 Token。',
            [$domain]
        );
    }

    /**
     * 与 {@see DomainResolveService::CF_DNS_ZONE_EXTERNAL_CREDENTIAL_KEY} 一致：由业务层注入已落库的 zone_id，本适配器优先直用。
     */
    private const INJECTED_ZONE_ID_CREDENTIAL_KEY = '_weline_dns_zone_external_id';

    /** SaaS：GET /zones?name= 重试次数（含首次，共 8 次） */
    private const ZONE_NAME_QUERY_ATTEMPTS = 8;

    /** 重试间隔（秒），第 2 次起 sleep */
    private const ZONE_NAME_QUERY_RETRY_SLEEP_SECONDS = 2;

    /**
     * 获取 Zone ID：优先凭据中注入的落库 ID；否则 GET /zones?name= 并带重试（CF API 非强一致）。
     */
    private function getZoneId(string $domain, array $credentials): string
    {
        $domain = \strtolower(\trim($domain));

        $pinned = \trim((string) ($credentials[self::INJECTED_ZONE_ID_CREDENTIAL_KEY] ?? ''));
        if ($pinned !== '' && \preg_match('/^[a-f0-9]{32}$/i', $pinned)) {
            return $pinned;
        }

        for ($attempt = 0; $attempt < self::ZONE_NAME_QUERY_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                \Weline\Framework\Runtime\SchedulerSystem::sleep(self::ZONE_NAME_QUERY_RETRY_SLEEP_SECONDS);
            }
            $response = $this->makeRequest('/zones', 'GET', ['name' => $domain], $credentials);
            if (!($response['success'] ?? false)) {
                continue;
            }
            $zones = $response['result'] ?? [];
            if (!empty($zones[0]['id'])) {
                return (string) $zones[0]['id'];
            }
        }

        return '';
    }

    /**
     * 解析 Account ID（仅从配置中获取，不调用 API）
     */
    private function resolveAccountId(array $credentials): string
    {
        // 优先使用凭据顶层的 account_id
        if (isset($credentials['account_id']) && $credentials['account_id'] !== '') {
            return (string) $credentials['account_id'];
        }

        // 兼容 extra_config 和 extra 两种键名
        $extraConfig = $credentials['extra_config'] ?? $credentials['extra'] ?? [];
        if (isset($extraConfig['account_id']) && $extraConfig['account_id'] !== '') {
            return (string) $extraConfig['account_id'];
        }

        return '';
    }

    /**
     * 获取 Account ID
     * 
     * 通过 /accounts API 自动获取当前 Token 关联的 Account ID。
     * 需要 Token 具有 Account:Read 权限。
     */
    private function getAccountId(array $credentials): string
    {
        // 优先使用凭据中已配置的 account_id
        $configuredAccountId = $this->resolveAccountId($credentials);
        if ($configuredAccountId !== '') {
            return $configuredAccountId;
        }
        
        $response = $this->makeRequest('/accounts', 'GET', ['page' => 1, 'per_page' => 1], $credentials);

        if (!($response['success'] ?? false)) {
            // 记录详细错误便于调试
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? '') : '';
            w_log_warning(__('获取 Cloudflare Account ID 失败：%{1}', [$errorMsg ?: 'Unknown error']), [], 'cloudflare_api');
            return '';
        }

        $accounts = $response['result'] ?? [];
        if (empty($accounts)) {
            w_log_warning(__('Cloudflare API 返回空账户列表，Token 可能没有 Account:Read 权限'), [], 'cloudflare_api');
            return '';
        }
        
        return $accounts[0]['id'] ?? '';
    }

    /**
     * 映射 Zone 状态
     */
    private function mapZoneStatus(string $status): string
    {
        $statusMap = [
            'active' => 'active',
            'pending' => 'pending',
            'initializing' => 'pending',
            'moved' => 'inactive',
            'deleted' => 'deleted',
            'deactivated' => 'inactive',
        ];

        return $statusMap[\strtolower($status)] ?? $status;
    }

    /**
     * 计算 Cloudflare 的 proxied 标记（同源：解析即开 CDN 代理）：
     * - 记录显式传入 proxied 时优先使用（便于上层按需覆盖）
     * - 未显式传入时，A/AAAA/CNAME 默认开启代理（橙云），与 DNS 一并生效
     */
    private function resolveProxiedValue(string $recordType, array $record): bool
    {
        if (\array_key_exists('proxied', $record)) {
            return (bool) $record['proxied'];
        }

        return $this->supportsCloudflareProxy($recordType);
    }

    /**
     * Cloudflare 仅支持部分记录类型开启代理。
     */
    private function supportsCloudflareProxy(string $recordType): bool
    {
        return \in_array($recordType, ['A', 'AAAA', 'CNAME'], true);
    }

    /**
     * 推送前为可代理记录默认开启橙云（与 {@see resolveProxiedValue} 行为一致）。
     *
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function applyCdnSettingsToDnsRecords(string $domain, array $records): array
    {
        unset($domain);
        $out = [];
        foreach ($records as $r) {
            $type = \strtoupper((string) ($r['type'] ?? 'A'));
            if ($this->supportsCloudflareProxy($type)) {
                $r['proxied'] = \array_key_exists('proxied', $r) ? (bool) $r['proxied'] : true;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * 通过 DNS API 判断根域或 www 的 A/AAAA/CNAME 是否已开启代理（不依赖站点是否可访问）。
     *
     * @return array{supported: bool, ok: bool, message: string}
     */
    public function verifyCdnConfiguration(string $domain, array $credentials): array
    {
        try {
            $this->validateCredentials($credentials);
            $records = $this->getDnsRecords($domain, $credentials);
        } catch (\Throwable $e) {
            return [
                'supported' => true,
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
        $apexHosts = ['@', 'www', ''];
        $types = ['A', 'AAAA', 'CNAME'];
        foreach ($records as $r) {
            $host = \trim((string) ($r['host'] ?? '@'));
            if ($host === '') {
                $host = '@';
            }
            $type = \strtoupper((string) ($r['type'] ?? ''));
            if (!\in_array($host, $apexHosts, true) || !\in_array($type, $types, true)) {
                continue;
            }
            if (!empty($r['proxied'])) {
                return [
                    'supported' => true,
                    'ok' => true,
                    'message' => __('已检测到 %{1} 记录（%{2}）开启 Cloudflare 代理', [$type, $host === '@' ? __('根域') : 'www']),
                ];
            }
        }

        return [
            'supported' => true,
            'ok' => false,
            'message' => __('根域或 www 下未找到已开启代理的 A/AAAA/CNAME 记录'),
        ];
    }

    /**
     * 从 Registrar GET 结果中提取展示用价格（字段名随 API 扩展可能变化）
     *
     * @param array<string, mixed> $r
     */
    private function extractRegistrarPriceFromLookup(array $r): float
    {
        foreach (['registration_price', 'register_price', 'price', 'retail_price', 'total_price'] as $k) {
            if (isset($r[$k]) && \is_numeric($r[$k])) {
                return (float) $r[$k];
            }
        }
        foreach ($r as $v) {
            if (\is_array($v) && isset($v['amount']) && \is_numeric($v['amount'])) {
                return (float) $v['amount'];
            }
        }

        return 0.0;
    }

    /**
     * Registrar API 不可用时用 DNS 启发式（与 GName 检查策略一致）
     */
    private function checkAvailabilityDnsFallback(string $domain): array
    {
        try {
            $hasNs = @\dns_get_record($domain, \DNS_NS);
            $hasA = @\dns_get_record($domain, \DNS_A | \DNS_AAAA);
            $looks = (\is_array($hasNs) && $hasNs !== []) || (\is_array($hasA) && $hasA !== []);
        } catch (\Throwable) {
            $looks = false;
        }

        return [
            'available' => !$looks,
            'domain' => $domain,
            'price' => 0.0,
            'currency' => 'USD',
            'premium' => false,
            'message' => $looks
                ? __('无法调用 Registrar API 或域名无记录；检测到 DNS，可能已被注册')
                : __('无法调用 Registrar API；未检测到 DNS，可能可注册（请用控制台或换注册商确认）'),
        ];
    }

    /**
     * Cloudflare registrant_contact 结构（与官方 Domain 模型一致）
     *
     * @param array<string, mixed> $contactInfo
     * @return array<string, string>|null
     */
    private function buildCloudflareRegistrantContact(array $contactInfo): ?array
    {
        $f = $contactInfo['purchase_contact_flat'] ?? [];
        if (!\is_array($f)) {
            $f = [];
        }
        $first = \trim((string) ($f['first_name'] ?? ''));
        $last = \trim((string) ($f['last_name'] ?? ''));
        $email = \trim((string) ($f['email'] ?? ''));
        $phone = \trim((string) ($f['phone'] ?? ''));
        $addr = \trim((string) ($f['address1'] ?? $f['address'] ?? ''));
        $city = \trim((string) ($f['city'] ?? ''));
        $state = \trim((string) ($f['state'] ?? ''));
        $zip = \trim((string) ($f['postal_code'] ?? $f['zip'] ?? ''));
        $country = \trim((string) ($f['country'] ?? ''));
        if ($first === '' || $last === '' || $email === '' || $phone === '' || $addr === '' || $city === '' || $state === '' || $zip === '' || $country === '') {
            return null;
        }
        $org = \trim((string) ($f['organization'] ?? ''));
        if ($org === '') {
            $org = $first . ' ' . $last;
        }

        return [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $phone,
            'address' => $addr,
            'address2' => \trim((string) ($f['address2'] ?? '')),
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'country' => \strtoupper(\substr($country, 0, 2)),
            'organization' => $org,
        ];
    }

    /**
     * 验证凭据
     */
    private function validateCredentials(array $credentials): void
    {
        $apiToken = $credentials['api_token'] ?? $credentials['api_secret'] ?? '';
        if ($apiToken === '') {
            throw new \InvalidArgumentException(__('Cloudflare API Token 不能为空'));
        }
    }

    /**
     * 发起 API 请求
     */
    private function makeRequest(string $endpoint, string $method, array $params, array $credentials): array
    {
        $apiToken = $credentials['api_token'] ?? $credentials['api_secret'] ?? '';
        $url = self::API_BASE_URL . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
        ];

        $ch = \curl_init();

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . \http_build_query($params);
        }

        // 开发环境下禁用 SSL 验证（解决本地 CA 证书问题）
        // 临时强制禁用 SSL 验证以排查问题
        $deployMode = Env::system('deploy') ?? 'prod';
        $isDev = \in_array($deployMode, ['dev', 'development', 'local'], true);
        
        // 强制禁用 SSL 验证（Windows 开发环境经常缺少 CA 证书）
        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        if ($method === 'POST') {
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($params));
        } elseif ($method === 'PUT') {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($params));
        } elseif ($method === 'DELETE') {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $responseBody = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = \curl_error($ch);
        \curl_close($ch);

        if ($curlError !== '') {
            w_log_error("cURL 错误: {$curlError}, URL: {$url}", [], 'cloudflare_api');
            return [
                'success' => false,
                'errors' => [['message' => __('网络请求失败：%{1}', [$curlError])]],
            ];
        }

        $response = \json_decode($responseBody, true);
        if (!\is_array($response)) {
            w_log_error("JSON 解析失败, HTTP Code: {$httpCode}, Body: " . \substr($responseBody, 0, 500), [], 'cloudflare_api');
            return [
                'success' => false,
                'errors' => [['message' => __('API 响应格式错误')]],
            ];
        }

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? '') : '';
            w_log_warning("API 错误: {$errorMsg}, Endpoint: {$endpoint}, HTTP Code: {$httpCode}", [], 'cloudflare_api');
        }

        return $response;
    }

    /**
     * @inheritDoc
     *
     * 视为注册商：同步与购买走 Registrar API 域名列表；DNS/Zone 仍用现有 Zones 能力。
     */
    public function isDomainRegistrar(): bool
    {
        return true;
    }
}
