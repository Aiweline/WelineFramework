<?php
declare(strict_types=1);

/**
 * Validator for the official App Store manifest contract required by the WLS
 * Panel marketplace typed-tag E2E.
 *
 * By default the tool is read-only. Template mode can materialize a manifest
 * JSON only when an explicit target, --write=1, and the confirmation phrase are
 * supplied. Source catalog materialization is a separate guarded action that
 * requires its own confirmation phrase. The tool never starts WLS, contacts App
 * Store, reads credentials, or writes anything unless an explicit write flag and
 * matching confirmation phrase are supplied.
 */

const WLS_PANEL_OFFICIAL_MANIFEST_EXIT_ASSERTION_FAILED = 1;
const WLS_PANEL_OFFICIAL_MANIFEST_DEFAULT_SOURCE_ROOT = 'modules';
const WLS_PANEL_OFFICIAL_MANIFEST_WRITE_CONFIRM = 'WRITE_WLS_OFFICIAL_MANIFEST';
const WLS_PANEL_OFFICIAL_SOURCES_WRITE_CONFIRM = 'WRITE_WLS_OFFICIAL_SOURCES';

/**
 * @return list<array{module_name:string,dev_dir:string,source_dir:string}>
 */
function wlsPanelOfficialManifestDefaultWlsModules(): array
{
    return [
        [
            'module_name' => 'Weline_PhpManager',
            'dev_dir' => 'app/code/Weline/PhpManager',
            'source_dir' => 'modules/PhpManager',
        ],
        [
            'module_name' => 'Weline_DbManager',
            'dev_dir' => 'app/code/Weline/DbManager',
            'source_dir' => 'modules/DbManager',
        ],
        [
            'module_name' => 'Weline_FileManager',
            'dev_dir' => 'app/code/Weline/FileManager',
            'source_dir' => 'modules/FileManager',
        ],
        [
            'module_name' => 'Weline_Deploy',
            'dev_dir' => 'app/code/Weline/Deploy',
            'source_dir' => 'modules/Deploy',
        ],
        [
            'module_name' => 'Weline_WlsDemoPlugin',
            'dev_dir' => 'app/code/Weline/WlsDemoPlugin',
            'source_dir' => 'modules/WlsDemoPlugin',
        ],
    ];
}

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelOfficialManifestParseArgs(array $argv): array
{
    $args = [];
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = (string)$argv[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
            continue;
        }

        $next = $argv[$i + 1] ?? null;
        if (is_string($next) && !str_starts_with($next, '--')) {
            $args[$arg] = $next;
            $i++;
            continue;
        }

        $args[$arg] = '1';
    }

    return $args;
}

/**
 * @param array<string, mixed> $payload
 */
function wlsPanelOfficialManifestFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelOfficialManifestPath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function wlsPanelOfficialManifestRead(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $content = file_get_contents($path);
    return is_string($content) ? $content : '';
}

function wlsPanelOfficialManifestNormalizePath(string $path): string
{
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $parts = [];
    foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    $prefix = '';
    if (preg_match('/^[A-Za-z]:/', $path) === 1) {
        $prefix = substr($path, 0, 2) . DIRECTORY_SEPARATOR;
        if ($parts !== [] && preg_match('/^[A-Za-z]:$/', $parts[0]) === 1) {
            array_shift($parts);
        }
    } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        $prefix = DIRECTORY_SEPARATOR;
    }

    return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
}

function wlsPanelOfficialManifestPathWithin(string $path, string $root): bool
{
    $path = strtolower(rtrim(wlsPanelOfficialManifestNormalizePath($path), "\\/"));
    $root = strtolower(rtrim(wlsPanelOfficialManifestNormalizePath($root), "\\/"));
    return $root !== '' && ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR));
}

/**
 * @return array<string, mixed>
 */
function wlsPanelOfficialManifestReadJson(string $path): array
{
    $content = wlsPanelOfficialManifestRead($path);
    if ($content === '') {
        return [];
    }

    $payload = json_decode(ltrim($content, "\xEF\xBB\xBF"), true);
    return is_array($payload) ? $payload : [];
}

function wlsPanelOfficialManifestIsAbsolutePath(string $path): bool
{
    return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
        || str_starts_with($path, '/')
        || str_starts_with($path, '\\\\');
}

/**
 * @return array{target:string,write_requested:bool,wrote:bool,would_write:bool,errors:list<string>}
 */
function wlsPanelOfficialManifestMaterializeTemplate(array $manifest, array $args): array
{
    $target = trim((string)($args['template-target'] ?? $args['target'] ?? ''));
    $write = (string)($args['write'] ?? '0') === '1';
    $confirm = trim((string)($args['confirm'] ?? ''));
    $overwrite = (string)($args['overwrite'] ?? '0') === '1';
    $createDir = (string)($args['create-dir'] ?? '0') === '1';
    $errors = [];

    if ($target === '') {
        if ($write) {
            $errors[] = 'template_target_required_for_write';
        }
        return [
            'target' => '',
            'write_requested' => $write,
            'wrote' => false,
            'would_write' => false,
            'errors' => $errors,
        ];
    }

    if ($write) {
        if ($confirm !== WLS_PANEL_OFFICIAL_MANIFEST_WRITE_CONFIRM) {
            $errors[] = 'write_confirm_phrase_required';
        }
        if (!wlsPanelOfficialManifestIsAbsolutePath($target)) {
            $errors[] = 'template_target_must_be_absolute';
        }
        if (!str_ends_with(str_replace('\\', '/', strtolower($target)), '/manifest.json')) {
            $errors[] = 'template_target_must_end_with_manifest_json';
        }
        if (is_file($target) && !$overwrite) {
            $errors[] = 'template_target_exists_without_overwrite';
        }

        $dir = dirname($target);
        if (!is_dir($dir)) {
            if ($createDir) {
                if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                    $errors[] = 'template_target_directory_create_failed';
                }
            } else {
                $errors[] = 'template_target_directory_missing';
            }
        }

        if ($errors === []) {
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                $errors[] = 'template_json_encode_failed';
            } elseif (file_put_contents($target, $json . PHP_EOL, LOCK_EX) === false) {
                $errors[] = 'template_target_write_failed';
            }
        }
    }

    return [
        'target' => $target,
        'write_requested' => $write,
        'wrote' => $write && $errors === [],
        'would_write' => !$write && $target !== '',
        'errors' => $errors,
    ];
}

/**
 * @return list<string>
 */
function wlsPanelOfficialManifestSourceExcludeNames(): array
{
    return [
        '.git',
        '.svn',
        '.idea',
        '.vscode',
        'generated',
        'node_modules',
        'vendor',
        'var',
    ];
}

/**
 * @return array{copied:int,errors:list<string>}
 */
function wlsPanelOfficialManifestCopyTree(string $source, string $target): array
{
    $errors = [];
    $copied = 0;
    $excluded = wlsPanelOfficialManifestSourceExcludeNames();

    if (!is_dir($source)) {
        return ['copied' => 0, 'errors' => ['copy_source_missing:' . $source]];
    }
    if (is_link($source)) {
        return ['copied' => 0, 'errors' => ['copy_source_symlink_refused:' . $source]];
    }
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        return ['copied' => 0, 'errors' => ['copy_target_create_failed:' . $target]];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $item) use ($excluded): bool {
                if (in_array($item->getFilename(), $excluded, true)) {
                    return false;
                }
                return !$item->isLink();
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        $relative = substr($item->getPathname(), strlen(rtrim($source, "\\/")) + 1);
        $targetPath = wlsPanelOfficialManifestPath($target, $relative);
        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                $errors[] = 'copy_directory_failed:' . $relative;
            }
            continue;
        }
        if (!$item->isFile()) {
            continue;
        }
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $errors[] = 'copy_parent_create_failed:' . $relative;
            continue;
        }
        if (!copy($item->getPathname(), $targetPath)) {
            $errors[] = 'copy_file_failed:' . $relative;
            continue;
        }
        $copied++;
    }

    return ['copied' => $copied, 'errors' => $errors];
}

/**
 * @return array{written:int,errors:list<string>}
 */
function wlsPanelOfficialManifestWriteCanarySource(string $target): array
{
    $errors = [];
    $registerPath = wlsPanelOfficialManifestPath($target, 'register.php');
    $metaPath = wlsPanelOfficialManifestPath($target, 'etc/marketplace/meta.json');
    foreach ([dirname($registerPath), dirname($metaPath)] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $errors[] = 'canary_directory_create_failed:' . $dir;
        }
    }
    if ($errors !== []) {
        return ['written' => 0, 'errors' => $errors];
    }

    $register = <<<'PHP'
<?php
declare(strict_types=1);

use Weline\Framework\App\Register;

Register::register(Register::MODULE, 'Weline_WlsTagCanary', __DIR__, '1.0.0', [
    'Weline_Backend',
]);
PHP;
    $meta = [
        'schema_version' => 1,
        'module' => 'Weline_WlsTagCanary',
        'display_name' => [
            'en_US' => 'WLS Typed Tag Canary',
            'zh_Hans_CN' => 'WLS 类型标签验证项',
        ],
        'description' => [
            'en_US' => 'Non-installable canary item used to prove module:wls-extra does not match module:wls.',
            'zh_Hans_CN' => '用于证明 module:wls-extra 不会匹配 module:wls 的不可安装验证项。',
        ],
        'surfaces' => ['backend'],
        'tags' => ['module:wls-extra', 'custom:wls-tag-canary', 'system:false'],
    ];

    $written = 0;
    if (file_put_contents($registerPath, $register . PHP_EOL, LOCK_EX) === false) {
        $errors[] = 'canary_register_write_failed';
    } else {
        $written++;
    }
    $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($metaJson) || file_put_contents($metaPath, $metaJson . PHP_EOL, LOCK_EX) === false) {
        $errors[] = 'canary_meta_write_failed';
    } else {
        $written++;
    }

    return ['written' => $written, 'errors' => $errors];
}

/**
 * @param list<array{module_name:string,dev_dir:string,source_dir:string}> $modules
 * @return array{target_root:string,write_requested:bool,wrote:bool,would_write:bool,ready:bool,errors:list<string>,entries:list<array<string,mixed>>}
 */
function wlsPanelOfficialManifestMaterializeSources(
    array $modules,
    string $workspaceRoot,
    string $templateTarget,
    array $args
): array {
    $targetRoot = trim((string)($args['source-target-root'] ?? ''));
    if ($targetRoot === '' && $templateTarget !== '') {
        $targetRoot = dirname($templateTarget);
    }

    $write = (string)($args['write-sources'] ?? '0') === '1';
    $confirm = trim((string)($args['confirm-sources'] ?? ''));
    $overwrite = (string)($args['overwrite-sources'] ?? '0') === '1';
    $createDir = (string)($args['create-source-dirs'] ?? '0') === '1';
    $errors = [];
    $entries = [];
    $wrote = false;

    if ($targetRoot === '') {
        if ($write) {
            $errors[] = 'source_target_root_required_for_write';
        }
        return [
            'target_root' => '',
            'write_requested' => $write,
            'wrote' => false,
            'would_write' => false,
            'ready' => false,
            'errors' => $errors,
            'entries' => [],
        ];
    }

    if ($write) {
        if ($confirm !== WLS_PANEL_OFFICIAL_SOURCES_WRITE_CONFIRM) {
            $errors[] = 'source_write_confirm_phrase_required';
        }
        if (!wlsPanelOfficialManifestIsAbsolutePath($targetRoot)) {
            $errors[] = 'source_target_root_must_be_absolute';
        }
    }

    $targetRootNormalized = wlsPanelOfficialManifestNormalizePath($targetRoot);
    $modulesRoot = wlsPanelOfficialManifestPath($targetRootNormalized, WLS_PANEL_OFFICIAL_MANIFEST_DEFAULT_SOURCE_ROOT);
    $catalog = $modules;
    $catalog[] = [
        'module_name' => 'Weline_WlsTagCanary',
        'dev_dir' => '',
        'source_dir' => WLS_PANEL_OFFICIAL_MANIFEST_DEFAULT_SOURCE_ROOT . '/WlsTagCanary',
    ];

    foreach ($catalog as $module) {
        $sourceDir = trim((string)$module['source_dir']);
        $target = wlsPanelOfficialManifestPath($targetRootNormalized, $sourceDir);
        $targetAcceptable = str_starts_with(str_replace('\\', '/', $sourceDir), WLS_PANEL_OFFICIAL_MANIFEST_DEFAULT_SOURCE_ROOT . '/')
            && wlsPanelOfficialManifestPathWithin($target, $modulesRoot);
        $isCanary = $module['module_name'] === 'Weline_WlsTagCanary';
        $devPath = $isCanary ? '' : wlsPanelOfficialManifestPath($workspaceRoot, $module['dev_dir']);
        $devReady = $isCanary
            || (is_dir($devPath)
                && is_file(wlsPanelOfficialManifestPath($devPath, 'register.php'))
                && is_file(wlsPanelOfficialManifestPath($devPath, 'etc/marketplace/meta.json')));
        $targetExists = is_dir($target);

        if (!$targetAcceptable) {
            $errors[] = 'source_target_outside_modules:' . $module['module_name'];
        }
        if (!$devReady) {
            $errors[] = 'source_dev_unreadable:' . $module['module_name'];
        }
        if ($write && $targetExists && !$overwrite) {
            $errors[] = 'source_target_exists_without_overwrite:' . $module['module_name'];
        }

        $entry = [
            'module_name' => $module['module_name'],
            'source_dir' => $sourceDir,
            'kind' => $isCanary ? 'generated_canary' : 'copy_dev_module',
            'dev_path' => $devPath,
            'target_path' => $target,
            'dev_ready' => $devReady,
            'target_acceptable' => $targetAcceptable,
            'target_exists' => $targetExists,
            'copied_files' => 0,
            'written_files' => 0,
        ];

        if ($write && $errors === []) {
            if (!is_dir(dirname($target))) {
                if ($createDir) {
                    if (!mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                        $errors[] = 'source_parent_create_failed:' . $module['module_name'];
                    }
                } else {
                    $errors[] = 'source_parent_missing:' . $module['module_name'];
                }
            }
            if ($errors === []) {
                if ($isCanary) {
                    $result = wlsPanelOfficialManifestWriteCanarySource($target);
                    $entry['written_files'] = $result['written'];
                    $errors = array_merge($errors, $result['errors']);
                } else {
                    $result = wlsPanelOfficialManifestCopyTree($devPath, $target);
                    $entry['copied_files'] = $result['copied'];
                    $errors = array_merge($errors, $result['errors']);
                }
                $wrote = $wrote || $entry['copied_files'] > 0 || $entry['written_files'] > 0;
            }
        }

        $entries[] = $entry;
    }

    $ready = $errors === [] && $entries !== [];

    return [
        'target_root' => $targetRoot,
        'write_requested' => $write,
        'wrote' => $write && $ready && $wrote,
        'would_write' => !$write && $ready,
        'ready' => $ready,
        'errors' => $errors,
        'entries' => $entries,
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelOfficialManifestCatalogSummary(array $manifest, array $sourcePlan): array
{
    $entries = wlsPanelOfficialManifestEntries($manifest);
    $expectedPositiveModules = array_map(
        static fn(array $module): string => $module['module_name'],
        wlsPanelOfficialManifestDefaultWlsModules()
    );
    $expectedCatalogModules = array_merge($expectedPositiveModules, ['Weline_WlsTagCanary']);
    $positiveModules = [];
    $negativeCanaryModules = [];

    foreach ($entries as $entry) {
        $tags = wlsPanelOfficialManifestEntryTags($entry);
        $name = wlsPanelOfficialManifestEntryName($entry);
        if (in_array('module:wls', $tags, true)) {
            $positiveModules[] = $name;
        }
        if (in_array('module:wls-extra', $tags, true)) {
            $negativeCanaryModules[] = $name;
        }
    }

    $sourceEntries = is_array($sourcePlan['entries'] ?? null) ? $sourcePlan['entries'] : [];
    $sourceEntryModules = [];
    $allSourceEntriesReady = $sourceEntries !== [];
    $allSourceTargetsAcceptable = $sourceEntries !== [];
    foreach ($sourceEntries as $sourceEntry) {
        if (!is_array($sourceEntry)) {
            $allSourceEntriesReady = false;
            $allSourceTargetsAcceptable = false;
            continue;
        }

        $sourceEntryModules[] = (string)($sourceEntry['module_name'] ?? '');
        $allSourceEntriesReady = $allSourceEntriesReady && ($sourceEntry['dev_ready'] ?? false) === true;
        $allSourceTargetsAcceptable = $allSourceTargetsAcceptable && ($sourceEntry['target_acceptable'] ?? false) === true;
    }

    $positiveModules = array_values(array_unique(array_filter($positiveModules)));
    $negativeCanaryModules = array_values(array_unique(array_filter($negativeCanaryModules)));
    $sourceEntryModules = array_values(array_unique(array_filter($sourceEntryModules)));
    $missingPositiveModules = array_values(array_diff($expectedPositiveModules, $positiveModules));
    $missingSourceModules = array_values(array_diff($expectedCatalogModules, $sourceEntryModules));
    $contractOk = $missingPositiveModules === []
        && in_array('Weline_WlsTagCanary', $negativeCanaryModules, true)
        && count($entries) === count($expectedCatalogModules)
        && count($sourceEntries) === count($expectedCatalogModules)
        && $missingSourceModules === []
        && $allSourceEntriesReady
        && $allSourceTargetsAcceptable;

    return [
        'contract_ok' => $contractOk,
        'app_count' => count($entries),
        'expected_app_count' => count($expectedCatalogModules),
        'positive_count' => count($positiveModules),
        'expected_positive_count' => count($expectedPositiveModules),
        'positive_modules' => $positiveModules,
        'missing_positive_modules' => $missingPositiveModules,
        'negative_canary_count' => count($negativeCanaryModules),
        'negative_canary_modules' => $negativeCanaryModules,
        'source_entry_count' => count($sourceEntries),
        'expected_source_entry_count' => count($expectedCatalogModules),
        'source_entry_modules' => $sourceEntryModules,
        'missing_source_modules' => $missingSourceModules,
        'source_entries_ready' => $allSourceEntriesReady,
        'source_targets_acceptable' => $allSourceTargetsAcceptable,
    ];
}

/**
 * @return list<string>
 */
function wlsPanelOfficialManifestNormalizeTagValue(mixed $value): array
{
    if (is_scalar($value)) {
        $text = strtolower(trim((string)$value));
        if ($text === '') {
            return [];
        }

        $json = json_decode($text, true);
        if (is_array($json)) {
            return wlsPanelOfficialManifestNormalizeTagValue($json);
        }

        $parts = preg_split('/[,;\s]+/', $text) ?: [];
        return array_values(array_unique(array_filter(array_map(
            static fn(string $part): string => trim($part),
            $parts
        ))));
    }

    if (!is_array($value)) {
        return [];
    }

    $tags = [];
    $code = $value['code'] ?? null;
    if (is_scalar($code)) {
        $tags[] = strtolower(trim((string)$code));
    }

    $type = $value['type'] ?? null;
    $typedValue = $value['value'] ?? $value['name'] ?? null;
    if (is_scalar($type) && is_scalar($typedValue)) {
        $typeText = strtolower(trim((string)$type));
        $valueText = strtolower(trim((string)$typedValue));
        if ($typeText !== '' && $valueText !== '') {
            $tags[] = $typeText . ':' . $valueText;
        }
    }

    $skipKeys = [
        'code',
        'type',
        'value',
        'name',
        'primary',
        'label',
        'labels',
        'description',
        'display_name',
        'version',
        'package',
        'signature',
    ];
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array($key, $skipKeys, true)) {
            continue;
        }
        $tags = array_merge($tags, wlsPanelOfficialManifestNormalizeTagValue($item));
    }

    return array_values(array_unique(array_filter($tags)));
}

/**
 * @param array<string, mixed> $entry
 * @return list<string>
 */
function wlsPanelOfficialManifestEntryTags(array $entry): array
{
    $tags = wlsPanelOfficialManifestNormalizeTagValue($entry['tags'] ?? []);
    $tags = array_merge($tags, wlsPanelOfficialManifestNormalizeTagValue($entry['tags_resolved'] ?? []));
    if (is_array($entry['marketplace_meta'] ?? null)) {
        $tags = array_merge($tags, wlsPanelOfficialManifestNormalizeTagValue($entry['marketplace_meta']['tags'] ?? []));
    }

    return array_values(array_unique(array_filter($tags)));
}

/**
 * @param array<string, mixed> $manifest
 * @return list<array<string, mixed>>
 */
function wlsPanelOfficialManifestEntries(array $manifest): array
{
    $entries = $manifest['apps'] ?? $manifest['modules'] ?? [];
    if (!is_array($entries)) {
        return [];
    }

    $result = [];
    foreach ($entries as $entry) {
        if (is_array($entry)) {
            $result[] = $entry;
        }
    }

    return $result;
}

function wlsPanelOfficialManifestEntryName(array $entry): string
{
    return trim((string)($entry['name'] ?? $entry['module_name'] ?? $entry['slug'] ?? 'unnamed'));
}

function wlsPanelOfficialManifestHasCustomWlsTag(array $tags): bool
{
    foreach ($tags as $tag) {
        if (str_starts_with($tag, 'custom:wls-')) {
            return true;
        }
    }

    return false;
}

function wlsPanelOfficialManifestEntryIsInstallable(array $entry): bool
{
    $value = strtolower(trim((string)($entry['installable_module'] ?? '')));
    return in_array($value, ['yes', 'true', '1'], true);
}

/**
 * @return array{name:string,version:string,dependencies:list<string>}
 */
function wlsPanelOfficialManifestRegisterInfo(string $registerFile): array
{
    $content = wlsPanelOfficialManifestRead($registerFile);
    $name = '';
    $version = '1.0.0';
    $dependencies = [];

    if (preg_match("/Register::register\\s*\\(\\s*Register::MODULE\\s*,\\s*['\"]([^'\"]+)['\"]/s", $content, $matches) === 1) {
        $name = (string)$matches[1];
    }
    if (preg_match("/Register::register\\s*\\([^;]+?__DIR__\\s*,\\s*['\"]([^'\"]+)['\"]/s", $content, $matches) === 1) {
        $version = (string)$matches[1];
    }
    if (preg_match_all("/\\[([^\\]]*)\\]/s", $content, $arrayMatches) && !empty($arrayMatches[1])) {
        $lastArray = end($arrayMatches[1]);
        if (is_string($lastArray)) {
            preg_match_all("/['\"]([A-Za-z][A-Za-z0-9]*(?:_[A-Za-z][A-Za-z0-9]*)+)['\"]/", $lastArray, $dependencyMatches);
            $dependencies = $dependencyMatches[1] ?? [];
        }
    }

    return [
        'name' => $name,
        'version' => $version,
        'dependencies' => array_values(array_unique(array_map('strval', $dependencies))),
    ];
}

/**
 * @return list<string>
 */
function wlsPanelOfficialManifestTagCodesFromMeta(array $meta): array
{
    return wlsPanelOfficialManifestEntryTags($meta);
}

/**
 * @return array<string, string>
 */
function wlsPanelOfficialManifestLocaleText(array $meta, string $field): array
{
    $locales = is_array($meta['i18n']['locales'] ?? null) ? $meta['i18n']['locales'] : [];
    $result = [];
    foreach ($locales as $locale => $data) {
        if (!is_array($data)) {
            continue;
        }
        $text = trim((string)($data[$field] ?? ''));
        if ($text !== '') {
            $result[(string)$locale] = $text;
        }
    }

    return $result;
}

/**
 * @return array{passed:bool,errors:list<string>,manifest_template:array<string,mixed>}
 */
function wlsPanelOfficialManifestBuildTemplate(string $workspaceRoot): array
{
    $errors = [];
    $apps = [];

    foreach (wlsPanelOfficialManifestDefaultWlsModules() as $module) {
        $moduleRoot = wlsPanelOfficialManifestPath($workspaceRoot, $module['dev_dir']);
        $metaPath = wlsPanelOfficialManifestPath($moduleRoot, 'etc/marketplace/meta.json');
        $registerPath = wlsPanelOfficialManifestPath($moduleRoot, 'register.php');
        $meta = wlsPanelOfficialManifestReadJson($metaPath);
        $register = wlsPanelOfficialManifestRegisterInfo($registerPath);
        $name = $register['name'] !== '' ? $register['name'] : $module['module_name'];

        if ($meta === []) {
            $errors[] = 'meta_unreadable:' . $module['module_name'];
            continue;
        }
        if (!is_file($registerPath)) {
            $errors[] = 'register_unreadable:' . $module['module_name'];
            continue;
        }
        if ($name !== $module['module_name']) {
            $errors[] = 'register_name_mismatch:' . $module['module_name'] . ':' . $name;
            continue;
        }

        $tags = wlsPanelOfficialManifestTagCodesFromMeta($meta);
        if (!in_array('module:wls', $tags, true)) {
            $errors[] = 'meta_missing_module_wls:' . $module['module_name'];
        }
        if (!wlsPanelOfficialManifestHasCustomWlsTag($tags)) {
            $errors[] = 'meta_missing_custom_wls_tag:' . $module['module_name'];
        }

        $sourcePrefix = basename(str_replace('\\', '/', $module['source_dir']));
        $apps[] = array_filter([
            'name' => $module['module_name'],
            'display_name' => wlsPanelOfficialManifestLocaleText($meta, 'display_name'),
            'description' => wlsPanelOfficialManifestLocaleText($meta, 'description'),
            'source_dir' => $module['source_dir'],
            'version' => $register['version'],
            'installable_module' => 'yes',
            'category' => 'Server Tools',
            'pricing_type' => 'free',
            'tags' => $tags,
            'surfaces' => array_values((array)($meta['surfaces'] ?? [])),
            'dependencies' => $register['dependencies'],
            'wls_panel' => is_array($meta['wls_panel'] ?? null) ? $meta['wls_panel'] : null,
            'marketplace_meta' => [
                'path' => $sourcePrefix . '/etc/marketplace/meta.json',
            ],
        ], static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    $apps[] = [
        'name' => 'Weline_WlsTagCanary',
        'display_name' => [
            'en_US' => 'WLS Typed Tag Canary',
            'zh_Hans_CN' => 'WLS 类型标签验证项',
        ],
        'description' => [
            'en_US' => 'Non-installable canary item used to prove module:wls-extra does not match module:wls.',
            'zh_Hans_CN' => '用于证明 module:wls-extra 不会匹配 module:wls 的不可安装验证项。',
        ],
        'source_dir' => WLS_PANEL_OFFICIAL_MANIFEST_DEFAULT_SOURCE_ROOT . '/WlsTagCanary',
        'version' => '1.0.0',
        'installable_module' => 'no',
        'category' => 'Server Tools',
        'pricing_type' => 'free',
        'tags' => ['module:wls-extra', 'custom:wls-tag-canary', 'system:false'],
        'surfaces' => ['backend'],
    ];

    return [
        'passed' => $errors === [],
        'errors' => $errors,
        'manifest_template' => [
            'schema_version' => 1,
            'publisher' => [
                'name' => 'Weline Official',
                'slug' => 'weline-official',
            ],
            'categories' => [
                [
                    'name' => 'Server Tools',
                    'description' => 'WLS Panel and server operation modules.',
                    'sort_order' => 10,
                ],
            ],
            'apps' => $apps,
        ],
    ];
}

function wlsPanelOfficialManifestResolveSource(string $sourceRoot, string $sourceDir): string
{
    $sourceDir = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceDir), DIRECTORY_SEPARATOR);
    if ($sourceDir === '' || str_contains($sourceDir, '..') || preg_match('/^[A-Z]:/i', $sourceDir) === 1) {
        return '';
    }

    return wlsPanelOfficialManifestPath($sourceRoot, $sourceDir);
}

/**
 * @param list<array<string, mixed>> $entries
 * @return array{passed:bool,checks:array<string,bool>,errors:list<string>,positive_modules:list<string>,negative_canary_modules:list<string>,bad_negative_canary_modules:list<string>,source_errors:list<string>}
 */
function wlsPanelOfficialManifestValidateEntries(array $entries, string $sourceRoot, bool $strictSource): array
{
    $positiveModules = [];
    $negativeCanaryModules = [];
    $badNegativeCanaryModules = [];
    $positiveMissingCustom = [];
    $positiveNotInstallable = [];
    $canaryInstallable = [];
    $sourceErrors = [];

    foreach ($entries as $entry) {
        $name = wlsPanelOfficialManifestEntryName($entry);
        $tags = wlsPanelOfficialManifestEntryTags($entry);
        $hasWlsTag = in_array('module:wls', $tags, true);
        $hasCanaryTag = in_array('module:wls-extra', $tags, true);

        if ($hasWlsTag) {
            $positiveModules[] = $name;
            if (!wlsPanelOfficialManifestHasCustomWlsTag($tags)) {
                $positiveMissingCustom[] = $name;
            }
            if (!wlsPanelOfficialManifestEntryIsInstallable($entry)) {
                $positiveNotInstallable[] = $name;
            }
        }

        if ($hasCanaryTag) {
            $negativeCanaryModules[] = $name;
            if (wlsPanelOfficialManifestEntryIsInstallable($entry)) {
                $canaryInstallable[] = $name;
            }
            if ($hasWlsTag) {
                $badNegativeCanaryModules[] = $name;
            }
        }

        if (!$strictSource || (!$hasWlsTag && !$hasCanaryTag)) {
            continue;
        }

        $sourceDir = trim((string)($entry['source_dir'] ?? ''));
        $sourcePath = wlsPanelOfficialManifestResolveSource($sourceRoot, $sourceDir);
        if ($sourcePath === '') {
            $sourceErrors[] = $name . ':source_dir_invalid_or_missing';
            continue;
        }

        if (!is_dir($sourcePath)) {
            $sourceErrors[] = $name . ':source_dir_not_found';
            continue;
        }

        if (!is_file(wlsPanelOfficialManifestPath($sourcePath, 'register.php'))) {
            $sourceErrors[] = $name . ':register_php_missing';
        }

        $metaPath = wlsPanelOfficialManifestPath($sourcePath, 'etc/marketplace/meta.json');
        if ($hasWlsTag) {
            if (!is_file($metaPath)) {
                $sourceErrors[] = $name . ':marketplace_meta_missing';
                continue;
            }

            $meta = wlsPanelOfficialManifestReadJson($metaPath);
            $metaTags = wlsPanelOfficialManifestEntryTags($meta);
            if (!in_array('module:wls', $metaTags, true)) {
                $sourceErrors[] = $name . ':marketplace_meta_missing_module_wls';
            }
        } elseif (is_file($metaPath)) {
            $meta = wlsPanelOfficialManifestReadJson($metaPath);
            $metaTags = wlsPanelOfficialManifestEntryTags($meta);
            if (in_array('module:wls', $metaTags, true)) {
                $sourceErrors[] = $name . ':canary_source_meta_must_not_include_module_wls';
            }
        }
    }

    $checks = [
        'apps_present' => $entries !== [],
        'has_wls_positive' => $positiveModules !== [],
        'has_negative_canary' => $negativeCanaryModules !== [],
        'negative_canary_exact' => $negativeCanaryModules !== [] && $badNegativeCanaryModules === [],
        'positive_entries_have_custom_wls_tags' => $positiveModules !== [] && $positiveMissingCustom === [],
        'positive_entries_are_installable' => $positiveModules !== [] && $positiveNotInstallable === [],
        'negative_canary_is_not_installable' => $canaryInstallable === [],
        'strict_source_contract' => !$strictSource || $sourceErrors === [],
    ];

    $errors = [];
    foreach ($checks as $label => $passed) {
        if (!$passed) {
            $errors[] = 'check_failed:' . $label;
        }
    }
    foreach ($positiveMissingCustom as $name) {
        $errors[] = 'positive_missing_custom_wls_tag:' . $name;
    }
    foreach ($positiveNotInstallable as $name) {
        $errors[] = 'positive_not_installable:' . $name;
    }
    foreach ($canaryInstallable as $name) {
        $errors[] = 'negative_canary_must_not_be_installable:' . $name;
    }
    foreach ($badNegativeCanaryModules as $name) {
        $errors[] = 'negative_canary_also_tagged_module_wls:' . $name;
    }
    foreach ($sourceErrors as $sourceError) {
        $errors[] = 'source_error:' . $sourceError;
    }

    return [
        'passed' => $errors === [],
        'checks' => $checks,
        'errors' => $errors,
        'positive_modules' => array_values(array_unique($positiveModules)),
        'negative_canary_modules' => array_values(array_unique($negativeCanaryModules)),
        'bad_negative_canary_modules' => array_values(array_unique($badNegativeCanaryModules)),
        'source_errors' => array_values(array_unique($sourceErrors)),
    ];
}

/**
 * @return array{passed:bool,cases:list<array<string,mixed>>}
 */
function wlsPanelOfficialManifestSelfTest(): array
{
    $validPositive = [
        'name' => 'Weline_FileManager',
        'source_dir' => 'modules/Weline_FileManager',
        'installable_module' => 'yes',
        'tags' => ['module:wls', 'custom:wls-file-manager', 'system:false'],
    ];
    $validCanary = [
        'name' => 'Weline_WlsTagCanary',
        'source_dir' => 'modules/Weline_WlsTagCanary',
        'installable_module' => 'no',
        'tags' => ['module:wls-extra', 'custom:wls-tag-canary', 'system:false'],
    ];
    $cases = [
        [
            'name' => 'valid_manifest_has_positive_and_canary',
            'want_passed' => true,
            'entries' => [$validPositive, $validCanary],
        ],
        [
            'name' => 'missing_canary_fails',
            'want_passed' => false,
            'entries' => [$validPositive],
        ],
        [
            'name' => 'canary_must_not_include_module_wls',
            'want_passed' => false,
            'entries' => [$validPositive, array_replace($validCanary, ['tags' => ['module:wls-extra', 'module:wls']])],
        ],
        [
            'name' => 'module_wls_extra_is_not_positive_wls',
            'want_passed' => false,
            'entries' => [$validCanary],
        ],
        [
            'name' => 'positive_requires_custom_wls_tag',
            'want_passed' => false,
            'entries' => [array_replace($validPositive, ['tags' => ['module:wls', 'category:server-tools']]), $validCanary],
        ],
        [
            'name' => 'positive_must_be_installable',
            'want_passed' => false,
            'entries' => [array_replace($validPositive, ['installable_module' => 'no']), $validCanary],
        ],
        [
            'name' => 'canary_must_not_be_installable',
            'want_passed' => false,
            'entries' => [$validPositive, array_replace($validCanary, ['installable_module' => 'yes'])],
        ],
    ];

    $results = [];
    $allPassed = true;
    foreach ($cases as $case) {
        $result = wlsPanelOfficialManifestValidateEntries((array)$case['entries'], '', false);
        $casePassed = $result['passed'] === $case['want_passed'];
        $allPassed = $allPassed && $casePassed;
        $results[] = [
            'name' => $case['name'],
            'want_passed' => $case['want_passed'],
            'actual_passed' => $result['passed'],
            'case_ok' => $casePassed,
            'errors' => $result['errors'],
            'positive_modules' => $result['positive_modules'],
            'negative_canary_modules' => $result['negative_canary_modules'],
        ];
    }

    return [
        'passed' => $allPassed,
        'cases' => $results,
    ];
}

$args = wlsPanelOfficialManifestParseArgs($argv);

if ((string)($args['template'] ?? '0') === '1') {
    $workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
    $template = wlsPanelOfficialManifestBuildTemplate($workspaceRoot);
    $materialize = wlsPanelOfficialManifestMaterializeTemplate($template['manifest_template'], $args);
    $sourcePlan = wlsPanelOfficialManifestMaterializeSources(
        wlsPanelOfficialManifestDefaultWlsModules(),
        $workspaceRoot,
        (string)($materialize['target'] ?? ''),
        $args
    );
    $catalogSummary = wlsPanelOfficialManifestCatalogSummary($template['manifest_template'], $sourcePlan);
    $passed = $template['passed']
        && $materialize['errors'] === []
        && $sourcePlan['errors'] === []
        && ($catalogSummary['contract_ok'] ?? false) === true;
    wlsPanelOfficialManifestFinish([
        'passed' => $passed,
        'template' => true,
        'workspace_root' => $workspaceRoot,
        'errors' => array_values(array_merge($template['errors'], $materialize['errors'], $sourcePlan['errors'])),
        'materialize' => $materialize,
        'source_plan' => $sourcePlan,
        'catalog_summary' => $catalogSummary,
        'manifest_template' => $template['manifest_template'],
        'side_effects' => $materialize['wrote'] || $sourcePlan['wrote']
            ? 'wrote only requested official AppStore manifest/source targets: no network, no token, no WLS start'
            : 'read-only template output: no sync, no package build, no network, no token, no WLS start, no writes',
    ], $passed ? 0 : WLS_PANEL_OFFICIAL_MANIFEST_EXIT_ASSERTION_FAILED);
}

if ((string)($args['self-test'] ?? '0') === '1') {
    $selfTest = wlsPanelOfficialManifestSelfTest();
    wlsPanelOfficialManifestFinish([
        'passed' => $selfTest['passed'],
        'self_test' => true,
        'cases' => $selfTest['cases'],
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ], $selfTest['passed'] ? 0 : WLS_PANEL_OFFICIAL_MANIFEST_EXIT_ASSERTION_FAILED);
}

$defaultManifest = 'E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json';
$manifestPath = trim((string)($args['manifest'] ?? $defaultManifest));
$sourceRoot = trim((string)($args['source-root'] ?? dirname($manifestPath)));
$strictSource = (string)($args['strict-source'] ?? '1') === '1';

$manifest = wlsPanelOfficialManifestReadJson($manifestPath);
$entries = wlsPanelOfficialManifestEntries($manifest);
$result = wlsPanelOfficialManifestValidateEntries($entries, $sourceRoot, $strictSource);
$manifestReadable = $manifest !== [];
$passed = $manifestReadable && $result['passed'];
$errors = $result['errors'];
if (!$manifestReadable) {
    array_unshift($errors, 'manifest_unreadable_or_invalid_json');
}

wlsPanelOfficialManifestFinish([
    'passed' => $passed,
    'manifest' => $manifestPath,
    'source_root' => $sourceRoot,
    'strict_source' => $strictSource,
    'checks' => [
        'manifest_readable' => $manifestReadable,
    ] + $result['checks'],
    'entry_count' => count($entries),
    'positive_modules' => $result['positive_modules'],
    'negative_canary_modules' => $result['negative_canary_modules'],
    'bad_negative_canary_modules' => $result['bad_negative_canary_modules'],
    'source_errors' => $result['source_errors'],
    'errors' => $errors,
    'side_effects' => 'read-only manifest contract check: no sync, no package build, no network, no token, no WLS start, no writes',
], $passed ? 0 : WLS_PANEL_OFFICIAL_MANIFEST_EXIT_ASSERTION_FAILED);
