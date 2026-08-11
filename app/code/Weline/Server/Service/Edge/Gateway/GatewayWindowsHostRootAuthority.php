<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Resolves and establishes the fixed Windows host trust root without trusting
 * PROGRAMDATA or inheriting a mutable parent DACL during first creation.
 */
final class GatewayWindowsHostRootAuthority
{
    private const CONTROLLER_SERVICE_SID =
        'S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626';
    private const DATA_PLANE_SERVICE_SID =
        'S-1-5-80-3611316956-1833621424-61377994-3153356469-2496947245';
    private const TRUSTED_INSTALLER_SID =
        'S-1-5-80-956008885-3418522649-1831038044-1853292631-2271478464';
    private const PATH_CAPACITY = 4096;
    private const INVALID_HANDLE_VALUE = -1;
    private const FILE_ATTRIBUTE_DIRECTORY = 0x10;
    private const FILE_ATTRIBUTE_REPARSE_POINT = 0x400;
    private const FILE_SHARE_READ = 0x1;
    private const FILE_SHARE_WRITE = 0x2;
    private const OPEN_EXISTING = 3;
    private const FILE_FLAG_OPEN_REPARSE_POINT = 0x00200000;
    private const FILE_FLAG_BACKUP_SEMANTICS = 0x02000000;
    private const FILE_READ_ATTRIBUTES = 0x80;
    private const FILE_TRAVERSE = 0x20;
    private const READ_CONTROL = 0x00020000;
    private const OWNER_SECURITY_INFORMATION = 0x1;
    private const DACL_SECURITY_INFORMATION = 0x4;
    private const PROTECTED_DACL_SECURITY_INFORMATION = 0x80000000;
    private const SE_DACL_PROTECTED = 0x1000;
    private const SE_FILE_OBJECT = 1;
    private const ACCESS_ALLOWED_ACE_TYPE = 0x0;
    private const INHERIT_ONLY_ACE = 0x8;
    private const FILE_DELETE_CHILD = 0x40;
    private const DELETE = 0x00010000;
    private const WRITE_DAC = 0x00040000;
    private const WRITE_OWNER = 0x00080000;
    private const GENERIC_ALL = 0x10000000;
    private const GENERIC_WRITE = 0x40000000;
    private const MAXIMUM_ALLOWED = 0x02000000;
    private const TOKEN_QUERY = 0x0008;
    private const TOKEN_ADJUST_PRIVILEGES = 0x0020;
    private const SE_PRIVILEGE_ENABLED = 0x00000002;
    private const ERROR_SUCCESS = 0;
    private const ERROR_NOT_ALL_ASSIGNED = 1300;

    private static ?string $resolvedHome = null;
    private static bool $homeReady = false;
    private static int $restorePrivilegeDepth = 0;

    /** @var array{kernel:\FFI,shell:\FFI,ole:\FFI,advapi:\FFI}|null */
    private static ?array $ffi = null;

    public static function resolveHome(): string
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows gateway host-root authority is unavailable on this platform.',
            );
        }
        if (self::$resolvedHome !== null) {
            return self::$resolvedHome;
        }
        $ffi = self::bindings();
        $guid = $ffi['shell']->new('GUID');
        $guid->Data1 = 0x62AB5D82;
        $guid->Data2 = 0xFDC1;
        $guid->Data3 = 0x4DC3;
        foreach ([0xA9, 0xDD, 0x07, 0x0D, 0x1D, 0x49, 0x5D, 0x97] as $index => $byte) {
            $guid->Data4[$index] = $byte;
        }
        $known = $ffi['shell']->new('WCHAR *');
        $status = (int)$ffi['shell']->SHGetKnownFolderPath(
            \FFI::addr($guid),
            0,
            null,
            \FFI::addr($known),
        );
        try {
            if ($status !== 0 || \FFI::isNull($known)) {
                throw new \RuntimeException(
                    'WLS Gateway cannot resolve FOLDERID_ProgramData.',
                );
            }
            $programData = self::normalizeLocalFixedPath(
                self::widePointerToUtf8($known),
                $ffi['kernel'],
            );
        } finally {
            if (!\FFI::isNull($known)) {
                $ffi['ole']->CoTaskMemFree($known);
            }
        }
        self::$resolvedHome = $programData . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Gateway';
        return self::$resolvedHome;
    }

    public static function ensureHome(): string
    {
        $home = self::resolveHome();
        if (self::$homeReady) {
            return $home;
        }
        $ffi = self::bindings();
        $programData = \dirname(\dirname($home));
        $weline = \dirname($home);
        return self::withRestorePrivilege(function () use (
            $ffi,
            $programData,
            $weline,
            $home,
        ): string {
            $handles = [];
            $failure = null;
            try {
                $programDataHandle = self::openDirectory(
                    $programData,
                    $ffi['kernel'],
                );
                $handles[] = $programDataHandle;
                self::assertProgramDataAuthority(
                    $programDataHandle,
                    $ffi['advapi'],
                    $ffi['kernel'],
                );
                $programDataVolume = self::directoryVolume(
                    $programDataHandle,
                    $ffi['kernel'],
                );

                $welineHandle = self::createOrOpenExactDirectory(
                    $weline,
                    'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)',
                    $ffi,
                );
                $handles[] = $welineHandle;
                $gatewayHandle = self::createOrOpenExactDirectory(
                    $home,
                    'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;0x1200a9;;;'
                        . self::CONTROLLER_SERVICE_SID . ')',
                    $ffi,
                );
                $handles[] = $gatewayHandle;
                foreach ([$welineHandle, $gatewayHandle] as $handle) {
                    if (!\hash_equals(
                        $programDataVolume,
                        self::directoryVolume($handle, $ffi['kernel']),
                    )) {
                        throw new \RuntimeException(
                            'Windows gateway trust-root crossed its fixed local volume.',
                        );
                    }
                }
            } catch (\Throwable $throwable) {
                $failure = $throwable;
            }
            self::closeHandlesExactly($handles, $ffi['kernel'], $failure);
            self::$homeReady = true;
            return $home;
        });
    }

    /**
     * Establish only the fixed host directories needed before the Windows
     * service SID is usable. Missing paths receive a protected SYSTEM/Admin
     * bootstrap DACL; established paths must already match their exact final
     * profile. Handles are retained without share-delete for the full pass.
     *
     * @param list<string> $directories
     */
    public static function ensureBootstrapDirectories(
        array $directories,
        bool $allowExistingBootstrap = false,
    ): void {
        $home = self::ensureHome();
        $ffi = self::bindings();
        $normalizedHome = self::normalizeLocalFixedPath(
            $home,
            $ffi['kernel'],
        );
        $requested = [];
        foreach ($directories as $directory) {
            if (!\is_string($directory)) {
                throw new \InvalidArgumentException(
                    'Windows gateway bootstrap directory must be a path.',
                );
            }
            $normalized = self::normalizeLocalFixedPath(
                $directory,
                $ffi['kernel'],
            );
            $homePrefix = \strtolower($normalizedHome . '\\');
            if (!\str_starts_with(\strtolower($normalized), $homePrefix)) {
                throw new \RuntimeException(
                    'Windows gateway bootstrap directory escaped the host root.',
                );
            }
            $relative = \str_replace(
                '\\',
                '/',
                \substr($normalized, \strlen($normalizedHome) + 1),
            );
            if (!\array_key_exists($relative, self::bootstrapProfiles())) {
                throw new \RuntimeException(
                    'Windows gateway bootstrap directory is outside the fixed namespace.',
                );
            }
            $requested[$relative] = $normalized;
            while (\str_contains($relative, '/')) {
                $relative = (string)\substr(
                    $relative,
                    0,
                    (int)\strrpos($relative, '/'),
                );
                if (!\array_key_exists($relative, self::bootstrapProfiles())) {
                    throw new \RuntimeException(
                        'Windows gateway bootstrap parent has no fixed profile.',
                    );
                }
                $requested[$relative] = $normalizedHome . '\\'
                    . \str_replace('/', '\\', $relative);
            }
        }
        \uksort(
            $requested,
            static function (string $left, string $right): int {
                $depth = \substr_count($left, '/') <=> \substr_count($right, '/');
                return $depth !== 0 ? $depth : \strcmp($left, $right);
            },
        );

        self::withRestorePrivilege(function () use (
            $ffi,
            $normalizedHome,
            $requested,
            $allowExistingBootstrap,
        ): void {
            $handles = [];
            $failure = null;
            try {
                $homeHandle = self::openDirectory($normalizedHome, $ffi['kernel']);
                $handles[] = $homeHandle;
                $volume = self::directoryVolume($homeHandle, $ffi['kernel']);
                foreach ($requested as $relative => $path) {
                    $profiles = self::bootstrapProfiles()[$relative];
                    if ($allowExistingBootstrap) {
                        $profiles[] = self::bootstrapSddl();
                    }
                    $handle = self::createOrOpenAllowedDirectory(
                        $path,
                        self::bootstrapSddl(),
                        $profiles,
                        $ffi,
                    );
                    $handles[] = $handle;
                    if (!\hash_equals(
                        $volume,
                        self::directoryVolume($handle, $ffi['kernel']),
                    )) {
                        throw new \RuntimeException(
                            'Windows gateway bootstrap directory crossed its fixed local volume.',
                        );
                    }
                }
            } catch (\Throwable $throwable) {
                $failure = $throwable;
            }
            self::closeHandlesExactly($handles, $ffi['kernel'], $failure);
        });
    }

    /**
     * Enable SeRestorePrivilege only while assigning the fixed SYSTEM owner,
     * restore the caller token exactly afterwards, and fail closed when the
     * elevated token does not carry that privilege.
     */
    private static function withRestorePrivilege(\Closure $operation): mixed
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows restore privilege is unavailable on this platform.',
            );
        }
        if (self::$restorePrivilegeDepth > 0) {
            return $operation();
        }
        $ffi = self::bindings();
        $token = $ffi['advapi']->new('HANDLE');
        if ((int)$ffi['advapi']->OpenProcessToken(
                $ffi['kernel']->GetCurrentProcess(),
                self::TOKEN_QUERY | self::TOKEN_ADJUST_PRIVILEGES,
                \FFI::addr($token),
            ) === 0
            || \FFI::isNull($token)
        ) {
            throw new \RuntimeException(
                'WLS Gateway cannot open its elevated Windows process token.',
            );
        }
        $enabled = false;
        $previous = $ffi['advapi']->new('TOKEN_PRIVILEGES');
        $previousLength = $ffi['advapi']->new('DWORD');
        $failure = null;
        $result = null;
        try {
            $luid = $ffi['advapi']->new('LUID');
            if ((int)$ffi['advapi']->LookupPrivilegeValueW(
                    null,
                    self::wideBuffer('SeRestorePrivilege', $ffi['kernel']),
                    \FFI::addr($luid),
                ) === 0
            ) {
                throw new \RuntimeException(
                    'WLS Gateway cannot resolve SeRestorePrivilege.',
                );
            }
            $requested = $ffi['advapi']->new('TOKEN_PRIVILEGES');
            $requested->PrivilegeCount = 1;
            $requested->Privileges[0]->Luid->LowPart = $luid->LowPart;
            $requested->Privileges[0]->Luid->HighPart = $luid->HighPart;
            $requested->Privileges[0]->Attributes = self::SE_PRIVILEGE_ENABLED;
            $ffi['kernel']->SetLastError(0);
            $adjusted = (int)$ffi['advapi']->AdjustTokenPrivileges(
                $token,
                0,
                \FFI::addr($requested),
                \FFI::sizeof($previous),
                \FFI::addr($previous),
                \FFI::addr($previousLength),
            );
            $adjustError = (int)$ffi['kernel']->GetLastError();
            // A successful call may already have changed the process token.
            // Mark it before every subsequent validation so all exceptional
            // exits restore PreviousState instead of leaking elevation.
            $enabled = $adjusted !== 0;
            if ($adjusted === 0
                || $adjustError !== self::ERROR_SUCCESS
                || (int)$previousLength > \FFI::sizeof($previous)
            ) {
                throw new \RuntimeException(
                    $adjustError === self::ERROR_NOT_ALL_ASSIGNED
                        ? 'The elevated Windows token does not provide SeRestorePrivilege.'
                        : 'WLS Gateway could not enable SeRestorePrivilege exactly.',
                );
            }
            ++self::$restorePrivilegeDepth;
            try {
                $result = $operation();
            } catch (\Throwable $throwable) {
                $failure = $throwable;
            } finally {
                --self::$restorePrivilegeDepth;
            }
        } catch (\Throwable $throwable) {
            $failure ??= $throwable;
        }

        $restoreFailure = null;
        if ($enabled) {
            $ffi['kernel']->SetLastError(0);
            $restored = (int)$ffi['advapi']->AdjustTokenPrivileges(
                $token,
                0,
                \FFI::addr($previous),
                0,
                null,
                null,
            );
            $restoreError = (int)$ffi['kernel']->GetLastError();
            if ($restored === 0
                || $restoreError !== self::ERROR_SUCCESS
            ) {
                $restoreFailure = new \RuntimeException(
                    'WLS Gateway could not restore its Windows process privileges.',
                );
            }
        }
        if ((int)$ffi['kernel']->CloseHandle($token) === 0) {
            $restoreFailure ??= new \RuntimeException(
                'WLS Gateway Windows process-token handle did not close cleanly.',
            );
        }
        if ($restoreFailure !== null) {
            throw new \RuntimeException(
                $restoreFailure->getMessage(),
                0,
                $failure,
            );
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }

    /**
     * Return the Access+Owner SDDL read from a no-follow handle while every
     * component from the fixed Gateway root to the target is held without
     * share-delete. Capture never enables a Windows privilege.
     *
     * @param array{device:string,inode:string} $expectedIdentity
     */
    public static function captureExactPathSddl(
        string $path,
        bool $directory,
        array $expectedIdentity,
    ): string {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows gateway path authority capture is unavailable on this platform.',
            );
        }
        $ffi = self::bindings();
        return self::withGatewayPathHandles(
            $path,
            $directory,
            self::READ_CONTROL | self::FILE_READ_ATTRIBUTES,
            $expectedIdentity,
            $ffi,
            static fn (\FFI\CData $handle): string => self::handleSddl(
                $handle,
                $ffi['advapi'],
                $ffi['kernel'],
            ),
        );
    }

    /**
     * Apply one already-canonical protected Access+Owner descriptor entirely
     * in this PHP process. No helper or PowerShell child inherits the scoped
     * SeRestorePrivilege used for the owner assignment.
     *
     * @param array{device:string,inode:string} $expectedIdentity
     */
    public static function applyExactPathSddl(
        string $path,
        bool $directory,
        string $canonicalSddl,
        array $expectedIdentity,
    ): string {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows gateway path authority restore is unavailable on this platform.',
            );
        }
        if (!\hash_equals(
            $canonicalSddl,
            self::canonicalizeSddl($canonicalSddl),
        )) {
            throw new \RuntimeException(
                'Windows gateway restore SDDL is not its exact canonical form.',
            );
        }
        $ffi = self::bindings();
        return self::withRestorePrivilege(
            static function () use (
                $path,
                $directory,
                $canonicalSddl,
                $expectedIdentity,
                $ffi,
            ): string {
                return self::withGatewayPathHandles(
                    $path,
                    $directory,
                    self::READ_CONTROL
                        | self::WRITE_DAC
                        | self::WRITE_OWNER
                        | self::FILE_READ_ATTRIBUTES,
                    $expectedIdentity,
                    $ffi,
                    static function (\FFI\CData $handle) use (
                        $canonicalSddl,
                        $ffi,
                    ): string {
                        $descriptor = self::descriptorFromSddl(
                            $canonicalSddl,
                            $ffi['advapi'],
                            $ffi['kernel'],
                        );
                        try {
                            $owner = $ffi['advapi']->new('void *');
                            $ownerDefaulted = $ffi['advapi']->new('BOOL');
                            $dacl = $ffi['advapi']->new('void *');
                            $daclPresent = $ffi['advapi']->new('BOOL');
                            $daclDefaulted = $ffi['advapi']->new('BOOL');
                            $control = $ffi['advapi']->new('unsigned short');
                            $revision = $ffi['advapi']->new('DWORD');
                            if ((int)$ffi['advapi']->GetSecurityDescriptorOwner(
                                    $descriptor,
                                    \FFI::addr($owner),
                                    \FFI::addr($ownerDefaulted),
                                ) === 0
                                || \FFI::isNull($owner)
                                || (int)$ffi['advapi']->GetSecurityDescriptorDacl(
                                    $descriptor,
                                    \FFI::addr($daclPresent),
                                    \FFI::addr($dacl),
                                    \FFI::addr($daclDefaulted),
                                ) === 0
                                || (int)$daclPresent === 0
                                || \FFI::isNull($dacl)
                                || (int)$ffi['advapi']->GetSecurityDescriptorControl(
                                    $descriptor,
                                    \FFI::addr($control),
                                    \FFI::addr($revision),
                                ) === 0
                                || (((int)$control & self::SE_DACL_PROTECTED) === 0)
                            ) {
                                throw new \RuntimeException(
                                    'Windows gateway restore descriptor is incomplete or unprotected.',
                                );
                            }
                            $status = (int)$ffi['advapi']->SetSecurityInfo(
                                $handle,
                                self::SE_FILE_OBJECT,
                                self::OWNER_SECURITY_INFORMATION
                                    | self::DACL_SECURITY_INFORMATION
                                    | self::PROTECTED_DACL_SECURITY_INFORMATION,
                                $owner,
                                null,
                                $dacl,
                                null,
                            );
                            if ($status !== self::ERROR_SUCCESS) {
                                throw new \RuntimeException(
                                    'Windows gateway path authority restore failed with status '
                                        . $status . '.',
                                );
                            }
                            $actual = self::handleSddl(
                                $handle,
                                $ffi['advapi'],
                                $ffi['kernel'],
                            );
                            if (!\hash_equals($canonicalSddl, $actual)) {
                                throw new \RuntimeException(
                                    'Windows gateway path authority differs after restore.',
                                );
                            }
                            return $actual;
                        } finally {
                            $ffi['kernel']->LocalFree($descriptor);
                        }
                    },
                );
            },
        );
    }

    public static function canonicalizeSddl(string $sddl): string
    {
        if (\PHP_OS_FAMILY !== 'Windows'
            || $sddl === ''
            || \strlen($sddl) > 8192
            || \str_contains($sddl, "\0")
        ) {
            throw new \RuntimeException(
                'Windows gateway SDDL cannot be canonicalized safely.',
            );
        }
        $ffi = self::bindings();
        return self::canonicalSddl(
            $sddl,
            $ffi['advapi'],
            $ffi['kernel'],
        );
    }

    /** @return array<string,list<string>> */
    private static function bootstrapProfiles(): array
    {
        $controller = self::CONTROLLER_SERVICE_SID;
        $dataPlane = self::DATA_PLANE_SERVICE_SID;
        $rootOnly = [
            'O:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)',
            'O:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)',
        ];
        $controllerRead = 'O:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)'
            . "(A;OICI;0x1200a9;;;$controller)";
        $controllerMutable = 'O:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)'
            . "(A;OICI;0x1301bf;;;$controller)";
        return [
            'runtime' => [$controllerMutable],
            'runtime/run' => [$controllerMutable],
            'runtime/logs' => [$controllerMutable],
            'state' => [$controllerMutable],
            'trust' => [$controllerRead],
            'slots' => [
                'O:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)'
                    . "(A;;0x1200a9;;;$controller)",
            ],
            // The immutable Guardian parent is a permanent SYSTEM/Admin-only
            // boundary. It deliberately keeps the same exact DACL used at
            // first creation; only guardian/v1 grants Controller read access.
            'guardian' => [self::bootstrapSddl()],
            'guardian/v1' => [$controllerRead],
            'rebootstrap' => $rootOnly,
            'rebootstrap/candidates' => $rootOnly,
            'rebootstrap/backups' => $rootOnly,
            'rebootstrap/capacity' => $rootOnly,
            'rebootstrap/receipts' => $rootOnly,
            'snapshots' => [$controllerMutable],
            'snapshots-v2' => [
                'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)'
                    . "(A;;GX;;;$dataPlane)",
            ],
            'snapshot-candidates-v2' => [
                'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)'
                    . "(A;;GA;;;$controller)",
            ],
            'bin' => [$controllerRead],
        ];
    }

    private static function bootstrapSddl(): string
    {
        return 'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)';
    }

    /** @return array{kernel:\FFI,shell:\FFI,ole:\FFI,advapi:\FFI} */
    private static function bindings(): array
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }
        if (!\extension_loaded('FFI')
            || !\class_exists(\FFI::class)
            || !\function_exists('iconv')
            || PHP_INT_SIZE !== 8
        ) {
            throw new \RuntimeException(
                'Windows gateway host-root authority requires 64-bit PHP FFI and iconv.',
            );
        }
        try {
            $kernel = \FFI::cdef(<<<'CDEF'
typedef unsigned short WCHAR;
typedef unsigned long DWORD;
typedef int BOOL;
typedef void *HANDLE;
typedef long long intptr_t;
typedef struct {
    DWORD nLength;
    void *lpSecurityDescriptor;
    BOOL bInheritHandle;
} SECURITY_ATTRIBUTES;
typedef struct {
    DWORD FileAttributes;
    DWORD ReparseTag;
} FILE_ATTRIBUTE_TAG_INFO;
typedef struct {
    unsigned long long VolumeSerialNumber;
    unsigned char FileId[16];
} FILE_ID_INFO;
typedef struct { DWORD dwLowDateTime; DWORD dwHighDateTime; } FILETIME;
typedef struct {
    DWORD dwFileAttributes;
    FILETIME ftCreationTime;
    FILETIME ftLastAccessTime;
    FILETIME ftLastWriteTime;
    DWORD dwVolumeSerialNumber;
    DWORD nFileSizeHigh;
    DWORD nFileSizeLow;
    DWORD nNumberOfLinks;
    DWORD nFileIndexHigh;
    DWORD nFileIndexLow;
} BY_HANDLE_FILE_INFORMATION;
DWORD GetFullPathNameW(const WCHAR *, DWORD, WCHAR *, WCHAR **);
unsigned int GetDriveTypeW(const WCHAR *);
HANDLE CreateFileW(const WCHAR *, DWORD, DWORD, void *, DWORD, DWORD, HANDLE);
BOOL CreateDirectoryW(const WCHAR *, SECURITY_ATTRIBUTES *);
BOOL GetFileInformationByHandleEx(HANDLE, int, void *, DWORD);
BOOL GetFileInformationByHandle(HANDLE, BY_HANDLE_FILE_INFORMATION *);
DWORD GetFinalPathNameByHandleW(HANDLE, WCHAR *, DWORD, DWORD);
DWORD GetLastError(void);
void SetLastError(DWORD);
HANDLE GetCurrentProcess(void);
BOOL CloseHandle(HANDLE);
void *LocalFree(void *);
CDEF, 'kernel32.dll');
            $shell = \FFI::cdef(<<<'CDEF'
typedef unsigned short WCHAR;
typedef unsigned long DWORD;
typedef void *HANDLE;
typedef struct {
    unsigned long Data1;
    unsigned short Data2;
    unsigned short Data3;
    unsigned char Data4[8];
} GUID;
long SHGetKnownFolderPath(const GUID *, DWORD, HANDLE, WCHAR **);
CDEF, 'shell32.dll');
            $ole = \FFI::cdef('void CoTaskMemFree(void *);', 'ole32.dll');
            $advapi = \FFI::cdef(<<<'CDEF'
typedef unsigned short WCHAR;
typedef unsigned long DWORD;
typedef long LONG;
typedef int BOOL;
typedef void *HANDLE;
typedef struct { DWORD LowPart; LONG HighPart; } LUID;
typedef struct { LUID Luid; DWORD Attributes; } LUID_AND_ATTRIBUTES;
typedef struct {
    DWORD PrivilegeCount;
    LUID_AND_ATTRIBUTES Privileges[1];
} TOKEN_PRIVILEGES;
typedef struct {
    DWORD AceCount;
    DWORD AclBytesInUse;
    DWORD AclBytesFree;
} ACL_SIZE_INFORMATION;
BOOL ConvertStringSecurityDescriptorToSecurityDescriptorW(
    const WCHAR *, DWORD, void **, DWORD *
);
BOOL ConvertSecurityDescriptorToStringSecurityDescriptorW(
    void *, DWORD, DWORD, WCHAR **, DWORD *
);
DWORD GetSecurityInfo(
    HANDLE, int, DWORD, void **, void **, void **, void **, void **
);
BOOL GetAclInformation(void *, void *, DWORD, int);
BOOL GetAce(void *, DWORD, void **);
BOOL ConvertSidToStringSidW(void *, WCHAR **);
BOOL IsValidSid(void *);
BOOL OpenProcessToken(HANDLE, DWORD, HANDLE *);
BOOL LookupPrivilegeValueW(const WCHAR *, const WCHAR *, LUID *);
BOOL AdjustTokenPrivileges(
    HANDLE, BOOL, TOKEN_PRIVILEGES *, DWORD, TOKEN_PRIVILEGES *, DWORD *
);
BOOL GetSecurityDescriptorOwner(void *, void **, BOOL *);
BOOL GetSecurityDescriptorDacl(void *, BOOL *, void **, BOOL *);
BOOL GetSecurityDescriptorControl(void *, unsigned short *, DWORD *);
DWORD SetSecurityInfo(
    HANDLE, int, DWORD, void *, void *, void *, void *
);
CDEF, 'advapi32.dll');
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Windows gateway host-root FFI bindings are unavailable.',
                0,
                $exception,
            );
        }
        return self::$ffi = [
            'kernel' => $kernel,
            'shell' => $shell,
            'ole' => $ole,
            'advapi' => $advapi,
        ];
    }

    private static function normalizeLocalFixedPath(
        string $path,
        \FFI $kernel,
    ): string {
        if ($path === ''
            || \strlen($path) >= self::PATH_CAPACITY
            || \str_contains($path, "\0")
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) !== 1
            || \preg_match('/(?:\A|[\\\\\/])\.\.?\z|(?:\A|[\\\\\/])\.\.?(?=[\\\\\/])/D', $path) === 1
            || \str_contains(\substr($path, 2), ':')
        ) {
            throw new \RuntimeException(
                'FOLDERID_ProgramData returned an unsafe path.',
            );
        }
        $input = self::wideBuffer($path, $kernel);
        $output = $kernel->new('WCHAR[' . self::PATH_CAPACITY . ']');
        $amount = (int)$kernel->GetFullPathNameW(
            $input,
            self::PATH_CAPACITY,
            $output,
            null,
        );
        if ($amount < 3 || $amount >= self::PATH_CAPACITY) {
            throw new \RuntimeException(
                'FOLDERID_ProgramData cannot be normalized.',
            );
        }
        $normalized = \rtrim(
            \str_replace('/', '\\', self::wideBufferToUtf8($output, $amount)),
            '\\',
        );
        if (\preg_match('/\A[A-Za-z]:\\\\/D', $normalized) !== 1
            || \str_contains(\substr($normalized, 2), ':')
        ) {
            throw new \RuntimeException(
                'FOLDERID_ProgramData escaped its local drive.',
            );
        }
        $drive = self::wideBuffer(\substr($normalized, 0, 3), $kernel);
        if ((int)$kernel->GetDriveTypeW($drive) !== 3) {
            throw new \RuntimeException(
                'WLS Gateway requires ProgramData on a fixed local drive.',
            );
        }
        return \strtoupper($normalized[0]) . \substr($normalized, 1);
    }

    /** @param array{kernel:\FFI,shell:\FFI,ole:\FFI,advapi:\FFI} $ffi */
    private static function createOrOpenExactDirectory(
        string $path,
        string $sddl,
        array $ffi,
    ): \FFI\CData {
        return self::createOrOpenAllowedDirectory(
            $path,
            $sddl,
            [$sddl],
            $ffi,
        );
    }

    /** @param array{kernel:\FFI,shell:\FFI,ole:\FFI,advapi:\FFI} $ffi */
    private static function createOrOpenAllowedDirectory(
        string $path,
        string $creationSddl,
        array $allowedSddls,
        array $ffi,
    ): \FFI\CData {
        $descriptor = $ffi['advapi']->new('void *');
        $wideSddl = self::wideBuffer($creationSddl, $ffi['kernel']);
        if ((int)$ffi['advapi']
                ->ConvertStringSecurityDescriptorToSecurityDescriptorW(
                    $wideSddl,
                    1,
                    \FFI::addr($descriptor),
                    null,
                ) === 0
            || \FFI::isNull($descriptor)
        ) {
            throw new \RuntimeException(
                'Windows gateway trust-root SDDL is invalid.',
            );
        }
        try {
            $attributes = $ffi['kernel']->new('SECURITY_ATTRIBUTES');
            $attributes->nLength = \FFI::sizeof($attributes);
            $attributes->lpSecurityDescriptor = $descriptor;
            $attributes->bInheritHandle = 0;
            $widePath = self::wideBuffer($path, $ffi['kernel']);
            $created = (int)$ffi['kernel']->CreateDirectoryW(
                $widePath,
                \FFI::addr($attributes),
            );
            if ($created === 0 && (int)$ffi['kernel']->GetLastError() !== 183) {
                throw new \RuntimeException(
                    'Windows gateway trust-root directory cannot be created.',
                );
            }
        } finally {
            $ffi['kernel']->LocalFree($descriptor);
        }
        $handle = self::openDirectory($path, $ffi['kernel']);
        try {
            $actual = self::handleSddl(
                $handle,
                $ffi['advapi'],
                $ffi['kernel'],
            );
            $matched = false;
            $effectiveAllowedSddls = $created !== 0
                ? [$creationSddl]
                : $allowedSddls;
            foreach (\array_values(\array_unique($effectiveAllowedSddls)) as $sddl) {
                if (!\is_string($sddl) || $sddl === '') {
                    throw new \RuntimeException(
                        'Windows gateway directory profile is invalid.',
                    );
                }
                if (\hash_equals(
                    self::canonicalSddl(
                        $sddl,
                        $ffi['advapi'],
                        $ffi['kernel'],
                    ),
                    $actual,
                )) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new \RuntimeException(
                    'Existing Windows gateway trust-root DACL differs from its exact protected profile.',
                );
            }
            return $handle;
        } catch (\Throwable $exception) {
            if ((int)$ffi['kernel']->CloseHandle($handle) === 0) {
                throw new \RuntimeException(
                    'Windows gateway trust-root validation handle did not close.',
                    0,
                    $exception,
                );
            }
            throw $exception;
        }
    }

    private static function openDirectory(
        string $path,
        \FFI $kernel,
    ): \FFI\CData {
        return self::openPath(
            $path,
            true,
            self::READ_CONTROL
                | self::FILE_READ_ATTRIBUTES
                | self::FILE_TRAVERSE,
            $kernel,
        );
    }

    /**
     * @param array{kernel:\FFI,shell:\FFI,ole:\FFI,advapi:\FFI} $ffi
     * @param array{device:string,inode:string} $expectedIdentity
     */
    private static function withGatewayPathHandles(
        string $path,
        bool $directory,
        int $targetAccess,
        array $expectedIdentity,
        array $ffi,
        \Closure $operation,
    ): mixed {
        $expectedDevice = $expectedIdentity['device'] ?? null;
        $expectedInode = $expectedIdentity['inode'] ?? null;
        if (!\is_string($expectedDevice)
            || !\is_string($expectedInode)
            || \preg_match('/\A[a-f0-9]{8}\z/D', $expectedDevice) !== 1
            || \preg_match('/\A[a-f0-9]{16}\z/D', $expectedInode) !== 1
        ) {
            throw new \RuntimeException(
                'Windows gateway expected path identity is invalid.',
            );
        }
        $home = self::normalizeLocalFixedPath(
            self::resolveHome(),
            $ffi['kernel'],
        );
        $programData = \dirname(\dirname($home));
        $weline = \dirname($home);
        $target = self::normalizeLocalFixedPath($path, $ffi['kernel']);
        if (\strcasecmp($target, $home) !== 0
            && !\str_starts_with(
                \strtolower($target),
                \strtolower($home . '\\'),
            )
        ) {
            throw new \RuntimeException(
                'Windows gateway path escaped the fixed host authority root.',
            );
        }
        $chain = [
            [$programData, true, 'program-data'],
            [$weline, true, 'weline'],
            [$home, true, 'home'],
        ];
        if (\strcasecmp($target, $home) !== 0) {
            $relative = \substr($target, \strlen($home) + 1);
            $components = \explode('\\', $relative);
            if ($components === []
                || \in_array('', $components, true)
                || \in_array('.', $components, true)
                || \in_array('..', $components, true)
            ) {
                throw new \RuntimeException(
                    'Windows gateway path has an invalid relative component.',
                );
            }
            $current = $home;
            $last = \count($components) - 1;
            foreach ($components as $index => $component) {
                $current .= '\\' . $component;
                $chain[] = [
                    $current,
                    $index !== $last || $directory,
                    'target',
                ];
            }
        } elseif (!$directory) {
            throw new \RuntimeException(
                'The fixed Windows gateway host root must be a directory.',
            );
        }

        $handles = [];
        $result = null;
        $failure = null;
        try {
            $volume = null;
            $last = \count($chain) - 1;
            foreach ($chain as $index => [
                $component,
                $componentDirectory,
                $profile,
            ]) {
                $access = $index === $last
                    ? $targetAccess
                    : self::READ_CONTROL
                        | self::FILE_READ_ATTRIBUTES
                        | self::FILE_TRAVERSE;
                if ($componentDirectory) {
                    $access |= self::FILE_TRAVERSE;
                }
                $handle = self::openPath(
                    $component,
                    $componentDirectory,
                    $access,
                    $ffi['kernel'],
                );
                $handles[] = $handle;
                if ($profile === 'program-data') {
                    self::assertProgramDataAuthority(
                        $handle,
                        $ffi['advapi'],
                        $ffi['kernel'],
                    );
                } elseif ($profile === 'weline') {
                    self::assertHandleMatchesSddl(
                        $handle,
                        'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)',
                        $ffi,
                    );
                } elseif ($profile === 'home') {
                    self::assertHandleMatchesSddl(
                        $handle,
                        'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;0x1200a9;;;'
                            . self::CONTROLLER_SERVICE_SID . ')',
                        $ffi,
                    );
                }
                $componentVolume = self::directoryVolume(
                    $handle,
                    $ffi['kernel'],
                );
                if ($volume === null) {
                    $volume = $componentVolume;
                } elseif (!\hash_equals($volume, $componentVolume)) {
                    throw new \RuntimeException(
                        'Windows gateway path crossed its fixed local volume.',
                    );
                }
            }
            $targetHandle = $handles[$last];
            $heldIdentity = self::handleLegacyIdentity(
                $targetHandle,
                $directory,
                $ffi['kernel'],
            );
            if (!\hash_equals($expectedDevice, $heldIdentity['device'])
                || !\hash_equals($expectedInode, $heldIdentity['inode'])
            ) {
                throw new \RuntimeException(
                    'Windows gateway target identity changed before authority access.',
                );
            }
            $result = $operation($targetHandle);
            $afterIdentity = self::handleLegacyIdentity(
                $targetHandle,
                $directory,
                $ffi['kernel'],
            );
            if (!\hash_equals($heldIdentity['device'], $afterIdentity['device'])
                || !\hash_equals($heldIdentity['inode'], $afterIdentity['inode'])
            ) {
                throw new \RuntimeException(
                    'Windows gateway target handle identity changed during authority access.',
                );
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }

        self::closeHandlesExactly($handles, $ffi['kernel'], $failure);
        $current = GatewayBoundedTreeWalker::identity($path);
        if ((bool)($current['directory'] ?? !$directory) !== $directory
            || !\hash_equals($expectedDevice, (string)($current['device'] ?? ''))
            || !\hash_equals($expectedInode, (string)($current['inode'] ?? ''))
        ) {
            throw new \RuntimeException(
                'Windows gateway target identity changed after authority access.',
            );
        }
        return $result;
    }

    private static function openPath(
        string $path,
        bool $directory,
        int $desiredAccess,
        \FFI $kernel,
    ): \FFI\CData {
        $wide = self::wideBuffer($path, $kernel);
        $handle = $kernel->CreateFileW(
            $wide,
            $desiredAccess,
            self::FILE_SHARE_READ | self::FILE_SHARE_WRITE,
            null,
            self::OPEN_EXISTING,
            self::FILE_FLAG_OPEN_REPARSE_POINT
                | ($directory ? self::FILE_FLAG_BACKUP_SEMANTICS : 0),
            null,
        );
        if (self::invalidHandle($handle, $kernel)) {
            throw new \RuntimeException(
                'Windows gateway trust-root directory cannot be opened safely.',
            );
        }
        try {
            $attributes = $kernel->new('FILE_ATTRIBUTE_TAG_INFO');
            if ((int)$kernel->GetFileInformationByHandleEx(
                    $handle,
                    9,
                    \FFI::addr($attributes),
                    \FFI::sizeof($attributes),
                ) === 0
                || ((((int)$attributes->FileAttributes
                    & self::FILE_ATTRIBUTE_DIRECTORY) !== 0) !== $directory)
                || (((int)$attributes->FileAttributes
                    & self::FILE_ATTRIBUTE_REPARSE_POINT) !== 0)
            ) {
                throw new \RuntimeException(
                    'Windows gateway authority path type is not direct.',
                );
            }
            $identity = $kernel->new('BY_HANDLE_FILE_INFORMATION');
            if ((int)$kernel->GetFileInformationByHandle(
                    $handle,
                    \FFI::addr($identity),
                ) === 0
                || (!$directory && (int)$identity->nNumberOfLinks !== 1)
            ) {
                throw new \RuntimeException(
                    'Windows gateway authority file is linked or has no identity.',
                );
            }
            $final = $kernel->new('WCHAR[' . self::PATH_CAPACITY . ']');
            $amount = (int)$kernel->GetFinalPathNameByHandleW(
                $handle,
                $final,
                self::PATH_CAPACITY,
                0,
            );
            if ($amount < 1 || $amount >= self::PATH_CAPACITY) {
                throw new \RuntimeException(
                    'Windows gateway trust-root final path is unavailable.',
                );
            }
            $actual = self::wideBufferToUtf8($final, $amount);
            if (\str_starts_with($actual, '\\\\?\\')) {
                $actual = \substr($actual, 4);
            }
            $actual = \rtrim(\str_replace('/', '\\', $actual), '\\');
            $expected = \rtrim(\str_replace('/', '\\', $path), '\\');
            if (\strcasecmp($actual, $expected) !== 0) {
                throw new \RuntimeException(
                    'Windows gateway trust-root final path changed.',
                );
            }
            return $handle;
        } catch (\Throwable $exception) {
            if ((int)$kernel->CloseHandle($handle) === 0) {
                throw new \RuntimeException(
                    'Windows gateway authority validation handle did not close.',
                    0,
                    $exception,
                );
            }
            throw $exception;
        }
    }

    /** @param list<\FFI\CData> $handles */
    private static function closeHandlesExactly(
        array $handles,
        \FFI $kernel,
        ?\Throwable $failure = null,
    ): void {
        $closeFailed = false;
        foreach (\array_reverse($handles) as $handle) {
            if ((int)$kernel->CloseHandle($handle) === 0) {
                $closeFailed = true;
            }
        }
        if ($closeFailed) {
            throw new \RuntimeException(
                'Windows gateway authority handle did not close cleanly.',
                0,
                $failure,
            );
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function directoryVolume(
        \FFI\CData $handle,
        \FFI $kernel,
    ): string {
        $identity = $kernel->new('FILE_ID_INFO');
        if ((int)$kernel->GetFileInformationByHandleEx(
                $handle,
                18,
                \FFI::addr($identity),
                \FFI::sizeof($identity),
            ) === 0
        ) {
            throw new \RuntimeException(
                'Windows gateway trust-root file identity is unavailable.',
            );
        }
        return \bin2hex(\FFI::string(
            \FFI::addr($identity->VolumeSerialNumber),
            \FFI::sizeof($identity->VolumeSerialNumber),
        ));
    }

    /** @return array{device:string,inode:string} */
    private static function handleLegacyIdentity(
        \FFI\CData $handle,
        bool $directory,
        \FFI $kernel,
    ): array {
        $identity = $kernel->new('BY_HANDLE_FILE_INFORMATION');
        if ((int)$kernel->GetFileInformationByHandle(
                $handle,
                \FFI::addr($identity),
            ) === 0
            || ((((int)$identity->dwFileAttributes
                & self::FILE_ATTRIBUTE_DIRECTORY) !== 0) !== $directory)
            || (((int)$identity->dwFileAttributes
                & self::FILE_ATTRIBUTE_REPARSE_POINT) !== 0)
            || (!$directory && (int)$identity->nNumberOfLinks !== 1)
        ) {
            throw new \RuntimeException(
                'Windows gateway target handle has no stable file identity.',
            );
        }
        $high = (int)$identity->nFileIndexHigh;
        $low = (int)$identity->nFileIndexLow;
        if ($high === 0 && $low === 0) {
            throw new \RuntimeException(
                'Windows gateway target handle file ID is empty.',
            );
        }
        return [
            'device' => \sprintf(
                '%08x',
                (int)$identity->dwVolumeSerialNumber,
            ),
            'inode' => \sprintf('%08x%08x', $high, $low),
        ];
    }

    /** @param array{kernel:\FFI,shell:\FFI,ole:\FFI,advapi:\FFI} $ffi */
    private static function assertHandleMatchesSddl(
        \FFI\CData $handle,
        string $expectedSddl,
        array $ffi,
    ): void {
        $actual = self::handleSddl(
            $handle,
            $ffi['advapi'],
            $ffi['kernel'],
        );
        $expected = self::canonicalSddl(
            $expectedSddl,
            $ffi['advapi'],
            $ffi['kernel'],
        );
        if (!\hash_equals($expected, $actual)) {
            throw new \RuntimeException(
                'Windows gateway authority chain differs from its fixed profile.',
            );
        }
    }

    private static function assertProgramDataAuthority(
        \FFI\CData $handle,
        \FFI $advapi,
        \FFI $kernel,
    ): void {
        $owner = $advapi->new('void *');
        $dacl = $advapi->new('void *');
        $descriptor = $advapi->new('void *');
        $status = (int)$advapi->GetSecurityInfo(
            $handle,
            1,
            self::OWNER_SECURITY_INFORMATION | self::DACL_SECURITY_INFORMATION,
            \FFI::addr($owner),
            null,
            \FFI::addr($dacl),
            null,
            \FFI::addr($descriptor),
        );
        if ($status !== 0
            || \FFI::isNull($owner)
            || \FFI::isNull($dacl)
            || \FFI::isNull($descriptor)
        ) {
            throw new \RuntimeException(
                'ProgramData authority descriptor is unavailable.',
            );
        }
        try {
            $trusted = [
                'S-1-5-18' => true,
                'S-1-5-32-544' => true,
                self::TRUSTED_INSTALLER_SID => true,
            ];
            $ownerSid = self::sidString($owner, $advapi, $kernel);
            if (!isset($trusted[$ownerSid])) {
                throw new \RuntimeException(
                    'ProgramData owner is outside the Windows platform TCB.',
                );
            }
            $information = $advapi->new('ACL_SIZE_INFORMATION');
            if ((int)$advapi->GetAclInformation(
                    $dacl,
                    \FFI::addr($information),
                    \FFI::sizeof($information),
                    2,
                ) === 0
                || (int)$information->AceCount > 4096
            ) {
                throw new \RuntimeException(
                    'ProgramData DACL inventory is invalid.',
                );
            }
            // Default ProgramData intentionally grants Users specific
            // WD/AD/WEA/WA rights. Atomic CreateDirectoryW with a protected
            // descriptor plus exact validation makes a pre-create race fail
            // closed. What must never be granted outside the platform TCB is
            // authority to delete/replace that exact child or rewrite its
            // descriptor after the handles close.
            $forbidden = self::FILE_DELETE_CHILD
                | self::DELETE
                | self::WRITE_DAC
                | self::WRITE_OWNER
                | self::GENERIC_ALL
                | self::GENERIC_WRITE
                | self::MAXIMUM_ALLOWED;
            for ($index = 0; $index < (int)$information->AceCount; ++$index) {
                $ace = $advapi->new('void *');
                if ((int)$advapi->GetAce(
                        $dacl,
                        $index,
                        \FFI::addr($ace),
                    ) === 0
                    || \FFI::isNull($ace)
                ) {
                    throw new \RuntimeException(
                        'ProgramData DACL ACE cannot be read.',
                    );
                }
                $bytes = $advapi->cast('unsigned char *', $ace);
                $type = (int)$bytes[0];
                $flags = (int)$bytes[1];
                if (($flags & self::INHERIT_ONLY_ACE) !== 0) {
                    continue;
                }
                if (\in_array($type, [1, 2, 3, 6, 7, 8, 10, 12, 13, 14, 15, 16, 17, 18, 19], true)) {
                    continue;
                }
                if (!\in_array($type, [self::ACCESS_ALLOWED_ACE_TYPE, 5, 9, 11], true)) {
                    throw new \RuntimeException(
                        'ProgramData DACL contains an unsupported ACE type.',
                    );
                }
                $aceSize = (int)$bytes[2] | ((int)$bytes[3] << 8);
                $sidOffset = 8;
                if ($type === 5 || $type === 11) {
                    if ($aceSize < 20) {
                        throw new \RuntimeException(
                            'ProgramData DACL contains a truncated object ACE.',
                        );
                    }
                    $objectFlags = (int)$bytes[8]
                        | ((int)$bytes[9] << 8)
                        | ((int)$bytes[10] << 16)
                        | ((int)$bytes[11] << 24);
                    $sidOffset = 12
                        + (($objectFlags & 0x1) !== 0 ? 16 : 0)
                        + (($objectFlags & 0x2) !== 0 ? 16 : 0);
                }
                if ($sidOffset + 8 > $aceSize) {
                    throw new \RuntimeException(
                        'ProgramData DACL ACE identity is truncated.',
                    );
                }
                $mask = (int)$bytes[4]
                    | ((int)$bytes[5] << 8)
                    | ((int)$bytes[6] << 16)
                    | ((int)$bytes[7] << 24);
                $sid = self::sidString(
                    \FFI::addr($bytes[$sidOffset]),
                    $advapi,
                    $kernel,
                );
                if (!isset($trusted[$sid]) && (($mask & $forbidden) !== 0)) {
                    throw new \RuntimeException(
                        'ProgramData grants replacement authority outside the Windows platform TCB.',
                    );
                }
            }
        } finally {
            $kernel->LocalFree($descriptor);
        }
    }

    private static function canonicalSddl(
        string $sddl,
        \FFI $advapi,
        \FFI $kernel,
    ): string {
        $descriptor = self::descriptorFromSddl($sddl, $advapi, $kernel);
        try {
            return self::descriptorSddl($descriptor, $advapi, $kernel);
        } finally {
            $kernel->LocalFree($descriptor);
        }
    }

    private static function descriptorFromSddl(
        string $sddl,
        \FFI $advapi,
        \FFI $kernel,
    ): \FFI\CData {
        $descriptor = $advapi->new('void *');
        if ((int)$advapi->ConvertStringSecurityDescriptorToSecurityDescriptorW(
                self::wideBuffer($sddl, $kernel),
                1,
                \FFI::addr($descriptor),
                null,
            ) === 0
            || \FFI::isNull($descriptor)
        ) {
            throw new \RuntimeException('Windows gateway SDDL is invalid.');
        }
        return $descriptor;
    }

    private static function handleSddl(
        \FFI\CData $handle,
        \FFI $advapi,
        \FFI $kernel,
    ): string {
        $descriptor = $advapi->new('void *');
        $status = (int)$advapi->GetSecurityInfo(
            $handle,
            1,
            self::OWNER_SECURITY_INFORMATION | self::DACL_SECURITY_INFORMATION,
            null,
            null,
            null,
            null,
            \FFI::addr($descriptor),
        );
        if ($status !== 0 || \FFI::isNull($descriptor)) {
            throw new \RuntimeException(
                'Windows gateway directory security descriptor is unavailable.',
            );
        }
        try {
            return self::descriptorSddl($descriptor, $advapi, $kernel);
        } finally {
            $kernel->LocalFree($descriptor);
        }
    }

    private static function descriptorSddl(
        \FFI\CData $descriptor,
        \FFI $advapi,
        \FFI $kernel,
    ): string {
        $encoded = $advapi->new('WCHAR *');
        $length = $advapi->new('DWORD');
        if ((int)$advapi->ConvertSecurityDescriptorToStringSecurityDescriptorW(
                $descriptor,
                1,
                self::OWNER_SECURITY_INFORMATION | self::DACL_SECURITY_INFORMATION,
                \FFI::addr($encoded),
                \FFI::addr($length),
            ) === 0
            || \FFI::isNull($encoded)
            || (int)$length < 1
            || (int)$length > 8192
        ) {
            throw new \RuntimeException(
                'Windows gateway SDDL serialization failed.',
            );
        }
        try {
            $characters = (int)$length;
            // The API reports the allocated WCHAR buffer size, including the
            // terminating NUL on supported Windows releases. Accept the
            // documented null-terminated form without serializing that NUL
            // into the canonical SDDL proof.
            if ($characters > 0 && (int)$encoded[$characters - 1] === 0) {
                --$characters;
            } elseif ((int)$encoded[$characters] !== 0) {
                throw new \RuntimeException(
                    'Windows gateway SDDL result is not null terminated.',
                );
            }
            return self::wideBufferToUtf8($encoded, $characters);
        } finally {
            $kernel->LocalFree($encoded);
        }
    }

    private static function sidString(
        \FFI\CData $sid,
        \FFI $advapi,
        \FFI $kernel,
    ): string {
        if ((int)$advapi->IsValidSid($sid) === 0) {
            throw new \RuntimeException('Windows gateway ACL contains an invalid SID.');
        }
        $encoded = $advapi->new('WCHAR *');
        if ((int)$advapi->ConvertSidToStringSidW(
                $sid,
                \FFI::addr($encoded),
            ) === 0
            || \FFI::isNull($encoded)
        ) {
            throw new \RuntimeException('Windows gateway SID serialization failed.');
        }
        try {
            return self::widePointerToUtf8($encoded);
        } finally {
            $kernel->LocalFree($encoded);
        }
    }

    private static function invalidHandle(
        \FFI\CData $handle,
        \FFI $kernel,
    ): bool {
        return \FFI::isNull($handle)
            || (int)$kernel->cast('intptr_t', $handle)->cdata
                === self::INVALID_HANDLE_VALUE;
    }

    private static function wideBuffer(
        string $value,
        \FFI $ffi,
    ): \FFI\CData {
        $encoded = @\iconv('UTF-8', 'UTF-16LE', $value . "\0");
        if (!\is_string($encoded) || (\strlen($encoded) % 2) !== 0) {
            throw new \RuntimeException('Windows gateway path encoding failed.');
        }
        $buffer = $ffi->new('WCHAR[' . (int)(\strlen($encoded) / 2) . ']');
        \FFI::memcpy($buffer, $encoded, \strlen($encoded));
        return $buffer;
    }

    private static function widePointerToUtf8(\FFI\CData $pointer): string
    {
        $length = 0;
        while ($length < self::PATH_CAPACITY && (int)$pointer[$length] !== 0) {
            ++$length;
        }
        if ($length === self::PATH_CAPACITY) {
            throw new \RuntimeException('Windows gateway wide string is unterminated.');
        }
        return self::wideBufferToUtf8($pointer, $length);
    }

    private static function wideBufferToUtf8(
        \FFI\CData $buffer,
        int $length,
    ): string {
        if ($length < 0 || $length > self::PATH_CAPACITY * 2) {
            throw new \RuntimeException('Windows gateway wide string is oversized.');
        }
        $bytes = '';
        for ($index = 0; $index < $length; ++$index) {
            $bytes .= \pack('v', (int)$buffer[$index]);
        }
        $decoded = @\iconv('UTF-16LE', 'UTF-8', $bytes);
        if (!\is_string($decoded) || \str_contains($decoded, "\0")) {
            throw new \RuntimeException('Windows gateway wide string is invalid.');
        }
        return $decoded;
    }
}
