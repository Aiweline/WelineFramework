<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllVisitorObserverContractsPass().
 */
final class VisitorObserverContractsTest extends TestCase
{
    public function testAllVisitorObserverContractsPass(): void
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
            'LoginRegisterPixelInProcessDelivery::testLoginAndRegisterPixelUsePixelEventServiceTrack' => function () {
            $this->assert_LoginRegisterPixelInProcessDelivery_testLoginAndRegisterPixelUsePixelEventServiceTrack();
        },
        ];
    }

private function assert_LoginRegisterPixelInProcessDelivery_testLoginAndRegisterPixelUsePixelEventServiceTrack(): void
{
    $login = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Observer/LoginPixel.php');
    $register = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Observer/RegisterPixel.php');
    self::assertStringContainsString('PixelEventService', $login);
    self::assertStringContainsString('function deliverPixel', $login);
    self::assertStringContainsString("\$pixelEventService->track([", $login);
    self::assertStringNotContainsString('curl_init', $login);
    self::assertStringNotContainsString('/visitor/rest/v1/pixel', $login);
    self::assertStringContainsString('PixelEventService', $register);
    self::assertStringContainsString('function deliverPixel', $register);
    self::assertStringContainsString("\$pixelEventService->track([", $register);
    self::assertStringNotContainsString('curl_init', $register);
    self::assertStringNotContainsString('/visitor/rest/v1/pixel', $register);
}
}
