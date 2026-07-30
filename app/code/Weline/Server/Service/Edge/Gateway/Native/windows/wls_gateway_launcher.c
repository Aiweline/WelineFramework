#define _CRT_SECURE_NO_WARNINGS
#include <windows.h>
#include <sodium.h>
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

struct wls_upgrade {
    int present;
    wchar_t from;
    wchar_t to;
    long long prepared_at;
    long long deadline;
    char runtime_generation[65];
};

static SERVICE_STATUS_HANDLE wls_status_handle = NULL;
static SERVICE_STATUS wls_service_status;
static HANDLE wls_broker_process = NULL;
static HANDLE wls_broker_stop_event = NULL;
static volatile LONG wls_service_stop_requested = 0;
static volatile LONG wls_service_reload_requested = 0;
static const wchar_t *wls_service_home = NULL;
static const wchar_t *wls_service_run = NULL;

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
    DWORD amount;
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
    if (buffer == NULL
        || !ReadFile(file, buffer, (DWORD)size.QuadPart, &amount, NULL)
        || amount != (DWORD)size.QuadPart) {
        if (buffer != NULL) HeapFree(GetProcessHeap(), 0U, buffer);
        CloseHandle(file);
        return 1;
    }
    CloseHandle(file);
    buffer[amount] = '\0';
    *contents = buffer;
    *length = amount;
    return 0;
}

static int wls_atomic_text(const wchar_t *path, const char *contents)
{
    wchar_t temporary[WLS_PATH_CHARS];
    HANDLE file = INVALID_HANDLE_VALUE;
    DWORD length;
    DWORD written;
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
        || (attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT) != 0
        || crypto_hash_sha256_init(&state) != 0) {
        if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
        return 1;
    }
    while (ReadFile(file, buffer, sizeof(buffer), &amount, NULL) && amount > 0U) {
        if (crypto_hash_sha256_update(&state, buffer, amount) != 0) goto cleanup;
    }
    if (GetLastError() != ERROR_SUCCESS && amount != 0U) goto cleanup;
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
        && length >= 1U
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
    size_t decoded = 0U;
    int consumed = 0;
    int fields;
    int result = -1;
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
        || upgrade->prepared_at < 1
        || upgrade->deadline != upgrade->prepared_at + 300
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
    upgrade->present = 1;
    upgrade->from = (wchar_t)from[0];
    upgrade->to = (wchar_t)to[0];
    result = 1;
cleanup:
    sodium_memzero(key, sizeof(key));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
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
    const struct wls_upgrade *upgrade
) {
    wchar_t path[WLS_PATH_CHARS];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char to[2];
    char runtime[65];
    int consumed = 0;
    int result = 0;
    if (wls_join(path, WLS_PATH_CHARS, home, L"trust\\upgrade-healthy") == 0
        && wls_read_file(path, 512U, &contents, &length) == 0
        && sscanf(
            (const char *)contents,
            "WLS-UPGRADE-HEALTHY/1\nto=%1[AB]\nruntime_generation=%64[0-9a-f]\n%n",
            to,
            runtime,
            &consumed
        ) == 2
        && consumed == (int)length
        && (wchar_t)to[0] == upgrade->to
        && strcmp(runtime, upgrade->runtime_generation) == 0) {
        result = 1;
    }
    if (contents != NULL) HeapFree(GetProcessHeap(), 0U, contents);
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
    char nonce[33];
    int consumed = 0;
    int result = 0;
    if (wls_join(
        path,
        WLS_PATH_CHARS,
        home,
        L"state\\upgrade-rollback.request"
    ) == 0
        && wls_read_file(path, 512U, &contents, &length) == 0
        && sscanf(
            (const char *)contents,
            "WLS-UPGRADE-ROLLBACK/1\nfrom=%1[AB]\nto=%1[AB]\nat=%lld\nnonce=%32[0-9a-f]\n%n",
            from,
            to,
            &at,
            nonce,
            &consumed
        ) == 4
        && consumed == (int)length
        && (wchar_t)from[0] == upgrade->to
        && (wchar_t)to[0] == upgrade->from
        && at > 0 && wls_is_hex(nonce, 32U)) {
        result = 1;
    }
    if (contents != NULL) HeapFree(GetProcessHeap(), 0U, contents);
    return result;
}

static int wls_reconcile_upgrade(
    const wchar_t *home,
    wchar_t active[2],
    int count_candidate_failure
)
{
    struct wls_upgrade upgrade;
    wchar_t attempts_path[WLS_PATH_CHARS];
    wchar_t active_path[WLS_PATH_CHARS];
    wchar_t previous_path[WLS_PATH_CHARS];
    wchar_t intent_path[WLS_PATH_CHARS];
    wchar_t healthy_path[WLS_PATH_CHARS];
    wchar_t rollback_path[WLS_PATH_CHARS];
    wchar_t retention_path[WLS_PATH_CHARS];
    wchar_t rolled_back_path[WLS_PATH_CHARS];
    unsigned char *attempts_text = NULL;
    size_t attempts_length = 0U;
    char attempt_slot[2];
    long long first_at = 0;
    unsigned int attempts = 0U;
    long long now = (long long)time(NULL);
    char record[192];
    int rollback_requested;
    int state = wls_upgrade_intent(home, &upgrade);
    if (state < 0) return 1;
    if (state == 0 || !upgrade.present || active[0] == upgrade.from) return 0;
    if (active[0] != upgrade.to
        || wls_join(attempts_path, WLS_PATH_CHARS, home, L"trust\\upgrade-attempts") != 0
        || wls_join(active_path, WLS_PATH_CHARS, home, L"trust\\active-slot") != 0
        || wls_join(previous_path, WLS_PATH_CHARS, home, L"trust\\previous-slot") != 0
        || wls_join(intent_path, WLS_PATH_CHARS, home, L"trust\\upgrade.intent") != 0
        || wls_join(healthy_path, WLS_PATH_CHARS, home, L"trust\\upgrade-healthy") != 0
        || wls_join(retention_path, WLS_PATH_CHARS, home, L"trust\\slot-retention") != 0
        || wls_join(rolled_back_path, WLS_PATH_CHARS, home, L"trust\\upgrade-rolled-back") != 0
        || wls_join(rollback_path, WLS_PATH_CHARS, home, L"state\\upgrade-rollback.request") != 0) {
        return 1;
    }
    if (wls_upgrade_healthy(home, &upgrade)) {
        if (_snprintf_s(
            record,
            sizeof(record),
            _TRUNCATE,
            "WLS-SLOT-RETENTION/1\nslot=%c\nretain_until=%lld\n",
            (char)upgrade.from,
            now + 86400
        ) < 0 || wls_atomic_text(retention_path, record) != 0) {
            return 1;
        }
        DeleteFileW(intent_path);
        DeleteFileW(healthy_path);
        DeleteFileW(attempts_path);
        DeleteFileW(rollback_path);
        return 0;
    }
    rollback_requested = wls_upgrade_rollback_requested(home, &upgrade);
    if (!count_candidate_failure
        && now <= upgrade.deadline
        && !rollback_requested) {
        return 0;
    }
    if (count_candidate_failure
        && wls_read_file(attempts_path, 256U, &attempts_text, &attempts_length) == 0) {
        int consumed = 0;
        if (sscanf(
            (const char *)attempts_text,
            "WLS-UPGRADE-ATTEMPTS/1\nslot=%1[AB]\nfirst_at=%lld\nattempts=%u\n%n",
            attempt_slot,
            &first_at,
            &attempts,
            &consumed
        ) != 3
            || consumed != (int)attempts_length
            || (wchar_t)attempt_slot[0] != upgrade.to
            || first_at < upgrade.prepared_at
            || now - first_at > 300) {
            attempts = 0U;
            first_at = now;
        }
        HeapFree(GetProcessHeap(), 0U, attempts_text);
    } else if (count_candidate_failure) {
        first_at = now;
    }
    if (count_candidate_failure) {
        attempts++;
    }
    if (now > upgrade.deadline
        || rollback_requested
        || (count_candidate_failure && attempts >= 3U)) {
        char active_text[3] = {(char)upgrade.from, '\n', '\0'};
        char previous_text[3] = {(char)upgrade.to, '\n', '\0'};
        if (wls_atomic_text(active_path, active_text) != 0
            || wls_atomic_text(previous_path, previous_text) != 0
            || _snprintf_s(
                record,
                sizeof(record),
                _TRUNCATE,
                "WLS-UPGRADE-ROLLED-BACK/1\nslot=%c\nat=%lld\n",
                (char)upgrade.to,
                now
            ) < 0
            || wls_atomic_text(rolled_back_path, record) != 0) {
            return 1;
        }
        active[0] = upgrade.from;
        DeleteFileW(intent_path);
        DeleteFileW(healthy_path);
        DeleteFileW(attempts_path);
        DeleteFileW(rollback_path);
        return 0;
    }
    if (!count_candidate_failure) {
        return 0;
    }
    if (_snprintf_s(
        record,
        sizeof(record),
        _TRUNCATE,
        "WLS-UPGRADE-ATTEMPTS/1\nslot=%c\nfirst_at=%lld\nattempts=%u\n",
        (char)upgrade.to,
        first_at,
        attempts
    ) < 0) {
        return 1;
    }
    return wls_atomic_text(attempts_path, record);
}

/*
 * Runtime monitoring never increments the candidate crash counter. Candidate
 * failures are recorded only after an unexpected broker exit; clean platform
 * stop/start cycles therefore preserve the whole observation budget.
 */
static int wls_monitor_upgrade(const wchar_t *home, wchar_t active[2])
{
    struct wls_upgrade upgrade;
    int state = wls_upgrade_intent(home, &upgrade);
    long long now = (long long)time(NULL);
    wchar_t before;
    if (state < 0) return -1;
    if (state == 0 || !upgrade.present || active[0] == upgrade.from) return 0;
    if (active[0] != upgrade.to) return -1;
    if (!wls_upgrade_healthy(home, &upgrade)
        && now <= upgrade.deadline
        && !wls_upgrade_rollback_requested(home, &upgrade)) {
        return 0;
    }
    before = active[0];
    if (wls_reconcile_upgrade(home, active, 0) != 0) return -1;
    return active[0] == before ? 0 : 1;
}

static HANDLE wls_open_verified_job_nginx(
    const wchar_t *home,
    HANDLE job,
    DWORD expected_pid,
    DWORD *verified_pid
) {
    wchar_t pid_path[WLS_PATH_CHARS];
    wchar_t actual[WLS_PATH_CHARS];
    wchar_t expected_a[WLS_PATH_CHARS];
    wchar_t expected_b[WLS_PATH_CHARS];
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
    unsigned long parsed;
    DWORD actual_length = WLS_PATH_CHARS;
    BOOL belongs = FALSE;
    HANDLE process = NULL;
    const wchar_t *slot_name = NULL;
    if (verified_pid == NULL
        || wls_join(pid_path, WLS_PATH_CHARS, home, L"runtime\\run\\nginx.pid") != 0
        || wls_read_file(pid_path, 64U, &pid_text, &pid_length) != 0) {
        return NULL;
    }
    while (pid_length > 0U
        && strchr("\r\n \t", ((char *)pid_text)[pid_length - 1U]) != NULL) {
        pid_length--;
    }
    ((char *)pid_text)[pid_length] = '\0';
    parsed = strtoul((const char *)pid_text, &end, 10);
    HeapFree(GetProcessHeap(), 0U, pid_text);
    if (pid_length == 0U || end == NULL || *end != '\0'
        || parsed == 0UL || parsed > MAXDWORD
        || (expected_pid > 0U && expected_pid != (DWORD)parsed)
        || _snwprintf_s(expected_a, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\A\\bin\\nginx.exe", home) < 0
        || _snwprintf_s(expected_b, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\B\\bin\\nginx.exe", home) < 0) {
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
    if (_wcsicmp(actual, expected_a) == 0) slot_name = L"A";
    if (_wcsicmp(actual, expected_b) == 0) slot_name = L"B";
    if (slot_name == NULL
        || _snwprintf_s(slot_path, WLS_PATH_CHARS, _TRUNCATE,
            L"%ls\\slots\\%ls", home, slot_name) < 0
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
            "runtime_generation", runtime_generation) != 0) {
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
    wchar_t command[WLS_PATH_CHARS * 4U];
    unsigned char *release_manifest = NULL;
    size_t release_manifest_length = 0U;
    unsigned char *installed_manifest = NULL;
    size_t installed_manifest_length = 0U;
    char runtime_generation[65];
    wchar_t runtime_generation_wide[65];
    STARTUPINFOW startup;
    PROCESS_INFORMATION process;
    HANDLE stop_event = NULL;
    HANDLE verified_nginx = NULL;
    DWORD broker_exit = 1U;
    DWORD verified_nginx_pid = 0U;
    ULONGLONG stop_started = 0U;
    if (preserved_nginx_pid == NULL || job == NULL) return 1;
    *preserved_nginx_pid = 0U;
    if (wls_admin_stopped(home) != 0) return 0;
    if (wls_active_slot(home, active) != 0
        || wls_reconcile_upgrade(home, active, 0) != 0
        || _snwprintf_s(
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
        || wls_join(fencing, WLS_PATH_CHARS, run_directory, L"fencing-token") != 0
        || _snwprintf_s(
            stop_event_name,
            sizeof(stop_event_name) / sizeof(stop_event_name[0]),
            _TRUNCATE,
            L"Local\\WelineWlsGatewayV2Stop-%lu",
            (unsigned long)GetCurrentProcessId()
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
            L"--stop-event \"%ls\" --adopted-nginx-pid \"%lu\"",
            broker,
            fencing,
            php,
            controller,
            home,
            active,
            runtime_generation_wide,
            stop_event_name,
            (unsigned long)adopted_nginx_pid
        ) < 0) {
        if (release_manifest != NULL) HeapFree(GetProcessHeap(), 0U, release_manifest);
        if (installed_manifest != NULL) HeapFree(GetProcessHeap(), 0U, installed_manifest);
        return 1;
    }
    HeapFree(GetProcessHeap(), 0U, release_manifest);
    HeapFree(GetProcessHeap(), 0U, installed_manifest);
    ZeroMemory(&startup, sizeof(startup));
    ZeroMemory(&process, sizeof(process));
    startup.cb = sizeof(startup);
    stop_event = CreateEventW(NULL, TRUE, FALSE, stop_event_name);
    if (stop_event == NULL) return 1;
    if (adopted_nginx_pid > 0U) {
        verified_nginx = wls_open_verified_job_nginx(
            home,
            job,
            adopted_nginx_pid,
            &verified_nginx_pid
        );
        if (verified_nginx == NULL || verified_nginx_pid != adopted_nginx_pid) {
            if (verified_nginx != NULL) CloseHandle(verified_nginx);
            CloseHandle(stop_event);
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
        return 1;
    }
    if (!AssignProcessToJobObject(job, process.hProcess)
        || ResumeThread(process.hThread) == (DWORD)-1) {
        TerminateProcess(process.hProcess, 1U);
        WaitForSingleObject(process.hProcess, 5000U);
        CloseHandle(process.hThread);
        CloseHandle(process.hProcess);
        CloseHandle(stop_event);
        return 1;
    }
    CloseHandle(process.hThread);
    wls_broker_process = process.hProcess;
    wls_broker_stop_event = stop_event;
    for (;;) {
        DWORD wait_result = WaitForSingleObject(process.hProcess, 200U);
        int upgrade_state;
        if (wait_result == WAIT_OBJECT_0) {
            wchar_t before = active[0];
            GetExitCodeProcess(process.hProcess, &broker_exit);
            if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0
                && InterlockedCompareExchange(&wls_service_reload_requested, 0, 0) == 0
                && wls_admin_stopped(home) == 0) {
                if (wls_reconcile_upgrade(home, active, 1) != 0) {
                    broker_exit = 1U;
                } else if (active[0] != before) {
                    broker_exit = WLS_CONTROL_TREE_RELOAD;
                }
            }
            break;
        }
        if (wait_result != WAIT_TIMEOUT) {
            TerminateProcess(process.hProcess, 1U);
            WaitForSingleObject(process.hProcess, 5000U);
            broker_exit = 1U;
            break;
        }
        if (InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) != 0) {
            if (stop_started == 0U) stop_started = GetTickCount64();
            if ((GetTickCount64() - stop_started) >= 15000U) {
                TerminateProcess(process.hProcess, 1U);
                WaitForSingleObject(process.hProcess, 5000U);
                broker_exit = 1U;
                break;
            }
        }
        upgrade_state = wls_monitor_upgrade(home, active);
        if (upgrade_state < 0) {
            TerminateProcess(process.hProcess, 1U);
            WaitForSingleObject(process.hProcess, 5000U);
            broker_exit = 1U;
            break;
        }
        if (upgrade_state > 0) {
            /* Keep the service PID and shared Job while rebuilding the old slot. */
            SetEvent(stop_event);
            if (WaitForSingleObject(process.hProcess, 5000U) != WAIT_OBJECT_0) {
                TerminateProcess(process.hProcess, WLS_CONTROL_TREE_RELOAD);
                WaitForSingleObject(process.hProcess, 5000U);
            }
            broker_exit = WLS_CONTROL_TREE_RELOAD;
            break;
        }
    }
    if ((broker_exit == WLS_CONTROL_TREE_RELOAD
            || InterlockedCompareExchange(&wls_service_reload_requested, 0, 0) != 0)
        && InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0
        && wls_admin_stopped(home) == 0) {
        verified_nginx = wls_open_verified_job_nginx(
            home,
            job,
            0U,
            &verified_nginx_pid
        );
        if (verified_nginx == NULL) {
            broker_exit = 1U;
        } else {
            CloseHandle(verified_nginx);
            *preserved_nginx_pid = verified_nginx_pid;
            broker_exit = WLS_CONTROL_TREE_RELOAD;
        }
    }
    wls_broker_stop_event = NULL;
    CloseHandle(process.hProcess);
    wls_broker_process = NULL;
    CloseHandle(stop_event);
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
    wls_service_status.dwCheckPoint = 0U;
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
        wls_report_service(SERVICE_STOP_PENDING, NO_ERROR, 0U);
        InterlockedExchange(&wls_service_stop_requested, 1);
        if (wls_broker_stop_event != NULL) SetEvent(wls_broker_stop_event);
    } else if (control == SERVICE_CONTROL_PARAMCHANGE
        && InterlockedCompareExchange(&wls_service_stop_requested, 0, 0) == 0) {
        InterlockedExchange(&wls_service_reload_requested, 1);
        if (wls_broker_stop_event != NULL) SetEvent(wls_broker_stop_event);
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
        InterlockedExchange(&wls_service_reload_requested, 0);
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
    wls_report_service(SERVICE_START_PENDING, NO_ERROR, 0U);
    wls_report_service(SERVICE_RUNNING, NO_ERROR, 0U);
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
    if (argc == 2 && wcscmp(argv[1], L"--self-test") == 0) return 0;
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
        return StartServiceCtrlDispatcherW(dispatch) ? 0 : 1;
    }
    return wls_run_supervisor(home, run_directory);
}
