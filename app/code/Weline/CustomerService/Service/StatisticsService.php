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
            ->where(ChatSession::schema_fields_AGENT_ID, 0, '>')
            ->where(ChatSession::schema_fields_CREATED_AT, $dateRange['start'], '>=')
            ->where(ChatSession::schema_fields_CREATED_AT, $dateRange['end'], '<=')
            ->select()
            ->fetch()
            ->getItems();
        
        // 提取唯一的客服ID
        $agentIds = [];
        foreach ($sessions as $sessionData) {
            $agentId = (int)$sessionData[ChatSession::schema_fields_AGENT_ID];
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
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_CREATED_AT, $startDate, '>=')
            ->where(ChatSession::schema_fields_CREATED_AT, $endDate, '<=');
        
        if ($status !== null) {
            $query->where(ChatSession::schema_fields_STATUS, $status);
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
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_CREATED_AT, $startDate, '>=')
            ->where(ChatSession::schema_fields_CREATED_AT, $endDate, '<=')
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
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_CREATED_AT, $startDate, '>=')
            ->where(ChatSession::schema_fields_CREATED_AT, $endDate, '<=')
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
     * 转让分账：
     * - 转让方：累计到 transferred_at（含转让前未回复的等待）
     * - 接收方：从 transferred_at 起算
     */
    public function getAverageResponseTime(int $agentId, string $startDate, string $endDate): float
    {
        $times = $this->collectResponseTimes($agentId, $startDate, $endDate);
        if ($times === []) {
            return 0.0;
        }

        return round(array_sum($times) / count($times), 2);
    }

    /**
     * 获取最快响应时间（秒）
     */
    public function getMinResponseTime(int $agentId, string $startDate, string $endDate): float
    {
        $times = $this->collectResponseTimes($agentId, $startDate, $endDate);
        return $times === [] ? 0.0 : round(min($times), 2);
    }

    /**
     * 获取最慢响应时间（秒）
     */
    public function getMaxResponseTime(int $agentId, string $startDate, string $endDate): float
    {
        $times = $this->collectResponseTimes($agentId, $startDate, $endDate);
        return $times === [] ? 0.0 : round(max($times), 2);
    }

    /**
     * @return list<float>
     */
    private function collectResponseTimes(int $agentId, string $startDate, string $endDate): array
    {
        if ($agentId <= 0) {
            return [];
        }

        /** @var ChatSession $sessionModel */
        $sessionModel = ObjectManager::getInstance(ChatSession::class);
        $owned = $sessionModel->reset()
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_CREATED_AT, $startDate, '>=')
            ->where(ChatSession::schema_fields_CREATED_AT, $endDate, '<=')
            ->select()
            ->fetch()
            ->getItems();
        $transferredOut = $sessionModel->reset()
            ->where(ChatSession::schema_fields_TRANSFERRED_FROM_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_TRANSFERRED_AT, $startDate, '>=')
            ->where(ChatSession::schema_fields_TRANSFERRED_AT, $endDate, '<=')
            ->select()
            ->fetch()
            ->getItems();

        $byId = [];
        foreach (array_merge(is_array($owned) ? $owned : [], is_array($transferredOut) ? $transferredOut : []) as $row) {
            $data = $row instanceof ChatSession ? $row->getData() : (is_array($row) ? $row : []);
            $sid = (int)($data[ChatSession::schema_fields_ID] ?? 0);
            if ($sid > 0) {
                $byId[$sid] = $data;
            }
        }

        $times = [];
        foreach ($byId as $sessionId => $sessionData) {
            $windows = $this->ownershipWindowsForAgent($agentId, $sessionData);
            foreach ($windows as $window) {
                $times = array_merge($times, $this->responseTimesInWindow($sessionId, $agentId, $window['start'], $window['end']));
            }
        }

        return $times;
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return list<array{start:int,end:int|null}>
     */
    private function ownershipWindowsForAgent(int $agentId, array $sessionData): array
    {
        $created = strtotime((string)($sessionData[ChatSession::schema_fields_CREATED_AT] ?? '')) ?: 0;
        $transferredAtRaw = trim((string)($sessionData[ChatSession::schema_fields_TRANSFERRED_AT] ?? ''));
        $transferredAt = $transferredAtRaw !== '' ? (strtotime($transferredAtRaw) ?: 0) : 0;
        $fromId = (int)($sessionData[ChatSession::schema_fields_TRANSFERRED_FROM_AGENT_ID] ?? 0);
        $ownerId = (int)($sessionData[ChatSession::schema_fields_AGENT_ID] ?? 0);
        $windows = [];

        // 转让方：从创建到转让时刻
        if ($fromId === $agentId && $transferredAt > 0) {
            $windows[] = [
                'start' => $created > 0 ? $created : $transferredAt,
                'end' => $transferredAt,
            ];
        }

        // 当前归属方
        if ($ownerId === $agentId) {
            $start = ($fromId > 0 && $transferredAt > 0) ? $transferredAt : ($created > 0 ? $created : time());
            $windows[] = [
                'start' => $start,
                'end' => null,
            ];
        }

        return $windows;
    }

    /**
     * @return list<float>
     */
    private function responseTimesInWindow(int $sessionId, int $agentId, int $windowStart, ?int $windowEnd): array
    {
        if ($sessionId <= 0 || $windowStart <= 0) {
            return [];
        }

        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        $customerMessages = $message->reset()
            ->where(ChatMessage::schema_fields_session_id, $sessionId)
            ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_CUSTOMER)
            ->order(ChatMessage::schema_fields_created_at, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $times = [];
        foreach ($customerMessages as $customerMsg) {
            $data = $customerMsg instanceof ChatMessage ? $customerMsg->getData() : (is_array($customerMsg) ? $customerMsg : []);
            $customerAt = strtotime((string)($data[ChatMessage::schema_fields_created_at] ?? '')) ?: 0;
            if ($customerAt <= 0) {
                continue;
            }
            if ($customerAt < $windowStart) {
                continue;
            }
            if ($windowEnd !== null && $customerAt > $windowEnd) {
                continue;
            }

            $clockStart = max($customerAt, $windowStart);
            $agentMessage = $message->reset()
                ->where(ChatMessage::schema_fields_session_id, $sessionId)
                ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_AGENT)
                ->where(ChatMessage::schema_fields_sender_id, $agentId)
                ->where(ChatMessage::schema_fields_created_at, date('Y-m-d H:i:s', $clockStart), '>=')
                ->order(ChatMessage::schema_fields_created_at, 'ASC')
                ->find()
                ->fetch();

            if ($agentMessage->getId()) {
                $replyAt = strtotime((string)$agentMessage->getData(ChatMessage::schema_fields_created_at)) ?: 0;
                if ($windowEnd !== null && $replyAt > $windowEnd) {
                    // 回复发生在转让之后，不算转让方
                    $times[] = (float)max(0, $windowEnd - $clockStart);
                    continue;
                }
                if ($replyAt > $clockStart) {
                    $times[] = (float)($replyAt - $clockStart);
                }
                continue;
            }

            if ($windowEnd !== null) {
                $times[] = (float)max(0, $windowEnd - $clockStart);
            }
        }

        return $times;
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
        // NOTE: keep existing implementation below
        $sessions = $session->reset()
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_CLOSED)
            ->where(ChatSession::schema_fields_CREATED_AT, $startDate, '>=')
            ->where(ChatSession::schema_fields_CREATED_AT, $endDate, '<=')
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
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_CLOSED)
            ->where(ChatSession::schema_fields_CREATED_AT, $startDate, '>=')
            ->where(ChatSession::schema_fields_CREATED_AT, $endDate, '<=')
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
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_CLOSED)
            ->where(ChatSession::schema_fields_CREATED_AT, $startDate, '>=')
            ->where(ChatSession::schema_fields_CREATED_AT, $endDate, '<=')
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

