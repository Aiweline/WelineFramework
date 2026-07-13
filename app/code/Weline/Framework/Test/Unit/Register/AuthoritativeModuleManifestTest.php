<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Register;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Register\Register;

final class AuthoritativeModuleManifestTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/weline-register-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory . '/etc', 0777, true);
    }

    protected function tearDown(): void
    {
        $manifest = $this->temporaryDirectory . '/etc/module.php';
        if (is_file($manifest)) {
            unlink($manifest);
        }
        if (is_dir($this->temporaryDirectory . '/etc')) {
            rmdir($this->temporaryDirectory . '/etc');
        }
        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
    }

    public function testRuntimeRegistrationUsesManifestVersionAndRequiredDependencies(): void
    {
        $this->writeManifest('Weline_Example');

        $method = new \ReflectionMethod(Register::class, 'resolveAuthoritativeModuleMetadata');
        $result = $method->invoke(null, $this->temporaryDirectory, 'Weline_Example', '1.0.0', []);

        self::assertSame([
            'Weline_Example',
            '2.0.0',
            ['Weline_Framework'],
        ], $result);
    }

    public function testRuntimeRegistrationRejectsNameMismatch(): void
    {
        $this->writeManifest('Weline_Different');

        $method = new \ReflectionMethod(Register::class, 'resolveAuthoritativeModuleMetadata');

        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('does not match register.php name');
        $method->invoke(null, $this->temporaryDirectory, 'Weline_Example', '1.0.0', []);
    }

    private function writeManifest(string $name): void
    {
        $exportedName = var_export($name, true);
        file_put_contents($this->temporaryDirectory . '/etc/module.php', <<<PHP
<?php
return [
    'name' => {$exportedName},
    'version' => '2.0.0',
    'requires' => ['Weline_Framework' => '^2.0'],
    'optional' => ['Weline_Server' => '^2.0'],
    'provides' => [],
];
PHP);
    }
}
