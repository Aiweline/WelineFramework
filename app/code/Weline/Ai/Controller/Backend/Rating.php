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
use Weline\Ai\Model\AiAssistantRating;
use Weline\Ai\Model\AiAssistantRental;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataObject;
use Weline\Framework\Acl\Acl;

/**
 * 助手评分控制器
 */
class Rating extends BackendController
{
    /**
     * 获取评分模型（懒加载）
     */
    private function getAiAssistantRating(): AiAssistantRating
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AiAssistantRating::class);
    }

    /**
     * 获取助手模型（懒加载）
     */
    private function getAiAssistant(): AiAssistant
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AiAssistant::class);
    }

    /**
     * 获取助手租赁模型（懒加载）
     */
    private function getAiAssistantRental(): AiAssistantRental
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(AiAssistantRental::class);
    }

    /**
     * 提交评分
     */
    #[Acl('Weline_Ai::ai_rating_submit', '提交评分', '', '提交助手评分', 'Weline_Ai::ai_rating_mylist')]
    public function submit(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $assistantId = (int)$this->request->getPost('assistant_id', 0);
        $rating = (int)$this->request->getPost('rating', 0);
        $comment = trim($this->request->getPost('comment', ''));
        
        // 可选的维度评分
        $accuracyRating = (int)$this->request->getPost('accuracy_rating', 0);
        $speedRating = (int)$this->request->getPost('speed_rating', 0);
        $usefulnessRating = (int)$this->request->getPost('usefulness_rating', 0);

        try {
            // 验证参数
            if (!$assistantId) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('助手ID不能为空')
                ]);
            }

            if ($rating < 1 || $rating > 5) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('评分必须在1-5之间')
                ]);
            }

            // 检查助手是否存在
            $assistant = $this->getAiAssistant()->reset()->load($assistantId);
            if (!$assistant->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('助手不存在')
                ]);
            }

            // TODO: 获取当前登录用户ID
            $userId = 1; // 暂时硬编码

            // 检查用户是否租用了此助手
            $rental = $this->getAiAssistantRental()->reset()
                ->where('assistant_id', $assistantId)
                ->where('renter_id', $userId)
                ->where('status', 'active')
                ->select()
                ->fetch()
                ->getItem();

            if (!$rental || !$rental->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('您还未租用此助手，无法评分')
                ]);
            }

            // 检查是否已经评分过
            $existingRating = $this->getAiAssistantRating()->reset()
                ->where('assistant_id', $assistantId)
                ->where('user_id', $userId)
                ->select()
                ->fetch()
                ->getItem();

            if ($existingRating && $existingRating->getId()) {
                // 更新评分
                $existingRating->setData([
                    'rating' => $rating,
                    'comment' => $comment,
                    'accuracy_rating' => $accuracyRating ?: null,
                    'speed_rating' => $speedRating ?: null,
                    'usefulness_rating' => $usefulnessRating ?: null,
                    'updated_time' => time()
                ]);
                $existingRating->save();

                $message = __('评分已更新');
            } else {
                // 创建新评分
                $newRating = $this->getAiAssistantRating()->reset();
                $newRating->setData([
                    'assistant_id' => $assistantId,
                    'user_id' => $userId,
                    'rating' => $rating,
                    'comment' => $comment,
                    'accuracy_rating' => $accuracyRating ?: null,
                    'speed_rating' => $speedRating ?: null,
                    'usefulness_rating' => $usefulnessRating ?: null,
                    'status' => 'pending', // 待审核
                    'created_time' => time(),
                    'updated_time' => time()
                ]);
                $newRating->save();

                $message = __('评分提交成功，待审核后显示');
            }

            // 更新助手的平均评分
            $this->updateAssistantRating($assistantId);

            return $this->jsonResponse([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('提交评分失败：') . $e->getMessage()
            ]);
        }
    }

    /**
     * 我的评分列表
     */
    #[Acl('Weline_Ai::ai_rating_mylist', '我的评分', 'mdi-star', '我的评分列表')]
    public function mylist(): string
    {
        // TODO: 获取当前登录用户ID
        $userId = 1;

        $page = max(1, (int)$this->request->getGet('page', 1));
        $pageSize = 20;

        try {
            // 获取我的评分
            $ratings = $this->getAiAssistantRating()->reset()
                ->where('user_id', $userId)
                ->order('created_time', 'DESC')
                ->limit($pageSize, ($page - 1) * $pageSize)
                ->select()
                ->fetch()
                ->getItems();

            $total = $this->getAiAssistantRating()->reset()
                ->where('user_id', $userId)
                ->count();

            // 获取关联的助手信息
            foreach ($ratings as $rating) {
                $assistant = $this->getAiAssistant()->reset()->load($rating->getData('assistant_id'));
                $rating->setData('assistant_name', $assistant->getData('name'));
                $rating->setData('assistant_description', $assistant->getData('description'));
            }
        } catch (\Exception $e) {
            // 表不存在或其他错误，返回空数据
            $ratings = [];
            $total = 0;
        }

        $this->assign('ratings', $ratings);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('pageSize', $pageSize);
        $this->assign('totalPages', ceil($total / $pageSize));

        return $this->fetch();
    }

    /**
     * 点赞评分
     */
    #[Acl('Weline_Ai::ai_rating_like', '点赞评分', '', '点赞评分', 'Weline_Ai::ai_rating_mylist')]
    public function like(): string
    {
        $ratingId = (int)$this->request->getPost('rating_id', 0);

        if (!$ratingId) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('评分ID不能为空')
            ]);
        }

        try {
            $rating = $this->getAiAssistantRating()->reset()->load($ratingId);
            
            if (!$rating->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('评分不存在')
                ]);
            }

            // 增加点赞数
            $likeCount = (int)($rating->getData('like_count') ?? 0);
            $rating->setData('like_count', $likeCount + 1);
            $rating->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('点赞成功'),
                'data' => ['like_count' => $likeCount + 1]
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('点赞失败')
            ]);
        }
    }

    /**
     * 更新助手的平均评分
     */
    private function updateAssistantRating(int $assistantId): void
    {
        // 计算平均评分（只计算已审核通过的）
        $ratings = $this->getAiAssistantRating()->reset()
            ->where('assistant_id', $assistantId)
            ->where('status', 'approved')
            ->select()
            ->fetch()
            ->getItems();

        if (empty($ratings)) {
            return;
        }

        $totalRating = 0;
        $count = count($ratings);

        foreach ($ratings as $rating) {
            $totalRating += (int)$rating->getData('rating');
        }

        $average = $totalRating / $count;

        // 更新助手
        $assistant = $this->getAiAssistant()->reset()->load($assistantId);
        if ($assistant->getId()) {
            $assistant->setData('rating_average', round($average, 2));
            $assistant->save();
        }
    }

    /**
     * JSON响应辅助方法
     */
    private function jsonResponse(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

