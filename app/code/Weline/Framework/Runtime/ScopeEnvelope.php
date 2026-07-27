<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * 通用异步 Scope 信封（P1b，v1）。
 *
 * 任何异步任务（Queue、outbox、事件投递）携带的 Scope 身份统一使用本信封：
 * - 包裹不可变 {@see ScopeIdentity}；
 * - `envelope_version` 允许后续演进；
 * - 可序列化为数组/JSON 存入固定列；
 * - `capture()` 从当前请求上下文捕获（无上下文 → global）。
 */
final class ScopeEnvelope
{
    public const VERSION = 'v1';
    private const V1_WEBSITE_CODE_MAX_BYTES = 64;
    private const V1_WEBSITE_ID_MAX = 2147483647;

    private const V1_STORAGE_FIELDS = [
        'scope_kind',
        'website_id',
        'website_code',
        'store_code',
        'channel_code',
        'store_mode',
        'envelope_version',
    ];

    private function __construct(
        public readonly ScopeIdentity $scope,
        public readonly string $envelopeVersion,
    ) {
        self::assertEnvelopeVersion($envelopeVersion);
    }

    public static function of(ScopeIdentity $scope): self
    {
        return new self($scope, self::VERSION);
    }

    /**
     * 从当前请求上下文捕获完整三段 Scope；无请求上下文时返回 global 信封。
     */
    public static function capture(): self
    {
        $websiteId = RequestContext::websiteId();
        if ($websiteId === null) {
            return self::of(ScopeIdentity::global());
        }
        $websiteCode = RequestContext::getWelineWebsiteCode();
        if ($websiteCode === '') {
            if ($websiteId !== 0) {
                throw new \InvalidArgumentException('非零 Website Scope 缺少 website_code');
            }
            $websiteCode = 'default';
        }
        $storeCode = RequestContext::getWelineStoreCode();
        $channelCode = RequestContext::getWelineChannelCode();
        $storeMode = RequestContext::getWelineStoreMode();

        if ($storeCode === '') {
            if ($channelCode !== '' || $storeMode !== '') {
                throw new \InvalidArgumentException('Website Scope 不允许缺少 Store 却携带 Channel/store_mode');
            }
            return self::of(ScopeIdentity::website($websiteId, $websiteCode));
        }
        if ($storeMode === '') {
            throw new \InvalidArgumentException('Store/Channel Scope 缺少 store_mode');
        }
        if ($channelCode === '') {
            return self::of(ScopeIdentity::store($websiteId, $websiteCode, $storeCode, $storeMode));
        }

        return self::of(ScopeIdentity::channel($websiteId, $websiteCode, $storeCode, $channelCode, $storeMode));
    }

    /**
     * @return array<string, mixed> scope 数组 + envelope_version
     */
    public function toArray(): array
    {
        return $this->scope->toArray() + ['envelope_version' => $this->envelopeVersion];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists('envelope_version', $data)
            || !is_string($data['envelope_version'])) {
            throw new \InvalidArgumentException('ScopeEnvelope 缺少规范 envelope_version');
        }

        return new self(ScopeIdentity::fromArray($data), $data['envelope_version']);
    }

    /**
     * Queue v1 固定列尚未单独持久化 context_version。兼容只允许在这个
     * 命名边界内把 v1 存储形状升级为 canonical identity；核心 parser
     * 仍严格要求全部字段。
     *
     * @param array<string, mixed> $data
     */
    public static function fromV1StorageArray(array $data): self
    {
        $expectedFields = self::V1_STORAGE_FIELDS;
        $actualFields = array_keys($data);
        sort($expectedFields);
        sort($actualFields);
        if ($actualFields !== $expectedFields) {
            throw new \InvalidArgumentException('旧 ScopeEnvelope 存储字段不完整或包含未知字段');
        }

        $envelopeVersion = $data['envelope_version'];
        if (!is_string($envelopeVersion) || $envelopeVersion !== self::VERSION) {
            throw new \InvalidArgumentException('旧 ScopeEnvelope 存储只支持显式 v1');
        }

        $websiteId = $data['website_id'];
        if (is_string($websiteId) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $websiteId) === 1) {
            $normalizedWebsiteId = (int)$websiteId;
            if ((string)$normalizedWebsiteId !== $websiteId) {
                throw new \InvalidArgumentException('ScopeEnvelope v1 website_id 超出可存储范围');
            }
            $websiteId = $normalizedWebsiteId;
        } elseif ($websiteId === '') {
            $websiteId = null;
        }

        if (is_int($websiteId) && $websiteId > self::V1_WEBSITE_ID_MAX) {
            throw new \InvalidArgumentException('ScopeEnvelope v1 website_id 超出可存储范围');
        }

        $websiteCode = self::storageString($data['website_code']);
        if ($websiteCode !== null && strlen($websiteCode) > self::V1_WEBSITE_CODE_MAX_BYTES) {
            throw new \InvalidArgumentException('ScopeEnvelope v1 website_code 超出 64 字节固定列');
        }

        $canonical = [
            'scope_kind' => self::storageString($data['scope_kind'], false),
            'website_id' => $websiteId,
            'website_code' => $websiteCode,
            'store_code' => self::storageString($data['store_code']),
            'channel_code' => self::storageString($data['channel_code']),
            'store_mode' => self::storageString($data['store_mode']),
            'context_version' => ScopeIdentity::CONTEXT_VERSION,
            'envelope_version' => self::VERSION,
        ];

        return self::fromArray($canonical);
    }

    /**
     * @return array{scope_kind:string,website_id:?int,website_code:?string,store_code:?string,channel_code:?string,store_mode:?string,envelope_version:string}
     */
    public function toV1StorageArray(): array
    {
        if ($this->envelopeVersion !== self::VERSION
            || $this->scope->contextVersion !== ScopeIdentity::CONTEXT_VERSION) {
            throw new \LogicException(
                'Queue v1 固定列不能保存未来 Scope/context version，必须先扩展存储契约',
            );
        }
        if (($this->scope->websiteId ?? 0) > self::V1_WEBSITE_ID_MAX
            || ($this->scope->websiteCode !== null
                && strlen($this->scope->websiteCode) > self::V1_WEBSITE_CODE_MAX_BYTES)) {
            throw new \LogicException(
                'Queue v1 固定列无法无损保存当前 Scope，必须先扩展存储契约',
            );
        }

        return [
            'scope_kind' => $this->scope->scopeKind,
            'website_id' => $this->scope->websiteId,
            'website_code' => $this->scope->websiteCode,
            'store_code' => $this->scope->storeCode,
            'channel_code' => $this->scope->channelCode,
            'store_mode' => $this->scope->storeMode,
            'envelope_version' => $this->envelopeVersion,
        ];
    }

    public function canonicalKey(): string
    {
        return $this->envelopeVersion . '|' . $this->scope->canonicalKey();
    }

    private static function assertEnvelopeVersion(string $version): void
    {
        if (preg_match('/^v[1-9][0-9]*$/D', $version) !== 1) {
            throw new \InvalidArgumentException('无效 envelope_version：' . $version);
        }
    }

    private static function storageString(mixed $value, bool $emptyAsNull = true): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('ScopeEnvelope v1 存储字段类型无效');
        }
        if (trim($value) !== $value) {
            throw new \InvalidArgumentException('ScopeEnvelope v1 存储字符串必须是规范值');
        }
        if ($value === '' && $emptyAsNull) {
            return null;
        }

        return $value;
    }
}
