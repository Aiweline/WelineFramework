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

#define WLS_MAX_REQUEST (4U * 1024U * 1024U)
#define WLS_MAX_SNAPSHOT (1024U * 1024U)
#define WLS_TOKEN_HEX 64U
#define WLS_CONTROLLER_START_ATTEMPTS 4500U
#define WLS_CONTROLLER_START_POLL_US 10000U
#define WLS_MAX_HANDLERS 64U

static volatile sig_atomic_t wls_running = 1;
static char wls_admin_socket[PATH_MAX];
static char wls_project_socket[PATH_MAX];
static pthread_mutex_t wls_handler_mutex = PTHREAD_MUTEX_INITIALIZER;
static unsigned int wls_active_handlers = 0U;

struct wls_peer {
    unsigned long uid;
    unsigned long gid;
    long pid;
};

struct wls_handler_context {
    int client;
    const char *channel;
    const char *controller_socket;
    const char *fencing;
    const char *home;
    const struct passwd *controller_account;
};

static int wls_write_all(int fd, const char *buffer, size_t length);

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
    return 1;
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
    directory_fd = dup(root_fd);
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
        return dup(root_fd);
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
    char temporary_leaf[NAME_MAX + 1U];
    char buffer[65536];
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
        || before.st_size < 1
        || (uint64_t)before.st_size > WLS_MAX_SNAPSHOT
        || (expected_source_owner != (uid_t)-1
            && before.st_uid != expected_source_owner
            && before.st_uid != 0)
        || (require_private_mode && (before.st_mode & 0077) != 0)) {
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
    if (snprintf(
        temporary_leaf,
        sizeof(temporary_leaf),
        ".wls-snapshot-%ld-%08lx",
        (long)getpid(),
        (unsigned long)before.st_ino
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
    while ((amount = read(source_fd, buffer, sizeof(buffer))) > 0) {
        ssize_t written = 0;
        total += (uint64_t)amount;
        if (total > WLS_MAX_SNAPSHOT) {
            errno = EFBIG;
            goto cleanup;
        }
        while (written < amount) {
            ssize_t part = write(
                temporary_fd,
                buffer + written,
                (size_t)(amount - written)
            );
            if (part <= 0) {
                goto cleanup;
            }
            written += part;
        }
    }
    if (amount < 0
        || fsync(temporary_fd) != 0
        || fchmod(temporary_fd, 0600) != 0
        || (destination_owner != (uid_t)-1
            && fchown(temporary_fd, destination_owner, destination_group) != 0)
        || fstat(source_fd, &after) != 0
        || before.st_dev != after.st_dev
        || before.st_ino != after.st_ino
        || before.st_size != after.st_size
        || before.st_mode != after.st_mode
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
    int fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0 || read(fd, bytes, sizeof(bytes)) != (ssize_t)sizeof(bytes)) {
        if (fd >= 0) close(fd);
        return -1;
    }
    close(fd);
    for (index = 0U; index < sizeof(bytes); index++) {
        token[index * 2U] = hex[bytes[index] >> 4U];
        token[index * 2U + 1U] = hex[bytes[index] & 0x0fU];
    }
    token[WLS_TOKEN_HEX] = '\0';
    return 0;
}

static int wls_write_fencing(
    const char *path,
    const char *token,
    const struct passwd *controller_account
) {
    char temporary[PATH_MAX];
    int fd;
    size_t length = strlen(token);
    if (snprintf(temporary, sizeof(temporary), "%s.candidate.%ld", path, (long)getpid())
        >= (int)sizeof(temporary)) {
        return -1;
    }
    fd = open(temporary, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (fd < 0
        || write(fd, token, length) != (ssize_t)length
        || write(fd, "\n", 1U) != 1
        || fsync(fd) != 0
    ) {
        if (fd >= 0) close(fd);
        unlink(temporary);
        return -1;
    }
    if (controller_account != NULL
        && (fchown(fd, 0, controller_account->pw_gid) != 0 || fchmod(fd, 0640) != 0)) {
        close(fd);
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

static int wls_read_hex_file(
    const char *path,
    char *text,
    size_t hex_length
) {
    struct stat status;
    ssize_t amount;
    int fd = open(path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0
        || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode)
        || status.st_uid != 0
        || (status.st_mode & 0022) != 0
        || hex_length + 2U > 256U) {
        if (fd >= 0) close(fd);
        errno = EPERM;
        return -1;
    }
    amount = read(fd, text, hex_length + 1U);
    close(fd);
    if (amount < (ssize_t)hex_length
        || (size_t)amount > hex_length + 1U
        || ((size_t)amount == hex_length + 1U && text[hex_length] != '\n')) {
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
    int fd;
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
    if (fd < 0
        || wls_write_all(fd, contents, length) != 0
        || fsync(fd) != 0
        || fchmod(fd, 0600) != 0
        || fchown(fd, 0, 0) != 0) {
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

static int wls_write_upgrade_healthy(
    const char *home,
    const char *active_slot,
    const char *runtime_generation
) {
    char intent_path[PATH_MAX];
    char healthy_path[PATH_MAX];
    char payload[192];
    struct stat status;
    int length;
    if (home == NULL
        || active_slot == NULL
        || strlen(active_slot) != 1U
        || (active_slot[0] != 'A' && active_slot[0] != 'B')
        || !wls_is_hex(runtime_generation, 64U)
        || snprintf(intent_path, sizeof(intent_path), "%s/trust/upgrade.intent", home)
            >= (int)sizeof(intent_path)
        || snprintf(healthy_path, sizeof(healthy_path), "%s/trust/upgrade-healthy", home)
            >= (int)sizeof(healthy_path)
        || lstat(intent_path, &status) != 0
        || !S_ISREG(status.st_mode)
        || S_ISLNK(status.st_mode)) {
        return -1;
    }
    length = snprintf(
        payload,
        sizeof(payload),
        "WLS-UPGRADE-HEALTHY/1\nto=%c\nruntime_generation=%s\n",
        active_slot[0],
        runtime_generation
    );
    return length > 0 && length < (int)sizeof(payload)
        ? wls_atomic_root_write(healthy_path, payload, (size_t)length)
        : -1;
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
    if (fd < 0) {
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
    while (used + 1U < capacity) {
        ssize_t amount = read(fd, buffer + used, capacity - used - 1U);
        size_t index;
        if (amount <= 0) {
            return amount;
        }
        for (index = 0U; index < (size_t)amount; index++) {
            if (buffer[used + index] == '\n') {
                used += index + 1U;
                buffer[used] = '\0';
                return (ssize_t)used;
            }
        }
        used += (size_t)amount;
    }
    errno = EMSGSIZE;
    return -1;
}

static int wls_connect_controller(const char *path)
{
    int fd;
    struct sockaddr_un address;
    size_t length = strlen(path);
    if (length == 0U || length >= sizeof(address.sun_path)) {
        errno = ENAMETOOLONG;
        return -1;
    }
    fd = socket(AF_UNIX, SOCK_STREAM, 0);
    if (fd < 0) return -1;
    memset(&address, 0, sizeof(address));
    address.sun_family = AF_UNIX;
    memcpy(address.sun_path, path, length + 1U);
    if (connect(fd, (struct sockaddr *)&address, sizeof(address)) != 0) {
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

static int wls_reap_controller(pid_t controller_pid, int options)
{
    pid_t waited;
    do {
        waited = waitpid(controller_pid, NULL, options);
    } while (waited < 0 && errno == EINTR);
    return waited == controller_pid ? 0 : -1;
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

static int wls_repair_trust_access(
    const char *home,
    const struct passwd *controller_account
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
        || controller_account == NULL) {
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
        || status.st_gid != controller_account->pw_gid) {
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
    int fd;
    size_t length;
    if (wls_registry_path(path, sizeof(path), home) != 0 || record == NULL) return -1;
    fd = open(path, O_WRONLY | O_APPEND | O_CREAT | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (fd < 0
        || fstat(fd, &status) != 0
        || !S_ISREG(status.st_mode)
        || status.st_uid != geteuid()
        || (status.st_mode & 0077) != 0) {
        if (fd >= 0) close(fd);
        errno = EPERM;
        return -1;
    }
    length = strlen(record);
    if (wls_write_all(fd, record, length) != 0 || fsync(fd) != 0) {
        close(fd);
        return -1;
    }
    close(fd);
    return 0;
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
    FILE *stream;
    int found = 0;
    unsigned long revoked_generation = 0U;
    if (wls_registry_path(path, sizeof(path), home) != 0) return -1;
    stream = fopen(path, "r");
    if (stream == NULL) return -1;
    while (fgets(line, sizeof(line), stream) != NULL) {
        char *save = NULL;
        char *kind = strtok_r(line, "\t\r\n", &save);
        char *record_project = strtok_r(NULL, "\t\r\n", &save);
        char *record_generation = strtok_r(NULL, "\t\r\n", &save);
        char *end = NULL;
        unsigned long parsed_generation;
        if (kind == NULL || record_project == NULL || record_generation == NULL
            || strcmp(record_project, project) != 0) {
            continue;
        }
        errno = 0;
        parsed_generation = strtoul(record_generation, &end, 10);
        if (errno != 0 || end == record_generation || *end != '\0') continue;
        if (strcmp(kind, "R") == 0) {
            if (parsed_generation > revoked_generation) revoked_generation = parsed_generation;
            continue;
        }
        if (strcmp(kind, "A") == 0 && parsed_generation == generation) {
            char *record_owner = strtok_r(NULL, "\t\r\n", &save);
            char *record_alias = strtok_r(NULL, "\t\r\n", &save);
            char *record_root = strtok_r(NULL, "\t\r\n", &save);
            unsigned long parsed_owner;
            if (record_owner == NULL || record_alias == NULL || record_root == NULL
                || strcmp(record_alias, alias) != 0
                || strlen(record_root) >= sizeof(root_hex)) {
                continue;
            }
            errno = 0;
            parsed_owner = strtoul(record_owner, &end, 10);
            if (errno != 0 || end == record_owner || *end != '\0') continue;
            memcpy(root_hex, record_root, strlen(record_root) + 1U);
            *owner = parsed_owner;
            found = 1;
        }
    }
    fclose(stream);
    if (!found || revoked_generation >= generation
        || wls_hex_decode(root_hex, root, root_capacity) != 0) {
        errno = EPERM;
        return -1;
    }
    return 0;
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
    char *end = NULL;
    unsigned long generation;
    unsigned long owner;
    struct stat project_status;
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
    errno = 0;
    generation = strtoul(generation_text, &end, 10);
    if (errno != 0 || end == generation_text || *end != '\0' || generation == 0U) return -1;
    errno = 0;
    owner = strtoul(owner_text, &end, 10);
    if (errno != 0 || end == owner_text || *end != '\0') return -1;
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
        || project_status.st_uid != (uid_t)owner) {
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
    char *end = NULL;
    unsigned long generation;
    if (generation_text == NULL
        || (peer->uid != 0U && !(geteuid() != 0 && peer->uid == (unsigned long)geteuid()))
        || !wls_is_uuid(project)) {
        errno = EPERM;
        return -1;
    }
    errno = 0;
    generation = strtoul(generation_text, &end, 10);
    if (errno != 0 || end == generation_text || *end != '\0' || generation == 0U) return -1;
    if (snprintf(record, sizeof(record), "R\t%s\t%lu\n", project, generation)
        >= (int)sizeof(record)) {
        return -1;
    }
    return wls_registry_append(home, record);
}

static int wls_snapshot_enrolled(
    const char *home,
    const struct wls_peer *peer,
    const struct passwd *controller_account,
    const char *project,
    const char *generation_text,
    const char *alias,
    const char *source_relative_hex,
    const char *digest,
    const char *leaf
) {
    char *end = NULL;
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
    errno = 0;
    generation = strtoul(generation_text, &end, 10);
    if (errno != 0 || end == generation_text || *end != '\0' || generation == 0U
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
        controller_account != NULL ? controller_account->pw_uid : (uid_t)-1,
        controller_account != NULL ? controller_account->pw_gid : (gid_t)-1
    );
}

static int wls_handle_action(
    char *line,
    const char *channel,
    const struct wls_peer *peer,
    const char *home,
    const struct passwd *controller_account
) {
    char *save = NULL;
    char *protocol = strtok_r(line, "\t\r\n", &save);
    char *operation = strtok_r(NULL, "\t\r\n", &save);
    char *project;
    char *generation;
    if (home == NULL || protocol == NULL || operation == NULL
        || strcmp(protocol, "WLS-ACTION/1") != 0) {
        errno = EPROTO;
        return -1;
    }
    if (strcmp(operation, "STOP") == 0 && strcmp(channel, "admin") == 0) {
        char *epoch = strtok_r(NULL, "\t\r\n", &save);
        return wls_write_admin_stopped(home, peer, epoch);
    }
    if (strcmp(operation, "AUTH") == 0 && strcmp(channel, "admin") == 0) {
        char *owner;
        char *alias;
        char *project_root;
        char *certificate_root;
        project = strtok_r(NULL, "\t\r\n", &save);
        generation = strtok_r(NULL, "\t\r\n", &save);
        owner = strtok_r(NULL, "\t\r\n", &save);
        alias = strtok_r(NULL, "\t\r\n", &save);
        project_root = strtok_r(NULL, "\t\r\n", &save);
        certificate_root = strtok_r(NULL, "\t\r\n", &save);
        return wls_authorize_root(
            home,
            peer,
            project,
            generation,
            owner,
            alias,
            project_root,
            certificate_root
        );
    }
    if (strcmp(operation, "REVOKE") == 0 && strcmp(channel, "admin") == 0) {
        project = strtok_r(NULL, "\t\r\n", &save);
        generation = strtok_r(NULL, "\t\r\n", &save);
        return wls_revoke_roots(home, peer, project, generation);
    }
    if (strcmp(operation, "SNAP") == 0 && strcmp(channel, "project") == 0) {
        char *alias;
        char *source_relative;
        char *digest;
        char *leaf;
        project = strtok_r(NULL, "\t\r\n", &save);
        generation = strtok_r(NULL, "\t\r\n", &save);
        alias = strtok_r(NULL, "\t\r\n", &save);
        source_relative = strtok_r(NULL, "\t\r\n", &save);
        digest = strtok_r(NULL, "\t\r\n", &save);
        leaf = strtok_r(NULL, "\t\r\n", &save);
        return wls_snapshot_enrolled(
            home,
            peer,
            controller_account,
            project,
            generation,
            alias,
            source_relative,
            digest,
            leaf
        );
    }
    errno = EPERM;
    return -1;
}

static void wls_handle(
    int client,
    const char *channel,
    const char *controller_socket,
    const char *fencing,
    const char *home,
    const struct passwd *controller_account
) {
    int controller = -1;
    struct wls_peer peer;
    char *request = NULL;
    char *response = NULL;
    char header[512];
    ssize_t request_length;
    ssize_t response_length;
    int header_length;
    struct timeval io_timeout;
    io_timeout.tv_sec = 2;
    io_timeout.tv_usec = 0;
    if (setsockopt(client, SOL_SOCKET, SO_RCVTIMEO, &io_timeout, sizeof(io_timeout)) != 0
        || setsockopt(client, SOL_SOCKET, SO_SNDTIMEO, &io_timeout, sizeof(io_timeout)) != 0
    ) {
        goto cleanup;
    }
    request = malloc(WLS_MAX_REQUEST + 2U);
    response = malloc(WLS_MAX_REQUEST + 2U);
    if (request == NULL || response == NULL || wls_peer_identity(client, &peer) != 0) {
        goto cleanup;
    }
    request_length = wls_read_line(client, request, WLS_MAX_REQUEST + 2U);
    if (request_length <= 0) goto cleanup;
    controller = wls_connect_controller(controller_socket);
    if (controller < 0) goto cleanup;
    header_length = snprintf(
        header,
        sizeof(header),
        "{\"broker_schema\":1,\"action_protocol\":1,\"channel\":\"%s\",\"uid\":%lu,\"gid\":%lu,"
        "\"pid\":%ld,\"fencing_token\":\"%s\",\"payload_length\":%ld}\n",
        channel,
        peer.uid,
        peer.gid,
        peer.pid,
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
    while ((response_length = wls_read_line(controller, response, WLS_MAX_REQUEST + 2U)) > 0) {
        if (strncmp(response, "WLS-ACTION/1\t", 13U) == 0) {
            int action_result = wls_handle_action(
                response,
                channel,
                &peer,
                home,
                controller_account
            );
            char action_response[96];
            int action_length = snprintf(
                action_response,
                sizeof(action_response),
                action_result == 0
                    ? "WLS-ACTION/1\tOK\n"
                    : "WLS-ACTION/1\tERR\t%d\n",
                action_result == 0 ? 0 : errno
            );
            if (action_length <= 0
                || action_length >= (int)sizeof(action_response)
                || wls_write_all(controller, action_response, (size_t)action_length) != 0) {
                goto cleanup;
            }
            continue;
        }
        (void)wls_write_all(client, response, (size_t)response_length);
        break;
    }
cleanup:
    if (controller >= 0) close(controller);
    if (client >= 0) close(client);
    free(request);
    free(response);
}

static void wls_handler_finished(void)
{
    if (pthread_mutex_lock(&wls_handler_mutex) != 0) return;
    if (wls_active_handlers > 0U) {
        wls_active_handlers--;
    }
    (void)pthread_mutex_unlock(&wls_handler_mutex);
}

static void *wls_handler_thread(void *argument)
{
    struct wls_handler_context *context = argument;
    wls_handle(
        context->client,
        context->channel,
        context->controller_socket,
        context->fencing,
        context->home,
        context->controller_account
    );
    free(context);
    wls_handler_finished();
    return NULL;
}

static void wls_dispatch(
    int listener,
    const char *channel,
    const char *controller_socket,
    const char *fencing,
    const char *home,
    const struct passwd *controller_account
) {
    int client = accept(listener, NULL, NULL);
    struct wls_handler_context *context;
    pthread_attr_t attributes;
    pthread_t thread;
    int reserved = 0;
    if (client < 0) return;
    if (pthread_mutex_lock(&wls_handler_mutex) == 0) {
        if (wls_active_handlers < WLS_MAX_HANDLERS) {
            wls_active_handlers++;
            reserved = 1;
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
        wls_handler_finished();
        return;
    }
    context->client = client;
    context->channel = channel;
    context->controller_socket = controller_socket;
    context->fencing = fencing;
    context->home = home;
    context->controller_account = controller_account;
    if (pthread_attr_init(&attributes) != 0) {
        close(client);
        free(context);
        wls_handler_finished();
        return;
    }
    if (pthread_attr_setdetachstate(&attributes, PTHREAD_CREATE_DETACHED) != 0
        || pthread_create(&thread, &attributes, wls_handler_thread, context) != 0) {
        (void)pthread_attr_destroy(&attributes);
        close(client);
        free(context);
        wls_handler_finished();
        return;
    }
    (void)pthread_attr_destroy(&attributes);
}

static pid_t wls_start_controller(
    const char *php,
    const char *controller,
    const char *home,
    const char *controller_socket,
    const char *controller_user
) {
    struct passwd *account;
    pid_t child;
    char home_argument[PATH_MAX + 16U];
    char broker_argument[PATH_MAX + 32U];
    if (php == NULL || controller == NULL || home == NULL
        || controller_socket == NULL || controller_user == NULL) {
        return 0;
    }
    account = getpwnam(controller_user);
    if (account == NULL
        || snprintf(home_argument, sizeof(home_argument), "--home=%s", home)
            >= (int)sizeof(home_argument)
        || snprintf(
            broker_argument,
            sizeof(broker_argument),
            "--broker-internal=unix://%s",
            controller_socket
        ) >= (int)sizeof(broker_argument)) {
        return -1;
    }
    child = fork();
    if (child != 0) {
        return child;
    }
    if (setgroups(0, NULL) != 0
        || setgid(account->pw_gid) != 0
        || setuid(account->pw_uid) != 0) {
        _exit(126);
    }
    execl(php, php, controller, home_argument, broker_argument, (char *)NULL);
    _exit(127);
}

static int wls_wait_for_controller(const char *socket_path, pid_t controller_pid)
{
    unsigned int attempt;
    struct stat status;
    for (attempt = 0U; attempt < WLS_CONTROLLER_START_ATTEMPTS; attempt++) {
        if (lstat(socket_path, &status) == 0 && S_ISSOCK(status.st_mode)) {
            return 0;
        }
        if (controller_pid > 0 && waitpid(controller_pid, NULL, WNOHANG) == controller_pid) {
            return -1;
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
    char fencing[WLS_TOKEN_HEX + 1U];
    fd_set read_set;
    struct timeval timeout;
    struct timespec observation_started;
    struct timespec observation_now;
    int upgrade_marked = 0;
    int maximum;
    pid_t controller_pid = 0;
    struct passwd *controller_account = NULL;
    lock_fd = open(lock_file, O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW, 0600);
    if (lock_fd < 0 || flock(lock_fd, LOCK_EX | LOCK_NB) != 0) {
        fprintf(stderr, "broker singleton lock unavailable: %s\n", strerror(errno));
        goto failed;
    }
    if (controller_user != NULL) {
        controller_account = getpwnam(controller_user);
        if (controller_account == NULL) {
            fprintf(stderr, "broker controller account is unavailable\n");
            goto failed;
        }
        if (wls_repair_trust_access(home, controller_account) != 0) {
            fprintf(stderr, "broker trust ACL is unsafe: %s\n", strerror(errno));
            goto failed;
        }
        if ((geteuid() == 0
                && (setgroups(0, NULL) != 0
                    || setgid(controller_account->pw_gid) != 0))
            || (geteuid() != 0 && getegid() != controller_account->pw_gid)
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
                || S_ISLNK(directory_status.st_mode)
                || chown(controller_directory, 0, controller_account->pw_gid) != 0
                || chmod(controller_directory, 0771) != 0) {
                fprintf(stderr, "broker controller run directory is unsafe\n");
                goto failed;
            }
        }
    }
    if (wls_random_token(fencing) != 0
        || wls_write_fencing(fencing_file, fencing, controller_account) != 0) {
        fprintf(stderr, "broker fencing token unavailable: %s\n", strerror(errno));
        goto failed;
    }
    controller_pid = wls_start_controller(
        php,
        controller,
        home,
        controller_socket,
        controller_user
    );
    if (controller_pid < 0
        || (controller_pid > 0 && wls_wait_for_controller(controller_socket, controller_pid) != 0)) {
        fprintf(stderr, "broker controller launch failed: %s\n", strerror(errno));
        goto failed;
    }
    admin_fd = wls_create_listener(admin_socket, 0600);
    project_fd = wls_create_listener(project_socket, 0622);
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
    if (clock_gettime(CLOCK_MONOTONIC, &observation_started) != 0) {
        goto failed;
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
        if (controller_pid > 0
            && wls_reap_controller(controller_pid, WNOHANG) == 0) {
            wls_release_controller_socket(controller_socket);
            controller_pid = 0;
            errno = ECHILD;
            fprintf(stderr, "broker controller exited; requesting platform restart\n");
            goto failed;
        }
        if (!upgrade_marked
            && active_slot != NULL
            && runtime_generation != NULL
            && clock_gettime(CLOCK_MONOTONIC, &observation_now) == 0
            && observation_now.tv_sec - observation_started.tv_sec >= 300) {
            if (wls_write_upgrade_healthy(
                home,
                active_slot,
                runtime_generation
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
                controller_account
            );
        }
        if (FD_ISSET(project_fd, &read_set)) {
            wls_dispatch(
                project_fd,
                "project",
                controller_socket,
                fencing,
                home,
                controller_account
            );
        }
    }
    close(admin_fd);
    close(project_fd);
    unlink(admin_socket);
    unlink(project_socket);
    if (controller_pid > 0) {
        (void)kill(controller_pid, SIGTERM);
        if (wls_reap_controller(controller_pid, 0) == 0) {
            wls_release_controller_socket(controller_socket);
        }
    }
    close(lock_fd);
    return 0;

failed:
    if (admin_fd >= 0) close(admin_fd);
    if (project_fd >= 0) close(project_fd);
    if (admin_socket != NULL) unlink(admin_socket);
    if (project_socket != NULL) unlink(project_socket);
    if (controller_pid > 0) {
        (void)kill(controller_pid, SIGTERM);
        if (wls_reap_controller(controller_pid, 0) == 0) {
            wls_release_controller_socket(controller_socket);
        }
    }
    if (lock_fd >= 0) close(lock_fd);
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
        || lock_file == NULL || fencing_file == NULL || home == NULL) {
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
