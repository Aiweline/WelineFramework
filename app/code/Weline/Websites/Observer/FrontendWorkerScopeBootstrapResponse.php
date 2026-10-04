<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\Runtime;
use Weline\Websites\Service\FrontendWorkerScopeBootstrapResponseService;

/**
 * Bridges the FPM, WLS, and early-FPC response surfaces to one decorator.
 */
final class FrontendWorkerScopeBootstrapResponse implements ObserverInterface
{
    private const EVENT_RUN_AFTER = 'Weline_Framework::App::run_after';
    private const EVENT_RESPONSE_READY = 'Weline_Framework_Http::response_ready';
    private const EVENT_FPC_HIT = 'Weline_Framework_Fpc::cache_hit_response';

    public function __construct(
        private readonly FrontendWorkerScopeBootstrapResponseService $responseService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventName = $event->getName();
        $persistent = Runtime::isPersistent();

        if ($eventName === self::EVENT_RUN_AFTER && !$persistent) {
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

        if (!\in_array($eventName, [self::EVENT_RESPONSE_READY, self::EVENT_FPC_HIT], true)) {
            return;
        }

        $response = $event->getData('response');
        if (!$response instanceof Response) {
            return;
        }
        $event->setData('response', $this->decorateOrFail($response));
    }

    private function decorateOrFail(mixed $result): mixed
    {
        try {
            return $this->responseService->decorate($result);
        } catch (FrontendWorkerScopeException $exception) {
            $cause = $exception->getPrevious();
            $nestedCause = $cause?->getPrevious();
            \w_log_error(
                '[FrontendWorkerScopeBootstrap] controlled response failure: {reason}',
                [
                    'reason' => $exception->reason,
                    'cause_type' => $cause !== null ? $cause::class : null,
                    'cause_code' => $cause instanceof \Weline\Framework\Service\Query\FrontendQueryException
                        ? $cause->getErrorCode() : null,
                    'cause_file' => $cause !== null ? $cause->getFile() : null,
                    'cause_line' => $cause?->getLine(),
                    'nested_cause_type' => $nestedCause !== null ? $nestedCause::class : null,
                ],
                'worker_scope',
            );

            $response = $result instanceof Response ? $result : new Response();
            $response->setHttpResponseCode(503);
            $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
            $response->setHeader('Pragma', 'no-cache');
            $response->setHeader('Expires', '0');
            $headers = $response->getHeaderCollectorInstance();
            $headers->removeHeader('Content-Encoding');
            $headers->removeHeader('Content-Length');
            $headers->removeHeader('Content-Disposition');
            foreach (['ETag', 'Content-MD5', 'Digest', 'Content-Digest', 'Last-Modified', 'Accept-Ranges'] as $header) {
                $headers->removeHeader($header);
            }
            $response->setBody((string)__('系统正在升级维护，请稍后再试。'));
            return $response;
        }
    }
}
