<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

/** All candidates are complete before promotion; ordinary failures restore old bytes. */
class ThemeLayoutEntityBatchPublisher
{
    /**
     * @param array<string,?string> $candidates Null means the derived file is no longer needed.
     * @param array{compile?:bool} $options compile=false → promote only (pipeline schedules Taglib compile).
     * @return array{completed:array<string,?string>,rollback_plan:array<string,?string>}
     */
    public function publish(ThemeVersionIdentity $identity, array $candidates, array $options = []): array
    {
        $doCompile = ($options['compile'] ?? true) !== false;
        $result = $this->promote($identity, $candidates);
        $completed = $result['completed'];
        $rollbackPlan = $result['rollback_plan'];

        // Formal publish: compile language com_* outside the owner write lock.
        // Empty promote still compiles when page candidates exist (com may be missing).
        if (
            $doCompile
            && $identity->mode === ThemeVersionIdentity::MODE_FORMAL
            && $identity->area === 'frontend'
        ) {
            try {
                ObjectManager::getInstance(ThemeLayoutEntityFormalLocaleCompileService::class)
                    ->compileAfterPromote($identity, $completed);
            } catch (\Throwable $compileError) {
                if ($rollbackPlan !== []) {
                    $this->rollbackPromote($identity, $rollbackPlan);
                }
                throw $compileError;
            }
        }

        return $result;
    }

    /**
     * Atomic entity promote only (no Taglib compile).
     *
     * @param array<string,?string> $candidates
     * @return array{completed:array<string,?string>,rollback_plan:array<string,?string>}
     */
    public function promote(ThemeVersionIdentity $identity, array $candidates): array
    {
        /** @var array<string,?string> $completed */
        $completed = [];
        /** @var array<string,?string> $rollbackPlan path => previous file bytes (null = did not exist) */
        $rollbackPlan = [];

        ThemeLayoutEntityOwnerLock::write($identity, function () use ($identity, $candidates, &$completed, &$rollbackPlan): void {
            $completed = $this->completeSourceSet($candidates, $identity->area);
            $staged = [];
            $backups = [];
            $promoted = [];
            try {
                foreach ($completed as $path => $bytes) {
                    if (!is_string($path) || !str_ends_with($path, '.phtml') || ($bytes !== null && !is_string($bytes))) {
                        throw new \InvalidArgumentException('theme_layout_candidate_must_be_phtml');
                    }
                    if (file_exists($path) && !is_file($path)) {
                        throw new \RuntimeException('theme_layout_target_not_file');
                    }
                    $old = is_file($path) ? file_get_contents($path) : null;
                    if ($old === false) { throw new \RuntimeException('theme_layout_previous_source_unreadable'); }
                    if ($old === $bytes) { continue; }
                    $rollbackPlan[$path] = $old;
                    $backups[$path] = $old === null ? null : $this->stage($path, $old);
                    $staged[$path] = $bytes === null ? null : $this->stage($path, $bytes);
                }
                foreach ($staged as $path => $temporary) {
                    try {
                        if ($temporary === null) {
                            if (is_file($path) && !@unlink($path)) { throw new \RuntimeException('theme_layout_delete_failed'); }
                        } else {
                            $this->replace($temporary, $path);
                        }
                    } catch (\Throwable $error) {
                        $before = $backups[$path] === null ? null : file_get_contents($backups[$path]);
                        $after = is_file($path) ? file_get_contents($path) : null;
                        if ($after !== $before) { $promoted[] = $path; }
                        throw $error;
                    }
                    $promoted[] = $path;
                    $this->invalidate($path);
                }
            } catch (\Throwable $error) {
                $rollbackErrors = [];
                foreach (array_reverse($promoted) as $path) {
                    try {
                        $this->restorePath($path, $rollbackPlan[$path] ?? null);
                    } catch (\Throwable $rollbackError) { $rollbackErrors[] = $rollbackError->getMessage(); }
                }
                if ($rollbackErrors !== []) {
                    throw new \RuntimeException('theme_layout_batch_restore_failed: ' . implode('; ', $rollbackErrors), 0, $error);
                }
                throw $error;
            } finally {
                foreach (array_merge(array_values($staged), array_values($backups)) as $temporary) {
                    if (is_string($temporary) && is_file($temporary)) { @unlink($temporary); }
                }
            }
        });

        return [
            'completed' => $completed,
            'rollback_plan' => $rollbackPlan,
        ];
    }

    /**
     * Restore entity bytes after a failed deferred Taglib compile.
     *
     * @param array<string,?string> $rollbackPlan
     */
    public function rollbackPromote(ThemeVersionIdentity $identity, array $rollbackPlan): void
    {
        if ($rollbackPlan === []) {
            return;
        }
        ThemeLayoutEntityOwnerLock::write($identity, function () use ($rollbackPlan): void {
            foreach (array_reverse(array_keys($rollbackPlan)) as $path) {
                $this->restorePath($path, $rollbackPlan[$path]);
            }
        });
    }

    private function restorePath(string $path, ?string $previousBytes): void
    {
        if ($previousBytes === null) {
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('theme_layout_rollback_delete_failed');
            }
            $this->invalidate($path);
            return;
        }
        $temporary = $this->stage($path, $previousBytes);
        try {
            $this->replace($temporary, $path);
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
        $this->invalidate($path);
    }

    /** Dependency removals participate in the same staging and compensation as their roots. */
    private function completeSourceSet(array $candidates, string $area): array
    {
        $partialRoots = [];
        foreach ($candidates as $path => $bytes) {
            if (!is_string($path)) { continue; }
            $normalized = str_replace('\\', '/', $path);
            if (str_contains($normalized, '/pages/') && str_contains($normalized, '/layouts/')
                && !str_contains($normalized, '/sources/') && str_ends_with($path, '.phtml')) {
                $root = substr($path, 0, -6) . DIRECTORY_SEPARATOR . 'sources';
                foreach ($this->sourceFiles($root) as $dependency) {
                    if ($bytes === null || !array_key_exists($dependency, $candidates)) { $candidates[$dependency] = null; }
                }
            }
            $position = strpos($normalized, '/theme/partials/');
            if ($position === false) { continue; }
            $partialRoots[substr($path, 0, $position) . '/theme/partials'] = true;
            $metadata = is_string($bytes) ? ThemeLayoutSourceSnapshot::metadata($bytes) : $this->fileMetadata($path);
            if (($metadata['resource_type'] ?? '') === 'partial_dependency') { continue; }
            foreach (glob(dirname($path) . '/*.phtml') ?: [] as $oldOption) {
                if (!array_key_exists($oldOption, $candidates)
                    && ($this->fileMetadata($oldOption)['resource_type'] ?? '') !== 'partial_dependency') {
                    $candidates[$oldOption] = null;
                }
            }
        }
        foreach (array_keys($partialRoots) as $root) {
            $sources = [];
            $catalog = [];
            $selected = [];
            foreach (array_unique([...$this->sourceFiles($root), ...array_keys($candidates)]) as $path) {
                if (!is_string($path) || !str_starts_with($path, $root . '/')) { continue; }
                $bytes = array_key_exists($path, $candidates) ? $candidates[$path] : file_get_contents($path);
                if (!is_string($bytes)) { continue; }
                $metadata = ThemeLayoutSourceSnapshot::metadata($bytes);
                if (!in_array($metadata['resource_type'] ?? '', ['partial', 'partial_dependency'], true)) { continue; }
                $type = (string)($metadata['partial_type'] ?? '');
                $option = (string)($metadata['partial_option'] ?? 'default');
                $sources[$path] = ['bytes' => $bytes, 'metadata' => $metadata];
                $catalog['partials/' . $type . '/' . $option] = ['file_path' => $metadata['origin'] ?? $path, 'candidate_path' => $path];
                if ($metadata['resource_type'] === 'partial') { $selected[$type][] = $path; }
            }
            $queue = [];
            foreach ($selected as $type => $paths) {
                array_push($queue, ...$paths);
                if (count($paths) === 1) {
                    $catalog['partials/' . $type . '/default'] = ['file_path' => $sources[$paths[0]]['metadata']['origin'] ?? $paths[0], 'candidate_path' => $paths[0]];
                }
            }
            $reachable = [];
            $resolver = new ThemeLayoutTemplateDependencies();
            while ($queue !== []) {
                $path = array_pop($queue);
                if (isset($reachable[$path]) || !isset($sources[$path])) { continue; }
                $reachable[$path] = true;
                $source = $sources[$path];
                foreach ($resolver->partialSources($source['bytes'], (string)($source['metadata']['origin'] ?? $path), $catalog, $area) as $dependency) {
                    $queue[] = $dependency['candidate_path'];
                }
            }
            foreach ($sources as $path => $source) {
                if ($source['metadata']['resource_type'] === 'partial_dependency' && !isset($reachable[$path])) { $candidates[$path] = null; }
            }
        }
        return $candidates;
    }

    private function sourceFiles(string $root): array
    {
        if (!is_dir($root)) { return []; }
        $paths = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'phtml') { $paths[] = $file->getPathname(); }
        }
        return $paths;
    }

    private function fileMetadata(string $path): ?array
    {
        if (!is_file($path)) { return null; }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) { throw new \RuntimeException('theme_layout_previous_source_unreadable'); }
        return ThemeLayoutSourceSnapshot::metadata($bytes);
    }

    private function stage(string $target, string $bytes): string
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('theme_layout_candidate_directory_failed');
        }
        $temporary = $directory . '/.' . basename($target, '.phtml') . '-' . bin2hex(random_bytes(12)) . '.phtml';
        $written = @file_put_contents($temporary, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            @unlink($temporary);
            throw new \RuntimeException('theme_layout_candidate_write_failed');
        }
        @chmod($temporary, is_file($target) ? ((int)fileperms($target) & 0777) : 0664);
        return $temporary;
    }

    protected function replace(string $temporary, string $target): void
    {
        if (@rename($temporary, $target)) { return; }
        if (DIRECTORY_SEPARATOR === '\\' && is_file($target) && @unlink($target) && @rename($temporary, $target)) { return; }
        throw new \RuntimeException('theme_layout_candidate_replace_failed');
    }

    private function invalidate(string $path): void
    {
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
    }
}
