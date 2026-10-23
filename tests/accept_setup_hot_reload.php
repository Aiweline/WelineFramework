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

$interfaceFile = BP . 'app/code/Weline/Framework/Runtime/RuntimeControlBroadcasterInterface.php';
$implementationFile = BP . 'app/code/Weline/Server/Api/Runtime/RuntimeControlBroadcaster.php';
$upgradeFile = BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php';

$interfaceSrc = (string) file_get_contents($interfaceFile);
$check(str_contains($interfaceSrc, 'public function reloadAsync('), 'runtime broadcaster interface must declare reloadAsync');

$implSrc = (string) file_get_contents($implementationFile);
$check(str_contains($implSrc, 'public function reloadAsync('), 'server runtime broadcaster must implement reloadAsync');
$check(str_contains($implSrc, 'BroadcastControlDispatchService'), 'server implementation must delegate to BroadcastControlDispatchService');

$upgradeSrc = (string) file_get_contents($upgradeFile);
$start = strpos($upgradeSrc, 'private function executeHotReload');
$check($start !== false, 'Upgrade::executeHotReload missing');
$body = $start === false ? '' : substr($upgradeSrc, $start, 1800);
$check(!str_contains($body, 'instances'), 'executeHotReload must not read wls.instances');
$check(str_contains($body, 'RuntimeControlBroadcasterInterface'), 'executeHotReload must resolve the runtime broadcaster interface');
$check(str_contains($body, 'reloadAsync'), 'executeHotReload must call reloadAsync');
$check(str_contains($body, 'throw new Exception') || str_contains($body, 'CommandResult'), 'executeHotReload must surface failure');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
