<?php

declare(strict_types=1);

namespace Weline\Payment\Interface;

use Throwable;
use Weline\Payment\Api\Data\AuthorizeRequest;
use Weline\Payment\Api\Data\AvailabilityRequest;
use Weline\Payment\Api\Data\CancelRequest;
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
     * CSP sources this payment vendor needs (collected by Payment Extends into app defaults).
     *
     * Declare script/frame/connect/img hosts required by hosted fields, SDK, approve redirect,
     * or iframe checkout. Empty when the method is fully first-party (e.g. fake card).
     *
     * @return array<string, list<string>> directive => absolute https hosts / keywords
     */
    public function cspDirectives(): array;

    /**
     * Display metadata for admin and checkout.
     *
     * Required keys:
     * - icon_url|icon: non-empty module static ref (Vendor_Module::img/....svg), media path, or absolute URL.
     * Optional: title, description, checkout_mode, checkout_template_code, config_template_code.
     * Admins may override the icon via SystemConfig `payment/method/{code}/icon`.
     *
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

    public function cancelPayment(CancelRequest $request): PaymentResult;

    public function authorize(AuthorizeRequest $request): PaymentResult;

    public function capture(CaptureRequest $request): PaymentResult;

    public function void(VoidRequest $request): PaymentResult;

    public function refund(RefundRequest $request): RefundResult;

    public function query(QueryRequest $request): PaymentResult;

    /**
     * Verify the exact raw request bytes. This method MUST be pure: no database,
     * cache, queue, network side effect, or payment state transition.
     */
    public function verifyCallback(CallbackRequest $request): CallbackResult;

    /**
     * Parse an already verified callback. This method MUST be pure and MUST NOT
     * advance Intent/Attempt/Order/Inventory state or create outbox records.
     */
    public function parseCallback(CallbackRequest $request): CallbackResult;

    public function testConnection(TestConnectionRequest $request): PaymentResult;

    /**
     * @param Throwable|array<string, mixed> $error
     */
    public function normalizeError(Throwable|array $error): ProviderError;
}
