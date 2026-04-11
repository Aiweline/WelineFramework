<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Controller\Core;
use Weline\Framework\Http\Response;

final class ControllerResponseHelperTest extends TestCase
{
    public function testCoreHelpersKeepLegacyArrayPayloads(): void
    {
        $controller = new class extends Core {
            public function successResponse(): array
            {
                return $this->success('ok', ['id' => 1], 201);
            }

            public function warningResponse(): array
            {
                return $this->warning('careful', ['warn' => true], 202);
            }

            public function errorResponse(): array
            {
                return $this->error('bad', ['reason' => 'x'], 422);
            }
        };

        $success = $controller->successResponse();
        self::assertSame(['success' => true, 'error' => false, 'code' => 201, 'msg' => 'ok', 'message' => 'ok', 'data' => ['id' => 1]], $success);

        $warning = $controller->warningResponse();
        self::assertSame('warning', $warning['status'] ?? null);
        self::assertTrue((bool)($warning['warning'] ?? false));

        $error = $controller->errorResponse();
        self::assertSame(422, $error['code'] ?? null);
        self::assertTrue((bool)($error['error'] ?? false));
    }

    public function testAbstractRestFetchKeepsLegacyStringPayloads(): void
    {
        $controller = new class extends AbstractRestController {
            public function __construct()
            {
            }

            public function jsonResponse(): string
            {
                return $this->fetch(['foo' => 'bar'], self::fetch_JSON);
            }

            public function xmlResponse(): string
            {
                return $this->fetch(['foo' => 'bar'], self::fetch_XML);
            }

            public function textResponse(): string
            {
                return $this->fetch(['foo' => 'bar'], self::fetch_STRING);
            }
        };

        self::assertSame(['foo' => 'bar'], \json_decode($controller->jsonResponse(), true));

        $xml = $controller->xmlResponse();
        self::assertStringContainsString('<foo><![CDATA[bar]]></foo>', $xml);

        $text = $controller->textResponse();
        self::assertSame('foo:bar', $text);
    }

    public function testRouterRuntimeCanStillNormalizeDirectResponseObjects(): void
    {
        $response = Response::json(['ok' => true], 201);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['ok' => true], \json_decode($response->getBody(), true));
    }
}
