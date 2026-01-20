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
            
            // 从完整路径中提取最后一部分作为handle
            $handle = $catData['handle'] ?? '';
            if (strpos($handle, '/') !== false) {
                $handleParts = explode('/', $handle);
                $handle = end($handleParts);
            }
            
            // 如果没有提供handle，则生成
            if (empty($handle)) {
                $handle = $this->generateHandle($catData['name']);
            }
            
            // 检查分类是否已存在（通过handle和parent_id）
            $existing = $category->clear()
                ->where(Category::fields_HANDLE, $handle)
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
                    ->setData(Category::fields_HANDLE, $handle)
                    ->setData(Category::fields_PARENT_ID, $parentId)
                    ->setData(Category::fields_SORT_ORDER, $sortOrder)
                    ->setData(Category::fields_IS_ACTIVE, $catData['is_active'] ?? 1)
                    ->setData(Category::fields_DESCRIPTION, $catData['description'] ?? '');
                
                $saveResult = $category->save();
                
                // 验证保存结果
                if ($saveResult === false || $saveResult === null) {
                    throw new \Exception(__('保存分类失败: %{1} (parent_id: %{2})', [$catData['name'], $parentId]));
                }
                
                $categoryId = (int)$category->getId();
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
                // 确保 $categoryId 是 int 类型
                $insertedCount += $this->insertCategories($category, $catData['children'], (int)$categoryId, 0);
            }

            $sortOrder++;
        }

        return $insertedCount;
    }

    /**
     * 生成Handle标识
     */
    private function generateHandle(string $name): string
    {
        // 简单的Handle标识生成（实际应该使用更完善的转换函数）
        $handle = strtolower($name);
        $handle = preg_replace('/[^a-z0-9]+/', '-', $handle);
        $handle = trim($handle, '-');
        return $handle;
    }

    /**
     * 获取默认分类数据
     */
    private function getDefaultCategories(): array
    {
        return [
            [
                'name' => '电子产品',
                'handle' => 'electronics',
                'is_active' => 1,
                'children' => [
                    [
                        'name' => '手机通讯',
                        'handle' => 'electronics/phones',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '智能手机', 'handle' => 'electronics/phones/smartphones', 'is_active' => 1],
                            ['name' => '功能手机', 'handle' => 'electronics/phones/feature-phones', 'is_active' => 1],
                            ['name' => '5G手机', 'handle' => 'electronics/phones/5g-phones', 'is_active' => 1],
                            ['name' => '折叠手机', 'handle' => 'electronics/phones/foldable', 'is_active' => 1],
                            ['name' => '手机壳', 'handle' => 'electronics/phones/cases', 'is_active' => 1],
                            ['name' => '屏幕保护膜', 'handle' => 'electronics/phones/screen-protectors', 'is_active' => 1],
                            ['name' => '充电器', 'handle' => 'electronics/phones/chargers', 'is_active' => 1],
                            ['name' => '数据线', 'handle' => 'electronics/phones/cables', 'is_active' => 1],
                            ['name' => '移动电源', 'handle' => 'electronics/phones/power-banks', 'is_active' => 1],
                            ['name' => '手机耳机', 'handle' => 'electronics/phones/headphones', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '电脑办公',
                        'handle' => 'electronics/computers',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '笔记本', 'handle' => 'electronics/computers/laptops', 'is_active' => 1],
                            ['name' => '游戏本', 'handle' => 'electronics/computers/gaming-laptops', 'is_active' => 1],
                            ['name' => '超极本', 'handle' => 'electronics/computers/ultrabooks', 'is_active' => 1],
                            ['name' => '台式机', 'handle' => 'electronics/computers/desktops', 'is_active' => 1],
                            ['name' => '一体机', 'handle' => 'electronics/computers/all-in-one', 'is_active' => 1],
                            ['name' => '平板电脑', 'handle' => 'electronics/computers/tablets', 'is_active' => 1],
                            ['name' => '显示器', 'handle' => 'electronics/computers/monitors', 'is_active' => 1],
                            ['name' => '键盘', 'handle' => 'electronics/computers/keyboards', 'is_active' => 1],
                            ['name' => '鼠标', 'handle' => 'electronics/computers/mice', 'is_active' => 1],
                            ['name' => '摄像头', 'handle' => 'electronics/computers/webcams', 'is_active' => 1],
                            ['name' => '存储设备', 'handle' => 'electronics/computers/storage', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '智能设备',
                        'handle' => 'electronics/smart-devices',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '智能手表', 'handle' => 'electronics/smart-devices/smart-watches', 'is_active' => 1],
                            ['name' => '运动手环', 'handle' => 'electronics/smart-devices/fitness-trackers', 'is_active' => 1],
                            ['name' => '智能音箱', 'handle' => 'electronics/smart-devices/smart-speakers', 'is_active' => 1],
                            ['name' => '智能照明', 'handle' => 'electronics/smart-devices/smart-lights', 'is_active' => 1],
                            ['name' => '智能门锁', 'handle' => 'electronics/smart-devices/smart-locks', 'is_active' => 1],
                            ['name' => '智能摄像头', 'handle' => 'electronics/smart-devices/smart-cameras', 'is_active' => 1],
                            ['name' => '智能家居套装', 'handle' => 'electronics/smart-devices/smart-home', 'is_active' => 1],
                            ['name' => '可穿戴设备', 'handle' => 'electronics/smart-devices/wearables', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '影音娱乐',
                        'handle' => 'electronics/audio-video',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '无线耳机', 'handle' => 'electronics/audio-video/wireless-headphones', 'is_active' => 1],
                            ['name' => '有线耳机', 'handle' => 'electronics/audio-video/wired-headphones', 'is_active' => 1],
                            ['name' => '入耳式耳机', 'handle' => 'electronics/audio-video/earbuds', 'is_active' => 1],
                            ['name' => '蓝牙音响', 'handle' => 'electronics/audio-video/speakers', 'is_active' => 1],
                            ['name' => 'Soundbar', 'handle' => 'electronics/audio-video/soundbars', 'is_active' => 1],
                            ['name' => '单反相机', 'handle' => 'electronics/audio-video/dslr-cameras', 'is_active' => 1],
                            ['name' => '微单相机', 'handle' => 'electronics/audio-video/mirrorless', 'is_active' => 1],
                            ['name' => '运动相机', 'handle' => 'electronics/audio-video/action-cameras', 'is_active' => 1],
                            ['name' => '游戏主机', 'handle' => 'electronics/audio-video/gaming-consoles', 'is_active' => 1],
                            ['name' => '游戏配件', 'handle' => 'electronics/audio-video/gaming-accessories', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            [
                'name' => '服装服饰',
                'handle' => 'clothing',
                'is_active' => 1,
                'children' => [
                    [
                        'name' => '男装',
                        'handle' => 'clothing/men',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '衬衫', 'handle' => 'clothing/men/shirts', 'is_active' => 1],
                            ['name' => 'T恤', 'handle' => 'clothing/men/t-shirts', 'is_active' => 1],
                            ['name' => 'Polo衫', 'handle' => 'clothing/men/polo-shirts', 'is_active' => 1],
                            ['name' => '长裤', 'handle' => 'clothing/men/pants', 'is_active' => 1],
                            ['name' => '短裤', 'handle' => 'clothing/men/shorts', 'is_active' => 1],
                            ['name' => '外套', 'handle' => 'clothing/men/jackets', 'is_active' => 1],
                            ['name' => '毛衣', 'handle' => 'clothing/men/sweaters', 'is_active' => 1],
                            ['name' => '皮鞋', 'handle' => 'clothing/men/shoes', 'is_active' => 1],
                            ['name' => '运动鞋', 'handle' => 'clothing/men/sneakers', 'is_active' => 1],
                            ['name' => '皮带', 'handle' => 'clothing/men/belts', 'is_active' => 1],
                            ['name' => '手表', 'handle' => 'clothing/men/watches', 'is_active' => 1],
                            ['name' => '包袋', 'handle' => 'clothing/men/bags', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '女装',
                        'handle' => 'clothing/women',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '连衣裙', 'handle' => 'clothing/women/dresses', 'is_active' => 1],
                            ['name' => '上装', 'handle' => 'clothing/women/tops', 'is_active' => 1],
                            ['name' => '衬衫', 'handle' => 'clothing/women/blouses', 'is_active' => 1],
                            ['name' => '下装', 'handle' => 'clothing/women/bottoms', 'is_active' => 1],
                            ['name' => '半身裙', 'handle' => 'clothing/women/skirts', 'is_active' => 1],
                            ['name' => '外套', 'handle' => 'clothing/women/outerwear', 'is_active' => 1],
                            ['name' => '大衣', 'handle' => 'clothing/women/coats', 'is_active' => 1],
                            ['name' => '高跟鞋', 'handle' => 'clothing/women/heels', 'is_active' => 1],
                            ['name' => '平底鞋', 'handle' => 'clothing/women/flats', 'is_active' => 1],
                            ['name' => '靴子', 'handle' => 'clothing/women/boots', 'is_active' => 1],
                            ['name' => '手提包', 'handle' => 'clothing/women/handbags', 'is_active' => 1],
                            ['name' => '斜挎包', 'handle' => 'clothing/women/crossbody-bags', 'is_active' => 1],
                            ['name' => '首饰', 'handle' => 'clothing/women/jewelry', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '童装',
                        'handle' => 'clothing/kids',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '男童装', 'handle' => 'clothing/kids/boys', 'is_active' => 1],
                            ['name' => '女童装', 'handle' => 'clothing/kids/girls', 'is_active' => 1],
                            ['name' => '婴儿装', 'handle' => 'clothing/kids/baby', 'is_active' => 1],
                            ['name' => '童鞋', 'handle' => 'clothing/kids/shoes', 'is_active' => 1],
                            ['name' => '童装配饰', 'handle' => 'clothing/kids/accessories', 'is_active' => 1],
                            ['name' => '校服', 'handle' => 'clothing/kids/school-uniforms', 'is_active' => 1],
                            ['name' => '泳装', 'handle' => 'clothing/kids/swimwear', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '运动服饰',
                        'handle' => 'clothing/sports',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '运动装', 'handle' => 'clothing/sports/athletic-wear', 'is_active' => 1],
                            ['name' => '跑步装备', 'handle' => 'clothing/sports/running-gear', 'is_active' => 1],
                            ['name' => '健身服', 'handle' => 'clothing/sports/fitness-wear', 'is_active' => 1],
                            ['name' => '运动鞋', 'handle' => 'clothing/sports/sports-shoes', 'is_active' => 1],
                            ['name' => '跑鞋', 'handle' => 'clothing/sports/running-shoes', 'is_active' => 1],
                            ['name' => '篮球鞋', 'handle' => 'clothing/sports/basketball-shoes', 'is_active' => 1],
                            ['name' => '户外装备', 'handle' => 'clothing/sports/outdoor', 'is_active' => 1],
                            ['name' => '露营装备', 'handle' => 'clothing/sports/camping', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            [
                'name' => '家居用品',
                'handle' => 'home',
                'is_active' => 1,
                'children' => [
                    [
                        'name' => '家具',
                        'handle' => 'home/furniture',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '沙发', 'handle' => 'home/furniture/sofas', 'is_active' => 1],
                            ['name' => '沙发床', 'handle' => 'home/furniture/sofa-beds', 'is_active' => 1],
                            ['name' => '餐桌', 'handle' => 'home/furniture/tables', 'is_active' => 1],
                            ['name' => '茶几', 'handle' => 'home/furniture/coffee-tables', 'is_active' => 1],
                            ['name' => '椅子', 'handle' => 'home/furniture/chairs', 'is_active' => 1],
                            ['name' => '办公椅', 'handle' => 'home/furniture/office-chairs', 'is_active' => 1],
                            ['name' => '床具', 'handle' => 'home/furniture/beds', 'is_active' => 1],
                            ['name' => '衣柜', 'handle' => 'home/furniture/wardrobes', 'is_active' => 1],
                            ['name' => '书柜', 'handle' => 'home/furniture/bookcases', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '家纺',
                        'handle' => 'home/textiles',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '床上用品', 'handle' => 'home/textiles/bedding', 'is_active' => 1],
                            ['name' => '床单被套', 'handle' => 'home/textiles/sheets', 'is_active' => 1],
                            ['name' => '枕头', 'handle' => 'home/textiles/pillows', 'is_active' => 1],
                            ['name' => '被子', 'handle' => 'home/textiles/quilts', 'is_active' => 1],
                            ['name' => '窗帘', 'handle' => 'home/textiles/curtains', 'is_active' => 1],
                            ['name' => '毛巾', 'handle' => 'home/textiles/towels', 'is_active' => 1],
                            ['name' => '地毯', 'handle' => 'home/textiles/rugs', 'is_active' => 1],
                            ['name' => '靠垫', 'handle' => 'home/textiles/cushions', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '厨房用品',
                        'handle' => 'home/kitchen',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '锅具', 'handle' => 'home/kitchen/cookware', 'is_active' => 1],
                            ['name' => '刀具', 'handle' => 'home/kitchen/knives', 'is_active' => 1],
                            ['name' => '餐具', 'handle' => 'home/kitchen/dining', 'is_active' => 1],
                            ['name' => '杯具', 'handle' => 'home/kitchen/cups', 'is_active' => 1],
                            ['name' => '收纳', 'handle' => 'home/kitchen/storage', 'is_active' => 1],
                            ['name' => '厨房工具', 'handle' => 'home/kitchen/kitchen-tools', 'is_active' => 1],
                            ['name' => '小家电', 'handle' => 'home/kitchen/small-appliances', 'is_active' => 1],
                        ]
                    ],
                    [
                        'name' => '装饰用品',
                        'handle' => 'home/decor',
                        'is_active' => 1,
                        'children' => [
                            ['name' => '墙饰', 'handle' => 'home/decor/wall-art', 'is_active' => 1],
                            ['name' => '花瓶', 'handle' => 'home/decor/vases', 'is_active' => 1],
                            ['name' => '灯具', 'handle' => 'home/decor/lamps', 'is_active' => 1],
                            ['name' => '镜子', 'handle' => 'home/decor/mirrors', 'is_active' => 1],
                            ['name' => '绿植', 'handle' => 'home/decor/plants', 'is_active' => 1],
                        ]
                    ],
                ]
            ],
            [
                'name' => '运动户外',
                'handle' => 'sports',
                'is_active' => 1,
            ],
            [
                'name' => '图书音像',
                'handle' => 'books',
                'is_active' => 1,
            ],
            [
                'name' => '食品饮料',
                'handle' => 'food',
                'is_active' => 1,
            ],
        ];
    }
}
