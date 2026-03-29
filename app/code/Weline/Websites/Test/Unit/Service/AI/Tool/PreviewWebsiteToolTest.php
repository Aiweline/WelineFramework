<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AI\Tool;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\AI\Tool\PreviewWebsiteTool;
use Weline\Websites\Service\AiWorkbench\DomainLifecycleBridgeService;
use Weline\Websites\Service\AiWorkbench\SessionService;
use Weline\Websites\Model\AiSiteBuilderSession;

class PreviewWebsiteToolTest extends TestCase
{
    private PreviewWebsiteTool $tool;
    private SessionService $mockSessionService;
    private DomainLifecycleBridgeService $mockBridgeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockSessionService = $this->createMock(SessionService::class);
        $this->mockBridgeService = $this->createMock(DomainLifecycleBridgeService::class);
        $this->tool = new PreviewWebsiteTool($this->mockSessionService, $this->mockBridgeService);
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $this->assertSame('preview_website', $this->tool->getName());
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
        $this->assertArrayHasKey('public_id', $params['properties']);
        $this->assertContains('public_id', $params['required']);
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->tool->isEnabled());
    }

    public function testExecuteReturnsErrorWhenPublicIdEmpty(): void
    {
        $result = $this->tool->execute(['public_id' => '']);

        $this->assertFalse($result['success']);
        $this->assertSame('public_id is required', $result['message']);
    }

    public function testExecuteReturnsErrorWhenSessionNotFound(): void
    {
        $this->mockSessionService
            ->expects($this->exactly(2))
            ->method('loadByPublicId')
            ->willReturn(null);

        $result = $this->tool->execute(['public_id' => 'non-existent-id']);

        $this->assertFalse($result['success']);
        $this->assertSame('Session not found or not accessible', $result['message']);
    }

    public function testExecuteReturnsPreviewInfoWhenSessionFound(): void
    {
        $mockSession = $this->createMock(AiSiteBuilderSession::class);
        $mockSession->method('getSelectedDomain')->willReturn('example.com');
        $mockSession->method('getPreviewUrl')->willReturn('https://example.com/preview');
        $mockSession->method('getWebsiteId')->willReturn(42);

        $this->mockSessionService
            ->method('loadByPublicId')
            ->willReturn($mockSession);

        $this->mockBridgeService
            ->method('buildLifecycleStatus')
            ->willReturn(['status' => 'completed', 'is_ready' => true, 'domain' => 'example.com']);

        $this->mockBridgeService
            ->method('isDomainReadyForBuild')
            ->willReturn(true);

        $result = $this->tool->execute(['public_id' => 'test-public-id']);

        $this->assertTrue($result['success']);
        $this->assertSame('test-public-id', $result['public_id']);
        $this->assertSame('https://example.com/preview', $result['preview_url']);
        $this->assertSame('example.com', $result['selected_domain']);
        $this->assertSame(42, $result['website_id']);
        $this->assertTrue($result['is_ready']);
    }

    public function testExecuteReturnsReadyWhenDomainCompleted(): void
    {
        $mockSession = $this->createMock(AiSiteBuilderSession::class);
        $mockSession->method('getSelectedDomain')->willReturn('ready.com');
        $mockSession->method('getPreviewUrl')->willReturn('');
        $mockSession->method('getWebsiteId')->willReturn(1);

        $this->mockSessionService
            ->method('loadByPublicId')
            ->willReturn($mockSession);

        $this->mockBridgeService
            ->method('buildLifecycleStatus')
            ->willReturn(['status' => 'completed', 'is_ready' => true, 'domain' => 'ready.com']);

        $this->mockBridgeService
            ->method('isDomainReadyForBuild')
            ->willReturn(true);

        $result = $this->tool->execute(['public_id' => 'test-id']);

        $this->assertTrue($result['success']);
        $this->assertSame('https://ready.com', $result['preview_url']);
        $this->assertTrue($result['is_ready']);
        $this->assertSame('confirm_materialization', $result['next_step']);
    }

    public function testExecuteReturnsNotReadyWhenDomainNotCompleted(): void
    {
        $mockSession = $this->createMock(AiSiteBuilderSession::class);
        $mockSession->method('getSelectedDomain')->willReturn('pending.com');
        $mockSession->method('getPreviewUrl')->willReturn('');
        $mockSession->method('getWebsiteId')->willReturn(0);

        $this->mockSessionService
            ->method('loadByPublicId')
            ->willReturn($mockSession);

        $this->mockBridgeService
            ->method('buildLifecycleStatus')
            ->willReturn(['status' => 'running', 'is_ready' => false, 'domain' => 'pending.com']);

        $this->mockBridgeService
            ->method('isDomainReadyForBuild')
            ->willReturn(false);

        $result = $this->tool->execute(['public_id' => 'test-id']);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_ready']);
        $this->assertSame('wait_for_domain_ready', $result['next_step']);
    }
}
