<?php
declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Mail\Controller\Backend\Index;

final class IndexTest extends TestCase
{
    public function testDetectsFakeEngineFromDomainLookupArray(): void
    {
        $method = new ReflectionMethod(Index::class, 'isFakeDomainLookupEntry');

        self::assertTrue($method->invoke(null, ['engine' => 'fake']));
        self::assertFalse($method->invoke(null, ['engine' => 'stalwart']));
        self::assertFalse($method->invoke(null, null));
    }
}
