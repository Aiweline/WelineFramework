<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore;

final class ThemeLayoutEntityBindingBehaviorTest extends TestCase
{
    private string $identity;
    private ThemeLayoutEntityPaths $paths;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 3);
        require_once $base . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
        require_once dirname($base) . '/Framework/Compilation/AtomicCompiledFilePublisher.php';
        foreach (['EntityRenderBinding', 'ThemeLayoutEntityBindingStore'] as $name) {
            if (is_file($base . '/Service/LayoutEntity/' . $name . '.php')) {
                require_once $base . '/Service/LayoutEntity/' . $name . '.php';
            }
        }
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir() . '/weline-binding-tests-' . getmypid());
        }
        $this->paths = new ThemeLayoutEntityPaths();
        $this->identity = 'test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ([$this->paths->pageIdentityDir(901, 'test', $this->identity), $this->paths->themeScopeDir(901, $this->identity)] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    public function testConfigurationSwitchKeepsOldBindingReadableAndDoesNotRewriteTemplate(): void
    {
        self::assertTrue(class_exists(ThemeLayoutEntityBindingStore::class), 'Entity binding store is required');
        $store = new ThemeLayoutEntityBindingStore($this->paths);
        $structure = 's' . str_repeat('a', 64);
        $template = $this->paths->pagePhtml(901, 'test', $this->identity, $structure);
        mkdir(dirname($template), 0775, true);
        file_put_contents($template, 'same layout');
        file_put_contents(dirname($template) . '/structure.json', '{}');
        touch($template, 1234567890);
        $old = $store->publishPageBinding(901, 'test', $this->identity, 'd1', $structure, ['uid' => ['config' => ['title' => 'old']]], []);
        $new = $store->publishPageBinding(901, 'test', $this->identity, 'd1', $structure, ['uid' => ['config' => ['title' => 'new']]], []);
        $release = $store->publishPageBinding(901, 'test', $this->identity, 'r2', $structure, ['uid' => ['config' => ['title' => 'new']]], []);
        self::assertSame('old', json_decode(file_get_contents($old->configPath), true)['uid']['config']['title']);
        self::assertSame('new', json_decode(file_get_contents($store->readPageBinding(901, 'test', $this->identity, 'd1')->configPath), true)['uid']['config']['title']);
        self::assertSame($old->templatePath, $new->templatePath);
        self::assertSame($new->configPath, $release->configPath);
        self::assertNotSame($old->cacheKey(), $new->cacheKey());
        clearstatcache(true, $template);
        self::assertSame(1234567890, filemtime($template));
        self::assertNull($store->readPageBinding(901, 'test', $this->identity, 'r999'));
    }

    public function testChromeVersionsShareStructureButKeepConfigurationSnapshots(): void
    {
        $store = new ThemeLayoutEntityBindingStore($this->paths);
        $scope = $this->identity;
        $structure = 's' . str_repeat('c', 64);
        $dir = $this->paths->chromeStructureDir(901, $scope, $structure);
        mkdir($dir, 0775, true);
        file_put_contents($dir . 'chrome.phtml', 'chrome');
        file_put_contents($dir . 'structure.json', '{}');
        $old = $store->publishChromeBinding(901, $scope, 1, $structure, ['title' => 'old'], []);
        $new = $store->publishChromeBinding(901, $scope, 2, $structure, ['title' => 'new'], []);
        self::assertSame($old->templatePath, $new->templatePath);
        self::assertSame('old', json_decode(file_get_contents($store->readChromeBinding(901, $scope, 1)->configPath), true)['title']);
        self::assertSame('new', json_decode(file_get_contents($store->readChromeBinding(901, $scope, 2)->configPath), true)['title']);
    }

    public function testMissingStructureCannotReplaceCompleteBinding(): void
    {
        self::assertTrue(class_exists(ThemeLayoutEntityBindingStore::class), 'Entity binding store is required');
        $store = new ThemeLayoutEntityBindingStore($this->paths);
        $this->expectException(\RuntimeException::class);
        $store->publishPageBinding(901, 'test', $this->identity, 'r1', 's' . str_repeat('b', 64), [], []);
    }
}
