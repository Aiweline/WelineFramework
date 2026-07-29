<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxConfigPublication;

final class NginxConfigPublicationTest extends TestCase
{
    private string $root = '';
    private string $active = '';
    private NginxConfigPublication $publication;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-publication-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $this->active = $this->root . DIRECTORY_SEPARATOR . 'nginx.conf';
        $this->publication = new NginxConfigPublication($this->active, 'test nginx');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCandidatePublishAndRollbackRestorePreviousActive(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('a', 32));

        self::assertSame('new-config', \file_get_contents($this->active));
        self::assertIsString($published['rollback']);
        self::assertSame('old-config', \file_get_contents($published['rollback']));

        $this->publication->rollbackPublished($published['rollback']);
        self::assertSame('old-config', \file_get_contents($this->active));
        self::assertFileDoesNotExist($published['rollback']);
    }

    public function testCommitPreservesPreviousGenerationAsLastGood(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('b', 32));

        self::assertTrue($this->publication->commitPublished($published['rollback']));
        self::assertSame('new-config', \file_get_contents($this->active));
        self::assertSame('old-config', \file_get_contents($this->active . '.last-good'));
        self::assertFileDoesNotExist($published['rollback']);
    }

    public function testInvalidCandidateNeverChangesActiveConfig(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside.conf';
        self::assertSame(10, \file_put_contents($outside, 'bad-config'));

        try {
            $this->publication->publishCandidate($outside, \str_repeat('c', 32));
            self::fail('A candidate outside the generated transaction scope must fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame('old-config', \file_get_contents($this->active));
            self::assertSame('bad-config', \file_get_contents($outside));
        }
    }

    public function testInterruptedMissingActiveRecoversLastGood(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('d', 32));
        self::assertTrue($this->publication->commitPublished($published['rollback']));
        self::assertTrue(\unlink($this->active));

        $this->publication->recoverInterruptedPublication();

        self::assertSame('old-config', \file_get_contents($this->active));
    }

    public function testFirstPublicationRollbackRemovesRejectedCandidate(): void
    {
        $candidate = $this->publication->stageCandidate('first-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('e', 32));
        self::assertNull($published['rollback']);
        self::assertSame('first-config', \file_get_contents($this->active));

        $this->publication->rollbackPublished(null);

        self::assertFileDoesNotExist($this->active);
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
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
