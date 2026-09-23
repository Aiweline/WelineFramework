<?php
declare(strict_types=1);

namespace Weline\Server\Service;

// 独立进程中把翻译入口设为陷阱，避免测试本身访问项目数据库。
function __(mixed ...$arguments): never
{
    throw new \RuntimeException('SIGCHLD reaper entered the translation/database path');
}

require dirname(__DIR__, 2) . '/Service/MasterProcess.php';

if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    fwrite(STDOUT, "SKIP: POSIX process support required\n");
    exit(0);
}

$masterClass = new class extends MasterProcess {
    public function __construct() {}

    protected function log(string $message, string $level = 'info'): void
    {
        throw new \RuntimeException('SIGCHLD reaper entered the logging path');
    }
};
$reap = new \ReflectionMethod(MasterProcess::class, 'reapExitedChildren');
$failure = null;
$callbacks = 0;
pcntl_async_signals(true);
pcntl_signal(SIGCHLD, static function () use ($reap, $masterClass, &$failure, &$callbacks): void {
    ++$callbacks;
    try {
        $reap->invoke($masterClass);
    } catch (\Throwable $error) {
        $failure = $error->getMessage();
    }
});

$children = [];
for ($index = 0; $index < 3; ++$index) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new \RuntimeException('fork failed');
    }
    if ($pid === 0) {
        usleep(20000 * ($index + 1));
        if ($index === 2) {
            posix_kill(getmypid(), SIGTERM);
        }
        exit($index === 1 ? 7 : 0);
    }
    $children[] = $pid;
}
$deadline = hrtime(true) + 3_000_000_000;
do {
    usleep(1000);
    $remaining = array_filter($children, static fn(int $pid): bool => posix_kill($pid, 0));
} while ($remaining !== [] && $failure === null && hrtime(true) < $deadline);

pcntl_signal(SIGCHLD, SIG_DFL);
if ($failure !== null || $remaining !== [] || $callbacks === 0) {
    foreach ($remaining as $pid) {
        posix_kill($pid, SIGKILL);
    }
    while (pcntl_waitpid(-1, $status) > 0) {}
    fwrite(STDERR, 'FAIL: ' . ($failure ?? 'children were not reaped') . "\n");
    exit(1);
}
if (pcntl_waitpid(-1, $status, WNOHANG) !== -1) {
    fwrite(STDERR, "FAIL: unreaped child remains\n");
    exit(1);
}
fwrite(STDOUT, "PASS: normal and signaled children reaped without translation or logging\n");
