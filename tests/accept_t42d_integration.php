<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DS', DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaJournal;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\SchemaParser;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$expectedTables = [
    'weline_schema_release',
    'weline_schema_checkpoint',
    'weline_schema_operation_event',
    'weline_schema_change',
    'weline_schema_scope',
    'weline_schema_table_owner',
    'weline_schema_data_transform_progress',
    'weline_schema_backup_set',
    'weline_schema_backup_object',
    'weline_schema_snapshot',
    'weline_schema_physical_state',
];

$tmp = sys_get_temp_dir() . DS . 'weline_t42d_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
$factory = null;
try {
    $config = new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmp,
        'persistent' => false,
    ]);
    $factory = ConnectionFactory::getInstance($config);

    $installer = new ReleaseTableInstaller(new SchemaParser(), $factory);
    $result = $installer->install();

    $created = array_values($result['created']);
    $check(count($created) === 11, 'installer must create 11 tables, got ' . count($created));
    $check($created === $expectedTables, 'installer created unexpected table list: ' . implode(',', $created));
    $check($result['existing'] === [], 'first install must not report existing tables');
    foreach ($created as $table) {
        $check(str_starts_with($table, 'weline_schema_'), 'created table must use weline_schema_ prefix: ' . $table);
    }

    $link = $factory->getConnector()->getLink();
    $physical = $link
        ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'weline_schema_%' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
    $check(count($physical) === 11, 'sqlite_master must contain 11 weline_schema_ tables, got ' . count($physical));

    $again = $installer->install();
    $check($again['created'] === [], 'second install must not create tables again');
    $check(count($again['existing']) === 11, 'second install must report 11 existing tables');

    $journal = new SchemaJournal($factory, new SchemaOperationEvent());
    $plannedId = $journal->append([
        'release_id' => 'rel-t42d',
        'operation_id' => 'op-t42d',
        'scope_key' => 'scope-default',
        'node_id' => 'Weline_Demo:1.0.0:1:1:demo_table',
        'state' => SchemaOperationEvent::STATE_PLANNED,
        'module_name' => 'Weline_Demo',
        'table_name' => 'demo_table',
        'detail_json' => '{"phase":"plan"}',
    ]);
    $expandedId = $journal->append([
        'release_id' => 'rel-t42d',
        'operation_id' => 'op-t42d',
        'scope_key' => 'scope-default',
        'node_id' => 'Weline_Demo:1.0.0:1:1:demo_table',
        'state' => SchemaOperationEvent::STATE_EXPANDED,
    ]);
    $check($plannedId > 0, 'append must return a positive event id');
    $check($expandedId > $plannedId, 'event ids must grow monotonically');

    $events = $journal->events('rel-t42d');
    $check(count($events) === 2, 'events must return 2 rows, got ' . count($events));
    $check(
        array_map(static fn(array $row): int => (int) $row['event_id'], $events) === [$plannedId, $expandedId],
        'events must be ordered by event_id ASC'
    );
    $check(($events[0]['state'] ?? null) === SchemaOperationEvent::STATE_PLANNED, 'first event state must be planned');
    $check(($events[1]['state'] ?? null) === SchemaOperationEvent::STATE_EXPANDED, 'second event state must be expanded');
    $check(($events[0]['actor'] ?? null) === 'cli', 'actor must default to cli');
    $check(($events[0]['source'] ?? null) === 'cli', 'source must default to cli');
    $check(($events[0]['checksum'] ?? '') !== '', 'checksum must be filled when omitted');

    $filtered = $journal->events('rel-t42d', 'op-t42d', 'scope-default');
    $check(count($filtered) === 2, 'events must honour operation and scope filters');
    $check($journal->events('rel-t42d', 'other-op') === [], 'events must return an empty list for unknown operation');
    $check($journal->events('unknown-release') === [], 'events must return an empty list for unknown release');

    $check(
        $journal->latestState('scope-default', 'Weline_Demo:1.0.0:1:1:demo_table') === SchemaOperationEvent::STATE_EXPANDED,
        'latestState must project the newest event state'
    );
    $check($journal->latestState('scope-default', 'missing-node') === null, 'latestState must return null without events');

    $rejected = false;
    try {
        $journal->append([
            'release_id' => 'rel-t42d',
            'operation_id' => 'op-t42d',
            'scope_key' => 'scope-default',
            'node_id' => 'node-x',
            'state' => 'not-a-state',
        ]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'append must reject an unsupported state');

    $rejected = false;
    try {
        $journal->append([
            'release_id' => 'rel-t42d',
            'operation_id' => 'op-t42d',
            'scope_key' => 'scope-default',
            'state' => SchemaOperationEvent::STATE_PLANNED,
        ]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'append must reject a missing required field');

    $check($journal->events('rel-t42d') === $events, 'rejected appends must not write events');
} finally {
    if ($factory instanceof ConnectionFactory) {
        try {
            $factory->close();
        } catch (Throwable) {
            // ignore close failure during cleanup
        }
    }
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

echo 'T42D INTEGRATION PASS', PHP_EOL;
