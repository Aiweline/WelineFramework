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

namespace Weline\Ai\Controller\Frontend;

use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Model\AiAssistant;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;
use Weline\Framework\Http\Url;

/**
 * 用户个人中心控制器
 * 
 * 功能：
 * - 用户API密钥管理
 * - 个人助手管理
 * - 使用统计查看
 * - 个人设置
 */
class Center extends FrontendController
{
    /**
     * @var AiApiKey
     */
    private AiApiKey $aiApiKey;

    /**
     * @var AiAssistant
     */
    private AiAssistant $aiAssistant;

    /**
     * @var Session|null
     */
    protected ?Session $session;

    /**
     * @var Url|null
     */
    protected ?Url $_url;

    /**
     * 构造函数
     * 
     * @param AiApiKey $aiApiKey
     * @param AiAssistant $aiAssistant
     * @param Session $session
     * @param Url $url
     */
    public function __construct(
        AiApiKey $aiApiKey,
        AiAssistant $aiAssistant,
        Session $session,
        Url $url
    ) {
        $this->aiApiKey = $aiApiKey;
        $this->aiAssistant = $aiAssistant;
        $this->session = $session;
        $this->_url = $url;
    }

    /**
     * 检查用户登录状态
     * 
     * @return bool
     */
    private function checkLogin(): bool
    {
        return $this->session && $this->session->isLogin();
    }

    /**
     * 个人中心首页
     * 
     * @return string
     */
    public function index(): string
    {
        $isLoggedIn = $this->checkLogin();
        
        if ($isLoggedIn) {
            $userId = $this->session->getLoginUserData('entity_id');

            // 获取用户的API密钥数量
            $apiKeyCount = $this->aiApiKey->reset()
                ->where('user_id', $userId)
                ->select()
                ->fetch()
                ->count();

            // 获取用户的助手数量
            $assistantCount = $this->aiAssistant->reset()
                ->where('user_id', $userId)
                ->select()
                ->fetch()
                ->count();

            $this->assign('api_key_count', $apiKeyCount);
            $this->assign('assistant_count', $assistantCount);
            $this->assign('user', $this->session->getLoginUserData());
        } else {
            $this->assign('api_key_count', 0);
            $this->assign('assistant_count', 0);
            $this->assign('user', []);
        }

        $this->assign('page_title', __('个人中心'));
        $this->assign('is_logged_in', $isLoggedIn);

        return $this->fetch();
    }

    /**
     * API密钥管理
     * 
     * @return string
     */
    public function apiKeys(): string
    {
        if (!$this->checkLogin()) {
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        $userId = $this->session->getLoginUserData('entity_id');

        // 获取用户的API密钥列表
        $apiKeys = $this->aiApiKey->reset()
            ->where('user_id', $userId)
            ->order('created_time', 'DESC')
            ->select()
            ->fetch();

        $this->assign('page_title', __('API密钥管理'));
        $this->assign('api_keys', $apiKeys->getItems());

        return $this->fetch();
    }

    /**
     * 创建API密钥
     * 
     * @return string
     */
    public function createApiKey(): string
    {
        if (!$this->checkLogin()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $userId = $this->session->getLoginUserData('entity_id');
        $name = $this->request->getPost('name', '');

        if (empty($name)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('密钥名称不能为空')
            ]);
        }

        try {
            // 生成唯一的API密钥
            $token = 'sk-' . bin2hex(random_bytes(32));

            $apiKey = $this->aiApiKey->reset();
            $apiKey->setData([
                'name' => $name,
                'user_id' => $userId,
                'token' => $token,
                'status' => 'pending', // 待审核
                'quota_limit' => 0,
                'used_quota' => 0,
                'is_active' => 0,
                'created_time' => time(),
                'updated_time' => time()
            ]);
            $apiKey->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('API密钥创建成功，等待管理员审核'),
                'data' => [
                    'id' => $apiKey->getId(),
                    'name' => $name,
                    'token' => $token,
                    'status' => 'pending'
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('创建失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除API密钥
     * 
     * @return string
     */
    public function deleteApiKey(): string
    {
        if (!$this->checkLogin()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $userId = $this->session->getLoginUserData('entity_id');

        try {
            $apiKey = $this->aiApiKey->reset()->load($id);
            
            // 验证密钥所有权
            if ($apiKey->getData('user_id') != $userId) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('无权操作此密钥')
                ]);
            }

            $apiKey->delete();

            return $this->fetchJson([
                'success' => true,
                'message' => __('API密钥已删除')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 助手管理
     * 
     * @return string
     */
    public function assistants(): string
    {
        if (!$this->checkLogin()) {
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        $userId = $this->session->getLoginUserData('entity_id');

        // 获取用户的助手列表
        $assistants = $this->aiAssistant->reset()
            ->where('user_id', $userId)
            ->order('created_time', 'DESC')
            ->select()
            ->fetch();

        $this->assign('page_title', __('我的助手'));
        $this->assign('assistants', $assistants->getItems());

        return $this->fetch();
    }

    /**
     * 使用统计
     * 
     * @return string
     */
    public function statistics(): string
    {
        if (!$this->checkLogin()) {
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        $userId = $this->session->getLoginUserData('entity_id');

        // TODO: 实现使用统计逻辑
        $stats = [
            'total_requests' => 0,
            'total_tokens' => 0,
            'total_cost' => 0,
            'this_month_requests' => 0,
            'this_month_tokens' => 0,
            'this_month_cost' => 0,
        ];

        $this->assign('page_title', __('使用统计'));
        $this->assign('stats', $stats);

        return $this->fetch();
    }

    /**
     * 个人设置
     * 
     * @return string
     */
    public function settings(): string
    {
        if (!$this->checkLogin()) {
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        $this->assign('page_title', __('个人设置'));
        $this->assign('user', $this->session->getLoginUserData());

        return $this->fetch();
    }

}


