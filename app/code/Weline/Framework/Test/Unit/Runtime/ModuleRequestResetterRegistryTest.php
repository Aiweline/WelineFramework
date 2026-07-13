<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Runtime\ModuleRequestResetterRegistry;
use Weline\Framework\Runtime\RequestResetterInterface;

final class ModuleRequestResetterRegistryTest extends TestCase
{
    private string $registryFile;

    protected function setUp(): void
    {
        $this->registryFile = sys_get_temp_dir() . '/weline-request-resetters-' . bin2hex(random_bytes(6)) . '.php';
        FirstResetter::resetCount();
        SecondResetter::resetCount();
    }

    protected function tearDown(): void
    {
        if (is_file($this->registryFile)) {
            unlink($this->registryFile);
        }
    }

    public function testInvokesOnlyCompiledRequestResetterProviders(): void
    {
        $registry = [
            'format' => 1,
            'order' => ['Module_A', 'Module_B'],
            'modules' => [
                'Module_A' => ['provides' => [
                    'request_resetter.Module_A' => FirstResetter::class,
                    'unrelated.capability' => FirstResetter::class,
                ]],
                'Module_B' => ['provides' => [
                    'request_resetter.Module_B' => SecondResetter::class,
                ]],
            ],
        ];
        file_put_contents($this->registryFile, '<?php return ' . var_export($registry, true) . ';');

        $resetters = new ModuleRequestResetterRegistry(new ServiceProviderRegistry($this->registryFile));
        $resetters->resetRequest();

        self::assertSame(1, FirstResetter::count());
        self::assertSame(1, SecondResetter::count());
    }

    public function testFrameworkStateManagerDoesNotNameConcreteModules(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/Runtime/StateManager.php');

        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression('/Weline\\\\(?!Framework\\\\)/', $source);
    }
}

final class FirstResetter implements RequestResetterInterface
{
    private static int $count = 0;

    public function resetRequest(): void
    {
        ++self::$count;
    }

    public static function resetCount(): void
    {
        self::$count = 0;
    }

    public static function count(): int
    {
        return self::$count;
    }
}

final class SecondResetter implements RequestResetterInterface
{
    private static int $count = 0;

    public function resetRequest(): void
    {
        ++self::$count;
    }

    public static function resetCount(): void
    {
        self::$count = 0;
    }

    public static function count(): int
    {
        return self::$count;
    }
}
