<?php

declare(strict_types=1);

use LearningMcp\Analyzer;
use LearningMcp\Config;
use LearningMcp\DeployBridgeService;
use LearningMcp\EditService;
use LearningMcp\ExecutionRunService;
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

/** @return array<string,mixed> */
function editableBundle(string $bundleId, int $revision = 7): array
{
    return [
        'bundle_id' => $bundleId,
        'index_revision' => $revision,
        'ready_for_edit' => true,
        'candidate_files' => [[
            'path' => 'src/Example.php',
            'materialized' => true,
            'selected' => true,
            'reasons' => ['explicit path', 'definition'],
        ], [
            'path' => 'docs/unused.md',
            'materialized' => false,
            'excluded_reason' => 'not required by final edit context',
        ]],
        'exact_regions' => [[
            'path' => 'src/Example.php',
            'start_line' => 10,
            'end_line' => 18,
            'symbol' => 'Example::run',
            'expected_file_sha256' => 'sha256:' . str_repeat('a', 64),
            'expected_digest' => 'sha256:' . str_repeat('b', 64),
        ]],
        'context_completeness' => [
            'status' => 'complete',
            'score' => 100,
            'covered' => ['architecture', 'definitions', 'tests'],
            'missing' => [],
        ],
        'architecture_summary' => ['phase' => 'materialization', 'missing_roles' => []],
        'impact_summary' => ['risk' => 'low', 'dependency_edge_count' => 1],
        'validation_plan' => ['fixed_checks_in_apply' => ['syntax', 'regression', 'diff_check']],
        'impacts' => [['symbol' => 'Example::run', 'upstream_files' => ['src/Caller.php']]],
    ];
}

/** @return array<string,mixed> */
function editPlan(string $runId, string $bundleId, string $reason = 'NORMAL'): array
{
    return [
        'schema_version' => 'edit-plan.v1',
        'metadata' => [
            'run_id' => $runId,
            'bundle_id' => $bundleId,
            'recursion_reason' => $reason,
        ],
        'operations' => [[
            'kind' => 'replace_symbol',
            'path' => 'src/Example.php',
            'target_ref' => 'Example::run',
            'replacement' => "public function run(): bool
{
    return true;
}",
        ]],
    ];
}

/** @return array<string,mixed> */
function applyResult(string $editId, bool $validationPassed = true, bool $impactExpansion = false): array
{
    return [
        'edit_id' => $editId,
        'state' => $validationPassed ? 'validated' : 'rolled_back',
        'index_revision' => 8,
        'validation' => [
            'status' => $validationPassed ? 'passed' : 'failed',
            'checks' => [[
                'check' => 'php_lint',
                'path' => 'src/Example.php',
                'status' => $validationPassed ? 'passed' : 'failed',
            ]],
        ],
        'regression_validation' => [
            'status' => $validationPassed ? 'passed' : 'skipped',
            'profile' => 'fixture',
        ],
        'rolled_back' => !$validationPassed,
        'change_report' => [
            'files' => [[
                'path' => 'src/Example.php',
                'before_sha256' => 'sha256:' . str_repeat('a', 64),
                'after_sha256' => 'sha256:' . str_repeat('c', 64),
                'diff' => "--- a/src/Example.php
+++ b/src/Example.php
@@ -10 +10 @@
-false
+true
",
                'diff_truncated' => false,
            ]],
        ],
        'impact_delta' => [
            'requires_followup' => $impactExpansion,
            'new_affected_paths' => $impactExpansion ? ['src/NewConsumer.php'] : [],
            'new_affected_symbols' => $impactExpansion ? ['NewConsumer::call'] : [],
            'status' => 'complete',
        ],
        'timing_ms' => ['total' => 12],
    ];
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
    check($store->schemaVersion() === Store::SCHEMA_VERSION, 'global migrations reach current schema version');
    check(Store::SCHEMA_VERSION === 3, 'data lifecycle migration advances global schema to version 3');
    check(
        $config->get('editing.allowed_roots') === ['.'],
        'default editing policy covers the canonical repository',
    );
    check($config->get('knowledge.auto_generate_skills') === false, 'repository knowledge projection is forcibly retired');
    check($config->get('knowledge.auto_doc_sync') === false, 'automatic repository document writes are forcibly retired');
    check($config->get('knowledge.learning_skills.enabled') === false, 'learning projection worker is forcibly retired');
    check(
        in_array('knowledge.repository-projections:retired-in-0.13.0', $config->runtimeMigrations(), true),
        'legacy projection configuration retirement is auditable',
    );
    $customConfigPath = $temporary . '/config-custom-roots.json';
    file_put_contents($customConfigPath, json_encode([
        'data_dir' => $temporary . '/data-custom-roots',
        'analysis' => ['provider' => 'none'],
        'editing' => ['allowed_roots' => ['tests/unit']],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $customConfig = Config::load($customConfigPath);
    check(
        $customConfig->get('editing.allowed_roots') === ['tests/unit'],
        'custom editing roots remain a strict user policy',
    );
    check($config->duration('privacy.raw_session_ttl') === 14 * 86_400, 'raw session TTL defaults to fourteen days');
    check($config->duration('privacy.tombstone_ttl') === 14 * 86_400, 'ordinary tombstones default to fourteen days');
    check($config->duration('privacy.execution_run_ttl') === 14 * 86_400, 'completed execution traces default to fourteen days');
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
    $upgradeDatabase->exec((string) file_get_contents(dirname(__DIR__) . '/migrations/002_execution_runs.sql'));
    $upgradeDatabase->exec(
        "CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)"
    );
    $upgradeDatabase->exec(
        "INSERT INTO schema_migrations(version, name, applied_at) VALUES
            (1, '001_initial.sql', '2026-01-01T00:00:00.000Z'),
            (2, '002_execution_runs.sql', '2026-01-01T00:00:00.000Z')"
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
    check($upgradedStore->schemaVersion() === 3, 'populated v2 database upgrades transactionally to lifecycle schema');
    $upgradedOldExpiry = (string) $upgradedStore->database()->query(
        "SELECT raw_expires_at FROM sessions WHERE id = 'session:upgrade-old'"
    )->fetchColumn();
    check($upgradedOldExpiry === '2026-01-15T00:00:00.000Z', 'v2 upgrade derives immutable expiry from historical Session start');
    $upgradedFutureExpiry = (string) $upgradedStore->database()->query(
        "SELECT raw_expires_at FROM sessions WHERE id = 'session:upgrade-future'"
    )->fetchColumn();
    check(
        strtotime($upgradedFutureExpiry) <= time() + (14 * 86_400) + 5,
        'v2 upgrade caps a future Session timestamp at fourteen days from migration',
    );
    $upgradeIndexes = (int) $upgradedStore->database()->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name IN (
            'idx_experience_provenance_source_hash', 'idx_audit_log_created_at', 'idx_feedback_session'
        )"
    )->fetchColumn();
    check($upgradeIndexes === 3, 'v2 upgrade adds bounded-cleanup and compliance lookup indexes');
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
    $expiredRun = $store->database()->prepare(
        'INSERT INTO execution_runs(run_id, task_id, trace_id, project_id, task_digest,
            task_original_redacted, current_phase, status, started_at, updated_at, completed_at)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $expiredRun->execute([
        'run:retention-fixture',
        'task:retention-fixture',
        'trace:retention-fixture',
        $lifecycleProjectId,
        hash('sha256', 'retention fixture'),
        'retention fixture',
        'complete',
        'completed',
        '2026-01-01T00:00:00.000Z',
        '2026-01-01T00:00:00.000Z',
        '2026-01-01T00:00:00.000Z',
    ]);
    $expiredRun->execute([
        'run:planned-retention-fixture',
        'task:planned-retention-fixture',
        'trace:planned-retention-fixture',
        $lifecycleProjectId,
        hash('sha256', 'planned retention fixture'),
        'planned retention fixture',
        'context_check',
        'planned',
        '2026-01-01T00:00:00.000Z',
        '2026-01-01T00:00:00.000Z',
        '2026-01-01T00:00:00.000Z',
    ]);
    $retentionCleanup = $store->cleanupLifecycleRetention();
    check(($retentionCleanup['audit_rows_deleted'] ?? 0) >= 1, 'maintenance bounds audit history instead of growing forever');
    check(
        ($retentionCleanup['execution_runs_deleted'] ?? 0) === 2,
        'maintenance bounds completed and planned execution traces with their cascaded detail',
    );

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

    $fixtureIntelligence = new IntelligenceService($store, $config);
    $batchFixtureRoot = $temporary . '/context-batch-project';
    mkdir($batchFixtureRoot . '/src/Batch', 0700, true);
    $batchFixturePaths = [];
    for ($batchFile = 0; $batchFile < 220; ++$batchFile) {
        $batchPath = sprintf('src/Batch/BatchFile%02d.php', $batchFile);
        $batchFixturePaths[] = $batchPath;
        $batchSource = sprintf(
            "<?php\nfinal class BatchFile%02d { public function value(): int { return %d; } }\n",
            $batchFile,
            $batchFile,
        );
        if ($batchFile === 148) {
            $batchSource = "<?php\nfinal class ExistingOnlyClass {}\n";
        } elseif ($batchFile === 149) {
            $batchSource = "<?php\nnamespace Fixture\\Alpha;\n"
                . "final class DuplicateOrderService {"
                . " public function dispatch(): string { return 'alpha'; }"
                . " }\n";
        } elseif ($batchFile === 150) {
            $batchSource = "<?php\nnamespace Fixture\\Beta;\n"
                . "final class DuplicateOrderService {"
                . " public function dispatch(): string { return 'beta'; }"
                . " }\n";
        } elseif ($batchFile === 151) {
            $batchSource = "<?php\nnamespace Fixture;\n"
                . "final class LingxingOrderNormalizer {"
                . " public function compact(array \$payload): array { return array_filter(\$payload); }"
                . " }\n";
        } elseif ($batchFile === 152) {
            $batchSource = "<?php\nnamespace Fixture;\n"
                . "final class SyncFailureNotifier {\n"
                . " public function notifyFailure(string \$message): void {\n"
                . str_repeat("  \$message .= 'Update context batch';\n", 120)
                . " }\n"
                . "}\n";
        }
        file_put_contents(
            $batchFixtureRoot . '/' . $batchPath,
            $batchSource,
        );
    }
    file_put_contents(
        $batchFixtureRoot . '/src/Batch/NoiseConsumer.php',
        "<?php\nnamespace Fixture;\n"
            . "final class NoiseConsumer {"
            . " public function send(LingxingOrderNormalizer \$normalizer): array {"
            . " return \$normalizer->compact(['noise']);"
            . " }"
            . " }\n",
    );
    $batchFixtureIndex = new ProjectIndex(
        $config,
        ProjectResolver::resolve($batchFixtureRoot),
    );
    (new ProjectIndexer($batchFixtureIndex, $config))->index(['mode' => 'full']);
    $batchFixtureIndex->close();
    file_put_contents(
        $batchFixtureRoot . '/src/Batch/LateIndexedService.php',
        "<?php\nnamespace Fixture;\n"
            . "final class LateIndexedService { public function run(): string { return 'fresh'; } }\n",
    );
    $batchFixtureIntelligence = new IntelligenceService($store, $config);
    $batchBoundaryError = null;
    try {
        $batchBundle = $batchFixtureIntelligence->call('get_edit_bundle', [
            'repository' => $batchFixtureRoot,
            'task' => 'Update one literal in every explicitly listed batch source file',
            'paths' => $batchFixturePaths,
            'max_regions' => 48,
            'max_chunks_per_file' => 1,
            'token_budget' => 24_000,
            'task_contract' => [
                'goal' => 'Update all explicitly listed batch source files without dropping overflow paths',
                'known_paths' => $batchFixturePaths,
                'known_symbols' => [
                    'LingxingOrderNormalizer::compact',
                    'SyncFailureNotifier::notifyFailure',
                ],
                'allowed_scope' => ['src/Batch/**'],
            ],
            'include_docs' => false,
            'include_skills' => false,
        ]);
    } catch (ToolException $exception) {
        $batchBoundaryError = $exception;
        $batchBundle = [];
    }
    $batchPlan = is_array($batchBundle['continuation']['batch_plan'] ?? null)
        ? $batchBundle['continuation']['batch_plan']
        : [];
    $plannedBatchPaths = [];
    foreach ((array) ($batchPlan['path_batches'] ?? []) as $pathBatch) {
        if (is_array($pathBatch)) {
            $plannedBatchPaths = array_merge($plannedBatchPaths, $pathBatch);
        }
    }
    $materializedBatchPaths = array_values(array_filter(array_map(
        static fn (array $region): string => (string) ($region['path'] ?? ''),
        $batchBundle['exact_regions'] ?? [],
    )));
    $expectedOverflowPaths = array_values(array_diff(
        $batchFixturePaths,
        $materializedBatchPaths,
    ));
    check(
        $batchBoundaryError === null
            && ($batchBundle['status'] ?? '') === 'CONTEXT_BATCH_PLANNED'
            && ($batchBundle['ready_for_edit'] ?? true) === false,
        'context capacity produces a planned batch state instead of a failed bundle',
    );
    check(
        count($expectedOverflowPaths) > 100
            && ($batchPlan['schema_version'] ?? '') === 'context-batch-plan.v1'
            && ($batchPlan['batch_count'] ?? 0) >= 1
            && array_diff($expectedOverflowPaths, $plannedBatchPaths) === []
            && array_diff($expectedOverflowPaths, $batchBundle['missing_paths'] ?? []) === [],
        'context batch plan covers every path omitted by the bounded first response',
    );
    check(
        ($batchBundle['execution_run']['workflow_state'] ?? '') === 'CONTEXT_BATCH_PLANNED'
            && ($batchBundle['execution_run']['status'] ?? '') === 'planned'
            && ($batchBundle['execution_run']['terminal'] ?? false) === true,
        'capacity-limited parent terminates as an executable plan instead of waiting forever',
    );
    $contextChildRequests = is_array($batchPlan['child_requests'] ?? null)
        ? $batchPlan['child_requests']
        : [];
    check(
        count($contextChildRequests) === (int) ($batchPlan['batch_count'] ?? -1)
            && array_reduce(
                $contextChildRequests,
                static fn (bool $valid, array $request): bool => $valid
                    && ($request['next_tool'] ?? '') === 'get_edit_bundle'
                    && count((array) ($request['input']['paths'] ?? [])) <= 40,
                true,
            )
            && ($batchBundle['write_contract']['parent_apply_allowed'] ?? true) === false,
        'context capacity returns bounded child requests that can be executed directly',
    );
    $firstContextChildInput = is_array($contextChildRequests[0]['input'] ?? null)
        ? $contextChildRequests[0]['input']
        : [];
    $firstContextChildBundle = $firstContextChildInput === []
        ? []
        : $batchFixtureIntelligence->call('get_edit_bundle', $firstContextChildInput);
    check(
        ($firstContextChildBundle['ready_for_edit'] ?? false) === true
            && ($firstContextChildBundle['execution_run']['status'] ?? '') === 'waiting_for_plan'
            && ($firstContextChildBundle['task_contract']['known_paths'] ?? [])
                === ($firstContextChildInput['paths'] ?? [])
            && ($firstContextChildBundle['write_contract']['parent_apply_allowed'] ?? false) === true,
        'first planned context child closes independently with its own writable run and bundle',
    );
    $exactTargetRegions = [];
    $exactTargetRegionCounts = [];
    foreach ((array) ($batchBundle['exact_regions'] ?? []) as $region) {
        $targetRef = (string) ($region['target_ref'] ?? '');
        if ($targetRef !== '') {
            $exactTargetRegions[$targetRef] = $region;
            $exactTargetRegionCounts[$targetRef] = ($exactTargetRegionCounts[$targetRef] ?? 0) + 1;
        }
    }
    $compactRegion = $exactTargetRegions['Fixture\\LingxingOrderNormalizer::compact'] ?? [];
    $notifyRegion = $exactTargetRegions['Fixture\\SyncFailureNotifier::notifyFailure'] ?? [];
    check(
        $compactRegion !== []
            && $notifyRegion !== []
            && (string) ($compactRegion['symbol_uid'] ?? '') !== ''
            && (string) ($notifyRegion['symbol_uid'] ?? '') !== ''
            && (string) ($compactRegion['expected_file_sha256'] ?? '') !== ''
            && (string) ($notifyRegion['expected_file_sha256'] ?? '') !== ''
            && (string) ($compactRegion['expected_digest'] ?? '') !== ''
            && (string) ($notifyRegion['expected_digest'] ?? '') !== ''
            && ($exactTargetRegionCounts['Fixture\\LingxingOrderNormalizer::compact'] ?? 0) === 1
            && ($exactTargetRegionCounts['Fixture\\SyncFailureNotifier::notifyFailure'] ?? 0) === 1,
        'exact TaskContract symbols keep guarded regions ahead of the 48-region capacity limit',
    );
    check(
        ($batchBundle['missing_symbols'] ?? ['missing']) === [],
        'short class method targets match fully-qualified indexed definitions',
    );
    $manySymbolPaths = array_slice($batchFixturePaths, 0, 25);
    $manySymbols = array_map(
        static fn (int $index): string => sprintf('BatchFile%02d', $index),
        range(0, 24),
    );
    $manySymbolBundle = $batchFixtureIntelligence->call('get_edit_bundle', [
        'repository' => $batchFixtureRoot,
        'task' => 'Resolve every one of twenty-five explicit class targets',
        'paths' => $manySymbolPaths,
        'task_contract' => [
            'goal' => 'Keep every explicit symbol instead of silently truncating the contract',
            'known_paths' => $manySymbolPaths,
            'known_symbols' => $manySymbols,
            'allowed_scope' => ['src/Batch/**'],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    check(
        count((array) ($manySymbolBundle['task_contract']['known_symbols'] ?? [])) === 25
            && count((array) ($manySymbolBundle['required_symbols'] ?? [])) === 25
            && ($manySymbolBundle['missing_symbols'] ?? ['missing']) === [],
        'edit bundle preserves and resolves more than twenty-four explicit symbols',
    );
    $capacitySymbolIndexes = array_values(array_filter(
        range(0, 219),
        static fn (int $index): bool => !in_array($index, [148, 149, 150, 151, 152], true),
    ));
    $capacitySymbols = array_map(
        static fn (int $index): string => sprintf('BatchFile%02d', $index),
        $capacitySymbolIndexes,
    );
    $capacitySymbolError = null;
    try {
        $capacitySymbolBundle = $batchFixtureIntelligence->call('get_edit_bundle', [
            'repository' => $batchFixtureRoot,
            'task' => 'Resolve more than two hundred explicit class targets through bounded batches',
            'symbols' => $capacitySymbols,
            'task_contract' => [
                'goal' => 'Plan every explicit symbol beyond the first response capacity without failing',
                'known_symbols' => $capacitySymbols,
                'allowed_scope' => ['src/Batch/**'],
            ],
            'include_docs' => false,
            'include_skills' => false,
        ]);
    } catch (ToolException $exception) {
        $capacitySymbolError = $exception;
        $capacitySymbolBundle = [];
    }
    $capacitySymbolChildren = (array) (
        $capacitySymbolBundle['continuation']['batch_plan']['child_requests'] ?? []
    );
    check(
        $capacitySymbolError === null
            && count((array) ($capacitySymbolBundle['task_contract']['known_symbols'] ?? [])) > 200
            && ($capacitySymbolBundle['status'] ?? '') === 'CONTEXT_BATCH_PLANNED'
            && count($capacitySymbolChildren) >= 9
            && array_reduce(
                $capacitySymbolChildren,
                static fn (bool $valid, array $request): bool => $valid
                    && count((array) ($request['input']['symbols'] ?? [])) <= 20,
                true,
            ),
        'explicit symbol capacity above two hundred produces bounded child requests instead of failure',
    );
    $missingMethodBundle = $batchFixtureIntelligence->call('get_edit_bundle', [
        'repository' => $batchFixtureRoot,
        'task' => 'Resolve one missing method on an existing indexed class',
        'paths' => ['src/Batch/BatchFile148.php'],
        'task_contract' => [
            'goal' => 'Do not let a class declaration satisfy a requested method target',
            'known_paths' => ['src/Batch/BatchFile148.php'],
            'known_symbols' => ['ExistingOnlyClass::missingMethod'],
            'allowed_scope' => ['src/Batch/**'],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    check(
        ($missingMethodBundle['status'] ?? '') === 'CONTEXT_TARGET_NOT_FOUND'
            && in_array(
                'ExistingOnlyClass::missingMethod',
                (array) ($missingMethodBundle['missing_symbols'] ?? []),
                true,
            ),
        'an existing class cannot falsely satisfy a missing method target',
    );
    $ambiguousBundle = $batchFixtureIntelligence->call('get_edit_bundle', [
        'repository' => $batchFixtureRoot,
        'task' => 'Resolve one short method target shared by two namespaces',
        'paths' => [
            'src/Batch/BatchFile149.php',
            'src/Batch/BatchFile150.php',
        ],
        'task_contract' => [
            'goal' => 'Report an ambiguous short symbol instead of selecting the first path',
            'known_paths' => [
                'src/Batch/BatchFile149.php',
                'src/Batch/BatchFile150.php',
            ],
            'known_symbols' => ['DuplicateOrderService::dispatch'],
            'allowed_scope' => ['src/Batch/**'],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    check(
        ($ambiguousBundle['status'] ?? '') === 'CONTEXT_TARGET_AMBIGUOUS'
            && count((array) (
                $ambiguousBundle['ambiguous_targets']['symbols'][0]['candidates'] ?? []
            )) === 2,
        'ambiguous short method target returns both candidates instead of choosing one',
    );
    $lateIndexedBundle = $batchFixtureIntelligence->call('get_edit_bundle', [
        'repository' => $batchFixtureRoot,
        'task' => 'Resolve Fixture LateIndexedService run after the current index was built',
        'symbols' => ['Fixture\\LateIndexedService::run'],
        'task_contract' => [
            'goal' => 'Refresh a symbol-only discovery request before proving the target absent',
            'known_symbols' => ['Fixture\\LateIndexedService::run'],
            'allowed_scope' => ['src/Batch/**'],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    check(
        ($lateIndexedBundle['status'] ?? '') !== 'CONTEXT_TARGET_NOT_FOUND'
            && ($lateIndexedBundle['missing_symbols'] ?? ['missing']) === [],
        'symbol-only request refreshes a current-but-incomplete index before target-not-found',
    );
    check(
        !in_array('src/Batch/NoiseConsumer.php', $plannedBatchPaths, true)
            && array_diff($plannedBatchPaths, $batchBundle['missing_paths'] ?? []) === [],
        'context continuation contains only required missing paths, never optional upstream candidates',
    );
    $absentBundle = $batchFixtureIntelligence->call('get_edit_bundle', [
        'repository' => $batchFixtureRoot,
        'task' => 'Resolve one explicitly requested symbol that is absent from the refreshed scope',
        'paths' => ['src/Batch/BatchFile00.php'],
        'task_contract' => [
            'goal' => 'Report an actually absent exact symbol without retrying the same context batch',
            'known_paths' => ['src/Batch/BatchFile00.php'],
            'known_symbols' => ['MissingOrderService::dispatch'],
            'allowed_scope' => ['src/Batch/**'],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    check(
        ($absentBundle['status'] ?? '') === 'CONTEXT_TARGET_NOT_FOUND'
            && ($absentBundle['continuation_needed'] ?? true) === false
            && ($absentBundle['continuation']['batch_plan']['batch_count'] ?? -1) === 0
            && ($absentBundle['execution_run']['workflow_state'] ?? '') === 'CONTEXT_TARGET_NOT_FOUND'
            && ($absentBundle['execution_run']['terminal'] ?? false) === true
            && ($absentBundle['unresolved_targets']['symbols'][0]['reason'] ?? '')
                === 'exact_symbol_not_found_in_refreshed_scope',
        'truly absent exact symbol terminates once with bounded evidence instead of repeating context batches',
    );
    $batchBoundaryError = null;
    unset($batchFixtureIntelligence);
    gc_collect_cycles();
    $testEditBundle = $fixtureIntelligence->call('get_edit_bundle', [
        'repository' => $fixtureRoot,
        'task' => 'Update one explicit unit test inside the current repository',
        'paths' => ['tests/unit/ExplicitTest.php'],
        'task_contract' => [
            'goal' => 'Update the explicit unit test',
            'known_paths' => ['tests/unit/ExplicitTest.php'],
            'allowed_scope' => ['tests/unit/ExplicitTest.php'],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    $testEditError = null;
    try {
        $fixtureIntelligence->call('apply_compact_edit', [
            'repository' => $fixtureRoot,
            'run_id' => (string) ($testEditBundle['run_id'] ?? ''),
            'bundle_id' => (string) ($testEditBundle['bundle_id'] ?? ''),
            'plan' => [
                'schema_version' => 'edit-plan.v1',
                'project_revision' => (int) ($testEditBundle['index_revision'] ?? 0),
                'metadata' => [
                    'task' => 'Update one explicit unit test inside the current repository',
                    'run_id' => (string) ($testEditBundle['run_id'] ?? ''),
                    'bundle_id' => (string) ($testEditBundle['bundle_id'] ?? ''),
                ],
                'operations' => [[
                    'kind' => 'replace_text',
                    'path' => 'tests/unit/ExplicitTest.php',
                    'search' => 'return true;',
                    'replacement' => 'return false;',
                    'expected_file_sha256' => (string) (
                        $testEditBundle['expected_file_hashes']['tests/unit/ExplicitTest.php'] ?? ''
                    ),
                ]],
                'validation_profile' => 'diff_check',
            ],
        ]);
    } catch (ToolException $exception) {
        $testEditError = $exception;
    }
    check(
        $testEditError === null,
        'task-scoped tests unit path passes compact edit policy',
    );
    check(
        $testEditError === null
            && str_contains((string) file_get_contents($fixtureRoot . '/tests/unit/ExplicitTest.php'), 'return false;'),
        'task-scoped tests unit edit reaches the guarded workspace apply',
    );
    unset($testEditError);

    $operationBatchBundle = $fixtureIntelligence->call('get_edit_bundle', [
        'repository' => $fixtureRoot,
        'task' => 'Update one explicit unit test inside the current repository',
        'paths' => ['tests/unit/ExplicitTest.php'],
        'task_contract' => [
            'goal' => 'Update the explicit unit test',
            'known_paths' => ['tests/unit/ExplicitTest.php'],
            'allowed_scope' => ['tests/unit/ExplicitTest.php'],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    $operationBatchOperations = [];
    for ($operationNumber = 0; $operationNumber < 51; ++$operationNumber) {
        $operationBatchOperations[] = [
            'op_id' => sprintf('batch-operation-%02d', $operationNumber),
            'kind' => 'replace_text',
            'path' => 'tests/unit/ExplicitTest.php',
            'search' => sprintf('missing-operation-%02d', $operationNumber),
            'replacement' => sprintf('replacement-operation-%02d', $operationNumber),
        ];
    }
    $operationBatchBefore = (string) file_get_contents(
        $fixtureRoot . '/tests/unit/ExplicitTest.php',
    );
    $operationBatchError = null;
    $operationBatchResult = [];
    try {
        $operationBatchResult = $fixtureIntelligence->call('apply_compact_edit', [
            'repository' => $fixtureRoot,
            'run_id' => (string) ($operationBatchBundle['run_id'] ?? ''),
            'bundle_id' => (string) ($operationBatchBundle['bundle_id'] ?? ''),
            'plan' => [
                'schema_version' => 'edit-plan.v1',
                'project_revision' => (int) ($operationBatchBundle['index_revision'] ?? 0),
                'metadata' => [
                    'task' => 'Update one explicit unit test inside the current repository',
                    'run_id' => (string) ($operationBatchBundle['run_id'] ?? ''),
                    'bundle_id' => (string) ($operationBatchBundle['bundle_id'] ?? ''),
                ],
                'operations' => $operationBatchOperations,
                'validation_profile' => 'diff_check',
            ],
        ]);
    } catch (ToolException $exception) {
        $operationBatchError = $exception;
    }
    $applyBatchPlan = is_array($operationBatchResult['batch_plan'] ?? null)
        ? $operationBatchResult['batch_plan']
        : [];
    check(
        $operationBatchError === null
            && ($operationBatchResult['status'] ?? '') === 'EDIT_BATCH_PLANNED'
            && ($applyBatchPlan['schema_version'] ?? '') === 'apply-batch-plan.v1'
            && ($applyBatchPlan['batch_count'] ?? 0) === 2
            && array_column($applyBatchPlan['batches'] ?? [], 'operation_count') === [50, 1],
        'operation capacity returns an ordered 50-plus-1 apply batch plan instead of failure',
    );
    check(
        ($operationBatchResult['execution_run']['workflow_state'] ?? '') === 'EDIT_BATCHING'
            && ($operationBatchResult['execution_run']['status'] ?? '') === 'waiting_for_edit_batch'
            && ($operationBatchResult['execution_run']['terminal'] ?? true) === false
            && (string) file_get_contents($fixtureRoot . '/tests/unit/ExplicitTest.php')
                === $operationBatchBefore,
        'oversized apply parent remains non-terminal and performs no file write',
    );
    $operationBatchError = null;
    gc_collect_cycles();

    $reviewConfigPath = $temporary . '/paged-review-config.json';
    file_put_contents($reviewConfigPath, json_encode([
        'data_dir' => $temporary . '/paged-review-data',
        'analysis' => ['provider' => 'none'],
        'editing' => ['max_files' => 30],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $reviewConfig = Config::load($reviewConfigPath);
    $reviewRoot = $temporary . '/paged-review-project';
    mkdir($reviewRoot . '/src', 0700, true);
    $reviewPaths = [];
    $reviewOperations = [];
    for ($fileNumber = 0; $fileNumber < 21; ++$fileNumber) {
        $path = sprintf('src/Review%02d.php', $fileNumber);
        $lineCount = $fileNumber === 0 ? 420 : 50;
        $before = "<?php\n";
        $after = "<?php\n";
        for ($lineNumber = 0; $lineNumber < $lineCount; ++$lineNumber) {
            $before .= sprintf(
                "// before-review-%02d-%04d-%s\n",
                $fileNumber,
                $lineNumber,
                str_repeat('a', 48),
            );
            $after .= sprintf(
                "// after-review-%02d-%04d-%s\n",
                $fileNumber,
                $lineNumber,
                str_repeat('b', 48),
            );
        }
        if ($fileNumber === 0) {
            $after .= "/*\n-----BEGIN TEST PRIVATE KEY-----\n"
                . "private-review-secret-material\n"
                . "-----END TEST PRIVATE KEY-----\n*/\n";
        }
        file_put_contents($reviewRoot . '/' . $path, $before);
        $reviewPaths[] = $path;
        $reviewOperations[] = [
            'kind' => 'replace_text',
            'path' => $path,
            'search' => $before,
            'replacement' => $after,
            'expected_file_sha256' => 'sha256:' . hash('sha256', $before),
        ];
    }
    $reviewResolved = ProjectResolver::resolve($reviewRoot);
    $reviewIndex = new ProjectIndex($reviewConfig, $reviewResolved);
    $reviewIndexer = new ProjectIndexer($reviewIndex, $reviewConfig);
    $reviewIndexer->indexPaths($reviewPaths);
    $reviewEdit = new EditService($reviewIndex, $reviewIndexer, $reviewConfig);
    $reviewStore = new Store($reviewConfig);
    $reviewIntelligence = new IntelligenceService($reviewStore, $reviewConfig);
    $reviewPrepared = $reviewEdit->prepare([
        'schema_version' => 'edit-plan.v1',
        'project_id' => $reviewIndex->projectId(),
        'project_revision' => $reviewIndex->revision(),
        'operations' => $reviewOperations,
        'validation_profile' => 'diff_check',
    ]);
    $reviewPage = $reviewEdit->apply(
        (string) $reviewPrepared['apply_token'],
        (string) $reviewPrepared['plan_digest'],
    );
    $firstReviewContract = $reviewPage['change_report']['review_contract'] ?? [];
    check(
        ($firstReviewContract['has_more'] ?? false) === true
            && trim((string) ($firstReviewContract['next_cursor'] ?? '')) !== '',
        'oversized multi-file review returns a resumable bounded cursor instead of terminal truncation',
    );
    $firstReviewCursor = trim((string) ($firstReviewContract['next_cursor'] ?? ''));
    $decodedReviewCursor = strtr($firstReviewCursor, '-_', '+/');
    $cursorPadding = strlen($decodedReviewCursor) % 4;
    if ($cursorPadding !== 0) {
        $decodedReviewCursor .= str_repeat('=', 4 - $cursorPadding);
    }
    $decodedReviewCursor = base64_decode($decodedReviewCursor, true);
    $tamperedReviewPayload = is_string($decodedReviewCursor)
        ? json_decode($decodedReviewCursor, true)
        : null;
    if (is_array($tamperedReviewPayload)) {
        $tamperedReviewPayload['diff_offset'] = (int) ($tamperedReviewPayload['diff_offset'] ?? 0) + 1;
        $tamperedReviewCursor = rtrim(strtr(base64_encode(json_encode(
            $tamperedReviewPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )), '+/', '-_'), '=');
    } else {
        $tamperedReviewCursor = 'invalid-review-cursor';
    }
    $reviewCursorError = null;
    try {
        $reviewIntelligence->call('get_edit_status', [
            'repository' => $reviewRoot,
            'edit_id' => (string) $reviewPrepared['edit_id'],
            'review_cursor' => $tamperedReviewCursor,
        ]);
    } catch (ToolException $exception) {
        $reviewCursorError = $exception;
    }
    check(
        $reviewCursorError?->errorCode === 'REVIEW_CURSOR_INVALID',
        'sealed review rejects a modified or cross-transaction cursor',
    );
    unset($reviewCursorError);

    $reviewedDiffs = [];
    $reviewCursors = [];
    $reviewPageCount = 0;
    $reviewStreamComplete = false;
    while ($reviewPageCount < 64) {
        ++$reviewPageCount;
        foreach ($reviewPage['change_report']['files'] ?? [] as $reviewFile) {
            if (!is_array($reviewFile) || empty($reviewFile['diff_included'])) {
                continue;
            }
            $reviewPath = (string) ($reviewFile['path'] ?? '');
            $reviewedDiffs[$reviewPath] = ($reviewedDiffs[$reviewPath] ?? '')
                . (string) ($reviewFile['diff'] ?? '');
        }
        $reviewContract = $reviewPage['change_report']['review_contract'] ?? [];
        $nextCursor = trim((string) ($reviewContract['next_cursor'] ?? ''));
        if ($nextCursor === '') {
            $reviewStreamComplete = ($reviewContract['complete'] ?? false) === true;
            break;
        }
        if (isset($reviewCursors[$nextCursor])) {
            break;
        }
        $reviewCursors[$nextCursor] = true;
        $reviewPage = $reviewIntelligence->call('get_edit_status', [
            'repository' => $reviewRoot,
            'edit_id' => (string) $reviewPrepared['edit_id'],
            'review_cursor' => $nextCursor,
        ]);
    }
    check(
        $reviewPageCount > 1 && $reviewPageCount < 64 && $reviewStreamComplete,
        'review cursor reaches a deterministic complete terminal page',
    );
    check(
        count($reviewedDiffs) === count($reviewPaths)
            && array_diff($reviewPaths, array_keys($reviewedDiffs)) === []
            && !str_contains(implode('', $reviewedDiffs), '[diff truncated]'),
        'paged review covers every changed file and every large diff without truncation markers',
    );
    check(
        !str_contains(implode('', $reviewedDiffs), 'PRIVATE KEY')
            && !str_contains(implode('', $reviewedDiffs), 'private-review-secret-material'),
        'paged review preserves multiline private-key redaction across chunk boundaries',
    );
    $reviewIndex->close();
    unset($reviewIntelligence);
    $reviewStore->close();
    unset($reviewStore, $reviewEdit, $reviewIndexer, $reviewIndex);

    file_put_contents(
        $fixtureRoot . '/tests/unit/ExplicitTest.php',
        "<?php\nfinal class ExplicitTest { public function testIt(): bool { return true; } }\n",
    );
    $restrictedConfigPath = $temporary . '/restricted-config.json';
    file_put_contents($restrictedConfigPath, json_encode([
        'data_dir' => $temporary . '/restricted-data',
        'analysis' => ['provider' => 'none'],
        'editing' => ['allowed_roots' => ['src']],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $restrictedConfig = Config::load($restrictedConfigPath);
    $restrictedResolved = ProjectResolver::resolve($fixtureRoot);
    $restrictedIndex = new ProjectIndex($restrictedConfig, $restrictedResolved);
    $restrictedIndexer = new ProjectIndexer($restrictedIndex, $restrictedConfig);
    $restrictedIndexer->indexPaths(['tests/unit/ExplicitTest.php']);
    $restrictedEdit = new EditService($restrictedIndex, $restrictedIndexer, $restrictedConfig);
    $restrictedError = null;
    try {
        $restrictedEdit->prepare([
            'schema_version' => 'edit-plan.v1',
            'project_id' => $restrictedIndex->projectId(),
            'project_revision' => $restrictedIndex->revision(),
            'operations' => [[
                'kind' => 'replace_text',
                'path' => 'tests/unit/ExplicitTest.php',
                'search' => 'return true;',
                'replacement' => 'return false;',
                'expected_file_sha256' => 'sha256:' . hash_file(
                    'sha256',
                    $fixtureRoot . '/tests/unit/ExplicitTest.php',
                ),
            ]],
        ]);
    } catch (ToolException $exception) {
        $restrictedError = $exception;
    }
    check(
        $restrictedError?->errorCode === 'EDIT_PATH_DENIED'
            && ($restrictedError->details['policy_reason'] ?? '') === 'outside_configured_allowed_roots'
            && ($restrictedError->details['filesystem_permissions_evaluated'] ?? true) === false,
        'custom root denial is reported as policy rather than filesystem permissions',
    );
    unset($restrictedError);
    $restrictedIndex->close();
    unset($restrictedEdit, $restrictedIndexer, $restrictedIndex);

    $securityResolved = ProjectResolver::resolve($fixtureRoot);
    $securityIndex = new ProjectIndex($config, $securityResolved);
    $securityEdit = new EditService(
        $securityIndex,
        new ProjectIndexer($securityIndex, $config),
        $config,
    );
    $securityError = null;
    try {
        $securityEdit->prepare([
            'schema_version' => 'edit-plan.v1',
            'project_id' => $securityIndex->projectId(),
            'project_revision' => $securityIndex->revision(),
            'operations' => [[
                'kind' => 'create_file',
                'path' => '.git/config',
                'content' => "unsafe\n",
            ]],
        ]);
    } catch (ToolException $exception) {
        $securityError = $exception;
    }
    check(
        $securityError?->errorCode === 'EDIT_PATH_DENIED'
            && ($securityError->details['policy_reason'] ?? '') === 'security_sensitive_path',
        'repository-wide editing still rejects security-sensitive paths',
    );
    unset($securityError);
    $securityIndex->close();
    unset($securityEdit, $securityIndex);

    file_put_contents(
        $fixtureRoot . '/tests/ContextClosureTest.php',
        "<?php\nfinal class ContextClosureTest { public function testBundle(): bool { return true; } }\n",
    );
    $closureBundle = $fixtureIntelligence->call('get_edit_bundle', [
        'repository' => $fixtureRoot,
        'task' => 'MCP UI plugin documentation validation retrieval service one-call context closure',
        'paths' => ['src/ToolService.php', 'ui/panel.html'],
        'task_contract' => [
            'goal' => 'Close all expected context roles inside one bundle call',
            'known_paths' => ['src/ToolService.php', 'ui/panel.html'],
            'requirements' => ['discover and materialize architecture roles server-side'],
            'acceptance_criteria' => ['ready_for_edit is true', 'continuation is not needed'],
            'allowed_scope' => ['current_project'],
            'authorized_actions' => ['read indexed context'],
            'forbidden_scope' => ['writes', 'external systems'],
        ],
        'include_skills' => false,
    ]);
    check($closureBundle['ready_for_edit'] === true, 'one bundle closes server-discovered architecture roles');
    check($closureBundle['continuation_needed'] === false, 'closed bundle requires no external continuation');
    check(
        count($closureBundle['selected_files'] ?? []) > 2,
        'one bundle materializes multiple related files beyond explicit paths',
    );
    check(
        ($closureBundle['context_completeness']['missing_roles'] ?? ['missing']) === [],
        'role discovery covers entrypoint, view, plugin, retrieval, docs and tests',
    );
    check(
        in_array(
            'tests/ContextClosureTest.php',
            $closureBundle['on_demand_index']['changed_paths'] ?? [],
            true,
        ),
        'cold test context is discovered and indexed inside the same MCP call',
    );
    check(
        ($closureBundle['execution_run']['counters']['get_edit_bundle_calls'] ?? 0) === 1,
        'server-side role closure remains one audited bundle call',
    );
    $originalCwd = getcwd();
    if (!is_string($originalCwd) || !chdir($fixtureRoot)) {
        throw new RuntimeException('Unable to enter repository inference fixture');
    }
    try {
        $inferredBundle = $fixtureIntelligence->call('get_edit_bundle', [
            'task' => 'MCP UI plugin documentation validation retrieval service inferred repository',
            'paths' => ['src/ToolService.php', 'ui/panel.html'],
            'include_skills' => false,
        ]);
    } finally {
        chdir($originalCwd);
    }
    check(
        ($inferredBundle['repository_resolution']['source'] ?? '')
            === 'process_cwd_validated_by_paths',
        'omitted repository is safely inferred only after all known paths validate',
    );
    check(
        ($inferredBundle['repository_resolution']['repository'] ?? '') === realpath($fixtureRoot),
        'inferred repository preserves canonical directory project identity',
    );
    unset($fixtureIntelligence);

    $moduleFixtureRoot = $temporary . '/module-scoped-discovery-project';
    foreach ([
        'app/code/Jhll/Center/Controller/Backend/Product',
        'app/code/Jhll/Center/Model',
        'app/code/Jhll/Center/Service',
        'app/code/Jhll/Center/view/adminhtml/templates/product',
        'app/code/Jhll/Center/view/adminhtml/templates/noise',
        'app/code/Jhll/Center/view/tpl/product',
        'app/code/Noise/Tools/Service',
        'docs',
    ] as $directory) {
        mkdir($moduleFixtureRoot . '/' . $directory, 0700, true);
    }
    file_put_contents(
        $moduleFixtureRoot . '/app/code/Jhll/Center/Controller/Backend/Product/Listing.php',
        "<?php\nnamespace Jhll\\Center\\Controller\\Backend\\Product;\n"
            . "final class Listing { public function execute(): array { return []; } }\n",
    );
    file_put_contents(
        $moduleFixtureRoot . '/app/code/Jhll/Center/Service/ProductThumbnail.php',
        "<?php\nnamespace Jhll\\Center\\Service;\n"
            . "final class ProductThumbnail { public function size(): int { return 48; } }\n",
    );
    file_put_contents(
        $moduleFixtureRoot . '/app/code/Jhll/Center/Model/ProductInventory.php',
        "<?php\nnamespace Jhll\\Center\\Model;\n"
            . "final class ProductInventory { public function quantity(): int { return 1; } }\n",
    );
    file_put_contents(
        $moduleFixtureRoot . '/app/code/Jhll/Center/view/adminhtml/templates/product/list.phtml',
        "<img class=\"product-thumbnail\" width=\"48\" height=\"48\" alt=\"Product thumbnail\">\n",
    );
    for ($template = 0; $template < 30; ++$template) {
        file_put_contents(
            $moduleFixtureRoot
                . '/app/code/Jhll/Center/view/adminhtml/templates/noise/item'
                . $template
                . '.phtml',
            "<img class=\"product-thumbnail\" width=\"48\" height=\"48\" alt=\"Product thumbnail\">\n",
        );
    }
    file_put_contents(
        $moduleFixtureRoot . '/app/code/Jhll/Center/view/tpl/product/forbidden.phtml',
        "<img class=\"forbidden-template\" alt=\"Must never be indexed\">\n",
    );
    file_put_contents(
        $moduleFixtureRoot . '/app/code/Noise/Tools/Service/ToolService.php',
        "<?php\nnamespace Noise\\Tools\\Service;\n"
            . "final class ToolService { public function getEditBundle(): array { return []; } }\n",
    );
    file_put_contents(
        $moduleFixtureRoot . '/docs/MCP.md',
        "# MCP retrieval plugin manifest documentation\n",
    );
    $moduleFixtureResolved = ProjectResolver::resolve($moduleFixtureRoot);
    $moduleFixtureIndex = new ProjectIndex($config, $moduleFixtureResolved);
    $moduleFixtureIndexer = new ProjectIndexer($moduleFixtureIndex, $config);
    $moduleFixtureFull = $moduleFixtureIndexer->index(['mode' => 'full']);
    check(
        !in_array(
            'app/code/Jhll/Center/view/tpl/product/forbidden.phtml',
            $moduleFixtureFull['changed_paths'] ?? [],
            true,
        ),
        'module-scoped discovery fixture preserves the built-in view/tpl exclusion',
    );
    $moduleFixtureIndex->close();

    $moduleFixtureIntelligence = new IntelligenceService($store, $config);
    $moduleBundle = $moduleFixtureIntelligence->call('get_edit_bundle', [
        'repository' => $moduleFixtureRoot,
        'task' => 'MCP 仍然找不到 Jhll_Center 后台产品列表商品图片缩略图来源，修正并验证',
        'symbols' => ['后台产品列表商品图片缩略图', '库存展示来源'],
        'module' => 'Jhll_Center',
        'task_contract' => [
            'goal' => '放大 Jhll_Center 后台产品列表的商品图片缩略图',
            'allowed_scope' => ['app/code/Jhll/Center/**'],
            'forbidden_scope' => ['**/view/tpl/**', 'app/code/Noise/**'],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    $moduleCandidatePaths = array_values(array_filter(array_map(
        static fn (array $candidate): string => (string) ($candidate['path'] ?? ''),
        $moduleBundle['candidate_paths'] ?? [],
    )));
    check(
        ($moduleBundle['ready_for_edit'] ?? false) === true,
        'module-scoped no-path bundle closes context in one call',
    );
    check(
        $moduleCandidatePaths !== []
            && array_reduce(
                $moduleCandidatePaths,
                static fn (bool $inside, string $path): bool =>
                    $inside && str_starts_with($path, 'app/code/Jhll/Center/'),
                true,
            ),
        'module and TaskContract allowed scope constrain every edit candidate',
    );
    check(
        in_array(
            'app/code/Jhll/Center/view/adminhtml/templates/product/list.phtml',
            $moduleCandidatePaths,
            true,
        )
            && in_array(
                'app/code/Jhll/Center/Service/ProductThumbnail.php',
                $moduleCandidatePaths,
                true,
            ),
        'module-scoped semantic retrieval materializes the product thumbnail source and template',
    );
    check(
        ($moduleBundle['missing_symbols'] ?? ['missing']) === [],
        'natural-language symbol hints rank scoped retrieval without becoming required exact definitions',
    );
    $moduleRolePaths = array_values(array_filter(array_map(
        static fn (array $candidate): string => (string) ($candidate['path'] ?? ''),
        $moduleBundle['server_aggregation']['role_discovery']['paths'] ?? [],
    )));
    check(
        $moduleRolePaths !== []
            && array_reduce(
                $moduleRolePaths,
                static fn (bool $inside, string $path): bool =>
                    $inside && str_starts_with($path, 'app/code/Jhll/Center/'),
                true,
            ),
        'server role discovery never escapes the bounded module scope',
    );
    $narrowModuleBundle = $moduleFixtureIntelligence->call('get_edit_bundle', [
        'repository' => $moduleFixtureRoot,
        'task' => 'MCP 仍然找不到 Jhll_Center 后台产品列表商品图片缩略图来源，修正并验证',
        'module' => 'Jhll_Center',
        'task_contract' => [
            'goal' => '只在现有控制器与产品模板范围内定位缩略图',
            'allowed_scope' => [
                'app/code/Jhll/Center/Controller/**',
                'app/code/Jhll/Center/view/adminhtml/templates/product/**',
            ],
        ],
        'include_docs' => false,
        'include_skills' => false,
    ]);
    check(
        ($narrowModuleBundle['ready_for_edit'] ?? false) === true
            && in_array(
                'service',
                $narrowModuleBundle['server_aggregation']['role_discovery']['inferred_expected_roles'] ?? [],
                true,
            )
            && !in_array(
                'service',
                $narrowModuleBundle['server_aggregation']['role_discovery']['expected_roles'] ?? [],
                true,
            ),
        'module-scoped completeness audits but does not require roles unavailable inside allowed_scope',
    );
    $unsupportedScopeError = null;
    try {
        $moduleFixtureIntelligence->call('get_edit_bundle', [
            'repository' => $moduleFixtureRoot,
            'task' => 'Discover a product template through an unsafe wildcard scope',
            'module' => 'Jhll_Center',
            'task_contract' => [
                'goal' => 'Reject a scope whose safe traversal root cannot be represented exactly',
                'allowed_scope' => ['app/code/*/Center/**'],
            ],
            'include_docs' => false,
            'include_skills' => false,
        ]);
    } catch (ToolException $exception) {
        $unsupportedScopeError = $exception;
    }
    check(
        $unsupportedScopeError?->errorCode === 'TASK_SCOPE_UNSUPPORTED',
        'discovery rejects wildcard scopes that cannot be reduced to an exact safe root',
    );
    unset($unsupportedScopeError);
    unset($moduleFixtureIntelligence);

    $resolved = ProjectResolver::resolve($root);
    $store->upsertProject($resolved['project']);
    $projectId = (string) $resolved['project']['id'];
    $runs = new ExecutionRunService($store, $config);
    $contract = [
        'goal' => 'Change one fixture safely',
        'known_paths' => ['src/Example.php'],
        'known_symbols' => ['Example::run'],
        'acceptance_criteria' => ['all checks pass'],
        'active_skills' => null,
        'active_skills_display' => '宿主未提供',
        'instruction_sources' => ['AGENTS.md'],
        'validation_expectations' => ['syntax', 'regression'],
    ];

    $scopeGuardContract = $contract;
    $scopeGuardContract['allowed_scope'] = ['tests/unit/AllowedTest.php'];
    $scopeGuard = $runs->begin(
        $projectId,
        'Reject an edit outside the run-bound task scope',
        $scopeGuardContract,
        7,
    );
    $scopeGuard = $runs->completeBundle(
        (string) $scopeGuard['run_id'],
        $projectId,
        editableBundle('bundle-scope-guard'),
    );
    $scopeGuardPlan = editPlan(
        (string) $scopeGuard['run_id'],
        'bundle-scope-guard',
    );
    $scopeGuardPlan['operations'][0]['path'] = 'docs/unselected.md';
    $scopeGuardError = null;
    try {
        $runs->beginApply(
            (string) $scopeGuard['run_id'],
            $projectId,
            $scopeGuardPlan,
        );
    } catch (ToolException $exception) {
        $scopeGuardError = $exception;
    }
    check(
        $scopeGuardError?->errorCode === 'EDIT_TASK_SCOPE_DENIED'
            && ($scopeGuardError->details['policy_reason'] ?? '') === 'outside_task_contract',
        'run-bound apply rejects a path outside selected files and TaskContract scope',
    );

    $globScopeContract = $contract;
    $globScopeContract['allowed_scope'] = ['app/code/Jhll/Center/**'];
    $globScopeRun = $runs->begin(
        $projectId,
        'Allow an edit inside a wildcard task scope',
        $globScopeContract,
        7,
    );
    $globScopeRun = $runs->completeBundle(
        (string) $globScopeRun['run_id'],
        $projectId,
        editableBundle('bundle-glob-scope'),
    );
    $globScopePlan = editPlan(
        (string) $globScopeRun['run_id'],
        'bundle-glob-scope',
    );
    $globScopePlan['operations'][0]['path'] = 'app/code/Jhll/Center/Service/ProductThumbnail.php';
    $globScopeError = null;
    try {
        $runs->beginApply(
            (string) $globScopeRun['run_id'],
            $projectId,
            $globScopePlan,
        );
    } catch (ToolException $exception) {
        $globScopeError = $exception;
    }
    check(
        $globScopeError === null,
        'run-bound apply accepts a path matched by a wildcard TaskContract scope',
    );

    $forbiddenScopeContract = $globScopeContract;
    $forbiddenScopeContract['forbidden_scope'] = ['app/code/Jhll/Center/Secret/**'];
    $forbiddenScopeRun = $runs->begin(
        $projectId,
        'Reject an edit inside a forbidden task scope',
        $forbiddenScopeContract,
        7,
    );
    $forbiddenScopeRun = $runs->completeBundle(
        (string) $forbiddenScopeRun['run_id'],
        $projectId,
        editableBundle('bundle-forbidden-scope'),
    );
    $forbiddenScopePlan = editPlan(
        (string) $forbiddenScopeRun['run_id'],
        'bundle-forbidden-scope',
    );
    $forbiddenScopePlan['operations'][0]['path'] = 'app/code/Jhll/Center/Secret/Credentials.php';
    $forbiddenScopeError = null;
    try {
        $runs->beginApply(
            (string) $forbiddenScopeRun['run_id'],
            $projectId,
            $forbiddenScopePlan,
        );
    } catch (ToolException $exception) {
        $forbiddenScopeError = $exception;
    }
    check(
        $forbiddenScopeError?->errorCode === 'EDIT_TASK_SCOPE_DENIED'
            && ($forbiddenScopeError->details['policy_reason'] ?? '') === 'forbidden_task_contract',
        'run-bound apply rejects a path matched by TaskContract forbidden_scope',
    );

    $batchWait = $runs->begin($projectId, 'Capacity-limited parent fixture', $contract, 8);
    $batchWaitBundle = editableBundle('bundle-capacity-parent', 8);
    $batchWaitBundle['ready_for_edit'] = false;
    $batchWaitBundle['continuation_needed'] = true;
    $batchWaitBundle['continuation'] = [
        'needed' => true,
        'batch_plan' => [
            'schema_version' => 'context-batch-plan.v1',
            'batch_count' => 1,
            'path_batches' => [['src/Example.php']],
        ],
    ];
    $batchWait = $runs->completeBundle(
        (string) $batchWait['run_id'],
        $projectId,
        $batchWaitBundle,
    );
    $batchWaitApplyError = null;
    try {
        $runs->beginApply(
            (string) $batchWait['run_id'],
            $projectId,
            editPlan((string) $batchWait['run_id'], 'bundle-capacity-parent'),
        );
    } catch (ToolException $exception) {
        $batchWaitApplyError = $exception;
    }
    check(
        $batchWaitApplyError?->errorCode === 'RUN_NOT_APPLICABLE'
            && ($batchWaitApplyError->details['workflow_state'] ?? '') === 'CONTEXT_BATCH_PLANNED',
        'capacity-limited parent cannot enter apply before a child batch is ready',
    );

    $normal = $runs->begin(
        $projectId,
        'Update fixture; api_key=sk-test-12345678901234567890',
        $contract,
        7,
    );
    $normal = $runs->completeBundle(
        (string) $normal['run_id'],
        $projectId,
        editableBundle('bundle-normal'),
    );
    check($normal['status'] === 'waiting_for_plan', 'complete bundle waits for one edit plan');
    $runs->beginApply((string) $normal['run_id'], $projectId, editPlan((string) $normal['run_id'], 'bundle-normal'));
    $normal = $runs->completeApply(
        (string) $normal['run_id'],
        $projectId,
        applyResult('edit-normal'),
    );
    check($normal['status'] === 'completed' && $normal['terminal'] === true, 'normal run completes atomically');
    check(($normal['counters']['apply_compact_edit_calls'] ?? 0) === 1, 'normal run records one apply call');
    check(($normal['counters']['successful_apply_compact_edit_calls'] ?? 0) === 1, 'normal run records one successful apply');
    $trace = $runs->trace($projectId, (string) $normal['run_id'], [
        'include_files' => true,
        'include_diffs' => true,
    ]);
    check(count($trace['events']) >= 8, 'timeline persists all major phases');
    check(count($trace['files']) === 2, 'candidate and excluded files persist');
    check(str_contains((string) $trace['files'][0]['diff'], '+true'), 'bounded file diff is reviewable');
    check(!str_contains(json_encode($trace, JSON_THROW_ON_ERROR), 'sk-test-12345678901234567890'), 'trace redacts credentials');
    $parseEvent = array_values(array_filter(
        $trace['events'],
        static fn (array $event): bool => ($event['operation_name'] ?? '') === 'parse_task_contract',
    ))[0] ?? [];
    check(
        ($parseEvent['input_summary']['active_skills_declared'] ?? true) === false,
        'null active skills are reported as host not supplied',
    );
    $conflict = $runs->begin($projectId, 'Conflict fixture', $contract, 8);
    $conflict = $runs->completeBundle((string) $conflict['run_id'], $projectId, editableBundle('bundle-conflict', 8));
    $runs->beginApply((string) $conflict['run_id'], $projectId, editPlan((string) $conflict['run_id'], 'bundle-conflict'));
    $runs->fail((string) $conflict['run_id'], $projectId, new ToolException(
        'EDIT_REPLAN_REQUIRED',
        'fixture hash changed',
        true,
        ['latest_regions' => editableBundle('latest')['exact_regions']],
    ));
    $conflict = $runs->status($projectId, (string) $conflict['run_id']);
    check($conflict['workflow_state'] === 'CONFLICT_REPLAN' && !$conflict['terminal'], 'conflict enters typed bounded replan');
    $runs->fail((string) $conflict['run_id'], $projectId, new ToolException(
        'EDIT_REPLAN_REQUIRED',
        'fixture hash changed',
        true,
        ['latest_regions' => editableBundle('latest-second')['exact_regions']],
    ));
    $conflict = $runs->status($projectId, (string) $conflict['run_id']);
    check(
        $conflict['workflow_state'] === 'CONFLICT_REPLAN'
            && $conflict['status'] === 'waiting_for_plan'
            && !$conflict['terminal'],
        'second identical edit conflict remains a non-terminal bounded replan',
    );
    $runs->fail((string) $conflict['run_id'], $projectId, new ToolException(
        'EDIT_REPLAN_REQUIRED',
        'fixture hash changed',
        true,
        ['latest_regions' => editableBundle('latest-third')['exact_regions']],
    ));
    $conflict = $runs->status($projectId, (string) $conflict['run_id']);
    check(
        $conflict['workflow_state'] === 'FAILED'
            && $conflict['status'] === 'failed'
            && $conflict['terminal'],
        'third identical edit conflict stops only after the bounded replan budget is exhausted',
    );

    $indexRetry = $runs->begin($projectId, 'Index warm-up fixture', $contract, 8);
    $runs->fail((string) $indexRetry['run_id'], $projectId, new ToolException(
        'INDEX_NOT_READY',
        'fixture index is still warming',
        true,
        ['retry_after_ms' => 100],
    ), 'get_edit_bundle');
    $indexRetry = $runs->status($projectId, (string) $indexRetry['run_id']);
    check(
        $indexRetry['workflow_state'] === 'CONTEXT_BATCHING'
            && $indexRetry['status'] === 'waiting_for_context_batch'
            && !$indexRetry['terminal'],
        'transient index capacity enters bounded context retry instead of terminal failure',
    );
    $symbolIndexRetry = $runs->begin($projectId, 'Exact symbol query fixture', $contract, 8);
    $runs->fail((string) $symbolIndexRetry['run_id'], $projectId, new ToolException(
        'INDEX_SYMBOL_QUERY_FAILED',
        'fixture exact query failed',
        true,
        ['index_revision' => 8],
    ), 'get_edit_bundle');
    $symbolIndexRetry = $runs->status($projectId, (string) $symbolIndexRetry['run_id']);
    check(
        $symbolIndexRetry['workflow_state'] === 'CONTEXT_INDEX_RETRY'
            && $symbolIndexRetry['status'] === 'failed'
            && $symbolIndexRetry['terminal']
            && ($symbolIndexRetry['error']['retryable'] ?? false) === true,
        'exact symbol query failure stays typed and retryable instead of becoming target-not-found',
    );

    $validation = $runs->begin($projectId, 'Validation fixture', $contract, 8);
    $validation = $runs->completeBundle((string) $validation['run_id'], $projectId, editableBundle('bundle-validation', 8));
    $runs->beginApply((string) $validation['run_id'], $projectId, editPlan((string) $validation['run_id'], 'bundle-validation'));
    $validation = $runs->completeApply(
        (string) $validation['run_id'],
        $projectId,
        applyResult('edit-validation', false),
    );
    check($validation['workflow_state'] === 'VALIDATION_REPAIR', 'validation failure enters typed repair');
    check(($validation['counters']['automatic_rollback_count'] ?? 0) === 1, 'validation failure records automatic rollback');

    $impact = $runs->begin($projectId, 'Impact fixture', $contract, 8);
    $impact = $runs->completeBundle((string) $impact['run_id'], $projectId, editableBundle('bundle-impact', 8));
    $runs->beginApply((string) $impact['run_id'], $projectId, editPlan((string) $impact['run_id'], 'bundle-impact'));
    $impact = $runs->completeApply(
        (string) $impact['run_id'],
        $projectId,
        applyResult('edit-impact', true, true),
    );
    check($impact['workflow_state'] === 'IMPACT_EXPANSION' && !$impact['terminal'], 'new consumer enters bounded impact expansion');

    $scope = $runs->begin(
        $projectId,
        'Expanded user scope',
        $contract,
        8,
        (string) $normal['run_id'],
    );
    $old = $runs->status($projectId, (string) $normal['run_id']);
    check($old['status'] === 'superseded' && $scope['task_id'] === $old['task_id'], 'user scope change supersedes while preserving task identity');

    $tools = new ToolService($store, $config, new Analyzer($store, $config));
    $definitions = $tools->definitions();
    $names = array_column($definitions, 'name');
    foreach (['resolve_deploy_plan', 'get_edit_bundle', 'apply_compact_edit', 'get_run_status', 'get_run_trace', 'validate_change'] as $name) {
        check(in_array($name, $names, true), "compact tool surface exposes $name");
    }
    $getBundleDefinition = array_values(array_filter(
        $definitions,
        static fn (array $definition): bool => ($definition['name'] ?? '') === 'get_edit_bundle',
    ))[0] ?? [];
    $getBundleRequired = $getBundleDefinition['inputSchema']['required'] ?? [];
    check(
        in_array('task', $getBundleRequired, true)
            && in_array('repository', $getBundleRequired, true)
            && in_array('client_session_id', $getBundleRequired, true)
            && in_array('readiness_id', $getBundleRequired, true),
        'get_edit_bundle schema requires prepared project and bound session',
    );
    $getEditStatusDefinition = array_values(array_filter(
        $definitions,
        static fn (array $definition): bool => ($definition['name'] ?? '') === 'get_edit_status',
    ))[0] ?? [];
    check(
        isset($getEditStatusDefinition['inputSchema']['properties']['review_cursor']),
        'get_edit_status exposes the sealed review cursor at the MCP boundary',
    );
    check(ToolService::VERSION === '0.13.0', 'tool service version is 0.13.0');
    check(str_contains(substr(ToolService::INSTRUCTIONS, 0, 512), 'prepare_project'), 'first 512 instruction characters contain mandatory preparation');
    check(str_contains(substr(ToolService::INSTRUCTIONS, 0, 512), 'readiness_id'), 'first 512 instruction characters require readiness binding');
    check(str_contains(ToolService::INSTRUCTIONS, 'get_edit_bundle once'), 'instructions preserve one-bundle editing');

    $ui = file_get_contents(dirname(__DIR__) . '/ui/execution-run-v1.html');
    check(is_string($ui) && str_contains($ui, 'window.openai.callTool'), 'MCP App performs host tool refresh');
    check(str_contains((string) $ui, 'data-theme') && str_contains((string) $ui, 'prefers-color-scheme'), 'MCP App supports host and system themes');
    check(str_contains((string) $ui, 'aria-live') && str_contains((string) $ui, 'focus-visible'), 'MCP App includes accessibility states');
    check(str_contains((string) $ui, 'get_run_trace') && str_contains((string) $ui, 'include_diffs'), 'MCP App retrieves live trace and terminal diffs');
    check(
        !str_contains((string) $ui, 'currentIndex = phaseOrder.length')
            && str_contains((string) $ui, 'completedPhaseSet(run)'),
        'MCP App does not mark every phase complete when a run fails early',
    );
    check(
        str_contains((string) $ui, 'workflowLabels[workflow]')
            && str_contains((string) $ui, 'CONTEXT_TARGET_AMBIGUOUS'),
        'MCP App prioritizes specific context workflow states over generic failure',
    );

    $runner = new ProcessRunner();
    $deployFixture = $temporary . '/deploy-bridge-project';
    mkdir($deployFixture . '/bin', 0700, true);
    file_put_contents($deployFixture . '/bin/w', <<<'PHP'
<?php
fwrite(STDOUT, json_encode([
    'schema_version' => 'deploy-machine-plan.v1',
    'status' => 'not_applicable',
    'development_blocked' => false,
    'deployment_blocked' => true,
    'release_executed' => false,
    'orchestrator_called' => false,
], JSON_THROW_ON_ERROR));
PHP);
    $deployPlan = (new DeployBridgeService($runner))->resolve($deployFixture, [
        'operation' => 'preflight',
        'target' => 'local',
    ]);
    check(
        ($deployPlan['schema_version'] ?? '') === 'deploy-machine-plan.v1'
            && ($deployPlan['bridge']['read_only'] ?? false) === true
            && ($deployPlan['release_executed'] ?? true) === false,
        'deployment bridge uses the public non-executing JSON contract',
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
            && (int) ($parserHighWaterResult['usage_before'] ?? 0) >= 120 * 1_024 * 1_024
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
            && ($parserIsolationResult['freshness'] ?? null) === 'partial'
            && ($parserIsolationResult['phase'] ?? null) === 'idle'
            && ($parserIsolationResult['previous_hash_retained'] ?? false) === true
            && ($parserIsolationResult['transport_survived'] ?? false) === true
            && ($parserIsolationResult['baseline_symbol'] ?? null) === 'Isolation\\Large::stable'
            && ($parserIsolationResult['near_threshold_bytes'] ?? null) === 65_535
            && ($parserIsolationResult['near_threshold_symbol'] ?? null) === 'Isolation\\NearThreshold::run'
            && ($parserIsolationResult['path_sensitive_worker_match'] ?? false) === true
            && ($parserIsolationResult['truncated_output_rejected'] ?? false) === true
            && (int) ($parserIsolationResult['resource_bytes'] ?? 0) > 0,
        'isolated parser failure retains the index and keeps MCP App resources readable',
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
    $prompts = $manifest['interface']['defaultPrompt'] ?? [];
    check(count($prompts) <= 3, 'plugin defaultPrompt has at most three entries');
    check(array_reduce($prompts, static fn (bool $ok, string $prompt): bool => $ok && mb_strlen($prompt) <= 128, true), 'every defaultPrompt entry is at most 128 characters');
    $mcpConfig = json_decode((string) file_get_contents(
        $marketplace . '/plugins/weline-project-intelligence/.mcp.json'
    ), true, 512, JSON_THROW_ON_ERROR);
    $enabled = $mcpConfig['mcpServers']['weline-project-intelligence']['enabled_tools'] ?? [];
    check(
        in_array('prepare_project', $enabled, true)
            && in_array('resolve_task_context', $enabled, true)
            && in_array('get_run_status', $enabled, true)
            && in_array('get_run_trace', $enabled, true),
        'generated plugin enables readiness, guidance, and execution-run tools',
    );

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
            && in_array('mcp remove weline-project-intelligence', $codexCommands, true),
        'installer removes both legacy explicit MCP registration names',
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
    check(str_contains((string) ($responses[1]['result']['instructions'] ?? ''), 'get_edit_bundle once'), 'initialize exposes closed-loop instructions');
    $protocolTools = array_column($responses[2]['result']['tools'] ?? [], 'name');
    check(in_array('get_run_trace', $protocolTools, true), 'protocol tools/list exposes run trace');
    $resourceUris = array_column($responses[3]['result']['resources'] ?? [], 'uri');
    check(in_array(ToolService::EXECUTION_RUN_RESOURCE_URI, $resourceUris, true), 'protocol resources/list exposes execution panel');

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
