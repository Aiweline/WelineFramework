<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\QA\Controller\Backend\QA;
use WeShop\QA\Model\Question;
use WeShop\QA\Service\QAService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

final class QATest extends TestCase
{
    private QAService $qaService;

    protected function setUp(): void
    {
        $this->qaService = $this->createMock(QAService::class);
    }

    public function testIndexReturnsQAManagementPage(): void
    {
        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(5))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_QA::templates/Backend/QA/Index/index.phtml')
            ->willReturn('qa-index-page');

        $this->setControllerUrl($controller);

        self::assertSame('qa-index-page', $controller->index());
        self::assertSame('Q&A Management', $assignments['page_title'] ?? null);
        self::assertSame('/backend/backend/qa/index', $assignments['list_url'] ?? null);
        self::assertSame('/backend/backend/qa/view', $assignments['view_url'] ?? null);
        self::assertSame('/backend/backend/qa/approve', $assignments['approve_url'] ?? null);
        self::assertSame('/backend/backend/qa/reject', $assignments['reject_url'] ?? null);
    }

    public function testViewWithInvalidIdAddsErrorMessage(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid question ID.');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 0]));

        self::assertSame('', $controller->view());
    }

    public function testViewWithNonExistentQuestionAddsErrorMessage(): void
    {
        $this->qaService->expects($this->once())
            ->method('getQuestion')
            ->with(999)
            ->willReturn(null);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Question not found.');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 999]));

        self::assertSame('', $controller->view());
    }

    public function testViewWithValidQuestionReturnsViewPage(): void
    {
        $questionData = [
            'question_id' => 5,
            'product_id' => 100,
            'customer_id' => 200,
            'question' => 'Is this product waterproof?',
            'answer' => 'Yes, it is waterproof.',
            'status' => 'pending',
            'created_at' => '2026-03-29 10:00:00',
        ];

        $question = $this->createMock(Question::class);
        $question->expects($this->once())
            ->method('getData')
            ->willReturn($questionData);

        $this->qaService->expects($this->once())
            ->method('getQuestion')
            ->with(5)
            ->willReturn($question);
        $this->qaService->expects($this->once())
            ->method('getSourceTypeOptions')
            ->willReturn(['customer' => '客户问答', 'system' => '系统推荐']);

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(8))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_QA::templates/Backend/QA/View/index.phtml')
            ->willReturn('qa-view-page');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 5]));
        $this->setControllerUrl($controller);

        self::assertSame('qa-view-page', $controller->view());
        self::assertSame('Q&A Detail', $assignments['page_title'] ?? null);
        self::assertSame($questionData, $assignments['question'] ?? null);
        self::assertSame(5, $assignments['question_id'] ?? null);
        self::assertSame('/backend/backend/qa/approve', $assignments['approve_url'] ?? null);
        self::assertSame('/backend/backend/qa/reject', $assignments['reject_url'] ?? null);
        self::assertSame('/backend/backend/qa', $assignments['back_url'] ?? null);
        self::assertSame('/backend/backend/qa/metadata', $assignments['metadata_url'] ?? null);
        self::assertSame(['customer' => '客户问答', 'system' => '系统推荐'], $assignments['source_type_options'] ?? null);
    }

    public function testApproveWithInvalidRequestMethodAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError')->with('Invalid request method.');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock([], false));

        self::assertSame('', $controller->approve());
    }

    public function testApproveWithInvalidQuestionIdAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('Invalid question ID'));

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 0], true));

        self::assertSame('', $controller->approve());
    }

    public function testApproveSuccessfullyApprovesQuestion(): void
    {
        $this->qaService->expects($this->once())
            ->method('approveQuestion')
            ->with(5, 'This is the answer.')
            ->willReturn(true);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with('Question approved successfully.');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 5,
            'answer' => 'This is the answer.',
        ], true));

        self::assertSame('', $controller->approve());
    }

    public function testApproveHandlesServiceException(): void
    {
        $this->qaService->expects($this->once())
            ->method('approveQuestion')
            ->willThrowException(new \RuntimeException('Database error'));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('Database error'));

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 5], true));

        self::assertSame('', $controller->approve());
    }

    public function testRejectWithInvalidRequestMethodAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError')->with('Invalid request method.');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock([], false));

        self::assertSame('', $controller->reject());
    }

    public function testRejectWithInvalidQuestionIdAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('Invalid question ID'));

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 0], true));

        self::assertSame('', $controller->reject());
    }

    public function testRejectSuccessfullyRejectsQuestion(): void
    {
        $this->qaService->expects($this->once())
            ->method('rejectQuestion')
            ->with(5)
            ->willReturn(true);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with('Question rejected successfully.');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 5], true));

        self::assertSame('', $controller->reject());
    }

    public function testRejectHandlesServiceException(): void
    {
        $this->qaService->expects($this->once())
            ->method('rejectQuestion')
            ->willThrowException(new \RuntimeException('Service unavailable'));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('Service unavailable'));

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/qa');

        $this->setControllerRequest($controller, $this->createRequestMock(['id' => 5], true));

        self::assertSame('', $controller->reject());
    }

    public function testMetadataSuccessfullyUpdatesQuestionSettings(): void
    {
        $this->qaService->expects($this->once())
            ->method('updateQuestionMetadata')
            ->with(5, [
                'source_type' => 'system',
                'is_recommended' => '1',
                'display_name' => '系统推荐',
            ])
            ->willReturn(true);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with('问答元数据已保存。');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/backend/qa/view', ['id' => 5]);

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 5,
            'source_type' => 'system',
            'is_recommended' => '1',
            'display_name' => '系统推荐',
        ], true));

        self::assertSame('', $controller->metadata());
    }

    private function createRequestMock(array $params = [], bool $isPost = true): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam', 'isPost'])
            ->getMock();
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
        $request->method('isPost')->willReturn($isPost);

        return $request;
    }

    private function setControllerRequest(object $controller, Request $request): void
    {
        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('request') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate request property.');
        }

        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);
    }

    private function setControllerUrl(object $controller): void
    {
        $url = $this->getMockBuilder(Url::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBackendUrl'])
            ->getMock();
        $url->method('getBackendUrl')
            ->willReturnCallback(static fn (string $path): string => '/backend/' . ltrim($path, '*/'));

        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('_url') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate _url property.');
        }

        $property = $reflection->getProperty('_url');
        $property->setAccessible(true);
        $property->setValue($controller, $url);
    }
}
