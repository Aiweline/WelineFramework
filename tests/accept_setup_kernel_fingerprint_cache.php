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
require_once BP . 'app/code/Weline/Framework/Setup/Service/SetupSourceFingerprint.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$class = \Weline\Framework\Setup\Service\SetupSourceFingerprint::class;
if (!property_exists($class, 'hashReads')) {
    fwrite(STDERR, 'missing hashReads counter' . PHP_EOL);
    exit(1);
}

$root = '/tmp/weline-fingerprint-' . bin2hex(random_bytes(4));
mkdir($root . '/Model', 0775, true);
file_put_contents($root . '/Model/A.php', '<?php class A {}');
file_put_contents($root . '/Model/B.php', '<?php class B {}');
file_put_contents($root . '/Model/C.php', '<?php class C {}');

$fp = new $class();
$reads0 = $class::$hashReads;
$hash1 = $fp->fingerprintTree($root . '/Model');
$reads1 = $class::$hashReads;
$check($reads1 - $reads0 === 3, 'first fingerprint must hash 3 files, got ' . ($reads1 - $reads0));

$hash2 = $fp->fingerprintTree($root . '/Model');
$reads2 = $class::$hashReads;
$check($hash2 === $hash1, 'same tree must produce the same fingerprint');
$check($reads2 === $reads1, 'second fingerprint must reuse cache (0 extra reads), got ' . ($reads2 - $reads1));

// 修改一个文件内容并推进 mtime
sleep(1);
file_put_contents($root . '/Model/B.php', '<?php class B { public int $x = 1; }');
touch($root . '/Model/B.php', time() + 2);
$fp->fingerprintTree($root . '/Model');
$reads3 = $class::$hashReads;
$check($reads3 - $reads2 === 1, 'changed file must be re-hashed once, got ' . ($reads3 - $reads2));

foreach (['A', 'B', 'C'] as $name) {
    @unlink($root . '/Model/' . $name . '.php');
}
@rmdir($root . '/Model');
@rmdir($root);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
