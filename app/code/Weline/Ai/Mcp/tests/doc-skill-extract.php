<?php

declare(strict_types=1);

use LearningMcp\DocSkillCatalog;
use LearningMcp\HardConstraintsCatalog;
use LearningMcp\McpSkillCatalog;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;
$checks = [];
$repository = dirname(__DIR__, 6);
if (!is_dir($repository . '/app/code')) {
    $repository = dirname(__DIR__, 5);
    if (is_dir($repository . '/code')) {
        $repository = dirname($repository);
    }
}

function extractCheck(bool $ok, string $label): void
{
    global $failed, $checks;
    $checks[] = ['label' => $label, 'passed' => $ok];
    fwrite(($ok ? STDOUT : STDERR), ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failed = true;
    }
}

extractCheck(is_dir($repository . '/app/code'), 'repository root resolved');

$docSkills = DocSkillCatalog::extractSkills($repository);
extractCheck($docSkills !== [], 'extracts at least one module doc skill/locator');
$hasModuleManager = false;
$hasAiIndex = false;
foreach ($docSkills as $skill) {
    if (($skill['skill_id'] ?? '') === 'doc:weline-modulemanager-knowledge') {
        $hasModuleManager = true;
    }
    if (str_starts_with((string) ($skill['skill_id'] ?? ''), 'doc-index:')) {
        $hasAiIndex = true;
    }
}
extractCheck($hasModuleManager, 'includes ModuleManager INDEX.json skill');
extractCheck($hasAiIndex, 'synthesizes AI-INDEX locator skills');

$commands = DocSkillCatalog::extractCommands($repository);
extractCheck($commands !== [], 'extracts ai-command instructions');
$hasExtractCmd = false;
foreach ($commands as $command) {
    if (str_contains((string) ($command['path'] ?? ''), '提取技能.md')) {
        $hasExtractCmd = true;
        break;
    }
}
extractCheck($hasExtractCmd, 'includes 提取技能 command file');

$shentuCmd = null;
foreach ($commands as $command) {
    if (str_contains((string) ($command['path'] ?? ''), 'theme/审图.md')) {
        $shentuCmd = $command;
        break;
    }
}
extractCheck(is_array($shentuCmd), 'includes 审图 command file');
extractCheck(
    is_array($shentuCmd)
    && ($shentuCmd['hard_trigger']['kind'] ?? '') === 'image_attachment',
    '审图 command hard_trigger kind is image_attachment'
);
extractCheck(
    is_array($shentuCmd)
    && in_array('image_attachment', $shentuCmd['triggers'] ?? [], true)
    && in_array('用户附图', $shentuCmd['triggers'] ?? [], true),
    '审图 command triggers include attachment keywords'
);
extractCheck(
    is_array($shentuCmd)
    && is_string($shentuCmd['command_id'] ?? null)
    && ($shentuCmd['command_id'] ?? '') !== 'cmd:-'
    && str_starts_with((string) ($shentuCmd['command_id'] ?? ''), 'cmd:'),
    '审图 command_id is stable (not cmd:-)'
);

$detailOptimizeCmd = null;
$productOptimizeCmd = null;
foreach ($commands as $command) {
    $path = (string) ($command['path'] ?? '');
    if (str_contains($path, 'product/详情优化.md')) {
        $detailOptimizeCmd = $command;
    }
    if (str_contains($path, 'product/产品优化.md')) {
        $productOptimizeCmd = $command;
    }
}
extractCheck(is_array($detailOptimizeCmd), 'includes 详情优化 command file');
extractCheck(is_array($productOptimizeCmd), 'includes 产品优化 parent command file');
$i18nOptimizeCmd = null;
foreach ($commands as $command) {
    $path = (string) ($command['path'] ?? '');
    if (str_contains($path, 'product/翻译优化.md')) {
        $i18nOptimizeCmd = $command;
    }
}
extractCheck(is_array($i18nOptimizeCmd), 'includes 翻译优化 command file');
extractCheck(
    is_array($i18nOptimizeCmd)
    && ($i18nOptimizeCmd['hard_trigger']['kind'] ?? '') === 'product_pdp_url'
    && in_array('翻译优化', $i18nOptimizeCmd['triggers'] ?? [], true),
    '翻译优化 command hard_trigger kind is product_pdp_url and triggers include 翻译优化'
);
extractCheck(
    is_array($detailOptimizeCmd)
    && ($detailOptimizeCmd['hard_trigger']['kind'] ?? '') === 'product_pdp_url',
    '详情优化 command hard_trigger kind is product_pdp_url'
);
extractCheck(
    is_array($productOptimizeCmd)
    && ($productOptimizeCmd['hard_trigger']['kind'] ?? '') === 'product_pdp_url',
    '产品优化 command hard_trigger kind is product_pdp_url'
);
extractCheck(
    is_array($detailOptimizeCmd)
    && in_array('详情优化', $detailOptimizeCmd['triggers'] ?? [], true)
    && in_array('商详优化', $detailOptimizeCmd['triggers'] ?? [], true)
    && !in_array('产品优化', $detailOptimizeCmd['triggers'] ?? [], true)
    && !in_array('商品优化', $detailOptimizeCmd['triggers'] ?? [], true)
    && in_array('/product/', $detailOptimizeCmd['triggers'] ?? [], true),
    '详情优化 child triggers exclude 产品/商品优化 parent keywords'
);
extractCheck(
    is_array($productOptimizeCmd)
    && in_array('产品优化', $productOptimizeCmd['triggers'] ?? [], true)
    && in_array('商品优化', $productOptimizeCmd['triggers'] ?? [], true)
    && in_array('/product/', $productOptimizeCmd['triggers'] ?? [], true),
    '产品优化 parent triggers include 产品/商品优化 and /product/'
);

$blogArticleCmd = null;
foreach ($commands as $command) {
    if (str_contains((string) ($command['path'] ?? ''), 'blog/新建文章.md')) {
        $blogArticleCmd = $command;
        break;
    }
}
extractCheck(is_array($blogArticleCmd), 'includes 新建文章 command file');
extractCheck(
    is_array($blogArticleCmd)
    && ($blogArticleCmd['hard_trigger']['kind'] ?? '') === 'blog_article',
    '新建文章 command hard_trigger kind is blog_article'
);
extractCheck(
    is_array($blogArticleCmd)
    && in_array('新建文章', $blogArticleCmd['triggers'] ?? [], true)
    && in_array('审查文章', $blogArticleCmd['triggers'] ?? [], true)
    && in_array('/blog/', $blogArticleCmd['triggers'] ?? [], true),
    '新建文章 triggers include 新建/审查文章 and /blog/'
);

$rules = HardConstraintsCatalog::package()['rules'] ?? [];
$hasShentuRule = false;
$hasProductOptimizeRule = false;
$hasBlogArticleRule = false;
foreach ($rules as $rule) {
    if (is_array($rule) && ($rule['id'] ?? '') === 'user_image_attachment_triggers_shentu') {
        $hasShentuRule = true;
    }
    if (is_array($rule) && ($rule['id'] ?? '') === 'product_optimize_triggers_detail_suite') {
        $hasProductOptimizeRule = true;
    }
    if (is_array($rule) && ($rule['id'] ?? '') === 'blog_article_methodology_gate') {
        $hasBlogArticleRule = true;
    }
}
extractCheck($hasShentuRule, 'hard constraints include user_image_attachment_triggers_shentu');
extractCheck($hasProductOptimizeRule, 'hard constraints include product_optimize_triggers_detail_suite');
extractCheck($hasBlogArticleRule, 'hard constraints include blog_article_methodology_gate');

$policy = McpSkillCatalog::policy($repository);
$total = (int) ($policy['catalog_counts']['total'] ?? 0);
$workflowCount = (int) ($policy['catalog_counts']['workflow'] ?? 0);
extractCheck($total > $workflowCount, 'policy catalog merges workflow + doc skills');
extractCheck(is_array($policy['greeting'] ?? null), 'policy exposes greeting catalog');
extractCheck(is_array($policy['commands'] ?? null) && $policy['commands'] !== [], 'policy lists commands');

$listed = McpSkillCatalog::resolve('提取技能', 500, false, $repository, true);
$listedCount = is_array($listed) ? count($listed) : 0;
extractCheck($listedCount >= $total, 'resolve 提取技能 lists full catalog');

$ops = HardConstraintsCatalog::mcpOperationalRules();
$hasGreeting = false;
foreach ($ops as $rule) {
    if (is_array($rule) && ($rule['id'] ?? '') === 'greeting_lists_mcp_skills_and_commands') {
        $hasGreeting = true;
        break;
    }
}
extractCheck($hasGreeting, 'hard constraints include greeting_lists_mcp_skills_and_commands');

fwrite(STDOUT, json_encode([
    'schema_version' => 'doc-skill-extract-tests.v1',
    'passed' => !$failed,
    'doc_skill_count' => count($docSkills),
    'command_count' => count($commands),
    'catalog_counts' => $policy['catalog_counts'] ?? [],
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($failed ? 1 : 0);
