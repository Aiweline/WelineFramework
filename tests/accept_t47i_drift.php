<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DEV', false);
define('PROD', true);
require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Framework\Database\Schema\SchemaCheckpointDriftInspector;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$source = (string)file_get_contents(BP . 'app/code/Weline/Framework/Database/Schema/SchemaCheckpointDriftInspector.php');
$check(!str_contains($source, 'SchemaDiffStage'), 'drift inspector must not depend on SchemaDiffStage');
$check(!str_contains($source, 'Setup\\Model\\Migration'), 'drift inspector must not depend on legacy Migration model');
$check(str_contains($source, 'SchemaManifestBuilder'), 'drift inspector must use SchemaManifestBuilder');
$check(str_contains($source, 'SchemaCheckpoint'), 'drift inspector must compare SchemaCheckpoint rows');

$inspector = new SchemaCheckpointDriftInspector();
$result = $inspector->inspect();
$check(isset($result['clean'], $result['drifts'], $result['checked_modules']), 'inspector result shape');
$check(is_array($result['drifts']), 'drifts must be array');
$check($result['checked_modules'] > 0, 'inspector must check modules');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T47I DRIFT-INSPECTOR PASS' . PHP_EOL;
