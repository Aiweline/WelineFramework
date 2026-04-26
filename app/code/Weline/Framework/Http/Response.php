<?php

namespace Weline\Framework\Http;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\TelemetryBroadcaster;
use Weline\Framework\Runtime\System;

/**
 * Unified framework response model.
 *
 * Existing request termination helpers are preserved, but the response state is
 * now first-class and can also be normalized from controller return values.
 */
class Response implements ResponseInterface
{
    public const SERVER_VERSION = '1.0.0';
    public const SERVER_SIGNATURE = 'Weline-Server/' . self::SERVER_VERSION;

    private ?HeaderCollectorInterface $headerCollector = null;

    private string $body = '';

    private bool $telemetryPrepared = false;

    public function __construct(bool $detached = false)
    {
        if ($detached) {
            $this->headerCollector = HeaderCollector::createDetached();
        }
    }

    public static function json(mixed $data, int $statusCode = 200): self
    {
        $response = new self(true);
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setBody(self::encodeJson($data));

        return $response;
    }

    public static function html(string $html, int $statusCode = 200): self
    {
        $response = new self(true);
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setBody($html);

        return $response;
    }

    public static function text(string $text, int $statusCode = 200, string $contentType = 'text/plain; charset=utf-8'): self
    {
        $response = new self(true);
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', $contentType);
        $response->setBody($text);

        return $response;
    }

    public static function fromContent(string $content, int $statusCode = 200, ?string $contentType = null): self
    {
        $response = new self(true);
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', $contentType ?? self::detectContentType($content));
        $response->setBody($content);

        return $response;
    }

    public static function normalize(mixed $result, ?self $fallback = null): self
    {
        if ($result instanceof self) {
            return $result;
        }

        if ($result === null) {
            return $fallback ?? new self(true);
        }

        if (\is_array($result)) {
            return self::json($result, $fallback?->getStatusCode() ?? 200);
        }

        if (\is_string($result)) {
            $contentType = (string)($fallback?->getHeader('Content-Type') ?? '');
            if ($contentType !== '' && !\str_contains(\strtolower($contentType), 'text/html')) {
                $response = $fallback ?? new self(true);
                $response->setBody($result);
                return $response;
            }

            return self::html($result, $fallback?->getStatusCode() ?? 200);
        }

        return self::text((string)$result, $fallback?->getStatusCode() ?? 200);
    }

    private function getHeaderCollector(): HeaderCollectorInterface
    {
        if ($this->headerCollector === null) {
            $this->headerCollector = HeaderCollector::getInstance();
        }

        return $this->headerCollector;
    }

    public function getEvenManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    public function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }

    public function getHeaders(): array
    {
        return $this->getHeaderCollector()->getHeaders();
    }

    public function getHeader(string $name): string|array|null
    {
        return $this->getHeaderCollector()->getHeader($name);
    }

    public function getCookies(): array
    {
        return $this->getHeaderCollector()->getCookies();
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->getHeaderCollector()->getStatusCode();
    }

    public function setHeader(string $headerKey, string $headerValue): static
    {
        $this->getHeaderCollector()->setHeader($headerKey, $headerValue);
        return $this;
    }

    public function setHeaders(array $headers): static
    {
        $this->getHeaderCollector()->setHeaders($headers);
        return $this;
    }

    public function emitHeaders(): void
    {
        $this->getHeaderCollector()->emit();
    }

    public function setCookie(
        string $name,
        string $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static {
        $this->getHeaderCollector()->setCookie($name, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite);
        return $this;
    }

    public function setData(mixed $data): static
    {
        /** @var DataObject $dataObject */
        $dataObject = ObjectManager::getInstance(DataObject::class);
        $dataObject->setData($data);

        $contentType = (string)$this->getRequest()->getContentType();
        if (\is_int(\strpos($contentType, 'application/json'))) {
            $this->setHeader('Content-Type', 'application/json; charset=utf-8');
            $this->setBody($dataObject->toJson());
        } elseif (\is_int(\strpos($contentType, 'text/xml'))) {
            $this->setHeader('Content-Type', 'text/xml');
            $this->setBody($dataObject->toXml());
        } else {
            $this->setBody($dataObject->toString());
        }

        return $this;
    }

    private function flushSessionBeforeTerminate(): void
    {
        if (\class_exists(\Weline\Framework\Session\Session::class, false)) {
            \Weline\Framework\Session\Session::flushRequestSessions();
        }
    }

    public function noRouter(int|string $code = 404, string $msg = ''): never
    {
        $this->flushSessionBeforeTerminate();

        if ($msg === '') {
            switch ($code) {
                case 403:
                    $msg = 'Forbidden';
                    break;
                case 404:
                    $msg = 'Not Found';
                    break;
                case 500:
                    $msg = 'Internal Server Error';
                    break;
                default:
                    $msg = 'Unknown Error';
            }
        }

        $eventData = ['code' => $code, 'msg' => $msg];
        $this->getEvenManager()->dispatch('Weline_Framework_Http::http_response_no_router_before', $eventData);
        $statusCode = \is_int($code) ? $code : (int)$code;

        throw new NoRouterException($statusCode, $msg);
    }

    public function responseHttpCode(int $code = 200): never
    {
        $this->setHttpResponseCode($code);
        throw new ResponseTerminateException($this);
    }

    public function redirect(string $url, int $code = 302): never
    {
        $this->flushSessionBeforeTerminate();

        $redirectCount = ((int)\w_env('wls.redirect_count', 0)) + 1;
        \w_env_set('wls.redirect_count', (string)$redirectCount, 'Response redirect');
        $_SERVER['REDIRECT_COUNT'] = $redirectCount;
        $currentUri = \w_env('request.uri', '/');

        if ($redirectCount > 5) {
            w_log_warning("[Redirect Warning] Too many redirects: {$redirectCount}, current URI: {$currentUri}, redirect to: {$url}");
        }

        if ($redirectCount > 10) {
            w_log_error("[Redirect Error] Redirect loop detected! Stopping redirect. Current URI: {$currentUri}, Attempted redirect to: {$url}");
            throw new \RuntimeException("Redirect loop detected after {$redirectCount} redirects");
        }

        $data = new DataObject(['url' => $url, 'code' => $code]);
        $this->getEvenManager()->dispatch('Framework_Http::response_redirect_before', $data);
        $url = (string)$data->getData('url');
        $code = (int)$data->getData('code');

        throw new RedirectException($url, $code);
    }

    public function renderJson(array $data): string
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        return self::encodeJson($data);
    }

    public function setHttpResponseCode(int $code): static
    {
        $this->getHeaderCollector()->setStatusCode($code);
        return $this;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        $this->telemetryPrepared = false;
        return $this;
    }

    public function markTelemetryPrepared(bool $prepared = true): static
    {
        $this->telemetryPrepared = $prepared;
        return $this;
    }

    public function emit(bool $terminate = true): void
    {
        $this->prepareForEmission();

        if (!\headers_sent()) {
            $contentType = (string)($this->getHeader('Content-Type') ?? '');
            if (!\str_contains(\strtolower($contentType), 'text/event-stream')
                && $this->getHeader('Content-Length') === null) {
                $this->setHeader('Content-Length', (string)\strlen($this->body));
            }
            $this->getHeaderCollector()->emit(true);
        }

        if ($this->body !== '') {
            echo $this->body;
        }

        if ($terminate) {
            System::exit(0);
        }
    }

    public function toHttpString(bool $keepAlive = true): string
    {
        $this->prepareForEmission();

        $statusCode = $this->getStatusCode();
        $statusText = self::getStatusText($statusCode);
        $response = "HTTP/1.1 {$statusCode} {$statusText}\r\n";

        foreach ($this->getHeaders() as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $headerValue) {
                    $response .= "{$name}: {$headerValue}\r\n";
                }
            } else {
                $response .= "{$name}: {$value}\r\n";
            }
        }

        foreach ($this->getCookies() as $cookie) {
            $response .= 'Set-Cookie: ' . $this->buildCookieString($cookie) . "\r\n";
        }

        $contentType = (string)($this->getHeader('Content-Type') ?? '');
        if (!\str_contains(\strtolower($contentType), 'text/event-stream')
            && $this->getHeader('Content-Length') === null) {
            $response .= 'Content-Length: ' . \strlen($this->body) . "\r\n";
        }

        if ($this->getHeader('Connection') === null) {
            $response .= 'Connection: ' . ($keepAlive ? 'keep-alive' : 'close') . "\r\n";
        }

        if ($this->getHeader('Server') === null) {
            $response .= 'Server: ' . self::SERVER_SIGNATURE . "\r\n";
        }

        if ($this->getHeader('X-Powered-By') === null) {
            $response .= 'X-Powered-By: WLS/' . self::SERVER_VERSION . ' PHP/' . \PHP_VERSION . "\r\n";
        }

        $response .= "\r\n";
        $response .= $this->body;

        return $response;
    }

    public function compress(string $acceptEncoding = ''): self
    {
        if ($this->body === '' || \strlen($this->body) < 1024) {
            return $this;
        }

        if (\stripos($acceptEncoding, 'gzip') !== false && \function_exists('gzencode')) {
            $compressed = \gzencode($this->body, 6);
            if ($compressed !== false) {
                $this->body = $compressed;
                $this->setHeader('Content-Encoding', 'gzip');
            }
        }

        return $this;
    }

    public function send(): never
    {
        $this->sendResponse();
    }

    public function sendResponse(): never
    {
        $this->flushSessionBeforeTerminate();
        throw new ResponseTerminateException($this);
    }

    public function download(string $file, string $name = '', bool $isDelete = false): never
    {
        if (!\is_file($file)) {
            Message::error(__('鏂囦欢涓嶅瓨鍦紒'));
            throw new NoRouterException(404, 'File not found');
        }

        throw new DownloadException($file, $name, $isDelete);
    }

    public function terminate(): ResponseTerminateException
    {
        return new ResponseTerminateException($this);
    }

    public function getHeaderCollectorInstance(): HeaderCollectorInterface
    {
        return $this->getHeaderCollector();
    }

    private static function encodeJson(mixed $data): string
    {
        $flags = \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE;
        if (\defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= \JSON_PARTIAL_OUTPUT_ON_ERROR;
        }

        $json = \json_encode($data, $flags);
        if ($json !== false) {
            return $json;
        }

        $fallback = \json_encode(
            ['code' => 500, 'msg' => 'JSON encode failed', 'data' => []],
            \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $fallback !== false ? $fallback : '{}';
    }

    private static function detectContentType(string $content): string
    {
        $trimmed = \ltrim($content);

        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = \json_decode($content);
            if (\json_last_error() === \JSON_ERROR_NONE) {
                return 'application/json; charset=utf-8';
            }
        }

        if (\stripos($trimmed, '<!DOCTYPE') === 0 || \stripos($trimmed, '<html') !== false) {
            return 'text/html; charset=utf-8';
        }

        if (\preg_match('/<(div|span|p|a|img|table|form|ul|ol|li|h[1-6]|head|body|html|script|style)\b/i', $trimmed)) {
            return 'text/html; charset=utf-8';
        }

        if (\stripos($trimmed, '<?xml') === 0) {
            return 'application/xml; charset=utf-8';
        }

        return 'text/plain; charset=utf-8';
    }

    private function prepareForEmission(): void
    {
        if ($this->telemetryPrepared) {
            return;
        }

        try {
            if ($this->shouldBroadcastTelemetryBeforeEmission()) {
                $preparedBody = TelemetryBroadcaster::broadcast(
                    $this->body,
                    $this->resolveRequestSafely(),
                    true
                );
                if ($preparedBody !== $this->body) {
                    $this->body = $preparedBody;
                    $this->synchronizeContentLengthHeader();
                }
            }
        } catch (\Throwable) {
            // Response decoration must never block the actual response emission.
        } finally {
            $this->telemetryPrepared = true;
        }
    }

    private function shouldBroadcastTelemetryBeforeEmission(): bool
    {
        if ($this->body === '') {
            return false;
        }

        if ($this->getHeader('Content-Encoding') !== null) {
            return false;
        }

        $contentType = \strtolower((string)($this->getHeader('Content-Type') ?? ''));
        if (\str_contains($contentType, 'text/event-stream')) {
            return false;
        }

        if ($contentType !== '') {
            return \str_contains($contentType, 'text/html');
        }

        return \str_contains(\strtolower(self::detectContentType($this->body)), 'text/html');
    }

    private function synchronizeContentLengthHeader(): void
    {
        $contentType = \strtolower((string)($this->getHeader('Content-Type') ?? ''));
        if (\str_contains($contentType, 'text/event-stream')) {
            return;
        }

        if ($this->getHeader('Content-Length') !== null) {
            $this->setHeader('Content-Length', (string)\strlen($this->body));
        }
    }

    private function resolveRequestSafely(): ?Request
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
            return $request instanceof Request ? $request : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildCookieString(array $cookie): string
    {
        $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];

        if (isset($cookie['expire']) && $cookie['expire'] !== 0) {
            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']);
        }
        if (!empty($cookie['path'])) {
            $parts[] = 'Path=' . $cookie['path'];
        }
        if (!empty($cookie['domain'])) {
            $parts[] = 'Domain=' . $cookie['domain'];
        }
        if (!empty($cookie['secure'])) {
            $parts[] = 'Secure';
        }
        if (!empty($cookie['httpOnly'])) {
            $parts[] = 'HttpOnly';
        }
        if (!empty($cookie['sameSite'])) {
            $parts[] = 'SameSite=' . $cookie['sameSite'];
        }

        return \implode('; ', $parts);
    }

    private static function getStatusText(int $code): string
    {
        static $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Request Entity Too Large',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $statusTexts[$code] ?? 'Unknown';
    }
}
