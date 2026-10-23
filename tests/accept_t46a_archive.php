<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DS', DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Model\Release\BackupObject;
use Weline\Database\Model\Release\BackupSet;
use Weline\Database\Model\Release\SchemaSnapshot;
use Weline\Database\Service\Release\BackupCatalog;
use Weline\Database\Service\Release\DatabaseSnapshotService;
use Weline\Database\Service\Release\LegacyTableArchiver;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\SchemaParser;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$tmp = sys_get_temp_dir() . DS . 'weline_t46a_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
try {
    $config = new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmp,
        'persistent' => false,
    ]);
    $factory = ConnectionFactory::getInstance($config);
    $connector = $factory->getConnector();

    (new ReleaseTableInstaller(new SchemaParser(), $factory))->install();
    $link = $connector->getLink();
    $link->exec('CREATE TABLE legacy_old (id INTEGER PRIMARY KEY, name TEXT)');
    $link->exec("INSERT INTO legacy_old (id, name) VALUES (1, 'old')");

    $catalog = new BackupCatalog($factory, new BackupSet(), new BackupObject());
    $snapshotService = new DatabaseSnapshotService($factory, new SchemaSnapshot(), $catalog);
    $scope = new SchemaScope('default', 't46a', 'main');
    $snapshot = $snapshotService->create('rel-t46a-' . getmypid(), $scope, ['operation_id' => 'op-t46a']);

    $archiver = new LegacyTableArchiver($factory, $snapshotService);
    $mapping = $archiver->archive(['legacy_old'], (string)$snapshot['snapshot_key'], '20261023');
    $check(($mapping['legacy_old'] ?? '') === 'legacy_old_legacy_20261023', 'legacy table must be renamed');
    $check(!$connector->tableExist('legacy_old'), 'original legacy table must not exist');
    $check($connector->tableExist('legacy_old_legacy_20261023'), 'archived legacy table must exist');

    $blocked = false;
    try {
        $archiver->archive(['legacy_old_legacy_20261023'], 'missing-snapshot', '20261024');
    } catch (Throwable) {
        $blocked = true;
    }
    $check($blocked, 'archive without verified snapshot must throw');

    $link->exec('DROP TABLE IF EXISTS legacy_old_legacy_20261023');
    $link = null;
} catch (Throwable $exception) {
    $failures[] = 'exception: ' . $exception->getMessage();
} finally {
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm', $tmp . '-journal'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T46A ARCHIVE PASS' . PHP_EOL;
