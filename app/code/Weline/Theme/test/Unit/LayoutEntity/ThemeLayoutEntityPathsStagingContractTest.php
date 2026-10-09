<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

final class ThemeLayoutEntityPathsStagingContractTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(DeployStagingSession::ENV_ROOT);
        unset($_ENV[DeployStagingSession::ENV_ROOT], $_SERVER[DeployStagingSession::ENV_ROOT]);
        parent::tearDown();
    }

    public function testRootFollowsStagingEnv(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/weline-theme-paths-staging-' . bin2hex(random_bytes(3));
        mkdir($root . '/theme-layout-entities', 0775, true);
        putenv(DeployStagingSession::ENV_ROOT . '=' . $root);
        $_ENV[DeployStagingSession::ENV_ROOT] = $root;

        $paths = new ThemeLayoutEntityPaths();
        self::assertStringStartsWith(
            rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'theme-layout-entities',
            rtrim($paths->root(), '/\\'),
        );

        @rmdir($root . '/theme-layout-entities');
        @rmdir($root);
    }

    public function testSourceMentionsStagingSession(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php',
        );
        self::assertStringContainsString('DeployStagingSession', $src);
        self::assertStringContainsString('themeLayoutEntitiesRoot', $src);
    }
}
