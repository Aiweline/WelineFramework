<?php

declare(strict_types=1);

namespace Weline\Framework\View\test;

use Weline\Framework\Hook\HookRenderResult;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

final class HookRenderResultRuntimeTest extends TestCore
{
    protected function tearDown(): void
    {
        RequestContext::remove('view.hook.decorate_output');
        RequestContext::remove('view.hook.handled_empty.header-language-switcher');
        parent::tearDown();
    }

    public function testStructuredEmptySemanticsAreRenderScoped(): void
    {
        $template = new class extends Template {
            public string $mode = 'handled';
            public bool $semanticRender = false;

            public function __construct()
            {
            }

            public function getHook(string $name, bool $forceRefresh = false): string
            {
                $this->semanticRender = RequestContext::get('view.hook.decorate_output') === false
                    && $forceRefresh;

                return match ($this->mode) {
                    'handled' => $this->handled($name),
                    'marker' => '<!--weline:hook:handled_empty-->',
                    'html' => '<span>rendered</span>',
                    default => '',
                };
            }

            private function handled(string $name): string
            {
                $this->markHookHandledEmpty($name);
                return '';
            }
        };

        RequestContext::set('view.hook.decorate_output', true);
        $handled = $template->getHookResult(' header-language-switcher ', false, true);
        self::assertTrue($template->semanticRender);
        self::assertTrue($handled->handledEmpty);
        self::assertFalse($handled->shouldUseFallback());
        self::assertTrue(RequestContext::get('view.hook.decorate_output'));
        self::assertFalse(RequestContext::has('view.hook.handled_empty.header-language-switcher'));

        $template->mode = 'empty';
        self::assertTrue(
            $template->getHookResult('header-language-switcher', false, true)->shouldUseFallback(),
        );

        $template->mode = 'marker';
        self::assertTrue(
            $template->getHookResult('header-language-switcher', false, true)->handledEmpty,
        );

        $template->mode = 'html';
        self::assertSame(
            '<span>rendered</span>',
            $template->getHookResult('header-language-switcher', false, true)->html,
        );
    }

    public function testEmptyNameDoesNotDispatch(): void
    {
        $template = new class extends Template {
            public bool $called = false;

            public function __construct()
            {
            }

            public function getHook(string $name, bool $forceRefresh = false): string
            {
                $this->called = true;
                return 'unexpected';
            }
        };

        $result = $template->getHookResult(' ', false, true);
        self::assertTrue($result->shouldUseFallback());
        self::assertSame(0, $result->fileCount);
        self::assertFalse($template->called);
    }

    public function testTaglibElseCompilesToStandardPhpAndRunsAllBranches(): void
    {
        $source = '<w:hook>header-language-switcher<else/><button>Fallback</button></w:hook>';
        $compiled = ObjectManager::getInstance(Taglib::class)->tagReplace(
            ObjectManager::getInstance(Template::class),
            $source,
        );

        self::assertStringContainsString(
            "getHookResult('header-language-switcher', false, true)",
            $compiled,
        );
        self::assertStringContainsString('<?php $__w_hr=', $compiled);
        self::assertStringContainsString(
            '<button>Fallback</button>',
            (new HookRuntimeProbe(new HookRenderResult(
                html: '',
                useFallback: true,
                fileCount: 1,
            )))->render($compiled),
        );

        $custom = (new HookRuntimeProbe(new HookRenderResult(
            html: '<span>Custom</span>',
            fileCount: 1,
        )))->render($compiled);
        self::assertStringContainsString('<span>Custom</span>', $custom);
        self::assertStringNotContainsString('<button>Fallback</button>', $custom);

        $handled = (new HookRuntimeProbe(new HookRenderResult(
            html: '',
            handledEmpty: true,
            useFallback: true,
            fileCount: 1,
        )))->render($compiled);
        self::assertStringNotContainsString('<button>Fallback</button>', $handled);
    }
}

final class HookRuntimeProbe
{
    public function __construct(
        private readonly HookRenderResult $result,
    ) {
    }

    public function getHookResult(
        string $name,
        bool $forceRefresh,
        bool $preferFallbackOnEmpty,
    ): HookRenderResult {
        return $this->result;
    }

    public function render(string $compiled): string
    {
        ob_start();
        try {
            eval('?>' . $compiled);
            return (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
