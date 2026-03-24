<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Controller\Frontend\Search\Index as FrontendIndex;
use WeShop\Search\Controller\Index;

class IndexAliasTest extends TestCase
{
    public function testAliasControllerExtendsFrontendSearchIndex(): void
    {
        $this->assertSame(FrontendIndex::class, get_parent_class(Index::class));
    }
}
