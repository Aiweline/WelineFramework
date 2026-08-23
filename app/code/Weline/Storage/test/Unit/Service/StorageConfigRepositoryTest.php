<?php

declare(strict_types=1);

namespace Weline\Storage\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Storage\Model\StorageConfig;
use Weline\Storage\Service\StorageConfigRepository;
use Weline\Storage\Service\StorageDriverProviderRegistry;

final class StorageConfigRepositoryTest extends TestCase
{
    public function testLegacyAliasIsReadOnlyAndResolvesToCanonicalThreeSegmentCode(): void
    {
        $row = $this->row(
            12,
            'oss::aliyun::media_public',
            'oss::aliyun',
            StorageConfig::STATUS_ENABLED,
            ['legacy_aliases' => ['oss_public']],
            true,
        );
        $repository = $this->repository([$row], [$row]);

        self::assertSame(
            'oss::aliyun::media_public',
            $repository->canonicalize('oss_public'),
        );
        self::assertSame(
            'oss::aliyun::media_public',
            $repository->defaultDiskCode(),
        );
    }

    public function testMissingNamedDiskFailsInsteadOfSilentlyFallingBackToLocal(): void
    {
        $repository = $this->repository([], []);

        $this->expectException(\RuntimeException::class);
        $repository->snapshot('oss::aliyun::missing');
    }

    public function testExplicitlyDisabledBuiltinLocalDiskIsNotResurrected(): void
    {
        $disabled = $this->row(
            1,
            'local::filesystem::media',
            'local::filesystem',
            StorageConfig::STATUS_DISABLED,
            [],
        );
        $repository = $this->repository([], [$disabled]);

        $this->expectException(\RuntimeException::class);
        $repository->snapshot('local');
    }

    public function testAmbiguousLegacyAliasFailsClosed(): void
    {
        $first = $this->row(
            20,
            'oss::aliyun::first',
            'oss::aliyun',
            StorageConfig::STATUS_ENABLED,
            ['legacy_aliases' => ['legacy_media']],
        );
        $second = $this->row(
            21,
            'oss::aliyun::second',
            'oss::aliyun',
            StorageConfig::STATUS_ENABLED,
            ['legacy_aliases' => ['legacy_media']],
        );
        $repository = $this->repository([$first, $second], [$first, $second]);

        $this->expectException(\RuntimeException::class);
        $repository->canonicalize('legacy_media');
    }

    /**
     * @param list<array<string,mixed>> $enabled
     * @param list<array<string,mixed>> $configured
     */
    private function repository(array $enabled, array $configured): StorageConfigRepository
    {
        $repository = new StorageConfigRepository(new StorageDriverProviderRegistry());
        $reflection = new \ReflectionClass($repository);
        foreach ([
            'requestEnabledRows' => $enabled,
            'requestConfiguredRows' => $configured,
        ] as $propertyName => $value) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue($repository, $value);
        }
        return $repository;
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function row(
        int $id,
        string $name,
        string $driver,
        int $status,
        array $config,
        bool $default = false,
    ): array {
        return [
            StorageConfig::schema_fields_CONFIG_ID => $id,
            StorageConfig::schema_fields_NAME => $name,
            StorageConfig::schema_fields_DISPLAY_NAME => $name,
            StorageConfig::schema_fields_DRIVER => $driver,
            StorageConfig::schema_fields_STATUS => $status,
            StorageConfig::schema_fields_IS_DEFAULT => $default ? 1 : 0,
            StorageConfig::schema_fields_CONFIG_REVISION => 1,
            StorageConfig::schema_fields_CONFIG => json_encode(
                $config,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        ];
    }
}
