<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\View\Template;
use Weline\Theme\Observer\ControllerFetchFileAfter;
use Weline\Theme\Service\PreparedContentStore;

class ControllerFetchFileAfterTest extends TestCase
{
    protected function tearDown(): void
    {
        PreparedContentStore::resetRequestState();
    }

    public function testUsesPrefetchedContentAndRendersLayoutOnlyOnce(): void
    {
        $template = new ControllerFetchFileAfterTestTemplateStub();
        $template->setFetchResponse('theme/frontend/layouts/default/default.phtml', '<html>wrapped</html>');

        $observer = new class($template) extends ControllerFetchFileAfter {
            public function __construct(private readonly Template $template)
            {
            }

            protected function getTemplateInstance(): Template
            {
                return $this->template;
            }
        };

        $eventData = new DataObject([
            'layoutType' => 'default',
            'contentTemplate' => 'templates/frontend/home/index.phtml',
            'layoutTemplate' => 'theme/frontend/layouts/default/default.phtml',
            'fileName' => 'templates/frontend/home/index.phtml',
            'content' => '<section>prefetched</section>',
        ]);
        $event = new Event(['data' => $eventData]);
        $event->setName('test');

        $observer->execute($event);

        $this->assertSame(
            [
                ['theme/frontend/layouts/default/default.phtml', ['content' => '<section>prefetched</section>']],
            ],
            $template->fetchCalls
        );
        $this->assertSame('<html>wrapped</html>', $eventData->getData('content'));
        $this->assertSame('theme/frontend/layouts/default/default.phtml', $eventData->getData('fileName'));

        $meta = $template->getData('meta');
        $this->assertIsArray($meta);
        $this->assertSame('<section>prefetched</section>', $meta['content'] ?? null);
        $this->assertSame('templates/frontend/home/index.phtml', $meta['contentTemplate'] ?? null);
    }

    public function testBackendLayoutUsesPreparedContentKeyInsteadOfEmbeddingContentHtml(): void
    {
        $template = new ControllerFetchFileAfterTestTemplateStub();
        $template->setFetchResponse('theme/backend/layouts/default/default.phtml', '<html>wrapped backend</html>');

        $observer = new class($template) extends ControllerFetchFileAfter {
            public function __construct(private readonly Template $template)
            {
            }

            protected function getTemplateInstance(): Template
            {
                return $this->template;
            }
        };

        $eventData = new DataObject([
            'layoutType' => 'default',
            'contentTemplate' => 'templates/backend/page/index.phtml',
            'layoutTemplate' => 'theme/backend/layouts/default/default.phtml',
            'fileName' => 'templates/backend/page/index.phtml',
            'content' => '<section>backend prefetched</section>',
        ]);
        $event = new Event(['data' => $eventData]);
        $event->setName('test');

        $observer->execute($event);

        $this->assertCount(1, $template->fetchCalls);
        $this->assertSame('theme/backend/layouts/default/default.phtml', $template->fetchCalls[0][0]);
        $this->assertArrayHasKey('contentRenderKey', $template->fetchCalls[0][1]);
        $this->assertArrayNotHasKey('content', $template->fetchCalls[0][1]);
        $this->assertSame(
            '<section>backend prefetched</section>',
            PreparedContentStore::get($template->fetchCalls[0][1]['contentRenderKey'])
        );

        $meta = $template->getData('meta');
        $this->assertIsArray($meta);
        $this->assertSame($template->fetchCalls[0][1]['contentRenderKey'], $meta['contentRenderKey'] ?? null);
        $this->assertArrayNotHasKey('content', $meta);
        $this->assertSame('templates/backend/page/index.phtml', $meta['contentTemplate'] ?? null);
        $this->assertNull($template->getData('content'));
        $this->assertSame($template->fetchCalls[0][1]['contentRenderKey'], $template->getData('contentRenderKey'));
        $this->assertSame('<html>wrapped backend</html>', $eventData->getData('content'));
    }
}

class ControllerFetchFileAfterTestTemplateStub extends Template
{
    /** @var array<int,array{0:string,1:array}> */
    public array $fetchCalls = [];

    /** @var array<string,string> */
    private array $fetchResponses = [];

    public function setFetchResponse(string $fileName, string $response): void
    {
        $this->fetchResponses[$fileName] = $response;
    }

    public function fetch(string $fileName, array $data = [])
    {
        $this->fetchCalls[] = [$fileName, $data];

        return $this->fetchResponses[$fileName] ?? '';
    }
}
