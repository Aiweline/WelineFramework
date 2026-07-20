/*
 * Linux QUIC reuseport selector used by WLS Direct HTTP/3 Workers.
 *
 * The design follows the Nginx worker-socket split: unknown long-header
 * packets select a canonical listen socket, while an issued server CID
 * selects the connection socket that owns the QUIC state. WLS deliberately
 * drops map misses instead of falling back to the kernel reuseport hash.
 *
 * This source is compiled only when regenerating
 * wls_linux_reuseport_bpf_code.h. The production transport loads the
 * generated instructions directly and has no libbpf/libelf dependency.
 */

#include <errno.h>
#include <linux/bpf.h>
#include <linux/types.h>
#include <linux/udp.h>
#include <stddef.h>

#ifndef SEC
#  define SEC(name) __attribute__((section(name), used))
#endif

#ifndef SK_DROP
#  define SK_DROP 0
#endif

#ifndef SK_PASS
#  define SK_PASS 1
#endif

#define WLS_H3_LINUX_SERVER_CID_LENGTH 20u
#define WLS_H3_LINUX_MAX_ROUTE_SLOTS 64u

struct wls_bpf_map_def {
  __u32 type;
  __u32 key_size;
  __u32 value_size;
  __u32 max_entries;
  __u32 map_flags;
};

struct wls_bpf_map_def SEC("maps") wls_h3_listen_map = {
  .type = BPF_MAP_TYPE_SOCKMAP,
  .key_size = sizeof(__u32),
  .value_size = sizeof(__u64),
  .max_entries = WLS_H3_LINUX_MAX_ROUTE_SLOTS,
};

struct wls_bpf_map_def SEC("maps") wls_h3_worker_map = {
  .type = BPF_MAP_TYPE_SOCKHASH,
  .key_size = WLS_H3_LINUX_SERVER_CID_LENGTH,
  .value_size = sizeof(__u64),
  .max_entries = 262144u,
};

struct wls_bpf_map_def SEC("maps") wls_h3_count_map = {
  .type = BPF_MAP_TYPE_ARRAY,
  .key_size = sizeof(__u32),
  .value_size = sizeof(__u32),
  .max_entries = 1u,
};

static void *(*wls_bpf_map_lookup_elem)(void *map, const void *key) =
  (void *)(long)BPF_FUNC_map_lookup_elem;

static long (*wls_bpf_sk_select_reuseport)(
  struct sk_reuseport_md *context, void *map, void *key, __u64 flags) =
  (void *)(long)BPF_FUNC_sk_select_reuseport;

SEC("sk_reuseport/wls_h3_route")
int wls_h3_select_reuseport(struct sk_reuseport_md *context) {
  unsigned char *start = context->data;
  unsigned char *end = context->data_end;
  unsigned char dcid[WLS_H3_LINUX_SERVER_CID_LENGTH] = {0};
  size_t offset = sizeof(struct udphdr) + 1u;
  __u32 zero = 0;
  __u32 *count;
  __u32 first;
  int is_short_header;
  int result;

  if (start + offset > end) {
    return SK_DROP;
  }

  is_short_header = (start[offset - 1u] & 0x80u) == 0;
  if (!is_short_header) {
    offset += 5u; /* version + DCID length */
    if (start + offset > end) {
      return SK_DROP;
    }
    if (start[offset - 1u] != WLS_H3_LINUX_SERVER_CID_LENGTH) {
      goto new_connection;
    }
  }

  if (start + offset + WLS_H3_LINUX_SERVER_CID_LENGTH > end) {
    return SK_DROP;
  }

  __builtin_memcpy(dcid, start + offset,
                   WLS_H3_LINUX_SERVER_CID_LENGTH);
  result = (int)wls_bpf_sk_select_reuseport(
    context, &wls_h3_worker_map, dcid, 0);
  if (result == 0) {
    return SK_PASS;
  }
  if (result != -ENOENT || is_short_header) {
    return SK_DROP;
  }

new_connection:
  count = wls_bpf_map_lookup_elem(&wls_h3_count_map, &zero);
  if (count == NULL || *count == 0 ||
      *count > WLS_H3_LINUX_MAX_ROUTE_SLOTS) {
    return SK_DROP;
  }

  first = context->hash % *count;
#pragma clang loop unroll(full)
  for (__u32 index = 0; index < WLS_H3_LINUX_MAX_ROUTE_SLOTS; ++index) {
    __u32 slot;
    if (index >= *count) {
      break;
    }
    slot = first + index;
    if (slot >= *count) {
      slot -= *count;
    }
    result = (int)wls_bpf_sk_select_reuseport(
      context, &wls_h3_listen_map, &slot, 0);
    if (result == 0) {
      return SK_PASS;
    }
    if (result != -ENOENT) {
      return SK_DROP;
    }
  }

  return SK_DROP;
}

char wls_h3_bpf_license[] SEC("license") = "BSD";
