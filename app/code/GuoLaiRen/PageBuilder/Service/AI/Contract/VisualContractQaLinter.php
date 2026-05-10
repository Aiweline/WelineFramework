<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

/**
 * 对照 reference_image_insights.visual_contract 与阶段一方案 / 布局产物，做未使用项与禁止视觉命中检查。
 */
final class VisualContractQaLinter
{
    private const CUE_MIN_LENGTH = 3;

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $renderPayload buildRenderDataContractPayload 结果
     * @return array{visual_contract_unused:list<string>,forbidden_visuals_hit:list<string>}
     */
    public function analyze(array $scope, array $renderPayload): array
    {
        $insights = \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [];
        $vc = \is_array($insights['visual_contract'] ?? null) ? $insights['visual_contract'] : [];
        if ($vc === [] || $this->isVisualContractEmpty($vc)) {
            return ['visual_contract_unused' => [], 'forbidden_visuals_hit' => []];
        }

        $haystack = $this->buildHaystack($scope, $renderPayload);

        $unused = [];
        foreach ($this->collectImplementableCues($vc) as $cue) {
            if (\mb_strlen($cue) < self::CUE_MIN_LENGTH) {
                continue;
            }
            if (!$this->containsInsensitive($haystack, $cue)) {
                $unused[] = $cue;
            }
        }

        $forbiddenHits = [];
        foreach ($this->stringList($vc['forbidden_visuals'] ?? [], 12) as $forbidden) {
            if ($forbidden !== '' && $this->containsInsensitive($haystack, $forbidden)) {
                $forbiddenHits[] = $forbidden;
            }
        }

        return [
            'visual_contract_unused' => $unused,
            'forbidden_visuals_hit' => $forbiddenHits,
        ];
    }

    /**
     * @param array<string, mixed> $vc
     */
    private function isVisualContractEmpty(array $vc): bool
    {
        $impl = $this->collectImplementableCues($vc);
        $forbidden = $this->stringList($vc['forbidden_visuals'] ?? [], 12);

        return $impl === [] && $forbidden === [];
    }

    /**
     * @param array<string, mixed> $vc
     * @return list<string>
     */
    private function collectImplementableCues(array $vc): array
    {
        $cues = [];
        $hero = \is_array($vc['hero_composition'] ?? null) ? $vc['hero_composition'] : [];
        foreach ($hero as $value) {
            $this->pushStringCue($cues, $value);
        }
        $cta = \is_array($vc['cta_rule'] ?? null) ? $vc['cta_rule'] : [];
        foreach (['primary_color', 'label_intent'] as $key) {
            $this->pushStringCue($cues, $cta[$key] ?? null);
        }
        $asset = \is_array($vc['asset_usage_rule'] ?? null) ? $vc['asset_usage_rule'] : [];
        if (isset($asset['reference_image_role'])) {
            $this->pushStringCue($cues, $asset['reference_image_role']);
        }

        return \array_values(\array_unique(\array_filter($cues, static fn(string $s): bool => $s !== '')));
    }

    /**
     * @param list<string> $cues
     */
    private function pushStringCue(array &$cues, mixed $value): void
    {
        if (!\is_string($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
            return;
        }
        $text = \trim((string)$value);
        if ($text !== '') {
            $cues[] = $text;
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values, int $limit): array
    {
        if (!\is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '' && !\in_array($text, $out, true)) {
                $out[] = $text;
            }
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $renderPayload
     */
    private function buildHaystack(array $scope, array $renderPayload): string
    {
        $parts = [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $parts[] = $this->aggregatePlanJsonText($planJson);
        $parts[] = $this->aggregateRenderPayloadText($renderPayload);

        return \trim(\preg_replace('/\s+/u', ' ', \implode(' ', $parts)) ?? '');
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function aggregatePlanJsonText(array $planJson): string
    {
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $parts = [];
        foreach ($pages as $page) {
            if (!\is_array($page)) {
                continue;
            }
            foreach (['page_goal', 'theme_alignment_summary'] as $key) {
                $parts[] = \trim((string)($page[$key] ?? ''));
            }
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                foreach (['content', 'goal'] as $key) {
                    $parts[] = \trim((string)($block[$key] ?? ''));
                }
                foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
                    if (\is_array($field)) {
                        $parts[] = \trim((string)($field['sample'] ?? ''));
                    }
                }
                $script = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
                $parts[] = \trim((string)($script['core_copy'] ?? ''));
            }
        }

        $theme = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [];
        $parts[] = \trim((string)\json_encode($theme, \JSON_UNESCAPED_UNICODE));

        return \implode(' ', \array_filter($parts, static fn(string $s): bool => $s !== ''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function aggregateRenderPayloadText(array $payload): string
    {
        $parts = [];
        $shared = \is_array($payload['shared_components'] ?? null) ? $payload['shared_components'] : [];
        foreach ($shared as $comp) {
            if (\is_array($comp)) {
                $parts[] = (string)($comp['html'] ?? '');
            }
        }
        $layouts = \is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [];
        foreach ($layouts as $layout) {
            if (!\is_array($layout)) {
                continue;
            }
            foreach (['title', 'description', 'h1'] as $key) {
                $parts[] = \trim((string)($layout[$key] ?? ''));
            }
            foreach (\is_array($layout['content'] ?? null) ? $layout['content'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                foreach (['title', 'description', 'html', 'html_content'] as $key) {
                    $parts[] = \trim((string)($block[$key] ?? ''));
                }
                $tags = \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [];
                $parts[] = \trim((string)\json_encode($tags, \JSON_UNESCAPED_UNICODE));
            }
        }

        return \implode(' ', \array_filter($parts, static fn(string $s): bool => $s !== ''));
    }

    private function containsInsensitive(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return \mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
}
