<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * Publish/editor store for the theme relationship tree of one theme version.
 *
 * The tree answers "for this website + scope + area + theme version, which
 * artifacts exist, and which widgets sit in which slot" — the question request
 * time used to answer by re-walking the theme inheritance chain and the slot graph
 * on every render.
 *
 * Two writers, deliberately different:
 *
 *   - PUBLISH (``theme:update`` promote) replaces the whole artifact map via
 *     {@see self::putArtifacts()} — that is the "solidify + compile" moment.
 *   - EDITOR (visual theme editing) patches a single slot or a single widget so the
 *     change is visible immediately without re-baking the version. Data still lives
 *     in the database; the tree only mirrors it for fast reads.
 *
 * Every mutation is a locked read-modify-write with an atomic rename, so a
 * concurrent editor save and a publish cannot interleave into a corrupt tree.
 */
final class ThemeTreeStore
{
    public const SCHEMA = 'theme-tree/1';

    public const FILE_NAME = 'theme-tree.json';

    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
    ) {
    }

    /** Absolute path of the tree file for a version. */
    public function path(ThemeVersionIdentity $identity): string
    {
        return \rtrim($this->treeDir($identity), '/\\') . '/' . self::FILE_NAME;
    }

    /**
     * Tree directory — same flat, theme-scoped leaf the artifacts use.
     *
     *     generated/theme/Weline/hanfu/default.default.default/formal/theme-tree.json
     *     generated/theme/Weline/hanfu/default.default.default/draft/theme-tree.json
     */
    public function treeDir(ThemeVersionIdentity $identity): string
    {
        return $this->paths->versionModeDir($identity);
    }

    /**
     * @return array{schema:string,generated_at:string,identity:array,artifacts:array,slots:array,slot_counts:array}
     */
    public function load(ThemeVersionIdentity $identity): array
    {
        $empty = $this->emptyTree($identity);
        $file = $this->path($identity);
        if (!\is_file($file)) {
            return $empty;
        }
        $raw = @\file_get_contents($file);
        if (!\is_string($raw) || $raw === '') {
            return $empty;
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded) || ($decoded['schema'] ?? '') !== self::SCHEMA) {
            return $empty;
        }
        $decoded['artifacts'] = \is_array($decoded['artifacts'] ?? null) ? $decoded['artifacts'] : [];
        $decoded['slots'] = \is_array($decoded['slots'] ?? null) ? $decoded['slots'] : [];
        $decoded['slot_counts'] = \is_array($decoded['slot_counts'] ?? null)
            ? $decoded['slot_counts']
            : \array_map('count', $decoded['slots']);

        return $decoded;
    }

    /**
     * Publish path: replace the whole artifact map (solidify + compile).
     *
     * @param array<string,array{kind:string,bytes:int,widgets:int}> $artifacts
     * @param array<string,list<array{module:string,code:string}>> $slots
     */
    public function putArtifacts(ThemeVersionIdentity $identity, array $artifacts, array $slots): bool
    {
        return $this->mutate($identity, static function (array $tree) use ($artifacts, $slots): array {
            $tree['artifacts'] = $artifacts;
            $tree['slots'] = $slots;
            $tree['slot_counts'] = \array_map('count', $slots);

            return $tree;
        });
    }

    /**
     * Editor path: replace the whole slot map while keeping the artifact map.
     *
     * Editor saves are human-paced, so re-deriving every slot from the draft nodes
     * once per save is cheaper to reason about than tracking which single slot a given
     * endpoint touched. Publish still uses {@see self::putArtifacts()}.
     *
     * @param array<string,list<array{module:string,code:string}>> $slots
     */
    public function putSlots(ThemeVersionIdentity $identity, array $slots): bool
    {
        $normalized = [];
        foreach ($slots as $slotId => $widgets) {
            $slotId = \trim((string)$slotId);
            if ($slotId === '' || !\is_array($widgets)) {
                continue;
            }
            $kept = [];
            foreach ($widgets as $widget) {
                $code = \trim((string)($widget['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $kept[] = ['module' => \trim((string)($widget['module'] ?? '')), 'code' => $code];
            }
            if ($kept !== []) {
                $normalized[$slotId] = $kept;
            }
        }

        return $this->mutate($identity, static function (array $tree) use ($normalized): array {
            $tree['slots'] = $normalized;
            $tree['slot_counts'] = \array_map('count', $normalized);

            return $tree;
        });
    }

    /**
     * Editor path: replace one slot's widget list (add / remove / reorder in one go).
     *
     * @param list<array{module:string,code:string}> $widgets
     */
    public function putSlot(ThemeVersionIdentity $identity, string $slotId, array $widgets): bool
    {
        $slotId = \trim($slotId);
        if ($slotId === '') {
            return false;
        }

        return $this->mutate($identity, static function (array $tree) use ($slotId, $widgets): array {
            if ($widgets === []) {
                unset($tree['slots'][$slotId], $tree['slot_counts'][$slotId]);

                return $tree;
            }
            $normalized = [];
            foreach ($widgets as $widget) {
                $module = \trim((string)($widget['module'] ?? ''));
                $code = \trim((string)($widget['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $normalized[] = ['module' => $module, 'code' => $code];
            }
            if ($normalized === []) {
                unset($tree['slots'][$slotId], $tree['slot_counts'][$slotId]);

                return $tree;
            }
            $tree['slots'][$slotId] = $normalized;
            $tree['slot_counts'][$slotId] = \count($normalized);

            return $tree;
        });
    }

    /** Editor path: append one widget to a slot if it is not already there. */
    public function addSlotWidget(ThemeVersionIdentity $identity, string $slotId, string $module, string $code): bool
    {
        $slotId = \trim($slotId);
        $code = \trim($code);
        if ($slotId === '' || $code === '') {
            return false;
        }
        $current = $this->load($identity)['slots'][$slotId] ?? [];
        foreach ($current as $widget) {
            if ((string)($widget['code'] ?? '') === $code && (string)($widget['module'] ?? '') === \trim($module)) {
                return true;
            }
        }
        $current[] = ['module' => \trim($module), 'code' => $code];

        return $this->putSlot($identity, $slotId, $current);
    }

    /** Editor path: drop one widget from a slot (no-op when absent). */
    public function removeSlotWidget(ThemeVersionIdentity $identity, string $slotId, string $module, string $code): bool
    {
        $slotId = \trim($slotId);
        $code = \trim($code);
        if ($slotId === '' || $code === '') {
            return false;
        }
        $current = $this->load($identity)['slots'][$slotId] ?? [];
        if ($current === []) {
            return false;
        }
        $kept = [];
        foreach ($current as $widget) {
            if ((string)($widget['code'] ?? '') === $code && (string)($widget['module'] ?? '') === \trim($module)) {
                continue;
            }
            $kept[] = $widget;
        }
        if (\count($kept) === \count($current)) {
            return true;
        }

        return $this->putSlot($identity, $slotId, $kept);
    }

    /** Editor path: record or refresh one artifact entry without touching the rest. */
    public function upsertArtifact(ThemeVersionIdentity $identity, string $relativePath, string $kind, int $bytes, int $widgets): bool
    {
        $relativePath = \ltrim(\str_replace('\\', '/', \trim($relativePath)), '/');
        if ($relativePath === '') {
            return false;
        }

        return $this->mutate($identity, static function (array $tree) use ($relativePath, $kind, $bytes, $widgets): array {
            $tree['artifacts'][$relativePath] = ['kind' => $kind, 'bytes' => $bytes, 'widgets' => $widgets];

            return $tree;
        });
    }

    public function removeArtifact(ThemeVersionIdentity $identity, string $relativePath): bool
    {
        $relativePath = \ltrim(\str_replace('\\', '/', \trim($relativePath)), '/');
        if ($relativePath === '') {
            return false;
        }

        return $this->mutate($identity, static function (array $tree) use ($relativePath): array {
            unset($tree['artifacts'][$relativePath]);

            return $tree;
        });
    }

    /**
     * Locked read-modify-write with atomic replace.
     *
     * @param callable(array):array $mutator
     */
    private function mutate(ThemeVersionIdentity $identity, callable $mutator): bool
    {
        $dir = $this->treeDir($identity);
        if (!\is_dir($dir) && !@\mkdir($dir, 0770, true) && !\is_dir($dir)) {
            return false;
        }
        $file = $this->path($identity);
        $lockFile = $file . '.lock';
        $handle = @\fopen($lockFile, 'c');
        if (!\is_resource($handle)) {
            return false;
        }
        try {
            if (!@\flock($handle, \LOCK_EX)) {
                return false;
            }
            $tree = $this->load($identity);
            $tree = $mutator($tree);
            $tree['schema'] = self::SCHEMA;
            $tree['generated_at'] = \date('c');
            $tree['identity'] = $identity->toArray();

            $encoded = \json_encode($tree, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
            if (!\is_string($encoded)) {
                return false;
            }
            $temporary = $file . '.tmp.' . \bin2hex(\random_bytes(4));
            if (@\file_put_contents($temporary, $encoded) === false) {
                return false;
            }
            if (!@\rename($temporary, $file)) {
                @\unlink($temporary);

                return false;
            }

            return true;
        } finally {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }
    }

    /**
     * @return array{schema:string,generated_at:string,identity:array,artifacts:array,slots:array,slot_counts:array}
     */
    private function emptyTree(ThemeVersionIdentity $identity): array
    {
        return [
            'schema' => self::SCHEMA,
            'generated_at' => '',
            'identity' => $identity->toArray(),
            'artifacts' => [],
            'slots' => [],
            'slot_counts' => [],
        ];
    }
}
