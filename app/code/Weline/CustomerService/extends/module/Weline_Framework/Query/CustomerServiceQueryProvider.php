<?php
declare(strict_types=1);

namespace Weline\CustomerService\Extends\Module\Weline_Framework\Query;

use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\CustomerService\Service\BindCaptchaGuard;
use Weline\CustomerService\Service\ChatAttachmentCodec;
use Weline\CustomerService\Service\ChatMediaUploader;
use Weline\CustomerService\Service\ChatService;
use Weline\CustomerService\Service\CustomerServiceSettings;
use Weline\CustomerService\Service\EmailBindingService;
use Weline\CustomerService\Service\WidgetTranslationService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

class CustomerServiceQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly EmailBindingService $emailBindingService,
        private readonly BindCaptchaGuard $bindCaptchaGuard,
        private readonly Request $request,
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
            'upload' => $this->upload($params),
            'messages' => $this->messages($params),
            'setLanguage' => $this->setLanguage($params),
            'serviceStatus' => $this->serviceStatus(),
            'sendVerification' => $this->sendVerification($params),
            'widgetTranslations' => $this->widgetTranslations($params),
            'adminRequest' => $this->adminRequest($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported customer service provider operation: %{1}', $operation)
            ),
        };
    }

    /**
     * @param array<string,mixed> $params
     */
    private function adminRequest(array $params): mixed
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'POST'))) ?: 'POST';
        $headers = is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $body = array_key_exists('body', $params) && $params['body'] !== null ? (string)$params['body'] : '';
        if ($url === '') {
            return ['success' => false, 'message' => (string)__('Missing URL')];
        }
        $parts = parse_url($url);
        $path = strtolower((string)($parts['path'] ?? ''));
        foreach (['/customerservice/backend/', '/customer-service/backend/'] as $marker) {
            $pos = strpos($path, $marker);
            if ($pos !== false) {
                $path = substr($path, $pos);
                break;
            }
        }
        if (!preg_match('#^/(?:customerservice|customer-service)/backend/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$#', $path, $m)) {
            return ['success' => false, 'message' => (string)__('Unsupported customer service admin path')];
        }
        // kebab/snake → Studly：先变空格再 ucwords，最后去掉空格（勿只 strip -/_, 否则留下 "Agent Statistics"）
        $controllerSeg = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $m[1])));
        $actionRaw = (string)($m[2] ?? 'index');
        $actionSeg = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $actionRaw)));
        $actionSegLc = lcfirst($actionSeg !== '' ? $actionSeg : 'index');
        $class = 'Weline\\CustomerService\\Controller\\Backend\\' . $controllerSeg;
        if (!class_exists($class)) {
            return ['success' => false, 'message' => (string)__('Controller missing: %{1}', $controllerSeg)];
        }
        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }
        $bodyParams = [];
        if ($body !== '') {
            $ct = '';
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'content-type') { $ct = strtolower((string)$value); break; }
            }
            if (str_contains($ct, 'application/json') || str_starts_with(ltrim($body), '{')) {
                $decoded = json_decode($body, true);
                $bodyParams = is_array($decoded) ? $decoded : [];
            } else {
                parse_str($body, $bodyParams);
                if (!is_array($bodyParams)) { $bodyParams = []; }
            }
        }
        $candidates = [$actionSegLc, 'get' . $actionSeg, 'post' . $actionSeg];
        if ($method === 'GET') {
            array_unshift($candidates, 'get' . $actionSeg);
        } else {
            array_unshift($candidates, 'post' . $actionSeg);
        }

        return \Weline\Framework\Service\Query\AdminControllerBridge::invoke(
            $class,
            $candidates,
            $queryParams,
            $bodyParams,
            $method,
            $body
        );
    }


    private function session(array $params): array
    {
        $frontendSession = $this->sessionFactory->createFrontendSession();
        $customerId = $frontendSession->isLoggedIn() ? (int)($frontendSession->getUserId() ?? 0) : null;
        $locale = trim((string)($params['locale'] ?? ''));
        $session = $this->chatService->getOrCreateSession(
            $customerId,
            trim((string)($params['session_token'] ?? '')),
            $locale
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
                'guest_send' => $this->chatService->resolveGuestSendGate(
                    (int)$session->getId(),
                    $customerId !== null && $customerId > 0
                ),
                'identity' => $this->chatService->buildSessionIdentity(
                    $session,
                    $customerId !== null && $customerId > 0,
                    $customerId
                ),
            ],
        ];
    }

    private function sendMessage(array $params): array
    {
        $sessionId = (int)($params['session_id'] ?? 0);
        $content = trim((string)($params['content'] ?? ''));
        $attachmentType = trim((string)($params['attachment_type'] ?? ''));
        $attachmentUrl = trim((string)($params['attachment_url'] ?? ''));
        if ($attachmentType !== '' && $attachmentUrl !== '') {
            try {
                $content = ChatAttachmentCodec::encode([
                    'type' => $attachmentType,
                    'url' => $attachmentUrl,
                    'name' => (string)($params['attachment_name'] ?? ''),
                    'size' => (int)($params['attachment_size'] ?? 0),
                    'mime' => (string)($params['attachment_mime'] ?? ''),
                ]);
            } catch (\InvalidArgumentException $e) {
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }
        if ($sessionId <= 0 || $content === '') {
            return [
                'success' => false,
                'message' => (string)__('Session and message content are required.'),
            ];
        }

        $frontendSession = $this->sessionFactory->createFrontendSession();
        $customerId = $frontendSession->isLoggedIn() ? (int)($frontendSession->getUserId() ?? 0) : 0;
        try {
            $this->chatService->assertCustomerMaySend($sessionId, $customerId > 0);
        } catch (\RuntimeException $e) {
            $gate = $this->chatService->resolveGuestSendGate($sessionId, $customerId > 0);
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'guest_send' => $gate,
                'guest_send_locked' => true,
            ];
        }

        $message = $this->chatService->sendMessage(
            $sessionId,
            ChatMessage::SENDER_TYPE_CUSTOMER,
            $customerId ?: $sessionId,
            $content
        );
        $viewerLocale = $this->resolveViewerLocale($params, $sessionId);
        $messageData = $this->chatService->formatMessageForCustomerView($message, $viewerLocale);
        $gate = $this->chatService->resolveGuestSendGate($sessionId, $customerId > 0);

        return [
            'success' => true,
            'data' => $messageData,
            'guest_send' => $gate,
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function upload(array $params): array
    {
        $sessionId = (int)($params['session_id'] ?? 0);
        if ($sessionId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Session ID is required.'),
            ];
        }
        $frontendSession = $this->sessionFactory->createFrontendSession();
        $customerId = $frontendSession->isLoggedIn() ? (int)($frontendSession->getUserId() ?? 0) : 0;
        try {
            $this->chatService->assertCustomerMaySend($sessionId, $customerId > 0);
        } catch (\RuntimeException $e) {
            $gate = $this->chatService->resolveGuestSendGate($sessionId, $customerId > 0);
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'guest_send' => $gate,
                'guest_send_locked' => true,
            ];
        }

        $owner = $customerId > 0 ? 'c_' . $customerId : 's_' . $sessionId;
        try {
            /** @var ChatMediaUploader $uploader */
            $uploader = ObjectManager::getInstance(ChatMediaUploader::class);
            $stored = $uploader->storeBase64(
                (string)($params['name'] ?? 'file'),
                (string)($params['mime'] ?? 'application/octet-stream'),
                (string)($params['data'] ?? ''),
                $owner
            );
            return [
                'success' => true,
                'message' => (string)__('上传成功'),
                'data' => $stored,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__('上传失败：%{1}', $e->getMessage()),
            ];
        }
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

        $frontendSession = $this->sessionFactory->createFrontendSession();
        $isLoggedIn = $frontendSession->isLoggedIn();

        return [
            'success' => true,
            'data' => $this->chatService->getMessagesForCustomerView(
                $sessionId,
                $this->resolveViewerLocale($params, $sessionId),
                min(100, max(1, (int)($params['limit'] ?? 50))),
                max(0, (int)($params['offset'] ?? 0))
            ),
            'guest_send' => $this->chatService->resolveGuestSendGate($sessionId, $isLoggedIn),
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
                /** @var CustomerServiceSettings $settings */
                $settings = ObjectManager::getInstance(CustomerServiceSettings::class);
                $aiEnabled = $settings->isAiEnabled();
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
        if ($email === '' || !$this->emailBindingService->isValidEmail($email)) {
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

        if (!$this->bindCaptchaGuard->verify($params, $this->request)) {
            $degrade = $this->bindCaptchaGuard->allowsLocalDegrade() ? 'local_image' : '';
            $provider = \strtolower(\trim((string)($params['captcha_provider'] ?? '')));
            // Empty provider (stale/SSR slot) or remote provider failure → offer local degrade.
            $shouldDegrade = $this->bindCaptchaGuard->shouldOfferLocalDegrade($provider);
            $detail = \trim($this->bindCaptchaGuard->lastFailureDetail());
            $payload = [
                'success' => false,
                'message' => (string)(
                    $shouldDegrade
                        ? __('人机验证服务暂不可用，已切换为本地图码，请填写后重试')
                        : (
                            \str_contains(\strtolower($detail), 'browser_error')
                                ? __('人机验证未完成，请再试一次')
                                : __('人机验证失败或已过期，请重试')
                        )
                ),
                'captcha_error' => true,
                'captcha_degrade' => $shouldDegrade ? $degrade : null,
            ];
            // Dev: keep provider failure reason for tooling; never append to user-facing message.
            if (\defined('DEV') && \DEV && $detail !== '') {
                $payload['captcha_detail'] = $detail;
            }

            return $payload;
        }

        if (!$this->emailBindingService->sendVerificationEmail($email, $sessionToken)) {
            $detail = trim($this->emailBindingService->getLastErrorMessage());
            $verificationUrl = trim($this->emailBindingService->getLastVerificationUrl());
            $payload = [
                'success' => false,
                'message' => $detail !== ''
                    ? $detail
                    : (string)__('Unable to send verification email. Please try again later.'),
            ];
            if ($verificationUrl !== '') {
                $payload['verification_url'] = $verificationUrl;
            }

            return $payload;
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

        return $session->getId()
            ? $session->getCustomerLocale()
            : ObjectManager::getInstance(CustomerServiceSettings::class)->defaultCustomerLocale();
    }

    /**
     * Batch widget chrome dictionaries — loaded on first chat open (not SSR body-end).
     *
     * @param array<string,mixed> $params
     * @return array{success:bool,data:array{translations:array<string,array<string,string>>}}
     */
    private function widgetTranslations(array $params): array
    {
        /** @var WidgetTranslationService $service */
        $service = ObjectManager::getInstance(WidgetTranslationService::class);
        $allowed = [];
        foreach ($service->getSupportedLocales() as $localeConfig) {
            $code = trim((string)($localeConfig['code'] ?? ''));
            if ($code !== '') {
                $allowed[$code] = true;
            }
        }

        $raw = trim((string)($params['locales'] ?? ''));
        $codes = [];
        if ($raw !== '') {
            foreach (preg_split('/[\s,]+/', $raw) ?: [] as $piece) {
                $code = trim(str_replace('-', '_', (string)$piece));
                if ($code !== '' && isset($allowed[$code])) {
                    $codes[$code] = true;
                }
            }
        }
        // Fail-closed empty: storefront loads one locale at a time via locales=…
        // (never dump the full supported matrix into a single query-bin map).
        if ($codes === []) {
            return [
                'success' => true,
                'data' => [
                    'translations' => [],
                ],
            ];
        }

        return [
            'success' => true,
            'data' => [
                'translations' => $service->getWidgetTranslationsForLocales(array_keys($codes)),
            ],
        ];
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
                        'content' => ['type' => 'string', 'required' => false, 'max_length' => 4000],
                        'attachment_type' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'attachment_url' => ['type' => 'string', 'required' => false, 'max_length' => 1024],
                        'attachment_name' => ['type' => 'string', 'required' => false, 'max_length' => 255],
                        'attachment_size' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'attachment_mime' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'locale' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Send customer chat message',
                ],
                [
                    'name' => 'upload',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 6,
                    'params' => [
                        'session_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'name' => ['type' => 'string', 'required' => false, 'max_length' => 255],
                        'mime' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'data' => ['type' => 'string', 'required' => true, 'max_length' => 16000000],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Upload customer chat attachment (base64)',
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
                    'name' => 'widgetTranslations',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 300,
                    'params' => [
                        'locales' => ['type' => 'string', 'required' => false, 'max_length' => 512],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Batch customer-service widget chrome dictionaries',
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
                        'captcha_provider' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                        'captcha_token' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'captcha_response' => ['type' => 'string', 'required' => false, 'max_length' => 8192],
                        'captcha_action' => ['type' => 'string', 'required' => false, 'max_length' => 100],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Send guest chat bind-email verification',
                ],
                [
                    'name' => 'adminRequest',
                    'description' => 'Backend customer-service controller bridge via bin-query',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true],
                        ['name' => 'method', 'type' => 'string', 'required' => false],
                        ['name' => 'headers', 'type' => 'array', 'required' => false],
                        ['name' => 'body', 'type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Proxy backend customer-service admin controllers',
                ],
            ],
        ];
    }
}
