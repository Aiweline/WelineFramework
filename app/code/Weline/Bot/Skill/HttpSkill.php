<?php
declare(strict_types=1);

namespace Weline\Bot\Skill;

use Weline\Bot\Interface\SkillInterface;
use Weline\Bot\Service\SkillContext;
use Weline\Bot\Service\SkillResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * HTTP 请求技能
 *
 * 发送 HTTP 请求
 */
class HttpSkill implements SkillInterface
{
    private ?Client $client = null;

    public function getCode(): string
    {
        return 'http.request';
    }

    public function getName(): string
    {
        return __('HTTP 请求');
    }

    public function getDescription(): string
    {
        return __('发送 HTTP 请求');
    }

    public function getCategory(): string
    {
        return 'api';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => __('请求 URL'),
                ],
                'method' => [
                    'type' => 'string',
                    'enum' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'],
                    'default' => 'GET',
                    'description' => __('HTTP 方法'),
                ],
                'headers' => [
                    'type' => 'object',
                    'description' => __('请求头'),
                ],
                'body' => [
                    'type' => 'object',
                    'description' => __('请求体（JSON）'),
                ],
                'timeout' => [
                    'type' => 'integer',
                    'default' => 30,
                    'description' => __('超时时间（秒）'),
                ],
                'follow_redirects' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => __('是否跟随重定向'),
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function getPermissionRequired(): array
    {
        return ['http.request'];
    }

    public function execute(array $params, SkillContext $context): SkillResult
    {
        $url = $params['url'] ?? '';
        $method = strtoupper($params['method'] ?? 'GET');
        $headers = $params['headers'] ?? [];
        $body = $params['body'] ?? [];
        $timeout = $params['timeout'] ?? 30;
        $followRedirects = $params['follow_redirects'] ?? true;

        if (empty($url)) {
            return SkillResult::error('URL is required');
        }

        // URL 格式验证
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return SkillResult::error("Invalid URL: {$url}");
        }

        try {
            $client = $this->getClient($timeout, $followRedirects);

            $options = [
                'headers' => $headers,
            ];

            if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($body)) {
                $options['json'] = $body;
            }

            $response = $client->request($method, $url, $options);

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
            $responseHeaders = $response->getHeaders();

            // 尝试解析 JSON
            $parsedBody = json_decode($responseBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $responseBody = $parsedBody;
            }

            return SkillResult::success([
                'url' => $url,
                'method' => $method,
                'status_code' => $statusCode,
                'headers' => $responseHeaders,
                'body' => $responseBody,
            ]);

        } catch (RequestException $e) {
            $response = $e->getResponse();
            return SkillResult::error(
                "HTTP request failed: {$e->getMessage()}",
                [
                    'url' => $url,
                    'method' => $method,
                    'status_code' => $response?->getStatusCode(),
                    'error' => $e->getMessage(),
                ]
            );
        } catch (\Throwable $e) {
            return SkillResult::error("Request failed: {$e->getMessage()}");
        }
    }

    public function isDangerous(): bool
    {
        return false;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * 获取 HTTP 客户端
     */
    private function getClient(int $timeout, bool $followRedirects): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'timeout' => $timeout,
                'allow_redirects' => $followRedirects,
                'verify' => true,
                'http_errors' => true,
            ]);
        }
        return $this->client;
    }
}
