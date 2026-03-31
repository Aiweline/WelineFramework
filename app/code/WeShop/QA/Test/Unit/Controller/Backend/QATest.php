<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\QA\Controller\Backend\QA;
use WeShop\QA\Service\QAService;
use WShop\QA\Model\Question;
use Weline\Framework\Http\Request\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Message\Manager as MessageManager;

class QATest extends TestCase
{
    private QAService $qaService;
    private QA $controller;

    protected function setUp(): void
    {
        $this->qaService = $this->createMock(QAService::class);
        $this->controller = new QA($this->qaService);
    }

    public function testIndexReturnsQAManagementPage(): void
    {
        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'fetchBase'])
            ->getMock();

        $controller->expects($this->exactly(5))->method('assign')->willReturnCallback(
            function (string $key, mixed $value) use (&$assignments) {
                $assignments[$key] = $value;
                return $this;
            }
        );
        $controller->expects($this->once())->method('fetch')->willReturn('page_html');

        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());
        $this->setProtectedProperty($controller, '_url', new class {
            public function getBackendUrl(string $path): string
            {
                return '/backend/' . ltrim($path, '*/');
            }
        });

        $result = $controller->index();

        $this->assertSame('page_html', $result);
        $this->assertArrayHasKey('page_title', $assignments);
        $this->assertArrayHasKey('list_url', $assignments);
        $this->assertArrayHasKey('view_url', $assignments);
        $this->assertArrayHasKey('approve_url', $assignments);
        $this->assertArrayHasKey('reject_url', $assignments);
    }

    public function testViewWithInvalidIdAddsErrorMessage(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->isType('string'));

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'redirect', 'fetchBase'])
            ->getMock();

        $controller->expects($this->never())->method('fetch');

        $this->setProtectedProperty($controller, '_request', new class {
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 0,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);
        $this->setProtectedProperty($controller, '_url', new class {
            public function getBackendUrl(string $path): string
            {
                return '/backend/' . ltrim($path, '*/');
            }
        });

        $controller->expects($this->once())->method('redirect');

        $controller->view();
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
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'redirect', 'fetchBase'])
            ->getMock();

        $controller->expects($this->never())->method('fetch');

        $this->setProtectedProperty($controller, '_request', new class {
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 999,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);
        $this->setProtectedProperty($controller, '_url', new class {
            public function getBackendUrl(string $path): string
            {
                return '/backend/' . ltrim($path, '*/');
            }
        });

        $controller->expects($this->once())->method('redirect');

        $controller->view();
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

        $questionMock = $this->createMock(Question::class);
        $questionMock->expects($this->once())
            ->method('getData')
            ->willReturn($questionData);

        $this->qaService->expects($this->once())
            ->method('getQuestion')
            ->with(5)
            ->willReturn($questionMock);

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'redirect', 'fetchBase'])
            ->getMock();

        $controller->expects($this->exactly(6))->method('assign')->willReturnCallback(
            function (string $key, mixed $value) use (&$assignments) {
                $assignments[$key] = $value;
                return $this;
            }
        );
        $controller->expects($this->once())->method('fetch')->willReturn('view_page_html');

        $this->setProtectedProperty($controller, '_request', new class {
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_url', new class {
            public function getBackendUrl(string $path): string
            {
                return '/backend/' . ltrim($path, '*/');
            }
        });

        $result = $controller->view();

        $this->assertSame('view_page_html', $result);
        $this->assertSame(5, $assignments['question_id']);
        $this->assertSame($questionData, $assignments['question']);
        $this->assertSame('Q&A Detail', $assignments['page_title']);
    }

    public function testApproveWithInvalidRequestMethodAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid request method.');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return false; }
            public function getParam(string $key, mixed $default = null): mixed { return $default; }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->approve();
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

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 0,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->approve();
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

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    'answer' => 'This is the answer.',
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->approve();
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

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->approve();
    }

    public function testRejectWithInvalidRequestMethodAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid request method.');

        $controller = $this->getMockBuilder(QA::class)
            ->setConstructorArgs([$this->qaService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return false; }
            public function getParam(string $key, mixed $default = null): mixed { return $default; }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->reject();
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

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 0,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->reject();
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

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->reject();
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

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->reject();
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
