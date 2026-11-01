<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeUpdate\ThemeStaticTreeFingerprint;

final class ThemeStaticTreeFingerprintTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/weline-fp-' . \uniqid('', true);
        \mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && \is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
            }
            @\rmdir($this->dir);
        }
        $this->dir = '';
    }

    public function testEmptyOrMissingRootYieldsEmptyString(): void
    {
        self::assertSame('', ThemeStaticTreeFingerprint::compute($this->dir));
        self::assertSame('', ThemeStaticTreeFingerprint::compute($this->dir . '/nope'));
    }

    public function testSameContentSameFingerprintAndShape(): void
    {
        \mkdir($this->dir . '/a', 0775, true);
        \file_put_contents($this->dir . '/a/x.css', 'body{color:red}');
        \file_put_contents($this->dir . '/y.css', 'p{margin:0}');
        \touch($this->dir . '/a/x.css', 1700000000);
        \touch($this->dir . '/y.css', 1700000001);

        $first = ThemeStaticTreeFingerprint::compute($this->dir);
        $second = ThemeStaticTreeFingerprint::compute($this->dir);

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $first);
        self::assertSame($first, $second);
    }

    public function testContentChangeMovesFingerprint(): void
    {
        \file_put_contents($this->dir . '/y.css', 'p{margin:0}');
        \touch($this->dir . '/y.css', 1700000001);
        $before = ThemeStaticTreeFingerprint::compute($this->dir);

        \file_put_contents($this->dir . '/y.css', 'p{margin:1px}');
        \touch($this->dir . '/y.css', 1700000002);
        $after = ThemeStaticTreeFingerprint::compute($this->dir);

        self::assertNotSame($before, $after);
    }

    public function testRenameMovesFingerprint(): void
    {
        \file_put_contents($this->dir . '/a.css', 'x{}');
        \touch($this->dir . '/a.css', 1700000001);
        $before = ThemeStaticTreeFingerprint::compute($this->dir);

        \rename($this->dir . '/a.css', $this->dir . '/b.css');
        $after = ThemeStaticTreeFingerprint::compute($this->dir);

        self::assertNotSame($before, $after);
    }
}
