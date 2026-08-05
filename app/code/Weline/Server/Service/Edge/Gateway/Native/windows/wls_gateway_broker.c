#include <winsock2.h>
#include <ws2tcpip.h>
#include <windows.h>
#include <winternl.h>
#include <shellapi.h>
#include <tlhelp32.h>
#include <sddl.h>
#include <aclapi.h>
#include <bcrypt.h>
#include <errno.h>
#include <limits.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <wchar.h>

#ifndef OBJ_DONT_REPARSE
#define OBJ_DONT_REPARSE 0x1000L
#endif
#ifndef FILE_OPEN_REPARSE_POINT
#define FILE_OPEN_REPARSE_POINT 0x00200000
#endif

#define WLS_MAX_REQUEST (4U * 1024U * 1024U)
#define WLS_MAX_SNAPSHOT (1024U * 1024U)
#define WLS_MAX_REGISTRY (4U * 1024U * 1024U)
#define WLS_FENCING_BYTES 32U
#define WLS_PATH_CHARS 32768U
#define WLS_CONTROLLER_START_ATTEMPTS 4500U
#define WLS_CONTROLLER_START_POLL_MS 10U
#define WLS_CONTROLLER_IO_TIMEOUT_MS 3000U
#define WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS 90000U
#define WLS_PROJECT_CONTROLLER_IO_TIMEOUT_MS 90000U
#define WLS_PUBLIC_PIPE_IO_TIMEOUT_MS 3000U
#define WLS_IO_POLL_MS 10U
#define WLS_PUBLIC_PIPE_BUFFER (64U * 1024U)
#define WLS_ADMIN_PIPE_INSTANCES 2U
#define WLS_PROJECT_PIPE_INSTANCES 8U
#define WLS_TOTAL_PIPE_INSTANCES \
    (WLS_ADMIN_PIPE_INSTANCES + WLS_PROJECT_PIPE_INSTANCES)
#define WLS_PROJECT_SID_ACTIVE_LIMIT 2U
#define WLS_INITIAL_FRAME_CAPACITY 4096U
#define WLS_UPGRADE_OBSERVATION_MILLISECONDS 300000ULL
#define WLS_ROLLBACK_HEALTH_MILLISECONDS 15000ULL
#define WLS_CONTROLLER_OBSERVATION_RESETS 3U
#define WLS_SID_GATE_SLOTS 32U
#define WLS_BOOTSTRAP_ATTEMPTS 4U
#define WLS_MAINTENANCE_BOOTSTRAP_INTERVAL_MS 5000U
#define WLS_MAINTENANCE_CONTROLLER_IO_TIMEOUT_MS 15000U
#define WLS_MAINTENANCE_HEALTH_FRESHNESS_MS 20000ULL
#define WLS_PREVIOUS_CONTROLLER_EXIT_ATTEMPTS 110U
#define WLS_PREVIOUS_CONTROLLER_EXIT_POLL_MS 100U

static SRWLOCK wls_bootstrap_lock = SRWLOCK_INIT;

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

struct wls_system_timeofday_information {
    LARGE_INTEGER boot_time;
    LARGE_INTEGER current_time;
    LARGE_INTEGER time_zone_bias;
    ULONG time_zone_id;
    ULONG reserved;
    ULONGLONG boot_time_bias;
    ULONGLONG sleep_time_bias;
};

typedef NTSTATUS (NTAPI *wls_nt_query_system_information_fn)(
    SYSTEM_INFORMATION_CLASS,
    PVOID,
    ULONG,
    PULONG
);

struct wls_upgrade_binding {
    int present;
    char intent_sha256[65];
    char nonce[33];
    wchar_t from;
    wchar_t to;
    long long prepared_at;
    char runtime_generation[65];
    char boot_id[65];
    char phase[24];
    unsigned int attempts;
    ULONGLONG observation_started;
    ULONGLONG observation_deadline;
    long long total_deadline;
};

struct wls_channel {
    const wchar_t *public_pipe;
    unsigned short controller_port;
    const wchar_t *channel;
    const wchar_t *security_sddl;
    const char *fencing;
    const wchar_t *home;
    HANDLE stop_event;
    DWORD instance_count;
    DWORD sid_active_limit;
    struct wls_sid_gate *sid_gate;
};

struct wls_sid_gate_entry {
    char sid[256];
    DWORD active;
};

struct wls_sid_gate {
    SRWLOCK lock;
    struct wls_sid_gate_entry entries[WLS_SID_GATE_SLOTS];
};

struct wls_channel_instance {
    struct wls_channel *channel;
    HANDLE public_pipe;
};

struct wls_bootstrap_receipt {
    char epoch[33];
    char controller_epoch[33];
    char active_slot[2];
    char runtime_generation[65];
    char host_boot_id[65];
    char intent_sha256[65];
    char intent_nonce[33];
    unsigned long long generation;
    unsigned long long active_config_generation;
    ULONGLONG observed_monotonic_ms;
};

struct wls_bootstrap_maintenance_context {
    unsigned short controller_port;
    const char *fencing;
    const wchar_t *home;
    HANDLE stop_event;
    SRWLOCK health_lock;
    struct wls_bootstrap_receipt receipt;
    ULONGLONG continuous_since_ms;
    ULONGLONG last_success_ms;
    int observation_mode;
    int observation_failed;
    char expected_slot[2];
    char expected_runtime_generation[65];
};

static HANDLE wls_stop_event = NULL;
static SRWLOCK wls_stop_event_lock = SRWLOCK_INIT;

static int wls_json_string(
    const char *json,
    const char *name,
    char *output,
    size_t capacity
);
static int wls_json_unsigned_long_long(
    const char *json,
    const char *name,
    unsigned long long *value
);

static int wls_checked_add_ll(long long value, long long increment, long long *result)
{
    if (result == NULL
        || (increment > 0 && value > LLONG_MAX - increment)
        || (increment < 0 && value < LLONG_MIN - increment)) return 1;
    *result = value + increment;
    return 0;
}

static int wls_checked_add_ull(
    ULONGLONG value,
    ULONGLONG increment,
    ULONGLONG *result
) {
    if (result == NULL || value > ULLONG_MAX - increment) return 1;
    *result = value + increment;
    return 0;
}

static int wls_sid_gate_acquire(
    struct wls_sid_gate *gate,
    const char *sid,
    DWORD limit
) {
    DWORD index;
    DWORD available = WLS_SID_GATE_SLOTS;
    if (limit == 0U) return 1;
    if (gate == NULL || sid == NULL || sid[0] == '\0') return 0;
    AcquireSRWLockExclusive(&gate->lock);
    for (index = 0U; index < WLS_SID_GATE_SLOTS; index++) {
        if (gate->entries[index].active == 0U) {
            if (available == WLS_SID_GATE_SLOTS) available = index;
            continue;
        }
        if (_stricmp(gate->entries[index].sid, sid) != 0) continue;
        if (gate->entries[index].active >= limit) {
            ReleaseSRWLockExclusive(&gate->lock);
            return 0;
        }
        gate->entries[index].active++;
        ReleaseSRWLockExclusive(&gate->lock);
        return 1;
    }
    if (available < WLS_SID_GATE_SLOTS) {
        if (strcpy_s(
                gate->entries[available].sid,
                sizeof(gate->entries[available].sid),
                sid
            ) == 0) {
            gate->entries[available].active = 1U;
            ReleaseSRWLockExclusive(&gate->lock);
            return 1;
        }
    }
    ReleaseSRWLockExclusive(&gate->lock);
    return 0;
}

static void wls_sid_gate_release(
    struct wls_sid_gate *gate,
    const char *sid,
    DWORD limit
) {
    DWORD index;
    if (limit == 0U || gate == NULL || sid == NULL || sid[0] == '\0') return;
    AcquireSRWLockExclusive(&gate->lock);
    for (index = 0U; index < WLS_SID_GATE_SLOTS; index++) {
        if (gate->entries[index].active == 0U
            || _stricmp(gate->entries[index].sid, sid) != 0) continue;
        gate->entries[index].active--;
        if (gate->entries[index].active == 0U) {
            SecureZeroMemory(
                gate->entries[index].sid,
                sizeof(gate->entries[index].sid)
            );
        }
        break;
    }
    ReleaseSRWLockExclusive(&gate->lock);
}

static int wls_owner_sid(HANDLE handle, char *output, size_t capacity);
static int wls_thread_sid(char *output, size_t capacity);
static int wls_begin_client_impersonation(HANDLE pipe, const char *expected_sid);
static void wls_end_client_impersonation(void);

static void wls_signal_stop_event(void)
{
    AcquireSRWLockShared(&wls_stop_event_lock);
    if (wls_stop_event != NULL) {
        SetEvent(wls_stop_event);
    }
    ReleaseSRWLockShared(&wls_stop_event_lock);
}

static void wls_publish_stop_event(HANDLE stop_event)
{
    AcquireSRWLockExclusive(&wls_stop_event_lock);
    wls_stop_event = stop_event;
    ReleaseSRWLockExclusive(&wls_stop_event_lock);
}

static void wls_unpublish_stop_event(HANDLE stop_event)
{
    AcquireSRWLockExclusive(&wls_stop_event_lock);
    if (wls_stop_event == stop_event) {
        wls_stop_event = NULL;
    }
    ReleaseSRWLockExclusive(&wls_stop_event_lock);
}

static int wls_read_exact(HANDLE file, void *contents, DWORD length)
{
    DWORD offset = 0U;
    while (offset < length) {
        DWORD amount = 0U;
        if (!ReadFile(
                file,
                (unsigned char *)contents + offset,
                length - offset,
                &amount,
                NULL
            )
            || amount == 0U) {
            return 1;
        }
        offset += amount;
    }
    return 0;
}

static int wls_write_all(HANDLE file, const void *contents, DWORD length)
{
    DWORD offset = 0U;
    while (offset < length) {
        DWORD amount = 0U;
        if (!WriteFile(
                file,
                (const unsigned char *)contents + offset,
                length - offset,
                &amount,
                NULL
            )
            || amount == 0U) {
            return 1;
        }
        offset += amount;
    }
    return 0;
}

static BOOL WINAPI wls_console_handler(DWORD signal_type)
{
    if (signal_type == CTRL_C_EVENT
        || signal_type == CTRL_BREAK_EVENT
        || signal_type == CTRL_CLOSE_EVENT
        || signal_type == CTRL_SHUTDOWN_EVENT) {
        wls_signal_stop_event();
        return TRUE;
    }
    return FALSE;
}

static int wls_valid_stop_event(const wchar_t *name)
{
    static const wchar_t prefix[] = L"Local\\WelineWlsGatewayV2Stop-";
    const wchar_t *cursor;
    size_t digits = 0U;
    if (name == NULL || wcsncmp(name, prefix, (sizeof(prefix) / sizeof(prefix[0])) - 1U) != 0) {
        return 0;
    }
    cursor = name + (sizeof(prefix) / sizeof(prefix[0])) - 1U;
    while (*cursor != L'\0') {
        if (*cursor < L'0' || *cursor > L'9' || ++digits > 10U) return 0;
        cursor++;
    }
    return digits > 0U;
}

static int wls_valid_ready_event(const wchar_t *name)
{
    static const wchar_t prefix[] = L"Local\\WelineWlsGatewayV2Ready-";
    const wchar_t *cursor;
    size_t digits = 0U;
    size_t nonce = 0U;
    if (name == NULL
        || wcsncmp(
            name,
            prefix,
            (sizeof(prefix) / sizeof(prefix[0])) - 1U
        ) != 0) {
        return 0;
    }
    cursor = name + (sizeof(prefix) / sizeof(prefix[0])) - 1U;
    while (*cursor != L'\0' && *cursor != L'-') {
        if (*cursor < L'0' || *cursor > L'9' || ++digits > 10U) return 0;
        cursor++;
    }
    if (digits == 0U || *cursor != L'-') return 0;
    cursor++;
    while (*cursor != L'\0') {
        if (!((*cursor >= L'0' && *cursor <= L'9')
                || (*cursor >= L'a' && *cursor <= L'f'))
            || ++nonce > 32U) {
            return 0;
        }
        cursor++;
    }
    return nonce == 32U;
}

static int wls_safe_relative(const wchar_t *relative)
{
    const wchar_t *cursor;
    const wchar_t *segment;
    size_t length;
    if (relative == NULL
        || relative[0] == L'\0'
        || relative[0] == L'\\'
        || relative[0] == L'/'
        || wcschr(relative, L':') != NULL) {
        return 0;
    }
    segment = relative;
    for (cursor = relative; ; cursor++) {
        if (*cursor != L'\\' && *cursor != L'/' && *cursor != L'\0') {
            continue;
        }
        length = (size_t)(cursor - segment);
        if (length == 0U
            || (length == 1U && segment[0] == L'.')
            || (length == 2U && segment[0] == L'.' && segment[1] == L'.')) {
            return 0;
        }
        if (*cursor == L'\0') {
            break;
        }
        segment = cursor + 1;
    }
    return 1;
}

static int wls_handle_is_reparse(HANDLE handle)
{
    FILE_ATTRIBUTE_TAG_INFO attributes;
    if (!GetFileInformationByHandleEx(
        handle,
        FileAttributeTagInfo,
        &attributes,
        sizeof(attributes))) {
        return 1;
    }
    return (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0;
}

static HANDLE wls_open_root(const wchar_t *path, DWORD access)
{
    HANDLE handle = CreateFileW(
        path,
        access,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (handle == INVALID_HANDLE_VALUE || wls_handle_is_reparse(handle)) {
        if (handle != INVALID_HANDLE_VALUE) CloseHandle(handle);
        return INVALID_HANDLE_VALUE;
    }
    return handle;
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
    if (ntdll == NULL || name_length == 0U || name_length > 32767U) {
        SetLastError(ERROR_INVALID_NAME);
        return INVALID_HANDLE_VALUE;
    }
    nt_create_file = (wls_nt_create_file_fn)(void *)GetProcAddress(ntdll, "NtCreateFile");
    if (nt_create_file == NULL) {
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
        FILE_OPEN_REPARSE_POINT
            | FILE_SYNCHRONOUS_IO_NONALERT
            | (directory ? FILE_DIRECTORY_FILE : FILE_NON_DIRECTORY_FILE),
        NULL,
        0U
    );
    if (status < 0 || child == INVALID_HANDLE_VALUE || wls_handle_is_reparse(child)) {
        if (child != INVALID_HANDLE_VALUE) CloseHandle(child);
        SetLastError(RtlNtStatusToDosError(status));
        return INVALID_HANDLE_VALUE;
    }
    return child;
}

static HANDLE wls_open_relative(
    HANDLE root,
    const wchar_t *relative,
    ACCESS_MASK final_access,
    ULONG final_share_access,
    ULONG final_disposition,
    int final_directory
) {
    const wchar_t *segment;
    const wchar_t *cursor;
    HANDLE current;
    HANDLE duplicate = INVALID_HANDLE_VALUE;
    if (!wls_safe_relative(relative)) {
        SetLastError(ERROR_INVALID_NAME);
        return INVALID_HANDLE_VALUE;
    }
    if (!DuplicateHandle(
        GetCurrentProcess(),
        root,
        GetCurrentProcess(),
        &duplicate,
        0U,
        FALSE,
        DUPLICATE_SAME_ACCESS
    )) {
        return INVALID_HANDLE_VALUE;
    }
    current = duplicate;
    if (current == INVALID_HANDLE_VALUE) return INVALID_HANDLE_VALUE;
    segment = relative;
    for (cursor = relative; ; cursor++) {
        if (*cursor != L'\\' && *cursor != L'/' && *cursor != L'\0') continue;
        {
            int last = *cursor == L'\0';
            HANDLE next = wls_nt_open_child(
                current,
                segment,
                (size_t)(cursor - segment),
                last ? final_access : FILE_LIST_DIRECTORY | FILE_TRAVERSE,
                last
                    ? final_share_access
                    : FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
                last ? final_disposition : FILE_OPEN,
                last ? final_directory : 1
            );
            CloseHandle(current);
            if (next == INVALID_HANDLE_VALUE) return INVALID_HANDLE_VALUE;
            current = next;
            if (last) break;
            segment = cursor + 1;
        }
    }
    return current;
}

static HANDLE wls_open_parent(
    HANDLE root,
    const wchar_t *relative,
    wchar_t *leaf,
    size_t leaf_capacity
) {
    wchar_t copy[WLS_PATH_CHARS];
    wchar_t *slash;
    size_t length;
    if (!wls_safe_relative(relative)
        || wcslen(relative) >= (sizeof(copy) / sizeof(copy[0]))) {
        SetLastError(ERROR_INVALID_NAME);
        return INVALID_HANDLE_VALUE;
    }
    wcscpy_s(copy, sizeof(copy) / sizeof(copy[0]), relative);
    slash = wcsrchr(copy, L'\\');
    if (slash == NULL) slash = wcsrchr(copy, L'/');
    if (slash == NULL) {
        length = wcslen(copy);
        if (length + 1U > leaf_capacity) return INVALID_HANDLE_VALUE;
        wcscpy_s(leaf, leaf_capacity, copy);
        {
            HANDLE duplicate = INVALID_HANDLE_VALUE;
            if (!DuplicateHandle(
                GetCurrentProcess(),
                root,
                GetCurrentProcess(),
                &duplicate,
                0U,
                FALSE,
                DUPLICATE_SAME_ACCESS)) {
                return INVALID_HANDLE_VALUE;
            }
            return duplicate;
        }
    }
    *slash = L'\0';
    slash++;
    if (wcslen(slash) + 1U > leaf_capacity) return INVALID_HANDLE_VALUE;
    wcscpy_s(leaf, leaf_capacity, slash);
    return wls_open_relative(
        root,
        copy,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | DELETE,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        FILE_OPEN,
        1
    );
}

static int wls_allowed_private_reader(
    PSID sid,
    PSID owner,
    PSID service,
    PSID system,
    PSID administrators,
    PSID creator_owner,
    PSID owner_rights
)
{
    return IsValidSid(sid)
        && ((owner != NULL && EqualSid(sid, owner))
            || (service != NULL && EqualSid(sid, service))
            || EqualSid(sid, system)
            || EqualSid(sid, administrators)
            || EqualSid(sid, creator_owner)
            || EqualSid(sid, owner_rights));
}

static int wls_private_acl_safe(HANDLE file, const char *expected_owner_sid)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PACL dacl = NULL;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    ACL_SIZE_INFORMATION acl_info;
    BYTE system_buffer[SECURITY_MAX_SID_SIZE];
    BYTE administrators_buffer[SECURITY_MAX_SID_SIZE];
    BYTE creator_owner_buffer[SECURITY_MAX_SID_SIZE];
    BYTE owner_rights_buffer[SECURITY_MAX_SID_SIZE];
    BYTE service_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD administrators_length = sizeof(administrators_buffer);
    DWORD creator_owner_length = sizeof(creator_owner_buffer);
    DWORD owner_rights_length = sizeof(owner_rights_buffer);
    DWORD service_length = sizeof(service_buffer);
    wchar_t service_domain[256];
    DWORD service_domain_length = sizeof(service_domain) / sizeof(service_domain[0]);
    SID_NAME_USE service_use;
    PSID owner = NULL;
    PSID service = NULL;
    char actual_owner_sid[256];
    const char *owner_text = expected_owner_sid;
    DWORD index;
    int result = 0;
    ZeroMemory(&acl_info, sizeof(acl_info));
    if (owner_text == NULL) {
        if (wls_owner_sid(file, actual_owner_sid, sizeof(actual_owner_sid)) != 0) {
            goto cleanup;
        }
        owner_text = actual_owner_sid;
    }
    if (!ConvertStringSidToSidA(owner_text, &owner)
        || !CreateWellKnownSid(
            WinLocalSystemSid,
            NULL,
            system_buffer,
            &system_length
        )
        || !CreateWellKnownSid(
            WinBuiltinAdministratorsSid,
            NULL,
            administrators_buffer,
            &administrators_length
        )
        || !CreateWellKnownSid(
            WinCreatorOwnerSid,
            NULL,
            creator_owner_buffer,
            &creator_owner_length
        )
        || !CreateWellKnownSid(
            WinOwnerRightsSid,
            NULL,
            owner_rights_buffer,
            &owner_rights_length
        )) {
        goto cleanup;
    }
    if (LookupAccountNameW(
        NULL,
        L"NT SERVICE\\weline-wls-gateway-v2",
        service_buffer,
        &service_length,
        service_domain,
        &service_domain_length,
        &service_use
    )) {
        service = service_buffer;
    }
    if (GetSecurityInfo(
        file,
        SE_FILE_OBJECT,
        DACL_SECURITY_INFORMATION,
        NULL,
        NULL,
        &dacl,
        NULL,
        &descriptor
    ) != ERROR_SUCCESS
        || !GetSecurityDescriptorDacl(
            descriptor,
            &dacl_present,
            &dacl,
            &dacl_defaulted
        )
        || !dacl_present
        || dacl == NULL
        || !GetAclInformation(
            dacl,
            &acl_info,
            sizeof(acl_info),
            AclSizeInformation
        )) {
        goto cleanup;
    }
    (void)dacl_defaulted;
    for (index = 0U; index < acl_info.AceCount; index++) {
        ACE_HEADER *header = NULL;
        ACCESS_ALLOWED_ACE *allowed;
        PSID allowed_sid;
        ACCESS_MASK rights;
        ACCESS_MASK mutation_rights;
        DWORD ace_size;
        DWORD sid_offset;
        GENERIC_MAPPING mapping = {
            FILE_GENERIC_READ,
            FILE_GENERIC_WRITE,
            FILE_GENERIC_EXECUTE,
            FILE_ALL_ACCESS
        };
        if (!GetAce(dacl, index, (LPVOID *)&header) || header == NULL) {
            goto cleanup;
        }
        if ((header->AceFlags & INHERIT_ONLY_ACE) != 0
            || (header->AceType != ACCESS_ALLOWED_ACE_TYPE
                && header->AceType != ACCESS_ALLOWED_COMPOUND_ACE_TYPE
                && header->AceType != ACCESS_ALLOWED_OBJECT_ACE_TYPE
                && header->AceType != ACCESS_ALLOWED_CALLBACK_ACE_TYPE
                && header->AceType != ACCESS_ALLOWED_CALLBACK_OBJECT_ACE_TYPE)) {
            continue;
        }
        if (header->AceSize < sizeof(ACE_HEADER) + sizeof(ACCESS_MASK)) {
            goto cleanup;
        }
        allowed = (ACCESS_ALLOWED_ACE *)header;
        ace_size = (DWORD)header->AceSize;
        sid_offset = (DWORD)FIELD_OFFSET(ACCESS_ALLOWED_ACE, SidStart);
        rights = allowed->Mask;
        MapGenericMask(&rights, &mapping);
        mutation_rights = FILE_WRITE_DATA | FILE_APPEND_DATA | FILE_WRITE_EA
            | FILE_WRITE_ATTRIBUTES | WRITE_DAC | WRITE_OWNER | DELETE;
        if ((rights & (FILE_READ_DATA | mutation_rights)) == 0U) {
            continue;
        }
        /*
         * Reject unknown readers, writers, and ACL owners. A source that can
         * be altered after enrollment is no safer than one that can be read.
         * Exotic allow ACEs are rejected instead of guessing SID offsets.
         */
        allowed_sid = (PSID)&allowed->SidStart;
        if (header->AceType != ACCESS_ALLOWED_ACE_TYPE
            || ace_size < sid_offset + 8U
            || !IsValidSid(allowed_sid)
            || GetLengthSid(allowed_sid)
                > ace_size - sid_offset
            || !wls_allowed_private_reader(
                allowed_sid,
                owner,
                service,
                system_buffer,
                administrators_buffer,
                creator_owner_buffer,
                owner_rights_buffer
            )
            || (service != NULL
                && EqualSid(allowed_sid, service)
                && (rights & mutation_rights) != 0U)) {
            goto cleanup;
        }
    }
    result = 1;
cleanup:
    if (owner != NULL) LocalFree(owner);
    if (descriptor != NULL) LocalFree(descriptor);
    return result;
}

static int wls_snapshot(
    const wchar_t *source_root_path,
    const wchar_t *source_relative,
    const wchar_t *destination_root_path,
    const wchar_t *destination_relative,
    const char *expected_owner_sid,
    int require_private,
    HANDLE client_pipe,
    const char *expected_peer_sid
) {
    HANDLE source_root = INVALID_HANDLE_VALUE;
    HANDLE source = INVALID_HANDLE_VALUE;
    HANDLE destination_root = INVALID_HANDLE_VALUE;
    HANDLE destination_parent = INVALID_HANDLE_VALUE;
    HANDLE temporary = INVALID_HANDLE_VALUE;
    FILE_STANDARD_INFO before_size;
    FILE_STANDARD_INFO after_size;
    FILE_BASIC_INFO before_time;
    FILE_BASIC_INFO after_time;
    FILE_DISPOSITION_INFO disposition = {TRUE};
    wchar_t destination_leaf[WLS_PATH_CHARS];
    wchar_t temporary_leaf[96];
    wchar_t random_suffix[17];
    BYTE buffer[65536];
    DWORD read_amount;
    uint64_t total = 0U;
    char source_root_owner[256];
    char source_owner[256];
    char source_owner_after[256];
    int impersonating = 0;
    int result = 1;

    if (client_pipe != NULL) {
        if (wls_begin_client_impersonation(client_pipe, expected_peer_sid) != 0) {
            goto cleanup;
        }
        impersonating = 1;
    }
    source_root = wls_open_root(
        source_root_path,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | READ_CONTROL
    );
    if (source_root == INVALID_HANDLE_VALUE
        || (expected_owner_sid != NULL
            && (wls_owner_sid(
                    source_root,
                    source_root_owner,
                    sizeof(source_root_owner)
                ) != 0
                || _stricmp(source_root_owner, expected_owner_sid) != 0))) {
        goto cleanup;
    }
    source = wls_open_relative(
        source_root,
        source_relative,
        FILE_GENERIC_READ,
        FILE_SHARE_READ,
        FILE_OPEN,
        0
    );
    if (source == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(source, FileStandardInfo, &before_size, sizeof(before_size))
        || !GetFileInformationByHandleEx(source, FileBasicInfo, &before_time, sizeof(before_time))
        || before_size.Directory
        || before_size.NumberOfLinks != 1U
        || before_size.EndOfFile.QuadPart < 1
        || before_size.EndOfFile.QuadPart > (LONGLONG)WLS_MAX_SNAPSHOT
        || (expected_owner_sid != NULL
            && (wls_owner_sid(source, source_owner, sizeof(source_owner)) != 0
                || _stricmp(source_owner, expected_owner_sid) != 0))
        || (require_private
            && !wls_private_acl_safe(source, expected_owner_sid))) {
        goto cleanup;
    }
    if (impersonating) {
        wls_end_client_impersonation();
        impersonating = 0;
    }
    destination_root = wls_open_root(
        destination_root_path,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | DELETE
    );
    if (destination_root == INVALID_HANDLE_VALUE) goto cleanup;
    destination_parent = wls_open_parent(
        destination_root,
        destination_relative,
        destination_leaf,
        sizeof(destination_leaf) / sizeof(destination_leaf[0])
    );
    if (destination_parent == INVALID_HANDLE_VALUE) goto cleanup;
    if (wls_random_suffix_w(random_suffix) != 0
        || _snwprintf_s(
        temporary_leaf,
        sizeof(temporary_leaf) / sizeof(temporary_leaf[0]),
        _TRUNCATE,
        L".wls-snapshot-%lu-%ls",
        GetCurrentProcessId(),
        random_suffix
    ) < 0) goto cleanup;
    temporary = wls_nt_open_child(
        destination_parent,
        temporary_leaf,
        wcslen(temporary_leaf),
        FILE_GENERIC_WRITE | DELETE | SYNCHRONIZE,
        0U,
        FILE_CREATE,
        0
    );
    if (temporary == INVALID_HANDLE_VALUE) goto cleanup;
    for (;;) {
        if (!ReadFile(source, buffer, sizeof(buffer), &read_amount, NULL)) {
            goto cleanup;
        }
        if (read_amount == 0U) {
            break;
        }
        total += read_amount;
        if (total > WLS_MAX_SNAPSHOT
            || wls_write_all(temporary, buffer, read_amount) != 0) {
            goto cleanup;
        }
    }
    if (total != (uint64_t)before_size.EndOfFile.QuadPart
        || !FlushFileBuffers(temporary)
        || !GetFileInformationByHandleEx(source, FileStandardInfo, &after_size, sizeof(after_size))
        || !GetFileInformationByHandleEx(source, FileBasicInfo, &after_time, sizeof(after_time))
        || before_size.EndOfFile.QuadPart != after_size.EndOfFile.QuadPart
        || before_size.AllocationSize.QuadPart != after_size.AllocationSize.QuadPart
        || before_size.NumberOfLinks != after_size.NumberOfLinks
        || after_size.NumberOfLinks != 1U
        || before_size.DeletePending != after_size.DeletePending
        || before_time.CreationTime.QuadPart != after_time.CreationTime.QuadPart
        || before_time.LastWriteTime.QuadPart != after_time.LastWriteTime.QuadPart
        || before_time.ChangeTime.QuadPart != after_time.ChangeTime.QuadPart
        || before_time.FileAttributes != after_time.FileAttributes
        || (expected_owner_sid != NULL
            && (wls_owner_sid(
                    source,
                    source_owner_after,
                    sizeof(source_owner_after)
                ) != 0
                || _stricmp(source_owner_after, expected_owner_sid) != 0))
        || (require_private
            && !wls_private_acl_safe(source, expected_owner_sid))) {
        goto cleanup;
    }
    {
        size_t leaf_bytes = wcslen(destination_leaf) * sizeof(wchar_t);
        size_t structure_bytes = sizeof(FILE_RENAME_INFO) + leaf_bytes;
        FILE_RENAME_INFO *rename_info = (FILE_RENAME_INFO *)HeapAlloc(
            GetProcessHeap(),
            HEAP_ZERO_MEMORY,
            structure_bytes
        );
        if (rename_info == NULL) goto cleanup;
        rename_info->ReplaceIfExists = TRUE;
        rename_info->RootDirectory = destination_parent;
        rename_info->FileNameLength = (DWORD)leaf_bytes;
        memcpy(rename_info->FileName, destination_leaf, leaf_bytes);
        if (!SetFileInformationByHandle(
            temporary,
            FileRenameInfo,
            rename_info,
            (DWORD)structure_bytes)) {
            HeapFree(GetProcessHeap(), 0U, rename_info);
            goto cleanup;
        }
        HeapFree(GetProcessHeap(), 0U, rename_info);
    }
    result = 0;

cleanup:
    if (impersonating) {
        wls_end_client_impersonation();
    }
    if (result != 0 && temporary != INVALID_HANDLE_VALUE) {
        SetFileInformationByHandle(
            temporary,
            FileDispositionInfo,
            &disposition,
            sizeof(disposition)
        );
    }
    if (temporary != INVALID_HANDLE_VALUE) CloseHandle(temporary);
    if (destination_parent != INVALID_HANDLE_VALUE) CloseHandle(destination_parent);
    if (destination_root != INVALID_HANDLE_VALUE) CloseHandle(destination_root);
    if (source != INVALID_HANDLE_VALUE) CloseHandle(source);
    if (source_root != INVALID_HANDLE_VALUE) CloseHandle(source_root);
    return result;
}

static int wls_fencing(char output[WLS_FENCING_BYTES * 2U + 1U])
{
    static const char hex[] = "0123456789abcdef";
    BYTE bytes[WLS_FENCING_BYTES];
    size_t index;
    if (BCryptGenRandom(NULL, bytes, sizeof(bytes), BCRYPT_USE_SYSTEM_PREFERRED_RNG) != 0) {
        return 1;
    }
    for (index = 0U; index < sizeof(bytes); index++) {
        output[index * 2U] = hex[bytes[index] >> 4U];
        output[index * 2U + 1U] = hex[bytes[index] & 0x0fU];
    }
    output[WLS_FENCING_BYTES * 2U] = '\0';
    SecureZeroMemory(bytes, sizeof(bytes));
    return 0;
}

static int wls_random_suffix_w(wchar_t output[17])
{
    BYTE bytes[8];
    static const wchar_t hex[] = L"0123456789abcdef";
    size_t index;
    if (BCryptGenRandom(
        NULL,
        bytes,
        sizeof(bytes),
        BCRYPT_USE_SYSTEM_PREFERRED_RNG
    ) != 0) {
        return 1;
    }
    for (index = 0U; index < sizeof(bytes); index++) {
        output[index * 2U] = hex[bytes[index] >> 4U];
        output[index * 2U + 1U] = hex[bytes[index] & 0x0fU];
    }
    output[16] = L'\0';
    SecureZeroMemory(bytes, sizeof(bytes));
    return 0;
}

static int wls_join_w(
    wchar_t *output,
    size_t capacity,
    const wchar_t *left,
    const wchar_t *right
) {
    size_t left_length;
    int written;
    if (output == NULL || capacity < 2U || left == NULL || right == NULL) return 1;
    left_length = wcslen(left);
    written = _snwprintf_s(
        output,
        capacity,
        _TRUNCATE,
        left_length > 0U && (left[left_length - 1U] == L'\\' || left[left_length - 1U] == L'/')
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

static int wls_is_uuid(const char *value)
{
    size_t index;
    if (value == NULL || strlen(value) != 36U) return 0;
    for (index = 0U; index < 36U; index++) {
        if (index == 8U || index == 13U || index == 18U || index == 23U) {
            if (value[index] != '-') return 0;
        } else if (!((value[index] >= '0' && value[index] <= '9')
            || (value[index] >= 'a' && value[index] <= 'f'))) {
            return 0;
        }
    }
    return value[14U] == '4'
        && (value[19U] == '8' || value[19U] == '9'
            || value[19U] == 'a' || value[19U] == 'b');
}

static int wls_is_alias(const char *value)
{
    size_t index;
    size_t length;
    if (value == NULL) return 0;
    length = strlen(value);
    if (length < 1U || length > 32U
        || value[0] < 'a' || value[0] > 'z') {
        return 0;
    }
    for (index = 1U; index < length; index++) {
        if (!((value[index] >= 'a' && value[index] <= 'z')
            || (value[index] >= '0' && value[index] <= '9')
            || value[index] == '_')) {
            return 0;
        }
    }
    return 1;
}

static int wls_is_sid_text(const char *value)
{
    size_t index;
    size_t length;
    if (value == NULL) return 0;
    length = strlen(value);
    if (length < 7U || length > 184U
        || (value[0] != 'S' && value[0] != 's')
        || value[1] != '-' || value[2] != '1' || value[3] != '-') {
        return 0;
    }
    for (index = 4U; index < length; index++) {
        if ((value[index] < '0' || value[index] > '9') && value[index] != '-') return 0;
        if (value[index] == '-' && (index + 1U == length || value[index + 1U] == '-')) return 0;
    }
    return 1;
}

static int wls_hex_to_utf8(
    const char *hex,
    char *output,
    size_t capacity
) {
    size_t length;
    size_t index;
    if (hex == NULL || output == NULL) return 1;
    length = strlen(hex);
    if (length == 0U || (length & 1U) != 0U || length / 2U + 1U > capacity) return 1;
    for (index = 0U; index < length; index += 2U) {
        unsigned int byte;
        if (sscanf_s(hex + index, "%2x", &byte) != 1 || byte == 0U) return 1;
        output[index / 2U] = (char)byte;
    }
    output[length / 2U] = '\0';
    return 0;
}

static int wls_utf8_to_wide(
    const char *input,
    wchar_t *output,
    size_t capacity
) {
    int amount;
    if (input == NULL || output == NULL || capacity > (size_t)INT_MAX) return 1;
    amount = MultiByteToWideChar(
        CP_UTF8,
        MB_ERR_INVALID_CHARS,
        input,
        -1,
        output,
        (int)capacity
    );
    return amount > 0 ? 0 : 1;
}

static int wls_hex_to_wide(
    const char *hex,
    wchar_t *output,
    size_t capacity
) {
    char *utf8;
    size_t bytes;
    int result;
    if (hex == NULL) return 1;
    bytes = strlen(hex) / 2U + 1U;
    utf8 = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, bytes);
    if (utf8 == NULL) return 1;
    result = wls_hex_to_utf8(hex, utf8, bytes) != 0
        || wls_utf8_to_wide(utf8, output, capacity) != 0;
    SecureZeroMemory(utf8, bytes);
    HeapFree(GetProcessHeap(), 0U, utf8);
    return result;
}

static int wls_owner_sid(HANDLE handle, char *output, size_t capacity)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PSID owner = NULL;
    LPWSTR owner_text = NULL;
    DWORD status;
    int result = 1;
    status = GetSecurityInfo(
        handle,
        SE_FILE_OBJECT,
        OWNER_SECURITY_INFORMATION,
        &owner,
        NULL,
        NULL,
        NULL,
        &descriptor
    );
    if (status != ERROR_SUCCESS || owner == NULL
        || !ConvertSidToStringSidW(owner, &owner_text)
        || WideCharToMultiByte(
            CP_UTF8,
            WC_ERR_INVALID_CHARS,
            owner_text,
            -1,
            output,
            (int)capacity,
            NULL,
            NULL
        ) <= 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (owner_text != NULL) LocalFree(owner_text);
    if (descriptor != NULL) LocalFree(descriptor);
    return result;
}

static int wls_thread_sid(char *output, size_t capacity)
{
    HANDLE token = NULL;
    DWORD required = 0U;
    TOKEN_USER *user = NULL;
    LPWSTR sid_string = NULL;
    int result = 1;
    if (output == NULL || capacity < 8U
        || !OpenThreadToken(GetCurrentThread(), TOKEN_QUERY, TRUE, &token)) {
        goto cleanup;
    }
    GetTokenInformation(token, TokenUser, NULL, 0U, &required);
    if (required < sizeof(TOKEN_USER)) goto cleanup;
    user = (TOKEN_USER *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, required);
    if (user == NULL
        || !GetTokenInformation(token, TokenUser, user, required, &required)
        || !ConvertSidToStringSidW(user->User.Sid, &sid_string)
        || WideCharToMultiByte(
            CP_UTF8,
            WC_ERR_INVALID_CHARS,
            sid_string,
            -1,
            output,
            (int)capacity,
            NULL,
            NULL
        ) <= 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (sid_string != NULL) LocalFree(sid_string);
    if (user != NULL) HeapFree(GetProcessHeap(), 0U, user);
    if (token != NULL) CloseHandle(token);
    return result;
}

static void wls_end_client_impersonation(void)
{
    /* Continuing under a project token would cross the Broker trust boundary. */
    if (!RevertToSelf()) {
        ExitProcess(90U);
    }
}

static int wls_begin_client_impersonation(HANDLE pipe, const char *expected_sid)
{
    char actual_sid[256];
    if (pipe == NULL || !ImpersonateNamedPipeClient(pipe)) {
        return 1;
    }
    if (wls_thread_sid(actual_sid, sizeof(actual_sid)) != 0
        || (expected_sid != NULL && _stricmp(actual_sid, expected_sid) != 0)) {
        wls_end_client_impersonation();
        return 1;
    }
    return 0;
}

static int wls_final_path(HANDLE handle, wchar_t *output, size_t capacity)
{
    DWORD amount = GetFinalPathNameByHandleW(
        handle,
        output,
        (DWORD)capacity,
        FILE_NAME_NORMALIZED | VOLUME_NAME_DOS
    );
    return amount > 0U && amount < capacity ? 0 : 1;
}

static int wls_path_within(const wchar_t *path, const wchar_t *root)
{
    size_t length;
    size_t path_length;
    if (path == NULL || root == NULL) return 0;
    length = wcslen(root);
    path_length = wcslen(path);
    if (path_length < length) return 0;
    if (_wcsnicmp(path, root, length) != 0) return 0;
    return path[length] == L'\0' || path[length] == L'\\' || path[length] == L'/';
}

static int wls_read_small_file(
    const wchar_t *path,
    char *output,
    size_t capacity,
    size_t *length
) {
    HANDLE file;
    FILE_STANDARD_INFO size;
    DWORD amount;
    file = CreateFileW(
        path,
        GENERIC_READ,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(file)
        || !GetFileInformationByHandleEx(file, FileStandardInfo, &size, sizeof(size))
        || size.EndOfFile.QuadPart < 0
        || (uint64_t)size.EndOfFile.QuadPart + 1U > capacity
        || wls_read_exact(file, output, (DWORD)size.EndOfFile.QuadPart) != 0) {
        if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
        return 1;
    }
    CloseHandle(file);
    amount = (DWORD)size.EndOfFile.QuadPart;
    output[amount] = '\0';
    if (length != NULL) *length = amount;
    return 0;
}

static int wls_atomic_bytes(
    const wchar_t *path,
    const void *contents,
    DWORD length
) {
    wchar_t temporary[WLS_PATH_CHARS];
    wchar_t random_suffix[17];
    HANDLE file = INVALID_HANDLE_VALUE;
    int result = 1;
    if (wls_random_suffix_w(random_suffix) != 0
        || _snwprintf_s(
        temporary,
        WLS_PATH_CHARS,
        _TRUNCATE,
        L"%ls.candidate.%lu.%ls",
        path,
        GetCurrentProcessId(),
        random_suffix
    ) < 0) {
        return 1;
    }
    file = CreateFileW(
        temporary,
        GENERIC_WRITE | DELETE,
        0U,
        NULL,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(file)
        || wls_write_all(file, contents, length) != 0
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

static int wls_hex_decode_fixed(
    const char *hex,
    unsigned char *output,
    size_t length
) {
    size_t index;
    if (hex == NULL || output == NULL || strlen(hex) != length * 2U) return 1;
    for (index = 0U; index < length; index++) {
        unsigned int byte;
        if (sscanf_s(hex + index * 2U, "%2x", &byte) != 1) return 1;
        output[index] = (unsigned char)byte;
    }
    return 0;
}

static int wls_hmac_sha256(
    const unsigned char *key,
    DWORD key_length,
    const unsigned char *payload,
    DWORD payload_length,
    unsigned char output[32]
) {
    BCRYPT_ALG_HANDLE algorithm = NULL;
    BCRYPT_HASH_HANDLE hash = NULL;
    PUCHAR object = NULL;
    DWORD object_length = 0U;
    DWORD result_length = 0U;
    NTSTATUS status;
    int result = 1;
    status = BCryptOpenAlgorithmProvider(
        &algorithm,
        BCRYPT_SHA256_ALGORITHM,
        NULL,
        BCRYPT_ALG_HANDLE_HMAC_FLAG
    );
    if (status != 0) goto cleanup;
    status = BCryptGetProperty(
        algorithm,
        BCRYPT_OBJECT_LENGTH,
        (PUCHAR)&object_length,
        sizeof(object_length),
        &result_length,
        0U
    );
    if (status != 0 || object_length == 0U) goto cleanup;
    object = (PUCHAR)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, object_length);
    if (object == NULL) goto cleanup;
    status = BCryptCreateHash(
        algorithm,
        &hash,
        object,
        object_length,
        (PUCHAR)key,
        key_length,
        0U
    );
    if (status != 0
        || BCryptHashData(hash, (PUCHAR)payload, payload_length, 0U) != 0
        || BCryptFinishHash(hash, output, 32U, 0U) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (hash != NULL) BCryptDestroyHash(hash);
    if (object != NULL) {
        SecureZeroMemory(object, object_length);
        HeapFree(GetProcessHeap(), 0U, object);
    }
    if (algorithm != NULL) BCryptCloseAlgorithmProvider(algorithm, 0U);
    return result;
}

static int wls_sha256(
    const unsigned char *payload,
    DWORD payload_length,
    unsigned char output[32]
) {
    BCRYPT_ALG_HANDLE algorithm = NULL;
    BCRYPT_HASH_HANDLE hash = NULL;
    PUCHAR object = NULL;
    DWORD object_length = 0U;
    DWORD result_length = 0U;
    int result = 1;
    if (payload == NULL || output == NULL
        || BCryptOpenAlgorithmProvider(
            &algorithm, BCRYPT_SHA256_ALGORITHM, NULL, 0U
        ) != 0
        || BCryptGetProperty(
            algorithm, BCRYPT_OBJECT_LENGTH, (PUCHAR)&object_length,
            sizeof(object_length), &result_length, 0U
        ) != 0
        || object_length == 0U) {
        goto cleanup;
    }
    object = (PUCHAR)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, object_length);
    if (object == NULL
        || BCryptCreateHash(
            algorithm, &hash, object, object_length, NULL, 0U, 0U
        ) != 0
        || BCryptHashData(hash, (PUCHAR)payload, payload_length, 0U) != 0
        || BCryptFinishHash(hash, output, 32U, 0U) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (hash != NULL) BCryptDestroyHash(hash);
    if (object != NULL) {
        SecureZeroMemory(object, object_length);
        HeapFree(GetProcessHeap(), 0U, object);
    }
    if (algorithm != NULL) BCryptCloseAlgorithmProvider(algorithm, 0U);
    return result;
}

static void wls_hex_encode_32(const unsigned char input[32], char output[65])
{
    static const char alphabet[] = "0123456789abcdef";
    size_t index;
    for (index = 0U; index < 32U; index++) {
        output[index * 2U] = alphabet[input[index] >> 4U];
        output[index * 2U + 1U] = alphabet[input[index] & 0x0fU];
    }
    output[64] = '\0';
}

static int wls_write_admin_stopped(
    const wchar_t *home,
    const char *epoch
) {
    wchar_t token_path[WLS_PATH_CHARS];
    wchar_t host_path[WLS_PATH_CHARS];
    wchar_t intent_path[WLS_PATH_CHARS];
    char token_text[128];
    char host_text[64];
    size_t token_length = 0U;
    size_t host_length = 0U;
    unsigned char key[32];
    unsigned char signature[32];
    char signature_hex[65];
    char nonce[65];
    char payload[512];
    char intent[640];
    int payload_length;
    int intent_length;
    size_t index;
    int result = 1;
    if (home == NULL || !wls_is_hex(epoch, 32U)
        || wls_join_w(token_path, WLS_PATH_CHARS, home, L"trust\\admin.token") != 0
        || wls_join_w(host_path, WLS_PATH_CHARS, home, L"trust\\host-id") != 0
        || wls_join_w(intent_path, WLS_PATH_CHARS, home, L"trust\\admin-stopped.intent") != 0
        || wls_read_small_file(token_path, token_text, sizeof(token_text), &token_length) != 0
        || wls_read_small_file(host_path, host_text, sizeof(host_text), &host_length) != 0) {
        goto cleanup;
    }
    while (token_length > 0U && (token_text[token_length - 1U] == '\r'
        || token_text[token_length - 1U] == '\n'
        || token_text[token_length - 1U] == ' '
        || token_text[token_length - 1U] == '\t')) token_length--;
    token_text[token_length] = '\0';
    while (host_length > 0U && (host_text[host_length - 1U] == '\r'
        || host_text[host_length - 1U] == '\n'
        || host_text[host_length - 1U] == ' '
        || host_text[host_length - 1U] == '\t')) host_length--;
    host_text[host_length] = '\0';
    if (!wls_is_hex(token_text, 64U)
        || !wls_is_hex(host_text, 32U)
        || wls_hex_decode_fixed(token_text, key, sizeof(key)) != 0
        || wls_fencing(nonce) != 0) {
        goto cleanup;
    }
    nonce[32] = '\0';
    payload_length = _snprintf_s(
        payload,
        sizeof(payload),
        _TRUNCATE,
        "WLS-ADMIN-STOPPED/1\nhost_id=%s\nepoch=%s\nat=%lld\nnonce=%s\n",
        host_text,
        epoch,
        (long long)time(NULL),
        nonce
    );
    if (payload_length <= 0
        || wls_hmac_sha256(
            key,
            sizeof(key),
            (const unsigned char *)payload,
            (DWORD)payload_length,
            signature
        ) != 0) {
        goto cleanup;
    }
    for (index = 0U; index < sizeof(signature); index++) {
        _snprintf_s(
            signature_hex + index * 2U,
            sizeof(signature_hex) - index * 2U,
            _TRUNCATE,
            "%02x",
            signature[index]
        );
    }
    intent_length = _snprintf_s(
        intent,
        sizeof(intent),
        _TRUNCATE,
        "%ssignature=%s\n",
        payload,
        signature_hex
    );
    if (intent_length <= 0
        || wls_atomic_bytes(intent_path, intent, (DWORD)intent_length) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    SecureZeroMemory(key, sizeof(key));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(token_text, sizeof(token_text));
    return result;
}

static int wls_upgrade_boot_id(char output[65])
{
    static const char prefix[] = "wls-gateway-host-boot/1|";
    HMODULE ntdll = GetModuleHandleW(L"ntdll.dll");
    wls_nt_query_system_information_fn query;
    struct wls_system_timeofday_information information;
    char platform_token[65];
    char canonical[sizeof(prefix) + sizeof(platform_token)];
    unsigned char digest[32];
    NTSTATUS status;
    int length;
    if (ntdll == NULL) return 1;
    query = (wls_nt_query_system_information_fn)GetProcAddress(
        ntdll, "NtQuerySystemInformation");
    if (query == NULL) return 1;
    ZeroMemory(&information, sizeof(information));
    status = query((SYSTEM_INFORMATION_CLASS)3, &information,
        (ULONG)sizeof(information), NULL);
    if (status < 0) return 1;
    length = _snprintf_s(platform_token, sizeof(platform_token), _TRUNCATE,
        "windows-%016llx",
        (unsigned long long)information.boot_time.QuadPart);
    if (length <= 0) return 1;
    length = _snprintf_s(canonical, sizeof(canonical), _TRUNCATE,
        "%s%s", prefix, platform_token);
    if (length <= 0
        || wls_sha256(
            (const unsigned char *)canonical,
            (DWORD)length,
            digest
        ) != 0) {
        SecureZeroMemory(digest, sizeof(digest));
        SecureZeroMemory(canonical, sizeof(canonical));
        return 1;
    }
    wls_hex_encode_32(digest, output);
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(canonical, sizeof(canonical));
    return wls_is_hex(output, 64U) ? 0 : 1;
}

static int wls_upgrade_binding_read(
    const wchar_t *home,
    struct wls_upgrade_binding *binding
) {
    wchar_t intent_path[WLS_PATH_CHARS], state_path[WLS_PATH_CHARS];
    char intent[2048], state[1024];
    size_t intent_length = 0U, state_length = 0U;
    unsigned char digest[32];
    char host[33], from[2], to[2], signature[65];
    char state_digest[65], state_nonce[33], state_runtime[65];
    long long legacy_deadline = 0;
    long long expected_legacy_deadline = 0;
    long long expected_total_deadline = 0;
    int intent_consumed = 0, state_consumed = 0, fields;
    ZeroMemory(binding, sizeof(*binding));
    if (wls_join_w(intent_path, WLS_PATH_CHARS, home, L"trust\\upgrade.intent") != 0
        || wls_join_w(state_path, WLS_PATH_CHARS, home, L"trust\\upgrade-state") != 0)
        return 1;
    if (wls_read_small_file(intent_path, intent, sizeof(intent), &intent_length) != 0) {
        DWORD error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND ? 0 : 1;
    }
    fields = sscanf(intent,
        "WLS-UPGRADE/1\nhost_id=%32[0-9a-f]\nfrom=%1[AB]\nto=%1[AB]\n"
        "prepared_at=%lld\ndeadline=%lld\nruntime_generation=%64[0-9a-f]\n"
        "nonce=%32[0-9a-f]\nsignature=%64[0-9a-f]\n%n",
        host, from, to, &binding->prepared_at, &legacy_deadline,
        binding->runtime_generation, binding->nonce, signature, &intent_consumed);
    if (fields != 8 || intent_consumed != (int)intent_length || from[0] == to[0]
        || binding->prepared_at < 1
        || wls_checked_add_ll(
            binding->prepared_at,
            300LL,
            &expected_legacy_deadline
        ) != 0
        || legacy_deadline != expected_legacy_deadline
        || intent_length > MAXDWORD
        || wls_sha256((const unsigned char *)intent, (DWORD)intent_length, digest) != 0
        || wls_read_small_file(state_path, state, sizeof(state), &state_length) != 0) {
        SecureZeroMemory(digest, sizeof(digest));
        return 1;
    }
    wls_hex_encode_32(digest, binding->intent_sha256);
    binding->from = (wchar_t)from[0];
    binding->to = (wchar_t)to[0];
    fields = sscanf(state,
        "WLS-UPGRADE-STATE/2\n"
        "intent_sha256=%64[0-9a-f]\nintent_nonce=%32[0-9a-f]\n"
        "from=%1[AB]\nto=%1[AB]\nruntime_generation=%64[0-9a-f]\n"
        "boot_id=%64[0-9A-Za-z-]\nphase=%23[A-Z_]\nattempts=%u\n"
        "observation_started_monotonic_ms=%llu\n"
        "observation_deadline_monotonic_ms=%llu\ntotal_deadline=%lld\n%n",
        state_digest, state_nonce, from, to, state_runtime,
        binding->boot_id, binding->phase, &binding->attempts,
        &binding->observation_started, &binding->observation_deadline,
        &binding->total_deadline, &state_consumed);
    SecureZeroMemory(digest, sizeof(digest));
    if (fields != 11 || state_consumed != (int)state_length
        || strcmp(state_digest, binding->intent_sha256) != 0
        || strcmp(state_nonce, binding->nonce) != 0
        || (wchar_t)from[0] != binding->from || (wchar_t)to[0] != binding->to
        || strcmp(state_runtime, binding->runtime_generation) != 0
        || wls_checked_add_ll(
            binding->prepared_at,
            900LL,
            &expected_total_deadline
        ) != 0
        || binding->total_deadline != expected_total_deadline) return 1;
    binding->present = 1;
    return 2;
}

static int wls_existing_upgrade_observation(
    const wchar_t *path,
    const struct wls_upgrade_binding *binding,
    ULONGLONG *started
) {
    char contents[768];
    size_t length = 0U;
    char digest[65], nonce[33], from[2], to[2], runtime[65], boot[65];
    ULONGLONG parsed_started = 0ULL, deadline = 0ULL;
    ULONGLONG expected_deadline = 0ULL;
    ULONGLONG now = GetTickCount64();
    int consumed = 0;
    if (wls_read_small_file(path, contents, sizeof(contents), &length) != 0) return 0;
    if (sscanf(contents,
        "WLS-UPGRADE-OBSERVING/2\n"
        "intent_sha256=%64[0-9a-f]\nintent_nonce=%32[0-9a-f]\n"
        "from=%1[AB]\nto=%1[AB]\nruntime_generation=%64[0-9a-f]\n"
        "boot_id=%64[0-9A-Za-z-]\nstarted_monotonic_ms=%llu\n"
        "deadline_monotonic_ms=%llu\n%n",
        digest, nonce, from, to, runtime, boot, &parsed_started, &deadline,
        &consumed) == 8 && consumed == (int)length
        && strcmp(digest, binding->intent_sha256) == 0
        && strcmp(nonce, binding->nonce) == 0
        && (wchar_t)from[0] == binding->from && (wchar_t)to[0] == binding->to
        && strcmp(runtime, binding->runtime_generation) == 0
        && strcmp(boot, binding->boot_id) == 0
        && parsed_started <= now
        && wls_checked_add_ull(
            parsed_started,
            WLS_UPGRADE_OBSERVATION_MILLISECONDS,
            &expected_deadline
        ) == 0
        && deadline == expected_deadline
        && now <= deadline) {
        *started = parsed_started;
        return 1;
    }
    return 0;
}

/* 0=no transaction, 1=candidate observation, 2=rollback health proof. */
static int wls_prepare_upgrade_runtime(
    const wchar_t *home,
    const wchar_t *active_slot,
    const char *runtime_generation,
    ULONGLONG *started
) {
    struct wls_upgrade_binding binding;
    wchar_t observing[WLS_PATH_CHARS], healthy[WLS_PATH_CHARS], rollback[WLS_PATH_CHARS];
    char boot_id[65], payload[768];
    ULONGLONG observation_deadline;
    int status, length;
    if (started == NULL || wls_upgrade_boot_id(boot_id) != 0) return -1;
    *started = GetTickCount64();
    status = wls_upgrade_binding_read(home, &binding);
    if (status == 0) return 0;
    if (status != 2 || strcmp(binding.boot_id, boot_id) != 0) return -1;
    if (wls_join_w(observing, WLS_PATH_CHARS, home, L"trust\\upgrade-observing") != 0
        || wls_join_w(healthy, WLS_PATH_CHARS, home, L"trust\\upgrade-healthy") != 0
        || wls_join_w(rollback, WLS_PATH_CHARS, home,
            L"trust\\upgrade-rollback-healthy") != 0) return -1;
    if (active_slot[0] == binding.from
        && strcmp(binding.phase, "ROLLBACK_PENDING") == 0) {
        (void)DeleteFileW(rollback);
        return 2;
    }
    if (active_slot[0] != binding.to
        || strcmp(runtime_generation, binding.runtime_generation) != 0
        || (strcmp(binding.phase, "PREPARED") != 0
            && strcmp(binding.phase, "OBSERVING") != 0)) return 0;
    if (wls_existing_upgrade_observation(observing, &binding, started)) return 1;
    if (strcmp(binding.phase, "OBSERVING") == 0) return -1;
    if (wls_checked_add_ull(
            *started,
            WLS_UPGRADE_OBSERVATION_MILLISECONDS,
            &observation_deadline
        ) != 0) return -1;
    length = _snprintf_s(payload, sizeof(payload), _TRUNCATE,
        "WLS-UPGRADE-OBSERVING/2\n"
        "intent_sha256=%s\nintent_nonce=%s\nfrom=%c\nto=%c\n"
        "runtime_generation=%s\nboot_id=%s\n"
        "started_monotonic_ms=%llu\ndeadline_monotonic_ms=%llu\n",
        binding.intent_sha256, binding.nonce, (char)binding.from, (char)binding.to,
        binding.runtime_generation, binding.boot_id, *started,
        observation_deadline);
    if (length <= 0 || wls_atomic_bytes(observing, payload, (DWORD)length) != 0) return -1;
    (void)DeleteFileW(healthy);
    return 1;
}

static int wls_write_upgrade_healthy(
    const wchar_t *home,
    const wchar_t *active_slot,
    const char *runtime_generation,
    ULONGLONG started
) {
    struct wls_upgrade_binding binding;
    wchar_t path[WLS_PATH_CHARS];
    char payload[768];
    ULONGLONG healthy = GetTickCount64();
    ULONGLONG observation_deadline;
    int length;
    if (wls_upgrade_binding_read(home, &binding) != 2
        || active_slot[0] != binding.to
        || strcmp(runtime_generation, binding.runtime_generation) != 0
        || strcmp(binding.phase, "OBSERVING") != 0
        || wls_checked_add_ull(
            started,
            WLS_UPGRADE_OBSERVATION_MILLISECONDS,
            &observation_deadline
        ) != 0
        || healthy < observation_deadline
        || wls_join_w(path, WLS_PATH_CHARS, home, L"trust\\upgrade-healthy") != 0) return 1;
    length = _snprintf_s(payload, sizeof(payload), _TRUNCATE,
        "WLS-UPGRADE-HEALTHY/2\n"
        "intent_sha256=%s\nintent_nonce=%s\nfrom=%c\nto=%c\n"
        "runtime_generation=%s\nboot_id=%s\n"
        "observation_deadline_monotonic_ms=%llu\nhealthy_monotonic_ms=%llu\n",
        binding.intent_sha256, binding.nonce, (char)binding.from, (char)binding.to,
        binding.runtime_generation, binding.boot_id,
        observation_deadline, healthy);
    return length > 0 ? wls_atomic_bytes(path, payload, (DWORD)length) : 1;
}

static int wls_write_rollback_healthy(
    const wchar_t *home,
    const wchar_t *active_slot,
    const char *runtime_generation,
    ULONGLONG started,
    ULONGLONG healthy
) {
    struct wls_upgrade_binding binding;
    wchar_t path[WLS_PATH_CHARS];
    char payload[768];
    ULONGLONG health_deadline;
    int length;
    if (wls_upgrade_binding_read(home, &binding) != 2
        || active_slot[0] != binding.from
        || strcmp(binding.phase, "ROLLBACK_PENDING") != 0
        || !wls_is_hex(runtime_generation, 64U)
        || wls_checked_add_ull(
            started,
            WLS_ROLLBACK_HEALTH_MILLISECONDS,
            &health_deadline
        ) != 0
        || healthy < health_deadline
        || wls_join_w(path, WLS_PATH_CHARS, home,
            L"trust\\upgrade-rollback-healthy") != 0) return 1;
    length = _snprintf_s(payload, sizeof(payload), _TRUNCATE,
        "WLS-UPGRADE-ROLLBACK-HEALTHY/2\n"
        "intent_sha256=%s\nintent_nonce=%s\nfrom=%c\nto=%c\n"
        "active_runtime_generation=%s\nboot_id=%s\n"
        "started_monotonic_ms=%llu\nhealthy_monotonic_ms=%llu\n",
        binding.intent_sha256, binding.nonce, (char)binding.from, (char)binding.to,
        runtime_generation, binding.boot_id, started, healthy);
    return length > 0 ? wls_atomic_bytes(path, payload, (DWORD)length) : 1;
}

static int wls_bootstrap_receipt_matches(
    const struct wls_bootstrap_maintenance_context *context,
    const struct wls_bootstrap_receipt *receipt
) {
    struct wls_upgrade_binding binding;
    char boot_id[65];
    int binding_status;
    static const char no_digest[65] =
        "0000000000000000000000000000000000000000000000000000000000000000";
    static const char no_nonce[33] =
        "00000000000000000000000000000000";
    if (context == NULL || receipt == NULL
        || strcmp(receipt->active_slot, context->expected_slot) != 0
        || strcmp(
            receipt->runtime_generation,
            context->expected_runtime_generation
        ) != 0
        || strcmp(receipt->epoch, receipt->controller_epoch) != 0
        || receipt->observed_monotonic_ms == 0ULL
        || wls_upgrade_boot_id(boot_id) != 0
        || strcmp(receipt->host_boot_id, boot_id) != 0) return 0;
    binding_status = wls_upgrade_binding_read(context->home, &binding);
    if (binding_status == 0) {
        return strcmp(receipt->intent_sha256, no_digest) == 0
            && strcmp(receipt->intent_nonce, no_nonce) == 0;
    }
    if (binding_status != 2) return 0;
    return strcmp(receipt->intent_sha256, binding.intent_sha256) == 0
        && strcmp(receipt->intent_nonce, binding.nonce) == 0
        && strcmp(receipt->host_boot_id, binding.boot_id) == 0;
}

static int wls_bootstrap_same_health_generation(
    const struct wls_bootstrap_receipt *left,
    const struct wls_bootstrap_receipt *right
) {
    return left != NULL && right != NULL
        && strcmp(left->epoch, right->epoch) == 0
        && strcmp(left->controller_epoch, right->controller_epoch) == 0
        && left->generation == right->generation
        && left->active_config_generation == right->active_config_generation
        && strcmp(left->active_slot, right->active_slot) == 0
        && strcmp(left->runtime_generation, right->runtime_generation) == 0
        && strcmp(left->host_boot_id, right->host_boot_id) == 0
        && strcmp(left->intent_sha256, right->intent_sha256) == 0
        && strcmp(left->intent_nonce, right->intent_nonce) == 0;
}

static int wls_bootstrap_health_record(
    struct wls_bootstrap_maintenance_context *context,
    int bootstrap_result,
    const struct wls_bootstrap_receipt *receipt
) {
    int valid;
    if (context == NULL) return 1;
    AcquireSRWLockExclusive(&context->health_lock);
    valid = bootstrap_result == 0
        && wls_bootstrap_receipt_matches(context, receipt);
    if (!valid) {
        context->continuous_since_ms = 0ULL;
        context->last_success_ms = 0ULL;
        if (context->observation_mode == 1) context->observation_failed = 1;
    } else {
        if (context->continuous_since_ms == 0ULL
            || !wls_bootstrap_same_health_generation(
                &context->receipt,
                receipt
            )) {
            context->continuous_since_ms = receipt->observed_monotonic_ms;
        }
        context->last_success_ms = receipt->observed_monotonic_ms;
        context->receipt = *receipt;
    }
    ReleaseSRWLockExclusive(&context->health_lock);
    return valid ? 0 : 1;
}

static void wls_bootstrap_health_arm(
    struct wls_bootstrap_maintenance_context *context,
    int observation_mode,
    ULONGLONG observation_started_ms
) {
    ULONGLONG floor = observation_started_ms
        > WLS_MAINTENANCE_HEALTH_FRESHNESS_MS
            ? observation_started_ms - WLS_MAINTENANCE_HEALTH_FRESHNESS_MS
            : 0ULL;
    ULONGLONG ceiling = 0ULL;
    if (context == NULL) return;
    AcquireSRWLockExclusive(&context->health_lock);
    context->observation_mode = observation_mode;
    context->observation_failed = 0;
    if (wls_checked_add_ull(
            observation_started_ms,
            WLS_MAINTENANCE_HEALTH_FRESHNESS_MS,
            &ceiling
        ) == 0
        && context->last_success_ms >= floor
        && context->last_success_ms <= ceiling
        && context->last_success_ms > 0ULL) {
        context->continuous_since_ms = observation_started_ms;
    } else {
        context->continuous_since_ms = 0ULL;
    }
    ReleaseSRWLockExclusive(&context->health_lock);
}

static int wls_bootstrap_health_ready(
    struct wls_bootstrap_maintenance_context *context,
    int observation_mode,
    ULONGLONG now_ms,
    ULONGLONG required_ms,
    struct wls_bootstrap_receipt *receipt
) {
    int ready = 0;
    if (context == NULL) return 0;
    AcquireSRWLockExclusive(&context->health_lock);
    if (context->observation_mode == observation_mode
        && !context->observation_failed
        && context->continuous_since_ms > 0ULL
        && now_ms >= context->continuous_since_ms
        && now_ms - context->continuous_since_ms >= required_ms
        && context->last_success_ms > 0ULL
        && now_ms >= context->last_success_ms
        && now_ms - context->last_success_ms
            <= WLS_MAINTENANCE_HEALTH_FRESHNESS_MS
        && wls_bootstrap_receipt_matches(context, &context->receipt)) {
        if (receipt != NULL) *receipt = context->receipt;
        ready = 1;
    }
    ReleaseSRWLockExclusive(&context->health_lock);
    return ready;
}

static int wls_bootstrap_observation_failed(
    struct wls_bootstrap_maintenance_context *context
) {
    int failed;
    if (context == NULL) return 1;
    AcquireSRWLockShared(&context->health_lock);
    failed = context->observation_mode == 1 && context->observation_failed;
    ReleaseSRWLockShared(&context->health_lock);
    return failed;
}

static int wls_registry_path(
    wchar_t *output,
    size_t capacity,
    const wchar_t *home
) {
    return wls_join_w(output, capacity, home, L"trust\\broker-enrollments.tsv");
}

static int wls_secure_root_only_handle(HANDLE file)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PSID owner = NULL;
    PACL dacl = NULL;
    BOOL owner_defaulted = FALSE;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    DWORD status;
    int result = 1;
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"O:SYD:P(A;;GA;;;SY)(A;;GA;;;BA)",
            SDDL_REVISION_1,
            &descriptor,
            NULL
        )
        || !GetSecurityDescriptorOwner(
            descriptor,
            &owner,
            &owner_defaulted
        )
        || !GetSecurityDescriptorDacl(
            descriptor,
            &dacl_present,
            &dacl,
            &dacl_defaulted
        )
        || owner == NULL
        || !dacl_present
        || dacl == NULL) {
        goto cleanup;
    }
    status = SetSecurityInfo(
        file,
        SE_FILE_OBJECT,
        OWNER_SECURITY_INFORMATION
            | DACL_SECURITY_INFORMATION
            | PROTECTED_DACL_SECURITY_INFORMATION,
        owner,
        NULL,
        dacl,
        NULL
    );
    if (status == ERROR_SUCCESS) result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    return result;
}

static int wls_split_tsv(
    char *line,
    char **fields,
    size_t field_capacity,
    size_t *field_count
) {
    char *cursor = line;
    size_t count = 0U;
    if (line == NULL || fields == NULL || field_count == NULL) return 1;
    for (;;) {
        char *tab;
        if (count >= field_capacity) return 1;
        fields[count++] = cursor;
        tab = strchr(cursor, '\t');
        if (tab == NULL) break;
        *tab = '\0';
        cursor = tab + 1U;
    }
    *field_count = count;
    return 0;
}

static int wls_registry_append(const wchar_t *home, const char *record)
{
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    LARGE_INTEGER size = {0};
    FILE_STANDARD_INFO file_info;
    size_t length;
    int locked = 0;
    int size_known = 0;
    int result = 1;
    if (record == NULL || wls_registry_path(path, WLS_PATH_CHARS, home) != 0) return 1;
    length = strlen(record);
    if (length < 2U
        || length > 131072U
        || record[length - 1U] != '\n'
        || memchr(record, '\r', length) != NULL
        || memchr(record, '\n', length - 1U) != NULL) {
        SetLastError(ERROR_INVALID_DATA);
        return 1;
    }
    file = CreateFileW(
        path,
        GENERIC_READ | GENERIC_WRITE | WRITE_DAC | WRITE_OWNER | SYNCHRONIZE,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT | FILE_FLAG_WRITE_THROUGH,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(file)
        || !LockFileEx(
            file,
            LOCKFILE_EXCLUSIVE_LOCK,
            0U,
            MAXDWORD,
            MAXDWORD,
            &lock
        )) {
        goto cleanup;
    }
    locked = 1;
    if (!GetFileInformationByHandleEx(
            file,
            FileStandardInfo,
            &file_info,
            sizeof(file_info)
        )
        || file_info.Directory
        || file_info.NumberOfLinks != 1U
        || file_info.EndOfFile.QuadPart < 0
        || file_info.EndOfFile.QuadPart
            > (LONGLONG)WLS_MAX_REGISTRY - (LONGLONG)length) {
        goto cleanup;
    }
    size = file_info.EndOfFile;
    if (wls_secure_root_only_handle(file) != 0) goto cleanup;
    size_known = 1;
    if (size.QuadPart > 0) {
        LARGE_INTEGER tail_position = size;
        char tail = '\0';
        DWORD amount = 0U;
        tail_position.QuadPart--;
        if (!SetFilePointerEx(file, tail_position, NULL, FILE_BEGIN)
            || !ReadFile(file, &tail, 1U, &amount, NULL)
            || amount != 1U
            || tail != '\n') {
            SetLastError(ERROR_INVALID_DATA);
            goto cleanup;
        }
    }
    if (!SetFilePointerEx(file, size, NULL, FILE_BEGIN)) goto cleanup;
    if (wls_write_all(file, record, (DWORD)length) != 0
        || !FlushFileBuffers(file)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) {
        if (locked && result != 0 && size_known) {
            if (SetFilePointerEx(file, size, NULL, FILE_BEGIN)) {
                SetEndOfFile(file);
                FlushFileBuffers(file);
            }
        }
        if (locked) UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    return result;
}

static int wls_parse_generation(const char *value, unsigned long long *generation)
{
    unsigned long long parsed = 0ULL;
    size_t index;
    if (value == NULL || generation == NULL || value[0] == '\0') return 1;
    for (index = 0U; value[index] != '\0'; index++) {
        unsigned long long digit;
        if (value[index] < '0' || value[index] > '9') return 1;
        digit = (unsigned long long)(value[index] - '0');
        if (parsed > (ULLONG_MAX - digit) / 10ULL) return 1;
        parsed = parsed * 10ULL + digit;
    }
    if (parsed == 0ULL) return 1;
    *generation = parsed;
    return 0;
}

static int wls_authorize_root(
    const wchar_t *home,
    HANDLE client_pipe,
    const char *peer_sid,
    const char *project,
    const char *generation_text,
    const char *owner_sid,
    const char *alias,
    const char *project_root_hex,
    const char *certificate_root_hex
) {
    wchar_t project_root[WLS_PATH_CHARS];
    wchar_t certificate_root[WLS_PATH_CHARS];
    wchar_t project_final[WLS_PATH_CHARS];
    wchar_t certificate_final[WLS_PATH_CHARS];
    HANDLE project_handle = INVALID_HANDLE_VALUE;
    HANDLE certificate_handle = INVALID_HANDLE_VALUE;
    char project_owner[256];
    char certificate_owner[256];
    char *record = NULL;
    size_t record_capacity;
    unsigned long long generation;
    int impersonating = 0;
    int result = 1;
    if (!wls_is_uuid(project) || !wls_is_alias(alias)
        || !wls_is_sid_text(owner_sid)
        || wls_parse_generation(generation_text, &generation) != 0
        || wls_hex_to_wide(project_root_hex, project_root, WLS_PATH_CHARS) != 0
        || wls_hex_to_wide(certificate_root_hex, certificate_root, WLS_PATH_CHARS) != 0) {
        return 1;
    }
    if (wls_begin_client_impersonation(client_pipe, peer_sid) != 0) {
        goto cleanup;
    }
    impersonating = 1;
    project_handle = wls_open_root(
        project_root,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | READ_CONTROL
    );
    certificate_handle = wls_open_root(
        certificate_root,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | READ_CONTROL
    );
    if (project_handle == INVALID_HANDLE_VALUE
        || certificate_handle == INVALID_HANDLE_VALUE
        || wls_owner_sid(project_handle, project_owner, sizeof(project_owner)) != 0
        || _stricmp(project_owner, owner_sid) != 0
        || wls_owner_sid(
            certificate_handle,
            certificate_owner,
            sizeof(certificate_owner)
        ) != 0
        || _stricmp(certificate_owner, owner_sid) != 0
        || wls_final_path(project_handle, project_final, WLS_PATH_CHARS) != 0
        || wls_final_path(certificate_handle, certificate_final, WLS_PATH_CHARS) != 0
        || !wls_path_within(certificate_final, project_final)) {
        goto cleanup;
    }
    wls_end_client_impersonation();
    impersonating = 0;
    record_capacity = strlen(project) + strlen(owner_sid) + strlen(alias)
        + strlen(certificate_root_hex) + 48U;
    record = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, record_capacity);
    if (record == NULL
        || _snprintf_s(
            record,
            record_capacity,
            _TRUNCATE,
            "A\t%s\t%llu\t%s\t%s\t%s\n",
            project,
            generation,
            owner_sid,
            alias,
            certificate_root_hex
        ) < 0
        || wls_registry_append(home, record) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (impersonating) wls_end_client_impersonation();
    if (record != NULL) HeapFree(GetProcessHeap(), 0U, record);
    if (certificate_handle != INVALID_HANDLE_VALUE) CloseHandle(certificate_handle);
    if (project_handle != INVALID_HANDLE_VALUE) CloseHandle(project_handle);
    return result;
}

static int wls_revoke_roots(
    const wchar_t *home,
    const char *project,
    const char *generation_text
) {
    char record[256];
    unsigned long long generation;
    if (!wls_is_uuid(project)
        || wls_parse_generation(generation_text, &generation) != 0
        || _snprintf_s(
            record,
            sizeof(record),
            _TRUNCATE,
            "R\t%s\t%llu\n",
            project,
            generation
        ) < 0) {
        return 1;
    }
    return wls_registry_append(home, record);
}

static int wls_registry_lookup(
    const wchar_t *home,
    const char *project,
    unsigned long long generation,
    const char *alias,
    char *owner_sid,
    size_t owner_capacity,
    char **root_hex
) {
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    LARGE_INTEGER size;
    FILE_STANDARD_INFO file_info;
    char *contents = NULL;
    char *cursor;
    char *end;
    unsigned long long revoked = 0U;
    int found = 0;
    int locked = 0;
    int result = 1;
    if (root_hex == NULL || wls_registry_path(path, WLS_PATH_CHARS, home) != 0) return 1;
    *root_hex = NULL;
    file = CreateFileW(
        path,
        GENERIC_READ | SYNCHRONIZE,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(file)
        || !LockFileEx(file, 0U, 0U, MAXDWORD, MAXDWORD, &lock)) {
        goto cleanup;
    }
    locked = 1;
    if (!GetFileInformationByHandleEx(
            file,
            FileStandardInfo,
            &file_info,
            sizeof(file_info)
        )
        || file_info.Directory
        || file_info.NumberOfLinks != 1U
        || file_info.EndOfFile.QuadPart < 1
        || file_info.EndOfFile.QuadPart > (LONGLONG)WLS_MAX_REGISTRY) {
        goto cleanup;
    }
    size = file_info.EndOfFile;
    contents = (char *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        (size_t)size.QuadPart + 1U
    );
    if (contents == NULL) goto cleanup;
    {
        if (wls_read_exact(file, contents, (DWORD)size.QuadPart) != 0) {
            goto cleanup;
        }
    }
    if (contents[size.QuadPart - 1] != '\n'
        || memchr(contents, '\0', (size_t)size.QuadPart) != NULL
        || memchr(contents, '\r', (size_t)size.QuadPart) != NULL) {
        SetLastError(ERROR_INVALID_DATA);
        goto cleanup;
    }
    cursor = contents;
    end = contents + (size_t)size.QuadPart;
    while (cursor < end) {
        char *newline = (char *)memchr(cursor, '\n', (size_t)(end - cursor));
        char *fields[7];
        size_t field_count = 0U;
        unsigned long long parsed = 0U;
        if (newline == NULL) {
            SetLastError(ERROR_INVALID_DATA);
            goto cleanup;
        }
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 7U, &field_count) != 0
            || (field_count != 3U && field_count != 6U)
            || !wls_is_uuid(fields[1])
            || wls_parse_generation(fields[2], &parsed) != 0) {
            SetLastError(ERROR_INVALID_DATA);
            goto cleanup;
        }
        if (field_count == 3U) {
            if (strcmp(fields[0], "R") != 0) {
                SetLastError(ERROR_INVALID_DATA);
                goto cleanup;
            }
            if (strcmp(fields[1], project) == 0 && parsed > revoked) {
                revoked = parsed;
            }
        } else {
            size_t root_length = strlen(fields[5]);
            if (strcmp(fields[0], "A") != 0
                || !wls_is_sid_text(fields[3])
                || !wls_is_alias(fields[4])
                || root_length < 2U
                || (root_length & 1U) != 0U
                || !wls_is_hex(fields[5], root_length)) {
                SetLastError(ERROR_INVALID_DATA);
                goto cleanup;
            }
            if (strcmp(fields[1], project) == 0
                && parsed == generation
                && strcmp(fields[4], alias) == 0) {
                if (strlen(fields[3]) + 1U > owner_capacity) {
                    SetLastError(ERROR_INSUFFICIENT_BUFFER);
                    goto cleanup;
                }
                if (found) {
                    if (_stricmp(owner_sid, fields[3]) != 0
                        || *root_hex == NULL
                        || strcmp(*root_hex, fields[5]) != 0) {
                        SetLastError(ERROR_INVALID_DATA);
                        goto cleanup;
                    }
                } else {
                    char *copy = (char *)HeapAlloc(
                        GetProcessHeap(),
                        HEAP_ZERO_MEMORY,
                        root_length + 1U
                    );
                    if (copy == NULL) goto cleanup;
                    strcpy_s(copy, root_length + 1U, fields[5]);
                    *root_hex = copy;
                    strcpy_s(owner_sid, owner_capacity, fields[3]);
                    found = 1;
                }
            }
        }
        cursor = newline + 1U;
    }
    if (found && revoked < generation && *root_hex != NULL) result = 0;
cleanup:
    if (result != 0 && *root_hex != NULL) {
        HeapFree(GetProcessHeap(), 0U, *root_hex);
        *root_hex = NULL;
    }
    if (contents != NULL) HeapFree(GetProcessHeap(), 0U, contents);
    if (file != INVALID_HANDLE_VALUE) {
        if (locked) UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    return result;
}

static int wls_snapshot_enrolled(
    const wchar_t *home,
    HANDLE client_pipe,
    const char *peer_sid,
    const char *project,
    const char *generation_text,
    const char *alias,
    const char *source_relative_hex,
    const char *digest,
    const char *leaf
) {
    unsigned long long generation;
    char owner_sid[256];
    char *root_hex = NULL;
    wchar_t source_root[WLS_PATH_CHARS];
    wchar_t source_relative[WLS_PATH_CHARS];
    wchar_t destination_root[WLS_PATH_CHARS];
    wchar_t destination_relative[WLS_PATH_CHARS];
    int result = 1;
    if (!wls_is_uuid(project) || !wls_is_alias(alias)
        || !wls_is_hex(digest, 64U)
        || (strcmp(leaf, "source-cert.pem") != 0
            && strcmp(leaf, "source-key.pem") != 0
            && strcmp(leaf, "source-chain.pem") != 0)
        || wls_parse_generation(generation_text, &generation) != 0
        || wls_registry_lookup(
            home,
            project,
            generation,
            alias,
            owner_sid,
            sizeof(owner_sid),
            &root_hex
        ) != 0
        || _stricmp(owner_sid, peer_sid) != 0
        || wls_hex_to_wide(root_hex, source_root, WLS_PATH_CHARS) != 0
        || wls_hex_to_wide(source_relative_hex, source_relative, WLS_PATH_CHARS) != 0
        || !wls_safe_relative(source_relative)
        || wls_join_w(destination_root, WLS_PATH_CHARS, home, L"snapshots") != 0
        || _snwprintf_s(
            destination_relative,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%hs\\%hs",
            digest,
            leaf
        ) < 0) {
        goto cleanup;
    }
    result = wls_snapshot(
        source_root,
        source_relative,
        destination_root,
        destination_relative,
        owner_sid,
        strcmp(leaf, "source-key.pem") == 0,
        client_pipe,
        peer_sid
    );
cleanup:
    if (root_hex != NULL) HeapFree(GetProcessHeap(), 0U, root_hex);
    return result;
}

static int wls_peer_sid(HANDLE pipe, char *sid_utf8, size_t capacity, DWORD *pid)
{
    int impersonating = 0;
    int result = 1;
    if (!GetNamedPipeClientProcessId(pipe, pid)
        || !ImpersonateNamedPipeClient(pipe)) {
        goto cleanup;
    }
    impersonating = 1;
    if (wls_thread_sid(sid_utf8, capacity) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (impersonating) wls_end_client_impersonation();
    return result;
}

static int wls_pipe_retryable_error(DWORD error)
{
    return error == ERROR_NO_DATA
        || error == ERROR_PIPE_LISTENING
        || error == ERROR_PIPE_BUSY;
}

static int wls_pipe_read_frame_alloc(
    HANDLE pipe,
    HANDLE stop_event,
    char **buffer_pointer,
    DWORD *capacity_pointer,
    DWORD *used,
    DWORD timeout
) {
    ULONGLONG started = GetTickCount64();
    char *buffer;
    DWORD capacity;
    if (buffer_pointer == NULL || capacity_pointer == NULL || used == NULL) {
        SetLastError(ERROR_INVALID_PARAMETER);
        return 1;
    }
    buffer = *buffer_pointer;
    capacity = *capacity_pointer;
    if (buffer == NULL || capacity < 2U) {
        capacity = WLS_INITIAL_FRAME_CAPACITY;
        if (capacity > WLS_MAX_REQUEST + 1U) capacity = WLS_MAX_REQUEST + 1U;
        buffer = (char *)HeapAlloc(GetProcessHeap(), 0U, capacity);
        if (buffer == NULL) return 1;
        *buffer_pointer = buffer;
        *capacity_pointer = capacity;
    }
    *used = 0U;
    while (*used < WLS_MAX_REQUEST) {
        DWORD amount = 0U;
        DWORD available;
        BOOL read;
        char *newline;
        if (*used + 1U >= capacity) {
            DWORD next = capacity < (WLS_MAX_REQUEST + 1U) / 2U
                ? capacity * 2U
                : WLS_MAX_REQUEST + 1U;
            char *grown;
            if (next <= capacity) {
                SetLastError(ERROR_INSUFFICIENT_BUFFER);
                return 1;
            }
            grown = (char *)HeapReAlloc(GetProcessHeap(), 0U, buffer, next);
            if (grown == NULL) return 1;
            buffer = grown;
            capacity = next;
            *buffer_pointer = buffer;
            *capacity_pointer = capacity;
        }
        available = capacity - *used - 1U;
        if (WaitForSingleObject(stop_event, 0U) == WAIT_OBJECT_0) {
            SetLastError(ERROR_OPERATION_ABORTED);
            return 1;
        }
        read = ReadFile(pipe, buffer + *used, available, &amount, NULL);
        if (read && amount > 0U) {
            newline = (char *)memchr(buffer + *used, '\n', amount);
            if (newline != NULL && newline != buffer + *used + amount - 1U) {
                SetLastError(ERROR_INVALID_DATA);
                return 1;
            }
            *used += amount;
            if (newline == NULL) continue;
            buffer[*used] = '\0';
            return 0;
        }
        if (!read && !wls_pipe_retryable_error(GetLastError())) return 1;
        if (GetTickCount64() - started >= timeout) {
            SetLastError(ERROR_TIMEOUT);
            return 1;
        }
        if (WaitForSingleObject(stop_event, WLS_IO_POLL_MS) == WAIT_OBJECT_0) {
            SetLastError(ERROR_OPERATION_ABORTED);
            return 1;
        }
    }
    SetLastError(ERROR_INSUFFICIENT_BUFFER);
    return 1;
}

static int wls_pipe_write_all(
    HANDLE pipe,
    HANDLE stop_event,
    const char *buffer,
    DWORD length,
    DWORD timeout
) {
    ULONGLONG started = GetTickCount64();
    DWORD written = 0U;
    while (written < length) {
        DWORD amount = 0U;
        BOOL write;
        if (WaitForSingleObject(stop_event, 0U) == WAIT_OBJECT_0) {
            SetLastError(ERROR_OPERATION_ABORTED);
            return 1;
        }
        write = WriteFile(pipe, buffer + written, length - written, &amount, NULL);
        if (write && amount > 0U) {
            written += amount;
            continue;
        }
        if (!write && !wls_pipe_retryable_error(GetLastError())) return 1;
        if (GetTickCount64() - started >= timeout) {
            SetLastError(ERROR_TIMEOUT);
            return 1;
        }
        if (WaitForSingleObject(stop_event, WLS_IO_POLL_MS) == WAIT_OBJECT_0) {
            SetLastError(ERROR_OPERATION_ABORTED);
            return 1;
        }
    }
    return 0;
}

static void wls_pipe_wait_for_client_close(
    HANDLE pipe,
    HANDLE stop_event,
    DWORD timeout
) {
    ULONGLONG started = GetTickCount64();
    for (;;) {
        DWORD available = 0U;
        if (WaitForSingleObject(stop_event, 0U) == WAIT_OBJECT_0) return;
        if (!PeekNamedPipe(pipe, NULL, 0U, NULL, &available, NULL)) {
            DWORD error = GetLastError();
            if (error == ERROR_BROKEN_PIPE || error == ERROR_PIPE_NOT_CONNECTED) return;
        }
        if (GetTickCount64() - started >= timeout) return;
        if (WaitForSingleObject(stop_event, WLS_IO_POLL_MS) == WAIT_OBJECT_0) return;
    }
}

static int wls_socket_read_line(
    SOCKET socket_handle,
    char *buffer,
    DWORD capacity,
    DWORD *used
) {
    if (buffer == NULL || used == NULL || capacity < 2U) {
        WSASetLastError(WSAEINVAL);
        return 1;
    }
    *used = 0U;
    while (*used + 1U < capacity) {
        int available = (int)(capacity - *used - 1U);
        int amount = recv(socket_handle, buffer + *used, available, MSG_PEEK);
        char *newline;
        DWORD consume;
        DWORD consumed = 0U;
        if (amount <= 0) return 1;
        newline = (char *)memchr(buffer + *used, '\n', (size_t)amount);
        consume = newline == NULL
            ? (DWORD)amount
            : (DWORD)(newline - (buffer + *used)) + 1U;
        while (consumed < consume) {
            int received = recv(
                socket_handle,
                buffer + *used + consumed,
                (int)(consume - consumed),
                0
            );
            if (received <= 0) return 1;
            consumed += (DWORD)received;
        }
        *used += consume;
        if (newline != NULL) {
            buffer[*used] = '\0';
            return 0;
        }
    }
    WSASetLastError(WSAEMSGSIZE);
    return 1;
}

static int wls_socket_read_frame_alloc(
    SOCKET socket_handle,
    char **buffer_pointer,
    DWORD *capacity_pointer,
    DWORD *used
) {
    char *buffer;
    DWORD capacity;
    if (buffer_pointer == NULL || capacity_pointer == NULL || used == NULL) {
        WSASetLastError(WSAEINVAL);
        return 1;
    }
    buffer = *buffer_pointer;
    capacity = *capacity_pointer;
    if (buffer == NULL || capacity < 2U) {
        capacity = WLS_INITIAL_FRAME_CAPACITY;
        if (capacity > WLS_MAX_REQUEST + 1U) capacity = WLS_MAX_REQUEST + 1U;
        buffer = (char *)HeapAlloc(GetProcessHeap(), 0U, capacity);
        if (buffer == NULL) return 1;
        *buffer_pointer = buffer;
        *capacity_pointer = capacity;
    }
    *used = 0U;
    while (*used < WLS_MAX_REQUEST) {
        int available;
        int amount;
        char *newline;
        DWORD consume;
        DWORD consumed = 0U;
        if (*used + 1U >= capacity) {
            DWORD next = capacity < (WLS_MAX_REQUEST + 1U) / 2U
                ? capacity * 2U
                : WLS_MAX_REQUEST + 1U;
            char *grown;
            if (next <= capacity) {
                WSASetLastError(WSAEMSGSIZE);
                return 1;
            }
            grown = (char *)HeapReAlloc(GetProcessHeap(), 0U, buffer, next);
            if (grown == NULL) return 1;
            buffer = grown;
            capacity = next;
            *buffer_pointer = buffer;
            *capacity_pointer = capacity;
        }
        available = (int)(capacity - *used - 1U);
        amount = recv(socket_handle, buffer + *used, available, MSG_PEEK);
        if (amount <= 0) return 1;
        newline = (char *)memchr(buffer + *used, '\n', (size_t)amount);
        consume = newline == NULL
            ? (DWORD)amount
            : (DWORD)(newline - (buffer + *used)) + 1U;
        while (consumed < consume) {
            int received = recv(
                socket_handle,
                buffer + *used + consumed,
                (int)(consume - consumed),
                0
            );
            if (received <= 0) return 1;
            consumed += (DWORD)received;
        }
        *used += consume;
        if (newline != NULL) {
            buffer[*used] = '\0';
            return 0;
        }
    }
    WSASetLastError(WSAEMSGSIZE);
    return 1;
}

static int wls_socket_write_all(
    SOCKET socket_handle,
    const char *buffer,
    DWORD length
) {
    DWORD written = 0U;
    while (written < length) {
        int amount = send(
            socket_handle,
            buffer + written,
            (int)(length - written),
            0
        );
        if (amount <= 0) return 1;
        written += (DWORD)amount;
    }
    return 0;
}

static void wls_hex_encode_fixed(
    const unsigned char *input,
    size_t length,
    char *output
) {
    static const char hex[] = "0123456789abcdef";
    size_t index;
    for (index = 0U; index < length; index++) {
        output[index * 2U] = hex[input[index] >> 4U];
        output[index * 2U + 1U] = hex[input[index] & 0x0fU];
    }
    output[length * 2U] = '\0';
}

static int wls_constant_equal(const char *left, const char *right, size_t length)
{
    unsigned char difference = 0U;
    size_t index;
    for (index = 0U; index < length; index++) {
        difference |= (unsigned char)left[index] ^ (unsigned char)right[index];
    }
    return difference == 0U;
}

static int wls_authenticate_controller(SOCKET controller, const char *fencing)
{
    unsigned char key[WLS_FENCING_BYTES];
    unsigned char request_signature[32];
    unsigned char response_signature[32];
    char nonce[WLS_FENCING_BYTES * 2U + 1U];
    char request_payload[128];
    char response_payload[128];
    char request_signature_hex[65];
    char response_signature_hex[65];
    char request[256];
    char expected[128];
    char actual[128];
    DWORD actual_length = 0U;
    int request_payload_length;
    int response_payload_length;
    int request_length;
    int expected_length;
    int result = 1;
    ZeroMemory(key, sizeof(key));
    ZeroMemory(request_signature, sizeof(request_signature));
    ZeroMemory(response_signature, sizeof(response_signature));
    if (!wls_is_hex(fencing, WLS_FENCING_BYTES * 2U)
        || wls_hex_decode_fixed(fencing, key, sizeof(key)) != 0
        || wls_fencing(nonce) != 0) {
        goto cleanup;
    }
    request_payload_length = _snprintf_s(
        request_payload,
        sizeof(request_payload),
        _TRUNCATE,
        "WLS-BROKER-PROBE/1\nnonce=%s\n",
        nonce
    );
    response_payload_length = _snprintf_s(
        response_payload,
        sizeof(response_payload),
        _TRUNCATE,
        "WLS-BROKER-READY/1\nnonce=%s\n",
        nonce
    );
    if (request_payload_length <= 0
        || response_payload_length <= 0
        || wls_hmac_sha256(
            key,
            sizeof(key),
            (const unsigned char *)request_payload,
            (DWORD)request_payload_length,
            request_signature
        ) != 0
        || wls_hmac_sha256(
            key,
            sizeof(key),
            (const unsigned char *)response_payload,
            (DWORD)response_payload_length,
            response_signature
        ) != 0) {
        goto cleanup;
    }
    wls_hex_encode_fixed(request_signature, sizeof(request_signature), request_signature_hex);
    wls_hex_encode_fixed(response_signature, sizeof(response_signature), response_signature_hex);
    request_length = _snprintf_s(
        request,
        sizeof(request),
        _TRUNCATE,
        "WLS-BROKER-PROBE/1\t%s\t%s\n",
        nonce,
        request_signature_hex
    );
    expected_length = _snprintf_s(
        expected,
        sizeof(expected),
        _TRUNCATE,
        "WLS-BROKER-READY/1\t%s\n",
        response_signature_hex
    );
    if (request_length <= 0
        || expected_length <= 0
        || wls_socket_write_all(controller, request, (DWORD)request_length) != 0
        || wls_socket_read_line(controller, actual, sizeof(actual), &actual_length) != 0
        || actual_length != (DWORD)expected_length
        || !wls_constant_equal(actual, expected, (size_t)expected_length)) {
        WSASetLastError(WSAEACCES);
        goto cleanup;
    }
    result = 0;
cleanup:
    SecureZeroMemory(key, sizeof(key));
    SecureZeroMemory(request_signature, sizeof(request_signature));
    SecureZeroMemory(response_signature, sizeof(response_signature));
    SecureZeroMemory(nonce, sizeof(nonce));
    SecureZeroMemory(request_payload, sizeof(request_payload));
    SecureZeroMemory(response_payload, sizeof(response_payload));
    SecureZeroMemory(request_signature_hex, sizeof(request_signature_hex));
    SecureZeroMemory(response_signature_hex, sizeof(response_signature_hex));
    SecureZeroMemory(request, sizeof(request));
    SecureZeroMemory(expected, sizeof(expected));
    SecureZeroMemory(actual, sizeof(actual));
    return result;
}

static SOCKET wls_connect_controller(
    unsigned short port,
    DWORD timeout,
    const char *fencing
)
{
    SOCKET controller;
    struct sockaddr_in address;
    DWORD probe_timeout = WLS_CONTROLLER_IO_TIMEOUT_MS;
    controller = WSASocketW(
        AF_INET,
        SOCK_STREAM,
        IPPROTO_TCP,
        NULL,
        0U,
        WSA_FLAG_NO_HANDLE_INHERIT
    );
    if (controller == INVALID_SOCKET) return INVALID_SOCKET;
    if (setsockopt(
        controller,
        SOL_SOCKET,
        SO_RCVTIMEO,
        (const char *)&probe_timeout,
        sizeof(probe_timeout)
    ) != 0 || setsockopt(
        controller,
        SOL_SOCKET,
        SO_SNDTIMEO,
        (const char *)&probe_timeout,
        sizeof(probe_timeout)
    ) != 0) {
        closesocket(controller);
        return INVALID_SOCKET;
    }
    ZeroMemory(&address, sizeof(address));
    address.sin_family = AF_INET;
    address.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    address.sin_port = htons(port);
    if (connect(
        controller,
        (const struct sockaddr *)&address,
        sizeof(address)
    ) != 0) {
        closesocket(controller);
        return INVALID_SOCKET;
    }
    if (wls_authenticate_controller(controller, fencing) != 0
        || setsockopt(
            controller,
            SOL_SOCKET,
            SO_RCVTIMEO,
            (const char *)&timeout,
            sizeof(timeout)
        ) != 0
        || setsockopt(
            controller,
            SOL_SOCKET,
            SO_SNDTIMEO,
            (const char *)&timeout,
            sizeof(timeout)
        ) != 0) {
        closesocket(controller);
        return INVALID_SOCKET;
    }
    return controller;
}

static int wls_handle_action(
    char *line,
    HANDLE client_pipe,
    const wchar_t *channel,
    const char *peer_sid,
    const wchar_t *home
) {
    char *fields[9];
    size_t field_count = 0U;
    size_t line_length;
    char channel_utf8[32];
    if (line == NULL) return 1;
    line_length = strlen(line);
    if (line_length < 2U
        || line[line_length - 1U] != '\n'
        || memchr(line, '\r', line_length) != NULL
        || memchr(line, '\n', line_length - 1U) != NULL) {
        return 1;
    }
    line[line_length - 1U] = '\0';
    if (wls_split_tsv(line, fields, 9U, &field_count) != 0
        || field_count < 2U
        || strcmp(fields[0], "WLS-ACTION/1") != 0
        || WideCharToMultiByte(
            CP_UTF8,
            WC_ERR_INVALID_CHARS,
            channel,
            -1,
            channel_utf8,
            sizeof(channel_utf8),
            NULL,
            NULL
    ) <= 0) {
        return 1;
    }
    if (strcmp(fields[1], "STOP") == 0
        && strcmp(channel_utf8, "admin") == 0
        && field_count == 3U) {
        return wls_write_admin_stopped(home, fields[2]);
    }
    if (strcmp(fields[1], "AUTH") == 0
        && strcmp(channel_utf8, "admin") == 0
        && field_count == 8U) {
        (void)wls_authorize_root;
        SetLastError(ERROR_NOT_SUPPORTED);
        return 1;
    }
    if (strcmp(fields[1], "REVOKE") == 0
        && strcmp(channel_utf8, "admin") == 0
        && field_count == 4U) {
        return wls_revoke_roots(home, fields[2], fields[3]);
    }
    if (strcmp(fields[1], "SNAP") == 0
        && strcmp(channel_utf8, "project") == 0
        && field_count == 8U) {
        return wls_snapshot_enrolled(
            home,
            client_pipe,
            peer_sid,
            fields[2],
            fields[3],
            fields[4],
            fields[5],
            fields[6],
            fields[7]
        );
    }
    return 1;
}

static int wls_handle_action_v2(
    char *line,
    HANDLE client_pipe,
    const wchar_t *channel,
    const char *peer_sid,
    const wchar_t *home,
    char *reply,
    size_t reply_capacity
);
static unsigned long long wls_filetime_value(const FILETIME *value);
static int wls_write_nginx_process_identity(
    const wchar_t *home,
    DWORD pid,
    const FILETIME *created,
    const wchar_t *active_slot,
    const char *runtime_generation
);

struct wls_win_security_summary {
    unsigned long long allocated;
    unsigned long long committed;
    unsigned long long assigned;
    unsigned long long transaction_expected;
    int reservation_found;
    int committed_found;
    int aborted_found;
    char anchor[65];
    char transaction_anchor[65];
};

static int wls_security_ledger_path(
    wchar_t *output,
    size_t capacity,
    const wchar_t *home
) {
    return wls_join_w(
        output, capacity, home, L"trust\\broker-security-v2.tsv"
    );
}

static int wls_win_security_value(const char *value)
{
    size_t index;
    if (value == NULL || value[0] == '\0' || strlen(value) > 64U) return 0;
    for (index = 0U; value[index] != '\0'; index++) {
        if (!((value[index] >= 'A' && value[index] <= 'Z')
            || value[index] == '_')) return 0;
    }
    return 1;
}

static int wls_parse_u64_zero(const char *value, unsigned long long *parsed)
{
    unsigned long long result = 0ULL;
    size_t index;
    if (value == NULL || parsed == NULL || value[0] == '\0') return 1;
    for (index = 0U; value[index] != '\0'; index++) {
        unsigned long long digit;
        if (value[index] < '0' || value[index] > '9') return 1;
        digit = (unsigned long long)(value[index] - '0');
        if (result > (ULLONG_MAX - digit) / 10ULL) return 1;
        result = result * 10ULL + digit;
    }
    *parsed = result;
    return 0;
}

static int wls_win_security_open_locked(
    const wchar_t *home,
    HANDLE *file,
    OVERLAPPED *lock,
    wchar_t path[WLS_PATH_CHARS]
) {
    FILE_STANDARD_INFO info;
    if (wls_security_ledger_path(path, WLS_PATH_CHARS, home) != 0) return 1;
    *file = CreateFileW(
        path,
        GENERIC_READ | GENERIC_WRITE | WRITE_DAC | WRITE_OWNER | SYNCHRONIZE,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT
            | FILE_FLAG_WRITE_THROUGH,
        NULL
    );
    if (*file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(*file)
        || !LockFileEx(
            *file, LOCKFILE_EXCLUSIVE_LOCK, 0U, MAXDWORD, MAXDWORD, lock
        )
        || !GetFileInformationByHandleEx(
            *file, FileStandardInfo, &info, sizeof(info)
        )
        || info.Directory || info.NumberOfLinks != 1U
        || info.EndOfFile.QuadPart < 0
        || info.EndOfFile.QuadPart > (LONGLONG)WLS_MAX_REGISTRY
        || wls_secure_root_only_handle(*file) != 0) {
        if (*file != INVALID_HANDLE_VALUE) CloseHandle(*file);
        *file = INVALID_HANDLE_VALUE;
        return 1;
    }
    return 0;
}

static int wls_win_security_read_locked(
    HANDLE file,
    char **contents,
    size_t *length
) {
    FILE_STANDARD_INFO info;
    LARGE_INTEGER beginning;
    char *buffer;
    beginning.QuadPart = 0;
    if (!GetFileInformationByHandleEx(
            file, FileStandardInfo, &info, sizeof(info)
        )
        || info.EndOfFile.QuadPart < 0
        || info.EndOfFile.QuadPart > (LONGLONG)WLS_MAX_REGISTRY
        || !SetFilePointerEx(file, beginning, NULL, FILE_BEGIN)) return 1;
    buffer = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        (size_t)info.EndOfFile.QuadPart + 1U
    );
    if (buffer == NULL
        || (info.EndOfFile.QuadPart > 0
            && wls_read_exact(
                file, buffer, (DWORD)info.EndOfFile.QuadPart
            ) != 0)) {
        if (buffer != NULL) HeapFree(GetProcessHeap(), 0U, buffer);
        return 1;
    }
    if (info.EndOfFile.QuadPart > 0
        && (buffer[info.EndOfFile.QuadPart - 1] != '\n'
            || memchr(
                buffer, '\0', (size_t)info.EndOfFile.QuadPart
            ) != NULL
            || memchr(
                buffer, '\r', (size_t)info.EndOfFile.QuadPart
            ) != NULL)) {
        SecureZeroMemory(buffer, (size_t)info.EndOfFile.QuadPart + 1U);
        HeapFree(GetProcessHeap(), 0U, buffer);
        return 1;
    }
    *contents = buffer;
    *length = (size_t)info.EndOfFile.QuadPart;
    return 0;
}

static int wls_win_security_append_locked(
    HANDLE file,
    const char *record
) {
    FILE_STANDARD_INFO info;
    LARGE_INTEGER end;
    size_t length = strlen(record);
    if (length < 2U || record[length - 1U] != '\n'
        || memchr(record, '\n', length - 1U) != NULL
        || !GetFileInformationByHandleEx(
            file, FileStandardInfo, &info, sizeof(info)
        )
        || info.EndOfFile.QuadPart < 0
        || info.EndOfFile.QuadPart + (LONGLONG)length
            > (LONGLONG)WLS_MAX_REGISTRY) return 1;
    end = info.EndOfFile;
    if (!SetFilePointerEx(file, end, NULL, FILE_BEGIN)
        || wls_write_all(file, record, (DWORD)length) != 0
        || !FlushFileBuffers(file)) {
        (void)SetFilePointerEx(file, end, NULL, FILE_BEGIN);
        (void)SetEndOfFile(file);
        (void)FlushFileBuffers(file);
        return 1;
    }
    return 0;
}

static void wls_win_sha256_hex(
    const unsigned char *contents,
    size_t length,
    char output[65]
) {
    unsigned char digest[32];
    if (length > MAXDWORD
        || wls_sha256(contents, (DWORD)length, digest) != 0) {
        memset(output, '0', 64U);
        output[64] = '\0';
        return;
    }
    wls_hex_encode_32(digest, output);
    SecureZeroMemory(digest, sizeof(digest));
}

static int wls_win_security_summary(
    char *contents,
    const char *tx,
    const char *intent,
    const char *kind,
    struct wls_win_security_summary *summary
) {
    char *cursor = contents;
    ZeroMemory(summary, sizeof(*summary));
    memset(summary->anchor, '0', 64U);
    summary->anchor[64] = '\0';
    memset(summary->transaction_anchor, '0', 64U);
    summary->transaction_anchor[64] = '\0';
    while (cursor != NULL && cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t count = 0U;
        unsigned long long assigned = 0ULL;
        if (newline == NULL) return 1;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) return 1;
        if (strcmp(fields[0], "H") == 0) {
            unsigned long long expected = 0ULL;
            if (count != 6U || !wls_is_hex(fields[1], 32U)
                || !wls_is_hex(fields[2], 64U)
                || !wls_win_security_value(fields[3])
                || wls_parse_u64_zero(fields[4], &expected) != 0
                || wls_parse_generation(fields[5], &assigned) != 0
                || assigned <= expected || assigned <= summary->allocated) return 1;
            summary->allocated = assigned;
            if (strcmp(fields[1], tx) == 0) {
                if (strcmp(fields[2], intent) != 0
                    || strcmp(fields[3], kind) != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return 1;
                summary->reservation_found = 1;
                summary->assigned = assigned;
                summary->transaction_expected = expected;
            }
        } else if (strcmp(fields[0], "K") == 0) {
            if (count != 6U || !wls_is_hex(fields[1], 32U)
                || !wls_is_hex(fields[2], 64U)
                || !wls_win_security_value(fields[3])
                || wls_parse_generation(fields[4], &assigned) != 0
                || !wls_is_hex(fields[5], 64U)
                || assigned > summary->allocated
                || assigned <= summary->committed) return 1;
            summary->committed = assigned;
            memcpy(summary->anchor, fields[5], 65U);
            if (strcmp(fields[1], tx) == 0) {
                if (strcmp(fields[2], intent) != 0
                    || strcmp(fields[3], kind) != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return 1;
                summary->committed_found = 1;
                memcpy(summary->transaction_anchor, fields[5], 65U);
            }
        } else if (strcmp(fields[0], "X") == 0) {
            if (count != 5U || !wls_is_hex(fields[1], 32U)
                || !wls_is_hex(fields[2], 64U)
                || !wls_win_security_value(fields[3])
                || wls_parse_generation(fields[4], &assigned) != 0
                || assigned > summary->allocated) return 1;
            if (strcmp(fields[1], tx) == 0) {
                if (strcmp(fields[2], intent) != 0
                    || strcmp(fields[3], kind) != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return 1;
                summary->aborted_found = 1;
            }
        } else if (strcmp(fields[0], "C") == 0) {
            unsigned long long root_count = 0ULL;
            if (count != 7U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_generation(fields[4], &assigned) != 0
                || wls_parse_generation(fields[5], &root_count) != 0
                || root_count > 64ULL
                || !wls_is_hex(fields[6], 64U)
                || assigned > summary->allocated
                || assigned <= summary->committed) return 1;
            summary->committed = assigned;
            memcpy(summary->anchor, fields[6], 65U);
            if (strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[3], intent) != 0
                    || strcmp(kind, "AUTH") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return 1;
                summary->committed_found = 1;
                memcpy(summary->transaction_anchor, fields[6], 65U);
            }
        } else if (strcmp(fields[0], "Q") == 0) {
            if (count != 5U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_generation(fields[4], &assigned) != 0
                || assigned > summary->allocated) return 1;
            if (strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[3], intent) != 0
                    || strcmp(kind, "AUTH") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return 1;
                summary->aborted_found = 1;
            }
        } else if (strcmp(fields[0], "P") == 0) {
            unsigned long long expected;
            size_t project_root_length;
            size_t certificate_root_length;
            if (count != 13U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_generation(fields[4], &assigned) != 0
                || assigned > summary->allocated
                || !wls_is_sid_text(fields[5]) || !wls_is_alias(fields[6])
                || (project_root_length = strlen(fields[7])) < 2U
                || (certificate_root_length = strlen(fields[8])) < 2U
                || (project_root_length & 1U) != 0U
                || (certificate_root_length & 1U) != 0U
                || project_root_length >= 16385U
                || certificate_root_length >= 16385U
                || !wls_is_hex(fields[7], project_root_length)
                || !wls_is_hex(fields[8], certificate_root_length)
                || wls_parse_generation(fields[9], &expected) != 0
                || expected > 64ULL
                || strlen(fields[10]) > 95U || strlen(fields[11]) > 95U
                || !wls_is_hex(fields[12], 64U)) return 1;
        } else if (strcmp(fields[0], "R") == 0) {
            unsigned long long expected = 0ULL;
            unsigned long long root_count = 0ULL;
            if (count != 10U || !wls_is_uuid(fields[1])
                || !wls_is_uuid(fields[2]) || strcmp(fields[1], fields[2]) == 0
                || !wls_is_hex(fields[3], 32U) || !wls_is_hex(fields[4], 64U)
                || wls_parse_generation(fields[5], &assigned) != 0
                || !wls_is_sid_text(fields[6])
                || wls_parse_u64_zero(fields[7], &expected) != 0
                || wls_parse_generation(fields[8], &root_count) != 0
                || root_count == 0ULL || root_count > 64ULL
                || !wls_is_hex(fields[9], 64U)
                || assigned > summary->allocated || assigned <= expected) return 1;
            if (strcmp(fields[3], tx) == 0
                && (strcmp(fields[4], intent) != 0
                    || strcmp(kind, "AUTH_TRANSFER") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned))) return 1;
        } else if (strcmp(fields[0], "T") == 0) {
            unsigned long long root_count = 0ULL;
            char canonical[1536];
            char calculated[65];
            int canonical_length;
            if (count != 11U || !wls_is_uuid(fields[1])
                || !wls_is_uuid(fields[2]) || strcmp(fields[1], fields[2]) == 0
                || !wls_is_hex(fields[3], 32U) || !wls_is_hex(fields[4], 64U)
                || wls_parse_generation(fields[5], &assigned) != 0
                || !wls_is_sid_text(fields[6])
                || wls_parse_generation(fields[7], &root_count) != 0
                || root_count == 0ULL || root_count > 64ULL
                || !wls_is_hex(fields[8], 64U)
                || !wls_is_hex(fields[9], 64U)
                || !wls_is_hex(fields[10], 64U)
                || assigned > summary->allocated || assigned <= summary->committed
                || strcmp(fields[9], summary->anchor) != 0) return 1;
            canonical_length = _snprintf_s(
                canonical, sizeof(canonical), _TRUNCATE,
                "%s\n%s\n%s\n%s\n%llu\n%s\n%llu\n%s\n%s\n",
                fields[1], fields[2], fields[3], fields[4], assigned,
                fields[6], root_count, fields[8], fields[9]
            );
            if (canonical_length <= 0) return 1;
            wls_win_sha256_hex(
                (const unsigned char *)canonical,
                (size_t)canonical_length,
                calculated
            );
            if (strcmp(calculated, fields[10]) != 0) return 1;
            summary->committed = assigned;
            memcpy(summary->anchor, fields[10], 65U);
            if (strcmp(fields[3], tx) == 0) {
                if (strcmp(fields[4], intent) != 0
                    || strcmp(kind, "AUTH_TRANSFER") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return 1;
                summary->committed_found = 1;
                memcpy(summary->transaction_anchor, fields[10], 65U);
            }
        } else if (strcmp(fields[0], "Y") == 0) {
            if (count != 6U || !wls_is_uuid(fields[1])
                || !wls_is_uuid(fields[2]) || strcmp(fields[1], fields[2]) == 0
                || !wls_is_hex(fields[3], 32U) || !wls_is_hex(fields[4], 64U)
                || wls_parse_generation(fields[5], &assigned) != 0
                || assigned > summary->allocated) return 1;
            if (strcmp(fields[3], tx) == 0) {
                if (strcmp(fields[4], intent) != 0
                    || strcmp(kind, "AUTH_TRANSFER") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return 1;
                summary->aborted_found = 1;
            }
        } else return 1;
        cursor = newline + 1U;
    }
    if ((summary->committed_found || summary->aborted_found)
        && !summary->reservation_found) return 1;
    return 0;
}

static int wls_win_action_error(
    char *reply,
    size_t capacity,
    const char *code,
    const char *opcode,
    const char *tx,
    const char *intent
) {
    int written = _snprintf_s(
        reply, capacity, _TRUNCATE,
        "WLS-ACTION/2\tERR\t%s\t%s\t%s\t%s\n",
        code, opcode, tx != NULL ? tx : "-", intent != NULL ? intent : "-"
    );
    return written > 0 ? 0 : 1;
}

static int wls_win_security_operation(
    const wchar_t *home,
    const char *opcode,
    const char *tx,
    const char *intent,
    const char *kind,
    const char *generation_text,
    const char *anchor,
    char *reply,
    size_t reply_capacity
) {
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char *contents = NULL;
    size_t length = 0U;
    char record[512];
    char digest[65];
    unsigned long long requested = 0ULL;
    struct wls_win_security_summary summary;
    int result = 1;
    if (!wls_is_hex(tx, 32U) || !wls_is_hex(intent, 64U)
        || !wls_win_security_value(kind)
        || wls_parse_u64_zero(generation_text, &requested) != 0
        || (strcmp(opcode, "SECURITY_COMMIT") == 0
            && !wls_is_hex(anchor, 64U))) {
        return wls_win_action_error(
            reply, reply_capacity, "INVALID", opcode, tx, intent
        );
    }
    if (wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0
        || wls_win_security_summary(
            contents, tx, intent, kind, &summary
        ) != 0) {
        (void)wls_win_action_error(
            reply, reply_capacity, "LEDGER_INVALID", opcode, tx, intent
        );
        goto cleanup;
    }
    if (strcmp(opcode, "SECURITY_RESERVE") == 0) {
        if (!summary.reservation_found) {
            if (requested != summary.committed
                || summary.allocated == ULLONG_MAX
                || _snprintf_s(
                    record, sizeof(record), _TRUNCATE,
                    "H\t%s\t%s\t%s\t%llu\t%llu\n",
                    tx, intent, kind, requested, summary.allocated + 1ULL
                ) < 0
                || wls_win_security_append_locked(file, record) != 0) {
                (void)wls_win_action_error(
                    reply, reply_capacity, "STALE_HIGH_WATER", opcode, tx, intent
                );
                goto cleanup;
            }
            summary.assigned = ++summary.allocated;
            summary.reservation_found = 1;
            summary.transaction_expected = requested;
        } else if (requested != summary.transaction_expected) {
            (void)wls_win_action_error(
                reply, reply_capacity, "BINDING_CONFLICT", opcode, tx, intent
            );
            goto cleanup;
        }
    } else {
        if (!summary.reservation_found || summary.assigned != requested) {
            (void)wls_win_action_error(
                reply, reply_capacity, "BINDING_CONFLICT", opcode, tx, intent
            );
            goto cleanup;
        }
        if (strcmp(opcode, "SECURITY_COMMIT") == 0) {
            if (summary.committed_found) {
                if (strcmp(summary.transaction_anchor, anchor) != 0) goto cleanup;
            } else if (summary.aborted_found
                || summary.assigned <= summary.committed
                || _snprintf_s(
                    record, sizeof(record), _TRUNCATE,
                    "K\t%s\t%s\t%s\t%llu\t%s\n",
                    tx, intent, kind, summary.assigned, anchor
                ) < 0
                || wls_win_security_append_locked(file, record) != 0) goto cleanup;
            summary.committed = summary.assigned;
            memcpy(summary.anchor, anchor, 65U);
        } else if (strcmp(opcode, "SECURITY_ABORT") == 0) {
            if (summary.committed_found) goto cleanup;
            if (!summary.aborted_found && (_snprintf_s(
                    record, sizeof(record), _TRUNCATE,
                    "X\t%s\t%s\t%s\t%llu\n",
                    tx, intent, kind, summary.assigned
                ) < 0
                || wls_win_security_append_locked(file, record) != 0)) goto cleanup;
        } else goto cleanup;
    }
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
        contents = NULL;
    }
    if (wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    wls_win_sha256_hex((const unsigned char *)contents, length, digest);
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\t%s\t%s\t%s\t%llu\t%llu\t%llu\t%s\t%s\n",
        opcode, tx, intent, summary.assigned, summary.allocated,
        summary.committed, digest, summary.anchor
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
    }
    if (file != INVALID_HANDLE_VALUE) {
        (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    return result;
}

static int wls_win_security_attest(
    const wchar_t *home,
    const char *host_id,
    const char *minimum_text,
    const char *expected_ledger,
    const char *expected_anchor,
    char *reply,
    size_t reply_capacity
) {
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char *contents = NULL;
    char *summary_copy = NULL;
    size_t length = 0U;
    char digest[65];
    char zero_tx[33];
    char zero_intent[65];
    unsigned long long minimum = 0ULL;
    struct wls_win_security_summary summary;
    int result = 1;
    memset(zero_tx, '0', 32U); zero_tx[32] = '\0';
    memset(zero_intent, '0', 64U); zero_intent[64] = '\0';
    if (!(wls_is_hex(host_id, 32U) || wls_is_hex(host_id, 64U))
        || wls_parse_u64_zero(minimum_text, &minimum) != 0
        || !(strcmp(expected_ledger, "-") == 0
            || wls_is_hex(expected_ledger, 64U))
        || !(strcmp(expected_anchor, "-") == 0
            || wls_is_hex(expected_anchor, 64U))) {
        return wls_win_action_error(
            reply, reply_capacity, "INVALID", "SECURITY_ATTEST", NULL, NULL
        );
    }
    if (wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    summary_copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U
    );
    if (summary_copy == NULL) goto cleanup;
    memcpy(summary_copy, contents, length + 1U);
    if (wls_win_security_summary(
            summary_copy, zero_tx, zero_intent, "ATTEST", &summary
        ) != 0) goto cleanup;
    wls_win_sha256_hex((const unsigned char *)contents, length, digest);
    if (minimum > summary.committed
        || (strcmp(expected_ledger, "-") != 0
            && strcmp(expected_ledger, digest) != 0)
        || (strcmp(expected_anchor, "-") != 0
            && strcmp(expected_anchor, summary.anchor) != 0)) {
        (void)wls_win_action_error(
            reply, reply_capacity, "ATTESTATION_MISMATCH",
            "SECURITY_ATTEST", NULL, NULL
        );
        goto cleanup;
    }
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tSECURITY_ATTEST\t%s\t%llu\t%llu\t%s\t%s\n",
        host_id, summary.allocated, summary.committed, digest, summary.anchor
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
    }
    if (summary_copy != NULL) {
        SecureZeroMemory(summary_copy, length);
        HeapFree(GetProcessHeap(), 0U, summary_copy);
    }
    if (file != INVALID_HANDLE_VALUE) {
        (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    if (result != 0 && reply[0] == '\0') {
        (void)wls_win_action_error(
            reply, reply_capacity, "LEDGER_INVALID",
            "SECURITY_ATTEST", NULL, NULL
        );
    }
    return result;
}

#define WLS_WIN_MAX_AUTH_ROOTS 64U
#define WLS_WIN_AUTH_ROOT_HEX 16385U

struct wls_win_auth_root_v2 {
    char project[37];
    char tx[33];
    char intent[65];
    unsigned long long assigned;
    char owner[256];
    char alias[65];
    char project_root_hex[WLS_WIN_AUTH_ROOT_HEX];
    char certificate_root_hex[WLS_WIN_AUTH_ROOT_HEX];
    unsigned long long expected_count;
    char project_object[96];
    char certificate_object[96];
    char attestation[65];
};

static int wls_win_object_id(
    HANDLE handle,
    char *output,
    size_t capacity
) {
    BY_HANDLE_FILE_INFORMATION information;
    int written;
    if (!GetFileInformationByHandle(handle, &information)) return 1;
    written = _snprintf_s(
        output, capacity, _TRUNCATE, "%08lx-%08lx%08lx",
        (unsigned long)information.dwVolumeSerialNumber,
        (unsigned long)information.nFileIndexHigh,
        (unsigned long)information.nFileIndexLow
    );
    return written > 0 ? 0 : 1;
}

static int wls_win_auth_attestation(
    const char *project,
    const char *tx,
    const char *intent,
    unsigned long long assigned,
    const char *owner,
    const char *alias,
    const char *project_object,
    const char *certificate_object,
    char output[65]
) {
    char canonical[1536];
    unsigned char digest[32];
    int length = _snprintf_s(
        canonical, sizeof(canonical), _TRUNCATE,
        "%s\n%s\n%s\n%llu\n%s\n%s\n%s\n%s\n",
        project, tx, intent, assigned, owner, alias,
        project_object, certificate_object
    );
    if (length <= 0
        || wls_sha256(
            (const unsigned char *)canonical, (DWORD)length, digest
        ) != 0) return 1;
    wls_hex_encode_32(digest, output);
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(canonical, sizeof(canonical));
    return 0;
}

static int wls_win_auth_validate_root(
    HANDLE client_pipe,
    const char *peer_sid,
    int impersonate,
    const char *owner_sid,
    const char *project_root_hex,
    const char *certificate_root_hex,
    char project_object[96],
    char certificate_object[96]
) {
    wchar_t project_root[WLS_PATH_CHARS];
    wchar_t certificate_root[WLS_PATH_CHARS];
    wchar_t project_final[WLS_PATH_CHARS];
    wchar_t certificate_final[WLS_PATH_CHARS];
    HANDLE project_handle = INVALID_HANDLE_VALUE;
    HANDLE certificate_handle = INVALID_HANDLE_VALUE;
    char project_owner[256];
    char certificate_owner[256];
    int impersonating = 0;
    int result = 1;
    if (!wls_is_sid_text(owner_sid)
        || wls_hex_to_wide(
            project_root_hex, project_root, WLS_PATH_CHARS
        ) != 0
        || wls_hex_to_wide(
            certificate_root_hex, certificate_root, WLS_PATH_CHARS
        ) != 0) return 1;
    if (impersonate) {
        if (wls_begin_client_impersonation(client_pipe, peer_sid) != 0) goto cleanup;
        impersonating = 1;
    }
    project_handle = wls_open_root(
        project_root, FILE_LIST_DIRECTORY | FILE_TRAVERSE | READ_CONTROL
    );
    certificate_handle = wls_open_root(
        certificate_root, FILE_LIST_DIRECTORY | FILE_TRAVERSE | READ_CONTROL
    );
    if (project_handle == INVALID_HANDLE_VALUE
        || certificate_handle == INVALID_HANDLE_VALUE
        || wls_owner_sid(project_handle, project_owner, sizeof(project_owner)) != 0
        || wls_owner_sid(
            certificate_handle, certificate_owner, sizeof(certificate_owner)
        ) != 0
        || _stricmp(project_owner, owner_sid) != 0
        || _stricmp(certificate_owner, owner_sid) != 0
        || wls_final_path(project_handle, project_final, WLS_PATH_CHARS) != 0
        || wls_final_path(
            certificate_handle, certificate_final, WLS_PATH_CHARS
        ) != 0
        || !wls_path_within(certificate_final, project_final)
        || wls_win_object_id(project_handle, project_object, 96U) != 0
        || wls_win_object_id(
            certificate_handle, certificate_object, 96U
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (impersonating) wls_end_client_impersonation();
    if (certificate_handle != INVALID_HANDLE_VALUE) CloseHandle(certificate_handle);
    if (project_handle != INVALID_HANDLE_VALUE) CloseHandle(project_handle);
    return result;
}

static int wls_win_auth_parse_root(
    char **fields,
    size_t count,
    struct wls_win_auth_root_v2 *root
) {
    size_t project_length;
    size_t certificate_length;
    if (fields == NULL || root == NULL) return 1;
    /* Duplicate-ledger comparison covers the whole struct. Deterministically
     * initialize ABI padding so identical records cannot disagree on
     * indeterminate stack bytes. */
    ZeroMemory(root, sizeof(*root));
    if (count != 13U || strcmp(fields[0], "P") != 0
        || !wls_is_uuid(fields[1]) || !wls_is_hex(fields[2], 32U)
        || !wls_is_hex(fields[3], 64U)
        || wls_parse_generation(fields[4], &root->assigned) != 0
        || !wls_is_sid_text(fields[5]) || !wls_is_alias(fields[6])) return 1;
    project_length = strlen(fields[7]);
    certificate_length = strlen(fields[8]);
    if (project_length < 2U || certificate_length < 2U
        || (project_length & 1U) != 0U || (certificate_length & 1U) != 0U
        || project_length >= sizeof(root->project_root_hex)
        || certificate_length >= sizeof(root->certificate_root_hex)
        || !wls_is_hex(fields[7], project_length)
        || !wls_is_hex(fields[8], certificate_length)
        || wls_parse_generation(fields[9], &root->expected_count) != 0
        || root->expected_count > WLS_WIN_MAX_AUTH_ROOTS
        || strlen(fields[10]) >= sizeof(root->project_object)
        || strlen(fields[11]) >= sizeof(root->certificate_object)
        || !wls_is_hex(fields[12], 64U)) return 1;
    strcpy_s(root->project, sizeof(root->project), fields[1]);
    strcpy_s(root->tx, sizeof(root->tx), fields[2]);
    strcpy_s(root->intent, sizeof(root->intent), fields[3]);
    strcpy_s(root->owner, sizeof(root->owner), fields[5]);
    strcpy_s(root->alias, sizeof(root->alias), fields[6]);
    strcpy_s(root->project_root_hex, sizeof(root->project_root_hex), fields[7]);
    strcpy_s(root->certificate_root_hex, sizeof(root->certificate_root_hex), fields[8]);
    strcpy_s(root->project_object, sizeof(root->project_object), fields[10]);
    strcpy_s(root->certificate_object, sizeof(root->certificate_object), fields[11]);
    strcpy_s(root->attestation, sizeof(root->attestation), fields[12]);
    return 0;
}

static int wls_win_auth_prepare_v2(
    const wchar_t *home,
    HANDLE client_pipe,
    const char *peer_sid,
    const char *project,
    const char *tx,
    const char *intent,
    const char *owner,
    const char *alias,
    const char *project_root_hex,
    const char *certificate_root_hex,
    const char *expected_count_text,
    const char *expected_previous,
    char *reply,
    size_t reply_capacity
) {
    unsigned long long expected_count = 0ULL;
    unsigned long long assigned = 0ULL;
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char *contents = NULL;
    size_t length = 0U;
    char reserve_reply[1024];
    char project_object[96];
    char certificate_object[96];
    char attestation[65];
    char *record = NULL;
    size_t record_capacity;
    char *cursor;
    int duplicate = 0;
    int result = 1;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U) || !wls_is_sid_text(owner)
        || !wls_is_alias(alias)
        || strlen(project_root_hex) >= WLS_WIN_AUTH_ROOT_HEX
        || strlen(certificate_root_hex) >= WLS_WIN_AUTH_ROOT_HEX
        || wls_parse_generation(expected_count_text, &expected_count) != 0
        || expected_count > WLS_WIN_MAX_AUTH_ROOTS
        || wls_win_auth_validate_root(
            client_pipe, peer_sid, 1, owner, project_root_hex,
            certificate_root_hex, project_object, certificate_object
        ) != 0
        || wls_win_security_operation(
            home, "SECURITY_RESERVE", tx, intent, "AUTH",
            expected_previous, NULL, reserve_reply, sizeof(reserve_reply)
        ) != 0
        || sscanf_s(
            reserve_reply,
            "WLS-ACTION/2\tOK\tSECURITY_RESERVE\t%*32s\t%*64s\t%llu",
            &assigned
        ) != 1
        || wls_win_auth_attestation(
            project, tx, intent, assigned, owner, alias,
            project_object, certificate_object, attestation
        ) != 0
        || wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    cursor = contents;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t count = 0U;
        struct wls_win_auth_root_v2 existing;
        if (newline == NULL) goto cleanup;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto cleanup;
        if (strcmp(fields[0], "P") == 0) {
            if (wls_win_auth_parse_root(fields, count, &existing) != 0) goto cleanup;
            if (strcmp(existing.tx, tx) == 0
                && strcmp(existing.alias, alias) == 0) {
                if (strcmp(existing.project, project) != 0
                    || strcmp(existing.intent, intent) != 0
                    || existing.assigned != assigned
                    || _stricmp(existing.owner, owner) != 0
                    || existing.expected_count != expected_count
                    || strcmp(existing.project_root_hex, project_root_hex) != 0
                    || strcmp(existing.certificate_root_hex, certificate_root_hex) != 0
                    || strcmp(existing.project_object, project_object) != 0
                    || strcmp(existing.certificate_object, certificate_object) != 0
                    || strcmp(existing.attestation, attestation) != 0) goto cleanup;
                duplicate = 1;
            }
        }
        cursor = newline + 1U;
    }
    if (!duplicate) {
        record_capacity = strlen(project_root_hex) + strlen(certificate_root_hex)
            + strlen(owner) + 1024U;
        record = (char *)HeapAlloc(
            GetProcessHeap(), HEAP_ZERO_MEMORY, record_capacity
        );
        if (record == NULL || _snprintf_s(
            record, record_capacity, _TRUNCATE,
            "P\t%s\t%s\t%s\t%llu\t%s\t%s\t%s\t%s\t%llu\t%s\t%s\t%s\n",
            project, tx, intent, assigned, owner, alias, project_root_hex,
            certificate_root_hex, expected_count, project_object,
            certificate_object, attestation
        ) < 0 || wls_win_security_append_locked(file, record) != 0) goto cleanup;
    }
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tAUTH_PREPARE\t%s\t%s\t%llu\t%s\t%s\t%s\n",
        tx, intent, assigned, project_object, certificate_object, attestation
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (record != NULL) {
        SecureZeroMemory(record, record_capacity);
        HeapFree(GetProcessHeap(), 0U, record);
    }
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
    }
    if (file != INVALID_HANDLE_VALUE) {
        (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    if (result != 0) (void)wls_win_action_error(
        reply, reply_capacity, "BINDING_CONFLICT", "AUTH_PREPARE", tx, intent
    );
    return result;
}

static int wls_win_auth_compare(const void *left, const void *right)
{
    const struct wls_win_auth_root_v2 *a = left;
    const struct wls_win_auth_root_v2 *b = right;
    return strcmp(a->alias, b->alias);
}

static int wls_win_auth_collect(
    char *contents,
    const char *project,
    const char *tx,
    const char *intent,
    struct wls_win_auth_root_v2 *roots,
    size_t *root_count,
    int *committed,
    int *aborted,
    char committed_digest[65]
) {
    char *cursor = contents;
    size_t count = 0U;
    *committed = 0;
    *aborted = 0;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t field_count = 0U;
        if (newline == NULL) return 1;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &field_count) != 0) return 1;
        if (strcmp(fields[0], "P") == 0) {
            struct wls_win_auth_root_v2 parsed;
            size_t index;
            if (wls_win_auth_parse_root(fields, field_count, &parsed) != 0) return 1;
            if (strcmp(parsed.project, project) == 0
                && strcmp(parsed.tx, tx) == 0
                && strcmp(parsed.intent, intent) == 0) {
                if (count >= WLS_WIN_MAX_AUTH_ROOTS) return 1;
                for (index = 0U; index < count; index++) {
                    if (strcmp(roots[index].alias, parsed.alias) == 0) {
                        if (memcmp(&roots[index], &parsed, sizeof(parsed)) != 0) return 1;
                        break;
                    }
                }
                if (index == count) roots[count++] = parsed;
            }
        } else if (strcmp(fields[0], "C") == 0) {
            unsigned long long assigned;
            unsigned long long expected_count;
            if (field_count != 7U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_generation(fields[4], &assigned) != 0
                || wls_parse_generation(fields[5], &expected_count) != 0
                || !wls_is_hex(fields[6], 64U)) return 1;
            if (strcmp(fields[1], project) == 0 && strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[3], intent) != 0 || *committed) return 1;
                *committed = 1;
                memcpy(committed_digest, fields[6], 65U);
            }
        } else if (strcmp(fields[0], "Q") == 0) {
            unsigned long long assigned;
            if (field_count != 5U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_generation(fields[4], &assigned) != 0) return 1;
            if (strcmp(fields[1], project) == 0 && strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[3], intent) != 0 || *aborted) return 1;
                *aborted = 1;
            }
        } else if (strcmp(fields[0], "T") == 0) {
            unsigned long long assigned;
            unsigned long long expected_count;
            if (field_count != 11U || !wls_is_uuid(fields[1])
                || !wls_is_uuid(fields[2]) || !wls_is_hex(fields[3], 32U)
                || !wls_is_hex(fields[4], 64U)
                || wls_parse_generation(fields[5], &assigned) != 0
                || wls_parse_generation(fields[7], &expected_count) != 0
                || !wls_is_hex(fields[8], 64U)
                || !wls_is_hex(fields[10], 64U)) return 1;
            if (strcmp(fields[1], project) == 0) *aborted = 1;
            if (strcmp(fields[2], project) == 0
                && strcmp(fields[3], tx) == 0) {
                if (strcmp(fields[4], intent) != 0 || *committed) return 1;
                *committed = 1;
                memcpy(committed_digest, fields[8], 65U);
            }
        }
        cursor = newline + 1U;
    }
    *root_count = count;
    return 0;
}

static int wls_win_auth_commit_v2(
    const wchar_t *home,
    const char *project,
    const char *tx,
    const char *intent,
    const char *expected_count_text,
    const char *expected_roots_digest,
    char *reply,
    size_t reply_capacity
) {
    struct wls_win_auth_root_v2 *roots = NULL;
    struct wls_win_security_summary summary;
    unsigned long long expected_count = 0ULL;
    size_t root_count = 0U;
    size_t index;
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char *contents = NULL;
    char *summary_copy = NULL;
    char *canonical = NULL;
    size_t length = 0U;
    size_t summary_length = 0U;
    size_t canonical_length = 0U;
    size_t canonical_capacity = WLS_WIN_MAX_AUTH_ROOTS * 512U + 1U;
    char roots_digest[65];
    char ledger_digest[65];
    char committed_digest[65] = {0};
    char record[512];
    int committed = 0;
    int aborted = 0;
    int result = 1;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U)
        || wls_parse_generation(expected_count_text, &expected_count) != 0
        || expected_count > WLS_WIN_MAX_AUTH_ROOTS
        || !wls_is_hex(expected_roots_digest, 64U)
        || wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    roots = (struct wls_win_auth_root_v2 *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        sizeof(*roots) * WLS_WIN_MAX_AUTH_ROOTS
    );
    summary_copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U
    );
    canonical = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, canonical_capacity
    );
    if (roots == NULL || summary_copy == NULL || canonical == NULL) goto cleanup;
    summary_length = length;
    memcpy(summary_copy, contents, length + 1U);
    if (wls_win_security_summary(
            summary_copy, tx, intent, "AUTH", &summary
        ) != 0
        || !summary.reservation_found || summary.aborted_found
        || wls_win_auth_collect(
            contents, project, tx, intent, roots, &root_count,
            &committed, &aborted, committed_digest
        ) != 0
        || aborted || root_count != (size_t)expected_count || root_count == 0U) goto cleanup;
    qsort(roots, root_count, sizeof(*roots), wls_win_auth_compare);
    for (index = 0U; index < root_count; index++) {
        char project_object[96];
        char certificate_object[96];
        char attestation[65];
        int amount;
        if (roots[index].assigned != summary.assigned
            || roots[index].expected_count != expected_count
            || (index > 0U
                && strcmp(roots[index - 1U].alias, roots[index].alias) >= 0)
            || wls_win_auth_validate_root(
                NULL, NULL, 0, roots[index].owner,
                roots[index].project_root_hex,
                roots[index].certificate_root_hex,
                project_object, certificate_object
            ) != 0
            || strcmp(project_object, roots[index].project_object) != 0
            || strcmp(certificate_object, roots[index].certificate_object) != 0
            || wls_win_auth_attestation(
                project, tx, intent, summary.assigned, roots[index].owner,
                roots[index].alias, project_object, certificate_object,
                attestation
            ) != 0
            || strcmp(attestation, roots[index].attestation) != 0) goto cleanup;
        amount = _snprintf_s(
            canonical + canonical_length,
            canonical_capacity - canonical_length,
            _TRUNCATE,
            "%s\t%llu\t%s\t%s\t%s\n",
            roots[index].alias, summary.assigned, project_object,
            certificate_object, attestation
        );
        if (amount <= 0) goto cleanup;
        canonical_length += (size_t)amount;
    }
    wls_win_sha256_hex(
        (const unsigned char *)canonical, canonical_length, roots_digest
    );
    if (strcmp(roots_digest, expected_roots_digest) != 0) goto cleanup;
    if (!committed) {
        if (summary.assigned <= summary.committed
            || _snprintf_s(
                record, sizeof(record), _TRUNCATE,
                "C\t%s\t%s\t%s\t%llu\t%llu\t%s\n",
                project, tx, intent, summary.assigned,
                expected_count, roots_digest
            ) < 0
            || wls_win_security_append_locked(file, record) != 0) goto cleanup;
    } else if (strcmp(committed_digest, roots_digest) != 0) goto cleanup;
    SecureZeroMemory(contents, length);
    HeapFree(GetProcessHeap(), 0U, contents);
    contents = NULL;
    if (wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    wls_win_sha256_hex((const unsigned char *)contents, length, ledger_digest);
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tAUTH_COMMIT\t%s\t%s\t%llu\t%llu\t%s\t%s\n",
        tx, intent, summary.assigned, expected_count, roots_digest, ledger_digest
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (result != 0) (void)wls_win_action_error(
        reply, reply_capacity, "ROOT_SET_MISMATCH", "AUTH_COMMIT", tx, intent
    );
    if (canonical != NULL) {
        SecureZeroMemory(canonical, canonical_capacity);
        HeapFree(GetProcessHeap(), 0U, canonical);
    }
    if (summary_copy != NULL) {
        SecureZeroMemory(summary_copy, summary_length);
        HeapFree(GetProcessHeap(), 0U, summary_copy);
    }
    if (roots != NULL) {
        SecureZeroMemory(roots, sizeof(*roots) * WLS_WIN_MAX_AUTH_ROOTS);
        HeapFree(GetProcessHeap(), 0U, roots);
    }
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
    }
    if (file != INVALID_HANDLE_VALUE) {
        (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    return result;
}

static int wls_win_auth_transfer_target(
    const char *contents,
    size_t length,
    const char *project,
    const char *tx,
    const char *intent
);

static int wls_win_auth_load_root(
    const wchar_t *home,
    const char *project,
    const char *tx,
    const char *intent,
    const char *alias,
    struct wls_win_auth_root_v2 *selected
) {
    struct wls_win_auth_root_v2 *roots = NULL;
    struct wls_win_security_summary summary;
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char *contents = NULL;
    char *copy = NULL;
    size_t length = 0U;
    size_t root_count = 0U;
    size_t index;
    int committed = 0;
    int aborted = 0;
    char committed_digest[65] = {0};
    int transfer_target = 0;
    int result = 1;
    if (wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    roots = (struct wls_win_auth_root_v2 *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        sizeof(*roots) * WLS_WIN_MAX_AUTH_ROOTS
    );
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (roots == NULL || copy == NULL) goto cleanup;
    memcpy(copy, contents, length + 1U);
    transfer_target = wls_win_auth_transfer_target(
        contents, length, project, tx, intent
    );
    if (transfer_target < 0
        || wls_win_security_summary(
            copy, tx, intent,
            transfer_target ? "AUTH_TRANSFER" : "AUTH", &summary
        ) != 0
        || wls_win_auth_collect(
            contents, project, tx, intent, roots, &root_count,
            &committed, &aborted, committed_digest
        ) != 0
        || !committed || aborted || !summary.committed_found) goto cleanup;
    for (index = 0U; index < root_count; index++) {
        if (strcmp(roots[index].alias, alias) == 0) {
            char project_object[96];
            char certificate_object[96];
            char attestation[65];
            if (wls_win_auth_validate_root(
                    NULL, NULL, 0, roots[index].owner,
                    roots[index].project_root_hex,
                    roots[index].certificate_root_hex,
                    project_object, certificate_object
                ) != 0
                || strcmp(project_object, roots[index].project_object) != 0
                || strcmp(certificate_object, roots[index].certificate_object) != 0
                || wls_win_auth_attestation(
                    project, tx, intent, roots[index].assigned,
                    roots[index].owner, alias, project_object,
                    certificate_object, attestation
                ) != 0
                || strcmp(attestation, roots[index].attestation) != 0) goto cleanup;
            *selected = roots[index];
            result = 0;
            break;
        }
    }
cleanup:
    if (copy != NULL) {
        SecureZeroMemory(copy, length);
        HeapFree(GetProcessHeap(), 0U, copy);
    }
    if (roots != NULL) {
        SecureZeroMemory(roots, sizeof(*roots) * WLS_WIN_MAX_AUTH_ROOTS);
        HeapFree(GetProcessHeap(), 0U, roots);
    }
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
    }
    if (file != INVALID_HANDLE_VALUE) {
        (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    return result;
}

static int wls_win_auth_abort_v2(
    const wchar_t *home,
    const char *project,
    const char *tx,
    const char *intent,
    char *reply,
    size_t reply_capacity
) {
    struct wls_win_security_summary summary;
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char *contents = NULL;
    char *copy = NULL;
    char *binding_copy = NULL;
    size_t length = 0U;
    size_t copy_length = 0U;
    char record[256];
    char digest[65];
    int result = 1;
    int project_binding_found = 0;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U)
        || wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (copy == NULL) goto cleanup;
    copy_length = length;
    memcpy(copy, contents, length + 1U);
    if (wls_win_security_summary(copy, tx, intent, "AUTH", &summary) != 0
        || summary.committed_found) goto cleanup;
    binding_copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U
    );
    if (binding_copy == NULL) goto cleanup;
    memcpy(binding_copy, contents, length + 1U);
    {
        char *cursor = binding_copy;
        while (cursor[0] != '\0') {
            char *newline = strchr(cursor, '\n');
            char *fields[16];
            size_t count = 0U;
            if (newline == NULL) goto cleanup;
            *newline = '\0';
            if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto cleanup;
            if ((strcmp(fields[0], "P") == 0
                    || strcmp(fields[0], "C") == 0
                    || strcmp(fields[0], "Q") == 0)
                && count >= 4U
                && strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[1], project) != 0
                    || strcmp(fields[3], intent) != 0) goto cleanup;
                project_binding_found = 1;
            }
            cursor = newline + 1U;
        }
    }
    SecureZeroMemory(binding_copy, length);
    HeapFree(GetProcessHeap(), 0U, binding_copy);
    binding_copy = NULL;
    if (!summary.reservation_found) {
        if (summary.aborted_found || project_binding_found) goto cleanup;
        wls_win_sha256_hex((const unsigned char *)contents, length, digest);
        if (_snprintf_s(
            reply, reply_capacity, _TRUNCATE,
            "WLS-ACTION/2\tOK\tAUTH_ABORT\t%s\t%s\t0\t%llu\t%s\n",
            tx, intent, summary.allocated, digest
        ) < 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (!summary.aborted_found
        && (_snprintf_s(
            record, sizeof(record), _TRUNCATE,
            "Q\t%s\t%s\t%s\t%llu\n",
            project, tx, intent, summary.assigned
        ) < 0 || wls_win_security_append_locked(file, record) != 0)) goto cleanup;
    SecureZeroMemory(contents, length);
    HeapFree(GetProcessHeap(), 0U, contents);
    contents = NULL;
    if (wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    wls_win_sha256_hex((const unsigned char *)contents, length, digest);
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tAUTH_ABORT\t%s\t%s\t%llu\t%llu\t%s\n",
        tx, intent, summary.assigned, summary.allocated, digest
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (result != 0) (void)wls_win_action_error(
        reply, reply_capacity, "BINDING_CONFLICT", "AUTH_ABORT", tx, intent
    );
    if (copy != NULL) {
        SecureZeroMemory(copy, copy_length);
        HeapFree(GetProcessHeap(), 0U, copy);
    }
    if (binding_copy != NULL) {
        SecureZeroMemory(binding_copy, length);
        HeapFree(GetProcessHeap(), 0U, binding_copy);
    }
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
    }
    if (file != INVALID_HANDLE_VALUE) {
        (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    return result;
}

static int wls_win_auth_attest_v2(
    const wchar_t *home,
    const char *project,
    const char *tx,
    const char *intent,
    const char *alias,
    char *reply,
    size_t reply_capacity
) {
    struct wls_win_auth_root_v2 root;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U) || !wls_is_alias(alias)
        || wls_win_auth_load_root(
            home, project, tx, intent, alias, &root
        ) != 0) return wls_win_action_error(
            reply, reply_capacity, "NOT_COMMITTED", "ATTEST_ROOT", tx, intent
        );
    return _snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tATTEST_ROOT\t%s\t%s\t%llu\t%s\t%s\t%s\t%s\t%s\n",
        tx, intent, root.assigned, root.owner, alias,
        root.project_object, root.certificate_object, root.attestation
    ) > 0 ? 0 : 1;
}

struct wls_win_auth_transfer_v2 {
    int prepared;
    int committed;
    int aborted;
    unsigned long long assigned;
    unsigned long long expected_previous;
    unsigned long long root_count;
    char owner[256];
    char roots_digest[65];
    char previous_anchor[65];
    char tombstone_digest[65];
};

static int wls_win_auth_transfer_target(
    const char *contents,
    size_t length,
    const char *project,
    const char *tx,
    const char *intent
) {
    char *copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U
    );
    char *cursor;
    int found = 0;
    if (copy == NULL) return -1;
    memcpy(copy, contents, length + 1U);
    cursor = copy;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16]; size_t count = 0U;
        if (newline == NULL) { found = -1; break; }
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) {
            found = -1; break;
        }
        if (strcmp(fields[0], "R") == 0 && count == 10U
            && strcmp(fields[2], project) == 0
            && strcmp(fields[3], tx) == 0) {
            if (strcmp(fields[4], intent) != 0 || found) { found = -1; break; }
            found = 1;
        }
        cursor = newline + 1U;
    }
    SecureZeroMemory(copy, length);
    HeapFree(GetProcessHeap(), 0U, copy);
    return found;
}

static int wls_win_auth_transfer_scan(
    char *contents,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    struct wls_win_auth_transfer_v2 *transfer
) {
    char *cursor = contents;
    ZeroMemory(transfer, sizeof(*transfer));
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16]; size_t count = 0U;
        if (newline == NULL) return 1;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) return 1;
        if (strcmp(fields[0], "R") == 0 && count == 10U
            && strcmp(fields[3], rotation_id) == 0) {
            unsigned long long assigned, expected, roots;
            if (strcmp(fields[1], old_project) != 0
                || strcmp(fields[2], new_project) != 0
                || strcmp(fields[4], intent) != 0
                || wls_parse_generation(fields[5], &assigned) != 0
                || !wls_is_sid_text(fields[6])
                || wls_parse_u64_zero(fields[7], &expected) != 0
                || wls_parse_generation(fields[8], &roots) != 0
                || !wls_is_hex(fields[9], 64U) || transfer->prepared) return 1;
            transfer->prepared = 1;
            transfer->assigned = assigned;
            transfer->expected_previous = expected;
            transfer->root_count = roots;
            strcpy_s(transfer->owner, sizeof(transfer->owner), fields[6]);
            memcpy(transfer->roots_digest, fields[9], 65U);
        } else if (strcmp(fields[0], "T") == 0 && count == 11U
            && strcmp(fields[3], rotation_id) == 0) {
            unsigned long long assigned, roots;
            char canonical[1536];
            char calculated[65];
            int canonical_length;
            if (strcmp(fields[1], old_project) != 0
                || strcmp(fields[2], new_project) != 0
                || strcmp(fields[4], intent) != 0
                || wls_parse_generation(fields[5], &assigned) != 0
                || !wls_is_sid_text(fields[6])
                || wls_parse_generation(fields[7], &roots) != 0
                || !wls_is_hex(fields[8], 64U)
                || !wls_is_hex(fields[9], 64U)
                || !wls_is_hex(fields[10], 64U)
                || !transfer->prepared || transfer->aborted
                || transfer->committed) return 1;
            canonical_length = _snprintf_s(
                canonical, sizeof(canonical), _TRUNCATE,
                "%s\n%s\n%s\n%s\n%llu\n%s\n%llu\n%s\n%s\n",
                fields[1], fields[2], fields[3], fields[4], assigned,
                fields[6], roots, fields[8], fields[9]
            );
            if (canonical_length <= 0) return 1;
            wls_win_sha256_hex(
                (const unsigned char *)canonical,
                (size_t)canonical_length,
                calculated
            );
            if (strcmp(calculated, fields[10]) != 0) return 1;
            transfer->committed = 1;
            if (transfer->assigned != assigned || transfer->root_count != roots
                || _stricmp(transfer->owner, fields[6]) != 0
                || strcmp(transfer->roots_digest, fields[8]) != 0) return 1;
            memcpy(transfer->previous_anchor, fields[9], 65U);
            memcpy(transfer->tombstone_digest, fields[10], 65U);
        } else if (strcmp(fields[0], "Y") == 0 && count == 6U
            && strcmp(fields[3], rotation_id) == 0) {
            unsigned long long assigned;
            if (strcmp(fields[1], old_project) != 0
                || strcmp(fields[2], new_project) != 0
                || strcmp(fields[4], intent) != 0
                || wls_parse_generation(fields[5], &assigned) != 0
                || !transfer->prepared || transfer->committed
                || transfer->aborted
                || transfer->assigned != assigned) return 1;
            transfer->aborted = 1;
        }
        cursor = newline + 1U;
    }
    return 0;
}

static int wls_win_auth_latest_project(
    const char *contents,
    size_t length,
    const char *project,
    struct wls_win_auth_root_v2 *roots,
    size_t *root_count,
    char tx[33],
    char intent[65],
    unsigned long long *assigned,
    char committed_digest[65]
) {
    char *first = NULL, *second = NULL, *cursor;
    unsigned long long expected_count = 0ULL;
    int committed = 0, aborted = 0, found = 0, result = 1;
    first = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    second = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (first == NULL || second == NULL) goto cleanup;
    memcpy(first, contents, length + 1U);
    memcpy(second, contents, length + 1U);
    cursor = first;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16]; size_t count = 0U;
        unsigned long long candidate = 0ULL, candidate_count = 0ULL;
        if (newline == NULL) goto cleanup;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto cleanup;
        if (strcmp(fields[0], "T") == 0 && count == 11U
            && strcmp(fields[1], project) == 0) goto cleanup;
        if (strcmp(fields[0], "C") == 0 && count == 7U
            && strcmp(fields[1], project) == 0) {
            if (wls_parse_generation(fields[4], &candidate) != 0
                || wls_parse_generation(fields[5], &candidate_count) != 0
                || !wls_is_hex(fields[6], 64U)) goto cleanup;
        } else if (strcmp(fields[0], "T") == 0 && count == 11U
            && strcmp(fields[2], project) == 0) {
            if (wls_parse_generation(fields[5], &candidate) != 0
                || wls_parse_generation(fields[7], &candidate_count) != 0
                || !wls_is_hex(fields[8], 64U)) goto cleanup;
        }
        if (candidate > 0ULL && (!found || candidate > *assigned)) {
            const char *candidate_tx = strcmp(fields[0], "C") == 0
                ? fields[2] : fields[3];
            const char *candidate_intent = strcmp(fields[0], "C") == 0
                ? fields[3] : fields[4];
            const char *candidate_digest = strcmp(fields[0], "C") == 0
                ? fields[6] : fields[8];
            *assigned = candidate;
            expected_count = candidate_count;
            memcpy(tx, candidate_tx, 33U);
            memcpy(intent, candidate_intent, 65U);
            memcpy(committed_digest, candidate_digest, 65U);
            found = 1;
        }
        cursor = newline + 1U;
    }
    if (!found || expected_count == 0ULL || expected_count > WLS_WIN_MAX_AUTH_ROOTS
        || wls_win_auth_collect(
            second, project, tx, intent, roots, root_count,
            &committed, &aborted, committed_digest
        ) != 0
        || !committed || aborted || *root_count != (size_t)expected_count) goto cleanup;
    result = 0;
cleanup:
    if (first != NULL) { SecureZeroMemory(first, length); HeapFree(GetProcessHeap(), 0U, first); }
    if (second != NULL) { SecureZeroMemory(second, length); HeapFree(GetProcessHeap(), 0U, second); }
    return result;
}

static int wls_win_auth_transfer_roots(
    struct wls_win_auth_root_v2 *roots,
    size_t root_count,
    const char *old_project,
    const char *old_tx,
    const char *old_intent,
    unsigned long long old_assigned,
    const char *old_digest,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    unsigned long long assigned,
    const char *owner,
    char (*attestations)[65],
    char roots_digest[65]
) {
    size_t capacity = root_count * 512U + 1U;
    char *old_canonical = NULL, *new_canonical = NULL;
    size_t old_length = 0U, new_length = 0U, index;
    char actual_old_digest[65];
    int result = 1;
    qsort(roots, root_count, sizeof(roots[0]), wls_win_auth_compare);
    old_canonical = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, capacity);
    new_canonical = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, capacity);
    if (old_canonical == NULL || new_canonical == NULL) goto cleanup;
    for (index = 0U; index < root_count; index++) {
        char project_object[96], certificate_object[96], old_attestation[65];
        int amount;
        if (_stricmp(roots[index].owner, owner) != 0
            || roots[index].assigned != old_assigned
            || strcmp(roots[index].project, old_project) != 0
            || strcmp(roots[index].tx, old_tx) != 0
            || strcmp(roots[index].intent, old_intent) != 0
            || (index > 0U
                && strcmp(roots[index - 1U].alias, roots[index].alias) >= 0)
            || wls_win_auth_validate_root(
                NULL, NULL, 0, owner, roots[index].project_root_hex,
                roots[index].certificate_root_hex, project_object,
                certificate_object
            ) != 0
            || strcmp(project_object, roots[index].project_object) != 0
            || strcmp(certificate_object, roots[index].certificate_object) != 0
            || wls_win_auth_attestation(
                old_project, old_tx, old_intent, old_assigned, owner,
                roots[index].alias, project_object, certificate_object,
                old_attestation
            ) != 0
            || strcmp(old_attestation, roots[index].attestation) != 0
            || wls_win_auth_attestation(
                new_project, rotation_id, intent, assigned, owner,
                roots[index].alias, project_object, certificate_object,
                attestations[index]
            ) != 0) goto cleanup;
        amount = _snprintf_s(
            old_canonical + old_length, capacity - old_length, _TRUNCATE,
            "%s\t%llu\t%s\t%s\t%s\n", roots[index].alias, old_assigned,
            project_object, certificate_object, old_attestation
        );
        if (amount <= 0) goto cleanup;
        old_length += (size_t)amount;
        amount = _snprintf_s(
            new_canonical + new_length, capacity - new_length, _TRUNCATE,
            "%s\t%llu\t%s\t%s\t%s\n", roots[index].alias, assigned,
            project_object, certificate_object, attestations[index]
        );
        if (amount <= 0) goto cleanup;
        new_length += (size_t)amount;
    }
    wls_win_sha256_hex((const unsigned char *)old_canonical, old_length, actual_old_digest);
    if (strcmp(actual_old_digest, old_digest) != 0) goto cleanup;
    wls_win_sha256_hex((const unsigned char *)new_canonical, new_length, roots_digest);
    result = 0;
cleanup:
    if (old_canonical != NULL) { SecureZeroMemory(old_canonical, capacity); HeapFree(GetProcessHeap(), 0U, old_canonical); }
    if (new_canonical != NULL) { SecureZeroMemory(new_canonical, capacity); HeapFree(GetProcessHeap(), 0U, new_canonical); }
    return result;
}

static int wls_win_auth_transfer_authorized(
    const wchar_t *channel,
    const char *peer_sid,
    const char *owner
) {
    return (wcscmp(channel, L"admin") == 0
            && _stricmp(peer_sid, "S-1-5-18") == 0)
        || (wcscmp(channel, L"project") == 0
            && _stricmp(peer_sid, owner) == 0);
}

static int wls_win_auth_transfer_prepare_v2(
    const wchar_t *home,
    const wchar_t *channel,
    const char *peer_sid,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    const char *owner,
    const char *expected_previous,
    char *reply,
    size_t reply_capacity
) {
    struct wls_win_auth_root_v2 *roots = NULL;
    struct wls_win_auth_transfer_v2 transfer;
    char (*attestations)[65] = NULL;
    unsigned long long assigned = 0ULL, old_assigned = 0ULL;
    size_t root_count = 0U, length = 0U;
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char old_tx[33], old_intent[65], old_digest[65];
    char roots_digest[65], registry_digest[65], reserve_reply[1024], record[1536];
    char *contents = NULL, *copy = NULL;
    int result = 1;
    if (!wls_is_uuid(old_project) || !wls_is_uuid(new_project)
        || strcmp(old_project, new_project) == 0
        || !wls_is_hex(rotation_id, 32U) || !wls_is_hex(intent, 64U)
        || !wls_is_sid_text(owner)
        || !wls_win_auth_transfer_authorized(channel, peer_sid, owner)
        || wls_win_security_operation(
            home, "SECURITY_RESERVE", rotation_id, intent,
            "AUTH_TRANSFER", expected_previous, NULL,
            reserve_reply, sizeof(reserve_reply)
        ) != 0
        || sscanf_s(
            reserve_reply,
            "WLS-ACTION/2\tOK\tSECURITY_RESERVE\t%*32s\t%*64s\t%llu",
            &assigned
        ) != 1
        || wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    roots = (struct wls_win_auth_root_v2 *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        sizeof(*roots) * WLS_WIN_MAX_AUTH_ROOTS
    );
    attestations = (char (*)[65])HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        sizeof(*attestations) * WLS_WIN_MAX_AUTH_ROOTS
    );
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (roots == NULL || attestations == NULL || copy == NULL) goto cleanup;
    memcpy(copy, contents, length + 1U);
    if (wls_win_auth_transfer_scan(
            copy, old_project, new_project, rotation_id, intent, &transfer
        ) != 0
        || transfer.aborted
        || (transfer.prepared
            && (transfer.assigned != assigned
                || _stricmp(transfer.owner, owner) != 0))) goto cleanup;
    SecureZeroMemory(copy, length); HeapFree(GetProcessHeap(), 0U, copy); copy = NULL;
    if (!transfer.prepared) {
        if (wls_win_auth_latest_project(
                contents, length, old_project, roots, &root_count,
                old_tx, old_intent, &old_assigned, old_digest
            ) != 0
            || wls_win_auth_transfer_roots(
                roots, root_count, old_project, old_tx, old_intent,
                old_assigned, old_digest, new_project, rotation_id, intent,
                assigned, owner, attestations, roots_digest
            ) != 0
            || _snprintf_s(
                record, sizeof(record), _TRUNCATE,
                "R\t%s\t%s\t%s\t%s\t%llu\t%s\t%s\t%llu\t%s\n",
                old_project, new_project, rotation_id, intent, assigned,
                owner, expected_previous, (unsigned long long)root_count,
                roots_digest
            ) < 0
            || wls_win_security_append_locked(file, record) != 0) goto cleanup;
    }
    SecureZeroMemory(contents, length); HeapFree(GetProcessHeap(), 0U, contents);
    contents = NULL;
    if (wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    wls_win_sha256_hex((const unsigned char *)contents, length, registry_digest);
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tAUTH_TRANSFER_PREPARE\t%s\t%s\t%llu\t%s\n",
        rotation_id, intent, assigned, registry_digest
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (result != 0) (void)wls_win_action_error(
        reply, reply_capacity, "TRANSFER_CONFLICT",
        "AUTH_TRANSFER_PREPARE", rotation_id, intent
    );
    if (copy != NULL) { SecureZeroMemory(copy, length); HeapFree(GetProcessHeap(), 0U, copy); }
    if (contents != NULL) { SecureZeroMemory(contents, length); HeapFree(GetProcessHeap(), 0U, contents); }
    if (attestations != NULL) { SecureZeroMemory(attestations, sizeof(*attestations) * WLS_WIN_MAX_AUTH_ROOTS); HeapFree(GetProcessHeap(), 0U, attestations); }
    if (roots != NULL) { SecureZeroMemory(roots, sizeof(*roots) * WLS_WIN_MAX_AUTH_ROOTS); HeapFree(GetProcessHeap(), 0U, roots); }
    if (file != INVALID_HANDLE_VALUE) { (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock); CloseHandle(file); }
    return result;
}

static int wls_win_auth_transfer_existing_roots(
    const char *contents,
    size_t length,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    unsigned long long assigned,
    const char *owner,
    struct wls_win_auth_root_v2 *roots,
    size_t root_count,
    char (*attestations)[65],
    int *present
) {
    char *copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U
    );
    char *cursor;
    size_t index;
    int result = 1;
    if (copy == NULL) return 1;
    ZeroMemory(present, sizeof(int) * WLS_WIN_MAX_AUTH_ROOTS);
    memcpy(copy, contents, length + 1U);
    cursor = copy;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16]; size_t count = 0U;
        if (newline == NULL) goto cleanup;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto cleanup;
        if (strcmp(fields[0], "P") == 0 && count == 13U
            && strcmp(fields[1], new_project) == 0) {
            struct wls_win_auth_root_v2 parsed;
            if (wls_win_auth_parse_root(fields, count, &parsed) != 0
                || strcmp(parsed.tx, rotation_id) != 0
                || strcmp(parsed.intent, intent) != 0
                || parsed.assigned != assigned
                || _stricmp(parsed.owner, owner) != 0
                || parsed.expected_count != (unsigned long long)root_count) goto cleanup;
            for (index = 0U; index < root_count; index++) {
                if (strcmp(roots[index].alias, parsed.alias) != 0) continue;
                if (present[index]
                    || strcmp(roots[index].project_root_hex, parsed.project_root_hex) != 0
                    || strcmp(roots[index].certificate_root_hex,
                        parsed.certificate_root_hex) != 0
                    || strcmp(roots[index].project_object, parsed.project_object) != 0
                    || strcmp(roots[index].certificate_object,
                        parsed.certificate_object) != 0
                    || strcmp(attestations[index], parsed.attestation) != 0) goto cleanup;
                present[index] = 1;
                break;
            }
            if (index == root_count) goto cleanup;
        } else if (strcmp(fields[0], "C") == 0 && count == 7U
            && strcmp(fields[1], new_project) == 0) goto cleanup;
        else if (strcmp(fields[0], "T") == 0 && count == 11U
            && strcmp(fields[2], new_project) == 0
            && strcmp(fields[3], rotation_id) != 0) goto cleanup;
        cursor = newline + 1U;
    }
    result = 0;
cleanup:
    SecureZeroMemory(copy, length);
    HeapFree(GetProcessHeap(), 0U, copy);
    return result;
}

static int wls_win_auth_transfer_commit_v2(
    const wchar_t *home,
    const wchar_t *channel,
    const char *peer_sid,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    const char *assigned_text,
    const char *controller_anchor,
    char *reply,
    size_t reply_capacity
) {
    struct wls_win_auth_root_v2 *roots = NULL;
    struct wls_win_auth_transfer_v2 transfer;
    struct wls_win_security_summary summary;
    char (*attestations)[65] = NULL;
    int *present = NULL;
    unsigned long long assigned = 0ULL, old_assigned = 0ULL;
    size_t root_count = 0U, length = 0U, index;
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char old_tx[33], old_intent[65], old_digest[65];
    char roots_digest[65], registry_digest[65], tombstone[65];
    char tombstone_canonical[1536];
    char *contents = NULL, *copy = NULL;
    int result = 1;
    if (!wls_is_uuid(old_project) || !wls_is_uuid(new_project)
        || strcmp(old_project, new_project) == 0
        || !wls_is_hex(rotation_id, 32U) || !wls_is_hex(intent, 64U)
        || wls_parse_generation(assigned_text, &assigned) != 0
        || !wls_is_hex(controller_anchor, 64U)
        || wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    roots = (struct wls_win_auth_root_v2 *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        sizeof(*roots) * WLS_WIN_MAX_AUTH_ROOTS
    );
    attestations = (char (*)[65])HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        sizeof(*attestations) * WLS_WIN_MAX_AUTH_ROOTS
    );
    present = (int *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        sizeof(*present) * WLS_WIN_MAX_AUTH_ROOTS
    );
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (roots == NULL || attestations == NULL || present == NULL || copy == NULL) goto cleanup;
    memcpy(copy, contents, length + 1U);
    if (wls_win_auth_transfer_scan(
            copy, old_project, new_project, rotation_id, intent, &transfer
        ) != 0
        || !transfer.prepared || transfer.aborted
        || transfer.assigned != assigned
        || !wls_win_auth_transfer_authorized(
            channel, peer_sid, transfer.owner
        )) goto cleanup;
    SecureZeroMemory(copy, length); HeapFree(GetProcessHeap(), 0U, copy); copy = NULL;
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (copy == NULL) goto cleanup;
    memcpy(copy, contents, length + 1U);
    if (wls_win_security_summary(
            copy, rotation_id, intent, "AUTH_TRANSFER", &summary
        ) != 0
        || !summary.reservation_found
        || summary.assigned != assigned) goto cleanup;
    SecureZeroMemory(copy, length); HeapFree(GetProcessHeap(), 0U, copy); copy = NULL;
    if (transfer.committed) {
        if (!summary.committed_found || summary.aborted_found
            || strcmp(summary.transaction_anchor,
                transfer.tombstone_digest) != 0
            || strcmp(transfer.previous_anchor,
                controller_anchor) != 0) goto cleanup;
    } else {
        if (summary.committed_found || summary.aborted_found
            || strcmp(summary.anchor, controller_anchor) != 0) goto cleanup;
        if (wls_win_auth_latest_project(
                contents, length, old_project, roots, &root_count,
                old_tx, old_intent, &old_assigned, old_digest
            ) != 0
            || root_count != (size_t)transfer.root_count
            || wls_win_auth_transfer_roots(
                roots, root_count, old_project, old_tx, old_intent,
                old_assigned, old_digest, new_project, rotation_id, intent,
                assigned, transfer.owner, attestations, roots_digest
            ) != 0
            || strcmp(roots_digest, transfer.roots_digest) != 0
            || wls_win_auth_transfer_existing_roots(
                contents, length, new_project, rotation_id, intent,
                assigned, transfer.owner, roots, root_count,
                attestations, present
            ) != 0) goto cleanup;
        for (index = 0U; index < root_count; index++) {
            char *record;
            size_t capacity;
            if (present[index]) continue;
            capacity = strlen(roots[index].project_root_hex)
                + strlen(roots[index].certificate_root_hex)
                + strlen(transfer.owner) + 1024U;
            record = (char *)HeapAlloc(
                GetProcessHeap(), HEAP_ZERO_MEMORY, capacity
            );
            if (record == NULL || _snprintf_s(
                record, capacity, _TRUNCATE,
                "P\t%s\t%s\t%s\t%llu\t%s\t%s\t%s\t%s\t%llu\t%s\t%s\t%s\n",
                new_project, rotation_id, intent, assigned, transfer.owner,
                roots[index].alias, roots[index].project_root_hex,
                roots[index].certificate_root_hex,
                (unsigned long long)root_count, roots[index].project_object,
                roots[index].certificate_object, attestations[index]
            ) < 0 || wls_win_security_append_locked(file, record) != 0) {
                if (record != NULL) { SecureZeroMemory(record, capacity); HeapFree(GetProcessHeap(), 0U, record); }
                goto cleanup;
            }
            SecureZeroMemory(record, capacity); HeapFree(GetProcessHeap(), 0U, record);
        }
        if (_snprintf_s(
            tombstone_canonical, sizeof(tombstone_canonical), _TRUNCATE,
            "%s\n%s\n%s\n%s\n%llu\n%s\n%llu\n%s\n%s\n",
            old_project, new_project, rotation_id, intent, assigned,
            transfer.owner, (unsigned long long)root_count, roots_digest,
            controller_anchor
        ) < 0) goto cleanup;
        wls_win_sha256_hex(
            (const unsigned char *)tombstone_canonical,
            strlen(tombstone_canonical), tombstone
        );
        {
            char record[1536];
            if (_snprintf_s(
                record, sizeof(record), _TRUNCATE,
                "T\t%s\t%s\t%s\t%s\t%llu\t%s\t%llu\t%s\t%s\t%s\n",
                old_project, new_project, rotation_id, intent, assigned,
                transfer.owner, (unsigned long long)root_count, roots_digest,
                controller_anchor, tombstone
            ) < 0 || wls_win_security_append_locked(file, record) != 0) goto cleanup;
        }
        memcpy(transfer.tombstone_digest, tombstone, 65U);
    }
    SecureZeroMemory(contents, length); HeapFree(GetProcessHeap(), 0U, contents);
    contents = NULL;
    if (wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    wls_win_sha256_hex((const unsigned char *)contents, length, registry_digest);
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tAUTH_TRANSFER_COMMIT\t%s\t%s\t%llu\t%s\t%s\n",
        rotation_id, intent, assigned, registry_digest,
        transfer.tombstone_digest
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (result != 0) (void)wls_win_action_error(
        reply, reply_capacity, "TRANSFER_CONFLICT",
        "AUTH_TRANSFER_COMMIT", rotation_id, intent
    );
    if (copy != NULL) { SecureZeroMemory(copy, length); HeapFree(GetProcessHeap(), 0U, copy); }
    if (contents != NULL) { SecureZeroMemory(contents, length); HeapFree(GetProcessHeap(), 0U, contents); }
    if (present != NULL) { SecureZeroMemory(present, sizeof(*present) * WLS_WIN_MAX_AUTH_ROOTS); HeapFree(GetProcessHeap(), 0U, present); }
    if (attestations != NULL) { SecureZeroMemory(attestations, sizeof(*attestations) * WLS_WIN_MAX_AUTH_ROOTS); HeapFree(GetProcessHeap(), 0U, attestations); }
    if (roots != NULL) { SecureZeroMemory(roots, sizeof(*roots) * WLS_WIN_MAX_AUTH_ROOTS); HeapFree(GetProcessHeap(), 0U, roots); }
    if (file != INVALID_HANDLE_VALUE) { (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock); CloseHandle(file); }
    return result;
}

static int wls_win_auth_transfer_abort_v2(
    const wchar_t *home,
    const wchar_t *channel,
    const char *peer_sid,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    const char *assigned_text,
    char *reply,
    size_t reply_capacity
) {
    struct wls_win_auth_transfer_v2 transfer;
    struct wls_win_security_summary summary;
    unsigned long long assigned = 0ULL;
    size_t length = 0U, copy_length = 0U;
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char *contents = NULL, *copy = NULL, *summary_copy = NULL, *cursor;
    char record[768], digest[65], zero_digest[65];
    int commit_started = 0, result = 1;
    memset(zero_digest, '0', 64U); zero_digest[64] = '\0';
    if (!wls_is_uuid(old_project) || !wls_is_uuid(new_project)
        || strcmp(old_project, new_project) == 0
        || !wls_is_hex(rotation_id, 32U) || !wls_is_hex(intent, 64U)
        || wls_parse_u64_zero(assigned_text, &assigned) != 0
        || wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    copy_length = length;
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (copy == NULL) goto cleanup;
    memcpy(copy, contents, length + 1U);
    if (wls_win_auth_transfer_scan(
            copy, old_project, new_project, rotation_id, intent, &transfer
        ) != 0
        || transfer.committed
        || (transfer.prepared && assigned != 0ULL
            && transfer.assigned != assigned)
        || (transfer.prepared && !wls_win_auth_transfer_authorized(
            channel, peer_sid, transfer.owner
        ))) goto cleanup;
    summary_copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U
    );
    if (summary_copy == NULL) goto cleanup;
    memcpy(summary_copy, contents, length + 1U);
    if (wls_win_security_summary(
            summary_copy, rotation_id, intent, "AUTH_TRANSFER", &summary
        ) != 0
        || (!transfer.prepared
            && (summary.committed_found || summary.aborted_found))
        || (transfer.prepared
            && (!summary.reservation_found
                || summary.assigned != transfer.assigned
                || summary.committed_found
                || summary.aborted_found != transfer.aborted))) goto cleanup;
    SecureZeroMemory(summary_copy, length);
    HeapFree(GetProcessHeap(), 0U, summary_copy);
    summary_copy = NULL;
    if (!transfer.prepared) {
        if (_snprintf_s(
            reply, reply_capacity, _TRUNCATE,
            "WLS-ACTION/2\tOK\tAUTH_TRANSFER_ABORT\t%s\t%s\t0\t%s\n",
            rotation_id, intent, zero_digest
        ) < 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    assigned = transfer.assigned;
    SecureZeroMemory(copy, copy_length); HeapFree(GetProcessHeap(), 0U, copy);
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (copy == NULL) goto cleanup;
    memcpy(copy, contents, length + 1U);
    cursor = copy;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16]; size_t count = 0U;
        if (newline == NULL) goto cleanup;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto cleanup;
        if (count == 13U && strcmp(fields[0], "P") == 0
            && strcmp(fields[1], new_project) == 0
            && strcmp(fields[2], rotation_id) == 0) commit_started = 1;
        cursor = newline + 1U;
    }
    if (commit_started) goto cleanup;
    if (!transfer.aborted
        && (_snprintf_s(
            record, sizeof(record), _TRUNCATE,
            "Y\t%s\t%s\t%s\t%s\t%llu\n",
            old_project, new_project, rotation_id, intent, assigned
        ) < 0 || wls_win_security_append_locked(file, record) != 0)) goto cleanup;
    SecureZeroMemory(contents, length); HeapFree(GetProcessHeap(), 0U, contents);
    contents = NULL;
    if (wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    wls_win_sha256_hex((const unsigned char *)contents, length, digest);
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tAUTH_TRANSFER_ABORT\t%s\t%s\t%llu\t%s\n",
        rotation_id, intent, assigned, digest
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (result != 0) (void)wls_win_action_error(
        reply, reply_capacity, "TRANSFER_CONFLICT",
        "AUTH_TRANSFER_ABORT", rotation_id, intent
    );
    if (summary_copy != NULL) { SecureZeroMemory(summary_copy, length); HeapFree(GetProcessHeap(), 0U, summary_copy); }
    if (copy != NULL) { SecureZeroMemory(copy, copy_length); HeapFree(GetProcessHeap(), 0U, copy); }
    if (contents != NULL) { SecureZeroMemory(contents, length); HeapFree(GetProcessHeap(), 0U, contents); }
    if (file != INVALID_HANDLE_VALUE) { (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock); CloseHandle(file); }
    return result;
}

static int wls_win_auth_transfer_attest_v2(
    const wchar_t *home,
    const wchar_t *channel,
    const char *peer_sid,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    const char *assigned_text,
    char *reply,
    size_t reply_capacity
) {
    struct wls_win_auth_transfer_v2 transfer;
    struct wls_win_security_summary summary;
    unsigned long long assigned = 0ULL;
    size_t length = 0U;
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    char *contents = NULL, *copy = NULL;
    char digest[65], zero_digest[65];
    const char *phase;
    int result = 1;
    memset(zero_digest, '0', 64U); zero_digest[64] = '\0';
    if (!wls_is_uuid(old_project) || !wls_is_uuid(new_project)
        || strcmp(old_project, new_project) == 0
        || !wls_is_hex(rotation_id, 32U) || !wls_is_hex(intent, 64U)
        || wls_parse_u64_zero(assigned_text, &assigned) != 0
        || wls_win_security_open_locked(home, &file, &lock, path) != 0
        || wls_win_security_read_locked(file, &contents, &length) != 0) goto cleanup;
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (copy == NULL) goto cleanup;
    memcpy(copy, contents, length + 1U);
    if (wls_win_auth_transfer_scan(
            copy, old_project, new_project, rotation_id, intent, &transfer
        ) != 0) goto cleanup;
    SecureZeroMemory(copy, length); HeapFree(GetProcessHeap(), 0U, copy);
    copy = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, length + 1U);
    if (copy == NULL) goto cleanup;
    memcpy(copy, contents, length + 1U);
    if (wls_win_security_summary(
            copy, rotation_id, intent, "AUTH_TRANSFER", &summary
        ) != 0
        || (!transfer.prepared
            && (summary.committed_found || summary.aborted_found))
        || (transfer.prepared
            && (!summary.reservation_found
                || summary.assigned != transfer.assigned
                || summary.committed_found != transfer.committed
                || summary.aborted_found != transfer.aborted
                || (transfer.committed
                    && strcmp(summary.transaction_anchor,
                        transfer.tombstone_digest) != 0)))) goto cleanup;
    if (!transfer.prepared) {
        if (_snprintf_s(
            reply, reply_capacity, _TRUNCATE,
            "WLS-ACTION/2\tOK\tAUTH_TRANSFER_ATTEST\t%s\t%s\tUNKNOWN\t0\t%s\t%s\n",
            rotation_id, intent, zero_digest, zero_digest
        ) < 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if ((assigned != 0ULL && transfer.assigned != assigned)
        || !wls_win_auth_transfer_authorized(
            channel, peer_sid, transfer.owner
        )) goto cleanup;
    assigned = transfer.assigned;
    phase = transfer.committed
        ? "COMMITTED" : (transfer.aborted ? "ABORTED" : "PREPARED");
    wls_win_sha256_hex((const unsigned char *)contents, length, digest);
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tAUTH_TRANSFER_ATTEST\t%s\t%s\t%s\t%llu\t%s\t%s\n",
        rotation_id, intent, phase, assigned, digest,
        transfer.committed ? transfer.tombstone_digest : zero_digest
    ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (result != 0) (void)wls_win_action_error(
        reply, reply_capacity, "NOT_COMMITTED",
        "AUTH_TRANSFER_ATTEST", rotation_id, intent
    );
    if (copy != NULL) { SecureZeroMemory(copy, length); HeapFree(GetProcessHeap(), 0U, copy); }
    if (contents != NULL) { SecureZeroMemory(contents, length); HeapFree(GetProcessHeap(), 0U, contents); }
    if (file != INVALID_HANDLE_VALUE) { (void)UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock); CloseHandle(file); }
    return result;
}

static int wls_win_snapshot_v2(
    const wchar_t *home,
    HANDLE client_pipe,
    const char *peer_sid,
    const char *project,
    const char *tx,
    const char *intent,
    const char *alias,
    const char *source_relative_hex,
    const char *digest,
    const char *leaf,
    char *reply,
    size_t reply_capacity
) {
    struct wls_win_auth_root_v2 root;
    wchar_t source_root[WLS_PATH_CHARS];
    wchar_t source_relative[WLS_PATH_CHARS];
    wchar_t destination_root[WLS_PATH_CHARS];
    wchar_t destination_relative[WLS_PATH_CHARS];
    if (!wls_is_hex(digest, 64U)
        || (strcmp(leaf, "source-cert.pem") != 0
            && strcmp(leaf, "source-key.pem") != 0
            && strcmp(leaf, "source-chain.pem") != 0)
        || wls_win_auth_load_root(
            home, project, tx, intent, alias, &root
        ) != 0
        || _stricmp(root.owner, peer_sid) != 0
        || wls_hex_to_wide(
            root.certificate_root_hex, source_root, WLS_PATH_CHARS
        ) != 0
        || wls_hex_to_wide(
            source_relative_hex, source_relative, WLS_PATH_CHARS
        ) != 0
        || !wls_safe_relative(source_relative)
        || wls_join_w(destination_root, WLS_PATH_CHARS, home, L"snapshots") != 0
        || _snwprintf_s(
            destination_relative, WLS_PATH_CHARS, _TRUNCATE,
            L"%hs\\%hs", digest, leaf
        ) < 0
        || wls_snapshot(
            source_root, source_relative, destination_root,
            destination_relative, root.owner,
            strcmp(leaf, "source-key.pem") == 0,
            client_pipe, peer_sid
        ) != 0) return wls_win_action_error(
            reply, reply_capacity, "SNAPSHOT_FAILED", "SNAP", tx, intent
        );
    return _snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tSNAP\t%s\t%s\t%s\t%s\t%s\n",
        tx, intent, alias, digest, leaf
    ) > 0 ? 0 : 1;
}

static int wls_win_atomic_target_allowed(const wchar_t *relative)
{
    static const wchar_t *allowed[] = {
        L"runtime\\conf\\nginx.conf",
        L"run\\controller.pid",
        L"state\\control-endpoint.json",
        L"state\\disk-pressure.marker",
        L"state\\gateway-state.json",
        L"state\\journal.jsonl",
        L"state\\nonce.wal",
        L"state\\publication-current.json",
        L"state\\route-lkg.json",
        L"state\\security-ledger.json",
        L"trust\\active-slot",
        L"trust\\journal.untrusted",
        L"trust\\previous-slot",
        L"trust\\security-anchor.json",
        L"trust\\upgrade-state",
        L"trust\\slot-retention",
        L"trust\\nginx-process.identity",
        L"trust\\broker-launch.receipt",
        L"trust\\wls-edge-2.initialized.json"
    };
    size_t index;
    for (index = 0U; index < sizeof(allowed) / sizeof(allowed[0]); index++) {
        if (_wcsicmp(relative, allowed[index]) == 0) return 1;
    }
    return 0;
}

static int wls_win_atomic_temporary_leaf_allowed(
    const wchar_t *temporary_leaf,
    const wchar_t *target_leaf
)
{
    size_t target_length;
    const wchar_t *nonce;
    size_t index;
    if (temporary_leaf == NULL || target_leaf == NULL) return 0;
    target_length = wcslen(target_leaf);
    if (target_length == 0U
        || _wcsnicmp(temporary_leaf, target_leaf, target_length) != 0
        || wcsncmp(temporary_leaf + target_length, L".tmp-", 5U) != 0) {
        return 0;
    }
    nonce = temporary_leaf + target_length + 5U;
    if (wcslen(nonce) != 12U) return 0;
    for (index = 0U; index < 12U; index++) {
        if (!((nonce[index] >= L'0' && nonce[index] <= L'9')
                || (nonce[index] >= L'a' && nonce[index] <= L'f'))) {
            return 0;
        }
    }
    return 1;
}

static int wls_win_read_digest(
    HANDLE file,
    char digest_hex[65],
    unsigned long long *size,
    BY_HANDLE_FILE_INFORMATION *identity
) {
    FILE_STANDARD_INFO standard;
    LARGE_INTEGER beginning;
    char *contents = NULL;
    unsigned char digest[32];
    beginning.QuadPart = 0;
    if (!GetFileInformationByHandle(file, identity)
        || !GetFileInformationByHandleEx(
            file, FileStandardInfo, &standard, sizeof(standard)
        )
        || standard.Directory || standard.NumberOfLinks != 1U
        || standard.EndOfFile.QuadPart < 0
        || standard.EndOfFile.QuadPart > (LONGLONG)WLS_MAX_REQUEST
        || !SetFilePointerEx(file, beginning, NULL, FILE_BEGIN)) return 1;
    contents = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        (size_t)standard.EndOfFile.QuadPart + 1U
    );
    if (contents == NULL
        || (standard.EndOfFile.QuadPart > 0
            && wls_read_exact(
                file, contents, (DWORD)standard.EndOfFile.QuadPart
            ) != 0)
        || wls_sha256(
            (const unsigned char *)contents,
            (DWORD)standard.EndOfFile.QuadPart,
            digest
        ) != 0) {
        if (contents != NULL) {
            SecureZeroMemory(contents, (size_t)standard.EndOfFile.QuadPart + 1U);
            HeapFree(GetProcessHeap(), 0U, contents);
        }
        return 1;
    }
    wls_hex_encode_32(digest, digest_hex);
    *size = (unsigned long long)standard.EndOfFile.QuadPart;
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(contents, (size_t)standard.EndOfFile.QuadPart + 1U);
    HeapFree(GetProcessHeap(), 0U, contents);
    return 0;
}

static int wls_win_atomic_replace_v2(
    const wchar_t *home,
    const char *temporary_hex,
    const char *target_hex,
    const char *expected_digest,
    const char *expected_size_text,
    const char *mode_text,
    char *reply,
    size_t reply_capacity
) {
    wchar_t temporary[WLS_PATH_CHARS];
    wchar_t target[WLS_PATH_CHARS];
    wchar_t temporary_parent[WLS_PATH_CHARS];
    wchar_t target_parent[WLS_PATH_CHARS];
    wchar_t backup[WLS_PATH_CHARS];
    wchar_t *temporary_leaf;
    wchar_t *target_leaf;
    size_t home_length = wcslen(home);
    unsigned long long expected_size;
    unsigned long long actual_size = 0ULL;
    unsigned char nonce[8];
    char actual_digest[65];
    char owner_sid[256];
    HANDLE parent = INVALID_HANDLE_VALUE;
    HANDLE temporary_file = INVALID_HANDLE_VALUE;
    HANDLE verified = INVALID_HANDLE_VALUE;
    BY_HANDLE_FILE_INFORMATION temporary_identity;
    BY_HANDLE_FILE_INFORMATION verified_identity;
    int target_existed = 0;
    int replaced = 0;
    if (!wls_is_hex(expected_digest, 64U)
        || wls_parse_u64_zero(expected_size_text, &expected_size) != 0
        || expected_size > WLS_MAX_REQUEST
        || (strcmp(mode_text, "0600") != 0
            && strcmp(mode_text, "0640") != 0
            && strcmp(mode_text, "0644") != 0)
        || wls_hex_to_wide(temporary_hex, temporary, WLS_PATH_CHARS) != 0
        || wls_hex_to_wide(target_hex, target, WLS_PATH_CHARS) != 0
        || _wcsnicmp(temporary, home, home_length) != 0
        || _wcsnicmp(target, home, home_length) != 0
        || (temporary[home_length] != L'\\' && temporary[home_length] != L'/')
        || (target[home_length] != L'\\' && target[home_length] != L'/')
        || !wls_win_atomic_target_allowed(target + home_length + 1U)) goto denied;
    wcscpy_s(temporary_parent, WLS_PATH_CHARS, temporary);
    wcscpy_s(target_parent, WLS_PATH_CHARS, target);
    temporary_leaf = wcsrchr(temporary_parent, L'\\');
    target_leaf = wcsrchr(target_parent, L'\\');
    if (temporary_leaf == NULL || target_leaf == NULL) goto denied;
    *temporary_leaf++ = L'\0';
    *target_leaf++ = L'\0';
    if (_wcsicmp(temporary_parent, target_parent) != 0
        || !wls_win_atomic_temporary_leaf_allowed(
            temporary_leaf, target_leaf
        )) goto denied;
    parent = wls_open_root(
        target_parent, FILE_LIST_DIRECTORY | FILE_TRAVERSE | SYNCHRONIZE
    );
    temporary_file = CreateFileW(
        temporary,
        GENERIC_READ | READ_CONTROL | SYNCHRONIZE,
        FILE_SHARE_READ | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (parent == INVALID_HANDLE_VALUE || temporary_file == INVALID_HANDLE_VALUE
        || wls_handle_is_reparse(temporary_file)
        || wls_owner_sid(temporary_file, owner_sid, sizeof(owner_sid)) != 0
        || (_stricmp(owner_sid, "S-1-5-18") != 0
            && _strnicmp(owner_sid, "S-1-5-80-", 9U) != 0)
        || wls_win_read_digest(
            temporary_file, actual_digest, &actual_size, &temporary_identity
        ) != 0
        || actual_size != expected_size
        || strcmp(actual_digest, expected_digest) != 0) goto denied;
    target_existed = GetFileAttributesW(target) != INVALID_FILE_ATTRIBUTES;
    if (BCryptGenRandom(
            NULL, nonce, sizeof(nonce), BCRYPT_USE_SYSTEM_PREFERRED_RNG
        ) != 0
        || _snwprintf_s(
            backup, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls.wls-backup-%02x%02x%02x%02x%02x%02x%02x%02x",
            target, nonce[0], nonce[1], nonce[2], nonce[3],
            nonce[4], nonce[5], nonce[6], nonce[7]
        ) < 0) goto denied;
    CloseHandle(temporary_file);
    temporary_file = INVALID_HANDLE_VALUE;
    if (target_existed) {
        if (!ReplaceFileW(
            target, temporary, backup,
            REPLACEFILE_WRITE_THROUGH | REPLACEFILE_IGNORE_MERGE_ERRORS,
            NULL, NULL
        )) goto denied;
    } else if (!MoveFileExW(
        temporary, target, MOVEFILE_WRITE_THROUGH
    )) goto denied;
    replaced = 1;
    verified = CreateFileW(
        target, GENERIC_READ | SYNCHRONIZE,
        FILE_SHARE_READ | FILE_SHARE_DELETE, NULL, OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT, NULL
    );
    if (verified == INVALID_HANDLE_VALUE || wls_handle_is_reparse(verified)
        || wls_win_read_digest(
            verified, actual_digest, &actual_size, &verified_identity
        ) != 0
        || verified_identity.dwVolumeSerialNumber
            != temporary_identity.dwVolumeSerialNumber
        || verified_identity.nFileIndexHigh != temporary_identity.nFileIndexHigh
        || verified_identity.nFileIndexLow != temporary_identity.nFileIndexLow
        || strcmp(actual_digest, expected_digest) != 0) {
        if (verified != INVALID_HANDLE_VALUE) CloseHandle(verified);
        verified = INVALID_HANDLE_VALUE;
        if (target_existed) {
            (void)ReplaceFileW(
                target, backup, NULL, REPLACEFILE_WRITE_THROUGH, NULL, NULL
            );
        } else {
            (void)MoveFileExW(target, temporary, MOVEFILE_WRITE_THROUGH);
        }
        replaced = 0;
        goto denied;
    }
    CloseHandle(verified); verified = INVALID_HANDLE_VALUE;
    if (target_existed) (void)DeleteFileW(backup);
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tATOMIC_REPLACE\t-\t-\t%s\t%llu\t%s\n",
        expected_digest, expected_size, mode_text
    ) < 0) goto denied;
    CloseHandle(parent);
    return 0;
denied:
    if (verified != INVALID_HANDLE_VALUE) CloseHandle(verified);
    if (temporary_file != INVALID_HANDLE_VALUE) CloseHandle(temporary_file);
    if (replaced && target_existed) {
        (void)ReplaceFileW(target, backup, NULL, REPLACEFILE_WRITE_THROUGH, NULL, NULL);
    }
    if (parent != INVALID_HANDLE_VALUE) CloseHandle(parent);
    return wls_win_action_error(
        reply, reply_capacity, "ATOMIC_REPLACE_FAILED", "ATOMIC_REPLACE", NULL, NULL
    );
}

static int wls_win_read_path_contents(
    const wchar_t *path,
    char **contents,
    size_t *length
) {
    HANDLE file = INVALID_HANDLE_VALUE;
    FILE_STANDARD_INFO info;
    char *buffer = NULL;
    file = CreateFileW(
        path, GENERIC_READ | SYNCHRONIZE, FILE_SHARE_READ,
        NULL, OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT, NULL
    );
    if (file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(file)
        || !GetFileInformationByHandleEx(
            file, FileStandardInfo, &info, sizeof(info)
        )
        || info.Directory || info.NumberOfLinks != 1U
        || info.EndOfFile.QuadPart < 0
        || info.EndOfFile.QuadPart > (LONGLONG)WLS_MAX_REQUEST) goto cleanup;
    buffer = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        (size_t)info.EndOfFile.QuadPart + 1U
    );
    if (buffer == NULL
        || (info.EndOfFile.QuadPart > 0
            && wls_read_exact(
                file, buffer, (DWORD)info.EndOfFile.QuadPart
            ) != 0)) goto cleanup;
    *contents = buffer;
    *length = (size_t)info.EndOfFile.QuadPart;
    CloseHandle(file);
    return 0;
cleanup:
    if (buffer != NULL) HeapFree(GetProcessHeap(), 0U, buffer);
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return 1;
}

static int wls_win_json_generation(const char *json, unsigned long long *value)
{
    return wls_json_unsigned_long_long(
        json, "active_config_generation", value
    );
}

typedef NTSTATUS (NTAPI *wls_nt_query_information_process_fn)(
    HANDLE,
    ULONG,
    PVOID,
    ULONG,
    PULONG
);

static int wls_win_process_command_matches(
    HANDLE process,
    const wchar_t *binary,
    const wchar_t *prefix,
    const wchar_t *config
) {
    HMODULE ntdll = GetModuleHandleW(L"ntdll.dll");
    wls_nt_query_information_process_fn query;
    BYTE *buffer = NULL;
    ULONG required = 0U;
    UNICODE_STRING *line;
    wchar_t **arguments = NULL;
    int count = 0;
    int result = 1;
    if (process == NULL || binary == NULL || prefix == NULL || config == NULL
        || ntdll == NULL) return 1;
    query = (wls_nt_query_information_process_fn)GetProcAddress(
        ntdll, "NtQueryInformationProcess"
    );
    if (query == NULL) return 1;
    (void)query(process, 60U, NULL, 0U, &required);
    if (required < sizeof(UNICODE_STRING)
        || required > WLS_MAX_SNAPSHOT) return 1;
    buffer = (BYTE *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, (SIZE_T)required + sizeof(wchar_t)
    );
    if (buffer == NULL
        || query(process, 60U, buffer, required, &required) < 0) goto cleanup;
    line = (UNICODE_STRING *)buffer;
    if (line->Buffer == NULL || line->Length == 0U
        || (line->Length % sizeof(wchar_t)) != 0U
        || (BYTE *)line->Buffer < buffer
        || (BYTE *)line->Buffer + line->Length + sizeof(wchar_t)
            > buffer + required + sizeof(wchar_t)) goto cleanup;
    line->Buffer[line->Length / sizeof(wchar_t)] = L'\0';
    arguments = CommandLineToArgvW(line->Buffer, &count);
    if (arguments != NULL && count == 5
        && _wcsicmp(arguments[0], binary) == 0
        && wcscmp(arguments[1], L"-p") == 0
        && _wcsicmp(arguments[2], prefix) == 0
        && wcscmp(arguments[3], L"-c") == 0
        && _wcsicmp(arguments[4], config) == 0) result = 0;
cleanup:
    if (arguments != NULL) LocalFree(arguments);
    if (buffer != NULL) {
        SecureZeroMemory(buffer, (SIZE_T)required + sizeof(wchar_t));
        HeapFree(GetProcessHeap(), 0U, buffer);
    }
    return result;
}

static int wls_same_file_identity(
    const BY_HANDLE_FILE_INFORMATION *left,
    const BY_HANDLE_FILE_INFORMATION *right
) {
    return left->dwVolumeSerialNumber == right->dwVolumeSerialNumber
        && left->nFileIndexHigh == right->nFileIndexHigh
        && left->nFileIndexLow == right->nFileIndexLow
        && left->nFileSizeHigh == right->nFileSizeHigh
        && left->nFileSizeLow == right->nFileSizeLow
        && CompareFileTime(&left->ftLastWriteTime, &right->ftLastWriteTime) == 0;
}

static int wls_win_process_attest_v2(
    const wchar_t *home,
    const char *pid_text,
    const char *start_text,
    const char *expected_binary_digest,
    const char *runtime_generation,
    const char *expected_config_digest,
    const char *expected_config_path_digest,
    const char *publication_text,
    char *reply,
    size_t reply_capacity
) {
    unsigned long long parsed_pid64;
    unsigned long long expected_start;
    int expected_start_known;
    unsigned long long expected_publication;
    unsigned long long actual_publication = 0ULL;
    DWORD pid;
    HANDLE process = NULL;
    HANDLE binary = INVALID_HANDLE_VALUE;
    HANDLE config = INVALID_HANDLE_VALUE;
    HANDLE verified_config = INVALID_HANDLE_VALUE;
    FILETIME created, exited, kernel, user;
    FILETIME verified_created, verified_exited, verified_kernel, verified_user;
    wchar_t binary_path[WLS_PATH_CHARS];
    wchar_t verified_binary_path[WLS_PATH_CHARS];
    wchar_t expected_binary_a[WLS_PATH_CHARS];
    wchar_t expected_binary_b[WLS_PATH_CHARS];
    wchar_t expected_prefix[WLS_PATH_CHARS];
    DWORD binary_path_length = WLS_PATH_CHARS;
    DWORD verified_binary_path_length = WLS_PATH_CHARS;
    wchar_t config_path[WLS_PATH_CHARS];
    wchar_t state_path[WLS_PATH_CHARS];
    wchar_t manifest_path[WLS_PATH_CHARS];
    wchar_t receipt_path[WLS_PATH_CHARS];
    char config_path_utf8[WLS_PATH_CHARS * 3U];
    char binary_digest[65];
    char config_digest[65];
    char state_config_digest[65];
    char manifest_runtime_generation[65];
    char config_path_digest[65];
    char receipt_digest[65];
    char receipt[2048];
    char *state = NULL;
    char *manifest = NULL;
    size_t state_length = 0U;
    size_t manifest_length = 0U;
    unsigned long long binary_size = 0ULL;
    unsigned long long config_size = 0ULL;
    BY_HANDLE_FILE_INFORMATION binary_identity;
    BY_HANDLE_FILE_INFORMATION binary_after_identity;
    BY_HANDLE_FILE_INFORMATION config_identity;
    BY_HANDLE_FILE_INFORMATION verified_config_identity;
    BY_HANDLE_FILE_INFORMATION config_after_identity;
    const wchar_t *slot;
    unsigned char digest[32];
    int utf8_length;
    int written;
    expected_start_known = strcmp(start_text, "-") != 0;
    expected_start = 0ULL;
    if (wls_parse_u64_zero(pid_text, &parsed_pid64) != 0
        || parsed_pid64 == 0ULL || parsed_pid64 > MAXDWORD
        || (expected_start_known
            && (wls_parse_u64_zero(start_text, &expected_start) != 0
                || expected_start == 0ULL))
        || wls_parse_u64_zero(publication_text, &expected_publication) != 0
        || !wls_is_hex(expected_binary_digest, 64U)
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(expected_config_digest, 64U)
        || !wls_is_hex(expected_config_path_digest, 64U)) goto denied;
    pid = (DWORD)parsed_pid64;
    process = OpenProcess(
        SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pid
    );
    if (process == NULL
        || !GetProcessTimes(process, &created, &exited, &kernel, &user)
        || (expected_start_known
            && wls_filetime_value(&created) != expected_start)
        || !QueryFullProcessImageNameW(
            process, 0U, binary_path, &binary_path_length
        )
        || _snwprintf_s(
            expected_binary_a, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\A\\bin\\nginx.exe", home
        ) < 0
        || _snwprintf_s(
            expected_binary_b, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\B\\bin\\nginx.exe", home
        ) < 0
        || _snwprintf_s(
            expected_prefix, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\runtime\\", home
        ) < 0
        || wls_join_w(
            config_path, WLS_PATH_CHARS, home, L"runtime\\conf\\nginx.conf"
        ) != 0) goto denied;
    if (_wcsicmp(binary_path, expected_binary_a) == 0) slot = L"A";
    else if (_wcsicmp(binary_path, expected_binary_b) == 0) slot = L"B";
    else goto denied;
    if (wls_win_process_command_matches(
            process, binary_path, expected_prefix, config_path
        ) != 0) goto denied;
    binary = CreateFileW(
        binary_path, GENERIC_READ | SYNCHRONIZE, FILE_SHARE_READ,
        NULL, OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT, NULL
    );
    if (binary == INVALID_HANDLE_VALUE || wls_handle_is_reparse(binary)
        || wls_win_read_digest(
            binary, binary_digest, &binary_size, &binary_identity
        ) != 0
        || binary_size == 0ULL
        || strcmp(binary_digest, expected_binary_digest) != 0) goto denied;
    utf8_length = WideCharToMultiByte(
        CP_UTF8, WC_ERR_INVALID_CHARS, config_path, -1,
        config_path_utf8, sizeof(config_path_utf8), NULL, NULL
    );
    if (utf8_length <= 1
        || wls_sha256(
            (const unsigned char *)config_path_utf8,
            (DWORD)(utf8_length - 1), digest
        ) != 0) goto denied;
    wls_hex_encode_32(digest, config_path_digest);
    SecureZeroMemory(digest, sizeof(digest));
    if (strcmp(config_path_digest, expected_config_path_digest) != 0) goto denied;
    config = CreateFileW(
        config_path, GENERIC_READ | SYNCHRONIZE, FILE_SHARE_READ,
        NULL, OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT, NULL
    );
    if (config == INVALID_HANDLE_VALUE || wls_handle_is_reparse(config)
        || wls_win_read_digest(
            config, config_digest, &config_size, &config_identity
        ) != 0
        || config_size == 0ULL
        || strcmp(config_digest, expected_config_digest) != 0
        || wls_join_w(
            state_path, WLS_PATH_CHARS, home, L"state\\gateway-state.json"
        ) != 0
        || wls_win_read_path_contents(
            state_path, &state, &state_length
        ) != 0
        || wls_win_json_generation(state, &actual_publication) != 0
        || actual_publication != expected_publication
        || wls_json_string(
            state,
            "active_config_digest",
            state_config_digest,
            sizeof(state_config_digest)
        ) != 0
        || !wls_is_hex(state_config_digest, 64U)
        || strcmp(state_config_digest, config_digest) != 0
        || _snwprintf_s(
            manifest_path, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\%ls\\manifest.json", home, slot
        ) < 0
        || wls_win_read_path_contents(
            manifest_path, &manifest, &manifest_length
        ) != 0
        || wls_json_string(
            manifest,
            "runtime_generation",
            manifest_runtime_generation,
            sizeof(manifest_runtime_generation)
        ) != 0
        || !wls_is_hex(manifest_runtime_generation, 64U)
        || strcmp(manifest_runtime_generation, runtime_generation) != 0
        || !GetFileInformationByHandle(config, &config_after_identity)
        || !wls_same_file_identity(&config_identity, &config_after_identity)
        || (verified_config = CreateFileW(
            config_path,
            GENERIC_READ | SYNCHRONIZE,
            FILE_SHARE_READ,
            NULL,
            OPEN_EXISTING,
            FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
            NULL
        )) == INVALID_HANDLE_VALUE
        || wls_handle_is_reparse(verified_config)
        || !GetFileInformationByHandle(
            verified_config, &verified_config_identity
        )
        || !wls_same_file_identity(
            &config_identity, &verified_config_identity
        )
        || !GetFileInformationByHandle(binary, &binary_after_identity)
        || !wls_same_file_identity(
            &binary_identity, &binary_after_identity
        )
        || WaitForSingleObject(process, 0U) != WAIT_TIMEOUT
        || !GetProcessTimes(
            process, &verified_created, &verified_exited,
            &verified_kernel, &verified_user
        )
        || CompareFileTime(&verified_created, &created) != 0
        || !QueryFullProcessImageNameW(
            process, 0U, verified_binary_path, &verified_binary_path_length
        )
        || _wcsicmp(verified_binary_path, binary_path) != 0
        || wls_win_process_command_matches(
            process, binary_path, expected_prefix, config_path
        ) != 0) goto denied;
    written = _snprintf_s(
        receipt, sizeof(receipt), _TRUNCATE,
        "WLS-PROCESS-ATTEST/2\npid=%lu\nstart_id=%llu\n"
        "binary_digest=%s\nruntime_generation=%s\nconfig_digest=%s\n"
        "config_path_digest=%s\npublication_generation=%llu\n",
        (unsigned long)pid, wls_filetime_value(&created), binary_digest, runtime_generation,
        config_digest, config_path_digest, actual_publication
    );
    if (written <= 0
        || wls_join_w(
            receipt_path, WLS_PATH_CHARS, home,
            L"trust\\process-attestation.receipt"
        ) != 0
        || wls_atomic_bytes(receipt_path, receipt, (DWORD)written) != 0
        || wls_sha256(
            (const unsigned char *)receipt, (DWORD)written, digest
        ) != 0) goto denied;
    wls_hex_encode_32(digest, receipt_digest);
    SecureZeroMemory(digest, sizeof(digest));
    if (_snprintf_s(
        reply, reply_capacity, _TRUNCATE,
        "WLS-ACTION/2\tOK\tPROCESS_ATTEST\t%s\t%lu\t%llu\t%s\t%s\t%s\t%s\t%llu\n",
        receipt_digest, (unsigned long)pid, wls_filetime_value(&created), binary_digest,
        runtime_generation, config_digest, config_path_digest, actual_publication
    ) < 0) goto denied;
    if (_wcsicmp(
            binary_path + (binary_path_length > 9U ? binary_path_length - 9U : 0U),
            L"nginx.exe"
        ) == 0
        && wls_write_nginx_process_identity(
            home, pid, &created, slot, runtime_generation
        ) != 0) goto denied;
    if (manifest != NULL) { SecureZeroMemory(manifest, manifest_length); HeapFree(GetProcessHeap(), 0U, manifest); }
    if (state != NULL) { SecureZeroMemory(state, state_length); HeapFree(GetProcessHeap(), 0U, state); }
    if (config != INVALID_HANDLE_VALUE) CloseHandle(config);
    if (verified_config != INVALID_HANDLE_VALUE) CloseHandle(verified_config);
    if (binary != INVALID_HANDLE_VALUE) CloseHandle(binary);
    if (process != NULL) CloseHandle(process);
    return 0;
denied:
    if (manifest != NULL) { SecureZeroMemory(manifest, manifest_length); HeapFree(GetProcessHeap(), 0U, manifest); }
    if (state != NULL) { SecureZeroMemory(state, state_length); HeapFree(GetProcessHeap(), 0U, state); }
    if (config != INVALID_HANDLE_VALUE) CloseHandle(config);
    if (verified_config != INVALID_HANDLE_VALUE) CloseHandle(verified_config);
    if (binary != INVALID_HANDLE_VALUE) CloseHandle(binary);
    if (process != NULL) CloseHandle(process);
    return wls_win_action_error(
        reply, reply_capacity, "PROCESS_ATTEST_FAILED", "PROCESS_ATTEST", NULL, NULL
    );
}

static int wls_handle_action_v2(
    char *line,
    HANDLE client_pipe,
    const wchar_t *channel,
    const char *peer_sid,
    const wchar_t *home,
    char *reply,
    size_t reply_capacity
) {
    char *fields[16];
    size_t count = 0U;
    size_t length;
    char channel_utf8[32];
    if (line == NULL || reply == NULL || reply_capacity < 128U) return 1;
    length = strlen(line);
    if (length < 2U || line[length - 1U] != '\n'
        || memchr(line, '\r', length) != NULL
        || memchr(line, '\n', length - 1U) != NULL) return 1;
    line[length - 1U] = '\0';
    if (wls_split_tsv(line, fields, 16U, &count) != 0
        || count < 2U || strcmp(fields[0], "WLS-ACTION/2") != 0
        || WideCharToMultiByte(
            CP_UTF8, WC_ERR_INVALID_CHARS, channel, -1,
            channel_utf8, sizeof(channel_utf8), NULL, NULL
        ) <= 0) return 1;
    if (strcmp(fields[1], "SNAP") == 0) {
        if (strcmp(channel_utf8, "project") != 0 || count != 9U) {
            return wls_win_action_error(
                reply, reply_capacity, "DENIED", fields[1],
                count > 3U ? fields[3] : NULL,
                count > 4U ? fields[4] : NULL
            );
        }
        return wls_win_snapshot_v2(
            home, client_pipe, peer_sid, fields[2], fields[3], fields[4],
            fields[5], fields[6], fields[7], fields[8], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "SECURITY_ATTEST") == 0
        && (strcmp(channel_utf8, "admin") == 0
            || strcmp(channel_utf8, "project") == 0)
        && count == 6U) {
        return wls_win_security_attest(
            home, fields[2], fields[3], fields[4], fields[5],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "PROCESS_ATTEST") == 0
        && (strcmp(channel_utf8, "admin") == 0
            || strcmp(channel_utf8, "project") == 0)
        && count == 9U) {
        return wls_win_process_attest_v2(
            home, fields[2], fields[3], fields[4], fields[5],
            fields[6], fields[7], fields[8], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_TRANSFER_PREPARE") == 0 && count == 8U) {
        return wls_win_auth_transfer_prepare_v2(
            home, channel, peer_sid, fields[2], fields[3], fields[4], fields[5],
            fields[6], fields[7], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_TRANSFER_COMMIT") == 0 && count == 8U) {
        return wls_win_auth_transfer_commit_v2(
            home, channel, peer_sid, fields[2], fields[3], fields[4], fields[5],
            fields[6], fields[7], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_TRANSFER_ABORT") == 0 && count == 7U) {
        return wls_win_auth_transfer_abort_v2(
            home, channel, peer_sid, fields[2], fields[3], fields[4], fields[5],
            fields[6], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_TRANSFER_ATTEST") == 0 && count == 7U) {
        return wls_win_auth_transfer_attest_v2(
            home, channel, peer_sid, fields[2], fields[3], fields[4], fields[5],
            fields[6], reply, reply_capacity
        );
    }
    if (strcmp(channel_utf8, "admin") != 0) {
        return wls_win_action_error(
            reply, reply_capacity, "DENIED", fields[1],
            count > 2U ? fields[2] : NULL,
            count > 3U ? fields[3] : NULL
        );
    }
    if (strcmp(fields[1], "SECURITY_RESERVE") == 0 && count == 6U) {
        return wls_win_security_operation(
            home, fields[1], fields[2], fields[3], fields[4], fields[5], NULL,
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "SECURITY_COMMIT") == 0 && count == 7U) {
        return wls_win_security_operation(
            home, fields[1], fields[2], fields[3], fields[4], fields[5], fields[6],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "SECURITY_ABORT") == 0 && count == 6U) {
        return wls_win_security_operation(
            home, fields[1], fields[2], fields[3], fields[4], fields[5], NULL,
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_PREPARE") == 0 && count == 11U) {
        return wls_win_auth_prepare_v2(
            home, client_pipe, peer_sid, fields[2], fields[3], fields[4],
            fields[5], fields[6], fields[7], fields[8], fields[9], fields[10],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_COMMIT") == 0 && count == 7U) {
        return wls_win_auth_commit_v2(
            home, fields[2], fields[3], fields[4], fields[5], fields[6],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_ABORT") == 0 && count == 5U) {
        return wls_win_auth_abort_v2(
            home, fields[2], fields[3], fields[4], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "ATTEST_ROOT") == 0 && count == 6U) {
        return wls_win_auth_attest_v2(
            home, fields[2], fields[3], fields[4], fields[5],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "ATOMIC_REPLACE") == 0 && count == 7U) {
        return wls_win_atomic_replace_v2(
            home, fields[2], fields[3], fields[4], fields[5], fields[6],
            reply, reply_capacity
        );
    }
    return wls_win_action_error(
        reply, reply_capacity, "UNSUPPORTED", fields[1],
        count > 2U ? fields[2] : NULL,
        count > 3U ? fields[3] : NULL
    );
}

static const char *wls_json_value(const char *json, const char *name)
{
    char needle[96];
    const char *field;
    if (json == NULL || name == NULL
        || _snprintf_s(needle, sizeof(needle), _TRUNCATE, "\"%s\"", name) <= 0) {
        return NULL;
    }
    field = strstr(json, needle);
    if (field == NULL || strstr(field + strlen(needle), needle) != NULL) return NULL;
    field += strlen(needle);
    while (*field == ' ' || *field == '\t' || *field == '\r' || *field == '\n') field++;
    if (*field++ != ':') return NULL;
    while (*field == ' ' || *field == '\t' || *field == '\r' || *field == '\n') field++;
    return field;
}

static int wls_json_string(
    const char *json,
    const char *name,
    char *output,
    size_t capacity
) {
    const char *cursor = wls_json_value(json, name);
    size_t used = 0U;
    if (cursor == NULL || output == NULL || capacity < 2U || *cursor++ != '"') return 1;
    while (*cursor != '\0' && *cursor != '"') {
        unsigned char value = (unsigned char)*cursor++;
        if (value < 0x20U || value == '\\' || used + 1U >= capacity) return 1;
        output[used++] = (char)value;
    }
    if (*cursor != '"') return 1;
    output[used] = '\0';
    return 0;
}

static int wls_json_boolean(const char *json, const char *name, int *value)
{
    const char *cursor = wls_json_value(json, name);
    if (cursor == NULL || value == NULL) return 1;
    if (strncmp(cursor, "true", 4U) == 0
        && (cursor[4] == ',' || cursor[4] == '}'
            || cursor[4] == ' ' || cursor[4] == '\t'
            || cursor[4] == '\r' || cursor[4] == '\n')) {
        *value = 1;
        return 0;
    }
    if (strncmp(cursor, "false", 5U) == 0
        && (cursor[5] == ',' || cursor[5] == '}'
            || cursor[5] == ' ' || cursor[5] == '\t'
            || cursor[5] == '\r' || cursor[5] == '\n')) {
        *value = 0;
        return 0;
    }
    return 1;
}

static int wls_json_unsigned_long_long(
    const char *json,
    const char *name,
    unsigned long long *value
) {
    const char *cursor = wls_json_value(json, name);
    char *end = NULL;
    if (cursor == NULL || value == NULL || *cursor < '0' || *cursor > '9') return 1;
    errno = 0;
    *value = _strtoui64(cursor, &end, 10);
    if (errno != 0 || end == cursor) return 1;
    while (*end == ' ' || *end == '\t' || *end == '\r' || *end == '\n') end++;
    return *end == ',' || *end == '}' ? 0 : 1;
}

static int wls_read_hex_authority_file(
    const wchar_t *path,
    char *output,
    size_t hex_length
) {
    HANDLE file = INVALID_HANDLE_VALUE;
    FILE_STANDARD_INFO size;
    DWORD amount;
    int result = 1;
    if (path == NULL || output == NULL || hex_length + 2U > 128U) return 1;
    file = CreateFileW(
        path,
        GENERIC_READ | READ_CONTROL | WRITE_DAC | WRITE_OWNER,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(file)
        || !GetFileInformationByHandleEx(file, FileStandardInfo, &size, sizeof(size))
        || size.Directory || size.NumberOfLinks != 1U
        || wls_secure_root_only_handle(file) != 0
        || (size.EndOfFile.QuadPart != (LONGLONG)hex_length
            && size.EndOfFile.QuadPart != (LONGLONG)(hex_length + 1U))
        || wls_read_exact(file, output, (DWORD)size.EndOfFile.QuadPart) != 0) {
        goto cleanup;
    }
    amount = (DWORD)size.EndOfFile.QuadPart;
    if (amount == hex_length + 1U && output[hex_length] != '\n') goto cleanup;
    output[hex_length] = '\0';
    if (!wls_is_hex(output, hex_length)) goto cleanup;
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return result;
}

static int wls_verify_bootstrap_response(
    const char *response,
    const char *expected_request_id,
    const unsigned char key[32],
    struct wls_bootstrap_receipt *receipt
) {
    char protocol[32];
    char request_id[33];
    char epoch[33];
    char gateway_epoch[33];
    char controller_epoch[33];
    char data_plane[24];
    char active_slot[2];
    char runtime_generation[65];
    char host_boot_id[65];
    char intent_sha256[65];
    char intent_nonce[33];
    char signature[65];
    char expected_signature[65];
    char canonical[1024];
    unsigned char digest[32];
    unsigned long long generation = 0ULL;
    unsigned long long publication_generation = 0ULL;
    unsigned long long active_config_generation = 0ULL;
    int ok = 0;
    int ready = 0;
    int recovery_pending = 1;
    int canonical_length;
    int result = 1;
    ZeroMemory(digest, sizeof(digest));
    if (response == NULL || expected_request_id == NULL || key == NULL
        || wls_json_string(response, "protocol", protocol, sizeof(protocol)) != 0
        || wls_json_string(response, "request_id", request_id, sizeof(request_id)) != 0
        || wls_json_string(response, "epoch", epoch, sizeof(epoch)) != 0
        || wls_json_string(response, "gateway_epoch", gateway_epoch, sizeof(gateway_epoch)) != 0
        || wls_json_string(response, "controller_epoch", controller_epoch, sizeof(controller_epoch)) != 0
        || wls_json_string(response, "data_plane", data_plane, sizeof(data_plane)) != 0
        || wls_json_string(response, "active_slot", active_slot, sizeof(active_slot)) != 0
        || wls_json_string(response, "runtime_generation", runtime_generation, sizeof(runtime_generation)) != 0
        || wls_json_string(response, "host_boot_id", host_boot_id, sizeof(host_boot_id)) != 0
        || wls_json_string(response, "upgrade_intent_sha256", intent_sha256, sizeof(intent_sha256)) != 0
        || wls_json_string(response, "upgrade_intent_nonce", intent_nonce, sizeof(intent_nonce)) != 0
        || wls_json_string(response, "signature", signature, sizeof(signature)) != 0
        || wls_json_boolean(response, "ok", &ok) != 0
        || wls_json_boolean(response, "ready", &ready) != 0
        || wls_json_boolean(response, "recovery_pending", &recovery_pending) != 0
        || wls_json_unsigned_long_long(response, "generation", &generation) != 0
        || wls_json_unsigned_long_long(
            response, "publication_generation", &publication_generation
        ) != 0
        || wls_json_unsigned_long_long(
            response, "active_config_generation", &active_config_generation
        ) != 0
        || strcmp(protocol, "wls-edge/2") != 0
        || strcmp(request_id, expected_request_id) != 0
        || !wls_is_hex(epoch, 32U)
        || !wls_is_hex(gateway_epoch, 32U)
        || strcmp(epoch, gateway_epoch) != 0
        || strcmp(epoch, controller_epoch) != 0
        || strcmp(data_plane, "RUNNING") != 0
        || (active_slot[0] != 'A' && active_slot[0] != 'B')
        || active_slot[1] != '\0'
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(host_boot_id, 64U)
        || !wls_is_hex(intent_sha256, 64U)
        || !wls_is_hex(intent_nonce, 32U)
        || active_config_generation != publication_generation
        || !wls_is_hex(signature, 64U)
        || !ok || !ready || recovery_pending) {
        goto cleanup;
    }
    canonical_length = _snprintf_s(
        canonical,
        sizeof(canonical),
        _TRUNCATE,
        "{\"epoch\":\"%s\",\"ok\":true,\"payload\":{"
        "\"active_config_generation\":%llu,\"active_slot\":\"%s\","
        "\"controller_epoch\":\"%s\",\"data_plane\":\"RUNNING\","
        "\"gateway_epoch\":\"%s\",\"generation\":%llu,"
        "\"host_boot_id\":\"%s\",\"publication_generation\":%llu,"
        "\"ready\":true,\"recovery_pending\":false,"
        "\"runtime_generation\":\"%s\",\"upgrade_intent_nonce\":\"%s\","
        "\"upgrade_intent_sha256\":\"%s\"},\"protocol\":\"wls-edge/2\","
        "\"request_id\":\"%s\"}",
        epoch,
        active_config_generation,
        active_slot,
        controller_epoch,
        gateway_epoch,
        generation,
        host_boot_id,
        publication_generation,
        runtime_generation,
        intent_nonce,
        intent_sha256,
        request_id
    );
    if (canonical_length <= 0
        || wls_hmac_sha256(
            key,
            32U,
            (const unsigned char *)canonical,
            (DWORD)canonical_length,
            digest
        ) != 0) {
        goto cleanup;
    }
    wls_hex_encode_32(digest, expected_signature);
    if (!wls_constant_equal(signature, expected_signature, 64U)) goto cleanup;
    if (receipt != NULL) {
        ZeroMemory(receipt, sizeof(*receipt));
        strcpy_s(receipt->epoch, sizeof(receipt->epoch), epoch);
        strcpy_s(
            receipt->controller_epoch,
            sizeof(receipt->controller_epoch),
            controller_epoch
        );
        strcpy_s(receipt->active_slot, sizeof(receipt->active_slot), active_slot);
        strcpy_s(
            receipt->runtime_generation,
            sizeof(receipt->runtime_generation),
            runtime_generation
        );
        strcpy_s(receipt->host_boot_id, sizeof(receipt->host_boot_id), host_boot_id);
        strcpy_s(receipt->intent_sha256, sizeof(receipt->intent_sha256), intent_sha256);
        strcpy_s(receipt->intent_nonce, sizeof(receipt->intent_nonce), intent_nonce);
        receipt->generation = generation;
        receipt->active_config_generation = active_config_generation;
        receipt->observed_monotonic_ms = GetTickCount64();
    }
    result = 0;
cleanup:
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(expected_signature, sizeof(expected_signature));
    SecureZeroMemory(canonical, sizeof(canonical));
    return result;
}

static int wls_bootstrap_once(
    unsigned short controller_port,
    const char *fencing,
    const wchar_t *home,
    DWORD io_timeout_ms,
    struct wls_bootstrap_receipt *receipt
) {
    wchar_t token_path[WLS_PATH_CHARS];
    wchar_t host_path[WLS_PATH_CHARS];
    char token[65];
    char host[33];
    char request_id[65];
    char nonce[65];
    char request_digest_canonical[256];
    char request_digest[65];
    char signature_canonical[1024];
    char signature[65];
    char request[1280];
    char header[768];
    char *response = NULL;
    unsigned char key[32];
    unsigned char digest[32];
    unsigned char signature_bytes[32];
    SOCKET controller = INVALID_SOCKET;
    DWORD response_length = 0U;
    long long wall_time;
    unsigned long long monotonic;
    int request_digest_length;
    int signature_canonical_length;
    int request_length;
    int header_length;
    int result = 1;
    ZeroMemory(key, sizeof(key));
    ZeroMemory(digest, sizeof(digest));
    ZeroMemory(signature_bytes, sizeof(signature_bytes));
    if (home == NULL || fencing == NULL || io_timeout_ms < 1000U
        || wls_join_w(token_path, WLS_PATH_CHARS, home, L"trust\\admin.token") != 0
        || wls_join_w(host_path, WLS_PATH_CHARS, home, L"trust\\host-id") != 0
        || wls_read_hex_authority_file(token_path, token, 64U) != 0
        || wls_read_hex_authority_file(host_path, host, 32U) != 0
        || wls_hex_decode_fixed(token, key, sizeof(key)) != 0
        || wls_fencing(request_id) != 0
        || wls_fencing(nonce) != 0) {
        goto cleanup;
    }
    request_id[32] = '\0';
    nonce[32] = '\0';
    monotonic = GetTickCount64() / 1000ULL;
    wall_time = (long long)time(NULL);
    if (wall_time < 0) goto cleanup;
    request_digest_length = _snprintf_s(
        request_digest_canonical,
        sizeof(request_digest_canonical),
        _TRUNCATE,
        "{\"operation\":\"bootstrap\",\"payload\":{\"host_id\":\"%s\","
        "\"internal_bootstrap\":true}}",
        host
    );
    if (request_digest_length <= 0
        || wls_sha256(
            (const unsigned char *)request_digest_canonical,
            (DWORD)request_digest_length,
            digest
        ) != 0) {
        goto cleanup;
    }
    wls_hex_encode_32(digest, request_digest);
    signature_canonical_length = _snprintf_s(
        signature_canonical,
        sizeof(signature_canonical),
        _TRUNCATE,
        "{\"channel\":\"admin\",\"credential_id\":\"admin\",\"host_id\":\"%s\","
        "\"monotonic_timestamp\":%llu,\"nonce\":\"%s\",\"operation\":\"bootstrap\","
        "\"payload\":{\"host_id\":\"%s\",\"internal_bootstrap\":true},"
        "\"protocol\":\"wls-edge/2\",\"request_digest\":\"%s\","
        "\"request_id\":\"%s\",\"timestamp\":%lld}",
        host,
        monotonic,
        nonce,
        host,
        request_digest,
        request_id,
        wall_time
    );
    if (signature_canonical_length <= 0
        || wls_hmac_sha256(
            key,
            sizeof(key),
            (const unsigned char *)signature_canonical,
            (DWORD)signature_canonical_length,
            signature_bytes
        ) != 0) {
        goto cleanup;
    }
    wls_hex_encode_32(signature_bytes, signature);
    request_length = _snprintf_s(
        request,
        sizeof(request),
        _TRUNCATE,
        "{\"channel\":\"admin\",\"credential_id\":\"admin\",\"host_id\":\"%s\","
        "\"monotonic_timestamp\":%llu,\"nonce\":\"%s\",\"operation\":\"bootstrap\","
        "\"payload\":{\"host_id\":\"%s\",\"internal_bootstrap\":true},"
        "\"protocol\":\"wls-edge/2\",\"request_digest\":\"%s\","
        "\"request_id\":\"%s\",\"signature\":\"%s\",\"timestamp\":%lld}\n",
        host,
        monotonic,
        nonce,
        host,
        request_digest,
        request_id,
        signature,
        wall_time
    );
    if (request_length <= 0) goto cleanup;
    controller = wls_connect_controller(
        controller_port, io_timeout_ms, fencing
    );
    response = (char *)HeapAlloc(GetProcessHeap(), 0U, WLS_MAX_REQUEST + 1U);
    if (controller == INVALID_SOCKET || response == NULL) goto cleanup;
    header_length = _snprintf_s(
        header,
        sizeof(header),
        _TRUNCATE,
        "{\"broker_schema\":1,\"action_protocol\":2,\"channel\":\"admin\","
        "\"sid\":\"S-1-5-18\",\"pid\":%lu,\"is_admin\":true,"
        "\"fencing_token\":\"%s\",\"payload_length\":%d}\n",
        GetCurrentProcessId(),
        fencing,
        request_length
    );
    if (header_length <= 0
        || wls_socket_write_all(controller, header, (DWORD)header_length) != 0
        || wls_socket_write_all(controller, request, (DWORD)request_length) != 0) {
        goto cleanup;
    }
    while (wls_socket_read_line(
        controller, response, WLS_MAX_REQUEST + 1U, &response_length
    ) == 0) {
        if (strncmp(response, "WLS-ACTION/1\t", 13U) == 0
            || strncmp(response, "WLS-ACTION/2\t", 13U) == 0) {
            char action_response[4096];
            int action_result;
            int action_length;
            if (response[11] == '2') {
                action_response[0] = '\0';
                action_result = wls_handle_action_v2(
                    response,
                    NULL,
                    L"admin",
                    "S-1-5-18",
                    home,
                    action_response,
                    sizeof(action_response)
                );
                action_length = (action_result == 0
                        || strncmp(action_response, "WLS-ACTION/2\t", 13U) == 0)
                    ? (int)strlen(action_response)
                    : _snprintf_s(
                        action_response,
                        sizeof(action_response),
                        _TRUNCATE,
                        "WLS-ACTION/2\tERR\tBROKER_IO\tBOOTSTRAP\t-\t-\n"
                    );
            } else {
                action_result = wls_handle_action(
                    response, NULL, L"admin", "S-1-5-18", home
                );
                action_length = _snprintf_s(
                    action_response,
                    sizeof(action_response),
                    _TRUNCATE,
                    action_result == 0
                        ? "WLS-ACTION/1\tOK\n"
                        : "WLS-ACTION/1\tERR\t%lu\n",
                    action_result == 0 ? 0UL : GetLastError()
                );
            }
            if (action_length <= 0
                || wls_socket_write_all(
                    controller, action_response, (DWORD)action_length
                ) != 0) {
                goto cleanup;
            }
            continue;
        }
        result = wls_verify_bootstrap_response(response, request_id, key, receipt);
        break;
    }
cleanup:
    if (controller != INVALID_SOCKET) closesocket(controller);
    if (response != NULL) {
        SecureZeroMemory(response, WLS_MAX_REQUEST + 1U);
        HeapFree(GetProcessHeap(), 0U, response);
    }
    SecureZeroMemory(key, sizeof(key));
    SecureZeroMemory(token, sizeof(token));
    SecureZeroMemory(signature_bytes, sizeof(signature_bytes));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(signature_canonical, sizeof(signature_canonical));
    SecureZeroMemory(request, sizeof(request));
    return result;
}

static int wls_bootstrap_once_serialized(
    unsigned short controller_port,
    const char *fencing,
    const wchar_t *home,
    DWORD io_timeout_ms,
    struct wls_bootstrap_receipt *receipt
) {
    int result;
    AcquireSRWLockExclusive(&wls_bootstrap_lock);
    result = wls_bootstrap_once(
        controller_port,
        fencing,
        home,
        io_timeout_ms,
        receipt
    );
    ReleaseSRWLockExclusive(&wls_bootstrap_lock);
    return result;
}

static int wls_bootstrap_controller(
    unsigned short controller_port,
    const char *fencing,
    const wchar_t *home,
    HANDLE stop_event,
    struct wls_bootstrap_maintenance_context *maintenance
) {
    static const DWORD delays[WLS_BOOTSTRAP_ATTEMPTS - 1U] = {
        250U, 1000U, 5000U
    };
    unsigned int attempt;
    for (attempt = 0U; attempt < WLS_BOOTSTRAP_ATTEMPTS; attempt++) {
        struct wls_bootstrap_receipt receipt;
        int result;
        ZeroMemory(&receipt, sizeof(receipt));
        result = wls_bootstrap_once_serialized(
                controller_port,
                fencing,
                home,
                WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS,
                &receipt
            );
        if (maintenance != NULL) {
            (void)wls_bootstrap_health_record(maintenance, result, &receipt);
        }
        if (result == 0) return 0;
        if (attempt + 1U >= WLS_BOOTSTRAP_ATTEMPTS) break;
        if (WaitForSingleObject(stop_event, delays[attempt]) != WAIT_TIMEOUT) break;
    }
    SetLastError(ERROR_PROTOCOL_UNREACHABLE);
    return 1;
}

static DWORD WINAPI wls_bootstrap_maintenance_thread(void *argument)
{
    struct wls_bootstrap_maintenance_context *context = argument;
    if (context == NULL || context->stop_event == NULL) return 1U;
    for (;;) {
        DWORD wait = WaitForSingleObject(
            context->stop_event,
            WLS_MAINTENANCE_BOOTSTRAP_INTERVAL_MS
        );
        if (wait == WAIT_OBJECT_0) return 0U;
        if (wait != WAIT_TIMEOUT) return 1U;
        {
            struct wls_bootstrap_receipt receipt;
            int result;
            ZeroMemory(&receipt, sizeof(receipt));
            result = wls_bootstrap_once_serialized(
            context->controller_port,
            context->fencing,
            context->home,
            WLS_MAINTENANCE_CONTROLLER_IO_TIMEOUT_MS,
            &receipt
            );
            (void)wls_bootstrap_health_record(context, result, &receipt);
        }
    }
}

static HANDLE wls_pipe_security(const wchar_t *sddl, SECURITY_ATTRIBUTES *attributes)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorW(
        sddl,
        SDDL_REVISION_1,
        &descriptor,
        NULL)) {
        return NULL;
    }
    attributes->nLength = sizeof(*attributes);
    attributes->lpSecurityDescriptor = descriptor;
    attributes->bInheritHandle = FALSE;
    return (HANDLE)descriptor;
}

static HANDLE wls_create_public_pipe(
    const struct wls_channel *channel,
    int first_instance
) {
    SECURITY_ATTRIBUTES security;
    HANDLE descriptor = wls_pipe_security(channel->security_sddl, &security);
    HANDLE public_pipe;
    DWORD access = PIPE_ACCESS_DUPLEX;
    if (descriptor == NULL) return INVALID_HANDLE_VALUE;
    if (first_instance) access |= FILE_FLAG_FIRST_PIPE_INSTANCE;
    public_pipe = CreateNamedPipeW(
        channel->public_pipe,
        access,
        PIPE_TYPE_BYTE | PIPE_READMODE_BYTE | PIPE_WAIT | PIPE_REJECT_REMOTE_CLIENTS,
        channel->instance_count,
        WLS_PUBLIC_PIPE_BUFFER,
        WLS_PUBLIC_PIPE_BUFFER,
        5000U,
        &security
    );
    LocalFree(descriptor);
    return public_pipe;
}

static DWORD WINAPI wls_channel_thread(LPVOID context)
{
    struct wls_channel_instance *instance =
        (struct wls_channel_instance *)context;
    struct wls_channel *channel = instance->channel;
    HANDLE public_pipe = instance->public_pipe;
    if (channel == NULL || public_pipe == INVALID_HANDLE_VALUE) return 10U;
    while (WaitForSingleObject(channel->stop_event, 0U) == WAIT_TIMEOUT) {
        BOOL connected;
        char *request = NULL;
        char *response = NULL;
        DWORD request_capacity = 0U;
        DWORD response_capacity = 0U;
        DWORD request_size = 0U;
        DWORD response_size = 0U;
        DWORD client_pid = 0U;
        char client_sid[256];
        char channel_utf8[32];
        char header[768];
        int header_size;
        int response_written = 0;
        int sid_gate_acquired = 0;
        SOCKET controller = INVALID_SOCKET;
        DWORD wait_mode = PIPE_READMODE_BYTE | PIPE_WAIT;
        DWORD pipe_mode = PIPE_READMODE_BYTE | PIPE_NOWAIT;
        if (!SetNamedPipeHandleState(public_pipe, &wait_mode, NULL, NULL)) break;
        connected = ConnectNamedPipe(public_pipe, NULL)
            ? TRUE
            : GetLastError() == ERROR_PIPE_CONNECTED;
        if (!connected) {
            DWORD connect_error = GetLastError();
            if (WaitForSingleObject(channel->stop_event, 0U) == WAIT_OBJECT_0) break;
            if (connect_error == ERROR_NO_DATA) {
                DisconnectNamedPipe(public_pipe);
                continue;
            }
            break;
        }
        if (!SetNamedPipeHandleState(public_pipe, &pipe_mode, NULL, NULL)) {
            DisconnectNamedPipe(public_pipe);
            break;
        }
        /* Authenticate the kernel pipe peer before any frame allocation. */
        if (wls_peer_sid(public_pipe, client_sid, sizeof(client_sid), &client_pid) != 0) {
            goto request_cleanup;
        }
        if (!wls_sid_gate_acquire(
                channel->sid_gate,
                client_sid,
                channel->sid_active_limit
            )) {
            goto request_cleanup;
        }
        sid_gate_acquired = 1;
        if (wls_pipe_read_frame_alloc(
                public_pipe,
                channel->stop_event,
                &request,
                &request_capacity,
                &request_size,
                WLS_PUBLIC_PIPE_IO_TIMEOUT_MS
            ) != 0
            || WideCharToMultiByte(
                CP_UTF8,
                WC_ERR_INVALID_CHARS,
                channel->channel,
                -1,
                channel_utf8,
                sizeof(channel_utf8),
                NULL,
                NULL
            ) <= 0) {
            goto request_cleanup;
        }
        controller = wls_connect_controller(
            channel->controller_port,
            wcscmp(channel->channel, L"admin") == 0
                ? WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS
                : WLS_PROJECT_CONTROLLER_IO_TIMEOUT_MS,
            channel->fencing
        );
        if (controller == INVALID_SOCKET) goto request_cleanup;
        header_size = _snprintf_s(
            header,
            sizeof(header),
            _TRUNCATE,
            "{\"broker_schema\":1,\"action_protocol\":2,\"channel\":\"%s\",\"sid\":\"%s\","
            "\"pid\":%lu,\"is_admin\":%s,\"fencing_token\":\"%s\",\"payload_length\":%lu}\n",
            channel_utf8,
            client_sid,
            client_pid,
            wcscmp(channel->channel, L"admin") == 0 ? "true" : "false",
            channel->fencing,
            request_size
        );
        if (header_size <= 0
            || wls_socket_write_all(controller, header, (DWORD)header_size) != 0
            || wls_socket_write_all(controller, request, request_size) != 0) {
            goto request_cleanup;
        }
        SecureZeroMemory(request, request_capacity);
        HeapFree(GetProcessHeap(), 0U, request);
        request = NULL;
        request_capacity = 0U;
        request_size = 0U;
        while (wls_socket_read_frame_alloc(
            controller,
            &response,
            &response_capacity,
            &response_size
        ) == 0) {
            if (strncmp(response, "WLS-ACTION/1\t", 13U) == 0
                || strncmp(response, "WLS-ACTION/2\t", 13U) == 0) {
                int action_result;
                char action_response[4096];
                int action_length;
                if (response[11] == '2') {
                    action_response[0] = '\0';
                    action_result = wls_handle_action_v2(
                        response, public_pipe, channel->channel, client_sid,
                        channel->home, action_response, sizeof(action_response)
                    );
                    action_length = (action_result == 0
                            || strncmp(action_response, "WLS-ACTION/2\t", 13U) == 0)
                        ? (int)strlen(action_response)
                        : _snprintf_s(
                            action_response, sizeof(action_response), _TRUNCATE,
                            "WLS-ACTION/2\tERR\tBROKER_IO\tUNKNOWN\t-\t-\n"
                        );
                } else {
                    action_result = wls_handle_action(
                        response, public_pipe, channel->channel, client_sid,
                        channel->home
                    );
                    action_length = _snprintf_s(
                        action_response,
                        sizeof(action_response),
                        _TRUNCATE,
                        action_result == 0
                            ? "WLS-ACTION/1\tOK\n"
                            : "WLS-ACTION/1\tERR\t%lu\n",
                        action_result == 0 ? 0UL : GetLastError()
                    );
                }
                if (action_length <= 0
                    || wls_socket_write_all(
                        controller,
                        action_response,
                        (DWORD)action_length
                    ) != 0) {
                    goto request_cleanup;
                }
                continue;
            }
            if (wls_pipe_write_all(
                public_pipe,
                channel->stop_event,
                response,
                response_size,
                WLS_PUBLIC_PIPE_IO_TIMEOUT_MS
            ) == 0) {
                response_written = 1;
            }
            break;
        }
request_cleanup:
        if (controller != INVALID_SOCKET) closesocket(controller);
        if (sid_gate_acquired) {
            wls_sid_gate_release(
                channel->sid_gate,
                client_sid,
                channel->sid_active_limit
            );
        }
        if (request != NULL) {
            SecureZeroMemory(request, request_capacity);
            HeapFree(GetProcessHeap(), 0U, request);
        }
        if (response != NULL) {
            SecureZeroMemory(response, response_capacity);
            HeapFree(GetProcessHeap(), 0U, response);
        }
        if (response_written) {
            wls_pipe_wait_for_client_close(
                public_pipe,
                channel->stop_event,
                WLS_PUBLIC_PIPE_IO_TIMEOUT_MS
            );
        }
        DisconnectNamedPipe(public_pipe);
    }
    CloseHandle(public_pipe);
    return 0U;
}

static int wls_write_fencing_file(const wchar_t *path, const char *token)
{
    char contents[WLS_FENCING_BYTES * 2U + 2U];
    int length = _snprintf_s(
        contents,
        sizeof(contents),
        _TRUNCATE,
        "%s\n",
        token
    );
    return length > 0 ? wls_atomic_bytes(path, contents, (DWORD)length) : 1;
}

static void wls_log_controller_event(
    const wchar_t *home,
    const char *event,
    DWORD value
) {
    wchar_t log_path[WLS_PATH_CHARS];
    HANDLE log;
    char message[160];
    int length;
    DWORD written = 0U;
    if (home == NULL || event == NULL
        || wls_join_w(
            log_path,
            WLS_PATH_CHARS,
            home,
            L"runtime\\logs\\controller-native.log"
        ) != 0) {
        return;
    }
    log = CreateFileW(
        log_path,
        FILE_APPEND_DATA,
        FILE_SHARE_READ | FILE_SHARE_DELETE,
        NULL,
        OPEN_ALWAYS,
        FILE_ATTRIBUTE_NORMAL,
        NULL
    );
    length = _snprintf_s(
        message,
        sizeof(message),
        _TRUNCATE,
        "[native-broker] %s: %lu\r\n",
        event,
        (unsigned long)value
    );
    if (log != INVALID_HANDLE_VALUE && length > 0) {
        (void)WriteFile(log, message, (DWORD)length, &written, NULL);
    }
    if (log != INVALID_HANDLE_VALUE) CloseHandle(log);
}

static int wls_allocate_controller_port(unsigned short *port)
{
    SOCKET probe = INVALID_SOCKET;
    struct sockaddr_in address;
    int length = sizeof(address);
    int result = 1;
    if (port == NULL) return 1;
    probe = WSASocketW(
        AF_INET,
        SOCK_STREAM,
        IPPROTO_TCP,
        NULL,
        0U,
        WSA_FLAG_NO_HANDLE_INHERIT
    );
    if (probe == INVALID_SOCKET) return 1;
    ZeroMemory(&address, sizeof(address));
    address.sin_family = AF_INET;
    address.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    address.sin_port = 0U;
    if (bind(probe, (const struct sockaddr *)&address, sizeof(address)) != 0
        || getsockname(probe, (struct sockaddr *)&address, &length) != 0
        || ntohs(address.sin_port) == 0U) {
        goto cleanup;
    }
    *port = ntohs(address.sin_port);
    result = 0;
cleanup:
    closesocket(probe);
    return result;
}

static HANDLE wls_restricted_controller_token(void)
{
    HANDLE process_token = NULL;
    HANDLE restricted = NULL;
    PTOKEN_GROUPS groups = NULL;
    DWORD groups_length = 0U;
    BYTE service_sid_buffer[SECURITY_MAX_SID_SIZE];
    DWORD service_sid_length = sizeof(service_sid_buffer);
    wchar_t service_domain[256];
    DWORD service_domain_length = sizeof(service_domain) / sizeof(service_domain[0]);
    SID_NAME_USE service_use;
    SID_AND_ATTRIBUTES restricting_sid;
    DWORD index;
    int service_sid_enabled = 0;
    if (!OpenProcessToken(
        GetCurrentProcess(),
        TOKEN_DUPLICATE | TOKEN_QUERY,
        &process_token
    ) || !LookupAccountNameW(
        NULL,
        L"NT SERVICE\\weline-wls-gateway-v2",
        service_sid_buffer,
        &service_sid_length,
        service_domain,
        &service_domain_length,
        &service_use
    )) {
        SetLastError(ERROR_ACCESS_DENIED);
        goto cleanup;
    }
    if (GetTokenInformation(
        process_token,
        TokenGroups,
        NULL,
        0U,
        &groups_length
    ) || GetLastError() != ERROR_INSUFFICIENT_BUFFER) {
        SetLastError(ERROR_ACCESS_DENIED);
        goto cleanup;
    }
    groups = (PTOKEN_GROUPS)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        groups_length
    );
    if (groups == NULL || !GetTokenInformation(
        process_token,
        TokenGroups,
        groups,
        groups_length,
        &groups_length
    )) {
        goto cleanup;
    }
    for (index = 0U; index < groups->GroupCount; index++) {
        DWORD attributes = groups->Groups[index].Attributes;
        if (EqualSid(groups->Groups[index].Sid, service_sid_buffer)
            && (attributes & SE_GROUP_ENABLED) != 0U
            && (attributes & SE_GROUP_USE_FOR_DENY_ONLY) == 0U) {
            service_sid_enabled = 1;
            break;
        }
    }
    if (!service_sid_enabled) {
        SetLastError(ERROR_ACCESS_DENIED);
        goto cleanup;
    }
    restricting_sid.Sid = service_sid_buffer;
    restricting_sid.Attributes = 0U;
    if (!CreateRestrictedToken(
        process_token,
        DISABLE_MAX_PRIVILEGE,
        0U,
        NULL,
        0U,
        NULL,
        1U,
        &restricting_sid,
        &restricted
    ) || !IsTokenRestricted(restricted)) {
        if (restricted != NULL) {
            CloseHandle(restricted);
            restricted = NULL;
        }
        SetLastError(ERROR_ACCESS_DENIED);
    }
cleanup:
    if (groups != NULL) {
        SecureZeroMemory(groups, groups_length);
        HeapFree(GetProcessHeap(), 0U, groups);
    }
    if (process_token != NULL) CloseHandle(process_token);
    return restricted;
}

static int wls_read_nginx_pid(const wchar_t *home, DWORD *pid)
{
    wchar_t pid_path[WLS_PATH_CHARS];
    char contents[32];
    size_t length = 0U;
    size_t index;
    DWORD value = 0U;
    if (home == NULL || pid == NULL
        || wls_join_w(
            pid_path,
            WLS_PATH_CHARS,
            home,
            L"runtime\\run\\nginx.pid"
        ) != 0
        || wls_read_small_file(
            pid_path,
            contents,
            sizeof(contents),
            &length
        ) != 0
        || length == 0U) {
        return 1;
    }
    for (index = 0U; index < length; index++) {
        unsigned int digit;
        if (contents[index] == '\r' || contents[index] == '\n') break;
        if (contents[index] < '0' || contents[index] > '9') return 1;
        digit = (unsigned int)(contents[index] - '0');
        if (value > (MAXDWORD - digit) / 10U) return 1;
        value = value * 10U + digit;
    }
    while (index < length) {
        if (contents[index] != '\r' && contents[index] != '\n') return 1;
        index++;
    }
    if (value == 0U) return 1;
    *pid = value;
    return 0;
}

static unsigned long long wls_filetime_value(const FILETIME *value)
{
    ULARGE_INTEGER combined;
    combined.LowPart = value->dwLowDateTime;
    combined.HighPart = value->dwHighDateTime;
    return combined.QuadPart;
}

static int wls_controller_slot_runtime_generation(
    const wchar_t *home,
    wchar_t slot,
    char runtime_generation[65]
) {
    wchar_t manifest_path[WLS_PATH_CHARS];
    char *manifest = NULL;
    size_t manifest_length = 0U;
    int result = 1;
    if (home == NULL || runtime_generation == NULL
        || (slot != L'A' && slot != L'B')
        || _snwprintf_s(
            manifest_path,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%lc\\manifest.json",
            home,
            slot
        ) < 0
        || wls_win_read_path_contents(
            manifest_path, &manifest, &manifest_length
        ) != 0
        || manifest_length == 0U
        || wls_json_string(
            manifest,
            "runtime_generation",
            runtime_generation,
            65U
        ) != 0
        || !wls_is_hex(runtime_generation, 64U)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (manifest != NULL) {
        SecureZeroMemory(manifest, manifest_length);
        HeapFree(GetProcessHeap(), 0U, manifest);
    }
    return result;
}

static int wls_write_controller_process_identity(
    const wchar_t *home,
    HANDLE controller,
    const wchar_t *active_slot,
    const char *runtime_generation,
    const char *fencing
) {
    wchar_t identity_path[WLS_PATH_CHARS];
    wchar_t expected_binary[WLS_PATH_CHARS];
    wchar_t actual_binary[WLS_PATH_CHARS];
    DWORD actual_binary_length = WLS_PATH_CHARS;
    DWORD pid;
    FILETIME created, exited, kernel, user;
    char manifest_generation[65];
    char fencing_digest[65];
    char payload[1024];
    int length;
    if (home == NULL || controller == NULL || active_slot == NULL
        || runtime_generation == NULL || fencing == NULL
        || (active_slot[0] != L'A' && active_slot[0] != L'B')
        || active_slot[1] != L'\0'
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(fencing, 64U)
        || _snwprintf_s(
            expected_binary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%ls\\bin\\php.exe",
            home,
            active_slot
        ) < 0
        || wls_join_w(
            identity_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\controller-process.identity"
        ) != 0) return 1;
    pid = GetProcessId(controller);
    if (pid == 0U
        || !GetProcessTimes(controller, &created, &exited, &kernel, &user)
        || !QueryFullProcessImageNameW(
            controller, 0U, actual_binary, &actual_binary_length
        )
        || _wcsicmp(actual_binary, expected_binary) != 0
        || wls_controller_slot_runtime_generation(
            home, active_slot[0], manifest_generation
        ) != 0
        || strcmp(manifest_generation, runtime_generation) != 0
        || WaitForSingleObject(controller, 0U) != WAIT_TIMEOUT) {
        return 1;
    }
    wls_win_sha256_hex(
        (const unsigned char *)fencing, strlen(fencing), fencing_digest
    );
    length = _snprintf_s(
        payload,
        sizeof(payload),
        _TRUNCATE,
        "WLS-CONTROLLER-PROCESS/2\n"
        "pid=%lu\ncreation_time=%llu\nslot=%c\n"
        "runtime_generation=%s\nfencing_digest=%s\n",
        (unsigned long)pid,
        wls_filetime_value(&created),
        (char)active_slot[0],
        runtime_generation,
        fencing_digest
    );
    SecureZeroMemory(fencing_digest, sizeof(fencing_digest));
    return length > 0
        ? wls_atomic_bytes(identity_path, payload, (DWORD)length)
        : 1;
}

/*
 * A crashed Broker deliberately leaves a healthy Nginx inside the stable Job
 * for no-interruption adoption. Its Controller must not overlap the new
 * generation, however. The freshly written fencing token makes a v2
 * Controller self-isolate; this routine waits only for a root-authored,
 * birth-bound Controller identity and never terminates an unknown PID.
 */
static int wls_wait_previous_controller_exit(
    const wchar_t *home,
    const char *new_fencing
) {
    wchar_t identity_path[WLS_PATH_CHARS];
    wchar_t pid_path[WLS_PATH_CHARS];
    wchar_t expected_binary[WLS_PATH_CHARS];
    wchar_t actual_binary[WLS_PATH_CHARS];
    DWORD actual_binary_length = WLS_PATH_CHARS;
    char *identity = NULL;
    size_t identity_length = 0U;
    char pid_contents[32];
    size_t pid_length = 0U;
    char slot[2] = {0};
    char runtime_generation[65] = {0};
    char manifest_generation[65] = {0};
    char recorded_fencing_digest[65] = {0};
    char new_fencing_digest[65] = {0};
    unsigned long parsed_pid = 0UL;
    unsigned long long parsed_created = 0ULL;
    int consumed = 0;
    HANDLE process = NULL;
    FILETIME created, exited, kernel, user;
    unsigned int attempt;
    int result = 1;
    if (home == NULL || new_fencing == NULL || !wls_is_hex(new_fencing, 64U)
        || wls_join_w(
            identity_path, WLS_PATH_CHARS, home,
            L"trust\\controller-process.identity"
        ) != 0
        || wls_join_w(
            pid_path, WLS_PATH_CHARS, home,
            L"runtime\\run\\controller.pid"
        ) != 0) return 1;
    if (wls_win_read_path_contents(
            identity_path, &identity, &identity_length
        ) != 0) {
        char *end = NULL;
        unsigned long fallback_pid;
        DWORD identity_attributes = GetFileAttributesW(identity_path);
        if (identity_attributes != INVALID_FILE_ATTRIBUTES
            || (GetLastError() != ERROR_FILE_NOT_FOUND
                && GetLastError() != ERROR_PATH_NOT_FOUND)) {
            return 1;
        }
        if (wls_read_small_file(
                pid_path, pid_contents, sizeof(pid_contents), &pid_length
            ) != 0) {
            DWORD pid_error = GetLastError();
            return pid_error == ERROR_FILE_NOT_FOUND
                    || pid_error == ERROR_PATH_NOT_FOUND
                ? 0
                : 1;
        }
        while (pid_length > 0U
            && (pid_contents[pid_length - 1U] == '\r'
                || pid_contents[pid_length - 1U] == '\n')) {
            pid_contents[--pid_length] = '\0';
        }
        errno = 0;
        fallback_pid = strtoul(pid_contents, &end, 10);
        if (errno != 0 || end == pid_contents || *end != '\0'
            || fallback_pid == 0UL || fallback_pid > MAXDWORD) return 1;
        process = OpenProcess(
            SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION,
            FALSE,
            (DWORD)fallback_pid
        );
        if (process == NULL) {
            return GetLastError() == ERROR_INVALID_PARAMETER ? 0 : 1;
        }
        result = WaitForSingleObject(process, 0U) == WAIT_OBJECT_0 ? 0 : 1;
        CloseHandle(process);
        return result;
    }
    if (identity_length == 0U || identity_length > 1024U
        || sscanf(
            identity,
            "WLS-CONTROLLER-PROCESS/2\n"
            "pid=%lu\ncreation_time=%llu\nslot=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "fencing_digest=%64[0-9a-f]\n%n",
            &parsed_pid,
            &parsed_created,
            slot,
            runtime_generation,
            recorded_fencing_digest,
            &consumed
        ) != 5
        || consumed != (int)identity_length
        || parsed_pid == 0UL || parsed_pid > MAXDWORD
        || parsed_created == 0ULL
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(recorded_fencing_digest, 64U)
        || wls_controller_slot_runtime_generation(
            home, (wchar_t)slot[0], manifest_generation
        ) != 0
        || strcmp(runtime_generation, manifest_generation) != 0
        || _snwprintf_s(
            expected_binary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%lc\\bin\\php.exe",
            home,
            (wchar_t)(unsigned char)slot[0]
        ) < 0) {
        goto cleanup;
    }
    wls_win_sha256_hex(
        (const unsigned char *)new_fencing,
        strlen(new_fencing),
        new_fencing_digest
    );
    if (strcmp(recorded_fencing_digest, new_fencing_digest) == 0) goto cleanup;
    process = OpenProcess(
        SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION,
        FALSE,
        (DWORD)parsed_pid
    );
    if (process == NULL) {
        result = GetLastError() == ERROR_INVALID_PARAMETER ? 0 : 1;
        goto cleanup;
    }
    if (WaitForSingleObject(process, 0U) == WAIT_OBJECT_0) {
        result = 0;
        goto cleanup;
    }
    if (!GetProcessTimes(process, &created, &exited, &kernel, &user)
        || wls_filetime_value(&created) != parsed_created
        || !QueryFullProcessImageNameW(
            process, 0U, actual_binary, &actual_binary_length
        )
        || _wcsicmp(actual_binary, expected_binary) != 0) {
        goto cleanup;
    }
    for (
        attempt = 0U;
        attempt < WLS_PREVIOUS_CONTROLLER_EXIT_ATTEMPTS;
        attempt++
    ) {
        DWORD wait = WaitForSingleObject(
            process, WLS_PREVIOUS_CONTROLLER_EXIT_POLL_MS
        );
        if (wait == WAIT_OBJECT_0) {
            result = 0;
            goto cleanup;
        }
        if (wait != WAIT_TIMEOUT) goto cleanup;
    }
cleanup:
    SecureZeroMemory(recorded_fencing_digest, sizeof(recorded_fencing_digest));
    SecureZeroMemory(new_fencing_digest, sizeof(new_fencing_digest));
    if (identity != NULL) {
        SecureZeroMemory(identity, identity_length);
        HeapFree(GetProcessHeap(), 0U, identity);
    }
    if (process != NULL) CloseHandle(process);
    return result;
}

struct wls_win_publication_identity {
    char config_digest[65];
    char config_path_digest[65];
    char config_object[96];
    unsigned long long generation;
};

static int wls_collect_publication_identity(
    const wchar_t *home,
    struct wls_win_publication_identity *identity
) {
    wchar_t config_path[WLS_PATH_CHARS];
    wchar_t state_path[WLS_PATH_CHARS];
    char config_path_utf8[WLS_PATH_CHARS * 3U];
    char *state = NULL;
    size_t state_length = 0U;
    HANDLE config = INVALID_HANDLE_VALUE;
    BY_HANDLE_FILE_INFORMATION config_information;
    unsigned long long config_size = 0ULL;
    unsigned char digest[32];
    int utf8_length;
    int result = 1;
    ZeroMemory(identity, sizeof(*identity));
    if (wls_join_w(
            config_path, WLS_PATH_CHARS, home, L"runtime\\conf\\nginx.conf"
        ) != 0
        || wls_join_w(
            state_path, WLS_PATH_CHARS, home, L"state\\gateway-state.json"
        ) != 0) return 1;
    config = CreateFileW(
        config_path, GENERIC_READ | SYNCHRONIZE, FILE_SHARE_READ,
        NULL, OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT, NULL
    );
    utf8_length = WideCharToMultiByte(
        CP_UTF8, WC_ERR_INVALID_CHARS, config_path, -1,
        config_path_utf8, sizeof(config_path_utf8), NULL, NULL
    );
    if (config == INVALID_HANDLE_VALUE || wls_handle_is_reparse(config)
        || utf8_length <= 1
        || wls_win_read_digest(
            config, identity->config_digest, &config_size,
            &config_information
        ) != 0
        || config_size == 0ULL
        || wls_win_object_id(
            config, identity->config_object,
            sizeof(identity->config_object)
        ) != 0
        || wls_sha256(
            (const unsigned char *)config_path_utf8,
            (DWORD)(utf8_length - 1), digest
        ) != 0
        || wls_win_read_path_contents(
            state_path, &state, &state_length
        ) != 0
        || wls_win_json_generation(state, &identity->generation) != 0) goto cleanup;
    wls_hex_encode_32(digest, identity->config_path_digest);
    SecureZeroMemory(digest, sizeof(digest));
    result = 0;
cleanup:
    if (state != NULL) {
        SecureZeroMemory(state, state_length);
        HeapFree(GetProcessHeap(), 0U, state);
    }
    if (config != INVALID_HANDLE_VALUE) CloseHandle(config);
    return result;
}

static int wls_write_broker_launch_receipt(
    const wchar_t *home,
    const wchar_t *active_slot,
    const char *runtime_generation,
    const struct wls_win_publication_identity *publication,
    char receipt_digest[65]
) {
    wchar_t broker_path[WLS_PATH_CHARS];
    wchar_t receipt_path[WLS_PATH_CHARS];
    DWORD broker_path_length = WLS_PATH_CHARS;
    HANDLE broker = INVALID_HANDLE_VALUE;
    BY_HANDLE_FILE_INFORMATION broker_identity;
    FILETIME created, exited, kernel, user;
    unsigned long long broker_size = 0ULL;
    char broker_digest[65];
    char receipt[2048];
    unsigned char digest[32];
    int length;
    if (!QueryFullProcessImageNameW(
            GetCurrentProcess(), 0U, broker_path, &broker_path_length
        )
        || !GetProcessTimes(
            GetCurrentProcess(), &created, &exited, &kernel, &user
        )
        || wls_join_w(
            receipt_path, WLS_PATH_CHARS, home,
            L"trust\\broker-launch.receipt"
        ) != 0) return 1;
    broker = CreateFileW(
        broker_path, GENERIC_READ | SYNCHRONIZE, FILE_SHARE_READ,
        NULL, OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT, NULL
    );
    if (broker == INVALID_HANDLE_VALUE || wls_handle_is_reparse(broker)
        || wls_win_read_digest(
            broker, broker_digest, &broker_size, &broker_identity
        ) != 0 || broker_size == 0ULL) goto cleanup;
    length = _snprintf_s(
        receipt, sizeof(receipt), _TRUNCATE,
        "WLS-BROKER-LAUNCH/2\npid=%lu\nstart_id=%llu\n"
        "binary_digest=%s\nslot=%c\nruntime_generation=%s\n"
        "config_digest=%s\nconfig_path_digest=%s\nconfig_object=%s\n"
        "publication_generation=%llu\n",
        (unsigned long)GetCurrentProcessId(), wls_filetime_value(&created),
        broker_digest, (char)active_slot[0], runtime_generation,
        publication->config_digest, publication->config_path_digest,
        publication->config_object, publication->generation
    );
    if (length <= 0
        || wls_atomic_bytes(receipt_path, receipt, (DWORD)length) != 0
        || wls_sha256(
            (const unsigned char *)receipt, (DWORD)length, digest
        ) != 0) goto cleanup;
    wls_hex_encode_32(digest, receipt_digest);
    SecureZeroMemory(digest, sizeof(digest));
    CloseHandle(broker);
    return 0;
cleanup:
    if (broker != INVALID_HANDLE_VALUE) CloseHandle(broker);
    return 1;
}

static int wls_existing_broker_receipt_digest(
    const wchar_t *home,
    char receipt_digest[65]
) {
    wchar_t receipt_path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    if (wls_join_w(
            receipt_path, WLS_PATH_CHARS, home,
            L"trust\\broker-launch.receipt"
        ) != 0
        || wls_win_read_path_contents(
            receipt_path, &contents, &length
        ) != 0
        || length == 0U) return 1;
    wls_win_sha256_hex(
        (const unsigned char *)contents, length, receipt_digest
    );
    SecureZeroMemory(contents, length);
    HeapFree(GetProcessHeap(), 0U, contents);
    return 0;
}

static int wls_write_nginx_process_identity(
    const wchar_t *home,
    DWORD pid,
    const FILETIME *created,
    const wchar_t *active_slot,
    const char *runtime_generation
) {
    wchar_t path[WLS_PATH_CHARS];
    char payload[1024];
    char receipt_digest[65];
    struct wls_win_publication_identity publication;
    int length;
    if (home == NULL || created == NULL || active_slot == NULL
        || runtime_generation == NULL
        || pid == 0U
        || (active_slot[0] != L'A' && active_slot[0] != L'B')
        || active_slot[1] != L'\0'
        || !wls_is_hex(runtime_generation, 64U)
        || wls_collect_publication_identity(home, &publication) != 0
        || wls_write_broker_launch_receipt(
            home, active_slot, runtime_generation, &publication, receipt_digest
        ) != 0
        || wls_join_w(
            path,
            WLS_PATH_CHARS,
            home,
            L"trust\\nginx-process.identity"
        ) != 0) {
        return 1;
    }
    length = _snprintf_s(
        payload,
        sizeof(payload),
        _TRUNCATE,
        "WLS-NGINX-PROCESS/2\n"
        "pid=%lu\n"
        "creation_time=%llu\n"
        "slot=%c\n"
        "runtime_generation=%s\n"
        "config_digest=%s\nconfig_path_digest=%s\nconfig_object=%s\n"
        "publication_generation=%llu\n"
        "broker_launch_receipt_digest=%s\n",
        (unsigned long)pid,
        wls_filetime_value(created),
        (char)active_slot[0],
        runtime_generation,
        publication.config_digest,
        publication.config_path_digest,
        publication.config_object,
        publication.generation,
        receipt_digest
    );
    return length > 0
        ? wls_atomic_bytes(path, payload, (DWORD)length)
        : 1;
}

static int wls_validate_nginx_process_identity(
    const wchar_t *home,
    DWORD pid,
    const FILETIME *created,
    const wchar_t *active_slot,
    const char *runtime_generation
) {
    wchar_t path[WLS_PATH_CHARS];
    char contents[1024];
    char slot[2];
    char runtime[65];
    char config_digest[65];
    char config_path_digest[65];
    char config_object[96];
    char receipt_digest[65];
    char actual_receipt_digest[65];
    struct wls_win_publication_identity publication;
    unsigned long parsed_pid = 0UL;
    unsigned long long parsed_created = 0ULL;
    unsigned long long publication_generation = 0ULL;
    size_t length = 0U;
    int consumed = 0;
    if (home == NULL || created == NULL || active_slot == NULL
        || runtime_generation == NULL
        || wls_join_w(
            path,
            WLS_PATH_CHARS,
            home,
            L"trust\\nginx-process.identity"
        ) != 0
        || wls_read_small_file(path, contents, sizeof(contents), &length) != 0
        || sscanf(
            contents,
            "WLS-NGINX-PROCESS/2\n"
            "pid=%lu\n"
            "creation_time=%llu\n"
            "slot=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "config_digest=%64[0-9a-f]\n"
            "config_path_digest=%64[0-9a-f]\n"
            "config_object=%95[0-9a-f-]\n"
            "publication_generation=%llu\n"
            "broker_launch_receipt_digest=%64[0-9a-f]\n%n",
            &parsed_pid,
            &parsed_created,
            slot,
            runtime,
            config_digest,
            config_path_digest,
            config_object,
            &publication_generation,
            receipt_digest,
            &consumed
        ) != 9
        || consumed != (int)length
        || parsed_pid != (unsigned long)pid
        || parsed_created != wls_filetime_value(created)
        || (wchar_t)slot[0] != active_slot[0]
        || strcmp(runtime, runtime_generation) != 0
        || wls_collect_publication_identity(home, &publication) != 0
        || strcmp(config_digest, publication.config_digest) != 0
        || strcmp(config_path_digest, publication.config_path_digest) != 0
        || strcmp(config_object, publication.config_object) != 0
        || publication_generation != publication.generation
        || wls_existing_broker_receipt_digest(
            home, actual_receipt_digest
        ) != 0
        || strcmp(receipt_digest, actual_receipt_digest) != 0) {
        return 1;
    }
    return 0;
}

static HANDLE wls_open_owned_nginx(
    const wchar_t *home,
    const wchar_t *active_slot,
    HANDLE controller,
    DWORD adopted_nginx_pid,
    const char *runtime_generation,
    DWORD *nginx_pid
) {
    wchar_t expected[WLS_PATH_CHARS];
    wchar_t actual[WLS_PATH_CHARS];
    DWORD actual_length = WLS_PATH_CHARS;
    DWORD pid = 0U;
    DWORD controller_pid;
    HANDLE process = NULL;
    HANDLE snapshot = INVALID_HANDLE_VALUE;
    PROCESSENTRY32W entry;
    int parent_matches = 0;
    int path_matches = 0;
    FILETIME controller_created;
    FILETIME controller_exited;
    FILETIME controller_kernel;
    FILETIME controller_user;
    FILETIME nginx_created;
    FILETIME nginx_exited;
    FILETIME nginx_kernel;
    FILETIME nginx_user;
    if (home == NULL || active_slot == NULL || controller == NULL || nginx_pid == NULL
        || runtime_generation == NULL
        || !wls_is_hex(runtime_generation, 64U)
        || (active_slot[0] != L'A' && active_slot[0] != L'B')
        || active_slot[1] != L'\0'
        || _snwprintf_s(expected, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\%ls\\bin\\nginx.exe", home, active_slot) < 0
        || wls_read_nginx_pid(home, &pid) != 0
        || (adopted_nginx_pid > 0U && adopted_nginx_pid != pid)) {
        return NULL;
    }
    controller_pid = GetProcessId(controller);
    if (controller_pid == 0U) return NULL;
    process = OpenProcess(
        SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION,
        FALSE,
        pid
    );
    if (process == NULL
        || !QueryFullProcessImageNameW(process, 0U, actual, &actual_length)) {
        if (process != NULL) CloseHandle(process);
        return NULL;
    }
    // An adopted process was already tied to this launcher Job and PID by the
    // stable launcher. It must still belong to the exact active immutable
    // slot; accepting either A/B lets an old executable pin the next inactive
    // slot forever and defeats runtime-generation fencing.
    path_matches = _wcsicmp(expected, actual) == 0;
    if (!path_matches) {
        CloseHandle(process);
        return NULL;
    }
    if (!GetProcessTimes(process, &nginx_created, &nginx_exited,
            &nginx_kernel, &nginx_user)) {
        CloseHandle(process);
        return NULL;
    }
    if (adopted_nginx_pid > 0U) {
        if (wls_validate_nginx_process_identity(
                home,
                pid,
                &nginx_created,
                active_slot,
                runtime_generation
            ) != 0) {
            CloseHandle(process);
            return NULL;
        }
        *nginx_pid = pid;
        return process;
    }
    snapshot = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0U);
    ZeroMemory(&entry, sizeof(entry));
    entry.dwSize = sizeof(entry);
    if (snapshot == INVALID_HANDLE_VALUE
        || !Process32FirstW(snapshot, &entry)) {
        if (snapshot != INVALID_HANDLE_VALUE) CloseHandle(snapshot);
        CloseHandle(process);
        return NULL;
    }
    do {
        if (entry.th32ProcessID == pid) {
            parent_matches = entry.th32ParentProcessID == controller_pid;
            break;
        }
    } while (Process32NextW(snapshot, &entry));
    CloseHandle(snapshot);
    if (!parent_matches
        || !GetProcessTimes(controller, &controller_created, &controller_exited,
            &controller_kernel, &controller_user)
        || CompareFileTime(&nginx_created, &controller_created) < 0
        || ((controller_exited.dwLowDateTime != 0U
                || controller_exited.dwHighDateTime != 0U)
            && CompareFileTime(&nginx_created, &controller_exited) > 0)) {
        CloseHandle(process);
        return NULL;
    }
    if (wls_write_nginx_process_identity(
            home,
            pid,
            &nginx_created,
            active_slot,
            runtime_generation
        ) != 0) {
        CloseHandle(process);
        return NULL;
    }
    *nginx_pid = pid;
    return process;
}

static int wls_reopen_owned_nginx(
    const wchar_t *home,
    const wchar_t *active_slot,
    HANDLE controller,
    DWORD nginx_pid,
    const char *runtime_generation,
    HANDLE *current_process
) {
    HANDLE verified_process;
    DWORD verified_pid = 0U;
    if (current_process == NULL || *current_process == NULL || nginx_pid == 0U) return 1;
    verified_process = wls_open_owned_nginx(
        home,
        active_slot,
        controller,
        nginx_pid,
        runtime_generation,
        &verified_pid
    );
    if (verified_process == NULL || verified_pid != nginx_pid) {
        if (verified_process != NULL) CloseHandle(verified_process);
        return 1;
    }
    CloseHandle(*current_process);
    *current_process = verified_process;
    return 0;
}

static HANDLE wls_start_controller(
    const wchar_t *php,
    const wchar_t *controller,
    const wchar_t *home,
    const wchar_t *fencing_file,
    unsigned short controller_port,
    HANDLE controller_token,
    const wchar_t *active_slot,
    const char *runtime_generation,
    const char *fencing,
    DWORD adopted_nginx_pid,
    DWORD *failure_stage
) {
    wchar_t command[WLS_PATH_CHARS * 3U];
    wchar_t controller_log[WLS_PATH_CHARS];
    wchar_t host_boot_id_wide[65];
    char host_boot_id[65];
    SECURITY_ATTRIBUTES inherited;
    HANDLE input = INVALID_HANDLE_VALUE;
    HANDLE output = INVALID_HANDLE_VALUE;
    STARTUPINFOEXW startup;
    PROCESS_INFORMATION process;
    SIZE_T attribute_size = 0U;
    LPPROC_THREAD_ATTRIBUTE_LIST attributes = NULL;
    HANDLE inherited_handles[2];
    BOOL created;
    if (failure_stage != NULL) *failure_stage = 0U;
    if (php == NULL || controller == NULL || home == NULL || fencing_file == NULL
        || controller_token == NULL || active_slot == NULL
        || runtime_generation == NULL || fencing == NULL
        || wcschr(php, L'"') != NULL || wcschr(controller, L'"') != NULL
        || wcschr(home, L'"') != NULL || wcschr(fencing_file, L'"') != NULL
        || wls_join_w(
            controller_log,
            WLS_PATH_CHARS,
            home,
            L"runtime\\logs\\controller-native.log"
        ) != 0
        || wls_upgrade_boot_id(host_boot_id) != 0
        || MultiByteToWideChar(
            CP_UTF8,
            MB_ERR_INVALID_CHARS,
            host_boot_id,
            -1,
            host_boot_id_wide,
            65
        ) <= 0) {
        if (failure_stage != NULL) *failure_stage = 1U;
        return NULL;
    }
    if (adopted_nginx_pid > 0U) {
        if (_snwprintf_s(
            command,
            sizeof(command) / sizeof(command[0]),
            _TRUNCATE,
            L"\"%ls\" \"%ls\" --home=\"%ls\" "
            L"--broker-internal=tcp://127.0.0.1:%hu "
            L"--broker-fencing-file=\"%ls\" "
            L"--host-boot-id=%ls "
            L"--broker-adopted-nginx-pid=%lu",
            php,
            controller,
            home,
            controller_port,
            fencing_file,
            host_boot_id_wide,
            (unsigned long)adopted_nginx_pid
        ) < 0) {
            if (failure_stage != NULL) *failure_stage = 2U;
            return NULL;
        }
    } else if (_snwprintf_s(
        command,
        sizeof(command) / sizeof(command[0]),
        _TRUNCATE,
        L"\"%ls\" \"%ls\" --home=\"%ls\" "
        L"--broker-internal=tcp://127.0.0.1:%hu "
        L"--broker-fencing-file=\"%ls\" "
        L"--host-boot-id=%ls",
        php,
        controller,
        home,
        controller_port,
        fencing_file,
        host_boot_id_wide
    ) < 0) {
        if (failure_stage != NULL) *failure_stage = 2U;
        return NULL;
    }
    ZeroMemory(&inherited, sizeof(inherited));
    inherited.nLength = sizeof(inherited);
    inherited.bInheritHandle = TRUE;
    input = CreateFileW(
        L"NUL",
        GENERIC_READ,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        &inherited,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL,
        NULL
    );
    output = CreateFileW(
        controller_log,
        GENERIC_WRITE,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        &inherited,
        OPEN_ALWAYS,
        FILE_ATTRIBUTE_NORMAL,
        NULL
    );
    if (input == INVALID_HANDLE_VALUE
        || output == INVALID_HANDLE_VALUE
        || SetFilePointer(output, 0L, NULL, FILE_END) == INVALID_SET_FILE_POINTER) {
        if (failure_stage != NULL) *failure_stage = 3U;
        if (input != INVALID_HANDLE_VALUE) CloseHandle(input);
        if (output != INVALID_HANDLE_VALUE) CloseHandle(output);
        return NULL;
    }
    ZeroMemory(&startup, sizeof(startup));
    ZeroMemory(&process, sizeof(process));
    startup.StartupInfo.cb = sizeof(startup);
    startup.StartupInfo.dwFlags = STARTF_USESTDHANDLES;
    startup.StartupInfo.hStdInput = input;
    startup.StartupInfo.hStdOutput = output;
    startup.StartupInfo.hStdError = output;
    inherited_handles[0] = input;
    inherited_handles[1] = output;
    if (InitializeProcThreadAttributeList(NULL, 1U, 0U, &attribute_size)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER
        || attribute_size == 0U) {
        if (failure_stage != NULL) *failure_stage = 4U;
        CloseHandle(input);
        CloseHandle(output);
        return NULL;
    }
    attributes = (LPPROC_THREAD_ATTRIBUTE_LIST)HeapAlloc(
        GetProcessHeap(),
        0U,
        attribute_size
    );
    if (attributes == NULL) {
        if (failure_stage != NULL) *failure_stage = 4U;
        CloseHandle(input);
        CloseHandle(output);
        return NULL;
    }
    if (!InitializeProcThreadAttributeList(
            attributes, 1U, 0U, &attribute_size
        )) {
        if (failure_stage != NULL) *failure_stage = 4U;
        HeapFree(GetProcessHeap(), 0U, attributes);
        CloseHandle(input);
        CloseHandle(output);
        return NULL;
    }
    if (!UpdateProcThreadAttribute(
            attributes,
            0U,
            PROC_THREAD_ATTRIBUTE_HANDLE_LIST,
            inherited_handles,
            sizeof(inherited_handles),
            NULL,
            NULL
        )) {
        if (failure_stage != NULL) *failure_stage = 4U;
        DeleteProcThreadAttributeList(attributes);
        HeapFree(GetProcessHeap(), 0U, attributes);
        CloseHandle(input);
        CloseHandle(output);
        return NULL;
    }
    startup.lpAttributeList = attributes;
    created = CreateProcessAsUserW(
        controller_token,
        php,
        command,
        NULL,
        NULL,
        TRUE,
        CREATE_NO_WINDOW | CREATE_UNICODE_ENVIRONMENT
            | EXTENDED_STARTUPINFO_PRESENT,
        NULL,
        NULL,
        &startup.StartupInfo,
        &process
    );
    DeleteProcThreadAttributeList(attributes);
    HeapFree(GetProcessHeap(), 0U, attributes);
    if (!created) {
        if (failure_stage != NULL) *failure_stage = 4U;
        CloseHandle(input);
        CloseHandle(output);
        return NULL;
    }
    if (wls_write_controller_process_identity(
            home,
            process.hProcess,
            active_slot,
            runtime_generation,
            fencing
        ) != 0) {
        if (failure_stage != NULL) *failure_stage = 5U;
        (void)TerminateProcess(process.hProcess, 1U);
        (void)WaitForSingleObject(process.hProcess, 5000U);
        CloseHandle(input);
        CloseHandle(output);
        CloseHandle(process.hThread);
        CloseHandle(process.hProcess);
        return NULL;
    }
    CloseHandle(input);
    CloseHandle(output);
    CloseHandle(process.hThread);
    return process.hProcess;
}

static int wls_wait_for_controller(
    unsigned short port,
    HANDLE controller,
    const wchar_t *home,
    const char *fencing
) {
    unsigned int attempt;
    for (attempt = 0U; attempt < WLS_CONTROLLER_START_ATTEMPTS; attempt++) {
        SOCKET probe;
        if (WaitForSingleObject(controller, 0U) == WAIT_OBJECT_0) {
            wchar_t log_path[WLS_PATH_CHARS];
            char message[96];
            DWORD exit_code = STILL_ACTIVE;
            HANDLE log = INVALID_HANDLE_VALUE;
            if (home != NULL
                && GetExitCodeProcess(controller, &exit_code)
                && wls_join_w(
                    log_path,
                    WLS_PATH_CHARS,
                    home,
                    L"runtime\\logs\\controller-native.log"
                ) == 0) {
                int length = _snprintf_s(
                    message,
                    sizeof(message),
                    _TRUNCATE,
                    "[native-broker] controller exited before readiness: %lu\r\n",
                    (unsigned long)exit_code
                );
                log = CreateFileW(
                    log_path,
                    FILE_APPEND_DATA,
                    FILE_SHARE_READ | FILE_SHARE_DELETE,
                    NULL,
                    OPEN_ALWAYS,
                    FILE_ATTRIBUTE_NORMAL,
                    NULL
                );
                if (log != INVALID_HANDLE_VALUE && length > 0) {
                    DWORD written = 0U;
                    (void)WriteFile(log, message, (DWORD)length, &written, NULL);
                }
                if (log != INVALID_HANDLE_VALUE) CloseHandle(log);
            }
            return 1;
        }
        probe = wls_connect_controller(
            port,
            WLS_CONTROLLER_IO_TIMEOUT_MS,
            fencing
        );
        if (probe != INVALID_SOCKET) {
            closesocket(probe);
            return 0;
        }
        Sleep(WLS_CONTROLLER_START_POLL_MS);
    }
    SetLastError(ERROR_TIMEOUT);
    return 1;
}

static void wls_wake_pipe(const wchar_t *pipe)
{
    HANDLE wake = CreateFileW(
        pipe,
        GENERIC_READ | GENERIC_WRITE,
        0U,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL,
        NULL
    );
    if (wake != INVALID_HANDLE_VALUE) CloseHandle(wake);
}

static const wchar_t *wls_argument(int argc, wchar_t **argv, const wchar_t *name)
{
    int index;
    for (index = 1; index + 1 < argc; index++) {
        if (wcscmp(argv[index], name) == 0) return argv[index + 1];
    }
    return NULL;
}

static int wls_self_test(void)
{
    char fencing[WLS_FENCING_BYTES * 2U + 1U];
    HMODULE kernel = GetModuleHandleW(L"kernel32.dll");
    HMODULE ntdll = GetModuleHandleW(L"ntdll.dll");
    if (kernel == NULL
        || ntdll == NULL
        || GetProcAddress(kernel, "GetNamedPipeClientProcessId") == NULL
        || GetProcAddress(ntdll, "NtCreateFile") == NULL
        || wls_fencing(fencing) != 0) {
        return 1;
    }
    return 0;
}

static int wls_snapshot_command(int argc, wchar_t **argv, int require_private)
{
    const wchar_t *source_root = wls_argument(argc, argv, L"--source-root");
    const wchar_t *source_relative = wls_argument(argc, argv, L"--source-relative");
    const wchar_t *destination_root = wls_argument(argc, argv, L"--destination-root");
    const wchar_t *destination_relative = wls_argument(
        argc,
        argv,
        L"--destination-relative"
    );
    if (source_root == NULL || source_relative == NULL
        || destination_root == NULL || destination_relative == NULL) {
        return 64;
    }
    return wls_snapshot(
        source_root,
        source_relative,
        destination_root,
        destination_relative,
        NULL,
        require_private,
        NULL,
        NULL
    );
}

int wmain(int argc, wchar_t **argv)
{
    const wchar_t *admin_pipe;
    const wchar_t *project_pipe;
    const wchar_t *fencing_file;
    const wchar_t *php;
    const wchar_t *controller_script;
    const wchar_t *home;
    const wchar_t *active_slot;
    const wchar_t *runtime_generation_wide;
    const wchar_t *stop_event_name;
    const wchar_t *ready_event_name;
    const wchar_t *adopted_nginx_pid_text;
    wchar_t expected_fencing[WLS_PATH_CHARS];
    char runtime_generation[65];
    char fencing[WLS_FENCING_BYTES * 2U + 1U];
    HANDLE singleton = NULL;
    HANDLE stop_event = NULL;
    HANDLE ready_event = NULL;
    HANDLE controller_token = NULL;
    HANDLE controller_process = NULL;
    HANDLE nginx_process = NULL;
    HANDLE threads[WLS_TOTAL_PIPE_INSTANCES];
    HANDLE maintenance_thread = NULL;
    HANDLE wait_handles[4U + WLS_TOTAL_PIPE_INSTANCES];
    struct wls_channel channels[2];
    struct wls_channel_instance instances[WLS_TOTAL_PIPE_INSTANCES];
    struct wls_bootstrap_maintenance_context bootstrap_maintenance_context;
    struct wls_bootstrap_receipt marker_health;
    struct wls_sid_gate sid_gate;
    WSADATA winsock;
    unsigned short controller_port = 0U;
    DWORD nginx_pid = 0U;
    DWORD adopted_nginx_pid = 0U;
    DWORD controller_start_stage = 0U;
    DWORD thread_count = 0U;
    DWORD created_instances = 0U;
    ULONGLONG observation_started;
    unsigned int observation_resets = 0U;
    int upgrade_mode = 0;
    int upgrade_marked = 0;
    int console_handler_registered = 0;
    int exit_code = 1;
    ZeroMemory(threads, sizeof(threads));
    ZeroMemory(instances, sizeof(instances));
    ZeroMemory(&bootstrap_maintenance_context, sizeof(bootstrap_maintenance_context));
    ZeroMemory(&marker_health, sizeof(marker_health));
    ZeroMemory(&sid_gate, sizeof(sid_gate));
    InitializeSRWLock(&sid_gate.lock);
    if (argc == 2 && wcscmp(argv[1], L"--self-test") == 0) {
        return wls_self_test();
    }
#if defined(WLS_NATIVE_TEST_HOOKS)
    if (argc > 1 && wcscmp(argv[1], L"--snapshot-private-test") == 0) {
        return wls_snapshot_command(argc, argv, 1);
    }
#endif
    if (argc > 1 && wcscmp(argv[1], L"--snapshot") == 0) {
        return wls_snapshot_command(argc, argv, 0);
    }
    if (argc <= 1 || wcscmp(argv[1], L"--serve") != 0) return 64;
    admin_pipe = wls_argument(argc, argv, L"--admin-pipe");
    project_pipe = wls_argument(argc, argv, L"--project-pipe");
    fencing_file = wls_argument(argc, argv, L"--fencing-file");
    php = wls_argument(argc, argv, L"--php");
    controller_script = wls_argument(argc, argv, L"--controller");
    home = wls_argument(argc, argv, L"--home");
    active_slot = wls_argument(argc, argv, L"--active-slot");
    runtime_generation_wide = wls_argument(argc, argv, L"--runtime-generation");
    stop_event_name = wls_argument(argc, argv, L"--stop-event");
    ready_event_name = wls_argument(argc, argv, L"--ready-event");
    adopted_nginx_pid_text = wls_argument(argc, argv, L"--adopted-nginx-pid");
    if (adopted_nginx_pid_text != NULL) {
        size_t index;
        size_t length = wcslen(adopted_nginx_pid_text);
        unsigned long long value = 0ULL;
        if (length == 0U || length > 10U) return 64;
        for (index = 0U; index < length; index++) {
            if (adopted_nginx_pid_text[index] < L'0'
                || adopted_nginx_pid_text[index] > L'9') return 64;
            value = value * 10ULL
                + (unsigned long long)(adopted_nginx_pid_text[index] - L'0');
            if (value > MAXDWORD) return 64;
        }
        adopted_nginx_pid = (DWORD)value;
    }
    if (admin_pipe == NULL || project_pipe == NULL || fencing_file == NULL
        || php == NULL || controller_script == NULL || home == NULL
        || active_slot == NULL || runtime_generation_wide == NULL
        || adopted_nginx_pid_text == NULL
        || !wls_valid_stop_event(stop_event_name)
        || !wls_valid_ready_event(ready_event_name)
        || (active_slot[0] != L'A' && active_slot[0] != L'B')
        || active_slot[1] != L'\0'
        || wcslen(runtime_generation_wide) != 64U
        || WideCharToMultiByte(
            CP_UTF8,
            WC_ERR_INVALID_CHARS,
            runtime_generation_wide,
            -1,
            runtime_generation,
            sizeof(runtime_generation),
            NULL,
            NULL
        ) <= 0
        || !wls_is_hex(runtime_generation, 64U)
        || wls_join_w(
            expected_fencing,
            WLS_PATH_CHARS,
            home,
            L"trust\\broker-fencing-token"
        ) != 0
        || _wcsicmp(expected_fencing, fencing_file) != 0) {
        return 64;
    }
    if (WSAStartup(MAKEWORD(2, 2), &winsock) != 0) return 70;
    singleton = CreateMutexW(NULL, TRUE, L"Global\\WelineWlsGatewayV2Broker");
    if (singleton == NULL || GetLastError() == ERROR_ALREADY_EXISTS || wls_fencing(fencing) != 0) {
        if (singleton != NULL) CloseHandle(singleton);
        WSACleanup();
        return 71;
    }
    stop_event = OpenEventW(
        SYNCHRONIZE | EVENT_MODIFY_STATE,
        FALSE,
        stop_event_name
    );
    if (stop_event == NULL) {
        CloseHandle(singleton);
        WSACleanup();
        return 72;
    }
    ready_event = OpenEventW(
        SYNCHRONIZE | EVENT_MODIFY_STATE,
        FALSE,
        ready_event_name
    );
    if (ready_event == NULL) {
        CloseHandle(stop_event);
        CloseHandle(singleton);
        WSACleanup();
        return 72;
    }
    wls_publish_stop_event(stop_event);
    if (SetConsoleCtrlHandler(wls_console_handler, TRUE)) {
        console_handler_registered = 1;
    }
    if (wls_allocate_controller_port(&controller_port) != 0) {
        exit_code = 73;
        goto cleanup;
    }
    if (wls_write_fencing_file(fencing_file, fencing) != 0) {
        exit_code = 74;
        goto cleanup;
    }
    if (wls_wait_previous_controller_exit(home, fencing) != 0) {
        wls_log_controller_event(
            home,
            "previous controller generation did not exit",
            GetLastError()
        );
        exit_code = 74;
        goto cleanup;
    }
    controller_token = wls_restricted_controller_token();
    if (controller_token == NULL) {
        exit_code = 75;
        goto cleanup;
    }
    controller_process = wls_start_controller(
        php,
        controller_script,
        home,
        fencing_file,
        controller_port,
        controller_token,
        active_slot,
        runtime_generation,
        fencing,
        adopted_nginx_pid,
        &controller_start_stage
    );
    if (controller_process == NULL) {
        exit_code = 75;
        goto cleanup;
    }
    if (wls_wait_for_controller(
            controller_port,
            controller_process,
            home,
            fencing
        ) != 0) {
        exit_code = 76;
        goto cleanup;
    }
    channels[0] = (struct wls_channel){
        admin_pipe,
        controller_port,
        L"admin",
        L"D:P(A;;GA;;;SY)(A;;GA;;;BA)",
        fencing,
        home,
        stop_event,
        WLS_ADMIN_PIPE_INSTANCES,
        0U,
        &sid_gate
    };
    channels[1] = (struct wls_channel){
        project_pipe,
        controller_port,
        L"project",
        L"D:P(A;;GA;;;SY)(A;;GRGW;;;AU)",
        fencing,
        home,
        stop_event,
        WLS_PROJECT_PIPE_INSTANCES,
        WLS_PROJECT_SID_ACTIVE_LIMIT,
        &sid_gate
    };
    {
        DWORD channel_index;
        for (channel_index = 0U; channel_index < 2U; channel_index++) {
            DWORD instance_index;
            for (
                instance_index = 0U;
                instance_index < channels[channel_index].instance_count;
                instance_index++
            ) {
                struct wls_channel_instance *instance =
                    &instances[created_instances];
                instance->channel = &channels[channel_index];
                instance->public_pipe = wls_create_public_pipe(
                    &channels[channel_index],
                    instance_index == 0U
                );
                if (instance->public_pipe == INVALID_HANDLE_VALUE) {
                    exit_code = 77;
                    goto cleanup;
                }
                created_instances++;
                threads[thread_count] = CreateThread(
                    NULL,
                    0U,
                    wls_channel_thread,
                    instance,
                    0U,
                    NULL
                );
                if (threads[thread_count] == NULL) {
                    exit_code = 77;
                    goto cleanup;
                }
                thread_count++;
            }
        }
    }
    bootstrap_maintenance_context.controller_port = controller_port;
    bootstrap_maintenance_context.fencing = fencing;
    bootstrap_maintenance_context.home = home;
    bootstrap_maintenance_context.stop_event = stop_event;
    bootstrap_maintenance_context.expected_slot[0] = (char)active_slot[0];
    bootstrap_maintenance_context.expected_slot[1] = '\0';
    strcpy_s(
        bootstrap_maintenance_context.expected_runtime_generation,
        sizeof(bootstrap_maintenance_context.expected_runtime_generation),
        runtime_generation
    );
    InitializeSRWLock(&bootstrap_maintenance_context.health_lock);
    if (wls_bootstrap_controller(
            controller_port,
            fencing,
            home,
            stop_event,
            &bootstrap_maintenance_context
        ) != 0) {
        exit_code = 76;
        goto cleanup;
    }
    maintenance_thread = CreateThread(
        NULL,
        0U,
        wls_bootstrap_maintenance_thread,
        &bootstrap_maintenance_context,
        0U,
        NULL
    );
    if (maintenance_thread == NULL) {
        exit_code = 76;
        goto cleanup;
    }
    nginx_process = wls_open_owned_nginx(
        home,
        active_slot,
        controller_process,
        adopted_nginx_pid,
        runtime_generation,
        &nginx_pid
    );
    if (nginx_process == NULL) {
        exit_code = 76;
        goto cleanup;
    }
    upgrade_mode = wls_prepare_upgrade_runtime(
        home,
        active_slot,
        runtime_generation,
        &observation_started
    );
    if (upgrade_mode < 0) {
        exit_code = 80;
        goto cleanup;
    }
    if (upgrade_mode > 0) {
        wls_bootstrap_health_arm(
            &bootstrap_maintenance_context,
            upgrade_mode,
            observation_started
        );
    }
    if (!SetEvent(ready_event)) {
        exit_code = 80;
        goto cleanup;
    }
    wait_handles[0] = controller_process;
    wait_handles[1] = stop_event;
    wait_handles[2] = nginx_process;
    wait_handles[3] = maintenance_thread;
    {
        DWORD index;
        for (index = 0U; index < thread_count; index++) {
            wait_handles[4U + index] = threads[index];
        }
    }
    for (;;) {
        DWORD wait = WaitForMultipleObjects(
            4U + thread_count,
            wait_handles,
            FALSE,
            1000U
        );
        if (wait == WAIT_OBJECT_0 + 1U) {
            exit_code = 0;
            break;
        }
        if (wait == WAIT_OBJECT_0) {
            DWORD controller_exit_code = 1U;
            unsigned int restart_attempt;
            int controller_restarted = 0;
            int controller_restart_failure = 78;
            (void)GetExitCodeProcess(controller_process, &controller_exit_code);
            if (controller_exit_code == 0U || controller_exit_code == 79U) {
                exit_code = (int)controller_exit_code;
                break;
            }
            if (upgrade_mode == 1) {
                // Candidate health is continuous. Exit so the stable launcher
                // records the bound attempt and creates a fresh PREPARED phase.
                exit_code = 83;
                break;
            }
            if (WaitForSingleObject(nginx_process, 0U) == WAIT_OBJECT_0) {
                exit_code = 79;
                break;
            }
            CloseHandle(controller_process);
            controller_process = NULL;
            for (restart_attempt = 0U; restart_attempt < 3U; restart_attempt++) {
                DWORD delay = restart_attempt == 0U
                    ? 250U
                    : (restart_attempt == 1U ? 1000U : 5000U);
                wls_log_controller_event(
                    home,
                    "controller restart attempt",
                    restart_attempt + 1U
                );
                if (WaitForSingleObject(stop_event, delay) == WAIT_OBJECT_0) {
                    exit_code = 0;
                    controller_restarted = -1;
                    break;
                }
                controller_process = wls_start_controller(
                    php,
                    controller_script,
                    home,
                    fencing_file,
                    controller_port,
                    controller_token,
                    active_slot,
                    runtime_generation,
                    fencing,
                    nginx_pid,
                    &controller_start_stage
                );
                if (controller_process == NULL) {
                    DWORD create_error = GetLastError();
                    controller_restart_failure = controller_start_stage >= 1U
                        && controller_start_stage <= 4U
                        ? (int)(84U + controller_start_stage)
                        : 89;
                    wls_log_controller_event(
                        home,
                        "controller CreateProcessAsUser failed",
                        create_error
                    );
                    continue;
                }
                if (wls_wait_for_controller(
                        controller_port,
                        controller_process,
                        home,
                        fencing
                    ) == 0
                    && wls_bootstrap_controller(
                        controller_port,
                        fencing,
                        home,
                        stop_event,
                        &bootstrap_maintenance_context
                    ) == 0
                    && wls_reopen_owned_nginx(
                        home,
                        active_slot,
                        controller_process,
                        nginx_pid,
                        runtime_generation,
                        &nginx_process
                    ) == 0) {
                    observation_resets++;
                    if (observation_resets
                        >= WLS_CONTROLLER_OBSERVATION_RESETS) {
                        controller_restart_failure = 83;
                        wls_log_controller_event(
                            home,
                            "controller observation reset limit reached",
                            observation_resets
                        );
                        break;
                    }
                    upgrade_mode = wls_prepare_upgrade_runtime(
                        home,
                        active_slot,
                        runtime_generation,
                        &observation_started
                    );
                    if (upgrade_mode < 0) {
                        controller_restart_failure = 80;
                        break;
                    }
                    if (upgrade_mode > 0) {
                        wls_bootstrap_health_arm(
                            &bootstrap_maintenance_context,
                            upgrade_mode,
                            observation_started
                        );
                    }
                    upgrade_marked = 0;
                    wait_handles[0] = controller_process;
                    wait_handles[2] = nginx_process;
                    controller_restarted = 1;
                    wls_log_controller_event(
                        home,
                        "controller restart ready",
                        GetProcessId(controller_process)
                    );
                    break;
                }
                controller_restart_failure = 81;
                wls_log_controller_event(
                    home,
                    "controller restart readiness failed",
                    GetLastError()
                );
                if (controller_process != NULL) {
                    if (WaitForSingleObject(controller_process, 0U) == WAIT_TIMEOUT) {
                        TerminateProcess(controller_process, 1U);
                    }
                    WaitForSingleObject(controller_process, 5000U);
                    CloseHandle(controller_process);
                    controller_process = NULL;
                }
            }
            if (controller_restarted < 0) break;
            if (controller_restarted == 0) {
                exit_code = controller_restart_failure;
                break;
            }
            continue;
        }
        if (wait == WAIT_OBJECT_0 + 2U) {
            DWORD controller_exit_code = 1U;
            if (WaitForSingleObject(controller_process, 2000U) == WAIT_OBJECT_0
                && GetExitCodeProcess(
                    controller_process,
                    &controller_exit_code
                )
                && controller_exit_code == 0U) {
                exit_code = 0;
            } else {
                exit_code = 79;
            }
            break;
        }
        if (wait == WAIT_OBJECT_0 + 3U) {
            exit_code = 82;
            break;
        }
        if (wait >= WAIT_OBJECT_0 + 4U
            && wait < WAIT_OBJECT_0 + 4U + thread_count) {
            exit_code = 82;
            break;
        }
        if (wait == WAIT_FAILED) {
            exit_code = 84;
            break;
        }
        if (upgrade_mode == 1
            && wls_bootstrap_observation_failed(
                &bootstrap_maintenance_context
            )) {
            exit_code = 83;
            break;
        }
        if (!upgrade_marked) {
            ULONGLONG now = GetTickCount64();
            ZeroMemory(&marker_health, sizeof(marker_health));
            if (upgrade_mode == 1
                && wls_bootstrap_health_ready(
                    &bootstrap_maintenance_context,
                    upgrade_mode,
                    now,
                    WLS_UPGRADE_OBSERVATION_MILLISECONDS,
                    &marker_health
                )
                && WaitForSingleObject(nginx_process, 0U) == WAIT_TIMEOUT
                && wls_write_upgrade_healthy(
                    home, active_slot, runtime_generation, observation_started
                ) == 0) {
                upgrade_marked = 1;
            } else if (upgrade_mode == 2
                && wls_bootstrap_health_ready(
                    &bootstrap_maintenance_context,
                    upgrade_mode,
                    now,
                    WLS_ROLLBACK_HEALTH_MILLISECONDS,
                    &marker_health
                )
                && WaitForSingleObject(nginx_process, 0U) == WAIT_TIMEOUT
                && wls_write_rollback_healthy(
                    home, active_slot, runtime_generation, observation_started, now
                ) == 0) {
                upgrade_marked = 1;
            }
        }
    }
cleanup:
    wls_signal_stop_event();
    {
        DWORD index;
        for (index = 0U; index < thread_count; index++) {
            (void)CancelSynchronousIo(threads[index]);
        }
        for (index = 0U; index < WLS_ADMIN_PIPE_INSTANCES; index++) {
            if (admin_pipe != NULL) wls_wake_pipe(admin_pipe);
        }
        for (index = 0U; index < WLS_PROJECT_PIPE_INSTANCES; index++) {
            if (project_pipe != NULL) wls_wake_pipe(project_pipe);
        }
        if (thread_count > 0U) {
            DWORD workers_wait = WaitForMultipleObjects(
                thread_count,
                threads,
                TRUE,
                5000U
            );
            if (workers_wait != WAIT_OBJECT_0) {
                wls_log_controller_event(
                    home,
                    "broker worker bounded stop failed",
                    workers_wait
                );
                ExitProcess(90U);
            }
        }
        for (index = thread_count; index < created_instances; index++) {
            if (instances[index].public_pipe != INVALID_HANDLE_VALUE) {
                CloseHandle(instances[index].public_pipe);
            }
        }
    }
    if (controller_process != NULL) {
        if (WaitForSingleObject(controller_process, 0U) == WAIT_TIMEOUT) {
            TerminateProcess(controller_process, exit_code == 0 ? 0U : 1U);
        }
        if (WaitForSingleObject(controller_process, 5000U) != WAIT_OBJECT_0) {
            wls_log_controller_event(
                home,
                "controller bounded stop failed",
                GetProcessId(controller_process)
            );
            ExitProcess(91U);
        }
        CloseHandle(controller_process);
    }
    if (maintenance_thread != NULL) {
        (void)CancelSynchronousIo(maintenance_thread);
        if (WaitForSingleObject(maintenance_thread, 5000U) != WAIT_OBJECT_0) {
            wls_log_controller_event(
                home,
                "maintenance bounded stop failed",
                GetThreadId(maintenance_thread)
            );
            ExitProcess(92U);
        }
        CloseHandle(maintenance_thread);
    }
    if (nginx_process != NULL) CloseHandle(nginx_process);
    if (controller_token != NULL) CloseHandle(controller_token);
    DeleteFileW(fencing_file);
    {
        DWORD index;
        for (index = 0U; index < thread_count; index++) {
            CloseHandle(threads[index]);
        }
    }
    if (console_handler_registered) {
        SetConsoleCtrlHandler(wls_console_handler, FALSE);
    }
    if (stop_event != NULL) {
        wls_unpublish_stop_event(stop_event);
        CloseHandle(stop_event);
    }
    if (ready_event != NULL) CloseHandle(ready_event);
    if (singleton != NULL) {
        ReleaseMutex(singleton);
        CloseHandle(singleton);
    }
    WSACleanup();
    SecureZeroMemory(fencing, sizeof(fencing));
    return exit_code;
}
