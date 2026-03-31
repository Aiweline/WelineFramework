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

    /**
     * 获取所有待审核问题列表
     *
     * @param string|null $status 状态筛选
     * @param int $page 页码
     * @param int $size 每页数量
     * @return array
     */
    public function getPendingQuestions(?string $status = null, int $page = 1, int $size = 20): array
    {
        /** @var Question $question */
        $question = ObjectManager::getInstance(Question::class);

        $question->clear();

        if ($status !== null) {
            $question->where(Question::schema_fields_STATUS, $status);
        }

        return $question
            ->pagination($page, $size)
            ->order(Question::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取问题详情
     *
     * @param int $questionId 问题ID
     * @return Question|null
     */
    public function getQuestion(int $questionId): ?Question
    {
        /** @var Question $question */
        $question = ObjectManager::getInstance(Question::class);
        $question->load($questionId);

        if (!$question->getId()) {
            return null;
        }

        return $question;
    }

    /**
     * 审核通过问题
     *
     * @param int $questionId 问题ID
     * @param string|null $answer 回复内容
     * @return bool
     */
    public function approveQuestion(int $questionId, ?string $answer = null): bool
    {
        /** @var Question $question */
        $question = ObjectManager::getInstance(Question::class);
        $question->load($questionId);

        if (!$question->getId()) {
            return false;
        }

        $question->setData(Question::schema_fields_STATUS, 'approved');

        if ($answer !== null) {
            $question->setData(Question::schema_fields_ANSWER, $answer);
        }

        $question->setData(Question::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));

        return (bool) $question->save()->fetch();
    }

    /**
     * 拒绝问题
     *
     * @param int $questionId 问题ID
     * @return bool
     */
    public function rejectQuestion(int $questionId): bool
    {
        /** @var Question $question */
        $question = ObjectManager::getInstance(Question::class);
        $question->load($questionId);

        if (!$question->getId()) {
            return false;
        }

        $question->setData(Question::schema_fields_STATUS, 'rejected');
        $question->setData(Question::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));

        return (bool) $question->save()->fetch();
    }

    /**
     * 获取待审核问题数量
     *
     * @param string|null $status 状态筛选
     * @return int
     */
    public function getPendingQuestionsCount(?string $status = null): int
    {
        /** @var Question $question */
        $question = ObjectManager::getInstance(Question::class);

        $question->clear();

        if ($status !== null) {
            $question->where(Question::schema_fields_STATUS, $status);
        }

        $result = $question->select()->fetch();

        return is_array($result) ? count($result) : 0;
    }
}
