<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App\Env;

final class EnvAtomicPersistenceTest extends TestCase
{
    private string $temporaryDirectory = '';

    protected function setUp(): void
    {
        $directory = \tempnam(\sys_get_temp_dir(), 'weline-env-atomic-');
        self::assertIsString($directory);
        @\unlink($directory);
        self::assertTrue(\mkdir($directory, 0700));
        $this->temporaryDirectory = $directory;
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->temporaryDirectory);
    }

    public function testAtomicWriterReplacesACompleteConfigWithoutLeavingStageFiles(): void
    {
        $target = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'env.php';
        $oldConfig = "<?php return ['old' => true];";
        self::assertSame(\strlen($oldConfig), \file_put_contents($target, $oldConfig));

        $method = new ReflectionMethod(Env::class, 'writeConfigFileAtomically');
        $result = $method->invoke(
            Env::getInstance(),
            ['db' => ['default' => 'pgsql'], 'marker' => 'complete'],
            $target
        );

        self::assertTrue($result);
        self::assertSame(
            ['db' => ['default' => 'pgsql'], 'marker' => 'complete'],
            include $target
        );
        self::assertSame([], \glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '.env.php.*') ?: []);
    }
}
