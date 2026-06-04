<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI 建站会话运行时：懒加载租借（Lease）大对象，闭包结束脱水归还。
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
            throw new \InvalidArgumentException('artifactKey 不能为空');
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
            throw new \InvalidArgumentException('artifactKey 不能为空');
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
            throw new \InvalidArgumentException('pageType 与 blockId 不能为空');
        }

        $manifest = $this->loadScopeManifest($session);
        $virtualThemeId = (int)($manifest['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0) {
            throw new \RuntimeException('virtual_theme_id 未就绪，无法 withBlock');
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
            throw new \InvalidArgumentException('pageType 不能为空');
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
        $pages = \is_array($manifest['virtual_pages_by_type'] ?? null) ? $manifest['virtual_pages_by_type'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        foreach (\is_array($page['block_nodes'] ?? null) ? $page['block_nodes'] : [] as $block) {
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
            throw new \RuntimeException('VirtualThemeComponent 未找到: ' . $componentCode);
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
        $index = \is_array($manifest['virtual_page_index'] ?? null)
            ? $manifest['virtual_page_index']
            : $this->manifestPolicy()->buildVirtualPageIndex(
                \is_array($manifest['virtual_pages_by_type'] ?? null) ? $manifest['virtual_pages_by_type'] : []
            );

        $pageIndex = \is_array($index[$pageType] ?? null) ? $index[$pageType] : ['page_type' => $pageType, 'block_nodes' => []];
        $blocks = \is_array($pageIndex['block_nodes'] ?? null) ? $pageIndex['block_nodes'] : [];
        $found = false;
        foreach ($blocks as $idx => $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if ((string)($entry['block_id'] ?? '') === $blockId) {
                $blocks[$idx]['hash'] = $hash;
                $blocks[$idx]['status'] = 'ready';
                $found = true;
                break;
            }
        }
        if (!$found) {
            $blocks[] = ['block_id' => $blockId, 'hash' => $hash, 'status' => 'ready', 'component_code' => ''];
        }
        $pageIndex['block_nodes'] = $blocks;
        $index[$pageType] = $pageIndex;
        $manifest['virtual_page_index'] = $index;

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
