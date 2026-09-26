<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * Immutable render bundle held for one request (theme-layout-entity.v3).
 * Structure, config, chrome and head assets all follow this DTO — no re-lookup.
 */
final readonly class EntityRenderBinding
{
    public function __construct(
        public ThemeVersionIdentity $identity,
        public string $source,
        public string $layoutIdentityHash,
        public string $structureKey,
        public string $configKey,
        public string $templatePath,
        public string $configPath,
        public string $assetsPath,
        public string $structurePath,
        public string $shellPath,
        public string $bindingPath,
        public string $chromeImmutableBindingKey = '',
        public int $baseVersionId = 0,
        public string $sourceFingerprint = '',
        public string $injectionFingerprint = '',
    ) {
        if (!\in_array($this->source, ['page', 'chrome'], true)) {
            throw new \InvalidArgumentException('entity_render_binding_source_invalid');
        }
    }

    public function cacheKey(): string
    {
        return \hash('sha256', \json_encode([
            $this->identity->toArray(),
            $this->source,
            $this->layoutIdentityHash,
            $this->structureKey,
            $this->configKey,
            $this->chromeImmutableBindingKey,
            $this->baseVersionId,
            $this->sourceFingerprint,
            $this->injectionFingerprint,
        ], \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toManifestArray(): array
    {
        return [
            'schema' => ThemeLayoutEntityPaths::SCHEMA_BINDING,
            'schema_version' => 3,
            'owner' => [
                'theme_id' => $this->identity->themeId,
                'canonical_scope' => $this->identity->canonicalScope,
                'store_mode' => $this->identity->storeMode,
                'area' => $this->identity->area,
            ],
            'theme_version_id' => $this->identity->themeVersionId,
            'base_version_id' => $this->baseVersionId,
            'mode' => $this->identity->mode,
            'content_revision' => $this->identity->contentRevision,
            'source' => $this->source,
            'layout_identity_hash' => $this->layoutIdentityHash,
            'structure_key' => $this->structureKey,
            'config_key' => $this->configKey,
            'chrome_immutable_binding_key' => $this->chromeImmutableBindingKey,
            'source_fingerprint' => $this->sourceFingerprint,
            'injection_fingerprint' => $this->injectionFingerprint,
            'template_path' => $this->templatePath,
            'config_path' => $this->configPath,
            'assets_path' => $this->assetsPath,
            'structure_path' => $this->structurePath,
            'shell_path' => $this->shellPath,
        ];
    }
}
