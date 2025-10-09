<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiApiKey;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

/**
 * API密钥管理后台控制器
 * 
 * 功能：
 * - API密钥列表展示
 * - 密钥审核管理
 * - 配额管理
 * - 使用统计
 */
#[Acl('Weline_Ai::ai_apikey_manager', 'API密钥管理', 'mdi-key', 'API密钥管理', 'Weline_Ai::ai')]
class ApiKey extends BackendController
{
    /**
     * @var AiApiKey
     */
    private AiApiKey $aiApiKey;

    /**
     * 构造函数
     * 
     * @param AiApiKey $aiApiKey
     */
    public function __construct(AiApiKey $aiApiKey)
    {
        $this->aiApiKey = $aiApiKey;
    }

    /**
     * API密钥列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_list', '查看API密钥列表', 'mdi-view-list', '查看API密钥列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            $status = $this->request->getGet('status', '');

            // 构建查询
            $query = $this->aiApiKey->reset();
            
            if ($status !== '') {
                $query->where('status', $status);
            }

            $apiKeys = $query->pagination($page, $pageSize)
                ->order('created_time', 'DESC')
                ->select()
                ->fetch();

            $pagination = $apiKeys->getPagination();
            $total = is_object($pagination) && method_exists($pagination, 'getTotal') 
                ? $pagination->getTotal() 
                : count($apiKeys->getItems());

            $this->assign('api_keys', $apiKeys->getItems());
            $this->assign('pagination', $pagination);
            $this->assign('total', $total);
            $this->assign('current_status', $status);

            // 统计各状态数量
            $stats = [
                'total' => $total,
                'pending' => $this->aiApiKey->reset()->where('status', 'pending')->select()->fetch()->count(),
                'approved' => $this->aiApiKey->reset()->where('status', 'approved')->select()->fetch()->count(),
                'rejected' => $this->aiApiKey->reset()->where('status', 'rejected')->select()->fetch()->count(),
                'active' => $this->aiApiKey->reset()->where('is_active', 1)->select()->fetch()->count(),
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            $this->messageManager->addError(__('加载API密钥列表失败：%1', $e->getMessage()));
            return $this->fetch();
        }
    }

    /**
     * 审核API密钥
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_approve', '审核API密钥', 'mdi-check', '审核API密钥')]
    public function approve(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $action = $this->request->getPost('action'); // approve or reject

        try {
            $apiKey = $this->aiApiKey->reset()->load($id);
            
            if (!$apiKey->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('API密钥不存在')
                ]);
            }

            if ($action === 'approve') {
                $apiKey->setData('status', 'approved');
                $apiKey->setData('is_active', 1);
                $message = __('API密钥已审核通过');
            } else {
                $apiKey->setData('status', 'rejected');
                $apiKey->setData('is_active', 0);
                $message = __('API密钥已拒绝');
            }

            $apiKey->setData('updated_time', time());
            $apiKey->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%1', $e->getMessage())
            ]);
        }
    }

    /**
     * 设置配额
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_set_quota', '设置配额', 'mdi-counter', '设置配额')]
    public function setQuota(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $quotaLimit = (int)$this->request->getPost('quota_limit');

        try {
            $apiKey = $this->aiApiKey->reset()->load($id);
            
            if (!$apiKey->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('API密钥不存在')
                ]);
            }

            $apiKey->setData('quota_limit', $quotaLimit);
            $apiKey->setData('updated_time', time());
            $apiKey->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('配额设置成功')
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('设置失败：%1', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除API密钥
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_delete', '删除API密钥', 'mdi-delete', '删除API密钥')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');

        try {
            $apiKey = $this->aiApiKey->reset()->load($id);
            
            if (!$apiKey->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('API密钥不存在')
                ]);
            }

            $apiKey->delete();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('API密钥已删除')
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%1', $e->getMessage())
            ]);
        }
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->response->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}

