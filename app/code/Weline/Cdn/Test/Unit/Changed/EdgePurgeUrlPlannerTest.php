<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Changed;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\EdgePurgeUrlPlanner;

/** 资源变更清边缘缓存：公网主机名只由 CDN 域名绑定决定。 */
final class EdgePurgeUrlPlannerTest extends TestCase
{
    public function testInstallLocalUrlsAreProjectedOntoBoundPublicEndpointsAndLocales(): void
    {
        $domains = [$this->domain(1, 1, 'playinginchina.com')];

        $planned = EdgePurgeUrlPlanner::project(
            $domains,
            ['http://localhost/', 'http://localhost/guide?_wl=en_US&_wc=USD'],
            [1],
            $this->endpoints(['https://www.playinginchina.com', 'https://playinginchina.com']),
            $this->locales(['', '/zh_Hans_CN']),
        );

        self::assertSame([1 => [
            'https://www.playinginchina.com/',
            'https://www.playinginchina.com/zh_Hans_CN/',
            'https://www.playinginchina.com/guide',
            'https://www.playinginchina.com/zh_Hans_CN/guide',
            'https://playinginchina.com/',
            'https://playinginchina.com/zh_Hans_CN/',
            'https://playinginchina.com/guide',
            'https://playinginchina.com/zh_Hans_CN/guide',
        ]], $planned);
    }

    public function testRelativePathsWithoutLocaleVariantsFallBackToEndpointBase(): void
    {
        $planned = EdgePurgeUrlPlanner::project(
            [$this->domain(5, 2, 'example.com')],
            ['/about'],
            [2],
            $this->endpoints(['https://www.example.com']),
            static fn(): array => [],
        );

        self::assertSame([5 => ['https://www.example.com/about']], $planned);
    }

    public function testBoundAbsoluteUrlsKeepTheirHostAndAreNotLocalized(): void
    {
        $planned = EdgePurgeUrlPlanner::project(
            [$this->domain(1, 1, 'example.com'), $this->domain(2, 2, 'other.org')],
            ['https://shop.other.org/p/1?_wl=en_US&color=red'],
            [1],
            $this->endpoints(['https://www.example.com']),
            static function (): array {
                throw new \LogicException('bound public URLs must not be re-localized');
            },
        );

        self::assertSame([2 => ['https://shop.other.org/p/1?color=red']], $planned);
    }

    public function testPathIntentsStayInsideTheChangeWebsite(): void
    {
        $planned = EdgePurgeUrlPlanner::project(
            [$this->domain(1, 1, 'example.com'), $this->domain(2, 2, 'other.org')],
            ['http://localhost/guide'],
            [1],
            static fn(Domain $domain): array => [[
                'host' => 'www.' . $domain->getData(Domain::schema_fields_DOMAIN_NAME),
                'base_url' => 'https://www.' . $domain->getData(Domain::schema_fields_DOMAIN_NAME),
            ]],
            static fn(): array => [],
        );

        self::assertSame([1 => ['https://www.example.com/guide']], $planned);
    }

    public function testLocalizedVariantsOnForeignHostsAreDropped(): void
    {
        $planned = EdgePurgeUrlPlanner::project(
            [$this->domain(1, 1, 'example.com')],
            ['/guide'],
            [1],
            $this->endpoints(['https://www.example.com']),
            static fn(): array => ['https://www.example.com/guide', 'https://evil.test/guide'],
        );

        self::assertSame([1 => ['https://www.example.com/guide']], $planned);
    }

    public function testTraversalPathsAreRejected(): void
    {
        $planned = EdgePurgeUrlPlanner::project(
            [$this->domain(1, 1, 'example.com')],
            ['/a/../b', 'http://localhost/..'],
            [1],
            $this->endpoints(['https://www.example.com']),
            static fn(): array => [],
        );

        self::assertSame([], $planned);
    }

    private function domain(int $id, int $siteId, string $name): Domain
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getData')->willReturnCallback(static fn(string $key): mixed => match ($key) {
            Domain::schema_fields_DOMAIN_ID => $id,
            Domain::schema_fields_SITE_ID => $siteId,
            Domain::schema_fields_DOMAIN_NAME => $name,
            default => null,
        });

        return $domain;
    }

    /** @param list<string> $bases */
    private function endpoints(array $bases): \Closure
    {
        return static fn(): array => array_map(
            static fn(string $base): array => ['host' => (string)parse_url($base, PHP_URL_HOST), 'base_url' => $base],
            $bases,
        );
    }

    /** @param list<string> $prefixes */
    private function locales(array $prefixes): \Closure
    {
        return static fn(int $siteId, string $base, string $path): array => array_map(
            static fn(string $prefix): string => $base . $prefix . $path,
            $prefixes,
        );
    }
}
