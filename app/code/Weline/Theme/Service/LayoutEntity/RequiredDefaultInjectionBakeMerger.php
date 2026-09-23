<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemePublishedVersionRuntimeResolver;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/**
 * Bake-time merge of required JSON default_injections into layout/chrome node maps.
 *
 * Authority: Theme/doc/布局固化与默认注入.md
 * — slot exists + no user_deleted@{versionId} ⇒ node must be in solidified template.
 * — Page-level identity once: wrong-slot drift is relocated; never dual-write into
 *   parent `content` plus the declared nested slot.
 */
final class RequiredDefaultInjectionBakeMerger
{
    /**
     * @param array<string|int, mixed> $nodes Flat node map (uid => node)
     * @return array<string, array<string, mixed>>
     */
    public function mergeIntoNodes(array $nodes, int $themeId, string $pageType, ?int $versionId = null, array $changes = [], ?array $omissionsOverride = null, string $layoutOption = 'default'): array
    {
        $pageType = \trim($pageType);
        if ($themeId < 1 || $pageType === '') {
            return $this->normalizeNodeMap($nodes);
        }

        foreach ($changes as &$change) {
            $change['before'] = $this->injectionsForLayoutOption($change['before'] ?? [], $layoutOption);
            $change['after'] = $this->injectionsForLayoutOption($change['after'] ?? [], $layoutOption);
        }
        unset($change);
        $nodes = $this->removeRetiredAutomaticNodes($this->normalizeNodeMap($nodes), $pageType, $changes);
        $bySlot = $this->nodesToBySlot($nodes);
        $declarations = $this->loadDeclarations();
        foreach ($declarations as &$declaration) {
            $declaration['default_injections'] = $this->injectionsForLayoutOption($declaration['default_injections'] ?? [], $layoutOption);
        }
        unset($declaration);
        if ($declarations === []) {
            return $this->normalizeNodeMap($nodes);
        }

        $omissions = $omissionsOverride ?? $this->omissionsFor($themeId, $pageType, $versionId);
        $mergedSlots = RequiredDefaultInjectionContract::merge(
            $bySlot,
            $pageType,
            $declarations,
            $omissions,
        );

        $merged = $this->bySlotToNodes($mergedSlots);
        foreach ($merged as $uid => &$node) {
            if (!isset($nodes[$uid])) {
                $node['source'] = 'default_injection';
            }
        }
        unset($node);
        return $merged;
    }

    public function injectionsForLayoutOption(array $injections, string $layoutOption): array
    {
        $items = $injections === [] || array_is_list($injections) ? $injections : [$injections];
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $option = trim((string)($item['layout_option'] ?? 'default')) ?: 'default';
            if ($option !== '*' && $option !== $layoutOption) { continue; }
            // Contract sees an already selected option; keep its legacy default-only API stable.
            $item['layout_option'] = 'default';
            $out[] = $item;
        }
        return $out;
    }

    /** Removing a declaration must never delete a manually installed instance. */
    public function unresolvedRetiredNodes(array $nodes, string $pageType, array $changes): array
    {
        $unresolved = [];
        foreach ($changes as $change) {
            if (($change['before'] ?? []) === ($change['after'] ?? [])) {
                continue;
            }
            $identity = $change['widget_identity'] ?? [];
            $declaration = ['module' => $identity['module'] ?? '', 'type' => $identity['type'] ?? '', 'code' => $identity['code'] ?? ''];
            $old = RequiredDefaultInjectionContract::requiredTargets([
                $declaration + ['default_injections' => $change['before'] ?? []],
            ], $pageType);
            foreach ($old as $target) {
                foreach ($nodes as $uid => $node) {
                    if (!is_array($node) || trim((string)($node['source'] ?? $node['config']['_source'] ?? '')) !== '') {
                        continue;
                    }
                    if (($node['slot_id'] ?? '') === $target['slot_id']
                        && ($node['widget_module'] ?? '') === $target['widget_module']
                        && ($node['widget_code'] ?? '') === $target['widget_code']) {
                        $unresolved[(string)$uid] = $target + [
                            'node_uid' => (string)($node['node_uid'] ?? $uid),
                            'reason' => 'automatic_ownership_unknown',
                        ];
                    }
                }
            }
        }
        return array_values($unresolved);
    }

    /** Removing a declaration must never delete a manually installed instance. */
    public function removeRetiredAutomaticNodes(array $nodes, string $pageType, array $changes): array
    {
        foreach ($changes as $change) {
            if (($change['before'] ?? []) === ($change['after'] ?? [])) {
                continue;
            }
            $identity = $change['widget_identity'] ?? [];
            $declaration = ['module' => $identity['module'] ?? '', 'type' => $identity['type'] ?? '', 'code' => $identity['code'] ?? ''];
            $old = RequiredDefaultInjectionContract::requiredTargets([
                $declaration + ['default_injections' => $change['before'] ?? []],
            ], $pageType);
            $new = RequiredDefaultInjectionContract::requiredInjections([
                $declaration + ['default_injections' => $change['after'] ?? []],
            ], $pageType);
            foreach ($old as $retired) {
                foreach ($nodes as $uid => $node) {
                    if (!is_array($node) || !in_array($node['source'] ?? $node['config']['_source'] ?? '', ['auto', 'default_injection'], true)) {
                        continue;
                    }
                    if (($node['slot_id'] ?? '') === $retired['slot_id']
                        && ($node['widget_module'] ?? '') === $retired['widget_module']
                        && ($node['widget_code'] ?? '') === $retired['widget_code']) {
                        $replacement = null;
                        foreach ($new as $candidate) {
                            if ($replacement === null || $candidate['slot_id'] === $retired['slot_id']) {
                                $replacement = $candidate['node'];
                            }
                        }
                        if ($replacement === null) {
                            unset($nodes[$uid]);
                        } else {
                            // Preserve instance/config while updating only declaration-owned structure.
                            foreach (['slot_id', 'area', 'sort_order'] as $field) {
                                $nodes[$uid][$field] = $replacement[$field];
                            }
                        }
                    }
                }
            }
        }
        return $nodes;
    }

    /**
     * Exact layout_type values that appear on required default_injections (no '*').
     *
     * @return list<string>
     */
    public function involvedExactLayoutTypes(): array
    {
        $types = [];
        foreach ($this->loadDeclarations() as $declaration) {
            $injections = $declaration['default_injections'] ?? [];
            if (!\is_array($injections)) {
                continue;
            }
            $isList = $injections === [] || \array_keys($injections) === \range(0, \count($injections) - 1);
            foreach ($isList ? $injections : [$injections] as $injection) {
                if (!\is_array($injection) || !$this->isRequired($injection['required'] ?? false)) {
                    continue;
                }
                $layoutType = \trim((string)($injection['layout_type'] ?? ''));
                if ($layoutType === '' || $layoutType === '*') {
                    continue;
                }
                $types[$layoutType] = true;
            }
        }
        $list = \array_keys($types);
        \sort($list);

        return $list;
    }

    public function hasWildcardRequired(): bool
    {
        foreach ($this->loadDeclarations() as $declaration) {
            $injections = $declaration['default_injections'] ?? [];
            if (!\is_array($injections)) {
                continue;
            }
            $isList = $injections === [] || \array_keys($injections) === \range(0, \count($injections) - 1);
            foreach ($isList ? $injections : [$injections] as $injection) {
                if (!\is_array($injection) || !$this->isRequired($injection['required'] ?? false)) {
                    continue;
                }
                $layoutType = \trim((string)($injection['layout_type'] ?? ''));
                if ($layoutType === '' || $layoutType === '*') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadDeclarations(): array
    {
        try {
            /** @var DefaultInjectionPlanRepository $plans */
            $plans = ObjectManager::getInstance(DefaultInjectionPlanRepository::class);

            return $plans->listDeclarations('frontend');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function omissionsFor(int $themeId, string $pageType, ?int $versionId = null): array
    {
        try {
            /** @var ThemePublishedVersionRuntimeResolver $versions */
            if ($versionId === null) {
                $versions = ObjectManager::getInstance(ThemePublishedVersionRuntimeResolver::class);
                $versionId = (int)($versions->resolve($themeId, $pageType)['themePublishedVersionId'] ?? 0);
            }
            /** @var WidgetDefaultInjectionService $injections */
            $injections = ObjectManager::getInstance(WidgetDefaultInjectionService::class);

            return $injections->uninstalledInjectionsForVersion($themeId, $pageType, $versionId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string|int, mixed> $nodes
     * @return array<string, list<array<string, mixed>>>
     */
    private function nodesToBySlot(array $nodes): array
    {
        $bySlot = [];
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $slotId = \trim((string)($node['slot_id'] ?? ''));
            if ($slotId === '') {
                $slotId = \trim((string)($node['area'] ?? 'content'));
            }
            if ($slotId === '') {
                $slotId = 'content';
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $key)));
            if ($uid !== '') {
                $node['node_uid'] = $uid;
            }
            $bySlot[$slotId][] = $node;
        }

        return $bySlot;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $bySlot
     * @return array<string, array<string, mixed>>
     */
    private function bySlotToNodes(array $bySlot): array
    {
        $out = [];
        foreach ($bySlot as $slotId => $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                if ($uid === '') {
                    $module = \trim((string)($widget['widget_module'] ?? ''));
                    $code = \trim((string)($widget['widget_code'] ?? ''));
                    $uid = \substr(\hash('sha256', $slotId . '|' . $module . '|' . $code), 0, 32);
                }
                $widget['node_uid'] = $uid;
                if (\trim((string)($widget['slot_id'] ?? '')) === '') {
                    $widget['slot_id'] = (string)$slotId;
                }
                $out[$uid] = $widget;
            }
        }

        return $out;
    }

    /**
     * @param array<string|int, mixed> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodeMap(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)($node['node_uid'] ?? $key)));
            if ($uid === '') {
                continue;
            }
            $node['node_uid'] = $uid;
            $out[$uid] = $node;
        }

        return $out;
    }

    private function isRequired(mixed $required): bool
    {
        return $required === true || $required === 1 || $required === '1' || $required === 'true';
    }
}
