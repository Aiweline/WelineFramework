<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Controller\Frontend\Search\Suggest as FrontendSuggest;
use WeShop\Search\Controller\Suggest;

class SuggestAliasTest extends TestCase
{
    public function testAliasControllerExtendsFrontendSearchSuggest(): void
    {
        $this->assertSame(FrontendSuggest::class, get_parent_class(Suggest::class));
    }
}
