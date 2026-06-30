<?php
declare(strict_types=1);

namespace Weline\CustomerService\Extends\Module\Weline_Framework\Query;

use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\CustomerServiceConfig;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\CustomerService\Service\ChatService;
use Weline\CustomerService\Service\EmailBindingService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

class CustomerServiceQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly EmailBindingService $emailBindingService,
        private readonly SessionFactory $sessionFactory
    ) {
    }

    public function getProviderName(): string
    {
        return 'customerService';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'session' => $this->session($params),
            'sendMessage' => $this->sendMessage($params),
            'messages' => $this->messages($params),
            'setLanguage' => $this->setLanguage($params),
            'serviceStatus' => $this->serviceStatus(),
            'sendVerification' => $this->sendVerification($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported customer service provider operation: %{1}', $operation)
            ),
        };
    }

    private function session(array $params): array
    {
        $frontendSession = $this->sessionFactory->createFrontendSession();
        $customerId = $frontendSession->isLoggedIn() ? (int)($frontendSession->getUserId() ?? 0) : null;
        $session = $this->chatService->getOrCreateSession(
            $customerId,
            trim((string)($params['session_token'] ?? '')),
            trim((string)($params['locale'] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN'
        );

        return [
            'success' => true,
            'data' => [
                'session_id' => (int)$session->getId(),
                'session_token' => $session->getSessionToken(),
                'customer_locale' => $session->getCustomerLocale(),
                'agent_locale' => $session->getAgentLocale(),
                'status' => $session->getStatus(),
                'agent_id' => $session->getAgentId(),
            ],
        ];
    }

    private function sendMessage(array $params): array
    {
        $sessionId = (int)($params['session_id'] ?? 0);
        $content = trim((string)($params['content'] ?? ''));
        if ($sessionId <= 0 || $content === '') {
            return [
                'success' => false,
                'message' => (string)__('Session and message content are required.'),
            ];
        }

        $frontendSession = $this->sessionFactory->createFrontendSession();
        $customerId = $frontendSession->isLoggedIn() ? (int)($frontendSession->getUserId() ?? 0) : 0;
        $message = $this->chatService->sendMessage(
            $sessionId,
            ChatMessage::SENDER_TYPE_CUSTOMER,
            $customerId ?: $sessionId,
            $content
        );
        $viewerLocale = $this->resolveViewerLocale($params, $sessionId);
        $messageData = $this->chatService->formatMessageForCustomerView($message, $viewerLocale);

        return [
            'success' => true,
            'data' => $messageData,
        ];
    }

    private function messages(array $params): array
    {
        $sessionId = (int)($params['session_id'] ?? 0);
        if ($sessionId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Session ID is required.'),
            ];
        }

        return [
            'success' => true,
            'data' => $this->chatService->getMessagesForCustomerView(
                $sessionId,
                $this->resolveViewerLocale($params, $sessionId),
                min(100, max(1, (int)($params['limit'] ?? 50))),
                max(0, (int)($params['offset'] ?? 0))
            ),
        ];
    }

    private function setLanguage(array $params): array
    {
        $locale = trim((string)($params['locale'] ?? ''));
        if ($locale === '') {
            return [
                'success' => false,
                'message' => (string)__('Language code is required.'),
            ];
        }

        $frontendSession = $this->sessionFactory->createFrontendSession();
        $customerId = $frontendSession->isLoggedIn() ? (int)($frontendSession->getUserId() ?? 0) : null;
        $this->chatService->setCustomerLocale(
            $locale,
            $customerId,
            trim((string)($params['session_token'] ?? '')) ?: null,
            null
        );

        return [
            'success' => true,
            'message' => (string)__('Language updated.'),
        ];
    }

    private function serviceStatus(): array
    {
        try {
            /** @var ServiceAgent $agentModel */
            $agentModel = ObjectManager::getInstance(ServiceAgent::class);
            $agents = $agentModel->reset()
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();

            $hasOnlineAgent = false;
            foreach ($agents as $agent) {
                $lastHeartbeat = $agent[ServiceAgent::schema_fields_LAST_HEARTBEAT] ?? null;
                if ($lastHeartbeat && (time() - strtotime((string)$lastHeartbeat)) < ServiceAgent::HEARTBEAT_TIMEOUT) {
                    $hasOnlineAgent = true;
                    break;
                }
            }

            $aiEnabled = false;
            try {
                /** @var CustomerServiceConfig $config */
                $config = ObjectManager::getInstance(CustomerServiceConfig::class);
                $aiEnabled = $config->getConfigValue('ai_enabled', '0') === '1';
            } catch (\Throwable) {
            }

            $status = $hasOnlineAgent ? 'online' : ($aiEnabled ? 'ai' : 'offline');

            return [
                'success' => true,
                'data' => [
                    'status' => $status,
                    'has_online_agent' => $hasOnlineAgent,
                    'ai_enabled' => $aiEnabled,
                ],
            ];
        } catch (\Throwable) {
            return [
                'success' => false,
                'data' => ['status' => 'offline'],
            ];
        }
    }

    private function sendVerification(array $params): array
    {
        $email = trim((string)($params['email'] ?? ''));
        $sessionToken = trim((string)($params['session_token'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => (string)__('Please enter a valid email address.'),
            ];
        }
        if ($sessionToken === '') {
            return [
                'success' => false,
                'message' => (string)__('Session token is required.'),
            ];
        }

        if (!$this->emailBindingService->sendVerificationEmail($email, $sessionToken)) {
            return [
                'success' => false,
                'message' => (string)__('Unable to send verification email. Please try again later.'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Verification email has been sent.'),
        ];
    }

    private function resolveViewerLocale(array $params, int $sessionId): string
    {
        $viewerLocale = trim((string)($params['locale'] ?? ''));
        if ($viewerLocale !== '') {
            return $viewerLocale;
        }

        /** @var \Weline\CustomerService\Model\ChatSession $session */
        $session = ObjectManager::getInstance(\Weline\CustomerService\Model\ChatSession::class);
        $session->load($sessionId);

        return $session->getId() ? $session->getCustomerLocale() : 'zh_Hans_CN';
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'customerService',
            'name' => __('Customer Service Query'),
            'description' => __('Provides frontend customer-service chat operations through the worker API.'),
            'module' => 'Weline_CustomerService',
            'operations' => [
                [
                    'name' => 'session',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'session_token' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'locale' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create or resume chat session',
                ],
                [
                    'name' => 'sendMessage',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'session_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'content' => ['type' => 'string', 'required' => true, 'max_length' => 4000],
                        'locale' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Send customer chat message',
                ],
                [
                    'name' => 'messages',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 0,
                    'params' => [
                        'session_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 100],
                        'offset' => ['type' => 'int', 'required' => false, 'min' => 0, 'max' => 10000],
                        'locale' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load chat messages',
                ],
                [
                    'name' => 'setLanguage',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'locale' => ['type' => 'string', 'required' => true, 'max_length' => 32],
                        'session_token' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Set customer chat language',
                ],
                [
                    'name' => 'serviceStatus',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 10,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Customer service availability',
                ],
                [
                    'name' => 'sendVerification',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'email' => ['type' => 'string', 'required' => true, 'max_length' => 190],
                        'session_token' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Send guest chat bind-email verification',
                ],
            ],
        ];
    }
}
