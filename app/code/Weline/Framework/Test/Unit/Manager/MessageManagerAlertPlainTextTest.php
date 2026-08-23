<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\MessageManager;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

final class MessageManagerAlertPlainTextTest extends TestCase
{
    public function testProcessMessageUsesEmptyCloseButton(): void
    {
        $html = MessageManager::process_message('Invalid login credentials!', '错误！', 'danger');
        self::assertStringContainsString('data-w-close', $html);
        self::assertMatchesRegularExpression('/data-w-close[^>]*>\s*<\/button>/', $html);
        self::assertStringNotContainsString('>×</button>', $html);
    }

    public function testHtmlToPlainTextDropsCloseGlyphResidue(): void
    {
        $html = MessageManager::process_message('Invalid login credentials!', '错误！', 'danger');
        $plain = MessageManager::htmlToPlainText($html);
        self::assertSame('错误！ Invalid login credentials!', $plain);

        $legacy = '<div class="w-alert"><div class="w-alert__content"><strong>错误！</strong>'
            . ' <span>Invalid login credentials!</span></div><button type="button">×</button></div>';
        self::assertSame('错误！ Invalid login credentials!', MessageManager::htmlToPlainText($legacy));
    }
}
