<?php

declare(strict_types=1);

namespace Weline\Component\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class MessageFlashToastContractTest extends TestCase
{
    public function testMessageTemplateConvertsFlashToToast(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/message.phtml');
        self::assertStringContainsString('data-flash-display="toast"', $src);
        self::assertStringContainsString('Weline.UI.toast', $src);
        self::assertStringContainsString('hidden', $src);
    }
}
