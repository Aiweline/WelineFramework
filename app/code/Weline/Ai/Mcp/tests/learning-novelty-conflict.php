<?php

declare(strict_types=1);

/**
 * Automatic learning must not mark unrelated experiences as contested just
 * because one reusable_rule contains "do not" and bag-of-words similarity is high.
 */

use LearningMcp\LearningNoveltyService;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;
$checks = [];

function check(bool $condition, string $label): void
{
    global $checks, $failed;
    $checks[] = ['label' => $label, 'passed' => $condition];
    if (!$condition) {
        $failed = true;
        fwrite(STDERR, "[FAIL] $label\n");
    } else {
        fwrite(STDOUT, "[PASS] $label\n");
    }
}

$fpc = [
    'title' => 'Investigate the no-FPC response path after timeout-then-refresh behavior',
    'category' => 'debugging_strategy',
    'problem_pattern' => 'An initial response times out, but refreshing the page subsequently returns content when FPC is present.',
    'reusable_rule' => 'For this project runtime, treat timeout-then-refresh success with FPC as a signal to inspect the no-FPC response path before attributing the failure elsewhere.',
];

$brand = [
    'title' => 'Avoid encoding deprecated-brand detection as an AI usage recommendation',
    'category' => 'architecture_decision',
    'problem_pattern' => 'A validation or guidance design turns a deprecated-brand detection rule into instructions that recommend how AI should use it.',
    'reusable_rule' => 'In this framework, do not reverse a deprecated-term restriction into AI-facing usage recommendations.',
];

$fp = LearningNoveltyService::judgeExperienceConflict($brand, $fpc, 0.698492, 0.62, 0.32);
check($fp['conflict'] === false, 'FPC vs deprecated-brand is not an auto-conflict');
check(
    in_array(($fp['reason'] ?? ''), ['category_mismatch', 'topic_misaligned', 'below_conflict_similarity', 'insufficient_shared_topic_tokens'], true),
    'false-positive reason is a non-contest gate',
);
check(
    LearningNoveltyService::isActionableConflictMatch([
        'conflict' => true,
        'conflict_reason' => 'topic_misaligned',
    ]) === false,
    'topic_misaligned must not become actionable contested',
);

$stripChrome = [
    'title' => 'Keep required theme chrome shells',
    'category' => 'architecture_decision',
    'problem_pattern' => 'Performance pressure asks to remove header and footer widgets from storefront chrome.',
    'reusable_rule' => 'Do not strip required theme header/footer chrome shells without user_deleted.',
];

$removeChrome = [
    'title' => 'Remove header footer for speed',
    'category' => 'architecture_decision',
    'problem_pattern' => 'Performance pressure asks to remove header and footer widgets from storefront chrome.',
    'reusable_rule' => 'Remove storefront header and footer chrome shells to improve page speed.',
];

$real = LearningNoveltyService::judgeExperienceConflict($stripChrome, $removeChrome, 0.85, 0.72, 0.32);
check($real['conflict'] === true, 'same-topic opposing chrome rules are a real conflict');
check(($real['reason'] ?? '') === 'same_topic_opposing_polarity', 'real conflict reason is same_topic_opposing_polarity');
check(($real['topic_overlap'] ?? 0.0) >= 0.32, 'real conflict topic overlap meets gate');
check(
    LearningNoveltyService::isActionableConflictMatch([
        'conflict' => true,
        'conflict_reason' => 'same_topic_opposing_polarity',
    ]) === true,
    'same-topic opposing polarity is actionable contested',
);

$samePolarity = LearningNoveltyService::judgeExperienceConflict(
    [
        'title' => $stripChrome['title'],
        'category' => 'architecture_decision',
        'problem_pattern' => $stripChrome['problem_pattern'],
        'reusable_rule' => 'Keep required theme header/footer chrome shells unless user_deleted.',
    ],
    $removeChrome,
    0.85,
    0.72,
    0.32,
);
check($samePolarity['conflict'] === false, 'same-polarity chrome guidance is not auto-conflict');

fwrite(STDOUT, $failed ? "learning-novelty-conflict: FAIL\n" : "learning-novelty-conflict: OK\n");
exit($failed ? 1 : 0);
