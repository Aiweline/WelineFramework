<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Service\OperationsDiagnosticsService;

final class OperationsDiagnosticsServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-r43-ops-' . \bin2hex(\random_bytes(6));
        foreach (['app/etc', 'var/mig/clones', 'var/mig/checkpoints', 'var/deploy'] as $directory) {
            self::assertTrue(\mkdir($this->root . DIRECTORY_SEPARATOR . $directory, 0700, true));
        }
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }
        \rmdir($this->root);
        parent::tearDown();
    }

    public function testMigrationProjectionContainsOnlyAllowlistedReadOnlyFields(): void
    {
        $this->writeEnv();
        $this->writeJson('var/mig/clones/r43.json', [
            'clone_id' => 'mig_commercer43e2e_example',
            'database' => 'mig_clone_commercer43e2e_example',
            'mode' => 'full',
            'source_database' => 'weline',
            'created_at' => '2026-08-03T00:00:00+08:00',
            'fingerprint' => \str_repeat('a', 64),
            'password' => 'must-not-leak',
        ]);
        $this->writeJson('var/mig/checkpoints/r43.json', [
            'checkpoint_id' => 'r43-checkpoint',
            'phase' => 'verify',
            'updated_at' => '2026-08-03T00:01:00+08:00',
            'journal' => [['ok' => true]],
            'secret' => 'must-not-leak',
        ]);

        $result = (new OperationsDiagnosticsService($this->root))->migration();

        self::assertSame([
            'connector' => 'pgsql',
            'database' => 'mig_clone_commercer43e2e_example',
            'is_clone' => true,
        ], $result['environment']);
        self::assertSame('aaaaaaaaaaaa', $result['clones'][0]['fingerprint']);
        self::assertSame(1, $result['checkpoints'][0]['journal_entries']);
        self::assertArrayNotHasKey('password', $result['clones'][0]);
        self::assertArrayNotHasKey('secret', $result['checkpoints'][0]);
    }

    public function testReleaseProjectionDoesNotExposeEnvironmentOrMarkerSecrets(): void
    {
        $this->writeEnv();
        $this->writeJson('var/deploy/current.json', [
            'release_id' => 'release-r43',
            'status' => 'ready',
            'git_ref' => 'codex/php84-orm-performance',
            'webhook_secret' => 'must-not-leak',
        ]);
        $this->writeJson('var/deploy/core-version.json', [
            'check' => 'ok',
            'last_notified_fingerprint' => \str_repeat('b', 64),
            'last_notified_at' => '2026-08-03T00:02:00+08:00',
            'token' => 'must-not-leak',
        ]);

        $result = (new OperationsDiagnosticsService($this->root))->release();

        self::assertSame('dev', $result['environment']['deploy_mode']);
        self::assertSame('release-r43', $result['current']['release_id']);
        self::assertSame('bbbbbbbbbbbb', $result['core']['last_notified_fingerprint']);
        self::assertArrayNotHasKey('webhook_secret', $result['current']);
        self::assertArrayNotHasKey('token', $result['core']);
    }

    private function writeEnv(): void
    {
        $contents = <<<'PHP'
<?php
return [
    'db' => ['master' => [
        'type' => 'pgsql',
        'database' => 'mig_clone_commercer43e2e_example',
        'password' => 'must-not-leak',
    ]],
    'deploy' => 'dev',
    'deploy_version' => 'r43',
    'worker_build_id' => 'worker-r43',
    'secret' => 'must-not-leak',
];
PHP;
        self::assertNotFalse(\file_put_contents($this->root . '/app/etc/env.php', $contents));
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $relativePath, array $data): void
    {
        self::assertNotFalse(\file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . $relativePath,
            \json_encode($data, JSON_THROW_ON_ERROR),
        ));
    }
}
