<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Diagnostics;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Diagnostics\DiagnosticEndpointGate;

final class DiagnosticEndpointGateTest extends TestCase
{
    public function testDevAlwaysAllowsDiagnostics(): void
    {
        self::assertTrue(DiagnosticEndpointGate::allows(true, false));
        self::assertTrue(DiagnosticEndpointGate::allows(true, null));
    }

    public function testProductionRequiresExplicitEnable(): void
    {
        self::assertTrue(DiagnosticEndpointGate::allows(false, true));
        self::assertTrue(DiagnosticEndpointGate::allows(false, 1));
        self::assertTrue(DiagnosticEndpointGate::allows(false, '1'));
        self::assertTrue(DiagnosticEndpointGate::allows(false, 'on'));

        self::assertFalse(DiagnosticEndpointGate::allows(false, false));
        self::assertFalse(DiagnosticEndpointGate::allows(false, 0));
        self::assertFalse(DiagnosticEndpointGate::allows(false, ''));
        self::assertFalse(DiagnosticEndpointGate::allows(false, null));
    }

    public function testAssertEnabledNeverThrowsInDev(): void
    {
        DiagnosticEndpointGate::assertEnabled();
        self::addToAssertionCount(1);
    }

    public function testAllPublicDiagnosticControllersCallTheGate(): void
    {
        foreach ([
            'Controller/SseTest.php',
            'Controller/Frontend/SseTest.php',
            'Controller/Test/SseTest.php',
        ] as $relative) {
            $source = (string)\file_get_contents(
                \dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative),
            );
            self::assertStringContainsString(
                'DiagnosticEndpointGate::assertEnabled();',
                $source,
                $relative . ' must gate its diagnostic actions.',
            );
        }
    }
}
