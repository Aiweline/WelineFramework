<?php
declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * Unified request termination exception.
 *
 * It now supports carrying the framework Response directly while remaining
 * backward compatible with the legacy `(status, body, headers)` constructor.
 */
class ResponseTerminateException extends \Error
{
    protected int $statusCode;

    /** @var array<string, string|array> */
    protected array $headers = [];

    protected string $body = '';

    protected ?Response $response = null;

    public function __construct(int|Response $statusCode = 200, string $body = '', array $headers = [])
    {
        if ($statusCode instanceof Response) {
            $this->response = $statusCode;
            $this->statusCode = $statusCode->getStatusCode();
            $this->headers = $statusCode->getHeaders();
            $this->body = $statusCode->getBody();
            parent::__construct("Response terminate with status {$this->statusCode}", $this->statusCode);
            return;
        }

        parent::__construct("Response terminate with status {$statusCode}", $statusCode);
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        if ($this->response !== null) {
            $this->response->setHeader($name, $value);
        }
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        if ($this->response !== null) {
            $this->response->setBody($body);
        }
        return $this;
    }

    public function getResponse(): Response
    {
        if ($this->response === null) {
            $response = new Response(true);
            $response->setHeaders($this->flattenHeaders($this->headers));
            $response->setHttpResponseCode($this->statusCode);
            $response->setBody($this->body);
            $this->response = $response;
        }

        return $this->response;
    }

    public function toHttpString(): string
    {
        return $this->getResponse()->toHttpString();
    }

    public function emit(bool $terminate = true): void
    {
        $this->getResponse()->emit($terminate);
    }

    /**
     * @param array<string, string|array> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                $result[$name] = \implode(', ', $value);
                continue;
            }
            $result[$name] = $value;
        }

        return $result;
    }

    protected function getStatusText(int $code): string
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
