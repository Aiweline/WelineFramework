#define _CRT_SECURE_NO_WARNINGS
#include <windows.h>
#include <aclapi.h>
#include <sddl.h>
#include <winternl.h>
#include <sodium.h>
#include <errno.h>
#include <limits.h>
#include <shlobj.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <wchar.h>
#include <wctype.h>

#include "../wls_launcher_recovery_ledger.h"
#include "wls_gateway_capacity.h"

#ifndef WLS_RELEASE_PUBLIC_KEY_HEX
#error "WLS_RELEASE_PUBLIC_KEY_HEX must be defined by the release build"
#endif

#ifndef OBJ_DONT_REPARSE
#define OBJ_DONT_REPARSE 0x1000L
#endif
#ifndef FILE_OPEN_REPARSE_POINT
#define FILE_OPEN_REPARSE_POINT 0x00200000
#endif

#define WLS_PATH_CHARS 32768U
#define WLS_MAX_MANIFEST (4U * 1024U * 1024U)
#define WLS_LAUNCHER_JSON_MAX_DEPTH 128U
#define WLS_LAUNCHER_JSON_MAX_NODES 262144U
#define WLS_CONTROL_TREE_RELOAD 254U
#define WLS_SERVICE_TREE_RESTART 79U
#define WLS_UPGRADE_ACTIVATION_SECONDS 300LL
#define WLS_UPGRADE_ACTIVATION_MILLISECONDS 300000ULL
#define WLS_UPGRADE_OBSERVATION_MILLISECONDS 300000ULL
#define WLS_UPGRADE_TOTAL_SECONDS 900LL
#define WLS_UPGRADE_TOTAL_MILLISECONDS 900000ULL
#define WLS_ROLLBACK_HEALTH_MILLISECONDS 15000ULL
#define WLS_SLOT_RETENTION_SECONDS 86400LL
#define WLS_SLOT_RETENTION_MILLISECONDS 86400000ULL
#define WLS_UPGRADE_MAX_ATTEMPTS 3U
#define WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS 30000ULL
#define WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES 131072U
#define WLS_REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES 2048U
#define WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES 16384U
#define WLS_NGINX_PID_RESIDUE_MAX 3U
#define WLS_NGINX_PID_RESIDUE_NAME_MAX 127U
#define WLS_NGINX_PID_RESIDUE_PID_MAX_BYTES 32U
#define WLS_NGINX_PID_RESIDUE_CONFIG_MAX_BYTES (16U * 1024U * 1024U)
#define WLS_PID_RESIDUE_EVIDENCE_CLEAN 0
#define WLS_PID_RESIDUE_EVIDENCE_PENDING 1
#define WLS_PID_RESIDUE_EVIDENCE_UNSAFE 2
#define WLS_RETIREMENT_RECEIPT_PRESENT 0
#define WLS_RETIREMENT_RECEIPT_ABSENT 1
#define WLS_RETIREMENT_RECEIPT_INVALID 2
#define WLS_RESTART_JOB_DRAIN_MILLISECONDS 5000U
#define WLS_GATEWAY_SERVICE_SID_TEXT \
    L"S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626"
#define WLS_DATA_PLANE_SERVICE_SID_TEXT \
    L"S-1-5-80-3611316956-1833621424-61377994-3153356469-2496947245"

static const wchar_t *wls_service_name = L"weline-wls-gateway-v2";
#ifdef WLS_NATIVE_TEST_HOOKS
static int wls_service_test_mode = 0;
#endif

struct wls_upgrade {
    int present;
    int legacy_protocol;
    wchar_t from;
    wchar_t to;
    long long prepared_at;
    long long deadline;
    char runtime_generation[65];
    char boot_id[65];
    unsigned long long prepared_monotonic;
    unsigned long long activation_deadline_monotonic;
    unsigned long long total_deadline_monotonic;
    char nonce[33];
    char intent_sha256[65];
};

struct wls_upgrade_state {
    int present;
    int legacy_protocol;
    char intent_sha256[65];
    char nonce[33];
    wchar_t from;
    wchar_t to;
    char runtime_generation[65];
    char boot_id[65];
    char phase[24];
    unsigned int attempts;
    unsigned long long observation_started;
    unsigned long long observation_deadline;
    unsigned long long prepared_monotonic;
    unsigned long long total_deadline_monotonic;
};

struct wls_system_timeofday_information {
    LARGE_INTEGER boot_time;
    LARGE_INTEGER current_time;
    LARGE_INTEGER time_zone_bias;
    ULONG time_zone_id;
    ULONG reserved;
    ULONGLONG boot_time_bias;
    ULONGLONG sleep_time_bias;
};

struct wls_platform_retirement_receipt {
    char status[16];
    char retirement_id[65];
    unsigned long pid;
    unsigned long long start_id;
    char attestation_digest[65];
    char binary_digest[65];
    char runtime_generation[65];
    char host_boot_id[65];
    char config_digest[65];
    char config_path_digest[65];
    unsigned long long publication_generation;
    char platform[16];
    char service_id[65];
    char requested_launcher_generation[65];
    char completed_launcher_generation[65];
    char completed_host_boot_id[65];
    char completed_runtime_generation[65];
};

struct wls_nginx_pid_residue_entry {
    char name[WLS_NGINX_PID_RESIDUE_NAME_MAX + 1U];
    DWORD volume_serial;
    DWORD file_index_high;
    DWORD file_index_low;
};

struct wls_nginx_pid_residue_intent {
    char platform[16];
    char service_id[65];
    char requested_launcher_generation[65];
    char runtime_generation[65];
    unsigned int count;
    struct wls_nginx_pid_residue_entry entries[WLS_NGINX_PID_RESIDUE_MAX];
};

struct wls_process_attestation_receipt {
    unsigned long pid;
    unsigned long long start_id;
    char binary_digest[65];
    char runtime_generation[65];
    char config_digest[65];
    char config_path_digest[65];
    unsigned long long publication_generation;
    char fence_kind[10];
    char candidate_transaction_id[33];
    char candidate_phase[40];
    char candidate_fence_digest[65];
};

struct wls_windows_recovery_context {
    struct wls_recovery_ledger state;
    wchar_t ledger_path[WLS_PATH_CHARS];
    wchar_t status_path[WLS_PATH_CHARS];
    int healthy_committed;
};

typedef NTSTATUS (NTAPI *wls_nt_query_system_information_fn)(
    SYSTEM_INFORMATION_CLASS,
    PVOID,
    ULONG,
    PULONG
);

typedef NTSTATUS (NTAPI *wls_nt_create_file_fn)(
    PHANDLE,
    ACCESS_MASK,
    POBJECT_ATTRIBUTES,
    PIO_STATUS_BLOCK,
    PLARGE_INTEGER,
    ULONG,
    ULONG,
    ULONG,
    ULONG,
    PVOID,
    ULONG
);

static SERVICE_STATUS_HANDLE wls_status_handle = NULL;
static SERVICE_STATUS wls_service_status;
static HANDLE wls_broker_stop_event = NULL;
static SRWLOCK wls_broker_stop_event_lock = SRWLOCK_INIT;
static volatile LONG wls_service_stop_requested = 0;
static volatile LONG wls_service_reload_generation = 0;
static volatile LONG wls_service_reload_consumed = 0;
static volatile LONG wls_service_ready_reported = 0;
#ifdef WLS_GUARDIAN_EXECUTABLE
static volatile LONG wls_service_preshutdown_unsealed = 0;
#endif
static DWORD wls_service_checkpoint = 0U;
#ifdef WLS_GUARDIAN_EXECUTABLE
static const wchar_t *wls_service_home = NULL;
static const wchar_t *wls_service_run = NULL;
#endif
static char wls_service_id[65];
static char wls_launcher_generation[65];
/* Only the validated Guardian-child command has the service-tree proof that
 * permits cross-generation nginx-pid residue replay. */
static volatile LONG wls_authenticated_platform_generation = 0;

static int wls_checked_add_long_long(
    long long value,
    long long increment,
    long long *result
) {
    if (result == NULL || value < 0 || increment < 0
        || value > LLONG_MAX - increment) {
        return 1;
    }
    *result = value + increment;
    return 0;
}

static int wls_checked_add_unsigned_long_long(
    unsigned long long value,
    unsigned long long increment,
    unsigned long long *result
) {
    if (result == NULL || value > ULLONG_MAX - increment) return 1;
    *result = value + increment;
    return 0;
}

/* Persisted upgrade timestamps originate in PHP hrtime(). On Windows that
 * API uses QueryPerformanceCounter, whose epoch is not interchangeable with
 * GetTickCount64 even though both are monotonic. */
static int wls_protocol_monotonic_milliseconds(
    unsigned long long *milliseconds
) {
    LARGE_INTEGER counter;
    LARGE_INTEGER frequency;
    double nanoseconds;
    if (milliseconds == NULL
        || !QueryPerformanceCounter(&counter)
        || !QueryPerformanceFrequency(&frequency)
        || counter.QuadPart < 0
        || frequency.QuadPart <= 0) {
        return 1;
    }
    nanoseconds = (double)counter.QuadPart
        * (1000000000.0 / (double)frequency.QuadPart);
    if (nanoseconds < 0.0
        || nanoseconds >= 18446744073709551616.0) {
        return 1;
    }
    *milliseconds = ((unsigned long long)nanoseconds) / 1000000ULL;
    return 0;
}

static void wls_report_service(
    DWORD state,
    DWORD win32_exit,
    DWORD service_specific_exit
);

static void wls_report_service_pending(DWORD state, DWORD wait_hint);

static int wls_reload_pending(void)
{
    return InterlockedCompareExchange(&wls_service_reload_generation, 0, 0)
        != InterlockedCompareExchange(&wls_service_reload_consumed, 0, 0);
}

static void wls_signal_broker_stop_event(void)
{
    AcquireSRWLockShared(&wls_broker_stop_event_lock);
    if (wls_broker_stop_event != NULL) {
        SetEvent(wls_broker_stop_event);
    }
    ReleaseSRWLockShared(&wls_broker_stop_event_lock);
}

static void wls_publish_broker_stop_event(HANDLE stop_event)
{
    AcquireSRWLockExclusive(&wls_broker_stop_event_lock);
    wls_broker_stop_event = stop_event;
    if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) != 0
        || wls_reload_pending()) {
        SetEvent(stop_event);
    }
    ReleaseSRWLockExclusive(&wls_broker_stop_event_lock);
}

static void wls_unpublish_broker_stop_event(HANDLE stop_event)
{
    AcquireSRWLockExclusive(&wls_broker_stop_event_lock);
    if (wls_broker_stop_event == stop_event) {
        wls_broker_stop_event = NULL;
    }
    ReleaseSRWLockExclusive(&wls_broker_stop_event_lock);
}

static DWORD wls_classify_broker_exit(
    DWORD broker_exit,
    LONG stop_requested,
    int automatic_launch_allowed,
    int reload_authorized
) {
    if (stop_requested != 0 || automatic_launch_allowed == 0) {
        return 0U;
    }
    if (reload_authorized != 0) {
        return WLS_CONTROL_TREE_RELOAD;
    }
    /* 254 is launcher-private and cannot be asserted by the Broker itself. */
    if (broker_exit == 0U || broker_exit == WLS_CONTROL_TREE_RELOAD) {
        return 1U;
    }
    return broker_exit;
}

static int wls_join(
    wchar_t *output,
    size_t capacity,
    const wchar_t *left,
    const wchar_t *right
) {
    size_t length;
    int written;
    if (output == NULL || left == NULL || right == NULL) return 1;
    length = wcslen(left);
    written = _snwprintf_s(
        output,
        capacity,
        _TRUNCATE,
        length > 0U && (left[length - 1U] == L'\\' || left[length - 1U] == L'/')
            ? L"%ls%ls"
            : L"%ls\\%ls",
        left,
        right
    );
    return written < 0 ? 1 : 0;
}

static int wls_is_hex(const char *value, size_t length)
{
    size_t index;
    if (value == NULL || strlen(value) != length) return 0;
    for (index = 0U; index < length; index++) {
        if (!((value[index] >= '0' && value[index] <= '9')
            || (value[index] >= 'a' && value[index] <= 'f'))) {
            return 0;
        }
    }
    return 1;
}

static int wls_launcher_gateway_service_sid(PSID *sid)
{
    if (sid == NULL) return 1;
    *sid = NULL;
#ifdef WLS_NATIVE_TEST_HOOKS
    if (wls_service_test_mode) {
        wchar_t account[256];
        wchar_t domain[256];
        DWORD sid_bytes = 0U;
        DWORD domain_chars = sizeof(domain) / sizeof(domain[0]);
        SID_NAME_USE use;
        if (wls_service_name == NULL
            || _snwprintf_s(
                account,
                sizeof(account) / sizeof(account[0]),
                _TRUNCATE,
                L"NT SERVICE\\%ls",
                wls_service_name
            ) < 0) return 1;
        (void)LookupAccountNameW(
            NULL, account, NULL, &sid_bytes, domain, &domain_chars, &use
        );
        if (sid_bytes == 0U || GetLastError() != ERROR_INSUFFICIENT_BUFFER) {
            return 1;
        }
        *sid = LocalAlloc(LMEM_FIXED, sid_bytes);
        if (*sid == NULL || !LookupAccountNameW(
                NULL, account, *sid, &sid_bytes, domain, &domain_chars, &use
            ) || !IsValidSid(*sid)) {
            if (*sid != NULL) LocalFree(*sid);
            *sid = NULL;
            return 1;
        }
        return 0;
    }
#endif
    if (!ConvertStringSidToSidW(WLS_GATEWAY_SERVICE_SID_TEXT, sid)
        || *sid == NULL || !IsValidSid(*sid)) {
        if (*sid != NULL) LocalFree(*sid);
        *sid = NULL;
        return 1;
    }
    return 0;
}

static int wls_launcher_slot_acl_valid_profile(
    HANDLE object,
    int directory,
    PSID service_sid,
    int controller_acl,
    PSID data_plane_sid,
    int data_plane_acl,
    int system_owner,
    int inherit_aces,
    ACCESS_MASK data_plane_mask
) {
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PSID owner = NULL;
    PACL dacl = NULL;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    SECURITY_DESCRIPTOR_CONTROL control = 0U;
    DWORD revision = 0U;
    ACL_SIZE_INFORMATION information;
    unsigned char system_buffer[SECURITY_MAX_SID_SIZE];
    unsigned char administrators_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD administrators_length = sizeof(administrators_buffer);
    DWORD expected_flags = directory && inherit_aces
        ? OBJECT_INHERIT_ACE | CONTAINER_INHERIT_ACE : 0U;
    DWORD status;
    DWORD index;
    unsigned int system_count = 0U;
    unsigned int administrators_count = 0U;
    unsigned int service_count = 0U;
    unsigned int data_plane_count = 0U;
    int result = 1;
    ZeroMemory(&information, sizeof(information));
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || (controller_acl != 0 && controller_acl != 1)
        || (data_plane_acl != 0 && data_plane_acl != 1)
        || (system_owner != 0 && system_owner != 1)
        || (inherit_aces != 0 && inherit_aces != 1)
        || (inherit_aces && !directory)
        || (controller_acl
            && (service_sid == NULL || !IsValidSid(service_sid)))
        || (data_plane_acl
            && (!controller_acl || data_plane_sid == NULL
                || !IsValidSid(data_plane_sid)
                || EqualSid(service_sid, data_plane_sid)
                || data_plane_mask == 0U))
        || (!data_plane_acl && data_plane_mask != 0U)
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_buffer, &system_length
        )
        || !CreateWellKnownSid(
            WinBuiltinAdministratorsSid,
            NULL,
            administrators_buffer,
            &administrators_length
        )) goto cleanup;
    status = GetSecurityInfo(
        object,
        SE_FILE_OBJECT,
        OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
        &owner,
        NULL,
        &dacl,
        NULL,
        &descriptor
    );
    if (status != ERROR_SUCCESS || descriptor == NULL || owner == NULL
        || !IsValidSid(owner)
        || !EqualSid(
            owner,
            system_owner ? (PSID)system_buffer
                : (PSID)administrators_buffer
        )
        || !GetSecurityDescriptorDacl(
            descriptor, &dacl_present, &dacl, &dacl_defaulted
        )
        || !dacl_present || dacl_defaulted || dacl == NULL
        || !GetSecurityDescriptorControl(descriptor, &control, &revision)
        || (control & SE_DACL_PROTECTED) == 0U
        || !GetAclInformation(
            dacl, &information, sizeof(information), AclSizeInformation
        )
        || dacl->AclRevision != ACL_REVISION
        || information.AceCount
            != 2U + (controller_acl ? 1U : 0U)
                + (data_plane_acl ? 1U : 0U)) {
        goto cleanup;
    }
    for (index = 0U; index < information.AceCount; index++) {
        ACCESS_ALLOWED_ACE *ace = NULL;
        PSID ace_sid;
        DWORD sid_offset = (DWORD)FIELD_OFFSET(ACCESS_ALLOWED_ACE, SidStart);
        DWORD sid_length;
        if (!GetAce(dacl, index, (LPVOID *)&ace)
            || ace == NULL
            || ace->Header.AceType != ACCESS_ALLOWED_ACE_TYPE
            || ace->Header.AceFlags != expected_flags
            || ace->Header.AceSize < sid_offset + 8U) goto cleanup;
        ace_sid = (PSID)&ace->SidStart;
        if (!IsValidSid(ace_sid)
            || (sid_length = GetLengthSid(ace_sid)) == 0U
            || sid_length != (DWORD)ace->Header.AceSize - sid_offset) {
            goto cleanup;
        }
        if (EqualSid(ace_sid, system_buffer)) {
            if (++system_count != 1U || ace->Mask != FILE_ALL_ACCESS) {
                goto cleanup;
            }
        } else if (EqualSid(ace_sid, administrators_buffer)) {
            if (++administrators_count != 1U
                || ace->Mask != FILE_ALL_ACCESS) goto cleanup;
        } else if (controller_acl && EqualSid(ace_sid, service_sid)) {
            if (++service_count != 1U
                || ace->Mask
                    != (FILE_GENERIC_READ | FILE_GENERIC_EXECUTE)) {
                goto cleanup;
            }
        } else if (data_plane_acl && EqualSid(ace_sid, data_plane_sid)) {
            if (++data_plane_count != 1U
                || ace->Mask != data_plane_mask) {
                goto cleanup;
            }
        } else {
            goto cleanup;
        }
    }
    if (system_count == 1U && administrators_count == 1U
        && service_count == (controller_acl ? 1U : 0U)
        && data_plane_count == (data_plane_acl ? 1U : 0U)) result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    return result;
}

static int wls_launcher_slot_acl_valid_mode(
    HANDLE object,
    int directory,
    PSID service_sid,
    int controller_acl
) {
    return wls_launcher_slot_acl_valid_profile(
        object,
        directory,
        service_sid,
        controller_acl,
        NULL,
        0,
        0,
        directory,
        0U
    );
}

static int wls_launcher_nginx_acl_valid(
    HANDLE object,
    PSID controller_sid
) {
    PSID data_plane_sid = NULL;
    int result = 1;
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || controller_sid == NULL || !IsValidSid(controller_sid)
        || !ConvertStringSidToSidW(
            WLS_DATA_PLANE_SERVICE_SID_TEXT, &data_plane_sid
        )
        || data_plane_sid == NULL || !IsValidSid(data_plane_sid)) {
        goto cleanup;
    }
    result = wls_launcher_slot_acl_valid_profile(
        object,
        0,
        controller_sid,
        1,
        data_plane_sid,
        1,
        0,
        0,
        FILE_GENERIC_READ | FILE_GENERIC_EXECUTE
    );
cleanup:
    if (data_plane_sid != NULL) LocalFree(data_plane_sid);
    return result;
}

static int wls_launcher_data_plane_directory_acl_valid(
    HANDLE object,
    PSID controller_sid,
    int system_owner
) {
    PSID data_plane_sid = NULL;
    int result = 1;
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || controller_sid == NULL || !IsValidSid(controller_sid)
        || (system_owner != 0 && system_owner != 1)
        || !ConvertStringSidToSidW(
            WLS_DATA_PLANE_SERVICE_SID_TEXT, &data_plane_sid
        )
        || data_plane_sid == NULL || !IsValidSid(data_plane_sid)) {
        goto cleanup;
    }
    result = wls_launcher_slot_acl_valid_profile(
        object,
        1,
        controller_sid,
        1,
        data_plane_sid,
        1,
        system_owner,
        0,
        FILE_TRAVERSE
    );
cleanup:
    if (data_plane_sid != NULL) LocalFree(data_plane_sid);
    return result;
}

static int wls_launcher_slot_data_plane_directory_acl_valid(
    HANDLE object,
    PSID controller_sid
) {
    return wls_launcher_data_plane_directory_acl_valid(
        object, controller_sid, 0
    );
}

static int wls_launcher_host_data_plane_directory_acl_valid(
    HANDLE object,
    PSID controller_sid
) {
    return wls_launcher_data_plane_directory_acl_valid(
        object, controller_sid, 1
    );
}

static int wls_launcher_root_only_directory_acl_valid(HANDLE object)
{
    return wls_launcher_slot_acl_valid_profile(
        object, 1, NULL, 0, NULL, 0, 1, 0, 0U
    );
}

static int wls_launcher_slot_acl_valid(
    HANDLE object,
    int directory,
    PSID service_sid
) {
    return wls_launcher_slot_acl_valid_mode(
        object, directory, service_sid, 1
    );
}

static int wls_launcher_slot_directory_valid_mode(
    const wchar_t *path,
    PSID service_sid,
    int controller_acl
) {
    HANDLE directory = INVALID_HANDLE_VALUE;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    if (path == NULL || (controller_acl != 0 && controller_acl != 1)
        || (controller_acl && service_sid == NULL)) return 1;
    directory = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (directory != INVALID_HANDLE_VALUE
        && GetFileInformationByHandleEx(
            directory,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        && (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) == 0U
        && (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
        && wls_launcher_slot_acl_valid_mode(
            directory, 1, service_sid, controller_acl
        ) == 0) result = 0;
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    return result;
}

static int wls_launcher_data_plane_directory_valid(
    const wchar_t *path,
    PSID controller_sid,
    int system_owner
) {
    HANDLE directory = INVALID_HANDLE_VALUE;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    if (path == NULL || controller_sid == NULL
        || !IsValidSid(controller_sid)
        || (system_owner != 0 && system_owner != 1)) return 1;
    directory = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (directory != INVALID_HANDLE_VALUE
        && GetFileInformationByHandleEx(
            directory,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        && (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) == 0U
        && (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
        && (system_owner
            ? wls_launcher_host_data_plane_directory_acl_valid(
                directory, controller_sid
            )
            : wls_launcher_slot_data_plane_directory_acl_valid(
                directory, controller_sid
            )) == 0) result = 0;
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    return result;
}

/* Resolve only one leaf beneath an already-validated directory handle.  The
 * residue recovery path must not reopen an attacker-controlled textual path
 * between identity validation and deletion. */
static int wls_launcher_handle_is_reparse(HANDLE handle)
{
    FILE_ATTRIBUTE_TAG_INFO attributes;
    ZeroMemory(&attributes, sizeof(attributes));
    return handle == NULL || handle == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            handle, FileAttributeTagInfo, &attributes, sizeof(attributes)
        ) || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U;
}

static int wls_launcher_directory_identity(
    HANDLE directory,
    BY_HANDLE_FILE_INFORMATION *identity
)
{
    FILE_ATTRIBUTE_TAG_INFO attributes;
    ZeroMemory(&attributes, sizeof(attributes));
    return directory == NULL || directory == INVALID_HANDLE_VALUE
        || identity == NULL
        || wls_launcher_handle_is_reparse(directory)
        || !GetFileInformationByHandleEx(
            directory, FileAttributeTagInfo, &attributes, sizeof(attributes)
        ) || (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) == 0U
        || !GetFileInformationByHandle(directory, identity)
        || identity->nNumberOfLinks == 0U ? 1 : 0;
}

static int wls_launcher_flush_verified_directory(HANDLE directory)
{
    typedef NTSTATUS (NTAPI *wls_nt_flush_buffers_file_fn)(
        HANDLE, PIO_STATUS_BLOCK
    );
    BY_HANDLE_FILE_INFORMATION before;
    BY_HANDLE_FILE_INFORMATION after;
    IO_STATUS_BLOCK status;
    HMODULE ntdll;
    wls_nt_flush_buffers_file_fn native_flush = NULL;
    int flushed = 0;
    ZeroMemory(&before, sizeof(before));
    ZeroMemory(&after, sizeof(after));
    ZeroMemory(&status, sizeof(status));
    if (wls_launcher_directory_identity(directory, &before) != 0) return 1;
    if (FlushFileBuffers(directory)) {
        flushed = 1;
    } else if ((ntdll = GetModuleHandleW(L"ntdll.dll")) != NULL
        && (native_flush = (wls_nt_flush_buffers_file_fn)(void *)GetProcAddress(
            ntdll, "NtFlushBuffersFile"
        )) != NULL && native_flush(directory, &status) >= 0) {
        flushed = 1;
    }
    return !flushed || wls_launcher_directory_identity(directory, &after) != 0
        || before.dwVolumeSerialNumber != after.dwVolumeSerialNumber
        || before.nFileIndexHigh != after.nFileIndexHigh
        || before.nFileIndexLow != after.nFileIndexLow
        || before.nNumberOfLinks != after.nNumberOfLinks ? 1 : 0;
}

static HANDLE wls_nt_open_child(
    HANDLE parent,
    const wchar_t *name,
    size_t name_length,
    ACCESS_MASK access,
    ULONG share_access,
    ULONG disposition,
    int directory
) {
    HMODULE ntdll = GetModuleHandleW(L"ntdll.dll");
    wls_nt_create_file_fn nt_create_file;
    UNICODE_STRING unicode_name;
    OBJECT_ATTRIBUTES attributes;
    IO_STATUS_BLOCK status_block;
    HANDLE child = INVALID_HANDLE_VALUE;
    NTSTATUS status;
    if (parent == NULL || parent == INVALID_HANDLE_VALUE || name == NULL
        || name_length == 0U || name_length > 32767U) {
        SetLastError(ERROR_INVALID_NAME);
        return INVALID_HANDLE_VALUE;
    }
    if (ntdll == NULL || (nt_create_file = (wls_nt_create_file_fn)(void *)
            GetProcAddress(ntdll, "NtCreateFile")) == NULL) {
        SetLastError(ERROR_CALL_NOT_IMPLEMENTED);
        return INVALID_HANDLE_VALUE;
    }
    unicode_name.Buffer = (PWSTR)name;
    unicode_name.Length = (USHORT)(name_length * sizeof(wchar_t));
    unicode_name.MaximumLength = unicode_name.Length;
    InitializeObjectAttributes(
        &attributes,
        &unicode_name,
        OBJ_CASE_INSENSITIVE | OBJ_DONT_REPARSE,
        parent,
        NULL
    );
    status = nt_create_file(
        &child,
        access | SYNCHRONIZE,
        &attributes,
        &status_block,
        NULL,
        FILE_ATTRIBUTE_NORMAL,
        share_access,
        disposition,
        FILE_OPEN_REPARSE_POINT | FILE_SYNCHRONOUS_IO_NONALERT
            | (directory ? FILE_DIRECTORY_FILE : FILE_NON_DIRECTORY_FILE),
        NULL,
        0U
    );
    if (status < 0 || child == INVALID_HANDLE_VALUE
        || wls_launcher_handle_is_reparse(child)) {
        if (child != INVALID_HANDLE_VALUE) CloseHandle(child);
        SetLastError(RtlNtStatusToDosError(status));
        return INVALID_HANDLE_VALUE;
    }
    return child;
}

static int wls_read_file(
    const wchar_t *path,
    size_t maximum,
    unsigned char **contents,
    size_t *length
) {
    HANDLE file;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    LARGE_INTEGER size;
    unsigned char *buffer;
    DWORD amount = 0U;
    DWORD used = 0U;
    if (contents == NULL || length == NULL) return 1;
    *contents = NULL;
    *length = 0U;
    file = CreateFileW(
        path,
        GENERIC_READ,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            file,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0
        || (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0
        || !GetFileSizeEx(file, &size)
        || size.QuadPart < 0
        || (uint64_t)size.QuadPart > maximum
        || size.QuadPart > MAXDWORD) {
        if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
        return 1;
    }
    buffer = (unsigned char *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        (size_t)size.QuadPart + 1U
    );
    if (buffer == NULL) {
        CloseHandle(file);
        return 1;
    }
    while (used < (DWORD)size.QuadPart) {
        if (!ReadFile(
                file,
                buffer + used,
                (DWORD)size.QuadPart - used,
                &amount,
                NULL
            )
            || amount == 0U) {
            HeapFree(GetProcessHeap(), 0U, buffer);
            CloseHandle(file);
            return 1;
        }
        used += amount;
    }
    CloseHandle(file);
    buffer[used] = '\0';
    *contents = buffer;
    *length = used;
    return 0;
}

static int wls_write_all(HANDLE file, const unsigned char *contents, DWORD length)
{
    DWORD offset = 0U;
    while (offset < length) {
        DWORD written = 0U;
        if (!WriteFile(file, contents + offset, length - offset, &written, NULL)
            || written == 0U) {
            return 1;
        }
        offset += written;
    }
    return 0;
}

static int wls_atomic_text(const wchar_t *path, const char *contents)
{
    wchar_t temporary[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    DWORD length;
    int result = 1;
    if (contents == NULL || strlen(contents) > MAXDWORD
        || _snwprintf_s(
            temporary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls.candidate.%lu.%llu",
            path,
            GetCurrentProcessId(),
            (unsigned long long)GetTickCount64()
        ) < 0) {
        return 1;
    }
    length = (DWORD)strlen(contents);
    file = CreateFileW(
        temporary,
        GENERIC_WRITE,
        0U,
        NULL,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || wls_write_all(file, (const unsigned char *)contents, length) != 0
        || !FlushFileBuffers(file)) {
        goto cleanup;
    }
    CloseHandle(file);
    file = INVALID_HANDLE_VALUE;
    if (!MoveFileExW(
        temporary,
        path,
        MOVEFILE_REPLACE_EXISTING | MOVEFILE_WRITE_THROUGH
    )) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (result != 0) DeleteFileW(temporary);
    return result;
}

static int wls_atomic_system_text(const wchar_t *path, const char *contents)
{
    wchar_t temporary[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    PSECURITY_DESCRIPTOR security_descriptor = NULL;
    SECURITY_ATTRIBUTES security_attributes;
    DWORD length;
    int result = 1;
    ZeroMemory(&security_attributes, sizeof(security_attributes));
    if (path == NULL || contents == NULL || strlen(contents) > MAXDWORD
        || _snwprintf_s(
            temporary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls.candidate.%lu.%llu",
            path,
            GetCurrentProcessId(),
            (unsigned long long)GetTickCount64()
        ) < 0
        || !ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"O:SYG:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)",
            SDDL_REVISION_1,
            &security_descriptor,
            NULL
        )) {
        return 1;
    }
    length = (DWORD)strlen(contents);
    security_attributes.nLength = sizeof(security_attributes);
    security_attributes.lpSecurityDescriptor = security_descriptor;
    security_attributes.bInheritHandle = FALSE;
    file = CreateFileW(
        temporary,
        GENERIC_WRITE,
        0U,
        &security_attributes,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH
            | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || wls_write_all(file, (const unsigned char *)contents, length) != 0
        || !FlushFileBuffers(file)) {
        goto cleanup;
    }
    CloseHandle(file);
    file = INVALID_HANDLE_VALUE;
    if (!MoveFileExW(
        temporary,
        path,
        MOVEFILE_REPLACE_EXISTING | MOVEFILE_WRITE_THROUGH
    )) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (security_descriptor != NULL) LocalFree(security_descriptor);
    if (result != 0) DeleteFileW(temporary);
    return result;
}

/* Minimal diagnostic projection: LocalSystem/Administrators may replace it;
 * authenticated local users receive read-only access and no control right. */
static int wls_atomic_diagnostic_text(
    const wchar_t *path,
    const char *contents
) {
    wchar_t temporary[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    PSECURITY_DESCRIPTOR security_descriptor = NULL;
    SECURITY_ATTRIBUTES security_attributes;
    DWORD length;
    int result = 1;
    ZeroMemory(&security_attributes, sizeof(security_attributes));
    if (path == NULL || contents == NULL || strlen(contents) > MAXDWORD
        || _snwprintf_s(
            temporary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls.candidate.%lu.%llu",
            path,
            GetCurrentProcessId(),
            (unsigned long long)GetTickCount64()
        ) < 0
        || !ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"O:SYG:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;GR;;;BU)",
            SDDL_REVISION_1,
            &security_descriptor,
            NULL
        )) return 1;
    length = (DWORD)strlen(contents);
    security_attributes.nLength = sizeof(security_attributes);
    security_attributes.lpSecurityDescriptor = security_descriptor;
    security_attributes.bInheritHandle = FALSE;
    file = CreateFileW(
        temporary,
        GENERIC_WRITE,
        0U,
        &security_attributes,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH
            | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || wls_write_all(file, (const unsigned char *)contents, length) != 0
        || !FlushFileBuffers(file)) goto cleanup;
    CloseHandle(file);
    file = INVALID_HANDLE_VALUE;
    if (!MoveFileExW(
            temporary,
            path,
            MOVEFILE_REPLACE_EXISTING | MOVEFILE_WRITE_THROUGH
        )) goto cleanup;
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (security_descriptor != NULL) LocalFree(security_descriptor);
    if (result != 0) DeleteFileW(temporary);
    return result;
}

static int wls_delete_optional(const wchar_t *path)
{
    DWORD error;
    if (DeleteFileW(path)) return 0;
    error = GetLastError();
    return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
        ? 0
        : 1;
}

static int wls_delete_optional_durable(const wchar_t *path)
{
    wchar_t pending[WLS_PATH_CHARS];
    HANDLE target = INVALID_HANDLE_VALUE;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    BY_HANDLE_FILE_INFORMATION information;
    DWORD error;
    if (path == NULL || _snwprintf_s(
            pending,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls.delete-pending",
            path
        ) < 0) {
        return 1;
    }
    if (!DeleteFileW(pending)) {
        error = GetLastError();
        if (error != ERROR_FILE_NOT_FOUND && error != ERROR_PATH_NOT_FOUND) return 1;
    }
    target = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (target == INVALID_HANDLE_VALUE) {
        error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND ? 0 : 1;
    }
    if (!GetFileInformationByHandleEx(
            target,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || !GetFileInformationByHandle(target, &information)
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0
        || (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0
        || information.nNumberOfLinks != 1U) {
        CloseHandle(target);
        return 1;
    }
    CloseHandle(target);
    if (!MoveFileExW(
            path,
            pending,
            MOVEFILE_REPLACE_EXISTING | MOVEFILE_WRITE_THROUGH
        )) {
        return 1;
    }
    return DeleteFileW(pending) ? 0 : 1;
}

static int wls_read_slot_pointer(const wchar_t *path, wchar_t *slot)
{
    unsigned char *contents = NULL;
    size_t length = 0U;
    int result = 1;
    if (path != NULL && slot != NULL
        && wls_read_file(path, 4U, &contents, &length) == 0
        && (length == 1U || (length == 2U && contents[1] == '\n'))
        && (contents[0] == 'A' || contents[0] == 'B')) {
        *slot = (wchar_t)contents[0];
        result = 0;
    }
    if (contents != NULL) HeapFree(GetProcessHeap(), 0U, contents);
    return result;
}

/* 0=acquired, 2=busy, 1=invalid/error. */
static int wls_package_lock_acquire(
    const wchar_t *home,
    HANDLE *lock_handle,
    int wait_for_lock
)
{
    wchar_t trust_path[WLS_PATH_CHARS];
    wchar_t path[WLS_PATH_CHARS];
    HANDLE trust_handle = INVALID_HANDLE_VALUE;
    HANDLE handle;
    FILE_ATTRIBUTE_TAG_INFO trust_attributes;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    BY_HANDLE_FILE_INFORMATION information;
    OVERLAPPED overlapped;
    unsigned long long started;
    unsigned long long deadline;
    unsigned long long now;
    DWORD error;
    if (home == NULL || lock_handle == NULL
        || wls_join(trust_path, WLS_PATH_CHARS, home, L"trust") != 0
        || wls_join(
            path,
            WLS_PATH_CHARS,
            home,
            L"trust\\package-install.lock"
        ) != 0) {
        return 1;
    }
    trust_handle = CreateFileW(
        trust_path,
        FILE_READ_ATTRIBUTES,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (trust_handle == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            trust_handle,
            FileAttributeTagInfo,
            &trust_attributes,
            sizeof(trust_attributes)
        )
        || (trust_attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0
        || (trust_attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) == 0) {
        if (trust_handle != INVALID_HANDLE_VALUE) CloseHandle(trust_handle);
        return 1;
    }
    handle = CreateFileW(
        path,
        GENERIC_READ | GENERIC_WRITE,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (handle == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            handle,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || !GetFileInformationByHandle(handle, &information)
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0
        || (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0
        || information.nNumberOfLinks != 1U
        || !FlushFileBuffers(handle)) {
        if (handle != INVALID_HANDLE_VALUE) CloseHandle(handle);
        CloseHandle(trust_handle);
        return 1;
    }
    started = (unsigned long long)GetTickCount64();
    if (wls_checked_add_unsigned_long_long(
            started,
            WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS,
            &deadline
    ) != 0) {
        CloseHandle(handle);
        CloseHandle(trust_handle);
        return 1;
    }
    ZeroMemory(&overlapped, sizeof(overlapped));
    for (;;) {
        if (LockFileEx(
                handle,
                LOCKFILE_EXCLUSIVE_LOCK | LOCKFILE_FAIL_IMMEDIATELY,
                0U,
                MAXDWORD,
                MAXDWORD,
                &overlapped
            )) {
            break;
        }
        error = GetLastError();
        if (error != ERROR_LOCK_VIOLATION && error != ERROR_IO_PENDING) {
            CloseHandle(handle);
            CloseHandle(trust_handle);
            return 1;
        }
        if (!wait_for_lock) {
            CloseHandle(handle);
            CloseHandle(trust_handle);
            SetLastError(ERROR_LOCK_VIOLATION);
            return 2;
        }
        now = (unsigned long long)GetTickCount64();
        if (now >= deadline) {
            CloseHandle(handle);
            CloseHandle(trust_handle);
            SetLastError(ERROR_LOCK_VIOLATION);
            return 2;
        }
        Sleep(10U);
    }
    if (!GetFileInformationByHandleEx(
            handle,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || !GetFileInformationByHandle(handle, &information)
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0
        || (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0
        || information.nNumberOfLinks != 1U) {
        (void)UnlockFileEx(handle, 0U, MAXDWORD, MAXDWORD, &overlapped);
        CloseHandle(handle);
        CloseHandle(trust_handle);
        return 1;
    }
    if (!CloseHandle(trust_handle)) {
        (void)UnlockFileEx(handle, 0U, MAXDWORD, MAXDWORD, &overlapped);
        CloseHandle(handle);
        return 1;
    }
    *lock_handle = handle;
    return 0;
}

static int wls_package_lock_release(HANDLE lock_handle)
{
    OVERLAPPED overlapped;
    int result = 0;
    if (lock_handle == NULL || lock_handle == INVALID_HANDLE_VALUE) return 1;
    ZeroMemory(&overlapped, sizeof(overlapped));
    if (!UnlockFileEx(
            lock_handle,
            0U,
            MAXDWORD,
            MAXDWORD,
            &overlapped
        )) {
        result = 1;
    }
    if (!CloseHandle(lock_handle)) result = 1;
    return result;
}

static int wls_public_key(unsigned char key[crypto_sign_PUBLICKEYBYTES])
{
    size_t decoded = 0U;
    const char *hex = WLS_RELEASE_PUBLIC_KEY_HEX;
    return strlen(hex) == crypto_sign_PUBLICKEYBYTES * 2U
        && sodium_hex2bin(
            key,
            crypto_sign_PUBLICKEYBYTES,
            hex,
            strlen(hex),
            NULL,
            &decoded,
            NULL
        ) == 0
        && decoded == crypto_sign_PUBLICKEYBYTES
            ? 0
            : 1;
}

enum wls_launcher_json_kind {
    WLS_LAUNCHER_JSON_NULL = 0,
    WLS_LAUNCHER_JSON_BOOLEAN = 1,
    WLS_LAUNCHER_JSON_NUMBER = 2,
    WLS_LAUNCHER_JSON_STRING = 3,
    WLS_LAUNCHER_JSON_ARRAY = 4,
    WLS_LAUNCHER_JSON_OBJECT = 5
};

struct wls_launcher_json_node;

struct wls_launcher_json_member {
    const char *raw_key;
    size_t raw_key_length;
    char *key;
    size_t key_length;
    struct wls_launcher_json_node *value;
};

struct wls_launcher_json_node {
    enum wls_launcher_json_kind kind;
    const char *raw;
    size_t raw_length;
    char *decoded;
    size_t decoded_length;
    struct wls_launcher_json_node **items;
    struct wls_launcher_json_member *members;
    size_t count;
    size_t capacity;
};

struct wls_launcher_json_parser {
    const char *cursor;
    const char *end;
    size_t nodes;
};

struct wls_launcher_file_observation {
    unsigned char *contents;
    size_t length;
    BY_HANDLE_FILE_INFORMATION identity;
    char digest[65];
};

struct wls_launcher_component_observation {
    BY_HANDLE_FILE_INFORMATION identity;
    unsigned long long size;
    char digest[65];
};

static void wls_launcher_json_free(struct wls_launcher_json_node *node)
{
    size_t index;
    if (node == NULL) return;
    if (node->kind == WLS_LAUNCHER_JSON_ARRAY) {
        for (index = 0U; index < node->count; index++) {
            wls_launcher_json_free(node->items[index]);
        }
    } else if (node->kind == WLS_LAUNCHER_JSON_OBJECT) {
        for (index = 0U; index < node->count; index++) {
            if (node->members[index].key != NULL) {
                SecureZeroMemory(
                    node->members[index].key,
                    node->members[index].key_length + 1U
                );
                HeapFree(GetProcessHeap(), 0U, node->members[index].key);
            }
            wls_launcher_json_free(node->members[index].value);
        }
    }
    if (node->decoded != NULL) {
        SecureZeroMemory(node->decoded, node->decoded_length + 1U);
        HeapFree(GetProcessHeap(), 0U, node->decoded);
    }
    if (node->items != NULL) {
        SecureZeroMemory(
            node->items, node->capacity * sizeof(node->items[0])
        );
        HeapFree(GetProcessHeap(), 0U, node->items);
    }
    if (node->members != NULL) {
        SecureZeroMemory(
            node->members, node->capacity * sizeof(node->members[0])
        );
        HeapFree(GetProcessHeap(), 0U, node->members);
    }
    SecureZeroMemory(node, sizeof(*node));
    HeapFree(GetProcessHeap(), 0U, node);
}

static void wls_launcher_json_skip_space(
    struct wls_launcher_json_parser *parser
) {
    while (parser->cursor < parser->end
        && (*parser->cursor == ' ' || *parser->cursor == '\t'
            || *parser->cursor == '\r' || *parser->cursor == '\n')) {
        parser->cursor++;
    }
}

static int wls_launcher_json_hex(char value)
{
    if (value >= '0' && value <= '9') return value - '0';
    if (value >= 'a' && value <= 'f') return value - 'a' + 10;
    if (value >= 'A' && value <= 'F') return value - 'A' + 10;
    return -1;
}

static int wls_launcher_json_utf8_append(
    char *output,
    size_t capacity,
    size_t *used,
    unsigned int codepoint
) {
    if (output == NULL || used == NULL || codepoint == 0U
        || codepoint > 0x10ffffU
        || (codepoint >= 0xd800U && codepoint <= 0xdfffU)) return 1;
    if (codepoint <= 0x7fU) {
        if (*used + 1U >= capacity) return 1;
        output[(*used)++] = (char)codepoint;
    } else if (codepoint <= 0x7ffU) {
        if (*used + 2U >= capacity) return 1;
        output[(*used)++] = (char)(0xc0U | (codepoint >> 6U));
        output[(*used)++] = (char)(0x80U | (codepoint & 0x3fU));
    } else if (codepoint <= 0xffffU) {
        if (*used + 3U >= capacity) return 1;
        output[(*used)++] = (char)(0xe0U | (codepoint >> 12U));
        output[(*used)++] = (char)(0x80U | ((codepoint >> 6U) & 0x3fU));
        output[(*used)++] = (char)(0x80U | (codepoint & 0x3fU));
    } else {
        if (*used + 4U >= capacity) return 1;
        output[(*used)++] = (char)(0xf0U | (codepoint >> 18U));
        output[(*used)++] = (char)(0x80U | ((codepoint >> 12U) & 0x3fU));
        output[(*used)++] = (char)(0x80U | ((codepoint >> 6U) & 0x3fU));
        output[(*used)++] = (char)(0x80U | (codepoint & 0x3fU));
    }
    return 0;
}

static int wls_launcher_json_string(
    struct wls_launcher_json_parser *parser,
    const char **raw,
    size_t *raw_length,
    char **decoded,
    size_t *decoded_length
) {
    const char *start;
    char *buffer = NULL;
    size_t capacity;
    size_t used = 0U;
    int result = 1;
    if (parser == NULL || raw == NULL || raw_length == NULL
        || decoded == NULL || decoded_length == NULL
        || parser->cursor >= parser->end || *parser->cursor != '"') return 1;
    start = parser->cursor++;
    capacity = (size_t)(parser->end - start) + 1U;
    buffer = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, capacity
    );
    if (buffer == NULL) return 1;
    while (parser->cursor < parser->end) {
        unsigned char value = (unsigned char)*parser->cursor++;
        if (value == '"') {
            int wide_length = MultiByteToWideChar(
                CP_UTF8, MB_ERR_INVALID_CHARS, buffer, (int)used, NULL, 0
            );
            if (used > 0U && wide_length <= 0) goto cleanup;
            buffer[used] = '\0';
            *raw = start;
            *raw_length = (size_t)(parser->cursor - start);
            *decoded = buffer;
            *decoded_length = used;
            buffer = NULL;
            result = 0;
            goto cleanup;
        }
        if (value < 0x20U) goto cleanup;
        if (value != '\\') {
            if (used + 1U >= capacity) goto cleanup;
            buffer[used++] = (char)value;
            continue;
        }
        if (parser->cursor >= parser->end) goto cleanup;
        value = (unsigned char)*parser->cursor++;
        if (value == '"' || value == '\\' || value == '/') {
            if (used + 1U >= capacity) goto cleanup;
            buffer[used++] = (char)value;
        } else if (value == 'b' || value == 'f' || value == 'n'
            || value == 'r' || value == 't') {
            static const char escaped[] = {'\b', '\f', '\n', '\r', '\t'};
            static const char names[] = "bfnrt";
            const char *found = strchr(names, (char)value);
            if (found == NULL || used + 1U >= capacity) goto cleanup;
            buffer[used++] = escaped[(size_t)(found - names)];
        } else if (value == 'u') {
            unsigned int codepoint = 0U;
            unsigned int index;
            if ((size_t)(parser->end - parser->cursor) < 4U) goto cleanup;
            for (index = 0U; index < 4U; index++) {
                int digit = wls_launcher_json_hex(parser->cursor[index]);
                if (digit < 0) goto cleanup;
                codepoint = (codepoint << 4U) | (unsigned int)digit;
            }
            parser->cursor += 4U;
            if (codepoint >= 0xd800U && codepoint <= 0xdbffU) {
                unsigned int low = 0U;
                if ((size_t)(parser->end - parser->cursor) < 6U
                    || parser->cursor[0] != '\\'
                    || parser->cursor[1] != 'u') goto cleanup;
                parser->cursor += 2U;
                for (index = 0U; index < 4U; index++) {
                    int digit = wls_launcher_json_hex(parser->cursor[index]);
                    if (digit < 0) goto cleanup;
                    low = (low << 4U) | (unsigned int)digit;
                }
                parser->cursor += 4U;
                if (low < 0xdc00U || low > 0xdfffU) goto cleanup;
                codepoint = 0x10000U
                    + ((codepoint - 0xd800U) << 10U)
                    + (low - 0xdc00U);
            } else if (codepoint >= 0xdc00U && codepoint <= 0xdfffU) {
                goto cleanup;
            }
            if (wls_launcher_json_utf8_append(
                    buffer, capacity, &used, codepoint
                ) != 0) goto cleanup;
        } else {
            goto cleanup;
        }
    }
cleanup:
    if (buffer != NULL) {
        SecureZeroMemory(buffer, capacity);
        HeapFree(GetProcessHeap(), 0U, buffer);
    }
    return result;
}

static struct wls_launcher_json_node *wls_launcher_json_value(
    struct wls_launcher_json_parser *parser,
    unsigned int depth
);

static int wls_launcher_json_array_add(
    struct wls_launcher_json_node *array,
    struct wls_launcher_json_node *value
) {
    size_t next;
    struct wls_launcher_json_node **items;
    if (array == NULL || value == NULL
        || array->kind != WLS_LAUNCHER_JSON_ARRAY) return 1;
    if (array->count == array->capacity) {
        next = array->capacity == 0U ? 8U : array->capacity * 2U;
        if (next < array->capacity || next > WLS_LAUNCHER_JSON_MAX_NODES) return 1;
        items = array->items == NULL
            ? (struct wls_launcher_json_node **)HeapAlloc(
                GetProcessHeap(), HEAP_ZERO_MEMORY,
                next * sizeof(array->items[0])
            )
            : (struct wls_launcher_json_node **)HeapReAlloc(
                GetProcessHeap(), HEAP_ZERO_MEMORY, array->items,
                next * sizeof(array->items[0])
            );
        if (items == NULL) return 1;
        array->items = items;
        array->capacity = next;
    }
    array->items[array->count++] = value;
    return 0;
}

static int wls_launcher_json_object_add(
    struct wls_launcher_json_node *object,
    const char *raw_key,
    size_t raw_key_length,
    char *key,
    size_t key_length,
    struct wls_launcher_json_node *value
) {
    size_t index;
    size_t next;
    struct wls_launcher_json_member *members;
    if (object == NULL || raw_key == NULL || key == NULL || value == NULL
        || object->kind != WLS_LAUNCHER_JSON_OBJECT) return 1;
    for (index = 0U; index < object->count; index++) {
        if (object->members[index].key_length == key_length
            && memcmp(object->members[index].key, key, key_length) == 0) {
            return 1;
        }
    }
    if (object->count == object->capacity) {
        next = object->capacity == 0U ? 8U : object->capacity * 2U;
        if (next < object->capacity || next > WLS_LAUNCHER_JSON_MAX_NODES) return 1;
        members = object->members == NULL
            ? (struct wls_launcher_json_member *)HeapAlloc(
                GetProcessHeap(), HEAP_ZERO_MEMORY,
                next * sizeof(object->members[0])
            )
            : (struct wls_launcher_json_member *)HeapReAlloc(
                GetProcessHeap(), HEAP_ZERO_MEMORY, object->members,
                next * sizeof(object->members[0])
            );
        if (members == NULL) return 1;
        object->members = members;
        object->capacity = next;
    }
    object->members[object->count].raw_key = raw_key;
    object->members[object->count].raw_key_length = raw_key_length;
    object->members[object->count].key = key;
    object->members[object->count].key_length = key_length;
    object->members[object->count].value = value;
    object->count++;
    return 0;
}

static struct wls_launcher_json_node *wls_launcher_json_value(
    struct wls_launcher_json_parser *parser,
    unsigned int depth
) {
    struct wls_launcher_json_node *node = NULL;
    const char *start;
    if (parser == NULL || depth > WLS_LAUNCHER_JSON_MAX_DEPTH
        || parser->nodes >= WLS_LAUNCHER_JSON_MAX_NODES) return NULL;
    wls_launcher_json_skip_space(parser);
    if (parser->cursor >= parser->end) return NULL;
    node = (struct wls_launcher_json_node *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, sizeof(*node)
    );
    if (node == NULL) return NULL;
    parser->nodes++;
    start = parser->cursor;
    if (*parser->cursor == '"') {
        node->kind = WLS_LAUNCHER_JSON_STRING;
        if (wls_launcher_json_string(
                parser, &node->raw, &node->raw_length,
                &node->decoded, &node->decoded_length
            ) != 0) goto failed;
        return node;
    }
    if (*parser->cursor == '[') {
        node->kind = WLS_LAUNCHER_JSON_ARRAY;
        parser->cursor++;
        wls_launcher_json_skip_space(parser);
        if (parser->cursor < parser->end && *parser->cursor == ']') {
            parser->cursor++;
            node->raw = start;
            node->raw_length = (size_t)(parser->cursor - start);
            return node;
        }
        for (;;) {
            struct wls_launcher_json_node *item =
                wls_launcher_json_value(parser, depth + 1U);
            if (item == NULL || wls_launcher_json_array_add(node, item) != 0) {
                wls_launcher_json_free(item);
                goto failed;
            }
            wls_launcher_json_skip_space(parser);
            if (parser->cursor >= parser->end) goto failed;
            if (*parser->cursor == ']') {
                parser->cursor++;
                node->raw = start;
                node->raw_length = (size_t)(parser->cursor - start);
                return node;
            }
            if (*parser->cursor++ != ',') goto failed;
        }
    }
    if (*parser->cursor == '{') {
        node->kind = WLS_LAUNCHER_JSON_OBJECT;
        parser->cursor++;
        wls_launcher_json_skip_space(parser);
        if (parser->cursor < parser->end && *parser->cursor == '}') {
            parser->cursor++;
            node->raw = start;
            node->raw_length = (size_t)(parser->cursor - start);
            return node;
        }
        for (;;) {
            const char *raw_key = NULL;
            size_t raw_key_length = 0U;
            char *key = NULL;
            size_t key_length = 0U;
            struct wls_launcher_json_node *value;
            wls_launcher_json_skip_space(parser);
            if (wls_launcher_json_string(
                    parser, &raw_key, &raw_key_length,
                    &key, &key_length
                ) != 0) goto failed;
            wls_launcher_json_skip_space(parser);
            if (parser->cursor >= parser->end || *parser->cursor++ != ':') {
                SecureZeroMemory(key, key_length + 1U);
                HeapFree(GetProcessHeap(), 0U, key);
                goto failed;
            }
            value = wls_launcher_json_value(parser, depth + 1U);
            if (value == NULL || wls_launcher_json_object_add(
                    node, raw_key, raw_key_length,
                    key, key_length, value
                ) != 0) {
                SecureZeroMemory(key, key_length + 1U);
                HeapFree(GetProcessHeap(), 0U, key);
                wls_launcher_json_free(value);
                goto failed;
            }
            wls_launcher_json_skip_space(parser);
            if (parser->cursor >= parser->end) goto failed;
            if (*parser->cursor == '}') {
                parser->cursor++;
                node->raw = start;
                node->raw_length = (size_t)(parser->cursor - start);
                return node;
            }
            if (*parser->cursor++ != ',') goto failed;
        }
    }
    if ((size_t)(parser->end - parser->cursor) >= 4U
        && memcmp(parser->cursor, "null", 4U) == 0) {
        node->kind = WLS_LAUNCHER_JSON_NULL;
        parser->cursor += 4U;
    } else if ((size_t)(parser->end - parser->cursor) >= 4U
        && memcmp(parser->cursor, "true", 4U) == 0) {
        node->kind = WLS_LAUNCHER_JSON_BOOLEAN;
        parser->cursor += 4U;
    } else if ((size_t)(parser->end - parser->cursor) >= 5U
        && memcmp(parser->cursor, "false", 5U) == 0) {
        node->kind = WLS_LAUNCHER_JSON_BOOLEAN;
        parser->cursor += 5U;
    } else {
        const char *number = parser->cursor;
        node->kind = WLS_LAUNCHER_JSON_NUMBER;
        if (*parser->cursor == '-') parser->cursor++;
        if (parser->cursor >= parser->end) goto failed;
        if (*parser->cursor == '0') {
            parser->cursor++;
            if (parser->cursor < parser->end
                && *parser->cursor >= '0' && *parser->cursor <= '9') goto failed;
        } else {
            if (*parser->cursor < '1' || *parser->cursor > '9') goto failed;
            do { parser->cursor++; }
            while (parser->cursor < parser->end
                && *parser->cursor >= '0' && *parser->cursor <= '9');
        }
        if (parser->cursor < parser->end && *parser->cursor == '.') {
            parser->cursor++;
            if (parser->cursor >= parser->end
                || *parser->cursor < '0' || *parser->cursor > '9') goto failed;
            do { parser->cursor++; }
            while (parser->cursor < parser->end
                && *parser->cursor >= '0' && *parser->cursor <= '9');
        }
        if (parser->cursor < parser->end
            && (*parser->cursor == 'e' || *parser->cursor == 'E')) {
            parser->cursor++;
            if (parser->cursor < parser->end
                && (*parser->cursor == '+' || *parser->cursor == '-')) parser->cursor++;
            if (parser->cursor >= parser->end
                || *parser->cursor < '0' || *parser->cursor > '9') goto failed;
            do { parser->cursor++; }
            while (parser->cursor < parser->end
                && *parser->cursor >= '0' && *parser->cursor <= '9');
        }
        if (parser->cursor == number) goto failed;
    }
    node->raw = start;
    node->raw_length = (size_t)(parser->cursor - start);
    return node;
failed:
    wls_launcher_json_free(node);
    return NULL;
}

static struct wls_launcher_json_node *wls_launcher_json_parse(
    const unsigned char *json,
    size_t length
) {
    struct wls_launcher_json_parser parser;
    struct wls_launcher_json_node *root;
    if (json == NULL || length == 0U || length > WLS_MAX_MANIFEST) return NULL;
    parser.cursor = (const char *)json;
    parser.end = (const char *)json + length;
    parser.nodes = 0U;
    root = wls_launcher_json_value(&parser, 0U);
    wls_launcher_json_skip_space(&parser);
    if (root == NULL || parser.cursor != parser.end) {
        wls_launcher_json_free(root);
        return NULL;
    }
    return root;
}

static const struct wls_launcher_json_node *wls_launcher_json_member(
    const struct wls_launcher_json_node *object,
    const char *name
) {
    size_t length;
    size_t index;
    if (object == NULL || name == NULL
        || object->kind != WLS_LAUNCHER_JSON_OBJECT) return NULL;
    length = strlen(name);
    for (index = 0U; index < object->count; index++) {
        if (object->members[index].key_length == length
            && memcmp(object->members[index].key, name, length) == 0) {
            return object->members[index].value;
        }
    }
    return NULL;
}

static int wls_launcher_json_string_equals(
    const struct wls_launcher_json_node *node,
    const char *expected
) {
    size_t length = expected != NULL ? strlen(expected) : 0U;
    return node != NULL && expected != NULL
        && node->kind == WLS_LAUNCHER_JSON_STRING
        && node->decoded_length == length
        && memcmp(node->decoded, expected, length) == 0;
}

static int wls_launcher_json_number_equals(
    const struct wls_launcher_json_node *node,
    const char *expected
) {
    size_t length = expected != NULL ? strlen(expected) : 0U;
    return node != NULL && expected != NULL
        && node->kind == WLS_LAUNCHER_JSON_NUMBER
        && node->raw_length == length
        && memcmp(node->raw, expected, length) == 0;
}

static int wls_launcher_json_boolean_true(
    const struct wls_launcher_json_node *node
) {
    return node != NULL && node->kind == WLS_LAUNCHER_JSON_BOOLEAN
        && node->raw_length == 4U && memcmp(node->raw, "true", 4U) == 0;
}

static int wls_launcher_json_u64(
    const struct wls_launcher_json_node *node,
    unsigned long long *value
) {
    unsigned long long parsed = 0ULL;
    size_t index;
    if (node == NULL || value == NULL
        || node->kind != WLS_LAUNCHER_JSON_NUMBER
        || node->raw_length == 0U) return 1;
    for (index = 0U; index < node->raw_length; index++) {
        unsigned int digit;
        if (node->raw[index] < '0' || node->raw[index] > '9') return 1;
        digit = (unsigned int)(node->raw[index] - '0');
        if (parsed > (ULLONG_MAX - digit) / 10ULL) return 1;
        parsed = parsed * 10ULL + digit;
    }
    *value = parsed;
    return 0;
}

static int wls_launcher_json_member_compare(
    const void *left,
    const void *right
) {
    const struct wls_launcher_json_member *const *a =
        (const struct wls_launcher_json_member *const *)left;
    const struct wls_launcher_json_member *const *b =
        (const struct wls_launcher_json_member *const *)right;
    size_t common = (*a)->key_length < (*b)->key_length
        ? (*a)->key_length : (*b)->key_length;
    int compared = memcmp((*a)->key, (*b)->key, common);
    if (compared != 0) return compared;
    if ((*a)->key_length < (*b)->key_length) return -1;
    if ((*a)->key_length > (*b)->key_length) return 1;
    return 0;
}

static int wls_launcher_json_hash_update(
    crypto_hash_sha256_state *state,
    const void *bytes,
    size_t length
) {
    return length <= ULLONG_MAX
        && crypto_hash_sha256_update(
            state, (const unsigned char *)bytes, (unsigned long long)length
        ) == 0 ? 0 : 1;
}

static int wls_launcher_json_hash_node(
    crypto_hash_sha256_state *state,
    const struct wls_launcher_json_node *node,
    const char *excluded_member
) {
    size_t index;
    if (state == NULL || node == NULL) return 1;
    if (node->kind != WLS_LAUNCHER_JSON_ARRAY
        && node->kind != WLS_LAUNCHER_JSON_OBJECT) {
        return wls_launcher_json_hash_update(state, node->raw, node->raw_length);
    }
    if (node->kind == WLS_LAUNCHER_JSON_ARRAY) {
        if (wls_launcher_json_hash_update(state, "[", 1U) != 0) return 1;
        for (index = 0U; index < node->count; index++) {
            if (index > 0U
                && wls_launcher_json_hash_update(state, ",", 1U) != 0) return 1;
            if (wls_launcher_json_hash_node(
                    state, node->items[index], NULL
                ) != 0) return 1;
        }
        return wls_launcher_json_hash_update(state, "]", 1U);
    }
    {
        struct wls_launcher_json_member **members = NULL;
        size_t emitted = 0U;
        int result = 1;
        if (node->count > 0U) {
            members = (struct wls_launcher_json_member **)HeapAlloc(
                GetProcessHeap(), HEAP_ZERO_MEMORY,
                node->count * sizeof(members[0])
            );
            if (members == NULL) return 1;
            for (index = 0U; index < node->count; index++) {
                members[index] = &node->members[index];
            }
            qsort(
                members, node->count, sizeof(members[0]),
                wls_launcher_json_member_compare
            );
        }
        if (wls_launcher_json_hash_update(state, "{", 1U) != 0) goto cleanup;
        for (index = 0U; index < node->count; index++) {
            if (excluded_member != NULL
                && members[index]->key_length == strlen(excluded_member)
                && memcmp(
                    members[index]->key,
                    excluded_member,
                    members[index]->key_length
                ) == 0) continue;
            if (emitted++ > 0U
                && wls_launcher_json_hash_update(state, ",", 1U) != 0) goto cleanup;
            if (wls_launcher_json_hash_update(
                    state, members[index]->raw_key,
                    members[index]->raw_key_length
                ) != 0
                || wls_launcher_json_hash_update(state, ":", 1U) != 0
                || wls_launcher_json_hash_node(
                    state, members[index]->value, NULL
                ) != 0) goto cleanup;
        }
        if (wls_launcher_json_hash_update(state, "}", 1U) != 0) goto cleanup;
        result = 0;
cleanup:
        if (members != NULL) HeapFree(GetProcessHeap(), 0U, members);
        return result;
    }
}

static int wls_launcher_json_generation(
    const struct wls_launcher_json_node *root,
    char digest[65]
) {
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    int result = 1;
    SecureZeroMemory(binary, sizeof(binary));
    if (root == NULL || root->kind != WLS_LAUNCHER_JSON_OBJECT
        || crypto_hash_sha256_init(&state) != 0
        || wls_launcher_json_hash_node(
            &state, root, "runtime_generation"
        ) != 0
        || crypto_hash_sha256_final(&state, binary) != 0) goto cleanup;
    sodium_bin2hex(digest, 65U, binary, sizeof(binary));
    result = 0;
cleanup:
    sodium_memzero(binary, sizeof(binary));
    return result;
}

static int wls_launcher_exact_durable_contract_v2(
    const struct wls_launcher_json_node *contract
) {
    return contract != NULL
        && contract->kind == WLS_LAUNCHER_JSON_OBJECT
        && contract->count == 8U
        && wls_launcher_json_number_equals(
            wls_launcher_json_member(contract, "schema_version"), "2"
        )
        && wls_launcher_json_number_equals(
            wls_launcher_json_member(contract, "security_ledger_read_schema"), "8"
        )
        && wls_launcher_json_number_equals(
            wls_launcher_json_member(contract, "security_ledger_write_schema"), "8"
        )
        && wls_launcher_json_number_equals(
            wls_launcher_json_member(contract, "snapshot_receipt_read_schema"), "2"
        )
        && wls_launcher_json_number_equals(
            wls_launcher_json_member(contract, "snapshot_receipt_write_schema"), "2"
        )
        && wls_launcher_json_string_equals(
            wls_launcher_json_member(contract, "snapshot_namespace"), "snapshots-v2"
        )
        && wls_launcher_json_number_equals(
            wls_launcher_json_member(contract, "nonce_wal_schema"), "1"
        )
        && wls_launcher_json_number_equals(
            wls_launcher_json_member(contract, "nginx_test_schema"), "1"
        ) ? 0 : 1;
}

static void wls_launcher_file_observation_free(
    struct wls_launcher_file_observation *observation
) {
    if (observation == NULL) return;
    if (observation->contents != NULL) {
        SecureZeroMemory(observation->contents, observation->length + 1U);
        HeapFree(GetProcessHeap(), 0U, observation->contents);
    }
    SecureZeroMemory(observation, sizeof(*observation));
}

static int wls_launcher_file_identity_equal(
    const BY_HANDLE_FILE_INFORMATION *left,
    const BY_HANDLE_FILE_INFORMATION *right
) {
    return left != NULL && right != NULL
        && left->dwVolumeSerialNumber == right->dwVolumeSerialNumber
        && left->nFileIndexHigh == right->nFileIndexHigh
        && left->nFileIndexLow == right->nFileIndexLow
        && left->nFileSizeHigh == right->nFileSizeHigh
        && left->nFileSizeLow == right->nFileSizeLow
        && left->nNumberOfLinks == right->nNumberOfLinks;
}

static int wls_launcher_file_observe(
    const wchar_t *path,
    size_t maximum,
    struct wls_launcher_file_observation *observation,
    PSID service_sid,
    int controller_acl
) {
    HANDLE file = INVALID_HANDLE_VALUE;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    BY_HANDLE_FILE_INFORMATION before;
    BY_HANDLE_FILE_INFORMATION after;
    LARGE_INTEGER size;
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    DWORD used = 0U;
    int result = 1;
    if (path == NULL || observation == NULL
        || (controller_acl != 0 && controller_acl != 1)
        || (controller_acl && service_sid == NULL)
        || maximum > MAXDWORD) return 1;
    SecureZeroMemory(observation, sizeof(*observation));
    SecureZeroMemory(binary, sizeof(binary));
    file = CreateFileW(
        path,
        GENERIC_READ | READ_CONTROL,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT
            | FILE_FLAG_SEQUENTIAL_SCAN,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            file, FileAttributeTagInfo, &attributes, sizeof(attributes)
        )
        || !GetFileInformationByHandle(file, &before)
        || (attributes.FileAttributes
            & (FILE_ATTRIBUTE_REPARSE_POINT | FILE_ATTRIBUTE_DIRECTORY)) != 0
        || before.nNumberOfLinks != 1U
        || wls_launcher_slot_acl_valid_mode(
            file, 0, service_sid, controller_acl
        ) != 0
        || !GetFileSizeEx(file, &size)
        || size.QuadPart < 0
        || (uint64_t)size.QuadPart > maximum
        || size.QuadPart > MAXDWORD
        || crypto_hash_sha256_init(&state) != 0) goto cleanup;
    observation->contents = (unsigned char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, (size_t)size.QuadPart + 1U
    );
    if (observation->contents == NULL) goto cleanup;
    while (used < (DWORD)size.QuadPart) {
        DWORD amount = 0U;
        if (!ReadFile(
                file,
                observation->contents + used,
                (DWORD)size.QuadPart - used,
                &amount,
                NULL
            )
            || amount == 0U
            || crypto_hash_sha256_update(
                &state, observation->contents + used, amount
            ) != 0) goto cleanup;
        used += amount;
    }
    if (!GetFileInformationByHandleEx(
            file, FileAttributeTagInfo, &attributes, sizeof(attributes)
        )
        || !GetFileInformationByHandle(file, &after)
        || (attributes.FileAttributes
            & (FILE_ATTRIBUTE_REPARSE_POINT | FILE_ATTRIBUTE_DIRECTORY)) != 0
        || after.nNumberOfLinks != 1U
        || !wls_launcher_file_identity_equal(&before, &after)
        || wls_launcher_slot_acl_valid_mode(
            file, 0, service_sid, controller_acl
        ) != 0
        || crypto_hash_sha256_final(&state, binary) != 0) goto cleanup;
    observation->contents[used] = '\0';
    observation->length = used;
    observation->identity = before;
    sodium_bin2hex(observation->digest, 65U, binary, sizeof(binary));
    result = 0;
cleanup:
    sodium_memzero(binary, sizeof(binary));
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (result != 0) wls_launcher_file_observation_free(observation);
    return result;
}

static int wls_launcher_file_observation_equal(
    const struct wls_launcher_file_observation *left,
    const struct wls_launcher_file_observation *right
) {
    return left != NULL && right != NULL
        && wls_launcher_file_identity_equal(&left->identity, &right->identity)
        && left->length == right->length
        && sodium_memcmp(left->digest, right->digest, 64U) == 0;
}

static int wls_verify_release_bytes(
    const struct wls_launcher_file_observation *manifest,
    const struct wls_launcher_file_observation *signature_text,
    const unsigned char public_key[crypto_sign_PUBLICKEYBYTES]
) {
    unsigned char signature[crypto_sign_BYTES];
    size_t decoded = 0U;
    int result = 1;
    SecureZeroMemory(signature, sizeof(signature));
    if (manifest == NULL || signature_text == NULL
        || public_key == NULL
        || signature_text->length > 256U
        || sodium_base642bin(
            signature,
            sizeof(signature),
            (const char *)signature_text->contents,
            signature_text->length,
            "\r\n\t ",
            &decoded,
            NULL,
            sodium_base64_VARIANT_ORIGINAL
        ) != 0
        || decoded != crypto_sign_BYTES
        || crypto_sign_verify_detached(
            signature,
            manifest->contents,
            (unsigned long long)manifest->length,
            public_key
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(signature, sizeof(signature));
    return result;
}

static int wls_file_digest(
    const wchar_t *path,
    char digest[65],
    unsigned long long *size,
    BY_HANDLE_FILE_INFORMATION *identity,
    PSID service_sid,
    int controller_acl,
    int data_plane_acl
)
{
    HANDLE file;
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    unsigned char buffer[65536];
    DWORD amount;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    BY_HANDLE_FILE_INFORMATION before;
    BY_HANDLE_FILE_INFORMATION after;
    int result = 1;
    if (path == NULL || digest == NULL || size == NULL || identity == NULL
        || (controller_acl != 0 && controller_acl != 1)
        || (data_plane_acl != 0 && data_plane_acl != 1)
        || (data_plane_acl && !controller_acl)
        || (controller_acl && service_sid == NULL)) {
        return 1;
    }
    file = CreateFileW(
        path,
        GENERIC_READ | READ_CONTROL,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT
            | FILE_FLAG_SEQUENTIAL_SCAN,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            file,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || !GetFileInformationByHandle(file, &before)
        || (attributes.FileAttributes
            & (FILE_ATTRIBUTE_REPARSE_POINT | FILE_ATTRIBUTE_DIRECTORY)) != 0
        || before.nNumberOfLinks != 1U
        || (data_plane_acl
            ? wls_launcher_nginx_acl_valid(file, service_sid)
            : wls_launcher_slot_acl_valid_mode(
                file, 0, service_sid, controller_acl
            )) != 0
        || crypto_hash_sha256_init(&state) != 0) {
        if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
        return 1;
    }
    for (;;) {
        if (!ReadFile(file, buffer, sizeof(buffer), &amount, NULL)) goto cleanup;
        if (amount == 0U) break;
        if (crypto_hash_sha256_update(&state, buffer, amount) != 0) goto cleanup;
    }
    if (!GetFileInformationByHandleEx(
            file,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || !GetFileInformationByHandle(file, &after)
        || (attributes.FileAttributes
            & (FILE_ATTRIBUTE_REPARSE_POINT | FILE_ATTRIBUTE_DIRECTORY)) != 0
        || after.nNumberOfLinks != 1U
        || !wls_launcher_file_identity_equal(&before, &after)
        || (data_plane_acl
            ? wls_launcher_nginx_acl_valid(file, service_sid)
            : wls_launcher_slot_acl_valid_mode(
                file, 0, service_sid, controller_acl
            )) != 0
        || crypto_hash_sha256_final(&state, binary) != 0) goto cleanup;
    sodium_bin2hex(digest, 65U, binary, sizeof(binary));
    *size = ((unsigned long long)after.nFileSizeHigh << 32U)
        | (unsigned long long)after.nFileSizeLow;
    *identity = after;
    result = 0;
cleanup:
    sodium_memzero(binary, sizeof(binary));
    CloseHandle(file);
    return result;
}

static int wls_utf8_to_wide(const char *input, wchar_t *output, size_t capacity)
{
    return MultiByteToWideChar(
        CP_UTF8,
        MB_ERR_INVALID_CHARS,
        input,
        -1,
        output,
        (int)capacity
    ) > 0 ? 0 : 1;
}

static int wls_verify_component_v2(
    const struct wls_launcher_json_node *components,
    const wchar_t *slot,
    const char *relative,
    unsigned long long expected_mode,
    struct wls_launcher_component_observation *observation,
    PSID service_sid,
    int controller_acl
) {
    const struct wls_launcher_json_node *definition;
    const struct wls_launcher_json_node *expected_node;
    unsigned long long declared_size = 0ULL;
    unsigned long long declared_mode = 0ULL;
    char expected[65];
    wchar_t relative_wide[512];
    wchar_t path[WLS_PATH_CHARS];
    if (observation == NULL
        || (controller_acl != 0 && controller_acl != 1)
        || (controller_acl && service_sid == NULL)) return 1;
    ZeroMemory(observation, sizeof(*observation));
    if (components == NULL || components->kind != WLS_LAUNCHER_JSON_OBJECT
        || (definition = wls_launcher_json_member(components, relative)) == NULL
        || definition->kind != WLS_LAUNCHER_JSON_OBJECT
        || definition->count != 3U
        || (expected_node = wls_launcher_json_member(
            definition, "sha256"
        )) == NULL
        || expected_node->kind != WLS_LAUNCHER_JSON_STRING
        || expected_node->decoded_length != 64U
        || !wls_is_hex(expected_node->decoded, 64U)
        || wls_launcher_json_u64(
            wls_launcher_json_member(definition, "mode"), &declared_mode
        ) != 0
        || declared_mode != expected_mode
        || wls_launcher_json_u64(
            wls_launcher_json_member(definition, "size"), &declared_size
        ) != 0
        || wls_utf8_to_wide(relative, relative_wide, 512U) != 0
        || wls_join(path, WLS_PATH_CHARS, slot, relative_wide) != 0
        || wls_file_digest(
            path,
            observation->digest,
            &observation->size,
            &observation->identity,
            service_sid,
            controller_acl,
            controller_acl && strcmp(relative, "bin/nginx.exe") == 0
        ) != 0
        || observation->size != declared_size) {
        return 1;
    }
    memcpy(expected, expected_node->decoded, 64U);
    expected[64] = '\0';
    if (sodium_memcmp(expected, observation->digest, 64U) != 0) return 1;
    return 0;
}

static int wls_verify_slot_durable_state_contract_v2_at_path_with_key(
    const wchar_t *slot_path,
    wchar_t slot_name,
    int controller_acl,
    char runtime_generation[65],
    const unsigned char public_key[crypto_sign_PUBLICKEYBYTES]
) {
    static const struct {
        const char *relative;
        unsigned long long mode;
    } release_required[] = {
        {"app/controller.php", 420ULL},
        {"bin/nginx.exe", 493ULL},
        {"bin/php.exe", 493ULL},
        {"bin/wls-gateway-broker.exe", 493ULL},
        {"bin/wls-gateway-launcher.exe", 493ULL}
    };
    static const struct {
        const char *relative;
        unsigned long long mode;
    } installed_required[] = {
        {"app/controller.php", 420ULL},
        {"bin/nginx.exe", 493ULL},
        {"bin/php.exe", 493ULL},
        {"bin/wls-gateway-broker.exe", 493ULL},
        {"bin/wls-gateway-launcher.exe", 493ULL},
        {"release/manifest.json", 384ULL},
        {"release/manifest.sig", 384ULL}
    };
    wchar_t slot[WLS_PATH_CHARS];
    wchar_t bin[WLS_PATH_CHARS];
    wchar_t app[WLS_PATH_CHARS];
    wchar_t release_directory[WLS_PATH_CHARS];
    wchar_t release_manifest_path[WLS_PATH_CHARS];
    wchar_t release_signature_path[WLS_PATH_CHARS];
    wchar_t installed_manifest_path[WLS_PATH_CHARS];
    char slot_text[2] = {(char)slot_name, '\0'};
    char declared_generation[65];
    char computed_generation[65];
    struct wls_launcher_file_observation release_before;
    struct wls_launcher_file_observation signature_before;
    struct wls_launcher_file_observation installed_before;
    struct wls_launcher_file_observation release_after;
    struct wls_launcher_file_observation signature_after;
    struct wls_launcher_file_observation installed_after;
    struct wls_launcher_json_node *release_root = NULL;
    struct wls_launcher_json_node *installed_root = NULL;
    const struct wls_launcher_json_node *release_components;
    const struct wls_launcher_json_node *installed_components;
    const struct wls_launcher_json_node *release_capabilities;
    const struct wls_launcher_json_node *installed_capabilities;
    const struct wls_launcher_json_node *generation;
    struct wls_launcher_component_observation release_observations[
        sizeof(release_required) / sizeof(release_required[0])
    ];
    struct wls_launcher_component_observation installed_observation;
    PSID service_sid = NULL;
    DWORD slot_length;
    size_t index;
    int result = 1;
    SecureZeroMemory(&release_before, sizeof(release_before));
    SecureZeroMemory(&signature_before, sizeof(signature_before));
    SecureZeroMemory(&installed_before, sizeof(installed_before));
    SecureZeroMemory(&release_after, sizeof(release_after));
    SecureZeroMemory(&signature_after, sizeof(signature_after));
    SecureZeroMemory(&installed_after, sizeof(installed_after));
    SecureZeroMemory(declared_generation, sizeof(declared_generation));
    SecureZeroMemory(computed_generation, sizeof(computed_generation));
    ZeroMemory(release_observations, sizeof(release_observations));
    ZeroMemory(&installed_observation, sizeof(installed_observation));
    if (slot_path == NULL || runtime_generation == NULL || public_key == NULL
        || (slot_name != L'A' && slot_name != L'B')
        || (controller_acl != 0 && controller_acl != 1)
        || wls_launcher_gateway_service_sid(&service_sid) != 0
        || (slot_length = GetFullPathNameW(
            slot_path, WLS_PATH_CHARS, slot, NULL
        )) < 3U
        || slot_length >= WLS_PATH_CHARS
        || wcscmp(slot, slot_path) != 0
        || wls_join(bin, WLS_PATH_CHARS, slot, L"bin") != 0
        || wls_join(app, WLS_PATH_CHARS, slot, L"app") != 0
        || wls_join(
            release_directory, WLS_PATH_CHARS, slot, L"release"
        ) != 0
        || (controller_acl
            ? wls_launcher_data_plane_directory_valid(
                slot, service_sid, 0
            )
            : wls_launcher_slot_directory_valid_mode(
                slot, service_sid, 0
            )) != 0
        || (controller_acl
            ? wls_launcher_data_plane_directory_valid(
                bin, service_sid, 0
            )
            : wls_launcher_slot_directory_valid_mode(
                bin, service_sid, 0
            )) != 0
        || wls_launcher_slot_directory_valid_mode(
            app, service_sid, controller_acl
        ) != 0
        || wls_launcher_slot_directory_valid_mode(
            release_directory, service_sid, controller_acl
        ) != 0
        || wls_join(
            release_manifest_path, WLS_PATH_CHARS,
            release_directory, L"manifest.json"
        ) != 0
        || wls_join(
            release_signature_path, WLS_PATH_CHARS,
            release_directory, L"manifest.sig"
        ) != 0
        || wls_join(
            installed_manifest_path, WLS_PATH_CHARS,
            slot, L"manifest.json"
        ) != 0
        || wls_launcher_file_observe(
            release_manifest_path, WLS_MAX_MANIFEST, &release_before,
            service_sid, controller_acl
        ) != 0
        || wls_launcher_file_observe(
            release_signature_path, 256U, &signature_before,
            service_sid, controller_acl
        ) != 0
        || wls_launcher_file_observe(
            installed_manifest_path, WLS_MAX_MANIFEST, &installed_before,
            service_sid, controller_acl
        ) != 0
        || wls_verify_release_bytes(
            &release_before, &signature_before, public_key
        ) != 0) {
        goto cleanup;
    }
    release_root = wls_launcher_json_parse(
        release_before.contents, release_before.length
    );
    installed_root = wls_launcher_json_parse(
        installed_before.contents, installed_before.length
    );
    if (release_root == NULL || installed_root == NULL
        || release_root->kind != WLS_LAUNCHER_JSON_OBJECT
        || installed_root->kind != WLS_LAUNCHER_JSON_OBJECT
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(release_root, "schema_version"), "2"
        )
        || wls_launcher_exact_durable_contract_v2(
            wls_launcher_json_member(release_root, "durable_state_contract")
        ) != 0
        || (release_components = wls_launcher_json_member(
            release_root, "components"
        )) == NULL
        || release_components->kind != WLS_LAUNCHER_JSON_OBJECT
        || (release_capabilities = wls_launcher_json_member(
            release_root, "capabilities"
        )) == NULL
        || release_capabilities->kind != WLS_LAUNCHER_JSON_OBJECT
        || !wls_launcher_json_boolean_true(wls_launcher_json_member(
            release_capabilities, "stable_launcher_rollback_target_proof"
        ))
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(installed_root, "schema_version"), "2"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(installed_root, "role"), "host_gateway"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(installed_root, "slot"), slot_text
        )
        || wls_launcher_exact_durable_contract_v2(
            wls_launcher_json_member(installed_root, "durable_state_contract")
        ) != 0
        || (installed_components = wls_launcher_json_member(
            installed_root, "components"
        )) == NULL
        || installed_components->kind != WLS_LAUNCHER_JSON_OBJECT
        || (installed_capabilities = wls_launcher_json_member(
            installed_root, "capabilities"
        )) == NULL
        || installed_capabilities->kind != WLS_LAUNCHER_JSON_OBJECT
        || !wls_launcher_json_boolean_true(wls_launcher_json_member(
            installed_capabilities, "stable_launcher_rollback_target_proof"
        ))
        || (generation = wls_launcher_json_member(
            installed_root, "runtime_generation"
        )) == NULL
        || generation->kind != WLS_LAUNCHER_JSON_STRING
        || generation->decoded_length != 64U
        || !wls_is_hex(generation->decoded, 64U)
        || wls_launcher_json_generation(
            installed_root, computed_generation
        ) != 0) goto cleanup;
    memcpy(declared_generation, generation->decoded, 64U);
    declared_generation[64] = '\0';
    if (sodium_memcmp(
            declared_generation, computed_generation, 64U
        ) != 0) goto cleanup;
    for (index = 0U;
        index < sizeof(release_required) / sizeof(release_required[0]);
        index++) {
        if (wls_verify_component_v2(
                release_components,
                slot,
                release_required[index].relative,
                release_required[index].mode,
                &release_observations[index],
                service_sid,
                controller_acl
            ) != 0) goto cleanup;
    }
    for (index = 0U;
        index < sizeof(installed_required) / sizeof(installed_required[0]);
        index++) {
        if (wls_verify_component_v2(
                installed_components,
                slot,
                installed_required[index].relative,
                installed_required[index].mode,
                &installed_observation,
                service_sid,
                controller_acl
            ) != 0) goto cleanup;
        if (index < sizeof(release_required) / sizeof(release_required[0])
            && (!wls_launcher_file_identity_equal(
                    &release_observations[index].identity,
                    &installed_observation.identity
                )
                || release_observations[index].size
                    != installed_observation.size
                || sodium_memcmp(
                    release_observations[index].digest,
                    installed_observation.digest,
                    64U
                ) != 0)) goto cleanup;
    }
    if (wls_launcher_file_observe(
            release_manifest_path, WLS_MAX_MANIFEST, &release_after,
            service_sid, controller_acl
        ) != 0
        || wls_launcher_file_observe(
            release_signature_path, 256U, &signature_after,
            service_sid, controller_acl
        ) != 0
        || wls_launcher_file_observe(
            installed_manifest_path, WLS_MAX_MANIFEST, &installed_after,
            service_sid, controller_acl
        ) != 0
        || !wls_launcher_file_observation_equal(
            &release_before, &release_after
        )
        || !wls_launcher_file_observation_equal(
            &signature_before, &signature_after
        )
        || !wls_launcher_file_observation_equal(
            &installed_before, &installed_after
        )) goto cleanup;
    memcpy(runtime_generation, declared_generation, 65U);
    result = 0;
cleanup:
    wls_launcher_json_free(release_root);
    wls_launcher_json_free(installed_root);
    wls_launcher_file_observation_free(&release_before);
    wls_launcher_file_observation_free(&signature_before);
    wls_launcher_file_observation_free(&installed_before);
    wls_launcher_file_observation_free(&release_after);
    wls_launcher_file_observation_free(&signature_after);
    wls_launcher_file_observation_free(&installed_after);
    SecureZeroMemory(declared_generation, sizeof(declared_generation));
    SecureZeroMemory(computed_generation, sizeof(computed_generation));
    SecureZeroMemory(release_observations, sizeof(release_observations));
    SecureZeroMemory(&installed_observation, sizeof(installed_observation));
    if (service_sid != NULL) LocalFree(service_sid);
    return result;
}

static int wls_verify_slot_durable_state_contract_v2_with_key(
    const wchar_t *home,
    wchar_t slot_name,
    char runtime_generation[65],
    const unsigned char public_key[crypto_sign_PUBLICKEYBYTES]
) {
    wchar_t slots[WLS_PATH_CHARS];
    wchar_t slot[WLS_PATH_CHARS];
    PSID service_sid = NULL;
    int result = 1;
    ZeroMemory(slots, sizeof(slots));
    ZeroMemory(slot, sizeof(slot));
    if (home == NULL || runtime_generation == NULL || public_key == NULL
        || (slot_name != L'A' && slot_name != L'B')
        || wls_launcher_gateway_service_sid(&service_sid) != 0
        || wls_join(slots, WLS_PATH_CHARS, home, L"slots") != 0
        || _snwprintf_s(
            slot,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%lc",
            home,
            slot_name
        ) < 0
        || wls_launcher_data_plane_directory_valid(
            home, service_sid, 1
        ) != 0
        || wls_launcher_data_plane_directory_valid(
            slots, service_sid, 0
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_at_path_with_key(
            slot,
            slot_name,
            1,
            runtime_generation,
            public_key
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    SecureZeroMemory(slots, sizeof(slots));
    SecureZeroMemory(slot, sizeof(slot));
    return result;
}

#ifdef WLS_GUARDIAN_EXECUTABLE
static int wls_verify_slot_durable_state_contract_v2_at_path(
    const wchar_t *slot_path,
    wchar_t slot_name,
    int controller_acl,
    char runtime_generation[65]
) {
    unsigned char public_key[crypto_sign_PUBLICKEYBYTES];
    int result;
    SecureZeroMemory(public_key, sizeof(public_key));
    if (wls_public_key(public_key) != 0) return 1;
    result = wls_verify_slot_durable_state_contract_v2_at_path_with_key(
        slot_path,
        slot_name,
        controller_acl,
        runtime_generation,
        public_key
    );
    sodium_memzero(public_key, sizeof(public_key));
    return result;
}
#endif

static int wls_verify_slot_durable_state_contract_v2(
    const wchar_t *home,
    wchar_t slot_name,
    char runtime_generation[65]
) {
    unsigned char public_key[crypto_sign_PUBLICKEYBYTES];
    int result;
    SecureZeroMemory(public_key, sizeof(public_key));
    if (wls_public_key(public_key) != 0) return 1;
    result = wls_verify_slot_durable_state_contract_v2_with_key(
        home, slot_name, runtime_generation, public_key
    );
    sodium_memzero(public_key, sizeof(public_key));
    return result;
}

static int wls_require_rollback_slot_contract_v2(
    const wchar_t *home,
    wchar_t slot,
    int *verified
) {
    char generation[65];
    if (verified == NULL) return 1;
    if (*verified) return 0;
    if (wls_verify_slot_durable_state_contract_v2(
            home, slot, generation
        ) != 0) return 1;
    SecureZeroMemory(generation, sizeof(generation));
    *verified = 1;
    return 0;
}

static int wls_active_slot(const wchar_t *home, wchar_t slot[2])
{
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    int result = 1;
    if (wls_join(path, WLS_PATH_CHARS, home, L"trust\\active-slot") == 0
        && wls_read_file(path, 4U, &contents, &length) == 0
        && (length == 1U || (length == 2U && contents[1] == '\n'))
        && (contents[0] == 'A' || contents[0] == 'B')) {
        slot[0] = (wchar_t)contents[0];
        slot[1] = L'\0';
        result = 0;
    }
    if (contents != NULL) HeapFree(GetProcessHeap(), 0U, contents);
    return result;
}

/* Any existing intent, including an unsafe or damaged one, fails closed. */
static int wls_admin_stopped(const wchar_t *home)
{
    wchar_t path[WLS_PATH_CHARS];
    HANDLE intent;
    if (wls_join(
        path,
        WLS_PATH_CHARS,
        home,
        L"trust\\admin-stopped.intent"
    ) != 0) {
        return 1;
    }
    intent = CreateFileW(
        path,
        GENERIC_READ,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (intent == INVALID_HANDLE_VALUE) {
        return GetLastError() == ERROR_FILE_NOT_FOUND
            || GetLastError() == ERROR_PATH_NOT_FOUND
                ? 0
                : 1;
    }
    CloseHandle(intent);
    return 1;
}

static int wls_upgrade_intent(
    const wchar_t *home,
    struct wls_upgrade *upgrade
) {
    wchar_t intent_path[WLS_PATH_CHARS];
    wchar_t token_path[WLS_PATH_CHARS];
    wchar_t host_path[WLS_PATH_CHARS];
    unsigned char *intent = NULL;
    unsigned char *token = NULL;
    unsigned char *host_text = NULL;
    size_t intent_length = 0U;
    size_t token_length = 0U;
    size_t host_length = 0U;
    char host[33];
    char from[2];
    char to[2];
    char nonce[33];
    char signature_hex[65];
    char *signature_line;
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char expected[crypto_auth_hmacsha256_BYTES];
    unsigned char actual[crypto_auth_hmacsha256_BYTES];
    unsigned char intent_digest[crypto_hash_sha256_BYTES];
    size_t decoded = 0U;
    int consumed = 0;
    int fields;
    int result = -1;
    long long expected_activation_deadline = 0;
    unsigned long long expected_activation_monotonic = 0ULL;
    unsigned long long expected_total_monotonic = 0ULL;
    ZeroMemory(upgrade, sizeof(*upgrade));
    if (wls_join(intent_path, WLS_PATH_CHARS, home, L"trust\\upgrade.intent") != 0
        || wls_join(token_path, WLS_PATH_CHARS, home, L"trust\\admin.token") != 0
        || wls_join(host_path, WLS_PATH_CHARS, home, L"trust\\host-id") != 0) {
        return -1;
    }
    if (wls_read_file(intent_path, 2048U, &intent, &intent_length) != 0) {
        DWORD error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND ? 0 : -1;
    }
    if (wls_read_file(token_path, 256U, &token, &token_length) != 0
        || wls_read_file(host_path, 256U, &host_text, &host_length) != 0) {
        goto cleanup;
    }
    while (token_length > 0U && strchr("\r\n \t", token[token_length - 1U]) != NULL) {
        token_length--;
    }
    token[token_length] = '\0';
    while (host_length > 0U && strchr("\r\n \t", host_text[host_length - 1U]) != NULL) {
        host_length--;
    }
    host_text[host_length] = '\0';
    if (strncmp((const char *)intent, "WLS-UPGRADE/2\n", 14U) == 0) {
        fields = sscanf(
            (const char *)intent,
            "WLS-UPGRADE/2\n"
            "host_id=%32[0-9a-f]\n"
            "from=%1[AB]\n"
            "to=%1[AB]\n"
            "prepared_at=%lld\n"
            "deadline=%lld\n"
            "runtime_generation=%64[0-9a-f]\n"
            "host_boot_id=%64[0-9a-f]\n"
            "prepared_monotonic_ms=%llu\n"
            "activation_deadline_monotonic_ms=%llu\n"
            "rollback_deadline_monotonic_ms=%llu\n"
            "nonce=%32[0-9a-f]\n"
            "signature=%64[0-9a-f]\n%n",
            host, from, to,
            &upgrade->prepared_at, &upgrade->deadline,
            upgrade->runtime_generation, upgrade->boot_id,
            &upgrade->prepared_monotonic,
            &upgrade->activation_deadline_monotonic,
            &upgrade->total_deadline_monotonic,
            nonce, signature_hex, &consumed
        );
        upgrade->legacy_protocol = 0;
        if (fields != 12 || !wls_is_hex(upgrade->boot_id, 64U)
            || wls_checked_add_unsigned_long_long(
                upgrade->prepared_monotonic,
                WLS_UPGRADE_ACTIVATION_MILLISECONDS,
                &expected_activation_monotonic
            ) != 0
            || upgrade->activation_deadline_monotonic
                != expected_activation_monotonic
            || wls_checked_add_unsigned_long_long(
                upgrade->prepared_monotonic,
                WLS_UPGRADE_TOTAL_MILLISECONDS,
                &expected_total_monotonic
            ) != 0
            || upgrade->total_deadline_monotonic != expected_total_monotonic) {
            goto cleanup;
        }
    } else {
        fields = sscanf(
            (const char *)intent,
            "WLS-UPGRADE/1\n"
            "host_id=%32[0-9a-f]\n"
            "from=%1[AB]\n"
            "to=%1[AB]\n"
            "prepared_at=%lld\n"
            "deadline=%lld\n"
            "runtime_generation=%64[0-9a-f]\n"
            "nonce=%32[0-9a-f]\n"
            "signature=%64[0-9a-f]\n%n",
            host, from, to,
            &upgrade->prepared_at, &upgrade->deadline,
            upgrade->runtime_generation, nonce, signature_hex, &consumed
        );
        upgrade->legacy_protocol = 1;
        if (fields != 8) goto cleanup;
    }
    signature_line = strstr((char *)intent, "signature=");
    if (consumed != (int)intent_length || from[0] == to[0]
        || upgrade->prepared_at < 1
        || wls_checked_add_long_long(
            upgrade->prepared_at,
            WLS_UPGRADE_ACTIVATION_SECONDS,
            &expected_activation_deadline
        ) != 0
        || upgrade->deadline != expected_activation_deadline
        || !wls_is_hex(host, 32U) || host_length != 32U
        || sodium_memcmp(host, host_text, 32U) != 0
        || !wls_is_hex(upgrade->runtime_generation, 64U)
        || !wls_is_hex(nonce, 32U)
        || !wls_is_hex(signature_hex, 64U)
        || signature_line == NULL
        || sodium_hex2bin(
            key,
            sizeof(key),
            (const char *)token,
            token_length,
            NULL,
            &decoded,
            NULL
        ) != 0
        || decoded != sizeof(key)
        || sodium_hex2bin(
            actual,
            sizeof(actual),
            signature_hex,
            64U,
            NULL,
            &decoded,
            NULL
        ) != 0
        || decoded != sizeof(actual)
        || crypto_auth_hmacsha256(
            expected,
            intent,
            (unsigned long long)(signature_line - (char *)intent),
            key
        ) != 0
        || sodium_memcmp(expected, actual, sizeof(expected)) != 0) {
        goto cleanup;
    }
    if (crypto_hash_sha256(
            intent_digest,
            intent,
            (unsigned long long)intent_length
        ) != 0
        || sodium_bin2hex(
            upgrade->intent_sha256,
            sizeof(upgrade->intent_sha256),
            intent_digest,
            sizeof(intent_digest)
        ) == NULL) {
        goto cleanup;
    }
    upgrade->present = 1;
    upgrade->from = (wchar_t)from[0];
    upgrade->to = (wchar_t)to[0];
    memcpy(upgrade->nonce, nonce, sizeof(upgrade->nonce));
    result = 1;
cleanup:
    sodium_memzero(key, sizeof(key));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
    sodium_memzero(intent_digest, sizeof(intent_digest));
    if (token != NULL) {
        sodium_memzero(token, token_length);
        HeapFree(GetProcessHeap(), 0U, token);
    }
    if (intent != NULL) HeapFree(GetProcessHeap(), 0U, intent);
    if (host_text != NULL) HeapFree(GetProcessHeap(), 0U, host_text);
    return result;
}

static int wls_upgrade_healthy(
    const wchar_t *home,
    const struct wls_upgrade *upgrade,
    const struct wls_upgrade_state *state,
    const char *boot_id
) {
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char digest[65];
    char nonce[33];
    char from[2];
    char to[2];
    char runtime[65];
    char marker_boot[65];
    unsigned long long observation_deadline = 0ULL;
    unsigned long long healthy_at = 0ULL;
    unsigned long long monotonic_now = 0ULL;
    int consumed = 0;
    int result = 0;
    if (wls_protocol_monotonic_milliseconds(&monotonic_now) == 0
        && wls_join(path, WLS_PATH_CHARS, home, L"trust\\upgrade-healthy") == 0
        && wls_read_file(path, 512U, &contents, &length) == 0
        && sscanf(
            (const char *)contents,
            "WLS-UPGRADE-HEALTHY/2\n"
            "intent_sha256=%64[0-9a-f]\n"
            "intent_nonce=%32[0-9a-f]\n"
            "from=%1[AB]\n"
            "to=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "boot_id=%64[0-9A-Za-z-]\n"
            "observation_deadline_monotonic_ms=%llu\n"
            "healthy_monotonic_ms=%llu\n%n",
            digest,
            nonce,
            from,
            to,
            runtime,
            marker_boot,
            &observation_deadline,
            &healthy_at,
            &consumed
        ) == 8
        && consumed == (int)length
        && state != NULL
        && strcmp(state->phase, "OBSERVING") == 0
        && strcmp(digest, upgrade->intent_sha256) == 0
        && strcmp(nonce, upgrade->nonce) == 0
        && (wchar_t)from[0] == upgrade->from
        && (wchar_t)to[0] == upgrade->to
        && strcmp(runtime, upgrade->runtime_generation) == 0
        && strcmp(marker_boot, boot_id) == 0
        && observation_deadline == state->observation_deadline
        && observation_deadline > 0ULL
        && healthy_at >= observation_deadline
        && healthy_at <= upgrade->total_deadline_monotonic
        && healthy_at <= monotonic_now) {
        result = 1;
    }
    if (contents != NULL) HeapFree(GetProcessHeap(), 0U, contents);
    return result;
}

static int wls_upgrade_observation_deadline(
    const wchar_t *home,
    const struct wls_upgrade *upgrade,
    const struct wls_upgrade_state *state,
    const char *boot_id,
    unsigned long long *started_out,
    unsigned long long *deadline
) {
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char digest[65];
    char nonce[33];
    char from[2];
    char to[2];
    char runtime[65];
    char marker_boot[65];
    unsigned long long started = 0ULL;
    unsigned long long parsed_deadline = 0ULL;
    unsigned long long expected_deadline = 0ULL;
    unsigned long long monotonic_now = 0ULL;
    int consumed = 0;
    int result = 0;
    if (deadline == NULL || started_out == NULL
        || wls_protocol_monotonic_milliseconds(&monotonic_now) != 0
        || wls_join(path, WLS_PATH_CHARS, home, L"trust\\upgrade-observing") != 0
        || wls_read_file(path, 512U, &contents, &length) != 0) {
        return 0;
    }
    if (sscanf(
        (const char *)contents,
        "WLS-UPGRADE-OBSERVING/2\n"
        "intent_sha256=%64[0-9a-f]\n"
        "intent_nonce=%32[0-9a-f]\n"
        "from=%1[AB]\n"
        "to=%1[AB]\n"
        "runtime_generation=%64[0-9a-f]\n"
        "boot_id=%64[0-9A-Za-z-]\n"
        "started_monotonic_ms=%llu\n"
        "deadline_monotonic_ms=%llu\n%n",
        digest,
        nonce,
        from,
        to,
        runtime,
        marker_boot,
        &started,
        &parsed_deadline,
        &consumed
    ) == 8
        && consumed == (int)length
        && state != NULL
        && (strcmp(state->phase, "PREPARED") == 0
            || strcmp(state->phase, "OBSERVING") == 0)
        && strcmp(digest, upgrade->intent_sha256) == 0
        && strcmp(nonce, upgrade->nonce) == 0
        && (wchar_t)from[0] == upgrade->from
        && (wchar_t)to[0] == upgrade->to
        && strcmp(runtime, upgrade->runtime_generation) == 0
        && strcmp(marker_boot, boot_id) == 0
        && started > 0ULL
        && wls_checked_add_unsigned_long_long(
            started,
            WLS_UPGRADE_OBSERVATION_MILLISECONDS,
            &expected_deadline
        ) == 0
        && parsed_deadline == expected_deadline
        && started <= upgrade->activation_deadline_monotonic
        && started <= monotonic_now) {
        *started_out = started;
        *deadline = parsed_deadline;
        result = 1;
    }
    HeapFree(GetProcessHeap(), 0U, contents);
    return result;
}

static int wls_boot_id(char output[65])
{
    static const char prefix[] = "wls-gateway-host-boot/1|";
    HMODULE ntdll = GetModuleHandleW(L"ntdll.dll");
    wls_nt_query_system_information_fn query;
    struct wls_system_timeofday_information information;
    char platform_token[65];
    char canonical[sizeof(prefix) + sizeof(platform_token)];
    unsigned char digest[crypto_hash_sha256_BYTES];
    NTSTATUS status;
    int length;
    if (ntdll == NULL) return 1;
    query = (wls_nt_query_system_information_fn)(void *)GetProcAddress(
        ntdll,
        "NtQuerySystemInformation"
    );
    if (query == NULL) return 1;
    ZeroMemory(&information, sizeof(information));
    status = query(
        (SYSTEM_INFORMATION_CLASS)3,
        &information,
        (ULONG)sizeof(information),
        NULL
    );
    if (status < 0) return 1;
    length = _snprintf_s(
        platform_token,
        sizeof(platform_token),
        _TRUNCATE,
        "windows-%016llx",
        (unsigned long long)information.boot_time.QuadPart
    );
    if (length <= 0) return 1;
    length = _snprintf_s(
        canonical,
        sizeof(canonical),
        _TRUNCATE,
        "%s%s",
        prefix,
        platform_token
    );
    if (length <= 0
        || crypto_hash_sha256(
            digest,
            (const unsigned char *)canonical,
            (unsigned long long)length
        ) != 0
        || sodium_bin2hex(output, 65U, digest, sizeof(digest)) == NULL
        || strlen(output) != 64U) {
        sodium_memzero(digest, sizeof(digest));
        sodium_memzero(canonical, sizeof(canonical));
        return 1;
    }
    sodium_memzero(digest, sizeof(digest));
    sodium_memzero(canonical, sizeof(canonical));
    return 0;
}

static int wls_sha256_text(
    const unsigned char *contents,
    size_t length,
    char output[65]
) {
    unsigned char digest[crypto_hash_sha256_BYTES];
    int result = 1;
    if (contents != NULL
        && crypto_hash_sha256(
            digest,
            contents,
            (unsigned long long)length
        ) == 0
        && sodium_bin2hex(output, 65U, digest, sizeof(digest)) != NULL
        && wls_is_hex(output, 64U)) {
        result = 0;
    }
    SecureZeroMemory(digest, sizeof(digest));
    return result;
}

static int wls_all_zero_hex(const char *value)
{
    size_t index;
    if (!wls_is_hex(value, 64U)) return 0;
    for (index = 0U; index < 64U; index++) {
        if (value[index] != '0') return 0;
    }
    return 1;
}

static int wls_initialize_service_generation(void)
{
    static const char canonical[] =
        "wls-platform-service/1\0windows-job\0com.weline.wls-gateway-v2";
    unsigned char random_generation[32];
    if (wls_sha256_text(
            (const unsigned char *)canonical,
            sizeof(canonical) - 1U,
            wls_service_id
        ) != 0) return 1;
    randombytes_buf(random_generation, sizeof(random_generation));
    if (sodium_bin2hex(
            wls_launcher_generation,
            sizeof(wls_launcher_generation),
            random_generation,
            sizeof(random_generation)
        ) == NULL
        || !wls_is_hex(wls_launcher_generation, 64U)) {
        SecureZeroMemory(random_generation, sizeof(random_generation));
        return 1;
    }
    SecureZeroMemory(random_generation, sizeof(random_generation));
    return 0;
}

static int wls_recovery_acl_safe(HANDLE file, int diagnostic)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PSID owner = NULL;
    PACL dacl = NULL;
    unsigned char system_buffer[SECURITY_MAX_SID_SIZE];
    unsigned char administrators_buffer[SECURITY_MAX_SID_SIZE];
    unsigned char users_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD administrators_length = sizeof(administrators_buffer);
    DWORD users_length = sizeof(users_buffer);
    ACL_SIZE_INFORMATION information;
    SECURITY_DESCRIPTOR_CONTROL control = 0U;
    DWORD revision = 0U;
    DWORD index;
    unsigned int system_count = 0U;
    unsigned int administrators_count = 0U;
    unsigned int users_count = 0U;
    DWORD forbidden_user_write = GENERIC_WRITE | GENERIC_ALL | DELETE
        | WRITE_DAC | WRITE_OWNER | FILE_WRITE_DATA | FILE_APPEND_DATA
        | FILE_WRITE_EA | FILE_WRITE_ATTRIBUTES | FILE_DELETE_CHILD;
    int result = 1;
    ZeroMemory(&information, sizeof(information));
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    SecureZeroMemory(users_buffer, sizeof(users_buffer));
    if (file == NULL || file == INVALID_HANDLE_VALUE
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_buffer, &system_length
        )
        || !CreateWellKnownSid(
            WinBuiltinAdministratorsSid,
            NULL,
            administrators_buffer,
            &administrators_length
        )
        || !CreateWellKnownSid(
            WinBuiltinUsersSid, NULL, users_buffer, &users_length
        )
        || GetSecurityInfo(
            file,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &owner,
            NULL,
            &dacl,
            NULL,
            &descriptor
        ) != ERROR_SUCCESS
        || descriptor == NULL || owner == NULL || dacl == NULL
        || (!EqualSid(owner, system_buffer)
            && !EqualSid(owner, administrators_buffer))
        || !GetSecurityDescriptorControl(descriptor, &control, &revision)
        || (control & SE_DACL_PROTECTED) == 0U
        || !GetAclInformation(
            dacl, &information, sizeof(information), AclSizeInformation
        )) goto cleanup;
    for (index = 0U; index < information.AceCount; index++) {
        ACCESS_ALLOWED_ACE *ace = NULL;
        PSID sid;
        if (!GetAce(dacl, index, (LPVOID *)&ace)
            || ace == NULL
            || ace->Header.AceType != ACCESS_ALLOWED_ACE_TYPE
            || (ace->Header.AceFlags & INHERITED_ACE) != 0U) goto cleanup;
        sid = (PSID)&ace->SidStart;
        if (EqualSid(sid, system_buffer)) {
            if (++system_count != 1U
                || !(ace->Mask == GENERIC_ALL
                    || ace->Mask == FILE_ALL_ACCESS)) {
                goto cleanup;
            }
        } else if (EqualSid(sid, administrators_buffer)) {
            if (++administrators_count != 1U
                || !(ace->Mask == GENERIC_ALL
                    || ace->Mask == FILE_ALL_ACCESS)) {
                goto cleanup;
            }
        } else if (diagnostic && EqualSid(sid, users_buffer)) {
            if (++users_count != 1U
                || (ace->Mask & forbidden_user_write) != 0U
                || !(ace->Mask == GENERIC_READ
                    || ace->Mask == FILE_GENERIC_READ
                    || ace->Mask == (FILE_GENERIC_READ & ~SYNCHRONIZE))) {
                goto cleanup;
            }
        } else {
            goto cleanup;
        }
    }
    if (system_count == 1U && administrators_count == 1U
        && users_count == (diagnostic ? 1U : 0U)) result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    SecureZeroMemory(users_buffer, sizeof(users_buffer));
    return result;
}

/* 0=read, 2=missing, 1=unsafe/error. */
static int wls_recovery_read_secure_with_acl(
    const wchar_t *path,
    int diagnostic,
    int service_readable,
    char *contents,
    size_t capacity,
    size_t *length
) {
    HANDLE file = INVALID_HANDLE_VALUE;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    BY_HANDLE_FILE_INFORMATION before;
    BY_HANDLE_FILE_INFORMATION after;
    LARGE_INTEGER size;
    DWORD used = 0U;
    DWORD amount = 0U;
    DWORD error;
    PSID service_sid = NULL;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    ZeroMemory(&before, sizeof(before));
    ZeroMemory(&after, sizeof(after));
    if (path == NULL || contents == NULL || capacity < 2U || length == NULL) {
        return 1;
    }
    file = CreateFileW(
        path,
        GENERIC_READ | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE) {
        error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
            ? 2 : 1;
    }
    if (!GetFileInformationByHandleEx(
            file, FileAttributeTagInfo, &attributes, sizeof(attributes)
        )
        || !GetFileInformationByHandle(file, &before)
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
        || before.nNumberOfLinks != 1U) goto cleanup;
    if (service_readable) {
        if (diagnostic
            || wls_launcher_gateway_service_sid(&service_sid) != 0
            || wls_launcher_slot_acl_valid(
                file, 0, service_sid
            ) != 0) goto cleanup;
    } else if (wls_recovery_acl_safe(file, diagnostic) != 0) {
        goto cleanup;
    }
    size.HighPart = (LONG)before.nFileSizeHigh;
    size.LowPart = before.nFileSizeLow;
    if (size.QuadPart <= 0
        || (unsigned long long)size.QuadPart >= (unsigned long long)capacity
        || (unsigned long long)size.QuadPart > MAXDWORD) goto cleanup;
    while (used < (DWORD)size.QuadPart) {
        if (!ReadFile(
                file,
                contents + used,
                (DWORD)size.QuadPart - used,
                &amount,
                NULL
            ) || amount == 0U) goto cleanup;
        used += amount;
    }
    if (!GetFileInformationByHandle(file, &after)
        || before.dwVolumeSerialNumber != after.dwVolumeSerialNumber
        || before.nFileIndexHigh != after.nFileIndexHigh
        || before.nFileIndexLow != after.nFileIndexLow
        || before.nFileSizeHigh != after.nFileSizeHigh
        || before.nFileSizeLow != after.nFileSizeLow
        || before.nNumberOfLinks != after.nNumberOfLinks
        || CompareFileTime(
            &before.ftLastWriteTime, &after.ftLastWriteTime
        ) != 0
        || memchr(contents, '\0', used) != NULL) goto cleanup;
    contents[used] = '\0';
    *length = (size_t)used;
    result = 0;
cleanup:
    CloseHandle(file);
    if (service_sid != NULL) LocalFree(service_sid);
    if (result != 0) {
        SecureZeroMemory(contents, capacity);
        *length = 0U;
    }
    return result;
}

static int wls_recovery_read_secure(
    const wchar_t *path,
    int diagnostic,
    char *contents,
    size_t capacity,
    size_t *length
) {
    return wls_recovery_read_secure_with_acl(
        path, diagnostic, 0, contents, capacity, length
    );
}

static int wls_recovery_read_controller_trust_file(
    const wchar_t *path,
    char *contents,
    size_t capacity,
    size_t *length
) {
    return wls_recovery_read_secure_with_acl(
        path, 0, 1, contents, capacity, length
    );
}

static int wls_recovery_target_safe(const wchar_t *path, int diagnostic)
{
    char probe[WLS_RECOVERY_LEDGER_CAPACITY];
    size_t length = 0U;
    int result = wls_recovery_read_secure(
        path, diagnostic, probe, sizeof(probe), &length
    );
    SecureZeroMemory(probe, sizeof(probe));
    return result == 2 ? 0 : (result == 0 ? 0 : 1);
}

static int wls_recovery_write_secure(
    const wchar_t *path,
    const char *contents,
    int diagnostic
) {
    if (wls_recovery_target_safe(path, diagnostic) != 0
        || (diagnostic
            ? wls_atomic_diagnostic_text(path, contents)
            : wls_atomic_system_text(path, contents)) != 0
        || wls_recovery_target_safe(path, diagnostic) != 0) return 1;
    return 0;
}

static int wls_recovery_wall_seconds(long long *seconds)
{
    FILETIME file_time;
    ULARGE_INTEGER ticks;
    static const unsigned long long unix_epoch = 116444736000000000ULL;
    if (seconds == NULL) return 1;
    GetSystemTimeAsFileTime(&file_time);
    ticks.LowPart = file_time.dwLowDateTime;
    ticks.HighPart = file_time.dwHighDateTime;
    if (ticks.QuadPart <= unix_epoch) return 1;
    *seconds = (long long)((ticks.QuadPart - unix_epoch) / 10000000ULL);
    return *seconds > 0LL ? 0 : 1;
}

static int wls_recovery_launcher_identity(
    const wchar_t *home,
    char identity[65]
);

static int wls_rebootstrap_wide_ascii_prefix_equal(
    const wchar_t *value,
    size_t value_length,
    const wchar_t *prefix
) {
    size_t index;
    size_t prefix_length;
    if (value == NULL || prefix == NULL) return 0;
    prefix_length = wcslen(prefix);
    if (value_length < prefix_length) return 0;
    for (index = 0U; index < prefix_length; index++) {
        wchar_t left = value[index];
        wchar_t right = prefix[index];
        if (left >= L'A' && left <= L'Z') left += L'a' - L'A';
        if (right >= L'A' && right <= L'Z') right += L'a' - L'A';
        if (left != right) return 0;
    }
    return 1;
}

static int wls_rebootstrap_reserved_recovery_name(
    const wchar_t *leaf,
    size_t length
) {
    static const wchar_t target[] = L"rebootstrap.transaction";
    static const wchar_t backup_prefix[] = L"wls-backup";
    static const wchar_t staging_prefix[] = L"tmp";
    size_t target_length = (sizeof(target) / sizeof(target[0])) - 1U;
    const wchar_t *suffix;
    size_t suffix_length;
    if (leaf == NULL
        || !wls_rebootstrap_wide_ascii_prefix_equal(
            leaf, length, target
        )) return 0;
    if (length == target_length) {
        /* The exact target may have appeared after the caller's first
         * not-found read. Directory observation is recovery evidence too. */
        return 1;
    }
    if (leaf[target_length] != L'.') return 0;
    suffix = leaf + target_length + 1U;
    suffix_length = length - target_length - 1U;
    return wls_rebootstrap_wide_ascii_prefix_equal(
            suffix, suffix_length, backup_prefix
        )
        || wls_rebootstrap_wide_ascii_prefix_equal(
            suffix, suffix_length, staging_prefix
        );
}

static int wls_rebootstrap_reserved_recovery_name_self_test(void)
{
    static const wchar_t exact[] = L"rebootstrap.transaction";
    static const wchar_t alias[] = L"REBOOTSTRAP.TRANSACTION";
    static const wchar_t backup[] =
        L"rebootstrap.transaction.wls-backup-0123456789abcdef";
    static const wchar_t staging[] =
        L"rebootstrap.transaction.tmp-0123456789abcdef01234567";
    static const wchar_t unrelated[] = L"unrelated.transaction";
    return wls_rebootstrap_reserved_recovery_name(
            exact, (sizeof(exact) / sizeof(exact[0])) - 1U
        ) == 1
        && wls_rebootstrap_reserved_recovery_name(
            alias, (sizeof(alias) / sizeof(alias[0])) - 1U
        ) == 1
        && wls_rebootstrap_reserved_recovery_name(
            backup, (sizeof(backup) / sizeof(backup[0])) - 1U
        ) == 1
        && wls_rebootstrap_reserved_recovery_name(
            staging, (sizeof(staging) / sizeof(staging[0])) - 1U
        ) == 1
        && wls_rebootstrap_reserved_recovery_name(
            unrelated, (sizeof(unrelated) / sizeof(unrelated[0])) - 1U
        ) == 0
        ? 0
        : 1;
}

/* 0=the exact target and every reserved recovery spelling are absent;
 * 1=present, unsafe, raced or outside the fixed immediate-entry budget. */
static int wls_rebootstrap_recovery_artifacts_absent(const wchar_t *home)
{
    wchar_t trust_path[WLS_PATH_CHARS];
    unsigned char buffer[16U * 1024U];
    HANDLE directory = INVALID_HANDLE_VALUE;
    HANDLE reopened = INVALID_HANDLE_VALUE;
    FILE_ATTRIBUTE_TAG_INFO attributes_before;
    FILE_ATTRIBUTE_TAG_INFO attributes_after;
    FILE_ATTRIBUTE_TAG_INFO attributes_reopened;
    BY_HANDLE_FILE_INFORMATION identity_before;
    BY_HANDLE_FILE_INFORMATION identity_after;
    BY_HANDLE_FILE_INFORMATION identity_reopened;
    PSID service_sid = NULL;
    unsigned int visited = 0U;
    int restart = 1;
    int result = 1;
    ZeroMemory(buffer, sizeof(buffer));
    ZeroMemory(&attributes_before, sizeof(attributes_before));
    ZeroMemory(&attributes_after, sizeof(attributes_after));
    ZeroMemory(&attributes_reopened, sizeof(attributes_reopened));
    ZeroMemory(&identity_before, sizeof(identity_before));
    ZeroMemory(&identity_after, sizeof(identity_after));
    ZeroMemory(&identity_reopened, sizeof(identity_reopened));
    if (home == NULL
        || wls_join(trust_path, WLS_PATH_CHARS, home, L"trust") != 0) {
        return 1;
    }
    directory = CreateFileW(
        trust_path,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | FILE_READ_ATTRIBUTES
            | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (directory == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            directory,
            FileAttributeTagInfo,
            &attributes_before,
            sizeof(attributes_before)
        )
        || !GetFileInformationByHandle(directory, &identity_before)
        || (attributes_before.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) == 0U
        || (attributes_before.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || wls_launcher_gateway_service_sid(&service_sid) != 0
        || wls_launcher_slot_acl_valid(directory, 1, service_sid) != 0) {
        goto cleanup;
    }
    for (;;) {
        FILE_ID_BOTH_DIR_INFO *entry;
        size_t offset = 0U;
        ZeroMemory(buffer, sizeof(buffer));
        if (!GetFileInformationByHandleEx(
                directory,
                restart
                    ? FileIdBothDirectoryRestartInfo
                    : FileIdBothDirectoryInfo,
                buffer,
                sizeof(buffer)
            )) {
            DWORD error = GetLastError();
            if (error == ERROR_NO_MORE_FILES) break;
            goto cleanup;
        }
        restart = 0;
        entry = (FILE_ID_BOTH_DIR_INFO *)(void *)buffer;
        for (;;) {
            size_t minimum = FIELD_OFFSET(FILE_ID_BOTH_DIR_INFO, FileName);
            size_t characters;
            if (offset > sizeof(buffer) - minimum
                || entry->FileNameLength == 0U
                || (entry->FileNameLength % sizeof(wchar_t)) != 0U
                || (size_t)entry->FileNameLength > sizeof(buffer) - offset - minimum) {
                goto cleanup;
            }
            characters = entry->FileNameLength / sizeof(wchar_t);
            if (characters > 255U) goto cleanup;
            if (!((characters == 1U && entry->FileName[0] == L'.')
                    || (characters == 2U
                        && entry->FileName[0] == L'.'
                        && entry->FileName[1] == L'.'))) {
                if (++visited
                        > WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES
                    || wls_rebootstrap_reserved_recovery_name(
                        entry->FileName, characters
                    )) goto cleanup;
            }
            if (entry->NextEntryOffset == 0U) break;
            if ((size_t)entry->NextEntryOffset < minimum
                || (size_t)entry->NextEntryOffset > sizeof(buffer) - offset) {
                goto cleanup;
            }
            offset += (size_t)entry->NextEntryOffset;
            entry = (FILE_ID_BOTH_DIR_INFO *)(void *)(buffer + offset);
        }
    }
    if (!GetFileInformationByHandleEx(
            directory,
            FileAttributeTagInfo,
            &attributes_after,
            sizeof(attributes_after)
        )
        || !GetFileInformationByHandle(directory, &identity_after)
        || attributes_before.FileAttributes != attributes_after.FileAttributes
        || !wls_launcher_file_identity_equal(
            &identity_before, &identity_after
        )
        || CompareFileTime(
            &identity_before.ftLastWriteTime,
            &identity_after.ftLastWriteTime
        ) != 0) goto cleanup;
    reopened = CreateFileW(
        trust_path,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | FILE_READ_ATTRIBUTES
            | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (reopened == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            reopened,
            FileAttributeTagInfo,
            &attributes_reopened,
            sizeof(attributes_reopened)
        )
        || !GetFileInformationByHandle(reopened, &identity_reopened)
        || attributes_after.FileAttributes != attributes_reopened.FileAttributes
        || (attributes_reopened.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || !wls_launcher_file_identity_equal(
            &identity_after, &identity_reopened
        )
        || CompareFileTime(
            &identity_after.ftLastWriteTime,
            &identity_reopened.ftLastWriteTime
        ) != 0
        || wls_launcher_slot_acl_valid(reopened, 1, service_sid) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    SecureZeroMemory(buffer, sizeof(buffer));
    if (service_sid != NULL) LocalFree(service_sid);
    if (reopened != INVALID_HANDLE_VALUE) CloseHandle(reopened);
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    return result;
}

/* A rebootstrap journal is a fail-closed maintenance fence unless an exact
 * administrator-HMAC authorization binds its current/next durable digest to
 * this launcher slot, runtime generation and stable-launcher identity.
 * START_AUTHORIZED and ROLLBACK_START_AUTHORIZED are therefore admitted only
 * through the signed forward/rollback marker, never by phase text alone. */
static int wls_recovery_maintenance_pending(
    const wchar_t *home,
    wchar_t active_slot,
    const char *runtime_generation
) {
    wchar_t journal_path[WLS_PATH_CHARS];
    wchar_t authorization_path[WLS_PATH_CHARS];
    wchar_t token_path[WLS_PATH_CHARS];
    wchar_t host_path[WLS_PATH_CHARS];
    char authorization[WLS_REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES + 1U];
    char token[66];
    char host_text[34];
    char stable_launcher[65];
    char marker_host[33];
    char nonce[33];
    char purpose[9];
    char primary[65];
    char secondary[65];
    char marker_slot[2];
    char marker_generation[65];
    char marker_launcher[65];
    char signature_hex[65];
    char journal_digest[65];
    char authorization_digest[65];
    char current_journal_digest[65];
    char current_authorization_digest[65];
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char expected[crypto_auth_hmacsha256_BYTES];
    unsigned char actual[crypto_auth_hmacsha256_BYTES];
    char *journal = NULL;
    char *signature_line;
    size_t journal_length = 0U;
    size_t authorization_length = 0U;
    size_t token_length = 0U;
    size_t host_length = 0U;
    size_t decoded = 0U;
    int consumed = 0;
    int fields;
    int read_status;
    int result = 1;
    SecureZeroMemory(key, sizeof(key));
    SecureZeroMemory(expected, sizeof(expected));
    SecureZeroMemory(actual, sizeof(actual));
    if (home == NULL || runtime_generation == NULL
        || (active_slot != L'A' && active_slot != L'B')
        || !wls_is_hex(runtime_generation, 64U)
        || wls_join(
            journal_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\rebootstrap.transaction"
        ) != 0
        || wls_join(
            authorization_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\rebootstrap-start.authorization"
        ) != 0
        || wls_join(
            token_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\admin.token"
        ) != 0
        || wls_join(
            host_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\host-id"
        ) != 0) goto cleanup;
    journal = HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES + 1U
    );
    if (journal == NULL) goto cleanup;
    read_status = wls_recovery_read_secure(
        journal_path,
        0,
        journal,
        WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES + 1U,
        &journal_length
    );
    if (read_status == 2) {
        /* A stale marker has no authority only after the target and every
         * atomic-write recovery spelling are proven absent. */
        result = wls_rebootstrap_recovery_artifacts_absent(home) == 0 ? 0 : 1;
        goto cleanup;
    }
    if (read_status != 0
        || wls_recovery_read_secure(
            authorization_path,
            0,
            authorization,
            sizeof(authorization),
            &authorization_length
        ) != 0
        || wls_recovery_read_controller_trust_file(
            token_path, token, sizeof(token), &token_length
        ) != 0
        || wls_recovery_read_controller_trust_file(
            host_path, host_text, sizeof(host_text), &host_length
        ) != 0
        || wls_recovery_launcher_identity(home, stable_launcher) != 0) {
        goto cleanup;
    }
    if (!((token_length == 64U)
            || (token_length == 65U && token[64] == '\n'))
        || !((host_length == 32U)
            || (host_length == 33U && host_text[32] == '\n'))) goto cleanup;
    token[64] = '\0';
    host_text[32] = '\0';
    if (!wls_is_hex(token, 64U)
        || !wls_is_hex(host_text, 32U)
        || wls_sha256_text(
            (const unsigned char *)journal,
            journal_length,
            journal_digest
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)authorization,
            authorization_length,
            authorization_digest
        ) != 0) goto cleanup;
    fields = sscanf(
        authorization,
        "WLS-REBOOTSTRAP-START/1\n"
        "host_id=%32[0-9a-f]\n"
        "nonce=%32[0-9a-f]\n"
        "purpose=%8[a-z]\n"
        "journal_sha256_primary=%64[0-9a-f]\n"
        "journal_sha256_secondary=%64[0-9a-f]\n"
        "active_slot=%1[AB]\n"
        "runtime_generation=%64[0-9a-f]\n"
        "stable_launcher_sha256=%64[0-9a-f]\n"
        "signature=%64[0-9a-f]\n%n",
        marker_host,
        nonce,
        purpose,
        primary,
        secondary,
        marker_slot,
        marker_generation,
        marker_launcher,
        signature_hex,
        &consumed
    );
    signature_line = strstr(authorization, "signature=");
    if (fields != 9 || consumed != (int)authorization_length
        || signature_line == NULL
        || !wls_is_hex(marker_host, 32U)
        || !wls_is_hex(nonce, 32U)
        || (strcmp(purpose, "forward") != 0
            && strcmp(purpose, "rollback") != 0)
        || !wls_is_hex(primary, 64U)
        || !wls_is_hex(secondary, 64U)
        || !wls_is_hex(marker_generation, 64U)
        || !wls_is_hex(marker_launcher, 64U)
        || !wls_is_hex(signature_hex, 64U)
        || strcmp(marker_host, host_text) != 0
        || marker_slot[0] != (char)active_slot || marker_slot[1] != '\0'
        || strcmp(marker_generation, runtime_generation) != 0
        || strcmp(marker_launcher, stable_launcher) != 0
        || (sodium_memcmp(journal_digest, primary, 64U) != 0
            && sodium_memcmp(journal_digest, secondary, 64U) != 0)
        || sodium_hex2bin(
            key, sizeof(key), token, 64U, NULL, &decoded, NULL
        ) != 0 || decoded != sizeof(key)
        || sodium_hex2bin(
            actual,
            sizeof(actual),
            signature_hex,
            64U,
            NULL,
            &decoded,
            NULL
        ) != 0 || decoded != sizeof(actual)
        || crypto_auth_hmacsha256(
            expected,
            (const unsigned char *)authorization,
            (unsigned long long)(signature_line - authorization),
            key
        ) != 0
        || sodium_memcmp(expected, actual, sizeof(expected)) != 0) {
        goto cleanup;
    }
    /* Re-read both independently replaced files after authentication. This
     * rejects a revocation or phase transition that raced the first snapshot
     * instead of authorizing an already superseded journal/marker pair. */
    journal_length = 0U;
    authorization_length = 0U;
    if (wls_recovery_read_secure(
            journal_path,
            0,
            journal,
            WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES + 1U,
            &journal_length
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)journal,
            journal_length,
            current_journal_digest
        ) != 0
        || sodium_memcmp(
            journal_digest, current_journal_digest, 64U
        ) != 0
        || wls_recovery_read_secure(
            authorization_path,
            0,
            authorization,
            sizeof(authorization),
            &authorization_length
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)authorization,
            authorization_length,
            current_authorization_digest
        ) != 0
        || sodium_memcmp(
            authorization_digest, current_authorization_digest, 64U
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (journal != NULL) {
        SecureZeroMemory(
            journal, WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES + 1U
        );
        HeapFree(GetProcessHeap(), 0U, journal);
    }
    SecureZeroMemory(authorization, sizeof(authorization));
    SecureZeroMemory(token, sizeof(token));
    SecureZeroMemory(host_text, sizeof(host_text));
    SecureZeroMemory(stable_launcher, sizeof(stable_launcher));
    SecureZeroMemory(key, sizeof(key));
    SecureZeroMemory(expected, sizeof(expected));
    SecureZeroMemory(actual, sizeof(actual));
    return result;
}

static int wls_recovery_launcher_identity(
    const wchar_t *home,
    char identity[65]
) {
    wchar_t path[WLS_PATH_CHARS];
    char contents[66];
    size_t length = 0U;
    if (wls_join(
            path,
            WLS_PATH_CHARS,
            home,
            L"trust\\stable-launcher.sha256"
        ) != 0
        || wls_recovery_read_secure(
            path, 0, contents, sizeof(contents), &length
        ) != 0
        || !((length == 64U) || (length == 65U && contents[64] == '\n'))) {
        SecureZeroMemory(contents, sizeof(contents));
        return 1;
    }
    contents[64] = '\0';
    if (!wls_recovery_hex(contents, 64U)) {
        SecureZeroMemory(contents, sizeof(contents));
        return 1;
    }
    memcpy(identity, contents, 65U);
    SecureZeroMemory(contents, sizeof(contents));
    return 0;
}

static int wls_recovery_launcher_generation(
    const char *launcher_identity,
    char generation[65]
) {
    char canonical[256];
    int length = _snprintf_s(
        canonical,
        sizeof(canonical),
        _TRUNCATE,
        "wls-launcher-recovery-generation/1%c%s%c%s",
        '\0', wls_service_id, '\0', launcher_identity
    );
    if (length <= 0
        || wls_sha256_text(
            (const unsigned char *)canonical,
            (size_t)length,
            generation
        ) != 0) {
        SecureZeroMemory(canonical, sizeof(canonical));
        return 1;
    }
    SecureZeroMemory(canonical, sizeof(canonical));
    return 0;
}

static int wls_recovery_publish(
    struct wls_windows_recovery_context *context,
    const char *stage_override,
    const char *reason_override
) {
    char ledger[WLS_RECOVERY_LEDGER_CAPACITY];
    char status[WLS_RECOVERY_STATUS_CAPACITY];
    int ledger_length;
    int status_length;
    if (context == NULL) return 1;
    ledger_length = wls_recovery_format(
        &context->state, ledger, sizeof(ledger)
    );
    status_length = wls_recovery_status_format(
        &context->state,
        stage_override,
        reason_override,
        status,
        sizeof(status)
    );
    if (ledger_length <= 0 || status_length <= 0
        || wls_recovery_write_secure(
            context->ledger_path, ledger, 0
        ) != 0
        || wls_recovery_write_secure(
            context->status_path, status, 1
        ) != 0) {
        SecureZeroMemory(ledger, sizeof(ledger));
        SecureZeroMemory(status, sizeof(status));
        return 1;
    }
    SecureZeroMemory(ledger, sizeof(ledger));
    SecureZeroMemory(status, sizeof(status));
    return 0;
}

/* 0=attempt marker committed, 1=controlled stop, 2=reload, 3=unsafe. */
static int wls_recovery_prepare_attempt(
    const wchar_t *home,
    const wchar_t *run_directory,
    wchar_t active_slot,
    const char *runtime_generation,
    struct wls_windows_recovery_context *context
) {
    char launcher_identity[65];
    char launcher_generation[65];
    char boot_id[65];
    char encoded[WLS_RECOVERY_LEDGER_CAPACITY];
    char attempt_id[33];
    unsigned char attempt_bytes[16];
    size_t encoded_length = 0U;
    unsigned long long now_monotonic;
    unsigned long long last_checkpoint = 0ULL;
    long long now_wall;
    int read_status;
    if (home == NULL || run_directory == NULL || runtime_generation == NULL
        || context == NULL || (active_slot != L'A' && active_slot != L'B')) {
        return 3;
    }
    ZeroMemory(context, sizeof(*context));
    if (wls_join(
            context->ledger_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\launcher-recovery.ledger"
        ) != 0
        || wls_join(
            context->status_path,
            WLS_PATH_CHARS,
            run_directory,
            L"launcher-recovery.status"
        ) != 0
        || wls_recovery_launcher_identity(home, launcher_identity) != 0
        || wls_recovery_launcher_generation(
            launcher_identity, launcher_generation
        ) != 0
        || wls_boot_id(boot_id) != 0
        || (now_monotonic = (unsigned long long)GetTickCount64()) == 0ULL
        || wls_recovery_wall_seconds(&now_wall) != 0) goto failed;
    read_status = wls_recovery_read_secure(
        context->ledger_path,
        0,
        encoded,
        sizeof(encoded),
        &encoded_length
    );
    if (read_status == 1) goto failed;
    if (read_status == 0
        && wls_recovery_parse(
            encoded, encoded_length, &context->state
        ) != 0) {
        wls_recovery_initialize_invalid(
            &context->state,
            boot_id,
            launcher_generation,
            launcher_identity,
            runtime_generation,
            (char)active_slot,
            now_monotonic,
            now_wall
        );
    } else {
        (void)wls_recovery_reconcile(
            &context->state,
            read_status == 0,
            boot_id,
            launcher_generation,
            launcher_identity,
            runtime_generation,
            (char)active_slot,
            now_monotonic,
            now_wall
        );
    }
    if (wls_recovery_publish(context, NULL, NULL) != 0) goto failed;
    for (;;) {
        if (InterlockedCompareExchange(
                &wls_service_stop_requested, 0, 0
            ) != 0
            || wls_admin_stopped(home) != 0) {
            (void)wls_recovery_publish(
                context, "ADMIN_STOPPED", "CONTROLLED_STOP"
            );
            SecureZeroMemory(encoded, sizeof(encoded));
            return 1;
        }
        if (wls_recovery_maintenance_pending(
                home, active_slot, runtime_generation
            )) {
            (void)wls_recovery_publish(
                context, "REBOOTSTRAP_PENDING", "CONTROLLED_STOP"
            );
            SecureZeroMemory(encoded, sizeof(encoded));
            return 1;
        }
        if (wls_reload_pending()) {
            SecureZeroMemory(encoded, sizeof(encoded));
            return 2;
        }
        now_monotonic = (unsigned long long)GetTickCount64();
        if (now_monotonic == 0ULL
            || wls_recovery_wall_seconds(&now_wall) != 0) goto failed;
        if (context->state.next_retry_monotonic_ms == 0ULL
            || now_monotonic >= context->state.next_retry_monotonic_ms) break;
        if (last_checkpoint == 0ULL
            || now_monotonic - last_checkpoint >= 10000ULL) {
            unsigned long long remaining =
                context->state.next_retry_monotonic_ms - now_monotonic;
            DWORD wait_hint = remaining >= 295000ULL
                ? 300000U : (DWORD)(remaining + 5000ULL);
            if (wait_hint < 5000U) wait_hint = 5000U;
            wls_report_service_pending(SERVICE_START_PENDING, wait_hint);
            last_checkpoint = now_monotonic;
        }
        Sleep(200U);
    }
    randombytes_buf(attempt_bytes, sizeof(attempt_bytes));
    if (sodium_bin2hex(
            attempt_id,
            sizeof(attempt_id),
            attempt_bytes,
            sizeof(attempt_bytes)
        ) == NULL
        || !wls_recovery_hex(attempt_id, 32U)) goto failed;
    wls_recovery_mark_attempt(
        &context->state, attempt_id, now_monotonic, now_wall
    );
    if (wls_recovery_publish(context, NULL, NULL) != 0) goto failed;
    SecureZeroMemory(attempt_bytes, sizeof(attempt_bytes));
    SecureZeroMemory(attempt_id, sizeof(attempt_id));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(launcher_identity, sizeof(launcher_identity));
    SecureZeroMemory(launcher_generation, sizeof(launcher_generation));
    SecureZeroMemory(boot_id, sizeof(boot_id));
    return 0;
failed:
    SecureZeroMemory(attempt_bytes, sizeof(attempt_bytes));
    SecureZeroMemory(attempt_id, sizeof(attempt_id));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(launcher_identity, sizeof(launcher_identity));
    SecureZeroMemory(launcher_generation, sizeof(launcher_generation));
    SecureZeroMemory(boot_id, sizeof(boot_id));
    return 3;
}

static int wls_recovery_finish_attempt(
    struct wls_windows_recovery_context *context,
    int controlled,
    const char *failure_reason
) {
    unsigned long long now = (unsigned long long)GetTickCount64();
    long long now_wall;
    if (context == NULL || now == 0ULL
        || wls_recovery_wall_seconds(&now_wall) != 0) return 1;
    if (controlled) {
        wls_recovery_mark_controlled(&context->state, now, now_wall);
    } else {
        wls_recovery_record_failure(
            &context->state, now, now_wall, failure_reason
        );
    }
    return wls_recovery_publish(context, NULL, NULL);
}

static int wls_recovery_observe_health(
    struct wls_windows_recovery_context *context,
    int exact_ready,
    unsigned long long *observation_started
) {
    unsigned long long now;
    long long now_wall;
    if (context == NULL || observation_started == NULL
        || context->healthy_committed) return 0;
    if (!exact_ready) {
        *observation_started = 0ULL;
        return 0;
    }
    now = (unsigned long long)GetTickCount64();
    if (now == 0ULL || wls_recovery_wall_seconds(&now_wall) != 0) return 1;
    if (*observation_started == 0ULL) {
        *observation_started = now;
        wls_recovery_mark_observing(&context->state, now, now_wall);
        return wls_recovery_publish(context, NULL, NULL);
    }
    if (now < *observation_started) return 1;
    if (now - *observation_started < WLS_RECOVERY_HEALTH_MILLISECONDS) {
        return 0;
    }
    wls_recovery_mark_healthy(&context->state, now, now_wall);
    if (wls_recovery_publish(context, NULL, NULL) != 0) return 1;
    context->healthy_committed = 1;
    return 0;
}

static int wls_parse_process_attestation(
    const char *contents,
    size_t length,
    struct wls_process_attestation_receipt *receipt
) {
    int consumed = 0;
    if (contents == NULL || receipt == NULL) return 1;
    ZeroMemory(receipt, sizeof(*receipt));
    if (sscanf(
            contents,
            "WLS-PROCESS-ATTEST/3\npid=%lu\nstart_id=%llu\n"
            "binary_digest=%64[0-9a-f]\nruntime_generation=%64[0-9a-f]\n"
            "config_digest=%64[0-9a-f]\nconfig_path_digest=%64[0-9a-f]\n"
            "publication_generation=%llu\nfence_kind=%9[A-Z]\n"
            "candidate_transaction_id=%32[0-9a-f-]\n"
            "candidate_phase=%39[A-Z_]\n"
            "candidate_fence_digest=%64[0-9a-f]\n%n",
            &receipt->pid,
            &receipt->start_id,
            receipt->binary_digest,
            receipt->runtime_generation,
            receipt->config_digest,
            receipt->config_path_digest,
            &receipt->publication_generation,
            receipt->fence_kind,
            receipt->candidate_transaction_id,
            receipt->candidate_phase,
            receipt->candidate_fence_digest,
            &consumed
        ) != 11
        || consumed != (int)length
        || receipt->pid == 0UL
        || receipt->start_id == 0ULL
        || receipt->publication_generation == 0ULL
        || !wls_is_hex(receipt->binary_digest, 64U)
        || !wls_is_hex(receipt->runtime_generation, 64U)
        || !wls_is_hex(receipt->config_digest, 64U)
        || !wls_is_hex(receipt->config_path_digest, 64U)
        || (strcmp(receipt->fence_kind, "ACTIVE") == 0
            ? (strcmp(receipt->candidate_transaction_id, "-") != 0
                || strcmp(receipt->candidate_phase, "ACTIVE") != 0
                || !wls_all_zero_hex(receipt->candidate_fence_digest))
            : (strcmp(receipt->fence_kind, "CANDIDATE") != 0
                || !wls_is_hex(receipt->candidate_transaction_id, 32U)
                || (strcmp(receipt->candidate_phase, "ACTIVATING") != 0
                    && strcmp(
                        receipt->candidate_phase,
                        "SERVICE_TREE_RETIREMENT_PENDING"
                    ) != 0)
                || !wls_is_hex(receipt->candidate_fence_digest, 64U)
                || wls_all_zero_hex(receipt->candidate_fence_digest)))) {
        SecureZeroMemory(receipt, sizeof(*receipt));
        return 1;
    }
    return 0;
}

static int wls_parse_platform_retirement_v2(
    const char *contents,
    size_t length,
    struct wls_platform_retirement_receipt *receipt
) {
    int consumed = 0;
    if (contents == NULL || receipt == NULL) return 1;
    ZeroMemory(receipt, sizeof(*receipt));
    if (sscanf(
            contents,
            "WLS-PROCESS-TREE-RETIRE/2\nstatus=%15[A-Z]\n"
            "retirement_id=%64[0-9a-f]\npid=%lu\nstart_id=%llu\n"
            "attestation_digest=%64[0-9a-f]\n"
            "binary_digest=%64[0-9a-f]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "host_boot_id=%64[0-9a-f]\n"
            "config_digest=%64[0-9a-f]\n"
            "config_path_digest=%64[0-9a-f]\n"
            "publication_generation=%llu\nplatform=%15[a-z-]\n"
            "service_id=%64[0-9a-f]\n"
            "requested_launcher_generation=%64[0-9a-f]\n"
            "completed_launcher_generation=%64[0-9a-f]\n"
            "completed_host_boot_id=%64[0-9a-f]\n"
            "completed_runtime_generation=%64[0-9a-f]\n%n",
            receipt->status,
            receipt->retirement_id,
            &receipt->pid,
            &receipt->start_id,
            receipt->attestation_digest,
            receipt->binary_digest,
            receipt->runtime_generation,
            receipt->host_boot_id,
            receipt->config_digest,
            receipt->config_path_digest,
            &receipt->publication_generation,
            receipt->platform,
            receipt->service_id,
            receipt->requested_launcher_generation,
            receipt->completed_launcher_generation,
            receipt->completed_host_boot_id,
            receipt->completed_runtime_generation,
            &consumed
        ) != 17
        || consumed != (int)length
        || (strcmp(receipt->status, "COMPLETE") != 0
            && strcmp(receipt->status, "INDETERMINATE") != 0)
        || receipt->pid == 0UL
        || receipt->start_id == 0ULL
        || !wls_is_hex(receipt->retirement_id, 64U)
        || !wls_is_hex(receipt->attestation_digest, 64U)
        || !wls_is_hex(receipt->binary_digest, 64U)
        || !wls_is_hex(receipt->runtime_generation, 64U)
        || !wls_is_hex(receipt->host_boot_id, 64U)
        || !wls_is_hex(receipt->config_digest, 64U)
        || !wls_is_hex(receipt->config_path_digest, 64U)
        || strcmp(receipt->platform, "windows-job") != 0
        || !wls_is_hex(receipt->service_id, 64U)
        || !wls_is_hex(receipt->requested_launcher_generation, 64U)
        || !wls_is_hex(receipt->completed_launcher_generation, 64U)
        || !wls_is_hex(receipt->completed_host_boot_id, 64U)
        || !wls_is_hex(receipt->completed_runtime_generation, 64U)) {
        SecureZeroMemory(receipt, sizeof(*receipt));
        return 1;
    }
    return 0;
}

static int wls_write_platform_retirement_v2(
    const wchar_t *home,
    const struct wls_platform_retirement_receipt *receipt
) {
    wchar_t path[WLS_PATH_CHARS];
    char payload[2048];
    int written;
    if (home == NULL || receipt == NULL
        || wls_join(
            path,
            WLS_PATH_CHARS,
            home,
            L"trust\\process-tree-retirement.receipt"
        ) != 0) return 1;
    written = _snprintf_s(
        payload,
        sizeof(payload),
        _TRUNCATE,
        "WLS-PROCESS-TREE-RETIRE/2\nstatus=%s\nretirement_id=%s\n"
        "pid=%lu\nstart_id=%llu\nattestation_digest=%s\n"
        "binary_digest=%s\nruntime_generation=%s\nhost_boot_id=%s\n"
        "config_digest=%s\nconfig_path_digest=%s\n"
        "publication_generation=%llu\nplatform=%s\nservice_id=%s\n"
        "requested_launcher_generation=%s\n"
        "completed_launcher_generation=%s\ncompleted_host_boot_id=%s\n"
        "completed_runtime_generation=%s\n",
        receipt->status,
        receipt->retirement_id,
        receipt->pid,
        receipt->start_id,
        receipt->attestation_digest,
        receipt->binary_digest,
        receipt->runtime_generation,
        receipt->host_boot_id,
        receipt->config_digest,
        receipt->config_path_digest,
        receipt->publication_generation,
        receipt->platform,
        receipt->service_id,
        receipt->requested_launcher_generation,
        receipt->completed_launcher_generation,
        receipt->completed_host_boot_id,
        receipt->completed_runtime_generation
    );
    if (written <= 0 || wls_atomic_system_text(path, payload) != 0) {
        SecureZeroMemory(payload, sizeof(payload));
        return 1;
    }
    SecureZeroMemory(payload, sizeof(payload));
    return 0;
}

/* Receipt absence is a CreateFileW-only result.  Once a handle exists, every
 * authority, size, read, or identity failure is invalid regardless of any
 * ambient LastError value left by an earlier API call. */
static int wls_platform_retirement_receipt_read(
    const wchar_t *home,
    struct wls_platform_retirement_receipt *receipt
)
{
    wchar_t path[WLS_PATH_CHARS];
    char contents[2048];
    HANDLE file = INVALID_HANDLE_VALUE;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    BY_HANDLE_FILE_INFORMATION before;
    BY_HANDLE_FILE_INFORMATION after;
    LARGE_INTEGER size;
    DWORD used = 0U;
    DWORD amount = 0U;
    int result = WLS_RETIREMENT_RECEIPT_INVALID;
    ZeroMemory(path, sizeof(path));
    ZeroMemory(contents, sizeof(contents));
    ZeroMemory(&attributes, sizeof(attributes));
    ZeroMemory(&before, sizeof(before));
    ZeroMemory(&after, sizeof(after));
    if (home == NULL || receipt == NULL
        || wls_join(path, WLS_PATH_CHARS, home,
            L"trust\\process-tree-retirement.receipt") != 0) goto cleanup;
    file = CreateFileW(
        path,
        GENERIC_READ | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE) {
        DWORD error = GetLastError();
        result = error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
            ? WLS_RETIREMENT_RECEIPT_ABSENT
            : WLS_RETIREMENT_RECEIPT_INVALID;
        goto cleanup;
    }
    if (!GetFileInformationByHandleEx(
            file, FileAttributeTagInfo, &attributes, sizeof(attributes)
        )
        || !GetFileInformationByHandle(file, &before)
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
        || before.nNumberOfLinks != 1U
        || wls_recovery_acl_safe(file, 0) != 0) goto cleanup;
    size.HighPart = (LONG)before.nFileSizeHigh;
    size.LowPart = before.nFileSizeLow;
    if (size.QuadPart <= 0 || (unsigned long long)size.QuadPart
            >= (unsigned long long)sizeof(contents)
        || size.QuadPart > MAXDWORD) goto cleanup;
    while (used < (DWORD)size.QuadPart) {
        if (!ReadFile(file, contents + used, (DWORD)size.QuadPart - used,
                &amount, NULL) || amount == 0U) goto cleanup;
        used += amount;
    }
    if (!GetFileInformationByHandle(file, &after)
        || before.dwVolumeSerialNumber != after.dwVolumeSerialNumber
        || before.nFileIndexHigh != after.nFileIndexHigh
        || before.nFileIndexLow != after.nFileIndexLow
        || before.nFileSizeHigh != after.nFileSizeHigh
        || before.nFileSizeLow != after.nFileSizeLow
        || before.nNumberOfLinks != after.nNumberOfLinks
        || CompareFileTime(&before.ftLastWriteTime, &after.ftLastWriteTime) != 0
        || memchr(contents, '\0', used) != NULL) goto cleanup;
    contents[used] = '\0';
    if (wls_parse_platform_retirement_v2(contents, (size_t)used, receipt) != 0) {
        goto cleanup;
    }
    result = WLS_RETIREMENT_RECEIPT_PRESENT;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(contents, sizeof(contents));
    if (result != WLS_RETIREMENT_RECEIPT_PRESENT && receipt != NULL) {
        SecureZeroMemory(receipt, sizeof(*receipt));
    }
    return result;
}

static int wls_seal_platform_retirement_pending(
    const wchar_t *home,
    const char *runtime_generation
) {
    wchar_t retirement_path[WLS_PATH_CHARS];
    wchar_t attestation_path[WLS_PATH_CHARS];
    unsigned char *retirement = NULL;
    unsigned char *attestation = NULL;
    size_t retirement_length = 0U;
    size_t attestation_length = 0U;
    char status[16];
    char digest[65];
    char zeros[65];
    int consumed = 0;
    int result = 1;
    struct wls_platform_retirement_receipt sealed;
    struct wls_process_attestation_receipt process;
    ZeroMemory(&sealed, sizeof(sealed));
    ZeroMemory(&process, sizeof(process));
    if (home == NULL || runtime_generation == NULL
        || InterlockedCompareExchange(
            &wls_authenticated_platform_generation, 0, 0
        ) == 0
        || wls_join(
            retirement_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\process-tree-retirement.receipt"
        ) != 0
        || wls_join(
            attestation_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\process-attestation.receipt"
        ) != 0
        || wls_read_file(
            retirement_path,
            2048U,
            &retirement,
            &retirement_length
        ) != 0) goto cleanup;
    if (strncmp(
            (const char *)retirement,
            "WLS-PROCESS-TREE-RETIRE/2\n",
            26U
        ) == 0) {
        if (wls_parse_platform_retirement_v2(
                (const char *)retirement,
                retirement_length,
                &sealed
            ) == 0
            && strcmp(sealed.status, "INDETERMINATE") == 0
            && strcmp(sealed.service_id, wls_service_id) == 0
            && strcmp(
                sealed.requested_launcher_generation,
                wls_launcher_generation
            ) == 0) {
            result = 0;
        }
        goto cleanup;
    }
    if (sscanf(
            (const char *)retirement,
            "WLS-PROCESS-TREE-RETIRE/1\nstatus=%15[A-Z]\n"
            "retirement_id=%64[0-9a-f]\npid=%lu\nstart_id=%llu\n"
            "attestation_digest=%64[0-9a-f]\n"
            "binary_digest=%64[0-9a-f]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "host_boot_id=%64[0-9a-f]\n%n",
            status,
            sealed.retirement_id,
            &sealed.pid,
            &sealed.start_id,
            sealed.attestation_digest,
            sealed.binary_digest,
            sealed.runtime_generation,
            sealed.host_boot_id,
            &consumed
        ) != 8
        || consumed != (int)retirement_length
        || strcmp(status, "INDETERMINATE") != 0
        || strcmp(sealed.runtime_generation, runtime_generation) != 0
        || wls_read_file(
            attestation_path,
            2048U,
            &attestation,
            &attestation_length
        ) != 0
        || wls_parse_process_attestation(
            (const char *)attestation,
            attestation_length,
            &process
        ) != 0
        || process.pid != sealed.pid
        || process.start_id != sealed.start_id
        || strcmp(process.binary_digest, sealed.binary_digest) != 0
        || strcmp(process.runtime_generation, sealed.runtime_generation) != 0
        || wls_sha256_text(attestation, attestation_length, digest) != 0
        || strcmp(digest, sealed.attestation_digest) != 0) goto cleanup;
    memcpy(sealed.status, "INDETERMINATE", 14U);
    memcpy(sealed.config_digest, process.config_digest, 65U);
    memcpy(sealed.config_path_digest, process.config_path_digest, 65U);
    sealed.publication_generation = process.publication_generation;
    memcpy(sealed.platform, "windows-job", 12U);
    memcpy(sealed.service_id, wls_service_id, 65U);
    memcpy(
        sealed.requested_launcher_generation,
        wls_launcher_generation,
        65U
    );
    memset(zeros, '0', 64U);
    zeros[64] = '\0';
    memcpy(sealed.completed_launcher_generation, zeros, 65U);
    memcpy(sealed.completed_host_boot_id, zeros, 65U);
    memcpy(sealed.completed_runtime_generation, zeros, 65U);
    result = wls_write_platform_retirement_v2(home, &sealed);
cleanup:
    SecureZeroMemory(&sealed, sizeof(sealed));
    SecureZeroMemory(&process, sizeof(process));
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(zeros, sizeof(zeros));
    if (retirement != NULL) {
        SecureZeroMemory(retirement, retirement_length);
        HeapFree(GetProcessHeap(), 0U, retirement);
    }
    if (attestation != NULL) {
        SecureZeroMemory(attestation, attestation_length);
        HeapFree(GetProcessHeap(), 0U, attestation);
    }
    return result;
}

static int wls_promote_platform_retirement(
    const wchar_t *home,
    const char *runtime_generation
) {
    char boot_id[65];
    int receipt_state;
    int result = 1;
    struct wls_platform_retirement_receipt receipt;
    ZeroMemory(&receipt, sizeof(receipt));
    if (home == NULL || runtime_generation == NULL
        || InterlockedCompareExchange(
            &wls_authenticated_platform_generation, 0, 0
        ) == 0) return 1;
    receipt_state = wls_platform_retirement_receipt_read(home, &receipt);
    if (receipt_state == WLS_RETIREMENT_RECEIPT_ABSENT) return 0;
    if (receipt_state != WLS_RETIREMENT_RECEIPT_PRESENT) goto cleanup;
    if (strcmp(receipt.status, "COMPLETE") == 0) {
        result = 0;
        goto cleanup;
    }
    if (strcmp(receipt.service_id, wls_service_id) != 0
        || strcmp(
            receipt.requested_launcher_generation,
            wls_launcher_generation
        ) == 0
        || wls_all_zero_hex(receipt.requested_launcher_generation)
        || !wls_all_zero_hex(receipt.completed_launcher_generation)
        || !wls_all_zero_hex(receipt.completed_host_boot_id)
        || !wls_all_zero_hex(receipt.completed_runtime_generation)
        || wls_boot_id(boot_id) != 0) goto cleanup;
    memcpy(receipt.status, "COMPLETE", 9U);
    memcpy(
        receipt.completed_launcher_generation,
        wls_launcher_generation,
        65U
    );
    memcpy(receipt.completed_host_boot_id, boot_id, 65U);
    memcpy(
        receipt.completed_runtime_generation,
        runtime_generation,
        65U
    );
    result = wls_write_platform_retirement_v2(home, &receipt);
cleanup:
    SecureZeroMemory(&receipt, sizeof(receipt));
    SecureZeroMemory(boot_id, sizeof(boot_id));
    return result;
}

static int wls_nginx_pid_residue_kind(const wchar_t *name)
{
    wchar_t token[17];
    int consumed = 0;
    if (name != NULL && swscanf_s(
            name, L".nginx.pid.test.%16[0-9a-f].pid%n", token,
            (unsigned int)(sizeof(token) / sizeof(token[0])), &consumed
        ) == 1 && wcslen(token) == 16U && consumed == (int)wcslen(name)) return 1;
    if (name != NULL && swscanf_s(
            name, L".nginx.pid.test.%16[0-9a-f].conf%n", token,
            (unsigned int)(sizeof(token) / sizeof(token[0])), &consumed
        ) == 1 && wcslen(token) == 16U && consumed == (int)wcslen(name)) return 2;
    if (name != NULL && swscanf_s(
            name, L".nginx.pid.seal.%16[0-9a-f]%n", token,
            (unsigned int)(sizeof(token) / sizeof(token[0])), &consumed
        ) == 1 && wcslen(token) == 16U && consumed == (int)wcslen(name)) return 3;
    return 0;
}

/* Exact Broker staging contracts: only TEST PID source grants data write;
 * shadow configs and seal staging grant controller/data read-only. */
static int wls_nginx_pid_residue_acl_valid(
    HANDLE leaf,
    int kind,
    PSID controller_sid,
    PSID data_plane_sid
)
{
    if (leaf == INVALID_HANDLE_VALUE || controller_sid == NULL
        || data_plane_sid == NULL || kind < 1 || kind > 3) return 1;
    if (wls_launcher_slot_acl_valid_profile(
            leaf, 0, controller_sid, 1, data_plane_sid, 1, 1, 0,
            kind == 1 ? FILE_GENERIC_READ | FILE_GENERIC_WRITE
                : FILE_GENERIC_READ
        ) == 0) return 0;
    /* Broker FILE_CREATE supplies this explicit profile before applying the
     * final kind-specific DACL; no token-default or inherited ACL is valid. */
    return wls_launcher_slot_acl_valid_profile(
        leaf, 0, NULL, 0, NULL, 0, 1, 0, 0U
    );
}

static int wls_nginx_pid_residue_intent_name_valid(const char *name)
{
    wchar_t wide[WLS_NGINX_PID_RESIDUE_NAME_MAX + 1U];
    int length;
    ZeroMemory(wide, sizeof(wide));
    if (name == NULL || name[0] == '\0') return 0;
    length = MultiByteToWideChar(
        CP_UTF8, MB_ERR_INVALID_CHARS, name, -1, wide,
        (int)(sizeof(wide) / sizeof(wide[0]))
    );
    return length > 1 && wls_nginx_pid_residue_kind(wide) != 0;
}

static int wls_nginx_pid_residue_directory(
    const wchar_t *home,
    HANDLE *directory
)
{
    wchar_t path[WLS_PATH_CHARS];
    PSID controller_sid = NULL;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    ZeroMemory(&attributes, sizeof(attributes));
    if (directory == NULL || home == NULL
        || wls_join(path, WLS_PATH_CHARS, home, L"nginx-pid") != 0
        || wls_launcher_gateway_service_sid(&controller_sid) != 0) goto cleanup;
    *directory = CreateFileW(
        path,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | FILE_WRITE_DATA | READ_CONTROL
            | SYNCHRONIZE,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL, OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (*directory == INVALID_HANDLE_VALUE
        || wls_launcher_handle_is_reparse(*directory)
        || !GetFileInformationByHandleEx(
            *directory, FileAttributeTagInfo, &attributes, sizeof(attributes)
        ) || (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) == 0U
        || wls_launcher_host_data_plane_directory_acl_valid(
            *directory, controller_sid
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (controller_sid != NULL) LocalFree(controller_sid);
    SecureZeroMemory(path, sizeof(path));
    if (result != 0 && directory != NULL && *directory != INVALID_HANDLE_VALUE) {
        CloseHandle(*directory);
        *directory = INVALID_HANDLE_VALUE;
    }
    return result;
}

static int wls_nginx_pid_residue_leaf(
    HANDLE directory,
    const wchar_t *name,
    struct wls_nginx_pid_residue_entry *entry
)
{
    HANDLE leaf = INVALID_HANDLE_VALUE;
    BY_HANDLE_FILE_INFORMATION before;
    BY_HANDLE_FILE_INFORMATION after;
    PSID controller_sid = NULL;
    PSID data_plane_sid = NULL;
    int kind;
    unsigned long long size;
    int result = 1;
    ZeroMemory(&before, sizeof(before));
    ZeroMemory(&after, sizeof(after));
    if (directory == INVALID_HANDLE_VALUE || name == NULL || entry == NULL
        || (kind = wls_nginx_pid_residue_kind(name)) == 0
        || wls_launcher_gateway_service_sid(&controller_sid) != 0
        || !ConvertStringSidToSidW(
            WLS_DATA_PLANE_SERVICE_SID_TEXT, &data_plane_sid
        ) || data_plane_sid == NULL || !IsValidSid(data_plane_sid)) goto cleanup;
    leaf = wls_nt_open_child(
        directory, name, wcslen(name), GENERIC_READ | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE, FILE_OPEN, 0
    );
    if (leaf == INVALID_HANDLE_VALUE || wls_launcher_handle_is_reparse(leaf)
        || !GetFileInformationByHandle(leaf, &before)
        || before.nNumberOfLinks != 1U) goto cleanup;
    size = ((unsigned long long)before.nFileSizeHigh << 32U)
        | (unsigned long long)before.nFileSizeLow;
    if ((kind == 2 && (size == 0ULL
                || size > WLS_NGINX_PID_RESIDUE_CONFIG_MAX_BYTES))
        || (kind != 2 && size > WLS_NGINX_PID_RESIDUE_PID_MAX_BYTES)
        || wls_nginx_pid_residue_acl_valid(
            leaf, kind, controller_sid, data_plane_sid
        ) != 0) goto cleanup;
    if (!GetFileInformationByHandle(leaf, &after)
        || before.dwVolumeSerialNumber != after.dwVolumeSerialNumber
        || before.nFileIndexHigh != after.nFileIndexHigh
        || before.nFileIndexLow != after.nFileIndexLow
        || before.nNumberOfLinks != after.nNumberOfLinks
        || before.nFileSizeHigh != after.nFileSizeHigh
        || before.nFileSizeLow != after.nFileSizeLow) goto cleanup;
    if (WideCharToMultiByte(CP_UTF8, WC_ERR_INVALID_CHARS, name, -1,
            entry->name, (int)sizeof(entry->name), NULL, NULL) <= 1) goto cleanup;
    entry->volume_serial = before.dwVolumeSerialNumber;
    entry->file_index_high = before.nFileIndexHigh;
    entry->file_index_low = before.nFileIndexLow;
    result = 0;
cleanup:
    if (leaf != INVALID_HANDLE_VALUE) CloseHandle(leaf);
    if (controller_sid != NULL) LocalFree(controller_sid);
    if (data_plane_sid != NULL) LocalFree(data_plane_sid);
    return result;
}

static int wls_nginx_pid_residue_scan(
    const wchar_t *home,
    struct wls_nginx_pid_residue_entry entries[WLS_NGINX_PID_RESIDUE_MAX],
    unsigned int *count
)
{
    wchar_t pattern[WLS_PATH_CHARS];
    WIN32_FIND_DATAW found;
    HANDLE directory = INVALID_HANDLE_VALUE;
    HANDLE enumeration = INVALID_HANDLE_VALUE;
    int status = 1;
    if (entries == NULL || count == NULL || home == NULL) return 1;
    ZeroMemory(entries, sizeof(*entries) * WLS_NGINX_PID_RESIDUE_MAX);
    *count = 0U;
    if (wls_nginx_pid_residue_directory(home, &directory) != 0
        || wls_join(pattern, WLS_PATH_CHARS, home, L"nginx-pid\\*") != 0) {
        goto cleanup;
    }
    enumeration = FindFirstFileW(pattern, &found);
    if (enumeration == INVALID_HANDLE_VALUE) {
        /* The parent handle was already pinned and validated.  An exact
         * no-match is the only empty manifest; every other enumeration error
         * leaves recovery fail-closed. */
        if (GetLastError() == ERROR_FILE_NOT_FOUND) {
            status = 0;
        }
        goto cleanup;
    }
    do {
        if (wcscmp(found.cFileName, L".") == 0
            || wcscmp(found.cFileName, L"..") == 0
            || wcscmp(found.cFileName, L"nginx.pid") == 0) continue;
        if (wls_nginx_pid_residue_kind(found.cFileName) == 0
            || *count >= WLS_NGINX_PID_RESIDUE_MAX
            || wls_nginx_pid_residue_leaf(
                directory, found.cFileName, &entries[*count]
            ) != 0) goto cleanup;
        (*count)++;
    } while (FindNextFileW(enumeration, &found));
    if (GetLastError() != ERROR_NO_MORE_FILES) goto cleanup;
    /* FindFirstFile ordering is unspecified. Sort the fixed record set before
     * serializing so same-generation crash replay is byte-identical. */
    for (unsigned int left = 0U; left < *count; left++) {
        for (unsigned int right = left + 1U; right < *count; right++) {
            if (strcmp(entries[left].name, entries[right].name) > 0) {
                struct wls_nginx_pid_residue_entry swap = entries[left];
                entries[left] = entries[right]; entries[right] = swap;
            }
        }
        if (left > 0U && strcmp(entries[left - 1U].name, entries[left].name) == 0) {
            goto cleanup;
        }
    }
    status = 0;
cleanup:
    if (enumeration != INVALID_HANDLE_VALUE) FindClose(enumeration);
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    if (status != 0) {
        SecureZeroMemory(entries, sizeof(*entries) * WLS_NGINX_PID_RESIDUE_MAX);
        *count = 0U;
    }
    return status;
}

static int wls_write_nginx_pid_residue_intent(
    const wchar_t *home, const struct wls_nginx_pid_residue_intent *intent
)
{
    wchar_t path[WLS_PATH_CHARS];
    char payload[2048];
    char existing[2048];
    size_t existing_length = 0U;
    int written;
    int read_status;
    if (home == NULL || intent == NULL || intent->count == 0U
        || intent->count > WLS_NGINX_PID_RESIDUE_MAX
        || wls_join(path, WLS_PATH_CHARS, home,
            L"trust\\nginx-pid-residue.intent") != 0) return 1;
    written = _snprintf_s(payload, sizeof(payload), _TRUNCATE,
        "WLS-NGINX-PID-RESIDUE/1\nplatform=%s\nservice_id=%s\n"
        "requested_launcher_generation=%s\nruntime_generation=%s\ncount=%u\n"
        "name1=%s\nvol1=%lu\nhigh1=%lu\nlow1=%lu\n"
        "name2=%s\nvol2=%lu\nhigh2=%lu\nlow2=%lu\n"
        "name3=%s\nvol3=%lu\nhigh3=%lu\nlow3=%lu\n",
        intent->platform, intent->service_id, intent->requested_launcher_generation,
        intent->runtime_generation, intent->count,
        intent->entries[0].name, (unsigned long)intent->entries[0].volume_serial,
        (unsigned long)intent->entries[0].file_index_high,
        (unsigned long)intent->entries[0].file_index_low,
        intent->entries[1].name, (unsigned long)intent->entries[1].volume_serial,
        (unsigned long)intent->entries[1].file_index_high,
        (unsigned long)intent->entries[1].file_index_low,
        intent->entries[2].name, (unsigned long)intent->entries[2].volume_serial,
        (unsigned long)intent->entries[2].file_index_high,
        (unsigned long)intent->entries[2].file_index_low);
    if (written <= 0) return 1;
    read_status = wls_recovery_read_secure(path, 0, existing, sizeof(existing),
        &existing_length);
    if ((read_status == 0 && (existing_length != (size_t)written
                || sodium_memcmp(existing, payload, existing_length) != 0))
        || (read_status != 0 && read_status != 2)
        || (read_status == 2 && wls_atomic_system_text(path, payload) != 0)) {
        SecureZeroMemory(payload, sizeof(payload));
        SecureZeroMemory(existing, sizeof(existing));
        return 1;
    }
    SecureZeroMemory(payload, sizeof(payload));
    SecureZeroMemory(existing, sizeof(existing));
    return 0;
}

static int wls_seal_nginx_pid_residue_pending(
    const wchar_t *home, const char *runtime_generation
)
{
    struct wls_nginx_pid_residue_intent intent;
    if (home == NULL || runtime_generation == NULL
        || InterlockedCompareExchange(
            &wls_authenticated_platform_generation, 0, 0
        ) == 0) return 1;
    ZeroMemory(&intent, sizeof(intent));
    if (wls_nginx_pid_residue_scan(home, intent.entries, &intent.count) != 0) {
        return 1;
    }
    if (intent.count == 0U) return 0;
    for (unsigned int index = intent.count; index < WLS_NGINX_PID_RESIDUE_MAX;
         index++) strcpy_s(intent.entries[index].name,
            sizeof(intent.entries[index].name), "-");
    strcpy_s(intent.platform, sizeof(intent.platform), "windows-job");
    memcpy(intent.service_id, wls_service_id, sizeof(intent.service_id));
    memcpy(intent.requested_launcher_generation, wls_launcher_generation,
        sizeof(intent.requested_launcher_generation));
    memcpy(intent.runtime_generation, runtime_generation,
        sizeof(intent.runtime_generation));
    return wls_write_nginx_pid_residue_intent(home, &intent);
}

static int wls_flush_trust_directory(const wchar_t *home)
{
    wchar_t trust[WLS_PATH_CHARS];
    HANDLE directory = INVALID_HANDLE_VALUE;
    int result = 1;
    if (home == NULL || wls_join(trust, WLS_PATH_CHARS, home, L"trust") != 0) {
        return 1;
    }
    directory = CreateFileW(
        trust, FILE_LIST_DIRECTORY | FILE_TRAVERSE | FILE_WRITE_DATA
            | READ_CONTROL | SYNCHRONIZE,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE, NULL, OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT, NULL
    );
    if (directory != INVALID_HANDLE_VALUE && !wls_launcher_handle_is_reparse(directory)
        && wls_launcher_root_only_directory_acl_valid(directory) == 0
        && wls_launcher_flush_verified_directory(directory) == 0) result = 0;
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    SecureZeroMemory(trust, sizeof(trust));
    return result;
}

/* Read-only classifier for a direct launcher.  No absence of Guardian proof
 * may turn a damaged receipt, intent, or staging namespace into cleanup. */
static int wls_pid_residue_recovery_evidence_state(const wchar_t *home)
{
    wchar_t intent_path[WLS_PATH_CHARS];
    char intent[2048];
    size_t intent_length = 0U;
    struct wls_platform_retirement_receipt receipt;
    struct wls_nginx_pid_residue_entry entries[WLS_NGINX_PID_RESIDUE_MAX];
    struct wls_nginx_pid_residue_intent recorded;
    unsigned int residue_count = 0U;
    int retirement_pending = 0;
    int intent_pending = 0;
    int read_status;
    int receipt_state;
    unsigned int count = 0U;
    int consumed = 0;
    int result = WLS_PID_RESIDUE_EVIDENCE_UNSAFE;
    ZeroMemory(&receipt, sizeof(receipt));
    ZeroMemory(entries, sizeof(entries));
    ZeroMemory(&recorded, sizeof(recorded));
    if (home == NULL
        || wls_join(intent_path, WLS_PATH_CHARS, home,
            L"trust\\nginx-pid-residue.intent") != 0
        || wls_nginx_pid_residue_scan(home, entries, &residue_count) != 0) {
        goto cleanup;
    }
    receipt_state = wls_platform_retirement_receipt_read(home, &receipt);
    if (receipt_state == WLS_RETIREMENT_RECEIPT_PRESENT) {
        if (strcmp(receipt.status, "INDETERMINATE") == 0) {
            retirement_pending = 1;
        } else if (strcmp(receipt.status, "COMPLETE") != 0) {
            goto cleanup;
        }
    } else if (receipt_state != WLS_RETIREMENT_RECEIPT_ABSENT) {
        goto cleanup;
    }
    read_status = wls_recovery_read_secure(
        intent_path, 0, intent, sizeof(intent), &intent_length
    );
    if (read_status != 2) {
        if (read_status != 0
            || sscanf_s(intent,
                "WLS-NGINX-PID-RESIDUE/1\nplatform=%15[a-z-]\nservice_id=%64[0-9a-f]\n"
                "requested_launcher_generation=%64[0-9a-f]\nruntime_generation=%64[0-9a-f]\ncount=%u\n"
                "name1=%127[-.a-z0-9]\nvol1=%lu\nhigh1=%lu\nlow1=%lu\n"
                "name2=%127[-.a-z0-9]\nvol2=%lu\nhigh2=%lu\nlow2=%lu\n"
                "name3=%127[-.a-z0-9]\nvol3=%lu\nhigh3=%lu\nlow3=%lu\n%n",
                recorded.platform, (unsigned int)sizeof(recorded.platform),
                recorded.service_id, (unsigned int)sizeof(recorded.service_id),
                recorded.requested_launcher_generation,
                    (unsigned int)sizeof(recorded.requested_launcher_generation),
                recorded.runtime_generation, (unsigned int)sizeof(recorded.runtime_generation),
                &count,
                recorded.entries[0].name, (unsigned int)sizeof(recorded.entries[0].name),
                &recorded.entries[0].volume_serial, &recorded.entries[0].file_index_high,
                &recorded.entries[0].file_index_low,
                recorded.entries[1].name, (unsigned int)sizeof(recorded.entries[1].name),
                &recorded.entries[1].volume_serial, &recorded.entries[1].file_index_high,
                &recorded.entries[1].file_index_low,
                recorded.entries[2].name, (unsigned int)sizeof(recorded.entries[2].name),
                &recorded.entries[2].volume_serial, &recorded.entries[2].file_index_high,
                &recorded.entries[2].file_index_low,
                &consumed) != 17 || consumed != (int)intent_length
            || count == 0U || count > WLS_NGINX_PID_RESIDUE_MAX) goto cleanup;
        recorded.count = count;
        for (unsigned int index = 0U; index < count; index++) {
            if (!wls_nginx_pid_residue_intent_name_valid(recorded.entries[index].name)
                || (recorded.entries[index].volume_serial == 0U)
                || (recorded.entries[index].file_index_high == 0U
                    && recorded.entries[index].file_index_low == 0U)) goto cleanup;
            for (unsigned int prior = 0U; prior < index; prior++) {
                if (strcmp(recorded.entries[index].name,
                        recorded.entries[prior].name) == 0) goto cleanup;
            }
        }
        for (unsigned int index = count; index < WLS_NGINX_PID_RESIDUE_MAX;
             index++) {
            if (strcmp(recorded.entries[index].name, "-") != 0
                || recorded.entries[index].volume_serial != 0U
                || recorded.entries[index].file_index_high != 0U
                || recorded.entries[index].file_index_low != 0U) goto cleanup;
        }
        intent_pending = 1;
    }
    if (!retirement_pending && !intent_pending && residue_count == 0U) {
        result = WLS_PID_RESIDUE_EVIDENCE_CLEAN;
    } else if (retirement_pending && !intent_pending && residue_count == 0U) {
        /* A receipt-only crash must be promoted only by the authenticated
         * Guardian child; a direct launcher has no platform boundary proof. */
        result = WLS_PID_RESIDUE_EVIDENCE_PENDING;
    } else if (retirement_pending && intent_pending) {
        result = WLS_PID_RESIDUE_EVIDENCE_PENDING;
    }
cleanup:
    SecureZeroMemory(intent_path, sizeof(intent_path));
    SecureZeroMemory(intent, sizeof(intent));
    SecureZeroMemory(&receipt, sizeof(receipt));
    SecureZeroMemory(entries, sizeof(entries));
    SecureZeroMemory(&recorded, sizeof(recorded));
    return result;
}

/* A residue intent is actionable only as the companion of the same pending
 * V2 platform retirement.  Completion is deliberately deferred until the
 * residue namespace has been durably emptied. */
static int wls_validate_pending_retirement_for_pid_residue(
    const wchar_t *home,
    const struct wls_nginx_pid_residue_intent *intent
)
{
    struct wls_platform_retirement_receipt receipt;
    int receipt_state;
    int result = 1;
    ZeroMemory(&receipt, sizeof(receipt));
    if (home == NULL || intent == NULL
        || InterlockedCompareExchange(
            &wls_authenticated_platform_generation, 0, 0
        ) == 0
        || strcmp(intent->platform, "windows-job") != 0
        || strcmp(intent->service_id, wls_service_id) != 0
        || sodium_memcmp(intent->requested_launcher_generation,
            wls_launcher_generation, 64U) == 0) goto cleanup;
    receipt_state = wls_platform_retirement_receipt_read(home, &receipt);
    if (receipt_state != WLS_RETIREMENT_RECEIPT_PRESENT
        || strcmp(receipt.status, "INDETERMINATE") != 0
        || strcmp(receipt.platform, intent->platform) != 0
        || strcmp(receipt.service_id, intent->service_id) != 0
        || sodium_memcmp(receipt.requested_launcher_generation,
            intent->requested_launcher_generation, 64U) != 0
        || sodium_memcmp(receipt.runtime_generation,
            intent->runtime_generation, 64U) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(&receipt, sizeof(receipt));
    return result;
}

static int wls_consume_nginx_pid_residue_pending(const wchar_t *home)
{
    wchar_t path[WLS_PATH_CHARS];
    char contents[2048];
    size_t length = 0U;
    struct wls_nginx_pid_residue_intent recorded;
    struct wls_nginx_pid_residue_intent actual;
    unsigned int count = 0U;
    int consumed = 0;
    int read_status;
    HANDLE directory = INVALID_HANDLE_VALUE;
    int result = 1;
    ZeroMemory(&recorded, sizeof(recorded));
    ZeroMemory(&actual, sizeof(actual));
    if (home == NULL || wls_join(path, WLS_PATH_CHARS, home,
            L"trust\\nginx-pid-residue.intent") != 0) goto cleanup;
    if (InterlockedCompareExchange(
            &wls_authenticated_platform_generation, 0, 0
        ) == 0) {
        /* A direct/standalone launcher has no Guardian Job retirement proof:
         * retain every residue or pending intent and refuse to start. */
        read_status = wls_recovery_read_secure(
            path, 0, contents, sizeof(contents), &length
        );
        if (read_status == 2) {
            result = wls_nginx_pid_residue_scan(
                home, actual.entries, &actual.count
            ) == 0 && actual.count == 0U ? 0 : 1;
        }
        goto cleanup;
    }
    read_status = wls_recovery_read_secure(path, 0, contents, sizeof(contents),
        &length);
    if (read_status == 2) {
        result = wls_nginx_pid_residue_scan(home, actual.entries, &actual.count) == 0
            && actual.count == 0U ? 0 : 1;
        goto cleanup;
    }
    if (read_status != 0 || sscanf_s(contents,
            "WLS-NGINX-PID-RESIDUE/1\nplatform=%15[a-z-]\nservice_id=%64[0-9a-f]\n"
            "requested_launcher_generation=%64[0-9a-f]\nruntime_generation=%64[0-9a-f]\ncount=%u\n"
            "name1=%127[-.a-z0-9]\nvol1=%lu\nhigh1=%lu\nlow1=%lu\n"
            "name2=%127[-.a-z0-9]\nvol2=%lu\nhigh2=%lu\nlow2=%lu\n"
            "name3=%127[-.a-z0-9]\nvol3=%lu\nhigh3=%lu\nlow3=%lu\n%n",
            recorded.platform, (unsigned int)sizeof(recorded.platform),
            recorded.service_id, (unsigned int)sizeof(recorded.service_id),
            recorded.requested_launcher_generation,
                (unsigned int)sizeof(recorded.requested_launcher_generation),
            recorded.runtime_generation, (unsigned int)sizeof(recorded.runtime_generation),
            &count,
            recorded.entries[0].name, (unsigned int)sizeof(recorded.entries[0].name),
            &recorded.entries[0].volume_serial, &recorded.entries[0].file_index_high,
            &recorded.entries[0].file_index_low,
            recorded.entries[1].name, (unsigned int)sizeof(recorded.entries[1].name),
            &recorded.entries[1].volume_serial, &recorded.entries[1].file_index_high,
            &recorded.entries[1].file_index_low,
            recorded.entries[2].name, (unsigned int)sizeof(recorded.entries[2].name),
            &recorded.entries[2].volume_serial, &recorded.entries[2].file_index_high,
            &recorded.entries[2].file_index_low,
            &consumed) != 17 || consumed != (int)length
        || count == 0U || count > WLS_NGINX_PID_RESIDUE_MAX
        || strcmp(recorded.platform, "windows-job") != 0
        || strcmp(recorded.service_id, wls_service_id) != 0
        || !wls_is_hex(recorded.requested_launcher_generation, 64U)
        || !wls_is_hex(recorded.runtime_generation, 64U)
        || sodium_memcmp(recorded.requested_launcher_generation,
            wls_launcher_generation, 64U) == 0) goto cleanup;
    recorded.count = count;
    for (unsigned int index = 0U; index < recorded.count; index++) {
        if (!wls_nginx_pid_residue_intent_name_valid(recorded.entries[index].name)) {
            goto cleanup;
        }
        for (unsigned int previous = 0U; previous < index; previous++) {
            if (strcmp(recorded.entries[index].name,
                    recorded.entries[previous].name) == 0) goto cleanup;
        }
    }
    for (unsigned int index = count; index < WLS_NGINX_PID_RESIDUE_MAX; index++) {
        if (strcmp(recorded.entries[index].name, "-") != 0
            || recorded.entries[index].volume_serial != 0U
            || recorded.entries[index].file_index_high != 0U
            || recorded.entries[index].file_index_low != 0U) goto cleanup;
    }
    if (wls_validate_pending_retirement_for_pid_residue(home, &recorded) != 0) {
        goto cleanup;
    }
    if (wls_nginx_pid_residue_scan(home, actual.entries, &actual.count) != 0) {
        goto cleanup;
    }
    for (unsigned int index = 0U; index < actual.count; index++) {
        unsigned int match = 0U;
        for (unsigned int candidate = 0U; candidate < recorded.count; candidate++) {
            if (strcmp(actual.entries[index].name, recorded.entries[candidate].name) == 0
                && actual.entries[index].volume_serial
                    == recorded.entries[candidate].volume_serial
                && actual.entries[index].file_index_high
                    == recorded.entries[candidate].file_index_high
                && actual.entries[index].file_index_low
                    == recorded.entries[candidate].file_index_low) match = 1U;
        }
        if (!match) goto cleanup; /* present unknown/mismatch is never deletable */
    }
    if (actual.count > 0U
        && wls_nginx_pid_residue_directory(home, &directory) != 0) goto cleanup;
    for (unsigned int index = 0U; index < actual.count; index++) {
        wchar_t name[WLS_NGINX_PID_RESIDUE_NAME_MAX + 1U];
        HANDLE leaf = INVALID_HANDLE_VALUE;
        FILE_DISPOSITION_INFO disposition;
        struct wls_nginx_pid_residue_entry verified;
        ZeroMemory(name, sizeof(name));
        ZeroMemory(&disposition, sizeof(disposition));
        if (MultiByteToWideChar(CP_UTF8, MB_ERR_INVALID_CHARS,
                actual.entries[index].name, -1, name,
                (int)(sizeof(name) / sizeof(name[0]))) <= 1
            || wls_nginx_pid_residue_leaf(directory, name, &verified) != 0
            || verified.volume_serial != actual.entries[index].volume_serial
            || verified.file_index_high != actual.entries[index].file_index_high
            || verified.file_index_low != actual.entries[index].file_index_low) goto cleanup;
        leaf = wls_nt_open_child(directory, name, wcslen(name),
            GENERIC_READ | DELETE | READ_CONTROL,
            FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE, FILE_OPEN, 0);
        disposition.DeleteFile = TRUE;
        if (leaf == INVALID_HANDLE_VALUE || wls_launcher_handle_is_reparse(leaf)
            || !SetFileInformationByHandle(
                leaf, FileDispositionInfo, &disposition, sizeof(disposition)
            ) || !CloseHandle(leaf)) {
            if (leaf != INVALID_HANDLE_VALUE) CloseHandle(leaf);
            goto cleanup;
        }
        leaf = INVALID_HANDLE_VALUE;
        if (wls_launcher_flush_verified_directory(directory) != 0) goto cleanup;
    }
    if (wls_nginx_pid_residue_scan(home, actual.entries, &actual.count) != 0
        || actual.count != 0U) goto cleanup;
    if (!DeleteFileW(path) || wls_flush_trust_directory(home) != 0) goto cleanup;
    result = 0;
cleanup:
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    SecureZeroMemory(contents, sizeof(contents));
    SecureZeroMemory(&recorded, sizeof(recorded));
    SecureZeroMemory(&actual, sizeof(actual));
    return result;
}

static int wls_upgrade_state_read(
    const wchar_t *home,
    const struct wls_upgrade *upgrade,
    struct wls_upgrade_state *state
) {
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char from[2];
    char to[2];
    int consumed = 0;
    int fields;
    long long legacy_total_deadline = 0;
    long long expected_legacy_total_deadline = 0;
    int rollback_phase;
    ZeroMemory(state, sizeof(*state));
    if (wls_join(path, WLS_PATH_CHARS, home, L"trust\\upgrade-state") != 0) return -1;
    if (wls_read_file(path, 1024U, &contents, &length) != 0) {
        DWORD error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND ? 0 : -1;
    }
    if (strncmp((const char *)contents, "WLS-UPGRADE-STATE/3\n", 20U) == 0) {
        fields = sscanf(
            (const char *)contents,
            "WLS-UPGRADE-STATE/3\n"
            "intent_sha256=%64[0-9a-f]\n"
            "intent_nonce=%32[0-9a-f]\n"
            "from=%1[AB]\n"
            "to=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "boot_id=%64[0-9a-f]\n"
            "phase=%23[A-Z_]\n"
            "attempts=%u\n"
            "prepared_monotonic_ms=%llu\n"
            "observation_started_monotonic_ms=%llu\n"
            "observation_deadline_monotonic_ms=%llu\n"
            "total_deadline_monotonic_ms=%llu\n%n",
            state->intent_sha256, state->nonce, from, to,
            state->runtime_generation, state->boot_id, state->phase,
            &state->attempts, &state->prepared_monotonic,
            &state->observation_started, &state->observation_deadline,
            &state->total_deadline_monotonic, &consumed
        );
        state->legacy_protocol = 0;
    } else {
        fields = sscanf(
            (const char *)contents,
            "WLS-UPGRADE-STATE/2\n"
            "intent_sha256=%64[0-9a-f]\n"
            "intent_nonce=%32[0-9a-f]\n"
            "from=%1[AB]\n"
            "to=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "boot_id=%64[0-9A-Za-z-]\n"
            "phase=%23[A-Z_]\n"
            "attempts=%u\n"
            "observation_started_monotonic_ms=%llu\n"
            "observation_deadline_monotonic_ms=%llu\n"
            "total_deadline=%lld\n%n",
            state->intent_sha256, state->nonce, from, to,
            state->runtime_generation, state->boot_id, state->phase,
            &state->attempts, &state->observation_started,
            &state->observation_deadline, &legacy_total_deadline, &consumed
        );
        state->legacy_protocol = 1;
    }
    HeapFree(GetProcessHeap(), 0U, contents);
    rollback_phase = strcmp(state->phase, "ROLLBACK_PENDING") == 0
        || strcmp(state->phase, "ROLLED_BACK") == 0;
    if ((state->legacy_protocol ? fields != 11 : fields != 12)
        || consumed != (int)length
        || strcmp(state->intent_sha256, upgrade->intent_sha256) != 0
        || strcmp(state->nonce, upgrade->nonce) != 0
        || (wchar_t)from[0] != upgrade->from || (wchar_t)to[0] != upgrade->to
        || strcmp(state->runtime_generation, upgrade->runtime_generation) != 0
        || state->attempts > WLS_UPGRADE_MAX_ATTEMPTS
        || (state->legacy_protocol
            ? (!upgrade->legacy_protocol
                || wls_checked_add_long_long(
                    upgrade->prepared_at,
                    WLS_UPGRADE_TOTAL_SECONDS,
                    &expected_legacy_total_deadline
                ) != 0
                || legacy_total_deadline != expected_legacy_total_deadline)
            : (upgrade->legacy_protocol
                ? (state->prepared_monotonic == 0ULL
                    || state->prepared_monotonic
                        > ULLONG_MAX - WLS_UPGRADE_TOTAL_MILLISECONDS
                    || state->total_deadline_monotonic
                        != state->prepared_monotonic
                            + WLS_UPGRADE_TOTAL_MILLISECONDS)
                : ((!rollback_phase
                        && strcmp(state->boot_id, upgrade->boot_id) != 0)
                    || state->prepared_monotonic != upgrade->prepared_monotonic
                    || state->total_deadline_monotonic
                        != upgrade->total_deadline_monotonic)))
        || (strcmp(state->phase, "PREPARED") != 0
            && strcmp(state->phase, "OBSERVING") != 0
            && strcmp(state->phase, "HEALTHY") != 0
            && strcmp(state->phase, "ROLLBACK_PENDING") != 0
            && strcmp(state->phase, "ROLLED_BACK") != 0
            && strcmp(state->phase, "COMMITTED") != 0)) return -1;
    state->from = (wchar_t)from[0];
    state->to = (wchar_t)to[0];
    state->present = 1;
    return 1;
}

static int wls_upgrade_state_write(
    const wchar_t *home,
    const struct wls_upgrade *upgrade,
    const char *boot_id,
    const char *phase,
    unsigned int attempts,
    unsigned long long observation_started,
    unsigned long long observation_deadline
) {
    wchar_t path[WLS_PATH_CHARS];
    char payload[768];
    int length;
    unsigned long long expected_observation_deadline = 0ULL;
    if (attempts > WLS_UPGRADE_MAX_ATTEMPTS
        || phase == NULL
        || !wls_is_hex(boot_id, 64U)
        || upgrade->prepared_monotonic == 0ULL
        || upgrade->prepared_monotonic
            > ULLONG_MAX - WLS_UPGRADE_TOTAL_MILLISECONDS
        || upgrade->total_deadline_monotonic
            != upgrade->prepared_monotonic + WLS_UPGRADE_TOTAL_MILLISECONDS
        || wls_join(path, WLS_PATH_CHARS, home, L"trust\\upgrade-state") != 0) return 1;
    if (strcmp(phase, "OBSERVING") == 0
        || strcmp(phase, "HEALTHY") == 0
        || strcmp(phase, "COMMITTED") == 0) {
        if (observation_started == 0ULL
            || wls_checked_add_unsigned_long_long(
                observation_started,
                WLS_UPGRADE_OBSERVATION_MILLISECONDS,
                &expected_observation_deadline
            ) != 0
            || observation_deadline != expected_observation_deadline) {
            return 1;
        }
    } else if (observation_started != 0ULL || observation_deadline != 0ULL) {
        return 1;
    }
    length = _snprintf_s(
        payload,
        sizeof(payload),
        _TRUNCATE,
        "WLS-UPGRADE-STATE/3\n"
        "intent_sha256=%s\nintent_nonce=%s\n"
        "from=%c\nto=%c\nruntime_generation=%s\n"
        "boot_id=%s\nphase=%s\nattempts=%u\n"
        "prepared_monotonic_ms=%llu\n"
        "observation_started_monotonic_ms=%llu\n"
        "observation_deadline_monotonic_ms=%llu\n"
        "total_deadline_monotonic_ms=%llu\n",
        upgrade->intent_sha256, upgrade->nonce,
        (char)upgrade->from, (char)upgrade->to,
        upgrade->runtime_generation, boot_id, phase, attempts,
        upgrade->prepared_monotonic, observation_started, observation_deadline,
        upgrade->total_deadline_monotonic
    );
    return length > 0 ? wls_atomic_text(path, payload) : 1;
}

static int wls_upgrade_rollback_healthy(
    const wchar_t *home,
    const struct wls_upgrade *upgrade,
    const char *boot_id
) {
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char digest[65], nonce[33], from[2], to[2], runtime[65], marker_boot[65];
    unsigned long long started = 0ULL, healthy = 0ULL;
    unsigned long long expected_healthy = 0ULL;
    unsigned long long monotonic_now = 0ULL;
    int consumed = 0;
    int result = 0;
    if (wls_protocol_monotonic_milliseconds(&monotonic_now) != 0
        || wls_join(path, WLS_PATH_CHARS, home,
            L"trust\\upgrade-rollback-healthy") != 0
        || wls_read_file(path, 768U, &contents, &length) != 0) return 0;
    if (sscanf(
        (const char *)contents,
        "WLS-UPGRADE-ROLLBACK-HEALTHY/2\n"
        "intent_sha256=%64[0-9a-f]\nintent_nonce=%32[0-9a-f]\n"
        "from=%1[AB]\nto=%1[AB]\n"
        "active_runtime_generation=%64[0-9a-f]\n"
        "boot_id=%64[0-9A-Za-z-]\n"
        "started_monotonic_ms=%llu\nhealthy_monotonic_ms=%llu\n%n",
        digest, nonce, from, to, runtime, marker_boot,
        &started, &healthy, &consumed
    ) == 8 && consumed == (int)length
        && strcmp(digest, upgrade->intent_sha256) == 0
        && strcmp(nonce, upgrade->nonce) == 0
        && (wchar_t)from[0] == upgrade->from
        && (wchar_t)to[0] == upgrade->to
        && wls_is_hex(runtime, 64U)
        && strcmp(marker_boot, boot_id) == 0
        && started > 0ULL
        && wls_checked_add_unsigned_long_long(
            started,
            WLS_ROLLBACK_HEALTH_MILLISECONDS,
            &expected_healthy
        ) == 0
        && healthy >= expected_healthy
        && healthy <= monotonic_now) result = 1;
    HeapFree(GetProcessHeap(), 0U, contents);
    return result;
}

static int wls_upgrade_rollback_requested(
    const wchar_t *home,
    const struct wls_upgrade *upgrade
) {
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char from[2];
    char to[2];
    char request_boot_id[65];
    unsigned long long requested_monotonic = 0ULL;
    long long legacy_at = 0;
    char intent_digest[65];
    char intent_nonce[33];
    char request_nonce[33];
    DWORD attributes;
    int consumed = 0;
    int result = 0;
    if (wls_join(
        path,
        WLS_PATH_CHARS,
        home,
        L"state\\upgrade-rollback.request"
    ) != 0) {
        return -1;
    }
    attributes = GetFileAttributesW(path);
    if (attributes == INVALID_FILE_ATTRIBUTES) {
        DWORD error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND ? 0 : -1;
    }
    if ((attributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0
        || (attributes & FILE_ATTRIBUTE_DIRECTORY) != 0) {
        return -1;
    }
    if (wls_read_file(path, 512U, &contents, &length) != 0) {
        return -1;
    }
    if (!upgrade->legacy_protocol && sscanf(
            (const char *)contents,
            "WLS-UPGRADE-ROLLBACK/3\n"
            "intent_sha256=%64[0-9a-f]\n"
            "intent_nonce=%32[0-9a-f]\n"
            "from=%1[AB]\nto=%1[AB]\n"
            "host_boot_id=%64[0-9a-f]\n"
            "requested_monotonic_ms=%llu\n"
            "request_nonce=%32[0-9a-f]\n%n",
            intent_digest, intent_nonce, from, to, request_boot_id,
            &requested_monotonic, request_nonce, &consumed
        ) == 7
        && consumed == (int)length
        && strcmp(intent_digest, upgrade->intent_sha256) == 0
        && strcmp(intent_nonce, upgrade->nonce) == 0
        && (wchar_t)from[0] == upgrade->to
        && (wchar_t)to[0] == upgrade->from
        && strcmp(request_boot_id, upgrade->boot_id) == 0
        && requested_monotonic >= upgrade->prepared_monotonic
        && requested_monotonic <= upgrade->total_deadline_monotonic
        && wls_is_hex(request_nonce, 32U)) {
        result = 1;
    } else if (upgrade->legacy_protocol) {
        consumed = 0;
        if (sscanf(
                (const char *)contents,
                "WLS-UPGRADE-ROLLBACK/2\n"
                "intent_sha256=%64[0-9a-f]\n"
                "intent_nonce=%32[0-9a-f]\n"
                "from=%1[AB]\nto=%1[AB]\nat=%lld\n"
                "request_nonce=%32[0-9a-f]\n%n",
                intent_digest, intent_nonce, from, to, &legacy_at,
                request_nonce, &consumed
            ) == 6
            && consumed == (int)length
            && strcmp(intent_digest, upgrade->intent_sha256) == 0
            && strcmp(intent_nonce, upgrade->nonce) == 0
            && (wchar_t)from[0] == upgrade->to
            && (wchar_t)to[0] == upgrade->from
            && legacy_at > 0 && wls_is_hex(request_nonce, 32U)) {
            result = 1;
        }
    }
    if (contents != NULL) HeapFree(GetProcessHeap(), 0U, contents);
    return result == 1 ? 1 : -1;
}

static int wls_reconcile_upgrade_locked(
    const wchar_t *home,
    wchar_t active[2],
    int count_candidate_failure
)
{
    struct wls_upgrade upgrade;
    struct wls_upgrade_state transaction;
    wchar_t active_path[WLS_PATH_CHARS];
    wchar_t previous_path[WLS_PATH_CHARS];
    wchar_t intent_path[WLS_PATH_CHARS];
    wchar_t healthy_path[WLS_PATH_CHARS];
    wchar_t observing_path[WLS_PATH_CHARS];
    wchar_t rollback_path[WLS_PATH_CHARS];
    wchar_t rollback_healthy_path[WLS_PATH_CHARS];
    wchar_t retention_path[WLS_PATH_CHARS];
    wchar_t rolled_back_path[WLS_PATH_CHARS];
    wchar_t state_path[WLS_PATH_CHARS];
    char boot_id[65];
    long long now = (long long)time(NULL);
    unsigned long long monotonic_now = 0ULL;
    char record[640];
    long long retained_at;
    unsigned long long retained_since_monotonic;
    int record_length;
    int rollback_requested;
    int observation_present;
    unsigned long long observation_started = 0ULL;
    unsigned long long observation_deadline = 0ULL;
    int intent_status;
    int state_status;
    unsigned int attempts;
    int must_rollback = 0;
    int rollback_transitioned = 0;
    int rollback_target_verified = 0;
    unsigned long long expected_observation_deadline = 0ULL;
    if (wls_active_slot(home, active) != 0) return 1;
    intent_status = wls_upgrade_intent(home, &upgrade);
    if (intent_status < 0) return 1;
    if (intent_status == 0 || !upgrade.present) return 0;
    if (now < 0 || wls_boot_id(boot_id) != 0
        || wls_protocol_monotonic_milliseconds(&monotonic_now) != 0
        || wls_join(active_path, WLS_PATH_CHARS, home, L"trust\\active-slot") != 0
        || wls_join(previous_path, WLS_PATH_CHARS, home, L"trust\\previous-slot") != 0
        || wls_join(intent_path, WLS_PATH_CHARS, home, L"trust\\upgrade.intent") != 0
        || wls_join(healthy_path, WLS_PATH_CHARS, home, L"trust\\upgrade-healthy") != 0
        || wls_join(observing_path, WLS_PATH_CHARS, home, L"trust\\upgrade-observing") != 0
        || wls_join(retention_path, WLS_PATH_CHARS, home, L"trust\\slot-retention") != 0
        || wls_join(rolled_back_path, WLS_PATH_CHARS, home, L"trust\\upgrade-rolled-back") != 0
        || wls_join(state_path, WLS_PATH_CHARS, home, L"trust\\upgrade-state") != 0
        || wls_join(rollback_path, WLS_PATH_CHARS, home, L"state\\upgrade-rollback.request") != 0
        || wls_join(rollback_healthy_path, WLS_PATH_CHARS, home,
            L"trust\\upgrade-rollback-healthy") != 0) {
        return 1;
    }
    if (upgrade.legacy_protocol) {
        memcpy(upgrade.boot_id, boot_id, sizeof(upgrade.boot_id));
        upgrade.prepared_monotonic = monotonic_now;
        upgrade.activation_deadline_monotonic = monotonic_now
            + WLS_UPGRADE_ACTIVATION_MILLISECONDS;
        upgrade.total_deadline_monotonic = monotonic_now
            + WLS_UPGRADE_TOTAL_MILLISECONDS;
        must_rollback = 1;
    } else if (strcmp(upgrade.boot_id, boot_id) != 0
        || monotonic_now < upgrade.prepared_monotonic) {
        must_rollback = 1;
    }
    state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
    if (state_status < 0) return 1;
    if (state_status > 0 && strcmp(transaction.boot_id, boot_id) == 0) {
        if (strcmp(transaction.phase, "OBSERVING") == 0
            || strcmp(transaction.phase, "HEALTHY") == 0
            || strcmp(transaction.phase, "COMMITTED") == 0) {
            if (transaction.observation_started == 0ULL
                || wls_checked_add_unsigned_long_long(
                    transaction.observation_started,
                    WLS_UPGRADE_OBSERVATION_MILLISECONDS,
                    &expected_observation_deadline
                ) != 0
                || transaction.observation_started > monotonic_now
                || transaction.observation_started
                    > upgrade.activation_deadline_monotonic
                || transaction.observation_deadline
                    != expected_observation_deadline
                || ((strcmp(transaction.phase, "HEALTHY") == 0
                        || strcmp(transaction.phase, "COMMITTED") == 0)
                    && transaction.observation_deadline > monotonic_now)) {
                return 1;
            }
        } else if (transaction.observation_started != 0ULL
            || transaction.observation_deadline != 0ULL) {
            return 1;
        }
    }
    rollback_requested = wls_upgrade_rollback_requested(home, &upgrade);
    if (rollback_requested < 0) return 1;
    if (state_status > 0 && strcmp(transaction.boot_id, boot_id) != 0
        && strcmp(transaction.phase, "COMMITTED") != 0
        && strcmp(transaction.phase, "ROLLED_BACK") != 0) {
        attempts = transaction.attempts + 1U;
        must_rollback = 1;
        if (active[0] == upgrade.from
            || strcmp(transaction.phase, "ROLLBACK_PENDING") == 0) {
            if (wls_require_rollback_slot_contract_v2(
                    home, upgrade.from, &rollback_target_verified
                ) != 0
                || wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLBACK_PENDING",
                    attempts > WLS_UPGRADE_MAX_ATTEMPTS
                        ? WLS_UPGRADE_MAX_ATTEMPTS : attempts,
                    0ULL, 0ULL
                ) != 0) return 1;
            rollback_transitioned = 1;
        }
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return 1;
    }
    if (active[0] == upgrade.from) {
        if (wls_require_rollback_slot_contract_v2(
                home, upgrade.from, &rollback_target_verified
            ) != 0) return 1;
        if (state_status == 0) {
            /* The shared package lock makes this a completed/incomplete
             * transaction, never a concurrently active preactivation writer. */
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLBACK_PENDING", 0U, 0ULL, 0ULL
                ) != 0) return 1;
            rollback_transitioned = 1;
            state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
            if (state_status < 1) return 1;
        } else if (strcmp(transaction.phase, "ROLLED_BACK") != 0
            && strcmp(transaction.phase, "ROLLBACK_PENDING") != 0) {
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLBACK_PENDING",
                    transaction.attempts, 0ULL, 0ULL
                ) != 0) return 1;
            rollback_transitioned = 1;
            state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
            if (state_status < 1) return 1;
        }
        if (strcmp(transaction.phase, "ROLLBACK_PENDING") == 0
            || strcmp(transaction.phase, "ROLLED_BACK") == 0) {
            char previous_text[3] = {(char)upgrade.to, '\n', '\0'};
            wchar_t verified_previous = L'\0';
            if (wls_atomic_text(previous_path, previous_text) != 0
                || wls_read_slot_pointer(
                    previous_path,
                    &verified_previous
                ) != 0
                || verified_previous != upgrade.to) {
                return 1;
            }
        }
        if (strcmp(transaction.phase, "ROLLBACK_PENDING") == 0) {
            if (!wls_upgrade_rollback_healthy(home, &upgrade, boot_id)) {
                return rollback_transitioned ? 3 : 0;
            }
            if (_snprintf_s(
                    record, sizeof(record), _TRUNCATE,
                    "WLS-UPGRADE-ROLLED-BACK/3\n"
                    "intent_sha256=%s\nintent_nonce=%s\n"
                    "from=%c\nto=%c\nruntime_generation=%s\nat=%lld\n",
                    upgrade.intent_sha256, upgrade.nonce,
                    (char)upgrade.from, (char)upgrade.to,
                    upgrade.runtime_generation, now
                ) < 0
                || wls_atomic_text(rolled_back_path, record) != 0
                || wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLED_BACK",
                    transaction.attempts, 0ULL, 0ULL
                ) != 0) return 1;
        }
        if (wls_delete_optional_durable(rollback_path) != 0
            || wls_delete_optional_durable(healthy_path) != 0
            || wls_delete_optional_durable(observing_path) != 0
            || wls_delete_optional_durable(rollback_healthy_path) != 0
            || wls_delete_optional_durable(intent_path) != 0
            || wls_delete_optional_durable(state_path) != 0) {
            return 1;
        }
        return 0;
    }
    if (active[0] != upgrade.to) return 1;
    if (state_status == 0) {
        if ((must_rollback
                && wls_require_rollback_slot_contract_v2(
                    home, upgrade.from, &rollback_target_verified
                ) != 0)
            || wls_upgrade_state_write(
                home,
                &upgrade,
                boot_id,
                must_rollback ? "ROLLBACK_PENDING" : "PREPARED",
                0U,
                0ULL,
                0ULL
            ) != 0) return 1;
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return 1;
    }
    if (strcmp(transaction.phase, "COMMITTED") == 0) {
        if (wls_delete_optional_durable(rollback_path) != 0
            || wls_delete_optional_durable(healthy_path) != 0
            || wls_delete_optional_durable(observing_path) != 0
            || wls_delete_optional_durable(intent_path) != 0
            || wls_delete_optional_durable(state_path) != 0) {
            return 1;
        }
        return 0;
    }
    if (strcmp(transaction.phase, "ROLLBACK_PENDING") == 0) must_rollback = 1;
    observation_present = !must_rollback
        ? wls_upgrade_observation_deadline(
            home, &upgrade, &transaction, boot_id,
            &observation_started, &observation_deadline
        )
        : 0;
    if (observation_present && strcmp(transaction.phase, "PREPARED") == 0) {
        if (wls_upgrade_state_write(
                home, &upgrade, boot_id, "OBSERVING", transaction.attempts,
                observation_started, observation_deadline
            ) != 0) return 1;
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return 1;
    }
    if (!must_rollback && !rollback_requested
        && (strcmp(transaction.phase, "HEALTHY") == 0
            || wls_upgrade_healthy(home, &upgrade, &transaction, boot_id))) {
        if (strcmp(transaction.phase, "HEALTHY") != 0
            && wls_upgrade_state_write(
                home, &upgrade, boot_id, "HEALTHY", transaction.attempts,
                transaction.observation_started, transaction.observation_deadline
            ) != 0) return 1;
        retained_at = (long long)time(NULL);
        if (wls_protocol_monotonic_milliseconds(
                &retained_since_monotonic
            ) != 0) {
            return 1;
        }
        if (retained_at < 0
            || retained_at > LLONG_MAX - WLS_SLOT_RETENTION_SECONDS
            || retained_since_monotonic
                > ULLONG_MAX - WLS_SLOT_RETENTION_MILLISECONDS) {
            return 1;
        }
        record_length = _snprintf_s(
            record, sizeof(record), _TRUNCATE,
            "WLS-SLOT-RETENTION/3\n"
            "intent_sha256=%s\nintent_nonce=%s\n"
            "slot=%c\nboot_id=%s\n"
            "retained_at=%lld\nretain_until=%lld\n"
            "retained_since_monotonic_ms=%llu\n"
            "retain_until_monotonic_ms=%llu\n",
            upgrade.intent_sha256, upgrade.nonce,
            (char)upgrade.from,
            boot_id,
            retained_at,
            retained_at + WLS_SLOT_RETENTION_SECONDS,
            retained_since_monotonic,
            retained_since_monotonic + WLS_SLOT_RETENTION_MILLISECONDS
        );
        if (record_length <= 0
            || record_length >= (int)sizeof(record)
            || wls_atomic_text(retention_path, record) != 0
            || wls_upgrade_state_write(
                home, &upgrade, boot_id, "COMMITTED", transaction.attempts,
                transaction.observation_started, transaction.observation_deadline
            ) != 0) {
            return 1;
        }
        if (wls_delete_optional_durable(rollback_path) != 0
            || wls_delete_optional_durable(healthy_path) != 0
            || wls_delete_optional_durable(observing_path) != 0
            || wls_delete_optional_durable(intent_path) != 0
            || wls_delete_optional_durable(state_path) != 0) {
            return 1;
        }
        return 0;
    }
    if (!must_rollback && !rollback_requested) {
        if (strcmp(transaction.phase, "PREPARED") == 0
            && monotonic_now >= upgrade.activation_deadline_monotonic) {
            must_rollback = 1;
        } else if (strcmp(transaction.phase, "OBSERVING") == 0
            && monotonic_now >= transaction.observation_deadline) {
            must_rollback = 1;
        }
    }
    if (count_candidate_failure) {
        attempts = transaction.attempts + 1U;
        if (attempts >= WLS_UPGRADE_MAX_ATTEMPTS) must_rollback = 1;
        else if (!must_rollback) {
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "PREPARED", attempts, 0ULL, 0ULL
                ) != 0) return 1;
            (void)wls_delete_optional(observing_path);
            (void)wls_delete_optional(healthy_path);
            return 0;
        }
    }
    if (rollback_requested
        || upgrade.legacy_protocol
        || strcmp(upgrade.boot_id, boot_id) != 0
        || monotonic_now >= upgrade.total_deadline_monotonic) {
        must_rollback = 1;
    }
    if (must_rollback) {
        char active_text[3] = {(char)upgrade.from, '\n', '\0'};
        char previous_text[3] = {(char)upgrade.to, '\n', '\0'};
        attempts = transaction.attempts + (count_candidate_failure ? 1U : 0U);
        if (attempts > WLS_UPGRADE_MAX_ATTEMPTS) attempts = WLS_UPGRADE_MAX_ATTEMPTS;
        if (wls_require_rollback_slot_contract_v2(
                home, upgrade.from, &rollback_target_verified
            ) != 0
            || wls_upgrade_state_write(
                home, &upgrade, boot_id, "ROLLBACK_PENDING", attempts, 0ULL, 0ULL
            ) != 0
            || wls_atomic_text(active_path, active_text) != 0
            || wls_atomic_text(previous_path, previous_text) != 0) return 1;
        active[0] = upgrade.from;
        (void)wls_delete_optional(healthy_path);
        (void)wls_delete_optional(observing_path);
        (void)wls_delete_optional(rollback_healthy_path);
        return 0;
    }
    return 0;
}

static int wls_reconcile_upgrade(
    const wchar_t *home,
    wchar_t active[2],
    int count_candidate_failure,
    int wait_for_lock
)
{
    HANDLE lock_handle = INVALID_HANDLE_VALUE;
    int result;
    int lock_status = wls_package_lock_acquire(home, &lock_handle, wait_for_lock);
    if (lock_status != 0) return lock_status;
    result = wls_reconcile_upgrade_locked(
        home,
        active,
        count_candidate_failure
    );
    if (wls_package_lock_release(lock_handle) != 0) return 1;
    return result;
}

/*
 * Runtime monitoring never increments the candidate crash counter. Candidate
 * failures are recorded only after an unexpected broker exit; clean platform
 * stop/start cycles therefore preserve the whole observation budget.
 */
static int wls_monitor_upgrade(const wchar_t *home, wchar_t active[2])
{
    wchar_t before;
    int result;
    before = active[0];
    result = wls_reconcile_upgrade(home, active, 0, 0);
    if (result == 2) return 0;
    if (result == 1) return -1;
    return result == 3 || active[0] != before ? 1 : 0;
}

#include "wls_gateway_guardian.inc"
#ifdef WLS_GUARDIAN_EXECUTABLE
#include "wls_gateway_guardian_protocol.inc"
#include "wls_gateway_guardian_recovery.inc"
#include "wls_gateway_guardian_runtime.inc"
#endif

static HANDLE wls_open_verified_job_nginx(
    const wchar_t *home,
    HANDLE supervision_job,
    HANDLE data_plane_job,
    DWORD expected_pid,
    const wchar_t *active_slot,
    const char *expected_runtime_generation,
    DWORD *verified_pid
) {
    wchar_t pid_path[WLS_PATH_CHARS];
    wchar_t actual[WLS_PATH_CHARS];
    wchar_t expected[WLS_PATH_CHARS];
    unsigned char *pid_text = NULL;
    size_t pid_length = 0U;
    char runtime_generation[65];
    char *end = NULL;
    unsigned long long parsed;
    DWORD actual_length = WLS_PATH_CHARS;
    BOOL belongs_to_supervision = FALSE;
    BOOL belongs_to_data_plane = FALSE;
    HANDLE process = NULL;
    if (verified_pid == NULL
        || supervision_job == NULL
        || data_plane_job == NULL
        || active_slot == NULL
        || (active_slot[0] != L'A' && active_slot[0] != L'B')
        || active_slot[1] != L'\0'
        || expected_runtime_generation == NULL
        || strlen(expected_runtime_generation) != 64U
        || wls_join(pid_path, WLS_PATH_CHARS, home, L"nginx-pid\\nginx.pid") != 0
        || wls_read_file(pid_path, 64U, &pid_text, &pid_length) != 0) {
        return NULL;
    }
    while (pid_length > 0U
        && strchr("\r\n \t", ((char *)pid_text)[pid_length - 1U]) != NULL) {
        pid_length--;
    }
    ((char *)pid_text)[pid_length] = '\0';
    parsed = _strtoui64((const char *)pid_text, &end, 10);
    HeapFree(GetProcessHeap(), 0U, pid_text);
    if (pid_length == 0U || end == NULL || *end != '\0'
        || parsed == 0ULL || parsed > MAXDWORD
        || (expected_pid > 0U && expected_pid != (DWORD)parsed)
        || _snwprintf_s(expected, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\%ls\\bin\\nginx.exe", home, active_slot) < 0) {
        return NULL;
    }
    process = OpenProcess(SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION, FALSE, (DWORD)parsed);
    if (process == NULL
        || !IsProcessInJob(
            process, supervision_job, &belongs_to_supervision
        )
        || !belongs_to_supervision
        || !IsProcessInJob(process, data_plane_job, &belongs_to_data_plane)
        || !belongs_to_data_plane
        || !QueryFullProcessImageNameW(process, 0U, actual, &actual_length)) {
        if (process != NULL) CloseHandle(process);
        return NULL;
    }
    if (_wcsicmp(actual, expected) != 0
        || wls_verify_slot_durable_state_contract_v2(
            home, active_slot[0], runtime_generation
        ) != 0
        || strcmp(runtime_generation, expected_runtime_generation) != 0) {
        CloseHandle(process);
        return NULL;
    }
    SecureZeroMemory(runtime_generation, sizeof(runtime_generation));
    *verified_pid = (DWORD)parsed;
    return process;
}

static int wls_force_terminate_process(HANDLE process, DWORD exit_code)
{
    DWORD state;
    if (process == NULL) return 1;
    state = WaitForSingleObject(process, 0U);
    if (state == WAIT_OBJECT_0) return 0;
    if (state != WAIT_TIMEOUT
        || !TerminateProcess(process, exit_code)
        || WaitForSingleObject(process, 5000U) != WAIT_OBJECT_0) {
        return 1;
    }
    return 0;
}

#ifdef WLS_NATIVE_TEST_HOOKS
static void wls_service_test_record_stage(
    const wchar_t *home,
    const wchar_t *stage
) {
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file;
    if (!wls_service_test_mode || home == NULL || stage == NULL
        || _snwprintf_s(
            path,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\state\\service-stage-%ls",
            home,
            stage
        ) < 0) return;
    file = CreateFileW(
        path,
        GENERIC_WRITE,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        CREATE_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE) return;
    (void)FlushFileBuffers(file);
    CloseHandle(file);
}
#else
#define wls_service_test_record_stage(home, stage) \
    do { (void)(home); (void)(stage); } while (0)
#endif

struct wls_launch_path_workspace {
    wchar_t slot[WLS_PATH_CHARS];
    wchar_t broker[WLS_PATH_CHARS];
    wchar_t php[WLS_PATH_CHARS];
    wchar_t controller[WLS_PATH_CHARS];
    wchar_t fencing[WLS_PATH_CHARS];
    wchar_t command[WLS_PATH_CHARS * 4U];
};

static int wls_launch_with_workspace(
    const wchar_t *home,
    const wchar_t *run_directory,
    HANDLE job,
    HANDLE data_plane_job,
    const wchar_t *data_plane_job_name,
    DWORD adopted_nginx_pid,
    DWORD *preserved_nginx_pid,
    struct wls_launch_path_workspace *workspace
)
{
    wchar_t active[2];
    wchar_t *slot;
    wchar_t *broker;
    wchar_t *php;
    wchar_t *controller;
    wchar_t *fencing;
    wchar_t stop_event_name[96];
    wchar_t ready_event_name[128];
    wchar_t ready_nonce_wide[33];
    wchar_t *command;
    char runtime_generation[65];
    unsigned char ready_nonce[16];
    char ready_nonce_hex[33];
    wchar_t runtime_generation_wide[65];
    STARTUPINFOW startup;
    PROCESS_INFORMATION process;
    SECURITY_ATTRIBUTES event_security_attributes;
    PSECURITY_DESCRIPTOR event_security_descriptor = NULL;
    HANDLE stop_event = NULL;
    HANDLE ready_event = NULL;
    HANDLE verified_nginx = NULL;
    struct wls_windows_recovery_context recovery;
    DWORD broker_exit = 1U;
    DWORD verified_nginx_pid = 0U;
    LONG handled_reload_generation = 0;
    ULONGLONG shutdown_started = 0U;
    ULONGLONG startup_started = 0U;
    ULONGLONG guardian_health_checked = 0U;
    ULONGLONG recovery_observation_started = 0U;
    int reload_authorized = 0;
    int reload_request_observed = 0;
    int reload_failed = 0;
    int automatic_launch_allowed;
    int reconcile_result;
    int recovery_prepare;
    int pid_evidence_state;
    wchar_t launched_slot;
    size_t ready_nonce_index;
    if (workspace == NULL) return 1;
    slot = workspace->slot;
    broker = workspace->broker;
    php = workspace->php;
    controller = workspace->controller;
    fencing = workspace->fencing;
    command = workspace->command;
    wls_service_test_record_stage(home, L"launch-entry");
    if (preserved_nginx_pid == NULL || job == NULL
        || data_plane_job == NULL
        || data_plane_job_name == NULL
        || wcsncmp(
            data_plane_job_name,
            L"Global\\WelineWlsGatewayV2DataPlane-",
            35U
        ) != 0
        || wcschr(data_plane_job_name, L'"') != NULL) return 1;
    *preserved_nginx_pid = 0U;
    if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) != 0) return 0;
    if (wls_admin_stopped(home) != 0) return 0;
    if (wls_active_slot(home, active) != 0) return 1;
    wls_service_test_record_stage(home, L"active-slot-ready");
    reconcile_result = wls_reconcile_upgrade(home, active, 0, 1);
    if (reconcile_result == 1 || reconcile_result == 2) return 1;
    wls_service_test_record_stage(home, L"reconcile-ready");
    if (wls_verify_slot_durable_state_contract_v2(
            home, active[0], runtime_generation
        ) != 0) return 1;
    wls_service_test_record_stage(home, L"durable-slot-ready");
    launched_slot = active[0];
    if (_snwprintf_s(
            slot,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%ls",
            home,
            active
        ) < 0
        || wls_utf8_to_wide(
            runtime_generation,
            runtime_generation_wide,
            65U
        ) != 0
        || wls_join(broker, WLS_PATH_CHARS, slot, L"bin\\wls-gateway-broker.exe") != 0
        || wls_join(php, WLS_PATH_CHARS, slot, L"bin\\php.exe") != 0
        || wls_join(controller, WLS_PATH_CHARS, slot, L"app\\controller.php") != 0
        || wls_join(fencing, WLS_PATH_CHARS, home, L"trust\\broker-fencing-token") != 0) {
        return 1;
    }
    randombytes_buf(ready_nonce, sizeof(ready_nonce));
    (void)sodium_bin2hex(
        ready_nonce_hex,
        sizeof(ready_nonce_hex),
        ready_nonce,
        sizeof(ready_nonce)
    );
    sodium_memzero(ready_nonce, sizeof(ready_nonce));
    for (ready_nonce_index = 0U; ready_nonce_index < 33U; ready_nonce_index++) {
        ready_nonce_wide[ready_nonce_index]
            = (wchar_t)(unsigned char)ready_nonce_hex[ready_nonce_index];
    }
    sodium_memzero(ready_nonce_hex, sizeof(ready_nonce_hex));
    if (_snwprintf_s(
            stop_event_name,
            sizeof(stop_event_name) / sizeof(stop_event_name[0]),
            _TRUNCATE,
            L"Local\\WelineWlsGatewayV2Stop-%lu",
            (unsigned long)GetCurrentProcessId()
        ) < 0
        || _snwprintf_s(
            ready_event_name,
            sizeof(ready_event_name) / sizeof(ready_event_name[0]),
            _TRUNCATE,
            L"Local\\WelineWlsGatewayV2Ready-%lu-%ls",
            (unsigned long)GetCurrentProcessId(),
            ready_nonce_wide
        ) < 0
        || wcschr(home, L'"') != NULL || wcschr(run_directory, L'"') != NULL
        || _snwprintf_s(
            command,
            WLS_PATH_CHARS * 4U,
            _TRUNCATE,
            L"\"%ls\" --serve "
            L"--admin-pipe \"\\\\.\\pipe\\weline-wls-gateway-v2-admin\" "
            L"--project-pipe \"\\\\.\\pipe\\weline-wls-gateway-v2-project\" "
            L"--fencing-file \"%ls\" --php \"%ls\" --controller \"%ls\" "
            L"--home \"%ls\" --active-slot \"%ls\" --runtime-generation \"%ls\" "
            L"--stop-event \"%ls\" --ready-event \"%ls\" "
            L"--data-plane-job \"%ls\" "
            L"--adopted-nginx-pid \"%lu\"",
            broker,
            fencing,
            php,
            controller,
            home,
            active,
            runtime_generation_wide,
            stop_event_name,
            ready_event_name,
            data_plane_job_name,
            (unsigned long)adopted_nginx_pid
        ) < 0) {
        return 1;
    }
    recovery_prepare = wls_recovery_prepare_attempt(
        home,
        run_directory,
        active[0],
        runtime_generation,
        &recovery
    );
    if (recovery_prepare == 1) return 0;
    if (recovery_prepare == 2) return (int)WLS_CONTROL_TREE_RELOAD;
    if (recovery_prepare != 0) return 1;
    wls_service_test_record_stage(home, L"recovery-ready");
    pid_evidence_state = wls_pid_residue_recovery_evidence_state(home);
    if (pid_evidence_state == WLS_PID_RESIDUE_EVIDENCE_UNSAFE) {
        (void)wls_recovery_finish_attempt(&recovery, 0, "SPAWN_FAILED");
        return 1;
    }
    if (InterlockedCompareExchange(
            &wls_authenticated_platform_generation, 0, 0
        ) == 0) {
        /* A direct binary may start only from a demonstrably clean namespace;
         * it must not consume or promote pending recovery evidence. */
        if (pid_evidence_state != WLS_PID_RESIDUE_EVIDENCE_CLEAN) {
            (void)wls_recovery_finish_attempt(&recovery, 0, "SPAWN_FAILED");
            return 1;
        }
        goto pid_residue_recovery_ready;
    }
    if (wls_consume_nginx_pid_residue_pending(home) != 0) {
        (void)wls_recovery_finish_attempt(
            &recovery, 0, "SPAWN_FAILED"
        );
        return 1;
    }
    if (wls_promote_platform_retirement(home, runtime_generation) != 0) {
        (void)wls_recovery_finish_attempt(
            &recovery, 0, "SPAWN_FAILED"
        );
        return 1;
    }
pid_residue_recovery_ready:
    wls_service_test_record_stage(home, L"pid-recovery-ready");
    ZeroMemory(&startup, sizeof(startup));
    ZeroMemory(&process, sizeof(process));
    ZeroMemory(&event_security_attributes, sizeof(event_security_attributes));
    startup.cb = sizeof(startup);
    event_security_attributes.nLength = sizeof(event_security_attributes);
    event_security_attributes.bInheritHandle = FALSE;
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"D:P(A;;GA;;;SY)(A;;GA;;;BA)",
            SDDL_REVISION_1,
            &event_security_descriptor,
            NULL
        )) {
        (void)wls_recovery_finish_attempt(
            &recovery, 0, "SPAWN_FAILED"
        );
        return 1;
    }
    event_security_attributes.lpSecurityDescriptor = event_security_descriptor;
    stop_event = CreateEventW(
        &event_security_attributes,
        TRUE,
        FALSE,
        stop_event_name
    );
    if (stop_event != NULL && GetLastError() == ERROR_ALREADY_EXISTS) {
        CloseHandle(stop_event);
        stop_event = NULL;
    }
    ready_event = CreateEventW(
        &event_security_attributes,
        TRUE,
        FALSE,
        ready_event_name
    );
    if (ready_event != NULL && GetLastError() == ERROR_ALREADY_EXISTS) {
        CloseHandle(ready_event);
        ready_event = NULL;
    }
    LocalFree(event_security_descriptor);
    event_security_descriptor = NULL;
    if (stop_event == NULL || ready_event == NULL) {
        if (stop_event != NULL) CloseHandle(stop_event);
        if (ready_event != NULL) CloseHandle(ready_event);
        (void)wls_recovery_finish_attempt(
            &recovery, 0, "SPAWN_FAILED"
        );
        return 1;
    }
    if (adopted_nginx_pid > 0U) {
        verified_nginx = wls_open_verified_job_nginx(
            home,
            job,
            data_plane_job,
            adopted_nginx_pid,
            active,
            runtime_generation,
            &verified_nginx_pid
        );
        if (verified_nginx == NULL || verified_nginx_pid != adopted_nginx_pid) {
            if (verified_nginx != NULL) CloseHandle(verified_nginx);
            CloseHandle(stop_event);
            CloseHandle(ready_event);
            (void)wls_recovery_finish_attempt(
                &recovery, 0, "SPAWN_FAILED"
            );
            return 1;
        }
        CloseHandle(verified_nginx);
        verified_nginx = NULL;
    }
    if (InterlockedCompareExchange(
            &wls_service_stop_requested, 0, 0
        ) != 0
        || wls_admin_stopped(home) != 0
        || wls_recovery_maintenance_pending(
            home, active[0], runtime_generation
        )) {
        CloseHandle(stop_event);
        CloseHandle(ready_event);
        (void)wls_recovery_finish_attempt(&recovery, 1, NULL);
        return 0;
    }
    if (wls_reload_pending()) {
        CloseHandle(stop_event);
        CloseHandle(ready_event);
        (void)wls_recovery_finish_attempt(&recovery, 1, NULL);
        return (int)WLS_CONTROL_TREE_RELOAD;
    }
    wls_service_test_record_stage(home, L"before-create-process");
    if (!CreateProcessW(
        broker,
        command,
        NULL,
        NULL,
        FALSE,
        CREATE_NO_WINDOW | CREATE_SUSPENDED,
        NULL,
        NULL,
        &startup,
        &process
    )) {
        CloseHandle(stop_event);
        CloseHandle(ready_event);
        (void)wls_recovery_finish_attempt(
            &recovery, 0, "SPAWN_FAILED"
        );
        return 1;
    }
    wls_service_test_record_stage(home, L"after-create-process");
    if (!AssignProcessToJobObject(job, process.hProcess)
        || ResumeThread(process.hThread) == (DWORD)-1) {
        (void)wls_force_terminate_process(process.hProcess, 1U);
        CloseHandle(process.hThread);
        CloseHandle(process.hProcess);
        CloseHandle(stop_event);
        CloseHandle(ready_event);
        (void)wls_recovery_finish_attempt(
            &recovery, 0, "SPAWN_FAILED"
        );
        return 1;
    }
    CloseHandle(process.hThread);
    wls_publish_broker_stop_event(stop_event);
    startup_started = GetTickCount64();
    for (;;) {
        DWORD wait_result = WaitForSingleObject(process.hProcess, 200U);
        int exact_ready = WaitForSingleObject(ready_event, 0U)
            == WAIT_OBJECT_0;
        int upgrade_state;
        if (wait_result == WAIT_OBJECT_0) {
            wchar_t before = active[0];
            LONG reload_generation = InterlockedCompareExchange(
                &wls_service_reload_generation,
                0,
                0
            );
            if (!GetExitCodeProcess(process.hProcess, &broker_exit)) {
                broker_exit = 1U;
                reload_failed = 1;
            }
            if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0
                && wls_admin_stopped(home) == 0) {
                if (reload_generation != InterlockedCompareExchange(
                    &wls_service_reload_consumed,
                    0,
                    0
                )) {
                    reload_authorized = 1;
                    reload_request_observed = 1;
                    handled_reload_generation = reload_generation;
                }
                reconcile_result = wls_reconcile_upgrade(home, active, 1, 1);
                if (reconcile_result == 1 || reconcile_result == 2) {
                    broker_exit = 1U;
                    reload_authorized = 0;
                    reload_failed = 1;
                } else if (reconcile_result == 3 || active[0] != before) {
                    reload_authorized = 1;
                }
            }
            break;
        }
        if (wait_result != WAIT_TIMEOUT) {
            (void)wls_force_terminate_process(process.hProcess, 1U);
            broker_exit = 1U;
            reload_failed = 1;
            break;
        }
        if (InterlockedCompareExchange(&wls_service_ready_reported, 0, 0) == 0) {
            // A v1 Broker cannot prove publication/config identity and must
            // never make SCM report RUNNING merely because a pipe and an
            // Nginx PID exist. The bounded timeout below forces a clean v2
            // rebootstrap instead of adopting unbound compatibility state.
            if (exact_ready) {
                InterlockedExchange(&wls_service_ready_reported, 1);
                if (wls_guardian_child_ready_event != NULL) {
                    (void)SetEvent(wls_guardian_child_ready_event);
                }
                wls_report_service(SERVICE_RUNNING, NO_ERROR, 0U);
            } else if (GetTickCount64() - startup_started >= 60000ULL) {
                (void)wls_force_terminate_process(process.hProcess, 1U);
                broker_exit = 1U;
                reload_failed = 1;
                break;
            } else {
                wls_report_service(SERVICE_START_PENDING, NO_ERROR, 0U);
            }
        }
        if (exact_ready && wls_guardian_child_health_event != NULL
            && (guardian_health_checked == 0U
                || GetTickCount64() - guardian_health_checked >= 5000ULL)) {
            verified_nginx = wls_open_verified_job_nginx(
                home,
                job,
                data_plane_job,
                0U,
                active,
                runtime_generation,
                &verified_nginx_pid
            );
            guardian_health_checked = GetTickCount64();
            if (verified_nginx != NULL) {
                CloseHandle(verified_nginx);
                verified_nginx = NULL;
                (void)SetEvent(wls_guardian_child_health_event);
            }
        }
        if (wls_recovery_observe_health(
                &recovery,
                exact_ready,
                &recovery_observation_started
            ) != 0) {
            (void)wls_force_terminate_process(process.hProcess, 1U);
            broker_exit = 1U;
            reload_failed = 1;
            break;
        }
        if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) != 0
            || wls_reload_pending()) {
            if (wls_reload_pending()) {
                reload_authorized = 1;
                reload_request_observed = 1;
                handled_reload_generation = InterlockedCompareExchange(
                    &wls_service_reload_generation,
                    0,
                    0
                );
            }
            if (shutdown_started == 0U) shutdown_started = GetTickCount64();
            if ((GetTickCount64() - shutdown_started) >= 15000U) {
                if (wls_force_terminate_process(process.hProcess, 1U) != 0) {
                    reload_authorized = 0;
                    reload_failed = 1;
                }
                broker_exit = 1U;
                break;
            }
        }
        upgrade_state = wls_monitor_upgrade(home, active);
        if (upgrade_state < 0) {
            (void)wls_force_terminate_process(process.hProcess, 1U);
            broker_exit = 1U;
            reload_authorized = 0;
            reload_failed = 1;
            break;
        }
        if (upgrade_state > 0) {
            /* Keep the service PID and shared Job while rebuilding the old slot. */
            reload_authorized = 1;
            SetEvent(stop_event);
            if (WaitForSingleObject(process.hProcess, 15000U) != WAIT_OBJECT_0
                && wls_force_terminate_process(process.hProcess, 1U) != 0) {
                reload_authorized = 0;
                reload_failed = 1;
            }
            break;
        }
    }
    if (WaitForSingleObject(process.hProcess, 0U) != WAIT_OBJECT_0) {
        broker_exit = 1U;
        reload_authorized = 0;
        reload_failed = 1;
    }
    automatic_launch_allowed = wls_admin_stopped(home) == 0;
    if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0
        && automatic_launch_allowed
        && !reload_failed
        && wls_reload_pending()) {
        reload_authorized = 1;
        reload_request_observed = 1;
        handled_reload_generation = InterlockedCompareExchange(
            &wls_service_reload_generation,
            0,
            0
        );
    }
    if (reload_authorized
        && !reload_failed
        && InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0
        && automatic_launch_allowed) {
        if (active[0] != launched_slot) {
            /* A binary slot transition must rebuild the whole data plane. The
             * old slot's Nginx cannot be adopted under the new manifest. */
            *preserved_nginx_pid = 0U;
        } else {
            verified_nginx = wls_open_verified_job_nginx(
                home,
                job,
                data_plane_job,
                0U,
            active,
            runtime_generation,
            &verified_nginx_pid
            );
            if (verified_nginx == NULL) {
                broker_exit = 1U;
                reload_authorized = 0;
            } else {
                CloseHandle(verified_nginx);
                *preserved_nginx_pid = verified_nginx_pid;
            }
        }
    }
    automatic_launch_allowed = wls_admin_stopped(home) == 0;
    if (!automatic_launch_allowed) {
        reload_authorized = 0;
    }
    if (reload_failed) {
        reload_authorized = 0;
    }
    broker_exit = wls_classify_broker_exit(
        broker_exit,
        InterlockedCompareExchange(&wls_service_stop_requested, 0, 0),
        automatic_launch_allowed,
        reload_authorized
    );
    if (broker_exit == WLS_SERVICE_TREE_RESTART) {
        if (wls_seal_platform_retirement_pending(
                home,
                runtime_generation
            ) != 0) {
            /* Without the durable V2 INDETERMINATE retirement receipt, an
             * nginx-pid intent would be unbound authority and is unsafe. */
            OutputDebugStringW(
                L"WLS Gateway could not seal pending process-tree retirement.\n"
            );
        } else if (wls_seal_nginx_pid_residue_pending(
                home, runtime_generation
            ) != 0) {
            OutputDebugStringW(
                L"WLS Gateway could not seal nginx pid staging recovery.\n"
            );
        }
    }
    if (broker_exit == WLS_CONTROL_TREE_RELOAD && reload_request_observed) {
        InterlockedExchange(
            &wls_service_reload_consumed,
            handled_reload_generation
        );
    }
    wls_unpublish_broker_stop_event(stop_event);
    CloseHandle(process.hProcess);
    CloseHandle(stop_event);
    CloseHandle(ready_event);
    if (wls_recovery_finish_attempt(
            &recovery,
            broker_exit == 0U
                || broker_exit == WLS_CONTROL_TREE_RELOAD
                || InterlockedCompareExchange(
                    &wls_service_stop_requested, 0, 0
                ) != 0
                || wls_admin_stopped(home) != 0
                || wls_recovery_maintenance_pending(
                    home, active[0], runtime_generation
                ),
            broker_exit == WLS_SERVICE_TREE_RESTART
                ? "SUPERVISION_FAILED" : "BROKER_EXIT"
        ) != 0
        && broker_exit != 0U) {
        broker_exit = 1U;
    }
    return broker_exit == 0U
        ? 0
        : (broker_exit <= 255U ? (int)broker_exit : 1);
}

static int wls_launch(
    const wchar_t *home,
    const wchar_t *run_directory,
    HANDLE job,
    HANDLE data_plane_job,
    const wchar_t *data_plane_job_name,
    DWORD adopted_nginx_pid,
    DWORD *preserved_nginx_pid
)
{
    struct wls_launch_path_workspace *workspace;
    int result;
    workspace = (struct wls_launch_path_workspace *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, sizeof(*workspace)
    );
    if (workspace == NULL) return 1;
    result = wls_launch_with_workspace(
        home,
        run_directory,
        job,
        data_plane_job,
        data_plane_job_name,
        adopted_nginx_pid,
        preserved_nginx_pid,
        workspace
    );
    SecureZeroMemory(workspace, sizeof(*workspace));
    HeapFree(GetProcessHeap(), 0U, workspace);
    return result;
}

static void wls_report_service_pending(DWORD state, DWORD wait_hint)
{
    wls_service_status.dwServiceType = SERVICE_WIN32_OWN_PROCESS;
    wls_service_status.dwCurrentState = state;
    wls_service_status.dwControlsAccepted = state == SERVICE_START_PENDING
        ? SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN
        : 0U;
    wls_service_status.dwWin32ExitCode = NO_ERROR;
    wls_service_status.dwServiceSpecificExitCode = 0U;
    wls_service_status.dwCheckPoint = ++wls_service_checkpoint;
    wls_service_status.dwWaitHint = wait_hint < 1000U ? 1000U : wait_hint;
    if (wls_status_handle != NULL) {
        SetServiceStatus(wls_status_handle, &wls_service_status);
    }
}

static void wls_report_service(
    DWORD state,
    DWORD win32_exit,
    DWORD service_specific_exit
)
{
    if (state == SERVICE_START_PENDING || state == SERVICE_STOP_PENDING) {
        wls_report_service_pending(state, 30000U);
        return;
    }
    wls_service_status.dwServiceType = SERVICE_WIN32_OWN_PROCESS;
    wls_service_status.dwCurrentState = state;
    wls_service_status.dwControlsAccepted = state == SERVICE_RUNNING
        ? SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN
            | SERVICE_ACCEPT_PRESHUTDOWN | SERVICE_ACCEPT_PARAMCHANGE
        : 0U;
    wls_service_status.dwWin32ExitCode = win32_exit;
    wls_service_status.dwServiceSpecificExitCode = service_specific_exit;
    wls_service_status.dwCheckPoint = 0U;
    wls_service_status.dwWaitHint = 0U;
    if (wls_status_handle != NULL) {
        SetServiceStatus(wls_status_handle, &wls_service_status);
    }
}

#ifdef WLS_GUARDIAN_EXECUTABLE
static DWORD WINAPI wls_service_control(
    DWORD control,
    DWORD event_type,
    LPVOID event_data,
    LPVOID context
) {
    (void)event_type;
    (void)event_data;
    (void)context;
    if (control == SERVICE_CONTROL_STOP || control == SERVICE_CONTROL_SHUTDOWN
        || control == SERVICE_CONTROL_PRESHUTDOWN) {
        if (control == SERVICE_CONTROL_PRESHUTDOWN
            && wls_service_stop_intent_persisted() != 0) {
            /* Continue the controlled drain; a failed marker may not turn a
             * pre-shutdown request into an unchecked recovery restart. */
            OutputDebugStringW(
                L"WLS Gateway could not persist its pre-shutdown intent.\n"
            );
            InterlockedExchange(&wls_service_preshutdown_unsealed, 1);
        }
        /* Publish stop ownership before Broker exit can be classified. */
        InterlockedExchange(&wls_service_stop_requested, 1);
        wls_report_service_pending(
            SERVICE_STOP_PENDING,
            (DWORD)WLS_GUARDIAN_DRAIN_MILLISECONDS
        );
        wls_guardian_service_child_control_signal(0);
        wls_signal_broker_stop_event();
    } else if (control == SERVICE_CONTROL_PARAMCHANGE
        && InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0) {
        InterlockedIncrement(&wls_service_reload_generation);
        wls_guardian_service_child_control_signal(1);
        wls_signal_broker_stop_event();
    }
    return NO_ERROR;
}
#endif

static HANDLE wls_create_supervision_job(void)
{
    JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits;
    HANDLE job = CreateJobObjectW(NULL, NULL);
    ZeroMemory(&limits, sizeof(limits));
    limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
    if (job == NULL || !SetInformationJobObject(
        job,
        JobObjectExtendedLimitInformation,
        &limits,
        sizeof(limits)
    )) {
        if (job != NULL) CloseHandle(job);
        return NULL;
    }
    return job;
}

static HANDLE wls_create_data_plane_job(
    wchar_t name[128]
) {
    JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits;
    SECURITY_ATTRIBUTES security;
    PSECURITY_DESCRIPTOR descriptor = NULL;
    unsigned char nonce[16];
    char nonce_hex[33];
    wchar_t nonce_wide[33];
    HANDLE job = NULL;
    size_t index;
    ZeroMemory(&limits, sizeof(limits));
    ZeroMemory(&security, sizeof(security));
    SecureZeroMemory(nonce, sizeof(nonce));
    SecureZeroMemory(nonce_hex, sizeof(nonce_hex));
    randombytes_buf(nonce, sizeof(nonce));
    if (sodium_bin2hex(
            nonce_hex, sizeof(nonce_hex), nonce, sizeof(nonce)
        ) == NULL) {
        goto cleanup;
    }
    for (index = 0U; index < 33U; index++) {
        nonce_wide[index] = (wchar_t)(unsigned char)nonce_hex[index];
    }
    if (_snwprintf_s(
            name,
            128U,
            _TRUNCATE,
            L"Global\\WelineWlsGatewayV2DataPlane-%lu-%ls",
            (unsigned long)GetCurrentProcessId(),
            nonce_wide
        ) < 0
        || !ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"O:SYD:P(A;;GA;;;SY)(A;;GA;;;BA)",
            SDDL_REVISION_1,
            &descriptor,
            NULL
        )) {
        goto cleanup;
    }
    security.nLength = sizeof(security);
    security.lpSecurityDescriptor = descriptor;
    security.bInheritHandle = FALSE;
    job = CreateJobObjectW(&security, name);
    if (job == NULL || GetLastError() == ERROR_ALREADY_EXISTS) {
        if (job != NULL) CloseHandle(job);
        job = NULL;
        goto cleanup;
    }
    limits.BasicLimitInformation.LimitFlags =
        JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
        | JOB_OBJECT_LIMIT_DIE_ON_UNHANDLED_EXCEPTION;
    if (!SetInformationJobObject(
            job,
            JobObjectExtendedLimitInformation,
            &limits,
            sizeof(limits)
        )) {
        CloseHandle(job);
        job = NULL;
    }
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    SecureZeroMemory(nonce, sizeof(nonce));
    SecureZeroMemory(nonce_hex, sizeof(nonce_hex));
    SecureZeroMemory(nonce_wide, sizeof(nonce_wide));
    return job;
}

static int wls_job_active_processes_zero(HANDLE job, int *zero)
{
    JOBOBJECT_BASIC_ACCOUNTING_INFORMATION accounting;
    if (job == NULL || zero == NULL) return 1;
    ZeroMemory(&accounting, sizeof(accounting));
    if (!QueryInformationJobObject(
            job,
            JobObjectBasicAccountingInformation,
            &accounting,
            sizeof(accounting),
            NULL
        )) return 1;
    *zero = accounting.ActiveProcesses == 0U ? 1 : 0;
    return 0;
}

/* Exit 79 authorizes a new Guardian child only after both old job trees were
 * explicitly terminated and their zero-process state was observed.  Closing a
 * KILL_ON_JOB_CLOSE handle alone does not establish that boundary. */
static int wls_terminate_jobs_and_wait_zero(HANDLE job, HANDLE data_plane_job)
{
    HANDLE completion_port = NULL;
    JOBOBJECT_ASSOCIATE_COMPLETION_PORT association;
    ULONGLONG deadline;
    int supervision_zero = 0;
    int data_plane_zero = 0;
    int associations_ready = 0;
    int supervision_terminated;
    int data_plane_terminated;
    int result = 1;
    if (job == NULL || data_plane_job == NULL) return 1;
    completion_port = CreateIoCompletionPort(INVALID_HANDLE_VALUE, NULL, 0U, 1U);
    if (completion_port != NULL) {
        ZeroMemory(&association, sizeof(association));
        association.CompletionPort = completion_port;
        association.CompletionKey = (PVOID)(ULONG_PTR)1U;
        if (SetInformationJobObject(
                job,
                JobObjectAssociateCompletionPortInformation,
                &association,
                sizeof(association)
            )) {
            association.CompletionKey = (PVOID)(ULONG_PTR)2U;
            if (SetInformationJobObject(
                    data_plane_job,
                    JobObjectAssociateCompletionPortInformation,
                    &association,
                    sizeof(association)
                )) {
                associations_ready = 1;
            }
        }
    }
    /* Do not short-circuit: both tree boundaries must receive exit 79 even if
     * the first termination reports an error. */
    supervision_terminated = TerminateJobObject(job, WLS_SERVICE_TREE_RESTART) ? 1 : 0;
    data_plane_terminated = TerminateJobObject(
        data_plane_job, WLS_SERVICE_TREE_RESTART
    ) ? 1 : 0;
    if (!associations_ready || !supervision_terminated || !data_plane_terminated) {
        goto cleanup;
    }
    deadline = GetTickCount64() + WLS_RESTART_JOB_DRAIN_MILLISECONDS;
    for (;;) {
        DWORD message = 0U;
        DWORD wait_milliseconds;
        ULONG_PTR completion_key = 0U;
        OVERLAPPED *overlapped = NULL;
        ULONGLONG now;
        if (wls_job_active_processes_zero(job, &supervision_zero) != 0
            || wls_job_active_processes_zero(data_plane_job, &data_plane_zero) != 0) {
            goto cleanup;
        }
        if (supervision_zero != 0 && data_plane_zero != 0) {
            result = 0;
            goto cleanup;
        }
        now = GetTickCount64();
        if (now >= deadline) goto cleanup;
        wait_milliseconds = (DWORD)(deadline - now);
        if (!GetQueuedCompletionStatus(
                completion_port,
                &message,
                &completion_key,
                &overlapped,
                wait_milliseconds
            )) goto cleanup;
        if (message != JOB_OBJECT_MSG_ACTIVE_PROCESS_ZERO
            || (completion_key != (ULONG_PTR)1U && completion_key != (ULONG_PTR)2U)) {
            continue;
        }
    }
cleanup:
    if (completion_port != NULL) CloseHandle(completion_port);
    return result;
}

static int wls_run_supervisor(const wchar_t *home, const wchar_t *run_directory)
{
    HANDLE job;
    HANDLE data_plane_job = NULL;
    wchar_t data_plane_job_name[128];
    DWORD adopted_nginx_pid = 0U;
    int result = 1;
    wls_service_test_record_stage(home, L"supervisor-entry");
    job = wls_create_supervision_job();
    ZeroMemory(data_plane_job_name, sizeof(data_plane_job_name));
    if (job == NULL
        || (data_plane_job = wls_create_data_plane_job(
            data_plane_job_name
        )) == NULL) {
        if (job != NULL) CloseHandle(job);
        return 1;
    }
    wls_service_test_record_stage(home, L"jobs-ready");
    do {
        DWORD preserved_nginx_pid = 0U;
        result = wls_launch(
            home,
            run_directory,
            job,
            data_plane_job,
            data_plane_job_name,
            adopted_nginx_pid,
            &preserved_nginx_pid
        );
        adopted_nginx_pid = preserved_nginx_pid;
    } while (result == (int)WLS_CONTROL_TREE_RELOAD
        && InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0);
    if (result == (int)WLS_SERVICE_TREE_RESTART
        && InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0
        && wls_terminate_jobs_and_wait_zero(job, data_plane_job) != 0) {
        /* Returning anything other than 79 prevents the Guardian from
         * spawning a child that could consume the pending residue intent. */
        result = 1;
    }
    CloseHandle(data_plane_job);
    CloseHandle(job);
    if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) != 0) {
        return 0;
    }
    return result == (int)WLS_CONTROL_TREE_RELOAD ? 1 : result;
}

#ifdef WLS_GUARDIAN_EXECUTABLE
static VOID WINAPI wls_service_main(DWORD argc, LPWSTR *argv)
{
    int result;
    (void)argc;
    (void)argv;
    wls_status_handle = RegisterServiceCtrlHandlerExW(
        wls_service_name,
        wls_service_control,
        NULL
    );
    if (wls_status_handle == NULL) return;
    InterlockedExchange(&wls_service_ready_reported, 0);
    wls_service_checkpoint = 0U;
    InterlockedExchange(&wls_service_preshutdown_unsealed, 0);
    wls_report_service(SERVICE_START_PENDING, NO_ERROR, 0U);
#ifdef WLS_NATIVE_TEST_HOOKS
    if (wls_service_test_mode) {
        wls_service_test_record_stage(wls_service_home, L"service-entry");
        if (wls_initialize_service_generation() != 0) {
            result = 1;
        } else {
            wls_service_test_record_stage(
                wls_service_home,
                L"service-generation-ready"
            );
            result = wls_run_supervisor(wls_service_home, wls_service_run);
        }
    } else {
        result = wls_guardian_service_run(wls_service_home, wls_service_run);
    }
#else
    result = wls_guardian_service_run(wls_service_home, wls_service_run);
#endif
    if (InterlockedCompareExchange(
            &wls_service_preshutdown_unsealed,
            0,
            0
        ) != 0) {
        /* Both boot-scoped persistence channels failed. The child/data-plane
         * jobs are already closed; keep this SCM instance STOP_PENDING until
         * Windows ends the boot so recovery cannot start an unchecked peer. */
        for (;;) {
            wls_report_service_pending(
                SERVICE_STOP_PENDING,
                (DWORD)WLS_GUARDIAN_DRAIN_MILLISECONDS
            );
            Sleep(10000U);
        }
    }
    wls_report_service(
        SERVICE_STOPPED,
        result == 0 ? NO_ERROR : ERROR_SERVICE_SPECIFIC_ERROR,
        result == 0 ? 0U : (DWORD)result
    );
}
#endif

struct wls_launcher_proof_self_test_component {
    const char *relative;
    const char *contents;
    size_t length;
    unsigned long long mode;
    char digest[65];
};

static int wls_launcher_proof_self_test_security(
    PSECURITY_DESCRIPTOR *descriptor
) {
    HANDLE token = NULL;
    TOKEN_USER *user = NULL;
    LPWSTR user_sid = NULL;
    DWORD required = 0U;
    DWORD allocated = 0U;
    wchar_t sddl[512];
    int result = 1;
    ZeroMemory(sddl, sizeof(sddl));
    if (descriptor == NULL) return 1;
    *descriptor = NULL;
    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &token)) {
        goto cleanup;
    }
    (void)GetTokenInformation(token, TokenUser, NULL, 0U, &required);
    if (required < sizeof(TOKEN_USER)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    allocated = required;
    user = (TOKEN_USER *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
    );
    if (user == NULL
        || !GetTokenInformation(
            token, TokenUser, user, required, &required
        )
        || !ConvertSidToStringSidW(user->User.Sid, &user_sid)
        || _snwprintf_s(
            sddl,
            sizeof(sddl) / sizeof(sddl[0]),
            _TRUNCATE,
            L"D:P(A;OICI;FA;;;%ls)",
            user_sid
        ) < 0
        || !ConvertStringSecurityDescriptorToSecurityDescriptorW(
            sddl, SDDL_REVISION_1, descriptor, NULL
        )) goto cleanup;
    result = 0;
cleanup:
    if (user_sid != NULL) LocalFree(user_sid);
    if (user != NULL) {
        SecureZeroMemory(user, allocated);
        HeapFree(GetProcessHeap(), 0U, user);
    }
    if (token != NULL) CloseHandle(token);
    SecureZeroMemory(sddl, sizeof(sddl));
    if (result != 0 && *descriptor != NULL) {
        LocalFree(*descriptor);
        *descriptor = NULL;
    }
    return result;
}

static int wls_launcher_proof_self_test_set_slot_acl_exact_profile(
    const wchar_t *path,
    int directory,
    PSID service_sid,
    int data_plane_acl,
    int system_owner,
    int inherit_aces,
    ACCESS_MASK data_plane_mask
) {
    HANDLE object = INVALID_HANDLE_VALUE;
    PACL dacl = NULL;
    PSID data_plane_sid = NULL;
    unsigned char system_buffer[SECURITY_MAX_SID_SIZE];
    unsigned char administrators_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD administrators_length = sizeof(administrators_buffer);
    DWORD acl_length;
    DWORD inherit_flags = directory && inherit_aces
        ? OBJECT_INHERIT_ACE | CONTAINER_INHERIT_ACE : 0U;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    DWORD status;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    if (path == NULL || service_sid == NULL || !IsValidSid(service_sid)
        || (data_plane_acl != 0 && data_plane_acl != 1)
        || (system_owner != 0 && system_owner != 1)
        || (inherit_aces != 0 && inherit_aces != 1)
        || (inherit_aces && !directory)
        || (data_plane_acl && (!ConvertStringSidToSidW(
                WLS_DATA_PLANE_SERVICE_SID_TEXT, &data_plane_sid
            ) || data_plane_sid == NULL || !IsValidSid(data_plane_sid)
            || EqualSid(service_sid, data_plane_sid)
            || data_plane_mask == 0U))
        || (!data_plane_acl && data_plane_mask != 0U)
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_buffer, &system_length
        )
        || !CreateWellKnownSid(
            WinBuiltinAdministratorsSid,
            NULL,
            administrators_buffer,
            &administrators_length
        )) goto cleanup;
    acl_length = (DWORD)sizeof(ACL)
        + (3U + (data_plane_acl ? 1U : 0U))
            * ((DWORD)sizeof(ACCESS_ALLOWED_ACE) - (DWORD)sizeof(DWORD))
        + system_length + administrators_length + GetLengthSid(service_sid)
        + (data_plane_acl ? GetLengthSid(data_plane_sid) : 0U);
    dacl = (PACL)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, acl_length);
    if (dacl == NULL
        || !InitializeAcl(dacl, acl_length, ACL_REVISION)
        || !AddAccessAllowedAceEx(
            dacl,
            ACL_REVISION,
            inherit_flags,
            FILE_ALL_ACCESS,
            system_buffer
        )
        || !AddAccessAllowedAceEx(
            dacl,
            ACL_REVISION,
            inherit_flags,
            FILE_ALL_ACCESS,
            administrators_buffer
        )
        || !AddAccessAllowedAceEx(
            dacl,
            ACL_REVISION,
            inherit_flags,
            FILE_GENERIC_READ | FILE_GENERIC_EXECUTE,
            service_sid
        )
        || (data_plane_acl && !AddAccessAllowedAceEx(
            dacl,
            ACL_REVISION,
            inherit_flags,
            data_plane_mask,
            data_plane_sid
        ))) goto cleanup;
    object = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES | READ_CONTROL | WRITE_DAC | WRITE_OWNER,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_OPEN_REPARSE_POINT
            | (directory ? FILE_FLAG_BACKUP_SEMANTICS : 0U),
        NULL
    );
    if (object == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            object,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || (((attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U)
            != (directory != 0))) goto cleanup;
    status = SetSecurityInfo(
        object,
        SE_FILE_OBJECT,
        OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION
            | PROTECTED_DACL_SECURITY_INFORMATION,
        system_owner ? (PSID)system_buffer : (PSID)administrators_buffer,
        NULL,
        dacl,
        NULL
    );
    if (status != ERROR_SUCCESS
        || (data_plane_acl
            ? (directory
                ? (system_owner
                    ? wls_launcher_host_data_plane_directory_acl_valid(
                        object, service_sid
                    )
                    : wls_launcher_slot_data_plane_directory_acl_valid(
                        object, service_sid
                    ))
                : wls_launcher_nginx_acl_valid(object, service_sid))
            : wls_launcher_slot_acl_valid(
                object, directory, service_sid
            )) != 0) goto cleanup;
    result = 0;
cleanup:
    if (object != INVALID_HANDLE_VALUE) CloseHandle(object);
    if (dacl != NULL) {
        SecureZeroMemory(dacl, acl_length);
        HeapFree(GetProcessHeap(), 0U, dacl);
    }
    if (data_plane_sid != NULL) LocalFree(data_plane_sid);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    return result;
}

static int wls_launcher_proof_self_test_set_slot_acl_profile(
    const wchar_t *path,
    int directory,
    PSID service_sid,
    int data_plane_acl
) {
    return wls_launcher_proof_self_test_set_slot_acl_exact_profile(
        path,
        directory,
        service_sid,
        data_plane_acl,
        0,
        directory && !data_plane_acl,
        data_plane_acl
            ? FILE_GENERIC_READ | FILE_GENERIC_EXECUTE : 0U
    );
}

static int wls_launcher_proof_self_test_set_data_plane_directory_acl(
    const wchar_t *path,
    PSID service_sid,
    int system_owner
) {
    return wls_launcher_proof_self_test_set_slot_acl_exact_profile(
        path,
        1,
        service_sid,
        1,
        system_owner,
        0,
        FILE_TRAVERSE
    );
}

static int wls_launcher_proof_self_test_set_slot_acl(
    const wchar_t *path,
    int directory,
    PSID service_sid
) {
    return wls_launcher_proof_self_test_set_slot_acl_profile(
        path, directory, service_sid, 0
    );
}

static int wls_launcher_proof_self_test_current_user_sid(PSID *sid)
{
    HANDLE token = NULL;
    TOKEN_USER *user = NULL;
    DWORD required = 0U;
    DWORD allocated = 0U;
    DWORD sid_length;
    int result = 1;
    if (sid == NULL) return 1;
    *sid = NULL;
    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &token)) {
        goto cleanup;
    }
    (void)GetTokenInformation(token, TokenUser, NULL, 0U, &required);
    if (required < sizeof(TOKEN_USER)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    allocated = required;
    user = (TOKEN_USER *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
    );
    if (user == NULL
        || !GetTokenInformation(
            token, TokenUser, user, allocated, &required
        )
        || !IsValidSid(user->User.Sid)
        || (sid_length = GetLengthSid(user->User.Sid)) == 0U
        || (*sid = (PSID)LocalAlloc(LMEM_FIXED, sid_length)) == NULL
        || !CopySid(sid_length, *sid, user->User.Sid)) goto cleanup;
    result = 0;
cleanup:
    if (user != NULL) {
        SecureZeroMemory(user, allocated);
        HeapFree(GetProcessHeap(), 0U, user);
    }
    if (token != NULL) CloseHandle(token);
    if (result != 0 && *sid != NULL) {
        LocalFree(*sid);
        *sid = NULL;
    }
    return result;
}

static int wls_launcher_proof_self_test_add_acl_identity(
    const wchar_t *path,
    PSID extra_sid
) {
    HANDLE file = INVALID_HANDLE_VALUE;
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PACL existing = NULL;
    PACL replacement = NULL;
    ACL_SIZE_INFORMATION information;
    BOOL present = FALSE;
    BOOL defaulted = FALSE;
    DWORD index;
    DWORD replacement_length;
    DWORD status;
    int result = 1;
    ZeroMemory(&information, sizeof(information));
    if (path == NULL || extra_sid == NULL || !IsValidSid(extra_sid)) return 1;
    file = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES | READ_CONTROL | WRITE_DAC,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || GetSecurityInfo(
            file,
            SE_FILE_OBJECT,
            DACL_SECURITY_INFORMATION,
            NULL,
            NULL,
            &existing,
            NULL,
            &descriptor
        ) != ERROR_SUCCESS
        || descriptor == NULL
        || !GetSecurityDescriptorDacl(
            descriptor, &present, &existing, &defaulted
        )
        || !present || defaulted || existing == NULL
        || !GetAclInformation(
            existing, &information, sizeof(information), AclSizeInformation
        )) goto cleanup;
    replacement_length = information.AclBytesInUse
        + (DWORD)sizeof(ACCESS_ALLOWED_ACE) - (DWORD)sizeof(DWORD)
        + GetLengthSid(extra_sid);
    replacement = (PACL)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, replacement_length
    );
    if (replacement == NULL
        || !InitializeAcl(replacement, replacement_length, ACL_REVISION)) {
        goto cleanup;
    }
    for (index = 0U; index < information.AceCount; index++) {
        ACE_HEADER *ace = NULL;
        if (!GetAce(existing, index, (LPVOID *)&ace)
            || ace == NULL
            || !AddAce(
                replacement,
                ACL_REVISION,
                MAXDWORD,
                ace,
                ace->AceSize
            )) goto cleanup;
    }
    if (!AddAccessAllowedAceEx(
            replacement,
            ACL_REVISION,
            0U,
            FILE_GENERIC_READ,
            extra_sid
        )) goto cleanup;
    status = SetSecurityInfo(
        file,
        SE_FILE_OBJECT,
        DACL_SECURITY_INFORMATION | PROTECTED_DACL_SECURITY_INFORMATION,
        NULL,
        NULL,
        replacement,
        NULL
    );
    if (status == ERROR_SUCCESS) result = 0;
cleanup:
    if (replacement != NULL) {
        SecureZeroMemory(replacement, replacement_length);
        HeapFree(GetProcessHeap(), 0U, replacement);
    }
    if (descriptor != NULL) LocalFree(descriptor);
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return result;
}

static int wls_launcher_proof_self_test_unprotect_acl(
    const wchar_t *path
) {
    HANDLE file = INVALID_HANDLE_VALUE;
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PACL dacl = NULL;
    DWORD status;
    int result = 1;
    if (path == NULL) return 1;
    file = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES | READ_CONTROL | WRITE_DAC,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || GetSecurityInfo(
            file,
            SE_FILE_OBJECT,
            DACL_SECURITY_INFORMATION,
            NULL,
            NULL,
            &dacl,
            NULL,
            &descriptor
        ) != ERROR_SUCCESS
        || descriptor == NULL || dacl == NULL) goto cleanup;
    status = SetSecurityInfo(
        file,
        SE_FILE_OBJECT,
        DACL_SECURITY_INFORMATION | UNPROTECTED_DACL_SECURITY_INFORMATION,
        NULL,
        NULL,
        dacl,
        NULL
    );
    if (status == ERROR_SUCCESS) result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return result;
}

static int wls_launcher_proof_self_test_set_owner(
    const wchar_t *path,
    PSID owner
) {
    HANDLE file = INVALID_HANDLE_VALUE;
    DWORD status;
    int result = 1;
    if (path == NULL || owner == NULL || !IsValidSid(owner)) return 1;
    file = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES | WRITE_OWNER,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE) goto cleanup;
    status = SetSecurityInfo(
        file,
        SE_FILE_OBJECT,
        OWNER_SECURITY_INFORMATION,
        owner,
        NULL,
        NULL,
        NULL
    );
    if (status == ERROR_SUCCESS) result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return result;
}

static int wls_launcher_proof_self_test_digest(
    const unsigned char *contents,
    size_t length,
    char digest[65]
) {
    unsigned char raw[crypto_hash_sha256_BYTES];
    int result = 1;
    SecureZeroMemory(raw, sizeof(raw));
    if (contents == NULL || digest == NULL || length > ULLONG_MAX
        || crypto_hash_sha256(
            raw, contents, (unsigned long long)length
        ) != 0) goto cleanup;
    sodium_bin2hex(digest, 65U, raw, sizeof(raw));
    result = 0;
cleanup:
    sodium_memzero(raw, sizeof(raw));
    return result;
}

static int wls_launcher_proof_self_test_write(
    const wchar_t *path,
    const char *contents,
    size_t length
) {
    HANDLE file = INVALID_HANDLE_VALUE;
    int result = 1;
    if (path == NULL || contents == NULL || length > MAXDWORD) return 1;
    file = CreateFileW(
        path,
        GENERIC_WRITE,
        0U,
        NULL,
        CREATE_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT
            | FILE_FLAG_WRITE_THROUGH,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || wls_write_all(file, (const unsigned char *)contents, (DWORD)length) != 0
        || !FlushFileBuffers(file)) goto cleanup;
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return result;
}

static int wls_launcher_proof_self_test_delete_file(const wchar_t *path)
{
    DWORD error;
    if (path == NULL || path[0] == L'\0') return 0;
    if (DeleteFileW(path)) return 0;
    error = GetLastError();
    return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
        ? 0 : 1;
}

static int wls_launcher_proof_self_test_remove_directory(
    const wchar_t *path
) {
    DWORD error;
    if (path == NULL || path[0] == L'\0') return 0;
    if (RemoveDirectoryW(path)) return 0;
    error = GetLastError();
    return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
        ? 0 : 1;
}

static int wls_launcher_proof_self_test_components_json(
    const struct wls_launcher_proof_self_test_component *components,
    size_t count,
    char *json,
    size_t capacity
) {
    size_t used = 0U;
    size_t index;
    if (components == NULL || count == 0U || json == NULL || capacity == 0U) {
        return 1;
    }
    json[0] = '\0';
    for (index = 0U; index < count; index++) {
        int written = _snprintf_s(
            json + used,
            capacity - used,
            _TRUNCATE,
            "%s\"%s\":{\"sha256\":\"%s\",\"size\":%llu,\"mode\":%llu}",
            index == 0U ? "" : ",",
            components[index].relative,
            components[index].digest,
            (unsigned long long)components[index].length,
            components[index].mode
        );
        if (written <= 0 || (size_t)written >= capacity - used) return 1;
        used += (size_t)written;
    }
    return 0;
}

static int wls_launcher_proof_self_test_generation(
    char *installed,
    size_t length,
    int forge,
    char expected[65]
) {
    static const char marker[] =
        "\"runtime_generation\":\"0000000000000000000000000000000000000000000000000000000000000000\"";
    struct wls_launcher_json_node *root = NULL;
    char *position;
    int result = 1;
    if (installed == NULL || expected == NULL || length == 0U) return 1;
    root = wls_launcher_json_parse((const unsigned char *)installed, length);
    position = strstr(installed, marker);
    if (root == NULL || position == NULL
        || strstr(position + 1U, marker) != NULL
        || wls_launcher_json_generation(root, expected) != 0) goto cleanup;
    position += strlen("\"runtime_generation\":\"");
    if (forge) memset(position, 'f', 64U);
    else memcpy(position, expected, 64U);
    result = 0;
cleanup:
    wls_launcher_json_free(root);
    return result;
}

static int wls_launcher_rollback_target_proof_self_test(void)
{
    static const char durable_contract[] =
        "{\"schema_version\":2,\"security_ledger_read_schema\":8,"
        "\"security_ledger_write_schema\":8,\"snapshot_receipt_read_schema\":2,"
        "\"snapshot_receipt_write_schema\":2,\"snapshot_namespace\":\"snapshots-v2\","
        "\"nonce_wal_schema\":1,\"nginx_test_schema\":1}";
    struct wls_launcher_proof_self_test_component components[] = {
        {"app/controller.php", "controller-proof\n", 17U, 420ULL, {0}},
        {"bin/nginx.exe", "nginx-proof\n", 12U, 493ULL, {0}},
        {"bin/php.exe", "php-proof\n", 10U, 493ULL, {0}},
        {"bin/wls-gateway-broker.exe", "broker-proof\n", 13U, 493ULL, {0}},
        {"bin/wls-gateway-launcher.exe", "launcher-proof\n", 15U, 493ULL, {0}}
    };
    wchar_t temporary_base[WLS_PATH_CHARS];
    wchar_t home[WLS_PATH_CHARS];
    wchar_t slots[WLS_PATH_CHARS];
    wchar_t slot[WLS_PATH_CHARS];
    wchar_t bin[WLS_PATH_CHARS];
    wchar_t app[WLS_PATH_CHARS];
    wchar_t release_directory[WLS_PATH_CHARS];
    wchar_t release_manifest_path[WLS_PATH_CHARS];
    wchar_t release_signature_path[WLS_PATH_CHARS];
    wchar_t installed_manifest_path[WLS_PATH_CHARS];
    wchar_t component_paths[
        sizeof(components) / sizeof(components[0])
    ][WLS_PATH_CHARS];
    unsigned char public_key[crypto_sign_PUBLICKEYBYTES];
    unsigned char secret_key[crypto_sign_SECRETKEYBYTES];
    unsigned char signature[crypto_sign_BYTES];
    unsigned char nonce[8];
    unsigned char world_sid[SECURITY_MAX_SID_SIZE];
    unsigned char users_sid[SECURITY_MAX_SID_SIZE];
    DWORD world_sid_length = sizeof(world_sid);
    DWORD users_sid_length = sizeof(users_sid);
    PSID slot_service_sid = NULL;
    PSID current_user_sid = NULL;
    PSECURITY_DESCRIPTOR home_descriptor = NULL;
    SECURITY_ATTRIBUTES home_security;
    DWORD temporary_length;
    char nonce_hex[17];
    char component_json[4096];
    char installed_components[6144];
    char release_manifest[8192];
    char installed_manifest[12288];
    char signature_text[256];
    char release_digest[65];
    char signature_digest[65];
    char expected_generation[65];
    char observed_generation[65];
    size_t release_length = 0U;
    size_t signature_length = 0U;
    size_t installed_length = 0U;
    size_t index;
    unsigned int attempt;
    int created_home = 0;
    int cleanup_failed = 0;
    int result = 1;
    static const char hex[] = "0123456789abcdef";
    ZeroMemory(temporary_base, sizeof(temporary_base));
    ZeroMemory(home, sizeof(home));
    ZeroMemory(slots, sizeof(slots));
    ZeroMemory(slot, sizeof(slot));
    ZeroMemory(bin, sizeof(bin));
    ZeroMemory(app, sizeof(app));
    ZeroMemory(release_directory, sizeof(release_directory));
    ZeroMemory(release_manifest_path, sizeof(release_manifest_path));
    ZeroMemory(release_signature_path, sizeof(release_signature_path));
    ZeroMemory(installed_manifest_path, sizeof(installed_manifest_path));
    ZeroMemory(component_paths, sizeof(component_paths));
    ZeroMemory(&home_security, sizeof(home_security));
    SecureZeroMemory(public_key, sizeof(public_key));
    SecureZeroMemory(secret_key, sizeof(secret_key));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(nonce, sizeof(nonce));
    SecureZeroMemory(world_sid, sizeof(world_sid));
    SecureZeroMemory(users_sid, sizeof(users_sid));
    SecureZeroMemory(nonce_hex, sizeof(nonce_hex));
    SecureZeroMemory(component_json, sizeof(component_json));
    SecureZeroMemory(installed_components, sizeof(installed_components));
    SecureZeroMemory(release_manifest, sizeof(release_manifest));
    SecureZeroMemory(installed_manifest, sizeof(installed_manifest));
    SecureZeroMemory(signature_text, sizeof(signature_text));
    SecureZeroMemory(release_digest, sizeof(release_digest));
    SecureZeroMemory(signature_digest, sizeof(signature_digest));
    SecureZeroMemory(expected_generation, sizeof(expected_generation));
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    temporary_length = GetTempPathW(WLS_PATH_CHARS, temporary_base);
    if (temporary_length == 0U || temporary_length >= WLS_PATH_CHARS
        || wls_launcher_proof_self_test_security(&home_descriptor) != 0
        || crypto_sign_keypair(public_key, secret_key) != 0) goto cleanup;
    home_security.nLength = sizeof(home_security);
    home_security.lpSecurityDescriptor = home_descriptor;
    home_security.bInheritHandle = FALSE;
    for (attempt = 0U; attempt < 8U; attempt++) {
        randombytes_buf(nonce, sizeof(nonce));
        for (index = 0U; index < sizeof(nonce); index++) {
            nonce_hex[index * 2U] = hex[nonce[index] >> 4U];
            nonce_hex[index * 2U + 1U] = hex[nonce[index] & 0x0fU];
        }
        nonce_hex[16] = '\0';
        if (_snwprintf_s(
                home, WLS_PATH_CHARS, _TRUNCATE,
                L"%lsWelineWlsLauncherProof-%lu-%hs",
                temporary_base,
                (unsigned long)GetCurrentProcessId(),
                nonce_hex
            ) < 0) goto cleanup;
        if (CreateDirectoryW(home, &home_security)) {
            created_home = 1;
            break;
        }
        if (GetLastError() != ERROR_ALREADY_EXISTS) goto cleanup;
    }
    if (!created_home
        || wls_join(slots, WLS_PATH_CHARS, home, L"slots") != 0
        || wls_join(slot, WLS_PATH_CHARS, slots, L"A") != 0
        || wls_join(bin, WLS_PATH_CHARS, slot, L"bin") != 0
        || wls_join(app, WLS_PATH_CHARS, slot, L"app") != 0
        || wls_join(release_directory, WLS_PATH_CHARS, slot, L"release") != 0
        || !CreateDirectoryW(slots, NULL)
        || !CreateDirectoryW(slot, NULL)
        || !CreateDirectoryW(bin, NULL)
        || !CreateDirectoryW(app, NULL)
        || !CreateDirectoryW(release_directory, NULL)) goto cleanup;
    for (index = 0U; index < sizeof(components) / sizeof(components[0]); index++) {
        wchar_t relative[512];
        if (wls_launcher_proof_self_test_digest(
                (const unsigned char *)components[index].contents,
                components[index].length,
                components[index].digest
            ) != 0
            || wls_utf8_to_wide(
                components[index].relative,
                relative,
                sizeof(relative) / sizeof(relative[0])
            ) != 0
            || wls_join(
                component_paths[index], WLS_PATH_CHARS, slot, relative
            ) != 0
            || wls_launcher_proof_self_test_write(
                component_paths[index],
                components[index].contents,
                components[index].length
            ) != 0) goto cleanup;
        SecureZeroMemory(relative, sizeof(relative));
    }
    if (wls_launcher_proof_self_test_components_json(
            components,
            sizeof(components) / sizeof(components[0]),
            component_json,
            sizeof(component_json)
        ) != 0) goto cleanup;
    {
        int written = _snprintf_s(
            release_manifest,
            sizeof(release_manifest),
            _TRUNCATE,
            "{\"schema_version\":2,\"capabilities\":{"
            "\"stable_launcher_rollback_target_proof\":true},"
            "\"durable_state_contract\":%s,\"components\":{%s}}",
            durable_contract,
            component_json
        );
        if (written <= 0) goto cleanup;
        release_length = (size_t)written;
    }
    if (crypto_sign_detached(
            signature,
            NULL,
            (const unsigned char *)release_manifest,
            (unsigned long long)release_length,
            secret_key
        ) != 0
        || sodium_bin2base64(
            signature_text,
            sizeof(signature_text),
            signature,
            sizeof(signature),
            sodium_base64_VARIANT_ORIGINAL
        ) == NULL) goto cleanup;
    signature_length = strlen(signature_text);
    if (wls_launcher_proof_self_test_digest(
            (const unsigned char *)release_manifest,
            release_length,
            release_digest
        ) != 0
        || wls_launcher_proof_self_test_digest(
            (const unsigned char *)signature_text,
            signature_length,
            signature_digest
        ) != 0
        || _snprintf_s(
            installed_components,
            sizeof(installed_components),
            _TRUNCATE,
            "%s,\"release/manifest.json\":{\"sha256\":\"%s\","
            "\"size\":%llu,\"mode\":384},\"release/manifest.sig\":{"
            "\"sha256\":\"%s\",\"size\":%llu,\"mode\":384}",
            component_json,
            release_digest,
            (unsigned long long)release_length,
            signature_digest,
            (unsigned long long)signature_length
        ) <= 0
        || wls_join(
            release_manifest_path, WLS_PATH_CHARS,
            release_directory, L"manifest.json"
        ) != 0
        || wls_join(
            release_signature_path, WLS_PATH_CHARS,
            release_directory, L"manifest.sig"
        ) != 0
        || wls_join(
            installed_manifest_path, WLS_PATH_CHARS,
            slot, L"manifest.json"
        ) != 0
        || wls_launcher_proof_self_test_write(
            release_manifest_path, release_manifest, release_length
        ) != 0
        || wls_launcher_proof_self_test_write(
            release_signature_path, signature_text, signature_length
        ) != 0) goto cleanup;
#define WLS_BUILD_INSTALLED(capability, forge_generation) do { \
        int wls_written = _snprintf_s( \
            installed_manifest, sizeof(installed_manifest), _TRUNCATE, \
            "{\"schema_version\":2,\"role\":\"host_gateway\",\"slot\":\"A\"," \
            "\"capabilities\":{\"stable_launcher_rollback_target_proof\":" capability "}," \
            "\"durable_state_contract\":%s,\"components\":{%s}," \
            "\"runtime_generation\":\"0000000000000000000000000000000000000000000000000000000000000000\"}", \
            durable_contract, installed_components \
        ); \
        if (wls_written <= 0) goto cleanup; \
        installed_length = (size_t)wls_written; \
        if (wls_launcher_proof_self_test_generation( \
                installed_manifest, installed_length, \
                (forge_generation), expected_generation \
            ) != 0 \
            || wls_launcher_proof_self_test_write( \
                installed_manifest_path, installed_manifest, installed_length \
            ) != 0) goto cleanup; \
    } while (0)
    WLS_BUILD_INSTALLED("true", 0);
    if (wls_launcher_gateway_service_sid(&slot_service_sid) != 0) goto cleanup;
    for (index = 0U; index < sizeof(components) / sizeof(components[0]); index++) {
        if (wls_launcher_proof_self_test_set_slot_acl_profile(
                component_paths[index],
                0,
                slot_service_sid,
                strcmp(components[index].relative, "bin/nginx.exe") == 0
            ) != 0) goto cleanup;
    }
    if (wls_launcher_proof_self_test_set_slot_acl(
            release_manifest_path, 0, slot_service_sid
        ) != 0
        || wls_launcher_proof_self_test_set_slot_acl(
            release_signature_path, 0, slot_service_sid
        ) != 0
        || wls_launcher_proof_self_test_set_slot_acl(
            installed_manifest_path, 0, slot_service_sid
        ) != 0
        || wls_launcher_proof_self_test_set_slot_acl(
            release_directory, 1, slot_service_sid
        ) != 0
        || wls_launcher_proof_self_test_set_slot_acl(
            app, 1, slot_service_sid
        ) != 0
        || wls_launcher_proof_self_test_set_data_plane_directory_acl(
            bin, slot_service_sid, 0
        ) != 0
        || wls_launcher_proof_self_test_set_data_plane_directory_acl(
            slot, slot_service_sid, 0
        ) != 0
        || wls_launcher_proof_self_test_set_data_plane_directory_acl(
            slots, slot_service_sid, 0
        ) != 0
        || wls_launcher_proof_self_test_set_data_plane_directory_acl(
            home, slot_service_sid, 1
        ) != 0) goto cleanup;
    if (wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) != 0
        || sodium_memcmp(
            expected_generation, observed_generation, 64U
        ) != 0
        || wls_launcher_proof_self_test_set_slot_acl(
            bin, 1, slot_service_sid
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) == 0
        || wls_launcher_proof_self_test_set_data_plane_directory_acl(
            bin, slot_service_sid, 0
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) != 0
        || wls_launcher_proof_self_test_set_slot_acl(
            component_paths[1], 0, slot_service_sid
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) == 0
        || wls_launcher_proof_self_test_set_slot_acl_profile(
            component_paths[1], 0, slot_service_sid, 1
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) != 0) goto cleanup;
    if (!CreateWellKnownSid(
            WinWorldSid, NULL, world_sid, &world_sid_length
        )
        || !CreateWellKnownSid(
            WinBuiltinUsersSid, NULL, users_sid, &users_sid_length
        )
        || wls_launcher_proof_self_test_current_user_sid(
            &current_user_sid
        ) != 0) goto cleanup;
    if (wls_launcher_proof_self_test_add_acl_identity(
            installed_manifest_path, world_sid
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) == 0
        || wls_launcher_proof_self_test_set_slot_acl(
            installed_manifest_path, 0, slot_service_sid
        ) != 0
        || wls_launcher_proof_self_test_add_acl_identity(
            installed_manifest_path, users_sid
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) == 0
        || wls_launcher_proof_self_test_set_slot_acl(
            installed_manifest_path, 0, slot_service_sid
        ) != 0
        || wls_launcher_proof_self_test_unprotect_acl(
            installed_manifest_path
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) == 0
        || wls_launcher_proof_self_test_set_slot_acl(
            installed_manifest_path, 0, slot_service_sid
        ) != 0
        || wls_launcher_proof_self_test_set_owner(
            installed_manifest_path, current_user_sid
        ) != 0
        || wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) == 0
        || wls_launcher_proof_self_test_set_slot_acl(
            installed_manifest_path, 0, slot_service_sid
        ) != 0) goto cleanup;
    WLS_BUILD_INSTALLED("false", 0);
    if (wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) == 0) goto cleanup;
    WLS_BUILD_INSTALLED("true", 1);
    if (wls_verify_slot_durable_state_contract_v2_with_key(
            home, L'A', observed_generation, public_key
        ) == 0) goto cleanup;
#undef WLS_BUILD_INSTALLED
    result = 0;
cleanup:
    cleanup_failed |= wls_launcher_proof_self_test_delete_file(
        installed_manifest_path
    );
    cleanup_failed |= wls_launcher_proof_self_test_delete_file(
        release_signature_path
    );
    cleanup_failed |= wls_launcher_proof_self_test_delete_file(
        release_manifest_path
    );
    for (index = 0U; index < sizeof(components) / sizeof(components[0]); index++) {
        cleanup_failed |= wls_launcher_proof_self_test_delete_file(
            component_paths[index]
        );
    }
    cleanup_failed |= wls_launcher_proof_self_test_remove_directory(
        release_directory
    );
    cleanup_failed |= wls_launcher_proof_self_test_remove_directory(app);
    cleanup_failed |= wls_launcher_proof_self_test_remove_directory(bin);
    cleanup_failed |= wls_launcher_proof_self_test_remove_directory(slot);
    cleanup_failed |= wls_launcher_proof_self_test_remove_directory(slots);
    if (created_home) {
        cleanup_failed |= wls_launcher_proof_self_test_remove_directory(home);
    }
    if (home_descriptor != NULL) LocalFree(home_descriptor);
    if (slot_service_sid != NULL) LocalFree(slot_service_sid);
    if (current_user_sid != NULL) LocalFree(current_user_sid);
    sodium_memzero(public_key, sizeof(public_key));
    sodium_memzero(secret_key, sizeof(secret_key));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(nonce, sizeof(nonce));
    SecureZeroMemory(world_sid, sizeof(world_sid));
    SecureZeroMemory(users_sid, sizeof(users_sid));
    SecureZeroMemory(nonce_hex, sizeof(nonce_hex));
    SecureZeroMemory(component_json, sizeof(component_json));
    SecureZeroMemory(installed_components, sizeof(installed_components));
    SecureZeroMemory(release_manifest, sizeof(release_manifest));
    SecureZeroMemory(installed_manifest, sizeof(installed_manifest));
    SecureZeroMemory(signature_text, sizeof(signature_text));
    SecureZeroMemory(release_digest, sizeof(release_digest));
    SecureZeroMemory(signature_digest, sizeof(signature_digest));
    SecureZeroMemory(expected_generation, sizeof(expected_generation));
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    if (cleanup_failed) result = 1;
    return result;
}

static const wchar_t *wls_argument(
    int argc,
    wchar_t **argv,
    const wchar_t *name
) {
    int index;
    size_t length = wcslen(name);
    for (index = 1; index < argc; index++) {
        if (wcsncmp(argv[index], name, length) == 0
            && argv[index][length] == L'=') {
            return argv[index] + length + 1U;
        }
        if (wcscmp(argv[index], name) == 0 && index + 1 < argc) {
            return argv[index + 1];
        }
    }
    return NULL;
}

static int wls_absolute_windows_path(const wchar_t *path)
{
    return path != NULL
        && ((path[0] != L'\0' && path[1] == L':'
                && (path[2] == L'\\' || path[2] == L'/'))
            || (path[0] == L'\\' && path[1] == L'\\'));
}

#ifdef WLS_NATIVE_TEST_HOOKS
static int wls_service_test_name_valid(const wchar_t *name)
{
    static const wchar_t prefix[] = L"ai-test-wls-gateway-";
    size_t index;
    size_t prefix_length = sizeof(prefix) / sizeof(prefix[0]) - 1U;
    if (name == NULL || wcslen(name) != prefix_length + 32U
        || wcsncmp(name, prefix, prefix_length) != 0) return 0;
    for (index = prefix_length; index < prefix_length + 32U; index++) {
        if (!((name[index] >= L'0' && name[index] <= L'9')
            || (name[index] >= L'a' && name[index] <= L'f'))) return 0;
    }
    return 1;
}
#endif

int wmain(int argc, wchar_t **argv)
{
    const wchar_t *home;
    const wchar_t *run_directory;
#ifdef WLS_GUARDIAN_EXECUTABLE
    const wchar_t *service_name;
    const wchar_t *service_test_root;
#endif
    unsigned char public_key[crypto_sign_PUBLICKEYBYTES];
    int service_mode = 0;
    int index;
    if (sodium_init() < 0 || wls_public_key(public_key) != 0) return 1;
    sodium_memzero(public_key, sizeof(public_key));
#ifndef WLS_GUARDIAN_EXECUTABLE
    if (argc > 1 && wcscmp(argv[1], L"--guardian-child") == 0) {
        return wls_guardian_child_command(argc, argv);
    }
#else
    if (argc > 1 && wcscmp(argv[1], L"--guardian-child") == 0) return 64;
#endif
    if (argc == 2 && wcscmp(argv[1], L"--self-test") == 0) {
#ifdef WLS_GUARDIAN_EXECUTABLE
        if (wls_guardian_core_self_test() != 0
            || wls_guardian_protocol_self_test() != 0
            || wls_guardian_recovery_self_test() != 0
            || wls_guardian_runtime_self_test() != 0) {
            return 1;
        }
#endif
        if (wls_rebootstrap_reserved_recovery_name_self_test() != 0
            || wls_windows_capacity_contract_self_test(0) != 0
            || wls_classify_broker_exit(0U, 0, 1, 0) != 1U
            || wls_classify_broker_exit(WLS_CONTROL_TREE_RELOAD, 0, 1, 0) != 1U
            || wls_classify_broker_exit(
                WLS_SERVICE_TREE_RESTART, 0, 1, 0
            ) != WLS_SERVICE_TREE_RESTART
            || wls_classify_broker_exit(7U, 0, 1, 0) != 7U
            || wls_classify_broker_exit(7U, 1, 1, 0) != 0U
            || wls_classify_broker_exit(7U, 0, 0, 0) != 0U
            || wls_classify_broker_exit(7U, 0, 1, 1)
                != WLS_CONTROL_TREE_RELOAD) {
            return 1;
        }
        return 0;
    }
    if (argc == 2
        && wcscmp(
            argv[1], L"--rollback-target-proof-self-test"
        ) == 0) {
        return wls_launcher_rollback_target_proof_self_test();
    }
    if (argc == 2
        && wcscmp(argv[1], L"--recovery-ledger-self-test") == 0) {
        return wls_recovery_state_self_test();
    }
    if (argc == 2
        && wcscmp(
            argv[1], L"--capacity-reserve-contract-self-test"
        ) == 0) {
        return wls_windows_capacity_contract_self_test(1);
    }
    if (argc == 2
        && wcscmp(argv[1], L"--programdata-authority") == 0) {
        return wls_windows_programdata_authority();
    }
    if (wls_windows_capacity_requested(argc, argv)) {
        return wls_windows_capacity_command(argc, argv);
    }
    for (index = 1; index < argc; index++) {
        if (wcscmp(argv[index], L"--service") == 0) service_mode = 1;
    }
    home = wls_argument(argc, argv, L"--home");
    run_directory = wls_argument(argc, argv, L"--run");
#ifdef WLS_GUARDIAN_EXECUTABLE
    service_name = wls_argument(argc, argv, L"--service-name");
    service_test_root = wls_argument(argc, argv, L"--service-test-root");
#endif
    if (!wls_absolute_windows_path(home)
        || !wls_absolute_windows_path(run_directory)) {
        return 64;
    }
#ifdef WLS_GUARDIAN_EXECUTABLE
    if (!service_mode) return 64;
#ifdef WLS_NATIVE_TEST_HOOKS
    if (service_name != NULL || service_test_root != NULL) {
        if (argc != 6 || service_name == NULL || service_test_root == NULL
            || !wls_service_test_name_valid(service_name)
            || !wls_absolute_windows_path(service_test_root)
            || wcschr(service_test_root, L'"') != NULL
            || wcschr(home, L'"') != NULL
            || wcschr(run_directory, L'"') != NULL
            || wcscmp(service_test_root, home) != 0) return 64;
        wls_service_name = service_name;
        wls_service_test_mode = 1;
    } else
#else
    if (service_name != NULL || service_test_root != NULL) return 64;
#endif
    {
        if (argc != 4) return 64;
    }
#ifdef WLS_NATIVE_TEST_HOOKS
    if (wls_service_test_mode) {
        wls_service_home = home;
        wls_service_run = run_directory;
        {
            SERVICE_TABLE_ENTRYW dispatch[] = {
                {(LPWSTR)wls_service_name, wls_service_main},
                {NULL, NULL}
            };
            return StartServiceCtrlDispatcherW(dispatch) ? 0 : 1;
        }
    }
#endif
    {
        wchar_t fixed_home[WLS_PATH_CHARS];
        wchar_t fixed_run[WLS_PATH_CHARS];
        ZeroMemory(fixed_home, sizeof(fixed_home));
        ZeroMemory(fixed_run, sizeof(fixed_run));
        if (wls_guardian_fixed_runtime_paths(
                home, run_directory, fixed_home, fixed_run
            ) != 0) return 64;
        SecureZeroMemory(fixed_home, sizeof(fixed_home));
        SecureZeroMemory(fixed_run, sizeof(fixed_run));
    }
    {
        SERVICE_TABLE_ENTRYW dispatch[] = {
            {(LPWSTR)wls_service_name, wls_service_main},
            {NULL, NULL}
        };
        wls_service_home = home;
        wls_service_run = run_directory;
        return StartServiceCtrlDispatcherW(dispatch) ? 0 : 1;
    }
#else
    if (service_mode) return 64;
    if (wls_initialize_service_generation() != 0) return 1;
    return wls_run_supervisor(home, run_directory);
#endif
}
