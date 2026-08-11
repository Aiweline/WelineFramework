#include <sys/types.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <sys/file.h>
#include <dirent.h>
#include <fcntl.h>
#include <grp.h>
#include <pwd.h>
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

#include "../wls_launcher_recovery_ledger.h"

#if defined(__APPLE__)
#include <sys/acl.h>
#include <libproc.h>
#include <mach/mach_time.h>
#include <sys/sysctl.h>
#include <sys/time.h>
#endif
#if defined(__linux__)
#include <sys/prctl.h>
#include <sys/xattr.h>
#endif

#ifndef WLS_RELEASE_PUBLIC_KEY_HEX
#error "WLS_RELEASE_PUBLIC_KEY_HEX must be defined by the release build"
#endif

#define WLS_MAX_MANIFEST (4U * 1024U * 1024U)
#define WLS_SIGNATURE_TEXT 256U
#define WLS_CONTROL_TREE_RELOAD 254
#define WLS_SERVICE_TREE_RESTART 79
#define WLS_UPGRADE_ACTIVATION_SECONDS 300LL
#define WLS_UPGRADE_ACTIVATION_MILLISECONDS 300000LL
#define WLS_UPGRADE_OBSERVATION_MILLISECONDS 300000LL
#define WLS_UPGRADE_TOTAL_SECONDS 900LL
#define WLS_UPGRADE_TOTAL_MILLISECONDS 900000LL
#define WLS_ROLLBACK_HEALTH_MILLISECONDS 15000LL
#define WLS_SLOT_RETENTION_SECONDS 86400LL
#define WLS_SLOT_RETENTION_MILLISECONDS 86400000LL
#define WLS_UPGRADE_MAX_ATTEMPTS 3U
#define WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS 30000LL
#define WLS_BROKER_TERM_GRACE_MILLISECONDS 5000LL
#define WLS_PLATFORM_SHUTDOWN_GRACE_MILLISECONDS 300000LL
#define WLS_PLATFORM_LAUNCHER_SHUTDOWN_MILLISECONDS 310000LL
#define WLS_PLATFORM_GUARDIAN_SHUTDOWN_MILLISECONDS 320000LL
#define WLS_BROKER_KILL_REAP_MILLISECONDS 1000LL
#define WLS_BROKER_REAP_POLL_MILLISECONDS 100LL
#define WLS_WAITPID_SELF_TEST_TIMEOUT_MILLISECONDS 50LL
#define WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES 131072U
#define WLS_REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES 2048U
#define WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES 16384U
#define WLS_GUARDIAN_DOCUMENT_MAX_BYTES 4096U
#define WLS_GUARDIAN_INVENTORY_MAX_BYTES 4194304U
#define WLS_GUARDIAN_INVENTORY_MAX_ENTRIES 16384U
#define WLS_GUARDIAN_DERIVED_CATEGORY_COUNT 9U
#define WLS_GUARDIAN_AUTHORITY_SDDL_B64_MAX_BYTES 10924U
#define WLS_GUARDIAN_CLOSURE_PATH_MAX_BYTES 8388608U
#define WLS_GUARDIAN_DERIVED_MAX_DEPTH 64U
#define WLS_GUARDIAN_LAUNCHER_MAX_BYTES 536870912ULL
#define WLS_GUARDIAN_PROBATION_MILLISECONDS 300000LL
#define WLS_GUARDIAN_ROLLBACK_OBSERVATION_MILLISECONDS 15000LL
#define WLS_GUARDIAN_HEALTH_FRESHNESS_MILLISECONDS 15000LL
#define WLS_GUARDIAN_PROBE_INTERVAL_MILLISECONDS 5000LL
#define WLS_GUARDIAN_ATOMIC_TEMPORARY 1
#define WLS_GUARDIAN_ATOMIC_BACKUP 2
#define WLS_CAPACITY_TEST_BYTES 8388608ULL
#define WLS_CAPACITY_TEST_INODES 128U
#define WLS_CAPACITY_PRODUCTION_BYTES 10737418240ULL
#define WLS_CAPACITY_PRODUCTION_INODES 65536U
#define WLS_CAPACITY_CONTROL_BYTES 1048576ULL
#define WLS_CAPACITY_CONTROL_INODES 16U
#define WLS_CAPACITY_PLATFORM_BYTES 4194304ULL
/* A definition publish may need one staging file and one retained backup in
 * the platform directory.  Reserve both entries, rather than merely proving
 * that one entry was once allocatable. */
#define WLS_CAPACITY_PLATFORM_INODES 2U
#define WLS_CAPACITY_PLATFORM_BYTES_PER_FILE \
    (WLS_CAPACITY_PLATFORM_BYTES / WLS_CAPACITY_PLATFORM_INODES)
#define WLS_CAPACITY_TOKEN_FSYNC_BATCH 1024U
#define WLS_CAPACITY_TOKEN_SUFFIX ".reserve"
#define WLS_CAPACITY_RELEASE_MARKER "WLS-CAPACITY-RELEASE/1\n"
#define WLS_CAPACITY_CONTROL_REQUIRED 0
#define WLS_CAPACITY_CONTROL_TRANSITION 1
#define WLS_CAPACITY_CONTROL_ABSENT 2
#define WLS_CAPACITY_INSPECT_SCHEMA "wls-capacity-inspect/1"
/* inspect is a machine contract, not a diagnostic: callers may branch only
 * on its zero-exit JSON.  A contradictory live namespace and an unsafe
 * namespace are deliberately distinguishable to tests and operators, but
 * both are fail-closed to PHP. */
#define WLS_CAPACITY_INSPECT_CONFLICT_EXIT 78
#define WLS_CAPACITY_INSPECT_UNSAFE_EXIT 77

struct wls_upgrade {
    int present;
    int legacy_protocol;
    char from;
    char to;
    long long prepared_at;
    long long deadline;
    char runtime_generation[65];
    char boot_id[65];
    long long prepared_monotonic;
    long long activation_deadline_monotonic;
    long long total_deadline_monotonic;
    char nonce[33];
    char intent_sha256[65];
};

struct wls_upgrade_state {
    int present;
    int legacy_protocol;
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
    long long prepared_monotonic;
    long long total_deadline_monotonic;
};

static volatile sig_atomic_t wls_shutdown_signal = 0;
static volatile sig_atomic_t wls_platform_shutdown_self_test_signal = 0;
static pid_t wls_guardian_parent_pid = 0;
static unsigned long long wls_guardian_parent_start_id = 0ULL;
static long long wls_guardian_parent_last_check_ms = 0LL;

struct wls_platform_retirement_receipt {
    char status[16];
    char retirement_id[65];
    unsigned long pid;
    unsigned long long start_id;
    char attestation_digest[65];
    char binary_digest[65];
    char runtime_generation[65];
    char host_boot_id[65];
    char config_digest[65];
    char config_path_digest[65];
    unsigned long publication_generation;
    char platform[16];
    char service_id[65];
    char requested_launcher_generation[65];
    char completed_launcher_generation[65];
    char completed_host_boot_id[65];
    char completed_runtime_generation[65];
};

struct wls_process_attestation_receipt {
    unsigned long pid;
    unsigned long long start_id;
    char binary_digest[65];
    char runtime_generation[65];
    char config_digest[65];
    char config_path_digest[65];
    unsigned long publication_generation;
    char fence_kind[10];
    char candidate_transaction_id[33];
    char candidate_phase[40];
    char candidate_fence_digest[65];
};

struct wls_posix_recovery_context {
    struct wls_recovery_ledger state;
    char ledger_path[PATH_MAX];
    char status_path[PATH_MAX];
    uid_t owner_uid;
    int healthy_committed;
};

struct wls_guardian_transition_request {
    char host_id[33];
    char nonce[33];
    unsigned long long expected_head_sequence;
    char expected_head_sha256[65];
    char journal_sha256[65];
    char candidate_generation_id[65];
    char candidate_launcher_sha256[65];
    unsigned long long candidate_launcher_size;
    unsigned int candidate_launcher_mode;
    char candidate_ca_sha256[65];
    char candidate_runtime_generation[65];
    char recovery_generation_id[65];
    char recovery_launcher_sha256[65];
    unsigned long long recovery_launcher_size;
    unsigned int recovery_launcher_mode;
    char recovery_ca_sha256[65];
    char recovery_runtime_generation[65];
    char recovery_active_slot[2];
    char recovery_previous_slot[5];
    char recovery_slot_a_generation[65];
    char recovery_slot_b_generation[65];
    char derived_manifest_sha256[65];
    char derived_policy_sha256[65];
    char platform_kind[17];
    char platform_profile[10];
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
    int slot;
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
};

struct wls_guardian_health_receipt {
    char host_id[33];
    char host_boot_id[65];
    char admission_state[32];
    char active_slot[2];
    char runtime_generation[65];
    char bootstrap_receipt_sha256[65];
    char guardian_health_sha256[65];
    char controller_epoch[33];
    unsigned long long controller_generation;
    unsigned long long local_data_plane_generation;
    unsigned long long public_data_plane_generation;
    unsigned long long observed_monotonic_ms;
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

struct wls_guardian_probation_observation {
    int initialized;
    int reset_existing_probation;
    char identity_sha256[65];
    unsigned long long last_issued_monotonic_ms;
    unsigned long long required_promotion_since_floor;
    unsigned long long last_promotion_since_monotonic_ms;
    char last_receipt_sha256[65];
};

struct wls_guardian_probation_sample {
    int promotion_healthy;
    unsigned long long promotion_since_monotonic_ms;
    unsigned long long issued_monotonic_ms;
    char identity_sha256[65];
    char raw_sha256[65];
};

struct wls_guardian_inventory_entry {
    char category[32];
    char policy[10];
    char leaf[256];
    char kind;
    char closure_sha256[65];
};

struct wls_guardian_root_authority {
    char category[32];
    char policy[10];
    char authority_profile[64];
    char authority_policy[96];
    int present;
    unsigned long long device;
    unsigned long long inode;
    unsigned long long uid;
    unsigned long long gid;
    unsigned long long mode;
    char authority_sha256[65];
    char parent_authority_profile[64];
    char parent_authority_policy[96];
    unsigned long long parent_device;
    unsigned long long parent_inode;
    unsigned long long parent_uid;
    unsigned long long parent_gid;
    unsigned long long parent_mode;
    char parent_authority_sha256[65];
};

struct wls_guardian_recovery_inventory {
    struct wls_guardian_root_authority
        roots[WLS_GUARDIAN_DERIVED_CATEGORY_COUNT];
    struct wls_guardian_inventory_entry *entries;
    size_t count;
};

struct wls_guardian_recovery_transaction {
    unsigned long long sequence;
    unsigned long long cursor;
    char host_id[33];
    char nonce[33];
    char request_sha256[65];
    char authorization_sha256[65];
    char inventory_sha256[65];
    char phase[16];
    char previous_record_sha256[65];
    char signature[65];
    char raw_sha256[65];
};

struct wls_guardian_atomic_artifact {
    int present;
    int kind;
    char path[PATH_MAX];
    char leaf[NAME_MAX + 1U];
    struct stat identity;
};

struct wls_guardian_atomic_inventory {
    char parent_path[PATH_MAX];
    char target_leaf[NAME_MAX + 1U];
    struct stat parent_identity;
    struct wls_guardian_atomic_artifact temporary;
    struct wls_guardian_atomic_artifact backup;
};

static long long wls_monotonic_milliseconds(void);
static int wls_parse_process_attestation(
    const char *contents,
    size_t length,
    struct wls_process_attestation_receipt *receipt
);
static int wls_recovery_attested_process_live(
    const struct wls_process_attestation_receipt *receipt
);
static int wls_guardian_regular_state(
    const char *path,
    const char *sha256,
    unsigned long long size,
    mode_t mode
);
static int wls_guardian_recovery_transaction_binding(
    const char *home,
    const struct wls_guardian_recovery_transaction *transaction,
    const struct wls_guardian_transition_request *request
);
static int wls_guardian_text_digest(
    const char *text,
    char digest[65],
    unsigned long long *size
);
static int wls_guardian_acl_free_fd(int fd, int test_platform);

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

static int wls_wait_child_exit_until(
    pid_t child,
    int *status,
    long long deadline_monotonic
) {
    if (child <= 0 || deadline_monotonic <= 0) {
        errno = EINVAL;
        return -1;
    }
    for (;;) {
        long long now;
        long long remaining;
        useconds_t delay;
        pid_t waited;
        do {
            waited = waitpid(child, status, WNOHANG);
        } while (waited < 0 && errno == EINTR);
        if (waited == child || (waited < 0 && errno == ECHILD)) return 0;
        if (waited < 0) return -1;
        now = wls_monotonic_milliseconds();
        if (now <= 0 || now >= deadline_monotonic) {
            errno = ETIMEDOUT;
            return -1;
        }
        remaining = deadline_monotonic - now;
        delay = remaining < WLS_BROKER_REAP_POLL_MILLISECONDS
            ? (useconds_t)(remaining * 1000LL)
            : (useconds_t)(WLS_BROKER_REAP_POLL_MILLISECONDS * 1000LL);
        if (delay == 0U) {
            errno = ETIMEDOUT;
            return -1;
        }
        if (usleep(delay) != 0 && errno != EINTR) return -1;
    }
}

static int wls_wait_child_exit_for(
    pid_t child,
    int *status,
    long long timeout_milliseconds
) {
    long long now;
    long long deadline;
    if (child <= 0 || timeout_milliseconds <= 0) {
        errno = EINVAL;
        return -1;
    }
    now = wls_monotonic_milliseconds();
    if (now <= 0
        || wls_checked_add_long_long(
            now,
            timeout_milliseconds,
            &deadline
        ) != 0) {
        return -1;
    }
    return wls_wait_child_exit_until(child, status, deadline);
}

static void wls_kill_self_test_child_bounded(pid_t child)
{
    int status = 0;
    if (child <= 0) return;
    (void)kill(child, SIGKILL);
    (void)wls_wait_child_exit_for(
        child,
        &status,
        WLS_BROKER_KILL_REAP_MILLISECONDS
    );
}

/* Prove that the launcher helper reaps a normal child and times out, kills,
 * and reaps a stuck child without falling back to blocking waitpid. */
static int wls_wait_child_exit_deadline_self_test(void)
{
    long long now;
    long long deadline;
    pid_t child;
    int status = 0;

    child = fork();
    if (child < 0) return -1;
    if (child == 0) _exit(0);
    now = wls_monotonic_milliseconds();
    if (now <= 0
        || wls_checked_add_long_long(
            now,
            WLS_BROKER_KILL_REAP_MILLISECONDS,
            &deadline
        ) != 0
        || wls_wait_child_exit_until(child, &status, deadline) != 0
        || !WIFEXITED(status)
        || WEXITSTATUS(status) != 0) {
        wls_kill_self_test_child_bounded(child);
        return -1;
    }

    status = 0;
    child = fork();
    if (child < 0) return -1;
    if (child == 0) {
        for (;;) pause();
    }
    now = wls_monotonic_milliseconds();
    if (now <= 0
        || wls_checked_add_long_long(
            now,
            WLS_WAITPID_SELF_TEST_TIMEOUT_MILLISECONDS,
            &deadline
        ) != 0) {
        wls_kill_self_test_child_bounded(child);
        return -1;
    }
    errno = 0;
    if (wls_wait_child_exit_until(child, &status, deadline) == 0
        || errno != ETIMEDOUT) {
        wls_kill_self_test_child_bounded(child);
        return -1;
    }
    if (kill(child, SIGKILL) != 0 && errno != ESRCH) {
        wls_kill_self_test_child_bounded(child);
        return -1;
    }
    now = wls_monotonic_milliseconds();
    if (now <= 0
        || wls_checked_add_long_long(
            now,
            WLS_BROKER_KILL_REAP_MILLISECONDS,
            &deadline
        ) != 0
        || wls_wait_child_exit_until(child, &status, deadline) != 0
        || !WIFSIGNALED(status)
        || WTERMSIG(status) != SIGKILL) {
        wls_kill_self_test_child_bounded(child);
        return -1;
    }
    return 0;
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

/*
 * The stable launcher cannot execute a candidate slot merely to decide
 * whether that slot is a safe rollback target.  This bounded parser mirrors
 * PHP's recursively key-sorted JSON used by NginxRuntimeArtifact.  It rejects
 * duplicate object keys, escaped keys, excessive depth/work and trailing
 * bytes.  Keeping this proof in the root launcher makes it available before
 * active-slot can be changed and before any package executable is trusted.
 */
struct wls_launcher_json_parser {
    const char *cursor;
    const char *end;
    size_t depth;
    size_t nodes;
    size_t work;
    size_t work_maximum;
    size_t output_maximum;
    const char *excluded_top_level_key;
    size_t excluded_top_level_key_length;
    unsigned int excluded_top_level_count;
};

struct wls_launcher_json_buffer {
    char *bytes;
    size_t length;
    size_t capacity;
};

struct wls_launcher_json_member {
    char *key;
    size_t key_length;
    char *value;
    size_t value_length;
};

struct wls_launcher_json_field {
    const char *name;
    const char *value;
    size_t length;
    int found;
};

static void wls_launcher_json_skip_space(
    struct wls_launcher_json_parser *parser
) {
    while (parser->cursor < parser->end
        && (*parser->cursor == ' ' || *parser->cursor == '\t'
            || *parser->cursor == '\r' || *parser->cursor == '\n')) {
        parser->cursor++;
    }
}

static int wls_launcher_json_append(
    struct wls_launcher_json_parser *parser,
    struct wls_launcher_json_buffer *buffer,
    const char *bytes,
    size_t length
) {
    size_t required;
    size_t capacity;
    char *grown;
    if (parser == NULL || buffer == NULL || bytes == NULL
        || buffer->length > parser->output_maximum
        || length > parser->output_maximum - buffer->length
        || parser->work > parser->work_maximum
        || length > parser->work_maximum - parser->work) {
        return -1;
    }
    required = buffer->length + length;
    if (required > buffer->capacity) {
        capacity = buffer->capacity == 0U ? 64U : buffer->capacity;
        while (capacity < required) {
            if (capacity > parser->output_maximum / 2U) {
                capacity = parser->output_maximum;
                break;
            }
            capacity *= 2U;
        }
        if (capacity < required) return -1;
        grown = realloc(buffer->bytes, capacity);
        if (grown == NULL) return -1;
        buffer->bytes = grown;
        buffer->capacity = capacity;
    }
    memcpy(buffer->bytes + buffer->length, bytes, length);
    buffer->length = required;
    parser->work += length;
    return 0;
}

static void wls_launcher_json_buffer_free(
    struct wls_launcher_json_buffer *buffer
) {
    if (buffer == NULL) return;
    if (buffer->bytes != NULL) {
        sodium_memzero(buffer->bytes, buffer->capacity);
        free(buffer->bytes);
    }
    memset(buffer, 0, sizeof(*buffer));
}

static int wls_launcher_json_scan_string(
    struct wls_launcher_json_parser *parser,
    const char **start,
    size_t *length,
    int reject_escaped
) {
    const char *begin;
    if (parser == NULL || start == NULL || length == NULL
        || parser->cursor >= parser->end || *parser->cursor != '"') {
        return -1;
    }
    begin = parser->cursor++;
    while (parser->cursor < parser->end) {
        unsigned char value = (unsigned char)*parser->cursor++;
        if (value == '"') {
            *start = begin;
            *length = (size_t)(parser->cursor - begin);
            return 0;
        }
        if (value < 0x20U) return -1;
        if (value != '\\') continue;
        if (reject_escaped || parser->cursor >= parser->end) return -1;
        value = (unsigned char)*parser->cursor++;
        if (value == 'u') {
            size_t index;
            for (index = 0U; index < 4U; index++) {
                if (parser->cursor >= parser->end
                    || !(('0' <= *parser->cursor && *parser->cursor <= '9')
                        || ('a' <= *parser->cursor && *parser->cursor <= 'f')
                        || ('A' <= *parser->cursor && *parser->cursor <= 'F'))) {
                    return -1;
                }
                parser->cursor++;
            }
        } else if (value != '"' && value != '\\' && value != '/'
            && value != 'b' && value != 'f' && value != 'n'
            && value != 'r' && value != 't') {
            return -1;
        }
    }
    return -1;
}

static int wls_launcher_json_value(
    struct wls_launcher_json_parser *parser,
    struct wls_launcher_json_buffer *buffer
);

static int wls_launcher_json_member_compare(
    const void *left_pointer,
    const void *right_pointer
) {
    const struct wls_launcher_json_member *left = left_pointer;
    const struct wls_launcher_json_member *right = right_pointer;
    size_t common = left->key_length < right->key_length
        ? left->key_length : right->key_length;
    int compared = memcmp(left->key, right->key, common);
    if (compared != 0) return compared;
    if (left->key_length < right->key_length) return -1;
    if (left->key_length > right->key_length) return 1;
    return 0;
}

static int wls_launcher_json_object(
    struct wls_launcher_json_parser *parser,
    struct wls_launcher_json_buffer *buffer
) {
    struct wls_launcher_json_member *members = NULL;
    size_t count = 0U;
    size_t capacity = 0U;
    size_t index;
    int result = -1;
    if (parser->depth >= 32U || parser->cursor >= parser->end
        || *parser->cursor++ != '{') return -1;
    parser->depth++;
    wls_launcher_json_skip_space(parser);
    if (parser->cursor < parser->end && *parser->cursor == '}') {
        parser->cursor++;
        result = wls_launcher_json_append(parser, buffer, "{}", 2U);
        goto cleanup;
    }
    for (;;) {
        const char *key_start = NULL;
        size_t key_token_length = 0U;
        size_t key_length;
        int excluded = 0;
        struct wls_launcher_json_buffer value = {0};
        struct wls_launcher_json_member *grown;
        if (parser->nodes++ >= 262144U
            || wls_launcher_json_scan_string(
                parser, &key_start, &key_token_length, 1
            ) != 0 || key_token_length < 2U) goto cleanup;
        key_length = key_token_length - 2U;
        if (parser->depth == 1U
            && parser->excluded_top_level_key != NULL
            && key_length == parser->excluded_top_level_key_length
            && memcmp(
                key_start + 1U,
                parser->excluded_top_level_key,
                key_length
            ) == 0) {
            if (++parser->excluded_top_level_count != 1U) goto cleanup;
            excluded = 1;
        }
        wls_launcher_json_skip_space(parser);
        if (parser->cursor >= parser->end || *parser->cursor++ != ':') {
            goto cleanup;
        }
        wls_launcher_json_skip_space(parser);
        if (wls_launcher_json_value(parser, &value) != 0) {
            wls_launcher_json_buffer_free(&value);
            goto cleanup;
        }
        if (excluded) {
            wls_launcher_json_buffer_free(&value);
        } else {
            if (count == capacity) {
                size_t next = capacity == 0U ? 8U : capacity * 2U;
                if (next < capacity
                    || next > SIZE_MAX / sizeof(*members)) {
                    wls_launcher_json_buffer_free(&value);
                    goto cleanup;
                }
                grown = realloc(members, next * sizeof(*members));
                if (grown == NULL) {
                    wls_launcher_json_buffer_free(&value);
                    goto cleanup;
                }
                members = grown;
                capacity = next;
            }
            memset(&members[count], 0, sizeof(members[count]));
            members[count].key_length = key_length;
            members[count].key = malloc(key_length + 1U);
            if (members[count].key == NULL) {
                wls_launcher_json_buffer_free(&value);
                goto cleanup;
            }
            memcpy(members[count].key, key_start + 1U, key_length);
            members[count].key[key_length] = '\0';
            members[count].value = value.bytes;
            members[count].value_length = value.length;
            value.bytes = NULL;
            value.length = 0U;
            value.capacity = 0U;
            count++;
        }
        wls_launcher_json_skip_space(parser);
        if (parser->cursor >= parser->end) goto cleanup;
        if (*parser->cursor == '}') {
            parser->cursor++;
            break;
        }
        if (*parser->cursor++ != ',') goto cleanup;
        wls_launcher_json_skip_space(parser);
    }
    qsort(members, count, sizeof(*members), wls_launcher_json_member_compare);
    for (index = 1U; index < count; index++) {
        if (members[index - 1U].key_length == members[index].key_length
            && memcmp(
                members[index - 1U].key,
                members[index].key,
                members[index].key_length
            ) == 0) goto cleanup;
    }
    if (wls_launcher_json_append(parser, buffer, "{", 1U) != 0) goto cleanup;
    for (index = 0U; index < count; index++) {
        if ((index > 0U
                && wls_launcher_json_append(parser, buffer, ",", 1U) != 0)
            || wls_launcher_json_append(parser, buffer, "\"", 1U) != 0
            || wls_launcher_json_append(
                parser, buffer, members[index].key,
                members[index].key_length
            ) != 0
            || wls_launcher_json_append(parser, buffer, "\":", 2U) != 0
            || wls_launcher_json_append(
                parser, buffer, members[index].value,
                members[index].value_length
            ) != 0) goto cleanup;
    }
    if (wls_launcher_json_append(parser, buffer, "}", 1U) != 0) goto cleanup;
    result = 0;
cleanup:
    if (parser->depth > 0U) parser->depth--;
    for (index = 0U; index < count; index++) {
        if (members[index].key != NULL) {
            sodium_memzero(members[index].key, members[index].key_length);
            free(members[index].key);
        }
        if (members[index].value != NULL) {
            sodium_memzero(members[index].value, members[index].value_length);
            free(members[index].value);
        }
    }
    free(members);
    return result;
}

static int wls_launcher_json_number(
    struct wls_launcher_json_parser *parser,
    struct wls_launcher_json_buffer *buffer
) {
    const char *start = parser->cursor;
    if (parser->cursor < parser->end && *parser->cursor == '-') {
        parser->cursor++;
    }
    if (parser->cursor >= parser->end) return -1;
    if (*parser->cursor == '0') {
        parser->cursor++;
        if (parser->cursor < parser->end
            && '0' <= *parser->cursor && *parser->cursor <= '9') return -1;
    } else {
        if (*parser->cursor < '1' || *parser->cursor > '9') return -1;
        while (parser->cursor < parser->end
            && '0' <= *parser->cursor && *parser->cursor <= '9') {
            parser->cursor++;
        }
    }
    if (parser->cursor < parser->end && *parser->cursor == '.') {
        parser->cursor++;
        if (parser->cursor >= parser->end
            || *parser->cursor < '0' || *parser->cursor > '9') return -1;
        while (parser->cursor < parser->end
            && '0' <= *parser->cursor && *parser->cursor <= '9') {
            parser->cursor++;
        }
    }
    if (parser->cursor < parser->end
        && (*parser->cursor == 'e' || *parser->cursor == 'E')) {
        parser->cursor++;
        if (parser->cursor < parser->end
            && (*parser->cursor == '+' || *parser->cursor == '-')) {
            parser->cursor++;
        }
        if (parser->cursor >= parser->end
            || *parser->cursor < '0' || *parser->cursor > '9') return -1;
        while (parser->cursor < parser->end
            && '0' <= *parser->cursor && *parser->cursor <= '9') {
            parser->cursor++;
        }
    }
    return wls_launcher_json_append(
        parser, buffer, start, (size_t)(parser->cursor - start)
    );
}

static int wls_launcher_json_value(
    struct wls_launcher_json_parser *parser,
    struct wls_launcher_json_buffer *buffer
) {
    const char *start = NULL;
    size_t length = 0U;
    if (parser == NULL || buffer == NULL) return -1;
    wls_launcher_json_skip_space(parser);
    if (parser->cursor >= parser->end) return -1;
    if (*parser->cursor == '{') {
        return wls_launcher_json_object(parser, buffer);
    }
    if (*parser->cursor == '[') {
        if (parser->depth >= 32U) return -1;
        parser->cursor++;
        parser->depth++;
        if (wls_launcher_json_append(parser, buffer, "[", 1U) != 0) {
            parser->depth--;
            return -1;
        }
        wls_launcher_json_skip_space(parser);
        if (parser->cursor < parser->end && *parser->cursor == ']') {
            parser->cursor++;
            parser->depth--;
            return wls_launcher_json_append(parser, buffer, "]", 1U);
        }
        for (;;) {
            if (parser->nodes++ >= 262144U
                || wls_launcher_json_value(parser, buffer) != 0) {
                parser->depth--;
                return -1;
            }
            wls_launcher_json_skip_space(parser);
            if (parser->cursor >= parser->end) {
                parser->depth--;
                return -1;
            }
            if (*parser->cursor == ']') {
                parser->cursor++;
                parser->depth--;
                return wls_launcher_json_append(parser, buffer, "]", 1U);
            }
            if (*parser->cursor++ != ','
                || wls_launcher_json_append(parser, buffer, ",", 1U) != 0) {
                parser->depth--;
                return -1;
            }
            wls_launcher_json_skip_space(parser);
        }
    }
    if (*parser->cursor == '"') {
        if (wls_launcher_json_scan_string(
                parser, &start, &length, 0
            ) != 0) return -1;
        return wls_launcher_json_append(parser, buffer, start, length);
    }
    if ((size_t)(parser->end - parser->cursor) >= 4U
        && memcmp(parser->cursor, "true", 4U) == 0) {
        parser->cursor += 4U;
        return wls_launcher_json_append(parser, buffer, "true", 4U);
    }
    if ((size_t)(parser->end - parser->cursor) >= 5U
        && memcmp(parser->cursor, "false", 5U) == 0) {
        parser->cursor += 5U;
        return wls_launcher_json_append(parser, buffer, "false", 5U);
    }
    if ((size_t)(parser->end - parser->cursor) >= 4U
        && memcmp(parser->cursor, "null", 4U) == 0) {
        parser->cursor += 4U;
        return wls_launcher_json_append(parser, buffer, "null", 4U);
    }
    return wls_launcher_json_number(parser, buffer);
}

static int wls_launcher_json_canonical_digest(
    const char *json,
    size_t length,
    const char *excluded_top_level_key,
    char digest[65]
) {
    struct wls_launcher_json_parser parser;
    struct wls_launcher_json_buffer canonical = {0};
    int result = -1;
    if (json == NULL || length == 0U || digest == NULL
        || length > WLS_MAX_MANIFEST || length > SIZE_MAX / 32U) return -1;
    memset(&parser, 0, sizeof(parser));
    parser.cursor = json;
    parser.end = json + length;
    parser.work_maximum = length * 32U;
    parser.output_maximum = length;
    parser.excluded_top_level_key = excluded_top_level_key;
    parser.excluded_top_level_key_length = excluded_top_level_key == NULL
        ? 0U : strlen(excluded_top_level_key);
    if (wls_launcher_json_value(&parser, &canonical) != 0) goto cleanup;
    wls_launcher_json_skip_space(&parser);
    if (parser.cursor != parser.end || canonical.length == 0U
        || (excluded_top_level_key != NULL
            && parser.excluded_top_level_count != 1U)) goto cleanup;
    {
        unsigned char binary[crypto_hash_sha256_BYTES];
        if (crypto_hash_sha256(
                binary,
                (const unsigned char *)canonical.bytes,
                (unsigned long long)canonical.length
            ) != 0) {
            sodium_memzero(binary, sizeof(binary));
            goto cleanup;
        }
        sodium_bin2hex(digest, 65U, binary, sizeof(binary));
        sodium_memzero(binary, sizeof(binary));
    }
    result = wls_is_hex_text(digest, 64U) ? 0 : -1;
cleanup:
    wls_launcher_json_buffer_free(&canonical);
    return result;
}

static int wls_launcher_json_object_fields(
    const char *json,
    size_t length,
    struct wls_launcher_json_field *fields,
    size_t field_count,
    size_t *member_count
) {
    struct wls_launcher_json_parser parser;
    size_t members = 0U;
    size_t index;
    int result = -1;
    if (json == NULL || fields == NULL || field_count == 0U
        || length == 0U || length > WLS_MAX_MANIFEST
        || length > SIZE_MAX / 32U) return -1;
    memset(&parser, 0, sizeof(parser));
    parser.cursor = json;
    parser.end = json + length;
    parser.work_maximum = length * 32U;
    parser.output_maximum = length;
    wls_launcher_json_skip_space(&parser);
    if (parser.cursor >= parser.end || *parser.cursor++ != '{') return -1;
    wls_launcher_json_skip_space(&parser);
    if (parser.cursor < parser.end && *parser.cursor == '}') {
        parser.cursor++;
        goto finished;
    }
    for (;;) {
        const char *key = NULL;
        size_t key_length = 0U;
        const char *value;
        struct wls_launcher_json_buffer discarded = {0};
        if (wls_launcher_json_scan_string(
                &parser, &key, &key_length, 1
            ) != 0 || key_length < 2U) goto cleanup;
        wls_launcher_json_skip_space(&parser);
        if (parser.cursor >= parser.end || *parser.cursor++ != ':') goto cleanup;
        wls_launcher_json_skip_space(&parser);
        value = parser.cursor;
        if (wls_launcher_json_value(&parser, &discarded) != 0) {
            wls_launcher_json_buffer_free(&discarded);
            goto cleanup;
        }
        wls_launcher_json_buffer_free(&discarded);
        members++;
        for (index = 0U; index < field_count; index++) {
            size_t expected = strlen(fields[index].name);
            if (key_length == expected + 2U
                && memcmp(key + 1U, fields[index].name, expected) == 0) {
                if (fields[index].found) goto cleanup;
                fields[index].value = value;
                fields[index].length = (size_t)(parser.cursor - value);
                fields[index].found = 1;
            }
        }
        wls_launcher_json_skip_space(&parser);
        if (parser.cursor >= parser.end) goto cleanup;
        if (*parser.cursor == '}') {
            parser.cursor++;
            break;
        }
        if (*parser.cursor++ != ',') goto cleanup;
        wls_launcher_json_skip_space(&parser);
    }
finished:
    wls_launcher_json_skip_space(&parser);
    if (parser.cursor != parser.end) goto cleanup;
    if (member_count != NULL) *member_count = members;
    result = 0;
cleanup:
    return result;
}

static int wls_launcher_json_field_string(
    const struct wls_launcher_json_field *field,
    char *output,
    size_t capacity
) {
    size_t length;
    size_t index;
    if (field == NULL || !field->found || output == NULL || capacity < 2U
        || field->length < 2U || field->value[0] != '"'
        || field->value[field->length - 1U] != '"') return -1;
    length = field->length - 2U;
    if (length == 0U || length + 1U > capacity) return -1;
    for (index = 0U; index < length; index++) {
        unsigned char value = (unsigned char)field->value[index + 1U];
        if (value < 0x20U || value == '\\' || value == '"') return -1;
    }
    memcpy(output, field->value + 1U, length);
    output[length] = '\0';
    return 0;
}

static int wls_launcher_json_field_unsigned_long_long(
    const struct wls_launcher_json_field *field,
    unsigned long long *output
) {
    char text[32];
    char *end = NULL;
    unsigned long long value;
    if (field == NULL || !field->found || output == NULL
        || field->length == 0U || field->length >= sizeof(text)) return -1;
    memcpy(text, field->value, field->length);
    text[field->length] = '\0';
    if ((field->length > 1U && text[0] == '0') || text[0] == '-') return -1;
    errno = 0;
    value = strtoull(text, &end, 10);
    if (errno != 0 || end == text || *end != '\0') return -1;
    *output = value;
    return 0;
}

static int wls_launcher_json_field_true(
    const struct wls_launcher_json_field *field
) {
    return field != NULL && field->found && field->length == 4U
        && memcmp(field->value, "true", 4U) == 0
        ? 0
        : -1;
}

static int wls_launcher_same_file_state(
    const struct stat *left,
    const struct stat *right
) {
    if (left == NULL || right == NULL
        || left->st_dev != right->st_dev
        || left->st_ino != right->st_ino
        || left->st_mode != right->st_mode
        || left->st_uid != right->st_uid
        || left->st_gid != right->st_gid
        || left->st_nlink != right->st_nlink
        || left->st_size != right->st_size) return 0;
#if defined(__APPLE__)
    return left->st_mtimespec.tv_sec == right->st_mtimespec.tv_sec
        && left->st_mtimespec.tv_nsec == right->st_mtimespec.tv_nsec
        && left->st_ctimespec.tv_sec == right->st_ctimespec.tv_sec
        && left->st_ctimespec.tv_nsec == right->st_ctimespec.tv_nsec;
#else
    return left->st_mtim.tv_sec == right->st_mtim.tv_sec
        && left->st_mtim.tv_nsec == right->st_mtim.tv_nsec
        && left->st_ctim.tv_sec == right->st_ctim.tv_sec
        && left->st_ctim.tv_nsec == right->st_ctim.tv_nsec;
#endif
}

static int wls_launcher_safe_directory_fd(int fd, struct stat *status)
{
    struct stat observed;
    if (fd < 0 || fstat(fd, &observed) != 0
        || !S_ISDIR(observed.st_mode)
        || observed.st_uid != geteuid()
        || (observed.st_mode & 0022) != 0) return -1;
    if (status != NULL) *status = observed;
    return 0;
}

static int wls_launcher_exact_directory_fd(
    int fd,
    unsigned long long expected_mode,
    struct stat *status
) {
    struct stat observed;
    if (wls_launcher_safe_directory_fd(fd, &observed) != 0
        || (unsigned long long)(observed.st_mode & 0777)
            != expected_mode) return -1;
    if (status != NULL) *status = observed;
    return 0;
}

static int wls_launcher_read_regular_at(
    int parent_fd,
    const char *leaf,
    size_t maximum,
    unsigned char **contents,
    size_t *length,
    struct stat *identity
) {
    struct stat before = {0};
    struct stat after = {0};
    unsigned char *buffer = NULL;
    size_t used = 0U;
    int fd = -1;
    int result = -1;
    if (parent_fd < 0 || leaf == NULL || leaf[0] == '\0'
        || strchr(leaf, '/') != NULL || contents == NULL || length == NULL) {
        return -1;
    }
    fd = openat(parent_fd, leaf, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || fstat(fd, &before) != 0 || !S_ISREG(before.st_mode)
        || before.st_uid != geteuid() || before.st_nlink != 1
        || (before.st_mode & 0022) != 0 || before.st_size < 0
        || (uint64_t)before.st_size > maximum) goto cleanup;
    buffer = calloc((size_t)before.st_size + 1U, 1U);
    if (buffer == NULL) goto cleanup;
    while (used < (size_t)before.st_size) {
        ssize_t amount = read(fd, buffer + used, (size_t)before.st_size - used);
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) goto cleanup;
        used += (size_t)amount;
    }
    {
        unsigned char extra;
        ssize_t amount;
        do {
            amount = read(fd, &extra, 1U);
        } while (amount < 0 && errno == EINTR);
        if (amount != 0) goto cleanup;
    }
    if (fstat(fd, &after) != 0
        || !wls_launcher_same_file_state(&before, &after)) goto cleanup;
    *contents = buffer;
    *length = used;
    if (identity != NULL) *identity = after;
    buffer = NULL;
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    if (buffer != NULL) {
        sodium_memzero(buffer, (size_t)(before.st_size > 0 ? before.st_size : 0));
        free(buffer);
    }
    return result;
}

static int wls_launcher_revalidate_regular_at(
    int parent_fd,
    const char *leaf,
    size_t maximum,
    const unsigned char *expected,
    size_t expected_length,
    const struct stat *expected_identity
) {
    unsigned char *actual = NULL;
    size_t actual_length = 0U;
    struct stat actual_identity;
    int result = -1;
    if (expected == NULL || expected_identity == NULL
        || wls_launcher_read_regular_at(
            parent_fd,
            leaf,
            maximum,
            &actual,
            &actual_length,
            &actual_identity
        ) != 0
        || actual_length != expected_length
        || !wls_launcher_same_file_state(
            expected_identity, &actual_identity
        )
        || sodium_memcmp(expected, actual, expected_length) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (actual != NULL) {
        sodium_memzero(actual, actual_length);
        free(actual);
    }
    return result;
}

static int wls_launcher_buffer_digest(
    const unsigned char *contents,
    size_t length,
    char digest[65]
) {
    unsigned char binary[crypto_hash_sha256_BYTES];
    if (contents == NULL || digest == NULL
        || crypto_hash_sha256(
            binary,
            contents,
            (unsigned long long)length
        ) != 0) {
        sodium_memzero(binary, sizeof(binary));
        return -1;
    }
    sodium_bin2hex(digest, 65U, binary, sizeof(binary));
    sodium_memzero(binary, sizeof(binary));
    return wls_is_hex_text(digest, 64U) ? 0 : -1;
}

static int wls_launcher_verify_release_signature(
    const unsigned char *manifest,
    size_t manifest_length,
    const unsigned char *signature_text,
    size_t signature_length
) {
    unsigned char public_key[crypto_sign_PUBLICKEYBYTES];
    unsigned char signature[crypto_sign_BYTES];
    size_t decoded = 0U;
    int result = -1;
    if (manifest == NULL || manifest_length == 0U
        || signature_text == NULL || signature_length == 0U
        || wls_public_key(public_key) != 0
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
        || decoded != sizeof(signature)
        || crypto_sign_verify_detached(
            signature,
            manifest,
            (unsigned long long)manifest_length,
            public_key
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(public_key, sizeof(public_key));
    sodium_memzero(signature, sizeof(signature));
    return result;
}

static int wls_launcher_exact_durable_contract(
    const struct wls_launcher_json_field *contract
) {
    struct wls_launcher_json_field fields[] = {
        {"schema_version", NULL, 0U, 0},
        {"security_ledger_read_schema", NULL, 0U, 0},
        {"security_ledger_write_schema", NULL, 0U, 0},
        {"snapshot_receipt_read_schema", NULL, 0U, 0},
        {"snapshot_receipt_write_schema", NULL, 0U, 0},
        {"snapshot_namespace", NULL, 0U, 0},
        {"nonce_wal_schema", NULL, 0U, 0},
        {"nginx_test_schema", NULL, 0U, 0},
    };
    const unsigned long long expected[] = {2ULL, 8ULL, 8ULL, 2ULL, 2ULL};
    char namespace_value[32];
    unsigned long long value;
    size_t members = 0U;
    size_t index;
    if (contract == NULL || !contract->found || contract->length < 2U
        || contract->value[0] != '{'
        || wls_launcher_json_object_fields(
            contract->value,
            contract->length,
            fields,
            sizeof(fields) / sizeof(fields[0]),
            &members
        ) != 0 || members != sizeof(fields) / sizeof(fields[0])) return -1;
    for (index = 0U; index < 5U; index++) {
        if (wls_launcher_json_field_unsigned_long_long(
                &fields[index], &value
            ) != 0 || value != expected[index]) return -1;
    }
    if (wls_launcher_json_field_string(
            &fields[5], namespace_value, sizeof(namespace_value)
        ) != 0 || strcmp(namespace_value, "snapshots-v2") != 0
        || wls_launcher_json_field_unsigned_long_long(
            &fields[6], &value
        ) != 0 || value != 1ULL
        || wls_launcher_json_field_unsigned_long_long(
            &fields[7], &value
        ) != 0 || value != 1ULL) return -1;
    return 0;
}

static int wls_launcher_manifest_contract(
    const unsigned char *manifest,
    size_t length,
    char expected_slot,
    struct wls_launcher_json_field *components,
    char runtime_generation[65]
) {
    struct wls_launcher_json_field fields[] = {
        {"schema_version", NULL, 0U, 0},
        {"role", NULL, 0U, 0},
        {"slot", NULL, 0U, 0},
        {"runtime_generation", NULL, 0U, 0},
        {"durable_state_contract", NULL, 0U, 0},
        {"components", NULL, 0U, 0},
        {"implementation_level", NULL, 0U, 0},
        {"capabilities", NULL, 0U, 0},
    };
    struct wls_launcher_json_field capability[] = {
        {"stable_launcher_rollback_target_proof", NULL, 0U, 0},
        {"certificate_public_trust_bundle", NULL, 0U, 0},
    };
    char canonical_digest[65];
    char role[32];
    char slot[4];
    char implementation[32];
    unsigned long long schema = 0ULL;
    size_t members = 0U;
    int installed = expected_slot == 'A' || expected_slot == 'B';
    if (manifest == NULL || length == 0U || components == NULL
        || wls_launcher_json_object_fields(
            (const char *)manifest,
            length,
            fields,
            sizeof(fields) / sizeof(fields[0]),
            &members
        ) != 0
        || wls_launcher_json_field_unsigned_long_long(
            &fields[0], &schema
        ) != 0 || schema != 2ULL
        || wls_launcher_exact_durable_contract(&fields[4]) != 0
        || !fields[5].found || fields[5].length < 2U
        || fields[5].value[0] != '{'
        || !fields[7].found || fields[7].length < 2U
        || fields[7].value[0] != '{'
        || wls_launcher_json_object_fields(
            fields[7].value,
            fields[7].length,
            capability,
            sizeof(capability) / sizeof(capability[0]),
            NULL
        ) != 0 || wls_launcher_json_field_true(&capability[0]) != 0
        || wls_launcher_json_field_true(&capability[1]) != 0) {
        return -1;
    }
    (void)members;
    if (installed) {
        if (wls_launcher_json_field_string(
                &fields[1], role, sizeof(role)
            ) != 0 || strcmp(role, "host_gateway") != 0
            || wls_launcher_json_field_string(
                &fields[2], slot, sizeof(slot)
            ) != 0 || slot[0] != expected_slot || slot[1] != '\0'
            || wls_launcher_json_field_string(
                &fields[3], runtime_generation, 65U
            ) != 0 || !wls_is_hex_text(runtime_generation, 64U)
            || wls_launcher_json_canonical_digest(
                (const char *)manifest,
                length,
                "runtime_generation",
                canonical_digest
            ) != 0
            || sodium_memcmp(
                runtime_generation, canonical_digest, 64U
            ) != 0) return -1;
    } else {
        if (wls_launcher_json_canonical_digest(
                (const char *)manifest,
                length,
                NULL,
                canonical_digest
            ) != 0
            || wls_launcher_json_field_string(
                &fields[6], implementation, sizeof(implementation)
            ) != 0 || strcmp(implementation, "wls-2.0") != 0) return -1;
        if (runtime_generation != NULL) runtime_generation[0] = '\0';
    }
    *components = fields[5];
    sodium_memzero(canonical_digest, sizeof(canonical_digest));
    return 0;
}

static int wls_launcher_component_definition(
    const struct wls_launcher_json_field *components,
    const char *relative,
    char digest[65],
    unsigned long long *size,
    unsigned long long *mode
) {
    struct wls_launcher_json_field component[] = {
        {relative, NULL, 0U, 0},
    };
    struct wls_launcher_json_field definition[] = {
        {"sha256", NULL, 0U, 0},
        {"size", NULL, 0U, 0},
        {"mode", NULL, 0U, 0},
    };
    size_t members = 0U;
    if (components == NULL || !components->found || relative == NULL
        || wls_launcher_json_object_fields(
            components->value,
            components->length,
            component,
            1U,
            NULL
        ) != 0 || !component[0].found || component[0].length < 2U
        || component[0].value[0] != '{'
        || wls_launcher_json_object_fields(
            component[0].value,
            component[0].length,
            definition,
            sizeof(definition) / sizeof(definition[0]),
            &members
        ) != 0 || members != sizeof(definition) / sizeof(definition[0])
        || wls_launcher_json_field_string(
            &definition[0], digest, 65U
        ) != 0 || !wls_is_hex_text(digest, 64U)
        || wls_launcher_json_field_unsigned_long_long(
            &definition[1], size
        ) != 0
        || wls_launcher_json_field_unsigned_long_long(
            &definition[2], mode
        ) != 0 || *mode > 0777ULL) return -1;
    return 0;
}

static int wls_launcher_open_component_fd(
    int slot_fd,
    const char *relative,
    int *component_fd
) {
    char directory[32];
    const char *slash;
    size_t directory_length;
    int directory_fd = -1;
    int fd = -1;
    if (component_fd != NULL) *component_fd = -1;
    if (slot_fd < 0 || relative == NULL || component_fd == NULL
        || (slash = strchr(relative, '/')) == NULL
        || strchr(slash + 1U, '/') != NULL || slash[1] == '\0') return -1;
    directory_length = (size_t)(slash - relative);
    if (directory_length == 0U || directory_length >= sizeof(directory)) {
        return -1;
    }
    memcpy(directory, relative, directory_length);
    directory[directory_length] = '\0';
    directory_fd = openat(
        slot_fd, directory,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            directory_fd, 0755ULL, NULL
        ) != 0) goto cleanup;
    fd = openat(directory_fd, slash + 1U, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0) goto cleanup;
    *component_fd = fd;
    fd = -1;
cleanup:
    if (fd >= 0) close(fd);
    if (directory_fd >= 0) close(directory_fd);
    return *component_fd >= 0 ? 0 : -1;
}

static int wls_launcher_digest_component_fd(
    int fd,
    char digest[65],
    unsigned long long *size,
    unsigned long long *mode
) {
    struct stat before;
    struct stat after;
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    unsigned char buffer[65536];
    ssize_t amount;
    int result = -1;
    if (fd < 0 || digest == NULL || size == NULL || mode == NULL
        || fstat(fd, &before) != 0 || !S_ISREG(before.st_mode)
        || before.st_uid != geteuid() || before.st_nlink != 1
        || (before.st_mode & 0022) != 0 || before.st_size < 0
        || lseek(fd, 0, SEEK_SET) < 0
        || crypto_hash_sha256_init(&state) != 0) return -1;
    for (;;) {
        amount = read(fd, buffer, sizeof(buffer));
        if (amount < 0 && errno == EINTR) continue;
        if (amount < 0) goto cleanup;
        if (amount == 0) break;
        if (crypto_hash_sha256_update(
                &state, buffer, (unsigned long long)amount
            ) != 0) goto cleanup;
    }
    if (fstat(fd, &after) != 0
        || !wls_launcher_same_file_state(&before, &after)
        || crypto_hash_sha256_final(&state, binary) != 0) goto cleanup;
    sodium_bin2hex(digest, 65U, binary, sizeof(binary));
    *size = (unsigned long long)after.st_size;
    *mode = (unsigned long long)(after.st_mode & 0777);
    result = wls_is_hex_text(digest, 64U) ? 0 : -1;
cleanup:
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    sodium_memzero(buffer, sizeof(buffer));
    return result;
}

static int wls_launcher_expected_component_modes(
    const char *relative,
    unsigned long long *release_mode,
    unsigned long long *installed_mode
) {
    if (relative == NULL || release_mode == NULL || installed_mode == NULL) {
        return -1;
    }
    if (strcmp(relative, "bin/wls-gateway-broker") == 0
        || strcmp(relative, "bin/wls-gateway-launcher") == 0
        || strcmp(relative, "bin/php") == 0
        || strcmp(relative, "bin/nginx") == 0) {
        *release_mode = 0755ULL;
        *installed_mode = 0555ULL;
        return 0;
    }
    if (strcmp(relative, "app/controller.php") == 0) {
        *release_mode = 0644ULL;
        *installed_mode = 0444ULL;
        return 0;
    }
    if (strcmp(relative, "share/ca-bundle.pem") == 0) {
        *release_mode = 0644ULL;
        *installed_mode = 0444ULL;
        return 0;
    }
    return -1;
}

static int wls_launcher_component_proof(
    int slot_fd,
    const struct wls_launcher_json_field *release_components,
    const struct wls_launcher_json_field *installed_components,
    const char *relative
) {
    char release_digest[65];
    char installed_digest[65];
    char actual_digest[65];
    unsigned long long release_size = 0ULL;
    unsigned long long release_mode = 0ULL;
    unsigned long long installed_size = 0ULL;
    unsigned long long installed_mode = 0ULL;
    unsigned long long actual_size = 0ULL;
    unsigned long long actual_mode = 0ULL;
    unsigned long long expected_release_mode = 0ULL;
    unsigned long long expected_installed_mode = 0ULL;
    struct stat opened_identity;
    int fd = -1;
    int result = -1;
    if (wls_launcher_expected_component_modes(
            relative, &expected_release_mode, &expected_installed_mode
        ) != 0
        || wls_launcher_component_definition(
            release_components, relative, release_digest,
            &release_size, &release_mode
        ) != 0
        || wls_launcher_component_definition(
            installed_components, relative, installed_digest,
            &installed_size, &installed_mode
        ) != 0
        || sodium_memcmp(release_digest, installed_digest, 64U) != 0
        || release_size != installed_size
        /*
         * The signed release records package transport modes.  A production
         * POSIX install deliberately seals the immutable, data-plane-readable
         * slot to 0555/0444.  Treating the two manifests as raw mode peers
         * makes every correctly sealed production slot unbootable.
         */
        || release_mode != expected_release_mode
        || installed_mode != expected_installed_mode
        || wls_launcher_open_component_fd(slot_fd, relative, &fd) != 0
        || fstat(fd, &opened_identity) != 0
        || opened_identity.st_size < 0
        || (unsigned long long)opened_identity.st_size != installed_size
        || wls_launcher_digest_component_fd(
            fd, actual_digest, &actual_size, &actual_mode
        ) != 0
        || sodium_memcmp(installed_digest, actual_digest, 64U) != 0
        || installed_size != actual_size || installed_mode != actual_mode) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    sodium_memzero(release_digest, sizeof(release_digest));
    sodium_memzero(installed_digest, sizeof(installed_digest));
    sodium_memzero(actual_digest, sizeof(actual_digest));
    return result;
}

static int wls_launcher_installed_leaf_proof(
    const struct wls_launcher_json_field *installed_components,
    const char *relative,
    const unsigned char *contents,
    size_t length,
    const struct stat *status
) {
    char expected[65];
    char actual[65];
    unsigned long long expected_size = 0ULL;
    unsigned long long expected_mode = 0ULL;
    unsigned long long required_mode = 0ULL;
    if (relative != NULL
        && (strcmp(relative, "release/manifest.json") == 0
            || strcmp(relative, "release/manifest.sig") == 0)) {
        required_mode = 0444ULL;
    } else {
        return -1;
    }
    if (contents == NULL || status == NULL
        || wls_launcher_component_definition(
            installed_components, relative, expected,
            &expected_size, &expected_mode
        ) != 0
        || wls_launcher_buffer_digest(contents, length, actual) != 0
        || sodium_memcmp(expected, actual, 64U) != 0
        || expected_size != (unsigned long long)length
        || expected_size != (unsigned long long)status->st_size
        || expected_mode != required_mode
        || expected_mode != (unsigned long long)(status->st_mode & 0777)) {
        sodium_memzero(expected, sizeof(expected));
        sodium_memzero(actual, sizeof(actual));
        return -1;
    }
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
    return 0;
}

static int wls_launcher_parse_ca_bundle_baseline(
    const unsigned char *contents,
    size_t length,
    char digest[65]
) {
    int result = -1;
    if (digest == NULL) return -1;
    digest[0] = '\0';
    if (contents == NULL || length != 65U || contents[64] != '\n') {
        return -1;
    }
    memcpy(digest, contents, 64U);
    digest[64] = '\0';
    if (wls_is_hex_text(digest, 64U)) result = 0;
    if (result != 0) sodium_memzero(digest, 65U);
    return result;
}

/* Resolve the host trust anchor only below the already proved gateway home.
 * Both namespace edges are opened no-follow; the leaf is observed through one
 * descriptor before and after its exact bounded read. */
static int wls_launcher_ca_bundle_baseline(
    int home_fd,
    char digest[65]
) {
    unsigned char *contents = NULL;
    size_t length = 0U;
    struct stat identity;
    int trust_fd = -1;
    int result = -1;
    if (home_fd < 0 || digest == NULL) return -1;
    digest[0] = '\0';
    trust_fd = openat(
        home_fd, "trust", O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(trust_fd, 0750ULL, NULL) != 0
        || wls_launcher_read_regular_at(
            trust_fd,
            "ca-bundle.sha256",
            65U,
            &contents,
            &length,
            &identity
        ) != 0
        || (unsigned long long)(identity.st_mode & 0777) != 0600ULL
        || wls_launcher_parse_ca_bundle_baseline(
            contents, length, digest
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (trust_fd >= 0) close(trust_fd);
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    if (result != 0) sodium_memzero(digest, 65U);
    return result;
}

static int wls_launcher_slot_contract_v2(
    const char *home,
    char slot,
    char runtime_generation[65]
) {
    static const char *required_components[] = {
        "bin/wls-gateway-broker",
        "bin/wls-gateway-launcher",
        "bin/php",
        "bin/nginx",
        "app/controller.php",
        "share/ca-bundle.pem",
    };
    char slot_name[2] = {slot, '\0'};
    struct stat home_identity;
    struct stat slots_identity;
    struct stat slot_identity;
    struct stat release_identity;
    struct stat release_manifest_identity;
    struct stat release_signature_identity;
    struct stat installed_manifest_identity;
    struct stat reopened_identity;
    struct wls_launcher_json_field release_components = {0};
    struct wls_launcher_json_field installed_components = {0};
    char ca_bundle_digest[65];
    char ca_bundle_baseline[65];
    unsigned long long ca_bundle_size = 0ULL;
    unsigned long long ca_bundle_mode = 0ULL;
    unsigned char *release_manifest = NULL;
    unsigned char *release_signature = NULL;
    unsigned char *installed_manifest = NULL;
    size_t release_manifest_length = 0U;
    size_t release_signature_length = 0U;
    size_t installed_manifest_length = 0U;
    int home_fd = -1;
    int slots_fd = -1;
    int slot_fd = -1;
    int release_fd = -1;
    int reopened_slots_fd = -1;
    int reopened_slot_fd = -1;
    int reopened_release_fd = -1;
    int reopened_fd = -1;
    size_t index;
    int result = -1;
    sodium_memzero(ca_bundle_digest, sizeof(ca_bundle_digest));
    sodium_memzero(ca_bundle_baseline, sizeof(ca_bundle_baseline));
    if (home == NULL || home[0] != '/' || home[1] == '\0'
        || (slot != 'A' && slot != 'B') || runtime_generation == NULL) {
        return -1;
    }
    home_fd = open(home, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW);
    if (wls_launcher_exact_directory_fd(
            home_fd, 0751ULL, &home_identity
        ) != 0) {
        goto cleanup;
    }
    slots_fd = openat(
        home_fd, "slots", O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            slots_fd, 0755ULL, &slots_identity
        ) != 0) {
        goto cleanup;
    }
    slot_fd = openat(
        slots_fd, slot_name,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            slot_fd, 0755ULL, &slot_identity
        ) != 0) {
        goto cleanup;
    }
    release_fd = openat(
        slot_fd, "release", O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            release_fd, 0755ULL, &release_identity
        ) != 0
        || wls_launcher_read_regular_at(
            release_fd, "manifest.json", WLS_MAX_MANIFEST,
            &release_manifest, &release_manifest_length,
            &release_manifest_identity
        ) != 0
        || wls_launcher_read_regular_at(
            release_fd, "manifest.sig", WLS_SIGNATURE_TEXT,
            &release_signature, &release_signature_length,
            &release_signature_identity
        ) != 0
        || wls_launcher_read_regular_at(
            slot_fd, "manifest.json", WLS_MAX_MANIFEST,
            &installed_manifest, &installed_manifest_length,
            &installed_manifest_identity
        ) != 0
        || (unsigned long long)(installed_manifest_identity.st_mode & 0777)
            != 0444ULL
        || wls_launcher_verify_release_signature(
            release_manifest,
            release_manifest_length,
            release_signature,
            release_signature_length
        ) != 0
        || wls_launcher_manifest_contract(
            release_manifest,
            release_manifest_length,
            '\0',
            &release_components,
            runtime_generation
        ) != 0
        || wls_launcher_manifest_contract(
            installed_manifest,
            installed_manifest_length,
            slot,
            &installed_components,
            runtime_generation
        ) != 0
        || wls_launcher_installed_leaf_proof(
            &installed_components,
            "release/manifest.json",
            release_manifest,
            release_manifest_length,
            &release_manifest_identity
        ) != 0
        || wls_launcher_installed_leaf_proof(
            &installed_components,
            "release/manifest.sig",
            release_signature,
            release_signature_length,
            &release_signature_identity
        ) != 0) goto cleanup;
    for (index = 0U;
        index < sizeof(required_components) / sizeof(required_components[0]);
        index++) {
        if (wls_launcher_component_proof(
                slot_fd,
                &release_components,
                &installed_components,
                required_components[index]
            ) != 0) goto cleanup;
    }
    if (wls_launcher_component_definition(
            &installed_components,
            "share/ca-bundle.pem",
            ca_bundle_digest,
            &ca_bundle_size,
            &ca_bundle_mode
        ) != 0
        || ca_bundle_size == 0ULL || ca_bundle_size > 4194304ULL
        || ca_bundle_mode != 0444ULL
        || wls_launcher_ca_bundle_baseline(
            home_fd, ca_bundle_baseline
        ) != 0
        || sodium_memcmp(
            ca_bundle_digest, ca_bundle_baseline, 64U
        ) != 0) goto cleanup;
    if (wls_launcher_revalidate_regular_at(
            release_fd,
            "manifest.json",
            WLS_MAX_MANIFEST,
            release_manifest,
            release_manifest_length,
            &release_manifest_identity
        ) != 0
        || wls_launcher_revalidate_regular_at(
            release_fd,
            "manifest.sig",
            WLS_SIGNATURE_TEXT,
            release_signature,
            release_signature_length,
            &release_signature_identity
        ) != 0
        || wls_launcher_revalidate_regular_at(
            slot_fd,
            "manifest.json",
            WLS_MAX_MANIFEST,
            installed_manifest,
            installed_manifest_length,
            &installed_manifest_identity
        ) != 0) goto cleanup;
    /* Reopen every namespace edge after the proof.  The package lock blocks
     * cooperative writers; these checks also turn replacement attempts into
     * a fail-closed reconciliation instead of publishing a stale proof. */
    reopened_slots_fd = openat(
        home_fd, "slots", O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            reopened_slots_fd, 0755ULL, &reopened_identity
        ) != 0
        || !wls_launcher_same_file_state(
            &slots_identity, &reopened_identity
        )) goto cleanup;
    reopened_slot_fd = openat(
        reopened_slots_fd, slot_name,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            reopened_slot_fd, 0755ULL, &reopened_identity
        ) != 0
        || !wls_launcher_same_file_state(&slot_identity, &reopened_identity)) {
        goto cleanup;
    }
    reopened_release_fd = openat(
        reopened_slot_fd, "release",
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            reopened_release_fd, 0755ULL, &reopened_identity
        ) != 0
        || !wls_launcher_same_file_state(
            &release_identity, &reopened_identity
        )) goto cleanup;
    reopened_fd = open(home, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW);
    if (wls_launcher_exact_directory_fd(
            reopened_fd, 0751ULL, &reopened_identity
        ) != 0
        || !wls_launcher_same_file_state(&home_identity, &reopened_identity)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (reopened_fd >= 0) close(reopened_fd);
    if (reopened_release_fd >= 0) close(reopened_release_fd);
    if (reopened_slot_fd >= 0) close(reopened_slot_fd);
    if (reopened_slots_fd >= 0) close(reopened_slots_fd);
    if (release_fd >= 0) close(release_fd);
    if (slot_fd >= 0) close(slot_fd);
    if (slots_fd >= 0) close(slots_fd);
    if (home_fd >= 0) close(home_fd);
    if (release_manifest != NULL) {
        sodium_memzero(release_manifest, release_manifest_length);
        free(release_manifest);
    }
    if (release_signature != NULL) {
        sodium_memzero(release_signature, release_signature_length);
        free(release_signature);
    }
    if (installed_manifest != NULL) {
        sodium_memzero(installed_manifest, installed_manifest_length);
        free(installed_manifest);
    }
    sodium_memzero(ca_bundle_digest, sizeof(ca_bundle_digest));
    sodium_memzero(ca_bundle_baseline, sizeof(ca_bundle_baseline));
    if (result != 0) runtime_generation[0] = '\0';
    return result;
}

static int wls_launcher_require_rollback_target_v2(
    const char *home,
    char slot,
    int *verified
) {
    char runtime_generation[65];
    if (verified == NULL) return -1;
    if (*verified) return 0;
    if (wls_launcher_slot_contract_v2(
            home, slot, runtime_generation
        ) != 0) {
        fprintf(
            stderr,
            "gateway rollback target lacks the exact WLS 2.0 durable-state contract; "
            "explicit host rebootstrap/repair is required\n"
        );
        return -1;
    }
    sodium_memzero(runtime_generation, sizeof(runtime_generation));
    *verified = 1;
    return 0;
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
    long long expected_activation_monotonic = 0;
    long long expected_total_monotonic = 0;
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
    if (strncmp((const char *)intent, "WLS-UPGRADE/2\n", 14U) == 0) {
        fields = sscanf(
            (const char *)intent,
            "WLS-UPGRADE/2\n"
            "host_id=%32[0-9a-f]\n"
            "from=%1[AB]\n"
            "to=%1[AB]\n"
            "prepared_at=%lld\n"
            "deadline=%lld\n"
            "runtime_generation=%64[0-9a-f]\n"
            "host_boot_id=%64[0-9a-f]\n"
            "prepared_monotonic_ms=%lld\n"
            "activation_deadline_monotonic_ms=%lld\n"
            "rollback_deadline_monotonic_ms=%lld\n"
            "nonce=%32[0-9a-f]\n"
            "signature=%64[0-9a-f]\n%n",
            host, from, to,
            &upgrade->prepared_at, &upgrade->deadline,
            upgrade->runtime_generation, upgrade->boot_id,
            &upgrade->prepared_monotonic,
            &upgrade->activation_deadline_monotonic,
            &upgrade->total_deadline_monotonic,
            nonce, signature_hex, &consumed
        );
        upgrade->legacy_protocol = 0;
        if (fields != 12
            || !wls_is_hex_text(upgrade->boot_id, 64U)
            || wls_checked_add_long_long(
                upgrade->prepared_monotonic,
                WLS_UPGRADE_ACTIVATION_MILLISECONDS,
                &expected_activation_monotonic
            ) != 0
            || upgrade->activation_deadline_monotonic
                != expected_activation_monotonic
            || wls_checked_add_long_long(
                upgrade->prepared_monotonic,
                WLS_UPGRADE_TOTAL_MILLISECONDS,
                &expected_total_monotonic
            ) != 0
            || upgrade->total_deadline_monotonic != expected_total_monotonic) {
            goto cleanup;
        }
    } else {
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
            host, from, to,
            &upgrade->prepared_at, &upgrade->deadline,
            upgrade->runtime_generation, nonce, signature_hex, &consumed
        );
        upgrade->legacy_protocol = 1;
        if (fields != 8) goto cleanup;
    }
    signature_line = strstr((char *)intent, "signature=");
    if (consumed != (int)intent_length
        || from[0] == to[0]
        || upgrade->prepared_at < 1
        || wls_checked_add_long_long(
            upgrade->prepared_at,
            WLS_UPGRADE_ACTIVATION_SECONDS,
            &expected_activation_deadline
        ) != 0
        || upgrade->deadline != expected_activation_deadline
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
        && healthy_at <= upgrade->total_deadline_monotonic
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
        && started <= upgrade->activation_deadline_monotonic
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
#if defined(__APPLE__)
    mach_timebase_info_data_t timebase;
    uint64_t ticks = mach_absolute_time();
    uint64_t nanoseconds;
    uint64_t milliseconds;
    /* PHP hrtime() uses mach_absolute_time() on Darwin. Upgrade timestamps
     * cross the PHP/native boundary, so CLOCK_MONOTONIC is not interchangeable:
     * macOS gives the two clocks different origins on supported hosts. */
    if (mach_timebase_info(&timebase) != KERN_SUCCESS
        || timebase.numer == 0U
        || timebase.denom == 0U
        || ticks > UINT64_MAX / (uint64_t)timebase.numer) {
        return -1;
    }
    nanoseconds = ticks * (uint64_t)timebase.numer
        / (uint64_t)timebase.denom;
    milliseconds = nanoseconds / 1000000ULL;
    return milliseconds <= (uint64_t)LLONG_MAX
        ? (long long)milliseconds
        : -1;
#else
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
#endif
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

static int wls_sha256_text(
    const unsigned char *contents,
    size_t length,
    char output[65]
) {
    unsigned char digest[crypto_hash_sha256_BYTES];
    int result = -1;
    if (contents != NULL
        && crypto_hash_sha256(
            digest,
            contents,
            (unsigned long long)length
        ) == 0
        && sodium_bin2hex(output, 65U, digest, sizeof(digest)) != NULL
        && wls_is_hex_text(output, 64U)) {
        result = 0;
    }
    sodium_memzero(digest, sizeof(digest));
    return result;
}

static int wls_all_zero_hex(const char *value)
{
    size_t index;
    if (!wls_is_hex_text(value, 64U)) return 0;
    for (index = 0U; index < 64U; index++) {
        if (value[index] != '0') return 0;
    }
    return 1;
}

static int wls_has_flag(int argc, char **argv, const char *name)
{
    int index;
    for (index = 1; index < argc; index++) {
        if (strcmp(argv[index], name) == 0) return 1;
    }
    return 0;
}

#if defined(__linux__)
static int wls_normalize_hex_32(const char *input, char output[33])
{
    size_t index;
    if (input == NULL || strlen(input) != 32U) return -1;
    for (index = 0U; index < 32U; index++) {
        char value = input[index];
        if (value >= 'A' && value <= 'F') value = (char)(value - 'A' + 'a');
        if (!((value >= '0' && value <= '9')
            || (value >= 'a' && value <= 'f'))) return -1;
        output[index] = value;
    }
    output[32] = '\0';
    return 0;
}
#endif

static int wls_platform_service_identity(
    int argc,
    char **argv,
    const char *home,
    char platform[16],
    char service_id[65],
    char launcher_generation[65]
) {
    char boot_id[65];
    char invocation[33];
    char canonical[512];
    unsigned char random_generation[32];
    int service_mode = wls_has_flag(argc, argv, "--service");
    int guardian_child = wls_has_flag(argc, argv, "--guardian-child");
    int written;
    if (home == NULL || wls_boot_id(boot_id) != 0) return -1;
#if defined(__linux__)
    if ((service_mode || guardian_child)
        && wls_normalize_hex_32(getenv("INVOCATION_ID"), invocation) == 0) {
        memcpy(platform, "systemd", 8U);
    } else {
        memcpy(platform, "standalone", 11U);
    }
#elif defined(__APPLE__)
    (void)invocation;
    if ((service_mode && getppid() == 1) || guardian_child) {
        memcpy(platform, "launchd", 8U);
    } else {
        memcpy(platform, "standalone", 11U);
    }
#else
    (void)argc;
    (void)argv;
    (void)service_mode;
    (void)invocation;
    memcpy(platform, "standalone", 11U);
#endif
    written = snprintf(
        canonical,
        sizeof(canonical),
        "wls-platform-service/1%c%s%ccom.weline.wls-gateway-v2",
        '\0', platform, '\0'
    );
    if (written <= 0 || written >= (int)sizeof(canonical)
        || wls_sha256_text(
            (const unsigned char *)canonical,
            (size_t)written,
            service_id
        ) != 0) {
        sodium_memzero(canonical, sizeof(canonical));
        return -1;
    }
    sodium_memzero(canonical, sizeof(canonical));
#if defined(__linux__)
    if (strcmp(platform, "systemd") == 0) {
        written = snprintf(
            canonical,
            sizeof(canonical),
            "wls-systemd-invocation/1%c%s%c%s%c%s",
            '\0', invocation, '\0', boot_id, '\0', service_id
        );
        if (written <= 0 || written >= (int)sizeof(canonical)
            || wls_sha256_text(
                (const unsigned char *)canonical,
                (size_t)written,
                launcher_generation
            ) != 0) {
            sodium_memzero(canonical, sizeof(canonical));
            return -1;
        }
        sodium_memzero(canonical, sizeof(canonical));
        sodium_memzero(invocation, sizeof(invocation));
        return 0;
    }
#endif
    randombytes_buf(random_generation, sizeof(random_generation));
    if (sodium_bin2hex(
            launcher_generation,
            65U,
            random_generation,
            sizeof(random_generation)
        ) == NULL
        || !wls_is_hex_text(launcher_generation, 64U)) {
        sodium_memzero(random_generation, sizeof(random_generation));
        return -1;
    }
    sodium_memzero(random_generation, sizeof(random_generation));
    return 0;
}

/* 0=read, 1=missing, -1=unsafe/error. */
static int wls_read_root_receipt(
    const char *path,
    char *contents,
    size_t capacity,
    size_t *length
) {
    int fd = -1;
    int result = -1;
    struct stat before;
    struct stat after;
    size_t used = 0U;
    if (path == NULL || contents == NULL || capacity < 2U || length == NULL) {
        return -1;
    }
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0) return errno == ENOENT ? 1 : -1;
    if (fstat(fd, &before) != 0
        || !S_ISREG(before.st_mode)
        || before.st_nlink != 1
        || before.st_uid != 0
        || before.st_gid != 0
        || (before.st_mode & 0777) != 0600
        || before.st_size <= 0
        || (uint64_t)before.st_size >= capacity) goto cleanup;
    while (used < (size_t)before.st_size) {
        ssize_t amount = read(fd, contents + used, (size_t)before.st_size - used);
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) goto cleanup;
        used += (size_t)amount;
    }
    if (fstat(fd, &after) != 0
        || after.st_dev != before.st_dev
        || after.st_ino != before.st_ino
        || after.st_size != before.st_size
        || after.st_mode != before.st_mode
        || after.st_nlink != before.st_nlink
        || after.st_uid != before.st_uid
        || after.st_gid != before.st_gid
#if defined(__APPLE__) || defined(__FreeBSD__)
        || after.st_mtimespec.tv_sec != before.st_mtimespec.tv_sec
        || after.st_mtimespec.tv_nsec != before.st_mtimespec.tv_nsec
        || after.st_ctimespec.tv_sec != before.st_ctimespec.tv_sec
        || after.st_ctimespec.tv_nsec != before.st_ctimespec.tv_nsec
#else
        || after.st_mtim.tv_sec != before.st_mtim.tv_sec
        || after.st_mtim.tv_nsec != before.st_mtim.tv_nsec
        || after.st_ctim.tv_sec != before.st_ctim.tv_sec
        || after.st_ctim.tv_nsec != before.st_ctim.tv_nsec
#endif
        || memchr(contents, '\0', used) != NULL) goto cleanup;
    contents[used] = '\0';
    *length = used;
    result = 0;
cleanup:
    close(fd);
    if (result != 0) {
        sodium_memzero(contents, capacity);
        *length = 0U;
    }
    return result;
}

/* Stable Launcher recovery evidence accepts the dedicated gateway group but
 * never a non-owner writer. The target is observed through one no-follow
 * descriptor before and after the bounded read. */
static int wls_recovery_read_secure(
    const char *path,
    mode_t expected_mode,
    uid_t expected_owner,
    char *contents,
    size_t capacity,
    size_t *length
) {
    int fd = -1;
    int result = -1;
    struct stat before;
    struct stat after;
    size_t used = 0U;
    if (path == NULL || contents == NULL || capacity < 2U || length == NULL) {
        return -1;
    }
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0) return errno == ENOENT ? 1 : -1;
    if (fstat(fd, &before) != 0
        || !S_ISREG(before.st_mode)
        || before.st_nlink != 1
        || before.st_uid != expected_owner
        || (before.st_mode & 0777) != expected_mode
        || before.st_size <= 0
        || (uint64_t)before.st_size >= capacity
        || wls_guardian_acl_free_fd(fd, 0) != 0) goto cleanup;
    while (used < (size_t)before.st_size) {
        ssize_t amount = read(fd, contents + used, (size_t)before.st_size - used);
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) goto cleanup;
        used += (size_t)amount;
    }
    if (fstat(fd, &after) != 0
        || after.st_dev != before.st_dev
        || after.st_ino != before.st_ino
        || after.st_size != before.st_size
        || after.st_mode != before.st_mode
        || after.st_nlink != before.st_nlink
        || after.st_uid != before.st_uid
        || after.st_gid != before.st_gid
#if defined(__APPLE__) || defined(__FreeBSD__)
        || after.st_mtimespec.tv_sec != before.st_mtimespec.tv_sec
        || after.st_mtimespec.tv_nsec != before.st_mtimespec.tv_nsec
        || after.st_ctimespec.tv_sec != before.st_ctimespec.tv_sec
        || after.st_ctimespec.tv_nsec != before.st_ctimespec.tv_nsec
#else
        || after.st_mtim.tv_sec != before.st_mtim.tv_sec
        || after.st_mtim.tv_nsec != before.st_mtim.tv_nsec
        || after.st_ctim.tv_sec != before.st_ctim.tv_sec
        || after.st_ctim.tv_nsec != before.st_ctim.tv_nsec
#endif
        || memchr(contents, '\0', used) != NULL) goto cleanup;
    contents[used] = '\0';
    *length = used;
    result = 0;
cleanup:
    close(fd);
    if (result != 0) {
        sodium_memzero(contents, capacity);
        *length = 0U;
    }
    return result;
}

static int wls_recovery_target_safe(
    const char *path,
    mode_t expected_mode,
    uid_t expected_owner
) {
    struct stat status;
    if (lstat(path, &status) != 0) return errno == ENOENT ? 0 : -1;
    return S_ISREG(status.st_mode)
        && !S_ISLNK(status.st_mode)
        && status.st_nlink == 1
        && status.st_uid == expected_owner
        && (status.st_mode & 0777) == expected_mode
        ? 0 : -1;
}

static int wls_recovery_write_secure(
    const char *path,
    const char *contents,
    mode_t mode,
    uid_t expected_owner
) {
    if (wls_recovery_target_safe(path, mode, expected_owner) != 0
        || wls_atomic_text(path, contents, mode) != 0
        || wls_recovery_target_safe(path, mode, expected_owner) != 0) {
        return -1;
    }
    return 0;
}

static int wls_recovery_wall_seconds(long long *seconds)
{
    time_t now;
    if (seconds == NULL) return -1;
    now = time(NULL);
    if (now <= 0 || (uintmax_t)now > (uintmax_t)LLONG_MAX) return -1;
    *seconds = (long long)now;
    return 0;
}

/* Controller-readable trust leaves are 0440 root:<controller-gid> after the
 * production ACL seal and 0600 under an isolated standalone/test owner. Read
 * them relative to one verified trust-directory descriptor so a linked or
 * writable namespace can never supply the authorization key or host identity.
 * 0=read, 1=missing, -1=unsafe/error. */
static int wls_recovery_read_controller_trust_file(
    const char *home,
    const char *leaf,
    uid_t expected_owner,
    char *contents,
    size_t capacity,
    size_t *length
) {
    char trust_path[PATH_MAX];
    struct stat trust_before;
    struct stat trust_opened;
    struct stat trust_after;
    struct stat before;
    struct stat after;
    size_t used = 0U;
    int trust_fd = -1;
    int file_fd = -1;
    int result = -1;
    if (home == NULL || leaf == NULL || leaf[0] == '\0'
        || strchr(leaf, '/') != NULL || contents == NULL || capacity < 2U
        || length == NULL
        || wls_join(trust_path, sizeof(trust_path), home, "trust") != 0) {
        return -1;
    }
    if (lstat(trust_path, &trust_before) != 0
        || !S_ISDIR(trust_before.st_mode)
        || S_ISLNK(trust_before.st_mode)
        || trust_before.st_uid != expected_owner
        || (trust_before.st_mode & 0022) != 0) goto cleanup;
    trust_fd = open(
        trust_path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (trust_fd < 0 || fstat(trust_fd, &trust_opened) != 0
        || !S_ISDIR(trust_opened.st_mode)
        || trust_opened.st_dev != trust_before.st_dev
        || trust_opened.st_ino != trust_before.st_ino
        || trust_opened.st_mode != trust_before.st_mode
        || trust_opened.st_uid != trust_before.st_uid
        || trust_opened.st_gid != trust_before.st_gid
        || wls_guardian_acl_free_fd(trust_fd, 0) != 0) goto cleanup;
    file_fd = openat(trust_fd, leaf, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (file_fd < 0) {
        result = errno == ENOENT ? 1 : -1;
        goto cleanup;
    }
    if (fstat(file_fd, &before) != 0
        || !S_ISREG(before.st_mode)
        || before.st_nlink != 1
        || before.st_uid != expected_owner
        || !((before.st_mode & 0777) == 0600
            || (expected_owner == 0
                && (before.st_mode & 0777) == 0440
                && before.st_gid == trust_opened.st_gid))
        || before.st_size <= 0
        || (uint64_t)before.st_size >= capacity
        || wls_guardian_acl_free_fd(file_fd, 0) != 0) goto cleanup;
    while (used < (size_t)before.st_size) {
        ssize_t amount = read(
            file_fd, contents + used, (size_t)before.st_size - used
        );
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) goto cleanup;
        used += (size_t)amount;
    }
    if (fstat(file_fd, &after) != 0
        || fstat(trust_fd, &trust_after) != 0
        || after.st_dev != before.st_dev
        || after.st_ino != before.st_ino
        || after.st_size != before.st_size
        || after.st_mode != before.st_mode
        || after.st_nlink != before.st_nlink
        || after.st_uid != before.st_uid
        || after.st_gid != before.st_gid
#if defined(__APPLE__) || defined(__FreeBSD__)
        || after.st_mtimespec.tv_sec != before.st_mtimespec.tv_sec
        || after.st_mtimespec.tv_nsec != before.st_mtimespec.tv_nsec
        || after.st_ctimespec.tv_sec != before.st_ctimespec.tv_sec
        || after.st_ctimespec.tv_nsec != before.st_ctimespec.tv_nsec
#else
        || after.st_mtim.tv_sec != before.st_mtim.tv_sec
        || after.st_mtim.tv_nsec != before.st_mtim.tv_nsec
        || after.st_ctim.tv_sec != before.st_ctim.tv_sec
        || after.st_ctim.tv_nsec != before.st_ctim.tv_nsec
#endif
        || trust_after.st_dev != trust_opened.st_dev
        || trust_after.st_ino != trust_opened.st_ino
        || trust_after.st_mode != trust_opened.st_mode
        || trust_after.st_uid != trust_opened.st_uid
        || trust_after.st_gid != trust_opened.st_gid
        || memchr(contents, '\0', used) != NULL) goto cleanup;
    contents[used] = '\0';
    *length = used;
    result = 0;
cleanup:
    if (file_fd >= 0) close(file_fd);
    if (trust_fd >= 0) close(trust_fd);
    if (result != 0) {
        sodium_memzero(contents, capacity);
        *length = 0U;
    }
    return result;
}

static int wls_recovery_launcher_identity(
    const char *home,
    uid_t expected_owner,
    char identity[65]
);

static int wls_rebootstrap_ascii_prefix_equal(
    const char *value,
    size_t value_length,
    const char *prefix
) {
    size_t index;
    size_t prefix_length;
    if (value == NULL || prefix == NULL) return 0;
    prefix_length = strlen(prefix);
    if (value_length < prefix_length) return 0;
    for (index = 0U; index < prefix_length; index++) {
        unsigned char left = (unsigned char)value[index];
        unsigned char right = (unsigned char)prefix[index];
        if (left >= (unsigned char)'A' && left <= (unsigned char)'Z') {
            left = (unsigned char)(left + ((unsigned char)'a' - (unsigned char)'A'));
        }
        if (right >= (unsigned char)'A' && right <= (unsigned char)'Z') {
            right = (unsigned char)(right + ((unsigned char)'a' - (unsigned char)'A'));
        }
        if (left != right) return 0;
    }
    return 1;
}

/* A case alias of the journal or any well-formed/malformed spelling rooted
 * in its reserved atomic-write prefixes is recovery evidence. The launcher
 * must not interpret, select or delete that evidence. */
static int wls_rebootstrap_reserved_recovery_name(const char *leaf)
{
    static const char target[] = "rebootstrap.transaction";
    static const char backup_prefix[] = "wls-backup";
    static const char staging_prefix[] = "tmp";
    size_t length;
    size_t target_length = sizeof(target) - 1U;
    const char *suffix;
    size_t suffix_length;
    if (leaf == NULL) return 1;
    length = strlen(leaf);
    if (!wls_rebootstrap_ascii_prefix_equal(leaf, length, target)) return 0;
    if (length == target_length) {
        /* The exact target may have appeared after the caller's first
         * ENOENT read. Seeing it in the directory is therefore recovery
         * evidence too, never an entry to ignore. */
        return 1;
    }
    if (leaf[target_length] != '.') return 0;
    suffix = leaf + target_length + 1U;
    suffix_length = length - target_length - 1U;
    return wls_rebootstrap_ascii_prefix_equal(
            suffix, suffix_length, backup_prefix
        )
        || wls_rebootstrap_ascii_prefix_equal(
            suffix, suffix_length, staging_prefix
        );
}

static int wls_rebootstrap_reserved_recovery_name_self_test(void)
{
    return wls_rebootstrap_reserved_recovery_name(
            "rebootstrap.transaction"
        ) == 1
        && wls_rebootstrap_reserved_recovery_name(
            "REBOOTSTRAP.TRANSACTION"
        ) == 1
        && wls_rebootstrap_reserved_recovery_name(
            "rebootstrap.transaction.wls-backup-0123456789abcdef"
        ) == 1
        && wls_rebootstrap_reserved_recovery_name(
            "rebootstrap.transaction.tmp-0123456789abcdef01234567"
        ) == 1
        && wls_rebootstrap_reserved_recovery_name(
            "unrelated.transaction"
        ) == 0
        ? 0
        : 1;
}

/* 0=the exact target and every reserved recovery spelling are absent;
 * -1=present, unsafe, raced or outside the fixed immediate-entry budget. */
static int wls_rebootstrap_recovery_artifacts_absent(
    const char *home,
    uid_t expected_owner
) {
    char trust_path[PATH_MAX];
    struct stat path_before;
    struct stat opened_before;
    struct stat opened_after;
    struct stat path_after;
    DIR *directory = NULL;
    struct dirent *entry;
    size_t visited = 0U;
    int trust_fd = -1;
    int result = -1;
    if (home == NULL
        || wls_join(trust_path, sizeof(trust_path), home, "trust") != 0
        || lstat(trust_path, &path_before) != 0
        || !S_ISDIR(path_before.st_mode)
        || S_ISLNK(path_before.st_mode)
        || path_before.st_uid != expected_owner
        || (path_before.st_mode & 0022) != 0) return -1;
    trust_fd = open(
        trust_path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (trust_fd < 0 || fstat(trust_fd, &opened_before) != 0
        || !wls_launcher_same_file_state(&path_before, &opened_before)) {
        goto cleanup;
    }
    directory = fdopendir(trust_fd);
    if (directory == NULL) goto cleanup;
    trust_fd = -1;
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (++visited > WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES
            || wls_rebootstrap_reserved_recovery_name(entry->d_name)) {
            goto cleanup;
        }
    }
    if (errno != 0
        || fstat(dirfd(directory), &opened_after) != 0
        || lstat(trust_path, &path_after) != 0
        || !wls_launcher_same_file_state(&opened_before, &opened_after)
        || !wls_launcher_same_file_state(&opened_after, &path_after)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    if (trust_fd >= 0) close(trust_fd);
    return result;
}

/* A rebootstrap journal is a fail-closed maintenance fence unless an exact
 * administrator-HMAC authorization binds its current/next durable digest to
 * this launcher slot, runtime generation and stable-launcher identity.
 * START_AUTHORIZED and ROLLBACK_START_AUTHORIZED are therefore admitted only
 * through the signed forward/rollback marker, never by phase text alone. */
static int wls_recovery_maintenance_pending(
    const char *home,
    char active_slot,
    const char *runtime_generation
) {
    char journal_path[PATH_MAX];
    char authorization_path[PATH_MAX];
    char host_path[PATH_MAX];
    char authorization[WLS_REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES + 1U];
    char token[66];
    char host_text[34];
    char stable_launcher[65];
    char marker_host[33];
    char nonce[33];
    char purpose[9];
    char primary[65];
    char secondary[65];
    char marker_slot[2];
    char marker_generation[65];
    char marker_launcher[65];
    char signature_hex[65];
    char journal_digest[65];
    char authorization_digest[65];
    char current_journal_digest[65];
    char current_authorization_digest[65];
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char expected[crypto_auth_hmacsha256_BYTES];
    unsigned char actual[crypto_auth_hmacsha256_BYTES];
    unsigned char *journal = NULL;
    char *signature_line;
    size_t journal_length = 0U;
    size_t authorization_length = 0U;
    size_t token_length = 0U;
    size_t host_length = 0U;
    size_t decoded = 0U;
    uid_t expected_owner = geteuid();
    int consumed = 0;
    int fields;
    int read_status;
    int result = 1;
    sodium_memzero(key, sizeof(key));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
    if (home == NULL || runtime_generation == NULL
        || (active_slot != 'A' && active_slot != 'B')
        || !wls_is_hex_text(runtime_generation, 64U)
        || wls_join(
            journal_path, sizeof(journal_path), home,
            "trust/rebootstrap.transaction"
        ) != 0
        || wls_join(
            authorization_path, sizeof(authorization_path), home,
            "trust/rebootstrap-start.authorization"
        ) != 0
        || wls_join(
            host_path, sizeof(host_path), home, "trust/host-id"
        ) != 0) goto cleanup;
    journal = malloc(WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES + 1U);
    if (journal == NULL) goto cleanup;
    read_status = wls_recovery_read_secure(
        journal_path,
        0600,
        expected_owner,
        (char *)journal,
        WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES + 1U,
        &journal_length
    );
    if (read_status == 1) {
        /* A stale marker has no authority only after the target and every
         * atomic-write recovery spelling are proven absent. */
        result = wls_rebootstrap_recovery_artifacts_absent(
            home, expected_owner
        ) == 0 ? 0 : 1;
        goto cleanup;
    }
    if (read_status != 0
        || wls_recovery_read_secure(
            authorization_path,
            0600,
            expected_owner,
            authorization,
            sizeof(authorization),
            &authorization_length
        ) != 0
        || wls_recovery_read_controller_trust_file(
            home,
            "admin.token",
            expected_owner,
            token,
            sizeof(token),
            &token_length
        ) != 0
        || wls_recovery_read_controller_trust_file(
            home,
            "host-id",
            expected_owner,
            host_text,
            sizeof(host_text),
            &host_length
        ) != 0
        || wls_recovery_launcher_identity(
            home, expected_owner, stable_launcher
        ) != 0) goto cleanup;
    if (!((token_length == 64U)
            || (token_length == 65U && token[64] == '\n'))
        || !((host_length == 32U)
            || (host_length == 33U && host_text[32] == '\n'))) goto cleanup;
    token[64] = '\0';
    host_text[32] = '\0';
    if (!wls_is_hex_text(token, 64U)
        || !wls_is_hex_text(host_text, 32U)
        || wls_sha256_text(
            journal, journal_length, journal_digest
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)authorization,
            authorization_length,
            authorization_digest
        ) != 0) goto cleanup;
    fields = sscanf(
        authorization,
        "WLS-REBOOTSTRAP-START/1\n"
        "host_id=%32[0-9a-f]\n"
        "nonce=%32[0-9a-f]\n"
        "purpose=%8[a-z]\n"
        "journal_sha256_primary=%64[0-9a-f]\n"
        "journal_sha256_secondary=%64[0-9a-f]\n"
        "active_slot=%1[AB]\n"
        "runtime_generation=%64[0-9a-f]\n"
        "stable_launcher_sha256=%64[0-9a-f]\n"
        "signature=%64[0-9a-f]\n%n",
        marker_host,
        nonce,
        purpose,
        primary,
        secondary,
        marker_slot,
        marker_generation,
        marker_launcher,
        signature_hex,
        &consumed
    );
    signature_line = strstr(authorization, "signature=");
    if (fields != 9 || consumed != (int)authorization_length
        || signature_line == NULL
        || !wls_is_hex_text(marker_host, 32U)
        || !wls_is_hex_text(nonce, 32U)
        || (strcmp(purpose, "forward") != 0
            && strcmp(purpose, "rollback") != 0)
        || !wls_is_hex_text(primary, 64U)
        || !wls_is_hex_text(secondary, 64U)
        || !wls_is_hex_text(marker_generation, 64U)
        || !wls_is_hex_text(marker_launcher, 64U)
        || !wls_is_hex_text(signature_hex, 64U)
        || strcmp(marker_host, host_text) != 0
        || marker_slot[0] != active_slot || marker_slot[1] != '\0'
        || strcmp(marker_generation, runtime_generation) != 0
        || strcmp(marker_launcher, stable_launcher) != 0
        || (sodium_memcmp(journal_digest, primary, 64U) != 0
            && sodium_memcmp(journal_digest, secondary, 64U) != 0)
        || sodium_hex2bin(
            key, sizeof(key), token, 64U, NULL, &decoded, NULL
        ) != 0 || decoded != sizeof(key)
        || sodium_hex2bin(
            actual,
            sizeof(actual),
            signature_hex,
            64U,
            NULL,
            &decoded,
            NULL
        ) != 0 || decoded != sizeof(actual)
        || crypto_auth_hmacsha256(
            expected,
            (const unsigned char *)authorization,
            (unsigned long long)(signature_line - authorization),
            key
        ) != 0
        || sodium_memcmp(expected, actual, sizeof(expected)) != 0) {
        goto cleanup;
    }
    /* Re-read both independently replaced files after authentication. This
     * rejects a revocation or phase transition that raced the first snapshot
     * instead of authorizing an already superseded journal/marker pair. */
    journal_length = 0U;
    authorization_length = 0U;
    if (wls_recovery_read_secure(
            journal_path,
            0600,
            expected_owner,
            (char *)journal,
            WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES + 1U,
            &journal_length
        ) != 0
        || wls_sha256_text(
            journal, journal_length, current_journal_digest
        ) != 0
        || sodium_memcmp(
            journal_digest, current_journal_digest, 64U
        ) != 0
        || wls_recovery_read_secure(
            authorization_path,
            0600,
            expected_owner,
            authorization,
            sizeof(authorization),
            &authorization_length
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)authorization,
            authorization_length,
            current_authorization_digest
        ) != 0
        || sodium_memcmp(
            authorization_digest, current_authorization_digest, 64U
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (journal != NULL) {
        sodium_memzero(
            journal, WLS_REBOOTSTRAP_JOURNAL_MAX_BYTES + 1U
        );
        free(journal);
    }
    sodium_memzero(authorization, sizeof(authorization));
    sodium_memzero(token, sizeof(token));
    sodium_memzero(host_text, sizeof(host_text));
    sodium_memzero(stable_launcher, sizeof(stable_launcher));
    sodium_memzero(key, sizeof(key));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
    return result;
}

static int wls_recovery_launcher_identity(
    const char *home,
    uid_t expected_owner,
    char identity[65]
) {
    char path[PATH_MAX];
    char contents[66];
    size_t length = 0U;
    if (wls_join(
            path, sizeof(path), home, "trust/stable-launcher.sha256"
        ) != 0
        || wls_recovery_read_secure(
            path, 0600, expected_owner, contents, sizeof(contents), &length
        ) != 0
        || !((length == 64U) || (length == 65U && contents[64] == '\n'))) {
        sodium_memzero(contents, sizeof(contents));
        return -1;
    }
    contents[64] = '\0';
    if (!wls_recovery_hex(contents, 64U)) {
        sodium_memzero(contents, sizeof(contents));
        return -1;
    }
    memcpy(identity, contents, 65U);
    sodium_memzero(contents, sizeof(contents));
    return 0;
}

static int wls_recovery_launcher_generation(
    const char *service_id,
    const char *launcher_identity,
    char generation[65]
) {
    char canonical[256];
    int length;
    if (service_id == NULL || launcher_identity == NULL) return -1;
    length = snprintf(
        canonical,
        sizeof(canonical),
        "wls-launcher-recovery-generation/1%c%s%c%s",
        '\0', service_id, '\0', launcher_identity
    );
    if (length <= 0 || length >= (int)sizeof(canonical)
        || wls_sha256_text(
            (const unsigned char *)canonical, (size_t)length, generation
        ) != 0) {
        sodium_memzero(canonical, sizeof(canonical));
        return -1;
    }
    sodium_memzero(canonical, sizeof(canonical));
    return 0;
}

static int wls_recovery_publish(
    struct wls_posix_recovery_context *context,
    const char *stage_override,
    const char *reason_override
) {
    char ledger[WLS_RECOVERY_LEDGER_CAPACITY];
    char status[WLS_RECOVERY_STATUS_CAPACITY];
    int ledger_length;
    int status_length;
    if (context == NULL) return -1;
    ledger_length = wls_recovery_format(
        &context->state, ledger, sizeof(ledger)
    );
    status_length = wls_recovery_status_format(
        &context->state,
        stage_override,
        reason_override,
        status,
        sizeof(status)
    );
    if (ledger_length <= 0 || status_length <= 0
        || wls_recovery_write_secure(
            context->ledger_path, ledger, 0600, context->owner_uid
        ) != 0
        || wls_recovery_write_secure(
            context->status_path, status, 0444, context->owner_uid
        ) != 0) {
        sodium_memzero(ledger, sizeof(ledger));
        sodium_memzero(status, sizeof(status));
        return -1;
    }
    sodium_memzero(ledger, sizeof(ledger));
    sodium_memzero(status, sizeof(status));
    return 0;
}

/* 0=attempt marker committed, 1=controlled stop, 2=reload, -1=unsafe. */
static int wls_recovery_prepare_attempt(
    const char *home,
    const char *run_directory,
    const char *platform,
    const char *service_id,
    char active_slot,
    const char *runtime_generation,
    struct wls_posix_recovery_context *context
) {
    char launcher_identity[65];
    char launcher_generation[65];
    char boot_id[65];
    char encoded[WLS_RECOVERY_LEDGER_CAPACITY];
    char attempt_id[33];
    unsigned char attempt_bytes[16];
    size_t encoded_length = 0U;
    long long now_wall;
    long long now_monotonic_signed;
    unsigned long long now_monotonic;
    int read_status;
    int signal_number;
    if (home == NULL || run_directory == NULL || platform == NULL
        || service_id == NULL || runtime_generation == NULL || context == NULL
        || (active_slot != 'A' && active_slot != 'B')) return -1;
    memset(context, 0, sizeof(*context));
    context->owner_uid = geteuid();
    if (strcmp(platform, "standalone") != 0 && context->owner_uid != 0) {
        fprintf(stderr, "platform Launcher recovery ledger requires root ownership\n");
        return -1;
    }
    if (wls_join(
            context->ledger_path, sizeof(context->ledger_path),
            home, "trust/launcher-recovery.ledger"
        ) != 0
        || wls_join(
            context->status_path, sizeof(context->status_path),
            run_directory, "launcher-recovery.status"
        ) != 0
        || wls_recovery_launcher_identity(
            home, context->owner_uid, launcher_identity
        ) != 0
        || wls_recovery_launcher_generation(
            service_id, launcher_identity, launcher_generation
        ) != 0
        || wls_boot_id(boot_id) != 0
        || (now_monotonic_signed = wls_monotonic_milliseconds()) <= 0
        || wls_recovery_wall_seconds(&now_wall) != 0) goto failed;
    now_monotonic = (unsigned long long)now_monotonic_signed;
    read_status = wls_recovery_read_secure(
        context->ledger_path,
        0600,
        context->owner_uid,
        encoded,
        sizeof(encoded),
        &encoded_length
    );
    if (read_status < 0) goto failed;
    if (read_status == 0
        && wls_recovery_parse(
            encoded, encoded_length, &context->state
        ) != 0) {
        wls_recovery_initialize_invalid(
            &context->state,
            boot_id,
            launcher_generation,
            launcher_identity,
            runtime_generation,
            active_slot,
            now_monotonic,
            now_wall
        );
    } else {
        (void)wls_recovery_reconcile(
            &context->state,
            read_status == 0,
            boot_id,
            launcher_generation,
            launcher_identity,
            runtime_generation,
            active_slot,
            now_monotonic,
            now_wall
        );
    }
    if (wls_recovery_publish(context, NULL, NULL) != 0) goto failed;
    for (;;) {
        struct timespec pause = {0, 200000000L};
        if (wls_admin_stopped(home) != 0) {
            (void)wls_recovery_publish(
                context, "ADMIN_STOPPED", "CONTROLLED_STOP"
            );
            sodium_memzero(encoded, sizeof(encoded));
            return 1;
        }
        if (wls_recovery_maintenance_pending(
                home, active_slot, runtime_generation
            )) {
            (void)wls_recovery_publish(
                context, "REBOOTSTRAP_PENDING", "CONTROLLED_STOP"
            );
            sodium_memzero(encoded, sizeof(encoded));
            return 1;
        }
        signal_number = wls_take_shutdown_signal();
        if (signal_number == SIGTERM || signal_number == SIGINT) {
            sodium_memzero(encoded, sizeof(encoded));
            return 1;
        }
        if (signal_number == SIGHUP) {
            sodium_memzero(encoded, sizeof(encoded));
            return 2;
        }
        now_monotonic_signed = wls_monotonic_milliseconds();
        if (now_monotonic_signed <= 0
            || wls_recovery_wall_seconds(&now_wall) != 0) goto failed;
        now_monotonic = (unsigned long long)now_monotonic_signed;
        if (context->state.next_retry_monotonic_ms == 0ULL
            || now_monotonic >= context->state.next_retry_monotonic_ms) break;
        while (nanosleep(&pause, &pause) != 0 && errno == EINTR) {
            if (wls_shutdown_signal != 0) break;
        }
    }
    randombytes_buf(attempt_bytes, sizeof(attempt_bytes));
    if (sodium_bin2hex(
            attempt_id, sizeof(attempt_id),
            attempt_bytes, sizeof(attempt_bytes)
        ) == NULL
        || !wls_recovery_hex(attempt_id, 32U)) goto failed;
    wls_recovery_mark_attempt(
        &context->state, attempt_id, now_monotonic, now_wall
    );
    if (wls_recovery_publish(context, NULL, NULL) != 0) goto failed;
    sodium_memzero(attempt_bytes, sizeof(attempt_bytes));
    sodium_memzero(attempt_id, sizeof(attempt_id));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(launcher_identity, sizeof(launcher_identity));
    sodium_memzero(launcher_generation, sizeof(launcher_generation));
    sodium_memzero(boot_id, sizeof(boot_id));
    return 0;
failed:
    sodium_memzero(attempt_bytes, sizeof(attempt_bytes));
    sodium_memzero(attempt_id, sizeof(attempt_id));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(launcher_identity, sizeof(launcher_identity));
    sodium_memzero(launcher_generation, sizeof(launcher_generation));
    sodium_memzero(boot_id, sizeof(boot_id));
    return -1;
}

static int wls_recovery_finish_attempt(
    struct wls_posix_recovery_context *context,
    int controlled,
    const char *failure_reason
) {
    long long now_wall;
    long long monotonic = wls_monotonic_milliseconds();
    if (context == NULL || monotonic <= 0
        || wls_recovery_wall_seconds(&now_wall) != 0) return -1;
    if (controlled) {
        wls_recovery_mark_controlled(
            &context->state, (unsigned long long)monotonic, now_wall
        );
    } else {
        wls_recovery_record_failure(
            &context->state,
            (unsigned long long)monotonic,
            now_wall,
            failure_reason
        );
    }
    return wls_recovery_publish(context, NULL, NULL);
}

static int wls_parse_process_attestation(
    const char *contents,
    size_t length,
    struct wls_process_attestation_receipt *receipt
) {
    int consumed = 0;
    if (contents == NULL || receipt == NULL) return -1;
    memset(receipt, 0, sizeof(*receipt));
    if (sscanf(
            contents,
            "WLS-PROCESS-ATTEST/3\npid=%lu\nstart_id=%llu\n"
            "binary_digest=%64[0-9a-f]\nruntime_generation=%64[0-9a-f]\n"
            "config_digest=%64[0-9a-f]\nconfig_path_digest=%64[0-9a-f]\n"
            "publication_generation=%lu\nfence_kind=%9[A-Z]\n"
            "candidate_transaction_id=%32[0-9a-f-]\n"
            "candidate_phase=%39[A-Z_]\n"
            "candidate_fence_digest=%64[0-9a-f]\n%n",
            &receipt->pid,
            &receipt->start_id,
            receipt->binary_digest,
            receipt->runtime_generation,
            receipt->config_digest,
            receipt->config_path_digest,
            &receipt->publication_generation,
            receipt->fence_kind,
            receipt->candidate_transaction_id,
            receipt->candidate_phase,
            receipt->candidate_fence_digest,
            &consumed
        ) != 11
        || consumed != (int)length
        || receipt->pid == 0U
        || receipt->start_id == 0ULL
        || receipt->publication_generation == 0U
        || !wls_is_hex_text(receipt->binary_digest, 64U)
        || !wls_is_hex_text(receipt->runtime_generation, 64U)
        || !wls_is_hex_text(receipt->config_digest, 64U)
        || !wls_is_hex_text(receipt->config_path_digest, 64U)
        || (strcmp(receipt->fence_kind, "ACTIVE") == 0
            ? (strcmp(receipt->candidate_transaction_id, "-") != 0
                || strcmp(receipt->candidate_phase, "ACTIVE") != 0
                || !wls_all_zero_hex(receipt->candidate_fence_digest))
            : (strcmp(receipt->fence_kind, "CANDIDATE") != 0
                || !wls_is_hex_text(receipt->candidate_transaction_id, 32U)
                || (strcmp(receipt->candidate_phase, "ACTIVATING") != 0
                    && strcmp(
                        receipt->candidate_phase,
                        "SERVICE_TREE_RETIREMENT_PENDING"
                    ) != 0)
                || !wls_is_hex_text(receipt->candidate_fence_digest, 64U)
                || wls_all_zero_hex(receipt->candidate_fence_digest)))) {
        sodium_memzero(receipt, sizeof(*receipt));
        return -1;
    }
    return 0;
}

/* Resolve the live process birth identity and executable without trusting the
 * receipt's PID as ownership. The caller never signals this process. */
static int wls_recovery_process_identity(
    pid_t pid,
    char *executable,
    size_t executable_capacity,
    unsigned long long *start_id
) {
#if defined(__linux__)
    char path[64];
    char contents[4096];
    char *cursor;
    char *end;
    int fd;
    ssize_t amount;
    unsigned int field = 3U;
    ssize_t executable_length;
    if (pid <= 0 || executable == NULL || executable_capacity < 2U
        || start_id == NULL
        || snprintf(path, sizeof(path), "/proc/%ld/stat", (long)pid)
            >= (int)sizeof(path)) return -1;
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0) return -1;
    do {
        amount = read(fd, contents, sizeof(contents) - 1U);
    } while (amount < 0 && errno == EINTR);
    close(fd);
    if (amount <= 0 || amount >= (ssize_t)sizeof(contents)) return -1;
    contents[amount] = '\0';
    cursor = strrchr(contents, ')');
    if (cursor == NULL || cursor[1] != ' ') return -1;
    cursor += 2U;
    while (field <= 22U) {
        char *space = strchr(cursor, ' ');
        if (field == 22U) {
            errno = 0;
            *start_id = strtoull(cursor, &end, 10);
            if (errno != 0 || end == cursor
                || (*end != ' ' && *end != '\0')
                || *start_id == 0ULL) return -1;
            if (snprintf(path, sizeof(path), "/proc/%ld/exe", (long)pid)
                >= (int)sizeof(path)) return -1;
            executable_length = readlink(
                path, executable, executable_capacity - 1U
            );
            if (executable_length <= 0
                || (size_t)executable_length >= executable_capacity) return -1;
            executable[executable_length] = '\0';
            return 0;
        }
        if (space == NULL) return -1;
        cursor = space + 1U;
        field++;
    }
    return -1;
#elif defined(__APPLE__)
    struct proc_bsdinfo information;
    int path_length;
    int amount;
    if (pid <= 0 || executable == NULL || executable_capacity < 2U
        || executable_capacity > (size_t)INT_MAX || start_id == NULL) return -1;
    memset(&information, 0, sizeof(information));
    amount = proc_pidinfo(
        pid, PROC_PIDTBSDINFO, 0, &information, (int)sizeof(information)
    );
    path_length = proc_pidpath(pid, executable, (uint32_t)executable_capacity);
    if (amount != (int)sizeof(information) || path_length <= 0
        || (size_t)path_length >= executable_capacity
        || information.pbi_start_tvsec == 0ULL
        || information.pbi_start_tvsec
            > (ULLONG_MAX - information.pbi_start_tvusec) / 1000000ULL) {
        return -1;
    }
    executable[path_length] = '\0';
    *start_id = information.pbi_start_tvsec * 1000000ULL
        + information.pbi_start_tvusec;
    return *start_id > 0ULL ? 0 : -1;
#else
    (void)pid;
    (void)executable;
    (void)executable_capacity;
    (void)start_id;
    errno = ENOTSUP;
    return -1;
#endif
}

static int wls_guardian_read_digest(
    const char *home,
    const char *relative,
    char digest[65]
) {
    char path[PATH_MAX];
    char contents[66];
    size_t length = 0U;
    if (home == NULL || relative == NULL || digest == NULL
        || wls_join(path, sizeof(path), home, relative) != 0
        || wls_recovery_read_secure(
            path, 0600, geteuid(), contents, sizeof(contents), &length
        ) != 0
        || !((length == 64U) || (length == 65U && contents[64] == '\n'))) {
        sodium_memzero(contents, sizeof(contents));
        return -1;
    }
    contents[64] = '\0';
    if (!wls_is_hex_text(contents, 64U)) {
        sodium_memzero(contents, sizeof(contents));
        return -1;
    }
    memcpy(digest, contents, 65U);
    sodium_memzero(contents, sizeof(contents));
    return 0;
}

static int wls_guardian_verify_executable(
    const char *path,
    const char *expected_digest
) {
    struct stat before;
    struct stat after;
    char actual[65];
    int result = -1;
    if (path == NULL || expected_digest == NULL
        || !wls_is_hex_text(expected_digest, 64U)
        || lstat(path, &before) != 0
        || !S_ISREG(before.st_mode)
        || S_ISLNK(before.st_mode)
        || before.st_nlink != 1
        || before.st_uid != geteuid()
        || (before.st_mode & 0022) != 0
        || (before.st_mode & 0111) == 0
        || wls_file_digest(path, actual) != 0
        || lstat(path, &after) != 0
        || before.st_dev != after.st_dev
        || before.st_ino != after.st_ino
        || before.st_size != after.st_size
        || before.st_mode != after.st_mode
        || before.st_uid != after.st_uid
        || before.st_gid != after.st_gid
        || before.st_nlink != after.st_nlink
        || sodium_memcmp(actual, expected_digest, 64U) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(actual, sizeof(actual));
    return result;
}

static int wls_guardian_paths(
    const char *home,
    char guardian[PATH_MAX],
    char launcher[PATH_MAX],
    char guardian_digest[65],
    char launcher_digest[65]
) {
    return home != NULL
        && wls_join(
            guardian, PATH_MAX, home,
            "guardian/v1/wls-gateway-guardian"
        ) == 0
        && wls_join(
            launcher, PATH_MAX, home, "bin/wls-gateway-launcher"
        ) == 0
        && wls_guardian_read_digest(
            home, "trust/guardian.sha256", guardian_digest
        ) == 0
        && wls_guardian_read_digest(
            home, "trust/stable-launcher.sha256", launcher_digest
        ) == 0
        && wls_guardian_verify_executable(
            guardian, guardian_digest
        ) == 0
        && wls_guardian_verify_executable(
            launcher, launcher_digest
        ) == 0 ? 0 : -1;
}

static int wls_guardian_identity(
    const char *home,
    char guardian[PATH_MAX],
    char guardian_digest[65]
) {
    return home != NULL
        && guardian != NULL
        && guardian_digest != NULL
        && wls_join(
            guardian,
            PATH_MAX,
            home,
            "guardian/v1/wls-gateway-guardian"
        ) == 0
        && wls_guardian_read_digest(
            home, "trust/guardian.sha256", guardian_digest
        ) == 0
        && wls_guardian_verify_executable(
            guardian, guardian_digest
        ) == 0 ? 0 : -1;
}

static int wls_guardian_parse_unsigned(
    const char *value,
    unsigned long long maximum,
    unsigned long long *parsed
) {
    char *end = NULL;
    unsigned long long result;
    if (value == NULL || value[0] == '\0' || parsed == NULL
        || value[0] == '0'
        || strspn(value, "0123456789") != strlen(value)) return -1;
    errno = 0;
    result = strtoull(value, &end, 10);
    if (errno != 0 || end == value || *end != '\0'
        || result == 0ULL || result > maximum) return -1;
    *parsed = result;
    return 0;
}

static int wls_guardian_parse_zero_or_unsigned(
    const char *value,
    unsigned long long maximum,
    unsigned long long *parsed
) {
    if (value != NULL && strcmp(value, "0") == 0 && parsed != NULL) {
        *parsed = 0ULL;
        return 0;
    }
    return wls_guardian_parse_unsigned(value, maximum, parsed);
}

static int wls_guardian_bind_child(
    int argc,
    char **argv,
    const char *home
) {
    const char *pid_text = wls_argument(
        argc, argv, "--guardian-parent-pid"
    );
    const char *start_text = wls_argument(
        argc, argv, "--guardian-parent-start-id"
    );
    char guardian[PATH_MAX];
    char launcher[PATH_MAX];
    char guardian_digest[65];
    char launcher_digest[65];
    char parent_executable[PATH_MAX];
    char child_executable[PATH_MAX];
    char parent_digest[65];
    char child_digest[65];
    unsigned long long pid_value = 0ULL;
    unsigned long long expected_start = 0ULL;
    unsigned long long parent_start = 0ULL;
    unsigned long long child_start = 0ULL;
    int result = -1;
    if (wls_guardian_parse_unsigned(
            pid_text, (unsigned long long)INT_MAX, &pid_value
        ) != 0
        || wls_guardian_parse_unsigned(
            start_text, ULLONG_MAX, &expected_start
        ) != 0
        || (pid_t)pid_value != getppid()
        || wls_guardian_paths(
            home,
            guardian,
            launcher,
            guardian_digest,
            launcher_digest
        ) != 0
        || wls_recovery_process_identity(
            (pid_t)pid_value,
            parent_executable,
            sizeof(parent_executable),
            &parent_start
        ) != 0
        || parent_start != expected_start
        || strcmp(parent_executable, guardian) != 0
        || wls_file_digest(parent_executable, parent_digest) != 0
        || sodium_memcmp(parent_digest, guardian_digest, 64U) != 0
        || wls_recovery_process_identity(
            getpid(),
            child_executable,
            sizeof(child_executable),
            &child_start
        ) != 0
        || strcmp(child_executable, launcher) != 0
        || wls_file_digest(child_executable, child_digest) != 0
        || sodium_memcmp(child_digest, launcher_digest, 64U) != 0) {
        goto cleanup;
    }
#if defined(__linux__)
    if (prctl(PR_SET_PDEATHSIG, SIGTERM, 0, 0, 0) != 0
        || getppid() != (pid_t)pid_value) goto cleanup;
#endif
    wls_guardian_parent_pid = (pid_t)pid_value;
    wls_guardian_parent_start_id = expected_start;
    wls_guardian_parent_last_check_ms = 0LL;
    result = 0;
cleanup:
    sodium_memzero(guardian, sizeof(guardian));
    sodium_memzero(launcher, sizeof(launcher));
    sodium_memzero(guardian_digest, sizeof(guardian_digest));
    sodium_memzero(launcher_digest, sizeof(launcher_digest));
    sodium_memzero(parent_executable, sizeof(parent_executable));
    sodium_memzero(child_executable, sizeof(child_executable));
    sodium_memzero(parent_digest, sizeof(parent_digest));
    sodium_memzero(child_digest, sizeof(child_digest));
    return result;
}

static int wls_guardian_parent_alive(void)
{
    char executable[PATH_MAX];
    unsigned long long start_id = 0ULL;
    long long now;
    if (wls_guardian_parent_pid <= 0
        || wls_guardian_parent_start_id == 0ULL) return 1;
    now = wls_monotonic_milliseconds();
    if (now > 0 && wls_guardian_parent_last_check_ms > 0
        && now - wls_guardian_parent_last_check_ms < 1000LL) return 1;
    if (kill(wls_guardian_parent_pid, 0) != 0
        || wls_recovery_process_identity(
            wls_guardian_parent_pid,
            executable,
            sizeof(executable),
            &start_id
        ) != 0
        || start_id != wls_guardian_parent_start_id) {
        sodium_memzero(executable, sizeof(executable));
        return 0;
    }
    wls_guardian_parent_last_check_ms = now;
    sodium_memzero(executable, sizeof(executable));
    return 1;
}

static int wls_guardian_admin_key(
    const char *home,
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES]
) {
    char token[66];
    size_t length = 0U;
    size_t decoded = 0U;
    int result = -1;
    sodium_memzero(token, sizeof(token));
    sodium_memzero(key, crypto_auth_hmacsha256_KEYBYTES);
    if (wls_recovery_read_controller_trust_file(
            home,
            "admin.token",
            geteuid(),
            token,
            sizeof(token),
            &length
        ) != 0
        || !((length == 64U) || (length == 65U && token[64] == '\n'))) {
        goto cleanup;
    }
    token[64] = '\0';
    if (!wls_is_hex_text(token, 64U)
        || sodium_hex2bin(
            key,
            crypto_auth_hmacsha256_KEYBYTES,
            token,
            64U,
            NULL,
            &decoded,
            NULL
        ) != 0
        || decoded != crypto_auth_hmacsha256_KEYBYTES) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(token, sizeof(token));
    if (result != 0) {
        sodium_memzero(key, crypto_auth_hmacsha256_KEYBYTES);
    }
    return result;
}

static int wls_guardian_hmac_hex(
    const char *home,
    const char *contents,
    size_t length,
    char output[65]
) {
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char digest[crypto_auth_hmacsha256_BYTES];
    int result = -1;
    sodium_memzero(key, sizeof(key));
    sodium_memzero(digest, sizeof(digest));
    if (home == NULL || contents == NULL || output == NULL
        || wls_guardian_admin_key(home, key) != 0
        || crypto_auth_hmacsha256(
            digest,
            (const unsigned char *)contents,
            (unsigned long long)length,
            key
        ) != 0
        || sodium_bin2hex(output, 65U, digest, sizeof(digest)) == NULL) {
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(key, sizeof(key));
    sodium_memzero(digest, sizeof(digest));
    if (result != 0 && output != NULL) sodium_memzero(output, 65U);
    return result;
}

static int wls_guardian_generation_id(
    const char *launcher,
    const char *ca,
    const char *runtime,
    char output[65]
) {
    char canonical[320];
    int length;
    int result = -1;
    if (launcher == NULL || ca == NULL || runtime == NULL || output == NULL
        || !wls_is_hex_text(launcher, 64U)
        || !wls_is_hex_text(ca, 64U)
        || !wls_is_hex_text(runtime, 64U)) return -1;
    length = snprintf(
        canonical,
        sizeof(canonical),
        "wls-guardian-active-generation/1%c%s%c%s%c%s",
        '\0',
        launcher,
        '\0',
        ca,
        '\0',
        runtime
    );
    if (length <= 0 || length >= (int)sizeof(canonical)
        || wls_sha256_text(
            (const unsigned char *)canonical,
            (size_t)length,
            output
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(canonical, sizeof(canonical));
    return result;
}

/* Guardian atomic publications share PHP's reserved companion spelling.
 * Readers reject retained evidence until the immutable Guardian reconciles
 * it under guardian-generation-head.lock. */
static int wls_guardian_atomic_companions_absent(const char *path)
{
    char parent_path[PATH_MAX];
    char temporary_prefix[NAME_MAX + 1U];
    char backup_prefix[NAME_MAX + 1U];
    char *slash;
    const char *leaf;
    struct stat before;
    struct stat opened;
    struct stat after;
    DIR *directory = NULL;
    struct dirent *entry;
    size_t visited = 0U;
    int result = -1;
    if (path == NULL || strlen(path) >= sizeof(parent_path)) return -1;
    memcpy(parent_path, path, strlen(path) + 1U);
    slash = strrchr(parent_path, '/');
    if (slash == NULL || slash == parent_path) return -1;
    leaf = slash + 1U;
    *slash = '\0';
    if (snprintf(
            temporary_prefix,
            sizeof(temporary_prefix),
            "%s.tmp-",
            leaf
        ) >= (int)sizeof(temporary_prefix)
        || snprintf(
            backup_prefix,
            sizeof(backup_prefix),
            "%s.wls-backup-",
            leaf
        ) >= (int)sizeof(backup_prefix)
        || lstat(parent_path, &before) != 0
        || !S_ISDIR(before.st_mode) || S_ISLNK(before.st_mode)
        || before.st_uid != geteuid() || (before.st_mode & 0022) != 0) {
        goto cleanup;
    }
    directory = opendir(parent_path);
    if (directory == NULL || fstat(dirfd(directory), &opened) != 0
        || !wls_launcher_same_file_state(&before, &opened)) goto cleanup;
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        size_t entry_length;
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (++visited > WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES) {
            goto cleanup;
        }
        entry_length = strlen(entry->d_name);
        if (wls_rebootstrap_ascii_prefix_equal(
                entry->d_name,
                entry_length,
                temporary_prefix
            )
            || wls_rebootstrap_ascii_prefix_equal(
                entry->d_name,
                entry_length,
                backup_prefix
            )) goto cleanup;
    }
    if (errno != 0 || fstat(dirfd(directory), &opened) != 0
        || lstat(parent_path, &after) != 0
        || !wls_launcher_same_file_state(&before, &opened)
        || !wls_launcher_same_file_state(&opened, &after)) goto cleanup;
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(temporary_prefix, sizeof(temporary_prefix));
    sodium_memzero(backup_prefix, sizeof(backup_prefix));
    return result;
}

static int wls_guardian_atomic_text(
    const char *path,
    const char *contents,
    mode_t mode
) {
    char temporary[PATH_MAX];
    char parent_path[PATH_MAX];
    char nonce[25];
    unsigned char nonce_bytes[12];
    char *slash;
    struct stat parent;
    struct stat opened_parent;
    int fd = -1;
    int parent_fd = -1;
    size_t length;
    int result = -1;
    sodium_memzero(temporary, sizeof(temporary));
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(nonce, sizeof(nonce));
    sodium_memzero(nonce_bytes, sizeof(nonce_bytes));
    if (path == NULL || contents == NULL || strlen(path) >= sizeof(parent_path)
        || wls_guardian_atomic_companions_absent(path) != 0) goto cleanup;
    length = strlen(contents);
    memcpy(parent_path, path, strlen(path) + 1U);
    slash = strrchr(parent_path, '/');
    if (slash == NULL) goto cleanup;
    *slash = '\0';
    if (lstat(parent_path, &parent) != 0
        || !S_ISDIR(parent.st_mode) || S_ISLNK(parent.st_mode)
        || parent.st_uid != geteuid() || (parent.st_mode & 0022) != 0) {
        goto cleanup;
    }
    randombytes_buf(nonce_bytes, sizeof(nonce_bytes));
    if (sodium_bin2hex(
            nonce,
            sizeof(nonce),
            nonce_bytes,
            sizeof(nonce_bytes)
        ) == NULL
        || snprintf(
            temporary,
            sizeof(temporary),
            "%s.tmp-%s",
            path,
            nonce
        ) >= (int)sizeof(temporary)) goto cleanup;
    parent_fd = open(
        parent_path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (parent_fd < 0 || fstat(parent_fd, &opened_parent) != 0
        || !wls_launcher_same_file_state(&parent, &opened_parent)) {
        goto cleanup;
    }
    fd = open(
        temporary,
        O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW,
        0600
    );
    if (fd < 0 || wls_write_all(fd, contents, length) != 0
        || fchown(fd, parent.st_uid, parent.st_gid) != 0
        || fchmod(fd, mode) != 0 || fsync(fd) != 0) goto cleanup;
    if (close(fd) != 0) {
        fd = -1;
        goto cleanup;
    }
    fd = -1;
    if (rename(temporary, path) != 0 || fsync(parent_fd) != 0) goto cleanup;
    temporary[0] = '\0';
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    if (parent_fd >= 0) close(parent_fd);
    if (temporary[0] != '\0') (void)unlink(temporary);
    sodium_memzero(temporary, sizeof(temporary));
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(nonce, sizeof(nonce));
    sodium_memzero(nonce_bytes, sizeof(nonce_bytes));
    return result;
}

static int wls_guardian_read_trust_document(
    const char *home,
    const char *leaf,
    char contents[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U],
    size_t *length
) {
    char trust[PATH_MAX];
    char path[PATH_MAX];
    if (home == NULL || leaf == NULL || strchr(leaf, '/') != NULL
        || contents == NULL || length == NULL
        || wls_join(trust, sizeof(trust), home, "trust") != 0
        || wls_join(path, sizeof(path), trust, leaf) != 0) return -1;
    if (wls_guardian_atomic_companions_absent(path) != 0) return 2;
    return wls_recovery_read_secure(
        path,
        0600,
        geteuid(),
        contents,
        WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U,
        length
    );
}

static int wls_guardian_verify_signed_document(
    const char *home,
    const char *contents,
    const char *signature_line,
    const char *signature_hex
) {
    char expected[65];
    int result = -1;
    sodium_memzero(expected, sizeof(expected));
    if (home == NULL || contents == NULL || signature_line == NULL
        || signature_hex == NULL || signature_line < contents
        || !wls_is_hex_text(signature_hex, 64U)
        || wls_guardian_hmac_hex(
            home,
            contents,
            (size_t)(signature_line - contents),
            expected
        ) != 0
        || sodium_memcmp(expected, signature_hex, 64U) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(expected, sizeof(expected));
    return result;
}

static int wls_guardian_request_parse(
    const char *home,
    const char *contents,
    size_t length,
    struct wls_guardian_transition_request *request
) {
    char sequence[21];
    char candidate_size[21];
    char candidate_mode[5];
    char recovery_size[21];
    char recovery_mode[5];
    char candidate_id[65];
    char recovery_id[65];
    char request_binding[3072];
    char request_binding_digest[65];
    char trust_rotation[2];
    const char *signature_line;
    char *end = NULL;
    unsigned long long expected_sequence;
    unsigned long long parsed_candidate_size;
    unsigned long long parsed_candidate_mode;
    unsigned long long parsed_recovery_size;
    unsigned long long parsed_recovery_mode;
    int consumed = 0;
    int fields;
    int request_binding_length;
    int result = -1;
    if (home == NULL || contents == NULL || request == NULL
        || length == 0U || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES
        || length > (size_t)INT_MAX) return -1;
    memset(request, 0, sizeof(*request));
    sodium_memzero(sequence, sizeof(sequence));
    sodium_memzero(candidate_size, sizeof(candidate_size));
    sodium_memzero(candidate_mode, sizeof(candidate_mode));
    sodium_memzero(recovery_size, sizeof(recovery_size));
    sodium_memzero(recovery_mode, sizeof(recovery_mode));
    sodium_memzero(candidate_id, sizeof(candidate_id));
    sodium_memzero(recovery_id, sizeof(recovery_id));
    sodium_memzero(request_binding, sizeof(request_binding));
    sodium_memzero(request_binding_digest, sizeof(request_binding_digest));
    sodium_memzero(trust_rotation, sizeof(trust_rotation));
    fields = sscanf(
        contents,
        "WLS-GUARDIAN-TRANSITION-REQUEST/1\n"
        "host_id=%32[0-9a-f]\n"
        "nonce=%32[0-9a-f]\n"
        "expected_head_sequence=%20[0-9]\n"
        "expected_head_sha256=%64[0-9a-f]\n"
        "journal_sha256=%64[0-9a-f]\n"
        "candidate_generation_id=%64[0-9a-f]\n"
        "candidate_launcher_sha256=%64[0-9a-f]\n"
        "candidate_launcher_size=%20[0-9]\n"
        "candidate_launcher_mode=%4[0-9]\n"
        "candidate_ca_sha256=%64[0-9a-f]\n"
        "candidate_runtime_generation=%64[0-9a-f]\n"
        "recovery_generation_id=%64[0-9a-f]\n"
        "recovery_launcher_sha256=%64[0-9a-f]\n"
        "recovery_launcher_size=%20[0-9]\n"
        "recovery_launcher_mode=%4[0-9]\n"
        "recovery_ca_sha256=%64[0-9a-f]\n"
        "recovery_runtime_generation=%64[0-9a-f]\n"
        "recovery_active_slot=%1[AB]\n"
        "recovery_previous_slot=%4[A-Z]\n"
        "recovery_slot_a_generation=%64[0-9a-f]\n"
        "recovery_slot_b_generation=%64[0-9a-f]\n"
        "derived_manifest_sha256=%64[0-9a-f]\n"
        "derived_policy_sha256=%64[0-9a-f]\n"
        "platform_kind=%16[a-z-]\n"
        "platform_profile=%9[a-z0-9-]\n"
        "platform_definition_sha256=%64[0-9a-f]\n"
        "platform_metadata_sha256=%64[0-9a-f]\n"
        "trust_rotation=%1[01]\n"
        "recovery_inventory_sha256=%64[0-9a-f]\n"
        "request_binding_sha256=%64[0-9a-f]\n"
        "recovery_authorization_sha256=%64[0-9a-f]\n"
        "signature=%64[0-9a-f]\n%n",
        request->host_id,
        request->nonce,
        sequence,
        request->expected_head_sha256,
        request->journal_sha256,
        request->candidate_generation_id,
        request->candidate_launcher_sha256,
        candidate_size,
        candidate_mode,
        request->candidate_ca_sha256,
        request->candidate_runtime_generation,
        request->recovery_generation_id,
        request->recovery_launcher_sha256,
        recovery_size,
        recovery_mode,
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
        trust_rotation,
        request->recovery_inventory_sha256,
        request->request_binding_sha256,
        request->recovery_authorization_sha256,
        request->signature,
        &consumed
    );
    errno = 0;
    expected_sequence = strtoull(sequence, &end, 10);
    if (errno != 0 || end == sequence || *end != '\0') goto cleanup;
    errno = 0;
    parsed_candidate_size = strtoull(candidate_size, &end, 10);
    if (errno != 0 || end == candidate_size || *end != '\0') goto cleanup;
    errno = 0;
    parsed_candidate_mode = strtoull(candidate_mode, &end, 10);
    if (errno != 0 || end == candidate_mode || *end != '\0') goto cleanup;
    errno = 0;
    parsed_recovery_size = strtoull(recovery_size, &end, 10);
    if (errno != 0 || end == recovery_size || *end != '\0') goto cleanup;
    errno = 0;
    parsed_recovery_mode = strtoull(recovery_mode, &end, 10);
    signature_line = strstr(contents, "signature=");
    if (fields != 32 || consumed != (int)length
        || signature_line == NULL
        || strlen(request->host_id) != 32U
        || strlen(request->nonce) != 32U
        || strlen(request->expected_head_sha256) != 64U
        || strlen(request->journal_sha256) != 64U
        || strlen(request->candidate_generation_id) != 64U
        || strlen(request->candidate_launcher_sha256) != 64U
        || strlen(candidate_size) == 0U || strlen(candidate_mode) == 0U
        || strlen(request->candidate_ca_sha256) != 64U
        || strlen(request->candidate_runtime_generation) != 64U
        || strlen(request->recovery_generation_id) != 64U
        || strlen(request->recovery_launcher_sha256) != 64U
        || strlen(recovery_size) == 0U || strlen(recovery_mode) == 0U
        || strlen(request->recovery_ca_sha256) != 64U
        || strlen(request->recovery_runtime_generation) != 64U
        || strlen(request->recovery_active_slot) != 1U
        || (strcmp(request->recovery_previous_slot, "NONE") != 0
            && strcmp(request->recovery_previous_slot, "A") != 0
            && strcmp(request->recovery_previous_slot, "B") != 0)
        || strcmp(
            request->recovery_previous_slot,
            request->recovery_active_slot
        ) == 0
        || strlen(request->recovery_slot_a_generation) != 64U
        || strlen(request->recovery_slot_b_generation) != 64U
        || strlen(request->derived_manifest_sha256) != 64U
        || strlen(request->derived_policy_sha256) != 64U
        || (strcmp(request->platform_kind, "test-session") != 0
            && strcmp(request->platform_kind, "launchd-system") != 0
            && strcmp(request->platform_kind, "systemd-system") != 0
            && strcmp(request->platform_kind, "windows-service") != 0)
        || (strcmp(request->platform_profile, "default") != 0
            && strcmp(request->platform_profile, "ipv4-only") != 0)
        || strlen(request->platform_definition_sha256) != 64U
        || strlen(request->platform_metadata_sha256) != 64U
        || strlen(trust_rotation) != 1U
        || strlen(request->recovery_inventory_sha256) != 64U
        || strlen(request->request_binding_sha256) != 64U
        || strlen(request->recovery_authorization_sha256) != 64U
        || strlen(request->signature) != 64U
        || (sequence[0] == '0' && sequence[1] != '\0')
        || (candidate_size[0] == '0' && candidate_size[1] != '\0')
        || (candidate_mode[0] == '0' && candidate_mode[1] != '\0')
        || (recovery_size[0] == '0' && recovery_size[1] != '\0')
        || (recovery_mode[0] == '0' && recovery_mode[1] != '\0')
        || errno != 0 || end == recovery_mode || *end != '\0'
        || expected_sequence == 0ULL
        || expected_sequence > (unsigned long long)LLONG_MAX
        || parsed_candidate_size == 0ULL
        || parsed_candidate_size > WLS_GUARDIAN_LAUNCHER_MAX_BYTES
        || parsed_candidate_mode == 0ULL || parsed_candidate_mode > 0777ULL
        || parsed_recovery_size == 0ULL
        || parsed_recovery_size > WLS_GUARDIAN_LAUNCHER_MAX_BYTES
        || parsed_recovery_mode == 0ULL || parsed_recovery_mode > 0777ULL
        || wls_guardian_generation_id(
            request->candidate_launcher_sha256,
            request->candidate_ca_sha256,
            request->candidate_runtime_generation,
            candidate_id
        ) != 0
        || sodium_memcmp(
            candidate_id, request->candidate_generation_id, 64U
        ) != 0
        || wls_guardian_generation_id(
            request->recovery_launcher_sha256,
            request->recovery_ca_sha256,
            request->recovery_runtime_generation,
            recovery_id
        ) != 0
        || sodium_memcmp(
            recovery_id, request->recovery_generation_id, 64U
        ) != 0
        || sodium_memcmp(
            request->recovery_runtime_generation,
            request->recovery_active_slot[0] == 'A'
                ? request->recovery_slot_a_generation
                : request->recovery_slot_b_generation,
            64U
        ) != 0) goto cleanup;
    request->expected_head_sequence = expected_sequence;
    request->candidate_launcher_size = parsed_candidate_size;
    request->candidate_launcher_mode = (unsigned int)parsed_candidate_mode;
    request->recovery_launcher_size = parsed_recovery_size;
    request->recovery_launcher_mode = (unsigned int)parsed_recovery_mode;
    request->trust_rotation = trust_rotation[0] == '1' ? 1 : 0;
    request_binding_length = snprintf(
        request_binding,
        sizeof(request_binding),
        "WLS-GUARDIAN-REQUEST-BINDING/1\n"
        "host_id=%s\n"
        "nonce=%s\n"
        "expected_head_sequence=%llu\n"
        "expected_head_sha256=%s\n"
        "journal_sha256=%s\n"
        "candidate_generation_id=%s\n"
        "candidate_launcher_sha256=%s\n"
        "candidate_launcher_size=%llu\n"
        "candidate_launcher_mode=%u\n"
        "candidate_ca_sha256=%s\n"
        "candidate_runtime_generation=%s\n"
        "recovery_generation_id=%s\n"
        "recovery_launcher_sha256=%s\n"
        "recovery_launcher_size=%llu\n"
        "recovery_launcher_mode=%u\n"
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
    if (request_binding_length <= 0
        || request_binding_length >= (int)sizeof(request_binding)
        || wls_sha256_text(
            (const unsigned char *)request_binding,
            (size_t)request_binding_length,
            request_binding_digest
        ) != 0
        || sodium_memcmp(
            request_binding_digest,
            request->request_binding_sha256,
            64U
        ) != 0
        || wls_guardian_verify_signed_document(
            home,
            contents,
            signature_line,
            request->signature
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)contents,
            length,
            request->raw_sha256
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(sequence, sizeof(sequence));
    sodium_memzero(candidate_size, sizeof(candidate_size));
    sodium_memzero(candidate_mode, sizeof(candidate_mode));
    sodium_memzero(recovery_size, sizeof(recovery_size));
    sodium_memzero(recovery_mode, sizeof(recovery_mode));
    sodium_memzero(candidate_id, sizeof(candidate_id));
    sodium_memzero(recovery_id, sizeof(recovery_id));
    sodium_memzero(request_binding, sizeof(request_binding));
    sodium_memzero(request_binding_digest, sizeof(request_binding_digest));
    sodium_memzero(trust_rotation, sizeof(trust_rotation));
    if (result != 0) sodium_memzero(request, sizeof(*request));
    return result;
}

static int wls_guardian_request_read(
    const char *home,
    struct wls_guardian_transition_request *request
) {
    char contents[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    size_t length = 0U;
    int read_result;
    int result = -1;
    sodium_memzero(contents, sizeof(contents));
    read_result = wls_guardian_read_trust_document(
        home,
        "guardian-transition.request",
        contents,
        &length
    );
    if (read_result == 1) {
        result = 1;
        goto cleanup;
    }
    if (read_result == 2) {
        result = 2;
        goto cleanup;
    }
    if (read_result == 0
        && wls_guardian_request_parse(
            home, contents, length, request
        ) == 0) result = 0;
cleanup:
    sodium_memzero(contents, sizeof(contents));
    return result;
}

static int wls_guardian_head_parse(
    const char *home,
    const char *contents,
    size_t length,
    int slot,
    struct wls_guardian_generation_head *head
) {
    char sequence[21];
    char probation_start[21];
    char probation_deadline[21];
    char generation_id[65];
    const char *signature_line;
    char *end = NULL;
    unsigned long long parsed_sequence;
    unsigned long long parsed_start;
    unsigned long long parsed_deadline;
    int consumed = 0;
    int fields;
    int result = -1;
    if (home == NULL || contents == NULL || head == NULL
        || (slot != 0 && slot != 1) || length == 0U
        || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES
        || length > (size_t)INT_MAX) return -1;
    memset(head, 0, sizeof(*head));
    sodium_memzero(sequence, sizeof(sequence));
    sodium_memzero(probation_start, sizeof(probation_start));
    sodium_memzero(probation_deadline, sizeof(probation_deadline));
    sodium_memzero(generation_id, sizeof(generation_id));
    fields = sscanf(
        contents,
        "WLS-GUARDIAN-GENERATION-HEAD/1\n"
        "host_id=%32[0-9a-f]\n"
        "sequence=%20[0-9]\n"
        "phase=%31[A-Z_]\n"
        "active_generation_id=%64[0-9a-f]\n"
        "active_launcher_sha256=%64[0-9a-f]\n"
        "active_ca_sha256=%64[0-9a-f]\n"
        "active_runtime_generation=%64[0-9a-f]\n"
        "recovery_generation_id=%64[0-9a-f]\n"
        "recovery_nonce=%32[0-9a-f]\n"
        "recovery_authorization_sha256=%64[0-9a-f]\n"
        "host_boot_id=%64[0-9a-f]\n"
        "probation_started_monotonic_ms=%20[0-9]\n"
        "probation_deadline_monotonic_ms=%20[0-9]\n"
        "previous_record_sha256=%64[0-9a-f]\n"
        "signature=%64[0-9a-f]\n%n",
        head->host_id,
        sequence,
        head->phase,
        head->active_generation_id,
        head->active_launcher_sha256,
        head->active_ca_sha256,
        head->active_runtime_generation,
        head->recovery_generation_id,
        head->recovery_nonce,
        head->recovery_authorization_sha256,
        head->host_boot_id,
        probation_start,
        probation_deadline,
        head->previous_record_sha256,
        head->signature,
        &consumed
    );
    errno = 0;
    parsed_sequence = strtoull(sequence, &end, 10);
    if (errno != 0 || end == sequence || *end != '\0') goto cleanup;
    errno = 0;
    parsed_start = strtoull(probation_start, &end, 10);
    if (errno != 0 || end == probation_start || *end != '\0') goto cleanup;
    errno = 0;
    parsed_deadline = strtoull(probation_deadline, &end, 10);
    signature_line = strstr(contents, "signature=");
    if (fields != 15 || consumed != (int)length
        || signature_line == NULL
        || strlen(head->host_id) != 32U
        || strlen(head->phase) == 0U
        || strlen(head->active_generation_id) != 64U
        || strlen(head->active_launcher_sha256) != 64U
        || strlen(head->active_ca_sha256) != 64U
        || strlen(head->active_runtime_generation) != 64U
        || strlen(head->recovery_generation_id) != 64U
        || strlen(head->recovery_nonce) != 32U
        || strlen(head->recovery_authorization_sha256) != 64U
        || strlen(head->host_boot_id) != 64U
        || strlen(head->previous_record_sha256) != 64U
        || strlen(head->signature) != 64U
        || (sequence[0] == '0' && sequence[1] != '\0')
        || (probation_start[0] == '0' && probation_start[1] != '\0')
        || (probation_deadline[0] == '0'
            && probation_deadline[1] != '\0')
        || errno != 0 || end == probation_deadline || *end != '\0'
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
        || sodium_memcmp(
            generation_id, head->active_generation_id, 64U
        ) != 0
        || wls_guardian_verify_signed_document(
            home,
            contents,
            signature_line,
            head->signature
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)contents,
            length,
            head->raw_sha256
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
            ) != 0)) {
        goto cleanup;
    }
    head->slot = slot;
    head->sequence = parsed_sequence;
    head->probation_started_monotonic_ms = parsed_start;
    head->probation_deadline_monotonic_ms = parsed_deadline;
    result = 0;
cleanup:
    sodium_memzero(sequence, sizeof(sequence));
    sodium_memzero(probation_start, sizeof(probation_start));
    sodium_memzero(probation_deadline, sizeof(probation_deadline));
    sodium_memzero(generation_id, sizeof(generation_id));
    if (result != 0) sodium_memzero(head, sizeof(*head));
    return result;
}

static int wls_guardian_head_slot_read(
    const char *home,
    int slot,
    struct wls_guardian_generation_head *head
) {
    char leaf[64];
    char contents[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    size_t length = 0U;
    int read_result;
    int result = -1;
    sodium_memzero(contents, sizeof(contents));
    if (snprintf(
            leaf,
            sizeof(leaf),
            "guardian-generation-head.%d",
            slot
        ) >= (int)sizeof(leaf)) goto cleanup;
    read_result = wls_guardian_read_trust_document(
        home, leaf, contents, &length
    );
    if (read_result == 1) {
        result = 1;
        goto cleanup;
    }
    if (read_result == 2) {
        result = 2;
        goto cleanup;
    }
    if (read_result == 0
        && wls_guardian_head_parse(
            home, contents, length, slot, head
        ) == 0) result = 0;
cleanup:
    sodium_memzero(contents, sizeof(contents));
    sodium_memzero(leaf, sizeof(leaf));
    return result;
}

static int wls_guardian_head_same_active(
    const struct wls_guardian_generation_head *left,
    const struct wls_guardian_generation_head *right
) {
    return sodium_memcmp(
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
    return sodium_memcmp(
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
        || sodium_memcmp(from->host_id, to->host_id, 32U) != 0) return 0;
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
            && same_active
            && wls_guardian_head_same_recovery(from, to);
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
        && strcmp(to->phase, "STABLE") == 0) {
        return same_active;
    }
    if (strcmp(to->phase, "FAILED_CLOSED") == 0) {
        return (strcmp(from->phase, "PROBATIONARY_COMMITTED") == 0
                || strcmp(from->phase, "ROLLBACK_PENDING") == 0
                || strcmp(from->phase, "ROLLBACK_OBSERVING") == 0)
            && same_active
            && wls_guardian_head_same_recovery(from, to);
    }
    return 0;
}

static int wls_guardian_head_read(
    const char *home,
    struct wls_guardian_generation_head *selected
) {
    struct wls_guardian_generation_head heads[2];
    int states[2];
    int newest;
    int older;
    int result = -1;
    memset(heads, 0, sizeof(heads));
    states[0] = wls_guardian_head_slot_read(home, 0, &heads[0]);
    states[1] = wls_guardian_head_slot_read(home, 1, &heads[1]);
    if (states[0] == 2 || states[1] == 2) {
        result = 2;
        goto cleanup;
    }
    if (states[0] == 1 && states[1] == 1) {
        result = 1;
        goto cleanup;
    }
    if (states[0] != 0 && states[1] != 0) goto cleanup;
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
    newest = heads[1].sequence > heads[0].sequence ? 1 : 0;
    older = 1 - newest;
    if (heads[0].sequence == heads[1].sequence) {
        if (sodium_memcmp(
                heads[0].raw_sha256, heads[1].raw_sha256, 64U
            ) != 0) goto cleanup;
        newest = 0;
    } else if (heads[newest].sequence != heads[older].sequence + 1ULL
        || sodium_memcmp(
            heads[newest].previous_record_sha256,
            heads[older].raw_sha256,
            64U
        ) != 0) {
        goto cleanup;
    }
    if (sodium_memcmp(heads[0].host_id, heads[1].host_id, 32U) != 0) {
        goto cleanup;
    }
    if (heads[0].sequence != heads[1].sequence
        && !wls_guardian_head_transition_valid(
            &heads[older], &heads[newest]
        )) goto cleanup;
    *selected = heads[newest];
    result = 0;
cleanup:
    sodium_memzero(heads, sizeof(heads));
    return result;
}

static int wls_guardian_head_publish(
    const char *home,
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
    static const char zeros64[] =
        "0000000000000000000000000000000000000000000000000000000000000000";
    char path[PATH_MAX];
    char unsigned_record[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    char encoded[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    char signature[65];
    const char *recovery_generation;
    const char *recovery_nonce;
    const char *recovery_authorization;
    const char *active_generation;
    const char *active_launcher;
    const char *active_ca;
    const char *active_runtime;
    unsigned long long sequence;
    int unsigned_length;
    int encoded_length;
    int target_slot;
    int result = -1;
    sodium_memzero(unsigned_record, sizeof(unsigned_record));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(signature, sizeof(signature));
    if (home == NULL || current == NULL || request == NULL || phase == NULL
        || boot_id == NULL || published == NULL
        || current->sequence == 0ULL
        || current->sequence >= (unsigned long long)LLONG_MAX
        || (recovery_active != 0 && recovery_active != 1)
        || (strcmp(phase, "STABLE") != 0
            && strcmp(phase, "PROBATIONARY_COMMITTED") != 0
            && strcmp(phase, "ROLLBACK_PENDING") != 0
            && strcmp(phase, "ROLLBACK_OBSERVING") != 0)) goto cleanup;
    sequence = current->sequence + 1ULL;
    active_generation = recovery_active
        ? request->recovery_generation_id : request->candidate_generation_id;
    active_launcher = recovery_active
        ? request->recovery_launcher_sha256 : request->candidate_launcher_sha256;
    active_ca = recovery_active
        ? request->recovery_ca_sha256 : request->candidate_ca_sha256;
    active_runtime = recovery_active
        ? request->recovery_runtime_generation
        : request->candidate_runtime_generation;
    recovery_generation = strcmp(phase, "STABLE") == 0
        ? zeros64 : request->recovery_generation_id;
    recovery_nonce = strcmp(phase, "STABLE") == 0
        ? zeros32 : request->nonce;
    recovery_authorization = strcmp(phase, "STABLE") == 0
        ? zeros64 : request->recovery_authorization_sha256;
    unsigned_length = snprintf(
        unsigned_record,
        sizeof(unsigned_record),
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
            home,
            unsigned_record,
            (size_t)unsigned_length,
            signature
        ) != 0) goto cleanup;
    encoded_length = snprintf(
        encoded,
        sizeof(encoded),
        "%ssignature=%s\n",
        unsigned_record,
        signature
    );
    target_slot = 1 - current->slot;
    if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
        || snprintf(
            path,
            sizeof(path),
            "%s/trust/guardian-generation-head.%d",
            home,
            target_slot
        ) >= (int)sizeof(path)
        || wls_recovery_target_safe(path, 0600, geteuid()) != 0
        || wls_guardian_atomic_text(path, encoded, 0600) != 0
        || wls_recovery_target_safe(path, 0600, geteuid()) != 0
        || wls_guardian_head_read(home, published) != 0
        || published->sequence != sequence
        || published->slot != target_slot) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(path, sizeof(path));
    sodium_memzero(unsigned_record, sizeof(unsigned_record));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(signature, sizeof(signature));
    return result;
}

static int wls_guardian_ack_publish(
    const char *home,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_generation_head *head,
    int recovery_active
) {
    char path[PATH_MAX];
    char unsigned_ack[1024];
    char encoded[1200];
    char existing[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    char signature[65];
    char existing_host[33];
    char existing_nonce[33];
    char existing_request[65];
    char existing_sequence[21];
    char existing_head[65];
    char existing_purpose[9];
    char existing_generation[65];
    char existing_signature[65];
    const char *existing_signature_line = NULL;
    const char *purpose;
    const char *expected_generation;
    size_t existing_length = 0U;
    int unsigned_length;
    int encoded_length;
    int read_result;
    int consumed = 0;
    int result = -1;
    sodium_memzero(path, sizeof(path));
    sodium_memzero(unsigned_ack, sizeof(unsigned_ack));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(existing, sizeof(existing));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(existing_host, sizeof(existing_host));
    sodium_memzero(existing_nonce, sizeof(existing_nonce));
    sodium_memzero(existing_request, sizeof(existing_request));
    sodium_memzero(existing_sequence, sizeof(existing_sequence));
    sodium_memzero(existing_head, sizeof(existing_head));
    sodium_memzero(existing_purpose, sizeof(existing_purpose));
    sodium_memzero(existing_generation, sizeof(existing_generation));
    sodium_memzero(existing_signature, sizeof(existing_signature));
    if (home == NULL || request == NULL || head == NULL
        || (recovery_active != 0 && recovery_active != 1)) goto cleanup;
    purpose = recovery_active ? "rollback" : "commit";
    expected_generation = recovery_active
        ? request->recovery_generation_id
        : request->candidate_generation_id;
    if (strcmp(head->phase, "STABLE") != 0
        || sodium_memcmp(
            head->active_generation_id,
            expected_generation,
            64U
        ) != 0) goto cleanup;
    unsigned_length = snprintf(
        unsigned_ack,
        sizeof(unsigned_ack),
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
            home,
            unsigned_ack,
            (size_t)unsigned_length,
            signature
        ) != 0) goto cleanup;
    encoded_length = snprintf(
        encoded,
        sizeof(encoded),
        "%ssignature=%s\n",
        unsigned_ack,
        signature
    );
    if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/guardian-transition.ack"
        ) != 0) goto cleanup;
    if (wls_guardian_atomic_companions_absent(path) != 0) goto cleanup;
    read_result = wls_recovery_read_secure(
        path,
        0600,
        geteuid(),
        existing,
        sizeof(existing),
        &existing_length
    );
    if (read_result == 0) {
        if (existing_length == (size_t)encoded_length
            && sodium_memcmp(existing, encoded, existing_length) == 0
        ) {
            result = 0;
            goto cleanup;
        }
        if (!recovery_active) goto cleanup;
        consumed = 0;
        if (sscanf(
                existing,
                "WLS-GUARDIAN-TRANSITION-ACK/1\n"
                "host_id=%32[0-9a-f]\n"
                "nonce=%32[0-9a-f]\n"
                "request_sha256=%64[0-9a-f]\n"
                "committed_head_sequence=%20[0-9]\n"
                "committed_head_sha256=%64[0-9a-f]\n"
                "purpose=%8[a-z]\n"
                "phase=STABLE\n"
                "active_generation_id=%64[0-9a-f]\n"
                "signature=%64[0-9a-f]\n%n",
                existing_host,
                existing_nonce,
                existing_request,
                existing_sequence,
                existing_head,
                existing_purpose,
                existing_generation,
                existing_signature,
                &consumed
            ) != 8
            || consumed != (int)existing_length
            || strcmp(existing_purpose, "commit") != 0
            || sodium_memcmp(existing_host, request->host_id, 32U) != 0
            || sodium_memcmp(existing_nonce, request->nonce, 32U) != 0
            || sodium_memcmp(existing_request, request->raw_sha256, 64U) != 0
            || sodium_memcmp(
                existing_generation,
                request->candidate_generation_id,
                64U
            ) != 0
            || (existing_signature_line = strstr(existing, "signature=")) == NULL
            || wls_guardian_verify_signed_document(
                home,
                existing,
                existing_signature_line,
                existing_signature
            ) != 0) goto cleanup;
    }
    if (read_result != 0 && read_result != 1) goto cleanup;
    if (wls_recovery_target_safe(path, 0600, geteuid()) != 0
        || wls_guardian_atomic_text(path, encoded, 0600) != 0
        || wls_recovery_target_safe(path, 0600, geteuid()) != 0) {
        goto cleanup;
    }
    existing_length = 0U;
    if (wls_recovery_read_secure(
            path,
            0600,
            geteuid(),
            existing,
            sizeof(existing),
            &existing_length
        ) != 0
        || existing_length != (size_t)encoded_length
        || sodium_memcmp(existing, encoded, existing_length) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(path, sizeof(path));
    sodium_memzero(unsigned_ack, sizeof(unsigned_ack));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(existing, sizeof(existing));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(existing_host, sizeof(existing_host));
    sodium_memzero(existing_nonce, sizeof(existing_nonce));
    sodium_memzero(existing_request, sizeof(existing_request));
    sodium_memzero(existing_sequence, sizeof(existing_sequence));
    sodium_memzero(existing_head, sizeof(existing_head));
    sodium_memzero(existing_purpose, sizeof(existing_purpose));
    sodium_memzero(existing_generation, sizeof(existing_generation));
    sodium_memzero(existing_signature, sizeof(existing_signature));
    return result;
}

static int wls_guardian_transition_lock(
    const char *home,
    int *lock_fd
) {
    char path[PATH_MAX];
    struct stat status;
    int fd;
    if (home == NULL || lock_fd == NULL
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/guardian-generation-head.lock"
        ) != 0) return -1;
    fd = open(path, O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (fd < 0 || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode) || status.st_nlink != 1
        || status.st_uid != geteuid() || (status.st_mode & 0777) != 0600) {
        if (fd >= 0) close(fd);
        return -1;
    }
    if (flock(fd, LOCK_EX | LOCK_NB) != 0) {
        int busy = errno == EWOULDBLOCK || errno == EAGAIN;
        close(fd);
        return busy ? 1 : -1;
    }
    *lock_fd = fd;
    return 0;
}

static int wls_guardian_verify_regular_digest(
    const char *path,
    const char *expected_digest,
    mode_t expected_mode,
    off_t maximum_size
) {
    crypto_hash_sha256_state digest_state;
    unsigned char digest_binary[crypto_hash_sha256_BYTES];
    unsigned char buffer[65536];
    char actual_digest[65];
    struct stat before;
    struct stat after;
    ssize_t amount;
    int fd = -1;
    int result = -1;
    sodium_memzero(&digest_state, sizeof(digest_state));
    sodium_memzero(digest_binary, sizeof(digest_binary));
    sodium_memzero(buffer, sizeof(buffer));
    sodium_memzero(actual_digest, sizeof(actual_digest));
    if (path == NULL || expected_digest == NULL
        || !wls_is_hex_text(expected_digest, 64U)
        || maximum_size < 1) goto cleanup;
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || fstat(fd, &before) != 0
        || !S_ISREG(before.st_mode) || S_ISLNK(before.st_mode)
        || before.st_nlink != 1 || before.st_uid != geteuid()
        || (before.st_mode & 0777) != expected_mode
        || before.st_size < 1 || before.st_size > maximum_size
        || crypto_hash_sha256_init(&digest_state) != 0) goto cleanup;
    for (;;) {
        amount = read(fd, buffer, sizeof(buffer));
        if (amount < 0 && errno == EINTR) continue;
        if (amount < 0) goto cleanup;
        if (amount == 0) break;
        if (crypto_hash_sha256_update(
                &digest_state,
                buffer,
                (unsigned long long)amount
            ) != 0) goto cleanup;
    }
    if (crypto_hash_sha256_final(&digest_state, digest_binary) != 0
        || sodium_bin2hex(
            actual_digest,
            sizeof(actual_digest),
            digest_binary,
            sizeof(digest_binary)
        ) == NULL
        || fstat(fd, &after) != 0
        || before.st_dev != after.st_dev
        || before.st_ino != after.st_ino
        || before.st_size != after.st_size
        || before.st_mode != after.st_mode
        || before.st_nlink != after.st_nlink
        || before.st_uid != after.st_uid
        || before.st_gid != after.st_gid
#if defined(__APPLE__) || defined(__FreeBSD__)
        || before.st_mtimespec.tv_sec != after.st_mtimespec.tv_sec
        || before.st_mtimespec.tv_nsec != after.st_mtimespec.tv_nsec
        || before.st_ctimespec.tv_sec != after.st_ctimespec.tv_sec
        || before.st_ctimespec.tv_nsec != after.st_ctimespec.tv_nsec
#else
        || before.st_mtim.tv_sec != after.st_mtim.tv_sec
        || before.st_mtim.tv_nsec != after.st_mtim.tv_nsec
        || before.st_ctim.tv_sec != after.st_ctim.tv_sec
        || before.st_ctim.tv_nsec != after.st_ctim.tv_nsec
#endif
        || sodium_memcmp(actual_digest, expected_digest, 64U) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    sodium_memzero(&digest_state, sizeof(digest_state));
    sodium_memzero(digest_binary, sizeof(digest_binary));
    sodium_memzero(buffer, sizeof(buffer));
    sodium_memzero(actual_digest, sizeof(actual_digest));
    return result;
}

static int wls_guardian_backup_root(
    const char *home,
    const char *nonce,
    char backup[PATH_MAX]
) {
    char rebootstrap[PATH_MAX];
    char backups[PATH_MAX];
    struct stat home_status;
    struct stat rebootstrap_status;
    struct stat backups_status;
    struct stat backup_status;
    if (home == NULL || nonce == NULL || backup == NULL
        || !wls_is_hex_text(nonce, 32U)
        || wls_join(rebootstrap, sizeof(rebootstrap), home, "rebootstrap") != 0
        || wls_join(backups, sizeof(backups), rebootstrap, "backups") != 0
        || wls_join(backup, PATH_MAX, backups, nonce) != 0
        || lstat(home, &home_status) != 0
        || lstat(rebootstrap, &rebootstrap_status) != 0
        || lstat(backups, &backups_status) != 0
        || lstat(backup, &backup_status) != 0
        || !S_ISDIR(home_status.st_mode) || S_ISLNK(home_status.st_mode)
        || !S_ISDIR(rebootstrap_status.st_mode)
        || S_ISLNK(rebootstrap_status.st_mode)
        || !S_ISDIR(backups_status.st_mode) || S_ISLNK(backups_status.st_mode)
        || !S_ISDIR(backup_status.st_mode) || S_ISLNK(backup_status.st_mode)
        || home_status.st_uid != geteuid()
        || rebootstrap_status.st_uid != geteuid()
        || backups_status.st_uid != geteuid()
        || backup_status.st_uid != geteuid()
        || (home_status.st_mode & 0022) != 0
        || (rebootstrap_status.st_mode & 0077) != 0
        || (backups_status.st_mode & 0077) != 0
        || (backup_status.st_mode & 0077) != 0
        || home_status.st_dev != backup_status.st_dev) {
        sodium_memzero(rebootstrap, sizeof(rebootstrap));
        sodium_memzero(backups, sizeof(backups));
        if (backup != NULL) sodium_memzero(backup, PATH_MAX);
        return -1;
    }
    sodium_memzero(rebootstrap, sizeof(rebootstrap));
    sodium_memzero(backups, sizeof(backups));
    return 0;
}

static int wls_guardian_read_bounded_regular(
    const char *path,
    size_t maximum,
    mode_t mode,
    char **contents,
    size_t *length
) {
    struct stat before;
    struct stat after;
    size_t used = 0U;
    int fd = -1;
    int result = -1;
    if (path == NULL || maximum == 0U || contents == NULL || length == NULL) {
        return -1;
    }
    *contents = NULL;
    *length = 0U;
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || fstat(fd, &before) != 0
        || !S_ISREG(before.st_mode) || before.st_nlink != 1
        || before.st_uid != geteuid() || (before.st_mode & 0777) != mode
        || before.st_size <= 0 || (uint64_t)before.st_size > maximum
        || (uint64_t)before.st_size >= (uint64_t)SIZE_MAX
        || wls_guardian_acl_free_fd(fd, 0) != 0) goto cleanup;
    *contents = malloc((size_t)before.st_size + 1U);
    if (*contents == NULL) goto cleanup;
    while (used < (size_t)before.st_size) {
        ssize_t amount = read(
            fd,
            *contents + used,
            (size_t)before.st_size - used
        );
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) goto cleanup;
        used += (size_t)amount;
    }
    if (fstat(fd, &after) != 0
        || !wls_launcher_same_file_state(&before, &after)
        || memchr(*contents, '\0', used) != NULL) goto cleanup;
    (*contents)[used] = '\0';
    *length = used;
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    if (result != 0 && *contents != NULL) {
        sodium_memzero(*contents, used);
        free(*contents);
        *contents = NULL;
        *length = 0U;
    }
    return result;
}

static int wls_guardian_recovery_authorization_verify_path(
    const char *home,
    const char *path,
    const struct wls_guardian_transition_request *request
) {
    char unsigned_authorization[3072];
    char expected[3328];
    char signature[65];
    char digest[65];
    char *contents = NULL;
    size_t length = 0U;
    int unsigned_length;
    int expected_length;
    int result = -1;
    sodium_memzero(unsigned_authorization, sizeof(unsigned_authorization));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(digest, sizeof(digest));
    if (home == NULL || path == NULL || request == NULL
        || wls_guardian_read_bounded_regular(
            path,
            WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
            0600,
            &contents,
            &length
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)contents,
            length,
            digest
        ) != 0
        || sodium_memcmp(
            digest,
            request->recovery_authorization_sha256,
            64U
        ) != 0) goto cleanup;
    unsigned_length = snprintf(
        unsigned_authorization,
        sizeof(unsigned_authorization),
        "WLS-GUARDIAN-RECOVERY-AUTHORIZATION/1\n"
        "host_id=%s\n"
        "nonce=%s\n"
        "expected_head_sequence=%llu\n"
        "expected_head_sha256=%s\n"
        "journal_sha256=%s\n"
        "candidate_generation_id=%s\n"
        "candidate_launcher_sha256=%s\n"
        "candidate_launcher_size=%llu\n"
        "candidate_launcher_mode=%u\n"
        "candidate_ca_sha256=%s\n"
        "candidate_runtime_generation=%s\n"
        "recovery_generation_id=%s\n"
        "recovery_launcher_sha256=%s\n"
        "recovery_launcher_size=%llu\n"
        "recovery_launcher_mode=%u\n"
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
    expected_length = snprintf(
        expected,
        sizeof(expected),
        "%ssignature=%s\n",
        unsigned_authorization,
        signature
    );
    if (expected_length <= 0 || expected_length >= (int)sizeof(expected)
        || length != (size_t)expected_length
        || sodium_memcmp(contents, expected, length) != 0) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    sodium_memzero(unsigned_authorization, sizeof(unsigned_authorization));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(digest, sizeof(digest));
    return result;
}

static int wls_guardian_recovery_authorization_verify(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request
) {
    char path[PATH_MAX];
    int result = -1;
    sodium_memzero(path, sizeof(path));
    if (home != NULL && backup != NULL && request != NULL
        && wls_join(
            path,
            sizeof(path),
            backup,
            "guardian-recovery.authorization"
        ) == 0
        && wls_guardian_atomic_companions_absent(path) == 0) {
        result = wls_guardian_recovery_authorization_verify_path(
            home, path, request
        );
    }
    sodium_memzero(path, sizeof(path));
    return result;
}

static int wls_guardian_inventory_policy_valid(
    const char *category,
    const char *policy
) {
    if (category == NULL || policy == NULL) return 0;
    if (strcmp(category, "state") == 0
        || strcmp(category, "trust") == 0
        || strcmp(category, "snapshots") == 0
        || strcmp(category, "snapshots-v2") == 0
        || strcmp(category, "snapshot-candidates-v2") == 0
        || strcmp(category, "runtime-conf") == 0) {
        return strcmp(policy, "restore") == 0;
    }
    if (strcmp(category, "runtime-temp") == 0
        || strcmp(category, "runtime-shadow") == 0
        || strcmp(category, "runtime-run") == 0) {
        return strcmp(policy, "ephemeral") == 0;
    }
    return 0;
}

struct wls_guardian_root_contract {
    const char *category;
    const char *policy;
    const char *authority_profile;
    const char *authority_policy;
    const char *parent_authority_profile;
    const char *parent_authority_policy;
    int preserve_identity;
};

static const struct wls_guardian_root_contract
wls_guardian_root_contracts[WLS_GUARDIAN_DERIVED_CATEGORY_COUNT] = {
    {
        "runtime-conf", "restore", "controller-runtime-child-v2",
        "controller-runtime-child-v2-recreate-sealed",
        "controller-data-plane-runtime-v2",
        "controller-data-plane-runtime-v2-fixed-parent", 0
    },
    {
        "runtime-run", "ephemeral", "controller-runtime-child-v2",
        "controller-runtime-child-v2-recreate-sealed",
        "controller-data-plane-runtime-v2",
        "controller-data-plane-runtime-v2-fixed-parent", 0
    },
    {
        "runtime-shadow", "ephemeral", "controller-runtime-child-v2",
        "controller-runtime-child-v2-recreate-sealed",
        "controller-data-plane-runtime-v2",
        "controller-data-plane-runtime-v2-fixed-parent", 0
    },
    {
        "runtime-temp", "ephemeral", "controller-runtime-child-v2",
        "controller-runtime-child-v2-recreate-sealed",
        "controller-data-plane-runtime-v2",
        "controller-data-plane-runtime-v2-fixed-parent", 0
    },
    {
        "snapshot-candidates-v2", "restore",
        "controller-snapshot-candidates-private-v2",
        "controller-snapshot-candidates-private-v2-recreate-sealed",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 0
    },
    {
        "snapshots", "restore", "controller-data-plane-search-v2",
        "controller-data-plane-search-v2-recreate-sealed",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 0
    },
    {
        "snapshots-v2", "restore", "root-data-plane-search-v2",
        "root-data-plane-search-v2-recreate-sealed",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 0
    },
    {
        "state", "restore", "controller-private-v2",
        "controller-private-v2-preserve-identity",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 1
    },
    {
        "trust", "restore", "root-controller-read-v2",
        "root-controller-read-v2-preserve-identity",
        "host-root-controller-search-v2",
        "host-root-controller-search-v2-fixed-parent", 1
    }
};

static int wls_guardian_unsigned_token(
    const char *token,
    unsigned long long maximum,
    unsigned long long *value
) {
    char *end = NULL;
    unsigned long long decoded;
    if (token == NULL || value == NULL || token[0] == '\0'
        || (token[0] == '0' && token[1] != '\0')) return -1;
    errno = 0;
    decoded = strtoull(token, &end, 10);
    if (errno != 0 || end == token || *end != '\0' || decoded > maximum) {
        return -1;
    }
    *value = decoded;
    return 0;
}

static int wls_guardian_authority_digest(
    const struct wls_guardian_root_authority *authority,
    int parent,
    char digest[65]
) {
    char canonical[512];
    unsigned char binary[crypto_hash_sha256_BYTES];
    int length;
    int result = -1;
    sodium_memzero(canonical, sizeof(canonical));
    sodium_memzero(binary, sizeof(binary));
    if (authority == NULL || digest == NULL) goto cleanup;
    if (!parent && !authority->present) {
        length = snprintf(
            canonical,
            sizeof(canonical),
            "%s\nabsent\n",
            authority->authority_policy
        );
    } else if (parent) {
        length = snprintf(
            canonical,
            sizeof(canonical),
            "{\"gid\":%llu,\"mode\":%llu,\"policy\":\"%s\","
            "\"scope\":\"parent\",\"uid\":%llu}",
            authority->parent_gid,
            authority->parent_mode,
            authority->parent_authority_policy,
            authority->parent_uid
        );
    } else {
        length = snprintf(
            canonical,
            sizeof(canonical),
            "{\"gid\":%llu,\"mode\":%llu,\"policy\":\"%s\","
            "\"uid\":%llu}",
            authority->gid,
            authority->mode,
            authority->authority_policy,
            authority->uid
        );
    }
    if (length <= 0 || length >= (int)sizeof(canonical)
        || crypto_hash_sha256(
            binary,
            (const unsigned char *)canonical,
            (unsigned long long)length
        ) != 0) goto cleanup;
    sodium_bin2hex(digest, 65U, binary, sizeof(binary));
    result = wls_is_hex_text(digest, 64U) ? 0 : -1;
cleanup:
    sodium_memzero(canonical, sizeof(canonical));
    sodium_memzero(binary, sizeof(binary));
    return result;
}

static int wls_guardian_service_identity_values(
    uid_t *controller_uid,
    gid_t *controller_gid,
    gid_t *data_plane_gid
) {
    const char *controller_name =
#if defined(__APPLE__)
        "_welinegateway";
    const char *data_plane_name = "_welinegateway_nginx";
#else
        "weline-gateway";
    const char *data_plane_name = "weline-gateway-nginx";
#endif
    struct passwd *password;
    struct group *group;
    uid_t local_controller_uid;
    gid_t local_controller_gid;
    gid_t local_data_plane_gid;
    if (controller_uid == NULL || controller_gid == NULL
        || data_plane_gid == NULL) return -1;
    password = getpwnam(controller_name);
    if (password == NULL || password->pw_uid == 0) return -1;
    local_controller_uid = password->pw_uid;
    group = getgrnam(controller_name);
    if (group == NULL || group->gr_gid == 0
        || password->pw_gid != group->gr_gid) return -1;
    local_controller_gid = group->gr_gid;
    password = getpwnam(data_plane_name);
    if (password == NULL || password->pw_uid == 0) return -1;
    group = getgrnam(data_plane_name);
    if (group == NULL || group->gr_gid == 0
        || password->pw_gid != group->gr_gid
        || local_controller_uid == password->pw_uid
        || local_controller_gid == group->gr_gid) return -1;
    local_data_plane_gid = group->gr_gid;
    *controller_uid = local_controller_uid;
    *controller_gid = local_controller_gid;
    *data_plane_gid = local_data_plane_gid;
    return 0;
}

static int wls_guardian_root_fixed_authority_valid(
    const struct wls_guardian_root_authority *authority,
    int test_platform,
    uid_t test_uid,
    gid_t test_gid
) {
    uid_t controller_uid = 0;
    gid_t controller_gid = 0;
    gid_t data_plane_gid = 0;
    int root_valid = 0;
    int parent_valid = 0;
    if (authority == NULL
        || authority->uid > UINT_MAX || authority->gid > UINT_MAX
        || authority->parent_uid > UINT_MAX
        || authority->parent_gid > UINT_MAX
        || authority->mode > 0777ULL || authority->parent_mode > 0777ULL
        || (authority->parent_mode & 0700ULL) != 0700ULL
        || (authority->parent_mode & 0022ULL) != 0ULL
        || (authority->present
            && ((authority->mode & 0700ULL) != 0700ULL
                || (authority->mode & 0022ULL) != 0ULL))) return 0;
    if (test_platform) {
        root_valid = !authority->present
            || (authority->uid == (unsigned long long)test_uid
                && authority->gid == (unsigned long long)test_gid);
        parent_valid = authority->parent_uid == (unsigned long long)test_uid
            && authority->parent_gid == (unsigned long long)test_gid;
        return root_valid && parent_valid;
    }
    if (wls_guardian_service_identity_values(
            &controller_uid, &controller_gid, &data_plane_gid
        ) != 0) return 0;
    if (strcmp(authority->authority_profile, "controller-private-v2") == 0) {
        root_valid = authority->present
            && authority->uid == (unsigned long long)controller_uid
            && authority->gid == (unsigned long long)controller_gid
            && authority->mode == 0700ULL;
    } else if (strcmp(
            authority->authority_profile,
            "controller-snapshot-candidates-private-v2"
        ) == 0) {
        root_valid = !authority->present
            || (authority->uid == (unsigned long long)controller_uid
                && authority->gid == (unsigned long long)controller_gid
                && authority->mode == 0700ULL);
    } else if (strcmp(
            authority->authority_profile, "root-controller-read-v2"
        ) == 0) {
        root_valid = authority->present && authority->uid == 0ULL
            && authority->gid == (unsigned long long)controller_gid
            && authority->mode == 0750ULL;
    } else if (strcmp(
            authority->authority_profile,
            "controller-data-plane-search-v2"
        ) == 0) {
        root_valid = !authority->present
            || (authority->uid == (unsigned long long)controller_uid
                && authority->gid == (unsigned long long)data_plane_gid
                && authority->mode == 0710ULL);
    } else if (strcmp(
            authority->authority_profile, "root-data-plane-search-v2"
        ) == 0) {
        root_valid = !authority->present
            || (authority->uid == 0ULL
                && authority->gid == (unsigned long long)data_plane_gid
                && authority->mode == 0710ULL);
    } else if (strcmp(
            authority->authority_profile, "controller-runtime-child-v2"
        ) == 0) {
        root_valid = !authority->present
            || (authority->uid == (unsigned long long)controller_uid
                && (authority->gid == (unsigned long long)controller_gid
                    || authority->gid == (unsigned long long)data_plane_gid)
                && (authority->mode == 0700ULL
                    || authority->mode == 0750ULL));
    }
    if (strcmp(
            authority->parent_authority_profile,
            "host-root-controller-search-v2"
        ) == 0) {
        parent_valid = authority->parent_uid == 0ULL
            && authority->parent_gid == (unsigned long long)controller_gid
            && authority->parent_mode == 0751ULL;
    } else if (strcmp(
            authority->parent_authority_profile,
            "controller-data-plane-runtime-v2"
        ) == 0) {
        parent_valid = authority->parent_uid
                == (unsigned long long)controller_uid
            && authority->parent_gid == (unsigned long long)data_plane_gid
            && authority->parent_mode == 0750ULL;
    }
    return root_valid && parent_valid;
}

static int wls_guardian_split_category_fields(
    char *line,
    char *fields[21]
) {
    char *cursor;
    size_t count = 0U;
    if (line == NULL || fields == NULL
        || strncmp(line, "category=", 9U) != 0) return -1;
    cursor = line + 9U;
    fields[count++] = cursor;
    while (*cursor != '\0') {
        if (*cursor == '\t') {
            *cursor = '\0';
            if (count >= 21U) return -1;
            fields[count++] = cursor + 1U;
        }
        ++cursor;
    }
    if (count != 21U) return -1;
    for (count = 0U; count < 21U; ++count) {
        if (fields[count][0] == '\0') return -1;
    }
    return 0;
}

static int wls_guardian_parse_root_authority_line(
    const char *line,
    const struct wls_guardian_root_contract *contract,
    int test_platform,
    uid_t test_uid,
    gid_t test_gid,
    struct wls_guardian_root_authority *authority
) {
    char *copy = NULL;
    char *fields[21];
    char root_digest[65];
    char parent_digest[65];
    size_t length;
    int result = -1;
    memset(fields, 0, sizeof(fields));
    sodium_memzero(root_digest, sizeof(root_digest));
    sodium_memzero(parent_digest, sizeof(parent_digest));
    if (line == NULL || contract == NULL || authority == NULL) return -1;
    memset(authority, 0, sizeof(*authority));
    length = strlen(line);
    if (length == 0U
        || length > 2U * WLS_GUARDIAN_AUTHORITY_SDDL_B64_MAX_BYTES + 2048U) {
        goto cleanup;
    }
    copy = malloc(length + 1U);
    if (copy == NULL) goto cleanup;
    memcpy(copy, line, length + 1U);
    if (wls_guardian_split_category_fields(copy, fields) != 0
        || strcmp(fields[0], contract->category) != 0
        || strcmp(fields[1], contract->policy) != 0
        || strcmp(fields[2], contract->authority_profile) != 0
        || strcmp(fields[3], contract->authority_policy) != 0
        || (strcmp(fields[4], "0") != 0 && strcmp(fields[4], "1") != 0)
        || strcmp(fields[12], contract->parent_authority_profile) != 0
        || strcmp(fields[13], contract->parent_authority_policy) != 0
        || strlen(fields[10]) != 64U || !wls_is_hex_text(fields[10], 64U)
        || strcmp(fields[11], "-") != 0
        || strlen(fields[19]) != 64U || !wls_is_hex_text(fields[19], 64U)
        || strcmp(fields[20], "-") != 0) goto cleanup;
    memcpy(authority->category, fields[0], strlen(fields[0]) + 1U);
    memcpy(authority->policy, fields[1], strlen(fields[1]) + 1U);
    memcpy(
        authority->authority_profile,
        fields[2],
        strlen(fields[2]) + 1U
    );
    memcpy(
        authority->authority_policy,
        fields[3],
        strlen(fields[3]) + 1U
    );
    authority->present = strcmp(fields[4], "1") == 0;
    memcpy(
        authority->authority_sha256,
        fields[10],
        sizeof(authority->authority_sha256)
    );
    memcpy(
        authority->parent_authority_profile,
        fields[12],
        strlen(fields[12]) + 1U
    );
    memcpy(
        authority->parent_authority_policy,
        fields[13],
        strlen(fields[13]) + 1U
    );
    memcpy(
        authority->parent_authority_sha256,
        fields[19],
        sizeof(authority->parent_authority_sha256)
    );
    if ((authority->present
            && (wls_guardian_unsigned_token(
                    fields[5], ULLONG_MAX, &authority->device
                ) != 0
                || wls_guardian_unsigned_token(
                    fields[6], ULLONG_MAX, &authority->inode
                ) != 0))
        || (!authority->present
            && (strcmp(fields[5], "-") != 0
                || strcmp(fields[6], "-") != 0))
        || wls_guardian_unsigned_token(
            fields[7], UINT_MAX, &authority->uid
        ) != 0
        || wls_guardian_unsigned_token(
            fields[8], UINT_MAX, &authority->gid
        ) != 0
        || wls_guardian_unsigned_token(
            fields[9], 0777ULL, &authority->mode
        ) != 0
        || wls_guardian_unsigned_token(
            fields[14], ULLONG_MAX, &authority->parent_device
        ) != 0
        || wls_guardian_unsigned_token(
            fields[15], ULLONG_MAX, &authority->parent_inode
        ) != 0
        || wls_guardian_unsigned_token(
            fields[16], UINT_MAX, &authority->parent_uid
        ) != 0
        || wls_guardian_unsigned_token(
            fields[17], UINT_MAX, &authority->parent_gid
        ) != 0
        || wls_guardian_unsigned_token(
            fields[18], 0777ULL, &authority->parent_mode
        ) != 0
        || (contract->preserve_identity && !authority->present)
        || (!authority->present
            && (authority->uid != 0ULL || authority->gid != 0ULL
                || authority->mode != 0ULL))
        || !wls_guardian_root_fixed_authority_valid(
            authority, test_platform, test_uid, test_gid
        )
        || wls_guardian_authority_digest(
            authority, 0, root_digest
        ) != 0
        || sodium_memcmp(
            root_digest, authority->authority_sha256, 64U
        ) != 0
        || wls_guardian_authority_digest(
            authority, 1, parent_digest
        ) != 0
        || sodium_memcmp(
            parent_digest, authority->parent_authority_sha256, 64U
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (copy != NULL) {
        sodium_memzero(copy, length);
        free(copy);
    }
    sodium_memzero(fields, sizeof(fields));
    sodium_memzero(root_digest, sizeof(root_digest));
    sodium_memzero(parent_digest, sizeof(parent_digest));
    if (result != 0 && authority != NULL) {
        sodium_memzero(authority, sizeof(*authority));
    }
    return result;
}

static int wls_guardian_same_parent_authority(
    const struct wls_guardian_root_authority *left,
    const struct wls_guardian_root_authority *right
) {
    return left != NULL && right != NULL
        && strcmp(
            left->parent_authority_profile,
            right->parent_authority_profile
        ) == 0
        && strcmp(
            left->parent_authority_policy,
            right->parent_authority_policy
        ) == 0
        && left->parent_device == right->parent_device
        && left->parent_inode == right->parent_inode
        && left->parent_uid == right->parent_uid
        && left->parent_gid == right->parent_gid
        && left->parent_mode == right->parent_mode
        && sodium_memcmp(
            left->parent_authority_sha256,
            right->parent_authority_sha256,
            64U
        ) == 0;
}

static int wls_guardian_recovery_inventory_load_path(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    const char *document_path,
    struct wls_guardian_recovery_inventory *inventory
) {
    char path[PATH_MAX];
    char manifest_path[PATH_MAX];
    char host_id[33];
    char nonce[33];
    char journal[65];
    char manifest[65];
    char policy_digest[65];
    char category_count_text[3];
    char entry_count_text[6];
    char digest[65];
    char signature[65];
    char previous[768];
    char line[768];
    char category[32];
    char policy[10];
    char leaf_hex[511];
    char kind[2];
    char closure[65];
    unsigned char decoded_leaf[256];
    char *contents = NULL;
    char *signature_line;
    char *cursor;
    char *line_end;
    char *end = NULL;
    size_t length = 0U;
    size_t unsigned_length;
    size_t decoded_length = 0U;
    unsigned long parsed_count = 0UL;
    struct stat home_status;
    size_t index;
    int test_platform;
    int consumed = 0;
    int header_fields;
    int result = -1;
    sodium_memzero(path, sizeof(path));
    sodium_memzero(manifest_path, sizeof(manifest_path));
    sodium_memzero(host_id, sizeof(host_id));
    sodium_memzero(nonce, sizeof(nonce));
    sodium_memzero(journal, sizeof(journal));
    sodium_memzero(manifest, sizeof(manifest));
    sodium_memzero(policy_digest, sizeof(policy_digest));
    sodium_memzero(category_count_text, sizeof(category_count_text));
    sodium_memzero(entry_count_text, sizeof(entry_count_text));
    sodium_memzero(digest, sizeof(digest));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(previous, sizeof(previous));
    if (home == NULL || backup == NULL || request == NULL
        || inventory == NULL || (document_path != NULL
            && (document_path[0] != '/'
                || strlen(document_path) >= sizeof(path)))) {
        return -1;
    }
    memset(inventory, 0, sizeof(*inventory));
    if (document_path != NULL) {
        memcpy(path, document_path, strlen(document_path) + 1U);
    }
    if ((document_path == NULL
            && wls_join(
                path,
                sizeof(path),
                backup,
                "guardian-recovery.inventory"
            ) != 0)
        || wls_join(
            manifest_path,
            sizeof(manifest_path),
            backup,
            "derived-state.manifest.json"
        ) != 0
        || wls_guardian_read_bounded_regular(
            path,
            WLS_GUARDIAN_INVENTORY_MAX_BYTES,
            0600,
            &contents,
            &length
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)contents,
            length,
            digest
        ) != 0
        || sodium_memcmp(
            digest,
            request->recovery_inventory_sha256,
            64U
        ) != 0
        || wls_guardian_verify_regular_digest(
            manifest_path,
            request->derived_manifest_sha256,
            0600,
            (off_t)WLS_GUARDIAN_INVENTORY_MAX_BYTES
        ) != 0) goto cleanup;
    signature_line = NULL;
    cursor = contents;
    while ((line_end = strstr(cursor, "\nsignature=")) != NULL) {
        signature_line = line_end + 1U;
        cursor = signature_line + 1U;
    }
    if (signature_line == NULL
        || signature_line == contents
        || sscanf(signature_line, "signature=%64[0-9a-f]\n%n", signature, &consumed) != 1
        || consumed <= 0
        || (size_t)(signature_line - contents) + (size_t)consumed != length
        || wls_guardian_verify_signed_document(
            home,
            contents,
            signature_line,
            signature
        ) != 0) goto cleanup;
    unsigned_length = (size_t)(signature_line - contents);
    header_fields = sscanf(
        contents,
        "WLS-GUARDIAN-RECOVERY-INVENTORY/2\n"
        "host_id=%32[0-9a-f]\n"
        "nonce=%32[0-9a-f]\n"
        "journal_sha256=%64[0-9a-f]\n"
        "derived_manifest_sha256=%64[0-9a-f]\n"
        "derived_policy_sha256=%64[0-9a-f]\n"
        "category_count=%2[0-9]\n"
        "entry_count=%5[0-9]\n%n",
        host_id,
        nonce,
        journal,
        manifest,
        policy_digest,
        category_count_text,
        entry_count_text,
        &consumed
    );
    errno = 0;
    parsed_count = strtoul(entry_count_text, &end, 10);
    test_platform = strcmp(request->platform_kind, "test-session") == 0;
    if (header_fields != 7 || consumed <= 0
        || (size_t)consumed > unsigned_length
        || strcmp(category_count_text, "9") != 0
        || errno != 0 || end == entry_count_text || *end != '\0'
        || (entry_count_text[0] == '0' && entry_count_text[1] != '\0')
        || parsed_count > WLS_GUARDIAN_INVENTORY_MAX_ENTRIES
        || lstat(home, &home_status) != 0
        || !S_ISDIR(home_status.st_mode) || S_ISLNK(home_status.st_mode)
        || sodium_memcmp(host_id, request->host_id, 32U) != 0
        || sodium_memcmp(nonce, request->nonce, 32U) != 0
        || sodium_memcmp(journal, request->journal_sha256, 64U) != 0
        || sodium_memcmp(
            manifest,
            request->derived_manifest_sha256,
            64U
        ) != 0
        || sodium_memcmp(
            policy_digest,
            request->derived_policy_sha256,
            64U
        ) != 0) goto cleanup;
    if (parsed_count > 0U) {
        inventory->entries = calloc(
            (size_t)parsed_count,
            sizeof(*inventory->entries)
        );
        if (inventory->entries == NULL) goto cleanup;
    }
    cursor = contents + consumed;
    for (index = 0U; index < WLS_GUARDIAN_DERIVED_CATEGORY_COUNT; ++index) {
        char *root_line = NULL;
        size_t line_length;
        line_end = memchr(cursor, '\n', (size_t)(signature_line - cursor));
        if (line_end == NULL) goto cleanup;
        line_length = (size_t)(line_end - cursor);
        if (line_length == 0U
            || line_length
                > 2U * WLS_GUARDIAN_AUTHORITY_SDDL_B64_MAX_BYTES + 2048U
            || memchr(cursor, '\0', line_length) != NULL) goto cleanup;
        root_line = malloc(line_length + 1U);
        if (root_line == NULL) goto cleanup;
        memcpy(root_line, cursor, line_length);
        root_line[line_length] = '\0';
        if (wls_guardian_parse_root_authority_line(
                root_line,
                &wls_guardian_root_contracts[index],
                test_platform,
                home_status.st_uid,
                home_status.st_gid,
                &inventory->roots[index]
            ) != 0) {
            sodium_memzero(root_line, line_length);
            free(root_line);
            goto cleanup;
        }
        sodium_memzero(root_line, line_length);
        free(root_line);
        cursor = line_end + 1U;
    }
    for (index = 1U; index < 4U; ++index) {
        if (!wls_guardian_same_parent_authority(
                &inventory->roots[0], &inventory->roots[index]
            )) goto cleanup;
    }
    for (index = 5U; index < WLS_GUARDIAN_DERIVED_CATEGORY_COUNT; ++index) {
        if (!wls_guardian_same_parent_authority(
                &inventory->roots[4], &inventory->roots[index]
            )) goto cleanup;
    }
    for (index = 0U; index < (size_t)parsed_count; ++index) {
        size_t line_length;
        sodium_memzero(line, sizeof(line));
        sodium_memzero(category, sizeof(category));
        sodium_memzero(policy, sizeof(policy));
        sodium_memzero(leaf_hex, sizeof(leaf_hex));
        sodium_memzero(kind, sizeof(kind));
        sodium_memzero(closure, sizeof(closure));
        sodium_memzero(decoded_leaf, sizeof(decoded_leaf));
        decoded_length = 0U;
        line_end = memchr(cursor, '\n', (size_t)(signature_line - cursor));
        if (line_end == NULL) goto cleanup;
        line_length = (size_t)(line_end - cursor);
        if (line_length == 0U || line_length >= sizeof(line)) goto cleanup;
        memcpy(line, cursor, line_length);
        line[line_length] = '\0';
        consumed = 0;
        if (sscanf(
                line,
                "entry=%31[a-z0-9-]\t%9[a-z]\t%510[0-9a-f]\t%1[df]\t%64[0-9a-f]%n",
                category,
                policy,
                leaf_hex,
                kind,
                closure,
                &consumed
            ) != 5
            || consumed != (int)line_length
            || !wls_guardian_inventory_policy_valid(category, policy)
            || strlen(leaf_hex) < 2U || strlen(leaf_hex) > 510U
            || (strlen(leaf_hex) & 1U) != 0U
            || strlen(closure) != 64U
            || (previous[0] != '\0' && strcmp(previous, line) >= 0)
            || sodium_hex2bin(
                decoded_leaf,
                sizeof(decoded_leaf) - 1U,
                leaf_hex,
                strlen(leaf_hex),
                NULL,
                &decoded_length,
                NULL
            ) != 0
            || decoded_length == 0U || decoded_length > 255U
            || memchr(decoded_leaf, '\0', decoded_length) != NULL
            || memchr(decoded_leaf, '/', decoded_length) != NULL
            || memchr(decoded_leaf, '\\', decoded_length) != NULL
            || (decoded_length == 1U && decoded_leaf[0] == '.')
            || (decoded_length == 2U && decoded_leaf[0] == '.'
                && decoded_leaf[1] == '.')) goto cleanup;
        memcpy(
            inventory->entries[index].category,
            category,
            strlen(category) + 1U
        );
        memcpy(
            inventory->entries[index].policy,
            policy,
            strlen(policy) + 1U
        );
        memcpy(
            inventory->entries[index].leaf,
            decoded_leaf,
            decoded_length
        );
        inventory->entries[index].leaf[decoded_length] = '\0';
        inventory->entries[index].kind = kind[0];
        memcpy(
            inventory->entries[index].closure_sha256,
            closure,
            65U
        );
        memcpy(previous, line, line_length + 1U);
        cursor = line_end + 1;
    }
    if (cursor != signature_line) goto cleanup;
    inventory->count = (size_t)parsed_count;
    result = 0;
cleanup:
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    if (result != 0 && inventory != NULL && inventory->entries != NULL) {
        sodium_memzero(
            inventory->entries,
            (size_t)parsed_count * sizeof(*inventory->entries)
        );
        free(inventory->entries);
        inventory->entries = NULL;
        inventory->count = 0U;
    }
    if (result != 0 && inventory != NULL) {
        sodium_memzero(inventory->roots, sizeof(inventory->roots));
    }
    sodium_memzero(path, sizeof(path));
    sodium_memzero(manifest_path, sizeof(manifest_path));
    sodium_memzero(host_id, sizeof(host_id));
    sodium_memzero(nonce, sizeof(nonce));
    sodium_memzero(journal, sizeof(journal));
    sodium_memzero(manifest, sizeof(manifest));
    sodium_memzero(policy_digest, sizeof(policy_digest));
    sodium_memzero(category_count_text, sizeof(category_count_text));
    sodium_memzero(entry_count_text, sizeof(entry_count_text));
    sodium_memzero(digest, sizeof(digest));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(previous, sizeof(previous));
    sodium_memzero(line, sizeof(line));
    sodium_memzero(category, sizeof(category));
    sodium_memzero(policy, sizeof(policy));
    sodium_memzero(leaf_hex, sizeof(leaf_hex));
    sodium_memzero(kind, sizeof(kind));
    sodium_memzero(closure, sizeof(closure));
    sodium_memzero(decoded_leaf, sizeof(decoded_leaf));
    return result;
}

static int wls_guardian_recovery_inventory_load(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_inventory *inventory
) {
    char path[PATH_MAX];
    int result = -1;
    sodium_memzero(path, sizeof(path));
    if (home != NULL && backup != NULL && request != NULL
        && inventory != NULL
        && wls_join(
            path,
            sizeof(path),
            backup,
            "guardian-recovery.inventory"
        ) == 0
        && wls_guardian_atomic_companions_absent(path) == 0) {
        result = wls_guardian_recovery_inventory_load_path(
            home, backup, request, path, inventory
        );
    }
    sodium_memzero(path, sizeof(path));
    return result;
}

static void wls_guardian_recovery_inventory_free(
    struct wls_guardian_recovery_inventory *inventory
) {
    if (inventory == NULL) return;
    if (inventory->entries != NULL) {
        sodium_memzero(
            inventory->entries,
            inventory->count * sizeof(*inventory->entries)
        );
        free(inventory->entries);
    }
    memset(inventory, 0, sizeof(*inventory));
}

static int wls_guardian_recovery_evidence_load(
    const char *home,
    const struct wls_guardian_transition_request *request,
    char backup[PATH_MAX],
    struct wls_guardian_recovery_inventory *inventory
) {
    char launcher[PATH_MAX];
    char live_launcher[PATH_MAX];
    char platform_definition[PATH_MAX];
    char platform_metadata[PATH_MAX];
    int backup_launcher_state;
    int live_launcher_state;
    int result = -1;
    sodium_memzero(launcher, sizeof(launcher));
    sodium_memzero(live_launcher, sizeof(live_launcher));
    sodium_memzero(platform_definition, sizeof(platform_definition));
    sodium_memzero(platform_metadata, sizeof(platform_metadata));
    if (home == NULL || request == NULL || backup == NULL || inventory == NULL
        || wls_guardian_backup_root(home, request->nonce, backup) != 0
        || wls_guardian_recovery_authorization_verify(
            home,
            backup,
            request
        ) != 0
        || wls_guardian_recovery_inventory_load(
            home,
            backup,
            request,
            inventory
        ) != 0
        || wls_join(launcher, sizeof(launcher), backup, "bin/launcher") != 0
        || wls_join(
            live_launcher,
            sizeof(live_launcher),
            home,
            "bin/wls-gateway-launcher"
        ) != 0
        || wls_join(
            platform_definition,
            sizeof(platform_definition),
            backup,
            "platform/definition.before"
        ) != 0
        || wls_join(
            platform_metadata,
            sizeof(platform_metadata),
            backup,
            "platform/metadata.before"
        ) != 0
        || wls_guardian_verify_regular_digest(
            platform_definition,
            request->platform_definition_sha256,
            0600,
            (off_t)1048576
        ) != 0
        || wls_guardian_verify_regular_digest(
            platform_metadata,
            request->platform_metadata_sha256,
            0600,
            (off_t)16384
        ) != 0) goto cleanup;
    backup_launcher_state = wls_guardian_regular_state(
        launcher,
        request->recovery_launcher_sha256,
        request->recovery_launcher_size,
        (mode_t)request->recovery_launcher_mode
    );
    live_launcher_state = wls_guardian_regular_state(
        live_launcher,
        request->recovery_launcher_sha256,
        request->recovery_launcher_size,
        (mode_t)request->recovery_launcher_mode
    );
    if (!((backup_launcher_state == 0 && live_launcher_state != 0)
        || (backup_launcher_state == 1 && live_launcher_state == 0)
        || (backup_launcher_state == 0 && live_launcher_state == 0
            && sodium_memcmp(
                request->recovery_launcher_sha256,
                request->candidate_launcher_sha256,
                64U
            ) == 0
            && request->recovery_launcher_size
                == request->candidate_launcher_size
            && request->recovery_launcher_mode
                == request->candidate_launcher_mode))) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (result != 0) {
        wls_guardian_recovery_inventory_free(inventory);
        if (backup != NULL) sodium_memzero(backup, PATH_MAX);
    }
    sodium_memzero(launcher, sizeof(launcher));
    sodium_memzero(live_launcher, sizeof(live_launcher));
    sodium_memzero(platform_definition, sizeof(platform_definition));
    sodium_memzero(platform_metadata, sizeof(platform_metadata));
    return result;
}

static int wls_guardian_digest_regular_record(
    const char *path,
    const struct stat *expected,
    char digest[65],
    unsigned long long *size
) {
    struct stat before;
    struct stat after;
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    unsigned char buffer[65536];
    ssize_t amount;
    int fd = -1;
    int result = -1;
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    sodium_memzero(buffer, sizeof(buffer));
    if (path == NULL || expected == NULL || digest == NULL || size == NULL) {
        return -1;
    }
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || fstat(fd, &before) != 0
        || !S_ISREG(before.st_mode) || before.st_nlink != 1
        || before.st_size < 0
        || (unsigned long long)before.st_size > 536870912ULL
        || !wls_launcher_same_file_state(expected, &before)
        || crypto_hash_sha256_init(&state) != 0) goto cleanup;
    for (;;) {
        amount = read(fd, buffer, sizeof(buffer));
        if (amount < 0 && errno == EINTR) continue;
        if (amount < 0) goto cleanup;
        if (amount == 0) break;
        if (crypto_hash_sha256_update(
                &state, buffer, (unsigned long long)amount
            ) != 0) goto cleanup;
    }
    if (fstat(fd, &after) != 0
        || !wls_launcher_same_file_state(&before, &after)
        || crypto_hash_sha256_final(&state, binary) != 0) goto cleanup;
    sodium_bin2hex(digest, 65U, binary, sizeof(binary));
    *size = (unsigned long long)after.st_size;
    result = wls_is_hex_text(digest, 64U) ? 0 : -1;
cleanup:
    if (fd >= 0) close(fd);
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    sodium_memzero(buffer, sizeof(buffer));
    return result;
}

static int wls_guardian_name_compare(const void *left, const void *right)
{
    const char *const *left_name = left;
    const char *const *right_name = right;
    return strcmp(*left_name, *right_name);
}

static void wls_guardian_name_list_free(char **names, size_t count)
{
    size_t index;
    if (names == NULL) return;
    for (index = 0U; index < count; ++index) {
        if (names[index] != NULL) {
            sodium_memzero(names[index], strlen(names[index]));
            free(names[index]);
        }
    }
    free(names);
}

static int wls_guardian_acl_free_fd(int fd, int test_platform);

struct wls_guardian_closure_record_value {
    char *relative;
    char *encoded;
    size_t encoded_length;
};

struct wls_guardian_closure_records {
    struct wls_guardian_closure_record_value *values;
    size_t count;
    size_t capacity;
    size_t path_bytes;
};

static void wls_guardian_closure_records_free(
    struct wls_guardian_closure_records *records
) {
    size_t index;
    if (records == NULL) return;
    for (index = 0U; index < records->count; ++index) {
        if (records->values[index].relative != NULL) {
            sodium_memzero(
                records->values[index].relative,
                strlen(records->values[index].relative)
            );
            free(records->values[index].relative);
        }
        if (records->values[index].encoded != NULL) {
            sodium_memzero(
                records->values[index].encoded,
                records->values[index].encoded_length
            );
            free(records->values[index].encoded);
        }
    }
    free(records->values);
    memset(records, 0, sizeof(*records));
}

static int wls_guardian_closure_record_compare(
    const void *left,
    const void *right
) {
    const struct wls_guardian_closure_record_value *left_record = left;
    const struct wls_guardian_closure_record_value *right_record = right;
    if (strcmp(left_record->relative, ".") == 0) {
        return strcmp(right_record->relative, ".") == 0 ? 0 : -1;
    }
    if (strcmp(right_record->relative, ".") == 0) return 1;
    return strcmp(left_record->relative, right_record->relative);
}

static int wls_guardian_closure_record(
    struct wls_guardian_closure_records *records,
    const char *path,
    const char *relative,
    const struct stat *status,
    int require_acl_free
) {
    static const char zeros[] =
        "0000000000000000000000000000000000000000000000000000000000000000";
    char file_digest[65];
    char *path_hex = NULL;
    char *record = NULL;
    char *relative_copy = NULL;
    struct stat opened;
    unsigned long long file_size = 0ULL;
    size_t relative_length = 0U;
    size_t record_capacity = 0U;
    int record_length;
    int metadata_fd = -1;
    int result = -1;
    sodium_memzero(file_digest, sizeof(file_digest));
    if (records == NULL || path == NULL || relative == NULL || status == NULL
        || (!S_ISDIR(status->st_mode) && !S_ISREG(status->st_mode))
        || (S_ISREG(status->st_mode) && status->st_nlink != 1)) goto cleanup;
    relative_length = strlen(relative);
    if (relative_length == 0U || relative_length >= PATH_MAX
        || records->count >= WLS_GUARDIAN_INVENTORY_MAX_ENTRIES
        || records->path_bytes
            > WLS_GUARDIAN_CLOSURE_PATH_MAX_BYTES - relative_length) {
        goto cleanup;
    }
    metadata_fd = open(
        path,
        O_RDONLY | O_CLOEXEC | O_NOFOLLOW
            | (S_ISDIR(status->st_mode) ? O_DIRECTORY : 0)
    );
    if (metadata_fd < 0 || fstat(metadata_fd, &opened) != 0
        || !wls_launcher_same_file_state(status, &opened)
        || (require_acl_free
            && wls_guardian_acl_free_fd(metadata_fd, 0) != 0)) goto cleanup;
    path_hex = malloc(relative_length * 2U + 1U);
    record_capacity = relative_length * 2U + 320U;
    record = malloc(record_capacity);
    relative_copy = malloc(relative_length + 1U);
    if (path_hex == NULL || record == NULL || relative_copy == NULL
        || sodium_bin2hex(
            path_hex,
            relative_length * 2U + 1U,
            (const unsigned char *)relative,
            relative_length
        ) == NULL) goto cleanup;
    if (S_ISREG(status->st_mode)
        && wls_guardian_digest_regular_record(
            path, status, file_digest, &file_size
        ) != 0) goto cleanup;
    if (fstat(metadata_fd, &opened) != 0
        || !wls_launcher_same_file_state(status, &opened)
        || (require_acl_free
            && wls_guardian_acl_free_fd(metadata_fd, 0) != 0)) goto cleanup;
    record_length = snprintf(
        record,
        record_capacity,
        "record=%s\t%c\t%u\t%llu\t%llu\t%llu\t%s\n",
        path_hex,
        S_ISDIR(status->st_mode) ? 'd' : 'f',
        (unsigned int)(status->st_mode & 0777),
        (unsigned long long)status->st_uid,
        (unsigned long long)status->st_gid,
        S_ISDIR(status->st_mode) ? 0ULL : file_size,
        S_ISDIR(status->st_mode) ? zeros : file_digest
    );
    if (record_length <= 0 || (size_t)record_length >= record_capacity) {
        goto cleanup;
    }
    if (records->count == records->capacity) {
        size_t next = records->capacity == 0U
            ? 16U
            : records->capacity * 2U;
        struct wls_guardian_closure_record_value *grown;
        if (next > WLS_GUARDIAN_INVENTORY_MAX_ENTRIES) {
            next = WLS_GUARDIAN_INVENTORY_MAX_ENTRIES;
        }
        grown = realloc(records->values, next * sizeof(*records->values));
        if (grown == NULL) goto cleanup;
        records->values = grown;
        records->capacity = next;
    }
    memcpy(relative_copy, relative, relative_length + 1U);
    records->values[records->count].relative = relative_copy;
    records->values[records->count].encoded = record;
    records->values[records->count].encoded_length = (size_t)record_length;
    relative_copy = NULL;
    record = NULL;
    ++records->count;
    records->path_bytes += relative_length;
    result = 0;
cleanup:
    if (metadata_fd >= 0) close(metadata_fd);
    sodium_memzero(file_digest, sizeof(file_digest));
    if (path_hex != NULL) {
        sodium_memzero(path_hex, relative_length * 2U + 1U);
        free(path_hex);
    }
    if (record != NULL) {
        sodium_memzero(record, record_capacity);
        free(record);
    }
    if (relative_copy != NULL) {
        sodium_memzero(relative_copy, relative_length);
        free(relative_copy);
    }
    return result;
}

static int wls_guardian_closure_walk(
    const char *path,
    const char *relative,
    unsigned int depth,
    struct wls_guardian_closure_records *records,
    int require_acl_free
) {
    struct stat before;
    struct stat opened;
    struct stat after;
    DIR *directory = NULL;
    struct dirent *entry;
    char **names = NULL;
    size_t count = 0U;
    size_t capacity = 0U;
    size_t index;
    int result = -1;
    if (path == NULL || relative == NULL || records == NULL
        || depth > WLS_GUARDIAN_DERIVED_MAX_DEPTH
        || lstat(path, &before) != 0 || S_ISLNK(before.st_mode)
        || (!S_ISDIR(before.st_mode) && !S_ISREG(before.st_mode))
        || wls_guardian_closure_record(
            records, path, relative, &before, require_acl_free
        ) != 0) goto cleanup;
    if (S_ISREG(before.st_mode)) {
        result = 0;
        goto cleanup;
    }
    directory = opendir(path);
    if (directory == NULL || fstat(dirfd(directory), &opened) != 0
        || !wls_launcher_same_file_state(&before, &opened)
        || (require_acl_free
            && wls_guardian_acl_free_fd(
                dirfd(directory), 0
            ) != 0)) goto cleanup;
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        char *copy;
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (count >= WLS_GUARDIAN_INVENTORY_MAX_ENTRIES) goto cleanup;
        if (count == capacity) {
            size_t next = capacity == 0U ? 16U : capacity * 2U;
            char **grown;
            if (next > WLS_GUARDIAN_INVENTORY_MAX_ENTRIES) {
                next = WLS_GUARDIAN_INVENTORY_MAX_ENTRIES;
            }
            grown = realloc(names, next * sizeof(*names));
            if (grown == NULL) goto cleanup;
            names = grown;
            capacity = next;
        }
        {
            size_t copy_length = strlen(entry->d_name);
            copy = malloc(copy_length + 1U);
            if (copy != NULL) {
                memcpy(copy, entry->d_name, copy_length + 1U);
            }
        }
        if (copy == NULL || copy[0] == '\0' || strchr(copy, '/') != NULL
            || strchr(copy, '\\') != NULL) {
            free(copy);
            goto cleanup;
        }
        names[count++] = copy;
    }
    if (errno != 0) goto cleanup;
    if (count > 1U) {
        qsort(names, count, sizeof(*names), wls_guardian_name_compare);
    }
    for (index = 0U; index < count; ++index) {
        char child_path[PATH_MAX];
        char child_relative[PATH_MAX];
        int relative_length;
        if (wls_join(child_path, sizeof(child_path), path, names[index]) != 0) {
            goto cleanup;
        }
        relative_length = strcmp(relative, ".") == 0
            ? snprintf(child_relative, sizeof(child_relative), "%s", names[index])
            : snprintf(
                child_relative,
                sizeof(child_relative),
                "%s/%s",
                relative,
                names[index]
            );
        if (relative_length <= 0
            || relative_length >= (int)sizeof(child_relative)
            || wls_guardian_closure_walk(
                child_path,
                child_relative,
                depth + 1U,
                records,
                require_acl_free
            ) != 0) goto cleanup;
    }
    if (fstat(dirfd(directory), &opened) != 0
        || lstat(path, &after) != 0
        || !wls_launcher_same_file_state(&before, &opened)
        || !wls_launcher_same_file_state(&opened, &after)
        || (require_acl_free
            && wls_guardian_acl_free_fd(
                dirfd(directory), 0
            ) != 0)) goto cleanup;
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    wls_guardian_name_list_free(names, count);
    return result;
}

static int wls_guardian_closure_digest(
    const char *path,
    char expected_kind,
    const char *expected_digest
) {
    crypto_hash_sha256_state state;
    unsigned char binary[crypto_hash_sha256_BYTES];
    char actual[65];
    struct stat status;
    struct wls_guardian_closure_records records;
    size_t index;
    int result = -1;
    memset(&records, 0, sizeof(records));
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    sodium_memzero(actual, sizeof(actual));
    if (path == NULL || expected_digest == NULL
        || (expected_kind != 'd' && expected_kind != 'f')
        || !wls_is_hex_text(expected_digest, 64U)
        || lstat(path, &status) != 0 || S_ISLNK(status.st_mode)
        || (expected_kind == 'd' && !S_ISDIR(status.st_mode))
        || (expected_kind == 'f' && !S_ISREG(status.st_mode))
        || wls_guardian_closure_walk(
            path, ".", 0U, &records, 1
        ) != 0 || records.count == 0U
        || strcmp(records.values[0].relative, ".") != 0) goto cleanup;
    if (records.count > 1U) {
        qsort(
            records.values,
            records.count,
            sizeof(*records.values),
            wls_guardian_closure_record_compare
        );
    }
    if (strcmp(records.values[0].relative, ".") != 0
        || crypto_hash_sha256_init(&state) != 0) goto cleanup;
    for (index = 0U; index < records.count; ++index) {
        if (crypto_hash_sha256_update(
                &state,
                (const unsigned char *)records.values[index].encoded,
                (unsigned long long)records.values[index].encoded_length
            ) != 0) goto cleanup;
    }
    if (crypto_hash_sha256_final(&state, binary) != 0) goto cleanup;
    sodium_bin2hex(actual, sizeof(actual), binary, sizeof(binary));
    result = sodium_memcmp(actual, expected_digest, 64U) == 0 ? 0 : -1;
cleanup:
    wls_guardian_closure_records_free(&records);
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(binary, sizeof(binary));
    sodium_memzero(actual, sizeof(actual));
    return result;
}

static int wls_guardian_slot_contract_at(
    const char *slot_path,
    char expected_slot,
    const char *expected_runtime_generation,
    const char *expected_ca_sha256
) {
    static const char *required_components[] = {
        "bin/wls-gateway-broker",
        "bin/wls-gateway-launcher",
        "bin/php",
        "bin/nginx",
        "app/controller.php",
        "share/ca-bundle.pem",
    };
    struct stat slot_identity;
    struct stat release_identity;
    struct stat release_manifest_identity;
    struct stat release_signature_identity;
    struct stat installed_manifest_identity;
    struct stat reopened_identity;
    struct wls_launcher_json_field release_components = {0};
    struct wls_launcher_json_field installed_components = {0};
    unsigned char *release_manifest = NULL;
    unsigned char *release_signature = NULL;
    unsigned char *installed_manifest = NULL;
    size_t release_manifest_length = 0U;
    size_t release_signature_length = 0U;
    size_t installed_manifest_length = 0U;
    char runtime_generation[65];
    char ca_digest[65];
    unsigned long long ca_size = 0ULL;
    unsigned long long ca_mode = 0ULL;
    int slot_fd = -1;
    int release_fd = -1;
    int reopened_slot_fd = -1;
    size_t index;
    int result = -1;
    sodium_memzero(runtime_generation, sizeof(runtime_generation));
    sodium_memzero(ca_digest, sizeof(ca_digest));
    if (slot_path == NULL || slot_path[0] != '/'
        || (expected_slot != 'A' && expected_slot != 'B')
        || !wls_is_hex_text(expected_runtime_generation, 64U)
        || !wls_is_hex_text(expected_ca_sha256, 64U)) goto cleanup;
    slot_fd = open(
        slot_path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            slot_fd, 0755ULL, &slot_identity
        ) != 0) goto cleanup;
    release_fd = openat(
        slot_fd, "release", O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            release_fd, 0755ULL, &release_identity
        ) != 0
        || wls_launcher_read_regular_at(
            release_fd, "manifest.json", WLS_MAX_MANIFEST,
            &release_manifest, &release_manifest_length,
            &release_manifest_identity
        ) != 0
        || wls_launcher_read_regular_at(
            release_fd, "manifest.sig", WLS_SIGNATURE_TEXT,
            &release_signature, &release_signature_length,
            &release_signature_identity
        ) != 0
        || wls_launcher_read_regular_at(
            slot_fd, "manifest.json", WLS_MAX_MANIFEST,
            &installed_manifest, &installed_manifest_length,
            &installed_manifest_identity
        ) != 0
        || (unsigned long long)(installed_manifest_identity.st_mode & 0777)
            != 0444ULL
        || wls_launcher_verify_release_signature(
            release_manifest,
            release_manifest_length,
            release_signature,
            release_signature_length
        ) != 0
        || wls_launcher_manifest_contract(
            release_manifest,
            release_manifest_length,
            '\0',
            &release_components,
            runtime_generation
        ) != 0
        || wls_launcher_manifest_contract(
            installed_manifest,
            installed_manifest_length,
            expected_slot,
            &installed_components,
            runtime_generation
        ) != 0
        || sodium_memcmp(
            runtime_generation, expected_runtime_generation, 64U
        ) != 0
        || wls_launcher_installed_leaf_proof(
            &installed_components,
            "release/manifest.json",
            release_manifest,
            release_manifest_length,
            &release_manifest_identity
        ) != 0
        || wls_launcher_installed_leaf_proof(
            &installed_components,
            "release/manifest.sig",
            release_signature,
            release_signature_length,
            &release_signature_identity
        ) != 0) goto cleanup;
    for (index = 0U;
        index < sizeof(required_components) / sizeof(required_components[0]);
        ++index) {
        if (wls_launcher_component_proof(
                slot_fd,
                &release_components,
                &installed_components,
                required_components[index]
            ) != 0) goto cleanup;
    }
    if (wls_launcher_component_definition(
            &installed_components,
            "share/ca-bundle.pem",
            ca_digest,
            &ca_size,
            &ca_mode
        ) != 0 || ca_size == 0ULL || ca_size > 4194304ULL
        || ca_mode != 0444ULL
        || sodium_memcmp(ca_digest, expected_ca_sha256, 64U) != 0
        || wls_launcher_revalidate_regular_at(
            release_fd,
            "manifest.json",
            WLS_MAX_MANIFEST,
            release_manifest,
            release_manifest_length,
            &release_manifest_identity
        ) != 0
        || wls_launcher_revalidate_regular_at(
            release_fd,
            "manifest.sig",
            WLS_SIGNATURE_TEXT,
            release_signature,
            release_signature_length,
            &release_signature_identity
        ) != 0
        || wls_launcher_revalidate_regular_at(
            slot_fd,
            "manifest.json",
            WLS_MAX_MANIFEST,
            installed_manifest,
            installed_manifest_length,
            &installed_manifest_identity
        ) != 0) goto cleanup;
    reopened_slot_fd = open(
        slot_path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (wls_launcher_exact_directory_fd(
            reopened_slot_fd, 0755ULL, &reopened_identity
        ) != 0
        || !wls_launcher_same_file_state(
            &slot_identity, &reopened_identity
        )) goto cleanup;
    result = 0;
cleanup:
    if (reopened_slot_fd >= 0) close(reopened_slot_fd);
    if (release_fd >= 0) close(release_fd);
    if (slot_fd >= 0) close(slot_fd);
    if (release_manifest != NULL) {
        sodium_memzero(release_manifest, release_manifest_length);
        free(release_manifest);
    }
    if (release_signature != NULL) {
        sodium_memzero(release_signature, release_signature_length);
        free(release_signature);
    }
    if (installed_manifest != NULL) {
        sodium_memzero(installed_manifest, installed_manifest_length);
        free(installed_manifest);
    }
    sodium_memzero(runtime_generation, sizeof(runtime_generation));
    sodium_memzero(ca_digest, sizeof(ca_digest));
    return result;
}

static int wls_guardian_directory_at_path(
    const char *path,
    mode_t exact_mode,
    int require_exact_mode,
    int create
) {
    char parent_path[PATH_MAX];
    char *slash;
    struct stat parent;
    struct stat status;
    int parent_fd = -1;
    int result = -1;
    sodium_memzero(parent_path, sizeof(parent_path));
    if (path == NULL || path[0] != '/' || strlen(path) >= sizeof(parent_path)) {
        return -1;
    }
    if (lstat(path, &status) != 0) {
        if (errno != ENOENT || !create) goto cleanup;
        memcpy(parent_path, path, strlen(path) + 1U);
        slash = strrchr(parent_path, '/');
        if (slash == NULL || slash == parent_path) goto cleanup;
        *slash = '\0';
        if (lstat(parent_path, &parent) != 0
            || !S_ISDIR(parent.st_mode) || S_ISLNK(parent.st_mode)
            || parent.st_uid != geteuid() || (parent.st_mode & 0022) != 0) {
            goto cleanup;
        }
        parent_fd = open(
            parent_path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
        );
        if (parent_fd < 0 || mkdir(path, exact_mode) != 0
            || fsync(parent_fd) != 0 || lstat(path, &status) != 0) goto cleanup;
    }
    if (!S_ISDIR(status.st_mode) || S_ISLNK(status.st_mode)
        || status.st_uid != geteuid() || (status.st_mode & 0022) != 0
        || (require_exact_mode && (status.st_mode & 0777) != exact_mode)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    sodium_memzero(parent_path, sizeof(parent_path));
    return result;
}

static int wls_guardian_acl_free_fd(int fd, int test_platform)
{
    if (fd < 0) return -1;
    (void)test_platform;
#if defined(__linux__)
    {
        static const char *names[] = {
            "system.posix_acl_access",
            "system.posix_acl_default"
        };
        size_t index;
        for (index = 0U; index < sizeof(names) / sizeof(names[0]); ++index) {
            ssize_t amount;
            errno = 0;
            amount = fgetxattr(fd, names[index], NULL, 0U);
            if (amount >= 0
                || (errno != ENODATA && errno != EOPNOTSUPP)) return -1;
        }
    }
    return 0;
#elif defined(__APPLE__)
    {
        acl_t acl;
        acl_entry_t entry;
        int state;
        int saved_errno;
        errno = 0;
        acl = acl_get_fd_np(fd, ACL_TYPE_EXTENDED);
        if (acl == NULL) return errno == ENOENT ? 0 : -1;
        state = acl_get_entry(acl, ACL_FIRST_ENTRY, &entry);
        saved_errno = errno;
        if (acl_free(acl) != 0) return -1;
        errno = saved_errno;
        return state == -1 && saved_errno == EINVAL ? 0 : -1;
    }
#else
    return -1;
#endif
}

static int wls_guardian_acl_clear_fd(int fd, int test_platform)
{
    if (fd < 0) return -1;
#if defined(__linux__)
    {
        static const char *names[] = {
            "system.posix_acl_access",
            "system.posix_acl_default"
        };
        size_t index;
        for (index = 0U; index < sizeof(names) / sizeof(names[0]); ++index) {
            if (fremovexattr(fd, names[index]) != 0
                && errno != ENODATA && errno != EOPNOTSUPP) return -1;
        }
    }
#elif defined(__APPLE__)
    {
        acl_t empty_acl;
        int set_result;
        int saved_errno;
        empty_acl = acl_init(0);
        if (empty_acl == NULL) return -1;
        errno = 0;
        set_result = acl_set_fd_np(fd, empty_acl, ACL_TYPE_EXTENDED);
        saved_errno = errno;
        if (acl_free(empty_acl) != 0) return -1;
        if (set_result != 0 && saved_errno != ENOENT) {
            errno = saved_errno;
            return -1;
        }
    }
#else
    return -1;
#endif
    return wls_guardian_acl_free_fd(fd, test_platform);
}

static int wls_guardian_authority_stat_matches(
    const struct stat *status,
    const struct wls_guardian_root_authority *authority,
    int parent,
    int require_identity
) {
    unsigned long long uid;
    unsigned long long gid;
    unsigned long long mode;
    unsigned long long device;
    unsigned long long inode;
    if (status == NULL || authority == NULL
        || !S_ISDIR(status->st_mode) || S_ISLNK(status->st_mode)) return 0;
    uid = parent ? authority->parent_uid : authority->uid;
    gid = parent ? authority->parent_gid : authority->gid;
    mode = parent ? authority->parent_mode : authority->mode;
    device = parent ? authority->parent_device : authority->device;
    inode = parent ? authority->parent_inode : authority->inode;
    return (unsigned long long)status->st_uid == uid
        && (unsigned long long)status->st_gid == gid
        && (unsigned long long)(status->st_mode & 0777) == mode
        && (status->st_mode & 0022) == 0
        && (!require_identity
            || ((unsigned long long)status->st_dev == device
                && (unsigned long long)status->st_ino == inode));
}

static int wls_guardian_authority_at_path(
    const char *path,
    const struct wls_guardian_root_authority *authority,
    int parent,
    int require_identity,
    int test_platform
);

static int wls_guardian_authority_identity_at_path(
    const char *path,
    const struct wls_guardian_root_authority *authority,
    int parent
) {
    struct stat before;
    struct stat opened;
    struct stat after;
    unsigned long long device;
    unsigned long long inode;
    int fd = -1;
    int result = -1;
    if (path == NULL || authority == NULL) return -1;
    device = parent ? authority->parent_device : authority->device;
    inode = parent ? authority->parent_inode : authority->inode;
    if (lstat(path, &before) != 0 || !S_ISDIR(before.st_mode)
        || S_ISLNK(before.st_mode)
        || (unsigned long long)before.st_dev != device
        || (unsigned long long)before.st_ino != inode) goto cleanup;
    fd = open(path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || fstat(fd, &opened) != 0
        || !wls_launcher_same_file_state(&before, &opened)
        || fstat(fd, &opened) != 0 || lstat(path, &after) != 0
        || !wls_launcher_same_file_state(&opened, &after)
        || (unsigned long long)after.st_dev != device
        || (unsigned long long)after.st_ino != inode) goto cleanup;
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    return result;
}

static int wls_guardian_restore_authority_at_path(
    const char *path,
    const struct wls_guardian_root_authority *authority,
    int parent,
    int require_identity,
    int test_platform
) {
    struct stat before;
    struct stat opened;
    unsigned long long uid;
    unsigned long long gid;
    unsigned long long mode;
    int fd = -1;
    int result = -1;
    if (path == NULL || authority == NULL || lstat(path, &before) != 0
        || !S_ISDIR(before.st_mode) || S_ISLNK(before.st_mode)
        || (require_identity
            && wls_guardian_authority_identity_at_path(
                path, authority, parent
            ) != 0)) goto cleanup;
    uid = parent ? authority->parent_uid : authority->uid;
    gid = parent ? authority->parent_gid : authority->gid;
    mode = parent ? authority->parent_mode : authority->mode;
    fd = open(path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || fstat(fd, &opened) != 0
        || !wls_launcher_same_file_state(&before, &opened)
        || ((opened.st_uid != (uid_t)uid || opened.st_gid != (gid_t)gid)
            && fchown(fd, (uid_t)uid, (gid_t)gid) != 0)
        || fchmod(fd, (mode_t)mode) != 0
        || wls_guardian_acl_clear_fd(fd, test_platform) != 0
        || fsync(fd) != 0
        || wls_guardian_authority_at_path(
            path, authority, parent, require_identity, test_platform
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    return result;
}

static int wls_guardian_authority_at_path(
    const char *path,
    const struct wls_guardian_root_authority *authority,
    int parent,
    int require_identity,
    int test_platform
) {
    struct stat before;
    struct stat opened;
    struct stat after;
    int fd = -1;
    int result = -1;
    if (path == NULL || authority == NULL
        || lstat(path, &before) != 0
        || !wls_guardian_authority_stat_matches(
            &before, authority, parent, require_identity
        )) goto cleanup;
    fd = open(path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 || fstat(fd, &opened) != 0
        || !wls_launcher_same_file_state(&before, &opened)
        || wls_guardian_acl_free_fd(fd, test_platform) != 0
        || fstat(fd, &opened) != 0 || lstat(path, &after) != 0
        || !wls_launcher_same_file_state(&opened, &after)
        || !wls_guardian_authority_stat_matches(
            &after, authority, parent, require_identity
        )) goto cleanup;
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    return result;
}

static int wls_guardian_authority_parent_path(
    const char *root,
    char parent[PATH_MAX],
    char leaf[NAME_MAX + 1U]
) {
    char *slash;
    size_t leaf_length;
    if (root == NULL || parent == NULL || leaf == NULL || root[0] != '/'
        || strlen(root) >= PATH_MAX) return -1;
    memcpy(parent, root, strlen(root) + 1U);
    slash = strrchr(parent, '/');
    if (slash == NULL || slash == parent || slash[1] == '\0') return -1;
    leaf_length = strlen(slash + 1U);
    if (leaf_length == 0U || leaf_length > NAME_MAX) return -1;
    memcpy(leaf, slash + 1U, leaf_length + 1U);
    *slash = '\0';
    return 0;
}

static int wls_guardian_create_authority_root(
    const char *root,
    const struct wls_guardian_root_authority *authority,
    int test_platform
) {
    char parent[PATH_MAX];
    char leaf[NAME_MAX + 1U];
    struct stat parent_before;
    struct stat parent_opened;
    struct stat status;
    int parent_fd = -1;
    int root_fd = -1;
    int result = -1;
    sodium_memzero(parent, sizeof(parent));
    sodium_memzero(leaf, sizeof(leaf));
    if (root == NULL || authority == NULL || !authority->present
        || wls_guardian_authority_parent_path(root, parent, leaf) != 0
        || wls_guardian_authority_at_path(
            parent, authority, 1, 1, test_platform
        ) != 0
        || lstat(parent, &parent_before) != 0) goto cleanup;
    parent_fd = open(
        parent, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (parent_fd < 0 || fstat(parent_fd, &parent_opened) != 0
        || !wls_launcher_same_file_state(&parent_before, &parent_opened)) {
        goto cleanup;
    }
    errno = 0;
    if (fstatat(parent_fd, leaf, &status, AT_SYMLINK_NOFOLLOW) == 0
        || errno != ENOENT
        || mkdirat(parent_fd, leaf, 0700) != 0) goto cleanup;
    root_fd = openat(
        parent_fd,
        leaf,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (root_fd < 0 || fstat(root_fd, &status) != 0
        || !S_ISDIR(status.st_mode)
        || ((status.st_uid != (uid_t)authority->uid
                || status.st_gid != (gid_t)authority->gid)
            && fchown(
                root_fd,
                (uid_t)authority->uid,
                (gid_t)authority->gid
            ) != 0)
        || fchmod(root_fd, (mode_t)authority->mode) != 0
        || wls_guardian_acl_clear_fd(root_fd, test_platform) != 0
        || fsync(root_fd) != 0 || fsync(parent_fd) != 0
        || wls_guardian_authority_at_path(
            root, authority, 0, 0, test_platform
        ) != 0
        || wls_guardian_authority_at_path(
            parent, authority, 1, 1, test_platform
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (root_fd >= 0) close(root_fd);
    if (parent_fd >= 0) close(parent_fd);
    sodium_memzero(parent, sizeof(parent));
    sodium_memzero(leaf, sizeof(leaf));
    return result;
}

static int wls_guardian_open_safe_parent(
    const char *path,
    char parent_path[PATH_MAX],
    char leaf[NAME_MAX + 1U],
    int *parent_fd
) {
    char *slash;
    struct stat before;
    struct stat opened;
    size_t leaf_length;
    int fd = -1;
    if (path == NULL || parent_path == NULL || leaf == NULL
        || parent_fd == NULL || path[0] != '/'
        || strlen(path) >= PATH_MAX) return -1;
    memcpy(parent_path, path, strlen(path) + 1U);
    slash = strrchr(parent_path, '/');
    if (slash == NULL || slash == parent_path || slash[1] == '\0') return -1;
    leaf_length = strlen(slash + 1U);
    if (leaf_length == 0U || leaf_length > NAME_MAX) return -1;
    memcpy(leaf, slash + 1U, leaf_length + 1U);
    *slash = '\0';
    if (lstat(parent_path, &before) != 0
        || !S_ISDIR(before.st_mode) || S_ISLNK(before.st_mode)
        || (before.st_mode & 0022) != 0) return -1;
    fd = open(
        parent_path, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0 || fstat(fd, &opened) != 0
        || !wls_launcher_same_file_state(&before, &opened)) {
        if (fd >= 0) close(fd);
        return -1;
    }
    *parent_fd = fd;
    return 0;
}

static int wls_guardian_rename_no_replace(
    const char *source,
    const char *target
) {
    char source_parent[PATH_MAX];
    char target_parent[PATH_MAX];
    char source_leaf[NAME_MAX + 1U];
    char target_leaf[NAME_MAX + 1U];
    struct stat source_status;
    struct stat target_status;
    int source_fd = -1;
    int target_fd = -1;
    int result = -1;
    sodium_memzero(source_parent, sizeof(source_parent));
    sodium_memzero(target_parent, sizeof(target_parent));
    sodium_memzero(source_leaf, sizeof(source_leaf));
    sodium_memzero(target_leaf, sizeof(target_leaf));
    if (wls_guardian_open_safe_parent(
            source, source_parent, source_leaf, &source_fd
        ) != 0
        || wls_guardian_open_safe_parent(
            target, target_parent, target_leaf, &target_fd
        ) != 0
        || fstatat(
            source_fd, source_leaf, &source_status, AT_SYMLINK_NOFOLLOW
        ) != 0
        || (fstatat(
            target_fd, target_leaf, &target_status, AT_SYMLINK_NOFOLLOW
        ) == 0 || errno != ENOENT)
        || renameat(source_fd, source_leaf, target_fd, target_leaf) != 0
        || fsync(source_fd) != 0
        || (target_fd != source_fd && fsync(target_fd) != 0)) goto cleanup;
    result = 0;
cleanup:
    if (target_fd >= 0) close(target_fd);
    if (source_fd >= 0) close(source_fd);
    sodium_memzero(source_parent, sizeof(source_parent));
    sodium_memzero(target_parent, sizeof(target_parent));
    sodium_memzero(source_leaf, sizeof(source_leaf));
    sodium_memzero(target_leaf, sizeof(target_leaf));
    return result;
}

static int wls_guardian_atomic_suffix_valid(
    const char *suffix,
    size_t expected_length
) {
    size_t index;
    if (suffix == NULL || strlen(suffix) != expected_length) return 0;
    for (index = 0U; index < expected_length; ++index) {
        if (!((suffix[index] >= '0' && suffix[index] <= '9')
            || (suffix[index] >= 'a' && suffix[index] <= 'f'))) return 0;
    }
    return 1;
}

static int wls_guardian_atomic_inventory_load(
    const char *path,
    mode_t expected_mode,
    size_t maximum,
    struct wls_guardian_atomic_inventory *inventory
) {
    char temporary_prefix[NAME_MAX + 1U];
    char backup_prefix[NAME_MAX + 1U];
    struct stat parent_after;
    DIR *directory = NULL;
    struct dirent *entry;
    int parent_fd = -1;
    int scan_fd = -1;
    size_t visited = 0U;
    int result = -1;
    if (path == NULL || maximum == 0U || inventory == NULL) return -1;
    memset(inventory, 0, sizeof(*inventory));
    sodium_memzero(temporary_prefix, sizeof(temporary_prefix));
    sodium_memzero(backup_prefix, sizeof(backup_prefix));
    if (wls_guardian_open_safe_parent(
            path,
            inventory->parent_path,
            inventory->target_leaf,
            &parent_fd
        ) != 0
        || fstat(parent_fd, &inventory->parent_identity) != 0
        || inventory->parent_identity.st_uid != geteuid()
        || wls_guardian_acl_free_fd(parent_fd, 0) != 0
        || snprintf(
            temporary_prefix,
            sizeof(temporary_prefix),
            "%s.tmp-",
            inventory->target_leaf
        ) >= (int)sizeof(temporary_prefix)
        || snprintf(
            backup_prefix,
            sizeof(backup_prefix),
            "%s.wls-backup-",
            inventory->target_leaf
        ) >= (int)sizeof(backup_prefix)) goto cleanup;
    scan_fd = fcntl(parent_fd, F_DUPFD_CLOEXEC, 3);
    if (scan_fd < 0 || (directory = fdopendir(scan_fd)) == NULL) goto cleanup;
    scan_fd = -1;
    for (;;) {
        struct wls_guardian_atomic_artifact *artifact = NULL;
        const char *suffix = NULL;
        size_t suffix_length = 0U;
        size_t entry_length;
        int artifact_fd = -1;
        struct stat opened;
        errno = 0;
        entry = readdir(directory);
        if (entry == NULL) {
            if (errno != 0) goto cleanup;
            break;
        }
        if (++visited > WLS_REBOOTSTRAP_RECOVERY_DIRECTORY_MAX_ENTRIES) {
            goto cleanup;
        }
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        entry_length = strlen(entry->d_name);
        if (wls_rebootstrap_ascii_prefix_equal(
                entry->d_name,
                entry_length,
                temporary_prefix
            )) {
            if (strncmp(
                    entry->d_name,
                    temporary_prefix,
                    strlen(temporary_prefix)
                ) != 0) goto cleanup;
            suffix = entry->d_name + strlen(temporary_prefix);
            suffix_length = 24U;
            artifact = &inventory->temporary;
            artifact->kind = WLS_GUARDIAN_ATOMIC_TEMPORARY;
        } else if (wls_rebootstrap_ascii_prefix_equal(
                entry->d_name,
                entry_length,
                backup_prefix
            )) {
            if (strncmp(
                    entry->d_name,
                    backup_prefix,
                    strlen(backup_prefix)
                ) != 0) goto cleanup;
            suffix = entry->d_name + strlen(backup_prefix);
            suffix_length = 16U;
            artifact = &inventory->backup;
            artifact->kind = WLS_GUARDIAN_ATOMIC_BACKUP;
        } else {
            continue;
        }
        if (artifact->present
            || !wls_guardian_atomic_suffix_valid(suffix, suffix_length)
            || strlen(entry->d_name) > NAME_MAX
            || snprintf(
                artifact->path,
                sizeof(artifact->path),
                "%s/%s",
                inventory->parent_path,
                entry->d_name
            ) >= (int)sizeof(artifact->path)) goto cleanup;
        memcpy(artifact->leaf, entry->d_name, entry_length + 1U);
        if (fstatat(
                parent_fd,
                artifact->leaf,
                &artifact->identity,
                AT_SYMLINK_NOFOLLOW
            ) != 0
            || !S_ISREG(artifact->identity.st_mode)
            || S_ISLNK(artifact->identity.st_mode)
            || artifact->identity.st_nlink != 1
            || artifact->identity.st_uid != geteuid()
            || (artifact->identity.st_gid != inventory->parent_identity.st_gid
                && artifact->identity.st_gid != getegid())
            || artifact->identity.st_size < 0
            || (uint64_t)artifact->identity.st_size > (uint64_t)maximum
            || (artifact->identity.st_mode & 0022) != 0
            || (artifact->kind == WLS_GUARDIAN_ATOMIC_BACKUP
                && ((artifact->identity.st_mode & 0777) != expected_mode
                    || artifact->identity.st_size == 0))) goto cleanup;
        artifact_fd = openat(
            parent_fd,
            artifact->leaf,
            O_RDONLY | O_CLOEXEC | O_NOFOLLOW
        );
        if (artifact_fd < 0 || fstat(artifact_fd, &opened) != 0
            || !wls_launcher_same_file_state(
                &artifact->identity,
                &opened
            )
            || wls_guardian_acl_free_fd(artifact_fd, 0) != 0) {
            if (artifact_fd >= 0) close(artifact_fd);
            goto cleanup;
        }
        if (close(artifact_fd) != 0) goto cleanup;
        artifact->present = 1;
    }
    if (fstat(parent_fd, &parent_after) != 0
        || !wls_launcher_same_file_state(
            &inventory->parent_identity,
            &parent_after
        )) goto cleanup;
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    if (scan_fd >= 0) close(scan_fd);
    if (parent_fd >= 0) close(parent_fd);
    sodium_memzero(temporary_prefix, sizeof(temporary_prefix));
    sodium_memzero(backup_prefix, sizeof(backup_prefix));
    if (result != 0) sodium_memzero(inventory, sizeof(*inventory));
    return result;
}

static int wls_guardian_atomic_artifact_remove(
    const struct wls_guardian_atomic_inventory *inventory,
    const struct wls_guardian_atomic_artifact *artifact
) {
    char parent_path[PATH_MAX];
    char target_leaf[NAME_MAX + 1U];
    struct stat parent;
    struct stat selected;
    int artifact_fd = -1;
    int parent_fd = -1;
    int result = -1;
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(target_leaf, sizeof(target_leaf));
    if (inventory == NULL || artifact == NULL || !artifact->present
        || wls_guardian_open_safe_parent(
            artifact->path,
            parent_path,
            target_leaf,
            &parent_fd
        ) != 0
        || strcmp(parent_path, inventory->parent_path) != 0
        || strcmp(target_leaf, artifact->leaf) != 0
        || fstat(parent_fd, &parent) != 0
        || !wls_launcher_same_file_state(
            &inventory->parent_identity,
            &parent
        )
        || (artifact_fd = openat(
            parent_fd,
            artifact->leaf,
            O_RDONLY | O_CLOEXEC | O_NOFOLLOW
        )) < 0
        || fstat(artifact_fd, &selected) != 0
        || !wls_launcher_same_file_state(&artifact->identity, &selected)
        || wls_guardian_acl_free_fd(artifact_fd, 0) != 0
        || fstatat(
            parent_fd,
            artifact->leaf,
            &selected,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !wls_launcher_same_file_state(&artifact->identity, &selected)
        || unlinkat(parent_fd, artifact->leaf, 0) != 0
        || fsync(parent_fd) != 0) goto cleanup;
    result = 0;
cleanup:
    if (artifact_fd >= 0) close(artifact_fd);
    if (parent_fd >= 0) close(parent_fd);
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(target_leaf, sizeof(target_leaf));
    return result;
}

static int wls_guardian_atomic_artifact_promote(
    const struct wls_guardian_atomic_inventory *inventory,
    mode_t expected_mode
) {
    char parent_path[PATH_MAX];
    char target_leaf[NAME_MAX + 1U];
    struct stat parent;
    struct stat temporary;
    struct stat published;
    int temporary_fd = -1;
    int parent_fd = -1;
    int result = -1;
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(target_leaf, sizeof(target_leaf));
    if (inventory == NULL || !inventory->temporary.present
        || inventory->backup.present
        || inventory->temporary.identity.st_size <= 0
        || (inventory->temporary.identity.st_mode & 0777) != expected_mode
        || wls_guardian_open_safe_parent(
            inventory->temporary.path,
            parent_path,
            target_leaf,
            &parent_fd
        ) != 0
        || strcmp(parent_path, inventory->parent_path) != 0
        || strcmp(target_leaf, inventory->temporary.leaf) != 0
        || fstat(parent_fd, &parent) != 0
        || !wls_launcher_same_file_state(
            &inventory->parent_identity,
            &parent
        )
        || (temporary_fd = openat(
            parent_fd,
            inventory->temporary.leaf,
            O_RDONLY | O_CLOEXEC | O_NOFOLLOW
        )) < 0
        || fstat(temporary_fd, &temporary) != 0
        || !wls_launcher_same_file_state(
            &inventory->temporary.identity,
            &temporary
        )
        || wls_guardian_acl_free_fd(temporary_fd, 0) != 0
        || (fstatat(
            parent_fd,
            inventory->target_leaf,
            &published,
            AT_SYMLINK_NOFOLLOW
        ) == 0 || errno != ENOENT)
        || renameat(
            parent_fd,
            inventory->temporary.leaf,
            parent_fd,
            inventory->target_leaf
        ) != 0
        || fsync(parent_fd) != 0
        || fstat(temporary_fd, &temporary) != 0
        || fstatat(
            parent_fd,
            inventory->target_leaf,
            &published,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !wls_launcher_same_file_state(&temporary, &published)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (temporary_fd >= 0) close(temporary_fd);
    if (parent_fd >= 0) close(parent_fd);
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(target_leaf, sizeof(target_leaf));
    return result;
}

static int wls_guardian_regular_state(
    const char *path,
    const char *sha256,
    unsigned long long size,
    mode_t mode
) {
    struct stat status;
    if (path == NULL || sha256 == NULL || !wls_is_hex_text(sha256, 64U)
        || size == 0ULL || size > WLS_GUARDIAN_LAUNCHER_MAX_BYTES
        || size > (unsigned long long)LLONG_MAX) return -1;
    if (lstat(path, &status) != 0) return errno == ENOENT ? 1 : -1;
    if (!S_ISREG(status.st_mode) || S_ISLNK(status.st_mode)
        || status.st_nlink != 1 || status.st_uid != geteuid()
        || (unsigned long long)status.st_size != size
        || (status.st_mode & 0777) != mode
        || wls_guardian_verify_regular_digest(
            path, sha256, mode, (off_t)size
        ) != 0) return -1;
    return 0;
}

static int wls_guardian_remove_regular_exact(
    const char *path,
    const char *sha256,
    unsigned long long size,
    mode_t mode
) {
    char parent_path[PATH_MAX];
    char leaf[NAME_MAX + 1U];
    int parent_fd = -1;
    int result = -1;
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(leaf, sizeof(leaf));
    if (wls_guardian_regular_state(path, sha256, size, mode) != 0
        || wls_guardian_open_safe_parent(
            path, parent_path, leaf, &parent_fd
        ) != 0
        || unlinkat(parent_fd, leaf, 0) != 0
        || fsync(parent_fd) != 0) goto cleanup;
    result = 0;
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(leaf, sizeof(leaf));
    return result;
}

static int wls_guardian_restore_regular(
    const char *stored,
    const char *target,
    const char *old_sha256,
    unsigned long long old_size,
    mode_t old_mode,
    const char *candidate_sha256,
    unsigned long long candidate_size,
    mode_t candidate_mode
) {
    int stored_state;
    int target_state;
    if (stored == NULL || target == NULL || old_sha256 == NULL
        || candidate_sha256 == NULL) return -1;
    stored_state = wls_guardian_regular_state(
        stored, old_sha256, old_size, old_mode
    );
    target_state = wls_guardian_regular_state(
        target, old_sha256, old_size, old_mode
    );
    if (stored_state == 1 && target_state == 0) return 0;
    if (stored_state != 0) return -1;
    if (target_state == 0) {
        if (sodium_memcmp(old_sha256, candidate_sha256, 64U) != 0
            || old_size != candidate_size || old_mode != candidate_mode
            || wls_guardian_remove_regular_exact(
                target,
                candidate_sha256,
                candidate_size,
                candidate_mode
            ) != 0) return -1;
        target_state = 1;
    }
    if (target_state < 0) {
        if (wls_guardian_regular_state(
                target,
                candidate_sha256,
                candidate_size,
                candidate_mode
            ) != 0
            || wls_guardian_remove_regular_exact(
                target,
                candidate_sha256,
                candidate_size,
                candidate_mode
            ) != 0) return -1;
    }
    if (wls_guardian_rename_no_replace(stored, target) != 0
        || wls_guardian_regular_state(
            target, old_sha256, old_size, old_mode
        ) != 0) return -1;
    return 0;
}

static int wls_guardian_recovery_transaction_parse(
    const char *home,
    const char *contents,
    size_t length,
    struct wls_guardian_recovery_transaction *transaction
) {
    char sequence[21];
    char cursor[21];
    const char *signature_line;
    char *end = NULL;
    unsigned long long parsed_sequence;
    unsigned long long parsed_cursor;
    int consumed = 0;
    int fields;
    int result = -1;
    if (home == NULL || contents == NULL || transaction == NULL
        || length == 0U || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES
        || length > (size_t)INT_MAX) return -1;
    memset(transaction, 0, sizeof(*transaction));
    sodium_memzero(sequence, sizeof(sequence));
    sodium_memzero(cursor, sizeof(cursor));
    fields = sscanf(
        contents,
        "WLS-GUARDIAN-RECOVERY-TRANSACTION/1\n"
        "host_id=%32[0-9a-f]\n"
        "nonce=%32[0-9a-f]\n"
        "request_sha256=%64[0-9a-f]\n"
        "authorization_sha256=%64[0-9a-f]\n"
        "inventory_sha256=%64[0-9a-f]\n"
        "sequence=%20[0-9]\n"
        "phase=%15[A-Z]\n"
        "cursor=%20[0-9]\n"
        "previous_record_sha256=%64[0-9a-f]\n"
        "signature=%64[0-9a-f]\n%n",
        transaction->host_id,
        transaction->nonce,
        transaction->request_sha256,
        transaction->authorization_sha256,
        transaction->inventory_sha256,
        sequence,
        transaction->phase,
        cursor,
        transaction->previous_record_sha256,
        transaction->signature,
        &consumed
    );
    errno = 0;
    parsed_sequence = strtoull(sequence, &end, 10);
    if (errno != 0 || end == sequence || *end != '\0') goto cleanup;
    errno = 0;
    parsed_cursor = strtoull(cursor, &end, 10);
    signature_line = strstr(contents, "signature=");
    if (fields != 10 || consumed != (int)length || signature_line == NULL
        || strlen(transaction->host_id) != 32U
        || strlen(transaction->nonce) != 32U
        || strlen(transaction->request_sha256) != 64U
        || strlen(transaction->authorization_sha256) != 64U
        || strlen(transaction->inventory_sha256) != 64U
        || strlen(transaction->previous_record_sha256) != 64U
        || strlen(transaction->signature) != 64U
        || (strcmp(transaction->phase, "AUTHORIZED") != 0
            && strcmp(transaction->phase, "RUNTIME") != 0
            && strcmp(transaction->phase, "DERIVED") != 0
            && strcmp(transaction->phase, "PLATFORM") != 0
            && strcmp(transaction->phase, "RESTORED") != 0
            && strcmp(transaction->phase, "OBSERVING") != 0
            && strcmp(transaction->phase, "STABLE") != 0)
        || (sequence[0] == '0' && sequence[1] != '\0')
        || (cursor[0] == '0' && cursor[1] != '\0')
        || errno != 0 || end == cursor || *end != '\0'
        || parsed_sequence == 0ULL
        || parsed_sequence > (unsigned long long)LLONG_MAX
        || ((strcmp(transaction->phase, "AUTHORIZED") == 0
                && (parsed_cursor != 0ULL || parsed_sequence != 1ULL
                    || !wls_all_zero_hex(
                        transaction->previous_record_sha256
                    )))
            || (strcmp(transaction->phase, "RUNTIME") == 0
                && (parsed_cursor > 7ULL
                    || parsed_sequence != 2ULL + parsed_cursor))
            || (strcmp(transaction->phase, "DERIVED") == 0
                && (parsed_cursor > 9ULL
                    || parsed_sequence != 10ULL + parsed_cursor))
            || (strcmp(transaction->phase, "PLATFORM") == 0
                && (parsed_cursor > 3ULL
                    || parsed_sequence != 20ULL + parsed_cursor))
            || (strcmp(transaction->phase, "RESTORED") == 0
                && (parsed_cursor != 0ULL || parsed_sequence != 24ULL))
            || (strcmp(transaction->phase, "OBSERVING") == 0
                && (parsed_cursor != 0ULL || parsed_sequence != 25ULL))
            || (strcmp(transaction->phase, "STABLE") == 0
                && (parsed_cursor != 0ULL || parsed_sequence != 26ULL)))
        || wls_guardian_verify_signed_document(
            home, contents, signature_line, transaction->signature
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)contents,
            length,
            transaction->raw_sha256
        ) != 0) goto cleanup;
    transaction->sequence = parsed_sequence;
    transaction->cursor = parsed_cursor;
    result = 0;
cleanup:
    sodium_memzero(sequence, sizeof(sequence));
    sodium_memzero(cursor, sizeof(cursor));
    if (result != 0) sodium_memzero(transaction, sizeof(*transaction));
    return result;
}

static int wls_guardian_recovery_transaction_read(
    const char *home,
    struct wls_guardian_recovery_transaction *transaction
) {
    char contents[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    size_t length = 0U;
    int read_result;
    int result = -1;
    sodium_memzero(contents, sizeof(contents));
    read_result = wls_guardian_read_trust_document(
        home,
        "guardian-recovery.transaction",
        contents,
        &length
    );
    if (read_result == 1) {
        result = 1;
    } else if (read_result == 0
        && wls_guardian_recovery_transaction_parse(
            home, contents, length, transaction
        ) == 0) {
        result = 0;
    }
    sodium_memzero(contents, sizeof(contents));
    return result;
}

static int wls_guardian_recovery_transaction_publish(
    const char *home,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_recovery_transaction *current,
    const char *phase,
    unsigned long long cursor,
    struct wls_guardian_recovery_transaction *published
) {
    static const char zeros[] =
        "0000000000000000000000000000000000000000000000000000000000000000";
    char path[PATH_MAX];
    char unsigned_record[1024];
    char encoded[1152];
    char signature[65];
    const char *previous;
    unsigned long long sequence;
    int unsigned_length;
    int encoded_length;
    int result = -1;
    sodium_memzero(path, sizeof(path));
    sodium_memzero(unsigned_record, sizeof(unsigned_record));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(signature, sizeof(signature));
    if (home == NULL || request == NULL || phase == NULL || published == NULL
        || (strcmp(phase, "AUTHORIZED") != 0
            && strcmp(phase, "RUNTIME") != 0
            && strcmp(phase, "DERIVED") != 0
            && strcmp(phase, "PLATFORM") != 0
            && strcmp(phase, "RESTORED") != 0
            && strcmp(phase, "OBSERVING") != 0
            && strcmp(phase, "STABLE") != 0)) goto cleanup;
    if (current == NULL) {
        if (strcmp(phase, "AUTHORIZED") != 0 || cursor != 0ULL) goto cleanup;
        sequence = 1ULL;
        previous = zeros;
    } else {
        if (current->sequence == 0ULL
            || current->sequence >= (unsigned long long)LLONG_MAX) goto cleanup;
        sequence = current->sequence + 1ULL;
        previous = current->raw_sha256;
    }
    unsigned_length = snprintf(
        unsigned_record,
        sizeof(unsigned_record),
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
    encoded_length = snprintf(
        encoded,
        sizeof(encoded),
        "%ssignature=%s\n",
        unsigned_record,
        signature
    );
    if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/guardian-recovery.transaction"
        ) != 0
        || wls_recovery_target_safe(path, 0600, geteuid()) != 0
        || wls_guardian_atomic_text(path, encoded, 0600) != 0
        || wls_recovery_target_safe(path, 0600, geteuid()) != 0
        || wls_guardian_recovery_transaction_read(home, published) != 0
        || published->sequence != sequence
        || published->cursor != cursor
        || strcmp(published->phase, phase) != 0
        || wls_guardian_recovery_transaction_binding(
            home, published, request
        ) != 0
        || sodium_memcmp(
            published->previous_record_sha256, previous, 64U
        ) != 0
        || sodium_memcmp(published->raw_sha256, previous, 64U) == 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(path, sizeof(path));
    sodium_memzero(unsigned_record, sizeof(unsigned_record));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(signature, sizeof(signature));
    return result;
}

static int wls_guardian_recovery_transaction_position(
    unsigned long long sequence,
    const char **phase,
    unsigned long long *cursor
) {
    if (phase == NULL || cursor == NULL || sequence == 0ULL
        || sequence > 26ULL) return -1;
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

static int wls_guardian_recovery_transaction_expected(
    const char *home,
    const struct wls_guardian_transition_request *request,
    unsigned long long target_sequence,
    char digest[65]
) {
    static const char zeros[] =
        "0000000000000000000000000000000000000000000000000000000000000000";
    char previous[65];
    char unsigned_record[1024];
    char encoded[1152];
    char signature[65];
    unsigned long long sequence;
    int result = -1;
    sodium_memzero(previous, sizeof(previous));
    sodium_memzero(unsigned_record, sizeof(unsigned_record));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(signature, sizeof(signature));
    if (home == NULL || request == NULL || digest == NULL
        || target_sequence == 0ULL || target_sequence > 26ULL) goto cleanup;
    memcpy(previous, zeros, sizeof(previous));
    for (sequence = 1ULL; sequence <= target_sequence; ++sequence) {
        const char *phase = NULL;
        unsigned long long cursor = 0ULL;
        int unsigned_length;
        int encoded_length;
        if (wls_guardian_recovery_transaction_position(
                sequence, &phase, &cursor
            ) != 0) goto cleanup;
        unsigned_length = snprintf(
            unsigned_record,
            sizeof(unsigned_record),
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
        encoded_length = snprintf(
            encoded,
            sizeof(encoded),
            "%ssignature=%s\n",
            unsigned_record,
            signature
        );
        if (encoded_length <= 0 || encoded_length >= (int)sizeof(encoded)
            || wls_sha256_text(
                (const unsigned char *)encoded,
                (size_t)encoded_length,
                previous
            ) != 0) goto cleanup;
    }
    memcpy(digest, previous, 65U);
    result = 0;
cleanup:
    sodium_memzero(previous, sizeof(previous));
    sodium_memzero(unsigned_record, sizeof(unsigned_record));
    sodium_memzero(encoded, sizeof(encoded));
    sodium_memzero(signature, sizeof(signature));
    if (result != 0 && digest != NULL) sodium_memzero(digest, 65U);
    return result;
}

static int wls_guardian_recovery_transaction_binding(
    const char *home,
    const struct wls_guardian_recovery_transaction *transaction,
    const struct wls_guardian_transition_request *request
) {
    char expected[65];
    int result = -1;
    sodium_memzero(expected, sizeof(expected));
    if (home == NULL || transaction == NULL || request == NULL
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
        || sodium_memcmp(
            transaction->raw_sha256, expected, 64U
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(expected, sizeof(expected));
    return result;
}

static int wls_guardian_recovery_transaction_begin(
    const char *home,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_transaction *transaction
) {
    int state;
    if (home == NULL || request == NULL || transaction == NULL) return -1;
    state = wls_guardian_recovery_transaction_read(home, transaction);
    if (state == 1) {
        return wls_guardian_recovery_transaction_publish(
            home, request, NULL, "AUTHORIZED", 0ULL, transaction
        );
    }
    return state == 0
        ? wls_guardian_recovery_transaction_binding(
            home, transaction, request
        )
        : -1;
}

static int wls_guardian_recovery_transaction_advance(
    const char *home,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_recovery_transaction *transaction,
    const char *phase,
    unsigned long long cursor
) {
    struct wls_guardian_recovery_transaction published;
    int allowed = 0;
    memset(&published, 0, sizeof(published));
    if (home == NULL || request == NULL || transaction == NULL || phase == NULL
        || wls_guardian_recovery_transaction_binding(
            home, transaction, request
        ) != 0) return -1;
    if (strcmp(transaction->phase, phase) == 0
        && cursor == transaction->cursor + 1ULL) {
        allowed = 1;
    } else if (cursor == 0ULL
        && ((strcmp(transaction->phase, "AUTHORIZED") == 0
                && strcmp(phase, "RUNTIME") == 0)
            || (strcmp(transaction->phase, "RUNTIME") == 0
                && strcmp(phase, "DERIVED") == 0)
            || (strcmp(transaction->phase, "DERIVED") == 0
                && strcmp(phase, "PLATFORM") == 0)
            || (strcmp(transaction->phase, "PLATFORM") == 0
                && strcmp(phase, "RESTORED") == 0)
            || (strcmp(transaction->phase, "RESTORED") == 0
                && strcmp(phase, "OBSERVING") == 0)
            || (strcmp(transaction->phase, "OBSERVING") == 0
                && strcmp(phase, "STABLE") == 0))) {
        allowed = 1;
    }
    if (!allowed || wls_guardian_recovery_transaction_publish(
            home, request, transaction, phase, cursor, &published
        ) != 0
        || published.sequence != transaction->sequence + 1ULL
        || sodium_memcmp(
            published.previous_record_sha256,
            transaction->raw_sha256,
            64U
        ) != 0) {
        sodium_memzero(&published, sizeof(published));
        return -1;
    }
    *transaction = published;
    sodium_memzero(&published, sizeof(published));
    return 0;
}

static int wls_guardian_recovery_transaction_mark(
    const char *home,
    const struct wls_guardian_transition_request *request,
    const char *phase
) {
    struct wls_guardian_recovery_transaction transaction;
    int result = -1;
    memset(&transaction, 0, sizeof(transaction));
    if (home == NULL || request == NULL || phase == NULL
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
        && wls_guardian_recovery_transaction_advance(
            home,
            request,
            &transaction,
            "OBSERVING",
            0ULL
        ) != 0) goto cleanup;
    if (wls_guardian_recovery_transaction_advance(
            home, request, &transaction, phase, 0ULL
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(&transaction, sizeof(transaction));
    return result;
}

static int wls_guardian_generation_closure(
    const char *home,
    const char *launcher_sha256,
    const char *ca_sha256,
    const char *runtime_generation
) {
    char launcher_digest[65];
    char ca_digest[65];
    char active[2];
    char observed_runtime_generation[65];
    char launcher_path[PATH_MAX];
    char ca_relative[128];
    char ca_path[PATH_MAX];
    int ca_relative_length = -1;
    int result = -1;
    sodium_memzero(launcher_digest, sizeof(launcher_digest));
    sodium_memzero(ca_digest, sizeof(ca_digest));
    sodium_memzero(observed_runtime_generation, sizeof(observed_runtime_generation));
    sodium_memzero(launcher_path, sizeof(launcher_path));
    sodium_memzero(ca_relative, sizeof(ca_relative));
    sodium_memzero(ca_path, sizeof(ca_path));
    if (home == NULL || launcher_sha256 == NULL || ca_sha256 == NULL
        || runtime_generation == NULL
        || !wls_is_hex_text(launcher_sha256, 64U)
        || !wls_is_hex_text(ca_sha256, 64U)
        || !wls_is_hex_text(runtime_generation, 64U)) goto cleanup;
    if (wls_guardian_read_digest(
            home,
            "trust/stable-launcher.sha256",
            launcher_digest
        ) != 0
        || sodium_memcmp(
            launcher_digest,
            launcher_sha256,
            64U
        ) != 0
        || wls_guardian_read_digest(
            home,
            "trust/ca-bundle.sha256",
            ca_digest
        ) != 0
        || sodium_memcmp(ca_digest, ca_sha256, 64U) != 0
        || wls_join(
            launcher_path,
            sizeof(launcher_path),
            home,
            "bin/wls-gateway-launcher"
        ) != 0
        || wls_guardian_verify_executable(
            launcher_path,
            launcher_sha256
        ) != 0
        || wls_active_slot(home, active) != 0
        || (ca_relative_length = snprintf(
            ca_relative,
            sizeof(ca_relative),
            "slots/%c/share/ca-bundle.pem",
            active[0]
        )) <= 0
        || ca_relative_length >= (int)sizeof(ca_relative)
        || wls_join(ca_path, sizeof(ca_path), home, ca_relative) != 0
        || wls_guardian_verify_regular_digest(
            ca_path,
            ca_sha256,
            0444,
            (off_t)(4U * 1024U * 1024U)
        ) != 0
        || wls_launcher_slot_contract_v2(
            home,
            active[0],
            observed_runtime_generation
        ) != 0
        || sodium_memcmp(
            observed_runtime_generation,
            runtime_generation,
            64U
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(launcher_digest, sizeof(launcher_digest));
    sodium_memzero(ca_digest, sizeof(ca_digest));
    sodium_memzero(observed_runtime_generation, sizeof(observed_runtime_generation));
    sodium_memzero(active, sizeof(active));
    sodium_memzero(launcher_path, sizeof(launcher_path));
    sodium_memzero(ca_relative, sizeof(ca_relative));
    sodium_memzero(ca_path, sizeof(ca_path));
    return result;
}

static int wls_guardian_candidate_closure(
    const char *home,
    const struct wls_guardian_transition_request *request
) {
    return request != NULL ? wls_guardian_generation_closure(
        home,
        request->candidate_launcher_sha256,
        request->candidate_ca_sha256,
        request->candidate_runtime_generation
    ) : -1;
}

static int wls_guardian_continuity_parse(
    const char *home,
    const char *contents,
    size_t length,
    struct wls_guardian_health_receipt *receipt
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
    const char *signature_line;
    unsigned long long parsed = 0ULL;
    int consumed = 0;
    int fields;
    int healthy;
    int continuity_only;
    int result = -1;
    if (home == NULL || contents == NULL || receipt == NULL
        || length == 0U || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES
        || length > (size_t)INT_MAX) return -1;
    memset(receipt, 0, sizeof(*receipt));
    sodium_memzero(controller_pid, sizeof(controller_pid));
    sodium_memzero(controller_start, sizeof(controller_start));
    sodium_memzero(broker_pid, sizeof(broker_pid));
    sodium_memzero(broker_start, sizeof(broker_start));
    sodium_memzero(data_plane_pid, sizeof(data_plane_pid));
    sodium_memzero(data_plane_start, sizeof(data_plane_start));
    sodium_memzero(publication_generation, sizeof(publication_generation));
    sodium_memzero(promotion_since, sizeof(promotion_since));
    sodium_memzero(issued_unix, sizeof(issued_unix));
    sodium_memzero(issued_monotonic, sizeof(issued_monotonic));
    fields = sscanf(
        contents,
        "WLS-GUARDIAN-CONTINUITY/1\n"
        "host_id=%32[0-9a-f]\n"
        "host_boot_id=%64[0-9a-f]\n"
        "admission_state=%31[A-Z_]\n"
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
    signature_line = strstr(contents, "signature=");
    healthy = strcmp(receipt->admission_state, "HEALTHY") == 0;
    continuity_only = strcmp(
            receipt->admission_state,
            "PUBLICATION_PENDING"
        ) == 0
        || strcmp(receipt->admission_state, "ISOLATION_REPLAY") == 0
        || strcmp(receipt->admission_state, "ROUTE_DEGRADED") == 0;
    if (fields != 24 || consumed != (int)length
        || signature_line == NULL
        || strlen(receipt->host_id) != 32U
        || strlen(receipt->host_boot_id) != 64U
        || strlen(receipt->active_slot) != 1U
        || strlen(receipt->runtime_generation) != 64U
        || strlen(receipt->bootstrap_receipt_sha256) != 64U
        || strlen(receipt->guardian_health_sha256) != 64U
        || strlen(receipt->controller_epoch) != 32U
        || strlen(receipt->controller_binary_sha256) != 64U
        || strlen(receipt->controller_identity_sha256) != 64U
        || strlen(receipt->broker_binary_sha256) != 64U
        || strlen(receipt->data_plane_binary_sha256) != 64U
        || strlen(receipt->process_attestation_sha256) != 64U
        || strlen(receipt->signature) != 64U
        || strspn(receipt->controller_epoch, "0") == 32U
        || (!healthy && !continuity_only)
        || wls_guardian_parse_unsigned(
            controller_pid,
            (unsigned long long)INT_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->controller_pid = (unsigned long)parsed;
    if (wls_guardian_parse_unsigned(
            controller_start,
            ULLONG_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->controller_start_id = parsed;
    if (wls_guardian_parse_unsigned(
            broker_pid,
            (unsigned long long)INT_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->broker_pid = (unsigned long)parsed;
    if (wls_guardian_parse_unsigned(
            broker_start,
            ULLONG_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->broker_start_id = parsed;
    if (wls_guardian_parse_unsigned(
            data_plane_pid,
            (unsigned long long)INT_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->data_plane_pid = (unsigned long)parsed;
    if (wls_guardian_parse_unsigned(
            data_plane_start,
            ULLONG_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->data_plane_start_id = parsed;
    if (wls_guardian_parse_unsigned(
            publication_generation,
            (unsigned long long)LLONG_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->attested_publication_generation = parsed;
    receipt->controller_generation = parsed;
    receipt->local_data_plane_generation = parsed;
    receipt->public_data_plane_generation = parsed;
    if (wls_guardian_parse_zero_or_unsigned(
            promotion_since,
            (unsigned long long)LLONG_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->promotion_healthy_since_monotonic_ms = parsed;
    if (wls_guardian_parse_unsigned(
            issued_unix,
            (unsigned long long)LLONG_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->issued_unix_ms = parsed;
    if (wls_guardian_parse_unsigned(
            issued_monotonic,
            (unsigned long long)LLONG_MAX,
            &parsed
        ) != 0) goto cleanup;
    receipt->issued_monotonic_ms = parsed;
    if ((healthy
            && (receipt->promotion_healthy_since_monotonic_ms == 0ULL
                || receipt->promotion_healthy_since_monotonic_ms
                    > receipt->issued_monotonic_ms))
        || (continuity_only
            && receipt->promotion_healthy_since_monotonic_ms != 0ULL)
        || wls_guardian_verify_signed_document(
            home,
            contents,
            signature_line,
            receipt->signature
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)contents,
            length,
            receipt->raw_sha256
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(controller_pid, sizeof(controller_pid));
    sodium_memzero(controller_start, sizeof(controller_start));
    sodium_memzero(broker_pid, sizeof(broker_pid));
    sodium_memzero(broker_start, sizeof(broker_start));
    sodium_memzero(data_plane_pid, sizeof(data_plane_pid));
    sodium_memzero(data_plane_start, sizeof(data_plane_start));
    sodium_memzero(publication_generation, sizeof(publication_generation));
    sodium_memzero(promotion_since, sizeof(promotion_since));
    sodium_memzero(issued_unix, sizeof(issued_unix));
    sodium_memzero(issued_monotonic, sizeof(issued_monotonic));
    if (result != 0) sodium_memzero(receipt, sizeof(*receipt));
    return result;
}

static int wls_guardian_controller_identity_live(
    const char *home,
    const struct wls_guardian_health_receipt *health
) {
    char contents[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    char identity_digest[65];
    char pid_text[21];
    char start_text[21];
    char slot[2];
    char runtime_generation[65];
    char fencing_digest[65];
    char expected_binary[PATH_MAX];
    char observed_before[PATH_MAX];
    char observed_after[PATH_MAX];
    unsigned long long parsed_pid = 0ULL;
    unsigned long long parsed_start = 0ULL;
    unsigned long long start_before = 0ULL;
    unsigned long long start_after = 0ULL;
    size_t length = 0U;
    int consumed = 0;
    int result = -1;
    sodium_memzero(contents, sizeof(contents));
    sodium_memzero(identity_digest, sizeof(identity_digest));
    sodium_memzero(pid_text, sizeof(pid_text));
    sodium_memzero(start_text, sizeof(start_text));
    sodium_memzero(slot, sizeof(slot));
    sodium_memzero(runtime_generation, sizeof(runtime_generation));
    sodium_memzero(fencing_digest, sizeof(fencing_digest));
    sodium_memzero(expected_binary, sizeof(expected_binary));
    sodium_memzero(observed_before, sizeof(observed_before));
    sodium_memzero(observed_after, sizeof(observed_after));
    if (home == NULL || health == NULL
        || wls_guardian_read_trust_document(
            home,
            "controller-process.identity",
            contents,
            &length
        ) != 0
        || wls_sha256_text(
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
            "pid=%20[0-9]\nstart_id=%20[0-9]\nslot=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "fencing_digest=%64[0-9a-f]\n%n",
            pid_text,
            start_text,
            slot,
            runtime_generation,
            fencing_digest,
            &consumed
        ) != 5
        || consumed != (int)length
        || strlen(slot) != 1U
        || strlen(runtime_generation) != 64U
        || strlen(fencing_digest) != 64U
        || wls_guardian_parse_unsigned(
            pid_text,
            (unsigned long long)INT_MAX,
            &parsed_pid
        ) != 0
        || wls_guardian_parse_unsigned(
            start_text,
            ULLONG_MAX,
            &parsed_start
        ) != 0
        || parsed_pid != (unsigned long long)health->controller_pid
        || parsed_start != health->controller_start_id
        || slot[0] != health->active_slot[0]
        || sodium_memcmp(
            runtime_generation,
            health->runtime_generation,
            64U
        ) != 0
        || snprintf(
            expected_binary,
            sizeof(expected_binary),
            "%s/slots/%c/bin/php",
            home,
            slot[0]
        ) >= (int)sizeof(expected_binary)
        || wls_recovery_process_identity(
            (pid_t)health->controller_pid,
            observed_before,
            sizeof(observed_before),
            &start_before
        ) != 0
        || start_before != health->controller_start_id
        || strcmp(observed_before, expected_binary) != 0
        || wls_guardian_verify_executable(
            expected_binary,
            health->controller_binary_sha256
        ) != 0
        || wls_recovery_process_identity(
            (pid_t)health->controller_pid,
            observed_after,
            sizeof(observed_after),
            &start_after
        ) != 0
        || start_after != start_before
        || strcmp(observed_after, observed_before) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(contents, sizeof(contents));
    sodium_memzero(identity_digest, sizeof(identity_digest));
    sodium_memzero(pid_text, sizeof(pid_text));
    sodium_memzero(start_text, sizeof(start_text));
    sodium_memzero(slot, sizeof(slot));
    sodium_memzero(runtime_generation, sizeof(runtime_generation));
    sodium_memzero(fencing_digest, sizeof(fencing_digest));
    sodium_memzero(expected_binary, sizeof(expected_binary));
    sodium_memzero(observed_before, sizeof(observed_before));
    sodium_memzero(observed_after, sizeof(observed_after));
    return result;
}

static int wls_guardian_broker_identity_live(
    const char *home,
    const struct wls_guardian_health_receipt *health
) {
    char expected_binary[PATH_MAX];
    char observed_before[PATH_MAX];
    char observed_after[PATH_MAX];
    unsigned long long start_before = 0ULL;
    unsigned long long start_after = 0ULL;
    int result = -1;
    sodium_memzero(expected_binary, sizeof(expected_binary));
    sodium_memzero(observed_before, sizeof(observed_before));
    sodium_memzero(observed_after, sizeof(observed_after));
    if (home == NULL || health == NULL
        || health->broker_pid == 0U
        || health->broker_pid > (unsigned long)INT_MAX
        || health->broker_start_id == 0ULL
        || (health->active_slot[0] != 'A' && health->active_slot[0] != 'B')
        || health->active_slot[1] != '\0'
        || snprintf(
            expected_binary,
            sizeof(expected_binary),
            "%s/slots/%c/bin/wls-gateway-broker",
            home,
            health->active_slot[0]
        ) >= (int)sizeof(expected_binary)
        || wls_recovery_process_identity(
            (pid_t)health->broker_pid,
            observed_before,
            sizeof(observed_before),
            &start_before
        ) != 0
        || start_before != health->broker_start_id
        || strcmp(observed_before, expected_binary) != 0
        || wls_guardian_verify_executable(
            expected_binary,
            health->broker_binary_sha256
        ) != 0
        || wls_recovery_process_identity(
            (pid_t)health->broker_pid,
            observed_after,
            sizeof(observed_after),
            &start_after
        ) != 0
        || start_after != start_before
        || strcmp(observed_after, observed_before) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(expected_binary, sizeof(expected_binary));
    sodium_memzero(observed_before, sizeof(observed_before));
    sodium_memzero(observed_after, sizeof(observed_after));
    return result;
}

static int wls_guardian_data_plane_live(
    const char *home,
    const struct wls_guardian_health_receipt *health,
    struct wls_process_attestation_receipt *attested
) {
    char path[PATH_MAX];
    char contents[2049];
    char digest[65];
    size_t length = 0U;
    struct wls_process_attestation_receipt receipt;
    int result = -1;
    memset(&receipt, 0, sizeof(receipt));
    sodium_memzero(contents, sizeof(contents));
    sodium_memzero(digest, sizeof(digest));
    if (home == NULL || health == NULL || attested == NULL
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/process-attestation.receipt"
        ) != 0
        || wls_recovery_read_secure(
            path,
            0600,
            geteuid(),
            contents,
            sizeof(contents),
            &length
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)contents,
            length,
            digest
        ) != 0
        || sodium_memcmp(
            digest,
            health->process_attestation_sha256,
            64U
        ) != 0
        || wls_parse_process_attestation(
            contents,
            length,
            &receipt
        ) != 0
        || receipt.pid != health->data_plane_pid
        || receipt.start_id != health->data_plane_start_id
        || sodium_memcmp(
            receipt.binary_digest,
            health->data_plane_binary_sha256,
            64U
        ) != 0
        || sodium_memcmp(
            receipt.runtime_generation,
            health->runtime_generation,
            64U
        ) != 0
        || (unsigned long long)receipt.publication_generation
            != health->public_data_plane_generation
        || wls_recovery_attested_process_live(&receipt) != 1) goto cleanup;
    memcpy(attested, &receipt, sizeof(receipt));
    result = 0;
cleanup:
    if (result != 0 && attested != NULL) {
        sodium_memzero(attested, sizeof(*attested));
    }
    sodium_memzero(&receipt, sizeof(receipt));
    sodium_memzero(contents, sizeof(contents));
    sodium_memzero(digest, sizeof(digest));
    sodium_memzero(path, sizeof(path));
    return result;
}

static int wls_guardian_observation_identity(
    const char *host_id,
    const char *candidate_generation_id,
    const struct wls_guardian_health_receipt *health,
    const struct wls_process_attestation_receipt *data_plane,
    char observation_identity[65]
) {
    char canonical[2048];
    int length;
    if (host_id == NULL || candidate_generation_id == NULL || health == NULL
        || data_plane == NULL
        || observation_identity == NULL
        || !wls_is_hex_text(host_id, 32U)
        || !wls_is_hex_text(candidate_generation_id, 64U)) return -1;
    length = snprintf(
        canonical,
        sizeof(canonical),
        "WLS-GUARDIAN-OBSERVATION-IDENTITY/3\n"
        "host_id=%s\n"
        "host_boot_id=%s\n"
        "active_slot=%s\n"
        "candidate_generation_id=%s\n"
        "runtime_generation=%s\n"
        "controller_epoch=%s\n"
        "controller_pid=%lu\n"
        "controller_start_id=%llu\n"
        "controller_binary_sha256=%s\n"
        "controller_identity_sha256=%s\n"
        "broker_pid=%lu\n"
        "broker_start_id=%llu\n"
        "broker_binary_sha256=%s\n"
        "data_plane_pid=%lu\n"
        "data_plane_start_id=%llu\n"
        "data_plane_binary_sha256=%s\n"
        "data_plane_runtime_generation=%s\n",
        host_id,
        health->host_boot_id,
        health->active_slot,
        candidate_generation_id,
        health->runtime_generation,
        health->controller_epoch,
        health->controller_pid,
        health->controller_start_id,
        health->controller_binary_sha256,
        health->controller_identity_sha256,
        health->broker_pid,
        health->broker_start_id,
        health->broker_binary_sha256,
        data_plane->pid,
        data_plane->start_id,
        data_plane->binary_digest,
        data_plane->runtime_generation
    );
    if (length <= 0 || length >= (int)sizeof(canonical)
        || wls_sha256_text(
            (const unsigned char *)canonical,
            (size_t)length,
            observation_identity
        ) != 0) {
        sodium_memzero(canonical, sizeof(canonical));
        return -1;
    }
    sodium_memzero(canonical, sizeof(canonical));
    return 0;
}

static int wls_guardian_observation_identity_self_test(void)
{
    struct wls_guardian_health_receipt health;
    struct wls_process_attestation_receipt data_plane;
    char baseline[65];
    char changed[65];
    char host_id[33];
    char generation[65];
    int result = -1;
    memset(&health, 0, sizeof(health));
    memset(&data_plane, 0, sizeof(data_plane));
    memset(host_id, '1', 32U);
    host_id[32] = '\0';
    memset(generation, '2', 64U);
    generation[64] = '\0';
    memset(health.host_boot_id, '3', 64U);
    health.host_boot_id[64] = '\0';
    memcpy(health.active_slot, "A", 2U);
    memset(health.runtime_generation, '4', 64U);
    health.runtime_generation[64] = '\0';
    memset(health.controller_epoch, '5', 32U);
    health.controller_epoch[32] = '\0';
    health.controller_generation = 11ULL;
    health.local_data_plane_generation = 11ULL;
    health.public_data_plane_generation = 11ULL;
    health.controller_pid = 101U;
    health.controller_start_id = 202ULL;
    memset(health.controller_binary_sha256, '6', 64U);
    health.controller_binary_sha256[64] = '\0';
    memset(health.controller_identity_sha256, '7', 64U);
    health.controller_identity_sha256[64] = '\0';
    health.broker_pid = 151U;
    health.broker_start_id = 252ULL;
    memset(health.broker_binary_sha256, 'a', 64U);
    health.broker_binary_sha256[64] = '\0';
    memset(health.process_attestation_sha256, '8', 64U);
    health.process_attestation_sha256[64] = '\0';
    data_plane.pid = 303U;
    data_plane.start_id = 404ULL;
    memset(data_plane.binary_digest, '9', 64U);
    data_plane.binary_digest[64] = '\0';
    memcpy(
        data_plane.runtime_generation,
        health.runtime_generation,
        sizeof(data_plane.runtime_generation)
    );
    if (wls_guardian_observation_identity(
            host_id, generation, &health, &data_plane, baseline
        ) != 0) goto cleanup;
#define WLS_GUARDIAN_IDENTITY_MUST_CHANGE(mutation, restoration) do { \
        mutation; \
        if (wls_guardian_observation_identity( \
                host_id, generation, &health, &data_plane, changed \
            ) != 0 || sodium_memcmp(baseline, changed, 64U) == 0) { \
            restoration; \
            goto cleanup; \
        } \
        restoration; \
    } while (0)
#define WLS_GUARDIAN_IDENTITY_MUST_NOT_CHANGE(mutation, restoration) do { \
        mutation; \
        if (wls_guardian_observation_identity( \
                host_id, generation, &health, &data_plane, changed \
            ) != 0 || sodium_memcmp(baseline, changed, 64U) != 0) { \
            restoration; \
            goto cleanup; \
        } \
        restoration; \
    } while (0)
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        host_id[0] = '9',
        host_id[0] = '1'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        generation[0] = '9',
        generation[0] = '2'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.host_boot_id[0] = '9',
        health.host_boot_id[0] = '3'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.active_slot[0] = 'B',
        health.active_slot[0] = 'A'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.runtime_generation[0] = '9',
        health.runtime_generation[0] = '4'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.controller_epoch[0] = '9',
        health.controller_epoch[0] = '5'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.controller_pid++,
        health.controller_pid--
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.controller_start_id++,
        health.controller_start_id--
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.controller_identity_sha256[0] = '9',
        health.controller_identity_sha256[0] = '7'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.broker_pid++,
        health.broker_pid--
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.broker_start_id++,
        health.broker_start_id--
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.broker_binary_sha256[0] = 'b',
        health.broker_binary_sha256[0] = 'a'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        data_plane.pid++,
        data_plane.pid--
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        data_plane.start_id++,
        data_plane.start_id--
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        data_plane.binary_digest[0] = 'a',
        data_plane.binary_digest[0] = '9'
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        data_plane.runtime_generation[0] = 'a',
        data_plane.runtime_generation[0] = '4'
    );
    WLS_GUARDIAN_IDENTITY_MUST_NOT_CHANGE(
        health.process_attestation_sha256[0] = '9',
        health.process_attestation_sha256[0] = '8'
    );
    WLS_GUARDIAN_IDENTITY_MUST_NOT_CHANGE(
        health.controller_generation++,
        health.controller_generation--
    );
    WLS_GUARDIAN_IDENTITY_MUST_NOT_CHANGE(
        health.local_data_plane_generation++,
        health.local_data_plane_generation--
    );
    WLS_GUARDIAN_IDENTITY_MUST_NOT_CHANGE(
        health.public_data_plane_generation++,
        health.public_data_plane_generation--
    );
    WLS_GUARDIAN_IDENTITY_MUST_CHANGE(
        health.controller_binary_sha256[0] = '9',
        health.controller_binary_sha256[0] = '6'
    );
    result = 0;
#undef WLS_GUARDIAN_IDENTITY_MUST_CHANGE
#undef WLS_GUARDIAN_IDENTITY_MUST_NOT_CHANGE
cleanup:
    sodium_memzero(&health, sizeof(health));
    sodium_memzero(&data_plane, sizeof(data_plane));
    sodium_memzero(baseline, sizeof(baseline));
    sodium_memzero(changed, sizeof(changed));
    sodium_memzero(host_id, sizeof(host_id));
    sodium_memzero(generation, sizeof(generation));
    return result;
}

static int wls_guardian_probation_health(
    const char *home,
    const char *run_directory,
    const char *host_id,
    const char *candidate_generation_id,
    const char *runtime_generation,
    struct wls_guardian_probation_sample *sample
) {
    char path[PATH_MAX];
    char contents[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    char after[WLS_GUARDIAN_DOCUMENT_MAX_BYTES + 1U];
    char after_digest[65];
    char boot_id[65];
    char active_slot[2];
    size_t length = 0U;
    size_t after_length = 0U;
    long long now;
    struct wls_guardian_health_receipt health;
    struct wls_process_attestation_receipt data_plane;
    int result = -1;
    memset(&health, 0, sizeof(health));
    memset(&data_plane, 0, sizeof(data_plane));
    if (sample != NULL) memset(sample, 0, sizeof(*sample));
    sodium_memzero(path, sizeof(path));
    sodium_memzero(contents, sizeof(contents));
    sodium_memzero(after, sizeof(after));
    sodium_memzero(after_digest, sizeof(after_digest));
    sodium_memzero(boot_id, sizeof(boot_id));
    sodium_memzero(active_slot, sizeof(active_slot));
    if (home == NULL || run_directory == NULL || host_id == NULL
        || candidate_generation_id == NULL
        || runtime_generation == NULL || sample == NULL
        || !wls_is_hex_text(host_id, 32U)
        || !wls_is_hex_text(candidate_generation_id, 64U)
        || !wls_is_hex_text(runtime_generation, 64U)
        || wls_join(
            path,
            sizeof(path),
            run_directory,
            "guardian-continuity.receipt"
        ) != 0
        || wls_recovery_read_secure(
            path,
            0600,
            geteuid(),
            contents,
            sizeof(contents),
            &length
        ) != 0
        || wls_guardian_continuity_parse(
            home,
            contents,
            length,
            &health
        ) != 0
        || wls_boot_id(boot_id) != 0
        || wls_active_slot(home, active_slot) != 0
        || sodium_memcmp(health.host_id, host_id, 32U) != 0
        || sodium_memcmp(health.host_boot_id, boot_id, 64U) != 0
        || health.active_slot[0] != active_slot[0]
        || sodium_memcmp(
            health.runtime_generation,
            runtime_generation,
            64U
        ) != 0
        || (now = wls_monotonic_milliseconds()) <= 0
        || (unsigned long long)now < health.issued_monotonic_ms
        || (unsigned long long)now - health.issued_monotonic_ms
            > (unsigned long long)WLS_GUARDIAN_HEALTH_FRESHNESS_MILLISECONDS
        || wls_guardian_controller_identity_live(home, &health) != 0
        || wls_guardian_broker_identity_live(home, &health) != 0
        || wls_guardian_data_plane_live(
            home,
            &health,
            &data_plane
        ) != 0
        || health.controller_pid == health.broker_pid
        || health.controller_pid == health.data_plane_pid
        || health.broker_pid == health.data_plane_pid
        || wls_recovery_read_secure(
            path,
            0600,
            geteuid(),
            after,
            sizeof(after),
            &after_length
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)after,
            after_length,
            after_digest
        ) != 0
        || sodium_memcmp(after_digest, health.raw_sha256, 64U) != 0) {
        goto cleanup;
    }
    if (wls_guardian_observation_identity(
            host_id,
            candidate_generation_id,
            &health,
            &data_plane,
            sample->identity_sha256
        ) != 0) goto cleanup;
    sample->promotion_healthy = strcmp(
        health.admission_state,
        "HEALTHY"
    ) == 0;
    sample->promotion_since_monotonic_ms
        = health.promotion_healthy_since_monotonic_ms;
    sample->issued_monotonic_ms = health.issued_monotonic_ms;
    memcpy(
        sample->raw_sha256,
        health.raw_sha256,
        sizeof(sample->raw_sha256)
    );
    result = 0;
cleanup:
    sodium_memzero(&health, sizeof(health));
    sodium_memzero(&data_plane, sizeof(data_plane));
    sodium_memzero(path, sizeof(path));
    sodium_memzero(contents, sizeof(contents));
    sodium_memzero(after, sizeof(after));
    sodium_memzero(after_digest, sizeof(after_digest));
    sodium_memzero(boot_id, sizeof(boot_id));
    sodium_memzero(active_slot, sizeof(active_slot));
    if (result != 0 && sample != NULL) {
        sodium_memzero(sample, sizeof(*sample));
    }
    return result;
}

/* 1=promotion-eligible HEALTHY sample; 0=valid continuity-only sample;
 * -1=replay, splice, rollback, or malformed in-memory sample.  Mutations are
 * committed only after the complete transition has been validated. */
static int wls_guardian_observation_accept_sample(
    struct wls_guardian_probation_observation *observation,
    const struct wls_guardian_probation_sample *sample,
    int *identity_changed
) {
    struct wls_guardian_probation_observation next;
    unsigned long long previous_issued;
    int changed = 0;
    int result = -1;
    if (observation == NULL || sample == NULL || identity_changed == NULL
        || (sample->promotion_healthy != 0
            && sample->promotion_healthy != 1)
        || sample->issued_monotonic_ms == 0ULL
        || !wls_is_hex_text(sample->identity_sha256, 64U)
        || !wls_is_hex_text(sample->raw_sha256, 64U)
        || (sample->promotion_healthy
            && (sample->promotion_since_monotonic_ms == 0ULL
                || sample->promotion_since_monotonic_ms
                    > sample->issued_monotonic_ms))
        || (!sample->promotion_healthy
            && sample->promotion_since_monotonic_ms != 0ULL)) return -1;
    next = *observation;
    previous_issued = next.last_issued_monotonic_ms;
    if (next.initialized) {
        if (sample->issued_monotonic_ms < previous_issued
            || (sample->issued_monotonic_ms == previous_issued
                && sodium_memcmp(
                    sample->raw_sha256,
                    next.last_receipt_sha256,
                    64U
                ) != 0)) goto cleanup;
        changed = sodium_memcmp(
            sample->identity_sha256,
            next.identity_sha256,
            64U
        ) != 0;
        if (changed) {
            if (previous_issued
                    > next.required_promotion_since_floor) {
                next.required_promotion_since_floor = previous_issued;
            }
            next.reset_existing_probation = 1;
            next.last_promotion_since_monotonic_ms = 0ULL;
        }
    } else {
        next.initialized = 1;
    }
    if (!sample->promotion_healthy) {
        if (sample->issued_monotonic_ms
                > next.required_promotion_since_floor) {
            next.required_promotion_since_floor
                = sample->issued_monotonic_ms;
        }
        next.reset_existing_probation = 1;
        next.last_promotion_since_monotonic_ms = 0ULL;
        result = 0;
    } else {
        if (next.required_promotion_since_floor != 0ULL
            && sample->promotion_since_monotonic_ms
                < next.required_promotion_since_floor) goto cleanup;
        if (next.last_promotion_since_monotonic_ms != 0ULL) {
            if (sample->promotion_since_monotonic_ms
                    < next.last_promotion_since_monotonic_ms) goto cleanup;
            if (sample->promotion_since_monotonic_ms
                    > next.last_promotion_since_monotonic_ms) {
                next.reset_existing_probation = 1;
            }
        }
        next.last_promotion_since_monotonic_ms
            = sample->promotion_since_monotonic_ms;
        result = 1;
    }
    memcpy(
        next.identity_sha256,
        sample->identity_sha256,
        sizeof(next.identity_sha256)
    );
    memcpy(
        next.last_receipt_sha256,
        sample->raw_sha256,
        sizeof(next.last_receipt_sha256)
    );
    next.last_issued_monotonic_ms = sample->issued_monotonic_ms;
    *observation = next;
    *identity_changed = changed;
cleanup:
    sodium_memzero(&next, sizeof(next));
    return result;
}

static void wls_guardian_observation_window_committed(
    struct wls_guardian_probation_observation *observation
) {
    if (observation == NULL) return;
    observation->reset_existing_probation = 0;
    observation->required_promotion_since_floor = 0ULL;
}

static int wls_guardian_observation_sample_self_test(void)
{
    struct wls_guardian_probation_observation observation;
    struct wls_guardian_probation_observation before_replay;
    struct wls_guardian_probation_sample sample;
    int changed = 0;
    int result = -1;
    memset(&observation, 0, sizeof(observation));
    memset(&before_replay, 0, sizeof(before_replay));
    memset(&sample, 0, sizeof(sample));
    memset(sample.identity_sha256, '1', 64U);
    sample.identity_sha256[64] = '\0';
    memset(sample.raw_sha256, 'a', 64U);
    sample.raw_sha256[64] = '\0';
    sample.promotion_healthy = 1;
    sample.promotion_since_monotonic_ms = 10ULL;
    sample.issued_monotonic_ms = 20ULL;
    if (wls_guardian_observation_accept_sample(
            &observation, &sample, &changed
        ) != 1 || changed || !observation.initialized) goto cleanup;
    wls_guardian_observation_window_committed(&observation);

    memset(sample.raw_sha256, 'b', 64U);
    sample.promotion_healthy = 0;
    sample.promotion_since_monotonic_ms = 0ULL;
    sample.issued_monotonic_ms = 100ULL;
    if (wls_guardian_observation_accept_sample(
            &observation, &sample, &changed
        ) != 0
        || changed
        || !observation.reset_existing_probation
        || observation.required_promotion_since_floor != 100ULL) {
        goto cleanup;
    }

    memset(sample.raw_sha256, 'c', 64U);
    sample.promotion_healthy = 1;
    sample.promotion_since_monotonic_ms = 101ULL;
    sample.issued_monotonic_ms = 110ULL;
    if (wls_guardian_observation_accept_sample(
            &observation, &sample, &changed
        ) != 1
        || changed
        || !observation.reset_existing_probation) goto cleanup;
    wls_guardian_observation_window_committed(&observation);
    before_replay = observation;

    memset(sample.raw_sha256, 'd', 64U);
    sample.issued_monotonic_ms = 109ULL;
    if (wls_guardian_observation_accept_sample(
            &observation, &sample, &changed
        ) != -1
        || sodium_memcmp(
            &observation,
            &before_replay,
            sizeof(observation)
        ) != 0) goto cleanup;
    sample.issued_monotonic_ms = 110ULL;
    if (wls_guardian_observation_accept_sample(
            &observation, &sample, &changed
        ) != -1
        || sodium_memcmp(
            &observation,
            &before_replay,
            sizeof(observation)
        ) != 0) goto cleanup;

    memset(sample.identity_sha256, '2', 64U);
    sample.identity_sha256[64] = '\0';
    sample.promotion_since_monotonic_ms = 111ULL;
    sample.issued_monotonic_ms = 120ULL;
    if (wls_guardian_observation_accept_sample(
            &observation, &sample, &changed
        ) != 1
        || !changed
        || !observation.reset_existing_probation) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(&observation, sizeof(observation));
    sodium_memzero(&before_replay, sizeof(before_replay));
    sodium_memzero(&sample, sizeof(sample));
    return result;
}

static int wls_guardian_head_is_committed_candidate(
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request
) {
    return head != NULL
        && request != NULL
        && strcmp(head->phase, "STABLE") == 0
        && head->sequence > request->expected_head_sequence
        && sodium_memcmp(
            head->active_generation_id,
            request->candidate_generation_id,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_launcher_sha256,
            request->candidate_launcher_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_ca_sha256,
            request->candidate_ca_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_runtime_generation,
            request->candidate_runtime_generation,
            64U
        ) == 0;
}

static int wls_guardian_head_is_committed_recovery(
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request
) {
    return head != NULL
        && request != NULL
        && strcmp(head->phase, "STABLE") == 0
        && head->sequence > request->expected_head_sequence
        && sodium_memcmp(
            head->active_generation_id,
            request->recovery_generation_id,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_launcher_sha256,
            request->recovery_launcher_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_ca_sha256,
            request->recovery_ca_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_runtime_generation,
            request->recovery_runtime_generation,
            64U
        ) == 0;
}

static int wls_guardian_head_has_request_recovery(
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request,
    int recovery_active
) {
    if (head == NULL || request == NULL
        || (recovery_active != 0 && recovery_active != 1)
        || sodium_memcmp(
            head->recovery_generation_id,
            request->recovery_generation_id,
            64U
        ) != 0
        || sodium_memcmp(head->recovery_nonce, request->nonce, 32U) != 0
        || sodium_memcmp(
            head->recovery_authorization_sha256,
            request->recovery_authorization_sha256,
            64U
        ) != 0) return 0;
    if (!recovery_active) {
        return sodium_memcmp(
                head->active_generation_id,
                request->candidate_generation_id,
                64U
            ) == 0
            && sodium_memcmp(
                head->active_launcher_sha256,
                request->candidate_launcher_sha256,
                64U
            ) == 0
            && sodium_memcmp(
                head->active_ca_sha256,
                request->candidate_ca_sha256,
                64U
            ) == 0
            && sodium_memcmp(
                head->active_runtime_generation,
                request->candidate_runtime_generation,
                64U
            ) == 0;
    }
    return sodium_memcmp(
            head->active_generation_id,
            request->recovery_generation_id,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_launcher_sha256,
            request->recovery_launcher_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_ca_sha256,
            request->recovery_ca_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_runtime_generation,
            request->recovery_runtime_generation,
            64U
        ) == 0;
}

static int wls_guardian_closure_safe(const char *path)
{
    struct wls_guardian_closure_records records;
    int result = -1;
    memset(&records, 0, sizeof(records));
    if (path == NULL || wls_guardian_closure_walk(
            path, ".", 0U, &records, 0
        ) != 0 || records.count == 0U) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_closure_records_free(&records);
    return result;
}

static int wls_guardian_directory_names(
    const char *path,
    char ***names,
    size_t *count
) {
    struct stat before;
    struct stat opened;
    struct stat after;
    DIR *directory = NULL;
    struct dirent *entry;
    char **selected = NULL;
    size_t used = 0U;
    size_t capacity = 0U;
    int result = -1;
    if (path == NULL || names == NULL || count == NULL) return -1;
    *names = NULL;
    *count = 0U;
    if (lstat(path, &before) != 0 || !S_ISDIR(before.st_mode)
        || S_ISLNK(before.st_mode) || (before.st_mode & 0022) != 0) {
        goto cleanup;
    }
    directory = opendir(path);
    if (directory == NULL || fstat(dirfd(directory), &opened) != 0
        || !wls_launcher_same_file_state(&before, &opened)) goto cleanup;
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        size_t length;
        char *copy;
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (used >= WLS_GUARDIAN_INVENTORY_MAX_ENTRIES) goto cleanup;
        if (used == capacity) {
            size_t next = capacity == 0U ? 16U : capacity * 2U;
            char **grown;
            if (next > WLS_GUARDIAN_INVENTORY_MAX_ENTRIES) {
                next = WLS_GUARDIAN_INVENTORY_MAX_ENTRIES;
            }
            grown = realloc(selected, next * sizeof(*selected));
            if (grown == NULL) goto cleanup;
            selected = grown;
            capacity = next;
        }
        length = strlen(entry->d_name);
        copy = malloc(length + 1U);
        if (copy == NULL) goto cleanup;
        memcpy(copy, entry->d_name, length + 1U);
        selected[used++] = copy;
    }
    if (errno != 0 || fstat(dirfd(directory), &opened) != 0
        || lstat(path, &after) != 0
        || !wls_launcher_same_file_state(&before, &opened)
        || !wls_launcher_same_file_state(&opened, &after)) goto cleanup;
    if (used > 1U) {
        qsort(selected, used, sizeof(*selected), wls_guardian_name_compare);
    }
    *names = selected;
    *count = used;
    selected = NULL;
    used = 0U;
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    wls_guardian_name_list_free(selected, used);
    return result;
}

static int wls_guardian_derived_preserved(
    const char *category,
    const char *leaf,
    int test_platform
) {
    static const char *state[] = {
        "recovery.reserve",
        "service-definition.test",
    };
    static const char *trust[] = {
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
        "stable-launcher.sha256",
    };
    const char *const *list = NULL;
    size_t count = 0U;
    size_t index;
    if (category == NULL || leaf == NULL) return 0;
    if (strcmp(category, "state") == 0) {
        list = state;
        count = sizeof(state) / sizeof(state[0]);
    } else if (strcmp(category, "trust") == 0) {
        list = trust;
        count = sizeof(trust) / sizeof(trust[0]);
    }
    for (index = 0U; index < count; ++index) {
        if (strcmp(list[index], leaf) == 0
            && (strcmp(leaf, "service-definition.test") != 0
                || test_platform)) return 1;
    }
    return 0;
}

static const struct wls_guardian_inventory_entry *
wls_guardian_inventory_find(
    const struct wls_guardian_recovery_inventory *inventory,
    const char *category,
    const char *leaf
) {
    size_t index;
    if (inventory == NULL || category == NULL || leaf == NULL) return NULL;
    for (index = 0U; index < inventory->count; ++index) {
        if (strcmp(inventory->entries[index].category, category) == 0
            && strcmp(inventory->entries[index].leaf, leaf) == 0) {
            return &inventory->entries[index];
        }
    }
    return NULL;
}

static const struct wls_guardian_root_authority *
wls_guardian_root_authority_find(
    const struct wls_guardian_recovery_inventory *inventory,
    const char *category
) {
    size_t index;
    if (inventory == NULL || category == NULL) return NULL;
    for (index = 0U; index < WLS_GUARDIAN_DERIVED_CATEGORY_COUNT; ++index) {
        if (strcmp(inventory->roots[index].category, category) == 0) {
            return &inventory->roots[index];
        }
    }
    return NULL;
}

static int wls_guardian_derived_category_paths(
    const char *home,
    const char *backup,
    const char *category,
    char live[PATH_MAX],
    char stored[PATH_MAX],
    char quarantine[PATH_MAX]
) {
    char derived[PATH_MAX];
    char new_derived[PATH_MAX];
    const char *relative = NULL;
    sodium_memzero(derived, sizeof(derived));
    sodium_memzero(new_derived, sizeof(new_derived));
    if (home == NULL || backup == NULL || category == NULL
        || live == NULL || stored == NULL || quarantine == NULL) return -1;
    if (strcmp(category, "state") == 0) relative = "state";
    else if (strcmp(category, "trust") == 0) relative = "trust";
    else if (strcmp(category, "snapshots") == 0) relative = "snapshots";
    else if (strcmp(category, "snapshots-v2") == 0) relative = "snapshots-v2";
    else if (strcmp(category, "snapshot-candidates-v2") == 0) {
        relative = "snapshot-candidates-v2";
    } else if (strcmp(category, "runtime-conf") == 0) relative = "runtime/conf";
    else if (strcmp(category, "runtime-temp") == 0) relative = "runtime/temp";
    else if (strcmp(category, "runtime-shadow") == 0) relative = "runtime/shadow";
    else if (strcmp(category, "runtime-run") == 0) relative = "runtime/run";
    else return -1;
    if (wls_join(live, PATH_MAX, home, relative) != 0
        || wls_join(derived, sizeof(derived), backup, "derived") != 0
        || wls_join(stored, PATH_MAX, derived, category) != 0
        || wls_join(new_derived, sizeof(new_derived), backup, "new-derived") != 0
        || wls_join(quarantine, PATH_MAX, new_derived, category) != 0) {
        sodium_memzero(derived, sizeof(derived));
        sodium_memzero(new_derived, sizeof(new_derived));
        return -1;
    }
    sodium_memzero(derived, sizeof(derived));
    sodium_memzero(new_derived, sizeof(new_derived));
    return 0;
}

static int wls_guardian_reconcile_derived_root(
    const char *live_root,
    const char *quarantine_root,
    const struct wls_guardian_root_authority *authority,
    int test_platform
) {
    char parent[PATH_MAX];
    char leaf[NAME_MAX + 1U];
    char after_image[PATH_MAX];
    char absence_marker[PATH_MAX];
    struct stat status;
    int root_exists;
    int after_image_exists;
    int absence_marker_exists;
    int root_identity;
    int root_matches;
    int recreation_evidence;
    int result = -1;
    sodium_memzero(parent, sizeof(parent));
    sodium_memzero(leaf, sizeof(leaf));
    sodium_memzero(after_image, sizeof(after_image));
    sodium_memzero(absence_marker, sizeof(absence_marker));
    if (live_root == NULL || quarantine_root == NULL || authority == NULL
        || wls_guardian_authority_parent_path(
            live_root, parent, leaf
        ) != 0
        || wls_guardian_authority_identity_at_path(
            parent, authority, 1
        ) != 0
        || wls_guardian_restore_authority_at_path(
            parent, authority, 1, 1, test_platform
        ) != 0
        || wls_join(
            after_image,
            sizeof(after_image),
            quarantine_root,
            ".wls-root-after-image"
        ) != 0
        || wls_join(
            absence_marker,
            sizeof(absence_marker),
            quarantine_root,
            ".wls-root-was-absent"
        ) != 0) goto cleanup;
    after_image_exists = lstat(after_image, &status) == 0;
    if (!after_image_exists && errno != ENOENT) goto cleanup;
    if (after_image_exists
        && (!S_ISDIR(status.st_mode) || S_ISLNK(status.st_mode)
            || wls_guardian_closure_safe(after_image) != 0)) goto cleanup;
    absence_marker_exists = lstat(absence_marker, &status) == 0;
    if (!absence_marker_exists && errno != ENOENT) goto cleanup;
    if (absence_marker_exists
        && wls_guardian_directory_at_path(
            absence_marker, 0700, 1, 0
        ) != 0) goto cleanup;
    if (after_image_exists && absence_marker_exists) goto cleanup;
    recreation_evidence = after_image_exists || absence_marker_exists;
    root_exists = lstat(live_root, &status) == 0;
    if (!root_exists && errno != ENOENT) goto cleanup;
    root_identity = root_exists && authority->present
        && wls_guardian_authority_identity_at_path(
            live_root, authority, 0
        ) == 0;
    if (root_identity
        && wls_guardian_restore_authority_at_path(
            live_root, authority, 0, 1, test_platform
        ) != 0) goto cleanup;
    root_matches = root_exists
        && wls_guardian_authority_at_path(
            live_root,
            authority,
            0,
            recreation_evidence ? 0 : 1,
            test_platform
        ) == 0;
    if (authority->authority_policy[0] == '\0') goto cleanup;
    if (strstr(authority->authority_policy, "-preserve-identity") != NULL) {
        if (!authority->present || recreation_evidence || !root_matches) {
            goto cleanup;
        }
        result = 0;
        goto cleanup;
    }
    if (strstr(authority->authority_policy, "-recreate-sealed") == NULL) {
        goto cleanup;
    }
    if (authority->present && root_exists && !root_matches
        && recreation_evidence) {
        if (!S_ISDIR(status.st_mode) || S_ISLNK(status.st_mode)
            || wls_guardian_restore_authority_at_path(
                live_root, authority, 0, 0, test_platform
            ) != 0) goto cleanup;
        root_matches = 1;
    }
    if (root_exists && !root_matches) {
        if (recreation_evidence || wls_guardian_closure_safe(live_root) != 0
            || wls_guardian_rename_no_replace(
                live_root, after_image
            ) != 0
            || wls_guardian_authority_at_path(
                parent, authority, 1, 1, test_platform
            ) != 0) goto cleanup;
        after_image_exists = 1;
        recreation_evidence = 1;
        root_exists = 0;
    }
    if (!authority->present) {
        if (root_exists || lstat(live_root, &status) == 0 || errno != ENOENT
            || wls_guardian_authority_at_path(
                parent, authority, 1, 1, test_platform
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (!root_exists) {
        if (!recreation_evidence) {
            if (wls_guardian_directory_at_path(
                    absence_marker, 0700, 1, 1
                ) != 0) goto cleanup;
            absence_marker_exists = 1;
            recreation_evidence = 1;
        }
        if (wls_guardian_create_authority_root(
                live_root, authority, test_platform
            ) != 0) goto cleanup;
        root_exists = 1;
    }
    if (!root_exists
        || wls_guardian_authority_at_path(
            live_root,
            authority,
            0,
            recreation_evidence ? 0 : 1,
            test_platform
        ) != 0
        || wls_guardian_authority_at_path(
            parent, authority, 1, 1, test_platform
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(parent, sizeof(parent));
    sodium_memzero(leaf, sizeof(leaf));
    sodium_memzero(after_image, sizeof(after_image));
    sodium_memzero(absence_marker, sizeof(absence_marker));
    return result;
}

static int wls_guardian_derived_root_prevalidate(
    const char *live_root,
    const char *quarantine_root,
    const struct wls_guardian_root_authority *authority,
    int test_platform
) {
    char parent[PATH_MAX];
    char leaf[NAME_MAX + 1U];
    char after_image[PATH_MAX];
    char absence_marker[PATH_MAX];
    struct stat status;
    int root_exists;
    int after_image_exists;
    int absence_marker_exists;
    int exact_identity;
    int recreation_evidence;
    int result = -1;
    sodium_memzero(parent, sizeof(parent));
    sodium_memzero(leaf, sizeof(leaf));
    sodium_memzero(after_image, sizeof(after_image));
    sodium_memzero(absence_marker, sizeof(absence_marker));
    if (live_root == NULL || quarantine_root == NULL || authority == NULL
        || wls_guardian_authority_parent_path(
            live_root, parent, leaf
        ) != 0
        || wls_guardian_authority_identity_at_path(
            parent, authority, 1
        ) != 0
        || wls_join(
            after_image,
            sizeof(after_image),
            quarantine_root,
            ".wls-root-after-image"
        ) != 0
        || wls_join(
            absence_marker,
            sizeof(absence_marker),
            quarantine_root,
            ".wls-root-was-absent"
        ) != 0) goto cleanup;
    after_image_exists = lstat(after_image, &status) == 0;
    if (!after_image_exists && errno != ENOENT) goto cleanup;
    if (after_image_exists
        && (!S_ISDIR(status.st_mode) || S_ISLNK(status.st_mode)
            || wls_guardian_closure_safe(after_image) != 0)) goto cleanup;
    absence_marker_exists = lstat(absence_marker, &status) == 0;
    if (!absence_marker_exists && errno != ENOENT) goto cleanup;
    if (absence_marker_exists
        && wls_guardian_directory_at_path(
            absence_marker, 0700, 1, 0
        ) != 0) goto cleanup;
    if (after_image_exists && absence_marker_exists) goto cleanup;
    recreation_evidence = after_image_exists || absence_marker_exists;
    root_exists = lstat(live_root, &status) == 0;
    if (!root_exists && errno != ENOENT) goto cleanup;
    exact_identity = root_exists && authority->present
        && wls_guardian_authority_identity_at_path(
            live_root, authority, 0
        ) == 0;
    if (strstr(authority->authority_policy, "-preserve-identity") != NULL) {
        result = authority->present && !recreation_evidence && exact_identity
            ? 0
            : -1;
        goto cleanup;
    }
    if (strstr(authority->authority_policy, "-recreate-sealed") == NULL) {
        goto cleanup;
    }
    if (recreation_evidence) {
        if (!authority->present) {
            result = root_exists ? -1 : 0;
            goto cleanup;
        }
        result = !root_exists
            || (S_ISDIR(status.st_mode) && !S_ISLNK(status.st_mode))
            ? 0
            : -1;
        goto cleanup;
    }
    if (!root_exists) {
        result = 0;
        goto cleanup;
    }
    if (exact_identity) {
        result = 0;
        goto cleanup;
    }
    result = wls_guardian_closure_safe(live_root);
cleanup:
    sodium_memzero(parent, sizeof(parent));
    sodium_memzero(leaf, sizeof(leaf));
    sodium_memzero(after_image, sizeof(after_image));
    sodium_memzero(absence_marker, sizeof(absence_marker));
    (void)test_platform;
    return result;
}

static int wls_guardian_derived_prevalidate(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_recovery_inventory *inventory
) {
    size_t index;
    int test_platform;
    if (home == NULL || backup == NULL || request == NULL || inventory == NULL) {
        return -1;
    }
    test_platform = strcmp(request->platform_kind, "test-session") == 0;
    for (index = 0U; index < WLS_GUARDIAN_DERIVED_CATEGORY_COUNT; ++index) {
        const struct wls_guardian_root_authority *authority =
            &inventory->roots[index];
        char live_root[PATH_MAX];
        char stored_root[PATH_MAX];
        char quarantine_root[PATH_MAX];
        sodium_memzero(live_root, sizeof(live_root));
        sodium_memzero(stored_root, sizeof(stored_root));
        sodium_memzero(quarantine_root, sizeof(quarantine_root));
        if (authority->category[0] == '\0'
            || wls_guardian_derived_category_paths(
                home,
                backup,
                authority->category,
                live_root,
                stored_root,
                quarantine_root
            ) != 0
            || wls_guardian_directory_at_path(
                stored_root, 0700, 1, 0
            ) != 0
            || wls_guardian_derived_root_prevalidate(
                live_root,
                quarantine_root,
                authority,
                test_platform
            ) != 0) return -1;
    }
    for (index = 0U; index < inventory->count; ++index) {
        const struct wls_guardian_inventory_entry *entry =
            &inventory->entries[index];
        char live_root[PATH_MAX];
        char stored_root[PATH_MAX];
        char quarantine_root[PATH_MAX];
        char live[PATH_MAX];
        char stored[PATH_MAX];
        struct stat status;
        int stored_exists;
        int live_exists;
        sodium_memzero(live_root, sizeof(live_root));
        sodium_memzero(stored_root, sizeof(stored_root));
        sodium_memzero(quarantine_root, sizeof(quarantine_root));
        sodium_memzero(live, sizeof(live));
        sodium_memzero(stored, sizeof(stored));
        if (wls_guardian_derived_category_paths(
                home,
                backup,
                entry->category,
                live_root,
                stored_root,
                quarantine_root
            ) != 0
            || wls_join(live, sizeof(live), live_root, entry->leaf) != 0
            || wls_join(stored, sizeof(stored), stored_root, entry->leaf) != 0) {
            return -1;
        }
        stored_exists = lstat(stored, &status) == 0;
        if (!stored_exists && errno != ENOENT) return -1;
        live_exists = lstat(live, &status) == 0;
        if (!live_exists && errno != ENOENT) return -1;
        if (stored_exists) {
            if (wls_guardian_closure_digest(
                    stored, entry->kind, entry->closure_sha256
                ) != 0) return -1;
        } else if (strcmp(entry->policy, "restore") == 0 && live_exists) {
            if (wls_guardian_closure_digest(
                    live, entry->kind, entry->closure_sha256
                ) != 0) return -1;
        } else {
            return -1;
        }
    }
    return 0;
}

static int wls_guardian_derived_root_final_verify(
    const char *live_root,
    const char *quarantine_root,
    const struct wls_guardian_root_authority *authority,
    int test_platform
) {
    char parent[PATH_MAX];
    char leaf[NAME_MAX + 1U];
    char after_image[PATH_MAX];
    char absence_marker[PATH_MAX];
    struct stat status;
    int after_image_exists;
    int absence_marker_exists;
    int recreation_evidence;
    int result = -1;
    sodium_memzero(parent, sizeof(parent));
    sodium_memzero(leaf, sizeof(leaf));
    sodium_memzero(after_image, sizeof(after_image));
    sodium_memzero(absence_marker, sizeof(absence_marker));
    if (live_root == NULL || quarantine_root == NULL || authority == NULL
        || wls_guardian_authority_parent_path(
            live_root, parent, leaf
        ) != 0
        || wls_guardian_authority_at_path(
            parent, authority, 1, 1, test_platform
        ) != 0
        || wls_join(
            after_image,
            sizeof(after_image),
            quarantine_root,
            ".wls-root-after-image"
        ) != 0
        || wls_join(
            absence_marker,
            sizeof(absence_marker),
            quarantine_root,
            ".wls-root-was-absent"
        ) != 0) goto cleanup;
    after_image_exists = lstat(after_image, &status) == 0;
    if (!after_image_exists && errno != ENOENT) goto cleanup;
    if (after_image_exists
        && (!S_ISDIR(status.st_mode) || S_ISLNK(status.st_mode)
            || wls_guardian_closure_safe(after_image) != 0)) goto cleanup;
    absence_marker_exists = lstat(absence_marker, &status) == 0;
    if (!absence_marker_exists && errno != ENOENT) goto cleanup;
    if (absence_marker_exists
        && wls_guardian_directory_at_path(
            absence_marker, 0700, 1, 0
        ) != 0) goto cleanup;
    if (after_image_exists && absence_marker_exists) goto cleanup;
    recreation_evidence = after_image_exists || absence_marker_exists;
    if (!authority->present) {
        if (lstat(live_root, &status) == 0 || errno != ENOENT) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_authority_at_path(
            live_root,
            authority,
            0,
            recreation_evidence ? 0 : 1,
            test_platform
        ) != 0) goto cleanup;
    if (strstr(authority->authority_policy, "-preserve-identity") != NULL
        && recreation_evidence) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(parent, sizeof(parent));
    sodium_memzero(leaf, sizeof(leaf));
    sodium_memzero(after_image, sizeof(after_image));
    sodium_memzero(absence_marker, sizeof(absence_marker));
    return result;
}

static int wls_guardian_derived_roots_final_verify(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_recovery_inventory *inventory
) {
    size_t index;
    int test_platform;
    if (home == NULL || backup == NULL || request == NULL || inventory == NULL) {
        return -1;
    }
    test_platform = strcmp(request->platform_kind, "test-session") == 0;
    for (index = 0U; index < WLS_GUARDIAN_DERIVED_CATEGORY_COUNT; ++index) {
        char live_root[PATH_MAX];
        char stored_root[PATH_MAX];
        char quarantine_root[PATH_MAX];
        const struct wls_guardian_root_authority *authority =
            &inventory->roots[index];
        sodium_memzero(live_root, sizeof(live_root));
        sodium_memzero(stored_root, sizeof(stored_root));
        sodium_memzero(quarantine_root, sizeof(quarantine_root));
        if (authority->category[0] == '\0'
            || wls_guardian_derived_category_paths(
                home,
                backup,
                authority->category,
                live_root,
                stored_root,
                quarantine_root
            ) != 0
            || wls_guardian_derived_root_final_verify(
                live_root,
                quarantine_root,
                authority,
                test_platform
            ) != 0) return -1;
    }
    return 0;
}

static int wls_guardian_derived_state_final_verify(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_recovery_inventory *inventory
) {
    size_t root_index;
    int test_platform;
    if (home == NULL || backup == NULL || request == NULL || inventory == NULL
        || wls_guardian_derived_roots_final_verify(
            home, backup, request, inventory
        ) != 0) return -1;
    test_platform = strcmp(request->platform_kind, "test-session") == 0;
    for (root_index = 0U;
        root_index < WLS_GUARDIAN_DERIVED_CATEGORY_COUNT;
        ++root_index) {
        const struct wls_guardian_root_authority *authority =
            &inventory->roots[root_index];
        char live_root[PATH_MAX];
        char stored_root[PATH_MAX];
        char quarantine_root[PATH_MAX];
        char **names = NULL;
        size_t count = 0U;
        size_t index;
        struct stat status;
        sodium_memzero(live_root, sizeof(live_root));
        sodium_memzero(stored_root, sizeof(stored_root));
        sodium_memzero(quarantine_root, sizeof(quarantine_root));
        if (authority->category[0] == '\0'
            || wls_guardian_derived_category_paths(
                home,
                backup,
                authority->category,
                live_root,
                stored_root,
                quarantine_root
            ) != 0) return -1;
        if (!authority->present) {
            if (lstat(live_root, &status) == 0 || errno != ENOENT) return -1;
            for (index = 0U; index < inventory->count; ++index) {
                if (strcmp(
                        inventory->entries[index].category,
                        authority->category
                    ) == 0) return -1;
            }
            continue;
        }
        if (wls_guardian_directory_names(
                live_root, &names, &count
            ) != 0) return -1;
        for (index = 0U; index < count; ++index) {
            const struct wls_guardian_inventory_entry *entry;
            char live[PATH_MAX];
            sodium_memzero(live, sizeof(live));
            if (wls_guardian_derived_preserved(
                    authority->category,
                    names[index],
                    test_platform
                )) continue;
            entry = wls_guardian_inventory_find(
                inventory, authority->category, names[index]
            );
            if (entry == NULL || strcmp(entry->policy, "restore") != 0
                || wls_join(
                    live, sizeof(live), live_root, names[index]
                ) != 0
                || wls_guardian_closure_digest(
                    live, entry->kind, entry->closure_sha256
                ) != 0) {
                wls_guardian_name_list_free(names, count);
                return -1;
            }
        }
        wls_guardian_name_list_free(names, count);
        names = NULL;
        count = 0U;
        for (index = 0U; index < inventory->count; ++index) {
            const struct wls_guardian_inventory_entry *entry =
                &inventory->entries[index];
            char live[PATH_MAX];
            int exists;
            if (strcmp(entry->category, authority->category) != 0) continue;
            sodium_memzero(live, sizeof(live));
            if (wls_join(
                    live, sizeof(live), live_root, entry->leaf
                ) != 0) return -1;
            exists = lstat(live, &status) == 0;
            if (!exists && errno != ENOENT) return -1;
            if (strcmp(entry->policy, "ephemeral") == 0) {
                if (exists) return -1;
            } else if (!exists
                || strcmp(entry->policy, "restore") != 0
                || wls_guardian_closure_digest(
                    live, entry->kind, entry->closure_sha256
                ) != 0) {
                return -1;
            }
        }
    }
    return 0;
}

static int wls_guardian_recovery_roots_verify(
    const char *home,
    const struct wls_guardian_transition_request *request
) {
    struct wls_guardian_recovery_inventory inventory;
    char backup[PATH_MAX];
    int result = -1;
    memset(&inventory, 0, sizeof(inventory));
    sodium_memzero(backup, sizeof(backup));
    if (home == NULL || request == NULL
        || wls_guardian_recovery_evidence_load(
            home, request, backup, &inventory
        ) != 0
        || wls_guardian_derived_state_final_verify(
            home, backup, request, &inventory
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_recovery_inventory_free(&inventory);
    sodium_memzero(backup, sizeof(backup));
    return result;
}

static int wls_guardian_restore_derived_category(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_recovery_inventory *inventory,
    const char *category
) {
    char live_root[PATH_MAX];
    char stored_root[PATH_MAX];
    char quarantine_root[PATH_MAX];
    char new_derived[PATH_MAX];
    char **names = NULL;
    size_t count = 0U;
    size_t index;
    const struct wls_guardian_root_authority *authority = NULL;
    int test_platform;
    int result = -1;
    sodium_memzero(live_root, sizeof(live_root));
    sodium_memzero(stored_root, sizeof(stored_root));
    sodium_memzero(quarantine_root, sizeof(quarantine_root));
    sodium_memzero(new_derived, sizeof(new_derived));
    if (home == NULL || backup == NULL || request == NULL || inventory == NULL
        || category == NULL
        || (authority = wls_guardian_root_authority_find(
            inventory, category
        )) == NULL
        || wls_guardian_derived_category_paths(
            home,
            backup,
            category,
            live_root,
            stored_root,
            quarantine_root
        ) != 0
        || wls_join(new_derived, sizeof(new_derived), backup, "new-derived") != 0
        || wls_guardian_directory_at_path(stored_root, 0700, 1, 0) != 0
        || wls_guardian_directory_at_path(new_derived, 0700, 1, 1) != 0
        || wls_guardian_directory_at_path(quarantine_root, 0700, 1, 1) != 0) {
        goto cleanup;
    }
    test_platform = strcmp(request->platform_kind, "test-session") == 0;
    if (wls_guardian_reconcile_derived_root(
            live_root,
            quarantine_root,
            authority,
            test_platform
        ) != 0) goto cleanup;
    if (!authority->present) {
        if (wls_guardian_directory_names(stored_root, &names, &count) != 0
            || count != 0U) goto cleanup;
        for (index = 0U; index < inventory->count; ++index) {
            if (strcmp(
                    inventory->entries[index].category, category
                ) == 0) goto cleanup;
        }
        wls_guardian_name_list_free(names, count);
        names = NULL;
        count = 0U;
        if (wls_guardian_reconcile_derived_root(
                live_root,
                quarantine_root,
                authority,
                test_platform
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_directory_names(live_root, &names, &count) != 0) {
        goto cleanup;
    }
    for (index = 0U; index < count; ++index) {
        const struct wls_guardian_inventory_entry *entry;
        char live[PATH_MAX];
        char stored[PATH_MAX];
        char quarantine[PATH_MAX];
        struct stat status;
        int stored_exists;
        int quarantine_exists;
        sodium_memzero(live, sizeof(live));
        sodium_memzero(stored, sizeof(stored));
        sodium_memzero(quarantine, sizeof(quarantine));
        if (wls_guardian_derived_preserved(
                category, names[index], test_platform
            )) continue;
        entry = wls_guardian_inventory_find(inventory, category, names[index]);
        if (wls_join(live, sizeof(live), live_root, names[index]) != 0
            || wls_join(stored, sizeof(stored), stored_root, names[index]) != 0
            || wls_join(
                quarantine,
                sizeof(quarantine),
                quarantine_root,
                names[index]
            ) != 0) goto cleanup;
        stored_exists = lstat(stored, &status) == 0;
        if (!stored_exists && errno != ENOENT) goto cleanup;
        if (entry != NULL && strcmp(entry->policy, "restore") == 0
            && !stored_exists
            && wls_guardian_closure_digest(
                live, entry->kind, entry->closure_sha256
            ) == 0) {
            continue;
        }
        quarantine_exists = lstat(quarantine, &status) == 0;
        if ((!quarantine_exists && errno != ENOENT) || quarantine_exists
            || wls_guardian_closure_safe(live) != 0
            || wls_guardian_rename_no_replace(live, quarantine) != 0) {
            goto cleanup;
        }
    }
    wls_guardian_name_list_free(names, count);
    names = NULL;
    count = 0U;
    for (index = 0U; index < inventory->count; ++index) {
        const struct wls_guardian_inventory_entry *entry =
            &inventory->entries[index];
        char live[PATH_MAX];
        char stored[PATH_MAX];
        struct stat status;
        int live_exists;
        int stored_exists;
        if (strcmp(entry->category, category) != 0) continue;
        if (wls_join(live, sizeof(live), live_root, entry->leaf) != 0
            || wls_join(stored, sizeof(stored), stored_root, entry->leaf) != 0) {
            goto cleanup;
        }
        live_exists = lstat(live, &status) == 0;
        if (!live_exists && errno != ENOENT) goto cleanup;
        stored_exists = lstat(stored, &status) == 0;
        if (!stored_exists && errno != ENOENT) goto cleanup;
        if (strcmp(entry->policy, "ephemeral") == 0) {
            if (live_exists || !stored_exists
                || wls_guardian_closure_digest(
                    stored, entry->kind, entry->closure_sha256
                ) != 0) goto cleanup;
            continue;
        }
        if (stored_exists) {
            if (live_exists
                || wls_guardian_closure_digest(
                    stored, entry->kind, entry->closure_sha256
                ) != 0
                || wls_guardian_rename_no_replace(stored, live) != 0) {
                goto cleanup;
            }
        }
        if (wls_guardian_closure_digest(
                live, entry->kind, entry->closure_sha256
            ) != 0) goto cleanup;
    }
    if (wls_guardian_directory_names(live_root, &names, &count) != 0) {
        goto cleanup;
    }
    for (index = 0U; index < count; ++index) {
        const struct wls_guardian_inventory_entry *entry;
        if (wls_guardian_derived_preserved(
                category, names[index], test_platform
            )) continue;
        entry = wls_guardian_inventory_find(inventory, category, names[index]);
        if (entry == NULL || strcmp(entry->policy, "restore") != 0) goto cleanup;
    }
    if (wls_guardian_reconcile_derived_root(
            live_root,
            quarantine_root,
            authority,
            test_platform
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    wls_guardian_name_list_free(names, count);
    sodium_memzero(live_root, sizeof(live_root));
    sodium_memzero(stored_root, sizeof(stored_root));
    sodium_memzero(quarantine_root, sizeof(quarantine_root));
    sodium_memzero(new_derived, sizeof(new_derived));
    return result;
}

static int wls_guardian_slot_state(
    const char *path,
    char slot,
    const char *runtime_generation,
    const char *ca_sha256
) {
    struct stat status;
    if (path == NULL || runtime_generation == NULL || ca_sha256 == NULL) {
        return -1;
    }
    if (lstat(path, &status) != 0) return errno == ENOENT ? 1 : -1;
    return wls_guardian_slot_contract_at(
        path, slot, runtime_generation, ca_sha256
    ) == 0 ? 0 : -1;
}

static int wls_guardian_runtime_paths(
    const char *home,
    const char *backup,
    const char *nonce,
    char candidate[PATH_MAX],
    char new_generation[PATH_MAX],
    char new_slots[PATH_MAX]
) {
    char candidates[PATH_MAX];
    sodium_memzero(candidates, sizeof(candidates));
    if (home == NULL || backup == NULL || nonce == NULL
        || candidate == NULL || new_generation == NULL || new_slots == NULL
        || wls_join(candidates, sizeof(candidates), home, "rebootstrap/candidates") != 0
        || wls_join(candidate, PATH_MAX, candidates, nonce) != 0
        || wls_join(new_generation, PATH_MAX, backup, "new-generation") != 0
        || wls_join(new_slots, PATH_MAX, new_generation, "slots") != 0) {
        sodium_memzero(candidates, sizeof(candidates));
        return -1;
    }
    sodium_memzero(candidates, sizeof(candidates));
    return 0;
}

static int wls_guardian_runtime_prevalidate(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request
) {
    char candidate[PATH_MAX];
    char new_generation[PATH_MAX];
    char new_slots[PATH_MAX];
    char quarantined_candidate[PATH_MAX];
    char backup_slots[PATH_MAX];
    char live_slots[PATH_MAX];
    char path[PATH_MAX];
    char live_path[PATH_MAX];
    char text[80];
    char digest[65];
    unsigned long long text_size = 0ULL;
    struct stat status;
    const char slots[] = {'A', 'B'};
    size_t index;
    int state;
    int result = -1;
    sodium_memzero(candidate, sizeof(candidate));
    sodium_memzero(new_generation, sizeof(new_generation));
    sodium_memzero(new_slots, sizeof(new_slots));
    sodium_memzero(quarantined_candidate, sizeof(quarantined_candidate));
    sodium_memzero(backup_slots, sizeof(backup_slots));
    sodium_memzero(live_slots, sizeof(live_slots));
    sodium_memzero(path, sizeof(path));
    sodium_memzero(live_path, sizeof(live_path));
    sodium_memzero(text, sizeof(text));
    sodium_memzero(digest, sizeof(digest));
    if (home == NULL || backup == NULL || request == NULL
        || wls_guardian_runtime_paths(
            home,
            backup,
            request->nonce,
            candidate,
            new_generation,
            new_slots
        ) != 0
        || wls_join(
            quarantined_candidate,
            sizeof(quarantined_candidate),
            new_generation,
            "candidate"
        ) != 0
        || wls_join(backup_slots, sizeof(backup_slots), backup, "slots") != 0
        || wls_join(live_slots, sizeof(live_slots), home, "slots") != 0) {
        goto cleanup;
    }
    state = wls_guardian_slot_state(
        candidate,
        'A',
        request->candidate_runtime_generation,
        request->candidate_ca_sha256
    );
    if (state < 0) goto cleanup;
    if (lstat(quarantined_candidate, &status) == 0) {
        if (state == 0 || wls_guardian_slot_state(
                quarantined_candidate,
                'A',
                request->candidate_runtime_generation,
                request->candidate_ca_sha256
            ) != 0) goto cleanup;
    } else if (errno != ENOENT) goto cleanup;
    for (index = 0U; index < sizeof(slots); ++index) {
        char slot_name[2] = {slots[index], '\0'};
        char stored[PATH_MAX];
        char live[PATH_MAX];
        const char *old_generation = slots[index] == 'A'
            ? request->recovery_slot_a_generation
            : request->recovery_slot_b_generation;
        int stored_state;
        int live_old_state;
        if (wls_join(stored, sizeof(stored), backup_slots, slot_name) != 0
            || wls_join(live, sizeof(live), live_slots, slot_name) != 0) {
            goto cleanup;
        }
        if (wls_all_zero_hex(old_generation)) {
            if (lstat(stored, &status) == 0 || errno != ENOENT) goto cleanup;
            if (lstat(live, &status) == 0
                && (slots[index] != 'A'
                    || wls_guardian_slot_state(
                        live,
                        'A',
                        request->candidate_runtime_generation,
                        request->candidate_ca_sha256
                    ) != 0)) goto cleanup;
            if (lstat(live, &status) != 0 && errno != ENOENT) goto cleanup;
            continue;
        }
        stored_state = wls_guardian_slot_state(
            stored, slots[index], old_generation, request->recovery_ca_sha256
        );
        live_old_state = wls_guardian_slot_state(
            live, slots[index], old_generation, request->recovery_ca_sha256
        );
        if (stored_state == 1 && live_old_state == 0) continue;
        if (stored_state != 0) goto cleanup;
        if (live_old_state == 1) continue;
        if (live_old_state == 0) goto cleanup;
        if (slots[index] != 'A'
            || wls_guardian_slot_state(
                live,
                'A',
                request->candidate_runtime_generation,
                request->candidate_ca_sha256
            ) != 0) goto cleanup;
    }
    if (snprintf(
            text, sizeof(text), "%s\n", request->recovery_launcher_sha256
        ) <= 0
        || wls_guardian_text_digest(text, digest, &text_size) != 0
        || wls_join(
            path,
            sizeof(path),
            backup,
            "trust/stable-launcher.sha256"
        ) != 0
        || wls_join(
            live_path,
            sizeof(live_path),
            home,
            "trust/stable-launcher.sha256"
        ) != 0) goto cleanup;
    {
        int stored_identity = wls_guardian_regular_state(
            path, digest, text_size, 0600
        );
        int live_identity = wls_guardian_regular_state(
            live_path, digest, text_size, 0600
        );
        if (!((stored_identity == 0 && live_identity != 0)
            || (stored_identity == 1 && live_identity == 0)
            || (stored_identity == 0 && live_identity == 0
                && sodium_memcmp(
                    request->recovery_launcher_sha256,
                    request->candidate_launcher_sha256,
                    64U
                ) == 0))) goto cleanup;
    }
    snprintf(text, sizeof(text), "%s\n", request->recovery_active_slot);
    if (wls_guardian_text_digest(text, digest, &text_size) != 0
        || wls_join(path, sizeof(path), backup, "trust/active-slot") != 0
        || wls_join(live_path, sizeof(live_path), home, "trust/active-slot") != 0) {
        goto cleanup;
    }
    {
        int stored_active = wls_guardian_regular_state(
            path, digest, text_size, 0640
        );
        int live_active = wls_guardian_regular_state(
            live_path, digest, text_size, 0640
        );
        if (!((stored_active == 0 && live_active != 0)
            || (stored_active == 1 && live_active == 0)
            || (stored_active == 0 && live_active == 0
                && request->recovery_active_slot[0] == 'A'))) goto cleanup;
    }
    if (strcmp(request->recovery_previous_slot, "NONE") == 0) {
        if (wls_join(path, sizeof(path), backup, "trust/previous-slot") != 0
            || wls_join(
                live_path, sizeof(live_path), home, "trust/previous-slot"
            ) != 0
            || lstat(path, &status) == 0 || errno != ENOENT
            || lstat(live_path, &status) == 0 || errno != ENOENT) goto cleanup;
    } else {
        snprintf(text, sizeof(text), "%s\n", request->recovery_previous_slot);
        if (wls_guardian_text_digest(text, digest, &text_size) != 0
            || wls_join(path, sizeof(path), backup, "trust/previous-slot") != 0
            || wls_join(
                live_path, sizeof(live_path), home, "trust/previous-slot"
            ) != 0) goto cleanup;
        {
            int stored_previous = wls_guardian_regular_state(
                path, digest, text_size, 0640
            );
            int live_previous = wls_guardian_regular_state(
                live_path, digest, text_size, 0640
            );
            if (!((stored_previous == 0 && live_previous != 0)
                || (stored_previous == 1 && live_previous == 0))) goto cleanup;
        }
    }
    result = 0;
cleanup:
    sodium_memzero(candidate, sizeof(candidate));
    sodium_memzero(new_generation, sizeof(new_generation));
    sodium_memzero(new_slots, sizeof(new_slots));
    sodium_memzero(quarantined_candidate, sizeof(quarantined_candidate));
    sodium_memzero(backup_slots, sizeof(backup_slots));
    sodium_memzero(live_slots, sizeof(live_slots));
    sodium_memzero(path, sizeof(path));
    sodium_memzero(live_path, sizeof(live_path));
    sodium_memzero(text, sizeof(text));
    sodium_memzero(digest, sizeof(digest));
    return result;
}

static int wls_guardian_restore_candidate_directory(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request
) {
    char candidate[PATH_MAX];
    char new_generation[PATH_MAX];
    char new_slots[PATH_MAX];
    char quarantine[PATH_MAX];
    int source_state;
    int target_state;
    if (wls_guardian_runtime_paths(
            home,
            backup,
            request->nonce,
            candidate,
            new_generation,
            new_slots
        ) != 0
        || wls_join(quarantine, sizeof(quarantine), new_generation, "candidate") != 0
        || wls_guardian_directory_at_path(new_generation, 0700, 1, 1) != 0) {
        return -1;
    }
    source_state = wls_guardian_slot_state(
        candidate,
        'A',
        request->candidate_runtime_generation,
        request->candidate_ca_sha256
    );
    target_state = wls_guardian_slot_state(
        quarantine,
        'A',
        request->candidate_runtime_generation,
        request->candidate_ca_sha256
    );
    if (source_state == 1 && (target_state == 0 || target_state == 1)) return 0;
    if (source_state != 0 || target_state != 1
        || wls_guardian_rename_no_replace(candidate, quarantine) != 0
        || wls_guardian_slot_state(
            quarantine,
            'A',
            request->candidate_runtime_generation,
            request->candidate_ca_sha256
        ) != 0) return -1;
    return 0;
}

static int wls_guardian_restore_slot(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    char slot
) {
    char candidate[PATH_MAX];
    char new_generation[PATH_MAX];
    char new_slots[PATH_MAX];
    char backup_slots[PATH_MAX];
    char live_slots[PATH_MAX];
    char slot_name[2] = {slot, '\0'};
    char stored[PATH_MAX];
    char live[PATH_MAX];
    char quarantine[PATH_MAX];
    const char *old_generation;
    int stored_state;
    int live_old_state;
    int quarantine_state;
    if ((slot != 'A' && slot != 'B')
        || wls_guardian_runtime_paths(
            home,
            backup,
            request->nonce,
            candidate,
            new_generation,
            new_slots
        ) != 0
        || wls_join(backup_slots, sizeof(backup_slots), backup, "slots") != 0
        || wls_join(live_slots, sizeof(live_slots), home, "slots") != 0
        || wls_join(stored, sizeof(stored), backup_slots, slot_name) != 0
        || wls_join(live, sizeof(live), live_slots, slot_name) != 0
        || wls_join(quarantine, sizeof(quarantine), new_slots, slot_name) != 0
        || wls_guardian_directory_at_path(new_generation, 0700, 1, 1) != 0
        || wls_guardian_directory_at_path(new_slots, 0700, 1, 1) != 0) {
        return -1;
    }
    old_generation = slot == 'A'
        ? request->recovery_slot_a_generation
        : request->recovery_slot_b_generation;
    if (wls_all_zero_hex(old_generation)) {
        struct stat status;
        if (lstat(stored, &status) == 0 || errno != ENOENT) return -1;
        if (slot != 'A') {
            if (lstat(live, &status) == 0 || errno != ENOENT) return -1;
            return 0;
        }
        stored_state = wls_guardian_slot_state(
            live,
            'A',
            request->candidate_runtime_generation,
            request->candidate_ca_sha256
        );
        quarantine_state = wls_guardian_slot_state(
            quarantine,
            'A',
            request->candidate_runtime_generation,
            request->candidate_ca_sha256
        );
        if (stored_state == 1 && (quarantine_state == 0 || quarantine_state == 1)) {
            return 0;
        }
        if (stored_state != 0 || quarantine_state != 1
            || wls_guardian_rename_no_replace(live, quarantine) != 0) return -1;
        return wls_guardian_slot_state(
            quarantine,
            'A',
            request->candidate_runtime_generation,
            request->candidate_ca_sha256
        );
    }
    stored_state = wls_guardian_slot_state(
        stored, slot, old_generation, request->recovery_ca_sha256
    );
    live_old_state = wls_guardian_slot_state(
        live, slot, old_generation, request->recovery_ca_sha256
    );
    if (stored_state == 1 && live_old_state == 0) return 0;
    if (stored_state != 0 || live_old_state == 0) return -1;
    if (live_old_state < 0) {
        if (slot != 'A'
            || wls_guardian_slot_state(
                live,
                'A',
                request->candidate_runtime_generation,
                request->candidate_ca_sha256
            ) != 0) return -1;
        quarantine_state = wls_guardian_slot_state(
            quarantine,
            'A',
            request->candidate_runtime_generation,
            request->candidate_ca_sha256
        );
        if (quarantine_state != 1
            || wls_guardian_rename_no_replace(live, quarantine) != 0) return -1;
    }
    if (wls_guardian_rename_no_replace(stored, live) != 0) return -1;
    return wls_guardian_slot_state(
        live, slot, old_generation, request->recovery_ca_sha256
    );
}

static int wls_guardian_text_digest(
    const char *text,
    char digest[65],
    unsigned long long *size
) {
    size_t length;
    if (text == NULL || digest == NULL || size == NULL) return -1;
    length = strlen(text);
    if (length == 0U
        || wls_sha256_text(
            (const unsigned char *)text, length, digest
        ) != 0) return -1;
    *size = (unsigned long long)length;
    return 0;
}

static int wls_guardian_restore_runtime_regular(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    unsigned int operation
) {
    char stored[PATH_MAX];
    char target[PATH_MAX];
    char old_text[80];
    char candidate_text[80];
    char old_digest[65];
    char candidate_digest[65];
    unsigned long long old_size = 0ULL;
    unsigned long long candidate_size = 0ULL;
    mode_t mode;
    sodium_memzero(stored, sizeof(stored));
    sodium_memzero(target, sizeof(target));
    sodium_memzero(old_text, sizeof(old_text));
    sodium_memzero(candidate_text, sizeof(candidate_text));
    sodium_memzero(old_digest, sizeof(old_digest));
    sodium_memzero(candidate_digest, sizeof(candidate_digest));
    if (operation == 3U) {
        if (wls_join(stored, sizeof(stored), backup, "bin/launcher") != 0
            || wls_join(
                target,
                sizeof(target),
                home,
                "bin/wls-gateway-launcher"
            ) != 0) return -1;
        return wls_guardian_restore_regular(
            stored,
            target,
            request->recovery_launcher_sha256,
            request->recovery_launcher_size,
            (mode_t)request->recovery_launcher_mode,
            request->candidate_launcher_sha256,
            request->candidate_launcher_size,
            (mode_t)request->candidate_launcher_mode
        );
    }
    mode = operation == 4U ? 0600 : 0640;
    if (operation == 4U) {
        snprintf(old_text, sizeof(old_text), "%s\n", request->recovery_launcher_sha256);
        snprintf(
            candidate_text,
            sizeof(candidate_text),
            "%s\n",
            request->candidate_launcher_sha256
        );
        if (wls_join(
                stored,
                sizeof(stored),
                backup,
                "trust/stable-launcher.sha256"
            ) != 0
            || wls_join(
                target,
                sizeof(target),
                home,
                "trust/stable-launcher.sha256"
            ) != 0) return -1;
    } else if (operation == 5U) {
        snprintf(old_text, sizeof(old_text), "%s\n", request->recovery_active_slot);
        snprintf(candidate_text, sizeof(candidate_text), "A\n");
        if (wls_join(stored, sizeof(stored), backup, "trust/active-slot") != 0
            || wls_join(target, sizeof(target), home, "trust/active-slot") != 0) {
            return -1;
        }
    } else {
        return -1;
    }
    if (wls_guardian_text_digest(old_text, old_digest, &old_size) != 0
        || wls_guardian_text_digest(
            candidate_text, candidate_digest, &candidate_size
        ) != 0) return -1;
    return wls_guardian_restore_regular(
        stored,
        target,
        old_digest,
        old_size,
        mode,
        candidate_digest,
        candidate_size,
        mode
    );
}

static int wls_guardian_restore_previous_slot(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request
) {
    char stored[PATH_MAX];
    char target[PATH_MAX];
    char text[4];
    char digest[65];
    unsigned long long size = 0ULL;
    struct stat status;
    int stored_state;
    int target_state;
    if (wls_join(stored, sizeof(stored), backup, "trust/previous-slot") != 0
        || wls_join(target, sizeof(target), home, "trust/previous-slot") != 0) {
        return -1;
    }
    if (strcmp(request->recovery_previous_slot, "NONE") == 0) {
        if (lstat(stored, &status) == 0 || errno != ENOENT) return -1;
        if (lstat(target, &status) == 0 || errno != ENOENT) return -1;
        return 0;
    }
    snprintf(text, sizeof(text), "%s\n", request->recovery_previous_slot);
    if (wls_guardian_text_digest(text, digest, &size) != 0) return -1;
    stored_state = wls_guardian_regular_state(stored, digest, size, 0640);
    target_state = wls_guardian_regular_state(target, digest, size, 0640);
    if (stored_state == 1 && target_state == 0) return 0;
    if (stored_state != 0 || target_state != 1
        || wls_guardian_rename_no_replace(stored, target) != 0) return -1;
    return wls_guardian_regular_state(target, digest, size, 0640);
}

static int wls_guardian_platform_target(
    const char *home,
    const struct wls_guardian_transition_request *request,
    char definition[PATH_MAX],
    mode_t *definition_mode
) {
    if (home == NULL || request == NULL || definition == NULL
        || definition_mode == NULL) return -1;
    if (strcmp(request->platform_kind, "test-session") == 0) {
        *definition_mode = 0600;
        return wls_join(
            definition, PATH_MAX, home, "state/service-definition.test"
        );
    }
    *definition_mode = 0644;
    if (strcmp(request->platform_kind, "launchd-system") == 0) {
        int length = snprintf(
            definition,
            PATH_MAX,
            "/Library/LaunchDaemons/com.weline.wls-gateway-v2.plist"
        );
        return length > 0 && length < PATH_MAX ? 0 : -1;
    }
    if (strcmp(request->platform_kind, "systemd-system") == 0) {
        int length = snprintf(
            definition,
            PATH_MAX,
            "/etc/weline-gateway/weline-wls-gateway-v2.service"
        );
        return length > 0 && length < PATH_MAX ? 0 : -1;
    }
    return -1;
}

/* The Guardian may atomically replace only the mutable definition below
 * /etc/weline-gateway.  The systemd search-path entry is deliberately a
 * fixed, installer-owned link: treating it as mutable would violate the
 * unit's ProtectSystem=strict sandbox and would allow a foreign unit to be
 * substituted during rollback recovery. */
static int wls_guardian_systemd_fixed_link_verify(
    const char *definition
) {
    static const char expected_definition[] =
        "/etc/weline-gateway/weline-wls-gateway-v2.service";
    static const char canonical_link[] =
        "/etc/systemd/system/weline-wls-gateway-v2.service";
    static const char canonical_parent[] = "/etc/systemd/system";
    char linked_target[PATH_MAX];
    struct stat link_before;
    struct stat link_after;
    struct stat parent_before;
    struct stat parent_opened;
    struct stat parent_after;
    ssize_t linked_length;
    int parent_fd = -1;
    int result = -1;
    sodium_memzero(linked_target, sizeof(linked_target));
    if (definition == NULL
        || strcmp(definition, expected_definition) != 0
        || lstat(canonical_link, &link_before) != 0
        || !S_ISLNK(link_before.st_mode)
        || link_before.st_uid != geteuid()
        || link_before.st_gid != getegid()
        || lstat(canonical_parent, &parent_before) != 0
        || !S_ISDIR(parent_before.st_mode)
        || S_ISLNK(parent_before.st_mode)
        || parent_before.st_uid != geteuid()
        || parent_before.st_gid != getegid()
        || (parent_before.st_mode & 0022) != 0) goto cleanup;
    parent_fd = open(
        canonical_parent,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (parent_fd < 0 || fstat(parent_fd, &parent_opened) != 0
        || !wls_launcher_same_file_state(&parent_before, &parent_opened)
        || wls_guardian_acl_free_fd(parent_fd, 0) != 0) goto cleanup;
    linked_length = readlink(
        canonical_link,
        linked_target,
        sizeof(linked_target) - 1U
    );
    if (linked_length < 0
        || (size_t)linked_length != strlen(expected_definition)) goto cleanup;
    linked_target[linked_length] = '\0';
    if (strcmp(linked_target, expected_definition) != 0
        || lstat(canonical_link, &link_after) != 0
        || !wls_launcher_same_file_state(&link_before, &link_after)
        || lstat(canonical_parent, &parent_after) != 0
        || !wls_launcher_same_file_state(&parent_opened, &parent_after)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    sodium_memzero(linked_target, sizeof(linked_target));
    return result;
}

typedef int (*wls_guardian_atomic_document_validator)(
    const char *,
    const char *,
    size_t,
    void *
);

static int wls_guardian_atomic_document_read(
    const char *path,
    mode_t mode,
    size_t maximum,
    char **contents,
    size_t *length
) {
    struct stat status;
    if (path == NULL || contents == NULL || length == NULL) return -1;
    *contents = NULL;
    *length = 0U;
    if (lstat(path, &status) != 0) return errno == ENOENT ? 1 : -1;
    return wls_guardian_read_bounded_regular(
        path, maximum, mode, contents, length
    );
}

static int wls_guardian_atomic_cleanup_artifacts(
    const char *path,
    mode_t mode,
    size_t maximum
) {
    struct wls_guardian_atomic_inventory inventory;
    unsigned int removed = 0U;
    int result = -1;
    memset(&inventory, 0, sizeof(inventory));
    while (removed < 2U) {
        if (wls_guardian_atomic_inventory_load(
                path, mode, maximum, &inventory
            ) != 0) goto cleanup;
        if (inventory.temporary.present) {
            if (wls_guardian_atomic_artifact_remove(
                    &inventory, &inventory.temporary
                ) != 0) goto cleanup;
            ++removed;
            memset(&inventory, 0, sizeof(inventory));
            continue;
        }
        if (inventory.backup.present) {
            goto cleanup;
        }
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_atomic_inventory_load(
            path, mode, maximum, &inventory
        ) == 0
        && !inventory.temporary.present
        && !inventory.backup.present) result = 0;
cleanup:
    sodium_memzero(&inventory, sizeof(inventory));
    return result;
}

/* The caller holds guardian-generation-head.lock. A committed target wins
 * over a retained pre-rename staging file once its complete semantic binding
 * has been revalidated. A missing target may accept only one exact staging
 * after-image; ReplaceFileW backup names are never authoritative on POSIX. */
static int wls_guardian_atomic_reconcile_document(
    const char *path,
    mode_t mode,
    size_t maximum,
    wls_guardian_atomic_document_validator target_validator,
    wls_guardian_atomic_document_validator staging_validator,
    void *target_context,
    void *staging_context,
    int discard_invalid_first_publication
) {
    struct wls_guardian_atomic_inventory inventory;
    char *contents = NULL;
    size_t length = 0U;
    int target_state;
    int staging_state;
    int result = -1;
    memset(&inventory, 0, sizeof(inventory));
    if (path == NULL || target_validator == NULL
        || staging_validator == NULL
        || (discard_invalid_first_publication != 0
            && discard_invalid_first_publication != 1)
        || wls_guardian_atomic_inventory_load(
            path, mode, maximum, &inventory
        ) != 0) goto cleanup;
    if (!inventory.temporary.present && !inventory.backup.present) {
        result = 0;
        goto cleanup;
    }
    if (inventory.backup.present) goto cleanup;
    target_state = wls_guardian_atomic_document_read(
        path, mode, maximum, &contents, &length
    );
    if (target_state == 0) {
        if (target_validator(
                path, contents, length, target_context
            ) != 0
            || wls_guardian_atomic_cleanup_artifacts(
                path, mode, maximum
            ) != 0) goto cleanup;
        result = 0;
        goto cleanup;
    }
    if (target_state != 1 || inventory.backup.present) goto cleanup;
    if (!inventory.temporary.present) {
        result = 0;
        goto cleanup;
    }
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
        contents = NULL;
        length = 0U;
    }
    staging_state = wls_guardian_atomic_document_read(
        inventory.temporary.path,
        mode,
        maximum,
        &contents,
        &length
    );
    if (staging_state == 0
        && staging_validator(
            inventory.temporary.path,
            contents,
            length,
            staging_context
        ) == 0) {
        if (wls_guardian_atomic_artifact_promote(
                &inventory, mode
            ) != 0) goto cleanup;
        sodium_memzero(contents, length);
        free(contents);
        contents = NULL;
        length = 0U;
        if (wls_guardian_atomic_document_read(
                path, mode, maximum, &contents, &length
            ) != 0
            || target_validator(
                path, contents, length, target_context
            ) != 0
            || wls_guardian_atomic_companions_absent(path) != 0) {
            goto cleanup;
        }
        result = 0;
        goto cleanup;
    }
    if (discard_invalid_first_publication
        && wls_guardian_atomic_artifact_remove(
            &inventory, &inventory.temporary
        ) == 0
        && wls_guardian_atomic_companions_absent(path) == 0) {
        result = 0;
    }
cleanup:
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    sodium_memzero(&inventory, sizeof(inventory));
    return result;
}

struct wls_guardian_head_validator_context {
    const char *home;
    int slot;
};

static int wls_guardian_head_document_validator(
    const char *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_head_validator_context *context = opaque;
    struct wls_guardian_generation_head head;
    int result;
    (void)path;
    memset(&head, 0, sizeof(head));
    if (context == NULL || context->home == NULL) return -1;
    result = wls_guardian_head_parse(
        context->home, contents, length, context->slot, &head
    );
    sodium_memzero(&head, sizeof(head));
    return result;
}

static int wls_guardian_head_path_read(
    const char *home,
    int slot,
    struct wls_guardian_generation_head *head
) {
    char path[PATH_MAX];
    char *contents = NULL;
    size_t length = 0U;
    int state;
    int result = -1;
    sodium_memzero(path, sizeof(path));
    if (home == NULL || head == NULL || (slot != 0 && slot != 1)
        || snprintf(
            path,
            sizeof(path),
            "%s/trust/guardian-generation-head.%d",
            home,
            slot
        ) >= (int)sizeof(path)) goto cleanup;
    state = wls_guardian_atomic_document_read(
        path, 0600, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &contents, &length
    );
    if (state == 1) {
        result = 1;
    } else if (state == 0
        && wls_guardian_head_parse(
            home, contents, length, slot, head
        ) == 0) {
        result = 0;
    }
cleanup:
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    sodium_memzero(path, sizeof(path));
    if (result != 0 && head != NULL) sodium_memzero(head, sizeof(*head));
    return result;
}

static int wls_guardian_initial_head_valid(
    const char *home,
    const struct wls_guardian_generation_head *head
) {
    static const char zeros[] =
        "0000000000000000000000000000000000000000000000000000000000000000";
    char host_id[34];
    size_t length = 0U;
    int result = -1;
    sodium_memzero(host_id, sizeof(host_id));
    if (home != NULL && head != NULL && head->sequence == 1ULL
        && strcmp(head->phase, "STABLE") == 0
        && sodium_memcmp(head->previous_record_sha256, zeros, 64U) == 0
        && wls_recovery_read_controller_trust_file(
            home,
            "host-id",
            geteuid(),
            host_id,
            sizeof(host_id),
            &length
        ) == 0
        && (length == 32U || (length == 33U && host_id[32] == '\n'))
        && sodium_memcmp(host_id, head->host_id, 32U) == 0) result = 0;
    sodium_memzero(host_id, sizeof(host_id));
    return result;
}

static int wls_guardian_head_staging_reconcile(
    const char *home,
    int slot,
    const struct wls_guardian_generation_head *other,
    int other_valid
) {
    struct wls_guardian_atomic_inventory inventory;
    struct wls_guardian_generation_head staged;
    struct wls_guardian_generation_head published;
    char path[PATH_MAX];
    char *contents = NULL;
    size_t length = 0U;
    int valid = 0;
    int result = -1;
    memset(&inventory, 0, sizeof(inventory));
    memset(&staged, 0, sizeof(staged));
    memset(&published, 0, sizeof(published));
    sodium_memzero(path, sizeof(path));
    if (home == NULL || (slot != 0 && slot != 1)
        || snprintf(
            path,
            sizeof(path),
            "%s/trust/guardian-generation-head.%d",
            home,
            slot
        ) >= (int)sizeof(path)
        || wls_guardian_atomic_inventory_load(
            path, 0600, WLS_GUARDIAN_DOCUMENT_MAX_BYTES, &inventory
        ) != 0) goto cleanup;
    if (!inventory.temporary.present && !inventory.backup.present) {
        result = 0;
        goto cleanup;
    }
    if (inventory.backup.present || !inventory.temporary.present
        || wls_guardian_atomic_document_read(
            inventory.temporary.path,
            0600,
            WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
            &contents,
            &length
        ) != 0
        || wls_guardian_head_parse(
            home, contents, length, slot, &staged
        ) != 0) {
        if (other_valid && !inventory.backup.present
            && inventory.temporary.present
            && wls_guardian_atomic_artifact_remove(
                &inventory, &inventory.temporary
            ) == 0
            && wls_guardian_atomic_companions_absent(path) == 0) {
            result = 0;
        }
        goto cleanup;
    }
    if (other_valid) {
        valid = other != NULL
            && staged.sequence == other->sequence + 1ULL
            && sodium_memcmp(staged.host_id, other->host_id, 32U) == 0
            && sodium_memcmp(
                staged.previous_record_sha256,
                other->raw_sha256,
                64U
            ) == 0
            && wls_guardian_head_transition_valid(other, &staged);
    } else {
        valid = wls_guardian_initial_head_valid(home, &staged) == 0;
    }
    if (!valid
        || wls_guardian_atomic_artifact_promote(&inventory, 0600) != 0
        || wls_guardian_head_path_read(home, slot, &published) != 0
        || sodium_memcmp(
            published.raw_sha256, staged.raw_sha256, 64U
        ) != 0
        || wls_guardian_atomic_companions_absent(path) != 0) goto cleanup;
    result = 0;
cleanup:
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    sodium_memzero(&inventory, sizeof(inventory));
    sodium_memzero(&staged, sizeof(staged));
    sodium_memzero(&published, sizeof(published));
    sodium_memzero(path, sizeof(path));
    return result;
}

static int wls_guardian_heads_reconcile_locked(const char *home)
{
    struct wls_guardian_generation_head heads[2];
    struct wls_guardian_head_validator_context contexts[2];
    char paths[2][PATH_MAX];
    int states[2];
    int slot;
    int result = -1;
    memset(heads, 0, sizeof(heads));
    memset(contexts, 0, sizeof(contexts));
    sodium_memzero(paths, sizeof(paths));
    if (home == NULL) goto cleanup;
    for (slot = 0; slot < 2; ++slot) {
        contexts[slot].home = home;
        contexts[slot].slot = slot;
        if (snprintf(
                paths[slot],
                sizeof(paths[slot]),
                "%s/trust/guardian-generation-head.%d",
                home,
                slot
            ) >= (int)sizeof(paths[slot])) goto cleanup;
        states[slot] = wls_guardian_head_path_read(
            home, slot, &heads[slot]
        );
        if (states[slot] == 0
            && wls_guardian_atomic_reconcile_document(
                paths[slot],
                0600,
                WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
                wls_guardian_head_document_validator,
                wls_guardian_head_document_validator,
                &contexts[slot],
                &contexts[slot],
                0
            ) != 0) goto cleanup;
    }
    for (slot = 0; slot < 2; ++slot) {
        states[slot] = wls_guardian_head_path_read(
            home, slot, &heads[slot]
        );
    }
    for (slot = 0; slot < 2; ++slot) {
        if (states[slot] == 1) {
            if (wls_guardian_head_staging_reconcile(
                    home,
                    slot,
                    &heads[1 - slot],
                    states[1 - slot] == 0
                ) != 0) goto cleanup;
            states[slot] = wls_guardian_head_path_read(
                home, slot, &heads[slot]
            );
        } else if (states[slot] != 0) {
            goto cleanup;
        }
    }
    if (states[0] != 0 && states[1] != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(heads, sizeof(heads));
    sodium_memzero(contexts, sizeof(contexts));
    sodium_memzero(paths, sizeof(paths));
    return result;
}

struct wls_guardian_request_validator_context {
    const char *home;
    const struct wls_guardian_generation_head *head;
    int require_head_binding;
};

static int wls_guardian_request_document_validator(
    const char *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_request_validator_context *context = opaque;
    struct wls_guardian_transition_request request;
    int result = -1;
    (void)path;
    memset(&request, 0, sizeof(request));
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
    sodium_memzero(&request, sizeof(request));
    return result;
}

struct wls_guardian_evidence_validator_context {
    const char *home;
    const char *backup;
    const struct wls_guardian_transition_request *request;
    int inventory;
};

static int wls_guardian_evidence_document_validator(
    const char *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_evidence_validator_context *context = opaque;
    struct wls_guardian_recovery_inventory inventory;
    int result = -1;
    (void)contents;
    (void)length;
    memset(&inventory, 0, sizeof(inventory));
    if (context == NULL || context->home == NULL || context->backup == NULL
        || context->request == NULL) goto cleanup;
    if (context->inventory) {
        if (wls_guardian_recovery_inventory_load_path(
                context->home,
                context->backup,
                context->request,
                path,
                &inventory
            ) == 0) result = 0;
    } else if (wls_guardian_recovery_authorization_verify_path(
            context->home, path, context->request
        ) == 0) {
        result = 0;
    }
cleanup:
    wls_guardian_recovery_inventory_free(&inventory);
    return result;
}

struct wls_guardian_transaction_validator_context {
    const char *home;
    const struct wls_guardian_transition_request *request;
    int require_initial;
};

static int wls_guardian_transaction_document_validator(
    const char *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_transaction_validator_context *context = opaque;
    struct wls_guardian_recovery_transaction transaction;
    int result = -1;
    (void)path;
    memset(&transaction, 0, sizeof(transaction));
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
    sodium_memzero(&transaction, sizeof(transaction));
    return result;
}

struct wls_guardian_ack_validator_context {
    const char *home;
    const struct wls_guardian_transition_request *request;
    const struct wls_guardian_generation_head *head;
    int require_current_head;
};

static int wls_guardian_ack_document_validator(
    const char *path,
    const char *contents,
    size_t length,
    void *opaque
) {
    struct wls_guardian_ack_validator_context *context = opaque;
    char host_id[33];
    char nonce[33];
    char request_sha256[65];
    char sequence[21];
    char head_sha256[65];
    char purpose[9];
    char generation[65];
    char signature[65];
    const char *signature_line;
    const char *expected_generation;
    char *end = NULL;
    unsigned long long parsed_sequence;
    int consumed = 0;
    int fields;
    int result = -1;
    (void)path;
    sodium_memzero(host_id, sizeof(host_id));
    sodium_memzero(nonce, sizeof(nonce));
    sodium_memzero(request_sha256, sizeof(request_sha256));
    sodium_memzero(sequence, sizeof(sequence));
    sodium_memzero(head_sha256, sizeof(head_sha256));
    sodium_memzero(purpose, sizeof(purpose));
    sodium_memzero(generation, sizeof(generation));
    sodium_memzero(signature, sizeof(signature));
    if (context == NULL || context->home == NULL
        || context->request == NULL || contents == NULL
        || length == 0U || length > WLS_GUARDIAN_DOCUMENT_MAX_BYTES
        || length > (size_t)INT_MAX) goto cleanup;
    fields = sscanf(
        contents,
        "WLS-GUARDIAN-TRANSITION-ACK/1\n"
        "host_id=%32[0-9a-f]\n"
        "nonce=%32[0-9a-f]\n"
        "request_sha256=%64[0-9a-f]\n"
        "committed_head_sequence=%20[0-9]\n"
        "committed_head_sha256=%64[0-9a-f]\n"
        "purpose=%8[a-z]\n"
        "phase=STABLE\n"
        "active_generation_id=%64[0-9a-f]\n"
        "signature=%64[0-9a-f]\n%n",
        host_id,
        nonce,
        request_sha256,
        sequence,
        head_sha256,
        purpose,
        generation,
        signature,
        &consumed
    );
    errno = 0;
    parsed_sequence = strtoull(sequence, &end, 10);
    signature_line = strstr(contents, "signature=");
    expected_generation = strcmp(purpose, "rollback") == 0
        ? context->request->recovery_generation_id
        : context->request->candidate_generation_id;
    if (fields != 8 || consumed != (int)length || signature_line == NULL
        || strlen(host_id) != 32U || strlen(nonce) != 32U
        || strlen(request_sha256) != 64U || strlen(head_sha256) != 64U
        || strlen(generation) != 64U || strlen(signature) != 64U
        || (strcmp(purpose, "commit") != 0
            && strcmp(purpose, "rollback") != 0)
        || (sequence[0] == '0' && sequence[1] != '\0')
        || errno != 0 || end == sequence || *end != '\0'
        || parsed_sequence == 0ULL
        || parsed_sequence > (unsigned long long)LLONG_MAX
        || sodium_memcmp(
            host_id, context->request->host_id, 32U
        ) != 0
        || sodium_memcmp(nonce, context->request->nonce, 32U) != 0
        || sodium_memcmp(
            request_sha256, context->request->raw_sha256, 64U
        ) != 0
        || sodium_memcmp(generation, expected_generation, 64U) != 0
        || wls_guardian_verify_signed_document(
            context->home, contents, signature_line, signature
        ) != 0
        || (context->require_current_head
            && (context->head == NULL
                || strcmp(context->head->phase, "STABLE") != 0
                || parsed_sequence != context->head->sequence
                || sodium_memcmp(
                    head_sha256, context->head->raw_sha256, 64U
                ) != 0
                || sodium_memcmp(
                    generation,
                    context->head->active_generation_id,
                    64U
                ) != 0))) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(host_id, sizeof(host_id));
    sodium_memzero(nonce, sizeof(nonce));
    sodium_memzero(request_sha256, sizeof(request_sha256));
    sodium_memzero(sequence, sizeof(sequence));
    sodium_memzero(head_sha256, sizeof(head_sha256));
    sodium_memzero(purpose, sizeof(purpose));
    sodium_memzero(generation, sizeof(generation));
    sodium_memzero(signature, sizeof(signature));
    return result;
}

static int wls_guardian_reconcile_request_locked(
    const char *home,
    const struct wls_guardian_generation_head *head
) {
    char path[PATH_MAX];
    struct wls_guardian_request_validator_context target_context;
    struct wls_guardian_request_validator_context staging_context;
    int result;
    sodium_memzero(path, sizeof(path));
    memset(&target_context, 0, sizeof(target_context));
    memset(&staging_context, 0, sizeof(staging_context));
    if (home == NULL || head == NULL
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/guardian-transition.request"
        ) != 0) return -1;
    target_context.home = home;
    target_context.head = head;
    target_context.require_head_binding = 0;
    staging_context = target_context;
    staging_context.require_head_binding = 1;
    result = wls_guardian_atomic_reconcile_document(
        path,
        0600,
        WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
        wls_guardian_request_document_validator,
        wls_guardian_request_document_validator,
        &target_context,
        &staging_context,
        1
    );
    sodium_memzero(path, sizeof(path));
    sodium_memzero(&target_context, sizeof(target_context));
    sodium_memzero(&staging_context, sizeof(staging_context));
    return result;
}

static int wls_guardian_reconcile_evidence_locked(
    const char *home,
    const struct wls_guardian_transition_request *request,
    char backup[PATH_MAX]
) {
    char authorization[PATH_MAX];
    char inventory_path[PATH_MAX];
    struct wls_guardian_evidence_validator_context authorization_context;
    struct wls_guardian_evidence_validator_context inventory_context;
    int result = -1;
    sodium_memzero(authorization, sizeof(authorization));
    sodium_memzero(inventory_path, sizeof(inventory_path));
    memset(&authorization_context, 0, sizeof(authorization_context));
    memset(&inventory_context, 0, sizeof(inventory_context));
    if (home == NULL || request == NULL || backup == NULL
        || wls_guardian_backup_root(home, request->nonce, backup) != 0
        || wls_join(
            authorization,
            sizeof(authorization),
            backup,
            "guardian-recovery.authorization"
        ) != 0
        || wls_join(
            inventory_path,
            sizeof(inventory_path),
            backup,
            "guardian-recovery.inventory"
        ) != 0) goto cleanup;
    authorization_context.home = home;
    authorization_context.backup = backup;
    authorization_context.request = request;
    authorization_context.inventory = 0;
    inventory_context = authorization_context;
    inventory_context.inventory = 1;
    if (wls_guardian_atomic_reconcile_document(
            authorization,
            0600,
            WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
            wls_guardian_evidence_document_validator,
            wls_guardian_evidence_document_validator,
            &authorization_context,
            &authorization_context,
            0
        ) != 0
        || wls_guardian_atomic_reconcile_document(
            inventory_path,
            0600,
            WLS_GUARDIAN_INVENTORY_MAX_BYTES,
            wls_guardian_evidence_document_validator,
            wls_guardian_evidence_document_validator,
            &inventory_context,
            &inventory_context,
            0
        ) != 0
        || wls_guardian_recovery_authorization_verify(
            home, backup, request
        ) != 0) goto cleanup;
    {
        struct wls_guardian_recovery_inventory inventory;
        memset(&inventory, 0, sizeof(inventory));
        if (wls_guardian_recovery_inventory_load(
                home, backup, request, &inventory
            ) != 0) {
            wls_guardian_recovery_inventory_free(&inventory);
            goto cleanup;
        }
        wls_guardian_recovery_inventory_free(&inventory);
    }
    result = 0;
cleanup:
    sodium_memzero(authorization, sizeof(authorization));
    sodium_memzero(inventory_path, sizeof(inventory_path));
    sodium_memzero(&authorization_context, sizeof(authorization_context));
    sodium_memzero(&inventory_context, sizeof(inventory_context));
    if (result != 0 && backup != NULL) sodium_memzero(backup, PATH_MAX);
    return result;
}

static int wls_guardian_reconcile_transaction_locked(
    const char *home,
    const struct wls_guardian_transition_request *request
) {
    char path[PATH_MAX];
    struct wls_guardian_transaction_validator_context target_context;
    struct wls_guardian_transaction_validator_context staging_context;
    int result;
    sodium_memzero(path, sizeof(path));
    memset(&target_context, 0, sizeof(target_context));
    memset(&staging_context, 0, sizeof(staging_context));
    if (home == NULL || request == NULL
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/guardian-recovery.transaction"
        ) != 0) return -1;
    target_context.home = home;
    target_context.request = request;
    target_context.require_initial = 0;
    staging_context = target_context;
    staging_context.require_initial = 1;
    result = wls_guardian_atomic_reconcile_document(
        path,
        0600,
        WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
        wls_guardian_transaction_document_validator,
        wls_guardian_transaction_document_validator,
        &target_context,
        &staging_context,
        1
    );
    sodium_memzero(path, sizeof(path));
    sodium_memzero(&target_context, sizeof(target_context));
    sodium_memzero(&staging_context, sizeof(staging_context));
    return result;
}

static int wls_guardian_reconcile_ack_locked(
    const char *home,
    const struct wls_guardian_transition_request *request,
    const struct wls_guardian_generation_head *head
) {
    char path[PATH_MAX];
    struct wls_guardian_ack_validator_context target_context;
    struct wls_guardian_ack_validator_context staging_context;
    int result;
    sodium_memzero(path, sizeof(path));
    memset(&target_context, 0, sizeof(target_context));
    memset(&staging_context, 0, sizeof(staging_context));
    if (home == NULL || request == NULL || head == NULL
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/guardian-transition.ack"
        ) != 0) return -1;
    target_context.home = home;
    target_context.request = request;
    target_context.head = head;
    target_context.require_current_head = 0;
    staging_context = target_context;
    staging_context.require_current_head = 1;
    result = wls_guardian_atomic_reconcile_document(
        path,
        0600,
        WLS_GUARDIAN_DOCUMENT_MAX_BYTES,
        wls_guardian_ack_document_validator,
        wls_guardian_ack_document_validator,
        &target_context,
        &staging_context,
        1
    );
    sodium_memzero(path, sizeof(path));
    sodium_memzero(&target_context, sizeof(target_context));
    sodium_memzero(&staging_context, sizeof(staging_context));
    return result;
}

static int wls_guardian_atomic_replay_target_safe(
    const char *path,
    mode_t mode,
    mode_t alternate_mode
) {
    char parent_path[PATH_MAX];
    char leaf[NAME_MAX + 1U];
    struct stat parent;
    struct stat status;
    int parent_fd = -1;
    int fd = -1;
    int result = -1;
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(leaf, sizeof(leaf));
    if (path == NULL
        || wls_guardian_open_safe_parent(
            path, parent_path, leaf, &parent_fd
        ) != 0
        || fstat(parent_fd, &parent) != 0) goto cleanup;
    fd = openat(parent_fd, leaf, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0 && errno == ENOENT) {
        result = 0;
        goto cleanup;
    }
    if (fd < 0 || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode) || S_ISLNK(status.st_mode)
        || status.st_nlink != 1 || status.st_uid != geteuid()
        || status.st_gid != parent.st_gid
        || ((status.st_mode & 0777) != mode
            && (alternate_mode == 0
                || (status.st_mode & 0777) != alternate_mode))
        || wls_guardian_acl_free_fd(fd, 0) != 0) goto cleanup;
    result = 0;
cleanup:
    if (fd >= 0) close(fd);
    if (parent_fd >= 0) close(parent_fd);
    sodium_memzero(parent_path, sizeof(parent_path));
    sodium_memzero(leaf, sizeof(leaf));
    return result;
}

static int wls_guardian_reconcile_platform_target_locked(
    const char *source,
    const char *target,
    const char *digest,
    mode_t mode,
    mode_t alternate_mode,
    size_t maximum
) {
    struct wls_guardian_atomic_inventory inventory;
    int result = -1;
    memset(&inventory, 0, sizeof(inventory));
    if (source == NULL || target == NULL || digest == NULL
        || wls_guardian_verify_regular_digest(
            source, digest, 0600, (off_t)maximum
        ) != 0
        || wls_guardian_atomic_inventory_load(
            target, mode, maximum, &inventory
        ) != 0
        || inventory.backup.present
        || wls_guardian_atomic_replay_target_safe(
            target, mode, alternate_mode
        ) != 0) goto cleanup;
    if (inventory.temporary.present
        && wls_guardian_atomic_artifact_remove(
            &inventory, &inventory.temporary
        ) != 0) goto cleanup;
    if (wls_guardian_atomic_companions_absent(target) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(&inventory, sizeof(inventory));
    return result;
}

static int wls_guardian_reconcile_platform_locked(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request
) {
    char definition[PATH_MAX];
    char definition_source[PATH_MAX];
    char metadata[PATH_MAX];
    char metadata_source[PATH_MAX];
    mode_t definition_mode;
    mode_t metadata_mode;
    int result = -1;
    sodium_memzero(definition, sizeof(definition));
    sodium_memzero(definition_source, sizeof(definition_source));
    sodium_memzero(metadata, sizeof(metadata));
    sodium_memzero(metadata_source, sizeof(metadata_source));
    if (home == NULL || backup == NULL || request == NULL
        || wls_guardian_platform_target(
            home, request, definition, &definition_mode
        ) != 0
        || (strcmp(request->platform_kind, "systemd-system") == 0
            && wls_guardian_systemd_fixed_link_verify(definition) != 0)
        || wls_join(
            definition_source,
            sizeof(definition_source),
            backup,
            "platform/definition.before"
        ) != 0
        || wls_join(
            metadata,
            sizeof(metadata),
            home,
            "trust/platform-service.json"
        ) != 0
        || wls_join(
            metadata_source,
            sizeof(metadata_source),
            backup,
            "platform/metadata.before"
        ) != 0) goto cleanup;
    metadata_mode = strcmp(request->platform_kind, "test-session") == 0
        ? 0600 : 0440;
    if (wls_guardian_reconcile_platform_target_locked(
            definition_source,
            definition,
            request->platform_definition_sha256,
            definition_mode,
            0,
            1048576U
        ) != 0
        || wls_guardian_reconcile_platform_target_locked(
            metadata_source,
            metadata,
            request->platform_metadata_sha256,
            metadata_mode,
            metadata_mode == 0440 ? 0600 : 0,
            16384U
        ) != 0
        || (strcmp(request->platform_kind, "systemd-system") == 0
            && wls_guardian_systemd_fixed_link_verify(definition) != 0)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(definition, sizeof(definition));
    sodium_memzero(definition_source, sizeof(definition_source));
    sodium_memzero(metadata, sizeof(metadata));
    sodium_memzero(metadata_source, sizeof(metadata_source));
    return result;
}

/* This entry is called only while guardian-generation-head.lock is held.
 * It is intentionally self-contained: the immutable Guardian can recover
 * transition publications before a damaged Controller/PHP runtime starts. */
static int wls_guardian_atomic_recover_locked(
    const char *home,
    struct wls_guardian_transition_request *request,
    struct wls_guardian_generation_head *head,
    int *request_present
) {
    char backup[PATH_MAX];
    int request_state;
    int result = -1;
    sodium_memzero(backup, sizeof(backup));
    if (home == NULL || request == NULL || head == NULL
        || request_present == NULL) goto cleanup;
    *request_present = 0;
    memset(request, 0, sizeof(*request));
    memset(head, 0, sizeof(*head));
    if (wls_guardian_heads_reconcile_locked(home) != 0
        || wls_guardian_head_read(home, head) != 0
        || wls_guardian_reconcile_request_locked(home, head) != 0) {
        goto cleanup;
    }
    request_state = wls_guardian_request_read(home, request);
    if (request_state == 1) {
        result = strcmp(head->phase, "STABLE") == 0 ? 0 : -1;
        goto cleanup;
    }
    if (request_state != 0
        || sodium_memcmp(request->host_id, head->host_id, 32U) != 0
        || wls_guardian_reconcile_evidence_locked(
            home, request, backup
        ) != 0
        || wls_guardian_reconcile_transaction_locked(
            home, request
        ) != 0
        || wls_guardian_reconcile_ack_locked(
            home, request, head
        ) != 0) goto cleanup;
    if ((strcmp(head->phase, "ROLLBACK_PENDING") == 0
            || strcmp(head->phase, "ROLLBACK_OBSERVING") == 0)
        && wls_guardian_reconcile_platform_locked(
            home, backup, request
        ) != 0) goto cleanup;
    *request_present = 1;
    result = 0;
cleanup:
    sodium_memzero(backup, sizeof(backup));
    if (result != 0) {
        if (request_present != NULL) *request_present = 0;
        if (request != NULL) sodium_memzero(request, sizeof(*request));
        if (head != NULL) sodium_memzero(head, sizeof(*head));
    }
    return result;
}

static int wls_guardian_restore_platform_file(
    const char *home,
    const char *backup,
    const struct wls_guardian_transition_request *request,
    int metadata
) {
    char source[PATH_MAX];
    char target[PATH_MAX];
    char definition[PATH_MAX];
    char actual_digest[65];
    char *contents = NULL;
    size_t length = 0U;
    const char *digest;
    size_t maximum;
    mode_t definition_mode;
    mode_t mode;
    int result = -1;
    if (wls_guardian_platform_target(
            home, request, definition, &definition_mode
        ) != 0
        || (strcmp(request->platform_kind, "systemd-system") == 0
            && wls_guardian_systemd_fixed_link_verify(definition) != 0)) {
        goto cleanup;
    }
    if (metadata) {
        if (wls_join(source, sizeof(source), backup, "platform/metadata.before") != 0
            || wls_join(
                target,
                sizeof(target),
                home,
                "trust/platform-service.json"
            ) != 0) goto cleanup;
        digest = request->platform_metadata_sha256;
        maximum = 16384U;
        mode = strcmp(request->platform_kind, "test-session") == 0
            ? 0600 : 0440;
    } else {
        if (wls_join(
                source,
                sizeof(source),
                backup,
                "platform/definition.before"
            ) != 0) goto cleanup;
        memcpy(target, definition, strlen(definition) + 1U);
        digest = request->platform_definition_sha256;
        maximum = 1048576U;
        mode = definition_mode;
    }
    if (wls_guardian_read_bounded_regular(
            source, maximum, 0600, &contents, &length
        ) != 0
        || wls_sha256_text(
            (const unsigned char *)contents, length, actual_digest
        ) != 0
        || sodium_memcmp(actual_digest, digest, 64U) != 0) goto cleanup;
    if (wls_guardian_verify_regular_digest(
            target, digest, mode, (off_t)maximum
        ) == 0) {
        if (strcmp(request->platform_kind, "systemd-system") == 0
            && wls_guardian_systemd_fixed_link_verify(definition) != 0) {
            goto cleanup;
        }
        result = 0;
        goto cleanup;
    }
    if ((wls_recovery_target_safe(target, mode, geteuid()) != 0
            && !(metadata && mode == 0440
                && wls_recovery_target_safe(
                    target, 0600, geteuid()
                ) == 0))
        || wls_guardian_atomic_text(target, contents, mode) != 0
        || wls_guardian_verify_regular_digest(
            target, digest, mode, (off_t)maximum
        ) != 0
        || (strcmp(request->platform_kind, "systemd-system") == 0
            && wls_guardian_systemd_fixed_link_verify(definition) != 0)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    sodium_memzero(source, sizeof(source));
    sodium_memzero(target, sizeof(target));
    sodium_memzero(definition, sizeof(definition));
    sodium_memzero(actual_digest, sizeof(actual_digest));
    return result;
}

/* Regression proof for the Linux rollback path: authenticating the retained
 * definition must not overwrite the absolute systemd target later passed to
 * the fixed-link verifier. */
static int wls_guardian_systemd_definition_buffer_self_test(void)
{
    static const char source[] = "systemd-definition-before\n";
    static const char expected_definition[] =
        "/etc/weline-gateway/weline-wls-gateway-v2.service";
    struct wls_guardian_transition_request request;
    char definition[PATH_MAX];
    char actual_digest[65];
    mode_t mode = 0;
    int result = 1;
    memset(&request, 0, sizeof(request));
    sodium_memzero(definition, sizeof(definition));
    sodium_memzero(actual_digest, sizeof(actual_digest));
    if (snprintf(
            request.platform_kind,
            sizeof(request.platform_kind),
            "%s",
            "systemd-system"
        ) <= 0
        || wls_guardian_platform_target(
            "/unused-test-home",
            &request,
            definition,
            &mode
        ) != 0
        || mode != 0644
        || strcmp(definition, expected_definition) != 0
        || wls_sha256_text(
            (const unsigned char *)source,
            sizeof(source) - 1U,
            actual_digest
        ) != 0
        || !wls_is_hex_text(actual_digest, 64U)
        || strcmp(definition, expected_definition) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(&request, sizeof(request));
    sodium_memzero(definition, sizeof(definition));
    sodium_memzero(actual_digest, sizeof(actual_digest));
    return result;
}

static int wls_guardian_platform_reload(
    const struct wls_guardian_transition_request *request
) {
    pid_t child;
    int status = 0;
    long long now;
    long long deadline;
    if (request == NULL) return -1;
    if (strcmp(request->platform_kind, "test-session") == 0
        || strcmp(request->platform_kind, "launchd-system") == 0) return 0;
    if (strcmp(request->platform_kind, "systemd-system") != 0) return -1;
    child = fork();
    if (child < 0) return -1;
    if (child == 0) {
        execl("/bin/systemctl", "systemctl", "daemon-reload", (char *)NULL);
        _exit(127);
    }
    now = wls_monotonic_milliseconds();
    if (now <= 0 || wls_checked_add_long_long(
            now,
            WLS_PACKAGE_LOCK_TIMEOUT_MILLISECONDS,
            &deadline
        ) != 0
        || wls_wait_child_exit_until(child, &status, deadline) != 0) {
        (void)kill(child, SIGKILL);
        now = wls_monotonic_milliseconds();
        if (now > 0 && wls_checked_add_long_long(
                now, WLS_BROKER_KILL_REAP_MILLISECONDS, &deadline
            ) == 0) {
            (void)wls_wait_child_exit_until(child, &status, deadline);
        }
        return -1;
    }
    return WIFEXITED(status) && WEXITSTATUS(status) == 0 ? 0 : -1;
}

static int wls_guardian_recovery_restore(
    const char *home,
    const char *run_directory
) {
    static const char *categories[] = {
        "snapshot-candidates-v2",
        "snapshots",
        "snapshots-v2",
        "runtime-conf",
        "runtime-run",
        "runtime-shadow",
        "runtime-temp",
        "state",
        "trust",
    };
    struct wls_guardian_transition_request request;
    struct wls_guardian_generation_head head;
    struct wls_guardian_recovery_inventory inventory;
    struct wls_guardian_recovery_transaction transaction;
    char backup[PATH_MAX];
    size_t index;
    int lock_fd = -1;
    int lock_result;
    int request_present = 0;
    int result = -1;
    (void)run_directory;
    memset(&request, 0, sizeof(request));
    memset(&head, 0, sizeof(head));
    memset(&inventory, 0, sizeof(inventory));
    memset(&transaction, 0, sizeof(transaction));
    sodium_memzero(backup, sizeof(backup));
    if (home == NULL || run_directory == NULL) goto cleanup;
    lock_result = wls_guardian_transition_lock(home, &lock_fd);
    if (lock_result != 0
        || wls_guardian_atomic_recover_locked(
            home, &request, &head, &request_present
        ) != 0
        || !request_present) goto cleanup;
    if (wls_guardian_head_is_committed_recovery(&head, &request)) {
        result = wls_guardian_ack_publish(home, &request, &head, 1);
        goto cleanup;
    }
    if ((strcmp(head.phase, "ROLLBACK_PENDING") != 0
            && strcmp(head.phase, "ROLLBACK_OBSERVING") != 0)
        || !wls_guardian_head_has_request_recovery(
            &head,
            &request,
            strcmp(head.phase, "ROLLBACK_OBSERVING") == 0 ? 1 : 0
        )
        || wls_guardian_recovery_evidence_load(
            home, &request, backup, &inventory
        ) != 0
        || wls_guardian_runtime_prevalidate(home, backup, &request) != 0
        || wls_guardian_derived_prevalidate(
            home, backup, &request, &inventory
        ) != 0
        || wls_guardian_recovery_transaction_begin(
            home, &request, &transaction
        ) != 0) goto cleanup;
    if (strcmp(transaction.phase, "AUTHORIZED") == 0
        && wls_guardian_recovery_transaction_advance(
            home, &request, &transaction, "RUNTIME", 0ULL
        ) != 0) goto cleanup;
    if (strcmp(transaction.phase, "RUNTIME") == 0) {
        while (transaction.cursor < 7ULL) {
            int operation_result;
            switch ((unsigned int)transaction.cursor) {
                case 0U:
                    operation_result = wls_guardian_restore_candidate_directory(
                        home, backup, &request
                    );
                    break;
                case 1U:
                    operation_result = wls_guardian_restore_slot(
                        home, backup, &request, 'A'
                    );
                    break;
                case 2U:
                    operation_result = wls_guardian_restore_slot(
                        home, backup, &request, 'B'
                    );
                    break;
                case 3U:
                case 4U:
                case 5U:
                    operation_result = wls_guardian_restore_runtime_regular(
                        home,
                        backup,
                        &request,
                        (unsigned int)transaction.cursor
                    );
                    break;
                case 6U:
                    operation_result = wls_guardian_restore_previous_slot(
                        home, backup, &request
                    );
                    break;
                default:
                    operation_result = -1;
                    break;
            }
            if (operation_result != 0
                || wls_guardian_recovery_transaction_advance(
                    home,
                    &request,
                    &transaction,
                    "RUNTIME",
                    transaction.cursor + 1ULL
                ) != 0) goto cleanup;
        }
        if (wls_guardian_recovery_transaction_advance(
                home, &request, &transaction, "DERIVED", 0ULL
            ) != 0) goto cleanup;
    }
    if (strcmp(transaction.phase, "DERIVED") == 0) {
        while (transaction.cursor
            < sizeof(categories) / sizeof(categories[0])) {
            index = (size_t)transaction.cursor;
            if (wls_guardian_restore_derived_category(
                    home,
                    backup,
                    &request,
                    &inventory,
                    categories[index]
                ) != 0
                || wls_guardian_recovery_transaction_advance(
                    home,
                    &request,
                    &transaction,
                    "DERIVED",
                    transaction.cursor + 1ULL
                ) != 0) goto cleanup;
        }
        if (wls_guardian_recovery_transaction_advance(
                home, &request, &transaction, "PLATFORM", 0ULL
            ) != 0) goto cleanup;
    }
    if (strcmp(transaction.phase, "PLATFORM") == 0) {
        while (transaction.cursor < 3ULL) {
            int operation_result = transaction.cursor == 0ULL
                ? wls_guardian_restore_platform_file(
                    home, backup, &request, 0
                )
                : (transaction.cursor == 1ULL
                    ? wls_guardian_restore_platform_file(
                        home, backup, &request, 1
                    )
                    : wls_guardian_platform_reload(&request));
            if (operation_result != 0
                || wls_guardian_recovery_transaction_advance(
                    home,
                    &request,
                    &transaction,
                    "PLATFORM",
                    transaction.cursor + 1ULL
                ) != 0) goto cleanup;
        }
        if (wls_guardian_generation_closure(
                home,
                request.recovery_launcher_sha256,
                request.recovery_ca_sha256,
                request.recovery_runtime_generation
            ) != 0
            || wls_guardian_derived_state_final_verify(
                home, backup, &request, &inventory
            ) != 0
            || wls_guardian_recovery_transaction_advance(
                home, &request, &transaction, "RESTORED", 0ULL
            ) != 0) goto cleanup;
    }
    if ((strcmp(transaction.phase, "RESTORED") != 0
            && strcmp(transaction.phase, "OBSERVING") != 0
            && strcmp(transaction.phase, "STABLE") != 0)
        || wls_guardian_generation_closure(
            home,
            request.recovery_launcher_sha256,
            request.recovery_ca_sha256,
            request.recovery_runtime_generation
        ) != 0
        || wls_guardian_derived_state_final_verify(
            home, backup, &request, &inventory
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (lock_fd >= 0) close(lock_fd);
    wls_guardian_recovery_inventory_free(&inventory);
    sodium_memzero(&request, sizeof(request));
    sodium_memzero(&head, sizeof(head));
    sodium_memzero(&transaction, sizeof(transaction));
    sodium_memzero(backup, sizeof(backup));
    return result;
}

/* 0=no request or probation still running; 1=STABLE acknowledgement durable;
 * -2=authenticated rollback is pending; -1=fail closed. */
static int wls_guardian_transition_tick(
    const char *home,
    const char *run_directory,
    int candidate_failed,
    struct wls_guardian_probation_observation *observation
) {
    struct wls_guardian_transition_request request;
    struct wls_guardian_generation_head head;
    struct wls_guardian_generation_head published;
    struct wls_guardian_probation_sample health_sample;
    char boot_id[65];
    long long now;
    long long deadline;
    int lock_fd = -1;
    int lock_result;
    int health_result;
    int sample_result;
    int identity_changed = 0;
    int request_present = 0;
    int result = -1;
    memset(&request, 0, sizeof(request));
    memset(&head, 0, sizeof(head));
    memset(&published, 0, sizeof(published));
    memset(&health_sample, 0, sizeof(health_sample));
    sodium_memzero(boot_id, sizeof(boot_id));
    if (home == NULL || run_directory == NULL || observation == NULL) {
        goto cleanup;
    }
    lock_result = wls_guardian_transition_lock(home, &lock_fd);
    if (lock_result == 1) return 0;
    if (lock_result != 0) goto cleanup;
    if (wls_guardian_atomic_recover_locked(
            home, &request, &head, &request_present
        ) != 0) goto cleanup;
    if (!request_present) {
        result = 0;
        goto cleanup;
    }
    if (wls_boot_id(boot_id) != 0) goto cleanup;

    if (strcmp(head.phase, "FAILED_CLOSED") == 0) goto cleanup;
    if (strcmp(head.phase, "ROLLBACK_PENDING") == 0) {
        if (!wls_guardian_head_has_request_recovery(&head, &request, 0)) {
            goto cleanup;
        }
        if (wls_guardian_generation_closure(
                home,
                request.recovery_launcher_sha256,
                request.recovery_ca_sha256,
                request.recovery_runtime_generation
            ) != 0
            || wls_guardian_recovery_roots_verify(
                home, &request
            ) != 0) {
            result = -2;
            goto cleanup;
        }
        health_result = wls_guardian_probation_health(
            home,
            run_directory,
            request.host_id,
            request.recovery_generation_id,
            request.recovery_runtime_generation,
            &health_sample
        );
        if (health_result != 0) {
            /* The exact recovery files are live but the freshly started old
             * Controller has not published a complete health receipt yet. */
            result = 0;
            goto cleanup;
        }
        sample_result = wls_guardian_observation_accept_sample(
            observation, &health_sample, &identity_changed
        );
        if (sample_result <= 0) {
            /* A continuity-only receipt proves the restored tree is alive but
             * cannot start rollback probation.  Replays likewise wait for a
             * fresh authoritative receipt instead of borrowing old time. */
            result = 0;
            goto cleanup;
        }
        now = wls_monotonic_milliseconds();
        if (now <= 0 || wls_checked_add_long_long(
                now,
                WLS_GUARDIAN_ROLLBACK_OBSERVATION_MILLISECONDS,
                &deadline
            ) != 0
            || wls_guardian_recovery_transaction_mark(
                home, &request, "OBSERVING"
            ) != 0
            || wls_guardian_head_publish(
                home,
                &head,
                &request,
                "ROLLBACK_OBSERVING",
                boot_id,
                (unsigned long long)now,
                (unsigned long long)deadline,
                1,
                &published
            ) != 0) goto cleanup;
        wls_guardian_observation_window_committed(observation);
        result = 0;
        goto cleanup;
    }
    if (strcmp(head.phase, "ROLLBACK_OBSERVING") == 0) {
        if (wls_guardian_recovery_transaction_mark(
                home, &request, "OBSERVING"
            ) != 0
            || !wls_guardian_head_has_request_recovery(&head, &request, 1)
            || wls_guardian_generation_closure(
                home,
                request.recovery_launcher_sha256,
                request.recovery_ca_sha256,
                request.recovery_runtime_generation
            ) != 0
            || wls_guardian_recovery_roots_verify(
                home, &request
            ) != 0) {
            result = -3;
            goto cleanup;
        }
        health_result = wls_guardian_probation_health(
            home,
            run_directory,
            request.host_id,
            request.recovery_generation_id,
            request.recovery_runtime_generation,
            &health_sample
        );
        if (health_result != 0) {
            result = -3;
            goto cleanup;
        }
        sample_result = wls_guardian_observation_accept_sample(
            observation, &health_sample, &identity_changed
        );
        if (sample_result < 0) {
            result = -3;
            goto cleanup;
        }
        if (identity_changed) {
            /* TEST-137: a process replacement during recovery observation is
             * an observation failure, not a new window for a different tree. */
            result = -3;
            goto cleanup;
        }
        if (sample_result == 0) {
            result = 0;
            goto cleanup;
        }
        now = wls_monotonic_milliseconds();
        if (now <= 0) goto cleanup;
        if (sodium_memcmp(head.host_boot_id, boot_id, 64U) != 0
            || observation->reset_existing_probation) {
            if (wls_checked_add_long_long(
                    now,
                    WLS_GUARDIAN_ROLLBACK_OBSERVATION_MILLISECONDS,
                    &deadline
                ) != 0
                || wls_guardian_head_publish(
                    home,
                    &head,
                    &request,
                    "ROLLBACK_OBSERVING",
                    boot_id,
                    (unsigned long long)now,
                    (unsigned long long)deadline,
                    1,
                    &published
                ) != 0) goto cleanup;
            wls_guardian_observation_window_committed(observation);
            result = 0;
            goto cleanup;
        }
        if ((unsigned long long)now
            < head.probation_deadline_monotonic_ms) {
            result = 0;
            goto cleanup;
        }
        if (wls_guardian_head_publish(
                home,
                &head,
                &request,
                "STABLE",
                boot_id,
                0ULL,
                0ULL,
                1,
                &published
            ) != 0
            || wls_guardian_recovery_transaction_mark(
                home, &request, "STABLE"
            ) != 0
            || wls_guardian_ack_publish(
                home,
                &request,
                &published,
                1
            ) != 0) goto cleanup;
        result = 2;
        goto cleanup;
    }
    /* STABLE is a terminal after-image.  PHP may not have retired the request
     * yet, but a later health sample must never regress a committed head back
     * into rollback.  Replay only the exact acknowledgement. */
    if (wls_guardian_head_is_committed_recovery(&head, &request)) {
        if (wls_guardian_recovery_transaction_mark(
                home, &request, "STABLE"
            ) != 0
            || wls_guardian_ack_publish(home, &request, &head, 1) != 0) {
            goto cleanup;
        }
        result = 2;
        goto cleanup;
    }
    if (wls_guardian_head_is_committed_candidate(&head, &request)) {
        if (wls_guardian_ack_publish(home, &request, &head, 0) != 0) {
            goto cleanup;
        }
        result = 1;
        goto cleanup;
    }
    if (candidate_failed
        || wls_guardian_candidate_closure(home, &request) != 0) {
        goto rollback_pending;
    }
    health_result = wls_guardian_probation_health(
        home,
        run_directory,
        request.host_id,
        request.candidate_generation_id,
        request.candidate_runtime_generation,
        &health_sample
    );
    if (health_result != 0) goto rollback_pending;
    sample_result = wls_guardian_observation_accept_sample(
        observation, &health_sample, &identity_changed
    );
    if (sample_result < 0) goto rollback_pending;
    if (strcmp(head.phase, "STABLE") == 0
        && head.sequence == request.expected_head_sequence
        && sodium_memcmp(
            head.raw_sha256, request.expected_head_sha256, 64U
        ) == 0
        && sodium_memcmp(
            head.active_generation_id, request.recovery_generation_id, 64U
        ) == 0) {
        if (sample_result == 0) {
            result = 0;
            goto cleanup;
        }
        now = wls_monotonic_milliseconds();
        if (now <= 0 || wls_checked_add_long_long(
                now,
                WLS_GUARDIAN_PROBATION_MILLISECONDS,
                &deadline
            ) != 0
            || wls_guardian_head_publish(
                home,
                &head,
                &request,
                "PROBATIONARY_COMMITTED",
                boot_id,
                (unsigned long long)now,
                (unsigned long long)deadline,
                0,
                &published
            ) != 0) goto cleanup;
        wls_guardian_observation_window_committed(observation);
        result = 0;
        goto cleanup;
    }
    if (strcmp(head.phase, "PROBATIONARY_COMMITTED") == 0) {
        if (head.sequence <= request.expected_head_sequence
            || sodium_memcmp(
                head.active_generation_id,
                request.candidate_generation_id,
                64U
            ) != 0
            || sodium_memcmp(
                head.recovery_generation_id,
                request.recovery_generation_id,
                64U
            ) != 0
            || sodium_memcmp(head.recovery_nonce, request.nonce, 32U) != 0
            || sodium_memcmp(
                head.recovery_authorization_sha256,
                request.recovery_authorization_sha256,
                64U
            ) != 0) goto cleanup;
        if (identity_changed) goto rollback_pending;
        if (sample_result == 0) {
            result = 0;
            goto cleanup;
        }
        now = wls_monotonic_milliseconds();
        if (now <= 0) goto cleanup;
        if (sodium_memcmp(head.host_boot_id, boot_id, 64U) != 0
            || observation->reset_existing_probation) {
            if (wls_checked_add_long_long(
                    now,
                    WLS_GUARDIAN_PROBATION_MILLISECONDS,
                    &deadline
                ) != 0
                || wls_guardian_head_publish(
                    home,
                    &head,
                    &request,
                    "PROBATIONARY_COMMITTED",
                    boot_id,
                    (unsigned long long)now,
                    (unsigned long long)deadline,
                    0,
                    &published
                ) != 0) goto cleanup;
            wls_guardian_observation_window_committed(observation);
            result = 0;
            goto cleanup;
        }
        if ((unsigned long long)now
            < head.probation_deadline_monotonic_ms) {
            result = 0;
            goto cleanup;
        }
        if (wls_guardian_head_publish(
                home,
                &head,
                &request,
                "STABLE",
                boot_id,
                0ULL,
                0ULL,
                0,
                &published
            ) != 0
            || wls_guardian_ack_publish(
                home,
                &request,
                &published,
                0
            ) != 0) goto cleanup;
        result = 1;
        goto cleanup;
    }
    goto cleanup;
rollback_pending:
    if (wls_guardian_head_publish(
            home,
            &head,
            &request,
            "ROLLBACK_PENDING",
            boot_id,
            0ULL,
            0ULL,
            0,
            &published
        ) != 0) goto cleanup;
    result = -2;
cleanup:
    if (lock_fd >= 0) close(lock_fd);
    sodium_memzero(&request, sizeof(request));
    sodium_memzero(&head, sizeof(head));
    sodium_memzero(&published, sizeof(published));
    sodium_memzero(&health_sample, sizeof(health_sample));
    sodium_memzero(boot_id, sizeof(boot_id));
    return result;
}

/* 1=receipt still names the exact live executable, 0=process exited,
 * -1=PID reuse, digest mismatch or an indeterminate observation. */
static int wls_recovery_attested_process_live(
    const struct wls_process_attestation_receipt *receipt
) {
    char executable_before[PATH_MAX];
    char executable_after[PATH_MAX];
    char binary_digest[65];
    unsigned long long start_before = 0ULL;
    unsigned long long start_after = 0ULL;
    pid_t pid;
    int result = -1;
    if (receipt == NULL || receipt->pid == 0U
        || receipt->pid > (unsigned long)INT_MAX
        || receipt->start_id == 0ULL
        || !wls_is_hex_text(receipt->binary_digest, 64U)) return -1;
    pid = (pid_t)receipt->pid;
    errno = 0;
    if (kill(pid, 0) != 0 && errno == ESRCH) return 0;
    if (wls_recovery_process_identity(
            pid,
            executable_before,
            sizeof(executable_before),
            &start_before
        ) != 0
        || start_before != receipt->start_id
        || wls_file_digest(executable_before, binary_digest) != 0
        || wls_recovery_process_identity(
            pid,
            executable_after,
            sizeof(executable_after),
            &start_after
        ) != 0
        || start_after != start_before
        || strcmp(executable_before, executable_after) != 0
        || sodium_memcmp(binary_digest, receipt->binary_digest, 64U) != 0) {
        goto cleanup;
    }
    result = 1;
cleanup:
    sodium_memzero(executable_before, sizeof(executable_before));
    sodium_memzero(executable_after, sizeof(executable_after));
    sodium_memzero(binary_digest, sizeof(binary_digest));
    return result;
}

static int wls_recovery_attested_process_self_test(void)
{
#if defined(__linux__) || defined(__APPLE__)
    struct wls_process_attestation_receipt receipt;
    char executable[PATH_MAX];
    unsigned long long start_id = 0ULL;
    int result = 1;
    memset(&receipt, 0, sizeof(receipt));
    if (wls_recovery_process_identity(
            getpid(), executable, sizeof(executable), &start_id
        ) != 0
        || wls_file_digest(executable, receipt.binary_digest) != 0) {
        goto cleanup;
    }
    receipt.pid = (unsigned long)getpid();
    receipt.start_id = start_id;
    if (wls_recovery_attested_process_live(&receipt) != 1) goto cleanup;
    receipt.start_id++;
    if (wls_recovery_attested_process_live(&receipt) != -1) goto cleanup;
    receipt.start_id = start_id;
    receipt.binary_digest[0] = receipt.binary_digest[0] == '0' ? '1' : '0';
    if (wls_recovery_attested_process_live(&receipt) != -1) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(&receipt, sizeof(receipt));
    sodium_memzero(executable, sizeof(executable));
    return result;
#else
    return 0;
#endif
}

/* 1=exact live data-plane attestation, 0=not ready, -1=unsafe evidence. */
static int wls_recovery_attestation_ready(
    const char *home,
    uid_t expected_owner,
    const char *runtime_generation
) {
    char path[PATH_MAX];
    char contents[2048];
    size_t length = 0U;
    int read_status;
    struct wls_process_attestation_receipt receipt;
    if (wls_join(
            path, sizeof(path), home, "trust/process-attestation.receipt"
        ) != 0) return -1;
    read_status = wls_recovery_read_secure(
        path, 0600, expected_owner, contents, sizeof(contents), &length
    );
    if (read_status == 1) return 0;
    if (read_status != 0
        || wls_parse_process_attestation(contents, length, &receipt) != 0) {
        sodium_memzero(contents, sizeof(contents));
        return -1;
    }
    if (strcmp(receipt.runtime_generation, runtime_generation) != 0) {
        read_status = -1;
    } else {
        read_status = wls_recovery_attested_process_live(&receipt);
    }
    sodium_memzero(&receipt, sizeof(receipt));
    sodium_memzero(contents, sizeof(contents));
    return read_status;
}

/* The attempt is cleared only after an exact attested data plane and its
 * supervising Broker coexist continuously for fifteen seconds. */
static int wls_recovery_observe_health(
    const char *home,
    const char *runtime_generation,
    struct wls_posix_recovery_context *context,
    unsigned long long *observation_started
) {
    int ready;
    long long now_wall;
    long long now_signed;
    unsigned long long now;
    if (context == NULL || observation_started == NULL
        || context->healthy_committed) return 0;
    ready = wls_recovery_attestation_ready(
        home, context->owner_uid, runtime_generation
    );
    if (ready < 0) return -1;
    if (ready == 0) {
        *observation_started = 0ULL;
        return 0;
    }
    now_signed = wls_monotonic_milliseconds();
    if (now_signed <= 0 || wls_recovery_wall_seconds(&now_wall) != 0) {
        return -1;
    }
    now = (unsigned long long)now_signed;
    if (*observation_started == 0ULL) {
        *observation_started = now;
        wls_recovery_mark_observing(&context->state, now, now_wall);
        return wls_recovery_publish(context, NULL, NULL);
    }
    if (now < *observation_started) return -1;
    if (now - *observation_started < WLS_RECOVERY_HEALTH_MILLISECONDS) {
        return 0;
    }
    wls_recovery_mark_healthy(&context->state, now, now_wall);
    if (wls_recovery_publish(context, NULL, NULL) != 0) return -1;
    context->healthy_committed = 1;
    return 0;
}

static int wls_parse_platform_retirement_v2(
    const char *contents,
    size_t length,
    struct wls_platform_retirement_receipt *receipt
) {
    int consumed = 0;
    if (contents == NULL || receipt == NULL) return -1;
    memset(receipt, 0, sizeof(*receipt));
    if (sscanf(
            contents,
            "WLS-PROCESS-TREE-RETIRE/2\nstatus=%15[A-Z]\n"
            "retirement_id=%64[0-9a-f]\npid=%lu\nstart_id=%llu\n"
            "attestation_digest=%64[0-9a-f]\n"
            "binary_digest=%64[0-9a-f]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "host_boot_id=%64[0-9a-f]\n"
            "config_digest=%64[0-9a-f]\n"
            "config_path_digest=%64[0-9a-f]\n"
            "publication_generation=%lu\nplatform=%15[a-z-]\n"
            "service_id=%64[0-9a-f]\n"
            "requested_launcher_generation=%64[0-9a-f]\n"
            "completed_launcher_generation=%64[0-9a-f]\n"
            "completed_host_boot_id=%64[0-9a-f]\n"
            "completed_runtime_generation=%64[0-9a-f]\n%n",
            receipt->status,
            receipt->retirement_id,
            &receipt->pid,
            &receipt->start_id,
            receipt->attestation_digest,
            receipt->binary_digest,
            receipt->runtime_generation,
            receipt->host_boot_id,
            receipt->config_digest,
            receipt->config_path_digest,
            &receipt->publication_generation,
            receipt->platform,
            receipt->service_id,
            receipt->requested_launcher_generation,
            receipt->completed_launcher_generation,
            receipt->completed_host_boot_id,
            receipt->completed_runtime_generation,
            &consumed
        ) != 17
        || consumed != (int)length
        || (strcmp(receipt->status, "COMPLETE") != 0
            && strcmp(receipt->status, "INDETERMINATE") != 0)
        || receipt->pid == 0U
        || receipt->start_id == 0ULL
        || !wls_is_hex_text(receipt->retirement_id, 64U)
        || !wls_is_hex_text(receipt->attestation_digest, 64U)
        || !wls_is_hex_text(receipt->binary_digest, 64U)
        || !wls_is_hex_text(receipt->runtime_generation, 64U)
        || !wls_is_hex_text(receipt->host_boot_id, 64U)
        || !wls_is_hex_text(receipt->config_digest, 64U)
        || !wls_is_hex_text(receipt->config_path_digest, 64U)
        || !wls_is_hex_text(receipt->service_id, 64U)
        || !wls_is_hex_text(receipt->requested_launcher_generation, 64U)
        || !wls_is_hex_text(receipt->completed_launcher_generation, 64U)
        || !wls_is_hex_text(receipt->completed_host_boot_id, 64U)
        || !wls_is_hex_text(receipt->completed_runtime_generation, 64U)) {
        sodium_memzero(receipt, sizeof(*receipt));
        return -1;
    }
    return 0;
}

static int wls_write_platform_retirement_v2(
    const char *home,
    const struct wls_platform_retirement_receipt *receipt
) {
    char path[PATH_MAX];
    char payload[2048];
    int written;
    if (home == NULL || receipt == NULL || geteuid() != 0
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/process-tree-retirement.receipt"
        ) != 0) return -1;
    written = snprintf(
        payload,
        sizeof(payload),
        "WLS-PROCESS-TREE-RETIRE/2\nstatus=%s\nretirement_id=%s\n"
        "pid=%lu\nstart_id=%llu\nattestation_digest=%s\n"
        "binary_digest=%s\nruntime_generation=%s\nhost_boot_id=%s\n"
        "config_digest=%s\nconfig_path_digest=%s\n"
        "publication_generation=%lu\nplatform=%s\nservice_id=%s\n"
        "requested_launcher_generation=%s\n"
        "completed_launcher_generation=%s\ncompleted_host_boot_id=%s\n"
        "completed_runtime_generation=%s\n",
        receipt->status,
        receipt->retirement_id,
        receipt->pid,
        receipt->start_id,
        receipt->attestation_digest,
        receipt->binary_digest,
        receipt->runtime_generation,
        receipt->host_boot_id,
        receipt->config_digest,
        receipt->config_path_digest,
        receipt->publication_generation,
        receipt->platform,
        receipt->service_id,
        receipt->requested_launcher_generation,
        receipt->completed_launcher_generation,
        receipt->completed_host_boot_id,
        receipt->completed_runtime_generation
    );
    if (written <= 0 || written >= (int)sizeof(payload)
        || wls_atomic_text(path, payload, 0600) != 0) {
        sodium_memzero(payload, sizeof(payload));
        return -1;
    }
    sodium_memzero(payload, sizeof(payload));
    return 0;
}

static int wls_seal_platform_retirement_pending(
    const char *home,
    const char *platform,
    const char *service_id,
    const char *launcher_generation,
    const char *runtime_generation
) {
    char retirement_path[PATH_MAX];
    char attestation_path[PATH_MAX];
    char retirement[2048];
    char attestation[2048];
    char status[16];
    char zeros[65];
    size_t retirement_length = 0U;
    size_t attestation_length = 0U;
    int consumed = 0;
    int read_result;
    struct wls_platform_retirement_receipt sealed;
    struct wls_process_attestation_receipt process;
    if (home == NULL || platform == NULL || service_id == NULL
        || launcher_generation == NULL || runtime_generation == NULL
        || strcmp(platform, "standalone") == 0
        || geteuid() != 0
        || wls_join(
            retirement_path,
            sizeof(retirement_path),
            home,
            "trust/process-tree-retirement.receipt"
        ) != 0
        || wls_join(
            attestation_path,
            sizeof(attestation_path),
            home,
            "trust/process-attestation.receipt"
        ) != 0) return -1;
    read_result = wls_read_root_receipt(
        retirement_path,
        retirement,
        sizeof(retirement),
        &retirement_length
    );
    if (read_result != 0) return -1;
    if (strncmp(retirement, "WLS-PROCESS-TREE-RETIRE/2\n", 26U) == 0) {
        if (wls_parse_platform_retirement_v2(
                retirement,
                retirement_length,
                &sealed
            ) == 0
            && strcmp(sealed.status, "INDETERMINATE") == 0
            && strcmp(sealed.platform, platform) == 0
            && strcmp(sealed.service_id, service_id) == 0
            && strcmp(
                sealed.requested_launcher_generation,
                launcher_generation
            ) == 0) {
            sodium_memzero(&sealed, sizeof(sealed));
            sodium_memzero(retirement, sizeof(retirement));
            return 0;
        }
        sodium_memzero(&sealed, sizeof(sealed));
        sodium_memzero(retirement, sizeof(retirement));
        return -1;
    }
    memset(&sealed, 0, sizeof(sealed));
    if (sscanf(
            retirement,
            "WLS-PROCESS-TREE-RETIRE/1\nstatus=%15[A-Z]\n"
            "retirement_id=%64[0-9a-f]\npid=%lu\nstart_id=%llu\n"
            "attestation_digest=%64[0-9a-f]\n"
            "binary_digest=%64[0-9a-f]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "host_boot_id=%64[0-9a-f]\n%n",
            status,
            sealed.retirement_id,
            &sealed.pid,
            &sealed.start_id,
            sealed.attestation_digest,
            sealed.binary_digest,
            sealed.runtime_generation,
            sealed.host_boot_id,
            &consumed
        ) != 8
        || consumed != (int)retirement_length
        || strcmp(status, "INDETERMINATE") != 0
        || !wls_is_hex_text(sealed.retirement_id, 64U)
        || sealed.pid == 0U
        || sealed.start_id == 0ULL
        || !wls_is_hex_text(sealed.attestation_digest, 64U)
        || !wls_is_hex_text(sealed.binary_digest, 64U)
        || !wls_is_hex_text(sealed.runtime_generation, 64U)
        || !wls_is_hex_text(sealed.host_boot_id, 64U)
        || strcmp(sealed.runtime_generation, runtime_generation) != 0
        || wls_read_root_receipt(
            attestation_path,
            attestation,
            sizeof(attestation),
            &attestation_length
        ) != 0
        || wls_parse_process_attestation(
            attestation,
            attestation_length,
            &process
        ) != 0
        || process.pid != sealed.pid
        || process.start_id != sealed.start_id
        || strcmp(process.binary_digest, sealed.binary_digest) != 0
        || strcmp(process.runtime_generation, sealed.runtime_generation) != 0
        || wls_sha256_text(
            (const unsigned char *)attestation,
            attestation_length,
            zeros
        ) != 0
        || strcmp(zeros, sealed.attestation_digest) != 0) {
        sodium_memzero(&process, sizeof(process));
        sodium_memzero(&sealed, sizeof(sealed));
        sodium_memzero(retirement, sizeof(retirement));
        sodium_memzero(attestation, sizeof(attestation));
        sodium_memzero(zeros, sizeof(zeros));
        return -1;
    }
    memcpy(sealed.status, "INDETERMINATE", 14U);
    memcpy(sealed.config_digest, process.config_digest, 65U);
    memcpy(sealed.config_path_digest, process.config_path_digest, 65U);
    sealed.publication_generation = process.publication_generation;
    snprintf(sealed.platform, sizeof(sealed.platform), "%s", platform);
    memcpy(sealed.service_id, service_id, 65U);
    memcpy(
        sealed.requested_launcher_generation,
        launcher_generation,
        65U
    );
    memset(zeros, '0', 64U);
    zeros[64] = '\0';
    memcpy(sealed.completed_launcher_generation, zeros, 65U);
    memcpy(sealed.completed_host_boot_id, zeros, 65U);
    memcpy(sealed.completed_runtime_generation, zeros, 65U);
    read_result = wls_write_platform_retirement_v2(home, &sealed);
    sodium_memzero(&process, sizeof(process));
    sodium_memzero(&sealed, sizeof(sealed));
    sodium_memzero(retirement, sizeof(retirement));
    sodium_memzero(attestation, sizeof(attestation));
    sodium_memzero(zeros, sizeof(zeros));
    return read_result;
}

static int wls_promote_platform_retirement(
    const char *home,
    const char *platform,
    const char *service_id,
    const char *launcher_generation,
    const char *runtime_generation
) {
    char path[PATH_MAX];
    char contents[2048];
    char boot_id[65];
    size_t length = 0U;
    int read_result;
    struct wls_platform_retirement_receipt receipt;
    if (home == NULL || platform == NULL || service_id == NULL
        || launcher_generation == NULL || runtime_generation == NULL
        || wls_join(
            path,
            sizeof(path),
            home,
            "trust/process-tree-retirement.receipt"
        ) != 0) return -1;
    read_result = wls_read_root_receipt(
        path,
        contents,
        sizeof(contents),
        &length
    );
    if (read_result == 1) return 0;
    if (read_result != 0) return -1;
    if (strncmp(contents, "WLS-PROCESS-TREE-RETIRE/2\n", 26U) != 0) {
        sodium_memzero(contents, sizeof(contents));
        return 0;
    }
    if (wls_parse_platform_retirement_v2(contents, length, &receipt) != 0) {
        sodium_memzero(contents, sizeof(contents));
        return -1;
    }
    sodium_memzero(contents, sizeof(contents));
    if (strcmp(receipt.status, "COMPLETE") == 0) {
        sodium_memzero(&receipt, sizeof(receipt));
        return 0;
    }
    if (strcmp(platform, "standalone") == 0) {
        sodium_memzero(&receipt, sizeof(receipt));
        return 0;
    }
    if (geteuid() != 0
        || strcmp(receipt.platform, platform) != 0
        || strcmp(receipt.service_id, service_id) != 0
        || strcmp(
            receipt.requested_launcher_generation,
            launcher_generation
        ) == 0
        || wls_all_zero_hex(receipt.requested_launcher_generation)
        || !wls_all_zero_hex(receipt.completed_launcher_generation)
        || !wls_all_zero_hex(receipt.completed_host_boot_id)
        || !wls_all_zero_hex(receipt.completed_runtime_generation)
        || wls_boot_id(boot_id) != 0) {
        sodium_memzero(&receipt, sizeof(receipt));
        return -1;
    }
    memcpy(receipt.status, "COMPLETE", 9U);
    memcpy(
        receipt.completed_launcher_generation,
        launcher_generation,
        65U
    );
    memcpy(receipt.completed_host_boot_id, boot_id, 65U);
    memcpy(
        receipt.completed_runtime_generation,
        runtime_generation,
        65U
    );
    read_result = wls_write_platform_retirement_v2(home, &receipt);
    sodium_memzero(&receipt, sizeof(receipt));
    sodium_memzero(boot_id, sizeof(boot_id));
    return read_result;
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
    long long legacy_total_deadline = 0;
    long long expected_legacy_total_deadline = 0;
    int rollback_phase;
    memset(state, 0, sizeof(*state));
    if (wls_join(path, sizeof(path), home, "trust/upgrade-state") != 0) return -1;
    if (wls_read_file(path, 1024U, &contents, &length) != 0) {
        return errno == ENOENT ? 0 : -1;
    }
    if (strncmp((const char *)contents, "WLS-UPGRADE-STATE/3\n", 20U) == 0) {
        fields = sscanf(
            (const char *)contents,
            "WLS-UPGRADE-STATE/3\n"
            "intent_sha256=%64[0-9a-f]\n"
            "intent_nonce=%32[0-9a-f]\n"
            "from=%1[AB]\n"
            "to=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "boot_id=%64[0-9a-f]\n"
            "phase=%23[A-Z_]\n"
            "attempts=%u\n"
            "prepared_monotonic_ms=%lld\n"
            "observation_started_monotonic_ms=%lld\n"
            "observation_deadline_monotonic_ms=%lld\n"
            "total_deadline_monotonic_ms=%lld\n%n",
            state->intent_sha256, state->nonce, from, to,
            state->runtime_generation, state->boot_id, state->phase,
            &state->attempts, &state->prepared_monotonic,
            &state->observation_started, &state->observation_deadline,
            &state->total_deadline_monotonic, &consumed
        );
        state->legacy_protocol = 0;
    } else {
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
            state->intent_sha256, state->nonce, from, to,
            state->runtime_generation, state->boot_id, state->phase,
            &state->attempts, &state->observation_started,
            &state->observation_deadline, &legacy_total_deadline, &consumed
        );
        state->legacy_protocol = 1;
    }
    free(contents);
    rollback_phase = strcmp(state->phase, "ROLLBACK_PENDING") == 0
        || strcmp(state->phase, "ROLLED_BACK") == 0;
    if ((state->legacy_protocol ? fields != 11 : fields != 12)
        || consumed != (int)length
        || strcmp(state->intent_sha256, upgrade->intent_sha256) != 0
        || strcmp(state->nonce, upgrade->nonce) != 0
        || from[0] != upgrade->from || to[0] != upgrade->to
        || strcmp(state->runtime_generation, upgrade->runtime_generation) != 0
        || state->attempts > WLS_UPGRADE_MAX_ATTEMPTS
        || (state->legacy_protocol
            ? (!upgrade->legacy_protocol
                || wls_checked_add_long_long(
                    upgrade->prepared_at,
                    WLS_UPGRADE_TOTAL_SECONDS,
                    &expected_legacy_total_deadline
                ) != 0
                || legacy_total_deadline != expected_legacy_total_deadline)
            : (upgrade->legacy_protocol
                ? (state->prepared_monotonic <= 0
                    || state->prepared_monotonic
                        > LLONG_MAX - WLS_UPGRADE_TOTAL_MILLISECONDS
                    || state->total_deadline_monotonic
                        != state->prepared_monotonic
                            + WLS_UPGRADE_TOTAL_MILLISECONDS)
                : ((!rollback_phase
                        && strcmp(state->boot_id, upgrade->boot_id) != 0)
                    || state->prepared_monotonic != upgrade->prepared_monotonic
                    || state->total_deadline_monotonic
                        != upgrade->total_deadline_monotonic)))
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
    char payload[768];
    int length;
    long long expected_observation_deadline = 0;
    if (attempts > WLS_UPGRADE_MAX_ATTEMPTS
        || phase == NULL
        || !wls_is_hex_text(boot_id, 64U)
        || upgrade->prepared_monotonic <= 0
        || upgrade->total_deadline_monotonic
            != upgrade->prepared_monotonic + WLS_UPGRADE_TOTAL_MILLISECONDS
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
        "WLS-UPGRADE-STATE/3\n"
        "intent_sha256=%s\n"
        "intent_nonce=%s\n"
        "from=%c\n"
        "to=%c\n"
        "runtime_generation=%s\n"
        "boot_id=%s\n"
        "phase=%s\n"
        "attempts=%u\n"
        "prepared_monotonic_ms=%lld\n"
        "observation_started_monotonic_ms=%lld\n"
        "observation_deadline_monotonic_ms=%lld\n"
        "total_deadline_monotonic_ms=%lld\n",
        upgrade->intent_sha256,
        upgrade->nonce,
        upgrade->from,
        upgrade->to,
        upgrade->runtime_generation,
        boot_id,
        phase,
        attempts,
        upgrade->prepared_monotonic,
        observation_started,
        observation_deadline,
        upgrade->total_deadline_monotonic
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
    char request_boot_id[65];
    long long requested_monotonic = 0;
    long long legacy_at = 0;
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
    if (!upgrade->legacy_protocol
        && sscanf(
            (const char *)contents,
            "WLS-UPGRADE-ROLLBACK/3\n"
            "intent_sha256=%64[0-9a-f]\n"
            "intent_nonce=%32[0-9a-f]\n"
            "from=%1[AB]\n"
            "to=%1[AB]\n"
            "host_boot_id=%64[0-9a-f]\n"
            "requested_monotonic_ms=%lld\n"
            "request_nonce=%32[0-9a-f]\n%n",
            intent_digest, intent_nonce, from, to, request_boot_id,
            &requested_monotonic, request_nonce, &consumed
        ) == 7
        && consumed == (int)length
        && strcmp(intent_digest, upgrade->intent_sha256) == 0
        && strcmp(intent_nonce, upgrade->nonce) == 0
        && from[0] == upgrade->to
        && to[0] == upgrade->from
        && strcmp(request_boot_id, upgrade->boot_id) == 0
        && requested_monotonic >= upgrade->prepared_monotonic
        && requested_monotonic <= upgrade->total_deadline_monotonic
        && wls_is_hex_text(request_nonce, 32U)) {
        result = 1;
    } else if (upgrade->legacy_protocol) {
        consumed = 0;
        if (sscanf(
                (const char *)contents,
                "WLS-UPGRADE-ROLLBACK/2\n"
                "intent_sha256=%64[0-9a-f]\n"
                "intent_nonce=%32[0-9a-f]\n"
                "from=%1[AB]\n"
                "to=%1[AB]\n"
                "at=%lld\n"
                "request_nonce=%32[0-9a-f]\n%n",
                intent_digest, intent_nonce, from, to, &legacy_at,
                request_nonce, &consumed
            ) == 6
            && consumed == (int)length
            && strcmp(intent_digest, upgrade->intent_sha256) == 0
            && strcmp(intent_nonce, upgrade->nonce) == 0
            && from[0] == upgrade->to && to[0] == upgrade->from
            && legacy_at > 0 && wls_is_hex_text(request_nonce, 32U)) {
            /* Legacy requests authorize only the safer rollback direction. */
            result = 1;
        }
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
    int rollback_target_verified = 0;
    long long expected_observation_deadline = 0;
    const char *rollback_reason = NULL;
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
    if (upgrade.legacy_protocol) {
        /* A legacy intent has no comparable boot/monotonic authority. Migrate
         * it only toward the safer previous slot; never extend its candidate. */
        memcpy(upgrade.boot_id, boot_id, sizeof(upgrade.boot_id));
        upgrade.prepared_monotonic = monotonic_now;
        upgrade.activation_deadline_monotonic = monotonic_now
            + WLS_UPGRADE_ACTIVATION_MILLISECONDS;
        upgrade.total_deadline_monotonic = monotonic_now
            + WLS_UPGRADE_TOTAL_MILLISECONDS;
        must_rollback = 1;
        rollback_reason = "legacy-protocol";
    } else if (strcmp(upgrade.boot_id, boot_id) != 0
        || monotonic_now < upgrade.prepared_monotonic) {
        /* Monotonic values are comparable only within the signed host boot. */
        must_rollback = 1;
        rollback_reason = strcmp(upgrade.boot_id, boot_id) != 0
            ? "host-boot-mismatch"
            : "monotonic-before-prepared";
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
                || transaction.observation_started
                    > upgrade.activation_deadline_monotonic
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
    if (active[0] == upgrade.to) {
        char candidate_generation[65];
        int candidate_valid;
        sodium_memzero(candidate_generation, sizeof(candidate_generation));
        candidate_valid = wls_launcher_slot_contract_v2(
                home, upgrade.to, candidate_generation
            ) == 0
            && sodium_memcmp(
                candidate_generation, upgrade.runtime_generation, 64U
            ) == 0;
        sodium_memzero(candidate_generation, sizeof(candidate_generation));
        if (!candidate_valid) {
            /* COMMITTED is a terminal decision. A legacy or externally damaged
             * committed slot fails closed instead of silently rewriting history;
             * every non-terminal candidate still falls back to the independently
             * baseline-proved source slot below. */
            if (state_status > 0
                && strcmp(transaction.phase, "COMMITTED") == 0) {
                fprintf(
                    stderr,
                    "committed gateway candidate no longer matches its host CA baseline\n"
                );
                return -1;
            }
            must_rollback = 1;
            rollback_reason = "candidate-slot-contract";
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
        must_rollback = 1;
        rollback_reason = "transaction-boot-mismatch";
        if (active[0] == upgrade.from
            || strcmp(transaction.phase, "ROLLBACK_PENDING") == 0) {
            if (wls_launcher_require_rollback_target_v2(
                    home, upgrade.from, &rollback_target_verified
                ) != 0
                || wls_upgrade_state_write(
                    home, &upgrade, boot_id, "ROLLBACK_PENDING",
                    attempts > WLS_UPGRADE_MAX_ATTEMPTS
                        ? WLS_UPGRADE_MAX_ATTEMPTS : attempts,
                    0, 0
                ) != 0) return -1;
            rollback_transitioned = 1;
        }
        state_status = wls_upgrade_state_read(home, &upgrade, &transaction);
        if (state_status < 1) return -1;
    }

    if (active[0] == upgrade.from) {
        if (wls_launcher_require_rollback_target_v2(
                home, upgrade.from, &rollback_target_verified
            ) != 0) return -1;
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
        if ((must_rollback
                && wls_launcher_require_rollback_target_v2(
                    home, upgrade.from, &rollback_target_verified
                ) != 0)
            || wls_upgrade_state_write(
                home,
                &upgrade,
                boot_id,
                must_rollback ? "ROLLBACK_PENDING" : "PREPARED",
                0U,
                0,
                0
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
        if (rollback_reason == NULL) rollback_reason = "persisted-rollback";
    }
    observation_present = !must_rollback
        ? wls_upgrade_observation_deadline(
            home,
            &upgrade,
            &transaction,
            boot_id,
            &observation_started,
            &observation_deadline
        )
        : 0;
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
    if (!must_rollback && !rollback_requested
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

    if (!must_rollback && !rollback_requested) {
        if (strcmp(transaction.phase, "PREPARED") == 0
            && monotonic_now >= upgrade.activation_deadline_monotonic) {
            must_rollback = 1;
            rollback_reason = "activation-deadline";
        } else if (strcmp(transaction.phase, "OBSERVING") == 0
            && monotonic_now >= transaction.observation_deadline) {
            must_rollback = 1;
            rollback_reason = "observation-deadline";
        }
    }

    if (count_candidate_failure) {
        attempts = transaction.attempts + 1U;
        if (attempts >= WLS_UPGRADE_MAX_ATTEMPTS) {
            must_rollback = 1;
            rollback_reason = "candidate-crash-budget";
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
        || upgrade.legacy_protocol
        || strcmp(upgrade.boot_id, boot_id) != 0
        || monotonic_now >= upgrade.total_deadline_monotonic) {
        must_rollback = 1;
        if (rollback_reason == NULL) {
            rollback_reason = rollback_requested
                ? "explicit-request"
                : (upgrade.legacy_protocol
                    ? "legacy-protocol"
                    : (strcmp(upgrade.boot_id, boot_id) != 0
                        ? "host-boot-mismatch"
                        : "monotonic-deadline"));
        }
    }
    if (must_rollback) {
        char slot_text[3] = {upgrade.from, '\n', '\0'};
        char previous_text[3] = {upgrade.to, '\n', '\0'};
        if (wls_launcher_require_rollback_target_v2(
                home, upgrade.from, &rollback_target_verified
            ) != 0) return -1;
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
        fprintf(
            stderr,
            "gateway candidate rollback awaits old-slot health proof "
            "(%s; monotonic=%lld prepared=%lld total=%lld)\n",
            rollback_reason == NULL ? "persisted-decision" : rollback_reason,
            monotonic_now,
            upgrade.prepared_monotonic,
            upgrade.total_deadline_monotonic
        );
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

/* 0 means the Broker completed its bounded shutdown; -1 requires the
 * platform supervisor to retire the whole service tree before relaunch. */
static int wls_terminate_broker(pid_t broker_pid, int signal_number)
{
    long long now;
    long long deadline;
    int status = 0;
    if (broker_pid <= 0) return -1;
    if (kill(
            broker_pid,
            signal_number > 0 ? signal_number : SIGTERM
        ) != 0 && errno != ESRCH) return -1;
    now = wls_monotonic_milliseconds();
    if (now > 0
        && wls_checked_add_long_long(
            now,
            WLS_BROKER_TERM_GRACE_MILLISECONDS,
            &deadline
        ) == 0
        && wls_wait_child_exit_until(
            broker_pid,
            &status,
            deadline
        ) == 0) {
        (void)wls_reap_exited_children(0, NULL, NULL);
        return 0;
    }
    if (kill(broker_pid, SIGKILL) != 0 && errno != ESRCH) return -1;
    now = wls_monotonic_milliseconds();
    if (now > 0
        && wls_checked_add_long_long(
            now,
            WLS_BROKER_KILL_REAP_MILLISECONDS,
            &deadline
        ) == 0) {
        (void)wls_wait_child_exit_until(
            broker_pid,
            &status,
            deadline
        );
    }
    (void)wls_reap_exited_children(0, NULL, NULL);
    /* A force-killed Broker could not retire its Controller/Nginx descendants.
     * Never start a replacement beneath this Launcher generation. */
    return -1;
}

/*
 * A service-manager stop is not a crash-recovery signal.  The Broker owns
 * the attested Nginx QUIT path and may consume the full 300-second worker
 * drain.  Never SIGKILL here: systemd/launchd owns the final 330-second tree
 * deadline and can retire descendants with its stable service handle.
 */
static int wls_gracefully_terminate_broker(pid_t broker_pid)
{
    long long now;
    long long deadline;
    int status = 0;
    if (broker_pid <= 0
        || (kill(broker_pid, SIGUSR1) != 0 && errno != ESRCH)) return -1;
    now = wls_monotonic_milliseconds();
    if (now <= 0
        || wls_checked_add_long_long(
            now,
            WLS_PLATFORM_LAUNCHER_SHUTDOWN_MILLISECONDS,
            &deadline
        ) != 0
        || wls_wait_child_exit_until(
            broker_pid,
            &status,
            deadline
        ) != 0) return -1;
    (void)wls_reap_exited_children(0, NULL, NULL);
    return 0;
}

static int wls_supervise_broker(
    pid_t broker_pid,
    const char *home,
    char active[2],
    const char *runtime_generation,
    struct wls_posix_recovery_context *recovery
)
{
    struct timespec pause = {0, 200000000L};
    unsigned long long recovery_observation_started = 0ULL;
    int status = 0;
    for (;;) {
        pid_t waited;
        int has_children = 1;
        int upgrade_state;
        int signal_number = wls_take_shutdown_signal();
        if (!wls_guardian_parent_alive()) {
            if (wls_terminate_broker(broker_pid, SIGTERM) != 0) {
                return WLS_SERVICE_TREE_RESTART;
            }
            return 0;
        }
        if (signal_number != 0) {
            if (signal_number == SIGTERM || signal_number == SIGINT) {
                if (wls_gracefully_terminate_broker(broker_pid) != 0) {
                    return WLS_SERVICE_TREE_RESTART;
                }
            } else if (wls_terminate_broker(broker_pid, SIGTERM) != 0) {
                return WLS_SERVICE_TREE_RESTART;
            }
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
            if (wls_terminate_broker(broker_pid, SIGTERM) != 0) {
                return WLS_SERVICE_TREE_RESTART;
            }
            return 1;
        }
        if (upgrade_state > 0) {
            /* Keep the stable launcher PID while rebuilding the verified slot. */
            if (wls_terminate_broker(broker_pid, SIGTERM) != 0) {
                return WLS_SERVICE_TREE_RESTART;
            }
            return WLS_CONTROL_TREE_RELOAD;
        }
        if (wls_recovery_observe_health(
                home,
                runtime_generation,
                recovery,
                &recovery_observation_started
            ) != 0) {
            if (wls_terminate_broker(broker_pid, SIGTERM) != 0) {
                return WLS_SERVICE_TREE_RESTART;
            }
            return WLS_SERVICE_TREE_RESTART;
        }
        while (nanosleep(&pause, &pause) != 0 && errno == EINTR) {
            if (wls_shutdown_signal != 0) break;
        }
        pause.tv_sec = 0;
        pause.tv_nsec = 200000000L;
    }
}

static int wls_launch(
    const char *home,
    const char *run_directory,
    const char *platform,
    const char *service_id,
    const char *launcher_generation
)
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
    char proved_runtime_generation[crypto_hash_sha256_BYTES * 2U + 1U];
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
    const char *data_plane_user =
#if defined(__APPLE__)
        "_welinegateway_nginx";
#else
        "weline-gateway-nginx";
#endif
    pid_t broker_pid;
    struct wls_posix_recovery_context recovery;
    int supervise_result;
    int pending_signal;
    int reconcile_result;
    int recovery_prepare;
    if (!wls_guardian_parent_alive()) {
        return 0;
    }
    if (wls_admin_stopped(home) != 0) {
        return 0;
    }
    if (wls_active_slot(home, active) != 0) return 1;
    reconcile_result = wls_reconcile_upgrade(home, active, 0, 1);
    if (reconcile_result < 0 || reconcile_result == 2) return 1;
    if (wls_launcher_slot_contract_v2(
            home, active[0], proved_runtime_generation
        ) != 0) {
        fprintf(
            stderr,
            "active gateway slot lacks the exact WLS 2.0 durable-state contract\n"
        );
        return 1;
    }
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
        || wls_verify_component(manifest, slot, "share/ca-bundle.pem") != 0
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
        || sodium_memcmp(
            runtime_generation,
            proved_runtime_generation,
            crypto_hash_sha256_BYTES * 2U
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
        sodium_memzero(
            proved_runtime_generation,
            sizeof(proved_runtime_generation)
        );
        return 1;
    }
    free(manifest);
    free(installed_manifest);
    sodium_memzero(
        proved_runtime_generation,
        sizeof(proved_runtime_generation)
    );
    recovery_prepare = wls_recovery_prepare_attempt(
        home,
        run_directory,
        platform,
        service_id,
        active[0],
        runtime_generation,
        &recovery
    );
    if (recovery_prepare == 1) return 0;
    if (recovery_prepare == 2) return WLS_CONTROL_TREE_RELOAD;
    if (recovery_prepare != 0) return 1;
    if (wls_promote_platform_retirement(
            home,
            platform,
            service_id,
            launcher_generation,
            runtime_generation
        ) != 0) {
        fprintf(
            stderr,
            "platform service generation could not prove pending process-tree retirement\n"
        );
        (void)wls_recovery_finish_attempt(
            &recovery, 0, "SPAWN_FAILED"
        );
        return 1;
    }
    pending_signal = wls_take_shutdown_signal();
    if (pending_signal == SIGTERM || pending_signal == SIGINT) {
        (void)wls_recovery_finish_attempt(&recovery, 1, NULL);
        return 0;
    }
    if (pending_signal == SIGHUP) {
        (void)wls_recovery_finish_attempt(&recovery, 1, NULL);
        return WLS_CONTROL_TREE_RELOAD;
    }
    if (wls_admin_stopped(home) != 0
        || wls_recovery_maintenance_pending(
            home, active[0], runtime_generation
        )) {
        (void)wls_recovery_finish_attempt(&recovery, 1, NULL);
        return 0;
    }
    broker_pid = fork();
    if (broker_pid < 0) {
        (void)wls_recovery_finish_attempt(
            &recovery, 0, "SPAWN_FAILED"
        );
        return 1;
    }
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
            "--data-plane-user",
            data_plane_user,
            "--active-slot",
            active,
            "--runtime-generation",
            runtime_generation,
            (char *)NULL
        );
        _exit(127);
    }
    supervise_result = wls_supervise_broker(
        broker_pid,
        home,
        active,
        runtime_generation,
        &recovery
    );
    if (supervise_result == WLS_SERVICE_TREE_RESTART
        && wls_seal_platform_retirement_pending(
            home,
            platform,
            service_id,
            launcher_generation,
            runtime_generation
        ) != 0) {
        fprintf(
            stderr,
            "platform service restart could not seal the pending process-tree retirement\n"
        );
    }
    if (wls_recovery_finish_attempt(
            &recovery,
            supervise_result == 0
                || supervise_result == WLS_CONTROL_TREE_RELOAD
                || wls_admin_stopped(home) != 0
                || wls_recovery_maintenance_pending(
                    home, active[0], runtime_generation
                ),
            supervise_result == WLS_SERVICE_TREE_RESTART
                ? "SUPERVISION_FAILED" : "BROKER_EXIT"
        ) != 0
        && supervise_result != 0) {
        return 1;
    }
    return supervise_result;
}

static int wls_launcher_self_test_installed_manifest(
    char *output,
    size_t capacity,
    const char *generation,
    const char *capabilities,
    const char *components
) {
    int length;
    if (output == NULL || capacity == 0U || generation == NULL
        || capabilities == NULL || components == NULL) return -1;
    length = snprintf(
        output,
        capacity,
        "{\"schema_version\":2,\"role\":\"host_gateway\",\"slot\":\"A\","
        "\"runtime_generation\":\"%s\",\"durable_state_contract\":{"
        "\"schema_version\":2,\"security_ledger_read_schema\":8,"
        "\"security_ledger_write_schema\":8,"
        "\"snapshot_receipt_read_schema\":2,"
        "\"snapshot_receipt_write_schema\":2,"
        "\"snapshot_namespace\":\"snapshots-v2\",\"nonce_wal_schema\":1,"
        "\"nginx_test_schema\":1},\"capabilities\":%s,\"components\":%s}",
        generation,
        capabilities,
        components
    );
    return length > 0 && (size_t)length < capacity ? length : -1;
}

static int wls_launcher_ca_bundle_baseline_self_test(void)
{
    static const unsigned char valid[] =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n";
    static const unsigned char different[] =
        "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n";
    static const unsigned char crlf[] =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\r\n";
    static const unsigned char missing_lf[] =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
    static const unsigned char uppercase[] =
        "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n";
    static const unsigned char non_hex[] =
        "gaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n";
    char parsed[65];
    char other[65];
    int result = -1;
    sodium_memzero(parsed, sizeof(parsed));
    sodium_memzero(other, sizeof(other));
    if (wls_launcher_parse_ca_bundle_baseline(
            valid, sizeof(valid) - 1U, parsed
        ) != 0
        || wls_launcher_parse_ca_bundle_baseline(
            different, sizeof(different) - 1U, other
        ) != 0
        || sodium_memcmp(parsed, other, 64U) == 0
        || wls_launcher_parse_ca_bundle_baseline(
            crlf, sizeof(crlf) - 1U, other
        ) == 0
        || wls_launcher_parse_ca_bundle_baseline(
            missing_lf, sizeof(missing_lf) - 1U, other
        ) == 0
        || wls_launcher_parse_ca_bundle_baseline(
            uppercase, sizeof(uppercase) - 1U, other
        ) == 0
        || wls_launcher_parse_ca_bundle_baseline(
            non_hex, sizeof(non_hex) - 1U, other
        ) == 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(parsed, sizeof(parsed));
    sodium_memzero(other, sizeof(other));
    return result;
}

/*
 * Release assembly invokes this fixed entry before it is allowed to advertise
 * stable_launcher_rollback_target_proof.  Exercise the exact parser used by
 * automatic rollback with a schema-2 release/installed pair and fail-closed
 * negative variants.  Signed filesystem closure remains covered by the
 * launcher lifecycle integration because a production signing key is never
 * embedded in, or exposed to, the launcher binary.
 */
static int wls_launcher_rollback_target_proof_self_test(void)
{
    static const char zeros[] =
        "0000000000000000000000000000000000000000000000000000000000000000";
    static const char capabilities[] =
        "{\"stable_launcher_rollback_target_proof\":true,"
        "\"certificate_public_trust_bundle\":true}";
    static const char false_capabilities[] =
        "{\"stable_launcher_rollback_target_proof\":false,"
        "\"certificate_public_trust_bundle\":true}";
    static const char false_ca_capabilities[] =
        "{\"stable_launcher_rollback_target_proof\":true,"
        "\"certificate_public_trust_bundle\":false}";
    static const char missing_ca_capabilities[] =
        "{\"stable_launcher_rollback_target_proof\":true}";
    static const char release_components_json[] =
        "{"
        "\"bin/wls-gateway-broker\":{\"sha256\":\""
        "1111111111111111111111111111111111111111111111111111111111111111"
        "\",\"size\":1,\"mode\":493},"
        "\"bin/wls-gateway-launcher\":{\"sha256\":\""
        "2222222222222222222222222222222222222222222222222222222222222222"
        "\",\"size\":2,\"mode\":493},"
        "\"bin/php\":{\"sha256\":\""
        "3333333333333333333333333333333333333333333333333333333333333333"
        "\",\"size\":3,\"mode\":493},"
        "\"bin/nginx\":{\"sha256\":\""
        "4444444444444444444444444444444444444444444444444444444444444444"
        "\",\"size\":4,\"mode\":493},"
        "\"app/controller.php\":{\"sha256\":\""
        "5555555555555555555555555555555555555555555555555555555555555555"
        "\",\"size\":5,\"mode\":420},"
        "\"share/ca-bundle.pem\":{\"sha256\":\""
        "6666666666666666666666666666666666666666666666666666666666666666"
        "\",\"size\":6,\"mode\":420}"
        "}";
    static const char installed_components_json[] =
        "{"
        "\"bin/wls-gateway-broker\":{\"sha256\":\""
        "1111111111111111111111111111111111111111111111111111111111111111"
        "\",\"size\":1,\"mode\":365},"
        "\"bin/wls-gateway-launcher\":{\"sha256\":\""
        "2222222222222222222222222222222222222222222222222222222222222222"
        "\",\"size\":2,\"mode\":365},"
        "\"bin/php\":{\"sha256\":\""
        "3333333333333333333333333333333333333333333333333333333333333333"
        "\",\"size\":3,\"mode\":365},"
        "\"bin/nginx\":{\"sha256\":\""
        "4444444444444444444444444444444444444444444444444444444444444444"
        "\",\"size\":4,\"mode\":365},"
        "\"app/controller.php\":{\"sha256\":\""
        "5555555555555555555555555555555555555555555555555555555555555555"
        "\",\"size\":5,\"mode\":292},"
        "\"share/ca-bundle.pem\":{\"sha256\":\""
        "6666666666666666666666666666666666666666666666666666666666666666"
        "\",\"size\":6,\"mode\":292},"
        "\"release/manifest.json\":{\"sha256\":\""
        "7777777777777777777777777777777777777777777777777777777777777777"
        "\",\"size\":7,\"mode\":292},"
        "\"release/manifest.sig\":{\"sha256\":\""
        "8888888888888888888888888888888888888888888888888888888888888888"
        "\",\"size\":8,\"mode\":292}"
        "}";
    static const char *required_components[] = {
        "bin/wls-gateway-broker",
        "bin/wls-gateway-launcher",
        "bin/php",
        "bin/nginx",
        "app/controller.php",
        "share/ca-bundle.pem",
    };
    char release[8192];
    char installed[8192];
    char invalid[8192];
    char generation[65];
    char parsed_generation[65];
    char release_digest[65];
    char installed_digest[65];
    unsigned long long release_size;
    unsigned long long installed_size;
    unsigned long long release_mode;
    unsigned long long installed_mode;
    unsigned long long expected_release_mode;
    unsigned long long expected_installed_mode;
    struct wls_launcher_json_field release_components = {0};
    struct wls_launcher_json_field installed_components = {0};
    int release_length;
    int installed_length;
    int invalid_length;
    char *generation_field;
    size_t index;

    release_length = snprintf(
        release,
        sizeof(release),
        "{\"schema_version\":2,\"implementation_level\":\"wls-2.0\","
        "\"durable_state_contract\":{\"schema_version\":2,"
        "\"security_ledger_read_schema\":8,"
        "\"security_ledger_write_schema\":8,"
        "\"snapshot_receipt_read_schema\":2,"
        "\"snapshot_receipt_write_schema\":2,"
        "\"snapshot_namespace\":\"snapshots-v2\",\"nonce_wal_schema\":1,"
        "\"nginx_test_schema\":1},\"capabilities\":%s,\"components\":%s}",
        capabilities,
        release_components_json
    );
    installed_length = wls_launcher_self_test_installed_manifest(
        installed, sizeof(installed), zeros, capabilities,
        installed_components_json
    );
    if (release_length <= 0 || (size_t)release_length >= sizeof(release)
        || installed_length <= 0
        || wls_launcher_json_canonical_digest(
            installed,
            (size_t)installed_length,
            "runtime_generation",
            generation
        ) != 0
        || wls_launcher_self_test_installed_manifest(
            installed, sizeof(installed), generation, capabilities,
            installed_components_json
        ) != installed_length
        || wls_launcher_manifest_contract(
            (const unsigned char *)release,
            (size_t)release_length,
            '\0',
            &release_components,
            parsed_generation
        ) != 0
        || parsed_generation[0] != '\0'
        || wls_launcher_manifest_contract(
            (const unsigned char *)installed,
            (size_t)installed_length,
            'A',
            &installed_components,
            parsed_generation
        ) != 0
        || sodium_memcmp(generation, parsed_generation, 64U) != 0) return 1;

    for (index = 0U;
        index < sizeof(required_components) / sizeof(required_components[0]);
        index++) {
        if (wls_launcher_component_definition(
                &release_components,
                required_components[index],
                release_digest,
                &release_size,
                &release_mode
            ) != 0
            || wls_launcher_component_definition(
                &installed_components,
                required_components[index],
                installed_digest,
                &installed_size,
                &installed_mode
            ) != 0
            || sodium_memcmp(release_digest, installed_digest, 64U) != 0
            || release_size != installed_size
            || wls_launcher_expected_component_modes(
                required_components[index],
                &expected_release_mode,
                &expected_installed_mode
            ) != 0
            || release_mode != expected_release_mode
            || installed_mode != expected_installed_mode) return 1;
    }
    if (wls_launcher_ca_bundle_baseline_self_test() != 0) return 1;

    invalid_length = wls_launcher_self_test_installed_manifest(
        invalid,
        sizeof(invalid),
        generation,
        false_capabilities,
        installed_components_json
    );
    if (invalid_length <= 0
        || wls_launcher_manifest_contract(
            (const unsigned char *)invalid,
            (size_t)invalid_length,
            'A',
            &installed_components,
            parsed_generation
        ) == 0) return 1;

    invalid_length = wls_launcher_self_test_installed_manifest(
        invalid,
        sizeof(invalid),
        generation,
        false_ca_capabilities,
        installed_components_json
    );
    if (invalid_length <= 0
        || wls_launcher_manifest_contract(
            (const unsigned char *)invalid,
            (size_t)invalid_length,
            'A',
            &installed_components,
            parsed_generation
        ) == 0) return 1;

    invalid_length = wls_launcher_self_test_installed_manifest(
        invalid,
        sizeof(invalid),
        generation,
        missing_ca_capabilities,
        installed_components_json
    );
    if (invalid_length <= 0
        || wls_launcher_manifest_contract(
            (const unsigned char *)invalid,
            (size_t)invalid_length,
            'A',
            &installed_components,
            parsed_generation
        ) == 0) return 1;

    memcpy(invalid, installed, (size_t)installed_length + 1U);
    generation_field = strstr(invalid, "\"runtime_generation\":\"");
    if (generation_field == NULL) return 1;
    generation_field += strlen("\"runtime_generation\":\"");
    generation_field[0] = generation_field[0] == '0' ? '1' : '0';
    if (wls_launcher_manifest_contract(
            (const unsigned char *)invalid,
            (size_t)installed_length,
            'A',
            &installed_components,
            parsed_generation
        ) == 0) return 1;
    sodium_memzero(generation, sizeof(generation));
    sodium_memzero(parsed_generation, sizeof(parsed_generation));
    sodium_memzero(release_digest, sizeof(release_digest));
    sodium_memzero(installed_digest, sizeof(installed_digest));
    return 0;
}

static int wls_guardian_lock(const char *run_directory, int *lock_fd)
{
    char path[PATH_MAX];
    struct stat status;
    int fd;
    if (run_directory == NULL || lock_fd == NULL
        || wls_join(path, sizeof(path), run_directory, "guardian.lock") != 0) {
        return -1;
    }
    fd = open(
        path,
        O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW,
        0600
    );
    if (fd < 0 || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode)
        || status.st_nlink != 1
        || status.st_uid != geteuid()
        || (status.st_mode & 0022) != 0
        || flock(fd, LOCK_EX | LOCK_NB) != 0) {
        if (fd >= 0) close(fd);
        return -1;
    }
    *lock_fd = fd;
    return 0;
}

static int wls_guardian_graceful_child_shutdown(
    pid_t child,
    long long grace_milliseconds
) {
    long long now;
    long long deadline;
    int status = 0;
    pid_t group;
    if (child <= 0 || grace_milliseconds <= 0) return -1;
    group = getpgid(child);
    if (group != child) return -1;
    /* Initial platform stop is main-process-only.  The verified Launcher and
     * Broker then perform the attested Nginx QUIT handoff themselves. */
    if (kill(child, SIGTERM) != 0 && errno != ESRCH) return -1;
    now = wls_monotonic_milliseconds();
    if (now <= 0
        || wls_checked_add_long_long(
            now, grace_milliseconds, &deadline
        ) != 0
        || wls_wait_child_exit_until(child, &status, deadline) != 0
        || !WIFEXITED(status)
        || WEXITSTATUS(status) != 0) return -1;
    return kill(-child, 0) != 0 && errno == ESRCH ? 0 : -1;
}

static void wls_platform_shutdown_self_test_signal_handler(int signal_number)
{
    wls_platform_shutdown_self_test_signal = signal_number;
}

static int wls_platform_shutdown_self_test_read(int fd, char *value)
{
    ssize_t amount;
    do {
        amount = read(fd, value, 1U);
    } while (amount < 0 && errno == EINTR);
    return amount == 1 ? 0 : -1;
}

/*
 * Isolated process-tree proof: the Guardian must signal only the Launcher
 * leader.  The leader then owns its descendant handoff.  A legacy group-wide
 * SIGTERM makes the grandchild report the wrong terminal code and fails.
 */
static int wls_guardian_platform_shutdown_self_test(void)
{
    int grandchild_ready[2] = {-1, -1};
    int leader_ready[2] = {-1, -1};
    int result_pipe[2] = {-1, -1};
    pid_t leader = -1;
    int status = 0;
    char result = 0;
    int test_result = 1;
    if (pipe(grandchild_ready) != 0
        || pipe(leader_ready) != 0
        || pipe(result_pipe) != 0) goto cleanup;
    leader = fork();
    if (leader < 0) goto cleanup;
    if (leader == 0) {
        pid_t grandchild;
        char ready = 0;
        close(leader_ready[0]);
        close(result_pipe[0]);
        if (setpgid(0, 0) != 0 || wls_install_signal_handlers() != 0) {
            _exit(120);
        }
        grandchild = fork();
        if (grandchild < 0) _exit(121);
        if (grandchild == 0) {
            struct sigaction action;
            close(grandchild_ready[0]);
            close(leader_ready[1]);
            close(result_pipe[1]);
            memset(&action, 0, sizeof(action));
            action.sa_handler = wls_platform_shutdown_self_test_signal_handler;
            sigemptyset(&action.sa_mask);
            if (sigaction(SIGTERM, &action, NULL) != 0
                || sigaction(SIGUSR1, &action, NULL) != 0
                || wls_write_all(grandchild_ready[1], "R", 1U) != 0) {
                _exit(122);
            }
            while (wls_platform_shutdown_self_test_signal == 0) pause();
            _exit(
                wls_platform_shutdown_self_test_signal == SIGUSR1 ? 42 : 43
            );
        }
        close(grandchild_ready[1]);
        if (wls_platform_shutdown_self_test_read(
                grandchild_ready[0], &ready
            ) != 0
            || ready != 'R'
            || wls_write_all(leader_ready[1], "R", 1U) != 0) {
            (void)kill(grandchild, SIGKILL);
            (void)wls_wait_child_exit_for(
                grandchild,
                NULL,
                WLS_BROKER_KILL_REAP_MILLISECONDS
            );
            _exit(123);
        }
        while (wls_shutdown_signal == 0) pause();
        if (wls_shutdown_signal != SIGTERM
            || kill(grandchild, SIGUSR1) != 0
            || wls_wait_child_exit_for(
                grandchild,
                &status,
                WLS_BROKER_TERM_GRACE_MILLISECONDS
            ) != 0
            || !WIFEXITED(status)
            || WEXITSTATUS(status) != 42
            || wls_write_all(result_pipe[1], "1", 1U) != 0) {
            _exit(124);
        }
        _exit(0);
    }
    close(grandchild_ready[0]); grandchild_ready[0] = -1;
    close(grandchild_ready[1]); grandchild_ready[1] = -1;
    close(leader_ready[1]); leader_ready[1] = -1;
    close(result_pipe[1]); result_pipe[1] = -1;
    if (setpgid(leader, leader) != 0
        && !(errno == EACCES && getpgid(leader) == leader)) goto cleanup;
    if (wls_platform_shutdown_self_test_read(leader_ready[0], &result) != 0
        || result != 'R'
        || wls_guardian_graceful_child_shutdown(leader, 3000LL) != 0
        || wls_platform_shutdown_self_test_read(result_pipe[0], &result) != 0
        || result != '1') goto cleanup;
    leader = -1;
    test_result = 0;
cleanup:
    if (leader > 0) {
        (void)kill(-leader, SIGKILL);
        (void)wls_wait_child_exit_for(
            leader,
            NULL,
            WLS_BROKER_KILL_REAP_MILLISECONDS
        );
    }
    if (grandchild_ready[0] >= 0) close(grandchild_ready[0]);
    if (grandchild_ready[1] >= 0) close(grandchild_ready[1]);
    if (leader_ready[0] >= 0) close(leader_ready[0]);
    if (leader_ready[1] >= 0) close(leader_ready[1]);
    if (result_pipe[0] >= 0) close(result_pipe[0]);
    if (result_pipe[1] >= 0) close(result_pipe[1]);
    return test_result;
}

static int wls_guardian_terminate_child_group(
    pid_t child,
    int signal_number
) {
    long long now;
    long long deadline;
    int status = 0;
    pid_t group;
    if (child <= 0) return -1;
    group = getpgid(child);
    if (group < 0 && errno == ESRCH) {
        if (kill(-child, 0) != 0 && errno == ESRCH) return 0;
        group = child;
    }
    if (group != child) return -1;
    if (kill(-child, signal_number) != 0 && errno != ESRCH) return -1;
    now = wls_monotonic_milliseconds();
    if (now > 0
        && wls_checked_add_long_long(
            now, WLS_BROKER_TERM_GRACE_MILLISECONDS, &deadline
        ) == 0
        && wls_wait_child_exit_until(child, &status, deadline) == 0) {
        if (kill(-child, 0) != 0 && errno == ESRCH) return 0;
    }
    if (kill(-child, SIGKILL) != 0 && errno != ESRCH) return -1;
    now = wls_monotonic_milliseconds();
    if (now > 0
        && wls_checked_add_long_long(
            now, WLS_BROKER_KILL_REAP_MILLISECONDS, &deadline
        ) == 0) {
        (void)wls_wait_child_exit_until(child, &status, deadline);
    }
    return kill(-child, 0) != 0 && errno == ESRCH ? 0 : -1;
}

static int wls_guardian_recover_command(
    const char *home,
    const char *run_directory
) {
    char guardian[PATH_MAX];
    char launcher[PATH_MAX];
    char guardian_digest[65];
    char launcher_digest[65];
    char executable[PATH_MAX];
    unsigned long long start_id = 0ULL;
    int lock_fd = -1;
    int result = 1;
    sodium_memzero(guardian, sizeof(guardian));
    sodium_memzero(launcher, sizeof(launcher));
    sodium_memzero(guardian_digest, sizeof(guardian_digest));
    sodium_memzero(launcher_digest, sizeof(launcher_digest));
    sodium_memzero(executable, sizeof(executable));
    if (wls_guardian_identity(
            home, guardian, guardian_digest
        ) != 0
        || wls_recovery_process_identity(
            getpid(), executable, sizeof(executable), &start_id
        ) != 0
        || strcmp(executable, guardian) != 0
        || wls_guardian_lock(run_directory, &lock_fd) != 0) {
        fprintf(
            stderr,
            "Recovery Guardian restore command lacks immutable identity or singleton ownership\n"
        );
        goto cleanup;
    }
    result = wls_guardian_recovery_restore(home, run_directory) == 0 ? 0 : 1;
cleanup:
    if (lock_fd >= 0) close(lock_fd);
    sodium_memzero(guardian, sizeof(guardian));
    sodium_memzero(launcher, sizeof(launcher));
    sodium_memzero(guardian_digest, sizeof(guardian_digest));
    sodium_memzero(launcher_digest, sizeof(launcher_digest));
    sodium_memzero(executable, sizeof(executable));
    return result;
}

static int wls_guardian_platform_live_verify(
    const char *home,
    const struct wls_guardian_transition_request *request
) {
    char definition[PATH_MAX];
    char metadata[PATH_MAX];
    mode_t definition_mode;
    mode_t metadata_mode;
    int result = -1;
    sodium_memzero(definition, sizeof(definition));
    sodium_memzero(metadata, sizeof(metadata));
    if (home == NULL || request == NULL
        || wls_guardian_platform_target(
            home, request, definition, &definition_mode
        ) != 0
        || wls_join(
            metadata,
            sizeof(metadata),
            home,
            "trust/platform-service.json"
        ) != 0) goto cleanup;
    metadata_mode = strcmp(request->platform_kind, "test-session") == 0
        ? 0600 : 0440;
    if (wls_guardian_atomic_replay_target_safe(
            definition, definition_mode, 0
        ) != 0
        || wls_guardian_atomic_replay_target_safe(
            metadata, metadata_mode, 0
        ) != 0
        || wls_guardian_verify_regular_digest(
            definition,
            request->platform_definition_sha256,
            definition_mode,
            (off_t)1048576
        ) != 0
        || wls_guardian_verify_regular_digest(
            metadata,
            request->platform_metadata_sha256,
            metadata_mode,
            (off_t)16384
        ) != 0
        || (strcmp(request->platform_kind, "systemd-system") == 0
            && wls_guardian_systemd_fixed_link_verify(definition) != 0)) {
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(definition, sizeof(definition));
    sodium_memzero(metadata, sizeof(metadata));
    return result;
}

/* Return with guardian-generation-head.lock still held. The caller forks
 * immediately; its CLOEXEC copy fences the child until the immutable
 * transition and recovery closure is safe to execute. */
static int wls_guardian_preflight_lock(
    const char *home,
    const char *run_directory,
    int *preflight_lock_fd
) {
    struct wls_guardian_transition_request request;
    struct wls_guardian_generation_head head;
    struct wls_guardian_recovery_transaction transaction;
    int attempt;
    int lock_attempt;
    int lock_result;
    int request_present;
    int transaction_complete;
    int lock_fd = -1;
    int result = -1;
    struct timespec retry_pause = {0, 200000000L};
    memset(&request, 0, sizeof(request));
    memset(&head, 0, sizeof(head));
    memset(&transaction, 0, sizeof(transaction));
    if (home == NULL || run_directory == NULL || preflight_lock_fd == NULL) {
        goto cleanup;
    }
    *preflight_lock_fd = -1;
    for (attempt = 0; attempt < 3; ++attempt) {
        request_present = 0;
        lock_result = -1;
        for (lock_attempt = 0; lock_attempt < 25; ++lock_attempt) {
            lock_result = wls_guardian_transition_lock(home, &lock_fd);
            if (lock_result != 1) break;
            while (nanosleep(&retry_pause, &retry_pause) != 0
                && errno == EINTR) {
                if (wls_shutdown_signal != 0) goto cleanup;
            }
            retry_pause.tv_sec = 0;
            retry_pause.tv_nsec = 200000000L;
        }
        if (lock_result != 0
            || wls_guardian_atomic_recover_locked(
                home, &request, &head, &request_present
            ) != 0) goto cleanup;
        if (strcmp(head.phase, "FAILED_CLOSED") == 0) goto cleanup;
        transaction_complete = request_present
            && wls_guardian_recovery_transaction_read(
                home, &transaction
            ) == 0
            && wls_guardian_recovery_transaction_binding(
                home, &transaction, &request
            ) == 0
            && (strcmp(transaction.phase, "RESTORED") == 0
                || strcmp(transaction.phase, "OBSERVING") == 0
                || strcmp(transaction.phase, "STABLE") == 0);
        if (request_present
            && (strcmp(head.phase, "ROLLBACK_PENDING") == 0
                || strcmp(head.phase, "ROLLBACK_OBSERVING") == 0)
            && (!transaction_complete
                || wls_guardian_generation_closure(
                    home,
                    request.recovery_launcher_sha256,
                    request.recovery_ca_sha256,
                    request.recovery_runtime_generation
                ) != 0
                || wls_guardian_recovery_roots_verify(
                    home, &request
                ) != 0
                || wls_guardian_platform_live_verify(
                    home, &request
                ) != 0)) {
            close(lock_fd);
            lock_fd = -1;
            if (wls_guardian_recovery_restore(
                    home, run_directory
                ) != 0) goto cleanup;
            sodium_memzero(&request, sizeof(request));
            sodium_memzero(&head, sizeof(head));
            sodium_memzero(&transaction, sizeof(transaction));
            continue;
        }
        *preflight_lock_fd = lock_fd;
        lock_fd = -1;
        result = 0;
        goto cleanup;
    }
cleanup:
    if (lock_fd >= 0) close(lock_fd);
    sodium_memzero(&request, sizeof(request));
    sodium_memzero(&head, sizeof(head));
    sodium_memzero(&transaction, sizeof(transaction));
    return result;
}

static int wls_guardian_run(
    const char *home,
    const char *run_directory
) {
    char guardian[PATH_MAX];
    char launcher[PATH_MAX];
    char guardian_digest[65];
    char launcher_digest[65];
    char executable[PATH_MAX];
    char parent_pid[32];
    char parent_start[32];
    unsigned long long start_id = 0ULL;
    long long next_transition_probe_ms = 0LL;
    struct wls_guardian_probation_observation observation;
    struct timespec pause = {0, 200000000L};
    int lock_fd = -1;
    int result = 1;
    memset(&observation, 0, sizeof(observation));
    observation.reset_existing_probation = 1;
    if (wls_guardian_identity(
            home, guardian, guardian_digest
        ) != 0
        || wls_recovery_process_identity(
            getpid(), executable, sizeof(executable), &start_id
        ) != 0
        || strcmp(executable, guardian) != 0
        || snprintf(parent_pid, sizeof(parent_pid), "%ld", (long)getpid())
            >= (int)sizeof(parent_pid)
        || snprintf(
            parent_start, sizeof(parent_start), "%llu", start_id
        ) >= (int)sizeof(parent_start)
        || wls_guardian_lock(run_directory, &lock_fd) != 0) {
        fprintf(stderr, "Recovery Guardian identity or singleton lock is invalid\n");
        goto cleanup;
    }
    for (;;) {
        pid_t child;
        int status = 0;
        int recovery_restart = 0;
        int preflight_lock_fd = -1;
        if (wls_guardian_preflight_lock(
                home, run_directory, &preflight_lock_fd
            ) != 0) {
            fprintf(stderr, "Recovery Guardian preflight closure is invalid\n");
            result = WLS_SERVICE_TREE_RESTART;
            goto cleanup;
        }
        if (wls_guardian_paths(
                home,
                guardian,
                launcher,
                guardian_digest,
                launcher_digest
            ) != 0) {
            close(preflight_lock_fd);
            fprintf(stderr, "Recovery Guardian child identity changed\n");
            result = 1;
            goto cleanup;
        }
        child = fork();
        if (child < 0) {
            close(preflight_lock_fd);
            result = 1;
            goto cleanup;
        }
        if (child == 0) {
            if (setpgid(0, 0) != 0) _exit(126);
            execl(
                launcher,
                launcher,
                "--guardian-child",
                "--service",
                "--home",
                home,
                "--run",
                run_directory,
                "--guardian-parent-pid",
                parent_pid,
                "--guardian-parent-start-id",
                parent_start,
                (char *)NULL
            );
            _exit(127);
        }
        close(preflight_lock_fd);
        preflight_lock_fd = -1;
        if (setpgid(child, child) != 0
            && !(errno == EACCES && getpgid(child) == child)) {
            (void)kill(child, SIGKILL);
            (void)wls_wait_child_exit_for(
                child,
                &status,
                WLS_BROKER_KILL_REAP_MILLISECONDS
            );
            result = 1;
            goto cleanup;
        }
        next_transition_probe_ms = 0LL;
        for (;;) {
            pid_t waited;
            int signal_number = wls_take_shutdown_signal();
            int transition_result = 0;
            long long transition_now = wls_monotonic_milliseconds();
            if (transition_now <= 0) {
                transition_result = -1;
            } else if (next_transition_probe_ms == 0LL
                || transition_now >= next_transition_probe_ms) {
                transition_result = wls_guardian_transition_tick(
                    home,
                    run_directory,
                    0,
                    &observation
                );
                if (wls_checked_add_long_long(
                        transition_now,
                        WLS_GUARDIAN_PROBE_INTERVAL_MILLISECONDS,
                        &next_transition_probe_ms
                    ) != 0) {
                    transition_result = -1;
                }
            }
            if (transition_result == -2) {
                if (wls_guardian_terminate_child_group(child, SIGTERM) != 0
                    || wls_guardian_recovery_restore(
                        home, run_directory
                    ) != 0) {
                    result = WLS_SERVICE_TREE_RESTART;
                    goto cleanup;
                }
                sodium_memzero(&observation, sizeof(observation));
                observation.reset_existing_probation = 1;
                recovery_restart = 1;
                break;
            }
            if (transition_result < 0) {
                (void)wls_guardian_terminate_child_group(child, SIGTERM);
                result = WLS_SERVICE_TREE_RESTART;
                goto cleanup;
            }
            if (signal_number == SIGTERM || signal_number == SIGINT) {
                result = wls_guardian_graceful_child_shutdown(
                    child,
                    WLS_PLATFORM_GUARDIAN_SHUTDOWN_MILLISECONDS
                ) == 0 ? 0 : WLS_SERVICE_TREE_RESTART;
                goto cleanup;
            }
            if (signal_number == SIGHUP) {
                pid_t group = getpgid(child);
                if (group != child
                    || (kill(child, SIGHUP) != 0 && errno != ESRCH)) {
                    result = WLS_SERVICE_TREE_RESTART;
                    goto cleanup;
                }
            }
            waited = waitpid(child, &status, WNOHANG);
            if (waited == child) {
                break;
            }
            if (waited < 0 && errno != EINTR) {
                result = WLS_SERVICE_TREE_RESTART;
                goto cleanup;
            }
            while (nanosleep(&pause, &pause) != 0 && errno == EINTR) {
                if (wls_shutdown_signal != 0) break;
            }
            pause.tv_sec = 0;
            pause.tv_nsec = 200000000L;
        }
        if (recovery_restart) {
            /* The immutable Guardian stays resident while the now-restored
             * stable Launcher path is re-opened and re-verified on the next
             * outer iteration. */
            continue;
        }
        result = WIFEXITED(status)
            ? WEXITSTATUS(status)
            : (WIFSIGNALED(status) ? 128 + WTERMSIG(status) : 1);
        if (result != WLS_CONTROL_TREE_RELOAD) {
            int exit_transition = wls_guardian_transition_tick(
                home,
                run_directory,
                1,
                &observation
            );
            if (exit_transition == -2) {
                if (wls_guardian_terminate_child_group(child, SIGKILL) != 0
                    || wls_guardian_recovery_restore(
                        home, run_directory
                    ) != 0) {
                    result = WLS_SERVICE_TREE_RESTART;
                    goto cleanup;
                }
                sodium_memzero(&observation, sizeof(observation));
                observation.reset_existing_probation = 1;
                continue;
            }
            if (exit_transition < 0) {
                result = WLS_SERVICE_TREE_RESTART;
            }
        }
        if (result != WLS_CONTROL_TREE_RELOAD) {
            if (result == WLS_SERVICE_TREE_RESTART) {
                (void)wls_guardian_terminate_child_group(child, SIGKILL);
            }
            goto cleanup;
        }
        if (kill(-child, 0) == 0 || errno != ESRCH) {
            if (wls_guardian_terminate_child_group(child, SIGTERM) != 0) {
                result = WLS_SERVICE_TREE_RESTART;
                goto cleanup;
            }
        }
        /* The immutable Guardian stays resident across a normal control-tree
         * reload and starts a freshly verified stable Launcher child. */
    }
cleanup:
    if (lock_fd >= 0) close(lock_fd);
    sodium_memzero(guardian, sizeof(guardian));
    sodium_memzero(launcher, sizeof(launcher));
    sodium_memzero(guardian_digest, sizeof(guardian_digest));
    sodium_memzero(launcher_digest, sizeof(launcher_digest));
    sodium_memzero(executable, sizeof(executable));
    sodium_memzero(&observation, sizeof(observation));
    return result;
}

struct wls_capacity_evidence {
    unsigned long long physical_bytes;
    unsigned int inode_count;
    char volume_id[65];
    char entry_set_sha256[65];
    char anchor_set_sha256[65];
};

/* The home reserve protects derived gateway state.  The platform definition
 * always receives a separate, directly adjacent reservation: same st_dev
 * does not prove that a directory quota or its atomic-write inode budget is
 * shared.  separate_filesystem records topology for diagnostics/self-tests;
 * it never weakens the definition-side reservation. */
struct wls_capacity_platform_anchor {
    int separate_filesystem;
    dev_t device;
    struct stat definition_identity;
    struct stat parent_identity;
    char definition[PATH_MAX];
    char parent[PATH_MAX];
    char reserve_prefix[64];
};

static int wls_capacity_is_hex(const char *value, size_t length)
{
    size_t index;
    if (value == NULL || strlen(value) != length) return 0;
    for (index = 0U; index < length; index++) {
        if (!((value[index] >= '0' && value[index] <= '9')
            || (value[index] >= 'a' && value[index] <= 'f'))) return 0;
    }
    return 1;
}

static int wls_capacity_unsigned(
    const char *value,
    unsigned long long maximum,
    unsigned long long *parsed
)
{
    const char *cursor;
    char *end = NULL;
    unsigned long long result;
    if (value == NULL || value[0] == '\0' || parsed == NULL
        || value[0] == '+' || value[0] == '-') return -1;
    for (cursor = value; *cursor != '\0'; cursor++) {
        if (*cursor < '0' || *cursor > '9') return -1;
    }
    errno = 0;
    result = strtoull(value, &end, 10);
    if (errno != 0 || end == value || *end != '\0' || result > maximum) {
        return -1;
    }
    *parsed = result;
    return 0;
}

static int wls_capacity_regular_at(
    int directory_fd,
    const char *leaf,
    struct stat *status,
    int optional
)
{
    struct stat path_status;
    struct stat opened_status;
    int fd = openat(
        directory_fd,
        leaf,
        O_RDONLY | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0) {
        return optional && errno == ENOENT ? 1 : -1;
    }
    if (fstat(fd, &opened_status) != 0
        || fstatat(
            directory_fd,
            leaf,
            &path_status,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !S_ISREG(opened_status.st_mode)
        || !S_ISREG(path_status.st_mode)
        || opened_status.st_nlink != 1
        || path_status.st_nlink != 1
        || (opened_status.st_mode & 0777) != 0600
        || (path_status.st_mode & 0777) != 0600
        || opened_status.st_uid != geteuid()
        || path_status.st_uid != geteuid()
        || opened_status.st_dev != path_status.st_dev
        || opened_status.st_ino != path_status.st_ino) {
        close(fd);
        return -1;
    }
    if (status != NULL) *status = opened_status;
    if (close(fd) != 0) return -1;
    return 0;
}

static int wls_capacity_directory_at(
    int directory_fd,
    const char *leaf,
    struct stat *status,
    int optional
)
{
    struct stat path_status;
    struct stat opened_status;
    int fd = openat(
        directory_fd,
        leaf,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0) {
        return optional && errno == ENOENT ? 1 : -1;
    }
    if (fstat(fd, &opened_status) != 0
        || fstatat(
            directory_fd,
            leaf,
            &path_status,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !S_ISDIR(opened_status.st_mode)
        || !S_ISDIR(path_status.st_mode)
        || (opened_status.st_mode & 0777) != 0700
        || (path_status.st_mode & 0777) != 0700
        || opened_status.st_uid != geteuid()
        || path_status.st_uid != geteuid()
        || opened_status.st_dev != path_status.st_dev
        || opened_status.st_ino != path_status.st_ino) {
        close(fd);
        return -1;
    }
    if (status != NULL) *status = opened_status;
    return fd;
}

static int wls_capacity_allocate_file_at(
    int directory_fd,
    const char *leaf,
    unsigned long long bytes
)
{
    struct stat status;
    unsigned char marker = 0xA5U;
    int allocation_result = -1;
    int fd;
    if (bytes == 0ULL || bytes > (unsigned long long)LLONG_MAX) return -1;
    fd = openat(
        directory_fd,
        leaf,
        O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW,
        0600
    );
    if (fd < 0) return -1;
    if (fchmod(fd, 0600) != 0) {
        close(fd);
        (void)unlinkat(directory_fd, leaf, 0);
        return -1;
    }
#if defined(__APPLE__)
    {
        fstore_t store;
        memset(&store, 0, sizeof(store));
        store.fst_flags = F_ALLOCATECONTIG;
        store.fst_posmode = F_PEOFPOSMODE;
        store.fst_offset = 0;
        store.fst_length = (off_t)bytes;
        allocation_result = fcntl(fd, F_PREALLOCATE, &store);
        if (allocation_result != 0) {
            store.fst_flags = F_ALLOCATEALL;
            allocation_result = fcntl(fd, F_PREALLOCATE, &store);
        }
    }
#elif defined(__linux__)
    allocation_result = posix_fallocate(fd, 0, (off_t)bytes) == 0 ? 0 : -1;
#else
    (void)allocation_result;
#endif
    if (allocation_result != 0
        || ftruncate(fd, (off_t)bytes) != 0
        || pwrite(fd, &marker, 1U, 0) != 1
        || pwrite(fd, &marker, 1U, (off_t)(bytes - 1ULL)) != 1
        || fsync(fd) != 0
        || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode)
        || status.st_nlink != 1
        || status.st_uid != geteuid()
        || (status.st_mode & 0777) != 0600
        || (unsigned long long)status.st_size != bytes
        || status.st_blocks <= 0
        || (unsigned long long)status.st_blocks > ULLONG_MAX / 512ULL
        || (unsigned long long)status.st_blocks * 512ULL < bytes) {
        close(fd);
        (void)unlinkat(directory_fd, leaf, 0);
        return -1;
    }
    return close(fd) == 0 ? 0 : -1;
}

static int wls_capacity_token_leaf(
    unsigned int index,
    char leaf[17]
)
{
    int written = snprintf(
        leaf,
        17U,
        "%08x%s",
        index,
        WLS_CAPACITY_TOKEN_SUFFIX
    );
    return written == 16 ? 0 : -1;
}

static int wls_capacity_parse_token_leaf(
    const char *leaf,
    unsigned int maximum,
    unsigned int *index
)
{
    char digits[9];
    char *end = NULL;
    unsigned long parsed;
    size_t cursor;
    if (leaf == NULL || index == NULL || strlen(leaf) != 16U
        || strcmp(leaf + 8U, WLS_CAPACITY_TOKEN_SUFFIX) != 0) return -1;
    memcpy(digits, leaf, 8U);
    digits[8] = '\0';
    for (cursor = 0U; cursor < 8U; cursor++) {
        if (!((digits[cursor] >= '0' && digits[cursor] <= '9')
            || (digits[cursor] >= 'a' && digits[cursor] <= 'f'))) return -1;
    }
    errno = 0;
    parsed = strtoul(digits, &end, 16);
    if (errno != 0 || end == digits || *end != '\0'
        || parsed >= maximum) return -1;
    *index = (unsigned int)parsed;
    return 0;
}

static unsigned int wls_capacity_token_directory_syncs(unsigned int count)
{
    return count / WLS_CAPACITY_TOKEN_FSYNC_BATCH
        + (count % WLS_CAPACITY_TOKEN_FSYNC_BATCH == 0U ? 0U : 1U);
}

static int wls_capacity_create_tokens(
    int live_fd,
    const char *leaf,
    unsigned int count
)
{
    unsigned int index;
    unsigned int sync_count = 0U;
    int token_fd;
    if (mkdirat(live_fd, leaf, 0700) != 0) return -1;
    token_fd = wls_capacity_directory_at(live_fd, leaf, NULL, 0);
    if (token_fd < 0) return -1;
    for (index = 0U; index < count; index++) {
        char token[17];
        struct stat status;
        int fd;
        if (wls_capacity_token_leaf(index, token) != 0) {
            close(token_fd);
            return -1;
        }
        fd = openat(
            token_fd,
            token,
            O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW,
            0600
        );
        if (fd < 0) {
            close(token_fd);
            return -1;
        }
        if (fchmod(fd, 0600) != 0 || fstat(fd, &status) != 0
            || !S_ISREG(status.st_mode) || status.st_nlink != 1
            || status.st_uid != geteuid()
            || (status.st_mode & 0777) != 0600
            || status.st_size != 0) {
            close(fd);
            (void)unlinkat(token_fd, token, 0);
            close(token_fd);
            return -1;
        }
        if (close(fd) != 0) {
            (void)unlinkat(token_fd, token, 0);
            close(token_fd);
            return -1;
        }
        if (((index + 1U) % WLS_CAPACITY_TOKEN_FSYNC_BATCH == 0U
                || index + 1U == count)
            && fsync(token_fd) != 0) {
            close(token_fd);
            return -1;
        }
        if ((index + 1U) % WLS_CAPACITY_TOKEN_FSYNC_BATCH == 0U
            || index + 1U == count) sync_count++;
    }
    if (sync_count != wls_capacity_token_directory_syncs(count)) {
        close(token_fd);
        return -1;
    }
    return close(token_fd) == 0 ? 0 : -1;
}

static int wls_capacity_inode_compare(const void *left, const void *right)
{
    const ino_t left_value = *(const ino_t *)left;
    const ino_t right_value = *(const ino_t *)right;
    if (left_value < right_value) return -1;
    if (left_value > right_value) return 1;
    return 0;
}

static int wls_capacity_hash_stat(
    crypto_hash_sha256_state *hash,
    const char *leaf,
    const struct stat *status
)
{
    char record[256];
    int length;
    if (hash == NULL || leaf == NULL || status == NULL) return -1;
    length = snprintf(
        record,
        sizeof(record),
        "%s\n%llu\n%llu\n%llu\n%llu\n",
        leaf,
        (unsigned long long)status->st_dev,
        (unsigned long long)status->st_ino,
        (unsigned long long)status->st_size,
        (unsigned long long)status->st_blocks
    );
    if (length <= 0 || (size_t)length >= sizeof(record)) return -1;
    return crypto_hash_sha256_update(
        hash,
        (const unsigned char *)record,
        (unsigned long long)length
    ) == 0 ? 0 : -1;
}

static int wls_capacity_validate_control_tokens(
    int live_fd,
    dev_t expected_device,
    int require_exact
)
{
    unsigned char seen[WLS_CAPACITY_CONTROL_INODES];
    struct dirent *entry;
    DIR *directory = NULL;
    unsigned int found = 0U;
    int token_fd = wls_capacity_directory_at(
        live_fd,
        "control-tokens",
        NULL,
        1
    );
    if (token_fd == 1) return require_exact ? -1 : 0;
    if (token_fd < 0) return -1;
    memset(seen, 0, sizeof(seen));
    directory = fdopendir(dup(token_fd));
    if (directory == NULL) {
        close(token_fd);
        return -1;
    }
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        struct stat status;
        unsigned int index;
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (wls_capacity_parse_token_leaf(
                entry->d_name,
                WLS_CAPACITY_CONTROL_INODES,
                &index
            ) != 0
            || seen[index] != 0U
            || wls_capacity_regular_at(
                token_fd,
                entry->d_name,
                &status,
                0
            ) != 0
            || status.st_dev != expected_device
            || status.st_size != 0) {
            closedir(directory);
            close(token_fd);
            return -1;
        }
        seen[index] = 1U;
        found++;
        errno = 0;
    }
    if (errno != 0
        || (require_exact && found != WLS_CAPACITY_CONTROL_INODES)
        || closedir(directory) != 0 || close(token_fd) != 0) {
        return -1;
    }
    return 0;
}

static int wls_capacity_validate_tokens(
    int live_fd,
    unsigned int target_inodes,
    dev_t expected_device,
    ino_t byte_inode,
    crypto_hash_sha256_state *hash
)
{
    unsigned char *seen = NULL;
    ino_t *inodes = NULL;
    struct dirent *entry;
    DIR *directory = NULL;
    unsigned int found = 0U;
    unsigned int index;
    int token_fd = -1;
    int result = -1;
    if (target_inodes == 0U || hash == NULL) return -1;
    seen = calloc((size_t)target_inodes, sizeof(*seen));
    inodes = calloc((size_t)target_inodes + 1U, sizeof(*inodes));
    if (seen == NULL || inodes == NULL) goto cleanup;
    inodes[0] = byte_inode;
    token_fd = wls_capacity_directory_at(live_fd, "tokens", NULL, 0);
    if (token_fd < 0) goto cleanup;
    directory = fdopendir(dup(token_fd));
    if (directory == NULL) goto cleanup;
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        struct stat status;
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (wls_capacity_parse_token_leaf(
                entry->d_name,
                target_inodes,
                &index
            ) != 0
            || seen[index] != 0U
            || wls_capacity_regular_at(
                token_fd,
                entry->d_name,
                &status,
                0
            ) != 0
            || status.st_dev != expected_device
            || status.st_size != 0) goto cleanup;
        seen[index] = 1U;
        inodes[(size_t)index + 1U] = status.st_ino;
        found++;
        errno = 0;
    }
    if (errno != 0 || found != target_inodes) goto cleanup;
    if (closedir(directory) != 0) {
        directory = NULL;
        goto cleanup;
    }
    directory = NULL;
    for (index = 0U; index < target_inodes; index++) {
        char token[17];
        struct stat status;
        if (seen[index] == 0U
            || wls_capacity_token_leaf(index, token) != 0
            || wls_capacity_regular_at(token_fd, token, &status, 0) != 0
            || status.st_ino != inodes[(size_t)index + 1U]
            || wls_capacity_hash_stat(hash, token, &status) != 0) {
            goto cleanup;
        }
    }
    qsort(
        inodes,
        (size_t)target_inodes + 1U,
        sizeof(*inodes),
        wls_capacity_inode_compare
    );
    for (index = 1U; index <= target_inodes; index++) {
        if (inodes[index - 1U] == inodes[index]) goto cleanup;
    }
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    if (token_fd >= 0) close(token_fd);
    free(seen);
    free(inodes);
    return result;
}

/* 0=present unmarked, 1=present release-marked, 2=absent, -1=unsafe. */
static int wls_capacity_control_marker_state(
    int live_fd,
    dev_t expected_device
)
{
    char marker[sizeof(WLS_CAPACITY_RELEASE_MARKER) - 1U];
    struct stat status;
    int present = wls_capacity_regular_at(
        live_fd,
        "control.reserve",
        &status,
        1
    );
    int fd;
    ssize_t amount;
    if (present == 1) return 2;
    if (present != 0 || status.st_dev != expected_device
        || status.st_size != (off_t)WLS_CAPACITY_CONTROL_BYTES
        || status.st_blocks <= 0
        || (unsigned long long)status.st_blocks > ULLONG_MAX / 512ULL
        || (unsigned long long)status.st_blocks * 512ULL
            < WLS_CAPACITY_CONTROL_BYTES) return -1;
    fd = openat(
        live_fd,
        "control.reserve",
        O_RDONLY | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0) return -1;
    amount = pread(fd, marker, sizeof(marker), 0);
    if (close(fd) != 0 || amount != (ssize_t)sizeof(marker)) return -1;
    return sodium_memcmp(
        marker,
        WLS_CAPACITY_RELEASE_MARKER,
        sizeof(marker)
    ) == 0 ? 1 : 0;
}

static int wls_capacity_validate_control_state(
    int live_fd,
    dev_t expected_device,
    int required_state
)
{
    struct stat ignored;
    int marker = wls_capacity_control_marker_state(
        live_fd,
        expected_device
    );
    int tokens = wls_capacity_directory_at(
        live_fd,
        "control-tokens",
        &ignored,
        1
    );
    if (tokens >= 0 && tokens != 1) close(tokens);
    if (marker < 0 || tokens < 0) return -1;
    if (required_state == WLS_CAPACITY_CONTROL_REQUIRED) {
        return marker == 0 && tokens != 1
            && wls_capacity_validate_control_tokens(
                live_fd,
                expected_device,
                1
            ) == 0 ? 0 : -1;
    }
    if (required_state == WLS_CAPACITY_CONTROL_TRANSITION) {
        return marker == 1
            && wls_capacity_validate_control_tokens(
                live_fd,
                expected_device,
                0
            ) == 0 ? 0 : -1;
    }
    if (required_state == WLS_CAPACITY_CONTROL_ABSENT) {
        return marker == 2 && tokens == 1 ? 0 : -1;
    }
    return -1;
}

static int wls_capacity_detect_control_state(
    int live_fd,
    dev_t expected_device
)
{
    if (wls_capacity_validate_control_state(
            live_fd,
            expected_device,
            WLS_CAPACITY_CONTROL_REQUIRED
        ) == 0) return WLS_CAPACITY_CONTROL_REQUIRED;
    if (wls_capacity_validate_control_state(
            live_fd,
            expected_device,
            WLS_CAPACITY_CONTROL_TRANSITION
        ) == 0) return WLS_CAPACITY_CONTROL_TRANSITION;
    if (wls_capacity_validate_control_state(
            live_fd,
            expected_device,
            WLS_CAPACITY_CONTROL_ABSENT
        ) == 0) return WLS_CAPACITY_CONTROL_ABSENT;
    return -1;
}

static int wls_capacity_mark_release(
    int live_fd,
    dev_t expected_device
)
{
    struct stat before;
    struct stat after;
    struct stat path_after;
    int fd;
    if (wls_capacity_validate_control_state(
            live_fd,
            expected_device,
            WLS_CAPACITY_CONTROL_REQUIRED
        ) != 0) return -1;
    fd = openat(
        live_fd,
        "control.reserve",
        O_RDWR | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0) return -1;
    if (fstat(fd, &before) != 0
        || !S_ISREG(before.st_mode) || before.st_nlink != 1
        || before.st_dev != expected_device
        || (before.st_mode & 0777) != 0600
        || before.st_uid != geteuid()
        || pwrite(
            fd,
            WLS_CAPACITY_RELEASE_MARKER,
            sizeof(WLS_CAPACITY_RELEASE_MARKER) - 1U,
            0
        ) != (ssize_t)(sizeof(WLS_CAPACITY_RELEASE_MARKER) - 1U)
        || fsync(fd) != 0 || fstat(fd, &after) != 0
        || before.st_dev != after.st_dev || before.st_ino != after.st_ino
        || before.st_size != after.st_size
        || before.st_blocks != after.st_blocks
        || fstatat(
            live_fd,
            "control.reserve",
            &path_after,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !S_ISREG(path_after.st_mode) || S_ISLNK(path_after.st_mode)
        || !wls_launcher_same_file_state(&after, &path_after)
        || path_after.st_nlink != 1
        || (path_after.st_mode & 0777) != 0600) {
        close(fd);
        return -1;
    }
    if (close(fd) != 0) return -1;
    return wls_capacity_validate_control_state(
        live_fd,
        expected_device,
        WLS_CAPACITY_CONTROL_TRANSITION
    );
}

static int wls_capacity_sync_release_marker(
    int live_fd,
    dev_t expected_device
)
{
    struct stat opened;
    struct stat path;
    int fd;
    if (wls_capacity_validate_control_state(
            live_fd,
            expected_device,
            WLS_CAPACITY_CONTROL_TRANSITION
        ) != 0) return -1;
    fd = openat(
        live_fd,
        "control.reserve",
        O_RDWR | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0) return -1;
    if (fstat(fd, &opened) != 0
        || !S_ISREG(opened.st_mode) || opened.st_nlink != 1
        || opened.st_dev != expected_device
        || opened.st_uid != geteuid()
        || (opened.st_mode & 0777) != 0600
        || opened.st_size != (off_t)WLS_CAPACITY_CONTROL_BYTES
        || opened.st_blocks <= 0
        || (unsigned long long)opened.st_blocks > ULLONG_MAX / 512ULL
        || (unsigned long long)opened.st_blocks * 512ULL
            < WLS_CAPACITY_CONTROL_BYTES
        || fsync(fd) != 0
        || fstatat(
            live_fd,
            "control.reserve",
            &path,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !S_ISREG(path.st_mode) || S_ISLNK(path.st_mode)
        || !wls_launcher_same_file_state(&opened, &path)
        || path.st_nlink != 1
        || (path.st_mode & 0777) != 0600) {
        close(fd);
        return -1;
    }
    if (close(fd) != 0) return -1;
    return wls_capacity_validate_control_state(
        live_fd,
        expected_device,
        WLS_CAPACITY_CONTROL_TRANSITION
    );
}

static int wls_capacity_validate_live(
    int capacity_fd,
    const char *leaf,
    unsigned long long target_bytes,
    unsigned int target_inodes,
    dev_t expected_device,
    int control_state,
    struct wls_capacity_evidence *evidence
)
{
    crypto_hash_sha256_state hash;
    unsigned char digest[crypto_hash_sha256_BYTES];
    struct stat live_status;
    struct stat byte_status;
    struct dirent *entry;
    DIR *directory = NULL;
    int live_fd = -1;
    int have_bytes = 0;
    int have_tokens = 0;
    int result = -1;
    if (leaf == NULL || evidence == NULL || target_bytes == 0ULL
        || target_inodes == 0U) return -1;
    live_fd = wls_capacity_directory_at(capacity_fd, leaf, &live_status, 0);
    if (live_fd < 0 || live_status.st_dev != expected_device
        || (live_status.st_mode & 0777) != 0700
        || live_status.st_uid != geteuid()) goto cleanup;
    directory = fdopendir(dup(live_fd));
    if (directory == NULL) goto cleanup;
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (strcmp(entry->d_name, "bytes.reserve") == 0) {
            if (have_bytes) goto cleanup;
            have_bytes = 1;
        } else if (strcmp(entry->d_name, "tokens") == 0) {
            if (have_tokens) goto cleanup;
            have_tokens = 1;
        } else if (strcmp(entry->d_name, "control.reserve") != 0
            && strcmp(entry->d_name, "control-tokens") != 0) {
            goto cleanup;
        }
        errno = 0;
    }
    if (errno != 0 || !have_bytes || !have_tokens) goto cleanup;
    if (closedir(directory) != 0) {
        directory = NULL;
        goto cleanup;
    }
    directory = NULL;
    if (wls_capacity_regular_at(
            live_fd,
            "bytes.reserve",
            &byte_status,
            0
        ) != 0
        || byte_status.st_dev != expected_device
        || byte_status.st_size < 0
        || (unsigned long long)byte_status.st_size != target_bytes
        || byte_status.st_blocks <= 0
        || (unsigned long long)byte_status.st_blocks > ULLONG_MAX / 512ULL
        || (unsigned long long)byte_status.st_blocks * 512ULL < target_bytes
        || crypto_hash_sha256_init(&hash) != 0
        || wls_capacity_hash_stat(&hash, "bytes.reserve", &byte_status) != 0
        || wls_capacity_validate_tokens(
            live_fd,
            target_inodes,
            expected_device,
            byte_status.st_ino,
            &hash
        ) != 0) goto cleanup;
    if (wls_capacity_validate_control_state(
            live_fd,
            expected_device,
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
        (unsigned long long)byte_status.st_blocks * 512ULL;
    evidence->inode_count = target_inodes;
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    if (live_fd >= 0) close(live_fd);
    sodium_memzero(digest, sizeof(digest));
    return result;
}

static int wls_capacity_hash_anchor(
    crypto_hash_sha256_state *hash,
    const char *label,
    const struct stat *status
)
{
    char record[PATH_MAX + 128];
    int length;
    if (hash == NULL || label == NULL || status == NULL) return -1;
    length = snprintf(
        record,
        sizeof(record),
        "%s\n%llu\n%llu\n%u\n",
        label,
        (unsigned long long)status->st_dev,
        (unsigned long long)status->st_ino,
        (unsigned int)(status->st_mode & 0170000)
    );
    if (length <= 0 || (size_t)length >= sizeof(record)) return -1;
    return crypto_hash_sha256_update(
        hash,
        (const unsigned char *)record,
        (unsigned long long)length
    ) == 0 ? 0 : -1;
}

static int wls_capacity_canonical_home(
    const char *input,
    int test_mode,
    char resolved[PATH_MAX],
    struct stat *status
)
{
    struct stat path_status;
    if (input == NULL || input[0] != '/' || resolved == NULL || status == NULL
        || realpath(input, resolved) == NULL
        || lstat(resolved, &path_status) != 0
        || !S_ISDIR(path_status.st_mode)
        || S_ISLNK(path_status.st_mode)
        || (path_status.st_mode & 0022) != 0
        || path_status.st_uid != geteuid()) return -1;
    if (!test_mode) {
        if (geteuid() != 0) return -1;
#if defined(__APPLE__)
        if (strcmp(
                resolved,
                "/Library/Application Support/WelineGateway"
            ) != 0) return -1;
#elif defined(__linux__)
        if (strcmp(resolved, "/var/lib/weline-gateway") != 0) return -1;
#else
        return -1;
#endif
    }
    *status = path_status;
    return 0;
}

static int wls_capacity_platform_requires_distinct_reserve(
    dev_t home_device,
    dev_t platform_device
)
{
    return home_device == platform_device ? 0 : 1;
}

static int wls_capacity_platform_reserve_prefix(
    const char *nonce,
    char prefix[64]
)
{
    int length;
    if (!wls_capacity_is_hex(nonce, 32U) || prefix == NULL) return -1;
    length = snprintf(prefix, 64U, "%s.platform.reserve", nonce);
    return length > 0 && length < 64 ? 0 : -1;
}

static int wls_capacity_platform_reserve_leaf(
    const struct wls_capacity_platform_anchor *anchor,
    unsigned int index,
    char leaf[80]
)
{
    int length;
    if (anchor == NULL || leaf == NULL || index >= WLS_CAPACITY_PLATFORM_INODES
        || anchor->reserve_prefix[0] == '\0') return -1;
    length = snprintf(leaf, 80U, "%s.%u", anchor->reserve_prefix, index);
    return length > 0 && length < 80 ? 0 : -1;
}

static int wls_capacity_platform_parent_from_definition(
    const char *definition,
    char parent[PATH_MAX]
)
{
    char *slash;
    if (definition == NULL || parent == NULL || definition[0] != '/'
        || strlen(definition) >= PATH_MAX) return -1;
    memcpy(parent, definition, strlen(definition) + 1U);
    slash = strrchr(parent, '/');
    if (slash == NULL || slash == parent || slash[1] == '\0') return -1;
    *slash = '\0';
    return 0;
}

static int wls_capacity_platform_parent_matches(
    const struct wls_capacity_platform_anchor *anchor,
    const struct stat *status
)
{
    if (anchor == NULL || status == NULL) return 0;
    return S_ISDIR(status->st_mode)
        && !S_ISLNK(status->st_mode)
        && status->st_dev == anchor->parent_identity.st_dev
        && status->st_ino == anchor->parent_identity.st_ino
        && status->st_uid == geteuid()
        && status->st_gid == getegid()
        && (status->st_mode & 0777) == 0700
        && (status->st_mode & 0022) == 0;
}

static int wls_capacity_platform_parent_open(
    const struct wls_capacity_platform_anchor *anchor,
    int *parent_fd
)
{
    struct stat before;
    struct stat opened;
    int fd = -1;
    if (anchor == NULL || parent_fd == NULL) return -1;
    *parent_fd = -1;
    if (lstat(anchor->parent, &before) != 0
        || !wls_capacity_platform_parent_matches(anchor, &before)) {
        return -1;
    }
    fd = open(
        anchor->parent,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0 || fstat(fd, &opened) != 0
        || !wls_capacity_platform_parent_matches(anchor, &opened)
        || !wls_launcher_same_file_state(&before, &opened)
        || wls_guardian_acl_free_fd(fd, 0) != 0) {
        if (fd >= 0) close(fd);
        return -1;
    }
    *parent_fd = fd;
    return 0;
}

/* 0=exact reservation, 1=absent when optional, -1=foreign or unsafe. */
static int wls_capacity_platform_reserve_file_state(
    const struct wls_capacity_platform_anchor *anchor,
    int parent_fd,
    unsigned int index
)
{
    char leaf[80];
    struct stat before;
    struct stat opened;
    struct stat after;
    int fd = -1;
    if (anchor == NULL || parent_fd < 0
        || wls_capacity_platform_reserve_leaf(anchor, index, leaf) != 0) {
        return -1;
    }
    fd = openat(
        parent_fd,
        leaf,
        O_RDONLY | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0) return errno == ENOENT ? 1 : -1;
    if (fstat(fd, &before) != 0
        || !S_ISREG(before.st_mode)
        || before.st_nlink != 1
        || before.st_dev != anchor->device
        || before.st_uid != geteuid()
        || before.st_gid != getegid()
        || (before.st_mode & 0777) != 0600
        || before.st_size != (off_t)WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
        || before.st_blocks <= 0
        || (unsigned long long)before.st_blocks > ULLONG_MAX / 512ULL
        || (unsigned long long)before.st_blocks * 512ULL
            < WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
        || wls_guardian_acl_free_fd(fd, 0) != 0
        || fstat(fd, &opened) != 0
        || fstatat(
            parent_fd,
            leaf,
            &after,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !wls_launcher_same_file_state(&before, &opened)
        || !wls_launcher_same_file_state(&opened, &after)) {
        close(fd);
        return -1;
    }
    return close(fd) == 0 ? 0 : -1;
}

/* 0=all exact, 1=all absent, 2=crash-recoverable exact subset,
 * -1=foreign or unsafe.  The subset state is only accepted by the two
 * journaled cleanup paths below. */
static int wls_capacity_platform_reserve_state(
    const struct wls_capacity_platform_anchor *anchor,
    int parent_fd
)
{
    unsigned int index;
    unsigned int present = 0U;
    if (anchor == NULL || parent_fd < 0) return -1;
    for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; index++) {
        int state = wls_capacity_platform_reserve_file_state(
            anchor,
            parent_fd,
            index
        );
        if (state < 0) return -1;
        if (state == 0) present++;
    }
    if (present == 0U) return 1;
    return present == WLS_CAPACITY_PLATFORM_INODES ? 0 : 2;
}

static int wls_capacity_platform_reserve_verify(
    const struct wls_capacity_platform_anchor *anchor
)
{
    int parent_fd = -1;
    int state;
    int result = -1;
    if (wls_capacity_platform_parent_open(anchor, &parent_fd) != 0) goto cleanup;
    state = wls_capacity_platform_reserve_state(anchor, parent_fd);
    if (state == 0) result = 0;
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    return result;
}

/* No direct platform reserve is valid once the home-side transition says the
 * reserved capacity has been handed to the Guardian. */
static int wls_capacity_platform_reserve_absent(
    const struct wls_capacity_platform_anchor *anchor
)
{
    int parent_fd = -1;
    int result = -1;
    if (wls_capacity_platform_parent_open(anchor, &parent_fd) != 0) goto cleanup;
    if (wls_capacity_platform_reserve_state(anchor, parent_fd) == 1) result = 0;
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    return result;
}

/* Return the exact direct platform reservation state (all exact, absent, or
 * crash-recoverable exact subset).  Unlike the normal HELD verifier this is
 * intentionally usable while an ALLOCATING cleanup has only some of its
 * direct credits on disk.  Any malformed leaf stays indistinguishable from
 * a foreign namespace and fails closed. */
static int wls_capacity_platform_reserve_inspect_state(
    const struct wls_capacity_platform_anchor *anchor
)
{
    int parent_fd = -1;
    int state = -1;
    if (wls_capacity_platform_parent_open(anchor, &parent_fd) != 0) {
        goto cleanup;
    }
    state = wls_capacity_platform_reserve_state(anchor, parent_fd);
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    return state;
}

static int wls_capacity_platform_reserve_create(
    const struct wls_capacity_platform_anchor *anchor
)
{
    int parent_fd = -1;
    int state;
    int result = -1;
    if (anchor == NULL) return -1;
    if (wls_capacity_platform_parent_open(anchor, &parent_fd) != 0) goto cleanup;
    state = wls_capacity_platform_reserve_state(anchor, parent_fd);
    if (state != 1) goto cleanup;
    {
        unsigned int index;
        for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; index++) {
            char leaf[80];
            /* Sync every entry: a failed create can then be resumed by the
             * allocating-state cleanup without guessing what survived a
             * power loss. */
            if (wls_capacity_platform_reserve_leaf(anchor, index, leaf) != 0
                || wls_capacity_allocate_file_at(
                    parent_fd,
                    leaf,
                    WLS_CAPACITY_PLATFORM_BYTES_PER_FILE
                ) != 0
                || fsync(parent_fd) != 0) goto cleanup;
        }
    }
    if (wls_capacity_platform_reserve_state(anchor, parent_fd) != 0) goto cleanup;
    result = 0;
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    return result;
}

/* This can consume an all-exact or partial exact reservation.  Callers may
 * use it only after either the durable home TRANSITION marker exists or while
 * cleaning an ALLOCATING state.  A malformed known leaf is never accepted. */
static int wls_capacity_platform_reserve_release(
    const struct wls_capacity_platform_anchor *anchor,
    int allow_absent
)
{
    int parent_fd = -1;
    int state;
    int result = -1;
    if (anchor == NULL || (allow_absent != 0 && allow_absent != 1)) return -1;
    if (wls_capacity_platform_parent_open(anchor, &parent_fd) != 0) goto cleanup;
    state = wls_capacity_platform_reserve_state(anchor, parent_fd);
    if (state == 1) {
        if (allow_absent) result = 0;
        goto cleanup;
    }
    if (state != 0 && state != 2) goto cleanup;
    {
        unsigned int index;
        for (index = 0U; index < WLS_CAPACITY_PLATFORM_INODES; index++) {
            char leaf[80];
            int file_state;
            if (wls_capacity_platform_reserve_leaf(anchor, index, leaf) != 0) {
                goto cleanup;
            }
            file_state = wls_capacity_platform_reserve_file_state(
                anchor,
                parent_fd,
                index
            );
            if (file_state == 1) continue;
            if (file_state != 0 || unlinkat(parent_fd, leaf, 0) != 0
                || fsync(parent_fd) != 0) goto cleanup;
        }
    }
    if (wls_capacity_platform_reserve_state(anchor, parent_fd) != 1) goto cleanup;
    result = 0;
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    return result;
}

static int wls_capacity_platform_reserve_cleanup_allocating(
    const struct wls_capacity_platform_anchor *anchor
)
{
    return wls_capacity_platform_reserve_release(anchor, 1);
}

static int wls_capacity_platform_device_self_test(void)
{
    return wls_capacity_platform_requires_distinct_reserve((dev_t)7, (dev_t)7) == 0
        && wls_capacity_platform_requires_distinct_reserve((dev_t)7, (dev_t)8) == 1
        && wls_capacity_platform_requires_distinct_reserve((dev_t)8, (dev_t)7) == 1
        && WLS_CAPACITY_PLATFORM_BYTES == 4194304ULL
        && WLS_CAPACITY_PLATFORM_INODES == 2U
        && WLS_CAPACITY_PLATFORM_BYTES
            % WLS_CAPACITY_PLATFORM_INODES == 0ULL
        && WLS_CAPACITY_PLATFORM_BYTES_PER_FILE == 2097152ULL
        ? 0 : 1;
}

static int wls_capacity_anchor_proof(
    const char *home,
    const char *platform_definition,
    dev_t expected_device,
    int test_mode,
    const char *nonce,
    struct wls_capacity_platform_anchor *platform_anchor,
    struct wls_capacity_evidence *evidence
)
{
    static const char *relative_anchors[] = {
        "bin",
        "runtime",
        "runtime/conf",
        "runtime/temp",
        "runtime/shadow",
        "runtime/run",
        "trust",
        "state",
        "snapshots",
        "snapshots-v2",
        "snapshot-candidates-v2",
        "slots",
        "rebootstrap",
        "rebootstrap/candidates",
        "rebootstrap/backups",
        "rebootstrap/capacity"
    };
    crypto_hash_sha256_state anchor_hash;
    unsigned char anchor_digest[crypto_hash_sha256_BYTES];
    unsigned char volume_digest[crypto_hash_sha256_BYTES];
    char executable[PATH_MAX];
    char device_record[64];
    unsigned long long start_id = 0ULL;
    mode_t definition_mode;
    size_t index;
    int definition_fd = -1;
    int parent_fd = -1;
    int length;
    int result = -1;
    struct stat status;
    struct stat definition_before;
    struct stat definition_opened;
    struct stat definition_after;
    struct stat parent_before;
    struct stat parent_opened;
    if (home == NULL || platform_definition == NULL || nonce == NULL
        || platform_anchor == NULL || evidence == NULL
        || (test_mode != 0 && test_mode != 1)
        || crypto_hash_sha256_init(&anchor_hash) != 0) goto cleanup;
    memset(platform_anchor, 0, sizeof(*platform_anchor));
    for (index = 0U;
         index < sizeof(relative_anchors) / sizeof(relative_anchors[0]);
         index++) {
        char path[PATH_MAX];
        if (wls_join(
                path,
                sizeof(path),
                home,
                relative_anchors[index]
            ) != 0
            || lstat(path, &status) != 0
            || !S_ISDIR(status.st_mode)
            || S_ISLNK(status.st_mode)
            || status.st_dev != expected_device
            || status.st_uid != geteuid()
            || (status.st_mode & 0022) != 0
            || wls_capacity_hash_anchor(
                &anchor_hash,
                relative_anchors[index],
                &status
            ) != 0) goto cleanup;
    }
    definition_mode = test_mode ? 0600 : 0644;
    if (platform_definition[0] != '/'
        || strlen(platform_definition) >= sizeof(platform_anchor->definition)
        || wls_capacity_platform_parent_from_definition(
            platform_definition,
            platform_anchor->parent
        ) != 0
        || wls_capacity_platform_reserve_prefix(
            nonce,
            platform_anchor->reserve_prefix
        ) != 0
        || lstat(platform_definition, &definition_before) != 0
        || !S_ISREG(definition_before.st_mode)
        || S_ISLNK(definition_before.st_mode)
        || definition_before.st_nlink != 1
        || definition_before.st_uid != geteuid()
        || definition_before.st_gid != getegid()
        || (definition_before.st_mode & 0777) != definition_mode
        || lstat(platform_anchor->parent, &parent_before) != 0
        || !S_ISDIR(parent_before.st_mode)
        || S_ISLNK(parent_before.st_mode)
        || parent_before.st_dev != definition_before.st_dev
        || parent_before.st_uid != geteuid()
        || parent_before.st_gid != getegid()
        || (parent_before.st_mode & 0777) != 0700
        || (parent_before.st_mode & 0022) != 0) goto cleanup;
    definition_fd = open(
        platform_definition,
        O_RDONLY | O_CLOEXEC | O_NOFOLLOW
    );
    if (definition_fd < 0 || fstat(definition_fd, &definition_opened) != 0
        || !wls_launcher_same_file_state(
            &definition_before,
            &definition_opened
        )
        || wls_guardian_acl_free_fd(definition_fd, 0) != 0) goto cleanup;
    parent_fd = open(
        platform_anchor->parent,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (parent_fd < 0 || fstat(parent_fd, &parent_opened) != 0
        || !wls_launcher_same_file_state(&parent_before, &parent_opened)
        || wls_guardian_acl_free_fd(parent_fd, 0) != 0
        || lstat(platform_definition, &definition_after) != 0
        || !wls_launcher_same_file_state(
            &definition_opened,
            &definition_after
        )) goto cleanup;
    memcpy(
        platform_anchor->definition,
        platform_definition,
        strlen(platform_definition) + 1U
    );
    platform_anchor->definition_identity = definition_after;
    platform_anchor->parent_identity = parent_opened;
    platform_anchor->device = definition_after.st_dev;
    platform_anchor->separate_filesystem =
        wls_capacity_platform_requires_distinct_reserve(
            expected_device,
            definition_after.st_dev
        );
    if (wls_capacity_hash_anchor(
            &anchor_hash,
            "platform-definition",
            &definition_after
        ) != 0
        || wls_capacity_hash_anchor(
            &anchor_hash,
            "platform-definition-parent",
            &parent_opened
        ) != 0
        || wls_recovery_process_identity(
            getpid(),
            executable,
            sizeof(executable),
            &start_id
        ) != 0
        || start_id == 0ULL
        || lstat(executable, &status) != 0
        || !S_ISREG(status.st_mode)
        || S_ISLNK(status.st_mode)
        || status.st_nlink != 1
        || status.st_dev != expected_device
        || wls_capacity_hash_anchor(
            &anchor_hash,
            "candidate-launcher",
            &status
        ) != 0
        || crypto_hash_sha256_final(&anchor_hash, anchor_digest) != 0) {
        goto cleanup;
    }
    sodium_bin2hex(
        evidence->anchor_set_sha256,
        sizeof(evidence->anchor_set_sha256),
        anchor_digest,
        sizeof(anchor_digest)
    );
    length = snprintf(
        device_record,
        sizeof(device_record),
        "device=%llu\n",
        (unsigned long long)expected_device
    );
    if (length <= 0 || (size_t)length >= sizeof(device_record)
        || crypto_hash_sha256(
            volume_digest,
            (const unsigned char *)device_record,
            (unsigned long long)length
        ) != 0) goto cleanup;
    sodium_bin2hex(
        evidence->volume_id,
        sizeof(evidence->volume_id),
        volume_digest,
        sizeof(volume_digest)
    );
    result = 0;
cleanup:
    if (parent_fd >= 0) close(parent_fd);
    if (definition_fd >= 0) close(definition_fd);
    sodium_memzero(anchor_digest, sizeof(anchor_digest));
    sodium_memzero(volume_digest, sizeof(volume_digest));
    sodium_memzero(executable, sizeof(executable));
    if (result != 0 && platform_anchor != NULL) {
        sodium_memzero(platform_anchor, sizeof(*platform_anchor));
    }
    return result;
}

static int wls_capacity_leaf(
    const char *nonce,
    const char *state,
    char leaf[48]
)
{
    int length;
    if (!wls_capacity_is_hex(nonce, 32U) || state == NULL) return -1;
    length = snprintf(leaf, 48U, "%s.%s", nonce, state);
    return length > 0 && length < 48 ? 0 : -1;
}

/* 0=absent, 1=present directory, -1=unsafe/indeterminate. */
static int wls_capacity_live_present(int capacity_fd, const char *leaf)
{
    struct stat status;
    if (fstatat(
            capacity_fd,
            leaf,
            &status,
            AT_SYMLINK_NOFOLLOW
        ) != 0) return errno == ENOENT ? 0 : -1;
    return S_ISDIR(status.st_mode) && !S_ISLNK(status.st_mode) ? 1 : -1;
}

static void wls_capacity_print_evidence(
    const char *state,
    const struct wls_capacity_evidence *evidence
)
{
    printf(
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

/* Exact and intentionally small protocol surface for PHP's unbound
 * ALLOCATING cancellation reconciliation.  Do not add optional fields: the
 * caller rejects any schema drift rather than guessing about native state. */
static void wls_capacity_print_inspect(const char *state)
{
    printf(
        "{\"schema\":\"%s\",\"state\":\"%s\"}\n",
        WLS_CAPACITY_INSPECT_SCHEMA,
        state
    );
}

static int wls_capacity_validate_partial_tokens(
    int live_fd,
    const char *leaf,
    unsigned int maximum,
    dev_t expected_device,
    int optional
)
{
    unsigned char *seen = NULL;
    struct dirent *entry;
    DIR *directory = NULL;
    int token_fd = wls_capacity_directory_at(
        live_fd,
        leaf,
        NULL,
        optional
    );
    int result = -1;
    if (token_fd == 1) return 0;
    if (token_fd < 0 || maximum == 0U) return -1;
    seen = calloc((size_t)maximum, sizeof(*seen));
    if (seen == NULL) goto cleanup;
    directory = fdopendir(dup(token_fd));
    if (directory == NULL) goto cleanup;
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        struct stat status;
        unsigned int index;
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (wls_capacity_parse_token_leaf(
                entry->d_name,
                maximum,
                &index
            ) != 0
            || seen[index] != 0U
            || wls_capacity_regular_at(
                token_fd,
                entry->d_name,
                &status,
                0
            ) != 0
            || status.st_dev != expected_device
            || status.st_size != 0) goto cleanup;
        seen[index] = 1U;
        errno = 0;
    }
    if (errno != 0) goto cleanup;
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    if (token_fd >= 0) close(token_fd);
    free(seen);
    return result;
}

static int wls_capacity_validate_removable(
    int capacity_fd,
    const char *leaf,
    unsigned int target_inodes,
    dev_t expected_device
)
{
    struct stat live_status;
    struct dirent *entry;
    DIR *directory = NULL;
    int live_fd = wls_capacity_directory_at(
        capacity_fd,
        leaf,
        &live_status,
        0
    );
    int result = -1;
    if (live_fd < 0 || live_status.st_dev != expected_device
        || live_status.st_uid != geteuid()
        || (live_status.st_mode & 0777) != 0700) goto cleanup;
    directory = fdopendir(dup(live_fd));
    if (directory == NULL) goto cleanup;
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        struct stat status;
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (strcmp(entry->d_name, "bytes.reserve") == 0
            || strcmp(entry->d_name, "control.reserve") == 0) {
            if (wls_capacity_regular_at(
                    live_fd,
                    entry->d_name,
                    &status,
                    0
                ) != 0 || status.st_dev != expected_device) goto cleanup;
        } else if (strcmp(entry->d_name, "tokens") == 0) {
            if (wls_capacity_validate_partial_tokens(
                    live_fd,
                    "tokens",
                    target_inodes,
                    expected_device,
                    0
                ) != 0) goto cleanup;
        } else if (strcmp(entry->d_name, "control-tokens") == 0) {
            if (wls_capacity_validate_partial_tokens(
                    live_fd,
                    "control-tokens",
                    WLS_CAPACITY_CONTROL_INODES,
                    expected_device,
                    0
                ) != 0) goto cleanup;
        } else {
            goto cleanup;
        }
        errno = 0;
    }
    if (errno != 0) goto cleanup;
    result = 0;
cleanup:
    if (directory != NULL) closedir(directory);
    if (live_fd >= 0) close(live_fd);
    return result;
}

static int wls_capacity_remove_token_directory(
    int live_fd,
    const char *leaf,
    unsigned int maximum,
    dev_t expected_device,
    int optional
)
{
    struct dirent *entry;
    DIR *directory = NULL;
    int token_fd;
    if (wls_capacity_validate_partial_tokens(
            live_fd,
            leaf,
            maximum,
            expected_device,
            optional
        ) != 0) return -1;
    token_fd = wls_capacity_directory_at(live_fd, leaf, NULL, optional);
    if (token_fd == 1) return 0;
    if (token_fd < 0) return -1;
    directory = fdopendir(dup(token_fd));
    if (directory == NULL) {
        close(token_fd);
        return -1;
    }
    errno = 0;
    while ((entry = readdir(directory)) != NULL) {
        struct stat status;
        unsigned int index;
        if (strcmp(entry->d_name, ".") == 0
            || strcmp(entry->d_name, "..") == 0) continue;
        if (wls_capacity_parse_token_leaf(
                entry->d_name,
                maximum,
                &index
            ) != 0
            || wls_capacity_regular_at(
                token_fd,
                entry->d_name,
                &status,
                0
            ) != 0
            || status.st_dev != expected_device
            || unlinkat(token_fd, entry->d_name, 0) != 0) {
            closedir(directory);
            close(token_fd);
            return -1;
        }
        errno = 0;
    }
    if (errno != 0 || fsync(token_fd) != 0
        || closedir(directory) != 0 || close(token_fd) != 0
        || unlinkat(live_fd, leaf, AT_REMOVEDIR) != 0) return -1;
    return 0;
}

static int wls_capacity_unlink_optional_regular(
    int directory_fd,
    const char *leaf,
    dev_t expected_device
)
{
    struct stat status;
    int present = wls_capacity_regular_at(
        directory_fd,
        leaf,
        &status,
        1
    );
    if (present == 1) return 0;
    if (present != 0 || status.st_dev != expected_device) return -1;
    return unlinkat(directory_fd, leaf, 0) == 0 ? 0 : -1;
}

static int wls_capacity_remove_live(
    int capacity_fd,
    const char *leaf,
    unsigned int target_inodes,
    dev_t expected_device
)
{
    int live_fd;
    if (wls_capacity_validate_removable(
            capacity_fd,
            leaf,
            target_inodes,
            expected_device
        ) != 0) return -1;
    live_fd = wls_capacity_directory_at(capacity_fd, leaf, NULL, 0);
    if (live_fd < 0) return -1;
    if (wls_capacity_remove_token_directory(
            live_fd,
            "control-tokens",
            WLS_CAPACITY_CONTROL_INODES,
            expected_device,
            1
        ) != 0
        || wls_capacity_remove_token_directory(
            live_fd,
            "tokens",
            target_inodes,
            expected_device,
            1
        ) != 0
        || wls_capacity_unlink_optional_regular(
            live_fd,
            "control.reserve",
            expected_device
        ) != 0
        || wls_capacity_unlink_optional_regular(
            live_fd,
            "bytes.reserve",
            expected_device
        ) != 0
        || fsync(live_fd) != 0
        || close(live_fd) != 0
        || unlinkat(capacity_fd, leaf, AT_REMOVEDIR) != 0
        || fsync(capacity_fd) != 0) return -1;
    return 0;
}

static int wls_capacity_prepare_release(
    int capacity_fd,
    const char *leaf,
    unsigned long long target_bytes,
    unsigned int target_inodes,
    dev_t expected_device,
    struct wls_capacity_evidence *evidence
)
{
    int live_fd = wls_capacity_directory_at(
        capacity_fd,
        leaf,
        NULL,
        0
    );
    int control_state;
    if (live_fd < 0) return -1;
    control_state = wls_capacity_detect_control_state(
        live_fd,
        expected_device
    );
    if (close(live_fd) != 0) return -1;
    if (!((control_state == WLS_CAPACITY_CONTROL_REQUIRED)
            || (control_state == WLS_CAPACITY_CONTROL_TRANSITION))
        || wls_capacity_validate_live(
            capacity_fd,
            leaf,
            target_bytes,
            target_inodes,
            expected_device,
            control_state,
            evidence
        ) != 0) return -1;
    live_fd = wls_capacity_directory_at(capacity_fd, leaf, NULL, 0);
    if (live_fd < 0) return -1;
    if ((control_state == WLS_CAPACITY_CONTROL_REQUIRED
            && wls_capacity_mark_release(
                live_fd,
                expected_device
            ) != 0)
        || (control_state == WLS_CAPACITY_CONTROL_TRANSITION
            && wls_capacity_sync_release_marker(
                live_fd,
                expected_device
            ) != 0)
        || wls_capacity_remove_token_directory(
            live_fd,
            "control-tokens",
            WLS_CAPACITY_CONTROL_INODES,
            expected_device,
            1
        ) != 0
        || fsync(live_fd) != 0
        || close(live_fd) != 0
        || wls_capacity_validate_live(
            capacity_fd,
            leaf,
            target_bytes,
            target_inodes,
            expected_device,
            WLS_CAPACITY_CONTROL_TRANSITION,
            evidence
        ) != 0) return -1;
    return 0;
}

static int wls_capacity_finish_release_control(
    int capacity_fd,
    const char *leaf,
    unsigned long long target_bytes,
    unsigned int target_inodes,
    dev_t expected_device,
    struct wls_capacity_evidence *evidence
)
{
    int live_fd = wls_capacity_directory_at(
        capacity_fd,
        leaf,
        NULL,
        0
    );
    int control_state;
    if (live_fd < 0) return -1;
    control_state = wls_capacity_detect_control_state(
        live_fd,
        expected_device
    );
    if (close(live_fd) != 0) return -1;
    if (!((control_state == WLS_CAPACITY_CONTROL_TRANSITION)
            || (control_state == WLS_CAPACITY_CONTROL_ABSENT))
        || wls_capacity_validate_live(
            capacity_fd,
            leaf,
            target_bytes,
            target_inodes,
            expected_device,
            control_state,
            evidence
        ) != 0) return -1;
    if (control_state == WLS_CAPACITY_CONTROL_TRANSITION) {
        live_fd = wls_capacity_directory_at(capacity_fd, leaf, NULL, 0);
        if (live_fd < 0
            || wls_capacity_unlink_optional_regular(
                live_fd,
                "control.reserve",
                expected_device
            ) != 0
            || fsync(live_fd) != 0
            || close(live_fd) != 0) return -1;
    }
    return wls_capacity_validate_live(
        capacity_fd,
        leaf,
        target_bytes,
        target_inodes,
        expected_device,
        WLS_CAPACITY_CONTROL_ABSENT,
        evidence
    );
}

static int wls_capacity_contract_self_test(void)
{
    unsigned long long parsed = 0ULL;
    return sizeof(off_t) >= 8U
        && WLS_CAPACITY_TEST_BYTES == 8388608ULL
        && WLS_CAPACITY_TEST_INODES == 128U
        && WLS_CAPACITY_PRODUCTION_BYTES == 10737418240ULL
        && WLS_CAPACITY_PRODUCTION_INODES == 65536U
        && WLS_CAPACITY_PLATFORM_BYTES == 4194304ULL
        && WLS_CAPACITY_PLATFORM_INODES == 2U
        && WLS_CAPACITY_PLATFORM_BYTES_PER_FILE == 2097152ULL
        && WLS_CAPACITY_TOKEN_FSYNC_BATCH == 1024U
        && wls_capacity_token_directory_syncs(
            WLS_CAPACITY_PRODUCTION_INODES
        )
            == 64U
        && wls_capacity_token_directory_syncs(WLS_CAPACITY_TEST_INODES)
            == 1U
        && wls_capacity_is_hex(
            "0123456789abcdef0123456789abcdef",
            32U
        )
        && wls_capacity_unsigned("8388608", ULLONG_MAX, &parsed) == 0
        && parsed == WLS_CAPACITY_TEST_BYTES
        && wls_capacity_platform_device_self_test() == 0
        ? 0 : 1;
}

static int wls_capacity_manifest_binding(
    int capacity_fd,
    const char *nonce,
    const char *expected_digest,
    dev_t expected_device
)
{
    crypto_hash_sha256_state hash;
    unsigned char digest[crypto_hash_sha256_BYTES];
    char actual[65];
    char leaf[48];
    unsigned char buffer[4096];
    struct stat before;
    struct stat after;
    struct stat path_after;
    ssize_t amount;
    int length;
    int fd = -1;
    int result = -1;
    if (!wls_capacity_is_hex(nonce, 32U)
        || !wls_capacity_is_hex(expected_digest, 64U)) return -1;
    length = snprintf(leaf, sizeof(leaf), "%s.held.json", nonce);
    if (length <= 0 || (size_t)length >= sizeof(leaf)) return -1;
    fd = openat(
        capacity_fd,
        leaf,
        O_RDONLY | O_CLOEXEC | O_NOFOLLOW
    );
    if (fd < 0 || fstat(fd, &before) != 0
        || !S_ISREG(before.st_mode) || before.st_nlink != 1
        || before.st_dev != expected_device
        || before.st_uid != geteuid()
        || (before.st_mode & 0777) != 0600
        || before.st_size <= 0 || before.st_size > 16384
        || crypto_hash_sha256_init(&hash) != 0) goto cleanup;
    while ((amount = read(fd, buffer, sizeof(buffer))) > 0) {
        if (crypto_hash_sha256_update(
                &hash,
                buffer,
                (unsigned long long)amount
            ) != 0) goto cleanup;
    }
    if (amount < 0 || crypto_hash_sha256_final(&hash, digest) != 0
        || fstat(fd, &after) != 0
        || fstatat(
            capacity_fd,
            leaf,
            &path_after,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !wls_launcher_same_file_state(&before, &after)
        || !wls_launcher_same_file_state(&after, &path_after)
        || !S_ISREG(path_after.st_mode) || S_ISLNK(path_after.st_mode)
        || path_after.st_nlink != 1
        || (path_after.st_mode & 0777) != 0600) goto cleanup;
    sodium_bin2hex(actual, sizeof(actual), digest, sizeof(digest));
    result = sodium_memcmp(actual, expected_digest, 64U) == 0 ? 0 : -1;
cleanup:
    if (fd >= 0) close(fd);
    sodium_memzero(buffer, sizeof(buffer));
    sodium_memzero(digest, sizeof(digest));
    sodium_memzero(actual, sizeof(actual));
    return result;
}

static int wls_capacity_command(int argc, char **argv)
{
    const char *operation = wls_argument(argc, argv, "--capacity-reserve");
    const char *home_argument = wls_argument(argc, argv, "--home");
    const char *nonce = wls_argument(argc, argv, "--nonce");
    const char *bytes_text = wls_argument(argc, argv, "--bytes");
    const char *inodes_text = wls_argument(argc, argv, "--inodes");
    const char *platform_definition = wls_argument(
        argc,
        argv,
        "--platform-definition"
    );
    const char *test_text = wls_argument(argc, argv, "--test-mode");
    const char *reason = wls_argument(argc, argv, "--release-reason");
    const char *expected_manifest = wls_argument(
        argc,
        argv,
        "--expected-manifest-sha256"
    );
    struct wls_capacity_evidence evidence;
    struct wls_capacity_platform_anchor platform_anchor;
    struct stat home_status;
    struct stat capacity_status;
    struct stat capacity_opened;
    char home[PATH_MAX];
    char capacity_path[PATH_MAX];
    char allocating[48];
    char held[48];
    char releasing[48];
    unsigned long long target_bytes = 0ULL;
    unsigned long long target_inodes_wide = 0ULL;
    unsigned int target_inodes;
    int test_mode;
    int capacity_fd = -1;
    int allocating_present;
    int held_present;
    int releasing_present;
    int live_count;
    int inspect_operation;
    int result = 1;
    memset(&evidence, 0, sizeof(evidence));
    memset(&platform_anchor, 0, sizeof(platform_anchor));
    if (operation == NULL || home_argument == NULL
        || !wls_capacity_is_hex(nonce, 32U)
        || bytes_text == NULL || inodes_text == NULL
        || platform_definition == NULL || platform_definition[0] != '/'
        || test_text == NULL
        || !((strcmp(test_text, "0") == 0)
            || (strcmp(test_text, "1") == 0))
        || wls_capacity_unsigned(
            bytes_text,
            WLS_CAPACITY_PRODUCTION_BYTES,
            &target_bytes
        ) != 0
        || wls_capacity_unsigned(
            inodes_text,
            WLS_CAPACITY_PRODUCTION_INODES,
            &target_inodes_wide
        ) != 0
        || target_inodes_wide == 0ULL
        || target_inodes_wide > UINT_MAX) {
        fprintf(stderr, "capacity reserve arguments are invalid\n");
        return 64;
    }
    test_mode = strcmp(test_text, "1") == 0;
    inspect_operation = strcmp(operation, "inspect") == 0;
    target_inodes = (unsigned int)target_inodes_wide;
    if ((test_mode
            && (target_bytes != WLS_CAPACITY_TEST_BYTES
                || target_inodes != WLS_CAPACITY_TEST_INODES))
        || (!test_mode
            && (target_bytes != WLS_CAPACITY_PRODUCTION_BYTES
                || target_inodes != WLS_CAPACITY_PRODUCTION_INODES))
        || ((strcmp(operation, "create") == 0
                || strcmp(operation, "verify") == 0
                || inspect_operation)
            && reason != NULL)
        || ((strcmp(operation, "begin-release") == 0
                || strcmp(operation, "complete-release") == 0)
            && (reason == NULL
                || !(strcmp(reason, "forward") == 0
                    || strcmp(reason, "rollback") == 0
                    || strcmp(reason, "cancel") == 0)))
        || (strcmp(operation, "create") != 0
            && strcmp(operation, "verify") != 0
            && !inspect_operation
            && strcmp(operation, "begin-release") != 0
            && strcmp(operation, "complete-release") != 0)
        || ((strcmp(operation, "verify") == 0
                || strcmp(operation, "begin-release") == 0)
            && !wls_capacity_is_hex(expected_manifest, 64U))
        || (inspect_operation && expected_manifest != NULL)
        || (expected_manifest != NULL
            && !wls_capacity_is_hex(expected_manifest, 64U))) {
        fprintf(stderr, "capacity reserve contract is invalid\n");
        return 64;
    }
    if (wls_capacity_canonical_home(
            home_argument,
            test_mode,
            home,
            &home_status
        ) != 0
        || wls_join(
            capacity_path,
            sizeof(capacity_path),
            home,
            "rebootstrap/capacity"
        ) != 0
        || lstat(capacity_path, &capacity_status) != 0
        || !S_ISDIR(capacity_status.st_mode)
        || S_ISLNK(capacity_status.st_mode)
        || capacity_status.st_dev != home_status.st_dev
        || capacity_status.st_uid != geteuid()
        || (capacity_status.st_mode & 0022) != 0
        || wls_capacity_leaf(nonce, "allocating", allocating) != 0
        || wls_capacity_leaf(nonce, "held", held) != 0
        || wls_capacity_leaf(nonce, "releasing", releasing) != 0) {
        fprintf(stderr, "capacity reserve root is unsafe\n");
        return 77;
    }
    capacity_fd = open(
        capacity_path,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (capacity_fd < 0
        || fstat(capacity_fd, &capacity_opened) != 0
        || capacity_opened.st_dev != capacity_status.st_dev
        || capacity_opened.st_ino != capacity_status.st_ino
        || wls_capacity_anchor_proof(
            home,
            platform_definition,
            home_status.st_dev,
            test_mode,
            nonce,
            &platform_anchor,
            &evidence
        ) != 0
        || (expected_manifest != NULL
            && wls_capacity_manifest_binding(
                capacity_fd,
                nonce,
                expected_manifest,
                home_status.st_dev
            ) != 0)) {
        fprintf(
            stderr,
            "capacity reserve anchors are unsafe or platform reserve is unavailable\n"
        );
        if (inspect_operation) result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
        goto cleanup;
    }
    allocating_present = wls_capacity_live_present(capacity_fd, allocating);
    held_present = wls_capacity_live_present(capacity_fd, held);
    releasing_present = wls_capacity_live_present(capacity_fd, releasing);
    if (allocating_present < 0 || held_present < 0 || releasing_present < 0) {
        fprintf(stderr, "capacity reserve live namespace is unsafe\n");
        if (inspect_operation) result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
        goto cleanup;
    }
    live_count = allocating_present + held_present + releasing_present;
    if (live_count > 1) {
        fprintf(stderr, "capacity reserve has conflicting live states\n");
        if (inspect_operation) result = WLS_CAPACITY_INSPECT_CONFLICT_EXIT;
        goto cleanup;
    }

    if (inspect_operation) {
        int platform_state;
        if (live_count == 0) {
            if (wls_capacity_platform_reserve_absent(&platform_anchor) != 0) {
                result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
                goto cleanup;
            }
            wls_capacity_print_inspect("NONE");
            result = 0;
            goto cleanup;
        }
        platform_state = wls_capacity_platform_reserve_inspect_state(
            &platform_anchor
        );
        if (platform_state < 0) {
            result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
            goto cleanup;
        }
        if (allocating_present) {
            /* An ALLOCATING directory is cleanup-only authority.  It may
             * contain a partial exact home allocation and zero, one, or two
             * exact platform credits, but no foreign entry may be removed. */
            if (wls_capacity_validate_removable(
                    capacity_fd,
                    allocating,
                    target_inodes,
                    home_status.st_dev
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
                    capacity_fd,
                    held,
                    target_bytes,
                    target_inodes,
                    home_status.st_dev,
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
            int releasing_fd = wls_capacity_directory_at(
                capacity_fd,
                releasing,
                NULL,
                0
            );
            int control_state;
            if (releasing_fd < 0) {
                result = WLS_CAPACITY_INSPECT_UNSAFE_EXIT;
                goto cleanup;
            }
            control_state = wls_capacity_detect_control_state(
                releasing_fd,
                home_status.st_dev
            );
            if (close(releasing_fd) != 0
                || (control_state != WLS_CAPACITY_CONTROL_TRANSITION
                    && control_state != WLS_CAPACITY_CONTROL_ABSENT)
                || (control_state == WLS_CAPACITY_CONTROL_ABSENT
                    && platform_state != 1)
                || wls_capacity_validate_live(
                    capacity_fd,
                    releasing,
                    target_bytes,
                    target_inodes,
                    home_status.st_dev,
                    control_state,
                    &evidence
                ) != 0) {
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

    if (strcmp(operation, "create") == 0) {
        int live_fd;
        if (releasing_present) {
            fprintf(stderr, "capacity reserve is already releasing\n");
            goto cleanup;
        }
        if (held_present) {
            if (wls_capacity_validate_live(
                    capacity_fd,
                    held,
                    target_bytes,
                    target_inodes,
                    home_status.st_dev,
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
                    &platform_anchor
                ) != 0
                || wls_capacity_remove_live(
                    capacity_fd,
                    allocating,
                    target_inodes,
                    home_status.st_dev
                ) != 0)) goto cleanup;
        /* This name is the cleanup authority for the direct platform credits.
         * Persist it before publishing a credit in another directory or on
         * another filesystem, otherwise power loss can leave an unclassifiable
         * platform-only allocation. */
        if (mkdirat(capacity_fd, allocating, 0700) != 0
            || fsync(capacity_fd) != 0) goto cleanup;
        live_fd = wls_capacity_directory_at(
            capacity_fd,
            allocating,
            NULL,
            0
        );
        if (live_fd < 0) goto cleanup;
        if (wls_capacity_platform_reserve_create(&platform_anchor) != 0
            || wls_capacity_allocate_file_at(
                live_fd,
                "bytes.reserve",
                target_bytes
            ) != 0
            || wls_capacity_create_tokens(
                live_fd,
                "tokens",
                target_inodes
            ) != 0
            || wls_capacity_allocate_file_at(
                live_fd,
                "control.reserve",
                WLS_CAPACITY_CONTROL_BYTES
            ) != 0
            || wls_capacity_create_tokens(
                live_fd,
                "control-tokens",
                WLS_CAPACITY_CONTROL_INODES
            ) != 0
            || fsync(live_fd) != 0
            || close(live_fd) != 0
            || wls_capacity_validate_live(
                capacity_fd,
                allocating,
                target_bytes,
                target_inodes,
                home_status.st_dev,
                WLS_CAPACITY_CONTROL_REQUIRED,
                &evidence
            ) != 0
            || renameat(capacity_fd, allocating, capacity_fd, held) != 0
            || fsync(capacity_fd) != 0
            || wls_capacity_validate_live(
                capacity_fd,
                held,
                target_bytes,
                target_inodes,
                home_status.st_dev,
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

    if (strcmp(operation, "verify") == 0) {
        if (!held_present || allocating_present || releasing_present
            || wls_capacity_validate_live(
                capacity_fd,
                held,
                target_bytes,
                target_inodes,
                home_status.st_dev,
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

    if (strcmp(operation, "begin-release") == 0) {
        int releasing_fd = -1;
        int control_state;
        if ((!held_present && !releasing_present) || allocating_present) {
            goto cleanup;
        }
        /* Prove the complete direct reservation before writing the durable
         * home-side handoff marker.  A later retry may consume a partial
         * reservation only because that marker records this proof. */
        if (held_present
            && (wls_capacity_platform_reserve_verify(&platform_anchor) != 0
                || wls_capacity_prepare_release(
                    capacity_fd,
                    held,
                    target_bytes,
                    target_inodes,
                    home_status.st_dev,
                    &evidence
                ) != 0
                || renameat(capacity_fd, held, capacity_fd, releasing) != 0
                || fsync(capacity_fd) != 0)) goto cleanup;
        releasing_fd = wls_capacity_directory_at(
            capacity_fd,
            releasing,
            NULL,
            0
        );
        if (releasing_fd < 0) goto cleanup;
        control_state = wls_capacity_detect_control_state(
            releasing_fd,
            home_status.st_dev
        );
        if (close(releasing_fd) != 0) goto cleanup;
        if (control_state != WLS_CAPACITY_CONTROL_TRANSITION
            && control_state != WLS_CAPACITY_CONTROL_ABSENT) goto cleanup;
        if ((control_state == WLS_CAPACITY_CONTROL_TRANSITION
                && wls_capacity_platform_reserve_release(
                    &platform_anchor,
                    1
                ) != 0)
            || (control_state == WLS_CAPACITY_CONTROL_ABSENT
                && wls_capacity_platform_reserve_absent(
                    &platform_anchor
                ) != 0)) goto cleanup;
        /* The platform reservation is released while TRANSITION is durable,
         * so same-directory temp and backup writes can actually consume it.
         * Finishing control is then idempotent after a crash. */
        if (wls_capacity_finish_release_control(
                capacity_fd,
                releasing,
                target_bytes,
                target_inodes,
                home_status.st_dev,
                &evidence
            ) != 0
            || wls_capacity_platform_reserve_absent(
                &platform_anchor
            ) != 0) goto cleanup;
        wls_capacity_print_evidence("RELEASING", &evidence);
        result = 0;
        goto cleanup;
    }

    if (held_present) {
        fprintf(
            stderr,
            "capacity reserve release transition was not started\n"
        );
        goto cleanup;
    }
    if (live_count == 0 && expected_manifest == NULL
        && strcmp(reason, "cancel") != 0) goto cleanup;
    if (live_count == 0
        && wls_capacity_platform_reserve_absent(&platform_anchor) != 0) {
        goto cleanup;
    }
    if (allocating_present) {
        if (strcmp(reason, "cancel") != 0 || expected_manifest != NULL
            || wls_capacity_platform_reserve_cleanup_allocating(
                &platform_anchor
            ) != 0
            || wls_capacity_remove_live(
                capacity_fd,
                allocating,
                target_inodes,
                home_status.st_dev
            ) != 0) goto cleanup;
    } else if (releasing_present) {
        int releasing_fd = -1;
        if (expected_manifest == NULL) goto cleanup;
        releasing_fd = wls_capacity_directory_at(
            capacity_fd,
            releasing,
            NULL,
            0
        );
        if (releasing_fd < 0
            || wls_capacity_validate_control_state(
                releasing_fd,
                home_status.st_dev,
                WLS_CAPACITY_CONTROL_ABSENT
            ) != 0) {
            if (releasing_fd >= 0) close(releasing_fd);
            goto cleanup;
        }
        if (close(releasing_fd) != 0) goto cleanup;
        /* begin-release has already made the direct reservation absent before
         * it removes control.reserve.  Completion never consumes capacity. */
        if (wls_capacity_platform_reserve_absent(&platform_anchor) != 0
            || wls_capacity_remove_live(
                capacity_fd,
                releasing,
                target_inodes,
                home_status.st_dev
            ) != 0) goto cleanup;
    }
    printf("{\"state\":\"RELEASED\"}\n");
    result = 0;
cleanup:
    if (capacity_fd >= 0) close(capacity_fd);
    sodium_memzero(&evidence, sizeof(evidence));
    sodium_memzero(&platform_anchor, sizeof(platform_anchor));
    sodium_memzero(home, sizeof(home));
    return result;
}

int main(int argc, char **argv)
{
    const char *home;
    const char *run_directory;
    char platform[16];
    char service_id[65];
    char launcher_generation[65];
    unsigned char key[crypto_sign_PUBLICKEYBYTES];
    if (sodium_init() < 0 || wls_public_key(key) != 0) {
        return 1;
    }
    sodium_memzero(key, sizeof(key));
    if (argc == 2 && strcmp(argv[1], "--self-test") == 0) {
        return wls_wait_child_exit_deadline_self_test() == 0
            && wls_rebootstrap_reserved_recovery_name_self_test() == 0
            && wls_capacity_contract_self_test() == 0
            && wls_guardian_observation_identity_self_test() == 0
            && wls_guardian_observation_sample_self_test() == 0
            && wls_classify_broker_exit(0, 1) == 1
            && wls_classify_broker_exit(WLS_CONTROL_TREE_RELOAD, 1) == 1
            && wls_classify_broker_exit(WLS_SERVICE_TREE_RESTART, 1)
                == WLS_SERVICE_TREE_RESTART
            && wls_classify_broker_exit(7, 1) == 7
            && wls_classify_broker_exit(7, 0) == 0
            ? 0
            : 1;
    }
    if (argc == 2
        && strcmp(argv[1], "--rollback-target-proof-self-test") == 0) {
        return wls_launcher_rollback_target_proof_self_test();
    }
    if (argc == 2
        && strcmp(argv[1], "--recovery-ledger-self-test") == 0) {
        return wls_recovery_state_self_test() == 0
            && wls_recovery_attested_process_self_test() == 0
            ? 0 : 1;
    }
    if (argc == 2
        && strcmp(
            argv[1],
            "--capacity-reserve-contract-self-test"
        ) == 0) {
        int capacity_test = wls_capacity_contract_self_test();
        if (capacity_test == 0) {
            printf(
                "{\"production_inodes\":%u,"
                "\"token_fsync_batch\":%u,"
                "\"production_token_directory_fsyncs\":%u,"
                "\"test_token_directory_fsyncs\":%u}\n",
                WLS_CAPACITY_PRODUCTION_INODES,
                WLS_CAPACITY_TOKEN_FSYNC_BATCH,
                wls_capacity_token_directory_syncs(
                    WLS_CAPACITY_PRODUCTION_INODES
                ),
                wls_capacity_token_directory_syncs(
                    WLS_CAPACITY_TEST_INODES
                )
            );
        }
        return capacity_test;
    }
    if (argc == 2
        && strcmp(
            argv[1],
            "--guardian-systemd-definition-buffer-self-test"
        ) == 0) {
        int definition_test =
            wls_guardian_systemd_definition_buffer_self_test();
        if (definition_test == 0) {
            printf("{\"systemd_definition_buffer\":\"ok\"}\n");
        }
        return definition_test;
    }
    if (argc == 2
        && strcmp(
            argv[1],
            "--guardian-platform-shutdown-self-test"
        ) == 0) {
        int shutdown_test = wls_guardian_platform_shutdown_self_test();
        if (shutdown_test == 0) {
            printf(
                "{\"platform_grace_ms\":%lld,"
                "\"guardian_budget_ms\":%lld,"
                "\"crash_grace_ms\":%lld,"
                "\"main_only_signal\":true}\n",
                WLS_PLATFORM_SHUTDOWN_GRACE_MILLISECONDS,
                WLS_PLATFORM_GUARDIAN_SHUTDOWN_MILLISECONDS,
                WLS_BROKER_TERM_GRACE_MILLISECONDS
            );
        }
        return shutdown_test;
    }
    if (wls_argument(argc, argv, "--capacity-reserve") != NULL) {
        return wls_capacity_command(argc, argv);
    }
    home = wls_argument(argc, argv, "--home");
    run_directory = wls_argument(argc, argv, "--run");
    if (home == NULL || run_directory == NULL || home[0] != '/' || run_directory[0] != '/') {
        fprintf(stderr, "stable launcher requires absolute --home and --run paths\n");
        return 64;
    }
    if (wls_has_flag(argc, argv, "--guardian-recover")) {
        return wls_guardian_recover_command(home, run_directory);
    }
    if (wls_has_flag(argc, argv, "--guardian-child")
        && wls_guardian_bind_child(argc, argv, home) != 0) {
        fprintf(stderr, "stable launcher rejected an unauthenticated Guardian parent\n");
        return 77;
    }
    if (wls_install_signal_handlers() != 0) {
        fprintf(stderr, "gateway supervisor cannot install signal handlers\n");
        return 1;
    }
    if (wls_prepare_process_supervision() != 0) {
        fprintf(stderr, "gateway supervisor cannot establish child supervision\n");
        return 1;
    }
    if (wls_has_flag(argc, argv, "--guardian")) {
        return wls_guardian_run(home, run_directory);
    }
    if (wls_platform_service_identity(
            argc,
            argv,
            home,
            platform,
            service_id,
            launcher_generation
        ) != 0) {
        fprintf(stderr, "stable launcher cannot establish its platform generation\n");
        return 1;
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
        result = wls_launch(
            home,
            run_directory,
            platform,
            service_id,
            launcher_generation
        );
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
