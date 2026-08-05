#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Synchronize thin Codex/plugin adapters from canonical Weline skills.
 *
 * Usage:
 *   php dev/ai/scripts/sync-codex-plugin-skills.php
 *   php dev/ai/scripts/sync-codex-plugin-skills.php --check
 */

const PLUGIN_ADAPTER_SKILLS = [
    'CI发布工程师-CI与发布门禁',
    'CI发布工程师-环境兼容与命令安全',
    'E2E自动化工程师-端到端流程测试',
    'E2E自动化工程师-路由与UI冒烟验证',
    'QA测试主管-测试策略治理',
    'QA测试主管-质量门禁验收',
    'SEO面板诊断',
    'WLS运行时工程师-Session与SSE运行时',
    'WLS运行时工程师-WLS面板性能诊断',
    'WLS运行时工程师-WLS进程稳定',
    'testing',
    'ui-ux-pro-max',
    'visitor-pixel',
    '业务模块工程师-服务层与业务逻辑',
    '业务模块工程师-模块开发',
    '业务模块工程师-配置缓存与后台权限',
    '前端主题工程师-主题模板开发',
    '前端主题工程师-前端API交互',
    '前端主题工程师-组件与页面构建',
    '单元测试工程师-单元测试覆盖',
    '单元测试工程师-测试数据与回归',
    '安全权限工程师-ACL与后台安全',
    '安全权限工程师-会话配置与数据保护',
    '技术主管-一级验收与进度追踪',
    '技术主管-任务拆分与调度',
    '文档知识库工程师-会话复盘与规则沉淀',
    '文档知识库工程师-技能索引与知识库',
    '文档知识库工程师-文档规范与变更记录',
    '框架核心工程师-ORM与数据模型',
    '框架核心工程师-命令与代码生成',
    '框架核心工程师-框架核心开发',
    '框架核心工程师-路由事件与扩展',
    '通用工程师-国际化与用户提示',
    '通用工程师-开发规范与代码质量',
];

const DIRECT_ADAPTER_SKILLS = [
    'framework-taglib-catalog',
    'payment-provider-development',
    'planning',
    'queue',
    'system-config-scope',
    'unified-query-provider',
];

const MANUAL_PLUGIN_SKILLS = [
    'weline-framework',
];

const MANUAL_DIRECT_SKILLS = [
    'deploy-release-system',
    'fencang-release',
    'fenxiang-update',
];

$arguments = array_slice($argv, 1);
$checkOnly = $arguments === ['--check'];
if ($arguments !== [] && !$checkOnly) {
    fwrite(STDERR, "Usage: php dev/ai/scripts/sync-codex-plugin-skills.php [--check]\n");
    exit(2);
}

$repositoryRoot = dirname(__DIR__, 3);
$canonicalRoot = $repositoryRoot . '/dev/ai/skills';
$pluginRoot = $repositoryRoot . '/dev/ai/codex/plugins/weline-codex-plugin/skills';
$directRoot = $repositoryRoot . '/.codex/skills';
$errors = [];
$expectedFiles = [];

foreach (PLUGIN_ADAPTER_SKILLS as $skill) {
    registerExpectedAdapter(
        $expectedFiles,
        $errors,
        $canonicalRoot,
        $pluginRoot,
        $skill
    );
}
foreach (DIRECT_ADAPTER_SKILLS as $skill) {
    registerExpectedAdapter(
        $expectedFiles,
        $errors,
        $canonicalRoot,
        $directRoot,
        $skill
    );
}

checkManualSkills($pluginRoot, MANUAL_PLUGIN_SKILLS, $repositoryRoot, $errors);
checkManualSkills($directRoot, MANUAL_DIRECT_SKILLS, $repositoryRoot, $errors);
checkActiveAllowlist(
    $pluginRoot,
    array_merge(PLUGIN_ADAPTER_SKILLS, MANUAL_PLUGIN_SKILLS),
    $repositoryRoot,
    $errors
);
checkActiveAllowlist(
    $directRoot,
    array_merge(DIRECT_ADAPTER_SKILLS, MANUAL_DIRECT_SKILLS),
    $repositoryRoot,
    $errors
);

if ($errors !== []) {
    printErrors($errors);
    exit(1);
}

$pending = [];
foreach ($expectedFiles as $targetPath => $expected) {
    $actual = is_file($targetPath) ? file_get_contents($targetPath) : false;
    if ($actual === $expected) {
        continue;
    }
    if ($checkOnly) {
        $errors[] = relativePath($repositoryRoot, $targetPath) . ' is missing or out of sync';
    } else {
        $pending[$targetPath] = $expected;
    }
}

if ($errors !== []) {
    printErrors($errors);
    exit(1);
}

if ($checkOnly) {
    printf(
        "OK: %d plugin and %d direct skill adapters are synchronized\n",
        count(PLUGIN_ADAPTER_SKILLS),
        count(DIRECT_ADAPTER_SKILLS)
    );
    exit(0);
}

if ($pending === []) {
    fwrite(STDOUT, "No Codex/plugin skill adapters changed\n");
    exit(0);
}

/**
 * Stage every output in its destination directory before replacing any target.
 *
 * @var array<string, string> $temporaryFiles
 */
$temporaryFiles = [];
foreach ($pending as $targetPath => $expected) {
    $targetDirectory = dirname($targetPath);
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
        $errors[] = 'Cannot create ' . relativePath($repositoryRoot, $targetDirectory);
        break;
    }
    $temporaryPath = $targetPath . '.skill-sync-' . getmypid() . '.tmp';
    if (is_file($temporaryPath)) {
        unlink($temporaryPath);
    }
    if (file_put_contents($temporaryPath, $expected) === false) {
        $errors[] = 'Cannot stage ' . relativePath($repositoryRoot, $targetPath);
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
        break;
    }
    $temporaryFiles[$targetPath] = $temporaryPath;
}

if ($errors !== []) {
    foreach ($temporaryFiles as $temporaryPath) {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
    printErrors($errors);
    exit(1);
}

/** @var array<string, ?string> $backups */
$backups = [];
/** @var list<string> $replaced */
$replaced = [];
foreach ($temporaryFiles as $targetPath => $temporaryPath) {
    $backupPath = null;
    if (is_file($targetPath)) {
        $backupPath = $targetPath . '.skill-sync-' . getmypid() . '.bak';
        if (is_file($backupPath)) {
            unlink($backupPath);
        }
        if (!rename($targetPath, $backupPath)) {
            $errors[] = 'Cannot back up ' . relativePath($repositoryRoot, $targetPath);
            break;
        }
    }
    if (!rename($temporaryPath, $targetPath)) {
        if ($backupPath !== null && is_file($backupPath)) {
            rename($backupPath, $targetPath);
        }
        $errors[] = 'Cannot replace ' . relativePath($repositoryRoot, $targetPath);
        break;
    }
    $backups[$targetPath] = $backupPath;
    $replaced[] = $targetPath;
    fwrite(STDOUT, 'Updated ' . relativePath($repositoryRoot, $targetPath) . "\n");
}

if ($errors !== []) {
    foreach (array_reverse($replaced) as $targetPath) {
        $backupPath = $backups[$targetPath] ?? null;
        if (is_file($targetPath)) {
            unlink($targetPath);
        }
        if ($backupPath !== null && is_file($backupPath)) {
            rename($backupPath, $targetPath);
        }
    }
    foreach ($temporaryFiles as $temporaryPath) {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
    printErrors($errors);
    exit(1);
}

foreach ($backups as $backupPath) {
    if ($backupPath !== null && is_file($backupPath)) {
        unlink($backupPath);
    }
}

/**
 * @param array<string, string> $expectedFiles
 * @param list<string> $errors
 */
function registerExpectedAdapter(
    array &$expectedFiles,
    array &$errors,
    string $canonicalRoot,
    string $targetRoot,
    string $skill
): void {
    $canonicalPath = $canonicalRoot . '/' . $skill . '/SKILL.md';
    $targetPath = $targetRoot . '/' . $skill . '/SKILL.md';
    try {
        $expectedFiles[$targetPath] = buildAdapter($canonicalPath, $skill);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
}

/**
 * @param list<string> $skills
 * @param list<string> $errors
 */
function checkManualSkills(string $root, array $skills, string $repositoryRoot, array &$errors): void
{
    foreach ($skills as $skill) {
        $path = $root . '/' . $skill . '/SKILL.md';
        if (!is_file($path)) {
            $errors[] = relativePath($repositoryRoot, $path) . ' is missing';
        }
    }
}

/**
 * @param list<string> $allowed
 * @param list<string> $errors
 */
function checkActiveAllowlist(string $root, array $allowed, string $repositoryRoot, array &$errors): void
{
    $allowedMap = array_fill_keys($allowed, true);
    foreach (glob($root . '/*/SKILL.md') ?: [] as $path) {
        $skill = basename(dirname($path));
        if (!isset($allowedMap[$skill])) {
            $errors[] = relativePath($repositoryRoot, $path) . ' is not in the active adapter allowlist';
        }
    }
}

function buildAdapter(string $canonicalPath, string $skill): string
{
    $contents = file_get_contents($canonicalPath);
    if ($contents === false) {
        throw new RuntimeException("Cannot read canonical skill: {$canonicalPath}");
    }
    if (!preg_match('/\A---\r?\n(.*?)\r?\n---(?:\r?\n|\z)/su', $contents, $matches)) {
        throw new RuntimeException("Invalid canonical frontmatter: {$canonicalPath}");
    }

    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $matches[1]));
    $nameBlock = extractFieldBlock($lines, 'name', $canonicalPath);
    $descriptionBlock = extractFieldBlock($lines, 'description', $canonicalPath);
    $nameValue = trim(substr($nameBlock[0], strlen('name:')), " \t\n\r\0\x0B\"'");
    if ($nameValue !== $skill) {
        throw new RuntimeException(
            "Canonical name '{$nameValue}' does not match adapter directory '{$skill}': {$canonicalPath}"
        );
    }

    return "---\n"
        . implode("\n", $nameBlock) . "\n"
        . implode("\n", $descriptionBlock) . "\n"
        . "---\n\n"
        . "# Canonical adapter\n\n"
        . "Read `dev/ai/skills/{$skill}/SKILL.md` completely when this skill triggers. "
        . "Follow `dev/ai/global-constraints.md`; do not duplicate the canonical body here.\n";
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function extractFieldBlock(array $lines, string $field, string $path): array
{
    $matches = [];
    foreach ($lines as $index => $line) {
        if (preg_match('/^' . preg_quote($field, '/') . ':\s*(.*)$/', $line, $fieldMatch)) {
            $matches[] = [$index, $fieldMatch[1]];
        }
    }
    if (count($matches) !== 1) {
        throw new RuntimeException("Canonical frontmatter must contain exactly one {$field}: {$path}");
    }

    [$index, $value] = $matches[0];
    if ($field !== 'description' || !preg_match('/^[>|][+-]?$/', trim($value))) {
        if (trim($value) === '') {
            throw new RuntimeException("Canonical {$field} must not be empty: {$path}");
        }
        return [$lines[$index]];
    }

    $block = [$lines[$index]];
    for ($cursor = $index + 1, $count = count($lines); $cursor < $count; $cursor++) {
        $line = $lines[$cursor];
        if ($line !== '' && !preg_match('/^\s+/', $line)) {
            break;
        }
        $block[] = $line;
    }
    if (trim(implode("\n", array_slice($block, 1))) === '') {
        throw new RuntimeException("Canonical description must not be empty: {$path}");
    }
    return $block;
}

/**
 * @param list<string> $errors
 */
function printErrors(array $errors): void
{
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
}

function relativePath(string $root, string $path): string
{
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
}
