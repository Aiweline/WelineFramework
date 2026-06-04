<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * ScopeManifest 鐧藉悕鍗?/ 榛戝悕鍗曚笌鑴辨按瑙勫垯銆? *
 * 鎸佷箙灞?scope_json 搴斿彧淇濈暀灏忓璞★紱澶?artifact 涓庡潡 HTML 涓嶅緱鍐呰仈椹荤暀銆? */
final class AiSiteScopeManifestPolicy
{
    public const MANIFEST_INLINE_MAX_BYTES = 131072;

    /** @var list<string> */
    public const INLINE_ARTIFACT_KEYS = [
        'content_manifest',
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
            throw new \InvalidArgumentException('ScopeManifest 绂佹鍐呰仈澶?artifact: ' . $key);
        }

        $this->assertPlanJsonPagesClean($scope);
        $encoded = $this->estimateJsonBytes($scope);
        if ($strict && $encoded > self::MANIFEST_INLINE_MAX_BYTES) {
            throw new \InvalidArgumentException(
                'ScopeManifest 鍐呰仈浣撶Н瓒呴檺: ' . $encoded . ' bytes (max ' . self::MANIFEST_INLINE_MAX_BYTES . ')'
            );
        }
    }

    /**
     * 浠?manifest 鎽樻帀 artifact 璺緞涓庡潡 HTML锛屼繚鐣?refs 涓庡皬瀛楁銆?     *
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
            $scope['theme_context_snapshot'],
            $scope['shared_prompt_context'],
            $scope['theme_css']
        );

        if (\is_array($scope['theme_css_ref'] ?? null)) {
            unset($scope['theme_css_ref']['css']);
        }

        return $scope;
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
            return true;
        }

        return $value !== null && $value !== false;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function assertPlanJsonPagesClean(array $scope): void
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
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
                            'ScopeManifest 绂佹 virtual_pages 鍐呰仈澶у潡 ' . $payloadKey . ' @ ' . (string)$pageType
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
    private function stripBlockPayloadFromPlanJson(array $planJson): array
    {
        $virtualPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
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

        $planJson['pages'] = $virtualPages;

        return $planJson;
    }

    private function emptyValueForArtifactKey(string $key): mixed
    {
        unset($key);

        return [];
    }
}
