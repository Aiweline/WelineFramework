<?php

declare(strict_types=1);

namespace Weline\Server\Controller\Test;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\WlsPerformanceTraceStore;

class WlsPerformancePanel extends FrontendController
{
    public function getIndex(): Response
    {
        return $this->jsonPayload([
            'success' => true,
            'message' => (string)__('WLS 性能面板端点可用。'),
            'operations' => ['summary', 'requests', 'request-detail', 'services', 'clear'],
        ]);
    }

    public function getSummary(): Response
    {
        if (!$this->canAccessPanel()) {
            return $this->jsonPayload($this->denied());
        }

        $params = $this->queryParams();

        return $this->jsonPayload($this->store()->summary(
            $this->boundedInt($params['window_sec'] ?? 300, 30, 3600, 300),
            $this->safeString($params['instance'] ?? ''),
            $this->safeString($params['host'] ?? '')
        ));
    }

    public function getRequests(): Response
    {
        if (!$this->canAccessPanel()) {
            return $this->jsonPayload($this->denied());
        }

        $params = $this->queryParams();
        $requests = $this->store()->requests(
            $this->boundedInt($params['limit'] ?? 50, 1, 200, 50),
            $this->boundedInt($params['since'] ?? 0, 0, \PHP_INT_MAX, 0),
            $this->truthy($params['slow_only'] ?? false),
            $this->safeString($params['instance'] ?? ''),
            $this->safeString($params['host'] ?? '')
        );

        return $this->jsonPayload([
            'success' => true,
            'requests' => $requests,
            'count' => \count($requests),
            'generated_at' => \time(),
        ]);
    }

    public function getRequestDetail(): Response
    {
        if (!$this->canAccessPanel()) {
            return $this->jsonPayload($this->denied());
        }

        $requestId = $this->safeString($this->queryParams()['request_id'] ?? '');
        if (!\preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $requestId)) {
            return $this->jsonPayload([
                'success' => false,
                'message' => (string)__('请求 ID 无效。'),
                'request' => [],
            ]);
        }

        $detail = $this->store()->getDetail($requestId);

        return $this->jsonPayload([
            'success' => $detail !== [],
            'message' => $detail !== [] ? (string)__('WLS 请求链路已加载。') : (string)__('未找到 WLS 请求链路。'),
            'request' => $detail,
        ]);
    }

    public function getServices(): Response
    {
        if (!$this->canAccessPanel()) {
            return $this->jsonPayload($this->denied());
        }

        return $this->jsonPayload($this->store()->services($this->safeString($this->queryParams()['instance'] ?? '')));
    }

    public function postClear(): Response
    {
        if (!$this->canAccessPanel()) {
            return $this->jsonPayload($this->denied());
        }

        return $this->jsonPayload($this->store()->clear());
    }

    public function getClear(): Response
    {
        return $this->postClear();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonPayload(array $payload): Response
    {
        return Response::json($payload)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache');
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParams(): array
    {
        $params = $this->request->getGet();

        return \is_array($params) ? $params : [];
    }

    private function canAccessPanel(): bool
    {
        if ((\defined('DEV') && DEV) || (\defined('DEBUG') && DEBUG)) {
            return true;
        }
        if ((bool)Env::get('wls.debug.performance_panel', false)) {
            return true;
        }
        if ((bool)Env::get('wls.performance_panel.enable_in_prod', false)) {
            $cookieName = (string)Env::get('wls.performance_panel.cookie_name', 'w_wls_perf');

            return $cookieName !== '' && Cookie::get($cookieName) === '1';
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function denied(): array
    {
        return [
            'success' => false,
            'message' => (string)__('WLS 性能面板仅在开发模式或授权调试模式下可用。'),
        ];
    }

    private function store(): WlsPerformanceTraceStore
    {
        return ObjectManager::getInstance(WlsPerformanceTraceStore::class);
    }

    private function boundedInt(mixed $value, int $min, int $max, int $default): int
    {
        if (\is_array($value)) {
            return $default;
        }
        $int = (int)$value;
        if ($int < $min) {
            return $min;
        }
        if ($int > $max) {
            return $max;
        }

        return $int;
    }

    private function safeString(mixed $value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        return \trim((string)$value);
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_scalar($value)) {
            return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
