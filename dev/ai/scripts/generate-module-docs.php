#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Weline 模块文档生成器。
 *
 * 生成两类自动快照文档（均带 auto-generated 标记，可安全重生成）：
 *   - app/code/{Vendor}/{Module}/doc/AI-INDEX.md      AI 进入模块前的导航入口
 *   - app/code/{Vendor}/{Module}/doc/功能现状.md       代码能力快照
 * 以及一份跨模块总览：
 *   - app/code/Weline/Ai/doc/模块文档总览.md
 *
 * 安全约束（重要）：
 *   1. 只重写带 auto-generated 标记的文件；手写文件（无标记）默认跳过，需 --force 才覆盖。
 *   2. 默认跳过工作区中已被其它会话修改（git 脏）的目标文件，需 --include-dirty 才覆盖。
 *   3. 保留自动生成文件里人工追加的模板外 `##` 章节。
 *
 * 用法：
 *   php dev/ai/scripts/generate-module-docs.php [--mode=all|ai-index|capability|overview]
 *        [--module=Weline_Cron] [--dry-run] [--check] [--force] [--include-dirty] [--quiet]
 *
 *   --dry-run   只打印将要写入的路径，不落盘
 *   --check     只检查漂移；有漂移退出码 1（供 CI/契约测试使用）
 *   --force     允许覆盖手写（无标记）文件
 */

const MARKER_AI_INDEX = 'weline:module-ai-index:auto-generated';
const MARKER_CAPABILITY = 'weline:module-capability-snapshot:auto-generated';
const GENERATOR_PATH = 'dev/ai/scripts/generate-module-docs.php';

/** AI-INDEX 模板章节（用于识别人工追加章节）。 */
const AI_INDEX_TEMPLATE_SECTIONS = [
    '必读顺序',
    '模块身份',
    '代码面清单',
    '从源码识别到的开发提示',
    'doc 目录',
    '开发前门禁',
];

/** 功能现状模板章节。 */
const CAPABILITY_TEMPLATE_SECTIONS = [
    '快照信息',
    '注册与依赖边界',
    '当前能力面',
    '关键入口摘要',
    '当前文档入口',
    '能确认与不能确认的边界',
    '维护规则',
];

/** AI-INDEX「代码面清单」的目录键与说明，顺序即输出顺序。 */
const CODE_FACES = [
    'Api' => ['paths' => ['Api'], 'desc' => '公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。'],
    'Block' => ['paths' => ['Block'], 'desc' => '视图数据块。配合模板输出页面数据，变更前要读对应模板和 layout。'],
    'Config' => ['paths' => ['Config'], 'desc' => '配置读取、合并或 schema 支撑。涉及作用域配置时同时读 SystemConfig 文档。'],
    'Console' => ['paths' => ['Console'], 'desc' => 'php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。'],
    'Controller' => ['paths' => ['Controller'], 'desc' => 'HTTP/后台/前台控制器入口。新增控制器后优先跑完整 `setup:upgrade`；仅需重建路由图时可用 `setup:upgrade --route`（选填）。'],
    'Controller/Router.php' => ['paths' => ['Controller/Router.php'], 'desc' => 'ModuleRouter 自定义 URL 匹配入口。只有自定义公网路径/动态路由匹配才改这里。'],
    'Dto' => ['paths' => ['Dto'], 'desc' => '跨层传输结构。变更字段时同步接口/文档。'],
    'Helper' => ['paths' => ['Helper'], 'desc' => '模块内辅助能力。跨模块不要直接调用未发布 Helper。'],
    'Interface' => ['paths' => ['Interface'], 'desc' => '模块发布的接口契约。跨模块依赖优先使用这里的稳定契约。'],
    'Model' => ['paths' => ['Model'], 'desc' => 'ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。'],
    'Observer' => ['paths' => ['Observer'], 'desc' => '事件观察者。改事件数据前要检查 doc/event 和触发方。'],
    'Plugin' => ['paths' => ['Plugin'], 'desc' => '插件扩展点。变更前确认被拦截对象和执行顺序。'],
    'Queue' => ['paths' => ['Queue'], 'desc' => '队列生产/消费入口。读 Queue 技能和模块文档后再改。'],
    'Service' => ['paths' => ['Service'], 'desc' => '模块内业务编排层。跨模块读取数据优先发布/使用 w_query。'],
    'Setup' => ['paths' => ['Setup'], 'desc' => '安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。'],
    'Taglib' => ['paths' => ['Taglib'], 'desc' => '模板标签扩展。改前读 Weline_Taglib 与 Theme 文档。'],
    'Ui' => ['paths' => ['Ui'], 'desc' => '后台/编辑器 UI 参数、schema 或渲染支撑。'],
    'etc' => ['paths' => ['etc'], 'desc' => '模块配置。禁止 routes.xml；路由由控制器发现，完整 `setup:upgrade` 会同步；仅路由图变更时可用 `--route`（选填）。'],
    'extends' => ['paths' => ['extends'], 'desc' => '模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。'],
    'i18n' => ['paths' => ['i18n'], 'desc' => '国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。'],
    'view/statics' => ['paths' => ['view/statics'], 'desc' => '静态资源源文件。浏览器业务请求必须走 Weline.Api.*。'],
    'view/templates' => ['paths' => ['view/templates'], 'desc' => '模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。'],
    'view/theme' => ['paths' => ['view/theme'], 'desc' => '主题资源贡献层。读 Weline_Theme/doc/AI-INDEX.md 后按 layout/partial/component/widget 规则开发。'],
    'view/tpl' => ['paths' => ['view/tpl'], 'desc' => '模板编译/生成产物。禁止直接修改。'],
];

/** AI-INDEX「入口/配置文件」候选，顺序即输出顺序。 */
const ENTRY_FILES = ['composer.json', 'etc/backend/menu.xml', 'etc/module.xml', '.module_config.json'];

/**
 * 功能现状「当前能力面」分类 → 匹配规则。
 * 每项为 glob 列表（相对模块根）；`*` 匹配一层，`**` 匹配多层。
 */
const CAPABILITY_FACES = [
    'HTTP 控制器与路由' => ['Controller/**'],
    'CLI 命令' => ['Console/**'],
    '公开契约与查询入口' => ['Api/**', 'extends/module/*/Query/**'],
    '服务与业务编排' => ['Service/**', 'Helper/**'],
    '模型与安装升级' => ['Model/**', 'Setup/**'],
    '事件、队列与扩展' => ['Observer/**', 'Queue/**', 'Plugin/**', 'extends/module/**'],
    '视图、主题、Taglib 与 UI' => ['view/templates/**', 'view/theme/**', 'Taglib/**', 'Block/**', 'Ui/**'],
    '配置与国际化' => ['composer.json', 'etc/**', 'i18n/**'],
    '测试与验收资产' => ['test/**', 'Test/**'],
    '静态资源源文件' => ['view/statics/**'],
];

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $options = parseOptions(array_slice($argv, 1));
    $root = repoRoot();

    $modules = discoverModules($root);
    if ($options['module'] !== null) {
        $modules = array_values(array_filter(
            $modules,
            static fn (array $m): bool => $m['code'] === $options['module'],
        ));
        if ($modules === []) {
            fwrite(STDERR, "未找到模块：{$options['module']}\n");
            return 2;
        }
    }

    $dirty = $options['include_dirty'] ? [] : gitDirtyPaths($root);

    $stats = ['written' => 0, 'unchanged' => 0, 'skipped_handwritten' => 0, 'skipped_dirty' => 0, 'created' => 0];
    $drift = [];    // 内容漂移（会被写入的目标）
    $skipped = [];  // 主动跳过（手写 / 他会话在飞 / 显式保护）

    if ($options['mode'] === 'all' || $options['mode'] === 'ai-index') {
        foreach ($modules as $module) {
            generateAiIndex($root, $module, $options, $dirty, $stats, $drift, $skipped);
        }
    }

    if ($options['mode'] === 'all' || $options['mode'] === 'capability') {
        foreach ($modules as $module) {
            generateCapability($root, $module, $options, $dirty, $stats, $drift, $skipped);
        }
    }

    // 总览覆盖全部模块；指定 --module 时不做全量总览，避免单模块运行重写全局文件。
    if (($options['mode'] === 'all' || $options['mode'] === 'overview') && $options['module'] === null) {
        generateOverview($root, $modules, $options, $dirty, $stats, $drift, $skipped);
    }

    if (!$options['quiet']) {
        printf(
            "写入 %d，新建 %d，未变 %d，跳过(手写) %d，跳过(保护/脏) %d\n",
            $stats['written'],
            $stats['created'],
            $stats['unchanged'],
            $stats['skipped_handwritten'],
            $stats['skipped_dirty'],
        );
        if ($drift !== [] && ($options['check'] || $options['dry_run'])) {
            echo "\n内容漂移（将被写入）：\n";
            foreach ($drift as $path) {
                echo "  - {$path}\n";
            }
        }
        if ($skipped !== [] && ($options['check'] || $options['dry_run'])) {
            echo "\n主动跳过：\n";
            foreach ($skipped as $path) {
                echo "  - {$path}\n";
            }
        }
    }

    // 仅「内容漂移」影响 --check 退出码；主动跳过不算漂移。
    if ($options['check'] && ($stats['written'] + $stats['created']) > 0) {
        return 1;
    }

    return 0;
}

/**
 * @param list<string> $args
 * @return array{mode:string,module:?string,dry_run:bool,check:bool,force:bool,include_dirty:bool,quiet:bool}
 */
function parseOptions(array $args): array
{
    $options = [
        'mode' => 'all',
        'module' => null,
        'dry_run' => false,
        'check' => false,
        'force' => false,
        'include_dirty' => false,
        'protect' => [],
        'quiet' => false,
    ];

    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--check') {
            $options['check'] = true;
        } elseif ($arg === '--force') {
            $options['force'] = true;
        } elseif ($arg === '--include-dirty') {
            $options['include_dirty'] = true;
        } elseif ($arg === '--quiet') {
            $options['quiet'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "用法：php " . GENERATOR_PATH . " [--mode=all|ai-index|capability|overview]"
                . " [--module=Weline_X] [--dry-run] [--check] [--force] [--include-dirty]"
                . " [--protect=路径] [--quiet]\n";
            exit(0);
        } elseif (str_starts_with($arg, '--mode=')) {
            $mode = substr($arg, 7);
            if (!in_array($mode, ['all', 'ai-index', 'capability', 'overview'], true)) {
                fwrite(STDERR, "非法 --mode：{$mode}\n");
                exit(2);
            }
            $options['mode'] = $mode;
        } elseif (str_starts_with($arg, '--module=')) {
            $options['module'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--protect=')) {
            foreach (explode(',', substr($arg, 10)) as $path) {
                $path = trim($path);
                if ($path !== '') {
                    $options['protect'][$path] = true;
                }
            }
        } else {
            fwrite(STDERR, "未知参数：{$arg}\n");
            exit(2);
        }
    }

    return $options;
}

function repoRoot(): string
{
    $dir = __DIR__;
    // dev/ai/scripts -> 仓库根
    return dirname($dir, 3);
}

/**
 * @return list<array{code:string,vendor:string,module:string,dir:string,doc:string,rel:string}>
 */
function discoverModules(string $root): array
{
    $modules = [];
    foreach (glob($root . '/app/code/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $doc = $dir . '/doc';
        if (!is_dir($doc)) {
            continue;
        }
        $module = basename($dir);
        $vendor = basename(dirname($dir));
        $rel = 'app/code/' . $vendor . '/' . $module;
        $modules[] = [
            'code' => $vendor . '_' . $module,
            'vendor' => $vendor,
            'module' => $module,
            'dir' => $dir,
            'doc' => $doc,
            'rel' => $rel,
        ];
    }
    usort($modules, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

    return $modules;
}

/** @return array<string,true> 仓库相对路径集合 */
function gitDirtyPaths(string $root): array
{
    // 必须用 -z：默认输出会对非 ASCII 路径加引号并做八进制转义，导致中文路径匹配不上。
    $command = 'git -C ' . escapeshellarg($root) . ' status --porcelain -z 2>/dev/null';
    $output = shell_exec($command);
    if (!is_string($output) || $output === '') {
        return [];
    }

    $dirty = [];
    $records = explode("\0", $output);
    $count = count($records);
    for ($i = 0; $i < $count; $i++) {
        $record = $records[$i];
        if ($record === '') {
            continue;
        }
        $status = substr($record, 0, 2);
        $path = substr($record, 3);
        // 改名/复制记录：下一段是原路径，跳过。
        if ($status[0] === 'R' || $status[0] === 'C') {
            $i++;
        }
        $dirty[$path] = true;
    }

    return $dirty;
}

/**
 * 写文件，带安全闸门。返回 'created' | 'written' | 'unchanged' | 'skipped_handwritten' | 'skipped_dirty'。
 *
 * @param array<string,true> $dirty
 * @param array<string,int>  $stats
 * @param list<string>       $drift   内容漂移（将被写入）
 * @param list<string>       $skipped 主动跳过
 */
function writeGuarded(
    string $root,
    string $absPath,
    string $content,
    string $marker,
    array $options,
    array $dirty,
    array &$stats,
    array &$drift,
    array &$skipped,
): string {
    $relPath = substr($absPath, strlen($root) + 1);
    $exists = is_file($absPath);
    $current = $exists ? (string) file_get_contents($absPath) : null;

    if ($exists && $current === $content) {
        $stats['unchanged']++;
        return 'unchanged';
    }

    if ($exists && !str_contains($current, $marker) && !$options['force']) {
        $stats['skipped_handwritten']++;
        $skipped[] = $relPath . '（手写文件，未覆盖）';
        return 'skipped_handwritten';
    }

    if ($exists && isset($options['protect'][$relPath])) {
        $stats['skipped_dirty']++;
        $skipped[] = $relPath . '（显式保护，未覆盖）';
        return 'skipped_dirty';
    }

    if ($exists && isset($dirty[$relPath]) && !$options['include_dirty']) {
        $stats['skipped_dirty']++;
        $skipped[] = $relPath . '（工作区脏，其它会话在飞，未覆盖）';
        return 'skipped_dirty';
    }

    if ($options['dry_run'] || $options['check']) {
        $drift[] = $relPath;
        $stats[$exists ? 'written' : 'created']++;
        return $exists ? 'written' : 'created';
    }

    if (!is_dir(dirname($absPath)) && !mkdir(dirname($absPath), 0o775, true) && !is_dir(dirname($absPath))) {
        throw new RuntimeException('无法创建目录：' . dirname($absPath));
    }

    if (file_put_contents($absPath, $content) === false) {
        throw new RuntimeException('写入失败：' . $absPath);
    }

    $stats[$exists ? 'written' : 'created']++;

    return $exists ? 'written' : 'created';
}

/**
 * 提取人工内容，用于重生成时保留：
 *   - preamble：首个 `##` 之前、剔除生成器样板后剩余的手写内容（例如人工更新记录）
 *   - sections：模板外的 `##` 章节（含正文）
 *
 * @param list<string> $templateSections
 * @return array{preamble:?string,sections:list<string>}
 */
function preservedSections(?string $existing, array $templateSections): array
{
    if ($existing === null || $existing === '') {
        return ['preamble' => null, 'sections' => []];
    }

    // 注意：不能用 /\R/ —— 无 u 修饰符时它按字节匹配，会把 UTF-8 汉字里的
    // 0x85（NEL）当换行拆断多字节字符，导致章节名被截断、误判成手写章节。
    $lines = preg_split('/\r\n|\n|\r/', $existing) ?: [];

    $boilerplate = [
        '/^<!--\s*weline:module-(ai-index|capability-snapshot):auto-generated\s*-->$/',
        '/^#\s+\S+\s+(功能现状|AI 开发入口)$/',
        '/^>\s*本文是根据当前工作树自动生成的代码能力快照/',
        '/^>\s*本文件由\s*`[^`]+`\s*根据当前代码结构生成/',
    ];

    $preambleLines = [];
    $blocks = [];
    $current = null;
    $buffer = [];

    foreach ($lines as $line) {
        if (preg_match('/^##\s+(.+?)\s*$/', $line, $m) === 1) {
            if ($current !== null && !in_array($current, $templateSections, true)) {
                $blocks[] = rtrim(implode("\n", $buffer));
            }
            $current = $m[1];
            $buffer = [$line];
            continue;
        }
        if ($current !== null) {
            $buffer[] = $line;
            continue;
        }

        $isBoilerplate = false;
        foreach ($boilerplate as $pattern) {
            if (preg_match($pattern, trim($line)) === 1) {
                $isBoilerplate = true;
                break;
            }
        }
        if (!$isBoilerplate) {
            $preambleLines[] = $line;
        }
    }
    if ($current !== null && !in_array($current, $templateSections, true)) {
        $blocks[] = rtrim(implode("\n", $buffer));
    }

    $preamble = trim(implode("\n", $preambleLines));
    $sections = array_values(array_filter($blocks, static fn (string $b): bool => trim($b) !== ''));

    return [
        'preamble' => $preamble === '' ? null : $preamble,
        'sections' => $sections,
    ];
}

/** 递归列出目录下的文件（仓库相对路径），可选扩展名过滤。 */
function listFiles(string $root, string $dir, ?array $extensions = null): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        if ($extensions !== null) {
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $extensions, true)) {
                continue;
            }
        }
        $files[] = substr($file->getPathname(), strlen($root) + 1);
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * 问 git 哪些仓库相对路径被 .gitignore 忽略（一次调用判定一批）。
 *
 * 只返回「被忽略」的子集；tracked 文件即使命中模式也不返回（git 语义：tracked 永不忽略）。
 *
 * @param list<string> $repoRelPaths
 * @return list<string>
 */
function gitCheckIgnore(string $root, array $repoRelPaths): array
{
    if ($repoRelPaths === []) {
        return [];
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open(['git', '-C', $root, 'check-ignore', '--stdin'], $descriptors, $pipes);
    if (!is_resource($process)) {
        return [];
    }

    fwrite($pipes[0], implode("\n", $repoRelPaths) . "\n");
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if (!is_string($stdout) || trim($stdout) === '') {
        return [];
    }

    $ignored = [];
    foreach (preg_split('/\r\n|\n|\r/', trim($stdout)) ?: [] as $line) {
        if ($line !== '') {
            $ignored[] = $line;
        }
    }

    return $ignored;
}

/**
 * 剔除被 .gitignore 忽略的路径（结果按路径全局缓存，避免重复调用 git）。
 *
 * 为什么必须剔除：`view/tpl`（模板编译产物）、`vendor`、`.idea` 等是**运行期生成**的
 * 非源码面，随每次模板编译增删。一旦把它们计入「文件数」，快照就会在编译后漂移，
 * 让 `--check` 永远报漂移、契约测试无法稳定。用 git 自己的规则判定，而不是手写
 * gitignore 解析器——嵌套 .gitignore、取反（`!`）、锚定都交给 git。
 *
 * git 不可用时**保守放行**（返回原列表），不因环境问题丢文件。
 *
 * @param list<string> $repoRelPaths
 * @return list<string>
 */
function excludeGitIgnored(string $root, array $repoRelPaths): array
{
    if ($repoRelPaths === []) {
        return [];
    }

    /** @var array<string,bool> $cache 仓库相对路径 => 是否被忽略 */
    static $cache = [];

    $unknown = [];
    foreach ($repoRelPaths as $path) {
        if (!array_key_exists($path, $cache)) {
            $unknown[$path] = true;
        }
    }
    if ($unknown !== []) {
        foreach (gitCheckIgnore($root, array_keys($unknown)) as $path) {
            $cache[$path] = true;
        }
        foreach (array_keys($unknown) as $path) {
            if (!array_key_exists($path, $cache)) {
                $cache[$path] = false;
            }
        }
    }

    return array_values(array_filter(
        $repoRelPaths,
        static fn (string $path): bool => $cache[$path] === false,
    ));
}

/**
 * 按 glob（相对模块根）收集文件，返回模块相对路径（按磁盘真实大小写）。
 *
 * 用「模块文件索引 + 正则」实现，避免手写 glob 拼接在 `**` / `*` 混用时漏项；
 * 大小写不敏感匹配是为了兼容 macOS 大小写不敏感文件系统（`test/` 与 `Test/`
 * 实为同一目录），结果按磁盘真实路径去重，不会重复计数。
 *
 * @param list<string> $patterns
 * @return list<string>
 */
function matchGlobs(string $moduleDir, array $patterns): array
{
    $index = moduleFileIndex($moduleDir);
    if ($index === []) {
        return [];
    }

    $found = [];
    foreach ($patterns as $pattern) {
        $regex = '#^' . str_replace(
            ['\*\*/', '\*\*', '\*'],
            ['.+/', '.+', '[^/]+'],
            preg_quote($pattern, '#'),
        ) . '$#i';
        foreach ($index as $rel) {
            if (preg_match($regex, $rel) === 1) {
                $found[$rel] = true;
            }
        }
    }

    $list = array_keys($found);
    sort($list, SORT_STRING);

    return $list;
}

/**
 * 模块内全部**源码**文件（模块相对路径），按模块缓存。
 *
 * 已剔除 .gitignore 忽略的生成/编译产物（`view/tpl`、`vendor`、`.idea` 等），
 * 保证「文件数」只随源码变化，不随模板编译漂移。
 *
 * @return list<string>
 */
function moduleFileIndex(string $moduleDir): array
{
    /** @var array<string,list<string>> $cache */
    static $cache = [];
    if (isset($cache[$moduleDir])) {
        return $cache[$moduleDir];
    }

    $root = repoRoot();
    $prefix = substr($moduleDir, strlen($root) + 1) . '/';

    $kept = [];
    foreach (excludeGitIgnored($root, array_map(
        static fn (string $rel): string => $prefix . $rel,
        listFiles($moduleDir, $moduleDir),
    )) as $repoRel) {
        $kept[] = substr($repoRel, strlen($prefix));
    }

    $cache[$moduleDir] = $kept;

    return $cache[$moduleDir];
}

/** 读取模块清单（etc/module.php）。 */
function readManifest(string $moduleDir): array
{
    $path = $moduleDir . '/etc/module.php';
    if (!is_file($path)) {
        return [];
    }

    try {
        /** @var mixed $data */
        $data = include $path;
    } catch (Throwable) {
        return [];
    }

    return is_array($data) ? $data : [];
}

/** 渲染「可核查入口（最多 8 项）」单元格。 */
function renderEntryCell(array $files): string
{
    $limit = 8;
    $head = array_slice($files, 0, $limit);
    $cell = implode('<br>', array_map(static fn (string $f): string => '`' . $f . '`', $head));
    $rest = count($files) - count($head);
    if ($rest > 0) {
        $cell .= '<br>… 另有 ' . $rest . ' 个文件';
    }

    return $cell;
}

/** @param array<string,int> $stats @param list<string> $drift @param list<string> $skipped */
function generateAiIndex(
    string $root,
    array $module,
    array $options,
    array $dirty,
    array &$stats,
    array &$drift,
    array &$skipped,
): void {
    $docDir = $module['doc'];
    $relDoc = $module['rel'] . '/doc';
    $path = $docDir . '/AI-INDEX.md';
    $existing = is_file($path) ? (string) file_get_contents($path) : null;

    $lines = [];
    $lines[] = '<!-- ' . MARKER_AI_INDEX . ' -->';
    $lines[] = '# ' . $module['code'] . ' AI 开发入口';
    $lines[] = '';
    $lines[] = '> 本文件由 `' . GENERATOR_PATH . '` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。';
    $lines[] = '';
    $lines[] = '## 必读顺序';
    $lines[] = '';
    $lines[] = '1. `AI-ENTRY.md`';
    $lines[] = '2. 全局硬规则与任务路由：`app/code/Weline/Ai/doc/AI硬规则索引.md`';
    $lines[] = '3. 本文件：`' . $relDoc . '/AI-INDEX.md`';
    $lines[] = '4. 模块说明：`' . $relDoc . '/README.md`';
    $lines[] = '5. `app/code/Weline/Theme/doc/AI-INDEX.md`';
    $lines[] = '6. `app/code/Weline/Frontend/doc/AI-INDEX.md`';
    $lines[] = '7. `app/code/Weline/Taglib/doc/AI-INDEX.md`';
    $lines[] = '8. 只读取本次任务相关源码、配置和验证入口';
    $lines[] = '';

    $lines[] = '## 模块身份';
    $lines[] = '';
    $lines[] = '- 模块代码：`' . $module['code'] . '`';
    $lines[] = '- 目录：`' . $module['rel'] . '`';
    $lines[] = '- Vendor：`' . $module['vendor'] . '`';
    $lines[] = '- Module：`' . $module['module'] . '`';
    $lines[] = '';

    $lines[] = '## 代码面清单';
    $lines[] = '';

    $entries = [];
    foreach (ENTRY_FILES as $candidate) {
        if (is_file($module['dir'] . '/' . $candidate)) {
            $entries[] = '- `' . $module['rel'] . '/' . $candidate . '`';
        }
    }
    if ($entries !== []) {
        $lines[] = '入口/配置文件：';
        foreach ($entries as $entry) {
            $lines[] = $entry;
        }
        $lines[] = '';
    }

    $faceLines = [];
    foreach (CODE_FACES as $key => $spec) {
        // CODE_FACES 里既有目录（Api/Service/...）也有单文件（Controller/Router.php）。
        // 目录要展开成 `dir/**` 才能匹配到文件。
        $patterns = [];
        foreach ($spec['paths'] as $candidate) {
            $patterns[] = is_dir($module['dir'] . '/' . $candidate)
                ? rtrim($candidate, '/') . '/**'
                : $candidate;
        }
        $count = count(matchGlobs($module['dir'], $patterns));
        if ($count === 0) {
            continue;
        }
        $faceLines[] = '- `' . $key . '`：' . $spec['desc'] . ' 文件数：' . $count;
    }
    if ($faceLines === [] && $entries === []) {
        $lines[] = '- （未发现标准代码面目录与入口配置文件；以实际源码为准。）';
    }
    foreach ($faceLines as $faceLine) {
        $lines[] = $faceLine;
    }
    $lines[] = '';

    $tips = [];
    if (is_dir($module['dir'] . '/view/templates')) {
        $tips[] = '- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。';
    }
    if (is_dir($module['dir'] . '/view/tpl')) {
        $tips[] = '- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。';
    }
    if (is_dir($module['dir'] . '/extends/module')) {
        $tips[] = '- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。';
    }
    if (is_dir($module['dir'] . '/view/theme')) {
        $tips[] = '- 存在 `view/theme`，说明该模块向主题资源 catalog 贡献 layout/partial/component/widget/asset。';
    }
    if (is_file($module['dir'] . '/Controller/Router.php')) {
        $tips[] = '- 存在 `Controller/Router.php`，说明模块可能发布自定义 URL 匹配；不要用 `routes.xml` 代替。';
    }
    if (is_dir($module['dir'] . '/i18n')) {
        $tips[] = '- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。';
    }

    $queryFiles = array_values(array_filter(
        matchGlobs($module['dir'], ['extends/module/*/Query/**']),
        static fn (string $f): bool => str_ends_with($f, '.php'),
    ));
    if ($queryFiles !== []) {
        $tips[] = '- 识别到 QueryProvider 入口：' . implode('、', array_map(
            static fn (string $f): string => '`' . $f . '`',
            array_slice($queryFiles, 0, 8),
        )) . '；前端/跨模块读数据先查 `php bin/w query:help`。';
    }

    if ($tips !== []) {
        $lines[] = '## 从源码识别到的开发提示';
        $lines[] = '';
        foreach ($tips as $tip) {
            $lines[] = $tip;
        }
        $lines[] = '';
    }

    $docFiles = array_values(array_filter(
        excludeGitIgnored($root, listFiles($root, $docDir, ['md'])),
        static fn (string $f): bool => !str_ends_with($f, '/AI-INDEX.md'),
    ));
    $lines[] = '## doc 目录';
    $lines[] = '';
    if ($docFiles === []) {
        $lines[] = '- （本模块 doc/ 下暂无 Markdown 文档）';
    } else {
        foreach ($docFiles as $file) {
            $lines[] = '- `' . $file . '`';
        }
    }
    $lines[] = '';

    $lines[] = '## 开发前门禁';
    $lines[] = '';
    $lines[] = '- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。';
    $lines[] = '- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。';
    $lines[] = '- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|' . $module['code'] . '> [operation]` 或对应 `w_query` 帮助。';
    $lines[] = '- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。';
    $lines[] = '- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。';
    $lines[] = '- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。';

    $preserved = preservedSections($existing, AI_INDEX_TEMPLATE_SECTIONS);
    foreach ($preserved['sections'] as $block) {
        $lines[] = '';
        $lines[] = $block;
    }

    $content = implode("\n", prefixPreamble($preserved['preamble'], $lines)) . "\n";
    writeGuarded($root, $path, $content, MARKER_AI_INDEX, $options, $dirty, $stats, $drift, $skipped);
}

/** @param array<string,int> $stats @param list<string> $drift @param list<string> $skipped */
function generateCapability(
    string $root,
    array $module,
    array $options,
    array $dirty,
    array &$stats,
    array &$drift,
    array &$skipped,
): void {
    $docDir = $module['doc'];
    $relDoc = $module['rel'] . '/doc';
    $path = $docDir . '/功能现状.md';
    $existing = is_file($path) ? (string) file_get_contents($path) : null;

    $manifest = readManifest($module['dir']);
    $hasManifest = is_file($module['dir'] . '/etc/module.php');
    $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : null;

    $lines = [];
    $lines[] = '<!-- ' . MARKER_CAPABILITY . ' -->';
    $lines[] = '# ' . $module['code'] . ' 功能现状';
    $lines[] = '';
    $lines[] = '> 本文是根据当前工作树自动生成的代码能力快照，用于避免模块文档长期脱离实现。它证明文件、入口和契约“存在”，不替代产品需求确认、运行验收或发布记录。';
    $lines[] = '';
    $lines[] = '## 快照信息';
    $lines[] = '';
    $lines[] = '- 模块：`' . $module['code'] . '`';
    if (!$hasManifest) {
        $lines[] = '- 当前模块版本：未发现 `' . $module['rel'] . '/etc/module.php`（模块清单缺失，版本不可判定）。';
    } elseif ($version === null) {
        $lines[] = '- 当前模块版本：清单存在但未声明 `version`（来源：`' . $module['rel'] . '/etc/module.php`）。';
    } else {
        $lines[] = '- 当前模块版本：`' . $version . '`（来源：`' . $module['rel'] . '/etc/module.php`）';
    }
    $lines[] = '- 模块目录：`' . $module['rel'] . '`';
    $lines[] = '- 证据日期：' . date('Y-m-d');
    $lines[] = '- 证据状态：当前工作树快照；未执行本模块运行时或 WebUI 验收。';
    $lines[] = '';
    $lines[] = '## 注册与依赖边界';
    $lines[] = '';

    $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
    $optional = is_array($manifest['optional'] ?? null) ? $manifest['optional'] : [];
    $provides = is_array($manifest['provides'] ?? null) ? $manifest['provides'] : [];

    $lines[] = '- 必需模块：' . renderDependencyList($requires);
    $lines[] = '- 可选模块：' . renderDependencyList($optional);
    if ($provides !== []) {
        $pairs = [];
        foreach ($provides as $interface => $implementation) {
            $pairs[] = '`' . ltrim((string) $interface, '\\') . '=' . ltrim((string) $implementation, '\\') . '`';
        }
        $lines[] = '- 发布契约：' . implode('、', $pairs) . '。';
    }
    $lines[] = '';

    $lines[] = '## 当前能力面';
    $lines[] = '';
    $lines[] = '| 能力面 | 文件数 | 可核查入口（最多 8 项） |';
    $lines[] = '|---|---:|---|';
    $faceFiles = [];
    foreach (CAPABILITY_FACES as $label => $patterns) {
        $files = matchGlobs($module['dir'], $patterns);
        $faceFiles[$label] = $files;
        if ($files === []) {
            continue;
        }
        $lines[] = '| ' . $label . ' | ' . count($files) . ' | ' . renderEntryCell($files) . ' |';
    }
    $lines[] = '';

    $lines[] = '## 关键入口摘要';
    $lines[] = '';
    $controllers = $faceFiles['HTTP 控制器与路由'] ?? [];
    if ($controllers === []) {
        $lines[] = '- HTTP/路由入口：未发现 `Controller/**`。';
    } else {
        $lines[] = '- HTTP/路由入口：' . inlineCodeList($controllers) . '。';
    }
    $console = $faceFiles['CLI 命令'] ?? [];
    if ($console === []) {
        $lines[] = '- CLI 入口：未发现 `Console/**`。';
    } else {
        $lines[] = '- CLI 入口：' . inlineCodeList($console) . '。';
    }
    $query = $faceFiles['公开契约与查询入口'] ?? [];
    $queryOnly = array_values(array_filter($query, static fn (string $f): bool => str_contains($f, '/Query/')));
    if ($queryOnly !== []) {
        $lines[] = '- QueryProvider 入口：' . inlineCodeList($queryOnly) . '。';
    } else {
        $lines[] = '- QueryProvider 入口：未发现 `extends/module/*/Query/**`。';
    }
    // 只报「目录是否存在」，不报文件数：view/tpl 是运行期编译产物，计数随编译漂移。
    if (!is_dir($module['dir'] . '/view/tpl')) {
        $lines[] = '- 编译产物：未发现 `view/tpl/**`。';
    } else {
        $lines[] = '- 编译产物：存在 `view/tpl/**`（运行期编译生成，数量随编译变化，故不列举；禁止直接修改）。';
    }
    $lines[] = '';

    $lines[] = '## 当前文档入口';
    $lines[] = '';
    $docFiles = array_values(array_filter(
        excludeGitIgnored($root, listFiles($root, $docDir, ['md'])),
        static fn (string $f): bool => !str_ends_with($f, '/功能现状.md') && !str_ends_with($f, '/AI-INDEX.md'),
    ));
    if ($docFiles === []) {
        $lines[] = '- （本模块 doc/ 下暂无其它 Markdown 文档）';
    } else {
        foreach ($docFiles as $file) {
            $rel = substr($file, strlen($relDoc) + 1);
            $lines[] = '- [`' . $rel . '`](' . encodeRelativeLink($rel) . ')';
        }
    }
    $lines[] = '';

    $lines[] = '## 能确认与不能确认的边界';
    $lines[] = '';
    $lines[] = '### 当前可直接确认';
    $lines[] = '';
    $lines[] = '- `etc/module.php` 中的模块代码、版本、requires/optional/provides 声明。';
    $lines[] = '- 上表列出的源码、配置、模板源文件、测试资产及 QueryProvider 文件当前存在。';
    $lines[] = '- 当前文档入口与模块目录结构可从工作树复查。';
    $lines[] = '';
    $lines[] = '### 尚未由本次文档任务确认';
    $lines[] = '';
    $lines[] = '- 控制器路由是否已在目标环境完成同步、菜单/ACL 是否可操作。';
    $lines[] = '- 测试资产是否在当前依赖、数据库和 WLS 环境全部通过。';
    $lines[] = '- 页面、交互、队列、持久化和外部集成是否已按真实用户路径验收。';
    $lines[] = '- 既有实现对应的完整产品意图、优先级和历史决策；这些内容仍以 `需求.md` 的确认状态为准。';
    $lines[] = '';
    $lines[] = '## 维护规则';
    $lines[] = '';
    $lines[] = '- 功能、公开契约、入口目录或模块版本发生变化时，应重新生成本快照并同步长期功能/API/运营文档。';
    $lines[] = '- 本快照不能作为“功能已完成”的证据；产品能力仍需按变更表面执行单测、真实运行或 WebUI/E2E 验收。';
    $lines[] = '- 手写产品语义放在 `README.md`、专题文档或 `需求.md`；不要在本自动快照中追加不可再生内容。';

    $preserved = preservedSections($existing, CAPABILITY_TEMPLATE_SECTIONS);
    foreach ($preserved['sections'] as $block) {
        $lines[] = '';
        $lines[] = $block;
    }

    $content = implode("\n", prefixPreamble($preserved['preamble'], $lines)) . "\n";
    writeGuarded($root, $path, $content, MARKER_CAPABILITY, $options, $dirty, $stats, $drift, $skipped);
}

/**
 * 把人工引言块放到生成内容之前（保持原有结构：手写更新记录在文件顶部）。
 *
 * @param list<string> $lines
 * @return list<string>
 */
function prefixPreamble(?string $preamble, array $lines): array
{
    if ($preamble === null) {
        return $lines;
    }

    return array_merge(explode("\n", $preamble), [''], $lines);
}

/** @param array<string,mixed> $requires */
function renderDependencyList(array $requires): string
{
    if ($requires === []) {
        return '无显式声明。';
    }

    $parts = [];
    foreach ($requires as $name => $constraint) {
        $parts[] = '`' . $name . '=' . (is_string($constraint) ? $constraint : '*') . '`';
    }

    return implode('、', $parts) . '。';
}

/** @param list<string> $files */
function inlineCodeList(array $files, int $limit = 10): string
{
    $head = array_slice($files, 0, $limit);
    $text = implode('、', array_map(static fn (string $f): string => '`' . $f . '`', $head));
    $rest = count($files) - count($head);
    if ($rest > 0) {
        $text .= '，另有 ' . $rest . ' 项';
    }

    return $text;
}

/** 生成 Markdown 相对链接，非 ASCII 字节按百分号编码（与既有快照一致）。 */
function encodeRelativeLink(string $rel): string
{
    $segments = explode('/', $rel);
    $encoded = array_map(static function (string $segment): string {
        $out = '';
        $length = strlen($segment);
        for ($i = 0; $i < $length; $i++) {
            $byte = $segment[$i];
            $ord = ord($byte);
            if ($ord >= 0x80) {
                // 仅编码非 ASCII 字节；ASCII（含 .md）原样保留。
                $out .= '%' . strtoupper(bin2hex($byte));
                continue;
            }
            $out .= str_replace(
                [' ', '(', ')', '?', '#', '%'],
                ['%20', '%28', '%29', '%3F', '%23', '%25'],
                $byte,
            );
        }

        return $out;
    }, $segments);

    return implode('/', $encoded);
}

/** @param array<string,int> $stats @param list<string> $drift @param list<string> $skipped */
function generateOverview(
    string $root,
    array $modules,
    array $options,
    array $dirty,
    array &$stats,
    array &$drift,
    array &$skipped,
): void {
    $path = $root . '/app/code/Weline/Ai/doc/模块文档总览.md';

    $rows = [];
    $totals = ['doc' => 0, 'md' => 0, 'missing_ai' => 0, 'missing_cap' => 0, 'hand_ai' => 0];
    foreach ($modules as $module) {
        // 排除 .gitignore 忽略的本机产物（QA 截图目录、evidence/ 等），否则总览会把
        // 未入库的本地文件算进「doc 文件数」，每次跑都变。
        $files = excludeGitIgnored($root, listFiles($root, $module['doc']));
        $md = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.md')));
        $totals['doc'] += count($files);
        $totals['md'] += count($md);

        $aiPath = $module['doc'] . '/AI-INDEX.md';
        $capPath = $module['doc'] . '/功能现状.md';
        $hasAi = is_file($aiPath);
        $hasCap = is_file($capPath);
        $aiAuto = $hasAi && str_contains((string) file_get_contents($aiPath), MARKER_AI_INDEX);

        if (!$hasAi) {
            $totals['missing_ai']++;
        } elseif (!$aiAuto) {
            $totals['hand_ai']++;
        }
        if (!$hasCap) {
            $totals['missing_cap']++;
        }

        $rows[] = [
            'code' => $module['code'],
            'rel' => $module['rel'],
            'files' => count($files),
            'md' => count($md),
            'ai' => $hasAi ? ($aiAuto ? '自动' : '手写') : '缺失',
            'cap' => $hasCap ? '有' : '缺失',
        ];
    }

    $lines = [];
    $lines[] = '# Weline 模块文档总览';
    $lines[] = '';
    $lines[] = '> 本文件由 `' . GENERATOR_PATH . '` 自动生成，列出 `app/code/*/*/doc/` 的规模与三文档契约齐备情况。';
    $lines[] = '> 用于跨模块定位与缺口巡检；正文仍以各模块 `doc/` 为准。生成日期：' . date('Y-m-d') . '。';
    $lines[] = '';
    $lines[] = '## 汇总';
    $lines[] = '';
    $lines[] = '- 模块数：' . count($modules);
    $lines[] = '- doc 文件总数：' . $totals['doc'] . '（其中 Markdown ' . $totals['md'] . '）';
    $lines[] = '- `AI-INDEX.md`：缺失 ' . $totals['missing_ai'] . ' 个，手写（非自动生成）' . $totals['hand_ai'] . ' 个';
    $lines[] = '- `功能现状.md`：缺失 ' . $totals['missing_cap'] . ' 个';
    $lines[] = '';
    $lines[] = '## 三文档契约';
    $lines[] = '';
    $lines[] = '每个模块 `doc/` 的必备文件：`README.md`（定位/导航）、`需求.md`（有效需求与验收）、`开发日志.md`（实现与发布记录）。';
    $lines[] = '自动快照：`AI-INDEX.md`（AI 导航入口）、`功能现状.md`（代码能力快照）。快照证明“存在”，不证明“已验收”。';
    $lines[] = '';
    $lines[] = '## 模块清单';
    $lines[] = '';
    $lines[] = '| 模块 | 目录 | doc 文件数 | Markdown | AI-INDEX | 功能现状 |';
    $lines[] = '|---|---|---:|---:|---|---|';
    foreach ($rows as $row) {
        $lines[] = '| `' . $row['code'] . '` | `' . $row['rel'] . '` | ' . $row['files'] . ' | ' . $row['md']
            . ' | ' . $row['ai'] . ' | ' . $row['cap'] . ' |';
    }
    $lines[] = '';

    $content = implode("\n", $lines) . "\n";

    // 总览每次全量重写，不受 auto-generated 标记限制（本文件即生成物）。
    writeGuarded($root, $path, $content, '# Weline 模块文档总览', $options, $dirty, $stats, $drift, $skipped);
}

exit(main($argv));
