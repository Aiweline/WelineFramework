<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Model\PaymentDevRelayCommand;
use Weline\Payment\Model\PaymentDevRelaySession;

final class DevRelayCommandService
{
    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelaySessionService $sessions,
        private readonly PaymentMethodManager $methodManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(string $sessionCode, string $token, string $action, array $payload): array
    {
        $session = $this->sessions->requireActiveSession($sessionCode, $token, PaymentDevRelaySession::ROLE_ONLINE);
        if (!$this->gate->isOnlineProxyOutboundEnabled((string) $session->getData(PaymentDevRelaySession::schema_fields_OUTBOUND_MODE))) {
            throw new \RuntimeException((string) __('当前会话未启用线上代发模式。'));
        }

        $commandCode = 'drc_' . bin2hex(random_bytes(12));
        $model = ObjectManager::getInstance(PaymentDevRelayCommand::class);
        $model->setData([
            PaymentDevRelayCommand::schema_fields_COMMAND_CODE => $commandCode,
            PaymentDevRelayCommand::schema_fields_SESSION_CODE => $sessionCode,
            PaymentDevRelayCommand::schema_fields_ACTION => trim($action),
            PaymentDevRelayCommand::schema_fields_REQUEST_JSON => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            PaymentDevRelayCommand::schema_fields_STATUS => PaymentDevRelayCommand::STATUS_PENDING,
            PaymentDevRelayCommand::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        try {
            $response = match (trim($action)) {
                'provider.refund' => $this->executeProviderRefund($payload),
                default => throw new \InvalidArgumentException((string) __('不支持的 Relay 命令：%{1}', [trim($action)])),
            };
            $model->setData(PaymentDevRelayCommand::schema_fields_STATUS, PaymentDevRelayCommand::STATUS_SUCCEEDED)
                ->setData(PaymentDevRelayCommand::schema_fields_RESPONSE_JSON, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
                ->setData(PaymentDevRelayCommand::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();

            return [
                'command_code' => $commandCode,
                'action' => trim($action),
                'status' => PaymentDevRelayCommand::STATUS_SUCCEEDED,
                'response' => $response,
            ];
        } catch (\Throwable $throwable) {
            $model->setData(PaymentDevRelayCommand::schema_fields_STATUS, PaymentDevRelayCommand::STATUS_FAILED)
                ->setData(PaymentDevRelayCommand::schema_fields_RESPONSE_JSON, json_encode([
                    'message' => $throwable->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
                ->setData(PaymentDevRelayCommand::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeProviderRefund(array $payload): array
    {
        $methodCode = trim((string) ($payload['method_code'] ?? 'paypal'));
        $providerCode = trim((string) ($payload['provider_code'] ?? 'paypal'));
        $context = \is_array($payload['context'] ?? null) ? $payload['context'] : [];

        $method = ObjectManager::getInstance(\Weline\Payment\Model\PaymentMethod::class);
        $method->load(\Weline\Payment\Model\PaymentMethod::schema_fields_CODE, $methodCode);
        if (!$method->getId()) {
            throw new \RuntimeException((string) __('支付方式不存在'));
        }

        $provider = $this->methodManager->getProviderInstance($method, $context);
        if ($provider === null) {
            throw new \RuntimeException((string) __('支付 Provider 不可用。'));
        }
        if ($provider->getProviderCode() !== $providerCode) {
            throw new \RuntimeException((string) __('Provider 不匹配。'));
        }

        $request = RefundRequest::fromArray($payload);
        $result = $provider->refund($request);

        return [
            'status' => $result->getStatus(),
            'refund_code' => $result->getRefundCode(),
            'transaction_code' => $result->getTransactionCode(),
            'provider_reference' => $result->getProviderReference(),
            'message' => $result->getString(RefundResult::FIELD_MESSAGE),
            'retryable' => $result->isRetryable(),
            'payload' => $result->getArray(RefundResult::FIELD_PAYLOAD),
        ];
    }
}
