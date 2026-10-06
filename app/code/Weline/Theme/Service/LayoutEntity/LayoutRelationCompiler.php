<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemePlaceableRegistry;

/** Converts placements to ordinary PHP calls without serializing an executable layout tree. */
final class LayoutRelationCompiler
{
    public function __construct(private readonly ?ThemePlaceableRegistry $registry = null, private readonly ?WelineTheme $theme = null) {}

    /** @param array<string|int,array<string,mixed>> $nodes */
    public function compile(string $source, array $nodes, array $configByUid = [], array $localeOverrides = [], ?ThemeVersionIdentity $identity = null): string
    {
        $indexed = [];
        $clearAll = false;
        foreach ($nodes as $key => $node) {
            if (!is_array($node)) { continue; }
            if (($node['widget_code'] ?? '') === '__no_widget_placements__') { $clearAll = true; continue; }
            $uid = (string)($node['node_uid'] ?? $key);
            $node['node_uid'] = $uid;
            if (isset($configByUid[$uid]) && is_array($configByUid[$uid])) {
                $override = $configByUid[$uid];
                $node['config'] = isset($override['config']) && is_array($override['config']) ? $override['config'] : $override;
            }
            $indexed[$uid] = $node;
        }
        if (!$clearAll) { $indexed = $this->discoverNativeOwners($source, $indexed, true); }
        $groups = [];
        $children = [];
        $sourceSlots = self::slotIds($source);
        $sourceParents = [];
        self::transform($source, static function (array $element, string $inner) use (&$sourceParents): string {
            $id = self::slotId($element);
            if ($id !== null) { $sourceParents[$id] = $element['slotAncestors']; }
            return $inner;
        });
        $owners = [];
        foreach ($indexed as $uid => $node) {
            foreach ($this->componentSlots($node) as $slot) { $owners[$slot][] = $uid; }
        }
        foreach ($indexed as $uid => $node) {
            $slot = (string)($node['slot_id'] ?? $node['meta']['config']['slot'] ?? $node['area'] ?? 'content');
            $parent = (string)($node['parent_uid'] ?? '');
            if ($parent === '' && count($owners[$slot] ?? []) === 1 && $owners[$slot][0] !== $uid) {
                $candidate = $owners[$slot][0];
                $parentSlot = (string)($indexed[$candidate]['slot_id'] ?? $indexed[$candidate]['area'] ?? 'content');
                if (!isset($sourceSlots[$slot]) || in_array($parentSlot, $sourceParents[$slot] ?? [], true)) { $parent = $candidate; }
            }
            if ($parent !== '' && isset($indexed[$parent])) { $children[$parent][$slot][] = $uid; }
            else { $groups[$slot][] = $uid; }
        }
        $sort = static function (array &$uids) use ($indexed): void {
            usort($uids, static fn(string $a, string $b): int => ((int)($indexed[$a]['sort_order'] ?? 0)) <=> ((int)($indexed[$b]['sort_order'] ?? 0)));
        };
        foreach ($groups as &$uids) { $sort($uids); } unset($uids);
        foreach ($children as &$slots) { foreach ($slots as &$uids) { $sort($uids); } unset($uids); } unset($slots);
        $renderSlots = function (string $uid, array $ancestors = []) use (&$renderNode, $children): string {
            $callbacks = [];
            foreach ($children[$uid] ?? [] as $slot => $uids) {
                $calls = array_map(fn(string $child): string => $renderNode($child, $ancestors), $uids);
                $callbacks[] = var_export((string)$slot, true) . ' => static function (): string { return ' . ($calls === [] ? "''" : implode(' . ', $calls)) . '; }';
            }
            return '[' . implode(', ', $callbacks) . ']';
        };
        $renderNode = function (string $uid, array $ancestors = [], array $sourceAttributes = [], string $dynamicParams = '') use ($renderSlots, $indexed, $localeOverrides, $identity): string {
            if (isset($ancestors[$uid])) { throw new \RuntimeException('theme_layout_node_parent_cycle'); }
            $node = $indexed[$uid];
            if ((array_key_exists('is_active', $node) && !$node['is_active']) || !empty($node['config']['template_deleted']) || ($node['source'] ?? '') === 'user_deleted') { return "''"; }
            $ancestors[$uid] = true;
            $entry = array_intersect_key($node, array_flip(['node_uid','widget_module','widget_type','widget_code','config','is_active','slot_id','layout_option','scope','target_type','target_id','layout_source','source','source_position']));
            $sourceParams = json_decode((string)($sourceAttributes['params'] ?? '{}'), true);
            $sourceParams = is_array($sourceParams) ? $sourceParams : [];
            if ($sourceAttributes !== []) {
                $entry['config'] = array_replace($entry['config'] ?? [], $sourceParams, $node['_explicit_config'] ?? $node['config'] ?? []);
                $entry['block_class'] = (string)($sourceAttributes['block-class'] ?? '');
                $entry['template_path'] = (string)($sourceAttributes['template'] ?? '');
                foreach (['layout-source' => 'layout_source', 'source' => 'source'] as $attribute => $key) {
                    if (isset($sourceAttributes[$attribute])) { $entry[$key] = $sourceAttributes[$attribute]; }
                }
                if (isset($sourceAttributes['source-postion']) || isset($sourceAttributes['source-position'])) {
                    $entry['source_position'] = $sourceAttributes['source-postion'] ?? $sourceAttributes['source-position'];
                }
                if (isset($sourceAttributes['id'])) { $entry['config']['widget_id'] = $sourceAttributes['id']; }
            }
            $locales = [];
            foreach ($localeOverrides as $locale => $configs) {
                if (isset($configs[$uid]) && is_array($configs[$uid])) { $locales[$locale] = array_replace($sourceParams, $configs[$uid]); }
            }
            $identityPhp = $identity === null ? 'null' : '\\' . ThemeVersionIdentity::class . '::fromArray(' . var_export($identity->toArray(), true) . ')';
            $entryPhp = var_export($entry, true);
            $localesPhp = var_export($locales, true);
            if ($dynamicParams !== '') {
                $entryPhp = 'array_replace(' . $entryPhp . ', [\'config\' => array_replace(' . var_export($entry['config'] ?? [], true) . ', ' . $dynamicParams . ')])';
                $localeEntries = [];
                foreach ($locales as $locale => $config) {
                    $values = [];
                    foreach ($config as $key => $value) {
                        $php = var_export($value, true);
                        if (array_key_exists($key, $sourceParams) && $sourceParams[$key] === $value) {
                            $php = '(array_key_exists(' . var_export($key, true) . ', ' . $dynamicParams . ') ? ' . $dynamicParams . '[' . var_export($key, true) . '] : ' . $php . ')';
                        }
                        $values[] = var_export($key, true) . ' => ' . $php;
                    }
                    $localeEntries[] = var_export($locale, true) . ' => array_replace(' . $dynamicParams . ', [' . implode(', ', $values) . '])';
                }
                $localesPhp = '[' . implode(', ', $localeEntries) . ']';
            }
            return '\\' . ObjectManager::class . '::getInstance(\\' . ThemeLayoutEntityWidgetRenderer::class . '::class)->renderResolved('
                . $entryPhp . ', ' . $localesPhp . ', ' . $renderSlots($uid, $ancestors) . ', ' . $identityPhp . ')';
        };
        return self::transform($source, static function (array $element, string $inner) use ($groups, $indexed, $renderNode, $renderSlots, $clearAll, $localeOverrides): string {
            $slot = self::slotId($element);
            if ($slot === null || (!array_key_exists($slot, $groups) && !$clearAll)) { return $inner; }
            $uids = $groups[$slot] ?? [];
            $bindings = [];
            $fullSlot = $clearAll;
            foreach ($uids as $uid) {
                $node = $indexed[$uid];
                $fullSlot = $fullSlot || !empty($node['config']['cow_full_slot']);
                $hasLocale = false;
                foreach ($localeOverrides as $configs) { $hasLocale = $hasLocale || isset($configs[$uid]); }
                $bindings[] = ['node' => $node, 'php' => '<?= ' . $renderNode($uid) . ' ?>', 'slots' => $renderSlots($uid, [$uid => true]),
                    'has_locale' => $hasLocale, 'render' => static function (array $attrs) use ($uid, $node, $renderNode): string {
                        if (($node['source'] ?? '') !== 'template_inline_dynamic') { return '<?= ' . $renderNode($uid, [], $attrs) . ' ?>'; }
                        $variable = '$__welineNativeParams_' . substr(hash('sha256', $uid), 0, 12);
                        return '<?php ob_start(); try { ?>' . (string)($attrs['params'] ?? '{}')
                            . '<?php ' . $variable . ' = ob_get_clean(); } catch (\\Throwable $__welineNativeParameterError) { ob_end_clean(); throw $__welineNativeParameterError; } '
                            . $variable . ' = json_decode(' . $variable . ', true); '
                            . $variable . ' = is_array(' . $variable . ') ? ' . $variable . ' : []; ?>'
                            . '<?= ' . $renderNode($uid, [], $attrs, $variable) . ' ?>';
                    }];
            }
            return self::mergeInner($inner, $bindings, $fullSlot, $element['attrs']);
        });
    }

    /** Compile static native HTML slots once; the callback remains runtime-local, never a cache input. */
    public static function compileRuntimeSlots(string $source): string
    {
        if (!str_contains($source, 'data-wslot')) { return $source; }
        return self::transform($source, static function (array $element, string $inner): string {
            if ($element['tag'] === 'w:slot' || !isset($element['attrs']['data-wslot'])) { return $inner; }
            $slot = self::slotId($element);
            if ($slot === null || str_starts_with($inner, '<?php /* resolved-layout-slot */')) { return $inner; }
            $id = var_export($slot, true);
            $has = '\\' . ResolvedLayoutSlots::class . '::has(' . $id . ')';
            $render = '<?= \\' . ResolvedLayoutSlots::class . '::render(' . $id . ') ?>';
            $attrs = $element['attrs'];
            if (self::flag($attrs, 'data-wslot-append') || (!self::flag($attrs, 'data-wslot-exclusive') && !self::flag($attrs, 'data-wslot-prepend'))) { return $inner . '<?php /* resolved-layout-slot */ if (' . $has . '): ?>' . $render . '<?php endif; ?>'; }
            if (self::flag($attrs, 'data-wslot-prepend')) { return '<?php /* resolved-layout-slot */ if (' . $has . '): ?>' . $render . '<?php endif; ?>' . $inner; }
            return '<?php /* resolved-layout-slot */ if (' . $has . '): ?>' . $render . '<?php else: ?>' . $inner . '<?php endif; ?>';
        });
    }

    /** Source slots are discovered lexically; PHP strings, comments and script/style text are opaque. */
    public static function slotIds(string $source): array
    {
        $ids = [];
        self::transform($source, static function (array $element, string $inner) use (&$ids): string {
            $id = self::slotId($element);
            if ($id !== null) { $ids[$id] = true; }
            return $inner;
        });
        return $ids;
    }

    private function componentSlots(array $node): array
    {
        $registry = $this->registry ?? ObjectManager::getInstance(ThemePlaceableRegistry::class);
        $definition = $registry->find((string)($node['widget_module'] ?? ''), (string)($node['widget_type'] ?? ''), (string)($node['widget_code'] ?? ''), $this->theme);
        if ($definition === null) { return []; }
        $ids = [];
        foreach ($definition->slots as $key => $slot) {
            $id = is_array($slot) ? (string)($slot['id'] ?? $slot['slot_id'] ?? (is_string($key) ? $key : '')) : (string)$slot;
            if ($id !== '') { $ids[$id] = true; }
        }
        $source = $definition->templateContent;
        $path = null;
        if ($source === null && $definition->templatePath !== null) {
            $path = $definition->templatePath;
            if (!is_file($path)) {
                try { $path = (string)(ObjectManager::getInstance(\Weline\Framework\View\Template::class)->convertFetchFileName($path)[1] ?? ''); }
                catch (\Throwable) { $path = ''; }
            }
            $source = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        }
        $dependencies = new ThemeLayoutTemplateDependencies();
        $pending = [['source' => $source ?? '', 'origin' => $path]];
        $visited = [];
        if ($path !== null && is_file($path)) { $visited[realpath($path) ?: $path] = true; }
        while ($pending !== []) {
            $current = array_pop($pending);
            $ids += self::slotIds($current['source']);
            foreach ($dependencies->moduleSources($current['source'], $this->theme, $definition->area, $current['origin']) as $dependency) {
                $origin = realpath($dependency['origin']) ?: $dependency['origin'];
                if (isset($visited[$origin])) { continue; }
                $visited[$origin] = true;
                $bytes = file_get_contents($origin);
                if (is_string($bytes)) { $pending[] = ['source' => $bytes, 'origin' => $origin]; }
            }
        }
        return array_keys($ids);
    }

    /** Native containers are source placements too, even when no editor intent was saved. */
    public function discoverNativeOwners(string $source, array $indexed, bool $freezeDefaults = false): array
    {
        $normalized = [];
        foreach ($indexed as $key => $node) {
            if (!is_array($node)) { continue; }
            if (($node['widget_code'] ?? '') === '__no_widget_placements__') { return $indexed; }
            $uid = (string)($node['node_uid'] ?? $key);
            $node['node_uid'] = $uid;
            $normalized[$uid] = $node;
        }
        $indexed = $normalized;
        $registry = $this->registry ?? ObjectManager::getInstance(ThemePlaceableRegistry::class);
        $saved = $indexed;
        $savedOwners = [];
        foreach ($saved as $uid => $node) {
            foreach ($this->componentSlots($node) as $slot) { $savedOwners[$slot][] = $uid; }
        }
        $assignedNativeChildren = [];
        $matchedSaved = [];
        self::transform($source, static fn(array $element, string $inner): string => $inner,
            function (array $element, string $whole) use (&$indexed, &$assignedNativeChildren, &$matchedSaved, $saved, $savedOwners, $registry, $freezeDefaults): ?string {
                if ($element['tag'] !== 'w:widget' || $element['slotAncestors'] === []) { return null; }
                $attrs = $element['attrs'];
                $type = (string)($attrs['type'] ?? '');
                $code = (string)($attrs['code'] ?? $attrs['name'] ?? '');
                if ($type === '' || $code === '' || str_contains($type . $code, '<?') || str_contains($type . $code, '{{')) { return null; }
                $slot = (string)end($element['slotAncestors']);
                $module = (string)($attrs['module'] ?? '');
                if ($module === '') {
                    $widget = ObjectManager::getInstance(\Weline\Widget\Service\WidgetData::class)->getWidget($type, $code);
                    $module = (string)($widget['module'] ?? '');
                }
                if ($module === '') { return null; }
                $ref = self::templateRef($attrs, $module);
                foreach ($saved as $savedUid => $node) {
                    if (isset($matchedSaved[$savedUid])) { continue; }
                    if (($node['slot_id'] ?? $node['area'] ?? '') === $slot && ($node['widget_code'] ?? '') === $code
                        && ($node['widget_type'] ?? '') === $type && ($node['widget_module'] ?? '') === $module
                        && (($node['config']['template_ref'] ?? '') === '' || $node['config']['template_ref'] === $ref)) {
                        $matchedSaved[$savedUid] = true;
                        return null;
                    }
                }
                $definition = $registry->find($module, $type, $code, $this->theme);
                if ($definition === null) { return null; }
                $owner = ['widget_module' => $module, 'widget_type' => $type, 'widget_code' => $code];
                $children = [];
                foreach ($this->componentSlots($owner) as $childSlot) {
                    foreach ($saved as $uid => $node) {
                        if (($node['slot_id'] ?? $node['area'] ?? '') === $childSlot
                            && empty($node['parent_uid']) && ($node['source'] ?? '') === 'default_injection') {
                            $children[$uid] = $node;
                        }
                    }
                }
                if ($children === []) { return null; }
                $uid = hash('md5', $slot . '|' . $ref . '|' . $element['start']);
                $defaults = [];
                foreach ($definition->params as $key => $param) {
                    $name = is_string($key) ? $key : (string)($param['param_name'] ?? $param['key'] ?? $param['name'] ?? '');
                    if ($name !== '' && is_array($param) && array_key_exists('default', $param)) { $defaults[$name] = $param['default']; }
                }
                $rawParams = (string)($attrs['params'] ?? '{}');
                $params = json_decode($rawParams, true);
                $dynamic = str_contains($rawParams, '<?') || str_contains($rawParams, '{{');
                $params = is_array($params) ? $params : [];
                $indexed[$uid] = $owner + ['node_uid' => $uid, 'slot_id' => $slot, 'source' => $dynamic ? 'template_inline_dynamic' : 'template_inline',
                    'config' => array_replace($freezeDefaults ? array_replace($defaults, $definition->defaultConfig) : [], $params, ['template_ref' => $ref]),
                    '_explicit_config' => $params];
                foreach ($children as $childUid => $node) {
                    $childSlot = (string)($node['slot_id'] ?? $node['area'] ?? '');
                    $cloneUid = !isset($savedOwners[$childSlot]) && !isset($assignedNativeChildren[$childUid])
                        ? $childUid : hash('md5', $uid . '|' . $childUid);
                    $node['node_uid'] = $cloneUid;
                    $node['parent_uid'] = $uid;
                    $indexed[$cloneUid] = $node;
                    if (count($savedOwners[$childSlot] ?? []) === 1) {
                        $indexed[$childUid]['parent_uid'] = $savedOwners[$childSlot][0];
                    }
                    $assignedNativeChildren[$childUid] = true;
                }
                return null;
            });
        return $indexed;
    }

    private static function mergeInner(string $inner, array $bindings, bool $clear, array $attrs): string
    {
        $used = [];
        $insertedFullSlot = false;
        $fullSlotVariable = '$__welineFullSlot_' . substr(sha1($inner . implode('', array_column($bindings, 'php'))), 0, 12);
        // Replacements stay at their original call sites, so PHP/Hook branches still decide whether they run.
        $out = self::transform($inner, static fn(array $el, string $content): string => $content,
            static function (array $el, string $whole) use (&$used, &$insertedFullSlot, $bindings, $clear, $fullSlotVariable): ?string {
                if (self::slotId($el) !== null) { return $whole; }
                if ($el['tag'] !== 'w:widget') { return null; }
                if ($clear) {
                    $insertedFullSlot = true;
                    foreach ($bindings as $i => $_) { $used[$i] = true; }
                    $calls = implode('', array_column($bindings, 'php'));
                    return $calls === '' ? '' : '<?php if (!' . $fullSlotVariable . '): ' . $fullSlotVariable . ' = true; ?>' . $calls . '<?php endif; ?>';
                }
                $attrs = $el['attrs'];
                $code = (string)($attrs['code'] ?? $attrs['name'] ?? '');
                foreach ($bindings as $i => $binding) {
                    if (isset($used[$i])) { continue; }
                    $node = $binding['node'];
                    if ((string)($node['widget_code'] ?? '') !== $code) { continue; }
                    $ref = (string)($node['config']['template_ref'] ?? '');
                    if ($ref !== '' && $ref !== self::templateRef($attrs, (string)($node['widget_module'] ?? ''))) { continue; }
                    $used[$i] = true;
                    if (($node['source'] ?? '') === 'default_injection' && ($node['is_active'] ?? true) && empty($node['config']['template_deleted'])
                        && !$binding['has_locale'] && !self::hasExplicitConfig($node)) {
                        if ($binding['slots'] === '[]') { return $whole; }
                        $saved = '$__welineSlots_' . substr(sha1((string)$node['node_uid']), 0, 12);
                        $context = '\\' . ResolvedLayoutSlots::class;
                        return '<?php ' . $saved . ' = ' . $context . '::enter(' . $binding['slots'] . '); try { ?>' . $whole
                            . '<?php } finally { ' . $context . '::restore(' . $saved . '); } ?>';
                    }
                    return ($binding['render'])($attrs);
                }
                return $whole;
            });
        if ($insertedFullSlot && $bindings !== []) { $out = '<?php ' . $fullSlotVariable . ' = false; ?>' . $out; }
        $calls = '';
        foreach ($bindings as $i => $binding) {
            if (!isset($used[$i])) { $calls .= $binding['php']; }
        }
        if ($calls === '') { return $out; }
        // Editor preview placeholders stay in layout source; once real placements
        // compile into the derived PHTML they must not remain visible on storefront.
        $out = self::stripSlotEditorPlaceholders($out, $attrs);
        if (self::flag($attrs, 'prepend') || self::flag($attrs, 'data-wslot-prepend')) { return $calls . $out; }
        if (self::flag($attrs, 'append') || self::flag($attrs, 'data-wslot-append')) { return $out . $calls; }
        $exclusive = self::flag($attrs, 'exclusive') || self::flag($attrs, 'data-wslot-exclusive');
        // An owned Hook keeps deciding between its implementation and its fallback. Nested slots own their own Hooks.
        $hookInserted = false;
        $withHook = self::transform($out, static function (array $element, string $content) use (&$hookInserted, $calls, $exclusive): string {
            if ($hookInserted || $element['tag'] !== 'w:hook' || preg_match('/<else\s*\/\s*>/i', $content, $match, PREG_OFFSET_CAPTURE) !== 1) { return $content; }
            $offset = $match[0][1] + strlen($match[0][0]);
            $hookInserted = true;
            $fallback = substr($content, $offset);
            return substr($content, 0, $offset) . ($exclusive ? self::exclusiveFallback($fallback, $calls) : $fallback . $calls);
        }, static fn(array $element, string $whole): ?string => self::slotId($element) !== null ? $whole : null);
        if ($hookInserted) { return $withHook; }
        return $exclusive ? self::exclusiveFallback($out, $calls) : $out . $calls;
    }

    /** Drop design-time slot placeholders once compiled placements own the slot. */
    private static function stripSlotEditorPlaceholders(string $html, array $attrs): string
    {
        $slotId = (string)($attrs['id'] ?? $attrs['data-wslot'] ?? '');
        if ($html === '' || $slotId === '' || !str_contains($html, 'data-placeholder')) {
            return $html;
        }
        $quoted = preg_quote($slotId, '/');
        $stripped = preg_replace(
            '/<(div|span|aside|section)\b[^>]*\bdata-placeholder\s*=\s*(["\'])' . $quoted . '\2[^>]*>.*?<\/\1\s*>/is',
            '',
            $html
        );
        return is_string($stripped) ? $stripped : $html;
    }

    /** The source fallback remains intact, while this derived file explicitly selects the saved placement. */
    private static function exclusiveFallback(string $fallback, string $calls): string
    {
        return '<?php if (true): ?>' . $calls . '<?php else: ?>' . $fallback . '<?php endif; ?>';
    }

    private static function templateRef(array $attrs, string $module): string
    {
        if (!empty($attrs['ref'])) { return (string)$attrs['ref']; }
        $type = (string)($attrs['type'] ?? '');
        $code = (string)($attrs['code'] ?? $attrs['name'] ?? '');
        $params = json_decode((string)($attrs['params'] ?? '{}'), true);
        $params = is_array($params) ? $params : [];
        foreach (['layout-source' => '_layout_source', 'source' => '_source'] as $key => $configKey) {
            if (isset($attrs[$key])) { $params[$configKey] = $attrs[$key]; }
        }
        if (isset($attrs['source-postion']) || isset($attrs['source-position'])) { $params['_source_position'] = $attrs['source-postion'] ?? $attrs['source-position']; }
        $fingerprint = sha1(json_encode(['module' => $attrs['module'] ?? $module, 'type' => $type, 'code' => $code, 'params' => $params], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $type . '-' . $code) ?: 'widget';
        return 'tpl:' . strtolower($slug) . ':' . substr($fingerprint, 0, 12);
    }

    private static function flag(array $attrs, string $key): bool
    {
        return in_array(strtolower((string)($attrs[$key] ?? '')), ['true', '1'], true);
    }

    private static function hasExplicitConfig(array $node): bool
    {
        foreach ($node['_explicit_config'] ?? $node['config'] ?? [] as $key => $_) {
            if (!str_starts_with((string)$key, '_') && !in_array($key, ['template_ref', 'template_deleted', 'cow_full_slot'], true)) { return true; }
        }
        return false;
    }

    private static function slotId(array $element): ?string
    {
        $id = $element['tag'] === 'w:slot' ? ($element['attrs']['id'] ?? null) : ($element['attrs']['data-wslot'] ?? null);
        return is_string($id) && $id !== '' && !str_contains($id, '<?') && !str_contains($id, '{{') ? $id : null;
    }

    private static function attributes(string $opening): array
    {
        preg_match_all('/([\w:.-]+)\s*=\s*(["\'])(.*?)\2/s', $opening, $matches, PREG_SET_ORDER);
        $attrs = [];
        foreach ($matches as $m) { $attrs[strtolower($m[1])] = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
        return $attrs;
    }

    /** Rewrite only inside balanced element boundaries, retaining the original opening/closing bytes. */
    private static function transform(string $source, callable $innerTransform, ?callable $wholeTransform = null): string
    {
        $mask = '';
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $mask .= is_array($token) && $token[0] === T_INLINE_HTML ? $text : str_repeat(' ', strlen($text));
        }
        $mask = preg_replace_callback('/<!--.*?-->|<(script|style)\b[^>]*>.*?<\/\1\s*>/is', static fn(array $m): string => str_repeat(' ', strlen($m[0])), $mask) ?? $mask;
        preg_match_all('~<(/?)([a-zA-Z][\w:.-]*)(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~s', $mask, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $elements = [];
        $stack = [];
        foreach ($matches as $match) {
            $tag = strtolower($match[2][0]);
            $start = $match[0][1];
            $length = strlen($match[0][0]);
            if ($match[1][0] === '/') {
                for ($i = count($stack) - 1; $i >= 0; --$i) {
                    $index = $stack[$i];
                    if ($elements[$index]['tag'] !== $tag) { continue; }
                    $elements[$index]['close'] = $start;
                    $elements[$index]['end'] = $start + $length;
                    $stack = array_slice($stack, 0, $i);
                    break;
                }
                continue;
            }
            $self = str_ends_with(rtrim($match[0][0]), '/>') || in_array($tag, ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr','else'], true);
            $index = count($elements);
            $slotAncestors = [];
            foreach ($stack as $ancestor) {
                $id = self::slotId($elements[$ancestor]);
                if ($id !== null) { $slotAncestors[] = $id; }
            }
            $elements[] = ['tag' => $tag, 'start' => $start, 'openEnd' => $start + $length, 'close' => $self ? $start + $length : null, 'end' => $self ? $start + $length : null, 'self' => $self, 'attrs' => self::attributes(substr($source, $start, $length)), 'parent' => $stack === [] ? null : $stack[count($stack) - 1], 'slotAncestors' => $slotAncestors];
            if (!$self) { $stack[] = $index; }
        }
        $children = [];
        foreach ($elements as $i => $element) {
            if ($element['end'] !== null) { $children[$element['parent'] ?? -1][] = $i; }
        }
        $walk = function (int $start, int $end, int $parent) use (&$walk, $source, $elements, $children, $innerTransform, $wholeTransform): string {
            $out = '';
            foreach ($children[$parent] ?? [] as $index) {
                $el = $elements[$index];
                if ($el['start'] < $start || $el['end'] > $end) { continue; }
                $out .= substr($source, $start, $el['start'] - $start);
                $whole = substr($source, $el['start'], $el['end'] - $el['start']);
                $replacement = $wholeTransform !== null ? $wholeTransform($el, $whole) : null;
                if ($replacement !== null) { $out .= $replacement; }
                else {
                    $inner = $walk($el['openEnd'], $el['close'], $index);
                    $inner = (string)$innerTransform($el, $inner);
                    $opening = substr($source, $el['start'], $el['openEnd'] - $el['start']);
                    if ($el['self'] && $inner !== '' && $el['tag'] === 'w:slot') {
                        $out .= preg_replace('~/\s*>$~', '>', $opening) . $inner . '</w:slot>';
                    } else { $out .= $opening . $inner . substr($source, $el['close'], $el['end'] - $el['close']); }
                }
                $start = $el['end'];
            }
            return $out . substr($source, $start, $end - $start);
        };
        return $walk(0, strlen($source), -1);
    }
}
