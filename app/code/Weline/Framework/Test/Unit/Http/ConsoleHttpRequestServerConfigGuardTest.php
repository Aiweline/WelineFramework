<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;

final class ConsoleHttpRequestServerConfigGuardTest extends TestCase
{
    public function testScalarWlsConfigIsNormalizedBeforeArrayAccess(): void
    {
        $contents = $this->readRequestSource();

        self::assertIsString($contents);

        $guardPosition = \strpos($contents, 'if (!\is_array($serverConfig))');
        $arrayKeyPosition = \strpos($contents, "\array_key_exists('https', \$serverConfig)");

        self::assertNotFalse($guardPosition, 'Expected scalar WLS config normalization guard.');
        self::assertNotFalse($arrayKeyPosition, 'Expected WLS HTTPS array_key_exists lookup.');
        self::assertLessThan($arrayKeyPosition, $guardPosition, 'WLS config must be normalized before array access.');
        self::assertStringContainsString('$serverConfig = [];', \substr($contents, $guardPosition, 120));
    }

    public function testExecuteUsesTransportFallbackInsteadOfGuzzleOnlyPath(): void
    {
        $contents = $this->readRequestSource();

        self::assertStringContainsString('$response = $this->sendRequest(', $contents);
        self::assertStringContainsString("function_exists('curl_init')", $contents);
        self::assertStringContainsString('class_exists(\GuzzleHttp\Client::class)', $contents);
    }

    public function testRequestPrefersWlsRuntimeInstanceMetadata(): void
    {
        $contents = $this->readRequestSource();

        self::assertStringContainsString('resolveWlsRuntimeHttpTarget', $contents);
        self::assertStringContainsString("'instances' . DIRECTORY_SEPARATOR . \$safeName . '.json'", $contents);
        self::assertStringContainsString("'ssl_enabled'", $contents);
        self::assertStringContainsString('$runtimeTarget[\'port\']', $contents);
    }

    private function readRequestSource(): string
    {
        $root = \dirname(__DIR__, 7);
        $requestPath = $root . '/app/code/Weline/Framework/Http/Console/Http/Request.php';
        $contents = \file_get_contents($requestPath);

        self::assertIsString($contents);

        return $contents;
    }
}
