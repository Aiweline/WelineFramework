/*
 * WLS 2.0 Windows Recovery Guardian.
 *
 * This file is included by wls_gateway_launcher.c after the signed-slot and
 * ordinary A/B recovery primitives.  Keeping the implementation in the same
 * translation unit is deliberate: the immutable Guardian and the stable
 * Launcher use one release-audited parser and one set of manifest verifiers,
 * while the Guardian entry point remains a separately sealed executable at
 * guardian\v1\wls-gateway-guardian.exe.
 *
 * The Guardian is the only process allowed to advance the host generation
 * head or the recovery transaction.  A Controller can publish an HMAC-bound
 * request, authorization and inventory, but it cannot manufacture the
 * kernel process handles inherited by the Launcher child and cannot write a
 * Guardian acknowledgement.
 */

#define WLS_GUARDIAN_DOCUMENT_MAX_BYTES 4096U
#define WLS_GUARDIAN_INVENTORY_MAX_BYTES (4U * 1024U * 1024U)
#define WLS_GUARDIAN_MANIFEST_MAX_BYTES (4U * 1024U * 1024U)
#define WLS_GUARDIAN_PROBATION_MILLISECONDS 300000ULL
#define WLS_GUARDIAN_ROLLBACK_OBSERVATION_MILLISECONDS 15000ULL
#define WLS_GUARDIAN_HEALTH_FRESHNESS_MILLISECONDS 15000ULL
#define WLS_GUARDIAN_MAX_DERIVED_ENTRIES 16384U
#define WLS_GUARDIAN_MAX_DERIVED_BYTES 536870912ULL
#define WLS_GUARDIAN_MAX_ACL_BYTES 8192U
#define WLS_GUARDIAN_MAX_TOTAL_ACL_BYTES (2U * 1024U * 1024U)
#define WLS_GUARDIAN_ZERO_64 \
    "0000000000000000000000000000000000000000000000000000000000000000"
#define WLS_GUARDIAN_CONTROLLER_SID \
    "S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626"
#define WLS_GUARDIAN_DATA_PLANE_SID \
    "S-1-5-80-3611316956-1833621424-61377994-3153356469-2496947245"

struct wls_guardian_transition_request {
    char host_id[33];
    char nonce[33];
    unsigned long long expected_head_sequence;
    char expected_head_sha256[65];
    char journal_sha256[65];
    char candidate_generation_id[65];
    char candidate_launcher_sha256[65];
    unsigned long long candidate_launcher_size;
    unsigned long long candidate_launcher_mode;
    char candidate_ca_sha256[65];
    char candidate_runtime_generation[65];
    char recovery_generation_id[65];
    char recovery_launcher_sha256[65];
    unsigned long long recovery_launcher_size;
    unsigned long long recovery_launcher_mode;
    char recovery_ca_sha256[65];
    char recovery_runtime_generation[65];
    char recovery_active_slot[2];
    char recovery_previous_slot[5];
    char recovery_slot_a_generation[65];
    char recovery_slot_b_generation[65];
    char derived_manifest_sha256[65];
    char derived_policy_sha256[65];
    char platform_kind[24];
    char platform_profile[16];
    char platform_definition_sha256[65];
    char platform_metadata_sha256[65];
    int trust_rotation;
    char recovery_inventory_sha256[65];
    char request_binding_sha256[65];
    char recovery_authorization_sha256[65];
    char signature[65];
    char raw_sha256[65];
};

struct wls_guardian_generation_head {
    char host_id[33];
    unsigned long long sequence;
    char phase[32];
    char active_generation_id[65];
    char active_launcher_sha256[65];
    char active_ca_sha256[65];
    char active_runtime_generation[65];
    char recovery_generation_id[65];
    char recovery_nonce[33];
    char recovery_authorization_sha256[65];
    char host_boot_id[65];
    unsigned long long probation_started_monotonic_ms;
    unsigned long long probation_deadline_monotonic_ms;
    char previous_record_sha256[65];
    char signature[65];
    char raw_sha256[65];
    int slot;
};

struct wls_guardian_recovery_transaction {
    char host_id[33];
    char nonce[33];
    char request_sha256[65];
    char authorization_sha256[65];
    char inventory_sha256[65];
    unsigned long long sequence;
    char phase[16];
    unsigned long long cursor;
    char previous_record_sha256[65];
    char signature[65];
    char raw_sha256[65];
};

struct wls_guardian_probation_observation {
    int initialized;
    int reset_existing_probation;
    char identity_sha256[65];
};

struct wls_guardian_health_receipt {
    char host_id[33];
    char host_boot_id[65];
    char active_slot[2];
    char runtime_generation[65];
    char controller_epoch[33];
    unsigned long long controller_generation;
    unsigned long long local_data_plane_generation;
    unsigned long long public_data_plane_generation;
    unsigned long long observed_monotonic_ms;
    unsigned long controller_pid;
    unsigned long long controller_start_id;
    char controller_binary_sha256[65];
    char controller_identity_sha256[65];
    char process_attestation_sha256[65];
    char signature[65];
};

struct wls_guardian_continuity_receipt {
    char host_id[33];
    char host_boot_id[65];
    char admission_state[24];
    char active_slot[2];
    char runtime_generation[65];
    char bootstrap_receipt_sha256[65];
    char guardian_health_sha256[65];
    char controller_epoch[33];
    unsigned long controller_pid;
    unsigned long long controller_start_id;
    char controller_binary_sha256[65];
    char controller_identity_sha256[65];
    unsigned long broker_pid;
    unsigned long long broker_start_id;
    char broker_binary_sha256[65];
    unsigned long data_plane_pid;
    unsigned long long data_plane_start_id;
    char data_plane_binary_sha256[65];
    char process_attestation_sha256[65];
    unsigned long long attested_publication_generation;
    unsigned long long promotion_healthy_since_monotonic_ms;
    unsigned long long issued_unix_ms;
    unsigned long long issued_monotonic_ms;
    char signature[65];
    char raw_sha256[65];
};

enum wls_guardian_continuity_state {
    WLS_GUARDIAN_CONTINUITY_INVALID = 0,
    WLS_GUARDIAN_CONTINUITY_PROMOTION_HEALTHY = 1,
    WLS_GUARDIAN_CONTINUITY_ONLY = 2
};

struct wls_guardian_continuity_sample {
    enum wls_guardian_continuity_state state;
    unsigned long long promotion_healthy_since_monotonic_ms;
    unsigned long long issued_monotonic_ms;
    char raw_sha256[65];
    char observation_identity_sha256[65];
};

struct wls_guardian_pinned_file {
    HANDLE handle;
    BY_HANDLE_FILE_INFORMATION identity;
    FILE_ID_INFO file_id;
    wchar_t final_path[WLS_PATH_CHARS];
};

struct wls_guardian_child_channel {
    HANDLE stop_event;
    HANDLE reload_event;
    HANDLE ready_event;
    HANDLE health_event;
    HANDLE parent_process;
};

struct wls_guardian_child_process {
    PROCESS_INFORMATION process;
    HANDLE job;
    HANDLE stop_event;
    HANDLE reload_event;
    HANDLE ready_event;
    HANDLE health_event;
    char launcher_sha256[65];
    unsigned long long started_monotonic_ms;
    unsigned long long last_health_monotonic_ms;
    int ready;
};

struct wls_guardian_acl_proof {
    PSECURITY_DESCRIPTOR descriptor;
    char canonical[WLS_GUARDIAN_MAX_ACL_BYTES + 1U];
    char owner_sid[SECURITY_MAX_SID_SIZE * 4U];
    char sha256[65];
};

struct wls_guardian_privilege_scope {
    HANDLE token;
    TOKEN_PRIVILEGES previous;
    DWORD previous_bytes;
    int enabled;
};

static HANDLE wls_guardian_service_child_stop_event = NULL;
static HANDLE wls_guardian_service_child_reload_event = NULL;
static HANDLE wls_guardian_child_ready_event = NULL;
static HANDLE wls_guardian_child_health_event = NULL;
static HANDLE wls_guardian_child_parent_process = NULL;
static volatile LONG wls_guardian_bridge_stop = 0;

static int wls_guardian_u64(const char *value, unsigned long long *parsed)
{
    char *end = NULL;
    unsigned long long result;
    if (value == NULL || parsed == NULL || value[0] == '\0'
        || strlen(value) > 20U
        || (value[0] == '0' && value[1] != '\0')) return 1;
    errno = 0;
    result = _strtoui64(value, &end, 10);
    if (errno != 0 || end == value || *end != '\0') return 1;
    *parsed = result;
    return 0;
}

static int wls_guardian_sha256(
    const unsigned char *contents,
    size_t length,
    char digest[65]
) {
    unsigned char binary[crypto_hash_sha256_BYTES];
    int result = 1;
    SecureZeroMemory(binary, sizeof(binary));
    if (contents != NULL && digest != NULL
        && crypto_hash_sha256(
            binary, contents, (unsigned long long)length
        ) == 0
        && sodium_bin2hex(digest, 65U, binary, sizeof(binary)) != NULL
        && wls_is_hex(digest, 64U)) result = 0;
    sodium_memzero(binary, sizeof(binary));
    return result;
}

static int wls_guardian_constant_equal(
    const char *left,
    const char *right,
    size_t length
) {
    return left != NULL && right != NULL
        && strlen(left) == length && strlen(right) == length
        && sodium_memcmp(left, right, length) == 0;
}

static int wls_guardian_admin_key(
    const wchar_t *home,
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES]
) {
    wchar_t path[WLS_PATH_CHARS];
    char contents[66];
    size_t length = 0U;
    size_t decoded = 0U;
    int result = 1;
    SecureZeroMemory(contents, sizeof(contents));
    if (home == NULL || key == NULL
        || wls_join(path, WLS_PATH_CHARS, home, L"trust\\admin.token") != 0
        || wls_recovery_read_controller_trust_file(
            path, contents, sizeof(contents), &length
        ) != 0
        || !((length == 64U) || (length == 65U && contents[64] == '\n'))) {
        goto cleanup;
    }
    contents[64] = '\0';
    if (!wls_is_hex(contents, 64U)
        || sodium_hex2bin(
            key,
            crypto_auth_hmacsha256_KEYBYTES,
            contents,
            64U,
            NULL,
            &decoded,
            NULL
        ) != 0
        || decoded != crypto_auth_hmacsha256_KEYBYTES) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(contents, sizeof(contents));
    sodium_memzero(path, sizeof(path));
    if (result != 0 && key != NULL) {
        sodium_memzero(key, crypto_auth_hmacsha256_KEYBYTES);
    }
    return result;
}

static int wls_guardian_hmac_hex(
    const wchar_t *home,
    const char *contents,
    size_t length,
    char digest[65]
) {
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char binary[crypto_auth_hmacsha256_BYTES];
    int result = 1;
    sodium_memzero(key, sizeof(key));
    sodium_memzero(binary, sizeof(binary));
    if (home != NULL && contents != NULL && digest != NULL
        && wls_guardian_admin_key(home, key) == 0
        && crypto_auth_hmacsha256(
            binary,
            (const unsigned char *)contents,
            (unsigned long long)length,
            key
        ) == 0
        && sodium_bin2hex(digest, 65U, binary, sizeof(binary)) != NULL
        && wls_is_hex(digest, 64U)) result = 0;
    sodium_memzero(key, sizeof(key));
    sodium_memzero(binary, sizeof(binary));
    return result;
}

static int wls_guardian_signed_document_valid(
    const wchar_t *home,
    const char *contents,
    size_t length,
    const char *signature
) {
    char expected[65];
    const char *line = NULL;
    const char *cursor;
    int result = 1;
    SecureZeroMemory(expected, sizeof(expected));
    if (home == NULL || contents == NULL || signature == NULL
        || length < 75U || contents[length] != '\0') {
        goto cleanup;
    }
    cursor = contents;
    while ((cursor = strstr(cursor, "signature=")) != NULL) {
        line = cursor;
        cursor++;
    }
    if (line == NULL
        || line <= contents
        || (size_t)(line - contents) >= length
        || line[-1] != '\n'
        || (size_t)(contents + length - line) != 75U
        || memcmp(line, "signature=", 10U) != 0
        || memcmp(line + 10U, signature, 64U) != 0
        || line[74] != '\n'
        || wls_guardian_hmac_hex(
            home, contents, (size_t)(line - contents), expected
        ) != 0
        || !wls_guardian_constant_equal(expected, signature, 64U)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(expected, sizeof(expected));
    return result;
}

static int wls_guardian_pinned_identity_unchanged(
    const struct wls_guardian_pinned_file *file,
    int directory
) {
    BY_HANDLE_FILE_INFORMATION identity;
    FILE_ID_INFO file_id;
    FILE_ATTRIBUTE_TAG_INFO attributes;
    if (file == NULL || file->handle == NULL
        || file->handle == INVALID_HANDLE_VALUE) return 0;
    ZeroMemory(&identity, sizeof(identity));
    ZeroMemory(&file_id, sizeof(file_id));
    ZeroMemory(&attributes, sizeof(attributes));
    return GetFileInformationByHandle(file->handle, &identity)
        && GetFileInformationByHandleEx(
            file->handle, FileIdInfo, &file_id, sizeof(file_id)
        )
        && GetFileInformationByHandleEx(
            file->handle,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        && (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) == 0U
        && (((attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U)
            == (directory != 0))
        && (directory || identity.nNumberOfLinks == 1U)
        && identity.dwVolumeSerialNumber == file->identity.dwVolumeSerialNumber
        && identity.nFileIndexHigh == file->identity.nFileIndexHigh
        && identity.nFileIndexLow == file->identity.nFileIndexLow
        && identity.nNumberOfLinks == file->identity.nNumberOfLinks
        && identity.nFileSizeHigh == file->identity.nFileSizeHigh
        && identity.nFileSizeLow == file->identity.nFileSizeLow
        && CompareFileTime(
            &identity.ftLastWriteTime, &file->identity.ftLastWriteTime
        ) == 0
        && file_id.VolumeSerialNumber == file->file_id.VolumeSerialNumber
        && sodium_memcmp(
            file_id.FileId.Identifier,
            file->file_id.FileId.Identifier,
            sizeof(file_id.FileId.Identifier)
        ) == 0;
}

static void wls_guardian_pinned_close(struct wls_guardian_pinned_file *file)
{
    if (file == NULL) return;
    if (file->handle != NULL && file->handle != INVALID_HANDLE_VALUE) {
        CloseHandle(file->handle);
    }
    SecureZeroMemory(file, sizeof(*file));
    file->handle = INVALID_HANDLE_VALUE;
}

/* Open without FILE_SHARE_DELETE or FILE_SHARE_WRITE.  The returned handle is
 * intentionally kept live across validation and mutation so a path cannot be
 * exchanged underneath a Guardian transaction. */
static int wls_guardian_open_pinned(
    const wchar_t *path,
    int directory,
    DWORD desired_access,
    struct wls_guardian_pinned_file *file
) {
    FILE_ATTRIBUTE_TAG_INFO attributes;
    wchar_t normalized[WLS_PATH_CHARS];
    const wchar_t *held_path;
    DWORD normalized_length;
    DWORD final_length;
    DWORD forbidden;
    if (path == NULL || file == NULL) return 1;
    SecureZeroMemory(file, sizeof(*file));
    file->handle = CreateFileW(
        path,
        desired_access | FILE_READ_ATTRIBUTES | READ_CONTROL,
        FILE_SHARE_READ,
        NULL,
        OPEN_EXISTING,
        FILE_FLAG_OPEN_REPARSE_POINT
            | (directory ? FILE_FLAG_BACKUP_SEMANTICS : FILE_ATTRIBUTE_NORMAL),
        NULL
    );
    if (file->handle == INVALID_HANDLE_VALUE
        || !GetFileInformationByHandleEx(
            file->handle,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        || !GetFileInformationByHandle(file->handle, &file->identity)
        || !GetFileInformationByHandleEx(
            file->handle,
            FileIdInfo,
            &file->file_id,
            sizeof(file->file_id)
        )) goto failed;
    forbidden = attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT;
    if (forbidden != 0U
        || ((attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY) != 0U)
            != (directory != 0)
        || (!directory && file->identity.nNumberOfLinks != 1U)
        || (file->identity.nFileIndexHigh == 0U
            && file->identity.nFileIndexLow == 0U)) goto failed;
    final_length = GetFinalPathNameByHandleW(
        file->handle,
        file->final_path,
        WLS_PATH_CHARS,
        FILE_NAME_NORMALIZED | VOLUME_NAME_DOS
    );
    if (final_length == 0U || final_length >= WLS_PATH_CHARS) goto failed;
    normalized_length = GetFullPathNameW(
        path, WLS_PATH_CHARS, normalized, NULL
    );
    if (normalized_length < 3U || normalized_length >= WLS_PATH_CHARS) {
        goto failed;
    }
    normalized[0] = (wchar_t)towupper(normalized[0]);
    held_path = file->final_path;
    if (wcsncmp(held_path, L"\\\\?\\", 4U) == 0) held_path += 4U;
    if (wcsncmp(held_path, L"UNC\\", 4U) == 0
        || wcscmp(held_path, normalized) != 0) goto failed;
    return 0;
failed:
    wls_guardian_pinned_close(file);
    return 1;
}

static int wls_guardian_read_pinned(
    const wchar_t *path,
    size_t maximum,
    int controller_readable,
    char **contents,
    size_t *length,
    struct wls_guardian_pinned_file *pin
) {
    LARGE_INTEGER size;
    DWORD used = 0U;
    size_t allocated = 0U;
    int result = 1;
    PSID service_sid = NULL;
    if (contents == NULL || length == NULL || pin == NULL
        || maximum > MAXDWORD) return 1;
    *contents = NULL;
    *length = 0U;
    if (wls_guardian_open_pinned(path, 0, GENERIC_READ, pin) != 0
        || !GetFileSizeEx(pin->handle, &size)
        || size.QuadPart <= 0
        || (unsigned long long)size.QuadPart > maximum
        || size.QuadPart > MAXDWORD) goto cleanup;
    if (controller_readable) {
        if (wls_launcher_gateway_service_sid(&service_sid) != 0
            || wls_launcher_slot_acl_valid(
                pin->handle, 0, service_sid
            ) != 0) goto cleanup;
    } else if (wls_recovery_acl_safe(pin->handle, 0) != 0) {
        goto cleanup;
    }
    allocated = (size_t)size.QuadPart + 1U;
    *contents = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
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
            )
            || amount == 0U) goto cleanup;
        used += amount;
    }
    if (memchr(*contents, '\0', used) != NULL
        || !wls_guardian_pinned_identity_unchanged(pin, 0)) goto cleanup;
    (*contents)[used] = '\0';
    *length = (size_t)used;
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    if (result != 0) {
        if (*contents != NULL) {
            SecureZeroMemory(*contents, allocated);
            HeapFree(GetProcessHeap(), 0U, *contents);
            *contents = NULL;
        }
        *length = 0U;
        wls_guardian_pinned_close(pin);
    }
    return result;
}

static int wls_guardian_handle_sddl_exact(
    HANDLE object,
    const char *expected_sddl
) {
    PSECURITY_DESCRIPTOR expected = NULL;
    PSECURITY_DESCRIPTOR observed = NULL;
    LPSTR expected_canonical = NULL;
    LPSTR observed_canonical = NULL;
    SECURITY_DESCRIPTOR_CONTROL control = 0U;
    DWORD revision = 0U;
    int result = 1;
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || expected_sddl == NULL || expected_sddl[0] == '\0'
        || !ConvertStringSecurityDescriptorToSecurityDescriptorA(
            expected_sddl, SDDL_REVISION_1, &expected, NULL
        )
        || GetSecurityInfo(
            object,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            NULL,
            NULL,
            NULL,
            NULL,
            &observed
        ) != ERROR_SUCCESS
        || observed == NULL
        || !GetSecurityDescriptorControl(
            observed, &control, &revision
        )
        || (control & SE_DACL_PROTECTED) == 0U
        || !ConvertSecurityDescriptorToStringSecurityDescriptorA(
            expected,
            SDDL_REVISION_1,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &expected_canonical,
            NULL
        )
        || !ConvertSecurityDescriptorToStringSecurityDescriptorA(
            observed,
            SDDL_REVISION_1,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &observed_canonical,
            NULL
        )
        || strcmp(expected_canonical, observed_canonical) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (observed_canonical != NULL) LocalFree(observed_canonical);
    if (expected_canonical != NULL) LocalFree(expected_canonical);
    if (observed != NULL) LocalFree(observed);
    if (expected != NULL) LocalFree(expected);
    return result;
}

static int wls_guardian_read_pinned_sddl(
    const wchar_t *path,
    size_t maximum,
    const char *expected_sddl,
    char **contents,
    size_t *length,
    struct wls_guardian_pinned_file *pin
) {
    LARGE_INTEGER size;
    DWORD used = 0U;
    size_t allocated = 0U;
    int result = 1;
    if (path == NULL || expected_sddl == NULL || contents == NULL
        || length == NULL || pin == NULL || maximum == 0U
        || maximum > MAXDWORD) return 1;
    *contents = NULL;
    *length = 0U;
    if (wls_guardian_open_pinned(path, 0, GENERIC_READ, pin) != 0
        || wls_guardian_handle_sddl_exact(
            pin->handle, expected_sddl
        ) != 0
        || !GetFileSizeEx(pin->handle, &size)
        || size.QuadPart <= 0
        || (unsigned long long)size.QuadPart > maximum
        || size.QuadPart > MAXDWORD) goto cleanup;
    allocated = (size_t)size.QuadPart + 1U;
    *contents = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
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
            )
            || amount == 0U) goto cleanup;
        used += amount;
    }
    if (memchr(*contents, '\0', used) != NULL
        || !wls_guardian_pinned_identity_unchanged(pin, 0)
        || wls_guardian_handle_sddl_exact(
            pin->handle, expected_sddl
        ) != 0) goto cleanup;
    (*contents)[used] = '\0';
    *length = (size_t)used;
    result = 0;
cleanup:
    if (result != 0) {
        if (*contents != NULL) {
            SecureZeroMemory(*contents, allocated);
            HeapFree(GetProcessHeap(), 0U, *contents);
            *contents = NULL;
        }
        *length = 0U;
        wls_guardian_pinned_close(pin);
    }
    return result;
}

static int wls_guardian_pinned_reread(
    struct wls_guardian_pinned_file *pin,
    size_t expected_length,
    char **contents
) {
    LARGE_INTEGER origin;
    DWORD used = 0U;
    size_t allocated;
    int result = 1;
    if (pin == NULL || pin->handle == NULL
        || pin->handle == INVALID_HANDLE_VALUE || contents == NULL
        || expected_length == 0U || expected_length > MAXDWORD) return 1;
    *contents = NULL;
    allocated = expected_length + 1U;
    *contents = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
    );
    if (*contents == NULL) return 1;
    origin.QuadPart = 0;
    if (!SetFilePointerEx(pin->handle, origin, NULL, FILE_BEGIN)) {
        goto cleanup;
    }
    while (used < (DWORD)expected_length) {
        DWORD amount = 0U;
        if (!ReadFile(
                pin->handle,
                *contents + used,
                (DWORD)expected_length - used,
                &amount,
                NULL
            )
            || amount == 0U) goto cleanup;
        used += amount;
    }
    {
        unsigned char extra = 0U;
        DWORD amount = 0U;
        if (!ReadFile(pin->handle, &extra, 1U, &amount, NULL)
            || amount != 0U
            || memchr(*contents, '\0', expected_length) != NULL
            || !wls_guardian_pinned_identity_unchanged(pin, 0)) {
            goto cleanup;
        }
    }
    (*contents)[expected_length] = '\0';
    result = 0;
cleanup:
    if (result != 0 && *contents != NULL) {
        SecureZeroMemory(*contents, allocated);
        HeapFree(GetProcessHeap(), 0U, *contents);
        *contents = NULL;
    }
    return result;
}

static void wls_guardian_contents_free(char **contents, size_t length)
{
    if (contents == NULL || *contents == NULL) return;
    SecureZeroMemory(*contents, length + 1U);
    HeapFree(GetProcessHeap(), 0U, *contents);
    *contents = NULL;
}

static int wls_guardian_monotonic(unsigned long long *milliseconds)
{
    return milliseconds != NULL
        && wls_protocol_monotonic_milliseconds(milliseconds) == 0
        && *milliseconds > 0ULL ? 0 : 1;
}

static int wls_guardian_generation_id(
    const char *launcher,
    const char *ca,
    const char *runtime,
    char generation[65]
) {
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    static const char prefix[] = "wls-guardian-active-generation/1";
    unsigned char zero = 0U;
    int result = 1;
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    if (launcher == NULL || ca == NULL || runtime == NULL || generation == NULL
        || !wls_is_hex(launcher, 64U) || !wls_is_hex(ca, 64U)
        || !wls_is_hex(runtime, 64U)
        || crypto_hash_sha256_init(&state) != 0
        || crypto_hash_sha256_update(
            &state,
            (const unsigned char *)prefix,
            sizeof(prefix) - 1U
        ) != 0
        || crypto_hash_sha256_update(&state, &zero, 1U) != 0
        || crypto_hash_sha256_update(
            &state, (const unsigned char *)launcher, 64U
        ) != 0
        || crypto_hash_sha256_update(&state, &zero, 1U) != 0
        || crypto_hash_sha256_update(
            &state, (const unsigned char *)ca, 64U
        ) != 0
        || crypto_hash_sha256_update(&state, &zero, 1U) != 0
        || crypto_hash_sha256_update(
            &state, (const unsigned char *)runtime, 64U
        ) != 0
        || crypto_hash_sha256_final(&state, binary) != 0
        || sodium_bin2hex(generation, 65U, binary, sizeof(binary)) == NULL) {
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    if (result != 0 && generation != NULL) sodium_memzero(generation, 65U);
    return result;
}

static int wls_guardian_digest_handle(
    struct wls_guardian_pinned_file *file,
    unsigned long long maximum,
    char digest[65],
    unsigned long long *size_out
) {
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    unsigned char buffer[65536];
    LARGE_INTEGER origin;
    DWORD amount = 0U;
    unsigned long long total = 0ULL;
    int result = 1;
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    sodium_memzero(buffer, sizeof(buffer));
    origin.QuadPart = 0;
    if (file == NULL || file->handle == NULL
        || file->handle == INVALID_HANDLE_VALUE || digest == NULL
        || maximum == 0ULL || !SetFilePointerEx(
            file->handle, origin, NULL, FILE_BEGIN
        ) || crypto_hash_sha256_init(&state) != 0) goto cleanup;
    for (;;) {
        if (!ReadFile(
                file->handle, buffer, sizeof(buffer), &amount, NULL
            )) goto cleanup;
        if (amount == 0U) break;
        if (total > maximum - amount
            || crypto_hash_sha256_update(&state, buffer, amount) != 0) {
            goto cleanup;
        }
        total += amount;
    }
    if (!wls_guardian_pinned_identity_unchanged(file, 0)
        || crypto_hash_sha256_final(&state, binary) != 0
        || sodium_bin2hex(digest, 65U, binary, sizeof(binary)) == NULL
        || !wls_is_hex(digest, 64U)) goto cleanup;
    if (size_out != NULL) *size_out = total;
    result = 0;
cleanup:
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    sodium_memzero(buffer, sizeof(buffer));
    if (result != 0) {
        if (digest != NULL) sodium_memzero(digest, 65U);
        if (size_out != NULL) *size_out = 0ULL;
    }
    return result;
}

static int wls_guardian_resolve_production_home(
    wchar_t home[WLS_PATH_CHARS]
) {
    static const GUID program_data = {
        0x62AB5D82U,
        0xFDC1U,
        0x4DC3U,
        {0xA9U, 0xDDU, 0x07U, 0x0DU, 0x1DU, 0x49U, 0x5DU, 0x97U}
    };
    PWSTR resolved = NULL;
    wchar_t normalized[WLS_PATH_CHARS];
    wchar_t drive[4];
    DWORD length;
    HRESULT status;
    int result = 1;
    ZeroMemory(normalized, sizeof(normalized));
    ZeroMemory(drive, sizeof(drive));
    if (home == NULL) return 1;
    home[0] = L'\0';
    status = SHGetKnownFolderPath(&program_data, 0U, NULL, &resolved);
    if (FAILED(status) || resolved == NULL) goto cleanup;
    length = GetFullPathNameW(
        resolved, WLS_PATH_CHARS, normalized, NULL
    );
    if (length < 3U || length >= WLS_PATH_CHARS
        || normalized[1] != L':'
        || (normalized[2] != L'\\' && normalized[2] != L'/')
        || wcschr(normalized + 2U, L':') != NULL) goto cleanup;
    normalized[0] = (wchar_t)towupper(normalized[0]);
    drive[0] = normalized[0];
    drive[1] = L':';
    drive[2] = L'\\';
    if (GetDriveTypeW(drive) != DRIVE_FIXED) goto cleanup;
    while (length > 3U
        && (normalized[length - 1U] == L'\\'
            || normalized[length - 1U] == L'/')) {
        normalized[--length] = L'\0';
    }
    if (_snwprintf_s(
            home,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\Weline\\Gateway",
            normalized
        ) < 0) goto cleanup;
    result = 0;
cleanup:
    if (resolved != NULL) CoTaskMemFree(resolved);
    SecureZeroMemory(normalized, sizeof(normalized));
    SecureZeroMemory(drive, sizeof(drive));
    if (result != 0) SecureZeroMemory(home, WLS_PATH_CHARS * sizeof(wchar_t));
    return result;
}

static int wls_guardian_fixed_runtime_paths(
    const wchar_t *requested_home,
    const wchar_t *requested_run,
    wchar_t home[WLS_PATH_CHARS],
    wchar_t run_directory[WLS_PATH_CHARS]
) {
    wchar_t normalized_home[WLS_PATH_CHARS];
    wchar_t normalized_run[WLS_PATH_CHARS];
    DWORD home_length;
    DWORD run_length;
    int result = 1;
    ZeroMemory(normalized_home, sizeof(normalized_home));
    ZeroMemory(normalized_run, sizeof(normalized_run));
    if (requested_home == NULL || requested_run == NULL || home == NULL
        || run_directory == NULL
        || wls_guardian_resolve_production_home(home) != 0) goto cleanup;
    home_length = GetFullPathNameW(
        requested_home, WLS_PATH_CHARS, normalized_home, NULL
    );
    run_length = GetFullPathNameW(
        requested_run, WLS_PATH_CHARS, normalized_run, NULL
    );
    if (home_length < 3U || home_length >= WLS_PATH_CHARS
        || run_length < 3U || run_length >= WLS_PATH_CHARS
        || wcscmp(normalized_home, home) != 0
        || wls_join(
            run_directory, WLS_PATH_CHARS, home, L"runtime\\run"
        ) != 0
        || wcscmp(normalized_run, run_directory) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(normalized_home, sizeof(normalized_home));
    SecureZeroMemory(normalized_run, sizeof(normalized_run));
    if (result != 0) {
        SecureZeroMemory(home, WLS_PATH_CHARS * sizeof(wchar_t));
        SecureZeroMemory(
            run_directory, WLS_PATH_CHARS * sizeof(wchar_t)
        );
    }
    return result;
}

static int wls_guardian_immutable_identity(
    const wchar_t *home,
    wchar_t guardian[WLS_PATH_CHARS],
    char guardian_digest[65]
) {
    wchar_t image[WLS_PATH_CHARS];
    wchar_t identity_path[WLS_PATH_CHARS];
    char *identity = NULL;
    size_t identity_length = 0U;
    struct wls_guardian_pinned_file identity_pin;
    struct wls_guardian_pinned_file guardian_pin;
    unsigned long long guardian_size = 0ULL;
    DWORD image_length;
    int result = 1;
    ZeroMemory(image, sizeof(image));
    ZeroMemory(identity_path, sizeof(identity_path));
    ZeroMemory(&identity_pin, sizeof(identity_pin));
    ZeroMemory(&guardian_pin, sizeof(guardian_pin));
    identity_pin.handle = INVALID_HANDLE_VALUE;
    guardian_pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || guardian == NULL || guardian_digest == NULL
        || wls_join(
            guardian,
            WLS_PATH_CHARS,
            home,
            L"guardian\\v1\\wls-gateway-guardian.exe"
        ) != 0
        || wls_join(
            identity_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\guardian.sha256"
        ) != 0) goto cleanup;
    image_length = GetModuleFileNameW(NULL, image, WLS_PATH_CHARS);
    if (image_length == 0U || image_length >= WLS_PATH_CHARS
        || wcscmp(image, guardian) != 0
        || wls_guardian_read_pinned(
            identity_path,
            65U,
            0,
            &identity,
            &identity_length,
            &identity_pin
        ) != 0
        || !((identity_length == 64U)
            || (identity_length == 65U && identity[64] == '\n'))
        || !wls_is_hex(identity, 64U)) goto cleanup;
    identity[64] = '\0';
    if (wls_guardian_open_pinned(
            guardian, 0, GENERIC_READ, &guardian_pin
        ) != 0) goto cleanup;
    /* guardian/v1 is immutable but Controller-readable; enforce the exact
     * installed file profile before trusting its pinned identity. */
    {
        PSID service_sid = NULL;
        if (wls_launcher_gateway_service_sid(&service_sid) != 0
            || wls_launcher_slot_acl_valid(
                guardian_pin.handle, 0, service_sid
            ) != 0
            || wls_guardian_digest_handle(
                &guardian_pin,
                536870912ULL,
                guardian_digest,
                &guardian_size
            ) != 0
            || guardian_size == 0ULL
            || !wls_guardian_constant_equal(
                identity, guardian_digest, 64U
            )) {
            if (service_sid != NULL) LocalFree(service_sid);
            goto cleanup;
        }
        LocalFree(service_sid);
    }
    result = 0;
cleanup:
    if (identity != NULL) {
        wls_guardian_contents_free(&identity, identity_length);
    }
    wls_guardian_pinned_close(&identity_pin);
    wls_guardian_pinned_close(&guardian_pin);
    SecureZeroMemory(image, sizeof(image));
    SecureZeroMemory(identity_path, sizeof(identity_path));
    if (result != 0) {
        SecureZeroMemory(guardian, WLS_PATH_CHARS * sizeof(wchar_t));
        sodium_memzero(guardian_digest, 65U);
    }
    return result;
}

static int wls_run_supervisor(
    const wchar_t *home,
    const wchar_t *run_directory
);

static int wls_guardian_wide_handle(
    const wchar_t *value,
    HANDLE *handle
) {
    wchar_t *end = NULL;
    unsigned long long parsed;
    if (value == NULL || handle == NULL || value[0] == L'\0'
        || value[0] == L'0' || wcslen(value) > 20U) return 1;
    errno = 0;
    parsed = _wcstoui64(value, &end, 10);
    if (errno != 0 || end == value || *end != L'\0'
        || parsed == 0ULL || parsed > (unsigned long long)UINTPTR_MAX) {
        return 1;
    }
    *handle = (HANDLE)(uintptr_t)parsed;
    return *handle == INVALID_HANDLE_VALUE ? 1 : 0;
}

static int wls_guardian_child_parent_valid(
    const wchar_t *home,
    HANDLE parent_process
) {
    wchar_t expected[WLS_PATH_CHARS];
    wchar_t actual[WLS_PATH_CHARS];
    wchar_t digest_path[WLS_PATH_CHARS];
    char *declared = NULL;
    size_t declared_length = 0U;
    char actual_digest[65];
    struct wls_guardian_pinned_file digest_pin;
    struct wls_guardian_pinned_file binary_pin;
    unsigned long long size = 0ULL;
    DWORD actual_length = WLS_PATH_CHARS;
    PSID service_sid = NULL;
    int result = 1;
    ZeroMemory(expected, sizeof(expected));
    ZeroMemory(actual, sizeof(actual));
    ZeroMemory(digest_path, sizeof(digest_path));
    ZeroMemory(&digest_pin, sizeof(digest_pin));
    ZeroMemory(&binary_pin, sizeof(binary_pin));
    digest_pin.handle = INVALID_HANDLE_VALUE;
    binary_pin.handle = INVALID_HANDLE_VALUE;
    sodium_memzero(actual_digest, sizeof(actual_digest));
    if (home == NULL || parent_process == NULL
        || parent_process == INVALID_HANDLE_VALUE
        || GetProcessId(parent_process) == 0U
        || WaitForSingleObject(parent_process, 0U) != WAIT_TIMEOUT
        || !QueryFullProcessImageNameW(
            parent_process, 0U, actual, &actual_length
        )
        || actual_length == 0U || actual_length >= WLS_PATH_CHARS
        || wls_join(
            expected,
            WLS_PATH_CHARS,
            home,
            L"guardian\\v1\\wls-gateway-guardian.exe"
        ) != 0
        || wcscmp(actual, expected) != 0
        || wls_join(
            digest_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\guardian.sha256"
        ) != 0
        || wls_guardian_read_pinned(
            digest_path,
            65U,
            0,
            &declared,
            &declared_length,
            &digest_pin
        ) != 0
        || !((declared_length == 64U)
            || (declared_length == 65U && declared[64] == '\n'))
        || !wls_is_hex(declared, 64U)
        || wls_guardian_open_pinned(
            expected, 0, GENERIC_READ, &binary_pin
        ) != 0
        || wls_launcher_gateway_service_sid(&service_sid) != 0
        || wls_launcher_slot_acl_valid(
            binary_pin.handle, 0, service_sid
        ) != 0
        || wls_guardian_digest_handle(
            &binary_pin,
            536870912ULL,
            actual_digest,
            &size
        ) != 0
        || size == 0ULL
        || sodium_memcmp(declared, actual_digest, 64U) != 0) goto cleanup;
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    if (declared != NULL) {
        wls_guardian_contents_free(&declared, declared_length);
    }
    wls_guardian_pinned_close(&digest_pin);
    wls_guardian_pinned_close(&binary_pin);
    SecureZeroMemory(expected, sizeof(expected));
    SecureZeroMemory(actual, sizeof(actual));
    SecureZeroMemory(digest_path, sizeof(digest_path));
    sodium_memzero(actual_digest, sizeof(actual_digest));
    return result;
}

static DWORD WINAPI wls_guardian_child_bridge(LPVOID context)
{
    HANDLE handles[3];
    DWORD wait_result;
    (void)context;
    handles[0] = wls_guardian_child_parent_process;
    handles[1] = wls_guardian_service_child_stop_event;
    handles[2] = wls_guardian_service_child_reload_event;
    for (;;) {
        wait_result = WaitForMultipleObjects(3U, handles, FALSE, 1000U);
        if (InterlockedCompareExchange(&wls_guardian_bridge_stop, 0, 0)
            != 0) return 0U;
        if (wait_result == WAIT_OBJECT_0
            || wait_result == WAIT_OBJECT_0 + 1U) {
            InterlockedExchange(&wls_service_stop_requested, 1);
            wls_signal_broker_stop_event();
            return 0U;
        }
        if (wait_result == WAIT_OBJECT_0 + 2U) {
            (void)ResetEvent(wls_guardian_service_child_reload_event);
            InterlockedIncrement(&wls_service_reload_generation);
            wls_signal_broker_stop_event();
            continue;
        }
        if (wait_result != WAIT_TIMEOUT) {
            InterlockedExchange(&wls_service_stop_requested, 1);
            wls_signal_broker_stop_event();
            return 1U;
        }
    }
}

static int wls_guardian_child_command(int argc, wchar_t **argv)
{
    static const wchar_t *names[] = {
        L"--home=",
        L"--run=",
        L"--guardian-parent-handle=",
        L"--guardian-stop-handle=",
        L"--guardian-reload-handle=",
        L"--guardian-ready-handle=",
        L"--guardian-health-handle=",
    };
    wchar_t home[WLS_PATH_CHARS];
    wchar_t run_directory[WLS_PATH_CHARS];
    const wchar_t *values[sizeof(names) / sizeof(names[0])];
    HANDLE parent_process = NULL;
    HANDLE stop_event = NULL;
    HANDLE reload_event = NULL;
    HANDLE ready_event = NULL;
    HANDLE health_event = NULL;
    HANDLE bridge = NULL;
    DWORD bridge_exit = 1U;
    size_t index;
    int result = 1;
    ZeroMemory(home, sizeof(home));
    ZeroMemory(run_directory, sizeof(run_directory));
    ZeroMemory(values, sizeof(values));
    if (argc != 2 + (int)(sizeof(names) / sizeof(names[0]))
        || argv == NULL || wcscmp(argv[1], L"--guardian-child") != 0) {
        return 64;
    }
    for (index = 0U; index < sizeof(names) / sizeof(names[0]); index++) {
        size_t name_length = wcslen(names[index]);
        if (wcsncmp(argv[index + 2U], names[index], name_length) != 0
            || argv[index + 2U][name_length] == L'\0') return 64;
        values[index] = argv[index + 2U] + name_length;
    }
    if (wls_guardian_fixed_runtime_paths(
            values[0], values[1], home, run_directory
        ) != 0
        || wls_guardian_wide_handle(values[2], &parent_process) != 0
        || wls_guardian_wide_handle(values[3], &stop_event) != 0
        || wls_guardian_wide_handle(values[4], &reload_event) != 0
        || wls_guardian_wide_handle(values[5], &ready_event) != 0
        || wls_guardian_wide_handle(values[6], &health_event) != 0
        || parent_process == stop_event || parent_process == reload_event
        || parent_process == ready_event || parent_process == health_event
        || stop_event == reload_event || stop_event == ready_event
        || stop_event == health_event || reload_event == ready_event
        || reload_event == health_event || ready_event == health_event
        || wls_guardian_child_parent_valid(home, parent_process) != 0
        || WaitForSingleObject(stop_event, 0U) != WAIT_TIMEOUT
        || WaitForSingleObject(reload_event, 0U) != WAIT_TIMEOUT
        || WaitForSingleObject(ready_event, 0U) != WAIT_TIMEOUT
        || WaitForSingleObject(health_event, 0U) != WAIT_TIMEOUT) goto cleanup;
    wls_guardian_child_parent_process = parent_process;
    wls_guardian_service_child_stop_event = stop_event;
    wls_guardian_service_child_reload_event = reload_event;
    wls_guardian_child_ready_event = ready_event;
    wls_guardian_child_health_event = health_event;
    InterlockedExchange(&wls_guardian_bridge_stop, 0);
    bridge = CreateThread(
        NULL, 0U, wls_guardian_child_bridge, NULL, 0U, NULL
    );
    if (bridge == NULL || wls_initialize_service_generation() != 0) {
        goto cleanup;
    }
    result = wls_run_supervisor(home, run_directory);
cleanup:
    InterlockedExchange(&wls_guardian_bridge_stop, 1);
    if (stop_event != NULL && stop_event != INVALID_HANDLE_VALUE) {
        (void)SetEvent(stop_event);
    }
    if (bridge != NULL) {
        if (WaitForSingleObject(bridge, 5000U) == WAIT_OBJECT_0) {
            (void)GetExitCodeThread(bridge, &bridge_exit);
        }
        CloseHandle(bridge);
        if (bridge_exit != 0U && result == 0) result = 1;
    }
    wls_guardian_child_parent_process = NULL;
    wls_guardian_service_child_stop_event = NULL;
    wls_guardian_service_child_reload_event = NULL;
    wls_guardian_child_ready_event = NULL;
    wls_guardian_child_health_event = NULL;
    if (parent_process != NULL && parent_process != INVALID_HANDLE_VALUE) {
        CloseHandle(parent_process);
    }
    if (stop_event != NULL && stop_event != INVALID_HANDLE_VALUE) {
        CloseHandle(stop_event);
    }
    if (reload_event != NULL && reload_event != INVALID_HANDLE_VALUE) {
        CloseHandle(reload_event);
    }
    if (ready_event != NULL && ready_event != INVALID_HANDLE_VALUE) {
        CloseHandle(ready_event);
    }
    if (health_event != NULL && health_event != INVALID_HANDLE_VALUE) {
        CloseHandle(health_event);
    }
    SecureZeroMemory(home, sizeof(home));
    SecureZeroMemory(run_directory, sizeof(run_directory));
    return result;
}

static void wls_guardian_child_process_close(
    struct wls_guardian_child_process *child
) {
    if (child == NULL) return;
    if (child->process.hThread != NULL) CloseHandle(child->process.hThread);
    if (child->process.hProcess != NULL) CloseHandle(child->process.hProcess);
    if (child->job != NULL) CloseHandle(child->job);
    if (child->stop_event != NULL) CloseHandle(child->stop_event);
    if (child->reload_event != NULL) CloseHandle(child->reload_event);
    if (child->ready_event != NULL) CloseHandle(child->ready_event);
    if (child->health_event != NULL) CloseHandle(child->health_event);
    SecureZeroMemory(child, sizeof(*child));
}

static int wls_guardian_stable_launcher_pin(
    const wchar_t *home,
    const char *expected_digest,
    wchar_t launcher[WLS_PATH_CHARS],
    struct wls_guardian_pinned_file *pin
) {
    char digest[65];
    unsigned long long size = 0ULL;
    PSID service_sid = NULL;
    int result = 1;
    sodium_memzero(digest, sizeof(digest));
    if (home == NULL || expected_digest == NULL || launcher == NULL
        || pin == NULL || !wls_is_hex(expected_digest, 64U)
        || wls_join(
            launcher,
            WLS_PATH_CHARS,
            home,
            L"bin\\wls-gateway-launcher.exe"
        ) != 0
        || wls_guardian_open_pinned(
            launcher, 0, GENERIC_READ, pin
        ) != 0
        || wls_launcher_gateway_service_sid(&service_sid) != 0
        || wls_launcher_slot_acl_valid(
            pin->handle, 0, service_sid
        ) != 0
        || wls_guardian_digest_handle(
            pin, 536870912ULL, digest, &size
        ) != 0
        || size == 0ULL
        || sodium_memcmp(digest, expected_digest, 64U) != 0) goto cleanup;
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    sodium_memzero(digest, sizeof(digest));
    if (result != 0) {
        wls_guardian_pinned_close(pin);
        SecureZeroMemory(launcher, WLS_PATH_CHARS * sizeof(wchar_t));
    }
    return result;
}

static int wls_guardian_child_command_line(
    const wchar_t *launcher,
    const wchar_t *home,
    const wchar_t *run_directory,
    HANDLE parent_process,
    HANDLE stop_event,
    HANDLE reload_event,
    HANDLE ready_event,
    HANDLE health_event,
    wchar_t *command,
    size_t capacity
) {
    HANDLE handles[5];
    size_t left;
    size_t right;
    int written;
    handles[0] = parent_process;
    handles[1] = stop_event;
    handles[2] = reload_event;
    handles[3] = ready_event;
    handles[4] = health_event;
    if (launcher == NULL || home == NULL || run_directory == NULL
        || command == NULL || capacity == 0U
        || launcher[0] == L'\0' || home[0] == L'\0'
        || run_directory[0] == L'\0'
        || wcschr(launcher, L'"') != NULL
        || wcschr(home, L'"') != NULL
        || wcschr(run_directory, L'"') != NULL) return 1;
    for (left = 0U; left < 5U; ++left) {
        if (handles[left] == NULL || handles[left] == INVALID_HANDLE_VALUE) {
            return 1;
        }
        for (right = left + 1U; right < 5U; ++right) {
            if (handles[left] == handles[right]) return 1;
        }
    }
    written = _snwprintf_s(
        command,
        capacity,
        _TRUNCATE,
        L"\"%ls\" --guardian-child --home=\"%ls\" --run=\"%ls\" "
        L"--guardian-parent-handle=%llu --guardian-stop-handle=%llu "
        L"--guardian-reload-handle=%llu --guardian-ready-handle=%llu "
        L"--guardian-health-handle=%llu",
        launcher,
        home,
        run_directory,
        (unsigned long long)(uintptr_t)parent_process,
        (unsigned long long)(uintptr_t)stop_event,
        (unsigned long long)(uintptr_t)reload_event,
        (unsigned long long)(uintptr_t)ready_event,
        (unsigned long long)(uintptr_t)health_event
    );
    return written < 0 || (size_t)written >= capacity || written >= 32767
        ? 1 : 0;
}

static int wls_guardian_service_command_line(
    const wchar_t *home,
    const wchar_t *run_directory,
    const wchar_t *guardian,
    wchar_t *command,
    size_t capacity
) {
    int written;
    if (home == NULL || run_directory == NULL || guardian == NULL
        || command == NULL || capacity == 0U
        || home[0] == L'\0' || run_directory[0] == L'\0'
        || guardian[0] == L'\0'
        || wcschr(home, L'"') != NULL
        || wcschr(run_directory, L'"') != NULL
        || wcschr(guardian, L'"') != NULL) return 1;
    written = _snwprintf_s(
        command,
        capacity,
        _TRUNCATE,
        L"\"%ls\" --service --home=\"%ls\" --run=\"%ls\"",
        guardian,
        home,
        run_directory
    );
    return written < 0 || (size_t)written >= capacity || written >= 32767
        ? 1 : 0;
}

static int wls_guardian_child_spawn(
    const wchar_t *home,
    const wchar_t *run_directory,
    const char *expected_launcher_sha256,
    struct wls_guardian_child_process *child
) {
    wchar_t launcher[WLS_PATH_CHARS];
    wchar_t command[WLS_PATH_CHARS * 2U];
    wchar_t actual[WLS_PATH_CHARS];
    struct wls_guardian_pinned_file launcher_pin;
    STARTUPINFOEXW startup;
    SIZE_T attribute_bytes = 0U;
    HANDLE inherited[5];
    HANDLE parent_process = NULL;
    SECURITY_ATTRIBUTES inheritable;
    JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits;
    DWORD actual_length = WLS_PATH_CHARS;
    unsigned long long started = 0ULL;
    int result = 1;
    ZeroMemory(launcher, sizeof(launcher));
    ZeroMemory(command, sizeof(command));
    ZeroMemory(actual, sizeof(actual));
    ZeroMemory(&launcher_pin, sizeof(launcher_pin));
    launcher_pin.handle = INVALID_HANDLE_VALUE;
    ZeroMemory(&startup, sizeof(startup));
    ZeroMemory(&inheritable, sizeof(inheritable));
    ZeroMemory(&limits, sizeof(limits));
    if (home == NULL || run_directory == NULL
        || expected_launcher_sha256 == NULL || child == NULL
        || wcschr(home, L'"') != NULL || wcschr(run_directory, L'"') != NULL) {
        return 1;
    }
    SecureZeroMemory(child, sizeof(*child));
    inheritable.nLength = sizeof(inheritable);
    inheritable.bInheritHandle = TRUE;
    child->stop_event = CreateEventW(&inheritable, TRUE, FALSE, NULL);
    child->reload_event = CreateEventW(&inheritable, TRUE, FALSE, NULL);
    child->ready_event = CreateEventW(&inheritable, TRUE, FALSE, NULL);
    child->health_event = CreateEventW(&inheritable, FALSE, FALSE, NULL);
    if (child->stop_event == NULL || child->reload_event == NULL
        || child->ready_event == NULL || child->health_event == NULL
        || !DuplicateHandle(
            GetCurrentProcess(),
            GetCurrentProcess(),
            GetCurrentProcess(),
            &parent_process,
            SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION,
            TRUE,
            0U
        )
        || wls_guardian_stable_launcher_pin(
            home,
            expected_launcher_sha256,
            launcher,
            &launcher_pin
        ) != 0
        || wls_guardian_child_command_line(
            launcher,
            home,
            run_directory,
            parent_process,
            child->stop_event,
            child->reload_event,
            child->ready_event,
            child->health_event,
            command,
            sizeof(command) / sizeof(command[0])
        ) != 0) goto cleanup;
    child->job = CreateJobObjectW(NULL, NULL);
    limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
    if (child->job == NULL || !SetInformationJobObject(
            child->job,
            JobObjectExtendedLimitInformation,
            &limits,
            sizeof(limits)
        )) goto cleanup;
    inherited[0] = parent_process;
    inherited[1] = child->stop_event;
    inherited[2] = child->reload_event;
    inherited[3] = child->ready_event;
    inherited[4] = child->health_event;
    (void)InitializeProcThreadAttributeList(NULL, 1U, 0U, &attribute_bytes);
    if (attribute_bytes == 0U || GetLastError() != ERROR_INSUFFICIENT_BUFFER) {
        goto cleanup;
    }
    startup.lpAttributeList = (LPPROC_THREAD_ATTRIBUTE_LIST)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, attribute_bytes
    );
    if (startup.lpAttributeList == NULL
        || !InitializeProcThreadAttributeList(
            startup.lpAttributeList, 1U, 0U, &attribute_bytes
        )
        || !UpdateProcThreadAttribute(
            startup.lpAttributeList,
            0U,
            PROC_THREAD_ATTRIBUTE_HANDLE_LIST,
            inherited,
            sizeof(inherited),
            NULL,
            NULL
        )) goto cleanup;
    startup.StartupInfo.cb = sizeof(startup);
    if (!CreateProcessW(
            launcher,
            command,
            NULL,
            NULL,
            TRUE,
            CREATE_NO_WINDOW | CREATE_SUSPENDED
                | EXTENDED_STARTUPINFO_PRESENT,
            NULL,
            NULL,
            &startup.StartupInfo,
            &child->process
        )
        || !AssignProcessToJobObject(child->job, child->process.hProcess)
        || !QueryFullProcessImageNameW(
            child->process.hProcess, 0U, actual, &actual_length
        )
        || actual_length == 0U || actual_length >= WLS_PATH_CHARS
        || wcscmp(actual, launcher) != 0
        || !wls_guardian_pinned_identity_unchanged(&launcher_pin, 0)
        || ResumeThread(child->process.hThread) == (DWORD)-1
        || wls_guardian_monotonic(&started) != 0) goto cleanup;
    memcpy(
        child->launcher_sha256,
        expected_launcher_sha256,
        sizeof(child->launcher_sha256)
    );
    child->started_monotonic_ms = started;
    result = 0;
cleanup:
    if (startup.lpAttributeList != NULL) {
        DeleteProcThreadAttributeList(startup.lpAttributeList);
        SecureZeroMemory(startup.lpAttributeList, attribute_bytes);
        HeapFree(GetProcessHeap(), 0U, startup.lpAttributeList);
    }
    if (parent_process != NULL) CloseHandle(parent_process);
    wls_guardian_pinned_close(&launcher_pin);
    SecureZeroMemory(launcher, sizeof(launcher));
    SecureZeroMemory(command, sizeof(command));
    SecureZeroMemory(actual, sizeof(actual));
    if (result != 0) {
        if (child->process.hProcess != NULL) {
            (void)TerminateProcess(child->process.hProcess, 1U);
        }
        wls_guardian_child_process_close(child);
    }
    return result;
}

static int wls_guardian_child_stop(
    struct wls_guardian_child_process *child,
    DWORD maximum_wait_ms
) {
    DWORD wait_result;
    int result = 1;
    if (child == NULL || child->process.hProcess == NULL
        || child->stop_event == NULL) return 1;
    (void)SetEvent(child->stop_event);
    wait_result = WaitForSingleObject(
        child->process.hProcess, maximum_wait_ms
    );
    if (wait_result == WAIT_OBJECT_0) {
        result = 0;
    } else if (wait_result == WAIT_TIMEOUT
        && TerminateJobObject(child->job, WLS_SERVICE_TREE_RESTART)
        && WaitForSingleObject(child->process.hProcess, 5000U)
            == WAIT_OBJECT_0) {
        result = 0;
    }
    return result;
}

static int wls_guardian_read_digest_value(
    const wchar_t *home,
    const wchar_t *relative,
    int controller_readable,
    char digest[65]
) {
    wchar_t path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || relative == NULL || digest == NULL
        || wls_join(path, WLS_PATH_CHARS, home, relative) != 0
        || wls_guardian_read_pinned(
            path,
            65U,
            controller_readable,
            &contents,
            &length,
            &pin
        ) != 0
        || !((length == 64U)
            || (length == 65U && contents[64] == '\n'))
        || !wls_is_hex(contents, 64U)) goto cleanup;
    memcpy(digest, contents, 64U);
    digest[64] = '\0';
    result = 0;
cleanup:
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    wls_guardian_pinned_close(&pin);
    SecureZeroMemory(path, sizeof(path));
    if (result != 0 && digest != NULL) sodium_memzero(digest, 65U);
    return result;
}

static int wls_guardian_active_slot_value(
    const wchar_t *home,
    wchar_t slot[2]
) {
    wchar_t path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || slot == NULL
        || wls_join(
            path, WLS_PATH_CHARS, home, L"trust\\active-slot"
        ) != 0
        || wls_guardian_read_pinned(
            path, 2U, 1, &contents, &length, &pin
        ) != 0
        || !((length == 1U)
            || (length == 2U && contents[1] == '\n'))
        || (contents[0] != 'A' && contents[0] != 'B')) goto cleanup;
    slot[0] = (wchar_t)contents[0];
    slot[1] = L'\0';
    result = 0;
cleanup:
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    wls_guardian_pinned_close(&pin);
    SecureZeroMemory(path, sizeof(path));
    if (result != 0 && slot != NULL) SecureZeroMemory(slot, 2U * sizeof(*slot));
    return result;
}

static int wls_guardian_regular_digest_with_acl(
    const wchar_t *path,
    unsigned long long maximum,
    int controller_readable,
    const char *expected_digest,
    unsigned long long expected_size
) {
    struct wls_guardian_pinned_file pin;
    char digest[65];
    unsigned long long size = 0ULL;
    PSID service_sid = NULL;
    int result = 1;
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    sodium_memzero(digest, sizeof(digest));
    if (path == NULL || expected_digest == NULL
        || !wls_is_hex(expected_digest, 64U)
        || maximum == 0ULL || expected_size > maximum
        || wls_guardian_open_pinned(path, 0, GENERIC_READ, &pin) != 0) {
        goto cleanup;
    }
    if (controller_readable) {
        if (wls_launcher_gateway_service_sid(&service_sid) != 0
            || wls_launcher_slot_acl_valid(
                pin.handle, 0, service_sid
            ) != 0) goto cleanup;
    } else if (wls_recovery_acl_safe(pin.handle, 0) != 0) {
        goto cleanup;
    }
    if (wls_guardian_digest_handle(
            &pin, maximum, digest, &size
        ) != 0
        || (expected_size != 0ULL && size != expected_size)
        || sodium_memcmp(digest, expected_digest, 64U) != 0) goto cleanup;
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    wls_guardian_pinned_close(&pin);
    sodium_memzero(digest, sizeof(digest));
    return result;
}

/* Verify the complete executable/CA/signed-slot generation that the stable
 * Launcher child is about to execute.  The generation head lock is held by
 * the caller while this closure is observed and through CreateProcessW. */
static int wls_guardian_generation_closure(
    const wchar_t *home,
    const char *launcher_sha256,
    unsigned long long launcher_size,
    const char *ca_sha256,
    const char *runtime_generation,
    const char *generation_id
) {
    wchar_t launcher[WLS_PATH_CHARS];
    wchar_t slots[WLS_PATH_CHARS];
    wchar_t slot_path[WLS_PATH_CHARS];
    wchar_t ca_path[WLS_PATH_CHARS];
    wchar_t active[2];
    char launcher_identity[65];
    char ca_identity[65];
    char observed_runtime[65];
    char observed_generation[65];
    struct wls_guardian_pinned_file slots_pin;
    struct wls_guardian_pinned_file slot_pin;
    int result = 1;
    ZeroMemory(launcher, sizeof(launcher));
    ZeroMemory(slots, sizeof(slots));
    ZeroMemory(slot_path, sizeof(slot_path));
    ZeroMemory(ca_path, sizeof(ca_path));
    ZeroMemory(active, sizeof(active));
    ZeroMemory(&slots_pin, sizeof(slots_pin));
    ZeroMemory(&slot_pin, sizeof(slot_pin));
    slots_pin.handle = INVALID_HANDLE_VALUE;
    slot_pin.handle = INVALID_HANDLE_VALUE;
    sodium_memzero(launcher_identity, sizeof(launcher_identity));
    sodium_memzero(ca_identity, sizeof(ca_identity));
    sodium_memzero(observed_runtime, sizeof(observed_runtime));
    sodium_memzero(observed_generation, sizeof(observed_generation));
    if (home == NULL || launcher_sha256 == NULL || ca_sha256 == NULL
        || runtime_generation == NULL || generation_id == NULL
        || !wls_is_hex(launcher_sha256, 64U)
        || !wls_is_hex(ca_sha256, 64U)
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(generation_id, 64U)
        || wls_guardian_generation_id(
            launcher_sha256,
            ca_sha256,
            runtime_generation,
            observed_generation
        ) != 0
        || sodium_memcmp(observed_generation, generation_id, 64U) != 0
        || wls_guardian_read_digest_value(
            home,
            L"trust\\stable-launcher.sha256",
            0,
            launcher_identity
        ) != 0
        || sodium_memcmp(
            launcher_identity, launcher_sha256, 64U
        ) != 0
        || wls_guardian_read_digest_value(
            home,
            L"trust\\ca-bundle.sha256",
            0,
            ca_identity
        ) != 0
        || sodium_memcmp(ca_identity, ca_sha256, 64U) != 0
        || wls_join(
            launcher,
            WLS_PATH_CHARS,
            home,
            L"bin\\wls-gateway-launcher.exe"
        ) != 0
        || wls_guardian_regular_digest_with_acl(
            launcher,
            536870912ULL,
            1,
            launcher_sha256,
            launcher_size
        ) != 0
        || wls_guardian_active_slot_value(home, active) != 0
        || wls_join(slots, WLS_PATH_CHARS, home, L"slots") != 0
        || _snwprintf_s(
            slot_path,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\%lc",
            slots,
            active[0]
        ) < 0
        || wls_guardian_open_pinned(
            slots, 1, FILE_TRAVERSE | FILE_LIST_DIRECTORY, &slots_pin
        ) != 0
        || wls_guardian_open_pinned(
            slot_path, 1, FILE_TRAVERSE | FILE_LIST_DIRECTORY, &slot_pin
        ) != 0
        || wls_verify_slot_durable_state_contract_v2(
            home, active[0], observed_runtime
        ) != 0
        || sodium_memcmp(
            observed_runtime, runtime_generation, 64U
        ) != 0
        || wls_join(
            ca_path,
            WLS_PATH_CHARS,
            slot_path,
            L"share\\ca-bundle.pem"
        ) != 0
        || wls_guardian_regular_digest_with_acl(
            ca_path,
            4ULL * 1024ULL * 1024ULL,
            1,
            ca_sha256,
            0ULL
        ) != 0
        || !wls_guardian_pinned_identity_unchanged(&slot_pin, 1)
        || !wls_guardian_pinned_identity_unchanged(&slots_pin, 1)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&slot_pin);
    wls_guardian_pinned_close(&slots_pin);
    SecureZeroMemory(launcher, sizeof(launcher));
    SecureZeroMemory(slots, sizeof(slots));
    SecureZeroMemory(slot_path, sizeof(slot_path));
    SecureZeroMemory(ca_path, sizeof(ca_path));
    SecureZeroMemory(active, sizeof(active));
    sodium_memzero(launcher_identity, sizeof(launcher_identity));
    sodium_memzero(ca_identity, sizeof(ca_identity));
    sodium_memzero(observed_runtime, sizeof(observed_runtime));
    sodium_memzero(observed_generation, sizeof(observed_generation));
    return result;
}

static int wls_guardian_continuity_parse(
    const wchar_t *home,
    const char *contents,
    size_t length,
    struct wls_guardian_continuity_receipt *receipt
) {
    char controller_pid[21];
    char controller_start[21];
    char broker_pid[21];
    char broker_start[21];
    char data_plane_pid[21];
    char data_plane_start[21];
    char publication_generation[21];
    char promotion_since[21];
    char issued_unix[21];
    char issued_monotonic[21];
    unsigned long long parsed = 0ULL;
    int consumed = 0;
    int fields;
    int result = 1;
    SecureZeroMemory(controller_pid, sizeof(controller_pid));
    SecureZeroMemory(controller_start, sizeof(controller_start));
    SecureZeroMemory(broker_pid, sizeof(broker_pid));
    SecureZeroMemory(broker_start, sizeof(broker_start));
    SecureZeroMemory(data_plane_pid, sizeof(data_plane_pid));
    SecureZeroMemory(data_plane_start, sizeof(data_plane_start));
    SecureZeroMemory(
        publication_generation, sizeof(publication_generation)
    );
    SecureZeroMemory(promotion_since, sizeof(promotion_since));
    SecureZeroMemory(issued_unix, sizeof(issued_unix));
    SecureZeroMemory(issued_monotonic, sizeof(issued_monotonic));
    if (home == NULL || contents == NULL || receipt == NULL
        || length == 0U || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES
        || length > (size_t)INT_MAX || contents[length] != '\0') return 1;
    SecureZeroMemory(receipt, sizeof(*receipt));
    fields = sscanf(
        contents,
        "WLS-GUARDIAN-CONTINUITY/1\n"
        "host_id=%32[0-9a-f]\n"
        "host_boot_id=%64[0-9a-f]\n"
        "admission_state=%23[A-Z_]\n"
        "active_slot=%1[AB]\n"
        "runtime_generation=%64[0-9a-f]\n"
        "bootstrap_receipt_sha256=%64[0-9a-f]\n"
        "guardian_health_sha256=%64[0-9a-f]\n"
        "controller_epoch=%32[0-9a-f]\n"
        "controller_pid=%20[0-9]\n"
        "controller_start_id=%20[0-9]\n"
        "controller_binary_sha256=%64[0-9a-f]\n"
        "controller_identity_sha256=%64[0-9a-f]\n"
        "broker_pid=%20[0-9]\n"
        "broker_start_id=%20[0-9]\n"
        "broker_binary_sha256=%64[0-9a-f]\n"
        "data_plane_pid=%20[0-9]\n"
        "data_plane_start_id=%20[0-9]\n"
        "data_plane_binary_sha256=%64[0-9a-f]\n"
        "process_attestation_sha256=%64[0-9a-f]\n"
        "attested_publication_generation=%20[0-9]\n"
        "promotion_healthy_since_monotonic_ms=%20[0-9]\n"
        "issued_unix_ms=%20[0-9]\n"
        "issued_monotonic_ms=%20[0-9]\n"
        "signature=%64[0-9a-f]\n%n",
        receipt->host_id,
        receipt->host_boot_id,
        receipt->admission_state,
        receipt->active_slot,
        receipt->runtime_generation,
        receipt->bootstrap_receipt_sha256,
        receipt->guardian_health_sha256,
        receipt->controller_epoch,
        controller_pid,
        controller_start,
        receipt->controller_binary_sha256,
        receipt->controller_identity_sha256,
        broker_pid,
        broker_start,
        receipt->broker_binary_sha256,
        data_plane_pid,
        data_plane_start,
        receipt->data_plane_binary_sha256,
        receipt->process_attestation_sha256,
        publication_generation,
        promotion_since,
        issued_unix,
        issued_monotonic,
        receipt->signature,
        &consumed
    );
    if (fields != 24 || consumed != (int)length
        || !wls_is_hex(receipt->host_id, 32U)
        || !wls_is_hex(receipt->host_boot_id, 64U)
        || (strcmp(receipt->admission_state, "HEALTHY") != 0
            && strcmp(
                receipt->admission_state, "PUBLICATION_PENDING"
            ) != 0
            && strcmp(
                receipt->admission_state, "ISOLATION_REPLAY"
            ) != 0
            && strcmp(
                receipt->admission_state, "ROUTE_DEGRADED"
            ) != 0)
        || strlen(receipt->active_slot) != 1U
        || !wls_is_hex(receipt->runtime_generation, 64U)
        || !wls_is_hex(receipt->bootstrap_receipt_sha256, 64U)
        || wls_all_zero_hex(receipt->bootstrap_receipt_sha256)
        || !wls_is_hex(receipt->guardian_health_sha256, 64U)
        || wls_all_zero_hex(receipt->guardian_health_sha256)
        || !wls_is_hex(receipt->controller_epoch, 32U)
        || strspn(receipt->controller_epoch, "0") == 32U
        || !wls_is_hex(receipt->controller_binary_sha256, 64U)
        || wls_all_zero_hex(receipt->controller_binary_sha256)
        || !wls_is_hex(receipt->controller_identity_sha256, 64U)
        || wls_all_zero_hex(receipt->controller_identity_sha256)
        || !wls_is_hex(receipt->broker_binary_sha256, 64U)
        || wls_all_zero_hex(receipt->broker_binary_sha256)
        || !wls_is_hex(receipt->data_plane_binary_sha256, 64U)
        || wls_all_zero_hex(receipt->data_plane_binary_sha256)
        || !wls_is_hex(receipt->process_attestation_sha256, 64U)
        || wls_all_zero_hex(receipt->process_attestation_sha256)
        || !wls_is_hex(receipt->signature, 64U)
        || wls_guardian_u64(controller_pid, &parsed) != 0
        || parsed == 0ULL || parsed > MAXDWORD) goto cleanup;
    receipt->controller_pid = (unsigned long)parsed;
    if (wls_guardian_u64(controller_start, &parsed) != 0
        || parsed == 0ULL) goto cleanup;
    receipt->controller_start_id = parsed;
    if (wls_guardian_u64(broker_pid, &parsed) != 0
        || parsed == 0ULL || parsed > MAXDWORD) goto cleanup;
    receipt->broker_pid = (unsigned long)parsed;
    if (wls_guardian_u64(broker_start, &parsed) != 0
        || parsed == 0ULL) goto cleanup;
    receipt->broker_start_id = parsed;
    if (wls_guardian_u64(data_plane_pid, &parsed) != 0
        || parsed == 0ULL || parsed > MAXDWORD) goto cleanup;
    receipt->data_plane_pid = (unsigned long)parsed;
    if (wls_guardian_u64(data_plane_start, &parsed) != 0
        || parsed == 0ULL) goto cleanup;
    receipt->data_plane_start_id = parsed;
    if (wls_guardian_u64(publication_generation, &parsed) != 0
        || parsed == 0ULL) goto cleanup;
    receipt->attested_publication_generation = parsed;
    if (wls_guardian_u64(promotion_since, &parsed) != 0) goto cleanup;
    receipt->promotion_healthy_since_monotonic_ms = parsed;
    if (wls_guardian_u64(issued_unix, &parsed) != 0
        || parsed == 0ULL) goto cleanup;
    receipt->issued_unix_ms = parsed;
    if (wls_guardian_u64(issued_monotonic, &parsed) != 0
        || parsed == 0ULL) goto cleanup;
    receipt->issued_monotonic_ms = parsed;
    if (((strcmp(receipt->admission_state, "HEALTHY") == 0)
            != (receipt->promotion_healthy_since_monotonic_ms > 0ULL))
        || receipt->promotion_healthy_since_monotonic_ms
            > receipt->issued_monotonic_ms
        || wls_guardian_signed_document_valid(
            home, contents, length, receipt->signature
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)contents,
            length,
            receipt->raw_sha256
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(controller_pid, sizeof(controller_pid));
    SecureZeroMemory(controller_start, sizeof(controller_start));
    SecureZeroMemory(broker_pid, sizeof(broker_pid));
    SecureZeroMemory(broker_start, sizeof(broker_start));
    SecureZeroMemory(data_plane_pid, sizeof(data_plane_pid));
    SecureZeroMemory(data_plane_start, sizeof(data_plane_start));
    SecureZeroMemory(
        publication_generation, sizeof(publication_generation)
    );
    SecureZeroMemory(promotion_since, sizeof(promotion_since));
    SecureZeroMemory(issued_unix, sizeof(issued_unix));
    SecureZeroMemory(issued_monotonic, sizeof(issued_monotonic));
    if (result != 0) SecureZeroMemory(receipt, sizeof(*receipt));
    return result;
}

static unsigned long long wls_guardian_filetime_value(
    const FILETIME *value
) {
    ULARGE_INTEGER combined;
    if (value == NULL) return 0ULL;
    combined.LowPart = value->dwLowDateTime;
    combined.HighPart = value->dwHighDateTime;
    return combined.QuadPart;
}

static int wls_guardian_process_restricted_sid(
    HANDLE process,
    const char *expected_sid
) {
    HANDLE token = NULL;
    PSID sid = NULL;
    PTOKEN_GROUPS groups = NULL;
    DWORD required = 0U;
    DWORD allocated = 0U;
    DWORD index;
    int result = 1;
    if (process == NULL || process == INVALID_HANDLE_VALUE
        || expected_sid == NULL
        || !ConvertStringSidToSidA(expected_sid, &sid)
        || sid == NULL || !IsValidSid(sid)
        || !OpenProcessToken(process, TOKEN_QUERY, &token)) goto cleanup;
    (void)GetTokenInformation(
        token, TokenRestrictedSids, NULL, 0U, &required
    );
    if (required < sizeof(TOKEN_GROUPS)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    allocated = required;
    groups = (PTOKEN_GROUPS)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
    );
    if (groups == NULL
        || !GetTokenInformation(
            token,
            TokenRestrictedSids,
            groups,
            allocated,
            &required
        )) goto cleanup;
    for (index = 0U; index < groups->GroupCount; index++) {
        if (IsValidSid(groups->Groups[index].Sid)
            && EqualSid(groups->Groups[index].Sid, sid)) {
            result = 0;
            break;
        }
    }
cleanup:
    if (groups != NULL) {
        SecureZeroMemory(groups, allocated);
        HeapFree(GetProcessHeap(), 0U, groups);
    }
    if (token != NULL) CloseHandle(token);
    if (sid != NULL) LocalFree(sid);
    return result;
}

static int wls_guardian_process_service_identity(HANDLE process)
{
    HANDLE token = NULL;
    TOKEN_USER *user = NULL;
    PSID service_sid = NULL;
    unsigned char system_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD required = 0U;
    DWORD allocated = 0U;
    BOOL service_member = FALSE;
    int result = 1;
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    if (process == NULL || process == INVALID_HANDLE_VALUE
        || !OpenProcessToken(process, TOKEN_QUERY, &token)) goto cleanup;
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
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_buffer, &system_length
        )
        || !EqualSid(user->User.Sid, system_buffer)
        || !ConvertStringSidToSidA(
            WLS_GUARDIAN_CONTROLLER_SID, &service_sid
        )
        || service_sid == NULL || !IsValidSid(service_sid)
        || !CheckTokenMembership(token, service_sid, &service_member)
        || !service_member) goto cleanup;
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    if (user != NULL) {
        SecureZeroMemory(user, allocated);
        HeapFree(GetProcessHeap(), 0U, user);
    }
    if (token != NULL) CloseHandle(token);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    return result;
}

static int wls_guardian_broker_identity_live(
    const wchar_t *home,
    const struct wls_guardian_continuity_receipt *continuity,
    HANDLE child_job
) {
    wchar_t expected_binary[WLS_PATH_CHARS];
    wchar_t observed_before[WLS_PATH_CHARS];
    wchar_t observed_after[WLS_PATH_CHARS];
    HANDLE process = NULL;
    FILETIME created_before;
    FILETIME exited_before;
    FILETIME kernel_before;
    FILETIME user_before;
    FILETIME created_after;
    FILETIME exited_after;
    FILETIME kernel_after;
    FILETIME user_after;
    BOOL in_job = FALSE;
    DWORD observed_length = WLS_PATH_CHARS;
    int result = 1;
    ZeroMemory(expected_binary, sizeof(expected_binary));
    ZeroMemory(observed_before, sizeof(observed_before));
    ZeroMemory(observed_after, sizeof(observed_after));
    if (home == NULL || continuity == NULL || child_job == NULL
        || _snwprintf_s(
            expected_binary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%lc\\bin\\wls-gateway-broker.exe",
            home,
            (wchar_t)(unsigned char)continuity->active_slot[0]
        ) < 0) goto cleanup;
    process = OpenProcess(
        SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION,
        FALSE,
        (DWORD)continuity->broker_pid
    );
    if (process == NULL
        || WaitForSingleObject(process, 0U) != WAIT_TIMEOUT
        || !IsProcessInJob(process, child_job, &in_job) || !in_job
        || wls_guardian_process_service_identity(process) != 0
        || !GetProcessTimes(
            process,
            &created_before,
            &exited_before,
            &kernel_before,
            &user_before
        )
        || wls_guardian_filetime_value(&created_before)
            != continuity->broker_start_id
        || !QueryFullProcessImageNameW(
            process, 0U, observed_before, &observed_length
        )
        || wcscmp(observed_before, expected_binary) != 0
        || wls_guardian_regular_digest_with_acl(
            expected_binary,
            536870912ULL,
            1,
            continuity->broker_binary_sha256,
            0ULL
        ) != 0) goto cleanup;
    observed_length = WLS_PATH_CHARS;
    if (WaitForSingleObject(process, 0U) != WAIT_TIMEOUT
        || !GetProcessTimes(
            process,
            &created_after,
            &exited_after,
            &kernel_after,
            &user_after
        )
        || wls_guardian_filetime_value(&created_after)
            != continuity->broker_start_id
        || !QueryFullProcessImageNameW(
            process, 0U, observed_after, &observed_length
        )
        || wcscmp(observed_after, observed_before) != 0) goto cleanup;
    result = 0;
cleanup:
    if (process != NULL) CloseHandle(process);
    SecureZeroMemory(expected_binary, sizeof(expected_binary));
    SecureZeroMemory(observed_before, sizeof(observed_before));
    SecureZeroMemory(observed_after, sizeof(observed_after));
    return result;
}

static int wls_guardian_controller_identity_live(
    const wchar_t *home,
    const struct wls_guardian_health_receipt *health,
    HANDLE child_job
) {
    wchar_t identity_path[WLS_PATH_CHARS];
    wchar_t expected_binary[WLS_PATH_CHARS];
    wchar_t observed_before[WLS_PATH_CHARS];
    wchar_t observed_after[WLS_PATH_CHARS];
    char expected_sddl[768];
    char *contents = NULL;
    char identity_digest[65];
    char slot[2];
    char runtime_generation[65];
    char fencing_digest[65];
    unsigned long parsed_pid = 0UL;
    unsigned long long parsed_start = 0ULL;
    size_t length = 0U;
    struct wls_guardian_pinned_file identity_pin;
    HANDLE process = NULL;
    FILETIME created_before;
    FILETIME exited_before;
    FILETIME kernel_before;
    FILETIME user_before;
    FILETIME created_after;
    FILETIME exited_after;
    FILETIME kernel_after;
    FILETIME user_after;
    BOOL in_job = FALSE;
    DWORD observed_length;
    int consumed = 0;
    int result = 1;
    ZeroMemory(identity_path, sizeof(identity_path));
    ZeroMemory(expected_binary, sizeof(expected_binary));
    ZeroMemory(observed_before, sizeof(observed_before));
    ZeroMemory(observed_after, sizeof(observed_after));
    SecureZeroMemory(expected_sddl, sizeof(expected_sddl));
    sodium_memzero(identity_digest, sizeof(identity_digest));
    SecureZeroMemory(slot, sizeof(slot));
    sodium_memzero(runtime_generation, sizeof(runtime_generation));
    sodium_memzero(fencing_digest, sizeof(fencing_digest));
    ZeroMemory(&identity_pin, sizeof(identity_pin));
    identity_pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || health == NULL || child_job == NULL
        || _snprintf_s(
            expected_sddl,
            sizeof(expected_sddl),
            _TRUNCATE,
            "O:S-1-5-32-544D:P(A;;FA;;;SY)(A;;FA;;;BA)"
            "(A;;0x1200a9;;;%s)",
            WLS_GUARDIAN_CONTROLLER_SID
        ) <= 0
        || wls_join(
            identity_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\controller-process.identity"
        ) != 0
        || wls_guardian_read_pinned_sddl(
            identity_path,
            WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
            expected_sddl,
            &contents,
            &length,
            &identity_pin
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)contents,
            length,
            identity_digest
        ) != 0
        || sodium_memcmp(
            identity_digest,
            health->controller_identity_sha256,
            64U
        ) != 0
        || sscanf(
            contents,
            "WLS-CONTROLLER-PROCESS/2\n"
            "pid=%lu\ncreation_time=%llu\nslot=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "fencing_digest=%64[0-9a-f]\n%n",
            &parsed_pid,
            &parsed_start,
            slot,
            runtime_generation,
            fencing_digest,
            &consumed
        ) != 5
        || consumed != (int)length
        || parsed_pid != health->controller_pid
        || parsed_start != health->controller_start_id
        || slot[0] != health->active_slot[0]
        || sodium_memcmp(
            runtime_generation, health->runtime_generation, 64U
        ) != 0
        || !wls_is_hex(fencing_digest, 64U)
        || _snwprintf_s(
            expected_binary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%lc\\bin\\php.exe",
            home,
            (wchar_t)(unsigned char)health->active_slot[0]
        ) < 0) goto cleanup;
    process = OpenProcess(
        SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION,
        FALSE,
        (DWORD)health->controller_pid
    );
    observed_length = WLS_PATH_CHARS;
    if (process == NULL
        || WaitForSingleObject(process, 0U) != WAIT_TIMEOUT
        || !IsProcessInJob(process, child_job, &in_job) || !in_job
        || wls_guardian_process_restricted_sid(
            process, WLS_GUARDIAN_CONTROLLER_SID
        ) != 0
        || !GetProcessTimes(
            process,
            &created_before,
            &exited_before,
            &kernel_before,
            &user_before
        )
        || wls_guardian_filetime_value(&created_before)
            != health->controller_start_id
        || !QueryFullProcessImageNameW(
            process, 0U, observed_before, &observed_length
        )
        || wcscmp(observed_before, expected_binary) != 0
        || wls_guardian_regular_digest_with_acl(
            expected_binary,
            536870912ULL,
            1,
            health->controller_binary_sha256,
            0ULL
        ) != 0) goto cleanup;
    observed_length = WLS_PATH_CHARS;
    if (WaitForSingleObject(process, 0U) != WAIT_TIMEOUT
        || !GetProcessTimes(
            process,
            &created_after,
            &exited_after,
            &kernel_after,
            &user_after
        )
        || wls_guardian_filetime_value(&created_after)
            != health->controller_start_id
        || !QueryFullProcessImageNameW(
            process, 0U, observed_after, &observed_length
        )
        || wcscmp(observed_after, observed_before) != 0
        || !wls_guardian_pinned_identity_unchanged(
            &identity_pin, 0
        )) goto cleanup;
    result = 0;
cleanup:
    if (process != NULL) CloseHandle(process);
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    wls_guardian_pinned_close(&identity_pin);
    SecureZeroMemory(identity_path, sizeof(identity_path));
    SecureZeroMemory(expected_binary, sizeof(expected_binary));
    SecureZeroMemory(observed_before, sizeof(observed_before));
    SecureZeroMemory(observed_after, sizeof(observed_after));
    SecureZeroMemory(expected_sddl, sizeof(expected_sddl));
    sodium_memzero(identity_digest, sizeof(identity_digest));
    SecureZeroMemory(slot, sizeof(slot));
    sodium_memzero(runtime_generation, sizeof(runtime_generation));
    sodium_memzero(fencing_digest, sizeof(fencing_digest));
    return result;
}

static int wls_guardian_data_plane_identity_live(
    const wchar_t *home,
    const struct wls_guardian_health_receipt *health,
    HANDLE child_job,
    struct wls_process_attestation_receipt *attestation
) {
    wchar_t receipt_path[WLS_PATH_CHARS];
    wchar_t expected_binary[WLS_PATH_CHARS];
    wchar_t observed_before[WLS_PATH_CHARS];
    wchar_t observed_after[WLS_PATH_CHARS];
    char receipt_sddl[768];
    char binary_sddl[1024];
    char *contents = NULL;
    char receipt_digest[65];
    char binary_digest[65];
    size_t length = 0U;
    unsigned long long binary_size = 0ULL;
    struct wls_guardian_pinned_file receipt_pin;
    struct wls_guardian_pinned_file binary_pin;
    HANDLE process = NULL;
    FILETIME created_before;
    FILETIME exited_before;
    FILETIME kernel_before;
    FILETIME user_before;
    FILETIME created_after;
    FILETIME exited_after;
    FILETIME kernel_after;
    FILETIME user_after;
    BOOL in_job = FALSE;
    DWORD observed_length;
    int result = 1;
    ZeroMemory(receipt_path, sizeof(receipt_path));
    ZeroMemory(expected_binary, sizeof(expected_binary));
    ZeroMemory(observed_before, sizeof(observed_before));
    ZeroMemory(observed_after, sizeof(observed_after));
    SecureZeroMemory(receipt_sddl, sizeof(receipt_sddl));
    SecureZeroMemory(binary_sddl, sizeof(binary_sddl));
    sodium_memzero(receipt_digest, sizeof(receipt_digest));
    sodium_memzero(binary_digest, sizeof(binary_digest));
    ZeroMemory(&receipt_pin, sizeof(receipt_pin));
    ZeroMemory(&binary_pin, sizeof(binary_pin));
    receipt_pin.handle = INVALID_HANDLE_VALUE;
    binary_pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || health == NULL || child_job == NULL
        || attestation == NULL
        || _snprintf_s(
            receipt_sddl,
            sizeof(receipt_sddl),
            _TRUNCATE,
            "O:S-1-5-32-544D:P(A;;FA;;;SY)(A;;FA;;;BA)"
            "(A;;0x1200a9;;;%s)",
            WLS_GUARDIAN_CONTROLLER_SID
        ) <= 0
        || _snprintf_s(
            binary_sddl,
            sizeof(binary_sddl),
            _TRUNCATE,
            "O:S-1-5-32-544D:P(A;;FA;;;SY)(A;;FA;;;BA)"
            "(A;;0x1200a9;;;%s)(A;;0x1200a9;;;%s)",
            WLS_GUARDIAN_CONTROLLER_SID,
            WLS_GUARDIAN_DATA_PLANE_SID
        ) <= 0
        || wls_join(
            receipt_path,
            WLS_PATH_CHARS,
            home,
            L"trust\\process-attestation.receipt"
        ) != 0
        || wls_guardian_read_pinned_sddl(
            receipt_path,
            2048U,
            receipt_sddl,
            &contents,
            &length,
            &receipt_pin
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)contents,
            length,
            receipt_digest
        ) != 0
        || sodium_memcmp(
            receipt_digest,
            health->process_attestation_sha256,
            64U
        ) != 0
        || wls_parse_process_attestation(
            contents, length, attestation
        ) != 0
        || sodium_memcmp(
            attestation->runtime_generation,
            health->runtime_generation,
            64U
        ) != 0
        || attestation->publication_generation
            != health->public_data_plane_generation
        || _snwprintf_s(
            expected_binary,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls\\slots\\%lc\\bin\\nginx.exe",
            home,
            (wchar_t)(unsigned char)health->active_slot[0]
        ) < 0) goto cleanup;
    process = OpenProcess(
        SYNCHRONIZE | PROCESS_QUERY_LIMITED_INFORMATION,
        FALSE,
        (DWORD)attestation->pid
    );
    observed_length = WLS_PATH_CHARS;
    if (process == NULL
        || WaitForSingleObject(process, 0U) != WAIT_TIMEOUT
        || !IsProcessInJob(process, child_job, &in_job) || !in_job
        || wls_guardian_process_restricted_sid(
            process, WLS_GUARDIAN_DATA_PLANE_SID
        ) != 0
        || !GetProcessTimes(
            process,
            &created_before,
            &exited_before,
            &kernel_before,
            &user_before
        )
        || wls_guardian_filetime_value(&created_before)
            != attestation->start_id
        || !QueryFullProcessImageNameW(
            process, 0U, observed_before, &observed_length
        )
        || wcscmp(observed_before, expected_binary) != 0
        || wls_guardian_open_pinned(
            expected_binary, 0, GENERIC_READ, &binary_pin
        ) != 0
        || wls_guardian_handle_sddl_exact(
            binary_pin.handle, binary_sddl
        ) != 0
        || wls_guardian_digest_handle(
            &binary_pin,
            536870912ULL,
            binary_digest,
            &binary_size
        ) != 0
        || binary_size == 0ULL
        || sodium_memcmp(
            binary_digest, attestation->binary_digest, 64U
        ) != 0) goto cleanup;
    observed_length = WLS_PATH_CHARS;
    if (WaitForSingleObject(process, 0U) != WAIT_TIMEOUT
        || !GetProcessTimes(
            process,
            &created_after,
            &exited_after,
            &kernel_after,
            &user_after
        )
        || wls_guardian_filetime_value(&created_after)
            != attestation->start_id
        || !QueryFullProcessImageNameW(
            process, 0U, observed_after, &observed_length
        )
        || wcscmp(observed_after, observed_before) != 0
        || !wls_guardian_pinned_identity_unchanged(&binary_pin, 0)
        || !wls_guardian_pinned_identity_unchanged(
            &receipt_pin, 0
        )) goto cleanup;
    result = 0;
cleanup:
    if (process != NULL) CloseHandle(process);
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    wls_guardian_pinned_close(&binary_pin);
    wls_guardian_pinned_close(&receipt_pin);
    SecureZeroMemory(receipt_path, sizeof(receipt_path));
    SecureZeroMemory(expected_binary, sizeof(expected_binary));
    SecureZeroMemory(observed_before, sizeof(observed_before));
    SecureZeroMemory(observed_after, sizeof(observed_after));
    SecureZeroMemory(receipt_sddl, sizeof(receipt_sddl));
    SecureZeroMemory(binary_sddl, sizeof(binary_sddl));
    sodium_memzero(receipt_digest, sizeof(receipt_digest));
    sodium_memzero(binary_digest, sizeof(binary_digest));
    if (result != 0 && attestation != NULL) {
        SecureZeroMemory(attestation, sizeof(*attestation));
    }
    return result;
}

/* The continuity receipt is the single probation authority.  HEALTH/1 and
 * bootstrap digests are provenance bindings only; reading their mutable paths
 * here would create a two-file publication race.  The caller holds the
 * generation-head lock from before this read until any head transition is
 * committed.  This function pins C1, re-opens Controller/Broker/Nginx births,
 * then re-reads the same pinned file as C2 and requires the exact raw digest. */
static int wls_guardian_probation_health(
    const wchar_t *home,
    const wchar_t *run_directory,
    const char *host_id,
    const char *runtime_generation,
    HANDLE child_job,
    struct wls_guardian_continuity_sample *sample
) {
    wchar_t path[WLS_PATH_CHARS];
    wchar_t active_slot[2];
    char receipt_sddl[768];
    char boot_id[65];
    char *contents_c1 = NULL;
    char *contents_c2 = NULL;
    char canonical[2048];
    size_t length = 0U;
    unsigned long long now = 0ULL;
    struct wls_guardian_pinned_file pin;
    struct wls_guardian_health_receipt health;
    struct wls_guardian_continuity_receipt continuity_c1;
    struct wls_guardian_continuity_receipt continuity_c2;
    struct wls_process_attestation_receipt attestation;
    int canonical_length;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    ZeroMemory(active_slot, sizeof(active_slot));
    SecureZeroMemory(receipt_sddl, sizeof(receipt_sddl));
    sodium_memzero(boot_id, sizeof(boot_id));
    SecureZeroMemory(canonical, sizeof(canonical));
    ZeroMemory(&pin, sizeof(pin));
    ZeroMemory(&health, sizeof(health));
    ZeroMemory(&continuity_c1, sizeof(continuity_c1));
    ZeroMemory(&continuity_c2, sizeof(continuity_c2));
    ZeroMemory(&attestation, sizeof(attestation));
    pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || run_directory == NULL || host_id == NULL
        || runtime_generation == NULL
        || child_job == NULL || sample == NULL
        || !wls_is_hex(host_id, 32U)
        || !wls_is_hex(runtime_generation, 64U)
        || _snprintf_s(
            receipt_sddl,
            sizeof(receipt_sddl),
            _TRUNCATE,
            "O:S-1-5-32-544D:P(A;;FA;;;SY)(A;;FA;;;BA)"
            "(A;;0x1301bf;;;%s)",
            WLS_GUARDIAN_CONTROLLER_SID
        ) <= 0
        || wls_join(
            path,
            WLS_PATH_CHARS,
            run_directory,
            L"guardian-continuity.receipt"
        ) != 0
        || wls_guardian_read_pinned_sddl(
            path,
            WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
            receipt_sddl,
            &contents_c1,
            &length,
            &pin
        ) != 0
        || wls_guardian_continuity_parse(
            home, contents_c1, length, &continuity_c1
        ) != 0
        || wls_boot_id(boot_id) != 0
        || wls_guardian_active_slot_value(home, active_slot) != 0
        || sodium_memcmp(
            continuity_c1.host_id, host_id, 32U
        ) != 0
        || sodium_memcmp(
            continuity_c1.host_boot_id, boot_id, 64U
        ) != 0
        || (wchar_t)(unsigned char)continuity_c1.active_slot[0]
            != active_slot[0]
        || sodium_memcmp(
            continuity_c1.runtime_generation, runtime_generation, 64U
        ) != 0
        || wls_guardian_monotonic(&now) != 0
        || now < continuity_c1.issued_monotonic_ms
        || now - continuity_c1.issued_monotonic_ms
            > WLS_GUARDIAN_HEALTH_FRESHNESS_MILLISECONDS
        || continuity_c1.promotion_healthy_since_monotonic_ms
            > continuity_c1.issued_monotonic_ms) goto cleanup;
    memcpy(health.host_id, continuity_c1.host_id, 33U);
    memcpy(health.host_boot_id, continuity_c1.host_boot_id, 65U);
    memcpy(health.active_slot, continuity_c1.active_slot, 2U);
    memcpy(
        health.runtime_generation,
        continuity_c1.runtime_generation,
        65U
    );
    memcpy(health.controller_epoch, continuity_c1.controller_epoch, 33U);
    health.controller_generation
        = continuity_c1.attested_publication_generation;
    health.local_data_plane_generation
        = continuity_c1.attested_publication_generation;
    health.public_data_plane_generation
        = continuity_c1.attested_publication_generation;
    health.observed_monotonic_ms = continuity_c1.issued_monotonic_ms;
    health.controller_pid = continuity_c1.controller_pid;
    health.controller_start_id = continuity_c1.controller_start_id;
    memcpy(
        health.controller_binary_sha256,
        continuity_c1.controller_binary_sha256,
        65U
    );
    memcpy(
        health.controller_identity_sha256,
        continuity_c1.controller_identity_sha256,
        65U
    );
    memcpy(
        health.process_attestation_sha256,
        continuity_c1.process_attestation_sha256,
        65U
    );
    if (wls_guardian_controller_identity_live(
            home, &health, child_job
        ) != 0
        || wls_guardian_broker_identity_live(
            home, &continuity_c1, child_job
        ) != 0
        || wls_guardian_data_plane_identity_live(
            home, &health, child_job, &attestation
        ) != 0
        || attestation.pid != continuity_c1.data_plane_pid
        || attestation.start_id != continuity_c1.data_plane_start_id
        || sodium_memcmp(
            attestation.binary_digest,
            continuity_c1.data_plane_binary_sha256,
            64U
        ) != 0
        || attestation.publication_generation
            != continuity_c1.attested_publication_generation
        || wls_guardian_pinned_reread(
            &pin, length, &contents_c2
        ) != 0
        || wls_guardian_continuity_parse(
            home, contents_c2, length, &continuity_c2
        ) != 0
        || sodium_memcmp(
            continuity_c1.raw_sha256,
            continuity_c2.raw_sha256,
            64U
        ) != 0) goto cleanup;
    canonical_length = _snprintf_s(
        canonical,
        sizeof(canonical),
        _TRUNCATE,
        "WLS-GUARDIAN-OBSERVATION-IDENTITY/3\n"
        "host_id=%s\nhost_boot_id=%s\nactive_slot=%s\n"
        "runtime_generation=%s\n"
        "controller_epoch=%s\n"
        "controller_pid=%lu\ncontroller_start_id=%llu\n"
        "controller_binary_sha256=%s\n"
        "controller_identity_sha256=%s\n"
        "broker_pid=%lu\nbroker_start_id=%llu\n"
        "broker_binary_sha256=%s\n"
        "data_plane_pid=%lu\ndata_plane_start_id=%llu\n"
        "data_plane_binary_sha256=%s\n"
        "data_plane_runtime_generation=%s\n",
        host_id,
        continuity_c1.host_boot_id,
        continuity_c1.active_slot,
        runtime_generation,
        health.controller_epoch,
        health.controller_pid,
        health.controller_start_id,
        health.controller_binary_sha256,
        health.controller_identity_sha256,
        continuity_c1.broker_pid,
        continuity_c1.broker_start_id,
        continuity_c1.broker_binary_sha256,
        attestation.pid,
        attestation.start_id,
        attestation.binary_digest,
        attestation.runtime_generation
    );
    if (canonical_length <= 0
        || canonical_length >= (int)sizeof(canonical)
        || wls_guardian_sha256(
            (const unsigned char *)canonical,
            (size_t)canonical_length,
            sample->observation_identity_sha256
        ) != 0) goto cleanup;
    sample->state = strcmp(
        continuity_c1.admission_state, "HEALTHY"
    ) == 0
        ? WLS_GUARDIAN_CONTINUITY_PROMOTION_HEALTHY
        : WLS_GUARDIAN_CONTINUITY_ONLY;
    sample->promotion_healthy_since_monotonic_ms
        = continuity_c1.promotion_healthy_since_monotonic_ms;
    sample->issued_monotonic_ms = continuity_c1.issued_monotonic_ms;
    memcpy(sample->raw_sha256, continuity_c1.raw_sha256, 65U);
    result = 0;
cleanup:
    if (contents_c2 != NULL) {
        wls_guardian_contents_free(&contents_c2, length);
    }
    if (contents_c1 != NULL) {
        wls_guardian_contents_free(&contents_c1, length);
    }
    wls_guardian_pinned_close(&pin);
    ZeroMemory(path, sizeof(path));
    ZeroMemory(active_slot, sizeof(active_slot));
    SecureZeroMemory(receipt_sddl, sizeof(receipt_sddl));
    sodium_memzero(boot_id, sizeof(boot_id));
    SecureZeroMemory(canonical, sizeof(canonical));
    SecureZeroMemory(&health, sizeof(health));
    SecureZeroMemory(&continuity_c1, sizeof(continuity_c1));
    SecureZeroMemory(&continuity_c2, sizeof(continuity_c2));
    SecureZeroMemory(&attestation, sizeof(attestation));
    if (result != 0 && sample != NULL) {
        SecureZeroMemory(sample, sizeof(*sample));
    }
    return result;
}

static void wls_guardian_acl_proof_free(
    struct wls_guardian_acl_proof *proof
) {
    if (proof == NULL) return;
    if (proof->descriptor != NULL) LocalFree(proof->descriptor);
    SecureZeroMemory(proof, sizeof(*proof));
}

/* Decode the schema-4 ACL proof without accepting alternate base64 or SDDL
 * spellings.  PHP signs the canonical OWNER|DACL SDDL bytes; accepting a
 * semantically equivalent spelling here would split the recovery authority
 * between two serializations. */
static int wls_guardian_acl_proof_read(
    const char *owner_sid,
    const char *sddl_base64,
    const char *expected_sha256,
    struct wls_guardian_acl_proof *proof
) {
    unsigned char decoded[WLS_GUARDIAN_MAX_ACL_BYTES + 1U];
    char encoded[
        sodium_base64_ENCODED_LEN(
            WLS_GUARDIAN_MAX_ACL_BYTES,
            sodium_base64_VARIANT_ORIGINAL
        )
    ];
    char digest[65];
    LPSTR canonical = NULL;
    LPSTR observed_owner = NULL;
    PSID descriptor_owner = NULL;
    PACL descriptor_dacl = NULL;
    SECURITY_DESCRIPTOR_CONTROL control = 0U;
    DWORD revision = 0U;
    BOOL owner_defaulted = FALSE;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    size_t decoded_length = 0U;
    size_t encoded_length;
    int result = 1;
    SecureZeroMemory(decoded, sizeof(decoded));
    SecureZeroMemory(encoded, sizeof(encoded));
    sodium_memzero(digest, sizeof(digest));
    if (owner_sid == NULL || sddl_base64 == NULL
        || expected_sha256 == NULL || proof == NULL
        || (strcmp(owner_sid, "S-1-5-18") != 0
            && strcmp(owner_sid, "S-1-5-32-544") != 0)
        || !wls_is_hex(expected_sha256, 64U)
        || strlen(sddl_base64) == 0U
        || strlen(sddl_base64) >= sizeof(encoded)) return 1;
    SecureZeroMemory(proof, sizeof(*proof));
    if (sodium_base642bin(
            decoded,
            WLS_GUARDIAN_MAX_ACL_BYTES,
            sddl_base64,
            strlen(sddl_base64),
            NULL,
            &decoded_length,
            NULL,
            sodium_base64_VARIANT_ORIGINAL
        ) != 0
        || decoded_length == 0U
        || decoded_length > WLS_GUARDIAN_MAX_ACL_BYTES
        || memchr(decoded, '\0', decoded_length) != NULL) goto cleanup;
    encoded_length = sodium_base64_ENCODED_LEN(
        decoded_length, sodium_base64_VARIANT_ORIGINAL
    );
    if (encoded_length > sizeof(encoded)
        || sodium_bin2base64(
            encoded,
            sizeof(encoded),
            decoded,
            decoded_length,
            sodium_base64_VARIANT_ORIGINAL
        ) == NULL
        || strcmp(encoded, sddl_base64) != 0
        || wls_guardian_sha256(
            decoded, decoded_length, digest
        ) != 0
        || sodium_memcmp(digest, expected_sha256, 64U) != 0) goto cleanup;
    decoded[decoded_length] = '\0';
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorA(
            (const char *)decoded,
            SDDL_REVISION_1,
            &proof->descriptor,
            NULL
        )
        || !IsValidSecurityDescriptor(proof->descriptor)
        || !GetSecurityDescriptorControl(
            proof->descriptor, &control, &revision
        )
        || (control & SE_DACL_PROTECTED) == 0U
        || !GetSecurityDescriptorOwner(
            proof->descriptor, &descriptor_owner, &owner_defaulted
        )
        || descriptor_owner == NULL || owner_defaulted
        || !GetSecurityDescriptorDacl(
            proof->descriptor,
            &dacl_present,
            &descriptor_dacl,
            &dacl_defaulted
        )
        || !dacl_present || descriptor_dacl == NULL || dacl_defaulted
        || !ConvertSidToStringSidA(descriptor_owner, &observed_owner)
        || strcmp(observed_owner, owner_sid) != 0
        || !ConvertSecurityDescriptorToStringSecurityDescriptorA(
            proof->descriptor,
            SDDL_REVISION_1,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &canonical,
            NULL
        )
        || strlen(canonical) != decoded_length
        || sodium_memcmp(canonical, decoded, decoded_length) != 0
        || strlen(canonical) >= sizeof(proof->canonical)
        || strlen(owner_sid) >= sizeof(proof->owner_sid)) goto cleanup;
    memcpy(proof->canonical, canonical, decoded_length + 1U);
    memcpy(proof->owner_sid, owner_sid, strlen(owner_sid) + 1U);
    memcpy(proof->sha256, digest, sizeof(proof->sha256));
    result = 0;
cleanup:
    if (canonical != NULL) LocalFree(canonical);
    if (observed_owner != NULL) LocalFree(observed_owner);
    SecureZeroMemory(decoded, sizeof(decoded));
    SecureZeroMemory(encoded, sizeof(encoded));
    sodium_memzero(digest, sizeof(digest));
    if (result != 0) wls_guardian_acl_proof_free(proof);
    return result;
}

static int wls_guardian_privilege_enable_restore(
    struct wls_guardian_privilege_scope *scope
) {
    TOKEN_PRIVILEGES requested;
    DWORD previous_bytes = sizeof(scope->previous);
    if (scope == NULL) return 1;
    SecureZeroMemory(scope, sizeof(*scope));
    SecureZeroMemory(&requested, sizeof(requested));
    if (!OpenProcessToken(
            GetCurrentProcess(),
            TOKEN_QUERY | TOKEN_ADJUST_PRIVILEGES,
            &scope->token
        )
        || !LookupPrivilegeValueW(
            NULL, SE_RESTORE_NAME, &requested.Privileges[0].Luid
        )) goto failed;
    requested.PrivilegeCount = 1U;
    requested.Privileges[0].Attributes = SE_PRIVILEGE_ENABLED;
    SetLastError(ERROR_SUCCESS);
    if (!AdjustTokenPrivileges(
            scope->token,
            FALSE,
            &requested,
            sizeof(scope->previous),
            &scope->previous,
            &previous_bytes
        )
        || GetLastError() == ERROR_NOT_ALL_ASSIGNED
        || previous_bytes > sizeof(scope->previous)) goto failed;
    scope->previous_bytes = previous_bytes;
    scope->enabled = 1;
    SecureZeroMemory(&requested, sizeof(requested));
    return 0;
failed:
    SecureZeroMemory(&requested, sizeof(requested));
    if (scope->token != NULL) CloseHandle(scope->token);
    SecureZeroMemory(scope, sizeof(*scope));
    return 1;
}

static int wls_guardian_privilege_restore(
    struct wls_guardian_privilege_scope *scope
) {
    int result = 1;
    if (scope == NULL) return 1;
    if (scope->enabled && scope->token != NULL
        && AdjustTokenPrivileges(
            scope->token,
            FALSE,
            &scope->previous,
            0U,
            NULL,
            NULL
        )) result = 0;
    if (scope->token != NULL) CloseHandle(scope->token);
    SecureZeroMemory(scope, sizeof(*scope));
    return result;
}

/* Apply an authenticated descriptor through the already pinned object and
 * prove both its file identity and canonical OWNER|DACL after-image before
 * releasing the handle. */
static int wls_guardian_acl_apply_exact(
    struct wls_guardian_pinned_file *pin,
    int directory,
    const struct wls_guardian_acl_proof *proof
) {
    PSID owner = NULL;
    PACL dacl = NULL;
    PSECURITY_DESCRIPTOR observed = NULL;
    LPSTR canonical = NULL;
    BOOL owner_defaulted = FALSE;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    struct wls_guardian_privilege_scope privilege;
    int privilege_enabled = 0;
    int result = 1;
    SecureZeroMemory(&privilege, sizeof(privilege));
    if (pin == NULL || proof == NULL || proof->descriptor == NULL
        || pin->handle == NULL || pin->handle == INVALID_HANDLE_VALUE
        || !wls_guardian_pinned_identity_unchanged(pin, directory)
        || !GetSecurityDescriptorOwner(
            proof->descriptor, &owner, &owner_defaulted
        )
        || owner == NULL || owner_defaulted
        || !GetSecurityDescriptorDacl(
            proof->descriptor, &dacl_present, &dacl, &dacl_defaulted
        )
        || !dacl_present || dacl == NULL || dacl_defaulted
        || wls_guardian_privilege_enable_restore(&privilege) != 0) {
        goto cleanup;
    }
    privilege_enabled = 1;
    if (SetSecurityInfo(
            pin->handle,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION
                | DACL_SECURITY_INFORMATION
                | PROTECTED_DACL_SECURITY_INFORMATION,
            owner,
            NULL,
            dacl,
            NULL
        ) != ERROR_SUCCESS
        || !wls_guardian_pinned_identity_unchanged(pin, directory)
        || GetSecurityInfo(
            pin->handle,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            NULL,
            NULL,
            NULL,
            NULL,
            &observed
        ) != ERROR_SUCCESS
        || observed == NULL
        || !ConvertSecurityDescriptorToStringSecurityDescriptorA(
            observed,
            SDDL_REVISION_1,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &canonical,
            NULL
        )
        || strcmp(canonical, proof->canonical) != 0
        || !wls_guardian_pinned_identity_unchanged(pin, directory)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (canonical != NULL) LocalFree(canonical);
    if (observed != NULL) LocalFree(observed);
    if (privilege_enabled
        && wls_guardian_privilege_restore(&privilege) != 0) result = 1;
    else if (!privilege_enabled) SecureZeroMemory(&privilege, sizeof(privilege));
    return result;
}

static int wls_guardian_local_system_process(void)
{
    HANDLE token = NULL;
    TOKEN_USER *user = NULL;
    DWORD needed = 0U;
    DWORD allocated = 0U;
    unsigned char system_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD session_id = MAXDWORD;
    int result = 1;
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &token)) {
        goto cleanup;
    }
    (void)GetTokenInformation(token, TokenUser, NULL, 0U, &needed);
    if (needed < sizeof(TOKEN_USER)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    allocated = needed;
    user = (TOKEN_USER *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
    );
    if (user == NULL
        || !GetTokenInformation(
            token, TokenUser, user, allocated, &needed
        )
        || !IsValidSid(user->User.Sid)
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_buffer, &system_length
        )
        || !EqualSid(user->User.Sid, system_buffer)
        || !ProcessIdToSessionId(GetCurrentProcessId(), &session_id)
        || session_id != 0U) goto cleanup;
    result = 0;
cleanup:
    if (user != NULL) {
        SecureZeroMemory(user, allocated);
        HeapFree(GetProcessHeap(), 0U, user);
    }
    if (token != NULL) CloseHandle(token);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    return result;
}

/* The service object is a host privilege boundary.  Its DACL must not
 * inherit from SCM or grant a recoverable service identity control over its
 * own registration: only LocalSystem and Builtin Administrators own the
 * exact SERVICE_ALL_ACCESS contract. */
static int wls_guardian_scm_service_dacl_exact(
    PSECURITY_DESCRIPTOR descriptor
) {
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
    DWORD index;
    unsigned int system_count = 0U;
    unsigned int administrators_count = 0U;
    int result = 1;
    ZeroMemory(&information, sizeof(information));
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    if (descriptor == NULL
        || !IsValidSecurityDescriptor(descriptor)
        || !GetSecurityDescriptorControl(descriptor, &control, &revision)
        || (control & SE_DACL_PROTECTED) == 0U
        || !GetSecurityDescriptorDacl(
            descriptor, &dacl_present, &dacl, &dacl_defaulted
        )
        || !dacl_present || dacl_defaulted || dacl == NULL
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_buffer, &system_length
        )
        || !CreateWellKnownSid(
            WinBuiltinAdministratorsSid,
            NULL,
            administrators_buffer,
            &administrators_length
        )
        || !GetAclInformation(
            dacl, &information, sizeof(information), AclSizeInformation
        )) goto cleanup;
    for (index = 0U; index < information.AceCount; ++index) {
        ACCESS_ALLOWED_ACE *ace = NULL;
        PSID sid;
        if (!GetAce(dacl, index, (LPVOID *)&ace)
            || ace == NULL
            || ace->Header.AceType != ACCESS_ALLOWED_ACE_TYPE
            || ace->Header.AceFlags != 0U) goto cleanup;
        sid = (PSID)&ace->SidStart;
        if (!IsValidSid(sid) || ace->Mask != SERVICE_ALL_ACCESS) goto cleanup;
        if (EqualSid(sid, system_buffer)) {
            if (++system_count != 1U) goto cleanup;
        } else if (EqualSid(sid, administrators_buffer)) {
            if (++administrators_count != 1U) goto cleanup;
        } else {
            goto cleanup;
        }
    }
    if (information.AceCount != 2U
        || system_count != 1U
        || administrators_count != 1U) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    return result;
}

static int wls_guardian_scm_service_dacl_query_exact(SC_HANDLE service)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    DWORD bytes = 0U;
    DWORD allocated_bytes = 0U;
    int result = 1;
    if (service == NULL) return 1;
    (void)QueryServiceObjectSecurity(
        service, DACL_SECURITY_INFORMATION, NULL, 0U, &bytes
    );
    if (bytes < sizeof(SECURITY_DESCRIPTOR)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    allocated_bytes = bytes;
    descriptor = (PSECURITY_DESCRIPTOR)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated_bytes
    );
    if (descriptor == NULL
        || !QueryServiceObjectSecurity(
            service,
            DACL_SECURITY_INFORMATION,
            descriptor,
            allocated_bytes,
            &bytes
        )
        || wls_guardian_scm_service_dacl_exact(descriptor) != 0) goto cleanup;
    result = 0;
cleanup:
    if (descriptor != NULL) {
        SecureZeroMemory(descriptor, allocated_bytes);
        HeapFree(GetProcessHeap(), 0U, descriptor);
    }
    return result;
}

static int wls_guardian_scm_definition_valid(
    const wchar_t *home,
    const wchar_t *run_directory,
    const wchar_t *guardian
) {
    SC_HANDLE manager = NULL;
    SC_HANDLE service = NULL;
    QUERY_SERVICE_CONFIGW *configuration = NULL;
    SERVICE_FAILURE_ACTIONSW *failure = NULL;
    SERVICE_FAILURE_ACTIONS_FLAG *failure_flag = NULL;
    SERVICE_PRESHUTDOWN_INFO *preshutdown = NULL;
    SERVICE_SID_INFO *sid_info = NULL;
    BYTE *failure_buffer = NULL;
    BYTE *flag_buffer = NULL;
    BYTE *preshutdown_buffer = NULL;
    BYTE *sid_buffer = NULL;
    wchar_t expected_command[WLS_PATH_CHARS * 2U];
    DWORD configuration_bytes = 0U;
    DWORD failure_bytes = 0U;
    DWORD flag_bytes = 0U;
    DWORD preshutdown_bytes = 0U;
    DWORD preshutdown_allocated_bytes = 0U;
    DWORD sid_bytes = 0U;
    int result = 1;
    ZeroMemory(expected_command, sizeof(expected_command));
    if (wls_guardian_service_command_line(
            home,
            run_directory,
            guardian,
            expected_command,
            sizeof(expected_command) / sizeof(expected_command[0])
        ) != 0) goto cleanup;
    manager = OpenSCManagerW(NULL, NULL, SC_MANAGER_CONNECT);
    if (manager == NULL) goto cleanup;
    service = OpenServiceW(
        manager,
        L"weline-wls-gateway-v2",
        SERVICE_QUERY_CONFIG | READ_CONTROL
    );
    if (service == NULL) goto cleanup;
    (void)QueryServiceConfigW(service, NULL, 0U, &configuration_bytes);
    if (configuration_bytes < sizeof(*configuration)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    configuration = (QUERY_SERVICE_CONFIGW *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, configuration_bytes
    );
    if (configuration == NULL
        || !QueryServiceConfigW(
            service,
            configuration,
            configuration_bytes,
            &configuration_bytes
        )
        || configuration->dwServiceType != SERVICE_WIN32_OWN_PROCESS
        || configuration->dwStartType != SERVICE_AUTO_START
        || configuration->lpBinaryPathName == NULL
        || wcscmp(
            configuration->lpBinaryPathName, expected_command
        ) != 0
        || configuration->lpServiceStartName == NULL
        || wcscmp(configuration->lpServiceStartName, L"LocalSystem") != 0) {
        goto cleanup;
    }
    (void)QueryServiceConfig2W(
        service,
        SERVICE_CONFIG_FAILURE_ACTIONS,
        NULL,
        0U,
        &failure_bytes
    );
    if (failure_bytes < sizeof(*failure)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    failure_buffer = (BYTE *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, failure_bytes
    );
    if (failure_buffer == NULL
        || !QueryServiceConfig2W(
            service,
            SERVICE_CONFIG_FAILURE_ACTIONS,
            failure_buffer,
            failure_bytes,
            &failure_bytes
        )) goto cleanup;
    failure = (SERVICE_FAILURE_ACTIONSW *)(void *)failure_buffer;
    if (failure->dwResetPeriod != 0U
        || failure->cActions != 3U
        || failure->lpsaActions == NULL
        || failure->lpsaActions[0].Type != SC_ACTION_RESTART
        || failure->lpsaActions[1].Type != SC_ACTION_RESTART
        || failure->lpsaActions[2].Type != SC_ACTION_RESTART
        || failure->lpsaActions[0].Delay != 5000U
        || failure->lpsaActions[1].Delay != 5000U
        || failure->lpsaActions[2].Delay != 5000U) goto cleanup;
    (void)QueryServiceConfig2W(
        service,
        SERVICE_CONFIG_FAILURE_ACTIONS_FLAG,
        NULL,
        0U,
        &flag_bytes
    );
    if (flag_bytes < sizeof(*failure_flag)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    flag_buffer = (BYTE *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, flag_bytes
    );
    if (flag_buffer == NULL
        || !QueryServiceConfig2W(
            service,
            SERVICE_CONFIG_FAILURE_ACTIONS_FLAG,
            flag_buffer,
            flag_bytes,
            &flag_bytes
        )) goto cleanup;
    failure_flag = (SERVICE_FAILURE_ACTIONS_FLAG *)(void *)flag_buffer;
    if (!failure_flag->fFailureActionsOnNonCrashFailures) goto cleanup;
    (void)QueryServiceConfig2W(
        service,
        SERVICE_CONFIG_PRESHUTDOWN_INFO,
        NULL,
        0U,
        &preshutdown_bytes
    );
    if (preshutdown_bytes < sizeof(*preshutdown)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    preshutdown_allocated_bytes = preshutdown_bytes;
    preshutdown_buffer = (BYTE *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, preshutdown_allocated_bytes
    );
    if (preshutdown_buffer == NULL
        || !QueryServiceConfig2W(
            service,
            SERVICE_CONFIG_PRESHUTDOWN_INFO,
            preshutdown_buffer,
            preshutdown_allocated_bytes,
            &preshutdown_bytes
        )) goto cleanup;
    preshutdown = (SERVICE_PRESHUTDOWN_INFO *)(void *)preshutdown_buffer;
    if (preshutdown->dwPreshutdownTimeout != 330000U
        || wls_guardian_scm_service_dacl_query_exact(service) != 0) {
        goto cleanup;
    }
    (void)QueryServiceConfig2W(
        service,
        SERVICE_CONFIG_SERVICE_SID_INFO,
        NULL,
        0U,
        &sid_bytes
    );
    if (sid_bytes < sizeof(*sid_info)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    sid_buffer = (BYTE *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, sid_bytes
    );
    if (sid_buffer == NULL
        || !QueryServiceConfig2W(
            service,
            SERVICE_CONFIG_SERVICE_SID_INFO,
            sid_buffer,
            sid_bytes,
            &sid_bytes
        )) goto cleanup;
    sid_info = (SERVICE_SID_INFO *)(void *)sid_buffer;
    if (sid_info->dwServiceSidType != SERVICE_SID_TYPE_UNRESTRICTED) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (preshutdown_buffer != NULL) {
        SecureZeroMemory(preshutdown_buffer, preshutdown_allocated_bytes);
        HeapFree(GetProcessHeap(), 0U, preshutdown_buffer);
    }
    if (sid_buffer != NULL) {
        SecureZeroMemory(sid_buffer, sid_bytes);
        HeapFree(GetProcessHeap(), 0U, sid_buffer);
    }
    if (flag_buffer != NULL) {
        SecureZeroMemory(flag_buffer, flag_bytes);
        HeapFree(GetProcessHeap(), 0U, flag_buffer);
    }
    if (failure_buffer != NULL) {
        SecureZeroMemory(failure_buffer, failure_bytes);
        HeapFree(GetProcessHeap(), 0U, failure_buffer);
    }
    if (configuration != NULL) {
        SecureZeroMemory(configuration, configuration_bytes);
        HeapFree(GetProcessHeap(), 0U, configuration);
    }
    if (service != NULL) CloseServiceHandle(service);
    if (manager != NULL) CloseServiceHandle(manager);
    SecureZeroMemory(expected_command, sizeof(expected_command));
    return result;
}

static int wls_guardian_scm_service_dacl_self_test(void)
{
    static const char *const invalid[] = {
        "D:P(A;;0xF01FF;;;SY)(A;;0xF01FF;;;BA)(A;;GR;;;BU)",
        "D:(A;ID;0xF01FF;;;SY)(A;;0xF01FF;;;BA)",
        "D:P(A;;0xF01FF;;;SY)(A;;GR;;;BA)"
    };
    PSECURITY_DESCRIPTOR descriptor = NULL;
    size_t index;
    int result = 1;
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorA(
            "D:P(A;;0xF01FF;;;SY)(A;;0xF01FF;;;BA)",
            SDDL_REVISION_1,
            &descriptor,
            NULL
        )
        || wls_guardian_scm_service_dacl_exact(descriptor) != 0) {
        goto cleanup;
    }
    LocalFree(descriptor);
    descriptor = NULL;
    for (index = 0U; index < sizeof(invalid) / sizeof(invalid[0]); ++index) {
        if (!ConvertStringSecurityDescriptorToSecurityDescriptorA(
                invalid[index], SDDL_REVISION_1, &descriptor, NULL
            )
            || wls_guardian_scm_service_dacl_exact(descriptor) == 0) {
            goto cleanup;
        }
        LocalFree(descriptor);
        descriptor = NULL;
    }
    result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    return result;
}

static int wls_guardian_core_self_test(void)
{
    static const wchar_t expected_child[] =
        L"\"C:\\ProgramData\\Weline\\Gateway\\bin\\wls-gateway-launcher.exe\" "
        L"--guardian-child --home=\"C:\\ProgramData\\Weline\\Gateway\" "
        L"--run=\"C:\\ProgramData\\Weline\\Gateway\\runtime\\run\" "
        L"--guardian-parent-handle=1 --guardian-stop-handle=2 "
        L"--guardian-reload-handle=3 --guardian-ready-handle=4 "
        L"--guardian-health-handle=5";
    static const wchar_t expected_service[] =
        L"\"C:\\ProgramData\\Weline\\Gateway\\guardian\\v1\\"
        L"wls-gateway-guardian.exe\" --service "
        L"--home=\"C:\\ProgramData\\Weline\\Gateway\" "
        L"--run=\"C:\\ProgramData\\Weline\\Gateway\\runtime\\run\"";
    wchar_t command[2048];
    HANDLE parsed = NULL;
    ZeroMemory(command, sizeof(command));
    if (wls_guardian_scm_service_dacl_self_test() != 0
        || wls_guardian_child_command_line(
            L"C:\\ProgramData\\Weline\\Gateway\\bin\\wls-gateway-launcher.exe",
            L"C:\\ProgramData\\Weline\\Gateway",
            L"C:\\ProgramData\\Weline\\Gateway\\runtime\\run",
            (HANDLE)(uintptr_t)1U,
            (HANDLE)(uintptr_t)2U,
            (HANDLE)(uintptr_t)3U,
            (HANDLE)(uintptr_t)4U,
            (HANDLE)(uintptr_t)5U,
            command,
            sizeof(command) / sizeof(command[0])
        ) != 0
        || wcscmp(command, expected_child) != 0
        || wls_guardian_child_command_line(
            L"C:\\ProgramData\\Weline\\Gateway\\bin\\wls-gateway-launcher.exe",
            L"C:\\ProgramData\\Weline\\Gateway",
            L"C:\\ProgramData\\Weline\\Gateway\\runtime\\run",
            (HANDLE)(uintptr_t)1U,
            (HANDLE)(uintptr_t)1U,
            (HANDLE)(uintptr_t)3U,
            (HANDLE)(uintptr_t)4U,
            (HANDLE)(uintptr_t)5U,
            command,
            sizeof(command) / sizeof(command[0])
        ) == 0
        || wls_guardian_child_command_line(
            L"C:\\ProgramData\\Weline\\Gateway\\bin\\wls-gateway-launcher.exe",
            L"C:\\ProgramData\\Weline\\Gateway",
            L"C:\\ProgramData\\Weline\\Gateway\\runtime\\run",
            NULL,
            (HANDLE)(uintptr_t)2U,
            (HANDLE)(uintptr_t)3U,
            (HANDLE)(uintptr_t)4U,
            (HANDLE)(uintptr_t)5U,
            command,
            sizeof(command) / sizeof(command[0])
        ) == 0
        || wls_guardian_child_command_line(
            L"C:\\bad\"path\\wls-gateway-launcher.exe",
            L"C:\\ProgramData\\Weline\\Gateway",
            L"C:\\ProgramData\\Weline\\Gateway\\runtime\\run",
            (HANDLE)(uintptr_t)1U,
            (HANDLE)(uintptr_t)2U,
            (HANDLE)(uintptr_t)3U,
            (HANDLE)(uintptr_t)4U,
            (HANDLE)(uintptr_t)5U,
            command,
            sizeof(command) / sizeof(command[0])
        ) == 0) return 1;
    ZeroMemory(command, sizeof(command));
    if (wls_guardian_service_command_line(
            L"C:\\ProgramData\\Weline\\Gateway",
            L"C:\\ProgramData\\Weline\\Gateway\\runtime\\run",
            L"C:\\ProgramData\\Weline\\Gateway\\guardian\\v1\\"
                L"wls-gateway-guardian.exe",
            command,
            sizeof(command) / sizeof(command[0])
        ) != 0
        || wcscmp(command, expected_service) != 0
        || wcsstr(command, L"--profile") != NULL
        || wcsstr(command, L"wls-gateway-launcher.exe --service") != NULL
        || wls_guardian_service_command_line(
            L"C:\\ProgramData\\Weline\\Gateway\"",
            L"C:\\ProgramData\\Weline\\Gateway\\runtime\\run",
            L"C:\\ProgramData\\Weline\\Gateway\\guardian\\v1\\"
                L"wls-gateway-guardian.exe",
            command,
            sizeof(command) / sizeof(command[0])
        ) == 0
        || wls_guardian_wide_handle(L"1", &parsed) != 0
        || parsed != (HANDLE)(uintptr_t)1U
        || wls_guardian_wide_handle(L"01", &parsed) == 0
        || wls_guardian_wide_handle(L"0", &parsed) == 0
        || wls_guardian_wide_handle(L"-1", &parsed) == 0) return 1;
    return 0;
}

#ifdef WLS_GUARDIAN_EXECUTABLE
/* Preshutdown must survive a Guardian crash long enough to suppress an SCM
 * recovery restart in the same boot.  It is intentionally boot-scoped: a
 * later host boot removes a verified stale request before normal auto-start.
 * A malformed or undeletable marker remains a controlled stop (fail closed). */
static const wchar_t wls_service_stop_intent_registry_path[] =
    L"SOFTWARE\\Weline\\WLS2\\ServicePreshutdownIntent";

static int wls_guardian_service_stop_intent_registry_dacl_exact(HKEY key)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PSID owner = NULL;
    PACL dacl = NULL;
    BOOL present = FALSE;
    BOOL defaulted = FALSE;
    SECURITY_DESCRIPTOR_CONTROL control = 0U;
    DWORD revision = 0U;
    ACL_SIZE_INFORMATION information;
    unsigned char system_buffer[SECURITY_MAX_SID_SIZE];
    unsigned char administrators_buffer[SECURITY_MAX_SID_SIZE];
    DWORD system_length = sizeof(system_buffer);
    DWORD administrators_length = sizeof(administrators_buffer);
    unsigned int system_count = 0U;
    unsigned int administrators_count = 0U;
    DWORD index;
    int result = 1;
    ZeroMemory(&information, sizeof(information));
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    if (key == NULL
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
        || GetSecurityInfo(
            (HANDLE)key,
            SE_REGISTRY_KEY,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &owner,
            NULL,
            &dacl,
            NULL,
            &descriptor
        ) != ERROR_SUCCESS
        || descriptor == NULL || owner == NULL
        || !EqualSid(owner, system_buffer)
        || !GetSecurityDescriptorControl(
            descriptor,
            &control,
            &revision
        )
        || (control & SE_DACL_PROTECTED) == 0U
        || !GetSecurityDescriptorDacl(
            descriptor,
            &present,
            &dacl,
            &defaulted
        )
        || !present || defaulted || dacl == NULL
        || !GetAclInformation(
            dacl,
            &information,
            sizeof(information),
            AclSizeInformation
        )
        || information.AceCount != 2U) goto cleanup;
    for (index = 0U; index < information.AceCount; index++) {
        ACCESS_ALLOWED_ACE *ace = NULL;
        PSID sid;
        if (!GetAce(dacl, index, (LPVOID *)&ace)
            || ace == NULL
            || ace->Header.AceType != ACCESS_ALLOWED_ACE_TYPE
            || ace->Header.AceFlags != 0U
            || ace->Mask != KEY_ALL_ACCESS) goto cleanup;
        sid = (PSID)&ace->SidStart;
        if (!IsValidSid(sid)) goto cleanup;
        if (EqualSid(sid, system_buffer)) {
            if (++system_count != 1U) goto cleanup;
        } else if (EqualSid(sid, administrators_buffer)) {
            if (++administrators_count != 1U) goto cleanup;
        } else {
            goto cleanup;
        }
    }
    if (system_count == 1U && administrators_count == 1U) result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    return result;
}

static int wls_service_stop_intent_volatile_persisted(
    const char boot_id[65]
)
{
    PSECURITY_DESCRIPTOR descriptor = NULL;
    SECURITY_ATTRIBUTES attributes;
    HKEY key = NULL;
    DWORD disposition = 0U;
    DWORD type = 0U;
    DWORD bytes = 65U * (DWORD)sizeof(wchar_t);
    wchar_t boot_wide[65];
    wchar_t verified[65];
    size_t index;
    int created = 0;
    int result = 1;
    ZeroMemory(&attributes, sizeof(attributes));
    SecureZeroMemory(boot_wide, sizeof(boot_wide));
    SecureZeroMemory(verified, sizeof(verified));
    if (boot_id == NULL || !wls_is_hex(boot_id, 64U)) goto cleanup;
    for (index = 0U; index < 64U; index++) {
        boot_wide[index] = (wchar_t)(unsigned char)boot_id[index];
    }
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"O:SYD:P(A;;KA;;;SY)(A;;KA;;;BA)",
            SDDL_REVISION_1,
            &descriptor,
            NULL
        ) || descriptor == NULL) goto cleanup;
    attributes.nLength = (DWORD)sizeof(attributes);
    attributes.lpSecurityDescriptor = descriptor;
    attributes.bInheritHandle = FALSE;
    if (RegCreateKeyExW(
            HKEY_LOCAL_MACHINE,
            wls_service_stop_intent_registry_path,
            0U,
            NULL,
            REG_OPTION_VOLATILE,
            KEY_SET_VALUE | KEY_QUERY_VALUE | READ_CONTROL,
            &attributes,
            &key,
            &disposition
        ) != ERROR_SUCCESS
        || key == NULL
        || disposition != REG_CREATED_NEW_KEY) goto cleanup;
    created = 1;
    if (wls_guardian_service_stop_intent_registry_dacl_exact(key) != 0
        || RegSetValueExW(
            key,
            L"host_boot_id",
            0U,
            REG_SZ,
            (const BYTE *)boot_wide,
            (DWORD)sizeof(boot_wide)
        ) != ERROR_SUCCESS
        || RegFlushKey(key) != ERROR_SUCCESS
        || RegQueryValueExW(
            key,
            L"host_boot_id",
            NULL,
            &type,
            (BYTE *)verified,
            &bytes
        ) != ERROR_SUCCESS
        || type != REG_SZ || bytes != (DWORD)sizeof(verified)
        || memcmp(verified, boot_wide, sizeof(verified)) != 0) goto cleanup;
    result = 0;
cleanup:
    if (key != NULL) RegCloseKey(key);
    if (result != 0 && created) {
        (void)RegDeleteKeyW(
            HKEY_LOCAL_MACHINE,
            wls_service_stop_intent_registry_path
        );
    }
    if (descriptor != NULL) LocalFree(descriptor);
    SecureZeroMemory(boot_wide, sizeof(boot_wide));
    SecureZeroMemory(verified, sizeof(verified));
    return result;
}

static int wls_guardian_service_stop_intent_volatile_active(void)
{
    HKEY key = NULL;
    DWORD type = 0U;
    DWORD bytes = 65U * (DWORD)sizeof(wchar_t);
    wchar_t stored[65];
    char boot_id[65];
    size_t index;
    LONG status;
    int active = 1;
    SecureZeroMemory(stored, sizeof(stored));
    SecureZeroMemory(boot_id, sizeof(boot_id));
    status = RegOpenKeyExW(
        HKEY_LOCAL_MACHINE,
        wls_service_stop_intent_registry_path,
        0U,
        KEY_QUERY_VALUE | READ_CONTROL,
        &key
    );
    if (status == ERROR_FILE_NOT_FOUND || status == ERROR_PATH_NOT_FOUND) {
        return 0;
    }
    if (status != ERROR_SUCCESS || key == NULL
        || wls_guardian_service_stop_intent_registry_dacl_exact(key) != 0
        || RegQueryValueExW(
            key,
            L"host_boot_id",
            NULL,
            &type,
            (BYTE *)stored,
            &bytes
        ) != ERROR_SUCCESS
        || type != REG_SZ || bytes != (DWORD)sizeof(stored)
        || stored[64] != L'\0') goto cleanup;
    for (index = 0U; index < 64U; index++) {
        if (!((stored[index] >= L'0' && stored[index] <= L'9')
            || (stored[index] >= L'a' && stored[index] <= L'f'))) {
            goto cleanup;
        }
    }
    if (wls_boot_id(boot_id) != 0) goto cleanup;
    active = 0;
    for (index = 0U; index < 64U; index++) {
        if (stored[index] != (wchar_t)(unsigned char)boot_id[index]) {
            break;
        }
    }
    if (index == 64U) active = 1;
cleanup:
    if (key != NULL) RegCloseKey(key);
    if (!active && RegDeleteKeyW(
            HKEY_LOCAL_MACHINE,
            wls_service_stop_intent_registry_path
        ) != ERROR_SUCCESS) {
        active = 1;
    }
    SecureZeroMemory(stored, sizeof(stored));
    SecureZeroMemory(boot_id, sizeof(boot_id));
    return active;
}

static int wls_service_stop_intent_persisted(void)
{
    wchar_t path[WLS_PATH_CHARS];
    char boot_id[65];
    char contents[128];
    int length;
    int volatile_result = 1;
    int file_result = 1;
    SecureZeroMemory(boot_id, sizeof(boot_id));
    SecureZeroMemory(contents, sizeof(contents));
    if (wls_service_run == NULL
        || wls_join(
            path,
            WLS_PATH_CHARS,
            wls_service_run,
            L"service-preshutdown.intent"
        ) != 0
        || wls_boot_id(boot_id) != 0) {
        goto failed;
    }
    length = _snprintf_s(
        contents,
        sizeof(contents),
        _TRUNCATE,
        "WLS-SERVICE-PRESHUTDOWN/1\nhost_boot_id=%s\n",
        boot_id
    );
    volatile_result = wls_service_stop_intent_volatile_persisted(boot_id);
    if (length > 0 && wls_atomic_system_text(path, contents) == 0) {
        file_result = 0;
    }
    SecureZeroMemory(boot_id, sizeof(boot_id));
    SecureZeroMemory(contents, sizeof(contents));
    return volatile_result == 0 || file_result == 0 ? 0 : 1;
failed:
    SecureZeroMemory(boot_id, sizeof(boot_id));
    SecureZeroMemory(contents, sizeof(contents));
    return 1;
}

static int wls_guardian_service_stop_intent_active(
    const wchar_t *run_directory
) {
    static const char prefix[] = "WLS-SERVICE-PRESHUTDOWN/1\nhost_boot_id=";
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char boot_id[65];
    int active = 1;
    SecureZeroMemory(boot_id, sizeof(boot_id));
    if (wls_guardian_service_stop_intent_volatile_active()) goto cleanup;
    if (run_directory == NULL
        || wls_join(
            path,
            WLS_PATH_CHARS,
            run_directory,
            L"service-preshutdown.intent"
        ) != 0) {
        goto cleanup;
    }
    if (wls_read_file(path, sizeof(prefix) + 64U, &contents, &length) != 0) {
        DWORD error = GetLastError();
        active = error != ERROR_FILE_NOT_FOUND && error != ERROR_PATH_NOT_FOUND;
        goto cleanup;
    }
    if (length != sizeof(prefix) - 1U + 64U + 1U
        || memcmp(contents, prefix, sizeof(prefix) - 1U) != 0
        || contents[length - 1U] != '\n'
        || !wls_is_hex(
            (const char *)(contents + sizeof(prefix) - 1U),
            64U
        )
        || wls_boot_id(boot_id) != 0) {
        goto cleanup;
    }
    if (memcmp(
            contents + sizeof(prefix) - 1U,
            boot_id,
            64U
        ) == 0) {
        goto cleanup;
    }
    if (wls_delete_optional_durable(path) != 0) goto cleanup;
    active = 0;
cleanup:
    if (contents != NULL) {
        SecureZeroMemory(contents, length);
        HeapFree(GetProcessHeap(), 0U, contents);
    }
    SecureZeroMemory(boot_id, sizeof(boot_id));
    return active;
}
#endif

static HANDLE wls_guardian_singleton(void)
{
    SECURITY_ATTRIBUTES security;
    PSECURITY_DESCRIPTOR descriptor = NULL;
    HANDLE mutex = NULL;
    ZeroMemory(&security, sizeof(security));
    if (!ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"O:SYD:P(A;;GA;;;SY)(A;;GA;;;BA)",
            SDDL_REVISION_1,
            &descriptor,
            NULL
        )) return NULL;
    security.nLength = sizeof(security);
    security.lpSecurityDescriptor = descriptor;
    security.bInheritHandle = FALSE;
    mutex = CreateMutexW(
        &security,
        TRUE,
        L"Global\\WelineWlsGatewayV2RecoveryGuardian"
    );
    if (mutex == NULL || GetLastError() == ERROR_ALREADY_EXISTS) {
        if (mutex != NULL) CloseHandle(mutex);
        mutex = NULL;
    }
    LocalFree(descriptor);
    return mutex;
}
