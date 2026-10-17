<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCaptchaControllerContractsPass().
 */
final class CaptchaControllerContractsTest extends TestCase
{
    public function testAllCaptchaControllerContractsPass(): void
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
            'GoogleRedirectTerminateRethrow::testAuthorizeRethrowsResponseTerminateException' => function () {
            $this->assert_GoogleRedirectTerminateRethrow_testAuthorizeRethrowsResponseTerminateException();
        },
        ];
    }

private function assert_GoogleRedirectTerminateRethrow_testAuthorizeRethrowsResponseTerminateException(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Controller/Backend/Google.php');
    self::assertStringContainsString('use Weline\Framework\Http\RedirectException;', $src);
    self::assertStringContainsString('use Weline\Framework\Http\ResponseTerminateException;', $src);
    self::assertStringContainsString("throw new RedirectException((string)\$result['authorization_url'], 302);", $src);
    self::assertStringContainsString('catch (ResponseTerminateException $terminate)', $src);
    self::assertStringContainsString('throw $terminate;', $src);
    self::assertStringNotContainsString("return \$this->redirect(\$result['authorization_url']);", $src);
}
}
