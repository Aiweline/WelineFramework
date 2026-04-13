<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Controller\PcController;
use Weline\Framework\Runtime\RequestContext;

final class PcControllerTemplateRetryTest extends TestCase
{
    private ?Context $previousContext = null;

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
        parent::tearDown();
    }

    public function testFetchTemplateWithEventsRetriesOnceWhenFirstRenderIsEmptyInRequestRuntime(): void
    {
        $this->enterRequestContext('request-template-retry');

        $controller = new class(['', '<html>ok</html>']) extends PcController {
            /** @var list<string> */
            public array $calls = [];

            /** @var list<array<string, mixed>> */
            public array $resetPayloads = [];

            /** @param list<mixed> $responses */
            public function __construct(private array $responses)
            {
            }

            public function render(string $fileName): mixed
            {
                return $this->fetchTemplateWithEvents($fileName);
            }

            protected function performTemplateFetchWithEvents(string $fileName): mixed
            {
                $this->calls[] = $fileName;
                return array_shift($this->responses);
            }

            protected function snapshotTemplateDataForEmptyRetry(): array
            {
                return ['activeTab' => 'statistics'];
            }

            protected function resetTemplateAfterEmptyRetry(array $templateData): void
            {
                $this->resetPayloads[] = $templateData;
            }
        };

        $result = $controller->render('templates/Backend/Statistics/index');

        self::assertSame('<html>ok</html>', $result);
        self::assertSame(
            ['templates/Backend/Statistics/index', 'templates/Backend/Statistics/index'],
            $controller->calls
        );
        self::assertSame([['activeTab' => 'statistics']], $controller->resetPayloads);
    }

    public function testFetchTemplateWithEventsDoesNotRetryOutsideRequestRuntime(): void
    {
        $controller = new class(['']) extends PcController {
            /** @var list<string> */
            public array $calls = [];

            /** @var list<array<string, mixed>> */
            public array $resetPayloads = [];

            /** @param list<mixed> $responses */
            public function __construct(private array $responses)
            {
            }

            public function render(string $fileName): mixed
            {
                return $this->fetchTemplateWithEvents($fileName);
            }

            protected function performTemplateFetchWithEvents(string $fileName): mixed
            {
                $this->calls[] = $fileName;
                return array_shift($this->responses);
            }

            protected function resetTemplateAfterEmptyRetry(array $templateData): void
            {
                $this->resetPayloads[] = $templateData;
            }
        };

        $result = $controller->render('templates/Backend/Statistics/index');

        self::assertSame('', $result);
        self::assertSame(['templates/Backend/Statistics/index'], $controller->calls);
        self::assertSame([], $controller->resetPayloads);
    }

    private function enterRequestContext(string $requestId): void
    {
        $this->previousContext = Context::getCurrent();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId($requestId);
    }
}
