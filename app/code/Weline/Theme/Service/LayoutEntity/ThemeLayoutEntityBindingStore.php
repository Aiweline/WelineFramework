<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * theme-layout-entity.v3 binding store.
 * Rejects v1/v2 and r/d/s entity keys — no schema fallback.
 */
final class ThemeLayoutEntityBindingStore
{
    private readonly AtomicCompiledFilePublisher $publisher;

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        ?AtomicCompiledFilePublisher $publisher = null,
    ) {
        $this->publisher = $publisher ?? new AtomicCompiledFilePublisher();
    }

    public function readPageBinding(ThemeVersionIdentity $identity, string $layoutIdentityHash): ?EntityRenderBinding
    {
        return $this->read(
            $this->paths->pageBindingJson($identity, $layoutIdentityHash),
            $identity,
            'page',
            $layoutIdentityHash,
        );
    }

    public function readChromeBinding(ThemeVersionIdentity $identity): ?EntityRenderBinding
    {
        return $this->read(
            $this->paths->chromeBindingJson($identity),
            $identity,
            'chrome',
            '',
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $assets
     */
    public function publishPageBinding(
        ThemeVersionIdentity $identity,
        string $layoutIdentityHash,
        string $structureKey,
        array $config,
        array $assets,
        int $baseVersionId = 0,
        string $chromeImmutableBindingKey = '',
        string $sourceFingerprint = '',
        string $injectionFingerprint = '',
    ): EntityRenderBinding {
        return $this->publish(
            $this->paths->pageBindingJson($identity, $layoutIdentityHash),
            $identity,
            'page',
            $layoutIdentityHash,
            $structureKey,
            $config,
            $assets,
            $baseVersionId,
            $chromeImmutableBindingKey,
            $sourceFingerprint,
            $injectionFingerprint,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $assets
     */
    public function publishChromeBinding(
        ThemeVersionIdentity $identity,
        string $structureKey,
        array $config,
        array $assets,
        int $baseVersionId = 0,
        string $sourceFingerprint = '',
        string $injectionFingerprint = '',
    ): EntityRenderBinding {
        return $this->publish(
            $this->paths->chromeBindingJson($identity),
            $identity,
            'chrome',
            '',
            $structureKey,
            $config,
            $assets,
            $baseVersionId,
            '',
            $sourceFingerprint,
            $injectionFingerprint,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $assets
     */
    private function publish(
        string $manifest,
        ThemeVersionIdentity $identity,
        string $source,
        string $layoutIdentityHash,
        string $structureKey,
        array $config,
        array $assets,
        int $baseVersionId,
        string $chromeImmutableBindingKey,
        string $sourceFingerprint,
        string $injectionFingerprint,
    ): EntityRenderBinding {
        $structureKey = $this->normalizeStructureKey($structureKey);
        $configJson = $this->encode($config);
        $assetsJson = $this->encode($assets);
        $configKey = \hash('sha256', $configJson . "\n" . $assetsJson);
        $binding = $this->binding(
            $identity,
            $source,
            $layoutIdentityHash,
            $structureKey,
            $configKey,
            $baseVersionId,
            $chromeImmutableBindingKey,
            $sourceFingerprint,
            $injectionFingerprint,
        );
        if (!\is_file($binding->templatePath) || !\is_file($binding->structurePath)) {
            throw new \RuntimeException('theme_layout_binding_structure_missing');
        }
        foreach ([$binding->configPath => $configJson, $binding->assetsPath => $assetsJson] as $path => $content) {
            if (!\is_file($path)) {
                $this->publisher->publish($path, $content);
            }
        }
        $manifestJson = $this->encode($binding->toManifestArray());
        if (!\is_file($manifest) || \file_get_contents($manifest) !== $manifestJson) {
            $this->publisher->publish($manifest, $manifestJson);
        }
        if (\class_exists(RequestContext::class)) {
            RequestContext::set('theme.layout_entity.' . $source . '_binding.v3.'
                . $binding->cacheKey(), null);
        }

        return $binding;
    }

    private function read(
        string $manifest,
        ThemeVersionIdentity $identity,
        string $source,
        string $layoutIdentityHash,
    ): ?EntityRenderBinding {
        if (!\is_file($manifest)) {
            return null;
        }
        $data = \json_decode((string)\file_get_contents($manifest), true);
        if (!\is_array($data)) {
            return null;
        }
        // Hard reject legacy schemas — no v1/v2 fallback.
        if (($data['schema'] ?? null) !== ThemeLayoutEntityPaths::SCHEMA_BINDING
            && ($data['schema_version'] ?? null) !== 3
        ) {
            return null;
        }
        if (($data['schema_version'] ?? null) !== 3) {
            return null;
        }
        $structureKey = (string)($data['structure_key'] ?? '');
        $configKey = (string)($data['config_key'] ?? '');
        try {
            $structureKey = $this->normalizeStructureKey($structureKey);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (\preg_match('/^[a-f0-9]{64}$/D', $configKey) !== 1) {
            return null;
        }
        // Manifest owner/version must match the requested identity — no cross-tv reuse.
        if ((int)($data['theme_version_id'] ?? 0) !== $identity->themeVersionId
            || (string)($data['mode'] ?? '') !== $identity->mode
            || (int)($data['content_revision'] ?? 0) !== $identity->contentRevision
        ) {
            return null;
        }

        return $this->binding(
            $identity,
            $source,
            $layoutIdentityHash !== '' ? $layoutIdentityHash : (string)($data['layout_identity_hash'] ?? ''),
            $structureKey,
            $configKey,
            (int)($data['base_version_id'] ?? 0),
            (string)($data['chrome_immutable_binding_key'] ?? ''),
            (string)($data['source_fingerprint'] ?? ''),
            (string)($data['injection_fingerprint'] ?? ''),
        );
    }

    private function binding(
        ThemeVersionIdentity $identity,
        string $source,
        string $layoutIdentityHash,
        string $structureKey,
        string $configKey,
        int $baseVersionId,
        string $chromeImmutableBindingKey,
        string $sourceFingerprint,
        string $injectionFingerprint,
    ): EntityRenderBinding {
        if ($source === 'chrome') {
            $templatePath = $this->paths->chromePhtml($identity, $structureKey);
            $structurePath = $this->paths->chromeStructureDir($identity, $structureKey) . 'structure.json';
            $configPath = $this->paths->chromeConfigJson($identity, $configKey);
            $assetsPath = $this->paths->chromeAssetsJson($identity, $configKey);
            $shellPath = '';
            $bindingPath = $this->paths->chromeBindingJson($identity);
        } else {
            $layoutIdentityHash = $this->paths->identityKey($layoutIdentityHash);
            $templatePath = $this->paths->pagePhtml($identity, $layoutIdentityHash, $structureKey);
            $structurePath = $this->paths->pageStructureJson($identity, $layoutIdentityHash, $structureKey);
            $configPath = $this->paths->pageConfigJson($identity, $layoutIdentityHash, $configKey);
            $assetsPath = $this->paths->pageAssetsJson($identity, $layoutIdentityHash, $configKey);
            $shellPath = $this->paths->shellPhtml($identity, $layoutIdentityHash, $structureKey);
            $bindingPath = $this->paths->pageBindingJson($identity, $layoutIdentityHash);
        }

        return new EntityRenderBinding(
            identity: $identity,
            source: $source,
            layoutIdentityHash: $layoutIdentityHash,
            structureKey: $structureKey,
            configKey: $configKey,
            templatePath: $templatePath,
            configPath: $configPath,
            assetsPath: $assetsPath,
            structurePath: $structurePath,
            shellPath: $shellPath,
            bindingPath: $bindingPath,
            chromeImmutableBindingKey: $chromeImmutableBindingKey,
            baseVersionId: $baseVersionId,
            sourceFingerprint: $sourceFingerprint,
            injectionFingerprint: $injectionFingerprint,
        );
    }

    private function normalizeStructureKey(string $structureKey): string
    {
        $structureKey = \strtolower(\trim($structureKey));
        if (\preg_match('/^s([a-f0-9]{64})$/D', $structureKey, $m) === 1) {
            return $m[1];
        }
        if (\preg_match('/^[a-f0-9]{64}$/D', $structureKey) === 1) {
            return $structureKey;
        }
        throw new \InvalidArgumentException('theme_layout_binding_structure_key_invalid');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . "\n";
    }
}
