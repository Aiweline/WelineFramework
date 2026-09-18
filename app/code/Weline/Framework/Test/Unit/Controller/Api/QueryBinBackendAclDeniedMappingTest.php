<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Binary\EmergencyPacket;
use Weline\Framework\Runtime\FrontendWorkerBackendAuthorizationException;

final class QueryBinBackendAclDeniedMappingTest extends TestCase
{
    public function testQueryBinMapsBackendAclDeniedBeforeInternalServerError(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Framework/Controller/Api/QueryBin.php');

        $deniedPos = strpos($source, 'catch (FrontendWorkerBackendAuthorizationException');
        $throwablePos = strpos($source, 'catch (\\Throwable');
        self::assertNotFalse($deniedPos);
        self::assertNotFalse($throwablePos);
        self::assertLessThan($throwablePos, $deniedPos);

        $deniedBlock = substr($source, $deniedPos, $throwablePos - $deniedPos);
        self::assertStringContainsString("'backend_acl_denied'", $deniedBlock);
        self::assertStringContainsString('$exception->httpStatus', $deniedBlock);
        self::assertStringNotContainsString(EmergencyPacket::ERROR_CODE, $deniedBlock);
        self::assertStringNotContainsString('logUnexpectedFailure', $deniedBlock);
        self::assertStringNotContainsString('QueryUnexpectedFailurePayload', $deniedBlock);
    }

    public function testBinQueryMapsBackendAclDeniedBeforeInternalServerError(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Framework/Controller/Api/BinQuery.php');

        $deniedPos = strpos($source, 'catch (FrontendWorkerBackendAuthorizationException');
        $throwablePos = strpos($source, 'catch (\\Throwable');
        self::assertNotFalse($deniedPos);
        self::assertNotFalse($throwablePos);
        self::assertLessThan($throwablePos, $deniedPos);

        $deniedBlock = substr($source, $deniedPos, $throwablePos - $deniedPos);
        self::assertStringContainsString("'backend_acl_denied'", $deniedBlock);
        self::assertStringContainsString('errorPayload', $deniedBlock);
        self::assertStringNotContainsString('unexpectedFailurePayload', $deniedBlock);
        self::assertStringNotContainsString('logUnexpectedFailure', $deniedBlock);
    }

    public function testBackendAuthorizationExceptionExposesReasonAndHttpStatus(): void
    {
        $exception = new FrontendWorkerBackendAuthorizationException(
            'backend_acl_denied',
            403,
            '当前后台账号无权执行该操作。',
        );
        self::assertSame('backend_acl_denied', $exception->reason);
        self::assertSame(403, $exception->httpStatus);
        self::assertSame('当前后台账号无权执行该操作。', $exception->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $exception);
    }
}
