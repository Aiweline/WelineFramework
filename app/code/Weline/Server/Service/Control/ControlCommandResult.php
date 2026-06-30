<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

final class ControlCommandResult
{
    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function normalize(
        array $result,
        string $instance,
        string $action,
        string $requestId,
        bool $asyncExpected = false
    ): array {
        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
        $timedOut = !empty($result['timed_out']);
        $accepted = (bool)($data['accepted'] ?? ($result['success'] ?? false));
        $async = (bool)($data['async'] ?? $asyncExpected);
        $completed = !$timedOut && !$async && !empty($result['success']);
        if (isset($data['state']) && \in_array($data['state'], ['completed', 'failed'], true)) {
            $completed = true;
        }

        $status = 'failed';
        if ($timedOut) {
            $status = 'timed_out';
        } elseif ($accepted && !$completed) {
            $status = 'accepted';
        } elseif ($completed && !empty($result['success'])) {
            $status = 'completed';
        }

        $errors = [];
        if (empty($result['success']) && isset($result['message']) && (string)$result['message'] !== '') {
            $errors[] = (string)$result['message'];
        }

        $data += [
            'accepted' => $accepted,
            'completed' => $completed,
            'status' => $status,
            'request_id' => $requestId,
            'instance' => $instance,
            'action' => $action,
            'timed_out' => $timedOut,
            'errors' => $errors,
        ];

        return [
            'success' => (bool)($result['success'] ?? false),
            'accepted' => $accepted,
            'completed' => $completed,
            'status' => $status,
            'request_id' => $requestId,
            'instance' => $instance,
            'action' => $action,
            'message' => (string)($result['message'] ?? ''),
            'timed_out' => $timedOut,
            'errors' => $errors,
            'data' => $data,
        ];
    }

    public static function requestId(string $action): string
    {
        try {
            $suffix = \bin2hex(\random_bytes(6));
        } catch (\Throwable) {
            $suffix = \str_replace('.', '', (string)\microtime(true));
        }

        return 'wls-' . $action . '-' . \getmypid() . '-' . $suffix;
    }
}
