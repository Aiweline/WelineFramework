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

use Weline\Framework\Database\Release\Api\DatabaseReleaseCoordinatorInterface;
use Weline\Framework\Setup\Stage\ReleaseDatabaseStage;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$fake = new class implements DatabaseReleaseCoordinatorInterface {
    public array $applied = [];
    public function inspect(string $releaseId): array { return []; }
    public function plan(string $releaseId, array $options = []): array { return []; }
    public function apply(string $releaseId, array $options = []): array
    {
        $this->applied[] = [$releaseId, $options];
        return ['release_id' => $releaseId, 'status' => 'applied', 'expanded' => 1];
    }
    public function rollback(string $releaseId, string $targetReleaseId, array $options = []): array { return []; }
};

$stage = new ReleaseDatabaseStage($fake);
$check($stage->getName() === 'release_database', 'stage name');
$check(!$stage->isPrepared(), 'not prepared initially');
$stage->prepare(['operation_id' => 'op-test']);
$check($stage->isPrepared(), 'prepared');
$stage->commit();
$check($stage->isCommitted(), 'committed');
$check(count($fake->applied) === 1, 'coordinator apply must be called once');
[$releaseId, $options] = $fake->applied[0];
$check($releaseId === 'setup-op-test', 'release id must derive from operation id');
$check(($options['operation_id'] ?? '') === 'op-test', 'operation id passthrough');
$check(str_starts_with((string)($options['signature'] ?? ''), 'local-'), 'local signature marker');
$check(($stage->getLastResult()['status'] ?? '') === 'applied', 'last result');
$check($stage->wasSourceFingerprintSkipped() === false, 'fingerprint skip must be false');

$blocked = new ReleaseDatabaseStage($fake);
$blocked->prepare([]);
$threw = false;
try {
    $blocked->commit();
} catch (Throwable) {
    $threw = true;
}
$check($threw && !$blocked->isCommitted(), 'missing operation id must fail closed');


$upgradeSource = (string)file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(
    str_contains($upgradeSource, 'ObjectManager::make(ReleaseDatabaseStage::class)')
        && !str_contains($upgradeSource, 'SchemaDiffStage::class'),
    'Upgrade.php 必须始终使用 ReleaseDatabaseStage，且不再保留旧 SchemaDiffStage 分支'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T47B STAGE PASS' . PHP_EOL;
