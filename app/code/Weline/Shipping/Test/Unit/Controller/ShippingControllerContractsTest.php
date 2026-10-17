<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Controller\Router;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllShippingControllerContractsPass().
 */
final class ShippingControllerContractsTest extends TestCase
{
    public function testAllShippingControllerContractsPass(): void
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
            'CarrierRedirectTerminateRethrow::testSaveDeleteToggleRethrowResponseTerminateException' => function () {
            $this->assert_CarrierRedirectTerminateRethrow_testSaveDeleteToggleRethrowResponseTerminateException();
        },
            'GuidePublicRouter::testGuideShippingAliasMapsToFrontendController' => function () {
            $this->assert_GuidePublicRouter_testGuideShippingAliasMapsToFrontendController();
        },
            'GuidePublicRouter::testGuideReturnsAliasMapsToFrontendController' => function () {
            $this->assert_GuidePublicRouter_testGuideReturnsAliasMapsToFrontendController();
        },
            'GuidePublicRouter::testIgnoresAlreadyOwnedRules' => function () {
            $this->assert_GuidePublicRouter_testIgnoresAlreadyOwnedRules();
        },
        ];
    }

private function assert_CarrierRedirectTerminateRethrow_testSaveDeleteToggleRethrowResponseTerminateException(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/Controller/Backend/Carrier.php');
    self::assertStringContainsString('use Weline\Framework\Http\ResponseTerminateException;', $src);
    self::assertGreaterThanOrEqual(3, substr_count($src, 'catch (ResponseTerminateException $terminate)'));
    self::assertStringContainsString('throw $terminate;', $src);
    self::assertStringNotContainsString("return \$this->redirect('shipping/backend/carrier');\n\n        } catch (\\Throwable", $src);
}

private function assert_GuidePublicRouter_testGuideShippingAliasMapsToFrontendController(): void
{
    $path = 'guide/shipping';
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('shipping/frontend/guide/shipping', $path);
    self::assertSame('Weline_Shipping', $rule['module'] ?? null);
}

private function assert_GuidePublicRouter_testGuideReturnsAliasMapsToFrontendController(): void
{
    $path = 'guide/returns';
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('shipping/frontend/guide/returns', $path);
    self::assertSame('Weline_Shipping', $rule['module'] ?? null);
}

private function assert_GuidePublicRouter_testIgnoresAlreadyOwnedRules(): void
{
    $path = 'guide/shipping';
    $rule = ['module' => 'Weline_Other'];
    Router::process($path, $rule);
    self::assertSame('guide/shipping', $path);
    self::assertSame('Weline_Other', $rule['module']);
}
}
