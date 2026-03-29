<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AI\Tool;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\AI\Tool\RecommendDomainsTool;

class RecommendDomainsToolTest extends TestCase
{
    private RecommendDomainsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new RecommendDomainsTool();
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $this->assertSame('recommend_domains', $this->tool->getName());
    }

    public function testGetDescriptionIsNotEmpty(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetParametersHasExpectedStructure(): void
    {
        $params = $this->tool->getParameters();
        $this->assertIsArray($params);
        $this->assertSame('object', $params['type']);
        $this->assertArrayHasKey('site_description', $params['properties']);
        $this->assertArrayHasKey('desired_tlds', $params['properties']);
        $this->assertArrayHasKey('max_per_tld', $params['properties']);
        $this->assertContains('site_description', $params['required']);
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->tool->isEnabled());
    }

    public function testExecuteReturnsErrorWhenDescriptionEmpty(): void
    {
        $result = $this->tool->execute(['site_description' => '']);

        $this->assertSame(['error' => 'site_description is required'], $result);
    }

    public function testExecuteReturnsSuggestionsForValidDescription(): void
    {
        $result = $this->tool->execute(['site_description' => 'online pet store selling organic treats']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('site_description', $result);
        $this->assertArrayHasKey('keywords', $result);
        $this->assertArrayHasKey('suggestions', $result);
        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('next_step', $result);
        $this->assertSame('check_domain_availability', $result['next_step']);
        $this->assertGreaterThan(0, $result['total_count']);
        $this->assertIsArray($result['suggestions']);
    }

    public function testExecuteSuggestionsHaveRequiredFields(): void
    {
        $result = $this->tool->execute(['site_description' => 'tech startup for AI coding']);

        foreach ($result['suggestions'] as $suggestion) {
            $this->assertArrayHasKey('domain', $suggestion);
            $this->assertArrayHasKey('reason', $suggestion);
            $this->assertArrayHasKey('tld', $suggestion);
            $this->assertArrayHasKey('pattern', $suggestion);
            $this->assertIsString($suggestion['domain']);
            $this->assertIsString($suggestion['reason']);
            $this->assertNotEmpty($suggestion['domain']);
            $this->assertStringStartsWith('.', $suggestion['tld']);
        }
    }

    public function testExecuteRespectsDesiredTlds(): void
    {
        $result = $this->tool->execute([
            'site_description' => 'fashion boutique online',
            'desired_tlds' => ['.com', '.io'],
        ]);

        foreach ($result['suggestions'] as $suggestion) {
            $this->assertContains($suggestion['tld'], ['.com', '.io']);
        }
    }

    public function testExecuteRespectsMaxPerTld(): void
    {
        $result = $this->tool->execute([
            'site_description' => 'cooking recipes blog',
            'desired_tlds' => ['.com', '.net', '.org', '.io'],
            'max_per_tld' => 1,
        ]);

        $tldCounts = [];
        foreach ($result['suggestions'] as $suggestion) {
            $tld = $suggestion['tld'];
            $tldCounts[$tld] = ($tldCounts[$tld] ?? 0) + 1;
        }

        foreach ($tldCounts as $count) {
            $this->assertLessThanOrEqual(1, $count);
        }
    }

    public function testExecuteFiltersShortKeywords(): void
    {
        $result = $this->tool->execute(['site_description' => 'a b c d e f g hi']);

        $this->assertIsArray($result['keywords']);
    }

    public function testExecuteWithDefaultTlds(): void
    {
        $result = $this->tool->execute(['site_description' => 'digital marketing agency']);

        $this->assertGreaterThan(0, $result['total_count']);
        foreach ($result['suggestions'] as $suggestion) {
            $this->assertContains($suggestion['tld'], ['.com', '.io', '.net', '.org']);
        }
    }
}
