<?php

namespace Weline\Framework\Controller;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Response;

abstract class AbstractRestController extends Core
{
    public const fetch_JSON = 'json';
    public const fetch_XML = 'xml';
    public const fetch_STRING = 'string';

    public function __construct()
    {
        $event = w_obj(EventsManager::class);
        $event->dispatch('Weline_Framework_RestController::init_before', $this);
        $this->__init();
        $event->dispatch('Weline_Framework_RestController::init_after', $this);
    }

    protected function fetch(mixed $data, string $type = self::fetch_JSON): string
    {
        return match ($type) {
            self::fetch_STRING => $this->toStringPayload($data),
            self::fetch_XML => $this->setXml((array)$data),
            default => Response::json($this->convertAllToString($data))->getBody(),
        };
    }

    private function toStringPayload(mixed $data): string
    {
        if (!\is_array($data)) {
            return (string)$data;
        }

        $result = '';
        foreach ($data as $key => $datum) {
            $result .= $key . ':' . $datum . ',';
        }

        return \trim($result, ',');
    }

    private function convertAllToString(mixed $data): mixed
    {
        if (\is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->convertAllToString($value);
            }
            return $result;
        }

        if (\is_object($data)) {
            $result = [];
            foreach ((array)$data as $key => $value) {
                $result[$key] = $this->convertAllToString($value);
            }
            return $result;
        }

        if ($data === null) {
            return '';
        }

        return (string)$data;
    }

    private function setXml(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $key => $val) {
            if (\is_numeric($val)) {
                $xml .= "<{$key}>{$val}</{$key}>";
                continue;
            }

            if (\is_array($val)) {
                $innerXml = \str_replace(['<xml>', '</xml>'], '', $this->setXml($val));
                $xml .= "<{$key}>{$innerXml}</{$key}>";
                continue;
            }

            $xml .= "<{$key}><![CDATA[{$val}]]></{$key}>";
        }
        $xml .= '</xml>';

        return $xml;
    }

    protected function success(string $msg = '请求成功', mixed $data = '', int $code = 200): array|string
    {
        $response = Response::json([
            'success' => true,
            'error' => false,
            'code' => $code,
            'msg' => __($msg),
            'message' => __($msg),
            'data' => $data,
        ], $code);
        $this->syncRestHttpStatus($response);

        return $response->getBody();
    }

    protected function error(string $msg = '请求失败', mixed $data = '', int $code = 400, ?string $title = null): array|string
    {
        $response = Response::json([
            'success' => false,
            'error' => true,
            'code' => $code,
            'title' => $title ?? \Weline\Framework\Exception\ErrorResponse::getTitle($code),
            'msg' => __($msg),
            'message' => __($msg),
            'icon' => \Weline\Framework\Exception\ErrorResponse::getIcon($code),
            'data' => $data,
        ], $code);
        $this->syncRestHttpStatus($response);

        return $response->getBody();
    }

    protected function exception(\Throwable $exception, string $msg = '', mixed $data = '', ?int $code = null): string
    {
        $statusCode = $code ?? \Weline\Framework\Exception\ErrorResponse::getStatusCode($exception);
        $message = $msg ?: $exception->getMessage();

        $payload = [
            'success' => false,
            'error' => true,
            'code' => $statusCode,
            'title' => \Weline\Framework\Exception\ErrorResponse::getTitle($statusCode),
            'msg' => __($message),
            'message' => __($message),
            'icon' => \Weline\Framework\Exception\ErrorResponse::getIcon($statusCode),
            'data' => $data,
        ];

        if (\defined('DEV') && DEV) {
            $payload['debug'] = [
                'exception' => \get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        $response = Response::json($payload, $statusCode);
        $this->syncRestHttpStatus($response);

        return $response->getBody();
    }

    /**
     * success/error/exception keep returning JSON body strings for BC with `: string`
     * action signatures, but must also stamp the request-scoped HTTP status. Otherwise
     * Router::resolveRequestScopedResponse keeps the default 200 while body.code is 4xx/5xx.
     */
    private function syncRestHttpStatus(Response $response): void
    {
        try {
            if (!isset($this->request)) {
                return;
            }
            $scoped = $this->request->getResponse();
            $scoped->setHttpResponseCode($response->getStatusCode());
            $contentType = $response->getHeader('Content-Type');
            if (\is_string($contentType) && $contentType !== '') {
                $scoped->setHeader('Content-Type', $contentType);
            }
        } catch (\Throwable) {
            // Controllers constructed outside a live request keep body-only behavior.
        }
    }
}
