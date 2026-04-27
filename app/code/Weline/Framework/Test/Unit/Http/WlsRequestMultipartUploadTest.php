<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\WlsRequest;

final class WlsRequestMultipartUploadTest extends TestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];
    private array $cookieBackup = [];
    private array $requestBackup = [];
    private array $filesBackup = [];
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->cookieBackup = $_COOKIE;
        $this->requestBackup = $_REQUEST;
        $this->filesBackup = $_FILES;
        $this->tempFiles = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (\is_string($tempFile) && $tempFile !== '' && \is_file($tempFile)) {
                @\unlink($tempFile);
            }
        }

        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_COOKIE = $this->cookieBackup;
        $_REQUEST = $this->requestBackup;
        $_FILES = $this->filesBackup;
        parent::tearDown();
    }

    public function testMultipartUploadFilesAreReusedByParameterBag(): void
    {
        $boundary = '----wls-test-boundary';
        $fileBody = 'weline upload payload';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"cmd\"\r\n\r\n"
            . "upload\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"upload[]\"; filename=\"banner.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . $fileBody . "\r\n"
            . "--{$boundary}--\r\n";

        $rawRequest = "POST /media/backend/connector?cmd=upload&target=mm_YmFubmVy HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: " . \strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        $request = WlsRequest::fromRaw($rawRequest, ['HTTPS' => 'on', 'REQUEST_SCHEME' => 'https']);

        $firstTmp = $_FILES['upload']['tmp_name'][0] ?? null;
        self::assertIsString($firstTmp);
        $this->tempFiles[] = $firstTmp;
        self::assertFileExists($firstTmp);
        self::assertSame($fileBody, \file_get_contents($firstTmp));

        $request->getParameterBag();

        $secondTmp = $_FILES['upload']['tmp_name'][0] ?? null;
        self::assertSame($firstTmp, $secondTmp);
        self::assertSame('banner.txt', $_FILES['upload']['name'][0] ?? null);
        self::assertSame('upload', $_POST['cmd'] ?? null);
    }

    public function testRequestWithoutFilesClearsStaleFiles(): void
    {
        $_FILES = ['stale' => ['name' => 'old.txt']];

        $rawRequest = "GET /media/backend/connector?cmd=open HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "\r\n";

        WlsRequest::fromRaw($rawRequest, ['HTTPS' => 'on', 'REQUEST_SCHEME' => 'https']);

        self::assertSame([], $_FILES);
    }
}
