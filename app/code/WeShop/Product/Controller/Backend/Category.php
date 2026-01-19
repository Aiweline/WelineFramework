<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/11 20:55:21
 */

namespace WeShop\Product\Controller\Backend;

use Weline\Eav\Model\EavAttribute\Set;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Catalog\Model\Category as CatalogCategory;
use WeShop\Catalog\Service\CategoryService;
use WeShop\Product\Model\Product;

class Category extends \Weline\Framework\App\Controller\BackendController
{
    private \WeShop\Catalog\Model\Category $category;
    private Product $product;
    private CategoryService $categoryService;

    public function __construct(
        \WeShop\Catalog\Model\Category $category,
        Product $product,
        CategoryService $categoryService
    )
    {
        $this->category = $category;
        $this->product = $product;
        $this->categoryService = $categoryService;
    }

    public function index()
    {
        // 先获取分页信息
        $categories = $this->category
            ->loadLocalDescription()
            ->pagination()
            ->select()
            ->fetch();
        
        # 为父分类添加名称
        // 为父分类添加名称（带缓存，避免重复查询）
        $parentCache = [];
        
        // 使用 fetchArray 获取数据，因为 getItems() 可能没有正确加载数据
        // 需要重新查询以获取数组数据
        $itemsArray = $this->category->reset()
            ->loadLocalDescription()
            ->pagination($categories->getPaginationData()['current_page'] ?? 1, $categories->getPaginationData()['page_size'] ?? 20)
            ->select()
            ->fetchArray();
        
        $items = [];
        
        foreach ($itemsArray as $row) {
            // 创建模型对象并设置数据
            $category = clone $this->category;
            $category->reset()->addData($row);
            
            $parentId = (int)($row['parent_id'] ?? 0);
            $categoryId = (int)($row['category_id'] ?? 0);
            
            // 字段映射：将 Catalog 模型字段映射到表单期望的字段
            $category->setData('pid', $parentId); // pid 用于列表显示
            $category->setData('position', (int)($row['sort_order'] ?? 0)); // position 用于列表显示
            $category->setData('create_time', $row['created_at'] ?? ''); // create_time 用于列表显示
            $category->setData('update_time', $row['updated_at'] ?? ''); // update_time 用于列表显示
            
            // 从 join 的 local 表中获取 local_name
            $localName = $row['local_name'] ?? '';
            // 如果 local_name 为空，使用主表的 name
            if (!$localName) {
                $localName = $row['name'] ?? '';
            }
            $category->setData('local_name', $localName);
            // 确保 name 字段也存在（用于模板回退）
            $category->setData('name', $row['name'] ?? '');
            
            // 处理父分类名称
            if ($parentId > 0) {
                if (!isset($parentCache[$parentId])) {
                    $parent = $this->category->reset()->loadLocalDescription()->load($parentId);
                    $parentLocalName = $parent->getData('local_name');
                    if (!$parentLocalName) {
                        $parentAllData = $parent->getData();
                        foreach ($parentAllData as $key => $value) {
                            if (($key === 'local_name' || strpos($key, 'local_') === 0) && $value) {
                                $parentLocalName = $value;
                                break;
                            }
                        }
                    }
                    $parentCache[$parentId] = $parent->getId() ? ($parentLocalName ?: $parent->getData(CatalogCategory::fields_NAME)) : __('无');
                }
                $category->setData('parent_name', $parentCache[$parentId]);
            } else {
                $category->setData('parent_name', __('无'));
            }
            
            $items[] = $category;
        }
        
        $this->assign('categories', $items);
        $this->assign('pagination', $categories->getPagination());
        return $this->fetch();
    }

    public function getSearch(): string
    {
        $id = $this->request->getGet('id', 0);
        $field = $this->request->getGet('field');
        $limit = $this->request->getGet('limit');
        $search = $this->request->getGet('search');
        $json = ['limit' => $limit, 'search' => $search];
        $this->category->loadLocalDescription();
        $this->category->where('main_table.category_id', $id, '!=', 'and');
        if ($field && $search) {
            $this->category->where('main_table.' . $field, "%{$search}%", 'like', 'or')
                ->where('local.' . $field, "%{$search}%", 'like');
            if ($limit) {
                $this->category->limit(1);
            } else {
                $this->category->limit(100);
            }
        } elseif (empty($field) && $search) {
            $this->category->where('main_table.name', "%{$search}%", 'like', 'or')
                ->where('local.name', "%{$search}%", 'like');
        }
        $attributes = $this->category->select()->fetchArray();
        $json['items'] = $attributes;
        return $this->fetchJson($json);
    }

    public function edit()
    {
        if ($this->request->isGet()) {
            $id = (int)$this->request->getGet('id', 0);
            
            // 检查根分类
            if ($id == 1) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('根分类不能修改'),
                    'reload' => 0
                ]);
                return;
            }
            
            if (!$id) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('参数错误！'),
                    'reload' => 0
                ]);
                return;
            }
            
            $this->assign('action', $this->request->getUrlBuilder()->getCurrentUrl());
            
            // 加载分类数据
            $category = $this->category->reset()->loadLocalDescription()->load($id);
            
            if (!$category->getId()) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('分类找不到！'),
                    'reload' => 0
                ]);
                return;
            }
            // 加载 EAV 属性（通过实体获取属性，然后通过属性模型获取值）
            $logFile = __DIR__ . '/../../../../../../var/log/category_edit_' . date('Y-m-d') . '.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            try {
                $logContent = "[" . date('Y-m-d H:i:s') . "] === 开始获取EAV属性 ===\n";
                $logContent .= "[" . date('Y-m-d H:i:s') . "] 分类ID: {$id}\n";
                $logContent .= "[" . date('Y-m-d H:i:s') . "] 分类实体ID: " . $category->getId() . "\n";
                $logContent .= "[" . date('Y-m-d H:i:s') . "] 分类实体代码: " . $category->getEntityCode() . "\n";
                $logContent .= "[" . date('Y-m-d H:i:s') . "] EAV实体ID: " . $category->getEavEntityId() . "\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
                
                // 1. 通过分类实体获取属性对象（getAttribute会自动关联当前实体）
                $attribute = $category->getAttribute('is_right_menu');
                $logContent = "[" . date('Y-m-d H:i:s') . "] 获取属性对象: " . ($attribute ? "存在" : "不存在") . "\n";
                if ($attribute) {
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] 属性ID: " . $attribute->getId() . "\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] 属性代码: " . $attribute->getCode() . "\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] 属性实体关联: " . ($attribute->current_getEntity() ? "已关联" : "未关联") . "\n";
                    if ($attribute->current_getEntity()) {
                        $logContent .= "[" . date('Y-m-d H:i:s') . "] 关联实体ID: " . $attribute->current_getEntity()->getId() . "\n";
                    }
                }
                file_put_contents($logFile, $logContent, FILE_APPEND);
                
                if ($attribute && $attribute->getId()) {
                    // 2. 通过属性模型的getValue方法获取值（getValue会自动使用关联的实体ID）
                    $isRightMenu = $attribute->getValue();
                    $logContent = "[" . date('Y-m-d H:i:s') . "] 获取属性值: " . var_export($isRightMenu, true) . "\n";
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                    
                    // getValue 可能返回字符串 '0' 或 '1'，需要转换为整数
                    if ($isRightMenu === '' || $isRightMenu === null || $isRightMenu === false) {
                        $isRightMenu = 0;
                    } else {
                        $isRightMenu = (int)$isRightMenu;
                    }
                    $logContent = "[" . date('Y-m-d H:i:s') . "] 转换后的值: {$isRightMenu}\n";
                } else {
                    $isRightMenu = 0;
                    $logContent = "[" . date('Y-m-d H:i:s') . "] 属性不存在，使用默认值: 0\n";
                }
                $category->setData('is_right_menu', $isRightMenu);
                $logContent .= "[" . date('Y-m-d H:i:s') . "] === EAV属性获取完成，最终值: {$isRightMenu} ===\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
            } catch (\Exception $e) {
                // 如果获取失败，记录日志并设为默认值
                $logContent = "[" . date('Y-m-d H:i:s') . "] EAV属性获取失败: " . $e->getMessage() . "\n";
                $logContent .= "[" . date('Y-m-d H:i:s') . "] 堆栈跟踪: " . $e->getTraceAsString() . "\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
                $category->setData('is_right_menu', 0);
            }
            
            // 字段映射：将 Catalog 模型字段映射到表单期望的字段
            $category->setData('pid', $category->getParentId()); // pid 用于表单
            $category->setData('position', $category->getData(CatalogCategory::fields_SORT_ORDER) ?: 0); // position 用于表单
            $category->setData('default_set_id', 0); // Catalog 模型没有 default_set_id，设为 0
            
            // 从 LocalDescription 中获取 Meta 字段（如果已加载）
            $localName = $category->getData('local_name');
            if (!$localName) {
                $allData = $category->getData();
                foreach ($allData as $key => $value) {
                    if (($key === 'local_name' || strpos($key, 'local_') === 0) && $value) {
                        $localName = $value;
                        break;
                    }
                }
            }
            if (!$localName) {
                $localName = $category->getData(CatalogCategory::fields_NAME);
            }
            $category->setData('local_name', $localName);
            $category->setData('name', $category->getData(CatalogCategory::fields_NAME));
            
            // 确保 category_id 字段存在
            $category->setData('category_id', $category->getId());
            
            // 处理父分类信息
            $parentId = $category->getParentId();
            if ($parentId > 0) {
                $parent = $this->category->reset()->loadLocalDescription()->load($parentId);
                $parentLocalName = $parent->getData('local_name');
                if (!$parentLocalName) {
                    $parentAllData = $parent->getData();
                    foreach ($parentAllData as $key => $value) {
                        if (($key === 'local_name' || strpos($key, 'local_') === 0) && $value) {
                            $parentLocalName = $value;
                            break;
                        }
                    }
                }
                $category->setData('parent_id', $parentId);
                $category->setData('parent_name', $parent->getId() ? ($parentLocalName ?: $parent->getData(CatalogCategory::fields_NAME)) : __('无'));
            } else {
                $category->setData('parent_id', 0);
                $category->setData('parent_name', __('无'));
            }
            
            // Meta 字段处理：从 LocalDescription 中获取
            // loadLocalDescription() 会 join local 表，但数据可能不会自动映射到顶层
            // 直接从 LocalDescription 模型获取，确保数据正确
            /** @var \WeShop\Catalog\Model\Category\LocalDescription $localDesc */
            $localDesc = ObjectManager::getInstance(\WeShop\Catalog\Model\Category\LocalDescription::class);
            $localDesc->clear()
                ->where(\WeShop\Catalog\Model\Category\LocalDescription::fields_ID, $category->getId())
                ->where('local_code', Cookie::getLang())
                ->find()
                ->fetch();
            
            if ($localDesc->getId()) {
                $metaTitle = $localDesc->getData('meta_title') ?: '';
                $metaKeywords = $localDesc->getData('meta_keywords') ?: '';
                $metaDescription = $localDesc->getData('meta_description') ?: '';
            } else {
                // 如果 LocalDescription 不存在，尝试从 join 后的数据中获取
                $metaTitle = $category->getData('meta_title') ?: '';
                $metaKeywords = $category->getData('meta_keywords') ?: '';
                $metaDescription = $category->getData('meta_description') ?: '';
            }
            
            $category->setData('meta_title', $metaTitle);
            $category->setData('meta_keywords', $metaKeywords);
            $category->setData('meta_description', $metaDescription);
            
            // 确保其他字段也存在
            $category->setData('description', $category->getData('description') ?: '');
            $category->setData('image', $category->getData('image') ?: '');
            $category->setData('is_active', $category->getData('is_active') ?: 1);
            
            // 调试：确保所有必要字段都已设置
            // category_id, name, pid, position, description, image, is_active, meta_title, meta_keywords, meta_description, is_right_menu
            
            $this->assign('category', $category);
            // 加载产品实体的所有属性集
            $sets = $this->product->eav_AttributeSetModel()->select()->fetchArray();
            $this->assign('attribute_sets', $sets);
            
            // 使用完整的模板路径
            return $this->fetch('WeShop_Product::templates/Backend/Category/form');
        }

        $category_data = $this->request->getPost();
        
        // 日志记录：记录接收到的 POST 数据
        $logFile = __DIR__ . '/../../../../../../var/log/category_edit_' . date('Y-m-d') . '.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logContent = "[" . date('Y-m-d H:i:s') . "] POST Data: " . json_encode($category_data, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($logFile, $logContent, FILE_APPEND);
        
        try {
            if (intval($category_data['category_id']) == 1) {
                $this->getMessageManager()->addWarning(__('根分类不能修改'));
            } else {
                $categoryId = (int)($category_data['category_id'] ?? 0);
                
                // 日志记录：开始保存
                $logContent = "[" . date('Y-m-d H:i:s') . "] 开始保存分类 ID: {$categoryId}\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
                
                // 字段映射：将表单字段映射到 Catalog 模型字段
                if (isset($category_data['pid'])) {
                    $category_data['parent_id'] = (int)$category_data['pid'];
                    unset($category_data['pid']);
                }
                if (isset($category_data['position'])) {
                    $category_data['sort_order'] = (int)$category_data['position'];
                    unset($category_data['position']);
                }
                // default_set_id 在 Catalog 模型中不存在，忽略
                if (isset($category_data['default_set_id'])) {
                    unset($category_data['default_set_id']);
                }
                
                // 提取 Meta 字段（这些字段保存在 LocalDescription 中）
                $metaTitle = $category_data['meta_title'] ?? '';
                $metaKeywords = $category_data['meta_keywords'] ?? '';
                $metaDescription = $category_data['meta_description'] ?? '';
                unset($category_data['meta_title'], $category_data['meta_keywords'], $category_data['meta_description']);
                
                // 日志记录：映射后的数据
                $logContent = "[" . date('Y-m-d H:i:s') . "] 映射后的数据: " . json_encode($category_data, JSON_UNESCAPED_UNICODE) . "\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
                
                // 加载现有分类
                $this->category->reset()->load($categoryId);
                if (!$this->category->getId()) {
                    $logContent = "[" . date('Y-m-d H:i:s') . "] 错误：分类不存在 ID: {$categoryId}\n";
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                    throw new \Exception(__('分类不存在！'));
                }
                
                $this->category->setModelData($category_data);
                
                // 日志记录：保存前的数据
                $logContent = "[" . date('Y-m-d H:i:s') . "] 保存前的分类数据: " . json_encode($this->category->getData(), JSON_UNESCAPED_UNICODE) . "\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
                
                $this->category->save();
                
                // 日志记录：保存后的 ID
                $savedId = $this->category->getId();
                $logContent = "[" . date('Y-m-d H:i:s') . "] 保存成功，分类 ID: {$savedId}\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
                
                // 保存 EAV 属性（参考队列模块的实现方式：通过实体获取属性，然后链式调用setValue）
                $isRightMenu = isset($category_data['is_right_menu']) ? (int)$category_data['is_right_menu'] : 0;
                $logContent = "[" . date('Y-m-d H:i:s') . "] === 开始保存EAV属性 ===\n";
                $logContent .= "[" . date('Y-m-d H:i:s') . "] 分类ID: {$savedId}\n";
                $logContent .= "[" . date('Y-m-d H:i:s') . "] 要保存的值: {$isRightMenu}\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
                
                try {
                    // 确保实体已加载（getAttribute需要实体有ID）
                    $this->category->load($savedId);
                    $logContent = "[" . date('Y-m-d H:i:s') . "] 实体已加载，实体ID: " . $this->category->getId() . "\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] 实体代码: " . $this->category->getEntityCode() . "\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] EAV实体ID: " . $this->category->getEavEntityId() . "\n";
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                    
                    // 获取属性并设置值（getAttribute会自动设置实体关联）
                    $attribute = $this->category->getAttribute('is_right_menu');
                    $logContent = "[" . date('Y-m-d H:i:s') . "] 获取属性对象: " . ($attribute ? "存在" : "不存在") . "\n";
                    if ($attribute) {
                        $logContent .= "[" . date('Y-m-d H:i:s') . "] 属性ID: " . $attribute->getId() . "\n";
                        $logContent .= "[" . date('Y-m-d H:i:s') . "] 属性代码: " . $attribute->getCode() . "\n";
                    }
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                    
                    if ($attribute && $attribute->getId()) {
                        // 确保属性对象关联了实体（getAttribute应该已经设置，但为了安全再次设置）
                        $attribute->current_setEntity($this->category);
                        $logContent = "[" . date('Y-m-d H:i:s') . "] 属性实体已关联\n";
                        file_put_contents($logFile, $logContent, FILE_APPEND);
                        
                        // 调用setValue保存
                        $attribute->setValue($savedId, $isRightMenu);
                        $logContent = "[" . date('Y-m-d H:i:s') . "] setValue调用完成\n";
                        
                        // 验证保存结果：查询数据库确认
                        $valueModel = $attribute->w_getValueModel();
                        $savedValue = $valueModel->reset()
                            ->where('entity_id', $savedId)
                            ->where('attribute_id', $attribute->getId())
                            ->find()
                            ->fetchArray();
                        $logContent .= "[" . date('Y-m-d H:i:s') . "] 数据库查询结果: " . json_encode($savedValue, JSON_UNESCAPED_UNICODE) . "\n";
                        
                        $logContent .= "[" . date('Y-m-d H:i:s') . "] EAV属性保存成功: is_right_menu = {$isRightMenu}\n";
                    } else {
                        $logContent = "[" . date('Y-m-d H:i:s') . "] 警告：EAV属性 is_right_menu 不存在\n";
                    }
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] === EAV属性保存完成 ===\n";
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                } catch (\Exception $e) {
                    $logContent = "[" . date('Y-m-d H:i:s') . "] EAV属性保存失败: " . $e->getMessage() . "\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] 堆栈跟踪: " . $e->getTraceAsString() . "\n";
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                    // EAV 属性保存失败不应该阻止整个保存流程，只记录日志
                }
                
                // 保存 Meta 字段到 LocalDescription
                if ($savedId) {
                    /** @var \WeShop\Catalog\Model\Category\LocalDescription $localDesc */
                    $localDesc = ObjectManager::getInstance(\WeShop\Catalog\Model\Category\LocalDescription::class);
                    $localDesc->clear()
                        ->where(\WeShop\Catalog\Model\Category\LocalDescription::fields_ID, $savedId)
                        ->where('local_code', Cookie::getLang())
                        ->find()
                        ->fetch();
                    
                    if ($localDesc->getId()) {
                        $localDesc->setData('meta_title', $metaTitle)
                            ->setData('meta_keywords', $metaKeywords)
                            ->setData('meta_description', $metaDescription)
                            ->save();
                        $logContent = "[" . date('Y-m-d H:i:s') . "] 更新 LocalDescription ID: {$localDesc->getId()}\n";
                    } else {
                        $localDesc->reset()
                            ->setData(\WeShop\Catalog\Model\Category\LocalDescription::fields_ID, $savedId)
                            ->setData('local_code', \Weline\Framework\Http\Cookie::getLang())
                            ->setData('name', $this->category->getData(CatalogCategory::fields_NAME))
                            ->setData('meta_title', $metaTitle)
                            ->setData('meta_keywords', $metaKeywords)
                            ->setData('meta_description', $metaDescription)
                            ->save();
                        $logContent = "[" . date('Y-m-d H:i:s') . "] 创建 LocalDescription ID: {$localDesc->getId()}\n";
                    }
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                }
                
                // 验证保存后的数据
                $this->category->reset()->load($savedId);
                $savedData = $this->category->getData();
                $logContent = "[" . date('Y-m-d H:i:s') . "] 保存后重新加载的数据: " . json_encode($savedData, JSON_UNESCAPED_UNICODE) . "\n";
                file_put_contents($logFile, $logContent, FILE_APPEND);
                
                $this->getMessageManager()->addSuccess(__('分类保存成功！'));
                
                // 如果是 AJAX 请求（OffCanvas 表单提交），返回 JSON 并刷新 iframe
                if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                    $logContent = "[" . date('Y-m-d H:i:s') . "] AJAX请求，返回JSON响应\n";
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                    
                    // 重新加载分类数据用于回填
                    $this->category->reset()->loadLocalDescription()->load($savedId);
                    
                    // 加载 EAV 属性（通过实体获取属性，然后通过属性模型获取值）
                    $logContent = "[" . date('Y-m-d H:i:s') . "] === AJAX响应：开始获取EAV属性 ===\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] 分类ID: {$savedId}\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] 分类实体ID: " . $this->category->getId() . "\n";
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                    
                    try {
                        // 1. 通过分类实体获取属性对象（getAttribute会自动关联当前实体）
                        $attribute = $this->category->getAttribute('is_right_menu');
                        $logContent = "[" . date('Y-m-d H:i:s') . "] 获取属性对象: " . ($attribute ? "存在" : "不存在") . "\n";
                        if ($attribute) {
                            $logContent .= "[" . date('Y-m-d H:i:s') . "] 属性ID: " . $attribute->getId() . "\n";
                        }
                        file_put_contents($logFile, $logContent, FILE_APPEND);
                        
                        if ($attribute && $attribute->getId()) {
                            // 2. 通过属性模型的getValue方法获取值（getValue会自动使用关联的实体ID）
                            $isRightMenu = $attribute->getValue();
                            $logContent = "[" . date('Y-m-d H:i:s') . "] 获取属性值: " . var_export($isRightMenu, true) . "\n";
                            file_put_contents($logFile, $logContent, FILE_APPEND);
                            
                            // 验证：直接查询数据库
                            $valueModel = $attribute->w_getValueModel();
                            $dbValue = $valueModel->reset()
                                ->where('entity_id', $savedId)
                                ->where('attribute_id', $attribute->getId())
                                ->find()
                                ->fetchArray();
                            $logContent = "[" . date('Y-m-d H:i:s') . "] 数据库直接查询结果: " . json_encode($dbValue, JSON_UNESCAPED_UNICODE) . "\n";
                            file_put_contents($logFile, $logContent, FILE_APPEND);
                            
                            if ($isRightMenu === '' || $isRightMenu === null || $isRightMenu === false) {
                                $isRightMenu = 0;
                            } else {
                                $isRightMenu = (int)$isRightMenu;
                            }
                            $logContent = "[" . date('Y-m-d H:i:s') . "] 转换后的值: {$isRightMenu}\n";
                        } else {
                            $isRightMenu = 0;
                            $logContent = "[" . date('Y-m-d H:i:s') . "] 属性不存在，使用默认值: 0\n";
                        }
                        $this->category->setData('is_right_menu', $isRightMenu);
                        $logContent .= "[" . date('Y-m-d H:i:s') . "] === AJAX响应：EAV属性获取完成，最终值: {$isRightMenu} ===\n";
                        file_put_contents($logFile, $logContent, FILE_APPEND);
                    } catch (\Exception $e) {
                        $logContent = "[" . date('Y-m-d H:i:s') . "] AJAX响应：EAV属性获取失败: " . $e->getMessage() . "\n";
                        $logContent .= "[" . date('Y-m-d H:i:s') . "] 堆栈跟踪: " . $e->getTraceAsString() . "\n";
                        file_put_contents($logFile, $logContent, FILE_APPEND);
                        $this->category->setData('is_right_menu', 0);
                    }
                    
                    // 字段映射
                    $this->category->setData('pid', $this->category->getParentId());
                    $this->category->setData('position', $this->category->getData(CatalogCategory::fields_SORT_ORDER) ?: 0);
                    $this->category->setData('category_id', $this->category->getId());
                    $this->category->setData('name', $this->category->getData(CatalogCategory::fields_NAME));
                    
                    // 加载 Meta 字段
                    $localDesc = ObjectManager::getInstance(\WeShop\Catalog\Model\Category\LocalDescription::class);
                    $localDesc->clear()
                        ->where(\WeShop\Catalog\Model\Category\LocalDescription::fields_ID, $savedId)
                        ->where('local_code', Cookie::getLang())
                        ->find()
                        ->fetch();
                    
                    if ($localDesc->getId()) {
                        $this->category->setData('meta_title', $localDesc->getData('meta_title') ?: '');
                        $this->category->setData('meta_keywords', $localDesc->getData('meta_keywords') ?: '');
                        $this->category->setData('meta_description', $localDesc->getData('meta_description') ?: '');
                    } else {
                        $this->category->setData('meta_title', '');
                        $this->category->setData('meta_keywords', '');
                        $this->category->setData('meta_description', '');
                    }
                    
                    // 记录最终回填数据
                    $finalData = $this->category->getData();
                    $logContent = "[" . date('Y-m-d H:i:s') . "] === AJAX响应：最终回填数据 ===\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] " . json_encode($finalData, JSON_UNESCAPED_UNICODE) . "\n";
                    $logContent .= "[" . date('Y-m-d H:i:s') . "] is_right_menu值: " . ($this->category->getData('is_right_menu') ?? '未设置') . "\n";
                    file_put_contents($logFile, $logContent, FILE_APPEND);
                    
                    return $this->fetchJson([
                        'success' => true,
                        'message' => __('分类保存成功！'),
                        'reload' => true,
                        'url' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/category/edit', ['id' => $savedId])
                    ]);
                }
            }
        } catch (\Exception $e) {
            $logContent = "[" . date('Y-m-d H:i:s') . "] 保存失败: " . $e->getMessage() . "\n";
            $logContent .= "[" . date('Y-m-d H:i:s') . "] 堆栈跟踪: " . $e->getTraceAsString() . "\n";
            file_put_contents($logFile, $logContent, FILE_APPEND);
            
            $this->getMessageManager()->addError(__('分类保存失败！请检查参数！'));
            $this->assign('category', $category_data);
            if (DEV || DEBUG) {
                $this->getMessageManager()->addException($e);
            }
            
            // 如果是 AJAX 请求，返回 JSON 错误
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('分类保存失败！') . ($e->getMessage() ? ': ' . $e->getMessage() : '')
                ]);
            }
        }
        
        $redirectId = (int)($category_data['category_id'] ?? 0);
        $logContent = "[" . date('Y-m-d H:i:s') . "] 重定向到编辑页面，ID: {$redirectId}\n";
        file_put_contents($logFile, $logContent, FILE_APPEND);
        
        $this->redirect('*/backend/category/edit', ['id' => $redirectId]);
    }

    public function add()
    {
        if ($this->request->isGet()) {
            $this->assign('action', $this->request->getUrlBuilder()->getCurrentUrl());
            // 加载产品实体的所有属性集
            $sets = $this->product->eav_AttributeSetModel()->select()->fetchArray();
            $this->assign('attribute_sets', $sets);
            return $this->fetch('form');
        }

        $category_data = $this->request->getPost();
        try {
            // 字段映射：将表单字段映射到 Catalog 模型字段
            if (isset($category_data['pid'])) {
                $category_data['parent_id'] = (int)$category_data['pid'];
                unset($category_data['pid']);
            }
            if (isset($category_data['position'])) {
                $category_data['sort_order'] = (int)$category_data['position'];
                unset($category_data['position']);
            }
            // default_set_id 在 Catalog 模型中不存在，忽略
            if (isset($category_data['default_set_id'])) {
                unset($category_data['default_set_id']);
            }
            
            // 提取 Meta 字段（这些字段保存在 LocalDescription 中）
            $metaTitle = $category_data['meta_title'] ?? '';
            $metaKeywords = $category_data['meta_keywords'] ?? '';
            $metaDescription = $category_data['meta_description'] ?? '';
            unset($category_data['meta_title'], $category_data['meta_keywords'], $category_data['meta_description']);
            
            $this->category->reset()->setModelData($category_data);
            
            $this->category->save();
            
            // 保存 EAV 属性（参考队列模块的实现方式：通过实体获取属性，然后链式调用setValue）
            $savedId = $this->category->getId();
            if ($savedId) {
                $isRightMenu = isset($category_data['is_right_menu']) ? (int)$category_data['is_right_menu'] : 0;
                try {
                    // 参考队列模块：$this->queue->getAttribute($attribute['code'])->setValue($queue_id, $attribute['value']);
                    // 确保实体已加载（getAttribute需要实体有ID）
                    $this->category->load($savedId);
                    // 获取属性并设置值（getAttribute会自动设置实体关联）
                    $attribute = $this->category->getAttribute('is_right_menu');
                    if ($attribute && $attribute->getId()) {
                        // 确保属性对象关联了实体（getAttribute应该已经设置，但为了安全再次设置）
                        $attribute->current_setEntity($this->category);
                        $attribute->setValue($savedId, $isRightMenu);
                    }
                } catch (\Exception $e) {
                    // EAV 属性保存失败不应该阻止整个保存流程，只记录日志
                    if (DEV || DEBUG) {
                        $this->getMessageManager()->addWarning(__('EAV属性保存失败：%{1}', $e->getMessage()));
                    }
                }
            }
            
            // 保存 Meta 字段到 LocalDescription
            if ($this->category->getId()) {
                /** @var \WeShop\Catalog\Model\Category\LocalDescription $localDesc */
                $localDesc = ObjectManager::getInstance(\WeShop\Catalog\Model\Category\LocalDescription::class);
                $localDesc->clear()
                    ->where(\WeShop\Catalog\Model\Category\LocalDescription::fields_ID, $this->category->getId())
                    ->where('local_code', \Weline\Framework\Http\Cookie::getLang())
                    ->find()
                    ->fetch();
                
                if ($localDesc->getId()) {
                    $localDesc->setData('meta_title', $metaTitle)
                        ->setData('meta_keywords', $metaKeywords)
                        ->setData('meta_description', $metaDescription)
                        ->save();
                } else {
                    $localDesc->reset()
                        ->setData(\WeShop\Catalog\Model\Category\LocalDescription::fields_ID, $this->category->getId())
                        ->setData('local_code', \Weline\Framework\Http\Cookie::getLang())
                        ->setData('name', $this->category->getData(CatalogCategory::fields_NAME))
                        ->setData('meta_title', $metaTitle)
                        ->setData('meta_keywords', $metaKeywords)
                        ->setData('meta_description', $metaDescription)
                        ->save();
                }
            }
            $this->getMessageManager()->addSuccess(__('分类保存成功！'));
            $this->redirect('*/backend/category/edit', ['id' => $this->category->getId()]);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('分类保存失败！请检查参数！'));
            $this->assign('category', $category_data);
            if (DEV || DEBUG) {
                $this->getMessageManager()->addException($e);
            }
        }
        $this->redirect('*/backend/category/add');
    }

    public function getDelete()
    {
        $id = $this->request->getGet('id', 0);
        $this->category->load($id);
        if (!$this->category->getId()) {
            $this->getMessageManager()->addWarning(__('该分类不存在！'));
            $this->redirect('*/backend/category');
        }

        try {
            $this->category->delete();
            $this->getMessageManager()->addSuccess(__('分类删除成功！'));
        } catch (\ReflectionException|Exception|Core $e) {
            $this->getMessageManager()->addException($e);
        }
        $this->redirect('*/backend/category');
    }

    /**
     * 获取分类的默认属性集
     * @return string
     */
    public function getGetDefaultSet(): string
    {
        $categoryId = (int)$this->request->getGet('category_id', 0);
        $result = ['default_set_id' => 0, 'set_name' => ''];
        
        if ($categoryId > 0) {
            $category = $this->category->reset()->load($categoryId);
            if ($category->getId()) {
                $defaultSetId = $category->getDefaultSetId();
                if ($defaultSetId > 0) {
                    $set = $category->getDefaultSet();
                    $result = [
                        'default_set_id' => $defaultSetId,
                        'set_name' => $set ? $set->getName() : ''
                    ];
                }
            }
        }
        
        return $this->fetchJson($result);
    }
}
