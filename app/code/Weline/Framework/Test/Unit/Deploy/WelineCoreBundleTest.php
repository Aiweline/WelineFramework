<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\WelineCoreBundle;

final class WelineCoreBundleTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-core-bundle-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . '/Weline/Frontend/js', 0775, true);
        \mkdir($this->root . '/Weline/Framework/js', 0775, true);
        \file_put_contents($this->root . '/Weline/Frontend/js/runtime.js', 'window.RT=1;');
        \file_put_contents($this->root . '/Weline/Framework/js/dom-observer.js', 'window.DOM=1;');
        \file_put_contents($this->root . '/Weline/Frontend/js/weline-api-business.js', 'window.API=1;');
        \file_put_contents($this->root . '/Weline/Framework/js/url-frontend.js', 'window.URLU=1;');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                $item->isDir() && !$item->isLink() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
            }
            @\rmdir($this->root);
        }
    }

    public function testPublishesOrderedBundleAndIsIdempotent(): void
    {
        $first = WelineCoreBundle::publish($this->root);
        self::assertSame(WelineCoreBundle::RELATIVE_PATH, $first['path']);
        self::assertTrue($first['changed']);

        $bundle = (string)\file_get_contents($this->root . '/' . WelineCoreBundle::RELATIVE_PATH);
        $positions = [];
        foreach (WelineCoreBundle::SOURCES as $source) {
            $marker = '/* ==== ' . $source . ' ==== */';
            self::assertStringContainsString($marker, $bundle);
            $positions[] = \strpos($bundle, $marker);
        }
        self::assertSame($positions, \array_values(\array_unique($positions)));
        $sorted = $positions;
        \sort($sorted);
        self::assertSame($sorted, $positions, '合并顺序必须与 SOURCES 一致');

        $second = WelineCoreBundle::publish($this->root);
        self::assertSame(WelineCoreBundle::RELATIVE_PATH, $second['path']);
        self::assertFalse($second['changed'], '内容未变时不应重写');

        \file_put_contents($this->root . '/Weline/Frontend/js/runtime.js', 'window.RT=2;');
        $third = WelineCoreBundle::publish($this->root);
        self::assertTrue($third['changed']);
        self::assertStringContainsString('window.RT=2;', (string)\file_get_contents($this->root . '/' . WelineCoreBundle::RELATIVE_PATH));
    }

    public function testMissingSourcesYieldPartialBundle(): void
    {
        \unlink($this->root . '/Weline/Framework/js/url-frontend.js');
        $result = WelineCoreBundle::publish($this->root);
        self::assertSame(WelineCoreBundle::RELATIVE_PATH, $result['path']);
        $bundle = (string)\file_get_contents($this->root . '/' . WelineCoreBundle::RELATIVE_PATH);
        self::assertStringNotContainsString('url-frontend.js', $bundle);
        self::assertStringContainsString('window.RT=1;', $bundle);
    }

    public function testEmptyRootReturnsNull(): void
    {
        $empty = \sys_get_temp_dir() . '/weline-core-empty-' . \bin2hex(\random_bytes(4));
        \mkdir($empty, 0775, true);
        try {
            $result = WelineCoreBundle::publish($empty);
            self::assertNull($result['path']);
            self::assertFalse($result['changed']);
        } finally {
            @\rmdir($empty);
        }
    }
}
