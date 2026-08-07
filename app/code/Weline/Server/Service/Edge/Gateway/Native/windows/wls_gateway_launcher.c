#define _CRT_SECURE_NO_WARNINGS
#include <windows.h>
#include <sddl.h>
#include <winternl.h>
#include <sodium.h>
#include <limits.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <wchar.h>

#ifndef WLS_RELEASE_PUBLIC_KEY_HEX
#error "WLS_RELEASE_PUBLIC_KEY_HEX must be defined by the release build"
#endif

#define WLS_PATH_CHARS 32768U
#define WLS_MAX_MANIFEST (4U * 1024U * 1024U)
#define WLS_CONTROL_TREE_RELOAD 254U
#define WLS_SERVICE_TREE_RESTART 79U
#define WLS_UPGRADE_ACTIVATION_SECONDS 300LL
#define WLS_UPGRADE_OBSERVATION_MILLISECONDS 300000ULL
#define WLS_UPGRADE_TOTAL_SECONDS 900LL
#define WLS_ROLLBACK_HEALTH_MILLISECONDS 15000ULL
#define WLS_SLOT_RETENTION_SECONDS 86400LL
#define WLS_SLOT_RETENTION_MILLISECONDS 86400000ULL
#define WLS_UPGRADE_MAX_ATTEMPTS 3U
#define WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS 30000ULL

struct wls_upgrade {
    int present;
    wchar_t from;
    wchar_t to;
    long long prepared_at;
    long long deadline;
    char runtime_generation[65];
    char nonce[33];
    char intent_sha256[65];
};

struct wls_upgrade_state {
    int present;
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
    long long total_deadline;
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

typedef NTSTATUS (NTAPI *wls_nt_query_system_information_fn)(
    SYSTEM_INFORMATION_CLASS,
    PVOID,
    ULONG,
    PULONG
);

static SERVICE_STATUS_HANDLE wls_status_handle = NULL;
static SERVICE_STATUS wls_service_status;
static HANDLE wls_broker_stop_event = NULL;
static SRWLOCK wls_broker_stop_event_lock = SRWLOCK_INIT;
static volatile LONG wls_service_stop_requested = 0;
static volatile LONG wls_service_reload_generation = 0;
static volatile LONG wls_service_reload_consumed = 0;
static volatile LONG wls_service_ready_reported = 0;
static DWORD wls_service_checkpoint = 0U;
static const wchar_t *wls_service_home = NULL;
static const wchar_t *wls_service_run = NULL;
static char wls_service_id[65];
static char wls_launcher_generation[65];

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

static void wls_report_service(
    DWORD state,
    DWORD win32_exit,
    DWORD service_specific_exit
);

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

static int wls_verify_release(
    const wchar_t *manifest_path,
    const wchar_t *signature_path,
    unsigned char **manifest,
    size_t *manifest_length
) {
    unsigned char public_key[crypto_sign_PUBLICKEYBYTES];
    unsigned char signature[crypto_sign_BYTES];
    unsigned char *signature_text = NULL;
    size_t signature_length = 0U;
    size_t decoded = 0U;
    int result = 1;
    if (wls_public_key(public_key) != 0
        || wls_read_file(
            signature_path,
            256U,
            &signature_text,
            &signature_length
        ) != 0
        || sodium_base642bin(
            signature,
            sizeof(signature),
            (const char *)signature_text,
            signature_length,
            "\r\n\t ",
            &decoded,
            NULL,
            sodium_base64_VARIANT_ORIGINAL
        ) != 0
        || decoded != crypto_sign_BYTES
        || wls_read_file(
            manifest_path,
            WLS_MAX_MANIFEST,
            manifest,
            manifest_length
        ) != 0
        || crypto_sign_verify_detached(
            signature,
            *manifest,
            (unsigned long long)*manifest_length,
            public_key
        ) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (signature_text != NULL) {
        sodium_memzero(signature_text, signature_length);
        HeapFree(GetProcessHeap(), 0U, signature_text);
    }
    if (result != 0 && *manifest != NULL) {
        HeapFree(GetProcessHeap(), 0U, *manifest);
        *manifest = NULL;
        *manifest_length = 0U;
    }
    sodium_memzero(public_key, sizeof(public_key));
    sodium_memzero(signature, sizeof(signature));
    return result;
}

static int wls_manifest_digest(
    const unsigned char *manifest,
    const char *component,
    char digest[65]
) {
    char needle[512];
    const char *start;
    const char *object_end;
    const char *sha;
    const char *quote;
    size_t length;
    if (_snprintf_s(
        needle,
        sizeof(needle),
        _TRUNCATE,
        "\"%s\"",
        component
    ) < 0) {
        return 1;
    }
    start = strstr((const char *)manifest, needle);
    object_end = start != NULL ? strchr(start, '}') : NULL;
    sha = start != NULL ? strstr(start, "\"sha256\"") : NULL;
    if (object_end == NULL || sha == NULL || sha > object_end) return 1;
    sha = strchr(sha, ':');
    quote = sha != NULL ? strchr(sha, '"') : NULL;
    if (quote == NULL || quote > object_end) return 1;
    quote++;
    length = strspn(quote, "0123456789abcdef");
    if (length != 64U || quote[length] != '"') return 1;
    memcpy(digest, quote, length);
    digest[length] = '\0';
    return 0;
}

static int wls_manifest_hex_value(
    const unsigned char *manifest,
    const char *field,
    char output[65]
) {
    char needle[128];
    const char *start;
    const char *colon;
    const char *quote;
    size_t length;
    if (_snprintf_s(
        needle,
        sizeof(needle),
        _TRUNCATE,
        "\"%s\"",
        field
    ) < 0) {
        return 1;
    }
    start = strstr((const char *)manifest, needle);
    colon = start != NULL ? strchr(start, ':') : NULL;
    quote = colon != NULL ? strchr(colon, '"') : NULL;
    if (quote == NULL) return 1;
    quote++;
    length = strspn(quote, "0123456789abcdef");
    if (length != 64U || quote[length] != '"') return 1;
    memcpy(output, quote, length);
    output[length] = '\0';
    return 0;
}

static int wls_file_digest(const wchar_t *path, char digest[65])
{
    HANDLE file;
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    unsigned char buffer[65536];
    DWORD amount;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    int result = 1;
    file = CreateFileW(
        path,
        GENERIC_READ,
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
        || (attributes.FileAttributes
            & (FILE_ATTRIBUTE_REPARSE_POINT | FILE_ATTRIBUTE_DIRECTORY)) != 0
        || crypto_hash_sha256_init(&state) != 0) {
        if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
        return 1;
    }
    for (;;) {
        if (!ReadFile(file, buffer, sizeof(buffer), &amount, NULL)) goto cleanup;
        if (amount == 0U) break;
        if (crypto_hash_sha256_update(&state, buffer, amount) != 0) goto cleanup;
    }
    if (crypto_hash_sha256_final(&state, binary) != 0) goto cleanup;
    sodium_bin2hex(digest, 65U, binary, sizeof(binary));
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

static int wls_verify_component(
    const unsigned char *manifest,
    const wchar_t *slot,
    const char *relative
) {
    char expected[65];
    char actual[65];
    wchar_t relative_wide[512];
    wchar_t path[WLS_PATH_CHARS];
    if (wls_manifest_digest(manifest, relative, expected) != 0
        || wls_utf8_to_wide(relative, relative_wide, 512U) != 0
        || wls_join(path, WLS_PATH_CHARS, slot, relative_wide) != 0
        || wls_file_digest(path, actual) != 0
        || sodium_memcmp(expected, actual, 64U) != 0) {
        return 1;
    }
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
        host,
        from,
        to,
        &upgrade->prepared_at,
        &upgrade->deadline,
        upgrade->runtime_generation,
        nonce,
        signature_hex,
        &consumed
    );
    signature_line = strstr((char *)intent, "signature=");
    if (fields != 8 || consumed != (int)intent_length || from[0] == to[0]
        || wls_checked_add_long_long(
            upgrade->prepared_at,
            WLS_UPGRADE_ACTIVATION_SECONDS,
            &expected_activation_deadline
        ) != 0
        || upgrade->deadline != expected_activation_deadline
        || upgrade->prepared_at > LLONG_MAX - WLS_UPGRADE_TOTAL_SECONDS
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
    unsigned long long monotonic_now = (unsigned long long)GetTickCount64();
    int consumed = 0;
    int result = 0;
    if (wls_join(path, WLS_PATH_CHARS, home, L"trust\\upgrade-healthy") == 0
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
    unsigned long long monotonic_now = (unsigned long long)GetTickCount64();
    int consumed = 0;
    int result = 0;
    if (deadline == NULL || started_out == NULL
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
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char boot_id[65];
    int result = 1;
    struct wls_platform_retirement_receipt receipt;
    ZeroMemory(&receipt, sizeof(receipt));
    if (home == NULL || runtime_generation == NULL
        || wls_join(
            path,
            WLS_PATH_CHARS,
            home,
            L"trust\\process-tree-retirement.receipt"
        ) != 0) return 1;
    if (wls_read_file(path, 2048U, &contents, &length) != 0) {
        DWORD error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
            ? 0
            : 1;
    }
    if (strncmp(
            (const char *)contents,
            "WLS-PROCESS-TREE-RETIRE/2\n",
            26U
        ) != 0) {
        result = 0;
        goto cleanup;
    }
    if (wls_parse_platform_retirement_v2(
            (const char *)contents,
            length,
            &receipt
        ) != 0) goto cleanup;
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
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
    }
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
    long long expected_total_deadline = 0;
    ZeroMemory(state, sizeof(*state));
    if (wls_join(path, WLS_PATH_CHARS, home, L"trust\\upgrade-state") != 0) return -1;
    if (wls_read_file(path, 1024U, &contents, &length) != 0) {
        DWORD error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND ? 0 : -1;
    }
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
        state->intent_sha256,
        state->nonce,
        from,
        to,
        state->runtime_generation,
        state->boot_id,
        state->phase,
        &state->attempts,
        &state->observation_started,
        &state->observation_deadline,
        &state->total_deadline,
        &consumed
    );
    HeapFree(GetProcessHeap(), 0U, contents);
    if (fields != 11 || consumed != (int)length
        || strcmp(state->intent_sha256, upgrade->intent_sha256) != 0
        || strcmp(state->nonce, upgrade->nonce) != 0
        || (wchar_t)from[0] != upgrade->from || (wchar_t)to[0] != upgrade->to
        || strcmp(state->runtime_generation, upgrade->runtime_generation) != 0
        || state->attempts > WLS_UPGRADE_MAX_ATTEMPTS
        || wls_checked_add_long_long(
            upgrade->prepared_at,
            WLS_UPGRADE_TOTAL_SECONDS,
            &expected_total_deadline
        ) != 0
        || state->total_deadline != expected_total_deadline
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
    char payload[640];
    int length;
    long long total_deadline = 0;
    unsigned long long expected_observation_deadline = 0ULL;
    if (attempts > WLS_UPGRADE_MAX_ATTEMPTS
        || phase == NULL
        || wls_checked_add_long_long(
            upgrade->prepared_at,
            WLS_UPGRADE_TOTAL_SECONDS,
            &total_deadline
        ) != 0
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
        "WLS-UPGRADE-STATE/2\n"
        "intent_sha256=%s\nintent_nonce=%s\n"
        "from=%c\nto=%c\nruntime_generation=%s\n"
        "boot_id=%s\nphase=%s\nattempts=%u\n"
        "observation_started_monotonic_ms=%llu\n"
        "observation_deadline_monotonic_ms=%llu\n"
        "total_deadline=%lld\n",
        upgrade->intent_sha256, upgrade->nonce,
        (char)upgrade->from, (char)upgrade->to,
        upgrade->runtime_generation, boot_id, phase, attempts,
        observation_started, observation_deadline,
        total_deadline
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
    unsigned long long monotonic_now = (unsigned long long)GetTickCount64();
    int consumed = 0;
    int result = 0;
    if (wls_join(path, WLS_PATH_CHARS, home,
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
    long long at = 0;
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
    if (sscanf(
            (const char *)contents,
            "WLS-UPGRADE-ROLLBACK/2\n"
            "intent_sha256=%64[0-9a-f]\n"
            "intent_nonce=%32[0-9a-f]\n"
            "from=%1[AB]\nto=%1[AB]\nat=%lld\n"
            "request_nonce=%32[0-9a-f]\n%n",
            intent_digest,
            intent_nonce,
            from,
            to,
            &at,
            request_nonce,
            &consumed
        ) == 6
        && consumed == (int)length
        && strcmp(intent_digest, upgrade->intent_sha256) == 0
        && strcmp(intent_nonce, upgrade->nonce) == 0
        && (wchar_t)from[0] == upgrade->to
        && (wchar_t)to[0] == upgrade->from
        && at > 0 && wls_is_hex(request_nonce, 32U)) {
        result = 1;
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
    unsigned long long monotonic_now = (unsigned long long)GetTickCount64();
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
    unsigned long long expected_observation_deadline = 0ULL;
    if (wls_active_slot(home, active) != 0) return 1;
    intent_status = wls_upgrade_intent(home, &upgrade);
    if (intent_status < 0) return 1;
    if (intent_status == 0 || !upgrade.present) return 0;
    if (now < 0 || wls_boot_id(boot_id) != 0
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
        if (active[0] == upgrade.from
            || strcmp(transaction.phase, "ROLLBACK_PENDING") == 0) {
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLBACK_PENDING",
                    attempts > WLS_UPGRADE_MAX_ATTEMPTS
                        ? WLS_UPGRADE_MAX_ATTEMPTS : attempts,
                    0ULL, 0ULL
                ) != 0) return 1;
            rollback_transitioned = 1;
        } else if (attempts >= WLS_UPGRADE_MAX_ATTEMPTS
            || now >= transaction.total_deadline) {
            must_rollback = 1;
        } else {
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "PREPARED", attempts, 0ULL, 0ULL
                ) != 0) return 1;
            (void)wls_delete_optional(observing_path);
            (void)wls_delete_optional(healthy_path);
        }
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return 1;
    }
    if (active[0] == upgrade.from) {
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
        if (wls_upgrade_state_write(
                home, &upgrade, boot_id, "PREPARED", 0U, 0ULL, 0ULL
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
    observation_present = wls_upgrade_observation_deadline(
        home, &upgrade, &transaction, boot_id,
        &observation_started, &observation_deadline
    );
    if (observation_present && strcmp(transaction.phase, "PREPARED") == 0) {
        if (wls_upgrade_state_write(
                home, &upgrade, boot_id, "OBSERVING", transaction.attempts,
                observation_started, observation_deadline
            ) != 0) return 1;
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return 1;
    }
    if (!rollback_requested
        && (strcmp(transaction.phase, "HEALTHY") == 0
            || wls_upgrade_healthy(home, &upgrade, &transaction, boot_id))) {
        if (strcmp(transaction.phase, "HEALTHY") != 0
            && wls_upgrade_state_write(
                home, &upgrade, boot_id, "HEALTHY", transaction.attempts,
                transaction.observation_started, transaction.observation_deadline
            ) != 0) return 1;
        retained_at = (long long)time(NULL);
        retained_since_monotonic = (unsigned long long)GetTickCount64();
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
    if (rollback_requested || now >= transaction.total_deadline) must_rollback = 1;
    if (must_rollback) {
        char active_text[3] = {(char)upgrade.from, '\n', '\0'};
        char previous_text[3] = {(char)upgrade.to, '\n', '\0'};
        attempts = transaction.attempts + (count_candidate_failure ? 1U : 0U);
        if (attempts > WLS_UPGRADE_MAX_ATTEMPTS) attempts = WLS_UPGRADE_MAX_ATTEMPTS;
        if (wls_upgrade_state_write(
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

static HANDLE wls_open_verified_job_nginx(
    const wchar_t *home,
    HANDLE job,
    DWORD expected_pid,
    const wchar_t *active_slot,
    const char *expected_runtime_generation,
    DWORD *verified_pid
) {
    wchar_t pid_path[WLS_PATH_CHARS];
    wchar_t actual[WLS_PATH_CHARS];
    wchar_t expected[WLS_PATH_CHARS];
    wchar_t slot_path[WLS_PATH_CHARS];
    wchar_t release_manifest_path[WLS_PATH_CHARS];
    wchar_t release_signature_path[WLS_PATH_CHARS];
    wchar_t installed_manifest_path[WLS_PATH_CHARS];
    unsigned char *pid_text = NULL;
    size_t pid_length = 0U;
    unsigned char *release_manifest = NULL;
    size_t release_manifest_length = 0U;
    unsigned char *installed_manifest = NULL;
    size_t installed_manifest_length = 0U;
    char runtime_generation[65];
    char *end = NULL;
    unsigned long long parsed;
    DWORD actual_length = WLS_PATH_CHARS;
    BOOL belongs = FALSE;
    HANDLE process = NULL;
    if (verified_pid == NULL
        || active_slot == NULL
        || (active_slot[0] != L'A' && active_slot[0] != L'B')
        || active_slot[1] != L'\0'
        || expected_runtime_generation == NULL
        || strlen(expected_runtime_generation) != 64U
        || wls_join(pid_path, WLS_PATH_CHARS, home, L"runtime\\run\\nginx.pid") != 0
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
        || !IsProcessInJob(process, job, &belongs)
        || !belongs
        || !QueryFullProcessImageNameW(process, 0U, actual, &actual_length)) {
        if (process != NULL) CloseHandle(process);
        return NULL;
    }
    if (_wcsicmp(actual, expected) != 0
        || _snwprintf_s(slot_path, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\%ls", home, active_slot) < 0
        || wls_join(release_manifest_path, WLS_PATH_CHARS,
            slot_path, L"release\\manifest.json") != 0
        || wls_join(release_signature_path, WLS_PATH_CHARS,
            slot_path, L"release\\manifest.sig") != 0
        || wls_join(installed_manifest_path, WLS_PATH_CHARS,
            slot_path, L"manifest.json") != 0
        || wls_verify_release(release_manifest_path, release_signature_path,
            &release_manifest, &release_manifest_length) != 0
        || wls_verify_component(release_manifest, slot_path, "bin/nginx.exe") != 0
        || wls_read_file(installed_manifest_path, WLS_MAX_MANIFEST,
            &installed_manifest, &installed_manifest_length) != 0
        || wls_manifest_hex_value(installed_manifest,
            "runtime_generation", runtime_generation) != 0
        || strcmp(runtime_generation, expected_runtime_generation) != 0) {
        if (release_manifest != NULL) HeapFree(GetProcessHeap(), 0U, release_manifest);
        if (installed_manifest != NULL) HeapFree(GetProcessHeap(), 0U, installed_manifest);
        CloseHandle(process);
        return NULL;
    }
    HeapFree(GetProcessHeap(), 0U, release_manifest);
    HeapFree(GetProcessHeap(), 0U, installed_manifest);
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

static int wls_launch(
    const wchar_t *home,
    const wchar_t *run_directory,
    HANDLE job,
    DWORD adopted_nginx_pid,
    DWORD *preserved_nginx_pid
)
{
    wchar_t active[2];
    wchar_t slot[WLS_PATH_CHARS];
    wchar_t release_manifest_path[WLS_PATH_CHARS];
    wchar_t release_signature_path[WLS_PATH_CHARS];
    wchar_t installed_manifest_path[WLS_PATH_CHARS];
    wchar_t broker[WLS_PATH_CHARS];
    wchar_t php[WLS_PATH_CHARS];
    wchar_t controller[WLS_PATH_CHARS];
    wchar_t fencing[WLS_PATH_CHARS];
    wchar_t stop_event_name[96];
    wchar_t ready_event_name[128];
    wchar_t ready_nonce_wide[33];
    wchar_t command[WLS_PATH_CHARS * 4U];
    unsigned char *release_manifest = NULL;
    size_t release_manifest_length = 0U;
    unsigned char *installed_manifest = NULL;
    size_t installed_manifest_length = 0U;
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
    DWORD broker_exit = 1U;
    DWORD verified_nginx_pid = 0U;
    LONG handled_reload_generation = 0;
    ULONGLONG shutdown_started = 0U;
    ULONGLONG startup_started = 0U;
    int reload_authorized = 0;
    int reload_request_observed = 0;
    int reload_failed = 0;
    int automatic_launch_allowed;
    int reconcile_result;
    wchar_t launched_slot;
    size_t ready_nonce_index;
    if (preserved_nginx_pid == NULL || job == NULL) return 1;
    *preserved_nginx_pid = 0U;
    if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) != 0) return 0;
    if (wls_admin_stopped(home) != 0) return 0;
    if (wls_active_slot(home, active) != 0) return 1;
    reconcile_result = wls_reconcile_upgrade(home, active, 0, 1);
    if (reconcile_result == 1 || reconcile_result == 2) return 1;
    launched_slot = active[0];
    if (_snwprintf_s(
            slot,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%ls",
            home,
            active
        ) < 0
        || wls_join(
            release_manifest_path,
            WLS_PATH_CHARS,
            slot,
            L"release\\manifest.json"
        ) != 0
        || wls_join(
            release_signature_path,
            WLS_PATH_CHARS,
            slot,
            L"release\\manifest.sig"
        ) != 0
        || wls_join(
            installed_manifest_path,
            WLS_PATH_CHARS,
            slot,
            L"manifest.json"
        ) != 0
        || wls_verify_release(
            release_manifest_path,
            release_signature_path,
            &release_manifest,
            &release_manifest_length
        ) != 0
        || wls_verify_component(
            release_manifest,
            slot,
            "bin/wls-gateway-broker.exe"
        ) != 0
        || wls_verify_component(release_manifest, slot, "bin/php.exe") != 0
        || wls_verify_component(release_manifest, slot, "bin/nginx.exe") != 0
        || wls_verify_component(
            release_manifest,
            slot,
            "app/controller.php"
        ) != 0
        || wls_read_file(
            installed_manifest_path,
            WLS_MAX_MANIFEST,
            &installed_manifest,
            &installed_manifest_length
        ) != 0
        || wls_manifest_hex_value(
            installed_manifest,
            "runtime_generation",
            runtime_generation
        ) != 0
        || wls_utf8_to_wide(
            runtime_generation,
            runtime_generation_wide,
            65U
        ) != 0
        || wls_join(broker, WLS_PATH_CHARS, slot, L"bin\\wls-gateway-broker.exe") != 0
        || wls_join(php, WLS_PATH_CHARS, slot, L"bin\\php.exe") != 0
        || wls_join(controller, WLS_PATH_CHARS, slot, L"app\\controller.php") != 0
        || wls_join(fencing, WLS_PATH_CHARS, home, L"trust\\broker-fencing-token") != 0) {
        if (release_manifest != NULL) HeapFree(GetProcessHeap(), 0U, release_manifest);
        if (installed_manifest != NULL) HeapFree(GetProcessHeap(), 0U, installed_manifest);
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
            sizeof(command) / sizeof(command[0]),
            _TRUNCATE,
            L"\"%ls\" --serve "
            L"--admin-pipe \"\\\\.\\pipe\\weline-wls-gateway-v2-admin\" "
            L"--project-pipe \"\\\\.\\pipe\\weline-wls-gateway-v2-project\" "
            L"--fencing-file \"%ls\" --php \"%ls\" --controller \"%ls\" "
            L"--home \"%ls\" --active-slot \"%ls\" --runtime-generation \"%ls\" "
            L"--stop-event \"%ls\" --ready-event \"%ls\" "
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
            (unsigned long)adopted_nginx_pid
        ) < 0) {
        if (release_manifest != NULL) HeapFree(GetProcessHeap(), 0U, release_manifest);
        if (installed_manifest != NULL) HeapFree(GetProcessHeap(), 0U, installed_manifest);
        return 1;
    }
    HeapFree(GetProcessHeap(), 0U, release_manifest);
    HeapFree(GetProcessHeap(), 0U, installed_manifest);
    if (wls_promote_platform_retirement(home, runtime_generation) != 0) {
        return 1;
    }
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
        return 1;
    }
    if (adopted_nginx_pid > 0U) {
        verified_nginx = wls_open_verified_job_nginx(
            home,
            job,
            adopted_nginx_pid,
            active,
            runtime_generation,
            &verified_nginx_pid
        );
        if (verified_nginx == NULL || verified_nginx_pid != adopted_nginx_pid) {
            if (verified_nginx != NULL) CloseHandle(verified_nginx);
            CloseHandle(stop_event);
            CloseHandle(ready_event);
            return 1;
        }
        CloseHandle(verified_nginx);
        verified_nginx = NULL;
    }
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
        return 1;
    }
    if (!AssignProcessToJobObject(job, process.hProcess)
        || ResumeThread(process.hThread) == (DWORD)-1) {
        (void)wls_force_terminate_process(process.hProcess, 1U);
        CloseHandle(process.hThread);
        CloseHandle(process.hProcess);
        CloseHandle(stop_event);
        CloseHandle(ready_event);
        return 1;
    }
    CloseHandle(process.hThread);
    wls_publish_broker_stop_event(stop_event);
    startup_started = GetTickCount64();
    for (;;) {
        DWORD wait_result = WaitForSingleObject(process.hProcess, 200U);
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
            int broker_ready = WaitForSingleObject(ready_event, 0U)
                == WAIT_OBJECT_0;
            // A v1 Broker cannot prove publication/config identity and must
            // never make SCM report RUNNING merely because a pipe and an
            // Nginx PID exist. The bounded timeout below forces a clean v2
            // rebootstrap instead of adopting unbound compatibility state.
            if (broker_ready) {
                InterlockedExchange(&wls_service_ready_reported, 1);
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
    if (broker_exit == WLS_SERVICE_TREE_RESTART
        && wls_seal_platform_retirement_pending(
            home,
            runtime_generation
        ) != 0) {
        OutputDebugStringW(
            L"WLS Gateway could not seal pending process-tree retirement.\n"
        );
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
    return broker_exit == 0U
        ? 0
        : (broker_exit <= 255U ? (int)broker_exit : 1);
}

static void wls_report_service(
    DWORD state,
    DWORD win32_exit,
    DWORD service_specific_exit
)
{
    wls_service_status.dwServiceType = SERVICE_WIN32_OWN_PROCESS;
    wls_service_status.dwCurrentState = state;
    wls_service_status.dwControlsAccepted = state == SERVICE_RUNNING
        ? SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN | SERVICE_ACCEPT_PARAMCHANGE
        : 0U;
    wls_service_status.dwWin32ExitCode = win32_exit;
    wls_service_status.dwServiceSpecificExitCode = service_specific_exit;
    wls_service_status.dwCheckPoint =
        state == SERVICE_START_PENDING || state == SERVICE_STOP_PENDING
            ? ++wls_service_checkpoint
            : 0U;
    wls_service_status.dwWaitHint =
        state == SERVICE_START_PENDING || state == SERVICE_STOP_PENDING ? 30000U : 0U;
    if (wls_status_handle != NULL) {
        SetServiceStatus(wls_status_handle, &wls_service_status);
    }
}

static DWORD WINAPI wls_service_control(
    DWORD control,
    DWORD event_type,
    LPVOID event_data,
    LPVOID context
) {
    (void)event_type;
    (void)event_data;
    (void)context;
    if (control == SERVICE_CONTROL_STOP || control == SERVICE_CONTROL_SHUTDOWN) {
        /* Publish stop ownership before Broker exit can be classified. */
        InterlockedExchange(&wls_service_stop_requested, 1);
        wls_report_service(SERVICE_STOP_PENDING, NO_ERROR, 0U);
        wls_signal_broker_stop_event();
    } else if (control == SERVICE_CONTROL_PARAMCHANGE
        && InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0) {
        InterlockedIncrement(&wls_service_reload_generation);
        wls_signal_broker_stop_event();
    }
    return NO_ERROR;
}

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

static int wls_run_supervisor(const wchar_t *home, const wchar_t *run_directory)
{
    HANDLE job = wls_create_supervision_job();
    DWORD adopted_nginx_pid = 0U;
    int result = 1;
    if (job == NULL) return 1;
    do {
        DWORD preserved_nginx_pid = 0U;
        result = wls_launch(
            home,
            run_directory,
            job,
            adopted_nginx_pid,
            &preserved_nginx_pid
        );
        adopted_nginx_pid = preserved_nginx_pid;
    } while (result == (int)WLS_CONTROL_TREE_RELOAD
        && InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0);
    CloseHandle(job);
    if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) != 0) {
        return 0;
    }
    return result == (int)WLS_CONTROL_TREE_RELOAD ? 1 : result;
}

static VOID WINAPI wls_service_main(DWORD argc, LPWSTR *argv)
{
    int result;
    (void)argc;
    (void)argv;
    wls_status_handle = RegisterServiceCtrlHandlerExW(
        L"weline-wls-gateway-v2",
        wls_service_control,
        NULL
    );
    if (wls_status_handle == NULL) return;
    InterlockedExchange(&wls_service_ready_reported, 0);
    wls_service_checkpoint = 0U;
    wls_report_service(SERVICE_START_PENDING, NO_ERROR, 0U);
    result = wls_run_supervisor(wls_service_home, wls_service_run);
    wls_report_service(
        SERVICE_STOPPED,
        result == 0 ? NO_ERROR : ERROR_SERVICE_SPECIFIC_ERROR,
        result == 0 ? 0U : (DWORD)result
    );
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

int wmain(int argc, wchar_t **argv)
{
    const wchar_t *home;
    const wchar_t *run_directory;
    unsigned char public_key[crypto_sign_PUBLICKEYBYTES];
    int service_mode = 0;
    int index;
    if (sodium_init() < 0 || wls_public_key(public_key) != 0) return 1;
    sodium_memzero(public_key, sizeof(public_key));
    if (argc == 2 && wcscmp(argv[1], L"--self-test") == 0) {
        if (wls_classify_broker_exit(0U, 0, 1, 0) != 1U
            || wls_classify_broker_exit(WLS_CONTROL_TREE_RELOAD, 0, 1, 0) != 1U
            || wls_classify_broker_exit(7U, 0, 1, 0) != 7U
            || wls_classify_broker_exit(7U, 1, 1, 0) != 0U
            || wls_classify_broker_exit(7U, 0, 0, 0) != 0U
            || wls_classify_broker_exit(7U, 0, 1, 1)
                != WLS_CONTROL_TREE_RELOAD) {
            return 1;
        }
        return 0;
    }
    for (index = 1; index < argc; index++) {
        if (wcscmp(argv[index], L"--service") == 0) service_mode = 1;
    }
    home = wls_argument(argc, argv, L"--home");
    run_directory = wls_argument(argc, argv, L"--run");
    if (!wls_absolute_windows_path(home)
        || !wls_absolute_windows_path(run_directory)) {
        return 64;
    }
    if (service_mode) {
        SERVICE_TABLE_ENTRYW dispatch[] = {
            {L"weline-wls-gateway-v2", wls_service_main},
            {NULL, NULL}
        };
        wls_service_home = home;
        wls_service_run = run_directory;
        if (wls_initialize_service_generation() != 0) return 1;
        return StartServiceCtrlDispatcherW(dispatch) ? 0 : 1;
    }
    return wls_run_supervisor(home, run_directory);
}
