<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Test\ProjectRoot;

/**
 * FPC URL-tree cold disk must live under repo-root {@code generated/html},
 * never {@code app/code/generated} (wrong BP stopping at app/code).
 */
final class FpcGeneratedNotUnderAppCodeContractTest extends TestCase
{
    public function testHtmlDiskRootIsRepoGeneratedNotAppCodeGenerated(): void
    {
        ProjectRoot::defineBp(__DIR__);
        self::assertFalse(
            ProjectRoot::isAccidentalAppCodeGenerated((string)BP),
            'BP itself must not be app/code'
        );

        $root = FpcHtmlDiskStore::root();
        self::assertStringContainsString(
            \DIRECTORY_SEPARATOR . 'generated' . \DIRECTORY_SEPARATOR . 'html',
            $root
        );
        self::assertFalse(
            ProjectRoot::isAccidentalAppCodeGenerated($root),
            'FpcHtmlDiskStore::root must not be under app/code/generated'
        );
        self::assertSame(
            \rtrim((string)Env::GENERATED_DIR, '\\/') . \DIRECTORY_SEPARATOR . 'html',
            $root
        );
    }
}
