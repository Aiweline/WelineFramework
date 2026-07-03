<?php
declare(strict_types=1);

namespace Weline\BackendActivity\Service;

use Weline\BackendActivity\Api\BusinessContextInterface;
use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;
use Weline\Framework\Runtime\RequestContext;

class BusinessContextService implements BusinessContextInterface
{
    public const CONTEXT_KEY = 'backend_activity.business_context';
    private const LOG_MODEL_KEY = 'backend_activity.log_model';
    private const DEFERRED_PAYLOAD_KEY = 'backend_activity.deferred_payload';
    private const PAYLOAD_LIMIT = 60000;

    public function mark(
        string $businessModule,
        string $entityType,
        string|int $entityId,
        string $action,
        string $title = '',
        array $payload = []
    ): void {
        $context = self::normalizeContext($businessModule, $entityType, $entityId, $action, $title, $payload);
        if ($context['business_module'] === '' || $context['business_entity_type'] === '' || $context['business_action'] === '') {
            return;
        }

        RequestContext::set(self::CONTEXT_KEY, $context);

        $deferredPayload = RequestContext::get(self::DEFERRED_PAYLOAD_KEY);
        if (is_array($deferredPayload)) {
            $deferredPayload['business_context'] = $context;
            RequestContext::set(self::DEFERRED_PAYLOAD_KEY, $deferredPayload);
        }

        $logModel = RequestContext::get(self::LOG_MODEL_KEY);
        if (!$logModel instanceof BackendActivityLog || (int)$logModel->getId() <= 0) {
            return;
        }

        self::applyToLogModel($logModel, $context);
        self::updateLogById((int)$logModel->getId(), $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function applyToLogModel(BackendActivityLog $logModel, array $context): void
    {
        $row = self::contextToRow($context);
        foreach ($row as $field => $value) {
            $logModel->setData($field, $value);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function updateLogById(int $logId, array $context): void
    {
        if ($logId <= 0) {
            return;
        }

        try {
            /** @var BackendActivityLog $backendActivityLog */
            $backendActivityLog = ObjectManager::getInstance(BackendActivityLog::class);
            $backendActivityLog->getQuery(false)
                ->where(BackendActivityLog::schema_fields_ID, $logId)
                ->update(self::contextToRow($context), BackendActivityLog::schema_fields_ID)
                ->fetch();
        } catch (\Throwable $e) {
            self::logFailure($e);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,string|null>
     */
    public static function contextToRow(array $context): array
    {
        return [
            BackendActivityLog::schema_fields_business_module => self::limit((string)($context['business_module'] ?? ''), 100),
            BackendActivityLog::schema_fields_business_entity_type => self::limit((string)($context['business_entity_type'] ?? ''), 100),
            BackendActivityLog::schema_fields_business_entity_id => self::limit((string)($context['business_entity_id'] ?? ''), 64),
            BackendActivityLog::schema_fields_business_action => self::limit((string)($context['business_action'] ?? ''), 80),
            BackendActivityLog::schema_fields_business_title => self::limit((string)($context['business_title'] ?? ''), 255),
            BackendActivityLog::schema_fields_business_payload => self::encodePayload($context['business_payload'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function normalizeContext(
        string $businessModule,
        string $entityType,
        string|int $entityId,
        string $action,
        string $title,
        array $payload
    ): array {
        return [
            'business_module' => self::limit(trim($businessModule), 100),
            'business_entity_type' => self::limit(trim($entityType), 100),
            'business_entity_id' => self::limit(trim((string)$entityId), 64),
            'business_action' => self::limit(trim($action), 80),
            'business_title' => self::limit(trim($title), 255),
            'business_payload' => $payload,
        ];
    }

    private static function encodePayload(mixed $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if (!is_string($json) || $json === '') {
            return null;
        }

        return self::limit($json, self::PAYLOAD_LIMIT);
    }

    private static function limit(string $value, int $limit): string
    {
        if ($limit <= 0 || $value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit);
        }

        return substr($value, 0, $limit);
    }

    private static function logFailure(\Throwable $e): void
    {
        try {
            ObjectManager::getInstance(Log::class)
                ->warning('BackendActivity business context write failed: ' . $e->getMessage());
        } catch (\Throwable) {
        }
    }
}
