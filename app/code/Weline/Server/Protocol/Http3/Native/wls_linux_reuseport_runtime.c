#define _GNU_SOURCE

#include "wls_linux_reuseport_runtime.h"
#include "wls_linux_reuseport_bpf_code.h"

#if defined(__linux__)

#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <linux/bpf.h>
#include <linux/magic.h>
#include <netdb.h>
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/epoll.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/statfs.h>
#include <sys/syscall.h>
#include <sys/types.h>
#include <unistd.h>

#define WLS_LINUX_H3_BPFFS_ROOT "/sys/fs/bpf"
#define WLS_LINUX_H3_MAP_LISTEN "listen_map"
#define WLS_LINUX_H3_MAP_WORKER "worker_map"
#define WLS_LINUX_H3_MAP_COUNT "count_map"
#define WLS_LINUX_H3_MAP_OWNER "owner_map"
#define WLS_LINUX_H3_BPF_LOG_BYTES (1024u * 1024u)
#define WLS_LINUX_H3_WORKER_MAP_ENTRIES 262144u

static int wls_linux_error(char *error, size_t capacity,
                           const char *format, ...) {
  if (error && capacity != 0) {
    va_list arguments;
    va_start(arguments, format);
    (void)vsnprintf(error, capacity, format, arguments);
    va_end(arguments);
  }
  return WLS_TRANSPORT_SOCKET_ERROR;
}

static int wls_linux_bpf(enum bpf_cmd command, union bpf_attr *attributes) {
  return (int)syscall(__NR_bpf, command, attributes, sizeof(*attributes));
}

static uint64_t wls_linux_namespace_hash(const char *value) {
  uint64_t hash = UINT64_C(1469598103934665603);
  const unsigned char *cursor = (const unsigned char *)value;
  while (*cursor != 0) {
    hash ^= *cursor++;
    hash *= UINT64_C(1099511628211);
  }
  return hash == 0 ? 1 : hash;
}

static int wls_linux_format(char *target, size_t capacity,
                            const char *format, ...) {
  va_list arguments;
  va_start(arguments, format);
  int length = vsnprintf(target, capacity, format, arguments);
  va_end(arguments);
  return length >= 0 && (size_t)length < capacity ? 0 : -1;
}

static int wls_linux_secure_directory(const char *path, mode_t mode,
                                      int root_owner_allowed,
                                      char *error, size_t error_capacity) {
  if (mkdir(path, mode) != 0 && errno != EEXIST) {
    return wls_linux_error(error, error_capacity, "mkdir(%s): %s",
                           path, strerror(errno));
  }
  struct stat metadata;
  if (lstat(path, &metadata) != 0) {
    return wls_linux_error(error, error_capacity, "lstat(%s): %s",
                           path, strerror(errno));
  }
  uid_t uid = geteuid();
  if (!S_ISDIR(metadata.st_mode) ||
      (metadata.st_uid != uid &&
       (!root_owner_allowed || metadata.st_uid != 0))) {
    return wls_linux_error(error, error_capacity,
                           "unsafe bpffs directory ownership: %s", path);
  }
  if (metadata.st_uid == uid && chmod(path, mode) != 0) {
    return wls_linux_error(error, error_capacity, "chmod(%s): %s",
                           path, strerror(errno));
  }
  return WLS_TRANSPORT_OK;
}

static int wls_linux_prepare_namespace(
  wls_linux_h3_route *route, const char *namespace_key,
  char *error, size_t error_capacity) {
  struct statfs filesystem;
  if (statfs(WLS_LINUX_H3_BPFFS_ROOT, &filesystem) != 0 ||
      (unsigned long)filesystem.f_type != (unsigned long)BPF_FS_MAGIC) {
    return wls_linux_error(
      error, error_capacity,
      "%s is not a mounted bpffs; Linux Direct HTTP/3 cannot start",
      WLS_LINUX_H3_BPFFS_ROOT);
  }

  char user_root[WLS_LINUX_H3_PIN_PATH_CAPACITY];
  char namespace_root[WLS_LINUX_H3_PIN_PATH_CAPACITY];
  uint64_t digest = wls_linux_namespace_hash(namespace_key);
  if (wls_linux_format(user_root, sizeof(user_root), "%s/weline/%lu",
                       WLS_LINUX_H3_BPFFS_ROOT,
                       (unsigned long)geteuid()) != 0 ||
      wls_linux_format(namespace_root, sizeof(namespace_root),
                       "%s/ns-%016llx", user_root,
                       (unsigned long long)digest) != 0 ||
      wls_linux_format(route->pin_namespace,
                       sizeof(route->pin_namespace), "%s/abi-%08x",
                       namespace_root,
                       (unsigned)WLS_TRANSPORT_ABI_VERSION) != 0 ||
      wls_linux_format(route->lock_path, sizeof(route->lock_path),
                       "/tmp/weline-h3-%lu-%016llx.lock",
                       (unsigned long)geteuid(),
                       (unsigned long long)digest) != 0) {
    return wls_linux_error(error, error_capacity,
                           "Linux HTTP/3 namespace path is too long");
  }

  char weline_root[WLS_LINUX_H3_PIN_PATH_CAPACITY];
  if (wls_linux_format(weline_root, sizeof(weline_root), "%s/weline",
                       WLS_LINUX_H3_BPFFS_ROOT) != 0) {
    return wls_linux_error(error, error_capacity,
                           "Linux HTTP/3 bpffs path is too long");
  }
  int result = wls_linux_secure_directory(
    weline_root, 0755, 1, error, error_capacity);
  if (result != WLS_TRANSPORT_OK) {
    return result;
  }
  result = wls_linux_secure_directory(
    user_root, 0700, 0, error, error_capacity);
  if (result != WLS_TRANSPORT_OK) {
    return result;
  }
  result = wls_linux_secure_directory(
    namespace_root, 0700, 0, error, error_capacity);
  if (result != WLS_TRANSPORT_OK) {
    return result;
  }
  return wls_linux_secure_directory(
    route->pin_namespace, 0700, 0, error, error_capacity);
}

static int wls_linux_open_lock(wls_linux_h3_route *route,
                               char *error, size_t error_capacity) {
  int descriptor = open(route->lock_path,
                        O_CREAT | O_RDWR | O_CLOEXEC | O_NOFOLLOW, 0600);
  if (descriptor < 0) {
    return wls_linux_error(error, error_capacity, "open(%s): %s",
                           route->lock_path, strerror(errno));
  }
  struct stat metadata;
  if (fstat(descriptor, &metadata) != 0 || !S_ISREG(metadata.st_mode) ||
      metadata.st_uid != geteuid()) {
    close(descriptor);
    return wls_linux_error(error, error_capacity,
                           "unsafe Linux HTTP/3 route lock");
  }
  route->lock_fd = descriptor;
  return WLS_TRANSPORT_OK;
}

static int wls_linux_lock(wls_linux_h3_route *route,
                          char *error, size_t error_capacity) {
  if (!route || route->lock_fd < 0 ||
      flock(route->lock_fd, LOCK_EX) != 0) {
    return wls_linux_error(error, error_capacity,
                           "lock Linux HTTP/3 route: %s", strerror(errno));
  }
  return WLS_TRANSPORT_OK;
}

static void wls_linux_unlock(wls_linux_h3_route *route) {
  if (route && route->lock_fd >= 0) {
    (void)flock(route->lock_fd, LOCK_UN);
  }
}

static int wls_linux_bpf_map_create(
  enum bpf_map_type type, uint32_t key_size, uint32_t value_size,
  uint32_t max_entries, uint32_t map_flags, const char *name) {
  union bpf_attr attributes;
  memset(&attributes, 0, sizeof(attributes));
  attributes.map_type = type;
  attributes.key_size = key_size;
  attributes.value_size = value_size;
  attributes.max_entries = max_entries;
  attributes.map_flags = map_flags;
  (void)snprintf(attributes.map_name, sizeof(attributes.map_name), "%s",
                 name);
  return wls_linux_bpf(BPF_MAP_CREATE, &attributes);
}

static int wls_linux_bpf_object_get(const char *path) {
  union bpf_attr attributes;
  memset(&attributes, 0, sizeof(attributes));
  attributes.pathname = (uint64_t)(uintptr_t)path;
  return wls_linux_bpf(BPF_OBJ_GET, &attributes);
}

static int wls_linux_bpf_object_pin(int descriptor, const char *path) {
  union bpf_attr attributes;
  memset(&attributes, 0, sizeof(attributes));
  attributes.bpf_fd = (uint32_t)descriptor;
  attributes.pathname = (uint64_t)(uintptr_t)path;
  return wls_linux_bpf(BPF_OBJ_PIN, &attributes);
}

static int wls_linux_bpf_map_info(int descriptor,
                                  struct bpf_map_info *information) {
  union bpf_attr attributes;
  uint32_t length = sizeof(*information);
  memset(&attributes, 0, sizeof(attributes));
  memset(information, 0, sizeof(*information));
  attributes.info.bpf_fd = (uint32_t)descriptor;
  attributes.info.info_len = length;
  attributes.info.info = (uint64_t)(uintptr_t)information;
  return wls_linux_bpf(BPF_OBJ_GET_INFO_BY_FD, &attributes);
}

static int wls_linux_bpf_program_info(int descriptor,
                                      struct bpf_prog_info *information) {
  union bpf_attr attributes;
  uint32_t length = sizeof(*information);
  memset(&attributes, 0, sizeof(attributes));
  memset(information, 0, sizeof(*information));
  attributes.info.bpf_fd = (uint32_t)descriptor;
  attributes.info.info_len = length;
  attributes.info.info = (uint64_t)(uintptr_t)information;
  return wls_linux_bpf(BPF_OBJ_GET_INFO_BY_FD, &attributes);
}

static int wls_linux_bpf_map_update(int descriptor, const void *key,
                                    const void *value, uint64_t flags) {
  union bpf_attr attributes;
  memset(&attributes, 0, sizeof(attributes));
  attributes.map_fd = (uint32_t)descriptor;
  attributes.key = (uint64_t)(uintptr_t)key;
  attributes.value = (uint64_t)(uintptr_t)value;
  attributes.flags = flags;
  return wls_linux_bpf(BPF_MAP_UPDATE_ELEM, &attributes);
}

static int wls_linux_bpf_map_lookup(int descriptor, const void *key,
                                    void *value) {
  union bpf_attr attributes;
  memset(&attributes, 0, sizeof(attributes));
  attributes.map_fd = (uint32_t)descriptor;
  attributes.key = (uint64_t)(uintptr_t)key;
  attributes.value = (uint64_t)(uintptr_t)value;
  return wls_linux_bpf(BPF_MAP_LOOKUP_ELEM, &attributes);
}

static int wls_linux_bpf_map_delete(int descriptor, const void *key) {
  union bpf_attr attributes;
  memset(&attributes, 0, sizeof(attributes));
  attributes.map_fd = (uint32_t)descriptor;
  attributes.key = (uint64_t)(uintptr_t)key;
  return wls_linux_bpf(BPF_MAP_DELETE_ELEM, &attributes);
}

static int wls_linux_open_or_create_map(
  wls_linux_h3_route *route, const char *pin_name,
  enum bpf_map_type type, uint32_t key_size, uint32_t value_size,
  uint32_t max_entries, uint32_t map_flags, uint32_t *map_id,
  char *error, size_t error_capacity) {
  char path[WLS_LINUX_H3_PIN_PATH_CAPACITY];
  if (wls_linux_format(path, sizeof(path), "%s/%s",
                       route->pin_namespace, pin_name) != 0) {
    return wls_linux_error(error, error_capacity,
                           "Linux HTTP/3 map path is too long");
  }

  int descriptor = wls_linux_bpf_object_get(path);
  if (descriptor < 0 && errno == ENOENT) {
    descriptor = wls_linux_bpf_map_create(
      type, key_size, value_size, max_entries, map_flags, pin_name);
    if (descriptor < 0) {
      return wls_linux_error(error, error_capacity,
                             "create BPF map %s: %s",
                             pin_name, strerror(errno));
    }
    if (wls_linux_bpf_object_pin(descriptor, path) != 0) {
      int pin_error = errno;
      if (pin_error == EEXIST) {
        close(descriptor);
        descriptor = wls_linux_bpf_object_get(path);
      } else {
        close(descriptor);
        return wls_linux_error(error, error_capacity,
                               "pin BPF map %s: %s",
                               pin_name, strerror(pin_error));
      }
    }
  }
  if (descriptor < 0) {
    return wls_linux_error(error, error_capacity,
                           "open BPF map %s: %s",
                           pin_name, strerror(errno));
  }

  struct bpf_map_info information;
  if (wls_linux_bpf_map_info(descriptor, &information) != 0 ||
      information.type != (uint32_t)type ||
      information.key_size != key_size ||
      information.value_size != value_size ||
      information.max_entries != max_entries) {
    close(descriptor);
    return wls_linux_error(error, error_capacity,
                           "pinned BPF map ABI mismatch: %s", pin_name);
  }
  if (map_id) {
    *map_id = information.id;
  }
  return descriptor;
}

static int wls_linux_prepare_maps(wls_linux_h3_route *route,
                                  char *error, size_t error_capacity) {
  route->listen_map_fd = wls_linux_open_or_create_map(
    route, WLS_LINUX_H3_MAP_LISTEN, BPF_MAP_TYPE_SOCKMAP,
    sizeof(uint32_t), sizeof(uint64_t), WLS_LINUX_H3_MAX_ROUTE_SLOTS,
    0, &route->listen_map_id, error, error_capacity);
  if (route->listen_map_fd < 0) {
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  route->worker_map_fd = wls_linux_open_or_create_map(
    route, WLS_LINUX_H3_MAP_WORKER, BPF_MAP_TYPE_SOCKHASH,
    WLS_LINUX_H3_SERVER_CID_LENGTH, sizeof(uint64_t),
    WLS_LINUX_H3_WORKER_MAP_ENTRIES, 0,
    &route->worker_map_id, error, error_capacity);
  if (route->worker_map_fd < 0) {
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  route->count_map_fd = wls_linux_open_or_create_map(
    route, WLS_LINUX_H3_MAP_COUNT, BPF_MAP_TYPE_ARRAY,
    sizeof(uint32_t), sizeof(uint32_t), 1u, 0,
    &route->count_map_id, error, error_capacity);
  if (route->count_map_fd < 0) {
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  route->owner_map_fd = wls_linux_open_or_create_map(
    route, WLS_LINUX_H3_MAP_OWNER, BPF_MAP_TYPE_ARRAY,
    sizeof(uint32_t), sizeof(wls_linux_h3_route_owner),
    WLS_LINUX_H3_MAX_ROUTE_SLOTS, 0,
    &route->owner_map_id, error, error_capacity);
  return route->owner_map_fd < 0
    ? WLS_TRANSPORT_SOCKET_ERROR : WLS_TRANSPORT_OK;
}

static int wls_linux_patch_map_instruction(
  struct bpf_insn *instructions, size_t instruction_count,
  size_t index, int map_fd, char *error, size_t error_capacity) {
  if (index + 1 >= instruction_count ||
      instructions[index].code != (BPF_LD | BPF_DW | BPF_IMM)) {
    return wls_linux_error(error, error_capacity,
                           "generated eBPF relocation table is invalid");
  }
  instructions[index].src_reg = BPF_PSEUDO_MAP_FD;
  instructions[index].imm = map_fd;
  instructions[index + 1].imm = 0;
  return WLS_TRANSPORT_OK;
}

static int wls_linux_load_program(wls_linux_h3_route *route,
                                  char *error, size_t error_capacity) {
  if (wls_linux_reuseport_bpf_code_len == 0 ||
      wls_linux_reuseport_bpf_code_len % sizeof(struct bpf_insn) != 0) {
    return wls_linux_error(error, error_capacity,
                           "generated eBPF instruction stream is invalid");
  }
  size_t instruction_count =
    wls_linux_reuseport_bpf_code_len / sizeof(struct bpf_insn);
  struct bpf_insn *instructions =
    malloc(wls_linux_reuseport_bpf_code_len);
  if (!instructions) {
    return WLS_TRANSPORT_NOMEM;
  }
  memcpy(instructions, wls_linux_reuseport_bpf_code,
         wls_linux_reuseport_bpf_code_len);

  const size_t worker_relocation_count =
    sizeof(wls_linux_reuseport_bpf_worker_map_relocations) /
      sizeof(wls_linux_reuseport_bpf_worker_map_relocations[0]);
  const size_t count_relocation_count =
    sizeof(wls_linux_reuseport_bpf_count_map_relocations) /
      sizeof(wls_linux_reuseport_bpf_count_map_relocations[0]);
  const size_t listen_relocation_count =
    sizeof(wls_linux_reuseport_bpf_listen_map_relocations) /
      sizeof(wls_linux_reuseport_bpf_listen_map_relocations[0]);
  if (worker_relocation_count != 1u || count_relocation_count != 1u ||
      listen_relocation_count != WLS_LINUX_H3_MAX_ROUTE_SLOTS) {
    free(instructions);
    return wls_linux_error(error, error_capacity,
                           "generated eBPF relocation metadata is invalid");
  }

  int result = wls_linux_patch_map_instruction(
    instructions, instruction_count,
    wls_linux_reuseport_bpf_worker_map_relocations[0],
    route->worker_map_fd, error, error_capacity);
  if (result == WLS_TRANSPORT_OK) {
    result = wls_linux_patch_map_instruction(
      instructions, instruction_count,
      wls_linux_reuseport_bpf_count_map_relocations[0],
      route->count_map_fd, error, error_capacity);
  }
  for (size_t index = 0;
       result == WLS_TRANSPORT_OK &&
         index < listen_relocation_count;
       ++index) {
    result = wls_linux_patch_map_instruction(
      instructions, instruction_count,
      wls_linux_reuseport_bpf_listen_map_relocations[index],
      route->listen_map_fd, error, error_capacity);
  }
  if (result != WLS_TRANSPORT_OK) {
    free(instructions);
    return result;
  }

  char *log = calloc(1, WLS_LINUX_H3_BPF_LOG_BYTES);
  if (!log) {
    free(instructions);
    return WLS_TRANSPORT_NOMEM;
  }
  static const char license[] = "BSD";
  union bpf_attr attributes;
  memset(&attributes, 0, sizeof(attributes));
  attributes.prog_type = BPF_PROG_TYPE_SK_REUSEPORT;
  attributes.expected_attach_type = BPF_SK_REUSEPORT_SELECT;
  attributes.insn_cnt = (uint32_t)instruction_count;
  attributes.insns = (uint64_t)(uintptr_t)instructions;
  attributes.license = (uint64_t)(uintptr_t)license;
  attributes.log_buf = (uint64_t)(uintptr_t)log;
  attributes.log_size = WLS_LINUX_H3_BPF_LOG_BYTES;
  attributes.log_level = 1;
  (void)snprintf(attributes.prog_name, sizeof(attributes.prog_name),
                 "wls_h3_route");

  int descriptor = wls_linux_bpf(BPF_PROG_LOAD, &attributes);
  int load_error = errno;
  if (descriptor < 0) {
    wls_linux_error(error, error_capacity,
                    "load Linux HTTP/3 reuseport eBPF: %s: %.240s",
                    strerror(load_error), log);
    free(log);
    free(instructions);
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  free(log);
  free(instructions);

  struct bpf_prog_info information;
  if (wls_linux_bpf_program_info(descriptor, &information) != 0) {
    close(descriptor);
    return wls_linux_error(error, error_capacity,
                           "inspect HTTP/3 eBPF program: %s",
                           strerror(errno));
  }
  route->program_id = information.id;
  route->program_fd = descriptor;
  return WLS_TRANSPORT_OK;
}

static int wls_linux_configure_udp_socket(int descriptor) {
  int one = 1;
  int buffer_bytes = 4 * 1024 * 1024;
  if (setsockopt(descriptor, SOL_SOCKET, SO_REUSEADDR,
                 &one, sizeof(one)) != 0 ||
      setsockopt(descriptor, SOL_SOCKET, SO_REUSEPORT,
                 &one, sizeof(one)) != 0) {
    return -1;
  }
  (void)setsockopt(descriptor, SOL_SOCKET, SO_RCVBUF,
                   &buffer_bytes, sizeof(buffer_bytes));
  (void)setsockopt(descriptor, SOL_SOCKET, SO_SNDBUF,
                   &buffer_bytes, sizeof(buffer_bytes));
  int flags = fcntl(descriptor, F_GETFL, 0);
  if (flags < 0 ||
      fcntl(descriptor, F_SETFL, flags | O_NONBLOCK) != 0) {
    return -1;
  }
  int descriptor_flags = fcntl(descriptor, F_GETFD, 0);
  return descriptor_flags < 0 ||
    fcntl(descriptor, F_SETFD, descriptor_flags | FD_CLOEXEC) != 0
    ? -1 : 0;
}

static int wls_linux_socket_cookie(int descriptor, uint64_t *cookie) {
  socklen_t length = sizeof(*cookie);
  return getsockopt(descriptor, SOL_SOCKET, SO_COOKIE,
                    cookie, &length);
}

static int wls_linux_attach_program(int descriptor, int program_fd) {
  return setsockopt(descriptor, SOL_SOCKET, SO_ATTACH_REUSEPORT_EBPF,
                    &program_fd, sizeof(program_fd));
}

static int wls_linux_add_epoll(int epoll_fd, int descriptor) {
  struct epoll_event event;
  memset(&event, 0, sizeof(event));
  event.events = EPOLLIN;
  event.data.fd = descriptor;
  return epoll_ctl(epoll_fd, EPOLL_CTL_ADD, descriptor, &event);
}

static int wls_linux_bind_sockets(
  wls_linux_h3_route *route, const char *host, uint16_t port,
  struct sockaddr_storage *bound_address, socklen_t *bound_length,
  uint16_t *bound_port, char *error, size_t error_capacity) {
  char service[6];
  (void)snprintf(service, sizeof(service), "%u", (unsigned)port);
  struct addrinfo hints;
  memset(&hints, 0, sizeof(hints));
  hints.ai_family = AF_UNSPEC;
  hints.ai_socktype = SOCK_DGRAM;
  hints.ai_protocol = IPPROTO_UDP;
  hints.ai_flags = AI_NUMERICSERV;

  struct addrinfo *addresses = NULL;
  int address_result = getaddrinfo(host, service, &hints, &addresses);
  if (address_result != 0) {
    return wls_linux_error(error, error_capacity,
                           "getaddrinfo(%s:%s): %s", host, service,
                           gai_strerror(address_result));
  }

  int last_error = EADDRNOTAVAIL;
  for (struct addrinfo *address = addresses; address;
       address = address->ai_next) {
    int listener = socket(address->ai_family, address->ai_socktype,
                          address->ai_protocol);
    if (listener < 0) {
      last_error = errno;
      continue;
    }
    if (wls_linux_configure_udp_socket(listener) != 0 ||
        bind(listener, address->ai_addr, address->ai_addrlen) != 0) {
      last_error = errno;
      close(listener);
      continue;
    }

    struct sockaddr_storage actual_address;
    socklen_t actual_length = sizeof(actual_address);
    memset(&actual_address, 0, sizeof(actual_address));
    if (getsockname(listener, (struct sockaddr *)&actual_address,
                    &actual_length) != 0) {
      last_error = errno;
      close(listener);
      continue;
    }

    int connection = socket(address->ai_family, address->ai_socktype,
                            address->ai_protocol);
    if (connection < 0 ||
        wls_linux_configure_udp_socket(connection) != 0 ||
        bind(connection, (struct sockaddr *)&actual_address,
             actual_length) != 0) {
      last_error = errno;
      if (connection >= 0) {
        close(connection);
      }
      close(listener);
      continue;
    }

    int wait_fd = epoll_create1(EPOLL_CLOEXEC);
    if (wait_fd < 0 ||
        wls_linux_add_epoll(wait_fd, listener) != 0 ||
        wls_linux_add_epoll(wait_fd, connection) != 0 ||
        wls_linux_socket_cookie(listener, &route->listener_cookie) != 0 ||
        wls_linux_socket_cookie(connection, &route->connection_cookie) != 0) {
      last_error = errno;
      if (wait_fd >= 0) {
        close(wait_fd);
      }
      close(connection);
      close(listener);
      continue;
    }

    route->listener_fd = listener;
    route->connection_fd = connection;
    route->wait_fd = wait_fd;
    memcpy(bound_address, &actual_address, actual_length);
    *bound_length = actual_length;
    if (actual_address.ss_family == AF_INET) {
      *bound_port = ntohs(
        ((const struct sockaddr_in *)&actual_address)->sin_port);
    } else {
      *bound_port = ntohs(
        ((const struct sockaddr_in6 *)&actual_address)->sin6_port);
    }
    freeaddrinfo(addresses);
    return WLS_TRANSPORT_OK;
  }

  freeaddrinfo(addresses);
  return wls_linux_error(error, error_capacity,
                         "bind Linux HTTP/3 dual UDP route: %s",
                         strerror(last_error));
}

void wls_linux_h3_route_init(wls_linux_h3_route *route) {
  if (!route) {
    return;
  }
  memset(route, 0, sizeof(*route));
  route->listener_fd = -1;
  route->connection_fd = -1;
  route->wait_fd = -1;
  route->listen_map_fd = -1;
  route->worker_map_fd = -1;
  route->count_map_fd = -1;
  route->owner_map_fd = -1;
  route->program_fd = -1;
  route->lock_fd = -1;
  route->state = WLS_H3_LINUX_ROUTE_DISABLED;
}

int wls_linux_h3_route_bind(
  wls_linux_h3_route *route, const char *host, uint16_t port,
  const wls_h3_linux_route_config *config,
  struct sockaddr_storage *bound_address, socklen_t *bound_length,
  uint16_t *bound_port, char *error, size_t error_capacity) {
  if (!route || !host || !config || !bound_address || !bound_length ||
      !bound_port ||
      config->struct_size != sizeof(wls_h3_linux_route_config) ||
      !config->namespace_key || config->namespace_key[0] == 0 ||
      config->slot_count == 0 ||
      config->slot_count > WLS_LINUX_H3_MAX_ROUTE_SLOTS ||
      config->slot >= config->slot_count ||
      config->owner_epoch == 0 || config->generation == 0 ||
      route->state != WLS_H3_LINUX_ROUTE_DISABLED) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  route->slot = config->slot;
  route->slot_count = config->slot_count;
  route->flags = config->flags;
  route->owner_epoch = config->owner_epoch;
  route->generation = config->generation;

  int result = wls_linux_prepare_namespace(
    route, config->namespace_key, error, error_capacity);
  if (result != WLS_TRANSPORT_OK) {
    route->state = WLS_H3_LINUX_ROUTE_FAILED;
    return result;
  }
  result = wls_linux_open_lock(route, error, error_capacity);
  if (result != WLS_TRANSPORT_OK) {
    route->state = WLS_H3_LINUX_ROUTE_FAILED;
    return result;
  }
  result = wls_linux_lock(route, error, error_capacity);
  if (result == WLS_TRANSPORT_OK) {
    result = wls_linux_prepare_maps(route, error, error_capacity);
  }
  wls_linux_unlock(route);
  if (result == WLS_TRANSPORT_OK) {
    result = wls_linux_load_program(route, error, error_capacity);
  }
  if (result == WLS_TRANSPORT_OK) {
    result = wls_linux_bind_sockets(
      route, host, port, bound_address, bound_length, bound_port,
      error, error_capacity);
  }
  if (result != WLS_TRANSPORT_OK) {
    route->state = WLS_H3_LINUX_ROUTE_FAILED;
    wls_linux_h3_route_close(route);
    return result;
  }
  route->state = WLS_H3_LINUX_ROUTE_STAGED;
  return WLS_TRANSPORT_OK;
}

int wls_linux_h3_route_activate(
  wls_linux_h3_route *route, char *error, size_t error_capacity) {
  if (!route || route->state != WLS_H3_LINUX_ROUTE_STAGED ||
      route->listener_fd < 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  int result = wls_linux_lock(route, error, error_capacity);
  if (result != WLS_TRANSPORT_OK) {
    return result;
  }

  wls_linux_h3_route_owner previous;
  memset(&previous, 0, sizeof(previous));
  if (wls_linux_bpf_map_lookup(
        route->owner_map_fd, &route->slot, &previous) != 0) {
    result = wls_linux_error(error, error_capacity,
                             "read HTTP/3 route owner: %s",
                             strerror(errno));
    goto done;
  }
  int previous_exists = previous.listener_cookie != 0;
  int same_owner = previous_exists &&
    previous.owner_epoch == route->owner_epoch &&
    previous.generation == route->generation &&
    previous.listener_cookie == route->listener_cookie;
  if (previous_exists && !same_owner &&
      (previous.owner_epoch > route->owner_epoch ||
       (previous.owner_epoch == route->owner_epoch &&
        previous.generation >= route->generation))) {
    result = wls_linux_error(
      error, error_capacity,
      "stale HTTP/3 route activation rejected for slot %u",
      route->slot);
    goto done;
  }

  if (wls_linux_attach_program(route->listener_fd, route->program_fd) != 0) {
    result = wls_linux_error(error, error_capacity,
                             "attach HTTP/3 reuseport eBPF at activation: %s",
                             strerror(errno));
    goto done;
  }

  uint32_t zero = 0;
  uint32_t previous_count = 0;
  if (wls_linux_bpf_map_lookup(
        route->count_map_fd, &zero, &previous_count) != 0) {
    result = wls_linux_error(error, error_capacity,
                             "read HTTP/3 route count: %s",
                             strerror(errno));
    goto done;
  }
  int count_expanded = previous_count < route->slot_count;
  if (count_expanded && wls_linux_bpf_map_update(
        route->count_map_fd, &zero,
        &route->slot_count, BPF_ANY) != 0) {
    result = wls_linux_error(error, error_capacity,
                             "publish HTTP/3 route count: %s",
                             strerror(errno));
    goto done;
  }

  wls_linux_h3_route_owner owner = {
    .owner_epoch = route->owner_epoch,
    .generation = route->generation,
    .listener_cookie = route->listener_cookie,
  };
  if (wls_linux_bpf_map_update(
        route->owner_map_fd, &route->slot, &owner, BPF_ANY) != 0) {
    if (count_expanded) {
      (void)wls_linux_bpf_map_update(
        route->count_map_fd, &zero, &previous_count, BPF_ANY);
    }
    result = wls_linux_error(error, error_capacity,
                             "publish HTTP/3 owner fence: %s",
                             strerror(errno));
    goto done;
  }
  uint64_t listener_value = (uint64_t)route->listener_fd;
  if (wls_linux_bpf_map_update(
        route->listen_map_fd, &route->slot,
        &listener_value, BPF_ANY) != 0) {
    int update_error = errno;
    (void)wls_linux_bpf_map_update(
      route->owner_map_fd, &route->slot, &previous, BPF_ANY);
    if (count_expanded) {
      (void)wls_linux_bpf_map_update(
        route->count_map_fd, &zero, &previous_count, BPF_ANY);
    }
    result = wls_linux_error(error, error_capacity,
                             "publish HTTP/3 listen slot: %s",
                             strerror(update_error));
    goto done;
  }

  route->state = WLS_H3_LINUX_ROUTE_ACTIVE;
  result = WLS_TRANSPORT_OK;

done:
  wls_linux_unlock(route);
  return result;
}

int wls_linux_h3_route_deactivate(
  wls_linux_h3_route *route, char *error, size_t error_capacity) {
  if (!route ||
      (route->state != WLS_H3_LINUX_ROUTE_ACTIVE &&
       route->state != WLS_H3_LINUX_ROUTE_DRAINING)) {
    return WLS_TRANSPORT_OK;
  }
  int result = wls_linux_lock(route, error, error_capacity);
  if (result != WLS_TRANSPORT_OK) {
    return result;
  }

  wls_linux_h3_route_owner owner;
  memset(&owner, 0, sizeof(owner));
  if (wls_linux_bpf_map_lookup(
        route->owner_map_fd, &route->slot, &owner) != 0) {
    result = wls_linux_error(error, error_capacity,
                             "read HTTP/3 route owner during drain: %s",
                             strerror(errno));
    goto done;
  }
  if (owner.owner_epoch == route->owner_epoch &&
      owner.generation == route->generation &&
      owner.listener_cookie == route->listener_cookie) {
    if (wls_linux_bpf_map_delete(
          route->listen_map_fd, &route->slot) != 0 &&
        errno != ENOENT) {
      result = wls_linux_error(error, error_capacity,
                               "retire HTTP/3 listen slot: %s",
                               strerror(errno));
      goto done;
    }
    memset(&owner, 0, sizeof(owner));
    if (wls_linux_bpf_map_update(
          route->owner_map_fd, &route->slot, &owner, BPF_ANY) != 0) {
      result = wls_linux_error(error, error_capacity,
                               "retire HTTP/3 owner fence: %s",
                               strerror(errno));
      goto done;
    }

    uint32_t count = 0;
    uint32_t zero = 0;
    for (uint32_t candidate = WLS_LINUX_H3_MAX_ROUTE_SLOTS;
         candidate > 0; --candidate) {
      uint32_t index = candidate - 1;
      wls_linux_h3_route_owner candidate_owner;
      memset(&candidate_owner, 0, sizeof(candidate_owner));
      if (wls_linux_bpf_map_lookup(
            route->owner_map_fd, &index, &candidate_owner) == 0 &&
          candidate_owner.listener_cookie != 0) {
        count = candidate;
        break;
      }
    }
    (void)wls_linux_bpf_map_update(
      route->count_map_fd, &zero, &count, BPF_ANY);
  }
  route->state = WLS_H3_LINUX_ROUTE_DRAINING;
  result = WLS_TRANSPORT_OK;

done:
  wls_linux_unlock(route);
  return result;
}

int wls_linux_h3_route_insert_cid(
  wls_linux_h3_route *route, const uint8_t *cid, size_t cid_length,
  char *error, size_t error_capacity) {
  if (!route || !cid ||
      cid_length != WLS_LINUX_H3_SERVER_CID_LENGTH ||
      (route->state != WLS_H3_LINUX_ROUTE_ACTIVE &&
       route->state != WLS_H3_LINUX_ROUTE_DRAINING) ||
      route->connection_fd < 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  uint64_t socket_value = (uint64_t)route->connection_fd;
  if (wls_linux_bpf_map_update(
        route->worker_map_fd, cid, &socket_value, BPF_NOEXIST) != 0) {
    if (errno == EEXIST) {
      return WLS_TRANSPORT_AGAIN;
    }
    return wls_linux_error(error, error_capacity,
                           "publish HTTP/3 connection CID: %s",
                           strerror(errno));
  }
  ++route->active_cids;
  return WLS_TRANSPORT_OK;
}

void wls_linux_h3_route_delete_cid(
  wls_linux_h3_route *route, const uint8_t *cid, size_t cid_length) {
  if (!route || !cid ||
      cid_length != WLS_LINUX_H3_SERVER_CID_LENGTH ||
      route->worker_map_fd < 0) {
    return;
  }
  if (wls_linux_bpf_map_delete(route->worker_map_fd, cid) == 0 &&
      route->active_cids != 0) {
    --route->active_cids;
  }
}

void wls_linux_h3_route_get_status(
  const wls_linux_h3_route *route, wls_h3_linux_route_status *status) {
  if (!route || !status) {
    return;
  }
  uint32_t struct_size = status->struct_size;
  memset(status, 0, sizeof(*status));
  status->struct_size = struct_size;
  status->state = route->state;
  status->slot = route->slot;
  status->slot_count = route->slot_count;
  status->owner_epoch = route->owner_epoch;
  status->generation = route->generation;
  status->listener_cookie = route->listener_cookie;
  status->connection_cookie = route->connection_cookie;
  status->active_cids = route->active_cids;
  status->program_id = route->program_id;
  status->listen_map_id = route->listen_map_id;
  status->worker_map_id = route->worker_map_id;
  status->count_map_id = route->count_map_id;
  status->owner_map_id = route->owner_map_id;
  size_t pin_length = strnlen(
    route->pin_namespace, sizeof(status->pin_namespace) - 1);
  memcpy(status->pin_namespace, route->pin_namespace, pin_length);
  status->pin_namespace[pin_length] = 0;
}

void wls_linux_h3_route_close(wls_linux_h3_route *route) {
  if (!route) {
    return;
  }
  char ignored[1];
  (void)wls_linux_h3_route_deactivate(route, ignored, sizeof(ignored));
  if (route->program_fd >= 0) {
    close(route->program_fd);
  }
  if (route->owner_map_fd >= 0) {
    close(route->owner_map_fd);
  }
  if (route->count_map_fd >= 0) {
    close(route->count_map_fd);
  }
  if (route->worker_map_fd >= 0) {
    close(route->worker_map_fd);
  }
  if (route->listen_map_fd >= 0) {
    close(route->listen_map_fd);
  }
  if (route->wait_fd >= 0) {
    close(route->wait_fd);
  }
  if (route->lock_fd >= 0) {
    close(route->lock_fd);
  }
  route->program_fd = -1;
  route->owner_map_fd = -1;
  route->count_map_fd = -1;
  route->worker_map_fd = -1;
  route->listen_map_fd = -1;
  route->wait_fd = -1;
  route->lock_fd = -1;
  route->state = WLS_H3_LINUX_ROUTE_DISABLED;
}

#endif
