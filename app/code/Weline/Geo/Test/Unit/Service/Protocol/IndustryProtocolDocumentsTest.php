<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service\Protocol;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Observer\ProtocolRouteRewrite;
use Weline\Geo\Service\Protocol\IndustryProtocolDocuments;
use Weline\Server\Service\StaticRequestBypassDecider;

class IndustryProtocolDocumentsTest extends TestCase
{
    public function testAiTxtAllowsTrainingAndIsNotAnLlmsCopy(): void
    {
        $body = IndustryProtocolDocuments::aiTxt();

        self::assertStringContainsString('User-Agent: *', $body);
        self::assertStringContainsString('Allow: /', $body);
        self::assertStringNotContainsString('Disallow: /', $body);
        self::assertStringNotContainsString('## Products', $body);
        self::assertStringNotContainsString('## Discovery', $body);
    }

    public function testLicenseAllowsSearchAiInputAndTraining(): void
    {
        $open = IndustryProtocolDocuments::licenseXml(true);
        self::assertStringContainsString('xmlns="https://rslstandard.org/rsl"', $open);
        self::assertStringContainsString('<permits type="usage">search ai-input ai-train</permits>', $open);

        $closed = IndustryProtocolDocuments::licenseXml(false);
        self::assertStringContainsString('<permits type="usage">search ai-train</permits>', $closed);
        self::assertStringNotContainsString('ai-input', $closed);
    }

    public function testTdmRepDoesNotBlanketReserveMining(): void
    {
        $decoded = json_decode(IndustryProtocolDocuments::tdmRepJson(), true);

        self::assertSame('/', $decoded[0]['location']);
        self::assertSame(0, $decoded[0]['tdm-reservation']);
    }

    public function testTrustTxtAllowsTraining(): void
    {
        $body = IndustryProtocolDocuments::trustTxt('https://shop.example');

        self::assertStringContainsString('member=https://shop.example', $body);
        self::assertStringContainsString('datatrainingallowed=yes', $body);
        self::assertStringNotContainsString('member=', IndustryProtocolDocuments::trustTxt(''));
    }

    public function testRobotsContributionSeparatesLicenseAndContentSignal(): void
    {
        $lines = IndustryProtocolDocuments::robotsContribution('https://shop.example', true, true);

        self::assertSame(['# RSL license: https://shop.example/license.xml'], $lines['global_lines']);
        self::assertSame([], preg_grep('/^License:/i', $lines['global_lines']), 'REP has no License directive; consoles report it as unknown');
        self::assertContains('# Content-Signal: search=yes, ai-input=yes, ai-train=yes', $lines['star_lines']);
        self::assertSame([], preg_grep('/^Content-Signal:/i', $lines['star_lines']), 'bare Content-Signal fails Lighthouse robots-txt');

        $closed = IndustryProtocolDocuments::robotsContribution('https://shop.example', true, false);
        self::assertContains('# Content-Signal: search=yes, ai-input=no, ai-train=yes', $closed['star_lines']);

        $blocked = IndustryProtocolDocuments::robotsContribution('not a url', false, true);
        self::assertSame([], $blocked['global_lines']);
        self::assertContains('# Content-Signal: search=no, ai-input=no, ai-train=no', $blocked['star_lines']);
    }

    public function testHeadLinksTheIndustryDocuments(): void
    {
        $html = IndustryProtocolDocuments::headHtml('https://shop.example', 'Shop & Co', true, true);

        self::assertStringContainsString('name="tdm-reservation" content="0"', $html);
        self::assertStringContainsString('rel="license"', $html);
        self::assertStringContainsString('https://shop.example/license.xml', $html);
        self::assertStringContainsString('https://shop.example/ai.txt', $html);
        self::assertStringContainsString('https://shop.example/.well-known/tdmrep.json', $html);
        self::assertStringContainsString('https://shop.example/llms.txt', $html);
        self::assertStringContainsString('Shop &amp; Co', $html);
        self::assertStringContainsString('rel="ard"', $html);
        self::assertStringContainsString('https://shop.example/.well-known/ard.json', $html);
        self::assertStringContainsString('rel="ai-catalog"', $html);
        self::assertStringContainsString('https://shop.example/.well-known/ai-catalog.json', $html);
    }

    public function testAiCatalogJsonMatchesArdManifestSchema(): void
    {
        $body = IndustryProtocolDocuments::aiCatalogJson([
            [
                'identifier' => 'urn:air:shop.example:catalog:product-1',
                'displayName' => 'Product 1',
                'type' => 'application/web-page+json',
                'url' => 'https://shop.example/product-1',
                'representativeQueries' => ['buy product 1', 'product 1 reviews'],
            ],
        ], 'https://shop.example');

        $decoded = json_decode($body, true);
        self::assertSame('1.0', $decoded['specVersion'] ?? null);
        self::assertNotEmpty($decoded['entries']);
        $entry = $decoded['entries'][0];
        foreach (['identifier', 'displayName', 'type'] as $required) {
            self::assertArrayHasKey($required, $entry);
        }
        self::assertArrayHasKey('url', $entry);
        self::assertArrayNotHasKey('data', $entry, 'value-or-reference: exactly one of url/data');
        self::assertMatchesRegularExpression('/^urn:air:[a-zA-Z0-9.-]+(:[a-zA-Z0-9._-]+)+$/', $entry['identifier']);
        self::assertSame('shop.example', $decoded['host']['displayName'] ?? null);
    }

    public function testAiCatalogJsonWithoutEntriesAndBaseUrlStaysValid(): void
    {
        $decoded = json_decode(IndustryProtocolDocuments::aiCatalogJson([], ''), true);

        self::assertSame('1.0', $decoded['specVersion'] ?? null);
        self::assertSame([], $decoded['entries']);
        self::assertArrayNotHasKey('host', $decoded);
    }

    public function testProtocolPathsAreRoutedAndNotTreatedAsStaticFiles(): void
    {
        $routes = (new \ReflectionClass(ProtocolRouteRewrite::class))->getConstant('ROUTES');
        self::assertIsArray($routes);
        if (!\function_exists('weline_is_static_file_path')) {
            require_once \dirname(__DIR__, 5) . '/Framework/Common/functions.php';
        }

        foreach ([
            'ai.txt',
            'license.xml',
            'trust.txt',
            '.well-known/tdmrep.json',
            '.well-known/trust.txt',
            'llms.txt',
            'llms-full.txt',
            '.well-known/ai-catalog.json',
            '.well-known/ard.json',
        ] as $path) {
            self::assertArrayHasKey($path, $routes, $path);
            self::assertFalse(\weline_is_static_file_path('/' . $path), $path);
            self::assertTrue(StaticRequestBypassDecider::shouldDeferToFramework($path), $path);
        }

        self::assertSame('geo/protocol/llms', $routes['ai.txt']);
        self::assertSame('geo/protocol/llms', $routes['license.xml']);
        self::assertSame('geo/protocol/llms', $routes['.well-known/ai-catalog.json']);
        self::assertSame('geo/protocol/llms', $routes['.well-known/ard.json']);
        self::assertNotSame($routes['llms-full.txt'], $routes['ai.txt']);
    }
}
