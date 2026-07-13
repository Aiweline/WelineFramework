<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Policy;

/**
 * Data-only request view shared by policy kernels and transport adapters.
 */
final readonly class RequestEnvelope
{
    /**
     * @param array<string, string> $headers Header names must be lowercase.
     * @param array<string, scalar|null> $attributes
     */
    public function __construct(
        public string $peerIp,
        public string $method,
        public string $path,
        public string $host,
        public array $headers = [],
        public string $body = '',
        public array $attributes = [],
    ) {
        if (\trim($this->method) === '' || \trim($this->path) === '') {
            throw new \InvalidArgumentException('Request envelope method and path are required.');
        }
        foreach ($this->headers as $name => $value) {
            if (!\is_string($name) || $name === '' || $name !== \strtolower($name) || !\is_string($value)) {
                throw new \InvalidArgumentException('Request envelope headers must use lowercase string names and values.');
            }
        }
        foreach ($this->attributes as $name => $value) {
            if (!\is_string($name) || $name === '' || (!\is_scalar($value) && $value !== null)) {
                throw new \InvalidArgumentException('Request envelope attributes must be scalar data.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $headers = [];
        foreach ((array)($data['headers'] ?? []) as $name => $value) {
            $headers[\strtolower(\trim((string)$name))] = (string)$value;
        }
        $attributes = [];
        foreach ((array)($data['attributes'] ?? []) as $name => $value) {
            if (\is_scalar($value) || $value === null) {
                $attributes[(string)$name] = $value;
            }
        }
        return new self(
            peerIp: (string)($data['peer_ip'] ?? ''),
            method: \strtoupper(\trim((string)($data['method'] ?? ''))),
            path: (string)($data['path'] ?? ''),
            host: \strtolower(\trim((string)($data['host'] ?? ''))),
            headers: $headers,
            body: (string)($data['body'] ?? ''),
            attributes: $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'peer_ip' => $this->peerIp,
            'method' => $this->method,
            'path' => $this->path,
            'host' => $this->host,
            'headers' => $this->headers,
            'body' => $this->body,
            'attributes' => $this->attributes,
        ];
    }
}
