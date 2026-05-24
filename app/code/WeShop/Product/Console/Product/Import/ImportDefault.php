<?php

declare(strict_types=1);

namespace WeShop\Product\Console\Product\Import;

use WeShop\Catalog\Model\Category;
use WeShop\Customer\Model\Customer;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\Product\LocalDescription as ProductLocalDescription;
use WeShop\Product\Model\ProductCategory;
use WeShop\Review\Model\Review;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use PDO;

/**
 * 导入默认测试产品命令
 * 
 * 导入30个测试产品，包括简单产品和可配置产品（多规格）
 */
class ImportDefault extends CommandAbstract
{
    public const dir = 'Console\\Product\\Import';

    /** 命令别名，支持 product:import:default 调用 */
    public const ALIASES = ['product:import:default'];

    private Product $product;
    private Set $attributeSet;

    public function __construct(Product $product, Set $attributeSet)
    {
        $this->product = $product;
        $this->attributeSet = $attributeSet;
    }

    /**
     * @param array<string, mixed> $productData
     */
    private function syncLocalDescriptions(int $productId, array $productData): void
    {
        $localDescriptions = $productData['local_descriptions'] ?? [];
        if ($productId <= 0 || !is_array($localDescriptions) || $localDescriptions === []) {
            return;
        }

        $records = [];
        foreach ($localDescriptions as $localeCode => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $localeCode = trim((string)$localeCode);
            $name = trim((string)($fields['name'] ?? ''));
            $shortDescription = trim((string)($fields['short_description'] ?? ''));
            $description = trim((string)($fields['description'] ?? ''));
            if ($localeCode === '' || ($name === '' && $shortDescription === '' && $description === '')) {
                continue;
            }

            $records[] = [
                ProductLocalDescription::schema_fields_ID => $productId,
                ProductLocalDescription::schema_fields_local_code => $localeCode,
                ProductLocalDescription::schema_fields_NAME => $name !== '' ? $name : (string)($productData['name'] ?? ''),
                ProductLocalDescription::schema_fields_SHORT_DESCRIPTION => $shortDescription !== '' ? $shortDescription : (string)($productData['short_description'] ?? ''),
                ProductLocalDescription::schema_fields_DESCRIPTION => $description !== '' ? $description : (string)($productData['description'] ?? ''),
                ProductLocalDescription::schema_fields_META_NAME => trim((string)($fields['meta_name'] ?? '')) !== '' ? (string)$fields['meta_name'] : ($name !== '' ? $name : (string)($productData['name'] ?? '')),
                ProductLocalDescription::schema_fields_META_DESCRIPTION => trim((string)($fields['meta_description'] ?? '')) !== '' ? (string)$fields['meta_description'] : ($shortDescription !== '' ? $shortDescription : (string)($productData['short_description'] ?? '')),
                ProductLocalDescription::schema_fields_META_KEYWORDS => trim((string)($fields['meta_keywords'] ?? '')) !== '' ? (string)$fields['meta_keywords'] : (string)($productData['meta_keywords'] ?? ''),
            ];
        }

        if ($records === []) {
            return;
        }

        /** @var ProductLocalDescription $localDescription */
        $localDescription = ObjectManager::getInstance(ProductLocalDescription::class);
        $link = $localDescription->getQuery()->getLink();
        $table = $localDescription->getTable();
        $delete = $link->prepare("DELETE FROM {$table} WHERE product_id = ?");
        $delete->execute([$productId]);

        $columns = array_keys($records[0]);
        $columnSql = implode(', ', array_map(
            static fn(string $column): string => '"' . str_replace('"', '""', $column) . '"',
            $columns
        ));
        $placeholderSql = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $link->prepare("INSERT INTO {$table} ({$columnSql}) VALUES ({$placeholderSql})");
        foreach ($records as $record) {
            $insert->execute(array_map(
                static fn(string $column): mixed => $record[$column] ?? null,
                $columns
            ));
        }
    }

    /**
     * @var array 缓存的属性ID
     */
    private array $attributeCache = [];
    
    /**
     * @var array 缓存的属性选项ID
     */
    private array $optionCache = [];
    
    public function execute(array $args = [], array $data = []): void
    {
        $this->printer->note('开始导入默认测试产品...');

        // 确保EAV属性类型已初始化
        $this->ensureEavAttributeTypes();

        // 确保默认分类已安装
        $this->ensureDefaultCategories();

        // 获取默认属性集ID
        $setId = $this->getDefaultAttributeSetId();
        if (!$setId) {
            $this->printer->error('未找到默认属性集，请先运行产品模块的安装/升级命令');
            return;
        }
        
        // 确保筛选属性已创建
        $this->ensureFilterableAttributes($setId);

        // 删除旧的测试产品数据
        $this->deleteExistingTestProducts();

        $importedCount = 0;
        $importedProductIds = [];

        // 定义测试产品数据
        $products = $this->getTestProducts();

        foreach ($products as $productData) {
            try {

                // 创建主产品
                $product = clone $this->product;
                $product->reset()->clearData();
                $product->setName($productData['name'])
                    ->setSku($productData['sku'])
                    ->setSpu($productData['spu'])
                    ->setHandle($productData['handle'])
                    ->setShortDescription($productData['short_description'])
                    ->setDescription($productData['description'])
                    ->setPrice($productData['price'])
                    ->setCost($productData['cost'])
                    ->setStock($productData['stock'])
                    ->setWeight($productData['weight'] ?? 0.5)
                    ->setImage($productData['image'] ?? '')
                    ->setImages($productData['images'] ?? '')
                    ->setStatus(1)
                    ->setParentId(0)
                    ->setSetId($setId)
                    ->setMetaName($productData['name'])
                    ->setMetaDescription($productData['short_description'])
                    ->setMetaKeywords($productData['meta_keywords'] ?? '');

                $productId = $product->save();

                if (!$productId) {
                    $this->printer->error("创建产品 {$productData['sku']} 失败");
                    continue;
                }

                $this->printer->success("✓ 创建产品: {$productData['name']} (SKU: {$productData['sku']}, ID: {$productId})");
                $importedCount++;
                $importedProductIds[] = $productId;
                $this->syncLocalDescriptions((int)$productId, $productData);
                
                // 保存EAV属性值（颜色、尺寸、材质、品牌）
                $this->saveProductAttributes($product, $productData);

                // 关联产品分类
                if (isset($productData['category_handles']) && is_array($productData['category_handles'])) {
                    $categoryIds = $this->getCategoryIdsByHandles($productData['category_handles']);
                    if (!empty($categoryIds)) {
                        $this->associateProductCategories($productId, $categoryIds);
                        $this->printer->note("  └─ 关联分类: " . implode(', ', $productData['category_handles']) . " (分类IDs: " . implode(', ', $categoryIds) . ")");
                    } else {
                        $this->printer->warning("  └─ 未找到任何分类: " . implode(', ', $productData['category_handles']));
                    }
                }

                // 如果是可配置产品，创建子产品（变体）
                if (isset($productData['variants']) && is_array($productData['variants'])) {
                    foreach ($productData['variants'] as $variant) {
                        $variantProduct = clone $this->product;
                        $variantProduct->reset()->clearData();
                        $variantProduct->setName($variant['name'])
                            ->setSku($variant['sku'])
                            ->setSpu($productData['spu']) // 使用相同的SPU
                            ->setHandle($variant['handle'])
                            ->setShortDescription($variant['short_description'] ?? $productData['short_description'])
                            ->setDescription($variant['description'] ?? $productData['description'])
                            ->setPrice($variant['price'])
                            ->setCost($variant['cost'])
                            ->setStock($variant['stock'])
                            ->setWeight($variant['weight'] ?? $productData['weight'] ?? 0.5)
                            ->setImage($variant['image'] ?? $productData['image'] ?? '')
                            ->setImages($variant['images'] ?? $productData['images'] ?? '')
                            ->setStatus(1)
                            ->setParentId($productId)
                            ->setSetId($setId)
                            ->setMetaName($variant['name'])
                            ->setMetaDescription($variant['short_description'] ?? $productData['short_description'])
                            ->setMetaKeywords($productData['meta_keywords'] ?? '');

                        $variantId = $variantProduct->save();
                        if ($variantId) {
                            // 保存变体的EAV属性值
                            $this->saveProductAttributes($variantProduct, $variant);
                            $this->syncLocalDescriptions((int)$variantId, $variant + [
                                'description' => $productData['description'],
                                'short_description' => $productData['short_description'],
                                'meta_keywords' => $productData['meta_keywords'] ?? '',
                            ]);
                            $this->printer->note("  └─ 创建变体: {$variant['name']} (SKU: {$variant['sku']})");
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->printer->error("创建产品 {$productData['sku']} 时出错: " . $e->getMessage());
            }
        }

        $this->printer->success("\n导入完成！");
        $this->printer->note("成功导入: {$importedCount} 个产品");
        
        // 生成示例评论数据
        $this->generateSampleReviews($importedProductIds);
    }
    
    /**
     * 生成示例评论数据
     * 
     * @param array $productIds
     */
    private function generateSampleReviews(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }
        
        $this->printer->note("\n正在生成示例评论数据...");
        
        try {
            /** @var Review $reviewModel */
            $reviewModel = ObjectManager::getInstance(Review::class);
            
            // 先清除旧的评论数据
            $reviewModel->reset()
                ->where(Review::schema_fields_PRODUCT_ID, $productIds, 'in')
                ->delete()
                ->fetch();
            
            $sampleTitles = [
                '非常满意', '物超所值', '推荐购买', '质量不错', '性价比高',
                '发货很快', '包装完好', '与描述相符', '值得推荐', '回购首选',
            ];
            
            $sampleContents = [
                '产品质量很好，完全符合预期，物流也很快，下次还会再买。',
                '包装很精美，产品做工精细，非常满意这次购物体验。',
                '性价比很高，功能齐全，使用方便，推荐给大家。',
                '发货速度快，物流给力，产品质量也不错，好评！',
                '第二次购买了，一如既往的好，会继续支持。',
                '朋友推荐来买的，果然没有让我失望，很满意。',
                '做工精良，材质优秀，价格也很合理。',
                '收到货后马上就用了，效果很好，值得购买。',
            ];

            $customerIds = $this->resolveReviewCustomerIds();
            
            $totalReviews = 0;
            
            foreach ($productIds as $productId) {
                // 每个产品生成 2-8 条评论
                $reviewCount = random_int(2, 8);
                
                for ($i = 0; $i < $reviewCount; $i++) {
                    $rating = $this->getWeightedRating();
                    $createdAt = $this->buildReviewTimestamp($productId, $i);
                    
                    $review = clone $reviewModel;
                    $review->reset()->clearData()
                        ->setData(Review::schema_fields_PRODUCT_ID, $productId)
                        ->setData(Review::schema_fields_CUSTOMER_ID, $customerIds[array_rand($customerIds)])
                        ->setData(Review::schema_fields_RATING, $rating)
                        ->setData(Review::schema_fields_TITLE, $sampleTitles[array_rand($sampleTitles)])
                        ->setData(Review::schema_fields_CONTENT, $sampleContents[array_rand($sampleContents)])
                        ->setData(Review::schema_fields_STATUS, Review::STATUS_APPROVED)
                        ->setData(Review::schema_fields_CREATED_AT, $createdAt)
                        ->setData(Review::schema_fields_UPDATED_AT, $createdAt)
                        ->save();
                    $totalReviews++;
                }
            }
            
            $this->printer->success("已为 " . count($productIds) . " 个产品生成 {$totalReviews} 条评论");
            
        } catch (\Throwable $e) {
            $this->printer->warning("生成评论数据失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取加权随机评分（偏向高分）
     * 
     * 评分分布：5分40%, 4分30%, 3分15%, 2分10%, 1分5%
     */
    private function getWeightedRating(): int
    {
        $rand = random_int(1, 100);
        
        if ($rand <= 40) return 5;
        if ($rand <= 70) return 4;
        if ($rand <= 85) return 3;
        if ($rand <= 95) return 2;
        return 1;
    }

    public function tip(): string
    {
        return '导入默认测试产品（30个产品，包括简单产品和可配置产品）';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'product:import:default',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '基本用法' => 'php bin/w product:import:default',
                '说明' => '此命令会导入30个测试产品，包括：' . PHP_EOL .
                    '  - 简单产品（约20个）' . PHP_EOL .
                    '  - 可配置产品（约5个，每个带2-4个变体）' . PHP_EOL .
                    '  - 产品类型包括：电子产品、服装、家居、食品、图书等',
            ]
        );
    }

    /**
     * 确保EAV属性类型已初始化
     */
    private function ensureEavAttributeTypes(): void
    {
        try {
            /** @var Type $typeModel */
            $typeModel = ObjectManager::getInstance(Type::class);
            $count = $typeModel->reset()->select()->count();
            
            if ($count === 0) {
                $this->printer->note('检测到EAV属性类型数据为空，正在初始化...');
                
                // 加载Schema并初始化数据
                /** @var \Weline\Eav\Schema\EavAttributeTypeSchema $schema */
                $schema = ObjectManager::getInstance(\Weline\Eav\Schema\EavAttributeTypeSchema::class);
                $initialData = $schema->getInitialData();
                
                $createdCount = 0;
                foreach ($initialData as $typeData) {
                    $code = $typeData[\Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_CODE];
                    $existing = $typeModel->reset()
                        ->where(Type::schema_fields_code, $code)
                        ->find()
                        ->fetch();
                    
                    if (!$existing->getId()) {
                        $typeModel->reset()->clearData();
                        // 使用Schema的字段常量映射到Model的字段
                        $fieldMap = [
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_CODE => Type::schema_fields_code,
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_NAME => Type::schema_fields_name,
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_ELEMENT => 'element',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_MODEL_CLASS => 'model_class',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_MODEL_CLASS_DATA => 'model_class_data',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_DEFAULT_VALUE => 'default_value',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_IS_SWATCH => 'is_swatch',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_SWATCH_IMAGE => 'swatch_image',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_SWATCH_COLOR => 'swatch_color',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_SWATCH_TEXT => 'swatch_text',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_FRONTEND_ATTRS => 'frontend_attrs',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_REQUIRED => 'required',
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_FIELD_TYPE => Type::schema_fields_field_type,
                            \Weline\Eav\Schema\EavAttributeTypeSchema::FIELD_FIELD_LENGTH => 'field_length',
                        ];
                        
                        foreach ($typeData as $schemaField => $value) {
                            if (isset($fieldMap[$schemaField])) {
                                $modelField = $fieldMap[$schemaField];
                                $typeModel->setData($modelField, $value);
                            }
                        }
                        
                        $typeModel->forceCheck(true, [Type::schema_fields_code]);
                        $typeId = $typeModel->save();
                        
                        if ($typeId) {
                            $createdCount++;
                        }
                    }
                }
                
                if ($createdCount > 0) {
                    $this->printer->success("已初始化 {$createdCount} 个EAV属性类型");
                } else {
                    $this->printer->warning('EAV属性类型初始化失败，请检查数据库连接');
                }
            }
        } catch (\Exception $e) {
            $this->printer->warning('无法检查/初始化EAV属性类型: ' . $e->getMessage());
            $this->printer->note('请确保 Weline_Eav 模块已安装');
        }
    }

    /**
     * 确保默认分类已安装
     */
    private function ensureDefaultCategories(): void
    {
        try {
            /** @var Category $category */
            $category = ObjectManager::getInstance(Category::class);
            $count = $category->reset()->select()->count();
            
            if ($count === 0) {
                $this->printer->note('检测到分类数据为空，正在安装默认分类...');
                /** @var \WeShop\Catalog\Setup\InstallData $installData */
                $installData = ObjectManager::getInstance(\WeShop\Catalog\Setup\InstallData::class);
                $installData->install();
                $this->printer->success('默认分类安装完成');
            }
        } catch (\Exception $e) {
            $this->printer->warning('无法检查/安装默认分类: ' . $e->getMessage());
            $this->printer->note('请确保 WeShop_Catalog 模块已安装');
        }
    }

    /**
     * 删除已存在的测试产品数据
     * 
     * 删除所有由此命令导入的测试产品及其关联数据
     */
    private function deleteExistingTestProducts(): void
    {
        $this->printer->note('正在删除旧的测试产品数据...');
        
        try {
            // 获取所有测试产品的SKU列表
            $testProducts = $this->getTestProducts();
            $skuList = [];
            
            foreach ($testProducts as $productData) {
                $skuList[] = $productData['sku'];
                // 同时收集变体SKU
                if (isset($productData['variants']) && is_array($productData['variants'])) {
                    foreach ($productData['variants'] as $variant) {
                        $skuList[] = $variant['sku'];
                    }
                }
            }
            $skuList = array_values(array_unique(array_merge($skuList, $this->getLegacyApparelTestSkus())));
            
            if (empty($skuList)) {
                return;
            }
            
            // 获取数据库连接
            $query = $this->product->getQuery();
            $link = $query->getLink();
            
            $productTable = $this->product->getTable();
            /** @var ProductCategory $productCategory */
            $productCategory = ObjectManager::getInstance(ProductCategory::class);
            $productCategoryTable = $productCategory->getTable();
            /** @var ProductLocalDescription $localDescription */
            $localDescription = ObjectManager::getInstance(ProductLocalDescription::class);
            $localDescriptionTable = $localDescription->getTable();
            
            // 收集所有要删除的产品ID
            $productIds = [];
            foreach ($skuList as $sku) {
                $stmt = $link->prepare("SELECT product_id FROM {$productTable} WHERE sku = :sku");
                $stmt->execute([':sku' => $sku]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && isset($result['product_id'])) {
                    $productIds[] = (int)$result['product_id'];
                }
            }
            
            if (empty($productIds)) {
                $this->printer->note('没有发现需要删除的旧产品');
                return;
            }
            
            $productIdList = implode(',', $productIds);
            
            // 1. 删除产品分类关联
            $sql = "DELETE FROM {$productCategoryTable} WHERE product_id IN ({$productIdList})";
            $link->exec($sql);

            $sql = "DELETE FROM {$localDescriptionTable} WHERE product_id IN ({$productIdList})";
            $link->exec($sql);
            
            // 2. 删除子产品/变体（parent_id 在列表中的产品）
            $sql = "DELETE FROM {$productTable} WHERE parent_id IN ({$productIdList})";
            $link->exec($sql);
            
            // 3. 删除主产品
            $sql = "DELETE FROM {$productTable} WHERE product_id IN ({$productIdList})";
            $link->exec($sql);
            
            // 4. 重置产品表的序列（PostgreSQL）
            $sql = "SELECT setval(pg_get_serial_sequence('{$productTable}', 'product_id'), COALESCE((SELECT MAX(product_id) FROM {$productTable}), 0) + 1, false)";
            try {
                $link->exec($sql);
            } catch (\Exception $e) {
                // 序列重置失败不影响主流程
            }
            
            // 5. 重置产品分类关联表的序列
            $sql = "SELECT setval(pg_get_serial_sequence('{$productCategoryTable}', 'product_category_id'), COALESCE((SELECT MAX(product_category_id) FROM {$productCategoryTable}), 0) + 1, false)";
            try {
                $link->exec($sql);
            } catch (\Exception $e) {
                // 序列重置失败不影响主流程
            }
            
            $this->printer->success("已删除 " . count($productIds) . " 个旧产品");
            
        } catch (\Exception $e) {
            $this->printer->warning('删除旧产品时出错: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, int>
     */
    private function resolveReviewCustomerIds(): array
    {
        /** @var Customer $customer */
        $customer = ObjectManager::getInstance(Customer::class);
        $rows = $customer->reset()
            ->fields(Customer::schema_fields_ID)
            ->order(Customer::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $customerIds = [];
        foreach ($rows as $row) {
            $customerId = (int)($row[Customer::schema_fields_ID] ?? 0);
            if ($customerId > 0) {
                $customerIds[] = $customerId;
            }
        }

        return $customerIds !== [] ? array_values(array_unique($customerIds)) : [1];
    }

    private function buildReviewTimestamp(int $productId, int $index): string
    {
        $daysAgo = (($productId + $index * 7) % 120) + 1;
        $hoursAgo = (($productId + $index * 13) % 23);
        return date('Y-m-d H:i:s', strtotime("-{$daysAgo} days -{$hoursAgo} hours"));
    }

    /**
     * 兼容清理旧的欧美服饰测试 SKU，避免切换到汉服数据后残留旧商品。
     *
     * @return array<int, string>
     */
    private function getLegacyApparelTestSkus(): array
    {
        return [
            'NIKE-AIRMAX90-BLK-42',
            'ADIDAS-SUPERSTAR-WHT-41',
            'UNIQLO-TEE-WHT-M',
            'NIKE-DRIFIT-TEE-MASTER',
            'NIKE-DRIFIT-TEE-BLK-M',
            'NIKE-DRIFIT-TEE-BLK-L',
            'NIKE-DRIFIT-TEE-WHT-M',
            'NIKE-DRIFIT-TEE-WHT-L',
            'NIKE-DRIFIT-TEE-BLU-M',
            'LEVIS-501-MASTER',
            'LEVIS-501-DBLUE-30-32',
            'LEVIS-501-DBLUE-32-32',
            'LEVIS-501-LBLUE-30-32',
            'LEVIS-501-LBLUE-32-32',
            'ADIDAS-UB22-MASTER',
            'ADIDAS-UB22-BLK-42',
            'ADIDAS-UB22-BLK-43',
            'ADIDAS-UB22-WHT-42',
            'ADIDAS-UB22-WHT-43',
        ];
    }

    /**
     * 获取默认属性集ID
     */
    private function getDefaultAttributeSetId(): int
    {
        $eavEntityId = $this->product->getEavEntityId();
        $set = $this->attributeSet->reset()
            ->where(Set::schema_fields_code, 'default')
            ->where(Set::schema_fields_eav_entity_id, $eavEntityId)
            ->find()
            ->fetch();

        return $set->getId() ?: 0;
    }
    
    /**
     * 确保筛选属性已创建
     */
    private function ensureFilterableAttributes(int $setId): void
    {
        $this->printer->note('检查并创建筛选属性...');
        
        $eavEntityId = $this->product->getEavEntityId();
        
        // 获取默认属性组ID
        $groupId = $this->getDefaultAttributeGroupId($setId, $eavEntityId);
        if (!$groupId) {
            $this->printer->warning('未找到默认属性组，跳过属性创建');
            return;
        }
        
        // 获取属性类型ID
        $typeId = $this->getAttributeTypeId('select');
        if (!$typeId) {
            $this->printer->warning('未找到select属性类型，跳过属性创建');
            return;
        }
        
        // 获取属性定义配置
        $attributes = $this->getAttributeDefinitions();
        
        foreach ($attributes as $code => $config) {
            $this->createFilterableAttribute($code, $config, $setId, $groupId, $typeId, $eavEntityId);
        }
        
        $this->printer->success('筛选属性检查完成');
    }
    
    /**
     * 创建可筛选属性
     */
    private function createFilterableAttribute(
        string $code,
        array $config,
        int $setId,
        int $groupId,
        int $typeId,
        int $eavEntityId
    ): void {
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        
        // 检查属性是否存在
        $existing = $attributeModel->reset()
            ->where(EavAttribute::schema_fields_code, $code)
            ->where(EavAttribute::schema_fields_eav_entity_id, $eavEntityId)
            ->find()
            ->fetch();
        
        if ($existing->getId()) {
            $this->printer->note("  属性 '{$code}' 已存在，检查选项...");
            $this->attributeCache[$code] = $existing->getId();
            
            // 加载现有选项到缓存
            $this->loadAttributeOptions($existing);
            
            // 检查并创建缺失的选项
            if (!empty($config['options'])) {
                $this->ensureAttributeOptions($existing->getId(), $config['options'], $eavEntityId);
            }
            return;
        }
        
        // 创建属性
        $attributeModel->reset()->clearData();
        $attributeModel->setData(EavAttribute::schema_fields_code, $code)
            ->setData(EavAttribute::schema_fields_eav_entity_id, $eavEntityId)
            ->setData(EavAttribute::schema_fields_set_id, $setId)
            ->setData(EavAttribute::schema_fields_group_id, $groupId)
            ->setData(EavAttribute::schema_fields_type_id, $typeId)
            ->setData(EavAttribute::schema_fields_name, $config['name'])
            ->setData(EavAttribute::schema_fields_is_enable, 1)
            ->setData(EavAttribute::schema_fields_is_filterable, 1)  // 可筛选
            ->setData(EavAttribute::schema_fields_is_system, 0)
            ->setData(EavAttribute::schema_fields_has_option, 1);  // 有选项
        
        $attributeModel->forceCheck(true, [EavAttribute::schema_fields_code, EavAttribute::schema_fields_eav_entity_id]);
        
        try {
            $attributeId = $attributeModel->save();
            
            if ($attributeId) {
                $this->printer->success("  ✓ 创建属性: {$code} ({$config['name']})");
                $this->attributeCache[$code] = $attributeId;
                
                // 创建选项（选项表要求 eav_entity_id 非空）
                if (!empty($config['options'])) {
                    $this->createAttributeOptions($attributeId, $config['options'], $eavEntityId);
                }
            } else {
                $this->printer->warning("  ✗ 创建属性失败: {$code} ({$config['name']})，save() 返回 false");
            }
        } catch (\Throwable $e) {
            $this->printer->warning("  ✗ 创建属性失败: {$code} ({$config['name']})，错误: " . $e->getMessage());
        }
    }
    
    /**
     * 创建属性选项
     * @param int $eavEntityId EAV 实体 ID，m_eav_attribute_option 表必填
     */
    private function createAttributeOptions(int $attributeId, array $options, int $eavEntityId): void
    {
        /** @var Option $optionModel */
        $optionModel = ObjectManager::getInstance(Option::class);
        
        foreach ($options as $code => $optionConfig) {
            $optionModel->reset()->clearData();
            $optionModel->setData(Option::schema_fields_eav_entity_id, $eavEntityId)
                ->setData(Option::schema_fields_attribute_id, $attributeId)
                ->setData(Option::schema_fields_code, $code)
                ->setData(Option::schema_fields_value, $optionConfig['value']);
            
            // 添加色块颜色（如果有）
            if (isset($optionConfig['swatch_color'])) {
                $optionModel->setData(Option::schema_fields_swatch_color, $optionConfig['swatch_color']);
            }
            
            $optionModel->forceCheck(true, [Option::schema_fields_attribute_id, Option::schema_fields_code]);
            $optionId = $optionModel->save();
            
            if ($optionId) {
                $this->optionCache[$attributeId][$code] = $optionId;
            }
        }
    }
    
    /**
     * 确保属性选项存在（检查并创建缺失的选项）
     */
    private function ensureAttributeOptions(int $attributeId, array $options, int $eavEntityId): void
    {
        /** @var Option $optionModel */
        $optionModel = ObjectManager::getInstance(Option::class);
        
        $createdCount = 0;
        foreach ($options as $code => $optionConfig) {
            // 如果选项已在缓存中，跳过
            if (isset($this->optionCache[$attributeId][$code])) {
                continue;
            }
            
            // 检查数据库中是否存在
            $existing = $optionModel->reset()
                ->where(Option::schema_fields_attribute_id, $attributeId)
                ->where(Option::schema_fields_code, $code)
                ->find()
                ->fetch();
            
            if ($existing->getId()) {
                $this->optionCache[$attributeId][$code] = $existing->getId();
                continue;
            }
            
            // 创建新选项
            $optionModel->reset()->clearData();
            $optionModel->setData(Option::schema_fields_eav_entity_id, $eavEntityId)
                ->setData(Option::schema_fields_attribute_id, $attributeId)
                ->setData(Option::schema_fields_code, $code)
                ->setData(Option::schema_fields_value, $optionConfig['value']);
            
            if (isset($optionConfig['swatch_color'])) {
                $optionModel->setData(Option::schema_fields_swatch_color, $optionConfig['swatch_color']);
            }
            
            $optionModel->forceCheck(true, [Option::schema_fields_attribute_id, Option::schema_fields_code]);
            $optionId = $optionModel->save();
            
            if ($optionId) {
                $this->optionCache[$attributeId][$code] = $optionId;
                $createdCount++;
            }
        }
        
        if ($createdCount > 0) {
            $this->printer->success("    ✓ 创建了 {$createdCount} 个缺失的选项");
        }
    }
    
    /**
     * 加载属性选项到缓存
     */
    private function loadAttributeOptions(EavAttribute $attribute): void
    {
        $attributeId = $attribute->getId();
        
        /** @var Option $optionModel */
        $optionModel = ObjectManager::getInstance(Option::class);
        $options = $optionModel->reset()
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->select()
            ->fetchArray();
        
        foreach ($options as $option) {
            $this->optionCache[$attributeId][$option['code']] = $option['option_id'];
        }
    }
    
    /**
     * 保存产品的EAV属性值
     */
    private function saveProductAttributes(Product $product, array $productData): void
    {
        $productId = $product->getId();
        if (!$productId) {
            return;
        }
        
        // 保存颜色属性
        if (isset($productData['color'])) {
            $this->saveAttributeValue($product, 'color', $productData['color']);
        }
        
        // 保存尺寸属性
        if (isset($productData['size'])) {
            $this->saveAttributeValue($product, 'size', $productData['size']);
        }
        
        // 保存材质属性
        if (isset($productData['material'])) {
            $this->saveAttributeValue($product, 'material', $productData['material']);
        }
        
        // 保存品牌属性
        if (isset($productData['brand'])) {
            $this->saveAttributeValue($product, 'brand', $productData['brand']);
        }
    }
    
    /**
     * 保存单个属性值
     */
    private function saveAttributeValue(Product $product, string $attributeCode, string $optionCode): void
    {
        // 如果属性不在缓存中，尝试加载或创建
        $attributeId = $this->attributeCache[$attributeCode] ?? null;
        if (!$attributeId) {
            $attributeId = $this->ensureAttribute($product, $attributeCode);
            if (!$attributeId) {
                $this->printer->warning("  无法创建或加载属性 {$attributeCode}");
                return;
            }
        }
        
        // 如果选项不在缓存中，尝试加载或创建
        $optionId = $this->optionCache[$attributeId][$optionCode] ?? null;
        if (!$optionId) {
            $optionId = $this->ensureAttributeOption($product, $attributeId, $attributeCode, $optionCode);
            if (!$optionId) {
                $this->printer->warning("  无法创建或加载选项 {$optionCode} (属性: {$attributeCode})");
                return;
            }
        }
        
        $productId = $product->getId();
        if (!$productId) {
            return;
        }
        
        try {
            // 获取属性类型代码（用于确定值表名）
            /** @var EavAttribute $attribute */
            $attribute = ObjectManager::getInstance(EavAttribute::class);
            $attribute->reset()
                ->where(EavAttribute::schema_fields_code, $attributeCode)
                ->where(EavAttribute::schema_fields_eav_entity_id, $product->getEavEntityId())
                ->find()
                ->fetch();
            
            if (!$attribute->getId()) {
                $this->printer->warning("  无法获取属性 {$attributeCode}");
                return;
            }
            
            // 获取属性类型代码
            $typeModel = $attribute->getTypeModel();
            $typeCode = $typeModel->getCode();
            $entityCode = $product->getEntityCode();
            
            // 直接构建值表名: m_eav_{entity}_{type}
            $valueTable = 'm_eav_' . $entityCode . '_' . $typeCode;
            
            // 使用产品模型的数据库连接获取 PDO
            $pdo = $product->getConnection()->getConnector()->getLink();
            
            // 先删除旧值
            $deleteSql = "DELETE FROM \"{$valueTable}\" WHERE attribute_id = :attribute_id AND entity_id = :entity_id";
            $deleteStmt = $pdo->prepare($deleteSql);
            $deleteStmt->execute([
                ':attribute_id' => $attribute->getId(),
                ':entity_id' => $productId,
            ]);
            
            // 插入新值
            $insertSql = "INSERT INTO \"{$valueTable}\" (attribute_id, entity_id, value) VALUES (:attribute_id, :entity_id, :value)";
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                ':attribute_id' => $attribute->getId(),
                ':entity_id' => $productId,
                ':value' => (string)$optionId,
            ]);
        } catch (\Throwable $e) {
            $this->printer->warning("  保存属性值失败: {$attributeCode} = {$optionCode}, 错误: " . $e->getMessage());
        }
    }
    
    /**
     * 确保属性存在（如果不存在则创建）
     * 
     * @param Product $product 产品模型
     * @param string $attributeCode 属性代码
     * @return int|null 属性ID，失败返回null
     */
    private function ensureAttribute(Product $product, string $attributeCode): ?int
    {
        $eavEntityId = $product->getEavEntityId();
        
        // 先尝试从数据库加载
        /** @var EavAttribute $attributeModel */
        $attributeModel = ObjectManager::getInstance(EavAttribute::class);
        $existing = $attributeModel->reset()
            ->where(EavAttribute::schema_fields_code, $attributeCode)
            ->where(EavAttribute::schema_fields_eav_entity_id, $eavEntityId)
            ->find()
            ->fetch();
        
        if ($existing->getId()) {
            $this->attributeCache[$attributeCode] = $existing->getId();
            // 加载选项到缓存
            $this->loadAttributeOptions($existing);
            return $existing->getId();
        }
        
        // 如果不存在，尝试创建
        $attributeConfig = $this->getAttributeConfig($attributeCode);
        if (!$attributeConfig) {
            // 如果没有预定义配置，使用默认配置创建
            $attributeConfig = [
                'name' => ucfirst($attributeCode),
                'options' => [],
            ];
        }
        
        $setId = $product->getSetId();
        if (!$setId) {
            $setId = $this->getDefaultAttributeSetId();
        }
        
        if (!$setId) {
            $this->printer->warning("  无法获取属性集ID，无法创建属性 {$attributeCode}");
            return null;
        }
        
        $groupId = $this->getDefaultAttributeGroupId($setId, $eavEntityId);
        if (!$groupId) {
            $this->printer->warning("  无法获取属性组ID (setId: {$setId}, eavEntityId: {$eavEntityId})，无法创建属性 {$attributeCode}");
            return null;
        }
        
        $typeId = $this->getAttributeTypeId('select');
        if (!$typeId) {
            $this->printer->warning("  无法获取select属性类型ID（尝试了: select, select_option, select_yes_no, select_option_multiple, input_string），无法创建属性 {$attributeCode}");
            return null;
        }
        
        // 创建属性
        $this->createFilterableAttribute($attributeCode, $attributeConfig, $setId, $groupId, $typeId, $eavEntityId);
        
        // 再次检查缓存，如果还是没有，说明创建失败
        if (!isset($this->attributeCache[$attributeCode])) {
            // 尝试再次从数据库加载，可能创建成功了但缓存没设置
            $created = $attributeModel->reset()
                ->where(EavAttribute::schema_fields_code, $attributeCode)
                ->where(EavAttribute::schema_fields_eav_entity_id, $eavEntityId)
                ->find()
                ->fetch();
            
            if ($created->getId()) {
                $this->attributeCache[$attributeCode] = $created->getId();
                $this->loadAttributeOptions($created);
                return $created->getId();
            }
            
            $this->printer->warning("  属性 {$attributeCode} 创建失败，请检查数据库连接和权限");
            return null;
        }
        
        return $this->attributeCache[$attributeCode];
    }
    
    /**
     * 确保属性选项存在（如果不存在则创建）
     * 
     * @param Product $product 产品模型
     * @param int $attributeId 属性ID
     * @param string $attributeCode 属性代码
     * @param string $optionCode 选项代码
     * @return int|null 选项ID，失败返回null
     */
    private function ensureAttributeOption(Product $product, int $attributeId, string $attributeCode, string $optionCode): ?int
    {
        $eavEntityId = $product->getEavEntityId();
        
        // 先尝试从数据库加载
        /** @var Option $optionModel */
        $optionModel = ObjectManager::getInstance(Option::class);
        $existing = $optionModel->reset()
            ->where(Option::schema_fields_attribute_id, $attributeId)
            ->where(Option::schema_fields_code, $optionCode)
            ->find()
            ->fetch();
        
        if ($existing->getId()) {
            $this->optionCache[$attributeId][$optionCode] = $existing->getId();
            return $existing->getId();
        }
        
        // 如果不存在，创建选项
        $attributeConfig = $this->getAttributeConfig($attributeCode);
        $optionValue = null;
        $swatchColor = null;
        
        if ($attributeConfig && isset($attributeConfig['options'][$optionCode])) {
            $optionValue = $attributeConfig['options'][$optionCode]['value'];
            $swatchColor = $attributeConfig['options'][$optionCode]['swatch_color'] ?? null;
        } else {
            // 如果没有预定义配置，使用选项代码作为显示值
            $optionValue = ucfirst(str_replace('_', ' ', $optionCode));
        }
        
        // 创建选项
        $optionModel->reset()->clearData();
        $optionModel->setData(Option::schema_fields_eav_entity_id, $eavEntityId)
            ->setData(Option::schema_fields_attribute_id, $attributeId)
            ->setData(Option::schema_fields_code, $optionCode)
            ->setData(Option::schema_fields_value, $optionValue);
        
        if ($swatchColor) {
            $optionModel->setData(Option::schema_fields_swatch_color, $swatchColor);
        }
        
        $optionModel->forceCheck(true, [Option::schema_fields_attribute_id, Option::schema_fields_code]);
        $optionId = $optionModel->save();
        
        if ($optionId) {
            $this->optionCache[$attributeId][$optionCode] = $optionId;
            $this->printer->note("    ✓ 创建选项: {$attributeCode}.{$optionCode} = {$optionValue}");
            return $optionId;
        }
        
        return null;
    }
    
    /**
     * 获取属性配置
     * 
     * @param string $attributeCode 属性代码
     * @return array|null 属性配置，不存在返回null
     */
    private function getAttributeConfig(string $attributeCode): ?array
    {
        $attributes = $this->getAttributeDefinitions();
        return $attributes[$attributeCode] ?? null;
    }
    
    /**
     * 获取属性定义配置
     * 
     * @return array
     */
    private function getAttributeDefinitions(): array
    {
        return [
            'color' => [
                'name' => '颜色',
                'options' => [
                    'black' => ['value' => '黑色', 'swatch_color' => '#000000'],
                    'white' => ['value' => '白色', 'swatch_color' => '#FFFFFF'],
                    'blue' => ['value' => '蓝色', 'swatch_color' => '#0066CC'],
                    'red' => ['value' => '红色', 'swatch_color' => '#CC0000'],
                    'green' => ['value' => '绿色', 'swatch_color' => '#00CC00'],
                    'gray' => ['value' => '灰色', 'swatch_color' => '#808080'],
                    'beige' => ['value' => '米杏色', 'swatch_color' => '#D8C3A5'],
                    'dark_blue' => ['value' => '深蓝色', 'swatch_color' => '#003366'],
                    'light_blue' => ['value' => '浅蓝色', 'swatch_color' => '#66CCFF'],
                    'starlight' => ['value' => '星光色', 'swatch_color' => '#F5F5DC'],
                    'midnight' => ['value' => '午夜色', 'swatch_color' => '#191970'],
                ],
            ],
            'size' => [
                'name' => '尺寸',
                'options' => [
                    'xs' => ['value' => 'XS'],
                    's' => ['value' => 'S'],
                    'm' => ['value' => 'M'],
                    'l' => ['value' => 'L'],
                    'xl' => ['value' => 'XL'],
                    'xxl' => ['value' => 'XXL'],
                    '30' => ['value' => '30'],
                    '32' => ['value' => '32'],
                    '34' => ['value' => '34'],
                    '36' => ['value' => '36'],
                    '38' => ['value' => '38'],
                    '39' => ['value' => '39'],
                    '40' => ['value' => '40'],
                    '41' => ['value' => '41'],
                    '42' => ['value' => '42'],
                    '43' => ['value' => '43'],
                    '44' => ['value' => '44'],
                    '45' => ['value' => '45'],
                    '41mm' => ['value' => '41mm'],
                    '45mm' => ['value' => '45mm'],
                ],
            ],
            'material' => [
                'name' => '材质',
                'options' => [
                    'cotton' => ['value' => '纯棉'],
                    'polyester' => ['value' => '涤纶'],
                    'leather' => ['value' => '皮革'],
                    'denim' => ['value' => '牛仔布'],
                    'nylon' => ['value' => '尼龙'],
                    'wool' => ['value' => '羊毛'],
                    'silk' => ['value' => '丝绸'],
                    'metal' => ['value' => '金属'],
                    'plastic' => ['value' => '塑料'],
                    'titanium' => ['value' => '钛金属'],
                    'aluminum' => ['value' => '铝合金'],
                    'brocade' => ['value' => '织锦'],
                ],
            ],
            'brand' => [
                'name' => '品牌',
                'options' => [
                    'apple' => ['value' => 'Apple'],
                    'samsung' => ['value' => 'Samsung'],
                    'nike' => ['value' => 'Nike'],
                    'adidas' => ['value' => 'Adidas'],
                    'sony' => ['value' => 'Sony'],
                    'bose' => ['value' => 'Bose'],
                    'canon' => ['value' => 'Canon'],
                    'levis' => ['value' => 'Levi\'s'],
                    'uniqlo' => ['value' => 'Uniqlo'],
                    'ikea' => ['value' => 'IKEA'],
                    'muji' => ['value' => 'MUJI'],
                    'dyson' => ['value' => 'Dyson'],
                    'logitech' => ['value' => 'Logitech'],
                    'osprey' => ['value' => 'Osprey'],
                    'herman_miller' => ['value' => 'Herman Miller'],
                    'lego' => ['value' => 'LEGO'],
                    'tesla' => ['value' => 'Tesla'],
                    'lindt' => ['value' => 'Lindt'],
                    'nespresso' => ['value' => 'Nespresso'],
                    'hua_chao' => ['value' => '华裳九州'],
                    'yun_jin_studio' => ['value' => '云锦司'],
                    'jin_xiu_ge' => ['value' => '锦绣阁'],
                ],
            ],
        ];
    }
    
    /**
     * 获取默认属性组ID
     */
    private function getDefaultAttributeGroupId(int $setId, int $eavEntityId): int
    {
        /** @var \Weline\Eav\Model\EavAttribute\Group $groupModel */
        $groupModel = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Group::class);
        $group = $groupModel->reset()
            ->where(\Weline\Eav\Model\EavAttribute\Group::schema_fields_code, 'default')
            ->where(\Weline\Eav\Model\EavAttribute\Group::schema_fields_set_id, $setId)
            ->where(\Weline\Eav\Model\EavAttribute\Group::schema_fields_eav_entity_id, $eavEntityId)
            ->find()
            ->fetch();
        
        return $group->getId() ?: 0;
    }
    
    /**
     * 获取属性类型ID
     */
    private function getAttributeTypeId(string $typeCode): int
    {
        /** @var Type $typeModel */
        $typeModel = ObjectManager::getInstance(Type::class);
        $type = $typeModel->reset()
            ->where(Type::schema_fields_code, $typeCode)
            ->find()
            ->fetch();
        
        if ($type->getId()) {
            return $type->getId();
        }
        
        // 如果直接查找失败，尝试常见的类型代码映射
        $typeCodeMap = [
            'select' => ['select_option', 'select_yes_no', 'select_option_multiple'],
            'multiselect' => ['select_option_multiple', 'select_option'],
            'text' => ['input_string', 'input_string_255', 'input_string_60'],
        ];
        
        if (isset($typeCodeMap[$typeCode])) {
            foreach ($typeCodeMap[$typeCode] as $fallbackCode) {
                $type = $typeModel->reset()
                    ->where(Type::schema_fields_code, $fallbackCode)
                    ->find()
                    ->fetch();
                
                if ($type->getId()) {
                    return $type->getId();
                }
            }
        }
        
        // 最后尝试使用input_string作为兜底
        $type = $typeModel->reset()
            ->where(Type::schema_fields_code, 'input_string')
            ->find()
            ->fetch();
        
        // 如果所有类型都找不到，输出调试信息：列出数据库中所有可用的类型
        if (!$type->getId()) {
            static $debugShown = false;
            if (!$debugShown) {
                $allTypes = $typeModel->reset()
                    ->select()
                    ->fetchArray();
                
                $this->printer->warning("  调试信息：数据库中可用的属性类型：");
                if (empty($allTypes)) {
                    $this->printer->warning("    数据库中没有找到任何属性类型！请先运行EAV模块的安装/升级命令。");
                } else {
                    foreach ($allTypes as $t) {
                        $this->printer->note("    - {$t['code']} ({$t['name']}) [ID: {$t['type_id']}]");
                    }
                }
                $debugShown = true;
            }
        }
        
        return $type->getId() ?: 0;
    }

    /**
     * 根据分类Handle数组获取分类ID数组
     * 
     * @param array $handles 分类Handle数组
     * @return array 分类ID数组
     */
    private function getCategoryIdsByHandles(array $handles): array
    {
        $categoryIds = [];
        
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        foreach ($handles as $handle) {
            // 从完整路径中提取最后一部分作为handle
            $handleParts = explode('/', $handle);
            $finalHandle = end($handleParts);
            
            // 查找分类（支持完整路径或最后一部分）
            $found = $category->reset()
                ->where(Category::schema_fields_HANDLE, $finalHandle)
                ->where(Category::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($found->getId()) {
                $categoryIds[] = (int)$found->getId();
            } else {
                // 如果没找到，尝试通过完整路径查找（递归查找父分类）
                $categoryId = $this->findCategoryByPath($handle);
                if ($categoryId) {
                    $categoryIds[] = $categoryId;
                } else {
                    $this->printer->warning("  └─ 未找到分类: {$handle}");
                }
            }
        }
        
        return array_unique($categoryIds);
    }

    /**
     * 根据分类路径查找分类ID（支持多级路径）
     * 
     * @param string $path 分类路径，如 'electronics/phones/smartphones'
     * @return int|null 分类ID
     */
    private function findCategoryByPath(string $path): ?int
    {
        $parts = explode('/', $path);
        $parentId = 0;
        
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        foreach ($parts as $handle) {
            $found = $category->reset()
                ->where(Category::schema_fields_HANDLE, $handle)
                ->where(Category::schema_fields_PARENT_ID, $parentId)
                ->where(Category::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if (!$found->getId()) {
                return null;
            }
            
            $parentId = (int)$found->getId();
        }
        
        return $parentId;
    }

    /**
     * 关联产品分类
     * 
     * @param int $productId 产品ID
     * @param array $categoryIds 分类ID数组
     */
    private function associateProductCategories(int $productId, array $categoryIds): void
    {
        try {
            /** @var ProductCategory $productCategory */
            $productCategory = ObjectManager::getInstance(ProductCategory::class);
            
            // 先删除旧关联
            $productCategory->reset()
                ->where(ProductCategory::schema_fields_product_id, $productId)
                ->delete()
                ->fetch();
            
            // 使用 PostgreSQL 的 RETURNING 子句插入，确保序列正确工作
            $tableName = $productCategory->getTable();
            $query = $productCategory->getQuery();
            $link = $query->getLink();
            
            foreach ($categoryIds as $categoryId) {
                // 使用 RETURNING 子句获取生成的 ID
                $sql = "INSERT INTO {$tableName} (product_id, category_id) VALUES (:product_id, :category_id) RETURNING product_category_id";
                $stmt = $link->prepare($sql);
                $stmt->execute([
                    ':product_id' => $productId,
                    ':category_id' => $categoryId
                ]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (\Exception $e) {
            $this->printer->warning("  └─ 关联分类失败: " . $e->getMessage());
        }
    }

    /**
     * 获取测试产品数据
     * 
     * @return array
     */
    private function getTestProducts(): array
    {
        return [
            // ========== 简单产品（Simple Products）==========
            
            // 电子产品 - 智能手机
            [
                'name' => 'iPhone 15 Pro Max 256GB',
                'sku' => 'IPHONE-15-PRO-MAX-256',
                'spu' => 'IPHONE-15-PRO-MAX',
                'handle' => 'iphone-15-pro-max-256gb',
                'short_description' => 'Apple iPhone 15 Pro Max，256GB存储，钛金属材质，A17 Pro芯片',
                'description' => 'Apple iPhone 15 Pro Max 是一款功能强大的智能手机，配备6.7英寸Super Retina XDR显示屏，A17 Pro芯片提供卓越性能，支持5G网络，拥有出色的相机系统和全天候电池续航。',
                'price' => 9999.00,
                'cost' => 7500.00,
                'stock' => 50,
                'weight' => 0.221,
                'meta_keywords' => 'iPhone,苹果,智能手机,5G',
                'category_handles' => ['smartphones', '5g-phones'],
                'brand' => 'apple',
                'material' => 'titanium',
            ],
            // 电子产品 - 笔记本电脑
            [
                'name' => 'MacBook Pro 14英寸 M3',
                'sku' => 'MBP-14-M3-512',
                'spu' => 'MBP-14-M3',
                'handle' => 'macbook-pro-14-m3-512gb',
                'short_description' => 'Apple MacBook Pro 14英寸，M3芯片，512GB SSD，16GB统一内存',
                'description' => 'MacBook Pro 14英寸配备强大的M3芯片，提供卓越的性能和能效。14.2英寸Liquid Retina XDR显示屏，支持P3广色域和True Tone技术，适合专业创作和日常办公。',
                'price' => 14999.00,
                'cost' => 12000.00,
                'stock' => 30,
                'weight' => 1.6,
                'meta_keywords' => 'MacBook,苹果笔记本,笔记本电脑,M3',
                'category_handles' => ['laptops'],
                'brand' => 'apple',
                'color' => 'gray',
                'material' => 'aluminum',
            ],
            // 电子产品 - 无线耳机
            [
                'name' => 'AirPods Pro 第二代',
                'sku' => 'AIRPODS-PRO-2',
                'spu' => 'AIRPODS-PRO-2',
                'handle' => 'airpods-pro-2nd-generation',
                'short_description' => 'Apple AirPods Pro 第二代，主动降噪，空间音频，MagSafe充电盒',
                'description' => 'AirPods Pro 第二代提供卓越的主动降噪和自适应通透模式，支持空间音频和动态头部追踪，MagSafe充电盒支持无线充电。',
                'price' => 1899.00,
                'cost' => 1200.00,
                'stock' => 100,
                'weight' => 0.056,
                'meta_keywords' => 'AirPods,无线耳机,降噪耳机,苹果',
                'category_handles' => ['wireless-headphones', 'earbuds'],
                'brand' => 'apple',
                'color' => 'white',
                'material' => 'plastic',
            ],
            // 电子产品 - 智能手机
            [
                'name' => 'Samsung Galaxy S24 Ultra',
                'sku' => 'SAMSUNG-S24-ULTRA-256',
                'spu' => 'SAMSUNG-S24-ULTRA',
                'handle' => 'samsung-galaxy-s24-ultra-256gb',
                'short_description' => 'Samsung Galaxy S24 Ultra，256GB，S Pen支持，200MP相机',
                'description' => 'Galaxy S24 Ultra配备6.8英寸Dynamic AMOLED 2X显示屏，Snapdragon 8 Gen 3处理器，200MP主摄像头，内置S Pen，支持5G网络。',
                'price' => 8999.00,
                'cost' => 6800.00,
                'stock' => 40,
                'weight' => 0.233,
                'meta_keywords' => 'Samsung,三星,智能手机,Galaxy',
                'category_handles' => ['smartphones', '5g-phones'],
                'brand' => 'samsung',
                'color' => 'black',
                'material' => 'titanium',
            ],
            // 电子产品 - 无线耳机
            [
                'name' => 'Sony WH-1000XM5 无线降噪耳机',
                'sku' => 'SONY-WH1000XM5',
                'spu' => 'SONY-WH1000XM5',
                'handle' => 'sony-wh1000xm5-headphones',
                'short_description' => 'Sony WH-1000XM5 头戴式无线降噪耳机，30小时续航，LDAC支持',
                'description' => 'Sony WH-1000XM5提供业界领先的降噪技术，支持LDAC高解析度音频，30小时电池续航，快速充电功能。',
                'price' => 2999.00,
                'cost' => 2000.00,
                'stock' => 60,
                'weight' => 0.25,
                'meta_keywords' => 'Sony,索尼,降噪耳机,无线耳机',
                'category_handles' => ['wireless-headphones'],
                'brand' => 'sony',
                'color' => 'black',
                'material' => 'plastic',
            ],

            // 汉服类 - 绣花布鞋
            [
                'name' => '云纹绣花布鞋',
                'sku' => 'HANFU-BUXIE-RED-42',
                'spu' => 'HANFU-BUXIE',
                'handle' => 'hanfu-yunwen-buxie-red-42',
                'short_description' => '传统云纹绣花布鞋，朱砂红，42码，适合汉服日常搭配',
                'description' => '云纹绣花布鞋采用软底与包边设计，保留传统汉服鞋履的轻便与雅致，适合搭配上襦、马面裙等国风造型。',
                'price' => 269.00,
                'cost' => 138.00,
                'stock' => 80,
                'weight' => 0.45,
                'image' => 'https://images.pexels.com/photos/36311713/pexels-photo-36311713.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => '汉服,布鞋,绣花鞋,国风',
                'category_handles' => ['sneakers'],
                'brand' => 'jin_xiu_ge',
                'color' => 'red',
                'size' => '42',
                'material' => 'cotton',
            ],
            // 汉服类 - 传统鞋履
            [
                'name' => '海棠履绣花鞋',
                'sku' => 'HANFU-HAITANGLV-BEI-41',
                'spu' => 'HANFU-HAITANGLV',
                'handle' => 'hanfu-haitanglv-beige-41',
                'short_description' => '海棠纹绣花汉服鞋，米杏色，41码，轻便柔软',
                'description' => '海棠履以绣花鞋面和柔软布底呈现传统气质，适合作为汉服鞋履示例商品，展示鞋类规格与国风搭配效果。',
                'price' => 289.00,
                'cost' => 146.00,
                'stock' => 90,
                'weight' => 0.42,
                'image' => 'https://images.pexels.com/photos/35222104/pexels-photo-35222104.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => '汉服,绣花鞋,布鞋,传统鞋履',
                'category_handles' => ['sneakers'],
                'brand' => 'jin_xiu_ge',
                'color' => 'beige',
                'size' => '41',
                'material' => 'cotton',
            ],
            // 汉服类 - 上装
            [
                'name' => '月白交领上襦',
                'sku' => 'HANFU-JIAOLING-WHT-M',
                'spu' => 'HANFU-JIAOLING',
                'handle' => 'hanfu-jiaoling-ruao-white-m',
                'short_description' => '月白色交领上襦，M码，轻薄真丝感面料，适合汉服叠穿',
                'description' => '月白交领上襦以传统交领右衽结构和飘逸袖型呈现汉服上装效果，适合作为汉服服饰示例商品，替代基础 T 恤类假数据。',
                'price' => 239.00,
                'cost' => 118.00,
                'stock' => 200,
                'weight' => 0.28,
                'image' => 'https://images.pexels.com/photos/8152155/pexels-photo-8152155.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => '汉服,上襦,交领,国风上装',
                'category_handles' => ['t-shirts'],
                'brand' => 'hua_chao',
                'color' => 'white',
                'size' => 'm',
                'material' => 'silk',
            ],

            // 家居用品 - 家具/床具
            [
                'name' => 'IKEA 宜家 MALM 床架',
                'sku' => 'IKEA-MALM-BED-QUEEN',
                'spu' => 'IKEA-MALM-BED',
                'handle' => 'ikea-malm-bed-queen',
                'short_description' => 'IKEA MALM 床架，Queen尺寸（160x200cm），白色，带储物抽屉',
                'description' => 'IKEA MALM床架设计简洁现代，Queen尺寸适合双人使用，床下带储物抽屉，节省空间。',
                'price' => 1999.00,
                'cost' => 1200.00,
                'stock' => 25,
                'weight' => 45.0,
                'meta_keywords' => 'IKEA,宜家,床架,家具',
                'category_handles' => ['beds'],
                'brand' => 'ikea',
                'color' => 'white',
            ],
            // 家居用品 - 装饰用品
            [
                'name' => 'MUJI 无印良品 香薰机',
                'sku' => 'MUJI-AROMATHERAPY-DIFFUSER',
                'spu' => 'MUJI-AROMA-DIFFUSER',
                'handle' => 'muji-aromatherapy-diffuser',
                'short_description' => 'MUJI 无印良品 超声波香薰机，LED夜灯，定时功能',
                'description' => 'MUJI超声波香薰机采用超声波技术，可添加精油进行香薰，内置LED夜灯，支持定时功能，营造舒适环境。',
                'price' => 399.00,
                'cost' => 200.00,
                'stock' => 50,
                'weight' => 0.8,
                'meta_keywords' => 'MUJI,无印良品,香薰机,加湿器',
                'category_handles' => ['decor'],
                'brand' => 'muji',
                'color' => 'white',
                'material' => 'plastic',
                'local_descriptions' => [
                    'zh_Hans_CN' => [
                        'name' => 'MUJI 无印良品 香薰机',
                        'short_description' => 'MUJI 无印良品超声波香薰机，配备 LED 夜灯和定时功能',
                        'description' => 'MUJI 超声波香薰机可搭配精油使用，内置 LED 夜灯并支持定时功能，适合卧室、书房和客厅营造舒适氛围。',
                        'meta_name' => 'MUJI 无印良品 香薰机',
                        'meta_description' => 'MUJI 无印良品超声波香薰机，配备 LED 夜灯和定时功能',
                        'meta_keywords' => 'MUJI,无印良品,香薰机,加湿器',
                    ],
                    'en_US' => [
                        'name' => 'MUJI Aromatherapy Diffuser',
                        'short_description' => 'MUJI ultrasonic aromatherapy diffuser with LED night light and timer.',
                        'description' => 'A MUJI ultrasonic aromatherapy diffuser for essential oils, featuring a soft LED night light and timer modes for bedrooms, studies, and living spaces.',
                        'meta_name' => 'MUJI Aromatherapy Diffuser',
                        'meta_description' => 'MUJI ultrasonic aromatherapy diffuser with LED night light and timer.',
                        'meta_keywords' => 'MUJI,aromatherapy diffuser,ultrasonic diffuser,humidifier',
                    ],
                ],
            ],
            // 家居用品 - 厨房小家电
            [
                'name' => 'Dyson V15 Detect 无线吸尘器',
                'sku' => 'DYSON-V15-DETECT',
                'spu' => 'DYSON-V15',
                'handle' => 'dyson-v15-detect-vacuum',
                'short_description' => 'Dyson V15 Detect 无线吸尘器，激光探测，60分钟续航',
                'description' => 'Dyson V15 Detect配备激光探测技术，可显示灰尘大小和数量，60分钟电池续航，适合家庭清洁。',
                'price' => 4999.00,
                'cost' => 3500.00,
                'stock' => 20,
                'weight' => 3.2,
                'meta_keywords' => 'Dyson,戴森,吸尘器,无线',
                'category_handles' => ['small-appliances'],
                'brand' => 'dyson',
                'color' => 'gray',
                'material' => 'plastic',
            ],

            // 食品类
            [
                'name' => 'Nespresso 咖啡胶囊 混合装',
                'sku' => 'NESPRESSO-CAPSULES-MIX-50',
                'spu' => 'NESPRESSO-CAPSULES',
                'handle' => 'nespresso-capsules-mix-50',
                'short_description' => 'Nespresso 咖啡胶囊混合装，50粒装，多种口味',
                'description' => 'Nespresso咖啡胶囊混合装包含多种经典口味，50粒装，适合Nespresso咖啡机使用。',
                'price' => 299.00,
                'cost' => 150.00,
                'stock' => 150,
                'weight' => 0.5,
                'meta_keywords' => 'Nespresso,咖啡,胶囊咖啡',
                'category_handles' => ['food'],
                'brand' => 'nespresso',
            ],
            // 食品类
            [
                'name' => 'Lindt 瑞士莲 巧克力礼盒',
                'sku' => 'LINDT-CHOCOLATE-BOX-500G',
                'spu' => 'LINDT-CHOCOLATE',
                'handle' => 'lindt-chocolate-box-500g',
                'short_description' => 'Lindt 瑞士莲 精选巧克力礼盒，500g，多种口味',
                'description' => 'Lindt瑞士莲精选巧克力礼盒，包含多种经典口味，500g装，适合送礼或自用。',
                'price' => 199.00,
                'cost' => 100.00,
                'stock' => 100,
                'weight' => 0.6,
                'meta_keywords' => 'Lindt,瑞士莲,巧克力,礼盒',
                'category_handles' => ['food'],
                'brand' => 'lindt',
            ],

            // 图书类
            [
                'name' => '《深入理解计算机系统》第三版',
                'sku' => 'BOOK-CSAPP-3RD',
                'spu' => 'BOOK-CSAPP',
                'handle' => 'book-computer-systems-3rd',
                'short_description' => '《深入理解计算机系统》第三版，计算机科学经典教材',
                'description' => '《深入理解计算机系统》是计算机科学领域的经典教材，第三版更新了内容，适合计算机专业学生和工程师阅读。',
                'price' => 139.00,
                'cost' => 80.00,
                'stock' => 60,
                'weight' => 1.2,
                'meta_keywords' => '计算机,教材,编程,系统',
                'category_handles' => ['books'],
            ],
            // 图书类
            [
                'name' => '《设计模式：可复用面向对象软件的基础》',
                'sku' => 'BOOK-DESIGN-PATTERNS',
                'spu' => 'BOOK-DESIGN-PATTERNS',
                'handle' => 'book-design-patterns-gof',
                'short_description' => '《设计模式：可复用面向对象软件的基础》，Gang of Four经典著作',
                'description' => '设计模式经典著作，由Gang of Four编写，介绍了23种常用的设计模式，是软件开发的必读书籍。',
                'price' => 89.00,
                'cost' => 50.00,
                'stock' => 80,
                'weight' => 0.8,
                'meta_keywords' => '设计模式,编程,软件工程,面向对象',
                'category_handles' => ['books'],
            ],

            // 电子产品 - 鼠标
            [
                'name' => 'Logitech MX Master 3S 无线鼠标',
                'sku' => 'LOGITECH-MX-MASTER-3S',
                'spu' => 'LOGITECH-MX-MASTER',
                'handle' => 'logitech-mx-master-3s-mouse',
                'short_description' => 'Logitech MX Master 3S 无线鼠标，精准追踪，70天续航',
                'description' => 'Logitech MX Master 3S是专业级无线鼠标，支持多设备连接，精准追踪，70天电池续航，适合办公和创作。',
                'price' => 899.00,
                'cost' => 500.00,
                'stock' => 70,
                'weight' => 0.141,
                'meta_keywords' => 'Logitech,罗技,鼠标,无线鼠标',
                'category_handles' => ['mice'],
                'brand' => 'logitech',
                'color' => 'gray',
                'material' => 'plastic',
            ],
            // 家居用品 - 办公椅
            [
                'name' => 'Herman Miller Aeron 办公椅',
                'sku' => 'HERMAN-AERON-SIZE-B',
                'spu' => 'HERMAN-AERON',
                'handle' => 'herman-miller-aeron-chair-size-b',
                'short_description' => 'Herman Miller Aeron 人体工学办公椅，B尺寸，全功能配置',
                'description' => 'Herman Miller Aeron是经典的人体工学办公椅，提供卓越的支撑和舒适度，适合长时间办公使用。',
                'price' => 8999.00,
                'cost' => 6000.00,
                'stock' => 15,
                'weight' => 18.0,
                'meta_keywords' => 'Herman Miller,办公椅,人体工学',
                'category_handles' => ['office-chairs'],
                'brand' => 'herman_miller',
                'color' => 'black',
            ],
            // 运动户外 - 乐高积木
            [
                'name' => 'LEGO 乐高 星球大战 千年隼',
                'sku' => 'LEGO-STARWARS-MILLENNIUM',
                'spu' => 'LEGO-STARWARS',
                'handle' => 'lego-star-wars-millennium-falcon',
                'short_description' => 'LEGO 乐高 星球大战 千年隼模型，75192，7541块积木',
                'description' => 'LEGO星球大战千年隼是大型模型套装，包含7541块积木，适合收藏和拼装，是星球大战粉丝的必备收藏。',
                'price' => 6999.00,
                'cost' => 4500.00,
                'stock' => 10,
                'weight' => 12.0,
                'meta_keywords' => 'LEGO,乐高,星球大战,积木',
                'category_handles' => ['sports'],
                'brand' => 'lego',
                'color' => 'gray',
                'material' => 'plastic',
            ],
            // 电子产品 - 相机
            [
                'name' => 'Canon EOS R5 全画幅无反相机',
                'sku' => 'CANON-EOS-R5-BODY',
                'spu' => 'CANON-EOS-R5',
                'handle' => 'canon-eos-r5-body-only',
                'short_description' => 'Canon EOS R5 全画幅无反相机，4500万像素，8K视频录制',
                'description' => 'Canon EOS R5是专业级全画幅无反相机，4500万像素，支持8K视频录制，5轴防抖，适合专业摄影和视频创作。',
                'price' => 25999.00,
                'cost' => 20000.00,
                'stock' => 8,
                'weight' => 0.65,
                'meta_keywords' => 'Canon,佳能,相机,无反相机',
                'category_handles' => ['mirrorless'],
                'brand' => 'canon',
                'color' => 'black',
                'material' => 'metal',
            ],
            // 电子产品 - 无线耳机
            [
                'name' => 'Bose QuietComfort 45 降噪耳机',
                'sku' => 'BOSE-QC45-BLK',
                'spu' => 'BOSE-QC45',
                'handle' => 'bose-quietcomfort-45-black',
                'short_description' => 'Bose QuietComfort 45 头戴式降噪耳机，24小时续航，黑色',
                'description' => 'Bose QuietComfort 45提供卓越的主动降噪技术，24小时电池续航，舒适的佩戴体验，适合旅行和日常使用。',
                'price' => 2499.00,
                'cost' => 1600.00,
                'stock' => 40,
                'weight' => 0.24,
                'meta_keywords' => 'Bose,降噪耳机,无线耳机',
                'category_handles' => ['wireless-headphones'],
                'brand' => 'bose',
                'color' => 'black',
                'material' => 'plastic',
            ],
            // 运动户外 - 模型
            [
                'name' => 'Tesla Model 3 车模 1:18',
                'sku' => 'TESLA-MODEL3-MODEL-118',
                'spu' => 'TESLA-MODEL3-MODEL',
                'handle' => 'tesla-model-3-diecast-118',
                'short_description' => 'Tesla Model 3 合金车模 1:18比例，精细还原，收藏级',
                'description' => 'Tesla Model 3 1:18比例合金车模，精细还原实车细节，适合收藏和展示。',
                'price' => 599.00,
                'cost' => 300.00,
                'stock' => 30,
                'weight' => 1.5,
                'meta_keywords' => 'Tesla,车模,模型,收藏',
                'category_handles' => ['sports'],
                'brand' => 'tesla',
                'color' => 'red',
                'material' => 'metal',
            ],

            // ========== 可配置产品（Configurable Products）==========

            // 1. 汉服上襦 - 多颜色多尺寸
            [
                'name' => '青玉交领上襦',
                'sku' => 'HANFU-JIAOLING-MASTER',
                'spu' => 'HANFU-JIAOLING',
                'handle' => 'hanfu-jiaoling-ruao',
                'short_description' => '青玉交领上襦，真丝感面料，多颜色多尺寸可选',
                'description' => '青玉交领上襦以汉服交领结构与轻盈面料呈现传统服饰气质，适合展示服饰颜色、尺寸与商品规格选择。',
                'price' => 299.00,
                'cost' => 156.00,
                'stock' => 0, // 可配置产品通常库存为0
                'weight' => 0.32,
                'image' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => '汉服,交领上襦,国风上装',
                'category_handles' => ['t-shirts'],
                'brand' => 'hua_chao',
                'material' => 'silk',
                'variants' => [
                    [
                        'name' => '青玉交领上襦 - 松绿色 M',
                        'sku' => 'HANFU-JIAOLING-GRN-M',
                        'handle' => 'hanfu-jiaoling-ruao-green-m',
                        'short_description' => '青玉交领上襦，松绿色，M码',
                        'price' => 299.00,
                        'cost' => 156.00,
                        'stock' => 50,
                        'color' => 'green',
                        'size' => 'm',
                        'brand' => 'hua_chao',
                        'material' => 'silk',
                    ],
                    [
                        'name' => '青玉交领上襦 - 月白色 L',
                        'sku' => 'HANFU-JIAOLING-WHT-L',
                        'handle' => 'hanfu-jiaoling-ruao-white-l',
                        'short_description' => '青玉交领上襦，月白色，L码',
                        'price' => 299.00,
                        'cost' => 156.00,
                        'stock' => 45,
                        'color' => 'white',
                        'size' => 'l',
                        'brand' => 'hua_chao',
                        'material' => 'silk',
                    ],
                    [
                        'name' => '青玉交领上襦 - 绯红色 M',
                        'sku' => 'HANFU-JIAOLING-RED-M',
                        'handle' => 'hanfu-jiaoling-ruao-red-m',
                        'short_description' => '青玉交领上襦，绯红色，M码',
                        'price' => 309.00,
                        'cost' => 162.00,
                        'stock' => 48,
                        'color' => 'red',
                        'size' => 'm',
                        'brand' => 'hua_chao',
                        'material' => 'silk',
                    ],
                    [
                        'name' => '青玉交领上襦 - 米杏色 XL',
                        'sku' => 'HANFU-JIAOLING-BEI-XL',
                        'handle' => 'hanfu-jiaoling-ruao-beige-xl',
                        'short_description' => '青玉交领上襦，米杏色，XL码',
                        'price' => 319.00,
                        'cost' => 168.00,
                        'stock' => 42,
                        'color' => 'beige',
                        'size' => 'xl',
                        'brand' => 'hua_chao',
                        'material' => 'silk',
                    ],
                    [
                        'name' => '青玉交领上襦 - 黛青色 M',
                        'sku' => 'HANFU-JIAOLING-NAVY-M',
                        'handle' => 'hanfu-jiaoling-ruao-navy-m',
                        'short_description' => '青玉交领上襦，黛青色，M码',
                        'price' => 309.00,
                        'cost' => 162.00,
                        'stock' => 40,
                        'color' => 'dark_blue',
                        'size' => 'm',
                        'brand' => 'hua_chao',
                        'material' => 'silk',
                    ],
                ],
            ],

            // 2. 汉服下装 - 多颜色多尺寸
            [
                'name' => '织金马面裙',
                'sku' => 'HANFU-MAMIANQUN-MASTER',
                'spu' => 'HANFU-MAMIANQUN',
                'handle' => 'hanfu-mamianqun',
                'short_description' => '织金马面裙，重工提花，多颜色多尺寸',
                'description' => '织金马面裙以传统裙门结构和金线纹样呈现汉服下装特色，适合展示下装规格、颜色与搭配层次。',
                'price' => 799.00,
                'cost' => 420.00,
                'stock' => 0,
                'weight' => 0.75,
                'image' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => '汉服,马面裙,国风下装',
                'category_handles' => ['pants'],
                'brand' => 'yun_jin_studio',
                'material' => 'brocade',
                'variants' => [
                    [
                        'name' => '织金马面裙 - 绯红色 M',
                        'sku' => 'HANFU-MAMIANQUN-RED-M',
                        'handle' => 'hanfu-mamianqun-red-m',
                        'short_description' => '织金马面裙，绯红色，M码',
                        'price' => 799.00,
                        'cost' => 420.00,
                        'stock' => 25,
                        'color' => 'red',
                        'size' => 'm',
                        'brand' => 'yun_jin_studio',
                        'material' => 'brocade',
                    ],
                    [
                        'name' => '织金马面裙 - 黛青色 L',
                        'sku' => 'HANFU-MAMIANQUN-NAVY-L',
                        'handle' => 'hanfu-mamianqun-navy-l',
                        'short_description' => '织金马面裙，黛青色，L码',
                        'price' => 809.00,
                        'cost' => 426.00,
                        'stock' => 30,
                        'color' => 'dark_blue',
                        'size' => 'l',
                        'brand' => 'yun_jin_studio',
                        'material' => 'brocade',
                    ],
                    [
                        'name' => '织金马面裙 - 竹青色 S',
                        'sku' => 'HANFU-MAMIANQUN-GRN-S',
                        'handle' => 'hanfu-mamianqun-green-s',
                        'short_description' => '织金马面裙，竹青色，S码',
                        'price' => 799.00,
                        'cost' => 420.00,
                        'stock' => 20,
                        'color' => 'green',
                        'size' => 's',
                        'brand' => 'yun_jin_studio',
                        'material' => 'brocade',
                    ],
                    [
                        'name' => '织金马面裙 - 米杏色 XL',
                        'sku' => 'HANFU-MAMIANQUN-BEI-XL',
                        'handle' => 'hanfu-mamianqun-beige-xl',
                        'short_description' => '织金马面裙，米杏色，XL码',
                        'price' => 819.00,
                        'cost' => 432.00,
                        'stock' => 22,
                        'color' => 'starlight',
                        'size' => 'xl',
                        'brand' => 'yun_jin_studio',
                        'material' => 'brocade',
                    ],
                ],
            ],

            // 3. 汉服布鞋 - 多颜色多尺寸
            [
                'name' => '云纹绣花布鞋',
                'sku' => 'HANFU-BUXIE-MASTER',
                'spu' => 'HANFU-BUXIE',
                'handle' => 'hanfu-yunwen-buxie',
                'short_description' => '云纹绣花布鞋，软底轻便，多颜色多尺寸',
                'description' => '云纹绣花布鞋以传统云纹鞋面和轻便软底呈现汉服鞋履风格，适合展示鞋类规格、颜色与商品选项切换。',
                'price' => 1299.00,
                'cost' => 700.00,
                'stock' => 0,
                'weight' => 0.48,
                'image' => 'https://images.pexels.com/photos/36311713/pexels-photo-36311713.jpeg?auto=compress&cs=tinysrgb&w=900',
                'meta_keywords' => '汉服,布鞋,绣花鞋,国风鞋履',
                'category_handles' => ['sneakers'],
                'brand' => 'jin_xiu_ge',
                'material' => 'cotton',
                'variants' => [
                    [
                        'name' => '云纹绣花布鞋 - 朱砂色 40',
                        'sku' => 'HANFU-BUXIE-RED-40',
                        'handle' => 'hanfu-yunwen-buxie-red-40',
                        'short_description' => '云纹绣花布鞋，朱砂色，40码',
                        'price' => 1299.00,
                        'cost' => 700.00,
                        'stock' => 35,
                        'color' => 'red',
                        'size' => '40',
                        'brand' => 'jin_xiu_ge',
                        'material' => 'cotton',
                    ],
                    [
                        'name' => '云纹绣花布鞋 - 米杏色 41',
                        'sku' => 'HANFU-BUXIE-BEI-41',
                        'handle' => 'hanfu-yunwen-buxie-beige-41',
                        'short_description' => '云纹绣花布鞋，米杏色，41码',
                        'price' => 1299.00,
                        'cost' => 700.00,
                        'stock' => 40,
                        'color' => 'starlight',
                        'size' => '41',
                        'brand' => 'jin_xiu_ge',
                        'material' => 'cotton',
                    ],
                    [
                        'name' => '云纹绣花布鞋 - 月白色 42',
                        'sku' => 'HANFU-BUXIE-WHT-42',
                        'handle' => 'hanfu-yunwen-buxie-white-42',
                        'short_description' => '云纹绣花布鞋，月白色，42码',
                        'price' => 1299.00,
                        'cost' => 700.00,
                        'stock' => 32,
                        'color' => 'white',
                        'size' => '42',
                        'brand' => 'jin_xiu_ge',
                        'material' => 'cotton',
                    ],
                    [
                        'name' => '云纹绣花布鞋 - 竹青色 43',
                        'sku' => 'HANFU-BUXIE-GRN-43',
                        'handle' => 'hanfu-yunwen-buxie-green-43',
                        'short_description' => '云纹绣花布鞋，竹青色，43码',
                        'price' => 1299.00,
                        'cost' => 700.00,
                        'stock' => 38,
                        'color' => 'green',
                        'size' => '43',
                        'brand' => 'jin_xiu_ge',
                        'material' => 'cotton',
                    ],
                ],
            ],

            // 4. 智能手表 - 多颜色多尺寸
            [
                'name' => 'Apple Watch Series 9',
                'sku' => 'APPLE-WATCH-S9-MASTER',
                'spu' => 'APPLE-WATCH-S9',
                'handle' => 'apple-watch-series-9',
                'short_description' => 'Apple Watch Series 9，GPS+蜂窝网络，多颜色多尺寸',
                'description' => 'Apple Watch Series 9配备S9芯片，支持GPS和蜂窝网络，健康监测功能，多种表带颜色和表壳尺寸可选。',
                'price' => 3199.00,
                'cost' => 2200.00,
                'stock' => 0,
                'weight' => 0.05,
                'meta_keywords' => 'Apple Watch,智能手表,健康监测',
                'category_handles' => ['smart-watches'],
                'brand' => 'apple',
                'material' => 'aluminum',
                'variants' => [
                    [
                        'name' => 'Apple Watch Series 9 - 41mm 星光色 运动表带',
                        'sku' => 'APPLE-WATCH-S9-41-STARLIGHT',
                        'handle' => 'apple-watch-s9-41mm-starlight',
                        'short_description' => 'Apple Watch Series 9，41mm，星光色，运动表带',
                        'price' => 3199.00,
                        'cost' => 2200.00,
                        'stock' => 25,
                        'color' => 'starlight',
                        'size' => '41mm',
                        'brand' => 'apple',
                        'material' => 'aluminum',
                    ],
                    [
                        'name' => 'Apple Watch Series 9 - 41mm 午夜色 运动表带',
                        'sku' => 'APPLE-WATCH-S9-41-MIDNIGHT',
                        'handle' => 'apple-watch-s9-41mm-midnight',
                        'short_description' => 'Apple Watch Series 9，41mm，午夜色，运动表带',
                        'price' => 3199.00,
                        'cost' => 2200.00,
                        'stock' => 28,
                        'color' => 'midnight',
                        'size' => '41mm',
                        'brand' => 'apple',
                        'material' => 'aluminum',
                    ],
                    [
                        'name' => 'Apple Watch Series 9 - 45mm 星光色 运动表带',
                        'sku' => 'APPLE-WATCH-S9-45-STARLIGHT',
                        'handle' => 'apple-watch-s9-45mm-starlight',
                        'short_description' => 'Apple Watch Series 9，45mm，星光色，运动表带',
                        'price' => 3499.00,
                        'cost' => 2400.00,
                        'stock' => 22,
                        'color' => 'starlight',
                        'size' => '45mm',
                        'brand' => 'apple',
                        'material' => 'aluminum',
                    ],
                    [
                        'name' => 'Apple Watch Series 9 - 45mm 午夜色 运动表带',
                        'sku' => 'APPLE-WATCH-S9-45-MIDNIGHT',
                        'handle' => 'apple-watch-s9-45mm-midnight',
                        'short_description' => 'Apple Watch Series 9，45mm，午夜色，运动表带',
                        'price' => 3499.00,
                        'cost' => 2400.00,
                        'stock' => 24,
                        'color' => 'midnight',
                        'size' => '45mm',
                        'brand' => 'apple',
                        'material' => 'aluminum',
                    ],
                ],
            ],

            // 5. 背包 - 多颜色多容量
            [
                'name' => 'Osprey Talon 22 徒步背包',
                'sku' => 'OSPREY-TALON22-MASTER',
                'spu' => 'OSPREY-TALON22',
                'handle' => 'osprey-talon-22-backpack',
                'short_description' => 'Osprey Talon 22 徒步背包，22升容量，多颜色可选',
                'description' => 'Osprey Talon 22是专业的徒步背包，22升容量，轻量化设计，适合一日徒步和日常使用，多种颜色可选。',
                'price' => 899.00,
                'cost' => 500.00,
                'stock' => 0,
                'weight' => 0.9,
                'meta_keywords' => 'Osprey,背包,徒步,户外',
                'category_handles' => ['bags'],
                'brand' => 'osprey',
                'material' => 'nylon',
                'variants' => [
                    [
                        'name' => 'Osprey Talon 22 - 黑色',
                        'sku' => 'OSPREY-TALON22-BLK',
                        'handle' => 'osprey-talon-22-black',
                        'short_description' => 'Osprey Talon 22，黑色',
                        'price' => 899.00,
                        'cost' => 500.00,
                        'stock' => 30,
                        'color' => 'black',
                        'brand' => 'osprey',
                        'material' => 'nylon',
                    ],
                    [
                        'name' => 'Osprey Talon 22 - 蓝色',
                        'sku' => 'OSPREY-TALON22-BLU',
                        'handle' => 'osprey-talon-22-blue',
                        'short_description' => 'Osprey Talon 22，蓝色',
                        'price' => 899.00,
                        'cost' => 500.00,
                        'stock' => 25,
                        'color' => 'blue',
                        'brand' => 'osprey',
                        'material' => 'nylon',
                    ],
                    [
                        'name' => 'Osprey Talon 22 - 绿色',
                        'sku' => 'OSPREY-TALON22-GRN',
                        'handle' => 'osprey-talon-22-green',
                        'short_description' => 'Osprey Talon 22，绿色',
                        'price' => 899.00,
                        'cost' => 500.00,
                        'stock' => 20,
                        'color' => 'green',
                        'brand' => 'osprey',
                        'material' => 'nylon',
                    ],
                ],
            ],
        ];
    }
}
