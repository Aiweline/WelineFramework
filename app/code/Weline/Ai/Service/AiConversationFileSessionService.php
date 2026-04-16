<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

/**
 * 基于文件的轻量会话历史存储。
 *
 * 说明：
 * - 仅在调用方传入 session_id 时启用
 * - 不依赖数据库，便于快速接入到任意调用方
 * - 当消息累计到阈值时，对早期消息做确定性摘要，降低上下文体积
 */
class AiConversationFileSessionService
{
    private const MAX_MESSAGES_BEFORE_SUMMARY = 24;
    private const KEEP_RECENT_MESSAGES = 10;
    private const MAX_MESSAGE_CHARS = 2400;
    private const MAX_SUMMARY_CHARS = 4000;

    public function hasSessionId(array $params): bool
    {
        return $this->extractSessionId($params) !== '';
    }

    public function buildHistoryMessages(array $params): array
    {
        $sessionId = $this->extractSessionId($params);
        if ($sessionId === '') {
            return [];
        }

        $data = $this->loadSessionData($sessionId);
        $history = [];
        $summary = trim((string)($data['summary'] ?? ''));
        if ($summary !== '') {
            $history[] = [
                'role' => 'system',
                'content' => "以下是当前会话的历史摘要，请保持上下文连续：\n" . $summary,
            ];
        }

        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = trim((string)($message['role'] ?? ''));
            $content = (string)($message['content'] ?? '');
            if ($role === '' || $content === '') {
                continue;
            }
            $history[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $history;
    }

    public function appendTurn(array $params, string $userPrompt, string $assistantResponse): void
    {
        $sessionId = $this->extractSessionId($params);
        if ($sessionId === '') {
            return;
        }

        $data = $this->loadSessionData($sessionId);
        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        $messages[] = [
            'role' => 'user',
            'content' => $this->truncateContent($userPrompt),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $messages[] = [
            'role' => 'assistant',
            'content' => $this->truncateContent($assistantResponse),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $summary = (string)($data['summary'] ?? '');
        [$summary, $messages] = $this->compactIfNeeded($summary, $messages);

        $this->saveSessionData($sessionId, [
            'session_id' => $sessionId,
            'summary' => $summary,
            'messages' => $messages,
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => (string)($data['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    public function getSession(string $sessionId): array
    {
        $safeSessionId = $this->sanitizeSessionId($sessionId);
        if ($safeSessionId === '') {
            return [];
        }

        $data = $this->loadSessionData($safeSessionId);
        return is_array($data) ? $data : [];
    }

    public function clearSession(string $sessionId): bool
    {
        $safeSessionId = $this->sanitizeSessionId($sessionId);
        if ($safeSessionId === '') {
            return false;
        }

        $file = $this->buildFilePath($safeSessionId);
        if (!is_file($file)) {
            return true;
        }

        return @unlink($file);
    }

    private function compactIfNeeded(string $summary, array $messages): array
    {
        if (count($messages) <= self::MAX_MESSAGES_BEFORE_SUMMARY) {
            return [$summary, $messages];
        }

        $keep = self::KEEP_RECENT_MESSAGES;
        $slicePos = max(0, count($messages) - $keep);
        $older = array_slice($messages, 0, $slicePos);
        $recent = array_slice($messages, $slicePos);

        $summaryParts = [];
        if ($summary !== '') {
            $summaryParts[] = $summary;
        }
        $summaryParts[] = $this->deterministicSummarize($older);
        $newSummary = trim(implode("\n", array_filter($summaryParts, static fn(string $s): bool => trim($s) !== '')));
        if (mb_strlen($newSummary, 'UTF-8') > self::MAX_SUMMARY_CHARS) {
            $newSummary = (string)mb_substr($newSummary, -self::MAX_SUMMARY_CHARS, null, 'UTF-8');
        }

        return [$newSummary, $recent];
    }

    private function deterministicSummarize(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = trim((string)($message['role'] ?? ''));
            $content = trim((string)($message['content'] ?? ''));
            if ($role === '' || $content === '') {
                continue;
            }
            $content = preg_replace('/\s+/u', ' ', $content) ?? $content;
            $content = (string)mb_substr($content, 0, 220, 'UTF-8');
            $lines[] = strtoupper($role) . ': ' . $content;
        }

        return implode("\n", $lines);
    }

    private function truncateContent(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }
        if (mb_strlen($content, 'UTF-8') <= self::MAX_MESSAGE_CHARS) {
            return $content;
        }

        return (string)mb_substr($content, 0, self::MAX_MESSAGE_CHARS, 'UTF-8');
    }

    private function extractSessionId(array $params): string
    {
        return $this->sanitizeSessionId((string)($params['session_id'] ?? ''));
    }

    private function sanitizeSessionId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // 仅允许安全字符，避免路径穿越
        $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $raw) ?? '';
        return trim($safe);
    }

    private function loadSessionData(string $sessionId): array
    {
        $file = $this->buildFilePath($sessionId);
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveSessionData(string $sessionId, array $data): void
    {
        $file = $this->buildFilePath($sessionId);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function buildFilePath(string $sessionId): string
    {
        $base = defined('BP') ? BP : getcwd() . DIRECTORY_SEPARATOR;
        return $base . 'var' . DIRECTORY_SEPARATOR . 'ai' . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . $sessionId . '.json';
    }
}

