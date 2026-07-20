<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;
use Throwable;

/**
 * Projects evidence-gated learning into marker-owned repository skills.
 * Codex only classifies IDs; this service renders the validated source facts,
 * owns every writable path, rolls back partial writes, and refreshes the index.
 */
final class LearningSkillService
{
    public const GENERATOR_VERSION = 'learning-skills.php.v4';

    private const SKILLS_ROOT = 'dev/ai/skills';
    private const INDEX_PATH = 'dev/ai/skills/_index.md';
    private const MANIFEST_PATH = 'dev/ai/skills/MCP-LEARNING-INDEX.json';
    private const CONFIGURED_INDEX_PATH = '_index.md';
    private const CONFIGURED_MANIFEST_PATH = 'MCP-LEARNING-INDEX.json';
    private const MODULE_INDEX_NAME = '_index.md';
    private const MODULE_MANIFEST_NAME = 'MCP-LEARNING-INDEX.json';
    private const SKILL_MARKER = '<!-- weline:mcp-learning-skill:auto-generated -->';
    private const MANIFEST_MARKER = 'weline:mcp-learning-skills:auto-generated';
    private const MODULE_INDEX_MARKER = '<!-- weline:mcp-learning-module-index:auto-generated -->';
    private const MODULE_MANIFEST_MARKER = 'weline:mcp-learning-module-skills:auto-generated';
    private const INDEX_START = '<!-- weline:mcp-learning-skills:index:start -->';
    private const INDEX_END = '<!-- weline:mcp-learning-skills:index:end -->';

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        private readonly ?CodexInvoker $codex = null,
    ) {
    }

    public static function projectionFingerprint(string $repository, ?Config $config = null): string
    {
        $repository = rtrim($repository, "/\\");
        if ($config instanceof Config) {
            $configuredRoot = self::configuredOutputDirectory($config, $repository);
            if ($configuredRoot !== null) {
                return self::configuredProjectionFingerprint($configuredRoot);
            }
        }
        $paths = [self::INDEX_PATH, self::MANIFEST_PATH];
        $manifestPath = $repository . '/' . self::MANIFEST_PATH;
        if (is_file($manifestPath) && !is_link($manifestPath)) {
            try {
                $manifest = Json::decode((string) file_get_contents($manifestPath), []);
                if (is_array($manifest) && ($manifest['marker'] ?? '') === self::MANIFEST_MARKER) {
                    $paths = array_merge($paths, self::manifestProjectionPaths($manifest));
                }
            } catch (Throwable) {
            }
        }
        $states = [];
        foreach (Text::uniqueStrings($paths) as $relativePath) {
            $path = $repository . '/' . $relativePath;
            if (is_link($path)) {
                $states[$relativePath] = ['state' => 'symlink'];
                continue;
            }
            $content = is_file($path) ? file_get_contents($path) : false;
            $states[$relativePath] = is_string($content)
                ? ['state' => 'file', 'hash' => Ids::hash($content), 'bytes' => strlen($content)]
                : ['state' => 'missing'];
        }
        ksort($states, SORT_STRING);

        return Ids::hash(Json::canonical($states));
    }

    public static function configuredOutputDirectory(Config $config, string $repository): ?string
    {
        $configured = trim((string) $config->get('knowledge.learning_skills.output_directory', ''));
        if ($configured === '') {
            return null;
        }
        $repositoryRoot = realpath(trim($repository));
        if (!is_string($repositoryRoot) || !is_dir($repositoryRoot)) {
            throw new RuntimeException('Unable to resolve repository for configured learning-skill output');
        }
        $homeRelative = $configured === '~'
            || str_starts_with($configured, '~/')
            || str_starts_with($configured, '~\\');
        $path = $homeRelative || self::isAbsolutePath($configured)
            ? Config::expandPath($configured)
            : $repositoryRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);
        $path = rtrim($path, "/\\");
        if ($path === ''
            || self::normalizedPath($path) === self::normalizedPath($repositoryRoot)) {
            throw new RuntimeException('Configured learning-skill output must be a dedicated directory, not the repository or filesystem root');
        }

        return $path;
    }

    public static function configuredRepositoryOutputPrefix(Config $config, string $repository): ?string
    {
        $root = self::configuredOutputDirectory($config, $repository);
        if ($root === null) {
            return null;
        }
        $repositoryRoot = realpath(trim($repository));
        if (!is_string($repositoryRoot)) {
            return null;
        }
        $repositoryPath = rtrim(self::normalizedPath($repositoryRoot), '/');
        $outputPath = rtrim(self::normalizedPath($root), '/');
        if (!str_starts_with($outputPath . '/', $repositoryPath . '/')) {
            return null;
        }
        $relative = ltrim(substr($outputPath, strlen($repositoryPath)), '/');

        return $relative === '' ? null : $relative;
    }

    public static function isGeneratedSkillPath(Config $config, string $repository, string $path): bool
    {
        $path = ltrim(self::normalizedPath($path), '/');
        if (self::isProjectSkillPath($path) || self::isModuleSkillPath($path)) {
            return true;
        }
        $prefix = self::configuredRepositoryOutputPrefix($config, $repository);
        if ($prefix === null) {
            return false;
        }

        return preg_match(
            '~^' . preg_quote($prefix, '~') . '/MCP学习-[^/]+/SKILL\.md$~uD',
            $path
        ) === 1;
    }

    private static function configuredProjectionFingerprint(string $root): string
    {
        $paths = [self::CONFIGURED_INDEX_PATH, self::CONFIGURED_MANIFEST_PATH];
        $manifestPath = $root . DIRECTORY_SEPARATOR . self::CONFIGURED_MANIFEST_PATH;
        if (is_file($manifestPath) && !is_link($manifestPath)) {
            try {
                $manifest = Json::decode((string) file_get_contents($manifestPath), []);
                if (is_array($manifest) && ($manifest['marker'] ?? '') === self::MANIFEST_MARKER) {
                    $paths = array_merge($paths, self::configuredManifestProjectionPaths($manifest));
                }
            } catch (Throwable) {
            }
        }
        $states = ['output_directory' => self::normalizedPath($root)];
        foreach (Text::uniqueStrings($paths) as $relativePath) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (is_link($path)) {
                $states[$relativePath] = ['state' => 'symlink'];
                continue;
            }
            $content = is_file($path) ? file_get_contents($path) : false;
            $states[$relativePath] = is_string($content)
                ? ['state' => 'file', 'hash' => Ids::hash($content), 'bytes' => strlen($content)]
                : ['state' => 'missing'];
        }
        ksort($states, SORT_STRING);

        return Ids::hash(Json::canonical($states));
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~D', $path) === 1;
    }

    private static function normalizedPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        return preg_replace('~/+~', '/', $path) ?? $path;
    }

    /** @param array<string, mixed> $job
     *  @return array<string, mixed>
     */
    public function syncJob(array $job): array
    {
        if ($this->config->get('knowledge.learning_skills.enabled', false) !== true) {
            return [
                'decision' => 'disabled',
                'closed_loop' => [
                    'status' => 'not_required',
                    'mode' => 'disabled',
                    'reason' => 'knowledge.learning_skills.enabled is false',
                ],
                'generator_version' => self::GENERATOR_VERSION,
            ];
        }
        $projectId = trim((string) ($job['project_id'] ?? ''));
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        if ($projectId === '') {
            throw new ToolException('VALIDATION_FAILED', 'Learning-skill sync job requires project_id');
        }
        $repository = $this->repository($projectId, (string) ($payload['repository'] ?? ''));

        return $this->withProjectLock($projectId, function () use ($projectId, $repository): array {
            return $this->syncProject($projectId, $repository);
        });
    }

    /** @return array<string, mixed> */
    private function syncProject(string $projectId, string $repository): array
    {
        $configuredRoot = self::configuredOutputDirectory($this->config, $repository);
        if ($configuredRoot !== null) {
            return $this->syncConfiguredProject($projectId, $repository, $configuredRoot);
        }

        $experiences = $this->actionableExperiences($projectId);
        $moduleProjectionLimit = max(
            0,
            min(256, (int) $this->config->get('knowledge.learning_skills.max_module_skill_projections', 64))
        );
        $sourceDigest = Ids::hash(Json::canonical([
            'generator_version' => self::GENERATOR_VERSION,
            'project_id' => $projectId,
            'minimum_confidence' => (float) $this->config->get('knowledge.learning_skills.minimum_confidence', 0.9),
            'maximum_experiences' => (int) $this->config->get('knowledge.learning_skills.max_experiences', 100),
            'maximum_skills' => (int) $this->config->get('knowledge.learning_skills.max_skills', 12),
            'maximum_module_skill_projections' => $moduleProjectionLimit,
            'experiences' => $experiences,
        ]));
        $manifest = $this->readManifest($repository);
        if ($experiences === [] && $manifest === null) {
            return [
                'decision' => 'no_actionable_learning',
                'project_id' => $projectId,
                'repository' => $repository,
                'source_digest' => $sourceDigest,
                'experience_count' => 0,
                'skill_count' => 0,
                'module_skill_count' => 0,
                'module_count' => 0,
                'closed_loop' => $this->directLearningClosure($projectId),
                'generator_version' => self::GENERATOR_VERSION,
            ];
        }

        $manifestMatchesSource = $manifest !== null
            && ($manifest['generator_version'] ?? '') === self::GENERATOR_VERSION
            && ($manifest['source_digest'] ?? '') === $sourceDigest;
        $manifestFilesCurrent = $manifestMatchesSource && $this->manifestFilesAreCurrent($repository, $manifest);
        $manifestIndexesCurrent = $manifestFilesCurrent && $this->manifestIndexesAreCurrent($repository, $manifest);
        if ($manifestIndexesCurrent) {
            $paths = self::manifestProjectionPaths($manifest);
            $index = $this->refreshIndex($repository, array_merge($paths, [self::INDEX_PATH, self::MANIFEST_PATH]));
            $skills = is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [];
            $moduleGroups = $this->moduleGroups($skills);

            return [
                'decision' => 'already_current',
                'project_id' => $projectId,
                'repository' => $repository,
                'source_digest' => $sourceDigest,
                'experience_count' => count($experiences),
                'skill_count' => count($skills),
                'module_skill_count' => count(array_filter(
                    $skills,
                    static fn(array $skill): bool => ($skill['projection_scope'] ?? 'project') === 'module'
                )),
                'module_count' => count($moduleGroups),
                'index' => $index,
                'closed_loop' => $index['closure'],
                'generator_version' => self::GENERATOR_VERSION,
            ];
        }
        if ($manifestFilesCurrent) {
            $changedPaths = $this->repairIndexes($repository, $manifest);
            $paths = self::manifestProjectionPaths($manifest);
            $index = $this->refreshIndex(
                $repository,
                array_merge($paths, [self::INDEX_PATH, self::MANIFEST_PATH]),
                $changedPaths,
            );
            $skills = is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [];
            $moduleGroups = $this->moduleGroups($skills);

            return [
                'decision' => 'indexes_repaired',
                'project_id' => $projectId,
                'repository' => $repository,
                'source_digest' => $sourceDigest,
                'experience_count' => count($experiences),
                'skill_count' => count($skills),
                'module_skill_count' => count(array_filter(
                    $skills,
                    static fn(array $skill): bool => ($skill['projection_scope'] ?? 'project') === 'module'
                )),
                'module_count' => count($moduleGroups),
                'changed_paths' => $changedPaths,
                'index' => $index,
                'closed_loop' => $index['closure'],
                'generator_version' => self::GENERATOR_VERSION,
            ];
        }

        $plan = ['schema_version' => 'learning-skills.v1', 'skills' => [], 'metadata' => ['planner' => 'none']];
        if ($experiences !== []) {
            $allowedIds = array_column($experiences, 'experience_id');
            $plan = $this->invoker()->classifyLearningSkills([
                'repository_root' => $repository,
                'project_id' => $projectId,
                'generator_version' => self::GENERATOR_VERSION,
                'max_skills' => (int) $this->config->get('knowledge.learning_skills.max_skills', 12),
                'allowed_experience_ids' => $allowedIds,
                'experiences' => $experiences,
            ]);
        }

        $experienceById = [];
        foreach ($experiences as $experience) {
            $experienceById[(string) $experience['experience_id']] = $experience;
        }
        $classifications = is_array($plan['skills'] ?? null) ? $plan['skills'] : [];
        usort($classifications, static fn(array $left, array $right): int => strcmp(
            (string) ($left['name'] ?? ''),
            (string) ($right['name'] ?? '')
        ));

        $skills = [];
        $moduleProjectionCount = 0;
        $moduleProjectionCandidates = 0;
        foreach ($classifications as $classification) {
            $skillExperiences = [];
            foreach ($classification['experience_ids'] as $experienceId) {
                if (!isset($experienceById[$experienceId])) {
                    throw new ToolException('CODEX_OUTPUT_INVALID', 'Learning-skill classification references an unavailable experience');
                }
                $skillExperiences[] = $experienceById[$experienceId];
            }
            $name = (string) $classification['name'];
            $relativePath = self::SKILLS_ROOT . '/' . $name . '/SKILL.md';
            $moduleScopes = $this->moduleExperiences($repository, $skillExperiences);
            $moduleProjectionCandidates += count($moduleScopes);
            $selectedModuleScopes = [];
            foreach ($moduleScopes as $moduleCode => $moduleScope) {
                if ($moduleProjectionCount >= $moduleProjectionLimit) {
                    break;
                }
                $selectedModuleScopes[$moduleCode] = $moduleScope;
                $moduleProjectionCount++;
            }
            $moduleRoutes = array_values(array_map(
                static fn(array $scope): string => (string) $scope['route'],
                $selectedModuleScopes
            ));
            $content = $this->renderSkill($classification, $skillExperiences);
            $skills[] = [
                'key' => (string) $classification['key'],
                'name' => $name,
                'description' => (string) $classification['description'],
                'triggers' => Text::uniqueStrings($classification['triggers'], false),
                'knowledge_types' => Text::uniqueStrings($classification['knowledge_types'], false),
                'surfaces' => Text::uniqueStrings($classification['surfaces'], false),
                'projection_scope' => 'project',
                'module' => '',
                'module_routes' => $moduleRoutes,
                'relative_path' => $relativePath,
                'experience_ids' => Text::uniqueStrings($classification['experience_ids']),
                'file_hash' => Ids::hash($content),
                'content' => $content,
            ];

            foreach ($selectedModuleScopes as $moduleScope) {
                $localPath = $moduleScope['root'] . '/doc/ai/skills/' . $name . '/SKILL.md';
                $localContent = $this->renderSkill($classification, $moduleScope['experiences'], $moduleScope);
                $skills[] = [
                    'key' => (string) $classification['key'],
                    'name' => $name,
                    'description' => (string) $classification['description'],
                    'triggers' => Text::uniqueStrings($classification['triggers'], false),
                    'knowledge_types' => Text::uniqueStrings(
                        array_column($moduleScope['experiences'], 'knowledge_type'),
                        false,
                    ),
                    'surfaces' => Text::uniqueStrings(
                        array_column($moduleScope['experiences'], 'surface'),
                        false,
                    ),
                    'projection_scope' => 'module',
                    'module' => $moduleScope['code'],
                    'module_route' => $moduleScope['route'],
                    'vendor' => $moduleScope['vendor'],
                    'module_name' => $moduleScope['name'],
                    'relative_path' => $localPath,
                    'source_skill_path' => $relativePath,
                    'experience_ids' => array_column($moduleScope['experiences'], 'experience_id'),
                    'file_hash' => Ids::hash($localContent),
                    'content' => $localContent,
                ];
            }
        }
        usort($skills, static fn(array $left, array $right): int => strcmp(
            (string) $left['relative_path'],
            (string) $right['relative_path']
        ));

        $manifestBody = $this->renderManifest($projectId, $sourceDigest, $skills, $plan['metadata'] ?? []);
        $changedPaths = $this->writeProjection($repository, $manifest, $skills, $manifestBody);
        $indexPaths = array_merge(
            self::manifestProjectionPaths(['skills' => $skills]),
            self::manifestProjectionPaths($manifest),
            [self::INDEX_PATH, self::MANIFEST_PATH],
        );
        $index = $this->refreshIndex($repository, $indexPaths, $changedPaths);
        $moduleGroups = $this->moduleGroups($skills);

        return [
            'decision' => $experiences === [] ? 'cleared' : 'synchronized',
            'project_id' => $projectId,
            'repository' => $repository,
            'source_digest' => $sourceDigest,
            'experience_count' => count($experiences),
            'skill_count' => count($skills),
            'project_skill_count' => count($skills) - $moduleProjectionCount,
            'module_skill_count' => $moduleProjectionCount,
            'module_count' => count($moduleGroups),
            'module_projection_limit' => $moduleProjectionLimit,
            'module_projection_truncated' => $moduleProjectionCandidates > $moduleProjectionCount,
            'changed_paths' => $changedPaths,
            'index' => $index,
            'closed_loop' => $index['closure'],
            'planner' => $plan['metadata'] ?? [],
            'generator_version' => self::GENERATOR_VERSION,
        ];
    }


    /** @return array<string, mixed> */
    private function syncConfiguredProject(string $projectId, string $repository, string $root): array
    {
        $experiences = $this->actionableExperiences($projectId);
        $sourceDigest = Ids::hash(Json::canonical([
            'generator_version' => self::GENERATOR_VERSION,
            'project_id' => $projectId,
            'projection_mode' => 'configured_directory',
            'output_directory' => self::normalizedPath($root),
            'minimum_confidence' => (float) $this->config->get('knowledge.learning_skills.minimum_confidence', 0.9),
            'maximum_experiences' => (int) $this->config->get('knowledge.learning_skills.max_experiences', 100),
            'maximum_skills' => (int) $this->config->get('knowledge.learning_skills.max_skills', 12),
            'experiences' => $experiences,
        ]));
        $manifest = $this->readConfiguredManifest($root, $projectId);
        if ($experiences === [] && $manifest === null) {
            return [
                'decision' => 'no_actionable_learning',
                'project_id' => $projectId,
                'repository' => $repository,
                'output_directory' => $root,
                'projection_mode' => 'configured_directory',
                'source_digest' => $sourceDigest,
                'experience_count' => 0,
                'skill_count' => 0,
                'module_skill_count' => 0,
                'generator_version' => self::GENERATOR_VERSION,
            ];
        }

        $manifestMatchesSource = $manifest !== null
            && ($manifest['generator_version'] ?? '') === self::GENERATOR_VERSION
            && ($manifest['source_digest'] ?? '') === $sourceDigest;
        $manifestFilesCurrent = $manifestMatchesSource
            && $this->configuredManifestFilesAreCurrent($root, $manifest);
        if ($manifestFilesCurrent) {
            $skills = is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [];
            $paths = array_merge(
                self::configuredManifestProjectionPaths($manifest),
                [self::CONFIGURED_INDEX_PATH, self::CONFIGURED_MANIFEST_PATH],
            );
            $changedPaths = [];
            $decision = 'already_current';
            if (!$this->configuredIndexIsCurrent($root, $skills)) {
                $this->ensureConfiguredRoot($root);
                $indexPath = $this->configuredProjectionPath($root, self::CONFIGURED_INDEX_PATH);
                $indexContent = is_file($indexPath)
                    ? file_get_contents($indexPath)
                    : "# Project Intelligence MCP Skills\n";
                if (!is_string($indexContent)) {
                    throw new ToolException('SKILL_INDEX_MISSING', 'Configured learning-skill index is unreadable');
                }
                $this->atomicWriteConfigured(
                    $root,
                    self::CONFIGURED_INDEX_PATH,
                    $this->renderConfiguredIndex($indexContent, $skills),
                );
                $changedPaths[] = self::CONFIGURED_INDEX_PATH;
                $decision = 'indexes_repaired';
            }
            $index = $this->refreshConfiguredOutput($projectId, $repository, $root, $paths);

            return [
                'decision' => $decision,
                'project_id' => $projectId,
                'repository' => $repository,
                'output_directory' => $root,
                'projection_mode' => 'configured_directory',
                'source_digest' => $sourceDigest,
                'experience_count' => count($experiences),
                'skill_count' => count($skills),
                'project_skill_count' => count($skills),
                'module_skill_count' => 0,
                'changed_paths' => $changedPaths,
                'index' => $index,
                'closed_loop' => $index['closure'],
                'generator_version' => self::GENERATOR_VERSION,
            ];
        }

        $plan = ['schema_version' => 'learning-skills.v1', 'skills' => [], 'metadata' => ['planner' => 'none']];
        if ($experiences !== []) {
            $plan = $this->invoker()->classifyLearningSkills([
                'repository_root' => $repository,
                'project_id' => $projectId,
                'generator_version' => self::GENERATOR_VERSION,
                'max_skills' => (int) $this->config->get('knowledge.learning_skills.max_skills', 12),
                'allowed_experience_ids' => array_column($experiences, 'experience_id'),
                'experiences' => $experiences,
            ]);
        }

        $experienceById = [];
        foreach ($experiences as $experience) {
            $experienceById[(string) $experience['experience_id']] = $experience;
        }
        $classifications = is_array($plan['skills'] ?? null) ? $plan['skills'] : [];
        usort($classifications, static fn(array $left, array $right): int => strcmp(
            (string) ($left['name'] ?? ''),
            (string) ($right['name'] ?? '')
        ));

        $skills = [];
        foreach ($classifications as $classification) {
            $skillExperiences = [];
            foreach ($classification['experience_ids'] as $experienceId) {
                if (!isset($experienceById[$experienceId])) {
                    throw new ToolException('CODEX_OUTPUT_INVALID', 'Learning-skill classification references an unavailable experience');
                }
                $skillExperiences[] = $experienceById[$experienceId];
            }
            $name = (string) $classification['name'];
            $relativePath = $name . '/SKILL.md';
            $content = $this->renderSkill($classification, $skillExperiences);
            $skills[] = [
                'key' => (string) $classification['key'],
                'name' => $name,
                'description' => (string) $classification['description'],
                'triggers' => Text::uniqueStrings($classification['triggers'], false),
                'knowledge_types' => Text::uniqueStrings($classification['knowledge_types'], false),
                'surfaces' => Text::uniqueStrings($classification['surfaces'], false),
                'projection_scope' => 'configured',
                'module' => '',
                'relative_path' => $relativePath,
                'experience_ids' => Text::uniqueStrings($classification['experience_ids']),
                'file_hash' => Ids::hash($content),
                'content' => $content,
            ];
        }
        usort($skills, static fn(array $left, array $right): int => strcmp(
            (string) $left['relative_path'],
            (string) $right['relative_path']
        ));

        $manifestBody = $this->renderConfiguredManifest(
            $projectId,
            $sourceDigest,
            $root,
            $skills,
            is_array($plan['metadata'] ?? null) ? $plan['metadata'] : [],
        );
        $changedPaths = $this->writeConfiguredProjection($root, $manifest, $skills, $manifestBody);
        $paths = array_merge(
            self::configuredManifestProjectionPaths(['skills' => $skills]),
            self::configuredManifestProjectionPaths($manifest),
            [self::CONFIGURED_INDEX_PATH, self::CONFIGURED_MANIFEST_PATH],
        );
        $index = $this->refreshConfiguredOutput($projectId, $repository, $root, $paths);

        return [
            'decision' => $experiences === [] ? 'cleared' : 'synchronized',
            'project_id' => $projectId,
            'repository' => $repository,
            'output_directory' => $root,
            'projection_mode' => 'configured_directory',
            'source_digest' => $sourceDigest,
            'experience_count' => count($experiences),
            'skill_count' => count($skills),
            'project_skill_count' => count($skills),
            'module_skill_count' => 0,
            'changed_paths' => $changedPaths,
            'index' => $index,
            'closed_loop' => $index['closure'],
            'planner' => $plan['metadata'] ?? [],
            'generator_version' => self::GENERATOR_VERSION,
        ];
    }

    /** @param list<array<string, mixed>> $skills
     *  @param array<string, mixed> $plannerMetadata
     */
    private function renderConfiguredManifest(
        string $projectId,
        string $sourceDigest,
        string $root,
        array $skills,
        array $plannerMetadata,
    ): string {
        $manifestSkills = [];
        foreach ($skills as $skill) {
            $item = $skill;
            unset($item['content']);
            $manifestSkills[] = $item;
        }

        return Json::encode([
            'schema_version' => 'learning-skill-manifest.v3',
            'marker' => self::MANIFEST_MARKER,
            'generator_version' => self::GENERATOR_VERSION,
            'project_id' => $projectId,
            'projection_mode' => 'configured_directory',
            'output_directory' => self::normalizedPath($root),
            'source_digest' => $sourceDigest,
            'generated_at' => Clock::now(),
            'planner' => [
                'name' => (string) ($plannerMetadata['planner'] ?? 'none'),
                'purpose' => (string) ($plannerMetadata['purpose'] ?? ''),
                'mode' => (string) ($plannerMetadata['mode'] ?? ''),
            ],
            'skills' => $manifestSkills,
        ], true) . "\n";
    }

    /** @param list<array<string, mixed>> $skills */
    private function renderConfiguredIndex(string $content, array $skills): string
    {
        $startCount = substr_count($content, self::INDEX_START);
        $endCount = substr_count($content, self::INDEX_END);
        if ($startCount !== $endCount || $startCount > 1) {
            throw new ToolException('SKILL_INDEX_MARKER_INVALID', 'Configured learning-skill index markers are unbalanced');
        }
        $pattern = '~\n?' . preg_quote(self::INDEX_START, '~') . '.*?' . preg_quote(self::INDEX_END, '~') . '\n?~s';
        $content = preg_replace($pattern, "\n", $content) ?? $content;
        if ($skills === []) {
            return rtrim($content) . "\n";
        }
        $lines = [
            self::INDEX_START,
            '## MCP 自动学习技能',
            '',
            '以下技能由 Project Intelligence MCP 从当前项目的已验证经验生成，并输出到配置目录。',
            '',
            '| 技能 | 相对配置目录路径 | 触发摘要 |',
            '|---|---|---|',
        ];
        foreach ($skills as $skill) {
            $description = str_replace('|', '\\|', self::inline((string) $skill['description'], 220));
            $lines[] = sprintf('| %s | %s | %s |', $skill['name'], $skill['relative_path'], $description);
        }
        $lines[] = self::INDEX_END;

        return rtrim($content) . "\n\n" . implode("\n", $lines) . "\n";
    }

    /** @return array<string, mixed>|null */
    private function readConfiguredManifest(string $root, string $projectId): ?array
    {
        $path = $this->configuredProjectionPath($root, self::CONFIGURED_MANIFEST_PATH);
        if (!is_file($path)) {
            return null;
        }
        $body = file_get_contents($path);
        $manifest = is_string($body) ? Json::decode($body) : null;
        if (!is_array($manifest) || ($manifest['marker'] ?? '') !== self::MANIFEST_MARKER) {
            throw new ToolException('SKILL_OWNERSHIP_CONFLICT', 'Configured learning-skill manifest is not marker-owned');
        }
        if (($manifest['project_id'] ?? '') !== $projectId) {
            throw new ToolException(
                'SKILL_OUTPUT_PROJECT_CONFLICT',
                'Configured learning-skill output directory is already owned by another project',
                false,
                ['output_directory' => $root],
            );
        }

        return $manifest;
    }

    /** @param array<string, mixed>|null $manifest
     *  @return list<string>
     */
    private static function configuredManifestProjectionPaths(?array $manifest): array
    {
        if (!is_array($manifest)) {
            return [];
        }
        $paths = [];
        foreach (is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [] as $skill) {
            if (!is_array($skill) || !self::configuredSkillRecordIsValid($skill)) {
                continue;
            }
            $paths[] = (string) $skill['relative_path'];
        }

        return Text::uniqueStrings($paths);
    }

    /** @param array<string, mixed> $skill */
    private static function configuredSkillRecordIsValid(array $skill): bool
    {
        return ($skill['projection_scope'] ?? '') === 'configured'
            && preg_match(
                '~^MCP学习-[^/]+/SKILL\.md$~uD',
                trim((string) ($skill['relative_path'] ?? ''))
            ) === 1;
    }

    /** @param array<string, mixed> $manifest */
    private function configuredManifestFilesAreCurrent(string $root, array $manifest): bool
    {
        foreach (is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [] as $skill) {
            if (!is_array($skill) || !self::configuredSkillRecordIsValid($skill)) {
                return false;
            }
            $relativePath = (string) $skill['relative_path'];
            $path = $this->configuredProjectionPath($root, $relativePath);
            $content = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($content)
                || !str_contains($content, self::SKILL_MARKER)
                || !hash_equals((string) ($skill['file_hash'] ?? ''), Ids::hash($content))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $skills */
    private function configuredIndexIsCurrent(string $root, array $skills): bool
    {
        $path = $this->configuredProjectionPath($root, self::CONFIGURED_INDEX_PATH);
        $content = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($content)) {
            return false;
        }

        return hash_equals(Ids::hash($content), Ids::hash($this->renderConfiguredIndex($content, $skills)));
    }

    /** @param array<string, mixed>|null $previousManifest
     *  @param list<array<string, mixed>> $skills
     *  @return list<string>
     */
    private function writeConfiguredProjection(
        string $root,
        ?array $previousManifest,
        array $skills,
        string $manifestBody,
    ): array {
        $this->ensureConfiguredRoot($root);
        $writes = [];
        foreach ($skills as $skill) {
            $writes[(string) $skill['relative_path']] = (string) $skill['content'];
        }
        $indexPath = $this->configuredProjectionPath($root, self::CONFIGURED_INDEX_PATH);
        $indexContent = is_file($indexPath)
            ? file_get_contents($indexPath)
            : "# Project Intelligence MCP Skills\n";
        if (!is_string($indexContent)) {
            throw new ToolException('SKILL_INDEX_MISSING', 'Configured learning-skill index is unreadable');
        }
        $writes[self::CONFIGURED_INDEX_PATH] = $this->renderConfiguredIndex($indexContent, $skills);
        $writes[self::CONFIGURED_MANIFEST_PATH] = $manifestBody;

        $previousPaths = self::configuredManifestProjectionPaths($previousManifest);
        $knownPaths = array_fill_keys($previousPaths, true);
        foreach (array_keys($writes) as $relativePath) {
            if ($relativePath === self::CONFIGURED_INDEX_PATH
                || $relativePath === self::CONFIGURED_MANIFEST_PATH) {
                continue;
            }
            $this->assertConfiguredSkillTarget($root, $relativePath, isset($knownPaths[$relativePath]));
        }
        $nextPaths = self::configuredManifestProjectionPaths(['skills' => $skills]);
        $stale = array_values(array_diff($previousPaths, $nextPaths));
        foreach ($stale as $relativePath) {
            $this->assertConfiguredSkillTarget($root, $relativePath, true);
        }

        $affected = Text::uniqueStrings(array_merge(array_keys($writes), $stale));
        $before = [];
        foreach ($affected as $relativePath) {
            $absolutePath = $this->configuredProjectionPath($root, $relativePath);
            $before[$relativePath] = is_file($absolutePath) ? file_get_contents($absolutePath) : null;
            if ($before[$relativePath] === false) {
                throw new ToolException('SKILL_WRITE_FAILED', 'Unable to snapshot configured learning-skill target', true, ['path' => $relativePath]);
            }
        }

        try {
            foreach ($writes as $relativePath => $content) {
                $this->atomicWriteConfigured($root, $relativePath, $content);
            }
            foreach ($stale as $relativePath) {
                $absolutePath = $this->configuredProjectionPath($root, $relativePath);
                if (is_file($absolutePath) && !unlink($absolutePath)) {
                    throw new RuntimeException('Unable to remove stale configured skill: ' . $relativePath);
                }
                @rmdir(dirname($absolutePath));
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($affected) as $relativePath) {
                try {
                    $snapshot = $before[$relativePath];
                    if (is_string($snapshot)) {
                        $this->atomicWriteConfigured($root, $relativePath, $snapshot);
                    } else {
                        $absolutePath = $this->configuredProjectionPath($root, $relativePath);
                        if (is_file($absolutePath)) {
                            @unlink($absolutePath);
                        }
                        if (str_ends_with($relativePath, '/SKILL.md')) {
                            @rmdir(dirname($absolutePath));
                        }
                    }
                } catch (Throwable) {
                }
            }
            throw new ToolException('SKILL_WRITE_FAILED', 'Configured learning-skill transaction was rolled back', true, [
                'reason' => Text::truncate(Redactor::string($exception->getMessage())[0], 500),
            ]);
        }

        $changed = [];
        foreach ($affected as $relativePath) {
            $afterPath = $this->configuredProjectionPath($root, $relativePath);
            $after = is_file($afterPath) ? file_get_contents($afterPath) : null;
            if ($before[$relativePath] !== $after) {
                $changed[] = $relativePath;
            }
        }

        return $changed;
    }

    private function assertConfiguredSkillTarget(string $root, string $relativePath, bool $ownedByManifest): void
    {
        $path = $this->configuredProjectionPath($root, $relativePath);
        if (is_link($path)) {
            throw new ToolException('SKILL_OWNERSHIP_CONFLICT', 'Refusing a symbolic-link configured skill target', false, ['path' => $relativePath]);
        }
        if (!is_file($path)) {
            return;
        }
        $content = file_get_contents($path);
        if (!$ownedByManifest || !is_string($content) || !str_contains($content, self::SKILL_MARKER)) {
            throw new ToolException('SKILL_OWNERSHIP_CONFLICT', 'Refusing to overwrite a configured skill not owned by this project manifest', false, ['path' => $relativePath]);
        }
    }

    private function configuredProjectionPath(string $root, string $relativePath): string
    {
        if ($relativePath !== self::CONFIGURED_INDEX_PATH
            && $relativePath !== self::CONFIGURED_MANIFEST_PATH
            && preg_match('~^MCP学习-[^/]+/SKILL\.md$~uD', $relativePath) !== 1) {
            throw new ToolException('PATH_DENIED', 'Configured learning-skill projection path is invalid', false, ['path' => $relativePath]);
        }

        return rtrim($root, "/\\")
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function ensureConfiguredRoot(string $root): void
    {
        if (is_link($root)) {
            throw new ToolException('SKILL_ROOT_INVALID', 'Configured learning-skill output cannot be a symbolic link');
        }
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new ToolException('SKILL_ROOT_MISSING', 'Unable to create configured learning-skill output directory', true);
        }
        $resolved = realpath($root);
        $resolvedParent = realpath(dirname($root));
        $expected = is_string($resolvedParent)
            ? rtrim($resolvedParent, "/\\") . DIRECTORY_SEPARATOR . basename($root)
            : $root;
        $matches = is_string($resolved)
            && (DIRECTORY_SEPARATOR === '\\'
                ? strcasecmp(self::normalizedPath($resolved), self::normalizedPath($expected)) === 0
                : self::normalizedPath($resolved) === self::normalizedPath($expected));
        if (!$matches || is_link($root)) {
            throw new ToolException('SKILL_ROOT_INVALID', 'Configured learning-skill output directory is unavailable or unsafe');
        }
    }

    private function atomicWriteConfigured(string $root, string $relativePath, string $content): void
    {
        $this->ensureConfiguredRoot($root);
        $path = $this->configuredProjectionPath($root, $relativePath);
        $directory = dirname($path);
        if (is_link($directory) || is_link($path)) {
            throw new RuntimeException('Configured learning-skill path cannot contain a symbolic-link target');
        }
        if (!is_dir($directory) && !mkdir($directory, 0755, false) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create configured learning-skill directory');
        }
        $resolvedDirectory = realpath($directory);
        $resolvedRoot = realpath($root);
        $normalizedRoot = is_string($resolvedRoot)
            ? rtrim(self::normalizedPath($resolvedRoot), '/') . '/'
            : '';
        if (!is_string($resolvedDirectory)
            || $normalizedRoot === ''
            || !str_starts_with(self::normalizedPath($resolvedDirectory) . '/', $normalizedRoot)) {
            throw new RuntimeException('Configured learning-skill directory escaped its output root');
        }

        $temporary = tempnam($directory, '.project-intelligence-skill-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Unable to allocate configured learning-skill temporary file');
        }
        try {
            if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('Unable to write complete configured learning-skill content');
            }
            @chmod($temporary, 0644);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically replace configured learning-skill target');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @param list<string> $paths
     *  @return array<string, mixed>
     */
    private function refreshConfiguredOutput(
        string $projectId,
        string $repository,
        string $root,
        array $paths,
    ): array {
        $paths = Text::uniqueStrings($paths);
        $prefix = self::configuredRepositoryOutputPrefix($this->config, $repository);
        if ($prefix === null) {
            $closure = array_replace($this->directLearningClosure($projectId), [
                'mode' => 'configured_external_skill_output',
                'output_directory' => $root,
                'host_skill_discovery_required' => true,
            ]);

            return [
                'refreshed' => false,
                'paths' => $paths,
                'output_directory' => $root,
                'closure' => $closure,
            ];
        }

        $repositoryPaths = [];
        $expectedHashes = [];
        $missingPaths = [];
        $skillPaths = [];
        foreach ($paths as $relativePath) {
            $repositoryPath = $prefix . '/' . $relativePath;
            $repositoryPaths[] = $repositoryPath;
            $absolutePath = $this->configuredProjectionPath($root, $relativePath);
            if (!is_file($absolutePath)) {
                $missingPaths[] = $repositoryPath;
                continue;
            }
            $content = file_get_contents($absolutePath);
            if (!is_string($content)) {
                throw new ToolException('LEARNING_INDEX_CLOSURE_FAILED', 'Unable to hash configured skill output before indexing', true);
            }
            $expectedHashes[$repositoryPath] = Ids::hash($content);
            if (str_ends_with($relativePath, '/SKILL.md')) {
                $skillPaths[] = $repositoryPath;
            }
        }
        ksort($expectedHashes, SORT_STRING);

        $intelligence = new IntelligenceService($this->store, $this->config);
        $before = $intelligence->call('project_index_status', ['repository' => $repository]);
        $result = $intelligence->call('index_project', [
            'repository' => $repository,
            'mode' => 'incremental',
            'paths' => $repositoryPaths,
        ]);
        $revision = (int) ($result['index']['revision'] ?? 0);
        $closure = $intelligence->call('verify_learning_projection', [
            'repository' => $repository,
            'expected_revision' => $revision,
            'expected_hashes' => $expectedHashes,
            'skill_paths' => $skillPaths,
            'missing_paths' => $missingPaths,
        ]);
        $closure = array_replace($closure, [
            'mode' => 'configured_project_index_projection',
            'output_directory' => $root,
            'revision_before' => (int) ($before['index']['revision'] ?? 0),
            'learning_source' => 'learning.sqlite:experiences',
            'query_path' => 'get_edit_bundle.skills+validated_learning',
        ]);

        return [
            'refreshed' => true,
            'revision' => $revision,
            'freshness' => (string) ($result['index']['freshness'] ?? 'unknown'),
            'paths' => $repositoryPaths,
            'output_directory' => $root,
            'closure' => $closure,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function actionableExperiences(string $projectId): array
    {
        $maximum = max(1, min(100, (int) $this->config->get('knowledge.learning_skills.max_experiences', 100)));
        $minimumConfidence = max(0.78, min(1.0, (float) $this->config->get('knowledge.learning_skills.minimum_confidence', 0.9)));
        $result = $this->store->searchExperiences(
            $projectId,
            '',
            [],
            ['validated', 'promotion_eligible', 'promoted'],
            [],
            $maximum,
            0,
        );
        $trusted = [];
        foreach ($result['experiences'] as $experience) {
            if ((float) ($experience['confidence'] ?? 0.0) < $minimumConfidence) {
                continue;
            }
            $validUntil = trim((string) ($experience['valid_until'] ?? ''));
            if ($validUntil !== '' && $validUntil <= Clock::now()) {
                continue;
            }
            $details = $this->store->explainExperience((string) $experience['experience_id']);
            $blocked = false;
            foreach ($details['contradictions'] as $contradiction) {
                if (in_array($contradiction['status'] ?? '', ['open', 'contested'], true)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked || $details['evidence'] === []) {
                continue;
            }
            $item = $this->compactExperience($experience);
            if (!in_array((string) $item['knowledge_type'], [
                'skill_knowledge', 'operational_observation',
            ], true)) {
                $this->store->writeAudit('learning-skill-service', 'skip_non_skill_learning', 'experience', (string) $experience['experience_id'], [
                    'knowledge_type' => (string) $item['knowledge_type'],
                ]);
                continue;
            }
            if (($item['examples_complete'] ?? false) !== true) {
                $this->store->writeAudit('learning-skill-service', 'reject_learning_skill_source', 'experience', (string) $experience['experience_id'], [
                    'reason' => 'positive and negative examples are both required',
                ]);
                continue;
            }
            if (($item['knowledge_type'] ?? '') === 'operational_observation'
                && ($item['surface'] === '' || $item['environment_constraints'] === [])) {
                $this->store->writeAudit('learning-skill-service', 'reject_learning_skill_source', 'experience', (string) $experience['experience_id'], [
                    'reason' => 'operational observation is missing surface or environment constraints',
                ]);
                continue;
            }
            if (Redactor::looksLikeInjection(Json::encode($item))) {
                $this->store->writeAudit('learning-skill-service', 'reject_learning_skill_source', 'experience', (string) $experience['experience_id'], [
                    'reason' => 'prompt injection pattern in validated source',
                ]);
                continue;
            }
            $trusted[] = $item;
        }
        usort($trusted, static fn(array $left, array $right): int => strcmp($left['experience_id'], $right['experience_id']));

        return $trusted;
    }

    /** @param array<string, mixed> $experience
     *  @return array<string, mixed>
     */
    private function compactExperience(array $experience): array
    {
        $wrong = [];
        foreach (is_array($experience['wrong_approaches'] ?? null) ? $experience['wrong_approaches'] : [] as $approach) {
            if (!is_array($approach)) {
                continue;
            }
            $text = self::inline((string) ($approach['approach'] ?? ''), 600);
            if ($text !== '') {
                $wrong[] = $text;
            }
        }
        $verification = [];
        foreach (is_array($experience['verification'] ?? null) ? $experience['verification'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $evidenceId = self::inline((string) ($item['evidence_id'] ?? ''), 160);
            $result = self::inline((string) ($item['result'] ?? ''), 80);
            if ($evidenceId !== '') {
                $verification[] = trim($evidenceId . ($result !== '' ? ': ' . $result : ''));
            }
        }
        $scope = is_array($experience['scope'] ?? null) ? $experience['scope'] : [];
        $metadata = is_array($experience['metadata'] ?? null) ? $experience['metadata'] : [];
        $classification = is_array($metadata['learning_classification'] ?? null)
            ? $metadata['learning_classification']
            : [];
        $knowledgeType = trim((string) ($classification['knowledge_type'] ?? ''));
        if (!in_array($knowledgeType, [
            'global_rule', 'project_rule', 'skill_knowledge', 'operational_observation',
        ], true)) {
            $knowledgeType = in_array((string) ($experience['category'] ?? ''), [
                'architecture_decision', 'debugging_strategy', 'anti_pattern', 'workflow_rule',
                'tool_usage', 'test_oracle', 'security_boundary',
            ], true) ? 'skill_knowledge' : 'project_rule';
        }
        $surface = self::inline((string) ($classification['surface'] ?? 'project'), 120);
        $positiveExample = self::inline(
            (string) ($classification['positive_example'] ?? $experience['correct_approach'] ?? ''),
            1_600,
        );
        $negativeExample = self::inline(
            (string) ($classification['negative_example'] ?? ($wrong[0] ?? '')),
            1_600,
        );
        $normalizeExample = static fn(string $value): string => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value),
            'UTF-8',
        );
        $examplesComplete = $positiveExample !== ''
            && $negativeExample !== ''
            && $normalizeExample($positiveExample) !== $normalizeExample($negativeExample);

        return [
            'experience_id' => (string) $experience['experience_id'],
            'version' => (int) $experience['version'],
            'title' => self::inline((string) $experience['title'], 180),
            'category' => self::inline((string) $experience['category'], 80),
            'knowledge_type' => $knowledgeType,
            'surface' => $surface,
            'environment_constraints' => array_slice(Text::uniqueStrings(
                is_array($classification['environment_constraints'] ?? null)
                    ? $classification['environment_constraints']
                    : [],
            ), 0, 20),
            'positive_example' => $positiveExample,
            'negative_example' => $negativeExample,
            'examples_complete' => $examplesComplete,
            'problem_pattern' => self::inline((string) $experience['problem_pattern'], 1_000),
            'trigger' => self::inline((string) $experience['trigger'], 700),
            'root_cause' => self::inline((string) $experience['root_cause'], 1_000),
            'correct_approach' => self::inline((string) $experience['correct_approach'], 1_600),
            'reusable_rule' => self::inline((string) $experience['reusable_rule'], 1_600),
            'wrong_approaches' => array_slice($wrong, 0, 10),
            'verification' => array_slice($verification, 0, 20),
            'scope' => [
                'paths' => array_slice(Text::uniqueStrings(is_array($scope['paths'] ?? null) ? $scope['paths'] : []), 0, 30),
                'languages' => array_slice(Text::uniqueStrings(is_array($scope['languages'] ?? null) ? $scope['languages'] : []), 0, 20),
            ],
            'exceptions' => array_slice(Text::uniqueStrings(is_array($experience['exceptions'] ?? null) ? $experience['exceptions'] : []), 0, 20),
            'confidence' => round((float) $experience['confidence'], 3),
            'status' => (string) $experience['status'],
            'evidence_ids' => array_slice(Text::uniqueStrings(is_array($experience['evidence_ids'] ?? null) ? $experience['evidence_ids'] : []), 0, 40),
            'updated_at' => (string) $experience['updated_at'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $experiences
     * @return array<string, array<string, mixed>>
     */
    private function moduleExperiences(string $repository, array $experiences): array
    {
        $groups = [];
        $repositoryPrefix = rtrim(str_replace('\\', '/', $repository), '/') . '/';
        foreach ($experiences as $experience) {
            $scope = is_array($experience['scope'] ?? null) ? $experience['scope'] : [];
            $paths = Text::uniqueStrings(is_array($scope['paths'] ?? null) ? $scope['paths'] : []);
            foreach ($paths as $path) {
                $relativePath = str_replace('\\', '/', trim($path));
                if (str_starts_with($relativePath, $repositoryPrefix)) {
                    $relativePath = substr($relativePath, strlen($repositoryPrefix));
                }
                $relativePath = ltrim($relativePath, '/');
                if (preg_match('~^app/code/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)(?:/|$)~D', $relativePath, $matches) !== 1) {
                    continue;
                }
                $vendor = $matches[1];
                $moduleName = $matches[2];
                $root = 'app/code/' . $vendor . '/' . $moduleName;
                $absoluteRoot = $repository . '/' . $root;
                $resolvedRoot = realpath($absoluteRoot);
                if (!is_string($resolvedRoot) || $resolvedRoot !== $absoluteRoot || is_link($absoluteRoot)) {
                    continue;
                }
                $code = $vendor . '_' . $moduleName;
                if (!isset($groups[$code])) {
                    $groups[$code] = [
                        'code' => $code,
                        'route' => $vendor . '/' . $moduleName,
                        'vendor' => $vendor,
                        'name' => $moduleName,
                        'root' => $root,
                        'experiences' => [],
                        'seen' => [],
                    ];
                }
                $experienceId = (string) $experience['experience_id'];
                if (isset($groups[$code]['seen'][$experienceId])) {
                    continue;
                }
                $groups[$code]['seen'][$experienceId] = true;
                $groups[$code]['experiences'][] = $experience;
            }
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            unset($group['seen']);
            usort($group['experiences'], static fn(array $left, array $right): int => strcmp(
                (string) $left['experience_id'],
                (string) $right['experience_id']
            ));
        }
        unset($group);

        return $groups;
    }

    /** @param array<string, mixed> $classification
     *  @param list<array<string, mixed>> $experiences
     *  @param array<string, mixed>|null $moduleScope
     */
    private function renderSkill(array $classification, array $experiences, ?array $moduleScope = null): string
    {
        $name = (string) $classification['name'];
        $triggers = Text::uniqueStrings($classification['triggers'], false);
        $description = self::inline((string) $classification['description'], 500);
        if ($moduleScope !== null) {
            $description = self::inline($description . ' Module scope: ' . (string) $moduleScope['code'] . '.', 500);
        }
        $triggerSummary = self::inline(implode('; ', $triggers), 500);
        $frontmatterDescription = self::inline($description . ' Use when: ' . $triggerSummary, 980);
        $lines = [
            '---',
            'name: ' . $name,
            'description: ' . Json::encode($frontmatterDescription),
            '---',
            self::SKILL_MARKER,
            '',
            '# Purpose',
            '',
            'Apply the following evidence-backed, project-local learning only when the current task matches this skill description and the recorded scope.',
        ];
        if ($moduleScope !== null) {
            $lines[] = '';
            $lines[] = 'Module scope: `' . self::inline((string) $moduleScope['code'], 120)
                . '` (index route `' . self::inline((string) $moduleScope['route'], 160) . '`).';
        }
        $lines[] = '';
        $lines[] = '# Learned Rules';
        foreach ($experiences as $experience) {
            $lines[] = '';
            $lines[] = '## ' . self::inline((string) $experience['title'], 180);
            $lines[] = '';
            $lines[] = '- Trigger: ' . self::inline((string) $experience['trigger'], 700);
            $lines[] = '- Knowledge type: `' . self::inline((string) $experience['knowledge_type'], 80) . '`.';
            $lines[] = '- Surface: `' . self::inline((string) $experience['surface'], 120) . '`.';
            if ($experience['environment_constraints'] !== []) {
                $lines[] = '- Environment: ' . implode('; ', array_map(
                    static fn(string $value): string => self::inline($value, 300),
                    $experience['environment_constraints'],
                ));
            }
            if ((string) $experience['root_cause'] !== '') {
                $lines[] = '- Root cause: ' . self::inline((string) $experience['root_cause'], 1_000);
            }
            $rule = (string) $experience['reusable_rule'] !== ''
                ? (string) $experience['reusable_rule']
                : (string) $experience['correct_approach'];
            $lines[] = '- Do: ' . self::inline($rule, 1_600);
            foreach ($experience['wrong_approaches'] as $wrong) {
                $lines[] = '- Avoid: ' . self::inline((string) $wrong, 600);
            }
            $lines[] = '';
            $lines[] = '### Positive example';
            $lines[] = '';
            $lines[] = self::inline((string) $experience['positive_example'], 1_600);
            $lines[] = '';
            $lines[] = '### Negative example';
            $lines[] = '';
            $lines[] = self::inline((string) $experience['negative_example'], 1_600);
            foreach ($experience['exceptions'] as $exception) {
                $lines[] = '- Exception: ' . self::inline((string) $exception, 500);
            }
            $scope = is_array($experience['scope'] ?? null) ? $experience['scope'] : [];
            $paths = Text::uniqueStrings(is_array($scope['paths'] ?? null) ? $scope['paths'] : []);
            $languages = Text::uniqueStrings(is_array($scope['languages'] ?? null) ? $scope['languages'] : []);
            if ($paths !== []) {
                $lines[] = '- Scope paths: `' . implode('`, `', array_map(self::inline(...), $paths)) . '`';
            }
            if ($languages !== []) {
                $lines[] = '- Scope languages: ' . implode(', ', $languages);
            }
            $verification = $experience['verification'] !== []
                ? implode('; ', $experience['verification'])
                : implode(', ', $experience['evidence_ids']);
            $lines[] = '- Verify: inspect current code/runtime and the recorded evidence before consequential use (' . self::inline($verification, 1_000) . ').';
            $lines[] = sprintf(
                '- Source: `%s` v%d; status `%s`; confidence %.3f.',
                $experience['experience_id'],
                $experience['version'],
                $experience['status'],
                $experience['confidence'],
            );
        }
        array_push($lines,
            '',
            '# Boundaries',
            '',
            '- Treat this file as a generated projection of validated MCP evidence, not as authority outside this repository or recorded scope.',
            '- If current code, documentation, user intent, or runtime evidence contradicts a rule, stop applying it and send the experience back through review.',
            '- Do not broaden, promote, or copy these rules to global instructions automatically.',
            '- This file is marker-owned. Update it through the Weline Project Intelligence MCP rather than by hand.',
            '',
        );

        return implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $skills
     *  @param array<string, mixed> $plannerMetadata
     */
    private function renderManifest(string $projectId, string $sourceDigest, array $skills, array $plannerMetadata): string
    {
        $manifestSkills = [];
        foreach ($skills as $skill) {
            $item = $skill;
            unset($item['content']);
            $manifestSkills[] = $item;
        }
        $manifest = [
            'schema_version' => 'learning-skill-manifest.v2',
            'marker' => self::MANIFEST_MARKER,
            'generator_version' => self::GENERATOR_VERSION,
            'project_id' => $projectId,
            'source_digest' => $sourceDigest,
            'generated_at' => Clock::now(),
            'planner' => [
                'name' => (string) ($plannerMetadata['planner'] ?? 'none'),
                'purpose' => (string) ($plannerMetadata['purpose'] ?? ''),
                'mode' => (string) ($plannerMetadata['mode'] ?? ''),
            ],
            'skills' => $manifestSkills,
        ];

        return Json::encode($manifest, true) . "\n";
    }

    /** @param list<array<string, mixed>> $skills
     *  @return list<array<string, mixed>>
     */
    private function moduleGroups(array $skills): array
    {
        $groups = [];
        foreach ($skills as $skill) {
            if (($skill['projection_scope'] ?? 'project') !== 'module') {
                continue;
            }
            if (!self::skillRecordPathIsValid($skill)) {
                throw new ToolException('SKILL_MANIFEST_INVALID', 'Module learning-skill manifest entry is invalid');
            }
            $vendor = (string) $skill['vendor'];
            $moduleName = (string) $skill['module_name'];
            $module = (string) $skill['module'];
            $root = 'app/code/' . $vendor . '/' . $moduleName . '/doc/ai/skills';
            if (!isset($groups[$module])) {
                $groups[$module] = [
                    'module' => $module,
                    'module_route' => (string) $skill['module_route'],
                    'vendor' => $vendor,
                    'module_name' => $moduleName,
                    'index_path' => $root . '/' . self::MODULE_INDEX_NAME,
                    'manifest_path' => $root . '/' . self::MODULE_MANIFEST_NAME,
                    'skills' => [],
                ];
            }
            $groups[$module]['skills'][] = $skill;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            usort($group['skills'], static fn(array $left, array $right): int => strcmp(
                (string) $left['name'],
                (string) $right['name']
            ));
        }
        unset($group);

        return array_values($groups);
    }

    /** @param array<string, mixed> $group */
    private function renderModuleIndex(array $group): string
    {
        $lines = [
            self::MODULE_INDEX_MARKER,
            '# ' . self::inline((string) $group['module'], 160) . ' MCP 自动学习技能索引',
            '',
            '本文件由 Weline Project Intelligence MCP 从当前模块路径命中的已验证经验生成。Codex 应通过 MCP 一次获取命中技能正文，不扫描本目录。',
            '',
            '| 技能 | 路径 | 触发摘要 |',
            '|---|---|---|',
        ];
        foreach ($group['skills'] as $skill) {
            $description = str_replace('|', '\\|', self::inline((string) $skill['description'], 220));
            $lines[] = sprintf('| `%s` | `%s` | %s |', $skill['name'], $skill['relative_path'], $description);
        }
        $lines[] = '';
        $lines[] = '项目级索引：`' . self::INDEX_PATH . '`。';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $group */
    private function renderModuleManifest(array $group): string
    {
        $skills = [];
        foreach ($group['skills'] as $skill) {
            $item = $skill;
            unset($item['content']);
            $skills[] = $item;
        }

        return Json::encode([
            'schema_version' => 'learning-module-skill-manifest.v1',
            'marker' => self::MODULE_MANIFEST_MARKER,
            'generator_version' => self::GENERATOR_VERSION,
            'module' => $group['module'],
            'module_route' => $group['module_route'],
            'project_index' => self::INDEX_PATH,
            'skills' => $skills,
        ], true) . "\n";
    }

    /** @param array<string, mixed>|null $previousManifest
     *  @param list<array<string, mixed>> $skills
     *  @return list<string>
     */
    private function writeProjection(string $repository, ?array $previousManifest, array $skills, string $manifestBody): array
    {
        $writes = [];
        foreach ($skills as $skill) {
            $relativePath = (string) $skill['relative_path'];
            $writes[$relativePath] = (string) $skill['content'];
        }
        $groups = $this->moduleGroups($skills);
        foreach ($groups as $group) {
            $writes[(string) $group['index_path']] = $this->renderModuleIndex($group);
            $writes[(string) $group['manifest_path']] = $this->renderModuleManifest($group);
        }

        $indexPath = $this->projectionPath($repository, self::INDEX_PATH);
        $indexContent = file_get_contents($indexPath);
        if (!is_string($indexContent)) {
            throw new ToolException('SKILL_INDEX_MISSING', 'dev/ai/skills/_index.md is unavailable');
        }
        $writes[self::INDEX_PATH] = $this->renderIndex($indexContent, $skills);
        $writes[self::MANIFEST_PATH] = $manifestBody;

        foreach (array_keys($writes) as $relativePath) {
            if ($relativePath === self::INDEX_PATH || $relativePath === self::MANIFEST_PATH) {
                continue;
            }
            $this->assertMarkerOwnedTarget($repository, $relativePath);
        }

        $previousPaths = self::manifestProjectionPaths($previousManifest);
        $nextPaths = self::manifestProjectionPaths(['skills' => $skills]);
        $stale = array_values(array_diff($previousPaths, $nextPaths));
        foreach ($stale as $relativePath) {
            $this->assertMarkerOwnedTarget($repository, $relativePath);
        }

        $affected = Text::uniqueStrings(array_merge(array_keys($writes), $stale));
        $before = [];
        foreach ($affected as $relativePath) {
            $absolutePath = $this->projectionPath($repository, $relativePath);
            $before[$relativePath] = is_file($absolutePath) ? file_get_contents($absolutePath) : null;
            if ($before[$relativePath] === false) {
                throw new ToolException('SKILL_WRITE_FAILED', 'Unable to snapshot a learning-skill target', true, ['path' => $relativePath]);
            }
        }

        try {
            foreach ($writes as $relativePath => $content) {
                $this->atomicWrite($repository, $relativePath, $content);
            }
            foreach ($stale as $relativePath) {
                $absolutePath = $this->projectionPath($repository, $relativePath);
                if (is_file($absolutePath) && !unlink($absolutePath)) {
                    throw new RuntimeException('Unable to remove stale generated projection: ' . $relativePath);
                }
                if (str_ends_with($relativePath, '/SKILL.md')) {
                    @rmdir(dirname($absolutePath));
                }
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($affected) as $relativePath) {
                $snapshot = $before[$relativePath];
                try {
                    if (is_string($snapshot)) {
                        $this->atomicWrite($repository, $relativePath, $snapshot);
                    } else {
                        $absolutePath = $this->projectionPath($repository, $relativePath);
                        if (is_file($absolutePath)) {
                            @unlink($absolutePath);
                        }
                        if (str_ends_with($relativePath, '/SKILL.md')) {
                            @rmdir(dirname($absolutePath));
                        }
                    }
                } catch (Throwable) {
                }
            }
            throw new ToolException('SKILL_WRITE_FAILED', 'Automatic learning-skill transaction was rolled back', true, [
                'reason' => Text::truncate(Redactor::string($exception->getMessage())[0], 500),
            ]);
        }

        $changed = [];
        foreach ($affected as $relativePath) {
            $afterPath = $this->projectionPath($repository, $relativePath);
            $after = is_file($afterPath) ? file_get_contents($afterPath) : null;
            if ($before[$relativePath] !== $after) {
                $changed[] = $relativePath;
            }
        }

        return $changed;
    }

    private function assertMarkerOwnedTarget(string $repository, string $relativePath): void
    {
        $path = $this->projectionPath($repository, $relativePath);
        if (is_link($path)) {
            throw new ToolException('SKILL_OWNERSHIP_CONFLICT', 'Refusing a symbolic-link projection target', false, ['path' => $relativePath]);
        }
        if (!is_file($path)) {
            return;
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new ToolException('SKILL_OWNERSHIP_CONFLICT', 'Unable to verify projection ownership', false, ['path' => $relativePath]);
        }
        $owned = false;
        if (str_ends_with($relativePath, '/SKILL.md')) {
            $owned = str_contains($content, self::SKILL_MARKER);
        } elseif (str_ends_with($relativePath, '/' . self::MODULE_INDEX_NAME)) {
            $owned = str_contains($content, self::MODULE_INDEX_MARKER);
        } elseif (str_ends_with($relativePath, '/' . self::MODULE_MANIFEST_NAME)) {
            try {
                $manifest = Json::decode($content, []);
                $owned = is_array($manifest) && ($manifest['marker'] ?? '') === self::MODULE_MANIFEST_MARKER;
            } catch (Throwable) {
                $owned = false;
            }
        }
        if (!$owned) {
            throw new ToolException('SKILL_OWNERSHIP_CONFLICT', 'Refusing to overwrite a non-marker-owned projection', false, ['path' => $relativePath]);
        }
    }

    /** @param list<array<string, mixed>> $skills */
    private function renderIndex(string $content, array $skills): string
    {
        $startCount = substr_count($content, self::INDEX_START);
        $endCount = substr_count($content, self::INDEX_END);
        if ($startCount !== $endCount || $startCount > 1) {
            throw new ToolException('SKILL_INDEX_MARKER_INVALID', 'Generated learning-skill index markers are unbalanced');
        }
        $pattern = '~\n?' . preg_quote(self::INDEX_START, '~') . '.*?' . preg_quote(self::INDEX_END, '~') . '\n?~s';
        $content = preg_replace($pattern, "\n", $content) ?? $content;
        $projectSkills = array_values(array_filter(
            $skills,
            static fn(array $skill): bool => ($skill['projection_scope'] ?? 'project') !== 'module'
        ));
        $moduleGroups = $this->moduleGroups($skills);
        if ($projectSkills === [] && $moduleGroups === []) {
            return rtrim($content) . "\n";
        }
        $lines = [
            self::INDEX_START,
            '## MCP 自动学习技能',
            '',
            '以下技能由 Weline Project Intelligence MCP 从已验证经验分类生成；当前任务优先通过 MCP 命中并批量取得正文，不要扫描全部技能目录。',
        ];
        if ($projectSkills !== []) {
            array_push($lines,
                '',
                '### 项目级技能',
                '',
                '| 技能 | 路径 | 触发摘要 |',
                '|---|---|---|',
            );
            foreach ($projectSkills as $skill) {
                $description = str_replace('|', '\\|', self::inline((string) $skill['description'], 220));
                $lines[] = sprintf('| `%s` | `%s` | %s |', $skill['name'], $skill['relative_path'], $description);
            }
        }
        if ($moduleGroups !== []) {
            array_push($lines,
                '',
                '### 模块技能索引',
                '',
                '模块索引只包含作用域路径直接落入该模块的学习技能；本表是索引之索引。',
                '',
                '| 模块 | 技能索引 | Manifest | 技能数 |',
                '|---|---|---|---:|',
            );
            foreach ($moduleGroups as $group) {
                $lines[] = sprintf(
                    '| `%s` | `%s` | `%s` | %d |',
                    $group['module'],
                    $group['index_path'],
                    $group['manifest_path'],
                    count($group['skills']),
                );
            }
        }
        $lines[] = self::INDEX_END;
        $block = implode("\n", $lines) . "\n\n";
        $anchor = "## GitNexus 路由";
        $position = strpos($content, $anchor);
        if ($position === false) {
            return rtrim($content) . "\n\n" . rtrim($block) . "\n";
        }

        return rtrim(substr($content, 0, $position)) . "\n\n" . $block . ltrim(substr($content, $position));
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $repository): ?array
    {
        $path = $this->projectionPath($repository, self::MANIFEST_PATH);
        if (!is_file($path)) {
            return null;
        }
        $body = file_get_contents($path);
        $manifest = is_string($body) ? Json::decode($body) : null;
        if (!is_array($manifest) || ($manifest['marker'] ?? '') !== self::MANIFEST_MARKER) {
            throw new ToolException('SKILL_OWNERSHIP_CONFLICT', 'Learning-skill manifest is not marker-owned');
        }

        return $manifest;
    }

    /** @param array<string, mixed> $skill */
    private static function skillRecordPathIsValid(array $skill): bool
    {
        $path = trim((string) ($skill['relative_path'] ?? ''));
        $scope = (string) ($skill['projection_scope'] ?? 'project');
        if ($scope === 'project') {
            return self::isProjectSkillPath($path);
        }
        if ($scope !== 'module') {
            return false;
        }
        $vendor = trim((string) ($skill['vendor'] ?? ''));
        $moduleName = trim((string) ($skill['module_name'] ?? ''));
        if (preg_match('~^[A-Za-z0-9_.-]+$~D', $vendor) !== 1
            || preg_match('~^[A-Za-z0-9_.-]+$~D', $moduleName) !== 1
            || (string) ($skill['module'] ?? '') !== $vendor . '_' . $moduleName
            || (string) ($skill['module_route'] ?? '') !== $vendor . '/' . $moduleName) {
            return false;
        }
        $prefix = 'app/code/' . $vendor . '/' . $moduleName . '/doc/ai/skills/';

        return str_starts_with($path, $prefix) && self::isModuleSkillPath($path);
    }

    /** @param array<string, mixed>|null $manifest
     *  @return list<string>
     */
    private static function manifestProjectionPaths(?array $manifest): array
    {
        if (!is_array($manifest)) {
            return [];
        }
        $paths = [];
        foreach (is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [] as $skill) {
            if (!is_array($skill) || !self::skillRecordPathIsValid($skill)) {
                continue;
            }
            $path = trim((string) $skill['relative_path']);
            $paths[] = $path;
            if (($skill['projection_scope'] ?? 'project') !== 'module') {
                continue;
            }
            $root = 'app/code/' . $skill['vendor'] . '/' . $skill['module_name'] . '/doc/ai/skills/';
            $paths[] = $root . self::MODULE_INDEX_NAME;
            $paths[] = $root . self::MODULE_MANIFEST_NAME;
        }

        return Text::uniqueStrings($paths);
    }

    /** @param array<string, mixed> $manifest */
    private function manifestFilesAreCurrent(string $repository, array $manifest): bool
    {
        $skills = is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [];
        foreach ($skills as $skill) {
            if (!is_array($skill) || !self::skillRecordPathIsValid($skill)) {
                return false;
            }
            $relativePath = trim((string) $skill['relative_path']);
            $expected = trim((string) ($skill['file_hash'] ?? ''));
            $path = $this->projectionPath($repository, $relativePath);
            $content = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($content)
                || !str_contains($content, self::SKILL_MARKER)
                || $expected === ''
                || Ids::hash($content) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $manifest */
    private function manifestIndexesAreCurrent(string $repository, array $manifest): bool
    {
        $skills = is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [];
        $path = $this->projectionPath($repository, self::INDEX_PATH);
        $content = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($content)) {
            return false;
        }
        try {
            if (!hash_equals(Ids::hash($content), Ids::hash($this->renderIndex($content, $skills)))) {
                return false;
            }
            foreach ($this->moduleGroups($skills) as $group) {
                $indexPath = $this->projectionPath($repository, (string) $group['index_path']);
                $indexContent = is_file($indexPath) ? file_get_contents($indexPath) : false;
                if (!is_string($indexContent)
                    || !hash_equals(Ids::hash($indexContent), Ids::hash($this->renderModuleIndex($group)))) {
                    return false;
                }
                $manifestPath = $this->projectionPath($repository, (string) $group['manifest_path']);
                $moduleManifest = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
                if (!is_string($moduleManifest)
                    || !hash_equals(Ids::hash($moduleManifest), Ids::hash($this->renderModuleManifest($group)))) {
                    return false;
                }
            }
        } catch (ToolException) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $manifest
     *  @return list<string>
     */
    private function repairIndexes(string $repository, array $manifest): array
    {
        $skills = is_array($manifest['skills'] ?? null) ? $manifest['skills'] : [];
        $indexPath = $this->projectionPath($repository, self::INDEX_PATH);
        $indexContent = file_get_contents($indexPath);
        if (!is_string($indexContent)) {
            throw new ToolException('SKILL_INDEX_MISSING', 'dev/ai/skills/_index.md is unavailable');
        }
        $writes = [self::INDEX_PATH => $this->renderIndex($indexContent, $skills)];
        foreach ($this->moduleGroups($skills) as $group) {
            $writes[(string) $group['index_path']] = $this->renderModuleIndex($group);
            $writes[(string) $group['manifest_path']] = $this->renderModuleManifest($group);
        }

        $changed = [];
        foreach ($writes as $relativePath => $body) {
            if ($relativePath !== self::INDEX_PATH) {
                $this->assertMarkerOwnedTarget($repository, $relativePath);
            }
            $path = $this->projectionPath($repository, $relativePath);
            $current = is_file($path) ? file_get_contents($path) : false;
            if (is_string($current) && hash_equals(Ids::hash($current), Ids::hash($body))) {
                continue;
            }
            $this->atomicWrite($repository, $relativePath, $body);
            $changed[] = $relativePath;
        }

        return $changed;
    }

    private static function isProjectSkillPath(string $path): bool
    {
        return preg_match('~^dev/ai/skills/MCP学习-[^/]+/SKILL\.md$~uD', $path) === 1;
    }

    private static function isModuleSkillPath(string $path): bool
    {
        return preg_match(
            '~^app/code/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/doc/ai/skills/MCP学习-[^/]+/SKILL\.md$~uD',
            $path
        ) === 1;
    }

    private static function isModuleProjectionPath(string $path): bool
    {
        return self::isModuleSkillPath($path)
            || preg_match(
                '~^app/code/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/doc/ai/skills/(?:_index\.md|MCP-LEARNING-INDEX\.json)$~D',
                $path
            ) === 1;
    }

    /** @return array<string, mixed> */
    private function directLearningClosure(string $projectId): array
    {
        $query = $this->store->searchExperiences(
            $projectId,
            '',
            [],
            ['validated', 'promotion_eligible', 'promoted'],
            [],
            1,
            0,
        );

        return [
            'status' => 'verified',
            'mode' => 'learning_store_direct',
            'storage' => 'learning.sqlite:experiences',
            'query_path' => 'get_edit_bundle.validated_learning',
            'project_index_required' => false,
            'queryable_experience_sample_count' => count($query['experiences'] ?? []),
            'verified_at' => Clock::now(),
        ];
    }

    /** @param list<string> $paths
     *  @param list<string> $changedPaths
     *  @return array<string, mixed>
     */
    private function refreshIndex(string $repository, array $paths, array $changedPaths = []): array
    {
        $paths = Text::uniqueStrings($paths);
        if ($paths === []) {
            return [
                'refreshed' => false,
                'closure' => [
                    'status' => 'verified',
                    'mode' => 'no_projection_paths',
                    'verified_at' => Clock::now(),
                ],
            ];
        }

        $expectedHashes = [];
        $missingPaths = [];
        foreach ($paths as $relativePath) {
            $absolutePath = $this->projectionPath($repository, $relativePath);
            if (is_link($absolutePath)) {
                throw new ToolException(
                    'LEARNING_INDEX_CLOSURE_FAILED',
                    'A learning projection path became a symbolic link before indexing',
                    false,
                    ['path' => $relativePath],
                );
            }
            if (!is_file($absolutePath)) {
                $missingPaths[] = $relativePath;
                continue;
            }
            $content = file_get_contents($absolutePath);
            if (!is_string($content)) {
                throw new ToolException(
                    'LEARNING_INDEX_CLOSURE_FAILED',
                    'Unable to hash a learning projection before indexing',
                    true,
                    ['path' => $relativePath],
                );
            }
            $expectedHashes[$relativePath] = Ids::hash($content);
        }
        ksort($expectedHashes, SORT_STRING);
        $skillPaths = array_values(array_filter(
            array_keys($expectedHashes),
            static fn(string $path): bool => self::isProjectSkillPath($path) || self::isModuleSkillPath($path),
        ));

        $intelligence = new IntelligenceService($this->store, $this->config);
        $before = $intelligence->call('project_index_status', ['repository' => $repository]);
        $result = $intelligence->call('index_project', [
            'repository' => $repository,
            'mode' => 'incremental',
            'paths' => $paths,
        ]);
        $revision = (int) ($result['index']['revision'] ?? 0);
        $closure = $intelligence->call('verify_learning_projection', [
            'repository' => $repository,
            'expected_revision' => $revision,
            'expected_hashes' => $expectedHashes,
            'skill_paths' => $skillPaths,
            'missing_paths' => $missingPaths,
        ]);
        $beforeRevision = (int) ($before['index']['revision'] ?? 0);
        $expectedChangedPaths = Text::uniqueStrings($changedPaths);
        $closure = array_replace($closure, [
            'mode' => 'project_index_projection',
            'revision_before' => $beforeRevision,
            'revision_advanced' => $revision > $beforeRevision,
            'expected_changed_path_count' => count($expectedChangedPaths),
            'learning_source' => 'learning.sqlite:experiences',
            'query_path' => 'get_edit_bundle.skills+validated_learning',
        ]);

        return [
            'refreshed' => true,
            'revision' => $revision,
            'freshness' => (string) ($result['index']['freshness'] ?? 'unknown'),
            'paths' => $paths,
            'closure' => $closure,
        ];
    }

    private function repository(string $projectId, string $candidate): string
    {
        $resolved = realpath(trim($candidate));
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new ToolException('PROJECT_ROOT_MISSING', 'Learning-skill sync repository is unavailable', true);
        }
        $project = ProjectResolver::resolve($resolved);
        if (($project['project']['id'] ?? '') !== $projectId) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Learning-skill sync repository does not match project_id');
        }
        if (self::configuredOutputDirectory($this->config, $resolved) === null) {
            $skillsRoot = realpath($resolved . '/' . self::SKILLS_ROOT);
            if (!is_string($skillsRoot) || $skillsRoot !== $resolved . '/' . self::SKILLS_ROOT || is_link($skillsRoot)) {
                throw new ToolException('SKILL_ROOT_MISSING', 'Canonical dev/ai/skills directory is unavailable or unsafe');
            }
        }

        return $resolved;
    }

    private function projectionPath(string $repository, string $relativePath): string
    {
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            throw new ToolException('PATH_DENIED', 'Learning-skill projection path is invalid');
        }
        if ($relativePath !== self::INDEX_PATH
            && $relativePath !== self::MANIFEST_PATH
            && !self::isProjectSkillPath($relativePath)
            && !self::isModuleProjectionPath($relativePath)) {
            throw new ToolException('PATH_DENIED', 'Learning-skill projection path is outside its marker-owned boundary', false, ['path' => $relativePath]);
        }

        return $repository . '/' . $relativePath;
    }

    private function projectionRoot(string $repository, string $relativePath): string
    {
        if (str_starts_with($relativePath, self::SKILLS_ROOT . '/')) {
            $root = $repository . '/' . self::SKILLS_ROOT;
        } elseif (preg_match(
            '~^app/code/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/doc/ai/skills/~D',
            $relativePath,
            $matches
        ) === 1) {
            $root = $repository . '/app/code/' . $matches[1] . '/' . $matches[2];
        } else {
            throw new RuntimeException('Unable to determine learning-skill projection root');
        }
        $resolved = realpath($root);
        if (!is_string($resolved) || $resolved !== $root || is_link($root)) {
            throw new RuntimeException('Learning-skill projection root is unavailable or unsafe');
        }

        return $root;
    }

    private function atomicWrite(string $repository, string $relativePath, string $content): void
    {
        $path = $this->projectionPath($repository, $relativePath);
        $directory = dirname($path);
        $root = $this->projectionRoot($repository, $relativePath);
        if (!str_starts_with($directory . '/', $root . '/')) {
            throw new RuntimeException('Generated skill directory escaped the projection root');
        }

        $relativeDirectory = ltrim(substr($directory, strlen($root)), '/');
        $cursor = $root;
        foreach (array_values(array_filter(explode('/', $relativeDirectory), static fn(string $part): bool => $part !== '')) as $part) {
            if ($part === '.' || $part === '..') {
                throw new RuntimeException('Generated skill directory contains an invalid segment');
            }
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('Generated skill directory cannot be a symbolic link');
            }
            if (!is_dir($cursor) && !mkdir($cursor, 0755, false) && !is_dir($cursor)) {
                throw new RuntimeException('Unable to create generated skill directory');
            }
            $resolved = realpath($cursor);
            if (!is_string($resolved) || $resolved !== $cursor) {
                throw new RuntimeException('Generated skill directory escaped the projection root');
            }
        }
        if (is_link($path)) {
            throw new RuntimeException('Generated skill target cannot be a symbolic link');
        }
        $temporary = tempnam($directory, '.weline-mcp-skill-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Unable to allocate generated skill temporary file');
        }
        try {
            if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('Unable to write complete generated skill content');
            }
            @chmod($temporary, 0644);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically replace generated skill target');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @template T
     *  @param callable():T $callback
     *  @return T
     */
    private function withProjectLock(string $projectId, callable $callback): mixed
    {
        $directory = rtrim($this->config->dataDir(), '/') . '/learning-skill-locks';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ToolException('SKILL_LOCK_FAILED', 'Unable to create the learning-skill lock directory', true);
        }
        @chmod($directory, 0700);
        $path = $directory . '/' . substr(hash('sha256', $projectId), 0, 32) . '.lock';
        $handle = fopen($path, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ToolException('SKILL_LOCK_FAILED', 'Unable to acquire the learning-skill project lock', true);
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @chmod($path, 0600);
        }
    }

    private function invoker(): CodexInvoker
    {
        return $this->codex ?? new CodexInvoker($this->config, new ProcessRunner());
    }

    private static function inline(string $value, int $limit = 1_000): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = str_replace(['`', '|', '<!--', '-->'], ["'", '/', '&lt;!--', '--&gt;'], $value);

        return Text::truncate($value, $limit);
    }
}
