<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\DeployStagingSession;

final class DeployStagingSessionTest extends TestCase
{
    protected function tearDown(): void
    {
        $active = DeployStagingSession::current();
        if ($active !== null) {
            $active->abort();
        }
        putenv(DeployStagingSession::ENV_ROOT);
        unset($_ENV[DeployStagingSession::ENV_ROOT], $_SERVER[DeployStagingSession::ENV_ROOT]);
        parent::tearDown();
    }

    public function testRootsFollowStagingEnv(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . '/weline-staging-roots-' . bin2hex(random_bytes(4));
        mkdir($root . '/static', 0775, true);
        mkdir($root . '/complicate', 0775, true);
        mkdir($root . '/theme-layout-entities', 0775, true);
        putenv(DeployStagingSession::ENV_ROOT . '=' . $root);
        $_ENV[DeployStagingSession::ENV_ROOT] = $root;

        self::assertTrue(DeployStagingSession::isActive());
        self::assertSame($root . DIRECTORY_SEPARATOR . 'static', DeployStagingSession::staticRoot());
        self::assertStringEndsWith(
            'complicate' . DIRECTORY_SEPARATOR,
            DeployStagingSession::complicateRoot(),
        );
        self::assertStringContainsString('theme-layout-entities', DeployStagingSession::themeLayoutEntitiesRoot());

        $this->removeTree($root);
    }

    public function testOpenCreatesStagingTreesAndEnv(): void
    {
        $session = DeployStagingSession::open('ut-open-' . bin2hex(random_bytes(2)));
        try {
            self::assertTrue(DeployStagingSession::isActive());
            self::assertDirectoryExists($session->root() . 'static');
            self::assertDirectoryExists($session->root() . 'complicate');
            self::assertDirectoryExists($session->root() . 'theme-layout-entities');
            self::assertSame(
                rtrim($session->root(), '/\\'),
                rtrim((string)getenv(DeployStagingSession::ENV_ROOT), '/\\'),
            );
            self::assertStringStartsWith($session->root(), DeployStagingSession::staticRoot());
        } finally {
            $session->abort();
        }
        self::assertFalse(DeployStagingSession::isActive());
    }

    public function testEmptyStaticCommitFailsClosed(): void
    {
        $session = DeployStagingSession::open('ut-empty-' . bin2hex(random_bytes(2)));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('deploy_staging_static_empty');
            $session->commitSwap();
        } finally {
            $session->abort();
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $p = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($path);
    }
}
