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
            $response->setHeader('Content-Type', 'text/html; charset=utf-8');
            $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
            $response->setHeader('Pragma', 'no-cache');
            $response->setHeader('Expires', '0');
            $response->setHeader('Refresh', '1');
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
            $plain = $exception->responseBody();
            $escaped = \htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escaped = \nl2br($escaped, false);
            $title = \htmlspecialchars((string)__('后台安全凭证暂不可用'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $retry = \htmlspecialchars((string)__('页面将自动重试，也可立即刷新。'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $response->setBody(
                '<!DOCTYPE html><html lang="zh-Hans"><head><meta charset="utf-8">'
                . '<meta http-equiv="refresh" content="1">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . $title . '</title>'
                . '<style>body{margin:2rem;font:16px/1.5 ui-sans-serif,system-ui,sans-serif;color:#111;background:#f5f5f5}a{color:#0b57d0}</style>'
                . '</head><body><p>' . $escaped . '</p><p>' . $retry . ' '
                . '<a href="">' . \htmlspecialchars((string)__('立即刷新'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p></body></html>'
            );
            return $response;
        }
    }
}
