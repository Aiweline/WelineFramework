#define _CRT_SECURE_NO_WARNINGS
#include <windows.h>
#include <aclapi.h>
#include <sddl.h>
#include <shlobj.h>
#include <sodium.h>
#include <errno.h>
#include <limits.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <wchar.h>

#include "wls_gateway_capacity.h"

#define WLS_CAPACITY_PATH_CHARS 4096U
#define WLS_CAPACITY_TEST_BYTES 8388608ULL
#define WLS_CAPACITY_TEST_INODES 128U
#define WLS_CAPACITY_PRODUCTION_BYTES 10737418240ULL
#define WLS_CAPACITY_PRODUCTION_INODES 65536U
#define WLS_CAPACITY_CONTROL_BYTES 1048576ULL
#define WLS_CAPACITY_CONTROL_INODES 16U
#define WLS_CAPACITY_PLATFORM_BYTES 4194304ULL
#define WLS_CAPACITY_PLATFORM_INODES 2U
#define WLS_CAPACITY_PLATFORM_BYTES_PER_FILE 2097152ULL
#define WLS_CAPACITY_TOKEN_FLUSH_BATCH 4096U
#define WLS_CAPACITY_TOKEN_SUFFIX L".reserve"
#define WLS_CAPACITY_RELEASE_MARKER "WLS-CAPACITY-RELEASE/1\n"
#define WLS_CAPACITY_CONTROL_REQUIRED 0
#define WLS_CAPACITY_CONTROL_TRANSITION 1
#define WLS_CAPACITY_CONTROL_ABSENT 2
#define WLS_CAPACITY_EVIDENCE_MAX_BYTES 16384U
#define WLS_CAPACITY_INSPECT_SCHEMA "wls-capacity-inspect/1"
#define WLS_CAPACITY_INSPECT_UNSAFE_EXIT 77
#define WLS_CAPACITY_INSPECT_CONFLICT_EXIT 78
#define WLS_CAPACITY_GATEWAY_SERVICE_SID \
    L"S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626"

static const GUID wls_capacity_folderid_programdata = {
    0x62AB5D82U,
    0xFDC1U,
    0x4DC3U,
    {0xA9U, 0xDDU, 0x07U, 0x0DU, 0x1DU, 0x49U, 0x5DU, 0x97U}
};

struct wls_capacity_observation {
    FILE_ID_INFO identity;
    FILE_STANDARD_INFO standard;
    FILE_BASIC_INFO basic;
    DWORD attributes;
};

struct wls_capacity_evidence {
    unsigned long long physical_bytes;
    unsigned int inode_count;
    char volume_id[65];
    char entry_set_sha256[65];
    char anchor_set_sha256[65];
};

struct wls_capacity_platform_anchor {
    wchar_t parent[WLS_CAPACITY_PATH_CHARS];
    wchar_t reserve_prefix[64];
    FILE_ID_INFO parent_identity;
    unsigned long long volume;
    int production_acl;
};

static int wls_capacity_test_configure(
    const struct wls_capacity_platform_anchor *anchor,
    int test_mode
);
static int wls_capacity_test_failpoint(const wchar_t *name);
static void wls_capacity_test_reset(void);
static int wls_capacity_platform_direct_descriptor(
    int production_acl,
    PSECURITY_DESCRIPTOR *descriptor
);
static int wls_capacity_directory_descriptor(
    int production_acl,
    PSECURITY_DESCRIPTOR *descriptor
);
static int wls_capacity_platform_direct_apply(
    HANDLE object,
    PSECURITY_DESCRIPTOR descriptor
);
static int wls_capacity_platform_direct_acl_exact(
    HANDLE object,
    int production_acl
);
static int wls_capacity_directory_acl_exact(
    HANDLE object,
    int production_acl
);

static const wchar_t *wls_capacity_argument(
    int argc,
    wchar_t **argv,
    const wchar_t *name
)
{
    int index;
    size_t length;
    if (argv == NULL || name == NULL) return NULL;
    length = wcslen(name);
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

int wls_windows_capacity_requested(int argc, wchar_t **argv)
{
    return wls_capacity_argument(argc, argv, L"--capacity-reserve") != NULL;
}

static int wls_capacity_wide_hex(
    const wchar_t *value,
    size_t length,
    char *ascii
)
{
    size_t index;
    if (value == NULL || wcslen(value) != length) return 0;
    for (index = 0U; index < length; index++) {
        if (!((value[index] >= L'0' && value[index] <= L'9')
            || (value[index] >= L'a' && value[index] <= L'f'))) {
            return 0;
        }
        if (ascii != NULL) ascii[index] = (char)value[index];
    }
    if (ascii != NULL) ascii[length] = '\0';
    return 1;
}

static int wls_capacity_unsigned(
    const wchar_t *value,
    unsigned long long maximum,
    unsigned long long *parsed
)
{
    const wchar_t *cursor;
    wchar_t *end = NULL;
    unsigned __int64 result;
    if (value == NULL || value[0] == L'\0' || parsed == NULL
        || value[0] == L'+' || value[0] == L'-') return 1;
    for (cursor = value; *cursor != L'\0'; cursor++) {
        if (*cursor < L'0' || *cursor > L'9') return 1;
    }
    errno = 0;
    result = _wcstoui64(value, &end, 10);
    if (errno != 0 || end == value || *end != L'\0'
        || result > maximum) return 1;
    *parsed = (unsigned long long)result;
    return 0;
}

static int wls_capacity_join(
    wchar_t *output,
    size_t capacity,
    const wchar_t *left,
    const wchar_t *right
)
{
    size_t length;
    int written;
    if (output == NULL || capacity == 0U || left == NULL || right == NULL) {
        return 1;
    }
    length = wcslen(left);
    written = _snwprintf_s(
        output,
        capacity,
        _TRUNCATE,
        length > 0U && left[length - 1U] == L'\\'
            ? L"%ls%ls" : L"%ls\\%ls",
        left,
        right
    );
    return written < 0 ? 1 : 0;
}

static int wls_capacity_path_segment_forbidden(const wchar_t *path)
{
    const wchar_t *segment;
    const wchar_t *cursor;
    size_t length;
    if (path == NULL) return 1;
    segment = path;
    for (cursor = path;; cursor++) {
        if (*cursor != L'\\' && *cursor != L'/' && *cursor != L'\0') {
            continue;
        }
        length = (size_t)(cursor - segment);
        if ((length == 1U && segment[0] == L'.')
            || (length == 2U && segment[0] == L'.'
                && segment[1] == L'.')) return 1;
        if (*cursor == L'\0') break;
        segment = cursor + 1U;
    }
    return 0;
}

static int wls_capacity_normalize_path(
    const wchar_t *input,
    wchar_t output[WLS_CAPACITY_PATH_CHARS]
)
{
    wchar_t expanded[WLS_CAPACITY_PATH_CHARS];
    DWORD amount;
    size_t index;
    size_t length;
    if (input == NULL || output == NULL) return 1;
    length = wcslen(input);
    if (length < 3U || length >= WLS_CAPACITY_PATH_CHARS
        || input[0] == L'\\' || input[0] == L'/'
        || input[1] != L':'
        || !((input[0] >= L'A' && input[0] <= L'Z')
            || (input[0] >= L'a' && input[0] <= L'z'))
        || (input[2] != L'\\' && input[2] != L'/')
        || wls_capacity_path_segment_forbidden(input)) return 1;
    for (index = 2U; input[index] != L'\0'; index++) {
        if (input[index] == L':') return 1;
    }
    amount = GetFullPathNameW(
        input,
        WLS_CAPACITY_PATH_CHARS,
        expanded,
        NULL
    );
    if (amount == 0U || amount >= WLS_CAPACITY_PATH_CHARS
        || expanded[1] != L':' || expanded[2] != L'\\') return 1;
    length = wcslen(expanded);
    for (index = 0U; index < length; index++) {
        output[index] = expanded[index] == L'/' ? L'\\' : expanded[index];
    }
    output[length] = L'\0';
    if (output[0] >= L'a' && output[0] <= L'z') {
        output[0] = (wchar_t)(output[0] - (L'a' - L'A'));
    }
    while (length > 3U && output[length - 1U] == L'\\') {
        output[--length] = L'\0';
    }
    return wls_capacity_path_segment_forbidden(output) ? 1 : 0;
}

static int wls_capacity_path_within(
    const wchar_t *path,
    const wchar_t *parent,
    int allow_equal
)
{
    size_t parent_length;
    if (path == NULL || parent == NULL) return 0;
    parent_length = wcslen(parent);
    if (_wcsnicmp(path, parent, parent_length) != 0) return 0;
    if (path[parent_length] == L'\0') return allow_equal;
    return path[parent_length] == L'\\';
}

static int wls_capacity_fixed_drive(const wchar_t *path)
{
    wchar_t root[4];
    if (path == NULL || wcslen(path) < 3U) return 1;
    root[0] = path[0];
    root[1] = L':';
    root[2] = L'\\';
    root[3] = L'\0';
    return GetDriveTypeW(root) == DRIVE_FIXED ? 0 : 1;
}

static int wls_capacity_known_home(
    wchar_t program_data[WLS_CAPACITY_PATH_CHARS],
    wchar_t home[WLS_CAPACITY_PATH_CHARS]
)
{
    PWSTR known = NULL;
    wchar_t weline[WLS_CAPACITY_PATH_CHARS];
    HRESULT status;
    int result = 1;
    if (program_data == NULL || home == NULL) return 1;
    status = SHGetKnownFolderPath(
        &wls_capacity_folderid_programdata,
        0U,
        NULL,
        &known
    );
    if (SUCCEEDED(status) && known != NULL
        && wls_capacity_normalize_path(known, program_data) == 0
        && wls_capacity_fixed_drive(program_data) == 0
        && wls_capacity_join(
            weline,
            WLS_CAPACITY_PATH_CHARS,
            program_data,
            L"Weline"
        ) == 0
        && wls_capacity_join(
            home,
            WLS_CAPACITY_PATH_CHARS,
            weline,
            L"Gateway"
        ) == 0) result = 0;
    if (known != NULL) CoTaskMemFree(known);
    SecureZeroMemory(weline, sizeof(weline));
    return result;
}

static int wls_capacity_identity_equal(
    const FILE_ID_INFO *left,
    const FILE_ID_INFO *right
)
{
    return left != NULL && right != NULL
        && left->VolumeSerialNumber == right->VolumeSerialNumber
        && memcmp(
            left->FileId.Identifier,
            right->FileId.Identifier,
            sizeof(left->FileId.Identifier)
        ) == 0;
}

static int wls_capacity_observation_equal(
    const struct wls_capacity_observation *left,
    const struct wls_capacity_observation *right
)
{
    return left != NULL && right != NULL
        && wls_capacity_identity_equal(&left->identity, &right->identity)
        && left->standard.AllocationSize.QuadPart
            == right->standard.AllocationSize.QuadPart
        && left->standard.EndOfFile.QuadPart
            == right->standard.EndOfFile.QuadPart
        && left->standard.NumberOfLinks == right->standard.NumberOfLinks
        && left->standard.DeletePending == right->standard.DeletePending
        && left->standard.Directory == right->standard.Directory
        && left->basic.LastWriteTime.QuadPart
            == right->basic.LastWriteTime.QuadPart
        && left->basic.ChangeTime.QuadPart
            == right->basic.ChangeTime.QuadPart
        && left->attributes == right->attributes;
}

static int wls_capacity_observe(
    HANDLE object,
    int directory,
    struct wls_capacity_observation *observation
)
{
    FILE_ATTRIBUTE_TAG_INFO attributes;
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || observation == NULL) return 1;
    ZeroMemory(observation, sizeof(*observation));
    ZeroMemory(&attributes, sizeof(attributes));
    if (!GetFileInformationByHandleEx(
            object,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || !GetFileInformationByHandleEx(
            object,
            FileIdInfo,
            &observation->identity,
            sizeof(observation->identity)
        )
        || !GetFileInformationByHandleEx(
            object,
            FileStandardInfo,
            &observation->standard,
            sizeof(observation->standard)
        )
        || !GetFileInformationByHandleEx(
            object,
            FileBasicInfo,
            &observation->basic,
            sizeof(observation->basic)
        )
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
        || ((attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U)
            != directory
        || (observation->standard.Directory != FALSE) != directory
        || observation->standard.DeletePending != FALSE
        || (!directory && observation->standard.NumberOfLinks != 1U)) {
        SecureZeroMemory(observation, sizeof(*observation));
        return 1;
    }
    observation->attributes = attributes.FileAttributes;
    return 0;
}

static int wls_capacity_sid(
    WELL_KNOWN_SID_TYPE type,
    unsigned char buffer[SECURITY_MAX_SID_SIZE],
    DWORD *length
)
{
    return buffer != NULL && length != NULL
        && CreateWellKnownSid(type, NULL, buffer, length)
        && IsValidSid((PSID)buffer) ? 0 : 1;
}

static int wls_capacity_owner_is_elevated_current_user(
    PSID owner,
    PSID administrators
)
{
    HANDLE token = NULL;
    TOKEN_USER *user = NULL;
    DWORD required = 0U;
    DWORD allocated = 0U;
    BOOL administrator = FALSE;
    int result = 0;
    if (owner == NULL || administrators == NULL
        || !OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &token)) {
        return 0;
    }
    (void)GetTokenInformation(token, TokenUser, NULL, 0U, &required);
    if (required == 0U) goto cleanup;
    allocated = required;
    user = (TOKEN_USER *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        allocated
    );
    if (user == NULL
        || !GetTokenInformation(token, TokenUser, user, allocated, &required)
        || required > allocated
        || !IsValidSid(user->User.Sid)
        || !EqualSid(owner, user->User.Sid)
        || !CheckTokenMembership(NULL, administrators, &administrator)
        || !administrator) goto cleanup;
    result = 1;
cleanup:
    if (user != NULL) {
        SecureZeroMemory(user, allocated);
        HeapFree(GetProcessHeap(), 0U, user);
    }
    CloseHandle(token);
    return result;
}

static int wls_capacity_acl_safe_mask(HANDLE object, DWORD forbidden)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PSID owner = NULL;
    PACL dacl = NULL;
    BOOL present = FALSE;
    BOOL defaulted = FALSE;
    unsigned char system_buffer[SECURITY_MAX_SID_SIZE];
    unsigned char administrators_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD administrators_length = sizeof(administrators_buffer);
    PSID service_sid = NULL;
    ACL_SIZE_INFORMATION information;
    GENERIC_MAPPING mapping;
    DWORD index;
    int result = 1;
    ZeroMemory(&information, sizeof(information));
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    mapping.GenericRead = FILE_GENERIC_READ;
    mapping.GenericWrite = FILE_GENERIC_WRITE;
    mapping.GenericExecute = FILE_GENERIC_EXECUTE;
    mapping.GenericAll = FILE_ALL_ACCESS;
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || wls_capacity_sid(
            WinLocalSystemSid,
            system_buffer,
            &system_length
        ) != 0
        || wls_capacity_sid(
            WinBuiltinAdministratorsSid,
            administrators_buffer,
            &administrators_length
        ) != 0
        || !ConvertStringSidToSidW(
            WLS_CAPACITY_GATEWAY_SERVICE_SID,
            &service_sid
        )
        || GetSecurityInfo(
            object,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &owner,
            NULL,
            &dacl,
            NULL,
            &descriptor
        ) != ERROR_SUCCESS
        || descriptor == NULL || owner == NULL || !IsValidSid(owner)
        || (!EqualSid(owner, system_buffer)
            && !EqualSid(owner, administrators_buffer)
            && !wls_capacity_owner_is_elevated_current_user(
                owner,
                administrators_buffer
            ))
        || !GetSecurityDescriptorDacl(
            descriptor,
            &present,
            &dacl,
            &defaulted
        )
        || !present || dacl == NULL || defaulted
        || !GetAclInformation(
            dacl,
            &information,
            sizeof(information),
            AclSizeInformation
        )) goto cleanup;
    for (index = 0U; index < information.AceCount; index++) {
        ACE_HEADER *header = NULL;
        ACCESS_ALLOWED_ACE *allowed;
        PSID sid;
        DWORD sid_length;
        const DWORD sid_offset = (DWORD)FIELD_OFFSET(
            ACCESS_ALLOWED_ACE,
            SidStart
        );
        DWORD mask;
        if (!GetAce(dacl, index, (LPVOID *)&header) || header == NULL) {
            goto cleanup;
        }
        if (header->AceType == ACCESS_DENIED_ACE_TYPE
            || (header->AceFlags & INHERIT_ONLY_ACE) != 0U) continue;
        if (header->AceType != ACCESS_ALLOWED_ACE_TYPE
            || header->AceSize
                < sid_offset + 8U) {
            goto cleanup;
        }
        allowed = (ACCESS_ALLOWED_ACE *)header;
        sid = (PSID)&allowed->SidStart;
        if (!IsValidSid(sid)
            || (sid_length = GetLengthSid(sid)) == 0U
            || sid_length != (DWORD)header->AceSize - sid_offset) {
            goto cleanup;
        }
        mask = allowed->Mask;
        MapGenericMask(&mask, &mapping);
        if (!EqualSid(sid, system_buffer)
            && !EqualSid(sid, administrators_buffer)
            && !EqualSid(sid, service_sid)
            && (mask & forbidden) != 0U) goto cleanup;
    }
    result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    if (service_sid != NULL) LocalFree(service_sid);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    return result;
}

static int wls_capacity_acl_safe(HANDLE object)
{
    return wls_capacity_acl_safe_mask(
        object,
        FILE_WRITE_DATA | FILE_APPEND_DATA | FILE_WRITE_EA
            | FILE_WRITE_ATTRIBUTES | FILE_DELETE_CHILD | DELETE
            | WRITE_DAC | WRITE_OWNER
    );
}

static int wls_capacity_parent_acl_safe(HANDLE object)
{
    return wls_capacity_acl_safe_mask(
        object,
        FILE_DELETE_CHILD | DELETE | WRITE_DAC | WRITE_OWNER
    );
}

static int wls_capacity_final_path_matches(
    HANDLE object,
    const wchar_t *expected
)
{
    wchar_t final_path[WLS_CAPACITY_PATH_CHARS];
    wchar_t normalized[WLS_CAPACITY_PATH_CHARS];
    const wchar_t *candidate = final_path;
    DWORD amount;
    if (object == NULL || object == INVALID_HANDLE_VALUE || expected == NULL) {
        return 1;
    }
    amount = GetFinalPathNameByHandleW(
        object,
        final_path,
        WLS_CAPACITY_PATH_CHARS,
        FILE_NAME_NORMALIZED | VOLUME_NAME_DOS
    );
    if (amount == 0U || amount >= WLS_CAPACITY_PATH_CHARS) return 1;
    if (wcsncmp(candidate, L"\\\\?\\", 4U) == 0) candidate += 4U;
    if (wls_capacity_normalize_path(candidate, normalized) != 0) return 1;
    return _wcsicmp(normalized, expected) == 0 ? 0 : 1;
}

static int wls_capacity_open_directory(
    const wchar_t *path,
    int production_acl,
    int writable,
    HANDLE *directory,
    struct wls_capacity_observation *observation
)
{
    HANDLE handle;
    struct wls_capacity_observation current;
    if (path == NULL || directory == NULL) return 1;
    *directory = INVALID_HANDLE_VALUE;
    handle = CreateFileW(
        path,
        GENERIC_READ | READ_CONTROL | (writable ? GENERIC_WRITE : 0U),
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (handle == INVALID_HANDLE_VALUE
        || wls_capacity_observe(handle, 1, &current) != 0
        || wls_capacity_final_path_matches(handle, path) != 0
        || (production_acl && wls_capacity_acl_safe(handle) != 0)) {
        if (handle != INVALID_HANDLE_VALUE) CloseHandle(handle);
        return 1;
    }
    if (observation != NULL) *observation = current;
    *directory = handle;
    return 0;
}

static int wls_capacity_validate_directory_chain(
    const wchar_t *path,
    int production_acl,
    const wchar_t *acl_from
)
{
    wchar_t normalized[WLS_CAPACITY_PATH_CHARS];
    wchar_t prefix[WLS_CAPACITY_PATH_CHARS];
    size_t length;
    size_t index;
    if (wls_capacity_normalize_path(path, normalized) != 0) return 1;
    length = wcslen(normalized);
    if (length >= WLS_CAPACITY_PATH_CHARS) return 1;
    for (index = 0U; index <= length; index++) {
        HANDLE directory = INVALID_HANDLE_VALUE;
        int require_acl;
        if (index < 3U
            || (index != 3U && index < length
                && normalized[index] != L'\\')) {
            continue;
        }
        memcpy(prefix, normalized, index * sizeof(wchar_t));
        prefix[index] = L'\0';
        require_acl = production_acl && acl_from != NULL
            && wls_capacity_path_within(prefix, acl_from, 1);
        if (wls_capacity_open_directory(
                prefix,
                require_acl,
                0,
                &directory,
                NULL
            ) != 0) return 1;
        if (!CloseHandle(directory)) return 1;
    }
    SecureZeroMemory(prefix, sizeof(prefix));
    return 0;
}

static int wls_capacity_volume_handle(
    const wchar_t *path,
    HANDLE *volume
)
{
    wchar_t volume_path[7];
    HANDLE handle;
    if (path == NULL || volume == NULL || wcslen(path) < 3U) return 1;
    volume_path[0] = L'\\';
    volume_path[1] = L'\\';
    volume_path[2] = L'.';
    volume_path[3] = L'\\';
    volume_path[4] = path[0];
    volume_path[5] = L':';
    volume_path[6] = L'\0';
    handle = CreateFileW(
        volume_path,
        GENERIC_READ | GENERIC_WRITE,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_EXISTING,
        0U,
        NULL
    );
    if (handle == INVALID_HANDLE_VALUE) return 1;
    *volume = handle;
    return 0;
}

static unsigned int wls_capacity_token_flushes(unsigned int count)
{
    return count / WLS_CAPACITY_TOKEN_FLUSH_BATCH
        + (count % WLS_CAPACITY_TOKEN_FLUSH_BATCH == 0U ? 0U : 1U);
}

int wls_windows_capacity_contract_self_test(int emit_evidence)
{
    int result = sizeof(LARGE_INTEGER) == 8U
        && WLS_CAPACITY_TEST_BYTES == 8388608ULL
        && WLS_CAPACITY_TEST_INODES == 128U
        && WLS_CAPACITY_PRODUCTION_BYTES == 10737418240ULL
        && WLS_CAPACITY_PRODUCTION_INODES == 65536U
        && WLS_CAPACITY_PLATFORM_BYTES == 4194304ULL
        && WLS_CAPACITY_PLATFORM_INODES == 2U
        && WLS_CAPACITY_PLATFORM_BYTES_PER_FILE == 2097152ULL
        && WLS_CAPACITY_TOKEN_FLUSH_BATCH == 4096U
        && wls_capacity_token_flushes(WLS_CAPACITY_PRODUCTION_INODES) == 16U
        && wls_capacity_token_flushes(WLS_CAPACITY_TEST_INODES) == 1U
        ? 0 : 1;
    if (result == 0 && emit_evidence) {
        (void)printf(
            "{\"production_inodes\":%u,"
            "\"token_flush_batch\":%u,"
            "\"production_volume_flushes\":%u,"
            "\"test_volume_flushes\":%u}\n",
            WLS_CAPACITY_PRODUCTION_INODES,
            WLS_CAPACITY_TOKEN_FLUSH_BATCH,
            wls_capacity_token_flushes(WLS_CAPACITY_PRODUCTION_INODES),
            wls_capacity_token_flushes(WLS_CAPACITY_TEST_INODES)
        );
    }
    return result;
}

static int wls_capacity_wide_json_string(
    const wchar_t *value,
    char *output,
    size_t capacity
)
{
    char utf8[WLS_CAPACITY_PATH_CHARS * 3U];
    int length;
    size_t input;
    size_t used = 0U;
    if (value == NULL || output == NULL || capacity < 3U) return 1;
    length = WideCharToMultiByte(
        CP_UTF8,
        WC_ERR_INVALID_CHARS,
        value,
        -1,
        utf8,
        (int)sizeof(utf8),
        NULL,
        NULL
    );
    if (length <= 0) return 1;
    output[used++] = '"';
    for (input = 0U; input + 1U < (size_t)length; input++) {
        unsigned char byte = (unsigned char)utf8[input];
        if (byte < 0x20U) {
            SecureZeroMemory(utf8, sizeof(utf8));
            return 1;
        }
        if (byte == '"' || byte == '\\') {
            if (used + 2U >= capacity) {
                SecureZeroMemory(utf8, sizeof(utf8));
                return 1;
            }
            output[used++] = '\\';
        } else if (used + 1U >= capacity) {
            SecureZeroMemory(utf8, sizeof(utf8));
            return 1;
        }
        output[used++] = (char)byte;
    }
    if (used + 2U > capacity) {
        SecureZeroMemory(utf8, sizeof(utf8));
        return 1;
    }
    output[used++] = '"';
    output[used] = '\0';
    SecureZeroMemory(utf8, sizeof(utf8));
    return 0;
}

static int wls_capacity_authoritative_home(
    wchar_t home[WLS_CAPACITY_PATH_CHARS],
    FILE_ID_INFO *identity
)
{
    wchar_t program_data[WLS_CAPACITY_PATH_CHARS];
    wchar_t weline[WLS_CAPACITY_PATH_CHARS];
    HANDLE program_data_directory = INVALID_HANDLE_VALUE;
    HANDLE directory = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation observation;
    int result = 1;
    if (home == NULL
        || wls_capacity_known_home(program_data, home) != 0
        || wls_capacity_join(
            weline,
            WLS_CAPACITY_PATH_CHARS,
            program_data,
            L"Weline"
        ) != 0
        || wls_capacity_validate_directory_chain(
            program_data,
            0,
            NULL
        ) != 0
        || wls_capacity_open_directory(
            program_data,
            0,
            0,
            &program_data_directory,
            NULL
        ) != 0
        || wls_capacity_parent_acl_safe(program_data_directory) != 0
        || wls_capacity_validate_directory_chain(home, 1, weline) != 0
        || wls_capacity_open_directory(
            home,
            1,
            0,
            &directory,
            &observation
        ) != 0) goto cleanup;
    if (identity != NULL) *identity = observation.identity;
    result = 0;
cleanup:
    if (program_data_directory != INVALID_HANDLE_VALUE) {
        CloseHandle(program_data_directory);
    }
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    SecureZeroMemory(program_data, sizeof(program_data));
    SecureZeroMemory(weline, sizeof(weline));
    SecureZeroMemory(&observation, sizeof(observation));
    return result;
}

int wls_windows_programdata_authority(void)
{
    wchar_t home[WLS_CAPACITY_PATH_CHARS];
    FILE_ID_INFO identity;
    unsigned char digest[crypto_hash_sha256_BYTES];
    char volume_record[96];
    char volume_id[65];
    char json_path[WLS_CAPACITY_PATH_CHARS * 6U];
    int length;
    int result = 1;
    SecureZeroMemory(&identity, sizeof(identity));
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(volume_id, sizeof(volume_id));
    if (wls_capacity_authoritative_home(home, &identity) != 0
        || wls_capacity_wide_json_string(
            home,
            json_path,
            sizeof(json_path)
        ) != 0) goto cleanup;
    length = snprintf(
        volume_record,
        sizeof(volume_record),
        "volume=%llu\n",
        (unsigned long long)identity.VolumeSerialNumber
    );
    if (length <= 0 || (size_t)length >= sizeof(volume_record)
        || crypto_hash_sha256(
            digest,
            (const unsigned char *)volume_record,
            (unsigned long long)length
        ) != 0) goto cleanup;
    sodium_bin2hex(volume_id, sizeof(volume_id), digest, sizeof(digest));
    (void)printf(
        "{\"authority\":\"FOLDERID_ProgramData\","
        "\"home\":%s,\"ready\":true,\"volume_id\":\"%s\"}\n",
        json_path,
        volume_id
    );
    result = 0;
cleanup:
    SecureZeroMemory(home, sizeof(home));
    SecureZeroMemory(&identity, sizeof(identity));
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(volume_record, sizeof(volume_record));
    SecureZeroMemory(volume_id, sizeof(volume_id));
    SecureZeroMemory(json_path, sizeof(json_path));
    return result;
}

static int wls_capacity_open_file(
    const wchar_t *path,
    DWORD access,
    DWORD sharing,
    int production_acl,
    HANDLE *file,
    struct wls_capacity_observation *observation
)
{
    HANDLE handle;
    struct wls_capacity_observation current;
    DWORD forbidden_attributes = FILE_ATTRIBUTE_REPARSE_POINT
        | FILE_ATTRIBUTE_DIRECTORY | FILE_ATTRIBUTE_SPARSE_FILE
        | FILE_ATTRIBUTE_COMPRESSED | FILE_ATTRIBUTE_ENCRYPTED;
    if (path == NULL || file == NULL) return 1;
    *file = INVALID_HANDLE_VALUE;
    handle = CreateFileW(
        path,
        access | READ_CONTROL,
        sharing,
        NULL,
        OPEN_EXISTING,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (handle == INVALID_HANDLE_VALUE
        || wls_capacity_observe(handle, 0, &current) != 0
        || (current.attributes & forbidden_attributes) != 0U
        || wls_capacity_final_path_matches(handle, path) != 0
        || (production_acl && wls_capacity_acl_safe(handle) != 0)) {
        if (handle != INVALID_HANDLE_VALUE) CloseHandle(handle);
        return 1;
    }
    if (observation != NULL) *observation = current;
    *file = handle;
    return 0;
}

static int wls_capacity_create_directory(
    const wchar_t *path,
    int production_acl,
    HANDLE volume
)
{
    PSECURITY_DESCRIPTOR security = NULL;
    SECURITY_ATTRIBUTES attributes;
    HANDLE directory = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation observation;
    int created = 0;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    ZeroMemory(&observation, sizeof(observation));
    if (path == NULL || volume == NULL || volume == INVALID_HANDLE_VALUE
        || wls_capacity_directory_descriptor(
            production_acl, &security
        ) != 0) goto cleanup;
    attributes.nLength = (DWORD)sizeof(attributes);
    attributes.lpSecurityDescriptor = security;
    attributes.bInheritHandle = FALSE;
    if (!CreateDirectoryW(path, &attributes)) goto cleanup;
    created = 1;
    directory = CreateFileW(
        path,
        GENERIC_READ | READ_CONTROL | WRITE_DAC | WRITE_OWNER,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (directory == INVALID_HANDLE_VALUE
        || wls_capacity_observe(directory, 1, &observation) != 0
        || wls_capacity_final_path_matches(directory, path) != 0
        || wls_capacity_platform_direct_apply(directory, security) != 0
        || wls_capacity_directory_acl_exact(
            directory, production_acl
        ) != 0
        || !FlushFileBuffers(volume)) goto cleanup;
    result = 0;
cleanup:
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    if (result != 0 && created) (void)RemoveDirectoryW(path);
    if (security != NULL) LocalFree(security);
    SecureZeroMemory(&observation, sizeof(observation));
    return result;
}

static int wls_capacity_allocate_file(
    const wchar_t *path,
    unsigned long long bytes,
    int production_acl
)
{
    PSECURITY_DESCRIPTOR security = NULL;
    SECURITY_ATTRIBUTES attributes;
    HANDLE file = INVALID_HANDLE_VALUE;
    FILE_ALLOCATION_INFO allocation;
    FILE_END_OF_FILE_INFO end_of_file;
    struct wls_capacity_observation observation;
    LARGE_INTEGER offset;
    unsigned char marker = 0xA5U;
    DWORD written = 0U;
    DWORD forbidden_attributes = FILE_ATTRIBUTE_REPARSE_POINT
        | FILE_ATTRIBUTE_DIRECTORY | FILE_ATTRIBUTE_SPARSE_FILE
        | FILE_ATTRIBUTE_COMPRESSED | FILE_ATTRIBUTE_ENCRYPTED;
    int created = 0;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    ZeroMemory(&allocation, sizeof(allocation));
    ZeroMemory(&end_of_file, sizeof(end_of_file));
    ZeroMemory(&observation, sizeof(observation));
    if (path == NULL || bytes == 0ULL || bytes > LLONG_MAX
        || wls_capacity_platform_direct_descriptor(
            production_acl, &security
        ) != 0) return 1;
    attributes.nLength = (DWORD)sizeof(attributes);
    attributes.lpSecurityDescriptor = security;
    attributes.bInheritHandle = FALSE;
    file = CreateFileW(
        path,
        GENERIC_READ | GENERIC_WRITE | READ_CONTROL
            | WRITE_DAC | WRITE_OWNER,
        FILE_SHARE_READ,
        &attributes,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH
            | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file != INVALID_HANDLE_VALUE) created = 1;
    allocation.AllocationSize.QuadPart = (LONGLONG)bytes;
    end_of_file.EndOfFile.QuadPart = (LONGLONG)bytes;
    offset.QuadPart = 0LL;
    if (file == INVALID_HANDLE_VALUE
        || wls_capacity_platform_direct_apply(file, security) != 0
        || wls_capacity_platform_direct_acl_exact(
            file, production_acl
        ) != 0
        || !SetFileInformationByHandle(
            file,
            FileAllocationInfo,
            &allocation,
            sizeof(allocation)
        )
        || !SetFileInformationByHandle(
            file,
            FileEndOfFileInfo,
            &end_of_file,
            sizeof(end_of_file)
        )
        || !SetFilePointerEx(file, offset, NULL, FILE_BEGIN)
        || !WriteFile(file, &marker, 1U, &written, NULL)
        || written != 1U) goto cleanup;
    offset.QuadPart = (LONGLONG)(bytes - 1ULL);
    written = 0U;
    if (!SetFilePointerEx(file, offset, NULL, FILE_BEGIN)
        || !WriteFile(file, &marker, 1U, &written, NULL)
        || written != 1U
        || !FlushFileBuffers(file)
        || wls_capacity_observe(file, 0, &observation) != 0
        || (observation.attributes & forbidden_attributes) != 0U
        || observation.standard.EndOfFile.QuadPart != (LONGLONG)bytes
        || observation.standard.AllocationSize.QuadPart < (LONGLONG)bytes
        || observation.standard.NumberOfLinks != 1U
        || wls_capacity_platform_direct_acl_exact(
            file, production_acl
        ) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (result != 0 && created) (void)DeleteFileW(path);
    if (security != NULL) LocalFree(security);
    SecureZeroMemory(&observation, sizeof(observation));
    return result;
}

static int wls_capacity_token_leaf(
    unsigned int index,
    wchar_t leaf[17]
)
{
    return _snwprintf_s(
        leaf,
        17U,
        _TRUNCATE,
        L"%08x%ls",
        index,
        WLS_CAPACITY_TOKEN_SUFFIX
    ) == 16 ? 0 : 1;
}

static int wls_capacity_create_tokens(
    const wchar_t *directory,
    unsigned int count,
    int production_acl,
    HANDLE volume
)
{
    PSECURITY_DESCRIPTOR security = NULL;
    SECURITY_ATTRIBUTES attributes;
    unsigned int index;
    unsigned int flushes = 0U;
    ZeroMemory(&attributes, sizeof(attributes));
    if (directory == NULL || count == 0U
        || volume == NULL || volume == INVALID_HANDLE_VALUE
        || wls_capacity_platform_direct_descriptor(
            production_acl, &security
        ) != 0) return 1;
    attributes.nLength = (DWORD)sizeof(attributes);
    attributes.lpSecurityDescriptor = security;
    attributes.bInheritHandle = FALSE;
    if (wls_capacity_test_failpoint(L"token-directory") != 0) {
        LocalFree(security);
        return 1;
    }
    for (index = 0U; index < count; index++) {
        wchar_t leaf[17];
        wchar_t path[WLS_CAPACITY_PATH_CHARS];
        HANDLE token = INVALID_HANDLE_VALUE;
        struct wls_capacity_observation observation;
        int created = 0;
        if (wls_capacity_token_leaf(index, leaf) != 0
            || wls_capacity_join(
                path,
                WLS_CAPACITY_PATH_CHARS,
                directory,
                leaf
            ) != 0) {
            LocalFree(security);
            return 1;
        }
        token = CreateFileW(
            path,
            GENERIC_READ | GENERIC_WRITE | READ_CONTROL
                | WRITE_DAC | WRITE_OWNER,
            FILE_SHARE_READ,
            &attributes,
            CREATE_NEW,
            FILE_ATTRIBUTE_NORMAL | FILE_FLAG_OPEN_REPARSE_POINT,
            NULL
        );
        if (token != INVALID_HANDLE_VALUE) created = 1;
        if (token == INVALID_HANDLE_VALUE
            || wls_capacity_platform_direct_apply(token, security) != 0
            || wls_capacity_observe(token, 0, &observation) != 0
            || observation.standard.EndOfFile.QuadPart != 0LL
            || observation.standard.NumberOfLinks != 1U
            || wls_capacity_platform_direct_acl_exact(
                token, production_acl
            ) != 0) {
            if (token != INVALID_HANDLE_VALUE) CloseHandle(token);
            if (created) (void)DeleteFileW(path);
            LocalFree(security);
            return 1;
        }
        if (!CloseHandle(token)) {
            token = INVALID_HANDLE_VALUE;
            if (created) (void)DeleteFileW(path);
            LocalFree(security);
            return 1;
        }
        token = INVALID_HANDLE_VALUE;
        if ((index + 1U) % WLS_CAPACITY_TOKEN_FLUSH_BATCH == 0U
            || index + 1U == count) {
            if (!FlushFileBuffers(volume)) {
                LocalFree(security);
                return 1;
            }
            flushes++;
            if (flushes == 1U
                && wls_capacity_test_failpoint(L"token-batch") != 0) {
                LocalFree(security);
                return 1;
            }
        }
    }
    LocalFree(security);
    return flushes == wls_capacity_token_flushes(count) ? 0 : 1;
}

static int wls_capacity_parse_token_leaf(
    const wchar_t *leaf,
    unsigned int maximum,
    unsigned int *index
)
{
    wchar_t digits[9];
    wchar_t *end = NULL;
    unsigned long value;
    size_t cursor;
    if (leaf == NULL || index == NULL || maximum == 0U
        || wcslen(leaf) != 16U
        || wcscmp(leaf + 8U, WLS_CAPACITY_TOKEN_SUFFIX) != 0) return 1;
    memcpy(digits, leaf, 8U * sizeof(wchar_t));
    digits[8] = L'\0';
    for (cursor = 0U; cursor < 8U; cursor++) {
        if (!((digits[cursor] >= L'0' && digits[cursor] <= L'9')
            || (digits[cursor] >= L'a' && digits[cursor] <= L'f'))) {
            return 1;
        }
    }
    errno = 0;
    value = wcstoul(digits, &end, 16);
    if (errno != 0 || end == digits || *end != L'\0'
        || value >= maximum) return 1;
    *index = (unsigned int)value;
    return 0;
}

static int wls_capacity_file_id_compare(const void *left, const void *right)
{
    return memcmp(left, right, sizeof(FILE_ID_128));
}

static int wls_capacity_token_prefix_exact(
    const unsigned char *seen,
    unsigned int maximum,
    unsigned int found
)
{
    unsigned int index;
    if (seen == NULL || found > maximum) return 1;
    for (index = 0U; index < maximum; ++index) {
        if ((index < found && seen[index] != 1U)
            || (index >= found && seen[index] != 0U)) return 1;
    }
    return 0;
}

static int wls_capacity_hash_observation(
    crypto_hash_sha256_state *hash,
    const wchar_t *leaf,
    const struct wls_capacity_observation *observation
)
{
    char leaf_ascii[32];
    char identity[sizeof(FILE_ID_128) * 2U + 1U];
    char record[256];
    size_t index;
    int length;
    if (hash == NULL || leaf == NULL || observation == NULL
        || wcslen(leaf) >= sizeof(leaf_ascii)) return 1;
    for (index = 0U; leaf[index] != L'\0'; index++) {
        if (leaf[index] < L' ' || leaf[index] > L'~') return 1;
        leaf_ascii[index] = (char)leaf[index];
    }
    leaf_ascii[index] = '\0';
    sodium_bin2hex(
        identity,
        sizeof(identity),
        observation->identity.FileId.Identifier,
        sizeof(observation->identity.FileId.Identifier)
    );
    length = snprintf(
        record,
        sizeof(record),
        "%s\n%llu\n%s\n%lld\n%lld\n",
        leaf_ascii,
        (unsigned long long)observation->identity.VolumeSerialNumber,
        identity,
        (long long)observation->standard.EndOfFile.QuadPart,
        (long long)observation->standard.AllocationSize.QuadPart
    );
    SecureZeroMemory(leaf_ascii, sizeof(leaf_ascii));
    SecureZeroMemory(identity, sizeof(identity));
    if (length <= 0 || (size_t)length >= sizeof(record)) {
        SecureZeroMemory(record, sizeof(record));
        return 1;
    }
    if (crypto_hash_sha256_update(
            hash,
            (const unsigned char *)record,
            (unsigned long long)length
        ) != 0) {
        SecureZeroMemory(record, sizeof(record));
        return 1;
    }
    SecureZeroMemory(record, sizeof(record));
    return 0;
}

static int wls_capacity_validate_file(
    const wchar_t *path,
    unsigned long long expected_volume,
    long long expected_size,
    unsigned long long minimum_allocation,
    int production_acl,
    struct wls_capacity_observation *observation
)
{
    HANDLE file = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation current;
    int result = 1;
    if (path == NULL || observation == NULL || expected_size < 0LL
        || minimum_allocation > (unsigned long long)LLONG_MAX
        || wls_capacity_open_file(
            path,
            GENERIC_READ,
            FILE_SHARE_READ,
            production_acl,
            &file,
            &current
        ) != 0) return 1;
    if (current.identity.VolumeSerialNumber == expected_volume
        && current.standard.EndOfFile.QuadPart == expected_size
        && current.standard.AllocationSize.QuadPart >= 0LL
        && (unsigned long long)current.standard.AllocationSize.QuadPart
            >= minimum_allocation
        && current.standard.NumberOfLinks == 1U
        && wls_capacity_platform_direct_acl_exact(
            file, production_acl
        ) == 0) {
        *observation = current;
        result = 0;
    }
    if (!CloseHandle(file)) result = 1;
    SecureZeroMemory(&current, sizeof(current));
    return result;
}

static int wls_capacity_validate_tokens(
    const wchar_t *directory,
    unsigned int maximum,
    unsigned long long expected_volume,
    const FILE_ID_128 *reserved_file_id,
    crypto_hash_sha256_state *hash,
    int production_acl,
    int require_exact
)
{
    wchar_t pattern[WLS_CAPACITY_PATH_CHARS];
    WIN32_FIND_DATAW entry;
    HANDLE search = INVALID_HANDLE_VALUE;
    HANDLE directory_handle = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation directory_observation;
    unsigned char *seen = NULL;
    FILE_ID_128 *identities = NULL;
    unsigned int found = 0U;
    unsigned int index;
    int result = 1;
    if (directory == NULL || maximum == 0U) return 1;
    if (wls_capacity_open_directory(
            directory,
            production_acl,
            0,
            &directory_handle,
            &directory_observation
        ) != 0
        || directory_observation.identity.VolumeSerialNumber
            != expected_volume
        || wls_capacity_directory_acl_exact(
            directory_handle, production_acl
        ) != 0
        || wls_capacity_join(
            pattern,
            WLS_CAPACITY_PATH_CHARS,
            directory,
            L"*"
        ) != 0) goto cleanup;
    seen = (unsigned char *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        (SIZE_T)maximum
    );
    identities = (FILE_ID_128 *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        ((SIZE_T)maximum + (reserved_file_id == NULL ? 0U : 1U))
            * sizeof(*identities)
    );
    if (seen == NULL || identities == NULL) goto cleanup;
    if (reserved_file_id != NULL) identities[0] = *reserved_file_id;
    search = FindFirstFileW(pattern, &entry);
    if (search == INVALID_HANDLE_VALUE) {
        DWORD error = GetLastError();
        if (require_exact || error != ERROR_FILE_NOT_FOUND) goto cleanup;
    } else {
        do {
            wchar_t token_path[WLS_CAPACITY_PATH_CHARS];
            struct wls_capacity_observation token;
            if (wcscmp(entry.cFileName, L".") == 0
                || wcscmp(entry.cFileName, L"..") == 0) continue;
            if (wls_capacity_parse_token_leaf(
                    entry.cFileName,
                    maximum,
                    &index
                ) != 0
                || seen[index] != 0U
                || wls_capacity_join(
                    token_path,
                    WLS_CAPACITY_PATH_CHARS,
                    directory,
                    entry.cFileName
                ) != 0
                || wls_capacity_validate_file(
                    token_path,
                    expected_volume,
                    0LL,
                    0ULL,
                    production_acl,
                    &token
                ) != 0) goto cleanup;
            seen[index] = 1U;
            identities[(SIZE_T)(require_exact ? index : found)
                + (reserved_file_id == NULL ? 0U : 1U)] = token.identity.FileId;
            found++;
            if (found > maximum) goto cleanup;
        } while (FindNextFileW(search, &entry));
        if (GetLastError() != ERROR_NO_MORE_FILES
            || (require_exact && found != maximum)
            || (!require_exact && wls_capacity_token_prefix_exact(
                seen, maximum, found
            ) != 0)) goto cleanup;
        if (!FindClose(search)) goto cleanup;
        search = INVALID_HANDLE_VALUE;
    }
    if (require_exact) {
        for (index = 0U; index < maximum; index++) {
            wchar_t leaf[17];
            wchar_t token_path[WLS_CAPACITY_PATH_CHARS];
            struct wls_capacity_observation token;
            if (seen[index] == 0U
                || wls_capacity_token_leaf(index, leaf) != 0
                || wls_capacity_join(
                    token_path,
                    WLS_CAPACITY_PATH_CHARS,
                    directory,
                    leaf
                ) != 0
                || wls_capacity_validate_file(
                    token_path,
                    expected_volume,
                    0LL,
                    0ULL,
                    production_acl,
                    &token
                ) != 0
                || memcmp(
                    &token.identity.FileId,
                    &identities[(SIZE_T)index
                        + (reserved_file_id == NULL ? 0U : 1U)],
                    sizeof(token.identity.FileId)
                ) != 0
                || (hash != NULL
                    && wls_capacity_hash_observation(
                        hash,
                        leaf,
                        &token
                    ) != 0)) goto cleanup;
        }
    }
    qsort(
        identities,
        (size_t)found + (reserved_file_id == NULL ? 0U : 1U),
        sizeof(*identities),
        wls_capacity_file_id_compare
    );
    for (index = 1U;
         index < found + (reserved_file_id == NULL ? 0U : 1U);
         index++) {
        if (memcmp(
                &identities[index - 1U],
                &identities[index],
                sizeof(*identities)
            ) == 0) goto cleanup;
    }
    result = 0;
cleanup:
    if (search != INVALID_HANDLE_VALUE) FindClose(search);
    if (directory_handle != INVALID_HANDLE_VALUE) CloseHandle(directory_handle);
    if (seen != NULL) {
        SecureZeroMemory(seen, (SIZE_T)maximum);
        HeapFree(GetProcessHeap(), 0U, seen);
    }
    if (identities != NULL) {
        SecureZeroMemory(
            identities,
            ((SIZE_T)maximum + (reserved_file_id == NULL ? 0U : 1U))
                * sizeof(*identities)
        );
        HeapFree(GetProcessHeap(), 0U, identities);
    }
    SecureZeroMemory(&directory_observation, sizeof(directory_observation));
    SecureZeroMemory(pattern, sizeof(pattern));
    return result;
}

/* 0=normal reserve, 1=durable release marker, 2=absent, -1=unsafe. */
static int wls_capacity_control_marker(
    const wchar_t *live,
    unsigned long long expected_volume,
    int production_acl
)
{
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation before;
    struct wls_capacity_observation after;
    unsigned char marker[sizeof(WLS_CAPACITY_RELEASE_MARKER) - 1U];
    LARGE_INTEGER offset;
    DWORD amount = 0U;
    DWORD attributes;
    DWORD error;
    int result = -1;
    SecureZeroMemory(marker, sizeof(marker));
    if (wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"control.reserve"
        ) != 0) return -1;
    attributes = GetFileAttributesW(path);
    if (attributes == INVALID_FILE_ATTRIBUTES) {
        error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
            ? 2 : -1;
    }
    if (wls_capacity_open_file(
            path,
            GENERIC_READ,
            FILE_SHARE_READ,
            production_acl,
            &file,
            &before
        ) != 0) {
        return -1;
    }
    offset.QuadPart = 0LL;
    if (before.identity.VolumeSerialNumber != expected_volume
        || before.standard.EndOfFile.QuadPart
            != (LONGLONG)WLS_CAPACITY_CONTROL_BYTES
        || before.standard.AllocationSize.QuadPart
            < (LONGLONG)WLS_CAPACITY_CONTROL_BYTES
        || !SetFilePointerEx(file, offset, NULL, FILE_BEGIN)
        || !ReadFile(file, marker, (DWORD)sizeof(marker), &amount, NULL)
        || amount != (DWORD)sizeof(marker)
        || wls_capacity_observe(file, 0, &after) != 0
        || !wls_capacity_observation_equal(&before, &after)
        || wls_capacity_platform_direct_acl_exact(
            file, production_acl
        ) != 0) goto cleanup;
    if (sodium_memcmp(
            marker,
            WLS_CAPACITY_RELEASE_MARKER,
            sizeof(marker)
        ) == 0) {
        result = 1;
    } else {
        /*
         * A crash can tear the first release-marker write after replacing
         * the original allocation pattern.  Until the complete marker has
         * been flushed, the durable state is still REQUIRED: control tokens
         * remain present and begin-release may safely rewrite the marker.
         * This also keeps the Windows replay contract aligned with POSIX.
         */
        result = 0;
    }
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    SecureZeroMemory(marker, sizeof(marker));
    SecureZeroMemory(&before, sizeof(before));
    SecureZeroMemory(&after, sizeof(after));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_capacity_directory_optional(
    const wchar_t *path,
    unsigned long long expected_volume,
    int production_acl,
    int *present
)
{
    HANDLE directory = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation observation;
    DWORD attributes;
    DWORD error;
    if (path == NULL || present == NULL) return 1;
    *present = 0;
    attributes = GetFileAttributesW(path);
    if (attributes == INVALID_FILE_ATTRIBUTES) {
        error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
            ? 0 : 1;
    }
    if (wls_capacity_open_directory(
            path,
            production_acl,
            0,
            &directory,
            &observation
        ) != 0
        || wls_capacity_directory_acl_exact(
            directory, production_acl
        ) != 0) {
        if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
        return 1;
    }
    if (observation.identity.VolumeSerialNumber != expected_volume) {
        CloseHandle(directory);
        return 1;
    }
    *present = 1;
    return CloseHandle(directory) ? 0 : 1;
}

static int wls_capacity_validate_control_state(
    const wchar_t *live,
    unsigned long long expected_volume,
    int production_acl,
    int required_state
)
{
    wchar_t tokens[WLS_CAPACITY_PATH_CHARS];
    int marker;
    int tokens_present = 0;
    if (live == NULL
        || wls_capacity_join(
            tokens,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"control-tokens"
        ) != 0) return 1;
    marker = wls_capacity_control_marker(
        live,
        expected_volume,
        production_acl
    );
    if (marker < 0
        || wls_capacity_directory_optional(
            tokens,
            expected_volume,
            production_acl,
            &tokens_present
        ) != 0) return 1;
    if (required_state == WLS_CAPACITY_CONTROL_REQUIRED) {
        return marker == 0 && tokens_present
            && wls_capacity_validate_tokens(
                tokens,
                WLS_CAPACITY_CONTROL_INODES,
                expected_volume,
                NULL,
                NULL,
                production_acl,
                1
            ) == 0 ? 0 : 1;
    }
    if (required_state == WLS_CAPACITY_CONTROL_TRANSITION) {
        return marker == 1
            && (!tokens_present || wls_capacity_validate_tokens(
                tokens,
                WLS_CAPACITY_CONTROL_INODES,
                expected_volume,
                NULL,
                NULL,
                production_acl,
                0
            ) == 0) ? 0 : 1;
    }
    if (required_state == WLS_CAPACITY_CONTROL_ABSENT) {
        return marker == 2 && !tokens_present ? 0 : 1;
    }
    return 1;
}

static int wls_capacity_detect_control_state(
    const wchar_t *live,
    unsigned long long expected_volume,
    int production_acl
)
{
    if (wls_capacity_validate_control_state(
            live,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_REQUIRED
        ) == 0) return WLS_CAPACITY_CONTROL_REQUIRED;
    if (wls_capacity_validate_control_state(
            live,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_TRANSITION
        ) == 0) return WLS_CAPACITY_CONTROL_TRANSITION;
    if (wls_capacity_validate_control_state(
            live,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_ABSENT
        ) == 0) return WLS_CAPACITY_CONTROL_ABSENT;
    return -1;
}

static int wls_capacity_validate_live(
    const wchar_t *live,
    unsigned long long target_bytes,
    unsigned int target_inodes,
    unsigned long long expected_volume,
    int production_acl,
    int control_state,
    struct wls_capacity_evidence *evidence
)
{
    wchar_t pattern[WLS_CAPACITY_PATH_CHARS];
    wchar_t bytes_path[WLS_CAPACITY_PATH_CHARS];
    wchar_t tokens_path[WLS_CAPACITY_PATH_CHARS];
    WIN32_FIND_DATAW entry;
    HANDLE search = INVALID_HANDLE_VALUE;
    HANDLE directory = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation directory_observation;
    struct wls_capacity_observation bytes_observation;
    crypto_hash_sha256_state hash;
    unsigned char digest[crypto_hash_sha256_BYTES];
    int have_bytes = 0;
    int have_tokens = 0;
    int result = 1;
    SecureZeroMemory(digest, sizeof(digest));
    if (live == NULL || target_bytes == 0ULL || target_inodes == 0U
        || evidence == NULL
        || wls_capacity_open_directory(
            live,
            production_acl,
            0,
            &directory,
            &directory_observation
        ) != 0
        || directory_observation.identity.VolumeSerialNumber
            != expected_volume
        || wls_capacity_directory_acl_exact(
            directory, production_acl
        ) != 0
        || wls_capacity_join(
            pattern,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"*"
        ) != 0) goto cleanup;
    search = FindFirstFileW(pattern, &entry);
    if (search == INVALID_HANDLE_VALUE) goto cleanup;
    do {
        if (wcscmp(entry.cFileName, L".") == 0
            || wcscmp(entry.cFileName, L"..") == 0) continue;
        if (wcscmp(entry.cFileName, L"bytes.reserve") == 0) {
            if (have_bytes) goto cleanup;
            have_bytes = 1;
        } else if (wcscmp(entry.cFileName, L"tokens") == 0) {
            if (have_tokens) goto cleanup;
            have_tokens = 1;
        } else if (wcscmp(entry.cFileName, L"control.reserve") != 0
            && wcscmp(entry.cFileName, L"control-tokens") != 0) {
            goto cleanup;
        }
    } while (FindNextFileW(search, &entry));
    if (GetLastError() != ERROR_NO_MORE_FILES
        || !have_bytes || !have_tokens
        || !FindClose(search)) goto cleanup;
    search = INVALID_HANDLE_VALUE;
    if (wls_capacity_join(
            bytes_path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"bytes.reserve"
        ) != 0
        || wls_capacity_join(
            tokens_path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"tokens"
        ) != 0
        || wls_capacity_validate_file(
            bytes_path,
            expected_volume,
            (long long)target_bytes,
            target_bytes,
            production_acl,
            &bytes_observation
        ) != 0
        || crypto_hash_sha256_init(&hash) != 0
        || wls_capacity_hash_observation(
            &hash,
            L"bytes.reserve",
            &bytes_observation
        ) != 0
        || wls_capacity_validate_tokens(
            tokens_path,
            target_inodes,
            expected_volume,
            &bytes_observation.identity.FileId,
            &hash,
            production_acl,
            1
        ) != 0
        || wls_capacity_validate_control_state(
            live,
            expected_volume,
            production_acl,
            control_state
        ) != 0
        || crypto_hash_sha256_final(&hash, digest) != 0) goto cleanup;
    sodium_bin2hex(
        evidence->entry_set_sha256,
        sizeof(evidence->entry_set_sha256),
        digest,
        sizeof(digest)
    );
    evidence->physical_bytes =
        (unsigned long long)bytes_observation.standard.AllocationSize.QuadPart;
    evidence->inode_count = target_inodes;
    result = 0;
cleanup:
    if (search != INVALID_HANDLE_VALUE) FindClose(search);
    if (directory != INVALID_HANDLE_VALUE) CloseHandle(directory);
    SecureZeroMemory(&directory_observation, sizeof(directory_observation));
    SecureZeroMemory(&bytes_observation, sizeof(bytes_observation));
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(pattern, sizeof(pattern));
    SecureZeroMemory(bytes_path, sizeof(bytes_path));
    SecureZeroMemory(tokens_path, sizeof(tokens_path));
    return result;
}

static int wls_capacity_hash_anchor_handle(
    crypto_hash_sha256_state *hash,
    const char *label,
    const struct wls_capacity_observation *observation
)
{
    char identity[sizeof(FILE_ID_128) * 2U + 1U];
    char record[256];
    int length;
    if (hash == NULL || label == NULL || observation == NULL) return 1;
    sodium_bin2hex(
        identity,
        sizeof(identity),
        observation->identity.FileId.Identifier,
        sizeof(observation->identity.FileId.Identifier)
    );
    length = snprintf(
        record,
        sizeof(record),
        "%s\n%llu\n%s\n%lu\n",
        label,
        (unsigned long long)observation->identity.VolumeSerialNumber,
        identity,
        (unsigned long)observation->attributes
    );
    SecureZeroMemory(identity, sizeof(identity));
    if (length <= 0 || (size_t)length >= sizeof(record)) {
        SecureZeroMemory(record, sizeof(record));
        return 1;
    }
    if (crypto_hash_sha256_update(
            hash,
            (const unsigned char *)record,
            (unsigned long long)length
        ) != 0) {
        SecureZeroMemory(record, sizeof(record));
        return 1;
    }
    SecureZeroMemory(record, sizeof(record));
    return 0;
}

static int wls_capacity_hash_anchor_path(
    crypto_hash_sha256_state *hash,
    const char *label,
    const wchar_t *path,
    int directory,
    unsigned long long expected_volume,
    int production_acl
)
{
    HANDLE object = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation observation;
    int result = 1;
    if (directory) {
        if (wls_capacity_open_directory(
                path,
                production_acl,
                0,
                &object,
                &observation
            ) != 0) return 1;
    } else if (wls_capacity_open_file(
            path,
            GENERIC_READ,
            FILE_SHARE_READ,
            production_acl,
            &object,
            &observation
        ) != 0) return 1;
    if (observation.identity.VolumeSerialNumber == expected_volume
        && wls_capacity_hash_anchor_handle(
            hash,
            label,
            &observation
        ) == 0) result = 0;
    if (!CloseHandle(object)) result = 1;
    SecureZeroMemory(&observation, sizeof(observation));
    return result;
}

static int wls_capacity_parent_path(
    const wchar_t *path,
    wchar_t parent[WLS_CAPACITY_PATH_CHARS]
)
{
    wchar_t *slash;
    size_t length;
    if (path == NULL || parent == NULL) return 1;
    length = wcslen(path);
    if (length < 4U || length >= WLS_CAPACITY_PATH_CHARS) return 1;
    memcpy(parent, path, (length + 1U) * sizeof(wchar_t));
    slash = wcsrchr(parent, L'\\');
    if (slash == NULL || slash <= parent + 2U) return 1;
    *slash = L'\0';
    return 0;
}

static int wls_capacity_anchor_proof(
    const wchar_t *home,
    const wchar_t *nonce,
    const wchar_t *platform_definition,
    unsigned long long expected_volume,
    int production_acl,
    struct wls_capacity_platform_anchor *platform_anchor,
    struct wls_capacity_evidence *evidence
)
{
    static const wchar_t *relative[] = {
        L"bin",
        L"trust",
        L"state",
        L"runtime",
        L"runtime\\conf",
        L"runtime\\temp",
        L"runtime\\shadow",
        L"runtime\\run",
        L"snapshots",
        L"snapshots-v2",
        L"snapshot-candidates-v2",
        L"slots",
        L"rebootstrap",
        L"rebootstrap\\candidates",
        L"rebootstrap\\backups",
        L"rebootstrap\\capacity"
    };
    static const char *labels[] = {
        "bin",
        "trust",
        "state",
        "runtime",
        "runtime/conf",
        "runtime/temp",
        "runtime/shadow",
        "runtime/run",
        "snapshots",
        "snapshots-v2",
        "snapshot-candidates-v2",
        "slots",
        "rebootstrap",
        "rebootstrap/candidates",
        "rebootstrap/backups",
        "rebootstrap/capacity"
    };
    crypto_hash_sha256_state hash;
    unsigned char digest[crypto_hash_sha256_BYTES];
    unsigned char volume_digest[crypto_hash_sha256_BYTES];
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    wchar_t platform[WLS_CAPACITY_PATH_CHARS];
    wchar_t platform_parent[WLS_CAPACITY_PATH_CHARS];
    wchar_t module[WLS_CAPACITY_PATH_CHARS];
    wchar_t module_normalized[WLS_CAPACITY_PATH_CHARS];
    wchar_t expected_module[WLS_CAPACITY_PATH_CHARS];
    wchar_t candidate[WLS_CAPACITY_PATH_CHARS];
    wchar_t candidate_bin[WLS_CAPACITY_PATH_CHARS];
    HANDLE platform_parent_handle = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation platform_parent_observation;
    struct wls_capacity_observation platform_parent_after;
    char volume_record[96];
    DWORD module_length;
    size_t index;
    int length;
    int result = 1;
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(volume_digest, sizeof(volume_digest));
    SecureZeroMemory(&platform_parent_observation, sizeof(platform_parent_observation));
    SecureZeroMemory(&platform_parent_after, sizeof(platform_parent_after));
    if (home == NULL || nonce == NULL || platform_definition == NULL
        || platform_anchor == NULL || evidence == NULL
        || wls_capacity_normalize_path(platform_definition, platform) != 0
        || wls_capacity_parent_path(platform, platform_parent) != 0
        || wls_capacity_validate_directory_chain(
            platform_parent,
            production_acl,
            production_acl
                ? (wls_capacity_path_within(platform_parent, home, 1)
                    ? home : platform_parent)
                : NULL
        ) != 0
        || wls_capacity_open_directory(
            platform_parent,
            production_acl,
            0,
            &platform_parent_handle,
            &platform_parent_observation
        ) != 0
        || platform_parent_observation.identity.VolumeSerialNumber
            != expected_volume
        || crypto_hash_sha256_init(&hash) != 0) goto cleanup;
    SecureZeroMemory(platform_anchor, sizeof(*platform_anchor));
    for (index = 0U; index < sizeof(relative) / sizeof(relative[0]); index++) {
        if (wls_capacity_join(
                path,
                WLS_CAPACITY_PATH_CHARS,
                home,
                relative[index]
            ) != 0
            || wls_capacity_hash_anchor_path(
                &hash,
                labels[index],
                path,
                1,
                expected_volume,
                production_acl
            ) != 0) goto cleanup;
    }
    if (wls_capacity_hash_anchor_path(
            &hash,
            "platform-definition-parent",
            platform_parent,
            1,
            expected_volume,
            production_acl
        ) != 0
        || wls_capacity_hash_anchor_path(
            &hash,
            "platform-definition",
            platform,
            0,
            expected_volume,
            production_acl
        ) != 0
        || wls_capacity_join(
            candidate,
            WLS_CAPACITY_PATH_CHARS,
            home,
            L"rebootstrap\\candidates"
        ) != 0
        || wls_capacity_join(
            candidate_bin,
            WLS_CAPACITY_PATH_CHARS,
            candidate,
            nonce
        ) != 0
        || wls_capacity_join(
            candidate,
            WLS_CAPACITY_PATH_CHARS,
            candidate_bin,
            L"bin"
        ) != 0
        || wls_capacity_join(
            expected_module,
            WLS_CAPACITY_PATH_CHARS,
            candidate,
            L"wls-gateway-launcher.exe"
        ) != 0
        || wls_capacity_validate_directory_chain(
            candidate,
            production_acl,
            production_acl ? home : NULL
        ) != 0) goto cleanup;
    module_length = GetModuleFileNameW(
        NULL,
        module,
        WLS_CAPACITY_PATH_CHARS
    );
    if (module_length == 0U || module_length >= WLS_CAPACITY_PATH_CHARS
        || wls_capacity_normalize_path(module, module_normalized) != 0
        || _wcsicmp(module_normalized, expected_module) != 0
        || wls_capacity_hash_anchor_path(
            &hash,
            "candidate-launcher",
            module_normalized,
            0,
            expected_volume,
            production_acl
        ) != 0
        || wls_capacity_observe(
            platform_parent_handle,
            1,
            &platform_parent_after
        ) != 0
        || !wls_capacity_observation_equal(
            &platform_parent_observation,
            &platform_parent_after
        )
        || crypto_hash_sha256_final(&hash, digest) != 0) goto cleanup;
    sodium_bin2hex(
        evidence->anchor_set_sha256,
        sizeof(evidence->anchor_set_sha256),
        digest,
        sizeof(digest)
    );
    length = snprintf(
        volume_record,
        sizeof(volume_record),
        "volume=%llu\n",
        expected_volume
    );
    if (length <= 0 || (size_t)length >= sizeof(volume_record)
        || crypto_hash_sha256(
            volume_digest,
            (const unsigned char *)volume_record,
            (unsigned long long)length
        ) != 0) goto cleanup;
    sodium_bin2hex(
        evidence->volume_id,
        sizeof(evidence->volume_id),
        volume_digest,
        sizeof(volume_digest)
    );
    if (wcscpy_s(
            platform_anchor->parent,
            WLS_CAPACITY_PATH_CHARS,
            platform_parent
        ) != 0
        || _snwprintf_s(
            platform_anchor->reserve_prefix,
            64U,
            _TRUNCATE,
            L"%ls.platform.reserve",
            nonce
        ) <= 0) goto cleanup;
    platform_anchor->parent_identity = platform_parent_observation.identity;
    platform_anchor->volume = expected_volume;
    platform_anchor->production_acl = production_acl;
    result = 0;
cleanup:
    if (platform_parent_handle != INVALID_HANDLE_VALUE) {
        if (!CloseHandle(platform_parent_handle)) result = 1;
    }
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(volume_digest, sizeof(volume_digest));
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(platform, sizeof(platform));
    SecureZeroMemory(platform_parent, sizeof(platform_parent));
    SecureZeroMemory(module, sizeof(module));
    SecureZeroMemory(module_normalized, sizeof(module_normalized));
    SecureZeroMemory(expected_module, sizeof(expected_module));
    SecureZeroMemory(candidate, sizeof(candidate));
    SecureZeroMemory(candidate_bin, sizeof(candidate_bin));
    SecureZeroMemory(volume_record, sizeof(volume_record));
    SecureZeroMemory(&platform_parent_observation, sizeof(platform_parent_observation));
    SecureZeroMemory(&platform_parent_after, sizeof(platform_parent_after));
    if (result != 0 && platform_anchor != NULL) {
        SecureZeroMemory(platform_anchor, sizeof(*platform_anchor));
    }
    return result;
}

static int wls_capacity_current_user_sid(
    unsigned char sid_buffer[SECURITY_MAX_SID_SIZE],
    DWORD *sid_length
)
{
    HANDLE token = NULL;
    TOKEN_USER *user = NULL;
    DWORD required = 0U;
    DWORD allocated = 0U;
    int result = 1;
    if (sid_buffer == NULL || sid_length == NULL) return 1;
    *sid_length = SECURITY_MAX_SID_SIZE;
    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &token)) return 1;
    (void)GetTokenInformation(token, TokenUser, NULL, 0U, &required);
    if (required == 0U) goto cleanup;
    allocated = required;
    user = (TOKEN_USER *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
    );
    if (user == NULL
        || !GetTokenInformation(token, TokenUser, user, allocated, &required)
        || required > allocated
        || !IsValidSid(user->User.Sid)
        || GetLengthSid(user->User.Sid) > SECURITY_MAX_SID_SIZE
        || !CopySid(
            SECURITY_MAX_SID_SIZE,
            sid_buffer,
            user->User.Sid
        )) goto cleanup;
    *sid_length = GetLengthSid((PSID)sid_buffer);
    result = 0;
cleanup:
    if (user != NULL) {
        SecureZeroMemory(user, allocated);
        HeapFree(GetProcessHeap(), 0U, user);
    }
    if (token != NULL) CloseHandle(token);
    return result;
}

static int wls_capacity_protected_descriptor(
    int production_acl,
    int directory,
    PSECURITY_DESCRIPTOR *descriptor
)
{
    unsigned char user_buffer[SECURITY_MAX_SID_SIZE];
    DWORD user_length = sizeof(user_buffer);
    LPWSTR user_text = NULL;
    wchar_t sddl[1024];
    const wchar_t *owner;
    const wchar_t *third;
    const wchar_t *inheritance;
    int length;
    int result = 1;
    SecureZeroMemory(user_buffer, sizeof(user_buffer));
    ZeroMemory(sddl, sizeof(sddl));
    if (descriptor == NULL || (production_acl != 0 && production_acl != 1)
        || (directory != 0 && directory != 1)) {
        return 1;
    }
    *descriptor = NULL;
    if (production_acl) {
        owner = L"SY";
        third = WLS_CAPACITY_GATEWAY_SERVICE_SID;
    } else {
        if (wls_capacity_current_user_sid(user_buffer, &user_length) != 0
            || !ConvertSidToStringSidW((PSID)user_buffer, &user_text)
            || user_text == NULL) goto cleanup;
        owner = user_text;
        third = WLS_CAPACITY_GATEWAY_SERVICE_SID;
    }
    inheritance = directory ? L"OICI" : L"";
    length = _snwprintf_s(
        sddl,
        sizeof(sddl) / sizeof(sddl[0]),
        _TRUNCATE,
        L"O:%lsD:P(A;%ls;FA;;;SY)(A;%ls;FA;;;BA)"
            L"(A;%ls;FA;;;%ls)",
        owner,
        inheritance,
        inheritance,
        inheritance,
        third
    );
    if (length <= 0 || (size_t)length >= sizeof(sddl) / sizeof(sddl[0])
        || !ConvertStringSecurityDescriptorToSecurityDescriptorW(
            sddl,
            SDDL_REVISION_1,
            descriptor,
            NULL
        )
        || *descriptor == NULL) goto cleanup;
    result = 0;
cleanup:
    if (user_text != NULL) LocalFree(user_text);
    SecureZeroMemory(user_buffer, sizeof(user_buffer));
    SecureZeroMemory(sddl, sizeof(sddl));
    return result;
}

static int wls_capacity_platform_direct_descriptor(
    int production_acl,
    PSECURITY_DESCRIPTOR *descriptor
)
{
    return wls_capacity_protected_descriptor(
        production_acl, 0, descriptor
    );
}

static int wls_capacity_directory_descriptor(
    int production_acl,
    PSECURITY_DESCRIPTOR *descriptor
)
{
    return wls_capacity_protected_descriptor(
        production_acl, 1, descriptor
    );
}

static int wls_capacity_platform_direct_apply(
    HANDLE object,
    PSECURITY_DESCRIPTOR descriptor
)
{
    PSID owner = NULL;
    PACL dacl = NULL;
    BOOL owner_defaulted = FALSE;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || descriptor == NULL
        || !GetSecurityDescriptorOwner(
            descriptor,
            &owner,
            &owner_defaulted
        )
        || owner == NULL || owner_defaulted
        || !GetSecurityDescriptorDacl(
            descriptor,
            &dacl_present,
            &dacl,
            &dacl_defaulted
        )
        || !dacl_present || dacl == NULL || dacl_defaulted) return 1;
    return SetSecurityInfo(
        object,
        SE_FILE_OBJECT,
        OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION
            | PROTECTED_DACL_SECURITY_INFORMATION,
        owner,
        NULL,
        dacl,
        NULL
    ) == ERROR_SUCCESS ? 0 : 1;
}

#if defined(WLS_NATIVE_TEST_HOOKS)
static struct wls_capacity_platform_anchor wls_capacity_test_anchor;
static wchar_t wls_capacity_test_name[32];
static int wls_capacity_test_enabled = 0;

static int wls_capacity_test_name_valid(const wchar_t *name)
{
    static const wchar_t *known[] = {
        L"allocation",
        L"token-batch",
        L"token-directory",
        L"direct-seal",
        L"rename",
        L"begin",
        L"release",
        L"control-token-partial",
        L"primary-token-partial"
    };
    size_t index;
    if (name == NULL || name[0] == L'\0') return 0;
    for (index = 0U; index < sizeof(known) / sizeof(known[0]); index++) {
        if (wcscmp(name, known[index]) == 0) return 1;
    }
    return 0;
}

static void wls_capacity_test_reset(void)
{
    SecureZeroMemory(
        &wls_capacity_test_anchor,
        sizeof(wls_capacity_test_anchor)
    );
    SecureZeroMemory(wls_capacity_test_name, sizeof(wls_capacity_test_name));
    wls_capacity_test_enabled = 0;
}

static int wls_capacity_test_configure(
    const struct wls_capacity_platform_anchor *anchor,
    int test_mode
)
{
    wchar_t requested[32];
    wchar_t production_gate[2];
    DWORD amount;
    wls_capacity_test_reset();
    SecureZeroMemory(requested, sizeof(requested));
    SecureZeroMemory(production_gate, sizeof(production_gate));
    SetLastError(ERROR_SUCCESS);
    amount = GetEnvironmentVariableW(
        L"WLS_CAPACITY_TEST_FAILPOINT",
        requested,
        (DWORD)(sizeof(requested) / sizeof(requested[0]))
    );
    if (amount == 0U) {
        return GetLastError() == ERROR_ENVVAR_NOT_FOUND ? 0 : 1;
    }
    if (anchor == NULL
        || amount >= (DWORD)(sizeof(requested) / sizeof(requested[0]))
        || !wls_capacity_test_name_valid(requested)) return 1;
    if (!test_mode) {
        amount = GetEnvironmentVariableW(
            L"WLS_RUN_NATIVE_GATEWAY_WINDOWS_PRODUCTION_CAPACITY_INTEGRATION",
            production_gate,
            (DWORD)(sizeof(production_gate) / sizeof(production_gate[0]))
        );
        if (amount != 1U || wcscmp(production_gate, L"1") != 0) return 1;
    }
    wls_capacity_test_anchor = *anchor;
    if (wcscpy_s(
            wls_capacity_test_name,
            sizeof(wls_capacity_test_name)
                / sizeof(wls_capacity_test_name[0]),
            requested
        ) != 0) {
        wls_capacity_test_reset();
        return 1;
    }
    wls_capacity_test_enabled = 1;
    SecureZeroMemory(requested, sizeof(requested));
    SecureZeroMemory(production_gate, sizeof(production_gate));
    return 0;
}

static int wls_capacity_test_failpoint(const wchar_t *name)
{
    wchar_t leaf[96];
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    PSECURITY_DESCRIPTOR security = NULL;
    SECURITY_ATTRIBUTES attributes;
    HANDLE file = INVALID_HANDLE_VALUE;
    HANDLE volume = INVALID_HANDLE_VALUE;
    DWORD written = 0U;
    DWORD bytes;
    int created = 0;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    if (!wls_capacity_test_enabled
        || wcscmp(name, wls_capacity_test_name) != 0) return 0;
    if (_snwprintf_s(
            leaf,
            sizeof(leaf) / sizeof(leaf[0]),
            _TRUNCATE,
            L"%ls.failpoint",
            wls_capacity_test_anchor.reserve_prefix
        ) <= 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            wls_capacity_test_anchor.parent,
            leaf
        ) != 0
        || wls_capacity_platform_direct_descriptor(
            wls_capacity_test_anchor.production_acl,
            &security
        ) != 0
        || wls_capacity_volume_handle(
            wls_capacity_test_anchor.parent,
            &volume
        ) != 0) goto cleanup;
    attributes.nLength = (DWORD)sizeof(attributes);
    attributes.lpSecurityDescriptor = security;
    attributes.bInheritHandle = FALSE;
    file = CreateFileW(
        path,
        GENERIC_READ | GENERIC_WRITE | READ_CONTROL | WRITE_DAC
            | WRITE_OWNER,
        FILE_SHARE_READ,
        &attributes,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH
            | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE) goto cleanup;
    created = 1;
    bytes = (DWORD)(wcslen(name) * sizeof(wchar_t));
    if (bytes == 0U
        || wls_capacity_platform_direct_apply(file, security) != 0
        || !WriteFile(file, name, bytes, &written, NULL)
        || written != bytes
        || !FlushFileBuffers(file)) goto cleanup;
    if (!CloseHandle(file)) {
        file = INVALID_HANDLE_VALUE;
        goto cleanup;
    }
    file = INVALID_HANDLE_VALUE;
    if (!FlushFileBuffers(volume)) goto cleanup;
    CloseHandle(volume);
    volume = INVALID_HANDLE_VALUE;
    LocalFree(security);
    security = NULL;
    for (;;) Sleep(1000U);
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    if (volume != INVALID_HANDLE_VALUE) CloseHandle(volume);
    if (created) (void)DeleteFileW(path);
    if (security != NULL) LocalFree(security);
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    return result;
}
#else
static int wls_capacity_test_configure(
    const struct wls_capacity_platform_anchor *anchor,
    int test_mode
)
{
    (void)anchor;
    (void)test_mode;
    return 0;
}

static int wls_capacity_test_failpoint(const wchar_t *name)
{
    (void)name;
    return 0;
}

static void wls_capacity_test_reset(void)
{
}
#endif

static int wls_capacity_protected_acl_exact(
    HANDLE object,
    int production_acl,
    int directory
)
{
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
    unsigned char user_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD administrators_length = sizeof(administrators_buffer);
    DWORD user_length = sizeof(user_buffer);
    PSID controller_sid = NULL;
    PSID expected_owner;
    PSID expected_third;
    unsigned int system_count = 0U;
    unsigned int administrators_count = 0U;
    unsigned int third_count = 0U;
    DWORD index;
    int result = 1;
    ZeroMemory(&information, sizeof(information));
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    SecureZeroMemory(user_buffer, sizeof(user_buffer));
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || (production_acl != 0 && production_acl != 1)
        || (directory != 0 && directory != 1)
        || wls_capacity_sid(
            WinLocalSystemSid, system_buffer, &system_length
        ) != 0
        || wls_capacity_sid(
            WinBuiltinAdministratorsSid,
            administrators_buffer,
            &administrators_length
        ) != 0) goto cleanup;
    if (!ConvertStringSidToSidW(
            WLS_CAPACITY_GATEWAY_SERVICE_SID,
            &controller_sid
        ) || controller_sid == NULL) goto cleanup;
    if (production_acl) {
        expected_owner = (PSID)system_buffer;
    } else {
        if (wls_capacity_current_user_sid(user_buffer, &user_length) != 0) {
            goto cleanup;
        }
        expected_owner = (PSID)user_buffer;
    }
    expected_third = controller_sid;
    if (GetSecurityInfo(
            object,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &owner,
            NULL,
            &dacl,
            NULL,
            &descriptor
        ) != ERROR_SUCCESS
        || descriptor == NULL || owner == NULL
        || !EqualSid(owner, expected_owner)
        || !GetSecurityDescriptorControl(
            descriptor, &control, &revision
        )
        || (control & SE_DACL_PROTECTED) == 0U
        || !GetSecurityDescriptorDacl(
            descriptor, &dacl_present, &dacl, &dacl_defaulted
        )
        || !dacl_present || dacl_defaulted || dacl == NULL
        || dacl->AclRevision != ACL_REVISION
        || !GetAclInformation(
            dacl,
            &information,
            sizeof(information),
            AclSizeInformation
        )
        || information.AceCount != 3U) goto cleanup;
    for (index = 0U; index < information.AceCount; index++) {
        ACCESS_ALLOWED_ACE *ace = NULL;
        PSID sid;
        if (!GetAce(dacl, index, (LPVOID *)&ace)
            || ace == NULL
            || ace->Header.AceType != ACCESS_ALLOWED_ACE_TYPE
            || ace->Header.AceFlags != (directory
                ? (OBJECT_INHERIT_ACE | CONTAINER_INHERIT_ACE)
                : 0U)
            || ace->Mask != FILE_ALL_ACCESS) goto cleanup;
        sid = (PSID)&ace->SidStart;
        if (!IsValidSid(sid)) goto cleanup;
        if (EqualSid(sid, system_buffer)) {
            if (++system_count != 1U) goto cleanup;
        } else if (EqualSid(sid, administrators_buffer)) {
            if (++administrators_count != 1U) goto cleanup;
        } else if (EqualSid(sid, expected_third)) {
            if (++third_count != 1U) goto cleanup;
        } else {
            goto cleanup;
        }
    }
    if (system_count == 1U && administrators_count == 1U
        && third_count == 1U) result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    if (controller_sid != NULL) LocalFree(controller_sid);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    SecureZeroMemory(user_buffer, sizeof(user_buffer));
    return result;
}

static int wls_capacity_platform_direct_acl_exact(
    HANDLE object,
    int production_acl
)
{
    return wls_capacity_protected_acl_exact(
        object, production_acl, 0
    );
}

static int wls_capacity_directory_acl_exact(
    HANDLE object,
    int production_acl
)
{
    return wls_capacity_protected_acl_exact(
        object, production_acl, 1
    );
}

static int wls_capacity_platform_parent_open(
    const struct wls_capacity_platform_anchor *anchor,
    HANDLE *parent
)
{
    struct wls_capacity_observation observation;
    int result = 1;
    if (anchor == NULL || parent == NULL) return 1;
    *parent = CreateFileW(
        anchor->parent,
        GENERIC_READ | READ_CONTROL,
        FILE_SHARE_READ | FILE_SHARE_WRITE,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (*parent == INVALID_HANDLE_VALUE
        || wls_capacity_observe(*parent, 1, &observation) != 0
        || wls_capacity_final_path_matches(
            *parent,
            anchor->parent
        ) != 0
        || (anchor->production_acl
            && wls_capacity_acl_safe(*parent) != 0)
        || observation.identity.VolumeSerialNumber != anchor->volume
        || !wls_capacity_identity_equal(
            &observation.identity,
            &anchor->parent_identity
        )) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(&observation, sizeof(observation));
    if (result != 0 && *parent != INVALID_HANDLE_VALUE) {
        CloseHandle(*parent);
        *parent = INVALID_HANDLE_VALUE;
    }
    return result;
}

static int wls_capacity_platform_reserve_leaf(
    const struct wls_capacity_platform_anchor *anchor,
    unsigned int index,
    wchar_t leaf[80]
)
{
    int length;
    if (anchor == NULL || leaf == NULL
        || index >= WLS_CAPACITY_PLATFORM_INODES
        || anchor->reserve_prefix[0] == L'\0') return 1;
    length = _snwprintf_s(
        leaf,
        80U,
        _TRUNCATE,
        L"%ls.%u",
        anchor->reserve_prefix,
        index
    );
    return length > 0 && length < 80 ? 0 : 1;
}

static int wls_capacity_platform_reserve_staging_leaf(
    const struct wls_capacity_platform_anchor *anchor,
    unsigned int index,
    wchar_t leaf[80]
)
{
    int length;
    if (anchor == NULL || leaf == NULL
        || index >= WLS_CAPACITY_PLATFORM_INODES
        || anchor->reserve_prefix[0] == L'\0') return 1;
    length = _snwprintf_s(
        leaf,
        80U,
        _TRUNCATE,
        L"%ls.%u.staging",
        anchor->reserve_prefix,
        index
    );
    return length > 0 && length < 80 ? 0 : 1;
}

static int wls_capacity_platform_namespace_known(
    const struct wls_capacity_platform_anchor *anchor
)
{
    wchar_t pattern[WLS_CAPACITY_PATH_CHARS];
    wchar_t expected[WLS_CAPACITY_PLATFORM_INODES][80];
    wchar_t staging[WLS_CAPACITY_PLATFORM_INODES][80];
    WIN32_FIND_DATAW entry;
    HANDLE search = INVALID_HANDLE_VALUE;
    unsigned int seen[WLS_CAPACITY_PLATFORM_INODES] = {0U, 0U};
    unsigned int seen_staging[WLS_CAPACITY_PLATFORM_INODES] = {0U, 0U};
    unsigned int index;
    int result = 1;
    if (anchor == NULL
        || _snwprintf_s(
            pattern,
            WLS_CAPACITY_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\%ls.*",
            anchor->parent,
            anchor->reserve_prefix
        ) <= 0) return 1;
    for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; index++) {
        if (wls_capacity_platform_reserve_leaf(
                anchor, index, expected[index]
            ) != 0
            || wls_capacity_platform_reserve_staging_leaf(
                anchor, index, staging[index]
            ) != 0) goto cleanup;
    }
    search = FindFirstFileW(pattern, &entry);
    if (search == INVALID_HANDLE_VALUE) {
        DWORD error = GetLastError();
        result = error == ERROR_FILE_NOT_FOUND
            || error == ERROR_PATH_NOT_FOUND ? 0 : 1;
        goto cleanup;
    }
    do {
        int matched = 0;
        for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; index++) {
            if (wcscmp(entry.cFileName, expected[index]) == 0) {
                if (++seen[index] != 1U) goto cleanup;
                matched = 1;
                break;
            }
            if (wcscmp(entry.cFileName, staging[index]) == 0) {
                if (++seen_staging[index] != 1U) goto cleanup;
                matched = 1;
                break;
            }
        }
        if (!matched
            || (entry.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
            || (entry.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0U) {
            goto cleanup;
        }
    } while (FindNextFileW(search, &entry));
    if (GetLastError() != ERROR_NO_MORE_FILES
        || !FindClose(search)) goto cleanup;
    search = INVALID_HANDLE_VALUE;
    for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; ++index) {
        if (seen[index] && seen_staging[index]) goto cleanup;
    }
    result = 0;
cleanup:
    if (search != INVALID_HANDLE_VALUE) FindClose(search);
    SecureZeroMemory(pattern, sizeof(pattern));
    SecureZeroMemory(expected, sizeof(expected));
    SecureZeroMemory(staging, sizeof(staging));
    SecureZeroMemory(seen, sizeof(seen));
    SecureZeroMemory(seen_staging, sizeof(seen_staging));
    return result;
}

static int wls_capacity_platform_reserve_file_open(
    const struct wls_capacity_platform_anchor *anchor,
    unsigned int index,
    DWORD access,
    DWORD sharing,
    HANDLE *file
)
{
    wchar_t leaf[80];
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    struct wls_capacity_observation observation;
    int result = 1;
    if (anchor == NULL || file == NULL
        || wls_capacity_platform_reserve_leaf(anchor, index, leaf) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            anchor->parent,
            leaf
        ) != 0) return 1;
    *file = INVALID_HANDLE_VALUE;
    if (wls_capacity_open_file(
            path,
            access,
            sharing,
            anchor->production_acl,
            file,
            &observation
        ) != 0
        || observation.identity.VolumeSerialNumber != anchor->volume
        || observation.standard.EndOfFile.QuadPart
            != (LONGLONG)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
        || observation.standard.AllocationSize.QuadPart
            < (LONGLONG)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
        || observation.standard.NumberOfLinks != 1U
        || wls_capacity_platform_direct_acl_exact(
            *file,
            anchor->production_acl
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (result != 0 && *file != INVALID_HANDLE_VALUE) {
        CloseHandle(*file);
        *file = INVALID_HANDLE_VALUE;
    }
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(&observation, sizeof(observation));
    return result;
}

/* 0=exact, 1=absent, -1=unsafe or malformed. */
static int wls_capacity_platform_reserve_file_state(
    const struct wls_capacity_platform_anchor *anchor,
    unsigned int index
)
{
    wchar_t leaf[80];
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    DWORD attributes;
    DWORD error;
    int result = -1;
    if (anchor == NULL
        || wls_capacity_platform_reserve_leaf(anchor, index, leaf) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            anchor->parent,
            leaf
        ) != 0) return -1;
    attributes = GetFileAttributesW(path);
    if (attributes == INVALID_FILE_ATTRIBUTES) {
        error = GetLastError();
        result = error == ERROR_FILE_NOT_FOUND
            || error == ERROR_PATH_NOT_FOUND ? 1 : -1;
        goto cleanup;
    }
    if (wls_capacity_platform_reserve_file_open(
            anchor,
            index,
            GENERIC_READ,
            FILE_SHARE_READ,
            &file
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE && !CloseHandle(file)) result = -1;
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

/* 0=exact protected staging (including a partial allocation), 1=absent. */
static int wls_capacity_platform_staging_file_state(
    const struct wls_capacity_platform_anchor *anchor,
    unsigned int index,
    HANDLE *opened
)
{
    wchar_t leaf[80];
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation observation;
    DWORD attributes;
    DWORD error;
    int result = -1;
    ZeroMemory(&observation, sizeof(observation));
    if (opened != NULL) *opened = INVALID_HANDLE_VALUE;
    if (anchor == NULL
        || wls_capacity_platform_reserve_staging_leaf(
            anchor, index, leaf
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            anchor->parent,
            leaf
        ) != 0) return -1;
    attributes = GetFileAttributesW(path);
    if (attributes == INVALID_FILE_ATTRIBUTES) {
        error = GetLastError();
        result = error == ERROR_FILE_NOT_FOUND
            || error == ERROR_PATH_NOT_FOUND ? 1 : -1;
        goto cleanup;
    }
    if (wls_capacity_open_file(
            path,
            GENERIC_READ | (opened != NULL ? DELETE : 0U),
            opened != NULL ? 0U : FILE_SHARE_READ,
            anchor->production_acl,
            &file,
            &observation
        ) != 0
        || observation.identity.VolumeSerialNumber != anchor->volume
        || observation.standard.EndOfFile.QuadPart < 0LL
        || observation.standard.EndOfFile.QuadPart
            > (LONGLONG)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
        || observation.standard.AllocationSize.QuadPart < 0LL
        || observation.standard.AllocationSize.QuadPart
            > (LONGLONG)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
        || observation.standard.NumberOfLinks != 1U
        || wls_capacity_platform_direct_acl_exact(
            file, anchor->production_acl
        ) != 0) goto cleanup;
    if (opened != NULL) {
        *opened = file;
        file = INVALID_HANDLE_VALUE;
    }
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE && !CloseHandle(file)) result = -1;
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(&observation, sizeof(observation));
    return result;
}

/* 0=all exact, 1=all absent, 2=exact crash subset, -1=unsafe. */
static int wls_capacity_platform_reserve_state(
    const struct wls_capacity_platform_anchor *anchor
)
{
    HANDLE parent = INVALID_HANDLE_VALUE;
    unsigned int index;
    unsigned int present = 0U;
    unsigned int staging = 0U;
    int result = -1;
    if (anchor == NULL
        || wls_capacity_platform_parent_open(anchor, &parent) != 0
        || wls_capacity_platform_namespace_known(anchor) != 0) goto cleanup;
    for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; index++) {
        int state = wls_capacity_platform_reserve_file_state(anchor, index);
        int staging_state = wls_capacity_platform_staging_file_state(
            anchor, index, NULL
        );
        if (state < 0 || staging_state < 0
            || (state == 0 && staging_state == 0)) goto cleanup;
        if (state == 0) present++;
        if (staging_state == 0) staging++;
    }
    result = present == 0U && staging == 0U ? 1
        : staging > 0U ? 2
        : present == WLS_CAPACITY_PLATFORM_INODES ? 0 : 2;
cleanup:
    if (parent != INVALID_HANDLE_VALUE && !CloseHandle(parent)) result = -1;
    return result;
}

static int wls_capacity_platform_reserve_allocate_file(
    const struct wls_capacity_platform_anchor *anchor,
    unsigned int index,
    HANDLE volume
)
{
    wchar_t leaf[80];
    wchar_t staging_leaf[80];
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    wchar_t staging_path[WLS_CAPACITY_PATH_CHARS];
    PSECURITY_DESCRIPTOR security = NULL;
    SECURITY_ATTRIBUTES attributes;
    HANDLE file = INVALID_HANDLE_VALUE;
    FILE_ALLOCATION_INFO allocation;
    FILE_END_OF_FILE_INFO end_of_file;
    struct wls_capacity_observation observation;
    LARGE_INTEGER offset;
    unsigned char marker = 0xA5U;
    DWORD written = 0U;
    int created = 0;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    ZeroMemory(&allocation, sizeof(allocation));
    ZeroMemory(&end_of_file, sizeof(end_of_file));
    ZeroMemory(&observation, sizeof(observation));
    if (anchor == NULL || volume == NULL || volume == INVALID_HANDLE_VALUE
        || wls_capacity_platform_reserve_leaf(anchor, index, leaf) != 0
        || wls_capacity_platform_reserve_staging_leaf(
            anchor, index, staging_leaf
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            anchor->parent,
            leaf
        ) != 0
        || wls_capacity_join(
            staging_path,
            WLS_CAPACITY_PATH_CHARS,
            anchor->parent,
            staging_leaf
        ) != 0
        || wls_capacity_platform_direct_descriptor(
            anchor->production_acl,
            &security
        ) != 0) goto cleanup;
    attributes.nLength = (DWORD)sizeof(attributes);
    attributes.lpSecurityDescriptor = security;
    attributes.bInheritHandle = FALSE;
    file = CreateFileW(
        staging_path,
        GENERIC_READ | GENERIC_WRITE | READ_CONTROL | WRITE_DAC | WRITE_OWNER,
        FILE_SHARE_READ,
        &attributes,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH
            | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (file == INVALID_HANDLE_VALUE) goto cleanup;
    created = 1;
    if (wls_capacity_platform_direct_apply(file, security) != 0
        || wls_capacity_platform_direct_acl_exact(
            file, anchor->production_acl
        ) != 0
        || !FlushFileBuffers(file)
        || !FlushFileBuffers(volume)
        || wls_capacity_test_failpoint(L"direct-seal") != 0) {
        goto cleanup;
    }
    allocation.AllocationSize.QuadPart =
        (LONGLONG)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE;
    end_of_file.EndOfFile.QuadPart =
        (LONGLONG)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE;
    offset.QuadPart = 0LL;
    if (!SetFileInformationByHandle(
            file,
            FileAllocationInfo,
            &allocation,
            sizeof(allocation)
        )
        || !SetFileInformationByHandle(
            file,
            FileEndOfFileInfo,
            &end_of_file,
            sizeof(end_of_file)
        )
        || !SetFilePointerEx(file, offset, NULL, FILE_BEGIN)
        || !WriteFile(file, &marker, 1U, &written, NULL)
        || written != 1U) goto cleanup;
    offset.QuadPart =
        (LONGLONG)(WLS_CAPACITY_PLATFORM_BYTES_PER_FILE - 1ULL);
    written = 0U;
    if (!SetFilePointerEx(file, offset, NULL, FILE_BEGIN)
        || !WriteFile(file, &marker, 1U, &written, NULL)
        || written != 1U
        || !FlushFileBuffers(file)
        || wls_capacity_observe(file, 0, &observation) != 0
        || observation.identity.VolumeSerialNumber != anchor->volume
        || observation.standard.EndOfFile.QuadPart
            != (LONGLONG)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
        || observation.standard.AllocationSize.QuadPart
            < (LONGLONG)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
        || observation.standard.NumberOfLinks != 1U
        || wls_capacity_final_path_matches(file, staging_path) != 0
        || wls_capacity_platform_direct_acl_exact(
            file,
            anchor->production_acl
        ) != 0) goto cleanup;
    if (!CloseHandle(file)) {
        file = INVALID_HANDLE_VALUE;
        goto cleanup;
    }
    file = INVALID_HANDLE_VALUE;
    if (!MoveFileExW(
            staging_path, path, MOVEFILE_WRITE_THROUGH
        ) || !FlushFileBuffers(volume)) goto cleanup;
    created = 0;
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE && !CloseHandle(file)) result = 1;
    if (result != 0 && created) (void)DeleteFileW(staging_path);
    if (security != NULL) LocalFree(security);
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(staging_leaf, sizeof(staging_leaf));
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(staging_path, sizeof(staging_path));
    SecureZeroMemory(&observation, sizeof(observation));
    return result;
}

static int wls_capacity_platform_reserve_create(
    const struct wls_capacity_platform_anchor *anchor,
    HANDLE volume
)
{
    HANDLE parent = INVALID_HANDLE_VALUE;
    unsigned int index;
    int result = 1;
    if (anchor == NULL || volume == NULL || volume == INVALID_HANDLE_VALUE
        || wls_capacity_platform_parent_open(anchor, &parent) != 0
        || wls_capacity_platform_reserve_state(anchor) != 1) goto cleanup;
    for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; index++) {
        if (wls_capacity_platform_reserve_allocate_file(
                anchor, index, volume
            ) != 0
            || !FlushFileBuffers(volume)) goto cleanup;
    }
    result = wls_capacity_platform_reserve_state(anchor) == 0 ? 0 : 1;
cleanup:
    if (parent != INVALID_HANDLE_VALUE && !CloseHandle(parent)) result = 1;
    return result;
}

static int wls_capacity_platform_reserve_release(
    const struct wls_capacity_platform_anchor *anchor,
    HANDLE volume,
    int allow_absent
)
{
    HANDLE parent = INVALID_HANDLE_VALUE;
    unsigned int index;
    int state;
    int result = 1;
    if (anchor == NULL || volume == NULL || volume == INVALID_HANDLE_VALUE
        || (allow_absent != 0 && allow_absent != 1)) return 1;
    if (wls_capacity_platform_parent_open(anchor, &parent) != 0) {
        return 1;
    }
    state = wls_capacity_platform_reserve_state(anchor);
    if (state == 1) {
        result = allow_absent ? 0 : 1;
        goto cleanup;
    }
    if (state != 0 && state != 2) goto cleanup;
    for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; index++) {
        HANDLE file = INVALID_HANDLE_VALUE;
        FILE_DISPOSITION_INFO disposition;
        int file_state = wls_capacity_platform_reserve_file_state(
            anchor, index
        );
        ZeroMemory(&disposition, sizeof(disposition));
        if (file_state != 1) {
            if (file_state != 0
                || wls_capacity_platform_reserve_file_open(
                    anchor,
                    index,
                    GENERIC_READ | DELETE,
                    0U,
                    &file
                ) != 0) goto cleanup;
            disposition.DeleteFile = TRUE;
            if (!SetFileInformationByHandle(
                    file,
                    FileDispositionInfo,
                    &disposition,
                    sizeof(disposition)
                )) {
                CloseHandle(file);
                goto cleanup;
            }
            if (!CloseHandle(file)) {
                file = INVALID_HANDLE_VALUE;
                goto cleanup;
            }
            file = INVALID_HANDLE_VALUE;
            if (!FlushFileBuffers(volume)) goto cleanup;
        }
        {
            int staging_state = wls_capacity_platform_staging_file_state(
                anchor, index, &file
            );
            if (staging_state < 0) goto cleanup;
            if (staging_state == 0) {
                disposition.DeleteFile = TRUE;
                if (!SetFileInformationByHandle(
                        file,
                        FileDispositionInfo,
                        &disposition,
                        sizeof(disposition)
                    )) {
                    CloseHandle(file);
                    file = INVALID_HANDLE_VALUE;
                    goto cleanup;
                }
                if (!CloseHandle(file)) {
                    file = INVALID_HANDLE_VALUE;
                    goto cleanup;
                }
                file = INVALID_HANDLE_VALUE;
                if (!FlushFileBuffers(volume)) goto cleanup;
            }
        }
    }
    result = wls_capacity_platform_reserve_state(anchor) == 1 ? 0 : 1;
cleanup:
    if (parent != INVALID_HANDLE_VALUE && !CloseHandle(parent)) result = 1;
    return result;
}

static int wls_capacity_platform_reserve_verify(
    const struct wls_capacity_platform_anchor *anchor
)
{
    return wls_capacity_platform_reserve_state(anchor) == 0 ? 0 : 1;
}

static int wls_capacity_platform_reserve_absent(
    const struct wls_capacity_platform_anchor *anchor
)
{
    return wls_capacity_platform_reserve_state(anchor) == 1 ? 0 : 1;
}

static int wls_capacity_platform_reserve_cleanup_allocating(
    const struct wls_capacity_platform_anchor *anchor,
    HANDLE volume
)
{
    return wls_capacity_platform_reserve_release(anchor, volume, 1);
}

static void wls_capacity_print_inspect(const char *state)
{
    (void)printf(
        "{\"schema\":\"%s\",\"state\":\"%s\"}\n",
        WLS_CAPACITY_INSPECT_SCHEMA,
        state
    );
}

static int wls_capacity_manifest_binding(
    const wchar_t *capacity,
    const wchar_t *nonce,
    const char *expected,
    unsigned long long expected_volume,
    int production_acl
)
{
    wchar_t leaf[48];
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation before;
    struct wls_capacity_observation after;
    crypto_hash_sha256_state hash;
    unsigned char digest[crypto_hash_sha256_BYTES];
    unsigned char buffer[4096];
    char actual[65];
    DWORD amount = 0U;
    unsigned long long total = 0ULL;
    int result = 1;
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(actual, sizeof(actual));
    if (capacity == NULL || nonce == NULL || expected == NULL
        || _snwprintf_s(
            leaf,
            48U,
            _TRUNCATE,
            L"%ls.held.json",
            nonce
        ) < 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            capacity,
            leaf
        ) != 0
        || wls_capacity_open_file(
            path,
            GENERIC_READ,
            FILE_SHARE_READ,
            production_acl,
            &file,
            &before
        ) != 0
        || before.identity.VolumeSerialNumber != expected_volume
        || before.standard.EndOfFile.QuadPart <= 0LL
        || before.standard.EndOfFile.QuadPart
            > (LONGLONG)WLS_CAPACITY_EVIDENCE_MAX_BYTES
        || crypto_hash_sha256_init(&hash) != 0) goto cleanup;
    for (;;) {
        if (!ReadFile(file, buffer, sizeof(buffer), &amount, NULL)) goto cleanup;
        if (amount == 0U) break;
        total += amount;
        if (total > WLS_CAPACITY_EVIDENCE_MAX_BYTES
            || crypto_hash_sha256_update(&hash, buffer, amount) != 0) {
            goto cleanup;
        }
    }
    if (total != (unsigned long long)before.standard.EndOfFile.QuadPart
        || wls_capacity_observe(file, 0, &after) != 0
        || !wls_capacity_observation_equal(&before, &after)
        || crypto_hash_sha256_final(&hash, digest) != 0) goto cleanup;
    sodium_bin2hex(actual, sizeof(actual), digest, sizeof(digest));
    result = sodium_memcmp(actual, expected, 64U) == 0 ? 0 : 1;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    SecureZeroMemory(&before, sizeof(before));
    SecureZeroMemory(&after, sizeof(after));
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(buffer, sizeof(buffer));
    SecureZeroMemory(actual, sizeof(actual));
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_capacity_live_present(
    const wchar_t *path,
    unsigned long long expected_volume,
    int production_acl,
    int *present
)
{
    return wls_capacity_directory_optional(
        path,
        expected_volume,
        production_acl,
        present
    );
}

static int wls_capacity_remove_token_directory(
    const wchar_t *directory,
    unsigned int maximum,
    unsigned long long expected_volume,
    int production_acl,
    HANDLE volume,
    int optional
)
{
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    unsigned int removed = 0U;
    unsigned int cursor;
    int present = 0;
    if (directory == NULL || maximum == 0U
        || volume == NULL || volume == INVALID_HANDLE_VALUE
        || wls_capacity_directory_optional(
            directory,
            expected_volume,
            production_acl,
            &present
        ) != 0) return 1;
    if (!present) return optional ? 0 : 1;
    if (wls_capacity_validate_tokens(
            directory,
            maximum,
            expected_volume,
            NULL,
            NULL,
            production_acl,
            0
        ) != 0) return 1;
    for (cursor = maximum; cursor > 0U; --cursor) {
        wchar_t leaf[17];
        DWORD attributes;
        DWORD error;
        struct wls_capacity_observation token;
        if (wls_capacity_token_leaf(cursor - 1U, leaf) != 0
            || wls_capacity_join(
                path,
                WLS_CAPACITY_PATH_CHARS,
                directory,
                leaf
            ) != 0) return 1;
        attributes = GetFileAttributesW(path);
        if (attributes == INVALID_FILE_ATTRIBUTES) {
            error = GetLastError();
            if (error == ERROR_FILE_NOT_FOUND
                || error == ERROR_PATH_NOT_FOUND) continue;
            return 1;
        }
        if (wls_capacity_validate_file(
                path,
                expected_volume,
                0LL,
                0ULL,
                production_acl,
                &token
            ) != 0
            || !DeleteFileW(path)) return 1;
        removed++;
        if ((removed == 1U
                || removed % WLS_CAPACITY_TOKEN_FLUSH_BATCH == 0U)
            && !FlushFileBuffers(volume)) return 1;
        if (removed == 1U
            && wls_capacity_test_failpoint(
                maximum == WLS_CAPACITY_CONTROL_INODES
                    ? L"control-token-partial"
                    : L"primary-token-partial"
            ) != 0) return 1;
    }
    if ((removed % WLS_CAPACITY_TOKEN_FLUSH_BATCH != 0U || removed == 0U)
        && !FlushFileBuffers(volume)) return 1;
    if (!RemoveDirectoryW(directory) || !FlushFileBuffers(volume)) return 1;
    SecureZeroMemory(path, sizeof(path));
    return 0;
}

static int wls_capacity_delete_optional_file(
    const wchar_t *path,
    unsigned long long expected_volume,
    int production_acl,
    HANDLE volume
)
{
    DWORD attributes;
    DWORD error;
    HANDLE file = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation observation;
    if (path == NULL || volume == NULL || volume == INVALID_HANDLE_VALUE) {
        return 1;
    }
    attributes = GetFileAttributesW(path);
    if (attributes == INVALID_FILE_ATTRIBUTES) {
        error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
            ? 0 : 1;
    }
    if (wls_capacity_open_file(
            path,
            DELETE | GENERIC_READ,
            FILE_SHARE_READ,
            production_acl,
            &file,
            &observation
        ) != 0
        || observation.identity.VolumeSerialNumber != expected_volume) {
        if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
        return 1;
    }
    if (!CloseHandle(file)) return 1;
    file = INVALID_HANDLE_VALUE;
    return DeleteFileW(path) && FlushFileBuffers(volume) ? 0 : 1;
}

static int wls_capacity_validate_removable_live(
    const wchar_t *live,
    unsigned int target_inodes,
    unsigned long long expected_volume,
    int production_acl
)
{
    wchar_t pattern[WLS_CAPACITY_PATH_CHARS];
    WIN32_FIND_DATAW entry;
    HANDLE search = INVALID_HANDLE_VALUE;
    HANDLE live_handle = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation live_observation;
    int result = 1;
    ZeroMemory(&live_observation, sizeof(live_observation));
    if (live == NULL || target_inodes == 0U
        || wls_capacity_open_directory(
            live,
            production_acl,
            0,
            &live_handle,
            &live_observation
        ) != 0
        || live_observation.identity.VolumeSerialNumber != expected_volume
        || wls_capacity_directory_acl_exact(
            live_handle, production_acl
        ) != 0
        || wls_capacity_join(
            pattern,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"*"
        ) != 0) return 1;
    search = FindFirstFileW(pattern, &entry);
    if (search == INVALID_HANDLE_VALUE) goto cleanup;
    do {
        wchar_t path[WLS_CAPACITY_PATH_CHARS];
        if (wcscmp(entry.cFileName, L".") == 0
            || wcscmp(entry.cFileName, L"..") == 0) continue;
        if (wcscmp(entry.cFileName, L"bytes.reserve") != 0
            && wcscmp(entry.cFileName, L"control.reserve") != 0
            && wcscmp(entry.cFileName, L"tokens") != 0
            && wcscmp(entry.cFileName, L"control-tokens") != 0) {
            goto cleanup;
        }
        if (wls_capacity_join(
                path,
                WLS_CAPACITY_PATH_CHARS,
                live,
                entry.cFileName
            ) != 0) goto cleanup;
        if (wcscmp(entry.cFileName, L"tokens") == 0) {
            if (wls_capacity_validate_tokens(
                    path,
                    target_inodes,
                    expected_volume,
                    NULL,
                    NULL,
                    production_acl,
                    0
                ) != 0) goto cleanup;
        } else if (wcscmp(entry.cFileName, L"control-tokens") == 0) {
            if (wls_capacity_validate_tokens(
                    path,
                    WLS_CAPACITY_CONTROL_INODES,
                    expected_volume,
                    NULL,
                    NULL,
                    production_acl,
                    0
                ) != 0) goto cleanup;
        } else {
            HANDLE file = INVALID_HANDLE_VALUE;
            struct wls_capacity_observation observation;
            if (wls_capacity_open_file(
                    path,
                    GENERIC_READ,
                    FILE_SHARE_READ,
                    production_acl,
                    &file,
                    &observation
                ) != 0
                || observation.identity.VolumeSerialNumber != expected_volume
                || wls_capacity_platform_direct_acl_exact(
                    file, production_acl
                ) != 0) {
                if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
                goto cleanup;
            }
            if (!CloseHandle(file)) goto cleanup;
            file = INVALID_HANDLE_VALUE;
        }
    } while (FindNextFileW(search, &entry));
    if (GetLastError() != ERROR_NO_MORE_FILES
        || !FindClose(search)) goto cleanup;
    search = INVALID_HANDLE_VALUE;
    result = 0;
cleanup:
    if (search != INVALID_HANDLE_VALUE) FindClose(search);
    if (live_handle != INVALID_HANDLE_VALUE) CloseHandle(live_handle);
    SecureZeroMemory(&live_observation, sizeof(live_observation));
    SecureZeroMemory(pattern, sizeof(pattern));
    return result;
}

static int wls_capacity_remove_live(
    const wchar_t *live,
    unsigned int target_inodes,
    unsigned long long expected_volume,
    int production_acl,
    HANDLE volume
)
{
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    if (wls_capacity_validate_removable_live(
            live,
            target_inodes,
            expected_volume,
            production_acl
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"control-tokens"
        ) != 0
        || wls_capacity_remove_token_directory(
            path,
            WLS_CAPACITY_CONTROL_INODES,
            expected_volume,
            production_acl,
            volume,
            1
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"tokens"
        ) != 0
        || wls_capacity_remove_token_directory(
            path,
            target_inodes,
            expected_volume,
            production_acl,
            volume,
            1
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"control.reserve"
        ) != 0
        || wls_capacity_delete_optional_file(
            path,
            expected_volume,
            production_acl,
            volume
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"bytes.reserve"
        ) != 0
        || wls_capacity_delete_optional_file(
            path,
            expected_volume,
            production_acl,
            volume
        ) != 0
        || !RemoveDirectoryW(live)
        || !FlushFileBuffers(volume)) return 1;
    SecureZeroMemory(path, sizeof(path));
    return 0;
}

static int wls_capacity_mark_release(
    const wchar_t *live,
    unsigned long long expected_volume,
    int production_acl
)
{
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation before;
    struct wls_capacity_observation after;
    LARGE_INTEGER offset;
    DWORD written = 0U;
    int result = 1;
    if (wls_capacity_validate_control_state(
            live,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_REQUIRED
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"control.reserve"
        ) != 0
        || wls_capacity_open_file(
            path,
            GENERIC_READ | GENERIC_WRITE,
            FILE_SHARE_READ,
            production_acl,
            &file,
            &before
        ) != 0
        || before.identity.VolumeSerialNumber != expected_volume) goto cleanup;
    offset.QuadPart = 0LL;
    if (!SetFilePointerEx(file, offset, NULL, FILE_BEGIN)
        || !WriteFile(
            file,
            WLS_CAPACITY_RELEASE_MARKER,
            (DWORD)(sizeof(WLS_CAPACITY_RELEASE_MARKER) - 1U),
            &written,
            NULL
        )
        || written != (DWORD)(sizeof(WLS_CAPACITY_RELEASE_MARKER) - 1U)
        || !FlushFileBuffers(file)
        || wls_capacity_observe(file, 0, &after) != 0
        || before.identity.VolumeSerialNumber
            != after.identity.VolumeSerialNumber
        || memcmp(
            before.identity.FileId.Identifier,
            after.identity.FileId.Identifier,
            sizeof(before.identity.FileId.Identifier)
        ) != 0
        || before.standard.EndOfFile.QuadPart
            != after.standard.EndOfFile.QuadPart
        || before.standard.AllocationSize.QuadPart
            != after.standard.AllocationSize.QuadPart) goto cleanup;
    if (!CloseHandle(file)) {
        file = INVALID_HANDLE_VALUE;
        goto cleanup;
    }
    file = INVALID_HANDLE_VALUE;
    if (wls_capacity_validate_control_state(
            live,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_TRANSITION
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    SecureZeroMemory(&before, sizeof(before));
    SecureZeroMemory(&after, sizeof(after));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_capacity_sync_release_marker(
    const wchar_t *live,
    unsigned long long expected_volume,
    int production_acl
)
{
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation observation;
    int result = 1;
    if (wls_capacity_validate_control_state(
            live,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_TRANSITION
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"control.reserve"
        ) != 0
        || wls_capacity_open_file(
            path,
            GENERIC_READ | GENERIC_WRITE,
            FILE_SHARE_READ,
            production_acl,
            &file,
            &observation
        ) != 0
        || observation.identity.VolumeSerialNumber != expected_volume
        || !FlushFileBuffers(file)) goto cleanup;
    if (!CloseHandle(file)) {
        file = INVALID_HANDLE_VALUE;
        goto cleanup;
    }
    file = INVALID_HANDLE_VALUE;
    if (wls_capacity_validate_control_state(
            live,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_TRANSITION
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    SecureZeroMemory(&observation, sizeof(observation));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_capacity_prepare_release(
    const wchar_t *live,
    unsigned long long target_bytes,
    unsigned int target_inodes,
    unsigned long long expected_volume,
    int production_acl,
    HANDLE volume,
    struct wls_capacity_evidence *evidence
)
{
    wchar_t tokens[WLS_CAPACITY_PATH_CHARS];
    int state = wls_capacity_detect_control_state(
        live,
        expected_volume,
        production_acl
    );
    if ((state != WLS_CAPACITY_CONTROL_REQUIRED
            && state != WLS_CAPACITY_CONTROL_TRANSITION)
        || wls_capacity_validate_live(
            live,
            target_bytes,
            target_inodes,
            expected_volume,
            production_acl,
            state,
            evidence
        ) != 0
        || (state == WLS_CAPACITY_CONTROL_REQUIRED
            && wls_capacity_mark_release(
                live,
                expected_volume,
                production_acl
            ) != 0)
        || (state == WLS_CAPACITY_CONTROL_TRANSITION
            && wls_capacity_sync_release_marker(
                live,
                expected_volume,
                production_acl
            ) != 0)
        || wls_capacity_join(
            tokens,
            WLS_CAPACITY_PATH_CHARS,
            live,
            L"control-tokens"
        ) != 0
        || wls_capacity_remove_token_directory(
            tokens,
            WLS_CAPACITY_CONTROL_INODES,
            expected_volume,
            production_acl,
            volume,
            1
        ) != 0
        || wls_capacity_validate_live(
            live,
            target_bytes,
            target_inodes,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_TRANSITION,
            evidence
        ) != 0) return 1;
    return 0;
}

static int wls_capacity_finish_release_control(
    const wchar_t *live,
    unsigned long long target_bytes,
    unsigned int target_inodes,
    unsigned long long expected_volume,
    int production_acl,
    HANDLE volume,
    struct wls_capacity_evidence *evidence
)
{
    wchar_t control[WLS_CAPACITY_PATH_CHARS];
    int state = wls_capacity_detect_control_state(
        live,
        expected_volume,
        production_acl
    );
    if ((state != WLS_CAPACITY_CONTROL_TRANSITION
            && state != WLS_CAPACITY_CONTROL_ABSENT)
        || wls_capacity_validate_live(
            live,
            target_bytes,
            target_inodes,
            expected_volume,
            production_acl,
            state,
            evidence
        ) != 0) return 1;
    if (state == WLS_CAPACITY_CONTROL_TRANSITION) {
        if (wls_capacity_join(
                control,
                WLS_CAPACITY_PATH_CHARS,
                live,
                L"control.reserve"
            ) != 0
            || wls_capacity_delete_optional_file(
                control,
                expected_volume,
                production_acl,
                volume
            ) != 0) return 1;
    }
    return wls_capacity_validate_live(
        live,
        target_bytes,
        target_inodes,
        expected_volume,
        production_acl,
        WLS_CAPACITY_CONTROL_ABSENT,
        evidence
    );
}

static int wls_capacity_create_live(
    const wchar_t *allocating,
    unsigned long long target_bytes,
    unsigned int target_inodes,
    unsigned long long expected_volume,
    int production_acl,
    HANDLE volume,
    struct wls_capacity_evidence *evidence
)
{
    wchar_t path[WLS_CAPACITY_PATH_CHARS];
    if (wls_capacity_create_directory(
            allocating,
            production_acl,
            volume
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            allocating,
            L"bytes.reserve"
        ) != 0
        || wls_capacity_allocate_file(
            path,
            target_bytes,
            production_acl
        ) != 0
        || wls_capacity_test_failpoint(L"allocation") != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            allocating,
            L"tokens"
        ) != 0
        || wls_capacity_create_directory(path, production_acl, volume) != 0
        || wls_capacity_create_tokens(
            path,
            target_inodes,
            production_acl,
            volume
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            allocating,
            L"control.reserve"
        ) != 0
        || wls_capacity_allocate_file(
            path,
            WLS_CAPACITY_CONTROL_BYTES,
            production_acl
        ) != 0
        || wls_capacity_join(
            path,
            WLS_CAPACITY_PATH_CHARS,
            allocating,
            L"control-tokens"
        ) != 0
        || wls_capacity_create_directory(path, production_acl, volume) != 0
        || wls_capacity_create_tokens(
            path,
            WLS_CAPACITY_CONTROL_INODES,
            production_acl,
            volume
        ) != 0
        || !FlushFileBuffers(volume)
        || wls_capacity_validate_live(
            allocating,
            target_bytes,
            target_inodes,
            expected_volume,
            production_acl,
            WLS_CAPACITY_CONTROL_REQUIRED,
            evidence
        ) != 0) return 1;
    return 0;
}

static void wls_capacity_print_evidence(
    const char *state,
    const struct wls_capacity_evidence *evidence
)
{
    (void)printf(
        "{\"anchor_set_sha256\":\"%s\","
        "\"entry_set_sha256\":\"%s\","
        "\"inode_count\":%u,"
        "\"physical_bytes\":%llu,"
        "\"state\":\"%s\","
        "\"volume_id\":\"%s\"}\n",
        evidence->anchor_set_sha256,
        evidence->entry_set_sha256,
        evidence->inode_count,
        evidence->physical_bytes,
        state,
        evidence->volume_id
    );
}

int wls_windows_capacity_command(int argc, wchar_t **argv)
{
    const wchar_t *operation = wls_capacity_argument(
        argc,
        argv,
        L"--capacity-reserve"
    );
    const wchar_t *home_argument = wls_capacity_argument(
        argc,
        argv,
        L"--home"
    );
    const wchar_t *nonce_argument = wls_capacity_argument(
        argc,
        argv,
        L"--nonce"
    );
    const wchar_t *bytes_argument = wls_capacity_argument(
        argc,
        argv,
        L"--bytes"
    );
    const wchar_t *inodes_argument = wls_capacity_argument(
        argc,
        argv,
        L"--inodes"
    );
    const wchar_t *platform_definition = wls_capacity_argument(
        argc,
        argv,
        L"--platform-definition"
    );
    const wchar_t *test_argument = wls_capacity_argument(
        argc,
        argv,
        L"--test-mode"
    );
    const wchar_t *reason = wls_capacity_argument(
        argc,
        argv,
        L"--release-reason"
    );
    const wchar_t *manifest_argument = wls_capacity_argument(
        argc,
        argv,
        L"--expected-manifest-sha256"
    );
    wchar_t home[WLS_CAPACITY_PATH_CHARS];
    wchar_t authority_home[WLS_CAPACITY_PATH_CHARS];
    wchar_t temporary[WLS_CAPACITY_PATH_CHARS];
    wchar_t temporary_long[WLS_CAPACITY_PATH_CHARS];
    wchar_t capacity[WLS_CAPACITY_PATH_CHARS];
    wchar_t allocating_leaf[48];
    wchar_t held_leaf[48];
    wchar_t releasing_leaf[48];
    wchar_t allocating[WLS_CAPACITY_PATH_CHARS];
    wchar_t held[WLS_CAPACITY_PATH_CHARS];
    wchar_t releasing[WLS_CAPACITY_PATH_CHARS];
    char expected_manifest[65];
    unsigned long long target_bytes = 0ULL;
    unsigned long long target_inodes_wide = 0ULL;
    unsigned int target_inodes;
    HANDLE capacity_handle = INVALID_HANDLE_VALUE;
    HANDLE volume = INVALID_HANDLE_VALUE;
    struct wls_capacity_observation capacity_observation;
    struct wls_capacity_evidence evidence;
    struct wls_capacity_platform_anchor platform_anchor;
    FILE_ID_INFO authority_identity;
    DWORD temporary_length;
    int test_mode;
    int production_acl;
    int allocating_present = 0;
    int held_present = 0;
    int releasing_present = 0;
    int live_count;
    int inspect_operation;
    int result = 1;
    SecureZeroMemory(home, sizeof(home));
    SecureZeroMemory(authority_home, sizeof(authority_home));
    SecureZeroMemory(temporary, sizeof(temporary));
    SecureZeroMemory(temporary_long, sizeof(temporary_long));
    SecureZeroMemory(expected_manifest, sizeof(expected_manifest));
    SecureZeroMemory(&capacity_observation, sizeof(capacity_observation));
    SecureZeroMemory(&evidence, sizeof(evidence));
    SecureZeroMemory(&platform_anchor, sizeof(platform_anchor));
    SecureZeroMemory(&authority_identity, sizeof(authority_identity));
    if (operation == NULL || home_argument == NULL
        || !wls_capacity_wide_hex(nonce_argument, 32U, NULL)
        || bytes_argument == NULL || inodes_argument == NULL
        || platform_definition == NULL || test_argument == NULL
        || (wcscmp(test_argument, L"0") != 0
            && wcscmp(test_argument, L"1") != 0)
        || wls_capacity_unsigned(
            bytes_argument,
            WLS_CAPACITY_PRODUCTION_BYTES,
            &target_bytes
        ) != 0
        || wls_capacity_unsigned(
            inodes_argument,
            WLS_CAPACITY_PRODUCTION_INODES,
            &target_inodes_wide
        ) != 0
        || target_inodes_wide == 0ULL
        || target_inodes_wide > UINT_MAX) {
        (void)fprintf(stderr, "capacity reserve arguments are invalid\n");
        return 64;
    }
    test_mode = wcscmp(test_argument, L"1") == 0;
    inspect_operation = wcscmp(operation, L"inspect") == 0;
    production_acl = !test_mode;
    target_inodes = (unsigned int)target_inodes_wide;
    if ((test_mode
            && (target_bytes != WLS_CAPACITY_TEST_BYTES
                || target_inodes != WLS_CAPACITY_TEST_INODES))
        || (!test_mode
            && (target_bytes != WLS_CAPACITY_PRODUCTION_BYTES
                || target_inodes != WLS_CAPACITY_PRODUCTION_INODES))
        || (wcscmp(operation, L"create") != 0
            && wcscmp(operation, L"verify") != 0
            && !inspect_operation
            && wcscmp(operation, L"begin-release") != 0
            && wcscmp(operation, L"complete-release") != 0)
        || ((wcscmp(operation, L"create") == 0
                || wcscmp(operation, L"verify") == 0
                || inspect_operation)
            && reason != NULL)
        || ((wcscmp(operation, L"begin-release") == 0
                || wcscmp(operation, L"complete-release") == 0)
            && (reason == NULL
                || (wcscmp(reason, L"forward") != 0
                    && wcscmp(reason, L"rollback") != 0
                    && wcscmp(reason, L"cancel") != 0)))
        || ((wcscmp(operation, L"verify") == 0
                || wcscmp(operation, L"begin-release") == 0)
            && !wls_capacity_wide_hex(
                manifest_argument,
                64U,
                expected_manifest
            ))
        || (inspect_operation && manifest_argument != NULL)
        || (manifest_argument != NULL
            && !wls_capacity_wide_hex(
                manifest_argument,
                64U,
                expected_manifest
            ))) {
        (void)fprintf(stderr, "capacity reserve contract is invalid\n");
        return 64;
    }
    if (wls_capacity_normalize_path(home_argument, home) != 0
        || wls_capacity_fixed_drive(home) != 0) {
        (void)fprintf(stderr, "capacity reserve home is invalid\n");
        return 77;
    }
    if (test_mode) {
        temporary_length = GetTempPathW(
            WLS_CAPACITY_PATH_CHARS,
            temporary
        );
        if (temporary_length == 0U
            || temporary_length >= WLS_CAPACITY_PATH_CHARS) {
            (void)fprintf(stderr, "test temporary root is unavailable\n");
            return 77;
        }
        temporary_length = GetLongPathNameW(
            temporary,
            temporary_long,
            WLS_CAPACITY_PATH_CHARS
        );
        if (temporary_length == 0U
            || temporary_length >= WLS_CAPACITY_PATH_CHARS
            || wls_capacity_normalize_path(
                temporary_long,
                authority_home
            ) != 0
            || !wls_capacity_path_within(home, authority_home, 0)
            || wls_capacity_validate_directory_chain(home, 0, NULL) != 0) {
            (void)fprintf(stderr, "test capacity root is outside temporary storage\n");
            return 77;
        }
    } else if (wls_capacity_authoritative_home(
            authority_home,
            &authority_identity
        ) != 0
        || _wcsicmp(home, authority_home) != 0) {
        (void)fprintf(stderr, "capacity root is not FOLDERID_ProgramData authority\n");
        return 77;
    }
    if (wls_capacity_join(
            capacity,
            WLS_CAPACITY_PATH_CHARS,
            home,
            L"rebootstrap\\capacity"
        ) != 0
        || _snwprintf_s(
            allocating_leaf,
            48U,
            _TRUNCATE,
            L"%ls.allocating",
            nonce_argument
        ) < 0
        || _snwprintf_s(
            held_leaf,
            48U,
            _TRUNCATE,
            L"%ls.held",
            nonce_argument
        ) < 0
        || _snwprintf_s(
            releasing_leaf,
            48U,
            _TRUNCATE,
            L"%ls.releasing",
            nonce_argument
        ) < 0
        || wls_capacity_join(
            allocating,
            WLS_CAPACITY_PATH_CHARS,
            capacity,
            allocating_leaf
        ) != 0
        || wls_capacity_join(
            held,
            WLS_CAPACITY_PATH_CHARS,
            capacity,
            held_leaf
        ) != 0
        || wls_capacity_join(
            releasing,
            WLS_CAPACITY_PATH_CHARS,
            capacity,
            releasing_leaf
        ) != 0
        || wls_capacity_open_directory(
            capacity,
            production_acl,
            inspect_operation ? 0 : 1,
            &capacity_handle,
            &capacity_observation
        ) != 0
        || (!test_mode
            && capacity_observation.identity.VolumeSerialNumber
                != authority_identity.VolumeSerialNumber)
        || (!inspect_operation
            && wls_capacity_volume_handle(home, &volume) != 0)
        || wls_capacity_anchor_proof(
            home,
            nonce_argument,
            platform_definition,
            capacity_observation.identity.VolumeSerialNumber,
            production_acl,
            &platform_anchor,
            &evidence
        ) != 0
        || (manifest_argument != NULL
            && wls_capacity_manifest_binding(
                capacity,
                nonce_argument,
                expected_manifest,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl
            ) != 0)) {
        (void)fprintf(stderr, "capacity reserve authority proof failed\n");
        if (inspect_operation) result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
        goto cleanup;
    }
    if (wls_capacity_test_configure(&platform_anchor, test_mode) != 0) {
        (void)fprintf(stderr, "capacity test failpoint is invalid\n");
        goto cleanup;
    }
    if (wls_capacity_live_present(
            allocating,
            capacity_observation.identity.VolumeSerialNumber,
            production_acl,
            &allocating_present
        ) != 0
        || wls_capacity_live_present(
            held,
            capacity_observation.identity.VolumeSerialNumber,
            production_acl,
            &held_present
        ) != 0
        || wls_capacity_live_present(
            releasing,
            capacity_observation.identity.VolumeSerialNumber,
            production_acl,
            &releasing_present
        ) != 0) {
        if (inspect_operation) result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
        goto cleanup;
    }
    live_count = allocating_present + held_present + releasing_present;
    if (live_count > 1) {
        (void)fprintf(stderr, "capacity reserve has conflicting live states\n");
        if (inspect_operation) result = WLS_CAPACITY_INSPECT_CONFLICT_EXIT;
        goto cleanup;
    }

    if (inspect_operation) {
        int platform_state = wls_capacity_platform_reserve_state(
            &platform_anchor
        );
        if (platform_state < 0) {
            result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
            goto cleanup;
        }
        if (live_count == 0) {
            if (platform_state != 1) {
                result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
                goto cleanup;
            }
            wls_capacity_print_inspect("NONE");
            result = 0;
            goto cleanup;
        }
        if (allocating_present) {
            if (wls_capacity_validate_removable_live(
                    allocating,
                    target_inodes,
                    capacity_observation.identity.VolumeSerialNumber,
                    production_acl
                ) != 0) {
                result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
                goto cleanup;
            }
            wls_capacity_print_inspect("ALLOCATING");
            result = 0;
            goto cleanup;
        }
        if (held_present) {
            if (platform_state != 0
                || wls_capacity_validate_live(
                    held,
                    target_bytes,
                    target_inodes,
                    capacity_observation.identity.VolumeSerialNumber,
                    production_acl,
                    WLS_CAPACITY_CONTROL_REQUIRED,
                    &evidence
                ) != 0) {
                result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
                goto cleanup;
            }
            wls_capacity_print_inspect("HELD");
            result = 0;
            goto cleanup;
        }
        if (releasing_present) {
            int control_state = wls_capacity_detect_control_state(
                releasing,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl
            );
            if ((control_state != WLS_CAPACITY_CONTROL_REQUIRED
                    && control_state != WLS_CAPACITY_CONTROL_TRANSITION
                    && control_state != WLS_CAPACITY_CONTROL_ABSENT)
                || (control_state == WLS_CAPACITY_CONTROL_REQUIRED
                    && platform_state != 0)
                || (control_state == WLS_CAPACITY_CONTROL_ABSENT
                    && platform_state != 1)
                || (control_state != WLS_CAPACITY_CONTROL_ABSENT
                    && wls_capacity_validate_live(
                        releasing,
                        target_bytes,
                        target_inodes,
                        capacity_observation.identity.VolumeSerialNumber,
                        production_acl,
                        control_state,
                        &evidence
                    ) != 0)
                || (control_state == WLS_CAPACITY_CONTROL_ABSENT
                    && wls_capacity_validate_removable_live(
                        releasing,
                        target_inodes,
                        capacity_observation.identity.VolumeSerialNumber,
                        production_acl
                    ) != 0)) {
                result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
                goto cleanup;
            }
            wls_capacity_print_inspect("RELEASING");
            result = 0;
            goto cleanup;
        }
        result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
        goto cleanup;
    }

    if (wcscmp(operation, L"create") == 0) {
        if (releasing_present) goto cleanup;
        if (held_present) {
            if (wls_capacity_validate_live(
                    held,
                    target_bytes,
                    target_inodes,
                    capacity_observation.identity.VolumeSerialNumber,
                    production_acl,
                    WLS_CAPACITY_CONTROL_REQUIRED,
                    &evidence
                ) != 0
                || wls_capacity_platform_reserve_verify(
                    &platform_anchor
                ) != 0) goto cleanup;
            wls_capacity_print_evidence("HELD", &evidence);
            result = 0;
            goto cleanup;
        }
        if (allocating_present
            && (wls_capacity_platform_reserve_cleanup_allocating(
                    &platform_anchor,
                    volume
                ) != 0
                || wls_capacity_remove_live(
                    allocating,
                    target_inodes,
                    capacity_observation.identity.VolumeSerialNumber,
                    production_acl,
                    volume
                ) != 0)) goto cleanup;
        if (wls_capacity_create_live(
                allocating,
                target_bytes,
                target_inodes,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl,
                volume,
                &evidence
            ) != 0
            || wls_capacity_platform_reserve_create(
                &platform_anchor,
                volume
            ) != 0
            || !MoveFileExW(
                allocating,
                held,
                MOVEFILE_WRITE_THROUGH
            )
            || !FlushFileBuffers(volume)
            || wls_capacity_test_failpoint(L"rename") != 0
            || wls_capacity_validate_live(
                held,
                target_bytes,
                target_inodes,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl,
                WLS_CAPACITY_CONTROL_REQUIRED,
                &evidence
            ) != 0
            || wls_capacity_platform_reserve_verify(
                &platform_anchor
            ) != 0) goto cleanup;
        wls_capacity_print_evidence("HELD", &evidence);
        result = 0;
        goto cleanup;
    }

    if (wcscmp(operation, L"verify") == 0) {
        if (!held_present || allocating_present || releasing_present
            || wls_capacity_validate_live(
                held,
                target_bytes,
                target_inodes,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl,
                WLS_CAPACITY_CONTROL_REQUIRED,
                &evidence
            ) != 0
            || wls_capacity_platform_reserve_verify(
                &platform_anchor
            ) != 0) goto cleanup;
        wls_capacity_print_evidence("HELD", &evidence);
        result = 0;
        goto cleanup;
    }

    if (wcscmp(operation, L"begin-release") == 0) {
        if ((!held_present && !releasing_present) || allocating_present
            || (held_present && wls_capacity_validate_live(
                held,
                target_bytes,
                target_inodes,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl,
                WLS_CAPACITY_CONTROL_REQUIRED,
                &evidence
            ) != 0)) goto cleanup;
        if (releasing_present) {
            int replay_control_state = wls_capacity_detect_control_state(
                releasing,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl
            );
            int replay_platform_state = wls_capacity_platform_reserve_state(
                &platform_anchor
            );
            if ((replay_control_state != WLS_CAPACITY_CONTROL_REQUIRED
                    && replay_control_state
                        != WLS_CAPACITY_CONTROL_TRANSITION
                    && replay_control_state != WLS_CAPACITY_CONTROL_ABSENT)
                || replay_platform_state < 0
                || (replay_control_state == WLS_CAPACITY_CONTROL_REQUIRED
                    && replay_platform_state != 0)
                || (replay_control_state == WLS_CAPACITY_CONTROL_ABSENT
                    && replay_platform_state != 1)
                || wls_capacity_validate_live(
                    releasing,
                    target_bytes,
                    target_inodes,
                    capacity_observation.identity.VolumeSerialNumber,
                    production_acl,
                    replay_control_state,
                    &evidence
                ) != 0) goto cleanup;
        }
        if (held_present
            && (!MoveFileExW(held, releasing, MOVEFILE_WRITE_THROUGH)
                || !FlushFileBuffers(volume))) goto cleanup;
        if ((held_present
                && wls_capacity_test_failpoint(L"begin") != 0)
            || (wls_capacity_detect_control_state(
                    releasing,
                    capacity_observation.identity.VolumeSerialNumber,
                    production_acl
                ) != WLS_CAPACITY_CONTROL_ABSENT
                && wls_capacity_prepare_release(
                    releasing,
                    target_bytes,
                    target_inodes,
                    capacity_observation.identity.VolumeSerialNumber,
                    production_acl,
                    volume,
                    &evidence
                ) != 0)
            || wls_capacity_platform_reserve_release(
                &platform_anchor,
                volume,
                1
            ) != 0
            || wls_capacity_test_failpoint(L"release") != 0
            || wls_capacity_finish_release_control(
                releasing,
                target_bytes,
                target_inodes,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl,
                volume,
                &evidence
            ) != 0) goto cleanup;
        wls_capacity_print_evidence("RELEASING", &evidence);
        result = 0;
        goto cleanup;
    }

    if (held_present) {
        (void)fprintf(
            stderr,
            "capacity reserve release transition was not started\n"
        );
        goto cleanup;
    }
    if (live_count == 0 && manifest_argument == NULL
        && wcscmp(reason, L"cancel") != 0) goto cleanup;
    if (allocating_present) {
        if (wcscmp(reason, L"cancel") != 0 || manifest_argument != NULL
            || wls_capacity_platform_reserve_cleanup_allocating(
                &platform_anchor,
                volume
            ) != 0
            || wls_capacity_remove_live(
                allocating,
                target_inodes,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl,
                volume
            ) != 0) goto cleanup;
    } else if (releasing_present) {
        if (manifest_argument == NULL
            || wls_capacity_platform_reserve_absent(
                &platform_anchor
            ) != 0
            || wls_capacity_validate_control_state(
                releasing,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl,
                WLS_CAPACITY_CONTROL_ABSENT
            ) != 0
            || wls_capacity_remove_live(
                releasing,
                target_inodes,
                capacity_observation.identity.VolumeSerialNumber,
                production_acl,
                volume
            ) != 0) goto cleanup;
    } else if (wls_capacity_platform_reserve_absent(
            &platform_anchor
        ) != 0) goto cleanup;
    (void)printf("{\"state\":\"RELEASED\"}\n");
    result = 0;
cleanup:
    wls_capacity_test_reset();
    if (capacity_handle != INVALID_HANDLE_VALUE) CloseHandle(capacity_handle);
    if (volume != INVALID_HANDLE_VALUE) CloseHandle(volume);
    SecureZeroMemory(home, sizeof(home));
    SecureZeroMemory(authority_home, sizeof(authority_home));
    SecureZeroMemory(temporary, sizeof(temporary));
    SecureZeroMemory(temporary_long, sizeof(temporary_long));
    SecureZeroMemory(capacity, sizeof(capacity));
    SecureZeroMemory(allocating_leaf, sizeof(allocating_leaf));
    SecureZeroMemory(held_leaf, sizeof(held_leaf));
    SecureZeroMemory(releasing_leaf, sizeof(releasing_leaf));
    SecureZeroMemory(allocating, sizeof(allocating));
    SecureZeroMemory(held, sizeof(held));
    SecureZeroMemory(releasing, sizeof(releasing));
    SecureZeroMemory(expected_manifest, sizeof(expected_manifest));
    SecureZeroMemory(&capacity_observation, sizeof(capacity_observation));
    SecureZeroMemory(&evidence, sizeof(evidence));
    SecureZeroMemory(&authority_identity, sizeof(authority_identity));
    SecureZeroMemory(&platform_anchor, sizeof(platform_anchor));
    return result;
}
