<?php

declare(strict_types=1);

namespace WeShop\Catalog\Console\Category\Import;

use WeShop\Catalog\Model\Category;
use WeShop\Product\Model\ProductCategory;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use PDO;

/**
 * 导入示例分类数据命令
 * 
 * 导入分类数据，包括EAV属性配置
 * 每次运行会删除旧数据并重新导入
 */
class Sample extends CommandAbstract
{
    public const dir = 'Console\\Category\\Import';
    
    private Category $category;
    
    /**
     * 产品分类关系备份
     */
    private array $productCategoryBackup = [];

    public function __construct(Category $category)
    {
        $this->category = $category;
    }

    public function execute(array $args = [], array $data = []): void
    {
        $this->printer->note('开始导入示例分类数据...');

        // 1. 备份产品分类关系
        $this->backupProductCategoryRelations();

        // 2. 删除所有旧分类数据
        $this->deleteAllCategories();

        // 3. 导入新分类数据
        $importedCount = $this->importCategories();

        // 4. 恢复产品分类关系
        $this->restoreProductCategoryRelations();

        $this->printer->success("\n导入完成！");
        $this->printer->note("成功导入: {$importedCount} 个分类");
    }

    public function tip(): string
    {
        return '导入示例分类数据（包含EAV属性配置）';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'category:import:sample',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '基本用法' => 'php bin/w category:import:sample',
                '说明' => '此命令会导入示例分类数据，包括：' . PHP_EOL .
                    '  - 电子产品（手机、电脑、智能设备等）' . PHP_EOL .
                    '  - 服装服饰（男装、女装、童装等）' . PHP_EOL .
                    '  - 家居用品（家具、家纺、厨房用品等）' . PHP_EOL .
                    '  - 运动户外' . PHP_EOL .
                    '  - 图书音像' . PHP_EOL .
                    '  - 食品饮料' . PHP_EOL .
                    '  注意：每次运行会删除旧数据，但会备份并恢复产品关联关系',
            ]
        );
    }

    /**
     * 备份产品分类关系
     */
    private function backupProductCategoryRelations(): void
    {
        $this->printer->note('正在备份产品分类关系...');
        
        try {
            /** @var ProductCategory $productCategory */
            $productCategory = ObjectManager::getInstance(ProductCategory::class);
            $tableName = $productCategory->getTable();
            $categoryTable = $this->category->getTable();
            
            $query = $productCategory->getQuery();
            $link = $query->getLink();
            
            // 查询所有产品分类关系，同时获取分类的handle用于后续匹配
            $sql = "SELECT pc.product_id, pc.category_id, c.handle 
                    FROM {$tableName} pc 
                    LEFT JOIN {$categoryTable} c ON pc.category_id = c.category_id";
            $stmt = $link->query($sql);
            $relations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->productCategoryBackup = [];
            foreach ($relations as $relation) {
                if (!empty($relation['handle'])) {
                    $this->productCategoryBackup[] = [
                        'product_id' => (int)$relation['product_id'],
                        'handle' => $relation['handle'],
                    ];
                }
            }
            
            $this->printer->success("已备份 " . count($this->productCategoryBackup) . " 条产品分类关系");
            
        } catch (\Exception $e) {
            $this->printer->warning('备份产品分类关系失败: ' . $e->getMessage());
            $this->productCategoryBackup = [];
        }
    }

    /**
     * 删除所有分类数据
     */
    private function deleteAllCategories(): void
    {
        $this->printer->note('正在删除旧分类数据...');
        
        try {
            $query = $this->category->getQuery();
            $link = $query->getLink();
            
            $categoryTable = $this->category->getTable();
            
            // 1. 删除产品分类关联
            /** @var ProductCategory $productCategory */
            $productCategory = ObjectManager::getInstance(ProductCategory::class);
            $productCategoryTable = $productCategory->getTable();
            $link->exec("DELETE FROM {$productCategoryTable}");
            
            // 2. 删除分类EAV值表数据
            $eavEntity = ObjectManager::getInstance(EavEntity::class)
                ->loadByCode($this->category::entity_code);
            
            if ($eavEntity->getId()) {
                $eavEntityId = $eavEntity->getId();
                
                // 查找所有分类EAV属性
                /** @var EavAttribute $attributeModel */
                $attributeModel = ObjectManager::getInstance(EavAttribute::class);
                $attributes = $attributeModel->reset()
                    ->where(EavAttribute::schema_fields_eav_entity_id, $eavEntityId)
                    ->select()
                    ->fetchArray();
                
                // 删除每个属性的值
                foreach ($attributes ?: [] as $attribute) {
                    $valueTable = $attribute['value_table'] ?? null;
                    if ($valueTable) {
                        try {
                            $link->exec("DELETE FROM {$valueTable}");
                        } catch (\Exception $e) {
                            // 忽略值表不存在的错误
                        }
                    }
                }
            }
            
            // 3. 删除分类主表数据
            $link->exec("DELETE FROM {$categoryTable}");
            
            // 4. 重置序列（PostgreSQL）
            try {
                $sql = "SELECT setval(pg_get_serial_sequence('{$categoryTable}', 'category_id'), 1, false)";
                $link->exec($sql);
            } catch (\Exception $e) {
                // 序列重置失败不影响主流程
            }
            
            try {
                $sql = "SELECT setval(pg_get_serial_sequence('{$productCategoryTable}', 'product_category_id'), 1, false)";
                $link->exec($sql);
            } catch (\Exception $e) {
                // 序列重置失败不影响主流程
            }
            
            $this->printer->success("已删除所有旧分类数据");
            
        } catch (\Exception $e) {
            $this->printer->error('删除分类数据失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 导入分类数据
     */
    private function importCategories(): int
    {
        $this->printer->note('正在导入新分类数据...');
        
        $categories = $this->getSampleCategories();
        $importedCount = 0;
        
        foreach ($categories as $categoryData) {
            $importedCount += $this->insertCategoryWithChildren($categoryData, 0);
        }
        
        return $importedCount;
    }

    /**
     * 递归插入分类及其子分类
     */
    private function insertCategoryWithChildren(array $categoryData, int $parentId, int $sortOrder = 0): int
    {
        $count = 0;
        
        try {
            // 从完整路径中提取最后一部分作为handle
            $handle = $categoryData['handle'] ?? '';
            if (strpos($handle, '/') !== false) {
                $handleParts = explode('/', $handle);
                $handle = end($handleParts);
            }
            
            if (empty($handle)) {
                $handle = $this->generateHandle($categoryData['name']);
            }
            
            // 创建分类
            $category = clone $this->category;
            $category->reset()->clearData();
            $category->forceCheck(false)
                ->setData(Category::schema_fields_NAME, $categoryData['name'])
                ->setData(Category::schema_fields_HANDLE, $handle)
                ->setData(Category::schema_fields_PARENT_ID, $parentId)
                ->setData(Category::schema_fields_SORT_ORDER, $sortOrder)
                ->setData(Category::schema_fields_IS_ACTIVE, $categoryData['is_active'] ?? 1)
                ->setData(Category::schema_fields_DESCRIPTION, $categoryData['description'] ?? '')
                ->setData(Category::schema_fields_IMAGE, $categoryData['image'] ?? '');
            
            $categoryId = $category->save();
            
            if (!$categoryId) {
                $categoryId = $category->getId();
            }
            
            if (!$categoryId) {
                $this->printer->warning("创建分类失败: {$categoryData['name']}");
                return $count;
            }
            
            // 设置EAV属性值
            if (!empty($categoryData['eav_attributes'])) {
                $this->setEavAttributes((int)$categoryId, $categoryData['eav_attributes']);
            }
            
            $indent = str_repeat('  ', $this->getCategoryDepth($parentId));
            $this->printer->success("{$indent}✓ 创建分类: {$categoryData['name']} (ID: {$categoryId})");
            $count++;
            
            // 递归处理子分类
            if (!empty($categoryData['children']) && is_array($categoryData['children'])) {
                $childSortOrder = 0;
                foreach ($categoryData['children'] as $childData) {
                    $count += $this->insertCategoryWithChildren($childData, (int)$categoryId, $childSortOrder);
                    $childSortOrder++;
                }
            }
            
        } catch (\Exception $e) {
            $this->printer->error("创建分类 {$categoryData['name']} 时出错: " . $e->getMessage());
        }
        
        return $count;
    }

    /**
     * 设置分类的EAV属性值
     */
    private function setEavAttributes(int $categoryId, array $attributes): void
    {
        try {
            // 重新加载分类以设置EAV属性
            $category = clone $this->category;
            $category->reset()->load($categoryId);
            
            foreach ($attributes as $code => $value) {
                try {
                    $category->setEavData($code, $value);
                } catch (\Exception $e) {
                    // 忽略设置EAV属性失败的错误
                }
            }
            
            $category->save();
        } catch (\Exception $e) {
            // 忽略EAV属性设置失败
        }
    }

    /**
     * 获取分类深度（用于缩进输出）
     */
    private function getCategoryDepth(int $parentId): int
    {
        if ($parentId === 0) {
            return 0;
        }
        
        $depth = 0;
        $currentParentId = $parentId;
        
        while ($currentParentId > 0 && $depth < 10) {
            $parent = clone $this->category;
            $parent->reset()->load($currentParentId);
            if (!$parent->getId()) {
                break;
            }
            $currentParentId = (int)$parent->getData(Category::schema_fields_PARENT_ID);
            $depth++;
        }
        
        return $depth;
    }

    /**
     * 恢复产品分类关系
     */
    private function restoreProductCategoryRelations(): void
    {
        if (empty($this->productCategoryBackup)) {
            $this->printer->note('没有需要恢复的产品分类关系');
            return;
        }
        
        $this->printer->note('正在恢复产品分类关系...');
        
        $restoredCount = 0;
        $failedCount = 0;
        
        /** @var ProductCategory $productCategory */
        $productCategory = ObjectManager::getInstance(ProductCategory::class);
        $tableName = $productCategory->getTable();
        $query = $productCategory->getQuery();
        $link = $query->getLink();
        
        foreach ($this->productCategoryBackup as $relation) {
            try {
                // 根据handle查找新的分类ID
                $newCategory = clone $this->category;
                $newCategory->reset()
                    ->where(Category::schema_fields_HANDLE, $relation['handle'])
                    ->find()
                    ->fetch();
                
                if (!$newCategory->getId()) {
                    $failedCount++;
                    continue;
                }
                
                $newCategoryId = $newCategory->getId();
                
                // 检查关系是否已存在
                $checkSql = "SELECT product_category_id FROM {$tableName} WHERE product_id = :product_id AND category_id = :category_id";
                $checkStmt = $link->prepare($checkSql);
                $checkStmt->execute([
                    ':product_id' => $relation['product_id'],
                    ':category_id' => $newCategoryId,
                ]);
                
                if ($checkStmt->fetch()) {
                    // 关系已存在，跳过
                    continue;
                }
                
                // 插入新关系
                $sql = "INSERT INTO {$tableName} (product_id, category_id) VALUES (:product_id, :category_id) RETURNING product_category_id";
                $stmt = $link->prepare($sql);
                $stmt->execute([
                    ':product_id' => $relation['product_id'],
                    ':category_id' => $newCategoryId,
                ]);
                
                $restoredCount++;
                
            } catch (\Exception $e) {
                $failedCount++;
            }
        }
        
        $this->printer->success("已恢复 {$restoredCount} 条产品分类关系");
        if ($failedCount > 0) {
            $this->printer->warning("有 {$failedCount} 条关系无法恢复（分类可能已被移除）");
        }
    }

    /**
     * 生成Handle标识
     */
    private function generateHandle(string $name): string
    {
        $handle = strtolower($name);
        $handle = preg_replace('/[^a-z0-9]+/', '-', $handle);
        $handle = trim($handle, '-');
        return $handle;
    }

    /**
     * 获取示例分类数据
     * 
     * 包含丰富的分类结构和EAV属性配置
     */
    private function getSampleCategories(): array
    {
        return [
            // ========== 电子产品 ==========
            [
                'name' => '电子产品',
                'handle' => 'electronics',
                'description' => '各类电子产品，包括手机、电脑、智能设备等',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '1',
                    'icon' => 'fas fa-laptop',
                    'show_icon' => '1',
                ],
                'children' => [
                    [
                        'name' => '手机通讯',
                        'handle' => 'phones',
                        'description' => '智能手机、功能手机及配件',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-mobile-alt',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '智能手机', 'handle' => 'smartphones', 'is_active' => 1, 'description' => 'iOS、Android等智能手机'],
                            ['name' => '功能手机', 'handle' => 'feature-phones', 'is_active' => 1],
                            ['name' => '5G手机', 'handle' => '5g-phones', 'is_active' => 1, 'description' => '支持5G网络的手机'],
                            ['name' => '折叠手机', 'handle' => 'foldable', 'is_active' => 1],
                            ['name' => '手机壳', 'handle' => 'cases', 'is_active' => 1],
                            ['name' => '屏幕保护膜', 'handle' => 'screen-protectors', 'is_active' => 1],
                            ['name' => '充电器', 'handle' => 'chargers', 'is_active' => 1],
                            ['name' => '数据线', 'handle' => 'cables', 'is_active' => 1],
                            ['name' => '移动电源', 'handle' => 'power-banks', 'is_active' => 1],
                            ['name' => '手机耳机', 'handle' => 'phone-headphones', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '电脑办公',
                        'handle' => 'computers',
                        'description' => '笔记本、台式机及办公设备',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-desktop',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '笔记本', 'handle' => 'laptops', 'is_active' => 1, 'description' => '各品牌笔记本电脑'],
                            ['name' => '游戏本', 'handle' => 'gaming-laptops', 'is_active' => 1],
                            ['name' => '超极本', 'handle' => 'ultrabooks', 'is_active' => 1],
                            ['name' => '台式机', 'handle' => 'desktops', 'is_active' => 1],
                            ['name' => '一体机', 'handle' => 'all-in-one', 'is_active' => 1],
                            ['name' => '平板电脑', 'handle' => 'tablets', 'is_active' => 1],
                            ['name' => '显示器', 'handle' => 'monitors', 'is_active' => 1],
                            ['name' => '键盘', 'handle' => 'keyboards', 'is_active' => 1],
                            ['name' => '鼠标', 'handle' => 'mice', 'is_active' => 1],
                            ['name' => '摄像头', 'handle' => 'webcams', 'is_active' => 1],
                            ['name' => '存储设备', 'handle' => 'storage', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '智能设备',
                        'handle' => 'smart-devices',
                        'description' => '智能穿戴及智能家居设备',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-robot',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '智能手表', 'handle' => 'smart-watches', 'is_active' => 1],
                            ['name' => '运动手环', 'handle' => 'fitness-trackers', 'is_active' => 1],
                            ['name' => '智能音箱', 'handle' => 'smart-speakers', 'is_active' => 1],
                            ['name' => '智能照明', 'handle' => 'smart-lights', 'is_active' => 1],
                            ['name' => '智能门锁', 'handle' => 'smart-locks', 'is_active' => 1],
                            ['name' => '智能摄像头', 'handle' => 'smart-cameras', 'is_active' => 1],
                            ['name' => '智能家居套装', 'handle' => 'smart-home', 'is_active' => 1],
                            ['name' => '可穿戴设备', 'handle' => 'wearables', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '影音娱乐',
                        'handle' => 'audio-video',
                        'description' => '耳机、音响、相机等影音设备',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-headphones',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '无线耳机', 'handle' => 'wireless-headphones', 'is_active' => 1],
                            ['name' => '有线耳机', 'handle' => 'wired-headphones', 'is_active' => 1],
                            ['name' => '入耳式耳机', 'handle' => 'earbuds', 'is_active' => 1],
                            ['name' => '蓝牙音响', 'handle' => 'speakers', 'is_active' => 1],
                            ['name' => 'Soundbar', 'handle' => 'soundbars', 'is_active' => 1],
                            ['name' => '单反相机', 'handle' => 'dslr-cameras', 'is_active' => 1],
                            ['name' => '微单相机', 'handle' => 'mirrorless', 'is_active' => 1],
                            ['name' => '运动相机', 'handle' => 'action-cameras', 'is_active' => 1],
                            ['name' => '游戏主机', 'handle' => 'gaming-consoles', 'is_active' => 1],
                            ['name' => '游戏配件', 'handle' => 'gaming-accessories', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            
            // ========== 服装服饰 ==========
            [
                'name' => '服装服饰',
                'handle' => 'clothing',
                'description' => '男装、女装、童装及配饰',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '1',
                    'icon' => 'fas fa-tshirt',
                    'show_icon' => '1',
                ],
                'children' => [
                    [
                        'name' => '男装',
                        'handle' => 'men',
                        'description' => '男士服装及配饰',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-male',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '衬衫', 'handle' => 'shirts', 'is_active' => 1],
                            ['name' => 'T恤', 'handle' => 't-shirts', 'is_active' => 1],
                            ['name' => 'Polo衫', 'handle' => 'polo-shirts', 'is_active' => 1],
                            ['name' => '长裤', 'handle' => 'pants', 'is_active' => 1],
                            ['name' => '短裤', 'handle' => 'shorts', 'is_active' => 1],
                            ['name' => '外套', 'handle' => 'jackets', 'is_active' => 1],
                            ['name' => '毛衣', 'handle' => 'sweaters', 'is_active' => 1],
                            ['name' => '皮鞋', 'handle' => 'leather-shoes', 'is_active' => 1],
                            ['name' => '运动鞋', 'handle' => 'sneakers', 'is_active' => 1],
                            ['name' => '皮带', 'handle' => 'belts', 'is_active' => 1],
                            ['name' => '手表', 'handle' => 'watches', 'is_active' => 1],
                            ['name' => '包袋', 'handle' => 'bags', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '女装',
                        'handle' => 'women',
                        'description' => '女士服装及配饰',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-female',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '连衣裙', 'handle' => 'dresses', 'is_active' => 1],
                            ['name' => '上装', 'handle' => 'tops', 'is_active' => 1],
                            ['name' => '衬衫', 'handle' => 'blouses', 'is_active' => 1],
                            ['name' => '下装', 'handle' => 'bottoms', 'is_active' => 1],
                            ['name' => '半身裙', 'handle' => 'skirts', 'is_active' => 1],
                            ['name' => '外套', 'handle' => 'outerwear', 'is_active' => 1],
                            ['name' => '大衣', 'handle' => 'coats', 'is_active' => 1],
                            ['name' => '高跟鞋', 'handle' => 'heels', 'is_active' => 1],
                            ['name' => '平底鞋', 'handle' => 'flats', 'is_active' => 1],
                            ['name' => '靴子', 'handle' => 'boots', 'is_active' => 1],
                            ['name' => '手提包', 'handle' => 'handbags', 'is_active' => 1],
                            ['name' => '斜挎包', 'handle' => 'crossbody-bags', 'is_active' => 1],
                            ['name' => '首饰', 'handle' => 'jewelry', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '童装',
                        'handle' => 'kids',
                        'description' => '儿童服装及配饰',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-child',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '男童装', 'handle' => 'boys', 'is_active' => 1],
                            ['name' => '女童装', 'handle' => 'girls', 'is_active' => 1],
                            ['name' => '婴儿装', 'handle' => 'baby', 'is_active' => 1],
                            ['name' => '童鞋', 'handle' => 'kids-shoes', 'is_active' => 1],
                            ['name' => '童装配饰', 'handle' => 'accessories', 'is_active' => 1],
                            ['name' => '校服', 'handle' => 'school-uniforms', 'is_active' => 1],
                            ['name' => '泳装', 'handle' => 'swimwear', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '运动服饰',
                        'handle' => 'sports-clothing',
                        'description' => '运动服装及装备',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-running',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '运动装', 'handle' => 'athletic-wear', 'is_active' => 1],
                            ['name' => '跑步装备', 'handle' => 'running-gear', 'is_active' => 1],
                            ['name' => '健身服', 'handle' => 'fitness-wear', 'is_active' => 1],
                            ['name' => '运动鞋', 'handle' => 'sports-shoes', 'is_active' => 1],
                            ['name' => '跑鞋', 'handle' => 'running-shoes', 'is_active' => 1],
                            ['name' => '篮球鞋', 'handle' => 'basketball-shoes', 'is_active' => 1],
                            ['name' => '户外装备', 'handle' => 'outdoor', 'is_active' => 1],
                            ['name' => '露营装备', 'handle' => 'camping', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            
            // ========== 家居用品 ==========
            [
                'name' => '家居用品',
                'handle' => 'home',
                'description' => '家具、家纺、厨房用品等家居产品',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '1',
                    'icon' => 'fas fa-home',
                    'show_icon' => '1',
                ],
                'children' => [
                    [
                        'name' => '家具',
                        'handle' => 'furniture',
                        'description' => '客厅、卧室、书房家具',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-couch',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '沙发', 'handle' => 'sofas', 'is_active' => 1],
                            ['name' => '沙发床', 'handle' => 'sofa-beds', 'is_active' => 1],
                            ['name' => '餐桌', 'handle' => 'tables', 'is_active' => 1],
                            ['name' => '茶几', 'handle' => 'coffee-tables', 'is_active' => 1],
                            ['name' => '椅子', 'handle' => 'chairs', 'is_active' => 1],
                            ['name' => '办公椅', 'handle' => 'office-chairs', 'is_active' => 1],
                            ['name' => '床具', 'handle' => 'beds', 'is_active' => 1],
                            ['name' => '衣柜', 'handle' => 'wardrobes', 'is_active' => 1],
                            ['name' => '书柜', 'handle' => 'bookcases', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '家纺',
                        'handle' => 'textiles',
                        'description' => '床上用品、窗帘、毛巾等',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-bed',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '床上用品', 'handle' => 'bedding', 'is_active' => 1],
                            ['name' => '床单被套', 'handle' => 'sheets', 'is_active' => 1],
                            ['name' => '枕头', 'handle' => 'pillows', 'is_active' => 1],
                            ['name' => '被子', 'handle' => 'quilts', 'is_active' => 1],
                            ['name' => '窗帘', 'handle' => 'curtains', 'is_active' => 1],
                            ['name' => '毛巾', 'handle' => 'towels', 'is_active' => 1],
                            ['name' => '地毯', 'handle' => 'rugs', 'is_active' => 1],
                            ['name' => '靠垫', 'handle' => 'cushions', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '厨房用品',
                        'handle' => 'kitchen',
                        'description' => '锅具、餐具、厨房工具等',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-utensils',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '锅具', 'handle' => 'cookware', 'is_active' => 1],
                            ['name' => '刀具', 'handle' => 'knives', 'is_active' => 1],
                            ['name' => '餐具', 'handle' => 'dining', 'is_active' => 1],
                            ['name' => '杯具', 'handle' => 'cups', 'is_active' => 1],
                            ['name' => '收纳', 'handle' => 'storage', 'is_active' => 1],
                            ['name' => '厨房工具', 'handle' => 'kitchen-tools', 'is_active' => 1],
                            ['name' => '小家电', 'handle' => 'small-appliances', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '装饰用品',
                        'handle' => 'decor',
                        'description' => '墙饰、花瓶、灯具等装饰品',
                        'is_active' => 1,
                        'eav_attributes' => [
                            'icon' => 'fas fa-paint-brush',
                            'show_icon' => '1',
                        ],
                        'children' => [
                            ['name' => '墙饰', 'handle' => 'wall-art', 'is_active' => 1],
                            ['name' => '花瓶', 'handle' => 'vases', 'is_active' => 1],
                            ['name' => '灯具', 'handle' => 'lamps', 'is_active' => 1],
                            ['name' => '镜子', 'handle' => 'mirrors', 'is_active' => 1],
                            ['name' => '绿植', 'handle' => 'plants', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            
            // ========== 运动户外 ==========
            [
                'name' => '运动户外',
                'handle' => 'sports',
                'description' => '运动器材、户外装备、健身用品',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '1',
                    'icon' => 'fas fa-futbol',
                    'show_icon' => '1',
                ],
                'children' => [
                    ['name' => '健身器材', 'handle' => 'fitness-equipment', 'is_active' => 1],
                    ['name' => '球类运动', 'handle' => 'ball-sports', 'is_active' => 1],
                    ['name' => '骑行运动', 'handle' => 'cycling', 'is_active' => 1],
                    ['name' => '游泳装备', 'handle' => 'swimming', 'is_active' => 1],
                    ['name' => '户外探险', 'handle' => 'outdoor-adventure', 'is_active' => 1],
                    ['name' => '瑜伽用品', 'handle' => 'yoga', 'is_active' => 1],
                    ['name' => '垂钓用品', 'handle' => 'fishing', 'is_active' => 1],
                ]
            ],
            
            // ========== 图书音像 ==========
            [
                'name' => '图书音像',
                'handle' => 'books',
                'description' => '图书、电子书、音像制品',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '1',
                    'icon' => 'fas fa-book',
                    'show_icon' => '1',
                ],
                'children' => [
                    ['name' => '文学小说', 'handle' => 'fiction', 'is_active' => 1],
                    ['name' => '经管励志', 'handle' => 'business', 'is_active' => 1],
                    ['name' => '人文社科', 'handle' => 'humanities', 'is_active' => 1],
                    ['name' => '科技编程', 'handle' => 'technology', 'is_active' => 1],
                    ['name' => '教育教材', 'handle' => 'education', 'is_active' => 1],
                    ['name' => '少儿童书', 'handle' => 'children-books', 'is_active' => 1],
                    ['name' => '电子书', 'handle' => 'ebooks', 'is_active' => 1],
                ]
            ],
            
            // ========== 食品饮料 ==========
            [
                'name' => '食品饮料',
                'handle' => 'food',
                'description' => '零食、饮料、生鲜食品',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '1',
                    'icon' => 'fas fa-utensils',
                    'show_icon' => '1',
                ],
                'children' => [
                    ['name' => '零食糕点', 'handle' => 'snacks', 'is_active' => 1],
                    ['name' => '饮料冲调', 'handle' => 'beverages', 'is_active' => 1],
                    ['name' => '粮油调味', 'handle' => 'cooking-essentials', 'is_active' => 1],
                    ['name' => '生鲜水果', 'handle' => 'fresh-fruits', 'is_active' => 1],
                    ['name' => '酒类', 'handle' => 'alcohol', 'is_active' => 1],
                    ['name' => '进口食品', 'handle' => 'imported-food', 'is_active' => 1],
                    ['name' => '茶叶咖啡', 'handle' => 'tea-coffee', 'is_active' => 1],
                ]
            ],
            
            // ========== 美妆个护 ==========
            [
                'name' => '美妆个护',
                'handle' => 'beauty',
                'description' => '护肤、彩妆、个人护理',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '1',
                    'icon' => 'fas fa-spa',
                    'show_icon' => '1',
                ],
                'children' => [
                    ['name' => '护肤品', 'handle' => 'skincare', 'is_active' => 1],
                    ['name' => '彩妆', 'handle' => 'makeup', 'is_active' => 1],
                    ['name' => '香水', 'handle' => 'perfume', 'is_active' => 1],
                    ['name' => '美发护发', 'handle' => 'hair-care', 'is_active' => 1],
                    ['name' => '口腔护理', 'handle' => 'oral-care', 'is_active' => 1],
                    ['name' => '身体护理', 'handle' => 'body-care', 'is_active' => 1],
                    ['name' => '美容工具', 'handle' => 'beauty-tools', 'is_active' => 1],
                ]
            ],
            
            // ========== 母婴用品 ==========
            [
                'name' => '母婴用品',
                'handle' => 'baby',
                'description' => '婴儿用品、孕妇用品、儿童玩具',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '1',
                    'icon' => 'fas fa-baby',
                    'show_icon' => '1',
                ],
                'children' => [
                    ['name' => '奶粉辅食', 'handle' => 'baby-food', 'is_active' => 1],
                    ['name' => '纸尿裤', 'handle' => 'diapers', 'is_active' => 1],
                    ['name' => '喂养用品', 'handle' => 'feeding', 'is_active' => 1],
                    ['name' => '洗护用品', 'handle' => 'baby-care', 'is_active' => 1],
                    ['name' => '童车童床', 'handle' => 'strollers', 'is_active' => 1],
                    ['name' => '玩具', 'handle' => 'toys', 'is_active' => 1],
                    ['name' => '孕妇用品', 'handle' => 'maternity', 'is_active' => 1],
                ]
            ],
            
            // ========== 汽车用品 ==========
            [
                'name' => '汽车用品',
                'handle' => 'automotive',
                'description' => '汽车配件、车载电子、汽车养护',
                'is_active' => 1,
                'eav_attributes' => [
                    'is_right_menu' => '0',
                    'icon' => 'fas fa-car',
                    'show_icon' => '1',
                ],
                'children' => [
                    ['name' => '车载电子', 'handle' => 'car-electronics', 'is_active' => 1],
                    ['name' => '汽车装饰', 'handle' => 'car-decor', 'is_active' => 1],
                    ['name' => '汽车养护', 'handle' => 'car-care', 'is_active' => 1],
                    ['name' => '安全座椅', 'handle' => 'car-seats', 'is_active' => 1],
                    ['name' => '汽车配件', 'handle' => 'car-parts', 'is_active' => 1],
                ]
            ],
        ];
    }
}
