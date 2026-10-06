<?php
// 从 /tmp/weline-modules.json 重新生成整仓模块依赖 DOT（可重复执行）
// 产物：core=分层概览；full=全量边（供过滤查看）
$d = json_decode(file_get_contents('/tmp/weline-modules.json'), true);

$platform = ['Weline_Base','Weline_Acl','Weline_SystemConfig','Weline_Backend','Weline_Admin','Weline_Api','Weline_I18n','Weline_Websites','Weline_Currency','Weline_Queue','Weline_Cron','Weline_Event','Weline_Hook','Weline_Server','Weline_Storage','Weline_StorageOss','Weline_SessionManager','Weline_PhpManager','Weline_DbManager','Weline_Database','Weline_Deploy','Weline_Terraform','Weline_AliDdnsServer','Weline_RdpWrapper','Weline_ModuleManager','Weline_ModuleRouter','Weline_UrlManager','Weline_Installer','Weline_WarmCache','Weline_Indexer','Weline_WebsiteMonitoring','Weline_Bt_Center','Weline_DeveloperWorkspace','Weline_BackendActivity','Weline_CacheManager','Weline_Code','Weline_Extends','Weline_Component','Weline_Layout','Weline_Taglib','Weline_Vue','Weline_DataTable','Weline_SampleModule','Weline_Parts','Weline_Index','Weline_Benchmark','Weline_Maintenance','Weline_Trash','Weline_Captcha','Weline_ConSent'];
$storefront = ['Weline_Frontend','Weline_Theme','Weline_ThemeFancy','Weline_BackendThemeUpzet','Weline_Widget','Weline_WidgetDemo','Weline_Eav','Weline_Catalog','Weline_Product','Weline_Search','Weline_Compare','Weline_Wishlist','Weline_RecentlyViewed','Weline_Review','Weline_Inquiry','Weline_Visitor','Weline_Location','Weline_Seo','Weline_Meta','Weline_GenerativeEngineOptimization','Weline_Sticker','Weline_StoreMusic','Weline_StorePet','Weline_Daocharms3d','Weline_MediaManager','Weline_FileManager','Weline_EditorManager','Weline_CKEditorEditorManager','Weline_ElFinderFileManager','Weline_Filters','Weline_Cms','Weline_Blog','Weline_Faq','Weline_Newsletter','Weline_Social','Weline_TranslationService','Weline_AiKnowledge'];
$commerce = ['Weline_Customer','Weline_CustomerAsset','Weline_CustomerService','Weline_Multipass','Weline_TwoFactorAuth','Weline_Cart','Weline_Checkout','Weline_Payment','Weline_Shipping','Weline_Tax','Weline_Inventory','Weline_Order','Weline_Rma','Weline_Promotion','Weline_Marketing','Weline_Subscription','Weline_B2B','Weline_Affiliate','Weline_Vendor','Weline_Dropship','Weline_CjDropshipping','Weline_HelpPay','Weline_Smtp','Weline_Mail','Weline_Geo','Weline_Dashboard','Weline_AppStore','Weline_PlatformAppStore','Weline_SiteSetupAssistant','Weline_FakeData','Weline_WlsDemoPlugin','Weline_Agent','Weline_Ai'];

function gid(string $n, array $platform, array $storefront, array $commerce): string
{
    if ($n === 'Weline_Framework') return 'kernel';
    if (in_array($n, $platform)) return 'platform';
    if (in_array($n, $storefront)) return 'storefront';
    if (in_array($n, $commerce)) return 'commerce';
    return 'other';
}

$colors = ['kernel' => '#b06000', 'platform' => '#1a73e8', 'storefront' => '#188038', 'commerce' => '#d93025', 'other' => '#757575'];
$bg = ['kernel' => '#fff3cd', 'platform' => '#e8f0fe', 'storefront' => '#e6f4ea', 'commerce' => '#fce8e6', 'other' => '#f1f1f1'];
// 图例条标题（rank tier 自上而下的展示名，与 cluster 分组名分开）
$tierTitles = ['L3 商务/交易层', 'L2 店面/内容/主题层', 'L1 平台/基础设施层', 'L0 内核 Framework'];

function legend_dot(array $tiers, array $tierTitles): string
{
    global $colors;
    $map = ['L3' => 'commerce', 'L2' => 'storefront', 'L1' => 'platform', 'L0' => 'kernel'];
    $L = ['  subgraph cluster_legend { rank=source; style=filled; fillcolor=white; color="#cccccc"; label="读法：模块 -> 它依赖的模块（自上而下收敛到内核）；#N = provides 接口数"; fontsize=11; fontcolor="#333333";',
          '    node [shape=plaintext, fontcolor=black, fontsize=11]'];
    foreach ($tiers as $i => $tier) {
        $key = substr($tierTitles[$i], 0, 2);
        $c = $colors[$map[$key] ?? 'other'];
        $L[] = sprintf('    "%s" [label="%s", shape=box, style="filled,rounded", fillcolor="%s", fontcolor=white];', 'lg_' . $i, $tierTitles[$i], $c);
    }
    $L[] = '    ' . implode(' -> ', array_map(fn($i) => '"lg_' . $i . '"', array_keys($tiers))) . ' [style=invis]';
    $L[] = '  }';
    return implode("\n", $L) . "\n";
}
$titles = ['kernel' => 'L0 内核 Framework', 'platform' => 'L1 平台/基础设施', 'storefront' => 'L2 店面/内容/主题', 'commerce' => 'L3 商务/交易/AI', 'other' => '未分组'];

$members = ['kernel' => [], 'platform' => [], 'storefront' => [], 'commerce' => [], 'other' => []];
foreach (array_keys($d) as $n) $members[gid($n, $platform, $storefront, $commerce)][] = $n;

# ---------- 图 A：核心骨架概览（高扇入枢纽 + 主干 requires 边） ----------
$hubs = ['Weline_Framework','Weline_Backend','Weline_Admin','Weline_Acl','Weline_SystemConfig','Weline_I18n','Weline_Websites','Weline_Theme','Weline_Widget','Weline_Frontend','Weline_Eav','Weline_Queue','Weline_Cron','Weline_Currency','Weline_Storage','Weline_Server','Weline_ModuleRouter','Weline_Taglib','Weline_DataTable','Weline_Component','Weline_Product','Weline_Catalog','Weline_Customer','Weline_Cart','Weline_Checkout','Weline_Payment','Weline_Order','Weline_Inventory','Weline_Shipping','Weline_Tax','Weline_Marketing','Weline_Promotion','Weline_Search','Weline_Seo','Weline_Meta','Weline_Mail','Weline_Smtp','Weline_Ai','Weline_B2B','Weline_Vendor','Weline_Dropship','Weline_Subscription','Weline_MediaManager','Weline_FileManager','Weline_Cms','Weline_Blog','Weline_SessionManager','Weline_Event','Weline_Hook','Weline_Location','Weline_Review','Weline_Inquiry','Weline_Visitor','Weline_Dashboard','Weline_Multipass'];
$skeleton = [];
foreach ($hubs as $from) {
    if (!isset($d[$from])) continue;
    foreach ($d[$from]['requires'] as $to) {
        if (in_array($to, $hubs) && isset($d[$to])) $skeleton[] = [$from, $to];
    }
}
function emit_dot(array $nodeSet, array $edges, string $title, callable $cls, array $rankTiers = []): string
{
    global $d, $colors, $bg, $titles, $platform, $storefront, $commerce, $tierTitles;
    $L = [];
    $L[] = "// $title";
    $L[] = '// 来源: app/code/Weline/*/etc/module.php，生成日期 2026-10-05。再生成: php 架构可视化/generate-module-dot.php';
    $L[] = 'digraph {';
    $L[] = '  graph [rankdir=BT, ranksep=0.7, nodesep=0.3, fontname="PingFang SC", labelloc=t, fontsize=18]';
    $L[] = "  graph [label=\"$title\"]";
    $L[] = '  node [shape=box, style="rounded,filled", fontsize=11, margin="0.12,0.06", fontcolor=white]';
    $L[] = '  edge [arrowsize=0.7]';
    if ($rankTiers) {
        // 先声明各层 rank，再注入图例（rank=source 置于最底），避免整图被挤到一侧
        foreach ($rankTiers as $tier) {
            $nodes = array_values(array_filter(explode(' ', $tier), fn($n) => in_array($n, $nodeSet)));
            if ($nodes) $L[] = '  { rank=same "' . implode('" "', $nodes) . '" }';
        }
        $L[] = legend_dot($rankTiers, $tierTitles);
    }
    $byGroup = [];
    foreach ($nodeSet as $n) $byGroup[$cls($n)][] = $n;
    if (!$rankTiers) {
        // 非分层模式：按组 cluster（全量图）
    foreach ($byGroup as $g => $ns) {
        $L[] = "  subgraph cluster_$g {";
        $L[] = "    graph [style=filled, fillcolor=\"$bg[$g]\", color=\"#bbbbbb\", label=\"  {$titles[$g]}\", fontsize=13, fontcolor=\"#333333\"];";
        foreach ($ns as $n) {
            $short = substr($n, 7);
            $prov = $d[$n]['provides'] ? ' #' . $d[$n]['provides'] : '';
            $L[] = sprintf('    "%s" [label="%s%s", fillcolor="%s"];', $n, $short, $prov, $colors[$g]);
        }
        $L[] = '  }';
    }
    } else {
        // 分层模式：tier 顺序即视觉行序（rankdir=BT → L0 内核落底），节点平铺不用 cluster
        foreach ($nodeSet as $n) {
            $short = substr($n, 7);
            $prov = $d[$n]['provides'] ? ' #' . $d[$n]['provides'] : '';
            $L[] = sprintf('  "%s" [label="%s%s", fillcolor="%s"];', $n, $short, $prov, $colors[$cls($n)]);
        }
    }
    foreach ($edges as [$from, $to, $opt]) {
        if ($opt) $L[] = sprintf('  "%s" -> "%s" [style=dotted, color="#aaaaaa"];', $from, $to);
        else $L[] = sprintf('  "%s" -> "%s" [color="%s"];', $from, $to, $colors[$cls($to)]);
    }
    $L[] = '}';
    return implode("\n", $L) . "\n";
}
$skeletonEdges = [];
foreach ($skeleton as [$f, $t]) $skeletonEdges[] = [$f, $t, false];
# 分层 rank 约束：内核在底、平台居中、商务/店面在上，边方向 child -> dependency 向下收敛
$rankTiers = [
    'Weline_Ai Weline_B2B Weline_Checkout Weline_Customer Weline_Dashboard Weline_Dropship Weline_Inquiry Weline_Mail Weline_Marketing Weline_MediaManager Weline_Multipass Weline_Order Weline_Payment Weline_Promotion Weline_Review Weline_Search Weline_Seo Weline_Shipping Weline_Subscription Weline_Tax Weline_Vendor Weline_Visitor',
    'Weline_Cart Weline_Catalog Weline_Cms Weline_Eav Weline_Frontend Weline_Inventory Weline_Location Weline_Product Weline_Widget Weline_Blog',
    'Weline_Acl Weline_Admin Weline_Backend Weline_Component Weline_Cron Weline_Currency Weline_DataTable Weline_Event Weline_FileManager Weline_I18n Weline_ModuleRouter Weline_Queue Weline_Server Weline_SessionManager Weline_Smtp Weline_SystemConfig Weline_Taglib Weline_Theme Weline_Websites',
    'Weline_Framework',
];
file_put_contents(__DIR__ . '/weline-core-overview.dot',
    emit_dot($hubs, $skeletonEdges, 'Weline 核心骨架：分层与主干模块依赖（仅枢纽模块的 requires 边）',
        fn($n) => gid($n, $platform, $storefront, $commerce), $rankTiers));

# ---------- 图 B：全量依赖（含 optional 点线，供 Canvas 过滤） ----------
$fullNodes = array_keys($d);
$fullEdges = [];
$unresolved = [];
foreach ($d as $from => $info) {
    foreach ($info['requires'] as $to) {
        if (!isset($d[$to])) { $unresolved[] = "$from -> $to [requires]"; continue; }
        $fullEdges[] = [$from, $to, false];
    }
    foreach ($info['optional'] as $to) {
        if (!isset($d[$to])) { $unresolved[] = "$from -> $to [optional]"; continue; }
        $fullEdges[] = [$from, $to, true];
    }
}
file_put_contents(__DIR__ . '/weline-module-dependencies-full.dot',
    emit_dot($fullNodes, $fullEdges, 'Weline 整仓全量模块依赖（120 模块 · 实线 requires / 点线 optional · 节点名后 #N = provides 接口数）',
        fn($n) => gid($n, $platform, $storefront, $commerce)));

echo 'core: nodes=' . count($hubs) . " edges=" . count($skeletonEdges) . "\n";
echo 'full: nodes=' . count($fullNodes) . ' edges=' . count($fullEdges) . "\n";
foreach ($unresolved as $u) echo "  unresolved: $u\n";
