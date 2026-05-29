<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Url;

final class UrlPrefixNormalizationTest extends TestCase
{
    private array $serverBackup = [];
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->envSnapshot = WelineEnv::getInstance()->capture();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->restore($this->envSnapshot);
        parent::tearDown();
    }

    public function testPrefixDoesNotAppendApiAreaSegmentAsCurrency(): void
    {
        if (Env::getAreaRoutePrefix('rest_frontend') === null) {
            self::markTestSkipped('REST frontend route prefix is not configured.');
        }

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/backend/affiliate',
            'HTTP_HOST' => '127.0.0.1:9502',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => '9502',
            'REQUEST_SCHEME' => 'http',
            'WELINE_AREA' => 'backend',
            'WELINE_USER_CURRENCY' => 'API',
            'WELINE_USER_LANG' => 'en_US',
        ];

        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);

        self::assertStringNotContainsString('/API', Url::getPrefix());
    }

    public function testDetectCurrencyRejectsApiAreaSegment(): void
    {
        if (Env::getAreaRoutePrefix('rest_frontend') === null) {
            self::markTestSkipped('REST frontend route prefix is not configured.');
        }

        $uri = '/API/en_US/affiliate/backend/affiliate';

        self::assertFalse(Url::detectCurrency($uri, 'API'));
        self::assertSame('/API/en_US/affiliate/backend/affiliate', $uri);
    }
}
