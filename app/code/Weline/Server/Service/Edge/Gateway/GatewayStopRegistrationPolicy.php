<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Fail-closed decision for project Stop after it owns the local retirement
 * fence. Host status is accepted only for the exact project and launch.
 */
final class GatewayStopRegistrationPolicy
{
    public const ACTION_DRAIN = 'drain';
    public const ACTION_LOCAL_ONLY = 'local_only';
    public const ACTION_BLOCK = 'block';

    /**
     * @param array<string,mixed> $endpoint retirement-fenced endpoint
     * @param array<string,mixed> $status authenticated project own-status, or failure
     * @return array{action:string,reason:string,status_authenticated:bool}
     */
    public static function decide(
        array $endpoint,
        array $status,
        string $currentHostBootId = '',
    ): array
    {
        $fence = GatewayRuntimeServingProjection::endpointFence($endpoint);
        $fact = GatewayRegistrationLifecycle::factForEndpoint($endpoint);
        if ($fence === null
            || $fact === []
            || !\hash_equals(
                GatewayRegistrationLifecycle::STATE_RETIRING,
                (string)($fact['state'] ?? ''),
            )
        ) {
            return self::block(
                'The project has no exact local gateway retirement fence.',
                false,
            );
        }

        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $projectUuid = (string)($gateway['project_uuid'] ?? '');
        $currentHostBootId = self::currentHostBootId($currentHostBootId);
        $statusAuthenticated = self::authenticatedOwnStatus(
            $status,
            $projectUuid,
            $currentHostBootId,
        );
        if (!$statusAuthenticated) {
            if (GatewayRegistrationLifecycle::provesNeverAttemptedRetirement($endpoint)) {
                return [
                    'action' => self::ACTION_LOCAL_ONLY,
                    'reason' => 'The exact launch was retired before its first gateway mutation.',
                    'status_authenticated' => false,
                ];
            }
            if (\hash_equals(
                GatewayRegistrationLifecycle::STATE_RETIRED,
                (string)($fact['previous_state'] ?? ''),
            )) {
                return [
                    'action' => self::ACTION_LOCAL_ONLY,
                    'reason' => 'The exact launch already has a local retirement fact.',
                    'status_authenticated' => false,
                ];
            }
            return self::block(
                'Gateway control is unavailable and this launch may already be registered.',
                false,
            );
        }

        // A register/renew that crossed beginMutation() may still commit after
        // this status snapshot, regardless of whether the snapshot currently
        // says ACTIVE, REMOVED or absent. Never drain against that moving
        // target. Stop will cancel its retirement claim and a later retry can
        // operate on the mutation's terminal REGISTERED/UNCERTAIN state.
        if (\hash_equals(
            GatewayRegistrationLifecycle::STATE_REGISTERING,
            (string)($fact['previous_state'] ?? ''),
        )) {
            return self::block(
                'A registration mutation was already in flight when Stop fenced the launch.',
                true,
            );
        }

        $matches = [];
        foreach ((array)($status['instances'] ?? []) as $instance) {
            if (!\is_array($instance)
                || !\hash_equals(
                    (string)($gateway['instance_id'] ?? ''),
                    (string)($instance['instance_id'] ?? ''),
                )
            ) {
                continue;
            }
            $matches[] = $instance;
        }
        if (\count($matches) > 1) {
            return self::block(
                'Authenticated gateway status returned duplicate instance identities.',
                true,
            );
        }
        if ($matches === []) {
            return [
                'action' => self::ACTION_LOCAL_ONLY,
                'reason' => 'Authenticated own-status proves this exact instance is absent.',
                'status_authenticated' => true,
            ];
        }

        $instance = $matches[0];
        $statusName = \strtoupper(\trim((string)($instance['status'] ?? '')));
        if ((int)($instance['generation'] ?? 0)
                !== (int)($gateway['instance_generation'] ?? -1)
            || (int)($instance['master_epoch'] ?? 0)
                !== (int)($endpoint['master_epoch'] ?? -1)
            || !\hash_equals(
                (string)($gateway['launch_id'] ?? ''),
                \strtolower(\trim((string)($instance['launch_id'] ?? ''))),
            )
        ) {
            return self::block(
                'Gateway owns the instance ID under a different launch or generation.',
                true,
            );
        }
        if ($statusName === 'REMOVED') {
            return [
                'action' => self::ACTION_LOCAL_ONLY,
                'reason' => 'Authenticated own-status proves the exact instance is removed.',
                'status_authenticated' => true,
            ];
        }
        return [
            'action' => self::ACTION_DRAIN,
            'reason' => 'Authenticated own-status proves the exact launch owns gateway state.',
            'status_authenticated' => true,
        ];
    }

    /** @param array<string,mixed> $status */
    private static function authenticatedOwnStatus(
        array $status,
        string $projectUuid,
        string $currentHostBootId,
    ): bool
    {
        $epoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
        $hostBootId = \strtolower(\trim((string)(
            $status['host_boot_id'] ?? ''
        )));
        return ($status['ok'] ?? false) === true
            && ($status['publication_exact'] ?? false) === true
            && \hash_equals(
                \strtolower($projectUuid),
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
            && \hash_equals(
                GatewayPaths::PROTOCOL,
                (string)($status['protocol'] ?? ''),
            )
            && \preg_match('/\A[a-f0-9]{32}\z/D', $epoch) === 1
            && \preg_match('/\A[a-f0-9]{64}\z/D', $hostBootId) === 1
            && \preg_match('/\A[a-f0-9]{64}\z/D', $currentHostBootId) === 1
            && \hash_equals($currentHostBootId, $hostBootId)
            && \is_array($status['instances'] ?? null);
    }

    private static function currentHostBootId(string $provided): string
    {
        $provided = \strtolower(\trim($provided));
        if ($provided !== '') {
            return $provided;
        }
        try {
            return GatewayHostBootIdentity::current();
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array{action:string,reason:string,status_authenticated:bool} */
    private static function block(string $reason, bool $authenticated): array
    {
        return [
            'action' => self::ACTION_BLOCK,
            'reason' => $reason,
            'status_authenticated' => $authenticated,
        ];
    }
}
