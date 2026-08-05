#include <sys/types.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <sys/file.h>
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

#if defined(__APPLE__)
#include <sys/sysctl.h>
#include <sys/time.h>
#endif
#if defined(__linux__)
#include <sys/prctl.h>
#endif

#ifndef WLS_RELEASE_PUBLIC_KEY_HEX
#error "WLS_RELEASE_PUBLIC_KEY_HEX must be defined by the release build"
#endif

#define WLS_MAX_MANIFEST (4U * 1024U * 1024U)
#define WLS_SIGNATURE_TEXT 256U
#define WLS_CONTROL_TREE_RELOAD 254
#define WLS_UPGRADE_ACTIVATION_SECONDS 300LL
#define WLS_UPGRADE_OBSERVATION_MILLISECONDS 300000LL
#define WLS_UPGRADE_TOTAL_SECONDS 900LL
#define WLS_ROLLBACK_HEALTH_MILLISECONDS 15000LL
#define WLS_SLOT_RETENTION_SECONDS 86400LL
#define WLS_SLOT_RETENTION_MILLISECONDS 86400000LL
#define WLS_UPGRADE_MAX_ATTEMPTS 3U
#define WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS 30000LL

struct wls_upgrade {
    int present;
    char from;
    char to;
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
    char from;
    char to;
    char runtime_generation[65];
    char boot_id[65];
    char phase[24];
    unsigned int attempts;
    long long observation_started;
    long long observation_deadline;
    long long total_deadline;
};

static volatile sig_atomic_t wls_shutdown_signal = 0;

static long long wls_monotonic_milliseconds(void);

static int wls_checked_add_long_long(
    long long value,
    long long increment,
    long long *result
) {
    if (result == NULL || value < 0 || increment < 0
        || value > LLONG_MAX - increment) {
        return -1;
    }
    *result = value + increment;
    return 0;
}

static void wls_capture_shutdown_signal(int signal_number)
{
    /* A stop request is authoritative and must never be overwritten by HUP. */
    if (signal_number == SIGTERM || signal_number == SIGINT) {
        wls_shutdown_signal = signal_number;
    } else if (wls_shutdown_signal == 0) {
        wls_shutdown_signal = signal_number;
    }
}

static int wls_install_signal_handlers(void)
{
    struct sigaction action;
    memset(&action, 0, sizeof(action));
    action.sa_handler = wls_capture_shutdown_signal;
    sigemptyset(&action.sa_mask);
    sigaddset(&action.sa_mask, SIGTERM);
    sigaddset(&action.sa_mask, SIGINT);
    sigaddset(&action.sa_mask, SIGHUP);
    return sigaction(SIGTERM, &action, NULL) == 0
        && sigaction(SIGINT, &action, NULL) == 0
        && sigaction(SIGHUP, &action, NULL) == 0
        ? 0
        : 1;
}

static int wls_take_shutdown_signal(void)
{
    sigset_t managed;
    sigset_t previous;
    int signal_number;
    sigemptyset(&managed);
    sigaddset(&managed, SIGTERM);
    sigaddset(&managed, SIGINT);
    sigaddset(&managed, SIGHUP);
    if (sigprocmask(SIG_BLOCK, &managed, &previous) != 0) {
        signal_number = (int)wls_shutdown_signal;
        wls_shutdown_signal = 0;
        return signal_number;
    }
    signal_number = (int)wls_shutdown_signal;
    wls_shutdown_signal = 0;
    (void)sigprocmask(SIG_SETMASK, &previous, NULL);
    return signal_number;
}

static int wls_classify_broker_exit(int exit_code, int automatic_launch_allowed)
{
    if (!automatic_launch_allowed) {
        return 0;
    }
    /* 254 is launcher-private; an arbitrary Broker must not request a reload. */
    if (exit_code == 0 || exit_code == WLS_CONTROL_TREE_RELOAD) {
        return 1;
    }
    return exit_code;
}

static int wls_prepare_process_supervision(void)
{
#if defined(__linux__)
    /*
     * The launcher is PID 1 inside the production gateway namespace and
     * therefore inherits orphaned Nginx masters after reload/recovery. Mark it
     * as a subreaper as well so the same ownership and reaping semantics hold
     * when it runs without a dedicated PID namespace.
     */
    if (prctl(PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0) != 0) {
        return -1;
    }
#endif
    return 0;
}

static pid_t wls_reap_exited_children(
    pid_t broker_pid,
    int *broker_status,
    int *has_children
) {
    pid_t broker_waited = 0;
    if (has_children != NULL) {
        *has_children = 1;
    }
    for (;;) {
        int status = 0;
        pid_t waited = waitpid(-1, &status, WNOHANG);
        if (waited > 0) {
            if (waited == broker_pid) {
                if (broker_status != NULL) {
                    *broker_status = status;
                }
                broker_waited = waited;
            }
            continue;
        }
        if (waited == 0) {
            return broker_waited;
        }
        if (errno == EINTR) {
            continue;
        }
        if (errno == ECHILD && has_children != NULL) {
            *has_children = 0;
        }
        return broker_waited;
    }
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

static int wls_write_all(int fd, const char *contents, size_t length)
{
    size_t offset = 0U;
    while (offset < length) {
        ssize_t amount = write(fd, contents + offset, length - offset);
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) return -1;
        offset += (size_t)amount;
    }
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
    if (!((amount == 1) || (amount == 2 && contents[1] == '\n'))
        || (contents[0] != 'A' && contents[0] != 'B')) return -1;
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
    struct stat opened_parent;
    int fd = -1;
    int parent_fd = -1;
    size_t length = strlen(contents);
    if (strlen(path) >= sizeof(parent_path)) {
        return -1;
    }
    memcpy(parent_path, path, strlen(path) + 1U);
    slash = strrchr(parent_path, '/');
    if (slash == NULL) return -1;
    *slash = '\0';
    if (lstat(parent_path, &parent) != 0
        || !S_ISDIR(parent.st_mode)
        || S_ISLNK(parent.st_mode)
        || snprintf(
            temporary,
            sizeof(temporary),
            "%s.candidate.%ld.%08x",
            path,
            (long)getpid(),
            randombytes_random()
        ) >= (int)sizeof(temporary)) {
        return -1;
    }
    parent_fd = open(
        parent_path,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (parent_fd < 0
        || fstat(parent_fd, &opened_parent) != 0
        || !S_ISDIR(opened_parent.st_mode)
        || parent.st_dev != opened_parent.st_dev
        || parent.st_ino != opened_parent.st_ino) {
        if (parent_fd >= 0) close(parent_fd);
        return -1;
    }
    fd = open(temporary, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (fd < 0
        || wls_write_all(fd, contents, length) != 0
        || fchown(fd, parent.st_uid, parent.st_gid) != 0
        || fchmod(fd, mode) != 0
        || fsync(fd) != 0) {
        if (fd >= 0) close(fd);
        close(parent_fd);
        unlink(temporary);
        return -1;
    }
    close(fd);
    if (rename(temporary, path) != 0) {
        close(parent_fd);
        unlink(temporary);
        return -1;
    }
    if (fsync(parent_fd) != 0) {
        close(parent_fd);
        return -1;
    }
    close(parent_fd);
    return 0;
}

static int wls_delete_optional_durable(const char *path)
{
    char parent_path[PATH_MAX];
    char *slash;
    struct stat target;
    struct stat parent;
    struct stat opened_parent;
    int parent_fd;
    if (path == NULL || strlen(path) >= sizeof(parent_path)) return -1;
    if (lstat(path, &target) != 0) {
        return errno == ENOENT ? 0 : -1;
    }
    if (!S_ISREG(target.st_mode) || S_ISLNK(target.st_mode)
        || target.st_nlink != 1) {
        return -1;
    }
    memcpy(parent_path, path, strlen(path) + 1U);
    slash = strrchr(parent_path, '/');
    if (slash == NULL) return -1;
    *slash = '\0';
    if (lstat(parent_path, &parent) != 0
        || !S_ISDIR(parent.st_mode)
        || S_ISLNK(parent.st_mode)) {
        return -1;
    }
    parent_fd = open(
        parent_path,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (parent_fd < 0
        || fstat(parent_fd, &opened_parent) != 0
        || !S_ISDIR(opened_parent.st_mode)
        || parent.st_dev != opened_parent.st_dev
        || parent.st_ino != opened_parent.st_ino) {
        if (parent_fd >= 0) close(parent_fd);
        return -1;
    }
    if (unlink(path) != 0 || fsync(parent_fd) != 0) {
        close(parent_fd);
        return -1;
    }
    return close(parent_fd) == 0 ? 0 : -1;
}

static int wls_read_slot_pointer(const char *path, char *slot)
{
    char contents[4];
    ssize_t amount;
    int fd;
    if (path == NULL || slot == NULL) return -1;
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0) return -1;
    do {
        amount = read(fd, contents, sizeof(contents));
    } while (amount < 0 && errno == EINTR);
    if (close(fd) != 0) return -1;
    if (!((amount == 1) || (amount == 2 && contents[1] == '\n'))
        || (contents[0] != 'A' && contents[0] != 'B')) {
        return -1;
    }
    *slot = contents[0];
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
    unsigned char intent_digest[crypto_hash_sha256_BYTES];
    size_t decoded = 0U;
    int consumed = 0;
    int fields;
    int result = -1;
    long long expected_activation_deadline = 0;
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
        || wls_checked_add_long_long(
            upgrade->prepared_at,
            WLS_UPGRADE_ACTIVATION_SECONDS,
            &expected_activation_deadline
        ) != 0
        || upgrade->deadline != expected_activation_deadline
        || upgrade->prepared_at > LLONG_MAX - WLS_UPGRADE_TOTAL_SECONDS
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
    upgrade->from = from[0];
    upgrade->to = to[0];
    memcpy(upgrade->nonce, nonce, sizeof(upgrade->nonce));
    result = 1;
cleanup:
    sodium_memzero(key, sizeof(key));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
    sodium_memzero(intent_digest, sizeof(intent_digest));
    if (token_text != NULL) sodium_memzero(token_text, token_length);
    free(intent);
    free(token_text);
    free(host_text);
    return result;
}

static int wls_upgrade_healthy(
    const char *home,
    const struct wls_upgrade *upgrade,
    const struct wls_upgrade_state *state,
    const char *boot_id
)
{
    char path[PATH_MAX];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char digest[65];
    char nonce[33];
    char from[2];
    char to[2];
    char runtime[65];
    char marker_boot[65];
    long long observation_deadline = 0;
    long long healthy_at = 0;
    long long monotonic_now = wls_monotonic_milliseconds();
    int consumed = 0;
    int result = 0;
    if (wls_join(path, sizeof(path), home, "trust/upgrade-healthy") != 0
        || wls_read_file(path, 512U, &contents, &length) != 0) {
        return 0;
    }
    if (sscanf(
        (const char *)contents,
        "WLS-UPGRADE-HEALTHY/2\n"
        "intent_sha256=%64[0-9a-f]\n"
        "intent_nonce=%32[0-9a-f]\n"
        "from=%1[AB]\n"
        "to=%1[AB]\n"
        "runtime_generation=%64[0-9a-f]\n"
        "boot_id=%64[0-9A-Za-z-]\n"
        "observation_deadline_monotonic_ms=%lld\n"
        "healthy_monotonic_ms=%lld\n%n",
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
        && from[0] == upgrade->from
        && to[0] == upgrade->to
        && strcmp(runtime, upgrade->runtime_generation) == 0
        && strcmp(marker_boot, boot_id) == 0
        && observation_deadline == state->observation_deadline
        && observation_deadline > 0
        && healthy_at >= observation_deadline
        && monotonic_now >= 0
        && healthy_at <= monotonic_now) {
        result = 1;
    }
    free(contents);
    return result;
}

static int wls_upgrade_observation_deadline(
    const char *home,
    const struct wls_upgrade *upgrade,
    const struct wls_upgrade_state *state,
    const char *boot_id,
    long long *started_out,
    long long *deadline
) {
    char path[PATH_MAX];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char digest[65];
    char nonce[33];
    char from[2];
    char to[2];
    char runtime[65];
    char marker_boot[65];
    long long started = 0;
    long long parsed_deadline = 0;
    long long expected_deadline = 0;
    long long monotonic_now = wls_monotonic_milliseconds();
    int consumed = 0;
    int result = 0;
    if (deadline == NULL || started_out == NULL
        || wls_join(path, sizeof(path), home, "trust/upgrade-observing") != 0
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
        "started_monotonic_ms=%lld\n"
        "deadline_monotonic_ms=%lld\n%n",
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
        && from[0] == upgrade->from
        && to[0] == upgrade->to
        && strcmp(runtime, upgrade->runtime_generation) == 0
        && strcmp(marker_boot, boot_id) == 0
        && wls_checked_add_long_long(
            started,
            WLS_UPGRADE_OBSERVATION_MILLISECONDS,
            &expected_deadline
        ) == 0
        && parsed_deadline == expected_deadline
        && monotonic_now >= 0
        && started <= monotonic_now) {
        *started_out = started;
        *deadline = parsed_deadline;
        result = 1;
    }
    free(contents);
    return result;
}

static long long wls_monotonic_milliseconds(void)
{
    struct timespec now;
    long long seconds_milliseconds;
    long long nanoseconds_milliseconds;
    if (clock_gettime(CLOCK_MONOTONIC, &now) != 0
        || now.tv_sec < 0
        || now.tv_nsec < 0
        || now.tv_nsec >= 1000000000L
        || (unsigned long long)now.tv_sec
            > (unsigned long long)LLONG_MAX / 1000ULL) {
        return -1;
    }
    seconds_milliseconds = (long long)now.tv_sec * 1000LL;
    nanoseconds_milliseconds = (long long)now.tv_nsec / 1000000LL;
    if (seconds_milliseconds > LLONG_MAX - nanoseconds_milliseconds) return -1;
    return seconds_milliseconds + nanoseconds_milliseconds;
}

static int wls_boot_id(char output[65])
{
    static const char prefix[] = "wls-gateway-host-boot/1|";
    char platform_token[65];
    char canonical[sizeof(prefix) + sizeof(platform_token)];
    unsigned char digest[crypto_hash_sha256_BYTES];
    int canonical_length;
    int result = -1;
#if defined(__linux__)
    int fd = open(
        "/proc/sys/kernel/random/boot_id",
        O_RDONLY | O_CLOEXEC | O_NOFOLLOW
    );
    ssize_t amount;
    size_t index;
    if (fd < 0) return -1;
    amount = read(fd, platform_token, 64U);
    close(fd);
    if (amount <= 0) return -1;
    while (amount > 0
        && (platform_token[amount - 1] == '\n'
            || platform_token[amount - 1] == '\r')) {
        amount--;
    }
    if (amount != 36) return -1;
    platform_token[amount] = '\0';
    for (index = 0U; index < (size_t)amount; index++) {
        char value = platform_token[index];
        int hyphen = index == 8U || index == 13U
            || index == 18U || index == 23U;
        if (hyphen ? value != '-'
            : !((value >= '0' && value <= '9')
                || (value >= 'a' && value <= 'f'))) return -1;
    }
#elif defined(__APPLE__)
    struct timeval boot;
    size_t length = sizeof(boot);
    int written;
    if (sysctlbyname("kern.boottime", &boot, &length, NULL, 0) != 0
        || length != sizeof(boot)) return -1;
    written = snprintf(
        platform_token,
        sizeof(platform_token),
        "darwin-%lld-%d",
        (long long)boot.tv_sec,
        (int)boot.tv_usec
    );
    if (written <= 0 || written >= (int)sizeof(platform_token)) return -1;
#else
    (void)output;
    return -1;
#endif
    canonical_length = snprintf(
        canonical,
        sizeof(canonical),
        "%s%s",
        prefix,
        platform_token
    );
    if (canonical_length > 0
        && canonical_length < (int)sizeof(canonical)
        && crypto_hash_sha256(
            digest,
            (const unsigned char *)canonical,
            (unsigned long long)canonical_length
        ) == 0
        && sodium_bin2hex(output, 65U, digest, sizeof(digest)) != NULL
        && strlen(output) == 64U) {
        result = 0;
    }
    sodium_memzero(digest, sizeof(digest));
    sodium_memzero(canonical, sizeof(canonical));
    return result;
}

/* 0=acquired, 1=busy, -1=invalid/error. */
static int wls_package_lock_acquire(
    const char *home,
    int *lock_fd,
    int wait_for_lock
)
{
    char trust_path[PATH_MAX];
    struct stat trust_status;
    struct stat opened_trust_status;
    struct stat lock_status;
    struct stat path_status;
    struct timespec pause = {0, 10000000L};
    long long started;
    long long deadline;
    long long now;
    int trust_fd = -1;
    int fd = -1;
    int lock_result;
    if (home == NULL || lock_fd == NULL
        || wls_join(trust_path, sizeof(trust_path), home, "trust") != 0
        || lstat(trust_path, &trust_status) != 0
        || !S_ISDIR(trust_status.st_mode)
        || S_ISLNK(trust_status.st_mode)) {
        return -1;
    }
    trust_fd = open(
        trust_path,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (trust_fd < 0
        || fstat(trust_fd, &opened_trust_status) != 0
        || !S_ISDIR(opened_trust_status.st_mode)
        || opened_trust_status.st_dev != trust_status.st_dev
        || opened_trust_status.st_ino != trust_status.st_ino) {
        if (trust_fd >= 0) close(trust_fd);
        return -1;
    }
    fd = openat(
        trust_fd,
        "package-install.lock",
        O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW,
        0600
    );
    if (fd < 0
        || fstat(fd, &lock_status) != 0
        || fstatat(
            trust_fd,
            "package-install.lock",
            &path_status,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !S_ISREG(lock_status.st_mode)
        || !S_ISREG(path_status.st_mode)
        || lock_status.st_nlink != 1
        || path_status.st_nlink != 1
        || lock_status.st_dev != path_status.st_dev
        || lock_status.st_ino != path_status.st_ino
        || fchown(fd, trust_status.st_uid, trust_status.st_gid) != 0
        || fchmod(fd, 0600) != 0
        || fsync(fd) != 0
        || fsync(trust_fd) != 0) {
        if (fd >= 0) close(fd);
        close(trust_fd);
        return -1;
    }
    started = wls_monotonic_milliseconds();
    if (wls_checked_add_long_long(
            started,
            WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS,
            &deadline
        ) != 0) {
        close(fd);
        close(trust_fd);
        return -1;
    }
    for (;;) {
        lock_result = flock(fd, LOCK_EX | LOCK_NB);
        if (lock_result == 0) break;
        if (errno != EWOULDBLOCK && errno != EAGAIN && errno != EINTR) {
            close(fd);
            close(trust_fd);
            return -1;
        }
        if (!wait_for_lock) {
            close(fd);
            close(trust_fd);
            errno = EWOULDBLOCK;
            return 1;
        }
        now = wls_monotonic_milliseconds();
        if (now < 0 || now >= deadline) {
            close(fd);
            close(trust_fd);
            errno = EWOULDBLOCK;
            return 1;
        }
        while (nanosleep(&pause, &pause) != 0 && errno == EINTR) {
        }
        pause.tv_sec = 0;
        pause.tv_nsec = 10000000L;
    }
    if (fstat(fd, &lock_status) != 0
        || fstatat(
            trust_fd,
            "package-install.lock",
            &path_status,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !S_ISREG(lock_status.st_mode)
        || !S_ISREG(path_status.st_mode)
        || lock_status.st_nlink != 1
        || path_status.st_nlink != 1
        || lock_status.st_dev != path_status.st_dev
        || lock_status.st_ino != path_status.st_ino) {
        (void)flock(fd, LOCK_UN);
        close(fd);
        close(trust_fd);
        return -1;
    }
    if (close(trust_fd) != 0) {
        (void)flock(fd, LOCK_UN);
        close(fd);
        return -1;
    }
    *lock_fd = fd;
    return 0;
}

static int wls_package_lock_release(int lock_fd)
{
    int result = 0;
    if (lock_fd < 0) return -1;
    if (flock(lock_fd, LOCK_UN) != 0) result = -1;
    if (close(lock_fd) != 0) result = -1;
    return result;
}

static int wls_upgrade_state_read(
    const char *home,
    const struct wls_upgrade *upgrade,
    struct wls_upgrade_state *state
) {
    char path[PATH_MAX];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char from[2];
    char to[2];
    int consumed = 0;
    int fields;
    long long expected_total_deadline = 0;
    memset(state, 0, sizeof(*state));
    if (wls_join(path, sizeof(path), home, "trust/upgrade-state") != 0) return -1;
    if (wls_read_file(path, 1024U, &contents, &length) != 0) {
        return errno == ENOENT ? 0 : -1;
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
        "observation_started_monotonic_ms=%lld\n"
        "observation_deadline_monotonic_ms=%lld\n"
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
    free(contents);
    if (fields != 11 || consumed != (int)length
        || strcmp(state->intent_sha256, upgrade->intent_sha256) != 0
        || strcmp(state->nonce, upgrade->nonce) != 0
        || from[0] != upgrade->from || to[0] != upgrade->to
        || strcmp(state->runtime_generation, upgrade->runtime_generation) != 0
        || state->attempts > WLS_UPGRADE_MAX_ATTEMPTS
        || wls_checked_add_long_long(
            upgrade->prepared_at,
            WLS_UPGRADE_TOTAL_SECONDS,
            &expected_total_deadline
        ) != 0
        || state->total_deadline != expected_total_deadline
        || state->observation_started < 0
        || state->observation_deadline < 0
        || (strcmp(state->phase, "PREPARED") != 0
            && strcmp(state->phase, "OBSERVING") != 0
            && strcmp(state->phase, "HEALTHY") != 0
            && strcmp(state->phase, "ROLLBACK_PENDING") != 0
            && strcmp(state->phase, "ROLLED_BACK") != 0
            && strcmp(state->phase, "COMMITTED") != 0)) {
        return -1;
    }
    state->from = from[0];
    state->to = to[0];
    state->present = 1;
    return 1;
}

static int wls_upgrade_state_write(
    const char *home,
    const struct wls_upgrade *upgrade,
    const char *boot_id,
    const char *phase,
    unsigned int attempts,
    long long observation_started,
    long long observation_deadline
) {
    char path[PATH_MAX];
    char payload[640];
    int length;
    long long total_deadline = 0;
    long long expected_observation_deadline = 0;
    if (attempts > WLS_UPGRADE_MAX_ATTEMPTS
        || phase == NULL
        || wls_checked_add_long_long(
            upgrade->prepared_at,
            WLS_UPGRADE_TOTAL_SECONDS,
            &total_deadline
        ) != 0
        || wls_join(path, sizeof(path), home, "trust/upgrade-state") != 0) {
        return -1;
    }
    if (strcmp(phase, "OBSERVING") == 0
        || strcmp(phase, "HEALTHY") == 0
        || strcmp(phase, "COMMITTED") == 0) {
        if (observation_started <= 0
            || wls_checked_add_long_long(
                observation_started,
                WLS_UPGRADE_OBSERVATION_MILLISECONDS,
                &expected_observation_deadline
            ) != 0
            || observation_deadline != expected_observation_deadline) {
            return -1;
        }
    } else if (observation_started != 0 || observation_deadline != 0) {
        return -1;
    }
    length = snprintf(
        payload,
        sizeof(payload),
        "WLS-UPGRADE-STATE/2\n"
        "intent_sha256=%s\n"
        "intent_nonce=%s\n"
        "from=%c\n"
        "to=%c\n"
        "runtime_generation=%s\n"
        "boot_id=%s\n"
        "phase=%s\n"
        "attempts=%u\n"
        "observation_started_monotonic_ms=%lld\n"
        "observation_deadline_monotonic_ms=%lld\n"
        "total_deadline=%lld\n",
        upgrade->intent_sha256,
        upgrade->nonce,
        upgrade->from,
        upgrade->to,
        upgrade->runtime_generation,
        boot_id,
        phase,
        attempts,
        observation_started,
        observation_deadline,
        total_deadline
    );
    return length > 0 && length < (int)sizeof(payload)
        ? wls_atomic_text(path, payload, 0600)
        : -1;
}

static int wls_upgrade_rollback_healthy(
    const char *home,
    const struct wls_upgrade *upgrade,
    const char *boot_id
) {
    char path[PATH_MAX];
    unsigned char *contents = NULL;
    size_t length = 0U;
    char digest[65];
    char nonce[33];
    char from[2];
    char to[2];
    char runtime[65];
    char marker_boot[65];
    long long started = 0;
    long long healthy = 0;
    long long expected_healthy = 0;
    long long monotonic_now = wls_monotonic_milliseconds();
    int consumed = 0;
    int result = 0;
    if (wls_join(path, sizeof(path), home, "trust/upgrade-rollback-healthy") != 0
        || wls_read_file(path, 768U, &contents, &length) != 0) return 0;
    if (sscanf(
        (const char *)contents,
        "WLS-UPGRADE-ROLLBACK-HEALTHY/2\n"
        "intent_sha256=%64[0-9a-f]\n"
        "intent_nonce=%32[0-9a-f]\n"
        "from=%1[AB]\n"
        "to=%1[AB]\n"
        "active_runtime_generation=%64[0-9a-f]\n"
        "boot_id=%64[0-9A-Za-z-]\n"
        "started_monotonic_ms=%lld\n"
        "healthy_monotonic_ms=%lld\n%n",
        digest, nonce, from, to, runtime, marker_boot,
        &started, &healthy, &consumed
    ) == 8
        && consumed == (int)length
        && strcmp(digest, upgrade->intent_sha256) == 0
        && strcmp(nonce, upgrade->nonce) == 0
        && from[0] == upgrade->from && to[0] == upgrade->to
        && wls_is_hex_text(runtime, 64U)
        && strcmp(marker_boot, boot_id) == 0
        && wls_checked_add_long_long(
            started,
            WLS_ROLLBACK_HEALTH_MILLISECONDS,
            &expected_healthy
        ) == 0
        && healthy >= expected_healthy
        && monotonic_now >= 0
        && healthy <= monotonic_now) {
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
    char intent_digest[65];
    char intent_nonce[33];
    char request_nonce[33];
    struct stat request_status;
    int consumed = 0;
    int result = 0;
    if (wls_join(path, sizeof(path), home, "state/upgrade-rollback.request") != 0) {
        return -1;
    }
    if (lstat(path, &request_status) != 0) {
        return errno == ENOENT ? 0 : -1;
    }
    if (!S_ISREG(request_status.st_mode) || S_ISLNK(request_status.st_mode)
        || request_status.st_nlink != 1) {
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
        "from=%1[AB]\n"
        "to=%1[AB]\n"
        "at=%lld\n"
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
        && from[0] == upgrade->to
        && to[0] == upgrade->from
        && at > 0
        && wls_is_hex_text(request_nonce, 32U)) {
        result = 1;
    }
    free(contents);
    return result == 1 ? 1 : -1;
}

static int wls_reconcile_upgrade_locked(
    const char *home,
    char active[2],
    int count_candidate_failure
)
{
    struct wls_upgrade upgrade;
    struct wls_upgrade_state transaction;
    char active_path[PATH_MAX];
    char previous_path[PATH_MAX];
    char intent_path[PATH_MAX];
    char healthy_path[PATH_MAX];
    char observing_path[PATH_MAX];
    char rollback_path[PATH_MAX];
    char rollback_healthy_path[PATH_MAX];
    char retention_path[PATH_MAX];
    char rolled_back_path[PATH_MAX];
    char state_path[PATH_MAX];
    char boot_id[65];
    long long now = (long long)time(NULL);
    long long monotonic_now = wls_monotonic_milliseconds();
    long long observation_started = 0;
    long long observation_deadline = 0;
    char record[640];
    long long retained_at;
    long long retained_since_monotonic;
    int record_length;
    int rollback_requested;
    int observation_present;
    int intent_status;
    int state_status;
    unsigned int attempts;
    int must_rollback = 0;
    int rollback_transitioned = 0;
    long long expected_observation_deadline = 0;
    if (wls_active_slot(home, active) != 0) return -1;
    intent_status = wls_upgrade_intent(home, &upgrade);
    if (intent_status < 0) {
        fprintf(stderr, "invalid signed upgrade intent blocks automatic launch\n");
        return -1;
    }
    if (intent_status == 0 || !upgrade.present) return 0;
    if (now < 0 || wls_boot_id(boot_id) != 0 || monotonic_now < 0
        || wls_join(active_path, sizeof(active_path), home, "trust/active-slot") != 0
        || wls_join(previous_path, sizeof(previous_path), home, "trust/previous-slot") != 0
        || wls_join(intent_path, sizeof(intent_path), home, "trust/upgrade.intent") != 0
        || wls_join(healthy_path, sizeof(healthy_path), home, "trust/upgrade-healthy") != 0
        || wls_join(observing_path, sizeof(observing_path), home, "trust/upgrade-observing") != 0
        || wls_join(retention_path, sizeof(retention_path), home, "trust/slot-retention") != 0
        || wls_join(rolled_back_path, sizeof(rolled_back_path), home, "trust/upgrade-rolled-back") != 0
        || wls_join(state_path, sizeof(state_path), home, "trust/upgrade-state") != 0
        || wls_join(rollback_path, sizeof(rollback_path), home, "state/upgrade-rollback.request") != 0
        || wls_join(rollback_healthy_path, sizeof(rollback_healthy_path), home,
            "trust/upgrade-rollback-healthy") != 0) {
        return -1;
    }
    state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
    if (state_status < 0) {
        fprintf(stderr, "invalid persistent upgrade transaction blocks automatic launch\n");
        return -1;
    }
    if (state_status > 0 && strcmp(transaction.boot_id, boot_id) == 0) {
        if (strcmp(transaction.phase, "OBSERVING") == 0
            || strcmp(transaction.phase, "HEALTHY") == 0
            || strcmp(transaction.phase, "COMMITTED") == 0) {
            if (transaction.observation_started <= 0
                || wls_checked_add_long_long(
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
                return -1;
            }
        } else if (transaction.observation_started != 0
            || transaction.observation_deadline != 0) {
            return -1;
        }
    }
    rollback_requested = wls_upgrade_rollback_requested(home, &upgrade);
    if (rollback_requested < 0) {
        fprintf(stderr, "invalid rollback request blocks automatic upgrade reconciliation\n");
        return -1;
    }

    if (state_status > 0
        && strcmp(transaction.boot_id, boot_id) != 0
        && strcmp(transaction.phase, "COMMITTED") != 0
        && strcmp(transaction.phase, "ROLLED_BACK") != 0) {
        attempts = transaction.attempts + 1U;
        if (active[0] == upgrade.from
            || strcmp(transaction.phase, "ROLLBACK_PENDING") == 0) {
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLBACK_PENDING",
                    attempts > WLS_UPGRADE_MAX_ATTEMPTS
                        ? WLS_UPGRADE_MAX_ATTEMPTS : attempts,
                    0, 0
                ) != 0) return -1;
            rollback_transitioned = 1;
        } else if (attempts >= WLS_UPGRADE_MAX_ATTEMPTS
            || now >= transaction.total_deadline) {
            must_rollback = 1;
        } else {
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "PREPARED", attempts, 0, 0
                ) != 0) return -1;
            (void)unlink(observing_path);
            (void)unlink(healthy_path);
        }
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return -1;
    }

    if (active[0] == upgrade.from) {
        if (state_status == 0) {
            /*
             * The package lock covers intent -> previous -> active. Once the
             * launcher owns that same lock, an absent state with the old slot
             * active can no longer belong to a live preactivation writer.
             */
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLBACK_PENDING", 0U, 0, 0
                ) != 0) return -1;
            rollback_transitioned = 1;
            state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
            if (state_status < 1) return -1;
        } else if (strcmp(transaction.phase, "ROLLED_BACK") != 0
            && strcmp(transaction.phase, "ROLLBACK_PENDING") != 0) {
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLBACK_PENDING",
                    transaction.attempts, 0, 0
                ) != 0) return -1;
            rollback_transitioned = 1;
            state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
            if (state_status < 1) return -1;
        }

        if (strcmp(transaction.phase, "ROLLBACK_PENDING") == 0
            || strcmp(transaction.phase, "ROLLED_BACK") == 0) {
            char previous_text[3] = {upgrade.to, '\n', '\0'};
            char verified_previous = '\0';
            /*
             * A crash can occur after active-slot is restored but before the
             * inverse previous-slot pointer is durable. Repair and reread it
             * before accepting health or publishing a terminal rollback.
             */
            if (wls_atomic_text(previous_path, previous_text, 0640) != 0
                || wls_read_slot_pointer(
                    previous_path,
                    &verified_previous
                ) != 0
                || verified_previous != upgrade.to) {
                return -1;
            }
        }
        if (strcmp(transaction.phase, "ROLLBACK_PENDING") == 0) {
            if (!wls_upgrade_rollback_healthy(home, &upgrade, boot_id)) {
                return rollback_transitioned ? 1 : 0;
            }
            record_length = snprintf(
                record,
                sizeof(record),
                "WLS-UPGRADE-ROLLED-BACK/3\n"
                "intent_sha256=%s\nintent_nonce=%s\n"
                "from=%c\nto=%c\nruntime_generation=%s\nat=%lld\n",
                upgrade.intent_sha256,
                upgrade.nonce,
                upgrade.from,
                upgrade.to,
                upgrade.runtime_generation,
                now
            );
            if (record_length <= 0 || record_length >= (int)sizeof(record)
                || wls_atomic_text(rolled_back_path, record, 0600) != 0
                || wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLED_BACK",
                    transaction.attempts, 0, 0
                ) != 0) return -1;
        }
        // ROLLED_BACK is the durable transaction decision. The rollback
        // request must be durably absent before intent/state authority is
        // consumed; a failed delete leaves both terminal records retryable.
        if (wls_delete_optional_durable(rollback_path) != 0
            || wls_delete_optional_durable(healthy_path) != 0
            || wls_delete_optional_durable(observing_path) != 0
            || wls_delete_optional_durable(rollback_healthy_path) != 0
            || wls_delete_optional_durable(intent_path) != 0) {
            return -1;
        }
        // The terminal state remains durable until the intent is gone. Remove
        // it only afterwards so a future, differently-bound intent can create
        // a fresh transaction instead of being rejected as a digest mismatch.
        if (wls_delete_optional_durable(state_path) != 0) return -1;
        return 0;
    }
    if (active[0] != upgrade.to) return -1;

    if (state_status == 0) {
        if (wls_upgrade_state_write(
                home, &upgrade, boot_id, "PREPARED", 0U, 0, 0
            ) != 0) return -1;
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return -1;
    }
    if (strcmp(transaction.phase, "COMMITTED") == 0) {
        if (wls_delete_optional_durable(rollback_path) != 0
            || wls_delete_optional_durable(healthy_path) != 0
            || wls_delete_optional_durable(observing_path) != 0
            || wls_delete_optional_durable(intent_path) != 0
            || wls_delete_optional_durable(state_path) != 0) {
            return -1;
        }
        return 0;
    }
    if (strcmp(transaction.phase, "ROLLBACK_PENDING") == 0) {
        must_rollback = 1;
    }
    observation_present = wls_upgrade_observation_deadline(
        home,
        &upgrade,
        &transaction,
        boot_id,
        &observation_started,
        &observation_deadline
    );
    if (observation_present
        && strcmp(transaction.phase, "PREPARED") == 0) {
        if (wls_upgrade_state_write(
                home, &upgrade, boot_id, "OBSERVING",
                transaction.attempts,
                observation_started,
                observation_deadline
            ) != 0) return -1;
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return -1;
    }
    if (!rollback_requested
        && (strcmp(transaction.phase, "HEALTHY") == 0
            || wls_upgrade_healthy(home, &upgrade, &transaction, boot_id))) {
        if (strcmp(transaction.phase, "HEALTHY") != 0
            && wls_upgrade_state_write(
                home, &upgrade, boot_id, "HEALTHY",
                transaction.attempts,
                transaction.observation_started,
                transaction.observation_deadline
            ) != 0) return -1;
        retained_at = (long long)time(NULL);
        retained_since_monotonic = wls_monotonic_milliseconds();
        if (retained_at < 0
            || retained_at > LLONG_MAX - WLS_SLOT_RETENTION_SECONDS
            || retained_since_monotonic < 0
            || retained_since_monotonic
                > LLONG_MAX - WLS_SLOT_RETENTION_MILLISECONDS) {
            return -1;
        }
        record_length = snprintf(
            record,
            sizeof(record),
            "WLS-SLOT-RETENTION/3\n"
            "intent_sha256=%s\nintent_nonce=%s\n"
            "slot=%c\nboot_id=%s\n"
            "retained_at=%lld\nretain_until=%lld\n"
            "retained_since_monotonic_ms=%lld\n"
            "retain_until_monotonic_ms=%lld\n",
            upgrade.intent_sha256,
            upgrade.nonce,
            upgrade.from,
            boot_id,
            retained_at,
            retained_at + WLS_SLOT_RETENTION_SECONDS,
            retained_since_monotonic,
            retained_since_monotonic + WLS_SLOT_RETENTION_MILLISECONDS
        );
        if (record_length <= 0 || record_length >= (int)sizeof(record)
            || wls_atomic_text(retention_path, record, 0600) != 0
            || wls_upgrade_state_write(
                home, &upgrade, boot_id, "COMMITTED",
                transaction.attempts,
                transaction.observation_started,
                transaction.observation_deadline
            ) != 0) {
            return -1;
        }
        if (wls_delete_optional_durable(rollback_path) != 0
            || wls_delete_optional_durable(healthy_path) != 0
            || wls_delete_optional_durable(observing_path) != 0
            || wls_delete_optional_durable(intent_path) != 0
            || wls_delete_optional_durable(state_path) != 0) {
            return -1;
        }
        return 0;
    }

    if (count_candidate_failure) {
        attempts = transaction.attempts + 1U;
        if (attempts >= WLS_UPGRADE_MAX_ATTEMPTS) {
            must_rollback = 1;
        } else if (!must_rollback) {
            if (wls_upgrade_state_write(
                    home, &upgrade, boot_id, "PREPARED", attempts, 0, 0
                ) != 0) return -1;
            (void)unlink(observing_path);
            (void)unlink(healthy_path);
            return 0;
        }
    }
    if (rollback_requested
        || now >= transaction.total_deadline) {
        must_rollback = 1;
    }
    if (must_rollback) {
        char slot_text[3] = {upgrade.from, '\n', '\0'};
        char previous_text[3] = {upgrade.to, '\n', '\0'};
        attempts = transaction.attempts
            + (count_candidate_failure ? 1U : 0U);
        if (attempts > WLS_UPGRADE_MAX_ATTEMPTS) {
            attempts = WLS_UPGRADE_MAX_ATTEMPTS;
        }
        // Persist the sole rollback decision before changing the slot pointer.
        if (wls_upgrade_state_write(
                home, &upgrade, boot_id, "ROLLBACK_PENDING", attempts, 0, 0
            ) != 0
            || wls_atomic_text(active_path, slot_text, 0640) != 0
            || wls_atomic_text(previous_path, previous_text, 0640) != 0) {
            return -1;
        }
        active[0] = upgrade.from;
        (void)unlink(healthy_path);
        (void)unlink(observing_path);
        (void)unlink(rollback_healthy_path);
        fprintf(stderr, "gateway candidate rollback awaits old-slot health proof\n");
        return 0;
    }
    return 0;
}

static int wls_reconcile_upgrade(
    const char *home,
    char active[2],
    int count_candidate_failure,
    int wait_for_lock
)
{
    int lock_fd = -1;
    int result;
    int lock_status = wls_package_lock_acquire(home, &lock_fd, wait_for_lock);
    if (lock_status > 0) return 2;
    if (lock_status < 0) {
        fprintf(stderr, "unable to acquire the host package transaction lock\n");
        return -1;
    }
    result = wls_reconcile_upgrade_locked(
        home,
        active,
        count_candidate_failure
    );
    if (wls_package_lock_release(lock_fd) != 0) {
        return -1;
    }
    return result;
}

/*
 * Runtime monitoring never increments the candidate crash counter. Candidate
 * failures are recorded only after an unexpected broker exit; clean platform
 * stop/start cycles therefore preserve the whole observation budget.
 */
static int wls_monitor_upgrade(const char *home, char active[2])
{
    char before;
    int result;
    before = active[0];
    result = wls_reconcile_upgrade(home, active, 0, 0);
    if (result == 2) return 0;
    if (result < 0) return -1;
    return result == 1 || active[0] != before ? 1 : 0;
}

static void wls_terminate_broker(pid_t broker_pid, int signal_number)
{
    unsigned int attempt;
    int status = 0;
    if (broker_pid <= 0) return;
    kill(broker_pid, signal_number > 0 ? signal_number : SIGTERM);
    for (attempt = 0U; attempt < 50U; attempt++) {
        int has_children = 1;
        pid_t waited = wls_reap_exited_children(
            broker_pid,
            &status,
            &has_children
        );
        if (waited == broker_pid || !has_children) {
            return;
        }
        usleep(100000);
    }
    kill(broker_pid, SIGKILL);
    while (waitpid(broker_pid, &status, 0) < 0 && errno == EINTR) {
    }
    (void)wls_reap_exited_children(0, NULL, NULL);
}

static int wls_supervise_broker(pid_t broker_pid, const char *home, char active[2])
{
    struct timespec pause = {0, 200000000L};
    int status = 0;
    for (;;) {
        pid_t waited;
        int has_children = 1;
        int upgrade_state;
        int signal_number = wls_take_shutdown_signal();
        if (signal_number != 0) {
            wls_terminate_broker(
                broker_pid,
                signal_number == SIGHUP ? SIGTERM : signal_number
            );
            return signal_number == SIGHUP ? WLS_CONTROL_TREE_RELOAD : 0;
        }
        waited = wls_reap_exited_children(
            broker_pid,
            &status,
            &has_children
        );
        if (waited == broker_pid) {
            char before = active[0];
            int reconcile_result = 0;
            int platform_signal = wls_take_shutdown_signal();
            int exit_code = WIFEXITED(status)
                ? WEXITSTATUS(status)
                : (WIFSIGNALED(status) ? 128 + WTERMSIG(status) : 1);
            int automatic_launch_allowed = wls_admin_stopped(home) == 0;
            /*
             * The platform signal can arrive after the loop's first intent
             * check but before the Broker is reaped.  Reconcile it here too,
             * otherwise a clean signal-owned shutdown can be misclassified as
             * an unexpected failure and trigger an unwanted service restart.
             */
            if (platform_signal != 0) {
                return platform_signal == SIGHUP ? WLS_CONTROL_TREE_RELOAD : 0;
            }
            if (automatic_launch_allowed) {
                reconcile_result = wls_reconcile_upgrade(home, active, 1, 1);
                if (reconcile_result < 0 || reconcile_result == 2) return 1;
            }
            if (wls_admin_stopped(home) != 0) {
                return 0;
            }
            if (reconcile_result == 1 || active[0] != before) {
                return WLS_CONTROL_TREE_RELOAD;
            }
            return wls_classify_broker_exit(exit_code, automatic_launch_allowed);
        }
        if (!has_children) {
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
    int pending_signal;
    int reconcile_result;
    if (wls_admin_stopped(home) != 0) {
        return 0;
    }
    if (wls_active_slot(home, active) != 0) return 1;
    reconcile_result = wls_reconcile_upgrade(home, active, 0, 1);
    if (reconcile_result < 0 || reconcile_result == 2) return 1;
    if (snprintf(slot, sizeof(slot), "%s/slots/%s", home, active) >= (int)sizeof(slot)
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
        || wls_join(
            fencing_file,
            sizeof(fencing_file),
            home,
            "trust/broker-fencing-token"
        ) != 0) {
        free(manifest);
        free(installed_manifest);
        return 1;
    }
    free(manifest);
    free(installed_manifest);
    pending_signal = wls_take_shutdown_signal();
    if (pending_signal == SIGTERM || pending_signal == SIGINT) return 0;
    if (pending_signal == SIGHUP) return WLS_CONTROL_TREE_RELOAD;
    if (wls_admin_stopped(home) != 0) return 0;
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
        return wls_classify_broker_exit(0, 1) == 1
            && wls_classify_broker_exit(WLS_CONTROL_TREE_RELOAD, 1) == 1
            && wls_classify_broker_exit(7, 1) == 7
            && wls_classify_broker_exit(7, 0) == 0
            ? 0
            : 1;
    }
    if (wls_install_signal_handlers() != 0) {
        fprintf(stderr, "stable launcher cannot install signal handlers\n");
        return 1;
    }
    if (wls_prepare_process_supervision() != 0) {
        fprintf(stderr, "stable launcher cannot establish child supervision\n");
        return 1;
    }
    home = wls_argument(argc, argv, "--home");
    run_directory = wls_argument(argc, argv, "--run");
    if (home == NULL || run_directory == NULL || home[0] != '/' || run_directory[0] != '/') {
        fprintf(stderr, "stable launcher requires absolute --home and --run paths\n");
        return 64;
    }
    for (;;) {
        int result;
        int pending_signal = wls_take_shutdown_signal();
        if (pending_signal == SIGTERM || pending_signal == SIGINT) {
            return 0;
        }
        if (pending_signal == SIGHUP) {
            continue;
        }
        result = wls_launch(home, run_directory);
        pending_signal = wls_take_shutdown_signal();
        if (pending_signal == SIGTERM || pending_signal == SIGINT) {
            return 0;
        }
        if (pending_signal == SIGHUP) {
            result = WLS_CONTROL_TREE_RELOAD;
        }
        if (result != WLS_CONTROL_TREE_RELOAD) return result;
    }
}
