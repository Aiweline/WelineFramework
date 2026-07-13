<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Policy;

final readonly class RuntimePolicyBundle
{
    public const FORMAT_VERSION = 1;

    /**
     * @param list<RuntimePolicyDescriptor> $descriptors
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public int $format,
        public string $version,
        public string $digest,
        public int $generatedAt,
        public string $topology,
        public array $descriptors,
        public array $metadata,
    ) {
    }

    /**
     * @param list<RuntimePolicyDescriptor|array<string, mixed>> $descriptors
     * @param array<string, mixed> $metadata
     */
    public static function fromDescriptors(
        array $descriptors,
        string $version = '1',
        string $topology = 'both',
        array $metadata = [],
        ?int $generatedAt = null,
    ): self {
        $version = \trim($version);
        if ($version === '') {
            throw new \InvalidArgumentException('Runtime policy bundle version is required.');
        }
        self::assertTopology($topology);
        self::assertDataOnly($metadata, 'Runtime policy bundle metadata');

        $normalized = [];
        $ids = [];
        foreach ($descriptors as $descriptor) {
            if (\is_array($descriptor)) {
                $descriptor = RuntimePolicyDescriptor::fromArray($descriptor);
            }
            if (!$descriptor instanceof RuntimePolicyDescriptor) {
                throw new \InvalidArgumentException('Runtime policy bundle descriptors must be descriptor objects or arrays.');
            }
            if (isset($ids[$descriptor->id])) {
                throw new \InvalidArgumentException('Duplicate runtime policy id: ' . $descriptor->id);
            }
            $ids[$descriptor->id] = true;
            $normalized[] = $descriptor;
        }
        \usort($normalized, static function (RuntimePolicyDescriptor $left, RuntimePolicyDescriptor $right): int {
            return [$left->stage->order(), $left->priority, $left->id]
                <=> [$right->stage->order(), $right->priority, $right->id];
        });

        $metadata = self::canonicalize($metadata);
        $material = self::digestMaterial(self::FORMAT_VERSION, $version, $topology, $normalized, $metadata);
        return new self(
            format: self::FORMAT_VERSION,
            version: $version,
            digest: self::computeDigest($material),
            generatedAt: $generatedAt ?? \time(),
            topology: $topology,
            descriptors: $normalized,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $format = (int)($data['format'] ?? 0);
        if ($format !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException("Unsupported runtime policy bundle format: {$format}");
        }
        $bundle = self::fromDescriptors(
            descriptors: \is_array($data['descriptors'] ?? null) ? $data['descriptors'] : [],
            version: (string)($data['version'] ?? ''),
            topology: (string)($data['topology'] ?? ''),
            metadata: \is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            generatedAt: (int)($data['generated_at'] ?? 0) > 0 ? (int)$data['generated_at'] : null,
        );
        $declaredDigest = \strtolower(\trim((string)($data['digest'] ?? '')));
        if ($declaredDigest === '' || !\hash_equals($bundle->digest, $declaredDigest)) {
            throw new \InvalidArgumentException('Runtime policy bundle digest mismatch.');
        }
        return $bundle;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'version' => $this->version,
            'digest' => $this->digest,
            'generated_at' => $this->generatedAt,
            'topology' => $this->topology,
            'descriptors' => \array_map(
                static fn(RuntimePolicyDescriptor $descriptor): array => $descriptor->toArray(),
                $this->descriptors,
            ),
            'metadata' => $this->metadata,
        ];
    }

    public function supportsTopology(string $topology): bool
    {
        return $this->topology === 'both' || $this->topology === $topology;
    }

    /**
     * @param list<RuntimePolicyDescriptor> $descriptors
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private static function digestMaterial(
        int $format,
        string $version,
        string $topology,
        array $descriptors,
        array $metadata,
    ): array {
        return [
            'format' => $format,
            'version' => $version,
            'topology' => $topology,
            'descriptors' => \array_map(
                static fn(RuntimePolicyDescriptor $descriptor): array => $descriptor->toArray(),
                $descriptors,
            ),
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string, mixed> $material
     */
    private static function computeDigest(array $material): string
    {
        $json = \json_encode(
            self::canonicalize($material),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
        );
        return \hash('sha256', $json);
    }

    private static function assertTopology(string $topology): void
    {
        if (!\in_array($topology, ['direct', 'dispatcher', 'both'], true)) {
            throw new \InvalidArgumentException('Runtime policy bundle topology must be direct, dispatcher, or both.');
        }
    }

    private static function assertDataOnly(mixed $value, string $label): void
    {
        if ($value === null || \is_scalar($value)) {
            return;
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException($label . ' must contain data only.');
        }
        foreach ($value as $item) {
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
