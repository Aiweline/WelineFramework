<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Exception\AiTransportException;
use Weline\Ai\Exception\TranslationBusyException;
use Weline\Ai\Service\TranslationConcurrencyGate;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllAiExceptionContractsPass().
 */
final class AiExceptionContractsTest extends TestCase
{
    public function testAllAiExceptionContractsPass(): void
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
            'QuietAiExceptions::testBusyAndTransportDoNotExtendFrameworkAppException' => function () {
            $this->assert_QuietAiExceptions_testBusyAndTransportDoNotExtendFrameworkAppException();
        },
            'QuietAiExceptions::testGateIsBusyRecognizesTranslationBusyException' => function () {
            $this->assert_QuietAiExceptions_testGateIsBusyRecognizesTranslationBusyException();
        },
            'QuietAiExceptions::testOpenAiProviderThrowsAiTransportExceptionForConnectFailures' => function () {
            $this->assert_QuietAiExceptions_testOpenAiProviderThrowsAiTransportExceptionForConnectFailures();
        },
            'QuietAiExceptions::testAiServiceWrapKeepsTransportQuiet' => function () {
            $this->assert_QuietAiExceptions_testAiServiceWrapKeepsTransportQuiet();
        },
        ];
    }

private function assert_QuietAiExceptions_testBusyAndTransportDoNotExtendFrameworkAppException(): void
{
    self::assertTrue(is_subclass_of(TranslationBusyException::class, \RuntimeException::class));
    self::assertTrue(is_subclass_of(AiTransportException::class, \RuntimeException::class));
    self::assertFalse(is_subclass_of(TranslationBusyException::class, \Weline\Framework\App\Exception::class));
    self::assertFalse(is_subclass_of(AiTransportException::class, \Weline\Framework\App\Exception::class));
}

private function assert_QuietAiExceptions_testGateIsBusyRecognizesTranslationBusyException(): void
{
    $gate = new TranslationConcurrencyGate();
    $busy = new TranslationBusyException(TranslationConcurrencyGate::BUSY_MARKER . ': held');
    self::assertTrue($gate->isBusy($busy));
    self::assertTrue($gate->isBusyMarker($busy->getMessage()));
    self::assertFalse($gate->isBusy(new \RuntimeException('other')));
}

private function assert_QuietAiExceptions_testOpenAiProviderThrowsAiTransportExceptionForConnectFailures(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/Service/Provider/OpenAiProvider.php');
    self::assertStringContainsString('use Weline\Ai\Exception\AiTransportException;', $src);
    self::assertStringContainsString('throw new AiTransportException', $src);
    self::assertStringContainsString('isNonRetryableTransportError', $src);
}

private function assert_QuietAiExceptions_testAiServiceWrapKeepsTransportQuiet(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/Service/AiService.php');
    self::assertStringContainsString('AiTransportException', $src);
    self::assertStringContainsString('isOperationalAiTransportMessage', $src);
    self::assertStringContainsString('ExceptionLogThrottle', $src);
}
}
