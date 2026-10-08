<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

/**
 * Rolling reload can republish PID/name indexes while exact lease files remain
 * authoritative. Same-identity path drift must not erase the exact lease.
 */
final class ProcesserLeaseIndexPathDriftTest extends TestCase
{
    public function testSameIdentityPidIndexPathDriftKeepsExactLease(): void
    {
        if (\defined('IS_WIN') && IS_WIN) {
            self::markTestSkipped('POSIX managed lease publication is not used on Windows.');
        }

        $name = 'weline-test-lease-drift-' . \bin2hex(\random_bytes(4));
        $launchId = \bin2hex(\random_bytes(16));
        $identity = '--name=' . $name . ' --launch-id=' . $launchId;
        $releasePath = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline-test-lease-drift-release-' . \bin2hex(\random_bytes(4));
        $pid = 0;

        try {
            $pid = Processer::createDetachedPhpArgv([
                PHP_BINARY,
                '-r',
                'while (!is_file(' . \var_export($releasePath, true)
                    . ')) { usleep(10000); }',
                '--',
                '--name=' . $name,
                '--launch-id=' . $launchId,
            ], BP, $identity, false);

            self::assertGreaterThan(0, $pid);
            $lease = Processer::getManagedProcessLeaseRecord($pid, $identity);
            self::assertNotSame([], $lease, 'baseline exact lease must resolve');

            $jsonPath = Processer::getPidFile($identity, $pid);
            $pidIndexFile = (new \ReflectionMethod(Processer::class, 'getPidIndexFile'))
                ->invoke(null);
            $indexStateValid = (new \ReflectionClassConstant(Processer::class, 'INDEX_STATE_VALID'))
                ->getValue();

            $driftPayload = [
                'state' => $indexStateValid,
                'data' => [
                    (string)$pid => [
                        'pname' => $identity,
                        'jsonPath' => $jsonPath . '.stale-publication-path',
                    ],
                ],
            ];
            self::assertNotFalse(
                \file_put_contents(
                    $pidIndexFile,
                    \json_encode($driftPayload, JSON_UNESCAPED_SLASHES) . "\n"
                )
            );

            $after = Processer::getManagedProcessLeaseRecord($pid, $identity);
            self::assertNotSame(
                [],
                $after,
                'Same-identity PID index path drift must keep the exact lease.'
            );
            self::assertSame($pid, (int)($after['pid'] ?? 0));
            self::assertSame($launchId, (string)($after['launch_id'] ?? ''));
        } finally {
            if ($pid > 0) {
                @\touch($releasePath);
                Processer::waitForExit([$pid], 2.0);
                Processer::removeManagedProcessLeaseRecord($pid, $name, $launchId);
            }
            @\unlink($releasePath);
        }
    }
}
