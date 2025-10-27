<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiAssistant;
use Weline\Ai\Model\AiAssistantRental;
use Weline\Ai\Model\AiAssistantRating;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataObject;
use Weline\Admin\Model\BackendUser;
use Weline\Framework\Acl\Acl;

/**
 * 助手市场控制器
 */
class Market extends BackendController
{
    /**
     * 获取助手模型（懒加载）
     */
    private function getAiAssistant(): AiAssistant
    {
        return ObjectManager::getInstance(AiAssistant::class);
    }

    /**
     * 获取助手租赁模型（懒加载）
     */
    private function getAiAssistantRental(): AiAssistantRental
    {
        return ObjectManager::getInstance(AiAssistantRental::class);
    }

    /**
     * 获取助手评分模型（懒加载）
     */
    private function getAiAssistantRating(): AiAssistantRating
    {
        return ObjectManager::getInstance(AiAssistantRating::class);
    }

    /**
     * 市场首页
     */
    #[Acl('Weline_Ai::ai_market', '助手市场', 'mdi-store', '助手市场')]
    public function index(): string
    {
        // 获取筛选参数
        $category = $this->request->getGet('category', '');
        $search = $this->request->getGet('search', '');
        $sortBy = $this->request->getGet('sort', 'popular'); // popular, newest, highest_rated, cheapest
        $page = max(1, (int)$this->request->getGet('page', 1));
        $pageSize = 12;

        // 构建查询
        $assistantModel = $this->getAiAssistant()->reset();
        $select = $assistantModel->where(AiAssistant::fields_IS_RENTABLE, 1)
            ->where(AiAssistant::fields_STATUS, 'active')
            ->where(AiAssistant::fields_AUDIT_STATUS, 'approved'); // 只显示审核通过的

        // 分类筛选
        if ($category) {
            $select->where(AiAssistant::fields_CATEGORY, $category);
        }

        // 搜索
        if ($search) {
            $select->where(AiAssistant::fields_NAME, 'like', "%{$search}%", 'or')
                ->where(AiAssistant::fields_DESCRIPTION, 'like', "%{$search}%", 'or');
        }

        // 排序
        switch ($sortBy) {
            case 'newest':
                $select->order(AiAssistant::fields_CREATED_AT, 'DESC');
                break;
            case 'highest_rated':
                $select->order(AiAssistant::fields_RATING_AVERAGE, 'DESC');
                break;
            case 'cheapest':
                $select->order(AiAssistant::fields_RENTAL_PRICE, 'ASC');
                break;
            case 'popular':
            default:
                $select->order(AiAssistant::fields_RENTAL_COUNT, 'DESC');
                break;
        }

        // 分页
        $select->limit($pageSize, ($page - 1) * $pageSize);

        $assistants = $select->select()->fetch()->getItems();
        $total = $assistantModel->reset()
            ->where(AiAssistant::fields_IS_RENTABLE, 1)
            ->where(AiAssistant::fields_STATUS, 'active')
            ->where(AiAssistant::fields_AUDIT_STATUS, 'approved')
            ->count();

        // 获取所有分类（用于筛选器）
        $categories = [
            'productivity' => __('生产力工具'),
            'creative' => __('创意写作'),
            'coding' => __('编程开发'),
            'business' => __('商业分析'),
            'education' => __('教育培训'),
            'entertainment' => __('娱乐休闲'),
            'other' => __('其他')
        ];

        $this->assign('assistants', $assistants);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('pageSize', $pageSize);
        $this->assign('totalPages', ceil($total / $pageSize));
        $this->assign('categories', $categories);
        $this->assign('currentCategory', $category);
        $this->assign('currentSearch', $search);
        $this->assign('currentSort', $sortBy);

        return $this->fetch();
    }

    /**
     * 助手详情
     */
    #[Acl('Weline_Ai::ai_market_detail', '助手详情', '', '查看助手详情', 'Weline_Ai::ai_market')]
    public function detail(): string
    {
        $id = (int)$this->request->getGet('id', 0);
        
        if (!$id) {
            $this->getMessageManager()->addError(__('助手ID不能为空'));
            return $this->redirect('*/backend/market/index');
        }

        $assistant = $this->getAiAssistant()->reset()->load($id);
        
        if (!$assistant->getId() 
            || !$assistant->getData(AiAssistant::fields_IS_RENTABLE) 
            || $assistant->getData(AiAssistant::fields_STATUS) !== 'active') {
            $this->getMessageManager()->addError(__('助手不存在或不可用'));
            return $this->redirect('*/backend/market/index');
        }

        // 获取评分列表
        $ratings = $this->getAiAssistantRating()->reset()
            ->where(AiAssistantRating::fields_ASSISTANT_ID, $id)
            ->where(AiAssistantRating::fields_STATUS, 'approved')
            ->order(AiAssistantRating::fields_CREATED_AT, 'DESC')
            ->limit(10)
            ->select()
            ->fetch()
            ->getItems();

        // 获取评分统计
        $ratingStats = [
            '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0
        ];
        $allRatings = $this->getAiAssistantRating()->reset()
            ->where(AiAssistantRating::fields_ASSISTANT_ID, $id)
            ->where(AiAssistantRating::fields_STATUS, 'approved')
            ->select()
            ->fetch()
            ->getItems();
        
        foreach ($allRatings as $rating) {
            $score = (string)$rating->getData(AiAssistantRating::fields_RATING);
            if (isset($ratingStats[$score])) {
                $ratingStats[$score]++;
            }
        }

        $this->assign('assistant', $assistant);
        $this->assign('ratings', $ratings);
        $this->assign('ratingStats', $ratingStats);
        $this->assign('totalRatings', count($allRatings));

        return $this->fetch();
    }

    /**
     * 租用助手
     */
    #[Acl('Weline_Ai::ai_market_rent', '租用助手', '', '租用助手', 'Weline_Ai::ai_market')]
    public function rent(): string
    {
        $assistantId = (int)$this->request->getPost('assistant_id', 0);
        
        if (!$assistantId) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('助手ID不能为空')
            ]);
        }

        try {
            $assistant = $this->getAiAssistant()->reset()->load($assistantId);
            
            if (!$assistant->getId() || !$assistant->getData(AiAssistant::fields_IS_RENTABLE)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('助手不存在或不可租用')
                ]);
            }

            // TODO: 获取当前登录用户ID
            $userId = 1; // 暂时硬编码

            // 检查是否已经租用
            $existingRental = $this->getAiAssistantRental()->reset()
                ->where('assistant_id', $assistantId)
                ->where('renter_id', $userId)
                ->where('status', 'active')
                ->select()
                ->fetch()
                ->getItem();

            if ($existingRental && $existingRental->getId()) {
                // 检查是否过期
                $endTime = $existingRental->getData('end_time');
                if ($endTime && $endTime > time()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('您已经租用了此助手，有效期至：') . date('Y-m-d H:i:s', $endTime)
                    ]);
                }
            }

            // 创建租用记录
            $rentalType = $assistant->getData('rental_type');
            $rentalPrice = $assistant->getData('rental_price');
            $startTime = time();
            $endTime = null;

            // 计算结束时间
            switch ($rentalType) {
                case 'per_use':
                    $endTime = null; // 按次不需要结束时间
                    break;
                case 'daily':
                    $endTime = $startTime + 86400; // 24小时
                    break;
                case 'monthly':
                    $endTime = $startTime + (86400 * 30); // 30天
                    break;
                case 'permanent':
                    $endTime = null; // 永久
                    break;
            }

            $rental = $this->getAiAssistantRental()->reset();
            $rental->setData([
                AiAssistantRental::fields_ASSISTANT_ID => $assistantId,
                AiAssistantRental::fields_OWNER_ID => $assistant->getData(AiAssistant::fields_USER_ID),
                AiAssistantRental::fields_RENTER_ID => $userId,
                AiAssistantRental::fields_RENTAL_TYPE => $rentalType,
                AiAssistantRental::fields_RENTAL_PRICE => $rentalPrice,
                AiAssistantRental::fields_START_TIME => $startTime,
                AiAssistantRental::fields_END_TIME => $endTime,
                AiAssistantRental::fields_STATUS => 'active',
                AiAssistantRental::fields_PAYMENT_STATUS => 'pending', // 支付状态待完善
                AiAssistantRental::fields_CREATED_AT => date('Y-m-d H:i:s', $startTime)
            ]);
            $rental->save();

            // 更新助手统计
            $rentalCount = (int)$assistant->getData(AiAssistant::fields_RENTAL_COUNT);
            $assistant->setData(AiAssistant::fields_RENTAL_COUNT, $rentalCount + 1);
            $assistant->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('租用成功！'),
                'data' => [
                    'rental_id' => $rental->getId(),
                    'end_time' => $endTime ? date('Y-m-d H:i:s', $endTime) : '永久有效'
                ]
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('租用失败：') . $e->getMessage()
            ]);
        }
    }

    /**
     * JSON响应辅助方法
     */
    private function jsonResponse(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data);
    }
}

