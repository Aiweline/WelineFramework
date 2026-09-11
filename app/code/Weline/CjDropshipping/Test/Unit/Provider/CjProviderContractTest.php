<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;

final class CjProviderContractTest extends TestCase
{
    public function testCodeIsCj(): void
    {
        $p = new CjProvider();
        self::assertSame('cj', $p->getCode());
        self::assertArrayHasKey('catalog', $p->getCapabilities());
        self::assertSame('Weline_CjDropshipping', $p->getDisplayMetadata()['module']);
    }
}
