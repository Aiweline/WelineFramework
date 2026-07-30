#include <sys/types.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <fcntl.h>
#include <sodium.h>
#include <errno.h>
#include <limits.h>
#include <signal.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

#ifndef WLS_RELEASE_PUBLIC_KEY_HEX
#error "WLS_RELEASE_PUBLIC_KEY_HEX must be defined by the release build"
#endif

#define WLS_MAX_MANIFEST (4U * 1024U * 1024U)
#define WLS_SIGNATURE_TEXT 256U
#define WLS_CONTROL_TREE_RELOAD 254

struct wls_upgrade {
    int present;
    char from;
    char to;
    long long prepared_at;
    long long deadline;
    char runtime_generation[65];
};

static volatile sig_atomic_t wls_shutdown_signal = 0;

static void wls_capture_shutdown_signal(int signal_number)
{
    wls_shutdown_signal = signal_number;
}

static int wls_join(char *output, size_t capacity, const char *left, const char *right)
{
    int length = snprintf(output, capacity, "%s/%s", left, right);
    return length > 0 && length < (int)capacity ? 0 : -1;
}

static int wls_read_file(
    const char *path,
    size_t maximum,
    unsigned char **contents,
    size_t *length
) {
    struct stat status;
    unsigned char *buffer;
    size_t used = 0U;
    int fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || fstat(fd, &status) != 0 || !S_ISREG(status.st_mode)
        || status.st_size < 0 || (uint64_t)status.st_size > maximum) {
        if (fd >= 0) close(fd);
        return -1;
    }
    buffer = malloc((size_t)status.st_size + 1U);
    if (buffer == NULL) {
        close(fd);
        return -1;
    }
    while (used < (size_t)status.st_size) {
        ssize_t amount = read(fd, buffer + used, (size_t)status.st_size - used);
        if (amount <= 0) {
            free(buffer);
            close(fd);
            return -1;
        }
        used += (size_t)amount;
    }
    close(fd);
    buffer[used] = '\0';
    *contents = buffer;
    *length = used;
    return 0;
}

static int wls_public_key(unsigned char key[crypto_sign_PUBLICKEYBYTES])
{
    size_t decoded = 0U;
    const char *hex = WLS_RELEASE_PUBLIC_KEY_HEX;
    if (strlen(hex) != crypto_sign_PUBLICKEYBYTES * 2U
        || sodium_hex2bin(
            key,
            crypto_sign_PUBLICKEYBYTES,
            hex,
            strlen(hex),
            NULL,
            &decoded,
            NULL
        ) != 0
        || decoded != crypto_sign_PUBLICKEYBYTES) {
        return -1;
    }
    return 0;
}

static int wls_verify_release(
    const char *manifest_path,
    const char *signature_path,
    unsigned char **manifest,
    size_t *manifest_length
) {
    unsigned char public_key[crypto_sign_PUBLICKEYBYTES];
    unsigned char signature[crypto_sign_BYTES];
    unsigned char *signature_text = NULL;
    size_t signature_length = 0U;
    size_t decoded = 0U;
    int result = -1;
    if (wls_public_key(public_key) != 0
        || wls_read_file(
            signature_path,
            WLS_SIGNATURE_TEXT,
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
    free(signature_text);
    if (result != 0 && *manifest != NULL) {
        free(*manifest);
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
    char digest[crypto_hash_sha256_BYTES * 2U + 1U]
) {
    char needle[PATH_MAX];
    const char *start;
    const char *object_end;
    const char *sha;
    const char *quote;
    size_t length;
    if (snprintf(needle, sizeof(needle), "\"%s\"", component) >= (int)sizeof(needle)) {
        return -1;
    }
    start = strstr((const char *)manifest, needle);
    if (start == NULL) return -1;
    object_end = strchr(start, '}');
    sha = strstr(start, "\"sha256\"");
    if (object_end == NULL || sha == NULL || sha > object_end) return -1;
    sha = strchr(sha, ':');
    if (sha == NULL || sha > object_end) return -1;
    quote = strchr(sha, '"');
    if (quote == NULL || quote > object_end) return -1;
    quote++;
    length = strspn(quote, "0123456789abcdef");
    if (length != crypto_hash_sha256_BYTES * 2U || quote[length] != '"') return -1;
    memcpy(digest, quote, length);
    digest[length] = '\0';
    return 0;
}

static int wls_manifest_hex_value(
    const unsigned char *manifest,
    const char *field,
    char output[crypto_hash_sha256_BYTES * 2U + 1U]
) {
    char needle[128];
    const char *start;
    const char *colon;
    const char *quote;
    size_t length;
    if (snprintf(needle, sizeof(needle), "\"%s\"", field) >= (int)sizeof(needle)) {
        return -1;
    }
    start = strstr((const char *)manifest, needle);
    colon = start != NULL ? strchr(start, ':') : NULL;
    quote = colon != NULL ? strchr(colon, '"') : NULL;
    if (quote == NULL) return -1;
    quote++;
    length = strspn(quote, "0123456789abcdef");
    if (length != crypto_hash_sha256_BYTES * 2U || quote[length] != '"') return -1;
    memcpy(output, quote, length);
    output[length] = '\0';
    return 0;
}

static int wls_file_digest(const char *path, char digest[crypto_hash_sha256_BYTES * 2U + 1U])
{
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    unsigned char buffer[65536];
    ssize_t amount;
    int fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || crypto_hash_sha256_init(&state) != 0) {
        if (fd >= 0) close(fd);
        return -1;
    }
    while ((amount = read(fd, buffer, sizeof(buffer))) > 0) {
        if (crypto_hash_sha256_update(&state, buffer, (unsigned long long)amount) != 0) {
            close(fd);
            return -1;
        }
    }
    close(fd);
    if (amount < 0 || crypto_hash_sha256_final(&state, binary) != 0) return -1;
    sodium_bin2hex(digest, crypto_hash_sha256_BYTES * 2U + 1U, binary, sizeof(binary));
    sodium_memzero(binary, sizeof(binary));
    return 0;
}

static int wls_verify_component(
    const unsigned char *manifest,
    const char *slot,
    const char *relative
) {
    char expected[crypto_hash_sha256_BYTES * 2U + 1U];
    char actual[crypto_hash_sha256_BYTES * 2U + 1U];
    char path[PATH_MAX];
    if (wls_manifest_digest(manifest, relative, expected) != 0
        || wls_join(path, sizeof(path), slot, relative) != 0
        || wls_file_digest(path, actual) != 0
        || sodium_memcmp(expected, actual, crypto_hash_sha256_BYTES * 2U) != 0) {
        return -1;
    }
    return 0;
}

static const char *wls_argument(int argc, char **argv, const char *name)
{
    int index;
    size_t length = strlen(name);
    for (index = 1; index < argc; index++) {
        if (strncmp(argv[index], name, length) == 0 && argv[index][length] == '=') {
            return argv[index] + length + 1U;
        }
        if (strcmp(argv[index], name) == 0 && index + 1 < argc) {
            return argv[index + 1];
        }
    }
    return NULL;
}

static int wls_active_slot(const char *home, char slot[2])
{
    char path[PATH_MAX];
    char contents[4];
    ssize_t amount;
    int fd;
    if (wls_join(path, sizeof(path), home, "trust/active-slot") != 0) return -1;
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0) return -1;
    amount = read(fd, contents, sizeof(contents));
    close(fd);
    if (amount < 1 || (contents[0] != 'A' && contents[0] != 'B')) return -1;
    slot[0] = contents[0];
    slot[1] = '\0';
    return 0;
}

/*
 * Any no-follow regular ADMIN_STOPPED intent blocks automatic launch. A valid
 * HMAC is reported separately for diagnostics; a damaged intent still fails
 * closed without entering a platform restart loop.
 */
static int wls_admin_stopped(const char *home)
{
    char intent_path[PATH_MAX];
    char token_path[PATH_MAX];
    struct stat status;
    unsigned char *intent = NULL;
    unsigned char *token_text = NULL;
    size_t intent_length = 0U;
    size_t token_length = 0U;
    char *signature_line;
    char *signature_end;
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char expected[crypto_auth_hmacsha256_BYTES];
    unsigned char actual[crypto_auth_hmacsha256_BYTES];
    size_t decoded = 0U;
    int verified = 0;
    if (wls_join(intent_path, sizeof(intent_path), home, "trust/admin-stopped.intent") != 0
        || wls_join(token_path, sizeof(token_path), home, "trust/admin.token") != 0) {
        return 1;
    }
    if (lstat(intent_path, &status) != 0) {
        return errno == ENOENT ? 0 : 1;
    }
    if (!S_ISREG(status.st_mode) || S_ISLNK(status.st_mode)
        || wls_read_file(intent_path, 4096U, &intent, &intent_length) != 0
        || wls_read_file(token_path, 256U, &token_text, &token_length) != 0) {
        fprintf(stderr, "ADMIN_STOPPED intent is unsafe; automatic launch remains blocked\n");
        free(intent);
        free(token_text);
        return 1;
    }
    while (token_length > 0U
        && (token_text[token_length - 1U] == '\n'
            || token_text[token_length - 1U] == '\r'
            || token_text[token_length - 1U] == ' '
            || token_text[token_length - 1U] == '\t')) {
        token_length--;
    }
    token_text[token_length] = '\0';
    signature_line = strstr((char *)intent, "signature=");
    signature_end = signature_line != NULL ? strchr(signature_line, '\n') : NULL;
    if (signature_line != NULL
        && signature_end != NULL
        && signature_end[1] == '\0'
        && (size_t)(signature_end - signature_line - 10)
            == crypto_auth_hmacsha256_BYTES * 2U
        && sodium_hex2bin(
            key,
            sizeof(key),
            (const char *)token_text,
            token_length,
            NULL,
            &decoded,
            NULL
        ) == 0
        && decoded == sizeof(key)
        && sodium_hex2bin(
            actual,
            sizeof(actual),
            signature_line + 10,
            crypto_auth_hmacsha256_BYTES * 2U,
            NULL,
            &decoded,
            NULL
        ) == 0
        && decoded == sizeof(actual)
        && crypto_auth_hmacsha256(
            expected,
            intent,
            (unsigned long long)(signature_line - (char *)intent),
            key
        ) == 0
        && sodium_memcmp(expected, actual, sizeof(expected)) == 0) {
        verified = 1;
    }
    fprintf(
        stderr,
        verified
            ? "signed ADMIN_STOPPED intent blocks automatic gateway launch\n"
            : "invalid ADMIN_STOPPED intent blocks automatic launch pending administrator repair\n"
    );
    sodium_memzero(key, sizeof(key));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
    sodium_memzero(token_text, token_length);
    free(intent);
    free(token_text);
    return 1;
}

static int wls_is_hex_text(const char *value, size_t length)
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

static int wls_atomic_text(const char *path, const char *contents, mode_t mode)
{
    char temporary[PATH_MAX];
    char parent_path[PATH_MAX];
    char *slash;
    struct stat parent;
    int fd;
    size_t length = strlen(contents);
    if (snprintf(
        temporary,
        sizeof(temporary),
        "%s.candidate.%ld",
        path,
        (long)getpid()
    ) >= (int)sizeof(temporary)) {
        return -1;
    }
    fd = open(temporary, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (strlen(path) >= sizeof(parent_path)) {
        return -1;
    }
    memcpy(parent_path, path, strlen(path) + 1U);
    slash = strrchr(parent_path, '/');
    if (slash == NULL) return -1;
    *slash = '\0';
    if (fd < 0
        || stat(parent_path, &parent) != 0
        || write(fd, contents, length) != (ssize_t)length
        || fsync(fd) != 0
        || fchown(fd, parent.st_uid, parent.st_gid) != 0
        || fchmod(fd, mode) != 0) {
        if (fd >= 0) close(fd);
        unlink(temporary);
        return -1;
    }
    close(fd);
    if (rename(temporary, path) != 0) {
        unlink(temporary);
        return -1;
    }
    return 0;
}

static int wls_upgrade_intent(
    const char *home,
    struct wls_upgrade *upgrade
) {
    char intent_path[PATH_MAX];
    char token_path[PATH_MAX];
    char host_path[PATH_MAX];
    unsigned char *intent = NULL;
    unsigned char *token_text = NULL;
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
    memset(upgrade, 0, sizeof(*upgrade));
    if (wls_join(intent_path, sizeof(intent_path), home, "trust/upgrade.intent") != 0
        || wls_join(token_path, sizeof(token_path), home, "trust/admin.token") != 0
        || wls_join(host_path, sizeof(host_path), home, "trust/host-id") != 0) {
        return -1;
    }
    if (access(intent_path, F_OK) != 0) {
        return errno == ENOENT ? 0 : -1;
    }
    if (wls_read_file(intent_path, 2048U, &intent, &intent_length) != 0
        || wls_read_file(token_path, 256U, &token_text, &token_length) != 0
        || wls_read_file(host_path, 256U, &host_text, &host_length) != 0) {
        goto cleanup;
    }
    while (token_length > 0U
        && (token_text[token_length - 1U] == '\n'
            || token_text[token_length - 1U] == '\r'
            || token_text[token_length - 1U] == ' '
            || token_text[token_length - 1U] == '\t')) {
        token_length--;
    }
    token_text[token_length] = '\0';
    while (host_length > 0U
        && (host_text[host_length - 1U] == '\n'
            || host_text[host_length - 1U] == '\r'
            || host_text[host_length - 1U] == ' '
            || host_text[host_length - 1U] == '\t')) {
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
    if (fields != 8
        || consumed != (int)intent_length
        || from[0] == to[0]
        || upgrade->prepared_at < 1
        || upgrade->deadline != upgrade->prepared_at + 300
        || !wls_is_hex_text(host, 32U)
        || host_length != 32U
        || sodium_memcmp(host, host_text, 32U) != 0
        || !wls_is_hex_text(upgrade->runtime_generation, 64U)
        || !wls_is_hex_text(nonce, 32U)
        || !wls_is_hex_text(signature_hex, 64U)
        || signature_line == NULL
        || sodium_hex2bin(
            key,
            sizeof(key),
            (const char *)token_text,
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
    upgrade->from = from[0];
    upgrade->to = to[0];
    result = 1;
cleanup:
    sodium_memzero(key, sizeof(key));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
    if (token_text != NULL) sodium_memzero(token_text, token_length);
    free(intent);
    free(token_text);
    free(host_text);
    return result;
}

static int wls_upgrade_healthy(const char *home, const struct wls_upgrade *upgrade)
{
    char path[PATH_MAX];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char to[2];
    char runtime[65];
    int consumed = 0;
    int result = 0;
    if (wls_join(path, sizeof(path), home, "trust/upgrade-healthy") != 0
        || wls_read_file(path, 512U, &contents, &length) != 0) {
        return 0;
    }
    if (sscanf(
        (const char *)contents,
        "WLS-UPGRADE-HEALTHY/1\nto=%1[AB]\nruntime_generation=%64[0-9a-f]\n%n",
        to,
        runtime,
        &consumed
    ) == 2
        && consumed == (int)length
        && to[0] == upgrade->to
        && strcmp(runtime, upgrade->runtime_generation) == 0) {
        result = 1;
    }
    free(contents);
    return result;
}

static int wls_upgrade_rollback_requested(
    const char *home,
    const struct wls_upgrade *upgrade
) {
    char path[PATH_MAX];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char from[2];
    char to[2];
    long long at = 0;
    char nonce[33];
    int consumed = 0;
    int result = 0;
    if (wls_join(path, sizeof(path), home, "state/upgrade-rollback.request") != 0
        || wls_read_file(path, 512U, &contents, &length) != 0) {
        return 0;
    }
    if (sscanf(
        (const char *)contents,
        "WLS-UPGRADE-ROLLBACK/1\nfrom=%1[AB]\nto=%1[AB]\nat=%lld\nnonce=%32[0-9a-f]\n%n",
        from,
        to,
        &at,
        nonce,
        &consumed
    ) == 4
        && consumed == (int)length
        && from[0] == upgrade->to
        && to[0] == upgrade->from
        && at > 0
        && wls_is_hex_text(nonce, 32U)) {
        result = 1;
    }
    free(contents);
    return result;
}

static int wls_reconcile_upgrade(
    const char *home,
    char active[2],
    int count_candidate_failure
)
{
    struct wls_upgrade upgrade;
    char attempts_path[PATH_MAX];
    char active_path[PATH_MAX];
    char previous_path[PATH_MAX];
    char intent_path[PATH_MAX];
    char healthy_path[PATH_MAX];
    char rollback_path[PATH_MAX];
    char retention_path[PATH_MAX];
    char rolled_back_path[PATH_MAX];
    unsigned char *attempts_text = NULL;
    size_t attempts_length = 0U;
    char attempt_slot[2];
    long long first_at = 0;
    unsigned int attempts = 0U;
    long long now = (long long)time(NULL);
    char record[128];
    int rollback_requested;
    int state = wls_upgrade_intent(home, &upgrade);
    if (state < 0) {
        fprintf(stderr, "invalid signed upgrade intent blocks automatic launch\n");
        return -1;
    }
    if (state == 0 || !upgrade.present) return 0;
    if (active[0] == upgrade.from) {
        return 0;
    }
    if (active[0] != upgrade.to
        || wls_join(attempts_path, sizeof(attempts_path), home, "trust/upgrade-attempts") != 0
        || wls_join(active_path, sizeof(active_path), home, "trust/active-slot") != 0
        || wls_join(previous_path, sizeof(previous_path), home, "trust/previous-slot") != 0
        || wls_join(intent_path, sizeof(intent_path), home, "trust/upgrade.intent") != 0
        || wls_join(healthy_path, sizeof(healthy_path), home, "trust/upgrade-healthy") != 0
        || wls_join(retention_path, sizeof(retention_path), home, "trust/slot-retention") != 0
        || wls_join(rolled_back_path, sizeof(rolled_back_path), home, "trust/upgrade-rolled-back") != 0
        || wls_join(rollback_path, sizeof(rollback_path), home, "state/upgrade-rollback.request") != 0) {
        return -1;
    }
    if (wls_upgrade_healthy(home, &upgrade)) {
        if (snprintf(
            record,
            sizeof(record),
            "WLS-SLOT-RETENTION/1\nslot=%c\nretain_until=%lld\n",
            upgrade.from,
            now + 86400
        ) >= (int)sizeof(record)
            || wls_atomic_text(retention_path, record, 0600) != 0) {
            return -1;
        }
        unlink(intent_path);
        unlink(healthy_path);
        unlink(attempts_path);
        unlink(rollback_path);
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
            || attempt_slot[0] != upgrade.to
            || first_at < upgrade.prepared_at
            || now - first_at > 300) {
            attempts = 0U;
            first_at = now;
        }
        free(attempts_text);
    } else if (count_candidate_failure) {
        first_at = now;
    }
    if (count_candidate_failure) {
        attempts++;
    }
    if (now > upgrade.deadline
        || rollback_requested
        || (count_candidate_failure && attempts >= 3U)) {
        char slot_text[3] = {upgrade.from, '\n', '\0'};
        char previous_text[3] = {upgrade.to, '\n', '\0'};
        if (wls_atomic_text(active_path, slot_text, 0640) != 0
            || wls_atomic_text(previous_path, previous_text, 0640) != 0) {
            return -1;
        }
        if (snprintf(
            record,
            sizeof(record),
            "WLS-UPGRADE-ROLLED-BACK/1\nslot=%c\nat=%lld\n",
            upgrade.to,
            now
        ) >= (int)sizeof(record)
            || wls_atomic_text(rolled_back_path, record, 0600) != 0) {
            return -1;
        }
        active[0] = upgrade.from;
        unlink(intent_path);
        unlink(healthy_path);
        unlink(attempts_path);
        unlink(rollback_path);
        fprintf(stderr, "gateway candidate slot rolled back by stable launcher\n");
        return 0;
    }
    if (!count_candidate_failure) {
        return 0;
    }
    if (snprintf(
        record,
        sizeof(record),
        "WLS-UPGRADE-ATTEMPTS/1\nslot=%c\nfirst_at=%lld\nattempts=%u\n",
        upgrade.to,
        first_at,
        attempts
    ) >= (int)sizeof(record)
        || wls_atomic_text(attempts_path, record, 0600) != 0) {
        return -1;
    }
    return 0;
}

/*
 * Runtime monitoring never increments the candidate crash counter. Candidate
 * failures are recorded only after an unexpected broker exit; clean platform
 * stop/start cycles therefore preserve the whole observation budget.
 */
static int wls_monitor_upgrade(const char *home, char active[2])
{
    struct wls_upgrade upgrade;
    int state = wls_upgrade_intent(home, &upgrade);
    long long now = (long long)time(NULL);
    char before;
    if (state < 0) {
        fprintf(stderr, "invalid signed upgrade intent blocks gateway supervision\n");
        return -1;
    }
    if (state == 0 || !upgrade.present || active[0] == upgrade.from) {
        return 0;
    }
    if (active[0] != upgrade.to) {
        return -1;
    }
    if (!wls_upgrade_healthy(home, &upgrade)
        && now <= upgrade.deadline
        && !wls_upgrade_rollback_requested(home, &upgrade)) {
        return 0;
    }
    before = active[0];
    if (wls_reconcile_upgrade(home, active, 0) != 0) {
        return -1;
    }
    return active[0] == before ? 0 : 1;
}

static void wls_terminate_broker(pid_t broker_pid, int signal_number)
{
    unsigned int attempt;
    int status = 0;
    if (broker_pid <= 0) return;
    kill(broker_pid, signal_number > 0 ? signal_number : SIGTERM);
    for (attempt = 0U; attempt < 50U; attempt++) {
        pid_t waited = waitpid(broker_pid, &status, WNOHANG);
        if (waited == broker_pid || (waited < 0 && errno == ECHILD)) {
            return;
        }
        usleep(100000);
    }
    kill(broker_pid, SIGKILL);
    while (waitpid(broker_pid, &status, 0) < 0 && errno == EINTR) {
    }
}

static int wls_supervise_broker(pid_t broker_pid, const char *home, char active[2])
{
    struct sigaction action;
    struct timespec pause = {0, 200000000L};
    int status = 0;
    memset(&action, 0, sizeof(action));
    action.sa_handler = wls_capture_shutdown_signal;
    sigemptyset(&action.sa_mask);
    if (sigaction(SIGTERM, &action, NULL) != 0
        || sigaction(SIGINT, &action, NULL) != 0
        || sigaction(SIGHUP, &action, NULL) != 0) {
        wls_terminate_broker(broker_pid, SIGTERM);
        return 1;
    }
    for (;;) {
        pid_t waited;
        int upgrade_state;
        if (wls_shutdown_signal != 0) {
            int signal_number = (int)wls_shutdown_signal;
            wls_shutdown_signal = 0;
            wls_terminate_broker(
                broker_pid,
                signal_number == SIGHUP ? SIGTERM : signal_number
            );
            return signal_number == SIGHUP ? WLS_CONTROL_TREE_RELOAD : 0;
        }
        waited = waitpid(broker_pid, &status, WNOHANG);
        if (waited == broker_pid) {
            char before = active[0];
            int exit_code = WIFEXITED(status)
                ? WEXITSTATUS(status)
                : (WIFSIGNALED(status) ? 128 + WTERMSIG(status) : 1);
            if (wls_admin_stopped(home) == 0
                && wls_reconcile_upgrade(home, active, 1) != 0) {
                return 1;
            }
            return active[0] == before ? exit_code : WLS_CONTROL_TREE_RELOAD;
        }
        if (waited < 0 && errno != EINTR) {
            return 1;
        }
        upgrade_state = wls_monitor_upgrade(home, active);
        if (upgrade_state < 0) {
            wls_terminate_broker(broker_pid, SIGTERM);
            return 1;
        }
        if (upgrade_state > 0) {
            /* Keep the stable launcher PID while rebuilding the verified slot. */
            wls_terminate_broker(broker_pid, SIGTERM);
            return WLS_CONTROL_TREE_RELOAD;
        }
        while (nanosleep(&pause, &pause) != 0 && errno == EINTR) {
            if (wls_shutdown_signal != 0) break;
        }
        pause.tv_sec = 0;
        pause.tv_nsec = 200000000L;
    }
}

static int wls_launch(const char *home, const char *run_directory)
{
    char active[2];
    char slot[PATH_MAX];
    char manifest_path[PATH_MAX];
    char signature_path[PATH_MAX];
    char installed_manifest_path[PATH_MAX];
    char broker[PATH_MAX];
    char php[PATH_MAX];
    char controller[PATH_MAX];
    char admin_socket[PATH_MAX];
    char project_socket[PATH_MAX];
    char controller_socket[PATH_MAX];
    char lock_file[PATH_MAX];
    char fencing_file[PATH_MAX];
    char runtime_generation[crypto_hash_sha256_BYTES * 2U + 1U];
    unsigned char *manifest = NULL;
    size_t manifest_length = 0U;
    unsigned char *installed_manifest = NULL;
    size_t installed_manifest_length = 0U;
    const char *controller_user =
#if defined(__APPLE__)
        "_welinegateway";
#else
        "weline-gateway";
#endif
    pid_t broker_pid;
    if (wls_admin_stopped(home) != 0) {
        return 0;
    }
    if (wls_active_slot(home, active) != 0
        || wls_reconcile_upgrade(home, active, 0) != 0
        || snprintf(slot, sizeof(slot), "%s/slots/%s", home, active) >= (int)sizeof(slot)
        || wls_join(manifest_path, sizeof(manifest_path), slot, "release/manifest.json") != 0
        || wls_join(signature_path, sizeof(signature_path), slot, "release/manifest.sig") != 0
        || wls_join(
            installed_manifest_path,
            sizeof(installed_manifest_path),
            slot,
            "manifest.json"
        ) != 0
        || wls_verify_release(
            manifest_path,
            signature_path,
            &manifest,
            &manifest_length
        ) != 0
        || wls_verify_component(manifest, slot, "bin/wls-gateway-broker") != 0
        || wls_verify_component(manifest, slot, "bin/php") != 0
        || wls_verify_component(manifest, slot, "bin/nginx") != 0
        || wls_verify_component(manifest, slot, "app/controller.php") != 0
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
        || wls_join(broker, sizeof(broker), slot, "bin/wls-gateway-broker") != 0
        || wls_join(php, sizeof(php), slot, "bin/php") != 0
        || wls_join(controller, sizeof(controller), slot, "app/controller.php") != 0
        || wls_join(admin_socket, sizeof(admin_socket), run_directory, "admin.sock") != 0
        || wls_join(project_socket, sizeof(project_socket), run_directory, "project.sock") != 0
        || wls_join(controller_socket, sizeof(controller_socket), run_directory, "controller.sock") != 0
        || wls_join(lock_file, sizeof(lock_file), run_directory, "broker.lock") != 0
        || wls_join(fencing_file, sizeof(fencing_file), run_directory, "fencing-token") != 0) {
        free(manifest);
        free(installed_manifest);
        return 1;
    }
    free(manifest);
    free(installed_manifest);
    broker_pid = fork();
    if (broker_pid < 0) return 1;
    if (broker_pid == 0) {
        signal(SIGTERM, SIG_DFL);
        signal(SIGINT, SIG_DFL);
        signal(SIGHUP, SIG_DFL);
        execl(
            broker,
            broker,
            "--serve",
            "--admin-socket",
            admin_socket,
            "--project-socket",
            project_socket,
            "--controller-socket",
            controller_socket,
            "--lock-file",
            lock_file,
            "--fencing-file",
            fencing_file,
            "--php",
            php,
            "--controller",
            controller,
            "--home",
            home,
            "--controller-user",
            controller_user,
            "--active-slot",
            active,
            "--runtime-generation",
            runtime_generation,
            (char *)NULL
        );
        _exit(127);
    }
    return wls_supervise_broker(broker_pid, home, active);
}

int main(int argc, char **argv)
{
    const char *home;
    const char *run_directory;
    unsigned char key[crypto_sign_PUBLICKEYBYTES];
    if (sodium_init() < 0 || wls_public_key(key) != 0) {
        return 1;
    }
    sodium_memzero(key, sizeof(key));
    if (argc == 2 && strcmp(argv[1], "--self-test") == 0) {
        return 0;
    }
    home = wls_argument(argc, argv, "--home");
    run_directory = wls_argument(argc, argv, "--run");
    if (home == NULL || run_directory == NULL || home[0] != '/' || run_directory[0] != '/') {
        fprintf(stderr, "stable launcher requires absolute --home and --run paths\n");
        return 64;
    }
    for (;;) {
        int result;
        wls_shutdown_signal = 0;
        result = wls_launch(home, run_directory);
        if (result != WLS_CONTROL_TREE_RELOAD) return result;
    }
}
