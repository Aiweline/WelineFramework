<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Registry\Service;

use PHPUnit\Framework\TestCase;
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
        $publisher = new GeneratedPhpArrayPublisher();
        $publisher->publishArray($target, ['a' => 1], GeneratedPhpArrayPublisher::countTopLevel(...));
        self::assertSame(['a' => 1], include $target);

        $publisher->publishArray($target, ['a' => 1, 'b' => 2], GeneratedPhpArrayPublisher::countTopLevel(...));
        self::assertSame(['a' => 1, 'b' => 2], include $target);
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
