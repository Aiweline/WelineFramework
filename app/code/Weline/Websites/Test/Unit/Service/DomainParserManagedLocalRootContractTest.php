<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\DomainParserService;

final class DomainParserManagedLocalRootContractTest extends TestCase
{
    public function testManagedTestWelineComRootWinsOverPublicSuffixList(): void
    {
        $parser = new DomainParserService();

        self::assertSame('test.weline.com', $parser->parseRootDomain('grocery.test.weline.com'));
        self::assertSame('test.weline.com', $parser->parseRootDomain('p05113ef3.test.weline.com'));
        self::assertSame('test.weline.com', $parser->parseRootDomain('test.weline.com'));
    }

    public function testLegacyWelineTestRootStillResolved(): void
    {
        $parser = new DomainParserService();

        self::assertSame('weline.test', $parser->parseRootDomain('shop.weline.test'));
    }

    public function testPublicDomainStillUsesRegistrableRoot(): void
    {
        $parser = new DomainParserService();

        self::assertSame('example.com', $parser->parseRootDomain('www.example.com'));
    }
}
