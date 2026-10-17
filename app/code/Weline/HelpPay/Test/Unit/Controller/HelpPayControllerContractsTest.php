<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\HelpPay\Controller\Router;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllHelpPayControllerContractsPass().
 */
final class HelpPayControllerContractsTest extends TestCase
{
    public function testAllHelpPayControllerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'HelpPayFrontendLayout::testShortLinkControllersSetDefaultLayoutType' => function () {
            $this->assert_HelpPayFrontendLayout_testShortLinkControllersSetDefaultLayoutType();
        },
            'HelpPayRouter::testRoutesHAndSAndQ' => function () {
            $this->assert_HelpPayRouter_testRoutesHAndSAndQ();
        },
            'HelpPayRouter::testIgnoresWhenModuleAlreadySet' => function () {
            $this->assert_HelpPayRouter_testIgnoresWhenModuleAlreadySet();
        },
        ];
    }

/** @return list<string> */
private function controllers_HelpPayFrontendLayout(): array
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/HelpPay' . '/Controller/Frontend';
    return [$base . '/QuickPay.php', $base . '/Payer.php', $base . '/SelectionShare.php'];
}

private function assert_HelpPayFrontendLayout_testShortLinkControllersSetDefaultLayoutType(): void
{
    foreach ($this->controllers_HelpPayFrontendLayout() as $file) {
        self::assertFileExists($file);
        $src = (string) file_get_contents($file);
        self::assertStringContainsString('ThemeLayout::PAGE_TYPE_DEFAULT', $src, $file);
        self::assertStringContainsString('$this->layoutType = ThemeLayout::PAGE_TYPE_DEFAULT', $src, $file);
    }
}

private function assert_HelpPayRouter_testRoutesHAndSAndQ(): void
{
    $token = str_repeat('a', 24);
    $path = 'h/' . $token;
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('Weline_HelpPay', $rule['module'] ?? null);
    self::assertSame('weline_helppay/frontend/payer', $path);
    $path = 's/' . $token;
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('weline_helppay/frontend/selectionShare', $path);
    $path = 'q/' . $token;
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('weline_helppay/frontend/quickPay', $path);
}

private function assert_HelpPayRouter_testIgnoresWhenModuleAlreadySet(): void
{
    $path = 'h/' . str_repeat('b', 24);
    $rule = ['module' => 'Other'];
    Router::process($path, $rule);
    self::assertSame('Other', $rule['module']);
    self::assertStringStartsWith('h/', $path);
}
}
