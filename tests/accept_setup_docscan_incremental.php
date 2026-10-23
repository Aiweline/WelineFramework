<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$scanner = (string) file_get_contents(BP . 'app/code/Weline/DeveloperWorkspace/Service/DocumentScanner.php');
$check(str_contains($scanner, '?array $moduleNames = null'), 'scanAllModules must accept module scope');
$observer = (string) file_get_contents(BP . 'app/code/Weline/DeveloperWorkspace/Observer/ModuleUpgradeObserver.php');
$check(str_contains($observer, "changed_modules"), 'observer must read changed_modules');
$check(str_contains($observer, 'scanAllModules(false'), 'observer must pass module scope');
$upgrade = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(str_contains($upgrade, "'changed_modules'"), 'upgrade_after payload must carry changed_modules');
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'ACCEPT PASS', PHP_EOL;
