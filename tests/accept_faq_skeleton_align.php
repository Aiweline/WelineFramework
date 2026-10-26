<?php
/**
 * 验收：版心骨架对齐（按任务范围校验）
 *
 * 用法：php tests/accept_faq_skeleton_align.php <T1|T2|T3|T4|T5|T6|ALL>
 *
 * 缺陷 A（骨架塌成 1440 贴边）：CSS 选择器写成 `. faq__xxx`（点号后多一个空格），
 *   点号后跟空格在 CSS 里是「悬空后代组合符」，浏览器 CSS 解析器判整条规则无效并丢弃。
 *   被包在 :is() / :has() 里的坏成员则静默失效（规则保留、该成员永不匹配）。
 *
 * 缺陷 B（terms/policy 骨架同样贴边）：Theme 模块默认模板的 stage 容器带
 *   class="doc-policy__stage"，版心规则挂在它上面
 *   （app/code/Weline/Theme/view/statics/css/widgets/widget-content-policy-document-default.css:33）。
 *   hanfu 覆盖版改成 guide-policy__stage / doc-terms__stage，这两个 class 在 hanfu CSS 里
 *   只有 color/font-family 规则、没有版心规则，于是版心失效、页面贴边。
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$scope = strtoupper(trim((string)($argv[1] ?? 'ALL')));

/** 任务范围 → 待检文件 */
const SCOPE_FILES = [
    'T1' => ['app/design/Weline/hanfu/frontend/layouts/faq/default.phtml'],
    'T2' => ['app/design/Weline/hanfu/frontend/assets/css/hanfu-content.css'],
    'T3' => ['app/design/Weline/hanfu/frontend/assets/css/hanfu-ink.css'],
    'T4' => [
        'app/design/Weline/playinginchina/frontend/layouts/blog/default.phtml',
        'app/design/Weline/playinginchina/frontend/layouts/blog_category/default.phtml',
    ],
    'T5' => [
        'app/design/Weline/daocharms/frontend/assets/css/daocharms-reading.css',
        'app/design/Weline/daocharms/frontend/assets/css/daocharms-responsive.css',
        'app/design/Weline/daocharms/Weline/Theme/view/theme/frontend/assets/css/daocharms-reading.css',
        'app/design/Weline/daocharms/Weline/Theme/view/theme/frontend/assets/css/daocharms-responsive.css',
    ],
    'T7' => ['app/design/Weline/hanfu/frontend/assets/css/hanfu-content.css'],
    'T6' => [
        'app/design/Weline/hanfu/frontend/layouts/terms/default.phtml',
        'app/design/Weline/hanfu/frontend/layouts/policy/accessibility.phtml',
        'app/design/Weline/hanfu/frontend/layouts/policy/cookie.phtml',
        'app/design/Weline/hanfu/frontend/layouts/policy/disclaimer.phtml',
        'app/design/Weline/hanfu/frontend/layouts/policy/privacy.phtml',
        'app/design/Weline/hanfu/frontend/layouts/policy/refund.phtml',
        'app/design/Weline/hanfu/frontend/layouts/policy/shipping.phtml',
        'app/design/Weline/hanfu/frontend/layouts/policy/term-condition.phtml',
    ],
];

/** CSS 上下文（<style> 块）里的畸形选择器 */
function cssContextBroken(string $src): array
{
    $out = [];
    $flags = PREG_SET_ORDER | PREG_OFFSET_CAPTURE;
    if (preg_match_all('#<style[^>]*>(.*?)</style>#s', $src, $m, $flags)) {
        foreach ($m as $block) {
            // $block[0] = [fullMatch, offset]，$block[1] = [group1, offset]
            $offset = $block[0][1];
            $start = substr_count(substr($src, 0, $offset), "\n") + 1;
            $body = preg_replace('#/\*.*?\*/#s', '', $block[1][0]);
            foreach (explode("\n", $body) as $j => $line) {
                if (preg_match('/(^|[\s,>:(])\.\s+[a-zA-Z\[]/', $line)) {
                    $out[] = 'style line ' . ($start + $j) . ': ' . trim($line);
                }
            }
        }
    }
    return $out;
}

/** 纯 css 文件里的畸形选择器（跳过注释） */
function cssFileBroken(string $path): array
{
    $body = preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($path));
    $out = [];
    foreach (explode("\n", $body) as $i => $line) {
        if (preg_match('/(^|[\s,>:(])\.\s+[a-zA-Z\[]/', $line)) {
            $out[] = 'line ' . ($i + 1) . ': ' . trim($line);
        }
    }
    return $out;
}

if ($scope === 'ALL') {
    $files = [];
    foreach (SCOPE_FILES as $list) {
        $files = array_merge($files, $list);
    }
} elseif (isset(SCOPE_FILES[$scope])) {
    $files = SCOPE_FILES[$scope];
} else {
    fwrite(STDERR, "未知范围: {$scope}（可用：T1..T6 / ALL）\n");
    exit(2);
}

$fail = [];
$ok = [];

foreach ($files as $rel) {
    $abs = $root . '/' . $rel;
    if (!is_file($abs)) {
        $fail[] = "缺失文件: {$rel}";
        continue;
    }
    $src = (string)file_get_contents($abs);
    $broken = str_ends_with($rel, '.css') ? cssFileBroken($abs) : cssContextBroken($src);
    if ($broken !== []) {
        foreach (array_slice($broken, 0, 5) as $b) {
            $fail[] = "{$rel} 仍含畸形选择器 -> {$b}";
        }
        if (count($broken) > 5) {
            $fail[] = "{$rel} 畸形选择器共 " . count($broken) . " 处（仅列前 5）";
        }
    } else {
        $ok[] = "{$rel}: 无畸形选择器";
    }
}

// T1 附加：class 属性前导空格 + FAQ 版心规则仍在且只消费框架契约 token
if ($scope === 'T1' || $scope === 'ALL') {
    $rel = 'app/design/Weline/hanfu/frontend/layouts/faq/default.phtml';
    $src = (string)file_get_contents($root . '/' . $rel);
    if (preg_match_all('/class="\s+[^"]*"/', $src, $m)) {
        foreach (array_slice($m[0], 0, 5) as $bad) {
            $fail[] = "{$rel} class 属性前导空格: {$bad}";
        }
    } else {
        $ok[] = "{$rel}: class 属性无前导空格";
    }
    if (preg_match('/\.faq__stage\s*\{[^}]*width:\s*min\(100%,\s*var\(--weline-layout-content-max-width\)\)/s', $src)) {
        $ok[] = "{$rel}: .faq__stage 消费 --weline-layout-content-max-width";
    } else {
        $fail[] = "{$rel}: .faq__stage 未消费 --weline-layout-content-max-width";
    }
    if (preg_match('/\.faq__stage\s*\{[^}]*margin-inline:\s*auto/s', $src)) {
        $ok[] = "{$rel}: .faq__stage 有 margin-inline:auto";
    } else {
        $fail[] = "{$rel}: .faq__stage 缺 margin-inline:auto";
    }
    if (preg_match('/\.faq__stage\s*\{[^}]*padding-inline:\s*var\(--weline-layout-content-padding-inline\)/s', $src)) {
        $ok[] = "{$rel}: .faq__stage 消费 --weline-layout-content-padding-inline";
    } else {
        $fail[] = "{$rel}: .faq__stage 未消费 --weline-layout-content-padding-inline";
    }
}

// T6 附加：terms/policy 的 stage 容器必须带 doc-policy__stage
if ($scope === 'T6' || $scope === 'ALL') {
    foreach (SCOPE_FILES['T6'] as $rel) {
        $abs = $root . '/' . $rel;
        if (!is_file($abs)) {
            continue;
        }
        $src = (string)file_get_contents($abs);
        if (preg_match('/class="[^"]*\bdoc-policy__stage\b[^"]*"/', $src)) {
            $ok[] = "{$rel}: stage 容器带 doc-policy__stage";
        } else {
            $fail[] = "{$rel}: stage 容器缺少 doc-policy__stage（版心规则将失效）";
        }
    }
}

// T7 附加：terms/policy 内层 .w-container 归零规则必须存在，且不得用 !important
if ($scope === 'T7' || $scope === 'ALL') {
    $rel = 'app/design/Weline/hanfu/frontend/assets/css/hanfu-content.css';
    $src = (string)file_get_contents($root . '/' . $rel);
    foreach (['.doc-terms__stage', '.guide-policy__stage'] as $stageSel) {
        $needle = 'body ' . $stageSel . ' .w-container';
        if (!str_contains($src, $needle)) {
            $fail[] = "{$rel}: 缺少归零规则 `{$needle}`";
            continue;
        }
        $ok[] = "{$rel}: 存在归零规则 `{$needle}`";
    }
    if (preg_match('/\.doc-terms__stage \.w-container[^{]*\{[^}]*!important/s', $src)
        || preg_match('/\.guide-policy__stage \.w-container[^{]*\{[^}]*!important/s', $src)) {
        $fail[] = "{$rel}: 归零规则不得使用 !important";
    } else {
        $ok[] = "{$rel}: 归零规则无 !important";
    }
}

echo "--- PASS [{$scope}] ---\n" . implode("\n", $ok) . "\n";
if ($fail !== []) {
    echo "--- FAIL [{$scope}] ---\n" . implode("\n", $fail) . "\n";
    exit(1);
}
echo "ACCEPT PASS\n";
exit(0);
