<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI 寤虹珯浼氳瘽杩愯鏃讹細鎳掑姞杞界鍊燂紙Lease锛夊ぇ瀵硅薄锛岄棴鍖呯粨鏉熻劚姘村綊杩樸€?
 */
final class AiSiteSessionRuntime
{
    public function __construct(
        private readonly ?AiSiteAgentSessionService $sessionService = null,
        private readonly ?AiSiteAgentSessionArtifactService $artifactService = null,
        private readonly ?AiSiteScopeManifestPolicy $manifestPolicy = null,
        private readonly ?AiSiteVirtualThemeService $virtualThemeService = null,
        private readonly ?PageRenderService $pageRenderService = null,
        private readonly ?AiSiteQualityGateService $qualityGateService = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function loadScopeManifest(AiSiteAgentSession $session): array
    {
        return $this->sessionService()->loadScopeManifest($session);
    }

    /**
     * @param callable(mixed): mixed $callback
     */
    public function withArtifact(
        AiSiteAgentSession $session,
        int $adminId,
        string $artifactKey,
        callable $callback
    ): mixed {
        $artifactKey = \trim($artifactKey);
        if ($artifactKey === '') {
            throw new \InvalidArgumentException('artifactKey 涓嶈兘涓虹┖');
        }

        $sessionId = (int)$session->getId();
        try {
            $manifest = $this->loadScopeManifest($session);
            $payload = $this->loadArtifactPayload($sessionId, $manifest, $artifactKey);
            $working = $payload;
            $result = $callback($working);

            if (!$this->artifactPayloadsEqual($payload, $working)) {
                $manifest = $this->injectArtifactIntoManifest($manifest, $artifactKey, $working);
                $this->persistManifest($session, $adminId, $manifest, [$artifactKey]);
            }

            return $result;
        } finally {
            unset($working, $payload, $result);
            $this->artifactService()->releasePayloadCache();
        }
    }

    /**
     * @param callable(mixed): mixed $callback
     */
    public function readArtifact(
        AiSiteAgentSession $session,
        string $artifactKey,
        callable $callback
    ): mixed {
        $artifactKey = \trim($artifactKey);
        if ($artifactKey === '') {
            throw new \InvalidArgumentException('artifactKey 涓嶈兘涓虹┖');
        }

        $sessionId = (int)$session->getId();
        try {
            $manifest = $this->loadScopeManifest($session);
            $payload = $this->loadArtifactPayload($sessionId, $manifest, $artifactKey);
            return $callback($payload);
        } finally {
            unset($payload, $manifest);
            $this->artifactService()->releasePayloadCache();
        }
    }

    /**
     * @param callable(array<string, mixed>): mixed $callback
     */
    public function withBlock(
        AiSiteAgentSession $session,
        int $adminId,
        string $pageType,
        string $blockId,
        callable $callback
    ): mixed {
        $pageType = \trim($pageType);
        $blockId = \trim($blockId);
        if ($pageType === '' || $blockId === '') {
            throw new \InvalidArgumentException('pageType 涓?blockId 涓嶈兘涓虹┖');
        }

        $manifest = $this->loadScopeManifest($session);
        $virtualThemeId = (int)($manifest['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0) {
            throw new \RuntimeException('virtual_theme_id 鏈氨缁紝鏃犳硶 withBlock');
        }

        $component = $this->resolveVirtualThemeComponent($virtualThemeId, $pageType, $blockId, $manifest);
        $componentData = $this->componentToArray($component);
        $originalHash = \sha1($this->stableJson($componentData));

        $result = $callback($componentData);

        $nextHash = \sha1($this->stableJson($componentData));
        if ($nextHash !== $originalHash) {
            $this->applyComponentArray($component, $componentData);
            $component->save();
            $manifest = $this->updateBlockIndexEntry($manifest, $pageType, $blockId, $nextHash);
            $this->sessionService()->patchScopeManifest((int)$session->getId(), $adminId, $manifest);
        }

        unset($componentData, $component);

        return $result;
    }

    /**
     * @param callable(string): mixed $callback
     */
    public function withRenderedPage(
        AiSiteAgentSession $session,
        string $pageType,
        callable $callback
    ): mixed {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            throw new \InvalidArgumentException('pageType 涓嶈兘涓虹┖');
        }

        $manifest = $this->loadScopeManifest($session);
        $html = $this->qualityGateService()->renderPageHtmlForInspection($manifest, $pageType);
        try {
            return $callback($html);
        } finally {
            unset($html);
            $this->artifactService()->releasePayloadCache();
        }
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function patchManifest(AiSiteAgentSession $session, int $adminId, array $patch): array
    {
        return $this->sessionService()->patchScopeManifest((int)$session->getId(), $adminId, $patch);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function loadArtifactPayload(int $sessionId, array $manifest, string $artifactKey): mixed
    {
        if ($this->manifestPolicy()->hasInlinePayload($manifest, $artifactKey)) {
            return $manifest[$artifactKey];
        }

        $hydrated = $this->artifactService()->hydrateScope($sessionId, $manifest, [$artifactKey]);
        $path = $this->artifactService()->artifactPath($artifactKey);
        if ($path === []) {
            return [];
        }

        return $this->getPathValue($hydrated, $path, $this->emptyArtifact($artifactKey));
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function injectArtifactIntoManifest(array $manifest, string $artifactKey, mixed $payload): array
    {
        $path = $this->artifactService()->artifactPath($artifactKey);
        if ($path === []) {
            $manifest[$artifactKey] = $payload;

            return $manifest;
        }

        return $this->setPathValue($manifest, $path, $payload);
    }

    /**
     * @param list<string> $touchedArtifactKeys
     * @param array<string, mixed> $manifest
     */
    private function persistManifest(
        AiSiteAgentSession $session,
        int $adminId,
        array $manifest,
        array $touchedArtifactKeys
    ): void {
        $this->sessionService()->replaceScope((int)$session->getId(), $adminId, $manifest, $touchedArtifactKeys);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function resolveVirtualThemeComponent(
        int $virtualThemeId,
        string $pageType,
        string $blockId,
        array $manifest
    ): VirtualThemeComponent {
        $componentCode = $blockId;
        $planJson = \is_array($manifest['plan_json'] ?? null) ? $manifest['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $candidate = \trim((string)($block['block_id'] ?? $block['id'] ?? ''));
            if ($candidate === $blockId) {
                $componentCode = \trim((string)($block['component_code'] ?? $block['component'] ?? $componentCode));
                break;
            }
        }

        /** @var VirtualThemeComponent $model */
        $model = ObjectManager::getInstance(VirtualThemeComponent::class);
        $model->clearData()->clearQuery()
            ->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
            ->find()
            ->fetch();

        if ((int)$model->getId() <= 0) {
            throw new \RuntimeException('VirtualThemeComponent 鏈壘鍒? ' . $componentCode);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function componentToArray(VirtualThemeComponent $component): array
    {
        $configRaw = (string)$component->getData(VirtualThemeComponent::schema_fields_DEFAULT_CONFIG);
        $config = [];
        if ($configRaw !== '') {
            try {
                $decoded = \json_decode($configRaw, true, 512, \JSON_THROW_ON_ERROR);
                $config = \is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $config = [];
            }
        }

        return [
            'component_code' => (string)$component->getData(VirtualThemeComponent::schema_fields_COMPONENT_CODE),
            'phtml' => (string)$component->getData(VirtualThemeComponent::schema_fields_TEMPLATE_CONTENT),
            'default_config' => $config,
        ];
    }

    /**
     * @param array<string, mixed> $componentData
     */
    private function applyComponentArray(VirtualThemeComponent $component, array $componentData): void
    {
        if (\array_key_exists('phtml', $componentData)) {
            $component->setData(
                VirtualThemeComponent::schema_fields_TEMPLATE_CONTENT,
                (string)$componentData['phtml']
            );
        }
        if (\array_key_exists('default_config', $componentData)) {
            $config = \is_array($componentData['default_config'] ?? null) ? $componentData['default_config'] : [];
            try {
                $component->setData(
                    VirtualThemeComponent::schema_fields_DEFAULT_CONFIG,
                    (string)\json_encode($config, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
                );
            } catch (\JsonException) {
                $component->setData(VirtualThemeComponent::schema_fields_DEFAULT_CONFIG, '{}');
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function updateBlockIndexEntry(array $manifest, string $pageType, string $blockId, string $hash): array
    {
        if (!\is_array($manifest['plan_json'] ?? null)) {
            $manifest['plan_json'] = [];
        }
        if (!\is_array($manifest['plan_json']['pages'] ?? null)) {
            $manifest['plan_json']['pages'] = [];
        }
        if (!\is_array($manifest['plan_json']['pages'][$pageType] ?? null)) {
            $manifest['plan_json']['pages'][$pageType] = ['page_type' => $pageType];
        }
        foreach ($manifest['plan_json']['pages'][$pageType] as $blockKey => $block) {
            if (!\is_string($blockKey) || !\is_array($block)) {
                continue;
            }
            $candidate = \trim((string)($block['block_id'] ?? $block['id'] ?? $blockKey));
            if ($candidate !== $blockId) {
                continue;
            }
            $manifest['plan_json']['pages'][$pageType][$blockKey]['hash'] = $hash;
            $manifest['plan_json']['pages'][$pageType][$blockKey]['status'] = 1;

            return $this->manifestPolicy()->dehydrateScopePaths($manifest);
        }
        $manifest['plan_json']['pages'][$pageType][$blockId] = [
            'block_id' => $blockId,
            'hash' => $hash,
            'status' => 1,
            'component_code' => '',
        ];

        return $this->manifestPolicy()->dehydrateScopePaths($manifest);
    }

    private function stableJson(mixed $value): string
    {
        try {
            return (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
    }

    private function artifactPayloadsEqual(mixed $left, mixed $right): bool
    {
        if (\is_array($left) && \is_array($right)) {
            return $left == $right;
        }

        if (\is_object($left) || \is_object($right)) {
            return $left == $right;
        }

        return $left === $right;
    }

    /**
     * @param list<string> $path
     */
    private function getPathValue(array $data, array $path, mixed $empty): mixed
    {
        $cursor = $data;
        foreach ($path as $segment) {
            if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                return $empty;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param list<string> $path
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function setPathValue(array $data, array $path, mixed $value): array
    {
        if ($path === []) {
            return \is_array($value) ? $value : $data;
        }

        $cursor = &$data;
        $last = \array_pop($path);
        foreach ($path as $segment) {
            if (!isset($cursor[$segment]) || !\is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor[$last] = $value;

        return $data;
    }

    private function emptyArtifact(string $artifactKey): mixed
    {
        unset($artifactKey);

        return [];
    }

    private function sessionService(): AiSiteAgentSessionService
    {
        return $this->sessionService ?? ObjectManager::getInstance(AiSiteAgentSessionService::class);
    }

    private function artifactService(): AiSiteAgentSessionArtifactService
    {
        return $this->artifactService ?? ObjectManager::getInstance(AiSiteAgentSessionArtifactService::class);
    }

    private function manifestPolicy(): AiSiteScopeManifestPolicy
    {
        return $this->manifestPolicy ?? ObjectManager::getInstance(AiSiteScopeManifestPolicy::class);
    }

    private function qualityGateService(): AiSiteQualityGateService
    {
        return $this->qualityGateService ?? ObjectManager::getInstance(AiSiteQualityGateService::class);
    }
}
