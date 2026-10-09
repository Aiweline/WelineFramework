<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;

/**
 * WLS listen-port strip must not use `#…#` delimiters when lookahead includes `#` (fragment).
 */
final class UrlWorkerListenPortStripContractTest extends TestCase
{
    public function testStripWorkerListenPortKeepsPathQueryAndFragmentWithoutPregWarning(): void
    {
        $method = new \ReflectionMethod(Url::class, 'stripWorkerListenPortFromUrl');
        $method->setAccessible(true);

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $severity . ':' . $message;

            return true;
        });

        try {
            self::assertSame(
                'https://p05113ef3.test.weline.com/product/x',
                $method->invoke(null, 'https://p05113ef3.test.weline.com:9555/product/x', '9555')
            );
            self::assertSame(
                'https://host.example/path?q=1',
                $method->invoke(null, 'https://host.example:9510/path?q=1', '9510')
            );
            self::assertSame(
                'https://host.example/path#section',
                $method->invoke(null, 'https://host.example:9510/path#section', '9510')
            );
            self::assertSame(
                'https://host.example:443/ok',
                $method->invoke(null, 'https://host.example:443/ok', '9510')
            );
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'preg_replace must not emit Unknown modifier warnings');
    }
}
