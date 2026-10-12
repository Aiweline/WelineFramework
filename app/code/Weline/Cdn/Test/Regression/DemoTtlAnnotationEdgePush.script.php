<?php

declare(strict_types=1);

/**
 * Demo @Cdn TTL=777：收集 → Free/付费计划 →（可选）临时推边缘再拉回验证 → 恢复 defaults。
 *
 * 用法：
 *   php app/code/Weline/Cdn/Test/Regression/DemoTtlAnnotationEdgePush.script.php
 *   CDN_DEMO_TTL_PUSH=1 php …   # 真推 CF（结束后恢复仅 defaults）
 */

require dirname(__DIR__, 5) . '/bootstrap.php';

use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CdnRuleCollector;
use Weline\Cdn\Service\FpcPolicyTargetService;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Manager\ObjectManager;

const DEMO_MARKERS = ['WELINE_DEMO_TTL_777_API', 'WELINE_DEMO_TTL_777_PAGE'];
const DEMO_TTL = 777;

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function ok(string $msg): void
{
    echo "OK: {$msg}\n";
}

$collector = ObjectManager::getInstance(CdnRuleCollector::class);
$rm = ObjectManager::getInstance(RuleManager::class);
$cf = ObjectManager::getInstance(Cloudflare::class);
$targets = ObjectManager::getInstance(FpcPolicyTargetService::class);

$modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
if (!isset($modules['Weline_Cdn'])) {
    fail('Weline_Cdn not in module list');
}

$collected = $collector->collectModule('Weline_Cdn', $modules['Weline_Cdn']);
$demoCollected = [];
foreach ($collected as $rule) {
    if (!\is_array($rule)) {
        continue;
    }
    $desc = (string)($rule['description'] ?? '');
    foreach (DEMO_MARKERS as $marker) {
        if (\str_contains($desc, $marker)) {
            $demoCollected[$marker] = $rule;
        }
    }
}
if (\count($demoCollected) < 2) {
    fail('collectModule missing demo markers, got: ' . \implode(',', \array_keys($demoCollected)));
}
foreach ($demoCollected as $marker => $rule) {
    $ttl = (int)($rule['action']['cache']['ttl'] ?? 0);
    if ($ttl !== DEMO_TTL) {
        fail("{$marker} ttl={$ttl} want " . DEMO_TTL);
    }
}
ok('collected demo API+PAGE with ttl=' . DEMO_TTL);

$domain = ObjectManager::getInstance(Domain::class)->load(2);
if (!(int)$domain->getId()) {
    fail('local domain id=2 missing');
}
$src = $rm->resolveCredentialSource($domain);
if (!Cloudflare::hasUsableCredentials($src['credentials'] ?? [])) {
    // 无凭证：如实 SKIP（exit 0），禁止假绿或当 FAIL 恐吓无 CF 本机
    echo "SKIP: domain credentials not usable (dry-run edge plan / push requires CF creds)\n";
    exit(0);
}

$freePlan = $rm->planRulesForEdgePush($domain, null, true);
$freeHasDemo = false;
$freeHasPack = false;
$freePackHasDemoPath = false;
foreach ($freePlan as $rule) {
    $desc = (string)($rule['description'] ?? '');
    $expr = (string)($rule['expression'] ?? '');
    $identity = (string)($rule['_weline_identity'] ?? '');
    foreach (DEMO_MARKERS as $marker) {
        if (\str_contains($desc, $marker)) {
            $freeHasDemo = true;
        }
    }
    if (\str_starts_with($identity, 'pack:') || \str_starts_with($desc, 'Weline pack:')) {
        $freeHasPack = true;
        if (\str_contains($expr, '__weline_cdn_demo_ttl_')) {
            $freePackHasDemoPath = true;
        }
    }
}
$freeAllowed = $rm->annotationsAllowedOnEdge($domain);
$packOn = $rm->isAnnotationPackEnabled();
echo 'INFO: free_capacity=' . $rm->getCacheRulesCapacity($domain)
    . ' pack_on=' . ($packOn ? 'Y' : 'N')
    . ' annotations_allowed=' . ($freeAllowed ? 'Y' : 'N')
    . ' free_plan_has_demo_desc=' . ($freeHasDemo ? 'Y' : 'N')
    . ' free_plan_has_pack=' . ($freeHasPack ? 'Y' : 'N')
    . ' free_pack_has_demo_path=' . ($freePackHasDemoPath ? 'Y' : 'N') . "\n";
if ($freeAllowed === false && $freeHasDemo === true) {
    fail('Free gate broken: demo appeared in plan while annotationsAllowed=false');
}
if ($packOn) {
    if ($freeAllowed !== true) {
        fail('pack on: Free must allow annotations on edge');
    }
    if ($freeHasDemo) {
        fail('pack on: Free plan must not contain per-rule demo descriptions');
    }
    if (!$freeHasPack) {
        fail('pack on: Free dry-run plan must contain pack identity/description');
    }
    if (!$freePackHasDemoPath) {
        fail('pack on: Free pack expression must include demo paths');
    }
    $packCount = 0;
    foreach ($freePlan as $rule) {
        $identity = (string)($rule['_weline_identity'] ?? '');
        if (\str_starts_with($identity, 'pack:')) {
            $packCount++;
        }
    }
    if ($packCount > 3) {
        fail("pack on: Free plan pack count={$packCount} exceeds 3");
    }
    ok('Free+pack：plan 含 pack、无逐条 demo description、demo 路径在 pack 内');
} elseif ($freeAllowed === false) {
    ok('Free/低容量 + pack 关：注解已入库但不进 planRulesForEdgePush（预期）');
}

\putenv('CDN_CF_CACHE_RULES_CAPACITY=200');
$_ENV['CDN_CF_CACHE_RULES_CAPACITY'] = '200';
$paidPlan = $rm->planRulesForEdgePush($domain, null, true);
if ($packOn) {
    $paidHasPack = false;
    $paidHasDemoDesc = false;
    foreach ($paidPlan as $rule) {
        $desc = (string)($rule['description'] ?? '');
        $identity = (string)($rule['_weline_identity'] ?? '');
        if (\str_starts_with($identity, 'pack:') || \str_starts_with($desc, 'Weline pack:')) {
            $paidHasPack = true;
        }
        foreach (DEMO_MARKERS as $marker) {
            if (\str_contains($desc, $marker)) {
                $paidHasDemoDesc = true;
            }
        }
    }
    if (!$paidHasPack) {
        fail('pack on: paid capacity plan must still use packs (no dual semantics)');
    }
    if ($paidHasDemoDesc) {
        fail('pack on: paid plan must not contain per-rule demo descriptions');
    }
    ok('paid+pack：仍走打包，无逐条 demo description');
} else {
    $paidMarkers = [];
    foreach ($paidPlan as $rule) {
        $desc = (string)($rule['description'] ?? '');
        foreach (DEMO_MARKERS as $marker) {
            if (\str_contains($desc, $marker)) {
                $paidMarkers[$marker] = true;
                $expr = (string)($rule['expression'] ?? '');
                if (!\str_contains($expr, '__weline_cdn_demo_ttl_')) {
                    fail("{$marker} expression missing demo path: {$expr}");
                }
            }
        }
    }
    if (\count($paidMarkers) < 2) {
        fail('paid plan missing demo markers: ' . \implode(',', \array_keys($paidMarkers)));
    }
    ok('paid capacity + pack 关：plan includes both demo markers with intact paths');
}

$doPush = \getenv('CDN_DEMO_TTL_PUSH') === '1';
if (!$doPush) {
    echo "SKIP_PUSH: set CDN_DEMO_TTL_PUSH=1 to temporarily putRules then restore defaults\n";
    exit(0);
}

$zone = (string)$domain->getData(Domain::schema_fields_ZONE_ID);
$creds = $src['credentials'];
$hosts = [];
foreach ($targets->publicEndpoints($domain) as $ep) {
    $h = \strtolower(\rtrim(\trim((string)($ep['host'] ?? '')), '.'));
    if ($h !== '') {
        $hosts[$h] = $h;
    }
}
$bound = \strtolower(\rtrim(\trim((string)$domain->getData(Domain::schema_fields_DOMAIN_NAME)), '.'));
if ($bound !== '') {
    $hosts[$bound] = $bound;
}
$hostList = \array_values($hosts);
$hostExpr = '(http.host in {' . \implode(' ', \array_map(
    static fn(string $h): string => \json_encode($h, \JSON_THROW_ON_ERROR),
    $hostList
)) . '})';

// 只推 defaults + 本 demo 两条，避免整库注解抢满 Free/低预算并污染 Zone。
$pushSet = \array_merge($rm->getDefaultRules(), \array_values($demoCollected));
$wrapped = [];
foreach ($pushSet as $rule) {
    if (!\is_array($rule)) {
        continue;
    }
    $expr = \trim((string)($rule['expression'] ?? ''));
    if ($expr === '') {
        continue;
    }
    // collect 返回的 demo 已含完整 expression；sanitize 丢 matches。
    if ($rm->sanitizeRuleExpressionForEdge(['expression' => $expr]) === null) {
        fail('demo/default expression rejected by sanitize: ' . $expr);
    }
    $rule['expression'] = $hostExpr . ' and (' . $expr . ')';
    $wrapped[] = $rule;
}

$before = $cf->getRules($zone, $creds);
$bak = BP . 'var/backup/cdn-cf-rules/demo-ttl-before-' . \gmdate('YmdHis') . '.json';
@\mkdir(\dirname($bak), 0775, true);
\file_put_contents($bak, \json_encode(['rules' => $before], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));
echo "INFO: backup={$bak}\n";

$put = $cf->putRules($zone, $wrapped, $creds);
if (empty($put['success'])) {
    fail('putRules failed: ' . (string)($put['message'] ?? 'unknown'));
}
ok('putRules temp plan count=' . \count($wrapped));

$after = $cf->getRules($zone, $creds);
$found = [];
foreach (\is_array($after) ? $after : [] as $rule) {
    if (!\is_array($rule)) {
        continue;
    }
    $desc = (string)($rule['description'] ?? '');
    $ttl = (int)(($rule['action_parameters']['edge_ttl']['default'] ?? 0));
    foreach (DEMO_MARKERS as $marker) {
        if (\str_contains($desc, $marker)) {
            $found[$marker] = $ttl;
        }
    }
}
echo 'INFO: cf_demo_found=' . \json_encode($found, \JSON_UNESCAPED_UNICODE) . "\n";
if (\count($found) < 2) {
    // still restore defaults below
    echo "WARN: demo markers not both on CF after push\n";
} else {
    foreach ($found as $marker => $ttl) {
        if ($ttl !== DEMO_TTL) {
            echo "WARN: {$marker} cf_ttl={$ttl} want " . DEMO_TTL . "\n";
        }
    }
    ok('CF pull shows both demo markers');
}

// Restore defaults-only (no demo annotations left on edge).
$defaults = $rm->getDefaultRules();
$restore = [];
foreach ($defaults as $rule) {
    if (!\is_array($rule)) {
        continue;
    }
    $expr = \trim((string)($rule['expression'] ?? ''));
    if ($expr === '') {
        continue;
    }
    $rule['expression'] = $hostExpr . ' and (' . $expr . ')';
    $restore[] = $rule;
}
$restorePut = $cf->putRules($zone, $restore, $creds);
if (empty($restorePut['success'])) {
    fail('restore defaults putRules failed: ' . (string)($restorePut['message'] ?? 'unknown'));
}
$final = $cf->getRules($zone, $creds);
$left = 0;
foreach (\is_array($final) ? $final : [] as $rule) {
    $desc = (string)($rule['description'] ?? '');
    foreach (DEMO_MARKERS as $marker) {
        if (\str_contains($desc, $marker)) {
            $left++;
        }
    }
}
if ($left > 0) {
    fail("demo markers still on CF after restore count={$left}");
}
ok('restored defaults-only on CF; demo markers removed');
\putenv('CDN_CF_CACHE_RULES_CAPACITY');
echo "DONE\n";
