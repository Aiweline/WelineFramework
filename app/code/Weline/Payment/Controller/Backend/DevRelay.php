<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\LastEventIdResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Backend\Model\BackendUser;
use Weline\Payment\Model\PaymentDevRelaySession;
use Weline\Payment\Service\DevRelayCommandService;
use Weline\Payment\Service\DevRelayEventStore;
use Weline\Payment\Service\DevRelayGateService;
use Weline\Payment\Service\DevRelayLocalConnectService;
use Weline\Payment\Service\DevRelayPairService;
use Weline\Payment\Service\DevRelaySessionService;
use Weline\Payment\Service\DevRelaySettingsService;
use Weline\Payment\Service\DevRelayStreamService;

#[Acl('Weline_Payment::payment_diagnostics', '支付诊断工作台', 'circle', '支付只读诊断', 'Weline_Backend::payment_group')]
final class DevRelay extends BackendController
{
    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelaySettingsService $settings,
        private readonly DevRelaySessionService $sessions,
        private readonly DevRelayStreamService $stream,
        private readonly DevRelayEventStore $events,
        private readonly DevRelayCommandService $commands,
        private readonly DevRelayPairService $pair,
        private readonly DevRelayLocalConnectService $localWorker,
    ) {
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function index(): string
    {
        // 未启用时仍可打开控制台，用开关统一配置；中继动作仍受 canOpenUi()/isAllowed() 约束。
        $config = $this->gate->config();
        $isLocal = $this->gate->isLocalEnvironment();
        $featureOn = $this->gate->canOpenUi();
        $activeOnline = ($featureOn && !$isLocal) ? $this->sessions->findActiveOnlineSession() : null;
        $activeLocal = ($featureOn && $isLocal) ? $this->sessions->findActiveLocalSession() : null;
        $websiteBaseUrl = rtrim(trim((string) $this->request->getBaseHost()), '/');
        if ($websiteBaseUrl === '') {
            $websiteBaseUrl = (string) ($config['online_base_url'] ?? '');
        }
        $userApiToken = '';
        try {
            $userId = (int) ($this->getLoginUserId() ?? 0);
            if ($userId > 0) {
                /** @var BackendUser $user */
                $user = ObjectManager::getInstance(BackendUser::class);
                $user->load($userId);
                if ($user->getId()) {
                    $userApiToken = $this->pair->ensureUserApiToken($user);
                }
            }
        } catch (\Throwable) {
            $userApiToken = '';
        }

        return $this->fetch('Weline_Payment::templates/Backend/DevRelay/index.phtml', [
            'is_local' => $isLocal,
            'is_online_host' => !$isLocal,
            'feature_enabled' => $featureOn,
            'config' => $config,
            'active_online' => $activeOnline ? $this->sessionView($activeOnline) : null,
            'active_local' => $activeLocal ? $this->sessionView($activeLocal) : null,
            'website_base_url' => $websiteBaseUrl,
            'user_api_token' => $userApiToken,
            'worker_status' => $isLocal ? $this->localWorker->status() : null,
        ]);
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postSaveSettings(): string
    {
        try {
            $body = $this->jsonBody();
            $enabled = !empty($body['enabled'] ?? $this->request->getPost('enabled', false));
            $allowOnProduction = !empty($body['allow_on_production'] ?? $this->request->getPost('allow_on_production', false));
            $outboundMode = trim((string) ($body['outbound_mode'] ?? $this->request->getPost('outbound_mode', '')));
            $onlineBaseUrl = trim((string) ($body['online_base_url'] ?? $this->request->getPost('online_base_url', '')));
            $ttl = (int) ($body['session_ttl_seconds'] ?? $this->request->getPost('session_ttl_seconds', 28800));

            $saved = $this->settings->save([
                'enabled' => $enabled,
                'allow_on_production' => $allowOnProduction,
                'outbound_mode' => $outboundMode,
                'online_base_url' => $onlineBaseUrl,
                'session_ttl_seconds' => $ttl,
            ]);

            return $this->json([
                'success' => true,
                'config' => $this->gate->config(),
                'saved' => $saved,
                'feature_enabled' => $this->gate->canOpenUi(),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postStart(): string
    {
        try {
            if (!$this->gate->isOnlineRelayHost()) {
                throw new \RuntimeException((string) __('仅线上/staging 主机可创建 Relay 会话。'));
            }

            $localInboundUrl = trim((string) $this->request->getPost('local_inbound_url', ''));
            $outboundMode = trim((string) $this->request->getPost('outbound_mode', PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT));
            $holder = $this->currentHolder();

            $payload = $this->sessions->createOnlineSession(
                $holder['user_id'],
                $holder['label'],
                $localInboundUrl,
                $outboundMode,
            );
            if ($localInboundUrl !== '') {
                $payload = $this->sessions->updateOnlineLocalInboundUrl(
                    (string) $payload['session_code'],
                    (string) $payload['token'],
                    $localInboundUrl,
                );
            }

            return $this->json(['success' => true, 'session' => $payload]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postBind(): string
    {
        try {
            $sessionCode = trim((string) $this->request->getPost('session_code', ''));
            $token = trim((string) $this->request->getPost('token', ''));
            $onlineStreamUrl = trim((string) $this->request->getPost('online_stream_url', ''));
            if ($onlineStreamUrl === '') {
                $onlineStreamUrl = trim((string) $this->request->getPost('online_console_url', ''));
            }

            $payload = $this->sessions->bindLocalSession($sessionCode, $token, $onlineStreamUrl);

            return $this->json(['success' => true, 'session' => $payload]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postUpdateInbound(): string
    {
        try {
            $sessionCode = trim((string) $this->request->getPost('session_code', ''));
            $token = trim((string) $this->request->getPost('token', ''));
            $localInboundUrl = trim((string) $this->request->getPost('local_inbound_url', ''));
            $payload = $this->sessions->updateOnlineLocalInboundUrl($sessionCode, $token, $localInboundUrl);

            return $this->json(['success' => true, 'session' => $payload]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postClose(): string
    {
        try {
            $sessionCode = trim((string) $this->request->getPost('session_code', ''));
            $token = trim((string) $this->request->getPost('token', ''));
            $this->sessions->closeSession($sessionCode, $token);

            return $this->json(['success' => true]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postAck(): string
    {
        try {
            $sessionCode = trim((string) $this->request->getPost('session_code', ''));
            $token = trim((string) $this->request->getPost('token', ''));
            $eventCode = trim((string) $this->request->getPost('event_code', ''));
            $success = !empty($this->request->getPost('success', false));
            $error = trim((string) $this->request->getPost('error', ''));

            $this->sessions->requireActiveSession($sessionCode, $token);
            $this->events->markRelayResult($eventCode, $success, $error);

            return $this->json(['success' => true]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postCommand(): string
    {
        try {
            $sessionCode = trim((string) $this->request->getPost('session_code', ''));
            $token = trim((string) $this->request->getPost('token', ''));
            $action = trim((string) $this->request->getPost('action', ''));
            $payload = $this->request->getPost('payload', []);
            if (!\is_array($payload)) {
                $payload = [];
            }

            $result = $this->commands->execute($sessionCode, $token, $action, $payload);

            return $this->json(['success' => true, 'result' => $result]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function getEvent(): string
    {
        try {
            $sessionCode = trim((string) $this->request->getParam('session_code', ''));
            $token = trim((string) $this->request->getParam('token', ''));
            $eventCode = trim((string) $this->request->getParam('event_code', ''));
            $payload = $this->stream->fetchEventPayload($eventCode, $sessionCode, $token);

            return $this->json(['success' => true, 'payload' => $payload]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function getStream(): void
    {
        $this->layoutType = null;
        $sessionCode = trim((string) $this->request->getParam('session_code', ''));
        $token = trim((string) $this->request->getParam('token', ''));
        $lastEventId = LastEventIdResolver::resolve($this->request);
        $this->stream->stream($sessionCode, $token, $lastEventId);
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function getConsole(): string
    {
        if (!$this->gate->canOpenUi()) {
            return (string) __('Dev Webhook Relay 未启用。');
        }

        $sessionCode = trim((string) $this->request->getParam('session_code', ''));
        $token = trim((string) $this->request->getParam('token', ''));
        $mode = trim((string) $this->request->getParam('mode', 'online'));
        $localInboundUrl = trim((string) $this->request->getParam('local_inbound_url', ''));
        $streamUrl = trim((string) $this->request->getParam('stream_url', ''));
        if ($streamUrl === '') {
            $streamUrl = $this->sessions->buildStreamUrl($sessionCode, $token);
        }
        $ackUrl = $this->getBackendUrl('payment/backend/dev-relay/postAck');
        $commandUrl = $this->getBackendUrl('payment/backend/dev-relay/postCommand');

        return $this->fetch('Weline_Payment::templates/Backend/DevRelay/console.phtml', [
            'session_code' => $sessionCode,
            'token' => $token,
            'stream_url' => $streamUrl,
            'ack_url' => $ackUrl,
            'command_url' => $commandUrl,
            'mode' => $mode,
            'local_inbound_url' => $localInboundUrl,
            'outbound_mode' => (string) $this->gate->config()['outbound_mode'],
        ]);
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postWorkerStart(): string
    {
        try {
            if (!$this->gate->isLocalEnvironment()) {
                throw new \RuntimeException((string) __('仅本地环境可启动静默 DevRelay worker。'));
            }

            $body = $this->jsonBody();
            $onlineBaseUrl = trim((string) ($body['online_base_url'] ?? $this->request->getPost('online_base_url', '')));
            $userToken = trim((string) ($body['user_token'] ?? $this->request->getPost('user_token', '')));
            $remembered = $this->localWorker->readRememberedCredentials();
            if ($onlineBaseUrl === '') {
                $onlineBaseUrl = (string) ($this->gate->config()['online_base_url'] ?? '');
            }
            if ($onlineBaseUrl === '' && \is_array($remembered)) {
                $onlineBaseUrl = (string) ($remembered['online_base_url'] ?? '');
            }
            if ($userToken === '' && \is_array($remembered)) {
                $userToken = (string) ($remembered['user_token'] ?? '');
            }

            // 先启用再 start，避免 canOpenUi chicken-egg；开启本身即打开中继开关。
            $this->settings->save(array_merge($this->settings->get(), [
                'enabled' => true,
                'online_base_url' => rtrim($onlineBaseUrl, '/'),
            ]));

            $status = $this->localWorker->start($onlineBaseUrl, $userToken);

            return $this->json([
                'success' => true,
                'status' => $status,
                'feature_enabled' => $this->gate->canOpenUi(),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function postWorkerStop(): string
    {
        try {
            $status = $this->localWorker->stop();

            return $this->json(['success' => true, 'status' => $status]);
        } catch (\Throwable $throwable) {
            return $this->json(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    #[Acl('Weline_Payment::payment_dev_relay', '开发 Webhook 转发', 'webhook', 'Payment Dev Webhook Relay')]
    public function getWorkerStatus(): string
    {
        return $this->json(['success' => true, 'status' => $this->localWorker->status()]);
    }

    /**
     * @return array{user_id:int,label:string}
     */
    private function currentHolder(): array
    {
        $userId = (int) ($this->getLoginUserId() ?? 0);
        $label = trim((string) ($this->getLoginUsername() ?? 'backend'));

        return [
            'user_id' => $userId,
            'label' => $label !== '' ? $label : 'backend',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionView(PaymentDevRelaySession $session): array
    {
        return [
            'session_code' => (string) $session->getData(PaymentDevRelaySession::schema_fields_SESSION_CODE),
            'role' => (string) $session->getData(PaymentDevRelaySession::schema_fields_ROLE),
            'status' => (string) $session->getData(PaymentDevRelaySession::schema_fields_STATUS),
            'local_inbound_url' => (string) $session->getData(PaymentDevRelaySession::schema_fields_LOCAL_INBOUND_URL),
            'online_stream_url' => (string) $session->getData(PaymentDevRelaySession::schema_fields_ONLINE_STREAM_URL),
            'outbound_mode' => (string) $session->getData(PaymentDevRelaySession::schema_fields_OUTBOUND_MODE),
            'expires_at' => (string) $session->getData(PaymentDevRelaySession::schema_fields_EXPIRES_AT),
            'last_event_seq' => (int) $session->getData(PaymentDevRelaySession::schema_fields_LAST_EVENT_SEQ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): string
    {
        $this->request->getResponse()->setHttpResponseCode($status);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
