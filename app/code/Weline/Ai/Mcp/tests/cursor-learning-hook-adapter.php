<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use LearningMcp\CursorLearningHookAdapter;

$checks = [];

$adapted = CursorLearningHookAdapter::adapt('beforeSubmitPrompt', [
    'hook_event_name' => 'beforeSubmitPrompt',
    'conversation_id' => 'conv-1',
    'generation_id' => 'gen-1',
    'prompt' => '以后默认网站全语种翻译',
    'workspace_roots' => ['/tmp/repo'],
]);
$checks['maps beforeSubmitPrompt'] = $adapted['event'] === 'UserPromptSubmit'
    && $adapted['payload']['session_id'] === 'conv-1'
    && $adapted['payload']['cwd'] === '/tmp/repo'
    && $adapted['payload']['host'] === 'cursor'
    && ($adapted['cursor_response']['continue'] ?? false) === true;

$stop = CursorLearningHookAdapter::adapt('stop', [
    'hook_event_name' => 'stop',
    'session_id' => 'sess-2',
    'workspace_roots' => ['/tmp/repo'],
    'status' => 'completed',
]);
$checks['maps stop'] = $stop['event'] === 'Stop'
    && ($stop['payload']['outcome'] ?? '') === 'completed';

$tool = CursorLearningHookAdapter::adapt('preToolUse', [
    'hook_event_name' => 'preToolUse',
    'conversation_id' => 'conv-3',
    'workspace_roots' => ['/tmp/repo'],
    'tool_name' => 'Shell',
    'tool_input' => ['command' => 'ls'],
]);
$checks['maps preToolUse'] = $tool['event'] === 'PreToolUse'
    && ($tool['cursor_response']['permission'] ?? '') === 'allow'
    && ($tool['payload']['tool_name'] ?? '') === 'Shell';

$failed = 0;
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL $name\n");
        ++$failed;
    } else {
        fwrite(STDOUT, "PASS $name\n");
    }
}
exit($failed === 0 ? 0 : 1);
