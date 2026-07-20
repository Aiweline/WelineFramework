<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;

final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(
        private array $values,
        public readonly ?string $sourcePath,
    ) {
    }

    public static function load(?string $path = null, ?string $dataDirOverride = null): self
    {
        $explicit = $path !== null && trim($path) !== '';
        $path = $explicit ? self::expandPath((string) $path) : self::defaultPath();
        $provided = [];
        $sourcePath = null;
        if (is_file($path)) {
            $body = file_get_contents($path);
            if ($body === false) {
                throw new RuntimeException('Unable to read config: ' . $path);
            }
            $provided = str_ends_with(strtolower($path), '.json')
                ? Json::object($body, 'config JSON')
                : SimpleYaml::parse($body);
            $sourcePath = $path;
        } elseif ($explicit) {
            throw new RuntimeException('Config file does not exist: ' . $path);
        }

        self::assertKnown($provided, self::shape());
        $values = self::merge(self::defaults(), $provided);
        $environmentDataDir = getenv('LEARNING_MCP_DATA_DIR');
        if ($dataDirOverride !== null && trim($dataDirOverride) !== '') {
            $values['data_dir'] = $dataDirOverride;
        } elseif (is_string($environmentDataDir) && trim($environmentDataDir) !== '') {
            $values['data_dir'] = $environmentDataDir;
        }
        $values['data_dir'] = self::expandPath((string) $values['data_dir']);
        $environmentSkillOutputDirectory = getenv('LEARNING_MCP_SKILL_OUTPUT_DIR');
        if (is_string($environmentSkillOutputDirectory) && trim($environmentSkillOutputDirectory) !== '') {
            $values['knowledge']['learning_skills']['output_directory'] = $environmentSkillOutputDirectory;
        }
        $values['knowledge']['learning_skills']['output_directory'] = trim(
            (string) $values['knowledge']['learning_skills']['output_directory']
        );
        $values['analysis']['provider'] = strtolower(trim((string) $values['analysis']['provider']));
        $values['analysis']['base_url'] = rtrim(trim((string) $values['analysis']['base_url']), '/');
        $values['retrieval']['minimum_status'] = strtolower(trim((string) $values['retrieval']['minimum_status']));
        $values['knowledge']['codex']['binary'] = trim((string) $values['knowledge']['codex']['binary']);
        $values['knowledge']['codex']['model'] = trim((string) $values['knowledge']['codex']['model']);
        self::validate($values);

        return new self($values, $sourcePath);
    }

    public static function defaultPath(): string
    {
        $configured = getenv('LEARNING_MCP_CONFIG');
        if (is_string($configured) && trim($configured) !== '') {
            return self::expandPath($configured);
        }

        return self::expandPath('~/.learning-mcp/config.yaml');
    }

    public function get(string $path, mixed $fallback = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $fallback;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public function dataDir(): string
    {
        return (string) $this->values['data_dir'];
    }

    public function duration(string $path): int
    {
        return self::durationSeconds((string) $this->get($path));
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public static function durationSeconds(string $value): int
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^(\d+)(ms|s|m|h|d)$/', $value, $matches)) {
            throw new RuntimeException('Invalid duration: ' . $value);
        }
        $amount = (int) $matches[1];
        $multiplier = match ($matches[2]) {
            'ms' => 0.001,
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
        };

        return max(1, (int) ceil($amount * $multiplier));
    }

    public static function expandPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Path cannot be empty');
        }
        if ($path === '~' || str_starts_with($path, '~/') || str_starts_with($path, '~\\')) {
            $home = getenv('HOME');
            if (!is_string($home) || $home === '') {
                $home = getenv('USERPROFILE');
            }
            if (!is_string($home) || $home === '') {
                throw new RuntimeException('HOME and USERPROFILE are unavailable for path expansion');
            }
            $path = $home . substr($path, 1);
        }
        $absolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~D', $path) === 1;
        if (!$absolute) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Unable to resolve current directory');
            }
            $path = $cwd . DIRECTORY_SEPARATOR . $path;
        }

        return rtrim($path, "/\\");
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'data_dir' => '~/.learning-mcp',
            'mode' => 'local',
            'collector' => [
                'redact_secrets' => true,
                'allow_cross_project' => false,
                'max_event_bytes' => 8_388_608,
            ],
            'analysis' => [
                'provider' => 'codex',
                'api_key_env' => 'OPENAI_API_KEY',
                'base_url' => 'https://api.openai.com/v1',
                'extractor_model' => '',
                'verifier_model' => '',
                'max_session_tokens' => 50_000,
                'request_timeout' => '60s',
                'require_non_model_evidence_for_technical_rules' => true,
                'automatic_learning' => [
                    'enabled' => true,
                    'auto_validate' => true,
                    'max_candidates' => 6,
                    'max_existing_experiences' => 100,
                    'max_project_matches' => 12,
                    'duplicate_similarity' => 0.86,
                    'related_similarity' => 0.55,
                    'conflict_similarity' => 0.62,
                    'project_duplicate_similarity' => 0.9,
                    'minimum_validation_confidence' => 0.9,
                ],
            ],
            'retrieval' => [
                'minimum_status' => 'validated',
                'max_items' => 5,
                'token_budget' => 1_800,
                'include_candidates' => false,
            ],
            'promotion' => [
                'automatic' => false,
                'allowed_targets' => [
                    'repository_knowledge',
                    'agents_md_proposal',
                    'skill_proposal',
                    'test_proposal',
                ],
            ],
            'privacy' => [
                'raw_retention' => '90d',
                'redact_before_model' => true,
            ],
            'scheduler' => [
                'poll_interval' => '10s',
                'session_idle_after' => '15m',
                'launchd_interval' => '10m',
                'max_attempts' => 5,
                'lease' => '2m',
                'auto_process_on_stop' => true,
            ],
            'index' => [
                'enabled' => true,
                'auto_refresh' => true,
                'sidecar_enabled' => true,
                'refresh_interval' => '60s',
                'max_file_bytes' => 524_288,
                'max_chunk_chars' => 6_000,
                'context_token_budget' => 6_000,
                'vector_dimensions' => 2_048,
                'vector_max_terms' => 24,
                'sqlite_mmap_bytes' => 268_435_456,
                'sqlite_cache_kib' => 16_384,
                'include_tests' => false,
                'allowed_extensions' => [
                    'php', 'phtml', 'md', 'markdown', 'txt', 'json', 'yaml', 'yml', 'xml', 'toml', 'ini',
                    'csv', 'sql', 'js', 'jsx', 'ts', 'tsx', 'vue', 'css', 'scss', 'less', 'html', 'htm',
                    'sh', 'bash', 'zsh', 'go', 'py', 'java', 'kt', 'rs', 'c', 'cc', 'cpp', 'h', 'hpp', 'proto',
                ],
                'excluded_paths' => [
                    '.git/**', '.gitnexus/**', '.codex/code-intelligence/**', 'vendor/**', '**/vendor/**',
                    'node_modules/**', '**/node_modules/**', 'generated/**', 'var/**', 'pub/static/**',
                    'pub/media/**', '**/view/tpl/**', '**/static/libs/**', 'dev/ai/archive/**',
                    '**/test/**', '**/tests/**', '**/Test/**', '**/*.min.*', '**/*.map',
                ],
            ],
            'editing' => [
                'enabled' => true,
                'ticket_ttl' => '10m',
                'max_files' => 20,
                'max_file_bytes' => 1_048_576,
                'max_total_bytes' => 4_194_304,
                'allowed_roots' => ['app/code', 'dev/ai/mcp'],
                'denied_paths' => [
                    '.git/**', '.codex/**', '.agents/**', '.gitnexus/**', 'vendor/**', '**/vendor/**',
                    'generated/**', 'var/**', 'pub/static/**', 'pub/media/**', '**/view/tpl/**',
                    '**/.env', '**/.env.*', '**/auth.json', '**/*.pem', '**/*.key', 'app/etc/env.php',
                ],
            ],
            'knowledge' => [
                'auto_generate_skills' => true,
                'auto_doc_sync' => false,
                'generated_skill_status' => 'validated',
                'learning_skills' => [
                    'enabled' => true,
                    'output_directory' => '',
                    'minimum_confidence' => 0.9,
                    'max_experiences' => 100,
                    'max_skills' => 12,
                    'max_module_skill_projections' => 64,
                    'inject_on_prompt' => true,
                    'prompt_skill_limit' => 3,
                    'prompt_token_budget' => 2_400,
                ],
                'codex' => [
                    'enabled' => true,
                    'binary' => '',
                    'model' => '',
                    'timeout' => '120s',
                    'max_context_chars' => 60_000,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function shape(): array
    {
        return [
            'data_dir' => true,
            'mode' => true,
            'collector' => [
                'redact_secrets' => true,
                'allow_cross_project' => true,
                'max_event_bytes' => true,
            ],
            'analysis' => [
                'provider' => true,
                'api_key_env' => true,
                'base_url' => true,
                'extractor_model' => true,
                'verifier_model' => true,
                'max_session_tokens' => true,
                'request_timeout' => true,
                'require_non_model_evidence_for_technical_rules' => true,
                'automatic_learning' => [
                    'enabled' => true,
                    'auto_validate' => true,
                    'max_candidates' => true,
                    'max_existing_experiences' => true,
                    'max_project_matches' => true,
                    'duplicate_similarity' => true,
                    'related_similarity' => true,
                    'conflict_similarity' => true,
                    'project_duplicate_similarity' => true,
                    'minimum_validation_confidence' => true,
                ],
            ],
            'retrieval' => [
                'minimum_status' => true,
                'max_items' => true,
                'token_budget' => true,
                'include_candidates' => true,
            ],
            'promotion' => [
                'automatic' => true,
                'allowed_targets' => true,
            ],
            'privacy' => [
                'raw_retention' => true,
                'redact_before_model' => true,
            ],
            'scheduler' => [
                'poll_interval' => true,
                'session_idle_after' => true,
                'launchd_interval' => true,
                'max_attempts' => true,
                'lease' => true,
                'auto_process_on_stop' => true,
            ],
            'index' => [
                'enabled' => true,
                'auto_refresh' => true,
                'sidecar_enabled' => true,
                'refresh_interval' => true,
                'max_file_bytes' => true,
                'max_chunk_chars' => true,
                'context_token_budget' => true,
                'vector_dimensions' => true,
                'vector_max_terms' => true,
                'sqlite_mmap_bytes' => true,
                'sqlite_cache_kib' => true,
                'include_tests' => true,
                'allowed_extensions' => true,
                'excluded_paths' => true,
            ],
            'editing' => [
                'enabled' => true,
                'ticket_ttl' => true,
                'max_files' => true,
                'max_file_bytes' => true,
                'max_total_bytes' => true,
                'allowed_roots' => true,
                'denied_paths' => true,
            ],
            'knowledge' => [
                'auto_generate_skills' => true,
                'auto_doc_sync' => true,
                'generated_skill_status' => true,
                'learning_skills' => [
                    'enabled' => true,
                    'output_directory' => true,
                    'minimum_confidence' => true,
                    'max_experiences' => true,
                    'max_skills' => true,
                    'max_module_skill_projections' => true,
                    'inject_on_prompt' => true,
                    'prompt_skill_limit' => true,
                    'prompt_token_budget' => true,
                ],
                'codex' => [
                    'enabled' => true,
                    'binary' => true,
                    'model' => true,
                    'timeout' => true,
                    'max_context_chars' => true,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $provided
     *  @param array<string, mixed> $shape
     */
    private static function assertKnown(array $provided, array $shape, string $prefix = ''): void
    {
        foreach ($provided as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $shape)) {
                throw new RuntimeException('Unknown config field: ' . $prefix . (string) $key);
            }
            if (is_array($shape[$key]) && is_array($value)) {
                if (array_is_list($value)) {
                    throw new RuntimeException('Config field must be a mapping: ' . $prefix . $key);
                }
                self::assertKnown($value, $shape[$key], $prefix . $key . '.');
            }
        }
    }

    /** @param array<string, mixed> $defaults
     *  @param array<string, mixed> $provided
     *  @return array<string, mixed>
     */
    private static function merge(array $defaults, array $provided): array
    {
        foreach ($provided as $key => $value) {
            if (isset($defaults[$key]) && is_array($defaults[$key]) && is_array($value)
                && !array_is_list($defaults[$key]) && !array_is_list($value)) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /** @param array<string, mixed> $values */
    private static function validate(array $values): void
    {
        if ($values['mode'] !== 'local') {
            throw new RuntimeException('Only mode=local is supported');
        }
        $provider = strtolower(trim((string) $values['analysis']['provider']));
        if (!in_array($provider, ['none', 'codex', 'openai'], true)) {
            throw new RuntimeException('analysis.provider must be none, codex, or openai');
        }
        if ($provider === 'openai') {
            if (trim((string) $values['analysis']['extractor_model']) === ''
                || trim((string) $values['analysis']['verifier_model']) === '') {
                throw new RuntimeException('OpenAI analysis requires extractor_model and verifier_model');
            }
            $environmentName = trim((string) $values['analysis']['api_key_env']);
            if ($environmentName === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/', $environmentName) !== 1) {
                throw new RuntimeException('analysis.api_key_env must be an environment variable name');
            }
            $url = parse_url((string) $values['analysis']['base_url']);
            $scheme = strtolower((string) ($url['scheme'] ?? ''));
            $host = strtolower((string) ($url['host'] ?? ''));
            $localHttp = $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            if ($host === '' || ($scheme !== 'https' && !$localHttp)) {
                throw new RuntimeException('analysis.base_url must use HTTPS, except for localhost HTTP');
            }
        }
        foreach ([
            'collector.redact_secrets', 'collector.allow_cross_project',
            'analysis.require_non_model_evidence_for_technical_rules',
            'analysis.automatic_learning.enabled', 'analysis.automatic_learning.auto_validate',
            'retrieval.include_candidates', 'promotion.automatic',
            'privacy.redact_before_model', 'scheduler.auto_process_on_stop',
            'index.enabled', 'index.auto_refresh', 'index.sidecar_enabled', 'index.include_tests',
            'editing.enabled', 'knowledge.auto_generate_skills', 'knowledge.auto_doc_sync',
            'knowledge.learning_skills.enabled', 'knowledge.learning_skills.inject_on_prompt',
            'knowledge.codex.enabled',
        ] as $boolean) {
            if (!is_bool(self::nested($values, $boolean))) {
                throw new RuntimeException($boolean . ' must be a boolean');
            }
        }
        $unsafe = [
            [!(bool) $values['collector']['redact_secrets'], 'collector.redact_secrets cannot be disabled'],
            [(bool) $values['collector']['allow_cross_project'], 'collector.allow_cross_project is not supported in local v1'],
            [!(bool) $values['analysis']['require_non_model_evidence_for_technical_rules'], 'technical evidence gate cannot be disabled'],
            [(bool) $values['retrieval']['include_candidates'], 'candidate experiences cannot be actionable guidance'],
            [(bool) $values['promotion']['automatic'], 'automatic promotion is prohibited'],
            [!(bool) $values['privacy']['redact_before_model'], 'privacy.redact_before_model cannot be disabled'],
        ];
        foreach ($unsafe as [$triggered, $message]) {
            if ($triggered) {
                throw new RuntimeException($message);
            }
        }
        if (!in_array($values['retrieval']['minimum_status'], ['validated', 'promotion_eligible', 'promoted'], true)) {
            throw new RuntimeException('retrieval.minimum_status must be validated, promotion_eligible, or promoted');
        }
        foreach ([
            'analysis.request_timeout', 'privacy.raw_retention', 'scheduler.poll_interval',
            'scheduler.session_idle_after', 'scheduler.launchd_interval', 'scheduler.lease',
            'index.refresh_interval', 'editing.ticket_ttl', 'knowledge.codex.timeout',
        ] as $duration) {
            self::durationSeconds((string) self::nested($values, $duration));
        }
        foreach ([
            'collector.max_event_bytes' => [1_024, 67_108_864],
            'analysis.max_session_tokens' => [1_000, 1_000_000],
            'analysis.automatic_learning.max_candidates' => [1, 12],
            'analysis.automatic_learning.max_existing_experiences' => [1, 100],
            'analysis.automatic_learning.max_project_matches' => [1, 50],
            'retrieval.max_items' => [1, 20],
            'retrieval.token_budget' => [128, 12_000],
            'scheduler.max_attempts' => [1, 20],
            'index.max_file_bytes' => [16_384, 16_777_216],
            'index.max_chunk_chars' => [512, 64_000],
            'index.context_token_budget' => [128, 32_000],
            'index.vector_dimensions' => [128, 65_536],
            'index.vector_max_terms' => [8, 1_024],
            'index.sqlite_mmap_bytes' => [0, 2_147_418_112],
            'index.sqlite_cache_kib' => [1_024, 262_144],
            'editing.max_files' => [1, 200],
            'editing.max_file_bytes' => [1_024, 16_777_216],
            'editing.max_total_bytes' => [1_024, 67_108_864],
            'knowledge.codex.max_context_chars' => [1_024, 1_000_000],
            'knowledge.learning_skills.max_experiences' => [1, 100],
            'knowledge.learning_skills.max_skills' => [1, 24],
            'knowledge.learning_skills.max_module_skill_projections' => [0, 256],
            'knowledge.learning_skills.prompt_skill_limit' => [1, 5],
            'knowledge.learning_skills.prompt_token_budget' => [128, 4_000],
        ] as $path => [$minimum, $maximum]) {
            $value = self::nested($values, $path);
            if (!is_int($value) || $value < $minimum || $value > $maximum) {
                throw new RuntimeException(sprintf('%s must be an integer between %d and %d', $path, $minimum, $maximum));
            }
        }
        foreach ([
            'analysis.automatic_learning.duplicate_similarity' => [0.5, 1.0],
            'analysis.automatic_learning.related_similarity' => [0.1, 0.95],
            'analysis.automatic_learning.conflict_similarity' => [0.3, 1.0],
            'analysis.automatic_learning.project_duplicate_similarity' => [0.5, 1.0],
            'analysis.automatic_learning.minimum_validation_confidence' => [0.78, 1.0],
        ] as $path => [$minimum, $maximum]) {
            $value = self::nested($values, $path);
            if ((!is_int($value) && !is_float($value))
                || (float) $value < $minimum
                || (float) $value > $maximum) {
                throw new RuntimeException(sprintf('%s must be numeric between %.2f and %.2f', $path, $minimum, $maximum));
            }
        }
        if ((float) $values['analysis']['automatic_learning']['related_similarity']
            >= (float) $values['analysis']['automatic_learning']['duplicate_similarity']) {
            throw new RuntimeException('analysis.automatic_learning.related_similarity must be below duplicate_similarity');
        }
        if ((bool) $values['analysis']['automatic_learning']['auto_validate']
            && !(bool) $values['analysis']['automatic_learning']['enabled']) {
            throw new RuntimeException('analysis.automatic_learning.auto_validate requires automatic_learning.enabled');
        }
        if ($provider === 'codex' && !(bool) $values['knowledge']['codex']['enabled']) {
            throw new RuntimeException('analysis.provider=codex requires knowledge.codex.enabled');
        }
        $targets = $values['promotion']['allowed_targets'];
        if (!is_array($targets) || $targets === [] || !array_is_list($targets)) {
            throw new RuntimeException('promotion.allowed_targets must be a non-empty list');
        }
        foreach (['index.allowed_extensions', 'index.excluded_paths', 'editing.allowed_roots', 'editing.denied_paths'] as $listPath) {
            $items = self::nested($values, $listPath);
            if (!is_array($items) || !array_is_list($items) || $items === []) {
                throw new RuntimeException($listPath . ' must be a non-empty list');
            }
            foreach ($items as $item) {
                if (!is_string($item) || trim($item) === '') {
                    throw new RuntimeException($listPath . ' must contain non-empty strings');
                }
            }
        }
        if (!in_array($values['knowledge']['generated_skill_status'], ['draft', 'validated'], true)) {
            throw new RuntimeException('knowledge.generated_skill_status must be draft or validated');
        }
        if ((bool) $values['knowledge']['auto_doc_sync'] && !(bool) $values['knowledge']['codex']['enabled']) {
            throw new RuntimeException('knowledge.auto_doc_sync requires knowledge.codex.enabled');
        }
        $skillOutputDirectory = $values['knowledge']['learning_skills']['output_directory'];
        if (!is_string($skillOutputDirectory)
            || preg_match('/[\x00-\x1F\x7F]/', $skillOutputDirectory) === 1
            || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $skillOutputDirectory) === 1) {
            throw new RuntimeException('knowledge.learning_skills.output_directory must be a safe path without control characters or .. segments');
        }
        $minimumConfidence = $values['knowledge']['learning_skills']['minimum_confidence'];
        if (!is_int($minimumConfidence) && !is_float($minimumConfidence)) {
            throw new RuntimeException('knowledge.learning_skills.minimum_confidence must be numeric');
        }
        if ((float) $minimumConfidence < 0.78 || (float) $minimumConfidence > 1.0) {
            throw new RuntimeException('knowledge.learning_skills.minimum_confidence must be between 0.78 and 1.0');
        }
        if ((bool) $values['knowledge']['learning_skills']['enabled'] && !(bool) $values['knowledge']['codex']['enabled']) {
            throw new RuntimeException('knowledge.learning_skills.enabled requires knowledge.codex.enabled');
        }
    }

    /** @param array<string, mixed> $values */
    private static function nested(array $values, string $path): mixed
    {
        $value = $values;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }
}

final class SimpleYaml
{
    /** @return array<string, mixed> */
    public static function parse(string $body): array
    {
        $tokens = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $lineNumber => $line) {
            if (str_contains($line, "\t")) {
                throw new RuntimeException('YAML tabs are not supported at line ' . ($lineNumber + 1));
            }
            $line = self::stripComment(rtrim($line));
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^ */', $line, $matches);
            $indent = strlen($matches[0] ?? '');
            if ($indent % 2 !== 0) {
                throw new RuntimeException('YAML indentation must use two spaces at line ' . ($lineNumber + 1));
            }
            $tokens[] = ['indent' => $indent, 'text' => substr($line, $indent), 'line' => $lineNumber + 1];
        }
        if ($tokens === []) {
            return [];
        }
        $index = 0;
        $result = self::parseBlock($tokens, $index, (int) $tokens[0]['indent']);
        if (!is_array($result) || array_is_list($result)) {
            throw new RuntimeException('Config YAML root must be a mapping');
        }

        return $result;
    }

    /** @param list<array{indent:int,text:string,line:int}> $tokens */
    private static function parseBlock(array $tokens, int &$index, int $indent): array
    {
        $isList = str_starts_with($tokens[$index]['text'], '- ');
        $result = [];
        while (isset($tokens[$index]) && $tokens[$index]['indent'] === $indent) {
            $token = $tokens[$index];
            if ($isList) {
                if (!str_starts_with($token['text'], '- ')) {
                    throw new RuntimeException('Cannot mix YAML list and mapping at line ' . $token['line']);
                }
                $value = trim(substr($token['text'], 2));
                if ($value === '') {
                    throw new RuntimeException('Empty YAML list item at line ' . $token['line']);
                }
                $result[] = self::scalar($value);
                ++$index;
                continue;
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):(?:\s*(.*))?$/', $token['text'], $matches)) {
                throw new RuntimeException('Invalid YAML mapping at line ' . $token['line']);
            }
            $key = $matches[1];
            if (array_key_exists($key, $result)) {
                throw new RuntimeException('Duplicate YAML key ' . $key . ' at line ' . $token['line']);
            }
            $remainder = $matches[2] ?? '';
            ++$index;
            if ($remainder !== '') {
                $result[$key] = self::scalar($remainder);
                continue;
            }
            if (isset($tokens[$index]) && $tokens[$index]['indent'] > $indent) {
                if ($tokens[$index]['indent'] !== $indent + 2) {
                    throw new RuntimeException('Unexpected YAML indentation at line ' . $tokens[$index]['line']);
                }
                $result[$key] = self::parseBlock($tokens, $index, $indent + 2);
            } else {
                $result[$key] = [];
            }
        }

        return $result;
    }

    private static function stripComment(string $line): string
    {
        $single = false;
        $double = false;
        $length = strlen($line);
        for ($index = 0; $index < $length; ++$index) {
            $character = $line[$index];
            if ($character === "'" && !$double) {
                $single = !$single;
            } elseif ($character === '"' && !$single && ($index === 0 || $line[$index - 1] !== '\\')) {
                $double = !$double;
            } elseif ($character === '#' && !$single && !$double && ($index === 0 || ctype_space($line[$index - 1]))) {
                return rtrim(substr($line, 0, $index));
            }
        }

        return $line;
    }

    private static function scalar(string $value): mixed
    {
        $value = trim($value);
        if (preg_match('/^\$\{([A-Z_][A-Z0-9_]*)}$/', $value, $matches)) {
            return getenv($matches[1]) ?: '';
        }
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        }
        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null', '~' => null,
            '[]' => [],
            '{}' => [],
            default => preg_match('/^-?\d+$/', $value) === 1
                ? (int) $value
                : (preg_match('/^-?\d+\.\d+$/', $value) === 1 ? (float) $value : $value),
        };
    }
}
