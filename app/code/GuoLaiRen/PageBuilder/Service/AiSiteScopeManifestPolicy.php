<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * ScopeManifest 白名单 / 黑名单与脱水规则。
 *
 * 持久层 scope_json 应只保留小对象；大 artifact 与块 HTML 不得内联驻留。
 */
final class AiSiteScopeManifestPolicy
{
    public const MANIFEST_INLINE_MAX_BYTES = 131072;

    /** @var list<string> */
    public const INLINE_ARTIFACT_KEYS = [
        'plan_json',
        'plan_markdown',
        'plan_projection',
        'content_manifest',
        'plan_workbench',
        'build_workbench',
        'build_contracts',
        'render_data_contract',
        'task_results',
        'qa_report',
        'qa_report_v2',
        'repair_patch',
        'theme_css',
    ];

    /** @var list<string> */
    public const BLOCK_PAYLOAD_KEYS = [
        'html',
        'html_content',
        'css_extra',
        'css_responsive',
        'css_content',
        'php_variables',
        'extra_fields',
        'js_content',
        'template_phtml',
    ];

    /**
     * @param array<string, mixed> $scope
     */
    public function assertManifestClean(array $scope, bool $strict = true): void
    {
        foreach (self::INLINE_ARTIFACT_KEYS as $key) {
            if (!$this->hasInlineArtifactPayload($scope, $key)) {
                continue;
            }
            if (!$strict) {
                continue;
            }
            throw new \InvalidArgumentException('ScopeManifest 禁止内联大 artifact: ' . $key);
        }

        $this->assertVirtualPagesClean($scope);
        $encoded = $this->estimateJsonBytes($scope);
        if ($strict && $encoded > self::MANIFEST_INLINE_MAX_BYTES) {
            throw new \InvalidArgumentException(
                'ScopeManifest 内联体积超限: ' . $encoded . ' bytes (max ' . self::MANIFEST_INLINE_MAX_BYTES . ')'
            );
        }
    }

    /**
     * 从 manifest 摘掉 artifact 路径与块 HTML，保留 refs 与小字段。
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function dehydrateScopePaths(array $scope): array
    {
        foreach (self::INLINE_ARTIFACT_KEYS as $key) {
            if (\array_key_exists($key, $scope)) {
                $scope[$key] = $this->emptyValueForArtifactKey($key);
            }
        }

        unset(
            $scope['confirmed_stage1_plan_book'],
            $scope['theme_context_snapshot'],
            $scope['shared_prompt_context'],
            $scope['theme_css']
        );

        if (\is_array($scope['theme_css_ref'] ?? null)) {
            unset($scope['theme_css_ref']['css']);
        }

        if (\is_array($scope['virtual_pages_by_type'] ?? null)) {
            $scope['virtual_pages_by_type'] = $this->stripBlockPayloadFromVirtualPages($scope['virtual_pages_by_type']);
        }

        $scope['virtual_page_index'] = $this->buildVirtualPageIndex(
            \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : []
        );

        return $scope;
    }

    /**
     * @param array<string, mixed> $virtualPages
     * @return array<string, mixed>
     */
    public function buildVirtualPageIndex(array $virtualPages): array
    {
        $index = [];
        foreach ($virtualPages as $pageType => $pageData) {
            if (!\is_array($pageData)) {
                continue;
            }
            $blocks = \is_array($pageData['blocks'] ?? null) ? $pageData['blocks'] : [];
            $entries = [];
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockId = \trim((string)($block['block_id'] ?? $block['id'] ?? $block['code'] ?? ''));
                if ($blockId === '') {
                    continue;
                }
                $entries[] = [
                    'block_id' => $blockId,
                    'component_code' => \trim((string)($block['component_code'] ?? $block['component'] ?? '')),
                    'status' => \trim((string)($block['status'] ?? 'ready')),
                    'hash' => \trim((string)($block['hash'] ?? $block['content_hash'] ?? '')),
                ];
            }
            $index[(string)$pageType] = [
                'page_type' => (string)$pageType,
                'blocks' => $entries,
            ];
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function estimateJsonBytes(array $scope): int
    {
        try {
            return \strlen((string)\json_encode($scope, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return \PHP_INT_MAX;
        }
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function hasInlinePayload(array $scope, string $key): bool
    {
        return $this->hasInlineArtifactPayload($scope, $key);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasInlineArtifactPayload(array $scope, string $key): bool
    {
        if (!\array_key_exists($key, $scope)) {
            return false;
        }

        return $this->hasPayload($scope[$key], $key);
    }

    private function hasPayload(mixed $value, string $key): bool
    {
        if (\is_string($value)) {
            return \trim($value) !== '';
        }
        if (\is_array($value)) {
            if ($value === []) {
                return false;
            }
            if ($key === 'plan_markdown') {
                return \trim((string)($value['markdown'] ?? $value['content'] ?? '')) !== '';
            }

            return true;
        }

        return $value !== null && $value !== false;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function assertVirtualPagesClean(array $scope): void
    {
        $pages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        foreach ($pages as $pageType => $pageData) {
            if (!\is_array($pageData)) {
                continue;
            }
            foreach (\is_array($pageData['blocks'] ?? null) ? $pageData['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                foreach (self::BLOCK_PAYLOAD_KEYS as $payloadKey) {
                    $payload = $block[$payloadKey] ?? null;
                    if (\is_string($payload) && \strlen(\trim($payload)) > 512) {
                        throw new \InvalidArgumentException(
                            'ScopeManifest 禁止 virtual_pages 内联大块 ' . $payloadKey . ' @ ' . (string)$pageType
                        );
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $virtualPages
     * @return array<string, mixed>
     */
    private function stripBlockPayloadFromVirtualPages(array $virtualPages): array
    {
        foreach ($virtualPages as $pageType => $pageData) {
            if (!\is_array($pageData)) {
                continue;
            }
            $blocks = \is_array($pageData['blocks'] ?? null) ? $pageData['blocks'] : [];
            foreach ($blocks as $idx => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                foreach (self::BLOCK_PAYLOAD_KEYS as $payloadKey) {
                    unset($block[$payloadKey]);
                }
                $blocks[$idx] = $block;
            }
            $pageData['blocks'] = $blocks;
            $virtualPages[$pageType] = $pageData;
        }

        return $virtualPages;
    }

    private function emptyValueForArtifactKey(string $key): mixed
    {
        return match ($key) {
            'plan_markdown' => '',
            default => [],
        };
    }
}
