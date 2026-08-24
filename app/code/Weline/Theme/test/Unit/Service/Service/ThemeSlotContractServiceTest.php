<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeResourceCatalog;
use Weline\Theme\Service\ThemeSlotContractService;

class ThemeSlotContractServiceTest extends TestCase
{
    public function testCollectsMissingDefaultSlotsFromEffectiveThemeOverride(): void
    {
        $service = new ThemeSlotContractService($this->mockCatalog([
            'partials' => [
                $this->resource('partials', 'theme', 'partials/footer/default', [
                    'footer',
                    'footer-links',
                    'footer-newsletter',
                ]),
                $this->resource('partials', 'default', 'partials/footer/default', [
                    'footer',
                    'footer-links',
                    'footer-social',
                    'footer-copyright',
                ]),
            ],
        ]));

        $warnings = $service->collectMissingDefaultSlots('frontend');

        $this->assertCount(1, $warnings);
        $this->assertSame('partials/footer/default', $warnings[0]['logical_key']);
        $this->assertSame(['footer-social', 'footer-copyright'], $warnings[0]['missing_slot_ids']);
    }

    public function testAllowsExtraSlotsWhenDefaultSlotsAreKept(): void
    {
        $service = new ThemeSlotContractService($this->mockCatalog([
            'partials' => [
                $this->resource('partials', 'theme', 'partials/footer/default', [
                    'footer',
                    'footer-links',
                    'footer-social',
                    'footer-copyright',
                    'footer-newsletter',
                ]),
                $this->resource('partials', 'default', 'partials/footer/default', [
                    'footer',
                    'footer-links',
                    'footer-social',
                    'footer-copyright',
                ]),
            ],
        ]));

        $this->assertSame([], $service->collectMissingDefaultSlots('frontend'));
    }

    public function testIgnoresThemeResourcesWithoutDefaultSlotContract(): void
    {
        $service = new ThemeSlotContractService($this->mockCatalog([
            'partials' => [
                $this->resource('partials', 'theme', 'partials/custom/default', ['custom-slot']),
            ],
        ]));

        $this->assertSame([], $service->collectMissingDefaultSlots('frontend'));
    }

    private function mockCatalog(array $resourcesByType): ThemeResourceCatalog
    {
        /** @var ThemeResourceCatalog&MockObject $catalog */
        $catalog = $this->getMockBuilder(ThemeResourceCatalog::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRawResources'])
            ->getMock();

        $catalog->method('getRawResources')
            ->willReturnCallback(static fn(string $type, string $area = 'frontend', mixed $theme = null): array => $resourcesByType[$type] ?? []);

        return $catalog;
    }

    private function resource(string $type, string $layerType, string $logicalKey, array $slotIds): array
    {
        return [
            'type' => $type,
            'area' => 'frontend',
            'logical_key' => $logicalKey,
            'layer_type' => $layerType,
            'theme_id' => $layerType === 'theme' ? 11 : 0,
            'theme_name' => $layerType === 'theme' ? 'motor' : 'Weline_Theme',
            'relative_path' => str_replace('partials/', '', $logicalKey) . '.phtml',
            'file_path' => '/tmp/' . $logicalKey . '.phtml',
            'slots' => array_map(static fn(string $slotId): array => [
                'id' => $slotId,
                'name' => $slotId,
                'accept' => [],
                'exclusive' => false,
                'multiple' => true,
            ], $slotIds),
        ];
    }
}
