<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Sse\LastEventIdResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Webhook\WebhookEndpointRecord;
use Weline\Payment\Model\PaymentWebhookEndpoint;
use Weline\Payment\Service\DevRelayEventStore;
use Weline\Payment\Service\DevRelayGateService;
use Weline\Payment\Service\DevRelayInboundService;
use Weline\Payment\Service\DevRelayLocalConnectService;
use Weline\Payment\Service\DevRelayPairService;
use Weline\Payment\Service\DevRelayProbeService;
use Weline\Payment\Service\DevRelaySessionService;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Service\DevRelaySettingsService;
use Weline\Payment\Service\DevRelayStreamService;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Payment\Service\WebhookEndpointDirectory;

/**
 * 公网可达 Dev Relay：pair（Bearer 用户 Token）+ stream/event/ack（relay token）+ inbound。
 */
final class DevRelay extends FrontendController
{
    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelaySessionService $sessions,
        private readonly DevRelayInboundService $inbound,
        private readonly DevRelayPairService $pair,
        private readonly DevRelayProbeService $probe,
        private readonly DevRelayStreamService $stream,
        private readonly DevRelayEventStore $events,
        private readonly DevRelaySettingsService $settings,
        private readonly DevRelayLocalConnectService $localWorker,
    ) {
    }

    public function pair(): string
    {
        return $this->json(function (): array {
            if (!$this->request->isPost()) {
                throw new \RuntimeException('method_not_allowed');
            }
            $body = $this->jsonBody();
            $localInboundUrl = trim((string) ($body['local_inbound_url'] ?? $this->request->getPost('local_inbound_url', '')));
            $outboundMode = trim((string) ($body['outbound_mode'] ?? $this->request->getPost('outbound_mode', '')));
            $session = $this->pair->pairFromRequest($this->request, $localInboundUrl, $outboundMode);

            return ['success' => true, 'session' => $session];
        });
    }

    public function probe(): string
    {
        return $this->json(function (): array {
            if (!$this->request->isPost()) {
                throw new \RuntimeException('method_not_allowed');
            }
            $this->pair->requireUserFromBearer($this->request);
            $body = $this->jsonBody();
            $marker = trim((string) ($body['marker'] ?? $this->request->getPost('marker', '')));
            $data = $this->probe->injectSyntheticEvent($marker);

            return ['success' => true, 'data' => $data];
        });
    }

    public function close(): string
    {
        return $this->json(function (): array {
            if (!$this->request->isPost()) {
                throw new \RuntimeException('method_not_allowed');
            }
            $body = $this->jsonBody();
            $sessionCode = trim((string) ($body['session_code'] ?? $this->request->getPost('session_code', '')));
            $token = trim((string) ($body['token'] ?? $this->request->getPost('token', '')));
            $this->pair->closeFromRequest($this->request, $sessionCode, $token);

            return ['success' => true];
        });
    }

    public function updateInbound(): string
    {
        return $this->json(function (): array {
            if (!$this->request->isPost()) {
                throw new \RuntimeException('method_not_allowed');
            }
            $this->pair->requireUserFromBearer($this->request);
            $body = $this->jsonBody();
            $sessionCode = trim((string) ($body['session_code'] ?? ''));
            $token = trim((string) ($body['token'] ?? ''));
            $localInboundUrl = trim((string) ($body['local_inbound_url'] ?? ''));
            $payload = $this->sessions->updateOnlineLocalInboundUrl($sessionCode, $token, $localInboundUrl);

            return ['success' => true, 'session' => $payload];
        });
    }

    public function stream(): void
    {
        $this->layoutType = null;
        $sessionCode = trim((string) $this->request->getParam('session_code', ''));
        $token = trim((string) $this->request->getParam('token', ''));
        $lastEventId = LastEventIdResolver::resolve($this->request);
        $this->stream->stream($sessionCode, $token, $lastEventId);
    }

    public function event(): string
    {
        return $this->json(function (): array {
            $sessionCode = trim((string) $this->request->getParam('session_code', ''));
            $token = trim((string) $this->request->getParam('token', ''));
            $eventCode = trim((string) $this->request->getParam('event_code', ''));
            $payload = $this->stream->fetchEventPayload($eventCode, $sessionCode, $token);

            return ['success' => true, 'payload' => $payload];
        });
    }

    public function ack(): string
    {
        return $this->json(function (): array {
            if (!$this->request->isPost()) {
                throw new \RuntimeException('method_not_allowed');
            }
            $body = $this->jsonBody();
            $sessionCode = trim((string) ($body['session_code'] ?? $this->request->getPost('session_code', '')));
            $token = trim((string) ($body['token'] ?? $this->request->getPost('token', '')));
            $eventCode = trim((string) ($body['event_code'] ?? $this->request->getPost('event_code', '')));
            $success = !empty($body['success'] ?? $this->request->getPost('success', false));
            $error = trim((string) ($body['error'] ?? $this->request->getPost('error', '')));
            $this->sessions->requireActiveSession($sessionCode, $token);
            $this->events->markRelayResult($eventCode, $success, $error);

            return ['success' => true];
        });
    }

    public function workerStatus(): string
    {
        return $this->json(function (): array {
            if (!$this->gate->isLocalEnvironment()) {
                throw new \RuntimeException((string) __('仅本地可查询静默 worker 状态。'));
            }
            $status = $this->localWorker->status();
            unset($status['relay_token']);
            $cfg = $this->gate->config();

            return [
                'success' => true,
                'status' => $status,
                'config' => [
                    'enabled' => !empty($cfg['enabled']),
                    'online_base_url' => (string) ($cfg['online_base_url'] ?? ''),
                    'outbound_mode' => (string) ($cfg['outbound_mode'] ?? 'local_direct'),
                ],
                'examples' => $this->exampleUrls((string) ($cfg['online_base_url'] ?? 'https://www.aiweline.com')),
                'provider_webhooks' => $this->providerWebhookUrls((string) ($cfg['online_base_url'] ?? '')),
            ];
        });
    }

    /**
     * 本机面板：启动静默 worker（避免跨域打后台 ACL）。
     */
    public function workerStart(): string
    {
        return $this->json(function (): array {
            if (!$this->gate->isLocalEnvironment()) {
                throw new \RuntimeException((string) __('仅本地可启动静默 worker。'));
            }
            if (!$this->gate->canOpenUi()) {
                throw new \RuntimeException((string) __('请先在支付钩子控制台启用 DevRelay。'));
            }
            if (!$this->request->isPost()) {
                throw new \RuntimeException('method_not_allowed');
            }
            $body = $this->jsonBody();
            $online = trim((string) ($body['online_base_url'] ?? $this->request->getPost('online_base_url', '')));
            $token = trim((string) ($body['user_token'] ?? $this->request->getPost('user_token', '')));
            $remembered = $this->localWorker->readRememberedCredentials();
            if ($online === '') {
                $online = (string) ($this->gate->config()['online_base_url'] ?? '');
            }
            if ($online === '' && $remembered) {
                $online = $remembered['online_base_url'];
            }
            if ($token === '' && $remembered) {
                $token = $remembered['user_token'];
            }
            $status = $this->localWorker->start($online, $token);
            try {
                $this->settings->save(array_merge($this->settings->get(), [
                    'enabled' => true,
                    'online_base_url' => $online,
                ]));
            } catch (\Throwable) {
            }

            return ['success' => true, 'status' => $status];
        });
    }

    public function workerStop(): string
    {
        return $this->json(function (): array {
            if (!$this->gate->isLocalEnvironment()) {
                throw new \RuntimeException((string) __('仅本地可停止静默 worker。'));
            }
            if (!$this->request->isPost()) {
                throw new \RuntimeException('method_not_allowed');
            }

            return ['success' => true, 'status' => $this->localWorker->stop()];
        });
    }

    /**
     * 本机面板一键探测：自动补全 Token、保活 worker、代发线上 probe、返回本机重放状态。
     */
    public function panelProbe(): string
    {
        return $this->json(function (): array {
            if (!$this->gate->isLocalEnvironment()) {
                throw new \RuntimeException((string) __('仅本地面板可代发探针。'));
            }
            if (!$this->gate->canOpenUi()) {
                throw new \RuntimeException((string) __('请先启用 DevRelay。'));
            }
            if (!$this->request->isPost()) {
                throw new \RuntimeException('method_not_allowed');
            }
            $body = $this->jsonBody();
            $online = rtrim(trim((string) ($body['online_base_url'] ?? '')), '/');
            $token = trim((string) ($body['user_token'] ?? ''));
            $marker = trim((string) ($body['marker'] ?? ''));

            $beforeOk = (int) ($this->localWorker->status()['relayed_ok'] ?? 0);
            $status = $this->localWorker->ensureRunning($online !== '' ? $online : null, $token !== '' ? $token : null);
            $probe = $this->localWorker->probeOnline($marker, $online !== '' ? $online : null, $token !== '' ? $token : null);

            // 等本机 SSE 重放结果落到 recent_events
            $deadline = time() + 10;
            $after = $this->localWorker->status();
            while (time() < $deadline) {
                $after = $this->localWorker->status();
                if ((int) ($after['relayed_ok'] ?? 0) > $beforeOk
                    || (!empty($after['last_event_code']) && (string) $after['last_event_code'] === (string) ((($probe['data']['event'] ?? [])['event_code'] ?? '')))
                ) {
                    break;
                }
                usleep(400_000);
            }

            return [
                'success' => !empty($probe['success']),
                'message' => (string) ($probe['message'] ?? ''),
                'data' => $probe['data'] ?? null,
                'http_status' => (int) ($probe['http_status'] ?? 0),
                'marker' => (string) ($probe['marker'] ?? $marker),
                'probe_url' => (string) ($probe['probe_url'] ?? ''),
                'status_before' => $status,
                'status' => $after,
            ];
        });
    }

    /**
     * 线上浏览器连调页：粘贴 Token → 发送探针。
     */
    public function demo(): string
    {
        $this->layoutType = null;
        if (!$this->gate->canOpenUi()) {
            $this->request->getResponse()->setHttpResponseCode(403);

            return '<!doctype html><meta charset="utf-8"><title>DevRelay</title><p>DevRelay disabled</p>';
        }

        $prefillToken = trim((string) $this->request->getParam('token', ''));
        $probePath = '/payment/dev-relay/probe';

        return $this->fetch('Weline_Payment::Frontend/dev-relay/demo.phtml', [
            'prefill_token' => $prefillToken,
            'probe_path' => $probePath,
            'is_online_host' => $this->gate->isOnlineRelayHost(),
            'is_local' => $this->gate->isLocalEnvironment(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function exampleUrls(string $onlineBaseUrl): array
    {
        $base = rtrim($onlineBaseUrl !== '' ? $onlineBaseUrl : 'https://www.aiweline.com', '/');

        return [
            'demo' => $base . '/payment/dev-relay/demo',
            'probe' => $base . '/payment/dev-relay/probe',
            'pair' => $base . '/payment/dev-relay/pair',
            'curl_probe' => "curl -sS -X POST '" . $base . "/payment/dev-relay/probe' \\\n"
                . "  -H 'Authorization: Bearer YOUR_TOKEN' \\\n"
                . "  -H 'Content-Type: application/json' \\\n"
                . "  -d '{\"marker\":\"browser_test\"}'",
        ];
    }

    /**
     * 任意 Provider 应登记的线上官方回调地址，不是 /payment/dev-relay/*。
     *
     * @return array{
     *   hint:string,
     *   notify_path:string,
     *   template:string,
     *   endpoints:list<array{
     *     endpoint_code:string,
     *     provider_code:string,
     *     method_code:string,
     *     environment:string,
     *     name:string,
     *     label:string,
     *     search_text:string,
     *     url:string
     *   }>
     * }
     */
    private function providerWebhookUrls(string $onlineBaseUrl): array
    {
        $remembered = $this->localWorker->readRememberedCredentials();
        $base = rtrim(
            $onlineBaseUrl !== ''
                ? $onlineBaseUrl
                : (string) (($remembered['online_base_url'] ?? '') ?: ($this->gate->config()['online_base_url'] ?? 'https://www.aiweline.com')),
            '/',
        );
        if ($base === '') {
            $base = 'https://www.aiweline.com';
        }
        $path = '/payment/frontend/callback/notify';
        $preferred = $this->defaultProviderWebhookEndpoints();
        $this->ensureDefaultProviderWebhookEndpoints($preferred);
        $endpoints = [];
        $seen = [];

        $push = static function (array $row) use (&$endpoints, &$seen, $base, $path): void {
            $code = trim((string) ($row['endpoint_code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                return;
            }
            $seen[$code] = true;
            $provider = trim((string) ($row['provider_code'] ?? ''));
            $method = trim((string) ($row['method_code'] ?? ''));
            $environment = trim((string) ($row['environment'] ?? 'sandbox'));
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = $method !== '' ? $method : $provider;
            }
            $label = $name
                . ($provider !== '' ? (' · ' . $provider) : '')
                . ($method !== '' ? (' / ' . $method) : '')
                . ($environment !== '' ? (' · ' . $environment) : '')
                . ' · ' . $code;
            $endpoints[] = [
                'endpoint_code' => $code,
                'provider_code' => $provider,
                'method_code' => $method,
                'environment' => $environment,
                'name' => $name,
                'label' => $label,
                'search_text' => strtolower($label . ' ' . $code . ' ' . $provider . ' ' . $method . ' ' . $name),
                'url' => $base . $path . '?endpoint_code=' . rawurlencode($code),
            ];
        };

        foreach ($preferred as $row) {
            $push($row);
        }

        try {
            /** @var PaymentWebhookEndpoint $model */
            $model = ObjectManager::getInstance()->getInstance(PaymentWebhookEndpoint::class);
            $rows = $model->reset()
                ->where(PaymentWebhookEndpoint::schema_fields_STATUS, WebhookEndpointRecord::STATUS_ACTIVE)
                ->order(PaymentWebhookEndpoint::schema_fields_PROVIDER_CODE, 'ASC')
                ->order(PaymentWebhookEndpoint::schema_fields_ENDPOINT_CODE, 'ASC')
                ->limit(80)
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $push([
                    'endpoint_code' => (string) ($row[PaymentWebhookEndpoint::schema_fields_ENDPOINT_CODE] ?? ''),
                    'provider_code' => (string) ($row[PaymentWebhookEndpoint::schema_fields_PROVIDER_CODE] ?? ''),
                    'method_code' => (string) ($row[PaymentWebhookEndpoint::schema_fields_METHOD_CODE] ?? ''),
                    'environment' => (string) ($row[PaymentWebhookEndpoint::schema_fields_ENVIRONMENT] ?? ''),
                    'name' => '',
                ]);
            }
        } catch (\Throwable) {
            // 表未建或库不可用时仍返回支付方式默认端点，不阻断面板。
        }

        usort($endpoints, static function (array $left, array $right): int {
            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        $primary = $endpoints[0]['url'] ?? ($base . $path . '?endpoint_code=paypal.sandbox.default');

        return [
            'hint' => (string) __('搜索并选择支付方式后，复制线上官方回调到对应支付后台 Webhook（勿填本机 *.weline.test，勿填 /payment/dev-relay/*）'),
            'notify_path' => $path,
            'template' => $primary,
            'endpoints' => $endpoints,
        ];
    }

    /**
     * @return list<array{endpoint_code:string,provider_code:string,method_code:string,environment:string,name:string}>
     */
    private function defaultProviderWebhookEndpoints(): array
    {
        $out = [];
        try {
            /** @var PaymentMethodManager $manager */
            $manager = ObjectManager::getInstance()->getInstance(PaymentMethodManager::class);
            /** @var PaymentMethod $model */
            $model = ObjectManager::getInstance()->getInstance(PaymentMethod::class);
            $rows = $model->reset()
                ->order(PaymentMethod::schema_fields_SORT_ORDER, 'ASC')
                ->order(PaymentMethod::schema_fields_CODE, 'ASC')
                ->limit(80)
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $methodCode = trim((string) ($row[PaymentMethod::schema_fields_CODE] ?? ''));
                if ($methodCode === '') {
                    continue;
                }
                $method = $manager->getMethodByCode($methodCode);
                $meta = $method ? $manager->getProviderMetadata($method) : [];
                $providerCode = trim((string) ($meta['provider_code'] ?? $methodCode));
                $resolvedMethod = trim((string) ($meta['method_code'] ?? $methodCode));
                $name = trim((string) ($row[PaymentMethod::schema_fields_NAME] ?? $methodCode));
                foreach (['sandbox', 'live'] as $environment) {
                    $out[] = [
                        'endpoint_code' => $resolvedMethod . '.' . $environment . '.default',
                        'provider_code' => $providerCode !== '' ? $providerCode : $resolvedMethod,
                        'method_code' => $resolvedMethod,
                        'environment' => $environment,
                        'name' => $name,
                    ];
                }
            }
        } catch (\Throwable) {
        }

        if ($out === []) {
            $out[] = [
                'endpoint_code' => 'paypal.sandbox.default',
                'provider_code' => 'paypal',
                'method_code' => 'paypal',
                'environment' => 'sandbox',
                'name' => 'PayPal',
            ];
        }

        return $out;
    }

    /**
     * @param list<array{endpoint_code:string,provider_code:string,method_code:string,environment:string,name?:string}> $preferred
     */
    private function ensureDefaultProviderWebhookEndpoints(array $preferred = []): void
    {
        if ($preferred === []) {
            $preferred = $this->defaultProviderWebhookEndpoints();
        }
        try {
            /** @var WebhookEndpointDirectory $directory */
            $directory = ObjectManager::getInstance()->getInstance(WebhookEndpointDirectory::class);
            foreach ($preferred as $row) {
                $methodCode = (string) ($row['method_code'] ?? 'provider');
                $directory->registerEndpoint(
                    endpointCode: (string) $row['endpoint_code'],
                    providerCode: (string) $row['provider_code'],
                    methodCode: $methodCode,
                    merchantAccount: 'default',
                    environment: (string) ($row['environment'] ?? 'sandbox'),
                    secrets: [[
                        'secret_version' => 'v1',
                        'secret_ref' => 'bootstrap',
                        'status' => 'active',
                        'valid_from' => 0,
                        'valid_until' => PHP_INT_MAX,
                        'material' => $methodCode . '-webhook-dev-placeholder',
                    ]],
                );
            }
        } catch (\Throwable) {
            // 库不可用时仍展示完整 URL。
        }
    }

    public function inbound()
    {
        if (!$this->gate->canOpenUi()) {
            $this->request->getResponse()->setHttpResponseCode(403);
            echo 'forbidden';

            return;
        }

        if (!$this->request->isPost()) {
            $this->request->getResponse()->setHttpResponseCode(405);
            echo 'method_not_allowed';

            return;
        }

        $sessionCode = trim((string) $this->request->getParam('session_code', ''));
        $token = trim((string) $this->request->getParam('token', ''));
        $endpointCode = trim((string) $this->request->getParam('endpoint_code', ''));

        $rawBody = $this->rawBody();
        $headers = [];
        $signature = '';

        $contentType = strtolower(trim((string) $this->request->getHeader('Content-Type')));
        if (str_contains($contentType, 'application/json') && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (\is_array($decoded)) {
                $endpointCode = $endpointCode !== '' ? $endpointCode : trim((string) ($decoded['endpoint_code'] ?? ''));
                $encoded = trim((string) ($decoded['raw_body_b64'] ?? ''));
                if ($encoded !== '') {
                    $bodyDecoded = base64_decode($encoded, true);
                    $rawBody = \is_string($bodyDecoded) ? $bodyDecoded : '';
                } elseif (isset($decoded['raw_body']) && \is_string($decoded['raw_body'])) {
                    $rawBody = $decoded['raw_body'];
                }
                $headers = \is_array($decoded['headers'] ?? null) ? $decoded['headers'] : [];
                $signature = trim((string) ($decoded['signature'] ?? ''));
            }
        }

        if ($endpointCode === '') {
            $endpointCode = trim((string) $this->request->getPost('endpoint_code', ''));
        }

        if ($rawBody === '' || ($headers === [] && $signature === '')) {
            $encoded = trim((string) $this->request->getPost('raw_body_b64', ''));
            if ($encoded !== '') {
                $decodedBody = base64_decode($encoded, true);
                $rawBody = \is_string($decodedBody) ? $decodedBody : $rawBody;
            }
        }

        $postHeaders = $this->request->getPost('headers', []);
        if (\is_array($postHeaders) && $postHeaders !== []) {
            $headers = $postHeaders;
        }
        if ($headers === []) {
            foreach ($this->request->getHeaders() as $name => $value) {
                if (str_starts_with(strtolower((string) $name), 'x-dev-relay-')) {
                    continue;
                }
                if (\is_array($value)) {
                    $headers[$name] = $value[0] ?? '';
                } else {
                    $headers[$name] = $value;
                }
            }
        }

        if ($signature === '') {
            $signature = trim((string) $this->request->getPost('signature', ''));
        }
        if ($signature === '') {
            $signature = trim((string) ($headers['PAYPAL-TRANSMISSION-SIG'] ?? $headers['paypal-transmission-sig'] ?? ''));
        }

        try {
            $this->sessions->requireActiveSession($sessionCode, $token);
            $result = $this->inbound->replay($sessionCode, $token, $endpointCode, $rawBody, $headers, $signature);
            $this->request->getResponse()->setHttpResponseCode($result->httpStatus);
            echo $result->body;
        } catch (\Throwable $throwable) {
            $this->request->getResponse()->setHttpResponseCode(400);
            echo $throwable->getMessage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = $this->rawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function rawBody(): string
    {
        // WLS 下 php://input 不可用，必须走 Request 注入的 raw body。
        if (method_exists($this->request, 'getRawBody')) {
            $body = $this->request->getRawBody();
            if (\is_string($body) && $body !== '') {
                return $body;
            }
        }
        $body = file_get_contents('php://input');

        return \is_string($body) ? $body : '';
    }

    /**
     * @param callable():array<string,mixed> $producer
     */
    private function json(callable $producer): string
    {
        try {
            $data = $producer();
            $status = 200;
        } catch (\Throwable $throwable) {
            $data = ['success' => false, 'message' => $throwable->getMessage()];
            $status = 400;
        }
        $this->request->getResponse()->setHttpResponseCode($status);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
