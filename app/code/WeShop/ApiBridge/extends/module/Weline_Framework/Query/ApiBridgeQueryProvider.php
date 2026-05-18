<?php
declare(strict_types=1);

namespace WeShop\ApiBridge\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\ApiBridge\Service\ApiBridgeService;

class ApiBridgeQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ApiBridgeService $apiBridgeService
    ) {
    }

    public function getProviderName(): string
    {
        return 'apiBridge';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'test' => $this->test($params),
            default => throw new \InvalidArgumentException('ApiBridge query provider does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'apiBridge',
            'name' => __('WeShop API Bridge'),
            'description' => __('Provides the frontend API Bridge tester through the worker channel.'),
            'module' => 'WeShop_ApiBridge',
            'operations' => [
                [
                    'name' => 'test',
                    'description' => __('Run an API Bridge test request through Weline worker.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'endpoint' => ['type' => 'string', 'required' => true, 'max_length' => 32],
                        'method' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'params' => ['type' => 'map', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Run API Bridge tester',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function test(array $params): array
    {
        $endpoint = (string)($params['endpoint'] ?? '');
        $method = (string)($params['method'] ?? 'index');
        $methodParams = $params['params'] ?? [];
        if (!\is_array($methodParams) || \array_is_list($methodParams)) {
            $methodParams = [];
        }

        if ($endpoint === '') {
            return $this->apiBridgeService->errorResponse(__('API endpoint parameter is required'), 400);
        }
        if (!$this->apiBridgeService->endpointExists($endpoint)) {
            return $this->apiBridgeService->errorResponse(__('API endpoint "%1" not found', $endpoint), 404);
        }

        $bridge = match ($endpoint) {
            'cart' => $this->apiBridgeService->getCartBridge(),
            'checkout' => $this->apiBridgeService->getCheckoutBridge(),
            'auth' => $this->apiBridgeService->getAuthBridge(),
            default => null,
        };
        if ($bridge === null) {
            return $this->apiBridgeService->errorResponse(__('Unsupported API endpoint'), 400);
        }
        if (!\method_exists($bridge, $method)) {
            return $this->apiBridgeService->errorResponse(__('Method "%1" not found in endpoint "%2"', $method, $endpoint), 404);
        }

        return $this->apiBridgeService->successResponse(
            $bridge->$method($methodParams),
            __('API test executed successfully')
        );
    }
}
