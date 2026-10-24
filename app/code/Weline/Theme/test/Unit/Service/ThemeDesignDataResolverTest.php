<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeDesignDataResolver;
use Weline\Theme\Service\ThemeDirectoryResolver;

final class ThemeDesignDataResolverTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/data/*.php') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($dir . '/data')) {
                rmdir($dir . '/data');
            }
            rmdir($dir);
        }
        $this->dirs = [];
        parent::tearDown();
    }

    public function testChildThemePackWinsOverParent(): void
    {
        $child = $this->themeDir(['storefront-defaults' => "['hot_words' => ['child']]"]);
        $parent = $this->themeDir(['storefront-defaults' => "['hot_words' => ['parent']]"]);

        self::assertSame(['child'], $this->resolver([$child, $parent])->stringList('storefront-defaults', 'hot_words'));
    }

    public function testFallsBackToParentWhenChildShipsNoPack(): void
    {
        $child = $this->themeDir([]);
        $parent = $this->themeDir(['storefront-defaults' => "['hot_words' => ['parent']]"]);

        self::assertSame(['parent'], $this->resolver([$child, $parent])->stringList('storefront-defaults', 'hot_words'));
    }

    public function testNoPackInChainYieldsEmpty(): void
    {
        $resolver = $this->resolver([$this->themeDir([])]);

        self::assertSame([], $resolver->load('storefront-defaults'));
        self::assertNull($resolver->locate('storefront-defaults'));
        self::assertSame([], $this->resolver([])->load('storefront-defaults'));
    }

    public function testStringListDropsBlankAndNonStringEntries(): void
    {
        $theme = $this->themeDir(['storefront-defaults' => "['hot_words' => [' a ', '', 3, null, 'b']]"]);

        self::assertSame(['a', 'b'], $this->resolver([$theme])->stringList('storefront-defaults', 'hot_words'));
    }

    public function testNonArrayPackFailsLoudly(): void
    {
        $theme = $this->themeDir(['broken' => "'not-an-array'"]);

        $this->expectException(\UnexpectedValueException::class);
        $this->resolver([$theme])->load('broken');
    }

    public function testRejectsPathTraversalKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver([])->load('../etc/passwd');
    }

    /**
     * @param array<string,string> $packs key => PHP array expression
     */
    private function themeDir(array $packs): string
    {
        $dir = sys_get_temp_dir() . '/weline-theme-data-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;
        if ($packs !== []) {
            mkdir($dir . '/data');
            foreach ($packs as $key => $expression) {
                file_put_contents($dir . '/data/' . $key . '.php', "<?php\nreturn " . $expression . ";\n");
            }
        }

        return $dir;
    }

    /**
     * @param list<string> $paths child-first theme chain
     */
    private function resolver(array $paths): ThemeDesignDataResolver
    {
        $chain = [];
        foreach ($paths as $path) {
            $theme = $this->createMock(WelineTheme::class);
            $theme->method('getPath')->willReturn($path);
            $chain[] = $theme;
        }
        $directories = $this->createMock(ThemeDirectoryResolver::class);
        $directories->method('getThemeChain')->willReturn($chain);

        return new ThemeDesignDataResolver($directories);
    }
}
