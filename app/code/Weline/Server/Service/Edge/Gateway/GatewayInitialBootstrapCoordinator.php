<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Elects exactly one first project to establish the project-independent host
 * gateway. The project package is verified before any host-root mutation and
 * is verified again by install() inside the host bootstrap lock.
 */
final class GatewayInitialBootstrapCoordinator implements GatewayStartupBootstrapperInterface
{
    public function __construct(
        private readonly GatewayInitialBootstrapOperationsInterface $operations = new GatewayInitialBootstrapOperations(),
    ) {
    }

    public function bootstrap(
        array $observedStatus,
        float $deadlineMonotonic,
    ): array {
        self::assertDeadline($deadlineMonotonic);
        try {
            $winner = $this->operations->status($deadlineMonotonic);
            if (self::admissionCapable($winner)) {
                return $winner + [
                    'established' => false,
                    'bootstrap_result' => 'concurrent_gateway',
                ];
            }
        } catch (\Throwable) {
            // A read-only winner recheck is opportunistic. Package and locked
            // discovery below retain the same absolute deadline and fail
            // closed if the host remains unavailable.
        }
        try {
            $package = $this->operations->resolveProjectReleasePackage();
        } catch (\Throwable $throwable) {
            return self::failure(
                'PACKAGE_INVALID',
                $throwable->getMessage(),
            );
        }
        if (($package['ok'] ?? false) !== true) {
            return self::failure(
                (string)($package['state'] ?? 'PACKAGE_UNAVAILABLE'),
                (string)($package['reason'] ?? 'No signed project gateway release package is available.'),
            ) + ['target_profile' => (string)($package['target_profile'] ?? '')];
        }
        $packagePath = \trim((string)($package['path'] ?? ''));
        if ($packagePath === '') {
            return self::failure(
                'PACKAGE_INVALID',
                'The project gateway release package path is empty.',
            );
        }
        $profile = 'default';
        try {
            self::assertDeadline($deadlineMonotonic);
            // Exact signature/profile/components are checked before creating
            // the host lock. install() repeats the same verification under
            // the lock so a project-side TOCTOU cannot cross the trust edge.
            $verification = $this->operations->preflightProjectReleasePackage(
                $packagePath,
                $profile,
                $deadlineMonotonic,
            );
            $fingerprint = self::packageFingerprint(
                $package,
                $verification,
                $profile,
            );
        } catch (\Throwable $throwable) {
            return self::failure('PACKAGE_INVALID', $throwable->getMessage());
        }

        try {
            return $this->operations->synchronized(
                function () use (
                    $observedStatus,
                    $deadlineMonotonic,
                    $profile,
                    $fingerprint,
                ): array {
                    self::assertDeadline($deadlineMonotonic);
                    try {
                        $lockedPackage = $this->operations
                            ->resolveProjectReleasePackage();
                        if (($lockedPackage['ok'] ?? false) !== true) {
                            throw new \RuntimeException((string)(
                                $lockedPackage['reason']
                                    ?? 'The project gateway package disappeared.'
                            ));
                        }
                        $lockedPath = \trim((string)(
                            $lockedPackage['path'] ?? ''
                        ));
                        $lockedVerification = $this->operations
                            ->preflightProjectReleasePackage(
                                $lockedPath,
                                $profile,
                                $deadlineMonotonic,
                            );
                        $lockedFingerprint = self::packageFingerprint(
                            $lockedPackage,
                            $lockedVerification,
                            $profile,
                        );
                        if (!\hash_equals($fingerprint, $lockedFingerprint)) {
                            throw new \RuntimeException(
                                'The project gateway release package changed while acquiring the host bootstrap lock.',
                            );
                        }
                    } catch (\Throwable $throwable) {
                        return self::failure(
                            'PACKAGE_CHANGED',
                            $throwable->getMessage(),
                        );
                    }

                    self::assertDeadline($deadlineMonotonic);
                    $current = $this->operations->status($deadlineMonotonic);
                    if (self::admissionCapable($current)) {
                        return $current + [
                            'established' => false,
                            'bootstrap_result' => 'concurrent_gateway',
                        ];
                    }

                    self::assertDeadline($deadlineMonotonic);
                    $prepared = $this->operations->prepare(
                        $current !== [] ? $current : $observedStatus,
                        $deadlineMonotonic,
                    );
                    if (!\in_array((string)($prepared['state'] ?? ''), [
                        'INSTALL_REQUIRED',
                        'BOOTSTRAP_RECOVERY_REQUIRED',
                    ], true)) {
                        return $prepared + [
                            'established' => false,
                            'bootstrap_result' => 'not_installable',
                        ];
                    }
                    $preparedProfile = \strtolower(\trim((string)(
                        $prepared['listen_profile'] ?? $profile
                    )));
                    if (!\hash_equals($profile, $preparedProfile)) {
                        return self::failure(
                            'REPAIR_REQUIRED',
                            'Virgin-host first installation requires the fixed default listen profile; persisted alternate profile state requires explicit repair.',
                        );
                    }

                    self::assertDeadline($deadlineMonotonic);
                    $installed = $this->operations->install(
                        $lockedPath,
                        $preparedProfile,
                        $deadlineMonotonic,
                    );
                    if (self::trustedEstablished($installed)) {
                        return $installed + [
                            'established' => true,
                            'bootstrap_result' => 'established',
                        ];
                    }

                    // install() can lose its response after another explicit
                    // administrator transaction commits. One bounded, signed
                    // status recheck distinguishes that case from a partial
                    // or test install; it never opens a fresh deadline.
                    self::assertDeadline($deadlineMonotonic);
                    $after = $this->operations->status($deadlineMonotonic);
                    if (self::trustedEstablished($after)) {
                        return $after + [
                            'established' => false,
                            'bootstrap_result' => 'concurrent_gateway',
                        ];
                    }
                    return self::failure(
                        'BOOTSTRAP_UNTRUSTED',
                        (string)($installed['reason']
                            ?? 'The installed gateway did not become trusted and ready.'),
                    ) + [
                        'install_state' => (string)($installed['state'] ?? ''),
                        'bootstrap_result' => 'untrusted',
                    ];
                },
                $deadlineMonotonic,
            );
        } catch (\Throwable $throwable) {
            return self::failure(
                'BOOTSTRAP_UNAVAILABLE',
                $throwable->getMessage(),
            );
        }
    }

    /** @param array<string,mixed> $status */
    private static function admissionCapable(array $status): bool
    {
        return GatewayHostManager::controlPlaneAcceptsRegistration($status);
    }

    /** @param array<string,mixed> $status */
    private static function trustedEstablished(array $status): bool
    {
        return self::admissionCapable($status)
            && ($status['ready'] ?? false) === true
            && ($status['data_plane']['running'] ?? false) === true
            && !\hash_equals('DATA_PLANE_DOWN', (string)($status['state'] ?? ''));
    }

    private static function assertDeadline(float $deadlineMonotonic): void
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($deadlineMonotonic) || $deadlineMonotonic <= $now) {
            throw new \RuntimeException(
                'The initial gateway bootstrap deadline was exhausted.',
            );
        }
    }

    /**
     * @param array<string,mixed> $package
     * @param array<string,mixed> $verification
     */
    private static function packageFingerprint(
        array $package,
        array $verification,
        string $listenProfile,
    ): string {
        $fields = [
            'project_root' => self::canonicalFingerprintPath((string)(
                $package['project_root'] ?? ''
            )),
            'package_path' => self::canonicalFingerprintPath((string)(
                $package['path'] ?? ''
            )),
            'verified_package_path' => self::canonicalFingerprintPath((string)(
                $verification['package_dir'] ?? ''
            )),
            'target_profile' => \strtolower(\trim((string)(
                $package['target_profile'] ?? ''
            ))),
            'listen_profile' => \strtolower(\trim($listenProfile)),
            'package_digest' => self::requiredDigest(
                $verification,
                'package_digest',
            ),
            'manifest_digest' => self::requiredDigest(
                $verification,
                'manifest_digest',
            ),
            'signature_digest' => self::requiredDigest(
                $verification,
                'signature_digest',
            ),
        ];
        if ($fields['project_root'] === ''
            || $fields['package_path'] === ''
            || $fields['verified_package_path'] === ''
            || !\hash_equals(
                $fields['package_path'],
                $fields['verified_package_path'],
            )
            || $fields['target_profile'] === ''
        ) {
            throw new \RuntimeException(
                'The project gateway package fingerprint is incomplete or inconsistent.',
            );
        }
        $encoded = \json_encode(
            $fields,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        return \hash('sha256', $encoded);
    }

    /** @param array<string,mixed> $verification */
    private static function requiredDigest(
        array $verification,
        string $field,
    ): string {
        $digest = \strtolower(\trim((string)(
            $verification[$field] ?? ''
        )));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new \RuntimeException(
                'The project gateway package ' . $field . ' is invalid.',
            );
        }
        return $digest;
    }

    private static function canonicalFingerprintPath(string $path): string
    {
        $path = \rtrim(\str_replace('\\', '/', \trim($path)), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
        }
        return $path;
    }

    /** @return array{ok:false,ready:false,state:string,reason:string} */
    private static function failure(string $state, string $reason): array
    {
        $state = \strtoupper(\trim($state));
        $reason = GatewayBoundedText::singleLine(
            $reason,
            1024,
            'Initial gateway bootstrap failed.',
        );
        return [
            'ok' => false,
            'ready' => false,
            'state' => $state !== '' ? $state : 'BOOTSTRAP_UNAVAILABLE',
            'reason' => $reason,
        ];
    }
}
