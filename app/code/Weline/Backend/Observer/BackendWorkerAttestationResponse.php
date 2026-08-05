<?php

declare(strict_types=1);

namespace Weline\Backend\Observer;

use Weline\Backend\Service\BackendWorkerAttestationResponseService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationException;
use Weline\Framework\Runtime\Runtime;

/** Bridges WLS and FPM final response surfaces to backend page attestation. */
final class BackendWorkerAttestationResponse implements ObserverInterface
{
    private const EVENT_RUN_AFTER = 'Weline_Framework::App::run_after';
    private const EVENT_RESPONSE_READY = 'Weline_Framework_Http::response_ready';

    public function __construct(
        private readonly BackendWorkerAttestationResponseService $responseService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventName = $event->getName();
        $persistent = Runtime::isPersistent();
        if (($eventName === self::EVENT_RUN_AFTER && !$persistent)
            || ($eventName === self::EVENT_RESPONSE_READY && $persistent)) {
            return;
        }

        if ($eventName === self::EVENT_RUN_AFTER) {
            $result = $event->getData('result');
            if (!\is_string($result) && !$result instanceof Response) {
                return;
            }
            $event->setData('result', $this->decorateOrFail($result));
            return;
        }
        if ($eventName !== self::EVENT_RESPONSE_READY) {
            return;
        }

        $response = $event->getData('response');
        if ($response instanceof Response) {
            $event->setData('response', $this->decorateOrFail($response));
        }
    }

    private function decorateOrFail(mixed $result): mixed
    {
        try {
            return $this->responseService->decorate($result);
        } catch (FrontendWorkerBackendAttestationException $exception) {
            \w_log_error(
                '[BackendWorkerAttestation] controlled response failure: {reason}',
                ['reason' => $exception->reason],
                'worker_backend_attestation',
            );

            $response = $result instanceof Response ? $result : new Response();
            $response->setHttpResponseCode($exception->httpStatus);
            $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
            $response->setHeader('Pragma', 'no-cache');
            $response->setHeader('Expires', '0');
            $headers = $response->getHeaderCollectorInstance();
            foreach ([
                'Content-Encoding',
                'Content-Length',
                'Content-Disposition',
                'ETag',
                'Content-MD5',
                'Digest',
                'Content-Digest',
                'Last-Modified',
                'Accept-Ranges',
            ] as $header) {
                $headers->removeHeader($header);
            }
            $response->setBody((string)__('后台安全凭证暂不可用，请稍后刷新页面。'));
            return $response;
        }
    }
}
