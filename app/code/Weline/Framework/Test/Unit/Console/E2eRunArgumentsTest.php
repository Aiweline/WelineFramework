<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Console\E2e;

if (!\function_exists(__NAMESPACE__ . '\\__')) {
    function __(string $text, array $params = []): string
    {
        foreach ($params as $index => $value) {
            $text = \str_replace('%{' . ((int)$index + 1) . '}', (string)$value, $text);
        }

        return $text;
    }
}

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Test\Console\E2e\Run;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class E2eRunArgumentsTest extends TestCase
{
    public function testWorkersOneInlineValueSurvivesBooleanCliNormalization(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'buildPlaywrightArgs');
        $arguments = $method->invoke($run, [
            'command' => 'e2e:run',
            'workers' => true,
        ], [
            'spec' => '',
            'case' => '',
            'case_id' => '',
        ]);

        self::assertContains('--workers=1', $arguments);
        self::assertNotContains('--workers', $arguments);
    }

    public function testDefaultDisplayModeIsHeadlessWithoutHeadedFlag(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveDisplayMode');
        $mode = $method->invoke($run, ['--project=chromium'], ['headless' => false]);

        self::assertTrue($mode['headless']);
        self::assertSame('1', $mode['env']['PLAYWRIGHT_HEADLESS'] ?? null);
        self::assertFalse($mode['append_headed']);
    }

    public function testExplicitHeadedDisablesDefaultHeadlessEnv(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveDisplayMode');
        $mode = $method->invoke($run, ['--headed'], ['headless' => false]);

        self::assertFalse($mode['headless']);
        self::assertArrayNotHasKey('PLAYWRIGHT_HEADLESS', $mode['env']);
        self::assertFalse($mode['append_headed']);
    }

    public function testExplicitHeadlessFlagForcesHeadlessEvenWithHeadedArg(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveDisplayMode');
        $mode = $method->invoke($run, ['--headed'], ['headless' => true]);

        self::assertTrue($mode['headless']);
        self::assertSame('1', $mode['env']['PLAYWRIGHT_HEADLESS'] ?? null);
    }

    public function testParseControlOptionsCapturesSuiteAndListSuites(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'parseControlOptions');
        $control = $method->invoke($run, [
            'suite' => 'commerce-release',
            'list-suites' => true,
        ]);

        self::assertSame('commerce-release', $control['suite']);
        self::assertTrue($control['list_suites']);
    }

    public function testResolveSuiteFilesCommerceReleaseReturnsExistingSpecs(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveSuiteFiles');
        $e2eDir = BP . 'tests' . DIRECTORY_SEPARATOR . 'e2e';
        $files = $method->invoke($run, 'commerce-release', $e2eDir);

        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertIsString($file);
            self::assertFileExists($file);
            self::assertStringEndsWith('.spec.js', $file);
        }
    }

    public function testResolveSuiteFilesUnknownSuiteFailsClosed(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveSuiteFiles');
        $e2eDir = BP . 'tests' . DIRECTORY_SEPARATOR . 'e2e';

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($run, 'not-a-real-suite', $e2eDir);
    }

    public function testResolveSuiteCommerceReleaseInjectsControllablePaymentEnv(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveSuite');
        $method->setAccessible(true);
        $e2eDir = BP . 'tests' . DIRECTORY_SEPARATOR . 'e2e';
        $resolved = $method->invoke($run, 'commerce-release', $e2eDir);

        self::assertSame('controllable', $resolved['env']['WELINE_E2E_PAYMENT_DRIVER'] ?? null);
        self::assertSame('fake_card', $resolved['env']['FORCE_PAYMENT_METHOD'] ?? null);
        self::assertNotEmpty($resolved['files']);
        self::assertArrayHasKey('REL-PAYPAL-QUICK-001', $resolved['allowed_skips'] ?? []);
        self::assertArrayHasKey('HELPPAY-CHECKOUT-ADDR-EDIT-001', $resolved['allowed_skips'] ?? []);
    }

    public function testResolveSuitePaypalSandboxInjectsWalletFailFastEnv(): void
    {
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Run::class, 'resolveSuite');
        $method->setAccessible(true);
        $e2eDir = BP . 'tests' . DIRECTORY_SEPARATOR . 'e2e';
        $resolved = $method->invoke($run, 'commerce-paypal-sandbox', $e2eDir);

        self::assertSame('paypal_sandbox', $resolved['env']['WELINE_E2E_PAYMENT_DRIVER'] ?? null);
        self::assertSame('paypal', $resolved['env']['FORCE_PAYMENT_METHOD'] ?? null);
        self::assertSame('45000', $resolved['env']['WELINE_E2E_PAYPAL_WALLET_TIMEOUT_MS'] ?? null);
        self::assertCount(1, $resolved['files']);
    }
}
