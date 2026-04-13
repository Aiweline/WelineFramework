<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class HookReaderIsolationGuardTest extends TestCase
{
    /**
     * HookReader carries mutable `path` state, so render-time code must not
     * share it as a singleton across concurrent WLS fibers.
     */
    private const GUARDED_FILES = [
        'app/code/Weline/Framework/View/Template.php',
        'app/code/Weline/Framework/View/Taglib.php',
        'app/code/Weline/Framework/Hook/Hooker.php',
        'app/code/Weline/Hook/Service/HookDataService.php',
    ];

    public function testRenderPathDoesNotUseSharedHookReaderSingleton(): void
    {
        $root = \dirname(__DIR__, 7);
        $violations = [];

        foreach (self::GUARDED_FILES as $relativePath) {
            $path = $root . '/' . $relativePath;
            $contents = \file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (\str_contains($contents, 'getInstance(HookReader::class)')
                || \str_contains($contents, 'getInstance(\\Weline\\Framework\\Hook\\Config\\HookReader::class)')
            ) {
                $violations[] = $relativePath;
            }
        }

        self::assertSame([], $violations, "HookReader must not be shared as a singleton in render/request paths.\n" . \implode("\n", $violations));
    }
}
