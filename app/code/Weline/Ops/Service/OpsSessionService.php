<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Ops\Model\OpsSession;

final class OpsSessionService
{
    public function __construct(
        private readonly OpsSession $session,
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $context
     * @return array{ok:bool,session?:array<string,mixed>,message?:string}
     */
    public function create(int $websiteId, int $programId, array $config, array $context = []): array
    {
        $sessionId = $this->newSessionId();
        $row = $this->session->clear();
        $row->setData(OpsSession::schema_fields_SESSION_ID, $sessionId);
        $row->setData(OpsSession::schema_fields_WEBSITE_ID, max(0, $websiteId));
        $row->setData(OpsSession::schema_fields_PROGRAM_ID, max(0, $programId));
        $row->setData(OpsSession::schema_fields_STATUS, OpsSession::STATUS_OPEN);
        $row->setData(OpsSession::schema_fields_CONFIG_JSON, $this->encode($config));
        $row->setData(OpsSession::schema_fields_CONTEXT_JSON, $this->encode($context));
        $row->save();

        return ['ok' => true, 'session' => $this->toView($row)];
    }

    /** @return array{ok:bool,session?:array<string,mixed>,message?:string} */
    public function get(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return ['ok' => false, 'message' => (string)__('会话ID不能为空')];
        }
        $row = $this->session->clear()->load($sessionId);
        if (!$row->getId() && !(string)$row->getData(OpsSession::schema_fields_SESSION_ID)) {
            // varchar PK: load may set data without numeric getId
            $loaded = (string)$row->getData(OpsSession::schema_fields_SESSION_ID);
            if ($loaded === '') {
                return ['ok' => false, 'message' => (string)__('会话不存在')];
            }
        }
        $loadedId = (string)$row->getData(OpsSession::schema_fields_SESSION_ID);
        if ($loadedId === '') {
            return ['ok' => false, 'message' => (string)__('会话不存在')];
        }

        return ['ok' => true, 'session' => $this->toView($row)];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{ok:bool,session?:array<string,mixed>,message?:string}
     */
    public function mergeContext(string $sessionId, array $context): array
    {
        $got = $this->get($sessionId);
        if (!($got['ok'] ?? false)) {
            return $got;
        }
        $row = $this->session->clear()->load($sessionId);
        $existing = $this->decode((string)$row->getData(OpsSession::schema_fields_CONTEXT_JSON));
        $merged = array_merge($existing, $context);
        $row->setData(OpsSession::schema_fields_CONTEXT_JSON, $this->encode($merged));
        $row->save();

        return ['ok' => true, 'session' => $this->toView($row)];
    }

    /** @return array<string,mixed> */
    public function toView(OpsSession $row): array
    {
        return [
            'session_id' => (string)$row->getData(OpsSession::schema_fields_SESSION_ID),
            'website_id' => (int)$row->getData(OpsSession::schema_fields_WEBSITE_ID),
            'program_id' => (int)$row->getData(OpsSession::schema_fields_PROGRAM_ID),
            'status' => (string)$row->getData(OpsSession::schema_fields_STATUS),
            'config' => $this->decode((string)$row->getData(OpsSession::schema_fields_CONFIG_JSON)),
            'context' => $this->decode((string)$row->getData(OpsSession::schema_fields_CONTEXT_JSON)),
            'created_at' => (string)$row->getData(OpsSession::schema_fields_CREATED_AT),
            'updated_at' => (string)$row->getData(OpsSession::schema_fields_UPDATED_AT),
        ];
    }

    private function newSessionId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return uniqid('ops_', true);
        }
    }

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
