<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService;
use Weline\Theme\Service\ThemeComponentCatalog;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;
use Weline\Theme\Service\WidgetDefaultInjectionService;

final class WidgetDefaultInjectionNestedComponentSlotTest extends TestCase
{
    public function testRequiredWidgetCanTargetNestedContainerSlot(): void
    {
        $definition = new ThemeComponentDefinition(
            module: 'Weline_Product',
            type: 'product',
            code: 'product-info',
            name: 'Product Info',
            area: PreviewContextService::AREA_FRONTEND,
            isContainer: true,
            slots: [
                'product-purchase-actions' => [
                    'name' => '购买操作',
                    'accepts' => [
                        'product',
                        'cart',
                        'checkout',
                    ],
                    'slot_type' => 'layout-product-purchase-actions',
                    'max' => 5,
                ],
            ],
        );
        $catalog = new class($definition) extends ThemeComponentCatalog {
            public function __construct(private readonly ThemeComponentDefinition $definition)
            {
            }

            public function getDefinitions(
                string $area = 'frontend',
                ?WelineTheme $theme = null,
                bool $forceReload = false,
            ): array {
                return [$this->definition];
            }
        };

        self::assertTrue(
            method_exists($catalog, 'findSlot'),
            'Component catalog must expose nested container slots to default injection.'
        );
        $slot = $catalog->findSlot('product-purchase-actions', PreviewContextService::AREA_FRONTEND);
        self::assertIsArray($slot);
        self::assertSame('product-purchase-actions', $slot['id']);
        self::assertContains('product', $slot['accept']);
        self::assertFalse($slot['exclusive']);
        self::assertTrue($slot['multiple']);
        self::assertSame(5, $slot['max']);

        $theme = $this->getMockBuilder(WelineTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'load', 'getId'])
            ->addMethods(['clearQuery'])
            ->getMock();
        $theme->method('clearData')->willReturnSelf();
        $theme->method('clearQuery')->willReturnSelf();
        $theme->method('load')->willReturnSelf();
        $theme->method('getId')->willReturn(1);

        $service = new WidgetDefaultInjectionService(
            $catalog,
            $this->withoutConstructor(\Weline\Widget\Service\DefaultInjectionPlanRepository::class),
            $this->withoutConstructor(ThemeLayoutService::class),
            $theme,
            $this->withoutConstructor(ThemeLayout::class),
            $this->withoutConstructor(ThemeLayoutVersion::class),
            $this->withoutConstructor(ThemeVirtualLayout::class),
            $this->withoutConstructor(ThemeWidgetDefaultInjection::class),
            $this->withoutConstructor(ThemeRuntimeLayoutResolver::class),
            $this->withoutConstructor(ThemeScopedLayoutWriteService::class),
        );

        $method = new ReflectionMethod($service, 'resolveInstallBlocker');
        $method->setAccessible(true);
        $blocker = $method->invoke(
            $service,
            1,
            [
                'slot_id' => 'product-purchase-actions',
                'area' => ThemeLayout::AREA_CONTENT,
                'component_area' => PreviewContextService::AREA_FRONTEND,
                'page_type' => 'product',
                'identity' => [
                    'layout_option' => 'default',
                    'scope' => 'default.__store__.__channel__',
                    'locale_code' => 'default',
                    'target_type' => 'global',
                    'target_id' => 0,
                ],
                'module' => 'Weline_Cart',
                'type' => 'product',
                'code' => 'product-add-to-cart',
                'exclusive' => false,
                'widget' => [
                    'supports' => [
                        'layout-product-purchase-actions',
                        'product-purchase-actions',
                        'product-add-to-cart',
                        'add-to-cart',
                    ],
                ],
            ],
            ThemeLayout::STATUS_DRAFT,
            PreviewContextService::AREA_FRONTEND,
        );

        self::assertNull($blocker);
    }

    public function testAcceptProtocolMatchesItemAndWidgetPlacementMetadata(): void
    {
        $service = $this->withoutConstructor(WidgetDefaultInjectionService::class);
        $method = new ReflectionMethod($service, 'slotAcceptsInjection');
        $method->setAccessible(true);

        $cases = [
            'item type' => ['product', ['type' => 'PRODUCT']],
            'item slot' => ['purchase-actions', ['slot' => 'PURCHASE-ACTIONS']],
            'item position' => ['content', ['position' => ['CONTENT']]],
            'widget type' => ['cart', ['widget' => ['type' => 'CART']]],
            'widget slot' => ['product-purchase-actions', ['widget' => ['slot' => 'PRODUCT-PURCHASE-ACTIONS']]],
            'widget position' => ['content', ['widget' => ['position' => ['CONTENT']]]],
        ];

        foreach ($cases as $label => [$accept, $item]) {
            self::assertTrue(
                $method->invoke($service, [$accept], $item),
                $label . ' must participate in the slot accept protocol.',
            );
        }
    }

    private function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
