<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * 不可变 Scope 身份（website.store.channel 三段 + store_mode）。
 *
 * - `scope_kind`：global|website|store|channel 判别联合；
 * - `website_id=0`（code=default）是合法系统默认站，不代表 Global；
 * - Global 使用 kind=global 且三段全空；
 * - 构造后不可变；比较使用 canonical key。
 */
final class ScopeIdentity
{
    public const CONTEXT_VERSION = 'v1';

    public const KIND_GLOBAL = 'global';
    public const KIND_WEBSITE = 'website';
    public const KIND_STORE = 'store';
    public const KIND_CHANNEL = 'channel';
    public const KINDS = [self::KIND_GLOBAL, self::KIND_WEBSITE, self::KIND_STORE, self::KIND_CHANNEL];

    public const MODE_NORMAL = 'normal';
    public const MODE_DEV = 'dev';
    public const MODE_TEST = 'test';

    private function __construct(
        public readonly string $scopeKind,
        public readonly ?int $websiteId,
        public readonly ?string $websiteCode,
        public readonly ?string $storeCode,
        public readonly ?string $channelCode,
        public readonly ?string $storeMode,
        public readonly string $contextVersion,
    ) {
        self::assertContextVersion($contextVersion);
    }

    private const SERIALIZED_FIELDS = [
        'scope_kind',
        'website_id',
        'website_code',
        'store_code',
        'channel_code',
        'store_mode',
        'context_version',
    ];

    public static function global(string $contextVersion = self::CONTEXT_VERSION): self
    {
        return new self(self::KIND_GLOBAL, null, null, null, null, null, $contextVersion);
    }

    public static function website(
        int $websiteId,
        string $websiteCode,
        string $contextVersion = self::CONTEXT_VERSION,
    ): self
    {
        self::assertWebsite($websiteId, $websiteCode);
        return new self(self::KIND_WEBSITE, $websiteId, $websiteCode, null, null, null, $contextVersion);
    }

    public static function store(
        int $websiteId,
        string $websiteCode,
        string $storeCode,
        string $storeMode,
        string $contextVersion = self::CONTEXT_VERSION,
    ): self {
        self::assertWebsite($websiteId, $websiteCode);
        self::assertSegment($storeCode, 'store_code');
        self::assertStoreMode($storeMode);
        return new self(self::KIND_STORE, $websiteId, $websiteCode, $storeCode, null, $storeMode, $contextVersion);
    }

    public static function channel(
        int $websiteId,
        string $websiteCode,
        string $storeCode,
        string $channelCode,
        string $storeMode,
        string $contextVersion = self::CONTEXT_VERSION,
    ): self {
        self::assertWebsite($websiteId, $websiteCode);
        self::assertSegment($storeCode, 'store_code');
        self::assertSegment($channelCode, 'channel_code');
        self::assertStoreMode($storeMode);
        return new self(self::KIND_CHANNEL, $websiteId, $websiteCode, $storeCode, $channelCode, $storeMode, $contextVersion);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $missingFields = array_values(array_diff(self::SERIALIZED_FIELDS, array_keys($data)));
        if ($missingFields !== []) {
            throw new \InvalidArgumentException(
                'ScopeIdentity 缺少必填字段：' . implode(', ', $missingFields),
            );
        }

        $kind = $data['scope_kind'];
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(
                '无效 scope_kind：' . (is_scalar($kind) ? (string)$kind : get_debug_type($kind)),
            );
        }

        $websiteId = self::websiteIdOrNull($data['website_id']);
        $websiteCode = self::stringOrNull($data['website_code'], 'website_code');
        $storeCode = self::stringOrNull($data['store_code'], 'store_code');
        $channelCode = self::stringOrNull($data['channel_code'], 'channel_code');
        $storeMode = self::stringOrNull($data['store_mode'], 'store_mode');
        $contextVersion = $data['context_version'];
        if (!is_string($contextVersion)) {
            throw new \InvalidArgumentException('context_version 必须是字符串');
        }
        self::assertContextVersion($contextVersion);

        return match ($kind) {
            self::KIND_GLOBAL => self::fromGlobalClaims(
                $websiteId,
                $websiteCode,
                $storeCode,
                $channelCode,
                $storeMode,
                $contextVersion,
            ),
            self::KIND_WEBSITE => self::fromWebsiteClaims(
                $websiteId,
                $websiteCode,
                $storeCode,
                $channelCode,
                $storeMode,
                $contextVersion,
            ),
            self::KIND_STORE => self::fromStoreClaims(
                $websiteId,
                $websiteCode,
                $storeCode,
                $channelCode,
                $storeMode,
                $contextVersion,
            ),
            self::KIND_CHANNEL => self::fromChannelClaims(
                $websiteId,
                $websiteCode,
                $storeCode,
                $channelCode,
                $storeMode,
                $contextVersion,
            ),
        };
    }

    /**
     * @return array{scope_kind: string, website_id: ?int, website_code: ?string, store_code: ?string, channel_code: ?string, store_mode: ?string, context_version: string}
     */
    public function toArray(): array
    {
        return [
            'scope_kind' => $this->scopeKind,
            'website_id' => $this->websiteId,
            'website_code' => $this->websiteCode,
            'store_code' => $this->storeCode,
            'channel_code' => $this->channelCode,
            'store_mode' => $this->storeMode,
            'context_version' => $this->contextVersion,
        ];
    }

    /**
     * canonical key：用于锁、幂等、缓存键。kind 必含，非空段逐一拼接。
     */
    public function canonicalKey(): string
    {
        return implode('|', [
            $this->scopeKind,
            $this->websiteId === null ? '' : (string)$this->websiteId,
            (string)$this->websiteCode,
            (string)$this->storeCode,
            (string)$this->channelCode,
            (string)$this->storeMode,
            $this->contextVersion,
        ]);
    }

    /**
     * 兼容三段字符串 scope（website.store.channel），Global 返回空串。
     */
    public function toLegacyScopeString(): string
    {
        if ($this->scopeKind === self::KIND_GLOBAL) {
            return '';
        }
        $website = $this->websiteCode ?? ScopeContext::DEFAULT_SEGMENT;
        $store = $this->storeCode ?? ScopeContext::DEFAULT_SEGMENT;
        $channel = $this->channelCode ?? ScopeContext::DEFAULT_SEGMENT;
        return $website . '.' . $store . '.' . $channel;
    }

    public function isGlobal(): bool
    {
        return $this->scopeKind === self::KIND_GLOBAL;
    }

    public function equals(self $other): bool
    {
        return $this->canonicalKey() === $other->canonicalKey();
    }

    private static function assertWebsite(int $websiteId, string $websiteCode): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('website_id 不能为负数（0 是合法系统默认站）');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,254}$/D', $websiteCode) !== 1) {
            throw new \InvalidArgumentException('website_code 格式无效');
        }
    }

    private static function assertSegment(string $value, string $field): void
    {
        if (trim($value) !== $value
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' 格式无效');
        }
    }

    private static function assertStoreMode(string $mode): void
    {
        if (!in_array($mode, [self::MODE_NORMAL, self::MODE_DEV, self::MODE_TEST], true)) {
            throw new \InvalidArgumentException('无效 store_mode：' . $mode);
        }
    }

    private static function assertContextVersion(string $contextVersion): void
    {
        if (preg_match('/^v[1-9][0-9]*$/D', $contextVersion) !== 1) {
            throw new \InvalidArgumentException('无效 context_version：' . $contextVersion);
        }
    }

    private static function websiteIdOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('website_id 必须是非负整数或 null');
        }

        return $value;
    }

    private static function fromGlobalClaims(
        ?int $websiteId,
        ?string $websiteCode,
        ?string $storeCode,
        ?string $channelCode,
        ?string $storeMode,
        string $contextVersion,
    ): self {
        if ($websiteId !== null || $websiteCode !== null || $storeCode !== null || $channelCode !== null || $storeMode !== null) {
            throw new \InvalidArgumentException('Global Scope 的三段和 store_mode 必须全空');
        }
        return self::global($contextVersion);
    }

    private static function fromWebsiteClaims(
        ?int $websiteId,
        ?string $websiteCode,
        ?string $storeCode,
        ?string $channelCode,
        ?string $storeMode,
        string $contextVersion,
    ): self {
        self::assertNonGlobalWebsiteId($websiteId);
        if ($storeCode !== null || $channelCode !== null || $storeMode !== null) {
            throw new \InvalidArgumentException('Website Scope 不允许携带 Store/Channel/store_mode');
        }
        return self::website($websiteId, (string)$websiteCode, $contextVersion);
    }

    private static function fromStoreClaims(
        ?int $websiteId,
        ?string $websiteCode,
        ?string $storeCode,
        ?string $channelCode,
        ?string $storeMode,
        string $contextVersion,
    ): self {
        self::assertNonGlobalWebsiteId($websiteId);
        if ($channelCode !== null) {
            throw new \InvalidArgumentException('Store Scope 不允许携带 channel_code');
        }
        return self::store($websiteId, (string)$websiteCode, (string)$storeCode, (string)$storeMode, $contextVersion);
    }

    private static function fromChannelClaims(
        ?int $websiteId,
        ?string $websiteCode,
        ?string $storeCode,
        ?string $channelCode,
        ?string $storeMode,
        string $contextVersion,
    ): self {
        self::assertNonGlobalWebsiteId($websiteId);
        return self::channel(
            $websiteId,
            (string)$websiteCode,
            (string)$storeCode,
            (string)$channelCode,
            (string)$storeMode,
            $contextVersion,
        );
    }

    private static function assertNonGlobalWebsiteId(?int $websiteId): void
    {
        if ($websiteId === null) {
            throw new \InvalidArgumentException('非 Global Scope 必须显式提供 website_id（0 是合法默认站）');
        }
    }

    private static function stringOrNull(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '' || trim($value) !== $value) {
            throw new \InvalidArgumentException($field . ' 必须是规范非空字符串或 null');
        }

        return $value;
    }
}
