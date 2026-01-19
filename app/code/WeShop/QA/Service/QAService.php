<?php

declare(strict_types=1);

namespace WeShop\QA\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\QA\Model\Question;

/**
 * 问答服务
 */
class QAService
{
    /**
     * 创建问题
     * 
     * @param array $questionData 问题数据
     * @return Question
     */
    public function createQuestion(array $questionData): Question
    {
        /** @var Question $question */
        $question = ObjectManager::getInstance(Question::class);
        
        $question->clearData()
            ->setData('product_id', $questionData['product_id'] ?? 0)
            ->setData('customer_id', $questionData['customer_id'] ?? 0)
            ->setData('question', $questionData['question'] ?? '')
            ->setData('status', 'pending')
            ->save();
        
        return $question;
    }
    
    /**
     * 获取产品问题列表
     * 
     * @param int $productId 产品ID
     * @return array
     */
    public function getProductQuestions(int $productId): array
    {
        /** @var Question $question */
        $question = ObjectManager::getInstance(Question::class);
        
        return $question->clear()
            ->where('product_id', $productId)
            ->where('status', 'approved')
            ->order('created_at', 'DESC')
            ->select()
            ->fetchArray();
    }
}
