/*
 * WLS 2.0 Windows Recovery Guardian protocol and durable publication layer.
 *
 * Include this file after wls_gateway_guardian.inc.  Every persisted protocol
 * object is line-canonical, administrator-HMAC authenticated and read through
 * a no-follow handle which does not share delete or write access.  The public
 * reconcile entry intentionally leaves guardian-generation-head.lock held so
 * the Guardian can retain the authenticated decision through child creation.
 *
 * This file never reads an environment variable and never selects a Launcher
 * profile.  Production authority is the fixed ProgramData Weline Gateway
 * root resolved by wls_guardian_resolve_production_home().
 */

#ifndef WLS_GATEWAY_GUARDIAN_PROTOCOL_INC
#define WLS_GATEWAY_GUARDIAN_PROTOCOL_INC

#define WLS_GUARDIAN_LAUNCHER_MAX_BYTES 536870912ULL
#define WLS_GUARDIAN_PROTOCOL_DIRECTORY_BUFFER (64U * 1024U)
#define WLS_GUARDIAN_ATOMIC_TEMPORARY 1
#define WLS_GUARDIAN_ATOMIC_BACKUP 2

struct wls_guardian_transition_ack {
    char host_id[33];
    char nonce[33];
    char request_sha256[65];
    unsigned long long committed_head_sequence;
    char committed_head_sha256[65];
    char purpose[9];
    char active_generation_id[65];
    char signature[65];
    char raw_sha256[65];
};

struct wls_guardian_protocol_artifact {
    int present;
    int kind;
    wchar_t path[WLS_PATH_CHARS];
    wchar_t leaf[256];
    struct wls_guardian_pinned_file pin;
};

struct wls_guardian_protocol_atomic_inventory {
    wchar_t parent_path[WLS_PATH_CHARS];
    wchar_t target_leaf[256];
    struct wls_guardian_pinned_file parent;
    struct wls_guardian_protocol_artifact target;
    struct wls_guardian_protocol_artifact temporary;
    struct wls_guardian_protocol_artifact backup;
};

struct wls_guardian_protocol_lock {
    struct wls_guardian_pinned_file pin;
    OVERLAPPED overlap;
    wchar_t home[WLS_PATH_CHARS];
    int locked;
};

typedef int (*wls_guardian_protocol_document_validator)(
    const wchar_t *,
    const char *,
    size_t,
    void *
);

#ifdef WLS_NATIVE_TEST_HOOKS
static wchar_t wls_guardian_protocol_test_home[WLS_PATH_CHARS];

/* Test-only authority is compile-time gated and set explicitly by the test
 * process.  Production builds contain neither this storage nor this setter. */
static int wls_guardian_protocol_test_home_set(const wchar_t *home)
{
    wchar_t normalized[WLS_PATH_CHARS];
    DWORD length;
    if (home == NULL || home[0] == L'\0'
        || wls_guardian_protocol_test_home[0] != L'\0') return 1;
    ZeroMemory(normalized, sizeof(normalized));
    length = GetFullPathNameW(home, WLS_PATH_CHARS, normalized, NULL);
    if (length < 3U || length >= WLS_PATH_CHARS
        || normalized[1] != L':' || normalized[2] != L'\\'
        || wcschr(normalized + 2U, L':') != NULL
        || wcsncmp(normalized, L"\\\\", 2U) == 0) return 1;
    normalized[0] = (wchar_t)towupper(normalized[0]);
    if (wcslen(normalized) + 1U > WLS_PATH_CHARS) return 1;
    memcpy(
        wls_guardian_protocol_test_home,
        normalized,
        (wcslen(normalized) + 1U) * sizeof(wchar_t)
    );
    SecureZeroMemory(normalized, sizeof(normalized));
    return 0;
}
#endif

static int wls_guardian_protocol_home_valid(const wchar_t *home)
{
    wchar_t production[WLS_PATH_CHARS];
    int result = 0;
    ZeroMemory(production, sizeof(production));
    if (home == NULL || home[0] == L'\0') return 0;
    if (wls_guardian_resolve_production_home(production) == 0
        && wcscmp(home, production) == 0) result = 1;
#ifdef WLS_NATIVE_TEST_HOOKS
    if (!result && wls_guardian_protocol_test_home[0] != L'\0'
        && wcscmp(home, wls_guardian_protocol_test_home) == 0) result = 1;
#endif
    SecureZeroMemory(production, sizeof(production));
    return result;
}

static int wls_guardian_protocol_ascii_leaf(const wchar_t *leaf)
{
    size_t index;
    size_t length;
    if (leaf == NULL || (length = wcslen(leaf)) == 0U || length > 255U
        || leaf[length - 1U] == L'.' || leaf[length - 1U] == L' ')
        return 0;
    if (wcscmp(leaf, L".") == 0 || wcscmp(leaf, L"..") == 0) return 0;
    for (index = 0U; index < length; ++index) {
        wchar_t value = leaf[index];
        if (value < 0x21 || value > 0x7e
            || wcschr(L"<>:\"/\\|?*", value) != NULL) return 0;
    }
    return 1;
}

static int wls_guardian_protocol_path_parts(
    const wchar_t *path,
    wchar_t parent[WLS_PATH_CHARS],
    wchar_t leaf[256]
) {
    wchar_t normalized[WLS_PATH_CHARS];
    wchar_t *separator;
    DWORD length;
    int result = 1;
    ZeroMemory(normalized, sizeof(normalized));
    if (path == NULL || parent == NULL || leaf == NULL) return 1;
    parent[0] = L'\0';
    leaf[0] = L'\0';
    length = GetFullPathNameW(path, WLS_PATH_CHARS, normalized, NULL);
    if (length < 4U || length >= WLS_PATH_CHARS
        || normalized[1] != L':' || normalized[2] != L'\\'
        || wcschr(normalized + 2U, L':') != NULL
        || wcscmp(normalized, path) != 0) goto cleanup;
    normalized[0] = (wchar_t)towupper(normalized[0]);
    separator = wcsrchr(normalized, L'\\');
    if (separator == NULL || separator <= normalized + 2U
        || !wls_guardian_protocol_ascii_leaf(separator + 1U)
        || _wcsicmp(
            separator + 1U, L"guardian-transition.retirement"
        ) == 0) goto cleanup;
    if (wcslen(separator + 1U) + 1U > 256U) goto cleanup;
    memcpy(
        leaf,
        separator + 1U,
        (wcslen(separator + 1U) + 1U) * sizeof(wchar_t)
    );
    *separator = L'\0';
    if (wcslen(normalized) + 1U > WLS_PATH_CHARS) goto cleanup;
    memcpy(
        parent,
        normalized,
        (wcslen(normalized) + 1U) * sizeof(wchar_t)
    );
    result = 0;
cleanup:
    SecureZeroMemory(normalized, sizeof(normalized));
    if (result != 0) {
        SecureZeroMemory(parent, WLS_PATH_CHARS * sizeof(wchar_t));
        SecureZeroMemory(leaf, 256U * sizeof(wchar_t));
    }
    return result;
}

static int wls_guardian_protocol_hex_suffix(
    const wchar_t *suffix,
    size_t expected
) {
    size_t index;
    if (suffix == NULL || wcslen(suffix) != expected) return 0;
    for (index = 0U; index < expected; ++index) {
        if (!((suffix[index] >= L'0' && suffix[index] <= L'9')
            || (suffix[index] >= L'a' && suffix[index] <= L'f'))) return 0;
    }
    return 1;
}

static int wls_guardian_protocol_prefix_folded(
    const wchar_t *value,
    size_t value_length,
    const wchar_t *prefix
) {
    size_t index;
    size_t prefix_length;
    if (value == NULL || prefix == NULL) return 0;
    prefix_length = wcslen(prefix);
    if (value_length < prefix_length) return 0;
    for (index = 0U; index < prefix_length; ++index) {
        if (towlower(value[index]) != towlower(prefix[index])) return 0;
    }
    return 1;
}

static int wls_guardian_protocol_directory_identity_unchanged(
    const struct wls_guardian_pinned_file *directory
) {
    FILE_ATTRIBUTE_TAG_INFO attributes;
    BY_HANDLE_FILE_INFORMATION identity;
    FILE_ID_INFO file_id;
    if (directory == NULL || directory->handle == NULL
        || directory->handle == INVALID_HANDLE_VALUE) return 0;
    ZeroMemory(&attributes, sizeof(attributes));
    ZeroMemory(&identity, sizeof(identity));
    ZeroMemory(&file_id, sizeof(file_id));
    return GetFileInformationByHandleEx(
            directory->handle,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        && GetFileInformationByHandle(directory->handle, &identity)
        && GetFileInformationByHandleEx(
            directory->handle, FileIdInfo, &file_id, sizeof(file_id)
        )
        && (attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U
        && (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) == 0U
        && identity.dwVolumeSerialNumber
            == directory->identity.dwVolumeSerialNumber
        && identity.nFileIndexHigh == directory->identity.nFileIndexHigh
        && identity.nFileIndexLow == directory->identity.nFileIndexLow
        && file_id.VolumeSerialNumber == directory->file_id.VolumeSerialNumber
        && sodium_memcmp(
            file_id.FileId.Identifier,
            directory->file_id.FileId.Identifier,
            sizeof(file_id.FileId.Identifier)
        ) == 0;
}

static void wls_guardian_protocol_artifact_close(
    struct wls_guardian_protocol_artifact *artifact
) {
    if (artifact == NULL) return;
    wls_guardian_pinned_close(&artifact->pin);
    SecureZeroMemory(artifact, sizeof(*artifact));
    artifact->pin.handle = INVALID_HANDLE_VALUE;
}

static void wls_guardian_protocol_inventory_close(
    struct wls_guardian_protocol_atomic_inventory *inventory
) {
    if (inventory == NULL) return;
    wls_guardian_protocol_artifact_close(&inventory->target);
    wls_guardian_protocol_artifact_close(&inventory->temporary);
    wls_guardian_protocol_artifact_close(&inventory->backup);
    wls_guardian_pinned_close(&inventory->parent);
    SecureZeroMemory(inventory, sizeof(*inventory));
    inventory->parent.handle = INVALID_HANDLE_VALUE;
    inventory->target.pin.handle = INVALID_HANDLE_VALUE;
    inventory->temporary.pin.handle = INVALID_HANDLE_VALUE;
    inventory->backup.pin.handle = INVALID_HANDLE_VALUE;
}

static int wls_guardian_protocol_artifact_open(
    const wchar_t *parent,
    const wchar_t *leaf,
    int kind,
    size_t maximum,
    struct wls_guardian_protocol_artifact *artifact
) {
    LARGE_INTEGER size;
    int result = 1;
    if (parent == NULL || leaf == NULL || artifact == NULL
        || maximum == 0U) return 1;
    SecureZeroMemory(artifact, sizeof(*artifact));
    artifact->pin.handle = INVALID_HANDLE_VALUE;
    if (wls_join(artifact->path, WLS_PATH_CHARS, parent, leaf) != 0
        || wcslen(leaf) + 1U > 256U) goto cleanup;
    memcpy(
        artifact->leaf,
        leaf,
        (wcslen(leaf) + 1U) * sizeof(wchar_t)
    );
    if (wls_guardian_open_pinned(
            artifact->path,
            0,
            GENERIC_READ | DELETE,
            &artifact->pin
        ) != 0
        || wls_recovery_acl_safe(artifact->pin.handle, 0) != 0
        || !GetFileSizeEx(artifact->pin.handle, &size)
        || size.QuadPart < 0
        || (unsigned long long)size.QuadPart
            > (unsigned long long)maximum
        || (kind == WLS_GUARDIAN_ATOMIC_BACKUP
            && size.QuadPart == 0)) goto cleanup;
    artifact->kind = kind;
    artifact->present = 1;
    result = 0;
cleanup:
    if (result != 0) wls_guardian_protocol_artifact_close(artifact);
    return result;
}

static int wls_guardian_protocol_atomic_inventory_load(
    const wchar_t *path,
    size_t maximum,
    struct wls_guardian_protocol_atomic_inventory *inventory
) {
    wchar_t temporary_prefix[512];
    wchar_t backup_prefix[512];
    unsigned char buffer[WLS_GUARDIAN_PROTOCOL_DIRECTORY_BUFFER];
    PSID service_sid = NULL;
    int restart = 1;
    unsigned int visited = 0U;
    int result = 1;
    if (path == NULL || maximum == 0U || inventory == NULL) return 1;
    SecureZeroMemory(inventory, sizeof(*inventory));
    inventory->parent.handle = INVALID_HANDLE_VALUE;
    inventory->target.pin.handle = INVALID_HANDLE_VALUE;
    inventory->temporary.pin.handle = INVALID_HANDLE_VALUE;
    inventory->backup.pin.handle = INVALID_HANDLE_VALUE;
    ZeroMemory(temporary_prefix, sizeof(temporary_prefix));
    ZeroMemory(backup_prefix, sizeof(backup_prefix));
    ZeroMemory(buffer, sizeof(buffer));
    if (wls_guardian_protocol_path_parts(
            path, inventory->parent_path, inventory->target_leaf
        ) != 0
        || _snwprintf_s(
            temporary_prefix,
            sizeof(temporary_prefix) / sizeof(temporary_prefix[0]),
            _TRUNCATE,
            L"%ls.tmp-",
            inventory->target_leaf
        ) < 0
        || _snwprintf_s(
            backup_prefix,
            sizeof(backup_prefix) / sizeof(backup_prefix[0]),
            _TRUNCATE,
            L"%ls.wls-backup-",
            inventory->target_leaf
        ) < 0
        || wls_guardian_open_pinned(
            inventory->parent_path,
            1,
            GENERIC_READ | GENERIC_WRITE | FILE_LIST_DIRECTORY
                | FILE_TRAVERSE,
            &inventory->parent
        ) != 0
        || wls_launcher_gateway_service_sid(&service_sid) != 0
        || wls_launcher_slot_acl_valid(
            inventory->parent.handle, 1, service_sid
        ) != 0) goto cleanup;
    for (;;) {
        FILE_ID_BOTH_DIR_INFO *entry;
        size_t offset = 0U;
        ZeroMemory(buffer, sizeof(buffer));
        if (!GetFileInformationByHandleEx(
                inventory->parent.handle,
                restart
                    ? FileIdBothDirectoryRestartInfo
                    : FileIdBothDirectoryInfo,
                buffer,
                sizeof(buffer)
            )) {
            if (GetLastError() == ERROR_NO_MORE_FILES) break;
            goto cleanup;
        }
        restart = 0;
        entry = (FILE_ID_BOTH_DIR_INFO *)(void *)buffer;
        for (;;) {
            size_t minimum = FIELD_OFFSET(FILE_ID_BOTH_DIR_INFO, FileName);
            size_t characters;
            wchar_t leaf[256];
            struct wls_guardian_protocol_artifact *artifact = NULL;
            int kind = 0;
            size_t prefix_length = 0U;
            size_t suffix_length = 0U;
            ZeroMemory(leaf, sizeof(leaf));
            if (offset > sizeof(buffer) - minimum
                || entry->FileNameLength == 0U
                || entry->FileNameLength % sizeof(wchar_t) != 0U
                || (size_t)entry->FileNameLength
                    > sizeof(buffer) - offset - minimum) goto cleanup;
            characters = entry->FileNameLength / sizeof(wchar_t);
            if (characters > 255U) goto cleanup;
            memcpy(leaf, entry->FileName, entry->FileNameLength);
            leaf[characters] = L'\0';
            if (!((characters == 1U && leaf[0] == L'.')
                    || (characters == 2U
                        && leaf[0] == L'.' && leaf[1] == L'.'))) {
                if (++visited
                    > WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES) {
                    goto cleanup;
                }
                if (_wcsicmp(leaf, inventory->target_leaf) == 0) {
                    if (wcscmp(leaf, inventory->target_leaf) != 0
                        || inventory->target.present
                        || wls_guardian_protocol_artifact_open(
                            inventory->parent_path,
                            leaf,
                            0,
                            maximum,
                            &inventory->target
                        ) != 0) goto cleanup;
                } else if (wls_guardian_protocol_prefix_folded(
                        leaf, characters, temporary_prefix
                    )) {
                    artifact = &inventory->temporary;
                    kind = WLS_GUARDIAN_ATOMIC_TEMPORARY;
                    prefix_length = wcslen(temporary_prefix);
                    suffix_length = 24U;
                } else if (wls_guardian_protocol_prefix_folded(
                        leaf, characters, backup_prefix
                    )) {
                    artifact = &inventory->backup;
                    kind = WLS_GUARDIAN_ATOMIC_BACKUP;
                    prefix_length = wcslen(backup_prefix);
                    suffix_length = 16U;
                }
                if (artifact != NULL) {
                    const wchar_t *expected_prefix = kind
                        == WLS_GUARDIAN_ATOMIC_TEMPORARY
                            ? temporary_prefix : backup_prefix;
                    if (artifact->present
                        || wcsncmp(leaf, expected_prefix, prefix_length) != 0
                        || !wls_guardian_protocol_hex_suffix(
                            leaf + prefix_length, suffix_length
                        )
                        || wls_guardian_protocol_artifact_open(
                            inventory->parent_path,
                            leaf,
                            kind,
                            maximum,
                            artifact
                        ) != 0) goto cleanup;
                }
            }
            if (entry->NextEntryOffset == 0U) break;
            if ((size_t)entry->NextEntryOffset < minimum
                || (size_t)entry->NextEntryOffset
                    > sizeof(buffer) - offset) goto cleanup;
            offset += (size_t)entry->NextEntryOffset;
            entry = (FILE_ID_BOTH_DIR_INFO *)(void *)(buffer + offset);
        }
    }
    if (!wls_guardian_pinned_identity_unchanged(&inventory->parent, 1)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    SecureZeroMemory(buffer, sizeof(buffer));
    SecureZeroMemory(temporary_prefix, sizeof(temporary_prefix));
    SecureZeroMemory(backup_prefix, sizeof(backup_prefix));
    if (result != 0) wls_guardian_protocol_inventory_close(inventory);
    return result;
}

static int wls_guardian_protocol_pin_read(
    struct wls_guardian_pinned_file *pin,
    size_t maximum,
    char **contents,
    size_t *length
) {
    LARGE_INTEGER size;
    LARGE_INTEGER origin;
    DWORD used = 0U;
    size_t allocation = 0U;
    int result = 1;
    if (pin == NULL || contents == NULL || length == NULL
        || maximum == 0U || maximum > MAXDWORD) return 1;
    *contents = NULL;
    *length = 0U;
    origin.QuadPart = 0;
    if (!GetFileSizeEx(pin->handle, &size) || size.QuadPart <= 0
        || (unsigned long long)size.QuadPart > maximum
        || size.QuadPart > MAXDWORD
        || !SetFilePointerEx(pin->handle, origin, NULL, FILE_BEGIN)) {
        goto cleanup;
    }
    allocation = (size_t)size.QuadPart + 1U;
    *contents = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocation
    );
    if (*contents == NULL) goto cleanup;
    while (used < (DWORD)size.QuadPart) {
        DWORD amount = 0U;
        if (!ReadFile(
                pin->handle,
                *contents + used,
                (DWORD)size.QuadPart - used,
                &amount,
                NULL
            ) || amount == 0U) goto cleanup;
        used += amount;
    }
    if (memchr(*contents, '\0', used) != NULL
        || !wls_guardian_pinned_identity_unchanged(pin, 0)) goto cleanup;
    (*contents)[used] = '\0';
    *length = (size_t)used;
    result = 0;
cleanup:
    if (result != 0) {
        if (*contents != NULL) {
            SecureZeroMemory(*contents, allocation);
            HeapFree(GetProcessHeap(), 0U, *contents);
            *contents = NULL;
        }
        *length = 0U;
    }
    return result;
}

/* 0=valid read, 2=missing, 1=unsafe/error/retained companion. */
static int wls_guardian_protocol_document_read(
    const wchar_t *path,
    size_t maximum,
    char **contents,
    size_t *length
) {
    struct wls_guardian_protocol_atomic_inventory inventory;
    int result = 1;
    SecureZeroMemory(&inventory, sizeof(inventory));
    inventory.parent.handle = INVALID_HANDLE_VALUE;
    inventory.target.pin.handle = INVALID_HANDLE_VALUE;
    inventory.temporary.pin.handle = INVALID_HANDLE_VALUE;
    inventory.backup.pin.handle = INVALID_HANDLE_VALUE;
    if (contents == NULL || length == NULL) return 1;
    *contents = NULL;
    *length = 0U;
    if (wls_guardian_protocol_atomic_inventory_load(
            path, maximum, &inventory
        ) != 0
        || inventory.temporary.present || inventory.backup.present) {
        goto cleanup;
    }
    if (!inventory.target.present) {
        result = 2;
        goto cleanup;
    }
    if (wls_guardian_protocol_pin_read(
            &inventory.target.pin, maximum, contents, length
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_protocol_inventory_close(&inventory);
    return result;
}

static int wls_guardian_protocol_flush_directory(
    struct wls_guardian_pinned_file *directory
) {
    typedef NTSTATUS (NTAPI *wls_guardian_nt_flush_buffers_file_fn)(
        HANDLE,
        PIO_STATUS_BLOCK
    );
    HMODULE ntdll;
    wls_guardian_nt_flush_buffers_file_fn native_flush = NULL;
    IO_STATUS_BLOCK status;
    int flushed = 0;
    ZeroMemory(&status, sizeof(status));
    if (directory == NULL
        || !wls_guardian_protocol_directory_identity_unchanged(directory)) {
        return 1;
    }
    if (FlushFileBuffers(directory->handle)) {
        flushed = 1;
    } else {
        ntdll = GetModuleHandleW(L"ntdll.dll");
        if (ntdll != NULL) {
            native_flush = (wls_guardian_nt_flush_buffers_file_fn)(void *)
                GetProcAddress(ntdll, "NtFlushBuffersFile");
        }
        if (native_flush != NULL
            && native_flush(directory->handle, &status) >= 0) flushed = 1;
    }
    return flushed
        && wls_guardian_protocol_directory_identity_unchanged(directory)
        ? 0 : 1;
}

static int wls_guardian_protocol_artifact_remove(
    struct wls_guardian_protocol_atomic_inventory *inventory,
    struct wls_guardian_protocol_artifact *artifact
) {
    FILE_DISPOSITION_INFO disposition;
    if (inventory == NULL || artifact == NULL || !artifact->present
        || !wls_guardian_pinned_identity_unchanged(&artifact->pin, 0)
        || !wls_guardian_protocol_directory_identity_unchanged(
            &inventory->parent
        )) return 1;
    ZeroMemory(&disposition, sizeof(disposition));
    disposition.DeleteFile = TRUE;
    if (!SetFileInformationByHandle(
            artifact->pin.handle,
            FileDispositionInfo,
            &disposition,
            sizeof(disposition)
        )) return 1;
    wls_guardian_protocol_artifact_close(artifact);
    return wls_guardian_protocol_flush_directory(&inventory->parent);
}

static int wls_guardian_protocol_artifact_promote(
    struct wls_guardian_protocol_atomic_inventory *inventory,
    struct wls_guardian_protocol_artifact *artifact
) {
    FILE_RENAME_INFO *rename = NULL;
    size_t leaf_bytes;
    size_t allocation;
    int result = 1;
    if (inventory == NULL || artifact == NULL || !artifact->present
        || inventory->target.present
        || !wls_guardian_pinned_identity_unchanged(&artifact->pin, 0)
        || !wls_guardian_protocol_directory_identity_unchanged(
            &inventory->parent
        )) return 1;
    leaf_bytes = wcslen(inventory->target_leaf) * sizeof(wchar_t);
    if (leaf_bytes == 0U || leaf_bytes > MAXDWORD
        || leaf_bytes > SIZE_MAX - FIELD_OFFSET(FILE_RENAME_INFO, FileName)) {
        return 1;
    }
    allocation = FIELD_OFFSET(FILE_RENAME_INFO, FileName) + leaf_bytes;
    rename = (FILE_RENAME_INFO *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocation
    );
    if (rename == NULL) return 1;
    rename->ReplaceIfExists = FALSE;
    rename->RootDirectory = inventory->parent.handle;
    rename->FileNameLength = (DWORD)leaf_bytes;
    memcpy(rename->FileName, inventory->target_leaf, leaf_bytes);
    if (SetFileInformationByHandle(
            artifact->pin.handle,
            FileRenameInfo,
            rename,
            (DWORD)allocation
        )
        && wls_guardian_protocol_flush_directory(&inventory->parent) == 0) {
        result = 0;
    }
    SecureZeroMemory(rename, allocation);
    HeapFree(GetProcessHeap(), 0U, rename);
    return result;
}

static int wls_guardian_protocol_random_hex(
    wchar_t *output,
    size_t characters
) {
    unsigned char bytes[12];
    char encoded[25];
    size_t index;
    int result = 1;
    SecureZeroMemory(bytes, sizeof(bytes));
    SecureZeroMemory(encoded, sizeof(encoded));
    if (output == NULL || (characters != 16U && characters != 24U)) {
        return 1;
    }
    randombytes_buf(bytes, characters / 2U);
    if (sodium_bin2hex(
            encoded,
            sizeof(encoded),
            bytes,
            characters / 2U
        ) == NULL) goto cleanup;
    for (index = 0U; index < characters; ++index) {
        output[index] = (wchar_t)encoded[index];
    }
    output[characters] = L'\0';
    result = 0;
cleanup:
    sodium_memzero(bytes, sizeof(bytes));
    sodium_memzero(encoded, sizeof(encoded));
    if (result != 0) SecureZeroMemory(
        output, (characters + 1U) * sizeof(wchar_t)
    );
    return result;
}

static int wls_guardian_protocol_root_file_create(
    const wchar_t *path,
    const char *contents,
    struct wls_guardian_pinned_file *pin
) {
    PSECURITY_DESCRIPTOR descriptor = NULL;
    SECURITY_ATTRIBUTES security;
    DWORD length;
    int result = 1;
    ZeroMemory(&security, sizeof(security));
    if (path == NULL || contents == NULL || pin == NULL
        || strlen(contents) == 0U || strlen(contents) > MAXDWORD
        || !ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"O:SYG:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)",
            SDDL_REVISION_1,
            &descriptor,
            NULL
        )) return 1;
    SecureZeroMemory(pin, sizeof(*pin));
    pin->handle = INVALID_HANDLE_VALUE;
    security.nLength = sizeof(security);
    security.lpSecurityDescriptor = descriptor;
    length = (DWORD)strlen(contents);
    pin->handle = CreateFileW(
        path,
        GENERIC_READ | GENERIC_WRITE | DELETE | READ_CONTROL,
        FILE_SHARE_READ,
        &security,
        CREATE_NEW,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH
            | FILE_FLAG_OPEN_REPARSE_POINT,
        NULL
    );
    if (pin->handle == INVALID_HANDLE_VALUE
        || wls_write_all(
            pin->handle, (const unsigned char *)contents, length
        ) != 0
        || !FlushFileBuffers(pin->handle)) goto cleanup;
    /* Capture the identity through the ordinary no-follow verifier after the
     * write handle has durably completed. */
    CloseHandle(pin->handle);
    pin->handle = INVALID_HANDLE_VALUE;
    if (wls_guardian_open_pinned(
            path, 0, GENERIC_READ | DELETE, pin
        ) != 0
        || wls_recovery_acl_safe(pin->handle, 0) != 0) goto cleanup;
    result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    if (result != 0) {
        if (pin->handle != INVALID_HANDLE_VALUE) CloseHandle(pin->handle);
        pin->handle = INVALID_HANDLE_VALUE;
    }
    return result;
}

/* Crash-safe publication using PHP's canonical companion names.  Existing
 * records use ReplaceFileW and retain the exact predecessor as a bounded
 * backup until the new target has been re-opened, authenticated by the caller
 * and its native identity has been checked. */
static int wls_guardian_protocol_atomic_text(
    const wchar_t *path,
    const char *contents
) {
    struct wls_guardian_protocol_atomic_inventory before;
    struct wls_guardian_protocol_atomic_inventory after;
    struct wls_guardian_pinned_file staging;
    struct wls_guardian_pinned_file old_target;
    FILE_ID_INFO staged_id;
    FILE_ID_INFO target_id;
    BY_HANDLE_FILE_INFORMATION staged_identity;
    BY_HANDLE_FILE_INFORMATION target_identity;
    wchar_t temporary[WLS_PATH_CHARS];
    wchar_t backup[WLS_PATH_CHARS];
    wchar_t temporary_hex[25];
    wchar_t backup_hex[17];
    char *published = NULL;
    size_t published_length = 0U;
    size_t content_length;
    int had_target = 0;
    int result = 1;
    SecureZeroMemory(&before, sizeof(before));
    SecureZeroMemory(&after, sizeof(after));
    SecureZeroMemory(&staging, sizeof(staging));
    SecureZeroMemory(&old_target, sizeof(old_target));
    SecureZeroMemory(&staged_id, sizeof(staged_id));
    SecureZeroMemory(&target_id, sizeof(target_id));
    SecureZeroMemory(&staged_identity, sizeof(staged_identity));
    SecureZeroMemory(&target_identity, sizeof(target_identity));
    ZeroMemory(temporary, sizeof(temporary));
    ZeroMemory(backup, sizeof(backup));
    ZeroMemory(temporary_hex, sizeof(temporary_hex));
    ZeroMemory(backup_hex, sizeof(backup_hex));
    before.parent.handle = INVALID_HANDLE_VALUE;
    before.target.pin.handle = INVALID_HANDLE_VALUE;
    before.temporary.pin.handle = INVALID_HANDLE_VALUE;
    before.backup.pin.handle = INVALID_HANDLE_VALUE;
    after.parent.handle = INVALID_HANDLE_VALUE;
    after.target.pin.handle = INVALID_HANDLE_VALUE;
    after.temporary.pin.handle = INVALID_HANDLE_VALUE;
    after.backup.pin.handle = INVALID_HANDLE_VALUE;
    staging.handle = INVALID_HANDLE_VALUE;
    old_target.handle = INVALID_HANDLE_VALUE;
    if (path == NULL || contents == NULL
        || (content_length = strlen(contents)) == 0U
        || content_length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES
        || wls_guardian_protocol_atomic_inventory_load(
            path, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &before
        ) != 0
        || before.temporary.present || before.backup.present
        || wls_guardian_protocol_random_hex(temporary_hex, 24U) != 0
        || wls_guardian_protocol_random_hex(backup_hex, 16U) != 0
        || _snwprintf_s(
            temporary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls.tmp-%ls",
            path,
            temporary_hex
        ) < 0
        || _snwprintf_s(
            backup,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls.wls-backup-%ls",
            path,
            backup_hex
        ) < 0
        || wls_guardian_protocol_root_file_create(
            temporary, contents, &staging
        ) != 0
        || !wls_guardian_protocol_directory_identity_unchanged(
            &before.parent
        )) goto cleanup;
    staged_id = staging.file_id;
    staged_identity = staging.identity;
    had_target = before.target.present;
    if (!before.target.present) {
        struct wls_guardian_protocol_artifact promoted;
        SecureZeroMemory(&promoted, sizeof(promoted));
        promoted.present = 1;
        promoted.kind = WLS_GUARDIAN_ATOMIC_TEMPORARY;
        promoted.pin = staging;
        memcpy(promoted.path, temporary, sizeof(promoted.path));
        memcpy(
            promoted.leaf,
            wcsrchr(temporary, L'\\') + 1U,
            (wcslen(wcsrchr(temporary, L'\\') + 1U) + 1U)
                * sizeof(wchar_t)
        );
        staging.handle = INVALID_HANDLE_VALUE;
        if (wls_guardian_protocol_artifact_promote(
                &before, &promoted
            ) != 0) {
            wls_guardian_protocol_artifact_close(&promoted);
            goto cleanup;
        }
        wls_guardian_protocol_artifact_close(&promoted);
    } else {
        old_target = before.target.pin;
        before.target.pin.handle = INVALID_HANDLE_VALUE;
        target_id = old_target.file_id;
        target_identity = old_target.identity;
        if (!wls_guardian_pinned_identity_unchanged(&staging, 0)
            || !wls_guardian_pinned_identity_unchanged(&old_target, 0)) {
            goto cleanup;
        }
        /* ReplaceFileW is name based.  Only LocalSystem/Administrators can
         * mutate this sealed directory; close the two no-share-delete pins at
         * the last possible point, execute the replacement, then prove both
         * resulting native identities before any backup cleanup. */
        wls_guardian_pinned_close(&staging);
        wls_guardian_pinned_close(&old_target);
        if (!ReplaceFileW(
                path,
                temporary,
                backup,
                REPLACEFILE_WRITE_THROUGH,
                NULL,
                NULL
            )
            || wls_guardian_protocol_flush_directory(&before.parent) != 0) {
            goto cleanup;
        }
    }
    wls_guardian_protocol_inventory_close(&before);
    if (wls_guardian_protocol_atomic_inventory_load(
            path, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &after
        ) != 0
        || !after.target.present || after.temporary.present
        || after.target.pin.file_id.VolumeSerialNumber
            != staged_id.VolumeSerialNumber
        || sodium_memcmp(
            after.target.pin.file_id.FileId.Identifier,
            staged_id.FileId.Identifier,
            sizeof(staged_id.FileId.Identifier)
        ) != 0
        || after.target.pin.identity.nFileIndexHigh
            != staged_identity.nFileIndexHigh
        || after.target.pin.identity.nFileIndexLow
            != staged_identity.nFileIndexLow
        || wls_guardian_protocol_pin_read(
            &after.target.pin,
            WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
            &published,
            &published_length
        ) != 0
        || published_length != content_length
        || sodium_memcmp(published, contents, content_length) != 0) {
        goto cleanup;
    }
    if (after.backup.present) {
        if (!had_target
            || after.backup.pin.file_id.VolumeSerialNumber
                != target_id.VolumeSerialNumber
            || sodium_memcmp(
                after.backup.pin.file_id.FileId.Identifier,
                target_id.FileId.Identifier,
                sizeof(target_id.FileId.Identifier)
            ) != 0
            || after.backup.pin.identity.nFileIndexHigh
                != target_identity.nFileIndexHigh
            || after.backup.pin.identity.nFileIndexLow
                != target_identity.nFileIndexLow
            || wls_guardian_protocol_artifact_remove(
                &after, &after.backup
            ) != 0) goto cleanup;
    } else if (had_target) {
        /* Replacing an existing record must create auditable predecessor
         * evidence until the after-image has been proven. */
        goto cleanup;
    }
    result = 0;
cleanup:
    if (published != NULL) {
        SecureZeroMemory(published, published_length + 1U);
        HeapFree(GetProcessHeap(), 0U, published);
    }
    wls_guardian_pinned_close(&staging);
    wls_guardian_pinned_close(&old_target);
    wls_guardian_protocol_inventory_close(&before);
    wls_guardian_protocol_inventory_close(&after);
    SecureZeroMemory(&staged_id, sizeof(staged_id));
    SecureZeroMemory(&target_id, sizeof(target_id));
    SecureZeroMemory(&staged_identity, sizeof(staged_identity));
    SecureZeroMemory(&target_identity, sizeof(target_identity));
    SecureZeroMemory(temporary, sizeof(temporary));
    SecureZeroMemory(backup, sizeof(backup));
    SecureZeroMemory(temporary_hex, sizeof(temporary_hex));
    SecureZeroMemory(backup_hex, sizeof(backup_hex));
    return result;
}

static int wls_guardian_protocol_line(
    const char *contents,
    size_t length,
    size_t *offset,
    const char *prefix,
    char *value,
    size_t capacity
) {
    size_t prefix_length;
    size_t start;
    size_t end;
    if (contents == NULL || offset == NULL || prefix == NULL || value == NULL
        || capacity < 2U || *offset > length) return 1;
    prefix_length = strlen(prefix);
    if (prefix_length > length - *offset
        || memcmp(contents + *offset, prefix, prefix_length) != 0) return 1;
    start = *offset + prefix_length;
    end = start;
    while (end < length && contents[end] != '\n') {
        unsigned char character = (unsigned char)contents[end];
        if (character == '\r' || character == '\0'
            || character < 0x20U || character > 0x7eU) return 1;
        ++end;
    }
    if (end >= length || end == start || end - start >= capacity) return 1;
    memcpy(value, contents + start, end - start);
    value[end - start] = '\0';
    *offset = end + 1U;
    return 0;
}

static int wls_guardian_protocol_header(
    const char *contents,
    size_t length,
    size_t *offset,
    const char *header
) {
    size_t header_length;
    if (contents == NULL || offset == NULL || header == NULL) return 1;
    header_length = strlen(header);
    if (header_length > length || memcmp(contents, header, header_length) != 0) {
        return 1;
    }
    *offset = header_length;
    return 0;
}

static int wls_guardian_protocol_decimal(
    const char *value,
    unsigned long long *parsed
) {
    size_t index;
    if (value == NULL || parsed == NULL || value[0] == '\0'
        || (value[0] == '0' && value[1] != '\0')) return 1;
    for (index = 0U; value[index] != '\0'; ++index) {
        if (value[index] < '0' || value[index] > '9') return 1;
    }
    return wls_guardian_u64(value, parsed) != 0 ? 1 : 0;
}

static int wls_guardian_protocol_signature_offset(
    const char *contents,
    size_t length,
    size_t offset,
    const char *signature
) {
    return contents != NULL && offset == length && signature != NULL
        && wls_is_hex(signature, 64U) ? 0 : 1;
}

static int wls_guardian_protocol_lock_valid(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock
) {
    return home != NULL && lock != NULL && lock->locked
        && wcscmp(home, lock->home) == 0
        && wls_guardian_pinned_identity_unchanged(&lock->pin, 0);
}

static int wls_guardian_protocol_trust_path(
    const wchar_t *home,
    const wchar_t *leaf,
    wchar_t path[WLS_PATH_CHARS]
) {
    wchar_t trust[WLS_PATH_CHARS];
    int result = 1;
    ZeroMemory(trust, sizeof(trust));
    if (wls_guardian_protocol_home_valid(home)
        && leaf != NULL && wls_guardian_protocol_ascii_leaf(leaf)
        && _wcsicmp(leaf, L"guardian-transition.retirement") != 0
        && wls_join(trust, WLS_PATH_CHARS, home, L"trust") == 0
        && wls_join(path, WLS_PATH_CHARS, trust, leaf) == 0) result = 0;
    SecureZeroMemory(trust, sizeof(trust));
    return result;
}

static int wls_guardian_request_parse(
    const wchar_t *home,
    const char *contents,
    size_t length,
    struct wls_guardian_transition_request *request
) {
    char sequence[21];
    char candidate_size[21];
    char candidate_mode[21];
    char recovery_size[21];
    char recovery_mode[21];
    char trust_rotation[2];
    char candidate_id[65];
    char recovery_id[65];
    char binding[3072];
    char binding_digest[65];
    unsigned long long parsed_sequence = 0ULL;
    unsigned long long parsed_candidate_size = 0ULL;
    unsigned long long parsed_candidate_mode = 0ULL;
    unsigned long long parsed_recovery_size = 0ULL;
    unsigned long long parsed_recovery_mode = 0ULL;
    size_t offset = 0U;
    size_t signature_offset = 0U;
    int binding_length;
    int result = 1;
    if (!wls_guardian_protocol_home_valid(home) || contents == NULL
        || request == NULL || length == 0U
        || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES) return 1;
    SecureZeroMemory(request, sizeof(*request));
    SecureZeroMemory(sequence, sizeof(sequence));
    SecureZeroMemory(candidate_size, sizeof(candidate_size));
    SecureZeroMemory(candidate_mode, sizeof(candidate_mode));
    SecureZeroMemory(recovery_size, sizeof(recovery_size));
    SecureZeroMemory(recovery_mode, sizeof(recovery_mode));
    SecureZeroMemory(trust_rotation, sizeof(trust_rotation));
    SecureZeroMemory(candidate_id, sizeof(candidate_id));
    SecureZeroMemory(recovery_id, sizeof(recovery_id));
    SecureZeroMemory(binding, sizeof(binding));
    SecureZeroMemory(binding_digest, sizeof(binding_digest));
#define WLS_GUARDIAN_REQUEST_LINE(prefix, field) \
    if (wls_guardian_protocol_line( \
            contents, length, &offset, prefix, field, sizeof(field) \
        ) != 0) goto cleanup
    if (wls_guardian_protocol_header(
            contents,
            length,
            &offset,
            "WLS-GUARDIAN-TRANSITION-REQUEST/1\n"
        ) != 0) goto cleanup;
    WLS_GUARDIAN_REQUEST_LINE("host_id=", request->host_id);
    WLS_GUARDIAN_REQUEST_LINE("nonce=", request->nonce);
    WLS_GUARDIAN_REQUEST_LINE("expected_head_sequence=", sequence);
    WLS_GUARDIAN_REQUEST_LINE(
        "expected_head_sha256=", request->expected_head_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE("journal_sha256=", request->journal_sha256);
    WLS_GUARDIAN_REQUEST_LINE(
        "candidate_generation_id=", request->candidate_generation_id
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "candidate_launcher_sha256=", request->candidate_launcher_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE("candidate_launcher_size=", candidate_size);
    WLS_GUARDIAN_REQUEST_LINE("candidate_launcher_mode=", candidate_mode);
    WLS_GUARDIAN_REQUEST_LINE(
        "candidate_ca_sha256=", request->candidate_ca_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "candidate_runtime_generation=",
        request->candidate_runtime_generation
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_generation_id=", request->recovery_generation_id
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_launcher_sha256=", request->recovery_launcher_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE("recovery_launcher_size=", recovery_size);
    WLS_GUARDIAN_REQUEST_LINE("recovery_launcher_mode=", recovery_mode);
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_ca_sha256=", request->recovery_ca_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_runtime_generation=",
        request->recovery_runtime_generation
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_active_slot=", request->recovery_active_slot
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_previous_slot=", request->recovery_previous_slot
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_slot_a_generation=",
        request->recovery_slot_a_generation
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_slot_b_generation=",
        request->recovery_slot_b_generation
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "derived_manifest_sha256=", request->derived_manifest_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "derived_policy_sha256=", request->derived_policy_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE("platform_kind=", request->platform_kind);
    WLS_GUARDIAN_REQUEST_LINE("platform_profile=", request->platform_profile);
    WLS_GUARDIAN_REQUEST_LINE(
        "platform_definition_sha256=",
        request->platform_definition_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "platform_metadata_sha256=", request->platform_metadata_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE("trust_rotation=", trust_rotation);
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_inventory_sha256=", request->recovery_inventory_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "request_binding_sha256=", request->request_binding_sha256
    );
    WLS_GUARDIAN_REQUEST_LINE(
        "recovery_authorization_sha256=",
        request->recovery_authorization_sha256
    );
    signature_offset = offset;
    WLS_GUARDIAN_REQUEST_LINE("signature=", request->signature);
#undef WLS_GUARDIAN_REQUEST_LINE
    if (wls_guardian_protocol_signature_offset(
            contents, length, offset, request->signature
        ) != 0
        || signature_offset == 0U
        || !wls_is_hex(request->host_id, 32U)
        || !wls_is_hex(request->nonce, 32U)
        || !wls_is_hex(request->expected_head_sha256, 64U)
        || !wls_is_hex(request->journal_sha256, 64U)
        || !wls_is_hex(request->candidate_generation_id, 64U)
        || !wls_is_hex(request->candidate_launcher_sha256, 64U)
        || !wls_is_hex(request->candidate_ca_sha256, 64U)
        || !wls_is_hex(request->candidate_runtime_generation, 64U)
        || !wls_is_hex(request->recovery_generation_id, 64U)
        || !wls_is_hex(request->recovery_launcher_sha256, 64U)
        || !wls_is_hex(request->recovery_ca_sha256, 64U)
        || !wls_is_hex(request->recovery_runtime_generation, 64U)
        || !wls_is_hex(request->recovery_slot_a_generation, 64U)
        || !wls_is_hex(request->recovery_slot_b_generation, 64U)
        || !wls_is_hex(request->derived_manifest_sha256, 64U)
        || !wls_is_hex(request->derived_policy_sha256, 64U)
        || !wls_is_hex(request->platform_definition_sha256, 64U)
        || !wls_is_hex(request->platform_metadata_sha256, 64U)
        || !wls_is_hex(request->recovery_inventory_sha256, 64U)
        || !wls_is_hex(request->request_binding_sha256, 64U)
        || !wls_is_hex(request->recovery_authorization_sha256, 64U)
        || wls_guardian_protocol_decimal(sequence, &parsed_sequence) != 0
        || wls_guardian_protocol_decimal(
            candidate_size, &parsed_candidate_size
        ) != 0
        || wls_guardian_protocol_decimal(
            candidate_mode, &parsed_candidate_mode
        ) != 0
        || wls_guardian_protocol_decimal(
            recovery_size, &parsed_recovery_size
        ) != 0
        || wls_guardian_protocol_decimal(
            recovery_mode, &parsed_recovery_mode
        ) != 0
        || parsed_sequence == 0ULL
        || parsed_sequence > (unsigned long long)LLONG_MAX
        || parsed_candidate_size == 0ULL
        || parsed_candidate_size > WLS_GUARDIAN_LAUNCHER_MAX_BYTES
        || parsed_recovery_size == 0ULL
        || parsed_recovery_size > WLS_GUARDIAN_LAUNCHER_MAX_BYTES
        || parsed_candidate_mode == 0ULL || parsed_candidate_mode > 0777ULL
        || parsed_recovery_mode == 0ULL || parsed_recovery_mode > 0777ULL
        || (strcmp(request->recovery_active_slot, "A") != 0
            && strcmp(request->recovery_active_slot, "B") != 0)
        || (strcmp(request->recovery_previous_slot, "NONE") != 0
            && strcmp(request->recovery_previous_slot, "A") != 0
            && strcmp(request->recovery_previous_slot, "B") != 0)
        || strcmp(
            request->recovery_active_slot,
            request->recovery_previous_slot
        ) == 0
        || (strcmp(request->platform_profile, "default") != 0
            && strcmp(request->platform_profile, "ipv4-only") != 0)
        || (strcmp(request->platform_kind, "windows-service") != 0
#ifdef WLS_NATIVE_TEST_HOOKS
            && strcmp(request->platform_kind, "test-session") != 0
#endif
        )
        || (strcmp(trust_rotation, "0") != 0
            && strcmp(trust_rotation, "1") != 0)
        || wls_guardian_generation_id(
            request->candidate_launcher_sha256,
            request->candidate_ca_sha256,
            request->candidate_runtime_generation,
            candidate_id
        ) != 0
        || !wls_guardian_constant_equal(
            candidate_id, request->candidate_generation_id, 64U
        )
        || wls_guardian_generation_id(
            request->recovery_launcher_sha256,
            request->recovery_ca_sha256,
            request->recovery_runtime_generation,
            recovery_id
        ) != 0
        || !wls_guardian_constant_equal(
            recovery_id, request->recovery_generation_id, 64U
        )
        || sodium_memcmp(
            request->recovery_runtime_generation,
            request->recovery_active_slot[0] == 'A'
                ? request->recovery_slot_a_generation
                : request->recovery_slot_b_generation,
            64U
        ) != 0) goto cleanup;
    request->expected_head_sequence = parsed_sequence;
    request->candidate_launcher_size = parsed_candidate_size;
    request->candidate_launcher_mode = parsed_candidate_mode;
    request->recovery_launcher_size = parsed_recovery_size;
    request->recovery_launcher_mode = parsed_recovery_mode;
    request->trust_rotation = trust_rotation[0] == '1' ? 1 : 0;
    binding_length = _snprintf_s(
        binding,
        sizeof(binding),
        _TRUNCATE,
        "WLS-GUARDIAN-REQUEST-BINDING/1\n"
        "host_id=%s\n"
        "nonce=%s\n"
        "expected_head_sequence=%llu\n"
        "expected_head_sha256=%s\n"
        "journal_sha256=%s\n"
        "candidate_generation_id=%s\n"
        "candidate_launcher_sha256=%s\n"
        "candidate_launcher_size=%llu\n"
        "candidate_launcher_mode=%llu\n"
        "candidate_ca_sha256=%s\n"
        "candidate_runtime_generation=%s\n"
        "recovery_generation_id=%s\n"
        "recovery_launcher_sha256=%s\n"
        "recovery_launcher_size=%llu\n"
        "recovery_launcher_mode=%llu\n"
        "recovery_ca_sha256=%s\n"
        "recovery_runtime_generation=%s\n"
        "recovery_active_slot=%s\n"
        "recovery_previous_slot=%s\n"
        "recovery_slot_a_generation=%s\n"
        "recovery_slot_b_generation=%s\n"
        "derived_manifest_sha256=%s\n"
        "derived_policy_sha256=%s\n"
        "platform_kind=%s\n"
        "platform_profile=%s\n"
        "platform_definition_sha256=%s\n"
        "platform_metadata_sha256=%s\n"
        "trust_rotation=%d\n"
        "recovery_inventory_sha256=%s\n",
        request->host_id,
        request->nonce,
        request->expected_head_sequence,
        request->expected_head_sha256,
        request->journal_sha256,
        request->candidate_generation_id,
        request->candidate_launcher_sha256,
        request->candidate_launcher_size,
        request->candidate_launcher_mode,
        request->candidate_ca_sha256,
        request->candidate_runtime_generation,
        request->recovery_generation_id,
        request->recovery_launcher_sha256,
        request->recovery_launcher_size,
        request->recovery_launcher_mode,
        request->recovery_ca_sha256,
        request->recovery_runtime_generation,
        request->recovery_active_slot,
        request->recovery_previous_slot,
        request->recovery_slot_a_generation,
        request->recovery_slot_b_generation,
        request->derived_manifest_sha256,
        request->derived_policy_sha256,
        request->platform_kind,
        request->platform_profile,
        request->platform_definition_sha256,
        request->platform_metadata_sha256,
        request->trust_rotation,
        request->recovery_inventory_sha256
    );
    if (binding_length <= 0 || binding_length >= (int)sizeof(binding)
        || wls_guardian_sha256(
            (const unsigned char *)binding,
            (size_t)binding_length,
            binding_digest
        ) != 0
        || !wls_guardian_constant_equal(
            binding_digest, request->request_binding_sha256, 64U
        )
        || wls_guardian_signed_document_valid(
            home, contents, length, request->signature
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)contents, length, request->raw_sha256
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(sequence, sizeof(sequence));
    SecureZeroMemory(candidate_size, sizeof(candidate_size));
    SecureZeroMemory(candidate_mode, sizeof(candidate_mode));
    SecureZeroMemory(recovery_size, sizeof(recovery_size));
    SecureZeroMemory(recovery_mode, sizeof(recovery_mode));
    SecureZeroMemory(trust_rotation, sizeof(trust_rotation));
    SecureZeroMemory(candidate_id, sizeof(candidate_id));
    SecureZeroMemory(recovery_id, sizeof(recovery_id));
    SecureZeroMemory(binding, sizeof(binding));
    SecureZeroMemory(binding_digest, sizeof(binding_digest));
    if (result != 0) SecureZeroMemory(request, sizeof(*request));
    return result;
}

/* 0=present/valid, 2=missing, 1=unsafe/error. */
static int wls_guardian_request_read(
    const wchar_t *home,
    struct wls_guardian_transition_request *request
) {
    wchar_t path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    int state;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    if (request == NULL || wls_guardian_protocol_trust_path(
            home, L"guardian-transition.request", path
        ) != 0) return 1;
    state = wls_guardian_protocol_document_read(
        path, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &contents, &length
    );
    if (state == 2) {
        result = 2;
    } else if (state == 0
        && wls_guardian_request_parse(
            home, contents, length, request
        ) == 0) {
        result = 0;
    }
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_guardian_head_parse(
    const wchar_t *home,
    const char *contents,
    size_t length,
    int slot,
    struct wls_guardian_generation_head *head
) {
    char sequence[21];
    char probation_start[21];
    char probation_deadline[21];
    char generation_id[65];
    unsigned long long parsed_sequence = 0ULL;
    unsigned long long parsed_start = 0ULL;
    unsigned long long parsed_deadline = 0ULL;
    size_t offset = 0U;
    int result = 1;
    if (!wls_guardian_protocol_home_valid(home) || contents == NULL
        || head == NULL || (slot != 0 && slot != 1)
        || length == 0U || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES) {
        return 1;
    }
    SecureZeroMemory(head, sizeof(*head));
    SecureZeroMemory(sequence, sizeof(sequence));
    SecureZeroMemory(probation_start, sizeof(probation_start));
    SecureZeroMemory(probation_deadline, sizeof(probation_deadline));
    SecureZeroMemory(generation_id, sizeof(generation_id));
#define WLS_GUARDIAN_HEAD_LINE(prefix, field) \
    if (wls_guardian_protocol_line( \
            contents, length, &offset, prefix, field, sizeof(field) \
        ) != 0) goto cleanup
    if (wls_guardian_protocol_header(
            contents,
            length,
            &offset,
            "WLS-GUARDIAN-GENERATION-HEAD/1\n"
        ) != 0) goto cleanup;
    WLS_GUARDIAN_HEAD_LINE("host_id=", head->host_id);
    WLS_GUARDIAN_HEAD_LINE("sequence=", sequence);
    WLS_GUARDIAN_HEAD_LINE("phase=", head->phase);
    WLS_GUARDIAN_HEAD_LINE(
        "active_generation_id=", head->active_generation_id
    );
    WLS_GUARDIAN_HEAD_LINE(
        "active_launcher_sha256=", head->active_launcher_sha256
    );
    WLS_GUARDIAN_HEAD_LINE("active_ca_sha256=", head->active_ca_sha256);
    WLS_GUARDIAN_HEAD_LINE(
        "active_runtime_generation=", head->active_runtime_generation
    );
    WLS_GUARDIAN_HEAD_LINE(
        "recovery_generation_id=", head->recovery_generation_id
    );
    WLS_GUARDIAN_HEAD_LINE("recovery_nonce=", head->recovery_nonce);
    WLS_GUARDIAN_HEAD_LINE(
        "recovery_authorization_sha256=",
        head->recovery_authorization_sha256
    );
    WLS_GUARDIAN_HEAD_LINE("host_boot_id=", head->host_boot_id);
    WLS_GUARDIAN_HEAD_LINE(
        "probation_started_monotonic_ms=", probation_start
    );
    WLS_GUARDIAN_HEAD_LINE(
        "probation_deadline_monotonic_ms=", probation_deadline
    );
    WLS_GUARDIAN_HEAD_LINE(
        "previous_record_sha256=", head->previous_record_sha256
    );
    WLS_GUARDIAN_HEAD_LINE("signature=", head->signature);
#undef WLS_GUARDIAN_HEAD_LINE
    if (offset != length
        || !wls_is_hex(head->host_id, 32U)
        || !wls_is_hex(head->active_generation_id, 64U)
        || !wls_is_hex(head->active_launcher_sha256, 64U)
        || !wls_is_hex(head->active_ca_sha256, 64U)
        || !wls_is_hex(head->active_runtime_generation, 64U)
        || !wls_is_hex(head->recovery_generation_id, 64U)
        || !wls_is_hex(head->recovery_nonce, 32U)
        || !wls_is_hex(head->recovery_authorization_sha256, 64U)
        || !wls_is_hex(head->host_boot_id, 64U)
        || !wls_is_hex(head->previous_record_sha256, 64U)
        || !wls_is_hex(head->signature, 64U)
        || wls_guardian_protocol_decimal(sequence, &parsed_sequence) != 0
        || wls_guardian_protocol_decimal(
            probation_start, &parsed_start
        ) != 0
        || wls_guardian_protocol_decimal(
            probation_deadline, &parsed_deadline
        ) != 0
        || parsed_sequence == 0ULL
        || parsed_sequence > (unsigned long long)LLONG_MAX
        || parsed_start > (unsigned long long)LLONG_MAX
        || parsed_deadline > (unsigned long long)LLONG_MAX
        || (strcmp(head->phase, "STABLE") != 0
            && strcmp(head->phase, "PROBATIONARY_COMMITTED") != 0
            && strcmp(head->phase, "ROLLBACK_PENDING") != 0
            && strcmp(head->phase, "ROLLBACK_OBSERVING") != 0
            && strcmp(head->phase, "FAILED_CLOSED") != 0)
        || wls_guardian_generation_id(
            head->active_launcher_sha256,
            head->active_ca_sha256,
            head->active_runtime_generation,
            generation_id
        ) != 0
        || !wls_guardian_constant_equal(
            generation_id, head->active_generation_id, 64U
        )
        || wls_guardian_signed_document_valid(
            home, contents, length, head->signature
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)contents, length, head->raw_sha256
        ) != 0) goto cleanup;
    if (((strcmp(head->phase, "PROBATIONARY_COMMITTED") == 0
                || strcmp(head->phase, "ROLLBACK_OBSERVING") == 0)
            && (parsed_start == 0ULL || parsed_deadline <= parsed_start))
        || ((strcmp(head->phase, "PROBATIONARY_COMMITTED") != 0
                && strcmp(head->phase, "ROLLBACK_OBSERVING") != 0)
            && (parsed_start != 0ULL || parsed_deadline != 0ULL))
        || (strcmp(head->phase, "STABLE") == 0
            && (!wls_all_zero_hex(head->recovery_generation_id)
                || strspn(head->recovery_nonce, "0") != 32U
                || !wls_all_zero_hex(
                    head->recovery_authorization_sha256
                )))
        || ((strcmp(head->phase, "PROBATIONARY_COMMITTED") == 0
                || strcmp(head->phase, "ROLLBACK_PENDING") == 0
                || strcmp(head->phase, "ROLLBACK_OBSERVING") == 0
                || strcmp(head->phase, "FAILED_CLOSED") == 0)
            && (wls_all_zero_hex(head->recovery_generation_id)
                || strspn(head->recovery_nonce, "0") == 32U
                || wls_all_zero_hex(
                    head->recovery_authorization_sha256
                )))
        || ((strcmp(head->phase, "PROBATIONARY_COMMITTED") == 0
                || strcmp(head->phase, "ROLLBACK_PENDING") == 0)
            && sodium_memcmp(
                head->active_generation_id,
                head->recovery_generation_id,
                64U
            ) == 0)
        || (strcmp(head->phase, "ROLLBACK_OBSERVING") == 0
            && sodium_memcmp(
                head->active_generation_id,
                head->recovery_generation_id,
                64U
            ) != 0)) goto cleanup;
    head->sequence = parsed_sequence;
    head->probation_started_monotonic_ms = parsed_start;
    head->probation_deadline_monotonic_ms = parsed_deadline;
    head->slot = slot;
    result = 0;
cleanup:
    SecureZeroMemory(sequence, sizeof(sequence));
    SecureZeroMemory(probation_start, sizeof(probation_start));
    SecureZeroMemory(probation_deadline, sizeof(probation_deadline));
    SecureZeroMemory(generation_id, sizeof(generation_id));
    if (result != 0) SecureZeroMemory(head, sizeof(*head));
    return result;
}

/* 0=present/valid, 2=missing, 1=unsafe/error. */
static int wls_guardian_head_slot_read(
    const wchar_t *home,
    int slot,
    struct wls_guardian_generation_head *head
) {
    wchar_t leaf[64];
    wchar_t path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    int state;
    int result = 1;
    ZeroMemory(leaf, sizeof(leaf));
    ZeroMemory(path, sizeof(path));
    if (head == NULL || (slot != 0 && slot != 1)
        || _snwprintf_s(
            leaf,
            sizeof(leaf) / sizeof(leaf[0]),
            _TRUNCATE,
            L"guardian-generation-head.%d",
            slot
        ) < 0
        || wls_guardian_protocol_trust_path(home, leaf, path) != 0) return 1;
    state = wls_guardian_protocol_document_read(
        path, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &contents, &length
    );
    if (state == 2) {
        result = 2;
    } else if (state == 0
        && wls_guardian_head_parse(
            home, contents, length, slot, head
        ) == 0) {
        result = 0;
    }
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_guardian_head_same_active(
    const struct wls_guardian_generation_head *left,
    const struct wls_guardian_generation_head *right
) {
    return left != NULL && right != NULL
        && sodium_memcmp(
            left->active_generation_id, right->active_generation_id, 64U
        ) == 0
        && sodium_memcmp(
            left->active_launcher_sha256,
            right->active_launcher_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            left->active_ca_sha256, right->active_ca_sha256, 64U
        ) == 0
        && sodium_memcmp(
            left->active_runtime_generation,
            right->active_runtime_generation,
            64U
        ) == 0;
}

static int wls_guardian_head_same_recovery(
    const struct wls_guardian_generation_head *left,
    const struct wls_guardian_generation_head *right
) {
    return left != NULL && right != NULL
        && sodium_memcmp(
            left->recovery_generation_id,
            right->recovery_generation_id,
            64U
        ) == 0
        && sodium_memcmp(
            left->recovery_nonce, right->recovery_nonce, 32U
        ) == 0
        && sodium_memcmp(
            left->recovery_authorization_sha256,
            right->recovery_authorization_sha256,
            64U
        ) == 0;
}

static int wls_guardian_head_transition_valid(
    const struct wls_guardian_generation_head *from,
    const struct wls_guardian_generation_head *to
) {
    int same_active;
    if (from == NULL || to == NULL
        || to->sequence != from->sequence + 1ULL
        || sodium_memcmp(from->host_id, to->host_id, 32U) != 0
        || sodium_memcmp(
            to->previous_record_sha256, from->raw_sha256, 64U
        ) != 0) return 0;
    same_active = wls_guardian_head_same_active(from, to);
    if (strcmp(from->phase, "STABLE") == 0
        && strcmp(to->phase, "PROBATIONARY_COMMITTED") == 0) {
        return !same_active
            && sodium_memcmp(
                from->active_generation_id,
                to->recovery_generation_id,
                64U
            ) == 0;
    }
    if (strcmp(from->phase, "PROBATIONARY_COMMITTED") == 0
        && strcmp(to->phase, "PROBATIONARY_COMMITTED") == 0) {
        return same_active && wls_guardian_head_same_recovery(from, to);
    }
    if (strcmp(from->phase, "PROBATIONARY_COMMITTED") == 0
        && strcmp(to->phase, "STABLE") == 0) return same_active;
    if (strcmp(to->phase, "ROLLBACK_PENDING") == 0) {
        if (strcmp(from->phase, "STABLE") == 0) {
            return (same_active
                    && sodium_memcmp(
                        from->active_generation_id,
                        to->recovery_generation_id,
                        64U
                    ) != 0)
                || (!same_active
                    && sodium_memcmp(
                        from->active_generation_id,
                        to->recovery_generation_id,
                        64U
                    ) == 0);
        }
        return strcmp(from->phase, "PROBATIONARY_COMMITTED") == 0
            && same_active && wls_guardian_head_same_recovery(from, to);
    }
    if (strcmp(from->phase, "ROLLBACK_PENDING") == 0
        && strcmp(to->phase, "ROLLBACK_OBSERVING") == 0) {
        return wls_guardian_head_same_recovery(from, to)
            && sodium_memcmp(
                from->recovery_generation_id,
                to->active_generation_id,
                64U
            ) == 0;
    }
    if (strcmp(from->phase, "ROLLBACK_OBSERVING") == 0
        && strcmp(to->phase, "ROLLBACK_OBSERVING") == 0) {
        return same_active && wls_guardian_head_same_recovery(from, to);
    }
    if (strcmp(from->phase, "ROLLBACK_OBSERVING") == 0
        && strcmp(to->phase, "STABLE") == 0) return same_active;
    if (strcmp(to->phase, "FAILED_CLOSED") == 0) {
        return (strcmp(from->phase, "PROBATIONARY_COMMITTED") == 0
                || strcmp(from->phase, "ROLLBACK_PENDING") == 0
                || strcmp(from->phase, "ROLLBACK_OBSERVING") == 0)
            && same_active && wls_guardian_head_same_recovery(from, to);
    }
    return 0;
}

/* 0=selected, 2=no copies, 1=invalid/fork. */
static int wls_guardian_head_read(
    const wchar_t *home,
    struct wls_guardian_generation_head *selected
) {
    struct wls_guardian_generation_head heads[2];
    int states[2];
    int newest;
    int older;
    int result = 1;
    SecureZeroMemory(heads, sizeof(heads));
    if (selected == NULL) return 1;
    SecureZeroMemory(selected, sizeof(*selected));
    states[0] = wls_guardian_head_slot_read(home, 0, &heads[0]);
    states[1] = wls_guardian_head_slot_read(home, 1, &heads[1]);
    if (states[0] == 2 && states[1] == 2) {
        result = 2;
        goto cleanup;
    }
    if (states[0] == 0 && states[1] != 0) {
        *selected = heads[0];
        result = 0;
        goto cleanup;
    }
    if (states[1] == 0 && states[0] != 0) {
        *selected = heads[1];
        result = 0;
        goto cleanup;
    }
    if (states[0] != 0 || states[1] != 0) goto cleanup;
    newest = heads[1].sequence > heads[0].sequence ? 1 : 0;
    older = 1 - newest;
    if (heads[0].sequence == heads[1].sequence) {
        if (!wls_guardian_constant_equal(
                heads[0].raw_sha256, heads[1].raw_sha256, 64U
            )) goto cleanup;
        newest = 0;
    } else if (!wls_guardian_head_transition_valid(
            &heads[older], &heads[newest]
        )) goto cleanup;
    *selected = heads[newest];
    result = 0;
cleanup:
    SecureZeroMemory(heads, sizeof(heads));
    if (result != 0) SecureZeroMemory(selected, sizeof(*selected));
    return result;
}

static int wls_guardian_head_publish_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    const struct wls_guardian_generation_head *current,
    const struct wls_guardian_transition_request *request,
    const char *phase,
    const char *boot_id,
    unsigned long long probation_started,
    unsigned long long probation_deadline,
    int recovery_active,
    struct wls_guardian_generation_head *published
) {
    static const char zeros32[] =
        "00000000000000000000000000000000";
    wchar_t leaf[64];
    wchar_t path[WLS_PATH_CHARS];
    char unsigned_record[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    char encoded[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    char signature[65];
    struct wls_guardian_generation_head candidate;
    struct wls_guardian_generation_head disk;
    const char *active_generation;
    const char *active_launcher;
    const char *active_ca;
    const char *active_runtime;
    const char *recovery_generation;
    const char *recovery_nonce;
    const char *recovery_authorization;
    unsigned long long sequence;
    int target_slot;
    int unsigned_length;
    int encoded_length;
    int result = 1;
    ZeroMemory(leaf, sizeof(leaf));
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(unsigned_record, sizeof(unsigned_record));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(&candidate, sizeof(candidate));
    SecureZeroMemory(&disk, sizeof(disk));
    if (!wls_guardian_protocol_home_valid(home)
        || !wls_guardian_protocol_lock_valid(home, lock)
        || current == NULL || request == NULL
        || phase == NULL || boot_id == NULL || published == NULL
        || !wls_is_hex(boot_id, 64U)
        || current->sequence == 0ULL
        || current->sequence >= (unsigned long long)LLONG_MAX
        || (recovery_active != 0 && recovery_active != 1)
        || (strcmp(phase, "STABLE") != 0
            && strcmp(phase, "PROBATIONARY_COMMITTED") != 0
            && strcmp(phase, "ROLLBACK_PENDING") != 0
            && strcmp(phase, "ROLLBACK_OBSERVING") != 0
            && strcmp(phase, "FAILED_CLOSED") != 0)) goto cleanup;
    if (wls_guardian_head_read(home, &disk) != 0
        || disk.sequence != current->sequence
        || disk.slot != current->slot
        || sodium_memcmp(disk.raw_sha256, current->raw_sha256, 64U) != 0) {
        goto cleanup;
    }
    sequence = current->sequence + 1ULL;
    active_generation = recovery_active
        ? request->recovery_generation_id : request->candidate_generation_id;
    active_launcher = recovery_active
        ? request->recovery_launcher_sha256
        : request->candidate_launcher_sha256;
    active_ca = recovery_active
        ? request->recovery_ca_sha256 : request->candidate_ca_sha256;
    active_runtime = recovery_active
        ? request->recovery_runtime_generation
        : request->candidate_runtime_generation;
    recovery_generation = strcmp(phase, "STABLE") == 0
        ? WLS_GUARDIAN_ZERO_64 : request->recovery_generation_id;
    recovery_nonce = strcmp(phase, "STABLE") == 0
        ? zeros32 : request->nonce;
    recovery_authorization = strcmp(phase, "STABLE") == 0
        ? WLS_GUARDIAN_ZERO_64 : request->recovery_authorization_sha256;
    unsigned_length = _snprintf_s(
        unsigned_record,
        sizeof(unsigned_record),
        _TRUNCATE,
        "WLS-GUARDIAN-GENERATION-HEAD/1\n"
        "host_id=%s\n"
        "sequence=%llu\n"
        "phase=%s\n"
        "active_generation_id=%s\n"
        "active_launcher_sha256=%s\n"
        "active_ca_sha256=%s\n"
        "active_runtime_generation=%s\n"
        "recovery_generation_id=%s\n"
        "recovery_nonce=%s\n"
        "recovery_authorization_sha256=%s\n"
        "host_boot_id=%s\n"
        "probation_started_monotonic_ms=%llu\n"
        "probation_deadline_monotonic_ms=%llu\n"
        "previous_record_sha256=%s\n",
        request->host_id,
        sequence,
        phase,
        active_generation,
        active_launcher,
        active_ca,
        active_runtime,
        recovery_generation,
        recovery_nonce,
        recovery_authorization,
        boot_id,
        probation_started,
        probation_deadline,
        current->raw_sha256
    );
    if (unsigned_length <= 0
        || unsigned_length >= (int)sizeof(unsigned_record)
        || wls_guardian_hmac_hex(
            home, unsigned_record, (size_t)unsigned_length, signature
        ) != 0) goto cleanup;
    encoded_length = _snprintf_s(
        encoded,
        sizeof(encoded),
        _TRUNCATE,
        "%ssignature=%s\n",
        unsigned_record,
        signature
    );
    target_slot = 1 - current->slot;
    if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
        || wls_guardian_head_parse(
            home,
            encoded,
            (size_t)encoded_length,
            target_slot,
            &candidate
        ) != 0
        || !wls_guardian_head_transition_valid(current, &candidate)
        || _snwprintf_s(
            leaf,
            sizeof(leaf) / sizeof(leaf[0]),
            _TRUNCATE,
            L"guardian-generation-head.%d",
            target_slot
        ) < 0
        || wls_guardian_protocol_trust_path(home, leaf, path) != 0
        || wls_guardian_protocol_atomic_text(path, encoded) != 0
        || wls_guardian_head_read(home, published) != 0
        || published->sequence != sequence
        || published->slot != target_slot
        || !wls_guardian_constant_equal(
            candidate.raw_sha256, published->raw_sha256, 64U
        )) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(unsigned_record, sizeof(unsigned_record));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(&candidate, sizeof(candidate));
    SecureZeroMemory(&disk, sizeof(disk));
    return result;
}

static int wls_guardian_recovery_transaction_position(
    unsigned long long sequence,
    const char **phase,
    unsigned long long *cursor
) {
    if (phase == NULL || cursor == NULL || sequence == 0ULL
        || sequence > 26ULL) return 1;
    if (sequence == 1ULL) {
        *phase = "AUTHORIZED";
        *cursor = 0ULL;
    } else if (sequence <= 9ULL) {
        *phase = "RUNTIME";
        *cursor = sequence - 2ULL;
    } else if (sequence <= 19ULL) {
        *phase = "DERIVED";
        *cursor = sequence - 10ULL;
    } else if (sequence <= 23ULL) {
        *phase = "PLATFORM";
        *cursor = sequence - 20ULL;
    } else if (sequence == 24ULL) {
        *phase = "RESTORED";
        *cursor = 0ULL;
    } else if (sequence == 25ULL) {
        *phase = "OBSERVING";
        *cursor = 0ULL;
    } else {
        *phase = "STABLE";
        *cursor = 0ULL;
    }
    return 0;
}

static int wls_guardian_recovery_transaction_parse(
    const wchar_t *home,
    const char *contents,
    size_t length,
    struct wls_guardian_recovery_transaction *transaction
) {
    char sequence[21];
    char cursor[21];
    const char *expected_phase = NULL;
    unsigned long long parsed_sequence = 0ULL;
    unsigned long long parsed_cursor = 0ULL;
    unsigned long long expected_cursor = 0ULL;
    size_t offset = 0U;
    int result = 1;
    if (!wls_guardian_protocol_home_valid(home) || contents == NULL
        || transaction == NULL || length == 0U
        || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES) return 1;
    SecureZeroMemory(transaction, sizeof(*transaction));
    SecureZeroMemory(sequence, sizeof(sequence));
    SecureZeroMemory(cursor, sizeof(cursor));
#define WLS_GUARDIAN_TX_LINE(prefix, field) \
    if (wls_guardian_protocol_line( \
            contents, length, &offset, prefix, field, sizeof(field) \
        ) != 0) goto cleanup
    if (wls_guardian_protocol_header(
            contents,
            length,
            &offset,
            "WLS-GUARDIAN-RECOVERY-TRANSACTION/1\n"
        ) != 0) goto cleanup;
    WLS_GUARDIAN_TX_LINE("host_id=", transaction->host_id);
    WLS_GUARDIAN_TX_LINE("nonce=", transaction->nonce);
    WLS_GUARDIAN_TX_LINE("request_sha256=", transaction->request_sha256);
    WLS_GUARDIAN_TX_LINE(
        "authorization_sha256=", transaction->authorization_sha256
    );
    WLS_GUARDIAN_TX_LINE(
        "inventory_sha256=", transaction->inventory_sha256
    );
    WLS_GUARDIAN_TX_LINE("sequence=", sequence);
    WLS_GUARDIAN_TX_LINE("phase=", transaction->phase);
    WLS_GUARDIAN_TX_LINE("cursor=", cursor);
    WLS_GUARDIAN_TX_LINE(
        "previous_record_sha256=", transaction->previous_record_sha256
    );
    WLS_GUARDIAN_TX_LINE("signature=", transaction->signature);
#undef WLS_GUARDIAN_TX_LINE
    if (offset != length
        || !wls_is_hex(transaction->host_id, 32U)
        || !wls_is_hex(transaction->nonce, 32U)
        || !wls_is_hex(transaction->request_sha256, 64U)
        || !wls_is_hex(transaction->authorization_sha256, 64U)
        || !wls_is_hex(transaction->inventory_sha256, 64U)
        || !wls_is_hex(transaction->previous_record_sha256, 64U)
        || !wls_is_hex(transaction->signature, 64U)
        || wls_guardian_protocol_decimal(sequence, &parsed_sequence) != 0
        || wls_guardian_protocol_decimal(cursor, &parsed_cursor) != 0
        || parsed_sequence == 0ULL || parsed_sequence > 26ULL
        || wls_guardian_recovery_transaction_position(
            parsed_sequence, &expected_phase, &expected_cursor
        ) != 0
        || strcmp(transaction->phase, expected_phase) != 0
        || parsed_cursor != expected_cursor
        || (parsed_sequence == 1ULL
            ? !wls_all_zero_hex(transaction->previous_record_sha256)
            : wls_all_zero_hex(transaction->previous_record_sha256))
        || wls_guardian_signed_document_valid(
            home, contents, length, transaction->signature
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)contents,
            length,
            transaction->raw_sha256
        ) != 0) goto cleanup;
    transaction->sequence = parsed_sequence;
    transaction->cursor = parsed_cursor;
    result = 0;
cleanup:
    SecureZeroMemory(sequence, sizeof(sequence));
    SecureZeroMemory(cursor, sizeof(cursor));
    if (result != 0) SecureZeroMemory(transaction, sizeof(*transaction));
    return result;
}

/* 0=present/valid, 2=missing, 1=unsafe/error. */
static int wls_guardian_recovery_transaction_read(
    const wchar_t *home,
    struct wls_guardian_recovery_transaction *transaction
) {
    wchar_t path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    int state;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    if (transaction == NULL || wls_guardian_protocol_trust_path(
            home, L"guardian-recovery.transaction", path
        ) != 0) return 1;
    state = wls_guardian_protocol_document_read(
        path, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &contents, &length
    );
    if (state == 2) {
        result = 2;
    } else if (state == 0
        && wls_guardian_recovery_transaction_parse(
            home, contents, length, transaction
        ) == 0) {
        result = 0;
    }
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_guardian_recovery_transaction_expected(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request,
    unsigned long long target_sequence,
    char digest[65]
) {
    char previous[65];
    char unsigned_record[1024];
    char encoded[1152];
    char signature[65];
    unsigned long long sequence;
    int result = 1;
    SecureZeroMemory(previous, sizeof(previous));
    SecureZeroMemory(unsigned_record, sizeof(unsigned_record));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    if (!wls_guardian_protocol_home_valid(home) || request == NULL
        || digest == NULL || target_sequence == 0ULL
        || target_sequence > 26ULL) goto cleanup;
    memcpy(previous, WLS_GUARDIAN_ZERO_64, sizeof(previous));
    for (sequence = 1ULL; sequence <= target_sequence; ++sequence) {
        const char *phase = NULL;
        unsigned long long cursor = 0ULL;
        int unsigned_length;
        int encoded_length;
        if (wls_guardian_recovery_transaction_position(
                sequence, &phase, &cursor
            ) != 0) goto cleanup;
        unsigned_length = _snprintf_s(
            unsigned_record,
            sizeof(unsigned_record),
            _TRUNCATE,
            "WLS-GUARDIAN-RECOVERY-TRANSACTION/1\n"
            "host_id=%s\n"
            "nonce=%s\n"
            "request_sha256=%s\n"
            "authorization_sha256=%s\n"
            "inventory_sha256=%s\n"
            "sequence=%llu\n"
            "phase=%s\n"
            "cursor=%llu\n"
            "previous_record_sha256=%s\n",
            request->host_id,
            request->nonce,
            request->raw_sha256,
            request->recovery_authorization_sha256,
            request->recovery_inventory_sha256,
            sequence,
            phase,
            cursor,
            previous
        );
        if (unsigned_length <= 0
            || unsigned_length >= (int)sizeof(unsigned_record)
            || wls_guardian_hmac_hex(
                home,
                unsigned_record,
                (size_t)unsigned_length,
                signature
            ) != 0) goto cleanup;
        encoded_length = _snprintf_s(
            encoded,
            sizeof(encoded),
            _TRUNCATE,
            "%ssignature=%s\n",
            unsigned_record,
            signature
        );
        if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
            || wls_guardian_sha256(
                (const unsigned char *)encoded,
                (size_t)encoded_length,
                previous
            ) != 0) goto cleanup;
    }
    memcpy(digest, previous, 65U);
    result = 0;
cleanup:
    SecureZeroMemory(previous, sizeof(previous));
    SecureZeroMemory(unsigned_record, sizeof(unsigned_record));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    if (result != 0 && digest != NULL) SecureZeroMemory(digest, 65U);
    return result;
}

static int wls_guardian_recovery_transaction_binding(
    const wchar_t *home,
    const struct wls_guardian_recovery_transaction *transaction,
    const struct wls_guardian_transition_request *request
) {
    char expected[65];
    int result = 1;
    SecureZeroMemory(expected, sizeof(expected));
    if (transaction == NULL || request == NULL
        || sodium_memcmp(transaction->host_id, request->host_id, 32U) != 0
        || sodium_memcmp(transaction->nonce, request->nonce, 32U) != 0
        || sodium_memcmp(
            transaction->request_sha256, request->raw_sha256, 64U
        ) != 0
        || sodium_memcmp(
            transaction->authorization_sha256,
            request->recovery_authorization_sha256,
            64U
        ) != 0
        || sodium_memcmp(
            transaction->inventory_sha256,
            request->recovery_inventory_sha256,
            64U
        ) != 0
        || wls_guardian_recovery_transaction_expected(
            home, request, transaction->sequence, expected
        ) != 0
        || !wls_guardian_constant_equal(
            expected, transaction->raw_sha256, 64U
        )) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(expected, sizeof(expected));
    return result;
}

static int wls_guardian_recovery_transaction_publish_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_recovery_transaction *current,
    const char *phase,
    unsigned long long cursor,
    struct wls_guardian_recovery_transaction *published
) {
    wchar_t path[WLS_PATH_CHARS];
    char unsigned_record[1024];
    char encoded[1152];
    char signature[65];
    struct wls_guardian_recovery_transaction disk;
    const char *expected_phase = NULL;
    const char *previous;
    unsigned long long expected_cursor = 0ULL;
    unsigned long long sequence;
    int unsigned_length;
    int encoded_length;
    int disk_state;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(unsigned_record, sizeof(unsigned_record));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(&disk, sizeof(disk));
    if (!wls_guardian_protocol_home_valid(home)
        || !wls_guardian_protocol_lock_valid(home, lock)
        || request == NULL || phase == NULL
        || published == NULL) goto cleanup;
    disk_state = wls_guardian_recovery_transaction_read(home, &disk);
    if (current == NULL) {
        if (disk_state != 2) goto cleanup;
        sequence = 1ULL;
        previous = WLS_GUARDIAN_ZERO_64;
    } else {
        if (disk_state != 0
            || disk.sequence != current->sequence
            || sodium_memcmp(disk.raw_sha256, current->raw_sha256, 64U) != 0
            || current->sequence == 0ULL || current->sequence >= 26ULL
            || wls_guardian_recovery_transaction_binding(
                home, current, request
            ) != 0) goto cleanup;
        sequence = current->sequence + 1ULL;
        previous = current->raw_sha256;
    }
    if (wls_guardian_recovery_transaction_position(
            sequence, &expected_phase, &expected_cursor
        ) != 0
        || strcmp(phase, expected_phase) != 0
        || cursor != expected_cursor) goto cleanup;
    unsigned_length = _snprintf_s(
        unsigned_record,
        sizeof(unsigned_record),
        _TRUNCATE,
        "WLS-GUARDIAN-RECOVERY-TRANSACTION/1\n"
        "host_id=%s\n"
        "nonce=%s\n"
        "request_sha256=%s\n"
        "authorization_sha256=%s\n"
        "inventory_sha256=%s\n"
        "sequence=%llu\n"
        "phase=%s\n"
        "cursor=%llu\n"
        "previous_record_sha256=%s\n",
        request->host_id,
        request->nonce,
        request->raw_sha256,
        request->recovery_authorization_sha256,
        request->recovery_inventory_sha256,
        sequence,
        phase,
        cursor,
        previous
    );
    if (unsigned_length <= 0 || unsigned_length >= (int)sizeof(unsigned_record)
        || wls_guardian_hmac_hex(
            home, unsigned_record, (size_t)unsigned_length, signature
        ) != 0) goto cleanup;
    encoded_length = _snprintf_s(
        encoded,
        sizeof(encoded),
        _TRUNCATE,
        "%ssignature=%s\n",
        unsigned_record,
        signature
    );
    if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
        || wls_guardian_protocol_trust_path(
            home, L"guardian-recovery.transaction", path
        ) != 0
        || wls_guardian_protocol_atomic_text(path, encoded) != 0
        || wls_guardian_recovery_transaction_read(home, published) != 0
        || published->sequence != sequence
        || published->cursor != cursor
        || strcmp(published->phase, phase) != 0
        || wls_guardian_recovery_transaction_binding(
            home, published, request
        ) != 0
        || sodium_memcmp(
            published->previous_record_sha256, previous, 64U
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(unsigned_record, sizeof(unsigned_record));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(&disk, sizeof(disk));
    return result;
}

static int wls_guardian_recovery_transaction_begin_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_transaction *transaction
) {
    int state;
    if (!wls_guardian_protocol_lock_valid(home, lock) || request == NULL
        || transaction == NULL) return 1;
    state = wls_guardian_recovery_transaction_read(home, transaction);
    if (state == 2) {
        return wls_guardian_recovery_transaction_publish_locked(
            home,
            lock,
            request,
            NULL,
            "AUTHORIZED",
            0ULL,
            transaction
        );
    }
    return state == 0
        ? wls_guardian_recovery_transaction_binding(
            home, transaction, request
        )
        : 1;
}

static int wls_guardian_recovery_transaction_advance_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_transaction *transaction,
    const char *phase,
    unsigned long long cursor
) {
    struct wls_guardian_recovery_transaction published;
    const char *expected_phase = NULL;
    unsigned long long expected_cursor = 0ULL;
    SecureZeroMemory(&published, sizeof(published));
    if (!wls_guardian_protocol_lock_valid(home, lock) || request == NULL
        || transaction == NULL || phase == NULL
        || transaction->sequence >= 26ULL
        || wls_guardian_recovery_transaction_binding(
            home, transaction, request
        ) != 0
        || wls_guardian_recovery_transaction_position(
            transaction->sequence + 1ULL,
            &expected_phase,
            &expected_cursor
        ) != 0
        || strcmp(phase, expected_phase) != 0
        || cursor != expected_cursor
        || wls_guardian_recovery_transaction_publish_locked(
            home,
            lock,
            request,
            transaction,
            phase,
            cursor,
            &published
        ) != 0
        || published.sequence != transaction->sequence + 1ULL
        || sodium_memcmp(
            published.previous_record_sha256,
            transaction->raw_sha256,
            64U
        ) != 0) {
        SecureZeroMemory(&published, sizeof(published));
        return 1;
    }
    *transaction = published;
    SecureZeroMemory(&published, sizeof(published));
    return 0;
}

static int wls_guardian_recovery_transaction_mark_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    const struct wls_guardian_transition_request *request,
    const char *phase
) {
    struct wls_guardian_recovery_transaction transaction;
    int result = 1;
    SecureZeroMemory(&transaction, sizeof(transaction));
    if (!wls_guardian_protocol_lock_valid(home, lock)
        || request == NULL || phase == NULL
        || (strcmp(phase, "OBSERVING") != 0
            && strcmp(phase, "STABLE") != 0)
        || wls_guardian_recovery_transaction_read(
            home, &transaction
        ) != 0
        || wls_guardian_recovery_transaction_binding(
            home, &transaction, request
        ) != 0) goto cleanup;
    if (strcmp(transaction.phase, phase) == 0) {
        result = 0;
        goto cleanup;
    }
    if (strcmp(phase, "STABLE") == 0
        && strcmp(transaction.phase, "RESTORED") == 0
        && wls_guardian_recovery_transaction_advance_locked(
            home,
            lock,
            request,
            &transaction,
            "OBSERVING",
            0ULL
        ) != 0) goto cleanup;
    if (wls_guardian_recovery_transaction_advance_locked(
            home,
            lock,
            request,
            &transaction,
            phase,
            0ULL
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(&transaction, sizeof(transaction));
    return result;
}

static int wls_guardian_ack_parse(
    const wchar_t *home,
    const char *contents,
    size_t length,
    struct wls_guardian_transition_ack *ack
) {
    char sequence[21];
    char phase[7];
    unsigned long long parsed_sequence = 0ULL;
    size_t offset = 0U;
    int result = 1;
    if (!wls_guardian_protocol_home_valid(home) || contents == NULL
        || ack == NULL || length == 0U
        || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES) return 1;
    SecureZeroMemory(ack, sizeof(*ack));
    SecureZeroMemory(sequence, sizeof(sequence));
    SecureZeroMemory(phase, sizeof(phase));
#define WLS_GUARDIAN_ACK_LINE(prefix, field) \
    if (wls_guardian_protocol_line( \
            contents, length, &offset, prefix, field, sizeof(field) \
        ) != 0) goto cleanup
    if (wls_guardian_protocol_header(
            contents,
            length,
            &offset,
            "WLS-GUARDIAN-TRANSITION-ACK/1\n"
        ) != 0) goto cleanup;
    WLS_GUARDIAN_ACK_LINE("host_id=", ack->host_id);
    WLS_GUARDIAN_ACK_LINE("nonce=", ack->nonce);
    WLS_GUARDIAN_ACK_LINE("request_sha256=", ack->request_sha256);
    WLS_GUARDIAN_ACK_LINE("committed_head_sequence=", sequence);
    WLS_GUARDIAN_ACK_LINE(
        "committed_head_sha256=", ack->committed_head_sha256
    );
    WLS_GUARDIAN_ACK_LINE("purpose=", ack->purpose);
    WLS_GUARDIAN_ACK_LINE("phase=", phase);
    WLS_GUARDIAN_ACK_LINE(
        "active_generation_id=", ack->active_generation_id
    );
    WLS_GUARDIAN_ACK_LINE("signature=", ack->signature);
#undef WLS_GUARDIAN_ACK_LINE
    if (offset != length
        || !wls_is_hex(ack->host_id, 32U)
        || !wls_is_hex(ack->nonce, 32U)
        || !wls_is_hex(ack->request_sha256, 64U)
        || !wls_is_hex(ack->committed_head_sha256, 64U)
        || !wls_is_hex(ack->active_generation_id, 64U)
        || !wls_is_hex(ack->signature, 64U)
        || (strcmp(ack->purpose, "commit") != 0
            && strcmp(ack->purpose, "rollback") != 0)
        || strcmp(phase, "STABLE") != 0
        || wls_guardian_protocol_decimal(sequence, &parsed_sequence) != 0
        || parsed_sequence == 0ULL
        || parsed_sequence > (unsigned long long)LLONG_MAX
        || wls_guardian_signed_document_valid(
            home, contents, length, ack->signature
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)contents, length, ack->raw_sha256
        ) != 0) goto cleanup;
    ack->committed_head_sequence = parsed_sequence;
    result = 0;
cleanup:
    SecureZeroMemory(sequence, sizeof(sequence));
    SecureZeroMemory(phase, sizeof(phase));
    if (result != 0) SecureZeroMemory(ack, sizeof(*ack));
    return result;
}

/* 0=present/valid, 2=missing, 1=unsafe/error. */
static int wls_guardian_ack_read(
    const wchar_t *home,
    struct wls_guardian_transition_ack *ack
) {
    wchar_t path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    int state;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    if (ack == NULL || wls_guardian_protocol_trust_path(
            home, L"guardian-transition.ack", path
        ) != 0) return 1;
    state = wls_guardian_protocol_document_read(
        path, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &contents, &length
    );
    if (state == 2) {
        result = 2;
    } else if (state == 0
        && wls_guardian_ack_parse(home, contents, length, ack) == 0) {
        result = 0;
    }
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_guardian_ack_binding(
    const struct wls_guardian_transition_ack *ack,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_generation_head *head,
    int require_current_head
) {
    const char *expected_generation;
    if (ack == NULL || request == NULL
        || (require_current_head != 0 && require_current_head != 1)) return 1;
    expected_generation = strcmp(ack->purpose, "rollback") == 0
        ? request->recovery_generation_id
        : request->candidate_generation_id;
    if (sodium_memcmp(ack->host_id, request->host_id, 32U) != 0
        || sodium_memcmp(ack->nonce, request->nonce, 32U) != 0
        || sodium_memcmp(
            ack->request_sha256, request->raw_sha256, 64U
        ) != 0
        || sodium_memcmp(
            ack->active_generation_id, expected_generation, 64U
        ) != 0) return 1;
    if (require_current_head
        && (head == NULL || strcmp(head->phase, "STABLE") != 0
            || ack->committed_head_sequence != head->sequence
            || sodium_memcmp(
                ack->committed_head_sha256, head->raw_sha256, 64U
            ) != 0
            || sodium_memcmp(
                ack->active_generation_id,
                head->active_generation_id,
                64U
            ) != 0)) return 1;
    return 0;
}

static int wls_guardian_ack_publish_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_generation_head *head,
    int recovery_active
) {
    wchar_t path[WLS_PATH_CHARS];
    char unsigned_ack[1024];
    char encoded[1152];
    char signature[65];
    char *existing_raw = NULL;
    size_t existing_length = 0U;
    struct wls_guardian_transition_ack existing;
    struct wls_guardian_transition_ack published;
    struct wls_guardian_generation_head disk_head;
    const char *purpose;
    const char *expected_generation;
    int state;
    int unsigned_length;
    int encoded_length;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(unsigned_ack, sizeof(unsigned_ack));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(&existing, sizeof(existing));
    SecureZeroMemory(&published, sizeof(published));
    SecureZeroMemory(&disk_head, sizeof(disk_head));
    if (!wls_guardian_protocol_home_valid(home)
        || !wls_guardian_protocol_lock_valid(home, lock)
        || request == NULL || head == NULL
        || (recovery_active != 0 && recovery_active != 1)) goto cleanup;
    purpose = recovery_active ? "rollback" : "commit";
    expected_generation = recovery_active
        ? request->recovery_generation_id
        : request->candidate_generation_id;
    if (strcmp(head->phase, "STABLE") != 0
        || sodium_memcmp(
            head->active_generation_id, expected_generation, 64U
        ) != 0
        || wls_guardian_head_read(home, &disk_head) != 0
        || disk_head.sequence != head->sequence
        || disk_head.slot != head->slot
        || sodium_memcmp(
            disk_head.raw_sha256, head->raw_sha256, 64U
        ) != 0) goto cleanup;
    unsigned_length = _snprintf_s(
        unsigned_ack,
        sizeof(unsigned_ack),
        _TRUNCATE,
        "WLS-GUARDIAN-TRANSITION-ACK/1\n"
        "host_id=%s\n"
        "nonce=%s\n"
        "request_sha256=%s\n"
        "committed_head_sequence=%llu\n"
        "committed_head_sha256=%s\n"
        "purpose=%s\n"
        "phase=STABLE\n"
        "active_generation_id=%s\n",
        request->host_id,
        request->nonce,
        request->raw_sha256,
        head->sequence,
        head->raw_sha256,
        purpose,
        head->active_generation_id
    );
    if (unsigned_length <= 0 || unsigned_length >= (int)sizeof(unsigned_ack)
        || wls_guardian_hmac_hex(
            home, unsigned_ack, (size_t)unsigned_length, signature
        ) != 0) goto cleanup;
    encoded_length = _snprintf_s(
        encoded,
        sizeof(encoded),
        _TRUNCATE,
        "%ssignature=%s\n",
        unsigned_ack,
        signature
    );
    if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
        || wls_guardian_protocol_trust_path(
            home, L"guardian-transition.ack", path
        ) != 0) goto cleanup;
    state = wls_guardian_protocol_document_read(
        path,
        WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
        &existing_raw,
        &existing_length
    );
    if (state == 0) {
        if (existing_length == (size_t)encoded_length
            && sodium_memcmp(existing_raw, encoded, existing_length) == 0) {
            result = 0;
            goto cleanup;
        }
        if (!recovery_active
            || wls_guardian_ack_parse(
                home, existing_raw, existing_length, &existing
            ) != 0
            || strcmp(existing.purpose, "commit") != 0
            || wls_guardian_ack_binding(
                &existing, request, NULL, 0
            ) != 0
            || sodium_memcmp(
                existing.active_generation_id,
                request->candidate_generation_id,
                64U
            ) != 0) goto cleanup;
    } else if (state != 2) {
        goto cleanup;
    }
    if (wls_guardian_protocol_atomic_text(path, encoded) != 0
        || wls_guardian_ack_read(home, &published) != 0
        || wls_guardian_ack_binding(
            &published, request, head, 1
        ) != 0
        || strcmp(published.purpose, purpose) != 0) goto cleanup;
    result = 0;
cleanup:
    if (existing_raw != NULL) {
        wls_guardian_contents_free(&existing_raw, existing_length);
    }
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(unsigned_ack, sizeof(unsigned_ack));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(&existing, sizeof(existing));
    SecureZeroMemory(&published, sizeof(published));
    SecureZeroMemory(&disk_head, sizeof(disk_head));
    return result;
}

static int wls_guardian_protocol_artifact_validate(
    struct wls_guardian_protocol_artifact *artifact,
    size_t maximum,
    wls_guardian_protocol_document_validator validator,
    void *context
) {
    char *contents = NULL;
    size_t length = 0U;
    int result = 1;
    if (artifact == NULL || !artifact->present || validator == NULL
        || wls_guardian_protocol_pin_read(
            &artifact->pin, maximum, &contents, &length
        ) != 0) goto cleanup;
    result = validator(artifact->path, contents, length, context) == 0 ? 0 : 1;
cleanup:
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    return result;
}

/* The caller holds guardian-generation-head.lock.  A valid committed target
 * wins.  If the target is absent, an authenticated staging after-image wins
 * only after any retained predecessor backup has independently validated.
 * An authenticated backup is otherwise restored as the last committed value.
 * Existing invalid targets are never overwritten. */
static int wls_guardian_protocol_atomic_reconcile_document(
    const wchar_t *path,
    size_t maximum,
    wls_guardian_protocol_document_validator target_validator,
    wls_guardian_protocol_document_validator staging_validator,
    void *target_context,
    void *staging_context,
    int discard_invalid_first_publication
) {
    struct wls_guardian_protocol_atomic_inventory inventory;
    struct wls_guardian_protocol_artifact *promote = NULL;
    int target_valid = 0;
    int temporary_valid = 0;
    int backup_valid = 0;
    int result = 1;
    SecureZeroMemory(&inventory, sizeof(inventory));
    inventory.parent.handle = INVALID_HANDLE_VALUE;
    inventory.target.pin.handle = INVALID_HANDLE_VALUE;
    inventory.temporary.pin.handle = INVALID_HANDLE_VALUE;
    inventory.backup.pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL || maximum == 0U || target_validator == NULL
        || staging_validator == NULL
        || (discard_invalid_first_publication != 0
            && discard_invalid_first_publication != 1)
        || wls_guardian_protocol_atomic_inventory_load(
            path, maximum, &inventory
        ) != 0) goto cleanup;
    if (!inventory.temporary.present && !inventory.backup.present) {
        result = 0;
        goto cleanup;
    }
    if (inventory.target.present) {
        target_valid = wls_guardian_protocol_artifact_validate(
            &inventory.target,
            maximum,
            target_validator,
            target_context
        ) == 0;
        if (!target_valid) goto cleanup;
        if (inventory.temporary.present
            && wls_guardian_protocol_artifact_remove(
                &inventory, &inventory.temporary
            ) != 0) goto cleanup;
        if (inventory.backup.present
            && wls_guardian_protocol_artifact_remove(
                &inventory, &inventory.backup
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (inventory.temporary.present) {
        temporary_valid = wls_guardian_protocol_artifact_validate(
            &inventory.temporary,
            maximum,
            staging_validator,
            staging_context
        ) == 0;
    }
    if (inventory.backup.present) {
        backup_valid = wls_guardian_protocol_artifact_validate(
            &inventory.backup,
            maximum,
            target_validator,
            target_context
        ) == 0;
        if (!backup_valid) goto cleanup;
    }
    if (temporary_valid && (!inventory.backup.present || backup_valid)) {
        promote = &inventory.temporary;
    } else if (backup_valid) {
        if (inventory.temporary.present
            && wls_guardian_protocol_artifact_remove(
                &inventory, &inventory.temporary
            ) != 0) goto cleanup;
        promote = &inventory.backup;
    } else if (inventory.temporary.present
        && !inventory.backup.present
        && discard_invalid_first_publication
        && wls_guardian_protocol_artifact_remove(
            &inventory, &inventory.temporary
        ) == 0) {
        result = 0;
        goto cleanup;
    } else {
        goto cleanup;
    }
    if (promote == NULL
        || wls_guardian_protocol_artifact_promote(
            &inventory, promote
        ) != 0) goto cleanup;
    wls_guardian_protocol_inventory_close(&inventory);
    if (wls_guardian_protocol_atomic_inventory_load(
            path, maximum, &inventory
        ) != 0
        || !inventory.target.present
        || wls_guardian_protocol_artifact_validate(
            &inventory.target,
            maximum,
            target_validator,
            target_context
        ) != 0
        || inventory.temporary.present
        || (inventory.backup.present
            && wls_guardian_protocol_artifact_remove(
                &inventory, &inventory.backup
            ) != 0)) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_protocol_inventory_close(&inventory);
    return result;
}

struct wls_guardian_protocol_head_validator_context {
    const wchar_t *home;
    int slot;
    const struct wls_guardian_generation_head *other;
    int require_chain;
    int allow_initial;
};

static int wls_guardian_protocol_initial_head_valid(
    const wchar_t *home,
    const struct wls_guardian_generation_head *head
) {
    wchar_t path[WLS_PATH_CHARS];
    char *host = NULL;
    size_t length = 0U;
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (head == NULL || head->sequence != 1ULL
        || strcmp(head->phase, "STABLE") != 0
        || !wls_all_zero_hex(head->previous_record_sha256)
        || wls_guardian_protocol_trust_path(home, L"host-id", path) != 0
        || wls_guardian_read_pinned(
            path, 33U, 1, &host, &length, &pin
        ) != 0
        || !((length == 32U) || (length == 33U && host[32] == '\n'))
        || sodium_memcmp(host, head->host_id, 32U) != 0) goto cleanup;
    result = 0;
cleanup:
    if (host != NULL) wls_guardian_contents_free(&host, length);
    wls_guardian_pinned_close(&pin);
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_guardian_protocol_head_document_validator(
    const wchar_t *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_protocol_head_validator_context *context =
        (struct wls_guardian_protocol_head_validator_context *)opaque;
    struct wls_guardian_generation_head head;
    int result = 1;
    (void)path;
    SecureZeroMemory(&head, sizeof(head));
    if (context == NULL || context->home == NULL
        || wls_guardian_head_parse(
            context->home, contents, length, context->slot, &head
        ) != 0) goto cleanup;
    if (context->require_chain) {
        if (context->other == NULL
            || !wls_guardian_head_transition_valid(
                context->other, &head
            )) goto cleanup;
    } else if (context->allow_initial
        && wls_guardian_protocol_initial_head_valid(
            context->home, &head
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(&head, sizeof(head));
    return result;
}

static int wls_guardian_protocol_head_target_read(
    const wchar_t *home,
    int slot,
    struct wls_guardian_generation_head *head
) {
    wchar_t leaf[64];
    wchar_t path[WLS_PATH_CHARS];
    struct wls_guardian_protocol_atomic_inventory inventory;
    char *contents = NULL;
    size_t length = 0U;
    int result = 1;
    ZeroMemory(leaf, sizeof(leaf));
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(&inventory, sizeof(inventory));
    inventory.parent.handle = INVALID_HANDLE_VALUE;
    inventory.target.pin.handle = INVALID_HANDLE_VALUE;
    inventory.temporary.pin.handle = INVALID_HANDLE_VALUE;
    inventory.backup.pin.handle = INVALID_HANDLE_VALUE;
    if (head == NULL || (slot != 0 && slot != 1)
        || _snwprintf_s(
            leaf,
            sizeof(leaf) / sizeof(leaf[0]),
            _TRUNCATE,
            L"guardian-generation-head.%d",
            slot
        ) < 0
        || wls_guardian_protocol_trust_path(home, leaf, path) != 0
        || wls_guardian_protocol_atomic_inventory_load(
            path, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &inventory
        ) != 0) goto cleanup;
    if (!inventory.target.present) {
        result = 2;
        goto cleanup;
    }
    if (wls_guardian_protocol_pin_read(
            &inventory.target.pin,
            WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
            &contents,
            &length
        ) != 0
        || wls_guardian_head_parse(
            home, contents, length, slot, head
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    wls_guardian_protocol_inventory_close(&inventory);
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_guardian_protocol_heads_reconcile_locked(
    const wchar_t *home
) {
    struct wls_guardian_generation_head heads[2];
    struct wls_guardian_protocol_head_validator_context target_context;
    struct wls_guardian_protocol_head_validator_context staging_context;
    wchar_t leaf[64];
    wchar_t path[WLS_PATH_CHARS];
    int states[2];
    int slot;
    int result = 1;
    SecureZeroMemory(heads, sizeof(heads));
    SecureZeroMemory(&target_context, sizeof(target_context));
    SecureZeroMemory(&staging_context, sizeof(staging_context));
    ZeroMemory(leaf, sizeof(leaf));
    ZeroMemory(path, sizeof(path));
    for (slot = 0; slot < 2; ++slot) {
        states[0] = wls_guardian_protocol_head_target_read(home, 0, &heads[0]);
        states[1] = wls_guardian_protocol_head_target_read(home, 1, &heads[1]);
        if (states[slot] == 1) goto cleanup;
        target_context.home = home;
        target_context.slot = slot;
        target_context.other = NULL;
        target_context.require_chain = 0;
        target_context.allow_initial = 0;
        staging_context = target_context;
        if (states[1 - slot] == 0) {
            staging_context.other = &heads[1 - slot];
            staging_context.require_chain = 1;
        } else {
            staging_context.allow_initial = 1;
        }
        if (_snwprintf_s(
                leaf,
                sizeof(leaf) / sizeof(leaf[0]),
                _TRUNCATE,
                L"guardian-generation-head.%d",
                slot
            ) < 0
            || wls_guardian_protocol_trust_path(home, leaf, path) != 0
            || wls_guardian_protocol_atomic_reconcile_document(
                path,
                WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
                wls_guardian_protocol_head_document_validator,
                wls_guardian_protocol_head_document_validator,
                &target_context,
                &staging_context,
                1
            ) != 0) goto cleanup;
    }
    if (wls_guardian_head_read(home, &heads[0]) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(heads, sizeof(heads));
    SecureZeroMemory(&target_context, sizeof(target_context));
    SecureZeroMemory(&staging_context, sizeof(staging_context));
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

struct wls_guardian_protocol_request_validator_context {
    const wchar_t *home;
    const struct wls_guardian_generation_head *head;
    int require_head_binding;
};

static int wls_guardian_protocol_request_document_validator(
    const wchar_t *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_protocol_request_validator_context *context =
        (struct wls_guardian_protocol_request_validator_context *)opaque;
    struct wls_guardian_transition_request request;
    int result = 1;
    (void)path;
    SecureZeroMemory(&request, sizeof(request));
    if (context == NULL || context->home == NULL
        || wls_guardian_request_parse(
            context->home, contents, length, &request
        ) != 0) goto cleanup;
    if (context->require_head_binding
        && (context->head == NULL
            || strcmp(context->head->phase, "STABLE") != 0
            || request.expected_head_sequence != context->head->sequence
            || sodium_memcmp(
                request.expected_head_sha256,
                context->head->raw_sha256,
                64U
            ) != 0
            || sodium_memcmp(
                request.host_id, context->head->host_id, 32U
            ) != 0)) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(&request, sizeof(request));
    return result;
}

static int wls_guardian_protocol_request_reconcile_locked(
    const wchar_t *home,
    const struct wls_guardian_generation_head *head
) {
    wchar_t path[WLS_PATH_CHARS];
    struct wls_guardian_protocol_request_validator_context target_context;
    struct wls_guardian_protocol_request_validator_context staging_context;
    int result;
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(&target_context, sizeof(target_context));
    SecureZeroMemory(&staging_context, sizeof(staging_context));
    if (head == NULL || wls_guardian_protocol_trust_path(
            home, L"guardian-transition.request", path
        ) != 0) return 1;
    target_context.home = home;
    target_context.head = head;
    target_context.require_head_binding = 0;
    staging_context = target_context;
    staging_context.require_head_binding = 1;
    result = wls_guardian_protocol_atomic_reconcile_document(
        path,
        WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
        wls_guardian_protocol_request_document_validator,
        wls_guardian_protocol_request_document_validator,
        &target_context,
        &staging_context,
        1
    );
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(&target_context, sizeof(target_context));
    SecureZeroMemory(&staging_context, sizeof(staging_context));
    return result;
}

struct wls_guardian_protocol_transaction_validator_context {
    const wchar_t *home;
    const struct wls_guardian_transition_request *request;
    int require_initial;
};

static int wls_guardian_protocol_transaction_document_validator(
    const wchar_t *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_protocol_transaction_validator_context *context =
        (struct wls_guardian_protocol_transaction_validator_context *)opaque;
    struct wls_guardian_recovery_transaction transaction;
    int result = 1;
    (void)path;
    SecureZeroMemory(&transaction, sizeof(transaction));
    if (context == NULL || context->home == NULL
        || context->request == NULL
        || wls_guardian_recovery_transaction_parse(
            context->home, contents, length, &transaction
        ) != 0
        || wls_guardian_recovery_transaction_binding(
            context->home, &transaction, context->request
        ) != 0
        || (context->require_initial
            && (transaction.sequence != 1ULL
                || transaction.cursor != 0ULL
                || strcmp(transaction.phase, "AUTHORIZED") != 0))) {
        goto cleanup;
    }
    result = 0;
cleanup:
    SecureZeroMemory(&transaction, sizeof(transaction));
    return result;
}

static int wls_guardian_protocol_transaction_reconcile_locked(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request
) {
    wchar_t path[WLS_PATH_CHARS];
    struct wls_guardian_protocol_transaction_validator_context target_context;
    struct wls_guardian_protocol_transaction_validator_context staging_context;
    int result;
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(&target_context, sizeof(target_context));
    SecureZeroMemory(&staging_context, sizeof(staging_context));
    if (request == NULL || wls_guardian_protocol_trust_path(
            home, L"guardian-recovery.transaction", path
        ) != 0) return 1;
    target_context.home = home;
    target_context.request = request;
    target_context.require_initial = 0;
    staging_context = target_context;
    staging_context.require_initial = 1;
    result = wls_guardian_protocol_atomic_reconcile_document(
        path,
        WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
        wls_guardian_protocol_transaction_document_validator,
        wls_guardian_protocol_transaction_document_validator,
        &target_context,
        &staging_context,
        1
    );
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(&target_context, sizeof(target_context));
    SecureZeroMemory(&staging_context, sizeof(staging_context));
    return result;
}

struct wls_guardian_protocol_ack_validator_context {
    const wchar_t *home;
    const struct wls_guardian_transition_request *request;
    const struct wls_guardian_generation_head *head;
    int require_current_head;
};

static int wls_guardian_protocol_ack_document_validator(
    const wchar_t *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_protocol_ack_validator_context *context =
        (struct wls_guardian_protocol_ack_validator_context *)opaque;
    struct wls_guardian_transition_ack ack;
    int result = 1;
    (void)path;
    SecureZeroMemory(&ack, sizeof(ack));
    if (context == NULL || context->home == NULL
        || context->request == NULL
        || wls_guardian_ack_parse(
            context->home, contents, length, &ack
        ) != 0
        || wls_guardian_ack_binding(
            &ack,
            context->request,
            context->head,
            context->require_current_head
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(&ack, sizeof(ack));
    return result;
}

static int wls_guardian_protocol_ack_reconcile_locked(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_generation_head *head
) {
    wchar_t path[WLS_PATH_CHARS];
    struct wls_guardian_protocol_ack_validator_context target_context;
    struct wls_guardian_protocol_ack_validator_context staging_context;
    int result;
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(&target_context, sizeof(target_context));
    SecureZeroMemory(&staging_context, sizeof(staging_context));
    if (request == NULL || head == NULL
        || wls_guardian_protocol_trust_path(
            home, L"guardian-transition.ack", path
        ) != 0) return 1;
    target_context.home = home;
    target_context.request = request;
    target_context.head = head;
    target_context.require_current_head = 0;
    staging_context = target_context;
    staging_context.require_current_head = 1;
    result = wls_guardian_protocol_atomic_reconcile_document(
        path,
        WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
        wls_guardian_protocol_ack_document_validator,
        wls_guardian_protocol_ack_document_validator,
        &target_context,
        &staging_context,
        1
    );
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(&target_context, sizeof(target_context));
    SecureZeroMemory(&staging_context, sizeof(staging_context));
    return result;
}

static int wls_guardian_protocol_request_head_binding(
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_generation_head *head
) {
    int candidate_active;
    int recovery_active;
    if (request == NULL || head == NULL
        || sodium_memcmp(request->host_id, head->host_id, 32U) != 0
        || head->sequence < request->expected_head_sequence) return 1;
    candidate_active = sodium_memcmp(
        head->active_generation_id, request->candidate_generation_id, 64U
    ) == 0
        && sodium_memcmp(
            head->active_launcher_sha256,
            request->candidate_launcher_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_ca_sha256, request->candidate_ca_sha256, 64U
        ) == 0
        && sodium_memcmp(
            head->active_runtime_generation,
            request->candidate_runtime_generation,
            64U
        ) == 0;
    recovery_active = sodium_memcmp(
        head->active_generation_id, request->recovery_generation_id, 64U
    ) == 0
        && sodium_memcmp(
            head->active_launcher_sha256,
            request->recovery_launcher_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_ca_sha256, request->recovery_ca_sha256, 64U
        ) == 0
        && sodium_memcmp(
            head->active_runtime_generation,
            request->recovery_runtime_generation,
            64U
        ) == 0;
    if (strcmp(head->phase, "STABLE") == 0) {
        if (head->sequence == request->expected_head_sequence) {
            return sodium_memcmp(
                head->raw_sha256, request->expected_head_sha256, 64U
            ) == 0 ? 0 : 1;
        }
        return candidate_active || recovery_active ? 0 : 1;
    }
    if (head->sequence <= request->expected_head_sequence) return 1;
    if (sodium_memcmp(
            head->recovery_generation_id,
            request->recovery_generation_id,
            64U
        ) != 0
        || sodium_memcmp(
            head->recovery_nonce, request->nonce, 32U
        ) != 0
        || sodium_memcmp(
            head->recovery_authorization_sha256,
            request->recovery_authorization_sha256,
            64U
        ) != 0) return 1;
    if (strcmp(head->phase, "PROBATIONARY_COMMITTED") == 0) {
        return candidate_active ? 0 : 1;
    }
    if (strcmp(head->phase, "ROLLBACK_PENDING") == 0
        || strcmp(head->phase, "FAILED_CLOSED") == 0) {
        return candidate_active || recovery_active ? 0 : 1;
    }
    if (strcmp(head->phase, "ROLLBACK_OBSERVING") == 0) {
        return recovery_active ? 0 : 1;
    }
    return 1;
}

static void wls_guardian_protocol_unlock(
    struct wls_guardian_protocol_lock *lock
) {
    if (lock == NULL) return;
    if (lock->locked && lock->pin.handle != NULL
        && lock->pin.handle != INVALID_HANDLE_VALUE) {
        (void)UnlockFileEx(
            lock->pin.handle,
            0U,
            MAXDWORD,
            MAXDWORD,
            &lock->overlap
        );
    }
    wls_guardian_pinned_close(&lock->pin);
    SecureZeroMemory(lock, sizeof(*lock));
    lock->pin.handle = INVALID_HANDLE_VALUE;
}

static int wls_guardian_protocol_lock_acquire(
    const wchar_t *home,
    struct wls_guardian_protocol_lock *lock
) {
    wchar_t path[WLS_PATH_CHARS];
    struct wls_guardian_protocol_atomic_inventory inventory;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(&inventory, sizeof(inventory));
    inventory.parent.handle = INVALID_HANDLE_VALUE;
    inventory.target.pin.handle = INVALID_HANDLE_VALUE;
    inventory.temporary.pin.handle = INVALID_HANDLE_VALUE;
    inventory.backup.pin.handle = INVALID_HANDLE_VALUE;
    if (lock == NULL) return 1;
    SecureZeroMemory(lock, sizeof(*lock));
    lock->pin.handle = INVALID_HANDLE_VALUE;
    if (wls_guardian_protocol_trust_path(
            home, L"guardian-generation-head.lock", path
        ) != 0
        || wls_guardian_protocol_atomic_inventory_load(
            path, 1U, &inventory
        ) != 0
        || !inventory.target.present
        || inventory.temporary.present || inventory.backup.present
        || inventory.target.pin.identity.nFileSizeHigh != 0U
        || inventory.target.pin.identity.nFileSizeLow > 1U) goto cleanup;
    lock->pin = inventory.target.pin;
    inventory.target.pin.handle = INVALID_HANDLE_VALUE;
    ZeroMemory(&lock->overlap, sizeof(lock->overlap));
    if (!LockFileEx(
            lock->pin.handle,
            LOCKFILE_EXCLUSIVE_LOCK | LOCKFILE_FAIL_IMMEDIATELY,
            0U,
            MAXDWORD,
            MAXDWORD,
            &lock->overlap
        )
        || !wls_guardian_pinned_identity_unchanged(&lock->pin, 0)) {
        goto cleanup;
    }
    if (wcslen(home) + 1U > WLS_PATH_CHARS) goto cleanup;
    memcpy(
        lock->home,
        home,
        (wcslen(home) + 1U) * sizeof(wchar_t)
    );
    lock->locked = 1;
    result = 0;
cleanup:
    wls_guardian_protocol_inventory_close(&inventory);
    SecureZeroMemory(path, sizeof(path));
    if (result != 0) wls_guardian_protocol_unlock(lock);
    return result;
}

/* Acquire and retain the generation-head lock, reconcile every protocol
 * companion, and return only authenticated persisted state.  The function
 * deliberately does not select a live Launcher digest: during a newly
 * published request the live stable Launcher may already be the candidate
 * while the authoritative head is still STABLE(old). */
static int wls_guardian_protocol_reconcile_and_select(
    const wchar_t *home,
    struct wls_guardian_protocol_lock *lock,
    struct wls_guardian_generation_head *head,
    struct wls_guardian_transition_request *request,
    int *request_present
) {
    struct wls_guardian_recovery_transaction transaction;
    struct wls_guardian_transition_ack ack;
    int request_state;
    int transaction_state;
    int ack_state;
    int result = 1;
    SecureZeroMemory(&transaction, sizeof(transaction));
    SecureZeroMemory(&ack, sizeof(ack));
    if (lock == NULL || head == NULL || request == NULL
        || request_present == NULL) return 1;
    SecureZeroMemory(head, sizeof(*head));
    SecureZeroMemory(request, sizeof(*request));
    *request_present = 0;
    if (wls_guardian_protocol_lock_acquire(home, lock) != 0
        || wls_guardian_protocol_heads_reconcile_locked(home) != 0
        || wls_guardian_head_read(home, head) != 0
        || wls_guardian_protocol_request_reconcile_locked(
            home, head
        ) != 0) goto cleanup;
    request_state = wls_guardian_request_read(home, request);
    if (request_state == 2) {
        if (strcmp(head->phase, "STABLE") != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (request_state != 0
        || wls_guardian_protocol_request_head_binding(
            request, head
        ) != 0
        || wls_guardian_protocol_transaction_reconcile_locked(
            home, request
        ) != 0
        || wls_guardian_protocol_ack_reconcile_locked(
            home, request, head
        ) != 0) goto cleanup;
    transaction_state = wls_guardian_recovery_transaction_read(
        home, &transaction
    );
    if (transaction_state == 0
        && wls_guardian_recovery_transaction_binding(
            home, &transaction, request
        ) != 0) goto cleanup;
    if (transaction_state != 0 && transaction_state != 2) goto cleanup;
    ack_state = wls_guardian_ack_read(home, &ack);
    if (ack_state == 0
        && wls_guardian_ack_binding(
            &ack,
            request,
            head,
            strcmp(head->phase, "STABLE") == 0 ? 1 : 0
        ) != 0) goto cleanup;
    if (ack_state != 0 && ack_state != 2) goto cleanup;
    *request_present = 1;
    result = 0;
cleanup:
    SecureZeroMemory(&transaction, sizeof(transaction));
    SecureZeroMemory(&ack, sizeof(ack));
    if (result != 0) {
        wls_guardian_protocol_unlock(lock);
        SecureZeroMemory(head, sizeof(*head));
        SecureZeroMemory(request, sizeof(*request));
        *request_present = 0;
    }
    return result;
}

static int wls_guardian_protocol_self_test(void)
{
    static const char document[] =
        "WLS-GUARDIAN-SELF-TEST/1\nvalue=42\nsignature="
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n";
    char value[16];
    char signature[65];
    unsigned long long parsed = 0ULL;
    size_t offset = 0U;
    SecureZeroMemory(value, sizeof(value));
    SecureZeroMemory(signature, sizeof(signature));
    if (!wls_guardian_protocol_ascii_leaf(L"guardian-transition.request")
        || wls_guardian_protocol_ascii_leaf(L"..")
        || wls_guardian_protocol_ascii_leaf(L"bad\\leaf")
        || wls_guardian_protocol_decimal("42", &parsed) != 0
        || parsed != 42ULL
        || wls_guardian_protocol_decimal("042", &parsed) == 0
        || wls_guardian_protocol_decimal("", &parsed) == 0
        || wls_guardian_protocol_header(
            document,
            sizeof(document) - 1U,
            &offset,
            "WLS-GUARDIAN-SELF-TEST/1\n"
        ) != 0
        || wls_guardian_protocol_line(
            document,
            sizeof(document) - 1U,
            &offset,
            "value=",
            value,
            sizeof(value)
        ) != 0
        || strcmp(value, "42") != 0
        || wls_guardian_protocol_line(
            document,
            sizeof(document) - 1U,
            &offset,
            "signature=",
            signature,
            sizeof(signature)
        ) != 0
        || wls_guardian_protocol_signature_offset(
            document,
            sizeof(document) - 1U,
            offset,
            signature
        ) != 0) {
        SecureZeroMemory(value, sizeof(value));
        SecureZeroMemory(signature, sizeof(signature));
        return 1;
    }
    SecureZeroMemory(value, sizeof(value));
    SecureZeroMemory(signature, sizeof(signature));
#ifdef WLS_NATIVE_TEST_HOOKS
    if (wls_guardian_protocol_test_home_set(
            L"C:\\WelineGuardianProtocolSelfTest"
        ) != 0
        || !wls_guardian_protocol_home_valid(
            L"C:\\WelineGuardianProtocolSelfTest"
        )) {
        return 1;
    }
#endif
    return 0;
}

#endif /* WLS_GATEWAY_GUARDIAN_PROTOCOL_INC */
