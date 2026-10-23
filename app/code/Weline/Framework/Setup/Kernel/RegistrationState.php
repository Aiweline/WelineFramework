<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Kernel;

final class RegistrationState
{
    public const DISCOVERED = 'discovered';
    public const PRE_REGISTERED = 'pre_registered';
    public const MODULE_REGISTERED = 'module_registered';
    public const DEPENDENCIES_READY = 'dependencies_ready';
    public const INSTALLED = 'installed';
    public const UPGRADED = 'upgraded';
    public const REGISTRY_READY = 'registry_ready';

    private string $state = self::DISCOVERED;
    private array $dirtyModules = [];
    private bool $needsFullRefresh = false;

    public static function fromChangeSet(ChangeSet $cs): self
    {
        $state = new self();
        $modules = $cs->toArray()['modules'];
        $dirty = array_values(array_unique(array_merge($modules['added'], $modules['updated'], $cs->pendingModules())));
        sort($dirty, SORT_STRING);
        $state->dirtyModules = $dirty;
        $state->needsFullRefresh = (bool)($modules['removed'] || $modules['disabled']);
        return $state;
    }

    public function advance(string $state): void
    {
        $states = [
            self::DISCOVERED,
            self::PRE_REGISTERED,
            self::MODULE_REGISTERED,
            self::DEPENDENCIES_READY,
            self::INSTALLED,
            self::UPGRADED,
            self::REGISTRY_READY,
        ];
        if (!in_array($state, $states, true)) {
            throw new \InvalidArgumentException('Invalid registration state: ' . $state);
        }
        $this->state = $state;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function dirtyModules(): array
    {
        return $this->dirtyModules;
    }

    public function needsFullRefresh(): bool
    {
        return $this->needsFullRefresh;
    }

    public function registriesToRefresh(): array
    {
        return match ($this->state) {
            self::MODULE_REGISTERED => ['extends'],
            self::REGISTRY_READY => $this->dirtyModules ? ['extends', 'plugin', 'event', 'hook', 'route', 'menu'] : [],
            self::INSTALLED, self::UPGRADED => $this->dirtyModules ? ['extends'] : [],
            default => [],
        };
    }

    public function shouldRefresh(string $registry): bool
    {
        return in_array($registry, $this->registriesToRefresh(), true);
    }
}
