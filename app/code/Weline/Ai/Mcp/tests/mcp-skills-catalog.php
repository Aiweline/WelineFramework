<?php

declare(strict_types=1);

use LearningMcp\GuidanceWorkflowCatalog;
use LearningMcp\HardConstraintsCatalog;
use LearningMcp\McpSkillCatalog;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;
$checks = [];

function skillCheck(bool $ok, string $label): void
{
    global $failed, $checks;
    $checks[] = ['label' => $label, 'passed' => $ok];
    fwrite(($ok ? STDOUT : STDERR), ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failed = true;
    }
}

$policy = McpSkillCatalog::policy();
skillCheck(($policy['schema_version'] ?? '') === McpSkillCatalog::SCHEMA, 'policy schema is mcp-skills.v1');
skillCheck(($policy['provider'] ?? '') === 'mcp', 'policy provider is mcp');
skillCheck(($policy['static_skill_files'] ?? true) === false, 'policy forbids static skill files');
skillCheck(($policy['fetch']['discover'] ?? '') === 'resolve_skill', 'policy discover tool is resolve_skill');
skillCheck(($policy['fetch']['load'] ?? '') === 'get_skill', 'policy load tool is get_skill');
skillCheck(is_array($policy['catalog'] ?? null) && count($policy['catalog']) >= 8, 'policy catalog lists surfaces');

$theme = McpSkillCatalog::get('weline-theme-development', true);
skillCheck(is_array($theme), 'get by host alias weline-theme-development');
skillCheck(($theme['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT, 'theme alias maps to frontend_development');
skillCheck(is_string($theme['content'] ?? null) && str_contains((string) $theme['content'], 'get_skill'), 'theme skill body includes fetch instructions');
skillCheck(($theme['static_skill_files'] ?? true) === false, 'theme skill static_skill_files=false');

$browser = McpSkillCatalog::get('local-browser-urls', true);
skillCheck(is_array($browser), 'get by host alias local-browser-urls');
skillCheck(($browser['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT, 'browser alias maps to webui surface');

$taglib = McpSkillCatalog::get(GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL, true);
skillCheck(is_array($taglib), 'get by canonical surface id');
skillCheck(in_array('weline-taglib-first', $taglib['aliases'] ?? [], true), 'taglib skill exposes host alias');

$matched = McpSkillCatalog::resolve('Theme 部件 phtml 前端样式', 5, false);
skillCheck($matched !== [], 'resolve matches frontend task');
skillCheck(($matched[0]['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT, 'frontend task ranks theme skill first');

$ops = HardConstraintsCatalog::mcpOperationalRules();
$hasMcpSkillsRule = false;
foreach ($ops as $rule) {
    if (is_array($rule) && ($rule['id'] ?? '') === 'mcp_skills_fetch_from_mcp') {
        $hasMcpSkillsRule = true;
        break;
    }
}
skillCheck($hasMcpSkillsRule, 'hard constraints include mcp_skills_fetch_from_mcp');

$bundle = $policy['css_or_theme_skill_bundle'] ?? [];
skillCheck(($bundle['rule_id'] ?? '') === 'css_or_theme_requires_ui_prototype_theme_skills', 'policy exposes css_or_theme skill bundle');
skillCheck(is_array($bundle['required'] ?? null) && count($bundle['required']) === 3, 'css_or_theme bundle lists three skills');

$shentuBundle = $policy['image_attachment_shentu_bundle'] ?? [];
skillCheck(($shentuBundle['rule_id'] ?? '') === 'user_image_attachment_triggers_shentu', 'policy exposes image_attachment_shentu_bundle');
skillCheck(($shentuBundle['hard_trigger'] ?? '') === 'any_user_message_image_or_screenshot_attachment', 'shentu bundle hard_trigger is any image attachment');
skillCheck(($shentuBundle['command_path'] ?? '') === 'dev/ai-command/theme/审图.md', 'shentu bundle points at 审图 command');
skillCheck(($shentuBundle['host_skill'] ?? '') === 'weline-ui-shentu', 'shentu bundle names host thin skill');
skillCheck(
    is_array($shentuBundle['required_actions'] ?? null)
    && in_array('judge_humanization_and_aesthetics_with_frontend_design_and_prototype', $shentuBundle['required_actions'], true)
    && in_array('judge_theme_fit_with_weline_theme_development', $shentuBundle['required_actions'], true),
    'shentu bundle requires UI+prototype humanization/aesthetics and theme-fit actions'
);
skillCheck(
    is_array($shentuBundle['review_dimensions'] ?? null)
    && in_array('humanization', $shentuBundle['review_dimensions'], true)
    && in_array('aesthetic_standards', $shentuBundle['review_dimensions'], true)
    && in_array('theme_fit', $shentuBundle['review_dimensions'], true),
    'shentu bundle lists humanization/aesthetic/theme_fit dimensions'
);
$missingGate = $shentuBundle['missing_host_skills_gate'] ?? [];
skillCheck(($missingGate['check'] ?? '') === 'available_skills_or_agent_store', 'shentu missing_host_skills_gate check is available_skills_or_agent_store');
skillCheck(
    is_array($missingGate['required_host_skills'] ?? null)
    && in_array('frontend-design', $missingGate['required_host_skills'], true)
    && in_array('prototype', $missingGate['required_host_skills'], true),
    'shentu missing gate requires frontend-design + prototype'
);
skillCheck(
    is_array($missingGate['on_missing'] ?? null)
    && in_array('prompt_user_visible_warning', $missingGate['on_missing'], true)
    && in_array('self_install_to_cursor_agent_store', $missingGate['on_missing'], true),
    'shentu missing gate requires user prompt + self-install'
);
skillCheck(
    is_string($missingGate['prompt_template'] ?? null)
    && str_contains((string) $missingGate['prompt_template'], 'frontend-design')
    && str_contains((string) $missingGate['prompt_template'], 'prototype'),
    'shentu missing gate prompt names UI+prototype skills'
);
skillCheck(
    is_array($policy['instructions'] ?? null)
    && array_reduce(
        $policy['instructions'],
        static fn (bool $ok, mixed $line): bool => $ok || (is_string($line) && str_contains($line, 'image/screenshot attachment')),
        false
    ),
    'policy instructions mention image attachment → 审图'
);
skillCheck(
    is_array($policy['instructions'] ?? null)
    && array_reduce(
        $policy['instructions'],
        static fn (bool $ok, mixed $line): bool => $ok || (
            is_string($line)
            && str_contains($line, 'humanization')
            && str_contains($line, 'frontend-design')
            && str_contains($line, 'prototype')
        ),
        false
    ),
    'policy instructions require humanization via UI+prototype on 审图'
);

$taskContract = GuidanceWorkflowCatalog::forTask('验收 交付地址 Browser');
$webui = $taskContract['surfaces'][GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT] ?? [];
skillCheck(($webui['mcp_skill_fetch']['load'] ?? '') === 'get_skill', 'forTask exposes mcp_skill_fetch');
skillCheck(($webui['authoritative_skill'] ?? '') === 'local-browser-urls', 'webui surface keeps host shell alias');

fwrite(STDOUT, json_encode([
    'schema_version' => 'mcp-skills-catalog-tests.v1',
    'passed' => !$failed,
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($failed ? 1 : 0);
