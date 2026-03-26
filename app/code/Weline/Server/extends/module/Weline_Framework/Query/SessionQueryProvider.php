<?php

declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Server\Service\SessionStateFacade;

class SessionQueryProvider implements QueryProviderInterface
{
    private ?SessionStateFacade $sessionFacade = null;

    public function __construct(?SessionStateFacade $sessionFacade = null)
    {
        $this->sessionFacade = $sessionFacade;
    }

    public function getProviderName(): string
    {
        return 'session';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'read' => $this->sessionFacade()->read((string) ($params['session_id'] ?? '')),
            'write' => $this->sessionFacade()->write(
                (string) ($params['session_id'] ?? ''),
                \is_array($params['data'] ?? null) ? $params['data'] : [],
                (int) ($params['ttl'] ?? 3600)
            ),
            'destroy' => $this->sessionFacade()->destroy((string) ($params['session_id'] ?? '')),
            'exists' => $this->sessionFacade()->exists((string) ($params['session_id'] ?? '')),
            'touch' => $this->sessionFacade()->touch(
                (string) ($params['session_id'] ?? ''),
                (int) ($params['ttl'] ?? 3600)
            ),
            'list' => $this->sessionFacade()->list([
                'filter' => \is_array($params['filter'] ?? null) ? $params['filter'] : [],
                'limit' => (int) ($params['limit'] ?? 50),
            ]),
            'gc' => $this->sessionFacade()->gc((int) ($params['max_lifetime'] ?? 3600)),
            'persist' => $this->sessionFacade()->persist(),
            'ping' => $this->sessionFacade()->ping(),
            'stats' => $this->sessionFacade()->getStats(),
            'runtime' => $this->sessionFacade()->getRuntime(),
            default => throw new \InvalidArgumentException(
                (string) __('Session query provider unsupported operation: %{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'session',
            'name' => __('Session Query'),
            'description' => __('Unified shared session facade query entry'),
            'module' => 'Weline_Server',
            'operations' => [
                ['name' => 'read', 'description' => __('Read session data')],
                ['name' => 'write', 'description' => __('Write full session payload')],
                ['name' => 'destroy', 'description' => __('Destroy session')],
                ['name' => 'exists', 'description' => __('Check session exists')],
                ['name' => 'touch', 'description' => __('Touch session ttl')],
                ['name' => 'list', 'description' => __('List sessions')],
                ['name' => 'gc', 'description' => __('Run session gc')],
                ['name' => 'persist', 'description' => __('Persist session store')],
                ['name' => 'ping', 'description' => __('Ping session service')],
                ['name' => 'stats', 'description' => __('Get session stats')],
                ['name' => 'runtime', 'description' => __('Get session runtime')],
            ],
        ];
    }

    private function sessionFacade(): SessionStateFacade
    {
        if ($this->sessionFacade === null) {
            $this->sessionFacade = new SessionStateFacade();
        }

        return $this->sessionFacade;
    }
}
