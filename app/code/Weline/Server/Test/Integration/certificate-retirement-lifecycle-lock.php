<?php
declare(strict_types=1);

namespace {
    define('WLS_REPLAY_TEST_VAR', sys_get_temp_dir() . '/wls-replay-lock-' . bin2hex(random_bytes(8)) . '/');
}

namespace Weline\Framework\App {
    final class Env { public const VAR_DIR = WLS_REPLAY_TEST_VAR; }
}

namespace Weline\Framework\Runtime {
    final class SchedulerSystem {
        public static function usleep(int $microseconds): void { \usleep($microseconds); }
    }
}

namespace Weline\Server\Service\Edge\Gateway {
    // 仅隔离实例目录和证书存储；生命周期锁仍走真实文件锁实现。
    final class GatewayProjectEndpointReader {
        public function all(?float $deadline = null): array { return ['replay-a' => [], 'replay-b' => []]; }
    }
    final class ProjectCertificateGenerationStore {
        public static int $entered = 0;
        public static bool $fail = false;
        public function withRetirementReplayLease(\Closure $work, float $deadline): array {
            ++self::$entered;
            if (self::$fail) { throw new \RuntimeException('storage failure'); }
            return $work();
        }
        public function pendingRetirementBatch(int $limit, float $deadline): array { return []; }
    }
}

namespace {
    $serviceRoot = dirname(__DIR__, 2) . '/Service/';
    require $serviceRoot . 'Edge/Gateway/GatewayProjectStateFilesystem.php';
    require $serviceRoot . 'Runtime/VerifiedPersistentFileLock.php';
    require $serviceRoot . 'Runtime/ServerLifecycleOperationLock.php';
    require $serviceRoot . 'SslCertificateService.php';

    $service = (new ReflectionClass(\Weline\Server\Service\SslCertificateService::class))->newInstanceWithoutConstructor();
    $paths = [];
    foreach (['replay-a', 'replay-b'] as $name) {
        $paths[] = \Weline\Server\Service\Runtime\ServerLifecycleOperationLock::pathForInstance($name);
    }
    mkdir(dirname($paths[0]), 0755, true);
    $busy = fopen($paths[1], 'x+b');
    chmod($paths[1], 0600);
    flock($busy, LOCK_EX);
    $check = static function (bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    };
    $released = static function (string $path) use ($check): void {
        $handle = fopen($path, 'c+b');
        try { $check(flock($handle, LOCK_EX | LOCK_NB), 'lifecycle lock leaked'); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    };
    try {
        $result = $service->replayPendingCertificateRetirements(2, 2);
        $check($result === ['attempted' => 0, 'completed' => 0, 'failures' => [], 'deferred' => true], 'busy reload must defer certificate replay');
        $check(\Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore::$entered === 0, 'busy replay entered certificate transaction');
        $released($paths[0]);
        flock($busy, LOCK_UN);
        fclose($busy);
        $busy = null;
        $result = $service->replayPendingCertificateRetirements(2, 2);
        $check($result['deferred'] === false, 'replay did not resume after reload released its lock');
        foreach ($paths as $path) { $released($path); }
        \Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore::$fail = true;
        try {
            $service->replayPendingCertificateRetirements(2, 2);
            throw new RuntimeException('storage exception was swallowed');
        } catch (RuntimeException $error) {
            $check($error->getMessage() === 'storage failure', $error->getMessage());
        }
        foreach ($paths as $path) { $released($path); }
        echo "PASS: busy replay deferred; replay resumed; locks released on partial acquisition, success and failure\n";
    } finally {
        if (is_resource($busy)) { flock($busy, LOCK_UN); fclose($busy); }
        foreach ($paths as $path) { if (is_file($path)) { unlink($path); } }
        rmdir(dirname($paths[0]));
        rmdir(WLS_REPLAY_TEST_VAR . 'server');
        rmdir(WLS_REPLAY_TEST_VAR);
    }
}
