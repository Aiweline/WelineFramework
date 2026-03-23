<?php

declare(strict_types=1);

namespace Weline\Bt_Center\Service;

use Weline\Bt_Center\Model\BtServer;

class BtServerMonitorService
{
    public function __construct(
        private readonly BtServer $btServerModel,
        private readonly BtPanelProbeService $probeService
    ) {
    }

    public function run(): array
    {
        $rows = $this->btServerModel->clearQuery()
            ->where(BtServer::schema_fields_IS_ENABLED, 1)
            ->order(BtServer::schema_fields_SERVER_ID, 'ASC')
            ->select()
            ->fetchArray();

        $stats = [
            'checked' => 0,
            'up' => 0,
            'down' => 0,
            'changed' => 0,
            'notified' => 0,
        ];

        foreach ($rows as $row) {
            $server = clone $this->btServerModel;
            $server->setData($row);

            $previousStatus = (string) ($row[BtServer::schema_fields_LAST_CHECK_STATUS] ?? BtServer::CHECK_STATUS_UNKNOWN);
            $probe = $this->probeService->probe((string) ($row[BtServer::schema_fields_EXTERNAL_URL] ?? ''));
            $currentStatus = (string) ($probe['status'] ?? BtServer::CHECK_STATUS_DOWN);
            $checkedAt = (string) ($probe['checked_at'] ?? date('Y-m-d H:i:s'));

            $server->setData(BtServer::schema_fields_LAST_CHECK_AT, $checkedAt)
                ->setData(BtServer::schema_fields_LAST_CHECK_STATUS, $currentStatus)
                ->setData(BtServer::schema_fields_LAST_HTTP_CODE, $probe['http_code'])
                ->setData(BtServer::schema_fields_LAST_RESPONSE_TIME_MS, $probe['response_time_ms'])
                ->setData(BtServer::schema_fields_LAST_ERROR_MESSAGE, (string) ($probe['error_message'] ?? ''));

            if (self::hasStateChanged($previousStatus, $currentStatus)) {
                $server->setData(BtServer::schema_fields_LAST_STATE_CHANGED_AT, $checkedAt);
                $stats['changed']++;
            }

            $server->save();

            $stats['checked']++;
            if ($currentStatus === BtServer::CHECK_STATUS_UP) {
                $stats['up']++;
            } else {
                $stats['down']++;
            }

            $notification = self::buildNotification($row, $probe, $previousStatus, $currentStatus);
            if ($notification !== null) {
                w_msg(
                    'bt_server_health',
                    $notification['type'],
                    $notification['title'],
                    $notification['content'],
                    [
                        'icon' => $notification['icon'],
                        'metadata' => $notification['metadata'],
                    ]
                );
                $stats['notified']++;
            }
        }

        return $stats;
    }

    public static function hasStateChanged(string $previousStatus, string $currentStatus): bool
    {
        $previousStatus = $previousStatus !== '' ? $previousStatus : BtServer::CHECK_STATUS_UNKNOWN;
        return $previousStatus !== $currentStatus;
    }

    public static function shouldNotifyOnTransition(string $previousStatus, string $currentStatus): bool
    {
        $previousStatus = $previousStatus !== '' ? $previousStatus : BtServer::CHECK_STATUS_UNKNOWN;

        if ($currentStatus === BtServer::CHECK_STATUS_DOWN && $previousStatus !== BtServer::CHECK_STATUS_DOWN) {
            return true;
        }

        return $currentStatus === BtServer::CHECK_STATUS_UP && $previousStatus === BtServer::CHECK_STATUS_DOWN;
    }

    public static function buildNotification(array $serverRow, array $probe, string $previousStatus, string $currentStatus): ?array
    {
        if (!self::shouldNotifyOnTransition($previousStatus, $currentStatus)) {
            return null;
        }

        $serverName = (string) ($serverRow[BtServer::schema_fields_NAME] ?? __('未命名服务器'));
        $serverUrl = (string) ($serverRow[BtServer::schema_fields_EXTERNAL_URL] ?? '');
        $httpCode = $probe['http_code'] ?? null;
        $responseTime = $probe['response_time_ms'] ?? null;
        $errorMessage = trim((string) ($probe['error_message'] ?? ''));

        $details = [
            __('地址：%{1}', $serverUrl),
            __('HTTP 状态：%{1}', $httpCode !== null ? (string) $httpCode : __('无')),
            __('响应耗时：%{1} ms', $responseTime !== null ? (string) $responseTime : __('无')),
        ];
        if ($errorMessage !== '') {
            $details[] = __('错误信息：%{1}', $errorMessage);
        }

        if ($currentStatus === BtServer::CHECK_STATUS_DOWN) {
            return [
                'type' => 'error',
                'title' => __('BT 服务器不可访问：%{1}', $serverName),
                'content' => implode(PHP_EOL, $details),
                'icon' => 'ri-alarm-warning-line',
                'metadata' => [
                    'server_id' => (int) ($serverRow[BtServer::schema_fields_SERVER_ID] ?? 0),
                    'server_name' => $serverName,
                    'external_url' => $serverUrl,
                    'previous_status' => $previousStatus,
                    'current_status' => $currentStatus,
                ],
            ];
        }

        return [
            'type' => 'success',
            'title' => __('BT 服务器已恢复：%{1}', $serverName),
            'content' => implode(PHP_EOL, $details),
            'icon' => 'ri-checkbox-circle-line',
            'metadata' => [
                'server_id' => (int) ($serverRow[BtServer::schema_fields_SERVER_ID] ?? 0),
                'server_name' => $serverName,
                'external_url' => $serverUrl,
                'previous_status' => $previousStatus,
                'current_status' => $currentStatus,
            ],
        ];
    }
}
