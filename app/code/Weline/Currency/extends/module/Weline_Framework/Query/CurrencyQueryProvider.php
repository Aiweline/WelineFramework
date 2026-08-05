<?php

declare(strict_types=1);

namespace Weline\Currency\Extends\Module\Weline_Framework\Query;

use Weline\Currency\Model\Config;
use Weline\Currency\Model\Currency;
use Weline\Currency\Service\CurrencyLocalDescriptionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CurrencyQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Currency $currencyModel,
        private readonly CurrencyLocalDescriptionService $localDescriptionService,
    ) {
    }

    public function getProviderName(): string
    {
        return 'currency';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'deleteCurrency' => $this->deleteCurrency($params),
            'adminRequest' => $this->adminRequest($params),
            default => throw new \InvalidArgumentException(
                (string)__('Currency 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'currency',
            'name' => __('货币管理'),
            'description' => __('后台货币 CRUD（bin-query）'),
            'module' => 'Weline_Currency',
            'operations' => [
                [
                    'name' => 'adminRequest',
                    'description' => 'Legacy controller bridge via bin-query',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true],
                        ['name' => 'method', 'type' => 'string', 'required' => false],
                        ['name' => 'headers', 'type' => 'array', 'required' => false],
                        ['name' => 'body', 'type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                ],

                [
                    'name' => 'deleteCurrency',
                    'description' => __('删除货币'),
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Currency::currency_delete'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'currency_id', 'type' => 'int', 'required' => true, 'description' => __('货币 ID')],
                    ],
                    'returns' => ['type' => 'array'],
                ],
            ],
        ];
    }

    private function deleteCurrency(array $params): array
    {
        $id = (int)($params['currency_id'] ?? $params['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('货币ID不能为空')];
        }

        try {
            $currency = clone $this->currencyModel;
            $currency->clear()->load($id);
            if (!$currency->getId()) {
                return ['success' => false, 'message' => (string)__('货币不存在')];
            }

            /** @var Config $config */
            $config = ObjectManager::getInstance(Config::class);
            $baseCurrency = $config->getBaseCurrency();
            if ($currency->getCode() === $baseCurrency) {
                return ['success' => false, 'message' => (string)__('不能删除基准货币')];
            }

            $currencyId = (int)$currency->getId();
            $currency->delete();
            $this->localDescriptionService->deleteLocalNames($currencyId);

            return ['success' => true, 'message' => (string)__('货币已删除')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string)__('删除失败：%{1}', $e->getMessage())];
        }
    }

    /** @param array<string,mixed> $params */
    private function adminRequest(array $params): mixed
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'POST'))) ?: 'POST';
        $headers = is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $body = array_key_exists('body', $params) && $params['body'] !== null ? (string)$params['body'] : '';
        if ($url === '') {
            return ['success' => false, 'message' => 'Missing URL'];
        }
        $parts = parse_url($url);
        $path = (string)($parts['path'] ?? '');
        $pathLower = strtolower($path);
        $markers = ['/currency/'];
        $normalized = $path;
        foreach ($markers as $marker) {
            $pos = strpos($pathLower, $marker);
            if ($pos !== false) {
                $normalized = substr($path, $pos);
                break;
            }
        }
        $area = 'Backend';
        $controllerSeg = 'Index';
        $actionSeg = 'index';
        if (preg_match('#^/[a-z0-9_-]+/(backend|admin|frontend)/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$#i', $normalized, $mm)) {
            $area = ucfirst(strtolower($mm[1]));
            $controllerSeg = $mm[2];
            $actionSeg = $mm[3] ?? 'index';
        } elseif (preg_match('#^/[a-z0-9_-]+/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$#i', $normalized, $mm)) {
            $controllerSeg = $mm[1];
            $actionSeg = $mm[2] ?? 'index';
        } else {
            return ['success' => false, 'message' => 'Unsupported admin path: ' . $normalized];
        }
        $controllerSeg = str_replace(['-', '_'], '', ucwords(str_replace(['-', '_'], ' ', $controllerSeg)));
        $actionSeg = str_replace('-', '', $actionSeg);
        $ns = 'Weline\Currency\Controller';
        $class = $ns . '\\' . $area . '\\' . $controllerSeg;
        if (!class_exists($class)) {
            $classAlt = $ns . '\\' . $controllerSeg;
            if (class_exists($classAlt)) {
                $class = $classAlt;
            } else {
                return ['success' => false, 'message' => 'Controller missing: ' . $class];
            }
        }
        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }
        $bodyParams = [];
        if ($body !== '') {
            $ct = '';
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'content-type') { $ct = strtolower((string)$value); break; }
            }
            if (str_contains($ct, 'application/json') || str_starts_with(ltrim($body), '{')) {
                $decoded = json_decode($body, true);
                $bodyParams = is_array($decoded) ? $decoded : [];
            } else {
                parse_str($body, $bodyParams);
                if (!is_array($bodyParams)) { $bodyParams = []; }
            }
        }
        $candidates = [$actionSeg, 'get' . ucfirst($actionSeg), 'post' . ucfirst($actionSeg)];
        if ($method === 'GET') {
            array_unshift($candidates, 'get' . ucfirst($actionSeg));
        } else {
            array_unshift($candidates, 'post' . ucfirst($actionSeg));
        }
        return \Weline\Framework\Service\Query\AdminControllerBridge::invoke(
            $class,
            $candidates,
            $queryParams,
            $bodyParams,
            $method,
            $body
        );
    }
}
