<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedText;

final class GatewayBoundedTextTest extends TestCase
{
    public function testTruncationNeverPublishesBrokenUtf8(): void
    {
        $value = "prefix-你好-\xF0\x28\x8C\x28-suffix";
        $prefix = GatewayBoundedText::singleLine($value, 12, 'fallback');
        $tail = GatewayBoundedText::tail($value, 12);

        self::assertLessThanOrEqual(12, \strlen($prefix));
        self::assertLessThanOrEqual(12, \strlen($tail));
        self::assertNotFalse(\json_encode($prefix));
        self::assertNotFalse(\json_encode($tail));
    }
}
