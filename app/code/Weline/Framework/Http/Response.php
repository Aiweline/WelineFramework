<?php

namespace Weline\Framework\Http;

use Weline\Framework\App\State;
use Weline\Framework\Container\ContainerRuntime;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\TelemetryBroadcaster;
use Weline\Framework\Runtime\System;
use Weline\Framework\View\Helper\HtmlCacheAdmission;
use Weline\Framework\View\Helper\TitleLocaleProbe;

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

    private bool $titleLocaleProbeApplied = false;

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
        $dataObject = ContainerRuntime::get()->create(DataObject::class);
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

        $statusCode = \is_int($code) ? $code : (int)$code;
        if ($msg === '') {
            $msg = ErrorPageRenderer::defaultMessage($statusCode);
        }

        $eventData = ['code' => $statusCode, 'msg' => $msg];
        $this->getEvenManager()->dispatch('Weline_Framework_Http::http_response_no_router_before', $eventData);
        $statusCode = \is_int($eventData['code'] ?? $statusCode) ? (int)$eventData['code'] : $statusCode;
        $msg = (string)($eventData['msg'] ?? $msg);

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
        \Weline\Framework\Env\WelineEnv::setServer('REDIRECT_COUNT', $redirectCount, 'Response redirect');
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

    /**
     * Alias used by Backend controllers (Config/Order/...).
     * Prefer setHttpResponseCode in new code; keep setCode for compatibility.
     */
    public function setCode(int $code): static
    {
        return $this->setHttpResponseCode($code);
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        $this->telemetryPrepared = false;
        $this->titleLocaleProbeApplied = false;
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

        if ($this->getHeader('X-Powered-By') === null
            && ResponseObservabilityPolicy::poweredByHeaderEnabled()) {
            $response .= 'X-Powered-By: WLS/' . self::SERVER_VERSION . ' PHP/' . \PHP_VERSION . "\r\n";
        }

        $response .= "\r\n";
        $response .= $this->body;

        return $response;
    }

    public function compress(string $acceptEncoding = ''): self
    {
        $this->ensureDocumentCspMetaApplied();

        if ($this->body === '' || \strlen($this->body) < 1024) {
            return $this;
        }

        if ($this->getHeader('Content-Encoding') !== null) {
            return $this;
        }

        $contentType = (string)($this->getHeader('Content-Type') ?? '');
        if (!ContentEncodingNegotiator::isCompressibleContentType($contentType)) {
            return $this;
        }

        // Prefer Brotli when the client accepts it; otherwise gzip.
        $encoding = ContentEncodingNegotiator::negotiate($acceptEncoding);
        if ($encoding === null) {
            return $this;
        }

        $compressed = ContentEncodingNegotiator::encode($this->body, $encoding);
        if ($compressed === null) {
            return $this;
        }

        $this->body = $compressed;
        $this->setHeader('Content-Encoding', $encoding);
        $this->setHeader('Content-Length', (string)\strlen($this->body));
        $this->ensureVaryAcceptEncoding();

        return $this;
    }

    private function ensureVaryAcceptEncoding(): void
    {
        $vary = $this->getHeader('Vary');
        $varyValue = \is_array($vary) ? \implode(', ', \array_map('strval', $vary)) : (string)($vary ?? '');
        if ($varyValue === '') {
            $this->setHeader('Vary', 'Accept-Encoding');
            return;
        }

        foreach (\array_map('trim', \explode(',', $varyValue)) as $part) {
            if (\strcasecmp($part, 'Accept-Encoding') === 0) {
                return;
            }
        }

        $this->setHeader('Vary', $varyValue . ', Accept-Encoding');
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
        // Title×locale probe is independent of telemetry prep — FPC HIT marks
        // telemetryPrepared early and must still emit observability headers.
        $this->applyTitleLocaleProbeIfNeeded();
        // Product-card CSS heal is independent of FPC publish: ?nocache / private
        // responses skip publishResponse and would otherwise ship cards without
        // data-weline-product-card-css (UA buttons + primary-link titles).
        $this->healStorefrontProductCardCssIfNeeded();

        if ($this->telemetryPrepared) {
            return;
        }

        try {
            $this->ensureDocumentCspMetaApplied();
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

    /**
     * Ensure canonical product-card CSS is present whenever the body already
     * contains storefront cards. Runs on every HTML emission (FPC MISS included).
     */
    private function healStorefrontProductCardCssIfNeeded(): void
    {
        try {
            if ($this->body === '' || \strncmp($this->body, 'WQB1', 4) === 0) {
                return;
            }
            if (!\str_contains($this->body, 'weline-product-card')
                && !\str_contains($this->body, 'data-testid="weline-product-card"')
                && !\str_contains($this->body, "data-testid='weline-product-card'")
            ) {
                return;
            }
            $healed = HtmlCacheAdmission::healStorefrontProductCardCss($this->body);
            if ($healed !== $this->body) {
                $this->body = $healed;
                $this->synchronizeContentLengthHeader();
            }
        } catch (\Throwable) {
            // Decoration must never block emission.
        }
    }

    /**
     * Title × request-locale cross-talk probe (observability only).
     * Regex analysis runs only when TitleLocaleProbe::shouldEmit() is true.
     */
    private function applyTitleLocaleProbeIfNeeded(): void
    {
        if ($this->titleLocaleProbeApplied) {
            return;
        }
        try {
            if (!TitleLocaleProbe::shouldEmit()) {
                return;
            }
            if ($this->body === '' || \strncmp($this->body, 'WQB1', 4) === 0) {
                return;
            }
            // Cheap gate: look for <title in the document head region first.
            $headSample = \substr($this->body, 0, 16384);
            if (\stripos($headSample, '<title') === false
                && \stripos($this->body, '<title') === false
            ) {
                return;
            }

            $locale = $this->resolveRequestLocaleForTitleProbe();
            $uri = TitleLocaleProbe::armedRequestUri();
            if ($uri === '') {
                try {
                    $uri = (string)(WelineEnv::server('REQUEST_URI', '') ?? '');
                    if ($uri === '') {
                        $uri = (string)(WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', '') ?? '');
                    }
                } catch (\Throwable) {
                }
            }
            if ($uri === '') {
                $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            }
            $path = (string)(\parse_url($uri, PHP_URL_PATH) ?: $uri);
            $analysis = TitleLocaleProbe::analyze($this->body, $locale, $path);
            TitleLocaleProbe::applyToResponse($this, $analysis);
            $this->titleLocaleProbeApplied = true;
        } catch (\Throwable) {
            // Probe must never block emission.
        }
    }

    private function resolveRequestLocaleForTitleProbe(): string
    {
        // Prefer path locale from armed request URI — emission may run after State/Context reset.
        $armedUri = TitleLocaleProbe::armedRequestUri();
        if ($armedUri !== '') {
            $fromArmed = TitleLocaleProbe::localeFromRequestUri($armedUri);
            if ($fromArmed !== '') {
                return $fromArmed;
            }
        }

        try {
            $lang = \trim((string)State::getLang());
            if ($lang !== '') {
                return $lang;
            }
        } catch (\Throwable) {
        }

        $uri = $armedUri;
        if ($uri === '') {
            try {
                $uri = (string)(WelineEnv::server('REQUEST_URI', '') ?? '');
                if ($uri === '') {
                    $uri = (string)(WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', '') ?? '');
                }
            } catch (\Throwable) {
            }
        }
        if ($uri === '') {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        }

        return TitleLocaleProbe::localeFromRequestUri($uri);
    }

    /**
     * meta 交付：在发送前把当前 Scope CSP 写入 HTML head（FPC 明文路径同样受益）。
     * 已压缩正文跳过（依赖 FPC securityVariant 含 CSP digest 失效旧包）。
     */
    private function ensureDocumentCspMetaApplied(): void
    {
        if ($this->body === '' || $this->getHeader('Content-Encoding') !== null) {
            return;
        }

        // Never rewrite binary query-bin packets. WQB1 payloads often embed preview HTML
        // strings; mistaking them for documents prepends CSP <meta> and breaks magic.
        if (\strncmp($this->body, 'WQB1', 4) === 0) {
            return;
        }

        $contentType = \strtolower((string)($this->getHeader('Content-Type') ?? ''));
        if ($contentType !== ''
            && (\str_contains($contentType, 'weline-query-bin')
                || \str_contains($contentType, 'octet-stream')
                || \str_contains($contentType, 'application/octet-stream'))
        ) {
            return;
        }

        $looksHtml = $contentType === ''
            || \str_contains($contentType, 'text/html')
            || \str_contains($contentType, 'application/xhtml');
        if (!$looksHtml) {
            return;
        }
        if ($contentType === ''
            && \preg_match('/<html\b/i', \substr($this->body, 0, 4096)) !== 1
        ) {
            return;
        }

        try {
            $service = new Security\SecurityHeaderPolicyService();
            if (!$service->isMetaDelivery()) {
                return;
            }
            $updated = $service->ensureDocumentCspMeta($this->body);
            if ($updated !== $this->body) {
                $this->body = $updated;
                $this->synchronizeContentLengthHeader();
            }
        } catch (\Throwable) {
            // CSP decoration must never block emission.
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
