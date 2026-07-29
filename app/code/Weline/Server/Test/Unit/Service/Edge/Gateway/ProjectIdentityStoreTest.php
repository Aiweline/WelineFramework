<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;

final class ProjectIdentityStoreTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->temporaryRoots) as $root) {
            $this->removeTree($root);
        }
        $this->temporaryRoots = [];
    }

    public function testIdentityMovesWithProjectAndGenerationIsMonotonic(): void
    {
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'project-a');
        $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        $store = new ProjectIdentityStore($project, $hostState, $legacy);

        $uuid = $store->projectUuid();
        $digestA = \hash('sha256', 'desired-a');
        $digestB = \hash('sha256', 'desired-b');
        self::assertSame(1, $store->advanceDesiredState($digestA)['generation']);
        self::assertSame(1, $store->advanceDesiredState($digestA)['generation']);
        self::assertSame(2, $store->advanceDesiredState($digestB)['generation']);

        $moved = $sandbox . DIRECTORY_SEPARATOR . 'project-moved';
        self::assertTrue(\rename($project, $moved));
        $movedStore = new ProjectIdentityStore($moved, $hostState, $legacy);

        self::assertSame($uuid, $movedStore->projectUuid());
        self::assertSame(2, $movedStore->advanceDesiredState($digestB)['generation']);
    }

    public function testLiveSameHostCloneRequiresExplicitRotation(): void
    {
        $sandbox = $this->makeSandbox();
        $first = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'first');
        $second = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'second');
        $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        $firstStore = new ProjectIdentityStore($first, $hostState, $legacy);
        $firstUuid = $firstStore->projectUuid();
        $identity = 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wls-project.json';
        self::assertTrue(\copy(
            $first . DIRECTORY_SEPARATOR . $identity,
            $second . DIRECTORY_SEPARATOR . $identity,
        ));
        $clone = new ProjectIdentityStore($second, $hostState, $legacy);

        try {
            $clone->projectUuid();
            self::fail('A live same-host clone must not share a project UUID.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('explicit project identity rotation', $exception->getMessage());
        }

        $rotated = $clone->rotate();
        self::assertSame($firstUuid, $rotated['previous_uuid']);
        self::assertNotSame($firstUuid, $rotated['project_uuid']);
        self::assertSame($rotated['project_uuid'], $clone->projectUuid());
    }

    public function testMissingIdentityInReadOnlyProjectFailsWithoutTemporaryUuid(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory permission test.');
        }
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'readonly');
        $etc = $project . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc';
        self::assertTrue(\chmod($etc, 0555));
        try {
            $store = new ProjectIdentityStore(
                $project,
                $sandbox . DIRECTORY_SEPARATOR . 'host-state',
                $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json',
            );
            $this->expectException(\RuntimeException::class);
            $store->projectUuid();
        } finally {
            \chmod($etc, 0755);
        }
    }

    public function testUnreadableIdentityIsNotMisreportedAsInvalidJson(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || (\function_exists('posix_geteuid') && \posix_geteuid() === 0)
        ) {
            self::markTestSkipped('POSIX non-root file permission test.');
        }
        $sandbox = $this->makeSandbox();
        $project = $this->makeProject($sandbox . DIRECTORY_SEPARATOR . 'unreadable');
        $hostState = $sandbox . DIRECTORY_SEPARATOR . 'host-state';
        $legacy = $sandbox . DIRECTORY_SEPARATOR . 'missing-legacy.json';
        $store = new ProjectIdentityStore($project, $hostState, $legacy);
        $store->projectUuid();
        $identity = $project . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'wls-project.json';
        self::assertTrue(\chmod($identity, 0000));

        try {
            (new ProjectIdentityStore($project, $hostState, $legacy))->projectUuid();
            self::fail('An unreadable project fact must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('not readable', $exception->getMessage());
            self::assertStringNotContainsString('invalid JSON', $exception->getMessage());
        } finally {
            \chmod($identity, 0600);
        }
    }

    private function makeSandbox(): string
    {
        $root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-project-identity-test-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($root, 0700, true));
        $this->temporaryRoots[] = $root;
        return $root;
    }

    private function makeProject(string $root): string
    {
        self::assertTrue(\mkdir(
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc',
            0755,
            true,
        ));
        $real = \realpath($root);
        self::assertIsString($real);
        return $real;
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($root);
    }
}
