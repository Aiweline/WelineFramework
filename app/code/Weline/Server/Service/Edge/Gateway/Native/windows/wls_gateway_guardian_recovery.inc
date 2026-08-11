/*
 * WLS 2.0 Windows whole-host recovery.
 *
 * This file is included after wls_gateway_guardian.inc and
 * wls_gateway_guardian_protocol.inc.  Recovery is deliberately implemented
 * inside the immutable Guardian translation unit: the Controller may seal a
 * request and its before-images, but only the Guardian can consume them,
 * restore ACLs with SeRestorePrivilege, update SCM, and advance the fixed
 * recovery transaction.
 *
 * A recovered signed slot names the WLS 2.0 Nginx data plane. Recovery never
 * creates, changes, moves, or removes the retirement artifact.
 */

#ifndef WLS_GATEWAY_GUARDIAN_RECOVERY_INC
#define WLS_GATEWAY_GUARDIAN_RECOVERY_INC

#define WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT 9U
#define WLS_GUARDIAN_RECOVERY_PATH_BYTES (8U * 1024U * 1024U)
#define WLS_GUARDIAN_RECOVERY_MAX_DEPTH 64U
#define WLS_GUARDIAN_RECOVERY_PLATFORM_DEFINITION_MAX (1024U * 1024U)
#define WLS_GUARDIAN_RECOVERY_PLATFORM_METADATA_MAX (16U * 1024U)
#define WLS_GUARDIAN_RECOVERY_PLATFORM_INTENT_MAX 2048U
#define WLS_GUARDIAN_RECOVERY_DIRECTORY_BUFFER (64U * 1024U)

struct wls_guardian_recovery_root_contract {
    const char *category;
    const wchar_t *live_relative;
    const char *root_id;
    const char *policy;
    const char *authority_profile;
    const char *authority_policy;
    const char *parent_authority_profile;
    const char *parent_authority_policy;
    int preserve_identity;
};

/* Inventory order is part of WLS-GUARDIAN-RECOVERY-INVENTORY/2. */
static const struct wls_guardian_recovery_root_contract
wls_guardian_recovery_root_contracts[
    WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT
] = {
    {
        "runtime-conf", L"runtime\\conf", "host/runtime/conf", "restore",
        "controller-runtime-child-v2",
        "controller-runtime-child-v2-recreate-sealed",
        "controller-data-plane-runtime-v2",
        "controller-data-plane-runtime-v2-fixed-parent", 0
    },
    {
        "runtime-run", L"runtime\\run", "host/runtime/run", "ephemeral",
        "controller-runtime-child-v2",
        "controller-runtime-child-v2-recreate-sealed",
        "controller-data-plane-runtime-v2",
        "controller-data-plane-runtime-v2-fixed-parent", 0
    },
    {
        "runtime-shadow", L"runtime\\shadow", "host/runtime/shadow",
        "ephemeral", "controller-runtime-child-v2",
        "controller-runtime-child-v2-recreate-sealed",
        "controller-data-plane-runtime-v2",
        "controller-data-plane-runtime-v2-fixed-parent", 0
    },
    {
        "runtime-temp", L"runtime\\temp", "host/runtime/temp", "ephemeral",
        "controller-runtime-child-v2",
        "controller-runtime-child-v2-recreate-sealed",
        "controller-data-plane-runtime-v2",
        "controller-data-plane-runtime-v2-fixed-parent", 0
    },
    {
        "snapshot-candidates-v2", L"snapshot-candidates-v2",
        "host/snapshot-candidates-v2", "restore",
        "controller-snapshot-candidates-private-v2",
        "controller-snapshot-candidates-private-v2-recreate-sealed",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 0
    },
    {
        "snapshots", L"snapshots", "host/snapshots-v1", "restore",
        "controller-data-plane-search-v2",
        "controller-data-plane-search-v2-recreate-sealed",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 0
    },
    {
        "snapshots-v2", L"snapshots-v2", "host/snapshots-v2", "restore",
        "root-data-plane-search-v2",
        "root-data-plane-search-v2-recreate-sealed",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 0
    },
    {
        "state", L"state", "host/state", "restore",
        "controller-private-v2", "controller-private-v2-preserve-identity",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 1
    },
    {
        "trust", L"trust", "host/trust", "restore",
        "root-controller-read-v2",
        "root-controller-read-v2-preserve-identity",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 1
    }
};

/* Transaction restore order; indexes refer to the fixed inventory table. */
static const unsigned int wls_guardian_recovery_restore_order[
    WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT
] = {4U, 5U, 6U, 0U, 1U, 2U, 3U, 7U, 8U};

struct wls_guardian_recovery_root_authority {
    const struct wls_guardian_recovery_root_contract *contract;
    int present;
    char device[9];
    char inode[17];
    char authority_sha256[65];
    char sddl_base64[
        sodium_base64_ENCODED_LEN(
            WLS_GUARDIAN_MAX_ACL_BYTES,
            sodium_base64_VARIANT_ORIGINAL
        )
    ];
    char parent_device[9];
    char parent_inode[17];
    char parent_authority_sha256[65];
    char parent_sddl_base64[
        sodium_base64_ENCODED_LEN(
            WLS_GUARDIAN_MAX_ACL_BYTES,
            sodium_base64_VARIANT_ORIGINAL
        )
    ];
    struct wls_guardian_acl_proof acl;
    struct wls_guardian_acl_proof parent_acl;
};

struct wls_guardian_recovery_manifest_record {
    char *relative;
    wchar_t *relative_wide;
    char kind;
    unsigned long long size;
    char sha256[65];
    struct wls_guardian_acl_proof acl;
};

struct wls_guardian_recovery_manifest_closure {
    char kind;
    unsigned long long entry_count;
    unsigned long long total_bytes;
    char records_sha256[65];
    char native_sha256[65];
    struct wls_guardian_recovery_manifest_record *records;
    size_t count;
};

struct wls_guardian_recovery_inventory_entry {
    unsigned int category_index;
    char leaf[256];
    wchar_t leaf_wide[256];
    char kind;
    char closure_sha256[65];
    int manifest_seen;
    struct wls_guardian_recovery_manifest_closure closure;
};

struct wls_guardian_recovery_evidence {
    wchar_t backup[WLS_PATH_CHARS];
    struct wls_guardian_recovery_root_authority
        roots[WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT];
    struct wls_guardian_recovery_inventory_entry *entries;
    size_t count;
    unsigned long long manifest_entry_count;
    unsigned long long manifest_total_bytes;
    unsigned long long manifest_acl_bytes;
};

struct wls_guardian_recovery_byte_buffer {
    char *bytes;
    size_t used;
    size_t capacity;
    size_t maximum;
};

struct wls_guardian_recovery_platform_write_intent {
    char host_id[33];
    char nonce[33];
    char kind[11];
    char target_volume[17];
    char target_file_id[33];
    char source_sha256[65];
    unsigned long long source_size;
    char current_sha256[65];
    unsigned long long current_size;
    char signature[65];
    char raw_sha256[65];
};

struct wls_guardian_recovery_walk_item {
    char *relative;
    wchar_t *relative_wide;
    char kind;
};

struct wls_guardian_recovery_walk {
    struct wls_guardian_recovery_walk_item *items;
    size_t count;
    size_t capacity;
    size_t path_bytes;
    unsigned long long volume;
};

static void wls_guardian_recovery_manifest_closure_free(
    struct wls_guardian_recovery_manifest_closure *closure
) {
    size_t index;
    if (closure == NULL) return;
    for (index = 0U; index < closure->count; ++index) {
        struct wls_guardian_recovery_manifest_record *record =
            &closure->records[index];
        if (record->relative != NULL) {
            SecureZeroMemory(record->relative, strlen(record->relative) + 1U);
            HeapFree(GetProcessHeap(), 0U, record->relative);
        }
        if (record->relative_wide != NULL) {
            SecureZeroMemory(
                record->relative_wide,
                (wcslen(record->relative_wide) + 1U) * sizeof(wchar_t)
            );
            HeapFree(GetProcessHeap(), 0U, record->relative_wide);
        }
        wls_guardian_acl_proof_free(&record->acl);
    }
    if (closure->records != NULL) {
        SecureZeroMemory(
            closure->records,
            closure->count * sizeof(*closure->records)
        );
        HeapFree(GetProcessHeap(), 0U, closure->records);
    }
    SecureZeroMemory(closure, sizeof(*closure));
}

static void wls_guardian_recovery_evidence_free(
    struct wls_guardian_recovery_evidence *evidence
) {
    size_t index;
    if (evidence == NULL) return;
    if (evidence->entries != NULL) {
        for (index = 0U; index < evidence->count; ++index) {
            wls_guardian_recovery_manifest_closure_free(
                &evidence->entries[index].closure
            );
        }
        SecureZeroMemory(
            evidence->entries,
            evidence->count * sizeof(*evidence->entries)
        );
        HeapFree(GetProcessHeap(), 0U, evidence->entries);
    }
    for (index = 0U;
        index < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT;
        ++index) {
        wls_guardian_acl_proof_free(&evidence->roots[index].acl);
        wls_guardian_acl_proof_free(&evidence->roots[index].parent_acl);
    }
    SecureZeroMemory(evidence, sizeof(*evidence));
}

static void wls_guardian_recovery_walk_free(
    struct wls_guardian_recovery_walk *walk
) {
    size_t index;
    if (walk == NULL) return;
    for (index = 0U; index < walk->count; ++index) {
        if (walk->items[index].relative != NULL) {
            SecureZeroMemory(
                walk->items[index].relative,
                strlen(walk->items[index].relative) + 1U
            );
            HeapFree(GetProcessHeap(), 0U, walk->items[index].relative);
        }
        if (walk->items[index].relative_wide != NULL) {
            SecureZeroMemory(
                walk->items[index].relative_wide,
                (wcslen(walk->items[index].relative_wide) + 1U)
                    * sizeof(wchar_t)
            );
            HeapFree(GetProcessHeap(), 0U, walk->items[index].relative_wide);
        }
    }
    if (walk->items != NULL) {
        SecureZeroMemory(
            walk->items, walk->capacity * sizeof(*walk->items)
        );
        HeapFree(GetProcessHeap(), 0U, walk->items);
    }
    SecureZeroMemory(walk, sizeof(*walk));
}

static int wls_guardian_recovery_buffer_reserve(
    struct wls_guardian_recovery_byte_buffer *buffer,
    size_t amount
) {
    size_t required;
    size_t next;
    char *grown;
    if (buffer == NULL || amount > buffer->maximum
        || buffer->used > buffer->maximum - amount) return 1;
    required = buffer->used + amount;
    if (required <= buffer->capacity) return 0;
    next = buffer->capacity == 0U ? 4096U : buffer->capacity;
    while (next < required) {
        size_t doubled = next > buffer->maximum / 2U
            ? buffer->maximum : next * 2U;
        if (doubled <= next) return 1;
        next = doubled;
    }
    grown = buffer->bytes == NULL
        ? (char *)HeapAlloc(GetProcessHeap(), HEAP_ZERO_MEMORY, next + 1U)
        : (char *)HeapReAlloc(
            GetProcessHeap(), HEAP_ZERO_MEMORY, buffer->bytes, next + 1U
        );
    if (grown == NULL) return 1;
    buffer->bytes = grown;
    buffer->capacity = next;
    return 0;
}

static int wls_guardian_recovery_buffer_append(
    struct wls_guardian_recovery_byte_buffer *buffer,
    const void *bytes,
    size_t length
) {
    if (buffer == NULL || (bytes == NULL && length != 0U)
        || wls_guardian_recovery_buffer_reserve(buffer, length) != 0) return 1;
    if (length != 0U) memcpy(buffer->bytes + buffer->used, bytes, length);
    buffer->used += length;
    buffer->bytes[buffer->used] = '\0';
    return 0;
}

static void wls_guardian_recovery_buffer_free(
    struct wls_guardian_recovery_byte_buffer *buffer
) {
    if (buffer == NULL) return;
    if (buffer->bytes != NULL) {
        SecureZeroMemory(buffer->bytes, buffer->capacity + 1U);
        HeapFree(GetProcessHeap(), 0U, buffer->bytes);
    }
    SecureZeroMemory(buffer, sizeof(*buffer));
}

static int wls_guardian_recovery_json_string(
    struct wls_guardian_recovery_byte_buffer *buffer,
    const char *value,
    size_t length
) {
    static const char hexadecimal[] = "0123456789abcdef";
    size_t index;
    if (buffer == NULL || value == NULL
        || wls_guardian_recovery_buffer_append(buffer, "\"", 1U) != 0) {
        return 1;
    }
    for (index = 0U; index < length; ++index) {
        unsigned char byte = (unsigned char)value[index];
        const char *escaped = NULL;
        size_t escaped_length = 0U;
        char unicode[6];
        if (byte == '"') { escaped = "\\\""; escaped_length = 2U; }
        else if (byte == '\\') { escaped = "\\\\"; escaped_length = 2U; }
        else if (byte == '\b') { escaped = "\\b"; escaped_length = 2U; }
        else if (byte == '\f') { escaped = "\\f"; escaped_length = 2U; }
        else if (byte == '\n') { escaped = "\\n"; escaped_length = 2U; }
        else if (byte == '\r') { escaped = "\\r"; escaped_length = 2U; }
        else if (byte == '\t') { escaped = "\\t"; escaped_length = 2U; }
        else if (byte < 0x20U) {
            unicode[0] = '\\'; unicode[1] = 'u';
            unicode[2] = '0'; unicode[3] = '0';
            unicode[4] = hexadecimal[byte >> 4U];
            unicode[5] = hexadecimal[byte & 0x0fU];
            escaped = unicode;
            escaped_length = sizeof(unicode);
        } else if (byte == 0xe2U && index + 2U < length
            && (unsigned char)value[index + 1U] == 0x80U
            && ((unsigned char)value[index + 2U] == 0xa8U
                || (unsigned char)value[index + 2U] == 0xa9U)) {
            escaped = (unsigned char)value[index + 2U] == 0xa8U
                ? "\\u2028" : "\\u2029";
            escaped_length = 6U;
            index += 2U;
        }
        if (escaped != NULL) {
            if (wls_guardian_recovery_buffer_append(
                    buffer, escaped, escaped_length
                ) != 0) return 1;
        } else if (wls_guardian_recovery_buffer_append(
                buffer, value + index, 1U
            ) != 0) return 1;
    }
    return wls_guardian_recovery_buffer_append(buffer, "\"", 1U);
}

static int wls_guardian_recovery_json_member_compare(
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

static int wls_guardian_recovery_json_canonical_node(
    struct wls_guardian_recovery_byte_buffer *buffer,
    const struct wls_launcher_json_node *node,
    unsigned int depth
) {
    size_t index;
    if (buffer == NULL || node == NULL
        || depth > WLS_GUARDIAN_RECOVERY_MAX_DEPTH) return 1;
    if (node->kind == WLS_LAUNCHER_JSON_NULL
        || node->kind == WLS_LAUNCHER_JSON_BOOLEAN) {
        return wls_guardian_recovery_buffer_append(
            buffer, node->raw, node->raw_length
        );
    }
    if (node->kind == WLS_LAUNCHER_JSON_NUMBER) {
        /* Schema 4 has only non-negative PHP integers. */
        if (node->raw_length == 0U
            || (node->raw_length > 1U && node->raw[0] == '0')) return 1;
        for (index = 0U; index < node->raw_length; ++index) {
            if (node->raw[index] < '0' || node->raw[index] > '9') return 1;
        }
        return wls_guardian_recovery_buffer_append(
            buffer, node->raw, node->raw_length
        );
    }
    if (node->kind == WLS_LAUNCHER_JSON_STRING) {
        return wls_guardian_recovery_json_string(
            buffer, node->decoded, node->decoded_length
        );
    }
    if (node->kind == WLS_LAUNCHER_JSON_ARRAY) {
        if (wls_guardian_recovery_buffer_append(buffer, "[", 1U) != 0) {
            return 1;
        }
        for (index = 0U; index < node->count; ++index) {
            if ((index != 0U && wls_guardian_recovery_buffer_append(
                    buffer, ",", 1U
                ) != 0)
                || wls_guardian_recovery_json_canonical_node(
                    buffer, node->items[index], depth + 1U
                ) != 0) return 1;
        }
        return wls_guardian_recovery_buffer_append(buffer, "]", 1U);
    }
    if (node->kind == WLS_LAUNCHER_JSON_OBJECT) {
        struct wls_launcher_json_member **members = NULL;
        int result = 1;
        if (node->count > 0U) {
            members = (struct wls_launcher_json_member **)HeapAlloc(
                GetProcessHeap(), HEAP_ZERO_MEMORY,
                node->count * sizeof(*members)
            );
            if (members == NULL) return 1;
            for (index = 0U; index < node->count; ++index) {
                members[index] = &node->members[index];
            }
            qsort(
                members,
                node->count,
                sizeof(*members),
                wls_guardian_recovery_json_member_compare
            );
        }
        if (wls_guardian_recovery_buffer_append(buffer, "{", 1U) != 0) {
            goto cleanup;
        }
        for (index = 0U; index < node->count; ++index) {
            if (index != 0U
                && wls_guardian_recovery_json_member_compare(
                    &members[index - 1U], &members[index]
                ) == 0) {
                goto cleanup;
            }
            if ((index != 0U && wls_guardian_recovery_buffer_append(
                    buffer, ",", 1U
                ) != 0)
                || wls_guardian_recovery_json_string(
                    buffer,
                    members[index]->key,
                    members[index]->key_length
                ) != 0
                || wls_guardian_recovery_buffer_append(buffer, ":", 1U) != 0
                || wls_guardian_recovery_json_canonical_node(
                    buffer, members[index]->value, depth + 1U
                ) != 0) goto cleanup;
        }
        if (wls_guardian_recovery_buffer_append(buffer, "}", 1U) != 0) {
            goto cleanup;
        }
        result = 0;
cleanup:
        if (members != NULL) {
            SecureZeroMemory(members, node->count * sizeof(*members));
            HeapFree(GetProcessHeap(), 0U, members);
        }
        return result;
    }
    return 1;
}

static int wls_guardian_recovery_json_is_canonical(
    const char *contents,
    size_t length,
    struct wls_launcher_json_node **root_out
) {
    struct wls_launcher_json_node *root = NULL;
    struct wls_guardian_recovery_byte_buffer canonical;
    int result = 1;
    SecureZeroMemory(&canonical, sizeof(canonical));
    if (contents == NULL || root_out == NULL || length < 3U
        || length > WLS_GUARDIAN_MANIFEST_MAX_BYTES
        || contents[length - 1U] != '\n'
        || contents[length - 2U] == '\n'
        || memchr(contents, '\0', length) != NULL) return 1;
    *root_out = NULL;
    root = wls_launcher_json_parse(
        (const unsigned char *)contents, length - 1U
    );
    canonical.maximum = WLS_GUARDIAN_MANIFEST_MAX_BYTES;
    if (root == NULL
        || wls_guardian_recovery_json_canonical_node(
            &canonical, root, 0U
        ) != 0
        || wls_guardian_recovery_buffer_append(&canonical, "\n", 1U) != 0
        || canonical.used != length
        || sodium_memcmp(canonical.bytes, contents, length) != 0) goto cleanup;
    *root_out = root;
    root = NULL;
    result = 0;
cleanup:
    wls_launcher_json_free(root);
    wls_guardian_recovery_buffer_free(&canonical);
    return result;
}

static int wls_guardian_recovery_wide_to_utf8(
    const wchar_t *input,
    char *output,
    size_t capacity
) {
    int needed;
    if (input == NULL || output == NULL || capacity == 0U
        || capacity > INT_MAX) return 1;
    needed = WideCharToMultiByte(
        CP_UTF8,
        WC_ERR_INVALID_CHARS,
        input,
        -1,
        output,
        (int)capacity,
        NULL,
        NULL
    );
    return needed > 0 && (size_t)needed <= capacity ? 0 : 1;
}

static int wls_guardian_recovery_wide_equal_folded(
    const wchar_t *left,
    const wchar_t *right
) {
    return left != NULL && right != NULL
        && CompareStringOrdinal(left, -1, right, -1, TRUE) == CSTR_EQUAL;
}

static int wls_guardian_recovery_reserved_leaf(const wchar_t *leaf)
{
    static const wchar_t *reserved[] = {
        L"CON", L"PRN", L"AUX", L"NUL",
        L"COM1", L"COM2", L"COM3", L"COM4", L"COM5",
        L"COM6", L"COM7", L"COM8", L"COM9",
        L"LPT1", L"LPT2", L"LPT3", L"LPT4", L"LPT5",
        L"LPT6", L"LPT7", L"LPT8", L"LPT9"
    };
    wchar_t base[16];
    size_t length = 0U;
    size_t index;
    ZeroMemory(base, sizeof(base));
    if (leaf == NULL) return 1;
    while (leaf[length] != L'\0' && leaf[length] != L'.'
        && length + 1U < sizeof(base) / sizeof(base[0])) {
        base[length] = (wchar_t)towupper(leaf[length]);
        ++length;
    }
    base[length] = L'\0';
    for (index = 0U; index < sizeof(reserved) / sizeof(reserved[0]); ++index) {
        if (wcscmp(base, reserved[index]) == 0) return 1;
    }
    return 0;
}

static int wls_guardian_recovery_leaf_wide_valid(const wchar_t *leaf)
{
    size_t length;
    size_t index;
    if (leaf == NULL || (length = wcslen(leaf)) == 0U || length > 255U
        || wcscmp(leaf, L".") == 0 || wcscmp(leaf, L"..") == 0
        || leaf[length - 1U] == L'.' || leaf[length - 1U] == L' '
        || wls_guardian_recovery_reserved_leaf(leaf)) return 0;
    for (index = 0U; index < length; ++index) {
        wchar_t value = leaf[index];
        if (value < 0x20 || wcschr(L"<>:\"/\\|?*", value) != NULL) {
            return 0;
        }
    }
    return 1;
}

static int wls_guardian_recovery_leaf_decode(
    const unsigned char *bytes,
    size_t length,
    char leaf[256],
    wchar_t leaf_wide[256]
) {
    int wide_length;
    if (bytes == NULL || leaf == NULL || leaf_wide == NULL
        || length == 0U || length > 255U
        || memchr(bytes, '\0', length) != NULL
        || memchr(bytes, '/', length) != NULL
        || memchr(bytes, '\\', length) != NULL) return 1;
    memcpy(leaf, bytes, length);
    leaf[length] = '\0';
    wide_length = MultiByteToWideChar(
        CP_UTF8,
        MB_ERR_INVALID_CHARS,
        leaf,
        (int)length,
        leaf_wide,
        255
    );
    if ((length == 1U && leaf[0] == '.')
        || (length == 2U && leaf[0] == '.' && leaf[1] == '.')
        || wide_length <= 0 || wide_length > 255) return 1;
    leaf_wide[wide_length] = L'\0';
    return wls_guardian_recovery_leaf_wide_valid(leaf_wide) ? 0 : 1;
}

static int wls_guardian_recovery_relative_decode(
    const char *relative,
    wchar_t **wide_out,
    unsigned int *depth_out
) {
    wchar_t *wide = NULL;
    wchar_t *cursor;
    wchar_t *segment;
    size_t length;
    int wide_length;
    unsigned int depth = 0U;
    int result = 1;
    if (relative == NULL || wide_out == NULL || depth_out == NULL) return 1;
    *wide_out = NULL;
    *depth_out = 0U;
    length = strlen(relative);
    if (strcmp(relative, ".") == 0) {
        wide = (wchar_t *)HeapAlloc(
            GetProcessHeap(), HEAP_ZERO_MEMORY, 2U * sizeof(wchar_t)
        );
        if (wide == NULL) return 1;
        wide[0] = L'.';
        wide[1] = L'\0';
        *wide_out = wide;
        return 0;
    }
    if (length == 0U || length > WLS_PATH_CHARS - 1U
        || relative[0] == '/' || strchr(relative, '\\') != NULL
        || memchr(relative, '\0', length) != NULL) return 1;
    wide = (wchar_t *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY,
        (length + 1U) * sizeof(wchar_t)
    );
    if (wide == NULL) return 1;
    wide_length = MultiByteToWideChar(
        CP_UTF8,
        MB_ERR_INVALID_CHARS,
        relative,
        (int)length,
        wide,
        (int)length
    );
    if (wide_length <= 0 || (size_t)wide_length > length) goto cleanup;
    wide[wide_length] = L'\0';
    segment = wide;
    for (cursor = wide;; ++cursor) {
        if (*cursor != L'/' && *cursor != L'\0') continue;
        {
            wchar_t saved = *cursor;
            *cursor = L'\0';
            if (!wls_guardian_recovery_leaf_wide_valid(segment)
                || ++depth > WLS_GUARDIAN_RECOVERY_MAX_DEPTH) {
                *cursor = saved;
                goto cleanup;
            }
            *cursor = saved == L'/' ? L'\\' : L'\0';
            if (saved == L'\0') break;
            segment = cursor + 1U;
        }
    }
    *wide_out = wide;
    *depth_out = depth;
    wide = NULL;
    result = 0;
cleanup:
    if (wide != NULL) {
        SecureZeroMemory(wide, (length + 1U) * sizeof(wchar_t));
        HeapFree(GetProcessHeap(), 0U, wide);
    }
    return result;
}

static int wls_guardian_recovery_object_exact_members(
    const struct wls_launcher_json_node *object,
    const char *const *names,
    size_t count
) {
    size_t index;
    if (object == NULL || names == NULL
        || object->kind != WLS_LAUNCHER_JSON_OBJECT
        || object->count != count) return 0;
    for (index = 0U; index < count; ++index) {
        if (wls_launcher_json_member(object, names[index]) == NULL) return 0;
    }
    return 1;
}

static int wls_guardian_recovery_json_string_copy(
    const struct wls_launcher_json_node *node,
    char *output,
    size_t capacity
) {
    if (node == NULL || output == NULL || capacity == 0U
        || node->kind != WLS_LAUNCHER_JSON_STRING
        || node->decoded_length + 1U > capacity
        || memchr(node->decoded, '\0', node->decoded_length) != NULL) return 1;
    memcpy(output, node->decoded, node->decoded_length);
    output[node->decoded_length] = '\0';
    return 0;
}

static int wls_guardian_recovery_json_boolean(
    const struct wls_launcher_json_node *node,
    int *value
) {
    if (node == NULL || value == NULL
        || node->kind != WLS_LAUNCHER_JSON_BOOLEAN) return 1;
    if (node->raw_length == 4U && memcmp(node->raw, "true", 4U) == 0) {
        *value = 1;
        return 0;
    }
    if (node->raw_length == 5U && memcmp(node->raw, "false", 5U) == 0) {
        *value = 0;
        return 0;
    }
    return 1;
}

static int wls_guardian_recovery_acl_proof_from_base64(
    const char *sddl_base64,
    const char *sha256,
    struct wls_guardian_acl_proof *proof
) {
    if (sddl_base64 == NULL || sha256 == NULL || proof == NULL) return 1;
    SecureZeroMemory(proof, sizeof(*proof));
    if (wls_guardian_acl_proof_read(
            "S-1-5-32-544", sddl_base64, sha256, proof
        ) == 0) return 0;
    return wls_guardian_acl_proof_read(
        "S-1-5-18", sddl_base64, sha256, proof
    );
}

static int wls_guardian_recovery_identity_text(
    const struct wls_guardian_pinned_file *pin,
    char device[9],
    char inode[17]
) {
    int device_length;
    int inode_length;
    if (pin == NULL || device == NULL || inode == NULL
        || pin->handle == NULL || pin->handle == INVALID_HANDLE_VALUE) return 1;
    device_length = _snprintf_s(
        device,
        9U,
        _TRUNCATE,
        "%08lx",
        (unsigned long)pin->identity.dwVolumeSerialNumber
    );
    inode_length = _snprintf_s(
        inode,
        17U,
        _TRUNCATE,
        "%08lx%08lx",
        (unsigned long)pin->identity.nFileIndexHigh,
        (unsigned long)pin->identity.nFileIndexLow
    );
    return device_length == 8 && inode_length == 16
        && wls_is_hex(device, 8U) && wls_is_hex(inode, 16U) ? 0 : 1;
}

static int wls_guardian_recovery_identity_matches(
    const struct wls_guardian_pinned_file *pin,
    const char *device,
    const char *inode,
    int directory
) {
    char observed_device[9];
    char observed_inode[17];
    int result;
    SecureZeroMemory(observed_device, sizeof(observed_device));
    SecureZeroMemory(observed_inode, sizeof(observed_inode));
    result = pin != NULL && device != NULL && inode != NULL
        && wls_guardian_pinned_identity_unchanged(pin, directory)
        && wls_guardian_recovery_identity_text(
            pin, observed_device, observed_inode
        ) == 0
        && sodium_memcmp(observed_device, device, 8U) == 0
        && sodium_memcmp(observed_inode, inode, 16U) == 0 ? 0 : 1;
    SecureZeroMemory(observed_device, sizeof(observed_device));
    SecureZeroMemory(observed_inode, sizeof(observed_inode));
    return result;
}

static int wls_guardian_recovery_path_absent(const wchar_t *path)
{
    DWORD attributes;
    DWORD error;
    if (path == NULL) return 0;
    SetLastError(ERROR_SUCCESS);
    attributes = GetFileAttributesW(path);
    if (attributes != INVALID_FILE_ATTRIBUTES) return 0;
    error = GetLastError();
    return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND;
}

static int wls_guardian_recovery_path_kind(
    const wchar_t *path,
    int directory,
    struct wls_guardian_pinned_file *pin
) {
    if (path == NULL || pin == NULL) return 1;
    if (wls_guardian_open_pinned(
            path,
            directory,
            GENERIC_READ | FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            pin
        ) == 0) return 0;
    return wls_guardian_recovery_path_absent(path) ? 2 : 1;
}

static int wls_guardian_recovery_root_only_read(
    const wchar_t *path,
    size_t maximum,
    const char *expected_sha256,
    char **contents,
    size_t *length,
    struct wls_guardian_pinned_file *pin
) {
    char digest[65];
    int result = 1;
    sodium_memzero(digest, sizeof(digest));
    if (path == NULL || expected_sha256 == NULL || contents == NULL
        || length == NULL || pin == NULL || !wls_is_hex(expected_sha256, 64U)
        || wls_guardian_read_pinned(
            path, maximum, 0, contents, length, pin
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)*contents, *length, digest
        ) != 0
        || !wls_guardian_constant_equal(
            digest, expected_sha256, 64U
        )) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(digest, sizeof(digest));
    return result;
}

static int wls_guardian_recovery_atomic_companions_absent(
    const wchar_t *path
) {
    wchar_t parent[WLS_PATH_CHARS];
    wchar_t leaf[256];
    wchar_t temporary_prefix[512];
    wchar_t backup_prefix[512];
    struct wls_guardian_pinned_file parent_pin;
    unsigned char buffer[WLS_GUARDIAN_RECOVERY_DIRECTORY_BUFFER];
    int restart = 1;
    int result = 1;
    ZeroMemory(parent, sizeof(parent));
    ZeroMemory(leaf, sizeof(leaf));
    ZeroMemory(temporary_prefix, sizeof(temporary_prefix));
    ZeroMemory(backup_prefix, sizeof(backup_prefix));
    ZeroMemory(&parent_pin, sizeof(parent_pin));
    ZeroMemory(buffer, sizeof(buffer));
    parent_pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL
        || wls_guardian_protocol_path_parts(path, parent, leaf) != 0
        || _snwprintf_s(
            temporary_prefix,
            sizeof(temporary_prefix) / sizeof(temporary_prefix[0]),
            _TRUNCATE,
            L"%ls.tmp-",
            leaf
        ) < 0
        || _snwprintf_s(
            backup_prefix,
            sizeof(backup_prefix) / sizeof(backup_prefix[0]),
            _TRUNCATE,
            L"%ls.wls-backup-",
            leaf
        ) < 0
        || wls_guardian_open_pinned(
            parent,
            1,
            FILE_LIST_DIRECTORY | FILE_TRAVERSE,
            &parent_pin
        ) != 0
        || wls_recovery_acl_safe(parent_pin.handle, 0) != 0) goto cleanup;
    for (;;) {
        FILE_ID_BOTH_DIR_INFO *entry;
        size_t offset = 0U;
        ZeroMemory(buffer, sizeof(buffer));
        if (!GetFileInformationByHandleEx(
                parent_pin.handle,
                restart ? FileIdBothDirectoryRestartInfo
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
            wchar_t observed[512];
            ZeroMemory(observed, sizeof(observed));
            if (offset > sizeof(buffer) - minimum
                || entry->FileNameLength == 0U
                || entry->FileNameLength % sizeof(wchar_t) != 0U
                || (size_t)entry->FileNameLength
                    > sizeof(buffer) - offset - minimum) goto cleanup;
            characters = entry->FileNameLength / sizeof(wchar_t);
            if (characters >= sizeof(observed) / sizeof(observed[0])) {
                goto cleanup;
            }
            memcpy(observed, entry->FileName, entry->FileNameLength);
            observed[characters] = L'\0';
            if (wls_guardian_protocol_prefix_folded(
                    observed, characters, temporary_prefix
                )
                || wls_guardian_protocol_prefix_folded(
                    observed, characters, backup_prefix
                )) goto cleanup;
            if (entry->NextEntryOffset == 0U) break;
            if (entry->NextEntryOffset < minimum
                || entry->NextEntryOffset > sizeof(buffer) - offset) {
                goto cleanup;
            }
            offset += entry->NextEntryOffset;
            entry = (FILE_ID_BOTH_DIR_INFO *)(void *)(buffer + offset);
        }
    }
    if (!wls_guardian_protocol_directory_identity_unchanged(&parent_pin)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&parent_pin);
    SecureZeroMemory(parent, sizeof(parent));
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(temporary_prefix, sizeof(temporary_prefix));
    SecureZeroMemory(backup_prefix, sizeof(backup_prefix));
    SecureZeroMemory(buffer, sizeof(buffer));
    return result;
}

static int wls_guardian_recovery_backup_root(
    const wchar_t *home,
    const char *nonce,
    wchar_t backup[WLS_PATH_CHARS]
) {
    wchar_t rebootstrap[WLS_PATH_CHARS];
    wchar_t backups[WLS_PATH_CHARS];
    wchar_t nonce_wide[33];
    struct wls_guardian_pinned_file home_pin;
    struct wls_guardian_pinned_file rebootstrap_pin;
    struct wls_guardian_pinned_file backups_pin;
    struct wls_guardian_pinned_file backup_pin;
    unsigned long long volume;
    int result = 1;
    ZeroMemory(rebootstrap, sizeof(rebootstrap));
    ZeroMemory(backups, sizeof(backups));
    ZeroMemory(nonce_wide, sizeof(nonce_wide));
    ZeroMemory(&home_pin, sizeof(home_pin));
    ZeroMemory(&rebootstrap_pin, sizeof(rebootstrap_pin));
    ZeroMemory(&backups_pin, sizeof(backups_pin));
    ZeroMemory(&backup_pin, sizeof(backup_pin));
    home_pin.handle = INVALID_HANDLE_VALUE;
    rebootstrap_pin.handle = INVALID_HANDLE_VALUE;
    backups_pin.handle = INVALID_HANDLE_VALUE;
    backup_pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || nonce == NULL || backup == NULL
        || !wls_is_hex(nonce, 32U)
        || wls_utf8_to_wide(nonce, nonce_wide, 33U) != 0
        || wls_join(
            rebootstrap, WLS_PATH_CHARS, home, L"rebootstrap"
        ) != 0
        || wls_join(
            backups, WLS_PATH_CHARS, rebootstrap, L"backups"
        ) != 0
        || wls_join(backup, WLS_PATH_CHARS, backups, nonce_wide) != 0
        || wls_guardian_open_pinned(
            home, 1, FILE_TRAVERSE | FILE_LIST_DIRECTORY, &home_pin
        ) != 0
        || wls_guardian_open_pinned(
            rebootstrap,
            1,
            FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &rebootstrap_pin
        ) != 0
        || wls_guardian_open_pinned(
            backups,
            1,
            FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &backups_pin
        ) != 0
        || wls_guardian_open_pinned(
            backup,
            1,
            FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &backup_pin
        ) != 0
        || wls_recovery_acl_safe(rebootstrap_pin.handle, 0) != 0
        || wls_recovery_acl_safe(backups_pin.handle, 0) != 0
        || wls_recovery_acl_safe(backup_pin.handle, 0) != 0) goto cleanup;
    volume = home_pin.file_id.VolumeSerialNumber;
    if (volume == 0ULL
        || rebootstrap_pin.file_id.VolumeSerialNumber != volume
        || backups_pin.file_id.VolumeSerialNumber != volume
        || backup_pin.file_id.VolumeSerialNumber != volume
        || !wls_guardian_protocol_directory_identity_unchanged(&home_pin)
        || !wls_guardian_protocol_directory_identity_unchanged(
            &rebootstrap_pin
        )
        || !wls_guardian_protocol_directory_identity_unchanged(&backups_pin)
        || !wls_guardian_protocol_directory_identity_unchanged(&backup_pin)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&backup_pin);
    wls_guardian_pinned_close(&backups_pin);
    wls_guardian_pinned_close(&rebootstrap_pin);
    wls_guardian_pinned_close(&home_pin);
    SecureZeroMemory(rebootstrap, sizeof(rebootstrap));
    SecureZeroMemory(backups, sizeof(backups));
    SecureZeroMemory(nonce_wide, sizeof(nonce_wide));
    if (result != 0 && backup != NULL) {
        SecureZeroMemory(backup, WLS_PATH_CHARS * sizeof(wchar_t));
    }
    return result;
}

static int wls_guardian_recovery_authorization_verify(
    const wchar_t *home,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request
) {
    wchar_t path[WLS_PATH_CHARS];
    char unsigned_authorization[3072];
    char expected[3328];
    char signature[65];
    char *contents = NULL;
    size_t length = 0U;
    struct wls_guardian_pinned_file pin;
    int unsigned_length;
    int expected_length;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    SecureZeroMemory(unsigned_authorization, sizeof(unsigned_authorization));
    SecureZeroMemory(expected, sizeof(expected));
    SecureZeroMemory(signature, sizeof(signature));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || backup == NULL || request == NULL
        || wls_join(
            path,
            WLS_PATH_CHARS,
            backup,
            L"guardian-recovery.authorization"
        ) != 0
        || wls_guardian_recovery_atomic_companions_absent(path) != 0
        || wls_guardian_recovery_root_only_read(
            path,
            WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
            request->recovery_authorization_sha256,
            &contents,
            &length,
            &pin
        ) != 0) goto cleanup;
    unsigned_length = _snprintf_s(
        unsigned_authorization,
        sizeof(unsigned_authorization),
        _TRUNCATE,
        "WLS-GUARDIAN-RECOVERY-AUTHORIZATION/1\n"
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
        "recovery_inventory_sha256=%s\n"
        "request_binding_sha256=%s\n",
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
        request->recovery_inventory_sha256,
        request->request_binding_sha256
    );
    if (unsigned_length <= 0
        || unsigned_length >= (int)sizeof(unsigned_authorization)
        || wls_guardian_hmac_hex(
            home,
            unsigned_authorization,
            (size_t)unsigned_length,
            signature
        ) != 0) goto cleanup;
    expected_length = _snprintf_s(
        expected,
        sizeof(expected),
        _TRUNCATE,
        "%ssignature=%s\n",
        unsigned_authorization,
        signature
    );
    if (expected_length <= 0 || expected_length >= (int)sizeof(expected)
        || length != (size_t)expected_length
        || sodium_memcmp(contents, expected, length) != 0
        || !wls_guardian_pinned_identity_unchanged(&pin, 0)) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    wls_guardian_pinned_close(&pin);
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(unsigned_authorization, sizeof(unsigned_authorization));
    SecureZeroMemory(expected, sizeof(expected));
    SecureZeroMemory(signature, sizeof(signature));
    return result;
}

static int wls_guardian_recovery_line(
    const char *contents,
    size_t limit,
    size_t *offset,
    const char **line,
    size_t *line_length
) {
    const char *newline;
    if (contents == NULL || offset == NULL || line == NULL
        || line_length == NULL || *offset >= limit) return 1;
    newline = (const char *)memchr(
        contents + *offset, '\n', limit - *offset
    );
    if (newline == NULL || newline == contents + *offset) return 1;
    *line = contents + *offset;
    *line_length = (size_t)(newline - (contents + *offset));
    *offset = (size_t)(newline - contents) + 1U;
    return 0;
}

static int wls_guardian_recovery_field_split(
    char *line,
    const char *prefix,
    char **fields,
    size_t expected
) {
    size_t count = 0U;
    char *cursor;
    size_t prefix_length;
    if (line == NULL || prefix == NULL || fields == NULL || expected == 0U) {
        return 1;
    }
    prefix_length = strlen(prefix);
    if (strncmp(line, prefix, prefix_length) != 0) return 1;
    cursor = line + prefix_length;
    fields[count++] = cursor;
    for (; *cursor != '\0'; ++cursor) {
        if (*cursor != '\t') continue;
        *cursor = '\0';
        if (count >= expected) return 1;
        fields[count++] = cursor + 1U;
    }
    if (count != expected) return 1;
    for (count = 0U; count < expected; ++count) {
        if (fields[count][0] == '\0') return 1;
    }
    return 0;
}

static int wls_guardian_recovery_absent_authority_digest(
    const char *policy,
    char digest[65]
) {
    char canonical[192];
    int length;
    int result = 1;
    SecureZeroMemory(canonical, sizeof(canonical));
    if (policy == NULL || digest == NULL) return 1;
    length = _snprintf_s(
        canonical, sizeof(canonical), _TRUNCATE, "%s\nabsent\n", policy
    );
    if (length > 0 && length < (int)sizeof(canonical)
        && wls_guardian_sha256(
            (const unsigned char *)canonical, (size_t)length, digest
        ) == 0) result = 0;
    SecureZeroMemory(canonical, sizeof(canonical));
    return result;
}

static int wls_guardian_recovery_root_parse(
    const char *line,
    size_t line_length,
    const struct wls_guardian_recovery_root_contract *contract,
    struct wls_guardian_recovery_root_authority *root
) {
    char *copy = NULL;
    char *fields[21];
    char absent_digest[65];
    size_t maximum = 2U * sodium_base64_ENCODED_LEN(
        WLS_GUARDIAN_MAX_ACL_BYTES, sodium_base64_VARIANT_ORIGINAL
    ) + 2048U;
    int result = 1;
    SecureZeroMemory(fields, sizeof(fields));
    sodium_memzero(absent_digest, sizeof(absent_digest));
    if (line == NULL || contract == NULL || root == NULL
        || line_length == 0U || line_length > maximum) return 1;
    copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, line_length + 1U
    );
    if (copy == NULL) return 1;
    memcpy(copy, line, line_length);
    copy[line_length] = '\0';
    SecureZeroMemory(root, sizeof(*root));
    root->contract = contract;
    if (wls_guardian_recovery_field_split(
            copy, "category=", fields, 21U
        ) != 0
        || strcmp(fields[0], contract->category) != 0
        || strcmp(fields[1], contract->policy) != 0
        || strcmp(fields[2], contract->authority_profile) != 0
        || strcmp(fields[3], contract->authority_policy) != 0
        || (strcmp(fields[4], "0") != 0 && strcmp(fields[4], "1") != 0)
        || strcmp(fields[7], "0") != 0
        || strcmp(fields[8], "0") != 0
        || strcmp(fields[9], "0") != 0
        || !wls_is_hex(fields[10], 64U)
        || strcmp(fields[12], contract->parent_authority_profile) != 0
        || strcmp(fields[13], contract->parent_authority_policy) != 0
        || !wls_is_hex(fields[14], 8U)
        || !wls_is_hex(fields[15], 16U)
        || strcmp(fields[16], "0") != 0
        || strcmp(fields[17], "0") != 0
        || strcmp(fields[18], "0") != 0
        || !wls_is_hex(fields[19], 64U)
        || strcmp(fields[20], "-") == 0
        || strlen(fields[20]) >= sizeof(root->parent_sddl_base64)) {
        goto cleanup;
    }
    root->present = fields[4][0] == '1';
    if (root->present) {
        if (!wls_is_hex(fields[5], 8U)
            || !wls_is_hex(fields[6], 16U)
            || strcmp(fields[11], "-") == 0
            || strlen(fields[11]) >= sizeof(root->sddl_base64)) goto cleanup;
        memcpy(root->device, fields[5], 9U);
        memcpy(root->inode, fields[6], 17U);
        memcpy(
            root->sddl_base64, fields[11], strlen(fields[11]) + 1U
        );
        if (wls_guardian_recovery_acl_proof_from_base64(
                root->sddl_base64, fields[10], &root->acl
            ) != 0) goto cleanup;
    } else {
        if (contract->preserve_identity
            || strcmp(fields[5], "-") != 0
            || strcmp(fields[6], "-") != 0
            || strcmp(fields[11], "-") != 0
            || wls_guardian_recovery_absent_authority_digest(
                contract->authority_policy, absent_digest
            ) != 0
            || !wls_guardian_constant_equal(
                absent_digest, fields[10], 64U
            )) goto cleanup;
    }
    memcpy(root->authority_sha256, fields[10], 65U);
    memcpy(root->parent_device, fields[14], 9U);
    memcpy(root->parent_inode, fields[15], 17U);
    memcpy(root->parent_authority_sha256, fields[19], 65U);
    memcpy(
        root->parent_sddl_base64, fields[20], strlen(fields[20]) + 1U
    );
    if (wls_guardian_recovery_acl_proof_from_base64(
            root->parent_sddl_base64,
            root->parent_authority_sha256,
            &root->parent_acl
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (copy != NULL) {
        SecureZeroMemory(copy, line_length + 1U);
        HeapFree(GetProcessHeap(), 0U, copy);
    }
    sodium_memzero(absent_digest, sizeof(absent_digest));
    if (result != 0 && root != NULL) {
        wls_guardian_acl_proof_free(&root->acl);
        wls_guardian_acl_proof_free(&root->parent_acl);
        SecureZeroMemory(root, sizeof(*root));
    }
    return result;
}

static int wls_guardian_recovery_parent_authority_equal(
    const struct wls_guardian_recovery_root_authority *left,
    const struct wls_guardian_recovery_root_authority *right
) {
    return left != NULL && right != NULL
        && sodium_memcmp(left->parent_device, right->parent_device, 8U) == 0
        && sodium_memcmp(left->parent_inode, right->parent_inode, 16U) == 0
        && sodium_memcmp(
            left->parent_authority_sha256,
            right->parent_authority_sha256,
            64U
        ) == 0
        && strcmp(
            left->parent_sddl_base64, right->parent_sddl_base64
        ) == 0
        && strcmp(
            left->parent_acl.canonical, right->parent_acl.canonical
        ) == 0;
}

static int wls_guardian_recovery_category_index(
    const char *category,
    unsigned int *index_out
) {
    unsigned int index;
    if (category == NULL || index_out == NULL) return 1;
    for (index = 0U;
        index < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT;
        ++index) {
        if (strcmp(
                category,
                wls_guardian_recovery_root_contracts[index].category
            ) == 0) {
            *index_out = index;
            return 0;
        }
    }
    return 1;
}

static int wls_guardian_recovery_inventory_entry_parse(
    const char *line,
    size_t line_length,
    struct wls_guardian_recovery_inventory_entry *entry
) {
    char *copy = NULL;
    char *fields[5];
    unsigned char decoded[256];
    size_t decoded_length = 0U;
    size_t hex_length;
    unsigned int category_index;
    int result = 1;
    SecureZeroMemory(fields, sizeof(fields));
    SecureZeroMemory(decoded, sizeof(decoded));
    if (line == NULL || entry == NULL || line_length == 0U
        || line_length >= 768U) return 1;
    copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, line_length + 1U
    );
    if (copy == NULL) return 1;
    memcpy(copy, line, line_length);
    copy[line_length] = '\0';
    SecureZeroMemory(entry, sizeof(*entry));
    if (wls_guardian_recovery_field_split(
            copy, "entry=", fields, 5U
        ) != 0
        || wls_guardian_recovery_category_index(
            fields[0], &category_index
        ) != 0
        || strcmp(
            fields[1],
            wls_guardian_recovery_root_contracts[category_index].policy
        ) != 0
        || (strcmp(fields[3], "d") != 0
            && strcmp(fields[3], "f") != 0)
        || !wls_is_hex(fields[4], 64U)) goto cleanup;
    hex_length = strlen(fields[2]);
    if (hex_length < 2U || hex_length > 510U || (hex_length & 1U) != 0U
        || sodium_hex2bin(
            decoded,
            sizeof(decoded) - 1U,
            fields[2],
            hex_length,
            NULL,
            &decoded_length,
            NULL
        ) != 0
        || wls_guardian_recovery_leaf_decode(
            decoded,
            decoded_length,
            entry->leaf,
            entry->leaf_wide
        ) != 0) goto cleanup;
    entry->category_index = category_index;
    entry->kind = fields[3][0];
    memcpy(entry->closure_sha256, fields[4], 65U);
    result = 0;
cleanup:
    if (copy != NULL) {
        SecureZeroMemory(copy, line_length + 1U);
        HeapFree(GetProcessHeap(), 0U, copy);
    }
    SecureZeroMemory(decoded, sizeof(decoded));
    if (result != 0 && entry != NULL) {
        SecureZeroMemory(entry, sizeof(*entry));
    }
    return result;
}

static int wls_guardian_recovery_inventory_load(
    const wchar_t *home,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_evidence *evidence
) {
    wchar_t path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    struct wls_guardian_pinned_file pin;
    const char *signature_line;
    const char *line;
    size_t line_length;
    size_t offset = 0U;
    size_t unsigned_length;
    char value[128];
    char previous[768];
    char signature[65];
    unsigned long long category_count = 0ULL;
    unsigned long long entry_count = 0ULL;
    size_t index;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    SecureZeroMemory(value, sizeof(value));
    SecureZeroMemory(previous, sizeof(previous));
    SecureZeroMemory(signature, sizeof(signature));
    if (home == NULL || backup == NULL || request == NULL || evidence == NULL
        || wls_join(
            path,
            WLS_PATH_CHARS,
            backup,
            L"guardian-recovery.inventory"
        ) != 0
        || wls_guardian_recovery_atomic_companions_absent(path) != 0
        || wls_guardian_recovery_root_only_read(
            path,
            WLS_GUARDIAN_INVENTORY_MAX_BYTES,
            request->recovery_inventory_sha256,
            &contents,
            &length,
            &pin
        ) != 0
        || length < 75U) goto cleanup;
    signature_line = contents + length - 75U;
    if (memcmp(signature_line, "signature=", 10U) != 0
        || signature_line[74] != '\n') goto cleanup;
    memcpy(signature, signature_line + 10U, 64U);
    signature[64] = '\0';
    if (!wls_is_hex(signature, 64U)
        || wls_guardian_signed_document_valid(
            home, contents, length, signature
        ) != 0) goto cleanup;
    unsigned_length = (size_t)(signature_line - contents);
#define WLS_GUARDIAN_RECOVERY_HEADER(expected_prefix, expected_value) do { \
        size_t wls_prefix_length = strlen(expected_prefix); \
        if (wls_guardian_recovery_line( \
                contents, unsigned_length, &offset, &line, &line_length \
            ) != 0 \
            || line_length != wls_prefix_length + strlen(expected_value) \
            || memcmp(line, expected_prefix, wls_prefix_length) != 0 \
            || sodium_memcmp( \
                line + wls_prefix_length, expected_value, strlen(expected_value) \
            ) != 0) goto cleanup; \
    } while (0)
    WLS_GUARDIAN_RECOVERY_HEADER(
        "", "WLS-GUARDIAN-RECOVERY-INVENTORY/2"
    );
    WLS_GUARDIAN_RECOVERY_HEADER("host_id=", request->host_id);
    WLS_GUARDIAN_RECOVERY_HEADER("nonce=", request->nonce);
    WLS_GUARDIAN_RECOVERY_HEADER(
        "journal_sha256=", request->journal_sha256
    );
    WLS_GUARDIAN_RECOVERY_HEADER(
        "derived_manifest_sha256=", request->derived_manifest_sha256
    );
    WLS_GUARDIAN_RECOVERY_HEADER(
        "derived_policy_sha256=", request->derived_policy_sha256
    );
#undef WLS_GUARDIAN_RECOVERY_HEADER
    if (wls_guardian_recovery_line(
            contents, unsigned_length, &offset, &line, &line_length
        ) != 0
        || line_length <= strlen("category_count=")
        || line_length - strlen("category_count=") >= sizeof(value)
        || memcmp(
            line, "category_count=", strlen("category_count=")
        ) != 0) goto cleanup;
    memcpy(
        value,
        line + strlen("category_count="),
        line_length - strlen("category_count=")
    );
    value[line_length - strlen("category_count=")] = '\0';
    if (wls_guardian_protocol_decimal(value, &category_count) != 0
        || category_count != WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT) {
        goto cleanup;
    }
    SecureZeroMemory(value, sizeof(value));
    if (wls_guardian_recovery_line(
            contents, unsigned_length, &offset, &line, &line_length
        ) != 0
        || line_length <= strlen("entry_count=")
        || line_length - strlen("entry_count=") >= sizeof(value)
        || memcmp(line, "entry_count=", strlen("entry_count=")) != 0) {
        goto cleanup;
    }
    memcpy(
        value,
        line + strlen("entry_count="),
        line_length - strlen("entry_count=")
    );
    value[line_length - strlen("entry_count=")] = '\0';
    if (wls_guardian_protocol_decimal(value, &entry_count) != 0
        || entry_count > WLS_GUARDIAN_MAX_DERIVED_ENTRIES
        || entry_count > SIZE_MAX / sizeof(*evidence->entries)) goto cleanup;
    for (index = 0U;
        index < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT;
        ++index) {
        if (wls_guardian_recovery_line(
                contents, unsigned_length, &offset, &line, &line_length
            ) != 0
            || wls_guardian_recovery_root_parse(
                line,
                line_length,
                &wls_guardian_recovery_root_contracts[index],
                &evidence->roots[index]
            ) != 0) goto cleanup;
    }
    for (index = 1U; index < 4U; ++index) {
        if (!wls_guardian_recovery_parent_authority_equal(
                &evidence->roots[0], &evidence->roots[index]
            )) goto cleanup;
    }
    for (index = 5U;
        index < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT;
        ++index) {
        if (!wls_guardian_recovery_parent_authority_equal(
                &evidence->roots[4], &evidence->roots[index]
            )) goto cleanup;
    }
    if (entry_count != 0ULL) {
        evidence->entries = (struct wls_guardian_recovery_inventory_entry *)
            HeapAlloc(
                GetProcessHeap(),
                HEAP_ZERO_MEMORY,
                (size_t)entry_count * sizeof(*evidence->entries)
            );
        if (evidence->entries == NULL) goto cleanup;
    }
    evidence->count = (size_t)entry_count;
    for (index = 0U; index < evidence->count; ++index) {
        size_t copy_length;
        size_t prior;
        if (wls_guardian_recovery_line(
                contents, unsigned_length, &offset, &line, &line_length
            ) != 0
            || line_length >= sizeof(previous)
            || (previous[0] != '\0'
                && (prior = strlen(previous),
                    (prior > line_length
                        ? memcmp(previous, line, line_length) >= 0
                        : (memcmp(previous, line, prior) > 0
                            || (memcmp(previous, line, prior) == 0
                                && prior >= line_length)))))
            || wls_guardian_recovery_inventory_entry_parse(
                line, line_length, &evidence->entries[index]
            ) != 0) goto cleanup;
        for (prior = 0U; prior < index; ++prior) {
            if (evidence->entries[prior].category_index
                    == evidence->entries[index].category_index
                && wls_guardian_recovery_wide_equal_folded(
                    evidence->entries[prior].leaf_wide,
                    evidence->entries[index].leaf_wide
                )) goto cleanup;
        }
        copy_length = line_length;
        memcpy(previous, line, copy_length);
        previous[copy_length] = '\0';
    }
    if (offset != unsigned_length
        || !wls_guardian_pinned_identity_unchanged(&pin, 0)) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    wls_guardian_pinned_close(&pin);
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(value, sizeof(value));
    SecureZeroMemory(previous, sizeof(previous));
    SecureZeroMemory(signature, sizeof(signature));
    return result;
}

static const char *const wls_guardian_recovery_state_preserved[] = {
    "recovery.reserve",
    "windows-service.json"
};

#ifdef WLS_NATIVE_TEST_HOOKS
static const char *const wls_guardian_recovery_test_state_preserved[] = {
    "recovery.reserve",
    "service-definition.test"
};
#endif

static const char *const wls_guardian_recovery_trust_preserved[] = {
    "active-slot",
    "admin-stopped.intent",
    "admin.token",
    "guardian-generation-head.0",
    "guardian-generation-head.1",
    "guardian-generation-head.lock",
    "guardian-recovery.transaction",
    "guardian-transition.ack",
    "guardian-transition.request",
    "guardian-transition.retirement",
    "guardian.sha256",
    "host-id",
    "package-install.lock",
    "package-stage-a.lock",
    "package-stage-b.lock",
    "platform-definition.transaction",
    "platform-removal.pending",
    "platform-service.json",
    "previous-slot",
    "rebootstrap-start.authorization",
    "rebootstrap.transaction",
    "stable-launcher.sha256"
};

static int wls_guardian_recovery_preserved_contract(
    unsigned int category_index,
    const struct wls_guardian_transition_request *request,
    const char *const **values,
    size_t *count
) {
    if (request == NULL || values == NULL || count == NULL
        || category_index >= WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT) return 1;
    *values = NULL;
    *count = 0U;
    if (category_index == 7U) {
#ifdef WLS_NATIVE_TEST_HOOKS
        if (strcmp(request->platform_kind, "test-session") == 0) {
            *values = wls_guardian_recovery_test_state_preserved;
            *count = sizeof(wls_guardian_recovery_test_state_preserved)
                / sizeof(wls_guardian_recovery_test_state_preserved[0]);
            return 0;
        }
#endif
        *values = wls_guardian_recovery_state_preserved;
        *count = sizeof(wls_guardian_recovery_state_preserved)
            / sizeof(wls_guardian_recovery_state_preserved[0]);
    } else if (category_index == 8U) {
        *values = wls_guardian_recovery_trust_preserved;
        *count = sizeof(wls_guardian_recovery_trust_preserved)
            / sizeof(wls_guardian_recovery_trust_preserved[0]);
    }
    return 0;
}

static int wls_guardian_recovery_preserved_array_valid(
    const struct wls_launcher_json_node *array,
    unsigned int category_index,
    const struct wls_guardian_transition_request *request
) {
    const char *const *expected = NULL;
    size_t expected_count = 0U;
    size_t index;
    if (array == NULL || array->kind != WLS_LAUNCHER_JSON_ARRAY
        || wls_guardian_recovery_preserved_contract(
            category_index, request, &expected, &expected_count
        ) != 0
        || array->count != expected_count) return 0;
    for (index = 0U; index < expected_count; ++index) {
        if (!wls_launcher_json_string_equals(
                array->items[index], expected[index]
            )) return 0;
    }
    return 1;
}

static int wls_guardian_recovery_policy_digest(
    const struct wls_guardian_transition_request *request,
    char digest[65]
) {
    struct wls_guardian_recovery_byte_buffer buffer;
    unsigned int index;
    int result = 1;
    SecureZeroMemory(&buffer, sizeof(buffer));
    if (request == NULL || digest == NULL) return 1;
    buffer.maximum = 32768U;
    if (wls_guardian_recovery_buffer_append(&buffer, "{", 1U) != 0) {
        goto cleanup;
    }
    for (index = 0U;
        index < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT;
        ++index) {
        const struct wls_guardian_recovery_root_contract *contract =
            &wls_guardian_recovery_root_contracts[index];
        const char *const *preserved = NULL;
        size_t preserved_count = 0U;
        size_t preserved_index;
        if (wls_guardian_recovery_preserved_contract(
                index, request, &preserved, &preserved_count
            ) != 0
            || (index != 0U && wls_guardian_recovery_buffer_append(
                &buffer, ",", 1U
            ) != 0)
            || wls_guardian_recovery_json_string(
                &buffer, contract->category, strlen(contract->category)
            ) != 0
            || wls_guardian_recovery_buffer_append(
                &buffer,
                ":{\"authority_profile\":",
                sizeof(":{\"authority_profile\":") - 1U
            ) != 0
            || wls_guardian_recovery_json_string(
                &buffer,
                contract->authority_profile,
                strlen(contract->authority_profile)
            ) != 0
            || wls_guardian_recovery_buffer_append(
                &buffer, ",\"policy\":", sizeof(",\"policy\":") - 1U
            ) != 0
            || wls_guardian_recovery_json_string(
                &buffer, contract->policy, strlen(contract->policy)
            ) != 0
            || wls_guardian_recovery_buffer_append(
                &buffer,
                ",\"preserved\":[",
                sizeof(",\"preserved\":[") - 1U
            ) != 0) goto cleanup;
        for (preserved_index = 0U;
            preserved_index < preserved_count;
            ++preserved_index) {
            if ((preserved_index != 0U
                    && wls_guardian_recovery_buffer_append(
                        &buffer, ",", 1U
                    ) != 0)
                || wls_guardian_recovery_json_string(
                    &buffer,
                    preserved[preserved_index],
                    strlen(preserved[preserved_index])
                ) != 0) goto cleanup;
        }
        if (wls_guardian_recovery_buffer_append(
                &buffer,
                "],\"root_authority_policy\":",
                sizeof("],\"root_authority_policy\":") - 1U
            ) != 0
            || wls_guardian_recovery_json_string(
                &buffer,
                contract->authority_policy,
                strlen(contract->authority_policy)
            ) != 0
            || wls_guardian_recovery_buffer_append(
                &buffer,
                ",\"root_id\":",
                sizeof(",\"root_id\":") - 1U
            ) != 0
            || wls_guardian_recovery_json_string(
                &buffer, contract->root_id, strlen(contract->root_id)
            ) != 0
            || wls_guardian_recovery_buffer_append(&buffer, "}", 1U) != 0) {
            goto cleanup;
        }
    }
    if (wls_guardian_recovery_buffer_append(&buffer, "}", 1U) != 0
        || wls_guardian_sha256(
            (const unsigned char *)buffer.bytes, buffer.used, digest
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_recovery_buffer_free(&buffer);
    if (result != 0 && digest != NULL) sodium_memzero(digest, 65U);
    return result;
}

static int wls_guardian_recovery_manifest_root_matches(
    const struct wls_launcher_json_node *node,
    const struct wls_guardian_recovery_root_authority *root
) {
    static const char *const keys[] = {
        "authority_policy", "authority_sha256", "device", "gid", "inode",
        "mode", "parent_authority_policy", "parent_authority_sha256",
        "parent_device", "parent_gid", "parent_inode", "parent_mode",
        "parent_uid", "parent_windows_sddl_b64", "present", "uid",
        "windows_sddl_b64"
    };
    const struct wls_launcher_json_node *present_node;
    const struct wls_launcher_json_node *value;
    int present = 0;
    if (node == NULL || root == NULL || root->contract == NULL
        || !wls_guardian_recovery_object_exact_members(
            node, keys, sizeof(keys) / sizeof(keys[0])
        )
        || (present_node = wls_launcher_json_member(
            node, "present"
        )) == NULL
        || wls_guardian_recovery_json_boolean(
            present_node, &present
        ) != 0
        || present != root->present
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "authority_policy"),
            root->contract->authority_policy
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "authority_sha256"),
            root->authority_sha256
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "uid"), "0"
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "gid"), "0"
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "mode"), "0"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "parent_authority_policy"),
            root->contract->parent_authority_policy
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "parent_authority_sha256"),
            root->parent_authority_sha256
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "parent_device"),
            root->parent_device
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "parent_inode"),
            root->parent_inode
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "parent_uid"), "0"
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "parent_gid"), "0"
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "parent_mode"), "0"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "parent_windows_sddl_b64"),
            root->parent_sddl_base64
        )) return 0;
    value = wls_launcher_json_member(node, "device");
    if (!wls_launcher_json_string_equals(
            value, root->present ? root->device : ""
        )) return 0;
    value = wls_launcher_json_member(node, "inode");
    if (!wls_launcher_json_string_equals(
            value, root->present ? root->inode : ""
        )) return 0;
    value = wls_launcher_json_member(node, "windows_sddl_b64");
    return wls_launcher_json_string_equals(
        value, root->present ? root->sddl_base64 : ""
    );
}

static struct wls_guardian_recovery_inventory_entry *
wls_guardian_recovery_inventory_find(
    struct wls_guardian_recovery_evidence *evidence,
    unsigned int category_index,
    const char *leaf
) {
    size_t index;
    if (evidence == NULL || leaf == NULL) return NULL;
    for (index = 0U; index < evidence->count; ++index) {
        if (evidence->entries[index].category_index == category_index
            && strcmp(evidence->entries[index].leaf, leaf) == 0) {
            return &evidence->entries[index];
        }
    }
    return NULL;
}

static int wls_guardian_recovery_record_native_update(
    crypto_hash_sha256_state *state,
    const struct wls_guardian_recovery_manifest_record *record
) {
    char *hex = NULL;
    char *encoded = NULL;
    size_t relative_length;
    size_t capacity;
    int encoded_length;
    int result = 1;
    if (state == NULL || record == NULL || record->relative == NULL
        || (record->kind != 'd' && record->kind != 'f')) return 1;
    relative_length = strlen(record->relative);
    if (relative_length == 0U
        || relative_length > (SIZE_MAX - 1U) / 2U) return 1;
    hex = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, relative_length * 2U + 1U
    );
    capacity = relative_length * 2U + 256U;
    encoded = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, capacity
    );
    if (hex == NULL || encoded == NULL
        || sodium_bin2hex(
            hex,
            relative_length * 2U + 1U,
            (const unsigned char *)record->relative,
            relative_length
        ) == NULL) goto cleanup;
    encoded_length = _snprintf_s(
        encoded,
        capacity,
        _TRUNCATE,
        "record=%s\t%c\t0\t0\t0\t%llu\t%s\n",
        hex,
        record->kind,
        record->kind == 'f' ? record->size : 0ULL,
        record->kind == 'f' ? record->sha256 : WLS_GUARDIAN_ZERO_64
    );
    if (encoded_length <= 0 || (size_t)encoded_length >= capacity
        || crypto_hash_sha256_update(
            state,
            (const unsigned char *)encoded,
            (unsigned long long)encoded_length
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (encoded != NULL) {
        SecureZeroMemory(encoded, capacity);
        HeapFree(GetProcessHeap(), 0U, encoded);
    }
    if (hex != NULL) {
        SecureZeroMemory(hex, relative_length * 2U + 1U);
        HeapFree(GetProcessHeap(), 0U, hex);
    }
    return result;
}

static int wls_guardian_recovery_manifest_record_parse(
    const struct wls_launcher_json_node *node,
    const struct wls_guardian_recovery_root_contract *contract,
    struct wls_guardian_recovery_manifest_record *record,
    size_t *path_bytes,
    unsigned long long *acl_bytes,
    unsigned long long *total_bytes
) {
    static const char *const directory_keys[] = {
        "acl_profile", "acl_sha256", "gid", "kind", "mode",
        "owner_sid", "path", "sddl_b64", "uid"
    };
    static const char *const file_keys[] = {
        "acl_profile", "acl_sha256", "gid", "kind", "mode",
        "owner_sid", "path", "sddl_b64", "sha256", "size", "uid"
    };
    const struct wls_launcher_json_node *kind_node;
    const struct wls_launcher_json_node *path_node;
    const struct wls_launcher_json_node *size_node;
    char owner_sid[SECURITY_MAX_SID_SIZE * 4U];
    char acl_sha256[65];
    char sddl_base64[
        sodium_base64_ENCODED_LEN(
            WLS_GUARDIAN_MAX_ACL_BYTES,
            sodium_base64_VARIANT_ORIGINAL
        )
    ];
    unsigned int depth = 0U;
    unsigned long long size = 0ULL;
    size_t relative_length;
    int file;
    int result = 1;
    SecureZeroMemory(owner_sid, sizeof(owner_sid));
    sodium_memzero(acl_sha256, sizeof(acl_sha256));
    SecureZeroMemory(sddl_base64, sizeof(sddl_base64));
    if (node == NULL || contract == NULL || record == NULL
        || path_bytes == NULL || acl_bytes == NULL || total_bytes == NULL
        || node->kind != WLS_LAUNCHER_JSON_OBJECT
        || (kind_node = wls_launcher_json_member(node, "kind")) == NULL) {
        return 1;
    }
    file = wls_launcher_json_string_equals(kind_node, "file");
    if (!file
        && !wls_launcher_json_string_equals(kind_node, "directory")) {
        return 1;
    }
    if (!(file
            ? wls_guardian_recovery_object_exact_members(
                node,
                file_keys,
                sizeof(file_keys) / sizeof(file_keys[0])
            )
            : wls_guardian_recovery_object_exact_members(
                node,
                directory_keys,
                sizeof(directory_keys) / sizeof(directory_keys[0])
            ))
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "acl_profile"),
            contract->authority_profile
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "mode"), "0"
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "uid"), "0"
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(node, "gid"), "0"
        )
        || wls_guardian_recovery_json_string_copy(
            wls_launcher_json_member(node, "owner_sid"),
            owner_sid,
            sizeof(owner_sid)
        ) != 0
        || wls_guardian_recovery_json_string_copy(
            wls_launcher_json_member(node, "acl_sha256"),
            acl_sha256,
            sizeof(acl_sha256)
        ) != 0
        || !wls_is_hex(acl_sha256, 64U)
        || wls_guardian_recovery_json_string_copy(
            wls_launcher_json_member(node, "sddl_b64"),
            sddl_base64,
            sizeof(sddl_base64)
        ) != 0
        || wls_guardian_acl_proof_read(
            owner_sid, sddl_base64, acl_sha256, &record->acl
        ) != 0
        || (path_node = wls_launcher_json_member(node, "path")) == NULL
        || path_node->kind != WLS_LAUNCHER_JSON_STRING
        || path_node->decoded_length == 0U
        || path_node->decoded_length > WLS_PATH_CHARS - 1U
        || memchr(path_node->decoded, '\0', path_node->decoded_length) != NULL) {
        goto cleanup;
    }
    relative_length = path_node->decoded_length;
    if (*path_bytes > WLS_GUARDIAN_RECOVERY_PATH_BYTES - relative_length
        || *acl_bytes > WLS_GUARDIAN_MAX_TOTAL_ACL_BYTES
            - strlen(record->acl.canonical)) goto cleanup;
    record->relative = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, relative_length + 1U
    );
    if (record->relative == NULL) goto cleanup;
    memcpy(record->relative, path_node->decoded, relative_length);
    record->relative[relative_length] = '\0';
    if (wls_guardian_recovery_relative_decode(
            record->relative, &record->relative_wide, &depth
        ) != 0) goto cleanup;
    record->kind = file ? 'f' : 'd';
    memcpy(record->sha256, WLS_GUARDIAN_ZERO_64, 65U);
    if (file) {
        size_node = wls_launcher_json_member(node, "size");
        if (wls_launcher_json_u64(size_node, &size) != 0
            || size > WLS_GUARDIAN_MAX_DERIVED_BYTES) goto cleanup;
        {
            const struct wls_launcher_json_node *sha_node =
                wls_launcher_json_member(node, "sha256");
            if (sha_node == NULL || sha_node->kind != WLS_LAUNCHER_JSON_STRING
                || sha_node->decoded_length != 64U
                || !wls_is_hex(sha_node->decoded, 64U)
                || *total_bytes > WLS_GUARDIAN_MAX_DERIVED_BYTES - size) {
                goto cleanup;
            }
            memcpy(record->sha256, sha_node->decoded, 64U);
            record->sha256[64] = '\0';
        }
        record->size = size;
        *total_bytes += size;
    }
    *path_bytes += relative_length;
    *acl_bytes += strlen(record->acl.canonical);
    result = 0;
cleanup:
    SecureZeroMemory(owner_sid, sizeof(owner_sid));
    sodium_memzero(acl_sha256, sizeof(acl_sha256));
    SecureZeroMemory(sddl_base64, sizeof(sddl_base64));
    if (result != 0 && record != NULL) {
        if (record->relative != NULL) {
            SecureZeroMemory(
                record->relative, strlen(record->relative) + 1U
            );
            HeapFree(GetProcessHeap(), 0U, record->relative);
        }
        if (record->relative_wide != NULL) {
            SecureZeroMemory(
                record->relative_wide,
                (wcslen(record->relative_wide) + 1U) * sizeof(wchar_t)
            );
            HeapFree(GetProcessHeap(), 0U, record->relative_wide);
        }
        wls_guardian_acl_proof_free(&record->acl);
        SecureZeroMemory(record, sizeof(*record));
    }
    return result;
}

static int wls_guardian_recovery_manifest_closure_parse(
    const struct wls_launcher_json_node *node,
    const struct wls_guardian_recovery_root_contract *contract,
    char inventory_kind,
    const char *inventory_digest,
    struct wls_guardian_recovery_manifest_closure *closure,
    size_t *path_bytes,
    unsigned long long *acl_bytes,
    unsigned long long *manifest_records,
    unsigned long long *manifest_bytes
) {
    static const char *const keys[] = {
        "entry_count", "kind", "records", "sha256", "total_bytes"
    };
    const struct wls_launcher_json_node *records;
    const struct wls_launcher_json_node *sha_node;
    unsigned long long declared_count = 0ULL;
    unsigned long long declared_bytes = 0ULL;
    unsigned long long computed_bytes = 0ULL;
    crypto_hash_sha256_state native_state;
    unsigned char native_binary[crypto_hash_sha256_BYTES];
    char records_digest[65];
    size_t index;
    int result = 1;
    sodium_memzero(&native_state, sizeof(native_state));
    sodium_memzero(native_binary, sizeof(native_binary));
    sodium_memzero(records_digest, sizeof(records_digest));
    if (node == NULL || contract == NULL || inventory_digest == NULL
        || closure == NULL || path_bytes == NULL || acl_bytes == NULL
        || manifest_records == NULL || manifest_bytes == NULL
        || !wls_guardian_recovery_object_exact_members(
            node, keys, sizeof(keys) / sizeof(keys[0])
        )
        || wls_launcher_json_u64(
            wls_launcher_json_member(node, "entry_count"), &declared_count
        ) != 0
        || declared_count == 0ULL
        || declared_count > WLS_GUARDIAN_MAX_DERIVED_ENTRIES
        || wls_launcher_json_u64(
            wls_launcher_json_member(node, "total_bytes"), &declared_bytes
        ) != 0
        || declared_bytes > WLS_GUARDIAN_MAX_DERIVED_BYTES
        || (records = wls_launcher_json_member(node, "records")) == NULL
        || records->kind != WLS_LAUNCHER_JSON_ARRAY
        || records->count != (size_t)declared_count
        || (sha_node = wls_launcher_json_member(node, "sha256")) == NULL
        || sha_node->kind != WLS_LAUNCHER_JSON_STRING
        || sha_node->decoded_length != 64U
        || !wls_is_hex(sha_node->decoded, 64U)
        || wls_guardian_sha256(
            (const unsigned char *)records->raw,
            records->raw_length,
            records_digest
        ) != 0
        || sodium_memcmp(records_digest, sha_node->decoded, 64U) != 0
        || ((inventory_kind == 'd'
                && !wls_launcher_json_string_equals(
                    wls_launcher_json_member(node, "kind"), "directory"
                ))
            || (inventory_kind == 'f'
                && !wls_launcher_json_string_equals(
                    wls_launcher_json_member(node, "kind"), "file"
                )))
        || *manifest_records
            > WLS_GUARDIAN_MAX_DERIVED_ENTRIES - declared_count
        || crypto_hash_sha256_init(&native_state) != 0) goto cleanup;
    closure->records = (struct wls_guardian_recovery_manifest_record *)
        HeapAlloc(
            GetProcessHeap(),
            HEAP_ZERO_MEMORY,
            records->count * sizeof(*closure->records)
        );
    if (closure->records == NULL) goto cleanup;
    closure->count = records->count;
    for (index = 0U; index < records->count; ++index) {
        if (wls_guardian_recovery_manifest_record_parse(
                records->items[index],
                contract,
                &closure->records[index],
                path_bytes,
                acl_bytes,
                &computed_bytes
            ) != 0
            || (index == 0U
                && (strcmp(closure->records[index].relative, ".") != 0
                    || closure->records[index].kind != inventory_kind))
            || (index > 0U
                && (strcmp(closure->records[index].relative, ".") == 0
                    || strcmp(
                        closure->records[index - 1U].relative,
                        closure->records[index].relative
                    ) >= 0))
            || wls_guardian_recovery_record_native_update(
                &native_state, &closure->records[index]
            ) != 0) goto cleanup;
    }
    if (computed_bytes != declared_bytes
        || *manifest_bytes > WLS_GUARDIAN_MAX_DERIVED_BYTES - computed_bytes
        || crypto_hash_sha256_final(
            &native_state, native_binary
        ) != 0
        || sodium_bin2hex(
            closure->native_sha256,
            sizeof(closure->native_sha256),
            native_binary,
            sizeof(native_binary)
        ) == NULL
        || sodium_memcmp(
            closure->native_sha256, inventory_digest, 64U
        ) != 0) goto cleanup;
    closure->kind = inventory_kind;
    closure->entry_count = declared_count;
    closure->total_bytes = declared_bytes;
    memcpy(closure->records_sha256, sha_node->decoded, 64U);
    closure->records_sha256[64] = '\0';
    *manifest_records += declared_count;
    *manifest_bytes += computed_bytes;
    result = 0;
cleanup:
    sodium_memzero(&native_state, sizeof(native_state));
    sodium_memzero(native_binary, sizeof(native_binary));
    sodium_memzero(records_digest, sizeof(records_digest));
    if (result != 0) {
        wls_guardian_recovery_manifest_closure_free(closure);
    }
    return result;
}

static int wls_guardian_recovery_manifest_category_parse(
    const struct wls_launcher_json_node *node,
    unsigned int category_index,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_evidence *evidence,
    size_t *path_bytes,
    unsigned long long *acl_bytes,
    unsigned long long *manifest_records,
    unsigned long long *manifest_bytes
) {
    static const char *const keys[] = {
        "authority_profile", "entries", "policy", "preserved", "root",
        "root_id"
    };
    const struct wls_guardian_recovery_root_contract *contract;
    const struct wls_launcher_json_node *entries;
    size_t index;
    if (node == NULL || request == NULL || evidence == NULL
        || path_bytes == NULL || acl_bytes == NULL
        || manifest_records == NULL || manifest_bytes == NULL
        || category_index >= WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT) return 1;
    contract = &wls_guardian_recovery_root_contracts[category_index];
    if (!wls_guardian_recovery_object_exact_members(
            node, keys, sizeof(keys) / sizeof(keys[0])
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "authority_profile"),
            contract->authority_profile
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "policy"), contract->policy
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(node, "root_id"), contract->root_id
        )
        || !wls_guardian_recovery_preserved_array_valid(
            wls_launcher_json_member(node, "preserved"),
            category_index,
            request
        )
        || !wls_guardian_recovery_manifest_root_matches(
            wls_launcher_json_member(node, "root"),
            &evidence->roots[category_index]
        )
        || (entries = wls_launcher_json_member(node, "entries")) == NULL
        || entries->kind != WLS_LAUNCHER_JSON_OBJECT
        || (!evidence->roots[category_index].present
            && entries->count != 0U)) return 1;
    for (index = 0U; index < entries->count; ++index) {
        const struct wls_launcher_json_member *member = &entries->members[index];
        struct wls_guardian_recovery_inventory_entry *entry;
        char leaf[256];
        wchar_t leaf_wide[256];
        size_t prior;
        SecureZeroMemory(leaf, sizeof(leaf));
        ZeroMemory(leaf_wide, sizeof(leaf_wide));
        if (wls_guardian_recovery_leaf_decode(
                (const unsigned char *)member->key,
                member->key_length,
                leaf,
                leaf_wide
            ) != 0
            || (entry = wls_guardian_recovery_inventory_find(
                evidence, category_index, leaf
            )) == NULL
            || entry->manifest_seen
            || entry->kind != (wls_launcher_json_string_equals(
                wls_launcher_json_member(member->value, "kind"), "directory"
            ) ? 'd' : 'f')) return 1;
        for (prior = 0U; prior < index; ++prior) {
            wchar_t prior_wide[256];
            char prior_leaf[256];
            SecureZeroMemory(prior_leaf, sizeof(prior_leaf));
            ZeroMemory(prior_wide, sizeof(prior_wide));
            if (wls_guardian_recovery_leaf_decode(
                    (const unsigned char *)entries->members[prior].key,
                    entries->members[prior].key_length,
                    prior_leaf,
                    prior_wide
                ) != 0
                || wls_guardian_recovery_wide_equal_folded(
                    prior_wide, leaf_wide
                )) return 1;
        }
        if (wls_guardian_recovery_manifest_closure_parse(
                member->value,
                contract,
                entry->kind,
                entry->closure_sha256,
                &entry->closure,
                path_bytes,
                acl_bytes,
                manifest_records,
                manifest_bytes
            ) != 0) return 1;
        entry->manifest_seen = 1;
    }
    return 0;
}

static int wls_guardian_recovery_ca_baseline_valid(
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_recovery_evidence *evidence
) {
    char contents[66];
    char digest[65];
    const struct wls_guardian_recovery_inventory_entry *entry = NULL;
    size_t index;
    int length;
    int result = 0;
    SecureZeroMemory(contents, sizeof(contents));
    sodium_memzero(digest, sizeof(digest));
    if (request == NULL || evidence == NULL) return 0;
    for (index = 0U; index < evidence->count; ++index) {
        if (evidence->entries[index].category_index == 8U
            && strcmp(
                evidence->entries[index].leaf, "ca-bundle.sha256"
            ) == 0) {
            entry = &evidence->entries[index];
            break;
        }
    }
    length = _snprintf_s(
        contents,
        sizeof(contents),
        _TRUNCATE,
        "%s\n",
        request->recovery_ca_sha256
    );
    if (entry != NULL && length == 65
        && wls_guardian_sha256(
            (const unsigned char *)contents, 65U, digest
        ) == 0
        && entry->kind == 'f' && entry->manifest_seen
        && entry->closure.count == 1U
        && entry->closure.entry_count == 1ULL
        && entry->closure.total_bytes == 65ULL
        && entry->closure.records[0].kind == 'f'
        && strcmp(entry->closure.records[0].relative, ".") == 0
        && entry->closure.records[0].size == 65ULL
        && sodium_memcmp(
            entry->closure.records[0].sha256, digest, 64U
        ) == 0) result = 1;
    SecureZeroMemory(contents, sizeof(contents));
    sodium_memzero(digest, sizeof(digest));
    return result;
}

static int wls_guardian_recovery_manifest_load(
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_evidence *evidence
) {
    static const char *const top_keys[] = {
        "categories", "derived_policy_sha256", "entry_count", "host_id",
        "nonce", "old_ca_bundle_sha256", "operation", "schema_version",
        "total_bytes", "windows_acl_bytes"
    };
    wchar_t path[WLS_PATH_CHARS];
    char *contents = NULL;
    size_t length = 0U;
    struct wls_guardian_pinned_file pin;
    struct wls_launcher_json_node *root = NULL;
    const struct wls_launcher_json_node *categories;
    char expected_policy[65];
    unsigned long long declared_entries = 0ULL;
    unsigned long long declared_bytes = 0ULL;
    unsigned long long declared_acl = 0ULL;
    unsigned long long manifest_records = 0ULL;
    unsigned long long manifest_bytes = 0ULL;
    unsigned long long manifest_acl = 0ULL;
    size_t path_bytes = 0U;
    unsigned int index;
    int result = 1;
    ZeroMemory(path, sizeof(path));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    sodium_memzero(expected_policy, sizeof(expected_policy));
    if (backup == NULL || request == NULL || evidence == NULL
        || wls_join(
            path,
            WLS_PATH_CHARS,
            backup,
            L"derived-state.manifest.json"
        ) != 0
        || wls_guardian_recovery_atomic_companions_absent(path) != 0
        || wls_guardian_recovery_root_only_read(
            path,
            WLS_GUARDIAN_MANIFEST_MAX_BYTES,
            request->derived_manifest_sha256,
            &contents,
            &length,
            &pin
        ) != 0
        || wls_guardian_recovery_json_is_canonical(
            contents, length, &root
        ) != 0
        || !wls_guardian_recovery_object_exact_members(
            root, top_keys, sizeof(top_keys) / sizeof(top_keys[0])
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(root, "schema_version"), "4"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "operation"),
            "wls-rebootstrap-derived-state"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "nonce"), request->nonce
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "host_id"), request->host_id
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "old_ca_bundle_sha256"),
            request->recovery_ca_sha256
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "derived_policy_sha256"),
            request->derived_policy_sha256
        )
        || wls_guardian_recovery_policy_digest(
            request, expected_policy
        ) != 0
        || !wls_guardian_constant_equal(
            expected_policy, request->derived_policy_sha256, 64U
        )
        || wls_launcher_json_u64(
            wls_launcher_json_member(root, "entry_count"), &declared_entries
        ) != 0
        || declared_entries > WLS_GUARDIAN_MAX_DERIVED_ENTRIES
        || wls_launcher_json_u64(
            wls_launcher_json_member(root, "total_bytes"), &declared_bytes
        ) != 0
        || declared_bytes > WLS_GUARDIAN_MAX_DERIVED_BYTES
        || wls_launcher_json_u64(
            wls_launcher_json_member(root, "windows_acl_bytes"),
            &declared_acl
        ) != 0
        || declared_acl > WLS_GUARDIAN_MAX_TOTAL_ACL_BYTES
        || (categories = wls_launcher_json_member(
            root, "categories"
        )) == NULL
        || categories->kind != WLS_LAUNCHER_JSON_OBJECT
        || categories->count != WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT) {
        goto cleanup;
    }
    for (index = 0U;
        index < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT;
        ++index) {
        const struct wls_launcher_json_node *category =
            wls_launcher_json_member(
                categories,
                wls_guardian_recovery_root_contracts[index].category
            );
        if (category == NULL
            || wls_guardian_recovery_manifest_category_parse(
                category,
                index,
                request,
                evidence,
                &path_bytes,
                &manifest_acl,
                &manifest_records,
                &manifest_bytes
            ) != 0) goto cleanup;
    }
    for (index = 0U; index < evidence->count; ++index) {
        if (!evidence->entries[index].manifest_seen) goto cleanup;
    }
    if (manifest_records != declared_entries
        || manifest_bytes != declared_bytes
        || manifest_acl != declared_acl
        || !wls_guardian_recovery_ca_baseline_valid(request, evidence)
        || !wls_guardian_pinned_identity_unchanged(&pin, 0)) goto cleanup;
    evidence->manifest_entry_count = manifest_records;
    evidence->manifest_total_bytes = manifest_bytes;
    evidence->manifest_acl_bytes = manifest_acl;
    result = 0;
cleanup:
    wls_launcher_json_free(root);
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    wls_guardian_pinned_close(&pin);
    SecureZeroMemory(path, sizeof(path));
    sodium_memzero(expected_policy, sizeof(expected_policy));
    return result;
}

static int wls_guardian_recovery_evidence_load(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_evidence *evidence
) {
    if (home == NULL || request == NULL || evidence == NULL) return 1;
    SecureZeroMemory(evidence, sizeof(*evidence));
    if (wls_guardian_recovery_backup_root(
            home, request->nonce, evidence->backup
        ) != 0
        || wls_guardian_recovery_authorization_verify(
            home, evidence->backup, request
        ) != 0
        || wls_guardian_recovery_inventory_load(
            home, evidence->backup, request, evidence
        ) != 0
        || wls_guardian_recovery_manifest_load(
            evidence->backup, request, evidence
        ) != 0) {
        wls_guardian_recovery_evidence_free(evidence);
        return 1;
    }
    return 0;
}

static int wls_guardian_recovery_walk_compare(
    const void *left,
    const void *right
) {
    const struct wls_guardian_recovery_walk_item *a =
        (const struct wls_guardian_recovery_walk_item *)left;
    const struct wls_guardian_recovery_walk_item *b =
        (const struct wls_guardian_recovery_walk_item *)right;
    if (strcmp(a->relative, ".") == 0) {
        return strcmp(b->relative, ".") == 0 ? 0 : -1;
    }
    if (strcmp(b->relative, ".") == 0) return 1;
    return strcmp(a->relative, b->relative);
}

static int wls_guardian_recovery_walk_add(
    struct wls_guardian_recovery_walk *walk,
    const char *relative,
    const wchar_t *relative_wide,
    char kind,
    unsigned long long volume
) {
    size_t relative_length;
    size_t wide_length;
    size_t next;
    struct wls_guardian_recovery_walk_item *grown;
    char *relative_copy = NULL;
    wchar_t *wide_copy = NULL;
    if (walk == NULL || relative == NULL || relative_wide == NULL
        || (kind != 'd' && kind != 'f') || volume == 0ULL) return 1;
    relative_length = strlen(relative);
    wide_length = wcslen(relative_wide);
    if (relative_length == 0U || relative_length > WLS_PATH_CHARS - 1U
        || wide_length == 0U || wide_length > WLS_PATH_CHARS - 1U
        || walk->count >= WLS_GUARDIAN_MAX_DERIVED_ENTRIES
        || walk->path_bytes
            > WLS_GUARDIAN_RECOVERY_PATH_BYTES - relative_length
        || (walk->volume != 0ULL && walk->volume != volume)) return 1;
    if (walk->count == walk->capacity) {
        next = walk->capacity == 0U ? 32U : walk->capacity * 2U;
        if (next < walk->capacity
            || next > WLS_GUARDIAN_MAX_DERIVED_ENTRIES) {
            next = WLS_GUARDIAN_MAX_DERIVED_ENTRIES;
        }
        grown = walk->items == NULL
            ? (struct wls_guardian_recovery_walk_item *)HeapAlloc(
                GetProcessHeap(),
                HEAP_ZERO_MEMORY,
                next * sizeof(*walk->items)
            )
            : (struct wls_guardian_recovery_walk_item *)HeapReAlloc(
                GetProcessHeap(),
                HEAP_ZERO_MEMORY,
                walk->items,
                next * sizeof(*walk->items)
            );
        if (grown == NULL) return 1;
        walk->items = grown;
        walk->capacity = next;
    }
    relative_copy = (char *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, relative_length + 1U
    );
    wide_copy = (wchar_t *)HeapAlloc(
        GetProcessHeap(),
        HEAP_ZERO_MEMORY,
        (wide_length + 1U) * sizeof(wchar_t)
    );
    if (relative_copy == NULL || wide_copy == NULL) goto cleanup;
    memcpy(relative_copy, relative, relative_length + 1U);
    memcpy(
        wide_copy,
        relative_wide,
        (wide_length + 1U) * sizeof(wchar_t)
    );
    walk->items[walk->count].relative = relative_copy;
    walk->items[walk->count].relative_wide = wide_copy;
    walk->items[walk->count].kind = kind;
    walk->count++;
    walk->path_bytes += relative_length;
    walk->volume = volume;
    return 0;
cleanup:
    if (wide_copy != NULL) {
        SecureZeroMemory(wide_copy, (wide_length + 1U) * sizeof(wchar_t));
        HeapFree(GetProcessHeap(), 0U, wide_copy);
    }
    if (relative_copy != NULL) {
        SecureZeroMemory(relative_copy, relative_length + 1U);
        HeapFree(GetProcessHeap(), 0U, relative_copy);
    }
    return 1;
}

struct wls_guardian_recovery_child_name {
    wchar_t wide[256];
    char utf8[1024];
    int directory;
};

static int wls_guardian_recovery_child_compare(
    const void *left,
    const void *right
) {
    const struct wls_guardian_recovery_child_name *a =
        (const struct wls_guardian_recovery_child_name *)left;
    const struct wls_guardian_recovery_child_name *b =
        (const struct wls_guardian_recovery_child_name *)right;
    return strcmp(a->utf8, b->utf8);
}

static void wls_guardian_recovery_children_free(
    struct wls_guardian_recovery_child_name **children,
    size_t count
) {
    if (children == NULL || *children == NULL) return;
    SecureZeroMemory(
        *children, count * sizeof(**children)
    );
    HeapFree(GetProcessHeap(), 0U, *children);
    *children = NULL;
}

static int wls_guardian_recovery_directory_children(
    struct wls_guardian_pinned_file *directory,
    struct wls_guardian_recovery_child_name **children_out,
    size_t *count_out
) {
    unsigned char buffer[WLS_GUARDIAN_RECOVERY_DIRECTORY_BUFFER];
    struct wls_guardian_recovery_child_name *children = NULL;
    size_t count = 0U;
    size_t capacity = 0U;
    int restart = 1;
    int result = 1;
    ZeroMemory(buffer, sizeof(buffer));
    if (directory == NULL || children_out == NULL || count_out == NULL
        || !wls_guardian_protocol_directory_identity_unchanged(directory)) {
        return 1;
    }
    *children_out = NULL;
    *count_out = 0U;
    for (;;) {
        FILE_ID_BOTH_DIR_INFO *entry;
        size_t offset = 0U;
        ZeroMemory(buffer, sizeof(buffer));
        if (!GetFileInformationByHandleEx(
                directory->handle,
                restart ? FileIdBothDirectoryRestartInfo
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
            wchar_t name[256];
            int is_dot;
            int is_directory;
            ZeroMemory(name, sizeof(name));
            if (offset > sizeof(buffer) - minimum
                || entry->FileNameLength == 0U
                || entry->FileNameLength % sizeof(wchar_t) != 0U
                || (size_t)entry->FileNameLength
                    > sizeof(buffer) - offset - minimum) goto cleanup;
            characters = entry->FileNameLength / sizeof(wchar_t);
            if (characters == 0U || characters > 255U) goto cleanup;
            memcpy(name, entry->FileName, entry->FileNameLength);
            name[characters] = L'\0';
            is_dot = (characters == 1U && name[0] == L'.')
                || (characters == 2U && name[0] == L'.'
                    && name[1] == L'.');
            if (!is_dot) {
                size_t index;
                size_t next;
                struct wls_guardian_recovery_child_name *grown;
                if (!wls_guardian_recovery_leaf_wide_valid(name)
                    || (entry->FileAttributes
                        & FILE_ATTRIBUTE_REPARSE_POINT) != 0U
                    || count >= WLS_GUARDIAN_MAX_DERIVED_ENTRIES) {
                    goto cleanup;
                }
                is_directory = (entry->FileAttributes
                    & FILE_ATTRIBUTE_DIRECTORY) != 0U;
                for (index = 0U; index < count; ++index) {
                    if (wls_guardian_recovery_wide_equal_folded(
                            children[index].wide, name
                        )) {
                        goto cleanup;
                    }
                }
                if (count == capacity) {
                    next = capacity == 0U ? 32U : capacity * 2U;
                    if (next < capacity
                        || next > WLS_GUARDIAN_MAX_DERIVED_ENTRIES) {
                        next = WLS_GUARDIAN_MAX_DERIVED_ENTRIES;
                    }
                    grown = children == NULL
                        ? (struct wls_guardian_recovery_child_name *)HeapAlloc(
                            GetProcessHeap(),
                            HEAP_ZERO_MEMORY,
                            next * sizeof(*children)
                        )
                        : (struct wls_guardian_recovery_child_name *)
                            HeapReAlloc(
                                GetProcessHeap(),
                                HEAP_ZERO_MEMORY,
                                children,
                                next * sizeof(*children)
                            );
                    if (grown == NULL) goto cleanup;
                    children = grown;
                    capacity = next;
                }
                memcpy(
                    children[count].wide,
                    name,
                    (characters + 1U) * sizeof(wchar_t)
                );
                if (wls_guardian_recovery_wide_to_utf8(
                        name,
                        children[count].utf8,
                        sizeof(children[count].utf8)
                    ) != 0) goto cleanup;
                children[count].directory = is_directory;
                count++;
            }
            if (entry->NextEntryOffset == 0U) break;
            if (entry->NextEntryOffset < minimum
                || entry->NextEntryOffset > sizeof(buffer) - offset) {
                goto cleanup;
            }
            offset += entry->NextEntryOffset;
            entry = (FILE_ID_BOTH_DIR_INFO *)(void *)(buffer + offset);
        }
    }
    if (!wls_guardian_protocol_directory_identity_unchanged(directory)) {
        goto cleanup;
    }
    if (count > 1U) {
        qsort(
            children,
            count,
            sizeof(*children),
            wls_guardian_recovery_child_compare
        );
    }
    *children_out = children;
    *count_out = count;
    children = NULL;
    result = 0;
cleanup:
    if (children != NULL) {
        SecureZeroMemory(children, capacity * sizeof(*children));
        HeapFree(GetProcessHeap(), 0U, children);
    }
    SecureZeroMemory(buffer, sizeof(buffer));
    return result;
}

static int wls_guardian_recovery_walk_collect(
    const wchar_t *path,
    const char *relative,
    const wchar_t *relative_wide,
    char expected_kind,
    unsigned int depth,
    struct wls_guardian_recovery_walk *walk
) {
    struct wls_guardian_pinned_file pin;
    struct wls_guardian_recovery_child_name *children = NULL;
    size_t child_count = 0U;
    size_t index;
    int directory;
    int result = 1;
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL || relative == NULL || relative_wide == NULL
        || walk == NULL || (expected_kind != 'd' && expected_kind != 'f')
        || depth > WLS_GUARDIAN_RECOVERY_MAX_DEPTH) return 1;
    directory = expected_kind == 'd';
    if (wls_guardian_open_pinned(
            path,
            directory,
            directory
                ? FILE_LIST_DIRECTORY | FILE_TRAVERSE | GENERIC_READ
                : GENERIC_READ,
            &pin
        ) != 0
        || wls_guardian_recovery_walk_add(
            walk,
            relative,
            relative_wide,
            expected_kind,
            pin.file_id.VolumeSerialNumber
        ) != 0) goto cleanup;
    if (!directory) {
        result = wls_guardian_pinned_identity_unchanged(&pin, 0) ? 0 : 1;
        goto cleanup;
    }
    if (wls_guardian_recovery_directory_children(
            &pin, &children, &child_count
        ) != 0) goto cleanup;
    for (index = 0U; index < child_count; ++index) {
        wchar_t child_path[WLS_PATH_CHARS];
        wchar_t child_relative_wide[WLS_PATH_CHARS];
        char child_relative[WLS_PATH_CHARS];
        int utf8_length;
        int wide_length;
        ZeroMemory(child_path, sizeof(child_path));
        ZeroMemory(child_relative_wide, sizeof(child_relative_wide));
        SecureZeroMemory(child_relative, sizeof(child_relative));
        if (wls_join(
                child_path,
                WLS_PATH_CHARS,
                path,
                children[index].wide
            ) != 0) goto cleanup;
        utf8_length = strcmp(relative, ".") == 0
            ? _snprintf_s(
                child_relative,
                sizeof(child_relative),
                _TRUNCATE,
                "%s",
                children[index].utf8
            )
            : _snprintf_s(
                child_relative,
                sizeof(child_relative),
                _TRUNCATE,
                "%s/%s",
                relative,
                children[index].utf8
            );
        wide_length = wcscmp(relative_wide, L".") == 0
            ? _snwprintf_s(
                child_relative_wide,
                WLS_PATH_CHARS,
                _TRUNCATE,
                L"%ls",
                children[index].wide
            )
            : _snwprintf_s(
                child_relative_wide,
                WLS_PATH_CHARS,
                _TRUNCATE,
                L"%ls\\%ls",
                relative_wide,
                children[index].wide
            );
        if (utf8_length <= 0 || wide_length <= 0
            || wls_guardian_recovery_walk_collect(
                child_path,
                child_relative,
                child_relative_wide,
                children[index].directory ? 'd' : 'f',
                depth + 1U,
                walk
            ) != 0) goto cleanup;
    }
    if (!wls_guardian_protocol_directory_identity_unchanged(&pin)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_recovery_children_free(&children, child_count);
    wls_guardian_pinned_close(&pin);
    return result;
}

static int wls_guardian_recovery_record_path(
    const wchar_t *root,
    const wchar_t *relative,
    wchar_t path[WLS_PATH_CHARS]
) {
    if (root == NULL || relative == NULL || path == NULL) return 1;
    if (wcscmp(relative, L".") == 0) {
        if (wcslen(root) + 1U > WLS_PATH_CHARS) return 1;
        memcpy(path, root, (wcslen(root) + 1U) * sizeof(wchar_t));
        return 0;
    }
    return wls_join(path, WLS_PATH_CHARS, root, relative);
}

/* acl_mode: 0=content only, 1=manifest ACL, 2=sealed SYSTEM/Admin backup. */
static int wls_guardian_recovery_closure_verify(
    const wchar_t *path,
    const struct wls_guardian_recovery_manifest_closure *closure,
    int acl_mode
) {
    struct wls_guardian_recovery_walk walk;
    size_t index;
    int result = 1;
    SecureZeroMemory(&walk, sizeof(walk));
    if (path == NULL || closure == NULL || closure->records == NULL
        || closure->count == 0U || closure->count != closure->entry_count
        || (acl_mode < 0 || acl_mode > 2)
        || wls_guardian_recovery_walk_collect(
            path,
            ".",
            L".",
            closure->kind,
            0U,
            &walk
        ) != 0
        || walk.count != closure->count) goto cleanup;
    if (walk.count > 1U) {
        qsort(
            walk.items,
            walk.count,
            sizeof(*walk.items),
            wls_guardian_recovery_walk_compare
        );
    }
    for (index = 0U; index < walk.count; ++index) {
        const struct wls_guardian_recovery_manifest_record *record =
            &closure->records[index];
        wchar_t record_path[WLS_PATH_CHARS];
        struct wls_guardian_pinned_file pin;
        char digest[65];
        unsigned long long size = 0ULL;
        ZeroMemory(record_path, sizeof(record_path));
        ZeroMemory(&pin, sizeof(pin));
        pin.handle = INVALID_HANDLE_VALUE;
        sodium_memzero(digest, sizeof(digest));
        if (strcmp(walk.items[index].relative, record->relative) != 0
            || walk.items[index].kind != record->kind
            || wls_guardian_recovery_record_path(
                path, record->relative_wide, record_path
            ) != 0
            || wls_guardian_open_pinned(
                record_path,
                record->kind == 'd',
                GENERIC_READ
                    | (record->kind == 'd'
                        ? FILE_LIST_DIRECTORY | FILE_TRAVERSE : 0U),
                &pin
            ) != 0
            || pin.file_id.VolumeSerialNumber != walk.volume
            || (acl_mode == 1
                && wls_guardian_handle_sddl_exact(
                    pin.handle, record->acl.canonical
                ) != 0)
            || (acl_mode == 2
                && wls_recovery_acl_safe(pin.handle, 0) != 0)
            || (record->kind == 'f'
                && (wls_guardian_digest_handle(
                        &pin,
                        WLS_GUARDIAN_MAX_DERIVED_BYTES,
                        digest,
                        &size
                    ) != 0
                    || size != record->size
                    || sodium_memcmp(digest, record->sha256, 64U) != 0))
            || !(record->kind == 'd'
                ? wls_guardian_protocol_directory_identity_unchanged(&pin)
                : wls_guardian_pinned_identity_unchanged(&pin, 0))) {
            wls_guardian_pinned_close(&pin);
            sodium_memzero(digest, sizeof(digest));
            goto cleanup;
        }
        wls_guardian_pinned_close(&pin);
        sodium_memzero(digest, sizeof(digest));
    }
    result = 0;
cleanup:
    wls_guardian_recovery_walk_free(&walk);
    return result;
}

static int wls_guardian_recovery_closure_state(
    const wchar_t *path,
    const struct wls_guardian_recovery_manifest_closure *closure,
    int acl_mode
) {
    DWORD attributes;
    DWORD error;
    if (path == NULL || closure == NULL) return 1;
    SetLastError(ERROR_SUCCESS);
    attributes = GetFileAttributesW(path);
    if (attributes == INVALID_FILE_ATTRIBUTES) {
        error = GetLastError();
        return error == ERROR_FILE_NOT_FOUND || error == ERROR_PATH_NOT_FOUND
            ? 2 : 1;
    }
    return wls_guardian_recovery_closure_verify(
        path, closure, acl_mode
    ) == 0 ? 0 : 1;
}

static int wls_guardian_recovery_closure_apply_acl(
    const wchar_t *path,
    const struct wls_guardian_recovery_manifest_closure *closure
) {
    size_t index;
    if (path == NULL || closure == NULL || closure->records == NULL
        || closure->count == 0U) return 1;
    /* Canonical records are parent-before-child. Reverse order ensures no
     * directory ACL is narrowed before every descendant has been restored. */
    for (index = closure->count; index > 0U; --index) {
        const struct wls_guardian_recovery_manifest_record *record =
            &closure->records[index - 1U];
        wchar_t record_path[WLS_PATH_CHARS];
        struct wls_guardian_pinned_file pin;
        ZeroMemory(record_path, sizeof(record_path));
        ZeroMemory(&pin, sizeof(pin));
        pin.handle = INVALID_HANDLE_VALUE;
        if (wls_guardian_recovery_record_path(
                path, record->relative_wide, record_path
            ) != 0
            || wls_guardian_open_pinned(
                record_path,
                record->kind == 'd',
                WRITE_DAC | WRITE_OWNER | GENERIC_READ,
                &pin
            ) != 0
            || wls_guardian_acl_apply_exact(
                &pin, record->kind == 'd', &record->acl
            ) != 0) {
            wls_guardian_pinned_close(&pin);
            return 1;
        }
        wls_guardian_pinned_close(&pin);
    }
    return wls_guardian_recovery_closure_verify(path, closure, 1);
}

static int wls_guardian_recovery_closure_safe(const wchar_t *path)
{
    struct wls_guardian_recovery_walk walk;
    struct wls_guardian_pinned_file directory;
    struct wls_guardian_pinned_file file;
    int directory_state;
    int file_state;
    int result = 1;
    SecureZeroMemory(&walk, sizeof(walk));
    ZeroMemory(&directory, sizeof(directory));
    ZeroMemory(&file, sizeof(file));
    directory.handle = INVALID_HANDLE_VALUE;
    file.handle = INVALID_HANDLE_VALUE;
    if (path == NULL) return 1;
    directory_state = wls_guardian_recovery_path_kind(path, 1, &directory);
    if (directory_state == 0) {
        wls_guardian_pinned_close(&directory);
        result = wls_guardian_recovery_walk_collect(
            path, ".", L".", 'd', 0U, &walk
        );
        goto cleanup;
    }
    file_state = wls_guardian_recovery_path_kind(path, 0, &file);
    if (file_state == 0) {
        wls_guardian_pinned_close(&file);
        result = wls_guardian_recovery_walk_collect(
            path, ".", L".", 'f', 0U, &walk
        );
    }
cleanup:
    wls_guardian_pinned_close(&directory);
    wls_guardian_pinned_close(&file);
    wls_guardian_recovery_walk_free(&walk);
    return result;
}

static int wls_guardian_recovery_directory_closure_safe(
    const wchar_t *path
) {
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL
        || wls_guardian_recovery_path_kind(path, 1, &pin) != 0
        || wls_guardian_recovery_closure_safe(path) != 0
        || !wls_guardian_protocol_directory_identity_unchanged(&pin)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&pin);
    return result;
}

static int wls_guardian_recovery_file_id_equal(
    const FILE_ID_INFO *left,
    const FILE_ID_INFO *right
) {
    return left != NULL && right != NULL
        && left->VolumeSerialNumber == right->VolumeSerialNumber
        && sodium_memcmp(
            left->FileId.Identifier,
            right->FileId.Identifier,
            sizeof(left->FileId.Identifier)
        ) == 0;
}

static int wls_guardian_recovery_rename_no_replace(
    const wchar_t *source,
    const wchar_t *target,
    int directory
) {
    wchar_t source_parent[WLS_PATH_CHARS];
    wchar_t target_parent[WLS_PATH_CHARS];
    wchar_t source_leaf[256];
    wchar_t target_leaf[256];
    struct wls_guardian_pinned_file source_pin;
    struct wls_guardian_pinned_file source_parent_pin;
    struct wls_guardian_pinned_file target_parent_pin;
    struct wls_guardian_pinned_file published_pin;
    FILE_ID_INFO source_id;
    unsigned long long volume;
    int result = 1;
    ZeroMemory(source_parent, sizeof(source_parent));
    ZeroMemory(target_parent, sizeof(target_parent));
    ZeroMemory(source_leaf, sizeof(source_leaf));
    ZeroMemory(target_leaf, sizeof(target_leaf));
    ZeroMemory(&source_pin, sizeof(source_pin));
    ZeroMemory(&source_parent_pin, sizeof(source_parent_pin));
    ZeroMemory(&target_parent_pin, sizeof(target_parent_pin));
    ZeroMemory(&published_pin, sizeof(published_pin));
    ZeroMemory(&source_id, sizeof(source_id));
    source_pin.handle = INVALID_HANDLE_VALUE;
    source_parent_pin.handle = INVALID_HANDLE_VALUE;
    target_parent_pin.handle = INVALID_HANDLE_VALUE;
    published_pin.handle = INVALID_HANDLE_VALUE;
    if (source == NULL || target == NULL
        || wls_guardian_protocol_path_parts(
            source, source_parent, source_leaf
        ) != 0
        || wls_guardian_protocol_path_parts(
            target, target_parent, target_leaf
        ) != 0
        || wls_guardian_open_pinned(
            source,
            directory,
            GENERIC_READ | DELETE,
            &source_pin
        ) != 0
        || wls_guardian_open_pinned(
            source_parent,
            1,
            FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &source_parent_pin
        ) != 0
        || wls_guardian_open_pinned(
            target_parent,
            1,
            FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &target_parent_pin
        ) != 0
        || !wls_guardian_recovery_path_absent(target)) goto cleanup;
    source_id = source_pin.file_id;
    volume = source_id.VolumeSerialNumber;
    if (volume == 0ULL
        || source_parent_pin.file_id.VolumeSerialNumber != volume
        || target_parent_pin.file_id.VolumeSerialNumber != volume
        || !wls_guardian_protocol_directory_identity_unchanged(
            &source_parent_pin
        )
        || !wls_guardian_protocol_directory_identity_unchanged(
            &target_parent_pin
        )) goto cleanup;
    /* A no-share-delete source handle blocks the rename. Close it only after
     * all identities and the absent target have been fixed under held parent
     * handles, then prove the published object has the same FileId. */
    wls_guardian_pinned_close(&source_pin);
    if (!MoveFileExW(source, target, MOVEFILE_WRITE_THROUGH)
        || wls_guardian_open_pinned(
            target, directory, GENERIC_READ, &published_pin
        ) != 0
        || !wls_guardian_recovery_file_id_equal(
            &source_id, &published_pin.file_id
        )
        || !wls_guardian_protocol_directory_identity_unchanged(
            &source_parent_pin
        )
        || !wls_guardian_protocol_directory_identity_unchanged(
            &target_parent_pin
        )) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_pinned_close(&published_pin);
    wls_guardian_pinned_close(&target_parent_pin);
    wls_guardian_pinned_close(&source_parent_pin);
    wls_guardian_pinned_close(&source_pin);
    SecureZeroMemory(source_parent, sizeof(source_parent));
    SecureZeroMemory(target_parent, sizeof(target_parent));
    SecureZeroMemory(source_leaf, sizeof(source_leaf));
    SecureZeroMemory(target_leaf, sizeof(target_leaf));
    SecureZeroMemory(&source_id, sizeof(source_id));
    return result;
}

static int wls_guardian_recovery_root_only_directory(
    const wchar_t *path,
    int create
) {
    PSECURITY_DESCRIPTOR descriptor = NULL;
    SECURITY_ATTRIBUTES security;
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(&security, sizeof(security));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL) return 1;
    if (wls_guardian_open_pinned(
            path,
            1,
            FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &pin
        ) != 0) {
        if (!create || !wls_guardian_recovery_path_absent(path)
            || !ConvertStringSecurityDescriptorToSecurityDescriptorW(
                L"O:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)",
                SDDL_REVISION_1,
                &descriptor,
                NULL
            )) goto cleanup;
        security.nLength = sizeof(security);
        security.lpSecurityDescriptor = descriptor;
        security.bInheritHandle = FALSE;
        if (!CreateDirectoryW(path, &security)
            || wls_guardian_open_pinned(
                path,
                1,
                FILE_TRAVERSE | FILE_LIST_DIRECTORY,
                &pin
            ) != 0) goto cleanup;
    }
    if (wls_recovery_acl_safe(pin.handle, 0) != 0
        || !wls_guardian_protocol_directory_identity_unchanged(&pin)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (descriptor != NULL) LocalFree(descriptor);
    wls_guardian_pinned_close(&pin);
    return result;
}

static int wls_guardian_recovery_derived_paths(
    const wchar_t *home,
    const wchar_t *backup,
    unsigned int category_index,
    wchar_t live[WLS_PATH_CHARS],
    wchar_t stored[WLS_PATH_CHARS],
    wchar_t quarantine[WLS_PATH_CHARS]
) {
    wchar_t derived[WLS_PATH_CHARS];
    wchar_t new_derived[WLS_PATH_CHARS];
    wchar_t category[64];
    int result = 1;
    ZeroMemory(derived, sizeof(derived));
    ZeroMemory(new_derived, sizeof(new_derived));
    ZeroMemory(category, sizeof(category));
    if (home == NULL || backup == NULL || live == NULL || stored == NULL
        || quarantine == NULL
        || category_index >= WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT
        || wls_utf8_to_wide(
            wls_guardian_recovery_root_contracts[category_index].category,
            category,
            sizeof(category) / sizeof(category[0])
        ) != 0
        || wls_join(
            live,
            WLS_PATH_CHARS,
            home,
            wls_guardian_recovery_root_contracts[category_index].live_relative
        ) != 0
        || wls_join(derived, WLS_PATH_CHARS, backup, L"derived") != 0
        || wls_join(stored, WLS_PATH_CHARS, derived, category) != 0
        || wls_join(
            new_derived, WLS_PATH_CHARS, backup, L"new-derived"
        ) != 0
        || wls_join(
            quarantine, WLS_PATH_CHARS, new_derived, category
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(derived, sizeof(derived));
    SecureZeroMemory(new_derived, sizeof(new_derived));
    SecureZeroMemory(category, sizeof(category));
    if (result != 0) {
        if (live != NULL) ZeroMemory(live, WLS_PATH_CHARS * sizeof(*live));
        if (stored != NULL) {
            ZeroMemory(stored, WLS_PATH_CHARS * sizeof(*stored));
        }
        if (quarantine != NULL) {
            ZeroMemory(quarantine, WLS_PATH_CHARS * sizeof(*quarantine));
        }
    }
    return result;
}

static int wls_guardian_recovery_authority_apply(
    const wchar_t *path,
    int directory,
    const struct wls_guardian_acl_proof *proof
) {
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL || proof == NULL
        || wls_guardian_open_pinned(
            path,
            directory,
            GENERIC_READ | READ_CONTROL | WRITE_DAC | WRITE_OWNER
                | (directory ? FILE_TRAVERSE | FILE_LIST_DIRECTORY : 0U),
            &pin
        ) != 0
        || wls_guardian_acl_apply_exact(&pin, directory, proof) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&pin);
    return result;
}

static int wls_guardian_recovery_parent_authority_verify(
    const wchar_t *live_root,
    const struct wls_guardian_recovery_root_authority *authority,
    int apply
) {
    wchar_t parent[WLS_PATH_CHARS];
    wchar_t leaf[256];
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(parent, sizeof(parent));
    ZeroMemory(leaf, sizeof(leaf));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (live_root == NULL || authority == NULL
        || authority->contract == NULL
        || wls_guardian_protocol_path_parts(
            live_root, parent, leaf
        ) != 0
        || wls_guardian_open_pinned(
            parent,
            1,
            GENERIC_READ | READ_CONTROL
                | (apply ? WRITE_DAC | WRITE_OWNER : 0U)
                | FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &pin
        ) != 0
        || wls_guardian_recovery_identity_matches(
            &pin,
            authority->parent_device,
            authority->parent_inode,
            1
        ) != 0
        || (apply
            && wls_guardian_acl_apply_exact(
                &pin, 1, &authority->parent_acl
            ) != 0)
        || wls_guardian_handle_sddl_exact(
            pin.handle, authority->parent_acl.canonical
        ) != 0
        || !wls_guardian_protocol_directory_identity_unchanged(&pin)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&pin);
    SecureZeroMemory(parent, sizeof(parent));
    SecureZeroMemory(leaf, sizeof(leaf));
    return result;
}

/* 0=exact, 2=absent, 1=unsafe/mismatch. */
static int wls_guardian_recovery_root_authority_state(
    const wchar_t *path,
    const struct wls_guardian_recovery_root_authority *authority,
    int require_original_identity
) {
    struct wls_guardian_pinned_file pin;
    int state;
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL || authority == NULL || authority->contract == NULL) {
        return 1;
    }
    state = wls_guardian_recovery_path_kind(path, 1, &pin);
    if (!authority->present) {
        wls_guardian_pinned_close(&pin);
        return state == 2 ? 0 : 1;
    }
    if (state != 0
        || (require_original_identity
            && wls_guardian_recovery_identity_matches(
                &pin, authority->device, authority->inode, 1
            ) != 0)
        || wls_guardian_handle_sddl_exact(
            pin.handle, authority->acl.canonical
        ) != 0
        || !wls_guardian_protocol_directory_identity_unchanged(&pin)) {
        wls_guardian_pinned_close(&pin);
        return state == 2 ? 2 : 1;
    }
    wls_guardian_pinned_close(&pin);
    return 0;
}

static int wls_guardian_recovery_create_authority_root(
    const wchar_t *path,
    const struct wls_guardian_recovery_root_authority *authority
) {
    SECURITY_ATTRIBUTES security;
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(&security, sizeof(security));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL || authority == NULL || !authority->present
        || authority->acl.descriptor == NULL
        || !wls_guardian_recovery_path_absent(path)) return 1;
    security.nLength = sizeof(security);
    security.lpSecurityDescriptor = authority->acl.descriptor;
    security.bInheritHandle = FALSE;
    if (!CreateDirectoryW(path, &security)
        || wls_guardian_open_pinned(
            path,
            1,
            GENERIC_READ | READ_CONTROL | WRITE_DAC | WRITE_OWNER
                | FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &pin
        ) != 0
        || wls_guardian_acl_apply_exact(&pin, 1, &authority->acl) != 0
        || wls_guardian_handle_sddl_exact(
            pin.handle, authority->acl.canonical
        ) != 0
        || !wls_guardian_protocol_directory_identity_unchanged(&pin)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&pin);
    return result;
}

static int wls_guardian_recovery_marker_state(
    const wchar_t *path,
    int *present
) {
    struct wls_guardian_pinned_file pin;
    int state;
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL || present == NULL) return 1;
    *present = 0;
    state = wls_guardian_recovery_path_kind(path, 1, &pin);
    if (state == 2) return 0;
    if (state != 0 || wls_recovery_acl_safe(pin.handle, 0) != 0
        || !wls_guardian_protocol_directory_identity_unchanged(&pin)) {
        wls_guardian_pinned_close(&pin);
        return 1;
    }
    wls_guardian_pinned_close(&pin);
    *present = 1;
    return 0;
}

static int wls_guardian_recovery_root_markers(
    const wchar_t *quarantine,
    wchar_t after_image[WLS_PATH_CHARS],
    wchar_t absence[WLS_PATH_CHARS],
    int *after_present,
    int *absence_present
) {
    struct wls_guardian_pinned_file after_pin;
    int after_state;
    ZeroMemory(&after_pin, sizeof(after_pin));
    after_pin.handle = INVALID_HANDLE_VALUE;
    if (quarantine == NULL || after_image == NULL || absence == NULL
        || after_present == NULL || absence_present == NULL
        || wls_join(
            after_image,
            WLS_PATH_CHARS,
            quarantine,
            L".wls-root-after-image"
        ) != 0
        || wls_join(
            absence,
            WLS_PATH_CHARS,
            quarantine,
            L".wls-root-was-absent"
        ) != 0
        || wls_guardian_recovery_marker_state(
            absence, absence_present
        ) != 0) return 1;
    *after_present = 0;
    after_state = wls_guardian_recovery_path_kind(
        after_image, 1, &after_pin
    );
    if (after_state == 0) {
        wls_guardian_pinned_close(&after_pin);
        if (wls_guardian_recovery_directory_closure_safe(
                after_image
            ) != 0) {
            return 1;
        }
        *after_present = 1;
    } else if (after_state != 2) {
        wls_guardian_pinned_close(&after_pin);
        return 1;
    }
    wls_guardian_pinned_close(&after_pin);
    if (*after_present && *absence_present) return 1;
    return 0;
}

static int wls_guardian_recovery_derived_root_prevalidate(
    const wchar_t *live,
    const wchar_t *quarantine,
    const struct wls_guardian_recovery_root_authority *authority
) {
    wchar_t after_image[WLS_PATH_CHARS];
    wchar_t absence[WLS_PATH_CHARS];
    int after_present = 0;
    int absence_present = 0;
    int result = 1;
    ZeroMemory(after_image, sizeof(after_image));
    ZeroMemory(absence, sizeof(absence));
    if (live == NULL || quarantine == NULL || authority == NULL
        || wls_guardian_recovery_parent_authority_verify(
            live, authority, 0
        ) != 0
        || wls_guardian_recovery_root_markers(
            quarantine,
            after_image,
            absence,
            &after_present,
            &absence_present
        ) != 0) goto cleanup;
    if (authority->contract->preserve_identity) {
        result = authority->present && !after_present && !absence_present
            && wls_guardian_recovery_root_authority_state(
                live, authority, 1
            ) == 0 ? 0 : 1;
        goto cleanup;
    }
    if (after_present || absence_present) {
        if (!authority->present) {
            result = wls_guardian_recovery_path_absent(live) ? 0 : 1;
        } else {
            result = wls_guardian_recovery_path_absent(live)
                || wls_guardian_recovery_directory_closure_safe(live) == 0
                ? 0 : 1;
        }
        goto cleanup;
    }
    if (wls_guardian_recovery_path_absent(live)) {
        result = 0;
    } else if (wls_guardian_recovery_root_authority_state(
            live, authority, authority->present
        ) == 0) {
        result = 0;
    } else {
        result = wls_guardian_recovery_directory_closure_safe(live);
    }
cleanup:
    SecureZeroMemory(after_image, sizeof(after_image));
    SecureZeroMemory(absence, sizeof(absence));
    return result;
}

static int wls_guardian_recovery_reconcile_derived_root(
    const wchar_t *live,
    const wchar_t *quarantine,
    const struct wls_guardian_recovery_root_authority *authority
) {
    wchar_t after_image[WLS_PATH_CHARS];
    wchar_t absence[WLS_PATH_CHARS];
    int after_present = 0;
    int absence_present = 0;
    int root_state;
    int result = 1;
    ZeroMemory(after_image, sizeof(after_image));
    ZeroMemory(absence, sizeof(absence));
    if (live == NULL || quarantine == NULL || authority == NULL
        || wls_guardian_recovery_parent_authority_verify(
            live, authority, 1
        ) != 0
        || wls_guardian_recovery_root_markers(
            quarantine,
            after_image,
            absence,
            &after_present,
            &absence_present
        ) != 0) goto cleanup;
    if (authority->contract->preserve_identity) {
        if (!authority->present || after_present || absence_present
            || wls_guardian_recovery_root_authority_state(
                live, authority, 1
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    root_state = wls_guardian_recovery_root_authority_state(
        live, authority, !(after_present || absence_present)
    );
    if (root_state != 0 && authority->present
        && (after_present || absence_present)
        && !wls_guardian_recovery_path_absent(live)) {
        if (wls_guardian_recovery_directory_closure_safe(live) != 0
            || wls_guardian_recovery_authority_apply(
                live, 1, &authority->acl
            ) != 0) goto cleanup;
        root_state = wls_guardian_recovery_root_authority_state(
            live, authority, 0
        );
    }
    if (root_state != 0 && !wls_guardian_recovery_path_absent(live)) {
        if (after_present || absence_present
            || wls_guardian_recovery_directory_closure_safe(live) != 0
            || wls_guardian_recovery_rename_no_replace(
                live, after_image, 1
            ) != 0) goto cleanup;
        after_present = 1;
    }
    if (!authority->present) {
        if (!wls_guardian_recovery_path_absent(live)) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_recovery_path_absent(live)) {
        if (!after_present && !absence_present) {
            if (wls_guardian_recovery_root_only_directory(
                    absence, 1
                ) != 0) goto cleanup;
            absence_present = 1;
        }
        if (wls_guardian_recovery_create_authority_root(
                live, authority
            ) != 0) goto cleanup;
    } else if (after_present || absence_present) {
        if (wls_guardian_recovery_authority_apply(
                live, 1, &authority->acl
            ) != 0) goto cleanup;
    }
    if (wls_guardian_recovery_root_authority_state(
            live, authority, !(after_present || absence_present)
        ) != 0
        || wls_guardian_recovery_parent_authority_verify(
            live, authority, 0
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(after_image, sizeof(after_image));
    SecureZeroMemory(absence, sizeof(absence));
    return result;
}

static int wls_guardian_recovery_directory_names_path(
    const wchar_t *path,
    struct wls_guardian_recovery_child_name **names,
    size_t *count
) {
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL || names == NULL || count == NULL
        || wls_guardian_open_pinned(
            path,
            1,
            GENERIC_READ | FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &pin
        ) != 0
        || wls_guardian_recovery_directory_children(
            &pin, names, count
        ) != 0
        || !wls_guardian_protocol_directory_identity_unchanged(&pin)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&pin);
    return result;
}

static int wls_guardian_recovery_leaf_preserved(
    unsigned int category_index,
    const char *leaf,
    const struct wls_guardian_transition_request *request
) {
    const char *const *values = NULL;
    size_t count = 0U;
    size_t index;
    if (leaf == NULL || request == NULL
        || wls_guardian_recovery_preserved_contract(
            category_index, request, &values, &count
        ) != 0) return 0;
    for (index = 0U; index < count; ++index) {
        if (strcmp(values[index], leaf) == 0) return 1;
    }
    return 0;
}

static int wls_guardian_recovery_stored_root_verify(
    const wchar_t *stored,
    unsigned int category_index,
    struct wls_guardian_recovery_evidence *evidence
) {
    struct wls_guardian_recovery_child_name *names = NULL;
    size_t count = 0U;
    size_t index;
    int result = 1;
    if (stored == NULL || evidence == NULL
        || wls_guardian_recovery_root_only_directory(stored, 0) != 0
        || wls_guardian_recovery_directory_names_path(
            stored, &names, &count
        ) != 0) goto cleanup;
    for (index = 0U; index < count; ++index) {
        struct wls_guardian_recovery_inventory_entry *entry =
            wls_guardian_recovery_inventory_find(
                evidence, category_index, names[index].utf8
            );
        wchar_t path[WLS_PATH_CHARS];
        ZeroMemory(path, sizeof(path));
        if (entry == NULL
            || entry->kind != (names[index].directory ? 'd' : 'f')
            || wls_join(
                path, WLS_PATH_CHARS, stored, names[index].wide
            ) != 0
            || wls_guardian_recovery_closure_verify(
                path, &entry->closure, 2
            ) != 0) goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_recovery_children_free(&names, count);
    return result;
}

static int wls_guardian_recovery_derived_prevalidate(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_evidence *evidence
) {
    unsigned int category_index;
    size_t index;
    if (home == NULL || request == NULL || evidence == NULL) return 1;
    for (category_index = 0U;
        category_index < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT;
        ++category_index) {
        wchar_t live[WLS_PATH_CHARS];
        wchar_t stored[WLS_PATH_CHARS];
        wchar_t quarantine[WLS_PATH_CHARS];
        ZeroMemory(live, sizeof(live));
        ZeroMemory(stored, sizeof(stored));
        ZeroMemory(quarantine, sizeof(quarantine));
        if (wls_guardian_recovery_derived_paths(
                home,
                evidence->backup,
                category_index,
                live,
                stored,
                quarantine
            ) != 0
            || wls_guardian_recovery_stored_root_verify(
                stored, category_index, evidence
            ) != 0
            || wls_guardian_recovery_derived_root_prevalidate(
                live, quarantine, &evidence->roots[category_index]
            ) != 0) return 1;
    }
    for (index = 0U; index < evidence->count; ++index) {
        struct wls_guardian_recovery_inventory_entry *entry =
            &evidence->entries[index];
        const struct wls_guardian_recovery_root_contract *contract =
            &wls_guardian_recovery_root_contracts[entry->category_index];
        wchar_t live_root[WLS_PATH_CHARS];
        wchar_t stored_root[WLS_PATH_CHARS];
        wchar_t quarantine_root[WLS_PATH_CHARS];
        wchar_t live[WLS_PATH_CHARS];
        wchar_t stored[WLS_PATH_CHARS];
        int stored_state;
        int live_state;
        ZeroMemory(live_root, sizeof(live_root));
        ZeroMemory(stored_root, sizeof(stored_root));
        ZeroMemory(quarantine_root, sizeof(quarantine_root));
        ZeroMemory(live, sizeof(live));
        ZeroMemory(stored, sizeof(stored));
        if (wls_guardian_recovery_derived_paths(
                home,
                evidence->backup,
                entry->category_index,
                live_root,
                stored_root,
                quarantine_root
            ) != 0
            || wls_join(
                live, WLS_PATH_CHARS, live_root, entry->leaf_wide
            ) != 0
            || wls_join(
                stored, WLS_PATH_CHARS, stored_root, entry->leaf_wide
            ) != 0) return 1;
        stored_state = wls_guardian_recovery_closure_state(
            stored, &entry->closure, 2
        );
        live_state = wls_guardian_recovery_closure_state(
            live, &entry->closure, 0
        );
        if (stored_state == 0) continue;
        if (stored_state != 2 || strcmp(contract->policy, "restore") != 0
            || live_state != 0) return 1;
    }
    return 0;
}

static int wls_guardian_recovery_derived_root_final_verify(
    const wchar_t *live,
    const wchar_t *quarantine,
    const struct wls_guardian_recovery_root_authority *authority
) {
    wchar_t after_image[WLS_PATH_CHARS];
    wchar_t absence[WLS_PATH_CHARS];
    int after_present = 0;
    int absence_present = 0;
    int result = 1;
    ZeroMemory(after_image, sizeof(after_image));
    ZeroMemory(absence, sizeof(absence));
    if (wls_guardian_recovery_parent_authority_verify(
            live, authority, 0
        ) != 0
        || wls_guardian_recovery_root_markers(
            quarantine,
            after_image,
            absence,
            &after_present,
            &absence_present
        ) != 0) goto cleanup;
    if (!authority->present) {
        result = wls_guardian_recovery_path_absent(live) ? 0 : 1;
        goto cleanup;
    }
    if (authority->contract->preserve_identity
        && (after_present || absence_present)) goto cleanup;
    result = wls_guardian_recovery_root_authority_state(
        live,
        authority,
        authority->contract->preserve_identity
            || !(after_present || absence_present)
    );
cleanup:
    SecureZeroMemory(after_image, sizeof(after_image));
    SecureZeroMemory(absence, sizeof(absence));
    return result;
}

static int wls_guardian_recovery_derived_state_final_verify(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_evidence *evidence
) {
    unsigned int category_index;
    if (home == NULL || request == NULL || evidence == NULL) return 1;
    for (category_index = 0U;
        category_index < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT;
        ++category_index) {
        wchar_t live[WLS_PATH_CHARS];
        wchar_t stored[WLS_PATH_CHARS];
        wchar_t quarantine[WLS_PATH_CHARS];
        struct wls_guardian_recovery_child_name *names = NULL;
        size_t count = 0U;
        size_t index;
        ZeroMemory(live, sizeof(live));
        ZeroMemory(stored, sizeof(stored));
        ZeroMemory(quarantine, sizeof(quarantine));
        if (wls_guardian_recovery_derived_paths(
                home,
                evidence->backup,
                category_index,
                live,
                stored,
                quarantine
            ) != 0
            || wls_guardian_recovery_derived_root_final_verify(
                live, quarantine, &evidence->roots[category_index]
            ) != 0) return 1;
        if (!evidence->roots[category_index].present) {
            for (index = 0U; index < evidence->count; ++index) {
                if (evidence->entries[index].category_index
                    == category_index) return 1;
            }
            continue;
        }
        if (wls_guardian_recovery_directory_names_path(
                live, &names, &count
            ) != 0) return 1;
        for (index = 0U; index < count; ++index) {
            struct wls_guardian_recovery_inventory_entry *entry;
            wchar_t path[WLS_PATH_CHARS];
            ZeroMemory(path, sizeof(path));
            if (wls_guardian_recovery_leaf_preserved(
                    category_index, names[index].utf8, request
                )) continue;
            entry = wls_guardian_recovery_inventory_find(
                evidence, category_index, names[index].utf8
            );
            if (entry == NULL
                || strcmp(
                    wls_guardian_recovery_root_contracts[
                        category_index
                    ].policy,
                    "restore"
                ) != 0
                || wls_join(
                    path, WLS_PATH_CHARS, live, names[index].wide
                ) != 0
                || wls_guardian_recovery_closure_verify(
                    path, &entry->closure, 1
                ) != 0) {
                wls_guardian_recovery_children_free(&names, count);
                return 1;
            }
        }
        wls_guardian_recovery_children_free(&names, count);
        names = NULL;
        count = 0U;
        for (index = 0U; index < evidence->count; ++index) {
            struct wls_guardian_recovery_inventory_entry *entry =
                &evidence->entries[index];
            wchar_t path[WLS_PATH_CHARS];
            int state;
            if (entry->category_index != category_index) continue;
            ZeroMemory(path, sizeof(path));
            if (wls_join(
                    path, WLS_PATH_CHARS, live, entry->leaf_wide
                ) != 0) return 1;
            state = wls_guardian_recovery_closure_state(
                path, &entry->closure, 1
            );
            if (strcmp(
                    wls_guardian_recovery_root_contracts[
                        category_index
                    ].policy,
                    "ephemeral"
                ) == 0) {
                if (state != 2) return 1;
            } else if (state != 0) {
                return 1;
            }
        }
    }
    return 0;
}

static int wls_guardian_recovery_restore_derived_category(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_evidence *evidence,
    unsigned int category_index
) {
    wchar_t live_root[WLS_PATH_CHARS];
    wchar_t stored_root[WLS_PATH_CHARS];
    wchar_t quarantine_root[WLS_PATH_CHARS];
    wchar_t new_derived[WLS_PATH_CHARS];
    struct wls_guardian_recovery_child_name *names = NULL;
    size_t count = 0U;
    size_t index;
    const struct wls_guardian_recovery_root_contract *contract;
    int result = 1;
    ZeroMemory(live_root, sizeof(live_root));
    ZeroMemory(stored_root, sizeof(stored_root));
    ZeroMemory(quarantine_root, sizeof(quarantine_root));
    ZeroMemory(new_derived, sizeof(new_derived));
    if (home == NULL || request == NULL || evidence == NULL
        || category_index >= WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT) {
        return 1;
    }
    contract = &wls_guardian_recovery_root_contracts[category_index];
    if (wls_guardian_recovery_derived_paths(
            home,
            evidence->backup,
            category_index,
            live_root,
            stored_root,
            quarantine_root
        ) != 0
        || wls_join(
            new_derived,
            WLS_PATH_CHARS,
            evidence->backup,
            L"new-derived"
        ) != 0
        || wls_guardian_recovery_root_only_directory(
            stored_root, 0
        ) != 0
        || wls_guardian_recovery_root_only_directory(
            new_derived, 1
        ) != 0
        || wls_guardian_recovery_root_only_directory(
            quarantine_root, 1
        ) != 0
        || wls_guardian_recovery_reconcile_derived_root(
            live_root,
            quarantine_root,
            &evidence->roots[category_index]
        ) != 0) goto cleanup;
    if (!evidence->roots[category_index].present) {
        if (wls_guardian_recovery_directory_names_path(
                stored_root, &names, &count
            ) != 0
            || count != 0U) goto cleanup;
        for (index = 0U; index < evidence->count; ++index) {
            if (evidence->entries[index].category_index
                == category_index) goto cleanup;
        }
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_recovery_directory_names_path(
            live_root, &names, &count
        ) != 0) goto cleanup;
    for (index = 0U; index < count; ++index) {
        struct wls_guardian_recovery_inventory_entry *entry;
        wchar_t live[WLS_PATH_CHARS];
        wchar_t stored[WLS_PATH_CHARS];
        wchar_t quarantine[WLS_PATH_CHARS];
        int stored_state;
        int live_content_state;
        ZeroMemory(live, sizeof(live));
        ZeroMemory(stored, sizeof(stored));
        ZeroMemory(quarantine, sizeof(quarantine));
        if (wls_guardian_recovery_leaf_preserved(
                category_index, names[index].utf8, request
            )) continue;
        entry = wls_guardian_recovery_inventory_find(
            evidence, category_index, names[index].utf8
        );
        if (wls_join(
                live, WLS_PATH_CHARS, live_root, names[index].wide
            ) != 0
            || wls_join(
                stored, WLS_PATH_CHARS, stored_root, names[index].wide
            ) != 0
            || wls_join(
                quarantine,
                WLS_PATH_CHARS,
                quarantine_root,
                names[index].wide
            ) != 0) goto cleanup;
        stored_state = entry == NULL ? 2
            : wls_guardian_recovery_closure_state(
                stored, &entry->closure, 2
            );
        live_content_state = entry == NULL ? 1
            : wls_guardian_recovery_closure_state(
                live, &entry->closure, 0
            );
        if (entry != NULL && strcmp(contract->policy, "restore") == 0
            && stored_state == 2 && live_content_state == 0) {
            continue;
        }
        if (!wls_guardian_recovery_path_absent(quarantine)
            || wls_guardian_recovery_closure_safe(live) != 0
            || wls_guardian_recovery_rename_no_replace(
                live, quarantine, names[index].directory
            ) != 0) goto cleanup;
    }
    wls_guardian_recovery_children_free(&names, count);
    names = NULL;
    count = 0U;
    for (index = 0U; index < evidence->count; ++index) {
        struct wls_guardian_recovery_inventory_entry *entry =
            &evidence->entries[index];
        wchar_t live[WLS_PATH_CHARS];
        wchar_t stored[WLS_PATH_CHARS];
        int stored_state;
        int live_state;
        if (entry->category_index != category_index) continue;
        ZeroMemory(live, sizeof(live));
        ZeroMemory(stored, sizeof(stored));
        if (wls_join(
                live, WLS_PATH_CHARS, live_root, entry->leaf_wide
            ) != 0
            || wls_join(
                stored, WLS_PATH_CHARS, stored_root, entry->leaf_wide
            ) != 0) goto cleanup;
        stored_state = wls_guardian_recovery_closure_state(
            stored, &entry->closure, 2
        );
        live_state = wls_guardian_recovery_closure_state(
            live, &entry->closure, 0
        );
        if (strcmp(contract->policy, "ephemeral") == 0) {
            if (stored_state != 0 || live_state != 2) goto cleanup;
            continue;
        }
        if (stored_state == 0) {
            if (live_state != 2
                || wls_guardian_recovery_rename_no_replace(
                    stored, live, entry->kind == 'd'
                ) != 0) goto cleanup;
        } else if (stored_state != 2 || live_state != 0) {
            goto cleanup;
        }
        if (wls_guardian_recovery_closure_apply_acl(
                live, &entry->closure
            ) != 0) goto cleanup;
    }
    if (wls_guardian_recovery_derived_root_final_verify(
            live_root,
            quarantine_root,
            &evidence->roots[category_index]
        ) != 0
        || wls_guardian_recovery_directory_names_path(
            live_root, &names, &count
        ) != 0) goto cleanup;
    for (index = 0U; index < count; ++index) {
        struct wls_guardian_recovery_inventory_entry *entry;
        if (wls_guardian_recovery_leaf_preserved(
                category_index, names[index].utf8, request
            )) continue;
        entry = wls_guardian_recovery_inventory_find(
            evidence, category_index, names[index].utf8
        );
        if (entry == NULL || strcmp(contract->policy, "restore") != 0) {
            goto cleanup;
        }
    }
    result = 0;
cleanup:
    wls_guardian_recovery_children_free(&names, count);
    SecureZeroMemory(live_root, sizeof(live_root));
    SecureZeroMemory(stored_root, sizeof(stored_root));
    SecureZeroMemory(quarantine_root, sizeof(quarantine_root));
    SecureZeroMemory(new_derived, sizeof(new_derived));
    return result;
}

/* 0=ordinary slot node, 1=nginx executable, 2=slot traversal directory. */
static int wls_guardian_recovery_slot_data_plane_role(
    const wchar_t *relative,
    char kind
) {
    if (relative == NULL || (kind != 'd' && kind != 'f')) return -1;
    if (kind == 'd'
        && (wcscmp(relative, L".") == 0
            || wls_guardian_recovery_wide_equal_folded(
                relative, L"bin"
            ))) return 2;
    if (kind == 'f' && wls_guardian_recovery_wide_equal_folded(
            relative, L"bin\\nginx.exe"
        )) return 1;
    return 0;
}

static int wls_guardian_recovery_fixed_controller_acl_apply(
    const wchar_t *path,
    int directory,
    int data_plane_role
) {
    char sddl[1024];
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PSID owner = NULL;
    PACL dacl = NULL;
    BOOL owner_defaulted = FALSE;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    PSID service_sid = NULL;
    struct wls_guardian_pinned_file pin;
    struct wls_guardian_privilege_scope privilege;
    int privilege_enabled = 0;
    int length;
    int result = 1;
    SecureZeroMemory(sddl, sizeof(sddl));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    SecureZeroMemory(&privilege, sizeof(privilege));
    if (path == NULL || (directory != 0 && directory != 1)
        || data_plane_role < 0 || data_plane_role > 2
        || (data_plane_role == 1 && directory)
        || (data_plane_role == 2 && !directory)) return 1;
    if (data_plane_role == 2) {
        length = _snprintf_s(
            sddl,
            sizeof(sddl),
            _TRUNCATE,
            "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)"
                "(A;;0x001200a9;;;%s)(A;;0x00000020;;;%s)",
            WLS_GUARDIAN_CONTROLLER_SID,
            WLS_GUARDIAN_DATA_PLANE_SID
        );
    } else if (directory) {
        length = _snprintf_s(
            sddl,
            sizeof(sddl),
            _TRUNCATE,
            "O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)"
                "(A;OICI;0x001200a9;;;%s)",
            WLS_GUARDIAN_CONTROLLER_SID
        );
    } else if (data_plane_role == 1) {
        length = _snprintf_s(
            sddl,
            sizeof(sddl),
            _TRUNCATE,
            "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)"
                "(A;;0x001200a9;;;%s)(A;;0x001200a9;;;%s)",
            WLS_GUARDIAN_CONTROLLER_SID,
            WLS_GUARDIAN_DATA_PLANE_SID
        );
    } else {
        length = _snprintf_s(
            sddl,
            sizeof(sddl),
            _TRUNCATE,
            "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)"
                "(A;;0x001200a9;;;%s)",
            WLS_GUARDIAN_CONTROLLER_SID
        );
    }
    if (length <= 0 || length >= (int)sizeof(sddl)
        || !ConvertStringSecurityDescriptorToSecurityDescriptorA(
            sddl, SDDL_REVISION_1, &descriptor, NULL
        )
        || !GetSecurityDescriptorOwner(
            descriptor, &owner, &owner_defaulted
        )
        || owner == NULL || owner_defaulted
        || !GetSecurityDescriptorDacl(
            descriptor, &dacl_present, &dacl, &dacl_defaulted
        )
        || !dacl_present || dacl == NULL || dacl_defaulted
        || wls_guardian_open_pinned(
            path,
            directory,
            GENERIC_READ | READ_CONTROL | WRITE_DAC | WRITE_OWNER
                | (directory ? FILE_TRAVERSE | FILE_LIST_DIRECTORY : 0U),
            &pin
        ) != 0
        || wls_guardian_privilege_enable_restore(&privilege) != 0) {
        goto cleanup;
    }
    privilege_enabled = 1;
    if (SetSecurityInfo(
            pin.handle,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION
                | PROTECTED_DACL_SECURITY_INFORMATION,
            owner,
            NULL,
            dacl,
            NULL
        ) != ERROR_SUCCESS
        || wls_launcher_gateway_service_sid(&service_sid) != 0
        || (data_plane_role == 1
            ? wls_launcher_nginx_acl_valid(pin.handle, service_sid)
            : data_plane_role == 2
                ? wls_launcher_slot_data_plane_directory_acl_valid(
                    pin.handle, service_sid
                )
            : wls_launcher_slot_acl_valid(
                pin.handle, directory, service_sid
            )) != 0
        || !(directory
            ? wls_guardian_protocol_directory_identity_unchanged(&pin)
            : wls_guardian_pinned_identity_unchanged(&pin, 0))) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    if (privilege_enabled) {
        if (wls_guardian_privilege_restore(&privilege) != 0) result = 1;
    } else {
        SecureZeroMemory(&privilege, sizeof(privilege));
    }
    wls_guardian_pinned_close(&pin);
    if (descriptor != NULL) LocalFree(descriptor);
    SecureZeroMemory(sddl, sizeof(sddl));
    return result;
}

static int wls_guardian_recovery_tree_acl_safe(
    const wchar_t *path,
    char kind,
    int controller
) {
    struct wls_guardian_recovery_walk walk;
    PSID service_sid = NULL;
    size_t index;
    int result = 1;
    SecureZeroMemory(&walk, sizeof(walk));
    if (path == NULL || (kind != 'd' && kind != 'f')
        || wls_guardian_recovery_walk_collect(
            path, ".", L".", kind, 0U, &walk
        ) != 0
        || (controller
            && wls_launcher_gateway_service_sid(&service_sid) != 0)) {
        goto cleanup;
    }
    for (index = 0U; index < walk.count; ++index) {
        wchar_t item_path[WLS_PATH_CHARS];
        struct wls_guardian_pinned_file pin;
        int data_plane_role;
        ZeroMemory(item_path, sizeof(item_path));
        ZeroMemory(&pin, sizeof(pin));
        pin.handle = INVALID_HANDLE_VALUE;
        data_plane_role = controller
            ? wls_guardian_recovery_slot_data_plane_role(
                walk.items[index].relative_wide,
                walk.items[index].kind
            ) : 0;
        if (data_plane_role < 0) goto cleanup;
        if (wls_guardian_recovery_record_path(
                path, walk.items[index].relative_wide, item_path
            ) != 0
            || wls_guardian_open_pinned(
                item_path,
                walk.items[index].kind == 'd',
                GENERIC_READ | READ_CONTROL
                    | (walk.items[index].kind == 'd'
                        ? FILE_TRAVERSE | FILE_LIST_DIRECTORY : 0U),
                &pin
            ) != 0
            || (controller
                ? data_plane_role == 1
                    ? wls_launcher_nginx_acl_valid(
                        pin.handle, service_sid
                    )
                    : data_plane_role == 2
                        ? wls_launcher_slot_data_plane_directory_acl_valid(
                            pin.handle, service_sid
                        )
                    : wls_launcher_slot_acl_valid(
                        pin.handle,
                        walk.items[index].kind == 'd',
                        service_sid
                    )
                : wls_recovery_acl_safe(pin.handle, 0)) != 0) {
            wls_guardian_pinned_close(&pin);
            goto cleanup;
        }
        wls_guardian_pinned_close(&pin);
    }
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    wls_guardian_recovery_walk_free(&walk);
    return result;
}

static int wls_guardian_recovery_slot_acl_transition_role_valid(
    const wchar_t *relative,
    char kind,
    int root_only,
    int controller,
    int data_plane_file,
    int data_plane_directory
) {
    int profile_count = (root_only ? 1 : 0)
        + (controller ? 1 : 0) + (data_plane_file ? 1 : 0)
        + (data_plane_directory ? 1 : 0);
    int expected_role;
    if (relative == NULL || (kind != 'd' && kind != 'f')
        || profile_count != 1) return 0;
    expected_role = wls_guardian_recovery_slot_data_plane_role(
        relative, kind
    );
    if (expected_role < 0) return 0;
    if (wcscmp(relative, L".") == 0) {
        return kind == 'd' && (root_only || data_plane_directory);
    }
    if (expected_role == 2) return root_only || data_plane_directory;
    if (expected_role == 1) return root_only || data_plane_file;
    return !data_plane_file && !data_plane_directory
        && (root_only || controller);
}

/* A crash during ACL conversion is recoverable while the slot root is either
 * exact root-only or its exact non-writing final traverse profile. Every
 * descendant must be in either its exact sealed before-profile or its exact
 * final role-profile; no other mixture is accepted. The root handle pins the
 * namespace identity for the full walk. */
static int wls_guardian_recovery_slot_acl_transition_safe(
    const wchar_t *path
) {
    struct wls_guardian_recovery_walk walk;
    struct wls_guardian_pinned_file root_pin;
    PSID service_sid = NULL;
    size_t index;
    int converted_seen = 0;
    int result = 1;
    SecureZeroMemory(&walk, sizeof(walk));
    ZeroMemory(&root_pin, sizeof(root_pin));
    root_pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL
        || wls_guardian_open_pinned(
            path,
            1,
            GENERIC_READ | READ_CONTROL | FILE_TRAVERSE
                | FILE_LIST_DIRECTORY,
            &root_pin
        ) != 0
        || wls_launcher_gateway_service_sid(&service_sid) != 0
        || (wls_recovery_acl_safe(root_pin.handle, 0) != 0
            && wls_launcher_slot_data_plane_directory_acl_valid(
                root_pin.handle, service_sid
            ) != 0)
        || wls_guardian_recovery_directory_closure_safe(path) != 0
        || wls_guardian_recovery_walk_collect(
            path, ".", L".", 'd', 0U, &walk
        ) != 0
        || walk.count == 0U
        || walk.volume != root_pin.file_id.VolumeSerialNumber) {
        goto cleanup;
    }
    for (index = 0U; index < walk.count; ++index) {
        wchar_t item_path[WLS_PATH_CHARS];
        struct wls_guardian_pinned_file pin;
        int directory = walk.items[index].kind == 'd';
        int root_only;
        int controller;
        int data_plane_file;
        int data_plane_directory;
        ZeroMemory(item_path, sizeof(item_path));
        ZeroMemory(&pin, sizeof(pin));
        pin.handle = INVALID_HANDLE_VALUE;
        if (wls_guardian_recovery_record_path(
                path, walk.items[index].relative_wide, item_path
            ) != 0
            || wls_guardian_open_pinned(
                item_path,
                directory,
                GENERIC_READ | READ_CONTROL
                    | (directory
                        ? FILE_TRAVERSE | FILE_LIST_DIRECTORY : 0U),
                &pin
            ) != 0
            || pin.file_id.VolumeSerialNumber != walk.volume) {
            wls_guardian_pinned_close(&pin);
            goto cleanup;
        }
        root_only = wls_recovery_acl_safe(pin.handle, 0) == 0;
        controller = wls_launcher_slot_acl_valid(
            pin.handle, directory, service_sid
        ) == 0;
        data_plane_file = !directory && wls_launcher_nginx_acl_valid(
            pin.handle, service_sid
        ) == 0;
        data_plane_directory = directory
            && wls_launcher_slot_data_plane_directory_acl_valid(
                pin.handle, service_sid
            ) == 0;
        if (!wls_guardian_recovery_slot_acl_transition_role_valid(
                walk.items[index].relative_wide,
                walk.items[index].kind,
                root_only,
                controller,
                data_plane_file,
                data_plane_directory
            )
            || (wcscmp(walk.items[index].relative_wide, L".") == 0
                && !wls_guardian_recovery_file_id_equal(
                    &pin.file_id, &root_pin.file_id
                ))
            || !(directory
                ? wls_guardian_protocol_directory_identity_unchanged(&pin)
                : wls_guardian_pinned_identity_unchanged(&pin, 0))) {
            wls_guardian_pinned_close(&pin);
            goto cleanup;
        }
        if (controller || data_plane_file || data_plane_directory) {
            converted_seen = 1;
        }
        wls_guardian_pinned_close(&pin);
    }
    if (!converted_seen
        || !wls_guardian_protocol_directory_identity_unchanged(&root_pin)
        || wls_guardian_recovery_directory_closure_safe(path) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (service_sid != NULL) LocalFree(service_sid);
    wls_guardian_pinned_close(&root_pin);
    wls_guardian_recovery_walk_free(&walk);
    return result;
}

static int wls_guardian_recovery_tree_controller_acl_apply(
    const wchar_t *path,
    char kind
) {
    struct wls_guardian_recovery_walk walk;
    size_t index;
    int result = 1;
    SecureZeroMemory(&walk, sizeof(walk));
    if (path == NULL || (kind != 'd' && kind != 'f')
        || wls_guardian_recovery_walk_collect(
            path, ".", L".", kind, 0U, &walk
        ) != 0) goto cleanup;
    for (index = walk.count; index > 0U; --index) {
        wchar_t item_path[WLS_PATH_CHARS];
        int data_plane_role =
            wls_guardian_recovery_slot_data_plane_role(
                walk.items[index - 1U].relative_wide,
                walk.items[index - 1U].kind
            );
        ZeroMemory(item_path, sizeof(item_path));
        if (data_plane_role < 0
            || wls_guardian_recovery_record_path(
                path,
                walk.items[index - 1U].relative_wide,
                item_path
            ) != 0
            || wls_guardian_recovery_fixed_controller_acl_apply(
                item_path,
                walk.items[index - 1U].kind == 'd',
                data_plane_role
            ) != 0) goto cleanup;
    }
    result = wls_guardian_recovery_tree_acl_safe(path, kind, 1);
cleanup:
    wls_guardian_recovery_walk_free(&walk);
    return result;
}

static int wls_guardian_recovery_runtime_paths(
    const wchar_t *home,
    const wchar_t *backup,
    const char *nonce,
    wchar_t candidate[WLS_PATH_CHARS],
    wchar_t new_generation[WLS_PATH_CHARS],
    wchar_t new_slots[WLS_PATH_CHARS]
) {
    wchar_t candidates[WLS_PATH_CHARS];
    wchar_t nonce_wide[33];
    int result = 1;
    ZeroMemory(candidates, sizeof(candidates));
    ZeroMemory(nonce_wide, sizeof(nonce_wide));
    if (home == NULL || backup == NULL || nonce == NULL
        || candidate == NULL || new_generation == NULL || new_slots == NULL
        || !wls_is_hex(nonce, 32U)
        || wls_utf8_to_wide(nonce, nonce_wide, 33U) != 0
        || wls_join(
            candidates,
            WLS_PATH_CHARS,
            home,
            L"rebootstrap\\candidates"
        ) != 0
        || wls_join(
            candidate, WLS_PATH_CHARS, candidates, nonce_wide
        ) != 0
        || wls_join(
            new_generation,
            WLS_PATH_CHARS,
            backup,
            L"new-generation"
        ) != 0
        || wls_join(
            new_slots, WLS_PATH_CHARS, new_generation, L"slots"
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(candidates, sizeof(candidates));
    SecureZeroMemory(nonce_wide, sizeof(nonce_wide));
    return result;
}

/* 0=exact signed slot, 2=absent, 1=unsafe/mismatch. */
static int wls_guardian_recovery_slot_state(
    const wchar_t *base,
    wchar_t slot,
    const char *runtime_generation,
    const char *ca_sha256
) {
    wchar_t slot_name[2];
    wchar_t slots[WLS_PATH_CHARS];
    wchar_t slot_path[WLS_PATH_CHARS];
    wchar_t ca_path[WLS_PATH_CHARS];
    char observed_generation[65];
    int result = 1;
    ZeroMemory(slot_name, sizeof(slot_name));
    ZeroMemory(slots, sizeof(slots));
    ZeroMemory(slot_path, sizeof(slot_path));
    ZeroMemory(ca_path, sizeof(ca_path));
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    if (base == NULL || (slot != L'A' && slot != L'B')
        || runtime_generation == NULL || ca_sha256 == NULL
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(ca_sha256, 64U)) goto cleanup;
    slot_name[0] = slot;
    if (wls_join(slots, WLS_PATH_CHARS, base, L"slots") != 0
        || wls_join(
            slot_path, WLS_PATH_CHARS, slots, slot_name
        ) != 0) goto cleanup;
    if (wls_guardian_recovery_path_absent(slot_path)) {
        result = 2;
        goto cleanup;
    }
    if (wls_verify_slot_durable_state_contract_v2(
            base, slot, observed_generation
        ) != 0
        || sodium_memcmp(
            observed_generation, runtime_generation, 64U
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
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(slot_name, sizeof(slot_name));
    SecureZeroMemory(slots, sizeof(slots));
    SecureZeroMemory(slot_path, sizeof(slot_path));
    SecureZeroMemory(ca_path, sizeof(ca_path));
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    return result;
}

static int wls_guardian_recovery_text_digest(
    const char *text,
    char digest[65],
    unsigned long long *size
) {
    size_t length;
    if (text == NULL || digest == NULL || size == NULL) return 1;
    length = strlen(text);
    if (length == 0U
        || wls_guardian_sha256(
            (const unsigned char *)text, length, digest
        ) != 0) return 1;
    *size = (unsigned long long)length;
    return 0;
}

/* acl: 0=root-only, 1=controller-readable. 0=exact, 2=absent, 1=bad. */
static int wls_guardian_recovery_regular_state(
    const wchar_t *path,
    const char *digest,
    unsigned long long size,
    unsigned long long maximum,
    int controller
) {
    if (path == NULL || digest == NULL || size == 0ULL || size > maximum) {
        return 1;
    }
    if (wls_guardian_recovery_path_absent(path)) return 2;
    return wls_guardian_regular_digest_with_acl(
        path, maximum, controller, digest, size
    ) == 0 ? 0 : 1;
}

static int wls_guardian_recovery_candidate_pointer_state(
    const wchar_t *path
) {
    static const char *const values[] = {"A\n", "B\n"};
    char digest[65];
    unsigned long long size = 0ULL;
    size_t index;
    int result = 1;
    SecureZeroMemory(digest, sizeof(digest));
    if (wls_guardian_recovery_path_absent(path)) return 2;
    for (index = 0U; index < sizeof(values) / sizeof(values[0]); ++index) {
        if (wls_guardian_recovery_text_digest(
                values[index], digest, &size
            ) == 0
            && wls_guardian_recovery_regular_state(
                path, digest, size, 2ULL, 1
            ) == 0) {
            result = 0;
            break;
        }
    }
    SecureZeroMemory(digest, sizeof(digest));
    return result;
}

static int wls_guardian_recovery_runtime_prevalidate(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request,
    const wchar_t *backup
) {
    wchar_t candidate[WLS_PATH_CHARS];
    wchar_t new_generation[WLS_PATH_CHARS];
    wchar_t new_slots[WLS_PATH_CHARS];
    wchar_t backup_slots[WLS_PATH_CHARS];
    wchar_t live_slots[WLS_PATH_CHARS];
    wchar_t path[WLS_PATH_CHARS];
    wchar_t target[WLS_PATH_CHARS];
    wchar_t ca_path[WLS_PATH_CHARS];
    char text[80];
    char digest[65];
    char observed_generation[65];
    unsigned long long size = 0ULL;
    const wchar_t slots[] = {L'A', L'B'};
    size_t index;
    int result = 1;
    ZeroMemory(candidate, sizeof(candidate));
    ZeroMemory(new_generation, sizeof(new_generation));
    ZeroMemory(new_slots, sizeof(new_slots));
    ZeroMemory(backup_slots, sizeof(backup_slots));
    ZeroMemory(live_slots, sizeof(live_slots));
    ZeroMemory(path, sizeof(path));
    ZeroMemory(target, sizeof(target));
    ZeroMemory(ca_path, sizeof(ca_path));
    SecureZeroMemory(text, sizeof(text));
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    if (home == NULL || request == NULL || backup == NULL
        || wls_guardian_recovery_runtime_paths(
            home,
            backup,
            request->nonce,
            candidate,
            new_generation,
            new_slots
        ) != 0
        || wls_join(
            backup_slots, WLS_PATH_CHARS, backup, L"slots"
        ) != 0
        || wls_join(live_slots, WLS_PATH_CHARS, home, L"slots") != 0
        || wls_guardian_recovery_root_only_directory(
            backup_slots, 0
        ) != 0) goto cleanup;
    if (!wls_guardian_recovery_path_absent(new_generation)
        && wls_guardian_recovery_root_only_directory(
            new_generation, 0
        ) != 0) goto cleanup;
    if (!wls_guardian_recovery_path_absent(candidate)
        && wls_guardian_recovery_directory_closure_safe(
            candidate
        ) != 0) goto cleanup;
    for (index = 0U; index < sizeof(slots) / sizeof(slots[0]); ++index) {
        wchar_t slot_name[2] = {slots[index], L'\0'};
        const char *old_generation = slots[index] == L'A'
            ? request->recovery_slot_a_generation
            : request->recovery_slot_b_generation;
        ZeroMemory(path, sizeof(path));
        ZeroMemory(target, sizeof(target));
        if (wls_join(
                path, WLS_PATH_CHARS, backup_slots, slot_name
            ) != 0
            || wls_join(
                target, WLS_PATH_CHARS, live_slots, slot_name
            ) != 0) goto cleanup;
        if (wls_all_zero_hex(old_generation)) {
            if (!wls_guardian_recovery_path_absent(path)) goto cleanup;
            if (slots[index] == L'B'
                && !wls_guardian_recovery_path_absent(target)) goto cleanup;
            continue;
        }
        if (!wls_guardian_recovery_path_absent(path)) {
            int controller_acl;
            if (wls_guardian_recovery_directory_closure_safe(path) != 0) {
                goto cleanup;
            }
            if (wls_guardian_recovery_tree_acl_safe(path, 'd', 0) == 0) {
                controller_acl = 0;
            } else if (wls_guardian_recovery_tree_acl_safe(
                    path, 'd', 1
                ) == 0) {
                controller_acl = 1;
            } else if (wls_guardian_recovery_slot_acl_transition_safe(
                    path
                ) == 0) {
                controller_acl = 2;
            } else {
                goto cleanup;
            }
            if (controller_acl == 2) continue;
            SecureZeroMemory(
                observed_generation, sizeof(observed_generation)
            );
            ZeroMemory(ca_path, sizeof(ca_path));
            if (wls_verify_slot_durable_state_contract_v2_at_path(
                    path,
                    slots[index],
                    controller_acl,
                    observed_generation
                ) != 0
                || sodium_memcmp(
                    observed_generation, old_generation, 64U
                ) != 0
                || wls_join(
                    ca_path,
                    WLS_PATH_CHARS,
                    path,
                    L"share\\ca-bundle.pem"
                ) != 0
                || wls_guardian_regular_digest_with_acl(
                    ca_path,
                    4ULL * 1024ULL * 1024ULL,
                    controller_acl,
                    request->recovery_ca_sha256,
                    0ULL
                ) != 0) {
                goto cleanup;
            }
        } else if (wls_guardian_recovery_slot_state(
                home,
                slots[index],
                old_generation,
                request->recovery_ca_sha256
            ) != 0) {
            goto cleanup;
        }
    }
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(target, sizeof(target));
    if (wls_join(path, WLS_PATH_CHARS, backup, L"bin\\launcher") != 0
        || wls_join(
            target,
            WLS_PATH_CHARS,
            home,
            L"bin\\wls-gateway-launcher.exe"
        ) != 0
        || (wls_guardian_recovery_regular_state(
                path,
                request->recovery_launcher_sha256,
                request->recovery_launcher_size,
                WLS_GUARDIAN_LAUNCHER_MAX_BYTES,
                0
            ) != 0
            && wls_guardian_recovery_regular_state(
                path,
                request->recovery_launcher_sha256,
                request->recovery_launcher_size,
                WLS_GUARDIAN_LAUNCHER_MAX_BYTES,
                1
            ) != 0
            && wls_guardian_recovery_regular_state(
                target,
                request->recovery_launcher_sha256,
                request->recovery_launcher_size,
                WLS_GUARDIAN_LAUNCHER_MAX_BYTES,
                1
            ) != 0)) goto cleanup;
    if (_snprintf_s(
            text,
            sizeof(text),
            _TRUNCATE,
            "%s\n",
            request->recovery_launcher_sha256
        ) <= 0
        || wls_guardian_recovery_text_digest(text, digest, &size) != 0
        || wls_join(
            path,
            WLS_PATH_CHARS,
            backup,
            L"trust\\stable-launcher.sha256"
        ) != 0
        || wls_join(
            target,
            WLS_PATH_CHARS,
            home,
            L"trust\\stable-launcher.sha256"
        ) != 0
        || (wls_guardian_recovery_regular_state(
                path, digest, size, 65ULL, 0
            ) != 0
            && wls_guardian_recovery_regular_state(
                path, digest, size, 65ULL, 1
            ) != 0
            && wls_guardian_recovery_regular_state(
                target, digest, size, 65ULL, 1
            ) != 0)) goto cleanup;
    if (_snprintf_s(
            text,
            sizeof(text),
            _TRUNCATE,
            "%s\n",
            request->recovery_active_slot
        ) <= 0
        || wls_guardian_recovery_text_digest(text, digest, &size) != 0
        || wls_join(
            path, WLS_PATH_CHARS, backup, L"trust\\active-slot"
        ) != 0
        || wls_join(
            target, WLS_PATH_CHARS, home, L"trust\\active-slot"
        ) != 0
        || (wls_guardian_recovery_regular_state(
                path, digest, size, 2ULL, 0
            ) != 0
            && wls_guardian_recovery_regular_state(
                path, digest, size, 2ULL, 1
            ) != 0
            && wls_guardian_recovery_regular_state(
                target, digest, size, 2ULL, 1
            ) != 0)) goto cleanup;
    if (wls_join(
            path, WLS_PATH_CHARS, backup, L"trust\\previous-slot"
        ) != 0
        || wls_join(
            target, WLS_PATH_CHARS, home, L"trust\\previous-slot"
        ) != 0) goto cleanup;
    if (strcmp(request->recovery_previous_slot, "NONE") == 0) {
        if (!wls_guardian_recovery_path_absent(path)) goto cleanup;
    } else {
        if (_snprintf_s(
                text,
                sizeof(text),
                _TRUNCATE,
                "%s\n",
                request->recovery_previous_slot
            ) <= 0
            || wls_guardian_recovery_text_digest(
                text, digest, &size
            ) != 0
            || (wls_guardian_recovery_regular_state(
                    path, digest, size, 2ULL, 0
                ) != 0
                && wls_guardian_recovery_regular_state(
                    path, digest, size, 2ULL, 1
                ) != 0
                && wls_guardian_recovery_regular_state(
                    target, digest, size, 2ULL, 1
                ) != 0)) goto cleanup;
    }
    result = 0;
cleanup:
    SecureZeroMemory(candidate, sizeof(candidate));
    SecureZeroMemory(new_generation, sizeof(new_generation));
    SecureZeroMemory(new_slots, sizeof(new_slots));
    SecureZeroMemory(backup_slots, sizeof(backup_slots));
    SecureZeroMemory(live_slots, sizeof(live_slots));
    SecureZeroMemory(path, sizeof(path));
    SecureZeroMemory(target, sizeof(target));
    SecureZeroMemory(ca_path, sizeof(ca_path));
    SecureZeroMemory(text, sizeof(text));
    SecureZeroMemory(digest, sizeof(digest));
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    return result;
}

static int wls_guardian_recovery_restore_candidate_directory(
    const wchar_t *home,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request
) {
    wchar_t candidate[WLS_PATH_CHARS];
    wchar_t new_generation[WLS_PATH_CHARS];
    wchar_t new_slots[WLS_PATH_CHARS];
    wchar_t quarantine[WLS_PATH_CHARS];
    int source_absent;
    int target_absent;
    int result = 1;
    ZeroMemory(candidate, sizeof(candidate));
    ZeroMemory(new_generation, sizeof(new_generation));
    ZeroMemory(new_slots, sizeof(new_slots));
    ZeroMemory(quarantine, sizeof(quarantine));
    if (wls_guardian_recovery_runtime_paths(
            home,
            backup,
            request->nonce,
            candidate,
            new_generation,
            new_slots
        ) != 0
        || wls_join(
            quarantine,
            WLS_PATH_CHARS,
            new_generation,
            L"candidate"
        ) != 0
        || wls_guardian_recovery_root_only_directory(
            new_generation, 1
        ) != 0) goto cleanup;
    source_absent = wls_guardian_recovery_path_absent(candidate);
    target_absent = wls_guardian_recovery_path_absent(quarantine);
    if (source_absent) {
        if (!target_absent
            && wls_guardian_recovery_directory_closure_safe(
                quarantine
            ) != 0) {
            goto cleanup;
        }
        result = 0;
        goto cleanup;
    }
    if (!target_absent
        || wls_guardian_recovery_directory_closure_safe(candidate) != 0
        || wls_guardian_recovery_rename_no_replace(
            candidate, quarantine, 1
        ) != 0
        || wls_guardian_recovery_directory_closure_safe(quarantine) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    SecureZeroMemory(candidate, sizeof(candidate));
    SecureZeroMemory(new_generation, sizeof(new_generation));
    SecureZeroMemory(new_slots, sizeof(new_slots));
    SecureZeroMemory(quarantine, sizeof(quarantine));
    return result;
}

static int wls_guardian_recovery_restore_slot(
    const wchar_t *home,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request,
    wchar_t slot
) {
    wchar_t candidate[WLS_PATH_CHARS];
    wchar_t new_generation[WLS_PATH_CHARS];
    wchar_t new_slots[WLS_PATH_CHARS];
    wchar_t backup_slots[WLS_PATH_CHARS];
    wchar_t live_slots[WLS_PATH_CHARS];
    wchar_t slot_name[2];
    wchar_t stored[WLS_PATH_CHARS];
    wchar_t live[WLS_PATH_CHARS];
    wchar_t quarantine[WLS_PATH_CHARS];
    wchar_t ca_path[WLS_PATH_CHARS];
    const char *old_generation;
    char observed_generation[65];
    int stored_absent;
    int stored_controller_acl = 0;
    int live_old_state;
    int live_candidate_state;
    int quarantine_candidate_state;
    int result = 1;
    ZeroMemory(candidate, sizeof(candidate));
    ZeroMemory(new_generation, sizeof(new_generation));
    ZeroMemory(new_slots, sizeof(new_slots));
    ZeroMemory(backup_slots, sizeof(backup_slots));
    ZeroMemory(live_slots, sizeof(live_slots));
    ZeroMemory(slot_name, sizeof(slot_name));
    ZeroMemory(stored, sizeof(stored));
    ZeroMemory(live, sizeof(live));
    ZeroMemory(quarantine, sizeof(quarantine));
    ZeroMemory(ca_path, sizeof(ca_path));
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    if ((slot != L'A' && slot != L'B')
        || wls_guardian_recovery_runtime_paths(
            home,
            backup,
            request->nonce,
            candidate,
            new_generation,
            new_slots
        ) != 0
        || wls_join(
            backup_slots, WLS_PATH_CHARS, backup, L"slots"
        ) != 0
        || wls_join(live_slots, WLS_PATH_CHARS, home, L"slots") != 0
        || wls_guardian_recovery_root_only_directory(
            new_generation, 1
        ) != 0
        || wls_guardian_recovery_root_only_directory(new_slots, 1) != 0) {
        goto cleanup;
    }
    slot_name[0] = slot;
    if (wls_join(
            stored, WLS_PATH_CHARS, backup_slots, slot_name
        ) != 0
        || wls_join(live, WLS_PATH_CHARS, live_slots, slot_name) != 0
        || wls_join(
            quarantine, WLS_PATH_CHARS, new_slots, slot_name
        ) != 0) goto cleanup;
    old_generation = slot == L'A'
        ? request->recovery_slot_a_generation
        : request->recovery_slot_b_generation;
    stored_absent = wls_guardian_recovery_path_absent(stored);
    live_old_state = wls_all_zero_hex(old_generation) ? 2
        : wls_guardian_recovery_slot_state(
            home, slot, old_generation, request->recovery_ca_sha256
        );
    live_candidate_state = slot == L'A'
        ? wls_guardian_recovery_slot_state(
            home,
            L'A',
            request->candidate_runtime_generation,
            request->candidate_ca_sha256
        ) : 2;
    quarantine_candidate_state = slot == L'A'
        ? wls_guardian_recovery_slot_state(
            new_generation,
            L'A',
            request->candidate_runtime_generation,
            request->candidate_ca_sha256
        ) : 2;
    if (wls_all_zero_hex(old_generation)) {
        if (!stored_absent) goto cleanup;
        if (slot == L'B') {
            result = wls_guardian_recovery_path_absent(live) ? 0 : 1;
            goto cleanup;
        }
        if (wls_guardian_recovery_path_absent(live)
            && (quarantine_candidate_state == 0
                || wls_guardian_recovery_path_absent(quarantine))) {
            result = 0;
            goto cleanup;
        }
        if (live_candidate_state != 0
            || !wls_guardian_recovery_path_absent(quarantine)
            || wls_guardian_recovery_rename_no_replace(
                live, quarantine, 1
            ) != 0
            || wls_guardian_recovery_slot_state(
                new_generation,
                L'A',
                request->candidate_runtime_generation,
                request->candidate_ca_sha256
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (stored_absent) {
        result = live_old_state == 0 ? 0 : 1;
        goto cleanup;
    }
    if (wls_guardian_recovery_directory_closure_safe(stored) != 0) {
        goto cleanup;
    }
    if (wls_guardian_recovery_tree_acl_safe(stored, 'd', 0) == 0) {
        stored_controller_acl = 0;
    } else if (wls_guardian_recovery_tree_acl_safe(
            stored, 'd', 1
        ) == 0) {
        stored_controller_acl = 1;
    } else if (wls_guardian_recovery_slot_acl_transition_safe(
            stored
        ) == 0) {
        stored_controller_acl = 2;
    } else {
        goto cleanup;
    }
    if (wls_join(
            ca_path,
            WLS_PATH_CHARS,
            stored,
            L"share\\ca-bundle.pem"
        ) != 0) goto cleanup;
    if (stored_controller_acl != 2
        && (wls_verify_slot_durable_state_contract_v2_at_path(
                stored,
                slot,
                stored_controller_acl,
                observed_generation
            ) != 0
            || sodium_memcmp(
                observed_generation, old_generation, 64U
            ) != 0
            || wls_guardian_regular_digest_with_acl(
                ca_path,
                4ULL * 1024ULL * 1024ULL,
                stored_controller_acl,
                request->recovery_ca_sha256,
                0ULL
            ) != 0)) goto cleanup;
    if (stored_controller_acl != 1
        && wls_guardian_recovery_tree_controller_acl_apply(
            stored, 'd'
        ) != 0) goto cleanup;
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    if (wls_guardian_recovery_tree_acl_safe(stored, 'd', 1) != 0
        || wls_verify_slot_durable_state_contract_v2_at_path(
            stored, slot, 1, observed_generation
        ) != 0
        || sodium_memcmp(
            observed_generation, old_generation, 64U
        ) != 0
        || wls_guardian_regular_digest_with_acl(
            ca_path,
            4ULL * 1024ULL * 1024ULL,
            1,
            request->recovery_ca_sha256,
            0ULL
        ) != 0) goto cleanup;
    if (live_old_state == 0) goto cleanup;
    if (!wls_guardian_recovery_path_absent(live)) {
        if (slot != L'A' || live_candidate_state != 0
            || !wls_guardian_recovery_path_absent(quarantine)
            || wls_guardian_recovery_rename_no_replace(
                live, quarantine, 1
            ) != 0
            || wls_guardian_recovery_slot_state(
                new_generation,
                L'A',
                request->candidate_runtime_generation,
                request->candidate_ca_sha256
            ) != 0) goto cleanup;
    } else if (slot == L'A'
        && !wls_guardian_recovery_path_absent(quarantine)
        && quarantine_candidate_state != 0) {
        goto cleanup;
    }
    if (wls_guardian_recovery_rename_no_replace(
            stored, live, 1
        ) != 0
        || wls_guardian_recovery_slot_state(
            home, slot, old_generation, request->recovery_ca_sha256
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(candidate, sizeof(candidate));
    SecureZeroMemory(new_generation, sizeof(new_generation));
    SecureZeroMemory(new_slots, sizeof(new_slots));
    SecureZeroMemory(backup_slots, sizeof(backup_slots));
    SecureZeroMemory(live_slots, sizeof(live_slots));
    SecureZeroMemory(slot_name, sizeof(slot_name));
    SecureZeroMemory(stored, sizeof(stored));
    SecureZeroMemory(live, sizeof(live));
    SecureZeroMemory(quarantine, sizeof(quarantine));
    SecureZeroMemory(ca_path, sizeof(ca_path));
    SecureZeroMemory(observed_generation, sizeof(observed_generation));
    return result;
}

static int wls_guardian_recovery_restore_regular(
    const wchar_t *stored,
    const wchar_t *target,
    const wchar_t *quarantine,
    const char *old_digest,
    unsigned long long old_size,
    const char *candidate_digest,
    unsigned long long candidate_size,
    unsigned long long maximum
) {
    int stored_state;
    int target_old_state;
    int target_candidate_state;
    int quarantine_state;
    if (stored == NULL || target == NULL || quarantine == NULL
        || old_digest == NULL || candidate_digest == NULL) return 1;
    stored_state = wls_guardian_recovery_regular_state(
        stored, old_digest, old_size, maximum, 0
    );
    if (stored_state != 0) {
        stored_state = wls_guardian_recovery_regular_state(
            stored, old_digest, old_size, maximum, 1
        );
    }
    target_old_state = wls_guardian_recovery_regular_state(
        target, old_digest, old_size, maximum, 1
    );
    if (stored_state == 2 && target_old_state == 0) return 0;
    if (stored_state != 0) return 1;
    target_candidate_state = wls_guardian_recovery_regular_state(
        target, candidate_digest, candidate_size, maximum, 1
    );
    quarantine_state = wls_guardian_recovery_regular_state(
        quarantine, candidate_digest, candidate_size, maximum, 1
    );
    if (!wls_guardian_recovery_path_absent(target)) {
        if (target_candidate_state != 0
            || quarantine_state != 2
            || wls_guardian_recovery_rename_no_replace(
                target, quarantine, 0
            ) != 0) return 1;
    } else if (quarantine_state != 0 && quarantine_state != 2) {
        return 1;
    }
    if (wls_guardian_recovery_fixed_controller_acl_apply(
            stored, 0, 0
        ) != 0
        || wls_guardian_recovery_rename_no_replace(
            stored, target, 0
        ) != 0
        || wls_guardian_recovery_regular_state(
            target, old_digest, old_size, maximum, 1
        ) != 0) return 1;
    return 0;
}

static int wls_guardian_recovery_restore_runtime_regular(
    const wchar_t *home,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request,
    unsigned int operation
) {
    wchar_t candidate[WLS_PATH_CHARS];
    wchar_t new_generation[WLS_PATH_CHARS];
    wchar_t new_slots[WLS_PATH_CHARS];
    wchar_t stored[WLS_PATH_CHARS];
    wchar_t target[WLS_PATH_CHARS];
    wchar_t quarantine[WLS_PATH_CHARS];
    char old_text[80];
    char candidate_text[80];
    char old_digest[65];
    char candidate_digest[65];
    unsigned long long old_size = 0ULL;
    unsigned long long candidate_size = 0ULL;
    unsigned long long maximum = 0ULL;
    int result = 1;
    ZeroMemory(candidate, sizeof(candidate));
    ZeroMemory(new_generation, sizeof(new_generation));
    ZeroMemory(new_slots, sizeof(new_slots));
    ZeroMemory(stored, sizeof(stored));
    ZeroMemory(target, sizeof(target));
    ZeroMemory(quarantine, sizeof(quarantine));
    SecureZeroMemory(old_text, sizeof(old_text));
    SecureZeroMemory(candidate_text, sizeof(candidate_text));
    SecureZeroMemory(old_digest, sizeof(old_digest));
    SecureZeroMemory(candidate_digest, sizeof(candidate_digest));
    if (wls_guardian_recovery_runtime_paths(
            home,
            backup,
            request->nonce,
            candidate,
            new_generation,
            new_slots
        ) != 0
        || wls_guardian_recovery_root_only_directory(
            new_generation, 1
        ) != 0) goto cleanup;
    if (operation == 3U) {
        if (wls_join(
                stored, WLS_PATH_CHARS, backup, L"bin\\launcher"
            ) != 0
            || wls_join(
                target,
                WLS_PATH_CHARS,
                home,
                L"bin\\wls-gateway-launcher.exe"
            ) != 0
            || wls_join(
                quarantine,
                WLS_PATH_CHARS,
                new_generation,
                L"launcher"
            ) != 0) goto cleanup;
        memcpy(old_digest, request->recovery_launcher_sha256, 65U);
        memcpy(candidate_digest, request->candidate_launcher_sha256, 65U);
        old_size = request->recovery_launcher_size;
        candidate_size = request->candidate_launcher_size;
        maximum = WLS_GUARDIAN_LAUNCHER_MAX_BYTES;
    } else if (operation == 4U || operation == 5U) {
        if (operation == 4U) {
            if (_snprintf_s(
                    old_text,
                    sizeof(old_text),
                    _TRUNCATE,
                    "%s\n",
                    request->recovery_launcher_sha256
                ) <= 0
                || _snprintf_s(
                    candidate_text,
                    sizeof(candidate_text),
                    _TRUNCATE,
                    "%s\n",
                    request->candidate_launcher_sha256
                ) <= 0
                || wls_join(
                    stored,
                    WLS_PATH_CHARS,
                    backup,
                    L"trust\\stable-launcher.sha256"
                ) != 0
                || wls_join(
                    target,
                    WLS_PATH_CHARS,
                    home,
                    L"trust\\stable-launcher.sha256"
                ) != 0
                || wls_join(
                    quarantine,
                    WLS_PATH_CHARS,
                    new_generation,
                    L"stable-launcher.sha256"
                ) != 0) goto cleanup;
            maximum = 65ULL;
        } else {
            if (_snprintf_s(
                    old_text,
                    sizeof(old_text),
                    _TRUNCATE,
                    "%s\n",
                    request->recovery_active_slot
                ) <= 0
                || _snprintf_s(
                    candidate_text,
                    sizeof(candidate_text),
                    _TRUNCATE,
                    "A\n"
                ) <= 0
                || wls_join(
                    stored,
                    WLS_PATH_CHARS,
                    backup,
                    L"trust\\active-slot"
                ) != 0
                || wls_join(
                    target,
                    WLS_PATH_CHARS,
                    home,
                    L"trust\\active-slot"
                ) != 0
                || wls_join(
                    quarantine,
                    WLS_PATH_CHARS,
                    new_generation,
                    L"active-slot"
                ) != 0) goto cleanup;
            maximum = 2ULL;
        }
        if (wls_guardian_recovery_text_digest(
                old_text, old_digest, &old_size
            ) != 0
            || wls_guardian_recovery_text_digest(
                candidate_text, candidate_digest, &candidate_size
            ) != 0) goto cleanup;
    } else {
        goto cleanup;
    }
    result = wls_guardian_recovery_restore_regular(
        stored,
        target,
        quarantine,
        old_digest,
        old_size,
        candidate_digest,
        candidate_size,
        maximum
    );
cleanup:
    SecureZeroMemory(candidate, sizeof(candidate));
    SecureZeroMemory(new_generation, sizeof(new_generation));
    SecureZeroMemory(new_slots, sizeof(new_slots));
    SecureZeroMemory(stored, sizeof(stored));
    SecureZeroMemory(target, sizeof(target));
    SecureZeroMemory(quarantine, sizeof(quarantine));
    SecureZeroMemory(old_text, sizeof(old_text));
    SecureZeroMemory(candidate_text, sizeof(candidate_text));
    SecureZeroMemory(old_digest, sizeof(old_digest));
    SecureZeroMemory(candidate_digest, sizeof(candidate_digest));
    return result;
}

static int wls_guardian_recovery_restore_previous_slot(
    const wchar_t *home,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request
) {
    wchar_t candidate[WLS_PATH_CHARS];
    wchar_t new_generation[WLS_PATH_CHARS];
    wchar_t new_slots[WLS_PATH_CHARS];
    wchar_t stored[WLS_PATH_CHARS];
    wchar_t target[WLS_PATH_CHARS];
    wchar_t quarantine[WLS_PATH_CHARS];
    char old_text[4];
    char old_digest[65];
    unsigned long long old_size = 0ULL;
    int result = 1;
    ZeroMemory(candidate, sizeof(candidate));
    ZeroMemory(new_generation, sizeof(new_generation));
    ZeroMemory(new_slots, sizeof(new_slots));
    ZeroMemory(stored, sizeof(stored));
    ZeroMemory(target, sizeof(target));
    ZeroMemory(quarantine, sizeof(quarantine));
    SecureZeroMemory(old_text, sizeof(old_text));
    SecureZeroMemory(old_digest, sizeof(old_digest));
    if (wls_guardian_recovery_runtime_paths(
            home,
            backup,
            request->nonce,
            candidate,
            new_generation,
            new_slots
        ) != 0
        || wls_guardian_recovery_root_only_directory(
            new_generation, 1
        ) != 0
        || wls_join(
            stored,
            WLS_PATH_CHARS,
            backup,
            L"trust\\previous-slot"
        ) != 0
        || wls_join(
            target,
            WLS_PATH_CHARS,
            home,
            L"trust\\previous-slot"
        ) != 0
        || wls_join(
            quarantine,
            WLS_PATH_CHARS,
            new_generation,
            L"previous-slot"
        ) != 0) goto cleanup;
    if (strcmp(request->recovery_previous_slot, "NONE") == 0) {
        if (!wls_guardian_recovery_path_absent(stored)) goto cleanup;
        if (wls_guardian_recovery_path_absent(target)) {
            if (!wls_guardian_recovery_path_absent(quarantine)
                && wls_guardian_recovery_candidate_pointer_state(
                    quarantine
                ) != 0) goto cleanup;
            result = 0;
            goto cleanup;
        }
        if (wls_guardian_recovery_candidate_pointer_state(target) != 0
            || !wls_guardian_recovery_path_absent(quarantine)
            || wls_guardian_recovery_rename_no_replace(
                target, quarantine, 0
            ) != 0
            || wls_guardian_recovery_candidate_pointer_state(
                quarantine
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (_snprintf_s(
            old_text,
            sizeof(old_text),
            _TRUNCATE,
            "%s\n",
            request->recovery_previous_slot
        ) <= 0
        || wls_guardian_recovery_text_digest(
            old_text, old_digest, &old_size
        ) != 0) goto cleanup;
    if (wls_guardian_recovery_regular_state(
            stored, old_digest, old_size, 2ULL, 0
        ) == 2
        && wls_guardian_recovery_regular_state(
            target, old_digest, old_size, 2ULL, 1
        ) == 0) {
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_recovery_regular_state(
            stored, old_digest, old_size, 2ULL, 0
        ) != 0
        && wls_guardian_recovery_regular_state(
            stored, old_digest, old_size, 2ULL, 1
        ) != 0) goto cleanup;
    if (!wls_guardian_recovery_path_absent(target)) {
        if (wls_guardian_recovery_candidate_pointer_state(target) != 0
            || !wls_guardian_recovery_path_absent(quarantine)
            || wls_guardian_recovery_rename_no_replace(
                target, quarantine, 0
            ) != 0) goto cleanup;
    }
    if (wls_guardian_recovery_fixed_controller_acl_apply(
            stored, 0, 0
        ) != 0
        || wls_guardian_recovery_rename_no_replace(
            stored, target, 0
        ) != 0
        || wls_guardian_recovery_regular_state(
            target, old_digest, old_size, 2ULL, 1
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(candidate, sizeof(candidate));
    SecureZeroMemory(new_generation, sizeof(new_generation));
    SecureZeroMemory(new_slots, sizeof(new_slots));
    SecureZeroMemory(stored, sizeof(stored));
    SecureZeroMemory(target, sizeof(target));
    SecureZeroMemory(quarantine, sizeof(quarantine));
    SecureZeroMemory(old_text, sizeof(old_text));
    SecureZeroMemory(old_digest, sizeof(old_digest));
    return result;
}

static int wls_guardian_recovery_platform_paths(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request,
    wchar_t definition[WLS_PATH_CHARS],
    wchar_t metadata[WLS_PATH_CHARS]
) {
    if (home == NULL || request == NULL || definition == NULL
        || metadata == NULL) return 1;
#ifdef WLS_NATIVE_TEST_HOOKS
    if (strcmp(request->platform_kind, "test-session") == 0) {
        if (wls_join(
                definition,
                WLS_PATH_CHARS,
                home,
                L"state\\service-definition.test"
            ) != 0) return 1;
    } else
#endif
    if (strcmp(request->platform_kind, "windows-service") == 0) {
        if (wls_join(
                definition,
                WLS_PATH_CHARS,
                home,
                L"state\\windows-service.json"
            ) != 0) return 1;
    } else {
        return 1;
    }
    return wls_join(
        metadata,
        WLS_PATH_CHARS,
        home,
        L"trust\\platform-service.json"
    );
}

static int wls_guardian_recovery_platform_file_acl_valid(
    HANDLE object,
    DWORD controller_mask
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
    PSID controller_sid = NULL;
    DWORD status;
    DWORD index;
    unsigned int system_count = 0U;
    unsigned int administrators_count = 0U;
    unsigned int controller_count = 0U;
    int result = 1;
    ZeroMemory(&information, sizeof(information));
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    if (object == NULL || object == INVALID_HANDLE_VALUE
        || !CreateWellKnownSid(
            WinLocalSystemSid, NULL, system_buffer, &system_length
        )
        || !CreateWellKnownSid(
            WinBuiltinAdministratorsSid,
            NULL,
            administrators_buffer,
            &administrators_length
        )
        || !ConvertStringSidToSidA(
            WLS_GUARDIAN_CONTROLLER_SID, &controller_sid
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
        || !EqualSid(owner, administrators_buffer)
        || !GetSecurityDescriptorDacl(
            descriptor, &dacl_present, &dacl, &dacl_defaulted
        )
        || !dacl_present || dacl_defaulted || dacl == NULL
        || !GetSecurityDescriptorControl(
            descriptor, &control, &revision
        )
        || (control & SE_DACL_PROTECTED) == 0U
        || !GetAclInformation(
            dacl,
            &information,
            sizeof(information),
            AclSizeInformation
        )
        || dacl->AclRevision != ACL_REVISION
        || information.AceCount != 3U) goto cleanup;
    for (index = 0U; index < information.AceCount; ++index) {
        ACCESS_ALLOWED_ACE *ace = NULL;
        PSID sid;
        DWORD offset = (DWORD)FIELD_OFFSET(ACCESS_ALLOWED_ACE, SidStart);
        if (!GetAce(dacl, index, (LPVOID *)&ace)
            || ace == NULL
            || ace->Header.AceType != ACCESS_ALLOWED_ACE_TYPE
            || ace->Header.AceFlags != 0U
            || ace->Header.AceSize < offset + 8U) goto cleanup;
        sid = (PSID)&ace->SidStart;
        if (!IsValidSid(sid)
            || GetLengthSid(sid)
                != (DWORD)ace->Header.AceSize - offset) goto cleanup;
        if (EqualSid(sid, system_buffer)) {
            if (++system_count != 1U || ace->Mask != FILE_ALL_ACCESS) {
                goto cleanup;
            }
        } else if (EqualSid(sid, administrators_buffer)) {
            if (++administrators_count != 1U
                || ace->Mask != FILE_ALL_ACCESS) goto cleanup;
        } else if (EqualSid(sid, controller_sid)) {
            if (++controller_count != 1U
                || ace->Mask != controller_mask) goto cleanup;
        } else {
            goto cleanup;
        }
    }
    if (system_count == 1U && administrators_count == 1U
        && controller_count == 1U) result = 0;
cleanup:
    if (controller_sid != NULL) LocalFree(controller_sid);
    if (descriptor != NULL) LocalFree(descriptor);
    SecureZeroMemory(system_buffer, sizeof(system_buffer));
    SecureZeroMemory(administrators_buffer, sizeof(administrators_buffer));
    return result;
}

static int wls_guardian_recovery_platform_file_acl_apply(
    const wchar_t *path,
    DWORD controller_mask
) {
    char sddl[1024];
    PSECURITY_DESCRIPTOR descriptor = NULL;
    PSID owner = NULL;
    PACL dacl = NULL;
    BOOL owner_defaulted = FALSE;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    struct wls_guardian_pinned_file pin;
    struct wls_guardian_privilege_scope privilege;
    int privilege_enabled = 0;
    int length;
    int result = 1;
    SecureZeroMemory(sddl, sizeof(sddl));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    SecureZeroMemory(&privilege, sizeof(privilege));
    length = _snprintf_s(
        sddl,
        sizeof(sddl),
        _TRUNCATE,
        "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;0x%08lx;;;%s)",
        (unsigned long)controller_mask,
        WLS_GUARDIAN_CONTROLLER_SID
    );
    if (path == NULL || length <= 0 || length >= (int)sizeof(sddl)
        || !ConvertStringSecurityDescriptorToSecurityDescriptorA(
            sddl, SDDL_REVISION_1, &descriptor, NULL
        )
        || !GetSecurityDescriptorOwner(
            descriptor, &owner, &owner_defaulted
        )
        || owner == NULL || owner_defaulted
        || !GetSecurityDescriptorDacl(
            descriptor, &dacl_present, &dacl, &dacl_defaulted
        )
        || !dacl_present || dacl == NULL || dacl_defaulted
        || wls_guardian_open_pinned(
            path,
            0,
            GENERIC_READ | READ_CONTROL | WRITE_DAC | WRITE_OWNER,
            &pin
        ) != 0
        || wls_guardian_privilege_enable_restore(&privilege) != 0) {
        goto cleanup;
    }
    privilege_enabled = 1;
    if (SetSecurityInfo(
            pin.handle,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION
                | PROTECTED_DACL_SECURITY_INFORMATION,
            owner,
            NULL,
            dacl,
            NULL
        ) != ERROR_SUCCESS
        || wls_guardian_recovery_platform_file_acl_valid(
            pin.handle, controller_mask
        ) != 0
        || !wls_guardian_pinned_identity_unchanged(&pin, 0)) goto cleanup;
    result = 0;
cleanup:
    if (privilege_enabled) {
        if (wls_guardian_privilege_restore(&privilege) != 0) result = 1;
    } else {
        SecureZeroMemory(&privilege, sizeof(privilege));
    }
    wls_guardian_pinned_close(&pin);
    if (descriptor != NULL) LocalFree(descriptor);
    SecureZeroMemory(sddl, sizeof(sddl));
    return result;
}

static int wls_guardian_recovery_json_boolean_equals(
    const struct wls_launcher_json_node *node,
    int expected
) {
    int observed = 0;
    return wls_guardian_recovery_json_boolean(node, &observed) == 0
        && observed == expected;
}

static int wls_guardian_recovery_platform_definition_valid(
    const char *contents,
    size_t length,
    const wchar_t *home,
    const wchar_t *run_directory,
    const wchar_t *guardian
) {
    static const char *const keys[] = {
        "account", "arguments", "display_name",
        "failure_restart_seconds", "guardian",
        "repeat_last_failure_action", "service", "start"
    };
    struct wls_launcher_json_node *root = NULL;
    const struct wls_launcher_json_node *failures;
    const struct wls_launcher_json_node *arguments;
    char home_utf8[WLS_PATH_CHARS * 4U];
    char run_utf8[WLS_PATH_CHARS * 4U];
    char guardian_utf8[WLS_PATH_CHARS * 4U];
    char expected_home[WLS_PATH_CHARS * 4U + 16U];
    char expected_run[WLS_PATH_CHARS * 4U + 16U];
    int result = 1;
    SecureZeroMemory(home_utf8, sizeof(home_utf8));
    SecureZeroMemory(run_utf8, sizeof(run_utf8));
    SecureZeroMemory(guardian_utf8, sizeof(guardian_utf8));
    SecureZeroMemory(expected_home, sizeof(expected_home));
    SecureZeroMemory(expected_run, sizeof(expected_run));
    if (contents == NULL || length == 0U
        || length > WLS_GUARDIAN_RECOVERY_PLATFORM_DEFINITION_MAX
        || memchr(contents, '\0', length) != NULL || home == NULL
        || run_directory == NULL || guardian == NULL
        || wls_guardian_recovery_wide_to_utf8(
            home, home_utf8, sizeof(home_utf8)
        ) != 0
        || wls_guardian_recovery_wide_to_utf8(
            run_directory, run_utf8, sizeof(run_utf8)
        ) != 0
        || wls_guardian_recovery_wide_to_utf8(
            guardian, guardian_utf8, sizeof(guardian_utf8)
        ) != 0
        || _snprintf_s(
            expected_home,
            sizeof(expected_home),
            _TRUNCATE,
            "--home=%s",
            home_utf8
        ) <= 0
        || _snprintf_s(
            expected_run,
            sizeof(expected_run),
            _TRUNCATE,
            "--run=%s",
            run_utf8
        ) <= 0) goto cleanup;
    root = wls_launcher_json_parse(
        (const unsigned char *)contents, length
    );
    if (root == NULL
        || !wls_guardian_recovery_object_exact_members(
            root, keys, sizeof(keys) / sizeof(keys[0])
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "service"),
            "weline-wls-gateway-v2"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "display_name"),
            "Weline WLS 2.0 Host Gateway"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "account"), "LocalSystem"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "start"), "automatic"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "guardian"), guardian_utf8
        )
        || (failures = wls_launcher_json_member(
            root, "failure_restart_seconds"
        )) == NULL
        || failures->kind != WLS_LAUNCHER_JSON_ARRAY
        || failures->count != 3U
        || !wls_launcher_json_number_equals(failures->items[0], "5")
        || !wls_launcher_json_number_equals(failures->items[1], "5")
        || !wls_launcher_json_number_equals(failures->items[2], "5")
        || !wls_guardian_recovery_json_boolean_equals(
            wls_launcher_json_member(root, "repeat_last_failure_action"),
            1
        )
        || (arguments = wls_launcher_json_member(
            root, "arguments"
        )) == NULL
        || arguments->kind != WLS_LAUNCHER_JSON_ARRAY
        || arguments->count != 3U
        || !wls_launcher_json_string_equals(
            arguments->items[0], "--service"
        )
        || !wls_launcher_json_string_equals(
            arguments->items[1], expected_home
        )
        || !wls_launcher_json_string_equals(
            arguments->items[2], expected_run
        )) goto cleanup;
    result = 0;
cleanup:
    wls_launcher_json_free(root);
    SecureZeroMemory(home_utf8, sizeof(home_utf8));
    SecureZeroMemory(run_utf8, sizeof(run_utf8));
    SecureZeroMemory(guardian_utf8, sizeof(guardian_utf8));
    SecureZeroMemory(expected_home, sizeof(expected_home));
    SecureZeroMemory(expected_run, sizeof(expected_run));
    return result;
}

static int wls_guardian_recovery_platform_metadata_valid(
    const char *contents,
    size_t length,
    const wchar_t *definition,
    const struct wls_guardian_transition_request *request
) {
    static const char *const keys[] = {
        "definition", "installed_at", "kind", "profile",
        "schema_version", "test_mode"
    };
    struct wls_launcher_json_node *root = NULL;
    char definition_utf8[WLS_PATH_CHARS * 4U];
    char installed_at[129];
    int expected_test = 0;
    int result = 1;
    SecureZeroMemory(definition_utf8, sizeof(definition_utf8));
    SecureZeroMemory(installed_at, sizeof(installed_at));
    if (contents == NULL || length == 0U
        || length > WLS_GUARDIAN_RECOVERY_PLATFORM_METADATA_MAX
        || memchr(contents, '\0', length) != NULL || definition == NULL
        || request == NULL
        || wls_guardian_recovery_wide_to_utf8(
            definition, definition_utf8, sizeof(definition_utf8)
        ) != 0) goto cleanup;
#ifdef WLS_NATIVE_TEST_HOOKS
    expected_test = strcmp(request->platform_kind, "test-session") == 0;
#endif
    root = wls_launcher_json_parse(
        (const unsigned char *)contents, length
    );
    if (root == NULL
        || !wls_guardian_recovery_object_exact_members(
            root, keys, sizeof(keys) / sizeof(keys[0])
        )
        || !wls_launcher_json_number_equals(
            wls_launcher_json_member(root, "schema_version"), "1"
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "kind"), request->platform_kind
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "definition"), definition_utf8
        )
        || !wls_launcher_json_string_equals(
            wls_launcher_json_member(root, "profile"),
            request->platform_profile
        )
        || !wls_guardian_recovery_json_boolean_equals(
            wls_launcher_json_member(root, "test_mode"), expected_test
        )
        || wls_guardian_recovery_json_string_copy(
            wls_launcher_json_member(root, "installed_at"),
            installed_at,
            sizeof(installed_at)
        ) != 0
        || installed_at[0] == '\0'
        || strchr(installed_at, '\n') != NULL
        || strchr(installed_at, '\r') != NULL) goto cleanup;
    result = 0;
cleanup:
    wls_launcher_json_free(root);
    SecureZeroMemory(definition_utf8, sizeof(definition_utf8));
    SecureZeroMemory(installed_at, sizeof(installed_at));
    return result;
}

typedef int (*wls_guardian_recovery_platform_validator)(
    const char *, size_t, void *
);

struct wls_guardian_recovery_platform_validation_context {
    const wchar_t *home;
    const wchar_t *run_directory;
    const wchar_t *guardian;
    const wchar_t *definition;
    const struct wls_guardian_transition_request *request;
    int metadata;
};

static int wls_guardian_recovery_platform_validate_callback(
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_recovery_platform_validation_context *context =
        (struct wls_guardian_recovery_platform_validation_context *)opaque;
    if (context == NULL) return 1;
    return context->metadata
        ? wls_guardian_recovery_platform_metadata_valid(
            contents, length, context->definition, context->request
        )
        : wls_guardian_recovery_platform_definition_valid(
            contents,
            length,
            context->home,
            context->run_directory,
            context->guardian
        );
}

static int wls_guardian_recovery_platform_intent_parse(
    const char *contents,
    size_t length,
    struct wls_guardian_recovery_platform_write_intent *intent
) {
    char source_size[21];
    char current_size[21];
    size_t offset = 0U;
    int result = 1;
    SecureZeroMemory(source_size, sizeof(source_size));
    SecureZeroMemory(current_size, sizeof(current_size));
    if (contents == NULL || intent == NULL || length == 0U
        || length > WLS_GUARDIAN_RECOVERY_PLATFORM_INTENT_MAX
        || memchr(contents, '\0', length) != NULL) return 1;
    SecureZeroMemory(intent, sizeof(*intent));
#define WLS_GUARDIAN_PLATFORM_INTENT_LINE(prefix, field) \
    if (wls_guardian_protocol_line( \
            contents, length, &offset, prefix, field, sizeof(field) \
        ) != 0) goto cleanup
    if (wls_guardian_protocol_header(
            contents,
            length,
            &offset,
            "WLS-GUARDIAN-PLATFORM-WRITE-INTENT/1\n"
        ) != 0) goto cleanup;
    WLS_GUARDIAN_PLATFORM_INTENT_LINE("host_id=", intent->host_id);
    WLS_GUARDIAN_PLATFORM_INTENT_LINE("nonce=", intent->nonce);
    WLS_GUARDIAN_PLATFORM_INTENT_LINE("kind=", intent->kind);
    WLS_GUARDIAN_PLATFORM_INTENT_LINE(
        "target_volume=", intent->target_volume
    );
    WLS_GUARDIAN_PLATFORM_INTENT_LINE(
        "target_file_id=", intent->target_file_id
    );
    WLS_GUARDIAN_PLATFORM_INTENT_LINE(
        "source_sha256=", intent->source_sha256
    );
    WLS_GUARDIAN_PLATFORM_INTENT_LINE("source_size=", source_size);
    WLS_GUARDIAN_PLATFORM_INTENT_LINE(
        "current_sha256=", intent->current_sha256
    );
    WLS_GUARDIAN_PLATFORM_INTENT_LINE("current_size=", current_size);
    WLS_GUARDIAN_PLATFORM_INTENT_LINE("signature=", intent->signature);
#undef WLS_GUARDIAN_PLATFORM_INTENT_LINE
    if (offset != length
        || !wls_is_hex(intent->host_id, 32U)
        || !wls_is_hex(intent->nonce, 32U)
        || (strcmp(intent->kind, "definition") != 0
            && strcmp(intent->kind, "metadata") != 0)
        || !wls_is_hex(intent->target_volume, 16U)
        || !wls_is_hex(intent->target_file_id, 32U)
        || !wls_is_hex(intent->source_sha256, 64U)
        || !wls_is_hex(intent->current_sha256, 64U)
        || !wls_is_hex(intent->signature, 64U)
        || wls_guardian_protocol_decimal(
            source_size, &intent->source_size
        ) != 0
        || wls_guardian_protocol_decimal(
            current_size, &intent->current_size
        ) != 0
        || intent->source_size == 0ULL
        || intent->current_size == 0ULL
        || intent->source_size > (strcmp(intent->kind, "metadata") == 0
            ? WLS_GUARDIAN_RECOVERY_PLATFORM_METADATA_MAX
            : WLS_GUARDIAN_RECOVERY_PLATFORM_DEFINITION_MAX)
        || intent->current_size > (strcmp(intent->kind, "metadata") == 0
            ? WLS_GUARDIAN_RECOVERY_PLATFORM_METADATA_MAX
            : WLS_GUARDIAN_RECOVERY_PLATFORM_DEFINITION_MAX)
        || wls_guardian_sha256(
            (const unsigned char *)contents,
            length,
            intent->raw_sha256
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(source_size, sizeof(source_size));
    SecureZeroMemory(current_size, sizeof(current_size));
    if (result != 0 && intent != NULL) {
        SecureZeroMemory(intent, sizeof(*intent));
    }
    return result;
}

static int wls_guardian_recovery_platform_target_identity(
    const struct wls_guardian_pinned_file *target,
    char volume[17],
    char file_id[33]
) {
    int length;
    if (target == NULL || volume == NULL || file_id == NULL
        || target->handle == NULL || target->handle == INVALID_HANDLE_VALUE
        || !wls_guardian_pinned_identity_unchanged(target, 0)) return 1;
    length = _snprintf_s(
        volume,
        17U,
        _TRUNCATE,
        "%016llx",
        (unsigned long long)target->file_id.VolumeSerialNumber
    );
    if (length != 16
        || sodium_bin2hex(
            file_id,
            33U,
            target->file_id.FileId.Identifier,
            sizeof(target->file_id.FileId.Identifier)
        ) == NULL
        || !wls_is_hex(volume, 16U)
        || !wls_is_hex(file_id, 32U)) {
        SecureZeroMemory(volume, 17U);
        SecureZeroMemory(file_id, 33U);
        return 1;
    }
    return 0;
}

static int wls_guardian_recovery_platform_target_file_id_held(
    const struct wls_guardian_pinned_file *target
) {
    FILE_ATTRIBUTE_TAG_INFO attributes;
    BY_HANDLE_FILE_INFORMATION identity;
    FILE_ID_INFO file_id;
    if (target == NULL || target->handle == NULL
        || target->handle == INVALID_HANDLE_VALUE) return 0;
    ZeroMemory(&attributes, sizeof(attributes));
    ZeroMemory(&identity, sizeof(identity));
    ZeroMemory(&file_id, sizeof(file_id));
    return GetFileInformationByHandleEx(
            target->handle,
            FileAttributeTagInfo,
            &attributes,
            sizeof(attributes)
        )
        && GetFileInformationByHandle(target->handle, &identity)
        && GetFileInformationByHandleEx(
            target->handle, FileIdInfo, &file_id, sizeof(file_id)
        )
        && (attributes.FileAttributes
            & (FILE_ATTRIBUTE_REPARSE_POINT | FILE_ATTRIBUTE_DIRECTORY)) == 0U
        && identity.nNumberOfLinks == 1U
        && identity.dwVolumeSerialNumber
            == target->identity.dwVolumeSerialNumber
        && identity.nFileIndexHigh == target->identity.nFileIndexHigh
        && identity.nFileIndexLow == target->identity.nFileIndexLow
        && file_id.VolumeSerialNumber
            == target->file_id.VolumeSerialNumber
        && sodium_memcmp(
            file_id.FileId.Identifier,
            target->file_id.FileId.Identifier,
            sizeof(file_id.FileId.Identifier)
        ) == 0;
}

static int wls_guardian_recovery_platform_intent_paths(
    const wchar_t *backup,
    int metadata,
    wchar_t intent_path[WLS_PATH_CHARS],
    wchar_t preparing_path[WLS_PATH_CHARS]
) {
    wchar_t platform[WLS_PATH_CHARS];
    int result = 1;
    ZeroMemory(platform, sizeof(platform));
    if (backup == NULL || intent_path == NULL || preparing_path == NULL
        || (metadata != 0 && metadata != 1)
        || wls_join(platform, WLS_PATH_CHARS, backup, L"platform") != 0
        || wls_guardian_recovery_root_only_directory(platform, 0) != 0
        || wls_join(
            intent_path,
            WLS_PATH_CHARS,
            platform,
            metadata
                ? L"metadata.write-intent"
                : L"definition.write-intent"
        ) != 0
        || _snwprintf_s(
            preparing_path,
            WLS_PATH_CHARS,
            _TRUNCATE,
            L"%ls.preparing",
            intent_path
        ) < 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(platform, sizeof(platform));
    if (result != 0) {
        if (intent_path != NULL) {
            SecureZeroMemory(
                intent_path, WLS_PATH_CHARS * sizeof(*intent_path)
            );
        }
        if (preparing_path != NULL) {
            SecureZeroMemory(
                preparing_path, WLS_PATH_CHARS * sizeof(*preparing_path)
            );
        }
    }
    return result;
}

/* 0=authenticated, 2=absent, 1=unsafe/malformed. */
static int wls_guardian_recovery_platform_intent_read(
    const wchar_t *home,
    const wchar_t *path,
    struct wls_guardian_recovery_platform_write_intent *intent
) {
    char *contents = NULL;
    char *second = NULL;
    size_t length = 0U;
    struct wls_guardian_pinned_file pin;
    int result = 1;
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || path == NULL || intent == NULL) return 1;
    SecureZeroMemory(intent, sizeof(*intent));
    if (wls_guardian_recovery_path_absent(path)) return 2;
    if (wls_guardian_read_pinned(
            path,
            WLS_GUARDIAN_RECOVERY_PLATFORM_INTENT_MAX,
            0,
            &contents,
            &length,
            &pin
        ) != 0
        || wls_guardian_pinned_reread(&pin, length, &second) != 0
        || sodium_memcmp(contents, second, length) != 0
        || wls_guardian_recovery_platform_intent_parse(
            contents, length, intent
        ) != 0
        || wls_guardian_signed_document_valid(
            home, contents, length, intent->signature
        ) != 0
        || !wls_guardian_pinned_identity_unchanged(&pin, 0)) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    if (second != NULL) wls_guardian_contents_free(&second, length);
    wls_guardian_pinned_close(&pin);
    if (result != 0) SecureZeroMemory(intent, sizeof(*intent));
    return result;
}

static int wls_guardian_recovery_platform_intent_binding(
    const struct wls_guardian_recovery_platform_write_intent *intent,
    const struct wls_guardian_transition_request *request,
    int metadata,
    const struct wls_guardian_pinned_file *target,
    const char *source_sha256,
    size_t source_size
) {
    char volume[17];
    char file_id[33];
    int result = 1;
    SecureZeroMemory(volume, sizeof(volume));
    SecureZeroMemory(file_id, sizeof(file_id));
    if (intent == NULL || request == NULL || target == NULL
        || source_sha256 == NULL || source_size == 0U
        || (metadata != 0 && metadata != 1)
        || wls_guardian_recovery_platform_target_identity(
            target, volume, file_id
        ) != 0) goto cleanup;
    if (sodium_memcmp(intent->host_id, request->host_id, 32U) == 0
        && sodium_memcmp(intent->nonce, request->nonce, 32U) == 0
        && strcmp(intent->kind, metadata ? "metadata" : "definition") == 0
        && sodium_memcmp(intent->target_volume, volume, 16U) == 0
        && sodium_memcmp(intent->target_file_id, file_id, 32U) == 0
        && sodium_memcmp(
            intent->source_sha256, source_sha256, 64U
        ) == 0
        && intent->source_size == (unsigned long long)source_size) {
        result = 0;
    }
cleanup:
    SecureZeroMemory(volume, sizeof(volume));
    SecureZeroMemory(file_id, sizeof(file_id));
    return result;
}

static int wls_guardian_recovery_platform_intent_artifact_remove(
    const wchar_t *path
) {
    wchar_t parent[WLS_PATH_CHARS];
    wchar_t leaf[256];
    struct wls_guardian_pinned_file parent_pin;
    struct wls_guardian_pinned_file target_pin;
    LARGE_INTEGER size;
    FILE_DISPOSITION_INFO disposition;
    int result = 1;
    ZeroMemory(parent, sizeof(parent));
    ZeroMemory(leaf, sizeof(leaf));
    ZeroMemory(&parent_pin, sizeof(parent_pin));
    ZeroMemory(&target_pin, sizeof(target_pin));
    ZeroMemory(&size, sizeof(size));
    ZeroMemory(&disposition, sizeof(disposition));
    parent_pin.handle = INVALID_HANDLE_VALUE;
    target_pin.handle = INVALID_HANDLE_VALUE;
    if (path == NULL
        || wls_guardian_protocol_path_parts(path, parent, leaf) != 0
        || wls_guardian_open_pinned(
            parent,
            1,
            FILE_TRAVERSE | FILE_LIST_DIRECTORY,
            &parent_pin
        ) != 0
        || wls_recovery_acl_safe(parent_pin.handle, 0) != 0) goto cleanup;
    if (wls_guardian_open_pinned(
            path, 0, GENERIC_READ | DELETE, &target_pin
        ) != 0) {
        if (wls_guardian_recovery_path_absent(path)
            && wls_guardian_protocol_directory_identity_unchanged(
                &parent_pin
            )) {
            result = 0;
        }
        goto cleanup;
    }
    if (wls_recovery_acl_safe(target_pin.handle, 0) != 0
        || !GetFileSizeEx(target_pin.handle, &size)
        || size.QuadPart < 0
        || (unsigned long long)size.QuadPart
            > WLS_GUARDIAN_RECOVERY_PLATFORM_INTENT_MAX
        || target_pin.file_id.VolumeSerialNumber
            != parent_pin.file_id.VolumeSerialNumber
        || !wls_guardian_pinned_identity_unchanged(&target_pin, 0)
        || !wls_guardian_protocol_directory_identity_unchanged(
            &parent_pin
        )) goto cleanup;
    disposition.DeleteFile = TRUE;
    if (!SetFileInformationByHandle(
            target_pin.handle,
            FileDispositionInfo,
            &disposition,
            sizeof(disposition)
        )) goto cleanup;
    wls_guardian_pinned_close(&target_pin);
    if (wls_guardian_protocol_flush_directory(&parent_pin) != 0
        || !wls_guardian_recovery_path_absent(path)) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_pinned_close(&target_pin);
    wls_guardian_pinned_close(&parent_pin);
    SecureZeroMemory(parent, sizeof(parent));
    SecureZeroMemory(leaf, sizeof(leaf));
    SecureZeroMemory(&size, sizeof(size));
    SecureZeroMemory(&disposition, sizeof(disposition));
    return result;
}

static int wls_guardian_recovery_platform_intent_reconcile(
    const wchar_t *home,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request,
    int metadata,
    const struct wls_guardian_pinned_file *target,
    const char *source_sha256,
    size_t source_size,
    int *present
) {
    wchar_t intent_path[WLS_PATH_CHARS];
    wchar_t preparing_path[WLS_PATH_CHARS];
    struct wls_guardian_recovery_platform_write_intent intent;
    struct wls_guardian_recovery_platform_write_intent preparing;
    int intent_state;
    int preparing_state;
    int result = 1;
    ZeroMemory(intent_path, sizeof(intent_path));
    ZeroMemory(preparing_path, sizeof(preparing_path));
    SecureZeroMemory(&intent, sizeof(intent));
    SecureZeroMemory(&preparing, sizeof(preparing));
    if (present == NULL) return 1;
    *present = 0;
    if (wls_guardian_recovery_platform_intent_paths(
            backup, metadata, intent_path, preparing_path
        ) != 0) goto cleanup;
    intent_state = wls_guardian_recovery_platform_intent_read(
        home, intent_path, &intent
    );
    preparing_state = wls_guardian_recovery_platform_intent_read(
        home, preparing_path, &preparing
    );
    if (intent_state == 0) {
        if (wls_guardian_recovery_platform_intent_binding(
                &intent,
                request,
                metadata,
                target,
                source_sha256,
                source_size
            ) != 0) goto cleanup;
        if (preparing_state == 0
            && (wls_guardian_recovery_platform_intent_binding(
                    &preparing,
                    request,
                    metadata,
                    target,
                    source_sha256,
                    source_size
                ) != 0
                || sodium_memcmp(
                    preparing.raw_sha256, intent.raw_sha256, 64U
                ) != 0)) goto cleanup;
        if (preparing_state != 2
            && wls_guardian_recovery_platform_intent_artifact_remove(
                preparing_path
            ) != 0) goto cleanup;
        *present = 1;
        result = 0;
        goto cleanup;
    }
    if (intent_state != 2) goto cleanup;
    if (preparing_state == 1) {
        if (wls_guardian_recovery_platform_intent_artifact_remove(
                preparing_path
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (preparing_state == 2) {
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_recovery_platform_intent_binding(
            &preparing,
            request,
            metadata,
            target,
            source_sha256,
            source_size
        ) != 0
        || wls_guardian_recovery_rename_no_replace(
            preparing_path, intent_path, 0
        ) != 0) goto cleanup;
    SecureZeroMemory(&intent, sizeof(intent));
    if (wls_guardian_recovery_platform_intent_read(
            home, intent_path, &intent
        ) != 0
        || wls_guardian_recovery_platform_intent_binding(
            &intent,
            request,
            metadata,
            target,
            source_sha256,
            source_size
        ) != 0
        || sodium_memcmp(
            intent.raw_sha256, preparing.raw_sha256, 64U
        ) != 0) goto cleanup;
    *present = 1;
    result = 0;
cleanup:
    SecureZeroMemory(intent_path, sizeof(intent_path));
    SecureZeroMemory(preparing_path, sizeof(preparing_path));
    SecureZeroMemory(&intent, sizeof(intent));
    SecureZeroMemory(&preparing, sizeof(preparing));
    return result;
}

static int wls_guardian_recovery_platform_intent_publish(
    const wchar_t *home,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request,
    int metadata,
    const struct wls_guardian_pinned_file *target,
    const char *source_sha256,
    size_t source_size,
    const char *current_sha256,
    size_t current_size
) {
    wchar_t intent_path[WLS_PATH_CHARS];
    wchar_t preparing_path[WLS_PATH_CHARS];
    char volume[17];
    char file_id[33];
    char unsigned_intent[1024];
    char encoded[1152];
    char signature[65];
    char expected_raw[65];
    struct wls_guardian_pinned_file preparing_pin;
    int unsigned_length;
    int encoded_length;
    int present = 0;
    int result = 1;
    ZeroMemory(intent_path, sizeof(intent_path));
    ZeroMemory(preparing_path, sizeof(preparing_path));
    SecureZeroMemory(volume, sizeof(volume));
    SecureZeroMemory(file_id, sizeof(file_id));
    SecureZeroMemory(unsigned_intent, sizeof(unsigned_intent));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(expected_raw, sizeof(expected_raw));
    ZeroMemory(&preparing_pin, sizeof(preparing_pin));
    preparing_pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || backup == NULL || request == NULL || target == NULL
        || source_sha256 == NULL || current_sha256 == NULL
        || source_size == 0U || current_size == 0U
        || !wls_is_hex(source_sha256, 64U)
        || !wls_is_hex(current_sha256, 64U)
        || wls_guardian_recovery_platform_intent_paths(
            backup, metadata, intent_path, preparing_path
        ) != 0
        || !wls_guardian_recovery_path_absent(intent_path)
        || !wls_guardian_recovery_path_absent(preparing_path)
        || wls_guardian_recovery_platform_target_identity(
            target, volume, file_id
        ) != 0) goto cleanup;
    unsigned_length = _snprintf_s(
        unsigned_intent,
        sizeof(unsigned_intent),
        _TRUNCATE,
        "WLS-GUARDIAN-PLATFORM-WRITE-INTENT/1\n"
        "host_id=%s\n"
        "nonce=%s\n"
        "kind=%s\n"
        "target_volume=%s\n"
        "target_file_id=%s\n"
        "source_sha256=%s\n"
        "source_size=%llu\n"
        "current_sha256=%s\n"
        "current_size=%llu\n",
        request->host_id,
        request->nonce,
        metadata ? "metadata" : "definition",
        volume,
        file_id,
        source_sha256,
        (unsigned long long)source_size,
        current_sha256,
        (unsigned long long)current_size
    );
    if (unsigned_length <= 0
        || unsigned_length >= (int)sizeof(unsigned_intent)
        || wls_guardian_hmac_hex(
            home,
            unsigned_intent,
            (size_t)unsigned_length,
            signature
        ) != 0) goto cleanup;
    encoded_length = _snprintf_s(
        encoded,
        sizeof(encoded),
        _TRUNCATE,
        "%ssignature=%s\n",
        unsigned_intent,
        signature
    );
    if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
        || wls_guardian_sha256(
            (const unsigned char *)encoded,
            (size_t)encoded_length,
            expected_raw
        ) != 0
        || wls_guardian_protocol_root_file_create(
            preparing_path, encoded, &preparing_pin
        ) != 0
        || !wls_guardian_pinned_identity_unchanged(
            &preparing_pin, 0
        )) goto cleanup;
    wls_guardian_pinned_close(&preparing_pin);
    if (wls_guardian_recovery_platform_intent_reconcile(
            home,
            backup,
            request,
            metadata,
            target,
            source_sha256,
            source_size,
            &present
        ) != 0
        || !present) goto cleanup;
    {
        struct wls_guardian_recovery_platform_write_intent published;
        SecureZeroMemory(&published, sizeof(published));
        if (wls_guardian_recovery_platform_intent_read(
                home, intent_path, &published
            ) != 0
            || sodium_memcmp(
                published.raw_sha256, expected_raw, 64U
            ) != 0) {
            SecureZeroMemory(&published, sizeof(published));
            goto cleanup;
        }
        SecureZeroMemory(&published, sizeof(published));
    }
    result = 0;
cleanup:
    wls_guardian_pinned_close(&preparing_pin);
    SecureZeroMemory(intent_path, sizeof(intent_path));
    SecureZeroMemory(preparing_path, sizeof(preparing_path));
    SecureZeroMemory(volume, sizeof(volume));
    SecureZeroMemory(file_id, sizeof(file_id));
    SecureZeroMemory(unsigned_intent, sizeof(unsigned_intent));
    SecureZeroMemory(encoded, sizeof(encoded));
    SecureZeroMemory(signature, sizeof(signature));
    SecureZeroMemory(expected_raw, sizeof(expected_raw));
    return result;
}

static int wls_guardian_recovery_platform_intent_clear(
    const wchar_t *backup,
    int metadata
) {
    wchar_t intent_path[WLS_PATH_CHARS];
    wchar_t preparing_path[WLS_PATH_CHARS];
    int result = 1;
    ZeroMemory(intent_path, sizeof(intent_path));
    ZeroMemory(preparing_path, sizeof(preparing_path));
    if (wls_guardian_recovery_platform_intent_paths(
            backup, metadata, intent_path, preparing_path
        ) != 0
        || wls_guardian_recovery_platform_intent_artifact_remove(
            preparing_path
        ) != 0
        || wls_guardian_recovery_platform_intent_artifact_remove(
            intent_path
        ) != 0
        || !wls_guardian_recovery_path_absent(preparing_path)
        || !wls_guardian_recovery_path_absent(intent_path)) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(intent_path, sizeof(intent_path));
    SecureZeroMemory(preparing_path, sizeof(preparing_path));
    return result;
}

static int wls_guardian_recovery_platform_backup_load(
    const wchar_t *backup,
    int metadata,
    const char *digest,
    char **contents,
    size_t *length,
    struct wls_guardian_pinned_file *pin
) {
    wchar_t platform[WLS_PATH_CHARS];
    wchar_t path[WLS_PATH_CHARS];
    int result = 1;
    ZeroMemory(platform, sizeof(platform));
    ZeroMemory(path, sizeof(path));
    if (backup == NULL || digest == NULL || contents == NULL
        || length == NULL || pin == NULL
        || wls_join(platform, WLS_PATH_CHARS, backup, L"platform") != 0
        || wls_join(
            path,
            WLS_PATH_CHARS,
            platform,
            metadata ? L"metadata.before" : L"definition.before"
        ) != 0
        || wls_guardian_recovery_atomic_companions_absent(path) != 0
        || wls_guardian_recovery_root_only_read(
            path,
            metadata
                ? WLS_GUARDIAN_RECOVERY_PLATFORM_METADATA_MAX
                : WLS_GUARDIAN_RECOVERY_PLATFORM_DEFINITION_MAX,
            digest,
            contents,
            length,
            pin
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(platform, sizeof(platform));
    SecureZeroMemory(path, sizeof(path));
    return result;
}

static int wls_guardian_recovery_platform_prevalidate(
    const wchar_t *home,
    const wchar_t *run_directory,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request
) {
    wchar_t definition[WLS_PATH_CHARS];
    wchar_t metadata[WLS_PATH_CHARS];
    wchar_t guardian[WLS_PATH_CHARS];
    char guardian_digest[65];
    char *definition_contents = NULL;
    char *metadata_contents = NULL;
    size_t definition_length = 0U;
    size_t metadata_length = 0U;
    struct wls_guardian_pinned_file definition_pin;
    struct wls_guardian_pinned_file metadata_pin;
    int result = 1;
    ZeroMemory(definition, sizeof(definition));
    ZeroMemory(metadata, sizeof(metadata));
    ZeroMemory(guardian, sizeof(guardian));
    SecureZeroMemory(guardian_digest, sizeof(guardian_digest));
    ZeroMemory(&definition_pin, sizeof(definition_pin));
    ZeroMemory(&metadata_pin, sizeof(metadata_pin));
    definition_pin.handle = INVALID_HANDLE_VALUE;
    metadata_pin.handle = INVALID_HANDLE_VALUE;
    if (wls_guardian_recovery_platform_paths(
            home, request, definition, metadata
        ) != 0
        || wls_guardian_immutable_identity(
            home, guardian, guardian_digest
        ) != 0
        || wls_guardian_recovery_platform_backup_load(
            backup,
            0,
            request->platform_definition_sha256,
            &definition_contents,
            &definition_length,
            &definition_pin
        ) != 0
        || wls_guardian_recovery_platform_backup_load(
            backup,
            1,
            request->platform_metadata_sha256,
            &metadata_contents,
            &metadata_length,
            &metadata_pin
        ) != 0
        || wls_guardian_recovery_platform_definition_valid(
            definition_contents,
            definition_length,
            home,
            run_directory,
            guardian
        ) != 0
        || wls_guardian_recovery_platform_metadata_valid(
            metadata_contents, metadata_length, definition, request
        ) != 0
        || !wls_guardian_pinned_identity_unchanged(&definition_pin, 0)
        || !wls_guardian_pinned_identity_unchanged(&metadata_pin, 0)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (definition_contents != NULL) {
        wls_guardian_contents_free(
            &definition_contents, definition_length
        );
    }
    if (metadata_contents != NULL) {
        wls_guardian_contents_free(&metadata_contents, metadata_length);
    }
    wls_guardian_pinned_close(&definition_pin);
    wls_guardian_pinned_close(&metadata_pin);
    SecureZeroMemory(definition, sizeof(definition));
    SecureZeroMemory(metadata, sizeof(metadata));
    SecureZeroMemory(guardian, sizeof(guardian));
    SecureZeroMemory(guardian_digest, sizeof(guardian_digest));
    return result;
}

static int wls_guardian_recovery_platform_target_read(
    const wchar_t *path,
    size_t maximum,
    DWORD controller_mask,
    DWORD extra_access,
    char **contents,
    size_t *length,
    struct wls_guardian_pinned_file *pin
) {
    LARGE_INTEGER size;
    DWORD used = 0U;
    size_t allocated = 0U;
    int result = 1;
    if (path == NULL || maximum == 0U || maximum > MAXDWORD
        || contents == NULL || length == NULL || pin == NULL) return 1;
    *contents = NULL;
    *length = 0U;
    if (wls_guardian_open_pinned(
            path, 0, GENERIC_READ | extra_access, pin
        ) != 0
        || wls_guardian_recovery_platform_file_acl_valid(
            pin->handle, controller_mask
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
    (*contents)[used] = '\0';
    *length = (size_t)used;
    if (!wls_guardian_pinned_identity_unchanged(pin, 0)) goto cleanup;
    result = 0;
cleanup:
    if (result != 0 && *contents != NULL) {
        SecureZeroMemory(*contents, allocated);
        HeapFree(GetProcessHeap(), 0U, *contents);
        *contents = NULL;
        *length = 0U;
    }
    return result;
}

static int wls_guardian_recovery_platform_live_file_verify(
    const wchar_t *path,
    size_t maximum,
    DWORD controller_mask,
    const char *expected_digest,
    const FILE_ID_INFO *expected_file_id,
    wls_guardian_recovery_platform_validator validator,
    void *context
) {
    char *contents = NULL;
    char *second = NULL;
    size_t length = 0U;
    char digest[65];
    struct wls_guardian_pinned_file pin;
    int result = 1;
    SecureZeroMemory(digest, sizeof(digest));
    ZeroMemory(&pin, sizeof(pin));
    pin.handle = INVALID_HANDLE_VALUE;
    if (wls_guardian_recovery_platform_target_read(
            path,
            maximum,
            controller_mask,
            0U,
            &contents,
            &length,
            &pin
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)contents, length, digest
        ) != 0
        || wls_guardian_pinned_reread(&pin, length, &second) != 0
        || sodium_memcmp(contents, second, length) != 0
        || !wls_guardian_constant_equal(
            digest, expected_digest, 64U
        )
        || (expected_file_id != NULL
            && !wls_guardian_recovery_file_id_equal(
                &pin.file_id, expected_file_id
            ))
        || validator(contents, length, context) != 0
        || !wls_guardian_pinned_identity_unchanged(&pin, 0)) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) wls_guardian_contents_free(&contents, length);
    if (second != NULL) wls_guardian_contents_free(&second, length);
    wls_guardian_pinned_close(&pin);
    SecureZeroMemory(digest, sizeof(digest));
    return result;
}

static int wls_guardian_recovery_restore_platform_file(
    const wchar_t *home,
    const wchar_t *run_directory,
    const wchar_t *backup,
    const struct wls_guardian_transition_request *request,
    int metadata_file
) {
    wchar_t definition[WLS_PATH_CHARS];
    wchar_t metadata[WLS_PATH_CHARS];
    wchar_t guardian[WLS_PATH_CHARS];
    char guardian_digest[65];
    char *source = NULL;
    char *current = NULL;
    size_t source_length = 0U;
    size_t current_length = 0U;
    char current_digest[65];
    const wchar_t *target;
    const char *expected_digest;
    size_t maximum;
    DWORD controller_mask;
    struct wls_guardian_pinned_file source_pin;
    struct wls_guardian_pinned_file target_pin;
    FILE_ID_INFO target_file_id;
    struct wls_guardian_recovery_platform_validation_context context;
    LARGE_INTEGER zero;
    int intent_present = 0;
    int result = 1;
    ZeroMemory(definition, sizeof(definition));
    ZeroMemory(metadata, sizeof(metadata));
    ZeroMemory(guardian, sizeof(guardian));
    SecureZeroMemory(guardian_digest, sizeof(guardian_digest));
    SecureZeroMemory(current_digest, sizeof(current_digest));
    ZeroMemory(&source_pin, sizeof(source_pin));
    ZeroMemory(&target_pin, sizeof(target_pin));
    ZeroMemory(&target_file_id, sizeof(target_file_id));
    source_pin.handle = INVALID_HANDLE_VALUE;
    target_pin.handle = INVALID_HANDLE_VALUE;
    SecureZeroMemory(&context, sizeof(context));
    ZeroMemory(&zero, sizeof(zero));
    if (wls_guardian_recovery_platform_paths(
            home, request, definition, metadata
        ) != 0
        || wls_guardian_immutable_identity(
            home, guardian, guardian_digest
        ) != 0) goto cleanup;
    target = metadata_file ? metadata : definition;
    expected_digest = metadata_file
        ? request->platform_metadata_sha256
        : request->platform_definition_sha256;
    maximum = metadata_file
        ? WLS_GUARDIAN_RECOVERY_PLATFORM_METADATA_MAX
        : WLS_GUARDIAN_RECOVERY_PLATFORM_DEFINITION_MAX;
    controller_mask = metadata_file ? 0x001200A9UL : 0x001301BFUL;
    context.home = home;
    context.run_directory = run_directory;
    context.guardian = guardian;
    context.definition = definition;
    context.request = request;
    context.metadata = metadata_file;
    if (wls_guardian_recovery_platform_backup_load(
            backup,
            metadata_file,
            expected_digest,
            &source,
            &source_length,
            &source_pin
        ) != 0
        || wls_guardian_recovery_platform_validate_callback(
            source, source_length, &context
        ) != 0
        || wls_guardian_recovery_platform_file_acl_apply(
            target, controller_mask
        ) != 0
        || wls_guardian_recovery_platform_target_read(
            target,
            maximum,
            controller_mask,
            GENERIC_WRITE,
            &current,
            &current_length,
            &target_pin
        ) != 0
        || wls_guardian_sha256(
            (const unsigned char *)current,
            current_length,
            current_digest
        ) != 0
        || !wls_guardian_pinned_identity_unchanged(&source_pin, 0)
        || !wls_guardian_pinned_identity_unchanged(&target_pin, 0)) {
        goto cleanup;
    }
    target_file_id = target_pin.file_id;
    if (wls_guardian_recovery_platform_intent_reconcile(
            home,
            backup,
            request,
            metadata_file,
            &target_pin,
            expected_digest,
            source_length,
            &intent_present
        ) != 0) goto cleanup;
    if (wls_guardian_constant_equal(
            current_digest, expected_digest, 64U
        )) {
        if (current_length != source_length
            || sodium_memcmp(current, source, source_length) != 0
            || wls_guardian_recovery_platform_validate_callback(
                current, current_length, &context
            ) != 0
            || !wls_guardian_pinned_identity_unchanged(&source_pin, 0)
            || !wls_guardian_pinned_identity_unchanged(&target_pin, 0)) {
            goto cleanup;
        }
        wls_guardian_pinned_close(&target_pin);
        if (wls_guardian_recovery_platform_live_file_verify(
                target,
                maximum,
                controller_mask,
                expected_digest,
                &target_file_id,
                wls_guardian_recovery_platform_validate_callback,
                &context
            ) != 0
            || wls_guardian_recovery_platform_intent_clear(
                backup, metadata_file
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (!intent_present) {
        /* Without a durable signed intent, only a structurally valid current
         * generation may become the before-image of an in-place restore. */
        if (wls_guardian_recovery_platform_validate_callback(
                current, current_length, &context
            ) != 0
            || !wls_guardian_pinned_identity_unchanged(&source_pin, 0)
            || !wls_guardian_pinned_identity_unchanged(&target_pin, 0)
            || wls_guardian_recovery_platform_intent_publish(
                home,
                backup,
                request,
                metadata_file,
                &target_pin,
                expected_digest,
                source_length,
                current_digest,
                current_length
            ) != 0) goto cleanup;
        intent_present = 1;
    }
    /* A matching authenticated intent binds recovery to this exact File-ID.
     * It also authorizes retry over arbitrary bytes left by a torn write. */
    if (!intent_present
        || source_length > MAXDWORD
        || !wls_guardian_pinned_identity_unchanged(&source_pin, 0)
        || !wls_guardian_pinned_identity_unchanged(&target_pin, 0)
        || !SetFilePointerEx(target_pin.handle, zero, NULL, FILE_BEGIN)
        || wls_write_all(
            target_pin.handle,
            (const unsigned char *)source,
            (DWORD)source_length
        ) != 0
        || !SetEndOfFile(target_pin.handle)
        || !FlushFileBuffers(target_pin.handle)
        || !wls_guardian_pinned_identity_unchanged(&source_pin, 0)
        || !wls_guardian_recovery_platform_target_file_id_held(
            &target_pin
        )) {
        goto cleanup;
    }
    wls_guardian_pinned_close(&target_pin);
    if (wls_guardian_recovery_platform_live_file_verify(
            target,
            maximum,
            controller_mask,
            expected_digest,
            &target_file_id,
            wls_guardian_recovery_platform_validate_callback,
            &context
        ) != 0
        || wls_guardian_recovery_platform_intent_clear(
            backup, metadata_file
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (source != NULL) {
        wls_guardian_contents_free(&source, source_length);
    }
    if (current != NULL) {
        wls_guardian_contents_free(&current, current_length);
    }
    wls_guardian_pinned_close(&source_pin);
    wls_guardian_pinned_close(&target_pin);
    SecureZeroMemory(definition, sizeof(definition));
    SecureZeroMemory(metadata, sizeof(metadata));
    SecureZeroMemory(guardian, sizeof(guardian));
    SecureZeroMemory(guardian_digest, sizeof(guardian_digest));
    SecureZeroMemory(current_digest, sizeof(current_digest));
    SecureZeroMemory(&target_file_id, sizeof(target_file_id));
    SecureZeroMemory(&context, sizeof(context));
    return result;
}

struct wls_guardian_recovery_scm_snapshot {
    QUERY_SERVICE_CONFIGW *configuration;
    DWORD configuration_bytes;
    BYTE *failure;
    DWORD failure_bytes;
    BYTE *failure_flag;
    DWORD failure_flag_bytes;
    BYTE *preshutdown;
    DWORD preshutdown_bytes;
    BYTE *sid;
    DWORD sid_bytes;
    PSECURITY_DESCRIPTOR security;
    DWORD security_bytes;
    SECURITY_DESCRIPTOR_CONTROL security_control;
};

static int wls_guardian_recovery_scm_query_level(
    SC_HANDLE service,
    DWORD level,
    BYTE **contents,
    DWORD *contents_bytes
) {
    DWORD required = 0U;
    DWORD allocated = 0U;
    BYTE *buffer = NULL;
    if (service == NULL || contents == NULL || contents_bytes == NULL) {
        return 1;
    }
    *contents = NULL;
    *contents_bytes = 0U;
    SetLastError(ERROR_SUCCESS);
    (void)QueryServiceConfig2W(service, level, NULL, 0U, &required);
    if (required == 0U || GetLastError() != ERROR_INSUFFICIENT_BUFFER) {
        return 1;
    }
    allocated = required;
    buffer = (BYTE *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
    );
    if (buffer == NULL
        || !QueryServiceConfig2W(
            service, level, buffer, allocated, &required
        )) {
        if (buffer != NULL) {
            SecureZeroMemory(buffer, allocated);
            HeapFree(GetProcessHeap(), 0U, buffer);
        }
        return 1;
    }
    *contents = buffer;
    *contents_bytes = allocated;
    return 0;
}

static void wls_guardian_recovery_scm_snapshot_free(
    struct wls_guardian_recovery_scm_snapshot *snapshot
) {
    if (snapshot == NULL) return;
#define WLS_GUARDIAN_SCM_SNAPSHOT_FREE(field, length) \
    do { \
        if (snapshot->field != NULL) { \
            SecureZeroMemory(snapshot->field, snapshot->length); \
            HeapFree(GetProcessHeap(), 0U, snapshot->field); \
        } \
    } while (0)
    WLS_GUARDIAN_SCM_SNAPSHOT_FREE(configuration, configuration_bytes);
    WLS_GUARDIAN_SCM_SNAPSHOT_FREE(failure, failure_bytes);
    WLS_GUARDIAN_SCM_SNAPSHOT_FREE(failure_flag, failure_flag_bytes);
    WLS_GUARDIAN_SCM_SNAPSHOT_FREE(preshutdown, preshutdown_bytes);
    WLS_GUARDIAN_SCM_SNAPSHOT_FREE(sid, sid_bytes);
    WLS_GUARDIAN_SCM_SNAPSHOT_FREE(security, security_bytes);
#undef WLS_GUARDIAN_SCM_SNAPSHOT_FREE
    SecureZeroMemory(snapshot, sizeof(*snapshot));
}

static int wls_guardian_recovery_scm_snapshot_capture(
    SC_HANDLE service,
    struct wls_guardian_recovery_scm_snapshot *snapshot
) {
    DWORD required = 0U;
    DWORD revision = 0U;
    if (service == NULL || snapshot == NULL) return 1;
    SecureZeroMemory(snapshot, sizeof(*snapshot));
    SetLastError(ERROR_SUCCESS);
    (void)QueryServiceConfigW(service, NULL, 0U, &required);
    if (required < sizeof(QUERY_SERVICE_CONFIGW)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    snapshot->configuration_bytes = required;
    snapshot->configuration = (QUERY_SERVICE_CONFIGW *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, required
    );
    if (snapshot->configuration == NULL
        || !QueryServiceConfigW(
            service,
            snapshot->configuration,
            snapshot->configuration_bytes,
            &required
        )
        || snapshot->configuration->lpServiceStartName == NULL
        || wcscmp(
            snapshot->configuration->lpServiceStartName,
            L"LocalSystem"
        ) != 0
        || wls_guardian_recovery_scm_query_level(
            service,
            SERVICE_CONFIG_FAILURE_ACTIONS,
            &snapshot->failure,
            &snapshot->failure_bytes
        ) != 0
        || wls_guardian_recovery_scm_query_level(
            service,
            SERVICE_CONFIG_FAILURE_ACTIONS_FLAG,
            &snapshot->failure_flag,
            &snapshot->failure_flag_bytes
        ) != 0
        || wls_guardian_recovery_scm_query_level(
            service,
            SERVICE_CONFIG_PRESHUTDOWN_INFO,
            &snapshot->preshutdown,
            &snapshot->preshutdown_bytes
        ) != 0
        || wls_guardian_recovery_scm_query_level(
            service,
            SERVICE_CONFIG_SERVICE_SID_INFO,
            &snapshot->sid,
            &snapshot->sid_bytes
        ) != 0
        || snapshot->failure_bytes < sizeof(SERVICE_FAILURE_ACTIONSW)
        || snapshot->failure_flag_bytes
            < sizeof(SERVICE_FAILURE_ACTIONS_FLAG)
        || snapshot->preshutdown_bytes < sizeof(SERVICE_PRESHUTDOWN_INFO)
        || snapshot->sid_bytes < sizeof(SERVICE_SID_INFO)) goto cleanup;
    required = 0U;
    SetLastError(ERROR_SUCCESS);
    (void)QueryServiceObjectSecurity(
        service, DACL_SECURITY_INFORMATION, NULL, 0U, &required
    );
    if (required < sizeof(SECURITY_DESCRIPTOR)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) goto cleanup;
    snapshot->security_bytes = required;
    snapshot->security = (PSECURITY_DESCRIPTOR)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, required
    );
    if (snapshot->security == NULL
        || !QueryServiceObjectSecurity(
            service,
            DACL_SECURITY_INFORMATION,
            snapshot->security,
            snapshot->security_bytes,
            &required
        )
        || !IsValidSecurityDescriptor(snapshot->security)
        || !GetSecurityDescriptorControl(
            snapshot->security,
            &snapshot->security_control,
            &revision
        )) goto cleanup;
    return 0;
cleanup:
    wls_guardian_recovery_scm_snapshot_free(snapshot);
    return 1;
}

static int wls_guardian_recovery_scm_snapshot_restore(
    SC_HANDLE service,
    const struct wls_guardian_recovery_scm_snapshot *snapshot
) {
    PACL dacl = NULL;
    BOOL dacl_present = FALSE;
    BOOL dacl_defaulted = FALSE;
    DWORD security_information = DACL_SECURITY_INFORMATION;
    int result = 0;
    if (service == NULL || snapshot == NULL
        || snapshot->configuration == NULL
        || snapshot->failure == NULL
        || snapshot->failure_flag == NULL
        || snapshot->preshutdown == NULL
        || snapshot->sid == NULL
        || snapshot->security == NULL) return 1;
    if (!ChangeServiceConfigW(
            service,
            snapshot->configuration->dwServiceType,
            snapshot->configuration->dwStartType,
            snapshot->configuration->dwErrorControl,
            snapshot->configuration->lpBinaryPathName,
            snapshot->configuration->lpLoadOrderGroup,
            NULL,
            snapshot->configuration->lpDependencies,
            snapshot->configuration->lpServiceStartName,
            NULL,
            snapshot->configuration->lpDisplayName
        )) result = 1;
    if (!ChangeServiceConfig2W(
            service,
            SERVICE_CONFIG_FAILURE_ACTIONS,
            snapshot->failure
        )) result = 1;
    if (!ChangeServiceConfig2W(
            service,
            SERVICE_CONFIG_FAILURE_ACTIONS_FLAG,
            snapshot->failure_flag
        )) result = 1;
    if (!ChangeServiceConfig2W(
            service,
            SERVICE_CONFIG_PRESHUTDOWN_INFO,
            snapshot->preshutdown
        )) result = 1;
    if (!ChangeServiceConfig2W(
            service,
            SERVICE_CONFIG_SERVICE_SID_INFO,
            snapshot->sid
        )) result = 1;
    if (!GetSecurityDescriptorDacl(
            snapshot->security,
            &dacl_present,
            &dacl,
            &dacl_defaulted
        ) || !dacl_present || dacl == NULL) {
        result = 1;
    } else {
        security_information |=
            (snapshot->security_control & SE_DACL_PROTECTED) != 0U
                ? PROTECTED_DACL_SECURITY_INFORMATION
                : UNPROTECTED_DACL_SECURITY_INFORMATION;
        if (SetSecurityInfo(
                service,
                SE_SERVICE,
                security_information,
                NULL,
                NULL,
                dacl,
                NULL
            ) != ERROR_SUCCESS) result = 1;
    }
    return result;
}

static int wls_guardian_recovery_wide_equal(
    const wchar_t *left,
    const wchar_t *right
) {
    if (left == NULL || right == NULL) return left == right ? 1 : 0;
    return wcscmp(left, right) == 0 ? 1 : 0;
}

static int wls_guardian_recovery_multi_sz_equal(
    const wchar_t *left,
    const wchar_t *right
) {
    if (left == NULL || right == NULL) return left == right ? 1 : 0;
    for (;;) {
        size_t left_length = wcslen(left);
        size_t right_length = wcslen(right);
        if (left_length != right_length
            || memcmp(
                left,
                right,
                (left_length + 1U) * sizeof(wchar_t)
            ) != 0) return 0;
        if (left_length == 0U) return 1;
        left += left_length + 1U;
        right += right_length + 1U;
    }
}

static int wls_guardian_recovery_failure_equal(
    const SERVICE_FAILURE_ACTIONSW *left,
    const SERVICE_FAILURE_ACTIONSW *right
) {
    DWORD index;
    if (left == NULL || right == NULL
        || left->dwResetPeriod != right->dwResetPeriod
        || left->cActions != right->cActions
        || !wls_guardian_recovery_wide_equal(
            left->lpRebootMsg, right->lpRebootMsg
        )
        || !wls_guardian_recovery_wide_equal(
            left->lpCommand, right->lpCommand
        )
        || (left->cActions > 0U
            && (left->lpsaActions == NULL || right->lpsaActions == NULL))) {
        return 0;
    }
    for (index = 0U; index < left->cActions; ++index) {
        if (left->lpsaActions[index].Type != right->lpsaActions[index].Type
            || left->lpsaActions[index].Delay
                != right->lpsaActions[index].Delay) return 0;
    }
    return 1;
}

static int wls_guardian_recovery_scm_snapshot_matches(
    SC_HANDLE service,
    const struct wls_guardian_recovery_scm_snapshot *expected
) {
    struct wls_guardian_recovery_scm_snapshot observed;
    PACL expected_dacl = NULL;
    PACL observed_dacl = NULL;
    BOOL expected_present = FALSE;
    BOOL observed_present = FALSE;
    BOOL expected_defaulted = FALSE;
    BOOL observed_defaulted = FALSE;
    int result = 1;
    SecureZeroMemory(&observed, sizeof(observed));
    if (service == NULL || expected == NULL
        || expected->configuration == NULL
        || wls_guardian_recovery_scm_snapshot_capture(
            service, &observed
        ) != 0) goto cleanup;
    if (observed.configuration == NULL
        || observed.configuration->dwServiceType
            != expected->configuration->dwServiceType
        || observed.configuration->dwStartType
            != expected->configuration->dwStartType
        || observed.configuration->dwErrorControl
            != expected->configuration->dwErrorControl
        || !wls_guardian_recovery_wide_equal(
            observed.configuration->lpBinaryPathName,
            expected->configuration->lpBinaryPathName
        )
        || !wls_guardian_recovery_wide_equal(
            observed.configuration->lpLoadOrderGroup,
            expected->configuration->lpLoadOrderGroup
        )
        || !wls_guardian_recovery_multi_sz_equal(
            observed.configuration->lpDependencies,
            expected->configuration->lpDependencies
        )
        || !wls_guardian_recovery_wide_equal(
            observed.configuration->lpServiceStartName,
            expected->configuration->lpServiceStartName
        )
        || !wls_guardian_recovery_wide_equal(
            observed.configuration->lpDisplayName,
            expected->configuration->lpDisplayName
        )
        || !wls_guardian_recovery_failure_equal(
            (const SERVICE_FAILURE_ACTIONSW *)(const void *)observed.failure,
            (const SERVICE_FAILURE_ACTIONSW *)(const void *)expected->failure
        )
        || ((const SERVICE_FAILURE_ACTIONS_FLAG *)(const void *)
                observed.failure_flag)->fFailureActionsOnNonCrashFailures
            != ((const SERVICE_FAILURE_ACTIONS_FLAG *)(const void *)
                expected->failure_flag)->fFailureActionsOnNonCrashFailures
        || ((const SERVICE_PRESHUTDOWN_INFO *)(const void *)
                observed.preshutdown)->dwPreshutdownTimeout
            != ((const SERVICE_PRESHUTDOWN_INFO *)(const void *)
                expected->preshutdown)->dwPreshutdownTimeout
        || ((const SERVICE_SID_INFO *)(const void *)observed.sid)->dwServiceSidType
            != ((const SERVICE_SID_INFO *)(const void *)expected->sid)->dwServiceSidType
        || (observed.security_control & SE_DACL_PROTECTED)
            != (expected->security_control & SE_DACL_PROTECTED)
        || !GetSecurityDescriptorDacl(
            expected->security,
            &expected_present,
            &expected_dacl,
            &expected_defaulted
        )
        || !GetSecurityDescriptorDacl(
            observed.security,
            &observed_present,
            &observed_dacl,
            &observed_defaulted
        )
        || !expected_present || !observed_present
        || expected_defaulted != observed_defaulted
        || expected_dacl == NULL || observed_dacl == NULL
        || expected_dacl->AclSize != observed_dacl->AclSize
        || memcmp(
            expected_dacl,
            observed_dacl,
            expected_dacl->AclSize
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_recovery_scm_snapshot_free(&observed);
    return result;
}

static int wls_guardian_recovery_scm_force_safe_start(SC_HANDLE service) {
    DWORD required = 0U;
    DWORD allocated = 0U;
    QUERY_SERVICE_CONFIGW *configuration = NULL;
    int result = 1;
    if (service == NULL) return 1;
    if (!ChangeServiceConfigW(
            service,
            SERVICE_NO_CHANGE,
            SERVICE_DISABLED,
            SERVICE_NO_CHANGE,
            NULL, NULL, NULL, NULL, NULL, NULL, NULL
        ) && !ChangeServiceConfigW(
            service,
            SERVICE_NO_CHANGE,
            SERVICE_DEMAND_START,
            SERVICE_NO_CHANGE,
            NULL, NULL, NULL, NULL, NULL, NULL, NULL
        )) return 1;
    SetLastError(ERROR_SUCCESS);
    (void)QueryServiceConfigW(service, NULL, 0U, &required);
    if (required < sizeof(QUERY_SERVICE_CONFIGW)
        || GetLastError() != ERROR_INSUFFICIENT_BUFFER) return 1;
    allocated = required;
    configuration = (QUERY_SERVICE_CONFIGW *)HeapAlloc(
        GetProcessHeap(), HEAP_ZERO_MEMORY, allocated
    );
    if (configuration != NULL
        && QueryServiceConfigW(
            service, configuration, allocated, &required
        )
        && configuration->dwStartType != SERVICE_AUTO_START
        && (configuration->dwStartType == SERVICE_DISABLED
            || configuration->dwStartType == SERVICE_DEMAND_START)) result = 0;
    if (configuration != NULL) {
        SecureZeroMemory(configuration, allocated);
        HeapFree(GetProcessHeap(), 0U, configuration);
    }
    return result;
}

static int wls_guardian_recovery_scm_delete_verified(
    SC_HANDLE manager,
    SC_HANDLE *service
) {
    SC_HANDLE observed = NULL;
    DWORD error;
    if (manager == NULL || service == NULL || *service == NULL) return 1;
    if (!DeleteService(*service)) return 1;
    CloseServiceHandle(*service);
    *service = NULL;
    SetLastError(ERROR_SUCCESS);
    observed = OpenServiceW(
        manager,
        L"weline-wls-gateway-v2",
        SERVICE_CHANGE_CONFIG | SERVICE_QUERY_CONFIG
            | WRITE_DAC | READ_CONTROL | DELETE
    );
    if (observed != NULL) {
        *service = observed;
        return 1;
    }
    error = GetLastError();
    return error == ERROR_SERVICE_DOES_NOT_EXIST
            || error == ERROR_SERVICE_MARKED_FOR_DELETE
        ? 0 : 1;
}

static int wls_guardian_recovery_scm_preshutdown_exact(
    SC_HANDLE service
) {
    SERVICE_PRESHUTDOWN_INFO info;
    DWORD required = 0U;
    ZeroMemory(&info, sizeof(info));
    return service != NULL
        && QueryServiceConfig2W(
            service,
            SERVICE_CONFIG_PRESHUTDOWN_INFO,
            (LPBYTE)&info,
            (DWORD)sizeof(info),
            &required
        )
        && info.dwPreshutdownTimeout == 330000U ? 0 : 1;
}

static int wls_guardian_recovery_platform_apply_scm(
    const wchar_t *home,
    const wchar_t *run_directory,
    const struct wls_guardian_transition_request *request
) {
    wchar_t guardian[WLS_PATH_CHARS];
    wchar_t command[WLS_PATH_CHARS * 2U];
    char guardian_digest[65];
    SC_HANDLE manager = NULL;
    SC_HANDLE service = NULL;
    SC_ACTION actions[3];
    SERVICE_FAILURE_ACTIONSW failure;
    SERVICE_FAILURE_ACTIONS_FLAG failure_flag;
    SERVICE_PRESHUTDOWN_INFO preshutdown;
    SERVICE_SID_INFO sid_info;
    struct wls_guardian_recovery_scm_snapshot snapshot;
    PSECURITY_DESCRIPTOR desired_security = NULL;
    PACL desired_dacl = NULL;
    BOOL desired_dacl_present = FALSE;
    BOOL desired_dacl_defaulted = FALSE;
    int created = 0;
    int snapshot_captured = 0;
    int rollback_hard_failure = 0;
    int result = 1;
    ZeroMemory(guardian, sizeof(guardian));
    ZeroMemory(command, sizeof(command));
    SecureZeroMemory(guardian_digest, sizeof(guardian_digest));
    ZeroMemory(actions, sizeof(actions));
    ZeroMemory(&failure, sizeof(failure));
    ZeroMemory(&failure_flag, sizeof(failure_flag));
    ZeroMemory(&preshutdown, sizeof(preshutdown));
    ZeroMemory(&sid_info, sizeof(sid_info));
    SecureZeroMemory(&snapshot, sizeof(snapshot));
    if (request == NULL) return 1;
#ifdef WLS_NATIVE_TEST_HOOKS
    if (strcmp(request->platform_kind, "test-session") == 0) return 0;
#endif
    if (strcmp(request->platform_kind, "windows-service") != 0
        || wls_guardian_local_system_process() != 0
        || wls_guardian_immutable_identity(
            home, guardian, guardian_digest
        ) != 0
        || wls_guardian_service_command_line(
            home,
            run_directory,
            guardian,
            command,
            sizeof(command) / sizeof(command[0])
        ) != 0) goto cleanup;
    manager = OpenSCManagerW(
        NULL, NULL, SC_MANAGER_CONNECT | SC_MANAGER_CREATE_SERVICE
    );
    if (manager == NULL) goto cleanup;
    service = OpenServiceW(
        manager,
        L"weline-wls-gateway-v2",
        SERVICE_CHANGE_CONFIG | SERVICE_QUERY_CONFIG
            | WRITE_DAC | READ_CONTROL
    );
    if (service == NULL && GetLastError() == ERROR_SERVICE_DOES_NOT_EXIST) {
        service = CreateServiceW(
            manager,
            L"weline-wls-gateway-v2",
            L"Weline WLS 2.0 Host Gateway",
            SERVICE_CHANGE_CONFIG | SERVICE_QUERY_CONFIG
                | WRITE_DAC | READ_CONTROL | DELETE,
            SERVICE_WIN32_OWN_PROCESS,
            SERVICE_DEMAND_START,
            SERVICE_ERROR_NORMAL,
            command,
            NULL,
            NULL,
            NULL,
            L"LocalSystem",
            NULL
        );
        if (service != NULL) created = 1;
    }
    if (service == NULL) goto cleanup;
    if (!created) {
        if (wls_guardian_recovery_scm_snapshot_capture(
                service, &snapshot
            ) != 0) goto cleanup;
        snapshot_captured = 1;
    }
    if (!ChangeServiceConfigW(
            service,
            SERVICE_WIN32_OWN_PROCESS,
            SERVICE_DEMAND_START,
            SERVICE_ERROR_NORMAL,
            command,
            NULL,
            NULL,
            NULL,
            L"LocalSystem",
            NULL,
            L"Weline WLS 2.0 Host Gateway"
        )) goto cleanup;
    actions[0].Type = SC_ACTION_RESTART;
    actions[1].Type = SC_ACTION_RESTART;
    actions[2].Type = SC_ACTION_RESTART;
    actions[0].Delay = 5000U;
    actions[1].Delay = 5000U;
    actions[2].Delay = 5000U;
    failure.dwResetPeriod = 0U;
    failure.cActions = 3U;
    failure.lpsaActions = actions;
    failure_flag.fFailureActionsOnNonCrashFailures = TRUE;
    preshutdown.dwPreshutdownTimeout = 330000U;
    sid_info.dwServiceSidType = SERVICE_SID_TYPE_UNRESTRICTED;
    if (!ChangeServiceConfig2W(
            service, SERVICE_CONFIG_FAILURE_ACTIONS, &failure
        )
        || !ChangeServiceConfig2W(
            service,
            SERVICE_CONFIG_FAILURE_ACTIONS_FLAG,
            &failure_flag
        )
        || !ChangeServiceConfig2W(
            service, SERVICE_CONFIG_SERVICE_SID_INFO, &sid_info
        )
        || !ChangeServiceConfig2W(
            service, SERVICE_CONFIG_PRESHUTDOWN_INFO, &preshutdown
        )
        || !ConvertStringSecurityDescriptorToSecurityDescriptorW(
            L"D:P(A;;0xF01FF;;;SY)(A;;0xF01FF;;;BA)",
            SDDL_REVISION_1,
            &desired_security,
            NULL
        )
        || desired_security == NULL
        || !GetSecurityDescriptorDacl(
            desired_security,
            &desired_dacl_present,
            &desired_dacl,
            &desired_dacl_defaulted
        )
        || !desired_dacl_present || desired_dacl_defaulted
        || desired_dacl == NULL
        || SetSecurityInfo(
            service,
            SE_SERVICE,
            DACL_SECURITY_INFORMATION
                | PROTECTED_DACL_SECURITY_INFORMATION,
            NULL,
            NULL,
            desired_dacl,
            NULL
        ) != ERROR_SUCCESS
        || wls_guardian_recovery_scm_preshutdown_exact(service) != 0
        || wls_guardian_scm_service_dacl_query_exact(service) != 0
        || !ChangeServiceConfigW(
            service,
            SERVICE_NO_CHANGE,
            SERVICE_AUTO_START,
            SERVICE_NO_CHANGE,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL,
            NULL
        )
        || wls_guardian_scm_definition_valid(
            home, run_directory, guardian
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (result != 0 && service != NULL) {
        if (created) {
            if (wls_guardian_recovery_scm_delete_verified(
                    manager, &service
                ) != 0) {
                rollback_hard_failure = 1;
                if (service == NULL
                    || wls_guardian_recovery_scm_force_safe_start(
                        service
                    ) != 0) {
                    rollback_hard_failure = 1;
                }
            }
        } else if (snapshot_captured) {
            int restore_failed = wls_guardian_recovery_scm_snapshot_restore(
                service, &snapshot
            );
            int readback_failed = wls_guardian_recovery_scm_snapshot_matches(
                service, &snapshot
            );
            if (restore_failed != 0 || readback_failed != 0) {
                rollback_hard_failure = 1;
                if (wls_guardian_recovery_scm_force_safe_start(service) != 0) {
                    rollback_hard_failure = 1;
                }
            }
        }
        if (rollback_hard_failure) result = 2;
    }
    if (desired_security != NULL) LocalFree(desired_security);
    wls_guardian_recovery_scm_snapshot_free(&snapshot);
    if (service != NULL) CloseServiceHandle(service);
    if (manager != NULL) CloseServiceHandle(manager);
    SecureZeroMemory(guardian, sizeof(guardian));
    SecureZeroMemory(command, sizeof(command));
    SecureZeroMemory(guardian_digest, sizeof(guardian_digest));
    SecureZeroMemory(actions, sizeof(actions));
    SecureZeroMemory(&failure, sizeof(failure));
    SecureZeroMemory(&failure_flag, sizeof(failure_flag));
    SecureZeroMemory(&preshutdown, sizeof(preshutdown));
    SecureZeroMemory(&sid_info, sizeof(sid_info));
    return result;
}

static int wls_guardian_recovery_runtime_path_binding(
    const wchar_t *home,
    const wchar_t *run_directory
) {
    wchar_t expected[WLS_PATH_CHARS];
    int result;
    ZeroMemory(expected, sizeof(expected));
    result = wls_guardian_protocol_home_valid(home)
        && run_directory != NULL
        && wls_join(
            expected, WLS_PATH_CHARS, home, L"runtime\\run"
        ) == 0
        && wcscmp(expected, run_directory) == 0 ? 0 : 1;
    SecureZeroMemory(expected, sizeof(expected));
    return result;
}

static int wls_guardian_platform_live_verify(
    const wchar_t *home,
    const wchar_t *run_directory,
    const struct wls_guardian_transition_request *request
) {
    wchar_t definition[WLS_PATH_CHARS];
    wchar_t metadata[WLS_PATH_CHARS];
    wchar_t guardian[WLS_PATH_CHARS];
    char guardian_digest[65];
    struct wls_guardian_recovery_platform_validation_context definition_context;
    struct wls_guardian_recovery_platform_validation_context metadata_context;
    int result = 1;
    ZeroMemory(definition, sizeof(definition));
    ZeroMemory(metadata, sizeof(metadata));
    ZeroMemory(guardian, sizeof(guardian));
    SecureZeroMemory(guardian_digest, sizeof(guardian_digest));
    SecureZeroMemory(&definition_context, sizeof(definition_context));
    SecureZeroMemory(&metadata_context, sizeof(metadata_context));
    if (request == NULL
        || wls_guardian_recovery_runtime_path_binding(
            home, run_directory
        ) != 0
        || wls_guardian_recovery_platform_paths(
            home, request, definition, metadata
        ) != 0
        || wls_guardian_immutable_identity(
            home, guardian, guardian_digest
        ) != 0) goto cleanup;
    definition_context.home = home;
    definition_context.run_directory = run_directory;
    definition_context.guardian = guardian;
    definition_context.definition = definition;
    definition_context.request = request;
    definition_context.metadata = 0;
    metadata_context = definition_context;
    metadata_context.metadata = 1;
    if (wls_guardian_recovery_platform_live_file_verify(
            definition,
            WLS_GUARDIAN_RECOVERY_PLATFORM_DEFINITION_MAX,
            0x001301BFUL,
            request->platform_definition_sha256,
            NULL,
            wls_guardian_recovery_platform_validate_callback,
            &definition_context
        ) != 0
        || wls_guardian_recovery_platform_live_file_verify(
            metadata,
            WLS_GUARDIAN_RECOVERY_PLATFORM_METADATA_MAX,
            0x001200A9UL,
            request->platform_metadata_sha256,
            NULL,
            wls_guardian_recovery_platform_validate_callback,
            &metadata_context
        ) != 0) goto cleanup;
#ifdef WLS_NATIVE_TEST_HOOKS
    if (strcmp(request->platform_kind, "test-session") == 0) {
        result = 0;
        goto cleanup;
    }
#endif
    if (strcmp(request->platform_kind, "windows-service") != 0
        || wls_guardian_scm_definition_valid(
            home, run_directory, guardian
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(definition, sizeof(definition));
    SecureZeroMemory(metadata, sizeof(metadata));
    SecureZeroMemory(guardian, sizeof(guardian));
    SecureZeroMemory(guardian_digest, sizeof(guardian_digest));
    SecureZeroMemory(&definition_context, sizeof(definition_context));
    SecureZeroMemory(&metadata_context, sizeof(metadata_context));
    return result;
}

static int wls_guardian_recovery_transaction_state_matches(
    unsigned long long sequence,
    const char *phase,
    unsigned long long cursor
) {
    const char *expected_phase = NULL;
    unsigned long long expected_cursor = 0ULL;
    return phase != NULL
        && wls_guardian_recovery_transaction_position(
            sequence, &expected_phase, &expected_cursor
        ) == 0
        && strcmp(phase, expected_phase) == 0
        && cursor == expected_cursor ? 0 : 1;
}

static int wls_guardian_recovery_self_test(void) {
    static const char canonical_json[] =
        "{\"a\":1,\"b\":[false,null,true]}\n";
    static const char unsorted_json[] =
        "{\"b\":[false,null,true],\"a\":1}\n";
    static const char duplicate_json[] = "{\"a\":1,\"a\":2}\n";
    static const char malformed_json[] = "{\"a\":[}\n";
    static const char valid_intent[] =
        "WLS-GUARDIAN-PLATFORM-WRITE-INTENT/1\n"
        "host_id=00000000000000000000000000000000\n"
        "nonce=11111111111111111111111111111111\n"
        "kind=definition\n"
        "target_volume=2222222222222222\n"
        "target_file_id=33333333333333333333333333333333\n"
        "source_sha256=" WLS_GUARDIAN_ZERO_64 "\n"
        "source_size=7\n"
        "current_sha256="
            "4444444444444444444444444444444444444444444444444444444444444444\n"
        "current_size=9\n"
        "signature="
            "5555555555555555555555555555555555555555555555555555555555555555\n";
    char inventory_line[768];
    char invalid_intent[sizeof(valid_intent)];
    struct wls_launcher_json_node *json = NULL;
    struct wls_guardian_recovery_inventory_entry entry;
    struct wls_guardian_recovery_platform_write_intent intent;
    wchar_t *wide = NULL;
    unsigned int depth = 0U;
    unsigned long long sequence;
    int inventory_length;
    int result = 1;
    SecureZeroMemory(inventory_line, sizeof(inventory_line));
    SecureZeroMemory(invalid_intent, sizeof(invalid_intent));
    SecureZeroMemory(&entry, sizeof(entry));
    SecureZeroMemory(&intent, sizeof(intent));

    for (sequence = 1ULL; sequence <= 26ULL; ++sequence) {
        const char *phase = sequence == 1ULL
            ? "AUTHORIZED"
            : sequence <= 9ULL
                ? "RUNTIME"
                : sequence <= 19ULL
                    ? "DERIVED"
                    : sequence <= 23ULL
                        ? "PLATFORM"
                        : sequence == 24ULL
                            ? "RESTORED"
                            : sequence == 25ULL
                                ? "OBSERVING" : "STABLE";
        unsigned long long cursor = sequence == 1ULL || sequence >= 24ULL
            ? 0ULL
            : sequence <= 9ULL
                ? sequence - 2ULL
                : sequence <= 19ULL
                    ? sequence - 10ULL : sequence - 20ULL;
        const char *mapped_phase = NULL;
        unsigned long long mapped_cursor = ULLONG_MAX;
        if (wls_guardian_recovery_transaction_position(
                sequence, &mapped_phase, &mapped_cursor
            ) != 0
            || mapped_phase == NULL
            || strcmp(mapped_phase, phase) != 0
            || mapped_cursor != cursor
            || wls_guardian_recovery_transaction_state_matches(
                sequence, phase, cursor
            ) != 0
            || wls_guardian_recovery_transaction_state_matches(
                sequence, "OUT_OF_ORDER", cursor
            ) == 0
            || wls_guardian_recovery_transaction_state_matches(
                sequence, phase, cursor + 1ULL
            ) == 0) goto cleanup;
    }
    {
        const char *phase = NULL;
        unsigned long long cursor = 0ULL;
        if (wls_guardian_recovery_transaction_position(
                0ULL, &phase, &cursor
            ) == 0
            || wls_guardian_recovery_transaction_position(
                27ULL, &phase, &cursor
            ) == 0
            || wls_guardian_recovery_transaction_position(
                1ULL, NULL, &cursor
            ) == 0
            || wls_guardian_recovery_transaction_position(
                1ULL, &phase, NULL
            ) == 0) goto cleanup;
    }

    /* Model an interrupted reverse conversion: the slot root is still
     * sealed, while bin/nginx.exe and ordinary descendants may independently
     * have reached only their exact final role-profile. */
    if (wls_guardian_recovery_slot_data_plane_role(L".", 'd') != 2
        || wls_guardian_recovery_slot_data_plane_role(L"BIN", 'd') != 2
        || wls_guardian_recovery_slot_data_plane_role(
            L"Bin\\Nginx.Exe", 'f'
        ) != 1
        || wls_guardian_recovery_slot_data_plane_role(
            L"bin\\php.exe", 'f'
        ) != 0
        || wls_guardian_recovery_slot_data_plane_role(NULL, 'd') != -1
        || !wls_guardian_recovery_slot_acl_transition_role_valid(
            L".", 'd', 1, 0, 0, 0
        )
        || !wls_guardian_recovery_slot_acl_transition_role_valid(
            L".", 'd', 0, 0, 0, 1
        )
        || !wls_guardian_recovery_slot_acl_transition_role_valid(
            L"bin", 'd', 1, 0, 0, 0
        )
        || !wls_guardian_recovery_slot_acl_transition_role_valid(
            L"bin", 'd', 0, 0, 0, 1
        )
        || !wls_guardian_recovery_slot_acl_transition_role_valid(
            L"bin\\nginx.exe", 'f', 1, 0, 0, 0
        )
        || !wls_guardian_recovery_slot_acl_transition_role_valid(
            L"bin\\nginx.exe", 'f', 0, 0, 1, 0
        )
        || !wls_guardian_recovery_slot_acl_transition_role_valid(
            L"app", 'd', 0, 1, 0, 0
        )
        || !wls_guardian_recovery_slot_acl_transition_role_valid(
            L"bin\\php.exe", 'f', 0, 1, 0, 0
        )
        || wls_guardian_recovery_slot_acl_transition_role_valid(
            L".", 'd', 0, 1, 0, 0
        )
        || wls_guardian_recovery_slot_acl_transition_role_valid(
            L".", 'd', 0, 0, 1, 0
        )
        || wls_guardian_recovery_slot_acl_transition_role_valid(
            L"bin", 'd', 0, 1, 0, 0
        )
        || wls_guardian_recovery_slot_acl_transition_role_valid(
            L"bin\\nginx.exe", 'f', 0, 1, 0, 0
        )
        || wls_guardian_recovery_slot_acl_transition_role_valid(
            L"app", 'd', 0, 0, 0, 1
        )
        || wls_guardian_recovery_slot_acl_transition_role_valid(
            L"bin\\php.exe", 'f', 0, 0, 1, 0
        )
        || wls_guardian_recovery_slot_acl_transition_role_valid(
            L"app", 'd', 0, 0, 0, 0
        )
        || wls_guardian_recovery_slot_acl_transition_role_valid(
            L"app", 'd', 1, 1, 0, 0
        )) goto cleanup;

    if (wls_guardian_recovery_json_is_canonical(
            canonical_json, sizeof(canonical_json) - 1U, &json
        ) != 0
        || json == NULL) goto cleanup;
    wls_launcher_json_free(json);
    json = NULL;
    if (wls_guardian_recovery_json_is_canonical(
            unsorted_json, sizeof(unsorted_json) - 1U, &json
        ) == 0
        || wls_guardian_recovery_json_is_canonical(
            duplicate_json, sizeof(duplicate_json) - 1U, &json
        ) == 0
        || wls_guardian_recovery_json_is_canonical(
            canonical_json, sizeof(canonical_json) - 2U, &json
        ) == 0
        || wls_guardian_recovery_json_is_canonical(
            malformed_json, sizeof(malformed_json) - 1U, &json
        ) == 0) goto cleanup;

    if (wls_guardian_recovery_relative_decode(
            "alpha/beta", &wide, &depth
        ) != 0
        || wide == NULL || depth != 2U
        || wcscmp(wide, L"alpha\\beta") != 0) goto cleanup;
    SecureZeroMemory(wide, (wcslen(wide) + 1U) * sizeof(*wide));
    HeapFree(GetProcessHeap(), 0U, wide);
    wide = NULL;
    if (wls_guardian_recovery_relative_decode(
            "../x", &wide, &depth
        ) == 0
        || wls_guardian_recovery_relative_decode(
            "alpha//beta", &wide, &depth
        ) == 0
        || wls_guardian_recovery_relative_decode(
            "alpha\\beta", &wide, &depth
        ) == 0
        || wls_guardian_recovery_relative_decode(
            "CON", &wide, &depth
        ) == 0) goto cleanup;

    inventory_length = _snprintf_s(
        inventory_line,
        sizeof(inventory_line),
        _TRUNCATE,
        "entry=runtime-conf\trestore\t616c706861\tf\t%s",
        WLS_GUARDIAN_ZERO_64
    );
    if (inventory_length <= 0
        || inventory_length >= (int)sizeof(inventory_line)
        || wls_guardian_recovery_inventory_entry_parse(
            inventory_line, (size_t)inventory_length, &entry
        ) != 0
        || entry.category_index != 0U || entry.kind != 'f'
        || strcmp(entry.leaf, "alpha") != 0
        || wcscmp(entry.leaf_wide, L"alpha") != 0) goto cleanup;
    inventory_length = _snprintf_s(
        inventory_line,
        sizeof(inventory_line),
        _TRUNCATE,
        "entry=runtime-conf\trestore\t2e2e\td\t%s",
        WLS_GUARDIAN_ZERO_64
    );
    if (inventory_length <= 0
        || inventory_length >= (int)sizeof(inventory_line)
        || wls_guardian_recovery_inventory_entry_parse(
            inventory_line, (size_t)inventory_length, &entry
        ) == 0) goto cleanup;

    if (wls_guardian_recovery_platform_intent_parse(
            valid_intent, sizeof(valid_intent) - 1U, &intent
        ) != 0
        || strcmp(intent.kind, "definition") != 0
        || intent.source_size != 7ULL || intent.current_size != 9ULL
        || strcmp(intent.target_volume, "2222222222222222") != 0
        || strcmp(
            intent.target_file_id,
            "33333333333333333333333333333333"
        ) != 0) goto cleanup;
    if (wls_guardian_recovery_platform_intent_parse(
            valid_intent, sizeof(valid_intent) - 2U, &intent
        ) == 0) goto cleanup;
    memcpy(invalid_intent, valid_intent, sizeof(valid_intent));
    {
        char *kind = strstr(invalid_intent, "kind=definition");
        if (kind == NULL) goto cleanup;
        kind[5] = 'x';
    }
    if (wls_guardian_recovery_platform_intent_parse(
            invalid_intent, sizeof(invalid_intent) - 1U, &intent
        ) == 0) goto cleanup;
    memcpy(invalid_intent, valid_intent, sizeof(valid_intent));
    {
        char *size = strstr(invalid_intent, "source_size=7");
        if (size == NULL) goto cleanup;
        size[strlen("source_size=")] = '0';
    }
    if (wls_guardian_recovery_platform_intent_parse(
            invalid_intent, sizeof(invalid_intent) - 1U, &intent
        ) == 0) goto cleanup;
    memcpy(invalid_intent, valid_intent, sizeof(valid_intent));
    {
        char *file_id = strstr(invalid_intent, "target_file_id=");
        if (file_id == NULL) goto cleanup;
        file_id[strlen("target_file_id=")] = 'g';
    }
    if (wls_guardian_recovery_platform_intent_parse(
            invalid_intent, sizeof(invalid_intent) - 1U, &intent
        ) == 0) goto cleanup;
    memcpy(invalid_intent, valid_intent, sizeof(valid_intent));
    memcpy(invalid_intent + strlen(
        "WLS-GUARDIAN-PLATFORM-WRITE-INTENT/1\n"
    ), "nonce_id", 8U);
    if (wls_guardian_recovery_platform_intent_parse(
            invalid_intent, sizeof(invalid_intent) - 1U, &intent
        ) == 0) goto cleanup;

    result = 0;
cleanup:
    wls_launcher_json_free(json);
    if (wide != NULL) {
        SecureZeroMemory(wide, (wcslen(wide) + 1U) * sizeof(*wide));
        HeapFree(GetProcessHeap(), 0U, wide);
    }
    SecureZeroMemory(inventory_line, sizeof(inventory_line));
    SecureZeroMemory(invalid_intent, sizeof(invalid_intent));
    SecureZeroMemory(&entry, sizeof(entry));
    SecureZeroMemory(&intent, sizeof(intent));
    return result;
}

static int wls_guardian_recovery_roots_verify(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request
) {
    struct wls_guardian_recovery_evidence evidence;
    int result = 1;
    SecureZeroMemory(&evidence, sizeof(evidence));
    if (!wls_guardian_protocol_home_valid(home) || request == NULL
        || wls_guardian_recovery_evidence_load(
            home, request, &evidence
        ) != 0
        || wls_guardian_recovery_derived_state_final_verify(
            home, request, &evidence
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_recovery_evidence_free(&evidence);
    return result;
}

static int wls_guardian_recovery_restore(
    const wchar_t *home,
    const wchar_t *run_directory,
    const struct wls_guardian_transition_request *request
) {
    struct wls_guardian_protocol_lock lock;
    struct wls_guardian_generation_head head;
    struct wls_guardian_transition_request disk_request;
    struct wls_guardian_recovery_transaction transaction;
    struct wls_guardian_recovery_evidence evidence;
    int request_present = 0;
    int result = 1;
    SecureZeroMemory(&lock, sizeof(lock));
    lock.pin.handle = INVALID_HANDLE_VALUE;
    SecureZeroMemory(&head, sizeof(head));
    SecureZeroMemory(&disk_request, sizeof(disk_request));
    SecureZeroMemory(&transaction, sizeof(transaction));
    SecureZeroMemory(&evidence, sizeof(evidence));
    if (request == NULL
        || wls_guardian_recovery_runtime_path_binding(
            home, run_directory
        ) != 0
        || !wls_is_hex(request->raw_sha256, 64U)
        || wls_guardian_protocol_reconcile_and_select(
            home,
            &lock,
            &head,
            &disk_request,
            &request_present
        ) != 0
        || !request_present
        || !wls_guardian_constant_equal(
            request->raw_sha256, disk_request.raw_sha256, 64U
        )
        || (strcmp(head.phase, "ROLLBACK_PENDING") != 0
            && strcmp(head.phase, "ROLLBACK_OBSERVING") != 0)
        || wls_guardian_protocol_request_head_binding(
            &disk_request, &head
        ) != 0
        || wls_guardian_recovery_evidence_load(
            home, &disk_request, &evidence
        ) != 0
        || wls_guardian_recovery_runtime_prevalidate(
            home, &disk_request, evidence.backup
        ) != 0
        || wls_guardian_recovery_derived_prevalidate(
            home, &disk_request, &evidence
        ) != 0
        || wls_guardian_recovery_platform_prevalidate(
            home, run_directory, evidence.backup, &disk_request
        ) != 0
        || wls_guardian_recovery_transaction_begin_locked(
            home, &lock, &disk_request, &transaction
        ) != 0) goto cleanup;
    if (strcmp(transaction.phase, "AUTHORIZED") == 0
        && wls_guardian_recovery_transaction_advance_locked(
            home,
            &lock,
            &disk_request,
            &transaction,
            "RUNTIME",
            0ULL
        ) != 0) goto cleanup;
    if (strcmp(transaction.phase, "RUNTIME") == 0) {
        while (transaction.cursor < 7ULL) {
            int operation_result;
            switch ((unsigned int)transaction.cursor) {
                case 0U:
                    operation_result =
                        wls_guardian_recovery_restore_candidate_directory(
                            home, evidence.backup, &disk_request
                        );
                    break;
                case 1U:
                    operation_result = wls_guardian_recovery_restore_slot(
                        home, evidence.backup, &disk_request, L'A'
                    );
                    break;
                case 2U:
                    operation_result = wls_guardian_recovery_restore_slot(
                        home, evidence.backup, &disk_request, L'B'
                    );
                    break;
                case 3U:
                case 4U:
                case 5U:
                    operation_result =
                        wls_guardian_recovery_restore_runtime_regular(
                            home,
                            evidence.backup,
                            &disk_request,
                            (unsigned int)transaction.cursor
                        );
                    break;
                case 6U:
                    operation_result =
                        wls_guardian_recovery_restore_previous_slot(
                            home, evidence.backup, &disk_request
                        );
                    break;
                default:
                    operation_result = 1;
                    break;
            }
            if (operation_result != 0
                || wls_guardian_recovery_transaction_advance_locked(
                    home,
                    &lock,
                    &disk_request,
                    &transaction,
                    "RUNTIME",
                    transaction.cursor + 1ULL
                ) != 0) goto cleanup;
        }
        if (wls_guardian_recovery_transaction_advance_locked(
                home,
                &lock,
                &disk_request,
                &transaction,
                "DERIVED",
                0ULL
            ) != 0) goto cleanup;
    }
    if (strcmp(transaction.phase, "DERIVED") == 0) {
        while (transaction.cursor
            < WLS_GUARDIAN_RECOVERY_CATEGORY_COUNT) {
            unsigned int category_index =
                wls_guardian_recovery_restore_order[
                    (size_t)transaction.cursor
                ];
            if (wls_guardian_recovery_restore_derived_category(
                    home,
                    &disk_request,
                    &evidence,
                    category_index
                ) != 0
                || wls_guardian_recovery_transaction_advance_locked(
                    home,
                    &lock,
                    &disk_request,
                    &transaction,
                    "DERIVED",
                    transaction.cursor + 1ULL
                ) != 0) goto cleanup;
        }
        if (wls_guardian_recovery_transaction_advance_locked(
                home,
                &lock,
                &disk_request,
                &transaction,
                "PLATFORM",
                0ULL
            ) != 0) goto cleanup;
    }
    if (strcmp(transaction.phase, "PLATFORM") == 0) {
        while (transaction.cursor < 3ULL) {
            int operation_result;
            if (transaction.cursor == 0ULL) {
                operation_result =
                    wls_guardian_recovery_restore_platform_file(
                        home,
                        run_directory,
                        evidence.backup,
                        &disk_request,
                        0
                    );
            } else if (transaction.cursor == 1ULL) {
                operation_result =
                    wls_guardian_recovery_restore_platform_file(
                        home,
                        run_directory,
                        evidence.backup,
                        &disk_request,
                        1
                    );
            } else {
                operation_result =
                    wls_guardian_recovery_platform_apply_scm(
                        home, run_directory, &disk_request
                    );
            }
            if (operation_result != 0
                || wls_guardian_recovery_transaction_advance_locked(
                    home,
                    &lock,
                    &disk_request,
                    &transaction,
                    "PLATFORM",
                    transaction.cursor + 1ULL
                ) != 0) goto cleanup;
        }
        if (wls_guardian_generation_closure(
                home,
                disk_request.recovery_launcher_sha256,
                disk_request.recovery_launcher_size,
                disk_request.recovery_ca_sha256,
                disk_request.recovery_runtime_generation,
                disk_request.recovery_generation_id
            ) != 0
            || wls_guardian_recovery_derived_state_final_verify(
                home, &disk_request, &evidence
            ) != 0
            || wls_guardian_platform_live_verify(
                home, run_directory, &disk_request
            ) != 0
            || wls_guardian_recovery_transaction_advance_locked(
                home,
                &lock,
                &disk_request,
                &transaction,
                "RESTORED",
                0ULL
            ) != 0) goto cleanup;
    }
    if ((strcmp(transaction.phase, "RESTORED") != 0
            && strcmp(transaction.phase, "OBSERVING") != 0
            && strcmp(transaction.phase, "STABLE") != 0)
        || wls_guardian_generation_closure(
            home,
            disk_request.recovery_launcher_sha256,
            disk_request.recovery_launcher_size,
            disk_request.recovery_ca_sha256,
            disk_request.recovery_runtime_generation,
            disk_request.recovery_generation_id
        ) != 0
        || wls_guardian_recovery_derived_state_final_verify(
            home, &disk_request, &evidence
        ) != 0
        || wls_guardian_platform_live_verify(
            home, run_directory, &disk_request
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_protocol_unlock(&lock);
    wls_guardian_recovery_evidence_free(&evidence);
    SecureZeroMemory(&head, sizeof(head));
    SecureZeroMemory(&disk_request, sizeof(disk_request));
    SecureZeroMemory(&transaction, sizeof(transaction));
    return result;
}

#endif /* WLS_GATEWAY_GUARDIAN_RECOVERY_INC */
