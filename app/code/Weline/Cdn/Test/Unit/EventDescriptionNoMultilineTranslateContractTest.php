<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit;

use PHPUnit\Framework\TestCase;

final class EventDescriptionNoMultilineTranslateContractTest extends TestCase
{
    public function testEventDescriptionsHaveNoEmbeddedNewlinesInsideTranslateCalls(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/event.php');
        self::assertDoesNotMatchRegularExpression("/__\\([^)]*\\n/s", $src);
        self::assertStringContainsString('CDN 统一操作请求', $src);
        self::assertStringContainsString('doc', $src);
    }
}
