<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/1 21:52:30
 */

namespace Weline\Smtp\Helper;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;

/**
 * SMTP 配置读写：按 SystemConfig storage_scope（global/website）存储，读取带继承回退。
 */
class Data
{
    public const smtp_host = 'smtp_host';
    public const smtp_auth = 'smtp_auth';
    public const smtp_port = 'smtp_port';
    public const smtp_username = 'smtp_username';
    public const smtp_password = 'smtp_password';
    public const smtp_secure = 'smtp_secure';
    public const smtp_test_address = 'smtp_test_address';

    public const keys = [
        self::smtp_host,
        self::smtp_auth,
        self::smtp_port,
        self::smtp_username,
        self::smtp_password,
        self::smtp_secure,
        self::smtp_test_address,
    ];

    /** 多发件人配置存储 key，值为 JSON 数组 [{ code, name, smtp_host, ... }] */
    public const key_smtp_senders = 'smtp_senders';
    /** 发件人 code 对应的默认联系人（收件邮箱）存储 key，值为 JSON 对象 { "code": "to_email" } */
    public const key_smtp_sender_contacts = 'smtp_sender_contacts';
    /** 已注册发信渠道 → 传输账户 id；JSON { "Module::channel": "transport_id" } */
    public const key_smtp_channel_bindings = 'smtp_channel_bindings';
    /** 邮件壳页头背景图（媒体路径或 URL；子 Scope 继承 Global） */
    public const key_smtp_mail_bg_header = 'smtp_mail_bg_header';
    /** 邮件壳正文背景图 */
    public const key_smtp_mail_bg_body = 'smtp_mail_bg_body';
    /** 邮件壳页尾背景图 */
    public const key_smtp_mail_bg_footer = 'smtp_mail_bg_footer';
    /** 邮件壳页头/页尾可视化编辑 innerHTML（JSON：header + footer[]） */
    public const key_smtp_mail_shell_regions = 'smtp_mail_shell_regions';
    /** 建站进度：当前 scope 已通过测试确认 */
    public const key_smtp_setup_confirmed = 'smtp_setup_confirmed';
    public const TRANSPORT_ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

    private const AREA = ConfigReader::area_BACKEND;

    /** @var array<string, array<string, string>> */
    private array $smtp = [];

    public function __construct(
        private readonly ConfigReader $reader,
        private readonly ConfigStore $store,
        private readonly ?ScopeHierarchyInterface $scopeHierarchy = null,
    ) {
    }

    /** @return list<string> */
    public static function mailShellBackgroundKeys(): array
    {
        return [
            self::key_smtp_mail_bg_header,
            self::key_smtp_mail_bg_body,
            self::key_smtp_mail_bg_footer,
        ];
    }

    /**
     * 读取邮件壳三区背景图路径（已按 Scope 继承回退）。
     *
     * @return array{header:string,body:string,footer:string}
     */
    public function getMailShellBackgrounds(?string $scope = null): array
    {
        $scope = $this->resolveScope($scope);
        $out = ['header' => '', 'body' => '', 'footer' => ''];
        $map = [
            'header' => self::key_smtp_mail_bg_header,
            'body' => self::key_smtp_mail_bg_body,
            'footer' => self::key_smtp_mail_bg_footer,
        ];
        foreach ($map as $region => $key) {
            try {
                $val = $this->reader->getConfig(
                    $key,
                    'Weline_Smtp',
                    self::AREA,
                    null,
                    $scope,
                    ConfigReader::LOCALE_DEFAULT,
                );
                $out[$region] = $val !== null && $val !== '' ? trim((string)$val) : '';
            } catch (\Throwable) {
                $out[$region] = '';
            }
        }

        return $out;
    }

    /**
     * 写入邮件壳三区背景图路径（相对 pub/media 或空串清空）。
     *
     * @param array{header?:string,body?:string,footer?:string} $paths
     */
    public function setMailShellBackgrounds(string $scope, array $paths): void
    {
        $scope = $this->resolveScope($scope);
        $map = [
            'header' => self::key_smtp_mail_bg_header,
            'body' => self::key_smtp_mail_bg_body,
            'footer' => self::key_smtp_mail_bg_footer,
        ];
        foreach ($map as $region => $key) {
            if (!array_key_exists($region, $paths)) {
                continue;
            }
            $val = trim((string)$paths[$region]);
            $this->writeScoped($key, $val, 'Weline_Smtp', $scope, 'string');
        }
    }

    /**
     * 规范化存储范围：显式 scope 优先；否则 RequestContext ScopeIdentity；再回落 Global。
     */
    public function resolveScope(?string $scope = null): string
    {
        $explicit = trim((string)($scope ?? ''));
        if ($explicit !== '') {
            return $this->reader->normalizeScope($explicit);
        }

        try {
            $identity = RequestContext::scopeIdentity();
            if ($identity instanceof ScopeIdentity) {
                $hierarchy = $this->scopeHierarchy
                    ?? ObjectManager::getInstance(ScopeHierarchyInterface::class);
                if ($hierarchy instanceof ScopeHierarchyInterface) {
                    return $this->reader->normalizeScope($hierarchy->toStorageScope($identity));
                }
            }
        } catch (\Throwable) {
        }

        return ConfigReader::SCOPE_GLOBAL;
    }

    function get(string $key = '', string $module = 'Weline_Smtp', ?string $scope = null): string|array
    {
        $scope = $this->resolveScope($scope);
        $cacheKey = $module . '@' . $scope;
        if (!isset($this->smtp[$cacheKey])) {
            $row = [];
            foreach (self::keys as $k) {
                $val = $this->reader->getConfig(
                    $k,
                    $module,
                    self::AREA,
                    null,
                    $scope,
                    ConfigReader::LOCALE_DEFAULT,
                );
                $row[$k] = $val !== null && $val !== '' ? (string)$val : '';
            }
            $this->smtp[$cacheKey] = $row;
        }
        if ($key !== '') {
            return $this->smtp[$cacheKey][$key] ?? '';
        }

        return $this->smtp[$cacheKey];
    }

    /**
     * @throws \Weline\Framework\App\Exception
     */
    function set(string|array $key, string $data = '', string $module = 'Weline_Smtp', ?string $scope = null): static
    {
        $scope = $this->resolveScope($scope);
        if (is_array($key)) {
            $keys = self::keys;
            $keysOks = [];
            $key['smtp_auth'] = !empty($key['smtp_auth']) ? '1' : '0';
            $key['smtp_secure'] = (string)($key['smtp_secure'] ?? '1');
            $key['smtp_test_address'] = $key['smtp_test_address'] ?? '';
            foreach ($keys as $k) {
                if (isset($key[$k])) {
                    try {
                        $this->set($k, (string)$key[$k], $module, $scope);
                        $keysOks[] = $k;
                    } catch (Exception $e) {
                        throw $e;
                    }
                }
            }
            foreach ($keys as $missing) {
                if (!in_array($missing, $keysOks, true)) {
                    throw new Exception(__('配置项不齐全%{1}', $missing));
                }
            }

            return $this;
        }
        $this->writeScoped($key, $data, $module, $scope);
        unset($this->smtp[$module . '@' . $scope]);

        return $this;
    }

    /**
     * 获取所有发件人配置（含从旧版单配置迁移的 default）
     */
    public function getSenders(string $module = 'Weline_Smtp', ?string $scope = null): array
    {
        $scope = $this->resolveScope($scope);
        $raw = $this->reader->getConfig(
            self::key_smtp_senders,
            $module,
            self::AREA,
            null,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
        );
        if ($raw !== null && $raw !== '') {
            $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $legacy = $this->get('', $module, $scope);
        if (!empty($legacy['smtp_host']) && !empty($legacy['smtp_username'])) {
            return [
                [
                    'code' => 'default',
                    'name' => __('默认发件人'),
                    'source_type' => 'external',
                    'smtp_host' => $legacy['smtp_host'] ?? '',
                    'smtp_port' => $legacy['smtp_port'] ?? '465',
                    'smtp_username' => $legacy['smtp_username'] ?? '',
                    'smtp_password' => $legacy['smtp_password'] ?? '',
                    'smtp_secure' => $legacy['smtp_secure'] ?? '1',
                    'smtp_auth' => $legacy['smtp_auth'] ?? '1',
                    'smtp_test_address' => $legacy['smtp_test_address'] ?? '',
                ],
            ];
        }

        return [];
    }

    /**
     * 按 code 获取发件人配置
     */
    public function getSenderByCode(string $code, string $module = 'Weline_Smtp', ?string $scope = null): ?array
    {
        foreach ($this->getSenders($module, $scope) as $sender) {
            if (($sender['code'] ?? '') === $code) {
                return $sender;
            }
        }

        return null;
    }

    /**
     * 保存发件人列表（完整覆盖当前 scope）
     */
    public function setSenders(array $senders, string $module = 'Weline_Smtp', ?string $scope = null): bool
    {
        $scope = $this->resolveScope($scope);
        $normalized = [];
        foreach ($senders as $sender) {
            if (!is_array($sender)) {
                continue;
            }
            $sender['source_type'] = in_array((string)($sender['source_type'] ?? 'external'), ['external', 'mail_account'], true)
                ? (string)$sender['source_type']
                : 'external';
            $normalized[] = $sender;
        }

        $this->writeScoped(
            self::key_smtp_senders,
            json_encode($normalized, JSON_UNESCAPED_UNICODE),
            $module,
            $scope,
            'json'
        );
        unset($this->smtp[$module . '@' . $scope]);
        // 账户变更后需重新测试确认
        $this->clearSetupConfirmed($module, $scope);

        return true;
    }

    public function isSetupConfirmed(string $module = 'Weline_Smtp', ?string $scope = null): bool
    {
        $scope = $this->resolveScope($scope);
        $resolved = $this->store->resolveConfig(
            self::key_smtp_setup_confirmed,
            $module,
            self::AREA,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
            ''
        );
        if (empty($resolved['found'])) {
            return false;
        }
        // 仅当确认写在当前请求 scope（非继承）时算已确认
        $sourceScope = (string)($resolved['source']['scope'] ?? '');
        if ($sourceScope !== '' && $sourceScope !== $scope) {
            return false;
        }
        return trim((string)($resolved['value'] ?? '')) !== '' && trim((string)($resolved['value'] ?? '')) !== '0';
    }

    public function markSetupConfirmed(string $module = 'Weline_Smtp', ?string $scope = null): bool
    {
        $scope = $this->resolveScope($scope);
        $this->writeScoped(
            self::key_smtp_setup_confirmed,
            date('c'),
            $module,
            $scope,
            'string'
        );
        return true;
    }

    public function clearSetupConfirmed(string $module = 'Weline_Smtp', ?string $scope = null): bool
    {
        $scope = $this->resolveScope($scope);
        $this->writeScoped(
            self::key_smtp_setup_confirmed,
            '',
            $module,
            $scope,
            'string'
        );
        return true;
    }

    /**
     * 解析发件人配置来源（自有 / 继承）。
     *
     * @return array{found:bool,inherited:bool,source_scope:string,senders:array}
     */
    public function resolveSendersProvenance(string $module = 'Weline_Smtp', ?string $scope = null): array
    {
        $scope = $this->resolveScope($scope);
        $resolved = $this->store->resolveConfig(
            self::key_smtp_senders,
            $module,
            self::AREA,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
            null
        );
        $senders = $this->getSenders($module, $scope);
        if ($senders === []) {
            return [
                'found' => false,
                'inherited' => false,
                'source_scope' => '',
                'senders' => [],
            ];
        }
        $sourceScope = (string)($resolved['source']['scope'] ?? $scope);
        $inherited = !empty($resolved['found']) && $sourceScope !== '' && $sourceScope !== $scope;
        // legacy 单账户也可能只在全局
        if (empty($resolved['found'])) {
            $legacy = $this->store->resolveConfig(
                self::smtp_host,
                $module,
                self::AREA,
                $scope,
                ConfigReader::LOCALE_DEFAULT,
                null
            );
            $sourceScope = (string)($legacy['source']['scope'] ?? $scope);
            $inherited = !empty($legacy['found']) && $sourceScope !== '' && $sourceScope !== $scope;
        }

        return [
            'found' => true,
            'inherited' => $inherited,
            'source_scope' => $sourceScope !== '' ? $sourceScope : $scope,
            'senders' => $senders,
        ];
    }

    /**
     * 获取某发件人 code 的默认联系人（收件邮箱）
     */
    public function getSenderContact(string $senderCode, string $module = 'Weline_Smtp', ?string $scope = null): string
    {
        $scope = $this->resolveScope($scope);
        $raw = $this->reader->getConfig(
            self::key_smtp_sender_contacts,
            $module,
            self::AREA,
            null,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
        );
        if ($raw === null || $raw === '') {
            return '';
        }
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim((string)($decoded[$senderCode] ?? ''));
    }

    /**
     * 设置某发件人 code 的默认联系人（收件邮箱），供 w_query 等调用
     */
    public function setSenderContact(string $senderCode, string $toEmail, string $module = 'Weline_Smtp', ?string $scope = null): bool
    {
        $scope = $this->resolveScope($scope);
        $raw = $this->reader->getConfig(
            self::key_smtp_sender_contacts,
            $module,
            self::AREA,
            null,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
        );
        if (is_array($raw)) {
            $contacts = $raw;
        } elseif ($raw !== null && $raw !== '') {
            $decoded = json_decode((string)$raw, true);
            $contacts = is_array($decoded) ? $decoded : [];
        } else {
            $contacts = [];
        }
        $contacts[$senderCode] = $toEmail;
        $this->writeScoped(
            self::key_smtp_sender_contacts,
            json_encode($contacts, JSON_UNESCAPED_UNICODE),
            $module,
            $scope,
            'json'
        );

        return true;
    }

    /** @return array<string, string> */
    public function getChannelBindings(string $module = 'Weline_Smtp', ?string $scope = null): array
    {
        $scope = $this->resolveScope($scope);
        $raw = $this->reader->getConfig(
            self::key_smtp_channel_bindings,
            $module,
            self::AREA,
            null,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
        );
        $source = is_array($raw) ? $raw : ((is_string($raw) && $raw !== '') ? (json_decode($raw, true) ?: []) : []);
        if (!is_array($source)) {
            return [];
        }
        $out = [];
        foreach ($source as $channel => $transport) {
            $channel = trim((string)$channel);
            $transport = trim((string)$transport);
            if ($channel !== '' && $transport !== '') {
                $out[$channel] = $transport;
            }
        }
        return $out;
    }

    /** @param array<string, string> $bindings */
    public function setChannelBindings(array $bindings, string $module = 'Weline_Smtp', ?string $scope = null): bool
    {
        $scope = $this->resolveScope($scope);
        $normalized = [];
        foreach ($bindings as $channel => $transport) {
            $channel = trim((string)$channel);
            $transport = trim((string)$transport);
            if ($channel !== '' && $transport !== '') {
                $normalized[$channel] = $transport;
            }
        }
        $this->writeScoped(
            self::key_smtp_channel_bindings,
            json_encode($normalized, JSON_UNESCAPED_UNICODE),
            $module,
            $scope,
            'json'
        );
        return true;
    }

    public function resolveTransportIdForChannel(string $channel, string $module = 'Weline_Smtp', ?string $scope = null): string
    {
        $channel = trim($channel);
        if ($channel === '') {
            return '';
        }
        return (string)($this->getChannelBindings($module, $scope)[$channel] ?? '');
    }

    public static function isValidTransportId(string $code): bool
    {
        return $code !== '' && preg_match(self::TRANSPORT_ID_PATTERN, $code) === 1;
    }

    public static function generateTransportId(): string
    {
        return 't' . substr(bin2hex(random_bytes(6)), 0, 12);
    }

    private function writeScoped(
        string $key,
        string $value,
        string $module,
        string $scope,
        string $valueType = 'string',
    ): void {
        $this->store->setScopedConfig(
            $key,
            $value,
            $module,
            self::AREA,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
            [
                'value_type' => $valueType,
                'reason' => 'smtp_scoped_config_save',
            ]
        );
    }
}
