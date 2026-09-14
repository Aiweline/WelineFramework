<?php

declare(strict_types=1);

use LearningMcp\Analyzer;
use LearningMcp\Config;
use LearningMcp\HardConstraintsCatalog;
use LearningMcp\IntelligenceService;
use LearningMcp\IndexGarbageCollector;
use LearningMcp\ProcessRunner;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectIndexer;
use LearningMcp\ProjectRetriever;
use LearningMcp\ProjectResolver;
use LearningMcp\SessionLifecycleService;
use LearningMcp\SparseVectorizer;
use LearningMcp\Store;
use LearningMcp\ToolException;
use LearningMcp\ToolService;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/project-guidance-runtime-time.php';

$root = dirname(__DIR__, 4);
$mode = in_array('--full', $argv, true) ? 'full' : 'quick';
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-mcp-tests-' . bin2hex(random_bytes(5));
$checks = [];
$failed = false;

function check(bool $condition, string $label): void
{
    global $checks, $failed;
    $checks[] = ['label' => $label, 'passed' => $condition];
    if (!$condition) {
        $failed = true;
        fwrite(STDERR, "[FAIL] $label
");
    } else {
        fwrite(STDOUT, "[PASS] $label
");
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

try {
    mkdir($temporary, 0700, true);
    $configPath = $temporary . '/config.json';
    file_put_contents($configPath, json_encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
        // 0.13 migrates all repository projection settings to the dynamic document index.
        'knowledge' => [
            'auto_generate_skills' => true,
            'learning_skills' => [
                'enabled' => true,
                'inject_on_prompt' => true,
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $config = Config::load($configPath);
    [, $variableCredentialCount] = LearningMcp\Redactor::string(
        '$password = $config->get(\'database/password\');',
    );
    check($variableCredentialCount === 0, 'credential detector permits code that reads into a sensitive-named variable');
    [, $literalCredentialCount] = LearningMcp\Redactor::string('password: actual-secret-value');
    check($literalCredentialCount === 1, 'credential detector still rejects a literal sensitive assignment');
    $store = new Store($config);
    check(
        (int) $store->database()->query('PRAGMA busy_timeout')->fetchColumn() === 30_000,
        'global learning SQLite waits thirty seconds for a concurrent writer',
    );
    check($store->schemaVersion() === Store::SCHEMA_VERSION, 'global migrations reach current schema version');
    check(Store::SCHEMA_VERSION === 3, 'data lifecycle migration advances global schema to version 3');
    check($config->get('knowledge.auto_generate_skills') === false, 'repository knowledge projection is forcibly retired');
    check($config->get('knowledge.auto_doc_sync') === false, 'automatic repository document writes are forcibly retired');
    check($config->get('knowledge.learning_skills.enabled') === false, 'learning projection worker is forcibly retired');
    check(
        in_array('knowledge.repository-projections:retired-in-0.13.0', $config->runtimeMigrations(), true),
        'legacy projection configuration retirement is auditable',
    );
    $legacyUnknownConfigPath = $temporary . '/config-unknown-privacy.json';
    file_put_contents($legacyUnknownConfigPath, json_encode([
        'data_dir' => $temporary . '/data-unknown-privacy',
        'analysis' => ['provider' => 'none'],
        'privacy' => ['not_a_real_privacy_key' => '14d'],
        'not_a_real_section' => ['enabled' => true],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $legacyUnknownConfig = Config::load($legacyUnknownConfigPath);
    check($legacyUnknownConfig->get('privacy.not_a_real_privacy_key') === null, 'unknown privacy keys are ignored without failing load');
    check($legacyUnknownConfig->get('not_a_real_section') === null, 'unknown top-level config sections are ignored without failing load');
    check($config->duration('privacy.raw_session_ttl') === 14 * 86_400, 'raw session TTL defaults to fourteen days');
    check($config->duration('privacy.tombstone_ttl') === 14 * 86_400, 'ordinary tombstones default to fourteen days');
    check($config->duration('index.refresh_interval') === 10 * 60, 'interactive index refresh interval defaults to ten minutes');
    check($config->duration('index.gc.retention') === 14 * 86_400, 'derived index inactivity retention defaults to fourteen days');
    check($config->duration('index.gc.dry_run_period') === 86_400, 'index GC requires a full-day observation before quarantine');
    check($config->duration('index.gc.quarantine_period') === 86_400, 'index GC quarantine keeps a full-day recovery window');
    $sessionColumns = array_column(
        $store->database()->query('PRAGMA table_info(sessions)')->fetchAll(PDO::FETCH_ASSOC),
        'name',
    );
    foreach (['lifecycle_state', 'lifecycle_generation', 'raw_expires_at', 'archiving_at', 'archive_reason'] as $column) {
        check(in_array($column, $sessionColumns, true), "sessions schema includes $column");
    }
    $lifecycleTables = $store->database()->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN (
            'project_locations', 'session_tombstones', 'experience_provenance',
            'experience_feedback_rollups', 'maintenance_state'
        ) ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
    check(count($lifecycleTables) === 5, 'data lifecycle migration creates all compact retention tables');

    $upgradeData = $temporary . '/upgrade-data';
    mkdir($upgradeData, 0700, true);
    $upgradeConfigPath = $temporary . '/upgrade-config.json';
    file_put_contents($upgradeConfigPath, json_encode([
        'data_dir' => $upgradeData,
        'analysis' => ['provider' => 'none'],
    ], JSON_THROW_ON_ERROR));
    $upgradeDatabase = new PDO('sqlite:' . $upgradeData . '/learning.db');
    $upgradeDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $upgradeDatabase->exec((string) file_get_contents(dirname(__DIR__) . '/migrations/001_initial.sql'));
    $upgradeDatabase->exec(
        "CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)"
    );
    $upgradeDatabase->exec(
        "INSERT INTO schema_migrations(version, name, applied_at) VALUES
            (1, '001_initial.sql', '2026-01-01T00:00:00.000Z')"
    );
    $upgradeDatabase->exec(
        "INSERT INTO projects(id, name, root_fingerprint, config_json, created_at, updated_at)
         VALUES('project:upgrade', 'Upgrade fixture', 'root', '{}',
            '2026-01-01T00:00:00.000Z', '2026-01-01T00:00:00.000Z')"
    );
    $upgradeSession = $upgradeDatabase->prepare(
        'INSERT INTO sessions(id, project_id, agent, cwd, status, consent_json, started_at, last_activity_at)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $upgradeSession->execute([
        'session:upgrade-old', 'project:upgrade', 'codex', '/tmp/upgrade', 'closed', '{}',
        '2026-01-01T00:00:00.000Z', '2026-01-02T00:00:00.000Z',
    ]);
    $upgradeSession->execute([
        'session:upgrade-future', 'project:upgrade', 'codex', '/tmp/upgrade', 'active', '{}',
        '2099-01-01T00:00:00.000Z', '2099-01-01T00:00:00.000Z',
    ]);
    $upgradeDatabase = null;
    $upgradedStore = new Store(Config::load($upgradeConfigPath));
    check($upgradedStore->schemaVersion() === 3, 'populated v1 database upgrades transactionally to lifecycle schema');
    $upgradedOldExpiry = (string) $upgradedStore->database()->query(
        "SELECT raw_expires_at FROM sessions WHERE id = 'session:upgrade-old'"
    )->fetchColumn();
    check($upgradedOldExpiry === '2026-01-15T00:00:00.000Z', 'v1 upgrade derives immutable expiry from historical Session start');
    $upgradedFutureExpiry = (string) $upgradedStore->database()->query(
        "SELECT raw_expires_at FROM sessions WHERE id = 'session:upgrade-future'"
    )->fetchColumn();
    check(
        strtotime($upgradedFutureExpiry) <= time() + (14 * 86_400) + 5,
        'v1 upgrade caps a future Session timestamp at fourteen days from migration',
    );
    $upgradeIndexes = (int) $upgradedStore->database()->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name IN (
            'idx_experience_provenance_source_hash', 'idx_audit_log_created_at', 'idx_feedback_session'
        )"
    )->fetchColumn();
    check($upgradeIndexes === 3, 'v1 upgrade adds bounded-cleanup and compliance lookup indexes');
    $upgradedStore->close();

    $lifecycleRoot = $temporary . '/lifecycle-project';
    mkdir($lifecycleRoot, 0700, true);
    $lifecycleResolved = ProjectResolver::resolve($lifecycleRoot);
    $lifecycleProject = $lifecycleResolved['project'];
    $lifecycleProject['repository'] = $lifecycleResolved['repository'];
    $lifecycleProjectId = (string) $lifecycleProject['id'];
    $store->upsertProject($lifecycleProject);
    $sessionId = 'session:lifecycle-secret';
    $session = [
        'id' => $sessionId,
        'project_id' => $lifecycleProjectId,
        'agent' => 'codex',
        'cwd' => $lifecycleRoot,
        'worktree' => $lifecycleRoot,
        'status' => 'active',
        'started_at' => '2026-07-01T00:00:00.000Z',
        'last_activity_at' => '2026-07-01T00:00:00.000Z',
    ];
    $store->upsertSession($session);
    $storedSession = $store->getSession($sessionId);
    check(
        ($storedSession['raw_expires_at'] ?? '') === '2026-07-15T00:00:00.000Z',
        'raw session expiry is fixed from session creation',
    );
    $futureSessionId = 'session:lifecycle-future-start';
    $store->upsertSession([
        'id' => $futureSessionId,
        'project_id' => $lifecycleProjectId,
        'cwd' => $lifecycleRoot,
        'worktree' => $lifecycleRoot,
        'started_at' => '2099-01-01T00:00:00.000Z',
        'last_activity_at' => '2099-01-01T00:00:00.000Z',
    ]);
    check(
        strtotime((string) $store->getSession($futureSessionId)['raw_expires_at']) <= time() + (14 * 86_400) + 5,
        'new Session expiry cannot be extended by a future started_at value',
    );
    $session['last_activity_at'] = '2026-07-10T00:00:00.000Z';
    $store->upsertSession($session);
    check(
        ($store->getSession($sessionId)['raw_expires_at'] ?? '') === '2026-07-15T00:00:00.000Z',
        'session activity cannot slide the raw retention deadline',
    );
    $location = $store->preferredProjectLocation($lifecycleProjectId);
    check(
        ($location['canonical_path'] ?? '') === realpath($lifecycleRoot),
        'durable project location is independent from session rows',
    );

    $eventId = 'event:lifecycle';
    $store->insertEvent([
        'event_id' => $eventId,
        'project_id' => $lifecycleProjectId,
        'session_id' => $sessionId,
        'type' => 'user_message',
        'content_redacted' => 'raw material that must be purged',
        'content_hash' => hash('sha256', 'raw material that must be purged'),
        'dedup_key' => 'lifecycle-event',
        'trust' => ['class' => 'user', 'score' => 1.0],
        'observed_at' => '2026-07-10T00:00:00.000Z',
    ]);
    $store->putEvidence([
        'evidence_id' => 'evidence:lifecycle',
        'project_id' => $lifecycleProjectId,
        'session_id' => $sessionId,
        'source_event_id' => $eventId,
        'evidence_type' => 'user_correction',
        'claim' => 'Keep only durable learning after archive',
        'polarity' => 'supports',
        'strength' => 1.0,
        'verified' => true,
    ]);
    $store->upsertExperience([
        'experience_id' => 'experience:lifecycle',
        'project_id' => $lifecycleProjectId,
        'fingerprint' => hash('sha256', 'lifecycle rule'),
        'title' => 'Lifecycle rule',
        'category' => 'workflow_rule',
        'problem_pattern' => 'Raw sessions grow without a bound',
        'correct_approach' => 'Compact mature learning and purge raw rows',
        'reusable_rule' => 'Archive raw session material after fourteen days',
        'confidence' => 0.98,
        'status' => 'validated',
        'scope' => ['project_ids' => [$lifecycleProjectId]],
        'source_session_ids' => [$sessionId],
        'evidence_ids' => ['evidence:lifecycle'],
        'metadata' => [
            'learning_classification' => [
                'knowledge_type' => 'skill_knowledge',
                'surface' => 'MCP lifecycle maintenance',
                'environment_constraints' => ['SQLite'],
                'positive_example' => 'Compact mature learning before deleting a Session',
                'negative_example' => 'Retain raw Session and Event rows forever',
                'examples_complete' => true,
            ],
        ],
    ]);
    $secondarySessionId = 'session:lifecycle-secondary';
    $store->upsertSession([
        'id' => $secondarySessionId,
        'project_id' => $lifecycleProjectId,
        'cwd' => $lifecycleRoot,
        'worktree' => $lifecycleRoot,
        'started_at' => '2099-01-02T00:00:00.000Z',
        'last_activity_at' => '2099-01-02T00:00:00.000Z',
    ]);
    $store->putEvidence([
        'evidence_id' => 'evidence:lifecycle-secondary',
        'project_id' => $lifecycleProjectId,
        'session_id' => $secondarySessionId,
        'evidence_type' => 'user_confirmation',
        'claim' => 'Independent evidence keeps a multi-source candidate',
        'polarity' => 'supports',
        'strength' => 0.8,
        'verified' => true,
    ]);
    $store->upsertExperience([
        'experience_id' => 'experience:multi-source',
        'project_id' => $lifecycleProjectId,
        'fingerprint' => hash('sha256', 'multi-source lifecycle rule'),
        'title' => 'Multi-source lifecycle rule',
        'category' => 'workflow_rule',
        'problem_pattern' => 'One source Session expires before another',
        'correct_approach' => 'Preserve the candidate while an independent source remains',
        'reusable_rule' => 'Archive only the expiring source edge',
        'confidence' => 0.7,
        'status' => 'candidate',
        'source_session_ids' => [$sessionId, $secondarySessionId],
        'evidence_ids' => ['evidence:lifecycle', 'evidence:lifecycle-secondary'],
    ]);
    $job = $store->enqueueAnalysisForSession($sessionId, $lifecycleProjectId);
    $jobGeneration = (int) $store->database()->query(
        "SELECT session_generation FROM analysis_jobs WHERE id = '" . $job['id'] . "'"
    )->fetchColumn();
    check($jobGeneration === 1, 'analysis jobs capture the active session generation');
    $claimedLifecycleJob = $store->claimJob(60, 5);
    check(($claimedLifecycleJob['id'] ?? '') === $job['id'], 'lifecycle fixture claims the generation-bound job');

    $frozen = $store->freezeSessionForArchive($sessionId, 'explicit_archive');
    check(
        ($frozen['lifecycle_state'] ?? '') === 'archiving' && ($frozen['lifecycle_generation'] ?? 0) === 2,
        'archive freeze atomically advances lifecycle generation',
    );
    $jobState = $store->database()->query(
        "SELECT status || ':' || COALESCE(cancel_reason, '') FROM analysis_jobs WHERE id = '" . $job['id'] . "'"
    )->fetchColumn();
    check($jobState === 'cancelled:session_archiving', 'archive freeze cancels stale analysis work');
    check(
        $store->completeJob((string) $job['id'], ['decision' => 'late']) === false,
        'cancelled worker cannot complete a stale generation',
    );
    check(
        $store->failJob($claimedLifecycleJob, new RuntimeException('late failure'), true, 5) === false,
        'cancelled worker cannot requeue a stale generation',
    );
    try {
        $store->insertEvent([
            'event_id' => 'event:late',
            'project_id' => $lifecycleProjectId,
            'session_id' => $sessionId,
            'type' => 'late_event',
            'content_hash' => hash('sha256', 'late'),
            'dedup_key' => 'lifecycle-late-event',
        ]);
        check(false, 'frozen sessions reject late events');
    } catch (ToolException $exception) {
        check($exception->errorCode === 'SESSION_ARCHIVING', 'frozen sessions reject late events');
    }

    $purged = $store->compactAndPurgeSession($sessionId, 2, 'explicit', 'failed');
    check(($purged['events_deleted'] ?? 0) === 1, 'archive removes raw events even when final learning fails');
    check((int) $store->database()->query("SELECT COUNT(*) FROM sessions WHERE id = '$sessionId'")->fetchColumn() === 0, 'archive removes raw session row');
    check((int) $store->database()->query("SELECT COUNT(*) FROM evidence WHERE session_id = '$sessionId'")->fetchColumn() === 0, 'archive removes raw evidence rows');
    check((int) $store->database()->query("SELECT COUNT(*) FROM experiences WHERE id = 'experience:lifecycle'")->fetchColumn() === 1, 'archive preserves validated Experience');
    check((int) $store->database()->query("SELECT COUNT(*) FROM experiences WHERE id = 'experience:multi-source'")->fetchColumn() === 1, 'archive preserves a Candidate with an independent live source');
    check((int) $store->database()->query("SELECT COUNT(*) FROM experience_sources WHERE experience_id = 'experience:multi-source'")->fetchColumn() === 1, 'archive removes only the expired Candidate source edge');
    $provenance = $store->database()->query(
        "SELECT source_hash, observation_count FROM experience_provenance WHERE experience_id = 'experience:lifecycle'"
    )->fetch(PDO::FETCH_ASSOC);
    check(
        is_array($provenance) && ($provenance['source_hash'] ?? '') !== $sessionId && strlen((string) ($provenance['source_hash'] ?? '')) === 64,
        'durable provenance stores only a keyed session hash',
    );
    $tombstoneJson = json_encode(
        $store->database()->query('SELECT * FROM session_tombstones')->fetchAll(PDO::FETCH_ASSOC),
        JSON_THROW_ON_ERROR,
    );
    check(!str_contains($tombstoneJson, $sessionId), 'tombstone does not retain the raw session identifier');
    try {
        $store->upsertSession($session);
        check(false, 'tombstone prevents archived-session resurrection');
    } catch (ToolException $exception) {
        check($exception->errorCode === 'SESSION_ARCHIVED', 'tombstone prevents archived-session resurrection');
    }
    $details = $store->explainExperience('experience:lifecycle');
    check(($details['evidence_state'] ?? '') === 'archived_compact', 'Experience explanation reports compact archived evidence');
    check(count($details['provenance'] ?? []) === 1, 'Experience explanation exposes compact provenance');
    $promotionEligible = $store->markExperience(
        'experience:lifecycle',
        'promotion_eligible',
        'archive-review-test',
        'compact provenance preserves the prior validation basis',
    );
    check(
        ($promotionEligible['status'] ?? '') === 'promotion_eligible',
        'a mature Experience can still pass review after raw evidence is archived',
    );
    $skillJobs = $store->enqueueLearningSkillSyncs();
    check($skillJobs === [], 'retired repository projection never enqueues a write job');
    $retiredProjection = (new LearningMcp\LearningSkillService($store, $config))->syncJob([]);
    check(
        ($retiredProjection['decision'] ?? '') === 'disabled'
            && ($retiredProjection['repository_files_written'] ?? true) === false,
        'legacy queued projection receives a no-write terminal receipt',
    );
    $retainedText = json_encode([
        $store->database()->query('SELECT snapshot_json FROM experience_versions')->fetchAll(PDO::FETCH_COLUMN),
        $store->database()->query('SELECT entity_id, details_json FROM audit_log')->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_THROW_ON_ERROR);
    check(!str_contains($retainedText, $sessionId), 'archive removes raw Session ID from durable snapshots and audit rows');
    $archivedFallbackSource = $store->database()->prepare(
        'INSERT INTO experience_sources(experience_id, session_id) VALUES(?, ?)'
    );
    $archivedFallbackSource->execute(['experience:lifecycle', $secondarySessionId]);
    $store->database()->exec(
        "UPDATE experiences SET source_session_count = 1 WHERE id = 'experience:lifecycle'"
    );
    $store->deleteSession($sessionId, 'archived-compliance-test');
    check(
        $store->getExperience('experience:lifecycle')['status'] === 'contested',
        'compliance removal of archived provenance contests learning with no remaining evidence',
    );
    $archivedComplianceTombstone = $store->sessionTombstone($sessionId);
    check(
        ($archivedComplianceTombstone['archive_kind'] ?? '') === 'compliance'
            && is_array($archivedComplianceTombstone)
            && array_key_exists('expires_at', $archivedComplianceTombstone)
            && $archivedComplianceTombstone['expires_at'] === null,
        'compliance removal upgrades an ordinary archive tombstone to permanent',
    );

    $complianceSessionId = 'session:compliance-delete';
    $store->upsertSession([
        'id' => $complianceSessionId,
        'project_id' => $lifecycleProjectId,
        'cwd' => $lifecycleRoot,
        'worktree' => $lifecycleRoot,
    ]);
    $store->putEvidence([
        'evidence_id' => 'evidence:compliance-delete',
        'project_id' => $lifecycleProjectId,
        'session_id' => $complianceSessionId,
        'evidence_type' => 'user_confirmation',
        'claim' => 'Compliance fixture',
        'polarity' => 'supports',
        'verified' => true,
    ]);
    $store->upsertExperience([
        'experience_id' => 'experience:compliance-delete',
        'project_id' => $lifecycleProjectId,
        'fingerprint' => hash('sha256', 'compliance delete'),
        'title' => 'Compliance delete',
        'category' => 'user_preference',
        'problem_pattern' => 'User requests source erasure',
        'correct_approach' => 'Erase learning supported only by that source',
        'reusable_rule' => 'Honor compliance deletion separately from ordinary archive',
        'confidence' => 0.99,
        'status' => 'validated',
        'source_session_ids' => [$complianceSessionId],
        'evidence_ids' => ['evidence:compliance-delete'],
    ]);
    $store->deleteSession($complianceSessionId, 'compliance-test');
    check((int) $store->database()->query("SELECT COUNT(*) FROM experiences WHERE id = 'experience:compliance-delete'")->fetchColumn() === 0, 'compliance delete erases Experience supported only by that Session');
    $complianceTombstone = $store->sessionTombstone($complianceSessionId);
    check(
        ($complianceTombstone['archive_kind'] ?? '') === 'compliance'
            && is_array($complianceTombstone)
            && array_key_exists('expires_at', $complianceTombstone)
            && $complianceTombstone['expires_at'] === null,
        'compliance delete writes a permanent non-raw tombstone',
    );

    $racingSessionId = 'session:maintenance-race';
    $store->upsertSession([
        'id' => $racingSessionId,
        'project_id' => $lifecycleProjectId,
        'cwd' => $lifecycleRoot,
        'worktree' => $lifecycleRoot,
        'started_at' => '2026-01-01T00:00:00.000Z',
        'last_activity_at' => '2026-01-01T00:00:00.000Z',
    ]);
    check($store->acquireMaintenanceLease('session_lifecycle', 'worker-a', 60), 'first lifecycle worker acquires maintenance lease');
    check(!$store->acquireMaintenanceLease('session_lifecycle', 'worker-b', 60), 'second lifecycle worker cannot steal active maintenance lease');
    $store->releaseMaintenanceLease('session_lifecycle', 'worker-a', ['fixture' => true]);
    $lifecycle = new SessionLifecycleService(
        $store,
        new Analyzer($store, $config),
        $config,
        static function (): array {
            throw new RuntimeException('forced final learning failure');
        },
    );
    $sweep = $lifecycle->sweep('worker-b');
    check(($sweep['sessions_purged'] ?? 0) === 1, 'maintenance sweep purges sessions past immutable TTL');
    check(($sweep['final_learning_failed'] ?? 0) === 1, 'maintenance records final-learning failure without retaining raw data');
    check((int) $store->database()->query("SELECT COUNT(*) FROM sessions WHERE id = '$racingSessionId'")->fetchColumn() === 0, 'failed final learning cannot block TTL purge');
    $sqliteMaintenance = $store->maintainSqlite();
    check(
        isset($sqliteMaintenance['page_count_before'], $sqliteMaintenance['page_count_after'], $sqliteMaintenance['freelist_after']),
        'SQLite maintenance reports physical page reclamation metrics',
    );
    $store->writeAudit('retention-test', 'expired_audit_fixture', 'fixture', 'old', []);
    $store->database()->exec("UPDATE audit_log SET created_at = '2026-01-01T00:00:00.000Z' WHERE action = 'expired_audit_fixture'");
    $retentionCleanup = $store->cleanupLifecycleRetention();
    check(($retentionCleanup['audit_rows_deleted'] ?? 0) >= 1, 'maintenance bounds audit history instead of growing forever');

    $gcRoot = $temporary . '/gc-project';
    mkdir($gcRoot, 0700, true);
    file_put_contents($gcRoot . '/README.md', "# GC fixture\n");
    $gcResolved = ProjectResolver::resolve($gcRoot);
    $gcIndex = new ProjectIndex($config, $gcResolved);
    $gcDirectory = dirname($gcIndex->path());
    $ownershipPath = $gcDirectory . '/.weline-index-owner.json';
    check(is_file($ownershipPath), 'project index generation writes an explicit ownership manifest');
    $ownership = json_decode((string) file_get_contents($ownershipPath), true, 512, JSON_THROW_ON_ERROR);
    check(
        ($ownership['schema_version'] ?? '') === 'weline-index-owner.v1'
            && ($ownership['owner'] ?? '') === 'weline-project-intelligence',
        'index ownership manifest is typed and names the owning subsystem',
    );
    $ownership['last_used_at'] = '2026-07-01T00:00:00.000Z';
    file_put_contents($ownershipPath, json_encode($ownership, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $unknownDirectory = $config->dataDir() . '/indexes/unowned-sentinel';
    mkdir($unknownDirectory, 0700, true);
    file_put_contents($unknownDirectory . '/keep.txt', "not owned\n");
    $forgedGeneration = str_repeat('a', 64);
    $forgedDirectory = $config->dataDir() . '/indexes/' . $forgedGeneration;
    mkdir($forgedDirectory, 0700, true);
    file_put_contents($forgedDirectory . '/project.sqlite', 'not an MCP SQLite index');
    file_put_contents($forgedDirectory . '/.weline-index-owner.json', json_encode([
        'schema_version' => 'weline-index-owner.v1',
        'owner' => 'weline-project-intelligence',
        'generation' => $forgedGeneration,
        'project_id_hash' => str_repeat('b', 64),
        'repository_hash' => str_repeat('c', 64),
        'created_at' => '2026-01-01T00:00:00.000Z',
        'last_used_at' => '2026-01-01T00:00:00.000Z',
    ], JSON_THROW_ON_ERROR));
    $protectedRoot = $temporary . '/gc-protected-project';
    mkdir($protectedRoot, 0700, true);
    $protectedIndex = new ProjectIndex($config, ProjectResolver::resolve($protectedRoot));
    $protectedDirectory = dirname($protectedIndex->path());
    $protectedIndex->close();
    $protectedManifestPath = $protectedDirectory . '/.weline-index-owner.json';
    $protectedManifest = json_decode((string) file_get_contents($protectedManifestPath), true, 512, JSON_THROW_ON_ERROR);
    $protectedManifest['last_used_at'] = '2026-07-01T00:00:00.000Z';
    file_put_contents($protectedManifestPath, json_encode($protectedManifest, JSON_THROW_ON_ERROR));
    file_put_contents($protectedDirectory . '/unexpected-user-file.txt', "keep\n");
    $legacyRoot = $temporary . '/gc-legacy-project';
    mkdir($legacyRoot, 0700, true);
    $legacyIndex = new ProjectIndex($config, ProjectResolver::resolve($legacyRoot));
    $legacyDirectory = dirname($legacyIndex->path());
    $legacyIndex->close();
    $legacyManifestPath = $legacyDirectory . '/.weline-index-owner.json';
    unlink($legacyManifestPath);
    $gcClock = static fn(): string => '2026-07-29T00:00:00.000Z';
    $garbageCollector = new IndexGarbageCollector($store, $config, $gcClock);
    $lockedSweep = $garbageCollector->sweep('gc-worker', true);
    check(($lockedSweep['active_leases'] ?? 0) === 1, 'index GC cannot quarantine a generation with an active shared lease');
    check(($lockedSweep['legacy_adopted'] ?? 0) === 1, 'legacy index ownership is adopted only after database identity verification');
    $adoptedLegacyManifest = json_decode(
        (string) file_get_contents($legacyManifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    check(
        ($adoptedLegacyManifest['last_used_at'] ?? '') === '2026-07-29T00:00:00.000Z',
        'legacy adoption starts a fresh fourteen-day retention grace period',
    );
    check(($lockedSweep['invalid_manifests'] ?? 0) >= 1, 'forged ownership marker is rejected');
    check(is_dir($gcDirectory), 'active index generation remains in place');
    $gcIndex->close();
    $ownership = json_decode((string) file_get_contents($ownershipPath), true, 512, JSON_THROW_ON_ERROR);
    $ownership['last_used_at'] = '2026-07-01T00:00:00.000Z';
    file_put_contents($ownershipPath, json_encode($ownership, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $observedSweep = $garbageCollector->sweep('gc-worker', true);
    check(($observedSweep['dry_run_observed'] ?? 0) === 1, 'first eligible GC pass records a mandatory dry-run observation');
    check(is_dir($gcDirectory), 'dry-run observation does not move the index generation');
    $reusedRoot = $temporary . '/gc-reused-project';
    mkdir($reusedRoot, 0700, true);
    $reusedIndex = new ProjectIndex($config, ProjectResolver::resolve($reusedRoot));
    $reusedDirectory = dirname($reusedIndex->path());
    $reusedIndex->close();
    $reusedManifestPath = $reusedDirectory . '/.weline-index-owner.json';
    $reusedManifest = json_decode((string) file_get_contents($reusedManifestPath), true, 512, JSON_THROW_ON_ERROR);
    $reusedManifest['last_used_at'] = '2026-07-01T00:00:00.000Z';
    file_put_contents($reusedManifestPath, json_encode($reusedManifest, JSON_THROW_ON_ERROR));
    $reusedObservation = $garbageCollector->sweep('gc-worker', true);
    check(($reusedObservation['dry_run_observed'] ?? 0) >= 1, 'a second stale generation enters its own dry-run observation');
    $reusedManifest['last_used_at'] = '2026-07-02T00:00:00.000Z';
    file_put_contents($reusedManifestPath, json_encode($reusedManifest, JSON_THROW_ON_ERROR));
    $quarantineCollector = new IndexGarbageCollector(
        $store,
        $config,
        static fn(): string => '2026-07-30T01:00:00.000Z',
    );
    $quarantinedSweep = $quarantineCollector->sweep('gc-worker', true);
    check(($quarantinedSweep['quarantined'] ?? 0) === 1, 'eligible owned index moves to same-filesystem quarantine after 24-hour observation');
    check(!is_dir($gcDirectory), 'quarantine move is atomic from the active index namespace');
    check(is_dir($reusedDirectory), 'index reuse resets the dry-run clock instead of inheriting an old observation');
    check(is_file($unknownDirectory . '/keep.txt'), 'GC never touches an unowned directory');
    check(is_file($protectedDirectory . '/unexpected-user-file.txt'), 'GC refuses an owned directory containing an unknown file');
    $deleteCollector = new IndexGarbageCollector(
        $store,
        $config,
        static fn(): string => '2026-07-31T02:00:00.000Z',
    );
    $deletedSweep = $deleteCollector->sweep('gc-worker', true);
    check(($deletedSweep['deleted'] ?? 0) === 1, 'quarantined index is deleted only after its recovery window');
    check(is_file($unknownDirectory . '/keep.txt'), 'unowned sentinel survives quarantine deletion pass');
    check(is_file($forgedDirectory . '/project.sqlite'), 'forged ownership directory survives every GC phase');

    $fixtureRoot = $temporary . '/explicit-path-project';
    foreach ([
        'src', 'tests/unit', 'ui', 'docs', 'scripts', '.cursor', '.claude', '.agents', '.github',
        'build', 'tmp', 'test-results', 'evidence', 'private', '.superpowers', 'Users',
        '.idea', '.vscode', 'coverage', '.cache', 'dist', 'out', 'log', 'logs', 'tmp_cache',
        'vendor-bin', 'vendor-bin/bin', '.scannerwork', 'build-cache', 'nbproject', '.nyc_output',
        'pub', 'setup', 'docs/assets', 'pub/errors', 'pub/readme', 'pub/source', 'pub/sitemaps',
        'pub/theme_previews', 'setup/static', 'setup/server_installer', 'setup/step',
        'extends', 'extends/foo', 'extends/foo/server', 'extends/foo/client',
    ] as $directory) {
        mkdir($fixtureRoot . '/' . $directory, 0700, true);
    }
    file_put_contents($fixtureRoot . '/src/Example.php', "<?php\nfinal class Example {}\n");
    $rankedMethods = '';
    $rankedPayload = var_export(str_repeat(
        'MCP UI plugin documentation validation retrieval service context bundle ',
        120,
    ), true);
    for ($method = 0; $method < 8; ++$method) {
        $rankedMethods .= ' public function context' . $method
            . '(): string { return ' . $rankedPayload . "; }\n";
    }
    file_put_contents(
        $fixtureRoot . '/src/ToolService.php',
        "<?php\nfinal class ToolService {\n"
            . " public function definitions(): array { return []; }\n"
            . $rankedMethods
            . "}\n",
    );
    file_put_contents(
        $fixtureRoot . '/src/ProjectRetriever.php',
        "<?php\nfinal class ProjectRetriever { public function search(): array { return []; } }\n",
    );
    file_put_contents(
        $fixtureRoot . '/src/McpServer.php',
        "<?php\nfinal class McpServer { public function run(): void {} }\n",
    );
    file_put_contents($fixtureRoot . '/ui/panel.html', "<!doctype html><main>Execution panel</main>\n");
    file_put_contents($fixtureRoot . '/docs/README.md', "# MCP architecture and usage\n");
    file_put_contents($fixtureRoot . '/scripts/install.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/.cursor/session.md', "cursor context\n");
    file_put_contents($fixtureRoot . '/.claude/rules.md', "claude rules\n");
    file_put_contents($fixtureRoot . '/.agents/agent.md', "agent config\n");
    file_put_contents($fixtureRoot . '/.github/workflow.md', "workflows\n");
    file_put_contents($fixtureRoot . '/build/build.log', "build\n");
    file_put_contents($fixtureRoot . '/tmp/cache.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/test-results/result.txt', "ok\n");
    file_put_contents($fixtureRoot . '/evidence/trace.md', "trace\n");
    file_put_contents($fixtureRoot . '/private/secret.md', "secret\n");
    file_put_contents($fixtureRoot . '/.superpowers/manifest.md', "superpowers\n");
    file_put_contents($fixtureRoot . '/Users/local.txt', "tmp-user\n");
    file_put_contents($fixtureRoot . '/pub/errors/error.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/pub/readme/readme.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/pub/source/source.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/pub/sitemaps/site.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/pub/theme_previews/theme.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/setup/static/setup.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/setup/server_installer/installer.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/setup/step/step.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/docs/assets/main.js', "export default {};\n");
    file_put_contents($fixtureRoot . '/.idea/workspace.xml', "<xml/>\n");
    file_put_contents($fixtureRoot . '/.vscode/settings.json', "{}\n");
    file_put_contents($fixtureRoot . '/coverage/coverage.xml', "<?xml version=\"1.0\"?>\n<coverage/>\n");
    file_put_contents($fixtureRoot . '/.cache/index.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/dist/dist.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/out/index.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/log/service.log', "ok\n");
    file_put_contents($fixtureRoot . '/logs/debug.log', "ok\n");
    file_put_contents($fixtureRoot . '/tmp_cache/index.php', "<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/vendor-bin/bin/phpunit', "#!/usr/bin/env php\n<?php\nreturn true;\n");
    file_put_contents($fixtureRoot . '/tmp/.phpunit.result.cache', "[]");
    file_put_contents($fixtureRoot . '/.scannerwork/report.txt', "report\n");
    file_put_contents($fixtureRoot . '/build-cache/config.json', "{\"ok\":true}\n");
    file_put_contents($fixtureRoot . '/nbproject/project.properties', "php.version=8.3\n");
    file_put_contents($fixtureRoot . '/.nyc_output/coverage.json', "{}\n");
    file_put_contents($fixtureRoot . '/extends/foo/server/Server.php', "<?php\nclass Server {}\n");
    file_put_contents($fixtureRoot . '/extends/foo/client/Client.php', "<?php\nclass Client {}\n");
    file_put_contents(
        $fixtureRoot . '/tests/unit/ExplicitTest.php',
        "<?php\nfinal class ExplicitTest { public function testIt(): bool { return true; } }\n",
    );
    $fixtureResolved = ProjectResolver::resolve($fixtureRoot);
    $fixtureIndex = new ProjectIndex($config, $fixtureResolved);
    $projectDatabaseProperty = (new ReflectionClass($fixtureIndex))->getProperty('database');
    $projectDatabase = $projectDatabaseProperty->getValue($fixtureIndex);
    check(
        $projectDatabase instanceof PDO
            && (int) $projectDatabase->query('PRAGMA busy_timeout')->fetchColumn() === 30_000,
        'project index SQLite waits thirty seconds for a concurrent writer',
    );
    $fixtureIndexer = new ProjectIndexer($fixtureIndex, $config);
    $fixtureFull = $fixtureIndexer->index(['mode' => 'full']);
    check(
        !in_array('tests/unit/ExplicitTest.php', $fixtureFull['changed_paths'] ?? [], true),
        'ordinary full indexing excludes test directories',
    );
    foreach ([
        '.cursor/session.md', '.claude/rules.md', '.agents/agent.md', '.github/workflow.md',
        'build/build.log', 'tmp/cache.php', 'test-results/result.txt', 'evidence/trace.md',
        'private/secret.md', '.superpowers/manifest.md', 'Users/local.txt',
        'pub/errors/error.php', 'pub/readme/readme.php', 'pub/source/source.php',
        'pub/sitemaps/site.php', 'pub/theme_previews/theme.php', 'setup/static/setup.php',
        'setup/server_installer/installer.php', 'setup/step/step.php', 'docs/assets/main.js',
        'extends/foo/server/Server.php',
        '.idea/workspace.xml', '.vscode/settings.json', 'coverage/coverage.xml', '.cache/index.php',
        'dist/dist.php', 'out/index.php', 'log/service.log', 'logs/debug.log',
        'tmp_cache/index.php', 'vendor-bin/bin/phpunit', '.scannerwork/report.txt',
        'build-cache/config.json', 'nbproject/project.properties', '.nyc_output/coverage.json',
        'tmp/.phpunit.result.cache',
    ] as $excludedFixturePath) {
        check(
            !in_array($excludedFixturePath, $fixtureFull['changed_paths'] ?? [], true),
            sprintf('fixture excludes path by policy: %s', $excludedFixturePath),
        );
    }
    check(
        in_array('extends/foo/client/Client.php', $fixtureFull['changed_paths'] ?? [], true),
        'extends server subdir is excluded while peer client dir remains indexable',
    );
    $anchorSearch = (new ProjectRetriever(
        $fixtureIndex,
        new SparseVectorizer($config),
        $config,
    ))->search(
        'MCP UI plugin documentation validation retrieval service context bundle',
        [
            'paths' => ['src/ToolService.php', 'src/McpServer.php'],
            'limit' => 2,
            'max_chunks_per_file' => 2,
            'token_budget' => 1_200,
            'per_result_token_budget' => 500,
        ],
    );
    $anchorPaths = array_values(array_unique(array_map(
        static fn (array $result): string => (string) ($result['relative_path'] ?? ''),
        $anchorSearch['results'] ?? [],
    )));
    check(
        in_array('src/ToolService.php', $anchorPaths, true)
            && in_array('src/McpServer.php', $anchorPaths, true),
        'path-scoped retrieval anchors one region per exact path before repeat chunks',
    );
    $fixtureExplicit = $fixtureIndexer->indexPaths(['tests/unit/ExplicitTest.php']);
    check(
        in_array('tests/unit/ExplicitTest.php', $fixtureExplicit['changed_paths'] ?? [], true),
        'explicit test path is materialized in one bounded batch',
    );
    $fixtureIndex->close();

    $indexTools = [
        'get_indexed_document',
        'get_skill',
        'health',
        'prepare_project',
        'project_index_status',
        'repair_project_docs',
        'resolve_skill',
        'resolve_task_context',
        'search_project_knowledge',
    ];
    $tools = new ToolService($store, $config, new Analyzer($store, $config));
    $definitions = $tools->definitions();
    $names = array_column($definitions, 'name');
    $sortedNames = $names;
    sort($sortedNames);
    check($sortedNames === $indexTools, 'compact tool surface equals the nine index/knowledge tools');
    check(ToolService::VERSION === '0.13.3', 'tool service version is 0.13.3');
    check(str_contains(substr(ToolService::instructions(), 0, 512), 'prepare_project'), 'first 512 instruction characters contain prepare_project');
    check(str_contains(ToolService::instructions(), 'resolve_task_context'), 'instructions mention resolve_task_context');
    check(str_contains(ToolService::instructions(), 'resolve_skill'), 'instructions mention resolve_skill');
    $instructions = ToolService::instructions();
    check(
        (str_contains($instructions, 'mandatory hard-rule gate') || str_contains($instructions, 'MANDATORY ensure'))
            && str_contains($instructions, 'host-native')
            && str_contains($instructions, 'hard_constraints'),
        'instructions require prepare_project hard_constraints gate with host-native coding',
    );
    check(str_contains(HardConstraintsCatalog::package()['mcp_operational'][0]['id'] ?? '', 'mcp_call_scope')
        || in_array('mcp_call_scope', array_column(HardConstraintsCatalog::mcpOperationalRules(), 'id'), true), 'mcp_operational includes mcp_call_scope');
    $mcpCallScope = '';
    foreach (HardConstraintsCatalog::mcpOperationalRules() as $rule) {
        if (($rule['id'] ?? '') === 'mcp_call_scope') {
            $mcpCallScope = (string) ($rule['summary'] ?? '');
            break;
        }
    }
    check(
        str_contains($mcpCallScope, 'MANDATORY')
            && str_contains($mcpCallScope, 'prepare_project')
            && str_contains($mcpCallScope, 'hard_constraints'),
        'mcp_call_scope mandates prepare_project hard_constraints for engineering',
    );

    $runner = new ProcessRunner();
    $destructiveGitBlocked = false;
    try {
        $runner->run(['git', 'reset', '--hard'], $root, '', 5);
    } catch (RuntimeException $exception) {
        $destructiveGitBlocked = str_contains($exception->getMessage(), 'MCP_WORKTREE_MUTATION_FORBIDDEN');
    }
    check($destructiveGitBlocked, 'process runner rejects destructive Git before spawning a child');
    check(
        welineGuidanceStartedEpochFromElapsed('04:00:00', 100_000) === 85_600,
        'host generation time derives from elapsed duration without timezone drift',
    );
    $parserMemory = $runner->run(
        [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/php-parser-memory.php'],
        $root,
        '',
        60,
    );
    check(
        $parserMemory['exit_code'] === 0,
        'token-dense PHP parses inside a 128 MiB child process',
    );
    $parserMemoryResult = json_decode(trim($parserMemory['stdout']), true);
    check(
        is_array($parserMemoryResult)
            && (int) ($parserMemoryResult['symbols'] ?? 0) === 3
            && (int) ($parserMemoryResult['peak_bytes'] ?? PHP_INT_MAX) < 96 * 1_024 * 1_024,
        'token-dense PHP preserves symbols below the 96 MiB parser budget',
    );
    $captureProbe = $runner->run(
        [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 9 * 1024 * 1024));'],
        $root,
        '',
        10,
    );
    check(
        ($captureProbe['stdout_truncated'] ?? false) === true
            && ($captureProbe['stderr_truncated'] ?? true) === false,
        'process runner exposes bounded stdout truncation without decoding its payload',
    );
    $parserPayload = $runner->run(
        [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/php-parser-payload.php'],
        $root,
        '',
        30,
    );
    $parserPayloadResult = json_decode(trim($parserPayload['stdout']), true);
    check(
        $parserPayload['exit_code'] === 0
            && ($parserPayloadResult['valid_round_trip'] ?? false) === true
            && ($parserPayloadResult['semantic_hash'] ?? null)
                === '3f5ae973d7e310462f69ad9f43efd11981c48f031ec1a8cf1ce2c2b864e7eaaf'
            && ($parserPayloadResult['invalid_records_rejected'] ?? false) === true
            && ($parserPayloadResult['adversarial_rejected'] ?? false) === true
            && (int) ($parserPayloadResult['peak_bytes'] ?? PHP_INT_MAX) < 64 * 1_024 * 1_024,
        'parser payload validation rejects amplified or incomplete JSON within 128 MiB',
    );
    $parserHighWater = $runner->run(
        [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/php-parser-high-water.php'],
        $root,
        '',
        30,
    );
    $parserHighWaterResult = json_decode(trim($parserHighWater['stdout']), true);
    check(
        $parserHighWater['exit_code'] === 0
            && ($parserHighWaterResult['rejected_before_decode'] ?? false) === true
            && (int) ($parserHighWaterResult['usage_before'] ?? 0) >= 124 * 1_024 * 1_024
            && (int) ($parserHighWaterResult['peak_bytes'] ?? PHP_INT_MAX) < 128 * 1_024 * 1_024,
        'parser payload decode is denied safely at the 128 MiB parent high-water mark',
    );
    $parserIsolation = $runner->run(
        [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/php-parser-isolation.php'],
        $root,
        '',
        180,
    );
    check(
        $parserIsolation['exit_code'] === 0,
        'resource-dense PHP parser failure remains isolated from the parent indexer',
    );
    $parserIsolationResult = json_decode(trim($parserIsolation['stdout']), true);
    check(
        is_array($parserIsolationResult)
            && ($parserIsolationResult['freshness'] ?? null) === 'current'
            && ($parserIsolationResult['phase'] ?? null) === 'idle'
            && ($parserIsolationResult['previous_hash_retained'] ?? false) === true
            && ($parserIsolationResult['transport_survived'] ?? false) === true
            && ($parserIsolationResult['baseline_symbol'] ?? null) === 'Isolation\\Large::stable'
            && ($parserIsolationResult['near_threshold_bytes'] ?? null) === 65_535
            && ($parserIsolationResult['near_threshold_symbol'] ?? null) === 'Isolation\\NearThreshold::run'
            && ($parserIsolationResult['path_sensitive_worker_match'] ?? false) === true
            && ($parserIsolationResult['truncated_output_rejected'] ?? false) === true
            && (int) ($parserIsolationResult['resource_bytes'] ?? 0) > 0,
        'isolated parser failure retains the index and keeps MCP transport readable',
    );
    $installConfig = $temporary . '/install/config.yaml';
    $marketplace = $temporary . '/marketplace';
    $dryRun = $runner->run(
        [
            PHP_BINARY,
            dirname(__DIR__) . '/scripts/install.php',
            'install',
            '--dry-run',
            '--config=' . $installConfig,
            '--marketplace-dir=' . $marketplace,
        ],
        $root,
        '',
        30,
    );
    check($dryRun['exit_code'] === 0, 'installer dry-run succeeds without changing Codex');
    $manifest = json_decode((string) file_get_contents(
        $marketplace . '/plugins/weline-project-intelligence/.codex-plugin/plugin.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    check(($manifest['version'] ?? '') === '0.13.0', 'generated plugin advertises version 0.13.0');
    check(!array_key_exists('mcpServers', $manifest), 'generated plugin does not declare a duplicate MCP server');
    $generation = json_decode((string) file_get_contents(
        $marketplace . '/plugins/weline-project-intelligence/.weline-generation.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    check(
        preg_match('/^[a-f0-9]{64}$/', (string) ($generation['source_generation'] ?? '')) === 1,
        'generated plugin records the exact MCP source generation',
    );
    $prompts = $manifest['interface']['defaultPrompt'] ?? [];
    check(count($prompts) <= 3, 'plugin defaultPrompt has at most three entries');
    check(array_reduce($prompts, static fn (bool $ok, string $prompt): bool => $ok && mb_strlen($prompt) <= 128, true), 'every defaultPrompt entry is at most 128 characters');
    $mcpConfig = json_decode((string) file_get_contents(
        $marketplace . '/plugins/weline-project-intelligence/.mcp.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    $enabled = $mcpConfig['mcpServers']['weline-project-intelligence']['enabled_tools'] ?? [];
    $sortedEnabled = is_array($enabled) ? $enabled : [];
    sort($sortedEnabled);
    check($sortedEnabled === $indexTools, 'generated plugin enabled_tools equals the nine index/knowledge tools');

    $fakeCodex = $temporary . '/fake-codex.sh';
    $fakeCodexLog = $temporary . '/fake-codex.log';
    file_put_contents($fakeCodex, <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_CODEX_LOG"
if [ "$1" = "--version" ]; then
    printf '%s\n' 'codex-cli fake'
    exit 0
fi
if [ "$1" = "plugin" ] && [ "$2" = "list" ]; then
    printf '%s\n' '{"installed":[{"name":"weline-project-intelligence","pluginId":"weline-project-intelligence@personal"}]}'
    exit 0
fi
printf '%s\n' '{}'
SH);
    chmod($fakeCodex, 0700);
    $liveInstall = $runner->run(
        [
            PHP_BINARY,
            dirname(__DIR__) . '/scripts/install.php',
            'install',
            '--config=' . $temporary . '/live-install/config.yaml',
            '--marketplace-dir=' . $temporary . '/live-marketplace',
        ],
        $root,
        '',
        30,
        [
            'CODEX_CLI_PATH' => $fakeCodex,
            'FAKE_CODEX_LOG' => $fakeCodexLog,
        ],
    );
    check($liveInstall['exit_code'] === 0, 'installer upgrade fixture completes with an isolated Codex CLI');
    $codexCommands = explode("\n", trim((string) file_get_contents($fakeCodexLog)));
    check(
        in_array('mcp remove weline', $codexCommands, true)
            && in_array('mcp remove weline-project-intelligence', $codexCommands, true)
            && in_array('mcp remove weline_project_intelligence', $codexCommands, true),
        'installer removes every legacy explicit MCP registration name',
    );

    $protocolInput = implode("
", [
        json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'weline-tests', 'version' => '1'],
            ],
        ], JSON_THROW_ON_ERROR),
        json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], JSON_THROW_ON_ERROR),
        json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list', 'params' => []], JSON_THROW_ON_ERROR),
        json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'health',
                'arguments' => (object) [],
            ],
        ], JSON_THROW_ON_ERROR),
    ]) . "
";
    $protocol = $runner->run(
        [PHP_BINARY, dirname(__DIR__) . '/bin/learning-mcp', '--config', $configPath],
        $root,
        $protocolInput,
        30,
        ['WELINE_MCP_TOOL_PROFILE' => 'compact'],
    );
    check($protocol['exit_code'] === 0, 'stdio MCP protocol smoke exits cleanly');
    $responses = [];
    foreach (explode(chr(10), trim($protocol['stdout'])) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['id'])) {
            $responses[(int) $decoded['id']] = $decoded;
        }
    }
    $initInstructions = (string) ($responses[1]['result']['instructions'] ?? '');
    check(str_contains($initInstructions, 'prepare_project'), 'initialize exposes prepare_project instructions');
    check(
        (str_contains($initInstructions, 'mandatory hard-rule gate') || str_contains($initInstructions, 'MANDATORY ensure'))
            && str_contains($initInstructions, 'host-native'),
        'initialize instructions require hard-rule gate with host-native coding',
    );
    $protocolTools = array_column($responses[2]['result']['tools'] ?? [], 'name');
    $sortedProtocolTools = $protocolTools;
    sort($sortedProtocolTools);
    check($sortedProtocolTools === $indexTools, 'protocol tools/list equals the nine index/knowledge tools');
    $resourceUris = array_column($responses[3]['result']['resources'] ?? [], 'uri');
    check($resourceUris === [], 'protocol resources/list is empty');
    $healthContent = $responses[4]['result']['content'] ?? [];
    $healthUsageLine = is_array($healthContent[0] ?? null) ? (string) ($healthContent[0]['text'] ?? '') : '';
    $healthStructured = is_array($responses[4]['result']['structuredContent'] ?? null)
        ? $responses[4]['result']['structuredContent']
        : [];
    check(
        str_starts_with($healthUsageLine, 'Weline：已调用 health')
            && (($healthStructured['_weline_mcp']['usage_line'] ?? '') === $healthUsageLine),
        'tools/call emits Weline usage_line receipt in content[0]',
    );
    $stdioIdle = $runner->run(
        [PHP_BINARY, __DIR__ . '/stdio-idle-socket.php'],
        $root,
        '',
        15,
    );
    $stdioIdleResult = json_decode(trim($stdioIdle['stdout']), true);
    check(
        $stdioIdle['exit_code'] === 0
            && (
                ($stdioIdleResult['survived'] ?? false) === true
                || ($stdioIdleResult['skipped'] ?? false) === true
            ),
        'stdio MCP survives socket idle longer than default_socket_timeout',
    );

    foreach ([
        'context-response-budget.php',
        'host-detection.php',
        'project-guidance-reload-policy.php',
        'readiness-incremental-scope.php',
        'index-directory-scope.php',
        'relation-resolution-scope.php',
        'session-start-background-refresh.php',
    ] as $regression) {
        $result = $runner->run([PHP_BINARY, __DIR__ . '/' . $regression], $root, '', 30);
        check($result['exit_code'] === 0, 'targeted regression: ' . $regression);
        if ($result['exit_code'] !== 0) {
            fwrite(STDERR, $result['stdout'] . $result['stderr']);
        }
    }

    if ($mode === 'full') {
        $acceptance = $runner->run(
            [PHP_BINARY, __DIR__ . '/acceptance.php'],
            $root,
            '',
            60,
        );
        check($acceptance['exit_code'] === 0, 'three-directory acceptance succeeds');
        if ($acceptance['stdout'] !== '') {
            fwrite(STDOUT, $acceptance['stdout']);
        }
        if ($acceptance['stderr'] !== '') {
            fwrite(STDERR, $acceptance['stderr']);
        }
    }
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, '[ERROR] ' . $exception::class . ': ' . $exception->getMessage() . "
");
} finally {
    removeTree($temporary);
}

$passed = count(array_filter($checks, static fn (array $check): bool => $check['passed']));
fwrite(STDOUT, sprintf("Weline MCP %s tests: %d/%d passed
", $mode, $passed, count($checks)));
exit($failed ? 1 : 0);
