<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

/**
 * 使用方提供的内容身份，不发现业务范围，也不反查范围权限。
 * parent 只代表使用方明确提供的当前内容来源，不代表历史 release。
 * 历史资源引用继续保存在不可变版本资源快照中。
 */
final readonly class ThemeContentScope
{
    private const EXTERNAL_PREFIX = '!external!';
    public string $storageScope;
    /** @var list<string> 明确提供的从近到远的范围，包含本范围。 */
    public array $fallbackStorageScopes;

    public function __construct(
        public string $provider,
        public string $scopeKey,
        public string $storeMode,
        public string $displayName,
        public string $defaultLocale,
        public ?self $parent = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $provider) !== 1) {
            throw new \InvalidArgumentException('theme_content_scope_provider_invalid');
        }
        if ($scopeKey === '' || trim($scopeKey) !== $scopeKey || preg_match('/[\x00-\x1f\x7f]/', $scopeKey) === 1) {
            throw new \InvalidArgumentException('theme_content_scope_key_invalid');
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,15}$/D', $storeMode) !== 1) {
            throw new \InvalidArgumentException('theme_content_scope_mode_invalid');
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.@-]{0,127}$/D', $defaultLocale) !== 1) {
            throw new \InvalidArgumentException('theme_content_scope_locale_invalid');
        }
        // 网站使用方证明其旧身份映射。保留前缀不属于旧规范编码，
        // 因此隔离其它提供方时不需要无依据修改历史摘要或目录。
        if ($provider === 'websites' && str_starts_with($scopeKey, self::EXTERNAL_PREFIX)) {
            throw new \InvalidArgumentException('theme_content_scope_reserved_key');
        }
        $storageScope = $provider === 'websites'
            ? $scopeKey
            : self::EXTERNAL_PREFIX . $provider . '.' . $scopeKey;
        if (strlen($storageScope) > 400) {
            throw new \InvalidArgumentException('theme_content_scope_storage_too_long');
        }
        if ($parent !== null && ($parent->provider !== $provider || $parent->storeMode !== $storeMode
            || in_array($storageScope, $parent->fallbackStorageScopes, true))) {
            throw new \InvalidArgumentException('theme_content_scope_parent_invalid');
        }
        $this->storageScope = $storageScope;
        $this->fallbackStorageScopes = [$storageScope, ...($parent?->fallbackStorageScopes ?? [])];
    }

    public function canonicalKey(): string
    {
        return json_encode([$this->provider, $this->storageScope, $this->storeMode], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'scope_key' => $this->scopeKey,
            'storage_scope' => $this->storageScope,
            'store_mode' => $this->storeMode,
            'display_name' => $this->displayName,
            'default_locale' => $this->defaultLocale,
            'parent' => $this->parent?->toArray(),
            'fallback_storage_scopes' => $this->fallbackStorageScopes,
            'canonical_key' => $this->canonicalKey(),
        ];
    }

    /** 恢复明确身份，不从存储字符串推测提供方或父范围。 */
    public static function fromArray(array $data): self
    {
        if (isset($data['parent']) && !is_array($data['parent'])) {
            throw new \InvalidArgumentException('theme_content_scope_parent_invalid');
        }
        $scope = new self(
            provider: (string)($data['provider'] ?? ''),
            scopeKey: (string)($data['scope_key'] ?? ''),
            storeMode: (string)($data['store_mode'] ?? ''),
            displayName: (string)($data['display_name'] ?? ''),
            defaultLocale: (string)($data['default_locale'] ?? ''),
            parent: isset($data['parent']) ? self::fromArray($data['parent']) : null,
        );
        if (isset($data['storage_scope']) && $data['storage_scope'] !== $scope->storageScope) {
            throw new \InvalidArgumentException('theme_content_scope_storage_mismatch');
        }
        return $scope;
    }

    public static function fromApplication(\Weline\Framework\Runtime\ThemeApplicationContext $application): self
    {
        $chain = $application->contentScopes;
        if ($chain === []) {
            return new self($application->provider, $application->scopeKey, $application->storeMode,
                $application->displayName, $application->defaultLocale);
        }
        $parent = null;
        foreach (array_reverse($chain) as $item) {
            $parent = new self((string)$item['provider'], (string)$item['scope_key'], (string)$item['store_mode'],
                (string)($item['display_name'] ?? ''), (string)($item['default_locale'] ?? $application->defaultLocale), $parent);
        }
        return $parent;
    }

    /**
     * 仅解码可信版本记录的 owner；不接受请求参数，也不发现父范围。
     * 无保留前缀的历史身份属于既有 websites 命名空间，保持原存储字节。
     */
    public static function fromStoredOwner(string $storageScope, string $storeMode, string $defaultLocale): self
    {
        $provider = 'websites';
        $scopeKey = $storageScope;
        if (str_starts_with($storageScope, self::EXTERNAL_PREFIX)) {
            $encoded = substr($storageScope, strlen(self::EXTERNAL_PREFIX));
            $separator = strpos($encoded, '.');
            if ($separator === false) {
                throw new \InvalidArgumentException('theme_content_scope_storage_invalid');
            }
            $provider = substr($encoded, 0, $separator);
            $scopeKey = substr($encoded, $separator + 1);
        }
        $scope = new self($provider, $scopeKey, $storeMode, '', $defaultLocale);
        if ($scope->storageScope !== $storageScope) {
            throw new \InvalidArgumentException('theme_content_scope_storage_mismatch');
        }
        return $scope;
    }
}
