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
     * 添加店铺页面
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_add', '添加店铺', '', '添加新店铺')]
    public function add()
    {
        $this->assign('title', '添加店铺');
        return $this->fetch();
    }

    /**
     * 编辑店铺页面
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_edit', '编辑店铺', '', '编辑店铺信息')]
    public function edit()
    {
        $shopId = intval($this->request->getGet('id'));
        
        if (!$shopId) {
            $this->getMessageManager()->addError('店铺ID不能为空');
            return $this->redirect('*/*/index');
        }

        $shop = $this->shopModel->where(ShopModel::fields_ID, $shopId)->find()->fetch();
        
        if (!$shop->getId()) {
            $this->getMessageManager()->addError('店铺不存在');
            return $this->redirect('*/*/index');
        }

        $this->assign('shop', $shop->getData());
        $this->assign('title', '编辑店铺');
        return $this->fetch();
    }

    /**
     * 保存店铺
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::shop_save', '保存店铺', '', '保存店铺信息')]
    public function postSave()
    {
        try {
            $data = $this->request->getPost();
            $shopId = intval($data['shop_id'] ?? 0);

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

            // 测试API连接
            $this->shopifyApi->init($data['shop_url'], $data['access_token']);
            if (!$this->shopifyApi->testConnection()) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => 'API连接测试失败，请检查店铺配置'
                ]);
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
                // 新增店铺
                $shop = new ShopModel();
            }

            $shop->setData([
                ShopModel::fields_NAME => $data['shop_name'],
                ShopModel::fields_SHOP_URL => rtrim($data['shop_url'], '/'),
                ShopModel::fields_API_KEY => $data['api_key'],
                ShopModel::fields_API_SECRET => $data['api_secret'],
                ShopModel::fields_ACCESS_TOKEN => $data['access_token'],
                ShopModel::fields_STATUS => intval($data['status'] ?? 1)
            ]);

            $shop->save();

            return $this->fetchJson([
                'code' => 0,
                'msg' => $shopId ? '更新成功' : '添加成功'
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '保存失败: ' . $e->getMessage()
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

            $shop->delete();

            return $this->fetchJson([
                'code' => 0,
                'msg' => '删除成功'
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '删除失败: ' . $e->getMessage()
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

    /**
     * 切换店铺状态
     */
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

            $currentStatus = $shop->getData(ShopModel::fields_STATUS);
            $newStatus = $currentStatus ? ShopModel::STATUS_INACTIVE : ShopModel::STATUS_ACTIVE;

            $shop->setData(ShopModel::fields_STATUS, $newStatus);
            $shop->save();

            return $this->fetchJson([
                'code' => 0,
                'msg' => '状态更新成功',
                'data' => ['status' => $newStatus]
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '状态更新失败: ' . $e->getMessage()
            ]);
        }
    }
}
