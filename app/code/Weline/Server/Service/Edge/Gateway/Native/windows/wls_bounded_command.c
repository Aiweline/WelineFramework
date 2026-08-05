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

#define WLS_CAPTURE_LIMIT ((SIZE_T)262144U)
#define WLS_CAPTURE_CHUNK ((DWORD)8192U)
#define WLS_MAX_NATIVE_PATH ((SIZE_T)240U)
#define WLS_MAX_COMMAND_LINE ((SIZE_T)32760U)
#define WLS_MAX_ARGUMENTS 4096
#define WLS_MAX_PATH_HANDLES 128
#define WLS_MAX_TIMEOUT_MS ((ULONGLONG)3600000U)
#define WLS_TERMINATION_GRACE_MS ((DWORD)2000U)
#define WLS_EXIT_USAGE 64
#define WLS_EXIT_INTERNAL 70
#define WLS_EXIT_FILESYSTEM 71
#define WLS_EXIT_TIMEOUT 124

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
    BOOL truncated
)
{
    BYTE stdout_digest[32];
    BYTE stderr_digest[32];
    char stdout_hex[65];
    char stderr_hex[65];
    char json[1024];
    int json_length;

    if (!wls_write_all(stdout_file, stdout_capture->buffer, stdout_capture->length)
        || !FlushFileBuffers(stdout_file)
        || !wls_write_all(stderr_file, stderr_capture->buffer, stderr_capture->length)
        || !FlushFileBuffers(stderr_file)
        || !wls_sha256(stdout_capture->buffer, stdout_capture->length, stdout_digest)
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
        || !wls_write_all(*result_temp_file, (const BYTE *)json, (SIZE_T)json_length)
        || !FlushFileBuffers(*result_temp_file)) {
        return FALSE;
    }
    if (!CloseHandle(*result_temp_file)) {
        return FALSE;
    }
    *result_temp_file = NULL;
    return MoveFileExW(result_temp_path, result_path, MOVEFILE_WRITE_THROUGH);
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
    DWORD exit_code = WLS_EXIT_INTERNAL;
    BOOL timed_out = FALSE;
    BOOL capture_initialized = FALSE;
    BOOL process_created = FALSE;
    BOOL attribute_list_initialized = FALSE;
    int return_code = WLS_EXIT_INTERNAL;

    if (argc == 2 && wcscmp(argv[1], L"--self-test") == 0) {
        return wls_self_test();
    }
    if (!wls_parse_options(argc, argv, &options)) {
        (void)fputs(
            "usage: wls-bounded-command --result-dir=<new-local-path> "
            "--timeout-ms=<1..3600000> [--cwd=<local-path>] -- <exe> [args...]\n",
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
        capture_shared.truncated
    )) {
        wls_print_error(L"durable result publication failed", GetLastError());
        goto cleanup;
    }
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
    wls_close_handle(&stdout_file);
    wls_close_handle(&stderr_file);
    wls_guard_close(&executable_guard);
    wls_guard_close(&working_guard);
    wls_guard_close(&result_guard);
    wls_guard_close(&result_parent_guard);
    return return_code;
}
