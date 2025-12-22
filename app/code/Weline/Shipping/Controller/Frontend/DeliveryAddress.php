<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Frontend;

use Weline\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\DeliveryAddressService;

/**
 * 运送地址前端控制器
 */
class DeliveryAddress extends FrontendController
{
    protected ?string $layoutType = 'account.dashboard';
    
    private DeliveryAddressService $service;
    private CustomerSession $session;

    public function __construct(
        ObjectManager $objectManager,
        CustomerSession $session
    ) {
        $this->service = $objectManager->getInstance(DeliveryAddressService::class);
        $this->session = $session;
    }

    /**
     * 检查登录状态
     */
    private function checkLogin(): bool
    {
        if (!$this->session->isLogin()) {
            $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
            $this->redirect('/customer/account/login?referer=' . urlencode($currentUrl));
            return false;
        }
        return true;
    }

    /**
     * 获取当前客户ID
     */
    private function getCustomerId(): ?int
    {
        if (!$this->session->isLogin()) {
            return null;
        }
        $user = $this->session->getLoginUser();
        return $user->getId();
    }

    /**
     * 地址列表页
     */
    public function getIndex()
    {
        if (!$this->checkLogin()) {
            return;
        }
        
        $customerId = $this->getCustomerId();
        $filters = [];
        if ($this->request->getParam('keyword')) {
            $filters['keyword'] = $this->request->getParam('keyword');
        }
        
        $addresses = $this->service->getListByCustomer($customerId, $filters);
        $this->assign('addresses', $addresses);
        return $this->fetch();
    }

    /**
     * 地址表单页（新增/编辑）
     */
    public function getForm()
    {
        if (!$this->checkLogin()) {
            return;
        }
        
        $id = $this->request->getParam('id');
        $address = null;
        
        if ($id) {
            $address = $this->service->getById((int)$id);
            if (!$address) {
                $this->getMessageManager()->addError(__('地址不存在'));
                $this->redirect('*/index');
                return;
            }
            
            // 验证权限：只能编辑自己的地址
            $customerId = $this->getCustomerId();
            if ($address->getCustomerId() !== $customerId) {
                $this->getMessageManager()->addError(__('无权操作此地址'));
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
        if (!$this->checkLogin()) {
            return;
        }
        
        $customerId = $this->getCustomerId();
        $data = $this->request->getPost();
        $id = $data['delivery_address_id'] ?? null;
        
        try {
            if ($id) {
                $address = $this->service->update((int)$id, $data, $customerId);
                $message = __('更新成功');
            } else {
                $address = $this->service->create($customerId, $data);
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
        if (!$this->checkLogin()) {
            return;
        }
        
        $customerId = $this->getCustomerId();
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
            $this->service->delete((int)$id, $customerId);
            
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
        if (!$this->checkLogin()) {
            return;
        }
        
        $customerId = $this->getCustomerId();
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
            $this->service->setDefault((int)$id, $customerId);
            
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

