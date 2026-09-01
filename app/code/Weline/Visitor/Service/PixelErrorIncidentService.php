<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelErrorIncident;

/**
 * 错误事故持久化 + 按类型 w_msg 通知。
 */
class PixelErrorIncidentService
{
    private ?PixelErrorIncidentClassifier $classifier = null;
    private ?EventDictionaryService $eventDictionary = null;

    private function classifier(): PixelErrorIncidentClassifier
    {
        return $this->classifier ??= ObjectManager::getInstance(PixelErrorIncidentClassifier::class);
    }

    private function eventDictionary(): EventDictionaryService
    {
        return $this->eventDictionary ??= ObjectManager::getInstance(EventDictionaryService::class);
    }

    /**
     * @param array<string, mixed> $payload site_error 原始/规范化字段
     * @param array<string, mixed> $pixelPersistResponse track() 持久化结果
     * @return array<string, mixed>
     */
    public function recordFromSiteError(array $payload, array $pixelPersistResponse = []): array
    {
        $serverDeploy = $this->resolveServerDeployVersion();
        $serverWorker = $this->resolveServerWorkerBuildId();
        $clientDeploy = \trim((string)($payload['client_deploy_version'] ?? $payload['deploy_version'] ?? $payload['deployVersion'] ?? ''));
        $clientWorker = \trim((string)($payload['worker_build_id'] ?? $payload['workerBuildId'] ?? ''));
        $stale = $clientDeploy !== '' && $serverDeploy !== '' && $clientDeploy !== 'dev' && $serverDeploy !== 'dev'
            && ($clientDeploy !== $serverDeploy || ($clientWorker !== '' && $serverWorker !== '' && $clientWorker !== $serverWorker && $serverWorker !== 'dev'));

        $errorType = $this->classifier()->classify($payload, $stale);
        $severity = $this->classifier()->severityForType($errorType);
        $fingerprint = $this->buildFingerprint($payload, $errorType);
        $email = $this->sanitizeEmail((string)($payload['identity_email'] ?? $payload['email'] ?? ''));
        $userId = max(0, (int)($payload['user_id'] ?? $payload['userId'] ?? 0));
        $identityKind = PixelErrorIncident::IDENTITY_VISITOR;
        if ($email !== '') {
            $identityKind = PixelErrorIncident::IDENTITY_EMAIL;
        }
        if ($userId > 0) {
            $identityKind = PixelErrorIncident::IDENTITY_USER;
        }

        $themeVersionId = $this->nullableString($payload['theme_published_version_id'] ?? $payload['themePublishedVersionId'] ?? null);
        $themeVersion = $this->nullableString($payload['theme_published_version'] ?? $payload['themePublishedVersion'] ?? null);

        $stepPath = $payload['step_path'] ?? $payload['stepPath'] ?? [];
        if (!\is_array($stepPath)) {
            $stepPath = [];
        }
        $formSnapshot = $payload['form_snapshot'] ?? $payload['formSnapshot'] ?? [];
        if (!\is_array($formSnapshot)) {
            $formSnapshot = [];
        }
        $formSnapshot = $this->redactFormSnapshot($formSnapshot);

        $now = date('Y-m-d H:i:s');
        $row = [
            PixelErrorIncident::schema_fields_PIXEL_ID => (int)($pixelPersistResponse['pixel_id'] ?? $payload['pixel_id'] ?? 0),
            PixelErrorIncident::schema_fields_WEBSITE_ID => max(0, (int)($payload['website_id'] ?? $payload['websiteId'] ?? 0)),
            PixelErrorIncident::schema_fields_SESSION_ID => \substr((string)($payload['session_id'] ?? ''), 0, 64),
            PixelErrorIncident::schema_fields_ERROR_TYPE => $errorType,
            PixelErrorIncident::schema_fields_ERROR_CODE => \substr((string)($payload['error_code'] ?? ''), 0, 128),
            PixelErrorIncident::schema_fields_SEVERITY => $severity,
            PixelErrorIncident::schema_fields_DISPOSITION => PixelErrorIncident::DISPOSITION_OPEN,
            PixelErrorIncident::schema_fields_STALE_CLIENT => $stale ? 1 : 0,
            PixelErrorIncident::schema_fields_IDENTITY_KIND => $identityKind,
            PixelErrorIncident::schema_fields_IDENTITY_EMAIL => $email !== '' ? $email : null,
            PixelErrorIncident::schema_fields_USER_ID => $userId,
            PixelErrorIncident::schema_fields_CLIENT_DEPLOY_VERSION => \substr($clientDeploy, 0, 128),
            PixelErrorIncident::schema_fields_SERVER_DEPLOY_VERSION => \substr($serverDeploy, 0, 128),
            PixelErrorIncident::schema_fields_WORKER_BUILD_ID => \substr($clientWorker !== '' ? $clientWorker : $serverWorker, 0, 128),
            PixelErrorIncident::schema_fields_THEME_PUBLISHED_VERSION_ID => $themeVersionId !== null ? $themeVersionId : '',
            PixelErrorIncident::schema_fields_THEME_PUBLISHED_VERSION => $themeVersion !== null ? $themeVersion : '',
            PixelErrorIncident::schema_fields_PIXEL_SCRIPT_VERSION => \substr((string)($payload['pixel_script_version'] ?? ''), 0, 64),
            PixelErrorIncident::schema_fields_DICT_VERSION => \substr((string)($payload['dict_version'] ?? $this->eventDictionary()->getVersion()), 0, 32),
            PixelErrorIncident::schema_fields_PAGE_URL => \substr((string)($payload['page_url'] ?? $payload['url'] ?? ''), 0, 512),
            PixelErrorIncident::schema_fields_STEP_PATH_JSON => json_encode(\array_slice($stepPath, -30), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]',
            PixelErrorIncident::schema_fields_FORM_SNAPSHOT_JSON => json_encode($formSnapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            PixelErrorIncident::schema_fields_ERROR_MESSAGE => \substr((string)($payload['error_message'] ?? $payload['message'] ?? ''), 0, 1024),
            PixelErrorIncident::schema_fields_ERROR_STACK => \substr((string)($payload['error_stack'] ?? $payload['stack'] ?? ''), 0, 8000),
            PixelErrorIncident::schema_fields_FINGERPRINT => $fingerprint,
            PixelErrorIncident::schema_fields_CREATED_AT => $now,
            PixelErrorIncident::schema_fields_UPDATED_AT => $now,
        ];

        /** @var PixelErrorIncident $model */
        $model = ObjectManager::make(PixelErrorIncident::class);
        $model->save($row);
        $incidentId = (int)$model->getId();

        $this->notify($errorType, $severity, $incidentId, $row, $stale);

        return [
            'incident_id' => $incidentId,
            'error_type' => $errorType,
            'stale_client' => $stale,
            'fingerprint' => $fingerprint,
            'disposition' => PixelErrorIncident::DISPOSITION_OPEN,
        ];
    }

    /**
     * 服务端业务失败直接建案（无客户端像素行时 pixel_id=0）。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordServerSide(array $payload): array
    {
        return $this->recordFromSiteError($payload, ['pixel_id' => 0]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function notify(string $errorType, string $severity, int $incidentId, array $row, bool $stale): void
    {
        if ($incidentId <= 0 || !\function_exists('w_msg')) {
            return;
        }

        $bucket = date('YmdH');
        $dedupe = implode('|', [
            (string)($row[PixelErrorIncident::schema_fields_WEBSITE_ID] ?? 0),
            $errorType,
            (string)($row[PixelErrorIncident::schema_fields_FINGERPRINT] ?? ''),
            $bucket,
        ]);

        $title = (string)__('站点错误：%{1}', [$errorType]);
        $content = (string)__(
            '页面 %{1} · %{2} · 身份 %{3} · 框架 %{4}%{5}',
            [
                (string)($row[PixelErrorIncident::schema_fields_PAGE_URL] ?? ''),
                (string)($row[PixelErrorIncident::schema_fields_ERROR_MESSAGE] ?? ''),
                (string)($row[PixelErrorIncident::schema_fields_IDENTITY_KIND] ?? 'visitor'),
                (string)($row[PixelErrorIncident::schema_fields_CLIENT_DEPLOY_VERSION] ?? ''),
                $stale ? ' (stale)' : '',
            ]
        );

        try {
            w_msg($errorType, $severity === 'info' ? 'info' : ($severity === 'warning' ? 'warning' : 'error'), $title, $content, [
                'priority' => $severity === 'urgent' ? 'high' : 'normal',
                'dedupe_key' => $dedupe,
                'source_module' => 'Weline_Visitor',
                'metadata' => [
                    'incident_id' => $incidentId,
                    'error_type' => $errorType,
                    'error_code' => (string)($row[PixelErrorIncident::schema_fields_ERROR_CODE] ?? ''),
                    'website_id' => (int)($row[PixelErrorIncident::schema_fields_WEBSITE_ID] ?? 0),
                    'stale_client' => $stale,
                    'theme_published_version_id' => $row[PixelErrorIncident::schema_fields_THEME_PUBLISHED_VERSION_ID] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            w_log_error('PixelErrorIncident w_msg failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildFingerprint(array $payload, string $errorType): string
    {
        $raw = implode('|', [
            $errorType,
            (string)($payload['error_code'] ?? ''),
            \substr((string)($payload['error_message'] ?? $payload['message'] ?? ''), 0, 200),
            (string)(parse_url((string)($payload['page_url'] ?? $payload['url'] ?? ''), PHP_URL_PATH) ?: '/'),
        ]);
        return \substr(hash('sha256', $raw), 0, 64);
    }

    private function sanitizeEmail(string $email): string
    {
        $email = \strtolower(\trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return \substr($email, 0, 255);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function redactFormSnapshot(array $snapshot): array
    {
        $blocked = ['password', 'pass', 'pwd', 'card', 'cvv', 'cvc', 'pan', 'otp', 'token', 'secret', 'ssn'];
        $out = [];
        foreach ($snapshot as $key => $value) {
            $name = \strtolower((string)$key);
            foreach ($blocked as $needle) {
                if (\str_contains($name, $needle)) {
                    continue 2;
                }
            }
            if (\is_scalar($value) || $value === null) {
                $out[\substr((string)$key, 0, 64)] = \substr((string)$value, 0, 500);
            }
        }
        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = \trim((string)$value);
        return $s === '' ? null : \substr($s, 0, 128);
    }

    private function resolveServerDeployVersion(): string
    {
        $file = BP . 'var' . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'current.json';
        if (!is_file($file)) {
            return 'dev';
        }
        try {
            $data = json_decode((string)file_get_contents($file), true);
            return \is_array($data) && !empty($data['deploy_version']) ? (string)$data['deploy_version'] : 'dev';
        } catch (\Throwable) {
            return 'dev';
        }
    }

    private function resolveServerWorkerBuildId(): string
    {
        $file = BP . 'var' . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'current.json';
        if (!is_file($file)) {
            return 'dev';
        }
        try {
            $data = json_decode((string)file_get_contents($file), true);
            return \is_array($data) && !empty($data['worker_build_id']) ? (string)$data['worker_build_id'] : 'dev';
        } catch (\Throwable) {
            return 'dev';
        }
    }
}
