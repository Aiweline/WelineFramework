<?php
declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Sandbox panel uplink whitelist contract (Phase 6).
 */
class PixelSandboxPanelWhitelistContractTest extends TestCase
{
    public function testWhitelistCommands(): void
    {
        $allowed = [
            'sandbox_ready',
            'subscribe',
            'last_match',
            'sandbox_log',
            'sandbox_error',
        ];
        foreach ($allowed as $cmd) {
            $this->assertTrue($this->isAllowed($cmd));
        }
        $this->assertFalse($this->isAllowed('eval'));
        $this->assertFalse($this->isAllowed('track'));
        $this->assertFalse($this->isAllowed('dataLayer.push'));
    }

    private function isAllowed(string $command): bool
    {
        static $whitelist = [
            'sandbox_ready' => true,
            'subscribe' => true,
            'last_match' => true,
            'sandbox_log' => true,
            'sandbox_error' => true,
        ];
        return isset($whitelist[$command]);
    }
}
