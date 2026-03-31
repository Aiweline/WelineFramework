<?php

declare(strict_types=1);

namespace WeShop\Compliance\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Compliance\Controller\Backend\Compliance;
use WeShop\Compliance\Service\CompliancePageDataService;
use WeShop\Compliance\Service\ConsentService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

class ComplianceTest extends TestCase
{
    public function testIndexAssignsDashboardDataAndReturnsTemplate(): void
    {
        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->once())
            ->method('getSupportedConsentTypes')
            ->willReturn(['cookie', 'privacy', 'terms', 'marketing']);

        $controller = $this->buildController(
            compliancePageDataService: $this->createMock(CompliancePageDataService::class),
            consentService: $consentService,
            requestParams: []
        );

        $controller->expects($this->once())->method('fetch')->willReturn('page_html');

        $result = $controller->index();

        $this->assertSame('page_html', $result);
    }

    public function testPolicyWithInvalidTypeAddsErrorAndRedirects(): void
    {
        $controller = $this->buildController(
            compliancePageDataService: $this->createMock(CompliancePageDataService::class),
            consentService: $this->createMock(ConsentService::class),
            requestParams: ['type' => 'invalid_type']
        );
        $messageManager = $this->createMock(MessageManager::class);

        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->isType('string'))
            ->willReturnSelf();
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->willReturn('');
        $controller->expects($this->never())->method('fetch');

        $this->assertSame('', $controller->policy());
    }

    public function testPolicyWithPrivacyTypeReturnsTemplate(): void
    {
        $pageData = ['sections' => [['title' => 'Data Collection', 'body' => '...']]];
        $pageDataService = $this->createMock(CompliancePageDataService::class);
        $pageDataService->expects($this->once())
            ->method('buildPrivacyPage')
            ->willReturn($pageData);

        $controller = $this->buildController(
            compliancePageDataService: $pageDataService,
            consentService: $this->createMock(ConsentService::class),
            requestParams: ['type' => 'privacy']
        );
        $controller->expects($this->once())->method('fetch')->willReturn('policy_page_html');

        $this->assertSame('policy_page_html', $controller->policy());
    }

    public function testSavePolicyWithInvalidTypeAddsErrorAndRedirects(): void
    {
        $controller = $this->buildController(
            compliancePageDataService: $this->createMock(CompliancePageDataService::class),
            consentService: $this->createMock(ConsentService::class),
            requestParams: ['type' => '', 'content' => 'x'],
            isPost: true
        );
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->isType('string'))
            ->willReturnSelf();

        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->willReturn('');

        $this->assertSame('', $controller->savePolicy());
    }

    public function testSavePolicyWithValidDataAddsSuccessAndRedirects(): void
    {
        $controller = $this->buildController(
            compliancePageDataService: $this->createMock(CompliancePageDataService::class),
            consentService: $this->createMock(ConsentService::class),
            requestParams: ['type' => 'privacy', 'content' => 'ok'],
            isPost: true
        );
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->stringContains('saved successfully'))
            ->willReturnSelf();

        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->willReturn('');

        $this->assertSame('', $controller->savePolicy());
    }

    public function testExportWithUnsupportedFormatAddsErrorAndRedirects(): void
    {
        $controller = $this->buildController(
            compliancePageDataService: $this->createMock(CompliancePageDataService::class),
            consentService: $this->createMock(ConsentService::class),
            requestParams: ['format' => 'pdf'],
            isPost: true
        );
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->isType('string'))
            ->willReturnSelf();

        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->willReturn('');

        $this->assertSame('', $controller->export());
    }

    public function testExportWithJsonFormatAddsSuccessAndRedirects(): void
    {
        $controller = $this->buildController(
            compliancePageDataService: $this->createMock(CompliancePageDataService::class),
            consentService: $this->createMock(ConsentService::class),
            requestParams: ['format' => 'json'],
            isGet: true
        );
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->isType('string'))
            ->willReturnSelf();

        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->willReturn('');

        $this->assertSame('', $controller->export());
    }

    /**
     * @param array<string, scalar> $requestParams
     */
    private function buildController(
        CompliancePageDataService $compliancePageDataService,
        ConsentService $consentService,
        array $requestParams,
        bool $isPost = false,
        bool $isGet = false
    ): Compliance {
        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = '') => $requestParams[$key] ?? $default);
        $request->method('isPost')->willReturn($isPost);
        $request->method('isGet')->willReturn($isGet);

        $url = $this->createMock(Url::class);
        $url->method('getBackendUrl')->willReturn('/admin/mock');

        $controller = $this->getMockBuilder(Compliance::class)
            ->setConstructorArgs([$compliancePageDataService, $consentService])
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('assign')->willReturnSelf();

        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, '_url', $url);
        return $controller;
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property)) {
            $reflection = $reflection->getParentClass();
            if (!$reflection) {
                throw new \RuntimeException("Property {$property} not found.");
            }
        }

        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}
