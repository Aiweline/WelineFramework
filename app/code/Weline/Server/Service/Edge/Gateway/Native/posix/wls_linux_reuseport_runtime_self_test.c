#include "wls_linux_reuseport_runtime.h"
#include "wls_linux_reuseport_bpf_code.h"

#include <arpa/inet.h>
#include <elf.h>
#include <errno.h>
#include <fcntl.h>
#include <linux/bpf.h>
#include <linux/magic.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/epoll.h>
#include <sys/socket.h>
#include <sys/statfs.h>
#include <unistd.h>

#if !defined(__linux__)
#error "The WLS HTTP/3 reuseport runtime self-test is Linux-only."
#endif

#ifndef R_BPF_64_64
#define R_BPF_64_64 1
#endif

#ifndef WLS_BPF_EXPECTED_CLANG_MAJOR
#error "WLS_BPF_EXPECTED_CLANG_MAJOR must be supplied by the native build"
#endif

#ifndef WLS_BPF_EXPECTED_SOURCE_SHA256
#error "WLS_BPF_EXPECTED_SOURCE_SHA256 must be supplied by the native build"
#endif

#define WLS_SELF_TEST_SKIP 77
#define WLS_SELF_TEST_MAX_BPF_OBJECT_BYTES (16u * 1024u * 1024u)
#define WLS_CAP_NET_ADMIN 12u
#define WLS_CAP_SYS_ADMIN 21u
#define WLS_CAP_BPF 39u

typedef struct wls_expected_relocations {
    const char *symbol;
    const unsigned int *indexes;
    size_t count;
    size_t seen;
} wls_expected_relocations;

static int wls_expect(int condition, const char *message) {
    if (condition) {
        return 0;
    }
    (void)fprintf(stderr, "wls-http3-reuseport-self-test: %s\n", message);
    return 1;
}

static int wls_route_is_fail_closed(const wls_linux_h3_route *route) {
    return route->listener_fd == -1 && route->connection_fd == -1 &&
           route->wait_fd == -1 && route->listen_map_fd == -1 &&
           route->worker_map_fd == -1 && route->count_map_fd == -1 &&
           route->owner_map_fd == -1 && route->program_fd == -1 &&
           route->lock_fd == -1;
}

static int wls_fd_is_closed(int descriptor) {
    errno = 0;
    return fcntl(descriptor, F_GETFD) == -1 && errno == EBADF;
}

static int wls_is_lower_sha256(const char *digest) {
    if (digest == NULL || strlen(digest) != 64U) {
        return 0;
    }
    for (size_t index = 0; index < 64U; ++index) {
        if (!((digest[index] >= '0' && digest[index] <= '9') ||
              (digest[index] >= 'a' && digest[index] <= 'f'))) {
            return 0;
        }
    }
    return 1;
}

static int wls_test_bpf_provenance(void) {
    return wls_expect(
               WLS_LINUX_REUSEPORT_BPF_CLANG_MAJOR ==
                   WLS_BPF_EXPECTED_CLANG_MAJOR,
               "checked-in BPF header has an unexpected clang major") != 0 ||
                   wls_expect(
                       strcmp(WLS_LINUX_REUSEPORT_BPF_SOURCE_SHA256,
                              WLS_BPF_EXPECTED_SOURCE_SHA256) == 0,
                       "checked-in BPF source SHA-256 is stale") != 0 ||
                   wls_expect(
                       wls_is_lower_sha256(
                           WLS_LINUX_REUSEPORT_BPF_CODE_SHA256),
                       "checked-in BPF code SHA-256 is malformed") != 0
               ? 1
               : 0;
}

static int wls_range_is_valid(size_t total, uint64_t offset,
                              uint64_t length) {
    return offset <= (uint64_t)total && length <= (uint64_t)total - offset;
}

static unsigned char *wls_read_bounded_file(const char *path, size_t *size) {
    FILE *stream = fopen(path, "rb");
    if (!stream) {
        return NULL;
    }
    if (fseek(stream, 0, SEEK_END) != 0) {
        (void)fclose(stream);
        return NULL;
    }
    long length = ftell(stream);
    if (length <= 0 || (unsigned long)length >
                           (unsigned long)WLS_SELF_TEST_MAX_BPF_OBJECT_BYTES ||
        fseek(stream, 0, SEEK_SET) != 0) {
        (void)fclose(stream);
        return NULL;
    }
    unsigned char *contents = malloc((size_t)length);
    if (!contents) {
        (void)fclose(stream);
        return NULL;
    }
    if (fread(contents, 1, (size_t)length, stream) != (size_t)length ||
        ferror(stream)) {
        free(contents);
        (void)fclose(stream);
        return NULL;
    }
    if (fclose(stream) != 0) {
        free(contents);
        return NULL;
    }
    *size = (size_t)length;
    return contents;
}

static const char *wls_elf_section_name(const unsigned char *contents,
                                        size_t size,
                                        const Elf64_Shdr *string_section,
                                        const Elf64_Shdr *section) {
    if (!wls_range_is_valid(size, string_section->sh_offset,
                            string_section->sh_size) ||
        section->sh_name >= string_section->sh_size) {
        return NULL;
    }
    const char *name = (const char *)contents + string_section->sh_offset +
                       section->sh_name;
    size_t remaining = (size_t)(string_section->sh_size - section->sh_name);
    return memchr(name, 0, remaining) ? name : NULL;
}

static int wls_test_compiled_bpf_header_sync(const char *object_path) {
    size_t size = 0;
    unsigned char *contents = wls_read_bounded_file(object_path, &size);
    if (wls_expect(contents != NULL,
                   "unable to read the bounded compiled eBPF object") != 0) {
        return 1;
    }

    int result = 1;
    if (wls_expect(size >= sizeof(Elf64_Ehdr),
                   "compiled eBPF object is smaller than an ELF header") != 0) {
        goto done;
    }
    const Elf64_Ehdr *header = (const Elf64_Ehdr *)contents;
    if (wls_expect(memcmp(header->e_ident, ELFMAG, SELFMAG) == 0 &&
                       header->e_ident[EI_CLASS] == ELFCLASS64 &&
                       header->e_ident[EI_DATA] == ELFDATA2LSB &&
                       header->e_type == ET_REL && header->e_machine == EM_BPF,
                   "compiled BPF artifact is not a little-endian ELF64 BPF object") !=
            0 ||
        wls_expect(header->e_shentsize == sizeof(Elf64_Shdr) &&
                       header->e_shnum != 0 &&
                       header->e_shstrndx < header->e_shnum,
                   "compiled BPF ELF section table is invalid") != 0 ||
        wls_expect(wls_range_is_valid(
                       size, header->e_shoff,
                       (uint64_t)header->e_shnum * sizeof(Elf64_Shdr)),
                   "compiled BPF ELF section table exceeds the object") != 0) {
        goto done;
    }

    const Elf64_Shdr *sections =
        (const Elf64_Shdr *)(contents + header->e_shoff);
    const Elf64_Shdr *section_names = &sections[header->e_shstrndx];
    size_t code_index = SIZE_MAX;
    for (size_t index = 0; index < header->e_shnum; ++index) {
        const char *name = wls_elf_section_name(
            contents, size, section_names, &sections[index]);
        if (name && strcmp(name, "sk_reuseport/wls_h3_route") == 0) {
            code_index = index;
            break;
        }
    }
    if (wls_expect(code_index != SIZE_MAX,
                   "compiled BPF object has no reuseport program section") != 0) {
        goto done;
    }
    const Elf64_Shdr *code = &sections[code_index];
    if (wls_expect(wls_range_is_valid(size, code->sh_offset, code->sh_size),
                   "compiled BPF program section exceeds the object") != 0 ||
        wls_expect(code->sh_size == wls_linux_reuseport_bpf_code_len,
                   "checked-in BPF header byte length is stale") != 0 ||
        wls_expect(memcmp(contents + code->sh_offset,
                          wls_linux_reuseport_bpf_code,
                          wls_linux_reuseport_bpf_code_len) == 0,
                   "checked-in BPF instruction bytes are stale") != 0) {
        goto done;
    }

    wls_expected_relocations expected[] = {
        {
            .symbol = "wls_h3_worker_map",
            .indexes = wls_linux_reuseport_bpf_worker_map_relocations,
            .count = sizeof(wls_linux_reuseport_bpf_worker_map_relocations) /
                     sizeof(wls_linux_reuseport_bpf_worker_map_relocations[0]),
        },
        {
            .symbol = "wls_h3_count_map",
            .indexes = wls_linux_reuseport_bpf_count_map_relocations,
            .count = sizeof(wls_linux_reuseport_bpf_count_map_relocations) /
                     sizeof(wls_linux_reuseport_bpf_count_map_relocations[0]),
        },
        {
            .symbol = "wls_h3_listen_map",
            .indexes = wls_linux_reuseport_bpf_listen_map_relocations,
            .count = sizeof(wls_linux_reuseport_bpf_listen_map_relocations) /
                     sizeof(wls_linux_reuseport_bpf_listen_map_relocations[0]),
        },
    };
    int relocation_section_found = 0;
    for (size_t section_index = 0; section_index < header->e_shnum;
         ++section_index) {
        const Elf64_Shdr *relocation_section = &sections[section_index];
        if (relocation_section->sh_type != SHT_REL ||
            relocation_section->sh_info != code_index) {
            continue;
        }
        relocation_section_found = 1;
        if (wls_expect(relocation_section->sh_link < header->e_shnum &&
                           relocation_section->sh_entsize == sizeof(Elf64_Rel) &&
                           relocation_section->sh_size % sizeof(Elf64_Rel) == 0 &&
                           wls_range_is_valid(size, relocation_section->sh_offset,
                                              relocation_section->sh_size),
                       "compiled BPF relocation section is invalid") != 0) {
            goto done;
        }
        const Elf64_Shdr *symbols_section =
            &sections[relocation_section->sh_link];
        if (wls_expect(symbols_section->sh_type == SHT_SYMTAB &&
                           symbols_section->sh_link < header->e_shnum &&
                           symbols_section->sh_entsize == sizeof(Elf64_Sym) &&
                           symbols_section->sh_size % sizeof(Elf64_Sym) == 0 &&
                           wls_range_is_valid(size, symbols_section->sh_offset,
                                              symbols_section->sh_size),
                       "compiled BPF symbol table is invalid") != 0) {
            goto done;
        }
        const Elf64_Shdr *symbol_names = &sections[symbols_section->sh_link];
        if (wls_expect(wls_range_is_valid(size, symbol_names->sh_offset,
                                          symbol_names->sh_size),
                       "compiled BPF symbol string table is invalid") != 0) {
            goto done;
        }
        const Elf64_Rel *relocations =
            (const Elf64_Rel *)(contents + relocation_section->sh_offset);
        const Elf64_Sym *symbols =
            (const Elf64_Sym *)(contents + symbols_section->sh_offset);
        size_t relocation_count =
            (size_t)(relocation_section->sh_size / sizeof(Elf64_Rel));
        size_t symbol_count =
            (size_t)(symbols_section->sh_size / sizeof(Elf64_Sym));
        for (size_t index = 0; index < relocation_count; ++index) {
            size_t symbol_index = (size_t)ELF64_R_SYM(relocations[index].r_info);
            if (wls_expect(ELF64_R_TYPE(relocations[index].r_info) ==
                               R_BPF_64_64 &&
                               relocations[index].r_offset %
                                       sizeof(struct bpf_insn) ==
                                   0 &&
                               symbol_index < symbol_count,
                           "compiled BPF relocation record is invalid") != 0) {
                goto done;
            }
            const Elf64_Sym *symbol = &symbols[symbol_index];
            if (wls_expect(symbol->st_name < symbol_names->sh_size,
                           "compiled BPF relocation symbol name is invalid") != 0) {
                goto done;
            }
            const char *symbol_name =
                (const char *)contents + symbol_names->sh_offset +
                symbol->st_name;
            size_t name_remaining =
                (size_t)(symbol_names->sh_size - symbol->st_name);
            if (wls_expect(memchr(symbol_name, 0, name_remaining) != NULL,
                           "compiled BPF relocation symbol is unterminated") != 0) {
                goto done;
            }
            size_t instruction_index =
                (size_t)(relocations[index].r_offset /
                         sizeof(struct bpf_insn));
            int matched = 0;
            for (size_t expected_index = 0;
                 expected_index < sizeof(expected) / sizeof(expected[0]);
                 ++expected_index) {
                if (strcmp(symbol_name, expected[expected_index].symbol) != 0) {
                    continue;
                }
                matched = 1;
                if (wls_expect(
                        expected[expected_index].seen <
                                expected[expected_index].count &&
                            instruction_index ==
                                expected[expected_index]
                                    .indexes[expected[expected_index].seen],
                        "checked-in BPF relocation metadata is stale") != 0) {
                    goto done;
                }
                ++expected[expected_index].seen;
                break;
            }
            if (wls_expect(matched,
                           "compiled BPF code contains an untracked relocation") !=
                0) {
                goto done;
            }
        }
    }
    if (wls_expect(relocation_section_found,
                   "compiled BPF object has no reuseport relocation section") !=
        0) {
        goto done;
    }
    for (size_t index = 0; index < sizeof(expected) / sizeof(expected[0]);
         ++index) {
        if (wls_expect(expected[index].seen == expected[index].count,
                       "checked-in BPF relocation count is stale") != 0) {
            goto done;
        }
    }

    result = 0;

done:
    free(contents);
    return result;
}

static int wls_test_transport_dependency_linkage(void) {
    wls_transport_versions versions;
    (void)memset(&versions, 0, sizeof(versions));
    versions.struct_size = sizeof(versions);
    if (wls_expect(wls_transport_abi_version() == WLS_TRANSPORT_ABI_VERSION,
                   "linked transport ABI does not match the production header") !=
            0 ||
        wls_expect(wls_transport_get_versions(&versions) == WLS_TRANSPORT_OK,
                   "linked ngtcp2/nghttp3/OpenSSL runtime versions are incompatible") !=
            0 ||
        wls_expect(versions.abi_version == WLS_TRANSPORT_ABI_VERSION &&
                       versions.ngtcp2_compile == versions.ngtcp2_runtime &&
                       versions.nghttp3_compile == versions.nghttp3_runtime &&
                       versions.openssl_compile == versions.openssl_runtime,
                   "compiled and runtime HTTP/3 dependencies do not match") != 0 ||
        wls_expect(wls_transport_build_id() != NULL &&
                       wls_transport_build_id()[0] != 0,
                   "linked transport did not expose a build identity") != 0) {
        return 1;
    }
    return 0;
}

static int wls_configure_reuseport_socket(int descriptor) {
    int enabled = 1;
    return setsockopt(descriptor, SOL_SOCKET, SO_REUSEADDR, &enabled,
                      sizeof(enabled)) == 0 &&
                   setsockopt(descriptor, SOL_SOCKET, SO_REUSEPORT, &enabled,
                              sizeof(enabled)) == 0
               ? 0
               : -1;
}

static int wls_test_nonprivileged_reuseport_io(void) {
    int listener = -1;
    int connection = -1;
    int sender = -1;
    int wait_fd = -1;
    int result = 1;
    struct sockaddr_in address;
    socklen_t address_length = sizeof(address);
    const char payload[] = "wls-reuseport-runtime";
    char received[sizeof(payload)];

    listener = socket(AF_INET, SOCK_DGRAM | SOCK_CLOEXEC, IPPROTO_UDP);
    connection = socket(AF_INET, SOCK_DGRAM | SOCK_CLOEXEC, IPPROTO_UDP);
    sender = socket(AF_INET, SOCK_DGRAM | SOCK_CLOEXEC, IPPROTO_UDP);
    if (wls_expect(listener >= 0 && connection >= 0 && sender >= 0,
                   "unable to create non-privileged UDP sockets") != 0 ||
        wls_expect(wls_configure_reuseport_socket(listener) == 0 &&
                       wls_configure_reuseport_socket(connection) == 0,
                   "SO_REUSEPORT is unavailable for non-privileged UDP") != 0) {
        goto done;
    }
    (void)memset(&address, 0, sizeof(address));
    address.sin_family = AF_INET;
    address.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    address.sin_port = 0;
    if (wls_expect(bind(listener, (const struct sockaddr *)&address,
                        sizeof(address)) == 0 &&
                       getsockname(listener, (struct sockaddr *)&address,
                                   &address_length) == 0 &&
                       address.sin_port != 0 &&
                       bind(connection, (const struct sockaddr *)&address,
                            address_length) == 0,
                   "non-privileged dual reuseport bind failed") != 0) {
        goto done;
    }
    wait_fd = epoll_create1(EPOLL_CLOEXEC);
    struct epoll_event event;
    (void)memset(&event, 0, sizeof(event));
    event.events = EPOLLIN;
    event.data.fd = listener;
    if (wls_expect(wait_fd >= 0 &&
                       epoll_ctl(wait_fd, EPOLL_CTL_ADD, listener, &event) == 0,
                   "unable to register the first reuseport socket") != 0) {
        goto done;
    }
    event.data.fd = connection;
    if (wls_expect(epoll_ctl(wait_fd, EPOLL_CTL_ADD, connection, &event) == 0,
                   "unable to register the second reuseport socket") != 0 ||
        wls_expect(sendto(sender, payload, sizeof(payload), 0,
                          (const struct sockaddr *)&address,
                          address_length) == (ssize_t)sizeof(payload),
                   "unable to send through the reuseport socket pair") != 0) {
        goto done;
    }
    struct epoll_event ready;
    (void)memset(&ready, 0, sizeof(ready));
    if (wls_expect(epoll_wait(wait_fd, &ready, 1, 1000) == 1 &&
                       (ready.data.fd == listener ||
                        ready.data.fd == connection),
                   "reuseport pair did not publish a readable socket") != 0 ||
        wls_expect(recv(ready.data.fd, received, sizeof(received), 0) ==
                           (ssize_t)sizeof(payload) &&
                       memcmp(received, payload, sizeof(payload)) == 0,
                   "reuseport pair did not preserve the UDP payload") != 0) {
        goto done;
    }

    wls_linux_h3_route route;
    wls_linux_h3_route_init(&route);
    route.listener_fd = listener;
    route.connection_fd = connection;
    route.wait_fd = wait_fd;
    route.state = WLS_H3_LINUX_ROUTE_STAGED;
    int listener_snapshot = listener;
    int connection_snapshot = connection;
    int wait_snapshot = wait_fd;
    listener = -1;
    connection = -1;
    wait_fd = -1;
    wls_linux_h3_route_close(&route);
    if (wls_expect(wls_route_is_fail_closed(&route),
                   "runtime close did not reset the live reuseport route") != 0 ||
        wls_expect(wls_fd_is_closed(listener_snapshot) &&
                       wls_fd_is_closed(connection_snapshot) &&
                       wls_fd_is_closed(wait_snapshot),
                   "runtime close leaked a live reuseport descriptor") != 0) {
        goto done;
    }
    result = 0;

done:
    if (wait_fd >= 0) {
        (void)close(wait_fd);
    }
    if (sender >= 0) {
        (void)close(sender);
    }
    if (connection >= 0) {
        (void)close(connection);
    }
    if (listener >= 0) {
        (void)close(listener);
    }
    return result;
}

static int wls_test_init_status_close(void) {
    wls_linux_h3_route route;
    wls_h3_linux_route_status status;

    (void)memset(&route, 0xa5, sizeof(route));
    wls_linux_h3_route_init(&route);
    if (wls_expect(wls_route_is_fail_closed(&route),
                   "route descriptors were not initialized fail-closed") != 0 ||
        wls_expect(route.state == WLS_H3_LINUX_ROUTE_DISABLED,
                   "route did not initialize disabled") != 0) {
        return 1;
    }

    (void)memset(&status, 0xa5, sizeof(status));
    status.struct_size = sizeof(status);
    wls_linux_h3_route_get_status(&route, &status);
    if (wls_expect(status.struct_size == sizeof(status),
                   "status struct size does not match the ABI") != 0 ||
        wls_expect(status.state == WLS_H3_LINUX_ROUTE_DISABLED,
                   "status did not report a disabled route") != 0) {
        return 1;
    }

    wls_linux_h3_route_close(&route);
    if (wls_expect(wls_route_is_fail_closed(&route),
                   "route close did not retain fail-closed descriptors") != 0) {
        return 1;
    }

    return 0;
}

static int wls_test_argument_contracts(void) {
    wls_linux_h3_route route;
    wls_h3_linux_route_config config;
    struct sockaddr_storage bound_address;
    socklen_t bound_length = sizeof(bound_address);
    uint16_t bound_port = 0;
    char error[256] = {0};
    uint8_t cid[WLS_LINUX_H3_SERVER_CID_LENGTH];

    wls_linux_h3_route_init(&route);
    (void)memset(&config, 0, sizeof(config));
    config.struct_size = sizeof(config);
    config.slot = 0;
    config.slot_count = 1;
    config.flags = WLS_H3_LINUX_ROUTE_FLAG_NONE;
    config.owner_epoch = 1;
    config.generation = 1;
    config.namespace_key = "wls-http3-self-test";
    (void)memset(cid, 0x11, sizeof(cid));

    if (wls_expect(
            wls_linux_h3_route_bind(
                NULL, "127.0.0.1", 0, &config, &bound_address, &bound_length,
                &bound_port, error, sizeof(error)) ==
                WLS_TRANSPORT_INVALID_ARGUMENT,
            "bind must reject a null route") != 0) {
        return 1;
    }

    config.struct_size = 0;
    if (wls_expect(
            wls_linux_h3_route_bind(
                &route, "127.0.0.1", 0, &config, &bound_address, &bound_length,
                &bound_port, error, sizeof(error)) ==
                WLS_TRANSPORT_INVALID_ARGUMENT,
            "bind must reject an ABI-mismatched config") != 0) {
        return 1;
    }
    config.struct_size = sizeof(config);
    config.slot_count = 0;
    if (wls_expect(
            wls_linux_h3_route_bind(
                &route, "127.0.0.1", 0, &config, &bound_address, &bound_length,
                &bound_port, error, sizeof(error)) ==
                WLS_TRANSPORT_INVALID_ARGUMENT,
            "bind must reject an empty slot count") != 0) {
        return 1;
    }
    config.slot_count = 1;
    config.owner_epoch = 0;
    if (wls_expect(
            wls_linux_h3_route_bind(
                &route, "127.0.0.1", 0, &config, &bound_address, &bound_length,
                &bound_port, error, sizeof(error)) ==
                WLS_TRANSPORT_INVALID_ARGUMENT,
            "bind must reject a zero owner epoch") != 0) {
        return 1;
    }
    config.owner_epoch = 1;

    if (wls_expect(
            wls_linux_h3_route_activate(&route, error, sizeof(error)) ==
                WLS_TRANSPORT_INVALID_ARGUMENT,
            "activate must reject a disabled route") != 0 ||
        wls_expect(
            wls_linux_h3_route_insert_cid(
                &route, cid, sizeof(cid), error, sizeof(error)) ==
                WLS_TRANSPORT_INVALID_ARGUMENT,
            "insert_cid must reject a disabled route") != 0 ||
        wls_expect(
            wls_linux_h3_route_deactivate(&route, error, sizeof(error)) ==
                WLS_TRANSPORT_OK,
            "deactivate must be idempotent on a disabled route") != 0 ||
        wls_expect(wls_route_is_fail_closed(&route),
                   "argument contracts must not open descriptors") != 0 ||
        wls_expect(route.state == WLS_H3_LINUX_ROUTE_DISABLED,
                   "argument contracts must leave the route disabled") != 0) {
        return 1;
    }

    return 0;
}

static int wls_bpffs_is_mounted(void) {
    struct statfs filesystem;
    return statfs("/sys/fs/bpf", &filesystem) == 0 &&
           (unsigned long)filesystem.f_type == (unsigned long)BPF_FS_MAGIC;
}

/* Returns 1 when required caps are absent, 0 when present, and -1 unknown. */
static int wls_missing_bpf_capabilities(void) {
    FILE *status = fopen("/proc/self/status", "r");
    if (!status) {
        return -1;
    }
    char line[256];
    unsigned long long effective = 0;
    int found = 0;
    while (fgets(line, sizeof(line), status)) {
        if (sscanf(line, "CapEff:%llx", &effective) == 1) {
            found = 1;
            break;
        }
    }
    if (fclose(status) != 0 || !found) {
        return -1;
    }
    unsigned long long cap_bpf = UINT64_C(1) << WLS_CAP_BPF;
    unsigned long long cap_net_admin = UINT64_C(1) << WLS_CAP_NET_ADMIN;
    unsigned long long cap_sys_admin = UINT64_C(1) << WLS_CAP_SYS_ADMIN;
    int capable = (effective & cap_sys_admin) != 0 ||
                  ((effective & cap_bpf) != 0 &&
                   (effective & cap_net_admin) != 0);
    return capable ? 0 : 1;
}

static int wls_is_expected_environment_failure(const char *error,
                                               int bpffs_mounted,
                                               int missing_capabilities) {
    if (!bpffs_mounted) {
        return strstr(error, "is not a mounted bpffs") != NULL;
    }
    if (missing_capabilities == 1) {
        return strstr(error, "Operation not permitted") != NULL ||
               strstr(error, "Permission denied") != NULL;
    }
    return 0;
}

static int wls_test_bind_lifecycle(void) {
    wls_linux_h3_route route;
    wls_h3_linux_route_config config;
    wls_h3_linux_route_status status;
    struct sockaddr_storage bound_address;
    socklen_t bound_length = sizeof(bound_address);
    uint16_t bound_port = 0;
    char error[256] = {0};
    char namespace_key[96];
    uint8_t cid[WLS_LINUX_H3_SERVER_CID_LENGTH];
    int bind_result;
    int bpffs_mounted = wls_bpffs_is_mounted();
    int missing_capabilities = wls_missing_bpf_capabilities();

    if (wls_expect(snprintf(namespace_key, sizeof(namespace_key),
                            "wls-http3-self-test-%lu",
                            (unsigned long)getpid()) > 0,
                   "unable to create an isolated BPF namespace") != 0) {
        return 1;
    }
    wls_linux_h3_route_init(&route);
    (void)memset(&config, 0, sizeof(config));
    config.struct_size = sizeof(config);
    config.slot = 0;
    config.slot_count = 1;
    config.flags = WLS_H3_LINUX_ROUTE_FLAG_NONE;
    config.owner_epoch = 7;
    config.generation = 3;
    config.namespace_key = namespace_key;
    (void)memset(cid, 0x42, sizeof(cid));

    bind_result = wls_linux_h3_route_bind(
        &route, "127.0.0.1", 0, &config, &bound_address, &bound_length,
        &bound_port, error, sizeof(error));
    if (bind_result == WLS_TRANSPORT_OK) {
        int listener_snapshot = route.listener_fd;
        int connection_snapshot = route.connection_fd;
        int wait_snapshot = route.wait_fd;
        if (wls_expect(route.state == WLS_H3_LINUX_ROUTE_STAGED,
                       "successful bind must stage the route") != 0 ||
            wls_expect(route.listener_fd >= 0 && route.connection_fd >= 0 &&
                           route.wait_fd >= 0,
                       "successful bind must own both sockets and epoll") != 0 ||
            wls_expect(bound_port != 0,
                       "successful bind must publish an ephemeral port") != 0 ||
            wls_expect(
                wls_linux_h3_route_activate(&route, error, sizeof(error)) ==
                    WLS_TRANSPORT_OK,
                "staged route activation must succeed") != 0 ||
            wls_expect(route.state == WLS_H3_LINUX_ROUTE_ACTIVE,
                       "activation must mark the route active") != 0 ||
            wls_expect(
                wls_linux_h3_route_insert_cid(
                    &route, cid, sizeof(cid), error, sizeof(error)) ==
                    WLS_TRANSPORT_OK,
                "active route must accept a CID publication") != 0 ||
            wls_expect(route.active_cids == 1,
                       "CID publication must increment active_cids") != 0) {
            wls_linux_h3_route_close(&route);
            return 1;
        }

        wls_linux_h3_route_delete_cid(&route, cid, sizeof(cid));
        if (wls_expect(route.active_cids == 0,
                       "CID deletion must decrement active_cids") != 0 ||
            wls_expect(
                wls_linux_h3_route_deactivate(&route, error, sizeof(error)) ==
                    WLS_TRANSPORT_OK,
                "active route deactivation must succeed") != 0 ||
            wls_expect(route.state == WLS_H3_LINUX_ROUTE_DRAINING,
                       "deactivation must enter draining") != 0) {
            wls_linux_h3_route_close(&route);
            return 1;
        }

        (void)memset(&status, 0, sizeof(status));
        status.struct_size = sizeof(status);
        wls_linux_h3_route_get_status(&route, &status);
        if (wls_expect(status.state == WLS_H3_LINUX_ROUTE_DRAINING,
                       "status must report the draining route") != 0 ||
            wls_expect(status.owner_epoch == 7 && status.generation == 3,
                       "status must retain the owner fence") != 0 ||
            wls_expect(status.pin_namespace[0] != 0,
                       "status must expose the pin namespace") != 0) {
            wls_linux_h3_route_close(&route);
            return 1;
        }

        wls_linux_h3_route_close(&route);
        if (wls_expect(wls_route_is_fail_closed(&route),
                       "privileged lifecycle close must reset all descriptors") !=
                0 ||
            wls_expect(wls_fd_is_closed(listener_snapshot) &&
                           wls_fd_is_closed(connection_snapshot) &&
                           wls_fd_is_closed(wait_snapshot),
                       "privileged lifecycle close leaked a route descriptor") !=
                0) {
            return 1;
        }
        return 0;
    }

    if (wls_expect(bind_result != WLS_TRANSPORT_INVALID_ARGUMENT,
                   "valid bind arguments must not fail as INVALID_ARGUMENT") !=
            0 ||
        wls_expect(route.state == WLS_H3_LINUX_ROUTE_DISABLED,
                   "failed bind must clean up to DISABLED state") != 0 ||
        wls_expect(wls_route_is_fail_closed(&route),
                   "failed bind must clean up every descriptor") != 0) {
        wls_linux_h3_route_close(&route);
        return 1;
    }
    if (!wls_is_expected_environment_failure(
            error, bpffs_mounted, missing_capabilities)) {
        (void)fprintf(
            stderr,
            "wls-http3-reuseport-self-test: unexpected BPF runtime failure: %s\n",
            error[0] != 0 ? error : "unknown");
        return 1;
    }
    (void)fprintf(
        stderr,
        "wls-http3-reuseport-self-test: SKIP permission/environment: "
        "bpffs=%s capabilities=%s error=%s\n",
        bpffs_mounted ? "mounted" : "missing",
        missing_capabilities == 1
            ? "missing"
            : (missing_capabilities == 0 ? "present" : "unknown"),
        error[0] != 0 ? error : "unknown");
    return WLS_SELF_TEST_SKIP;
}

int main(int argc, char **argv) {
    if (wls_expect(argc == 2 && argv[1] != NULL && argv[1][0] != 0,
                   "compiled BPF object path is required") != 0 ||
        wls_expect(WLS_TRANSPORT_ABI_VERSION == UINT32_C(0x00020009),
                   "unexpected transport ABI") != 0 ||
        wls_test_bpf_provenance() != 0 ||
        wls_test_compiled_bpf_header_sync(argv[1]) != 0 ||
        wls_test_transport_dependency_linkage() != 0 ||
        wls_test_nonprivileged_reuseport_io() != 0 ||
        wls_test_init_status_close() != 0 ||
        wls_test_argument_contracts() != 0) {
        return 1;
    }

    int lifecycle = wls_test_bind_lifecycle();
    if (lifecycle == WLS_SELF_TEST_SKIP) {
        return WLS_SELF_TEST_SKIP;
    }
    if (lifecycle != 0) {
        return 1;
    }

    (void)puts("wls-http3-reuseport-self-test: ok");
    return 0;
}
