<?php

declare(strict_types=1);

namespace Weline\Database\test;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Database\Service\ModuleRollbackManager;
use Weline\Framework\Architecture\Module\ModuleGraphValidator;
use Weline\Framework\Module\Manifest\ModuleManifest;

final class ModuleRollbackDependencySolverTest extends TestCase
{
    public function testRootRollbackSelectsHighestCompatibleDependentAndOrdersDependentFirst(): void
    {
        $current = [
            'Vendor_A' => $this->manifest('Vendor_A', '1.1.0'),
            'Vendor_B' => $this->manifest('Vendor_B', '2.0.0', ['Vendor_A' => '^1.1.0']),
        ];
        $candidates = [
            'Vendor_A' => [
                '1.0.0' => $this->candidate($this->manifest('Vendor_A', '1.0.0')),
            ],
            'Vendor_B' => [
                '2.0.0' => $this->candidate($current['Vendor_B']),
                '1.9.0' => $this->candidate($this->manifest('Vendor_B', '1.9.0', ['Vendor_A' => '^1.1.0'])),
                '1.5.0' => $this->candidate($this->manifest('Vendor_B', '1.5.0', ['Vendor_A' => '1.0.0'])),
                '1.4.0' => $this->candidate($this->manifest('Vendor_B', '1.4.0', ['Vendor_A' => '1.0.0'])),
            ],
        ];

        $manager = (new ReflectionClass(ModuleRollbackManager::class))->newInstanceWithoutConstructor();
        $graphProperty = (new ReflectionClass(ModuleRollbackManager::class))->getProperty('graphValidator');
        $graphProperty->setValue($manager, new ModuleGraphValidator());
        $solve = (new ReflectionClass(ModuleRollbackManager::class))->getMethod('solveHighestCompatible');

        $selection = $solve->invoke(
            $manager,
            ['Vendor_A', 'Vendor_B'],
            $candidates,
            $current,
            0,
            [],
        );

        self::assertIsArray($selection);
        self::assertSame('1.0.0', $selection['Vendor_A']['manifest']->version);
        self::assertSame('1.5.0', $selection['Vendor_B']['manifest']->version);

        $sort = (new ReflectionClass(ModuleRollbackManager::class))->getMethod('sortReverseDependencies');
        self::assertSame(
            ['Vendor_B', 'Vendor_A'],
            $sort->invoke($manager, ['Vendor_A', 'Vendor_B'], $selection),
        );
    }

    public function testRollbackIsBlockedWhenNoCompatibleDependentArtifactExists(): void
    {
        $current = [
            'Vendor_A' => $this->manifest('Vendor_A', '1.1.0'),
            'Vendor_B' => $this->manifest('Vendor_B', '2.0.0', ['Vendor_A' => '^1.1.0']),
        ];
        $candidates = [
            'Vendor_A' => [
                '1.0.0' => $this->candidate($this->manifest('Vendor_A', '1.0.0')),
            ],
            'Vendor_B' => [
                '2.0.0' => $this->candidate($current['Vendor_B']),
                '1.9.0' => $this->candidate($this->manifest('Vendor_B', '1.9.0', ['Vendor_A' => '^1.1.0'])),
            ],
        ];

        $manager = (new ReflectionClass(ModuleRollbackManager::class))->newInstanceWithoutConstructor();
        $graphProperty = (new ReflectionClass(ModuleRollbackManager::class))->getProperty('graphValidator');
        $graphProperty->setValue($manager, new ModuleGraphValidator());
        $solve = (new ReflectionClass(ModuleRollbackManager::class))->getMethod('solveHighestCompatible');

        self::assertNull($solve->invoke(
            $manager,
            ['Vendor_A', 'Vendor_B'],
            $candidates,
            $current,
            0,
            [],
        ));
    }

    /** @param array<string, string> $requires */
    private function manifest(string $name, string $version, array $requires = []): ModuleManifest
    {
        return new ModuleManifest($name, $version, $requires, [], [], '/tmp/' . $name);
    }

    /** @return array{manifest: ModuleManifest, artifact: array<string, string>} */
    private function candidate(ModuleManifest $manifest): array
    {
        return [
            'manifest' => $manifest,
            'artifact' => ['version' => $manifest->version],
        ];
    }
}
