<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$appCode = $root . '/app/code';
$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
$marker = '<!-- weline:module-readme:auto-generated -->';

if (!is_dir($appCode)) {
    fwrite(STDERR, "Missing app/code directory: {$appCode}\n");
    exit(1);
}

$surfaceMap = [
    'Api' => '公开接口契约与对外能力发布面。',
    'Block' => '视图数据块与模板输出辅助层。',
    'Config' => '配置读取、合并与 schema 支撑。',
    'Console' => 'php bin/w 命令入口。',
    'Controller' => '前后台 HTTP 控制器与路由入口。',
    'Controller/Api' => 'API 控制器入口；涉及外部接入时同步检查 API 契约与安全约束。',
    'Controller/Backend' => '后台控制器入口；变更前同步检查 ACL、菜单和返回路径。',
    'Dto' => '跨层传输结构。',
    'Helper' => '模块内辅助能力。',
    'Interface' => '已发布接口契约；跨模块依赖优先使用这里。',
    'Model' => 'ORM 模型与字段 schema。',
    'Observer' => '事件观察者与订阅逻辑。',
    'Plugin' => '插件扩展点。',
    'Queue' => '队列生产/消费入口。',
    'Schema' => '结构定义、约束或类型声明。',
    'Service' => '业务编排与模块服务层。',
    'Setup' => '安装/升级装配。',
    'Taglib' => '模板标签扩展。',
    'Ui' => '后台/编辑器 UI 或参数 schema。',
    'etc' => '模块配置。',
    'extends' => '扩展声明与挂载点。',
    'i18n' => '国际化资源。',
    'view/blocks' => '区块模板/片段视图。',
    'view/statics' => '浏览器静态资源源文件。',
    'view/templates' => '模块模板源文件。',
    'view/theme' => '主题资源贡献层。',
    'view/tpl' => '模板编译/生成产物。',
];

$moduleDirs = glob($appCode . '/*/*', GLOB_ONLYDIR) ?: [];
sort($moduleDirs);

$created = 0;
$updated = 0;
$skipped = 0;
$planned = [];

foreach ($moduleDirs as $moduleDir) {
    $readme = $moduleDir . '/doc/README.md';
    if (is_file($readme)) {
        $content = (string)file_get_contents($readme);
        if (!str_contains($content, $marker) || !$force) {
            $skipped++;
            continue;
        }
    }

    $content = buildReadme($root, $moduleDir, $surfaceMap, $marker);
    $old = is_file($readme) ? (string)file_get_contents($readme) : null;
    if ($old === $content) {
        $skipped++;
        continue;
    }

    $planned[] = rel($root, $readme);
    if ($dryRun) {
        is_file($readme) ? $updated++ : $created++;
        continue;
    }

    $docDir = dirname($readme);
    if (!is_dir($docDir) && !mkdir($docDir, 0775, true) && !is_dir($docDir)) {
        fwrite(STDERR, "Cannot create doc dir: {$docDir}\n");
        exit(1);
    }

    if (file_put_contents($readme, $content) === false) {
        fwrite(STDERR, "Cannot write: {$readme}\n");
        exit(1);
    }

    $old === null ? $created++ : $updated++;
}

echo "created={$created} updated={$updated} skipped={$skipped}\n";
foreach ($planned as $path) {
    echo $path . "\n";
}

function buildReadme(string $root, string $moduleDir, array $surfaceMap, string $marker): string
{
    $vendor = basename(dirname($moduleDir));
    $module = basename($moduleDir);
    $moduleCode = $vendor . '_' . $module;
    $relativeModuleDir = rel($root, $moduleDir);
    $surfaces = detectSurfaces($moduleDir, $surfaceMap);
    $docFiles = scanDocFiles($root, $moduleDir . '/doc');
    $entryFiles = detectEntryFiles($root, $moduleDir);
    $notes = buildNotes($moduleDir, $moduleCode, $surfaces);
    $related = relatedDocs($moduleCode, $surfaces);

    $lines = [];
    $lines[] = $marker;
    $lines[] = "# {$moduleCode} 模块文档";
    $lines[] = "";
    $lines[] = "> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。";
    $lines[] = "";
    $lines[] = "## 当前入口";
    $lines[] = "";
    $lines[] = "开发前先读：";
    $lines[] = "";
    $lines[] = "1. `{$relativeModuleDir}/doc/AI-INDEX.md`";
    $lines[] = "2. `dev/ai/diagrams/08-module-docs-index.txt`";
    $lines[] = "3. `dev/ai/global-constraints.md`";
    foreach ($related as $index => $doc) {
        $lines[] = (4 + $index) . ". `{$doc}`";
    }
    $lines[] = "";
    $lines[] = "## 模块定位";
    $lines[] = "";
    $lines[] = "- 模块代码：`{$moduleCode}`";
    $lines[] = "- 目录：`{$relativeModuleDir}`";
    $lines[] = "- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。";
    $lines[] = "";
    $lines[] = "## 代码面概览";
    $lines[] = "";

    if ($entryFiles !== []) {
        $lines[] = "入口文件：";
        foreach ($entryFiles as $entryFile) {
            $lines[] = "- `{$entryFile}`";
        }
        $lines[] = "";
    }

    if ($surfaces === []) {
        $lines[] = "- 当前未识别出常见代码面；开发前请先阅读模块根目录源码。";
    } else {
        foreach ($surfaces as $surface) {
            $lines[] = "- `{$surface['path']}`：{$surface['description']} 文件数：{$surface['files']}";
        }
    }

    $lines[] = "";
    $lines[] = "## 开发关注点";
    $lines[] = "";
    foreach ($notes as $note) {
        $lines[] = "- {$note}";
    }

    $lines[] = "";
    $lines[] = "## 本模块文档资产";
    $lines[] = "";
    if ($docFiles === []) {
        $lines[] = "- 当前除 `AI-INDEX.md` 外没有其他模块文档。后续一旦涉及稳定行为、接口或配置约定，请把长期说明补到本目录。";
    } else {
        foreach ($docFiles as $doc) {
            $lines[] = "- `{$doc}`";
        }
    }

    $lines[] = "";
    $lines[] = "## 维护规则";
    $lines[] = "";
    $lines[] = "- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。";
    $lines[] = "- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。";
    $lines[] = "- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。";
    $lines[] = "- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。";
    $lines[] = "- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。";
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

function scanDocFiles(string $root, string $docDir): array
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
        $base = basename($path);
        if ($base === 'AI-INDEX.md' || $base === 'README.md') {
            continue;
        }
        if (!preg_match('/\.(md|txt|json|xml|csv)$/i', $path)) {
            continue;
        }
        $docs[] = rel($root, $path);
    }

    sort($docs);
    if (count($docs) > 30) {
        $remaining = count($docs) - 30;
        $docs = array_slice($docs, 0, 30);
        $docs[] = "... 另有 {$remaining} 个文档，请按任务继续在本模块 doc/ 下查找";
    }
    return $docs;
}

function detectEntryFiles(string $root, string $moduleDir): array
{
    $candidates = [
        'registration.php',
        'composer.json',
        '.module_config.json',
        'etc/module.xml',
        'etc/di.xml',
        'etc/events.xml',
        'etc/backend/menu.xml',
        'etc/adminhtml/menu.xml',
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

function buildNotes(string $moduleDir, string $moduleCode, array $surfaces): array
{
    $paths = array_column($surfaces, 'path');
    $notes = [];

    if (in_array('Controller', $paths, true)) {
        $notes[] = "存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。";
    }
    if (in_array('Controller/Api', $paths, true)) {
        $notes[] = "存在 `Controller/Api`，说明模块可能对外暴露 API；补文档时应记录认证、参数和边界。";
    }
    if (in_array('Controller/Backend', $paths, true)) {
        $notes[] = "存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。";
    }
    if (in_array('Model', $paths, true)) {
        $notes[] = "存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。";
    }
    if (in_array('Service', $paths, true)) {
        $notes[] = "存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。";
    }
    if (in_array('Observer', $paths, true)) {
        $notes[] = "存在 `Observer/`，改事件数据前应同步检查触发点和消费点。";
    }
    if (in_array('Ui', $paths, true)) {
        $notes[] = "存在 `Ui/`，说明模块带后台或编辑器参数 schema；改字段/组件时同步检查 UI 消费层。";
    }
    if (in_array('view/templates', $paths, true) || in_array('view/blocks', $paths, true)) {
        $notes[] = "存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。";
    }
    if (in_array('view/statics', $paths, true)) {
        $notes[] = "存在浏览器静态资源；业务请求必须走 `Weline.Api.*`，不要直接写 raw fetch/ajax。";
    }
    if (in_array('view/theme', $paths, true)) {
        $notes[] = "存在 `view/theme`，说明模块向主题资源层贡献 layout/partial/component/widget；先读 Theme 模块文档。";
    }
    if (in_array('i18n', $paths, true)) {
        $notes[] = "存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。";
    }
    if (is_dir($moduleDir . '/test') || is_dir($moduleDir . '/tests')) {
        $notes[] = "存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。";
    }
    if ($moduleCode === 'Weline_Eav') {
        $notes[] = "EAV 类模块涉及动态属性、实体、UI 表单和数据持久化边界，后续适合继续补实体/属性/值存储的专项文档。";
    }

    if ($notes === []) {
        $notes[] = "当前主要依赖源码结构理解模块边界；如本模块后续承担稳定业务能力，建议继续把职责和接口沉淀到 `doc/`。";
    }

    return $notes;
}

function relatedDocs(string $moduleCode, array $surfaces): array
{
    $paths = array_column($surfaces, 'path');
    $docs = [];
    if (in_array('view/templates', $paths, true) || in_array('view/theme', $paths, true) || in_array('view/statics', $paths, true)) {
        $docs[] = 'app/code/Weline/Theme/doc/AI-INDEX.md';
        $docs[] = 'app/code/Weline/Frontend/doc/AI-INDEX.md';
    }
    if (in_array('Taglib', $paths, true)) {
        $docs[] = 'app/code/Weline/Taglib/doc/AI-INDEX.md';
    }
    if ($moduleCode === 'Weline_Eav') {
        $docs[] = 'dev/ai/skills/框架核心工程师-ORM与数据模型/SKILL.md';
    }
    return array_values(array_unique($docs));
}

function rel(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $path = str_replace('\\', '/', $path);
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}
