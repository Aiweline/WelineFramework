<?php
declare(strict_types=1);

namespace Weline\Shipping\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\RegionService;

class Region extends FrontendController
{
    private RegionService $regionService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->regionService = $objectManager->getInstance(RegionService::class);
    }

    public function getList(): string
    {
        try {
            return $this->json([
                'success' => true,
                'message' => __('Get regions success'),
                'data' => $this->regionService->getAllActiveList(),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    public function getChildren(): string
    {
        try {
            $parentRegionId = $this->request->getParam('parent_region_id');
            $countryCode = (string)$this->request->getParam('country_code', '');

            return $this->json([
                'success' => true,
                'message' => __('Get regions success'),
                'data' => $this->regionService->getChildrenList(
                    $parentRegionId !== null && $parentRegionId !== '' ? (int)$parentRegionId : null,
                    $countryCode !== '' ? $countryCode : null
                ),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    private function json(array $data, int $statusCode = 200): string
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
