#include <sys/types.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/un.h>
#include <sys/file.h>
#include <sys/select.h>
#include <sys/wait.h>
#include <fcntl.h>
#include <grp.h>
#include <pwd.h>
#include <pthread.h>
#include <signal.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <errno.h>
#include <limits.h>
#include <sodium.h>
#include <time.h>
#if defined(__APPLE__)
#include <sys/acl.h>
#include <sys/sysctl.h>
#include <sys/time.h>
#include <libproc.h>
#include <mach/vm_prot.h>
#endif
#if defined(__linux__)
#include <linux/capability.h>
#include <sys/prctl.h>
#include <sys/syscall.h>
#endif

#define WLS_MAX_REQUEST (4U * 1024U * 1024U)
#define WLS_MAX_SNAPSHOT (1024U * 1024U)
#define WLS_MAX_REGISTRY (4U * 1024U * 1024U)
#define WLS_TOKEN_HEX 64U
#define WLS_CONTROLLER_START_ATTEMPTS 4500U
#define WLS_CONTROLLER_START_POLL_US 10000U
#define WLS_CONTROLLER_START_TIMEOUT_SECONDS 45
#define WLS_CONTROLLER_PROBE_TIMEOUT_SECONDS 1
#define WLS_CONTROLLER_IO_TIMEOUT_SECONDS 90
#define WLS_CONTROLLER_STOP_POLL_US 100000U
#define WLS_CONTROLLER_STOP_GRACE_ATTEMPTS 50U
#define WLS_CONTROLLER_KILL_ATTEMPTS 50U
#define WLS_MAX_HANDLERS 64U
#define WLS_ADMIN_RESERVED_HANDLERS 8U
#define WLS_MAX_PROJECT_HANDLERS 48U
#define WLS_MAX_PROJECT_HANDLERS_PER_UID 2U
#define WLS_INITIAL_FRAME_CAPACITY 4096U
#define WLS_HANDLER_FREE 0
#define WLS_HANDLER_RUNNING 1
#define WLS_HANDLER_DONE 2
#define WLS_HANDLER_JOINING 3
#define WLS_UPGRADE_OBSERVATION_MILLISECONDS 300000LL
#define WLS_ROLLBACK_HEALTH_MILLISECONDS 15000LL
#define WLS_CONTROLLER_OBSERVATION_RESETS 3U
#define WLS_BOOTSTRAP_ATTEMPTS 4U
#define WLS_MAINTENANCE_BOOTSTRAP_INTERVAL_US 5000000U
#define WLS_MAINTENANCE_BOOTSTRAP_POLL_US 100000U
#define WLS_MAINTENANCE_CONTROLLER_IO_TIMEOUT_SECONDS 15
#define WLS_MAINTENANCE_HEALTH_FRESHNESS_MILLISECONDS 20000LL
#define WLS_MAINTENANCE_STOP_ATTEMPTS 50U
#define WLS_MAINTENANCE_STOP_POLL_US 100000U
#define WLS_PREVIOUS_CONTROLLER_EXIT_ATTEMPTS 110U
#define WLS_PREVIOUS_CONTROLLER_EXIT_POLL_US 100000U
#define WLS_CONTROLLER_IDENTITY_ATTEMPTS 500U
#define WLS_CONTROLLER_IDENTITY_POLL_US 10000U

static volatile sig_atomic_t wls_running = 1;
static volatile sig_atomic_t wls_bootstrap_maintenance_failed = 0;
static volatile sig_atomic_t wls_controller_pid = 0;
static char wls_admin_socket[PATH_MAX];
static char wls_project_socket[PATH_MAX];
static pthread_mutex_t wls_handler_mutex = PTHREAD_MUTEX_INITIALIZER;
static pthread_mutex_t wls_bootstrap_mutex = PTHREAD_MUTEX_INITIALIZER;
static unsigned int wls_active_handlers = 0U;
static unsigned int wls_active_project_handlers = 0U;

struct wls_handler_slot {
    pthread_t thread;
    int state;
};

struct wls_uid_gate_entry {
    unsigned long uid;
    unsigned int count;
};

static struct wls_handler_slot wls_handler_slots[WLS_MAX_HANDLERS];
static struct wls_uid_gate_entry wls_uid_gate[WLS_MAX_HANDLERS];

struct wls_peer {
    unsigned long uid;
    unsigned long gid;
    long pid;
};

struct wls_controller_identity {
    uid_t uid;
    gid_t gid;
};

struct wls_upgrade_binding {
    int present;
    char intent_sha256[65];
    char nonce[33];
    char from;
    char to;
    long long prepared_at;
    char runtime_generation[65];
    char boot_id[65];
    char phase[24];
    unsigned int attempts;
    long long observation_started;
    long long observation_deadline;
    long long total_deadline;
};

struct wls_bootstrap_receipt {
    char epoch[33];
    char controller_epoch[33];
    char active_slot[2];
    char runtime_generation[65];
    char host_boot_id[65];
    char intent_sha256[65];
    char intent_nonce[33];
    unsigned long long generation;
    unsigned long long active_config_generation;
    long long observed_monotonic_ms;
};

struct wls_handler_context {
    int client;
    const char *channel;
    const char *controller_socket;
    const char *fencing;
    const char *home;
    const struct wls_controller_identity *controller_identity;
    struct wls_peer peer;
    int project_gate_acquired;
    unsigned int slot_index;
};

struct wls_bootstrap_maintenance_context {
    char controller_socket[PATH_MAX];
    char fencing[WLS_TOKEN_HEX + 1U];
    char home[PATH_MAX];
    struct wls_controller_identity controller_identity;
    int controller_identity_present;
    pthread_mutex_t completion_mutex;
    int completed;
    struct wls_bootstrap_receipt receipt;
    long long continuous_since_ms;
    long long last_success_ms;
    int observation_mode;
    int observation_failed;
    char expected_slot[2];
    char expected_runtime_generation[65];
};

static int wls_write_all(int fd, const char *buffer, size_t length);
static void wls_release_controller_socket(const char *controller_socket);

static int wls_read_exact(int fd, char *buffer, size_t length)
{
    size_t offset = 0U;
    while (offset < length) {
        ssize_t amount = read(fd, buffer + offset, length - offset);
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) return -1;
        offset += (size_t)amount;
    }
    return 0;
}

static int wls_checked_add_ll(long long value, long long increment, long long *result)
{
    if (result == NULL
        || (increment > 0 && value > LLONG_MAX - increment)
        || (increment < 0 && value < LLONG_MIN - increment)) {
        errno = ERANGE;
        return -1;
    }
    *result = value + increment;
    return 0;
}

/* A frame includes its trailing newline and may never exceed 4 MiB. */
static ssize_t wls_read_frame_alloc(
    int fd,
    char **buffer_pointer,
    size_t *capacity_pointer
) {
    char *buffer;
    size_t capacity;
    size_t used = 0U;
    if (buffer_pointer == NULL || capacity_pointer == NULL) {
        errno = EINVAL;
        return -1;
    }
    buffer = *buffer_pointer;
    capacity = *capacity_pointer;
    if (buffer == NULL || capacity < 2U) {
        capacity = WLS_INITIAL_FRAME_CAPACITY;
        if (capacity > WLS_MAX_REQUEST + 1U) capacity = WLS_MAX_REQUEST + 1U;
        buffer = malloc(capacity);
        if (buffer == NULL) return -1;
        *buffer_pointer = buffer;
        *capacity_pointer = capacity;
    }
    while (used < WLS_MAX_REQUEST) {
        size_t available;
        ssize_t peeked;
        char *newline;
        size_t consume;
        if (used + 1U >= capacity) {
            size_t next = capacity < (WLS_MAX_REQUEST + 1U) / 2U
                ? capacity * 2U
                : WLS_MAX_REQUEST + 1U;
            char *grown;
            if (next <= capacity) {
                errno = EMSGSIZE;
                return -1;
            }
            grown = realloc(buffer, next);
            if (grown == NULL) return -1;
            buffer = grown;
            capacity = next;
            *buffer_pointer = buffer;
            *capacity_pointer = capacity;
        }
        available = capacity - used - 1U;
        peeked = recv(fd, buffer + used, available, MSG_PEEK);
        if (peeked < 0 && errno == EINTR) continue;
        if (peeked <= 0) return peeked;
        newline = memchr(buffer + used, '\n', (size_t)peeked);
        consume = newline == NULL
            ? (size_t)peeked
            : (size_t)(newline - (buffer + used)) + 1U;
        if (used + consume > WLS_MAX_REQUEST
            || wls_read_exact(fd, buffer + used, consume) != 0) {
            if (used + consume > WLS_MAX_REQUEST) errno = EMSGSIZE;
            return -1;
        }
        used += consume;
        if (newline != NULL) {
            buffer[used] = '\0';
            return (ssize_t)used;
        }
    }
    errno = EMSGSIZE;
    return -1;
}

static int wls_fsync_parent(const char *path)
{
    char parent[PATH_MAX];
    char *slash;
    int directory_fd;
    int result;
    if (path == NULL || strlen(path) >= sizeof(parent)) {
        errno = EINVAL;
        return -1;
    }
    memcpy(parent, path, strlen(path) + 1U);
    slash = strrchr(parent, '/');
    if (slash == NULL) {
        errno = EINVAL;
        return -1;
    }
    if (slash == parent) {
        slash[1] = '\0';
    } else {
        *slash = '\0';
    }
    directory_fd = open(
        parent,
        O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
    );
    if (directory_fd < 0) return -1;
    result = fsync(directory_fd);
    close(directory_fd);
    return result;
}

static int wls_fd_cloexec(int fd)
{
    int flags = fcntl(fd, F_GETFD);
    return flags >= 0 && fcntl(fd, F_SETFD, flags | FD_CLOEXEC) == 0 ? 0 : -1;
}

static void wls_signal(int signal_number)
{
    (void)signal_number;
    wls_running = 0;
}

static int wls_is_relative_safe(const char *path)
{
    const char *cursor;
    const char *segment;
    size_t length;
    if (path == NULL || path[0] == '\0' || path[0] == '/') {
        return 0;
    }
    segment = path;
    for (cursor = path; ; cursor++) {
        if (*cursor != '/' && *cursor != '\0') {
            continue;
        }
        length = (size_t)(cursor - segment);
        if (length == 0U
            || (length == 1U && segment[0] == '.')
            || (length == 2U && segment[0] == '.' && segment[1] == '.')) {
            return 0;
        }
        if (*cursor == '\0') {
            break;
        }
        segment = cursor + 1;
    }
    return 1;
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

static int wls_is_alias(const char *value)
{
    size_t index;
    size_t length;
    if (value == NULL) return 0;
    length = strlen(value);
    if (length == 0U || length > 32U || value[0] < 'a' || value[0] > 'z') return 0;
    for (index = 1U; index < length; index++) {
        if (!((value[index] >= 'a' && value[index] <= 'z')
            || (value[index] >= '0' && value[index] <= '9')
            || value[index] == '_')) {
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
            continue;
        }
        if (!((value[index] >= '0' && value[index] <= '9')
            || (value[index] >= 'a' && value[index] <= 'f'))) {
            return 0;
        }
    }
    return value[14U] == '4'
        && (value[19U] == '8' || value[19U] == '9'
            || value[19U] == 'a' || value[19U] == 'b');
}

static int wls_hex_decode(const char *hex, char *output, size_t capacity)
{
    size_t length;
    size_t index;
    if (hex == NULL) return -1;
    length = strlen(hex);
    if ((length & 1U) != 0U || length / 2U + 1U > capacity) return -1;
    for (index = 0U; index < length; index += 2U) {
        unsigned int high;
        unsigned int low;
        char left = hex[index];
        char right = hex[index + 1U];
        high = left >= '0' && left <= '9'
            ? (unsigned int)(left - '0')
            : left >= 'a' && left <= 'f' ? (unsigned int)(left - 'a' + 10) : 16U;
        low = right >= '0' && right <= '9'
            ? (unsigned int)(right - '0')
            : right >= 'a' && right <= 'f' ? (unsigned int)(right - 'a' + 10) : 16U;
        if (high > 15U || low > 15U) return -1;
        output[index / 2U] = (char)((high << 4U) | low);
        if (output[index / 2U] == '\0') return -1;
    }
    output[length / 2U] = '\0';
    return 0;
}

static int wls_open_relative(int root_fd, const char *relative, int final_flags)
{
    char copy[PATH_MAX];
    char *save = NULL;
    char *part;
    int directory_fd;
    int next_fd;
    if (!wls_is_relative_safe(relative) || strlen(relative) >= sizeof(copy)) {
        errno = EINVAL;
        return -1;
    }
    memcpy(copy, relative, strlen(relative) + 1U);
    directory_fd = fcntl(root_fd, F_DUPFD_CLOEXEC, 0);
    if (directory_fd < 0) {
        return -1;
    }
    part = strtok_r(copy, "/", &save);
    while (part != NULL) {
        char *next = strtok_r(NULL, "/", &save);
        if (next == NULL) {
            next_fd = openat(directory_fd, part, final_flags | O_CLOEXEC | O_NOFOLLOW);
        } else {
            next_fd = openat(
                directory_fd,
                part,
                O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW
            );
        }
        close(directory_fd);
        if (next_fd < 0) {
            return -1;
        }
        directory_fd = next_fd;
        part = next;
    }
    return directory_fd;
}

static int wls_open_absolute_directory(const char *path)
{
    int root_fd;
    int result;
    if (path == NULL || path[0] != '/' || path[1] == '\0') {
        errno = EINVAL;
        return -1;
    }
    root_fd = open("/", O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW);
    if (root_fd < 0) return -1;
    result = wls_open_relative(root_fd, path + 1, O_RDONLY | O_DIRECTORY);
    close(root_fd);
    return result;
}

static int wls_open_parent(int root_fd, const char *relative, char *leaf, size_t leaf_size)
{
    char copy[PATH_MAX];
    char *slash;
    int parent_fd;
    if (!wls_is_relative_safe(relative) || strlen(relative) >= sizeof(copy)) {
        errno = EINVAL;
        return -1;
    }
    memcpy(copy, relative, strlen(relative) + 1U);
    slash = strrchr(copy, '/');
    if (slash == NULL) {
        if (strlen(copy) + 1U > leaf_size) {
            errno = ENAMETOOLONG;
            return -1;
        }
        memcpy(leaf, copy, strlen(copy) + 1U);
        return fcntl(root_fd, F_DUPFD_CLOEXEC, 0);
    }
    *slash = '\0';
    slash++;
    if (strlen(slash) + 1U > leaf_size) {
        errno = ENAMETOOLONG;
        return -1;
    }
    memcpy(leaf, slash, strlen(slash) + 1U);
    parent_fd = wls_open_relative(root_fd, copy, O_RDONLY | O_DIRECTORY);
    return parent_fd;
}

static int wls_private_acl_safe(int fd)
{
#if defined(__APPLE__)
    acl_t acl;
    acl_entry_t entry;
    int entry_result;
    int entry_errno;
    int free_result;
    acl = acl_get_fd_np(fd, ACL_TYPE_EXTENDED);
    if (acl == NULL) return 0;
    errno = 0;
    entry_result = acl_get_entry(acl, ACL_FIRST_ENTRY, &entry);
    entry_errno = errno;
    free_result = acl_free(acl);
    // A private key must not carry any macOS extended ACL entry. Mode
    // 0600 alone does not suppress an explicit NFSv4 allow ACE.
    return entry_result < 0 && entry_errno == EINVAL && free_result == 0;
#else
    (void)fd;
    return 1;
#endif
}

static int wls_snapshot(
    const char *source_root,
    const char *source_relative,
    const char *destination_root,
    const char *destination_relative,
    uid_t expected_source_owner,
    int require_private_mode,
    uid_t destination_owner,
    gid_t destination_group
) {
    int source_root_fd = -1;
    int source_fd = -1;
    int destination_root_fd = -1;
    int destination_parent_fd = -1;
    int temporary_fd = -1;
    struct stat before;
    struct stat after;
    char destination_leaf[NAME_MAX + 1U];
    char temporary_leaf[NAME_MAX + 1U] = {0};
    char buffer[65536];
    unsigned long long temporary_nonce = 0U;
    ssize_t amount;
    uint64_t total = 0U;
    int result = 1;

    source_root_fd = wls_open_absolute_directory(source_root);
    destination_root_fd = wls_open_absolute_directory(destination_root);
    if (source_root_fd < 0 || destination_root_fd < 0) {
        goto cleanup;
    }
    source_fd = wls_open_relative(source_root_fd, source_relative, O_RDONLY);
    if (source_fd < 0
        || fstat(source_fd, &before) != 0
        || !S_ISREG(before.st_mode)
        || before.st_nlink != 1
        || before.st_size < 1
        || (uint64_t)before.st_size > WLS_MAX_SNAPSHOT
        || (expected_source_owner != (uid_t)-1
            && before.st_uid != expected_source_owner
            && before.st_uid != 0)
        || (require_private_mode
            && ((before.st_mode & 0077) != 0
                || !wls_private_acl_safe(source_fd)))) {
        goto cleanup;
    }
    destination_parent_fd = wls_open_parent(
        destination_root_fd,
        destination_relative,
        destination_leaf,
        sizeof(destination_leaf)
    );
    if (destination_parent_fd < 0) {
        goto cleanup;
    }
    randombytes_buf(&temporary_nonce, sizeof(temporary_nonce));
    if (snprintf(
        temporary_leaf,
        sizeof(temporary_leaf),
        ".wls-snapshot-%ld-%016llx",
        (long)getpid(),
        temporary_nonce
    ) >= (int)sizeof(temporary_leaf)) {
        errno = ENAMETOOLONG;
        goto cleanup;
    }
    temporary_fd = openat(
        destination_parent_fd,
        temporary_leaf,
        O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW,
        0600
    );
    if (temporary_fd < 0) {
        goto cleanup;
    }
    for (;;) {
        amount = read(source_fd, buffer, sizeof(buffer));
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) break;
        total += (uint64_t)amount;
        if (total > WLS_MAX_SNAPSHOT) {
            errno = EFBIG;
            goto cleanup;
        }
        if (wls_write_all(temporary_fd, buffer, (size_t)amount) != 0) goto cleanup;
    }
    if (amount < 0
        || total != (uint64_t)before.st_size
        || fchmod(temporary_fd, 0600) != 0
        || (destination_owner != (uid_t)-1
            && fchown(temporary_fd, destination_owner, destination_group) != 0)
        || fsync(temporary_fd) != 0
        || fstat(source_fd, &after) != 0
        || before.st_dev != after.st_dev
        || before.st_ino != after.st_ino
        || before.st_size != after.st_size
        || before.st_mode != after.st_mode
        || before.st_nlink != after.st_nlink
        || after.st_nlink != 1
        || before.st_uid != after.st_uid
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
        || (require_private_mode && !wls_private_acl_safe(source_fd))
    ) {
        goto cleanup;
    }
    close(temporary_fd);
    temporary_fd = -1;
    if (renameat(
        destination_parent_fd,
        temporary_leaf,
        destination_parent_fd,
        destination_leaf
    ) != 0 || fsync(destination_parent_fd) != 0) {
        goto cleanup;
    }
    result = 0;

cleanup:
    if (result != 0 && destination_parent_fd >= 0 && temporary_leaf[0] != '\0') {
        unlinkat(destination_parent_fd, temporary_leaf, 0);
    }
    if (temporary_fd >= 0) close(temporary_fd);
    if (destination_parent_fd >= 0) close(destination_parent_fd);
    if (destination_root_fd >= 0) close(destination_root_fd);
    if (source_fd >= 0) close(source_fd);
    if (source_root_fd >= 0) close(source_root_fd);
    return result;
}

static int wls_random_token(char token[WLS_TOKEN_HEX + 1U])
{
    static const char hex[] = "0123456789abcdef";
    unsigned char bytes[WLS_TOKEN_HEX / 2U];
    size_t index;
    randombytes_buf(bytes, sizeof(bytes));
    for (index = 0U; index < sizeof(bytes); index++) {
        token[index * 2U] = hex[bytes[index] >> 4U];
        token[index * 2U + 1U] = hex[bytes[index] & 0x0fU];
    }
    token[WLS_TOKEN_HEX] = '\0';
    sodium_memzero(bytes, sizeof(bytes));
    return 0;
}

static int wls_write_fencing(
    const char *path,
    const char *token,
    const struct wls_controller_identity *controller_identity
) {
    char temporary[PATH_MAX];
    unsigned long long nonce = 0U;
    int fd;
    size_t length = strlen(token);
    randombytes_buf(&nonce, sizeof(nonce));
    if (snprintf(
        temporary,
        sizeof(temporary),
        "%s.candidate.%ld.%016llx",
        path,
        (long)getpid(),
        nonce
    )
        >= (int)sizeof(temporary)) {
        return -1;
    }
    fd = open(temporary, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (fd < 0
        || wls_write_all(fd, token, length) != 0
        || wls_write_all(fd, "\n", 1U) != 0
    ) {
        if (fd >= 0) close(fd);
        unlink(temporary);
        return -1;
    }
    if (controller_identity != NULL) {
        if ((geteuid() == 0
                && fchown(fd, 0, controller_identity->gid) != 0)
            || (geteuid() != 0 && getegid() != controller_identity->gid)
            || fchmod(fd, 0640) != 0) {
            close(fd);
            unlink(temporary);
            return -1;
        }
    }
    if (fsync(fd) != 0) {
        close(fd);
        unlink(temporary);
        return -1;
    }
    close(fd);
    if (rename(temporary, path) != 0) {
        unlink(temporary);
        return -1;
    }
    if (wls_fsync_parent(path) != 0) {
        return -1;
    }
    return 0;
}

static int wls_read_hex_file(
    const char *path,
    char *text,
    size_t hex_length
) {
    struct stat status;
    size_t amount;
    int fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0
        || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode)
        || status.st_nlink != 1
        || status.st_uid != 0
        || (status.st_mode & 0022) != 0
        || hex_length + 2U > 256U
        || (status.st_size != (off_t)hex_length
            && status.st_size != (off_t)(hex_length + 1U))) {
        if (fd >= 0) close(fd);
        errno = EPERM;
        return -1;
    }
    amount = (size_t)status.st_size;
    if (wls_read_exact(fd, text, amount) != 0) {
        close(fd);
        errno = EIO;
        return -1;
    }
    close(fd);
    if (amount < hex_length
        || amount > hex_length + 1U
        || (amount == hex_length + 1U && text[hex_length] != '\n')) {
        errno = EINVAL;
        return -1;
    }
    text[hex_length] = '\0';
    return wls_is_hex(text, hex_length) ? 0 : -1;
}

static int wls_atomic_root_write(
    const char *path,
    const char *contents,
    size_t length
) {
    char temporary[PATH_MAX];
    unsigned long long nonce = 0U;
    int fd;
    randombytes_buf(&nonce, sizeof(nonce));
    if (snprintf(
        temporary,
        sizeof(temporary),
        "%s.candidate.%ld.%016llx",
        path,
        (long)getpid(),
        nonce
    ) >= (int)sizeof(temporary)) {
        return -1;
    }
    fd = open(temporary, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (fd < 0
        || wls_write_all(fd, contents, length) != 0
        || fchmod(fd, 0600) != 0
        || fchown(fd, 0, 0) != 0
        || fsync(fd) != 0) {
        if (fd >= 0) close(fd);
        unlink(temporary);
        return -1;
    }
    close(fd);
    if (rename(temporary, path) != 0 || wls_fsync_parent(path) != 0) {
        unlink(temporary);
        return -1;
    }
    return 0;
}

static int wls_write_admin_stopped(
    const char *home,
    const struct wls_peer *peer,
    const char *epoch
) {
    char token_path[PATH_MAX];
    char host_path[PATH_MAX];
    char intent_path[PATH_MAX];
    char token[crypto_auth_hmacsha256_KEYBYTES * 2U + 1U];
    char host[33];
    char nonce[WLS_TOKEN_HEX + 1U];
    char payload[512];
    char intent[640];
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char signature[crypto_auth_hmacsha256_BYTES];
    char signature_hex[crypto_auth_hmacsha256_BYTES * 2U + 1U];
    size_t decoded = 0U;
    int payload_length;
    int intent_length;
    int result = -1;
    if (home == NULL
        || epoch == NULL
        || !wls_is_hex(epoch, 32U)
        || (peer->uid != 0U && !(geteuid() != 0 && peer->uid == (unsigned long)geteuid()))
        || snprintf(token_path, sizeof(token_path), "%s/trust/admin.token", home)
            >= (int)sizeof(token_path)
        || snprintf(host_path, sizeof(host_path), "%s/trust/host-id", home)
            >= (int)sizeof(host_path)
        || snprintf(intent_path, sizeof(intent_path), "%s/trust/admin-stopped.intent", home)
            >= (int)sizeof(intent_path)
        || wls_read_hex_file(token_path, token, 64U) != 0
        || wls_read_hex_file(host_path, host, 32U) != 0
        || wls_random_token(nonce) != 0
        || sodium_hex2bin(
            key,
            sizeof(key),
            token,
            64U,
            NULL,
            &decoded,
            NULL
        ) != 0
        || decoded != sizeof(key)) {
        goto cleanup;
    }
    nonce[32] = '\0';
    payload_length = snprintf(
        payload,
        sizeof(payload),
        "WLS-ADMIN-STOPPED/1\nhost_id=%s\nepoch=%s\nat=%lld\nnonce=%s\n",
        host,
        epoch,
        (long long)time(NULL),
        nonce
    );
    if (payload_length <= 0
        || payload_length >= (int)sizeof(payload)
        || crypto_auth_hmacsha256(
            signature,
            (const unsigned char *)payload,
            (unsigned long long)payload_length,
            key
        ) != 0) {
        goto cleanup;
    }
    sodium_bin2hex(signature_hex, sizeof(signature_hex), signature, sizeof(signature));
    intent_length = snprintf(
        intent,
        sizeof(intent),
        "%ssignature=%s\n",
        payload,
        signature_hex
    );
    if (intent_length <= 0
        || intent_length >= (int)sizeof(intent)
        || wls_atomic_root_write(intent_path, intent, (size_t)intent_length) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(key, sizeof(key));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(token, sizeof(token));
    return result;
}

static int wls_upgrade_boot_id(char output[65])
{
    static const char prefix[] = "wls-gateway-host-boot/1|";
    char platform_token[65];
    char canonical[sizeof(prefix) + sizeof(platform_token)];
    unsigned char digest[crypto_hash_sha256_BYTES];
    int canonical_length;
    int result = -1;
#if defined(__linux__)
    int fd = open("/proc/sys/kernel/random/boot_id", O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    ssize_t amount;
    size_t index;
    if (fd < 0) return -1;
    amount = read(fd, platform_token, 64U);
    close(fd);
    if (amount <= 0) return -1;
    while (amount > 0
        && (platform_token[amount - 1] == '\n'
            || platform_token[amount - 1] == '\r')) amount--;
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
    size_t size = sizeof(boot);
    int length;
    if (sysctlbyname("kern.boottime", &boot, &size, NULL, 0) != 0
        || size != sizeof(boot)) return -1;
    length = snprintf(platform_token, sizeof(platform_token), "darwin-%lld-%d",
        (long long)boot.tv_sec, (int)boot.tv_usec);
    if (length <= 0 || length >= (int)sizeof(platform_token)) return -1;
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

static int wls_upgrade_binding_read(
    const char *home,
    struct wls_upgrade_binding *binding
) {
    char intent_path[PATH_MAX];
    char state_path[PATH_MAX];
    unsigned char *intent = NULL;
    unsigned char *state = NULL;
    size_t intent_length = 0U;
    size_t state_length = 0U;
    unsigned char digest[crypto_hash_sha256_BYTES];
    char host[33], from[2], to[2], signature[65];
    char state_digest[65], state_nonce[33], state_runtime[65];
    long long legacy_deadline = 0;
    long long expected_legacy_deadline = 0;
    long long expected_total_deadline = 0;
    int intent_consumed = 0;
    int state_consumed = 0;
    int fields;
    memset(binding, 0, sizeof(*binding));
    if (snprintf(intent_path, sizeof(intent_path), "%s/trust/upgrade.intent", home)
            >= (int)sizeof(intent_path)
        || snprintf(state_path, sizeof(state_path), "%s/trust/upgrade-state", home)
            >= (int)sizeof(state_path)) return -1;
    if (wls_read_file(intent_path, 2048U, &intent, &intent_length) != 0) {
        if (errno != ENOENT) return -1;
        return 0;
    }
    fields = sscanf(
        (const char *)intent,
        "WLS-UPGRADE/1\nhost_id=%32[0-9a-f]\nfrom=%1[AB]\nto=%1[AB]\n"
        "prepared_at=%lld\ndeadline=%lld\nruntime_generation=%64[0-9a-f]\n"
        "nonce=%32[0-9a-f]\nsignature=%64[0-9a-f]\n%n",
        host, from, to, &binding->prepared_at, &legacy_deadline,
        binding->runtime_generation, binding->nonce, signature, &intent_consumed
    );
    if (fields != 8 || intent_consumed != (int)intent_length || from[0] == to[0]
        || binding->prepared_at < 1
        || wls_checked_add_ll(
            binding->prepared_at,
            300LL,
            &expected_legacy_deadline
        ) != 0
        || legacy_deadline != expected_legacy_deadline
        || crypto_hash_sha256(digest, intent, (unsigned long long)intent_length) != 0
        || sodium_bin2hex(binding->intent_sha256, sizeof(binding->intent_sha256),
            digest, sizeof(digest)) == NULL
        || wls_read_file(state_path, 1024U, &state, &state_length) != 0) {
        free(intent);
        sodium_memzero(digest, sizeof(digest));
        return -1;
    }
    binding->from = from[0];
    binding->to = to[0];
    fields = sscanf(
        (const char *)state,
        "WLS-UPGRADE-STATE/2\n"
        "intent_sha256=%64[0-9a-f]\nintent_nonce=%32[0-9a-f]\n"
        "from=%1[AB]\nto=%1[AB]\nruntime_generation=%64[0-9a-f]\n"
        "boot_id=%64[0-9A-Za-z-]\nphase=%23[A-Z_]\nattempts=%u\n"
        "observation_started_monotonic_ms=%lld\n"
        "observation_deadline_monotonic_ms=%lld\ntotal_deadline=%lld\n%n",
        state_digest, state_nonce, from, to, state_runtime,
        binding->boot_id, binding->phase, &binding->attempts,
        &binding->observation_started, &binding->observation_deadline,
        &binding->total_deadline, &state_consumed
    );
    free(intent);
    free(state);
    sodium_memzero(digest, sizeof(digest));
    if (fields != 11 || state_consumed != (int)state_length
        || strcmp(state_digest, binding->intent_sha256) != 0
        || strcmp(state_nonce, binding->nonce) != 0
        || from[0] != binding->from || to[0] != binding->to
        || strcmp(state_runtime, binding->runtime_generation) != 0
        || wls_checked_add_ll(
            binding->prepared_at,
            900LL,
            &expected_total_deadline
        ) != 0
        || binding->total_deadline != expected_total_deadline) return -1;
    binding->present = 1;
    return 1;
}

static int wls_existing_upgrade_observation(
    const char *path,
    const struct wls_upgrade_binding *binding,
    long long *started
) {
    unsigned char *contents = NULL;
    size_t length = 0U;
    char digest[65], nonce[33], from[2], to[2], runtime[65], boot[65];
    long long parsed_started = 0, deadline = 0;
    long long expected_deadline = 0;
    long long now_ms = 0;
    struct timespec now;
    int consumed = 0;
    int result = 0;
    if (wls_read_file(path, 768U, &contents, &length) != 0) return 0;
    if (clock_gettime(CLOCK_MONOTONIC, &now) == 0
        && now.tv_sec <= LLONG_MAX / 1000LL
        && (now_ms = (long long)now.tv_sec * 1000LL
            + (long long)now.tv_nsec / 1000000LL) > 0
        && sscanf((const char *)contents,
        "WLS-UPGRADE-OBSERVING/2\n"
        "intent_sha256=%64[0-9a-f]\nintent_nonce=%32[0-9a-f]\n"
        "from=%1[AB]\nto=%1[AB]\nruntime_generation=%64[0-9a-f]\n"
        "boot_id=%64[0-9A-Za-z-]\nstarted_monotonic_ms=%lld\n"
        "deadline_monotonic_ms=%lld\n%n",
        digest, nonce, from, to, runtime, boot, &parsed_started, &deadline,
        &consumed) == 8 && consumed == (int)length
        && strcmp(digest, binding->intent_sha256) == 0
        && strcmp(nonce, binding->nonce) == 0
        && from[0] == binding->from && to[0] == binding->to
        && strcmp(runtime, binding->runtime_generation) == 0
        && strcmp(boot, binding->boot_id) == 0
        && parsed_started > 0
        && parsed_started <= now_ms
        && wls_checked_add_ll(
            parsed_started,
            WLS_UPGRADE_OBSERVATION_MILLISECONDS,
            &expected_deadline
        ) == 0
        && deadline == expected_deadline
        && now_ms <= deadline) {
        *started = parsed_started;
        result = 1;
    }
    free(contents);
    return result;
}

/* 0=no transaction, 1=candidate observation, 2=rollback health proof. */
static int wls_prepare_upgrade_runtime(
    const char *home,
    const char *active_slot,
    const char *runtime_generation,
    long long *started_ms
) {
    struct wls_upgrade_binding binding;
    char observing_path[PATH_MAX], healthy_path[PATH_MAX], rollback_path[PATH_MAX];
    char boot_id[65];
    char payload[768];
    struct timespec now;
    int status;
    int length;
    long long observation_deadline;
    if (started_ms == NULL || clock_gettime(CLOCK_MONOTONIC, &now) != 0
        || wls_upgrade_boot_id(boot_id) != 0) return -1;
    *started_ms = (long long)now.tv_sec * 1000LL + (long long)now.tv_nsec / 1000000LL;
    status = wls_upgrade_binding_read(home, &binding);
    if (status <= 0) return status;
    if (strcmp(binding.boot_id, boot_id) != 0) return -1;
    if (snprintf(observing_path, sizeof(observing_path), "%s/trust/upgrade-observing", home)
            >= (int)sizeof(observing_path)
        || snprintf(healthy_path, sizeof(healthy_path), "%s/trust/upgrade-healthy", home)
            >= (int)sizeof(healthy_path)
        || snprintf(rollback_path, sizeof(rollback_path),
            "%s/trust/upgrade-rollback-healthy", home) >= (int)sizeof(rollback_path)) return -1;
    if (active_slot[0] == binding.from
        && strcmp(binding.phase, "ROLLBACK_PENDING") == 0) {
        (void)unlink(rollback_path);
        return 2;
    }
    if (active_slot[0] != binding.to
        || strcmp(runtime_generation, binding.runtime_generation) != 0
        || (strcmp(binding.phase, "PREPARED") != 0
            && strcmp(binding.phase, "OBSERVING") != 0)) return 0;
    if (wls_existing_upgrade_observation(observing_path, &binding, started_ms)) return 1;
    if (strcmp(binding.phase, "OBSERVING") == 0) return -1;
    if (wls_checked_add_ll(
            *started_ms,
            WLS_UPGRADE_OBSERVATION_MILLISECONDS,
            &observation_deadline
        ) != 0) return -1;
    length = snprintf(payload, sizeof(payload),
        "WLS-UPGRADE-OBSERVING/2\n"
        "intent_sha256=%s\nintent_nonce=%s\nfrom=%c\nto=%c\n"
        "runtime_generation=%s\nboot_id=%s\n"
        "started_monotonic_ms=%lld\ndeadline_monotonic_ms=%lld\n",
        binding.intent_sha256, binding.nonce, binding.from, binding.to,
        binding.runtime_generation, binding.boot_id, *started_ms,
        observation_deadline);
    if (length <= 0 || length >= (int)sizeof(payload)
        || wls_atomic_root_write(observing_path, payload, (size_t)length) != 0) return -1;
    (void)unlink(healthy_path);
    return 1;
}

static int wls_write_upgrade_healthy(
    const char *home,
    const char *active_slot,
    const char *runtime_generation,
    long long started_ms
) {
    struct wls_upgrade_binding binding;
    char path[PATH_MAX], payload[768];
    struct timespec now;
    long long healthy_ms;
    long long observation_deadline;
    int length;
    if (wls_upgrade_binding_read(home, &binding) != 1
        || active_slot[0] != binding.to
        || strcmp(runtime_generation, binding.runtime_generation) != 0
        || strcmp(binding.phase, "OBSERVING") != 0) return -1;
    if (clock_gettime(CLOCK_MONOTONIC, &now) != 0) return -1;
    healthy_ms = (long long)now.tv_sec * 1000LL
        + (long long)now.tv_nsec / 1000000LL;
    if (wls_checked_add_ll(
            started_ms,
            WLS_UPGRADE_OBSERVATION_MILLISECONDS,
            &observation_deadline
        ) != 0
        || healthy_ms < observation_deadline) return -1;
    if (snprintf(path, sizeof(path), "%s/trust/upgrade-healthy", home)
            >= (int)sizeof(path)) return -1;
    length = snprintf(payload, sizeof(payload),
        "WLS-UPGRADE-HEALTHY/2\n"
        "intent_sha256=%s\nintent_nonce=%s\nfrom=%c\nto=%c\n"
        "runtime_generation=%s\nboot_id=%s\n"
        "observation_deadline_monotonic_ms=%lld\nhealthy_monotonic_ms=%lld\n",
        binding.intent_sha256, binding.nonce, binding.from, binding.to,
        binding.runtime_generation, binding.boot_id,
        observation_deadline,
        healthy_ms);
    return length > 0 && length < (int)sizeof(payload)
        ? wls_atomic_root_write(path, payload, (size_t)length) : -1;
}

static int wls_write_rollback_healthy(
    const char *home,
    const char *active_slot,
    const char *runtime_generation,
    long long started_ms,
    long long healthy_ms
) {
    struct wls_upgrade_binding binding;
    char path[PATH_MAX], payload[768];
    int length;
    long long health_deadline;
    if (wls_upgrade_binding_read(home, &binding) != 1
        || active_slot[0] != binding.from
        || strcmp(binding.phase, "ROLLBACK_PENDING") != 0
        || !wls_is_hex(runtime_generation, 64U)
        || wls_checked_add_ll(
            started_ms,
            WLS_ROLLBACK_HEALTH_MILLISECONDS,
            &health_deadline
        ) != 0
        || healthy_ms < health_deadline
        || snprintf(path, sizeof(path), "%s/trust/upgrade-rollback-healthy", home)
            >= (int)sizeof(path)) return -1;
    length = snprintf(payload, sizeof(payload),
        "WLS-UPGRADE-ROLLBACK-HEALTHY/2\n"
        "intent_sha256=%s\nintent_nonce=%s\nfrom=%c\nto=%c\n"
        "active_runtime_generation=%s\nboot_id=%s\n"
        "started_monotonic_ms=%lld\nhealthy_monotonic_ms=%lld\n",
        binding.intent_sha256, binding.nonce, binding.from, binding.to,
        runtime_generation, binding.boot_id, started_ms, healthy_ms);
    return length > 0 && length < (int)sizeof(payload)
        ? wls_atomic_root_write(path, payload, (size_t)length) : -1;
}

static int wls_create_listener(const char *path, mode_t mode)
{
    int fd;
    struct sockaddr_un address;
    struct stat status;
    size_t length = strlen(path);
    if (length == 0U || length >= sizeof(address.sun_path)) {
        errno = ENAMETOOLONG;
        return -1;
    }
    if (lstat(path, &status) == 0) {
        if (!S_ISSOCK(status.st_mode) || status.st_uid != geteuid()) {
            errno = EPERM;
            return -1;
        }
        if (unlink(path) != 0) {
            return -1;
        }
    } else if (errno != ENOENT) {
        return -1;
    }
    fd = socket(AF_UNIX, SOCK_STREAM, 0);
    if (fd < 0 || wls_fd_cloexec(fd) != 0) {
        if (fd >= 0) close(fd);
        return -1;
    }
    memset(&address, 0, sizeof(address));
    address.sun_family = AF_UNIX;
    memcpy(address.sun_path, path, length + 1U);
    if (bind(fd, (struct sockaddr *)&address, sizeof(address)) != 0
        || chmod(path, mode) != 0
        || listen(fd, 64) != 0
    ) {
        close(fd);
        unlink(path);
        return -1;
    }
    return fd;
}

static int wls_peer_identity(int client, struct wls_peer *peer)
{
#if defined(__linux__)
    struct ucred credentials;
    socklen_t length = sizeof(credentials);
    if (getsockopt(client, SOL_SOCKET, SO_PEERCRED, &credentials, &length) != 0) {
        return -1;
    }
    peer->uid = (unsigned long)credentials.uid;
    peer->gid = (unsigned long)credentials.gid;
    peer->pid = (long)credentials.pid;
    return 0;
#elif defined(__APPLE__) || defined(__FreeBSD__)
    uid_t uid;
    gid_t gid;
    if (getpeereid(client, &uid, &gid) != 0) {
        return -1;
    }
    peer->uid = (unsigned long)uid;
    peer->gid = (unsigned long)gid;
    peer->pid = -1;
#if defined(LOCAL_PEERPID)
    {
        pid_t pid = -1;
        socklen_t length = sizeof(pid);
        if (getsockopt(client, SOL_LOCAL, LOCAL_PEERPID, &pid, &length) == 0) {
            peer->pid = (long)pid;
        }
    }
#endif
    return 0;
#else
    (void)client;
    (void)peer;
    errno = ENOTSUP;
    return -1;
#endif
}

static ssize_t wls_read_line(int fd, char *buffer, size_t capacity)
{
    size_t used = 0U;
    if (buffer == NULL || capacity < 2U) {
        errno = EINVAL;
        return -1;
    }
    while (used + 1U < capacity) {
        size_t available = capacity - used - 1U;
        ssize_t amount = recv(fd, buffer + used, available, MSG_PEEK);
        char *newline;
        size_t consume;
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) return amount;
        newline = memchr(buffer + used, '\n', (size_t)amount);
        consume = newline == NULL
            ? (size_t)amount
            : (size_t)(newline - (buffer + used)) + 1U;
        if (wls_read_exact(fd, buffer + used, consume) != 0) return -1;
        used += consume;
        if (newline != NULL) {
            buffer[used] = '\0';
            return (ssize_t)used;
        }
    }
    errno = EMSGSIZE;
    return -1;
}

static int wls_authenticate_controller(int fd, const char *fencing)
{
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char request_signature[crypto_auth_hmacsha256_BYTES];
    unsigned char response_signature[crypto_auth_hmacsha256_BYTES];
    char nonce[WLS_TOKEN_HEX + 1U];
    char request_payload[128];
    char response_payload[128];
    char request_signature_hex[crypto_auth_hmacsha256_BYTES * 2U + 1U];
    char response_signature_hex[crypto_auth_hmacsha256_BYTES * 2U + 1U];
    char request[256];
    char expected[128];
    char actual[128];
    size_t decoded = 0U;
    int request_payload_length;
    int response_payload_length;
    int request_length;
    int expected_length;
    ssize_t actual_length;
    int result = -1;
    memset(key, 0, sizeof(key));
    memset(request_signature, 0, sizeof(request_signature));
    memset(response_signature, 0, sizeof(response_signature));
    if (!wls_is_hex(fencing, WLS_TOKEN_HEX)
        || sodium_hex2bin(
            key,
            sizeof(key),
            fencing,
            WLS_TOKEN_HEX,
            NULL,
            &decoded,
            NULL
        ) != 0
        || decoded != sizeof(key)
        || wls_random_token(nonce) != 0) {
        goto cleanup;
    }
    request_payload_length = snprintf(
        request_payload,
        sizeof(request_payload),
        "WLS-BROKER-PROBE/1\nnonce=%s\n",
        nonce
    );
    response_payload_length = snprintf(
        response_payload,
        sizeof(response_payload),
        "WLS-BROKER-READY/1\nnonce=%s\n",
        nonce
    );
    if (request_payload_length <= 0
        || request_payload_length >= (int)sizeof(request_payload)
        || response_payload_length <= 0
        || response_payload_length >= (int)sizeof(response_payload)
        || crypto_auth_hmacsha256(
            request_signature,
            (const unsigned char *)request_payload,
            (unsigned long long)request_payload_length,
            key
        ) != 0
        || crypto_auth_hmacsha256(
            response_signature,
            (const unsigned char *)response_payload,
            (unsigned long long)response_payload_length,
            key
        ) != 0) {
        goto cleanup;
    }
    sodium_bin2hex(
        request_signature_hex,
        sizeof(request_signature_hex),
        request_signature,
        sizeof(request_signature)
    );
    sodium_bin2hex(
        response_signature_hex,
        sizeof(response_signature_hex),
        response_signature,
        sizeof(response_signature)
    );
    request_length = snprintf(
        request,
        sizeof(request),
        "WLS-BROKER-PROBE/1\t%s\t%s\n",
        nonce,
        request_signature_hex
    );
    expected_length = snprintf(
        expected,
        sizeof(expected),
        "WLS-BROKER-READY/1\t%s\n",
        response_signature_hex
    );
    if (request_length <= 0
        || request_length >= (int)sizeof(request)
        || expected_length <= 0
        || expected_length >= (int)sizeof(expected)
        || wls_write_all(fd, request, (size_t)request_length) != 0) {
        goto cleanup;
    }
    actual_length = wls_read_line(fd, actual, sizeof(actual));
    if (actual_length != expected_length
        || sodium_memcmp(actual, expected, (size_t)expected_length) != 0) {
        errno = EACCES;
        goto cleanup;
    }
    result = 0;
cleanup:
    sodium_memzero(key, sizeof(key));
    sodium_memzero(request_signature, sizeof(request_signature));
    sodium_memzero(response_signature, sizeof(response_signature));
    sodium_memzero(request_signature_hex, sizeof(request_signature_hex));
    sodium_memzero(response_signature_hex, sizeof(response_signature_hex));
    sodium_memzero(request, sizeof(request));
    sodium_memzero(expected, sizeof(expected));
    sodium_memzero(actual, sizeof(actual));
    return result;
}

static int wls_connect_controller(
    const char *path,
    const char *fencing,
    int timeout_seconds
)
{
    int fd;
    struct sockaddr_un address;
    struct timeval timeout;
    size_t length = strlen(path);
    if (length == 0U || length >= sizeof(address.sun_path)) {
        errno = ENAMETOOLONG;
        return -1;
    }
    if (timeout_seconds < 1) {
        errno = EINVAL;
        return -1;
    }
    fd = socket(AF_UNIX, SOCK_STREAM, 0);
    if (fd < 0 || wls_fd_cloexec(fd) != 0) {
        if (fd >= 0) close(fd);
        return -1;
    }
    timeout.tv_sec = timeout_seconds;
    timeout.tv_usec = 0;
    if (setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout)) != 0
        || setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &timeout, sizeof(timeout)) != 0) {
        close(fd);
        return -1;
    }
    memset(&address, 0, sizeof(address));
    address.sun_family = AF_UNIX;
    memcpy(address.sun_path, path, length + 1U);
    if (connect(fd, (struct sockaddr *)&address, sizeof(address)) != 0
        || wls_authenticate_controller(fd, fencing) != 0) {
        close(fd);
        return -1;
    }
    return fd;
}

static int wls_write_all(int fd, const char *buffer, size_t length)
{
    size_t written = 0U;
    while (written < length) {
        ssize_t amount = write(fd, buffer + written, length - written);
        if (amount < 0 && errno == EINTR) continue;
        if (amount <= 0) return -1;
        written += (size_t)amount;
    }
    return 0;
}

static int wls_reap_controller(
    pid_t controller_pid,
    int options,
    int *controller_status
)
{
    pid_t waited;
    do {
        waited = waitpid(controller_pid, controller_status, options);
    } while (waited < 0 && errno == EINTR);
    return waited == controller_pid ? 0 : -1;
}

static int wls_wait_controller_exit_bounded(
    pid_t controller_pid,
    unsigned int attempts
) {
    unsigned int attempt;
    if (controller_pid <= 0 || attempts == 0U) {
        errno = EINVAL;
        return -1;
    }
    for (attempt = 0U; attempt < attempts; attempt++) {
        pid_t waited;
        do {
            waited = waitpid(controller_pid, NULL, WNOHANG);
        } while (waited < 0 && errno == EINTR);
        if (waited == controller_pid || (waited < 0 && errno == ECHILD)) {
            return 0;
        }
        if (waited < 0) return -1;
        if (usleep(WLS_CONTROLLER_STOP_POLL_US) != 0 && errno != EINTR) {
            return -1;
        }
    }
    errno = ETIMEDOUT;
    return -1;
}

static int wls_stop_controller_bounded(
    pid_t controller_pid,
    const char *controller_socket
) {
    int stop_error;
    if (controller_pid <= 0 || controller_socket == NULL) {
        errno = EINVAL;
        return -1;
    }
    if (kill(controller_pid, SIGTERM) != 0 && errno != ESRCH) return -1;
    if (wls_wait_controller_exit_bounded(
            controller_pid,
            WLS_CONTROLLER_STOP_GRACE_ATTEMPTS
        ) != 0) {
        stop_error = errno;
        if (stop_error != ETIMEDOUT
            || (kill(controller_pid, SIGKILL) != 0 && errno != ESRCH)
            || wls_wait_controller_exit_bounded(
                controller_pid,
                WLS_CONTROLLER_KILL_ATTEMPTS
            ) != 0) {
            return -1;
        }
    }
    wls_release_controller_socket(controller_socket);
    return 0;
}

static void wls_release_controller_socket(const char *controller_socket)
{
    if (unlink(controller_socket) != 0 && errno != ENOENT) {
        fprintf(stderr, "broker controller socket cleanup failed: %s\n", strerror(errno));
    }
}

static int wls_registry_path(char *output, size_t capacity, const char *home)
{
    int written = snprintf(output, capacity, "%s/trust/broker-enrollments.tsv", home);
    return written > 0 && written < (int)capacity ? 0 : -1;
}

static int wls_split_tsv(
    char *line,
    char **fields,
    size_t field_capacity,
    size_t *field_count
) {
    char *cursor = line;
    size_t count = 0U;
    if (line == NULL || fields == NULL || field_count == NULL) return -1;
    for (;;) {
        char *tab;
        if (count >= field_capacity) return -1;
        fields[count++] = cursor;
        tab = strchr(cursor, '\t');
        if (tab == NULL) break;
        *tab = '\0';
        cursor = tab + 1U;
    }
    *field_count = count;
    return 0;
}

static int wls_parse_unsigned(
    const char *text,
    int allow_zero,
    unsigned long *value
) {
    unsigned long parsed = 0U;
    size_t index;
    if (text == NULL || value == NULL || text[0] == '\0') return -1;
    for (index = 0U; text[index] != '\0'; index++) {
        unsigned long digit;
        if (text[index] < '0' || text[index] > '9') return -1;
        digit = (unsigned long)(text[index] - '0');
        if (parsed > (ULONG_MAX - digit) / 10U) return -1;
        parsed = parsed * 10U + digit;
    }
    if (!allow_zero && parsed == 0U) return -1;
    *value = parsed;
    return 0;
}

static int wls_repair_trust_access(
    const char *home,
    const struct wls_controller_identity *controller_identity
) {
    char trust[PATH_MAX];
    struct stat status;
    mode_t mode;
    int written;
    if (geteuid() != 0) {
        return 0;
    }
    if (home == NULL
        || home[0] != '/'
        || home[1] == '\0'
        || controller_identity == NULL) {
        errno = EINVAL;
        return -1;
    }
    written = snprintf(trust, sizeof(trust), "%s/trust", home);
    if (written <= 0
        || written >= (int)sizeof(trust)
        || lstat(trust, &status) != 0
        || !S_ISDIR(status.st_mode)
        || S_ISLNK(status.st_mode)
        || status.st_uid != 0
        || status.st_gid != controller_identity->gid) {
        errno = EPERM;
        return -1;
    }
    mode = status.st_mode & 0777;
    if (mode != 0700 && mode != 0750) {
        errno = EPERM;
        return -1;
    }
    return mode == 0750 ? 0 : chmod(trust, 0750);
}

static int wls_registry_append(const char *home, const char *record)
{
    char path[PATH_MAX];
    struct stat status;
    int fd = -1;
    size_t length;
    off_t original_size = 0;
    int locked = 0;
    int result = -1;
    if (wls_registry_path(path, sizeof(path), home) != 0 || record == NULL) return -1;
    length = strlen(record);
    if (length < 2U
        || length > 131072U
        || record[length - 1U] != '\n'
        || memchr(record, '\r', length) != NULL
        || memchr(record, '\n', length - 1U) != NULL) {
        errno = EINVAL;
        return -1;
    }
    fd = open(path, O_RDWR | O_APPEND | O_CREAT | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (fd < 0
        || flock(fd, LOCK_EX) != 0
        || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode)
        || status.st_nlink != 1
        || status.st_uid != geteuid()
        || (status.st_mode & 0077) != 0
        || status.st_size < 0
        || (uint64_t)status.st_size > WLS_MAX_REGISTRY - length) {
        errno = EPERM;
        goto cleanup;
    }
    locked = 1;
    original_size = status.st_size;
    if (original_size > 0) {
        char tail;
        ssize_t amount;
        do {
            amount = pread(fd, &tail, 1U, original_size - 1);
        } while (amount < 0 && errno == EINTR);
        if (amount != 1 || tail != '\n') {
            errno = EPROTO;
            goto cleanup;
        }
    }
    if (wls_write_all(fd, record, length) != 0 || fsync(fd) != 0) goto cleanup;
    if (wls_fsync_parent(path) != 0) goto cleanup;
    result = 0;
cleanup:
    if (fd >= 0) {
        if (locked && result != 0) {
            (void)ftruncate(fd, original_size);
            (void)fsync(fd);
        }
        if (locked) (void)flock(fd, LOCK_UN);
        close(fd);
    }
    return result;
}

static int wls_registry_lookup(
    const char *home,
    const char *project,
    unsigned long generation,
    const char *alias,
    unsigned long *owner,
    char *root,
    size_t root_capacity
) {
    char path[PATH_MAX];
    char line[PATH_MAX * 3U];
    char root_hex[PATH_MAX * 2U + 1U];
    struct stat status;
    FILE *stream = NULL;
    int fd = -1;
    int found = 0;
    int result = -1;
    unsigned long revoked_generation = 0U;
    if (wls_registry_path(path, sizeof(path), home) != 0) return -1;
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0
        || flock(fd, LOCK_SH) != 0
        || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode)
        || status.st_nlink != 1
        || status.st_uid != geteuid()
        || (status.st_mode & 0077) != 0
        || status.st_size < 1
        || (uint64_t)status.st_size > WLS_MAX_REGISTRY) {
        goto cleanup;
    }
    stream = fdopen(fd, "r");
    if (stream == NULL) goto cleanup;
    fd = -1;
    while (fgets(line, sizeof(line), stream) != NULL) {
        char *fields[7];
        size_t field_count = 0U;
        size_t line_length = strlen(line);
        unsigned long parsed_generation = 0U;
        if (line_length < 2U
            || line[line_length - 1U] != '\n'
            || memchr(line, '\0', line_length) != NULL
            || memchr(line, '\r', line_length) != NULL) {
            errno = EPROTO;
            goto cleanup;
        }
        line[--line_length] = '\0';
        if (wls_split_tsv(line, fields, 7U, &field_count) != 0
            || (field_count != 3U && field_count != 6U)
            || !wls_is_uuid(fields[1])
            || wls_parse_unsigned(fields[2], 0, &parsed_generation) != 0) {
            errno = EPROTO;
            goto cleanup;
        }
        if (field_count == 3U) {
            if (strcmp(fields[0], "R") != 0) {
                errno = EPROTO;
                goto cleanup;
            }
            if (strcmp(fields[1], project) == 0
                && parsed_generation > revoked_generation) {
                revoked_generation = parsed_generation;
            }
            continue;
        }
        {
            unsigned long parsed_owner = 0U;
            size_t root_length = strlen(fields[5]);
            if (strcmp(fields[0], "A") != 0
                || wls_parse_unsigned(fields[3], 1, &parsed_owner) != 0
                || (unsigned long)(uid_t)parsed_owner != parsed_owner
                || !wls_is_alias(fields[4])
                || root_length < 2U
                || (root_length & 1U) != 0U
                || root_length >= sizeof(root_hex)
                || !wls_is_hex(fields[5], root_length)) {
                errno = EPROTO;
                goto cleanup;
            }
            if (strcmp(fields[1], project) == 0
                && parsed_generation == generation
                && strcmp(fields[4], alias) == 0) {
                if (found
                    && (*owner != parsed_owner
                        || strcmp(root_hex, fields[5]) != 0)) {
                    errno = EPROTO;
                    goto cleanup;
                }
                if (!found) {
                    memcpy(root_hex, fields[5], root_length + 1U);
                    *owner = parsed_owner;
                    found = 1;
                }
            }
        }
    }
    if (ferror(stream) != 0 || !found || revoked_generation >= generation
        || wls_hex_decode(root_hex, root, root_capacity) != 0) {
        errno = EPERM;
        goto cleanup;
    }
    result = 0;
cleanup:
    if (stream != NULL) {
        fclose(stream);
    } else if (fd >= 0) {
        (void)flock(fd, LOCK_UN);
        close(fd);
    }
    return result;
}

static int wls_authorize_root(
    const char *home,
    const struct wls_peer *peer,
    const char *project,
    const char *generation_text,
    const char *owner_text,
    const char *alias,
    const char *project_root_hex,
    const char *certificate_root_hex
) {
    char project_root[PATH_MAX];
    char certificate_root[PATH_MAX];
    char record[PATH_MAX * 3U];
    unsigned long generation;
    unsigned long owner;
    struct stat project_status;
    struct stat certificate_status;
    int project_fd = -1;
    int certificate_fd = -1;
    size_t project_length;
    int result = -1;
    if (generation_text == NULL || owner_text == NULL
        || project_root_hex == NULL || certificate_root_hex == NULL
        || (peer->uid != 0U && !(geteuid() != 0 && peer->uid == (unsigned long)geteuid()))
        || !wls_is_uuid(project) || !wls_is_alias(alias)) {
        errno = EPERM;
        return -1;
    }
    if (wls_parse_unsigned(generation_text, 0, &generation) != 0
        || wls_parse_unsigned(owner_text, 1, &owner) != 0
        || (unsigned long)(uid_t)owner != owner) {
        return -1;
    }
    if (wls_hex_decode(project_root_hex, project_root, sizeof(project_root)) != 0
        || wls_hex_decode(certificate_root_hex, certificate_root, sizeof(certificate_root)) != 0) {
        return -1;
    }
    project_length = strlen(project_root);
    if (project_root[0] != '/'
        || certificate_root[0] != '/'
        || strncmp(certificate_root, project_root, project_length) != 0
        || (certificate_root[project_length] != '\0'
            && certificate_root[project_length] != '/')) {
        errno = EPERM;
        return -1;
    }
    project_fd = wls_open_absolute_directory(project_root);
    certificate_fd = wls_open_absolute_directory(certificate_root);
    if (project_fd < 0
        || certificate_fd < 0
        || fstat(project_fd, &project_status) != 0
        || fstat(certificate_fd, &certificate_status) != 0
        || project_status.st_uid != (uid_t)owner
        || certificate_status.st_uid != (uid_t)owner) {
        goto cleanup;
    }
    if (snprintf(
        record,
        sizeof(record),
        "A\t%s\t%lu\t%lu\t%s\t%s\n",
        project,
        generation,
        owner,
        alias,
        certificate_root_hex
    ) >= (int)sizeof(record)
        || wls_registry_append(home, record) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (certificate_fd >= 0) close(certificate_fd);
    if (project_fd >= 0) close(project_fd);
    return result;
}

static int wls_revoke_roots(
    const char *home,
    const struct wls_peer *peer,
    const char *project,
    const char *generation_text
) {
    char record[256];
    unsigned long generation;
    if (generation_text == NULL
        || (peer->uid != 0U && !(geteuid() != 0 && peer->uid == (unsigned long)geteuid()))
        || !wls_is_uuid(project)) {
        errno = EPERM;
        return -1;
    }
    if (wls_parse_unsigned(generation_text, 0, &generation) != 0) return -1;
    if (snprintf(record, sizeof(record), "R\t%s\t%lu\n", project, generation)
        >= (int)sizeof(record)) {
        return -1;
    }
    return wls_registry_append(home, record);
}

static int wls_snapshot_enrolled(
    const char *home,
    const struct wls_peer *peer,
    const struct wls_controller_identity *controller_identity,
    const char *project,
    const char *generation_text,
    const char *alias,
    const char *source_relative_hex,
    const char *digest,
    const char *leaf
) {
    unsigned long generation;
    unsigned long owner = 0U;
    char source_root[PATH_MAX];
    char source_relative[PATH_MAX];
    char destination_root[PATH_MAX];
    char destination_relative[PATH_MAX];
    int require_private;
    if (generation_text == NULL || source_relative_hex == NULL || digest == NULL || leaf == NULL
        || (strcmp(leaf, "source-cert.pem") != 0
        && strcmp(leaf, "source-key.pem") != 0
        && strcmp(leaf, "source-chain.pem") != 0)) {
        errno = EINVAL;
        return -1;
    }
    require_private = strcmp(leaf, "source-key.pem") == 0;
    if (wls_parse_unsigned(generation_text, 0, &generation) != 0
        || !wls_is_uuid(project)
        || !wls_is_alias(alias)
        || !wls_is_hex(digest, 64U)
        || wls_hex_decode(source_relative_hex, source_relative, sizeof(source_relative)) != 0
        || !wls_is_relative_safe(source_relative)
        || wls_registry_lookup(
            home,
            project,
            generation,
            alias,
            &owner,
            source_root,
            sizeof(source_root)
        ) != 0
        || peer->uid != owner
        || snprintf(destination_root, sizeof(destination_root), "%s/snapshots", home)
            >= (int)sizeof(destination_root)
        || snprintf(destination_relative, sizeof(destination_relative), "%s/%s", digest, leaf)
            >= (int)sizeof(destination_relative)) {
        errno = EPERM;
        return -1;
    }
    return wls_snapshot(
        source_root,
        source_relative,
        destination_root,
        destination_relative,
        (uid_t)owner,
        require_private,
        controller_identity != NULL ? controller_identity->uid : (uid_t)-1,
        controller_identity != NULL ? controller_identity->gid : (gid_t)-1
    );
}

static int wls_handle_action(
    char *line,
    const char *channel,
    const struct wls_peer *peer,
    const char *home,
    const struct wls_controller_identity *controller_identity
) {
    char *fields[9];
    size_t field_count = 0U;
    size_t line_length;
    if (home == NULL || line == NULL) {
        errno = EPROTO;
        return -1;
    }
    line_length = strlen(line);
    if (line_length < 2U
        || line[line_length - 1U] != '\n'
        || memchr(line, '\r', line_length) != NULL) {
        errno = EPROTO;
        return -1;
    }
    line[line_length - 1U] = '\0';
    if (wls_split_tsv(line, fields, 9U, &field_count) != 0
        || field_count < 2U
        || strcmp(fields[0], "WLS-ACTION/1") != 0) {
        errno = EPROTO;
        return -1;
    }
    if (field_count == 3U
        && strcmp(fields[1], "STOP") == 0
        && strcmp(channel, "admin") == 0) {
        return wls_write_admin_stopped(home, peer, fields[2]);
    }
    if (field_count == 8U
        && strcmp(fields[1], "AUTH") == 0
        && strcmp(channel, "admin") == 0) {
        (void)wls_authorize_root;
        errno = EPROTONOSUPPORT;
        return -1;
    }
    if (field_count == 4U
        && strcmp(fields[1], "REVOKE") == 0
        && strcmp(channel, "admin") == 0) {
        return wls_revoke_roots(home, peer, fields[2], fields[3]);
    }
    if (field_count == 8U
        && strcmp(fields[1], "SNAP") == 0
        && strcmp(channel, "project") == 0) {
        return wls_snapshot_enrolled(
            home,
            peer,
            controller_identity,
            fields[2],
            fields[3],
            fields[4],
            fields[5],
            fields[6],
            fields[7]
        );
    }
    errno = EPERM;
    return -1;
}

/*
 * WLS-ACTION/2 is deliberately handled by the privileged Broker and never
 * forwarded as an ambient filesystem operation. Implementations append a
 * bounded, root-owned ledger before returning an OK payload. The detailed
 * handler is kept separate from the v1 compatibility parser so an old AUTH
 * cannot accidentally acquire v2 commit semantics.
 */
static int wls_handle_action_v2(
    char *line,
    const char *channel,
    const struct wls_peer *peer,
    const char *home,
    const struct wls_controller_identity *controller_identity,
    char *reply,
    size_t reply_capacity
);

struct wls_security_summary {
    unsigned long allocated;
    unsigned long committed;
    unsigned long assigned;
    unsigned long transaction_expected;
    int reservation_found;
    int committed_found;
    int aborted_found;
    char anchor[65];
    char transaction_anchor[65];
};

static int wls_security_ledger_path(
    char *output,
    size_t capacity,
    const char *home
) {
    int written = snprintf(
        output, capacity, "%s/trust/broker-security-v2.tsv", home
    );
    return written > 0 && written < (int)capacity ? 0 : -1;
}

static int wls_security_value(const char *value)
{
    size_t index;
    if (value == NULL || value[0] == '\0' || strlen(value) > 64U) return 0;
    for (index = 0U; value[index] != '\0'; index++) {
        if (!((value[index] >= 'A' && value[index] <= 'Z')
            || value[index] == '_')) return 0;
    }
    return 1;
}

static int wls_security_open_locked(
    const char *home,
    int *fd,
    char *path,
    size_t path_capacity
) {
    struct stat status;
    if (fd == NULL || wls_security_ledger_path(
            path, path_capacity, home
        ) != 0) return -1;
    *fd = open(
        path, O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW, 0600
    );
    if (*fd < 0
        || flock(*fd, LOCK_EX) != 0
        || fstat(*fd, &status) != 0
        || !S_ISREG(status.st_mode)
        || status.st_nlink != 1
        || status.st_uid != geteuid()
        || (status.st_mode & 0077) != 0
        || status.st_size < 0
        || (uint64_t)status.st_size > WLS_MAX_REGISTRY) {
        if (*fd >= 0) close(*fd);
        *fd = -1;
        errno = EPERM;
        return -1;
    }
    return 0;
}

static int wls_security_read_locked(
    int fd,
    char **contents,
    size_t *length
) {
    struct stat status;
    char *buffer;
    if (contents == NULL || length == NULL || fstat(fd, &status) != 0
        || status.st_size < 0
        || (uint64_t)status.st_size > WLS_MAX_REGISTRY) return -1;
    buffer = calloc((size_t)status.st_size + 1U, 1U);
    if (buffer == NULL) return -1;
    if (status.st_size > 0
        && wls_read_exact(fd, buffer, (size_t)status.st_size) != 0) {
        free(buffer);
        return -1;
    }
    if (status.st_size > 0
        && (buffer[status.st_size - 1] != '\n'
            || memchr(buffer, '\0', (size_t)status.st_size) != NULL
            || memchr(buffer, '\r', (size_t)status.st_size) != NULL)) {
        free(buffer);
        errno = EPROTO;
        return -1;
    }
    *contents = buffer;
    *length = (size_t)status.st_size;
    return 0;
}

static int wls_security_append_locked(
    int fd,
    const char *path,
    const char *record
) {
    off_t before;
    size_t length;
    if (record == NULL || path == NULL) return -1;
    length = strlen(record);
    before = lseek(fd, 0, SEEK_END);
    if (before < 0 || length < 2U
        || (uint64_t)before + length > WLS_MAX_REGISTRY
        || record[length - 1U] != '\n'
        || memchr(record, '\n', length - 1U) != NULL
        || wls_write_all(fd, record, length) != 0
        || fsync(fd) != 0
        || wls_fsync_parent(path) != 0) {
        if (before >= 0) {
            (void)ftruncate(fd, before);
            (void)fsync(fd);
        }
        return -1;
    }
    return 0;
}

static void wls_sha256_hex(
    const unsigned char *contents,
    size_t length,
    char digest[65]
) {
    unsigned char raw[crypto_hash_sha256_BYTES];
    (void)crypto_hash_sha256(raw, contents, (unsigned long long)length);
    (void)sodium_bin2hex(digest, 65U, raw, sizeof(raw));
    sodium_memzero(raw, sizeof(raw));
}

static int wls_security_summary(
    char *contents,
    const char *tx,
    const char *intent,
    const char *kind,
    struct wls_security_summary *summary
) {
    char *cursor = contents;
    memset(summary, 0, sizeof(*summary));
    memset(summary->anchor, '0', 64U);
    summary->anchor[64] = '\0';
    memset(summary->transaction_anchor, '0', 64U);
    summary->transaction_anchor[64] = '\0';
    while (cursor != NULL && cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t count = 0U;
        unsigned long assigned = 0U;
        if (newline == NULL) return -1;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) return -1;
        if (strcmp(fields[0], "H") == 0) {
            unsigned long expected = 0U;
            if (count != 6U || !wls_is_hex(fields[1], 32U)
                || !wls_is_hex(fields[2], 64U)
                || !wls_security_value(fields[3])
                || wls_parse_unsigned(fields[4], 1, &expected) != 0
                || wls_parse_unsigned(fields[5], 0, &assigned) != 0
                || assigned <= expected || assigned <= summary->allocated) {
                return -1;
            }
            summary->allocated = assigned;
            if (strcmp(fields[1], tx) == 0) {
                if (strcmp(fields[2], intent) != 0
                    || strcmp(fields[3], kind) != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return -1;
                summary->reservation_found = 1;
                summary->assigned = assigned;
                summary->transaction_expected = expected;
            }
        } else if (strcmp(fields[0], "K") == 0) {
            if (count != 6U || !wls_is_hex(fields[1], 32U)
                || !wls_is_hex(fields[2], 64U)
                || !wls_security_value(fields[3])
                || wls_parse_unsigned(fields[4], 0, &assigned) != 0
                || !wls_is_hex(fields[5], 64U)
                || assigned > summary->allocated
                || assigned <= summary->committed) return -1;
            summary->committed = assigned;
            memcpy(summary->anchor, fields[5], 65U);
            if (strcmp(fields[1], tx) == 0) {
                if (strcmp(fields[2], intent) != 0
                    || strcmp(fields[3], kind) != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return -1;
                summary->committed_found = 1;
                memcpy(summary->transaction_anchor, fields[5], 65U);
            }
        } else if (strcmp(fields[0], "X") == 0) {
            if (count != 5U || !wls_is_hex(fields[1], 32U)
                || !wls_is_hex(fields[2], 64U)
                || !wls_security_value(fields[3])
                || wls_parse_unsigned(fields[4], 0, &assigned) != 0
                || assigned > summary->allocated) return -1;
            if (strcmp(fields[1], tx) == 0) {
                if (strcmp(fields[2], intent) != 0
                    || strcmp(fields[3], kind) != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return -1;
                summary->aborted_found = 1;
            }
        } else if (strcmp(fields[0], "C") == 0) {
            unsigned long root_count = 0U;
            if (count != 7U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_unsigned(fields[4], 0, &assigned) != 0
                || wls_parse_unsigned(fields[5], 0, &root_count) != 0
                || root_count > 64U
                || !wls_is_hex(fields[6], 64U)
                || assigned > summary->allocated
                || assigned <= summary->committed) return -1;
            summary->committed = assigned;
            memcpy(summary->anchor, fields[6], 65U);
            if (strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[3], intent) != 0
                    || strcmp(kind, "AUTH") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return -1;
                summary->committed_found = 1;
                memcpy(summary->transaction_anchor, fields[6], 65U);
            }
        } else if (strcmp(fields[0], "Q") == 0) {
            if (count != 5U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_unsigned(fields[4], 0, &assigned) != 0
                || assigned > summary->allocated) return -1;
            if (strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[3], intent) != 0
                    || strcmp(kind, "AUTH") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return -1;
                summary->aborted_found = 1;
            }
        } else if (strcmp(fields[0], "P") == 0) {
            unsigned long owner = 0U;
            unsigned long expected = 0U;
            size_t project_root_length;
            size_t certificate_root_length;
            if (count != 13U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_unsigned(fields[4], 0, &assigned) != 0
                || wls_parse_unsigned(fields[5], 1, &owner) != 0
                || assigned > summary->allocated
                || (unsigned long)(uid_t)owner != owner
                || !wls_is_alias(fields[6])
                || (project_root_length = strlen(fields[7])) < 2U
                || (certificate_root_length = strlen(fields[8])) < 2U
                || (project_root_length & 1U) != 0U
                || (certificate_root_length & 1U) != 0U
                || project_root_length >= PATH_MAX * 2U + 1U
                || certificate_root_length >= PATH_MAX * 2U + 1U
                || !wls_is_hex(fields[7], project_root_length)
                || !wls_is_hex(fields[8], certificate_root_length)
                || wls_parse_unsigned(fields[9], 0, &expected) != 0
                || expected > 64U
                || strlen(fields[10]) > 95U || strlen(fields[11]) > 95U
                || !wls_is_hex(fields[12], 64U)) return -1;
        } else if (strcmp(fields[0], "R") == 0) {
            unsigned long owner = 0U;
            unsigned long expected = 0U;
            unsigned long root_count = 0U;
            if (count != 10U || !wls_is_uuid(fields[1])
                || !wls_is_uuid(fields[2]) || strcmp(fields[1], fields[2]) == 0
                || !wls_is_hex(fields[3], 32U) || !wls_is_hex(fields[4], 64U)
                || wls_parse_unsigned(fields[5], 0, &assigned) != 0
                || wls_parse_unsigned(fields[6], 1, &owner) != 0
                || (unsigned long)(uid_t)owner != owner
                || wls_parse_unsigned(fields[7], 1, &expected) != 0
                || wls_parse_unsigned(fields[8], 0, &root_count) != 0
                || root_count == 0U || root_count > 64U
                || !wls_is_hex(fields[9], 64U)
                || assigned > summary->allocated || assigned <= expected) return -1;
            if (strcmp(fields[3], tx) == 0) {
                if (strcmp(fields[4], intent) != 0
                    || strcmp(kind, "AUTH_TRANSFER") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return -1;
            }
        } else if (strcmp(fields[0], "T") == 0) {
            unsigned long owner = 0U;
            unsigned long root_count = 0U;
            char canonical[1024];
            char calculated[65];
            int canonical_length;
            if (count != 11U || !wls_is_uuid(fields[1])
                || !wls_is_uuid(fields[2]) || strcmp(fields[1], fields[2]) == 0
                || !wls_is_hex(fields[3], 32U) || !wls_is_hex(fields[4], 64U)
                || wls_parse_unsigned(fields[5], 0, &assigned) != 0
                || wls_parse_unsigned(fields[6], 1, &owner) != 0
                || (unsigned long)(uid_t)owner != owner
                || wls_parse_unsigned(fields[7], 0, &root_count) != 0
                || root_count == 0U || root_count > 64U
                || !wls_is_hex(fields[8], 64U)
                || !wls_is_hex(fields[9], 64U)
                || !wls_is_hex(fields[10], 64U)
                || assigned > summary->allocated || assigned <= summary->committed
                || strcmp(fields[9], summary->anchor) != 0) return -1;
            canonical_length = snprintf(
                canonical, sizeof(canonical),
                "%s\n%s\n%s\n%s\n%lu\n%lu\n%lu\n%s\n%s\n",
                fields[1], fields[2], fields[3], fields[4], assigned,
                owner, root_count, fields[8], fields[9]
            );
            if (canonical_length <= 0
                || canonical_length >= (int)sizeof(canonical)) return -1;
            wls_sha256_hex(
                (const unsigned char *)canonical,
                (size_t)canonical_length,
                calculated
            );
            if (strcmp(calculated, fields[10]) != 0) return -1;
            summary->committed = assigned;
            memcpy(summary->anchor, fields[10], 65U);
            if (strcmp(fields[3], tx) == 0) {
                if (strcmp(fields[4], intent) != 0
                    || strcmp(kind, "AUTH_TRANSFER") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return -1;
                summary->committed_found = 1;
                memcpy(summary->transaction_anchor, fields[10], 65U);
            }
        } else if (strcmp(fields[0], "Y") == 0) {
            if (count != 6U || !wls_is_uuid(fields[1])
                || !wls_is_uuid(fields[2]) || strcmp(fields[1], fields[2]) == 0
                || !wls_is_hex(fields[3], 32U) || !wls_is_hex(fields[4], 64U)
                || wls_parse_unsigned(fields[5], 0, &assigned) != 0
                || assigned > summary->allocated) return -1;
            if (strcmp(fields[3], tx) == 0) {
                if (strcmp(fields[4], intent) != 0
                    || strcmp(kind, "AUTH_TRANSFER") != 0
                    || (summary->reservation_found
                        && summary->assigned != assigned)) return -1;
                summary->aborted_found = 1;
            }
        } else {
            return -1;
        }
        cursor = newline + 1U;
    }
    if ((summary->committed_found || summary->aborted_found)
        && !summary->reservation_found) return -1;
    return 0;
}

static int wls_security_reply_error(
    char *reply,
    size_t capacity,
    const char *code,
    const char *opcode,
    const char *tx,
    const char *intent
) {
    int length = snprintf(
        reply, capacity, "WLS-ACTION/2\tERR\t%s\t%s\t%s\t%s\n",
        code, opcode, tx != NULL ? tx : "-", intent != NULL ? intent : "-"
    );
    return length > 0 && length < (int)capacity ? 0 : -1;
}

static int wls_security_operation(
    const char *home,
    const char *opcode,
    const char *tx,
    const char *intent,
    const char *kind,
    const char *generation_text,
    const char *anchor,
    char *reply,
    size_t reply_capacity
) {
    char path[PATH_MAX];
    char record[512];
    char digest[65];
    char *contents = NULL;
    size_t length = 0U;
    int fd = -1;
    unsigned long requested = 0U;
    struct wls_security_summary summary;
    int result = -1;
    if (!wls_is_hex(tx, 32U) || !wls_is_hex(intent, 64U)
        || !wls_security_value(kind)
        || wls_parse_unsigned(generation_text, 1, &requested) != 0
        || (strcmp(opcode, "SECURITY_COMMIT") == 0
            && !wls_is_hex(anchor, 64U))) {
        return wls_security_reply_error(
            reply, reply_capacity, "INVALID", opcode, tx, intent
        );
    }
    if (wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0
        || wls_security_summary(contents, tx, intent, kind, &summary) != 0) {
        (void)wls_security_reply_error(
            reply, reply_capacity, "LEDGER_INVALID", opcode, tx, intent
        );
        goto cleanup;
    }
    if (strcmp(opcode, "SECURITY_RESERVE") == 0) {
        if (!summary.reservation_found) {
            if (requested != summary.committed || summary.allocated == ULONG_MAX
                || snprintf(record, sizeof(record),
                    "H\t%s\t%s\t%s\t%lu\t%lu\n",
                    tx, intent, kind, requested, summary.allocated + 1U
                ) >= (int)sizeof(record)
                || wls_security_append_locked(fd, path, record) != 0) {
                (void)wls_security_reply_error(
                    reply, reply_capacity, "STALE_HIGH_WATER", opcode, tx, intent
                );
                goto cleanup;
            }
            summary.assigned = ++summary.allocated;
            summary.reservation_found = 1;
        } else if (requested >= summary.assigned) {
            (void)wls_security_reply_error(
                reply, reply_capacity, "BINDING_CONFLICT", opcode, tx, intent
            );
            goto cleanup;
        } else if (requested != summary.transaction_expected) {
            (void)wls_security_reply_error(
                reply, reply_capacity, "BINDING_CONFLICT", opcode, tx, intent
            );
            goto cleanup;
        }
    } else {
        if (!summary.reservation_found || summary.assigned != requested) {
            (void)wls_security_reply_error(
                reply, reply_capacity, "BINDING_CONFLICT", opcode, tx, intent
            );
            goto cleanup;
        }
        if (strcmp(opcode, "SECURITY_COMMIT") == 0) {
            if (summary.committed_found) {
                if (strcmp(summary.transaction_anchor, anchor) != 0) goto cleanup;
            } else if (summary.aborted_found
                || summary.assigned <= summary.committed
                || snprintf(record, sizeof(record),
                    "K\t%s\t%s\t%s\t%lu\t%s\n",
                    tx, intent, kind, summary.assigned, anchor
                ) >= (int)sizeof(record)
                || wls_security_append_locked(fd, path, record) != 0) {
                (void)wls_security_reply_error(
                    reply, reply_capacity, "COMMIT_CONFLICT", opcode, tx, intent
                );
                goto cleanup;
            }
            summary.committed = summary.assigned;
            memcpy(summary.anchor, anchor, 65U);
        } else if (strcmp(opcode, "SECURITY_ABORT") == 0) {
            if (summary.committed_found) goto cleanup;
            if (!summary.aborted_found && (snprintf(record, sizeof(record),
                    "X\t%s\t%s\t%s\t%lu\n",
                    tx, intent, kind, summary.assigned
                ) >= (int)sizeof(record)
                || wls_security_append_locked(fd, path, record) != 0)) goto cleanup;
        } else {
            goto cleanup;
        }
    }
    free(contents);
    contents = NULL;
    if (lseek(fd, 0, SEEK_SET) < 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto cleanup;
    wls_sha256_hex((const unsigned char *)contents, length, digest);
    {
        int written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\t%s\t%s\t%s\t%lu\t%lu\t%lu\t%s\t%s\n",
        opcode, tx, intent, summary.assigned, summary.allocated,
        summary.committed, digest, summary.anchor
        );
        if (written <= 0 || written >= (int)reply_capacity) goto cleanup;
    }
    result = 0;
cleanup:
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    if (fd >= 0) {
        (void)flock(fd, LOCK_UN);
        close(fd);
    }
    return result;
}

static int wls_security_attest(
    const char *home,
    const char *host_id,
    const char *minimum_text,
    const char *expected_ledger,
    const char *expected_anchor,
    char *reply,
    size_t reply_capacity
) {
    char path[PATH_MAX];
    char digest[65];
    char zero_tx[33];
    char zero_intent[65];
    char *contents = NULL;
    char *summary_copy = NULL;
    size_t length = 0U;
    unsigned long minimum = 0U;
    int fd = -1;
    int written;
    struct wls_security_summary summary;
    memset(zero_tx, '0', 32U); zero_tx[32] = '\0';
    memset(zero_intent, '0', 64U); zero_intent[64] = '\0';
    if (!(wls_is_hex(host_id, 32U) || wls_is_hex(host_id, 64U))
        || wls_parse_unsigned(minimum_text, 1, &minimum) != 0
        || !(strcmp(expected_ledger, "-") == 0
            || wls_is_hex(expected_ledger, 64U))
        || !(strcmp(expected_anchor, "-") == 0
            || wls_is_hex(expected_anchor, 64U))) {
        return wls_security_reply_error(
            reply, reply_capacity, "INVALID", "SECURITY_ATTEST", NULL, NULL
        );
    }
    if (wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0) {
        (void)wls_security_reply_error(
            reply, reply_capacity, "LEDGER_INVALID", "SECURITY_ATTEST", NULL, NULL
        );
        goto fail;
    }
    summary_copy = malloc(length + 1U);
    if (summary_copy == NULL) goto fail;
    memcpy(summary_copy, contents, length + 1U);
    if (wls_security_summary(
            summary_copy, zero_tx, zero_intent, "ATTEST", &summary
        ) != 0) goto fail;
    wls_sha256_hex((const unsigned char *)contents, length, digest);
    if (minimum > summary.committed
        || (strcmp(expected_ledger, "-") != 0
            && strcmp(expected_ledger, digest) != 0)
        || (strcmp(expected_anchor, "-") != 0
            && strcmp(expected_anchor, summary.anchor) != 0)) {
        (void)wls_security_reply_error(
            reply, reply_capacity, "ATTESTATION_MISMATCH",
            "SECURITY_ATTEST", NULL, NULL
        );
        goto fail;
    }
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tSECURITY_ATTEST\t%s\t%lu\t%lu\t%s\t%s\n",
        host_id, summary.allocated, summary.committed, digest, summary.anchor
    );
    if (written <= 0 || written >= (int)reply_capacity) goto fail;
    sodium_memzero(contents, length);
    free(contents);
    sodium_memzero(summary_copy, length);
    free(summary_copy);
    (void)flock(fd, LOCK_UN);
    close(fd);
    return 0;
fail:
    if (contents != NULL) {
        sodium_memzero(contents, length);
        free(contents);
    }
    if (summary_copy != NULL) {
        sodium_memzero(summary_copy, length);
        free(summary_copy);
    }
    if (fd >= 0) {
        (void)flock(fd, LOCK_UN);
        close(fd);
    }
    return -1;
}

#define WLS_MAX_AUTH_ROOTS 64U

struct wls_auth_root_v2 {
    char project[37];
    char tx[33];
    char intent[65];
    unsigned long assigned;
    unsigned long owner;
    char alias[65];
    char project_root_hex[PATH_MAX * 2U + 1U];
    char certificate_root_hex[PATH_MAX * 2U + 1U];
    unsigned long expected_count;
    char project_object[96];
    char certificate_object[96];
    char attestation[65];
};

static int wls_posix_object_id(
    const struct stat *status,
    char *output,
    size_t capacity
) {
    int written = snprintf(
        output, capacity, "%llx-%llx",
        (unsigned long long)status->st_dev,
        (unsigned long long)status->st_ino
    );
    return written > 0 && written < (int)capacity ? 0 : -1;
}

static int wls_auth_attestation(
    const char *project,
    const char *tx,
    const char *intent,
    unsigned long assigned,
    unsigned long owner,
    const char *alias,
    const char *project_object,
    const char *certificate_object,
    char output[65]
) {
    char canonical[1024];
    int length = snprintf(
        canonical, sizeof(canonical),
        "%s\n%s\n%s\n%lu\n%lu\n%s\n%s\n%s\n",
        project, tx, intent, assigned, owner, alias,
        project_object, certificate_object
    );
    if (length <= 0 || length >= (int)sizeof(canonical)) return -1;
    wls_sha256_hex((const unsigned char *)canonical, (size_t)length, output);
    sodium_memzero(canonical, sizeof(canonical));
    return 0;
}

static int wls_auth_root_validate(
    const char *project_root_hex,
    const char *certificate_root_hex,
    unsigned long owner,
    char project_object[96],
    char certificate_object[96]
) {
    char project_root[PATH_MAX];
    char certificate_root[PATH_MAX];
    size_t project_length;
    struct stat project_status;
    struct stat certificate_status;
    int project_fd = -1;
    int certificate_fd = -1;
    int result = -1;
    if (wls_hex_decode(project_root_hex, project_root, sizeof(project_root)) != 0
        || wls_hex_decode(
            certificate_root_hex, certificate_root, sizeof(certificate_root)
        ) != 0) return -1;
    project_length = strlen(project_root);
    if (project_root[0] != '/' || certificate_root[0] != '/'
        || strncmp(certificate_root, project_root, project_length) != 0
        || (certificate_root[project_length] != '\0'
            && certificate_root[project_length] != '/')) {
        errno = EPERM;
        return -1;
    }
    project_fd = wls_open_absolute_directory(project_root);
    certificate_fd = wls_open_absolute_directory(certificate_root);
    if (project_fd < 0 || certificate_fd < 0
        || fstat(project_fd, &project_status) != 0
        || fstat(certificate_fd, &certificate_status) != 0
        || project_status.st_uid != (uid_t)owner
        || certificate_status.st_uid != (uid_t)owner
        || wls_posix_object_id(
            &project_status, project_object, 96U
        ) != 0
        || wls_posix_object_id(
            &certificate_status, certificate_object, 96U
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (certificate_fd >= 0) close(certificate_fd);
    if (project_fd >= 0) close(project_fd);
    return result;
}

static int wls_auth_parse_root(
    char **fields,
    size_t count,
    struct wls_auth_root_v2 *root
) {
    if (fields == NULL || root == NULL) return -1;
    /* Duplicate-ledger comparison covers the whole struct. Deterministically
     * initialize ABI padding so identical records cannot disagree on
     * indeterminate stack bytes. */
    memset(root, 0, sizeof(*root));
    if (count != 13U || strcmp(fields[0], "P") != 0
        || !wls_is_uuid(fields[1]) || !wls_is_hex(fields[2], 32U)
        || !wls_is_hex(fields[3], 64U)
        || wls_parse_unsigned(fields[4], 0, &root->assigned) != 0
        || wls_parse_unsigned(fields[5], 1, &root->owner) != 0
        || !wls_is_alias(fields[6])
        || strlen(fields[7]) < 2U || strlen(fields[8]) < 2U
        || !wls_is_hex(fields[7], strlen(fields[7]))
        || !wls_is_hex(fields[8], strlen(fields[8]))
        || (strlen(fields[7]) & 1U) != 0U
        || (strlen(fields[8]) & 1U) != 0U
        || strlen(fields[7]) >= sizeof(root->project_root_hex)
        || strlen(fields[8]) >= sizeof(root->certificate_root_hex)
        || wls_parse_unsigned(fields[9], 0, &root->expected_count) != 0
        || root->expected_count > WLS_MAX_AUTH_ROOTS
        || strlen(fields[10]) >= sizeof(root->project_object)
        || strlen(fields[11]) >= sizeof(root->certificate_object)
        || !wls_is_hex(fields[12], 64U)) return -1;
    strcpy(root->project, fields[1]);
    strcpy(root->tx, fields[2]);
    strcpy(root->intent, fields[3]);
    strcpy(root->alias, fields[6]);
    strcpy(root->project_root_hex, fields[7]);
    strcpy(root->certificate_root_hex, fields[8]);
    strcpy(root->project_object, fields[10]);
    strcpy(root->certificate_object, fields[11]);
    strcpy(root->attestation, fields[12]);
    return 0;
}

static int wls_auth_prepare_v2(
    const char *home,
    const char *project,
    const char *tx,
    const char *intent,
    const char *owner_text,
    const char *alias,
    const char *project_root_hex,
    const char *certificate_root_hex,
    const char *expected_count_text,
    const char *expected_previous,
    char *reply,
    size_t reply_capacity
) {
    unsigned long owner = 0U;
    unsigned long expected_count = 0U;
    unsigned long assigned = 0U;
    char reserve_reply[1024];
    char project_object[96];
    char certificate_object[96];
    char attestation[65];
    char path[PATH_MAX];
    char record[PATH_MAX * 5U];
    char *contents = NULL;
    size_t length = 0U;
    int fd = -1;
    int written;
    int duplicate = 0;
    char *cursor;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U) || !wls_is_alias(alias)
        || wls_parse_unsigned(owner_text, 1, &owner) != 0
        || (unsigned long)(uid_t)owner != owner
        || wls_parse_unsigned(expected_count_text, 0, &expected_count) != 0
        || expected_count > WLS_MAX_AUTH_ROOTS
        || wls_auth_root_validate(
            project_root_hex, certificate_root_hex, owner,
            project_object, certificate_object
        ) != 0) {
        return wls_security_reply_error(
            reply, reply_capacity, "INVALID_ROOT", "AUTH_PREPARE", tx, intent
        );
    }
    if (wls_security_operation(
            home, "SECURITY_RESERVE", tx, intent, "AUTH",
            expected_previous, NULL, reserve_reply, sizeof(reserve_reply)
        ) != 0
        || sscanf(
            reserve_reply,
            "WLS-ACTION/2\tOK\tSECURITY_RESERVE\t%*32s\t%*64s\t%lu",
            &assigned
        ) != 1
        || wls_auth_attestation(
            project, tx, intent, assigned, owner, alias,
            project_object, certificate_object, attestation
        ) != 0
        || wls_security_open_locked(home, &fd, path, sizeof(path)) != 0) {
        return wls_security_reply_error(
            reply, reply_capacity, "LEDGER_INVALID", "AUTH_PREPARE", tx, intent
        );
    }
    if (wls_security_read_locked(fd, &contents, &length) != 0) {
        (void)flock(fd, LOCK_UN);
        close(fd);
        return wls_security_reply_error(
            reply, reply_capacity, "LEDGER_INVALID", "AUTH_PREPARE", tx, intent
        );
    }
    cursor = contents;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *parts[16];
        size_t part_count = 0U;
        struct wls_auth_root_v2 existing;
        if (newline == NULL) goto fail;
        *newline = '\0';
        if (wls_split_tsv(cursor, parts, 16U, &part_count) != 0) goto fail;
        if (strcmp(parts[0], "P") == 0) {
            if (wls_auth_parse_root(parts, part_count, &existing) != 0) goto fail;
            if (strcmp(existing.tx, tx) == 0
                && strcmp(existing.alias, alias) == 0) {
                if (strcmp(existing.project, project) != 0
                    || strcmp(existing.intent, intent) != 0
                    || existing.assigned != assigned
                    || existing.owner != owner
                    || existing.expected_count != expected_count
                    || strcmp(existing.project_root_hex, project_root_hex) != 0
                    || strcmp(existing.certificate_root_hex, certificate_root_hex) != 0
                    || strcmp(existing.project_object, project_object) != 0
                    || strcmp(existing.certificate_object, certificate_object) != 0
                    || strcmp(existing.attestation, attestation) != 0) goto fail;
                duplicate = 1;
            }
        }
        cursor = newline + 1U;
    }
    if (!duplicate) {
        written = snprintf(
            record, sizeof(record),
            "P\t%s\t%s\t%s\t%lu\t%lu\t%s\t%s\t%s\t%lu\t%s\t%s\t%s\n",
            project, tx, intent, assigned, owner, alias,
            project_root_hex, certificate_root_hex, expected_count,
            project_object, certificate_object, attestation
        );
        if (written <= 0 || written >= (int)sizeof(record)
            || wls_security_append_locked(fd, path, record) != 0) goto fail;
    }
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tAUTH_PREPARE\t%s\t%s\t%lu\t%s\t%s\t%s\n",
        tx, intent, assigned, project_object, certificate_object, attestation
    );
    if (written <= 0 || written >= (int)reply_capacity) goto fail;
    sodium_memzero(contents, length); free(contents);
    (void)flock(fd, LOCK_UN); close(fd);
    return 0;
fail:
    if (contents != NULL) { sodium_memzero(contents, length); free(contents); }
    if (fd >= 0) { (void)flock(fd, LOCK_UN); close(fd); }
    return wls_security_reply_error(
        reply, reply_capacity, "BINDING_CONFLICT", "AUTH_PREPARE", tx, intent
    );
}

static int wls_auth_alias_compare(const void *left, const void *right)
{
    const struct wls_auth_root_v2 *a = left;
    const struct wls_auth_root_v2 *b = right;
    return strcmp(a->alias, b->alias);
}

static int wls_auth_collect(
    char *contents,
    const char *project,
    const char *tx,
    const char *intent,
    struct wls_auth_root_v2 *roots,
    size_t root_capacity,
    size_t *root_count,
    int *committed,
    int *aborted,
    char committed_digest[65]
) {
    char *cursor = contents;
    size_t count = 0U;
    *committed = 0;
    *aborted = 0;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t field_count = 0U;
        if (newline == NULL) return -1;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &field_count) != 0) return -1;
        if (strcmp(fields[0], "P") == 0) {
            struct wls_auth_root_v2 parsed;
            size_t index;
            if (wls_auth_parse_root(fields, field_count, &parsed) != 0) return -1;
            if (strcmp(parsed.project, project) == 0
                && strcmp(parsed.tx, tx) == 0
                && strcmp(parsed.intent, intent) == 0) {
                if (count >= root_capacity) return -1;
                for (index = 0U; index < count; index++) {
                    if (strcmp(roots[index].alias, parsed.alias) == 0) {
                        if (memcmp(&roots[index], &parsed, sizeof(parsed)) != 0) return -1;
                        break;
                    }
                }
                if (index == count) roots[count++] = parsed;
            }
        } else if (strcmp(fields[0], "C") == 0) {
            unsigned long assigned = 0U;
            unsigned long expected = 0U;
            if (field_count != 7U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_unsigned(fields[4], 0, &assigned) != 0
                || wls_parse_unsigned(fields[5], 0, &expected) != 0
                || !wls_is_hex(fields[6], 64U)) return -1;
            if (assigned == 0U || expected == 0U) return -1;
            if (strcmp(fields[1], project) == 0 && strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[3], intent) != 0 || *committed) return -1;
                *committed = 1;
                memcpy(committed_digest, fields[6], 65U);
            }
        } else if (strcmp(fields[0], "Q") == 0) {
            unsigned long assigned = 0U;
            if (field_count != 5U || !wls_is_uuid(fields[1])
                || !wls_is_hex(fields[2], 32U)
                || !wls_is_hex(fields[3], 64U)
                || wls_parse_unsigned(fields[4], 0, &assigned) != 0) return -1;
            if (strcmp(fields[1], project) == 0 && strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[3], intent) != 0 || *aborted) return -1;
                *aborted = 1;
            }
        } else if (strcmp(fields[0], "T") == 0) {
            unsigned long assigned = 0U;
            unsigned long root_count = 0U;
            if (field_count != 11U || !wls_is_uuid(fields[1])
                || !wls_is_uuid(fields[2]) || !wls_is_hex(fields[3], 32U)
                || !wls_is_hex(fields[4], 64U)
                || wls_parse_unsigned(fields[5], 0, &assigned) != 0
                || wls_parse_unsigned(fields[7], 0, &root_count) != 0
                || !wls_is_hex(fields[8], 64U)
                || !wls_is_hex(fields[10], 64U)) return -1;
            if (strcmp(fields[1], project) == 0) *aborted = 1;
            if (strcmp(fields[2], project) == 0
                && strcmp(fields[3], tx) == 0) {
                if (strcmp(fields[4], intent) != 0 || *committed) return -1;
                *committed = 1;
                memcpy(committed_digest, fields[8], 65U);
            }
        }
        cursor = newline + 1U;
    }
    *root_count = count;
    return 0;
}

static int wls_auth_commit_v2(
    const char *home,
    const char *project,
    const char *tx,
    const char *intent,
    const char *expected_count_text,
    const char *expected_roots_digest,
    char *reply,
    size_t reply_capacity
) {
    struct wls_auth_root_v2 roots[WLS_MAX_AUTH_ROOTS];
    struct wls_security_summary summary;
    unsigned long expected_count = 0U;
    size_t root_count = 0U;
    size_t index;
    char *canonical = NULL;
    size_t canonical_capacity;
    size_t canonical_length = 0U;
    char roots_digest[65];
    char ledger_digest[65];
    char committed_digest[65] = {0};
    char path[PATH_MAX];
    char record[512];
    char *contents = NULL;
    size_t length = 0U;
    int committed = 0;
    int aborted = 0;
    int fd = -1;
    int written;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U)
        || wls_parse_unsigned(expected_count_text, 0, &expected_count) != 0
        || expected_count > WLS_MAX_AUTH_ROOTS
        || !wls_is_hex(expected_roots_digest, 64U)
        || wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0) {
        goto invalid;
    }
    {
        char *summary_copy = malloc(length + 1U);
        if (summary_copy == NULL) goto invalid;
        memcpy(summary_copy, contents, length + 1U);
        if (wls_security_summary(
                summary_copy, tx, intent, "AUTH", &summary
            ) != 0) {
            free(summary_copy);
            goto invalid;
        }
        free(summary_copy);
    }
    if (!summary.reservation_found || summary.aborted_found
        || wls_auth_collect(
            contents, project, tx, intent, roots, WLS_MAX_AUTH_ROOTS,
            &root_count, &committed, &aborted, committed_digest
        ) != 0
        || aborted || root_count != (size_t)expected_count || root_count == 0U) {
        goto conflict;
    }
    qsort(roots, root_count, sizeof(roots[0]), wls_auth_alias_compare);
    canonical_capacity = root_count * 512U + 1U;
    canonical = calloc(canonical_capacity, 1U);
    if (canonical == NULL) goto invalid;
    for (index = 0U; index < root_count; index++) {
        char project_object[96];
        char certificate_object[96];
        char attestation[65];
        int amount;
        if (roots[index].assigned != summary.assigned
            || roots[index].expected_count != expected_count
            || (index > 0U
                && strcmp(roots[index - 1U].alias, roots[index].alias) >= 0)
            || wls_auth_root_validate(
                roots[index].project_root_hex,
                roots[index].certificate_root_hex,
                roots[index].owner,
                project_object,
                certificate_object
            ) != 0
            || strcmp(project_object, roots[index].project_object) != 0
            || strcmp(certificate_object, roots[index].certificate_object) != 0
            || wls_auth_attestation(
                project, tx, intent, summary.assigned, roots[index].owner,
                roots[index].alias, project_object, certificate_object,
                attestation
            ) != 0
            || strcmp(attestation, roots[index].attestation) != 0) goto conflict;
        amount = snprintf(
            canonical + canonical_length,
            canonical_capacity - canonical_length,
            "%s\t%lu\t%s\t%s\t%s\n",
            roots[index].alias, summary.assigned, project_object,
            certificate_object, attestation
        );
        if (amount <= 0
            || (size_t)amount >= canonical_capacity - canonical_length) goto invalid;
        canonical_length += (size_t)amount;
    }
    wls_sha256_hex(
        (const unsigned char *)canonical, canonical_length, roots_digest
    );
    if (strcmp(roots_digest, expected_roots_digest) != 0) goto conflict;
    if (!committed) {
        if (summary.assigned <= summary.committed
            || snprintf(
                record, sizeof(record), "C\t%s\t%s\t%s\t%lu\t%lu\t%s\n",
                project, tx, intent, summary.assigned, expected_count, roots_digest
            ) >= (int)sizeof(record)
            || wls_security_append_locked(fd, path, record) != 0) goto conflict;
    } else if (strcmp(committed_digest, roots_digest) != 0) {
        goto conflict;
    }
    free(contents); contents = NULL;
    if (lseek(fd, 0, SEEK_SET) < 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto invalid;
    wls_sha256_hex((const unsigned char *)contents, length, ledger_digest);
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tAUTH_COMMIT\t%s\t%s\t%lu\t%lu\t%s\t%s\n",
        tx, intent, summary.assigned, expected_count, roots_digest, ledger_digest
    );
    if (written <= 0 || written >= (int)reply_capacity) goto invalid;
    sodium_memzero(canonical, canonical_capacity); free(canonical);
    sodium_memzero(contents, length); free(contents);
    (void)flock(fd, LOCK_UN); close(fd);
    return 0;
conflict:
    (void)wls_security_reply_error(
        reply, reply_capacity, "ROOT_SET_MISMATCH", "AUTH_COMMIT", tx, intent
    );
    goto fail;
invalid:
    (void)wls_security_reply_error(
        reply, reply_capacity, "LEDGER_INVALID", "AUTH_COMMIT", tx, intent
    );
fail:
    if (canonical != NULL) { sodium_memzero(canonical, canonical_capacity); free(canonical); }
    if (contents != NULL) { sodium_memzero(contents, length); free(contents); }
    if (fd >= 0) { (void)flock(fd, LOCK_UN); close(fd); }
    return -1;
}

static int wls_auth_transfer_target(
    const char *contents,
    size_t length,
    const char *project,
    const char *tx,
    const char *intent
);

static int wls_auth_load_committed_root(
    const char *home,
    const char *project,
    const char *tx,
    const char *intent,
    const char *alias,
    struct wls_auth_root_v2 *selected,
    unsigned long *allocated,
    char ledger_digest[65]
) {
    struct wls_auth_root_v2 roots[WLS_MAX_AUTH_ROOTS];
    struct wls_security_summary summary;
    char path[PATH_MAX];
    char *contents = NULL;
    char *summary_copy = NULL;
    size_t length = 0U;
    size_t count = 0U;
    size_t index;
    int committed = 0;
    int aborted = 0;
    int fd = -1;
    char committed_digest[65] = {0};
    int transfer_target = 0;
    int result = -1;
    if (wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto cleanup;
    summary_copy = malloc(length + 1U);
    if (summary_copy == NULL) goto cleanup;
    memcpy(summary_copy, contents, length + 1U);
    transfer_target = wls_auth_transfer_target(
        contents, length, project, tx, intent
    );
    if (transfer_target < 0
        || wls_security_summary(
            summary_copy, tx, intent,
            transfer_target ? "AUTH_TRANSFER" : "AUTH", &summary
        ) != 0
        || wls_auth_collect(
            contents, project, tx, intent, roots, WLS_MAX_AUTH_ROOTS,
            &count, &committed, &aborted, committed_digest
        ) != 0
        || !committed || aborted || !summary.committed_found) goto cleanup;
    for (index = 0U; index < count; index++) {
        if (strcmp(roots[index].alias, alias) == 0) {
            char project_object[96];
            char certificate_object[96];
            char attestation[65];
            if (wls_auth_root_validate(
                    roots[index].project_root_hex,
                    roots[index].certificate_root_hex,
                    roots[index].owner,
                    project_object,
                    certificate_object
                ) != 0
                || strcmp(project_object, roots[index].project_object) != 0
                || strcmp(certificate_object, roots[index].certificate_object) != 0
                || wls_auth_attestation(
                    project, tx, intent, roots[index].assigned,
                    roots[index].owner, alias, project_object,
                    certificate_object, attestation
                ) != 0
                || strcmp(attestation, roots[index].attestation) != 0) goto cleanup;
            *selected = roots[index];
            *allocated = summary.allocated;
            wls_sha256_hex((const unsigned char *)summary_copy, length, ledger_digest);
            result = 0;
            break;
        }
    }
cleanup:
    if (summary_copy != NULL) { sodium_memzero(summary_copy, length); free(summary_copy); }
    if (contents != NULL) { sodium_memzero(contents, length); free(contents); }
    if (fd >= 0) { (void)flock(fd, LOCK_UN); close(fd); }
    return result;
}

static int wls_auth_abort_v2(
    const char *home,
    const char *project,
    const char *tx,
    const char *intent,
    char *reply,
    size_t reply_capacity
) {
    struct wls_security_summary summary;
    char path[PATH_MAX];
    char record[256];
    char digest[65];
    char *contents = NULL;
    char *copy = NULL;
    char *binding_copy = NULL;
    size_t length = 0U;
    size_t copy_length = 0U;
    int fd = -1;
    int written;
    int project_binding_found = 0;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U)
        || wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    copy = malloc(length + 1U);
    if (copy == NULL) goto fail;
    copy_length = length;
    memcpy(copy, contents, length + 1U);
    if (wls_security_summary(copy, tx, intent, "AUTH", &summary) != 0
        || summary.committed_found) goto fail;
    binding_copy = malloc(length + 1U);
    if (binding_copy == NULL) goto fail;
    memcpy(binding_copy, contents, length + 1U);
    {
        char *cursor = binding_copy;
        while (cursor[0] != '\0') {
            char *newline = strchr(cursor, '\n');
            char *fields[16];
            size_t count = 0U;
            if (newline == NULL) goto fail;
            *newline = '\0';
            if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto fail;
            if ((strcmp(fields[0], "P") == 0
                    || strcmp(fields[0], "C") == 0
                    || strcmp(fields[0], "Q") == 0)
                && count >= 4U
                && strcmp(fields[2], tx) == 0) {
                if (strcmp(fields[1], project) != 0
                    || strcmp(fields[3], intent) != 0) goto fail;
                project_binding_found = 1;
            }
            cursor = newline + 1U;
        }
    }
    sodium_memzero(binding_copy, length); free(binding_copy); binding_copy = NULL;
    if (!summary.reservation_found) {
        if (summary.aborted_found || project_binding_found) goto fail;
        wls_sha256_hex((const unsigned char *)contents, length, digest);
        written = snprintf(
            reply, reply_capacity,
            "WLS-ACTION/2\tOK\tAUTH_ABORT\t%s\t%s\t0\t%lu\t%s\n",
            tx, intent, summary.allocated, digest
        );
        if (written <= 0 || written >= (int)reply_capacity) goto fail;
        sodium_memzero(copy, copy_length); free(copy);
        sodium_memzero(contents, length); free(contents);
        (void)flock(fd, LOCK_UN); close(fd);
        return 0;
    }
    if (!summary.aborted_found) {
        if (snprintf(record, sizeof(record), "Q\t%s\t%s\t%s\t%lu\n",
                project, tx, intent, summary.assigned) >= (int)sizeof(record)
            || wls_security_append_locked(fd, path, record) != 0) goto fail;
    }
    free(contents); contents = NULL;
    if (lseek(fd, 0, SEEK_SET) < 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    wls_sha256_hex((const unsigned char *)contents, length, digest);
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tAUTH_ABORT\t%s\t%s\t%lu\t%lu\t%s\n",
        tx, intent, summary.assigned, summary.allocated, digest
    );
    if (written <= 0 || written >= (int)reply_capacity) goto fail;
    sodium_memzero(copy, copy_length); free(copy);
    sodium_memzero(contents, length); free(contents);
    (void)flock(fd, LOCK_UN); close(fd);
    return 0;
fail:
    if (binding_copy != NULL) { sodium_memzero(binding_copy, length); free(binding_copy); }
    if (copy != NULL) { sodium_memzero(copy, copy_length); free(copy); }
    if (contents != NULL) { sodium_memzero(contents, length); free(contents); }
    if (fd >= 0) { (void)flock(fd, LOCK_UN); close(fd); }
    return wls_security_reply_error(
        reply, reply_capacity, "BINDING_CONFLICT", "AUTH_ABORT", tx, intent
    );
}

static int wls_auth_attest_root_v2(
    const char *home,
    const char *project,
    const char *tx,
    const char *intent,
    const char *alias,
    char *reply,
    size_t reply_capacity
) {
    struct wls_auth_root_v2 root;
    unsigned long allocated = 0U;
    char ledger_digest[65];
    int written;
    (void)ledger_digest;
    (void)allocated;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U) || !wls_is_alias(alias)
        || wls_auth_load_committed_root(
            home, project, tx, intent, alias, &root, &allocated, ledger_digest
        ) != 0) {
        return wls_security_reply_error(
            reply, reply_capacity, "NOT_COMMITTED", "ATTEST_ROOT", tx, intent
        );
    }
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tATTEST_ROOT\t%s\t%s\t%lu\t%lu\t%s\t%s\t%s\t%s\n",
        tx, intent, root.assigned, root.owner, alias,
        root.project_object, root.certificate_object, root.attestation
    );
    return written > 0 && written < (int)reply_capacity ? 0 : -1;
}

struct wls_auth_transfer_v2 {
    int prepared;
    int committed;
    int aborted;
    unsigned long assigned;
    unsigned long owner;
    unsigned long expected_previous;
    unsigned long root_count;
    char roots_digest[65];
    char previous_anchor[65];
    char tombstone_digest[65];
};

static int wls_auth_transfer_target(
    const char *contents,
    size_t length,
    const char *project,
    const char *tx,
    const char *intent
) {
    char *copy = malloc(length + 1U);
    char *cursor;
    int found = 0;
    if (copy == NULL) return -1;
    memcpy(copy, contents, length + 1U);
    cursor = copy;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t count = 0U;
        if (newline == NULL) { found = -1; break; }
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) {
            found = -1; break;
        }
        if (strcmp(fields[0], "R") == 0 && count == 10U
            && strcmp(fields[2], project) == 0
            && strcmp(fields[3], tx) == 0) {
            if (strcmp(fields[4], intent) != 0 || found) { found = -1; break; }
            found = 1;
        }
        cursor = newline + 1U;
    }
    sodium_memzero(copy, length); free(copy);
    return found;
}

static int wls_auth_transfer_scan(
    char *contents,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    struct wls_auth_transfer_v2 *transfer
) {
    char *cursor = contents;
    memset(transfer, 0, sizeof(*transfer));
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t count = 0U;
        if (newline == NULL) return -1;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) return -1;
        if (strcmp(fields[0], "R") == 0 && count == 10U
            && strcmp(fields[3], rotation_id) == 0) {
            unsigned long assigned = 0U, owner = 0U, expected = 0U, roots = 0U;
            if (strcmp(fields[1], old_project) != 0
                || strcmp(fields[2], new_project) != 0
                || strcmp(fields[4], intent) != 0
                || wls_parse_unsigned(fields[5], 0, &assigned) != 0
                || wls_parse_unsigned(fields[6], 1, &owner) != 0
                || wls_parse_unsigned(fields[7], 1, &expected) != 0
                || wls_parse_unsigned(fields[8], 0, &roots) != 0
                || !wls_is_hex(fields[9], 64U)
                || transfer->prepared) return -1;
            transfer->prepared = 1;
            transfer->assigned = assigned;
            transfer->owner = owner;
            transfer->expected_previous = expected;
            transfer->root_count = roots;
            memcpy(transfer->roots_digest, fields[9], 65U);
        } else if (strcmp(fields[0], "T") == 0 && count == 11U
            && strcmp(fields[3], rotation_id) == 0) {
            unsigned long assigned = 0U, owner = 0U, roots = 0U;
            char canonical[1024];
            char calculated[65];
            int canonical_length;
            if (strcmp(fields[1], old_project) != 0
                || strcmp(fields[2], new_project) != 0
                || strcmp(fields[4], intent) != 0
                || wls_parse_unsigned(fields[5], 0, &assigned) != 0
                || wls_parse_unsigned(fields[6], 1, &owner) != 0
                || wls_parse_unsigned(fields[7], 0, &roots) != 0
                || !wls_is_hex(fields[8], 64U)
                || !wls_is_hex(fields[9], 64U)
                || !wls_is_hex(fields[10], 64U)
                || !transfer->prepared || transfer->aborted
                || transfer->committed) return -1;
            canonical_length = snprintf(
                canonical, sizeof(canonical),
                "%s\n%s\n%s\n%s\n%lu\n%lu\n%lu\n%s\n%s\n",
                fields[1], fields[2], fields[3], fields[4], assigned,
                owner, roots, fields[8], fields[9]
            );
            if (canonical_length <= 0
                || canonical_length >= (int)sizeof(canonical)) return -1;
            wls_sha256_hex(
                (const unsigned char *)canonical,
                (size_t)canonical_length,
                calculated
            );
            if (strcmp(calculated, fields[10]) != 0) return -1;
            transfer->committed = 1;
            if (transfer->assigned != assigned || transfer->owner != owner
                || transfer->root_count != roots
                || strcmp(transfer->roots_digest, fields[8]) != 0) return -1;
            memcpy(transfer->previous_anchor, fields[9], 65U);
            memcpy(transfer->tombstone_digest, fields[10], 65U);
        } else if (strcmp(fields[0], "Y") == 0 && count == 6U
            && strcmp(fields[3], rotation_id) == 0) {
            unsigned long assigned = 0U;
            if (strcmp(fields[1], old_project) != 0
                || strcmp(fields[2], new_project) != 0
                || strcmp(fields[4], intent) != 0
                || wls_parse_unsigned(fields[5], 0, &assigned) != 0
                || !transfer->prepared || transfer->committed
                || transfer->aborted
                || transfer->assigned != assigned) return -1;
            transfer->aborted = 1;
        }
        cursor = newline + 1U;
    }
    return 0;
}

static int wls_auth_latest_project(
    const char *contents,
    size_t length,
    const char *project,
    struct wls_auth_root_v2 *roots,
    size_t *root_count,
    char tx[33],
    char intent[65],
    unsigned long *assigned,
    char committed_digest[65]
) {
    char *first = NULL;
    char *second = NULL;
    char *cursor;
    unsigned long expected_count = 0U;
    int committed = 0;
    int aborted = 0;
    int found = 0;
    int result = -1;
    first = malloc(length + 1U);
    second = malloc(length + 1U);
    if (first == NULL || second == NULL) goto cleanup;
    memcpy(first, contents, length + 1U);
    memcpy(second, contents, length + 1U);
    cursor = first;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t count = 0U;
        unsigned long candidate = 0U;
        unsigned long candidate_count = 0U;
        if (newline == NULL) goto cleanup;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto cleanup;
        if (strcmp(fields[0], "T") == 0 && count == 11U
            && strcmp(fields[1], project) == 0) goto cleanup;
        if (strcmp(fields[0], "C") == 0 && count == 7U
            && strcmp(fields[1], project) == 0) {
            if (wls_parse_unsigned(fields[4], 0, &candidate) != 0
                || wls_parse_unsigned(fields[5], 0, &candidate_count) != 0
                || !wls_is_hex(fields[6], 64U)) goto cleanup;
        } else if (strcmp(fields[0], "T") == 0 && count == 11U
            && strcmp(fields[2], project) == 0) {
            if (wls_parse_unsigned(fields[5], 0, &candidate) != 0
                || wls_parse_unsigned(fields[7], 0, &candidate_count) != 0
                || !wls_is_hex(fields[8], 64U)) goto cleanup;
        }
        if (candidate > 0U && (!found || candidate > *assigned)) {
            const char *candidate_tx = strcmp(fields[0], "C") == 0
                ? fields[2] : fields[3];
            const char *candidate_intent = strcmp(fields[0], "C") == 0
                ? fields[3] : fields[4];
            const char *candidate_digest = strcmp(fields[0], "C") == 0
                ? fields[6] : fields[8];
            *assigned = candidate;
            expected_count = candidate_count;
            memcpy(tx, candidate_tx, 33U);
            memcpy(intent, candidate_intent, 65U);
            memcpy(committed_digest, candidate_digest, 65U);
            found = 1;
        }
        cursor = newline + 1U;
    }
    if (!found || expected_count == 0U || expected_count > WLS_MAX_AUTH_ROOTS
        || wls_auth_collect(
            second, project, tx, intent, roots, WLS_MAX_AUTH_ROOTS,
            root_count, &committed, &aborted, committed_digest
        ) != 0
        || !committed || aborted || *root_count != (size_t)expected_count) goto cleanup;
    result = 0;
cleanup:
    if (first != NULL) { sodium_memzero(first, length); free(first); }
    if (second != NULL) { sodium_memzero(second, length); free(second); }
    return result;
}

static int wls_auth_transfer_roots(
    struct wls_auth_root_v2 *roots,
    size_t root_count,
    const char *old_project,
    const char *old_tx,
    const char *old_intent,
    unsigned long old_assigned,
    const char *old_digest,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    unsigned long assigned,
    unsigned long owner,
    char attestations[WLS_MAX_AUTH_ROOTS][65],
    char roots_digest[65]
) {
    char *old_canonical = NULL;
    char *new_canonical = NULL;
    size_t capacity = root_count * 512U + 1U;
    size_t old_length = 0U;
    size_t new_length = 0U;
    size_t index;
    char actual_old_digest[65];
    int result = -1;
    qsort(roots, root_count, sizeof(roots[0]), wls_auth_alias_compare);
    old_canonical = calloc(capacity, 1U);
    new_canonical = calloc(capacity, 1U);
    if (old_canonical == NULL || new_canonical == NULL) goto cleanup;
    for (index = 0U; index < root_count; index++) {
        char project_object[96];
        char certificate_object[96];
        char old_attestation[65];
        int amount;
        if (roots[index].owner != owner || roots[index].assigned != old_assigned
            || strcmp(roots[index].project, old_project) != 0
            || strcmp(roots[index].tx, old_tx) != 0
            || strcmp(roots[index].intent, old_intent) != 0
            || (index > 0U
                && strcmp(roots[index - 1U].alias, roots[index].alias) >= 0)
            || wls_auth_root_validate(
                roots[index].project_root_hex,
                roots[index].certificate_root_hex,
                owner,
                project_object,
                certificate_object
            ) != 0
            || strcmp(project_object, roots[index].project_object) != 0
            || strcmp(certificate_object, roots[index].certificate_object) != 0
            || wls_auth_attestation(
                old_project, old_tx, old_intent, old_assigned, owner,
                roots[index].alias, project_object, certificate_object,
                old_attestation
            ) != 0
            || strcmp(old_attestation, roots[index].attestation) != 0
            || wls_auth_attestation(
                new_project, rotation_id, intent, assigned, owner,
                roots[index].alias, project_object, certificate_object,
                attestations[index]
            ) != 0) goto cleanup;
        amount = snprintf(
            old_canonical + old_length, capacity - old_length,
            "%s\t%lu\t%s\t%s\t%s\n",
            roots[index].alias, old_assigned, project_object,
            certificate_object, old_attestation
        );
        if (amount <= 0 || (size_t)amount >= capacity - old_length) goto cleanup;
        old_length += (size_t)amount;
        amount = snprintf(
            new_canonical + new_length, capacity - new_length,
            "%s\t%lu\t%s\t%s\t%s\n",
            roots[index].alias, assigned, project_object,
            certificate_object, attestations[index]
        );
        if (amount <= 0 || (size_t)amount >= capacity - new_length) goto cleanup;
        new_length += (size_t)amount;
    }
    wls_sha256_hex((const unsigned char *)old_canonical, old_length, actual_old_digest);
    if (strcmp(actual_old_digest, old_digest) != 0) goto cleanup;
    wls_sha256_hex((const unsigned char *)new_canonical, new_length, roots_digest);
    result = 0;
cleanup:
    if (old_canonical != NULL) { sodium_memzero(old_canonical, capacity); free(old_canonical); }
    if (new_canonical != NULL) { sodium_memzero(new_canonical, capacity); free(new_canonical); }
    return result;
}

static int wls_auth_transfer_authorized(
    const char *channel,
    const struct wls_peer *peer,
    unsigned long owner
) {
    return (strcmp(channel, "admin") == 0 && peer->uid == 0U)
        || (strcmp(channel, "project") == 0 && peer->uid == owner);
}

static int wls_auth_transfer_prepare_v2(
    const char *home,
    const char *channel,
    const struct wls_peer *peer,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    const char *owner_text,
    const char *expected_previous,
    char *reply,
    size_t reply_capacity
) {
    struct wls_auth_root_v2 roots[WLS_MAX_AUTH_ROOTS];
    struct wls_auth_transfer_v2 transfer;
    unsigned long owner = 0U, assigned = 0U, old_assigned = 0U;
    size_t root_count = 0U, length = 0U;
    char old_tx[33], old_intent[65], old_digest[65];
    char attestations[WLS_MAX_AUTH_ROOTS][65];
    char roots_digest[65], registry_digest[65], reserve_reply[1024];
    char path[PATH_MAX], record[1024];
    char *contents = NULL, *copy = NULL;
    int fd = -1, written;
    if (!wls_is_uuid(old_project) || !wls_is_uuid(new_project)
        || strcmp(old_project, new_project) == 0
        || !wls_is_hex(rotation_id, 32U) || !wls_is_hex(intent, 64U)
        || wls_parse_unsigned(owner_text, 1, &owner) != 0
        || !wls_auth_transfer_authorized(channel, peer, owner)
        || wls_security_operation(
            home, "SECURITY_RESERVE", rotation_id, intent,
            "AUTH_TRANSFER", expected_previous, NULL,
            reserve_reply, sizeof(reserve_reply)
        ) != 0
        || sscanf(
            reserve_reply,
            "WLS-ACTION/2\tOK\tSECURITY_RESERVE\t%*32s\t%*64s\t%lu",
            &assigned
        ) != 1
        || wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    copy = malloc(length + 1U);
    if (copy == NULL) goto fail;
    memcpy(copy, contents, length + 1U);
    if (wls_auth_transfer_scan(
            copy, old_project, new_project, rotation_id, intent, &transfer
        ) != 0
        || transfer.aborted
        || (transfer.prepared
            && (transfer.assigned != assigned || transfer.owner != owner))) goto fail;
    sodium_memzero(copy, length); free(copy); copy = NULL;
    if (!transfer.prepared) {
        if (wls_auth_latest_project(
                contents, length, old_project, roots, &root_count,
                old_tx, old_intent, &old_assigned, old_digest
            ) != 0
            || wls_auth_transfer_roots(
                roots, root_count, old_project, old_tx, old_intent,
                old_assigned, old_digest, new_project, rotation_id, intent,
                assigned, owner, attestations, roots_digest
            ) != 0) goto fail;
        written = snprintf(
            record, sizeof(record),
            "R\t%s\t%s\t%s\t%s\t%lu\t%lu\t%s\t%lu\t%s\n",
            old_project, new_project, rotation_id, intent, assigned, owner,
            expected_previous, (unsigned long)root_count, roots_digest
        );
        if (written <= 0 || written >= (int)sizeof(record)
            || wls_security_append_locked(fd, path, record) != 0) goto fail;
    }
    sodium_memzero(contents, length); free(contents); contents = NULL;
    if (lseek(fd, 0, SEEK_SET) < 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    wls_sha256_hex((const unsigned char *)contents, length, registry_digest);
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tAUTH_TRANSFER_PREPARE\t%s\t%s\t%lu\t%s\n",
        rotation_id, intent, assigned, registry_digest
    );
    if (written <= 0 || written >= (int)reply_capacity) goto fail;
    sodium_memzero(contents, length); free(contents);
    (void)flock(fd, LOCK_UN); close(fd);
    return 0;
fail:
    if (copy != NULL) { sodium_memzero(copy, length); free(copy); }
    if (contents != NULL) { sodium_memzero(contents, length); free(contents); }
    if (fd >= 0) { (void)flock(fd, LOCK_UN); close(fd); }
    return wls_security_reply_error(
        reply, reply_capacity, "TRANSFER_CONFLICT",
        "AUTH_TRANSFER_PREPARE", rotation_id, intent
    );
}

static int wls_auth_transfer_existing_roots(
    const char *contents,
    size_t length,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    unsigned long assigned,
    unsigned long owner,
    struct wls_auth_root_v2 *roots,
    size_t root_count,
    char attestations[WLS_MAX_AUTH_ROOTS][65],
    int present[WLS_MAX_AUTH_ROOTS]
) {
    char *copy = malloc(length + 1U);
    char *cursor;
    int result = -1;
    size_t index;
    if (copy == NULL) return -1;
    memset(present, 0, sizeof(int) * WLS_MAX_AUTH_ROOTS);
    memcpy(copy, contents, length + 1U);
    cursor = copy;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16];
        size_t count = 0U;
        if (newline == NULL) goto cleanup;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto cleanup;
        if (strcmp(fields[0], "P") == 0 && count == 13U
            && strcmp(fields[1], new_project) == 0) {
            struct wls_auth_root_v2 parsed;
            if (wls_auth_parse_root(fields, count, &parsed) != 0
                || strcmp(parsed.tx, rotation_id) != 0
                || strcmp(parsed.intent, intent) != 0
                || parsed.assigned != assigned || parsed.owner != owner
                || parsed.expected_count != (unsigned long)root_count) goto cleanup;
            for (index = 0U; index < root_count; index++) {
                if (strcmp(roots[index].alias, parsed.alias) != 0) continue;
                if (present[index]
                    || strcmp(roots[index].project_root_hex, parsed.project_root_hex) != 0
                    || strcmp(roots[index].certificate_root_hex,
                        parsed.certificate_root_hex) != 0
                    || strcmp(roots[index].project_object, parsed.project_object) != 0
                    || strcmp(roots[index].certificate_object,
                        parsed.certificate_object) != 0
                    || strcmp(attestations[index], parsed.attestation) != 0) goto cleanup;
                present[index] = 1;
                break;
            }
            if (index == root_count) goto cleanup;
        } else if (strcmp(fields[0], "C") == 0 && count == 7U
            && strcmp(fields[1], new_project) == 0) goto cleanup;
        else if (strcmp(fields[0], "T") == 0 && count == 11U
            && strcmp(fields[2], new_project) == 0
            && strcmp(fields[3], rotation_id) != 0) goto cleanup;
        cursor = newline + 1U;
    }
    result = 0;
cleanup:
    sodium_memzero(copy, length); free(copy);
    return result;
}

static int wls_auth_transfer_commit_v2(
    const char *home,
    const char *channel,
    const struct wls_peer *peer,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    const char *assigned_text,
    const char *controller_anchor,
    char *reply,
    size_t reply_capacity
) {
    struct wls_auth_root_v2 roots[WLS_MAX_AUTH_ROOTS];
    struct wls_auth_transfer_v2 transfer;
    struct wls_security_summary summary;
    unsigned long assigned = 0U, old_assigned = 0U;
    size_t root_count = 0U, length = 0U, index;
    char old_tx[33], old_intent[65], old_digest[65];
    char attestations[WLS_MAX_AUTH_ROOTS][65];
    char roots_digest[65], registry_digest[65], tombstone[65];
    char tombstone_canonical[1024];
    char path[PATH_MAX];
    char *contents = NULL, *copy = NULL;
    int present[WLS_MAX_AUTH_ROOTS];
    int fd = -1, written;
    if (!wls_is_uuid(old_project) || !wls_is_uuid(new_project)
        || strcmp(old_project, new_project) == 0
        || !wls_is_hex(rotation_id, 32U) || !wls_is_hex(intent, 64U)
        || wls_parse_unsigned(assigned_text, 0, &assigned) != 0
        || !wls_is_hex(controller_anchor, 64U)
        || wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    copy = malloc(length + 1U);
    if (copy == NULL) goto fail;
    memcpy(copy, contents, length + 1U);
    if (wls_auth_transfer_scan(
            copy, old_project, new_project, rotation_id, intent, &transfer
        ) != 0
        || !transfer.prepared || transfer.aborted
        || transfer.assigned != assigned
        || !wls_auth_transfer_authorized(channel, peer, transfer.owner)) goto fail;
    sodium_memzero(copy, length); free(copy); copy = NULL;
    copy = malloc(length + 1U);
    if (copy == NULL) goto fail;
    memcpy(copy, contents, length + 1U);
    if (wls_security_summary(
            copy, rotation_id, intent, "AUTH_TRANSFER", &summary
        ) != 0
        || !summary.reservation_found
        || summary.assigned != assigned) goto fail;
    sodium_memzero(copy, length); free(copy); copy = NULL;
    if (transfer.committed) {
        if (!summary.committed_found || summary.aborted_found
            || strcmp(summary.transaction_anchor,
                transfer.tombstone_digest) != 0
            || strcmp(transfer.previous_anchor,
                controller_anchor) != 0) goto fail;
    } else {
        if (summary.committed_found || summary.aborted_found
            || strcmp(summary.anchor, controller_anchor) != 0) goto fail;
        if (wls_auth_latest_project(
                contents, length, old_project, roots, &root_count,
                old_tx, old_intent, &old_assigned, old_digest
            ) != 0
            || root_count != (size_t)transfer.root_count
            || wls_auth_transfer_roots(
                roots, root_count, old_project, old_tx, old_intent,
                old_assigned, old_digest, new_project, rotation_id, intent,
                assigned, transfer.owner, attestations, roots_digest
            ) != 0
            || strcmp(roots_digest, transfer.roots_digest) != 0
            || wls_auth_transfer_existing_roots(
                contents, length, new_project, rotation_id, intent,
                assigned, transfer.owner, roots, root_count,
                attestations, present
            ) != 0) goto fail;
        for (index = 0U; index < root_count; index++) {
            char *record;
            size_t capacity;
            if (present[index]) continue;
            capacity = strlen(roots[index].project_root_hex)
                + strlen(roots[index].certificate_root_hex) + 1024U;
            record = calloc(capacity, 1U);
            if (record == NULL) goto fail;
            written = snprintf(
                record, capacity,
                "P\t%s\t%s\t%s\t%lu\t%lu\t%s\t%s\t%s\t%lu\t%s\t%s\t%s\n",
                new_project, rotation_id, intent, assigned, transfer.owner,
                roots[index].alias, roots[index].project_root_hex,
                roots[index].certificate_root_hex, (unsigned long)root_count,
                roots[index].project_object, roots[index].certificate_object,
                attestations[index]
            );
            if (written <= 0 || (size_t)written >= capacity
                || wls_security_append_locked(fd, path, record) != 0) {
                sodium_memzero(record, capacity); free(record); goto fail;
            }
            sodium_memzero(record, capacity); free(record);
        }
        written = snprintf(
            tombstone_canonical, sizeof(tombstone_canonical),
            "%s\n%s\n%s\n%s\n%lu\n%lu\n%lu\n%s\n%s\n",
            old_project, new_project, rotation_id, intent, assigned,
            transfer.owner, (unsigned long)root_count, roots_digest,
            controller_anchor
        );
        if (written <= 0 || written >= (int)sizeof(tombstone_canonical)) goto fail;
        wls_sha256_hex(
            (const unsigned char *)tombstone_canonical, (size_t)written, tombstone
        );
        {
            char record[1024];
            written = snprintf(
                record, sizeof(record),
                "T\t%s\t%s\t%s\t%s\t%lu\t%lu\t%lu\t%s\t%s\t%s\n",
                old_project, new_project, rotation_id, intent, assigned,
                transfer.owner, (unsigned long)root_count, roots_digest,
                controller_anchor, tombstone
            );
            if (written <= 0 || written >= (int)sizeof(record)
                || wls_security_append_locked(fd, path, record) != 0) goto fail;
        }
        memcpy(transfer.tombstone_digest, tombstone, 65U);
    }
    sodium_memzero(contents, length); free(contents); contents = NULL;
    if (lseek(fd, 0, SEEK_SET) < 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    wls_sha256_hex((const unsigned char *)contents, length, registry_digest);
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tAUTH_TRANSFER_COMMIT\t%s\t%s\t%lu\t%s\t%s\n",
        rotation_id, intent, assigned, registry_digest,
        transfer.tombstone_digest
    );
    if (written <= 0 || written >= (int)reply_capacity) goto fail;
    sodium_memzero(contents, length); free(contents);
    (void)flock(fd, LOCK_UN); close(fd);
    return 0;
fail:
    if (copy != NULL) { sodium_memzero(copy, length); free(copy); }
    if (contents != NULL) { sodium_memzero(contents, length); free(contents); }
    if (fd >= 0) { (void)flock(fd, LOCK_UN); close(fd); }
    return wls_security_reply_error(
        reply, reply_capacity, "TRANSFER_CONFLICT",
        "AUTH_TRANSFER_COMMIT", rotation_id, intent
    );
}

static int wls_auth_transfer_abort_v2(
    const char *home,
    const char *channel,
    const struct wls_peer *peer,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    const char *assigned_text,
    char *reply,
    size_t reply_capacity
) {
    struct wls_auth_transfer_v2 transfer;
    struct wls_security_summary summary;
    unsigned long assigned = 0U;
    size_t length = 0U, copy_length = 0U;
    char path[PATH_MAX], record[512], digest[65];
    char *contents = NULL, *copy = NULL, *summary_copy = NULL, *cursor;
    int fd = -1, written, commit_started = 0;
    if (!wls_is_uuid(old_project) || !wls_is_uuid(new_project)
        || !wls_is_hex(rotation_id, 32U) || !wls_is_hex(intent, 64U)
        || wls_parse_unsigned(assigned_text, 1, &assigned) != 0
        || wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    copy = malloc(length + 1U);
    if (copy == NULL) goto fail;
    copy_length = length;
    memcpy(copy, contents, length + 1U);
    if (wls_auth_transfer_scan(
            copy, old_project, new_project, rotation_id, intent, &transfer
        ) != 0
        || transfer.committed
        || (transfer.prepared && assigned != 0U
            && transfer.assigned != assigned)
        || (transfer.prepared
            && !wls_auth_transfer_authorized(channel, peer, transfer.owner))) goto fail;
    summary_copy = malloc(length + 1U);
    if (summary_copy == NULL) goto fail;
    memcpy(summary_copy, contents, length + 1U);
    if (wls_security_summary(
            summary_copy, rotation_id, intent, "AUTH_TRANSFER", &summary
        ) != 0
        || (!transfer.prepared
            && (summary.committed_found || summary.aborted_found))
        || (transfer.prepared
            && (!summary.reservation_found
                || summary.assigned != transfer.assigned
                || summary.committed_found
                || summary.aborted_found != transfer.aborted))) goto fail;
    sodium_memzero(summary_copy, length); free(summary_copy); summary_copy = NULL;
    if (!transfer.prepared) {
        memset(digest, '0', 64U); digest[64] = '\0';
        written = snprintf(
            reply, reply_capacity,
            "WLS-ACTION/2\tOK\tAUTH_TRANSFER_ABORT\t%s\t%s\t0\t%s\n",
            rotation_id, intent, digest
        );
        if (written <= 0 || written >= (int)reply_capacity) goto fail;
        sodium_memzero(copy, copy_length); free(copy);
        sodium_memzero(contents, length); free(contents);
        (void)flock(fd, LOCK_UN); close(fd);
        return 0;
    }
    assigned = transfer.assigned;
    sodium_memzero(copy, copy_length); free(copy); copy = malloc(length + 1U);
    if (copy == NULL) goto fail;
    memcpy(copy, contents, length + 1U);
    cursor = copy;
    while (cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[16]; size_t count = 0U;
        if (newline == NULL) goto fail;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 16U, &count) != 0) goto fail;
        if (count == 13U && strcmp(fields[0], "P") == 0
            && strcmp(fields[1], new_project) == 0
            && strcmp(fields[2], rotation_id) == 0) commit_started = 1;
        cursor = newline + 1U;
    }
    if (commit_started) goto fail;
    if (!transfer.aborted) {
        written = snprintf(
            record, sizeof(record), "Y\t%s\t%s\t%s\t%s\t%lu\n",
            old_project, new_project, rotation_id, intent, assigned
        );
        if (written <= 0 || written >= (int)sizeof(record)
            || wls_security_append_locked(fd, path, record) != 0) goto fail;
    }
    sodium_memzero(contents, length); free(contents); contents = NULL;
    if (lseek(fd, 0, SEEK_SET) < 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    wls_sha256_hex((const unsigned char *)contents, length, digest);
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tAUTH_TRANSFER_ABORT\t%s\t%s\t%lu\t%s\n",
        rotation_id, intent, assigned, digest
    );
    if (written <= 0 || written >= (int)reply_capacity) goto fail;
    sodium_memzero(copy, copy_length); free(copy);
    sodium_memzero(contents, length); free(contents);
    (void)flock(fd, LOCK_UN); close(fd);
    return 0;
fail:
    if (summary_copy != NULL) { sodium_memzero(summary_copy, length); free(summary_copy); }
    if (copy != NULL) { sodium_memzero(copy, copy_length); free(copy); }
    if (contents != NULL) { sodium_memzero(contents, length); free(contents); }
    if (fd >= 0) { (void)flock(fd, LOCK_UN); close(fd); }
    return wls_security_reply_error(
        reply, reply_capacity, "TRANSFER_CONFLICT",
        "AUTH_TRANSFER_ABORT", rotation_id, intent
    );
}

static int wls_auth_transfer_attest_v2(
    const char *home,
    const char *channel,
    const struct wls_peer *peer,
    const char *old_project,
    const char *new_project,
    const char *rotation_id,
    const char *intent,
    const char *assigned_text,
    char *reply,
    size_t reply_capacity
) {
    struct wls_auth_transfer_v2 transfer;
    struct wls_security_summary summary;
    unsigned long assigned = 0U;
    size_t length = 0U;
    char path[PATH_MAX], digest[65];
    char *contents = NULL, *copy = NULL;
    int fd = -1, written;
    char zero_digest[65];
    memset(zero_digest, '0', 64U); zero_digest[64] = '\0';
    if (!wls_is_uuid(old_project) || !wls_is_uuid(new_project)
        || strcmp(old_project, new_project) == 0
        || !wls_is_hex(rotation_id, 32U) || !wls_is_hex(intent, 64U)
        || wls_parse_unsigned(assigned_text, 1, &assigned) != 0
        || wls_security_open_locked(home, &fd, path, sizeof(path)) != 0
        || wls_security_read_locked(fd, &contents, &length) != 0) goto fail;
    copy = malloc(length + 1U);
    if (copy == NULL) goto fail;
    memcpy(copy, contents, length + 1U);
    if (wls_auth_transfer_scan(
            copy, old_project, new_project, rotation_id, intent, &transfer
        ) != 0) goto fail;
    sodium_memzero(copy, length); free(copy); copy = malloc(length + 1U);
    if (copy == NULL) goto fail;
    memcpy(copy, contents, length + 1U);
    if (wls_security_summary(
            copy, rotation_id, intent, "AUTH_TRANSFER", &summary
        ) != 0
        || (!transfer.prepared
            && (summary.committed_found || summary.aborted_found))
        || (transfer.prepared
            && (!summary.reservation_found
                || summary.assigned != transfer.assigned
                || summary.committed_found != transfer.committed
                || summary.aborted_found != transfer.aborted
                || (transfer.committed
                    && strcmp(summary.transaction_anchor,
                        transfer.tombstone_digest) != 0)))) goto fail;
    wls_sha256_hex((const unsigned char *)contents, length, digest);
    if (!transfer.prepared) {
        written = snprintf(
            reply, reply_capacity,
            "WLS-ACTION/2\tOK\tAUTH_TRANSFER_ATTEST\t%s\t%s\tUNKNOWN\t0\t%s\t%s\n",
            rotation_id, intent, zero_digest, zero_digest
        );
        if (written <= 0 || written >= (int)reply_capacity) goto fail;
        sodium_memzero(copy, length); free(copy);
        sodium_memzero(contents, length); free(contents);
        (void)flock(fd, LOCK_UN); close(fd);
        return 0;
    }
    if ((assigned != 0U && transfer.assigned != assigned)
        || !wls_auth_transfer_authorized(channel, peer, transfer.owner)) goto fail;
    assigned = transfer.assigned;
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tAUTH_TRANSFER_ATTEST\t%s\t%s\t%s\t%lu\t%s\t%s\n",
        rotation_id, intent,
        transfer.committed ? "COMMITTED" : (transfer.aborted ? "ABORTED" : "PREPARED"),
        assigned, digest,
        transfer.committed ? transfer.tombstone_digest : zero_digest
    );
    if (written <= 0 || written >= (int)reply_capacity) goto fail;
    sodium_memzero(copy, length); free(copy);
    sodium_memzero(contents, length); free(contents);
    (void)flock(fd, LOCK_UN); close(fd);
    return 0;
fail:
    if (copy != NULL) { sodium_memzero(copy, length); free(copy); }
    if (contents != NULL) { sodium_memzero(contents, length); free(contents); }
    if (fd >= 0) { (void)flock(fd, LOCK_UN); close(fd); }
    return wls_security_reply_error(
        reply, reply_capacity, "NOT_COMMITTED",
        "AUTH_TRANSFER_ATTEST", rotation_id, intent
    );
}

static int wls_snapshot_enrolled_v2(
    const char *home,
    const struct wls_peer *peer,
    const struct wls_controller_identity *controller_identity,
    const char *project,
    const char *tx,
    const char *intent,
    const char *alias,
    const char *source_relative_hex,
    const char *digest,
    const char *leaf,
    char *reply,
    size_t reply_capacity
) {
    struct wls_auth_root_v2 root;
    unsigned long allocated = 0U;
    char ledger_digest[65];
    char source_root[PATH_MAX];
    char source_relative[PATH_MAX];
    char destination_root[PATH_MAX];
    char destination_relative[PATH_MAX];
    int result;
    int written;
    (void)allocated;
    (void)ledger_digest;
    if (!wls_is_hex(digest, 64U)
        || (strcmp(leaf, "source-cert.pem") != 0
            && strcmp(leaf, "source-key.pem") != 0
            && strcmp(leaf, "source-chain.pem") != 0)
        || wls_auth_load_committed_root(
            home, project, tx, intent, alias, &root, &allocated, ledger_digest
        ) != 0
        || peer->uid != root.owner
        || wls_hex_decode(
            root.certificate_root_hex, source_root, sizeof(source_root)
        ) != 0
        || wls_hex_decode(
            source_relative_hex, source_relative, sizeof(source_relative)
        ) != 0
        || !wls_is_relative_safe(source_relative)
        || snprintf(destination_root, sizeof(destination_root), "%s/snapshots", home)
            >= (int)sizeof(destination_root)
        || snprintf(destination_relative, sizeof(destination_relative), "%s/%s", digest, leaf)
            >= (int)sizeof(destination_relative)) {
        return wls_security_reply_error(
            reply, reply_capacity, "DENIED", "SNAP", tx, intent
        );
    }
    result = wls_snapshot(
        source_root, source_relative, destination_root, destination_relative,
        (uid_t)root.owner, strcmp(leaf, "source-key.pem") == 0,
        controller_identity != NULL ? controller_identity->uid : (uid_t)-1,
        controller_identity != NULL ? controller_identity->gid : (gid_t)-1
    );
    if (result != 0) return wls_security_reply_error(
        reply, reply_capacity, "SNAPSHOT_FAILED", "SNAP", tx, intent
    );
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tSNAP\t%s\t%s\t%s\t%s\t%s\n",
        tx, intent, alias, digest, leaf
    );
    return written > 0 && written < (int)reply_capacity ? 0 : -1;
}

static int wls_atomic_target_allowed(const char *relative)
{
    static const char *allowed[] = {
        "runtime/conf/nginx.conf",
        "run/controller.pid",
        "state/control-endpoint.json",
        "state/disk-pressure.marker",
        "state/gateway-state.json",
        "state/journal.jsonl",
        "state/nonce.wal",
        "state/publication-current.json",
        "state/route-lkg.json",
        "state/security-ledger.json",
        "trust/active-slot",
        "trust/journal.untrusted",
        "trust/previous-slot",
        "trust/security-anchor.json",
        "trust/upgrade-state",
        "trust/slot-retention",
        "trust/nginx-process.identity",
        "trust/broker-launch.receipt",
        "trust/wls-edge-2.initialized.json"
    };
    size_t index;
    for (index = 0U; index < sizeof(allowed) / sizeof(allowed[0]); index++) {
        if (strcmp(relative, allowed[index]) == 0) return 1;
    }
    return 0;
}

static int wls_atomic_temporary_leaf_allowed(
    const char *temporary_leaf,
    const char *target_leaf
)
{
    size_t target_length;
    const char *nonce;
    size_t index;
    if (temporary_leaf == NULL || target_leaf == NULL) return 0;
    target_length = strlen(target_leaf);
    if (target_length == 0U
        || strncmp(temporary_leaf, target_leaf, target_length) != 0
        || strncmp(temporary_leaf + target_length, ".tmp-", 5U) != 0) {
        return 0;
    }
    nonce = temporary_leaf + target_length + 5U;
    if (strlen(nonce) != 12U) return 0;
    for (index = 0U; index < 12U; index++) {
        if (!((nonce[index] >= '0' && nonce[index] <= '9')
                || (nonce[index] >= 'a' && nonce[index] <= 'f'))) {
            return 0;
        }
    }
    return 1;
}

static int wls_file_digest_fd(int fd, char output[65], uint64_t *size)
{
    crypto_hash_sha256_state state;
    unsigned char digest[crypto_hash_sha256_BYTES];
    unsigned char buffer[65536];
    uint64_t total = 0U;
    ssize_t amount;
    if (lseek(fd, 0, SEEK_SET) < 0
        || crypto_hash_sha256_init(&state) != 0) return -1;
    for (;;) {
        amount = read(fd, buffer, sizeof(buffer));
        if (amount < 0 && errno == EINTR) continue;
        if (amount < 0) return -1;
        if (amount == 0) break;
        if (total > UINT64_MAX - (uint64_t)amount
            || crypto_hash_sha256_update(
                &state, buffer, (unsigned long long)amount
            ) != 0) return -1;
        total += (uint64_t)amount;
    }
    if (crypto_hash_sha256_final(&state, digest) != 0) return -1;
    (void)sodium_bin2hex(output, 65U, digest, sizeof(digest));
    sodium_memzero(digest, sizeof(digest));
    sodium_memzero(buffer, sizeof(buffer));
    *size = total;
    return 0;
}

static int wls_atomic_replace_v2(
    const char *home,
    const char *temporary_hex,
    const char *target_hex,
    const char *expected_digest,
    const char *expected_size_text,
    const char *mode_text,
    char *reply,
    size_t reply_capacity
) {
    char temporary[PATH_MAX];
    char target[PATH_MAX];
    char temporary_relative[PATH_MAX];
    char target_relative[PATH_MAX];
    char temporary_leaf[NAME_MAX + 1U];
    char target_leaf[NAME_MAX + 1U];
    char backup_leaf[NAME_MAX + 1U] = {0};
    char temporary_parent[PATH_MAX];
    char target_parent[PATH_MAX];
    char actual_digest[65];
    struct stat temporary_status;
    struct stat target_status;
    unsigned long expected_size = 0U;
    mode_t expected_mode;
    uint64_t actual_size = 0U;
    unsigned long long nonce;
    int home_fd = -1;
    int temporary_parent_fd = -1;
    int target_parent_fd = -1;
    int temporary_fd = -1;
    int target_exists = 0;
    int backup_created = 0;
    int replaced = 0;
    int written;
    size_t home_length = strlen(home);
    if (!wls_is_hex(expected_digest, 64U)
        || wls_parse_unsigned(expected_size_text, 1, &expected_size) != 0
        || expected_size > WLS_MAX_REQUEST
        || (strcmp(mode_text, "0600") != 0
            && strcmp(mode_text, "0640") != 0
            && strcmp(mode_text, "0644") != 0)
        || wls_hex_decode(temporary_hex, temporary, sizeof(temporary)) != 0
        || wls_hex_decode(target_hex, target, sizeof(target)) != 0
        || strncmp(temporary, home, home_length) != 0
        || strncmp(target, home, home_length) != 0
        || temporary[home_length] != '/'
        || target[home_length] != '/') goto denied;
    expected_mode = strcmp(mode_text, "0600") == 0 ? 0600
        : (strcmp(mode_text, "0640") == 0 ? 0640 : 0644);
    strcpy(temporary_relative, temporary + home_length + 1U);
    strcpy(target_relative, target + home_length + 1U);
    if (!wls_is_relative_safe(temporary_relative)
        || !wls_is_relative_safe(target_relative)
        || !wls_atomic_target_allowed(target_relative)) goto denied;
    strcpy(temporary_parent, temporary_relative);
    strcpy(target_parent, target_relative);
    {
        char *temporary_slash = strrchr(temporary_parent, '/');
        char *target_slash = strrchr(target_parent, '/');
        if (temporary_slash == NULL || target_slash == NULL) goto denied;
        if (strlen(temporary_slash + 1U) > NAME_MAX
            || strlen(target_slash + 1U) > NAME_MAX) goto denied;
        strcpy(temporary_leaf, temporary_slash + 1U);
        strcpy(target_leaf, target_slash + 1U);
        *temporary_slash = '\0';
        *target_slash = '\0';
    }
    if (strcmp(temporary_parent, target_parent) != 0
        || !wls_atomic_temporary_leaf_allowed(temporary_leaf, target_leaf)
        || strcmp(temporary_leaf, target_leaf) == 0) goto denied;
    home_fd = wls_open_absolute_directory(home);
    if (home_fd < 0) goto denied;
    temporary_parent_fd = wls_open_relative(
        home_fd, temporary_parent, O_RDONLY | O_DIRECTORY
    );
    target_parent_fd = wls_open_relative(
        home_fd, target_parent, O_RDONLY | O_DIRECTORY
    );
    if (temporary_parent_fd < 0 || target_parent_fd < 0) goto denied;
    {
        struct stat left;
        struct stat right;
        if (fstat(temporary_parent_fd, &left) != 0
            || fstat(target_parent_fd, &right) != 0
            || left.st_dev != right.st_dev || left.st_ino != right.st_ino) goto denied;
    }
    temporary_fd = openat(
        temporary_parent_fd, temporary_leaf,
        O_RDONLY | O_CLOEXEC | O_NOFOLLOW
    );
    if (temporary_fd < 0 || fstat(temporary_fd, &temporary_status) != 0
        || !S_ISREG(temporary_status.st_mode)
        || temporary_status.st_nlink != 1
        || temporary_status.st_uid != geteuid()
        || (temporary_status.st_mode & 0777) != expected_mode
        || wls_file_digest_fd(temporary_fd, actual_digest, &actual_size) != 0
        || actual_size != (uint64_t)expected_size
        || strcmp(actual_digest, expected_digest) != 0) goto denied;
    if (fstatat(
            target_parent_fd, target_leaf, &target_status, AT_SYMLINK_NOFOLLOW
        ) == 0) {
        target_exists = 1;
        if (!S_ISREG(target_status.st_mode) || target_status.st_nlink != 1
            || target_status.st_uid != geteuid()) goto denied;
        randombytes_buf(&nonce, sizeof(nonce));
        if (snprintf(
                backup_leaf, sizeof(backup_leaf), ".wls-replace-backup-%016llx", nonce
            ) >= (int)sizeof(backup_leaf)
            || linkat(
                target_parent_fd, target_leaf,
                target_parent_fd, backup_leaf, 0
            ) != 0
            || fsync(target_parent_fd) != 0) goto denied;
        backup_created = 1;
    } else if (errno != ENOENT) {
        goto denied;
    }
    if (renameat(
            temporary_parent_fd, temporary_leaf,
            target_parent_fd, target_leaf
        ) != 0) goto denied;
    replaced = 1;
    if (fsync(target_parent_fd) != 0) {
        if (target_exists && backup_created) {
            (void)renameat(
                target_parent_fd, backup_leaf, target_parent_fd, target_leaf
            );
        } else {
            (void)renameat(
                target_parent_fd, target_leaf,
                temporary_parent_fd, temporary_leaf
            );
        }
        (void)fsync(target_parent_fd);
        replaced = 0;
        goto denied;
    }
    if (backup_created) {
        (void)unlinkat(target_parent_fd, backup_leaf, 0);
        (void)fsync(target_parent_fd);
        backup_created = 0;
    }
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tATOMIC_REPLACE\t-\t-\t%s\t%lu\t%s\n",
        expected_digest, expected_size, mode_text
    );
    if (written <= 0 || written >= (int)reply_capacity) goto denied;
    close(temporary_fd); close(target_parent_fd);
    close(temporary_parent_fd); close(home_fd);
    return 0;
denied:
    if (replaced && backup_created) {
        (void)renameat(target_parent_fd, backup_leaf, target_parent_fd, target_leaf);
        (void)fsync(target_parent_fd);
    }
    if (temporary_fd >= 0) close(temporary_fd);
    if (target_parent_fd >= 0) close(target_parent_fd);
    if (temporary_parent_fd >= 0) close(temporary_parent_fd);
    if (home_fd >= 0) close(home_fd);
    return wls_security_reply_error(
        reply, reply_capacity, "ATOMIC_REPLACE_FAILED", "ATOMIC_REPLACE", NULL, NULL
    );
}

static int wls_process_identity(
    pid_t pid,
    char *executable,
    size_t executable_capacity,
    unsigned long long *start_id
)
{
#if defined(__linux__)
    char path[64];
    char contents[4096];
    char *cursor;
    char *end;
    int fd;
    ssize_t amount;
    unsigned int field = 3U;
    ssize_t executable_length;
    if (executable == NULL || executable_capacity < 2U
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
                || (*end != ' ' && *end != '\0') || *start_id == 0ULL) return -1;
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
    if (executable == NULL || executable_capacity < 2U
        || executable_capacity > (size_t)INT_MAX) return -1;
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

static int wls_read_regular_at(
    int root_fd,
    const char *relative,
    size_t maximum,
    char **contents,
    size_t *length
) {
    struct stat status;
    char *buffer;
    int fd = wls_open_relative(root_fd, relative, O_RDONLY);
    if (fd < 0 || fstat(fd, &status) != 0 || !S_ISREG(status.st_mode)
        || status.st_nlink != 1 || status.st_size < 0
        || (uint64_t)status.st_size > maximum) {
        if (fd >= 0) close(fd);
        return -1;
    }
    buffer = calloc((size_t)status.st_size + 1U, 1U);
    if (buffer == NULL
        || wls_read_exact(fd, buffer, (size_t)status.st_size) != 0) {
        free(buffer);
        close(fd);
        return -1;
    }
    close(fd);
    *contents = buffer;
    *length = (size_t)status.st_size;
    return 0;
}

static int wls_validate_nginx_arguments(
    char **arguments,
    size_t count,
    const char *binary,
    const char *prefix,
    const char *config
) {
    return arguments != NULL && count == 5U
        && strcmp(arguments[0], binary) == 0
        && strcmp(arguments[1], "-p") == 0
        && strcmp(arguments[2], prefix) == 0
        && strcmp(arguments[3], "-c") == 0
        && strcmp(arguments[4], config) == 0 ? 0 : -1;
}

static int wls_nginx_master_title_matches(
    const char *title,
    const char *binary,
    const char *prefix,
    const char *config
) {
    char expected[PATH_MAX * 3U + 64U];
    int written;
    if (title == NULL || binary == NULL || prefix == NULL || config == NULL) {
        return -1;
    }
    written = snprintf(
        expected,
        sizeof(expected),
        "nginx: master process %s -p %s -c %s",
        binary,
        prefix,
        config
    );
    return written > 0 && written < (int)sizeof(expected)
        && strcmp(title, expected) == 0 ? 0 : -1;
}

static int wls_process_command_matches(
    pid_t pid,
    const char *binary,
    const char *prefix,
    const char *config
) {
    char *buffer = NULL;
    char *arguments[128];
    size_t count = 0U;
    int result = -1;
#if defined(__linux__)
    char path[64];
    int fd = -1;
    ssize_t amount;
    size_t used = 0U;
    if (snprintf(path, sizeof(path), "/proc/%ld/cmdline", (long)pid)
        >= (int)sizeof(path)) return -1;
    buffer = calloc(WLS_MAX_SNAPSHOT + 1U, 1U);
    fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (buffer == NULL || fd < 0) goto cleanup;
    while (used < WLS_MAX_SNAPSHOT) {
        do {
            amount = read(fd, buffer + used, WLS_MAX_SNAPSHOT - used);
        } while (amount < 0 && errno == EINTR);
        if (amount < 0) goto cleanup;
        if (amount == 0) break;
        used += (size_t)amount;
    }
    if (used == 0U || used == WLS_MAX_SNAPSHOT || buffer[used - 1U] != '\0') goto cleanup;
    if (wls_nginx_master_title_matches(
            buffer, binary, prefix, config
        ) == 0) {
        result = 0;
        goto cleanup;
    }
    {
        size_t offset = 0U;
        while (offset < used) {
            size_t length = strnlen(buffer + offset, used - offset);
            if (length == 0U || length >= used - offset
                || count >= sizeof(arguments) / sizeof(arguments[0])) goto cleanup;
            arguments[count++] = buffer + offset;
            offset += length + 1U;
        }
    }
#elif defined(__APPLE__)
    int mib[3] = {CTL_KERN, KERN_PROCARGS2, (int)pid};
    size_t amount = 0U;
    int argc = 0;
    char *cursor;
    char *end;
    int index;
    if (sysctl(mib, 3U, NULL, &amount, NULL, 0U) != 0
        || amount < sizeof(argc) + 2U || amount > WLS_MAX_SNAPSHOT) return -1;
    buffer = calloc(amount + 1U, 1U);
    if (buffer == NULL || sysctl(mib, 3U, buffer, &amount, NULL, 0U) != 0
        || amount < sizeof(argc) + 2U) goto cleanup;
    memcpy(&argc, buffer, sizeof(argc));
    if (argc < 1 || argc > (int)(sizeof(arguments) / sizeof(arguments[0]))) goto cleanup;
    cursor = buffer + sizeof(argc);
    end = buffer + amount;
    while (cursor < end && *cursor != '\0') cursor++;
    while (cursor < end && *cursor == '\0') cursor++;
    if (cursor < end && wls_nginx_master_title_matches(
            cursor, binary, prefix, config
        ) == 0) {
        result = 0;
        goto cleanup;
    }
    for (index = 0; index < argc; index++) {
        size_t length;
        if (cursor >= end) goto cleanup;
        length = strnlen(cursor, (size_t)(end - cursor));
        if (length == 0U || length >= (size_t)(end - cursor)) goto cleanup;
        arguments[count++] = cursor;
        cursor += length + 1U;
    }
#else
    (void)pid; (void)binary; (void)prefix; (void)config;
    return -1;
#endif
    result = wls_validate_nginx_arguments(arguments, count, binary, prefix, config);
cleanup:
#if defined(__linux__)
    if (fd >= 0) close(fd);
#endif
    if (buffer != NULL) {
#if defined(__APPLE__)
        sodium_memzero(buffer, amount);
#else
        sodium_memzero(buffer, WLS_MAX_SNAPSHOT + 1U);
#endif
        free(buffer);
    }
    return result;
}

static int wls_open_live_binary(
    pid_t pid,
    const char *binary_path,
    int *binary_fd
) {
    int expected_fd = -1;
    int live_fd = -1;
    struct stat expected_status;
    if (binary_path == NULL || binary_fd == NULL) return -1;
    expected_fd = open(binary_path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (expected_fd < 0 || fstat(expected_fd, &expected_status) != 0
        || !S_ISREG(expected_status.st_mode) || expected_status.st_nlink != 1
        || expected_status.st_uid != 0 || (expected_status.st_mode & 0022) != 0) {
        goto denied;
    }
#if defined(__linux__)
    {
        char path[64];
        struct stat live_status;
        if (snprintf(path, sizeof(path), "/proc/%ld/exe", (long)pid)
            >= (int)sizeof(path)) goto denied;
        live_fd = open(path, O_RDONLY | O_CLOEXEC);
        if (live_fd < 0 || fstat(live_fd, &live_status) != 0
            || !S_ISREG(live_status.st_mode)
            || live_status.st_dev != expected_status.st_dev
            || live_status.st_ino != expected_status.st_ino) goto denied;
    }
    close(expected_fd);
    *binary_fd = live_fd;
    return 0;
#elif defined(__APPLE__)
    {
        uint64_t address = 0ULL;
        unsigned int iterations;
        int matched = 0;
        for (iterations = 0U; iterations < 65536U; iterations++) {
            struct proc_regionwithpathinfo region;
            uint64_t next;
            int amount;
            memset(&region, 0, sizeof(region));
            amount = proc_pidinfo(
                pid,
                PROC_PIDREGIONPATHINFO,
                address,
                &region,
                (int)sizeof(region)
            );
            if (amount != (int)sizeof(region)) break;
            next = region.prp_prinfo.pri_address + region.prp_prinfo.pri_size;
            if ((region.prp_prinfo.pri_protection & VM_PROT_EXECUTE) != 0U
                && strcmp(region.prp_vip.vip_path, binary_path) == 0
                && (uint64_t)region.prp_vip.vip_vi.vi_stat.vst_dev
                    == (uint64_t)expected_status.st_dev
                && region.prp_vip.vip_vi.vi_stat.vst_ino
                    == (uint64_t)expected_status.st_ino) {
                matched = 1;
                break;
            }
            if (next <= address) break;
            address = next;
        }
        if (!matched) goto denied;
    }
    *binary_fd = expected_fd;
    return 0;
#else
    goto denied;
#endif
denied:
    if (live_fd >= 0) close(live_fd);
    if (expected_fd >= 0) close(expected_fd);
    return -1;
}

static int wls_json_hex_field(
    const char *json,
    const char *name,
    size_t hex_length,
    char *output
) {
    char needle[96];
    const char *cursor;
    if (json == NULL || name == NULL || output == NULL
        || hex_length + 1U > 128U
        || snprintf(needle, sizeof(needle), "\"%s\"", name)
            >= (int)sizeof(needle)) return -1;
    cursor = strstr(json, needle);
    if (cursor == NULL || strstr(cursor + strlen(needle), needle) != NULL) return -1;
    cursor += strlen(needle);
    while (*cursor == ' ' || *cursor == '\t' || *cursor == '\r' || *cursor == '\n') cursor++;
    if (*cursor++ != ':') return -1;
    while (*cursor == ' ' || *cursor == '\t' || *cursor == '\r' || *cursor == '\n') cursor++;
    if (*cursor++ != '"') return -1;
    memcpy(output, cursor, hex_length);
    output[hex_length] = '\0';
    cursor += hex_length;
    if (*cursor++ != '"' || !wls_is_hex(output, hex_length)) return -1;
    while (*cursor == ' ' || *cursor == '\t' || *cursor == '\r' || *cursor == '\n') cursor++;
    return *cursor == ',' || *cursor == '}' ? 0 : -1;
}

static int wls_json_unsigned(
    const char *json,
    const char *name,
    unsigned long *value
) {
    char needle[96];
    const char *cursor;
    char *end;
    if (snprintf(needle, sizeof(needle), "\"%s\"", name)
        >= (int)sizeof(needle)) return -1;
    cursor = strstr(json, needle);
    if (cursor == NULL || strstr(cursor + strlen(needle), needle) != NULL) return -1;
    cursor += strlen(needle);
    while (*cursor == ' ' || *cursor == '\t' || *cursor == '\r'
        || *cursor == '\n') cursor++;
    if (*cursor++ != ':') return -1;
    while (*cursor == ' ' || *cursor == '\t' || *cursor == '\r'
        || *cursor == '\n') cursor++;
    errno = 0;
    if (*cursor < '0' || *cursor > '9') return -1;
    *value = strtoul(cursor, &end, 10);
    if (errno != 0 || end == cursor) return -1;
    while (*end == ' ' || *end == '\t' || *end == '\r' || *end == '\n') end++;
    return *end == ',' || *end == '}' ? 0 : -1;
}

static int wls_controller_slot_runtime_generation(
    const char *home,
    char slot,
    char runtime_generation[65]
) {
    char relative[64];
    char *manifest = NULL;
    size_t manifest_length = 0U;
    int home_fd = -1;
    int result = -1;
    if (home == NULL || runtime_generation == NULL
        || (slot != 'A' && slot != 'B')
        || snprintf(
            relative, sizeof(relative), "slots/%c/manifest.json", slot
        ) >= (int)sizeof(relative)) return -1;
    home_fd = wls_open_absolute_directory(home);
    if (home_fd < 0
        || wls_read_regular_at(
            home_fd, relative, WLS_MAX_REQUEST,
            &manifest, &manifest_length
        ) != 0
        || manifest_length == 0U
        || wls_json_hex_field(
            manifest,
            "runtime_generation",
            64U,
            runtime_generation
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    if (manifest != NULL) {
        sodium_memzero(manifest, manifest_length);
        free(manifest);
    }
    if (home_fd >= 0) close(home_fd);
    return result;
}

static int wls_write_controller_process_identity(
    const char *home,
    pid_t controller_pid,
    const char *php,
    const char *active_slot,
    const char *runtime_generation,
    const char *fencing
) {
    char identity_path[PATH_MAX];
    char expected_binary[PATH_MAX];
    char observed_binary[PATH_MAX];
    char manifest_generation[65];
    char fencing_digest[65];
    char payload[1024];
    unsigned long long start_id = 0ULL;
    unsigned int attempt;
    int identity_matched = 0;
    int length;
    if (home == NULL || controller_pid <= 0 || php == NULL
        || active_slot == NULL || runtime_generation == NULL || fencing == NULL
        || (active_slot[0] != 'A' && active_slot[0] != 'B')
        || active_slot[1] != '\0'
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(fencing, 64U)
        || snprintf(
            expected_binary,
            sizeof(expected_binary),
            "%s/slots/%c/bin/php",
            home,
            active_slot[0]
        ) >= (int)sizeof(expected_binary)
        || strcmp(expected_binary, php) != 0
        || snprintf(
            identity_path,
            sizeof(identity_path),
            "%s/trust/controller-process.identity",
            home
        ) >= (int)sizeof(identity_path)
        || wls_controller_slot_runtime_generation(
            home, active_slot[0], manifest_generation
        ) != 0
        || strcmp(manifest_generation, runtime_generation) != 0) return -1;
    for (
        attempt = 0U;
        attempt < WLS_CONTROLLER_IDENTITY_ATTEMPTS;
        attempt++
    ) {
        if (wls_process_identity(
                controller_pid,
                observed_binary,
                sizeof(observed_binary),
                &start_id
            ) == 0
            && strcmp(observed_binary, expected_binary) == 0) {
            identity_matched = 1;
            break;
        }
        if (kill(controller_pid, 0) != 0 && errno == ESRCH) {
            errno = ECHILD;
            return -1;
        }
        if (usleep(WLS_CONTROLLER_IDENTITY_POLL_US) != 0 && errno != EINTR) {
            return -1;
        }
    }
    if (!identity_matched) {
        errno = ETIMEDOUT;
        return -1;
    }
    wls_sha256_hex(
        (const unsigned char *)fencing, strlen(fencing), fencing_digest
    );
    length = snprintf(
        payload,
        sizeof(payload),
        "WLS-CONTROLLER-PROCESS/2\n"
        "pid=%ld\nstart_id=%llu\nslot=%c\n"
        "runtime_generation=%s\nfencing_digest=%s\n",
        (long)controller_pid,
        start_id,
        active_slot[0],
        runtime_generation,
        fencing_digest
    );
    sodium_memzero(fencing_digest, sizeof(fencing_digest));
    return length > 0 && length < (int)sizeof(payload)
        ? wls_atomic_root_write(identity_path, payload, (size_t)length)
        : -1;
}

static int wls_pid_is_gone(pid_t pid)
{
    if (pid <= 0) return 0;
    if (kill(pid, 0) == 0 || errno == EPERM) return 0;
    return errno == ESRCH ? 1 : 0;
}

/*
 * Preserve Nginx across a Broker crash, but never overlap two autonomous
 * Controllers. The new fencing token asks the previous v2 Controller to
 * self-isolate; only a Broker-authored, runtime-generation-bound identity is
 * waited. Unknown live PIDs are rejected and are never signalled here.
 */
static int wls_wait_previous_controller_exit(
    const char *home,
    const char *new_fencing
) {
    char *identity = NULL;
    size_t identity_length = 0U;
    char *pid_contents = NULL;
    size_t pid_length = 0U;
    char expected_binary[PATH_MAX];
    char actual_binary[PATH_MAX];
    char slot[2] = {0};
    char runtime_generation[65] = {0};
    char manifest_generation[65] = {0};
    char recorded_fencing_digest[65] = {0};
    char new_fencing_digest[65] = {0};
    unsigned long parsed_pid = 0UL;
    unsigned long long parsed_start = 0ULL;
    unsigned long long actual_start = 0ULL;
    int consumed = 0;
    int home_fd = -1;
    unsigned int attempt;
    int result = -1;
    if (home == NULL || new_fencing == NULL || !wls_is_hex(new_fencing, 64U)) {
        errno = EINVAL;
        return -1;
    }
    home_fd = wls_open_absolute_directory(home);
    if (home_fd < 0) return -1;
    if (wls_read_regular_at(
            home_fd,
            "trust/controller-process.identity",
            1024U,
            &identity,
            &identity_length
        ) != 0) {
        char *end = NULL;
        unsigned long fallback_pid;
        struct stat identity_status;
        struct stat pid_status;
        if (fstatat(
                home_fd,
                "trust/controller-process.identity",
                &identity_status,
                AT_SYMLINK_NOFOLLOW
            ) == 0
            || errno != ENOENT) {
            goto cleanup;
        }
        if (wls_read_regular_at(
                home_fd,
                "runtime/run/controller.pid",
                31U,
                &pid_contents,
                &pid_length
            ) != 0) {
            result = fstatat(
                home_fd,
                "runtime/run/controller.pid",
                &pid_status,
                AT_SYMLINK_NOFOLLOW
            ) != 0 && errno == ENOENT ? 0 : -1;
            goto cleanup;
        }
        while (pid_length > 0U
            && (pid_contents[pid_length - 1U] == '\r'
                || pid_contents[pid_length - 1U] == '\n')) {
            pid_contents[--pid_length] = '\0';
        }
        errno = 0;
        fallback_pid = strtoul(pid_contents, &end, 10);
        if (errno != 0 || end == pid_contents || *end != '\0'
            || fallback_pid == 0UL || fallback_pid > (unsigned long)INT_MAX) {
            goto cleanup;
        }
        result = wls_pid_is_gone((pid_t)fallback_pid) ? 0 : -1;
        goto cleanup;
    }
    if (identity_length == 0U
        || sscanf(
            identity,
            "WLS-CONTROLLER-PROCESS/2\n"
            "pid=%lu\nstart_id=%llu\nslot=%1[AB]\n"
            "runtime_generation=%64[0-9a-f]\n"
            "fencing_digest=%64[0-9a-f]\n%n",
            &parsed_pid,
            &parsed_start,
            slot,
            runtime_generation,
            recorded_fencing_digest,
            &consumed
        ) != 5
        || consumed != (int)identity_length
        || parsed_pid == 0UL || parsed_pid > (unsigned long)INT_MAX
        || parsed_start == 0ULL
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(recorded_fencing_digest, 64U)
        || wls_controller_slot_runtime_generation(
            home, slot[0], manifest_generation
        ) != 0
        || strcmp(runtime_generation, manifest_generation) != 0
        || snprintf(
            expected_binary,
            sizeof(expected_binary),
            "%s/slots/%c/bin/php",
            home,
            slot[0]
        ) >= (int)sizeof(expected_binary)) {
        errno = EINVAL;
        goto cleanup;
    }
    wls_sha256_hex(
        (const unsigned char *)new_fencing,
        strlen(new_fencing),
        new_fencing_digest
    );
    if (strcmp(recorded_fencing_digest, new_fencing_digest) == 0) {
        errno = EEXIST;
        goto cleanup;
    }
    if (wls_process_identity(
            (pid_t)parsed_pid,
            actual_binary,
            sizeof(actual_binary),
            &actual_start
        ) != 0) {
        result = wls_pid_is_gone((pid_t)parsed_pid) ? 0 : -1;
        goto cleanup;
    }
    if (actual_start != parsed_start
        || strcmp(actual_binary, expected_binary) != 0) {
        // A different birth at the same PID proves the recorded Controller is
        // gone. Never signal the replacement process.
        result = actual_start != parsed_start ? 0 : -1;
        goto cleanup;
    }
    for (
        attempt = 0U;
        attempt < WLS_PREVIOUS_CONTROLLER_EXIT_ATTEMPTS;
        attempt++
    ) {
        if (usleep(WLS_PREVIOUS_CONTROLLER_EXIT_POLL_US) != 0
            && errno != EINTR) goto cleanup;
        if (wls_process_identity(
                (pid_t)parsed_pid,
                actual_binary,
                sizeof(actual_binary),
                &actual_start
            ) != 0) {
            if (wls_pid_is_gone((pid_t)parsed_pid)) {
                result = 0;
                goto cleanup;
            }
            continue;
        }
        if (actual_start != parsed_start) {
            result = 0;
            goto cleanup;
        }
        if (strcmp(actual_binary, expected_binary) != 0) goto cleanup;
    }
    errno = ETIMEDOUT;
cleanup:
    sodium_memzero(recorded_fencing_digest, sizeof(recorded_fencing_digest));
    sodium_memzero(new_fencing_digest, sizeof(new_fencing_digest));
    if (identity != NULL) {
        sodium_memzero(identity, identity_length);
        free(identity);
    }
    if (pid_contents != NULL) {
        sodium_memzero(pid_contents, pid_length);
        free(pid_contents);
    }
    if (home_fd >= 0) close(home_fd);
    return result;
}

static int wls_process_attest_v2(
    const char *home,
    const char *pid_text,
    const char *start_text,
    const char *expected_binary_digest,
    const char *runtime_generation,
    const char *expected_config_digest,
    const char *expected_config_path_digest,
    const char *publication_text,
    char *reply,
    size_t reply_capacity
) {
#if defined(__linux__) || defined(__APPLE__)
    unsigned long parsed_pid = 0U;
    unsigned long publication = 0U;
    unsigned long actual_publication = 0U;
    unsigned long long expected_start;
    int expected_start_known;
    unsigned long long actual_start;
    char *start_end;
    char binary_path[PATH_MAX];
    char verified_binary_path[PATH_MAX];
    char expected_binary_a[PATH_MAX];
    char expected_binary_b[PATH_MAX];
    char expected_prefix[PATH_MAX];
    char config_path[PATH_MAX];
    char manifest_relative[PATH_MAX];
    char receipt_path[PATH_MAX];
    char binary_digest[65];
    char config_digest[65];
    char state_config_digest[65];
    char manifest_runtime_generation[65];
    char config_path_digest[65];
    char receipt_digest[65];
    char receipt[2048];
    char *state = NULL;
    char *manifest = NULL;
    size_t state_length = 0U;
    size_t manifest_length = 0U;
    uint64_t binary_size = 0U;
    uint64_t config_size = 0U;
    unsigned long long verified_start = 0ULL;
    int home_fd = -1;
    int binary_fd = -1;
    int config_fd = -1;
    struct stat config_before;
    struct stat config_after;
    struct stat config_path_status;
    int written;
    const char *slot;
    if (wls_parse_unsigned(pid_text, 0, &parsed_pid) != 0
        || parsed_pid > (unsigned long)INT_MAX
        || !wls_is_hex(expected_binary_digest, 64U)
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(expected_config_digest, 64U)
        || !wls_is_hex(expected_config_path_digest, 64U)
        || wls_parse_unsigned(publication_text, 1, &publication) != 0) goto denied;
    expected_start_known = strcmp(start_text, "-") != 0;
    expected_start = 0ULL;
    start_end = NULL;
    if (expected_start_known) {
        errno = 0;
        expected_start = strtoull(start_text, &start_end, 10);
    }
    if ((expected_start_known
            && (errno != 0 || start_end == start_text || *start_end != '\0'
                || expected_start == 0ULL))
        || wls_process_identity(
            (pid_t)parsed_pid, binary_path, sizeof(binary_path), &actual_start
        ) != 0
        || (expected_start_known && actual_start != expected_start)) goto denied;
    if (snprintf(
            expected_binary_a, sizeof(expected_binary_a),
            "%s/slots/A/bin/nginx", home
        ) >= (int)sizeof(expected_binary_a)
        || snprintf(
            expected_binary_b, sizeof(expected_binary_b),
            "%s/slots/B/bin/nginx", home
        ) >= (int)sizeof(expected_binary_b)
        || snprintf(
            expected_prefix, sizeof(expected_prefix), "%s/runtime/", home
        ) >= (int)sizeof(expected_prefix)
        || snprintf(config_path, sizeof(config_path), "%s/runtime/conf/nginx.conf", home)
            >= (int)sizeof(config_path)) goto denied;
    if (strcmp(binary_path, expected_binary_a) == 0) slot = "A";
    else if (strcmp(binary_path, expected_binary_b) == 0) slot = "B";
    else goto denied;
    if (wls_process_command_matches(
            (pid_t)parsed_pid,
            binary_path,
            expected_prefix,
            config_path
        ) != 0
        || wls_open_live_binary(
            (pid_t)parsed_pid, binary_path, &binary_fd
        ) != 0
        || wls_file_digest_fd(binary_fd, binary_digest, &binary_size) != 0
        || binary_size == 0U
        || strcmp(binary_digest, expected_binary_digest) != 0
        || wls_process_identity(
            (pid_t)parsed_pid, verified_binary_path,
            sizeof(verified_binary_path), &verified_start
        ) != 0
        || verified_start != actual_start
        || strcmp(verified_binary_path, binary_path) != 0
        || wls_process_command_matches(
            (pid_t)parsed_pid,
            binary_path,
            expected_prefix,
            config_path
        ) != 0) goto denied;
    wls_sha256_hex(
        (const unsigned char *)config_path, strlen(config_path), config_path_digest
    );
    if (strcmp(config_path_digest, expected_config_path_digest) != 0) goto denied;
    home_fd = wls_open_absolute_directory(home);
    if (home_fd < 0) goto denied;
    config_fd = wls_open_relative(home_fd, "runtime/conf/nginx.conf", O_RDONLY);
    if (config_fd < 0
        || fstat(config_fd, &config_before) != 0
        || !S_ISREG(config_before.st_mode)
        || config_before.st_nlink != 1
        || (config_before.st_mode & 0022) != 0
        || fstatat(
            home_fd,
            "runtime/conf/nginx.conf",
            &config_path_status,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || !S_ISREG(config_path_status.st_mode)
        || config_path_status.st_dev != config_before.st_dev
        || config_path_status.st_ino != config_before.st_ino
        || wls_file_digest_fd(config_fd, config_digest, &config_size) != 0
        || config_size == 0U
        || strcmp(config_digest, expected_config_digest) != 0
        || wls_read_regular_at(
            home_fd, "state/gateway-state.json", WLS_MAX_REQUEST,
            &state, &state_length
        ) != 0
        || wls_json_unsigned(
            state, "active_config_generation", &actual_publication
        ) != 0
        || actual_publication != publication
        || wls_json_hex_field(
            state,
            "active_config_digest",
            64U,
            state_config_digest
        ) != 0
        || strcmp(state_config_digest, config_digest) != 0
        || snprintf(
            manifest_relative, sizeof(manifest_relative),
            "slots/%s/manifest.json", slot
        ) >= (int)sizeof(manifest_relative)
        || wls_read_regular_at(
            home_fd, manifest_relative, WLS_MAX_REQUEST,
            &manifest, &manifest_length
        ) != 0
        || wls_json_hex_field(
            manifest,
            "runtime_generation",
            64U,
            manifest_runtime_generation
        ) != 0
        || strcmp(manifest_runtime_generation, runtime_generation) != 0
        || fstat(config_fd, &config_after) != 0
        || fstatat(
            home_fd,
            "runtime/conf/nginx.conf",
            &config_path_status,
            AT_SYMLINK_NOFOLLOW
        ) != 0
        || config_after.st_dev != config_before.st_dev
        || config_after.st_ino != config_before.st_ino
        || config_after.st_size != config_before.st_size
        || config_after.st_mode != config_before.st_mode
        || config_after.st_nlink != config_before.st_nlink
        || config_path_status.st_dev != config_before.st_dev
        || config_path_status.st_ino != config_before.st_ino
#if defined(__APPLE__) || defined(__FreeBSD__)
        || config_after.st_mtimespec.tv_sec != config_before.st_mtimespec.tv_sec
        || config_after.st_mtimespec.tv_nsec != config_before.st_mtimespec.tv_nsec
        || config_after.st_ctimespec.tv_sec != config_before.st_ctimespec.tv_sec
        || config_after.st_ctimespec.tv_nsec != config_before.st_ctimespec.tv_nsec
#else
        || config_after.st_mtim.tv_sec != config_before.st_mtim.tv_sec
        || config_after.st_mtim.tv_nsec != config_before.st_mtim.tv_nsec
        || config_after.st_ctim.tv_sec != config_before.st_ctim.tv_sec
        || config_after.st_ctim.tv_nsec != config_before.st_ctim.tv_nsec
#endif
        || wls_process_identity(
            (pid_t)parsed_pid,
            verified_binary_path,
            sizeof(verified_binary_path),
            &verified_start
        ) != 0
        || verified_start != actual_start
        || strcmp(verified_binary_path, binary_path) != 0
        || wls_process_command_matches(
            (pid_t)parsed_pid,
            binary_path,
            expected_prefix,
            config_path
        ) != 0) goto denied;
    written = snprintf(
        receipt, sizeof(receipt),
        "WLS-PROCESS-ATTEST/2\npid=%lu\nstart_id=%llu\n"
        "binary_digest=%s\nruntime_generation=%s\nconfig_digest=%s\n"
        "config_path_digest=%s\npublication_generation=%lu\n",
        parsed_pid, actual_start, binary_digest, runtime_generation,
        config_digest, config_path_digest, actual_publication
    );
    if (written <= 0 || written >= (int)sizeof(receipt)
        || snprintf(
            receipt_path, sizeof(receipt_path),
            "%s/trust/process-attestation.receipt", home
        ) >= (int)sizeof(receipt_path)
        || wls_atomic_root_write(receipt_path, receipt, (size_t)written) != 0) goto denied;
    wls_sha256_hex((const unsigned char *)receipt, (size_t)written, receipt_digest);
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tPROCESS_ATTEST\t%s\t%lu\t%llu\t%s\t%s\t%s\t%s\t%lu\n",
        receipt_digest, parsed_pid, actual_start, binary_digest,
        runtime_generation, config_digest, config_path_digest, actual_publication
    );
    if (written <= 0 || written >= (int)reply_capacity) goto denied;
    if (manifest != NULL) { sodium_memzero(manifest, manifest_length); free(manifest); }
    if (state != NULL) { sodium_memzero(state, state_length); free(state); }
    if (config_fd >= 0) close(config_fd);
    if (binary_fd >= 0) close(binary_fd);
    if (home_fd >= 0) close(home_fd);
    return 0;
denied:
    if (manifest != NULL) { sodium_memzero(manifest, manifest_length); free(manifest); }
    if (state != NULL) { sodium_memzero(state, state_length); free(state); }
    if (config_fd >= 0) close(config_fd);
    if (binary_fd >= 0) close(binary_fd);
    if (home_fd >= 0) close(home_fd);
    return wls_security_reply_error(
        reply, reply_capacity, "PROCESS_ATTEST_FAILED", "PROCESS_ATTEST", NULL, NULL
    );
#else
    (void)home; (void)pid_text; (void)start_text;
    (void)expected_binary_digest; (void)runtime_generation;
    (void)expected_config_digest; (void)expected_config_path_digest;
    (void)publication_text;
    return wls_security_reply_error(
        reply, reply_capacity, "UNSUPPORTED_PLATFORM", "PROCESS_ATTEST", NULL, NULL
    );
#endif
}

static int wls_owned_nginx_alive(
    const char *home,
    const struct wls_bootstrap_receipt *health
) {
#if defined(__linux__) || defined(__APPLE__)
    char path[PATH_MAX];
    char expected_binary[PATH_MAX];
    char observed_binary[PATH_MAX];
    unsigned char *contents = NULL;
    size_t length = 0U;
    unsigned long pid = 0U;
    unsigned long publication = 0U;
    unsigned long long start_id = 0ULL;
    unsigned long long observed_start = 0ULL;
    char binary_digest[65];
    char runtime_generation[65];
    char config_digest[65];
    char config_path_digest[65];
    int consumed = 0;
    int result = 0;
    if (home == NULL || health == NULL
        || snprintf(
            path, sizeof(path), "%s/trust/process-attestation.receipt", home
        ) >= (int)sizeof(path)
        || snprintf(
            expected_binary,
            sizeof(expected_binary),
            "%s/slots/%c/bin/nginx",
            home,
            health->active_slot[0]
        ) >= (int)sizeof(expected_binary)
        || wls_read_file(path, 2048U, &contents, &length) != 0) {
        return 0;
    }
    if (sscanf(
            (const char *)contents,
            "WLS-PROCESS-ATTEST/2\npid=%lu\nstart_id=%llu\n"
            "binary_digest=%64[0-9a-f]\nruntime_generation=%64[0-9a-f]\n"
            "config_digest=%64[0-9a-f]\nconfig_path_digest=%64[0-9a-f]\n"
            "publication_generation=%lu\n%n",
            &pid,
            &start_id,
            binary_digest,
            runtime_generation,
            config_digest,
            config_path_digest,
            &publication,
            &consumed
        ) == 7
        && consumed == (int)length
        && pid > 0U
        && pid <= (unsigned long)INT_MAX
        && publication == health->active_config_generation
        && strcmp(runtime_generation, health->runtime_generation) == 0
        && wls_process_identity(
            (pid_t)pid,
            observed_binary,
            sizeof(observed_binary),
            &observed_start
        ) == 0
        && observed_start == start_id
        && strcmp(observed_binary, expected_binary) == 0) {
        result = 1;
    }
    sodium_memzero(contents, length);
    free(contents);
    return result;
#else
    (void)home;
    (void)health;
    return 0;
#endif
}

struct wls_emergency_binding {
    char project[37];
    char tx[33];
    char intent[65];
    unsigned long security_generation;
    unsigned long owner;
    char credential_id[33];
    unsigned long credential_generation;
    char secret[65];
};

static int wls_emergency_hmac_hex(
    const char *secret,
    const char *message,
    char output[65]
) {
    crypto_auth_hmacsha256_state state;
    unsigned char digest[crypto_auth_hmacsha256_BYTES];
    if (!wls_is_hex(secret, 64U) || message == NULL
        || crypto_auth_hmacsha256_init(
            &state, (const unsigned char *)secret, strlen(secret)
        ) != 0
        || crypto_auth_hmacsha256_update(
            &state, (const unsigned char *)message, strlen(message)
        ) != 0
        || crypto_auth_hmacsha256_final(&state, digest) != 0) {
        sodium_memzero(&state, sizeof(state));
        sodium_memzero(digest, sizeof(digest));
        return -1;
    }
    sodium_bin2hex(output, 65U, digest, sizeof(digest));
    sodium_memzero(&state, sizeof(state));
    sodium_memzero(digest, sizeof(digest));
    return 0;
}

static int wls_emergency_file_open_locked(
    const char *home,
    const char *relative,
    mode_t mode,
    const struct wls_controller_identity *controller_identity,
    int *fd,
    char *path,
    size_t path_capacity
) {
    struct stat status;
    int written;
    if (home == NULL || relative == NULL || fd == NULL || path == NULL) return -1;
    written = snprintf(path, path_capacity, "%s/%s", home, relative);
    if (written <= 0 || written >= (int)path_capacity) return -1;
    *fd = open(path, O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW, mode);
    if (*fd < 0 || flock(*fd, LOCK_EX) != 0) goto denied;
    if (controller_identity != NULL && geteuid() == 0
        && fchown(*fd, geteuid(), controller_identity->gid) != 0) goto denied;
    if (fchmod(*fd, mode) != 0 || fstat(*fd, &status) != 0
        || !S_ISREG(status.st_mode) || status.st_nlink != 1
        || status.st_uid != geteuid() || (status.st_mode & 0007) != 0
        || ((mode & 0040) == 0 && (status.st_mode & 0077) != 0)
        || ((mode & 0040) != 0 && (status.st_mode & 0037) != 0)
        || (controller_identity != NULL
            && status.st_gid != controller_identity->gid)
        || status.st_size < 0
        || (uint64_t)status.st_size > WLS_MAX_REGISTRY) goto denied;
    return 0;
denied:
    if (*fd >= 0) {
        (void)flock(*fd, LOCK_UN);
        close(*fd);
    }
    *fd = -1;
    errno = EPERM;
    return -1;
}

static int wls_emergency_parse_binding(
    char **fields,
    size_t count,
    struct wls_emergency_binding *binding
) {
    if (fields == NULL || binding == NULL || count != 9U
        || strcmp(fields[0], "B") != 0 || !wls_is_uuid(fields[1])
        || !wls_is_hex(fields[2], 32U) || !wls_is_hex(fields[3], 64U)
        || wls_parse_unsigned(fields[4], 0, &binding->security_generation) != 0
        || wls_parse_unsigned(fields[5], 1, &binding->owner) != 0
        || !wls_is_hex(fields[6], 32U)
        || wls_parse_unsigned(fields[7], 0, &binding->credential_generation) != 0
        || !wls_is_hex(fields[8], 64U)) return -1;
    memset(binding, 0, sizeof(*binding));
    if (wls_parse_unsigned(fields[4], 0, &binding->security_generation) != 0
        || wls_parse_unsigned(fields[5], 1, &binding->owner) != 0
        || wls_parse_unsigned(fields[7], 0, &binding->credential_generation) != 0) {
        return -1;
    }
    strcpy(binding->project, fields[1]);
    strcpy(binding->tx, fields[2]);
    strcpy(binding->intent, fields[3]);
    strcpy(binding->credential_id, fields[6]);
    strcpy(binding->secret, fields[8]);
    return 0;
}

static int wls_emergency_collect_binding(
    char *contents,
    const char *project,
    struct wls_emergency_binding *selected,
    int *found
) {
    char *cursor = contents;
    *found = 0;
    while (cursor != NULL && cursor[0] != '\0') {
        char *newline = strchr(cursor, '\n');
        char *fields[12];
        size_t count = 0U;
        struct wls_emergency_binding parsed;
        if (newline == NULL) return -1;
        *newline = '\0';
        if (wls_split_tsv(cursor, fields, 12U, &count) != 0
            || wls_emergency_parse_binding(fields, count, &parsed) != 0) return -1;
        if (strcmp(parsed.project, project) == 0
            && (!*found
                || parsed.credential_generation > selected->credential_generation)) {
            *selected = parsed;
            *found = 1;
        }
        cursor = newline + 1U;
    }
    return 0;
}

static int wls_emergency_bind_v1(
    const char *home,
    const char *project,
    const char *tx,
    const char *intent,
    const char *security_generation_text,
    const char *owner_text,
    const char *credential_id,
    const char *credential_generation_text,
    const char *secret,
    const struct wls_controller_identity *controller_identity,
    char *reply,
    size_t reply_capacity
) {
    struct wls_auth_root_v2 roots[WLS_MAX_AUTH_ROOTS];
    struct wls_emergency_binding existing;
    unsigned long security_generation = 0U;
    unsigned long owner = 0U;
    unsigned long credential_generation = 0U;
    size_t root_count = 0U;
    size_t index;
    int committed = 0;
    int aborted = 0;
    int found = 0;
    char committed_digest[65] = {0};
    char security_path[PATH_MAX];
    char binding_path[PATH_MAX];
    char *security_contents = NULL;
    char *binding_contents = NULL;
    size_t security_length = 0U;
    size_t binding_length = 0U;
    char record[512];
    char ledger_digest[65];
    int security_fd = -1;
    int binding_fd = -1;
    int written;
    if (!wls_is_uuid(project) || !wls_is_hex(tx, 32U)
        || !wls_is_hex(intent, 64U)
        || wls_parse_unsigned(
            security_generation_text, 0, &security_generation
        ) != 0
        || wls_parse_unsigned(owner_text, 1, &owner) != 0
        || !wls_is_hex(credential_id, 32U)
        || wls_parse_unsigned(
            credential_generation_text, 0, &credential_generation
        ) != 0
        || !wls_is_hex(secret, 64U)
        || wls_security_open_locked(
            home, &security_fd, security_path, sizeof(security_path)
        ) != 0
        || wls_security_read_locked(
            security_fd, &security_contents, &security_length
        ) != 0
        || wls_auth_collect(
            security_contents, project, tx, intent, roots, WLS_MAX_AUTH_ROOTS,
            &root_count, &committed, &aborted, committed_digest
        ) != 0 || !committed || aborted || root_count == 0U) goto invalid;
    for (index = 0U; index < root_count; index++) {
        if (roots[index].assigned != security_generation
            || roots[index].owner != owner) goto denied;
    }
    sodium_memzero(security_contents, security_length);
    free(security_contents); security_contents = NULL;
    (void)flock(security_fd, LOCK_UN); close(security_fd); security_fd = -1;
    if (wls_emergency_file_open_locked(
            home, "trust/emergency-credentials-v1.tsv", 0600,
            controller_identity, &binding_fd, binding_path, sizeof(binding_path)
        ) != 0
        || wls_security_read_locked(
            binding_fd, &binding_contents, &binding_length
        ) != 0
        || wls_emergency_collect_binding(
            binding_contents, project, &existing, &found
        ) != 0) goto invalid;
    if (found && credential_generation < existing.credential_generation) goto denied;
    if (found && credential_generation == existing.credential_generation) {
        if (strcmp(existing.tx, tx) != 0 || strcmp(existing.intent, intent) != 0
            || existing.security_generation != security_generation
            || existing.owner != owner
            || strcmp(existing.credential_id, credential_id) != 0
            || strcmp(existing.secret, secret) != 0) goto denied;
    } else {
        written = snprintf(
            record, sizeof(record), "B\t%s\t%s\t%s\t%lu\t%lu\t%s\t%lu\t%s\n",
            project, tx, intent, security_generation, owner, credential_id,
            credential_generation, secret
        );
        if (written <= 0 || written >= (int)sizeof(record)
            || wls_security_append_locked(binding_fd, binding_path, record) != 0) {
            goto invalid;
        }
    }
    sodium_memzero(binding_contents, binding_length);
    free(binding_contents); binding_contents = NULL;
    if (lseek(binding_fd, 0, SEEK_SET) < 0
        || wls_security_read_locked(
            binding_fd, &binding_contents, &binding_length
        ) != 0) goto invalid;
    wls_sha256_hex(
        (const unsigned char *)binding_contents, binding_length, ledger_digest
    );
    written = snprintf(
        reply, reply_capacity,
        "WLS-ACTION/2\tOK\tEMERGENCY_BIND\t%s\t%s\t%lu\t%lu\t%s\n",
        tx, intent, security_generation, credential_generation, ledger_digest
    );
    if (written <= 0 || written >= (int)reply_capacity) goto invalid;
    sodium_memzero(binding_contents, binding_length); free(binding_contents);
    (void)flock(binding_fd, LOCK_UN); close(binding_fd);
    return 0;
denied:
    (void)wls_security_reply_error(
        reply, reply_capacity, "BINDING_CONFLICT", "EMERGENCY_BIND", tx, intent
    );
    goto fail;
invalid:
    (void)wls_security_reply_error(
        reply, reply_capacity, "LEDGER_INVALID", "EMERGENCY_BIND", tx, intent
    );
fail:
    if (security_contents != NULL) {
        sodium_memzero(security_contents, security_length); free(security_contents);
    }
    if (binding_contents != NULL) {
        sodium_memzero(binding_contents, binding_length); free(binding_contents);
    }
    if (security_fd >= 0) {
        (void)flock(security_fd, LOCK_UN); close(security_fd);
    }
    if (binding_fd >= 0) {
        (void)flock(binding_fd, LOCK_UN); close(binding_fd);
    }
    return -1;
}

static int wls_emergency_domain(const char *domain)
{
    const char *cursor = domain;
    const char *label;
    size_t length;
    if (domain == NULL || domain[0] == '\0' || strlen(domain) > 253U) return 0;
    if (strncmp(cursor, "*.", 2U) == 0) cursor += 2U;
    label = cursor;
    while (1) {
        const char *end = strchr(label, '.');
        size_t label_length = end == NULL ? strlen(label) : (size_t)(end - label);
        size_t index;
        if (label_length == 0U || label_length > 63U
            || label[0] == '-' || label[label_length - 1U] == '-') return 0;
        for (index = 0U; index < label_length; index++) {
            if (!((label[index] >= 'a' && label[index] <= 'z')
                || (label[index] >= '0' && label[index] <= '9')
                || label[index] == '-')) return 0;
        }
        if (end == NULL) break;
        label = end + 1U;
    }
    length = strlen(cursor);
    return length > 0U && cursor[length - 1U] != '.';
}

static int wls_stop_attested_nginx(
    const char *home,
    unsigned long *stopped_pid,
    unsigned long long *stopped_start
) {
    char receipt_path[PATH_MAX];
    char pid_path[PATH_MAX];
    char expected_a[PATH_MAX];
    char expected_b[PATH_MAX];
    char observed[PATH_MAX];
    unsigned char *contents = NULL;
    size_t length = 0U;
    unsigned long pid = 0U;
    unsigned long publication = 0U;
    unsigned long long start_id = 0ULL;
    unsigned long long observed_start = 0ULL;
    char binary_digest[65];
    char runtime_generation[65];
    char config_digest[65];
    char config_path_digest[65];
    int consumed = 0;
    unsigned int attempt;
    pid_t group;
    struct stat pid_status;
    *stopped_pid = 0U;
    *stopped_start = 0ULL;
    if (snprintf(
            receipt_path, sizeof(receipt_path),
            "%s/trust/process-attestation.receipt", home
        ) >= (int)sizeof(receipt_path)
        || snprintf(pid_path, sizeof(pid_path), "%s/runtime/nginx.pid", home)
            >= (int)sizeof(pid_path)
        || snprintf(expected_a, sizeof(expected_a), "%s/slots/A/bin/nginx", home)
            >= (int)sizeof(expected_a)
        || snprintf(expected_b, sizeof(expected_b), "%s/slots/B/bin/nginx", home)
            >= (int)sizeof(expected_b)) return -1;
    if (wls_read_file(receipt_path, 2048U, &contents, &length) != 0) {
        if (lstat(pid_path, &pid_status) != 0 && errno == ENOENT) return 0;
        return -1;
    }
    if (sscanf(
            (const char *)contents,
            "WLS-PROCESS-ATTEST/2\npid=%lu\nstart_id=%llu\n"
            "binary_digest=%64[0-9a-f]\nruntime_generation=%64[0-9a-f]\n"
            "config_digest=%64[0-9a-f]\nconfig_path_digest=%64[0-9a-f]\n"
            "publication_generation=%lu\n%n",
            &pid, &start_id, binary_digest, runtime_generation, config_digest,
            config_path_digest, &publication, &consumed
        ) != 7 || consumed != (int)length || pid == 0U
        || pid > (unsigned long)INT_MAX) goto denied;
    sodium_memzero(contents, length); free(contents); contents = NULL;
    if (wls_process_identity(
            (pid_t)pid, observed, sizeof(observed), &observed_start
        ) != 0) {
        if (errno == ESRCH) {
            *stopped_pid = pid; *stopped_start = start_id; return 0;
        }
        return -1;
    }
    if (observed_start != start_id
        || (strcmp(observed, expected_a) != 0 && strcmp(observed, expected_b) != 0)) {
        return -1;
    }
    *stopped_pid = pid;
    *stopped_start = start_id;
    group = getpgid((pid_t)pid);
    if (group == (pid_t)pid) {
        if (kill(-(pid_t)pid, SIGTERM) != 0 && errno != ESRCH) return -1;
    } else if (kill((pid_t)pid, SIGTERM) != 0 && errno != ESRCH) {
        return -1;
    }
    for (attempt = 0U; attempt < 100U; attempt++) {
        int master_gone = wls_process_identity(
            (pid_t)pid, observed, sizeof(observed), &observed_start
        ) != 0 && errno == ESRCH;
        int group_gone = group != (pid_t)pid
            || (kill(-(pid_t)pid, 0) != 0 && errno == ESRCH);
        if (master_gone && group_gone) return 0;
        (void)usleep(50000U);
    }
    if (group == (pid_t)pid) {
        if (kill(-(pid_t)pid, SIGKILL) != 0 && errno != ESRCH) return -1;
    } else if (kill((pid_t)pid, SIGKILL) != 0 && errno != ESRCH) {
        return -1;
    }
    for (attempt = 0U; attempt < 100U; attempt++) {
        int master_gone = wls_process_identity(
            (pid_t)pid, observed, sizeof(observed), &observed_start
        ) != 0 && errno == ESRCH;
        int group_gone = group != (pid_t)pid
            || (kill(-(pid_t)pid, 0) != 0 && errno == ESRCH);
        if (master_gone && group_gone) return 0;
        (void)usleep(50000U);
    }
    return -1;
denied:
    if (contents != NULL) {
        sodium_memzero(contents, length); free(contents);
    }
    return -1;
}

static int wls_handle_emergency_revocation(
    char *line,
    const char *home,
    const char *fencing,
    const struct wls_controller_identity *controller_identity,
    const struct wls_peer *peer,
    char *reply,
    size_t reply_capacity
) {
    char *fields[16];
    size_t count = 0U;
    size_t length;
    struct wls_emergency_binding binding;
    unsigned long credential_generation = 0U;
    unsigned long generation = 0U;
    unsigned long timestamp = 0U;
    unsigned long highest_sequence = 0U;
    unsigned long sequence = 0U;
    unsigned long highest_domain_generation = 0U;
    unsigned long stopped_pid = 0U;
    unsigned long long stopped_start = 0ULL;
    int binding_found = 0;
    int duplicate_found = 0;
    int duplicate_acked = 0;
    char domain[254];
    char tombstone[512];
    char tombstone_digest[65];
    char canonical[2048];
    char expected_hmac[65];
    char response_hmac[65];
    char binding_path[PATH_MAX];
    char revocation_path[PATH_MAX];
    char *binding_contents = NULL;
    char *revocation_contents = NULL;
    size_t binding_length = 0U;
    size_t revocation_length = 0U;
    int binding_fd = -1;
    int revocation_fd = -1;
    int written;
    time_t now = time(NULL);
    if (line == NULL || peer == NULL || reply == NULL || fencing == NULL) return -1;
    length = strlen(line);
    if (length < 2U || line[length - 1U] != '\n'
        || memchr(line, '\r', length) != NULL
        || memchr(line, '\n', length - 1U) != NULL) return -1;
    line[length - 1U] = '\0';
    if (wls_split_tsv(line, fields, 16U, &count) != 0 || count != 12U
        || strcmp(fields[0], "WLS-EDGE-EMERGENCY/1") != 0
        || strcmp(fields[1], "REVOKE") != 0 || !wls_is_uuid(fields[2])
        || !wls_is_hex(fields[3], 32U)
        || wls_parse_unsigned(fields[4], 0, &credential_generation) != 0
        || strlen(fields[5]) < 2U || strlen(fields[5]) > 506U
        || (strlen(fields[5]) & 1U) != 0U
        || !wls_is_hex(fields[5], strlen(fields[5]))
        || wls_parse_unsigned(fields[6], 0, &generation) != 0
        || !wls_is_hex(fields[7], 64U) || !wls_is_hex(fields[8], 32U)
        || wls_parse_unsigned(fields[9], 0, &timestamp) != 0
        || !wls_is_hex(fields[10], 32U) || !wls_is_hex(fields[11], 64U)
        || wls_hex_decode(fields[5], domain, sizeof(domain)) != 0
        || !wls_emergency_domain(domain)
        || now <= 0 || timestamp + 300U < (unsigned long)now
        || timestamp > (unsigned long)now + 300U) return -1;
    written = snprintf(
        tombstone, sizeof(tombstone), "wls-disabled-certificate%c%s%c%lu",
        '\0', domain, '\0', generation
    );
    if (written <= 0 || written >= (int)sizeof(tombstone)) return -1;
    wls_sha256_hex(
        (const unsigned char *)tombstone,
        strlen("wls-disabled-certificate") + 1U + strlen(domain) + 1U
            + strlen(fields[6]),
        tombstone_digest
    );
    if (strcmp(tombstone_digest, fields[7]) != 0) return -1;
    written = snprintf(
        canonical, sizeof(canonical), "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s",
        fields[0], fields[1], fields[2], fields[3], fields[4], fields[5],
        fields[6], fields[7], fields[8], fields[9], fields[10]
    );
    if (written <= 0 || written >= (int)sizeof(canonical)
        || wls_emergency_file_open_locked(
            home, "trust/emergency-credentials-v1.tsv", 0600,
            controller_identity, &binding_fd, binding_path, sizeof(binding_path)
        ) != 0
        || wls_security_read_locked(
            binding_fd, &binding_contents, &binding_length
        ) != 0
        || wls_emergency_collect_binding(
            binding_contents, fields[2], &binding, &binding_found
        ) != 0 || !binding_found
        || binding.owner != peer->uid
        || binding.credential_generation != credential_generation
        || strcmp(binding.credential_id, fields[3]) != 0
        || wls_emergency_hmac_hex(binding.secret, canonical, expected_hmac) != 0
        || sodium_memcmp(expected_hmac, fields[11], 64U) != 0) goto denied;
    sodium_memzero(binding_contents, binding_length);
    free(binding_contents); binding_contents = NULL;
    (void)flock(binding_fd, LOCK_UN); close(binding_fd); binding_fd = -1;
    if (wls_emergency_file_open_locked(
            home, "state/emergency-revocations-v1.tsv",
            controller_identity != NULL ? 0640 : 0600,
            controller_identity, &revocation_fd, revocation_path,
            sizeof(revocation_path)
        ) != 0
        || wls_security_read_locked(
            revocation_fd, &revocation_contents, &revocation_length
        ) != 0) goto denied;
    {
        char *cursor = revocation_contents;
        while (cursor[0] != '\0') {
            char *newline = strchr(cursor, '\n');
            char *row[16];
            size_t row_count = 0U;
            unsigned long row_sequence = 0U;
            unsigned long row_generation = 0U;
            unsigned long row_timestamp = 0U;
            unsigned long row_credential_generation = 0U;
            if (newline == NULL) goto denied;
            *newline = '\0';
            if (wls_split_tsv(cursor, row, 16U, &row_count) != 0) goto denied;
            if (strcmp(row[0], "R") == 0) {
                if (row_count != 11U
                    || wls_parse_unsigned(row[1], 0, &row_sequence) != 0
                    || !wls_is_uuid(row[2]) || !wls_is_hex(row[3], strlen(row[3]))
                    || wls_parse_unsigned(row[4], 0, &row_generation) != 0
                    || !wls_is_hex(row[5], 64U) || !wls_is_hex(row[6], 32U)
                    || wls_parse_unsigned(row[7], 0, &row_timestamp) != 0
                    || !wls_is_hex(row[8], 32U) || !wls_is_hex(row[9], 32U)
                    || wls_parse_unsigned(
                        row[10], 0, &row_credential_generation
                    ) != 0 || row_sequence <= highest_sequence) goto denied;
                highest_sequence = row_sequence;
                if (strcmp(row[2], fields[2]) == 0
                    && strcmp(row[3], fields[5]) == 0) {
                    if (row_generation > highest_domain_generation) {
                        highest_domain_generation = row_generation;
                    }
                    if (strcmp(row[6], fields[8]) == 0) {
                        if (row_generation != generation
                            || strcmp(row[5], fields[7]) != 0
                            || strcmp(row[8], fields[10]) != 0
                            || strcmp(row[9], fields[3]) != 0
                            || row_credential_generation != credential_generation) {
                            goto denied;
                        }
                        duplicate_found = 1;
                        sequence = row_sequence;
                    } else if (strcmp(row[8], fields[10]) == 0) {
                        goto denied;
                    }
                }
            } else if (strcmp(row[0], "A") == 0) {
                unsigned long ack_sequence = 0U;
                unsigned long ack_pid = 0U;
                unsigned long ack_start = 0U;
                if (row_count != 6U
                    || wls_parse_unsigned(row[1], 0, &ack_sequence) != 0
                    || !wls_is_hex(row[2], 32U)
                    || wls_parse_unsigned(row[3], 1, &ack_pid) != 0
                    || wls_parse_unsigned(row[4], 1, &ack_start) != 0
                    || !wls_is_hex(row[5], 64U)) goto denied;
                if (duplicate_found && ack_sequence == sequence
                    && strcmp(row[2], fields[8]) == 0) duplicate_acked = 1;
            } else {
                goto denied;
            }
            cursor = newline + 1U;
        }
    }
    if (generation < highest_domain_generation) goto denied;
    if (!duplicate_found) {
        if (highest_sequence == ULONG_MAX) goto denied;
        sequence = highest_sequence + 1U;
        written = snprintf(
            canonical, sizeof(canonical),
            "R\t%lu\t%s\t%s\t%lu\t%s\t%s\t%lu\t%s\t%s\t%lu\n",
            sequence, fields[2], fields[5], generation, fields[7], fields[8],
            timestamp, fields[10], fields[3], credential_generation
        );
        if (written <= 0 || written >= (int)sizeof(canonical)
            || wls_security_append_locked(
                revocation_fd, revocation_path, canonical
            ) != 0) goto denied;
    }
    if (!duplicate_acked) {
        pid_t controller = (pid_t)wls_controller_pid;
        if (controller > 0 && kill(controller, SIGTERM) != 0 && errno != ESRCH) {
            goto denied;
        }
        if (wls_stop_attested_nginx(
                home, &stopped_pid, &stopped_start
            ) != 0) goto denied;
        written = snprintf(
            canonical, sizeof(canonical), "A\t%lu\t%s\t%lu\t%llu\t%s\n",
            sequence, fields[8], stopped_pid, stopped_start, fencing
        );
        if (written <= 0 || written >= (int)sizeof(canonical)
            || wls_security_append_locked(
                revocation_fd, revocation_path, canonical
            ) != 0) goto denied;
    }
    sodium_memzero(revocation_contents, revocation_length);
    free(revocation_contents); revocation_contents = NULL;
    if (lseek(revocation_fd, 0, SEEK_SET) < 0
        || wls_security_read_locked(
            revocation_fd, &revocation_contents, &revocation_length
        ) != 0) goto denied;
    wls_sha256_hex(
        (const unsigned char *)revocation_contents, revocation_length,
        tombstone_digest
    );
    written = snprintf(
        canonical, sizeof(canonical),
        "WLS-EDGE-EMERGENCY/1\tOK\t%s\t%s\t%s\t%lu\t%s\t%lu\t%s\t%s\t1\t1",
        fields[8], fields[2], fields[5], generation, fields[7], sequence,
        tombstone_digest, fencing
    );
    if (written <= 0 || written >= (int)sizeof(canonical)
        || wls_emergency_hmac_hex(binding.secret, canonical, response_hmac) != 0) {
        goto denied;
    }
    written = snprintf(
        reply, reply_capacity, "%s\t%s\n", canonical, response_hmac
    );
    if (written <= 0 || written >= (int)reply_capacity) goto denied;
    sodium_memzero(&binding, sizeof(binding));
    sodium_memzero(revocation_contents, revocation_length); free(revocation_contents);
    (void)flock(revocation_fd, LOCK_UN); close(revocation_fd);
    return 0;
denied:
    sodium_memzero(&binding, sizeof(binding));
    if (binding_contents != NULL) {
        sodium_memzero(binding_contents, binding_length); free(binding_contents);
    }
    if (revocation_contents != NULL) {
        sodium_memzero(revocation_contents, revocation_length); free(revocation_contents);
    }
    if (binding_fd >= 0) {
        (void)flock(binding_fd, LOCK_UN); close(binding_fd);
    }
    if (revocation_fd >= 0) {
        (void)flock(revocation_fd, LOCK_UN); close(revocation_fd);
    }
    return -1;
}

static int wls_handle_action_v2(
    char *line,
    const char *channel,
    const struct wls_peer *peer,
    const char *home,
    const struct wls_controller_identity *controller_identity,
    char *reply,
    size_t reply_capacity
) {
    char *fields[16];
    size_t count = 0U;
    size_t length;
    (void)controller_identity;
    if (line == NULL || reply == NULL || reply_capacity < 128U) return -1;
    length = strlen(line);
    if (length < 2U || line[length - 1U] != '\n'
        || memchr(line, '\r', length) != NULL
        || memchr(line, '\n', length - 1U) != NULL) return -1;
    line[length - 1U] = '\0';
    if (wls_split_tsv(line, fields, 16U, &count) != 0
        || count < 2U || strcmp(fields[0], "WLS-ACTION/2") != 0) return -1;
    if (strcmp(fields[1], "SNAP") == 0) {
        if (strcmp(channel, "project") != 0 || count != 9U) {
            return wls_security_reply_error(
                reply, reply_capacity, "DENIED", fields[1],
                count > 3U ? fields[3] : NULL,
                count > 4U ? fields[4] : NULL
            );
        }
        return wls_snapshot_enrolled_v2(
            home, peer, controller_identity, fields[2], fields[3], fields[4],
            fields[5], fields[6], fields[7], fields[8], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "SECURITY_ATTEST") == 0
        && (strcmp(channel, "admin") == 0 || strcmp(channel, "project") == 0)
        && count == 6U) {
        return wls_security_attest(
            home, fields[2], fields[3], fields[4], fields[5],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "PROCESS_ATTEST") == 0
        && (strcmp(channel, "admin") == 0 || strcmp(channel, "project") == 0)
        && count == 9U) {
        return wls_process_attest_v2(
            home, fields[2], fields[3], fields[4], fields[5],
            fields[6], fields[7], fields[8], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_TRANSFER_PREPARE") == 0 && count == 8U) {
        return wls_auth_transfer_prepare_v2(
            home, channel, peer, fields[2], fields[3], fields[4], fields[5],
            fields[6], fields[7], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_TRANSFER_COMMIT") == 0 && count == 8U) {
        return wls_auth_transfer_commit_v2(
            home, channel, peer, fields[2], fields[3], fields[4], fields[5],
            fields[6], fields[7], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_TRANSFER_ABORT") == 0 && count == 7U) {
        return wls_auth_transfer_abort_v2(
            home, channel, peer, fields[2], fields[3], fields[4], fields[5],
            fields[6], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_TRANSFER_ATTEST") == 0 && count == 7U) {
        return wls_auth_transfer_attest_v2(
            home, channel, peer, fields[2], fields[3], fields[4], fields[5],
            fields[6], reply, reply_capacity
        );
    }
    if (strcmp(channel, "admin") != 0
        || (peer->uid != 0U
            && !(geteuid() != 0 && peer->uid == (unsigned long)geteuid()))) {
        return wls_security_reply_error(
            reply, reply_capacity, "DENIED", fields[1],
            count > 2U ? fields[2] : NULL,
            count > 3U ? fields[3] : NULL
        );
    }
    if (strcmp(fields[1], "SECURITY_RESERVE") == 0 && count == 6U) {
        return wls_security_operation(
            home, fields[1], fields[2], fields[3], fields[4], fields[5], NULL,
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "SECURITY_COMMIT") == 0 && count == 7U) {
        return wls_security_operation(
            home, fields[1], fields[2], fields[3], fields[4], fields[5], fields[6],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "SECURITY_ABORT") == 0 && count == 6U) {
        return wls_security_operation(
            home, fields[1], fields[2], fields[3], fields[4], fields[5], NULL,
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_PREPARE") == 0 && count == 11U) {
        return wls_auth_prepare_v2(
            home, fields[2], fields[3], fields[4], fields[5], fields[6],
            fields[7], fields[8], fields[9], fields[10], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_COMMIT") == 0 && count == 7U) {
        return wls_auth_commit_v2(
            home, fields[2], fields[3], fields[4], fields[5], fields[6],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "AUTH_ABORT") == 0 && count == 5U) {
        return wls_auth_abort_v2(
            home, fields[2], fields[3], fields[4], reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "ATTEST_ROOT") == 0 && count == 6U) {
        return wls_auth_attest_root_v2(
            home, fields[2], fields[3], fields[4], fields[5],
            reply, reply_capacity
        );
    }
    if (strcmp(fields[1], "ATOMIC_REPLACE") == 0 && count == 7U) {
        return wls_atomic_replace_v2(
            home, fields[2], fields[3], fields[4], fields[5], fields[6],
            reply, reply_capacity
        );
    }
    return wls_security_reply_error(
        reply, reply_capacity, "UNSUPPORTED", fields[1],
        count > 2U ? fields[2] : NULL,
        count > 3U ? fields[3] : NULL
    );
}

static const char *wls_json_value(const char *json, const char *name)
{
    char needle[96];
    const char *field;
    const char *duplicate;
    if (json == NULL || name == NULL
        || snprintf(needle, sizeof(needle), "\"%s\"", name)
            >= (int)sizeof(needle)) {
        return NULL;
    }
    field = strstr(json, needle);
    if (field == NULL) return NULL;
    duplicate = strstr(field + strlen(needle), needle);
    if (duplicate != NULL) return NULL;
    field += strlen(needle);
    while (*field == ' ' || *field == '\t' || *field == '\r' || *field == '\n') field++;
    if (*field++ != ':') return NULL;
    while (*field == ' ' || *field == '\t' || *field == '\r' || *field == '\n') field++;
    return field;
}

static int wls_json_string(
    const char *json,
    const char *name,
    char *output,
    size_t capacity
) {
    const char *cursor = wls_json_value(json, name);
    size_t used = 0U;
    if (cursor == NULL || output == NULL || capacity < 2U || *cursor++ != '"') return -1;
    while (*cursor != '\0' && *cursor != '"') {
        unsigned char value = (unsigned char)*cursor++;
        if (value < 0x20U || value == '\\' || used + 1U >= capacity) return -1;
        output[used++] = (char)value;
    }
    if (*cursor != '"') return -1;
    output[used] = '\0';
    return 0;
}

static int wls_json_boolean(const char *json, const char *name, int *value)
{
    const char *cursor = wls_json_value(json, name);
    if (cursor == NULL || value == NULL) return -1;
    if (strncmp(cursor, "true", 4U) == 0
        && (cursor[4] == ',' || cursor[4] == '}'
            || cursor[4] == ' ' || cursor[4] == '\t'
            || cursor[4] == '\r' || cursor[4] == '\n')) {
        *value = 1;
        return 0;
    }
    if (strncmp(cursor, "false", 5U) == 0
        && (cursor[5] == ',' || cursor[5] == '}'
            || cursor[5] == ' ' || cursor[5] == '\t'
            || cursor[5] == '\r' || cursor[5] == '\n')) {
        *value = 0;
        return 0;
    }
    return -1;
}

static int wls_json_unsigned_long_long(
    const char *json,
    const char *name,
    unsigned long long *value
) {
    const char *cursor = wls_json_value(json, name);
    char *end = NULL;
    if (cursor == NULL || value == NULL || *cursor < '0' || *cursor > '9') return -1;
    errno = 0;
    *value = strtoull(cursor, &end, 10);
    if (errno != 0 || end == cursor) return -1;
    while (*end == ' ' || *end == '\t' || *end == '\r' || *end == '\n') end++;
    return *end == ',' || *end == '}' ? 0 : -1;
}

static int wls_verify_bootstrap_response(
    const char *response,
    const char *expected_request_id,
    const unsigned char key[crypto_auth_hmacsha256_KEYBYTES],
    struct wls_bootstrap_receipt *receipt
) {
    char protocol[32];
    char request_id[33];
    char epoch[33];
    char gateway_epoch[33];
    char controller_epoch[33];
    char data_plane[24];
    char active_slot[2];
    char runtime_generation[65];
    char host_boot_id[65];
    char intent_sha256[65];
    char intent_nonce[33];
    char signature[65];
    char expected_signature[65];
    char canonical[1024];
    unsigned char digest[crypto_auth_hmacsha256_BYTES];
    unsigned long long generation = 0ULL;
    unsigned long long publication_generation = 0ULL;
    unsigned long long active_config_generation = 0ULL;
    int ok = 0;
    int ready = 0;
    int recovery_pending = 1;
    int canonical_length;
    int result = -1;
    memset(digest, 0, sizeof(digest));
    if (response == NULL || expected_request_id == NULL || key == NULL
        || wls_json_string(response, "protocol", protocol, sizeof(protocol)) != 0
        || wls_json_string(response, "request_id", request_id, sizeof(request_id)) != 0
        || wls_json_string(response, "epoch", epoch, sizeof(epoch)) != 0
        || wls_json_string(response, "gateway_epoch", gateway_epoch, sizeof(gateway_epoch)) != 0
        || wls_json_string(response, "controller_epoch", controller_epoch, sizeof(controller_epoch)) != 0
        || wls_json_string(response, "data_plane", data_plane, sizeof(data_plane)) != 0
        || wls_json_string(response, "active_slot", active_slot, sizeof(active_slot)) != 0
        || wls_json_string(response, "runtime_generation", runtime_generation, sizeof(runtime_generation)) != 0
        || wls_json_string(response, "host_boot_id", host_boot_id, sizeof(host_boot_id)) != 0
        || wls_json_string(response, "upgrade_intent_sha256", intent_sha256, sizeof(intent_sha256)) != 0
        || wls_json_string(response, "upgrade_intent_nonce", intent_nonce, sizeof(intent_nonce)) != 0
        || wls_json_string(response, "signature", signature, sizeof(signature)) != 0
        || wls_json_boolean(response, "ok", &ok) != 0
        || wls_json_boolean(response, "ready", &ready) != 0
        || wls_json_boolean(response, "recovery_pending", &recovery_pending) != 0
        || wls_json_unsigned_long_long(response, "generation", &generation) != 0
        || wls_json_unsigned_long_long(
            response, "publication_generation", &publication_generation
        ) != 0
        || wls_json_unsigned_long_long(
            response, "active_config_generation", &active_config_generation
        ) != 0
        || strcmp(protocol, "wls-edge/2") != 0
        || strcmp(request_id, expected_request_id) != 0
        || !wls_is_hex(epoch, 32U)
        || !wls_is_hex(gateway_epoch, 32U)
        || strcmp(epoch, gateway_epoch) != 0
        || strcmp(epoch, controller_epoch) != 0
        || strcmp(data_plane, "RUNNING") != 0
        || (active_slot[0] != 'A' && active_slot[0] != 'B')
        || active_slot[1] != '\0'
        || !wls_is_hex(runtime_generation, 64U)
        || !wls_is_hex(intent_sha256, 64U)
        || !wls_is_hex(intent_nonce, 32U)
        || host_boot_id[0] == '\0'
        || active_config_generation != publication_generation
        || !wls_is_hex(signature, 64U)
        || !ok || !ready || recovery_pending) {
        goto cleanup;
    }
    canonical_length = snprintf(
        canonical,
        sizeof(canonical),
        "{\"epoch\":\"%s\",\"ok\":true,\"payload\":{"
        "\"active_config_generation\":%llu,\"active_slot\":\"%s\","
        "\"controller_epoch\":\"%s\",\"data_plane\":\"RUNNING\","
        "\"gateway_epoch\":\"%s\",\"generation\":%llu,"
        "\"host_boot_id\":\"%s\",\"publication_generation\":%llu,"
        "\"ready\":true,\"recovery_pending\":false,"
        "\"runtime_generation\":\"%s\",\"upgrade_intent_nonce\":\"%s\","
        "\"upgrade_intent_sha256\":\"%s\"},\"protocol\":\"wls-edge/2\","
        "\"request_id\":\"%s\"}",
        epoch,
        active_config_generation,
        active_slot,
        controller_epoch,
        gateway_epoch,
        generation,
        host_boot_id,
        publication_generation,
        runtime_generation,
        intent_nonce,
        intent_sha256,
        request_id
    );
    if (canonical_length <= 0 || canonical_length >= (int)sizeof(canonical)
        || crypto_auth_hmacsha256(
            digest,
            (const unsigned char *)canonical,
            (unsigned long long)canonical_length,
            key
        ) != 0) {
        goto cleanup;
    }
    sodium_bin2hex(expected_signature, sizeof(expected_signature), digest, sizeof(digest));
    if (sodium_memcmp(signature, expected_signature, 64U) != 0) goto cleanup;
    if (receipt != NULL) {
        struct timespec observed;
        if (clock_gettime(CLOCK_MONOTONIC, &observed) != 0) goto cleanup;
        memset(receipt, 0, sizeof(*receipt));
        snprintf(receipt->epoch, sizeof(receipt->epoch), "%s", epoch);
        snprintf(receipt->controller_epoch, sizeof(receipt->controller_epoch), "%s", controller_epoch);
        snprintf(receipt->active_slot, sizeof(receipt->active_slot), "%s", active_slot);
        snprintf(receipt->runtime_generation, sizeof(receipt->runtime_generation), "%s", runtime_generation);
        snprintf(receipt->host_boot_id, sizeof(receipt->host_boot_id), "%s", host_boot_id);
        snprintf(receipt->intent_sha256, sizeof(receipt->intent_sha256), "%s", intent_sha256);
        snprintf(receipt->intent_nonce, sizeof(receipt->intent_nonce), "%s", intent_nonce);
        receipt->generation = generation;
        receipt->active_config_generation = active_config_generation;
        receipt->observed_monotonic_ms = (long long)observed.tv_sec * 1000LL
            + (long long)observed.tv_nsec / 1000000LL;
    }
    result = 0;
cleanup:
    sodium_memzero(digest, sizeof(digest));
    sodium_memzero(expected_signature, sizeof(expected_signature));
    sodium_memzero(canonical, sizeof(canonical));
    return result;
}

static int wls_bootstrap_once(
    const char *controller_socket,
    const char *fencing,
    const char *home,
    const struct wls_controller_identity *controller_identity,
    int io_timeout_seconds,
    struct wls_bootstrap_receipt *receipt
) {
    char token_path[PATH_MAX];
    char host_path[PATH_MAX];
    char token[65];
    char host[33];
    char request_id[WLS_TOKEN_HEX + 1U];
    char nonce[WLS_TOKEN_HEX + 1U];
    char request_digest_canonical[256];
    char request_digest[65];
    char signature_canonical[1024];
    char signature[65];
    char request[1280];
    char header[512];
    char *response = NULL;
    unsigned char key[crypto_auth_hmacsha256_KEYBYTES];
    unsigned char digest[crypto_hash_sha256_BYTES];
    unsigned char signature_bytes[crypto_auth_hmacsha256_BYTES];
    size_t decoded = 0U;
    struct timespec monotonic;
    struct wls_peer peer;
    time_t wall_time;
    int controller = -1;
    int request_digest_length;
    int signature_canonical_length;
    int request_length;
    int header_length;
    ssize_t response_length;
    int result = -1;
    memset(key, 0, sizeof(key));
    memset(digest, 0, sizeof(digest));
    memset(signature_bytes, 0, sizeof(signature_bytes));
    if (controller_socket == NULL || fencing == NULL || home == NULL
        || controller_identity == NULL || geteuid() != 0
        || io_timeout_seconds < 1
        || snprintf(token_path, sizeof(token_path), "%s/trust/admin.token", home)
            >= (int)sizeof(token_path)
        || snprintf(host_path, sizeof(host_path), "%s/trust/host-id", home)
            >= (int)sizeof(host_path)
        || wls_read_hex_file(token_path, token, 64U) != 0
        || wls_read_hex_file(host_path, host, 32U) != 0
        || sodium_hex2bin(key, sizeof(key), token, 64U, NULL, &decoded, NULL) != 0
        || decoded != sizeof(key)
        || wls_random_token(request_id) != 0
        || wls_random_token(nonce) != 0
        || clock_gettime(CLOCK_MONOTONIC, &monotonic) != 0) {
        goto cleanup;
    }
    wall_time = time(NULL);
    if (wall_time == (time_t)-1) goto cleanup;
    request_id[32] = '\0';
    nonce[32] = '\0';
    request_digest_length = snprintf(
        request_digest_canonical,
        sizeof(request_digest_canonical),
        "{\"operation\":\"bootstrap\",\"payload\":{\"host_id\":\"%s\","
        "\"internal_bootstrap\":true}}",
        host
    );
    if (request_digest_length <= 0
        || request_digest_length >= (int)sizeof(request_digest_canonical)
        || crypto_hash_sha256(
            digest,
            (const unsigned char *)request_digest_canonical,
            (unsigned long long)request_digest_length
        ) != 0) {
        goto cleanup;
    }
    sodium_bin2hex(request_digest, sizeof(request_digest), digest, sizeof(digest));
    signature_canonical_length = snprintf(
        signature_canonical,
        sizeof(signature_canonical),
        "{\"channel\":\"admin\",\"credential_id\":\"admin\",\"host_id\":\"%s\","
        "\"monotonic_timestamp\":%lld,\"nonce\":\"%s\",\"operation\":\"bootstrap\","
        "\"payload\":{\"host_id\":\"%s\",\"internal_bootstrap\":true},"
        "\"protocol\":\"wls-edge/2\",\"request_digest\":\"%s\","
        "\"request_id\":\"%s\",\"timestamp\":%lld}",
        host,
        (long long)monotonic.tv_sec,
        nonce,
        host,
        request_digest,
        request_id,
        (long long)wall_time
    );
    if (signature_canonical_length <= 0
        || signature_canonical_length >= (int)sizeof(signature_canonical)
        || crypto_auth_hmacsha256(
            signature_bytes,
            (const unsigned char *)signature_canonical,
            (unsigned long long)signature_canonical_length,
            key
        ) != 0) {
        goto cleanup;
    }
    sodium_bin2hex(signature, sizeof(signature), signature_bytes, sizeof(signature_bytes));
    request_length = snprintf(
        request,
        sizeof(request),
        "{\"channel\":\"admin\",\"credential_id\":\"admin\",\"host_id\":\"%s\","
        "\"monotonic_timestamp\":%lld,\"nonce\":\"%s\",\"operation\":\"bootstrap\","
        "\"payload\":{\"host_id\":\"%s\",\"internal_bootstrap\":true},"
        "\"protocol\":\"wls-edge/2\",\"request_digest\":\"%s\","
        "\"request_id\":\"%s\",\"signature\":\"%s\",\"timestamp\":%lld}\n",
        host,
        (long long)monotonic.tv_sec,
        nonce,
        host,
        request_digest,
        request_id,
        signature,
        (long long)wall_time
    );
    if (request_length <= 0 || request_length >= (int)sizeof(request)) goto cleanup;
    controller = wls_connect_controller(
        controller_socket, fencing, io_timeout_seconds
    );
    response = malloc(WLS_MAX_REQUEST + 1U);
    if (controller < 0 || response == NULL) goto cleanup;
    peer.uid = (unsigned long)geteuid();
    peer.gid = (unsigned long)getegid();
    peer.pid = (long)getpid();
    header_length = snprintf(
        header,
        sizeof(header),
        "{\"broker_schema\":1,\"action_protocol\":2,\"channel\":\"admin\","
        "\"uid\":%lu,\"gid\":%lu,\"pid\":%ld,\"fencing_token\":\"%s\","
        "\"payload_length\":%d}\n",
        peer.uid,
        peer.gid,
        peer.pid,
        fencing,
        request_length
    );
    if (header_length <= 0 || header_length >= (int)sizeof(header)
        || wls_write_all(controller, header, (size_t)header_length) != 0
        || wls_write_all(controller, request, (size_t)request_length) != 0) {
        goto cleanup;
    }
    while ((response_length = wls_read_line(
        controller, response, WLS_MAX_REQUEST + 1U
    )) > 0) {
        if (strncmp(response, "WLS-ACTION/1\t", 13U) == 0
            || strncmp(response, "WLS-ACTION/2\t", 13U) == 0) {
            char action_response[4096];
            int action_result;
            int action_length;
            if (response[11] == '2') {
                action_response[0] = '\0';
                action_result = wls_handle_action_v2(
                    response, "admin", &peer, home, controller_identity,
                    action_response, sizeof(action_response)
                );
                action_length = (action_result == 0
                        || strncmp(action_response, "WLS-ACTION/2\t", 13U) == 0)
                    ? (int)strlen(action_response)
                    : snprintf(
                        action_response, sizeof(action_response),
                        "WLS-ACTION/2\tERR\tBROKER_IO\tBOOTSTRAP\t-\t-\n"
                    );
            } else {
                action_result = wls_handle_action(
                    response, "admin", &peer, home, controller_identity
                );
                action_length = snprintf(
                    action_response,
                    sizeof(action_response),
                    action_result == 0
                        ? "WLS-ACTION/1\tOK\n"
                        : "WLS-ACTION/1\tERR\t%d\n",
                    action_result == 0 ? 0 : errno
                );
            }
            if (action_length <= 0 || action_length >= (int)sizeof(action_response)
                || wls_write_all(
                    controller, action_response, (size_t)action_length
                ) != 0) {
                goto cleanup;
            }
            continue;
        }
        result = wls_verify_bootstrap_response(response, request_id, key, receipt);
        break;
    }
cleanup:
    if (controller >= 0) close(controller);
    if (response != NULL) {
        sodium_memzero(response, WLS_MAX_REQUEST + 1U);
        free(response);
    }
    sodium_memzero(key, sizeof(key));
    sodium_memzero(token, sizeof(token));
    sodium_memzero(signature_bytes, sizeof(signature_bytes));
    sodium_memzero(signature, sizeof(signature));
    sodium_memzero(signature_canonical, sizeof(signature_canonical));
    sodium_memzero(request, sizeof(request));
    return result;
}

static int wls_bootstrap_once_serialized(
    const char *controller_socket,
    const char *fencing,
    const char *home,
    const struct wls_controller_identity *controller_identity,
    int io_timeout_seconds,
    struct wls_bootstrap_receipt *receipt
) {
    int result;
    int lock_error = pthread_mutex_lock(&wls_bootstrap_mutex);
    if (lock_error != 0) {
        errno = lock_error;
        return -1;
    }
    result = wls_bootstrap_once(
        controller_socket,
        fencing,
        home,
        controller_identity,
        io_timeout_seconds,
        receipt
    );
    lock_error = pthread_mutex_unlock(&wls_bootstrap_mutex);
    if (lock_error != 0) {
        errno = lock_error;
        return -1;
    }
    return result;
}

static int wls_bootstrap_controller(
    const char *controller_socket,
    const char *fencing,
    const char *home,
    const struct wls_controller_identity *controller_identity,
    struct wls_bootstrap_maintenance_context *maintenance
) {
    static const useconds_t delays[WLS_BOOTSTRAP_ATTEMPTS - 1U] = {
        250000U, 1000000U, 5000000U
    };
    unsigned int attempt;
    for (attempt = 0U; attempt < WLS_BOOTSTRAP_ATTEMPTS; attempt++) {
        struct wls_bootstrap_receipt receipt;
        int result;
        memset(&receipt, 0, sizeof(receipt));
        result = wls_bootstrap_once_serialized(
                controller_socket,
                fencing,
                home,
                controller_identity,
                WLS_CONTROLLER_IO_TIMEOUT_SECONDS,
                &receipt
            );
        if (maintenance != NULL) {
            (void)wls_bootstrap_health_record(maintenance, result, &receipt);
        }
        if (result == 0) {
            return 0;
        }
        if (!wls_running || attempt + 1U >= WLS_BOOTSTRAP_ATTEMPTS) break;
        if (usleep(delays[attempt]) != 0 && errno != EINTR) break;
    }
    errno = EPROTO;
    return -1;
}

static void wls_destroy_bootstrap_maintenance_context(
    struct wls_bootstrap_maintenance_context *context
) {
    if (context == NULL) return;
    (void)pthread_mutex_destroy(&context->completion_mutex);
    sodium_memzero(context->fencing, sizeof(context->fencing));
    free(context);
}

static struct wls_bootstrap_maintenance_context *
wls_create_bootstrap_maintenance_context(
    const char *controller_socket,
    const char *fencing,
    const char *home,
    const struct wls_controller_identity *controller_identity,
    const char *active_slot,
    const char *runtime_generation
) {
    struct wls_bootstrap_maintenance_context *context;
    int mutex_error;
    if (controller_socket == NULL || fencing == NULL || home == NULL
        || active_slot == NULL || runtime_generation == NULL
        || (active_slot[0] != 'A' && active_slot[0] != 'B')
        || active_slot[1] != '\0'
        || !wls_is_hex(runtime_generation, 64U)) {
        errno = EINVAL;
        return NULL;
    }
    context = calloc(1U, sizeof(*context));
    if (context == NULL) return NULL;
    if (snprintf(
            context->controller_socket,
            sizeof(context->controller_socket),
            "%s",
            controller_socket
        ) >= (int)sizeof(context->controller_socket)
        || snprintf(
            context->fencing,
            sizeof(context->fencing),
            "%s",
            fencing
        ) >= (int)sizeof(context->fencing)
        || snprintf(context->home, sizeof(context->home), "%s", home)
            >= (int)sizeof(context->home)
        || snprintf(context->expected_slot, sizeof(context->expected_slot), "%s", active_slot)
            >= (int)sizeof(context->expected_slot)
        || snprintf(
            context->expected_runtime_generation,
            sizeof(context->expected_runtime_generation),
            "%s",
            runtime_generation
        ) >= (int)sizeof(context->expected_runtime_generation)) {
        sodium_memzero(context->fencing, sizeof(context->fencing));
        free(context);
        errno = ENAMETOOLONG;
        return NULL;
    }
    if (controller_identity != NULL) {
        context->controller_identity = *controller_identity;
        context->controller_identity_present = 1;
    }
    mutex_error = pthread_mutex_init(&context->completion_mutex, NULL);
    if (mutex_error != 0) {
        sodium_memzero(context->fencing, sizeof(context->fencing));
        free(context);
        errno = mutex_error;
        return NULL;
    }
    return context;
}

static int wls_bootstrap_receipt_matches(
    const struct wls_bootstrap_maintenance_context *context,
    const struct wls_bootstrap_receipt *receipt
) {
    struct wls_upgrade_binding binding;
    char boot_id[65];
    int binding_status;
    static const char no_digest[65] =
        "0000000000000000000000000000000000000000000000000000000000000000";
    static const char no_nonce[33] =
        "00000000000000000000000000000000";
    if (context == NULL || receipt == NULL
        || strcmp(receipt->active_slot, context->expected_slot) != 0
        || strcmp(
            receipt->runtime_generation,
            context->expected_runtime_generation
        ) != 0
        || strcmp(receipt->epoch, receipt->controller_epoch) != 0
        || receipt->observed_monotonic_ms <= 0
        || wls_upgrade_boot_id(boot_id) != 0
        || strcmp(receipt->host_boot_id, boot_id) != 0) {
        return 0;
    }
    binding_status = wls_upgrade_binding_read(context->home, &binding);
    if (binding_status < 0) return 0;
    if (binding_status == 0) {
        return strcmp(receipt->intent_sha256, no_digest) == 0
            && strcmp(receipt->intent_nonce, no_nonce) == 0;
    }
    return strcmp(receipt->intent_sha256, binding.intent_sha256) == 0
        && strcmp(receipt->intent_nonce, binding.nonce) == 0
        && strcmp(receipt->host_boot_id, binding.boot_id) == 0;
}

static int wls_bootstrap_same_health_generation(
    const struct wls_bootstrap_receipt *left,
    const struct wls_bootstrap_receipt *right
) {
    return left != NULL && right != NULL
        && strcmp(left->epoch, right->epoch) == 0
        && strcmp(left->controller_epoch, right->controller_epoch) == 0
        && left->generation == right->generation
        && left->active_config_generation == right->active_config_generation
        && strcmp(left->active_slot, right->active_slot) == 0
        && strcmp(left->runtime_generation, right->runtime_generation) == 0
        && strcmp(left->host_boot_id, right->host_boot_id) == 0
        && strcmp(left->intent_sha256, right->intent_sha256) == 0
        && strcmp(left->intent_nonce, right->intent_nonce) == 0;
}

static int wls_bootstrap_health_record(
    struct wls_bootstrap_maintenance_context *context,
    int bootstrap_result,
    const struct wls_bootstrap_receipt *receipt
) {
    int valid;
    if (context == NULL || pthread_mutex_lock(&context->completion_mutex) != 0) {
        return -1;
    }
    valid = bootstrap_result == 0
        && wls_bootstrap_receipt_matches(context, receipt);
    if (!valid) {
        context->continuous_since_ms = 0;
        context->last_success_ms = 0;
        if (context->observation_mode == 1) context->observation_failed = 1;
    } else {
        if (context->continuous_since_ms <= 0
            || !wls_bootstrap_same_health_generation(
                &context->receipt,
                receipt
            )) {
            context->continuous_since_ms = receipt->observed_monotonic_ms;
        }
        context->last_success_ms = receipt->observed_monotonic_ms;
        context->receipt = *receipt;
    }
    (void)pthread_mutex_unlock(&context->completion_mutex);
    return valid ? 0 : -1;
}

static void wls_bootstrap_health_arm(
    struct wls_bootstrap_maintenance_context *context,
    int observation_mode,
    long long observation_started_ms
) {
    long long freshness_floor;
    long long freshness_ceiling;
    if (context == NULL || pthread_mutex_lock(&context->completion_mutex) != 0) return;
    context->observation_mode = observation_mode;
    context->observation_failed = 0;
    if (wls_checked_add_ll(
            observation_started_ms,
            -WLS_MAINTENANCE_HEALTH_FRESHNESS_MILLISECONDS,
            &freshness_floor
        ) == 0
        && wls_checked_add_ll(
            observation_started_ms,
            WLS_MAINTENANCE_HEALTH_FRESHNESS_MILLISECONDS,
            &freshness_ceiling
        ) == 0
        && context->last_success_ms > 0
        && context->last_success_ms >= freshness_floor
        && context->last_success_ms <= freshness_ceiling) {
        context->continuous_since_ms = observation_started_ms;
    } else {
        context->continuous_since_ms = 0;
    }
    (void)pthread_mutex_unlock(&context->completion_mutex);
}

static int wls_bootstrap_health_ready(
    struct wls_bootstrap_maintenance_context *context,
    int observation_mode,
    long long now_ms,
    long long required_ms,
    struct wls_bootstrap_receipt *receipt
) {
    int ready = 0;
    if (context == NULL || pthread_mutex_lock(&context->completion_mutex) != 0) return 0;
    if (context->observation_mode == observation_mode
        && !context->observation_failed
        && context->continuous_since_ms > 0
        && now_ms >= context->continuous_since_ms
        && now_ms - context->continuous_since_ms >= required_ms
        && context->last_success_ms > 0
        && now_ms >= context->last_success_ms
        && now_ms - context->last_success_ms
            <= WLS_MAINTENANCE_HEALTH_FRESHNESS_MILLISECONDS
        && wls_bootstrap_receipt_matches(context, &context->receipt)) {
        if (receipt != NULL) *receipt = context->receipt;
        ready = 1;
    }
    (void)pthread_mutex_unlock(&context->completion_mutex);
    return ready;
}

static int wls_bootstrap_observation_failed(
    struct wls_bootstrap_maintenance_context *context
) {
    int failed = 1;
    if (context != NULL && pthread_mutex_lock(&context->completion_mutex) == 0) {
        failed = context->observation_mode == 1 && context->observation_failed;
        (void)pthread_mutex_unlock(&context->completion_mutex);
    }
    return failed;
}

static void wls_bootstrap_maintenance_completed(void *argument)
{
    struct wls_bootstrap_maintenance_context *context = argument;
    if (context == NULL) return;
    if (pthread_mutex_lock(&context->completion_mutex) == 0) {
        context->completed = 1;
        (void)pthread_mutex_unlock(&context->completion_mutex);
    }
}

static void *wls_bootstrap_maintenance_thread(void *argument)
{
    struct wls_bootstrap_maintenance_context *context = argument;
    useconds_t waited;
    if (context == NULL) {
        wls_bootstrap_maintenance_failed = 1;
        wls_running = 0;
        return NULL;
    }
    pthread_cleanup_push(wls_bootstrap_maintenance_completed, context);
    while (wls_running) {
        waited = 0U;
        while (wls_running
            && waited < WLS_MAINTENANCE_BOOTSTRAP_INTERVAL_US) {
            useconds_t delay = WLS_MAINTENANCE_BOOTSTRAP_POLL_US;
            if (WLS_MAINTENANCE_BOOTSTRAP_INTERVAL_US - waited < delay) {
                delay = WLS_MAINTENANCE_BOOTSTRAP_INTERVAL_US - waited;
            }
            if (usleep(delay) != 0 && errno != EINTR) {
                wls_bootstrap_maintenance_failed = 1;
                wls_running = 0;
                break;
            }
            waited += delay;
        }
        if (!wls_running) break;
        {
            struct wls_bootstrap_receipt receipt;
            int result;
            memset(&receipt, 0, sizeof(receipt));
            result = wls_bootstrap_once_serialized(
                context->controller_socket,
                context->fencing,
                context->home,
                context->controller_identity_present
                    ? &context->controller_identity
                    : NULL,
                WLS_MAINTENANCE_CONTROLLER_IO_TIMEOUT_SECONDS,
                &receipt
            );
            (void)wls_bootstrap_health_record(context, result, &receipt);
        }
    }
    pthread_cleanup_pop(1);
    return NULL;
}

static int wls_stop_bootstrap_maintenance_bounded(
    pthread_t thread,
    struct wls_bootstrap_maintenance_context **context_pointer
) {
    struct wls_bootstrap_maintenance_context *context;
    unsigned int attempt;
    int completed = 0;
    int cancel_error;
    int detach_error;
    if (context_pointer == NULL || *context_pointer == NULL) {
        errno = EINVAL;
        return -1;
    }
    context = *context_pointer;
    cancel_error = pthread_cancel(thread);
    if (cancel_error != 0 && cancel_error != ESRCH) {
        (void)pthread_detach(thread);
        *context_pointer = NULL;
        errno = cancel_error;
        return -1;
    }
    for (attempt = 0U; attempt < WLS_MAINTENANCE_STOP_ATTEMPTS; attempt++) {
        int lock_error = pthread_mutex_lock(&context->completion_mutex);
        if (lock_error != 0) {
            (void)pthread_detach(thread);
            *context_pointer = NULL;
            errno = lock_error;
            return -1;
        }
        completed = context->completed;
        lock_error = pthread_mutex_unlock(&context->completion_mutex);
        if (lock_error != 0) {
            (void)pthread_detach(thread);
            *context_pointer = NULL;
            errno = lock_error;
            return -1;
        }
        if (completed) break;
        if (usleep(WLS_MAINTENANCE_STOP_POLL_US) != 0 && errno != EINTR) {
            int wait_error = errno;
            (void)pthread_detach(thread);
            *context_pointer = NULL;
            errno = wait_error;
            return -1;
        }
    }
    if (!completed) {
        (void)pthread_detach(thread);
        *context_pointer = NULL;
        errno = ETIMEDOUT;
        return -1;
    }
    detach_error = pthread_detach(thread);
    wls_destroy_bootstrap_maintenance_context(context);
    *context_pointer = NULL;
    if (detach_error != 0 && detach_error != ESRCH) {
        errno = detach_error;
        return -1;
    }
    return 0;
}

static void wls_handle(
    int client,
    const char *channel,
    const char *controller_socket,
    const char *fencing,
    const char *home,
    const struct wls_controller_identity *controller_identity,
    const struct wls_peer *peer
) {
    int controller = -1;
    char *request = NULL;
    char *response = NULL;
    size_t request_capacity = 0U;
    size_t response_capacity = 0U;
    char header[512];
    ssize_t request_length = 0;
    ssize_t response_length = 0;
    int header_length;
    struct timeval io_timeout;
    io_timeout.tv_sec = 2;
    io_timeout.tv_usec = 0;
    if (setsockopt(client, SOL_SOCKET, SO_RCVTIMEO, &io_timeout, sizeof(io_timeout)) != 0
        || setsockopt(client, SOL_SOCKET, SO_SNDTIMEO, &io_timeout, sizeof(io_timeout)) != 0
    ) {
        goto cleanup;
    }
    if (peer == NULL) goto cleanup;
    request_length = wls_read_frame_alloc(
        client,
        &request,
        &request_capacity
    );
    if (request_length <= 0) goto cleanup;
    controller = wls_connect_controller(
        controller_socket,
        fencing,
        WLS_CONTROLLER_IO_TIMEOUT_SECONDS
    );
    if (controller < 0) goto cleanup;
    header_length = snprintf(
        header,
        sizeof(header),
        "{\"broker_schema\":1,\"action_protocol\":2,\"channel\":\"%s\",\"uid\":%lu,\"gid\":%lu,"
        "\"pid\":%ld,\"fencing_token\":\"%s\",\"payload_length\":%ld}\n",
        channel,
        peer->uid,
        peer->gid,
        peer->pid,
        fencing,
        (long)request_length
    );
    if (header_length <= 0
        || header_length >= (int)sizeof(header)
        || wls_write_all(controller, header, (size_t)header_length) != 0
        || wls_write_all(controller, request, (size_t)request_length) != 0
    ) {
        goto cleanup;
    }
    sodium_memzero(request, (size_t)request_length);
    free(request);
    request = NULL;
    request_capacity = 0U;
    request_length = 0;
    while ((response_length = wls_read_frame_alloc(
        controller,
        &response,
        &response_capacity
    )) > 0) {
        if (strncmp(response, "WLS-ACTION/1\t", 13U) == 0
            || strncmp(response, "WLS-ACTION/2\t", 13U) == 0) {
            char action_response[4096];
            int action_result;
            int action_length;
            if (response[11] == '2') {
                action_response[0] = '\0';
                action_result = wls_handle_action_v2(
                    response, channel, peer, home, controller_identity,
                    action_response, sizeof(action_response)
                );
                action_length = (action_result == 0
                        || strncmp(action_response, "WLS-ACTION/2\t", 13U) == 0)
                    ? (int)strlen(action_response)
                    : snprintf(
                        action_response,
                        sizeof(action_response),
                        "WLS-ACTION/2\tERR\tBROKER_IO\tUNKNOWN\t-\t-\n"
                    );
            } else {
                action_result = wls_handle_action(
                    response, channel, peer, home, controller_identity
                );
                action_length = snprintf(
                    action_response,
                    sizeof(action_response),
                    action_result == 0
                        ? "WLS-ACTION/1\tOK\n"
                        : "WLS-ACTION/1\tERR\t%d\n",
                    action_result == 0 ? 0 : errno
                );
            }
            if (action_length <= 0
                || action_length >= (int)sizeof(action_response)
                || wls_write_all(controller, action_response, (size_t)action_length) != 0) {
                goto cleanup;
            }
            continue;
        }
        if (wls_write_all(client, response, (size_t)response_length) != 0) {
            goto cleanup;
        }
        break;
    }
cleanup:
    if (controller >= 0) close(controller);
    if (client >= 0) close(client);
    if (request != NULL && request_capacity > 0U) {
        sodium_memzero(request, request_capacity);
    }
    if (response != NULL && response_capacity > 0U) {
        sodium_memzero(response, response_capacity);
    }
    free(request);
    free(response);
}

static int wls_project_gate_acquire(unsigned long uid)
{
    unsigned int index;
    unsigned int free_index = WLS_MAX_HANDLERS;
    if (wls_active_project_handlers >= WLS_MAX_PROJECT_HANDLERS
        || wls_active_handlers >= WLS_MAX_HANDLERS - WLS_ADMIN_RESERVED_HANDLERS) {
        return -1;
    }
    for (index = 0U; index < WLS_MAX_HANDLERS; index++) {
        if (wls_uid_gate[index].count == 0U) {
            if (free_index == WLS_MAX_HANDLERS) free_index = index;
            continue;
        }
        if (wls_uid_gate[index].uid == uid) {
            if (wls_uid_gate[index].count >= WLS_MAX_PROJECT_HANDLERS_PER_UID) {
                return -1;
            }
            wls_uid_gate[index].count++;
            wls_active_project_handlers++;
            return 0;
        }
    }
    if (free_index == WLS_MAX_HANDLERS) return -1;
    wls_uid_gate[free_index].uid = uid;
    wls_uid_gate[free_index].count = 1U;
    wls_active_project_handlers++;
    return 0;
}

static void wls_project_gate_release(unsigned long uid)
{
    unsigned int index;
    for (index = 0U; index < WLS_MAX_HANDLERS; index++) {
        if (wls_uid_gate[index].count == 0U
            || wls_uid_gate[index].uid != uid) continue;
        wls_uid_gate[index].count--;
        if (wls_uid_gate[index].count == 0U) wls_uid_gate[index].uid = 0U;
        if (wls_active_project_handlers > 0U) wls_active_project_handlers--;
        return;
    }
}

static void wls_handler_finished(
    unsigned int slot_index,
    int project_gate_acquired,
    unsigned long uid
)
{
    if (pthread_mutex_lock(&wls_handler_mutex) != 0) return;
    if (slot_index < WLS_MAX_HANDLERS
        && wls_handler_slots[slot_index].state == WLS_HANDLER_RUNNING) {
        wls_handler_slots[slot_index].state = WLS_HANDLER_DONE;
        wls_active_handlers--;
        if (project_gate_acquired) wls_project_gate_release(uid);
    }
    (void)pthread_mutex_unlock(&wls_handler_mutex);
}

static void *wls_handler_thread(void *argument)
{
    struct wls_handler_context *context = argument;
    unsigned int slot_index = context->slot_index;
    int project_gate_acquired = context->project_gate_acquired;
    unsigned long uid = context->peer.uid;
    wls_handle(
        context->client,
        context->channel,
        context->controller_socket,
        context->fencing,
        context->home,
        context->controller_identity,
        &context->peer
    );
    free(context);
    wls_handler_finished(slot_index, project_gate_acquired, uid);
    return NULL;
}

static int wls_reap_finished_handlers(void)
{
    unsigned int index;
    for (index = 0U; index < WLS_MAX_HANDLERS; index++) {
        pthread_t thread;
        int should_join = 0;
        int join_error;
        if (pthread_mutex_lock(&wls_handler_mutex) != 0) {
            errno = EBUSY;
            return -1;
        }
        if (wls_handler_slots[index].state == WLS_HANDLER_DONE) {
            wls_handler_slots[index].state = WLS_HANDLER_JOINING;
            thread = wls_handler_slots[index].thread;
            should_join = 1;
        }
        (void)pthread_mutex_unlock(&wls_handler_mutex);
        if (!should_join) continue;
        join_error = pthread_join(thread, NULL);
        if (pthread_mutex_lock(&wls_handler_mutex) != 0) {
            errno = EBUSY;
            return -1;
        }
        wls_handler_slots[index].state = join_error == 0
            ? WLS_HANDLER_FREE
            : WLS_HANDLER_DONE;
        (void)pthread_mutex_unlock(&wls_handler_mutex);
        if (join_error != 0) {
            errno = join_error;
            return -1;
        }
    }
    return 0;
}

static void wls_dispatch(
    int listener,
    const char *channel,
    const char *controller_socket,
    const char *fencing,
    const char *home,
    const struct wls_controller_identity *controller_identity
) {
    int client = accept(listener, NULL, NULL);
    struct wls_handler_context *context;
    struct wls_peer peer;
    int project_channel = strcmp(channel, "project") == 0;
    int project_gate_acquired = 0;
    int reserved = 0;
    unsigned int slot_index = WLS_MAX_HANDLERS;
    unsigned int index;
    if (client < 0) return;
    if (wls_fd_cloexec(client) != 0) {
        close(client);
        return;
    }
    /* Resolve the kernel peer before reserving a worker or allocating a frame. */
    if (wls_peer_identity(client, &peer) != 0) {
        close(client);
        return;
    }
    if (wls_reap_finished_handlers() != 0) {
        close(client);
        return;
    }
    if (pthread_mutex_lock(&wls_handler_mutex) == 0) {
        if (!project_channel || wls_project_gate_acquire(peer.uid) == 0) {
            project_gate_acquired = project_channel;
            for (index = 0U; index < WLS_MAX_HANDLERS; index++) {
                if (wls_handler_slots[index].state == WLS_HANDLER_FREE) {
                    wls_handler_slots[index].state = WLS_HANDLER_RUNNING;
                    wls_active_handlers++;
                    slot_index = index;
                    reserved = 1;
                    break;
                }
            }
            if (!reserved && project_gate_acquired) {
                wls_project_gate_release(peer.uid);
                project_gate_acquired = 0;
            }
        }
        (void)pthread_mutex_unlock(&wls_handler_mutex);
    }
    if (!reserved) {
        close(client);
        return;
    }
    context = malloc(sizeof(*context));
    if (context == NULL) {
        close(client);
        wls_handler_finished(slot_index, project_gate_acquired, peer.uid);
        return;
    }
    context->client = client;
    context->channel = channel;
    context->controller_socket = controller_socket;
    context->fencing = fencing;
    context->home = home;
    context->controller_identity = controller_identity;
    context->peer = peer;
    context->project_gate_acquired = project_gate_acquired;
    context->slot_index = slot_index;
    if (pthread_create(
            &wls_handler_slots[slot_index].thread,
            NULL,
            wls_handler_thread,
            context
        ) != 0) {
        close(client);
        free(context);
        wls_handler_finished(slot_index, project_gate_acquired, peer.uid);
        if (pthread_mutex_lock(&wls_handler_mutex) == 0) {
            wls_handler_slots[slot_index].state = WLS_HANDLER_FREE;
            (void)pthread_mutex_unlock(&wls_handler_mutex);
        }
        return;
    }
}

static int wls_wait_for_handlers(void)
{
    unsigned int attempt;
    for (attempt = 0U; attempt < 100U; attempt++) {
        unsigned int active = WLS_MAX_HANDLERS;
        int all_reaped = 1;
        unsigned int index;
        if (wls_reap_finished_handlers() != 0) return -1;
        if (pthread_mutex_lock(&wls_handler_mutex) == 0) {
            active = wls_active_handlers;
            for (index = 0U; index < WLS_MAX_HANDLERS; index++) {
                if (wls_handler_slots[index].state != WLS_HANDLER_FREE) {
                    all_reaped = 0;
                    break;
                }
            }
            (void)pthread_mutex_unlock(&wls_handler_mutex);
        }
        if (active == 0U && all_reaped) return 0;
        usleep(50000U);
    }
    errno = EBUSY;
    return -1;
}

static int wls_drop_controller_identity(
    const struct wls_controller_identity *identity
) {
    if (identity == NULL) {
        errno = EINVAL;
        return -1;
    }
    if (geteuid() != 0) {
        return geteuid() == identity->uid && getegid() == identity->gid ? 0 : -1;
    }
#if defined(__linux__)
    if (prctl(PR_SET_KEEPCAPS, 1L, 0L, 0L, 0L) != 0) return -1;
#endif
    if (setgroups(0, NULL) != 0
        || setgid(identity->gid) != 0
        || setuid(identity->uid) != 0) {
        return -1;
    }
#if defined(__linux__)
    {
        struct __user_cap_header_struct header;
        struct __user_cap_data_struct data[2];
        unsigned int index = (unsigned int)CAP_NET_BIND_SERVICE / 32U;
        unsigned int mask = 1U << ((unsigned int)CAP_NET_BIND_SERVICE % 32U);
        memset(&header, 0, sizeof(header));
        memset(data, 0, sizeof(data));
        header.version = _LINUX_CAPABILITY_VERSION_3;
        header.pid = 0;
        data[index].effective = mask;
        data[index].permitted = mask;
        data[index].inheritable = mask;
        if (syscall(SYS_capset, &header, data) != 0
            || prctl(
                PR_CAP_AMBIENT,
                PR_CAP_AMBIENT_RAISE,
                CAP_NET_BIND_SERVICE,
                0L,
                0L
            ) != 0
            || prctl(PR_SET_KEEPCAPS, 0L, 0L, 0L, 0L) != 0
            || prctl(PR_SET_NO_NEW_PRIVS, 1L, 0L, 0L, 0L) != 0) {
            return -1;
        }
    }
#endif
    return 0;
}

static pid_t wls_start_controller(
    const char *php,
    const char *controller,
    const char *home,
    const char *controller_socket,
    const char *fencing_file,
    const char *active_slot,
    const char *runtime_generation,
    const char *fencing,
    const struct wls_controller_identity *controller_identity
) {
    pid_t child;
    char home_argument[PATH_MAX + 16U];
    char broker_argument[PATH_MAX + 32U];
    char fencing_argument[PATH_MAX + 32U];
    char host_boot_argument[96];
    char host_boot_id[65];
    if (php == NULL || controller == NULL || home == NULL
        || controller_socket == NULL || fencing_file == NULL
        || active_slot == NULL || runtime_generation == NULL || fencing == NULL
        || controller_identity == NULL) {
        return 0;
    }
    if (wls_upgrade_boot_id(host_boot_id) != 0
        || snprintf(home_argument, sizeof(home_argument), "--home=%s", home)
            >= (int)sizeof(home_argument)
        || snprintf(
            broker_argument,
            sizeof(broker_argument),
            "--broker-internal=unix://%s",
            controller_socket
        ) >= (int)sizeof(broker_argument)
        || snprintf(
            fencing_argument,
            sizeof(fencing_argument),
            "--broker-fencing-file=%s",
            fencing_file
        ) >= (int)sizeof(fencing_argument)
        || snprintf(
            host_boot_argument,
            sizeof(host_boot_argument),
            "--host-boot-id=%s",
            host_boot_id
        ) >= (int)sizeof(host_boot_argument)) {
        return -1;
    }
    if (wls_wait_for_handlers() != 0) return -1;
    child = fork();
    if (child != 0) {
        if (child > 0 && wls_write_controller_process_identity(
                home,
                child,
                php,
                active_slot,
                runtime_generation,
                fencing
            ) != 0) {
            int identity_error = errno != 0 ? errno : EIO;
            (void)wls_stop_controller_bounded(child, controller_socket);
            errno = identity_error;
            return -1;
        }
        return child;
    }
    signal(SIGTERM, SIG_DFL);
    signal(SIGINT, SIG_DFL);
    signal(SIGHUP, SIG_DFL);
    signal(SIGPIPE, SIG_DFL);
    if (wls_drop_controller_identity(controller_identity) != 0) {
        _exit(126);
    }
    execl(
        php,
        php,
        controller,
        home_argument,
        broker_argument,
        fencing_argument,
        host_boot_argument,
        (char *)NULL
    );
    _exit(127);
}

static int wls_wait_for_controller(
    const char *socket_path,
    const char *fencing,
    pid_t *controller_pid
)
{
    unsigned int attempt;
    struct stat status;
    struct timespec started;
    struct timespec now;
    int controller_status = 0;
    if (controller_pid == NULL
        || clock_gettime(CLOCK_MONOTONIC, &started) != 0) {
        errno = EINVAL;
        return -1;
    }
    for (attempt = 0U; attempt < WLS_CONTROLLER_START_ATTEMPTS; attempt++) {
        if (lstat(socket_path, &status) == 0 && S_ISSOCK(status.st_mode)) {
            int probe = wls_connect_controller(
                socket_path,
                fencing,
                WLS_CONTROLLER_PROBE_TIMEOUT_SECONDS
            );
            if (probe >= 0) {
                close(probe);
                return 0;
            }
        }
        if (*controller_pid > 0
            && wls_reap_controller(
                *controller_pid,
                WNOHANG,
                &controller_status
            ) == 0) {
            *controller_pid = 0;
            errno = ECHILD;
            return -1;
        }
        if (clock_gettime(CLOCK_MONOTONIC, &now) != 0
            || now.tv_sec - started.tv_sec >= WLS_CONTROLLER_START_TIMEOUT_SECONDS) {
            break;
        }
        usleep(WLS_CONTROLLER_START_POLL_US);
    }
    errno = ETIMEDOUT;
    return -1;
}

static int wls_serve(
    const char *admin_socket,
    const char *project_socket,
    const char *controller_socket,
    const char *lock_file,
    const char *fencing_file,
    const char *php,
    const char *controller,
    const char *home,
    const char *controller_user,
    const char *active_slot,
    const char *runtime_generation
) {
    int lock_fd = -1;
    int admin_fd = -1;
    int project_fd = -1;
    char fencing[WLS_TOKEN_HEX + 1U] = {0};
    fd_set read_set;
    struct timeval timeout;
    struct timespec observation_now;
    long long observation_started_ms = 0;
    long long observation_now_ms = 0;
    unsigned int observation_resets = 0U;
    int upgrade_mode = 0;
    int upgrade_marked = 0;
    int exit_code = 1;
    int admin_bound = 0;
    int project_bound = 0;
    int fencing_written = 0;
    int maximum;
    pid_t controller_pid = 0;
    pthread_t bootstrap_maintenance_thread;
    int bootstrap_maintenance_started = 0;
    struct wls_bootstrap_maintenance_context *bootstrap_maintenance_context = NULL;
    struct wls_controller_identity resolved_controller_identity;
    const struct wls_controller_identity *controller_identity = NULL;
    struct wls_bootstrap_receipt marker_health;
    lock_fd = open(lock_file, O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (lock_fd < 0 || flock(lock_fd, LOCK_EX | LOCK_NB) != 0) {
        fprintf(stderr, "broker singleton lock unavailable: %s\n", strerror(errno));
        goto failed;
    }
    if (controller_user != NULL) {
        struct passwd *account = getpwnam(controller_user);
        if (account == NULL) {
            fprintf(stderr, "broker controller account is unavailable\n");
            goto failed;
        }
        resolved_controller_identity.uid = account->pw_uid;
        resolved_controller_identity.gid = account->pw_gid;
        controller_identity = &resolved_controller_identity;
        if (wls_repair_trust_access(home, controller_identity) != 0) {
            fprintf(stderr, "broker trust ACL is unsafe: %s\n", strerror(errno));
            goto failed;
        }
        if ((geteuid() == 0
                && (setgroups(0, NULL) != 0
                    || setgid(controller_identity->gid) != 0))
            || (geteuid() != 0 && getegid() != controller_identity->gid)
        ) {
            fprintf(stderr, "broker controller group isolation failed: %s\n", strerror(errno));
            goto failed;
        }
        {
            char controller_directory[PATH_MAX];
            char *slash;
            struct stat directory_status;
            if (strlen(controller_socket) >= sizeof(controller_directory)) goto failed;
            memcpy(controller_directory, controller_socket, strlen(controller_socket) + 1U);
            slash = strrchr(controller_directory, '/');
            if (slash == NULL) goto failed;
            *slash = '\0';
            if (lstat(controller_directory, &directory_status) != 0
                || !S_ISDIR(directory_status.st_mode)
                || S_ISLNK(directory_status.st_mode)) {
                fprintf(stderr, "broker controller run directory is unsafe\n");
                goto failed;
            }
            if ((geteuid() == 0
                    && (chown(
                            controller_directory,
                            0,
                            controller_identity->gid
                        ) != 0
                        || chmod(controller_directory, 0771) != 0))
                || (geteuid() != 0
                    && (directory_status.st_uid != geteuid()
                        || directory_status.st_gid != getegid()
                        || getegid() != controller_identity->gid))) {
                fprintf(
                    stderr,
                    "broker controller run directory owner is unsafe "
                    "(uid=%lu gid=%lu euid=%lu egid=%lu controller_gid=%lu)\n",
                    (unsigned long)directory_status.st_uid,
                    (unsigned long)directory_status.st_gid,
                    (unsigned long)geteuid(),
                    (unsigned long)getegid(),
                    (unsigned long)controller_identity->gid
                );
                goto failed;
            }
        }
    }
    if (wls_random_token(fencing) != 0
        || wls_write_fencing(fencing_file, fencing, controller_identity) != 0) {
        fprintf(stderr, "broker fencing token unavailable: %s\n", strerror(errno));
        goto failed;
    }
    fencing_written = 1;
    if (wls_wait_previous_controller_exit(home, fencing) != 0) {
        fprintf(
            stderr,
            "broker previous controller generation did not exit: %s\n",
            strerror(errno)
        );
        exit_code = 1;
        goto failed;
    }
    controller_pid = wls_start_controller(
        php,
        controller,
        home,
        controller_socket,
        fencing_file,
        active_slot,
        runtime_generation,
        fencing,
        controller_identity
    );
    if (controller_pid < 0
        || (controller_pid > 0
            && wls_wait_for_controller(
                controller_socket,
                fencing,
                &controller_pid
            ) != 0)) {
        fprintf(stderr, "broker controller launch failed: %s\n", strerror(errno));
        goto failed;
    }
    admin_fd = wls_create_listener(admin_socket, 0600);
    admin_bound = admin_fd >= 0;
    project_fd = wls_create_listener(project_socket, 0622);
    project_bound = project_fd >= 0;
    if (admin_fd < 0 || project_fd < 0) {
        fprintf(stderr, "broker control socket unavailable: %s\n", strerror(errno));
        goto failed;
    }
    strncpy(wls_admin_socket, admin_socket, sizeof(wls_admin_socket) - 1U);
    strncpy(wls_project_socket, project_socket, sizeof(wls_project_socket) - 1U);
    signal(SIGTERM, wls_signal);
    signal(SIGINT, wls_signal);
    signal(SIGHUP, SIG_IGN);
    signal(SIGPIPE, SIG_IGN);
    if (php != NULL) {
        bootstrap_maintenance_context =
            wls_create_bootstrap_maintenance_context(
                controller_socket,
                fencing,
                home,
                controller_identity,
                active_slot,
                runtime_generation
            );
        if (bootstrap_maintenance_context == NULL) {
            fprintf(stderr, "broker maintenance bootstrap context unavailable\n");
            goto failed;
        }
        if (wls_bootstrap_controller(
                controller_socket,
                fencing,
                home,
                controller_identity,
                bootstrap_maintenance_context
            ) != 0) {
            fprintf(stderr, "broker controller bootstrap failed: %s\n", strerror(errno));
            goto failed;
        }
    }
    upgrade_mode = wls_prepare_upgrade_runtime(
        home,
        active_slot,
        runtime_generation,
        &observation_started_ms
    );
    if (upgrade_mode < 0) {
        goto failed;
    }
    if (bootstrap_maintenance_context != NULL && upgrade_mode > 0) {
        wls_bootstrap_health_arm(
            bootstrap_maintenance_context,
            upgrade_mode,
            observation_started_ms
        );
    }
    if (php != NULL) {
        if (pthread_create(
                &bootstrap_maintenance_thread,
                NULL,
                wls_bootstrap_maintenance_thread,
                bootstrap_maintenance_context
            ) != 0) {
            fprintf(stderr, "broker maintenance bootstrap thread unavailable\n");
            wls_destroy_bootstrap_maintenance_context(
                bootstrap_maintenance_context
            );
            bootstrap_maintenance_context = NULL;
            goto failed;
        }
        bootstrap_maintenance_started = 1;
    }
    maximum = admin_fd > project_fd ? admin_fd : project_fd;
    while (wls_running) {
        FD_ZERO(&read_set);
        FD_SET(admin_fd, &read_set);
        FD_SET(project_fd, &read_set);
        timeout.tv_sec = 1;
        timeout.tv_usec = 0;
        if (select(maximum + 1, &read_set, NULL, NULL, &timeout) < 0) {
            if (errno == EINTR) continue;
            goto failed;
        }
        if (controller_pid > 0) {
            int controller_status = 0;
            if (wls_reap_controller(
                    controller_pid,
                    WNOHANG,
                    &controller_status
                ) != 0) {
                goto controller_alive;
            }
            {
                int controller_exit_code = WIFEXITED(controller_status)
                    ? WEXITSTATUS(controller_status)
                    : (WIFSIGNALED(controller_status)
                        ? 128 + WTERMSIG(controller_status)
                        : 1);
                unsigned int restart_attempt;
                int controller_restarted = 0;
                int controller_restart_failure = 1;
                wls_release_controller_socket(controller_socket);
                controller_pid = 0;
                if (controller_exit_code == 0 || controller_exit_code == 79) {
                    exit_code = controller_exit_code;
                    goto cleanup;
                }
                if (upgrade_mode == 1) {
                    // Candidate health must be continuous. Let the stable
                    // launcher persist one bound failure and relaunch from a
                    // fresh PREPARED state rather than resetting OBSERVING.
                    errno = ECHILD;
                    goto failed;
                }
                for (
                    restart_attempt = 0U;
                    restart_attempt < 3U;
                    restart_attempt++
                ) {
                    useconds_t delay = restart_attempt == 0U
                        ? 250000U
                        : (restart_attempt == 1U ? 1000000U : 5000000U);
                    fprintf(
                        stderr,
                        "broker controller restart attempt %u after exit %d\n",
                        restart_attempt + 1U,
                        controller_exit_code
                    );
                    if (usleep(delay) != 0 && errno != EINTR) {
                        controller_restart_failure = errno;
                    }
                    if (!wls_running) {
                        controller_restarted = -1;
                        break;
                    }
                    controller_pid = wls_start_controller(
                        php,
                        controller,
                        home,
                        controller_socket,
                        fencing_file,
                        active_slot,
                        runtime_generation,
                        fencing,
                        controller_identity
                    );
                    if (controller_pid <= 0) {
                        controller_restart_failure = errno != 0 ? errno : ECHILD;
                        controller_pid = 0;
                        continue;
                    }
                    if (wls_wait_for_controller(
                            controller_socket,
                            fencing,
                            &controller_pid
                        ) == 0
                        && wls_bootstrap_controller(
                            controller_socket,
                            fencing,
                            home,
                            controller_identity,
                            bootstrap_maintenance_context
                        ) == 0) {
                        observation_resets++;
                        if (observation_resets
                            >= WLS_CONTROLLER_OBSERVATION_RESETS) {
                            controller_restart_failure = ELOOP;
                            fprintf(
                                stderr,
                                "broker controller observation reset limit reached\n"
                            );
                            break;
                        }
                        upgrade_mode = wls_prepare_upgrade_runtime(
                            home,
                            active_slot,
                            runtime_generation,
                            &observation_started_ms
                        );
                        if (upgrade_mode < 0) {
                            controller_restart_failure = errno != 0
                                ? errno
                                : EIO;
                            break;
                        }
                        if (bootstrap_maintenance_context != NULL
                            && upgrade_mode > 0) {
                            wls_bootstrap_health_arm(
                                bootstrap_maintenance_context,
                                upgrade_mode,
                                observation_started_ms
                            );
                        }
                        upgrade_marked = 0;
                        controller_restarted = 1;
                        fprintf(
                            stderr,
                            "broker controller restart ready pid=%ld\n",
                            (long)controller_pid
                        );
                        break;
                    }
                    controller_restart_failure = errno != 0 ? errno : ECHILD;
                    if (controller_pid > 0) {
                        if (wls_stop_controller_bounded(
                                controller_pid,
                                controller_socket
                            ) != 0) {
                            controller_restart_failure = errno != 0
                                ? errno
                                : ECHILD;
                            break;
                        }
                        controller_pid = 0;
                    }
                }
                if (controller_restarted < 0) {
                    break;
                }
                if (controller_restarted == 0) {
                    errno = controller_restart_failure;
                    fprintf(
                        stderr,
                        "broker controller restart exhausted: %s\n",
                        strerror(errno)
                    );
                    goto failed;
                }
            }
        }
controller_alive:
        if (controller_pid < 0) {
            wls_release_controller_socket(controller_socket);
            controller_pid = 0;
            goto failed;
        }
        if (upgrade_mode == 1
            && wls_bootstrap_observation_failed(
                bootstrap_maintenance_context
            )) {
            errno = EPROTO;
            goto failed;
        }
        if (!upgrade_marked && clock_gettime(CLOCK_MONOTONIC, &observation_now) == 0) {
            observation_now_ms = (long long)observation_now.tv_sec * 1000LL
                + (long long)observation_now.tv_nsec / 1000000LL;
            memset(&marker_health, 0, sizeof(marker_health));
            if (upgrade_mode == 1
                && wls_bootstrap_health_ready(
                    bootstrap_maintenance_context,
                    upgrade_mode,
                    observation_now_ms,
                    WLS_UPGRADE_OBSERVATION_MILLISECONDS,
                    &marker_health
                )
                && wls_owned_nginx_alive(home, &marker_health)
                && wls_write_upgrade_healthy(
                    home,
                    active_slot,
                    runtime_generation,
                    observation_started_ms
                ) == 0) {
                upgrade_marked = 1;
            } else if (upgrade_mode == 2
                && wls_bootstrap_health_ready(
                    bootstrap_maintenance_context,
                    upgrade_mode,
                    observation_now_ms,
                    WLS_ROLLBACK_HEALTH_MILLISECONDS,
                    &marker_health
                )
                && wls_owned_nginx_alive(home, &marker_health)
                && wls_write_rollback_healthy(
                    home,
                    active_slot,
                    runtime_generation,
                    observation_started_ms,
                    observation_now_ms
                ) == 0) {
                upgrade_marked = 1;
            }
        }
        if (FD_ISSET(admin_fd, &read_set)) {
            wls_dispatch(
                admin_fd,
                "admin",
                controller_socket,
                fencing,
                home,
                controller_identity
            );
        }
        if (FD_ISSET(project_fd, &read_set)) {
            wls_dispatch(
                project_fd,
                "project",
                controller_socket,
                fencing,
                home,
                controller_identity
            );
        }
    }
    exit_code = wls_bootstrap_maintenance_failed ? 1 : 0;
cleanup:
    wls_running = 0;
    if (admin_fd >= 0) close(admin_fd);
    if (project_fd >= 0) close(project_fd);
    if (admin_bound) unlink(admin_socket);
    if (project_bound) unlink(project_socket);
    if (bootstrap_maintenance_started
        && wls_stop_bootstrap_maintenance_bounded(
            bootstrap_maintenance_thread,
            &bootstrap_maintenance_context
        ) != 0) {
        fprintf(
            stderr,
            "broker maintenance thread bounded stop failed: %s\n",
            strerror(errno)
        );
        exit_code = 1;
    }
    if (controller_pid > 0) {
        if (wls_stop_controller_bounded(
                controller_pid,
                controller_socket
            ) != 0) {
            fprintf(stderr, "broker controller bounded stop failed: %s\n", strerror(errno));
            exit_code = 1;
        }
    }
    if (wls_wait_for_handlers() != 0) {
        fprintf(stderr, "broker handler bounded stop failed: %s\n", strerror(errno));
        exit_code = 1;
    }
    if (fencing_written) unlink(fencing_file);
    if (lock_fd >= 0) close(lock_fd);
    sodium_memzero(fencing, sizeof(fencing));
    return exit_code;

failed:
    wls_running = 0;
    if (admin_fd >= 0) close(admin_fd);
    if (project_fd >= 0) close(project_fd);
    if (admin_bound) unlink(admin_socket);
    if (project_bound) unlink(project_socket);
    if (bootstrap_maintenance_started
        && wls_stop_bootstrap_maintenance_bounded(
            bootstrap_maintenance_thread,
            &bootstrap_maintenance_context
        ) != 0) {
        fprintf(
            stderr,
            "broker maintenance thread bounded stop failed: %s\n",
            strerror(errno)
        );
    } else if (!bootstrap_maintenance_started
        && bootstrap_maintenance_context != NULL) {
        wls_destroy_bootstrap_maintenance_context(
            bootstrap_maintenance_context
        );
        bootstrap_maintenance_context = NULL;
    }
    if (controller_pid > 0) {
        if (wls_stop_controller_bounded(
                controller_pid,
                controller_socket
            ) != 0) {
            fprintf(stderr, "broker controller bounded stop failed: %s\n", strerror(errno));
        }
    }
    if (wls_wait_for_handlers() != 0) {
        fprintf(stderr, "broker handler bounded stop failed: %s\n", strerror(errno));
    }
    if (fencing_written) unlink(fencing_file);
    if (lock_fd >= 0) close(lock_fd);
    sodium_memzero(fencing, sizeof(fencing));
    return 1;
}

static int wls_self_test(void)
{
    char directory[] = "/tmp/wls-gateway-broker-selftest-XXXXXX";
    char source[PATH_MAX];
    char link_path[PATH_MAX];
    int root_fd = -1;
    int file_fd = -1;
    int linked_fd = -1;
    int sockets[2] = {-1, -1};
    struct wls_peer peer;
    int result = 1;
    if (mkdtemp(directory) == NULL) return 1;
    if (snprintf(source, sizeof(source), "%s/source.pem", directory) >= (int)sizeof(source)
        || snprintf(link_path, sizeof(link_path), "%s/link.pem", directory) >= (int)sizeof(link_path)) {
        goto cleanup;
    }
    file_fd = open(source, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC, 0600);
    if (file_fd < 0 || write(file_fd, "certificate", 11U) != 11) goto cleanup;
    close(file_fd);
    file_fd = -1;
    if (symlink(source, link_path) != 0) goto cleanup;
    root_fd = open(directory, O_RDONLY | O_DIRECTORY | O_CLOEXEC | O_NOFOLLOW);
    if (root_fd < 0) goto cleanup;
    file_fd = wls_open_relative(root_fd, "source.pem", O_RDONLY);
    linked_fd = wls_open_relative(root_fd, "link.pem", O_RDONLY);
    if (file_fd < 0 || linked_fd >= 0) goto cleanup;
    if (socketpair(AF_UNIX, SOCK_STREAM, 0, sockets) != 0
        || wls_peer_identity(sockets[0], &peer) != 0
        || peer.uid != (unsigned long)geteuid()) {
        goto cleanup;
    }
    result = 0;
cleanup:
    if (sockets[0] >= 0) close(sockets[0]);
    if (sockets[1] >= 0) close(sockets[1]);
    if (linked_fd >= 0) close(linked_fd);
    if (file_fd >= 0) close(file_fd);
    if (root_fd >= 0) close(root_fd);
    unlink(link_path);
    unlink(source);
    rmdir(directory);
    return result;
}

static const char *wls_argument(int argc, char **argv, const char *name)
{
    int index;
    for (index = 1; index + 1 < argc; index++) {
        if (strcmp(argv[index], name) == 0) {
            return argv[index + 1];
        }
    }
    return NULL;
}

int main(int argc, char **argv)
{
    const char *admin_socket;
    const char *project_socket;
    const char *controller_socket;
    const char *lock_file;
    const char *fencing_file;
    const char *php;
    const char *controller;
    const char *home;
    const char *controller_user;
    const char *active_slot;
    const char *runtime_generation;
    char expected_fencing[PATH_MAX];
    if (sodium_init() < 0) {
        return 1;
    }
    if (argc == 2 && strcmp(argv[1], "--self-test") == 0) {
        return wls_self_test();
    }
    if (argc > 1 && strcmp(argv[1], "--snapshot") == 0) {
        const char *source_root = wls_argument(argc, argv, "--source-root");
        const char *source_relative = wls_argument(argc, argv, "--source-relative");
        const char *destination_root = wls_argument(argc, argv, "--destination-root");
        const char *destination_relative = wls_argument(argc, argv, "--destination-relative");
        if (source_root == NULL || source_relative == NULL
            || destination_root == NULL || destination_relative == NULL) {
            return 64;
        }
        return wls_snapshot(
            source_root,
            source_relative,
            destination_root,
            destination_relative,
            (uid_t)-1,
            0,
            (uid_t)-1,
            (gid_t)-1
        );
    }
    if (argc <= 1 || strcmp(argv[1], "--serve") != 0) {
        fprintf(stderr, "usage: wls-gateway-broker --self-test|--snapshot|--serve\n");
        return 64;
    }
    admin_socket = wls_argument(argc, argv, "--admin-socket");
    project_socket = wls_argument(argc, argv, "--project-socket");
    controller_socket = wls_argument(argc, argv, "--controller-socket");
    lock_file = wls_argument(argc, argv, "--lock-file");
    fencing_file = wls_argument(argc, argv, "--fencing-file");
    php = wls_argument(argc, argv, "--php");
    controller = wls_argument(argc, argv, "--controller");
    home = wls_argument(argc, argv, "--home");
    controller_user = wls_argument(argc, argv, "--controller-user");
    active_slot = wls_argument(argc, argv, "--active-slot");
    runtime_generation = wls_argument(argc, argv, "--runtime-generation");
    if (admin_socket == NULL || project_socket == NULL || controller_socket == NULL
        || lock_file == NULL || fencing_file == NULL || home == NULL
        || home[0] != '/' || home[1] == '\0'
        || snprintf(
            expected_fencing,
            sizeof(expected_fencing),
            "%s/trust/broker-fencing-token",
            home
        ) >= (int)sizeof(expected_fencing)
        || strcmp(expected_fencing, fencing_file) != 0
        || ((php != NULL || controller != NULL || controller_user != NULL)
            && (php == NULL || controller == NULL || controller_user == NULL))
        || (php != NULL
            && (active_slot == NULL
                || strlen(active_slot) != 1U
                || (active_slot[0] != 'A' && active_slot[0] != 'B')
                || !wls_is_hex(runtime_generation, 64U)))) {
        return 64;
    }
    return wls_serve(
        admin_socket,
        project_socket,
        controller_socket,
        lock_file,
        fencing_file,
        php,
        controller,
        home,
        controller_user,
        active_slot,
        runtime_generation
    );
}
