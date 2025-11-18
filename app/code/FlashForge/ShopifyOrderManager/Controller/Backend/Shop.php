<?php

namespace FlashForge\ShopifyOrderManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Shop as ShopModel;
use FlashForge\ShopifyOrderManager\Helper\ShopifyApi;

/**
 * 店铺管理控制器
 */
#[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_manage', '店铺管理', '管理Shopify店铺配置', '')]
class Shop extends BackendController
{
    private ShopModel $shopModel;
    private ShopifyApi $shopifyApi;

    public function __init()
    {
        parent::__init();
        $this->shopModel = ObjectManager::getInstance(ShopModel::class);
        $this->shopifyApi = ObjectManager::getInstance(ShopifyApi::class);
    }

    /**
     * 店铺列表页面
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_list', '店铺列表', '', '查看店铺列表')]
    public function index()
    {
        $this->assign('title', '店铺管理');
        
        // 搜索功能
        if ($search = $this->request->getGet('search')) {
            $this->shopModel->where('concat(shop_name,shop_url,api_key)', "%$search%", 'like');
        }
        
        // 状态筛选
        if ($status = $this->request->getGet('status')) {
            $this->shopModel->where(ShopModel::fields_STATUS, $status);
        }
        
        // 排序和分页
        $this->shopModel->order(ShopModel::fields_CREATED_AT, 'DESC')
            ->pagination()
            ->select()
            ->fetch();
        
        // 分配数据到模板
        $this->assign('shops', $this->shopModel->getItems());
        $this->assign('pagination', $this->shopModel->getPagination());
        $this->assign('search', $search ?? '');
        $this->assign('status', $status ?? '');
        
        return $this->fetch();
    }

    /**
     * 获取店铺列表数据
     */
    public function getList()
    {
        try {
            $page = intval($this->request->getGet('page', 1));
            $limit = intval($this->request->getGet('limit', 20));

            $shops = $this->shopModel
                ->pagination($page, $limit)
                ->order(ShopModel::fields_ID, 'DESC')
                ->select()
                ->fetchArray();
            $total = $this->shopModel->total();

            return $this->fetchJson([
                'code' => 0,
                'msg' => '获取成功',
                'count' => $total,
                'data' => $shops
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '获取失败: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 获取单个店铺数据
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_edit', '编辑店铺', '', '编辑店铺信息')]
    public function getShop()
    {
        try {
            $shopId = intval($this->request->getGet('id'));
            
            if (!$shopId) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '店铺ID不能为空'
                ]);
            }

            $shop = $this->shopModel->where(ShopModel::fields_ID, $shopId)->find()->fetch();
            
            if (!$shop->getId()) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '店铺不存在'
                ]);
            }

            return $this->fetchJson([
                'code' => 0,
                'msg' => '获取成功',
                'data' => $shop->getData()
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 添加/编辑店铺表单页面
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_add', '添加店铺', '', '添加新店铺')]
    public function form()
    {
        $shopId = $this->request->getGet('id');
        $shop = null;
        
        if ($shopId) {
            $shop = $this->shopModel->load($shopId);
        }
        
        $this->assign('shop', $shop ? $shop->getData() : []);
        $this->assign('title', $shopId ? '编辑店铺' : '添加店铺');
        // OffCanvas 组件通过 iframe 加载指定模板，需要显式返回 form 模板
        return $this->fetch('form');
    }


    /**
     * 保存店铺
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_save', '保存店铺', '', '保存店铺信息')]
    public function postSave()
    {
        try {
            $data = $this->request->getPost();
            $shopId = intval($data['id'] ?? 0);

            // 验证必填字段
            $requiredFields = ['shop_name', 'shop_url', 'api_key', 'api_secret', 'access_token'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->fetchJson([
                        'code' => 1,
                        'msg' => "字段 {$field} 不能为空"
                    ]);
                }
            }

            // 验证店铺URL格式
            if (!filter_var($data['shop_url'], FILTER_VALIDATE_URL)) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '店铺URL格式不正确'
                ]);
            }

            // 测试API连接（即使测试失败也允许保存，但将店铺设为未启用并返回警告）
            $this->shopifyApi->init($data['shop_url'], $data['access_token']);
            $apiOk = true;
            try {
                if (!$this->shopifyApi->testConnection()) {
                    $apiOk = false;
                }
            } catch (\Exception $e) {
                $apiOk = false;
            }

            if ($shopId) {
                // 更新店铺
                $shop = $this->shopModel->where(ShopModel::fields_ID, $shopId)->find()->fetch();
                if (!$shop->getId()) {
                    return $this->fetchJson([
                        'code' => 1,
                        'msg' => '店铺不存在'
                    ]);
                }
            } else {
                // 新增店铺 - 检查 shop_url 是否已存在
                $existingShop = $this->shopModel->where(ShopModel::fields_SHOP_URL, rtrim($data['shop_url'], '/'))->find()->fetch();
                if ($existingShop->getId()) {
                    return $this->fetchJson([
                        'code' => 1,
                        'msg' => '店铺URL已存在，请使用不同的URL或编辑现有店铺'
                    ]);
                }
                $shop = new ShopModel();
            }

            $status = intval($data['status'] ?? 1);
            if (!$apiOk && !$shopId) {
                // 如果 API 链接失败且是新增店铺，安全起见默认保存为未启用
                $status = ShopModel::STATUS_INACTIVE;
            }

            $shop->setData([
                ShopModel::fields_NAME => $data['shop_name'],
                ShopModel::fields_SHOP_URL => rtrim($data['shop_url'], '/'),
                ShopModel::fields_API_KEY => $data['api_key'],
                ShopModel::fields_API_SECRET => $data['api_secret'],
                ShopModel::fields_ACCESS_TOKEN => $data['access_token'],
                ShopModel::fields_STATUS => $status
            ]);

            $shop->save();

            $msg = $shopId ? '更新成功' : '添加成功';
            if (!$apiOk && !$shopId) {
                $msg .= '（警告：API连接测试失败，店铺已保存为未启用）';
            }

            return $this->fetchJson([
                'code' => 0,
                'msg' => $msg
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '保存失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 切换店铺状态
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_toggle_status', '切换店铺状态', '', '启用或禁用店铺')]
    public function postToggleStatus()
    {
        try {
            $shopId = intval($this->request->getPost('id'));
            
            if (!$shopId) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '店铺ID不能为空'
                ]);
            }

            $shop = $this->shopModel->where(ShopModel::fields_ID, $shopId)->find()->fetch();
            
            if (!$shop->getId()) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '店铺不存在'
                ]);
            }

            // 切换状态
            $newStatus = $shop->getData(ShopModel::fields_STATUS) == 1 ? 0 : 1;
            $shop->setData(ShopModel::fields_STATUS, $newStatus);
            $shop->save();

            $statusText = $newStatus == 1 ? '启用' : '禁用';
            return $this->fetchJson([
                'code' => 0,
                'msg' => "店铺已{$statusText}"
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '状态切换失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 删除店铺
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_delete', '删除店铺', '', '删除店铺')]
    public function postDelete()
    {
        try {
            $shopId = intval($this->request->getBodyParam('id'));
            
            if (!$shopId) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '店铺ID不能为空'
                ]);
            }

            $shop = $this->shopModel->where(ShopModel::fields_ID, $shopId)->find()->fetch();
            
            if (!$shop->getId()) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '店铺不存在'
                ]);
            }

            $shop->delete()->fetch();

            return $this->fetchJson([
                'success' => true,
                'message' => '删除成功'
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => '删除失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 测试API连接
     */
    public function postTestApi()
    {
        try {
            $shopUrl = $this->request->getPost('shop_url');
            $accessToken = $this->request->getPost('access_token');

            if (empty($shopUrl) || empty($accessToken)) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '店铺URL和Access Token不能为空'
                ]);
            }

            $this->shopifyApi->init($shopUrl, $accessToken);
            $success = $this->shopifyApi->testConnection();

            if ($success) {
                // 获取API限制信息
                $rateLimitInfo = $this->shopifyApi->getRateLimitInfo();
                
                return $this->fetchJson([
                    'code' => 0,
                    'msg' => 'API连接测试成功',
                    'data' => $rateLimitInfo
                ]);
            } else {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => 'API连接测试失败，请检查配置'
                ]);
            }

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => 'API测试失败: ' . $e->getMessage()
            ]);
        }
    }

}
