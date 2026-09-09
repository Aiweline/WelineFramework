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
            $mode = strtolower(trim((string)$this->request->getParam('mode', '')));
            if ($mode === 'suggest' || $mode === 'autocomplete') {
                $query = trim((string)$this->request->getParam('q', $this->request->getParam('query', '')));
                $countryCode = strtoupper(trim((string)$this->request->getParam('country_code', '')));
                $limit = (int)$this->request->getParam('limit', 8);

                return $this->json([
                    'success' => true,
                    'message' => __('Suggest regions success'),
                    'data' => $this->regionService->suggest(
                        $query,
                        $countryCode !== '' ? $countryCode : null,
                        $limit
                    ),
                ]);
            }
            if ($mode === 'country_profile' || $mode === 'profile') {
                $countryCode = strtoupper(trim((string)$this->request->getParam('country_code', '')));

                return $this->json([
                    'success' => true,
                    'message' => __('Get country profile success'),
                    'data' => $this->regionService->getAddressCountryProfile(
                        $countryCode !== '' ? $countryCode : null
                    ),
                ]);
            }
            if ($mode === 'format_suggestion' || $mode === 'format') {
                $regionId = (int)$this->request->getParam('id', $this->request->getParam('region_id', 0));
                $formatted = $this->regionService->formatSuggestion($regionId);

                return $this->json([
                    'success' => $formatted !== null,
                    'message' => $formatted !== null ? __('Format suggestion success') : __('Region not found'),
                    'data' => $formatted,
                ], $formatted !== null ? 200 : 404);
            }
            if ($mode === 'embargo_evaluate' || $mode === 'embargo') {
                /** @var \Weline\Shipping\Service\EmbargoService $embargo */
                $embargo = ObjectManager::getInstance(\Weline\Shipping\Service\EmbargoService::class);
                $address = [
                    'country_code' => strtoupper(trim((string)$this->request->getParam('country_code', ''))),
                    'province_code' => trim((string)$this->request->getParam('province_code', '')),
                    'province_region_id' => (int)$this->request->getParam('province_region_id', 0),
                    'city_code' => trim((string)$this->request->getParam('city_code', '')),
                    'city_region_id' => (int)$this->request->getParam('city_region_id', 0),
                    'district_code' => trim((string)$this->request->getParam('district_code', '')),
                    'district_region_id' => (int)$this->request->getParam('district_region_id', 0),
                    'street_code' => trim((string)$this->request->getParam('street_code', '')),
                    'street_id' => (int)$this->request->getParam('street_id', 0),
                ];

                return $this->json([
                    'success' => true,
                    'message' => __('Embargo evaluate success'),
                    'data' => $embargo->evaluateAddress($address),
                ]);
            }
            if ($mode === 'embargo_countries') {
                /** @var \Weline\Shipping\Service\EmbargoService $embargo */
                $embargo = ObjectManager::getInstance(\Weline\Shipping\Service\EmbargoService::class);

                return $this->json([
                    'success' => true,
                    'message' => __('Embargo countries success'),
                    'data' => array_keys($embargo->embargoedCountryCodes()),
                ]);
            }
            if ($mode === 'embargo_regions' || $mode === 'embargo_subnational') {
                /** @var \Weline\Shipping\Service\EmbargoService $embargo */
                $embargo = ObjectManager::getInstance(\Weline\Shipping\Service\EmbargoService::class);
                $country = strtoupper(trim((string)$this->request->getParam('country_code', '')));
                $rows = $embargo->activeSubnationalRules();
                if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country)) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn(array $row): bool => ($row['country_code'] ?? '') === $country
                    ));
                }

                return $this->json([
                    'success' => true,
                    'message' => __('Embargo regions success'),
                    'data' => $rows,
                ]);
            }
            if ($mode === 'postal_countries') {
                $postal = trim((string)$this->request->getParam('postal_code', $this->request->getParam('postal', '')));

                return $this->json([
                    'success' => true,
                    'message' => __('Postal countries success'),
                    'data' => $this->regionService->postalCountries($postal),
                ]);
            }
            if ($mode === 'postal_lookup' || $mode === 'postal') {
                $countryCode = strtoupper(trim((string)$this->request->getParam('country_code', '')));
                $postal = trim((string)$this->request->getParam('postal_code', $this->request->getParam('postal', '')));
                $limit = (int)$this->request->getParam('limit', 20);

                return $this->json([
                    'success' => true,
                    'message' => __('Postal lookup success'),
                    'data' => $this->regionService->postalLookup($countryCode, $postal, $limit),
                ]);
            }
            if ($mode === 'has_streets') {
                $parentId = (int)$this->request->getParam('parent_region_id', 0);

                return $this->json([
                    'success' => true,
                    'message' => __('Has streets success'),
                    'data' => ['has_streets' => $this->regionService->hasStreets($parentId)],
                ]);
            }
            if ($mode === 'streets') {
                $parentId = (int)$this->request->getParam('parent_region_id', 0);
                $limit = (int)$this->request->getParam('limit', 200);

                return $this->json([
                    'success' => true,
                    'message' => __('List streets success'),
                    'data' => $this->regionService->streetsByParent($parentId, $limit),
                ]);
            }
            if ($mode === 'children') {
                $parentRegionId = $this->request->getParam('parent_region_id');
                $countryCode = (string)$this->request->getParam('country_code', '');
                $limit = (int)$this->request->getParam('limit', 500);

                return $this->json([
                    'success' => true,
                    'message' => __('Get regions success'),
                    'data' => $this->regionService->getChildrenList(
                        $parentRegionId !== null && $parentRegionId !== '' ? (int)$parentRegionId : null,
                        $countryCode !== '' ? $countryCode : null,
                        $limit
                    ),
                ]);
            }

            $countryCode = strtoupper(trim((string)$this->request->getParam('country_code', '')));
            $catalog = strtolower(trim((string)$this->request->getParam('catalog', 'installed')));
            if (!in_array($catalog, ['installed', 'global'], true)) {
                $catalog = 'installed';
            }

            return $this->json([
                'success' => true,
                'message' => __('Get regions success'),
                'data' => $this->regionService->getAllActiveList(
                    $countryCode !== '' ? $countryCode : null,
                    $catalog
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
                    $countryCode !== '' ? $countryCode : null,
                    (int)$this->request->getParam('limit', 500)
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

    /**
     * 本地地址自动完成（数据源：w_shipping_regions / 本地 pack）。
     */
    public function getSuggest(): string
    {
        try {
            $query = trim((string)$this->request->getParam('q', $this->request->getParam('query', '')));
            $countryCode = strtoupper(trim((string)$this->request->getParam('country_code', '')));
            $limit = (int)$this->request->getParam('limit', 8);

            return $this->json([
                'success' => true,
                'message' => __('Suggest regions success'),
                'data' => $this->regionService->suggest(
                    $query,
                    $countryCode !== '' ? $countryCode : null,
                    $limit
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

    public function getFormatSuggestion(): string
    {
        try {
            $regionId = (int)$this->request->getParam('id', $this->request->getParam('region_id', 0));
            $formatted = $this->regionService->formatSuggestion($regionId);

            return $this->json([
                'success' => $formatted !== null,
                'message' => $formatted !== null ? __('Format suggestion success') : __('Region not found'),
                'data' => $formatted,
            ], $formatted !== null ? 200 : 404);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    public function getCountryProfile(): string
    {
        try {
            $countryCode = strtoupper(trim((string)$this->request->getParam('country_code', '')));

            return $this->json([
                'success' => true,
                'message' => __('Get country profile success'),
                'data' => $this->regionService->getAddressCountryProfile(
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
