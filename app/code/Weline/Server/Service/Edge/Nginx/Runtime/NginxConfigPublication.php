<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

/**
 * Shared candidate/active/rollback transaction for managed Nginx configs.
 *
 * Candidates and rollback files always remain beside the active file, keeping
 * every rename on one filesystem. Callers must hold their lifecycle lock.
 */
final class NginxConfigPublication
{
    public function __construct(
        private readonly string $activeConfig,
        private readonly string $scope = 'managed nginx',
    ) {
        if (\basename($activeConfig) === '' || \dirname($activeConfig) === '.') {
            throw new \InvalidArgumentException('Nginx active config path must be absolute.');
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
        $this->assertRegularFile($candidate, 'candidate');
    }

    /** @return array{conf:string,rollback:string|null} */
    public function publishCandidate(string $candidate, string $transactionId): array
    {
        $this->validateCandidate($candidate);
        $this->assertTransactionId($transactionId);
        $active = $this->activeConfig;
        $rollback = null;
        if (\file_exists($active)) {
            $this->assertRegularFile($active, 'active config');
            $rollback = $this->rollbackPathForTransaction($transactionId);
            if (\file_exists($rollback)) {
                throw new \RuntimeException($this->scope . ' transaction rollback already exists.');
            }
            if (!@\rename($active, $rollback)) {
                throw new \RuntimeException('Unable to preserve the active ' . $this->scope . ' config.');
            }
        }
        if (!@\rename($candidate, $active)) {
            if ($rollback !== null) {
                @\rename($rollback, $active);
            }
            throw new \RuntimeException('Unable to publish the ' . $this->scope . ' candidate config.');
        }
        @\chmod($active, 0600);

        return ['conf' => $active, 'rollback' => $rollback];
    }

    public function rollbackPublished(?string $rollback): void
    {
        if ($rollback !== null) {
            if ($rollback !== $this->rollbackPathForTransaction($this->transactionIdFromRollback($rollback))) {
                throw new \InvalidArgumentException($this->scope . ' rollback path is outside its config scope.');
            }
            $this->assertRegularFile($rollback, 'rollback');
        }
        $active = $this->activeConfig;
        $rejected = null;
        if (\file_exists($active)) {
            $this->assertRegularFile($active, 'active config');
            $rejected = $active . '.rejected.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
            if (!@\rename($active, $rejected)) {
                throw new \RuntimeException(
                    'Unable to preserve the rejected ' . $this->scope . ' config during rollback.'
                );
            }
        }
        if ($rollback !== null && !@\rename($rollback, $active)) {
            if ($rejected !== null) {
                @\rename($rejected, $active);
            }
            throw new \RuntimeException('Unable to restore the previous ' . $this->scope . ' config.');
        }
        if ($rejected !== null) {
            @\unlink($rejected);
        }
    }

    public function recoverInterruptedPublication(): void
    {
        if (\file_exists($this->activeConfig)) {
            $this->assertRegularFile($this->activeConfig, 'active config');
            return;
        }
        $lastGood = $this->lastGoodPath();
        if (!\is_file($lastGood) || \is_link($lastGood)) {
            return;
        }
        $contents = @\file_get_contents($lastGood);
        if (!\is_string($contents) || $contents === '') {
            throw new \RuntimeException($this->scope . ' last-known-good config is unreadable.');
        }
        $candidate = $this->stageCandidate($contents);
        if (!@\rename($candidate, $this->activeConfig)) {
            @\unlink($candidate);
            throw new \RuntimeException('Unable to recover the last-known-good ' . $this->scope . ' config.');
        }
        @\chmod($this->activeConfig, 0600);
    }

    public function rollbackPathForTransaction(string $transactionId): string
    {
        $this->assertTransactionId($transactionId);
        return $this->activeConfig . '.rollback.' . \strtolower($transactionId);
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
            $this->assertRegularFile($rollback, 'rollback');
            $contents = @\file_get_contents($rollback);
            if (!\is_string($contents) || $contents === '') {
                return false;
            }
            $this->replaceFile($this->lastGoodPath(), $contents, 0600);
            return @\unlink($rollback) || !\file_exists($rollback);
        } catch (\Throwable) {
            return false;
        }
    }

    public function discardCandidate(string $candidate): void
    {
        $this->assertCandidatePath($candidate);
        if (\is_file($candidate) && !\is_link($candidate)) {
            @\unlink($candidate);
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
        if (!\is_file($file) || \is_link($file)) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' ' . $kind . ' is missing or unsafe.'
            );
        }
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
        $handle = @\fopen($file, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to create the ' . $this->scope . ' staging file.');
        }
        try {
            $remaining = $contents;
            while ($remaining !== '') {
                $written = @\fwrite($handle, $remaining);
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException('Unable to write the ' . $this->scope . ' staging file.');
                }
                $remaining = (string)\substr($remaining, $written);
            }
            if (!@\fflush($handle)) {
                throw new \RuntimeException('Unable to flush the ' . $this->scope . ' staging file.');
            }
            if (\function_exists('fsync')) {
                @\fsync($handle);
            }
        } catch (\Throwable $throwable) {
            @\fclose($handle);
            @\unlink($file);
            throw $throwable;
        }
        @\fclose($handle);
        @\chmod($file, $mode);
    }

    private function replaceFile(string $target, string $contents, int $mode): void
    {
        if (\is_link($target) || (\file_exists($target) && !\is_file($target))) {
            throw new \RuntimeException('Refusing to replace an unsafe ' . $this->scope . ' file.');
        }
        $temporary = $target . '.candidate.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
        $this->writeNewFile($temporary, $contents, $mode);
        $previous = null;
        if (\is_file($target)) {
            $previous = $target . '.previous.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
            if (!@\rename($target, $previous)) {
                @\unlink($temporary);
                throw new \RuntimeException('Unable to preserve the existing ' . $this->scope . ' file.');
            }
        }
        if (!@\rename($temporary, $target)) {
            if ($previous !== null) {
                @\rename($previous, $target);
            }
            @\unlink($temporary);
            throw new \RuntimeException('Unable to replace the ' . $this->scope . ' file.');
        }
        @\chmod($target, $mode);
        if ($previous !== null) {
            @\unlink($previous);
        }
    }
}
