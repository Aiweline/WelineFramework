<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service\Protocol;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Observer\ProtocolRouteRewrite;
use Weline\Geo\Service\Protocol\IndustryProtocolDocuments;
use Weline\Server\Service\StaticRequestBypassDecider;

class IndustryProtocolDocumentsTest extends TestCase
{
    public function testAiTxtIsTrainingOptOutAndNotAnLlmsCopy(): void
    {
        $body = IndustryProtocolDocuments::aiTxt();

        self::assertStringContainsString('User-Agent: *', $body);
        self::assertStringContainsString('Disallow: /', $body);
        self::assertStringNotContainsString('## Products', $body);
        self::assertStringNotContainsString('## Discovery', $body);
    }

    public function testLicenseAllowsSearchAndAiInputButNotTraining(): void
    {
        $open = IndustryProtocolDocuments::licenseXml(true);
        self::assertStringContainsString('xmlns="https://rslstandard.org/rsl"', $open);
        self::assertStringContainsString('<permits type="usage">search ai-input</permits>', $open);
        self::assertStringNotContainsString('ai-train', $open);

        $closed = IndustryProtocolDocuments::licenseXml(false);
        self::assertStringContainsString('<permits type="usage">search</permits>', $closed);
        self::assertStringNotContainsString('ai-input', $closed);
    }

    public function testTdmRepDoesNotBlanketReserveMining(): void
    {
        $decoded = json_decode(IndustryProtocolDocuments::tdmRepJson(), true);

        self::assertSame('/', $decoded[0]['location']);
        self::assertSame(0, $decoded[0]['tdm-reservation']);
    }

    public function testTrustTxtRefusesTraining(): void
    {
        $body = IndustryProtocolDocuments::trustTxt('https://shop.example');

        self::assertStringContainsString('member=https://shop.example', $body);
        self::assertStringContainsString('datatrainingallowed=no', $body);
        self::assertStringNotContainsString('member=', IndustryProtocolDocuments::trustTxt(''));
    }

    public function testRobotsContributionSeparatesLicenseAndContentSignal(): void
    {
        $lines = IndustryProtocolDocuments::robotsContribution('https://shop.example', true, true);

        self::assertSame(['License: https://shop.example/license.xml'], $lines['global_lines']);
        self::assertContains('Content-Signal: search=yes, ai-input=yes, ai-train=no', $lines['star_lines']);

        $closed = IndustryProtocolDocuments::robotsContribution('https://shop.example', true, false);
        self::assertContains('Content-Signal: search=yes, ai-input=no, ai-train=no', $closed['star_lines']);

        $blocked = IndustryProtocolDocuments::robotsContribution('not a url', false, true);
        self::assertSame([], $blocked['global_lines']);
        self::assertContains('Content-Signal: search=no, ai-input=no, ai-train=no', $blocked['star_lines']);
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
        ] as $path) {
            self::assertArrayHasKey($path, $routes, $path);
            self::assertFalse(\weline_is_static_file_path('/' . $path), $path);
            self::assertTrue(StaticRequestBypassDecider::shouldDeferToFramework($path), $path);
        }

        self::assertSame('geo/protocol/llms', $routes['ai.txt']);
        self::assertSame('geo/protocol/llms', $routes['license.xml']);
        self::assertNotSame($routes['llms-full.txt'], $routes['ai.txt']);
    }
}
