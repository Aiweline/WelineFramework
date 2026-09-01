<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Binary\EmergencyPacket;
use Weline\Framework\Service\Query\QueryUnexpectedFailurePayload;

final class QueryUnexpectedFailurePayloadTest extends TestCase
{
    public function testBuildReturnsGenericMessageWhenNotInDev(): void
    {
        if (\defined('DEV') && DEV) {
            self::markTestSkipped('DEV is enabled in this PHPUnit process.');
        }

        $payload = QueryUnexpectedFailurePayload::build(new \RuntimeException('secret failure'));

        self::assertSame(EmergencyPacket::ERROR_CODE, $payload['code']);
        self::assertSame(EmergencyPacket::ERROR_MESSAGE, $payload['message']);
        self::assertArrayNotHasKey('debug', $payload);
    }

    public function testBuildReturnsDetailedMessageWhenDevEnabled(): void
    {
        if (!\defined('DEV') || !DEV) {
            self::markTestSkipped('DEV is disabled in this PHPUnit process.');
        }

        $throwable = new \RuntimeException('secret failure');
        $payload = QueryUnexpectedFailurePayload::build($throwable);

        self::assertSame(EmergencyPacket::ERROR_CODE, $payload['code']);
        self::assertStringContainsString('RuntimeException: secret failure', $payload['message']);
        self::assertStringContainsString($throwable->getFile(), $payload['message']);
        self::assertIsArray($payload['debug'] ?? null);
        self::assertSame('secret failure', $payload['debug']['exception_message'] ?? null);
        self::assertNotSame('', $payload['debug']['_exception_trace'] ?? '');
    }

    public function testQueryBinUsesUnexpectedFailurePayloadHelper(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Api/QueryBin.php',
        );

        self::assertStringContainsString('QueryUnexpectedFailurePayload::build', $source);
    }

    public function testBinQueryUsesUnexpectedFailurePayloadHelper(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Api/BinQuery.php',
        );

        self::assertStringContainsString('QueryUnexpectedFailurePayload::build', $source);
        self::assertStringContainsString('unexpectedFailurePayload', $source);
    }
}
