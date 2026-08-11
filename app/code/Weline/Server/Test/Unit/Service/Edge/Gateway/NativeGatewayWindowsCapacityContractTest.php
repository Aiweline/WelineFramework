<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayWindowsCapacityContractTest extends TestCase
{
    private string $capacity;
    private string $launcher;
    private string $cmake;
    private string $fixture;
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $server = \dirname(__DIR__, 5);
        $native = $server . '/Service/Edge/Gateway/Native';
        $this->capacity = $this->read(
            $native . '/windows/wls_gateway_capacity.c',
        );
        $this->launcher = $this->read(
            $native . '/windows/wls_gateway_launcher.c',
        );
        $this->cmake = $this->read($native . '/CMakeLists.txt');
        $this->fixture = $this->read(
            $server
                . '/Test/Integration/Service/Edge/Gateway/windows_service_recovery.php',
        );
        $this->workflow = $this->read(
            \dirname($server, 4) . '/.github/workflows/wls-gateway-native.yml',
        );
    }

    public function testPhysicalAllocationAndInodeIdentityProofAreNative(): void
    {
        foreach ([
            '#define WLS_CAPACITY_PRODUCTION_BYTES 10737418240ULL',
            '#define WLS_CAPACITY_PRODUCTION_INODES 65536U',
            '#define WLS_CAPACITY_TOKEN_FLUSH_BATCH 4096U',
            'FileAllocationInfo',
            'FileEndOfFileInfo',
            'standard.AllocationSize.QuadPart',
            'FILE_ATTRIBUTE_SPARSE_FILE',
            'FILE_ATTRIBUTE_COMPRESSED',
            'FILE_ATTRIBUTE_ENCRYPTED',
            'FILE_ATTRIBUTE_REPARSE_POINT',
            'FILE_ID_INFO identity',
            'FILE_ID_128 *identities',
            'observation.standard.EndOfFile.QuadPart != 0LL',
            'observation.standard.NumberOfLinks != 1U',
            'qsort(',
            'FlushFileBuffers(volume)',
        ] as $required) {
            self::assertStringContainsString($required, $this->capacity);
        }

        $createTokens = $this->between(
            $this->capacity,
            'static int wls_capacity_create_tokens(',
            'static int wls_capacity_parse_token_leaf(',
        );
        self::assertStringContainsString(
            '(index + 1U) % WLS_CAPACITY_TOKEN_FLUSH_BATCH == 0U',
            $createTokens,
        );
        self::assertStringNotContainsString(
            'FlushFileBuffers(token)',
            $createTokens,
            'Token creation must batch durable volume metadata instead of flushing every token.',
        );
    }

    public function testProgramDataAuthorityIgnoresEnvironmentAndRejectsReparseRoots(): void
    {
        foreach ([
            'SHGetKnownFolderPath(',
            'wls_capacity_folderid_programdata',
            'GetLongPathNameW(',
            'GetDriveTypeW(root) == DRIVE_FIXED',
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'wls_capacity_validate_directory_chain(',
            'wls_capacity_parent_acl_safe(program_data_directory)',
            'wls_capacity_acl_safe(handle)',
            'sid_length != (DWORD)header->AceSize - sid_offset',
        ] as $required) {
            self::assertStringContainsString($required, $this->capacity);
        }
        self::assertDoesNotMatchRegularExpression(
            '/(?:GetEnvironmentVariableW|_wgetenv|getenv)\s*\([^\n]*PROGRAMDATA/i',
            $this->capacity,
        );
        self::assertStringContainsString(
            'wls_windows_programdata_authority()',
            $this->launcher,
        );
    }

    public function testHeldCannotBypassDurableReleaseTransition(): void
    {
        $command = $this->between(
            $this->capacity,
            'int wls_windows_capacity_command(',
            null,
        );
        $manifest = \strpos($command, 'wls_capacity_manifest_binding(');
        $live = \strpos($command, 'wls_capacity_live_present(');
        self::assertIsInt($manifest);
        self::assertIsInt($live);
        self::assertLessThan(
            $live,
            $manifest,
            'Manifest identity must be authenticated before live-state mutation.',
        );
        self::assertStringContainsString(
            'capacity reserve release transition was not started',
            $command,
        );
        self::assertStringContainsString(
            'WLS_CAPACITY_CONTROL_ABSENT',
            $this->between(
                $command,
                'if (held_present) {',
                '(void)printf("{\\"state\\":\\"RELEASED\\"}\\n");',
            ),
        );
        foreach ([
            'WLS_CAPACITY_CONTROL_REQUIRED',
            'WLS_CAPACITY_CONTROL_TRANSITION',
            'WLS_CAPACITY_CONTROL_ABSENT',
            'WLS_CAPACITY_RELEASE_MARKER',
            'MOVEFILE_WRITE_THROUGH',
        ] as $required) {
            self::assertStringContainsString($required, $this->capacity);
        }
        $marker = $this->between(
            $this->capacity,
            'static int wls_capacity_control_marker(',
            'static int wls_capacity_directory_optional(',
        );
        self::assertStringNotContainsString(
            'marker[0]',
            $marker,
            'A torn release-marker write remains crash-replayable while all control tokens exist.',
        );
    }

    public function testEveryRebootstrapDerivedRootIsAnchoredBeforeMutation(): void
    {
        $proof = $this->between(
            $this->capacity,
            'static int wls_capacity_anchor_proof(',
            'static int wls_capacity_manifest_binding(',
        );
        foreach ([
            'L"state"',
            'L"trust"',
            'L"runtime"',
            'L"runtime\\\\conf"',
            'L"runtime\\\\temp"',
            'L"runtime\\\\shadow"',
            'L"runtime\\\\run"',
            'L"snapshots"',
            'L"snapshots-v2"',
            'L"snapshot-candidates-v2"',
        ] as $required) {
            self::assertStringContainsString($required, $proof);
        }
        foreach ([
            'wls_capacity_hash_anchor_path(',
            'observation.identity.VolumeSerialNumber == expected_volume',
            'wls_capacity_open_directory(',
        ] as $required) {
            self::assertStringContainsString($required, $this->capacity);
        }

        $command = $this->between(
            $this->capacity,
            'int wls_windows_capacity_command(',
            null,
        );
        $anchor = \strpos($command, 'wls_capacity_anchor_proof(');
        $liveMutation = \strpos($command, 'wls_capacity_create_live(');
        self::assertIsInt($anchor);
        self::assertIsInt($liveMutation);
        self::assertLessThan(
            $liveMutation,
            $anchor,
            'Every movable root must be pinned to the authority volume before reserve state can advance.',
        );
    }

    public function testWindowsBuildAndRealIntegrationOwnTheCapacityContract(): void
    {
        foreach ([
            'windows/wls_gateway_capacity.c',
            'advapi32',
            'ole32',
            'shell32',
            '/W4 /WX /sdl',
        ] as $required) {
            self::assertStringContainsString($required, $this->cmake);
        }
        foreach ([
            '--capacity-reserve-contract-self-test',
            'WLS_RUN_NATIVE_GATEWAY_WINDOWS_CAPACITY_INTEGRATION',
            'capacity-test',
            '--launcher=build/wls-gateway-native/Release/wls-gateway-launcher.exe',
        ] as $required) {
            self::assertStringContainsString($required, $this->workflow);
        }
        foreach ([
            'Windows physical reserve create',
            'Windows HELD reserve bypassed begin-release.',
            'Windows torn release marker was not replayable.',
            'Partial Windows control credits failed open.',
            'Missing Windows control reserve failed open.',
            'Windows ALLOCATING cancellation did not converge.',
            'Windows derived-root junction/other-volume anchor failed open before HELD.',
        ] as $required) {
            self::assertStringContainsString($required, $this->fixture);
        }
    }

    public function testProductionCapacityIsAnExplicitDedicatedWindowsCrashMatrixGate(): void
    {
        foreach ([
            'schedule:',
            'run_windows_production_capacity',
            'windows-production-capacity',
            'wls-gateway-capacity',
            'WLS_RUN_NATIVE_GATEWAY_WINDOWS_PRODUCTION_CAPACITY_INTEGRATION',
            'capacity-production-test',
            '--bytes=10737418240',
            '--inodes=65536',
            '--test-mode=0',
            "'token-directory'",
            "'direct-seal'",
            "'control-token-partial'",
            "'primary-token-partial'",
            'wlsCapacityKillAtFailpoint(',
            'wlsCapacityRecoverDurableMarker(',
            'FOLDERID_ProgramData',
        ] as $required) {
            self::assertStringContainsString($required, $this->workflow . $this->fixture);
        }
    }

    public function testInspectMatchesTheReadOnlyFailClosedCapacityProtocol(): void
    {
        foreach ([
            '#define WLS_CAPACITY_INSPECT_SCHEMA "wls-capacity-inspect/1"',
            '#define WLS_CAPACITY_INSPECT_UNSAFE_EXIT 77',
            '#define WLS_CAPACITY_INSPECT_CONFLICT_EXIT 78',
            'static void wls_capacity_print_inspect(',
            '{\\"schema\\":\\"%s\\",\\"state\\":\\"%s\\"}',
        ] as $required) {
            self::assertStringContainsString($required, $this->capacity);
        }

        $command = $this->between(
            $this->capacity,
            'int wls_windows_capacity_command(',
            null,
        );
        self::assertStringContainsString(
            'inspect_operation = wcscmp(operation, L"inspect") == 0;',
            $command,
        );
        self::assertStringContainsString(
            '(inspect_operation && manifest_argument != NULL)',
            $command,
        );
        self::assertStringContainsString('|| inspect_operation)', $command);
        self::assertStringContainsString('&& reason != NULL)', $command);
        self::assertStringContainsString(
            'result = WLS_CAPACITY_INSPECT_CONFLICT_EXIT;',
            $command,
        );

        $inspect = $this->between(
            $command,
            'if (inspect_operation) {',
            'if (wcscmp(operation, L"create") == 0) {',
        );
        foreach ([
            'wls_capacity_print_inspect("NONE")',
            'wls_capacity_print_inspect("ALLOCATING")',
            'wls_capacity_print_inspect("HELD")',
            'wls_capacity_print_inspect("RELEASING")',
            'wls_capacity_validate_removable_live(',
            'wls_capacity_validate_live(',
            'wls_capacity_detect_control_state(',
            'WLS_CAPACITY_INSPECT_UNSAFE_EXIT',
        ] as $required) {
            self::assertStringContainsString($required, $inspect);
        }
        foreach ([
            'wls_capacity_create_live(',
            'wls_capacity_remove_live(',
            'MoveFileExW(',
            'DeleteFileW(',
            'RemoveDirectoryW(',
            'FlushFileBuffers(',
        ] as $mutation) {
            self::assertStringNotContainsString($mutation, $inspect);
        }
        self::assertStringContainsString(
            "!inspect_operation\n            && wls_capacity_volume_handle(",
            $command,
            'Inspect must not acquire the mutating volume handle.',
        );
        self::assertStringContainsString(
            'inspect_operation ? 0 : 1,',
            $command,
            'Inspect must not request a writable capacity-directory handle.',
        );
    }

    public function testDefinitionParentCreditsAreDurableAndLifecycleBound(): void
    {
        foreach ([
            '#define WLS_CAPACITY_PLATFORM_BYTES 4194304ULL',
            '#define WLS_CAPACITY_PLATFORM_INODES 2U',
            '#define WLS_CAPACITY_PLATFORM_BYTES_PER_FILE 2097152ULL',
            'struct wls_capacity_platform_anchor',
            'wls_capacity_platform_reserve_state(',
            'wls_capacity_platform_reserve_create(',
            'wls_capacity_platform_reserve_release(',
            'wls_capacity_platform_direct_acl_exact(',
            'PROTECTED_DACL_SECURITY_INFORMATION',
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'standard.NumberOfLinks != 1U',
        ] as $required) {
            self::assertStringContainsString($required, $this->capacity);
        }

        $command = $this->between(
            $this->capacity,
            'int wls_windows_capacity_command(',
            null,
        );
        $create = $this->between(
            $command,
            'if (wcscmp(operation, L"create") == 0) {',
            'if (wcscmp(operation, L"verify") == 0) {',
        );
        foreach ([
            'wls_capacity_platform_reserve_create(',
            'wls_capacity_platform_reserve_verify(',
            'wls_capacity_platform_reserve_cleanup_allocating(',
        ] as $required) {
            self::assertStringContainsString($required, $create);
        }

        $begin = $this->between(
            $command,
            'if (wcscmp(operation, L"begin-release") == 0) {',
            'if (held_present) {',
        );
        $transition = \strpos($begin, 'wls_capacity_prepare_release(');
        $directRelease = \strpos(
            $begin,
            'wls_capacity_platform_reserve_release(',
        );
        $finish = \strpos($begin, 'wls_capacity_finish_release_control(');
        self::assertIsInt($transition);
        self::assertIsInt($directRelease);
        self::assertIsInt($finish);
        self::assertGreaterThan($transition, $directRelease);
        self::assertGreaterThan($directRelease, $finish);

        $complete = $this->between(
            $command,
            'if (held_present) {',
            '(void)printf("{\\"state\\":\\"RELEASED\\"}\\n");',
        );
        self::assertStringContainsString(
            'wls_capacity_platform_reserve_absent(',
            $complete,
        );
        self::assertStringContainsString(
            'wls_capacity_platform_reserve_cleanup_allocating(',
            $complete,
        );
    }

    public function testProductionCrashGateKillsRealHelpersAndRecoversDurableState(): void
    {
        foreach ([
            'if(WLS_BUILD_TEST_HELPERS)',
            'WLS_NATIVE_TEST_HOOKS=1',
        ] as $testBuildFence) {
            self::assertStringContainsString($testBuildFence, $this->cmake);
        }
        self::assertStringContainsString(
            '-DWLS_BUILD_TEST_HELPERS=ON',
            $this->workflow,
        );
        foreach ([
            '#if defined(WLS_NATIVE_TEST_HOOKS)',
            'WLS_CAPACITY_TEST_FAILPOINT',
            'wls_capacity_test_failpoint(L"allocation")',
            'wls_capacity_test_failpoint(L"token-batch")',
            'wls_capacity_test_failpoint(L"rename")',
            'wls_capacity_test_failpoint(L"begin")',
            'wls_capacity_test_failpoint(L"release")',
            'wls_capacity_test_failpoint(L"token-directory")',
            'wls_capacity_test_failpoint(L"direct-seal")',
            'L"control-token-partial"',
            'L"primary-token-partial"',
        ] as $required) {
            self::assertStringContainsString($required, $this->capacity);
        }
        foreach ([
            'function wlsCapacityKillAtFailpoint(',
            'function wlsCapacityRecoverDurableMarker(',
            'proc_open(',
            'proc_terminate(',
            "'schema' => 'wls-capacity-production-gate/1'",
            "'allocation'",
            "'token-batch'",
            "'rename'",
            "'begin'",
            "'release'",
            "'token-directory'",
            "'direct-seal'",
            "'control-token-partial'",
            "'primary-token-partial'",
            "'schema' => 'wls-capacity-inspect/1'",
            "'state' => 'ALLOCATING'",
            "=== 'HELD'",
            "=== 'RELEASING'",
        ] as $required) {
            self::assertStringContainsString($required, $this->fixture);
        }
        foreach ([
            'concurrency:',
            'wls-windows-production-capacity',
            'cancel-in-progress: false',
        ] as $required) {
            self::assertStringContainsString($required, $this->workflow);
        }
    }

    public function testCrashIntermediatesKeepExactAclAndReplayableState(): void
    {
        $directoryCreate = $this->between(
            $this->capacity,
            'static int wls_capacity_create_directory(',
            'static int wls_capacity_allocate_file(',
        );
        foreach ([
            'SECURITY_ATTRIBUTES attributes',
            'wls_capacity_directory_descriptor(',
            'CreateDirectoryW(path, &attributes)',
            'wls_capacity_platform_direct_apply(directory, security)',
            'wls_capacity_directory_acl_exact(',
            'if (result != 0 && created)',
        ] as $required) {
            self::assertStringContainsString($required, $directoryCreate);
        }

        $allocation = $this->between(
            $this->capacity,
            'static int wls_capacity_allocate_file(',
            'static int wls_capacity_token_leaf(',
        );
        self::assertStringContainsString(
            'if (result != 0 && created) (void)DeleteFileW(path);',
            $allocation,
        );

        $tokens = $this->between(
            $this->capacity,
            'static int wls_capacity_create_tokens(',
            'static int wls_capacity_parse_token_leaf(',
        );
        foreach ([
            'wls_capacity_platform_direct_descriptor(',
            '&attributes',
            'wls_capacity_platform_direct_apply(token, security)',
            'wls_capacity_platform_direct_acl_exact(',
            'if (created) (void)DeleteFileW(path);',
        ] as $required) {
            self::assertStringContainsString($required, $tokens);
        }

        foreach ([
            'wls_capacity_platform_reserve_staging_leaf(',
            'wls_capacity_platform_staging_file_state(',
            'MOVEFILE_WRITE_THROUGH',
            'L"%ls.%u.staging"',
        ] as $required) {
            self::assertStringContainsString($required, $this->capacity);
        }

        $command = $this->between(
            $this->capacity,
            'int wls_windows_capacity_command(',
            null,
        );
        $rename = \strpos($command, 'MoveFileExW(held, releasing');
        $prepare = \strpos($command, 'wls_capacity_prepare_release(');
        self::assertIsInt($rename);
        self::assertIsInt($prepare);
        self::assertLessThan(
            $prepare,
            $rename,
            'The durable RELEASING namespace must exist before token deletion starts.',
        );
        self::assertStringContainsString(
            'wls_capacity_token_prefix_exact(',
            $this->capacity,
        );
        foreach ([
            'int replay_control_state = wls_capacity_detect_control_state(',
            'int replay_platform_state = wls_capacity_platform_reserve_state(',
            'replay_control_state == WLS_CAPACITY_CONTROL_REQUIRED',
            'replay_control_state == WLS_CAPACITY_CONTROL_ABSENT',
        ] as $replayFence) {
            self::assertStringContainsString($replayFence, $command);
        }
        self::assertStringContainsString(
            "if (\$beginReplay['code'] === 0)",
            $this->fixture,
        );
        self::assertStringContainsString(
            'Malformed HELD primary tokens were downgraded to removable state.',
            $this->fixture,
        );
    }

    private function read(string $file): string
    {
        $contents = \file_get_contents($file);
        self::assertIsString($contents, 'Unable to read ' . $file);
        return $contents;
    }

    private function between(
        string $source,
        string $start,
        ?string $end,
    ): string {
        $offset = \strpos($source, $start);
        self::assertIsInt($offset, 'Missing source marker: ' . $start);
        if ($end === null) {
            return \substr($source, $offset);
        }
        $limit = \strpos($source, $end, $offset + \strlen($start));
        self::assertIsInt($limit, 'Missing source marker: ' . $end);
        return \substr($source, $offset, $limit - $offset);
    }
}
