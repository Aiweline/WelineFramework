<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Console\Cli;
use Weline\Framework\Test\Console\E2e\Run as ProjectE2eRun;
use Weline\Framework\UnitTest\Console\E2e\Run as VendorE2eRun;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class CommandPreferenceTest extends TestCase
{
    public function testProjectCommandWinsOverRegisteredVendorCommand(): void
    {
        $cli = (new ReflectionClass(Cli::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Cli::class, 'selectPreferredCommand');
        $selected = $method->invoke($cli, [
            ['class' => VendorE2eRun::class, 'command' => 'e2e:run', 'data' => ['class' => VendorE2eRun::class]],
            ['class' => ProjectE2eRun::class, 'command' => 'e2e:run', 'data' => ['class' => ProjectE2eRun::class]],
        ]);

        self::assertSame(ProjectE2eRun::class, $selected['class']);
    }
}
