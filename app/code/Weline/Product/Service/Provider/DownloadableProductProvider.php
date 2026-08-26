<?php

declare(strict_types=1);

namespace Weline\Product\Service\Provider;

use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\ProductTypeDefinition;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;

final class DownloadableProductProvider extends AbstractBuiltInProductProvider
{
    public function __construct(
        private ?FileAssetManagerInterface $assets = null,
    ) {
        parent::__construct();
    }

    public function getCode(): string { return 'builtin_downloadable'; }
    public function getType(): string { return 'downloadable'; }
    public function getLabel(): string { return (string)__('下载商品'); }
    public function getSortOrder(): int { return 30; }

    public function getDefinition(): ProductTypeDefinition
    {
        return new ProductTypeDefinition(
            code: $this->getType(),
            label: $this->getLabel(),
            minimumOffers: 1,
            maximumOffers: null,
            formSchema: [
                'sections' => ['basic', 'attributes', 'download_assets', 'entitlement_policy', 'prices'],
                'fields' => [
                    [
                        'code' => 'download_assets',
                        'label' => (string)__('受保护下载资产'),
                        'type' => 'json',
                        'required' => true,
                        'default' => [],
                        'help' => (string)__('发布前至少配置一个 ready、private 且授权 product_download 角色的 FileManager 资产。'),
                    ],
                    [
                        'code' => 'entitlement_policy',
                        'label' => (string)__('下载权益策略'),
                        'type' => 'json',
                        'default' => [
                            'download_limit' => null,
                            'expires_after_days' => null,
                        ],
                        'help' => (string)__('可设置下载次数和支付成功后的有效天数；null 表示不限制。'),
                    ],
                ],
                'assets' => ['visibility' => 'private', 'supports_limit' => true, 'supports_expiry' => true],
                'shipping' => 'none',
            ],
            requiredProductAttributes: ['name'],
            requiredOfferAttributes: ['sku', 'price'],
            supportsVariants: false,
            supportsPricing: true,
            tracksInventory: false,
            requiresShipping: false,
            supportsDigitalDelivery: true,
            supportsComposition: false,
        );
    }

    protected function validateProviderSpecific(ProductValidationContext $context): ProductValidationResult
    {
        $rows = $context->typeConfiguration['download_assets'] ?? [];
        if (!is_array($rows) || $rows === []) {
            return new ProductValidationResult();
        }

        $manager = $this->assetManager();
        if ($manager === null) {
            return new ProductValidationResult(errors: [[
                'code' => 'download_asset_unavailable',
                'message' => (string)__('FileManager 当前不可用，无法验证下载资产'),
                'path' => 'type_configuration.download_assets',
            ]]);
        }

        $errors = [];
        foreach ($rows as $index => $row) {
            $assetId = is_array($row) ? trim((string)($row['asset_id'] ?? '')) : '';
            if ($assetId === '') {
                continue;
            }
            try {
                $asset = $manager->get($assetId);
                if ($asset->getAssetId() === '' || $asset->isDeleted()) {
                    throw new \RuntimeException('asset_missing');
                }
                if (!$asset->isReady()) {
                    $errors[] = $this->assetIssue(
                        'download_asset_not_ready',
                        __('下载资产尚未 ready'),
                        $index,
                    );
                    continue;
                }
                if ($asset->getVisibility() !== FileAsset::VISIBILITY_PRIVATE) {
                    $errors[] = $this->assetIssue(
                        'download_asset_not_private',
                        __('下载资产必须是 private'),
                        $index,
                    );
                    continue;
                }
                if ($this->downloadPolicy($asset) === null) {
                    $errors[] = $this->assetIssue(
                        'download_asset_policy_invalid',
                        __('下载资产策略必须允许 product_download 角色'),
                        $index,
                    );
                }
            } catch (\Throwable $exception) {
                $errors[] = $this->assetIssue(
                    'download_asset_unavailable',
                    __('下载资产不存在或当前不可读取'),
                    $index,
                );
            }
        }

        return new ProductValidationResult(errors: $errors);
    }

    private function assetManager(): ?FileAssetManagerInterface
    {
        if ($this->assets !== null) {
            return $this->assets;
        }
        try {
            $candidate = ObjectManager::getInstance(FileAssetManagerInterface::class);
            if ($candidate instanceof FileAssetManagerInterface) {
                $this->assets = $candidate;
            }
        } catch (\Throwable) {
            return null;
        }
        return $this->assets;
    }

    /** @return array{policy_revision:int}|null */
    private function downloadPolicy(FileAsset $asset): ?array
    {
        try {
            $metadata = json_decode(
                trim((string)$asset->getData(FileAsset::schema_fields_METADATA)),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($metadata)) {
            return null;
        }
        $policy = $metadata['access_policy'] ?? $metadata;
        if (!is_array($policy) || !is_array($policy['allowed_roles'] ?? null)) {
            return null;
        }
        $roles = array_values(array_filter(array_map(
            static fn(mixed $role): string => is_scalar($role) ? trim((string)$role) : '',
            $policy['allowed_roles'],
        )));
        $revision = (int)($policy['policy_revision'] ?? 1);
        if (!in_array('product_download', $roles, true) || $revision < 1) {
            return null;
        }
        return ['policy_revision' => $revision];
    }

    /** @return array{code:string,message:string,path:string} */
    private function assetIssue(string $code, mixed $message, int|string $index): array
    {
        return [
            'code' => $code,
            'message' => (string)$message,
            'path' => 'type_configuration.download_assets.' . $index,
        ];
    }
}
