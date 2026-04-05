<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\AiSiteBuilderMessage;

class MessageService
{
    public function __construct(
        private readonly AiSiteBuilderMessage $messageModel,
        private readonly SessionService $sessionService,
    ) {
    }

    /**
     * @param array<string, mixed> $toolPayload
     */
    public function appendMessage(
        int $sessionId,
        int $adminUserId,
        string $role,
        string $content,
        string $messageType = 'message',
        array $toolPayload = []
    ): bool {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return false;
        }

        $message = clone $this->messageModel;
        $message->clearData()->clearQuery();
        $message->setData(AiSiteBuilderMessage::schema_fields_SESSION_ID, $sessionId);
        $message->setData(AiSiteBuilderMessage::schema_fields_ROLE, \trim($role));
        $message->setData(AiSiteBuilderMessage::schema_fields_CONTENT, $content);
        $message->setData(AiSiteBuilderMessage::schema_fields_MESSAGE_TYPE, \trim($messageType) ?: 'message');
        $message->setToolPayloadArray($toolPayload);
        try {
            $message->save();
        } catch (\Throwable $e) {
            if (!$this->isPgsqlAiSiteBuilderMessagePrimaryKeyBroken($e)) {
                throw $e;
            }

            $message = clone $this->messageModel;
            $message->clearData()->clearQuery();
            $message->setData(AiSiteBuilderMessage::schema_fields_ID, $this->allocateNextMessageId());
            $message->setData(AiSiteBuilderMessage::schema_fields_SESSION_ID, $sessionId);
            $message->setData(AiSiteBuilderMessage::schema_fields_ROLE, \trim($role));
            $message->setData(AiSiteBuilderMessage::schema_fields_CONTENT, $content);
            $message->setData(AiSiteBuilderMessage::schema_fields_MESSAGE_TYPE, \trim($messageType) ?: 'message');
            $message->setToolPayloadArray($toolPayload);
            $message->save();
        }

        return true;
    }

    /**
     * @return list<array{
     *   message_id:int,
     *   role:string,
     *   content:string,
     *   message_type:string,
     *   tool_payload:array<string, mixed>,
     *   create_time:string
     * }>
     */
    public function listForSession(int $sessionId, int $adminUserId, int $limit = 200): array
    {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return [];
        }

        $limit = \min(500, \max(1, $limit));
        $message = clone $this->messageModel;
        $rows = $message->clearData()->clearQuery()
            ->where(AiSiteBuilderMessage::schema_fields_SESSION_ID, $sessionId)
            ->order(AiSiteBuilderMessage::schema_fields_ID, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        if (!\is_array($rows)) {
            return [];
        }

        $rows = \array_reverse($rows);
        $messages = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $item = clone $this->messageModel;
            $item->setData($row);
            $messages[] = [
                'message_id' => $item->getId(),
                'role' => $item->getRole(),
                'content' => $item->getContent(),
                'message_type' => $item->getMessageType(),
                'tool_payload' => $item->getToolPayloadArray(),
                'create_time' => (string)($row[AiSiteBuilderMessage::schema_fields_CREATE_TIME] ?? ''),
            ];
        }

        return $messages;
    }

    private function isPgsqlAiSiteBuilderMessagePrimaryKeyBroken(\Throwable $e): bool
    {
        $chain = $e;
        while ($chain !== null) {
            $msg = $chain->getMessage();
            if ((\str_contains($msg, '23502') || \str_contains($msg, '23505'))
                && \str_contains($msg, AiSiteBuilderMessage::schema_fields_ID)
                && (\str_contains($msg, 'weline_websites_ai_site_builder_message')
                    || \str_contains($msg, 'm_weline_websites_ai_site_builder_message'))) {
                return true;
            }
            $chain = $chain->getPrevious();
        }

        return false;
    }

    private function allocateNextMessageId(): int
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector || !\method_exists($connector, 'getWrappedConnection')) {
            return 0;
        }

        $pdo = $connector->getWrappedConnection()->getPdo();
        $tableSql = $this->messageModel->getTable();
        $pk = AiSiteBuilderMessage::schema_fields_ID;
        $stmt = $pdo->query('SELECT COALESCE(MAX("' . $pk . '"), 0) AS mx FROM ' . $tableSql);
        if ($stmt === false) {
            return 0;
        }

        return ((int)($stmt->fetch(\PDO::FETCH_ASSOC)['mx'] ?? 0)) + 1;
    }
}
