<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\WebsiteEntryUrlService;

final class WebsiteEntryUrlServicePortContractTest extends TestCase
{
    private ?string $previousHttpHost = null;

    protected function tearDown(): void
    {
        if ($this->previousHttpHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->previousHttpHost;
        }

        parent::tearDown();
    }

    public function testWithCurrentRequestPortAppendsLivePortWhenStoredUrlHasNone(): void
    {
        $this->previousHttpHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'p05113ef3.weline.test:9555';

        $service = new WebsiteEntryUrlService(new \Weline\Websites\Model\WebsiteDomain());

        self::assertSame(
            'http://p05113ef3.weline.test:9555',
            $service->withCurrentRequestPort('http://p05113ef3.weline.test'),
        );
    }

    public function testWithCurrentRequestPortLeavesExplicitPortUntouched(): void
    {
        $this->previousHttpHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'p05113ef3.weline.test:9555';

        $service = new WebsiteEntryUrlService(new \Weline\Websites\Model\WebsiteDomain());

        self::assertSame(
            'http://p05113ef3.weline.test:8080',
            $service->withCurrentRequestPort('http://p05113ef3.weline.test:8080'),
        );
    }
}
