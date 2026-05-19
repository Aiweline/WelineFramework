<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\View\Template;

final class PcControllerEmptyRenderRetryTest extends TestCase
{
    protected function tearDown(): void
    {
        FiberOutputBuffer::uninstall();
        Runtime::resetModeCache();
    }

    public function testRetriesWhenAfterEventClearsNonEmptyTemplateOutput(): void
    {
        $template = new PcControllerEmptyRenderRetryTemplateStub([
            '<section>first</section>',
            '<section>retry</section>',
        ]);
        $events = new PcControllerEmptyRenderRetryEventsStub([
            '',
            '<html>retry layout</html>',
        ]);

        $controller = new PcControllerEmptyRenderRetryControllerHarness($template, $events);

        self::assertSame('<html>retry layout</html>', $controller->renderWithEvents('demo/template.phtml'));
        self::assertSame(2, $template->calls);
        self::assertSame(2, $events->afterCalls);
    }

    public function testFallsBackToRetryContentWhenAfterEventStaysEmpty(): void
    {
        $template = new PcControllerEmptyRenderRetryTemplateStub([
            '<section>first</section>',
            '<section>retry content</section>',
        ]);
        $events = new PcControllerEmptyRenderRetryEventsStub([
            '',
            '',
        ]);

        $controller = new PcControllerEmptyRenderRetryControllerHarness($template, $events);

        self::assertSame('<section>retry content</section>', $controller->renderWithEvents('demo/template.phtml'));
        self::assertSame(2, $template->calls);
        self::assertSame(2, $events->afterCalls);
    }

    public function testDirectTemplateRetriesEmptyPersistentRender(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        $template = new PcControllerEmptyRenderRetryTemplateStub([
            '',
            '<main>retry direct</main>',
        ]);
        $events = new PcControllerEmptyRenderRetryEventsStub([]);

        $controller = new PcControllerEmptyRenderRetryControllerHarness($template, $events);

        self::assertSame('<main>retry direct</main>', $controller->renderDirectTemplate('demo/direct.phtml'));
        self::assertSame(2, $template->calls);
    }
}

final class PcControllerEmptyRenderRetryControllerHarness extends PcController
{
    public function __construct(
        private readonly Template $templateStub,
        private readonly EventsManager $eventsStub
    ) {
    }

    public function renderWithEvents(string $fileName): mixed
    {
        return $this->fetchTemplateWithEvents($fileName);
    }

    public function renderDirectTemplate(string $fileName): string
    {
        return $this->template($fileName);
    }

    protected function getTemplate(): Template
    {
        return $this->templateStub;
    }

    protected function getEventManager(): EventsManager
    {
        return $this->eventsStub;
    }
}

final class PcControllerEmptyRenderRetryTemplateStub extends Template
{
    public int $calls = 0;

    /**
     * @param list<string> $responses
     */
    public function __construct(private readonly array $responses)
    {
    }

    public function fetch(string $fileName, array $data = [])
    {
        $response = $this->responses[$this->calls] ?? '';
        $this->calls++;
        return $response;
    }

    public function fetchHtml(string $fileName, array $dictionary = [])
    {
        return $this->fetch($fileName, $dictionary);
    }
}

final class PcControllerEmptyRenderRetryEventsStub extends EventsManager
{
    public int $afterCalls = 0;

    /**
     * @param list<string> $afterResponses
     */
    public function __construct(private readonly array $afterResponses)
    {
    }

    public function dispatch(string $eventName, mixed &$data = []): static
    {
        if ($eventName !== 'Weline_Framework_Controller::fetch_file_after' || !$data instanceof DataObject) {
            return $this;
        }

        $data->setData('content', $this->afterResponses[$this->afterCalls] ?? '');
        $this->afterCalls++;
        return $this;
    }
}
