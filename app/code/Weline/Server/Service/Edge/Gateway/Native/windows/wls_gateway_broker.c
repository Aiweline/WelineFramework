#include <winsock2.h>
#include <ws2tcpip.h>
#include <windows.h>
#include <winternl.h>
#include <tlhelp32.h>
#include <sddl.h>
#include <aclapi.h>
#include <bcrypt.h>
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
#define WLS_FENCING_BYTES 32U
#define WLS_PATH_CHARS 32768U
#define WLS_CONTROLLER_START_ATTEMPTS 4500U
#define WLS_CONTROLLER_START_POLL_MS 10U
#define WLS_CONTROLLER_IO_TIMEOUT_MS 3000U
#define WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS 90000U
#define WLS_PROJECT_CONTROLLER_IO_TIMEOUT_MS 90000U

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

struct wls_channel {
    const wchar_t *public_pipe;
    unsigned short controller_port;
    const wchar_t *channel;
    const wchar_t *security_sddl;
    const char *fencing;
    const wchar_t *home;
    HANDLE stop_event;
};

static HANDLE wls_stop_event = NULL;

static int wls_owner_sid(HANDLE handle, char *output, size_t capacity);

static BOOL WINAPI wls_console_handler(DWORD signal_type)
{
    if (signal_type == CTRL_C_EVENT
        || signal_type == CTRL_BREAK_EVENT
        || signal_type == CTRL_CLOSE_EVENT
        || signal_type == CTRL_SHUTDOWN_EVENT) {
        if (wls_stop_event != NULL) {
            SetEvent(wls_stop_event);
        }
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
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
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
        FILE_OPEN,
        1
    );
}

static int wls_private_acl_safe(HANDLE file)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PACL dacl = NULL;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    WELL_KNOWN_SID_TYPE broad_types[] = {
        WinWorldSid,
        WinAuthenticatedUserSid,
        WinBuiltinUsersSid
    };
    size_t index;
    int result = 0;
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
        || dacl == NULL) {
        goto cleanup;
    }
    for (index = 0U; index < sizeof(broad_types) / sizeof(broad_types[0]); index++) {
        BYTE sid_buffer[SECURITY_MAX_SID_SIZE];
        DWORD sid_length = sizeof(sid_buffer);
        TRUSTEE_W trustee;
        ACCESS_MASK rights = 0U;
        if (!CreateWellKnownSid(
            broad_types[index],
            NULL,
            sid_buffer,
            &sid_length
        )) {
            goto cleanup;
        }
        BuildTrusteeWithSidW(&trustee, sid_buffer);
        if (GetEffectiveRightsFromAclW(dacl, &trustee, &rights) != ERROR_SUCCESS
            || (rights & (GENERIC_READ | FILE_READ_DATA | FILE_READ_EA)) != 0U) {
            goto cleanup;
        }
    }
    result = 1;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    return result;
}

static int wls_snapshot(
    const wchar_t *source_root_path,
    const wchar_t *source_relative,
    const wchar_t *destination_root_path,
    const wchar_t *destination_relative,
    const char *expected_owner_sid,
    int require_private
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
    BYTE buffer[65536];
    DWORD read_amount;
    DWORD write_amount;
    uint64_t total = 0U;
    char source_owner[256];
    int result = 1;

    source_root = wls_open_root(source_root_path, FILE_LIST_DIRECTORY | FILE_TRAVERSE);
    destination_root = wls_open_root(
        destination_root_path,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | DELETE
    );
    if (source_root == INVALID_HANDLE_VALUE || destination_root == INVALID_HANDLE_VALUE) goto cleanup;
    source = wls_open_relative(
        source_root,
        source_relative,
        FILE_GENERIC_READ,
        FILE_OPEN,
        0
    );
    if (source == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(source, FileStandardInfo, &before_size, sizeof(before_size))
        || !GetFileInformationByHandleEx(source, FileBasicInfo, &before_time, sizeof(before_time))
        || (expected_owner_sid != NULL
            && (wls_owner_sid(source, source_owner, sizeof(source_owner)) != 0
                || _stricmp(source_owner, expected_owner_sid) != 0))
        || (require_private && !wls_private_acl_safe(source))) {
        goto cleanup;
    }
    destination_parent = wls_open_parent(
        destination_root,
        destination_relative,
        destination_leaf,
        sizeof(destination_leaf) / sizeof(destination_leaf[0])
    );
    if (destination_parent == INVALID_HANDLE_VALUE) goto cleanup;
    if (_snwprintf_s(
        temporary_leaf,
        sizeof(temporary_leaf) / sizeof(temporary_leaf[0]),
        _TRUNCATE,
        L".wls-snapshot-%lu-%llu",
        GetCurrentProcessId(),
        (unsigned long long)GetTickCount64()
    ) < 0) goto cleanup;
    temporary = wls_nt_open_child(
        destination_parent,
        temporary_leaf,
        wcslen(temporary_leaf),
        FILE_GENERIC_WRITE | DELETE | SYNCHRONIZE,
        FILE_CREATE,
        0
    );
    if (temporary == INVALID_HANDLE_VALUE) goto cleanup;
    while (ReadFile(source, buffer, sizeof(buffer), &read_amount, NULL) && read_amount > 0U) {
        total += read_amount;
        if (total > WLS_MAX_SNAPSHOT
            || !WriteFile(temporary, buffer, read_amount, &write_amount, NULL)
            || write_amount != read_amount) {
            goto cleanup;
        }
    }
    if (!FlushFileBuffers(temporary)
        || !GetFileInformationByHandleEx(source, FileStandardInfo, &after_size, sizeof(after_size))
        || !GetFileInformationByHandleEx(source, FileBasicInfo, &after_time, sizeof(after_time))
        || before_size.EndOfFile.QuadPart != after_size.EndOfFile.QuadPart
        || before_time.LastWriteTime.QuadPart != after_time.LastWriteTime.QuadPart) {
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
    return 1;
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
        || !ReadFile(file, output, (DWORD)size.EndOfFile.QuadPart, &amount, NULL)
        || amount != (DWORD)size.EndOfFile.QuadPart) {
        if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
        return 1;
    }
    CloseHandle(file);
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
    HANDLE file = INVALID_HANDLE_VALUE;
    DWORD written = 0U;
    int result = 1;
    if (_snwprintf_s(
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
        || !WriteFile(file, contents, length, &written, NULL)
        || written != length
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

static int wls_write_upgrade_healthy(
    const wchar_t *home,
    const wchar_t *active_slot,
    const char *runtime_generation
) {
    wchar_t intent_path[WLS_PATH_CHARS];
    wchar_t healthy_path[WLS_PATH_CHARS];
    char payload[192];
    int length;
    HANDLE intent;
    if (home == NULL || active_slot == NULL
        || (active_slot[0] != L'A' && active_slot[0] != L'B')
        || active_slot[1] != L'\0'
        || !wls_is_hex(runtime_generation, 64U)
        || wls_join_w(intent_path, WLS_PATH_CHARS, home, L"trust\\upgrade.intent") != 0
        || wls_join_w(healthy_path, WLS_PATH_CHARS, home, L"trust\\upgrade-healthy") != 0) {
        return 1;
    }
    intent = CreateFileW(
        intent_path,
        GENERIC_READ,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (intent == INVALID_HANDLE_VALUE || wls_handle_is_reparse(intent)) {
        if (intent != INVALID_HANDLE_VALUE) CloseHandle(intent);
        return 1;
    }
    CloseHandle(intent);
    length = _snprintf_s(
        payload,
        sizeof(payload),
        _TRUNCATE,
        "WLS-UPGRADE-HEALTHY/1\nto=%c\nruntime_generation=%s\n",
        (char)active_slot[0],
        runtime_generation
    );
    return length > 0 ? wls_atomic_bytes(healthy_path, payload, (DWORD)length) : 1;
}

static int wls_registry_path(
    wchar_t *output,
    size_t capacity,
    const wchar_t *home
) {
    return wls_join_w(output, capacity, home, L"state\\broker-enrollments.tsv");
}

static int wls_registry_append(const wchar_t *home, const char *record)
{
    wchar_t path[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    OVERLAPPED lock = {0};
    LARGE_INTEGER size;
    DWORD written;
    size_t length;
    int result = 1;
    if (record == NULL || wls_registry_path(path, WLS_PATH_CHARS, home) != 0) return 1;
    length = strlen(record);
    if (length < 1U || length > 131072U) return 1;
    file = CreateFileW(
        path,
        FILE_APPEND_DATA | SYNCHRONIZE,
        FILE_SHARE_READ,
        NULL,
        OPEN_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT | FILE_FLAG_WRITE_THROUGH,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(file)
        || !GetFileSizeEx(file, &size)
        || size.QuadPart < 0
        || size.QuadPart > 4LL * 1024LL * 1024LL
        || !LockFileEx(
            file,
            LOCKFILE_EXCLUSIVE_LOCK,
            0U,
            MAXDWORD,
            MAXDWORD,
            &lock
        )
        || !WriteFile(file, record, (DWORD)length, &written, NULL)
        || written != (DWORD)length
        || !FlushFileBuffers(file)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) {
        UnlockFileEx(file, 0U, MAXDWORD, MAXDWORD, &lock);
        CloseHandle(file);
    }
    return result;
}

static int wls_parse_generation(const char *value, unsigned long long *generation)
{
    char *end = NULL;
    unsigned long long parsed;
    if (value == NULL || generation == NULL || value[0] == '\0') return 1;
    parsed = _strtoui64(value, &end, 10);
    if (end == value || *end != '\0' || parsed == 0U) return 1;
    *generation = parsed;
    return 0;
}

static int wls_authorize_root(
    const wchar_t *home,
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
    char actual_owner[256];
    char *record = NULL;
    size_t record_capacity;
    unsigned long long generation;
    int result = 1;
    if (!wls_is_uuid(project) || !wls_is_alias(alias)
        || !wls_is_sid_text(owner_sid)
        || wls_parse_generation(generation_text, &generation) != 0
        || wls_hex_to_wide(project_root_hex, project_root, WLS_PATH_CHARS) != 0
        || wls_hex_to_wide(certificate_root_hex, certificate_root, WLS_PATH_CHARS) != 0) {
        return 1;
    }
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
        || wls_owner_sid(project_handle, actual_owner, sizeof(actual_owner)) != 0
        || _stricmp(actual_owner, owner_sid) != 0
        || wls_final_path(project_handle, project_final, WLS_PATH_CHARS) != 0
        || wls_final_path(certificate_handle, certificate_final, WLS_PATH_CHARS) != 0
        || !wls_path_within(certificate_final, project_final)) {
        goto cleanup;
    }
    record_capacity = strlen(project) + strlen(generation_text) + strlen(owner_sid)
        + strlen(alias) + strlen(certificate_root_hex) + 16U;
    record = (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, record_capacity);
    if (record == NULL
        || _snprintf_s(
            record,
            record_capacity,
            _TRUNCATE,
            "A\t%s\t%s\t%s\t%s\t%s\n",
            project,
            generation_text,
            owner_sid,
            alias,
            certificate_root_hex
        ) < 0
        || wls_registry_append(home, record) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
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
            "R\t%s\t%s\n",
            project,
            generation_text
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
    LARGE_INTEGER size;
    char *contents = NULL;
    char *context = NULL;
    char *line;
    unsigned long long revoked = 0U;
    int found = 0;
    int result = 1;
    if (root_hex == NULL || wls_registry_path(path, WLS_PATH_CHARS, home) != 0) return 1;
    *root_hex = NULL;
    file = CreateFileW(
        path,
        GENERIC_READ,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE || wls_handle_is_reparse(file)
        || !GetFileSizeEx(file, &size)
        || size.QuadPart < 1
        || size.QuadPart > 4LL * 1024LL * 1024LL) {
        goto cleanup;
    }
    contents = (char *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        (size_t)size.QuadPart + 1U
    );
    if (contents == NULL) goto cleanup;
    {
        DWORD amount;
        if (!ReadFile(file, contents, (DWORD)size.QuadPart, &amount, NULL)
            || amount != (DWORD)size.QuadPart) {
            goto cleanup;
        }
    }
    line = strtok_s(contents, "\r\n", &context);
    while (line != NULL) {
        char *field_context = NULL;
        char *kind = strtok_s(line, "\t", &field_context);
        char *record_project = strtok_s(NULL, "\t", &field_context);
        char *record_generation = strtok_s(NULL, "\t", &field_context);
        unsigned long long parsed = 0U;
        if (kind != NULL && record_project != NULL && record_generation != NULL
            && strcmp(record_project, project) == 0
            && wls_parse_generation(record_generation, &parsed) == 0) {
            if (strcmp(kind, "R") == 0) {
                if (parsed > revoked) revoked = parsed;
            } else if (strcmp(kind, "A") == 0 && parsed == generation) {
                char *record_owner = strtok_s(NULL, "\t", &field_context);
                char *record_alias = strtok_s(NULL, "\t", &field_context);
                char *record_root = strtok_s(NULL, "\t", &field_context);
                if (record_owner != NULL && record_alias != NULL && record_root != NULL
                    && strcmp(record_alias, alias) == 0
                    && wls_is_sid_text(record_owner)
                    && strlen(record_owner) + 1U <= owner_capacity
                    && wls_is_hex(record_root, strlen(record_root))) {
                    char *copy = (char *)HeapAlloc(
                        GetProcessHeap(),
                        HEAP_ZERO_MEMORY,
                        strlen(record_root) + 1U
                    );
                    if (copy == NULL) goto cleanup;
                    if (*root_hex != NULL) HeapFree(GetProcessHeap(), 0U, *root_hex);
                    strcpy_s(copy, strlen(record_root) + 1U, record_root);
                    *root_hex = copy;
                    strcpy_s(owner_sid, owner_capacity, record_owner);
                    found = 1;
                }
            }
        }
        line = strtok_s(NULL, "\r\n", &context);
    }
    if (found && revoked < generation && *root_hex != NULL) result = 0;
cleanup:
    if (result != 0 && *root_hex != NULL) {
        HeapFree(GetProcessHeap(), 0U, *root_hex);
        *root_hex = NULL;
    }
    if (contents != NULL) HeapFree(GetProcessHeap(), 0U, contents);
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return result;
}

static int wls_snapshot_enrolled(
    const wchar_t *home,
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
    HANDLE source_root_handle = INVALID_HANDLE_VALUE;
    char actual_owner[256];
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
    source_root_handle = wls_open_root(
        source_root,
        FILE_LIST_DIRECTORY | FILE_TRAVERSE | READ_CONTROL
    );
    if (source_root_handle == INVALID_HANDLE_VALUE
        || wls_owner_sid(source_root_handle, actual_owner, sizeof(actual_owner)) != 0
        || _stricmp(actual_owner, owner_sid) != 0) {
        goto cleanup;
    }
    CloseHandle(source_root_handle);
    source_root_handle = INVALID_HANDLE_VALUE;
    result = wls_snapshot(
        source_root,
        source_relative,
        destination_root,
        destination_relative,
        owner_sid,
        strcmp(leaf, "source-key.pem") == 0
    );
cleanup:
    if (source_root_handle != INVALID_HANDLE_VALUE) CloseHandle(source_root_handle);
    if (root_hex != NULL) HeapFree(GetProcessHeap(), 0U, root_hex);
    return result;
}

static int wls_peer_sid(HANDLE pipe, char *sid_utf8, size_t capacity, DWORD *pid)
{
    HANDLE token = NULL;
    DWORD required = 0U;
    TOKEN_USER *user = NULL;
    LPWSTR sid_string = NULL;
    int result = 1;
    if (!GetNamedPipeClientProcessId(pipe, pid)
        || !ImpersonateNamedPipeClient(pipe)
        || !OpenThreadToken(GetCurrentThread(), TOKEN_QUERY, TRUE, &token)) {
        goto cleanup;
    }
    GetTokenInformation(token, TokenUser, NULL, 0U, &required);
    user = (TOKEN_USER *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, required);
    if (user == NULL
        || !GetTokenInformation(token, TokenUser, user, required, &required)
        || !ConvertSidToStringSidW(user->User.Sid, &sid_string)
        || WideCharToMultiByte(
            CP_UTF8,
            WC_ERR_INVALID_CHARS,
            sid_string,
            -1,
            sid_utf8,
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
    RevertToSelf();
    return result;
}

static int wls_read_line(HANDLE pipe, char *buffer, DWORD capacity, DWORD *used)
{
    DWORD amount;
    *used = 0U;
    while (*used + 1U < capacity) {
        if (!ReadFile(pipe, buffer + *used, 1U, &amount, NULL) || amount != 1U) return 1;
        (*used)++;
        if (buffer[*used - 1U] == '\n') {
            buffer[*used] = '\0';
            return 0;
        }
    }
    SetLastError(ERROR_INSUFFICIENT_BUFFER);
    return 1;
}

static int wls_socket_read_line(
    SOCKET socket_handle,
    char *buffer,
    DWORD capacity,
    DWORD *used
) {
    *used = 0U;
    while (*used + 1U < capacity) {
        int amount = recv(socket_handle, buffer + *used, 1, 0);
        if (amount != 1) return 1;
        (*used)++;
        if (buffer[*used - 1U] == '\n') {
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

static SOCKET wls_connect_controller(unsigned short port, DWORD timeout)
{
    SOCKET controller;
    struct sockaddr_in address;
    controller = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
    if (controller == INVALID_SOCKET) return INVALID_SOCKET;
    setsockopt(
        controller,
        SOL_SOCKET,
        SO_RCVTIMEO,
        (const char *)&timeout,
        sizeof(timeout)
    );
    setsockopt(
        controller,
        SOL_SOCKET,
        SO_SNDTIMEO,
        (const char *)&timeout,
        sizeof(timeout)
    );
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
    return controller;
}

static int wls_handle_action(
    char *line,
    const wchar_t *channel,
    const char *peer_sid,
    const wchar_t *home
) {
    char *context = NULL;
    char *protocol = strtok_s(line, "\t\r\n", &context);
    char *operation = strtok_s(NULL, "\t\r\n", &context);
    char channel_utf8[32];
    if (protocol == NULL || operation == NULL
        || strcmp(protocol, "WLS-ACTION/1") != 0
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
    if (strcmp(operation, "STOP") == 0 && strcmp(channel_utf8, "admin") == 0) {
        char *epoch = strtok_s(NULL, "\t\r\n", &context);
        return wls_write_admin_stopped(home, epoch);
    }
    if (strcmp(operation, "AUTH") == 0 && strcmp(channel_utf8, "admin") == 0) {
        char *project = strtok_s(NULL, "\t\r\n", &context);
        char *generation = strtok_s(NULL, "\t\r\n", &context);
        char *owner = strtok_s(NULL, "\t\r\n", &context);
        char *alias = strtok_s(NULL, "\t\r\n", &context);
        char *project_root = strtok_s(NULL, "\t\r\n", &context);
        char *certificate_root = strtok_s(NULL, "\t\r\n", &context);
        return wls_authorize_root(
            home,
            project,
            generation,
            owner,
            alias,
            project_root,
            certificate_root
        );
    }
    if (strcmp(operation, "REVOKE") == 0 && strcmp(channel_utf8, "admin") == 0) {
        char *project = strtok_s(NULL, "\t\r\n", &context);
        char *generation = strtok_s(NULL, "\t\r\n", &context);
        return wls_revoke_roots(home, project, generation);
    }
    if (strcmp(operation, "SNAP") == 0 && strcmp(channel_utf8, "project") == 0) {
        char *project = strtok_s(NULL, "\t\r\n", &context);
        char *generation = strtok_s(NULL, "\t\r\n", &context);
        char *alias = strtok_s(NULL, "\t\r\n", &context);
        char *source_relative = strtok_s(NULL, "\t\r\n", &context);
        char *digest = strtok_s(NULL, "\t\r\n", &context);
        char *leaf = strtok_s(NULL, "\t\r\n", &context);
        return wls_snapshot_enrolled(
            home,
            peer_sid,
            project,
            generation,
            alias,
            source_relative,
            digest,
            leaf
        );
    }
    return 1;
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

static DWORD WINAPI wls_channel_thread(LPVOID context)
{
    struct wls_channel *channel = (struct wls_channel *)context;
    SECURITY_ATTRIBUTES security;
    HANDLE descriptor = wls_pipe_security(channel->security_sddl, &security);
    if (descriptor == NULL) return 10U;
    while (WaitForSingleObject(channel->stop_event, 0U) == WAIT_TIMEOUT) {
        HANDLE public_pipe = CreateNamedPipeW(
            channel->public_pipe,
            PIPE_ACCESS_DUPLEX | FILE_FLAG_FIRST_PIPE_INSTANCE,
            PIPE_TYPE_BYTE | PIPE_READMODE_BYTE | PIPE_WAIT | PIPE_REJECT_REMOTE_CLIENTS,
            1U,
            WLS_MAX_REQUEST,
            WLS_MAX_REQUEST,
            5000U,
            &security
        );
        BOOL connected;
        char *request = NULL;
        char *response = NULL;
        DWORD request_size = 0U;
        DWORD response_size = 0U;
        DWORD client_pid = 0U;
        char client_sid[256];
        char channel_utf8[32];
        char header[768];
        int header_size;
        SOCKET controller = INVALID_SOCKET;
        if (public_pipe == INVALID_HANDLE_VALUE) break;
        connected = ConnectNamedPipe(public_pipe, NULL)
            ? TRUE
            : GetLastError() == ERROR_PIPE_CONNECTED;
        if (!connected) {
            CloseHandle(public_pipe);
            continue;
        }
        request = (char *)HeapAlloc(GetProcessHeap(), 0U, WLS_MAX_REQUEST + 2U);
        response = (char *)HeapAlloc(GetProcessHeap(), 0U, WLS_MAX_REQUEST + 2U);
        if (request == NULL
            || response == NULL
            || wls_peer_sid(public_pipe, client_sid, sizeof(client_sid), &client_pid) != 0
            || wls_read_line(public_pipe, request, WLS_MAX_REQUEST + 2U, &request_size) != 0
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
                : WLS_PROJECT_CONTROLLER_IO_TIMEOUT_MS
        );
        if (controller == INVALID_SOCKET) goto request_cleanup;
        header_size = _snprintf_s(
            header,
            sizeof(header),
            _TRUNCATE,
            "{\"broker_schema\":1,\"action_protocol\":1,\"channel\":\"%s\",\"sid\":\"%s\","
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
        while (wls_socket_read_line(
            controller,
            response,
            WLS_MAX_REQUEST + 2U,
            &response_size
        ) == 0) {
            if (strncmp(response, "WLS-ACTION/1\t", 13U) == 0) {
                int action_result = wls_handle_action(
                    response,
                    channel->channel,
                    client_sid,
                    channel->home
                );
                char action_response[96];
                int action_length = _snprintf_s(
                    action_response,
                    sizeof(action_response),
                    _TRUNCATE,
                    action_result == 0
                        ? "WLS-ACTION/1\tOK\n"
                        : "WLS-ACTION/1\tERR\t%lu\n",
                    action_result == 0 ? 0UL : GetLastError()
                );
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
            WriteFile(public_pipe, response, response_size, &request_size, NULL);
            break;
        }
request_cleanup:
        if (controller != INVALID_SOCKET) closesocket(controller);
        if (request != NULL) HeapFree(GetProcessHeap(), 0U, request);
        if (response != NULL) HeapFree(GetProcessHeap(), 0U, response);
        FlushFileBuffers(public_pipe);
        DisconnectNamedPipe(public_pipe);
        CloseHandle(public_pipe);
    }
    LocalFree(descriptor);
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
    probe = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
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
    if (!OpenProcessToken(
        GetCurrentProcess(),
        TOKEN_DUPLICATE | TOKEN_QUERY,
        &process_token
    ) || !IsTokenRestricted(process_token)) {
        SetLastError(ERROR_ACCESS_DENIED);
        goto cleanup;
    }
    if (!DuplicateTokenEx(
        process_token,
        MAXIMUM_ALLOWED,
        NULL,
        SecurityImpersonation,
        TokenPrimary,
        &restricted
    ) || !AdjustTokenPrivileges(
        restricted,
        TRUE,
        NULL,
        0U,
        NULL,
        NULL
    )) {
        if (restricted != NULL) CloseHandle(restricted);
        restricted = NULL;
    }
cleanup:
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

static HANDLE wls_open_owned_nginx(
    const wchar_t *home,
    const wchar_t *active_slot,
    HANDLE controller,
    DWORD adopted_nginx_pid,
    DWORD *nginx_pid
) {
    wchar_t expected[WLS_PATH_CHARS];
    wchar_t expected_a[WLS_PATH_CHARS];
    wchar_t expected_b[WLS_PATH_CHARS];
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
        || (active_slot[0] != L'A' && active_slot[0] != L'B')
        || active_slot[1] != L'\0'
        || _snwprintf_s(expected, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\%ls\\bin\\nginx.exe", home, active_slot) < 0
        || _snwprintf_s(expected_a, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\A\\bin\\nginx.exe", home) < 0
        || _snwprintf_s(expected_b, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\B\\bin\\nginx.exe", home) < 0
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
    path_matches = adopted_nginx_pid > 0U
        ? (_wcsicmp(expected_a, actual) == 0 || _wcsicmp(expected_b, actual) == 0)
        : _wcsicmp(expected, actual) == 0;
    if (!path_matches) {
        CloseHandle(process);
        return NULL;
    }
    if (adopted_nginx_pid > 0U) {
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
        || !GetProcessTimes(process, &nginx_created, &nginx_exited,
            &nginx_kernel, &nginx_user)
        || CompareFileTime(&nginx_created, &controller_created) < 0
        || ((controller_exited.dwLowDateTime != 0U
                || controller_exited.dwHighDateTime != 0U)
            && CompareFileTime(&nginx_created, &controller_exited) > 0)) {
        CloseHandle(process);
        return NULL;
    }
    *nginx_pid = pid;
    return process;
}

static HANDLE wls_start_controller(
    const wchar_t *php,
    const wchar_t *controller,
    const wchar_t *home,
    const wchar_t *fencing_file,
    unsigned short controller_port,
    HANDLE controller_token,
    DWORD adopted_nginx_pid,
    DWORD *failure_stage
) {
    wchar_t command[WLS_PATH_CHARS * 3U];
    wchar_t controller_log[WLS_PATH_CHARS];
    SECURITY_ATTRIBUTES inherited;
    HANDLE input = INVALID_HANDLE_VALUE;
    HANDLE output = INVALID_HANDLE_VALUE;
    STARTUPINFOW startup;
    PROCESS_INFORMATION process;
    if (failure_stage != NULL) *failure_stage = 0U;
    if (php == NULL || controller == NULL || home == NULL || fencing_file == NULL
        || controller_token == NULL
        || wcschr(php, L'"') != NULL || wcschr(controller, L'"') != NULL
        || wcschr(home, L'"') != NULL || wcschr(fencing_file, L'"') != NULL
        || wls_join_w(
            controller_log,
            WLS_PATH_CHARS,
            home,
            L"runtime\\logs\\controller-native.log"
        ) != 0) {
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
            L"--broker-adopted-nginx-pid=%lu",
            php,
            controller,
            home,
            controller_port,
            fencing_file,
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
        L"--broker-fencing-file=\"%ls\"",
        php,
        controller,
        home,
        controller_port,
        fencing_file
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
    startup.cb = sizeof(startup);
    startup.dwFlags = STARTF_USESTDHANDLES;
    startup.hStdInput = input;
    startup.hStdOutput = output;
    startup.hStdError = output;
    if (!CreateProcessAsUserW(
        controller_token,
        php,
        command,
        NULL,
        NULL,
        TRUE,
        CREATE_NO_WINDOW | CREATE_UNICODE_ENVIRONMENT,
        NULL,
        NULL,
        &startup,
        &process
    )) {
        if (failure_stage != NULL) *failure_stage = 4U;
        CloseHandle(input);
        CloseHandle(output);
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
    const wchar_t *home
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
        probe = wls_connect_controller(port, WLS_CONTROLLER_IO_TIMEOUT_MS);
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
    const wchar_t *adopted_nginx_pid_text;
    wchar_t expected_fencing[WLS_PATH_CHARS];
    char runtime_generation[65];
    char fencing[WLS_FENCING_BYTES * 2U + 1U];
    HANDLE singleton = NULL;
    HANDLE controller_token = NULL;
    HANDLE controller_process = NULL;
    HANDLE nginx_process = NULL;
    HANDLE threads[2];
    HANDLE wait_handles[5];
    struct wls_channel channels[2];
    WSADATA winsock;
    unsigned short controller_port = 0U;
    DWORD nginx_pid = 0U;
    DWORD adopted_nginx_pid = 0U;
    DWORD controller_start_stage = 0U;
    ULONGLONG observation_started;
    int upgrade_marked = 0;
    int exit_code = 1;
    threads[0] = NULL;
    threads[1] = NULL;
    if (argc == 2 && wcscmp(argv[1], L"--self-test") == 0) {
        return wls_self_test();
    }
    if (argc > 1 && wcscmp(argv[1], L"--snapshot") == 0) {
        const wchar_t *source_root = wls_argument(argc, argv, L"--source-root");
        const wchar_t *source_relative = wls_argument(argc, argv, L"--source-relative");
        const wchar_t *destination_root = wls_argument(argc, argv, L"--destination-root");
        const wchar_t *destination_relative = wls_argument(argc, argv, L"--destination-relative");
        if (source_root == NULL || source_relative == NULL
            || destination_root == NULL || destination_relative == NULL) return 64;
        return wls_snapshot(
            source_root,
            source_relative,
            destination_root,
            destination_relative,
            NULL,
            0
        );
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
            L"runtime\\run\\fencing-token"
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
    wls_stop_event = OpenEventW(
        SYNCHRONIZE | EVENT_MODIFY_STATE,
        FALSE,
        stop_event_name
    );
    if (wls_stop_event == NULL) {
        CloseHandle(singleton);
        WSACleanup();
        return 72;
    }
    SetConsoleCtrlHandler(wls_console_handler, TRUE);
    if (wls_allocate_controller_port(&controller_port) != 0) {
        exit_code = 73;
        goto cleanup;
    }
    if (wls_write_fencing_file(fencing_file, fencing) != 0) {
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
        adopted_nginx_pid,
        &controller_start_stage
    );
    if (controller_process == NULL) {
        exit_code = 75;
        goto cleanup;
    }
    if (wls_wait_for_controller(controller_port, controller_process, home) != 0) {
        exit_code = 76;
        goto cleanup;
    }
    nginx_process = wls_open_owned_nginx(
        home,
        active_slot,
        controller_process,
        adopted_nginx_pid,
        &nginx_pid
    );
    if (nginx_process == NULL) {
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
        wls_stop_event
    };
    channels[1] = (struct wls_channel){
        project_pipe,
        controller_port,
        L"project",
        L"D:P(A;;GA;;;SY)(A;;GRGW;;;AU)",
        fencing,
        home,
        wls_stop_event
    };
    threads[0] = CreateThread(NULL, 0U, wls_channel_thread, &channels[0], 0U, NULL);
    threads[1] = CreateThread(NULL, 0U, wls_channel_thread, &channels[1], 0U, NULL);
    if (threads[0] == NULL || threads[1] == NULL) {
        exit_code = 77;
        goto cleanup;
    }
    observation_started = GetTickCount64();
    wait_handles[0] = controller_process;
    wait_handles[1] = wls_stop_event;
    wait_handles[2] = threads[0];
    wait_handles[3] = threads[1];
    wait_handles[4] = nginx_process;
    for (;;) {
        DWORD wait = WaitForMultipleObjects(5U, wait_handles, FALSE, 1000U);
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
                if (WaitForSingleObject(wls_stop_event, delay) == WAIT_OBJECT_0) {
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
                        home
                    ) == 0) {
                    wait_handles[0] = controller_process;
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
        if (wait == WAIT_OBJECT_0 + 4U) {
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
        if (wait == WAIT_OBJECT_0 + 2U) {
            exit_code = 82;
            break;
        }
        if (wait == WAIT_OBJECT_0 + 3U) {
            exit_code = 83;
            break;
        }
        if (wait == WAIT_FAILED) {
            exit_code = 84;
            break;
        }
        if (!upgrade_marked
            && GetTickCount64() - observation_started >= 300000ULL
            && wls_write_upgrade_healthy(
                home,
                active_slot,
                runtime_generation
            ) == 0) {
            upgrade_marked = 1;
        }
    }
cleanup:
    if (wls_stop_event != NULL) SetEvent(wls_stop_event);
    if (admin_pipe != NULL) wls_wake_pipe(admin_pipe);
    if (project_pipe != NULL) wls_wake_pipe(project_pipe);
    if (threads[0] != NULL || threads[1] != NULL) {
        HANDLE live_threads[2];
        DWORD count = 0U;
        if (threads[0] != NULL) live_threads[count++] = threads[0];
        if (threads[1] != NULL) live_threads[count++] = threads[1];
        WaitForMultipleObjects(count, live_threads, TRUE, 5000U);
    }
    if (controller_process != NULL) {
        if (WaitForSingleObject(controller_process, 0U) == WAIT_TIMEOUT) {
            TerminateProcess(controller_process, exit_code == 0 ? 0U : 1U);
        }
        WaitForSingleObject(controller_process, 5000U);
        CloseHandle(controller_process);
    }
    if (nginx_process != NULL) CloseHandle(nginx_process);
    if (controller_token != NULL) CloseHandle(controller_token);
    DeleteFileW(fencing_file);
    if (threads[0] != NULL) CloseHandle(threads[0]);
    if (threads[1] != NULL) CloseHandle(threads[1]);
    if (wls_stop_event != NULL) CloseHandle(wls_stop_event);
    wls_stop_event = NULL;
    if (singleton != NULL) {
        ReleaseMutex(singleton);
        CloseHandle(singleton);
    }
    WSACleanup();
    SecureZeroMemory(fencing, sizeof(fencing));
    return exit_code;
}
