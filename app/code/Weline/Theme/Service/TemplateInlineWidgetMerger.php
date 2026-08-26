<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * 模板内嵌部件（w:slot 内的 w:widget）与布局覆盖的 copy-on-write 合并。
 *
 * - 无布局行：保留模板 HTML（由 SlotRenderer 直接 return）
 * - 有布局行且槽内存在 data-weline-template-widget：按 template_ref 合并，未覆盖的模板实例保留
 * - 布局 config.cow_full_slot：整槽以布局 sort_order 为准（排序物化后）
 */
final class TemplateInlineWidgetMerger
{
    public const ATTR_TEMPLATE_WIDGET = 'data-weline-template-widget';
    public const ATTR_TEMPLATE_REF = 'data-template-ref';
    public const CONFIG_TEMPLATE_REF = 'template_ref';
    public const CONFIG_TEMPLATE_DELETED = 'template_deleted';
    public const CONFIG_COW_FULL_SLOT = 'cow_full_slot';

    /**
     * @return list<array{ref:string,html:string,element:\DOMElement}>
     */
    public function extractTemplateWidgets(\DOMElement $slot): array
    {
        $doc = $slot->ownerDocument;
        if (!$doc instanceof \DOMDocument) {
            return [];
        }

        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('.//*[@' . self::ATTR_TEMPLATE_WIDGET . '="1"]', $slot);
        if (!$nodes) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            // 只取槽内顶层模板部件：父链上到 slot 之间不应再有同标记祖先
            $parent = $node->parentNode;
            $nested = false;
            while ($parent instanceof \DOMElement && $parent !== $slot) {
                if ($parent->getAttribute(self::ATTR_TEMPLATE_WIDGET) === '1') {
                    $nested = true;
                    break;
                }
                $parent = $parent->parentNode;
            }
            if ($nested) {
                continue;
            }

            $ref = trim((string)$node->getAttribute(self::ATTR_TEMPLATE_REF));
            if ($ref === '') {
                continue;
            }

            $out[] = [
                'ref' => $ref,
                'html' => $doc->saveHTML($node) ?: '',
                'element' => $node,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{ref:string,html:string,element?:\DOMElement}> $templateWidgets
     * @param list<array<string,mixed>> $layoutWidgets
     * @return list<array{kind:string,html?:string,widget?:array<string,mixed>}>
     */
    public function plan(array $templateWidgets, array $layoutWidgets): array
    {
        if ($templateWidgets === []) {
            return array_map(
                static fn(array $widget): array => ['kind' => 'layout', 'widget' => $widget],
                $layoutWidgets
            );
        }

        if ($this->isFullSlotOverride($layoutWidgets)) {
            $sorted = $layoutWidgets;
            usort($sorted, static function (array $a, array $b): int {
                return ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
            });

            $plan = [];
            foreach ($sorted as $widget) {
                $config = $this->widgetConfig($widget);
                if (!empty($config[self::CONFIG_TEMPLATE_DELETED])) {
                    continue;
                }
                $plan[] = ['kind' => 'layout', 'widget' => $widget];
            }

            return $plan;
        }

        $overrides = [];
        $tombstones = [];
        $additions = [];

        foreach ($layoutWidgets as $widget) {
            $config = $this->widgetConfig($widget);
            $ref = trim((string)($config[self::CONFIG_TEMPLATE_REF] ?? ''));
            if ($ref !== '' && !empty($config[self::CONFIG_TEMPLATE_DELETED])) {
                $tombstones[$ref] = true;
                continue;
            }
            if ($ref !== '') {
                $overrides[$ref] = $widget;
                continue;
            }
            $additions[] = $widget;
        }

        $plan = [];
        foreach ($templateWidgets as $template) {
            $ref = (string)$template['ref'];
            if (isset($tombstones[$ref])) {
                continue;
            }
            if (isset($overrides[$ref])) {
                $plan[] = ['kind' => 'layout', 'widget' => $overrides[$ref]];
                unset($overrides[$ref]);
                continue;
            }
            $plan[] = [
                'kind' => 'template',
                'html' => (string)($template['html'] ?? ''),
            ];
        }

        foreach ($overrides as $widget) {
            $additions[] = $widget;
        }

        usort($additions, static function (array $a, array $b): int {
            return ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
        });

        foreach ($additions as $widget) {
            $plan[] = ['kind' => 'layout', 'widget' => $widget];
        }

        return $plan;
    }

    /**
     * @param list<array<string,mixed>> $layoutWidgets
     */
    public function isFullSlotOverride(array $layoutWidgets): bool
    {
        foreach ($layoutWidgets as $widget) {
            $config = $this->widgetConfig($widget);
            if (!empty($config[self::CONFIG_COW_FULL_SLOT])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $widget
     * @return array<string,mixed>
     */
    private function widgetConfig(array $widget): array
    {
        $config = $widget['config'] ?? ($widget['widget_config'] ?? []);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        return is_array($config) ? $config : [];
    }
}
