<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;
use Throwable;

final class Cli
{
    /** @param list<string> $argv */
    public static function runCtl(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $arguments = array_slice($argv, 2);
        try {
            return match ($command) {
                'hook' => self::hook($arguments),
                'project' => self::project($arguments),
                'review' => self::review($arguments),
                'evidence' => self::evidence($arguments),
                'outcome' => self::outcome($arguments),
                'proposal' => self::proposal($arguments),
                'delete' => self::delete($arguments),
                'doctor' => self::doctor($arguments),
                'scheduler' => self::scheduler($arguments),
                'intelligence', 'intel' => self::intelligence($arguments),
                'help', '--help', '-h' => self::help(),
                default => throw new RuntimeException('Unknown learningctl command: ' . $command),
            };
        } catch (Throwable $exception) {
            self::writeError($exception);
            return 1;
        }
    }

    /** @param list<string> $argv */
    public static function runDaemon(array $argv): int
    {
        $command = $argv[1] ?? 'run';
        try {
            [$positionals, $options] = self::options(
                array_slice($argv, 2),
                ['config', 'data-dir', 'max-jobs'],
            );
            if ($positionals !== []) {
                throw new RuntimeException('Unexpected learningd arguments: ' . implode(' ', $positionals));
            }
            $config = self::config($options);
            $store = new Store($config);
            try {
                $worker = new Worker($store, new Analyzer($store, $config), $config);
                $result = match ($command) {
                    'once' => $worker->runOnce(),
                    'drain' => $worker->drain((int) ($options['max-jobs'] ?? 100)),
                    'run' => $worker->run(),
                    default => throw new RuntimeException('Unknown learningd command: ' . $command),
                };
                self::writeJson($result);
            } finally {
                $store->close();
            }

            return 0;
        } catch (Throwable $exception) {
            self::writeError($exception);
            return 1;
        }
    }

    /** @param list<string> $argv */
    public static function runMcp(array $argv): int
    {
        try {
            [$positionals, $options] = self::options(array_slice($argv, 1), ['config', 'data-dir']);
            if ($positionals !== []) {
                throw new RuntimeException('Unexpected learning-mcp arguments: ' . implode(' ', $positionals));
            }
            $config = self::config($options);
            $store = new Store($config);
            try {
                $analyzer = new Analyzer($store, $config);
                $server = new McpServer(new ToolService($store, $config, $analyzer));
                $server->run(STDIN, STDOUT);
            } finally {
                $store->close();
            }

            return 0;
        } catch (Throwable $exception) {
            self::writeError($exception);
            return 1;
        }
    }

    /** @param list<string> $arguments */
    private static function hook(array $arguments): int
    {
        [$positionals, $options] = self::options(
            $arguments,
            ['config', 'data-dir', 'json', 'inject-project-context', 'inject-project-rules', 'strict'],
            ['json', 'inject-project-context', 'inject-project-rules', 'strict'],
        );
        $event = $positionals[0] ?? '';
        if ($event === '' || count($positionals) !== 1) {
            throw new RuntimeException('Usage: learningctl hook <event> [--inject-project-context] [--inject-project-rules] [--json] [--strict]');
        }
        $canonicalEvent = self::canonicalHook($event);
        $injectProjectContext = self::bool($options['inject-project-context'] ?? false);
        if ($injectProjectContext && $canonicalEvent !== 'SessionStart') {
            throw new RuntimeException('--inject-project-context is only valid for SessionStart');
        }
        $strict = self::bool($options['strict'] ?? false);
        $config = null;
        $store = null;
        $result = null;
        $autoContext = null;
        $postToolRepository = null;
        try {
            $config = self::config($options);
            $store = new Store($config);
            $result = (new Collector($store, $config))->ingest($canonicalEvent, STDIN);
            if ($canonicalEvent === 'PostToolUse'
                && (bool) $config->get('index.enabled', true)
                && (bool) $config->get('index.auto_refresh', true)
                && isset($result['session_id'])) {
                $session = $store->getSession((string) $result['session_id']);
                $postToolRepository = trim((string) ($session['worktree'] ?: $session['cwd'])) ?: null;
            }
            $additionalContext = [];
            if ($injectProjectContext) {
                $autoContext = (new ProjectAutoContext($store, $config))->describe($result);
                $additionalContext[] = (string) $autoContext['context'];
            }
            if (self::bool($options['inject-project-rules'] ?? false)) {
                $context = $store->trustedProjectContext((string) ($result['project_id'] ?? ''), 10);
                if ($context !== []) {
                    $additionalContext[] = "Evidence-backed project learning follows. Apply it only within scope and inspect provenance before consequential decisions.\n" . implode("\n", $context);
                }
            }
            if ($additionalContext !== []) {
                self::writeJson([
                    'continue' => true,
                    'hookSpecificOutput' => [
                        'hookEventName' => 'SessionStart',
                        'additionalContext' => implode("\n\n", $additionalContext),
                    ],
                ]);
            } elseif (self::bool($options['json'] ?? false)) {
                self::writeJson($result);
            }
        } catch (Throwable $exception) {
            if ($store instanceof Store) {
                $store->close();
            }
            if ($strict) {
                throw $exception;
            }
            [$message] = Redactor::string($exception->getMessage());
            fwrite(STDERR, 'learningctl hook (non-blocking): ' . Text::truncate($message, 500) . "\n");
            return 0;
        }
        $store->close();
        $refreshRepository = $postToolRepository;
        if (is_array($autoContext) && (bool) ($autoContext['schedule_refresh'] ?? false)) {
            $refreshRepository = (string) $autoContext['repository'];
        }
        if (is_string($refreshRepository) && $refreshRepository !== '') {
            ProjectAutoContext::spawnIndex(
                $config->sourcePath,
                $config->dataDir(),
                $refreshRepository,
            );
        }
        if (isset($result['job_id']) && (bool) $config->get('scheduler.auto_process_on_stop', true)) {
            Worker::spawnDrain($config->sourcePath, $config->dataDir());
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private static function project(array $arguments): int
    {
        [$positionals, $options] = self::options($arguments, ['cwd', 'dirty'], ['dirty']);
        if ($positionals !== []) {
            throw new RuntimeException('Unexpected project arguments');
        }
        self::writeJson(ProjectResolver::resolve((string) ($options['cwd'] ?? '.'), self::bool($options['dirty'] ?? false)));

        return 0;
    }

    /** @param list<string> $arguments */
    private static function review(array $arguments): int
    {
        $subcommand = array_shift($arguments) ?? '';
        if ($subcommand === 'list') {
            [$positionals, $options] = self::options($arguments, ['config', 'data-dir', 'project', 'repository', 'limit', 'cursor']);
            self::noPositionals($positionals);
            [$config, $store] = self::open($options);
            try {
                $projectId = self::resolveProjectId($store, $options);
                self::writeJson($store->listCandidates($projectId, (int) ($options['limit'] ?? 50), (int) ($options['cursor'] ?? 0)));
            } finally {
                $store->close();
            }
            return 0;
        }
        if ($subcommand === 'mark') {
            [$positionals, $options] = self::options($arguments, ['config', 'data-dir', 'id', 'status', 'actor', 'reason']);
            self::noPositionals($positionals);
            [, $store] = self::open($options);
            try {
                self::writeJson($store->markExperience(
                    self::requiredOption($options, 'id'),
                    self::requiredOption($options, 'status'),
                    self::requiredOption($options, 'actor'),
                    self::requiredOption($options, 'reason'),
                ));
            } finally {
                $store->close();
            }
            return 0;
        }

        throw new RuntimeException('review subcommand must be list or mark');
    }

    /** @param list<string> $arguments */
    private static function evidence(array $arguments): int
    {
        if ((array_shift($arguments) ?? '') !== 'add') {
            throw new RuntimeException('evidence subcommand must be add');
        }
        [$positionals, $options] = self::options($arguments, [
            'config', 'data-dir', 'id', 'project', 'repository', 'session', 'experience', 'type', 'claim',
            'polarity', 'strength', 'verified', 'locator', 'actor',
        ], ['verified']);
        self::noPositionals($positionals);
        $actor = self::requiredOption($options, 'actor');
        $locator = Json::object((string) ($options['locator'] ?? '{}'), 'locator JSON');
        [, $store] = self::open($options);
        try {
            $projectId = self::resolveProjectId($store, $options);
            $stored = $store->putEvidence([
                'evidence_id' => (string) ($options['id'] ?? ''),
                'project_id' => $projectId,
                'session_id' => (string) ($options['session'] ?? ''),
                'evidence_type' => self::requiredOption($options, 'type'),
                'claim' => self::requiredOption($options, 'claim'),
                'polarity' => (string) ($options['polarity'] ?? 'supports'),
                'strength' => (float) ($options['strength'] ?? 0.8),
                'verified' => self::bool($options['verified'] ?? false),
                'locator' => $locator,
                'created_at' => Clock::now(),
            ]);
            $experienceId = trim((string) ($options['experience'] ?? ''));
            $attachment = null;
            if ($experienceId !== '') {
                $attachment = $store->attachEvidence(
                    $experienceId,
                    $stored['id'],
                    (string) ($options['polarity'] ?? 'supports'),
                    $actor,
                );
            }
            $store->writeAudit($actor, 'add_evidence', 'evidence', $stored['id'], [
                'type' => $options['type'],
                'verified' => self::bool($options['verified'] ?? false),
                'created' => $stored['created'],
                'experience_id' => $experienceId,
            ]);
            self::writeJson([
                'created' => $stored['created'],
                'evidence' => $store->evidence([$stored['id']])[0],
                'attachment' => $attachment,
            ]);
        } finally {
            $store->close();
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private static function outcome(array $arguments): int
    {
        if ((array_shift($arguments) ?? '') !== 'record') {
            throw new RuntimeException('outcome subcommand must be record');
        }
        [$positionals, $options] = self::options($arguments, [
            'config', 'data-dir', 'project', 'session', 'experience-ids', 'evidence-ids', 'idempotency-key',
            'result', 'applied', 'comment', 'user-confirmed', 'actor',
        ], ['applied', 'user-confirmed']);
        self::noPositionals($positionals);
        $projectId = self::requiredOption($options, 'project');
        $experienceIds = self::csv(self::requiredOption($options, 'experience-ids'));
        $evidenceIds = self::csv((string) ($options['evidence-ids'] ?? ''));
        $key = self::requiredOption($options, 'idempotency-key');
        [, $store] = self::open($options);
        try {
            $store->requireEvidence($projectId, $evidenceIds);
            $results = [];
            foreach ($experienceIds as $experienceId) {
                $results[] = $store->recordFeedback([
                    'project_id' => $projectId,
                    'session_id' => (string) ($options['session'] ?? ''),
                    'experience_id' => $experienceId,
                    'actor' => (string) ($options['actor'] ?? 'learningctl'),
                    'result' => self::requiredOption($options, 'result'),
                    'applied' => self::bool($options['applied'] ?? false),
                    'comment' => (string) ($options['comment'] ?? ''),
                    'evidence_ids' => $evidenceIds,
                    'user_confirmed' => self::bool($options['user-confirmed'] ?? false),
                    'idempotency_key' => $key . ':' . $experienceId,
                ]);
            }
            self::writeJson($results);
        } finally {
            $store->close();
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private static function proposal(array $arguments): int
    {
        if ((array_shift($arguments) ?? '') !== 'list') {
            throw new RuntimeException('proposal subcommand must be list');
        }
        [$positionals, $options] = self::options($arguments, ['config', 'data-dir', 'project', 'limit']);
        self::noPositionals($positionals);
        [, $store] = self::open($options);
        try {
            self::writeJson($store->listProposals(self::requiredOption($options, 'project'), (int) ($options['limit'] ?? 50)));
        } finally {
            $store->close();
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private static function delete(array $arguments): int
    {
        $target = array_shift($arguments) ?? '';
        if (!in_array($target, ['session', 'project'], true)) {
            throw new RuntimeException('delete target must be session or project');
        }
        [$positionals, $options] = self::options($arguments, ['config', 'data-dir', 'id', 'actor', 'yes'], ['yes']);
        self::noPositionals($positionals);
        if (!self::bool($options['yes'] ?? false)) {
            throw new RuntimeException('--yes is required for deletion');
        }
        $id = self::requiredOption($options, 'id');
        $actor = self::requiredOption($options, 'actor');
        [, $store] = self::open($options);
        try {
            if ($target === 'session') {
                $store->deleteSession($id, $actor);
            } else {
                $store->deleteProject($id, $actor);
            }
            self::writeJson(['deleted' => true, 'target' => $target, 'id' => $id]);
        } finally {
            $store->close();
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private static function doctor(array $arguments): int
    {
        [$positionals, $options] = self::options($arguments, ['config', 'data-dir']);
        self::noPositionals($positionals);
        $config = self::config($options);
        $checks = [
            'php_version' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'json' => extension_loaded('json'),
            'mbstring' => extension_loaded('mbstring'),
            'openssl' => extension_loaded('openssl'),
            'allow_url_fopen' => filter_var((string) ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL),
            'pcntl_stop_worker' => function_exists('pcntl_fork'),
        ];
        $store = new Store($config);
        try {
            $analyzer = null;
            $analyzerError = '';
            try {
                $analyzer = new Analyzer($store, $config);
            } catch (Throwable $exception) {
                [$analyzerError] = Redactor::string($exception->getMessage());
            }
            $requiredChecks = array_intersect_key($checks, array_flip([
                'php_version', 'pdo_sqlite', 'json', 'mbstring', 'openssl',
            ]));
            if ($config->get('analysis.provider') === 'openai') {
                $requiredChecks['allow_url_fopen'] = $checks['allow_url_fopen'];
            }
            $ok = !in_array(false, $requiredChecks, true) && $analyzerError === '';
            self::writeJson([
                'ok' => $ok,
                'runtime' => ['php' => PHP_VERSION, 'binary' => PHP_BINARY, 'checks' => $checks],
                'data_dir' => $config->dataDir(),
                'config_path' => $config->sourcePath,
                'mode' => $config->get('mode'),
                'database' => $store->health(),
                'analyzer' => $analyzer?->metadata() ?? ['provider' => $config->get('analysis.provider'), 'error' => $analyzerError],
                'scheduler' => (new Scheduler($config))->status(),
                'warnings' => $checks['pcntl_stop_worker']
                    ? []
                    : ['pcntl is unavailable; Stop will queue work but cannot fork a one-shot worker.'],
                'security' => ['redact_secrets' => true, 'automatic_promotion' => false, 'cross_project' => false],
            ]);
        } finally {
            $store->close();
        }

        return $ok ? 0 : 1;
    }

    /** @param list<string> $arguments */
    private static function scheduler(array $arguments): int
    {
        $subcommand = array_shift($arguments) ?? 'status';
        [$positionals, $options] = self::options($arguments, ['config', 'data-dir', 'max-jobs']);
        self::noPositionals($positionals);
        $config = self::config($options);
        $scheduler = new Scheduler($config);
        $result = match ($subcommand) {
            'print' => ['plist' => $scheduler->renderPlist(), 'status' => $scheduler->status()],
            'status' => $scheduler->status(),
            'install' => $scheduler->install(),
            'uninstall' => $scheduler->uninstall(),
            'kickstart' => $scheduler->kickstart(),
            'run-now' => self::runWorkerNow($config, (int) ($options['max-jobs'] ?? 100)),
            default => throw new RuntimeException('scheduler subcommand must be print, status, install, uninstall, kickstart, or run-now'),
        };
        self::writeJson($result);

        return 0;
    }

    /** @param list<string> $arguments */
    private static function intelligence(array $arguments): int
    {
        $tool = array_shift($arguments) ?? '';
        if ($tool === '') {
            throw new RuntimeException('Usage: learningctl intelligence <tool> --repository PATH [--input JSON|-]');
        }
        [$positionals, $options] = self::options(
            $arguments,
            ['config', 'data-dir', 'repository', 'project', 'input'],
        );
        self::noPositionals($positionals);
        $repository = self::requiredOption($options, 'repository');
        $input = trim((string) ($options['input'] ?? '{}'));
        if ($input === '-') {
            $body = stream_get_contents(STDIN);
            if ($body === false) {
                throw new RuntimeException('Unable to read intelligence input from stdin');
            }
            $input = $body;
        }
        $payload = Json::object($input === '' ? '{}' : $input, 'intelligence input');
        $payload['repository'] = $repository;
        if (trim((string) ($options['project'] ?? '')) !== '') {
            $payload['project_id'] = (string) $options['project'];
        }
        [$config, $store] = self::open($options);
        try {
            self::writeJson((new IntelligenceService($store, $config))->call($tool, $payload));
        } finally {
            $store->close();
        }

        return 0;
    }

    private static function runWorkerNow(Config $config, int $maximumJobs): array
    {
        $store = new Store($config);
        try {
            return (new Worker($store, new Analyzer($store, $config), $config))->drain($maximumJobs);
        } finally {
            $store->close();
        }
    }

    private static function help(): int
    {
        fwrite(STDOUT, <<<'HELP'
Learning MCP (PHP)

Commands:
  learningctl hook <SessionStart|UserPromptSubmit|PreToolUse|PostToolUse|Stop>
  learningctl project [--cwd PATH] [--dirty]
  learningctl review list|mark ...
  learningctl evidence add ...
  learningctl outcome record ...
  learningctl proposal list ...
  learningctl delete session|project ... --yes
  learningctl doctor [--config PATH] [--data-dir PATH]
  learningctl scheduler print|status|install|uninstall|kickstart|run-now ...
  learningctl intelligence <tool> --repository PATH [--input JSON|-]

  learningd once|drain|run [--config PATH] [--data-dir PATH]
  learning-mcp [--config PATH] [--data-dir PATH]

HELP);

        return 0;
    }

    /** @param array<string, mixed> $options
     *  @return array{Config,Store}
     */
    private static function open(array $options): array
    {
        $config = self::config($options);
        return [$config, new Store($config)];
    }

    /** @param array<string, mixed> $options */
    private static function config(array $options): Config
    {
        return Config::load(
            isset($options['config']) ? (string) $options['config'] : null,
            isset($options['data-dir']) ? (string) $options['data-dir'] : null,
        );
    }

    /** @param array<string, mixed> $options */
    private static function resolveProjectId(Store $store, array $options): string
    {
        $projectId = trim((string) ($options['project'] ?? ''));
        $repository = trim((string) ($options['repository'] ?? ''));
        if ($repository === '') {
            if ($projectId === '') {
                throw new RuntimeException('--project or --repository is required');
            }
            return $projectId;
        }
        $resolved = ProjectResolver::resolve($repository);
        $actual = (string) $resolved['project']['id'];
        if ($projectId !== '' && $projectId !== $actual) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', '--project does not match --repository');
        }
        $store->upsertProject($resolved['project']);

        return $actual;
    }

    private static function canonicalHook(string $value): string
    {
        $normalized = strtolower(str_replace(['_', ' '], '-', trim($value)));
        return match ($normalized) {
            'sessionstart', 'session-start' => 'SessionStart',
            'userpromptsubmit', 'user-prompt', 'user-prompt-submit' => 'UserPromptSubmit',
            'pretooluse', 'pre-tool-use' => 'PreToolUse',
            'posttooluse', 'post-tool-use' => 'PostToolUse',
            'precompact', 'pre-compact' => 'PreCompact',
            'postcompact', 'post-compact' => 'PostCompact',
            'stop' => 'Stop',
            default => $value,
        };
    }

    /** @param list<string> $arguments
     *  @param list<string> $allowed
     *  @param list<string> $booleans
     *  @return array{list<string>,array<string,mixed>}
     */
    private static function options(array $arguments, array $allowed, array $booleans = []): array
    {
        $positionals = [];
        $options = [];
        for ($index = 0; $index < count($arguments); ++$index) {
            $argument = $arguments[$index];
            if (!str_starts_with($argument, '--')) {
                $positionals[] = $argument;
                continue;
            }
            $option = substr($argument, 2);
            $value = null;
            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            }
            if (!in_array($option, $allowed, true)) {
                throw new RuntimeException('Unknown option --' . $option);
            }
            if (in_array($option, $booleans, true)) {
                $options[$option] = $value === null ? true : self::bool($value);
                continue;
            }
            if ($value === null) {
                if (!isset($arguments[$index + 1]) || str_starts_with($arguments[$index + 1], '--')) {
                    throw new RuntimeException('Option --' . $option . ' requires a value');
                }
                $value = $arguments[++$index];
            }
            $options[$option] = $value;
        }

        return [$positionals, $options];
    }

    /** @param list<string> $positionals */
    private static function noPositionals(array $positionals): void
    {
        if ($positionals !== []) {
            throw new RuntimeException('Unexpected arguments: ' . implode(' ', $positionals));
        }
    }

    /** @param array<string, mixed> $options */
    private static function requiredOption(array $options, string $key): string
    {
        $value = trim((string) ($options[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException('--' . $key . ' is required');
        }

        return $value;
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return Text::uniqueStrings(explode(',', $value));
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1'
            || (is_string($value) && in_array(strtolower($value), ['true', 'yes', 'on'], true));
    }

    private static function writeJson(mixed $value): void
    {
        fwrite(STDOUT, Json::encode($value, true) . "\n");
        fflush(STDOUT);
    }

    private static function writeError(Throwable $exception): void
    {
        [$message] = Redactor::string($exception->getMessage());
        $code = $exception instanceof ToolException ? $exception->errorCode : 'CLI_ERROR';
        fwrite(STDERR, Json::encode(['error' => ['code' => $code, 'message' => Text::truncate($message, 1_000)]], true) . "\n");
    }
}
