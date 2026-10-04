<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/** 使用方完成范围与权限校验后，安装到当前请求的不可变主题输入。 */
final readonly class ThemeApplicationContext
{
    public const REQUEST_KEY_PREFIX = 'theme.application.context.v1.';

    /**
     * @param list<array<string,mixed>> $contentScopes 使用方提供的从近到远的内容身份与资源引用。
     * @param array<string,mixed> $assetAccessClaims 由使用方校验，原样传给文件所属公开验证契约。
     * @param list<string> $invalidationNamespaces 使用方明确供应的失效范围，Theme 不解释网站身份。
     */
    public function __construct(
        public string $provider,
        public string $scopeKey,
        public string $storeMode,
        public string $area,
        public int $themeId,
        public string $versionOwnerScope,
        public string $versionOwnerStoreMode,
        public int $themeVersionId,
        public int $contentRevision,
        public string $defaultLocale,
        public string $displayName = '',
        public array $contentScopes = [],
        public string $purpose = 'runtime',
        public array $assetAccessClaims = [],
        public array $invalidationNamespaces = [],
        public array $affectedContentContexts = [],
    ) {
        // theme_id=0 允许：Theme 模块包默认（不依赖库表注册行）；负 id 非法。
        if (!in_array($area, ['frontend','backend'], true) || $themeId < 0
            || $themeVersionId < 0 || $contentRevision < 0 || $scopeKey === ''
            || $versionOwnerScope === '' || $storeMode === '' || $versionOwnerStoreMode === ''
            || $defaultLocale === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $provider) !== 1
            || !in_array($purpose, ['runtime','editor','preview','asset'], true)) {
            throw new \InvalidArgumentException('theme_application_context_invalid');
        }
        foreach ($contentScopes as $index => $scope) {
            if (!is_array($scope) || ($scope['provider'] ?? '') !== $provider
                || ($scope['store_mode'] ?? '') !== $storeMode || empty($scope['scope_key'])) {
                throw new \InvalidArgumentException('theme_application_content_scope_invalid');
            }
            if ($index === 0 && $scope['scope_key'] !== $scopeKey) {
                throw new \InvalidArgumentException('theme_application_content_scope_head_mismatch');
            }
        }
    }

    /** 每个请求固定一次选择；缓存失效不能将本请求改为另一个内容来源。 */
    public function install(): void
    {
        $existing = self::current($this->area, $this->purpose);
        if ($existing !== null && $existing !== $this && $existing->toArray() !== $this->toArray()) {
            throw new \LogicException('theme_application_context_already_fixed');
        }
        RequestContext::set(self::REQUEST_KEY_PREFIX . $this->area . '.' . $this->purpose, $this);
    }

    public static function current(string $area, string $purpose = 'runtime'): ?self
    {
        $context = RequestContext::get(self::REQUEST_KEY_PREFIX . $area . '.' . $purpose);
        return $context instanceof self ? $context : null;
    }

    public function toArray(): array
    {
        return [
            'provider'=>$this->provider, 'scope_key'=>$this->scopeKey, 'store_mode'=>$this->storeMode,
            'area'=>$this->area, 'theme_id'=>$this->themeId, 'version_owner_scope'=>$this->versionOwnerScope,
            'version_owner_store_mode'=>$this->versionOwnerStoreMode, 'theme_version_id'=>$this->themeVersionId,
            'content_revision'=>$this->contentRevision, 'default_locale'=>$this->defaultLocale,
            'display_name'=>$this->displayName, 'content_scopes'=>$this->contentScopes,
            'purpose'=>$this->purpose, 'asset_access_claims'=>$this->assetAccessClaims,
            'invalidation_namespaces'=>$this->invalidationNamespaces,
            'affected_content_contexts'=>$this->affectedContentContexts,
        ];
    }

    /** 仅供使用方可信持久化边界恢复数据，不能据此授权浏览器传来的声明。 */
    public static function fromArray(array $data): self
    {
        return new self(
            provider:(string)($data['provider'] ?? ''), scopeKey:(string)($data['scope_key'] ?? ''),
            storeMode:(string)($data['store_mode'] ?? ''), area:(string)($data['area'] ?? ''),
            themeId:(int)($data['theme_id'] ?? 0), versionOwnerScope:(string)($data['version_owner_scope'] ?? ''),
            versionOwnerStoreMode:(string)($data['version_owner_store_mode'] ?? ''),
            themeVersionId:(int)($data['theme_version_id'] ?? 0), contentRevision:(int)($data['content_revision'] ?? 0),
            defaultLocale:(string)($data['default_locale'] ?? ''), displayName:(string)($data['display_name'] ?? ''),
            contentScopes:(array)($data['content_scopes'] ?? []), purpose:(string)($data['purpose'] ?? 'runtime'),
            assetAccessClaims:(array)($data['asset_access_claims'] ?? []),
            invalidationNamespaces:(array)($data['invalidation_namespaces'] ?? []),
            affectedContentContexts:(array)($data['affected_content_contexts'] ?? []),
        );
    }
}
