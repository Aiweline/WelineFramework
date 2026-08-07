<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;

final class SecurityRulesReceiptWiringTest extends TestCase
{
    public function testServerMonitorEndpointRoundTripsRulesReceipt(): void
    {
        $controller = $this->source('Controller/Backend/ServerMonitor.php');
        $template = $this->source('view/templates/Backend/ServerMonitor/security-rules.phtml');

        self::assertStringContainsString('getRulesState()', $controller);
        self::assertStringContainsString("'rulesGeneration'", $controller);
        self::assertStringContainsString("'rulesDigest'", $controller);
        self::assertStringContainsString("['expected_generation']", $controller);
        self::assertStringContainsString("['expected_digest']", $controller);
        self::assertStringContainsString('$expectedGeneration,', $controller);
        self::assertStringContainsString('$expectedDigest,', $controller);
        self::assertStringContainsString('expected_generation: rulesGeneration', $template);
        self::assertStringContainsString('expected_digest: rulesDigest', $template);
    }

    public function testWlsPanelFormsRoundTripRulesReceipt(): void
    {
        $controller = $this->source('Controller/Backend/WlsPanel.php');
        $template = $this->source('view/templates/Backend/WlsPanel/index.phtml');

        self::assertStringContainsString("'rules_generation'", $controller);
        self::assertStringContainsString("'rules_digest'", $controller);
        self::assertStringContainsString('$expectedReceipt', $controller);
        self::assertStringContainsString('name="rules_generation"', $template);
        self::assertStringContainsString('name="rules_digest"', $template);
        self::assertGreaterThanOrEqual(2, \substr_count($template, 'name="rules_generation"'));
        self::assertGreaterThanOrEqual(2, \substr_count($template, 'name="rules_digest"'));
    }

    private function source(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR
            . \str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $source = \file_get_contents($path);
        self::assertIsString($source, $path);

        return $source;
    }
}
