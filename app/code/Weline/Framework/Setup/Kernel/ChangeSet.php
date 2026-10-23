<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Kernel;

final class ChangeSet
{
    private array $previous;
    private array $current;
    private array $pendingModules;
    private array $modules = [];
    private array $reasons = [];

    public function __construct(array $previous, array $current, array $pendingModules = [])
    {
        $this->previous = $previous;
        $this->current = $current;
        $this->pendingModules = $this->normalizePending($pendingModules);
        $this->compute();
    }

    public function modules(): array
    {
        return $this->modules;
    }

    public function pendingModules(): array
    {
        return $this->pendingModules;
    }

    public function needs(): array
    {
        $modules = $this->modules;
        $changed = $modules['added'] || $modules['updated'] || $modules['removed'] || $modules['disabled'] || $this->pendingModules;
        return [
            'schema' => $changed,
            'route' => $changed,
            'menu' => $changed,
            'i18n' => (bool)($modules['added'] || $modules['updated']),
            'compile' => $changed,
        ];
    }

    public function reasons(): array
    {
        return $this->reasons;
    }

    public function isEmpty(): bool
    {
        $modules = $this->modules;
        return !$this->pendingModules
            && !$modules['added'] && !$modules['updated']
            && !$modules['removed'] && !$modules['disabled'];
    }

    public function toArray(): array
    {
        return [
            'modules' => $this->modules,
            'pending_modules' => $this->pendingModules,
            'needs' => $this->needs(),
            'reasons' => $this->reasons,
        ];
    }

    private function normalizePending(array $pendingModules): array
    {
        $pending = array_values(array_unique($pendingModules));
        $pending = array_filter($pending, static fn($module): bool => $module !== '' && $module !== null);
        sort($pending, SORT_STRING);
        return $pending;
    }

    private function normalize(array $module): array
    {
        return [
            'version' => (string)($module['version'] ?? ''),
            'status' => (bool)($module['status'] ?? true),
            'fingerprint' => (string)($module['fingerprint'] ?? ''),
        ];
    }

    private function compute(): void
    {
        $added = $updated = $removed = $disabled = $unchanged = [];
        foreach ($this->previous as $module => $data) {
            if (!array_key_exists($module, $this->current)) {
                $removed[] = $module;
                $this->reasons['module:' . $module] = 'removed';
            }
        }
        foreach ($this->current as $module => $data) {
            if (!array_key_exists($module, $this->previous)) {
                $added[] = $module;
                $this->reasons['module:' . $module] = 'added';
                continue;
            }
            $before = $this->normalize($this->previous[$module]);
            $after = $this->normalize($data);
            if ($before['status'] && !$after['status']) {
                $disabled[] = $module;
                $this->reasons['module:' . $module] = 'status_changed';
            } elseif ($before['version'] !== $after['version']) {
                $updated[] = $module;
                $this->reasons['module:' . $module] = 'version_changed';
            } elseif ($before['fingerprint'] !== $after['fingerprint']) {
                $updated[] = $module;
                $this->reasons['module:' . $module] = 'fingerprint_changed';
            } else {
                $unchanged[] = $module;
            }
        }
        sort($added, SORT_STRING);
        sort($updated, SORT_STRING);
        sort($removed, SORT_STRING);
        sort($disabled, SORT_STRING);
        sort($unchanged, SORT_STRING);
        $this->modules = [
            'added' => $added,
            'updated' => $updated,
            'removed' => $removed,
            'disabled' => $disabled,
            'unchanged' => $unchanged,
        ];
    }
}
