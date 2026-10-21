#!/usr/bin/env php
<?php

/**
 * 后端主题标准静态审计（Backend Theme Audit）。
 *
 * 标准正文：app/code/Weline/Theme/doc/backend/后端主题开发标准.md
 *
 * 用法：
 *   php app/code/Weline/Theme/bin/backend-theme-audit.php            # 全仓后台模板概览
 *   php .../backend-theme-audit.php --module=Theme                  # 只看某模块
 *   php .../backend-theme-audit.php --strict                        # 只审 Weline_Theme 默认主题，有违规退出码 1
 *   php .../backend-theme-audit.php --json                          # 机器可读（含逐文件清单）
 *   php .../backend-theme-audit.php --files                         # 打印逐文件违规清单（按违规数降序）
 *
 * 退出码：0 = 无违规（或非 strict 概览）；1 = strict 下存在违规。
 */

declare(strict_types=1);

$root = dirname(__DIR__, 5);
$options = getopt('', ['module::', 'strict', 'json', 'files', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php app/code/Weline/Theme/bin/backend-theme-audit.php [--module=Theme] [--strict] [--json] [--files]\n");
    exit(0);
}

$strict = isset($options['strict']);
$jsonOut = isset($options['json']);
$filesOut = isset($options['files']);
$onlyModule = isset($options['module']) ? trim((string)$options['module']) : '';

$patterns = [
    'style_block' => '/<style\b[^>]*>/i',
    'inline_visual_style' => '/\bstyle\s*=\s*"[^"]*(?:#[0-9a-fA-F]{3,8}\b|rgba?\(|hsl\(|\b\d+(?:\.\d+)?(?:px|rem|em)\b|!important)[^"]*"/i',
    'important' => '/!important/i',
    'legacy_class' => '/\bclass\s*=\s*"[^"]*\b(?:page-title-box|card-body|form-control|table-responsive|nav-tabs|col-(?:xs|sm|md|lg|xl)-\d+|admin-card)\b[^"]*"/i',
    // 同步业务脚本：无 defer/async、非 type=module，且不是首屏 prepaint 兜底。
    'sync_script_src' => '/<script\b(?![^>]*\b(?:defer|async)\b)(?![^>]*\btype\s*=\s*["\']module["\'])(?![^>]*weline-theme-prepaint\.js)[^>]*\bsrc\s*=\s*["\'][^"\']*(?:@static|\/static\/)[^"\']*["\'][^>]*>/i',
    'inline_h1' => '/<h1\b/i',
];

/**
 * @return list<string>
 */
function collectBackendTemplates(string $root, string $onlyModule = ''): array
{
    $files = [];
    $moduleGlob = $onlyModule !== ''
        ? $root . '/app/code/*/' . $onlyModule . '/view/templates/backend'
        : $root . '/app/code/*/*/view/templates/backend';
    foreach (glob($moduleGlob, GLOB_ONLYDIR) ?: [] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'phtml') {
                $files[] = $file->getPathname();
            }
        }
    }
    // 默认主题自身模板（area 模板也按同一标准审）
    $themeTemplates = $root . '/app/code/Weline/Theme/view/theme/backend';
    if ($onlyModule === '' || $onlyModule === 'Theme') {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeTemplates, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'phtml') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

$files = collectBackendTemplates($root, $onlyModule);
$summary = [
    'scanned' => count($files),
    'rules' => array_fill_keys(array_keys($patterns), 0),
    'files_with_violations' => 0,
    'samples' => [],
    'files' => [],
];

foreach ($files as $file) {
    $relative = str_replace($root . '/', '', $file);
    $content = (string)@file_get_contents($file);
    $fileHits = [];
    foreach ($patterns as $rule => $regex) {
        $count = preg_match_all($regex, $content, $matches);
        if ($count > 0) {
            $summary['rules'][$rule] += $count;
            $fileHits[$rule] = $count;
            if (count($summary['samples']) < 40 && $rule !== 'inline_h1') {
                $summary['samples'][] = ['file' => $relative, 'rule' => $rule, 'count' => $count];
            }
        }
    }
    // 页面模板里出现第二个 h1 才算违规（布局允许一个）
    if (($fileHits['inline_h1'] ?? 0) > 1) {
        $summary['rules']['inline_h1'] += $fileHits['inline_h1'] - 1;
        $summary['samples'][] = ['file' => $relative, 'rule' => 'multiple_h1', 'count' => $fileHits['inline_h1']];
        $fileHits['multiple_h1'] = $fileHits['inline_h1'] - 1;
    }
    if ($fileHits !== []) {
        $summary['files_with_violations']++;
        $summary['files'][] = [
            'file' => $relative,
            'total' => array_sum($fileHits),
            'rules' => $fileHits,
        ];
    }
}

usort($summary['files'], static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

if ($jsonOut) {
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} else {
    fwrite(STDOUT, "后端主题标准审计（Backend Theme Audit）\n");
    fwrite(STDOUT, "====================================\n");
    fwrite(STDOUT, '扫描模板：' . $summary['scanned'] . " 个\n");
    fwrite(STDOUT, '存在违规文件：' . $summary['files_with_violations'] . " 个\n\n");
    $labels = [
        'style_block' => '页面内 <style> 视觉块',
        'inline_visual_style' => '行内视觉字面量 style=""',
        'important' => '!important',
        'legacy_class' => '旧 Bootstrap/Admin 类',
        'sync_script_src' => '同步 <script src> 业务脚本',
        'inline_h1' => '模板内额外 h1',
    ];
    foreach ($labels as $rule => $label) {
        fwrite(STDOUT, sprintf("  %-28s %d\n", $label, $summary['rules'][$rule]));
    }
    if ($filesOut) {
        fwrite(STDOUT, "\n逐文件违规清单（按违规数降序）：\n");
        foreach ($summary['files'] as $item) {
            $ruleText = [];
            foreach ($item['rules'] as $rule => $count) {
                $ruleText[] = $rule . ' x' . $count;
            }
            fwrite(STDOUT, sprintf("  %4d  %s  [%s]\n", $item['total'], $item['file'], implode(', ', $ruleText)));
        }
    } else {
        fwrite(STDOUT, "\n优先修复样例（最多 15 条，完整清单用 --files 或 --json）：\n");
        foreach (array_slice($summary['samples'], 0, 15) as $sample) {
            fwrite(STDOUT, sprintf("  [%s x%d] %s\n", $sample['rule'], $sample['count'], $sample['file']));
        }
    }
    fwrite(STDOUT, "\n标准：app/code/Weline/Theme/doc/backend/后端主题开发标准.md\n");
}

/**
 * strict：默认主题 area 模板不允许 style_block / important / inline_visual_style / sync_script_src。
 */
if ($strict) {
    $themeArea = $root . '/app/code/Weline/Theme/view/theme/backend';
    $violations = [];
    foreach ($files as $file) {
        if (!str_starts_with($file, $themeArea)) {
            continue;
        }
        $content = (string)@file_get_contents($file);
        foreach (['style_block', 'inline_visual_style', 'important', 'sync_script_src'] as $rule) {
            if (preg_match_all($patterns[$rule], $content, $m) > 0) {
                $violations[] = str_replace($root . '/', '', $file) . ' [' . $rule . ']';
            }
        }
    }
    // 0 字节组件同样不允许
    foreach (glob($themeArea . '/components/*.phtml') ?: [] as $component) {
        if ((int)filesize($component) === 0) {
            $violations[] = str_replace($root . '/', '', $component) . ' [empty_component]';
        }
    }
    if ($violations !== []) {
        fwrite(STDERR, "\n[strict] 默认主题存在违规：\n  " . implode("\n  ", $violations) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "\n[strict] 默认主题零违规 ✓\n");
}
exit(0);
