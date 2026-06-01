<?php

declare(strict_types=1);

namespace WeShop\Catalog\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

final class CategoryLocalDescriptionFieldsTest extends TestCase
{
    public function testLocalDescriptionFieldsExcludeConfigWithoutColumnDeclaration(): void
    {
        $local = ObjectManager::getInstance(\WeShop\Catalog\Model\Category\LocalDescription::class);

        self::assertNotContains('config', $local->getModelFields());
    }
}
