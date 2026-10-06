<?php
// 机制感知版架构图生成器 v2 —— 五类证据通道：requires(排序)/optional/provides(DI)/event.xml/extends/view-hooks
// 输入: /tmp/weline-modules.json, /tmp/mech-classified.json（提取脚本见 evidence.md）
// 产物: weline-mechanism-map.dot|svg（装配机制概念图）
//       weline-core-overview.dot|svg（核心骨架·边按机制分类着色）
//       weline-module-dependencies-full.dot|svg（全量·带机制角标）
$d = json_decode(file_get_contents('/tmp/weline-modules.json'), true);
$M = json_decode(file_get_contents('/tmp/mech-classified.json'), true);
$mechEdges = $M['mech'];   // [from,to,type] type=event|extends
$hooks = $M['hooks'];      // module => hook-file count

// 合并机制索引: "from|to" => [types...]
$mx = [];
foreach ($mechEdges as [$f, $t, $ty]) $mx["$f|$t"][ $ty] = true;

$platform = ['Weline_Base','Weline_Acl','Weline_SystemConfig','Weline_Backend','Weline_Admin','Weline_Api','Weline_I18n','Weline_Websites','Weline_Currency','Weline_Queue','Weline_Cron','Weline_Event','Weline_Hook','Weline_Server','Weline_Storage','Weline_StorageOss','Weline_SessionManager','Weline_PhpManager','Weline_DbManager','Weline_Database','Weline_Deploy','Weline_Terraform','Weline_AliDdnsServer','Weline_RdpWrapper','Weline_ModuleManager','Weline_ModuleRouter','Weline_UrlManager','Weline_Installer','Weline_WarmCache','Weline_Indexer','Weline_WebsiteMonitoring','Weline_Bt_Center','Weline_DeveloperWorkspace','Weline_BackendActivity','Weline_CacheManager','Weline_Code','Weline_Extends','Weline_Component','Weline_Layout','Weline_Taglib','Weline_Vue','Weline_DataTable','Weline_SampleModule','Weline_Parts','Weline_Index','Weline_Benchmark','Weline_Maintenance','Weline_Trash','Weline_Captcha','Weline_ConSent'];
$storefront = ['Weline_Frontend','Weline_Theme','Weline_ThemeFancy','Weline_BackendThemeUpzet','Weline_Widget','Weline_WidgetDemo','Weline_Eav','Weline_Catalog','Weline_Product','Weline_Search','Weline_Compare','Weline_Wishlist','Weline_RecentlyViewed','Weline_Review','Weline_Inquiry','Weline_Visitor','Weline_Location','Weline_Seo','Weline_Meta','Weline_GenerativeEngineOptimization','Weline_Sticker','Weline_StoreMusic','Weline_StorePet','Weline_Daocharms3d','Weline_MediaManager','Weline_FileManager','Weline_EditorManager','Weline_CKEditorEditorManager','Weline_ElFinderFileManager','Weline_Filters','Weline_Cms','Weline_Blog','Weline_Faq','Weline_Newsletter','Weline_Social','Weline_TranslationService','Weline_AiKnowledge'];
function gid(string $n): string
{
    global $platform, $storefront;
    if ($n === 'Weline_Framework') return 'kernel';
    if (in_array($n, $platform)) return 'platform';
    if (in_array($n, $storefront)) return 'storefront';
    return 'commerce';
}
$gcolor = ['kernel' => '#b06000', 'platform' => '#1a73e8', 'storefront' => '#188038', 'commerce' => '#d93025'];
$bg = ['kernel' => '#fff3cd', 'platform' => '#e8f0fe', 'storefront' => '#e6f4ea', 'commerce' => '#fce8e6'];

// ---------- 图 A：装配机制概念图（Mermaid，Markdown 原生） ----------
$mmd = <<<'EOM'
%% Weline 模块装配机制总览（证据: Framework/Register/Register.php:890-940, Module/Dependency/Sort.php,
%% Manager/ObjectManager.php:146-205/761, Extends/ExtendsScanner.php, Event/EventRegistry.php,
%% Registry/Service/RegistryModulePresence.php, Hook/readme.txt）
flowchart TB
    subgraph Decl["声明层（每模块 etc/module.php + register.php）"]
        REQ["requires<br/>唯一驱动加载拓扑排序"]
        OPT["optional<br/>不参与排序；Composer suggest 对账"]
        PROV["provides 接口=>实现<br/>编译进 ServiceProviderRegistry"]
    end
    subgraph Order["Framework\\Register::扫描"]
        TOPO["dependenciesSort(requires) DFS<br/>决定模块 enable/加载顺序"]
    end
    subgraph Reg["注册表层（setup:upgrade 增量刷新 · generated/*.php）"]
        EXT["ExtendsRegistry<br/>扫描 extends/module/&lt;Target&gt;/&lt;Point&gt;/<br/>按 extends.php 规约校验接口契约"]
        EVR["EventRegistry<br/>解析 etc/event.xml 观察者<br/>sync/async+retry+coalesce"]
        HKR["HookRegistry<br/>view/hooks/&lt;name&gt;.phtml"]
    end
    subgraph Gate["在场门禁 RegistryModulePresence::isActivePresent()"]
        G1["目标模块 status+源码目录+register.php<br/>缺席 => 该装配整体跳过"]
    end
    subgraph Run["运行期"]
        OM["ObjectManager.getInstance(接口)<br/>provides 为权威绑定；Factory 仅迁移桥"]
        RPR["RuntimeProviderResolver<br/>可选能力 null-safe 解析"]
        DISP["EventsManager 派发 / Hooker 渲染"]
    end
    REQ --> TOPO
    OPT -.文档对账.-> Composer["composer.json suggest"]
    PROV --> OM
    EXT --> Gate
    EVR --> Gate
    HKR --> Gate
    Gate -->|在场| OM
    Gate -->|在场| RPR
    Gate -->|在场| DISP
    classDef decl fill:#e8f0fe; classDef reg fill:#e6f4ea; classDef run fill:#fce8e6;
    class REQ,OPT,PROV,TOPO decl; class EXT,EVR,HKR,G1 reg; class OM,RPR,DISP run;
EOM;
file_put_contents(__DIR__ . '/weline-assembly-mechanisms.mmd', $mmd . "\n");

// ---------- 图 B：核心骨架（分层 rank + 边按机制类型着色） ----------
$hubs = ['Weline_Framework','Weline_Backend','Weline_Admin','Weline_Acl','Weline_SystemConfig','Weline_I18n','Weline_Websites','Weline_Theme','Weline_Widget','Weline_Frontend','Weline_Eav','Weline_Queue','Weline_Cron','Weline_Currency','Weline_Storage','Weline_Server','Weline_ModuleRouter','Weline_Taglib','Weline_DataTable','Weline_Component','Weline_Product','Weline_Catalog','Weline_Customer','Weline_Cart','Weline_Checkout','Weline_Payment','Weline_Order','Weline_Inventory','Weline_Shipping','Weline_Tax','Weline_Marketing','Weline_Promotion','Weline_Search','Weline_Seo','Weline_Meta','Weline_Mail','Weline_Smtp','Weline_Ai','Weline_B2B','Weline_Vendor','Weline_Dropship','Weline_Subscription','Weline_MediaManager','Weline_FileManager','Weline_Cms','Weline_Blog','Weline_SessionManager','Weline_Event','Weline_Hook','Weline_Location','Weline_Review','Weline_Inquiry','Weline_Visitor','Weline_Dashboard','Weline_Multipass','Weline_ModuleManager','Weline_UrlManager','Weline_Consent','Weline_Maintenance','Weline_TwoFactorAuth','Weline_SiteSetupAssistant'];
$L = [];
$L[] = '// Weline 核心骨架 v2：边按装配机制分类。蓝实线=requires(排序驱动) 灰点线=optional(suggest对账)';
$L[] = '// 绿虚线=event.xml订阅 紫虚线=extends装配 橙粗线=provides被消费枢纽。来源: 五通道证据，2026-10-05。';
$L[] = 'digraph weline_core_mech {';
$L[] = '  graph [rankdir=BT, ranksep=0.9, nodesep=0.35, fontname="PingFang SC", labelloc=t, fontsize=18]';
$L[] = '  graph [label="Weline 核心骨架 · 集成机制视图（边的颜色=装配通道）"]';
$L[] = '  node [shape=box, style="rounded,filled", fontsize=11, margin="0.12,0.06", fontcolor=white]';
$L[] = '  edge [arrowsize=0.7]';
$tiers = [
    'Weline_Ai Weline_B2B Weline_Checkout Weline_Customer Weline_Dashboard Weline_Dropship Weline_Inquiry Weline_Mail Weline_Marketing Weline_MediaManager Weline_Multipass Weline_Order Weline_Payment Weline_Promotion Weline_Review Weline_Search Weline_Seo Weline_Shipping Weline_Subscription Weline_Tax Weline_Vendor Weline_Visitor Weline_TwoFactorAuth Weline_Maintenance Weline_Consent Weline_SiteSetupAssistant',
    'Weline_Cart Weline_Catalog Weline_Cms Weline_Eav Weline_Frontend Weline_Inventory Weline_Location Weline_Product Weline_Widget Weline_Blog Weline_UrlManager Weline_Taglib Weline_ModuleManager Weline_SessionManager',
    'Weline_Acl Weline_Admin Weline_Backend Weline_Component Weline_Cron Weline_Currency Weline_DataTable Weline_Event Weline_FileManager Weline_I18n Weline_ModuleRouter Weline_Queue Weline_Server Weline_Smtp Weline_SystemConfig Weline_Websites Weline_Marketing_L2',
    'Weline_Framework',
];
foreach ($tiers as $tier) {
    $nodes = array_values(array_filter(explode(' ', $tier), fn($n) => in_array($n, $hubs)));
    if ($nodes) $L[] = '  { rank=same "' . implode('" "', $nodes) . '" }';
}
foreach ($hubs as $n) {
    if (!isset($d[$n])) continue;
    $prov = $d[$n]['provides'] ? ' #' . $d[$n]['provides'] : '';
    $hk = isset($hooks[$n]) ? " h{$hooks[$n]}" : '';
    $L[] = sprintf('  "%s" [label="%s%s%s", fillcolor="%s"];', $n, substr($n, 7), $prov, $hk, $gcolor[gid($n)]);
}
foreach ($hubs as $from) {
    if (!isset($d[$from])) continue;
    foreach ($d[$from]['requires'] as $to) {
        if (!in_array($to, $hubs) || !isset($d[$to])) continue;
        $types = $mx["$from|$to"] ?? [];
        if (isset($types['event']) && isset($types['extends'])) $col = '#7b1fa2'; // both mechanisms
        elseif (isset($types['event'])) $col = '#2e7d32'; // event
        elseif (isset($types['extends'])) $col = '#7b1fa2'; // extends
        else $col = $gcolor[gid($to)];
        $pen = isset($types['event']) || isset($types['extends']) ? '1.6' : '1';
        $sty = (isset($types['event']) xor isset($types['extends'])) ? 'dashed' : (isset($types['event']) && isset($types['extends']) ? 'bold,dashed' : 'solid');
        $L[] = sprintf('  "%s" -> "%s" [color="%s", style="%s", penwidth=%s];', $from, $to, $col, $sty, $pen);
    }
    foreach ($d[$from]['optional'] as $to) {
        if (!in_array($to, $hubs) || !isset($d[$to])) continue;
        $L[] = sprintf('  "%s" -> "%s" [style=dotted, color="#9e9e9e"];', $from, $to);
    }
}
$L[] = '  subgraph cluster_legend { rank=source; style=filled; fillcolor=white; color="#cccccc"; label="边图例"; fontsize=11;';
$L[] = '    node [shape=plaintext, fontsize=10, fontcolor=black]';
$L[] = '    lg [label=<';
$L[] = '      <table border="0" cellborder="0" cellspacing="2">';
$L[] = '        <tr><td align="left"><font color="#1a73e8">━━━━</font> requires（纯类引用/排序）</td><td align="left"><font color="#2e7d32">╌╌</font> requires + event.xml 订阅</td></tr>';
$L[] = '        <tr><td align="left"><font color="#7b1fa2">╌╌╌</font> requires + extends 装配</td><td align="left"><font color="#9e9e9e">┄┄┄</font> optional（Composer suggest 对账，不排序）</td></tr>';
$L[] = '        <tr><td colspan="2" align="left">节点: #N=provides 接口数 · hN=view/hooks 文件数 · 分层=扇入归纳(非官方)</td></tr>';
$L[] = '      </table>>];';
$L[] = '  }';
$L[] = '}';
file_put_contents(__DIR__ . '/weline-core-overview.dot', implode("\n", $L) . "\n");

// ---------- 图 C：全量 120 模块 · 机制分类边（cluster 分组，供过滤查看） ----------
$L = [];
$L[] = '// Weline 整仓全量依赖 v2：requires 按装配通道着色（蓝=纯类引用 绿=event.xml 紫=extends），optional 灰点线。';
$L[] = '// 五通道证据: module.php requires/optional/provides + event.xml + extends/ + view/hooks。2026-10-05。';
$L[] = 'digraph weline_full {';
$L[] = '  graph [rankdir=TB, ranksep=0.6, nodesep=0.22, fontname="PingFang SC", labelloc=t, fontsize=18]';
$L[] = '  graph [label="Weline 全量模块 · 机制分类依赖图（#N=provides hN=hooks ev=事件订阅 ex=extends装配）"]';
$L[] = '  node [shape=box, style="rounded,filled", fontsize=9, margin="0.08,0.04", fontcolor=white]';
$L[] = '  edge [arrowsize=0.6]';
$titles = ['kernel' => 'L0 内核', 'platform' => 'L1 平台/基础设施', 'storefront' => 'L2 店面/内容/主题', 'commerce' => 'L3 商务/交易/AI'];
$members = ['kernel' => [], 'platform' => [], 'storefront' => [], 'commerce' => []];
foreach (array_keys($d) as $n) $members[gid($n)][] = $n;
foreach ($members as $g => $ns) {
    if (!$ns) continue;
    sort($ns);
    $L[] = "  subgraph cluster_$g {";
    $L[] = "    graph [style=filled, fillcolor=\"$bg[$g]\", color=\"#bbbbbb\", label=\"  {$titles[$g]}\", fontsize=12, fontcolor=\"#333333\"];";
    foreach ($ns as $n) {
        $tags = '';
        if ($d[$n]['provides']) $tags .= ' #' . $d[$n]['provides'];
        if (!empty($hooks[$n])) $tags .= ' h' . $hooks[$n];
        $evc = 0; $exc = 0;
        foreach ($mechEdges as [$f, $t, $ty]) if ($f === $n) { $ty === 'event' ? $evc++ : $exc++; }
        if ($evc) $tags .= " ev$evc";
        if ($exc) $tags .= " ex$exc";
        $L[] = sprintf('    "%s" [label="%s%s", fillcolor="%s"];', $n, substr($n, 7), $tags, $gcolor[$g]);
    }
    $L[] = '  }';
}
foreach ($d as $from => $info) {
    foreach ($info['requires'] as $to) {
        if (!isset($d[$to])) continue;
        $types = $mx["$from|$to"] ?? [];
        if (isset($types['event']) && isset($types['extends'])) $col = '#7b1fa2';
        elseif (isset($types['event'])) $col = '#2e7d32';
        elseif (isset($types['extends'])) $col = '#7b1fa2';
        else $col = $gcolor[gid($to)];
        $sty = isset($types['event']) || isset($types['extends']) ? 'dashed' : 'solid';
        $L[] = sprintf('  "%s" -> "%s" [color="%s", style=%s];', $from, $to, $col, $sty);
    }
    foreach ($info['optional'] as $to) {
        if (!isset($d[$to])) continue;
        $L[] = sprintf('  "%s" -> "%s" [style=dotted, color="#aaaaaa"];', $from, $to);
    }
}
// 仅机制、无 requires 声明的装配边（未声明集成——治理候选）用点划黄线显式画出
$undeclared = [];
foreach ($mechEdges as [$f, $t, $ty]) {
    if (!isset($d[$f]) || !isset($d[$t])) continue;
    if (in_array($t, $d[$f]['requires']) || in_array($t, $d[$f]['optional'])) continue;
    if (isset($undeclared["$f|$t"])) continue;
    $undeclared["$f|$t"] = true;
    $L[] = sprintf('  "%s" -> "%s" [color="#f9a825", penwidth=1.4];', $f, $t);
}
$L[] = '}';
file_put_contents(__DIR__ . '/weline-module-dependencies-full.dot', implode("\n", $L) . "\n");

echo "v2 written: mechanisms mmd + core dot (hubs=" . count($hubs) . ") + full dot (undeclared mech edges=" . count($undeclared) . ")\n";
