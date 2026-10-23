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

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$class = 'Weline\\Framework\\Setup\\Kernel\\ChangeSet';
if (!class_exists($class)) {
    fwrite(STDERR, 'missing ChangeSet' . PHP_EOL);
    exit(1);
}

$previous = [
    'Weline_A' => ['version' => '1.0.0', 'status' => true, 'fingerprint' => 'a1'],
    'Weline_B' => ['version' => '2.0.0', 'status' => true, 'fingerprint' => 'b1'],
    'Weline_C' => ['version' => '3.0.0', 'status' => true, 'fingerprint' => 'c1'],
    'Weline_D' => ['version' => '4.0.0', 'status' => true, 'fingerprint' => 'd1'],
];
$current = [
    'Weline_A' => ['version' => '1.0.0', 'status' => true, 'fingerprint' => 'a1'],      // unchanged
    'Weline_B' => ['version' => '2.1.0', 'status' => true, 'fingerprint' => 'b1'],      // updated (version)
    'Weline_C' => ['version' => '3.0.0', 'status' => false, 'fingerprint' => 'c1'],     // disabled
    'Weline_E' => ['version' => '5.0.0', 'status' => true, 'fingerprint' => 'e1'],      // added
    // Weline_D removed
];

$cs = new $class($previous, $current, ['Weline_E']);
$modules = $cs->modules();
$check(($modules['added'] ?? []) === ['Weline_E'], 'added must contain Weline_E, got ' . json_encode($modules['added'] ?? null));
$check(($modules['updated'] ?? []) === ['Weline_B'], 'updated must contain Weline_B');
$check(($modules['disabled'] ?? []) === ['Weline_C'], 'disabled must contain Weline_C');
$check(($modules['removed'] ?? []) === ['Weline_D'], 'removed must contain Weline_D');
$check(($modules['unchanged'] ?? []) === ['Weline_A'], 'unchanged must contain Weline_A');

$needs = $cs->needs();
foreach (['schema', 'route', 'menu', 'compile'] as $need) {
    $check(($needs[$need] ?? false) === true, 'needs.' . $need . ' must be true when modules changed');
}
$check(($needs['i18n'] ?? false) === true, 'needs.i18n must be true when modules added/updated');

$reasons = $cs->reasons();
$check(isset($reasons['module:Weline_E']), 'reasons must explain added module');
$check(isset($reasons['module:Weline_B']), 'reasons must explain updated module');
$check(isset($reasons['module:Weline_D']), 'reasons must explain removed module');
$check(str_contains((string)($reasons['module:Weline_B'] ?? ''), 'version'), 'updated reason must mention version');
$check(str_contains((string)($reasons['module:Weline_C'] ?? ''), 'status'), 'disabled reason must mention status');

// 无变化
$cs2 = new $class($current, $current, []);
$check($cs2->isEmpty() === true, 'identical snapshots must be empty');
foreach (['schema', 'route', 'menu', 'i18n', 'compile'] as $need) {
    $check(($cs2->needs()[$need] ?? true) === false, 'empty change set must not need ' . $need);
}

// 仅 pending
$cs3 = new $class($current, $current, ['Weline_A']);
$check($cs3->isEmpty() === false, 'pending-only change set must not be empty');
$check($cs3->modules()['added'] === [] && $cs3->modules()['updated'] === [], 'pending must not fake module add/update');
$check(($cs3->needs()['route'] ?? false) === true, 'pending modules must force registry/route refresh');

// 指纹变化（版本不变）
$currentFp = $current;
$currentFp['Weline_A']['fingerprint'] = 'a2';
$cs4 = new $class($current, $currentFp, []);
$check(($cs4->modules()['updated'] ?? []) === ['Weline_A'], 'fingerprint change must mark module updated');
$check(str_contains((string)($cs4->reasons()['module:Weline_A'] ?? ''), 'fingerprint'), 'fingerprint reason must be recorded');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
