#ifndef WIN32_LEAN_AND_MEAN
#define WIN32_LEAN_AND_MEAN
#endif
#include <windows.h>
#include <aclapi.h>
#include <bcrypt.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <wchar.h>

#if !defined(WLS_NAMED_PIPE_DEADLINE_TRANSPORT) \
    || WLS_NAMED_PIPE_DEADLINE_TRANSPORT != 1
#error "wls-bounded-command must include the native named-pipe deadline transport"
#endif

#define WLS_CAPTURE_LIMIT ((SIZE_T)262144U)
#define WLS_CAPTURE_CHUNK ((DWORD)8192U)
#define WLS_MAX_NATIVE_PATH ((SIZE_T)240U)
#define WLS_MAX_COMMAND_LINE ((SIZE_T)32760U)
#define WLS_MAX_ARGUMENTS 4096
#define WLS_MAX_PATH_HANDLES 128
#define WLS_MAX_TIMEOUT_MS ((ULONGLONG)3600000U)
#define WLS_TERMINATION_GRACE_MS ((DWORD)2000U)
#define WLS_RESULT_PUBLICATION_GRACE_MS ((ULONGLONG)2000U)
#define WLS_EXIT_USAGE 64
#define WLS_EXIT_INTERNAL 70
#define WLS_EXIT_FILESYSTEM 71
#define WLS_EXIT_TRANSPORT 72
#define WLS_EXIT_ORPHAN_BUSY 73
#define WLS_EXIT_ORPHAN_UNSAFE 74
#define WLS_EXIT_TIMEOUT 124
#define WLS_PIPE_MAX_FRAME_BYTES ((SIZE_T)4194304U)
#define WLS_PIPE_IO_CHUNK ((DWORD)65536U)
#define WLS_PIPE_MAX_CONNECT_ATTEMPTS ((DWORD)4096U)
#define WLS_PIPE_MAX_READ_OPERATIONS ((DWORD)8192U)
#define WLS_PIPE_MAX_WRITE_OPERATIONS ((DWORD)8192U)
#define WLS_PIPE_RESULT_SCHEMA "wls-named-pipe-exchange-result/1"
#define WLS_PIPE_ORPHAN_MINIMUM_AGE_MS ((ULONGLONG)30000U)
#define WLS_PIPE_ADMIN_PATH L"\\\\.\\pipe\\weline-wls-gateway-v2-admin"
#define WLS_PIPE_PROJECT_PATH L"\\\\.\\pipe\\weline-wls-gateway-v2-project"

typedef struct WLS_PATH_GUARD {
    HANDLE handles[WLS_MAX_PATH_HANDLES];
    SIZE_T count;
} WLS_PATH_GUARD;

typedef struct WLS_CAPTURE_SHARED {
    CRITICAL_SECTION lock;
    SIZE_T total;
    BOOL truncated;
} WLS_CAPTURE_SHARED;

typedef struct WLS_CAPTURE_CHANNEL {
    HANDLE pipe;
    BYTE *buffer;
    SIZE_T length;
    WLS_CAPTURE_SHARED *shared;
    volatile LONG io_error;
} WLS_CAPTURE_CHANNEL;

typedef struct WLS_OPTIONS {
    const wchar_t *result_dir;
    const wchar_t *working_dir;
    ULONGLONG timeout_ms;
    int command_index;
} WLS_OPTIONS;

typedef struct WLS_PIPE_IO {
    OVERLAPPED overlapped;
    HANDLE event;
} WLS_PIPE_IO;

static void wls_print_error(const wchar_t *message, DWORD error)
{
    (void)fwprintf(stderr, L"%ls (win32=%lu)\n", message, (unsigned long)error);
}

static void wls_close_handle(HANDLE *handle)
{
    if (handle != NULL && *handle != NULL && *handle != INVALID_HANDLE_VALUE) {
        (void)CloseHandle(*handle);
        *handle = NULL;
    }
}

static void wls_guard_close(WLS_PATH_GUARD *guard)
{
    SIZE_T index;

    if (guard == NULL) {
        return;
    }
    for (index = guard->count; index > 0U; --index) {
        wls_close_handle(&guard->handles[index - 1U]);
    }
    guard->count = 0U;
}

static BOOL wls_is_ascii_drive_letter(wchar_t value)
{
    return (value >= L'A' && value <= L'Z') || (value >= L'a' && value <= L'z');
}

static BOOL wls_drive_is_fixed_volume(wchar_t drive_letter)
{
    wchar_t root[4];
    wchar_t drive[3];
    wchar_t mapping[WLS_MAX_NATIVE_PATH];
    DWORD length;

    root[0] = drive_letter;
    root[1] = L':';
    root[2] = L'\\';
    root[3] = L'\0';
    drive[0] = drive_letter;
    drive[1] = L':';
    drive[2] = L'\0';
    if (GetDriveTypeW(root) != DRIVE_FIXED) {
        return FALSE;
    }
    length = QueryDosDeviceW(drive, mapping, (DWORD)WLS_MAX_NATIVE_PATH);
    if (length == 0U || (SIZE_T)length >= WLS_MAX_NATIVE_PATH) {
        return FALSE;
    }
    return _wcsnicmp(mapping, L"\\Device\\HarddiskVolume", 22U) == 0;
}

static BOOL wls_component_is_safe(const wchar_t *start, SIZE_T length)
{
    SIZE_T index;

    if (length == 0U || start[length - 1U] == L'.' || start[length - 1U] == L' ') {
        return FALSE;
    }
    if ((length == 1U && start[0] == L'.')
        || (length == 2U && start[0] == L'.' && start[1] == L'.')) {
        return FALSE;
    }
    for (index = 0U; index < length; ++index) {
        wchar_t value = start[index];
        if (value < 32 || value == L':' || value == L'/' || value == L'"'
            || value == L'<' || value == L'>' || value == L'|' || value == L'*'
            || value == L'?') {
            return FALSE;
        }
    }
    return TRUE;
}

static BOOL wls_normalize_local_path(
    const wchar_t *input,
    wchar_t *output,
    SIZE_T output_count,
    BOOL allow_root
)
{
    DWORD length;
    SIZE_T input_length;
    SIZE_T index;
    SIZE_T component_start;

    if (input == NULL || output == NULL || output_count < 4U || input[0] == L'\0') {
        return FALSE;
    }
    input_length = wcslen(input);
    if (input_length >= WLS_MAX_NATIVE_PATH || input_length >= output_count
        || !wls_is_ascii_drive_letter(input[0]) || input[1] != L':' || input[2] != L'\\'
        || input[3] == L'\\' || input[0] == L'\\') {
        return FALSE;
    }
    length = GetFullPathNameW(input, (DWORD)output_count, output, NULL);
    if (length == 0U || (SIZE_T)length >= output_count || (SIZE_T)length >= WLS_MAX_NATIVE_PATH) {
        return FALSE;
    }
    if ((SIZE_T)length > 3U && output[length - 1U] == L'\\') {
        output[length - 1U] = L'\0';
        --length;
    }
    if (_wcsicmp(input, output) != 0 || (!allow_root && (SIZE_T)length <= 3U)) {
        return FALSE;
    }
    if (!wls_drive_is_fixed_volume(output[0])) {
        return FALSE;
    }
    if ((SIZE_T)length == 3U) {
        return allow_root;
    }
    component_start = 3U;
    for (index = 3U; index <= (SIZE_T)length; ++index) {
        if (output[index] == L'\\' || output[index] == L'\0') {
            if (!wls_component_is_safe(
                output + component_start,
                index - component_start
            )) {
                return FALSE;
            }
            component_start = index + 1U;
        }
    }
    return TRUE;
}

static BOOL wls_open_guarded_component(
    WLS_PATH_GUARD *guard,
    const wchar_t *path,
    BOOL expect_directory
)
{
    HANDLE handle;
    BY_HANDLE_FILE_INFORMATION information;

    if (guard == NULL || guard->count >= WLS_MAX_PATH_HANDLES) {
        SetLastError(ERROR_BUFFER_OVERFLOW);
        return FALSE;
    }
    handle = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (handle == INVALID_HANDLE_VALUE) {
        return FALSE;
    }
    if (!GetFileInformationByHandle(handle, &information)
        || (information.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || (((information.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U)
            != expect_directory)) {
        DWORD error = GetLastError();
        (void)CloseHandle(handle);
        SetLastError(error == ERROR_SUCCESS ? ERROR_REPARSE_TAG_MISMATCH : error);
        return FALSE;
    }
    guard->handles[guard->count] = handle;
    ++guard->count;
    return TRUE;
}

static BOOL wls_guard_existing_path(
    const wchar_t *path,
    BOOL leaf_is_directory,
    WLS_PATH_GUARD *guard
)
{
    wchar_t prefix[WLS_MAX_NATIVE_PATH];
    SIZE_T length;
    SIZE_T cursor;

    if (path == NULL || guard == NULL) {
        SetLastError(ERROR_INVALID_PARAMETER);
        return FALSE;
    }
    (void)memset(guard, 0, sizeof(*guard));
    length = wcslen(path);
    if (length < 3U || length >= WLS_MAX_NATIVE_PATH) {
        SetLastError(ERROR_INVALID_NAME);
        return FALSE;
    }
    prefix[0] = path[0];
    prefix[1] = L':';
    prefix[2] = L'\\';
    prefix[3] = L'\0';
    if (!wls_open_guarded_component(guard, prefix, TRUE)) {
        return FALSE;
    }
    cursor = 3U;
    while (cursor < length) {
        const wchar_t *separator = wcschr(path + cursor, L'\\');
        SIZE_T prefix_length = separator == NULL
            ? length
            : (SIZE_T)(separator - path);
        BOOL directory = separator != NULL || leaf_is_directory;

        if (prefix_length >= WLS_MAX_NATIVE_PATH) {
            SetLastError(ERROR_BUFFER_OVERFLOW);
            wls_guard_close(guard);
            return FALSE;
        }
        (void)memcpy(prefix, path, prefix_length * sizeof(wchar_t));
        prefix[prefix_length] = L'\0';
        if (!wls_open_guarded_component(guard, prefix, directory)) {
            wls_guard_close(guard);
            return FALSE;
        }
        if (separator == NULL) {
            break;
        }
        cursor = prefix_length + 1U;
    }
    return TRUE;
}

static BOOL wls_parent_path(
    const wchar_t *path,
    wchar_t *parent,
    SIZE_T parent_count
)
{
    const wchar_t *separator;
    SIZE_T length;

    if (path == NULL || parent == NULL) {
        return FALSE;
    }
    separator = wcsrchr(path, L'\\');
    if (separator == NULL) {
        return FALSE;
    }
    length = (SIZE_T)(separator - path);
    if (length == 2U) {
        length = 3U;
    }
    if (length >= parent_count) {
        return FALSE;
    }
    (void)memcpy(parent, path, length * sizeof(wchar_t));
    parent[length] = L'\0';
    return TRUE;
}

static BOOL wls_create_private_directory(const wchar_t *path)
{
    HANDLE token = NULL;
    PTOKEN_USER token_user = NULL;
    DWORD token_bytes = 0U;
    BYTE system_sid[SECURITY_MAX_SID_SIZE];
    DWORD system_sid_bytes = (DWORD)sizeof(system_sid);
    EXPLICIT_ACCESSW entries[2];
    PACL acl = NULL;
    SECURITY_DESCRIPTOR descriptor;
    SECURITY_ATTRIBUTES attributes;
    BOOL created = FALSE;

    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &token)) {
        return FALSE;
    }
    (void)GetTokenInformation(token, TokenUser, NULL, 0U, &token_bytes);
    if (GetLastError() != ERROR_INSUFFICIENT_BUFFER || token_bytes == 0U) {
        goto cleanup;
    }
    token_user = (PTOKEN_USER)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, token_bytes);
    if (token_user == NULL
        || !GetTokenInformation(token, TokenUser, token_user, token_bytes, &token_bytes)
        || !IsValidSid(token_user->User.Sid)
        || !CreateWellKnownSid(WinLocalSystemSid, NULL, system_sid, &system_sid_bytes)) {
        goto cleanup;
    }

    (void)memset(entries, 0, sizeof(entries));
    entries[0].grfAccessPermissions = FILE_ALL_ACCESS;
    entries[0].grfAccessMode = SET_ACCESS;
    entries[0].grfInheritance = SUB_CONTAINERS_AND_OBJECTS_INHERIT;
    BuildTrusteeWithSidW(&entries[0].Trustee, token_user->User.Sid);
    entries[1].grfAccessPermissions = FILE_ALL_ACCESS;
    entries[1].grfAccessMode = SET_ACCESS;
    entries[1].grfInheritance = SUB_CONTAINERS_AND_OBJECTS_INHERIT;
    BuildTrusteeWithSidW(&entries[1].Trustee, system_sid);
    if (SetEntriesInAclW(2U, entries, NULL, &acl) != ERROR_SUCCESS
        || !InitializeSecurityDescriptor(&descriptor, SECURITY_DESCRIPTOR_REVISION)
        || !SetSecurityDescriptorOwner(&descriptor, token_user->User.Sid, FALSE)
        || !SetSecurityDescriptorDacl(&descriptor, TRUE, acl, FALSE)
        || !SetSecurityDescriptorControl(
            &descriptor,
            SE_DACL_PROTECTED,
            SE_DACL_PROTECTED
        )) {
        goto cleanup;
    }
    attributes.nLength = (DWORD)sizeof(attributes);
    attributes.lpSecurityDescriptor = &descriptor;
    attributes.bInheritHandle = FALSE;
    created = CreateDirectoryW(path, &attributes);

cleanup:
    if (acl != NULL) {
        (void)LocalFree(acl);
    }
    if (token_user != NULL) {
        (void)HeapFree(GetProcessHeap(), 0U, token_user);
    }
    wls_close_handle(&token);
    return created;
}

static BOOL wls_private_handle_acl_exact(HANDLE handle, BOOL directory)
{
    HANDLE token = NULL;
    PTOKEN_USER token_user = NULL;
    DWORD token_bytes = 0U;
    BYTE system_sid[SECURITY_MAX_SID_SIZE];
    DWORD system_sid_bytes = (DWORD)sizeof(system_sid);
    PSID owner = NULL;
    PACL dacl = NULL;
    PSECURITY_DESCRIPTOR descriptor = NULL;
    SECURITY_DESCRIPTOR_CONTROL control = 0U;
    DWORD revision = 0U;
    DWORD status;
    DWORD index;
    BOOL current_seen = FALSE;
    BOOL system_seen = FALSE;
    BOOL same_principal = FALSE;
    BOOL exact = FALSE;

    if (handle == NULL || handle == INVALID_HANDLE_VALUE
        || !OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &token)) {
        return FALSE;
    }
    (void)GetTokenInformation(token, TokenUser, NULL, 0U, &token_bytes);
    if (GetLastError() != ERROR_INSUFFICIENT_BUFFER || token_bytes == 0U) {
        goto cleanup;
    }
    token_user = (PTOKEN_USER)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, token_bytes
    );
    if (token_user == NULL
        || !GetTokenInformation(
            token, TokenUser, token_user, token_bytes, &token_bytes
        )
        || !IsValidSid(token_user->User.Sid)
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_sid, &system_sid_bytes
        )) goto cleanup;
    status = GetSecurityInfo(
        handle,
        SE_FILE_OBJECT,
        OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
        &owner,
        NULL,
        &dacl,
        NULL,
        &descriptor
    );
    if (status != ERROR_SUCCESS || descriptor == NULL
        || owner == NULL || !EqualSid(owner, token_user->User.Sid)
        || dacl == NULL
        || !GetSecurityDescriptorControl(descriptor, &control, &revision)
        || (control & SE_DACL_PROTECTED) == 0U) goto cleanup;
    same_principal = EqualSid(token_user->User.Sid, system_sid);
    if ((!same_principal && dacl->AceCount != 2U)
        || (same_principal
            && (dacl->AceCount < 1U || dacl->AceCount > 2U))) goto cleanup;
    for (index = 0U; index < (DWORD)dacl->AceCount; ++index) {
        ACCESS_ALLOWED_ACE *ace = NULL;
        PSID sid;
        BYTE expected_flags = directory
            ? (BYTE)(OBJECT_INHERIT_ACE | CONTAINER_INHERIT_ACE)
            : 0U;
        if (!GetAce(dacl, index, (void **)&ace)
            || ace == NULL
            || ace->Header.AceType != ACCESS_ALLOWED_ACE_TYPE
            || ace->Header.AceFlags != expected_flags
            || ace->Mask != FILE_ALL_ACCESS) goto cleanup;
        sid = (PSID)(void *)&ace->SidStart;
        if (EqualSid(sid, token_user->User.Sid)) current_seen = TRUE;
        if (EqualSid(sid, system_sid)) system_seen = TRUE;
        if (!EqualSid(sid, token_user->User.Sid)
            && !EqualSid(sid, system_sid)) goto cleanup;
    }
    exact = current_seen && system_seen;
cleanup:
    if (descriptor != NULL) (void)LocalFree(descriptor);
    if (token_user != NULL) {
        SecureZeroMemory(token_user, token_bytes);
        (void)HeapFree(GetProcessHeap(), 0U, token_user);
    }
    wls_close_handle(&token);
    return exact;
}

static BOOL wls_private_path_acl_exact(
    const wchar_t *path,
    BOOL directory
) {
    HANDLE handle;
    BY_HANDLE_FILE_INFORMATION information;
    BOOL exact;
    handle = CreateFileW(
        path,
        FILE_READ_ATTRIBUTES | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_OPEN_REPARSE_POINT
            | (directory ? FILE_FLAG_BACKUP_SEMANTICS : 0U),
        NULL
    );
    if (handle == INVALID_HANDLE_VALUE) return FALSE;
    exact = GetFileInformationByHandle(handle, &information)
        && (information.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) == 0U
        && (((information.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U)
            == directory)
        && (directory || information.nNumberOfLinks == 1U)
        && wls_private_handle_acl_exact(handle, directory);
    (void)CloseHandle(handle);
    return exact;
}

static BOOL wls_protect_private_directory(const wchar_t *path)
{
    HANDLE token = NULL;
    PTOKEN_USER token_user = NULL;
    DWORD token_bytes = 0U;
    BYTE system_sid[SECURITY_MAX_SID_SIZE];
    DWORD system_sid_bytes = (DWORD)sizeof(system_sid);
    EXPLICIT_ACCESSW entries[2];
    PACL acl = NULL;
    DWORD status;
    BOOL exact = FALSE;

    if (path == NULL
        || !OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &token)) {
        return FALSE;
    }
    (void)GetTokenInformation(token, TokenUser, NULL, 0U, &token_bytes);
    if (GetLastError() != ERROR_INSUFFICIENT_BUFFER || token_bytes == 0U) {
        goto cleanup;
    }
    token_user = (PTOKEN_USER)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, token_bytes
    );
    if (token_user == NULL
        || !GetTokenInformation(
            token, TokenUser, token_user, token_bytes, &token_bytes
        )
        || !IsValidSid(token_user->User.Sid)
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_sid, &system_sid_bytes
        )) goto cleanup;
    (void)memset(entries, 0, sizeof(entries));
    entries[0].grfAccessPermissions = FILE_ALL_ACCESS;
    entries[0].grfAccessMode = SET_ACCESS;
    entries[0].grfInheritance = SUB_CONTAINERS_AND_OBJECTS_INHERIT;
    BuildTrusteeWithSidW(&entries[0].Trustee, token_user->User.Sid);
    entries[1].grfAccessPermissions = FILE_ALL_ACCESS;
    entries[1].grfAccessMode = SET_ACCESS;
    entries[1].grfInheritance = SUB_CONTAINERS_AND_OBJECTS_INHERIT;
    BuildTrusteeWithSidW(&entries[1].Trustee, system_sid);
    status = SetEntriesInAclW(2U, entries, NULL, &acl);
    if (status != ERROR_SUCCESS || acl == NULL) goto cleanup;
    status = SetNamedSecurityInfoW(
        (LPWSTR)(void *)path,
        SE_FILE_OBJECT,
        OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION
            | PROTECTED_DACL_SECURITY_INFORMATION,
        token_user->User.Sid,
        NULL,
        acl,
        NULL
    );
    exact = status == ERROR_SUCCESS
        && wls_private_path_acl_exact(path, TRUE);
cleanup:
    if (acl != NULL) (void)LocalFree(acl);
    if (token_user != NULL) {
        SecureZeroMemory(token_user, token_bytes);
        (void)HeapFree(GetProcessHeap(), 0U, token_user);
    }
    wls_close_handle(&token);
    return exact;
}

static BOOL wls_join_path(
    const wchar_t *directory,
    const wchar_t *name,
    wchar_t *output,
    SIZE_T output_count
)
{
    SIZE_T directory_length;
    SIZE_T name_length;

    if (directory == NULL || name == NULL || output == NULL) {
        return FALSE;
    }
    directory_length = wcslen(directory);
    name_length = wcslen(name);
    if (directory_length + 1U + name_length + 1U > output_count) {
        return FALSE;
    }
    (void)memcpy(output, directory, directory_length * sizeof(wchar_t));
    output[directory_length] = L'\\';
    (void)memcpy(
        output + directory_length + 1U,
        name,
        (name_length + 1U) * sizeof(wchar_t)
    );
    return TRUE;
}

static HANDLE wls_create_result_file(const wchar_t *path)
{
    return CreateFileW(
        path,
        GENERIC_READ | GENERIC_WRITE,
        0U,
        NULL,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
}

static BOOL wls_write_all(HANDLE file, const BYTE *bytes, SIZE_T length)
{
    SIZE_T offset = 0U;

    while (offset < length) {
        DWORD chunk = (DWORD)((length - offset) > (SIZE_T)0x7fffffffU
            ? (SIZE_T)0x7fffffffU
            : (length - offset));
        DWORD written = 0U;
        if (!WriteFile(file, bytes + offset, chunk, &written, NULL) || written == 0U) {
            return FALSE;
        }
        offset += (SIZE_T)written;
    }
    return TRUE;
}

static BOOL wls_sha256(const BYTE *bytes, SIZE_T length, BYTE digest[32])
{
    BCRYPT_ALG_HANDLE algorithm = NULL;
    BCRYPT_HASH_HANDLE hash = NULL;
    BYTE *object = NULL;
    DWORD object_length = 0U;
    DWORD digest_length = 0U;
    DWORD result_length = 0U;
    NTSTATUS status;
    BOOL ok = FALSE;

    status = BCryptOpenAlgorithmProvider(&algorithm, BCRYPT_SHA256_ALGORITHM, NULL, 0U);
    if (status != 0) {
        goto cleanup;
    }
    status = BCryptGetProperty(
        algorithm,
        BCRYPT_OBJECT_LENGTH,
        (PUCHAR)&object_length,
        (ULONG)sizeof(object_length),
        &result_length,
        0U
    );
    if (status != 0 || result_length != sizeof(object_length)) {
        goto cleanup;
    }
    status = BCryptGetProperty(
        algorithm,
        BCRYPT_HASH_LENGTH,
        (PUCHAR)&digest_length,
        (ULONG)sizeof(digest_length),
        &result_length,
        0U
    );
    if (status != 0 || digest_length != 32U) {
        goto cleanup;
    }
    object = (BYTE *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, object_length);
    if (object == NULL) {
        goto cleanup;
    }
    status = BCryptCreateHash(algorithm, &hash, object, object_length, NULL, 0U, 0U);
    if (status != 0) {
        goto cleanup;
    }
    if (length > 0U) {
        status = BCryptHashData(hash, (PUCHAR)bytes, (ULONG)length, 0U);
        if (status != 0) {
            goto cleanup;
        }
    }
    status = BCryptFinishHash(hash, digest, 32U, 0U);
    ok = status == 0;

cleanup:
    if (hash != NULL) {
        (void)BCryptDestroyHash(hash);
    }
    if (object != NULL) {
        SecureZeroMemory(object, object_length);
        (void)HeapFree(GetProcessHeap(), 0U, object);
    }
    if (algorithm != NULL) {
        (void)BCryptCloseAlgorithmProvider(algorithm, 0U);
    }
    return ok;
}

static void wls_hex_digest(const BYTE digest[32], char output[65])
{
    static const char alphabet[] = "0123456789abcdef";
    SIZE_T index;

    for (index = 0U; index < 32U; ++index) {
        output[index * 2U] = alphabet[(digest[index] >> 4U) & 0x0fU];
        output[index * 2U + 1U] = alphabet[digest[index] & 0x0fU];
    }
    output[64] = '\0';
}

static BOOL wls_parse_pipe_size(
    const wchar_t *value,
    SIZE_T maximum,
    SIZE_T *parsed_value
)
{
    wchar_t *end = NULL;
    unsigned long long parsed;

    if (value == NULL || parsed_value == NULL || value[0] == L'\0'
        || value[0] == L'-') {
        return FALSE;
    }
    parsed = wcstoull(value, &end, 10);
    if (end == value || end == NULL || *end != L'\0'
        || parsed == 0ULL || parsed > (unsigned long long)maximum) {
        return FALSE;
    }
    *parsed_value = (SIZE_T)parsed;
    return TRUE;
}

static BOOL wls_wide_sha256_is_valid(const wchar_t *digest)
{
    SIZE_T index;

    if (digest == NULL || wcslen(digest) != 64U) {
        return FALSE;
    }
    for (index = 0U; index < 64U; ++index) {
        wchar_t value = digest[index];
        if (!((value >= L'0' && value <= L'9')
            || (value >= L'a' && value <= L'f'))) {
            return FALSE;
        }
    }
    return TRUE;
}

static BOOL wls_sha256_matches_wide(
    const BYTE *bytes,
    SIZE_T length,
    const wchar_t *expected
)
{
    BYTE digest[32];
    char hex[65];
    SIZE_T index;

    if (!wls_wide_sha256_is_valid(expected)
        || !wls_sha256(bytes, length, digest)) {
        return FALSE;
    }
    wls_hex_digest(digest, hex);
    for (index = 0U; index < 64U; ++index) {
        if ((wchar_t)(unsigned char)hex[index] != expected[index]) {
            return FALSE;
        }
    }
    return TRUE;
}

static BOOL wls_pipe_directory_has_only_request(const wchar_t *directory)
{
    wchar_t pattern[WLS_MAX_NATIVE_PATH];
    WIN32_FIND_DATAW data;
    HANDLE find = INVALID_HANDLE_VALUE;
    SIZE_T count = 0U;
    BOOL ok = FALSE;

    if (!wls_join_path(directory, L"*", pattern, WLS_MAX_NATIVE_PATH)) {
        return FALSE;
    }
    find = FindFirstFileW(pattern, &data);
    if (find == INVALID_HANDLE_VALUE) {
        return FALSE;
    }
    do {
        if (wcscmp(data.cFileName, L".") == 0
            || wcscmp(data.cFileName, L"..") == 0) {
            continue;
        }
        ++count;
        if (count != 1U
            || _wcsicmp(data.cFileName, L"request.bin") != 0
            || (data.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
            || (data.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U) {
            goto cleanup;
        }
    } while (FindNextFileW(find, &data));
    if (GetLastError() != ERROR_NO_MORE_FILES) {
        goto cleanup;
    }
    ok = count == 1U;

cleanup:
    if (find != INVALID_HANDLE_VALUE) {
        (void)FindClose(find);
    }
    return ok;
}

static BOOL wls_pipe_orphan_name_exact(const wchar_t *path)
{
    const wchar_t *leaf = wcsrchr(path, L'\\');
    SIZE_T index;
    if (leaf == NULL) return FALSE;
    ++leaf;
    if (wcslen(leaf) != 37U || wcsncmp(leaf, L"pipe-", 5U) != 0) {
        return FALSE;
    }
    for (index = 5U; index < 37U; ++index) {
        if (!((leaf[index] >= L'0' && leaf[index] <= L'9')
            || (leaf[index] >= L'a' && leaf[index] <= L'f'))) return FALSE;
    }
    return TRUE;
}

static BOOL wls_pipe_orphan_parent_name_exact(const wchar_t *parent)
{
    const wchar_t *leaf = wcsrchr(parent, L'\\');
    return leaf != NULL
        && wcscmp(leaf + 1U, L"wls-bounded-command-results-v1") == 0;
}

static BOOL wls_pipe_orphan_leaf_allowed(const wchar_t *leaf)
{
    static const wchar_t *allowed[] = {
        L"request.bin",
        L"response.bin.tmp",
        L"response.bin",
        L"result.json.tmp",
        L"result.json"
    };
    SIZE_T index;
    for (index = 0U; index < sizeof(allowed) / sizeof(allowed[0]); ++index) {
        if (wcscmp(leaf, allowed[index]) == 0) return TRUE;
    }
    return FALSE;
}

static int wls_pipe_reap_orphan(int argc, wchar_t **argv)
{
    static const wchar_t transaction_prefix[] = L"--transaction-dir=";
    static const wchar_t age_prefix[] = L"--minimum-age-ms=";
    const SIZE_T transaction_prefix_length =
        (sizeof(transaction_prefix) / sizeof(transaction_prefix[0])) - 1U;
    const SIZE_T age_prefix_length =
        (sizeof(age_prefix) / sizeof(age_prefix[0])) - 1U;
    wchar_t transaction_dir[WLS_MAX_NATIVE_PATH];
    wchar_t parent[WLS_MAX_NATIVE_PATH];
    wchar_t pattern[WLS_MAX_NATIVE_PATH];
    wchar_t paths[5][WLS_MAX_NATIVE_PATH];
    HANDLE files[5];
    SIZE_T minimum_age = 0U;
    SIZE_T count = 0U;
    SIZE_T index;
    HANDLE directory = INVALID_HANDLE_VALUE;
    WIN32_FIND_DATAW data;
    HANDLE find = INVALID_HANDLE_VALUE;
    BY_HANDLE_FILE_INFORMATION information;
    FILETIME now_filetime;
    FILETIME modified_filetime;
    ULARGE_INTEGER now;
    ULARGE_INTEGER modified;
    FILE_DISPOSITION_INFO disposition;
    WLS_PATH_GUARD parent_guard;
    int result = WLS_EXIT_ORPHAN_UNSAFE;

    (void)memset(files, 0, sizeof(files));
    (void)memset(paths, 0, sizeof(paths));
    (void)memset(&parent_guard, 0, sizeof(parent_guard));
    (void)memset(&information, 0, sizeof(information));
    (void)memset(&disposition, 0, sizeof(disposition));
    if (argc != 4
        || wcscmp(argv[1], L"--pipe-reap-orphan") != 0
        || wcsncmp(argv[2], transaction_prefix, transaction_prefix_length) != 0
        || wcsncmp(argv[3], age_prefix, age_prefix_length) != 0
        || !wls_normalize_local_path(
            argv[2] + transaction_prefix_length,
            transaction_dir,
            WLS_MAX_NATIVE_PATH,
            FALSE
        )
        || !wls_parse_pipe_size(
            argv[3] + age_prefix_length,
            (SIZE_T)WLS_PIPE_ORPHAN_MINIMUM_AGE_MS,
            &minimum_age
        )
        || minimum_age != (SIZE_T)WLS_PIPE_ORPHAN_MINIMUM_AGE_MS
        || !wls_pipe_orphan_name_exact(transaction_dir)
        || !wls_parent_path(transaction_dir, parent, WLS_MAX_NATIVE_PATH)
        || !wls_pipe_orphan_parent_name_exact(parent)
        || !wls_guard_existing_path(parent, TRUE, &parent_guard)
        || !wls_private_path_acl_exact(parent, TRUE)) {
        goto cleanup;
    }
    directory = CreateFileW(
        transaction_dir,
        FILE_READ_ATTRIBUTES | READ_CONTROL | DELETE,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (directory == INVALID_HANDLE_VALUE) {
        DWORD error = GetLastError();
        if (error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND) {
            result = 0;
        } else if (error == ERROR_SHARING_VIOLATION
            || error == ERROR_LOCK_VIOLATION) {
            result = WLS_EXIT_ORPHAN_BUSY;
        }
        goto cleanup;
    }
    if (!GetFileInformationByHandle(directory, &information)
        || (information.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) == 0U
        || (information.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || !wls_private_handle_acl_exact(directory, TRUE)
        || !GetFileTime(directory, NULL, NULL, &modified_filetime)) goto cleanup;
    GetSystemTimeAsFileTime(&now_filetime);
    now.LowPart = now_filetime.dwLowDateTime;
    now.HighPart = now_filetime.dwHighDateTime;
    modified.LowPart = modified_filetime.dwLowDateTime;
    modified.HighPart = modified_filetime.dwHighDateTime;
    if (modified.QuadPart > now.QuadPart
        || (now.QuadPart - modified.QuadPart) / 10000ULL
            < (ULONGLONG)minimum_age) {
        result = WLS_EXIT_ORPHAN_BUSY;
        goto cleanup;
    }
    if (!wls_join_path(transaction_dir, L"*", pattern, WLS_MAX_NATIVE_PATH)) {
        goto cleanup;
    }
    find = FindFirstFileW(pattern, &data);
    if (find == INVALID_HANDLE_VALUE) goto cleanup;
    do {
        HANDLE file;
        if (wcscmp(data.cFileName, L".") == 0
            || wcscmp(data.cFileName, L"..") == 0) continue;
        if (count >= 5U
            || !wls_pipe_orphan_leaf_allowed(data.cFileName)
            || (data.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
            || (data.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
            || !wls_join_path(
                transaction_dir,
                data.cFileName,
                paths[count],
                WLS_MAX_NATIVE_PATH
            )) goto cleanup;
        file = CreateFileW(
            paths[count],
            FILE_READ_ATTRIBUTES | DELETE,
            0U,
            NULL,
            OPEN_EXISTING,
            FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
            NULL
        );
        if (file == INVALID_HANDLE_VALUE) {
            DWORD error = GetLastError();
            if (error == ERROR_SHARING_VIOLATION
                || error == ERROR_LOCK_VIOLATION) {
                result = WLS_EXIT_ORPHAN_BUSY;
            }
            goto cleanup;
        }
        if (!GetFileInformationByHandle(file, &information)
            || (information.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
            || (information.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
            || information.nNumberOfLinks != 1U) {
            (void)CloseHandle(file);
            goto cleanup;
        }
        files[count++] = file;
    } while (FindNextFileW(find, &data));
    if (GetLastError() != ERROR_NO_MORE_FILES) goto cleanup;
    (void)FindClose(find);
    find = INVALID_HANDLE_VALUE;
    disposition.DeleteFile = TRUE;
    for (index = 0U; index < count; ++index) {
        if (!SetFileInformationByHandle(
            files[index],
            FileDispositionInfo,
            &disposition,
            (DWORD)sizeof(disposition)
        )) goto cleanup;
    }
    for (index = 0U; index < count; ++index) {
        (void)CloseHandle(files[index]);
        files[index] = NULL;
    }
    if (!SetFileInformationByHandle(
        directory,
        FileDispositionInfo,
        &disposition,
        (DWORD)sizeof(disposition)
    )) goto cleanup;
    result = 0;

cleanup:
    if (find != INVALID_HANDLE_VALUE) (void)FindClose(find);
    for (index = 0U; index < 5U; ++index) {
        wls_close_handle(&files[index]);
    }
    if (directory != INVALID_HANDLE_VALUE) (void)CloseHandle(directory);
    wls_guard_close(&parent_guard);
    return result;
}

static int wls_pipe_prepare(int argc, wchar_t **argv)
{
    static const wchar_t transaction_prefix[] = L"--transaction-dir=";
    wchar_t transaction_dir[WLS_MAX_NATIVE_PATH];
    wchar_t parent[WLS_MAX_NATIVE_PATH];
    wchar_t request_path[WLS_MAX_NATIVE_PATH];
    WLS_PATH_GUARD parent_guard;
    WLS_PATH_GUARD transaction_guard;
    HANDLE request = NULL;
    BOOL created_directory = FALSE;
    int result = WLS_EXIT_FILESYSTEM;

    (void)memset(&parent_guard, 0, sizeof(parent_guard));
    (void)memset(&transaction_guard, 0, sizeof(transaction_guard));
    request_path[0] = L'\0';
    if (argc != 3
        || wcscmp(argv[1], L"--pipe-prepare") != 0
        || wcsncmp(
            argv[2],
            transaction_prefix,
            (sizeof(transaction_prefix) / sizeof(transaction_prefix[0])) - 1U
        ) != 0
        || !wls_normalize_local_path(
            argv[2] + ((sizeof(transaction_prefix) / sizeof(transaction_prefix[0])) - 1U),
            transaction_dir,
            WLS_MAX_NATIVE_PATH,
            FALSE
        )
        || !wls_parent_path(transaction_dir, parent, WLS_MAX_NATIVE_PATH)
        || !wls_pipe_orphan_name_exact(transaction_dir)
        || !wls_pipe_orphan_parent_name_exact(parent)
        || !wls_protect_private_directory(parent)
        || !wls_guard_existing_path(parent, TRUE, &parent_guard)
        || !wls_create_private_directory(transaction_dir)) {
        wls_print_error(L"named-pipe transaction directory could not be prepared", GetLastError());
        goto cleanup;
    }
    created_directory = TRUE;
    if (!wls_guard_existing_path(transaction_dir, TRUE, &transaction_guard)
        || !wls_private_path_acl_exact(transaction_dir, TRUE)
        || !wls_join_path(
            transaction_dir,
            L"request.bin",
            request_path,
            WLS_MAX_NATIVE_PATH
        )) {
        wls_print_error(L"named-pipe transaction directory is unsafe", GetLastError());
        goto cleanup;
    }
    request = wls_create_result_file(request_path);
    if (request == INVALID_HANDLE_VALUE || !FlushFileBuffers(request)) {
        wls_print_error(L"named-pipe request file could not be created exclusively", GetLastError());
        goto cleanup;
    }
    result = 0;

cleanup:
    wls_close_handle(&request);
    if (result != 0 && created_directory) {
        if (request_path[0] != L'\0') {
            (void)DeleteFileW(request_path);
        }
    }
    wls_guard_close(&transaction_guard);
    if (result != 0 && created_directory) {
        (void)RemoveDirectoryW(transaction_dir);
    }
    wls_guard_close(&parent_guard);
    return result;
}

static BOOL wls_pipe_remaining_milliseconds(
    ULONGLONG deadline,
    DWORD *remaining
)
{
    ULONGLONG now;
    ULONGLONG delta;

    if (remaining == NULL) {
        return FALSE;
    }
    now = GetTickCount64();
    if (now >= deadline) {
        *remaining = 0U;
        return FALSE;
    }
    delta = deadline - now;
    *remaining = delta >= (ULONGLONG)(MAXDWORD - 1U)
        ? MAXDWORD - 1U
        : (DWORD)delta;
    if (*remaining == 0U) {
        *remaining = 1U;
    }
    return TRUE;
}

static BOOL wls_pipe_overlapped_io_until(
    HANDLE pipe,
    BOOL write_operation,
    BYTE *buffer,
    DWORD requested,
    DWORD *transferred,
    ULONGLONG deadline,
    BOOL *timed_out,
    BOOL *abandoned
)
{
    WLS_PIPE_IO *operation;
    BOOL started;
    DWORD error;
    DWORD remaining;
    DWORD wait_result;

    if (transferred == NULL || timed_out == NULL || abandoned == NULL
        || requested == 0U || GetTickCount64() >= deadline) {
        if (timed_out != NULL) {
            *timed_out = TRUE;
        }
        return FALSE;
    }
    *transferred = 0U;
    operation = (WLS_PIPE_IO *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        sizeof(*operation)
    );
    if (operation == NULL) {
        return FALSE;
    }
    operation->event = CreateEventW(NULL, TRUE, FALSE, NULL);
    if (operation->event == NULL) {
        (void)HeapFree(GetProcessHeap(), 0U, operation);
        return FALSE;
    }
    operation->overlapped.hEvent = operation->event;
    started = write_operation
        ? WriteFile(
            pipe,
            buffer,
            requested,
            NULL,
            &operation->overlapped
        )
        : ReadFile(
            pipe,
            buffer,
            requested,
            NULL,
            &operation->overlapped
        );
    if (!started) {
        error = GetLastError();
        if (error != ERROR_IO_PENDING) {
            (void)CloseHandle(operation->event);
            (void)HeapFree(GetProcessHeap(), 0U, operation);
            SetLastError(error);
            return FALSE;
        }
        if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
            (void)CancelIoEx(pipe, &operation->overlapped);
            *timed_out = TRUE;
            *abandoned = TRUE;
            return FALSE;
        }
        wait_result = WaitForSingleObject(operation->event, remaining);
        if (wait_result == WAIT_TIMEOUT) {
            (void)CancelIoEx(pipe, &operation->overlapped);
            *timed_out = TRUE;
            *abandoned = TRUE;
            return FALSE;
        }
        if (wait_result != WAIT_OBJECT_0) {
            (void)CancelIoEx(pipe, &operation->overlapped);
            *abandoned = TRUE;
            return FALSE;
        }
    }
    if (!GetOverlappedResult(
                pipe,
                &operation->overlapped,
                transferred,
                FALSE
            )) {
        error = GetLastError();
        (void)CloseHandle(operation->event);
        (void)HeapFree(GetProcessHeap(), 0U, operation);
        SetLastError(error);
        return FALSE;
    }
    (void)CloseHandle(operation->event);
    (void)HeapFree(GetProcessHeap(), 0U, operation);
    return TRUE;
}

static HANDLE wls_pipe_connect_until(
    const wchar_t *pipe_path,
    ULONGLONG connect_deadline,
    BOOL *timed_out
)
{
    HANDLE pipe;
    DWORD remaining;
    DWORD mode = PIPE_READMODE_BYTE;
    DWORD attempts = 0U;

    for (;;) {
        if (++attempts > WLS_PIPE_MAX_CONNECT_ATTEMPTS) {
            SetLastError(ERROR_RETRY);
            return INVALID_HANDLE_VALUE;
        }
        if (!wls_pipe_remaining_milliseconds(connect_deadline, &remaining)) {
            *timed_out = TRUE;
            return INVALID_HANDLE_VALUE;
        }
        if (!WaitNamedPipeW(pipe_path, remaining)) {
            DWORD error = GetLastError();
            if (error == ERROR_SEM_TIMEOUT) {
                *timed_out = TRUE;
            }
            SetLastError(error);
            return INVALID_HANDLE_VALUE;
        }
        pipe = CreateFileW(
            pipe_path,
            GENERIC_READ | GENERIC_WRITE,
            0U,
            NULL,
            OPEN_EXISTING,
            FILE_FLAG_OVERLAPPED
                | SECURITY_SQOS_PRESENT
                | SECURITY_IDENTIFICATION
                | SECURITY_EFFECTIVE_ONLY,
            NULL
        );
        if (pipe != INVALID_HANDLE_VALUE) {
            if (SetNamedPipeHandleState(pipe, &mode, NULL, NULL)) {
                return pipe;
            }
            (void)CloseHandle(pipe);
            return INVALID_HANDLE_VALUE;
        }
        if (GetLastError() != ERROR_PIPE_BUSY) {
            return INVALID_HANDLE_VALUE;
        }
    }
}

static BOOL wls_pipe_write_frame_until(
    HANDLE pipe,
    BYTE *frame,
    SIZE_T frame_length,
    ULONGLONG deadline,
    BOOL *timed_out,
    BOOL *abandoned
)
{
    SIZE_T offset = 0U;
    DWORD operations = 0U;

    while (offset < frame_length) {
        DWORD chunk = (DWORD)((frame_length - offset) > (SIZE_T)WLS_PIPE_IO_CHUNK
            ? (SIZE_T)WLS_PIPE_IO_CHUNK
            : frame_length - offset);
        DWORD written = 0U;
        if (++operations > WLS_PIPE_MAX_WRITE_OPERATIONS) {
            SetLastError(ERROR_RETRY);
            return FALSE;
        }
        if (!wls_pipe_overlapped_io_until(
            pipe,
            TRUE,
            frame + offset,
            chunk,
            &written,
            deadline,
            timed_out,
            abandoned
        ) || written == 0U) {
            return FALSE;
        }
        offset += (SIZE_T)written;
    }
    return TRUE;
}

static BOOL wls_pipe_read_frame_until(
    HANDLE pipe,
    BYTE *frame,
    SIZE_T maximum,
    SIZE_T *frame_length,
    ULONGLONG deadline,
    BOOL *timed_out,
    BOOL *abandoned
)
{
    SIZE_T length = 0U;
    DWORD operations = 0U;

    while (length <= maximum) {
        SIZE_T available = maximum + 1U - length;
        DWORD chunk = (DWORD)(available > (SIZE_T)WLS_PIPE_IO_CHUNK
            ? (SIZE_T)WLS_PIPE_IO_CHUNK
            : available);
        DWORD received = 0U;
        BYTE *newline;
        if (++operations > WLS_PIPE_MAX_READ_OPERATIONS) {
            SetLastError(ERROR_RETRY);
            return FALSE;
        }
        if (!wls_pipe_overlapped_io_until(
            pipe,
            FALSE,
            frame + length,
            chunk,
            &received,
            deadline,
            timed_out,
            abandoned
        ) || received == 0U) {
            return FALSE;
        }
        length += (SIZE_T)received;
        newline = (BYTE *)memchr(frame, '\n', length);
        if (newline != NULL) {
            if ((SIZE_T)(newline - frame) + 1U != length || length > maximum) {
                SetLastError(ERROR_INVALID_DATA);
                return FALSE;
            }
            *frame_length = length;
            return TRUE;
        }
        if (length > maximum) {
            SetLastError(ERROR_BUFFER_OVERFLOW);
            return FALSE;
        }
    }
    SetLastError(ERROR_BUFFER_OVERFLOW);
    return FALSE;
}

static BOOL wls_pipe_publish_result(
    const wchar_t *transaction_dir,
    const BYTE *response,
    SIZE_T response_length,
    ULONGLONG deadline,
    BOOL *timed_out
)
{
    wchar_t response_path[WLS_MAX_NATIVE_PATH];
    wchar_t response_temp_path[WLS_MAX_NATIVE_PATH];
    wchar_t manifest_temp_path[WLS_MAX_NATIVE_PATH];
    wchar_t manifest_path[WLS_MAX_NATIVE_PATH];
    HANDLE response_file = NULL;
    HANDLE manifest_file = NULL;
    BYTE digest[32];
    char digest_hex[65];
    char manifest[384];
    int manifest_length;
    BOOL ok = FALSE;
    DWORD remaining = 0U;

    if (timed_out == NULL) {
        SetLastError(ERROR_INVALID_PARAMETER);
        return FALSE;
    }
    *timed_out = FALSE;

    if (!wls_join_path(
            transaction_dir,
            L"response.bin",
            response_path,
            WLS_MAX_NATIVE_PATH
        )
        || !wls_join_path(
            transaction_dir,
            L"response.bin.tmp",
            response_temp_path,
            WLS_MAX_NATIVE_PATH
        )
        || !wls_join_path(
            transaction_dir,
            L"result.json.tmp",
            manifest_temp_path,
            WLS_MAX_NATIVE_PATH
        )
        || !wls_join_path(
            transaction_dir,
            L"result.json",
            manifest_path,
            WLS_MAX_NATIVE_PATH
        )
        || !wls_sha256(response, response_length, digest)) {
        return FALSE;
    }
    wls_hex_digest(digest, digest_hex);
    manifest_length = snprintf(
        manifest,
        sizeof(manifest),
        "{\"schema\":\"%s\",\"response_bytes\":%lu,"
        "\"response_sha256\":\"%s\"}\n",
        WLS_PIPE_RESULT_SCHEMA,
        (unsigned long)response_length,
        digest_hex
    );
    if (manifest_length <= 0 || (SIZE_T)manifest_length >= sizeof(manifest)) {
        return FALSE;
    }
    /* response.bin and result.json form one receipt. The synchronous FlushFileBuffers and MoveFileExW calls are not cancellable by this
     * standalone process: deadline fences run before and after each call, and
     * the parent watchdog contains the standalone helper process if a syscall
     * does not return. Only staging state exists until every fence succeeds;
     * a late process therefore cannot leave an authoritative receipt for PHP. */
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
        *timed_out = TRUE;
        SetLastError(ERROR_TIMEOUT);
        goto cleanup;
    }
    response_file = wls_create_result_file(response_temp_path);
    manifest_file = wls_create_result_file(manifest_temp_path);
    if (response_file == INVALID_HANDLE_VALUE
        || manifest_file == INVALID_HANDLE_VALUE
        || !wls_write_all(response_file, response, response_length)) goto cleanup;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
        *timed_out = TRUE;
        SetLastError(ERROR_TIMEOUT);
        goto cleanup;
    }
    if (!FlushFileBuffers(response_file)) goto cleanup;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
        *timed_out = TRUE;
        SetLastError(ERROR_TIMEOUT);
        goto cleanup;
    }
    if (!wls_write_all(
            manifest_file,
            (const BYTE *)manifest,
            (SIZE_T)manifest_length
        )) goto cleanup;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
        *timed_out = TRUE;
        SetLastError(ERROR_TIMEOUT);
        goto cleanup;
    }
    if (!FlushFileBuffers(manifest_file)) goto cleanup;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
        *timed_out = TRUE;
        SetLastError(ERROR_TIMEOUT);
        goto cleanup;
    }
    if (!CloseHandle(response_file)) {
        response_file = NULL;
        goto cleanup;
    }
    response_file = NULL;
    if (!CloseHandle(manifest_file)) {
        manifest_file = NULL;
        goto cleanup;
    }
    manifest_file = NULL;
    if (!MoveFileExW(
            response_temp_path,
            response_path,
            MOVEFILE_WRITE_THROUGH
        )) goto cleanup;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
        *timed_out = TRUE;
        SetLastError(ERROR_TIMEOUT);
        goto cleanup;
    }
    if (!MoveFileExW(
        manifest_temp_path,
        manifest_path,
        MOVEFILE_WRITE_THROUGH
    )) goto cleanup;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
        *timed_out = TRUE;
        SetLastError(ERROR_TIMEOUT);
        goto cleanup;
    }
    ok = TRUE;

cleanup:
    wls_close_handle(&response_file);
    wls_close_handle(&manifest_file);
    if (!ok) {
        (void)DeleteFileW(response_temp_path);
        (void)DeleteFileW(manifest_temp_path);
        (void)DeleteFileW(response_path);
        (void)DeleteFileW(manifest_path);
    }
    return ok;
}

static void wls_pipe_delete_failed_transaction_files(const wchar_t *transaction_dir)
{
    static const wchar_t *leaves[] = {
        L"request.bin",
        L"response.bin.tmp",
        L"response.bin",
        L"result.json.tmp",
        L"result.json"
    };
    SIZE_T index;

    for (index = 0U; index < sizeof(leaves) / sizeof(leaves[0]); ++index) {
        wchar_t path[WLS_MAX_NATIVE_PATH];
        if (wls_join_path(
            transaction_dir,
            leaves[index],
            path,
            WLS_MAX_NATIVE_PATH
        )) {
            (void)DeleteFileW(path);
        }
    }
}

static int wls_pipe_exchange(int argc, wchar_t **argv)
{
    static const wchar_t transaction_prefix[] = L"--transaction-dir=";
    static const wchar_t channel_prefix[] = L"--pipe-channel=";
    static const wchar_t digest_prefix[] = L"--request-sha256=";
    static const wchar_t maximum_prefix[] = L"--max-frame-bytes=";
    static const wchar_t connect_prefix[] = L"--connect-timeout-ms=";
    static const wchar_t timeout_prefix[] = L"--timeout-ms=";
    const SIZE_T transaction_prefix_length =
        (sizeof(transaction_prefix) / sizeof(transaction_prefix[0])) - 1U;
    const SIZE_T channel_prefix_length =
        (sizeof(channel_prefix) / sizeof(channel_prefix[0])) - 1U;
    const SIZE_T digest_prefix_length =
        (sizeof(digest_prefix) / sizeof(digest_prefix[0])) - 1U;
    const SIZE_T maximum_prefix_length =
        (sizeof(maximum_prefix) / sizeof(maximum_prefix[0])) - 1U;
    const SIZE_T connect_prefix_length =
        (sizeof(connect_prefix) / sizeof(connect_prefix[0])) - 1U;
    const SIZE_T timeout_prefix_length =
        (sizeof(timeout_prefix) / sizeof(timeout_prefix[0])) - 1U;
    wchar_t transaction_dir[WLS_MAX_NATIVE_PATH];
    wchar_t request_path[WLS_MAX_NATIVE_PATH];
    const wchar_t *pipe_path = NULL;
    const wchar_t *expected_digest;
    SIZE_T maximum = 0U;
    SIZE_T connect_timeout_size = 0U;
    SIZE_T timeout_size = 0U;
    ULONGLONG started = GetTickCount64();
    ULONGLONG deadline;
    ULONGLONG connect_deadline;
    WLS_PATH_GUARD transaction_guard;
    HANDLE request = NULL;
    HANDLE pipe = NULL;
    BY_HANDLE_FILE_INFORMATION information;
    LARGE_INTEGER file_size;
    BYTE *request_bytes = NULL;
    BYTE *response_bytes = NULL;
    SIZE_T request_length = 0U;
    SIZE_T response_length = 0U;
    BOOL timed_out = FALSE;
    BOOL abandoned = FALSE;
    BOOL transaction_trusted = FALSE;
    int result = WLS_EXIT_TRANSPORT;

    (void)memset(&transaction_guard, 0, sizeof(transaction_guard));
    (void)memset(&information, 0, sizeof(information));
    (void)memset(&file_size, 0, sizeof(file_size));
    if (argc != 8
        || wcscmp(argv[1], L"--pipe-exchange") != 0
        || wcsncmp(argv[2], transaction_prefix, transaction_prefix_length) != 0
        || wcsncmp(argv[3], channel_prefix, channel_prefix_length) != 0
        || wcsncmp(argv[4], digest_prefix, digest_prefix_length) != 0
        || wcsncmp(argv[5], maximum_prefix, maximum_prefix_length) != 0
        || wcsncmp(argv[6], connect_prefix, connect_prefix_length) != 0
        || wcsncmp(argv[7], timeout_prefix, timeout_prefix_length) != 0
        || !wls_normalize_local_path(
            argv[2] + transaction_prefix_length,
            transaction_dir,
            WLS_MAX_NATIVE_PATH,
            FALSE
        )
        || !wls_wide_sha256_is_valid(argv[4] + digest_prefix_length)
        || !wls_parse_pipe_size(
            argv[5] + maximum_prefix_length,
            WLS_PIPE_MAX_FRAME_BYTES,
            &maximum
        )
        || !wls_parse_pipe_size(
            argv[6] + connect_prefix_length,
            (SIZE_T)WLS_MAX_TIMEOUT_MS,
            &connect_timeout_size
        )
        || !wls_parse_pipe_size(
            argv[7] + timeout_prefix_length,
            (SIZE_T)WLS_MAX_TIMEOUT_MS,
            &timeout_size
        )) {
        (void)fputs("invalid named-pipe exchange arguments\n", stderr);
        return WLS_EXIT_USAGE;
    }
    if (wcscmp(argv[3] + channel_prefix_length, L"admin") == 0) {
        pipe_path = WLS_PIPE_ADMIN_PATH;
    } else if (wcscmp(argv[3] + channel_prefix_length, L"project") == 0) {
        pipe_path = WLS_PIPE_PROJECT_PATH;
    } else {
        (void)fputs("invalid named-pipe channel\n", stderr);
        return WLS_EXIT_USAGE;
    }
    expected_digest = argv[4] + digest_prefix_length;
    deadline = started + (ULONGLONG)timeout_size;
    if (deadline < started) {
        deadline = ~(ULONGLONG)0;
    }
    connect_deadline = started + (ULONGLONG)connect_timeout_size;
    if (connect_deadline < started || connect_deadline > deadline) {
        connect_deadline = deadline;
    }
    if (!wls_guard_existing_path(
            transaction_dir,
            TRUE,
            &transaction_guard
        )
        || !wls_pipe_directory_has_only_request(transaction_dir)
        || !wls_join_path(
            transaction_dir,
            L"request.bin",
            request_path,
            WLS_MAX_NATIVE_PATH
        )) {
        wls_print_error(L"named-pipe transaction input is unsafe", GetLastError());
        result = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    transaction_trusted = TRUE;
    request = CreateFileW(
        request_path,
        GENERIC_READ,
        0U,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL
            | FILE_FLAG_OPEN_REPARSE_POINT
            | FILE_FLAG_SEQUENTIAL_SCAN
            | FILE_FLAG_OVERLAPPED,
        NULL
    );
    if (request == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandle(request, &information)
        || (information.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
        || (information.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || information.nNumberOfLinks != 1U
        || !GetFileSizeEx(request, &file_size)
        || file_size.QuadPart < 1
        || (ULONGLONG)file_size.QuadPart > (ULONGLONG)maximum) {
        wls_print_error(L"named-pipe request file is invalid", GetLastError());
        result = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    request_length = (SIZE_T)file_size.QuadPart;
    request_bytes = (BYTE *)HeapAlloc(GetProcessHeap(), 0U, request_length);
    response_bytes = (BYTE *)HeapAlloc(GetProcessHeap(), 0U, maximum + 1U);
    if (request_bytes == NULL || response_bytes == NULL) {
        result = WLS_EXIT_INTERNAL;
        goto cleanup;
    }
    {
        SIZE_T offset = 0U;
        while (offset < request_length) {
            DWORD chunk = (DWORD)((request_length - offset) > (SIZE_T)WLS_PIPE_IO_CHUNK
                ? (SIZE_T)WLS_PIPE_IO_CHUNK
                : request_length - offset);
            DWORD received = 0U;
            if (!wls_pipe_overlapped_io_until(
                    request,
                    FALSE,
                    request_bytes + offset,
                    chunk,
                    &received,
                    deadline,
                    &timed_out,
                    &abandoned
                )
                || received == 0U) {
                result = timed_out ? WLS_EXIT_TIMEOUT : WLS_EXIT_FILESYSTEM;
                goto cleanup;
            }
            offset += (SIZE_T)received;
        }
    }
    if (request_bytes[request_length - 1U] != (BYTE)'\n'
        || memchr(request_bytes, '\0', request_length) != NULL
        || memchr(request_bytes, '\n', request_length - 1U) != NULL
        || !wls_sha256_matches_wide(
            request_bytes,
            request_length,
            expected_digest
        )) {
        SetLastError(ERROR_INVALID_DATA);
        result = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    wls_close_handle(&request);
    if (!DeleteFileW(request_path)) {
        result = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    if (GetTickCount64() >= deadline) {
        timed_out = TRUE;
        result = WLS_EXIT_TIMEOUT;
        goto cleanup;
    }
    pipe = wls_pipe_connect_until(pipe_path, connect_deadline, &timed_out);
    if (pipe == INVALID_HANDLE_VALUE) {
        result = timed_out && GetTickCount64() >= deadline
            ? WLS_EXIT_TIMEOUT
            : WLS_EXIT_TRANSPORT;
        goto cleanup;
    }
    if (!wls_pipe_write_frame_until(
            pipe,
            request_bytes,
            request_length,
            deadline,
            &timed_out,
            &abandoned
        )
        || !wls_pipe_read_frame_until(
            pipe,
            response_bytes,
            maximum,
            &response_length,
            deadline,
            &timed_out,
            &abandoned
        )) {
        result = timed_out ? WLS_EXIT_TIMEOUT : WLS_EXIT_TRANSPORT;
        goto cleanup;
    }
    if (!wls_pipe_publish_result(
        transaction_dir,
        response_bytes,
        response_length,
        deadline,
        &timed_out
    )) {
        result = timed_out ? WLS_EXIT_TIMEOUT : WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    result = 0;

cleanup:
    wls_close_handle(&request);
    wls_close_handle(&pipe);
    if (result != 0 && transaction_trusted) {
        wls_pipe_delete_failed_transaction_files(transaction_dir);
    }
    wls_guard_close(&transaction_guard);
    if (result != 0 && transaction_trusted) {
        (void)RemoveDirectoryW(transaction_dir);
    }
    if (!abandoned) {
        if (request_bytes != NULL) {
            SecureZeroMemory(request_bytes, request_length);
            (void)HeapFree(GetProcessHeap(), 0U, request_bytes);
        }
        if (response_bytes != NULL) {
            SecureZeroMemory(response_bytes, maximum + 1U);
            (void)HeapFree(GetProcessHeap(), 0U, response_bytes);
        }
    }
    return result;
}

static DWORD WINAPI wls_capture_thread(LPVOID parameter)
{
    WLS_CAPTURE_CHANNEL *channel = (WLS_CAPTURE_CHANNEL *)parameter;
    BYTE chunk[WLS_CAPTURE_CHUNK];

    for (;;) {
        DWORD bytes_read = 0U;
        BOOL read = ReadFile(
            channel->pipe,
            chunk,
            (DWORD)sizeof(chunk),
            &bytes_read,
            NULL
        );
        if (!read) {
            DWORD error = GetLastError();
            if (error != ERROR_BROKEN_PIPE && error != ERROR_HANDLE_EOF
                && error != ERROR_OPERATION_ABORTED) {
                (void)InterlockedExchange(&channel->io_error, 1L);
            }
            break;
        }
        if (bytes_read == 0U) {
            break;
        }
        EnterCriticalSection(&channel->shared->lock);
        {
            SIZE_T available = channel->shared->total < WLS_CAPTURE_LIMIT
                ? WLS_CAPTURE_LIMIT - channel->shared->total
                : 0U;
            SIZE_T accepted = (SIZE_T)bytes_read < available
                ? (SIZE_T)bytes_read
                : available;
            if (accepted > 0U) {
                (void)memcpy(channel->buffer + channel->length, chunk, accepted);
                channel->length += accepted;
                channel->shared->total += accepted;
            }
            if (accepted != (SIZE_T)bytes_read) {
                channel->shared->truncated = TRUE;
            }
        }
        LeaveCriticalSection(&channel->shared->lock);
    }
    return 0U;
}

static BOOL wls_create_capture_pipe(HANDLE *read_pipe, HANDLE *write_pipe)
{
    SECURITY_ATTRIBUTES attributes;

    attributes.nLength = (DWORD)sizeof(attributes);
    attributes.lpSecurityDescriptor = NULL;
    attributes.bInheritHandle = TRUE;
    if (!CreatePipe(read_pipe, write_pipe, &attributes, 0U)) {
        return FALSE;
    }
    if (!SetHandleInformation(*read_pipe, HANDLE_FLAG_INHERIT, 0U)) {
        DWORD error = GetLastError();
        wls_close_handle(read_pipe);
        wls_close_handle(write_pipe);
        SetLastError(error);
        return FALSE;
    }
    return TRUE;
}

static HANDLE wls_create_job(void)
{
    HANDLE job = CreateJobObjectW(NULL, NULL);
    JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits;

    if (job == NULL) {
        return NULL;
    }
    (void)memset(&limits, 0, sizeof(limits));
    limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
    if (!SetInformationJobObject(
        job,
        JobObjectExtendedLimitInformation,
        &limits,
        (DWORD)sizeof(limits)
    )) {
        (void)CloseHandle(job);
        return NULL;
    }
    return job;
}

static BOOL wls_job_is_empty(HANDLE job)
{
    JOBOBJECT_BASIC_ACCOUNTING_INFORMATION accounting;

    (void)memset(&accounting, 0, sizeof(accounting));
    return QueryInformationJobObject(
        job,
        JobObjectBasicAccountingInformation,
        &accounting,
        (DWORD)sizeof(accounting),
        NULL
    ) && accounting.ActiveProcesses == 0U;
}

static BOOL wls_wait_job_empty_until(HANDLE job, ULONGLONG deadline)
{
    for (;;) {
        if (wls_job_is_empty(job)) {
            return TRUE;
        }
        if (GetTickCount64() >= deadline) {
            return FALSE;
        }
        Sleep(10U);
    }
}

static BOOL wls_terminate_job_and_wait(HANDLE job, HANDLE process, DWORD exit_code)
{
    ULONGLONG deadline;

    if (!TerminateJobObject(job, exit_code)) {
        return FALSE;
    }
    deadline = GetTickCount64() + (ULONGLONG)WLS_TERMINATION_GRACE_MS;
    for (;;) {
        if (process != NULL) {
            (void)WaitForSingleObject(process, 10U);
        } else {
            Sleep(10U);
        }
        if (wls_job_is_empty(job)) {
            return TRUE;
        }
        if (GetTickCount64() >= deadline) {
            return FALSE;
        }
    }
}

static SIZE_T wls_quoted_argument_length(const wchar_t *argument)
{
    SIZE_T length = wcslen(argument);
    SIZE_T output = 0U;
    SIZE_T slash_count = 0U;
    SIZE_T index;
    BOOL quote = length == 0U || wcspbrk(argument, L" \t\n\v\"") != NULL;

    if (!quote) {
        return length;
    }
    output = 2U;
    for (index = 0U; index < length; ++index) {
        if (argument[index] == L'\\') {
            ++slash_count;
        } else if (argument[index] == L'"') {
            output += slash_count * 2U + 2U;
            slash_count = 0U;
        } else {
            output += slash_count + 1U;
            slash_count = 0U;
        }
    }
    return output + slash_count * 2U;
}

static void wls_append_quoted_argument(wchar_t *output, SIZE_T *offset, const wchar_t *argument)
{
    SIZE_T length = wcslen(argument);
    SIZE_T slash_count = 0U;
    SIZE_T index;
    BOOL quote = length == 0U || wcspbrk(argument, L" \t\n\v\"") != NULL;

    if (!quote) {
        (void)memcpy(output + *offset, argument, length * sizeof(wchar_t));
        *offset += length;
        return;
    }
    output[(*offset)++] = L'"';
    for (index = 0U; index < length; ++index) {
        if (argument[index] == L'\\') {
            ++slash_count;
            continue;
        }
        if (argument[index] == L'"') {
            SIZE_T repeat;
            for (repeat = 0U; repeat < slash_count * 2U + 1U; ++repeat) {
                output[(*offset)++] = L'\\';
            }
            output[(*offset)++] = L'"';
        } else {
            SIZE_T repeat;
            for (repeat = 0U; repeat < slash_count; ++repeat) {
                output[(*offset)++] = L'\\';
            }
            output[(*offset)++] = argument[index];
        }
        slash_count = 0U;
    }
    {
        SIZE_T repeat;
        for (repeat = 0U; repeat < slash_count * 2U; ++repeat) {
            output[(*offset)++] = L'\\';
        }
    }
    output[(*offset)++] = L'"';
}

static wchar_t *wls_build_command_line(int argc, wchar_t **argv, int command_index)
{
    SIZE_T required = 1U;
    SIZE_T offset = 0U;
    int index;
    wchar_t *command_line;

    if (command_index < 0 || command_index >= argc
        || argc - command_index > WLS_MAX_ARGUMENTS) {
        return NULL;
    }
    for (index = command_index; index < argc; ++index) {
        SIZE_T argument_length = wls_quoted_argument_length(argv[index]);
        if (argument_length >= WLS_MAX_COMMAND_LINE
            || required > WLS_MAX_COMMAND_LINE - argument_length - 1U) {
            return NULL;
        }
        required += argument_length;
        if (index > command_index) {
            ++required;
        }
    }
    command_line = (wchar_t *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        required * sizeof(wchar_t)
    );
    if (command_line == NULL) {
        return NULL;
    }
    for (index = command_index; index < argc; ++index) {
        if (index > command_index) {
            command_line[offset++] = L' ';
        }
        wls_append_quoted_argument(command_line, &offset, argv[index]);
    }
    command_line[offset] = L'\0';
    return command_line;
}

static BOOL wls_parse_timeout(const wchar_t *value, ULONGLONG *timeout_ms)
{
    wchar_t *end = NULL;
    unsigned long long parsed;

    if (value == NULL || value[0] == L'\0' || value[0] == L'-') {
        return FALSE;
    }
    parsed = wcstoull(value, &end, 10);
    if (end == value || end == NULL || *end != L'\0'
        || parsed == 0ULL || parsed > WLS_MAX_TIMEOUT_MS) {
        return FALSE;
    }
    *timeout_ms = (ULONGLONG)parsed;
    return TRUE;
}

static BOOL wls_parse_options(int argc, wchar_t **argv, WLS_OPTIONS *options)
{
    int index;

    (void)memset(options, 0, sizeof(*options));
    for (index = 1; index < argc; ++index) {
        const wchar_t *argument = argv[index];
        if (wcscmp(argument, L"--") == 0) {
            options->command_index = index + 1;
            break;
        }
        if (wcsncmp(argument, L"--result-dir=", 13U) == 0) {
            if (options->result_dir != NULL || argument[13] == L'\0') {
                return FALSE;
            }
            options->result_dir = argument + 13;
        } else if (wcsncmp(argument, L"--timeout-ms=", 13U) == 0) {
            if (options->timeout_ms != 0U
                || !wls_parse_timeout(argument + 13, &options->timeout_ms)) {
                return FALSE;
            }
        } else if (wcsncmp(argument, L"--cwd=", 6U) == 0) {
            if (options->working_dir != NULL || argument[6] == L'\0') {
                return FALSE;
            }
            options->working_dir = argument + 6;
        } else {
            return FALSE;
        }
    }
    return options->result_dir != NULL
        && options->timeout_ms != 0U
        && options->command_index > 0
        && options->command_index < argc;
}

static BOOL wls_process_image_matches(HANDLE process, const wchar_t *expected)
{
    wchar_t image[WLS_MAX_NATIVE_PATH];
    DWORD length = (DWORD)WLS_MAX_NATIVE_PATH;

    if (!QueryFullProcessImageNameW(process, 0U, image, &length)
        || length == 0U || (SIZE_T)length >= WLS_MAX_NATIVE_PATH) {
        return FALSE;
    }
    image[length] = L'\0';
    return _wcsicmp(image, expected) == 0;
}

static BOOL wls_publish_results(
    HANDLE stdout_file,
    HANDLE stderr_file,
    HANDLE *result_temp_file,
    const wchar_t *result_temp_path,
    const wchar_t *result_path,
    const WLS_CAPTURE_CHANNEL *stdout_capture,
    const WLS_CAPTURE_CHANNEL *stderr_capture,
    DWORD exit_code,
    BOOL timed_out,
    BOOL truncated,
    ULONGLONG deadline,
    BOOL *deadline_expired
)
{
    BYTE stdout_digest[32];
    BYTE stderr_digest[32];
    char stdout_hex[65];
    char stderr_hex[65];
    char json[1024];
    int json_length;
    DWORD remaining = 0U;

    if (deadline_expired == NULL) {
        SetLastError(ERROR_INVALID_PARAMETER);
        return FALSE;
    }
    *deadline_expired = FALSE;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) goto expired;
    if (!wls_write_all(stdout_file, stdout_capture->buffer, stdout_capture->length)) {
        return FALSE;
    }
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) goto expired;
    if (!FlushFileBuffers(stdout_file)) return FALSE;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) goto expired;
    if (!wls_write_all(stderr_file, stderr_capture->buffer, stderr_capture->length)) {
        return FALSE;
    }
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) goto expired;
    if (!FlushFileBuffers(stderr_file)) return FALSE;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) goto expired;
    if (!wls_sha256(stdout_capture->buffer, stdout_capture->length, stdout_digest)
        || !wls_sha256(stderr_capture->buffer, stderr_capture->length, stderr_digest)) {
        return FALSE;
    }
    wls_hex_digest(stdout_digest, stdout_hex);
    wls_hex_digest(stderr_digest, stderr_hex);
    json_length = snprintf(
        json,
        sizeof(json),
        "{\"schema\":\"wls-bounded-command-result/1\","
        "\"exit_code\":%lu,\"timed_out\":%s,\"truncated\":%s,"
        "\"stdout_bytes\":%lu,\"stderr_bytes\":%lu,"
        "\"stdout_sha256\":\"%s\",\"stderr_sha256\":\"%s\"}\n",
        (unsigned long)exit_code,
        timed_out ? "true" : "false",
        truncated ? "true" : "false",
        (unsigned long)stdout_capture->length,
        (unsigned long)stderr_capture->length,
        stdout_hex,
        stderr_hex
    );
    if (json_length <= 0 || (SIZE_T)json_length >= sizeof(json)
        || result_temp_file == NULL
        || *result_temp_file == NULL
        || *result_temp_file == INVALID_HANDLE_VALUE
        || !wls_write_all(*result_temp_file, (const BYTE *)json, (SIZE_T)json_length)) {
        return FALSE;
    }
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) goto expired;
    if (!FlushFileBuffers(*result_temp_file)) return FALSE;
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) goto expired;
    if (!CloseHandle(*result_temp_file)) {
        return FALSE;
    }
    *result_temp_file = NULL;
    if (!MoveFileExW(result_temp_path, result_path, MOVEFILE_WRITE_THROUGH)) {
        return FALSE;
    }
    if (!wls_pipe_remaining_milliseconds(deadline, &remaining)) {
        (void)DeleteFileW(result_path);
        goto expired;
    }
    return TRUE;

expired:
    *deadline_expired = TRUE;
    SetLastError(ERROR_TIMEOUT);
    return FALSE;
}

static int wls_self_test(void)
{
    static const char expected[] =
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    HANDLE job = wls_create_job();
    BYTE digest[32];
    char digest_hex[65];

    if (job == NULL || !wls_sha256((const BYTE *)"", 0U, digest)) {
        wls_close_handle(&job);
        return WLS_EXIT_INTERNAL;
    }
    wls_hex_digest(digest, digest_hex);
    wls_close_handle(&job);
    if (strcmp(digest_hex, expected) != 0) {
        return WLS_EXIT_INTERNAL;
    }
    (void)fputs("wls-bounded-command self-test ok\n", stdout);
    return 0;
}

static int wls_pipe_deadline_self_test(void)
{
    HMODULE kernel = GetModuleHandleW(L"kernel32.dll");
    wchar_t pipe_path[192];
    HANDLE server = INVALID_HANDLE_VALUE;
    HANDLE client = INVALID_HANDLE_VALUE;
    BYTE byte = 0U;
    DWORD transferred = 0U;
    BOOL timed_out = FALSE;
    BOOL abandoned = FALSE;
    ULONGLONG started;
    ULONGLONG finished;
    int result = WLS_EXIT_INTERNAL;

    if (kernel == NULL
        || GetProcAddress(kernel, "WaitNamedPipeW") == NULL
        || GetProcAddress(kernel, "CancelIoEx") == NULL
        || GetProcAddress(kernel, "GetTickCount64") == NULL
        || WLS_PIPE_MAX_FRAME_BYTES != (SIZE_T)4194304U
        || wcscmp(WLS_PIPE_ADMIN_PATH, WLS_PIPE_PROJECT_PATH) == 0) {
        return WLS_EXIT_INTERNAL;
    }
    if (swprintf_s(
            pipe_path,
            sizeof(pipe_path) / sizeof(pipe_path[0]),
            L"\\\\.\\pipe\\weline-wls-bounded-command-deadline-self-test-%lu-%llu",
            (unsigned long)GetCurrentProcessId(),
            (unsigned long long)GetTickCount64()
        ) <= 0) {
        goto cleanup;
    }
    server = CreateNamedPipeW(
        pipe_path,
        PIPE_ACCESS_DUPLEX | FILE_FLAG_FIRST_PIPE_INSTANCE,
        PIPE_TYPE_BYTE | PIPE_READMODE_BYTE | PIPE_WAIT | PIPE_REJECT_REMOTE_CLIENTS,
        1U,
        4096U,
        4096U,
        0U,
        NULL
    );
    if (server == INVALID_HANDLE_VALUE) {
        goto cleanup;
    }
    client = CreateFileW(
        pipe_path,
        GENERIC_READ | GENERIC_WRITE,
        0U,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_OVERLAPPED
            | SECURITY_SQOS_PRESENT
            | SECURITY_IDENTIFICATION
            | SECURITY_EFFECTIVE_ONLY,
        NULL
    );
    if (client == INVALID_HANDLE_VALUE
        || (ConnectNamedPipe(server, NULL)
            ? FALSE
            : GetLastError() != ERROR_PIPE_CONNECTED)) {
        goto cleanup;
    }
    started = GetTickCount64();
    if (wls_pipe_overlapped_io_until(
            client,
            FALSE,
            &byte,
            1U,
            &transferred,
            started + 25U,
            &timed_out,
            &abandoned
        )
        || !timed_out
        || !abandoned
        || transferred != 0U) {
        goto cleanup;
    }
    finished = GetTickCount64();
    if (finished < started || finished - started > 2000U) {
        goto cleanup;
    }
    result = 0;

cleanup:
    wls_close_handle(&client);
    wls_close_handle(&server);
    if (result != 0) {
        return result;
    }
    (void)fputs(
        "wls-bounded-command named-pipe deadline self-test ok\n",
        stdout
    );
    return result;
}

int wmain(int argc, wchar_t **argv)
{
    WLS_OPTIONS options;
    wchar_t result_dir[WLS_MAX_NATIVE_PATH];
    wchar_t result_parent[WLS_MAX_NATIVE_PATH];
    wchar_t working_dir[WLS_MAX_NATIVE_PATH];
    wchar_t executable[WLS_MAX_NATIVE_PATH];
    wchar_t stdout_path[WLS_MAX_NATIVE_PATH];
    wchar_t stderr_path[WLS_MAX_NATIVE_PATH];
    wchar_t result_temp_path[WLS_MAX_NATIVE_PATH];
    wchar_t result_path[WLS_MAX_NATIVE_PATH];
    WLS_PATH_GUARD result_parent_guard;
    WLS_PATH_GUARD result_guard;
    WLS_PATH_GUARD working_guard;
    WLS_PATH_GUARD executable_guard;
    HANDLE executable_lock = NULL;
    HANDLE stdout_file = NULL;
    HANDLE stderr_file = NULL;
    HANDLE result_temp_file = NULL;
    HANDLE stdout_read = NULL;
    HANDLE stdout_write = NULL;
    HANDLE stderr_read = NULL;
    HANDLE stderr_write = NULL;
    HANDLE stdin_null = NULL;
    HANDLE job = NULL;
    HANDLE stdout_thread = NULL;
    HANDLE stderr_thread = NULL;
    LPPROC_THREAD_ATTRIBUTE_LIST attribute_list = NULL;
    SIZE_T attribute_bytes = 0U;
    PROCESS_INFORMATION process;
    STARTUPINFOEXW startup;
    WLS_CAPTURE_SHARED capture_shared;
    WLS_CAPTURE_CHANNEL stdout_capture;
    WLS_CAPTURE_CHANNEL stderr_capture;
    wchar_t *command_line = NULL;
    BYTE *stdout_buffer = NULL;
    BYTE *stderr_buffer = NULL;
    HANDLE inherited_handles[3];
    ULONGLONG deadline;
    ULONGLONG publication_deadline = 0U;
    DWORD exit_code = WLS_EXIT_INTERNAL;
    BOOL timed_out = FALSE;
    BOOL publication_deadline_expired = FALSE;
    BOOL result_paths_ready = FALSE;
    BOOL result_published = FALSE;
    BOOL capture_initialized = FALSE;
    BOOL process_created = FALSE;
    BOOL attribute_list_initialized = FALSE;
    int return_code = WLS_EXIT_INTERNAL;

    if (argc > 1 && wcscmp(argv[1], L"--pipe-prepare") == 0) {
        return wls_pipe_prepare(argc, argv);
    }
    if (argc > 1 && wcscmp(argv[1], L"--pipe-exchange") == 0) {
        return wls_pipe_exchange(argc, argv);
    }
    if (argc > 1 && wcscmp(argv[1], L"--pipe-reap-orphan") == 0) {
        return wls_pipe_reap_orphan(argc, argv);
    }
    if (argc == 2
        && wcscmp(argv[1], L"--pipe-deadline-self-test") == 0) {
        return wls_pipe_deadline_self_test();
    }
    if (argc == 2 && wcscmp(argv[1], L"--self-test") == 0) {
        return wls_self_test();
    }
    if (!wls_parse_options(argc, argv, &options)) {
        (void)fputs(
            "usage: wls-bounded-command --result-dir=<new-local-path> "
            "--timeout-ms=<1..3600000> [--cwd=<local-path>] -- <exe> [args...]; "
            "or --pipe-prepare/--pipe-exchange/--pipe-reap-orphan "
            "with their fixed arguments\n",
            stderr
        );
        return WLS_EXIT_USAGE;
    }
    deadline = GetTickCount64() + options.timeout_ms;
    (void)memset(&result_parent_guard, 0, sizeof(result_parent_guard));
    (void)memset(&result_guard, 0, sizeof(result_guard));
    (void)memset(&working_guard, 0, sizeof(working_guard));
    (void)memset(&executable_guard, 0, sizeof(executable_guard));
    (void)memset(&process, 0, sizeof(process));
    (void)memset(&startup, 0, sizeof(startup));
    (void)memset(&capture_shared, 0, sizeof(capture_shared));
    (void)memset(&stdout_capture, 0, sizeof(stdout_capture));
    (void)memset(&stderr_capture, 0, sizeof(stderr_capture));

    if (!wls_normalize_local_path(options.result_dir, result_dir, WLS_MAX_NATIVE_PATH, FALSE)
        || wcslen(result_dir) + wcslen(L"\\result.json.tmp") + 1U
            > WLS_MAX_NATIVE_PATH
        || !wls_parent_path(result_dir, result_parent, WLS_MAX_NATIVE_PATH)
        || !wls_guard_existing_path(result_parent, TRUE, &result_parent_guard)) {
        wls_print_error(L"result parent path is not a guarded local directory", GetLastError());
        return_code = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    if (!wls_create_private_directory(result_dir)) {
        wls_print_error(L"result directory must not already exist and must be creatable", GetLastError());
        return_code = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    if (!wls_guard_existing_path(result_dir, TRUE, &result_guard)
        || !wls_join_path(result_dir, L"stdout.bin", stdout_path, WLS_MAX_NATIVE_PATH)
        || !wls_join_path(result_dir, L"stderr.bin", stderr_path, WLS_MAX_NATIVE_PATH)
        || !wls_join_path(result_dir, L"result.json.tmp", result_temp_path, WLS_MAX_NATIVE_PATH)
        || !wls_join_path(result_dir, L"result.json", result_path, WLS_MAX_NATIVE_PATH)) {
        wls_print_error(L"result directory could not be guarded", GetLastError());
        return_code = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    result_paths_ready = TRUE;
    stdout_file = wls_create_result_file(stdout_path);
    stderr_file = wls_create_result_file(stderr_path);
    result_temp_file = wls_create_result_file(result_temp_path);
    if (stdout_file == INVALID_HANDLE_VALUE || stderr_file == INVALID_HANDLE_VALUE
        || result_temp_file == INVALID_HANDLE_VALUE) {
        wls_print_error(L"exclusive result files could not be created", GetLastError());
        return_code = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }

    if (options.working_dir != NULL) {
        if (!wls_normalize_local_path(
            options.working_dir,
            working_dir,
            WLS_MAX_NATIVE_PATH,
            TRUE
        ) || !wls_guard_existing_path(working_dir, TRUE, &working_guard)) {
            wls_print_error(L"working directory is not a guarded local directory", GetLastError());
            return_code = WLS_EXIT_FILESYSTEM;
            goto cleanup;
        }
    } else {
        DWORD cwd_length = GetCurrentDirectoryW((DWORD)WLS_MAX_NATIVE_PATH, working_dir);
        wchar_t normalized_cwd[WLS_MAX_NATIVE_PATH];
        if (cwd_length == 0U || (SIZE_T)cwd_length >= WLS_MAX_NATIVE_PATH
            || !wls_normalize_local_path(
                working_dir,
                normalized_cwd,
                WLS_MAX_NATIVE_PATH,
                TRUE
            ) || !wls_guard_existing_path(normalized_cwd, TRUE, &working_guard)) {
            wls_print_error(L"current directory is not a guarded local directory", GetLastError());
            return_code = WLS_EXIT_FILESYSTEM;
            goto cleanup;
        }
        if (wcscpy_s(working_dir, WLS_MAX_NATIVE_PATH, normalized_cwd) != 0) {
            wls_print_error(L"normalized current directory is too long", ERROR_BUFFER_OVERFLOW);
            return_code = WLS_EXIT_FILESYSTEM;
            goto cleanup;
        }
    }
    if (!wls_normalize_local_path(
        argv[options.command_index],
        executable,
        WLS_MAX_NATIVE_PATH,
        FALSE
    ) || !wls_guard_existing_path(executable, FALSE, &executable_guard)) {
        wls_print_error(L"executable is not a guarded local file", GetLastError());
        return_code = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    executable_lock = CreateFileW(
        executable,
        GENERIC_READ,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (executable_lock == INVALID_HANDLE_VALUE) {
        wls_print_error(L"executable could not be locked against mutation", GetLastError());
        return_code = WLS_EXIT_FILESYSTEM;
        goto cleanup;
    }
    command_line = wls_build_command_line(argc, argv, options.command_index);
    stdout_buffer = (BYTE *)HeapAlloc(GetProcessHeap(), 0U, WLS_CAPTURE_LIMIT);
    stderr_buffer = (BYTE *)HeapAlloc(GetProcessHeap(), 0U, WLS_CAPTURE_LIMIT);
    if (command_line == NULL || stdout_buffer == NULL || stderr_buffer == NULL
        || !wls_create_capture_pipe(&stdout_read, &stdout_write)
        || !wls_create_capture_pipe(&stderr_read, &stderr_write)) {
        wls_print_error(L"bounded capture initialization failed", GetLastError());
        goto cleanup;
    }
    stdin_null = CreateFileW(
        L"NUL",
        GENERIC_READ,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL,
        NULL
    );
    if (stdin_null == INVALID_HANDLE_VALUE) {
        wls_print_error(L"NUL stdin could not be opened", GetLastError());
        goto cleanup;
    }
    if (!SetHandleInformation(stdin_null, HANDLE_FLAG_INHERIT, HANDLE_FLAG_INHERIT)) {
        wls_print_error(L"stdin inheritance could not be restricted", GetLastError());
        goto cleanup;
    }

    InitializeCriticalSection(&capture_shared.lock);
    capture_initialized = TRUE;
    stdout_capture.pipe = stdout_read;
    stdout_capture.buffer = stdout_buffer;
    stdout_capture.shared = &capture_shared;
    stderr_capture.pipe = stderr_read;
    stderr_capture.buffer = stderr_buffer;
    stderr_capture.shared = &capture_shared;
    startup.StartupInfo.cb = (DWORD)sizeof(startup);
    startup.StartupInfo.dwFlags = STARTF_USESTDHANDLES;
    startup.StartupInfo.hStdInput = stdin_null;
    startup.StartupInfo.hStdOutput = stdout_write;
    startup.StartupInfo.hStdError = stderr_write;
    (void)InitializeProcThreadAttributeList(NULL, 2U, 0U, &attribute_bytes);
    if (attribute_bytes == 0U) {
        wls_print_error(L"process handle-list sizing failed", GetLastError());
        goto cleanup;
    }
    attribute_list = (LPPROC_THREAD_ATTRIBUTE_LIST)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        attribute_bytes
    );
    if (attribute_list == NULL
        || !InitializeProcThreadAttributeList(attribute_list, 2U, 0U, &attribute_bytes)) {
        wls_print_error(L"process handle-list initialization failed", GetLastError());
        goto cleanup;
    }
    attribute_list_initialized = TRUE;
    inherited_handles[0] = stdin_null;
    inherited_handles[1] = stdout_write;
    inherited_handles[2] = stderr_write;
    if (!UpdateProcThreadAttribute(
        attribute_list,
        0U,
        PROC_THREAD_ATTRIBUTE_HANDLE_LIST,
        inherited_handles,
        sizeof(inherited_handles),
        NULL,
        NULL
    )) {
        wls_print_error(L"process handle-list restriction failed", GetLastError());
        goto cleanup;
    }
    job = wls_create_job();
    if (job == NULL) {
        wls_print_error(L"kill-on-close Job creation failed", GetLastError());
        goto cleanup;
    }
    if (!UpdateProcThreadAttribute(
        attribute_list,
        0U,
        PROC_THREAD_ATTRIBUTE_JOB_LIST,
        &job,
        sizeof(job),
        NULL,
        NULL
    )) {
        wls_print_error(L"atomic process Job assignment could not be configured", GetLastError());
        goto cleanup;
    }
    startup.lpAttributeList = attribute_list;
    if (!CreateProcessW(
        executable,
        command_line,
        NULL,
        NULL,
        TRUE,
        CREATE_NO_WINDOW | CREATE_SUSPENDED | EXTENDED_STARTUPINFO_PRESENT,
        NULL,
        working_dir,
        &startup.StartupInfo,
        &process
    )) {
        wls_print_error(L"target process creation failed", GetLastError());
        goto cleanup;
    }
    process_created = TRUE;
    {
        BOOL assigned = FALSE;
        if (!IsProcessInJob(process.hProcess, job, &assigned) || !assigned) {
            DWORD error = GetLastError();
            (void)TerminateProcess(process.hProcess, WLS_EXIT_INTERNAL);
            (void)WaitForSingleObject(process.hProcess, WLS_TERMINATION_GRACE_MS);
            wls_print_error(L"target was not atomically assigned to the kill-on-close Job", error);
            goto cleanup;
        }
    }
    if (!wls_process_image_matches(process.hProcess, executable)) {
        (void)wls_terminate_job_and_wait(job, process.hProcess, WLS_EXIT_INTERNAL);
        wls_print_error(L"created process image does not match the guarded executable", ERROR_BAD_EXE_FORMAT);
        goto cleanup;
    }
    wls_close_handle(&stdout_write);
    wls_close_handle(&stderr_write);
    stdout_thread = CreateThread(NULL, 0U, wls_capture_thread, &stdout_capture, 0U, NULL);
    stderr_thread = CreateThread(NULL, 0U, wls_capture_thread, &stderr_capture, 0U, NULL);
    if (stdout_thread == NULL || stderr_thread == NULL) {
        (void)wls_terminate_job_and_wait(job, process.hProcess, WLS_EXIT_INTERNAL);
        wls_print_error(L"capture threads could not be created", GetLastError());
        goto cleanup;
    }
    if (GetTickCount64() >= deadline) {
        timed_out = TRUE;
        exit_code = WLS_EXIT_TIMEOUT;
        if (!wls_terminate_job_and_wait(job, process.hProcess, exit_code)) {
            wls_print_error(L"suspended target tree could not be terminated", GetLastError());
            goto cleanup;
        }
    } else {
        DWORD resumed = ResumeThread(process.hThread);
        DWORD wait_result;
        DWORD remaining;
        if (resumed == (DWORD)-1) {
            (void)wls_terminate_job_and_wait(job, process.hProcess, WLS_EXIT_INTERNAL);
            wls_print_error(L"assigned target could not be resumed", GetLastError());
            goto cleanup;
        }
        {
            ULONGLONG now = GetTickCount64();
            if (now >= deadline) {
                wait_result = WAIT_TIMEOUT;
            } else {
                remaining = (DWORD)(deadline - now);
                wait_result = WaitForSingleObject(process.hProcess, remaining);
            }
        }
        if (wait_result == WAIT_TIMEOUT) {
            timed_out = TRUE;
            exit_code = WLS_EXIT_TIMEOUT;
            if (!wls_terminate_job_and_wait(job, process.hProcess, exit_code)) {
                wls_print_error(L"timed-out target tree could not be terminated", GetLastError());
                goto cleanup;
            }
        } else if (wait_result != WAIT_OBJECT_0
            || !GetExitCodeProcess(process.hProcess, &exit_code)) {
            (void)wls_terminate_job_and_wait(job, process.hProcess, WLS_EXIT_INTERNAL);
            wls_print_error(L"target wait or exit-code query failed", GetLastError());
            goto cleanup;
        } else if (!wls_wait_job_empty_until(job, deadline)) {
            timed_out = TRUE;
            exit_code = WLS_EXIT_TIMEOUT;
            if (!wls_terminate_job_and_wait(job, process.hProcess, exit_code)) {
                wls_print_error(L"surviving target descendants could not be terminated", GetLastError());
                goto cleanup;
            }
        }
    }

    {
        HANDLE threads[2];
        DWORD joined;
        threads[0] = stdout_thread;
        threads[1] = stderr_thread;
        joined = WaitForMultipleObjects(2U, threads, TRUE, WLS_TERMINATION_GRACE_MS);
        if (joined != WAIT_OBJECT_0) {
            (void)CancelSynchronousIo(stdout_thread);
            (void)CancelSynchronousIo(stderr_thread);
            wls_close_handle(&stdout_read);
            wls_close_handle(&stderr_read);
            joined = WaitForMultipleObjects(2U, threads, TRUE, WLS_TERMINATION_GRACE_MS);
            if (joined != WAIT_OBJECT_0) {
                wls_print_error(L"capture threads did not stop within the fixed grace", ERROR_TIMEOUT);
                goto cleanup;
            }
        }
    }
    if (stdout_capture.io_error != 0L || stderr_capture.io_error != 0L) {
        wls_print_error(L"child output capture failed", ERROR_READ_FAULT);
        goto cleanup;
    }
    publication_deadline = GetTickCount64() + WLS_RESULT_PUBLICATION_GRACE_MS;
    if (!wls_publish_results(
        stdout_file,
        stderr_file,
        &result_temp_file,
        result_temp_path,
        result_path,
        &stdout_capture,
        &stderr_capture,
        exit_code,
        timed_out,
        capture_shared.truncated,
        publication_deadline,
        &publication_deadline_expired
    )) {
        if (publication_deadline_expired) {
            timed_out = TRUE;
            return_code = WLS_EXIT_TIMEOUT;
        }
        wls_print_error(L"durable result publication failed", GetLastError());
        goto cleanup;
    }
    result_published = TRUE;
    return_code = 0;

cleanup:
    if (process_created && job != NULL && !wls_job_is_empty(job)) {
        (void)TerminateJobObject(job, WLS_EXIT_INTERNAL);
    }
    wls_close_handle(&stdout_write);
    wls_close_handle(&stderr_write);
    if (stdout_thread != NULL) {
        (void)CancelSynchronousIo(stdout_thread);
    }
    if (stderr_thread != NULL) {
        (void)CancelSynchronousIo(stderr_thread);
    }
    wls_close_handle(&stdout_read);
    wls_close_handle(&stderr_read);
    if (stdout_thread != NULL
        && WaitForSingleObject(stdout_thread, WLS_TERMINATION_GRACE_MS) != WAIT_OBJECT_0) {
        ExitProcess((UINT)return_code);
    }
    if (stderr_thread != NULL
        && WaitForSingleObject(stderr_thread, WLS_TERMINATION_GRACE_MS) != WAIT_OBJECT_0) {
        ExitProcess((UINT)return_code);
    }
    wls_close_handle(&stdout_thread);
    wls_close_handle(&stderr_thread);
    wls_close_handle(&process.hThread);
    wls_close_handle(&process.hProcess);
    wls_close_handle(&job);
    wls_close_handle(&stdin_null);
    if (capture_initialized) {
        DeleteCriticalSection(&capture_shared.lock);
    }
    if (attribute_list_initialized) {
        DeleteProcThreadAttributeList(attribute_list);
    }
    if (attribute_list != NULL) {
        (void)HeapFree(GetProcessHeap(), 0U, attribute_list);
    }
    if (command_line != NULL) {
        (void)HeapFree(GetProcessHeap(), 0U, command_line);
    }
    if (stdout_buffer != NULL) {
        SecureZeroMemory(stdout_buffer, WLS_CAPTURE_LIMIT);
        (void)HeapFree(GetProcessHeap(), 0U, stdout_buffer);
    }
    if (stderr_buffer != NULL) {
        SecureZeroMemory(stderr_buffer, WLS_CAPTURE_LIMIT);
        (void)HeapFree(GetProcessHeap(), 0U, stderr_buffer);
    }
    wls_close_handle(&executable_lock);
    wls_close_handle(&result_temp_file);
    if (result_paths_ready && !result_published) {
        (void)DeleteFileW(result_temp_path);
        (void)DeleteFileW(result_path);
    }
    wls_close_handle(&stdout_file);
    wls_close_handle(&stderr_file);
    wls_guard_close(&executable_guard);
    wls_guard_close(&working_guard);
    wls_guard_close(&result_guard);
    wls_guard_close(&result_parent_guard);
    return return_code;
}
