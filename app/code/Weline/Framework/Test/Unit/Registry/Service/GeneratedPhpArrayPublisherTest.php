<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Registry\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\CompiledPhpRegistry;
use Weline\Framework\Registry\Service\GeneratedPhpArrayPublisher;

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 8) . DIRECTORY_SEPARATOR);
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('CLI')) {
    define('CLI', true);
}
if (!defined('PROD')) {
    define('PROD', false);
}
require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Framework/func_log.php';

final class GeneratedPhpArrayPublisherTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/weline-registry-publish-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testAtomicReplaceKeepsPreviousUntilComplete(): void
    {
        $target = $this->dir . '/sample.php';
        CompiledPhpRegistry::forgetForTests($target);
        $publisher = new GeneratedPhpArrayPublisher();
        $publisher->publishArray($target, ['a' => 1], GeneratedPhpArrayPublisher::countTopLevel(...));
        self::assertSame(['a' => 1], CompiledPhpRegistry::peek($target));
        self::assertSame(['a' => 1], CompiledPhpRegistry::load($target));

        $publisher->publishArray($target, ['a' => 1, 'b' => 2], GeneratedPhpArrayPublisher::countTopLevel(...));
        self::assertSame(['a' => 1, 'b' => 2], CompiledPhpRegistry::peek($target));
        self::assertSame(['a' => 1, 'b' => 2], CompiledPhpRegistry::load($target));
    }

    public function testPublishPutsIntoProcessBagWithoutReinclude(): void
    {
        $counter = $this->dir . '/count.txt';
        $target = $this->dir . '/counted.php';
        file_put_contents($counter, '0');
        // Seed via publisher (put), then overwrite disk by hand and put again —
        // load must still return put payload and never bump counter from include.
        $publisher = new GeneratedPhpArrayPublisher();
        CompiledPhpRegistry::forgetForTests($target);
        $publisher->publishArray($target, ['seed' => 1], GeneratedPhpArrayPublisher::countTopLevel(...));
        self::assertTrue(CompiledPhpRegistry::isLoaded($target));

        // Corrupt the mental model: rewrite file with include counter; put wins.
        file_put_contents($target, <<<PHP
<?php
\$c = (int) file_get_contents({$this->export($counter)});
file_put_contents({$this->export($counter)}, (string) (\$c + 1));
return ['from_disk' => true];
PHP);
        CompiledPhpRegistry::put($target, ['from_put' => true]);
        self::assertSame(['from_put' => true], CompiledPhpRegistry::load($target));
        self::assertSame('0', file_get_contents($counter));
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    public function testRefuseWipeWhenExistingHasEntries(): void
    {
        $target = $this->dir . '/hooks-like.php';
        $publisher = new GeneratedPhpArrayPublisher();
        $publisher->publishArray(
            $target,
            ['hooks' => ['h1' => []]],
            GeneratedPhpArrayPublisher::countKey('hooks'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/persist refused.*keeping existing/');
        $publisher->publishArray(
            $target,
            ['hooks' => []],
            GeneratedPhpArrayPublisher::countKey('hooks'),
        );
    }
}
