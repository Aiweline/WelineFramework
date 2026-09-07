<?php

declare(strict_types=1);

namespace Weline\Framework\View\test;

use ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

class ProcessFileSourceCompileDirTest extends TestCore
{
    private Request $originalRequest;

    protected function setUp(): void
    {
        parent::setUp();
        self::initRequest();
        Template::resetInstance();
        $this->originalRequest = ObjectManager::getInstance(Request::class);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance(Request::class, $this->originalRequest);
        Template::resetInstance();
        parent::tearDown();
    }

    public function testEmptyModulePathDoesNotResolveToRepoRootViewTpl(): void
    {
        ObjectManager::setInstance(Request::class, $this->createEmptyModulePathRequest());
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        [$fileName, $fileDir, $viewDir, $templateDir, $compileDir] = $template->processFileSource(
            'orphan.phtml',
            ''
        );

        $unscopedPrefix = Env::path_framework_generated_complicate
            . '_unscoped' . DIRECTORY_SEPARATOR . DataInterface::dir . DIRECTORY_SEPARATOR;
        $repoViewTpl = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, BP), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'tpl' . DIRECTORY_SEPARATOR;

        self::assertSame('orphan.phtml', $fileName);
        self::assertSame('', $fileDir);
        self::assertStringStartsWith($unscopedPrefix, $viewDir);
        self::assertStringStartsWith($unscopedPrefix, $templateDir);
        self::assertStringStartsWith($unscopedPrefix, $compileDir);
        self::assertStringContainsString(
            DataInterface::dir_type_TEMPLATE_COMPILE . DIRECTORY_SEPARATOR,
            $compileDir
        );
        self::assertStringStartsWith('/', str_replace('\\', '/', $compileDir));
        self::assertFalse(
            str_starts_with(
                rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $compileDir), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR,
                $repoViewTpl
            ),
            'compile_dir must not land under repository-root view/tpl'
        );
        self::assertNotSame('view' . DIRECTORY_SEPARATOR . 'tpl' . DIRECTORY_SEPARATOR, $compileDir);
    }

    public function testValidModulePathStillUsesModuleViewTpl(): void
    {
        $moduleBase = (string)(Env::getInstance()->getModuleList()['Weline_Framework']['base_path'] ?? '');
        self::assertNotSame('', $moduleBase);
        self::assertDirectoryExists($moduleBase);

        ObjectManager::setInstance(Request::class, $this->createModulePathRequest($moduleBase));
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        [, , $viewDir, , $compileDir] = $template->processFileSource('demo.phtml', '');

        $expectedView = rtrim($moduleBase, '/\\') . DIRECTORY_SEPARATOR . DataInterface::dir . DIRECTORY_SEPARATOR;
        self::assertSame($expectedView, $viewDir);
        if (PROD) {
            self::assertStringStartsWith(Env::path_framework_generated_complicate, $compileDir);
        } else {
            self::assertSame(
                $expectedView . DataInterface::view_TEMPLATE_COMPILE_DIR . DIRECTORY_SEPARATOR,
                $compileDir
            );
        }
    }

    public function testAbsoluteModuleViewTplMkdirIsAllowed(): void
    {
        $base = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_tpl_guard_' . \uniqid('', true);
        $viewDir = $base . DIRECTORY_SEPARATOR . DataInterface::dir . DIRECTORY_SEPARATOR;
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $method = new ReflectionMethod(Template::class, 'getModuleViewDir');
            $method->setAccessible(true);
            $result = $method->invoke(
                $template,
                $viewDir,
                DataInterface::dir_type_TEMPLATE_COMPILE,
                'Weline_Test'
            );

            $expectedTpl = $viewDir . DataInterface::view_TEMPLATE_COMPILE_DIR . DIRECTORY_SEPARATOR;
            self::assertSame($expectedTpl, $result);
            self::assertDirectoryExists($expectedTpl);
        } finally {
            $this->removeDirectoryTree($base);
        }
    }

    public function testRepoRootViewTplMkdirIsRejected(): void
    {
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        $method = new ReflectionMethod(Template::class, 'getModuleViewDir');
        $method->setAccessible(true);
        $repoView = \rtrim(\str_replace(['/', '\\'], DIRECTORY_SEPARATOR, BP), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . DataInterface::dir . DIRECTORY_SEPARATOR;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('拒绝在不安全路径创建模板目录');
        $method->invoke($template, $repoView, DataInterface::dir_type_TEMPLATE_COMPILE, 'Weline_Test');
    }

    private function removeDirectoryTree(string $path): void
    {
        if ($path === '' || !\is_dir($path)) {
            return;
        }
        $items = \scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (\is_dir($fullPath)) {
                $this->removeDirectoryTree($fullPath);
            } else {
                @\unlink($fullPath);
            }
        }
        @\rmdir($path);
    }

    private function createEmptyModulePathRequest(): Request
    {
        $urlBuilder = new class('http://localhost/dev/tool/index') extends Url {
            public function __construct(private readonly string $currentUrl)
            {
            }

            public function getCurrentUrl(array $params = [], bool $merge_url_params = true): string
            {
                return $this->currentUrl;
            }
        };

        return new class($urlBuilder) extends Request {
            public function __construct(private readonly Url $urlBuilder)
            {
            }

            public function getModulePath(): string
            {
                return '';
            }

            public function getModuleName(): string
            {
                return '';
            }

            public function getRouterData(string $key): mixed
            {
                return null;
            }

            public function getUrlBuilder(): Url
            {
                return $this->urlBuilder;
            }
        };
    }

    private function createModulePathRequest(string $modulePath): Request
    {
        $urlBuilder = new class('http://localhost/dev/tool/index') extends Url {
            public function __construct(private readonly string $currentUrl)
            {
            }

            public function getCurrentUrl(array $params = [], bool $merge_url_params = true): string
            {
                return $this->currentUrl;
            }
        };

        return new class($modulePath, $urlBuilder) extends Request {
            public function __construct(
                private readonly string $modulePath,
                private readonly Url $urlBuilder
            ) {
            }

            public function getModulePath(): string
            {
                return rtrim($this->modulePath, '/\\') . DIRECTORY_SEPARATOR;
            }

            public function getModuleName(): string
            {
                return 'Weline_Framework';
            }

            public function getRouterData(string $key): mixed
            {
                return $key === 'module_path' ? $this->modulePath : null;
            }

            public function getUrlBuilder(): Url
            {
                return $this->urlBuilder;
            }
        };
    }
}
