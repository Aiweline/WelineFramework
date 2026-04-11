<?php
declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Runtime\StateManager;

class HeaderCollector implements HeaderCollectorInterface
{
    private static ?HeaderCollector $instance = null;

    /** @var \WeakMap<\Fiber, HeaderCollector>|null */
    private static ?\WeakMap $fiberInstances = null;

    private array $headers = [];

    private array $cookies = [];

    private int $statusCode = 200;

    private bool $statusCodeExplicitlySet = false;

    private function __construct(bool $registerReset = true)
    {
        if ($registerReset) {
            StateManager::registerResetCallback('header_collector', function (): void {
                self::reset();
            });
        }
    }

    public static function getInstance(): HeaderCollector
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            self::$fiberInstances ??= new \WeakMap();
            if (!isset(self::$fiberInstances[$fiber])) {
                self::$fiberInstances[$fiber] = new self();
            }

            return self::$fiberInstances[$fiber];
        }

        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function createDetached(): HeaderCollector
    {
        return new self(false);
    }

    public static function reset(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            if (self::$fiberInstances !== null && isset(self::$fiberInstances[$fiber])) {
                self::clearCollector(self::$fiberInstances[$fiber]);
            }
            return;
        }

        if (self::$instance !== null) {
            self::clearCollector(self::$instance);
        }
    }

    public function setHeader(string $name, string $value, bool $replace = true): static
    {
        $normalizedName = $this->normalizeHeaderName($name);

        if ($replace || !isset($this->headers[$normalizedName])) {
            $this->headers[$normalizedName] = $value;
        } elseif (\is_array($this->headers[$normalizedName])) {
            $this->headers[$normalizedName][] = $value;
        } else {
            $this->headers[$normalizedName] = [$this->headers[$normalizedName], $value];
        }

        return $this;
    }

    public function setHeaders(array $headers, bool $replace = true): static
    {
        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $headerValue) {
                    $this->setHeader((string)$name, (string)$headerValue, false);
                }
                continue;
            }
            $this->setHeader((string)$name, (string)$value, $replace);
        }

        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): string|array|null
    {
        $normalizedName = $this->normalizeHeaderName($name);
        return $this->headers[$normalizedName] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        $normalizedName = $this->normalizeHeaderName($name);
        return isset($this->headers[$normalizedName]);
    }

    public function removeHeader(string $name): static
    {
        $normalizedName = $this->normalizeHeaderName($name);
        unset($this->headers[$normalizedName]);
        return $this;
    }

    public function clearHeaders(): static
    {
        $this->headers = [];
        return $this;
    }

    public function setStatusCode(int $code): static
    {
        $this->statusCode = $code;
        $this->statusCodeExplicitlySet = true;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function hasExplicitStatusCode(): bool
    {
        return $this->statusCodeExplicitlySet;
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
        $this->cookies[$name] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];
        return $this;
    }

    public function removeCookie(string $name): static
    {
        unset($this->cookies[$name]);
        return $this;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function captureState(): array
    {
        return [
            'headers' => $this->headers,
            'cookies' => $this->cookies,
            'status_code' => $this->statusCode,
            'status_code_explicit' => $this->statusCodeExplicitlySet,
        ];
    }

    public function restoreState(array $state): static
    {
        $this->headers = \is_array($state['headers'] ?? null) ? $state['headers'] : [];
        $this->cookies = \is_array($state['cookies'] ?? null) ? $state['cookies'] : [];
        $this->statusCode = (int)($state['status_code'] ?? 200);
        $this->statusCodeExplicitlySet = (bool)($state['status_code_explicit'] ?? false);

        return $this;
    }

    public function emit(bool $sendStatusCode = true): void
    {
        if (\headers_sent()) {
            return;
        }

        if ($sendStatusCode && $this->statusCode !== 200) {
            \http_response_code($this->statusCode);
        }

        foreach ($this->headers as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $headerValue) {
                    \header("{$name}: {$headerValue}", false);
                }
            } else {
                \header("{$name}: {$value}");
            }
        }

        foreach ($this->cookies as $cookie) {
            \setcookie(
                $cookie['name'],
                $cookie['value'],
                [
                    'expires' => $cookie['expire'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httpOnly'],
                    'samesite' => $cookie['sameSite'],
                ]
            );
        }
    }

    public function toHttpHeaderString(): string
    {
        $statusText = $this->getStatusText($this->statusCode);
        $headerString = "HTTP/1.1 {$this->statusCode} {$statusText}\r\n";

        foreach ($this->headers as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $headerValue) {
                    $headerString .= "{$name}: {$headerValue}\r\n";
                }
            } else {
                $headerString .= "{$name}: {$value}\r\n";
            }
        }

        foreach ($this->cookies as $cookie) {
            $headerString .= 'Set-Cookie: ' . $this->buildCookieString($cookie) . "\r\n";
        }

        return $headerString;
    }

    private function buildCookieString(array $cookie): string
    {
        $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];

        if (($cookie['expire'] ?? 0) !== 0) {
            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', (int)$cookie['expire']);
        }
        if (($cookie['path'] ?? '') !== '') {
            $parts[] = 'Path=' . $cookie['path'];
        }
        if (($cookie['domain'] ?? '') !== '') {
            $parts[] = 'Domain=' . $cookie['domain'];
        }
        if (!empty($cookie['secure'])) {
            $parts[] = 'Secure';
        }
        if (!empty($cookie['httpOnly'])) {
            $parts[] = 'HttpOnly';
        }
        if (($cookie['sameSite'] ?? '') !== '') {
            $parts[] = 'SameSite=' . $cookie['sameSite'];
        }

        return \implode('; ', $parts);
    }

    private function normalizeHeaderName(string $name): string
    {
        return \str_replace(' ', '-', \ucwords(\str_replace('-', ' ', \strtolower($name))));
    }

    private static function clearCollector(HeaderCollector $collector): void
    {
        $collector->headers = [];
        $collector->cookies = [];
        $collector->statusCode = 200;
        $collector->statusCodeExplicitlySet = false;
    }

    private function getStatusText(int $code): string
    {
        static $statusTexts = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            206 => 'Partial Content',
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
            408 => 'Request Timeout',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];

        return $statusTexts[$code] ?? 'Unknown';
    }
}
