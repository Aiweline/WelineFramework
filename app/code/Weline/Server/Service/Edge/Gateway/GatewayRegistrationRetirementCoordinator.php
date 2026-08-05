<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * One retirement transaction shared by ordinary Stop and failed Start.
 *
 * The local CAS fence is always claimed before reading host state. A caller
 * may restore the prior local state when it intends to keep WLS running
 * (ordinary non-force Stop), or retain RETIRING when local startup is already
 * doomed and the host lease must be allowed to expire fail-closed.
 */
final class GatewayRegistrationRetirementCoordinator
{
    private readonly GatewayHostManager $host;

    private readonly GatewayRegistrationLifecycle $lifecycle;

    public function __construct(
        ?GatewayHostManager $host = null,
        ?GatewayRegistrationLifecycle $lifecycle = null,
        private readonly ?\Closure $statusResolver = null,
        private readonly ?\Closure $receiptValidator = null,
        private readonly ?\Closure $drainResolver = null,
        private readonly string $currentHostBootId = '',
    ) {
        $this->host = $host ?? new GatewayHostManager();
        $this->lifecycle = $lifecycle ?? new GatewayRegistrationLifecycle();
    }

    /**
     * @return array{
     *   action:string,
     *   reason:string,
     *   status_authenticated:bool,
     *   previous_state:string,
     *   drain:array<string,mixed>
     * }
     */
    public function retire(
        string $instanceName,
        int $drainSeconds = 300,
        bool $waitForConnections = true,
        bool $restoreOnFailure = true,
    ): array {
        $retirement = [];
        try {
            $retirement = $this->lifecycle->claimRetirement($instanceName);
            $endpoint = \is_array($retirement['endpoint'] ?? null)
                ? $retirement['endpoint']
                : [];
            $decision = GatewayStopRegistrationPolicy::decide(
                $endpoint,
                $this->status(),
                $this->currentHostBootId,
            );
            $action = (string)($decision['action'] ?? 'block');
            if ($action === GatewayStopRegistrationPolicy::ACTION_BLOCK) {
                throw new \RuntimeException((string)(
                    $decision['reason']
                    ?? 'Gateway registration state is not safe for retirement.'
                ));
            }
            if (!\in_array($action, [
                GatewayStopRegistrationPolicy::ACTION_LOCAL_ONLY,
                GatewayStopRegistrationPolicy::ACTION_DRAIN,
            ], true)) {
                throw new \RuntimeException(
                    'Gateway retirement policy returned an unknown action.',
                );
            }

            $drain = [];
            $reason = (string)($decision['reason'] ?? 'gateway_state_absent');
            if ($action === GatewayStopRegistrationPolicy::ACTION_DRAIN) {
                $this->validateReceipt($instanceName);
                $drain = $this->drain(
                    $instanceName,
                    \max(1, \min(300, $drainSeconds)),
                    $waitForConnections,
                );
                if (($drain['unregistered'] ?? false) !== true) {
                    if (($drain['already_removed'] ?? false) !== true) {
                        throw new \RuntimeException(
                            'Gateway did not return a committed unregister result.',
                        );
                    }
                    // `already_removed` can originate from an idempotent error
                    // response without a signed publication body. Require a
                    // fresh authenticated own-status absence before accepting
                    // it as authority to tear down the local backend.
                    $confirmation = GatewayStopRegistrationPolicy::decide(
                        $endpoint,
                        $this->status(),
                        $this->currentHostBootId,
                    );
                    if (($confirmation['action'] ?? '')
                        !== GatewayStopRegistrationPolicy::ACTION_LOCAL_ONLY
                    ) {
                        throw new \RuntimeException(
                            'Gateway already-removed result lacks authenticated launch absence.',
                        );
                    }
                }
                $reason = 'gateway_unregister_committed';
            }

            if (!$this->lifecycle->completeRetirement(
                $instanceName,
                (string)($retirement['nonce'] ?? ''),
                $reason,
            )) {
                throw new \RuntimeException(
                    'Gateway host retirement completed but the local retirement fence changed.',
                );
            }

            return [
                'action' => $action,
                'reason' => $reason,
                'status_authenticated' => ($decision['status_authenticated'] ?? false) === true,
                'previous_state' => (string)($retirement['previous_state'] ?? ''),
                'drain' => $drain,
            ];
        } catch (\Throwable $throwable) {
            if (!$restoreOnFailure) {
                throw $throwable;
            }
            $nonce = \strtolower(\trim((string)($retirement['nonce'] ?? '')));
            if ($nonce === '') {
                throw $throwable;
            }
            try {
                if (!$this->lifecycle->cancelRetirement($instanceName, $nonce)) {
                    throw new \RuntimeException(
                        'Gateway retirement fence changed before it could be restored.',
                    );
                }
            } catch (\Throwable $restoreFailure) {
                throw new \RuntimeException(
                    GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        1024,
                        'Gateway retirement failed.',
                    ) . ' Local retirement restoration also failed: '
                    . GatewayBoundedText::singleLine(
                        $restoreFailure->getMessage(),
                        512,
                        'state restoration failed',
                    ),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /** @return array<string,mixed> */
    private function status(): array
    {
        $status = $this->statusResolver !== null
            ? ($this->statusResolver)()
            : $this->host->status(1.0);
        return \is_array($status) ? $status : [];
    }

    private function validateReceipt(string $instanceName): void
    {
        if ($this->receiptValidator !== null) {
            ($this->receiptValidator)($instanceName);
            return;
        }
        $this->host->validatedLeaseReceiptForInstance($instanceName);
    }

    /** @return array<string,mixed> */
    private function drain(
        string $instanceName,
        int $seconds,
        bool $waitForConnections,
    ): array {
        $drain = $this->drainResolver !== null
            ? ($this->drainResolver)($instanceName, $seconds, $waitForConnections)
            : $this->host->drain($instanceName, $seconds, $waitForConnections);
        if (!\is_array($drain)) {
            throw new \RuntimeException('Gateway drain returned an invalid result.');
        }
        return $drain;
    }
}
