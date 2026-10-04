<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Service;
use PHPUnit\Framework\TestCase;
final class ForeignAuthPlacementContractTest extends TestCase
{
    public function testNativeAuthWidgetsDeclareOnlyForeignHostSlots(): void
    {
        $entries = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        foreach (['login', 'register', 'challenge'] as $form) {
            $matched = false;
            foreach ($entries as $entry) {
                if (!is_array($entry) || ($entry['template'] ?? '') !== 'Weline_Theme::theme/frontend/widgets/form/account-' . $form . '/default.phtml') { continue; }
                $matched = true;
                self::assertSame('layout', $entry['placement'] ?? null);
                self::assertNotEmpty($entry['default_injections']);
                foreach ($entry['default_injections'] as $injection) {
                    self::assertSame('injection', $injection['placement']);
                    self::assertSame('foreign-theme-account-' . $form, $injection['slot']);
                    self::assertTrue($injection['required']);
                }
            }
            self::assertTrue($matched);
        }
    }
}
