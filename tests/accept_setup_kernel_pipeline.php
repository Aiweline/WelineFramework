<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Weline\\')) {
        return;
    }
    $path = BP . 'app/code/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
require_once BP . 'app/code/Weline/Framework/Setup/Kernel/UpgradeSession.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

foreach (['StageResult', 'UpgradeStageInterface', 'StagePipeline'] as $name) {
    $class = 'Weline\\Framework\\Setup\\Kernel\\' . $name;
    if (!interface_exists($class) && !class_exists($class)) {
        fwrite(STDERR, 'missing ' . $name . PHP_EOL);
        exit(1);
    }
}

$root = '/tmp/weline-kernel-pipeline-' . bin2hex(random_bytes(4));
$session = new \Weline\Framework\Setup\Kernel\UpgradeSession('pipe', $root);
$session->begin();
$pipeline = new \Weline\Framework\Setup\Kernel\StagePipeline($session);

$trace = [];
$make = static function (string $key, array $requires, string $status, array &$trace) {
    return new class($key, $requires, $status, $trace) implements \Weline\Framework\Setup\Kernel\UpgradeStageInterface {
        private array $trace;
        public function __construct(
            private string $k,
            private array $req,
            private string $s,
            array &$trace,
        ) {
            $this->trace = &$trace;
        }
        public function key(): string { return $this->k; }
        public function requires(): array { return $this->req; }
        public function shouldRun(): bool { return $this->s !== 'skipped'; }
        public function run(): \Weline\Framework\Setup\Kernel\StageResult
        {
            $this->trace[] = $this->k;
            if ($this->s === 'failed') {
                return new \Weline\Framework\Setup\Kernel\StageResult('failed', $this->k . ' failed');
            }
            return new \Weline\Framework\Setup\Kernel\StageResult('ok', $this->k . ' ok');
        }
    };
};

$pipeline->add($make('c', ['b'], 'ok', $trace));
$pipeline->add($make('a', [], 'ok', $trace));
$pipeline->add($make('b', ['a'], 'ok', $trace));
$exit = $pipeline->run();
$check($exit === 0, 'successful pipeline must return 0, got ' . var_export($exit, true));
$check($trace === ['a', 'b', 'c'], 'pipeline must respect requires order, got ' . json_encode($trace));
$report = json_decode((string) file_get_contents($session->reportPath()), true);
$check(is_array($report) && count($report['stages'] ?? []) === 3, 'pipeline must record 3 stages in session');

// skip 不执行
$trace2 = [];
$p2 = new \Weline\Framework\Setup\Kernel\StagePipeline($session);
$p2->add($make('x', [], 'skipped', $trace2));
$p2->add($make('y', [], 'ok', $trace2));
$check($p2->run() === 0, 'skip pipeline must return 0');
$check($trace2 === ['y'], 'skipped stage must not run, got ' . json_encode($trace2));

// fatal 停止后续
$trace3 = [];
$p3 = new \Weline\Framework\Setup\Kernel\StagePipeline($session);
$p3->add($make('f', [], 'failed', $trace3));
$p3->add($make('g', [], 'ok', $trace3));
$check($p3->run() === 1, 'failed pipeline must return 1');
$check($trace3 === ['f'], 'fatal stage must stop the pipeline, got ' . json_encode($trace3));

// 缺依赖抛异常
$trace4 = [];
$p4 = new \Weline\Framework\Setup\Kernel\StagePipeline($session);
$p4->add($make('h', ['missing'], 'ok', $trace4));
try {
    $p4->run();
    $failures[] = 'missing dependency must throw';
} catch (\Throwable) {
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($root);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
