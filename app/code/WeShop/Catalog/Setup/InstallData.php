<?php

declare(strict_types=1);

namespace WeShop\Catalog\Setup;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Catalog\Model\Category;

/**
 * 安装默认分类数据
 */
class InstallData
{
    /**
     * 安装默认分类数据
     */
    public function install(): void
    {
        // 检查是否已有分类数据
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        // 使用 count() 方法检查记录数，更可靠
        $existingCount = $category->clear()
            ->select()
            ->count();

        // 如果已有分类数据，跳过安装
        if ($existingCount > 0) {
            return;
        }

        // 定义默认分类数据
        $defaultCategories = $this->getDefaultCategories();

        // 递归插入分类
        try {
            $insertedCount = $this->insertCategories($category, $defaultCategories, 0, 0);
            
            // 验证插入的数据
            $verifyCount = $category->clear()
                ->select()
                ->count();
                
            if ($verifyCount === 0) {
                throw new \Exception(__('分类数据插入后验证失败：数据库中没有找到任何分类数据（插入计数: %{1}）', [$insertedCount]));
            }
        } catch (\Exception $e) {
            throw new \Exception(__('安装默认分类数据失败: %{1}', [$e->getMessage()]), 0, $e);
        }
    }

    /**
     * 递归插入分类
     *
     * @param Category $category
     * @param array $categories
     * @param int $parentId
     * @param int $sortOrder
     * @return int 返回插入的分类数量
     */
    private function insertCategories(Category $category, array $categories, int $parentId, int $sortOrder): int
    {
        $insertedCount = 0;
        foreach ($categories as $catData) {
            $category->clear();
            
            // 从完整路径中提取最后一部分作为url_key
            $urlKey = $catData['url_key'] ?? '';
            if (strpos($urlKey, '/') !== false) {
                $urlKeyParts = explode('/', $urlKey);
                $urlKey = end($urlKeyParts);
            }
            
            // 如果没有提供url_key，则生成
            if (empty($urlKey)) {
                $urlKey = $this->generateUrlKey($catData['name']);
            }
            
            // 检查分类是否已存在（通过url_key和parent_id）
            $existing = $category->clear()
                ->where(Category::fields_URL_KEY, $urlKey)
                ->where(Category::fields_PARENT_ID, $parentId)
                ->find()
                ->fetch();
            
            $categoryId = $existing->getId();
            if (!$categoryId) {
                // 如果不存在，创建新分类
                // 使用 forceCheck(false) 强制插入新记录，不检查是否存在
                $category->clear()
                    ->forceCheck(false)
                    ->setData(Category::fields_NAME, $catData['name'])
                    ->setData(Category::fields_URL_KEY, $urlKey)
                    ->setData(Category::fields_PARENT_ID, $parentId)
                    ->setData(Category::fields_SORT_ORDER, $sortOrder)
                    ->setData(Category::fields_IS_ACTIVE, $catData['is_active'] ?? 1)
                    ->setData(Category::fields_DESCRIPTION, $catData['description'] ?? '');
                
                $saveResult = $category->save();
                
                // 验证保存结果
                if ($saveResult === false || $saveResult === null) {
                    throw new \Exception(__('保存分类失败: %{1} (parent_id: %{2})', [$catData['name'], $parentId]));
                }
                
                $categoryId = $category->getId();
                if (!$categoryId) {
                    // 如果 getId() 为空，尝试从 saveResult 获取
                    if (is_numeric($saveResult)) {
                        $categoryId = (int)$saveResult;
                        $category->setId($categoryId);
                    } else {
                        throw new \Exception(__('保存分类后无法获取ID: %{1} (parent_id: %{2}, save_result: %{3})', [
                            $catData['name'], 
                            $parentId,
                            var_export($saveResult, true)
                        ]));
                    }
                }
                $insertedCount++;
            }

            // 如果有子分类，递归插入
            if (!empty($catData['children']) && is_array($catData['children'])) {
                $insertedCount += $this->insertCategories($category, $catData['children'], $categoryId, 0);
            }

            $sortOrder++;
        }

        return $insertedCount;
    }

    /**
     * 生成URL标识
     */
    private function generateUrlKey(string $name): string
    {
        // 简单的URL标识生成（实际应该使用更完善的转换函数）
        $urlKey = strtolower($name);
        $urlKey = preg_replace('/[^a-z0-9]+/', '-', $urlKey);
        $urlKey = trim($urlKey, '-');
        return $urlKey;
    }

    /**
     * 获取默认分类数据
     */
    private function getDefaultCategories(): array
    {
        return [
            [
                'name' => '电子产品',
                'url_key' => 'electronics',
                'is_active' => 1,
                'children' => [
                    [
                        'name' => '手机通讯',
                        'url_key' => 'electronics/phones',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '智能手机', 'url_key' => 'electronics/phones/smartphones', 'is_active' => 1],
                            ['name' => '功能手机', 'url_key' => 'electronics/phones/feature-phones', 'is_active' => 1],
                            ['name' => '5G手机', 'url_key' => 'electronics/phones/5g-phones', 'is_active' => 1],
                            ['name' => '折叠手机', 'url_key' => 'electronics/phones/foldable', 'is_active' => 1],
                            ['name' => '手机壳', 'url_key' => 'electronics/phones/cases', 'is_active' => 1],
                            ['name' => '屏幕保护膜', 'url_key' => 'electronics/phones/screen-protectors', 'is_active' => 1],
                            ['name' => '充电器', 'url_key' => 'electronics/phones/chargers', 'is_active' => 1],
                            ['name' => '数据线', 'url_key' => 'electronics/phones/cables', 'is_active' => 1],
                            ['name' => '移动电源', 'url_key' => 'electronics/phones/power-banks', 'is_active' => 1],
                            ['name' => '手机耳机', 'url_key' => 'electronics/phones/headphones', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '电脑办公',
                        'url_key' => 'electronics/computers',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '笔记本', 'url_key' => 'electronics/computers/laptops', 'is_active' => 1],
                            ['name' => '游戏本', 'url_key' => 'electronics/computers/gaming-laptops', 'is_active' => 1],
                            ['name' => '超极本', 'url_key' => 'electronics/computers/ultrabooks', 'is_active' => 1],
                            ['name' => '台式机', 'url_key' => 'electronics/computers/desktops', 'is_active' => 1],
                            ['name' => '一体机', 'url_key' => 'electronics/computers/all-in-one', 'is_active' => 1],
                            ['name' => '平板电脑', 'url_key' => 'electronics/computers/tablets', 'is_active' => 1],
                            ['name' => '显示器', 'url_key' => 'electronics/computers/monitors', 'is_active' => 1],
                            ['name' => '键盘', 'url_key' => 'electronics/computers/keyboards', 'is_active' => 1],
                            ['name' => '鼠标', 'url_key' => 'electronics/computers/mice', 'is_active' => 1],
                            ['name' => '摄像头', 'url_key' => 'electronics/computers/webcams', 'is_active' => 1],
                            ['name' => '存储设备', 'url_key' => 'electronics/computers/storage', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '智能设备',
                        'url_key' => 'electronics/smart-devices',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '智能手表', 'url_key' => 'electronics/smart-devices/smart-watches', 'is_active' => 1],
                            ['name' => '运动手环', 'url_key' => 'electronics/smart-devices/fitness-trackers', 'is_active' => 1],
                            ['name' => '智能音箱', 'url_key' => 'electronics/smart-devices/smart-speakers', 'is_active' => 1],
                            ['name' => '智能照明', 'url_key' => 'electronics/smart-devices/smart-lights', 'is_active' => 1],
                            ['name' => '智能门锁', 'url_key' => 'electronics/smart-devices/smart-locks', 'is_active' => 1],
                            ['name' => '智能摄像头', 'url_key' => 'electronics/smart-devices/smart-cameras', 'is_active' => 1],
                            ['name' => '智能家居套装', 'url_key' => 'electronics/smart-devices/smart-home', 'is_active' => 1],
                            ['name' => '可穿戴设备', 'url_key' => 'electronics/smart-devices/wearables', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '影音娱乐',
                        'url_key' => 'electronics/audio-video',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '无线耳机', 'url_key' => 'electronics/audio-video/wireless-headphones', 'is_active' => 1],
                            ['name' => '有线耳机', 'url_key' => 'electronics/audio-video/wired-headphones', 'is_active' => 1],
                            ['name' => '入耳式耳机', 'url_key' => 'electronics/audio-video/earbuds', 'is_active' => 1],
                            ['name' => '蓝牙音响', 'url_key' => 'electronics/audio-video/speakers', 'is_active' => 1],
                            ['name' => 'Soundbar', 'url_key' => 'electronics/audio-video/soundbars', 'is_active' => 1],
                            ['name' => '单反相机', 'url_key' => 'electronics/audio-video/dslr-cameras', 'is_active' => 1],
                            ['name' => '微单相机', 'url_key' => 'electronics/audio-video/mirrorless', 'is_active' => 1],
                            ['name' => '运动相机', 'url_key' => 'electronics/audio-video/action-cameras', 'is_active' => 1],
                            ['name' => '游戏主机', 'url_key' => 'electronics/audio-video/gaming-consoles', 'is_active' => 1],
                            ['name' => '游戏配件', 'url_key' => 'electronics/audio-video/gaming-accessories', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            [
                'name' => '服装服饰',
                'url_key' => 'clothing',
                'is_active' => 1,
                'children' => [
                    [
                        'name' => '男装',
                        'url_key' => 'clothing/men',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '衬衫', 'url_key' => 'clothing/men/shirts', 'is_active' => 1],
                            ['name' => 'T恤', 'url_key' => 'clothing/men/t-shirts', 'is_active' => 1],
                            ['name' => 'Polo衫', 'url_key' => 'clothing/men/polo-shirts', 'is_active' => 1],
                            ['name' => '长裤', 'url_key' => 'clothing/men/pants', 'is_active' => 1],
                            ['name' => '短裤', 'url_key' => 'clothing/men/shorts', 'is_active' => 1],
                            ['name' => '外套', 'url_key' => 'clothing/men/jackets', 'is_active' => 1],
                            ['name' => '毛衣', 'url_key' => 'clothing/men/sweaters', 'is_active' => 1],
                            ['name' => '皮鞋', 'url_key' => 'clothing/men/shoes', 'is_active' => 1],
                            ['name' => '运动鞋', 'url_key' => 'clothing/men/sneakers', 'is_active' => 1],
                            ['name' => '皮带', 'url_key' => 'clothing/men/belts', 'is_active' => 1],
                            ['name' => '手表', 'url_key' => 'clothing/men/watches', 'is_active' => 1],
                            ['name' => '包袋', 'url_key' => 'clothing/men/bags', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '女装',
                        'url_key' => 'clothing/women',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '连衣裙', 'url_key' => 'clothing/women/dresses', 'is_active' => 1],
                            ['name' => '上装', 'url_key' => 'clothing/women/tops', 'is_active' => 1],
                            ['name' => '衬衫', 'url_key' => 'clothing/women/blouses', 'is_active' => 1],
                            ['name' => '下装', 'url_key' => 'clothing/women/bottoms', 'is_active' => 1],
                            ['name' => '半身裙', 'url_key' => 'clothing/women/skirts', 'is_active' => 1],
                            ['name' => '外套', 'url_key' => 'clothing/women/outerwear', 'is_active' => 1],
                            ['name' => '大衣', 'url_key' => 'clothing/women/coats', 'is_active' => 1],
                            ['name' => '高跟鞋', 'url_key' => 'clothing/women/heels', 'is_active' => 1],
                            ['name' => '平底鞋', 'url_key' => 'clothing/women/flats', 'is_active' => 1],
                            ['name' => '靴子', 'url_key' => 'clothing/women/boots', 'is_active' => 1],
                            ['name' => '手提包', 'url_key' => 'clothing/women/handbags', 'is_active' => 1],
                            ['name' => '斜挎包', 'url_key' => 'clothing/women/crossbody-bags', 'is_active' => 1],
                            ['name' => '首饰', 'url_key' => 'clothing/women/jewelry', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '童装',
                        'url_key' => 'clothing/kids',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '男童装', 'url_key' => 'clothing/kids/boys', 'is_active' => 1],
                            ['name' => '女童装', 'url_key' => 'clothing/kids/girls', 'is_active' => 1],
                            ['name' => '婴儿装', 'url_key' => 'clothing/kids/baby', 'is_active' => 1],
                            ['name' => '童鞋', 'url_key' => 'clothing/kids/shoes', 'is_active' => 1],
                            ['name' => '童装配饰', 'url_key' => 'clothing/kids/accessories', 'is_active' => 1],
                            ['name' => '校服', 'url_key' => 'clothing/kids/school-uniforms', 'is_active' => 1],
                            ['name' => '泳装', 'url_key' => 'clothing/kids/swimwear', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '运动服饰',
                        'url_key' => 'clothing/sports',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '运动装', 'url_key' => 'clothing/sports/athletic-wear', 'is_active' => 1],
                            ['name' => '跑步装备', 'url_key' => 'clothing/sports/running-gear', 'is_active' => 1],
                            ['name' => '健身服', 'url_key' => 'clothing/sports/fitness-wear', 'is_active' => 1],
                            ['name' => '运动鞋', 'url_key' => 'clothing/sports/sports-shoes', 'is_active' => 1],
                            ['name' => '跑鞋', 'url_key' => 'clothing/sports/running-shoes', 'is_active' => 1],
                            ['name' => '篮球鞋', 'url_key' => 'clothing/sports/basketball-shoes', 'is_active' => 1],
                            ['name' => '户外装备', 'url_key' => 'clothing/sports/outdoor', 'is_active' => 1],
                            ['name' => '露营装备', 'url_key' => 'clothing/sports/camping', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            [
                'name' => '家居用品',
                'url_key' => 'home',
                'is_active' => 1,
                'children' => [
                    [
                        'name' => '家具',
                        'url_key' => 'home/furniture',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '沙发', 'url_key' => 'home/furniture/sofas', 'is_active' => 1],
                            ['name' => '沙发床', 'url_key' => 'home/furniture/sofa-beds', 'is_active' => 1],
                            ['name' => '餐桌', 'url_key' => 'home/furniture/tables', 'is_active' => 1],
                            ['name' => '茶几', 'url_key' => 'home/furniture/coffee-tables', 'is_active' => 1],
                            ['name' => '椅子', 'url_key' => 'home/furniture/chairs', 'is_active' => 1],
                            ['name' => '办公椅', 'url_key' => 'home/furniture/office-chairs', 'is_active' => 1],
                            ['name' => '床具', 'url_key' => 'home/furniture/beds', 'is_active' => 1],
                            ['name' => '衣柜', 'url_key' => 'home/furniture/wardrobes', 'is_active' => 1],
                            ['name' => '书柜', 'url_key' => 'home/furniture/bookcases', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '家纺',
                        'url_key' => 'home/textiles',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '床上用品', 'url_key' => 'home/textiles/bedding', 'is_active' => 1],
                            ['name' => '床单被套', 'url_key' => 'home/textiles/sheets', 'is_active' => 1],
                            ['name' => '枕头', 'url_key' => 'home/textiles/pillows', 'is_active' => 1],
                            ['name' => '被子', 'url_key' => 'home/textiles/quilts', 'is_active' => 1],
                            ['name' => '窗帘', 'url_key' => 'home/textiles/curtains', 'is_active' => 1],
                            ['name' => '毛巾', 'url_key' => 'home/textiles/towels', 'is_active' => 1],
                            ['name' => '地毯', 'url_key' => 'home/textiles/rugs', 'is_active' => 1],
                            ['name' => '靠垫', 'url_key' => 'home/textiles/cushions', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '厨房用品',
                        'url_key' => 'home/kitchen',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '锅具', 'url_key' => 'home/kitchen/cookware', 'is_active' => 1],
                            ['name' => '刀具', 'url_key' => 'home/kitchen/knives', 'is_active' => 1],
                            ['name' => '餐具', 'url_key' => 'home/kitchen/dining', 'is_active' => 1],
                            ['name' => '杯具', 'url_key' => 'home/kitchen/cups', 'is_active' => 1],
                            ['name' => '收纳', 'url_key' => 'home/kitchen/storage', 'is_active' => 1],
                            ['name' => '厨房工具', 'url_key' => 'home/kitchen/kitchen-tools', 'is_active' => 1],
                            ['name' => '小家电', 'url_key' => 'home/kitchen/small-appliances', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '装饰用品',
                        'url_key' => 'home/decor',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '墙饰', 'url_key' => 'home/decor/wall-art', 'is_active' => 1],
                            ['name' => '花瓶', 'url_key' => 'home/decor/vases', 'is_active' => 1],
                            ['name' => '灯具', 'url_key' => 'home/decor/lamps', 'is_active' => 1],
                            ['name' => '镜子', 'url_key' => 'home/decor/mirrors', 'is_active' => 1],
                            ['name' => '绿植', 'url_key' => 'home/decor/plants', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            [
                'name' => '运动户外',
                'url_key' => 'sports',
                'is_active' => 1,
            ],
            [
                'name' => '图书音像',
                'url_key' => 'books',
                'is_active' => 1,
            ],
            [
                'name' => '食品饮料',
                'url_key' => 'food',
                'is_active' => 1,
            ],
        ];
    }
}
