<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayEmergencyBindingContractTest extends TestCase
{
    private string $posix;
    private string $windows;
    private string $controller;

    protected function setUp(): void
    {
        $server = \dirname(__DIR__, 5);
        $native = $server . '/Service/Edge/Gateway/Native';
        $this->posix = (string)\file_get_contents(
            $native . '/posix/wls_gateway_broker.c',
        );
        $this->windows = (string)\file_get_contents(
            $native . '/windows/wls_gateway_broker.c',
        );
        $this->controller = (string)\file_get_contents(
            $server . '/bin/wls_gateway_controller.php',
        );
    }

    public function testControllerAndBothBrokersShareTheExactBindingEnvelope(): void
    {
        $controllerBinding = $this->between(
            $this->controller,
            'private function bindBrokerEmergencyCredential(',
            'private function abortBrokerCertificateRoots(',
        );
        foreach ([
            "'EMERGENCY_BIND'",
            '$projectUuid',
            '$transactionId',
            '$intentDigest',
            '(string)$securityGeneration',
            '$this->brokerOwnerIdentity($owner)',
            '$credentialId',
            '(string)$credentialGeneration',
            '$credentialSecret',
        ] as $field) {
            self::assertStringContainsString($field, $controllerBinding);
        }
        self::assertStringContainsString('\\count($response) !== 5', $controllerBinding);

        foreach ([$this->posix, $this->windows] as $source) {
            $dispatcher = $this->between(
                $source,
                'static int wls_handle_action_v2(',
                'static const char *wls_json_value(',
                2,
            );
            $this->assertNeedlesInOrder($dispatcher, [
                'if (strcmp(channel',
                '"admin"',
                'strcmp(fields[1], "EMERGENCY_BIND") == 0',
                'count != 10U',
                '"DENIED"',
                'fields[9]',
            ]);
            self::assertStringNotContainsString('"EMERGENCY_REVOKE"', $dispatcher);
        }
    }

    public function testPosixBindingIsCommittedOwnerFencedAndSecretPrivate(): void
    {
        $binding = $this->between(
            $this->posix,
            'static int wls_emergency_bind_v1(',
            'static int wls_emergency_domain(',
        );
        foreach ([
            'wls_security_summary(',
            '!security_summary.committed_found',
            'security_summary.assigned != security_generation',
            'wls_auth_collect(',
            'security_summary.transaction_anchor, committed_digest',
            'roots[index].owner != owner',
            'roots[index].expected_count != (unsigned long)root_count',
            'credential_generation < existing.credential_generation',
            'credential_generation == existing.credential_generation',
            'wls_security_append_locked(',
            'WLS-ACTION/2\\tOK\\tEMERGENCY_BIND',
        ] as $required) {
            self::assertStringContainsString($required, $binding);
        }
        self::assertStringNotContainsString(
            'WLS_MAYBE_UNUSED wls_emergency_bind_v1',
            $this->posix,
        );

        $open = $this->between(
            $this->posix,
            'static int wls_emergency_file_open_locked(',
            'static int wls_emergency_parse_binding(',
        );
        foreach ([
            'O_NOFOLLOW',
            'LOCK_EX',
            'status.st_nlink != 1',
            'status.st_uid != geteuid()',
            'wls_posix_acl_remove_and_verify(*fd, 0)',
        ] as $required) {
            self::assertStringContainsString($required, $open);
        }
        self::assertStringContainsString(
            '"trust/emergency-credentials-v1.tsv", 0600',
            $binding,
        );

        $collector = $this->between(
            $this->posix,
            'static int wls_emergency_collect_binding(',
            'static int wls_emergency_bind_v1(',
        );
        self::assertStringContainsString(
            'parsed.credential_generation',
            $collector,
        );
        self::assertStringContainsString(
            '== selected->credential_generation',
            $collector,
        );
        self::assertStringContainsString(
            'strcmp(parsed.secret, selected->secret) != 0',
            $collector,
        );
    }

    public function testWindowsBindingHasEquivalentAclLedgerAndConflictSemantics(): void
    {
        $open = $this->between(
            $this->windows,
            'static int wls_win_emergency_open_locked(',
            'static int wls_win_emergency_parse_binding(',
        );
        foreach ([
            'L"trust\\\\emergency-credentials-v1.tsv"',
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'FILE_FLAG_WRITE_THROUGH',
            'LOCKFILE_EXCLUSIVE_LOCK',
            'info.NumberOfLinks != 1U',
            'wls_secure_root_only_handle(*file)',
        ] as $required) {
            self::assertStringContainsString($required, $open);
        }

        $binding = $this->between(
            $this->windows,
            'static int wls_win_emergency_bind_v1(',
            'static int wls_win_auth_transfer_target(',
        );
        foreach ([
            'wls_win_security_summary(',
            '!security_summary.committed_found',
            'security_summary.assigned != security_generation',
            'wls_win_auth_collect(',
            'security_summary.transaction_anchor, committed_digest',
            '_stricmp(roots[index].owner, owner) != 0',
            'roots[index].expected_count',
            'wls_win_emergency_collect_binding(',
            'credential_generation < existing.credential_generation',
            'wls_win_emergency_binding_equal(&existing, &requested)',
            'wls_win_security_append_locked(binding_file, record)',
            'WLS-ACTION/2\\tOK\\tEMERGENCY_BIND',
            '"BINDING_CONFLICT" : "LEDGER_INVALID"',
            'SecureZeroMemory(record, sizeof(record))',
        ] as $required) {
            self::assertStringContainsString($required, $binding);
        }

        $collector = $this->between(
            $this->windows,
            'static int wls_win_emergency_collect_binding(',
            'static int wls_win_emergency_bind_v1(',
        );
        self::assertStringContainsString(
            '== selected->credential_generation',
            $collector,
        );
        self::assertStringContainsString(
            'wls_win_emergency_binding_equal(&parsed, selected)',
            $collector,
        );
    }

    private function between(
        string $source,
        string $startNeedle,
        ?string $endNeedle,
        int $occurrence = 1,
    ): string {
        $offset = 0;
        $start = false;
        for ($index = 0; $index < $occurrence; $index++) {
            $start = \strpos($source, $startNeedle, $offset);
            self::assertIsInt($start, 'Missing source contract: ' . $startNeedle);
            $offset = $start + \strlen($startNeedle);
        }
        if ($endNeedle === null) {
            return \substr($source, $start);
        }
        $end = \strpos($source, $endNeedle, $offset);
        self::assertIsInt($end, 'Missing source boundary: ' . $endNeedle);
        return \substr($source, $start, $end - $start);
    }

    /** @param list<string> $needles */
    private function assertNeedlesInOrder(string $source, array $needles): void
    {
        $offset = 0;
        foreach ($needles as $needle) {
            $position = \strpos($source, $needle, $offset);
            self::assertIsInt($position, 'Missing ordered contract: ' . $needle);
            $offset = $position + \strlen($needle);
        }
    }
}
