<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AI\Tool;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\AI\Tool\ConfirmDomainPurchaseTool;
use Weline\Websites\Service\WebsiteAgentService;

class ConfirmDomainPurchaseToolTest extends TestCase
{
    private ConfirmDomainPurchaseTool $tool;
    private WebsiteAgentService $mockAgentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockAgentService = $this->createMock(WebsiteAgentService::class);
        $this->tool = new ConfirmDomainPurchaseTool($this->mockAgentService);
    }

    public function testGetNameReturnsCorrectName(): void
    {
        $this->assertSame('confirm_domain_purchase', $this->tool->getName());
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
        $this->assertArrayHasKey('domain', $params['properties']);
        $this->assertArrayHasKey('account_id', $params['properties']);
        $this->assertArrayHasKey('confirmed', $params['properties']);
        $this->assertSame('boolean', $params['properties']['confirmed']['type']);
        $this->assertNotEmpty($params['properties']['confirmed']['description']);
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->tool->isEnabled());
    }

    public function testExecuteReturnsErrorWhenDomainEmpty(): void
    {
        $result = $this->tool->execute([
            'domain' => '',
            'account_id' => 1,
            'confirmed' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_DOMAIN', $result['error_code']);
    }

    public function testExecuteReturnsErrorWhenAccountIdInvalid(): void
    {
        $result = $this->tool->execute([
            'domain' => 'example.com',
            'account_id' => 0,
            'confirmed' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_ACCOUNT', $result['error_code']);
    }

    public function testExecuteReturnsConfirmationRequiredWhenNotConfirmed(): void
    {
        $this->mockAgentService->expects($this->never())->method('buildFromDescription');

        $result = $this->tool->execute([
            'domain' => 'example.com',
            'account_id' => 900001,
            'confirmed' => false,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('CONFIRMATION_REQUIRED', $result['error_code']);
        $this->assertTrue($result['requires_confirmation']);
        $this->assertSame('example.com', $result['domain']);
    }

    public function testExecuteReturnsUnsupportedTldError(): void
    {
        $result = $this->tool->execute([
            'domain' => 'example.xyz123',
            'account_id' => 900001,
            'confirmed' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('UNSUPPORTED_TLD', $result['error_code']);
    }

    public function testExecuteSucceedsWithValidConfirmedRequest(): void
    {
        $this->mockAgentService
            ->expects($this->once())
            ->method('buildFromDescription')
            ->with('example.com', 'example.com', 900001, null)
            ->willReturn([
                'success' => true,
                'order_id' => 42,
                'status' => 'completed',
            ]);

        $result = $this->tool->execute([
            'domain' => 'example.com',
            'account_id' => 900001,
            'confirmed' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['order_id']);
        $this->assertSame('completed', $result['purchase_status']);
        $this->assertSame('check_domain_status', $result['next_step']);
    }

    public function testExecuteSucceedsWithUseAiDescription(): void
    {
        $this->mockAgentService
            ->expects($this->once())
            ->method('buildFromDescription')
            ->with('Example', 'example.com', 900001, null)
            ->willReturn([
                'success' => true,
                'order_id' => 99,
                'status' => 'completed',
            ]);

        $result = $this->tool->execute([
            'domain' => 'example.com',
            'account_id' => 900001,
            'confirmed' => true,
            'use_ai_description' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(99, $result['order_id']);
    }

    public function testExecuteReturnsErrorOnServiceFailure(): void
    {
        $this->mockAgentService
            ->expects($this->once())
            ->method('buildFromDescription')
            ->willReturn([
                'success' => false,
                'message' => 'Service unavailable',
            ]);

        $result = $this->tool->execute([
            'domain' => 'example.com',
            'account_id' => 900001,
            'confirmed' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('PURCHASE_FAILED', $result['error_code']);
    }

    public function testExecuteHandlesServiceException(): void
    {
        $this->mockAgentService
            ->expects($this->once())
            ->method('buildFromDescription')
            ->willThrowException(new \Exception('Network error'));

        $result = $this->tool->execute([
            'domain' => 'example.com',
            'account_id' => 900001,
            'confirmed' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('EXCEPTION', $result['error_code']);
    }

    public function testSupportedTldsAreAccepted(): void
    {
        $supportedTlds = ['.com', '.net', '.org', '.io', '.co', '.info', '.biz', '.me', '.cc', '.tv'];

        foreach ($supportedTlds as $tld) {
            $result = $this->tool->execute([
                'domain' => 'test' . $tld,
                'account_id' => 900001,
                'confirmed' => true,
            ]);

            if (!$result['success']) {
                $this->assertNotSame('UNSUPPORTED_TLD', $result['error_code'] ?? '');
            }
        }
    }
}
