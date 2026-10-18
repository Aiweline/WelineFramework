<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\DirectoryList;

final class DirectoryListTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline-dirlist-' . \bin2hex(\random_bytes(4));
        self::assertTrue(@\mkdir($this->tmp . '/app/etc', 0700, true));
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && \is_dir($this->tmp)) {
            $this->rmTree($this->tmp);
        }
    }

    public function testDefaultsWhenDirectoriesAbsent(): void
    {
        \file_put_contents($this->tmp . '/app/etc/env.php', "<?php return [];\n");
        $list = DirectoryList::bootstrapFromEnvFile($this->tmp);
        self::assertSame(
            \rtrim($this->tmp, '/\\') . \DIRECTORY_SEPARATOR . 'pub' . \DIRECTORY_SEPARATOR,
            $list->getPub(),
        );
        self::assertSame(
            \rtrim($this->tmp, '/\\') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR,
            $list->getVar(),
        );
        self::assertSame(
            \rtrim($list->getPub(), '/\\') . \DIRECTORY_SEPARATOR . 'media' . \DIRECTORY_SEPARATOR,
            $list->getMedia(),
        );
    }

    public function testAbsoluteMediaAndVar(): void
    {
        $media = $this->tmp . '/data-media';
        $var = $this->tmp . '/data-var';
        self::assertTrue(@\mkdir($media, 0700, true));
        self::assertTrue(@\mkdir($var, 0700, true));
        $env = "<?php return ['directories' => ["
            . "'var' => " . \var_export($var, true) . ','
            . "'media' => " . \var_export($media, true)
            . "]];\n";
        \file_put_contents($this->tmp . '/app/etc/env.php', $env);
        $list = DirectoryList::bootstrapFromEnvFile($this->tmp);
        self::assertSame(\rtrim($var, '/\\') . \DIRECTORY_SEPARATOR, $list->getVar());
        self::assertSame(\rtrim($media, '/\\') . \DIRECTORY_SEPARATOR, $list->getMedia());
        $resolved = $list->resolveConfiguredDataPath('var/cache/');
        self::assertStringStartsWith(\rtrim($var, '/\\'), $resolved);
        self::assertStringContainsString('cache', $resolved);
    }

    public function testRejectsDotDot(): void
    {
        $env = "<?php return ['directories' => ['var' => '../evil']];\n";
        \file_put_contents($this->tmp . '/app/etc/env.php', $env);
        $this->expectException(\InvalidArgumentException::class);
        DirectoryList::bootstrapFromEnvFile($this->tmp);
    }

    public function testAbsoluteConfiguredDataPathNotPrefixedWithBp(): void
    {
        \file_put_contents($this->tmp . '/app/etc/env.php', "<?php return [];\n");
        $list = DirectoryList::bootstrapFromEnvFile($this->tmp);
        $abs = $this->tmp . '/outside-cache';
        $resolved = $list->resolveConfiguredDataPath($abs);
        self::assertStringStartsWith($this->tmp, $resolved);
        self::assertStringNotContainsString($this->tmp . \DIRECTORY_SEPARATOR . $this->tmp, $resolved);
    }

    public function testMediaAbsoluteFromUrlPath(): void
    {
        if (!\defined('MEDIA_DIR')) {
            \define('MEDIA_DIR', $this->tmp . \DIRECTORY_SEPARATOR . 'media' . \DIRECTORY_SEPARATOR);
        }
        $abs = DirectoryList::mediaAbsoluteFromUrlPath('/pub/media/a/b.jpg');
        self::assertStringEndsWith('a' . \DIRECTORY_SEPARATOR . 'b.jpg', $abs);
    }

    private function rmTree(string $dir): void
    {
        $items = @\scandir($dir);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . \DIRECTORY_SEPARATOR . $item;
            if (\is_dir($path)) {
                $this->rmTree($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
