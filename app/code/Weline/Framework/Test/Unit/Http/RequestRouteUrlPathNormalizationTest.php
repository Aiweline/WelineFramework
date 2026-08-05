<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;

final class RequestRouteUrlPathNormalizationTest extends TestCase
{
    public function testBackendRouteUrlPathStripsCurrentBackendPrefix(): void
    {
        $backendPrefix = (string)(Env::getAreaRoutePrefix('backend') ?? '');
        self::assertNotSame('', $backendPrefix);

        $request = $this->createBackendRequest([
            'REQUEST_URI' => '/' . $backendPrefix . '/admin/login',
            'WELINE_AREA_ROUTE' => $backendPrefix,
        ]);

        self::assertSame('admin/login', $request->getRouteUrlPath());
    }

    public function testBackendRouteUrlPathStripsConfiguredBackendPrefixFromExplicitUrl(): void
    {
        $backendPrefix = (string)(Env::getAreaRoutePrefix('backend') ?? '');
        self::assertNotSame('', $backendPrefix);

        $request = $this->createBackendRequest([
            'REQUEST_URI' => '/admin',
            'WELINE_AREA_ROUTE' => '',
        ]);

        self::assertSame(
            'pagebuilder/backend/ai-site-agent/workspace',
            $request->getRouteUrlPath(
                'https://p11005ce4.weline.test/' . $backendPrefix . '/pagebuilder/backend/ai-site-agent/workspace?foo=1'
            )
        );
    }

    private function createBackendRequest(array $server): Request
    {
        return new class($server) extends Request {
            public function __construct(private readonly array $serverValues)
            {
            }

            public function isBackend(): bool
            {
                return true;
            }

            public function isApiBackend(): bool
            {
                return false;
            }

            public function isApiFrontend(): bool
            {
                return false;
            }

            public function getMethod(): string
            {
                return 'GET';
            }

            public function getServer(string $key = ''): string|array
            {
                if ($key === '') {
                    return $this->serverValues;
                }

                return $this->serverValues[$key] ?? '';
            }
        };
    }
}
