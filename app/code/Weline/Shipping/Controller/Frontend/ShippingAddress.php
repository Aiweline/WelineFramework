<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\ShippingAddressService;

/**
 * 发货地址前端控制器
 */
class ShippingAddress extends FrontendController
{
    protected ?string $layoutType = 'account.dashboard';
    
    private ShippingAddressService $service;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->service = $objectManager->getInstance(ShippingAddressService::class);
    }

    /**
     * 地址列表页
     */
    public function getIndex()
    {
        $filters = [];
        if ($this->request->getParam('keyword')) {
            $filters['keyword'] = $this->request->getParam('keyword');
        }
        
        $addresses = $this->service->getList($filters);
        $this->assign('addresses', $addresses);
        return $this->fetch();
    }

    /**
     * 地址表单页（新增/编辑）
     */
    public function getForm()
    {
        $id = $this->request->getParam('id');
        $address = null;
        
        if ($id) {
            $address = $this->service->getById((int)$id);
            if (!$address) {
                $this->getMessageManager()->addError(__('地址不存在'));
                $this->redirect('*/index');
                return;
            }
        }
        
        $this->assign('address', $address);
        return $this->fetch();
    }

    /**
     * 保存地址
     */
    public function postSave()
    {
        $data = $this->request->getPost();
        $id = $data['shipping_address_id'] ?? null;
        
        try {
            if ($id) {
                $address = $this->service->update((int)$id, $data);
                $message = __('更新成功');
            } else {
                $address = $this->service->create($data);
                $message = __('创建成功');
            }
            
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => true,
                    'message' => $message,
                    'data' => $address->getData()
                ]);
            }
            
            $this->getMessageManager()->addSuccess($message);
            $this->redirect('*/index');
        } catch (\Exception $e) {
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
            }
            
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/form' . ($id ? '?id=' . $id : ''));
        }
    }

    /**
     * 删除地址
     */
    public function postDelete()
    {
        $id = $this->request->getPost('id');
        
        if (!$id) {
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => false,
                    'message' => __('参数错误')
                ]);
            }
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $this->service->delete((int)$id);
            
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => true,
                    'message' => __('删除成功')
                ]);
            }
            
            $this->getMessageManager()->addSuccess(__('删除成功'));
            $this->redirect('*/index');
        } catch (\Exception $e) {
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
            }
            
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/index');
        }
    }

    /**
     * 设置默认地址
     */
    public function postSetDefault()
    {
        $id = $this->request->getPost('id');
        
        if (!$id) {
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => false,
                    'message' => __('参数错误')
                ]);
            }
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $this->service->setDefault((int)$id);
            
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => true,
                    'message' => __('设置成功')
                ]);
            }
            
            $this->getMessageManager()->addSuccess(__('设置成功'));
            $this->redirect('*/index');
        } catch (\Exception $e) {
            if ($this->request->isAjax()) {
                return $this->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
            }
            
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/index');
        }
    }

    /**
     * 返回JSON响应
     */
    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

