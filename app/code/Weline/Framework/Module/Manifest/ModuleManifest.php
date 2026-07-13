<?php

declare(strict_types=1);

namespace Weline\Framework\Module\Manifest;

final readonly class ModuleManifest
{
    /**
     * @param array<string, string> $requires
     * @param array<string, string> $optional
     * @param array<string, string> $provides Contract/capability => implementation/provider
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $requires,
        public array $optional,
        public array $provides,
        public string $path,
        public bool $authoritative = true,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path, bool $authoritative = true): self
    {
        $name = trim((string)($data['name'] ?? ''));
        $version = trim((string)($data['version'] ?? ''));
        if ($name === '' || $version === '') {
            throw new \InvalidArgumentException("Module manifest at {$path} must define non-empty name and version.");
        }

        return new self(
            $name,
            $version,
            self::normalizeDependencies($data['requires'] ?? []),
            self::normalizeDependencies($data['optional'] ?? []),
            self::normalizeProvides($data['provides'] ?? []),
            $path,
            $authoritative,
        );
    }

    public function declares(string $module): bool
    {
        return isset($this->requires[$module]) || isset($this->optional[$module]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'requires' => $this->requires,
            'optional' => $this->optional,
            'provides' => $this->provides,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeDependencies(mixed $dependencies): array
    {
        if (!is_array($dependencies)) {
            throw new \InvalidArgumentException('Module requires/optional must be an array.');
        }

        $normalized = [];
        foreach ($dependencies as $key => $value) {
            if (is_int($key)) {
                $module = trim((string)$value);
                $constraint = '*';
            } else {
                $module = trim((string)$key);
                $constraint = trim((string)$value) ?: '*';
            }
            if ($module !== '') {
                $normalized[$module] = $constraint;
            }
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeProvides(mixed $provides): array
    {
        if (!is_array($provides)) {
            throw new \InvalidArgumentException('Module provides must be an array.');
        }

        $normalized = [];
        foreach ($provides as $contract => $implementation) {
            if (is_int($contract)) {
                $contract = trim((string)$implementation);
            } else {
                $contract = trim((string)$contract);
            }
            $implementation = trim((string)$implementation);
            if ($contract !== '' && $implementation !== '') {
                $normalized[$contract] = $implementation;
            }
        }
        ksort($normalized);

        return $normalized;
    }
}
