<?php
declare(strict_types=1);

namespace Aiweline\BinQuery;

final class BinQueryClient
{
    private const PROTOCOL = 'binquery-v1';
    private const CACHE_PARAM = '__wq_cache';

    private BinaryCodec $codec;
    /** @var array<string, array<string, mixed>> */
    private array $operationDocs = [];

    private function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $area = 'frontend',
        private readonly string|bool $cache = 'auto'
    ) {
        $this->codec = new BinaryCodec();
    }

    /**
     * @param array{domain?:string,endpoint?:string,apiKey:string,area?:string,cache?:string|bool} $config
     */
    public static function connect(array $config): self
    {
        $apiKey = trim((string)($config['apiKey'] ?? ''));
        $domain = trim((string)($config['domain'] ?? ''));
        $endpoint = trim((string)($config['endpoint'] ?? ''));
        if ($endpoint === '') {
            if ($domain === '') {
                throw new \InvalidArgumentException('BinQuery domain is required.');
            }
            $endpoint = 'https://' . preg_replace('#^https?://#', '', rtrim($domain, '/')) . '/bin/query';
        }
        $client = new self(
            $endpoint,
            $apiKey,
            (string)($config['area'] ?? 'frontend'),
            $config['cache'] ?? 'auto'
        );
        $client->request(['type' => 'connect']);

        return $client;
    }

    public function help(?string $provider = null, ?string $operation = null): mixed
    {
        if ($provider !== null && $operation !== null) {
            return $this->docs($provider, $operation);
        }
        if ($provider !== null) {
            return $this->provider($provider);
        }

        return $this->providers();
    }

    public function query(string $what = 'providers', array $params = []): mixed
    {
        return $this->request(['type' => 'query', 'what' => $what] + $params);
    }

    public function providers(): array
    {
        return (array)$this->query('providers');
    }

    public function resources(): array
    {
        return $this->providers();
    }

    public function provider(string $provider): array
    {
        return (array)$this->query('provider', ['provider' => $provider]);
    }

    public function resource(string $provider): array
    {
        return $this->provider($provider);
    }

    public function operations(string $provider): array
    {
        return (array)$this->query('operations', ['provider' => $provider]);
    }

    public function docs(string $provider, string $operation): array
    {
        $key = $provider . '.' . $operation;
        if (!isset($this->operationDocs[$key])) {
            $this->operationDocs[$key] = (array)$this->query('docs', [
                'provider' => $provider,
                'operation' => $operation,
            ]);
        }

        return $this->operationDocs[$key];
    }

    public function exists(string $provider, ?string $operation = null): array
    {
        return (array)$this->query('exists', [
            'provider' => $provider,
            'operation' => $operation ?? '',
        ]);
    }

    public function hasProvider(string $provider): bool
    {
        return (bool)($this->exists($provider)['provider'] ?? false);
    }

    public function hasResource(string $provider): bool
    {
        return $this->hasProvider($provider);
    }

    public function hasOperation(string $provider, string $operation): bool
    {
        return (bool)($this->exists($provider, $operation)['operation'] ?? false);
    }

    public function call(string $provider, string $operation, array $params = []): mixed
    {
        $payload = [
            'type' => 'call',
            'provider' => $provider,
            'operation' => $operation,
            'params' => $params,
        ];
        $query = [];
        $marker = $this->buildCacheMarker($provider, $operation, $params);
        if ($marker !== '') {
            $query[self::CACHE_PARAM] = $marker;
        }

        return $this->request($payload, $query);
    }

    public function graph(array $operations): array
    {
        return (array)$this->request([
            'type' => 'graph',
            'graph' => $operations,
        ]);
    }

    private function request(array $payload, array $query = []): mixed
    {
        $payload['area'] = $payload['area'] ?? $this->area;
        $url = $this->endpoint . ($query === [] ? '' : ((str_contains($this->endpoint, '?') ? '&' : '?') . http_build_query($query)));
        $body = $this->codec->encodePacket($payload);
        $headers = [
            'Content-Type: ' . BinaryCodec::CONTENT_TYPE,
            'Accept: ' . BinaryCodec::CONTENT_TYPE,
            'Authorization: Bearer ' . $this->apiKey,
            'X-Weline-BinQuery-Protocol: ' . self::PROTOCOL,
        ];

        $responseBody = $this->send($url, $headers, $body);
        $decoded = $this->codec->decodePacket($responseBody);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            throw new \RuntimeException((string)($error['message'] ?? 'BinQuery request failed.'));
        }

        return $decoded['data'] ?? null;
    }

    private function send(string $url, array $headers, string $body): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 30,
            ]);
            $response = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);
            if (!is_string($response)) {
                throw new \RuntimeException($error !== '' ? $error : 'BinQuery HTTP request failed.');
            }
            return $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 30,
            ],
        ]);
        $response = file_get_contents($url, false, $context);
        if (!is_string($response)) {
            throw new \RuntimeException('BinQuery HTTP request failed.');
        }

        return $response;
    }

    private function buildCacheMarker(string $provider, string $operation, array $params): string
    {
        if ($this->cache !== 'auto') {
            return '';
        }

        $docs = $this->docs($provider, $operation);
        $cache = is_array($docs['cache'] ?? null) ? $docs['cache'] : [];
        if (($cache['cdn'] ?? false) !== true || ($docs['mode'] ?? '') !== 'read') {
            return '';
        }

        $keyParams = is_array($cache['key_params'] ?? null) ? $cache['key_params'] : [];
        $vary = is_array($cache['vary'] ?? null) ? $cache['vary'] : ['area', 'locale', 'currency'];
        $pickedParams = $keyParams === [] ? $params : array_intersect_key($params, array_flip($keyParams));
        ksort($pickedParams);
        $varyValues = [];
        foreach ($vary as $name) {
            $name = (string)$name;
            $varyValues[$name] = $name === 'area' ? $this->area : ($params[$name] ?? null);
        }
        ksort($varyValues);

        $json = json_encode([
            'area' => $this->area,
            'provider' => $provider,
            'operation' => $operation,
            'params' => $pickedParams,
            'vary' => $varyValues,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'wq1.' . $this->area . '.' . $provider . '.' . $operation . '.' . substr(hash('sha256', is_string($json) ? $json : ''), 0, 24);
    }
}
