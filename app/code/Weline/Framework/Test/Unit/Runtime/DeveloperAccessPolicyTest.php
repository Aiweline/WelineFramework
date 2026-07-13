<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\DeveloperAccessPolicy;
use Weline\Framework\Runtime\DeveloperAccessProviderInterface;

final class DeveloperAccessPolicyTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testDeniesAccessWhenOptionalDeveloperModuleIsAbsent(): void
    {
        $policy = new DeveloperAccessPolicy(new ServiceProviderRegistry($this->registry([])));

        self::assertFalse($policy->shouldInjectBootstrap());
        self::assertFalse($policy->canAccessPanel());
        self::assertFalse($policy->canAccessApi());
    }

    public function testDelegatesToCompiledDeveloperProvider(): void
    {
        $policy = new DeveloperAccessPolicy(new ServiceProviderRegistry($this->registry([
            DeveloperAccessProviderInterface::class => AllowDeveloperAccessProvider::class,
        ])));

        self::assertTrue($policy->shouldInjectBootstrap());
        self::assertTrue($policy->canAccessPanel());
        self::assertTrue($policy->canAccessApi());
    }

    /** @param array<string, class-string> $provides */
    private function registry(array $provides): string
    {
        $file = sys_get_temp_dir() . '/weline-developer-access-' . bin2hex(random_bytes(6)) . '.php';
        $this->files[] = $file;
        $compiled = [
            'format' => 1,
            'order' => ['Module_Developer'],
            'modules' => ['Module_Developer' => ['provides' => $provides]],
        ];
        file_put_contents($file, '<?php return ' . var_export($compiled, true) . ';');
        return $file;
    }
}

final class AllowDeveloperAccessProvider implements DeveloperAccessProviderInterface
{
    public function shouldInjectBootstrap(): bool { return true; }
    public function canAccessPanel(?Request $request = null): bool { return true; }
    public function canAccessApi(?Request $request = null): bool { return true; }
}
