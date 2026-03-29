<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AI\Tool;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\AI\Tool\GeneratePageDraftTool;
use Weline\Websites\Service\AiWorkbench\VirtualThemeWorkbenchService;

class GeneratePageDraftToolTest extends TestCase
{
    private GeneratePageDraftTool $tool;
    private VirtualThemeWorkbenchService $mockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockService = $this->createMock(VirtualThemeWorkbenchService::class);
        $this->tool = new GeneratePageDraftTool($this->mockService);
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $this->assertSame('generate_page_draft', $this->tool->getName());
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
        $this->assertArrayHasKey('page_type', $params['properties']);
        $this->assertArrayHasKey('site_title', $params['properties']);
        $this->assertContains('page_type', $params['required']);
        $this->assertContains('site_title', $params['required']);
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->tool->isEnabled());
    }

    public function testExecuteReturnsErrorWhenPageTypeEmpty(): void
    {
        $result = $this->tool->execute(['page_type' => '', 'site_title' => 'Test Site']);

        $this->assertFalse($result['success']);
        $this->assertSame('page_type is required', $result['message']);
    }

    public function testExecuteReturnsErrorWhenSiteTitleEmpty(): void
    {
        $result = $this->tool->execute(['page_type' => 'home_page', 'site_title' => '']);

        $this->assertFalse($result['success']);
        $this->assertSame('site_title is required', $result['message']);
    }

    public function testExecuteReturnsSuccessForValidRequest(): void
    {
        $result = $this->tool->execute([
            'page_type' => 'home_page',
            'site_title' => 'Test Site',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('home_page', $result['page_type']);
        $this->assertSame('Test Site', $result['site_title']);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('suggestions', $result);
        $this->assertArrayHasKey('next_step', $result);
        $this->assertSame('update_page_draft', $result['next_step']);
    }

    public function testExecuteHomePageHasRequiredContentKeys(): void
    {
        $result = $this->tool->execute([
            'page_type' => 'home_page',
            'site_title' => 'Test Site',
            'site_description' => 'A test site for pets',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('hero_title', $result['content']);
        $this->assertArrayHasKey('hero_subtitle', $result['content']);
        $this->assertArrayHasKey('sections', $result['content']);
    }

    public function testExecuteAboutPageHasRequiredContentKeys(): void
    {
        $result = $this->tool->execute([
            'page_type' => 'about_page',
            'site_title' => 'Test Site',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('page_title', $result['content']);
        $this->assertArrayHasKey('story_content', $result['content']);
        $this->assertArrayHasKey('values', $result['content']);
    }

    public function testExecuteContactPageHasRequiredContentKeys(): void
    {
        $result = $this->tool->execute([
            'page_type' => 'contact_page',
            'site_title' => 'Test Site',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('contact_methods', $result['content']);
        $this->assertIsArray($result['content']['contact_methods']);
    }

    public function testExecuteCustomPageReturnsGenericStructure(): void
    {
        $result = $this->tool->execute([
            'page_type' => 'custom_page',
            'site_title' => 'Custom Page',
            'site_description' => 'Custom description',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('page_title', $result['content']);
        $this->assertArrayHasKey('hero_title', $result['content']);
        $this->assertArrayHasKey('content', $result['content']);
    }

    public function testExecuteReturnsImprovementSuggestionsForHomePage(): void
    {
        $result = $this->tool->execute([
            'page_type' => 'home_page',
            'site_title' => 'Test Site',
        ]);

        $this->assertIsArray($result['suggestions']);
        $hasImprovement = false;
        foreach ($result['suggestions'] as $s) {
            if (($s['type'] ?? '') === 'improvement') {
                $hasImprovement = true;
                break;
            }
        }
        $this->assertTrue($hasImprovement);
    }

    public function testExecuteWithThemeName(): void
    {
        $result = $this->tool->execute([
            'page_type' => 'home_page',
            'site_title' => 'Test Site',
            'theme_name' => 'Modern Blue Theme',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('content', $result);
    }
}
