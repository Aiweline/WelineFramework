<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Product\Helper\ProductLayoutScanner;
use WeShop\Product\Model\ProductCategory;
use WeShop\Product\Model\ProductLayout;
use WeShop\Product\Model\ProductLayoutSchedule;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

class ProductLayoutService
{
    private const CACHE_KEY_PREFIX = 'weshop_product_layout_';
    private const CACHE_TTL = 3600;
    private const THEME_DEFAULT_AREA = 'frontend';

    private ProductLayout $productLayoutModel;
    private ProductLayoutSchedule $scheduleModel;
    private ?ProductCategory $productCategoryModel;

    public function __construct(
        ProductLayout $productLayoutModel,
        ProductLayoutSchedule $scheduleModel,
        ?ProductCategory $productCategoryModel = null
    ) {
        $this->productLayoutModel = $productLayoutModel;
        $this->scheduleModel = $scheduleModel;
        $this->productCategoryModel = $productCategoryModel;
    }

    public function getProductLayout(int $productId, string $layoutType): ?string
    {
        return $this->resolveProductLayoutOption($productId, $layoutType);
    }

    public function resolveProductLayoutOption(int $productId, string $layoutType = ProductLayout::LAYOUT_TYPE_PRODUCT): ?string
    {
        $context = $this->resolveProductLayoutContext($productId, $layoutType);
        return is_array($context) ? (string)$context['layout_code'] : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveProductLayoutContext(
        int $productId,
        string $layoutType = ProductLayout::LAYOUT_TYPE_PRODUCT,
        ?int $currentCategoryId = null
    ): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        foreach ($this->productLayoutTypeCandidates($layoutType) as $candidateLayoutType) {
            $context = $this->getActiveEntityLayoutContext(
                ProductLayout::ENTITY_PRODUCT,
                $productId,
                $candidateLayoutType
            );
            if ($context !== null) {
                return $context + [
                    'source' => $context['source'] ?? ProductLayout::ENTITY_PRODUCT,
                    'matched_product_id' => $productId,
                ];
            }
        }

        foreach ($this->getProductCategoryIds($productId, $currentCategoryId) as $categoryId) {
            foreach ($this->categoryProductDefaultLayoutTypeCandidates() as $candidateLayoutType) {
                $context = $this->getActiveEntityLayoutContext(
                    ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
                    $categoryId,
                    $candidateLayoutType
                );
                if ($context !== null) {
                    return $context + [
                        'source' => $context['source'] ?? ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
                        'matched_category_id' => $categoryId,
                    ];
                }
            }
        }

        return null;
    }

    public function resolveCategoryLayoutOption(int $categoryId, string $layoutType = ProductLayout::LAYOUT_TYPE_CATEGORY): ?string
    {
        $context = $this->resolveCategoryLayoutContext($categoryId, $layoutType);
        return is_array($context) ? (string)$context['layout_code'] : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveCategoryLayoutContext(int $categoryId, string $layoutType = ProductLayout::LAYOUT_TYPE_CATEGORY): ?array
    {
        if ($categoryId <= 0) {
            return null;
        }

        $context = $this->getActiveEntityLayoutContext(
            ProductLayout::ENTITY_CATEGORY,
            $categoryId,
            $this->normalizeCategoryLayoutType($layoutType)
        );
        if ($context === null) {
            return null;
        }

        return $context + [
            'source' => $context['source'] ?? ProductLayout::ENTITY_CATEGORY,
            'matched_category_id' => $categoryId,
        ];
    }

    public function resolveCategoryProductDefaultLayoutOption(int $categoryId): ?string
    {
        $context = $this->resolveCategoryProductDefaultLayoutContext($categoryId);
        return is_array($context) ? (string)$context['layout_code'] : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveCategoryProductDefaultLayoutContext(int $categoryId): ?array
    {
        if ($categoryId <= 0) {
            return null;
        }

        foreach ($this->categoryProductDefaultLayoutTypeCandidates() as $candidateLayoutType) {
            $context = $this->getActiveEntityLayoutContext(
                ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
                $categoryId,
                $candidateLayoutType
            );
            if ($context !== null) {
                return $context + [
                    'source' => $context['source'] ?? ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
                    'matched_category_id' => $categoryId,
                ];
            }
        }

        return null;
    }

    public function applyProductLayout(int $productId, string $layoutType, string $layoutCode, array $config = []): bool
    {
        return $this->applyEntityLayout(
            ProductLayout::ENTITY_PRODUCT,
            $productId,
            $this->normalizeProductLayoutType($layoutType),
            $layoutCode,
            $config
        );
    }

    public function applyCategoryLayout(int $categoryId, string $layoutCode, array $config = []): bool
    {
        return $this->applyEntityLayout(
            ProductLayout::ENTITY_CATEGORY,
            $categoryId,
            ProductLayout::LAYOUT_TYPE_CATEGORY,
            $layoutCode,
            $config
        );
    }

    public function applyCategoryProductDefaultLayout(int $categoryId, string $layoutCode, array $config = []): bool
    {
        return $this->applyEntityLayout(
            ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
            $categoryId,
            ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT,
            $layoutCode,
            $config
        );
    }

    public function applyEntityLayout(
        string $entityType,
        int $entityId,
        string $layoutType,
        string $layoutCode,
        array $config = []
    ): bool {
        $layoutCode = trim($layoutCode);
        if ($entityId <= 0 || $layoutType === '' || $layoutCode === '') {
            return false;
        }

        try {
            $result = $this->themeQuery('saveLayoutSelection', [
                'target_type' => $entityType,
                'target_id' => $entityId,
                'layout_type' => $layoutType,
                'layout_option' => $layoutCode,
                'options' => [
                    'metadata' => [
                        'weshop_config' => $config,
                    ],
                ],
            ]);
            if (empty($result['success'])) {
                w_log_error('Apply Theme layout selection failed: {status}', ['status' => (string)($result['status'] ?? 'unknown')]);
                return false;
            }
            $this->clearEntityLayoutCache($entityType, $entityId, $layoutType);

            return true;
        } catch (\Throwable $e) {
            w_log_error('Apply layout failed: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteEntityLayoutSelection(string $entityType, int $entityId, string $layoutType): bool
    {
        if ($entityId <= 0 || trim($layoutType) === '') {
            return false;
        }

        try {
            $result = $this->themeQuery('deleteLayoutSelection', [
                'target_type' => $entityType,
                'target_id' => $entityId,
                'layout_type' => $layoutType,
            ]);
            if (empty($result['success'])) {
                return false;
            }
            $this->clearEntityLayoutCache($entityType, $entityId, $layoutType);
            return true;
        } catch (\Throwable $e) {
            w_log_error('Delete Theme layout selection failed: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listEntityLayoutSelectionVersions(
        string $entityType,
        int $entityId,
        string $layoutType,
        int $limit = 10
    ): array {
        $entityType = trim($entityType);
        $layoutType = trim($layoutType);
        if ($entityType === '' || $entityId <= 0 || $layoutType === '') {
            return [];
        }

        try {
            $versions = \w_query('theme', 'listLayoutSelectionVersions', [
                'target_type' => $entityType,
                'target_id' => $entityId,
                'layout_type' => $layoutType,
                'limit' => $limit,
                'with_precheck' => true,
            ], 'backend');

            return is_array($versions) ? array_values(array_filter($versions, 'is_array')) : [];
        } catch (\Throwable $e) {
            w_log_error('List Theme layout selection versions failed: {error}', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function rollbackEntityLayoutSelectionVersion(
        string $entityType,
        int $entityId,
        string $layoutType,
        int $versionId,
        string $reason = ''
    ): array {
        $entityType = trim($entityType);
        $layoutType = trim($layoutType);
        if ($entityType === '' || $entityId <= 0 || $layoutType === '' || $versionId <= 0) {
            return ['success' => false, 'status' => 'invalid_params'];
        }

        try {
            $result = \w_query('theme', 'rollbackLayoutSelectionVersion', [
                'version_id' => $versionId,
                'target_type' => $entityType,
                'target_id' => $entityId,
                'layout_type' => $layoutType,
                'reason' => $reason !== '' ? $reason : (string)__('回滚 WeShop 布局选择版本'),
            ], 'backend');

            if (!is_array($result)) {
                return ['success' => false, 'status' => 'invalid_result'];
            }

            if (!empty($result['success'])) {
                $this->clearEntityLayoutCache($entityType, $entityId, $layoutType);
            }

            return $result;
        } catch (\Throwable $e) {
            w_log_error('Rollback Theme layout selection version failed: {error}', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'status' => 'exception',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function getProductLayoutSchedule(int $productId, string $layoutType): ?ProductLayoutSchedule
    {
        return $this->scheduleModel->getActiveScheduleByProduct($productId, $this->normalizeProductLayoutType($layoutType));
    }

    public function createProductLayoutSchedule(
        int $productId,
        string $layoutType,
        string $layoutCode,
        string $startTime,
        ?string $endTime = null,
        bool $isRecurring = false,
        string $cronExpression = '',
        string $description = '',
        int $priority = 0,
        string $timezone = ''
    ): ?ProductLayoutSchedule {
        return $this->createEntityLayoutSchedule(
            ProductLayout::ENTITY_PRODUCT,
            $productId,
            $this->normalizeProductLayoutType($layoutType),
            $layoutCode,
            $startTime,
            $endTime,
            $isRecurring,
            $cronExpression,
            $description,
            $priority,
            $timezone
        );
    }

    public function createCategoryLayoutSchedule(
        int $categoryId,
        string $layoutCode,
        string $startTime,
        ?string $endTime = null,
        bool $isRecurring = false,
        string $cronExpression = '',
        string $description = '',
        int $priority = 0,
        string $timezone = ''
    ): ?ProductLayoutSchedule {
        return $this->createEntityLayoutSchedule(
            ProductLayout::ENTITY_CATEGORY,
            $categoryId,
            ProductLayout::LAYOUT_TYPE_CATEGORY,
            $layoutCode,
            $startTime,
            $endTime,
            $isRecurring,
            $cronExpression,
            $description,
            $priority,
            $timezone
        );
    }

    public function createCategoryProductDefaultLayoutSchedule(
        int $categoryId,
        string $layoutCode,
        string $startTime,
        ?string $endTime = null,
        bool $isRecurring = false,
        string $cronExpression = '',
        string $description = '',
        int $priority = 0,
        string $timezone = ''
    ): ?ProductLayoutSchedule {
        return $this->createEntityLayoutSchedule(
            ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
            $categoryId,
            ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT,
            $layoutCode,
            $startTime,
            $endTime,
            $isRecurring,
            $cronExpression,
            $description,
            $priority,
            $timezone
        );
    }

    public function createEntityLayoutSchedule(
        string $entityType,
        int $entityId,
        string $layoutType,
        string $layoutCode,
        string $startTime,
        ?string $endTime = null,
        bool $isRecurring = false,
        string $cronExpression = '',
        string $description = '',
        int $priority = 0,
        string $timezone = ''
    ): ?ProductLayoutSchedule {
        $layoutCode = trim($layoutCode);
        if ($entityId <= 0 || $layoutType === '' || $layoutCode === '') {
            return null;
        }

        try {
            $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
            $schedule->clearData()->clearQuery()
                ->setEntityType($entityType)
                ->setEntityId($entityId)
                ->setLayoutType($layoutType)
                ->setLayoutCode($layoutCode)
                ->setStartTime($startTime)
                ->setEndTime($endTime)
                ->setIsRecurring($isRecurring)
                ->setCronExpression($cronExpression)
                ->setPriority($priority)
                ->setTimezone($timezone)
                ->setStatus(ProductLayoutSchedule::STATUS_PENDING)
                ->setDescription($description)
                ->save();

            $created = clone $schedule;
            $created->clearQuery();
            return $created;
        } catch (\Throwable $e) {
            w_log_error('Create layout schedule failed: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function updateProductLayoutSchedule(int $scheduleId, array $data): bool
    {
        try {
            $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
            $schedule->load($scheduleId);

            if (!$schedule->getId()) {
                return false;
            }

            if (isset($data['entity_type'])) {
                $schedule->setEntityType((string)$data['entity_type']);
            }
            if (isset($data['entity_id'])) {
                $schedule->setEntityId((int)$data['entity_id']);
            }
            if (isset($data['layout_code'])) {
                $schedule->setLayoutCode((string)$data['layout_code']);
            }
            if (isset($data['layout_type'])) {
                $schedule->setLayoutType((string)$data['layout_type']);
            }
            if (isset($data['start_time'])) {
                $schedule->setStartTime((string)$data['start_time']);
            }
            if (isset($data['end_time'])) {
                $schedule->setEndTime($data['end_time'] !== null ? (string)$data['end_time'] : null);
            }
            if (isset($data['is_recurring'])) {
                $schedule->setIsRecurring((bool)$data['is_recurring']);
            }
            if (isset($data['cron_expression'])) {
                $schedule->setCronExpression((string)$data['cron_expression']);
            }
            if (isset($data['status'])) {
                $schedule->setStatus((string)$data['status']);
            }
            if (isset($data['priority'])) {
                $schedule->setPriority((int)$data['priority']);
            }
            if (isset($data['timezone'])) {
                $schedule->setTimezone((string)$data['timezone']);
            }
            if (isset($data['description'])) {
                $schedule->setDescription((string)$data['description']);
            }

            $schedule->save();
            $this->clearEntityLayoutCache($schedule->getEntityType(), $schedule->getEntityId(), $schedule->getLayoutType());

            return true;
        } catch (\Throwable $e) {
            w_log_error('Update layout schedule failed: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteProductLayoutSchedule(int $scheduleId): bool
    {
        try {
            $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
            $schedule->load($scheduleId);

            if (!$schedule->getId()) {
                return false;
            }

            $entityType = $schedule->getEntityType();
            $entityId = $schedule->getEntityId();
            $layoutType = $schedule->getLayoutType();

            $schedule->delete();
            $this->clearEntityLayoutCache($entityType, $entityId, $layoutType);

            return true;
        } catch (\Throwable $e) {
            w_log_error('Delete layout schedule failed: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getProductSchedules(int $productId, ?string $layoutType = null): array
    {
        return $this->scheduleModel->getByProduct(
            $productId,
            $layoutType !== null ? $this->normalizeProductLayoutType($layoutType) : null
        );
    }

    public function getEntitySchedules(string $entityType, int $entityId, ?string $layoutType = null): array
    {
        return $this->scheduleModel->getByEntity($entityType, $entityId, $layoutType);
    }

    public function getAvailableEditableLayoutOptions(string $layoutType, array $identity = []): array
    {
        $editableLayoutType = $this->normalizeEditableLayoutType($layoutType);
        $options = ProductLayoutScanner::scanProductLayouts($editableLayoutType);
        try {
            $options = array_replace(
                $options,
                $this->themeQuery('listVirtualLayoutOptions', [
                    'layout_type' => $editableLayoutType,
                    'theme_id' => (int)($identity['theme_id'] ?? 0),
                    'area' => (string)($identity['area'] ?? self::THEME_DEFAULT_AREA),
                    'scope' => isset($identity['scope']) ? (string)$identity['scope'] : null,
                ])
            );
        } catch (\Throwable) {
        }

        if (!isset($options['default']) && $this->editableLayoutExists($editableLayoutType, 'default')) {
            $options['default'] = [
                'name' => 'Default',
                'description' => '',
                'template' => 'Weline_Theme::theme/frontend/layouts/' . $editableLayoutType . '/default.phtml',
                'preview_image' => '',
                'config' => [],
            ];
        }

        ksort($options, SORT_STRING);
        return $options;
    }

    public function normalizeEditableLayoutType(string $layoutType): string
    {
        $layoutType = trim($layoutType);
        if ($layoutType === ProductLayout::LAYOUT_TYPE_PRODUCT_DETAIL
            || $layoutType === ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT
            || $layoutType === ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT
        ) {
            return ProductLayout::LAYOUT_TYPE_PRODUCT;
        }
        if ($layoutType === ProductLayout::LAYOUT_TYPE_CATEGORY) {
            return ProductLayout::LAYOUT_TYPE_CATEGORY;
        }

        return ProductLayout::LAYOUT_TYPE_PRODUCT;
    }

    public function normalizeLayoutOptionCode(string $layoutCode): string
    {
        $layoutCode = strtolower(trim($layoutCode));
        $layoutCode = preg_replace('/[^a-z0-9_-]+/', '-', $layoutCode) ?? '';
        return trim($layoutCode, '-_');
    }

    public function editableLayoutExists(string $layoutType, string $layoutCode, array $identity = []): bool
    {
        try {
            if ($this->themeQuery('virtualLayoutExists', [
                'identity' => array_merge($identity, [
                    'layout_type' => $this->normalizeEditableLayoutType($layoutType),
                    'layout_option' => $this->normalizeLayoutOptionCode($layoutCode),
                ]),
            ])) {
                return true;
            }
        } catch (\Throwable) {
        }

        $path = $this->resolveEditableLayoutPath($layoutType, $layoutCode);
        return $path !== null && is_file($path);
    }

    public function loadEditableLayoutSource(string $layoutType, string $layoutCode, array $identity = []): ?string
    {
        try {
            $source = $this->themeQuery('loadVirtualLayoutSource', [
                'identity' => array_merge($identity, [
                    'layout_type' => $this->normalizeEditableLayoutType($layoutType),
                    'layout_option' => $this->normalizeLayoutOptionCode($layoutCode),
                ]),
            ]);
            if (is_string($source) && trim($source) !== '') {
                return $source;
            }
        } catch (\Throwable) {
        }

        $path = $this->resolveEditableLayoutPath($layoutType, $layoutCode);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $source = file_get_contents($path);
        return is_string($source) ? $source : null;
    }

    public function getDefaultEditableLayoutSource(string $layoutType): string
    {
        $source = $this->loadEditableLayoutSource($this->normalizeEditableLayoutType($layoutType), 'default');
        if (is_string($source) && trim($source) !== '') {
            return $source;
        }

        $editableLayoutType = $this->normalizeEditableLayoutType($layoutType);
        $hookNamespace = $editableLayoutType === ProductLayout::LAYOUT_TYPE_CATEGORY
            ? 'WeShop_Catalog::frontend::layouts::category::products-content'
            : 'WeShop_Product::frontend::layouts::product::main-content';

        return <<<PHTML
<?php
\$meta = \$this->getData('meta') ?? [];
\$title = \$meta['title'] ?? \$this->getData('title') ?? '';
\$this->assign('meta', \$meta);
?>
<!DOCTYPE html>
<html lang="{{htmlLang}}" data-local="{{lang_local}}" data-lang="{{lang}}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <w:hook>Weline_Theme::frontend::layouts::base::head-before</w:hook>
    <w:block class="Weline\Theme\Block\Partials" area="frontend" type="head" default-option="default"/>
    <w:hook>Weline_Theme::frontend::layouts::base::head-after</w:hook>
</head>
<body>
    <w:hook>Weline_Theme::frontend::layouts::base::body-start</w:hook>
    <div class="weline-page-wrapper weshop-layout weshop-layout--custom">
        <w:block class="Weline\Theme\Block\Partials" area="frontend" type="header" default-option="default"/>
        <main class="weshop-layout__main" data-layout="{$editableLayoutType}">
            <section class="weshop-layout__hero">
                <h1><?= htmlspecialchars((string)\$title, ENT_QUOTES, 'UTF-8') ?></h1>
            </section>
            <w:slot id="content" name="内容区" multiple="true" position="content">
                <w:hook>{$hookNamespace}</w:hook>
            </w:slot>
        </main>
        <w:block class="Weline\Theme\Block\Partials" area="frontend" type="footer" default-option="default"/>
    </div>
    <w:hook>Weline_Theme::frontend::layouts::base::body-end</w:hook>
</body>
</html>
PHTML;
    }

    public function saveEditableLayoutSource(string $layoutType, string $layoutCode, string $source, ?string &$error = null, array $identity = []): bool
    {
        $error = null;
        $editableLayoutType = $this->normalizeEditableLayoutType($layoutType);
        $layoutCode = $this->normalizeLayoutOptionCode($layoutCode);
        $source = trim($source);

        if ($layoutCode === '') {
            $error = (string)__('布局代码无效');
            return false;
        }
        if ($layoutCode === 'default') {
            $error = (string)__('默认布局不能在产品布局编辑器中覆盖');
            return false;
        }
        if ($source === '') {
            $error = (string)__('布局源码不能为空');
            return false;
        }
        if ($this->containsForbiddenLayoutLogic($source)) {
            $error = (string)__('布局模板只能保存页面骨架、slot、hook 和样式，不能包含前端业务交互脚本或直接请求');
            return false;
        }

        try {
            $result = $this->themeQuery('saveVirtualLayoutSource', [
                'identity' => array_merge($identity, [
                    'layout_type' => $editableLayoutType,
                    'layout_option' => $layoutCode,
                ]),
                'source' => $source,
                'publish' => true,
                'version_data' => [
                    'reason' => (string)__('保存产品/分类虚拟布局源码'),
                ],
            ]);
            if (empty($result['success'])) {
                $error = (string)($result['message'] ?? $result['status'] ?? __('布局源码保存失败'));
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    /**
     * @param array<string,mixed> $identity
     * @return list<array<string,mixed>>
     */
    public function listEditableLayoutVersions(string $layoutType, string $layoutCode, array $identity = []): array
    {
        $editableLayoutType = $this->normalizeEditableLayoutType($layoutType);
        $layoutCode = $this->normalizeLayoutOptionCode($layoutCode);
        if ($layoutCode === '' || $layoutCode === 'default') {
            return [];
        }

        try {
            $versions = $this->themeQuery('listVirtualLayoutVersions', [
                'identity' => array_merge($identity, [
                    'layout_type' => $editableLayoutType,
                    'layout_option' => $layoutCode,
                ]),
            ]);
            return is_array($versions) ? array_values(array_filter($versions, 'is_array')) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function rollbackEditableLayoutVersion(
        string $layoutType,
        string $layoutCode,
        int $assetId,
        int $versionId,
        ?string &$error = null,
        array $identity = []
    ): bool {
        $error = null;
        $editableLayoutType = $this->normalizeEditableLayoutType($layoutType);
        $layoutCode = $this->normalizeLayoutOptionCode($layoutCode);
        if ($layoutCode === '' || $layoutCode === 'default' || $assetId <= 0 || $versionId <= 0) {
            $error = (string)__('Invalid layout version parameters');
            return false;
        }

        $identity = array_merge($identity, [
            'layout_type' => $editableLayoutType,
            'layout_option' => $layoutCode,
        ]);

        $matched = false;
        foreach ($this->listEditableLayoutVersions($editableLayoutType, $layoutCode, $identity) as $version) {
            if ((int)($version['asset_id'] ?? 0) === $assetId
                && (int)($version['version_id'] ?? 0) === $versionId
            ) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $error = (string)__('Layout version does not belong to current object');
            return false;
        }

        try {
            $result = $this->themeQuery('rollbackVirtualLayoutVersion', [
                'asset_id' => $assetId,
                'version_id' => $versionId,
                'options' => [
                    'reason' => (string)__('Rollback WeShop virtual layout version'),
                ],
            ]);
            if (empty($result['success'])) {
                $error = (string)($result['message'] ?? $result['status'] ?? __('Layout version rollback failed'));
                return false;
            }

            $this->clearVirtualLayoutIdentityCache($identity);
            return true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    /**
     * @param array<string,mixed>|null $layoutContext
     */
    public function buildLayoutRuntimeCacheIdentity(?array $layoutContext, string $fallbackLayoutType): string
    {
        if (!is_array($layoutContext)) {
            return 'default';
        }

        $layoutCode = $this->normalizeLayoutOptionCode((string)($layoutContext['layout_code'] ?? ''));
        $layoutType = $this->normalizeEditableLayoutType((string)($layoutContext['layout_type'] ?? $fallbackLayoutType));
        $entityType = trim((string)($layoutContext['entity_type'] ?? ''));
        $entityId = (int)($layoutContext['entity_id'] ?? 0);
        $identity = [
            'layout_code' => $layoutCode,
            'layout_type' => $layoutType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'source' => (string)($layoutContext['source'] ?? ''),
            'selection_version' => (int)($layoutContext['version'] ?? 0),
            'schedule_id' => (int)($layoutContext['schedule_id'] ?? 0),
        ];

        if ($layoutCode !== '' && $layoutType !== '') {
            $targets = [];
            if ($entityType !== '' && $entityId > 0) {
                $targets[] = [
                    'target_type' => $entityType,
                    'target_id' => $entityId,
                ];
            }

            try {
                $runtime = $this->themeQuery('resolveVirtualLayoutRuntime', [
                    'layout_type' => $layoutType,
                    'layout_option' => $layoutCode,
                    'theme_id' => 0,
                    'area' => self::THEME_DEFAULT_AREA,
                    'target_chain' => $targets,
                ], 'frontend');
                if (is_array($runtime)) {
                    $identity['virtual_asset_id'] = (int)($runtime['asset_id'] ?? 0);
                    $identity['virtual_version_id'] = (int)($runtime['version_id'] ?? 0);
                    $identity['virtual_module_path'] = (string)($runtime['module_path'] ?? '');
                }
            } catch (\Throwable) {
            }
        }

        return sha1((string)json_encode(
            $identity,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    public function clearProductLayoutCache(int $productId, string $layoutType): void
    {
        $this->clearEntityLayoutCache(ProductLayout::ENTITY_PRODUCT, $productId, $this->normalizeProductLayoutType($layoutType));
    }

    public function clearEntityLayoutCache(string $entityType, int $entityId, string $layoutType): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $entityType . '_' . $layoutType . '_' . $entityId;
        w_cache('product')->delete($cacheKey);
        $this->clearRuntimeLayoutCaches('weshop_product_layout_context_change');
    }

    private function clearRuntimeLayoutCaches(string $reason): void
    {
        try {
            $this->themeQuery('clearRuntimeLayoutCaches', [
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            w_log_error('Clear product layout runtime cache failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function clearVirtualLayoutIdentityCache(array $identity): void
    {
        $metadata = is_array($identity['metadata'] ?? null) ? $identity['metadata'] : [];
        $entityType = (string)($metadata['weshop_entity_type'] ?? $identity['target_type'] ?? '');
        $entityId = (int)($metadata['weshop_entity_id'] ?? $identity['target_id'] ?? 0);
        $layoutType = (string)($metadata['weshop_layout_type'] ?? $identity['layout_type'] ?? '');
        if ($entityType !== '' && $entityId > 0 && $layoutType !== '') {
            $this->clearEntityLayoutCache($entityType, $entityId, $layoutType);
        }
    }

    public function activateSchedule(ProductLayoutSchedule $schedule): bool
    {
        try {
            $schedule->setStatus(ProductLayoutSchedule::STATUS_ACTIVE)->save();
            $this->clearEntityLayoutCache($schedule->getEntityType(), $schedule->getEntityId(), $schedule->getLayoutType());

            return true;
        } catch (\Throwable $e) {
            w_log_error('Activate layout schedule failed: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deactivateSchedule(ProductLayoutSchedule $schedule): bool
    {
        try {
            if ($schedule->isRecurring()) {
                $schedule->setStatus(ProductLayoutSchedule::STATUS_PENDING);
                $cronExpression = $schedule->getCronExpression();
                if ($cronExpression !== '') {
                    $nextTime = $this->calculateNextCronTime($cronExpression);
                    if ($nextTime !== null) {
                        $schedule->setStartTime($nextTime);
                    }
                }
            } else {
                $schedule->setStatus(ProductLayoutSchedule::STATUS_COMPLETED);
            }

            $schedule->save();
            $this->clearEntityLayoutCache($schedule->getEntityType(), $schedule->getEntityId(), $schedule->getLayoutType());

            return true;
        } catch (\Throwable $e) {
            w_log_error('Deactivate layout schedule failed: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function getActiveEntityLayoutCode(string $entityType, int $entityId, string $layoutType): ?string
    {
        $context = $this->getActiveEntityLayoutContext($entityType, $entityId, $layoutType);
        return is_array($context) ? (string)$context['layout_code'] : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getActiveEntityLayoutContext(string $entityType, int $entityId, string $layoutType): ?array
    {
        $activeSchedule = method_exists($this->scheduleModel, 'getEffectiveScheduleByEntity')
            ? $this->scheduleModel->getEffectiveScheduleByEntity($entityType, $entityId, $layoutType)
            : $this->scheduleModel->getActiveScheduleByEntity($entityType, $entityId, $layoutType);
        if ($activeSchedule) {
            return [
                'layout_code' => $activeSchedule->getLayoutCode(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'layout_type' => $layoutType,
                'source' => 'schedule',
                'schedule_id' => $activeSchedule->getId(),
            ];
        }

        if ($entityType === ProductLayout::ENTITY_PRODUCT) {
            $legacySchedule = $this->scheduleModel->getActiveScheduleByProduct($entityId, $layoutType);
            if ($legacySchedule) {
                return [
                    'layout_code' => $legacySchedule->getLayoutCode(),
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'layout_type' => $layoutType,
                    'source' => 'legacy_schedule',
                    'schedule_id' => $legacySchedule->getId(),
                ];
            }
        }

        try {
            $selection = $this->themeQuery('resolveLayoutSelection', [
                'target_type' => $entityType,
                'target_id' => $entityId,
                'layout_type' => $layoutType,
            ], 'frontend');
            if (is_array($selection) && trim((string)($selection['layout_code'] ?? '')) !== '') {
                return $selection + [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'layout_type' => $layoutType,
                    'source' => $selection['source'] ?? 'theme_config',
                ];
            }
        } catch (\Throwable) {
        }

        $layout = $this->productLayoutModel->getByEntityAndType($entityType, $entityId, $layoutType);
        if ($layout) {
            return [
                'layout_code' => $layout->getLayoutCode(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'layout_type' => $layoutType,
                'source' => 'legacy_product_layout',
            ];
        }

        if ($entityType === ProductLayout::ENTITY_PRODUCT) {
            $legacyLayout = $this->productLayoutModel->getByProductAndType($entityId, $layoutType);
            if ($legacyLayout) {
                return [
                    'layout_code' => $legacyLayout->getLayoutCode(),
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'layout_type' => $layoutType,
                    'source' => 'legacy_product_layout',
                ];
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function getProductCategoryIds(int $productId, ?int $preferredCategoryId = null): array
    {
        if ($productId <= 0) {
            return [];
        }

        try {
            $categoryIds = $this->productCategoryModel()->getCategoryIdsByProductId($productId);
        } catch (\Throwable) {
            return [];
        }

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        sort($categoryIds, SORT_NUMERIC);
        if ($preferredCategoryId !== null && $preferredCategoryId > 0 && in_array($preferredCategoryId, $categoryIds, true)) {
            $categoryIds = array_values(array_unique(array_merge([$preferredCategoryId], $categoryIds)));
        }

        return $categoryIds;
    }

    private function productCategoryModel(): ProductCategory
    {
        if ($this->productCategoryModel instanceof ProductCategory) {
            return $this->productCategoryModel;
        }

        return $this->productCategoryModel = ObjectManager::getInstance(ProductCategory::class);
    }

    private function themeQuery(string $operation, array $params, string $area = 'backend'): mixed
    {
        return \w_query('theme', $operation, $params, $area);
    }

    private function normalizeProductLayoutType(string $layoutType): string
    {
        $layoutType = trim($layoutType);
        if ($layoutType === ProductLayout::LAYOUT_TYPE_PRODUCT_DETAIL) {
            return ProductLayout::LAYOUT_TYPE_PRODUCT;
        }

        return $layoutType !== '' ? $layoutType : ProductLayout::LAYOUT_TYPE_PRODUCT;
    }

    /**
     * @return list<string>
     */
    private function productLayoutTypeCandidates(string $layoutType): array
    {
        $layoutType = $this->normalizeProductLayoutType($layoutType);
        if ($layoutType === ProductLayout::LAYOUT_TYPE_PRODUCT) {
            return [
                ProductLayout::LAYOUT_TYPE_PRODUCT,
                ProductLayout::LAYOUT_TYPE_PRODUCT_DETAIL,
            ];
        }

        return [$layoutType];
    }

    /**
     * @return list<string>
     */
    private function categoryProductDefaultLayoutTypeCandidates(): array
    {
        return [
            ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT,
            ProductLayout::LAYOUT_TYPE_PRODUCT,
            ProductLayout::LAYOUT_TYPE_PRODUCT_DETAIL,
        ];
    }

    private function normalizeCategoryLayoutType(string $layoutType): string
    {
        $layoutType = trim($layoutType);
        return $layoutType !== '' ? $layoutType : ProductLayout::LAYOUT_TYPE_CATEGORY;
    }

    private function resolveEditableLayoutPath(string $layoutType, string $layoutCode, bool $createDirectory = false): ?string
    {
        $layoutType = $this->normalizeEditableLayoutType($layoutType);
        $layoutCode = $this->normalizeLayoutOptionCode($layoutCode);
        if ($layoutCode === '') {
            return null;
        }

        $directory = $this->editableLayoutDirectory($layoutType);
        if ($directory === null) {
            return null;
        }
        if (!is_dir($directory)) {
            if (!$createDirectory) {
                return null;
            }
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                return null;
            }
        }

        return $directory . DS . $layoutCode . '.phtml';
    }

    private function editableLayoutDirectory(string $layoutType): ?string
    {
        $layoutType = $this->normalizeEditableLayoutType($layoutType);
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules['Weline_Theme']['base_path'])) {
            return null;
        }

        return rtrim((string)$modules['Weline_Theme']['base_path'], DS)
            . DS . 'view'
            . DS . 'theme'
            . DS . 'frontend'
            . DS . 'layouts'
            . DS . $layoutType;
    }

    private function containsForbiddenLayoutLogic(string $source): bool
    {
        $forbiddenPatterns = [
            '/<script\b/i',
            '/\baddEventListener\s*\(/i',
            '/\bfetch\s*\(/i',
            '/\bXMLHttpRequest\b/i',
            '/\baxios\s*\./i',
            '/\bnew\s+EventSource\s*\(/i',
        ];

        foreach ($forbiddenPatterns as $pattern) {
            if (preg_match($pattern, $source)) {
                return true;
            }
        }

        return false;
    }

    private function calculateNextCronTime(string $cronExpression): ?string
    {
        $parts = preg_split('/\s+/', trim($cronExpression));
        if (count($parts) < 5) {
            return null;
        }

        $now = new \DateTime();
        $now->modify('+1 minute');

        for ($i = 0; $i < 366 * 24 * 60; $i++) {
            $now->modify('+1 minute');
            if (
                $this->cronFieldMatch((int)$now->format('i'), $parts[0]) &&
                $this->cronFieldMatch((int)$now->format('H'), $parts[1]) &&
                $this->cronFieldMatch((int)$now->format('d'), $parts[2]) &&
                $this->cronFieldMatch((int)$now->format('m'), $parts[3]) &&
                $this->cronFieldMatch((int)$now->format('w'), $parts[4])
            ) {
                return $now->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function cronFieldMatch(int $value, string $field): bool
    {
        if ($field === '*') {
            return true;
        }

        $subFields = explode(',', $field);
        foreach ($subFields as $sub) {
            if (str_contains($sub, '/')) {
                [$range, $step] = explode('/', $sub, 2);
                $step = max(1, (int)$step);
                if ($range === '*' && $value % $step === 0) {
                    return true;
                }
            } elseif (str_contains($sub, '-')) {
                [$low, $high] = explode('-', $sub, 2);
                if ($value >= (int)$low && $value <= (int)$high) {
                    return true;
                }
            } elseif ((int)$sub === $value) {
                return true;
            }
        }

        return false;
    }
}
