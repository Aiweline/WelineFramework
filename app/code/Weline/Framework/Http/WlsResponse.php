<?php
declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * Compatibility adapter for legacy WLS response call sites.
 *
 * The real response model is now {@see Response}; this class only keeps the
 * old type name available while delegating behavior to the unified response
 * implementation.
 */
class WlsResponse extends Response
{
    public const SERVER_VERSION = Response::SERVER_VERSION;
    public const SERVER_SIGNATURE = Response::SERVER_SIGNATURE;

    public function __construct(string $body = '', int $statusCode = 200, array $headers = [])
    {
        parent::__construct(true);
        $this->setHttpResponseCode($statusCode);
        $this->setHeaders($headers);
        $this->setBody($body);
    }

    public static function fromContent(string $content, int $statusCode = 200, ?string $contentType = null): self
    {
        $response = new self();
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', $contentType ?? 'text/plain; charset=utf-8');
        $response->setBody($content);

        return $response;
    }

    public static function json(mixed $data, int $statusCode = 200): self
    {
        $base = Response::json($data, $statusCode);
        return self::fromResponse($base);
    }

    public static function html(string $html, int $statusCode = 200): self
    {
        $base = Response::html($html, $statusCode);
        return self::fromResponse($base);
    }

    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self('', $statusCode);
        $response->setHeader('Location', $url);
        $response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');

        return $response;
    }

    public static function error(string $message, int $statusCode = 500): self
    {
        $base = Response::text($message, $statusCode);
        return self::fromResponse($base);
    }

    public function addCookieHeader(string $cookieString): self
    {
        $cookie = $this->parseCookieHeader($cookieString);
        if ($cookie !== null) {
            $this->setCookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expire'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httpOnly'],
                $cookie['sameSite'],
            );
        }

        return $this;
    }

    public function setStatusCode(int $code): self
    {
        $this->setHttpResponseCode($code);
        return $this;
    }

    private static function fromResponse(Response $response): self
    {
        $instance = new self();
        $instance->setHttpResponseCode($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $headerValue) {
                    $instance->setHeader($name, (string)$headerValue);
                }
            } else {
                $instance->setHeader($name, (string)$value);
            }
        }
        foreach ($response->getCookies() as $cookie) {
            $instance->setCookie(
                (string)$cookie['name'],
                (string)$cookie['value'],
                (int)($cookie['expire'] ?? 0),
                (string)($cookie['path'] ?? '/'),
                (string)($cookie['domain'] ?? ''),
                (bool)($cookie['secure'] ?? false),
                (bool)($cookie['httpOnly'] ?? true),
                (string)($cookie['sameSite'] ?? 'Lax'),
            );
        }
        $instance->setBody($response->getBody());

        return $instance;
    }

    /**
     * @return array{
     *   name:string,
     *   value:string,
     *   expire:int,
     *   path:string,
     *   domain:string,
     *   secure:bool,
     *   httpOnly:bool,
     *   sameSite:string
     * }|null
     */
    private function parseCookieHeader(string $cookieString): ?array
    {
        $segments = \array_map('trim', \explode(';', $cookieString));
        if ($segments === []) {
            return null;
        }

        $nameValue = \explode('=', (string)\array_shift($segments), 2);
        if (\count($nameValue) !== 2) {
            return null;
        }

        $cookie = [
            'name' => \urldecode($nameValue[0]),
            'value' => \urldecode($nameValue[1]),
            'expire' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'Lax',
        ];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            [$attr, $value] = \array_pad(\explode('=', $segment, 2), 2, null);
            $attr = \strtolower((string)$attr);

            match ($attr) {
                'expires' => $cookie['expire'] = $value === null ? 0 : ((\strtotime($value) ?: 0)),
                'path' => $cookie['path'] = (string)($value ?? '/'),
                'domain' => $cookie['domain'] = (string)($value ?? ''),
                'secure' => $cookie['secure'] = true,
                'httponly' => $cookie['httpOnly'] = true,
                'samesite' => $cookie['sameSite'] = (string)($value ?? 'Lax'),
                default => null,
            };
        }

        return $cookie;
    }
}
