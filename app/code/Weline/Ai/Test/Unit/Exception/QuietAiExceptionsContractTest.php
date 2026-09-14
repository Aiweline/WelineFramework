<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Exception\AiTransportException;
use Weline\Ai\Exception\TranslationBusyException;
use Weline\Ai\Service\TranslationConcurrencyGate;

final class QuietAiExceptionsContractTest extends TestCase
{
    public function testBusyAndTransportDoNotExtendFrameworkAppException(): void
    {
        self::assertTrue(is_subclass_of(TranslationBusyException::class, \RuntimeException::class));
        self::assertTrue(is_subclass_of(AiTransportException::class, \RuntimeException::class));
        self::assertFalse(is_subclass_of(TranslationBusyException::class, \Weline\Framework\App\Exception::class));
        self::assertFalse(is_subclass_of(AiTransportException::class, \Weline\Framework\App\Exception::class));
    }

    public function testGateIsBusyRecognizesTranslationBusyException(): void
    {
        $gate = new TranslationConcurrencyGate();
        $busy = new TranslationBusyException(TranslationConcurrencyGate::BUSY_MARKER . ': held');
        self::assertTrue($gate->isBusy($busy));
        self::assertTrue($gate->isBusyMarker($busy->getMessage()));
        self::assertFalse($gate->isBusy(new \RuntimeException('other')));
    }

    public function testOpenAiProviderThrowsAiTransportExceptionForConnectFailures(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/Provider/OpenAiProvider.php');
        self::assertStringContainsString('use Weline\\Ai\\Exception\\AiTransportException;', $src);
        self::assertStringContainsString('throw new AiTransportException', $src);
        self::assertStringContainsString('isNonRetryableTransportError', $src);
    }

    public function testAiServiceWrapKeepsTransportQuiet(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/AiService.php');
        self::assertStringContainsString('AiTransportException', $src);
        self::assertStringContainsString('isOperationalAiTransportMessage', $src);
        self::assertStringContainsString('ExceptionLogThrottle', $src);
    }
}
