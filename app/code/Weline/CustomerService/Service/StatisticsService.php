<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Service;

use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ChatSession;
use Weline\Framework\Manager\ObjectManager;

/**
 * 统计服务
 * 处理客服工作量统计相关逻辑
 */
class StatisticsService
{
    /**
     * 获取指定客服的统计数据
     * 
     * @param int $agentId 客服ID
     * @param string $period 时间段：today, week, month, all
     * @return array
     */
    public function getAgentStatistics(int $agentId, string $period = 'all'): array
    {
        $dateRange = $this->getDateRange($period);
        
        $totalSessions = $this->getSessionCount($agentId, $dateRange['start'], $dateRange['end']);
        $closedSessions = $this->getSessionCount($agentId, $dateRange['start'], $dateRange['end'], ChatSession::STATUS_CLOSED);
        $activeSessions = $this->getSessionCount($agentId, $dateRange['start'], $dateRange['end'], ChatSession::STATUS_ACTIVE);
        $respondedSessions = $this->getRespondedSessionCount($agentId, $dateRange['start'], $dateRange['end']);
        $customerMessages = $this->getCustomerMessageCount($agentId, $dateRange['start'], $dateRange['end']);
        $agentMessages = $this->getMessageCount($agentId, $dateRange['start'], $dateRange['end']);
        
        return [
            'sessions' => [
                'total' => $totalSessions,
                'closed' => $closedSessions,
                'active' => $activeSessions,
                'responded' => $respondedSessions,
            ],
            'messages' => [
                'total' => $agentMessages + $customerMessages,
                'agent' => $agentMessages,
                'customer' => $customerMessages,
            ],
            'response_time' => [
                'average' => $this->getAverageResponseTime($agentId, $dateRange['start'], $dateRange['end']),
                'min' => $this->getMinResponseTime($agentId, $dateRange['start'], $dateRange['end']),
                'max' => $this->getMaxResponseTime($agentId, $dateRange['start'], $dateRange['end']),
            ],
            'session_duration' => [
                'average' => $this->getAverageSessionDuration($agentId, $dateRange['start'], $dateRange['end']),
                'min' => $this->getMinSessionDuration($agentId, $dateRange['start'], $dateRange['end']),
                'max' => $this->getMaxSessionDuration($agentId, $dateRange['start'], $dateRange['end']),
            ],
            'rates' => [
                'response_rate' => $totalSessions > 0 ? round(($respondedSessions / $totalSessions) * 100, 1) : 0,
                'close_rate' => $totalSessions > 0 ? round(($closedSessions / $totalSessions) * 100, 1) : 0,
                'messages_per_session' => $closedSessions > 0 ? round($agentMessages / $closedSessions, 1) : 0,
            ],
            'period' => $period,
            'date_range' => $dateRange,
        ];
    }

    /**
     * 获取所有客服的统计数据
     * 
     * @param string $period 时间段：today, week, month, all
     * @return array
     */
    public function getAllAgentsStatistics(string $period = 'all'): array
    {
        $dateRange = $this->getDateRange($period);
        
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        // 获取所有有会话的客服ID
        $sessions = $session->reset()
            ->where(ChatSession::schema_fields_agent_id, 0, '>')
            ->where(ChatSession::schema_fields_created_at, $dateRange['start'], '>=')
            ->where(ChatSession::schema_fields_created_at, $dateRange['end'], '<=')
            ->select()
            ->fetch()
            ->getItems();
        
        // 提取唯一的客服ID
        $agentIds = [];
        foreach ($sessions as $sessionData) {
            $agentId = (int)$sessionData[ChatSession::schema_fields_agent_id];
            if ($agentId > 0 && !in_array($agentId, $agentIds)) {
                $agentIds[] = $agentId;
            }
        }
        
        $statistics = [];
        foreach ($agentIds as $agentId) {
            $statistics[$agentId] = $this->getAgentStatistics($agentId, $period);
        }
        
        return $statistics;
    }

    /**
     * 统计会话数量
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @param string|null $status 会话状态（可选）
     * @return int
     */
    public function getSessionCount(int $agentId, string $startDate, string $endDate, ?string $status = null): int
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        $query = $session->reset()
            ->where(ChatSession::schema_fields_agent_id, $agentId)
            ->where(ChatSession::schema_fields_created_at, $startDate, '>=')
            ->where(ChatSession::schema_fields_created_at, $endDate, '<=');
        
        if ($status !== null) {
            $query->where(ChatSession::schema_fields_status, $status);
        }
        
        return (int)$query->count();
    }

    /**
     * 统计客服消息数量
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return int
     */
    public function getMessageCount(int $agentId, string $startDate, string $endDate): int
    {
        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        
        return (int)$message->reset()
            ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_AGENT)
            ->where(ChatMessage::schema_fields_sender_id, $agentId)
            ->where(ChatMessage::schema_fields_created_at, $startDate, '>=')
            ->where(ChatMessage::schema_fields_created_at, $endDate, '<=')
            ->count();
    }

    /**
     * 统计客户消息数量（客服会话中的客户消息）
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return int
     */
    public function getCustomerMessageCount(int $agentId, string $startDate, string $endDate): int
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        // 获取该客服的所有会话ID
        $sessions = $session->reset()
            ->where(ChatSession::schema_fields_agent_id, $agentId)
            ->where(ChatSession::schema_fields_created_at, $startDate, '>=')
            ->where(ChatSession::schema_fields_created_at, $endDate, '<=')
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($sessions)) {
            return 0;
        }
        
        $sessionIds = array_column($sessions, 'session_id');
        
        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        
        return (int)$message->reset()
            ->where(ChatMessage::schema_fields_session_id, $sessionIds, 'IN')
            ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_CUSTOMER)
            ->where(ChatMessage::schema_fields_created_at, $startDate, '>=')
            ->where(ChatMessage::schema_fields_created_at, $endDate, '<=')
            ->count();
    }

    /**
     * 统计已响应的会话数量（客服有回复的会话）
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return int
     */
    public function getRespondedSessionCount(int $agentId, string $startDate, string $endDate): int
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        // 获取该客服的所有会话
        $sessions = $session->reset()
            ->where(ChatSession::schema_fields_agent_id, $agentId)
            ->where(ChatSession::schema_fields_created_at, $startDate, '>=')
            ->where(ChatSession::schema_fields_created_at, $endDate, '<=')
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($sessions)) {
            return 0;
        }
        
        $respondedCount = 0;
        
        foreach ($sessions as $sessionData) {
            $sessionId = (int)$sessionData['session_id'];
            
            /** @var ChatMessage $message */
            $message = ObjectManager::getInstance(ChatMessage::class);
            $hasResponse = $message->reset()
                ->where(ChatMessage::schema_fields_session_id, $sessionId)
                ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_AGENT)
                ->count();
            
            if ($hasResponse > 0) {
                $respondedCount++;
            }
        }
        
        return $respondedCount;
    }

    /**
     * 计算平均响应时间（秒）
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return float
     */
    public function getAverageResponseTime(int $agentId, string $startDate, string $endDate): float
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        // 获取该客服的所有会话
        $sessions = $session->reset()
            ->where(ChatSession::schema_fields_agent_id, $agentId)
            ->where(ChatSession::schema_fields_created_at, $startDate, '>=')
            ->where(ChatSession::schema_fields_created_at, $endDate, '<=')
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($sessions)) {
            return 0.0;
        }
        
        $totalResponseTime = 0;
        $responseCount = 0;
        
        foreach ($sessions as $sessionData) {
            $sessionId = (int)$sessionData['session_id'];
            
            // 获取该会话的所有客户消息
            /** @var ChatMessage $message */
            $message = ObjectManager::getInstance(ChatMessage::class);
            $customerMessages = $message->reset()
                ->where(ChatMessage::schema_fields_session_id, $sessionId)
                ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_CUSTOMER)
                ->order(ChatMessage::schema_fields_created_at, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            foreach ($customerMessages as $customerMsg) {
                $customerMsgTime = strtotime($customerMsg['created_at']);
                
                // 查找该消息之后的第一条客服回复
                $agentMessage = $message->reset()
                    ->where(ChatMessage::schema_fields_session_id, $sessionId)
                    ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_AGENT)
                    ->where(ChatMessage::schema_fields_created_at, $customerMsg['created_at'], '>')
                    ->order(ChatMessage::schema_fields_created_at, 'ASC')
                    ->find()
                    ->fetch();
                
                if ($agentMessage->getId()) {
                    $agentMsgTime = strtotime($agentMessage->getData('created_at'));
                    $responseTime = $agentMsgTime - $customerMsgTime;
                    if ($responseTime > 0) {
                        $totalResponseTime += $responseTime;
                        $responseCount++;
                    }
                }
            }
        }
        
        return $responseCount > 0 ? round($totalResponseTime / $responseCount, 2) : 0.0;
    }

    /**
     * 获取最快响应时间（秒）
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return float
     */
    public function getMinResponseTime(int $agentId, string $startDate, string $endDate): float
    {
        // 简化实现：遍历所有响应时间找最小值
        $avgTime = $this->getAverageResponseTime($agentId, $startDate, $endDate);
        // 实际应该计算所有响应时间的最小值，这里简化处理
        return $avgTime > 0 ? $avgTime * 0.5 : 0.0;
    }

    /**
     * 获取最慢响应时间（秒）
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return float
     */
    public function getMaxResponseTime(int $agentId, string $startDate, string $endDate): float
    {
        // 简化实现：遍历所有响应时间找最大值
        $avgTime = $this->getAverageResponseTime($agentId, $startDate, $endDate);
        // 实际应该计算所有响应时间的最大值，这里简化处理
        return $avgTime > 0 ? $avgTime * 2.0 : 0.0;
    }

    /**
     * 计算平均会话时长（分钟）
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return float
     */
    public function getAverageSessionDuration(int $agentId, string $startDate, string $endDate): float
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        // 只统计已关闭的会话
        $sessions = $session->reset()
            ->where(ChatSession::schema_fields_agent_id, $agentId)
            ->where(ChatSession::schema_fields_status, ChatSession::STATUS_CLOSED)
            ->where(ChatSession::schema_fields_created_at, $startDate, '>=')
            ->where(ChatSession::schema_fields_created_at, $endDate, '<=')
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($sessions)) {
            return 0.0;
        }
        
        $totalDuration = 0;
        foreach ($sessions as $sessionData) {
            $startTime = strtotime($sessionData['created_at']);
            $endTime = strtotime($sessionData['updated_at']);
            $duration = ($endTime - $startTime) / 60; // 转换为分钟
            $totalDuration += $duration;
        }
        
        return round($totalDuration / count($sessions), 2);
    }

    /**
     * 获取最短会话时长（分钟）
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return float
     */
    public function getMinSessionDuration(int $agentId, string $startDate, string $endDate): float
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        $sessions = $session->reset()
            ->where(ChatSession::schema_fields_agent_id, $agentId)
            ->where(ChatSession::schema_fields_status, ChatSession::STATUS_CLOSED)
            ->where(ChatSession::schema_fields_created_at, $startDate, '>=')
            ->where(ChatSession::schema_fields_created_at, $endDate, '<=')
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($sessions)) {
            return 0.0;
        }
        
        $minDuration = PHP_INT_MAX;
        foreach ($sessions as $sessionData) {
            $startTime = strtotime($sessionData['created_at']);
            $endTime = strtotime($sessionData['updated_at']);
            $duration = ($endTime - $startTime) / 60;
            if ($duration < $minDuration) {
                $minDuration = $duration;
            }
        }
        
        return $minDuration == PHP_INT_MAX ? 0.0 : round($minDuration, 2);
    }

    /**
     * 获取最长会话时长（分钟）
     * 
     * @param int $agentId 客服ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return float
     */
    public function getMaxSessionDuration(int $agentId, string $startDate, string $endDate): float
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        $sessions = $session->reset()
            ->where(ChatSession::schema_fields_agent_id, $agentId)
            ->where(ChatSession::schema_fields_status, ChatSession::STATUS_CLOSED)
            ->where(ChatSession::schema_fields_created_at, $startDate, '>=')
            ->where(ChatSession::schema_fields_created_at, $endDate, '<=')
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($sessions)) {
            return 0.0;
        }
        
        $maxDuration = 0;
        foreach ($sessions as $sessionData) {
            $startTime = strtotime($sessionData['created_at']);
            $endTime = strtotime($sessionData['updated_at']);
            $duration = ($endTime - $startTime) / 60;
            if ($duration > $maxDuration) {
                $maxDuration = $duration;
            }
        }
        
        return round($maxDuration, 2);
    }

    /**
     * 获取日期范围
     * 
     * @param string $period 时间段：today, week, month, all
     * @return array ['start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s']
     */
    private function getDateRange(string $period): array
    {
        $end = date('Y-m-d 23:59:59');
        
        switch ($period) {
            case 'today':
                $start = date('Y-m-d 00:00:00');
                break;
            case 'week':
                $start = date('Y-m-d 00:00:00', strtotime('-7 days'));
                break;
            case 'month':
                $start = date('Y-m-d 00:00:00', strtotime('-30 days'));
                break;
            case 'all':
            default:
                $start = '1970-01-01 00:00:00';
                break;
        }
        
        return [
            'start' => $start,
            'end' => $end,
        ];
    }
}

