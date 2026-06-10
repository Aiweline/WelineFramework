<?php

declare(strict_types=1);

namespace Weline\Payment\Interface;

use Throwable;
use Weline\Payment\Api\Data\AuthorizeRequest;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\AvailabilityResult;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\CallbackResult;
use Weline\Payment\Api\Data\CaptureRequest;
use Weline\Payment\Api\Data\PaymentRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\ProviderError;
use Weline\Payment\Api\Data\QueryRequest;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Api\Data\ResumeRequest;
use Weline\Payment\Api\Data\TestConnectionRequest;
use Weline\Payment\Api\Data\VoidRequest;

interface ProviderInterface
{
    public function getCode(): string;

    public function getProviderCode(): string;

    public function getProviderApiVersion(): string;

    public function getWebhookSchemaVersion(): string;

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * @return array<string, mixed>
     */
    public function getDisplayMetadata(): array;

    /**
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array;

    /**
     * @return array<string, mixed>
     */
    public function getDynamicFormSchema(AvailabilityRequest $request): array;

    public function checkAvailability(AvailabilityRequest $request): AvailabilityResult;

    public function createPayment(PaymentRequest $request): PaymentResult;

    public function resumePayment(ResumeRequest $request): PaymentResult;

    public function authorize(AuthorizeRequest $request): PaymentResult;

    public function capture(CaptureRequest $request): PaymentResult;

    public function void(VoidRequest $request): PaymentResult;

    public function refund(RefundRequest $request): RefundResult;

    public function query(QueryRequest $request): PaymentResult;

    public function verifyCallback(CallbackRequest $request): CallbackResult;

    public function parseCallback(CallbackRequest $request): CallbackResult;

    public function testConnection(TestConnectionRequest $request): PaymentResult;

    /**
     * @param Throwable|array<string, mixed> $error
     */
    public function normalizeError(Throwable|array $error): ProviderError;
}
