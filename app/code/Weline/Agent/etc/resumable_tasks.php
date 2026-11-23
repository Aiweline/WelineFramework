<?php

declare(strict_types=1);

use Weline\Agent\Service\Resumable\AgentChatTurnTaskHandler;
use Weline\Agent\Service\Resumable\AgentFlowRunTaskHandler;

return [
    'agent.chat_turn' => [
        'handler' => AgentChatTurnTaskHandler::class,
        'areas' => ['frontend', 'backend'],
        'backend_acl' => 'Weline_Agent::console_index',
    ],
    'agent.flow_run' => [
        'handler' => AgentFlowRunTaskHandler::class,
        'areas' => ['frontend', 'backend'],
        'backend_acl' => 'Weline_Agent::flow',
    ],
];
