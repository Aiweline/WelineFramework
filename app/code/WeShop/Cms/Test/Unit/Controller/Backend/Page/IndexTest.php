<?php

declare(strict_types=1);

namespace WeShop\Cms\Test\Unit\Controller\Backend\Page;

use PHPUnit\Framework\TestCase;
use WeShop\Cms\Controller\Backend\Page\Index;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testControllerHasAclAttribute(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $attributes = $reflection->getAttributes();
        $hasAcl = false;
        foreach ($attributes as $attribute) {
            if (str_contains($attribute->getName(), 'Acl')) {
                $hasAcl = true;
                break;
            }
        }
        $this->assertTrue($hasAcl, 'Index controller must have Acl attribute');
    }
}
