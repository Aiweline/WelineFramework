<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request\RequestAbstract;

final class RequestDocumentNavigationTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->reset();
        parent::tearDown();
    }

    public function testBrowserDocumentNavigationIsAllowed(): void
    {
        $request = $this->createRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_SEC_FETCH_MODE' => 'navigate',
        ]);

        self::assertTrue($request->isDocumentNavigationRequest());
    }

    public function testAjaxRequestIsNotDocumentNavigation(): void
    {
        $request = $this->createRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT' => 'application/json, text/javascript, */*; q=0.01',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertFalse($request->isDocumentNavigationRequest());
    }

    public function testFetchRequestIsNotDocumentNavigation(): void
    {
        $request = $this->createRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT' => '*/*',
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_SEC_FETCH_MODE' => 'cors',
        ]);

        self::assertFalse($request->isDocumentNavigationRequest());
    }

    public function testJsonAcceptIsNotDocumentNavigation(): void
    {
        $request = $this->createRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertFalse($request->isDocumentNavigationRequest());
    }

    public function testPostIsNotDocumentNavigation(): void
    {
        $request = $this->createRequest([
            'REQUEST_METHOD' => 'POST',
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_SEC_FETCH_MODE' => 'navigate',
        ]);

        self::assertFalse($request->isDocumentNavigationRequest());
    }

    private function createRequest(array $server): RequestAbstract
    {
        $server += [
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'admin.test',
            'SERVER_NAME' => 'admin.test',
        ];
        $_SERVER = $server;
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $server);

        return new class extends RequestAbstract {
        };
    }
}
