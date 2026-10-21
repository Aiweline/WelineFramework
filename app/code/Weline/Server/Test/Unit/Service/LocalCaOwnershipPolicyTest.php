<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\LocalCaOwnershipPolicy;
use Weline\Server\Service\SslCertificateService;

require_once dirname(__DIR__, 6) . '/bootstrap_phpunit.php';

/**
 * 整机共享 Local CA：只有本仓生成、未共享、且有本仓签发证据的指纹才允许删除。
 */
final class LocalCaOwnershipPolicyTest extends TestCase
{
    private const FINGERPRINT = '8FF068CA0000000000000000000000000000F8C9';

    public function testWorkspaceGeneratedUnsharedCaWithIssuanceProofIsRetireable(): void
    {
        $decision = LocalCaOwnershipPolicy::deletionDecision(
            [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                'shared' => false,
                'domains' => ['p05113ef3.test.weline.com'],
            ],
            [],
            self::FINGERPRINT,
        );

        self::assertSame([
            'retireable' => true,
            'reason' => LocalCaOwnershipPolicy::DECISION_RETIREABLE,
        ], $decision);
    }

    public function testLiveIssuanceProofSatisfiesEvidenceRequirement(): void
    {
        $decision = LocalCaOwnershipPolicy::deletionDecision(
            [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                'shared' => false,
                'domains' => [],
            ],
            ['p05113ef3.test.weline.com'],
            self::FINGERPRINT,
        );

        self::assertTrue($decision['retireable']);
    }

    public function testMissingProvenanceIsNeverRetireable(): void
    {
        $decision = LocalCaOwnershipPolicy::deletionDecision(
            null,
            ['p05113ef3.test.weline.com'],
            self::FINGERPRINT,
        );

        self::assertFalse($decision['retireable']);
        self::assertSame(LocalCaOwnershipPolicy::DECISION_NO_PROVENANCE, $decision['reason']);
    }

    public function testSiblingOrAdoptedCaIsNeverRetireable(): void
    {
        foreach ([
            LocalCaOwnershipPolicy::ORIGIN_ADOPTED_GLOBAL,
            LocalCaOwnershipPolicy::ORIGIN_LEGACY_UNKNOWN,
        ] as $origin) {
            $decision = LocalCaOwnershipPolicy::deletionDecision(
                [
                    'origin' => $origin,
                    'shared' => false,
                    'domains' => ['p05113ef3.test.weline.com'],
                ],
                ['p05113ef3.test.weline.com'],
                self::FINGERPRINT,
            );

            self::assertFalse($decision['retireable'], $origin . ' CA must be preserved.');
            self::assertSame(
                LocalCaOwnershipPolicy::DECISION_FOREIGN_OR_ADOPTED,
                $decision['reason'],
            );
        }
    }

    public function testSharedCaIsNeverRetireable(): void
    {
        $decision = LocalCaOwnershipPolicy::deletionDecision(
            [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                'shared' => true,
                'domains' => ['p05113ef3.test.weline.com'],
            ],
            ['p05113ef3.test.weline.com'],
            self::FINGERPRINT,
        );

        self::assertFalse($decision['retireable']);
        self::assertSame(LocalCaOwnershipPolicy::DECISION_SHARED, $decision['reason']);
    }

    public function testGeneratedCaWithoutIssuanceProofIsPreserved(): void
    {
        $decision = LocalCaOwnershipPolicy::deletionDecision(
            [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                'shared' => false,
                'domains' => [],
            ],
            [],
            self::FINGERPRINT,
        );

        self::assertFalse($decision['retireable']);
        self::assertSame(
            LocalCaOwnershipPolicy::DECISION_NO_ISSUANCE_PROOF,
            $decision['reason'],
        );
    }

    public function testEmptyFingerprintIsNeverRetireable(): void
    {
        $decision = LocalCaOwnershipPolicy::deletionDecision(
            [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                'shared' => false,
                'domains' => ['p05113ef3.test.weline.com'],
            ],
            ['p05113ef3.test.weline.com'],
            '',
        );

        self::assertFalse($decision['retireable']);
        self::assertSame(
            LocalCaOwnershipPolicy::DECISION_MISSING_FINGERPRINT,
            $decision['reason'],
        );
    }

    public function testLinuxTrustAnchorFilenameIsScopedPerCaFingerprint(): void
    {
        $first = LocalCaOwnershipPolicy::linuxTrustAnchorFilename(
            '8FF068CA0000000000000000000000000000F8C9'
        );
        $second = LocalCaOwnershipPolicy::linuxTrustAnchorFilename(
            '546555A400000000000000000000000000005C69'
        );

        self::assertSame('weline-local-development-ca-8ff068ca00000000.crt', $first);
        self::assertNotSame($first, $second, 'Different projects must never overwrite one anchor.');
        self::assertNotSame(
            LocalCaOwnershipPolicy::LINUX_LEGACY_TRUST_ANCHOR,
            $first,
            'The legacy fixed filename must not be reused for new CAs.',
        );
        self::assertMatchesRegularExpression(
            '/\Aweline-local-development-ca-[a-f0-9]{16}\.crt\z/',
            $first,
        );
    }

    public function testLinuxTrustAnchorFilenameFallsBackForMissingFingerprint(): void
    {
        $filename = LocalCaOwnershipPolicy::linuxTrustAnchorFilename('');

        self::assertMatchesRegularExpression(
            '/\Aweline-local-development-ca-[a-f0-9]{16}\.crt\z/',
            $filename,
        );
    }

    public function testRetiredFingerprintStateIsAcceptedByLocalCaStateValidator(): void
    {
        $validate = $this->localCaStateValidator();
        $body = "8FF068CA0000000000000000000000000000F8C9\n"
            . "546555A400000000000000000000000000005C69\n";

        $validate('/tmp/var/server/_local_ca/retired_sha1_fingerprints.txt', $body);

        $this->expectException(\RuntimeException::class);
        $validate('/tmp/var/server/_local_ca/retired_sha1_fingerprints.txt', "not-a-fingerprint\n");
    }

    public function testProvenanceStateIsAcceptedByLocalCaStateValidator(): void
    {
        $validate = $this->localCaStateValidator();
        $body = (string)\json_encode([
            'schema' => LocalCaOwnershipPolicy::SCHEMA,
            'fingerprints' => [
                self::FINGERPRINT => [
                    'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                    'shared' => false,
                    'project_root' => '/repo',
                    'recorded_at' => 1_700_000_000,
                    'domains' => ['p05113ef3.test.weline.com'],
                ],
            ],
        ]);

        $validate('/tmp/var/server/_local_ca/local_ca_provenance.json', $body);

        $this->expectException(\RuntimeException::class);
        $validate('/tmp/var/server/_local_ca/local_ca_provenance.json', '{"schema":"other"}');
    }

    /**
     * `writeLocalCaStateAtomically()` 只允许白名单文件：本用例锁住
     * retired 清单与 provenance 台账这两个 Local CA 状态文件必须被接受，
     * 否则登记会被静默丢弃（历史实现即如此）。
     *
     * @return \Closure(string,string):void
     */
    private function localCaStateValidator(): \Closure
    {
        $service = new class extends SslCertificateService {
            public function __construct()
            {
            }
        };
        $method = new \ReflectionMethod($service, 'assertLocalCaStateContents');
        $method->setAccessible(true);

        return static function (string $path, string $contents) use ($method, $service): void {
            $method->invoke($service, $path, $contents);
        };
    }
}
