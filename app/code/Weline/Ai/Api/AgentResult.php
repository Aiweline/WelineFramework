<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/** Data-only result returned by module-provided agents. */
class AgentResult
{
    /**
     * @param list<array<string,mixed>> $toolCalls
     * @param list<array<string,mixed>> $messages
     */
    public function __construct(
        public string $content = '',
        public array $toolCalls = [],
        public int $iterations = 0,
        public array $messages = [],
        public bool $success = true,
        public ?string $error = null,
        public string $agentCode = '',
        public string $modelCode = '',
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'tool_calls' => $this->toolCalls,
            'iterations' => $this->iterations,
            'success' => $this->success,
            'error' => $this->error,
            'agent_code' => $this->agentCode,
            'model_code' => $this->modelCode,
        ];
    }

    public static function failure(string $error, string $agentCode = ''): self
    {
        return new self(
            content: '',
            success: false,
            error: $error,
            agentCode: $agentCode,
        );
    }
}
