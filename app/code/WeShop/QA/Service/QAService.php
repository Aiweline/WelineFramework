<?php

declare(strict_types=1);

namespace WeShop\QA\Service;

use WeShop\QA\Model\Question;
use Weline\Framework\Manager\ObjectManager;

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
            ->setData(Question::schema_fields_PRODUCT_ID, $questionData['product_id'] ?? 0)
            ->setData(Question::schema_fields_CUSTOMER_ID, $questionData['customer_id'] ?? 0)
            ->setData(Question::schema_fields_QUESTION, $questionData['question'] ?? '')
            ->setData(Question::schema_fields_STATUS, 'pending')
            ->setData(Question::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
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
            ->where(Question::schema_fields_PRODUCT_ID, $productId)
            ->where(Question::schema_fields_STATUS, 'approved')
            ->order(Question::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
    }

    public function removeQuestion(int $questionId, int $customerId): bool
    {
        /** @var Question $question */
        $question = ObjectManager::getInstance(Question::class);
        $question->load($questionId);

        if (!$question->getId()) {
            return false;
        }

        if ((int) $question->getData(Question::schema_fields_CUSTOMER_ID) !== $customerId) {
            return false;
        }

        return (bool) $question->delete()->fetch();
    }
}
