<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');

if (!preg_match('/private function routeArtifactsPresent\(\): bool\s*\{(.*?)\n    \}/s', $src, $m)) {
    $failures[] = 'routeArtifactsPresent() not found';
    $body = '';
} else {
    $body = $m[1];
}
$check(!str_contains($body, '>= 2'), 'routeArtifactsPresent must not accept any 2 route files');
$check(str_contains($body, 'path_BACKEND_PC_ROUTER_FILE'), 'routeArtifactsPresent must check backend PC router');
$check(str_contains($body, 'path_FRONTEND_PC_ROUTER_FILE'), 'routeArtifactsPresent must check frontend PC router');

if (!preg_match('/private function resolveRouteFingerprintPlan\([^)]*\): array\s*\{(.*?)\n    \}/s', $src, $m2)) {
    $failures[] = 'resolveRouteFingerprintPlan() not found';
    $plan = '';
} else {
    $plan = $m2[1];
}
$check(str_contains($plan, 'status='), 'route fingerprint must include module status');
$check(str_contains($plan, 'total='), 'route fingerprint must include module set size');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
