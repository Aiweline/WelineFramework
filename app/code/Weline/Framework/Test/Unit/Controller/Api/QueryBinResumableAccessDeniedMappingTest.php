<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Binary\EmergencyPacket;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;

final class QueryBinResumableAccessDeniedMappingTest extends TestCase
{
    public function testQueryBinMapsResumableAccessDeniedBeforeInternalServerError(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Framework/Controller/Api/QueryBin.php');

        $deniedPos = strpos($source, 'catch (ResumableTaskAccessDeniedException');
        $throwablePos = strpos($source, 'catch (\\Throwable');
        self::assertNotFalse($deniedPos);
        self::assertNotFalse($throwablePos);
        self::assertLessThan($throwablePos, $deniedPos);

        $deniedBlock = substr($source, $deniedPos, $throwablePos - $deniedPos);
        self::assertStringContainsString("'backend_attestation_invalid'", $deniedBlock);
        self::assertStringContainsString('$statusCode = 401;', $deniedBlock);
        self::assertStringNotContainsString(EmergencyPacket::ERROR_CODE, $deniedBlock);
        self::assertStringNotContainsString(EmergencyPacket::ERROR_MESSAGE, $deniedBlock);
        self::assertStringNotContainsString('logUnexpectedFailure', $deniedBlock);
    }

    public function testResumableAccessDeniedExceptionRemainsARuntimeException(): void
    {
        $exception = new ResumableTaskAccessDeniedException(
            'Runtime task backend authority no longer matches the Session.',
        );
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertNotInstanceOf(\InvalidArgumentException::class, $exception);
    }
}
