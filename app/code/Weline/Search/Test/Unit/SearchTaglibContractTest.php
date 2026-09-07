<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Search\Taglib\Search;

final class SearchTaglibContractTest extends TestCase
{
    public function testSearchTagImplementsCurrentSelfClosingTagContract(): void
    {
        self::assertContains(TaglibInterface::class, class_implements(Search::class));
        self::assertTrue(Search::tag_self_close());
        self::assertTrue(Search::tag_self_close_with_attrs());
        self::assertNull(Search::parent());
        self::assertNotSame('', Search::document());
    }
}
