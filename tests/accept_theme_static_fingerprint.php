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

$class = 'Weline\\Theme\\Service\\StaticPublishFingerprint';
if (!class_exists($class)) {
    fwrite(STDERR, 'missing StaticPublishFingerprint' . PHP_EOL);
    exit(1);
}

$root = '/tmp/weline-static-fp-' . bin2hex(random_bytes(4));
$fp = new $class($root);

$inputs = ['version' => 1, 'themes' => ['a' => '1.0.0'], 'sources' => ['x' => 1]];
$hash1 = $fp->compute($inputs);
$check(is_string($hash1) && strlen($hash1) === 64, 'compute must return sha256');
$check($hash1 === $fp->compute($inputs), 'compute must be stable');
$check($hash1 !== $fp->compute(['version' => 2] + $inputs), 'compute must change with input');

$check($fp->matches('assets', $hash1) === false, 'matches must be false before remember');
$fp->remember('assets', $hash1);
$check($fp->matches('assets', $hash1) === true, 'matches must be true after remember');
$check($fp->matches('not_found', $hash1) === false, 'kinds must be isolated');
$check(is_file($fp->path('assets')), 'fingerprint file must exist');
$check($fp->matches('assets', 'deadbeef') === false, 'different fingerprint must not match');

file_put_contents($fp->path('broken'), 'not-json');
$check($fp->matches('broken', $hash1) === false, 'broken store must not match');

try {
    $fp->matches('', $hash1);
    $failures[] = 'empty kind must throw';
} catch (\Throwable) {
}

exec('rm -rf ' . escapeshellarg($root));

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
