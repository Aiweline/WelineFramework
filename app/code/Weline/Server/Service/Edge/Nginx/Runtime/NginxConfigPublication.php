<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * Shared candidate/active/rollback transaction for managed Nginx configs.
 *
 * Candidates and rollback files always remain beside the active file, keeping
 * every rename on one filesystem. Callers must hold their lifecycle lock.
 */
final class NginxConfigPublication
{
    private const MAX_CONFIG_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly string $activeConfig,
        private readonly string $scope = 'managed nginx',
    ) {
        if (!$this->isAbsolutePath($activeConfig)
            || \basename($activeConfig) === ''
            || \str_contains($activeConfig, "\0")
            || \preg_match('#(?:^|[\\/])\.\.?([\\/]|$)#D', $activeConfig) === 1
        ) {
            throw new \InvalidArgumentException('Nginx active config path must be absolute.');
        }
        $directory = \dirname($activeConfig);
        $resolved = \realpath($directory);
        if (!\is_string($resolved)
            || !\is_dir($resolved)
            || \is_link($directory)
            || !$this->samePath($resolved, $directory)
        ) {
            throw new \InvalidArgumentException('Nginx active config directory is unsafe.');
        }
    }

    public function stageCandidate(string $contents): string
    {
        if ($contents === '') {
            throw new \InvalidArgumentException('Nginx candidate config must not be empty.');
        }
        $candidate = $this->candidatePath();
        $this->writeNewFile($candidate, $contents, 0600);
        return $candidate;
    }

    public function validateCandidate(string $candidate): void
    {
        $this->assertCandidatePath($candidate);
        $this->readRegularFile($candidate, 'candidate');
    }

    /** @return array{conf:string,rollback:string|null} */
    public function publishCandidate(string $candidate, string $transactionId): array
    {
        $this->assertCandidatePath($candidate);
        $candidateContents = $this->readRegularFile($candidate, 'candidate');
        $this->assertTransactionId($transactionId);
        $active = $this->activeConfig;
        $rollback = null;
        $activeContents = null;
        if (\file_exists($active) || \is_link($active)) {
            $activeContents = $this->readRegularFile($active, 'active config');
            $rollback = $this->rollbackPathForTransaction($transactionId);
            if (\file_exists($rollback) || \is_link($rollback)) {
                throw new \RuntimeException($this->scope . ' transaction rollback already exists.');
            }
            GatewayProjectStateFilesystem::atomicWrite(
                $rollback,
                $activeContents,
                0600,
            );
        }
        try {
            GatewayProjectStateFilesystem::atomicWrite(
                $active,
                $candidateContents,
                0600,
            );
            GatewayProjectStateFilesystem::removeRegular(
                $candidate,
                \ucfirst($this->scope) . ' candidate config',
            );
        } catch (\Throwable $throwable) {
            if ($rollback !== null && $activeContents !== null) {
                try {
                    GatewayProjectStateFilesystem::atomicWrite(
                        $active,
                        $activeContents,
                        0600,
                    );
                    GatewayProjectStateFilesystem::removeRegular(
                        $rollback,
                        \ucfirst($this->scope) . ' rollback config',
                    );
                } catch (\Throwable $restoreFailure) {
                    throw new \RuntimeException(
                        'Unable to publish or restore the ' . $this->scope
                            . ' config: ' . $restoreFailure->getMessage(),
                        0,
                        $throwable,
                    );
                }
            } elseif (\file_exists($active) || \is_link($active)) {
                try {
                    GatewayProjectStateFilesystem::removeRegular(
                        $active,
                        \ucfirst($this->scope) . ' failed first active config',
                    );
                } catch (\Throwable $restoreFailure) {
                    throw new \RuntimeException(
                        'Unable to publish or remove the first ' . $this->scope
                            . ' config: ' . $restoreFailure->getMessage(),
                        0,
                        $throwable,
                    );
                }
            }
            throw new \RuntimeException(
                'Unable to publish the ' . $this->scope . ' candidate config.',
                0,
                $throwable,
            );
        }

        return ['conf' => $active, 'rollback' => $rollback];
    }

    public function rollbackPublished(?string $rollback): void
    {
        if ($rollback !== null) {
            if ($rollback !== $this->rollbackPathForTransaction($this->transactionIdFromRollback($rollback))) {
                throw new \InvalidArgumentException($this->scope . ' rollback path is outside its config scope.');
            }
            $rollbackContents = $this->readRegularFile($rollback, 'rollback');
        } else {
            $rollbackContents = null;
        }
        $active = $this->activeConfig;
        $rejected = null;
        if (\file_exists($active) || \is_link($active)) {
            $activeContents = $this->readRegularFile($active, 'active config');
            $rejected = $active . '.rejected.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
            GatewayProjectStateFilesystem::atomicWrite(
                $rejected,
                $activeContents,
                0600,
            );
        }
        $restored = false;
        try {
            if ($rollbackContents !== null) {
                GatewayProjectStateFilesystem::atomicWrite(
                    $active,
                    $rollbackContents,
                    0600,
                );
                GatewayProjectStateFilesystem::removeRegular(
                    (string)$rollback,
                    \ucfirst($this->scope) . ' rollback config',
                );
            } elseif (\file_exists($active) || \is_link($active)) {
                GatewayProjectStateFilesystem::removeRegular(
                    $active,
                    \ucfirst($this->scope) . ' active config',
                );
            }
            $restored = true;
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Unable to restore the previous ' . $this->scope . ' config.',
                0,
                $throwable,
            );
        } finally {
            if ($restored && $rejected !== null) {
                GatewayProjectStateFilesystem::removeRegular(
                    $rejected,
                    \ucfirst($this->scope) . ' rejected config',
                );
            }
        }
    }

    public function recoverInterruptedPublication(): void
    {
        if (\file_exists($this->activeConfig)) {
            $this->assertRegularFile($this->activeConfig, 'active config');
            return;
        }
        $lastGood = $this->lastGoodPath();
        if (!\file_exists($lastGood) && !\is_link($lastGood)) {
            return;
        }
        $contents = $this->readRegularFile($lastGood, 'last-known-good config');
        GatewayProjectStateFilesystem::atomicWrite(
            $this->activeConfig,
            $contents,
            0600,
        );
    }

    public function rollbackPathForTransaction(string $transactionId): string
    {
        $this->assertTransactionId($transactionId);
        return $this->activeConfig . '.rollback.'
            . \strtolower(\trim($transactionId));
    }

    public function commitPublished(?string $rollback): bool
    {
        if ($rollback === null) {
            return true;
        }
        try {
            $transactionId = $this->transactionIdFromRollback($rollback);
            if ($rollback !== $this->rollbackPathForTransaction($transactionId)) {
                return false;
            }
            $contents = $this->readRegularFile($rollback, 'rollback');
            $this->replaceFile($this->lastGoodPath(), $contents, 0600);
            return GatewayProjectStateFilesystem::removeRegular(
                $rollback,
                \ucfirst($this->scope) . ' committed rollback config',
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function discardCandidate(string $candidate): void
    {
        $this->assertCandidatePath($candidate);
        if (\file_exists($candidate) || \is_link($candidate)) {
            GatewayProjectStateFilesystem::removeRegular(
                $candidate,
                \ucfirst($this->scope) . ' discarded candidate config',
            );
        }
    }

    public function candidatePath(): string
    {
        return $this->activeConfig
            . '.candidate.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
    }

    private function assertCandidatePath(string $candidate): void
    {
        $prefix = $this->activeConfig . '.candidate.';
        if (\dirname($candidate) !== \dirname($this->activeConfig)
            || !\str_starts_with($candidate, $prefix)
            || \preg_match(
                '/\A[1-9][0-9]*\.[a-f0-9]{8}\z/D',
                \substr($candidate, \strlen($prefix)),
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                \ucfirst($this->scope) . ' candidate path is outside the isolated config scope.'
            );
        }
    }

    private function assertTransactionId(string $transactionId): void
    {
        if (\preg_match('/\A[a-f0-9]{32}\z/D', \strtolower(\trim($transactionId))) !== 1) {
            throw new \InvalidArgumentException(\ucfirst($this->scope) . ' transaction id is invalid.');
        }
    }

    private function transactionIdFromRollback(string $rollback): string
    {
        $prefix = $this->activeConfig . '.rollback.';
        if (\dirname($rollback) !== \dirname($this->activeConfig)
            || !\str_starts_with($rollback, $prefix)
        ) {
            throw new \InvalidArgumentException($this->scope . ' rollback path is invalid.');
        }
        $transactionId = \substr($rollback, \strlen($prefix));
        $this->assertTransactionId($transactionId);
        return $transactionId;
    }

    private function assertRegularFile(string $file, string $kind): void
    {
        // Reuse the sealed state-file reader so interrupted recovery only
        // continues against a regular, size-bounded config path.
        $this->readRegularFile($file, $kind);
    }

    private function readRegularFile(string $file, string $kind): string
    {
        return GatewayProjectStateFilesystem::read(
            $file,
            self::MAX_CONFIG_BYTES,
            \ucfirst($this->scope) . ' ' . $kind,
        );
    }

    private function lastGoodPath(): string
    {
        return $this->activeConfig . '.last-good';
    }

    private function writeNewFile(string $file, string $contents, int $mode): void
    {
        if (\file_exists($file) || \is_link($file)) {
            throw new \RuntimeException('Refusing to overwrite an existing ' . $this->scope . ' staging file.');
        }
        GatewayProjectStateFilesystem::atomicWrite($file, $contents, $mode);
    }

    private function replaceFile(string $target, string $contents, int $mode): void
    {
        GatewayProjectStateFilesystem::atomicWrite($target, $contents, $mode);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return \preg_match('/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+)/D', $path) === 1;
        }
        return \str_starts_with($path, '/');
    }

    private function samePath(string $left, string $right): bool
    {
        $left = \rtrim(\str_replace('\\', '/', $left), '/');
        $right = \rtrim(\str_replace('\\', '/', $right), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $left = \strtolower($left);
            $right = \strtolower($right);
        }
        return \hash_equals($left, $right);
    }
}
