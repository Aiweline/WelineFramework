<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Deploy\HostProcessPoolPolicy;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * Global-flat: one call may hold identities from many themes.
 * Solidify pool + compile pool overlap (promote → enqueue compile); ownerHash mutexes same scope.
 * Progress bar label shows last event identity + live s=/c=/themes= occupancy (not theme-serial).
 * Fiber is not used for CPU parallel. WLS / concurrency=1 → serial in-process.
 */
final class ThemeLayoutEntitySolidifyCompilePipeline
{
    public const ENV_SOLIDIFY_CONCURRENCY = 'WELINE_THEME_SOLIDIFY_CONCURRENCY';
    public const ENV_COMPILE_CONCURRENCY = 'WELINE_THEME_COMPILE_CONCURRENCY';
    /** Soft ceiling when ENV unset/auto — actual size from HostProcessPoolPolicy. */
    public const DEFAULT_CONCURRENCY = 10;
    public const MAX_CONCURRENCY = 32;

    public function __construct(
        private readonly ThemeLayoutEntityBakeCoordinator $bakeCoordinator,
        private readonly ThemeLayoutEntityBatchPublisher $publisher,
        private readonly ThemeLayoutEntityFormalLocaleCompileService $compiler,
        private readonly Printing $printing,
    ) {
    }

    public function resolveSolidifyConcurrency(?int $override = null): int
    {
        return $this->resolveConcurrencyDecision($override, self::ENV_SOLIDIFY_CONCURRENCY)['concurrency'];
    }

    public function resolveCompileConcurrency(?int $override = null): int
    {
        return $this->resolveConcurrencyDecision($override, self::ENV_COMPILE_CONCURRENCY)['concurrency'];
    }

    /**
     * @return array{concurrency:int, source:string, cpus:int, mem_avail_mb:int|null}
     */
    public function resolveSolidifyConcurrencyDecision(?int $override = null): array
    {
        return $this->resolveConcurrencyDecision($override, self::ENV_SOLIDIFY_CONCURRENCY);
    }

    /**
     * @return array{concurrency:int, source:string, cpus:int, mem_avail_mb:int|null}
     */
    public function resolveCompileConcurrencyDecision(?int $override = null): array
    {
        return $this->resolveConcurrencyDecision($override, self::ENV_COMPILE_CONCURRENCY);
    }

    /**
     * @param list<array{identity:ThemeVersionIdentity,pending:list<array<string,mixed>>,changes:array}> $workItems
     * @param callable|null $progress fn(int $done, int $total, ThemeVersionIdentity $identity, string $phase): void
     * @return array{migrated:int,compiled:int}
     */
    public function run(array $workItems, ?callable $progress = null, array $options = []): array
    {
        if ($workItems === []) {
            return ['migrated' => 0, 'compiled' => 0];
        }

        $solidifyDecision = $this->resolveSolidifyConcurrencyDecision(
            isset($options['solidify_concurrency']) ? (int)$options['solidify_concurrency'] : null,
        );
        $compileDecision = $this->resolveCompileConcurrencyDecision(
            isset($options['compile_concurrency']) ? (int)$options['compile_concurrency'] : null,
        );
        $solidifyN = max(1, (int)$solidifyDecision['concurrency']);
        $compileN = max(1, (int)$compileDecision['concurrency']);
        if ($this->isInsideWlsWorker() || ($solidifyN <= 1 && $compileN <= 1)) {
            if (!$this->isInsideWlsWorker() && count($workItems) > 1) {
                $this->printing->note($this->formatPoolDecisionNote(
                    $solidifyN,
                    $compileN,
                    count($workItems),
                    $solidifyDecision,
                    $compileDecision,
                ));
            }

            return $this->runSerial($workItems, $progress);
        }

        return $this->runProcessPipeline(
            $workItems,
            $progress,
            $solidifyN,
            $compileN,
            $solidifyDecision,
            $compileDecision,
        );
    }

    /**
     * @param list<array{identity:ThemeVersionIdentity,pending:list<array<string,mixed>>,changes:array}> $workItems
     * @return array{migrated:int,compiled:int}
     */
    private function runSerial(array $workItems, ?callable $progress): array
    {
        $total = count($workItems);
        $migrated = 0;
        $compiled = 0;
        $index = 0;
        foreach ($workItems as $item) {
            ++$index;
            $identity = $item['identity'];
            if ($progress !== null) {
                $progress($index, $total, $identity, 'solidify');
            }
            $result = $this->bakeCoordinator->solidifyPendingIdentity(
                $identity,
                $item['pending'],
                $item['changes'] ?? [],
                false,
            );
            $migrated += (int)($result['migrated'] ?? 0);
            $completed = $result['completed'] ?? [];
            if (
                $identity->mode === ThemeVersionIdentity::MODE_FORMAL
                && $identity->area === 'frontend'
                && $completed !== []
            ) {
                try {
                    if ($progress !== null) {
                        $progress($index, $total, $identity, 'compile');
                    }
                    $this->compiler->compileAfterPromote($identity, $completed, ['nested' => false]);
                    ++$compiled;
                } catch (\Throwable $error) {
                    $rollback = $result['rollback_plan'] ?? [];
                    if (is_array($rollback) && $rollback !== []) {
                        $this->publisher->rollbackPromote($identity, $rollback);
                    }
                    throw $error;
                }
            }
        }

        return ['migrated' => $migrated, 'compiled' => $compiled];
    }

    /**
     * @param list<array{identity:ThemeVersionIdentity,pending:list<array<string,mixed>>,changes:array}> $workItems
     * @return array{migrated:int,compiled:int}
     */
    /**
     * @param array{concurrency:int, source:string, cpus:int, mem_avail_mb:int|null} $solidifyDecision
     * @param array{concurrency:int, source:string, cpus:int, mem_avail_mb:int|null} $compileDecision
     */
    private function runProcessPipeline(
        array $workItems,
        ?callable $progress,
        int $solidifyN,
        int $compileN,
        array $solidifyDecision,
        array $compileDecision,
    ): array {
        $solidifyScript = dirname(__DIR__, 2) . '/bin/solidify-identity-job.php';
        $compileScript = dirname(__DIR__, 2) . '/bin/compile-identity-job.php';
        if (!is_file($solidifyScript) || !is_file($compileScript)) {
            throw new \RuntimeException('theme_layout_pipeline_worker_script_missing');
        }

        $this->printing->note($this->formatPoolDecisionNote(
            $solidifyN,
            $compileN,
            count($workItems),
            $solidifyDecision,
            $compileDecision,
        ));

        $jobRoot = rtrim(sys_get_temp_dir(), '/\\') . '/weline-theme-pipeline-' . bin2hex(random_bytes(8));
        if (!mkdir($jobRoot, 0700, true) && !is_dir($jobRoot)) {
            throw new \RuntimeException('theme_layout_pipeline_job_root_failed');
        }

        $phpBin = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
        $total = count($workItems);
        // Denominator = solidify(all) + compile(only formal frontend). Draft / empty paths ≠ ×2.
        $phaseTotal = $this->expectedPhaseTotal($workItems);
        $solidifyQueue = array_values($workItems);
        $compileQueue = [];
        /** @var array<string, array{proc:resource,pipes:array,kind:string,item:array,job_dir:string}> $running */
        $running = [];
        $solidifyDone = 0;
        $compileDone = 0;
        $migrated = 0;
        $compiled = 0;
        $errors = [];
        $stopping = false;
        /** @var list<array{identity:ThemeVersionIdentity,job_dir:string}> $pendingRollbacks */
        $pendingRollbacks = [];
        $lastIdentity = $workItems[0]['identity'] ?? null;

        $paint = function (ThemeVersionIdentity $identity, string $phase, int $doneOverride = -1) use (
            $progress,
            &$solidifyDone,
            &$compileDone,
            &$phaseTotal,
            &$lastIdentity,
            &$running,
            $solidifyN,
            $compileN,
        ): void {
            $lastIdentity = $identity;
            $done = $doneOverride >= 0 ? $doneOverride : ($solidifyDone + $compileDone);
            // Paths may be empty after solidify → compile skipped; shrink total so bar can finish.
            if ($done > $phaseTotal) {
                $phaseTotal = $done;
            }
            $totalPhases = max(1, $phaseTotal);
            $current = min($totalPhases, max(0, $done));
            // Always paint occupancy here so CLI (and piped first/last frames) show cross-theme concurrency.
            // Custom $progress stays for SSE/hooks and must not draw a second single-theme bar.
            $this->printing->progressBar(
                max(1, $current > 0 ? $current : 1),
                $totalPhases,
                $this->formatPipelineProgressLabel($identity, $phase, $running, $solidifyN, $compileN),
                24,
            );
            if ($progress !== null) {
                $progress($done, $totalPhases, $identity, $phase);
            }
        };

        $emit = function (ThemeVersionIdentity $identity, string $phase) use ($paint): void {
            $paint($identity, $phase);
        };

        try {
            while (
                !$stopping && ($solidifyQueue !== [] || $compileQueue !== [] || $running !== [] || $pendingRollbacks !== [])
                || ($stopping && ($running !== [] || $pendingRollbacks !== []))
            ) {
                while (
                    !$stopping
                    && $solidifyQueue !== []
                    && $this->countKind($running, 'solidify') < $solidifyN
                ) {
                    // OwnerLock keys by owner (theme+scope+storeMode+area), not V/mode.
                    // Never solidify while same ownerHash is solidifying, compiling, or pending rollback.
                    $pickIndex = $this->findSolidifyQueueIndex($solidifyQueue, $running, $compileQueue, $pendingRollbacks);
                    if ($pickIndex === null) {
                        break;
                    }
                    $item = array_splice($solidifyQueue, $pickIndex, 1)[0] ?? null;
                    if ($item === null) {
                        break;
                    }
                    $identity = $item['identity'];
                    $jobDir = $jobRoot . '/s-' . $identity->themeVersionId . '-' . bin2hex(random_bytes(4));
                    mkdir($jobDir, 0700, true);
                    $jobFile = $jobDir . '/job.json';
                    file_put_contents($jobFile, json_encode([
                        'identity' => $identity->toArray(),
                        'pending' => $item['pending'],
                        'changes' => $item['changes'] ?? [],
                        'job_dir' => $jobDir,
                        'deploy_staging_root' => $this->currentDeployStagingRoot(),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $proc = $this->spawn($phpBin, $solidifyScript, $jobFile);
                    if ($proc === null) {
                        $errors[] = 'theme_layout_solidify_spawn_failed:V' . $identity->themeVersionId;
                        $stopping = true;
                        break;
                    }
                    $running['s:' . $identity->themeVersionId . ':' . bin2hex(random_bytes(3))] = [
                        'proc' => $proc['proc'],
                        'pipes' => $proc['pipes'],
                        'kind' => 'solidify',
                        'item' => $item,
                        'job_dir' => $jobDir,
                        'owner_hash' => $identity->ownerHash(),
                    ];
                    // Spawn tick: themes= grows while workers are live (completion-only bar looked theme-serial).
                    $paint($identity, 'solidify', $solidifyDone + $compileDone);
                }

                while (
                    !$stopping
                    && $compileQueue !== []
                    && $this->countKind($running, 'compile') < $compileN
                ) {
                    $ready = array_shift($compileQueue);
                    if ($ready === null) {
                        break;
                    }
                    $identity = $ready['identity'];
                    $jobDir = $ready['job_dir'];
                    $jobFile = $jobDir . '/compile-job.json';
                    file_put_contents($jobFile, json_encode([
                        'identity' => $identity->toArray(),
                        'candidate_paths' => $ready['candidate_paths'],
                        'job_dir' => $jobDir,
                        'deploy_staging_root' => $this->currentDeployStagingRoot(),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $proc = $this->spawn($phpBin, $compileScript, $jobFile, [
                        'WELINE_TEMPLATE_COMPILE_NESTED' => '1',
                    ]);
                    if ($proc === null) {
                        $errors[] = 'theme_layout_compile_spawn_failed:V' . $identity->themeVersionId;
                        $pendingRollbacks[] = ['identity' => $identity, 'job_dir' => $jobDir];
                        $stopping = true;
                        break;
                    }
                    $running['c:' . $identity->themeVersionId . ':' . bin2hex(random_bytes(2))] = [
                        'proc' => $proc['proc'],
                        'pipes' => $proc['pipes'],
                        'kind' => 'compile',
                        'item' => $ready,
                        'job_dir' => $jobDir,
                        'owner_hash' => $identity->ownerHash(),
                    ];
                    $paint($identity, 'compile', $solidifyDone + $compileDone);
                }

                foreach ($running as $key => $state) {
                    $status = proc_get_status($state['proc']);
                    if (!empty($status['running'])) {
                        continue;
                    }
                    // proc_get_status() reaps the child; proc_close() then often returns -1.
                    // Always trust exitcode from the first non-running status snapshot.
                    $exit = array_key_exists('exitcode', $status) ? (int)$status['exitcode'] : -1;
                    $stdout = stream_get_contents($state['pipes'][1]);
                    $stderr = stream_get_contents($state['pipes'][2]);
                    foreach ([1, 2] as $fd) {
                        if (is_resource($state['pipes'][$fd])) {
                            fclose($state['pipes'][$fd]);
                        }
                    }
                    @proc_close($state['proc']);
                    unset($running[$key]);
                    $identity = $state['item']['identity'];
                    if ($exit !== 0) {
                        // Parent SIGTERM for OwnerLock drain — not a primary failure.
                        if ($exit < 0 && !empty($state['terminated_by_parent'])) {
                            continue;
                        }
                        $snippet = trim((string)$stderr);
                        if ($snippet === '') {
                            $snippet = trim((string)$stdout);
                        }
                        // Strip progress-bar noise from worker stderr snippets.
                        $snippet = preg_replace('/\x1b\[[0-9;]*m/', '', $snippet) ?? $snippet;
                        $snippet = trim(preg_replace('/[^\P{C}\n]+/u', ' ', $snippet) ?? $snippet);
                        if (strlen($snippet) > 240) {
                            $snippet = substr($snippet, 0, 240) . '…';
                        }
                        $errors[] = 'theme_layout_pipeline_' . $state['kind'] . '_failed:V'
                            . $identity->themeVersionId . ':exit=' . $exit
                            . ($snippet !== '' ? ':' . $snippet : '');
                        if ($state['kind'] === 'compile') {
                            // Defer restore until same ownerHash has no solidify/compile sibling still running.
                            $pendingRollbacks[] = [
                                'identity' => $identity,
                                'job_dir' => (string)$state['job_dir'],
                            ];
                        }
                        $stopping = true;
                        continue;
                    }
                    if ($state['kind'] === 'solidify') {
                        ++$solidifyDone;
                        $payload = json_decode(trim((string)$stdout), true);
                        $migrated += (int)($payload['migrated'] ?? 0);
                        $paths = is_array($payload['candidate_paths'] ?? null)
                            ? array_values(array_filter($payload['candidate_paths'], 'is_string'))
                            : [];
                        $emit($identity, 'solidify');
                        if (
                            $identity->mode === ThemeVersionIdentity::MODE_FORMAL
                            && $identity->area === 'frontend'
                            && $paths !== []
                        ) {
                            $compileQueue[] = [
                                'identity' => $identity,
                                'candidate_paths' => $paths,
                                'job_dir' => $state['job_dir'],
                                'pending' => [],
                                'changes' => [],
                            ];
                        } elseif (
                            $identity->mode === ThemeVersionIdentity::MODE_FORMAL
                            && $identity->area === 'frontend'
                        ) {
                            // Counted in expectedPhaseTotal but no Taglib work — drop one slot.
                            $phaseTotal = max($solidifyDone + $compileDone, $phaseTotal - 1);
                        }
                    } else {
                        ++$compileDone;
                        ++$compiled;
                        $emit($identity, 'compile');
                    }
                }

                if ($stopping) {
                    // Abandon queued compiles that never started — defer restore (do not fight OwnerLock).
                    foreach ($compileQueue as $abandoned) {
                        $abandonedIdentity = $abandoned['identity'] ?? null;
                        $abandonedJobDir = $abandoned['job_dir'] ?? null;
                        if ($abandonedIdentity instanceof ThemeVersionIdentity && is_string($abandonedJobDir) && $abandonedJobDir !== '') {
                            $pendingRollbacks[] = [
                                'identity' => $abandonedIdentity,
                                'job_dir' => $abandonedJobDir,
                            ];
                        }
                    }
                    $solidifyQueue = [];
                    $compileQueue = [];
                    // Only stop workers that share OwnerLock with pending restores —
                    // do not SIGTERM the whole pool (that yields exit=-1 noise).
                    $blockOwners = [];
                    foreach ($pendingRollbacks as $pending) {
                        $blockOwners[$pending['identity']->ownerHash()] = true;
                    }
                    $this->terminateRunningWorkers($running, $blockOwners);
                }

                $this->flushPendingRollbacks($pendingRollbacks, $running);

                if ($stopping && $running === [] && $pendingRollbacks === []) {
                    break;
                }
                // Always yield while work remains (incl. owner-busy solidify waits).
                if ($solidifyQueue !== [] || $compileQueue !== [] || $running !== [] || $pendingRollbacks !== []) {
                    usleep($running !== [] ? 20000 : 5000);
                }
            }

            // After natural drain: terminate leftovers, then restore.
            $this->terminateRunningWorkers($running, null);
            $deadline = hrtime(true) + 30_000_000_000;
            while ($pendingRollbacks !== [] && hrtime(true) < $deadline) {
                $this->flushPendingRollbacks($pendingRollbacks, $running);
                if ($pendingRollbacks === []) {
                    break;
                }
                usleep(200_000);
            }
            if ($pendingRollbacks !== []) {
                // Last attempt — surface lock timeout if an external holder remains.
                foreach ($pendingRollbacks as $pending) {
                    $this->rollbackFromJobDir($pending['identity'], $pending['job_dir']);
                }
                $pendingRollbacks = [];
            }

            if ($errors !== []) {
                $this->printing->finishProgressLine();
                throw new \RuntimeException(implode(' | ', $errors));
            }
            $done = $solidifyDone + $compileDone;
            $finalTotal = max(1, max($phaseTotal, $done));
            $identityForBar = $lastIdentity instanceof ThemeVersionIdentity
                ? $lastIdentity
                : ($workItems[0]['identity'] ?? null);
            if ($identityForBar instanceof ThemeVersionIdentity) {
                $paint($identityForBar, 'done', $finalTotal);
            }
            $this->printing->finishProgressLine();

            return ['migrated' => $migrated, 'compiled' => $compiled];
        } finally {
            foreach ($running as $state) {
                if (is_resource($state['proc'])) {
                    proc_terminate($state['proc']);
                    proc_close($state['proc']);
                }
            }
            $this->removeTree($jobRoot);
        }
    }

    /**
     * @param array<string,mixed> $extraEnv
     * @return array{proc:resource,pipes:array<int,resource>}|null
     */
    private function spawn(string $phpBin, string $script, string $jobFile, array $extraEnv = []): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Always pass an env map so staging root / nested flags cannot be dropped when
        // extraEnv is non-empty (array_merge(getenv()?:[]) alone is easy to get wrong).
        $env = $this->workerEnvironment($extraEnv);
        $proc = proc_open(
            [$phpBin, $script, $jobFile],
            $descriptors,
            $pipes,
            null,
            $env,
            ['bypass_shell' => true],
        );
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /** @param array<string,mixed> $extraEnv @return array<string,string> */
    private function workerEnvironment(array $extraEnv = []): array
    {
        $base = getenv();
        if (!is_array($base)) {
            $base = [];
            foreach ($_ENV as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $base[$key] = $value;
                }
            }
        }
        $staging = $this->currentDeployStagingRoot();
        if ($staging !== '') {
            $base[DeployStagingSession::ENV_ROOT] = $staging;
        }
        foreach ($extraEnv as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $base[$key] = (string)$value;
            }
        }

        return $base;
    }

    private function currentDeployStagingRoot(): string
    {
        if (!class_exists(DeployStagingSession::class) || !DeployStagingSession::isActive()) {
            return '';
        }

        return rtrim(DeployStagingSession::envRoot(), '/\\');
    }

    /** @param array<string,mixed> $job */
    private static function applyDeployStagingRootFromJob(array $job): void
    {
        $root = trim((string)($job['deploy_staging_root'] ?? ''));
        if ($root === '') {
            return;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        putenv(DeployStagingSession::ENV_ROOT . '=' . $root);
        $_ENV[DeployStagingSession::ENV_ROOT] = $root;
        $_SERVER[DeployStagingSession::ENV_ROOT] = $root;
    }

    /**
     * Progress phases: every identity solidifies; only formal frontend is expected to compile.
     *
     * @param list<array{identity:ThemeVersionIdentity,pending?:list<array<string,mixed>>,changes?:array}> $workItems
     */
    private function expectedPhaseTotal(array $workItems): int
    {
        $phases = 0;
        foreach ($workItems as $item) {
            $identity = $item['identity'] ?? null;
            if (!$identity instanceof ThemeVersionIdentity) {
                continue;
            }
            ++$phases;
            if (
                $identity->mode === ThemeVersionIdentity::MODE_FORMAL
                && $identity->area === 'frontend'
            ) {
                ++$phases;
            }
        }

        return max(1, $phases);
    }

    /** @param array<string, array{kind:string}> $running */
    private function countKind(array $running, string $kind): int
    {
        $n = 0;
        foreach ($running as $state) {
            if (($state['kind'] ?? '') === $kind) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * CLI label: event identity + live pool occupancy across themes.
     * Same ownerHash still mutexes; different themeId fill s=/c= together.
     *
     * @param array<string, array{kind?:string,item?:array}> $running
     */
    private function formatPipelineProgressLabel(
        ThemeVersionIdentity $identity,
        string $phase,
        array $running,
        int $solidifyN,
        int $compileN,
    ): string {
        $themeIds = [];
        foreach ($running as $state) {
            $live = $state['item']['identity'] ?? null;
            if ($live instanceof ThemeVersionIdentity) {
                $themeIds[(int)$live->themeId] = true;
            }
        }
        ksort($themeIds, SORT_NUMERIC);
        $themes = $themeIds === [] ? '-' : implode(',', array_keys($themeIds));

        return sprintf(
            '%s %s theme=%d V%d R%d %s %s s=%d/%d c=%d/%d themes=%s',
            (string)__('主题布局流水线'),
            $phase,
            $identity->themeId,
            $identity->themeVersionId,
            $identity->contentRevision,
            $identity->mode,
            $identity->canonicalScope,
            $this->countKind($running, 'solidify'),
            max(1, $solidifyN),
            $this->countKind($running, 'compile'),
            max(1, $compileN),
            $themes,
        );
    }

    /**
     * Pick first queued solidify whose owner is free across solidify+compile+rollback.
     * Draft/formal share OwnerLock; compile does not hold flock but rollbackPromote does —
     * so same owner must stay busy until compile (or deferred restore) finishes.
     *
     * @param list<array{identity:ThemeVersionIdentity,pending:list<array<string,mixed>>,changes:array}> $queue
     * @param array<string, array{kind:string,owner_hash?:string,item?:array}> $running
     * @param list<array{identity?:ThemeVersionIdentity}> $compileQueue
     * @param list<array{identity:ThemeVersionIdentity}> $pendingRollbacks
     */
    private function findSolidifyQueueIndex(
        array $queue,
        array $running,
        array $compileQueue = [],
        array $pendingRollbacks = [],
    ): ?int {
        $busyOwners = $this->collectBusyOwnerHashes($running, $compileQueue, $pendingRollbacks);
        foreach ($queue as $index => $item) {
            $identity = $item['identity'] ?? null;
            if (!$identity instanceof ThemeVersionIdentity) {
                continue;
            }
            if (!isset($busyOwners[$identity->ownerHash()])) {
                return (int)$index;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{kind?:string,owner_hash?:string,item?:array}> $running
     * @param list<array{identity?:mixed}> $compileQueue
     * @param list<array{identity?:mixed}> $pendingRollbacks
     * @return array<string,true>
     */
    private function collectBusyOwnerHashes(array $running, array $compileQueue = [], array $pendingRollbacks = []): array
    {
        $busy = [];
        foreach ($running as $state) {
            $hash = $this->stateOwnerHash($state);
            if ($hash !== '') {
                $busy[$hash] = true;
            }
        }
        foreach ([$compileQueue, $pendingRollbacks] as $list) {
            foreach ($list as $item) {
                $identity = $item['identity'] ?? null;
                if ($identity instanceof ThemeVersionIdentity) {
                    $busy[$identity->ownerHash()] = true;
                }
            }
        }

        return $busy;
    }

    /** @param array{owner_hash?:string,item?:array} $state */
    private function stateOwnerHash(array $state): string
    {
        $hash = (string)($state['owner_hash'] ?? '');
        if ($hash !== '') {
            return $hash;
        }
        $identity = $state['item']['identity'] ?? null;
        if ($identity instanceof ThemeVersionIdentity) {
            return $identity->ownerHash();
        }

        return '';
    }

    /**
     * @param array<string, array{proc?:resource|false,pipes?:array,owner_hash?:string,item?:array,terminated_by_parent?:bool}> $running
     * @param array<string,true>|null $onlyOwners null = terminate all remaining
     */
    private function terminateRunningWorkers(array &$running, ?array $onlyOwners): void
    {
        foreach ($running as $key => $state) {
            if ($onlyOwners !== null) {
                $hash = $this->stateOwnerHash($state);
                if ($hash === '' || !isset($onlyOwners[$hash])) {
                    continue;
                }
            }
            $running[$key]['terminated_by_parent'] = true;
            $proc = $state['proc'] ?? null;
            if (is_resource($proc)) {
                @proc_terminate($proc);
            }
        }
        // Final drain: reap immediately so flushPendingRollbacks sees owners free.
        if ($onlyOwners === null && $running !== []) {
            usleep(100_000);
            foreach ($running as $key => $state) {
                $proc = $state['proc'] ?? null;
                if (is_resource($proc)) {
                    foreach ([1, 2] as $fd) {
                        if (isset($state['pipes'][$fd]) && is_resource($state['pipes'][$fd])) {
                            @fclose($state['pipes'][$fd]);
                        }
                    }
                    @proc_close($proc);
                }
                unset($running[$key]);
            }
        }
    }

    /**
     * Restore promotes only when no sibling worker still holds/needs the same OwnerLock.
     *
     * @param list<array{identity:ThemeVersionIdentity,job_dir:string}> $pendingRollbacks
     * @param array<string, array{kind?:string,owner_hash?:string,item?:array}> $running
     */
    private function flushPendingRollbacks(array &$pendingRollbacks, array $running): void
    {
        if ($pendingRollbacks === []) {
            return;
        }
        $kept = [];
        foreach ($pendingRollbacks as $pending) {
            $hash = $pending['identity']->ownerHash();
            if ($this->ownerHasRunningWorker($running, $hash)) {
                $kept[] = $pending;
                continue;
            }
            try {
                $this->rollbackFromJobDir($pending['identity'], $pending['job_dir']);
            } catch (\RuntimeException $error) {
                // Contended by external holder (WLS / sibling CLI): keep and retry after drain.
                if (!str_contains($error->getMessage(), 'theme_layout_owner_lock_timeout')) {
                    throw $error;
                }
                $kept[] = $pending;
            }
        }
        $pendingRollbacks = $kept;
    }

    /** @param array<string, array{kind?:string,owner_hash?:string,item?:array}> $running */
    private function ownerHasRunningWorker(array $running, string $ownerHash): bool
    {
        if ($ownerHash === '') {
            return false;
        }
        foreach ($running as $state) {
            if ($this->stateOwnerHash($state) === $ownerHash) {
                return true;
            }
        }

        return false;
    }

    private function rollbackFromJobDir(ThemeVersionIdentity $identity, string $jobDir): void
    {
        $index = $jobDir . '/rollback.json';
        if (!is_file($index)) {
            return;
        }
        $raw = file_get_contents($index);
        if (!is_string($raw) || $raw === '') {
            return;
        }
        try {
            $map = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }
        if (!is_array($map)) {
            return;
        }
        $plan = [];
        foreach ($map as $path => $rel) {
            if (!is_string($path)) {
                continue;
            }
            if ($rel === null) {
                $plan[$path] = null;
                continue;
            }
            if (!is_string($rel) || str_contains($rel, '..')) {
                continue;
            }
            $bytesPath = $jobDir . '/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (!is_file($bytesPath)) {
                continue;
            }
            $bytes = file_get_contents($bytesPath);
            if (is_string($bytes)) {
                $plan[$path] = $bytes;
            }
        }
        if ($plan !== []) {
            $this->publisher->rollbackPromote($identity, $plan);
        }
    }

    /**
     * @return array{concurrency:int, source:string, cpus:int, mem_avail_mb:int|null}
     */
    private function resolveConcurrencyDecision(?int $override, string $envName): array
    {
        return (new HostProcessPoolPolicy())->resolve(
            $override,
            HostProcessPoolPolicy::envRaw($envName),
            self::DEFAULT_CONCURRENCY,
            self::MAX_CONCURRENCY,
        );
    }

    /**
     * @param array{concurrency:int, source:string, cpus:int, mem_avail_mb:int|null} $solidifyDecision
     * @param array{concurrency:int, source:string, cpus:int, mem_avail_mb:int|null} $compileDecision
     */
    private function formatPoolDecisionNote(
        int $solidifyN,
        int $compileN,
        int $identities,
        array $solidifyDecision,
        array $compileDecision,
    ): string {
        $mem = $solidifyDecision['mem_avail_mb'] ?? $compileDecision['mem_avail_mb'];

        return sprintf(
            '%s solidify_pool=%d compile_pool=%d identities=%d solidify_source=%s compile_source=%s cpus=%d mem_avail_mb=%s',
            (string)__('主题布局固化编译流水线'),
            $solidifyN,
            $compileN,
            $identities,
            (string)$solidifyDecision['source'],
            (string)$compileDecision['source'],
            (int)$solidifyDecision['cpus'],
            $mem === null ? 'n/a' : (string)(int)$mem,
        );
    }

    private function isInsideWlsWorker(): bool
    {
        $id = trim((string)(getenv('WLS_WORKER_ID') ?: ($_ENV['WLS_WORKER_ID'] ?? $_SERVER['WLS_WORKER_ID'] ?? '')));

        return $id !== '';
    }

    private function removeTree(string $root): void
    {
        if ($root === '' || !is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($root);
    }

    /** Worker entry: solidify one identity (promote only). */
    public static function runSolidifyJob(array $job): array
    {
        self::applyDeployStagingRootFromJob($job);
        $identity = ThemeVersionIdentity::fromArray(is_array($job['identity'] ?? null) ? $job['identity'] : []);
        $pending = is_array($job['pending'] ?? null) ? $job['pending'] : [];
        $changes = is_array($job['changes'] ?? null) ? $job['changes'] : [];
        $jobDir = rtrim(str_replace('\\', '/', (string)($job['job_dir'] ?? '')), '/');
        if ($jobDir === '' || !is_dir($jobDir)) {
            throw new \InvalidArgumentException('theme_layout_solidify_job_dir_invalid');
        }

        /** @var ThemeLayoutEntityBakeCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
        $result = $coordinator->solidifyPendingIdentity($identity, $pending, $changes, false);
        $completed = is_array($result['completed'] ?? null) ? $result['completed'] : [];
        $rollback = is_array($result['rollback_plan'] ?? null) ? $result['rollback_plan'] : [];
        self::writeRollbackArtifacts($jobDir, $rollback);
        $paths = [];
        foreach ($completed as $path => $bytes) {
            if (is_string($path) && is_string($bytes)) {
                $paths[] = $path;
            }
        }

        return [
            'ok' => true,
            'migrated' => (int)($result['migrated'] ?? count($completed)),
            'candidate_paths' => $paths,
        ];
    }

    /** Worker entry: compile one identity from promoted paths. */
    public static function runCompileJob(array $job): array
    {
        self::applyDeployStagingRootFromJob($job);
        $identity = ThemeVersionIdentity::fromArray(is_array($job['identity'] ?? null) ? $job['identity'] : []);
        $paths = is_array($job['candidate_paths'] ?? null) ? $job['candidate_paths'] : [];
        putenv('WELINE_TEMPLATE_COMPILE_NESTED=1');
        $_ENV['WELINE_TEMPLATE_COMPILE_NESTED'] = '1';

        /** @var ThemeLayoutEntityFormalLocaleCompileService $compiler */
        $compiler = ObjectManager::getInstance(ThemeLayoutEntityFormalLocaleCompileService::class);
        $compiler->compileAfterPromoteFromPaths($identity, array_values(array_filter($paths, 'is_string')), [
            'nested' => true,
            // Keep worker stderr clean: parent surfaces real failures; progress bars look like exit noise.
            'on_progress' => static function (): void {
            },
        ]);

        return ['ok' => true, 'compiled' => count($paths)];
    }

    /**
     * @param array<string,?string> $rollback
     */
    private static function writeRollbackArtifacts(string $jobDir, array $rollback): void
    {
        $index = [];
        $i = 0;
        foreach ($rollback as $path => $bytes) {
            if (!is_string($path)) {
                continue;
            }
            if ($bytes === null) {
                $index[$path] = null;
                continue;
            }
            if (!is_string($bytes)) {
                continue;
            }
            $rel = 'rollback/' . sprintf('%04d.bin', $i++);
            $abs = $jobDir . '/' . $rel;
            $dir = dirname($abs);
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('theme_layout_rollback_dir_failed');
            }
            if (file_put_contents($abs, $bytes) === false) {
                throw new \RuntimeException('theme_layout_rollback_write_failed');
            }
            $index[$path] = $rel;
        }
        file_put_contents(
            $jobDir . '/rollback.json',
            json_encode($index, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
