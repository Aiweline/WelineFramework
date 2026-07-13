<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Policy;

final readonly class RuntimePolicyDescriptor
{
    public const STATE_STATELESS = 'stateless';
    public const STATE_PROCESS_LOCAL = 'process_local';
    public const STATE_SHARED_ATOMIC = 'shared_atomic';

    /**
     * @param list<string> $requiredInputs
     * @param array<string, mixed> $matcher
     * @param array<string, mixed> $action
     * @param list<string> $supportedTopologies
     * @param list<string> $capabilities
     */
    public function __construct(
        public string $id,
        public int $priority,
        public PolicyStage $stage,
        public array $requiredInputs,
        public array $matcher,
        public array $action,
        public string $state = self::STATE_STATELESS,
        public bool $critical = false,
        public array $supportedTopologies = ['direct', 'dispatcher'],
        public array $capabilities = [],
    ) {
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $this->id) !== 1) {
            throw new \InvalidArgumentException('Runtime policy id is invalid: ' . $this->id);
        }
        if ($this->priority < -1_000_000 || $this->priority > 1_000_000) {
            throw new \InvalidArgumentException("Runtime policy {$this->id} priority is outside the supported range.");
        }
        if (!\in_array($this->state, [
            self::STATE_STATELESS,
            self::STATE_PROCESS_LOCAL,
            self::STATE_SHARED_ATOMIC,
        ], true)) {
            throw new \InvalidArgumentException("Runtime policy {$this->id} has an invalid state scope.");
        }

        self::assertStringList($this->requiredInputs, "Runtime policy {$this->id} required_inputs");
        self::assertStringList($this->supportedTopologies, "Runtime policy {$this->id} supported_topologies");
        self::assertStringList($this->capabilities, "Runtime policy {$this->id} capabilities");
        foreach ($this->supportedTopologies as $topology) {
            if (!\in_array($topology, ['direct', 'dispatcher'], true)) {
                throw new \InvalidArgumentException("Runtime policy {$this->id} has unsupported topology {$topology}.");
            }
        }
        self::assertDataOnly($this->matcher, "Runtime policy {$this->id} matcher");
        self::assertDataOnly($this->action, "Runtime policy {$this->id} action");
        if (\trim((string)($this->matcher['type'] ?? '')) === '') {
            throw new \InvalidArgumentException("Runtime policy {$this->id} matcher.type is required.");
        }
        if (\trim((string)($this->action['type'] ?? '')) === '') {
            throw new \InvalidArgumentException("Runtime policy {$this->id} action.type is required.");
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $stage = $data['stage'] ?? null;
        if (!$stage instanceof PolicyStage) {
            $stage = PolicyStage::tryFrom((string)$stage);
        }
        if (!$stage instanceof PolicyStage) {
            throw new \InvalidArgumentException('Runtime policy stage is invalid.');
        }

        return new self(
            id: \trim((string)($data['id'] ?? '')),
            priority: (int)($data['priority'] ?? 0),
            stage: $stage,
            requiredInputs: self::normalizeStringList($data['required_inputs'] ?? []),
            matcher: self::normalizeMap($data['matcher'] ?? []),
            action: self::normalizeMap($data['action'] ?? []),
            state: \trim((string)($data['state'] ?? self::STATE_STATELESS)),
            critical: (bool)($data['critical'] ?? false),
            supportedTopologies: self::normalizeStringList(
                $data['supported_topologies'] ?? ['direct', 'dispatcher']
            ),
            capabilities: self::normalizeStringList($data['capabilities'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'priority' => $this->priority,
            'stage' => $this->stage->value,
            'required_inputs' => self::normalizeStringList($this->requiredInputs),
            'matcher' => self::canonicalize($this->matcher),
            'action' => self::canonicalize($this->action),
            'state' => $this->state,
            'critical' => $this->critical,
            'supported_topologies' => self::normalizeStringList($this->supportedTopologies),
            'capabilities' => self::normalizeStringList($this->capabilities),
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('Runtime policy list value must be an array.');
        }
        $items = [];
        foreach ($value as $item) {
            $item = \trim((string)$item);
            if ($item !== '') {
                $items[$item] = true;
            }
        }
        $items = \array_keys($items);
        \sort($items, \SORT_STRING);
        return $items;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function normalizeMap(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('Runtime policy map value must be an array.');
        }
        return self::canonicalize($value);
    }

    /**
     * @param list<string> $items
     */
    private static function assertStringList(array $items, string $label): void
    {
        foreach ($items as $key => $item) {
            if (!\is_int($key) || !\is_string($item) || \trim($item) === '') {
                throw new \InvalidArgumentException($label . ' must be a list of non-empty strings.');
            }
        }
    }

    private static function assertDataOnly(mixed $value, string $label): void
    {
        if ($value === null || \is_scalar($value)) {
            return;
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException($label . ' must contain data only; objects and callables are forbidden.');
        }
        foreach ($value as $key => $item) {
            if (!\is_int($key) && !\is_string($key)) {
                throw new \InvalidArgumentException($label . ' contains an invalid key.');
            }
            self::assertDataOnly($item, $label);
        }
    }

    private static function canonicalize(array $value): array
    {
        if (!\array_is_list($value)) {
            \ksort($value, \SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }
        return $value;
    }
}
