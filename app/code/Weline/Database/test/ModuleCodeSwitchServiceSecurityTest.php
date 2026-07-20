<?php

declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Database\Service\ModuleCodeSwitchService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

final class ModuleCodeSwitchServiceSecurityTest extends TestCore
{
    private ModuleCodeSwitchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ObjectManager::getInstance(ModuleCodeSwitchService::class, [], false);
    }

    public function testCleanupRejectsPathsOutsideManagedAppCodeRollbackDirectories(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('app/code');

        $this->service->cleanup([[
            'module_name' => 'Fixture_UnsafePath',
            'target_path' => BP,
            'old_path' => BP . 'var',
        ]]);
    }

    public function testSubprocessArgumentsAreNotEvaluatedByAShell(): void
    {
        $marker = sys_get_temp_dir() . DS . 'weline-rollback-shell-' . bin2hex(random_bytes(6));
        $payload = ';touch ' . $marker;
        $method = new \ReflectionMethod(ModuleCodeSwitchService::class, 'runCommand');

        [$exitCode, $output] = $method->invoke(
            $this->service,
            [PHP_BINARY, '-r', 'echo $argv[1];', '--', $payload],
            false
        );

        self::assertSame(0, $exitCode);
        self::assertSame([$payload], $output);
        self::assertFileDoesNotExist($marker);
    }
}
