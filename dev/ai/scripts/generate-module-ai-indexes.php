<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$appCode = $root . '/app/code';
$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);

if (!is_dir($appCode)) {
    fwrite(STDERR, "Missing app/code directory: {$appCode}\n");
    exit(1);
}

$moduleDirs = glob($appCode . '/*/*', GLOB_ONLYDIR) ?: [];
sort($moduleDirs);

$created = 0;
$updated = 0;
$skipped = 0;
$planned = [];
$marker = '<!-- weline:module-ai-index:auto-generated -->';

$surfaceMap = [
    'Api' => '公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。',
    'Block' => '视图数据块。配合模板输出页面数据，变更前要读对应模板和 layout。',
    'Config' => '配置读取、合并或 schema 支撑。涉及作用域配置时同时读 SystemConfig 文档。',
    'Console' => 'php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。',
    'Controller' => 'HTTP/后台/前台控制器入口。新增控制器后运行 setup:upgrade --route，同步路由。',
    'Controller/Router.php' => 'ModuleRouter 自定义 URL 匹配入口。只有自定义公网路径/动态路由匹配才改这里。',
    'Dto' => '跨层传输结构。变更字段时同步接口/文档。',
    'Helper' => '模块内辅助能力。跨模块不要直接调用未发布 Helper。',
    'Interface' => '模块发布的接口契约。跨模块依赖优先使用这里的稳定契约。',
    'Model' => 'ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。',
    'Observer' => '事件观察者。改事件数据前要检查 doc/event 和触发方。',
    'Plugin' => '插件扩展点。变更前确认被拦截对象和执行顺序。',
    'Queue' => '队列生产/消费入口。读 Queue 技能和模块文档后再改。',
    'Service' => '模块内业务编排层。跨模块读取数据优先发布/使用 w_query。',
    'Setup' => '安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。',
    'Taglib' => '模板标签扩展。改前读 Weline_Taglib 与 Theme 文档。',
    'Ui' => '后台/编辑器 UI 参数、schema 或渲染支撑。',
    'etc' => '模块配置。禁止 routes.xml；路由由控制器和 setup:upgrade --route 生成。',
    'extends' => '模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。',
    'i18n' => '国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。',
    'view/statics' => '静态资源源文件。浏览器业务请求必须走 Weline.Api.*。',
    'view/templates' => '模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。',
    'view/theme' => '主题资源贡献层。读 Weline_Theme/doc/AI-INDEX.md 后按 layout/partial/component/widget 规则开发。',
    'view/tpl' => '模板编译/生成产物。禁止直接修改。',
];

foreach ($moduleDirs as $moduleDir) {
    if (!is_dir($moduleDir)) {
        continue;
    }

    $module = basename($moduleDir);
    $vendor = basename(dirname($moduleDir));
    $moduleCode = $vendor . '_' . $module;
    $docDir = $moduleDir . '/doc';
    $target = $docDir . '/AI-INDEX.md';
    $hasCustom = is_file($target) && !str_contains((string)file_get_contents($target), $marker);

    if ($hasCustom && !$force) {
        $skipped++;
        continue;
    }

    $content = buildModuleIndex($root, $moduleDir, $vendor, $module, $moduleCode, $surfaceMap, $marker);
    $old = is_file($target) ? (string)file_get_contents($target) : null;
    if ($old === $content) {
        $skipped++;
        continue;
    }

    $planned[] = str_replace($root . '/', '', $target);
    if ($dryRun) {
        is_file($target) ? $updated++ : $created++;
        continue;
    }

    if (!is_dir($docDir) && !mkdir($docDir, 0775, true) && !is_dir($docDir)) {
        fwrite(STDERR, "Cannot create doc dir: {$docDir}\n");
        exit(1);
    }

    if (file_put_contents($target, $content) === false) {
        fwrite(STDERR, "Cannot write: {$target}\n");
        exit(1);
    }

    is_file($target) && $old !== null ? $updated++ : $created++;
}

echo "created={$created} updated={$updated} skipped={$skipped}\n";
foreach ($planned as $path) {
    echo $path . "\n";
}

function buildModuleIndex(string $root, string $moduleDir, string $vendor, string $module, string $moduleCode, array $surfaceMap, string $marker): string
{
    $relativeModuleDir = rel($root, $moduleDir);
    $readme = $moduleDir . '/doc/README.md';
    $hasReadme = is_file($readme);
    $surfaces = detectSurfaces($moduleDir, $surfaceMap);
    $docs = scanDocs($root, $moduleDir . '/doc');
    $entryFiles = detectEntryFiles($root, $moduleDir);
    $sourceHints = buildSourceHints($moduleDir);
    $specificDocs = buildSpecificDocHints($moduleDir, $moduleCode, $surfaces);

    $lines = [];
    $lines[] = $marker;
    $lines[] = "# {$moduleCode} AI 开发入口";
    $lines[] = "";
    $lines[] = "> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。";
    $lines[] = "";
    $lines[] = "## 必读顺序";
    $lines[] = "";
    $lines[] = "1. `AI-ENTRY.md`";
    $lines[] = "2. `dev/ai/global-constraints.md`";
    $lines[] = "3. `dev/ai/diagrams/08-module-docs-index.txt`";
    $lines[] = "4. 本文件：`{$relativeModuleDir}/doc/AI-INDEX.md`";
    if ($hasReadme) {
        $lines[] = "5. 模块说明：`{$relativeModuleDir}/doc/README.md`";
        $next = 6;
    } else {
        $lines[] = "5. 本模块暂未发现 `doc/README.md`；只能把本文件当作代码结构索引，开发前必须补读相关源码。";
        $next = 6;
    }
    if ($specificDocs !== []) {
        foreach ($specificDocs as $doc) {
            $lines[] = "{$next}. {$doc}";
            $next++;
        }
    }
    $lines[] = "{$next}. 只读取本次任务相关源码、配置和验证入口";
    $lines[] = "";
    $lines[] = "## 模块身份";
    $lines[] = "";
    $lines[] = "- 模块代码：`{$moduleCode}`";
    $lines[] = "- 目录：`{$relativeModuleDir}`";
    $lines[] = "- Vendor：`{$vendor}`";
    $lines[] = "- Module：`{$module}`";
    $lines[] = "";
    $lines[] = "## 代码面清单";
    $lines[] = "";

    if ($entryFiles !== []) {
        $lines[] = "入口/配置文件：";
        foreach ($entryFiles as $entryFile) {
            $lines[] = "- `{$entryFile}`";
        }
        $lines[] = "";
    }

    if ($surfaces === []) {
        $lines[] = "- 当前未识别出常见代码面；开发前先阅读模块根目录文件。";
    } else {
        foreach ($surfaces as $surface) {
            $lines[] = "- `{$surface['path']}`：{$surface['description']} 文件数：{$surface['files']}";
        }
    }

    if ($sourceHints !== []) {
        $lines[] = "";
        $lines[] = "## 从源码识别到的开发提示";
        $lines[] = "";
        foreach ($sourceHints as $hint) {
            $lines[] = "- {$hint}";
        }
    }

    $lines[] = "";
    $lines[] = "## doc 目录";
    $lines[] = "";
    if ($docs === []) {
        $lines[] = "- 未发现除本文件外的模块文档。行为变更、接口变更或跨模块约定变更时，先在本模块 `doc/` 下补长期文档。";
    } else {
        foreach ($docs as $doc) {
            $lines[] = "- `{$doc}`";
        }
    }

    $lines[] = "";
    $lines[] = "## 开发前门禁";
    $lines[] = "";
    $lines[] = "- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。";
    $lines[] = "- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。";
    $lines[] = "- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|{$moduleCode}> [operation]` 或对应 `w_query` 帮助。";
    $lines[] = "- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。";
    $lines[] = "- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。";
    $lines[] = "- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。";
    $lines[] = "";

    return implode("\n", $lines);
}

function detectSurfaces(string $moduleDir, array $surfaceMap): array
{
    $surfaces = [];
    foreach ($surfaceMap as $relative => $description) {
        $path = $moduleDir . '/' . $relative;
        if (!file_exists($path)) {
            continue;
        }

        $surfaces[] = [
            'path' => $relative,
            'description' => $description,
            'files' => is_file($path) ? 1 : countFiles($path),
        ];
    }

    return $surfaces;
}

function countFiles(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $count++;
        }
    }

    return $count;
}

function scanDocs(string $root, string $docDir): array
{
    if (!is_dir($docDir)) {
        return [];
    }

    $docs = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($docDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if (str_ends_with($path, '/AI-INDEX.md')) {
            continue;
        }
        if (!preg_match('/\.(md|txt|json|xml|csv)$/i', $path)) {
            continue;
        }

        $docs[] = rel($root, $path);
    }

    sort($docs);
    if (count($docs) > 80) {
        $remaining = count($docs) - 80;
        $docs = array_slice($docs, 0, 80);
        $docs[] = "... 另有 {$remaining} 个文档，请按任务在该模块 doc/ 下继续查找";
    }

    return $docs;
}

function detectEntryFiles(string $root, string $moduleDir): array
{
    $candidates = [
        'registration.php',
        'module.xml',
        'etc/module.xml',
        'etc/di.xml',
        'etc/events.xml',
        'etc/adminhtml/menu.xml',
        'etc/backend/menu.xml',
        'etc/frontend/events.xml',
        'etc/backend/events.xml',
        '.module_config.json',
        'composer.json',
    ];
    $entries = [];
    foreach ($candidates as $candidate) {
        $path = $moduleDir . '/' . $candidate;
        if (is_file($path)) {
            $entries[] = rel($root, $path);
        }
    }

    return $entries;
}

function buildSourceHints(string $moduleDir): array
{
    $hints = [];
    if (is_file($moduleDir . '/Controller/Router.php')) {
        $hints[] = "存在 `Controller/Router.php`，说明模块可能发布自定义 URL 匹配；不要用 `routes.xml` 代替。";
    }
    if (is_dir($moduleDir . '/view/theme')) {
        $hints[] = "存在 `view/theme`，说明该模块向主题资源 catalog 贡献 layout/partial/component/widget/asset。";
    }
    if (is_dir($moduleDir . '/view/templates')) {
        $hints[] = "存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。";
    }
    if (is_dir($moduleDir . '/view/tpl')) {
        $hints[] = "存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。";
    }
    if (is_dir($moduleDir . '/extends/module')) {
        $hints[] = "存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。";
    } elseif (is_dir($moduleDir . '/extends')) {
        $hints[] = "存在 `extends`，改扩展前先读本模块 doc/extends 或源码中的扫描规则。";
    }
    if (is_dir($moduleDir . '/i18n')) {
        $hints[] = "存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。";
    }

    $queryFiles = findPhpFilesMatching($moduleDir, ['QueryProvider', 'Query\\Provider', 'frontend: true', 'frontend=true']);
    if ($queryFiles !== []) {
        $hints[] = "识别到 QueryProvider 相关 PHP 文件：" . implode('、', array_slice($queryFiles, 0, 8)) . (count($queryFiles) > 8 ? ' 等' : '') . "；前端/跨模块读数据先查 query 帮助。";
    }

    return $hints;
}

function findPhpFilesMatching(string $moduleDir, array $needles): array
{
    $matches = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                $path = str_replace('\\', '/', $file->getPathname());
                foreach (['/doc/', '/test/', '/tests/', '/view/tpl/'] as $skip) {
                    if (str_contains($path, $skip)) {
                        return false;
                    }
                }
                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if (!is_string($content) || $content === '') {
            continue;
        }
        foreach ($needles as $needle) {
            if (stripos($content, $needle) !== false) {
                $matches[] = str_replace('\\', '/', substr($file->getPathname(), strlen($moduleDir) + 1));
                break;
            }
        }
    }

    sort($matches);
    return $matches;
}

function buildSpecificDocHints(string $moduleDir, string $moduleCode, array $surfaces): array
{
    $surfacePaths = array_column($surfaces, 'path');
    $docs = [];
    if ($moduleCode === 'Weline_Theme') {
        $docs[] = "`app/code/Weline/Theme/doc/开发/Theme开发总指南.md`";
        $docs[] = "`app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`";
    }
    if (in_array('view/theme', $surfacePaths, true) || in_array('view/templates', $surfacePaths, true)) {
        if ($moduleCode !== 'Weline_Theme') {
            $docs[] = "`app/code/Weline/Theme/doc/AI-INDEX.md`";
        }
        if ($moduleCode !== 'Weline_Frontend') {
            $docs[] = "`app/code/Weline/Frontend/doc/AI-INDEX.md`";
        }
    }
    if (in_array('Taglib', $surfacePaths, true) || $moduleCode === 'Weline_Taglib') {
        if ($moduleCode !== 'Weline_Taglib') {
            $docs[] = "`app/code/Weline/Taglib/doc/AI-INDEX.md`";
        }
    }
    if ($moduleCode === 'Weline_Widget' || str_contains(str_replace('\\', '/', $moduleDir), '/extends/module/Weline_Widget')) {
        if ($moduleCode !== 'Weline_Widget') {
            $docs[] = "`app/code/Weline/Widget/doc/AI-INDEX.md`";
        }
    }

    return array_values(array_unique($docs));
}

function rel(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $path = str_replace('\\', '/', $path);
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}
