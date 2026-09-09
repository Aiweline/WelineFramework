<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Kind\DefaultCmsPageKind;
use Weline\Cms\Model\Page;
use Weline\Cms\Service\CmsPageKindRegistry;
use Weline\Framework\Manager\ObjectManager;

final class CmsPageKindRegistryTest extends TestCase
{
    public function testDefaultKindIsCmsPageLayoutOnly(): void
    {
        $kind = new DefaultCmsPageKind();
        self::assertSame('cms', $kind->getCode());
        self::assertSame('', $kind->getPathGroup());
        self::assertSame([Page::LAYOUT_TYPE], $kind->getLayoutTypes());
        self::assertSame(Page::LAYOUT_TYPE, $kind->getDefaultLayoutType());
    }

    public function testUnknownPathGroupFallsBackToDefaultKind(): void
    {
        $om = $this->getMockBuilder(ObjectManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $registry = new CmsPageKindRegistry($om, new DefaultCmsPageKind());
        $kind = $registry->resolveByPathGroup('cms-draft');
        self::assertSame('cms', $kind->getCode());
        self::assertTrue($registry->canUseLayoutTypeForPathGroup('cms-draft', Page::LAYOUT_TYPE));
        self::assertFalse($registry->canUseLayoutTypeForPathGroup('cms-draft', 'help'));
    }

    public function testExtendsDeclarationIncludesPageKind(): void
    {
        $extends = include dirname(__DIR__, 3) . '/extends.php';
        self::assertIsArray($extends['extends']['PageKind'] ?? null);
        self::assertSame(
            'Weline\Cms\Api\Kind\CmsPageKindInterface',
            $extends['extends']['PageKind']['interface']
        );
    }
}
