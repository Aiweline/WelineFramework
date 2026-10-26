<?php

declare(strict_types=1);

/**
 * 验收：默认主题 storefront-float-layer 贴边收起后，召回把手必须可点。
 *
 * 根因（浏览器实测）：side 槽 `overflow-y:auto` + `max-height:calc(100vh - 8rem)`
 * 使槽成为滚动容器，`right:100%` 的召回把手位于槽盒之外被裁剪 →
 * `document.elementFromPoint()` 命中页面内容，点击超时，用户召唤不回来。
 *
 * 修复必须在**三处来源**同时成立（否则内联 critical CSS 特异性更高会盖掉文件 CSS）：
 *   1. view/statics/css/storefront-float-layer.css
 *   2. view/theme/frontend/partials/footer/default.phtml 的 data-storefront-float-layer-critical
 *   3. Service/LayoutEntity/StorefrontFloatLayerHost.php 的 publishedSlotLayerMarkup / sourceSlotLayerMarkup
 */

$root = dirname(__DIR__);

$sources = [
    'css' => $root . '/app/code/Weline/Theme/view/statics/css/storefront-float-layer.css',
    'footer' => $root . '/app/code/Weline/Theme/view/theme/frontend/partials/footer/default.phtml',
    'host' => $root . '/app/code/Weline/Theme/Service/LayoutEntity/StorefrontFloatLayerHost.php',
    'js' => $root . '/app/code/Weline/Theme/view/statics/js/storefront-float-layer.js',
];

$fail = [];
$texts = [];
foreach ($sources as $key => $path) {
    if (!is_file($path)) {
        $fail[] = "missing source {$key}: {$path}";
        continue;
    }
    $texts[$key] = (string)file_get_contents($path);
}
if ($fail !== []) {
    foreach ($fail as $line) {
        fwrite(STDOUT, "FAIL: {$line}\n");
    }
    exit(1);
}

/** 取所有含 side 槽声明的规则块。 */
$sideRuleBlocks = static function (string $text): array {
    $blocks = [];
    $offset = 0;
    while (preg_match('/[^{}]*\.w-storefront-float-layer__slot--side[^{}]*\{[^}]*\}/', $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $blocks[] = $m[0][0];
        $offset = $m[0][1] + strlen($m[0][0]);
    }

    return $blocks;
};

/** 取所有 is-edge-collapsed 槽规则块（不含其后代选择器）。 */
$collapsedRuleBlocks = static function (string $text): array {
    $blocks = [];
    $offset = 0;
    while (preg_match('/[^{}]*\.w-storefront-float-layer__slot\.is-edge-collapsed\s*\{[^}]*\}/', $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $blocks[] = $m[0][0];
        $offset = $m[0][1] + strlen($m[0][0]);
    }

    return $blocks;
};

foreach ($texts as $key => $text) {
    if ($key === 'js') {
        continue; // JS 只做 storageKey 断言（见第 3 段）
    }

    // 1) side 槽不得是滚动容器（overflow-y:auto 会把 overflow-x 也算成 auto）。
    $sideBlocks = $sideRuleBlocks($text);
    if ($sideBlocks === []) {
        $fail[] = "[{$key}] side slot rule missing";
    }
    foreach ($sideBlocks as $block) {
        if (preg_match('/overflow(-[xy])?\s*:\s*(auto|scroll|hidden)/', $block) === 1) {
            $fail[] = "[{$key}] side slot still clips (overflow auto/scroll/hidden): " . trim(preg_replace('/\s+/', ' ', $block));
        }
    }

    // 2) 收起态必须显式 overflow:visible + max-height:none，贴边把手才不被裁剪。
    $collapsedBlocks = $collapsedRuleBlocks($text);
    if ($collapsedBlocks === []) {
        $fail[] = "[{$key}] collapsed slot rule missing";
    }
    foreach ($collapsedBlocks as $block) {
        $flat = preg_replace('/\s+/', ' ', $block);
        if (preg_match('/overflow\s*:\s*visible/', $flat) !== 1) {
            $fail[] = "[{$key}] collapsed slot rule lacks overflow: visible: {$flat}";
        }
        if (preg_match('/max-height\s*:\s*none/', $flat) !== 1) {
            $fail[] = "[{$key}] collapsed slot rule lacks max-height: none: {$flat}";
        }
    }
}

// 3) 收起状态必须按槽位分键持久化：side 归 side，不能写进 start 的键
//    （否则收起 side 会让 start 刷新后跟着收起，反之亦然 → 召唤不回来）。
if (preg_match('/function storageKey\(([^)]*)\)\s*\{(.*?)\n  \}/s', $texts['js'], $sm) !== 1) {
    $fail[] = '[js] storageKey() not found';
} else {
    $body = $sm[2];
    if (preg_match('/[\'"]side[\'"]/', $body) !== 1) {
        $fail[] = '[js] storageKey() ignores the side slot: ' . trim(preg_replace('/\s+/', ' ', $body));
    }
    // 必须按槽位原样返回（`+ side` 或 `? side`），不能把 side 归并成 start。
    if (preg_match('/(?:\+\s*side\b|\?\s*side\s*:)/', $body) !== 1) {
        $fail[] = '[js] storageKey() does not key by the slot side directly: ' . trim(preg_replace('/\s+/', ' ', $body));
    }
    if (preg_match("/'side'\\s*\\?\\s*'start'|'end'\\s*\\?\\s*'end'\\s*:\\s*'start'/", $body) === 1
        && preg_match('/[\'"]side[\'"]/', $body) !== 1) {
        $fail[] = '[js] storageKey() folds side into start';
    }
}

// 4) 不回归：既有标记 / 召回把手 / 贴边位移必须仍在。
$mustKeep = [
    'css' => [
        'storefront-float-edge-dock-v1',
        'storefront-float-edge-flush-v2',
        'storefront-float-edge-transform-clear-v1',
        'storefront-float-consent-clearance-v1',
        '.w-storefront-float-edge__recall',
        'translateX(-100%)',
        'translateX(100%)',
    ],
    'footer' => [
        'data-storefront-float-layer-critical',
        'storefront-float-edge-transform-clear-v1',
        'is-edge-collapsed',
        'data-float-slot="side"',
        '#w-storefront-float-layer{',
    ],
    'host' => [
        'publishedSlotLayerMarkup',
        'sourceSlotLayerMarkup',
        'data-storefront-float-layer-critical',
        'storefront-float-edge-label-heal-v1',
        'data-slot-id="storefront-float-side"',
    ],
];
foreach ($mustKeep as $key => $needles) {
    if (!isset($texts[$key])) {
        continue;
    }
    foreach ($needles as $needle) {
        if (!str_contains($texts[$key], $needle)) {
            $fail[] = "[{$key}] regression: missing {$needle}";
        }
    }
}

// 5) 内联 critical CSS 必须自带贴边 chrome（收起后暴露的召回把手不能被隐藏/拖出视口）。
//    实测：发布态宿主 markup 不带外部表 <link>，漏掉这些规则时召回把手 position:static、
//    随槽位 translateX 整块移出视口 → 用户「收起后找不到了」。
$chromeMarker = 'storefront-float-edge-chrome-critical-v1';
$chromeNeedes = [
    '.w-storefront-float-edge__recall{',
    '.w-storefront-float-edge__dismiss{',
    'right:100%',
    'left:100%',
];
$expectedChromeBlocks = ['footer' => 1, 'host' => 2];
foreach ($expectedChromeBlocks as $key => $expected) {
    $count = substr_count($texts[$key], $chromeMarker);
    if ($count !== $expected) {
        $fail[] = "[{$key}] critical chrome marker {$chromeMarker} expected {$expected} block(s), found {$count}";
    }
    foreach ($chromeNeedes as $needle) {
        if (!str_contains($texts[$key], $needle)) {
            $fail[] = "[{$key}] critical chrome rule missing: {$needle}";
        }
    }
}

if ($fail !== []) {
    foreach ($fail as $line) {
        fwrite(STDOUT, "FAIL: {$line}\n");
    }
    exit(1);
}

fwrite(STDOUT, "FLOAT_EDGE_RECALL_OK\n");
exit(0);
