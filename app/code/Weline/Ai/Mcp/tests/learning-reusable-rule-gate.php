<?php

declare(strict_types=1);

use LearningMcp\LearningReusableRuleGate;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;

function check(bool $condition, string $label): void
{
    global $failed;
    if (!$condition) {
        $failed = true;
        fwrite(STDERR, "[FAIL] $label\n");
    } else {
        fwrite(STDOUT, "[PASS] $label\n");
    }
}

$abstract = LearningReusableRuleGate::judge(
    'Machine-shared side effects (keychain Local CA, MCP bind) MUST stay scoped to the current workspace root.',
    'Workspace-scoped machine shared state',
    'Do not install sibling-repo rootCA.pem into the System keychain while IDE workspace is another project.',
    'Silently replacing a same-CN different-fingerprint keychain CA across sibling repos.',
);
check($abstract['ok'] === true, 'abstract rule with distinct examples passes');

$eqTitle = LearningReusableRuleGate::judge(
    '没进入ai全局规则内？以后全局修改，必然是ai的mcp和宿主的全局一起改',
    '没进入ai全局规则内？以后全局修改，必然是ai的mcp和宿主的全局一起改',
    'good positive',
    'bad negative',
);
check($eqTitle['ok'] === false && $eqTitle['reason'] === 'reusable_rule_equals_title', 'raw chat equals title is rejected');

$wrapper = LearningReusableRuleGate::judge(
    'Verify this user-reported technical correction against code or runtime evidence before acting: foo',
    'Some title',
    'pos',
    'neg',
);
check($wrapper['ok'] === false && $wrapper['reason'] === 'raw_user_claim_wrapper', 'user-reported wrapper is rejected');

$question = LearningReusableRuleGate::judge(
    'w-frame / w-skel 这是任何元素都可以用对么？',
    'Theme utility classes question',
    'pos',
    'neg',
);
check($question['ok'] === false && $question['reason'] === 'question_form_not_abstract_rule', 'question-form rule is rejected');

$eqExample = LearningReusableRuleGate::judge(
    'Do not inject Theme login layouts for foreign modules.',
    'Theme mechanism boundary',
    'Do not inject Theme login layouts for foreign modules.',
    'other',
);
check($eqExample['ok'] === false && $eqExample['reason'] === 'reusable_rule_equals_positive_example', 'rule equals positive example is rejected');

$widgetCss = LearningReusableRuleGate::judge(
    'Set .w-store-music-player to display:flex; gap:8px in widgets/store-music.css.',
    'Store music flex gap',
    'store-music widget used gap:8px',
    'store-music used raw px without tokens',
);
check($widgetCss['ok'] === false && $widgetCss['reason'] === 'special_case_prescription_as_rule', 'single-widget CSS prescription is rejected');

$widgetPath = LearningReusableRuleGate::judge(
    'Always load view/statics/css/widgets/customer-service.css before the footer.',
    'Customer service CSS order',
    'customer-service css was missing',
    'footer loaded first',
);
check($widgetPath['ok'] === false && $widgetPath['reason'] === 'special_case_prescription_as_rule', 'single widget css path prescription is rejected');

$abstractCss = LearningReusableRuleGate::judge(
    'All storefront widgets MUST consume theme CSS tokens for spacing and color; forbid promoting one widget private stylesheet into a global hard rule.',
    'Widget styles via theme tokens',
    'store-music once used private gap:8px in widgets/store-music.css',
    'Copied store-music gap into a hard rule for every widget',
);
check($abstractCss['ok'] === true, 'abstract widget CSS mechanism with special-case examples passes');

exit($failed ? 1 : 0);
