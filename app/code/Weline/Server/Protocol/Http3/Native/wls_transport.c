#if defined(__APPLE__)
#  define _DARWIN_C_SOURCE
#elif defined(__linux__)
#  define _GNU_SOURCE
#endif
#define _POSIX_C_SOURCE 200809L
#include "wls_transport_abi.h"
#if defined(__linux__)
#  include "wls_linux_reuseport_runtime.h"
#endif


#include <nghttp3/nghttp3.h>
#include <nghttp3/version.h>
#include <ngtcp2/ngtcp2.h>
#include <ngtcp2/ngtcp2_crypto.h>
#include <ngtcp2/ngtcp2_crypto_ossl.h>
#include <ngtcp2/version.h>

#include <openssl/crypto.h>
#include <openssl/core_names.h>
#include <openssl/err.h>
#include <openssl/hmac.h>
#include <openssl/opensslv.h>
#include <openssl/params.h>
#include <openssl/rand.h>
#include <openssl/ssl.h>

#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <ifaddrs.h>
#include <netdb.h>
#include <net/if.h>
#include <netinet/in.h>
#include <poll.h>
#include <pthread.h>
#include <stdarg.h>
#include <stdatomic.h>
#include <stdio.h>
#include <stdlib.h>
#include <time.h>
#include <string.h>
#include <strings.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <sys/un.h>
#include <unistd.h>

#if defined(__APPLE__)
#  include <sys/event.h>
#endif

#define WLS_STRINGIFY_INNER(v) #v
#define WLS_STRINGIFY(v) WLS_STRINGIFY_INNER(v)
#define WLS_H3_DEFAULT_ALPN "\x02h3"
#define WLS_H3_DEFAULT_ALPN_LEN 3u
#if defined(__linux__)
#  define WLS_H3_SERVER_SCID_LENGTH WLS_LINUX_H3_SERVER_CID_LENGTH
#else
#  define WLS_H3_SERVER_SCID_LENGTH 18u
#endif
#define WLS_H3_CID_TABLE_CAPACITY 4096u
#define WLS_H3_TOKEN_TABLE_CAPACITY 4096u
#define WLS_H3_MAX_PACKET_SIZE 65536u
#define WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES 1452u
#define WLS_H3_DEFAULT_HEADER_LIMIT 65536u
#define WLS_H3_DEFAULT_BODY_LIMIT (4u * 1024u * 1024u)
#define WLS_H3_MAX_WRITE_BATCH 64u
#define WLS_H3_MAX_READ_BATCH 256u
#define WLS_H3_RETRY_SECRET_LENGTH 32u
#define WLS_H3_DEFAULT_MAX_CONNECTIONS 512u
#define WLS_H3_DEFAULT_MAX_ACTIVE_STREAMS 4096u
#define WLS_H3_DEFAULT_MAX_STREAMS_BIDI 64u
/*
 * Bound connection-lifetime protocol state like nginx keepalive_requests.
 * curl's legal post-FIN MAX_STREAM_DATA updates are counted by ngtcp2's
 * connection-wide glitch limiter, so a hot connection must eventually stop
 * accepting new work. A budget rollover sends only the shutdown-notice
 * GOAWAY: existing and client-reserved streams remain valid, while new work
 * migrates to another multiplexed TLS 1.3 session. The final GOAWAY is
 * reserved for an explicit whole-runtime drain because using it for routine
 * rollover can strand client-reserved stream IDs which have not reached the
 * wire. CID jitter prevents a synchronized reconnect wave.
 */
#define WLS_H3_DEFAULT_MAX_REQUESTS_PER_CONNECTION UINT64_C(16384)
#define WLS_H3_REQUEST_ROTATION_JITTER UINT64_C(2048)
#define WLS_H3_HANDSHAKE_TIMEOUT (10u * NGTCP2_SECONDS)
#define WLS_H3_DEFAULT_RETRY_TOKEN_LIFETIME_MS 10000u
#define WLS_TLS_TICKET_SECRET_LENGTH 32u
#define WLS_TLS_TICKET_NAME_LENGTH 16u
#define WLS_TLS_TICKET_KEY_LENGTH 32u
#define WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH 32u
#define WLS_TLS_TICKET_DIGEST_HEX_LENGTH 64u
#define WLS_TLS_TICKET_MIN_LIFETIME_SECONDS 300u
#define WLS_TLS_TICKET_MAX_LIFETIME_SECONDS 604800u
#define WLS_H3_DRAIN_NOTICE_DEFAULT_DELAY (200u * NGTCP2_MILLISECONDS)
#define WLS_H3_DRAIN_NOTICE_MIN_DELAY (100u * NGTCP2_MILLISECONDS)
#define WLS_H3_DRAIN_NOTICE_MAX_DELAY (2000u * NGTCP2_MILLISECONDS)
#define WLS_H3_TERMINAL_CLOSE_RETRY_INTERVAL (10u * NGTCP2_MILLISECONDS)
#define WLS_H3_TERMINAL_CLOSE_RESEND_INTERVAL (100u * NGTCP2_MILLISECONDS)
#define WLS_H3_CHANNEL_MAGIC UINT32_C(0x574c4833)
#define WLS_H3_CHANNEL_VERSION 4u
#define WLS_H3_CHANNEL_KEY_LENGTH 32u
#define WLS_H3_MAX_ROUTER_ENDPOINTS 64u
#define WLS_H3_DEFAULT_MAX_INITIAL_DATAGRAM_BYTES 65536u
#define WLS_H3_KQUEUE_EVENT_BATCH 64u
#define WLS_H3_ROUTER_KQUEUE_EVENT_BATCH 128u
#define WLS_H3_ROUTER_CHANNEL_BUFFER_BYTES (4 * 1024 * 1024)
#define WLS_H3_ROUTER_INGRESS_PAYLOAD_CAPACITY 4096u
#define WLS_H3_ROUTER_INGRESS_TTL NGTCP2_SECONDS
#define WLS_H3_ROUTER_EGRESS_QUEUE_CAPACITY 2048u
#define WLS_H3_ROUTER_EGRESS_FLUSH_BATCH 256u
#define WLS_H3_ROUTER_EGRESS_MAX_AGE (1u * NGTCP2_SECONDS)
#define WLS_H3_ROUTER_LISTENER_BUFFER_BYTES (4 * 1024 * 1024)
#define WLS_H3_ROUTER_ENDPOINT_DRAIN_LIMIT 8192u
#define WLS_H3_CHANNEL_INGRESS 1u
#define WLS_H3_CHANNEL_EGRESS 2u
#define WLS_H3_CHANNEL_PATH_RETIRE 3u
#define WLS_H3_CHANNEL_PATH_CLOSE 4u
#define WLS_H3_ROUTE_CID_MARKER 0xa3u
#define WLS_H3_ROUTE_CID_IDENTITY_BYTES 13u
#define WLS_H3_ROUTER_PATH_CAPACITY 8192u
#define WLS_H3_ROUTER_PATH_EMPTY 0u
#define WLS_H3_ROUTER_PATH_PROVISIONAL 1u
#define WLS_H3_ROUTER_PATH_ESTABLISHED 2u
#define WLS_H3_ROUTER_PATH_CLOSING 3u
#define WLS_H3_ROUTER_PROVISIONAL_TTL \
  (WLS_H3_HANDSHAKE_TIMEOUT + 2u * NGTCP2_SECONDS)
#define WLS_H3_ROUTER_ESTABLISHED_TTL (60u * NGTCP2_SECONDS)
#define WLS_H3_ROUTER_CLOSING_TTL (3u * NGTCP2_SECONDS)
#define WLS_H3_ROUTER_CLOSE_RESEND_INTERVAL (100u * NGTCP2_MILLISECONDS)
#define WLS_H3_ROUTER_PATH_SWEEP_INTERVAL (250u * NGTCP2_MILLISECONDS)
#define WLS_H3_ROUTER_MAX_LIVE_PATHS 6144u
#define WLS_H3_ROUTER_MAX_PROVISIONAL_PATHS 2048u
#define WLS_H3_ROUTER_SOURCE_BUCKETS 256u
#define WLS_H3_ROUTER_MAX_PROVISIONAL_PER_SOURCE_BUCKET 256u
#define WLS_H3_MAX_TERMINAL_CIDS 8u

static _Thread_local char wls_last_error[512];
static pthread_once_t wls_crypto_once = PTHREAD_ONCE_INIT;
static int wls_crypto_init_result = -1;
static pthread_once_t wls_tls_context_ex_once = PTHREAD_ONCE_INIT;
static int wls_tls_context_ex_index = -1;

typedef struct wls_tls_ticket_material {
  uint8_t name[WLS_TLS_TICKET_NAME_LENGTH];
  uint8_t cipher_key[WLS_TLS_TICKET_KEY_LENGTH];
  uint8_t mac_key[WLS_TLS_TICKET_KEY_LENGTH];
} wls_tls_ticket_material;

struct wls_tls_context {
  _Atomic uint32_t ref_count;
  SSL_CTX *ssl_ctx;
  uint8_t *alpn_wire;
  size_t alpn_wire_length;
  pthread_rwlock_t ticket_lock;
  uint8_t ticket_lock_initialized;
  _Atomic uint8_t ticket_ring_active;
  uint8_t session_context[WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH];
  wls_tls_ticket_material current_ticket;
  wls_tls_ticket_material previous_ticket;
  uint64_t ticket_epoch;
  uint32_t ticket_lifetime_seconds;
  char ticket_digest[65];
  _Atomic uint64_t handshakes_completed;
  _Atomic uint64_t full_handshakes;
  _Atomic uint64_t resumed_handshakes;
  _Atomic uint64_t tickets_encrypted;
  _Atomic uint64_t tickets_decrypted_current;
  _Atomic uint64_t tickets_decrypted_previous;
  _Atomic uint64_t tickets_rejected;
  _Atomic uint64_t ticket_errors;
  _Atomic uint64_t ssl_objects_created;
};

typedef struct wls_h3_server wls_h3_server_impl;
typedef struct wls_h3_connection wls_h3_connection;
typedef struct wls_h3_stream wls_h3_stream;
typedef struct wls_h3_io_path wls_h3_io_path;
typedef struct wls_h3_router_endpoint wls_h3_router_endpoint;
typedef struct wls_h3_router_authorized_path
  wls_h3_router_authorized_path;
typedef struct wls_h3_router_egress_datagram
  wls_h3_router_egress_datagram;

typedef struct wls_h3_header {
  char *name;
  char *value;
} wls_h3_header;

typedef struct wls_h3_cid_slot {
  uint64_t hash;
  uint8_t cid[NGTCP2_MAX_CIDLEN];
  uint8_t cid_length;
  uint8_t state;
  wls_h3_connection *connection;
} wls_h3_cid_slot;

typedef struct wls_h3_token_slot {
  uint64_t token;
  uint8_t state;
  wls_h3_stream *stream;
} wls_h3_token_slot;

struct wls_h3_stream {
  wls_h3_stream *next;
  wls_h3_stream *queue_next;
  wls_h3_connection *connection;
  int64_t stream_id;
  uint64_t token;
  uint8_t queued;
  uint8_t response_submitted;
  char *method;
  char *path;
  char *authority;
  char *scheme;
  wls_h3_header *headers;
  size_t header_count;
  size_t header_capacity;
  size_t header_bytes;
  uint8_t *request_body;
  size_t request_body_length;
  size_t request_body_capacity;
  uint8_t *raw_request;
  size_t raw_request_length;
  uint8_t *response_body;
  size_t response_body_length;
};

/*
 * One UDP I/O path used by one or more QUIC connections.
 *
 * Linux uses a public reuseport listener. Darwin uses one process-local
 * packet channel to the Master-owned UDP router. Both remain transport-neutral
 * from ngtcp2's point of view and never allocate one socket per connection.
 */
struct wls_h3_io_path {
  int fd;
  uint8_t owns_fd;
  uint8_t datagram_channel;
  wls_h3_server_impl *server;
  struct sockaddr_storage local_address;
  socklen_t local_address_length;
};

struct wls_h3_connection {
  wls_h3_connection *next;
  wls_h3_server_impl *server;
  wls_h3_io_path *io_path;
  uint64_t connection_id;
  ngtcp2_conn *quic;
  ngtcp2_crypto_ossl_ctx *crypto_context;
  SSL *ssl;
  nghttp3_conn *http3;
  ngtcp2_crypto_conn_ref connection_ref;
  ngtcp2_cid server_cid;
  struct sockaddr_storage local_address;
  socklen_t local_address_length;
  struct sockaddr_storage remote_address;
  socklen_t remote_address_length;
  wls_h3_stream *streams;
  uint8_t *pending_packet;
  size_t pending_packet_length;
  struct sockaddr_storage pending_remote_address;
  socklen_t pending_remote_address_length;
  uint8_t pending_channel_direction;
  uint8_t *terminal_close_packet;
  size_t terminal_close_packet_length;
  ngtcp2_path_storage terminal_close_path_storage;
  uint8_t handshake_complete;
  uint8_t http3_ready;
  uint8_t active_counted;
  uint8_t shutdown_notice_sent;
  uint8_t graceful_shutdown_started;
  uint8_t final_goaway_flushed;
  uint8_t terminal_close_requested;
  uint8_t terminal_close_generated;
  uint8_t terminal_close_handed_off;
  uint8_t terminal_close_error_recorded;
  uint8_t rotation_requested;
  uint8_t rotation_completion_recorded;
  ngtcp2_tstamp shutdown_notice_sent_at;
  ngtcp2_tstamp terminal_close_last_attempt_at;
  uint64_t authorization_id;
  uint64_t request_count;
  uint64_t request_limit;
};

struct wls_h3_server {
  wls_tls_context *tls_context;
  wls_h3_io_path listener_io;
#if defined(__linux__)
  wls_h3_io_path connection_io;
  wls_linux_h3_route linux_route;
  uint8_t linux_route_mode;
#endif
  uint16_t bound_port;
  uint8_t disable_active_migration;
  uint64_t max_idle_timeout_ms;
  uint64_t accepted_initials;
  uint64_t received_datagrams;
  uint64_t next_connection_id;
  uint64_t next_request_token;
  uint64_t active_connections;
  uint64_t active_streams;
  uint64_t queued_requests;
  uint64_t retry_sent;
  uint64_t retry_validated;
  uint64_t rejected_initials;
  uint64_t connection_errors;
  uint64_t connection_read_errors;
  uint64_t connection_flush_errors;
  uint64_t connection_callback_errors;
  uint64_t connection_expiry_errors;
  uint64_t draining_reads;
  uint64_t closing_reads;
  uint64_t flush_skipped_draining;
  uint64_t flush_skipped_closing;
  uint64_t write_stream_not_found;
  uint64_t connection_rotation_requests;
  uint64_t connection_rotation_goaways;
  uint64_t connection_rotation_completions;
  uint64_t max_connection_request_count;
  uint64_t last_connection_error_stage;
  int64_t last_connection_error_code;
  uint64_t capacity_rejections;
  uint64_t peer_mismatch_drops;
  uint64_t routed_datagrams;
  uint64_t channel_drops;
  uint64_t channel_auth_failures;
  uint64_t initial_max_data;
  uint64_t initial_max_stream_data;
  uint64_t initial_max_streams_bidi;
  uint64_t max_connections;
  uint64_t max_active_streams;
  uint64_t retry_token_lifetime_ms;
  uint8_t retry_secret[WLS_H3_RETRY_SECRET_LENGTH];
  uint32_t max_request_header_bytes;
  uint32_t max_request_body_bytes;
  wls_h3_connection *connections;
  wls_h3_stream *request_head;
  wls_h3_stream *request_tail;
  wls_h3_cid_slot *cid_slots;
  wls_h3_token_slot *token_slots;
  int wait_fd;
  int channel_fd;
  char channel_path[sizeof(((struct sockaddr_un *)0)->sun_path)];
  char router_path[sizeof(((struct sockaddr_un *)0)->sun_path)];
  uint8_t channel_key[WLS_H3_CHANNEL_KEY_LENGTH];
  uint32_t route_id;
  uint32_t worker_id;
  uint64_t worker_generation;
  uint8_t datagram_worker_mode;
  uint8_t draining;
  ngtcp2_tstamp drain_started_at;
};

static void wls_record_connection_error(wls_h3_connection *connection,
                                        uint64_t stage, int64_t code) {
  if (!connection || !connection->server) {
    return;
  }
  wls_h3_server_impl *server = connection->server;
  switch (stage) {
    case WLS_H3_CONNECTION_ERROR_STAGE_READ_PKT:
      ++server->connection_read_errors;
      break;
    case WLS_H3_CONNECTION_ERROR_STAGE_FLUSH:
      ++server->connection_flush_errors;
      break;
    case WLS_H3_CONNECTION_ERROR_STAGE_CALLBACK:
      ++server->connection_callback_errors;
      break;
    case WLS_H3_CONNECTION_ERROR_STAGE_EXPIRY:
      ++server->connection_expiry_errors;
      break;
    default:
      break;
  }
  server->last_connection_error_stage = stage;
  server->last_connection_error_code = code;
}

typedef struct wls_h3_channel_datagram {
  uint32_t magic;
  uint16_t version;
  uint16_t header_size;
  uint64_t route_epoch;
  uint32_t worker_id;
  uint32_t datagram_length;
  uint64_t worker_generation;
  uint64_t authorization_id;
  uint16_t local_address_length;
  uint16_t remote_address_length;
  uint8_t direction;
  uint8_t terminal_cid_count;
  uint8_t terminal_cid_lengths[WLS_H3_MAX_TERMINAL_CIDS];
  uint8_t reserved8[6];
  uint8_t terminal_cids[WLS_H3_MAX_TERMINAL_CIDS][NGTCP2_MAX_CIDLEN];
  struct sockaddr_storage local_address;
  struct sockaddr_storage remote_address;
  uint8_t authentication_tag[32];
} wls_h3_channel_datagram;

struct wls_h3_router_endpoint {
  uint32_t route_id;
  uint32_t worker_id;
  uint64_t generation;
  uint8_t accepting_new_connections;
  int channel_fd;
  char channel_path[sizeof(((struct sockaddr_un *)0)->sun_path)];
  char router_path[sizeof(((struct sockaddr_un *)0)->sun_path)];
  uint8_t channel_key[WLS_H3_CHANNEL_KEY_LENGTH];
};

struct wls_h3_router_authorized_path {
  uint32_t route_id;
  uint8_t state;
  uint8_t source_bucket;
  uint8_t terminal_pending;
  uint8_t terminal_sent;
  uint8_t terminal_attempted;
  uint64_t generation;
  uint64_t hash;
  uint64_t authorization_id;
  ngtcp2_tstamp expires_at;
  struct sockaddr_storage local_address;
  socklen_t local_address_length;
  struct sockaddr_storage remote_address;
  socklen_t remote_address_length;
  uint8_t terminal_cid_count;
  uint8_t terminal_cid_lengths[WLS_H3_MAX_TERMINAL_CIDS];
  uint8_t terminal_cids[WLS_H3_MAX_TERMINAL_CIDS][NGTCP2_MAX_CIDLEN];
  uint8_t *terminal_packet;
  size_t terminal_packet_length;
  ngtcp2_tstamp next_terminal_send_at;
};

struct wls_h3_router_egress_datagram {
  uint8_t packet[WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES];
  size_t packet_length;
  struct sockaddr_storage local_address;
  socklen_t local_address_length;
  struct sockaddr_storage remote_address;
  socklen_t remote_address_length;
  ngtcp2_tstamp expires_at;
};

struct wls_h3_datagram_router {
  wls_h3_io_path listener_io;
  int wait_fd;
  uint16_t bound_port;
  struct sockaddr_storage allowed_local_address;
  socklen_t allowed_local_address_length;
  uint8_t filter_local_address;
  uint32_t max_initial_datagram_bytes;
  uint64_t retry_token_lifetime_ms;
  uint8_t retry_secret[WLS_H3_RETRY_SECRET_LENGTH];
  wls_h3_router_endpoint endpoints[WLS_H3_MAX_ROUTER_ENDPOINTS];
  size_t endpoint_count;
  uint64_t route_epoch;
  wls_h3_router_authorized_path *authorized_paths;
  uint32_t live_authorizations;
  uint32_t provisional_authorizations;
  uint32_t established_authorizations;
  uint32_t closing_authorizations;
  uint32_t pending_terminal_closes;
  uint16_t provisional_source_buckets[WLS_H3_ROUTER_SOURCE_BUCKETS];
  ngtcp2_tstamp next_authorization_sweep_at;
  wls_h3_router_egress_datagram *pending_egress;
  size_t pending_egress_head;
  size_t pending_egress_count;
  uint64_t received_datagrams;
  uint64_t routed_datagrams;
  uint64_t ingress_drops;
  size_t pending_ingress_length;
  uint32_t pending_ingress_worker_id;
  uint64_t pending_ingress_generation;
  uint64_t pending_ingress_route_epoch;
  ngtcp2_tstamp pending_ingress_queued_at;
  uint8_t pending_ingress_payload[WLS_H3_ROUTER_INGRESS_PAYLOAD_CAPACITY];
  uint64_t ingress_datagrams_queued;
  uint64_t ingress_queue_sends;
  uint64_t ingress_queue_retries;
  uint64_t ingress_queue_drops;
  uint64_t egress_datagrams;
  uint64_t egress_drops;
  uint64_t channel_auth_failures;
  uint64_t retry_sent;
  uint64_t retry_validated;
  uint64_t rejected_initials;
  uint64_t terminal_closes_cached;
  uint64_t terminal_close_sends;
  uint64_t terminal_close_resends;
  uint64_t terminal_close_drops;
  uint64_t terminal_close_rate_limited;
  uint64_t egress_datagrams_queued;
  uint64_t egress_queue_sends;
  uint64_t egress_queue_retries;
  uint64_t egress_queue_drops;
  uint8_t terminal_write_interest;
};

static int wls_worker_send_channel_datagram(
  wls_h3_server_impl *server, const uint8_t *packet,
  size_t packet_length, const struct sockaddr *local_address,
  socklen_t local_address_length, const struct sockaddr *remote_address,
  socklen_t remote_address_length, uint64_t authorization_id,
  uint8_t direction, const wls_h3_connection *terminal_connection);

#if defined(__APPLE__)
static void wls_router_sweep_authorizations(
  wls_h3_datagram_router *router, ngtcp2_tstamp now, int force);

static int wls_router_receive_egress(
  wls_h3_datagram_router *router, wls_h3_router_endpoint *endpoint);
#endif

static void wls_set_error(const char *format, ...) {
  va_list ap;
  va_start(ap, format);
  vsnprintf(wls_last_error, sizeof(wls_last_error), format, ap);
  va_end(ap);
}

static void wls_set_ssl_error(const char *operation) {
  unsigned long error_code = ERR_get_error();
  char detail[256] = {0};
  if (error_code != 0) {
    ERR_error_string_n(error_code, detail, sizeof(detail));
  } else {
    snprintf(detail, sizeof(detail), "unknown OpenSSL error");
  }
  wls_set_error("%s: %s", operation, detail);
}


static ngtcp2_tstamp wls_now(void) {
  struct timespec value;
  clock_gettime(CLOCK_MONOTONIC, &value);
  return (ngtcp2_tstamp)value.tv_sec * NGTCP2_SECONDS +
         (ngtcp2_tstamp)value.tv_nsec;
}

static ssize_t wls_udp_send_with_source(
  int fd, const uint8_t *packet, size_t packet_length,
  const struct sockaddr *local_address, socklen_t local_address_length,
  const struct sockaddr *remote_address, socklen_t remote_address_length) {
  if (!local_address || local_address_length == 0 ||
      local_address->sa_family != remote_address->sa_family) {
    return sendto(fd, packet, packet_length, 0,
                  remote_address, remote_address_length);
  }

  struct iovec vector = {
    .iov_base = (void *)(uintptr_t)packet,
    .iov_len = packet_length,
  };
  struct msghdr message;
  union {
    struct cmsghdr alignment;
    uint8_t bytes[128];
  } control;
  memset(&message, 0, sizeof(message));
  memset(&control, 0, sizeof(control));
  message.msg_name = (void *)(uintptr_t)remote_address;
  message.msg_namelen = remote_address_length;
  message.msg_iov = &vector;
  message.msg_iovlen = 1;

  if (local_address->sa_family == AF_INET &&
      local_address_length >= sizeof(struct sockaddr_in)) {
    const struct sockaddr_in *local_ipv4 =
      (const struct sockaddr_in *)local_address;
    if (local_ipv4->sin_addr.s_addr == htonl(INADDR_ANY)) {
      return sendto(fd, packet, packet_length, 0,
                    remote_address, remote_address_length);
    }
#if defined(__APPLE__) && defined(IP_SENDSRCADDR)
    message.msg_control = control.bytes;
    message.msg_controllen = CMSG_SPACE(sizeof(struct in_addr));
    struct cmsghdr *item = CMSG_FIRSTHDR(&message);
    item->cmsg_level = IPPROTO_IP;
    item->cmsg_type = IP_SENDSRCADDR;
    item->cmsg_len = CMSG_LEN(sizeof(struct in_addr));
    memcpy(CMSG_DATA(item), &local_ipv4->sin_addr,
           sizeof(local_ipv4->sin_addr));
#elif defined(__linux__) && defined(IP_PKTINFO)
    message.msg_control = control.bytes;
    message.msg_controllen = CMSG_SPACE(sizeof(struct in_pktinfo));
    struct cmsghdr *item = CMSG_FIRSTHDR(&message);
    item->cmsg_level = IPPROTO_IP;
    item->cmsg_type = IP_PKTINFO;
    item->cmsg_len = CMSG_LEN(sizeof(struct in_pktinfo));
    struct in_pktinfo *packet_info = (struct in_pktinfo *)CMSG_DATA(item);
    packet_info->ipi_spec_dst = local_ipv4->sin_addr;
#else
    return sendto(fd, packet, packet_length, 0,
                  remote_address, remote_address_length);
#endif
  } else if (local_address->sa_family == AF_INET6 &&
             local_address_length >= sizeof(struct sockaddr_in6)) {
    const struct sockaddr_in6 *local_ipv6 =
      (const struct sockaddr_in6 *)local_address;
    if (IN6_IS_ADDR_UNSPECIFIED(&local_ipv6->sin6_addr)) {
      return sendto(fd, packet, packet_length, 0,
                    remote_address, remote_address_length);
    }
#if defined(IPV6_PKTINFO)
    message.msg_control = control.bytes;
    message.msg_controllen = CMSG_SPACE(sizeof(struct in6_pktinfo));
    struct cmsghdr *item = CMSG_FIRSTHDR(&message);
    item->cmsg_level = IPPROTO_IPV6;
    item->cmsg_type = IPV6_PKTINFO;
    item->cmsg_len = CMSG_LEN(sizeof(struct in6_pktinfo));
    struct in6_pktinfo *packet_info =
      (struct in6_pktinfo *)CMSG_DATA(item);
    packet_info->ipi6_addr = local_ipv6->sin6_addr;
    packet_info->ipi6_ifindex = local_ipv6->sin6_scope_id;
#else
    return sendto(fd, packet, packet_length, 0,
                  remote_address, remote_address_length);
#endif
  } else {
    return sendto(fd, packet, packet_length, 0,
                  remote_address, remote_address_length);
  }

  return sendmsg(fd, &message, 0);
}

static int wls_io_path_send_datagram(
  const wls_h3_io_path *io_path, const uint8_t *packet, size_t packet_length,
  const struct sockaddr *local_address, socklen_t local_address_length,
  const struct sockaddr *remote_address, socklen_t remote_address_length,
  uint64_t authorization_id, const char *operation) {
  if (!io_path || io_path->fd < 0 || !packet || packet_length == 0) {
    wls_set_error("%s: invalid UDP I/O path", operation);
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  if (io_path->datagram_channel) {
    return wls_worker_send_channel_datagram(
      io_path->server, packet, packet_length, local_address,
      local_address_length, remote_address, remote_address_length,
      authorization_id, WLS_H3_CHANNEL_EGRESS, NULL);
  }

  if (!remote_address || remote_address_length == 0) {
    wls_set_error("%s: missing remote address", operation);
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  ssize_t sent;
  do {
    sent = wls_udp_send_with_source(
      io_path->fd, packet, packet_length, local_address,
      local_address_length, remote_address, remote_address_length);
  } while (sent < 0 && errno == EINTR);
  if (sent < 0) {
    if (errno == EAGAIN || errno == EWOULDBLOCK || errno == ENOBUFS) {
      return WLS_TRANSPORT_AGAIN;
    }
    wls_set_error("%s: %s", operation, strerror(errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  if ((size_t)sent != packet_length) {
    wls_set_error("%s: short datagram write", operation);
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  return WLS_TRANSPORT_OK;
}

static void wls_io_path_close(wls_h3_io_path *io_path) {
  if (!io_path) {
    return;
  }
  if (io_path->owns_fd && io_path->fd >= 0) {
    close(io_path->fd);
  }
  io_path->fd = -1;
  io_path->owns_fd = 0;
  io_path->local_address_length = 0;
}

#if defined(__APPLE__)
static int wls_set_nonblocking_cloexec(int fd) {
  int flags = fcntl(fd, F_GETFL, 0);
  if (flags < 0 || fcntl(fd, F_SETFL, flags | O_NONBLOCK) != 0) {
    return -1;
  }
  int descriptor_flags = fcntl(fd, F_GETFD, 0);
  if (descriptor_flags < 0 ||
      fcntl(fd, F_SETFD, descriptor_flags | FD_CLOEXEC) != 0) {
    return -1;
  }
  return 0;
}
#endif

#if defined(__APPLE__) || !defined(F_DUPFD_CLOEXEC)
static int wls_set_cloexec(int fd) {
  int descriptor_flags = fcntl(fd, F_GETFD, 0);
  if (descriptor_flags < 0 ||
      fcntl(fd, F_SETFD, descriptor_flags | FD_CLOEXEC) != 0) {
    return -1;
  }
  return 0;
}
#endif

static void wls_write_u32_be(uint8_t *target, uint32_t value) {
  for (unsigned index = 0; index < 4; ++index) {
    target[index] = (uint8_t)(value >> (24u - index * 8u));
  }
}

#if defined(__APPLE__)
static void wls_write_u64_be(uint8_t *target, uint64_t value) {
  for (unsigned index = 0; index < 8; ++index) {
    target[index] = (uint8_t)(value >> (56u - index * 8u));
  }
}
#endif

#if defined(__APPLE__)
static uint32_t wls_read_u32_be(const uint8_t *source) {
  uint32_t value = 0;
  for (unsigned index = 0; index < 4; ++index) {
    value = (value << 8u) | source[index];
  }
  return value;
}
#endif

#if defined(__APPLE__)
static uint64_t wls_read_u64_be(const uint8_t *source) {
  uint64_t value = 0;
  for (unsigned index = 0; index < 8; ++index) {
    value = (value << 8u) | source[index];
  }
  return value;
}
#endif

#if defined(__APPLE__)
static uint16_t wls_sockaddr_port(const struct sockaddr_storage *address) {
  if (!address) {
    return 0;
  }
  if (address->ss_family == AF_INET) {
    return ntohs(((const struct sockaddr_in *)address)->sin_port);
  }
  if (address->ss_family == AF_INET6) {
    return ntohs(((const struct sockaddr_in6 *)address)->sin6_port);
  }
  return 0;
}
#endif

static int wls_ipv6_scope_required(const struct in6_addr *address) {
  if (!address) {
    return 0;
  }
  if (IN6_IS_ADDR_LINKLOCAL(address)) {
    return 1;
  }
  if (IN6_IS_ADDR_MULTICAST(address)) {
    uint8_t scope = address->s6_addr[1] & 0x0fu;
    return scope == 1u || scope == 2u;
  }
  return 0;
}

static int wls_sockaddr_equal(const struct sockaddr_storage *left,
                              socklen_t left_length,
                              const struct sockaddr_storage *right,
                              socklen_t right_length) {
  if (!left || !right || left->ss_family != right->ss_family ||
      left_length == 0 || right_length == 0) {
    return 0;
  }
  if (left->ss_family == AF_INET) {
    const struct sockaddr_in *a = (const struct sockaddr_in *)left;
    const struct sockaddr_in *b = (const struct sockaddr_in *)right;
    return a->sin_port == b->sin_port &&
           memcmp(&a->sin_addr, &b->sin_addr, sizeof(a->sin_addr)) == 0;
  }
  if (left->ss_family == AF_INET6) {
    const struct sockaddr_in6 *a = (const struct sockaddr_in6 *)left;
    const struct sockaddr_in6 *b = (const struct sockaddr_in6 *)right;
    int scope_required = wls_ipv6_scope_required(&a->sin6_addr);
    return a->sin6_port == b->sin6_port &&
           (!scope_required || a->sin6_scope_id == b->sin6_scope_id) &&
           memcmp(&a->sin6_addr, &b->sin6_addr, sizeof(a->sin6_addr)) == 0;
  }
  return 0;
}

#if defined(__APPLE__)
static int wls_sockaddr_is_wildcard(
  const struct sockaddr_storage *address, socklen_t address_length) {
  if (!address || address_length == 0) {
    return 0;
  }
  if (address->ss_family == AF_INET) {
    const struct sockaddr_in *ipv4 = (const struct sockaddr_in *)address;
    return ipv4->sin_addr.s_addr == htonl(INADDR_ANY);
  }
  if (address->ss_family == AF_INET6) {
    const struct sockaddr_in6 *ipv6 = (const struct sockaddr_in6 *)address;
    return IN6_IS_ADDR_UNSPECIFIED(&ipv6->sin6_addr);
  }
  return 0;
}
#endif

#if defined(__APPLE__)
static int wls_sockaddr_address_equal(
  const struct sockaddr *left, const struct sockaddr *right) {
  if (!left || !right || left->sa_family != right->sa_family) {
    return 0;
  }
  if (left->sa_family == AF_INET) {
    const struct sockaddr_in *a = (const struct sockaddr_in *)left;
    const struct sockaddr_in *b = (const struct sockaddr_in *)right;
    return memcmp(&a->sin_addr, &b->sin_addr, sizeof(a->sin_addr)) == 0;
  }
  if (left->sa_family == AF_INET6) {
    const struct sockaddr_in6 *a = (const struct sockaddr_in6 *)left;
    const struct sockaddr_in6 *b = (const struct sockaddr_in6 *)right;
    return memcmp(&a->sin6_addr, &b->sin6_addr,
                  sizeof(a->sin6_addr)) == 0 &&
           (!wls_ipv6_scope_required(&a->sin6_addr) ||
            a->sin6_scope_id == 0 || b->sin6_scope_id == 0 ||
            a->sin6_scope_id == b->sin6_scope_id);
  }
  return 0;
}

static unsigned wls_interface_index_for_address(
  const struct sockaddr_storage *address) {
  if (!address || (address->ss_family != AF_INET &&
                   address->ss_family != AF_INET6)) {
    return 0;
  }
  struct ifaddrs *interfaces = NULL;
  if (getifaddrs(&interfaces) != 0) {
    return 0;
  }
  unsigned index = 0;
  for (const struct ifaddrs *item = interfaces; item; item = item->ifa_next) {
    if (!item->ifa_addr || !item->ifa_name ||
        !wls_sockaddr_address_equal(
          (const struct sockaddr *)address, item->ifa_addr)) {
      continue;
    }
    index = if_nametoindex(item->ifa_name);
    if (index != 0) {
      break;
    }
  }
  freeifaddrs(interfaces);
  return index;
}

static int wls_enable_destination_address(int fd, int family) {
  int one = 1;
  if (family == AF_INET) {
#ifdef IP_RECVDSTADDR
    return setsockopt(fd, IPPROTO_IP, IP_RECVDSTADDR, &one,
                      sizeof(one)) == 0
             ? WLS_TRANSPORT_OK
             : WLS_TRANSPORT_SOCKET_ERROR;
#elif defined(IP_PKTINFO)
    return setsockopt(fd, IPPROTO_IP, IP_PKTINFO, &one,
                      sizeof(one)) == 0
             ? WLS_TRANSPORT_OK
             : WLS_TRANSPORT_SOCKET_ERROR;
#else
    errno = ENOTSUP;
    return WLS_TRANSPORT_UNSUPPORTED;
#endif
  }
  if (family == AF_INET6) {
#ifdef IPV6_RECVPKTINFO
    return setsockopt(fd, IPPROTO_IPV6, IPV6_RECVPKTINFO, &one,
                      sizeof(one)) == 0
             ? WLS_TRANSPORT_OK
             : WLS_TRANSPORT_SOCKET_ERROR;
#else
    errno = ENOTSUP;
    return WLS_TRANSPORT_UNSUPPORTED;
#endif
  }
  errno = EAFNOSUPPORT;
  return WLS_TRANSPORT_UNSUPPORTED;
}
#endif

#if defined(__APPLE__)
static int wls_valid_channel_path(const char *path) {
  if (!path || path[0] != '/' || strstr(path, "..") != NULL) {
    return 0;
  }
  size_t length = strlen(path);
  return length > 1 &&
         length < sizeof(((struct sockaddr_un *)0)->sun_path);
}

static int wls_unix_source_path_matches(
  const struct sockaddr_un *source, socklen_t source_length,
  const char *expected_path) {
  const size_t path_offset = offsetof(struct sockaddr_un, sun_path);
  if (!source || !expected_path || source->sun_family != AF_UNIX ||
      source_length <= path_offset) {
    return 0;
  }
  size_t available = (size_t)source_length - path_offset;
  size_t expected_length = strlen(expected_path);
  return expected_length < available &&
    source->sun_path[expected_length] == '\0' &&
    memcmp(source->sun_path, expected_path, expected_length) == 0;
}

static int wls_router_channel_path(const char *worker_path, char *router_path,
                                  size_t router_path_capacity) {
  if (!wls_valid_channel_path(worker_path) || !router_path ||
      router_path_capacity == 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  int written = snprintf(router_path, router_path_capacity, "%s.o", worker_path);
  if (written <= 0 || (size_t)written >= router_path_capacity) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  return WLS_TRANSPORT_OK;
}

static int wls_channel_authentication_tag(
  const uint8_t key[WLS_H3_CHANNEL_KEY_LENGTH],
  const wls_h3_channel_datagram *envelope, const uint8_t *datagram,
  size_t datagram_length, uint8_t output[32]) {
  size_t header_length = offsetof(wls_h3_channel_datagram,
                                  authentication_tag);
  if (!envelope || (!datagram && datagram_length != 0) ||
      datagram_length > WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES ||
      header_length > SIZE_MAX - datagram_length) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  size_t signed_length = header_length + datagram_length;
  uint8_t signed_payload[sizeof(wls_h3_channel_datagram) +
                         WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES];
  memcpy(signed_payload, envelope, header_length);
  if (datagram_length != 0) {
    memcpy(signed_payload + header_length, datagram, datagram_length);
  }
  unsigned int tag_length = 0;
  unsigned char *result = HMAC(
    EVP_sha256(), key, WLS_H3_CHANNEL_KEY_LENGTH, signed_payload,
    signed_length, output, &tag_length);
  OPENSSL_cleanse(signed_payload, signed_length);
  if (!result || tag_length != 32) {
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }
  return WLS_TRANSPORT_OK;
}

static uint32_t wls_route_id_for_key(
  const uint8_t key[WLS_H3_CHANNEL_KEY_LENGTH]) {
  static const uint8_t context[] = "wls-h3-route-id-v2";
  uint8_t digest[32];
  unsigned int digest_length = 0;
  unsigned char *result = HMAC(
    EVP_sha256(), key, WLS_H3_CHANNEL_KEY_LENGTH, context,
    sizeof(context) - 1, digest, &digest_length);
  if (!result || digest_length < 4) {
    OPENSSL_cleanse(digest, sizeof(digest));
    return 0;
  }
  uint32_t route_id = wls_read_u32_be(digest);
  OPENSSL_cleanse(digest, sizeof(digest));
  return route_id == 0 ? 1 : route_id;
}
#endif

static int wls_route_cid_tag(
  const uint8_t key[WLS_H3_CHANNEL_KEY_LENGTH], const uint8_t *cid,
  size_t cid_length, uint8_t output[7]) {
  static const uint8_t context[] = "wls-h3-route-cid-v2";
  if (!key || !cid || cid_length < 11 || !output) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  uint8_t material[sizeof(context) - 1 + 11];
  memcpy(material, context, sizeof(context) - 1);
  memcpy(material + sizeof(context) - 1, cid, 11);
  uint8_t digest[32];
  unsigned int digest_length = 0;
  unsigned char *result = HMAC(
    EVP_sha256(), key, WLS_H3_CHANNEL_KEY_LENGTH, material,
    sizeof(material), digest, &digest_length);
  OPENSSL_cleanse(material, sizeof(material));
  if (!result || digest_length < 7) {
    OPENSSL_cleanse(digest, sizeof(digest));
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }
  memcpy(output, digest, 7);
  OPENSSL_cleanse(digest, sizeof(digest));
  return WLS_TRANSPORT_OK;
}

static int wls_route_cid_generate(
  const uint8_t key[WLS_H3_CHANNEL_KEY_LENGTH], uint32_t route_id,
  ngtcp2_cid *cid) {
  if (!key || route_id == 0 || !cid) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  cid->datalen = WLS_H3_SERVER_SCID_LENGTH;
  memset(cid->data, 0, cid->datalen);
  cid->data[0] = WLS_H3_ROUTE_CID_MARKER;
  wls_write_u32_be(cid->data + 1, route_id);
  if (RAND_bytes(cid->data + 5, 6) != 1) {
    return WLS_TRANSPORT_TLS_ERROR;
  }
  return wls_route_cid_tag(key, cid->data, cid->datalen,
                           cid->data + 11);
}

#if defined(__APPLE__)
static int wls_route_cid_valid(
  const wls_h3_router_endpoint *endpoint, const uint8_t *cid,
  size_t cid_length) {
  if (!endpoint || !cid || cid_length != WLS_H3_SERVER_SCID_LENGTH ||
      cid[0] != WLS_H3_ROUTE_CID_MARKER ||
      wls_read_u32_be(cid + 1) != endpoint->route_id) {
    return 0;
  }
  uint8_t expected[7];
  int result = wls_route_cid_tag(
    endpoint->channel_key, cid, cid_length, expected);
  int valid = result == WLS_TRANSPORT_OK &&
    CRYPTO_memcmp(expected, cid + 11, sizeof(expected)) == 0;
  OPENSSL_cleanse(expected, sizeof(expected));
  return valid;
}
#endif

static int wls_worker_send_channel_datagram(
  wls_h3_server_impl *server, const uint8_t *packet,
  size_t packet_length, const struct sockaddr *local_address,
  socklen_t local_address_length, const struct sockaddr *remote_address,
  socklen_t remote_address_length, uint64_t authorization_id,
  uint8_t direction, const wls_h3_connection *terminal_connection) {
#if !defined(__APPLE__)
  (void)server;
  (void)packet;
  (void)packet_length;
  (void)local_address;
  (void)local_address_length;
  (void)remote_address;
  (void)remote_address_length;
  (void)authorization_id;
  (void)direction;
  (void)terminal_connection;
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  int is_terminal_close = direction == WLS_H3_CHANNEL_PATH_CLOSE;
  if (!server || !server->datagram_worker_mode || server->channel_fd < 0 ||
      server->router_path[0] == '\0' || !packet || packet_length == 0 ||
      packet_length > WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES || !local_address ||
      authorization_id == 0 ||
      (direction != WLS_H3_CHANNEL_EGRESS && !is_terminal_close) ||
      (is_terminal_close && !terminal_connection) ||
      local_address_length == 0 ||
      local_address_length > sizeof(struct sockaddr_storage) ||
      !remote_address || remote_address_length == 0 ||
      remote_address_length > sizeof(struct sockaddr_storage)) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  size_t payload_length = sizeof(wls_h3_channel_datagram) + packet_length;
  uint8_t payload[sizeof(wls_h3_channel_datagram) +
                  WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES];
  wls_h3_channel_datagram *envelope =
    (wls_h3_channel_datagram *)payload;
  memset(envelope, 0, sizeof(*envelope));
  envelope->magic = WLS_H3_CHANNEL_MAGIC;
  envelope->version = WLS_H3_CHANNEL_VERSION;
  envelope->header_size = sizeof(*envelope);
  envelope->worker_id = server->worker_id;
  envelope->worker_generation = server->worker_generation;
  envelope->authorization_id = authorization_id;
  envelope->datagram_length = (uint32_t)packet_length;
  envelope->local_address_length = (uint16_t)local_address_length;
  envelope->remote_address_length = (uint16_t)remote_address_length;
  envelope->direction = direction;
  if (is_terminal_close) {
    for (size_t index = 0;
         index < WLS_H3_CID_TABLE_CAPACITY &&
           envelope->terminal_cid_count < WLS_H3_MAX_TERMINAL_CIDS;
         ++index) {
      const wls_h3_cid_slot *slot = &server->cid_slots[index];
      if (slot->state != 1 || slot->connection != terminal_connection ||
          slot->cid_length == 0 || slot->cid_length > NGTCP2_MAX_CIDLEN ||
          slot->cid_length != WLS_H3_SERVER_SCID_LENGTH ||
          slot->cid[0] != WLS_H3_ROUTE_CID_MARKER ||
          wls_read_u32_be(slot->cid + 1) != server->route_id) {
        continue;
      }
      size_t cid_index = envelope->terminal_cid_count;
      envelope->terminal_cid_lengths[cid_index] = slot->cid_length;
      memcpy(envelope->terminal_cids[cid_index], slot->cid,
             slot->cid_length);
      ++envelope->terminal_cid_count;
    }
    if (envelope->terminal_cid_count == 0) {
      OPENSSL_cleanse(payload, payload_length);
      wls_set_error("terminal close has no active routed connection id");
      return WLS_TRANSPORT_INTERNAL_ERROR;
    }
  }
  memcpy(&envelope->local_address, local_address, local_address_length);
  memcpy(&envelope->remote_address, remote_address,
         remote_address_length);
  memcpy(payload + sizeof(*envelope), packet, packet_length);
  int auth_result = wls_channel_authentication_tag(
    server->channel_key, envelope, payload + sizeof(*envelope),
    packet_length, envelope->authentication_tag);
  if (auth_result != WLS_TRANSPORT_OK) {
    OPENSSL_cleanse(payload, payload_length);
    return auth_result;
  }

  struct sockaddr_un destination;
  memset(&destination, 0, sizeof(destination));
  destination.sun_family = AF_UNIX;
  memcpy(destination.sun_path, server->router_path,
         strlen(server->router_path) + 1);
  ssize_t sent;
  do {
    sent = sendto(server->channel_fd, payload, payload_length, 0,
                  (struct sockaddr *)&destination, sizeof(destination));
  } while (sent < 0 && errno == EINTR);
  OPENSSL_cleanse(payload, payload_length);
  if (sent < 0) {
    ++server->channel_drops;
    if (errno == EAGAIN || errno == EWOULDBLOCK || errno == ENOBUFS) {
      return WLS_TRANSPORT_AGAIN;
    }
    wls_set_error("send Darwin HTTP/3 Worker datagram channel: %s",
                  strerror(errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  if ((size_t)sent != payload_length) {
    ++server->channel_drops;
    wls_set_error("Darwin HTTP/3 Worker datagram channel write was not atomic");
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  ++server->routed_datagrams;
  return WLS_TRANSPORT_OK;
#endif
}

static int wls_worker_retire_authorization(
  const wls_h3_connection *connection) {
#if !defined(__APPLE__)
  (void)connection;
  return WLS_TRANSPORT_OK;
#else
  if (!connection || connection->authorization_id == 0 ||
      !connection->server || !connection->server->datagram_worker_mode ||
      connection->server->channel_fd < 0 ||
      connection->server->router_path[0] == '\0') {
    return WLS_TRANSPORT_OK;
  }
  wls_h3_server_impl *server = connection->server;
  wls_h3_channel_datagram envelope;
  memset(&envelope, 0, sizeof(envelope));
  envelope.magic = WLS_H3_CHANNEL_MAGIC;
  envelope.version = WLS_H3_CHANNEL_VERSION;
  envelope.header_size = sizeof(envelope);
  envelope.worker_id = server->worker_id;
  envelope.worker_generation = server->worker_generation;
  envelope.authorization_id = connection->authorization_id;
  envelope.local_address_length =
    (uint16_t)connection->local_address_length;
  envelope.remote_address_length =
    (uint16_t)connection->remote_address_length;
  envelope.direction = WLS_H3_CHANNEL_PATH_RETIRE;
  memcpy(&envelope.local_address, &connection->local_address,
         connection->local_address_length);
  memcpy(&envelope.remote_address, &connection->remote_address,
         connection->remote_address_length);
  int auth_result = wls_channel_authentication_tag(
    server->channel_key, &envelope, NULL, 0,
    envelope.authentication_tag);
  if (auth_result != WLS_TRANSPORT_OK) {
    return auth_result;
  }

  struct sockaddr_un destination;
  memset(&destination, 0, sizeof(destination));
  destination.sun_family = AF_UNIX;
  memcpy(destination.sun_path, server->router_path,
         strlen(server->router_path) + 1);
  ssize_t sent;
  do {
    sent = sendto(server->channel_fd, &envelope, sizeof(envelope), 0,
                  (struct sockaddr *)&destination, sizeof(destination));
  } while (sent < 0 && errno == EINTR);
  OPENSSL_cleanse(&envelope, sizeof(envelope));
  if (sent == (ssize_t)sizeof(envelope)) {
    return WLS_TRANSPORT_OK;
  }
  ++server->channel_drops;
  return sent < 0 &&
      (errno == EAGAIN || errno == EWOULDBLOCK || errno == ENOBUFS)
    ? WLS_TRANSPORT_AGAIN
    : WLS_TRANSPORT_SOCKET_ERROR;
#endif
}

static uint64_t wls_hash_bytes(const uint8_t *data, size_t length) {
  uint64_t hash = UINT64_C(1469598103934665603);
  for (size_t index = 0; index < length; ++index) {
    hash ^= data[index];
    hash *= UINT64_C(1099511628211);
  }
  return hash == 0 ? 1 : hash;
}

static wls_h3_connection *wls_cid_lookup(wls_h3_server_impl *server,
                                         const uint8_t *cid,
                                         size_t cid_length) {
  uint64_t hash = wls_hash_bytes(cid, cid_length);
  size_t index = (size_t)hash & (WLS_H3_CID_TABLE_CAPACITY - 1);
  for (size_t probe = 0; probe < WLS_H3_CID_TABLE_CAPACITY; ++probe) {
    wls_h3_cid_slot *slot = &server->cid_slots[index];
    if (slot->state == 0) {
      return NULL;
    }
    if (slot->state == 1 && slot->hash == hash &&
        slot->cid_length == cid_length &&
        memcmp(slot->cid, cid, cid_length) == 0) {
      return slot->connection;
    }
    index = (index + 1) & (WLS_H3_CID_TABLE_CAPACITY - 1);
  }
  return NULL;
}

static int wls_cid_publish_route(
  wls_h3_server_impl *server, const uint8_t *cid, size_t cid_length) {
#if defined(__linux__)
  if (server->linux_route_mode &&
      cid_length == WLS_LINUX_H3_SERVER_CID_LENGTH) {
    char error[sizeof(wls_last_error)];
    memset(error, 0, sizeof(error));
    int result = wls_linux_h3_route_insert_cid(
      &server->linux_route, cid, cid_length, error, sizeof(error));
    if (result != WLS_TRANSPORT_OK) {
      wls_set_error("%s", error[0] != 0
                            ? error
                            : "Linux HTTP/3 CID already belongs to a route");
    }
    return result;
  }
#else
  (void)server;
  (void)cid;
  (void)cid_length;
#endif
  return WLS_TRANSPORT_OK;
}

static void wls_cid_retire_route(
  wls_h3_server_impl *server, const uint8_t *cid, size_t cid_length) {
#if defined(__linux__)
  if (server->linux_route_mode &&
      cid_length == WLS_LINUX_H3_SERVER_CID_LENGTH) {
    wls_linux_h3_route_delete_cid(
      &server->linux_route, cid, cid_length);
  }
#else
  (void)server;
  (void)cid;
  (void)cid_length;
#endif
}

static int wls_cid_insert(wls_h3_server_impl *server, const uint8_t *cid,
                          size_t cid_length, wls_h3_connection *connection) {
  if (cid_length == 0 || cid_length > NGTCP2_MAX_CIDLEN) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  uint64_t hash = wls_hash_bytes(cid, cid_length);
  size_t index = (size_t)hash & (WLS_H3_CID_TABLE_CAPACITY - 1);
  size_t tombstone = SIZE_MAX;
  for (size_t probe = 0; probe < WLS_H3_CID_TABLE_CAPACITY; ++probe) {
    wls_h3_cid_slot *slot = &server->cid_slots[index];
    if (slot->state == 2 && tombstone == SIZE_MAX) {
      tombstone = index;
    } else if (slot->state == 0) {
      if (tombstone != SIZE_MAX) {
        slot = &server->cid_slots[tombstone];
      }
      int route_result = wls_cid_publish_route(server, cid, cid_length);
      if (route_result != WLS_TRANSPORT_OK) {
        return route_result;
      }
      slot->state = 1;
      slot->hash = hash;
      slot->cid_length = (uint8_t)cid_length;
      memcpy(slot->cid, cid, cid_length);
      slot->connection = connection;
      return WLS_TRANSPORT_OK;
    } else if (slot->state == 1 && slot->hash == hash &&
               slot->cid_length == cid_length &&
               memcmp(slot->cid, cid, cid_length) == 0) {
      slot->connection = connection;
      return WLS_TRANSPORT_OK;
    }
    index = (index + 1) & (WLS_H3_CID_TABLE_CAPACITY - 1);
  }
  if (tombstone != SIZE_MAX) {
    wls_h3_cid_slot *slot = &server->cid_slots[tombstone];
    int route_result = wls_cid_publish_route(server, cid, cid_length);
    if (route_result != WLS_TRANSPORT_OK) {
      return route_result;
    }
    slot->state = 1;
    slot->hash = hash;
    slot->cid_length = (uint8_t)cid_length;
    memcpy(slot->cid, cid, cid_length);
    slot->connection = connection;
    return WLS_TRANSPORT_OK;
  }
  wls_set_error("CID table is full");
  return WLS_TRANSPORT_NOMEM;
}

static void wls_cid_delete_at(wls_h3_server_impl *server, size_t index) {
  const size_t mask = WLS_H3_CID_TABLE_CAPACITY - 1;
  size_t hole = index;
  wls_cid_retire_route(server, server->cid_slots[hole].cid,
                       server->cid_slots[hole].cid_length);
  memset(&server->cid_slots[hole], 0, sizeof(server->cid_slots[hole]));
  size_t scan = (hole + 1) & mask;
  while (server->cid_slots[scan].state != 0) {
    wls_h3_cid_slot *candidate = &server->cid_slots[scan];
    if (candidate->state == 1) {
      size_t home = (size_t)candidate->hash & mask;
      size_t hole_distance = (hole - home) & mask;
      size_t scan_distance = (scan - home) & mask;
      if (hole_distance < scan_distance) {
        server->cid_slots[hole] = *candidate;
        memset(candidate, 0, sizeof(*candidate));
        hole = scan;
      }
    }
    scan = (scan + 1) & mask;
  }
}

static void wls_cid_remove(wls_h3_server_impl *server, const uint8_t *cid,
                           size_t cid_length) {
  uint64_t hash = wls_hash_bytes(cid, cid_length);
  size_t index = (size_t)hash & (WLS_H3_CID_TABLE_CAPACITY - 1);
  for (size_t probe = 0; probe < WLS_H3_CID_TABLE_CAPACITY; ++probe) {
    wls_h3_cid_slot *slot = &server->cid_slots[index];
    if (slot->state == 0) {
      return;
    }
    if (slot->state == 1 && slot->hash == hash &&
        slot->cid_length == cid_length &&
        memcmp(slot->cid, cid, cid_length) == 0) {
      wls_cid_delete_at(server, index);
      return;
    }
    index = (index + 1) & (WLS_H3_CID_TABLE_CAPACITY - 1);
  }
}

static int wls_token_insert(wls_h3_server_impl *server, uint64_t token,
                            wls_h3_stream *stream) {
  size_t index = (size_t)token & (WLS_H3_TOKEN_TABLE_CAPACITY - 1);
  size_t tombstone = SIZE_MAX;
  for (size_t probe = 0; probe < WLS_H3_TOKEN_TABLE_CAPACITY; ++probe) {
    wls_h3_token_slot *slot = &server->token_slots[index];
    if (slot->state == 2 && tombstone == SIZE_MAX) {
      tombstone = index;
    } else if (slot->state == 0) {
      if (tombstone != SIZE_MAX) {
        slot = &server->token_slots[tombstone];
      }
      slot->state = 1;
      slot->token = token;
      slot->stream = stream;
      return WLS_TRANSPORT_OK;
    } else if (slot->state == 1 && slot->token == token) {
      slot->stream = stream;
      return WLS_TRANSPORT_OK;
    }
    index = (index + 1) & (WLS_H3_TOKEN_TABLE_CAPACITY - 1);
  }
  if (tombstone != SIZE_MAX) {
    wls_h3_token_slot *slot = &server->token_slots[tombstone];
    slot->state = 1;
    slot->token = token;
    slot->stream = stream;
    return WLS_TRANSPORT_OK;
  }
  return WLS_TRANSPORT_NOMEM;
}

static void wls_token_delete_at(wls_h3_server_impl *server, size_t index) {
  const size_t mask = WLS_H3_TOKEN_TABLE_CAPACITY - 1;
  size_t hole = index;
  memset(&server->token_slots[hole], 0,
         sizeof(server->token_slots[hole]));
  size_t scan = (hole + 1) & mask;
  while (server->token_slots[scan].state != 0) {
    wls_h3_token_slot *candidate = &server->token_slots[scan];
    if (candidate->state == 1) {
      size_t home = (size_t)candidate->token & mask;
      size_t hole_distance = (hole - home) & mask;
      size_t scan_distance = (scan - home) & mask;
      if (hole_distance < scan_distance) {
        server->token_slots[hole] = *candidate;
        memset(candidate, 0, sizeof(*candidate));
        hole = scan;
      }
    }
    scan = (scan + 1) & mask;
  }
}

static wls_h3_stream *wls_token_lookup(wls_h3_server_impl *server,
                                       uint64_t token) {
  size_t index = (size_t)token & (WLS_H3_TOKEN_TABLE_CAPACITY - 1);
  for (size_t probe = 0; probe < WLS_H3_TOKEN_TABLE_CAPACITY; ++probe) {
    wls_h3_token_slot *slot = &server->token_slots[index];
    if (slot->state == 0) {
      return NULL;
    }
    if (slot->state == 1 && slot->token == token) {
      return slot->stream;
    }
    index = (index + 1) & (WLS_H3_TOKEN_TABLE_CAPACITY - 1);
  }
  return NULL;
}

static void wls_token_remove(wls_h3_server_impl *server, uint64_t token) {
  size_t index = (size_t)token & (WLS_H3_TOKEN_TABLE_CAPACITY - 1);
  for (size_t probe = 0; probe < WLS_H3_TOKEN_TABLE_CAPACITY; ++probe) {
    wls_h3_token_slot *slot = &server->token_slots[index];
    if (slot->state == 0) {
      return;
    }
    if (slot->state == 1 && slot->token == token) {
      wls_token_delete_at(server, index);
      return;
    }
    index = (index + 1) & (WLS_H3_TOKEN_TABLE_CAPACITY - 1);
  }
}

static char *wls_duplicate_bytes(const uint8_t *data, size_t length) {
  char *value = malloc(length + 1);
  if (!value) {
    return NULL;
  }
  if (length != 0) {
    memcpy(value, data, length);
  }
  value[length] = 0;
  return value;
}

static int wls_append_bytes(uint8_t **buffer, size_t *length,
                            size_t *capacity, const uint8_t *data,
                            size_t data_length, size_t limit) {
  if (data_length > limit || *length > limit - data_length) {
    return WLS_TRANSPORT_BUFFER_TOO_SMALL;
  }
  size_t required = *length + data_length;
  if (required > *capacity) {
    size_t next = *capacity == 0 ? 1024 : *capacity;
    while (next < required && next < limit) {
      next *= 2;
    }
    if (next > limit) {
      next = limit;
    }
    uint8_t *resized = realloc(*buffer, next);
    if (!resized) {
      return WLS_TRANSPORT_NOMEM;
    }
    *buffer = resized;
    *capacity = next;
  }
  if (data_length != 0) {
    memcpy(*buffer + *length, data, data_length);
  }
  *length = required;
  return WLS_TRANSPORT_OK;
}

static wls_h3_stream *wls_stream_find(wls_h3_connection *connection,
                                      int64_t stream_id) {
  for (wls_h3_stream *stream = connection->streams; stream;
       stream = stream->next) {
    if (stream->stream_id == stream_id) {
      return stream;
    }
  }
  return NULL;
}

static wls_h3_stream *wls_stream_create(wls_h3_connection *connection,
                                        int64_t stream_id) {
  if (connection->server->active_streams >=
      connection->server->max_active_streams) {
    wls_set_error("HTTP/3 active stream capacity reached");
    return NULL;
  }
  wls_h3_stream *stream = calloc(1, sizeof(*stream));
  if (!stream) {
    return NULL;
  }
  stream->connection = connection;
  stream->stream_id = stream_id;
  stream->next = connection->streams;
  connection->streams = stream;
  ++connection->server->active_streams;
  return stream;
}

static void wls_stream_free(wls_h3_stream *stream) {
  if (!stream) {
    return;
  }
  free(stream->method);
  free(stream->path);
  free(stream->authority);
  free(stream->scheme);
  for (size_t index = 0; index < stream->header_count; ++index) {
    free(stream->headers[index].name);
    free(stream->headers[index].value);
  }
  free(stream->headers);
  free(stream->request_body);
  free(stream->raw_request);
  free(stream->response_body);
  free(stream);
}

static int wls_stream_add_header(wls_h3_stream *stream,
                                 const uint8_t *name, size_t name_length,
                                 const uint8_t *value, size_t value_length) {
  if (name_length == 0 || name[0] == 58) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  for (size_t index = 0; index < name_length; ++index) {
    uint8_t byte = name[index];
    if (byte <= 32 || byte >= 127 || byte == 58) {
      return WLS_TRANSPORT_INVALID_ARGUMENT;
    }
  }
  for (size_t index = 0; index < value_length; ++index) {
    uint8_t byte = value[index];
    if (byte == 0 || byte == 10 || byte == 13 ||
        (byte < 32 && byte != 9) || byte == 127) {
      return WLS_TRANSPORT_INVALID_ARGUMENT;
    }
  }
  wls_h3_server_impl *server = stream->connection->server;
  if (name_length + value_length > server->max_request_header_bytes ||
      stream->header_bytes > server->max_request_header_bytes -
                             name_length - value_length) {
    return WLS_TRANSPORT_BUFFER_TOO_SMALL;
  }
  if (stream->header_count == stream->header_capacity) {
    size_t capacity = stream->header_capacity == 0 ? 16 :
                      stream->header_capacity * 2;
    wls_h3_header *headers = realloc(stream->headers,
                                     capacity * sizeof(*headers));
    if (!headers) {
      return WLS_TRANSPORT_NOMEM;
    }
    stream->headers = headers;
    stream->header_capacity = capacity;
  }
  char *name_copy = wls_duplicate_bytes(name, name_length);
  char *value_copy = wls_duplicate_bytes(value, value_length);
  if (!name_copy || !value_copy) {
    free(name_copy);
    free(value_copy);
    return WLS_TRANSPORT_NOMEM;
  }
  stream->headers[stream->header_count].name = name_copy;
  stream->headers[stream->header_count].value = value_copy;
  ++stream->header_count;
  stream->header_bytes += name_length + value_length;
  return WLS_TRANSPORT_OK;
}

static int wls_stream_build_raw_request(wls_h3_stream *stream) {
  if (stream->raw_request) {
    return WLS_TRANSPORT_OK;
  }
  if (!stream->method || !stream->path || !stream->authority ||
      !stream->scheme || strcasecmp(stream->scheme, "https") != 0 ||
      stream->path[0] != 47) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  uint64_t declared_content_length = 0;
  int has_content_length = 0;
  for (size_t index = 0; index < stream->header_count; ++index) {
    const char *name = stream->headers[index].name;
    const char *value = stream->headers[index].value;
    if (strcasecmp(name, "transfer-encoding") == 0) {
      return WLS_TRANSPORT_INVALID_ARGUMENT;
    }
    if (strcasecmp(name, "content-length") != 0) {
      continue;
    }
    if (has_content_length || value[0] == 0) {
      return WLS_TRANSPORT_INVALID_ARGUMENT;
    }
    uint64_t parsed = 0;
    for (const unsigned char *cursor = (const unsigned char *)value;
         *cursor; ++cursor) {
      if (*cursor < 48 || *cursor > 57 ||
          parsed > (UINT64_MAX - (uint64_t)(*cursor - 48)) / 10) {
        return WLS_TRANSPORT_INVALID_ARGUMENT;
      }
      parsed = parsed * 10 + (uint64_t)(*cursor - 48);
    }
    declared_content_length = parsed;
    has_content_length = 1;
  }
  if (has_content_length &&
      declared_content_length != stream->request_body_length) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  char synthesized_length[32] = {0};
  int synthesize_content_length =
    stream->request_body_length != 0 && !has_content_length;
  if (synthesize_content_length) {
    snprintf(synthesized_length, sizeof(synthesized_length), "%zu",
             stream->request_body_length);
  }

  size_t total = strlen(stream->method) + 1 + strlen(stream->path) + 11 +
                 6 + strlen(stream->authority) + 2 + 2 +
                 stream->request_body_length;
  for (size_t index = 0; index < stream->header_count; ++index) {
    if (stream->headers[index].name[0] == 58) {
      continue;
    }
    total += strlen(stream->headers[index].name) + 2 +
             strlen(stream->headers[index].value) + 2;
  }
  if (synthesize_content_length) {
    total += 16 + strlen(synthesized_length) + 2;
  }
  if (total > stream->connection->server->max_request_header_bytes +
              stream->connection->server->max_request_body_bytes) {
    return WLS_TRANSPORT_BUFFER_TOO_SMALL;
  }
  uint8_t *raw = malloc(total + 1);
  if (!raw) {
    return WLS_TRANSPORT_NOMEM;
  }
  size_t offset = (size_t)snprintf((char *)raw, total + 1,
                                   "%s %s HTTP/1.1\r\nHost: %s\r\n",
                                   stream->method, stream->path,
                                   stream->authority);
  for (size_t index = 0; index < stream->header_count; ++index) {
    const char *name = stream->headers[index].name;
    if (name[0] == 58 || strcasecmp(name, "host") == 0) {
      continue;
    }
    offset += (size_t)snprintf((char *)raw + offset, total + 1 - offset,
                               "%s: %s\r\n", name,
                               stream->headers[index].value);
  }
  if (synthesize_content_length) {
    offset += (size_t)snprintf((char *)raw + offset, total + 1 - offset,
                               "Content-Length: %s\r\n",
                               synthesized_length);
  }
  memcpy(raw + offset, "\r\n", 2);
  offset += 2;
  if (stream->request_body_length != 0) {
    memcpy(raw + offset, stream->request_body, stream->request_body_length);
    offset += stream->request_body_length;
  }
  raw[offset] = 0;
  stream->raw_request = raw;
  stream->raw_request_length = offset;
  return WLS_TRANSPORT_OK;
}

static int wls_queue_request(wls_h3_stream *stream) {
  if (!stream || stream->queued || stream->response_submitted) {
    return WLS_TRANSPORT_OK;
  }
  int result = wls_stream_build_raw_request(stream);
  if (result != WLS_TRANSPORT_OK) {
    return result;
  }
  wls_h3_server_impl *server = stream->connection->server;
  do {
    stream->token = ++server->next_request_token;
  } while (stream->token == 0 || wls_token_lookup(server, stream->token));
  result = wls_token_insert(server, stream->token, stream);
  if (result != WLS_TRANSPORT_OK) {
    return result;
  }
  stream->queued = 1;
  stream->queue_next = NULL;
  if (server->request_tail) {
    server->request_tail->queue_next = stream;
  } else {
    server->request_head = stream;
  }
  server->request_tail = stream;
  ++server->queued_requests;

  wls_h3_connection *connection = stream->connection;
  ++connection->request_count;
  if (connection->request_count > server->max_connection_request_count) {
    server->max_connection_request_count = connection->request_count;
  }
  if (!server->draining && !connection->rotation_requested &&
      connection->request_count >= connection->request_limit) {
    connection->rotation_requested = 1;
    ++server->connection_rotation_requests;
  }
  return WLS_TRANSPORT_OK;
}


static int wls_connection_flush(wls_h3_connection *connection);
static int wls_connection_handoff_terminal_close(
  wls_h3_connection *connection);
static int wls_connection_resend_terminal_close(
  wls_h3_connection *connection, ngtcp2_tstamp now);
static int wls_connection_send_drain_close(
  wls_h3_connection *connection);
static int wls_server_process_datagram(
  wls_h3_server_impl *server, wls_h3_io_path *io_path,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, const uint8_t *datagram,
  size_t datagram_length, uint64_t authorization_id);

static ngtcp2_conn *wls_crypto_get_connection(
  ngtcp2_crypto_conn_ref *connection_ref) {
  wls_h3_connection *connection = connection_ref->user_data;
  return connection->quic;
}

static void wls_random_bytes(uint8_t *destination, size_t length,
                             const ngtcp2_rand_ctx *random_context) {
  (void)random_context;
  if (RAND_bytes(destination, (int)length) != 1) {
    abort();
  }
}

static void wls_http3_random_bytes(uint8_t *destination, size_t length) {
  if (RAND_bytes(destination, (int)length) != 1) {
    abort();
  }
}

static int wls_connection_handshake_completed(ngtcp2_conn *quic,
                                               void *user_data) {
  (void)quic;
  wls_h3_connection *connection = user_data;
  const unsigned char *selected = NULL;
  unsigned int selected_length = 0;
  SSL_get0_alpn_selected(connection->ssl, &selected, &selected_length);
  if (SSL_version(connection->ssl) != TLS1_3_VERSION ||
      selected_length != 2 || memcmp(selected, "h3", 2) != 0) {
    wls_set_error("QUIC handshake did not negotiate TLS1.3+h3");
    return NGTCP2_ERR_CALLBACK_FAILURE;
  }
  int session_reused = SSL_session_reused(connection->ssl);
  wls_tls_context *tls_context = connection->server->tls_context;
  atomic_fetch_add_explicit(&tls_context->handshakes_completed, 1,
                            memory_order_relaxed);
  if (session_reused) {
    atomic_fetch_add_explicit(&tls_context->resumed_handshakes, 1,
                              memory_order_relaxed);
  } else {
    atomic_fetch_add_explicit(&tls_context->full_handshakes, 1,
                              memory_order_relaxed);
  }
  connection->handshake_complete = 1;
  return 0;
}

static int wls_http3_acked_data(nghttp3_conn *http3, int64_t stream_id,
                                uint64_t data_length, void *user_data,
                                void *stream_user_data) {
  (void)http3;
  (void)stream_id;
  (void)data_length;
  (void)user_data;
  (void)stream_user_data;
  return 0;
}

static int wls_http3_stream_close(nghttp3_conn *http3, int64_t stream_id,
                                  uint64_t app_error_code, void *user_data,
                                  void *stream_user_data) {
  (void)http3;
  (void)stream_id;
  (void)app_error_code;
  (void)user_data;
  (void)stream_user_data;
  return 0;
}

static int wls_http3_recv_data(nghttp3_conn *http3, int64_t stream_id,
                               const uint8_t *data, size_t data_length,
                               void *user_data, void *stream_user_data) {
  (void)http3;
  wls_h3_connection *connection = user_data;
  wls_h3_stream *stream = stream_user_data;
  if (!stream) {
    stream = wls_stream_find(connection, stream_id);
  }
  if (!stream) {
    return NGHTTP3_ERR_CALLBACK_FAILURE;
  }
  int result = wls_append_bytes(&stream->request_body,
                                &stream->request_body_length,
                                &stream->request_body_capacity,
                                data, data_length,
                                connection->server->max_request_body_bytes);
  if (result != WLS_TRANSPORT_OK) {
    return NGHTTP3_ERR_CALLBACK_FAILURE;
  }
  ngtcp2_conn_extend_max_stream_offset(connection->quic, stream_id,
                                        data_length);
  ngtcp2_conn_extend_max_offset(connection->quic, data_length);
  return 0;
}

static int wls_http3_deferred_consume(nghttp3_conn *http3, int64_t stream_id,
                                      size_t consumed, void *user_data,
                                      void *stream_user_data) {
  (void)http3;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  ngtcp2_conn_extend_max_stream_offset(connection->quic, stream_id, consumed);
  ngtcp2_conn_extend_max_offset(connection->quic, consumed);
  return 0;
}

static int wls_http3_begin_headers(nghttp3_conn *http3, int64_t stream_id,
                                   void *user_data, void *stream_user_data) {
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  wls_h3_stream *stream = wls_stream_find(connection, stream_id);
  if (!stream) {
    stream = wls_stream_create(connection, stream_id);
  }
  if (!stream) {
    return NGHTTP3_ERR_CALLBACK_FAILURE;
  }
  nghttp3_conn_set_stream_user_data(http3, stream_id, stream);
  return 0;
}

static int wls_http3_recv_header(nghttp3_conn *http3, int64_t stream_id,
                                 int32_t token, nghttp3_rcbuf *name_buffer,
                                 nghttp3_rcbuf *value_buffer, uint8_t flags,
                                 void *user_data, void *stream_user_data) {
  (void)http3;
  (void)stream_id;
  (void)token;
  (void)flags;
  (void)user_data;
  wls_h3_stream *stream = stream_user_data;
  if (!stream) {
    return NGHTTP3_ERR_CALLBACK_FAILURE;
  }
  nghttp3_vec name = nghttp3_rcbuf_get_buf(name_buffer);
  nghttp3_vec value = nghttp3_rcbuf_get_buf(value_buffer);
  char **target = NULL;
  if (name.len == 7 && memcmp(name.base, ":method", 7) == 0) {
    target = &stream->method;
  } else if (name.len == 5 && memcmp(name.base, ":path", 5) == 0) {
    target = &stream->path;
  } else if (name.len == 10 && memcmp(name.base, ":authority", 10) == 0) {
    target = &stream->authority;
  } else if (name.len == 7 && memcmp(name.base, ":scheme", 7) == 0) {
    target = &stream->scheme;
  }
  if (target) {
    if (*target) {
      return NGHTTP3_ERR_CALLBACK_FAILURE;
    }
    *target = wls_duplicate_bytes(value.base, value.len);
    return *target ? 0 : NGHTTP3_ERR_CALLBACK_FAILURE;
  }
  return wls_stream_add_header(stream, name.base, name.len,
                               value.base, value.len) == WLS_TRANSPORT_OK
           ? 0 : NGHTTP3_ERR_CALLBACK_FAILURE;
}

static int wls_http3_end_headers(nghttp3_conn *http3, int64_t stream_id,
                                 int fin, void *user_data,
                                 void *stream_user_data) {
  (void)http3;
  (void)stream_id;
  (void)user_data;
  wls_h3_stream *stream = stream_user_data;
  if (fin && wls_queue_request(stream) != WLS_TRANSPORT_OK) {
    return NGHTTP3_ERR_CALLBACK_FAILURE;
  }
  return 0;
}

static int wls_http3_end_stream(nghttp3_conn *http3, int64_t stream_id,
                                void *user_data, void *stream_user_data) {
  (void)http3;
  (void)stream_id;
  (void)user_data;
  return wls_queue_request(stream_user_data) == WLS_TRANSPORT_OK
           ? 0 : NGHTTP3_ERR_CALLBACK_FAILURE;
}

static int wls_http3_stop_sending(nghttp3_conn *http3, int64_t stream_id,
                                  uint64_t app_error_code, void *user_data,
                                  void *stream_user_data) {
  (void)http3;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  return ngtcp2_conn_shutdown_stream_read(connection->quic, 0, stream_id,
                                           app_error_code) == 0
           ? 0 : NGHTTP3_ERR_CALLBACK_FAILURE;
}

static int wls_http3_reset_stream(nghttp3_conn *http3, int64_t stream_id,
                                  uint64_t app_error_code, void *user_data,
                                  void *stream_user_data) {
  (void)http3;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  return ngtcp2_conn_shutdown_stream_write(connection->quic, 0, stream_id,
                                            app_error_code) == 0
           ? 0 : NGHTTP3_ERR_CALLBACK_FAILURE;
}

static nghttp3_ssize wls_http3_read_response(
  nghttp3_conn *http3, int64_t stream_id, nghttp3_vec *vectors,
  size_t vector_count, uint32_t *flags, void *user_data,
  void *stream_user_data) {
  (void)http3;
  (void)stream_id;
  (void)vector_count;
  (void)user_data;
  if (vector_count == 0) {
    return 0;
  }
  wls_h3_stream *stream = stream_user_data;
  vectors[0].base = stream->response_body;
  vectors[0].len = stream->response_body_length;
  *flags |= NGHTTP3_DATA_FLAG_EOF;
  return 1;
}

static int wls_http3_submit_shutdown_notice(
  wls_h3_connection *connection) {
  if (!connection || !connection->http3 ||
      connection->shutdown_notice_sent) {
    return WLS_TRANSPORT_OK;
  }
  int result = nghttp3_conn_submit_shutdown_notice(connection->http3);
  if (result != 0) {
    wls_set_error("nghttp3_conn_submit_shutdown_notice: %s",
                  nghttp3_strerror(result));
    return WLS_TRANSPORT_HTTP3_ERROR;
  }
  connection->shutdown_notice_sent = 1;
  connection->shutdown_notice_sent_at = wls_now();
  if (connection->rotation_requested) {
    ++connection->server->connection_rotation_goaways;
  }
  return WLS_TRANSPORT_OK;
}

static ngtcp2_duration wls_http3_shutdown_notice_delay(
  const wls_h3_connection *connection) {
  if (!connection || !connection->quic) {
    return WLS_H3_DRAIN_NOTICE_DEFAULT_DELAY;
  }
  ngtcp2_conn_info connection_info;
  memset(&connection_info, 0, sizeof(connection_info));
  ngtcp2_conn_get_conn_info2(connection->quic, &connection_info);
  ngtcp2_duration delay = connection_info.smoothed_rtt != 0
                            ? connection_info.smoothed_rtt * 2
                            : WLS_H3_DRAIN_NOTICE_DEFAULT_DELAY;
  if (delay < WLS_H3_DRAIN_NOTICE_MIN_DELAY) {
    return WLS_H3_DRAIN_NOTICE_MIN_DELAY;
  }
  if (delay > WLS_H3_DRAIN_NOTICE_MAX_DELAY) {
    return WLS_H3_DRAIN_NOTICE_MAX_DELAY;
  }
  return delay;
}

static int wls_http3_initialize(wls_h3_connection *connection) {
  if (connection->http3_ready) {
    return WLS_TRANSPORT_OK;
  }
  nghttp3_callbacks callbacks;
  memset(&callbacks, 0, sizeof(callbacks));
  callbacks.acked_stream_data = wls_http3_acked_data;
  callbacks.stream_close = wls_http3_stream_close;
  callbacks.recv_data = wls_http3_recv_data;
  callbacks.deferred_consume = wls_http3_deferred_consume;
  callbacks.begin_headers = wls_http3_begin_headers;
  callbacks.recv_header = wls_http3_recv_header;
  callbacks.end_headers = wls_http3_end_headers;
  callbacks.stop_sending = wls_http3_stop_sending;
  callbacks.end_stream = wls_http3_end_stream;
  callbacks.reset_stream = wls_http3_reset_stream;
  callbacks.rand = wls_http3_random_bytes;

  nghttp3_settings settings;
  nghttp3_settings_default(&settings);
  settings.max_field_section_size =
    connection->server->max_request_header_bytes;
  int result = nghttp3_conn_server_new(&connection->http3, &callbacks,
                                       &settings, NULL, connection);
  if (result != 0) {
    wls_set_error("nghttp3_conn_server_new: %s", nghttp3_strerror(result));
    return WLS_TRANSPORT_HTTP3_ERROR;
  }

  int64_t control_stream = -1;
  int64_t qpack_encoder_stream = -1;
  int64_t qpack_decoder_stream = -1;
  if (ngtcp2_conn_open_uni_stream(connection->quic, &control_stream, NULL) != 0 ||
      ngtcp2_conn_open_uni_stream(connection->quic, &qpack_encoder_stream,
                                  NULL) != 0 ||
      ngtcp2_conn_open_uni_stream(connection->quic, &qpack_decoder_stream,
                                  NULL) != 0 ||
      nghttp3_conn_bind_control_stream(connection->http3, control_stream) != 0 ||
      nghttp3_conn_bind_qpack_streams(connection->http3,
                                      qpack_encoder_stream,
                                      qpack_decoder_stream) != 0) {
    wls_set_error("unable to bind HTTP/3 control/QPACK streams");
    return WLS_TRANSPORT_HTTP3_ERROR;
  }
  nghttp3_conn_set_max_client_streams_bidi(
    connection->http3, connection->server->initial_max_streams_bidi);
  connection->http3_ready = 1;
  if (connection->server->draining &&
      wls_http3_submit_shutdown_notice(connection) != WLS_TRANSPORT_OK) {
    return WLS_TRANSPORT_HTTP3_ERROR;
  }
  return WLS_TRANSPORT_OK;
}

static int wls_quic_recv_stream_data(ngtcp2_conn *quic, uint32_t flags,
                                     int64_t stream_id, uint64_t offset,
                                     const uint8_t *data, size_t data_length,
                                     void *user_data,
                                     void *stream_user_data) {
  (void)quic;
  (void)offset;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  if (!connection->http3) {
    return 0;
  }
  nghttp3_ssize consumed = nghttp3_conn_read_stream2(
    connection->http3, stream_id, data, data_length,
    (flags & NGTCP2_STREAM_DATA_FLAG_FIN) != 0,
    ngtcp2_conn_get_timestamp(connection->quic));
  if (consumed < 0) {
    wls_set_error("nghttp3_conn_read_stream2: %s",
                  nghttp3_strerror((int)consumed));
    return NGTCP2_ERR_CALLBACK_FAILURE;
  }
  ngtcp2_conn_extend_max_stream_offset(connection->quic, stream_id,
                                        (uint64_t)consumed);
  ngtcp2_conn_extend_max_offset(connection->quic, (uint64_t)consumed);
  return 0;
}

static int wls_quic_acked_stream_data(ngtcp2_conn *quic, int64_t stream_id,
                                      uint64_t offset, uint64_t data_length,
                                      void *user_data,
                                      void *stream_user_data) {
  (void)quic;
  (void)offset;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  if (!connection->http3) {
    return 0;
  }
  int result = nghttp3_conn_add_ack_offset(connection->http3, stream_id,
                                            data_length);
  return result == 0 || result == NGHTTP3_ERR_STREAM_NOT_FOUND
           ? 0 : NGTCP2_ERR_CALLBACK_FAILURE;
}

static int wls_quic_stream_open(ngtcp2_conn *quic, int64_t stream_id,
                                void *user_data) {
  wls_h3_connection *connection = user_data;
  if (ngtcp2_is_bidi_stream(stream_id)) {
    wls_h3_stream *stream = wls_stream_find(connection, stream_id);
    if (!stream) {
      stream = wls_stream_create(connection, stream_id);
    }
    if (!stream ||
        ngtcp2_conn_set_stream_user_data(quic, stream_id, stream) != 0) {
      return NGTCP2_ERR_CALLBACK_FAILURE;
    }
  }
  return 0;
}

static void wls_queue_remove_stream(wls_h3_server_impl *server,
                                    wls_h3_stream *stream) {
  wls_h3_stream *previous = NULL;
  for (wls_h3_stream *current = server->request_head; current;
       current = current->queue_next) {
    if (current == stream) {
      if (previous) {
        previous->queue_next = current->queue_next;
      } else {
        server->request_head = current->queue_next;
      }
      if (server->request_tail == current) {
        server->request_tail = previous;
      }
      if (server->queued_requests != 0) {
        --server->queued_requests;
      }
      return;
    }
    previous = current;
  }
}

static int wls_quic_stream_close(ngtcp2_conn *quic, uint32_t flags,
                                 int64_t stream_id, uint64_t app_error_code,
                                 void *user_data, void *stream_user_data) {
  (void)flags;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  if (connection->http3) {
    int result = nghttp3_conn_close_stream(connection->http3, stream_id,
                                            app_error_code == 0
                                              ? NGHTTP3_H3_NO_ERROR
                                              : app_error_code);
    if (result != 0 && result != NGHTTP3_ERR_STREAM_NOT_FOUND) {
      return NGTCP2_ERR_CALLBACK_FAILURE;
    }
  }
  if (ngtcp2_is_bidi_stream(stream_id) &&
      !ngtcp2_conn_is_local_stream2(quic, stream_id)) {
    /* MAX_STREAMS is connection-lifetime credit.  Return one credit for every
     * closed peer bidirectional stream even when nghttp3 or local bookkeeping
     * already retired the stream object; otherwise long-lived multiplexed
     * connections eventually stop opening request streams. */
    ngtcp2_conn_extend_max_streams_bidi(quic, 1);
  }
  wls_h3_stream **cursor = &connection->streams;
  while (*cursor) {
    if ((*cursor)->stream_id == stream_id) {
      wls_h3_stream *stream = *cursor;
      *cursor = stream->next;
      if (stream->queued) {
        wls_queue_remove_stream(connection->server, stream);
      }
      if (stream->token) {
        wls_token_remove(connection->server, stream->token);
      }
      if (connection->server->active_streams != 0) {
        --connection->server->active_streams;
      }
      wls_stream_free(stream);
      break;
    }
    cursor = &(*cursor)->next;
  }
  return 0;
}

static int wls_quic_stream_reset(ngtcp2_conn *quic, int64_t stream_id,
                                 uint64_t final_size,
                                 uint64_t app_error_code, void *user_data,
                                 void *stream_user_data) {
  (void)quic;
  (void)final_size;
  (void)app_error_code;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  if (!connection->http3) {
    return 0;
  }
  return nghttp3_conn_shutdown_stream_read(connection->http3, stream_id) == 0
           ? 0 : NGTCP2_ERR_CALLBACK_FAILURE;
}

static int wls_quic_stream_stop_sending(ngtcp2_conn *quic, int64_t stream_id,
                                        uint64_t app_error_code,
                                        void *user_data,
                                        void *stream_user_data) {
  (void)quic;
  (void)app_error_code;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  if (!connection->http3) {
    return 0;
  }
  nghttp3_conn_shutdown_stream_write(connection->http3, stream_id);
  return 0;
}

static int wls_quic_extend_remote_streams(ngtcp2_conn *quic,
                                          uint64_t max_streams,
                                          void *user_data) {
  (void)quic;
  wls_h3_connection *connection = user_data;
  if (connection->http3) {
    nghttp3_conn_set_max_client_streams_bidi(connection->http3, max_streams);
  }
  return 0;
}

static int wls_quic_extend_stream_data(ngtcp2_conn *quic, int64_t stream_id,
                                       uint64_t max_data, void *user_data,
                                       void *stream_user_data) {
  (void)quic;
  (void)max_data;
  (void)stream_user_data;
  wls_h3_connection *connection = user_data;
  if (!connection->http3) {
    return 0;
  }
  int result = nghttp3_conn_unblock_stream(connection->http3, stream_id);
  return result == 0 || result == NGHTTP3_ERR_STREAM_NOT_FOUND
           ? 0 : NGTCP2_ERR_CALLBACK_FAILURE;
}

static int wls_quic_recv_tx_key(ngtcp2_conn *quic,
                                ngtcp2_encryption_level level,
                                void *user_data) {
  (void)quic;
  if (level != NGTCP2_ENCRYPTION_LEVEL_1RTT) {
    return 0;
  }
  return wls_http3_initialize(user_data) == WLS_TRANSPORT_OK
           ? 0 : NGTCP2_ERR_CALLBACK_FAILURE;
}

static int wls_quic_new_connection_id(
  ngtcp2_conn *quic, ngtcp2_cid *cid,
  ngtcp2_stateless_reset_token *token, size_t cid_length, void *user_data) {
  (void)quic;
  wls_h3_connection *connection = user_data;
  int cid_result;
  if (connection->server->datagram_worker_mode) {
    if (cid_length != WLS_H3_SERVER_SCID_LENGTH) {
      return NGTCP2_ERR_CALLBACK_FAILURE;
    }
    cid_result = wls_route_cid_generate(
      connection->server->channel_key,
      connection->server->route_id,
      cid);
  } else {
    cid_result = RAND_bytes(cid->data, (int)cid_length) == 1
                   ? WLS_TRANSPORT_OK
                   : WLS_TRANSPORT_TLS_ERROR;
    cid->datalen = cid_length;
  }
  if (cid_result != WLS_TRANSPORT_OK ||
      RAND_bytes(token->data, NGTCP2_STATELESS_RESET_TOKENLEN) != 1) {
    return NGTCP2_ERR_CALLBACK_FAILURE;
  }
  return wls_cid_insert(connection->server, cid->data, cid->datalen,
                        connection) == WLS_TRANSPORT_OK
           ? 0 : NGTCP2_ERR_CALLBACK_FAILURE;
}

static int wls_quic_remove_connection_id(ngtcp2_conn *quic,
                                         const ngtcp2_cid *cid,
                                         void *user_data) {
  (void)quic;
  wls_h3_connection *connection = user_data;
  wls_cid_remove(connection->server, cid->data, cid->datalen);
  return 0;
}


static ngtcp2_callbacks wls_quic_callbacks(void) {
  ngtcp2_callbacks callbacks;
  memset(&callbacks, 0, sizeof(callbacks));
  callbacks.recv_client_initial = ngtcp2_crypto_recv_client_initial_cb;
  callbacks.recv_crypto_data = ngtcp2_crypto_recv_crypto_data_cb;
  callbacks.handshake_completed = wls_connection_handshake_completed;
  callbacks.encrypt = ngtcp2_crypto_encrypt_cb;
  callbacks.decrypt = ngtcp2_crypto_decrypt_cb;
  callbacks.hp_mask = ngtcp2_crypto_hp_mask_cb;
  callbacks.recv_stream_data = wls_quic_recv_stream_data;
  callbacks.acked_stream_data_offset = wls_quic_acked_stream_data;
  callbacks.stream_open = wls_quic_stream_open;
  callbacks.stream_close = wls_quic_stream_close;
  callbacks.rand = wls_random_bytes;
  callbacks.remove_connection_id = wls_quic_remove_connection_id;
  callbacks.update_key = ngtcp2_crypto_update_key_cb;
  callbacks.stream_reset = wls_quic_stream_reset;
  callbacks.extend_max_remote_streams_bidi = wls_quic_extend_remote_streams;
  callbacks.extend_max_stream_data = wls_quic_extend_stream_data;
  callbacks.delete_crypto_aead_ctx =
    ngtcp2_crypto_delete_crypto_aead_ctx_cb;
  callbacks.delete_crypto_cipher_ctx =
    ngtcp2_crypto_delete_crypto_cipher_ctx_cb;
  callbacks.stream_stop_sending = wls_quic_stream_stop_sending;
  callbacks.version_negotiation = ngtcp2_crypto_version_negotiation_cb;
  callbacks.recv_tx_key = wls_quic_recv_tx_key;
  callbacks.get_new_connection_id2 = wls_quic_new_connection_id;
  callbacks.get_path_challenge_data2 =
    ngtcp2_crypto_get_path_challenge_data2_cb;
  return callbacks;
}

static void wls_connection_destroy(wls_h3_connection *connection) {
  if (!connection) {
    return;
  }
  (void)wls_worker_retire_authorization(connection);
  wls_h3_server_impl *server = connection->server;
  for (size_t index = 0; index < WLS_H3_CID_TABLE_CAPACITY;) {
    if (server->cid_slots[index].state == 1 &&
        server->cid_slots[index].connection == connection) {
      wls_cid_delete_at(server, index);
      continue;
    }
    ++index;
  }
  wls_h3_stream *stream = connection->streams;
  while (stream) {
    wls_h3_stream *next = stream->next;
    if (stream->queued) {
      wls_queue_remove_stream(server, stream);
    }
    if (stream->token) {
      wls_token_remove(server, stream->token);
    }
    wls_stream_free(stream);
    if (server->active_streams != 0) {
      --server->active_streams;
    }
    stream = next;
  }
  if (connection->ssl) {
    SSL_set_app_data(connection->ssl, NULL);
    SSL_free(connection->ssl);
    connection->ssl = NULL;
  }
  if (connection->crypto_context) {
    ngtcp2_crypto_ossl_ctx_del(connection->crypto_context);
  }
  if (connection->http3) {
    nghttp3_conn_del(connection->http3);
  }
  if (connection->quic) {
    ngtcp2_conn_del(connection->quic);
  }
  free(connection->pending_packet);
  free(connection->terminal_close_packet);
  if (connection->active_counted && server->active_connections != 0) {
    --server->active_connections;
  }
  connection->io_path = NULL;
  free(connection);
}

static int wls_send_retry(
  wls_h3_server_impl *server, const wls_h3_io_path *io_path,
  const ngtcp2_pkt_hd *client_header,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, size_t received_length) {
  ngtcp2_cid retry_scid;
  retry_scid.datalen = WLS_H3_SERVER_SCID_LENGTH;
  if (RAND_bytes(retry_scid.data, (int)retry_scid.datalen) != 1) {
    wls_set_ssl_error("RAND_bytes retry CID");
    return WLS_TRANSPORT_TLS_ERROR;
  }

  uint8_t token[NGTCP2_CRYPTO_MAX_RETRY_TOKENLEN2];
  ngtcp2_ssize token_length = ngtcp2_crypto_generate_retry_token2(
    token, server->retry_secret, sizeof(server->retry_secret),
    client_header->version, (const ngtcp2_sockaddr *)remote_address,
    remote_address_length, &retry_scid, &client_header->dcid, wls_now());
  if (token_length < 0) {
    wls_set_error("unable to generate QUIC Retry token");
    return WLS_TRANSPORT_QUIC_ERROR;
  }

  uint8_t packet[NGTCP2_MAX_UDP_PAYLOAD_SIZE];
  size_t amplification_limit = received_length > sizeof(packet) / 3
                                 ? sizeof(packet)
                                 : received_length * 3;
  ngtcp2_ssize packet_length = ngtcp2_crypto_write_retry(
    packet, amplification_limit, client_header->version,
    &client_header->scid, &retry_scid, &client_header->dcid,
    token, (size_t)token_length);
  if (packet_length < 0) {
    wls_set_error("unable to encode QUIC Retry packet");
    return WLS_TRANSPORT_QUIC_ERROR;
  }

  int send_result = wls_io_path_send_datagram(
    io_path, packet, (size_t)packet_length,
    (const struct sockaddr *)&io_path->local_address,
    io_path->local_address_length,
    (const struct sockaddr *)remote_address, remote_address_length,
    0,
    "send QUIC Retry");
  if (send_result != WLS_TRANSPORT_OK) {
    return send_result;
  }
  ++server->retry_sent;
  return WLS_TRANSPORT_OK;
}

static int wls_verify_retry(
  wls_h3_server_impl *server, const ngtcp2_pkt_hd *initial_header,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, ngtcp2_cid *original_dcid) {
  if (!initial_header->token || initial_header->tokenlen == 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  ngtcp2_duration lifetime =
    server->retry_token_lifetime_ms * NGTCP2_MILLISECONDS;
  int result = ngtcp2_crypto_verify_retry_token2(
    original_dcid, initial_header->token, initial_header->tokenlen,
    server->retry_secret, sizeof(server->retry_secret),
    initial_header->version, (const ngtcp2_sockaddr *)remote_address,
    remote_address_length, &initial_header->dcid, lifetime, wls_now());
  if (result != 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  ++server->retry_validated;
  return WLS_TRANSPORT_OK;
}

static wls_h3_connection *wls_connection_create(
  wls_h3_server_impl *server, const ngtcp2_pkt_hd *initial_header,
  const ngtcp2_cid *original_dcid, wls_h3_io_path *io_path,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, uint64_t authorization_id) {
  if (!io_path || io_path->fd < 0) {
    wls_set_error("unable to create QUIC connection without a UDP I/O path");
    return NULL;
  }
  wls_h3_connection *connection = calloc(1, sizeof(*connection));
  if (!connection) {
    wls_set_error("unable to allocate QUIC connection");
    return NULL;
  }
  connection->server = server;
  connection->io_path = io_path;
  connection->connection_id = ++server->next_connection_id;
  memcpy(&connection->local_address, local_address, local_address_length);
  connection->local_address_length = local_address_length;
  memcpy(&connection->remote_address, remote_address, remote_address_length);
  connection->remote_address_length = remote_address_length;
  connection->authorization_id = authorization_id;

  if (server->datagram_worker_mode) {
    if (wls_route_cid_generate(server->channel_key, server->route_id,
                               &connection->server_cid) !=
        WLS_TRANSPORT_OK) {
      wls_set_error("generate routed HTTP/3 server CID failed");
      wls_connection_destroy(connection);
      return NULL;
    }
  } else {
    connection->server_cid.datalen = WLS_H3_SERVER_SCID_LENGTH;
    if (RAND_bytes(connection->server_cid.data,
                   (int)connection->server_cid.datalen) != 1) {
      wls_set_ssl_error("RAND_bytes server CID");
      wls_connection_destroy(connection);
      return NULL;
    }
  }

  uint64_t request_jitter =
    wls_hash_bytes(connection->server_cid.data,
                   connection->server_cid.datalen) %
    (WLS_H3_REQUEST_ROTATION_JITTER * 2u + 1u);
  connection->request_limit =
    WLS_H3_DEFAULT_MAX_REQUESTS_PER_CONNECTION -
    WLS_H3_REQUEST_ROTATION_JITTER + request_jitter;

  ngtcp2_settings settings;
  ngtcp2_settings_default(&settings);
  settings.initial_ts = wls_now();
  settings.handshake_timeout = WLS_H3_HANDSHAKE_TIMEOUT;
  settings.max_tx_udp_payload_size = WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES;
  settings.token = initial_header->token;
  settings.tokenlen = initial_header->tokenlen;
  settings.token_type = NGTCP2_TOKEN_TYPE_RETRY;

  ngtcp2_transport_params parameters;
  ngtcp2_transport_params_default(&parameters);
  parameters.max_udp_payload_size = WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES;
  parameters.initial_max_stream_data_bidi_local =
    server->initial_max_stream_data;
  parameters.initial_max_stream_data_bidi_remote =
    server->initial_max_stream_data;
  parameters.initial_max_stream_data_uni = 1024 * 1024;
  parameters.initial_max_data = server->initial_max_data;
  parameters.initial_max_streams_bidi = server->initial_max_streams_bidi;
  parameters.initial_max_streams_uni = 3;
  parameters.max_idle_timeout = server->max_idle_timeout_ms *
                                NGTCP2_MILLISECONDS;
  parameters.active_connection_id_limit = 4;
  parameters.disable_active_migration =
    server->disable_active_migration ? 1 : 0;
  parameters.original_dcid = *original_dcid;
  parameters.original_dcid_present = 1;
  parameters.retry_scid = initial_header->dcid;
  parameters.retry_scid_present = 1;

  ngtcp2_path path;
  memset(&path, 0, sizeof(path));
  path.local.addr = (ngtcp2_sockaddr *)&connection->local_address;
  path.local.addrlen = connection->local_address_length;
  path.remote.addr = (ngtcp2_sockaddr *)&connection->remote_address;
  path.remote.addrlen = connection->remote_address_length;
  path.user_data = io_path;

  ngtcp2_callbacks callbacks = wls_quic_callbacks();
  int result = ngtcp2_conn_server_new(
    &connection->quic, &initial_header->scid, &connection->server_cid,
    &path, initial_header->version, &callbacks, &settings, &parameters,
    NULL, connection);
  if (result != 0) {
    wls_set_error("ngtcp2_conn_server_new: %s", ngtcp2_strerror(result));
    wls_connection_destroy(connection);
    return NULL;
  }

  if (ngtcp2_crypto_ossl_ctx_new(&connection->crypto_context, NULL) != 0) {
    wls_set_error("ngtcp2_crypto_ossl_ctx_new failed");
    wls_connection_destroy(connection);
    return NULL;
  }
  if (pthread_rwlock_rdlock(&server->tls_context->ticket_lock) != 0) {
    wls_set_error("unable to lock TLS context before SSL_new");
    wls_connection_destroy(connection);
    return NULL;
  }
  connection->ssl = SSL_new(server->tls_context->ssl_ctx);
  if (connection->ssl) {
    atomic_fetch_add_explicit(&server->tls_context->ssl_objects_created, 1,
                              memory_order_relaxed);
  }
  pthread_rwlock_unlock(&server->tls_context->ticket_lock);
  if (!connection->ssl ||
      SSL_set_min_proto_version(connection->ssl, TLS1_3_VERSION) != 1 ||
      SSL_set_max_proto_version(connection->ssl, TLS1_3_VERSION) != 1) {
    wls_set_ssl_error("SSL_new/QUIC TLS1.3 profile");
    wls_connection_destroy(connection);
    return NULL;
  }
  ngtcp2_crypto_ossl_ctx_set_ssl(connection->crypto_context, connection->ssl);
  if (ngtcp2_crypto_ossl_configure_server_session(connection->ssl) != 0) {
    wls_set_error("ngtcp2_crypto_ossl_configure_server_session failed");
    wls_connection_destroy(connection);
    return NULL;
  }
  connection->connection_ref.get_conn = wls_crypto_get_connection;
  connection->connection_ref.user_data = connection;
  SSL_set_app_data(connection->ssl, &connection->connection_ref);
  SSL_set_accept_state(connection->ssl);
  SSL_set_quic_tls_early_data_enabled(connection->ssl, 0);
  ngtcp2_conn_set_tls_native_handle(connection->quic,
                                    connection->crypto_context);

  connection->next = server->connections;
  server->connections = connection;
  ++server->active_connections;
  connection->active_counted = 1;
  if (wls_cid_insert(server, connection->server_cid.data,
                     connection->server_cid.datalen, connection) !=
        WLS_TRANSPORT_OK ||
      wls_cid_insert(server, initial_header->dcid.data,
                     initial_header->dcid.datalen, connection) !=
        WLS_TRANSPORT_OK) {
    server->connections = connection->next;
    wls_connection_destroy(connection);
    return NULL;
  }
  return connection;
}

static int wls_connection_feed(wls_h3_connection *connection,
                               const struct sockaddr_storage *local_address,
                               socklen_t local_address_length,
                               const struct sockaddr_storage *remote_address,
                               socklen_t remote_address_length,
                               const uint8_t *packet, size_t packet_length) {
  ngtcp2_path path;
  memset(&path, 0, sizeof(path));
  path.local.addr = (ngtcp2_sockaddr *)local_address;
  path.local.addrlen = local_address_length;
  path.remote.addr = (ngtcp2_sockaddr *)remote_address;
  path.remote.addrlen = remote_address_length;
  path.user_data = connection->io_path;
  ngtcp2_pkt_info packet_info;
  memset(&packet_info, 0, sizeof(packet_info));
  int result = ngtcp2_conn_read_pkt(connection->quic, &path, &packet_info,
                                    packet, packet_length, wls_now());
  if (result == NGTCP2_ERR_DRAINING) {
    ++connection->server->draining_reads;
    if (connection->rotation_requested &&
        !connection->rotation_completion_recorded) {
      connection->rotation_completion_recorded = 1;
      ++connection->server->connection_rotation_completions;
    }
    return WLS_TRANSPORT_OK;
  }
  if (result == NGTCP2_ERR_CLOSING) {
    ++connection->server->closing_reads;
    (void)wls_connection_resend_terminal_close(connection, wls_now());
    return WLS_TRANSPORT_OK;
  }
  if (result != 0) {
    wls_record_connection_error(
      connection,
      result == NGTCP2_ERR_CALLBACK_FAILURE
        ? WLS_H3_CONNECTION_ERROR_STAGE_CALLBACK
        : WLS_H3_CONNECTION_ERROR_STAGE_READ_PKT,
      result);
    wls_set_error("ngtcp2_conn_read_pkt: %s", ngtcp2_strerror(result));
    return WLS_TRANSPORT_QUIC_ERROR;
  }
  if (connection->rotation_requested &&
      !connection->shutdown_notice_sent) {
    int notice_result = wls_http3_submit_shutdown_notice(connection);
    if (notice_result != WLS_TRANSPORT_OK) {
      wls_record_connection_error(
        connection, WLS_H3_CONNECTION_ERROR_STAGE_CALLBACK, notice_result);
      return notice_result;
    }
  }
  return wls_connection_flush(connection);
}

static int wls_connection_flush_pending(wls_h3_connection *connection) {
  if (!connection->pending_packet || connection->pending_packet_length == 0) {
    return WLS_TRANSPORT_OK;
  }
  uint8_t direction = connection->pending_channel_direction != 0
                        ? connection->pending_channel_direction
                        : WLS_H3_CHANNEL_EGRESS;
  int send_result;
  if (connection->io_path->datagram_channel) {
    send_result = wls_worker_send_channel_datagram(
      connection->server, connection->pending_packet,
      connection->pending_packet_length,
      (const struct sockaddr *)&connection->local_address,
      connection->local_address_length,
      (const struct sockaddr *)&connection->pending_remote_address,
      connection->pending_remote_address_length,
      connection->authorization_id, direction,
      direction == WLS_H3_CHANNEL_PATH_CLOSE ? connection : NULL);
  } else {
    send_result = wls_io_path_send_datagram(
      connection->io_path, connection->pending_packet,
      connection->pending_packet_length,
      (const struct sockaddr *)&connection->local_address,
      connection->local_address_length,
      (const struct sockaddr *)&connection->pending_remote_address,
      connection->pending_remote_address_length,
      connection->authorization_id, "send pending QUIC packet");
  }
  if (send_result != WLS_TRANSPORT_OK) {
    return send_result;
  }
  free(connection->pending_packet);
  connection->pending_packet = NULL;
  connection->pending_packet_length = 0;
  connection->pending_remote_address_length = 0;
  connection->pending_channel_direction = 0;
  return WLS_TRANSPORT_OK;
}

static int wls_connection_store_pending(
  wls_h3_connection *connection, const uint8_t *packet, size_t packet_length,
  const ngtcp2_addr *remote_address, uint8_t channel_direction) {
  if (!remote_address || !remote_address->addr ||
      remote_address->addrlen == 0 ||
      remote_address->addrlen > sizeof(connection->pending_remote_address) ||
      packet_length == 0 || packet_length > WLS_H3_MAX_PACKET_SIZE ||
      connection->pending_packet) {
    wls_set_error("invalid pending QUIC packet state");
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }
  uint8_t *copy = malloc(packet_length);
  if (!copy) {
    wls_set_error("unable to retain pending QUIC packet");
    return WLS_TRANSPORT_NOMEM;
  }
  memcpy(copy, packet, packet_length);
  memset(&connection->pending_remote_address, 0,
         sizeof(connection->pending_remote_address));
  memcpy(&connection->pending_remote_address, remote_address->addr,
         remote_address->addrlen);
  connection->pending_packet = copy;
  connection->pending_packet_length = packet_length;
  connection->pending_remote_address_length = remote_address->addrlen;
  connection->pending_channel_direction = channel_direction;
  return WLS_TRANSPORT_OK;
}

/*
 * Complete an HTTP/3 graceful shutdown with an explicit QUIC application
 * close.  GOAWAY alone only rejects new request streams; if the Worker exits
 * afterwards, clients see a silent UDP black hole and wait for their request
 * timeout before reconnecting.  A NO_ERROR CONNECTION_CLOSE makes the
 * connection loss observable immediately and lets the client move to a hot
 * replacement Worker.
 */
static int wls_connection_transmit_terminal_close(
  wls_h3_connection *connection) {
  if (!connection || !connection->terminal_close_generated ||
      !connection->terminal_close_packet ||
      connection->terminal_close_packet_length == 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  ngtcp2_path *path = &connection->terminal_close_path_storage.path;
  connection->terminal_close_last_attempt_at = wls_now();
  if (connection->io_path->datagram_channel) {
    return wls_worker_send_channel_datagram(
      connection->server, connection->terminal_close_packet,
      connection->terminal_close_packet_length,
      path->local.addr, path->local.addrlen,
      (const struct sockaddr *)path->remote.addr, path->remote.addrlen,
      connection->authorization_id, WLS_H3_CHANNEL_PATH_CLOSE, connection);
  }
  return wls_io_path_send_datagram(
    connection->io_path, connection->terminal_close_packet,
    connection->terminal_close_packet_length,
    path->local.addr, path->local.addrlen,
    (const struct sockaddr *)path->remote.addr, path->remote.addrlen,
    connection->authorization_id,
    "send graceful QUIC connection close");
}

static int wls_connection_handoff_terminal_close(
  wls_h3_connection *connection) {
  if (!connection || !connection->terminal_close_generated) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  if (connection->terminal_close_handed_off) {
    return WLS_TRANSPORT_OK;
  }
  int send_result = wls_connection_transmit_terminal_close(connection);
  if (send_result == WLS_TRANSPORT_OK) {
    connection->terminal_close_handed_off = 1;
  }
  return send_result;
}

static int wls_connection_resend_terminal_close(
  wls_h3_connection *connection, ngtcp2_tstamp now) {
  if (!connection || !connection->terminal_close_generated ||
      !connection->terminal_close_handed_off) {
    return WLS_TRANSPORT_OK;
  }
  if (connection->terminal_close_last_attempt_at != 0 &&
      now < connection->terminal_close_last_attempt_at +
              WLS_H3_TERMINAL_CLOSE_RESEND_INTERVAL) {
    return WLS_TRANSPORT_OK;
  }
  return wls_connection_transmit_terminal_close(connection);
}

static int wls_connection_send_drain_close(
  wls_h3_connection *connection) {
  if (!connection) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  connection->terminal_close_requested = 1;
  if (ngtcp2_conn_in_draining_period2(connection->quic)) {
    return WLS_TRANSPORT_OK;
  }
  if (connection->terminal_close_handed_off) {
    return WLS_TRANSPORT_OK;
  }
  if (connection->terminal_close_generated) {
    return wls_connection_handoff_terminal_close(connection);
  }
  if (ngtcp2_conn_in_closing_period2(connection->quic)) {
    return WLS_TRANSPORT_AGAIN;
  }

  connection->terminal_close_last_attempt_at = wls_now();
  int pending_result = wls_connection_flush_pending(connection);
  if (pending_result != WLS_TRANSPORT_OK) {
    return pending_result;
  }

  if (!connection->terminal_close_packet) {
    connection->terminal_close_packet =
      malloc(WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES);
    if (!connection->terminal_close_packet) {
      wls_set_error("unable to reserve terminal QUIC close packet");
      return WLS_TRANSPORT_NOMEM;
    }
  }

  ngtcp2_path_storage_zero(&connection->terminal_close_path_storage);
  ngtcp2_path *path = &connection->terminal_close_path_storage.path;
  ngtcp2_pkt_info packet_info;
  memset(&packet_info, 0, sizeof(packet_info));
  ngtcp2_ccerr close_error;
  ngtcp2_ccerr_default(&close_error);
  if (connection->http3_ready) {
    ngtcp2_ccerr_set_application_error(
      &close_error, NGHTTP3_H3_NO_ERROR, NULL, 0);
  }
  ngtcp2_tstamp now = wls_now();
  connection->terminal_close_last_attempt_at = now;
  ngtcp2_ssize written = ngtcp2_conn_write_connection_close(
    connection->quic, path, &packet_info,
    connection->terminal_close_packet, WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES,
    &close_error, now);
  if (written == NGTCP2_ERR_NOBUF) {
    return WLS_TRANSPORT_AGAIN;
  }
  if (written <= 0) {
    wls_set_error("ngtcp2_conn_write_connection_close: %s",
                  written < 0 ? ngtcp2_strerror((int)written)
                              : "no close packet produced");
    return WLS_TRANSPORT_QUIC_ERROR;
  }
  ngtcp2_conn_update_pkt_tx_time(connection->quic, now);
  connection->terminal_close_packet_length = (size_t)written;
  connection->terminal_close_generated = 1;
  return wls_connection_handoff_terminal_close(connection);
}

static int wls_connection_flush(wls_h3_connection *connection) {
  if (ngtcp2_conn_in_draining_period2(connection->quic)) {
    ++connection->server->flush_skipped_draining;
    return WLS_TRANSPORT_OK;
  }
  if (ngtcp2_conn_in_closing_period2(connection->quic)) {
    ++connection->server->flush_skipped_closing;
    return WLS_TRANSPORT_OK;
  }
  int pending_result = wls_connection_flush_pending(connection);
  if (pending_result == WLS_TRANSPORT_AGAIN) {
    return WLS_TRANSPORT_OK;
  }
  if (pending_result != WLS_TRANSPORT_OK) {
    wls_record_connection_error(
      connection, WLS_H3_CONNECTION_ERROR_STAGE_FLUSH, pending_result);
    return pending_result;
  }
  uint8_t packet[WLS_H3_MAX_PACKET_SIZE];
  for (unsigned iteration = 0; iteration < WLS_H3_MAX_WRITE_BATCH;
       ++iteration) {
    ngtcp2_path_storage path_storage;
    ngtcp2_path_storage_zero(&path_storage);
    ngtcp2_path *path = &path_storage.path;
    ngtcp2_pkt_info packet_info;
    memset(&packet_info, 0, sizeof(packet_info));
    ngtcp2_tstamp write_timestamp = wls_now();
    ngtcp2_ssize written = 0;

    for (;;) {
      int64_t stream_id = -1;
      int fin = 0;
      nghttp3_vec http_vectors[16];
      ngtcp2_vec quic_vectors[16];
      nghttp3_ssize vector_count = 0;
      if (connection->http3 &&
          ngtcp2_conn_get_max_data_left2(connection->quic) != 0) {
        vector_count = nghttp3_conn_writev_stream(
          connection->http3, &stream_id, &fin, http_vectors,
          sizeof(http_vectors) / sizeof(http_vectors[0]));
        if (vector_count < 0) {
          wls_record_connection_error(
            connection, WLS_H3_CONNECTION_ERROR_STAGE_FLUSH,
            (int64_t)vector_count);
          wls_set_error("nghttp3_conn_writev_stream: %s",
                        nghttp3_strerror((int)vector_count));
          return WLS_TRANSPORT_HTTP3_ERROR;
        }
        for (nghttp3_ssize index = 0; index < vector_count; ++index) {
          quic_vectors[index].base = http_vectors[index].base;
          quic_vectors[index].len = http_vectors[index].len;
        }
      }

      /*
       * Keep the ngtcp2 packet coalescer open only while real HTTP/3 stream
       * data is available.  A stream-close callback may queue MAX_STREAMS
       * without any application payload; finalizing that control-only packet
       * here prevents both peers waiting at the advertised stream boundary.
       */
      uint32_t flags = NGTCP2_WRITE_STREAM_FLAG_PADDING;
      if (vector_count > 0) {
        flags |= NGTCP2_WRITE_STREAM_FLAG_MORE;
      }
      if (fin) {
        flags |= NGTCP2_WRITE_STREAM_FLAG_FIN;
      }
      ngtcp2_ssize data_written = -1;
      written = ngtcp2_conn_writev_stream(
        connection->quic, path, &packet_info, packet, sizeof(packet),
        &data_written, flags, stream_id,
        vector_count > 0 ? quic_vectors : NULL,
        vector_count > 0 ? (size_t)vector_count : 0, write_timestamp);
      if (written == NGTCP2_ERR_DRAINING) {
        ++connection->server->flush_skipped_draining;
        return WLS_TRANSPORT_OK;
      }
      if (written == NGTCP2_ERR_CLOSING) {
        ++connection->server->flush_skipped_closing;
        return WLS_TRANSPORT_OK;
      }
      if (written == NGTCP2_ERR_STREAM_DATA_BLOCKED) {
        if (connection->http3) {
          nghttp3_conn_block_stream(connection->http3, stream_id);
        }
        continue;
      }
      if (written == NGTCP2_ERR_STREAM_NOT_FOUND) {
        ++connection->server->write_stream_not_found;
        /* nghttp3 may still yield a stream which ngtcp2 closed while an ACK
         * callback advanced the HTTP/3 scheduler. Retire that stale writer
         * and continue with the remaining streams instead of tearing down the
         * whole multiplexed connection. */
        if (connection->http3 && stream_id >= 0) {
          nghttp3_conn_shutdown_stream_write(connection->http3, stream_id);
        }
        continue;
      }
      if (written == NGTCP2_ERR_STREAM_SHUT_WR) {
        if (connection->http3) {
          nghttp3_conn_shutdown_stream_write(connection->http3, stream_id);
        }
        continue;
      }
      if (written < 0 && written != NGTCP2_ERR_WRITE_MORE) {
        wls_record_connection_error(
          connection, WLS_H3_CONNECTION_ERROR_STAGE_FLUSH, written);
        wls_set_error("ngtcp2_conn_writev_stream: %s",
                      ngtcp2_strerror((int)written));
        return WLS_TRANSPORT_QUIC_ERROR;
      }
      if (data_written >= 0 && connection->http3) {
        int result = nghttp3_conn_add_write_offset(
          connection->http3, stream_id, (uint64_t)data_written);
        if (result != 0) {
          wls_record_connection_error(
            connection, WLS_H3_CONNECTION_ERROR_STAGE_FLUSH, result);
          wls_set_error("nghttp3_conn_add_write_offset: %s",
                        nghttp3_strerror(result));
          return WLS_TRANSPORT_HTTP3_ERROR;
        }
      }
      if (written == NGTCP2_ERR_WRITE_MORE) {
        continue;
      }
      break;
    }

    ngtcp2_conn_update_pkt_tx_time(connection->quic, write_timestamp);
    if (written == 0) {
      break;
    }
    int send_result = wls_io_path_send_datagram(
      connection->io_path, packet, (size_t)written,
      path->local.addr, path->local.addrlen,
      (struct sockaddr *)path->remote.addr, path->remote.addrlen,
      connection->authorization_id,
      "send QUIC packet");
    if (send_result == WLS_TRANSPORT_AGAIN) {
      return wls_connection_store_pending(
        connection, packet, (size_t)written, &path->remote,
        WLS_H3_CHANNEL_EGRESS);
    }
    if (send_result != WLS_TRANSPORT_OK) {
      wls_record_connection_error(
        connection, WLS_H3_CONNECTION_ERROR_STAGE_FLUSH, send_result);
      return send_result;
    }
  }
  return WLS_TRANSPORT_OK;
}

static void wls_crypto_init_once(void) {
  OPENSSL_init_ssl(OPENSSL_INIT_LOAD_SSL_STRINGS |
                     OPENSSL_INIT_LOAD_CRYPTO_STRINGS,
                   NULL);
  wls_crypto_init_result = ngtcp2_crypto_ossl_init();
}

static int wls_validate_runtime_versions(void) {
  const ngtcp2_info *ngtcp2_info_ptr = ngtcp2_version(0);
  const nghttp3_info *nghttp3_info_ptr = nghttp3_version(0);
  if (!ngtcp2_info_ptr || !nghttp3_info_ptr) {
    wls_set_error("unable to query ngtcp2/nghttp3 runtime versions");
    return WLS_TRANSPORT_ABI_MISMATCH;
  }

  if ((uint32_t)ngtcp2_info_ptr->version_num != (uint32_t)NGTCP2_VERSION_NUM) {
    wls_set_error("ngtcp2 compile/runtime mismatch: 0x%06x/0x%06x",
                  (unsigned)NGTCP2_VERSION_NUM,
                  (unsigned)ngtcp2_info_ptr->version_num);
    return WLS_TRANSPORT_ABI_MISMATCH;
  }
  if ((uint32_t)nghttp3_info_ptr->version_num !=
      (uint32_t)NGHTTP3_VERSION_NUM) {
    wls_set_error("nghttp3 compile/runtime mismatch: 0x%06x/0x%06x",
                  (unsigned)NGHTTP3_VERSION_NUM,
                  (unsigned)nghttp3_info_ptr->version_num);
    return WLS_TRANSPORT_ABI_MISMATCH;
  }
  if ((OpenSSL_version_num() >> 20) != (OPENSSL_VERSION_NUMBER >> 20)) {
    wls_set_error("OpenSSL compile/runtime major mismatch: 0x%llx/0x%llx",
                  (unsigned long long)OPENSSL_VERSION_NUMBER,
                  (unsigned long long)OpenSSL_version_num());
    return WLS_TRANSPORT_ABI_MISMATCH;
  }

  pthread_once(&wls_crypto_once, wls_crypto_init_once);
  if (wls_crypto_init_result != 0) {
    wls_set_error("ngtcp2_crypto_ossl_init failed");
    return WLS_TRANSPORT_TLS_ERROR;
  }
  return WLS_TRANSPORT_OK;
}

static void wls_tls_context_ex_init_once(void) {
  wls_tls_context_ex_index =
    SSL_CTX_get_ex_new_index(0, NULL, NULL, NULL, NULL);
}

static int wls_tls_ticket_digest_valid(const char *digest) {
  if (!digest || strlen(digest) != WLS_TLS_TICKET_DIGEST_HEX_LENGTH) {
    return 0;
  }
  for (size_t index = 0; index < WLS_TLS_TICKET_DIGEST_HEX_LENGTH; ++index) {
    if (!((digest[index] >= '0' && digest[index] <= '9') ||
          (digest[index] >= 'a' && digest[index] <= 'f'))) {
      return 0;
    }
  }
  return 1;
}

static int wls_tls_ticket_derive_component(
  const uint8_t seed[WLS_TLS_TICKET_SECRET_LENGTH],
  const uint8_t session_context[WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH],
  const char *label, uint8_t *output, size_t output_length) {
  uint8_t input[96];
  uint8_t digest[EVP_MAX_MD_SIZE];
  unsigned int digest_length = 0;
  size_t label_length = strlen(label);
  if (!seed || !session_context || !label || !output || output_length > 32 ||
      label_length + 1 + WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH > sizeof(input)) {
    return 0;
  }
  memcpy(input, label, label_length);
  input[label_length] = 0;
  memcpy(input + label_length + 1, session_context,
         WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH);
  unsigned char *result = HMAC(
    EVP_sha256(), seed, WLS_TLS_TICKET_SECRET_LENGTH, input,
    label_length + 1 + WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH, digest,
    &digest_length);
  OPENSSL_cleanse(input, sizeof(input));
  if (!result || digest_length < output_length) {
    OPENSSL_cleanse(digest, sizeof(digest));
    return 0;
  }
  memcpy(output, digest, output_length);
  OPENSSL_cleanse(digest, sizeof(digest));
  return 1;
}

static int wls_tls_ticket_material_derive(
  const uint8_t seed[WLS_TLS_TICKET_SECRET_LENGTH],
  const uint8_t session_context[WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH],
  wls_tls_ticket_material *material) {
  if (!material) {
    return 0;
  }
  memset(material, 0, sizeof(*material));
  if (!wls_tls_ticket_derive_component(
        seed, session_context, "wls-ticket-name-v1", material->name,
        sizeof(material->name)) ||
      !wls_tls_ticket_derive_component(
        seed, session_context, "wls-ticket-cipher-v1", material->cipher_key,
        sizeof(material->cipher_key)) ||
      !wls_tls_ticket_derive_component(
        seed, session_context, "wls-ticket-mac-v1", material->mac_key,
        sizeof(material->mac_key))) {
    OPENSSL_cleanse(material, sizeof(*material));
    return 0;
  }
  return 1;
}

static int wls_tls_ticket_mac_init(EVP_MAC_CTX *mac,
                                   wls_tls_ticket_material *material) {
  char digest_name[] = "SHA256";
  OSSL_PARAM parameters[3];
  parameters[0] = OSSL_PARAM_construct_octet_string(
    OSSL_MAC_PARAM_KEY, material->mac_key, sizeof(material->mac_key));
  parameters[1] = OSSL_PARAM_construct_utf8_string(
    OSSL_MAC_PARAM_DIGEST, digest_name, 0);
  parameters[2] = OSSL_PARAM_construct_end();
  return EVP_MAC_CTX_set_params(mac, parameters) == 1;
}

static int wls_tls_ticket_key_callback(
  SSL *ssl, unsigned char key_name[WLS_TLS_TICKET_NAME_LENGTH],
  unsigned char iv[EVP_MAX_IV_LENGTH], EVP_CIPHER_CTX *cipher,
  EVP_MAC_CTX *mac, int encrypt) {
  SSL_CTX *ssl_context = ssl ? SSL_get_SSL_CTX(ssl) : NULL;
  wls_tls_context *context =
    ssl_context && wls_tls_context_ex_index >= 0
      ? SSL_CTX_get_ex_data(ssl_context, wls_tls_context_ex_index)
      : NULL;
  if (!context ||
      atomic_load_explicit(&context->ticket_ring_active,
                           memory_order_acquire) == 0) {
    return 0;
  }

  wls_tls_ticket_material material;
  memset(&material, 0, sizeof(material));
  int use_previous = 0;
  if (pthread_rwlock_rdlock(&context->ticket_lock) != 0) {
    atomic_fetch_add_explicit(&context->ticket_errors, 1,
                              memory_order_relaxed);
    return -1;
  }
  if (encrypt) {
    memcpy(&material, &context->current_ticket, sizeof(material));
  } else if (CRYPTO_memcmp(key_name, context->current_ticket.name,
                           WLS_TLS_TICKET_NAME_LENGTH) == 0) {
    memcpy(&material, &context->current_ticket, sizeof(material));
  } else if (CRYPTO_memcmp(key_name, context->previous_ticket.name,
                           WLS_TLS_TICKET_NAME_LENGTH) == 0) {
    memcpy(&material, &context->previous_ticket, sizeof(material));
    use_previous = 1;
  } else {
    pthread_rwlock_unlock(&context->ticket_lock);
    atomic_fetch_add_explicit(&context->tickets_rejected, 1,
                              memory_order_relaxed);
    return 0;
  }
  pthread_rwlock_unlock(&context->ticket_lock);

  int result = -1;
  const EVP_CIPHER *ticket_cipher = EVP_aes_256_cbc();
  int iv_length = EVP_CIPHER_get_iv_length(ticket_cipher);
  if (iv_length <= 0 || iv_length > EVP_MAX_IV_LENGTH) {
    goto cleanup;
  }
  if (encrypt) {
    memcpy(key_name, material.name, sizeof(material.name));
    if (RAND_bytes(iv, iv_length) != 1 ||
        EVP_EncryptInit_ex(cipher, ticket_cipher, NULL, material.cipher_key,
                           iv) != 1 ||
        !wls_tls_ticket_mac_init(mac, &material)) {
      goto cleanup;
    }
    atomic_fetch_add_explicit(&context->tickets_encrypted, 1,
                              memory_order_relaxed);
    result = 1;
  } else {
    if (EVP_DecryptInit_ex(cipher, ticket_cipher, NULL, material.cipher_key,
                           iv) != 1 ||
        !wls_tls_ticket_mac_init(mac, &material)) {
      goto cleanup;
    }
    if (use_previous) {
      atomic_fetch_add_explicit(&context->tickets_decrypted_previous, 1,
                                memory_order_relaxed);
      result = 2;
    } else {
      atomic_fetch_add_explicit(&context->tickets_decrypted_current, 1,
                                memory_order_relaxed);
      result = 1;
    }
  }

cleanup:
  if (result < 0) {
    atomic_fetch_add_explicit(&context->ticket_errors, 1,
                              memory_order_relaxed);
  }
  OPENSSL_cleanse(&material, sizeof(material));
  return result;
}

static int wls_alpn_select(SSL *ssl, const unsigned char **out,
                           unsigned char *outlen, const unsigned char *in,
                           unsigned int inlen, void *argument) {
  (void)ssl;
  wls_tls_context *context = argument;
  if (!context || !context->alpn_wire || context->alpn_wire_length == 0) {
    return SSL_TLSEXT_ERR_ALERT_FATAL;
  }

  size_t wanted_offset = 0;
  while (wanted_offset < context->alpn_wire_length) {
    size_t wanted_length = context->alpn_wire[wanted_offset];
    if (wanted_length == 0 ||
        wanted_offset + 1 + wanted_length > context->alpn_wire_length) {
      return SSL_TLSEXT_ERR_ALERT_FATAL;
    }

    size_t offered_offset = 0;
    while (offered_offset < inlen) {
      size_t offered_length = in[offered_offset];
      if (offered_length == 0 ||
          offered_offset + 1 + offered_length > inlen) {
        return SSL_TLSEXT_ERR_ALERT_FATAL;
      }
      if (wanted_length == offered_length &&
          memcmp(context->alpn_wire + wanted_offset + 1,
                 in + offered_offset + 1, wanted_length) == 0) {
        *out = in + offered_offset + 1;
        *outlen = (unsigned char)offered_length;
        return SSL_TLSEXT_ERR_OK;
      }
      offered_offset += 1 + offered_length;
    }
    wanted_offset += 1 + wanted_length;
  }
  return SSL_TLSEXT_ERR_ALERT_FATAL;
}

uint32_t wls_transport_abi_version(void) {
  return WLS_TRANSPORT_ABI_VERSION;
}

const char *wls_transport_build_id(void) {
  return "wls-h3-abi/2.9 ngtcp2/" NGTCP2_VERSION " nghttp3/" NGHTTP3_VERSION
         " openssl/" OPENSSL_VERSION_TEXT;
}

const char *wls_transport_last_error(void) {
  return wls_last_error;
}

int wls_transport_get_versions(wls_transport_versions *versions) {
  if (!versions || versions->struct_size != sizeof(*versions)) {
    wls_set_error("wls_transport_versions struct_size mismatch");
    return WLS_TRANSPORT_ABI_MISMATCH;
  }

  const ngtcp2_info *ngtcp2_info_ptr = ngtcp2_version(0);
  const nghttp3_info *nghttp3_info_ptr = nghttp3_version(0);
  if (!ngtcp2_info_ptr || !nghttp3_info_ptr) {
    wls_set_error("unable to query runtime versions");
    return WLS_TRANSPORT_ABI_MISMATCH;
  }

  versions->abi_version = WLS_TRANSPORT_ABI_VERSION;
  versions->ngtcp2_compile = NGTCP2_VERSION_NUM;
  versions->ngtcp2_runtime = (uint32_t)ngtcp2_info_ptr->version_num;
  versions->nghttp3_compile = NGHTTP3_VERSION_NUM;
  versions->nghttp3_runtime = (uint32_t)nghttp3_info_ptr->version_num;
  versions->openssl_compile = OPENSSL_VERSION_NUMBER;
  versions->openssl_runtime = OpenSSL_version_num();
  return wls_validate_runtime_versions();
}

static void wls_tls_context_destroy(wls_tls_context *context) {
  if (!context) {
    return;
  }
  if (context->ssl_ctx) {
    if (wls_tls_context_ex_index >= 0) {
      SSL_CTX_set_ex_data(context->ssl_ctx, wls_tls_context_ex_index, NULL);
    }
    SSL_CTX_free(context->ssl_ctx);
    context->ssl_ctx = NULL;
  }
  if (context->alpn_wire) {
    OPENSSL_cleanse(context->alpn_wire, context->alpn_wire_length);
    free(context->alpn_wire);
    context->alpn_wire = NULL;
  }
  OPENSSL_cleanse(&context->current_ticket, sizeof(context->current_ticket));
  OPENSSL_cleanse(&context->previous_ticket, sizeof(context->previous_ticket));
  OPENSSL_cleanse(context->session_context,
                  sizeof(context->session_context));
  OPENSSL_cleanse(context->ticket_digest,
                  sizeof(context->ticket_digest));
  if (context->ticket_lock_initialized) {
    pthread_rwlock_destroy(&context->ticket_lock);
    context->ticket_lock_initialized = 0;
  }
  OPENSSL_cleanse(context, sizeof(*context));
  free(context);
}

int wls_tls_context_new(const wls_tls_context_config *config,
                        wls_tls_context **out_context) {
  if (!config || !out_context ||
      config->struct_size != sizeof(wls_tls_context_config) ||
      !config->certificate_file || !config->private_key_file) {
    wls_set_error("invalid TLS context arguments or struct_size");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  *out_context = NULL;

  int version_result = wls_validate_runtime_versions();
  if (version_result != WLS_TRANSPORT_OK) {
    return version_result;
  }

  int min_version = config->min_tls_version == 0
                      ? TLS1_2_VERSION
                      : config->min_tls_version;
  int max_version = config->max_tls_version == 0
                      ? TLS1_3_VERSION
                      : config->max_tls_version;
  if ((min_version != TLS1_2_VERSION && min_version != TLS1_3_VERSION) ||
      max_version != TLS1_3_VERSION || min_version > max_version) {
    wls_set_error("shared TLS profile must be TLS1.2..TLS1.3 or TLS1.3 only");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  wls_tls_context *context = calloc(1, sizeof(*context));
  if (!context) {
    wls_set_error("unable to allocate TLS context");
    return WLS_TRANSPORT_NOMEM;
  }
  atomic_init(&context->ref_count, 1);
  atomic_init(&context->ticket_ring_active, 0);
  atomic_init(&context->handshakes_completed, 0);
  atomic_init(&context->full_handshakes, 0);
  atomic_init(&context->resumed_handshakes, 0);
  atomic_init(&context->tickets_encrypted, 0);
  atomic_init(&context->tickets_decrypted_current, 0);
  atomic_init(&context->tickets_decrypted_previous, 0);
  atomic_init(&context->tickets_rejected, 0);
  atomic_init(&context->ticket_errors, 0);
  atomic_init(&context->ssl_objects_created, 0);
  if (pthread_rwlock_init(&context->ticket_lock, NULL) != 0) {
    wls_set_error("unable to initialize TLS ticket-ring lock");
    free(context);
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }
  context->ticket_lock_initialized = 1;

  const uint8_t *alpn_wire = config->alpn_wire;
  size_t alpn_wire_length = config->alpn_wire_length;
  if (!alpn_wire || alpn_wire_length == 0) {
    alpn_wire = (const uint8_t *)WLS_H3_DEFAULT_ALPN;
    alpn_wire_length = WLS_H3_DEFAULT_ALPN_LEN;
  }
  if (alpn_wire_length > 255) {
    wls_tls_context_destroy(context);
    wls_set_error("ALPN wire list is too large");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  context->alpn_wire = malloc(alpn_wire_length);
  if (!context->alpn_wire) {
    wls_tls_context_destroy(context);
    wls_set_error("unable to allocate ALPN profile");
    return WLS_TRANSPORT_NOMEM;
  }
  memcpy(context->alpn_wire, alpn_wire, alpn_wire_length);
  context->alpn_wire_length = alpn_wire_length;

  context->ssl_ctx = SSL_CTX_new(TLS_server_method());
  if (!context->ssl_ctx) {
    wls_set_ssl_error("SSL_CTX_new");
    wls_tls_context_destroy(context);
    return WLS_TRANSPORT_TLS_ERROR;
  }
  if (SSL_CTX_set_min_proto_version(context->ssl_ctx, min_version) != 1 ||
      SSL_CTX_set_max_proto_version(context->ssl_ctx, max_version) != 1) {
    wls_set_ssl_error("SSL_CTX_set TLS1.3 profile");
    wls_tls_context_destroy(context);
    return WLS_TRANSPORT_TLS_ERROR;
  }

  SSL_CTX_set_mode(context->ssl_ctx, SSL_MODE_RELEASE_BUFFERS);
  SSL_CTX_set_session_cache_mode(context->ssl_ctx, SSL_SESS_CACHE_SERVER);
  if (SSL_CTX_set_num_tickets(context->ssl_ctx, 1) != 1 ||
      SSL_CTX_set_max_early_data(context->ssl_ctx, 0) != 1 ||
      SSL_CTX_set_recv_max_early_data(context->ssl_ctx, 0) != 1) {
    wls_set_ssl_error("SSL_CTX_set TLS ticket safety profile");
    wls_tls_context_destroy(context);
    return WLS_TRANSPORT_TLS_ERROR;
  }
  static const unsigned char session_id_context[] = "wls-native-transport";
  if (SSL_CTX_set_session_id_context(context->ssl_ctx, session_id_context,
                                     sizeof(session_id_context) - 1) != 1) {
    wls_set_ssl_error("SSL_CTX_set_session_id_context");
    wls_tls_context_destroy(context);
    return WLS_TRANSPORT_TLS_ERROR;
  }
  pthread_once(&wls_tls_context_ex_once, wls_tls_context_ex_init_once);
  if (wls_tls_context_ex_index < 0 ||
      SSL_CTX_set_ex_data(context->ssl_ctx, wls_tls_context_ex_index,
                          context) != 1) {
    wls_set_ssl_error("SSL_CTX_set_ex_data TLS context");
    wls_tls_context_destroy(context);
    return WLS_TRANSPORT_TLS_ERROR;
  }
  SSL_CTX_set_alpn_select_cb(context->ssl_ctx, wls_alpn_select, context);

  if (SSL_CTX_use_certificate_chain_file(context->ssl_ctx,
                                         config->certificate_file) != 1) {
    wls_set_ssl_error("SSL_CTX_use_certificate_chain_file");
    wls_tls_context_destroy(context);
    return WLS_TRANSPORT_TLS_ERROR;
  }
  if (SSL_CTX_use_PrivateKey_file(context->ssl_ctx, config->private_key_file,
                                  SSL_FILETYPE_PEM) != 1) {
    wls_set_ssl_error("SSL_CTX_use_PrivateKey_file");
    wls_tls_context_destroy(context);
    return WLS_TRANSPORT_TLS_ERROR;
  }
  if (SSL_CTX_check_private_key(context->ssl_ctx) != 1) {
    wls_set_ssl_error("SSL_CTX_check_private_key");
    wls_tls_context_destroy(context);
    return WLS_TRANSPORT_TLS_ERROR;
  }

  *out_context = context;
  wls_last_error[0] = '\0';
  return WLS_TRANSPORT_OK;
}

void wls_tls_context_retain(wls_tls_context *context) {
  if (context) {
    atomic_fetch_add_explicit(&context->ref_count, 1, memory_order_relaxed);
  }
}

void wls_tls_context_release(wls_tls_context *context) {
  if (!context) {
    return;
  }
  if (atomic_fetch_sub_explicit(&context->ref_count, 1,
                                memory_order_acq_rel) == 1) {
    wls_tls_context_destroy(context);
  }
}

uint64_t wls_tls_context_capabilities(const wls_tls_context *context) {
  if (!context || !context->ssl_ctx) {
    return 0;
  }
  uint64_t capabilities =
    WLS_TLS_CAP_TLS13 | WLS_TLS_CAP_ALPN | WLS_TLS_CAP_QUIC |
    WLS_TLS_CAP_TCP | WLS_TLS_CAP_SESSION_REUSE_STATS;
  if (atomic_load_explicit(&context->ticket_ring_active,
                           memory_order_acquire) != 0) {
    capabilities |= WLS_TLS_CAP_SHARED_TICKET_RING;
  }
  return capabilities;
}

int wls_tls_context_set_ticket_ring(
  wls_tls_context *context, const wls_tls_ticket_ring *ticket_ring) {
  if (!context || !context->ssl_ctx || !ticket_ring ||
      ticket_ring->struct_size != sizeof(wls_tls_ticket_ring) ||
      !ticket_ring->current_key ||
      ticket_ring->current_key_length != WLS_TLS_TICKET_SECRET_LENGTH ||
      !ticket_ring->previous_key ||
      ticket_ring->previous_key_length != WLS_TLS_TICKET_SECRET_LENGTH ||
      CRYPTO_memcmp(ticket_ring->current_key, ticket_ring->previous_key,
                    WLS_TLS_TICKET_SECRET_LENGTH) == 0 ||
      ticket_ring->epoch == 0 ||
      !wls_tls_ticket_digest_valid(ticket_ring->digest) ||
      !ticket_ring->session_context ||
      ticket_ring->session_context_length !=
        WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH ||
      ticket_ring->ticket_lifetime_seconds <
        WLS_TLS_TICKET_MIN_LIFETIME_SECONDS ||
      ticket_ring->ticket_lifetime_seconds >
        WLS_TLS_TICKET_MAX_LIFETIME_SECONDS ||
      ticket_ring->flags != 0) {
    wls_set_error("invalid ticket ring arguments or struct_size");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  wls_tls_ticket_material next_current;
  wls_tls_ticket_material next_previous;
  memset(&next_current, 0, sizeof(next_current));
  memset(&next_previous, 0, sizeof(next_previous));
  if (!wls_tls_ticket_material_derive(
        ticket_ring->current_key, ticket_ring->session_context,
        &next_current) ||
      !wls_tls_ticket_material_derive(
        ticket_ring->previous_key, ticket_ring->session_context,
        &next_previous)) {
    OPENSSL_cleanse(&next_current, sizeof(next_current));
    OPENSSL_cleanse(&next_previous, sizeof(next_previous));
    wls_set_error("unable to derive TLS ticket-ring material");
    return WLS_TRANSPORT_TLS_ERROR;
  }

  int result = WLS_TRANSPORT_OK;
  if (pthread_rwlock_wrlock(&context->ticket_lock) != 0) {
    OPENSSL_cleanse(&next_current, sizeof(next_current));
    OPENSSL_cleanse(&next_previous, sizeof(next_previous));
    wls_set_error("unable to lock TLS ticket ring for update");
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }

  int ring_active =
    atomic_load_explicit(&context->ticket_ring_active,
                         memory_order_acquire) != 0;
  if (ring_active) {
    int same_policy =
      CRYPTO_memcmp(context->session_context, ticket_ring->session_context,
                     WLS_TLS_TICKET_SESSION_CONTEXT_LENGTH) == 0 &&
      context->ticket_lifetime_seconds ==
        ticket_ring->ticket_lifetime_seconds;
    if (!same_policy) {
      wls_set_error("TLS ticket-ring policy cannot change during rotation");
      result = WLS_TRANSPORT_INVALID_ARGUMENT;
      goto cleanup;
    }
    if (ticket_ring->epoch < context->ticket_epoch) {
      wls_set_error("TLS ticket-ring epoch rollback rejected");
      result = WLS_TRANSPORT_INVALID_ARGUMENT;
      goto cleanup;
    }
    if (ticket_ring->epoch == context->ticket_epoch) {
      int same_snapshot =
        CRYPTO_memcmp(context->ticket_digest, ticket_ring->digest,
                       WLS_TLS_TICKET_DIGEST_HEX_LENGTH) == 0 &&
        CRYPTO_memcmp(&context->current_ticket, &next_current,
                       sizeof(next_current)) == 0 &&
        CRYPTO_memcmp(&context->previous_ticket, &next_previous,
                       sizeof(next_previous)) == 0;
      if (!same_snapshot) {
        wls_set_error("same TLS ticket-ring epoch has different snapshot");
        result = WLS_TRANSPORT_INVALID_ARGUMENT;
      } else {
        wls_last_error[0] = '\0';
      }
      goto cleanup;
    }
    if (CRYPTO_memcmp(&context->current_ticket, &next_previous,
                      sizeof(next_previous)) != 0) {
      wls_set_error("TLS ticket-ring rotation does not preserve previous key");
      result = WLS_TRANSPORT_INVALID_ARGUMENT;
      goto cleanup;
    }
  } else {
    if (atomic_load_explicit(&context->ssl_objects_created,
                             memory_order_acquire) != 0) {
      wls_set_error("TLS ticket ring must be installed before SSL_new");
      result = WLS_TRANSPORT_INVALID_ARGUMENT;
      goto cleanup;
    }
    if (SSL_CTX_set_session_id_context(
          context->ssl_ctx, ticket_ring->session_context,
          (unsigned int)ticket_ring->session_context_length) != 1 ||
        SSL_CTX_set_tlsext_ticket_key_evp_cb(
          context->ssl_ctx, wls_tls_ticket_key_callback) != 1) {
      wls_set_ssl_error("install TLS ticket-ring policy");
      result = WLS_TRANSPORT_TLS_ERROR;
      goto cleanup;
    }
    SSL_CTX_set_timeout(context->ssl_ctx,
                        ticket_ring->ticket_lifetime_seconds);
    memcpy(context->session_context, ticket_ring->session_context,
           sizeof(context->session_context));
  }

  OPENSSL_cleanse(&context->current_ticket,
                  sizeof(context->current_ticket));
  OPENSSL_cleanse(&context->previous_ticket,
                  sizeof(context->previous_ticket));
  memcpy(&context->current_ticket, &next_current, sizeof(next_current));
  memcpy(&context->previous_ticket, &next_previous, sizeof(next_previous));
  context->ticket_epoch = ticket_ring->epoch;
  context->ticket_lifetime_seconds = ticket_ring->ticket_lifetime_seconds;
  memcpy(context->ticket_digest, ticket_ring->digest,
         WLS_TLS_TICKET_DIGEST_HEX_LENGTH);
  context->ticket_digest[WLS_TLS_TICKET_DIGEST_HEX_LENGTH] = '\0';
  atomic_store_explicit(&context->ticket_ring_active, 1,
                        memory_order_release);
  wls_last_error[0] = '\0';

cleanup:
  pthread_rwlock_unlock(&context->ticket_lock);
  OPENSSL_cleanse(&next_current, sizeof(next_current));
  OPENSSL_cleanse(&next_previous, sizeof(next_previous));
  return result;
}

int wls_tls_context_get_stats(const wls_tls_context *context,
                              wls_tls_context_stats *stats) {
  if (!context || !context->ssl_ctx || !stats ||
      stats->struct_size != sizeof(wls_tls_context_stats)) {
    wls_set_error("invalid TLS context stats arguments or struct_size");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  uint32_t struct_size = stats->struct_size;
  memset(stats, 0, sizeof(*stats));
  stats->struct_size = struct_size;
  stats->flags = WLS_TLS_STATS_EARLY_DATA_DISABLED;
  stats->handshakes_completed = atomic_load_explicit(
    &context->handshakes_completed, memory_order_relaxed);
  stats->full_handshakes = atomic_load_explicit(
    &context->full_handshakes, memory_order_relaxed);
  stats->resumed_handshakes = atomic_load_explicit(
    &context->resumed_handshakes, memory_order_relaxed);
  stats->tickets_encrypted = atomic_load_explicit(
    &context->tickets_encrypted, memory_order_relaxed);
  stats->tickets_decrypted_current = atomic_load_explicit(
    &context->tickets_decrypted_current, memory_order_relaxed);
  stats->tickets_decrypted_previous = atomic_load_explicit(
    &context->tickets_decrypted_previous, memory_order_relaxed);
  stats->tickets_rejected = atomic_load_explicit(
    &context->tickets_rejected, memory_order_relaxed);
  stats->ticket_errors = atomic_load_explicit(
    &context->ticket_errors, memory_order_relaxed);

  if (pthread_rwlock_rdlock((pthread_rwlock_t *)&context->ticket_lock) != 0) {
    wls_set_error("unable to lock TLS ticket ring for stats");
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }
  if (atomic_load_explicit(&context->ticket_ring_active,
                           memory_order_acquire) != 0) {
    stats->flags |= WLS_TLS_STATS_RING_ACTIVE;
    stats->ticket_epoch = context->ticket_epoch;
    stats->ticket_lifetime_seconds = context->ticket_lifetime_seconds;
    memcpy(stats->ticket_digest, context->ticket_digest,
           sizeof(stats->ticket_digest));
  }
  pthread_rwlock_unlock((pthread_rwlock_t *)&context->ticket_lock);
  wls_last_error[0] = '\0';
  return WLS_TRANSPORT_OK;
}

int wls_h3_server_new(wls_tls_context *tls_context,
                      const wls_h3_server_config *config,
                      wls_h3_server **out_server) {
  if (!tls_context || !config || !out_server ||
      config->struct_size != sizeof(wls_h3_server_config)) {
    wls_set_error("invalid H3 server arguments or struct_size");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  *out_server = NULL;

  nghttp3_callbacks callbacks;
  memset(&callbacks, 0, sizeof(callbacks));
  nghttp3_settings settings;
  nghttp3_settings_default(&settings);
  nghttp3_conn *http3_probe = NULL;
  int http3_result = nghttp3_conn_server_new(
    &http3_probe, &callbacks, &settings, NULL, NULL);
  if (http3_result != 0) {
    wls_set_error("nghttp3_conn_server_new probe failed: %s",
                  nghttp3_strerror(http3_result));
    return WLS_TRANSPORT_HTTP3_ERROR;
  }
  nghttp3_conn_del(http3_probe);

  wls_h3_server *server = calloc(1, sizeof(*server));
  if (!server) {
    wls_set_error("unable to allocate H3 server");
    return WLS_TRANSPORT_NOMEM;
  }
  server->listener_io.fd = -1;
  server->listener_io.server = server;
#if defined(__linux__)
  server->connection_io.fd = -1;
  server->connection_io.server = server;
  wls_linux_h3_route_init(&server->linux_route);
#endif
  server->wait_fd = -1;
  server->channel_fd = -1;
  server->tls_context = tls_context;
  server->disable_active_migration =
    config->disable_active_migration ? 1 : 0;
  server->max_idle_timeout_ms = config->max_idle_timeout_ms != 0
                                  ? config->max_idle_timeout_ms
                                  : 30000;
  server->max_request_header_bytes = config->max_request_header_bytes != 0
                                       ? config->max_request_header_bytes
                                       : WLS_H3_DEFAULT_HEADER_LIMIT;
  server->max_request_body_bytes = config->max_request_body_bytes != 0
                                     ? config->max_request_body_bytes
                                     : WLS_H3_DEFAULT_BODY_LIMIT;
  uint64_t default_stream_window =
    (uint64_t)server->max_request_header_bytes +
    (uint64_t)server->max_request_body_bytes;
  server->initial_max_stream_data = config->initial_max_stream_data != 0
                                      ? config->initial_max_stream_data
                                      : default_stream_window;
  server->initial_max_data = config->initial_max_data != 0
                               ? config->initial_max_data
                               : server->initial_max_stream_data * 16;
  server->initial_max_streams_bidi = config->initial_max_streams_bidi != 0
                                       ? config->initial_max_streams_bidi
                                       : WLS_H3_DEFAULT_MAX_STREAMS_BIDI;
  server->max_connections = config->max_connections != 0
                              ? config->max_connections
                              : WLS_H3_DEFAULT_MAX_CONNECTIONS;
  if (server->max_connections > WLS_H3_CID_TABLE_CAPACITY / 4) {
    server->max_connections = WLS_H3_CID_TABLE_CAPACITY / 4;
  }
  server->max_active_streams = config->max_active_streams != 0
                                 ? config->max_active_streams
                                 : WLS_H3_DEFAULT_MAX_ACTIVE_STREAMS;
  if (server->max_active_streams > WLS_H3_TOKEN_TABLE_CAPACITY) {
    server->max_active_streams = WLS_H3_TOKEN_TABLE_CAPACITY;
  }
  server->retry_token_lifetime_ms =
    config->retry_token_lifetime_ms != 0
      ? config->retry_token_lifetime_ms
      : WLS_H3_DEFAULT_RETRY_TOKEN_LIFETIME_MS;
  if (config->retry_secret &&
      config->retry_secret_length >= sizeof(server->retry_secret)) {
    memcpy(server->retry_secret, config->retry_secret,
           sizeof(server->retry_secret));
  } else if (RAND_bytes(server->retry_secret,
                        (int)sizeof(server->retry_secret)) != 1) {
    free(server);
    wls_set_ssl_error("RAND_bytes Retry secret");
    return WLS_TRANSPORT_TLS_ERROR;
  }
  server->cid_slots = calloc(WLS_H3_CID_TABLE_CAPACITY,
                              sizeof(*server->cid_slots));
  server->token_slots = calloc(WLS_H3_TOKEN_TABLE_CAPACITY,
                                sizeof(*server->token_slots));
  if (!server->cid_slots || !server->token_slots) {
    free(server->cid_slots);
    free(server->token_slots);
    free(server);
    wls_set_error("unable to allocate H3 routing tables");
    return WLS_TRANSPORT_NOMEM;
  }
  wls_tls_context_retain(tls_context);
  *out_server = server;
  wls_last_error[0] = '\0';
  return WLS_TRANSPORT_OK;
}

int wls_h3_server_bind(wls_h3_server *server, const char *host,
                       uint16_t port, int reuse_port) {
  if (!server || !host || server->listener_io.fd >= 0) {
    wls_set_error("invalid or already-bound H3 server");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

#ifndef SO_REUSEPORT
  if (reuse_port) {
    wls_set_error("SO_REUSEPORT is unavailable on this platform");
    return WLS_TRANSPORT_UNSUPPORTED;
  }
#endif

  char service[6];
  snprintf(service, sizeof(service), "%u", (unsigned)port);
  struct addrinfo hints;
  memset(&hints, 0, sizeof(hints));
  hints.ai_family = AF_UNSPEC;
  hints.ai_socktype = SOCK_DGRAM;
  hints.ai_protocol = IPPROTO_UDP;
  hints.ai_flags = AI_NUMERICSERV;

  struct addrinfo *addresses = NULL;
  int gai_result = getaddrinfo(host, service, &hints, &addresses);
  if (gai_result != 0) {
    wls_set_error("getaddrinfo(%s:%s): %s", host, service,
                  gai_strerror(gai_result));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }

  int last_errno = 0;
  for (struct addrinfo *address = addresses; address;
       address = address->ai_next) {
    int fd = socket(address->ai_family, address->ai_socktype,
                    address->ai_protocol);
    if (fd < 0) {
      last_errno = errno;
      continue;
    }

    int one = 1;
    setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof(one));
#ifdef SO_REUSEPORT
    if (reuse_port &&
        setsockopt(fd, SOL_SOCKET, SO_REUSEPORT, &one, sizeof(one)) != 0) {
      last_errno = errno;
      close(fd);
      continue;
    }
#endif
    int flags = fcntl(fd, F_GETFL, 0);
    if (flags < 0 || fcntl(fd, F_SETFL, flags | O_NONBLOCK) != 0) {
      last_errno = errno;
      close(fd);
      continue;
    }
    if (bind(fd, address->ai_addr, address->ai_addrlen) != 0) {
      last_errno = errno;
      close(fd);
      continue;
    }

    struct sockaddr_storage bound_address;
    socklen_t bound_length = sizeof(bound_address);
    memset(&bound_address, 0, sizeof(bound_address));
    if (getsockname(fd, (struct sockaddr *)&bound_address,
                    &bound_length) != 0) {
      last_errno = errno;
      close(fd);
      continue;
    }
    server->listener_io.fd = fd;
    server->listener_io.owns_fd = 1;
    memcpy(&server->listener_io.local_address, &bound_address,
           bound_length);
    server->listener_io.local_address_length = bound_length;
    if (bound_address.ss_family == AF_INET) {
      server->bound_port = ntohs(
        ((struct sockaddr_in *)&bound_address)->sin_port);
    } else if (bound_address.ss_family == AF_INET6) {
      server->bound_port = ntohs(
        ((struct sockaddr_in6 *)&bound_address)->sin6_port);
    }
    break;
  }
  freeaddrinfo(addresses);

  if (server->listener_io.fd < 0) {
    wls_set_error("UDP bind(%s:%u%s) failed: %s", host, (unsigned)port,
                  reuse_port ? ", SO_REUSEPORT" : "", strerror(last_errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  wls_last_error[0] = '\0';
  return WLS_TRANSPORT_OK;
}

int wls_h3_server_bind_linux_route(
  wls_h3_server *server, const char *host, uint16_t port,
  const wls_h3_linux_route_config *config) {
#if !defined(__linux__)
  (void)server;
  (void)host;
  (void)port;
  (void)config;
  wls_set_error("reuseport eBPF routing requires Linux");
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  if (!server || !host || !config ||
      config->struct_size != sizeof(*config) ||
      server->listener_io.fd >= 0 || server->connection_io.fd >= 0 ||
      server->channel_fd >= 0 || server->linux_route_mode) {
    wls_set_error("invalid or already-bound Linux HTTP/3 route");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  struct sockaddr_storage bound_address;
  socklen_t bound_length = sizeof(bound_address);
  uint16_t bound_port = 0;
  char error[sizeof(wls_last_error)];
  memset(&bound_address, 0, sizeof(bound_address));
  memset(error, 0, sizeof(error));
  int result = wls_linux_h3_route_bind(
    &server->linux_route, host, port, config,
    &bound_address, &bound_length, &bound_port,
    error, sizeof(error));
  if (result != WLS_TRANSPORT_OK) {
    wls_set_error("%s", error[0] != 0
                          ? error
                          : "Linux HTTP/3 route bind failed");
    return result;
  }

  server->listener_io.fd = server->linux_route.listener_fd;
  server->listener_io.owns_fd = 1;
  memcpy(&server->listener_io.local_address,
         &bound_address, bound_length);
  server->listener_io.local_address_length = bound_length;
  server->connection_io.fd = server->linux_route.connection_fd;
  server->connection_io.owns_fd = 1;
  memcpy(&server->connection_io.local_address,
         &bound_address, bound_length);
  server->connection_io.local_address_length = bound_length;
  server->bound_port = bound_port;
  server->linux_route_mode = 1;
  wls_last_error[0] = 0;
  return WLS_TRANSPORT_OK;
#endif
}

int wls_h3_server_activate_linux_route(wls_h3_server *server) {
#if !defined(__linux__)
  (void)server;
  wls_set_error("reuseport eBPF routing requires Linux");
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  if (!server || !server->linux_route_mode) {
    wls_set_error("Linux HTTP/3 route is not staged");
    return WLS_TRANSPORT_NOT_BOUND;
  }
  char error[sizeof(wls_last_error)];
  memset(error, 0, sizeof(error));
  int result = wls_linux_h3_route_activate(
    &server->linux_route, error, sizeof(error));
  if (result != WLS_TRANSPORT_OK) {
    wls_set_error("%s", error[0] != 0
                          ? error
                          : "Linux HTTP/3 route activation failed");
    return result;
  }
  wls_last_error[0] = 0;
  return WLS_TRANSPORT_OK;
#endif
}

int wls_h3_server_get_linux_route_status(
  const wls_h3_server *server, wls_h3_linux_route_status *status) {
  if (!status || status->struct_size != sizeof(*status)) {
    wls_set_error("wls_h3_linux_route_status struct_size mismatch");
    return WLS_TRANSPORT_ABI_MISMATCH;
  }
#if !defined(__linux__)
  (void)server;
  uint32_t struct_size = status->struct_size;
  memset(status, 0, sizeof(*status));
  status->struct_size = struct_size;
  status->state = WLS_H3_LINUX_ROUTE_DISABLED;
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  if (!server || !server->linux_route_mode) {
    wls_set_error("Linux HTTP/3 route is not bound");
    return WLS_TRANSPORT_NOT_BOUND;
  }
  wls_linux_h3_route_get_status(&server->linux_route, status);
  return WLS_TRANSPORT_OK;
#endif
}

uint16_t wls_h3_server_bound_port(const wls_h3_server *server) {
  return server ? server->bound_port : 0;
}

int wls_h3_server_fd(const wls_h3_server *server) {
  return server ? server->listener_io.fd : -1;
}

int wls_h3_server_dup_fd(const wls_h3_server *server) {
  return server && server->listener_io.fd >= 0
           ? dup(server->listener_io.fd)
           : -1;
}

int wls_h3_server_wait_fd(const wls_h3_server *server) {
  if (!server) {
    return -1;
  }
#if defined(__linux__)
  if (server->linux_route_mode) {
    return server->linux_route.wait_fd;
  }
#endif
  return server->datagram_worker_mode ? server->wait_fd
                                    : server->listener_io.fd;
}

int wls_h3_server_dup_wait_fd(const wls_h3_server *server) {
  int fd = wls_h3_server_wait_fd(server);
  return fd >= 0 ? dup(fd) : -1;
}

#if defined(__APPLE__)
static int wls_kqueue_set_read(int queue_fd, int fd, int add) {
  struct kevent change;
  EV_SET(&change, (uintptr_t)fd, EVFILT_READ,
         add ? (EV_ADD | EV_ENABLE) : EV_DELETE,
         0, 0, NULL);
  if (kevent(queue_fd, &change, 1, NULL, 0, NULL) == 0) {
    return WLS_TRANSPORT_OK;
  }
  if (!add && errno == ENOENT) {
    return WLS_TRANSPORT_OK;
  }
  wls_set_error("kevent %s fd %d: %s", add ? "add" : "delete", fd,
                strerror(errno));
  return WLS_TRANSPORT_SOCKET_ERROR;
}

static int wls_kqueue_set_router_listener_read(int queue_fd, int fd,
                                               int add) {
  struct kevent change;
  EV_SET(&change, (uintptr_t)fd, EVFILT_READ,
         add ? (EV_ADD | EV_ENABLE) : EV_DELETE, 0, 0, NULL);
  if (kevent(queue_fd, &change, 1, NULL, 0, NULL) == 0) {
    return WLS_TRANSPORT_OK;
  }
  if (!add && errno == ENOENT) {
    return WLS_TRANSPORT_OK;
  }
  wls_set_error("kevent %s HTTP/3 Router listener fd %d: %s",
                add ? "add" : "delete", fd, strerror(errno));
  return WLS_TRANSPORT_SOCKET_ERROR;
}

static int wls_kqueue_set_router_listener_write(int queue_fd, int fd,
                                                int add) {
  struct kevent change;
  EV_SET(&change, (uintptr_t)fd, EVFILT_WRITE,
         add ? (EV_ADD | EV_ENABLE) : EV_DELETE, 0, 0, NULL);
  if (kevent(queue_fd, &change, 1, NULL, 0, NULL) == 0) {
    return WLS_TRANSPORT_OK;
  }
  if (!add && errno == ENOENT) {
    return WLS_TRANSPORT_OK;
  }
  wls_set_error("kevent %s HTTP/3 Router write fd %d: %s",
                add ? "add" : "delete", fd, strerror(errno));
  return WLS_TRANSPORT_SOCKET_ERROR;
}

#endif

#if defined(__APPLE__)
static int wls_server_receive_channel_datagram(
  wls_h3_server_impl *server) {
#if !defined(__APPLE__)
  (void)server;
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  uint8_t payload[sizeof(wls_h3_channel_datagram) +
                  WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES];
  struct sockaddr_un source;
  socklen_t source_length = sizeof(source);
  memset(&source, 0, sizeof(source));
  ssize_t received = recvfrom(
    server->channel_fd, payload, sizeof(payload), 0,
    (struct sockaddr *)&source, &source_length);
  if (received < 0) {
    if (errno == EAGAIN || errno == EWOULDBLOCK) {
      return WLS_TRANSPORT_AGAIN;
    }
    if (errno == EINTR) {
      return WLS_TRANSPORT_AGAIN;
    }
    wls_set_error("receive Darwin HTTP/3 datagram channel: %s",
                  strerror(errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  if ((size_t)received < sizeof(wls_h3_channel_datagram) ||
      !wls_unix_source_path_matches(
        &source, source_length, server->router_path)) {
    ++server->channel_drops;
    return WLS_TRANSPORT_OK;
  }

  wls_h3_channel_datagram envelope;
  memcpy(&envelope, payload, sizeof(envelope));
  size_t datagram_length = (size_t)received - sizeof(envelope);
  if (envelope.magic != WLS_H3_CHANNEL_MAGIC ||
      envelope.version != WLS_H3_CHANNEL_VERSION ||
      envelope.header_size != sizeof(envelope) ||
      envelope.direction != WLS_H3_CHANNEL_INGRESS ||
      envelope.worker_id != server->worker_id ||
      envelope.worker_generation != server->worker_generation ||
      envelope.route_epoch == 0 ||
      envelope.datagram_length != datagram_length ||
      datagram_length == 0 ||
      datagram_length > WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES ||
      envelope.local_address_length == 0 ||
      envelope.local_address_length > sizeof(envelope.local_address) ||
      envelope.remote_address_length == 0 ||
      envelope.remote_address_length > sizeof(envelope.remote_address) ||
      wls_sockaddr_port(&envelope.local_address) != server->bound_port ||
      (envelope.local_address.ss_family != AF_INET &&
       envelope.local_address.ss_family != AF_INET6) ||
      envelope.remote_address.ss_family !=
        envelope.local_address.ss_family) {
    ++server->channel_drops;
    return WLS_TRANSPORT_OK;
  }

  uint8_t expected_tag[32];
  int auth_result = wls_channel_authentication_tag(
    server->channel_key, &envelope, payload + sizeof(envelope),
    datagram_length, expected_tag);
  if (auth_result != WLS_TRANSPORT_OK ||
      CRYPTO_memcmp(expected_tag, envelope.authentication_tag,
                    sizeof(expected_tag)) != 0) {
    OPENSSL_cleanse(expected_tag, sizeof(expected_tag));
    ++server->channel_auth_failures;
    ++server->channel_drops;
    return WLS_TRANSPORT_OK;
  }
  OPENSSL_cleanse(expected_tag, sizeof(expected_tag));

  ++server->routed_datagrams;
  return wls_server_process_datagram(
    server, &server->listener_io, &envelope.local_address,
    envelope.local_address_length, &envelope.remote_address,
    envelope.remote_address_length, payload + sizeof(envelope),
    datagram_length, envelope.authorization_id);
#endif
}

static int wls_server_drain_channel(wls_h3_server_impl *server,
                                    unsigned max_datagrams) {
  unsigned drained = 0;
  while (drained < max_datagrams) {
    int result = wls_server_receive_channel_datagram(server);
    if (result == WLS_TRANSPORT_AGAIN) {
      break;
    }
    if (result != WLS_TRANSPORT_OK) {
      return result;
    }
    ++drained;
  }
  return drained != 0 ? (int)drained : WLS_TRANSPORT_AGAIN;
}
#endif

int wls_h3_server_bind_datagram_worker(
  wls_h3_server *server, const wls_h3_datagram_worker_config *config) {
#if !defined(__APPLE__)
  (void)server;
  (void)config;
  wls_set_error("packet-channel Workers require Darwin kqueue");
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  if (!server || !config || config->struct_size != sizeof(*config) ||
      server->listener_io.fd >= 0 || server->channel_fd >= 0 ||
      config->worker_id == 0 || config->generation == 0 ||
      config->public_port == 0 ||
      !wls_valid_channel_path(config->channel_path) ||
      !config->channel_key ||
      config->channel_key_length != WLS_H3_CHANNEL_KEY_LENGTH) {
    wls_set_error("invalid Darwin HTTP/3 packet Worker configuration");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }

  struct stat path_stat;
  if (lstat(config->channel_path, &path_stat) == 0) {
    if (!S_ISSOCK(path_stat.st_mode) || path_stat.st_uid != geteuid() ||
        unlink(config->channel_path) != 0) {
      wls_set_error("unsafe existing HTTP/3 Worker channel path");
      return WLS_TRANSPORT_SOCKET_ERROR;
    }
  } else if (errno != ENOENT) {
    wls_set_error("lstat HTTP/3 Worker channel path: %s", strerror(errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }

  int channel_fd = socket(AF_UNIX, SOCK_DGRAM, 0);
  if (channel_fd < 0 || wls_set_nonblocking_cloexec(channel_fd) != 0) {
    if (channel_fd >= 0) {
      close(channel_fd);
    }
    wls_set_error("create HTTP/3 Worker channel: %s", strerror(errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  int receive_buffer = WLS_H3_ROUTER_CHANNEL_BUFFER_BYTES;
  int send_buffer = WLS_H3_ROUTER_CHANNEL_BUFFER_BYTES;
  if (setsockopt(channel_fd, SOL_SOCKET, SO_RCVBUF, &receive_buffer,
                 sizeof(receive_buffer)) != 0 ||
      setsockopt(channel_fd, SOL_SOCKET, SO_SNDBUF, &send_buffer,
                 sizeof(send_buffer)) != 0) {
    int saved_errno = errno;
    close(channel_fd);
    wls_set_error("size HTTP/3 Worker datagram channel: %s",
                  strerror(saved_errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  socklen_t receive_buffer_length = sizeof(receive_buffer);
  if (getsockopt(channel_fd, SOL_SOCKET, SO_RCVBUF, &receive_buffer,
                 &receive_buffer_length) != 0 ||
      receive_buffer < (int)(WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES +
                             sizeof(wls_h3_channel_datagram))) {
    close(channel_fd);
    wls_set_error("HTTP/3 Worker channel is below one maximum datagram");
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  struct sockaddr_un address;
  memset(&address, 0, sizeof(address));
  address.sun_family = AF_UNIX;
  memcpy(address.sun_path, config->channel_path,
         strlen(config->channel_path) + 1);
  if (bind(channel_fd, (struct sockaddr *)&address, sizeof(address)) != 0 ||
      chmod(config->channel_path, 0600) != 0) {
    int saved_errno = errno;
    close(channel_fd);
    unlink(config->channel_path);
    wls_set_error("bind HTTP/3 Worker channel: %s", strerror(saved_errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }

  int queue_fd = kqueue();
  if (queue_fd < 0 || wls_set_cloexec(queue_fd) != 0) {
    int saved_errno = errno;
    if (queue_fd >= 0) {
      close(queue_fd);
    }
    close(channel_fd);
    unlink(config->channel_path);
    wls_set_error("create HTTP/3 packet-channel kqueue: %s",
                  strerror(saved_errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  if (wls_kqueue_set_read(queue_fd, channel_fd, 1) != WLS_TRANSPORT_OK) {
    close(queue_fd);
    close(channel_fd);
    unlink(config->channel_path);
    return WLS_TRANSPORT_SOCKET_ERROR;
  }

  server->wait_fd = queue_fd;
  server->channel_fd = channel_fd;
  server->datagram_worker_mode = 1;
  server->worker_id = config->worker_id;
  server->worker_generation = config->generation;
  server->bound_port = config->public_port;
  memcpy(server->channel_key, config->channel_key,
         WLS_H3_CHANNEL_KEY_LENGTH);
  server->route_id = wls_route_id_for_key(server->channel_key);
  if (server->route_id == 0) {
    close(queue_fd);
    close(channel_fd);
    unlink(config->channel_path);
    server->wait_fd = -1;
    server->channel_fd = -1;
    server->datagram_worker_mode = 0;
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }
  memcpy(server->channel_path, config->channel_path,
         strlen(config->channel_path) + 1);
  if (wls_router_channel_path(config->channel_path, server->router_path,
                             sizeof(server->router_path)) !=
      WLS_TRANSPORT_OK) {
    close(queue_fd);
    close(channel_fd);
    unlink(config->channel_path);
    server->wait_fd = -1;
    server->channel_fd = -1;
    server->datagram_worker_mode = 0;
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  server->listener_io.fd = channel_fd;
  server->listener_io.owns_fd = 0;
  server->listener_io.datagram_channel = 1;
  server->listener_io.server = server;
  wls_last_error[0] = '\0';
  return WLS_TRANSPORT_OK;
#endif
}

static int wls_server_effective_poll_timeout(
  const wls_h3_server_impl *server, int timeout_ms, ngtcp2_tstamp now) {
  int effective_timeout = timeout_ms;
  for (const wls_h3_connection *connection = server->connections; connection;
       connection = connection->next) {
    if (server->draining &&
        connection->shutdown_notice_sent &&
        !connection->graceful_shutdown_started) {
      ngtcp2_tstamp shutdown_at =
        connection->shutdown_notice_sent_at +
        wls_http3_shutdown_notice_delay(connection);
      if (shutdown_at <= now) {
        return 0;
      }
      uint64_t shutdown_delta_ms =
        (shutdown_at - now + NGTCP2_MILLISECONDS - 1) /
        NGTCP2_MILLISECONDS;
      if (shutdown_delta_ms < (uint64_t)effective_timeout) {
        effective_timeout = (int)shutdown_delta_ms;
      }
    }
    if (connection->terminal_close_requested &&
        !connection->terminal_close_handed_off) {
      ngtcp2_tstamp retry_at =
        connection->terminal_close_last_attempt_at == 0
          ? now
          : connection->terminal_close_last_attempt_at +
              WLS_H3_TERMINAL_CLOSE_RETRY_INTERVAL;
      if (retry_at <= now) {
        return 0;
      }
      uint64_t retry_delta_ms =
        (retry_at - now + NGTCP2_MILLISECONDS - 1) /
        NGTCP2_MILLISECONDS;
      if (retry_delta_ms < (uint64_t)effective_timeout) {
        effective_timeout = (int)retry_delta_ms;
      }
    }
    ngtcp2_tstamp expiry = ngtcp2_conn_get_expiry2(connection->quic);
    if (expiry <= now) {
      if (!(connection->terminal_close_requested &&
            !connection->terminal_close_handed_off)) {
        return 0;
      }
    } else {
      uint64_t delta_ms = (expiry - now + NGTCP2_MILLISECONDS - 1) /
                          NGTCP2_MILLISECONDS;
      if (delta_ms < (uint64_t)effective_timeout) {
        effective_timeout = (int)delta_ms;
      }
    }
  }
  return effective_timeout;
}

static int wls_server_process_datagram(
  wls_h3_server_impl *server, wls_h3_io_path *io_path,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, const uint8_t *datagram,
  size_t datagram_length, uint64_t authorization_id) {
  ++server->received_datagrams;
  if (datagram_length < 21) {
    return WLS_TRANSPORT_OK;
  }

  ngtcp2_version_cid version_cid;
  int decode_result = ngtcp2_pkt_decode_version_cid(
    &version_cid, datagram, datagram_length, WLS_H3_SERVER_SCID_LENGTH);
  if (decode_result != 0) {
    return WLS_TRANSPORT_OK;
  }

  wls_h3_connection *connection = wls_cid_lookup(
    server, version_cid.dcid, version_cid.dcidlen);
  if (connection &&
      (!wls_sockaddr_equal(&connection->local_address,
                           connection->local_address_length,
                           local_address, local_address_length) ||
       !wls_sockaddr_equal(&connection->remote_address,
                           connection->remote_address_length,
                           remote_address, remote_address_length))) {
    /*
     * The Darwin packet channel multiplexes every connection through one
     * virtual I/O path. A CID match alone is therefore insufficient: retain the
     * exact local/remote path accepted for this connection and reject packets
     * that attempt address migration or cross-connection CID injection.
     */
    ++server->peer_mismatch_drops;
    return WLS_TRANSPORT_OK;
  }
  if (!connection) {
    if (server->draining) {
      ++server->rejected_initials;
      return WLS_TRANSPORT_OK;
    }
#if defined(__linux__)
    if (server->linux_route_mode &&
        (server->linux_route.state != WLS_H3_LINUX_ROUTE_ACTIVE ||
         io_path != &server->listener_io)) {
      ++server->rejected_initials;
      return WLS_TRANSPORT_OK;
    }
#endif
    ngtcp2_pkt_hd header;
    if (ngtcp2_accept(&header, datagram, datagram_length) != 0) {
      return WLS_TRANSPORT_OK;
    }
    wls_h3_io_path *new_connection_io = io_path;
#if defined(__linux__)
    if (server->linux_route_mode) {
      new_connection_io = &server->connection_io;
    }
#endif
    ++server->accepted_initials;
    if (header.tokenlen == 0) {
      int retry_result = wls_send_retry(
        server, new_connection_io, &header, remote_address, remote_address_length,
        datagram_length);
      if (retry_result != WLS_TRANSPORT_OK &&
          retry_result != WLS_TRANSPORT_AGAIN) {
        ++server->rejected_initials;
      }
      return WLS_TRANSPORT_OK;
    }

    ngtcp2_cid original_dcid;
    if (wls_verify_retry(server, &header, remote_address,
                         remote_address_length,
                         &original_dcid) != WLS_TRANSPORT_OK) {
      ++server->rejected_initials;
      return WLS_TRANSPORT_OK;
    }
    if (server->active_connections >= server->max_connections) {
      ++server->capacity_rejections;
      return WLS_TRANSPORT_OK;
    }
    if (server->datagram_worker_mode && authorization_id == 0) {
      ++server->rejected_initials;
      return WLS_TRANSPORT_OK;
    }
    connection = wls_connection_create(
      server, &header, &original_dcid, new_connection_io, local_address,
      local_address_length, remote_address, remote_address_length,
      authorization_id);
    if (!connection) {
      ++server->connection_errors;
      return WLS_TRANSPORT_OK;
    }
  }

  int feed_result = wls_connection_feed(
    connection, local_address, local_address_length, remote_address,
    remote_address_length, datagram, datagram_length);
  if (feed_result != WLS_TRANSPORT_OK) {
    /*
     * A fatal parser/crypto error starts an explicit terminal-close lifecycle.
     * Keep the CID authorization alive until the close packet is handed off and
     * the QUIC closing period expires; otherwise multiplexed clients observe a
     * silent UDP black hole.
     */
    (void)wls_connection_send_drain_close(connection);
    ++server->connection_errors;
  }
  return WLS_TRANSPORT_OK;
}

static int wls_server_drain_io_path(wls_h3_server_impl *server,
                                    wls_h3_io_path *io_path,
                                    unsigned max_datagrams) {
  if (!io_path || io_path->fd < 0) {
    return WLS_TRANSPORT_NOT_BOUND;
  }

  unsigned drained = 0;
  while (drained < max_datagrams) {
    uint8_t datagram[WLS_H3_MAX_PACKET_SIZE];
    struct sockaddr_storage peer;
    socklen_t peer_length = sizeof(peer);
    memset(&peer, 0, sizeof(peer));
    ssize_t received = recvfrom(
      io_path->fd, datagram, sizeof(datagram), 0,
      (struct sockaddr *)&peer, &peer_length);
    if (received < 0) {
      if (errno == EAGAIN || errno == EWOULDBLOCK) {
        break;
      }
      if (errno == EINTR) {
        continue;
      }
      wls_set_error("receive QUIC datagram: %s", strerror(errno));
      return WLS_TRANSPORT_SOCKET_ERROR;
    }
    if (received == 0 &&
        (peer_length == 0 ||
         (peer.ss_family != AF_INET && peer.ss_family != AF_INET6))) {
      /*
       * A legal zero-length UDP datagram still carries a complete peer.
       * Treat only the impossible zero/no-peer combination as terminal so a
       * malformed readiness result cannot keep a level-triggered fd hot.
       */
      wls_set_error("QUIC UDP listener reached terminal zero-peer state");
      ++server->connection_errors;
      return WLS_TRANSPORT_SOCKET_ERROR;
    }
    ++drained;
    int process_result = wls_server_process_datagram(
      server, io_path, &io_path->local_address, io_path->local_address_length,
      &peer, peer_length, datagram, (size_t)received, 0);
    if (process_result != WLS_TRANSPORT_OK) {
      return process_result;
    }
  }
  return drained != 0 ? (int)drained : WLS_TRANSPORT_AGAIN;
}

int wls_h3_server_begin_drain(wls_h3_server *server) {
  if (!server || server->bound_port == 0 ||
      (server->listener_io.fd < 0 && server->channel_fd < 0)) {
    return WLS_TRANSPORT_NOT_BOUND;
  }
#if defined(__linux__)
  if (server->linux_route_mode) {
    char error[sizeof(wls_last_error)];
    memset(error, 0, sizeof(error));
    int route_result = wls_linux_h3_route_deactivate(
      &server->linux_route, error, sizeof(error));
    if (route_result != WLS_TRANSPORT_OK) {
      wls_set_error("%s", error[0] != 0
                            ? error
                            : "Linux HTTP/3 route deactivation failed");
      return route_result;
    }
  }
#endif
  if (!server->draining) {
    server->draining = 1;
    server->drain_started_at = wls_now();
  }
  for (wls_h3_connection *connection = server->connections; connection;
       connection = connection->next) {
    if (wls_http3_submit_shutdown_notice(connection) != WLS_TRANSPORT_OK) {
      return WLS_TRANSPORT_HTTP3_ERROR;
    }
    if (wls_connection_flush(connection) != WLS_TRANSPORT_OK) {
      return WLS_TRANSPORT_QUIC_ERROR;
    }
  }
  return WLS_TRANSPORT_OK;
}

static int wls_server_maintain_connections(wls_h3_server_impl *server,
                                           ngtcp2_tstamp now) {
  wls_h3_connection **connection_cursor = &server->connections;
  while (*connection_cursor) {
    wls_h3_connection *connection = *connection_cursor;
    ngtcp2_tstamp expiry = ngtcp2_conn_get_expiry2(connection->quic);
    int in_draining =
      ngtcp2_conn_in_draining_period2(connection->quic);
    int in_closing =
      ngtcp2_conn_in_closing_period2(connection->quic);

    if (in_draining) {
      if (expiry <= now) {
        *connection_cursor = connection->next;
        wls_connection_destroy(connection);
        continue;
      }
      connection_cursor = &connection->next;
      continue;
    }

    if (in_closing) {
      if (connection->terminal_close_generated &&
          !connection->terminal_close_handed_off &&
          (connection->terminal_close_last_attempt_at == 0 ||
           now >= connection->terminal_close_last_attempt_at +
                    WLS_H3_TERMINAL_CLOSE_RETRY_INTERVAL)) {
        int was_handed_off = connection->terminal_close_handed_off;
        int handoff_result =
          wls_connection_handoff_terminal_close(connection);
        if (handoff_result == WLS_TRANSPORT_OK && !was_handed_off &&
            connection->terminal_close_handed_off &&
            connection->rotation_requested &&
            !connection->rotation_completion_recorded) {
          connection->rotation_completion_recorded = 1;
          ++server->connection_rotation_completions;
        } else if (handoff_result != WLS_TRANSPORT_OK &&
                   handoff_result != WLS_TRANSPORT_AGAIN &&
                   !connection->terminal_close_error_recorded) {
          connection->terminal_close_error_recorded = 1;
          wls_record_connection_error(
            connection, WLS_H3_CONNECTION_ERROR_STAGE_FLUSH,
            handoff_result);
          ++server->connection_errors;
        }
      }
      if (expiry <= now &&
          (!connection->terminal_close_requested ||
           connection->terminal_close_handed_off)) {
        *connection_cursor = connection->next;
        wls_connection_destroy(connection);
        continue;
      }
      connection_cursor = &connection->next;
      continue;
    }

    if (connection->terminal_close_requested) {
      if (connection->terminal_close_last_attempt_at == 0 ||
          now >= connection->terminal_close_last_attempt_at +
                   WLS_H3_TERMINAL_CLOSE_RETRY_INTERVAL) {
        int was_handed_off = connection->terminal_close_handed_off;
        int close_result = wls_connection_send_drain_close(connection);
        if (close_result == WLS_TRANSPORT_OK && !was_handed_off &&
            connection->terminal_close_handed_off &&
            connection->rotation_requested &&
            !connection->rotation_completion_recorded) {
          connection->rotation_completion_recorded = 1;
          ++server->connection_rotation_completions;
        } else if (close_result != WLS_TRANSPORT_OK &&
                   close_result != WLS_TRANSPORT_AGAIN &&
                   !connection->terminal_close_error_recorded) {
          connection->terminal_close_error_recorded = 1;
          wls_record_connection_error(
            connection, WLS_H3_CONNECTION_ERROR_STAGE_FLUSH,
            close_result);
          ++server->connection_errors;
        }
      }
      connection_cursor = &connection->next;
      continue;
    }
    if ((server->draining || connection->rotation_requested) &&
        connection->http3) {
      int notice_result = wls_http3_submit_shutdown_notice(connection);
      if (notice_result != WLS_TRANSPORT_OK) {
        wls_record_connection_error(
          connection, WLS_H3_CONNECTION_ERROR_STAGE_CALLBACK,
          notice_result);
        (void)wls_connection_send_drain_close(connection);
        ++server->connection_errors;
        connection_cursor = &connection->next;
        continue;
      }
      if (server->draining && !connection->graceful_shutdown_started &&
          now >= connection->shutdown_notice_sent_at +
                   wls_http3_shutdown_notice_delay(connection)) {
        int shutdown_result = nghttp3_conn_shutdown(connection->http3);
        if (shutdown_result != 0) {
          wls_record_connection_error(
            connection, WLS_H3_CONNECTION_ERROR_STAGE_CALLBACK,
            shutdown_result);
          wls_set_error("nghttp3_conn_shutdown: %s",
                        nghttp3_strerror(shutdown_result));
          (void)wls_connection_send_drain_close(connection);
          ++server->connection_errors;
          connection_cursor = &connection->next;
          continue;
        }
        connection->graceful_shutdown_started = 1;
      }
    }

    int http3_drained =
      connection->graceful_shutdown_started && connection->http3 &&
      nghttp3_conn_is_drained2(connection->http3);
    int application_drained =
      http3_drained && connection->streams == NULL &&
      connection->pending_packet == NULL;
    int server_close_allowed =
      server->draining && connection->final_goaway_flushed &&
      application_drained;
    if (server_close_allowed) {
      int was_handed_off = connection->terminal_close_handed_off;
      int close_result = wls_connection_send_drain_close(connection);
      if (close_result == WLS_TRANSPORT_OK && !was_handed_off &&
          connection->terminal_close_handed_off &&
          connection->rotation_requested &&
          !connection->rotation_completion_recorded) {
        connection->rotation_completion_recorded = 1;
        ++server->connection_rotation_completions;
      } else if (close_result != WLS_TRANSPORT_OK &&
                 close_result != WLS_TRANSPORT_AGAIN &&
                 !connection->terminal_close_error_recorded) {
        connection->terminal_close_error_recorded = 1;
        wls_record_connection_error(
          connection, WLS_H3_CONNECTION_ERROR_STAGE_FLUSH, close_result);
        ++server->connection_errors;
      }
      connection_cursor = &connection->next;
      continue;
    }

    if (expiry <= now) {
      int result = ngtcp2_conn_handle_expiry(connection->quic, now);
      if (result == NGTCP2_ERR_IDLE_CLOSE) {
        *connection_cursor = connection->next;
        wls_connection_destroy(connection);
        continue;
      }
      if (result != 0 && result != NGTCP2_ERR_DRAINING &&
          result != NGTCP2_ERR_CLOSING) {
        wls_record_connection_error(
          connection, WLS_H3_CONNECTION_ERROR_STAGE_EXPIRY, result);
        wls_set_error("ngtcp2_conn_handle_expiry: %s",
                      ngtcp2_strerror(result));
        (void)wls_connection_send_drain_close(connection);
        ++server->connection_errors;
        connection_cursor = &connection->next;
        continue;
      }
    }
    int flush_result = wls_connection_flush(connection);
    if (flush_result != WLS_TRANSPORT_OK) {
      (void)wls_connection_send_drain_close(connection);
      ++server->connection_errors;
      connection_cursor = &connection->next;
      continue;
    }
    if (connection->graceful_shutdown_started &&
        !connection->final_goaway_flushed &&
        !connection->pending_packet &&
        !ngtcp2_conn_in_closing_period2(connection->quic) &&
        !ngtcp2_conn_in_draining_period2(connection->quic)) {
      connection->final_goaway_flushed = 1;
    }
    connection_cursor = &connection->next;
  }
  return WLS_TRANSPORT_OK;
}

static int wls_h3_server_poll_datagram_worker(wls_h3_server_impl *server,
                                          int timeout_ms) {
#if !defined(__APPLE__)
  (void)server;
  (void)timeout_ms;
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  if (!server->datagram_worker_mode || server->wait_fd < 0 ||
      server->channel_fd < 0) {
    return WLS_TRANSPORT_NOT_BOUND;
  }
  ngtcp2_tstamp now = wls_now();
  int effective_timeout = wls_server_effective_poll_timeout(
    server, timeout_ms, now);
  struct timespec timeout = {
    .tv_sec = effective_timeout / 1000,
    .tv_nsec = (long)(effective_timeout % 1000) * 1000000L,
  };
  struct kevent events[WLS_H3_KQUEUE_EVENT_BATCH];
  int event_count;
  do {
    event_count = kevent(server->wait_fd, NULL, 0, events,
                         WLS_H3_KQUEUE_EVENT_BATCH, &timeout);
  } while (event_count < 0 && errno == EINTR);
  if (event_count < 0) {
    wls_set_error("kevent HTTP/3 packet Worker: %s", strerror(errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }

  int drained = 0;
  for (int index = 0; index < event_count; ++index) {
    int ready_fd = (int)events[index].ident;
    if ((events[index].flags & EV_EOF) != 0 &&
        ready_fd == server->channel_fd) {
      wls_set_error("HTTP/3 packet channel reached EOF");
      return WLS_TRANSPORT_SOCKET_ERROR;
    }
    if ((events[index].flags & EV_ERROR) != 0 && events[index].data != 0) {
      if (ready_fd == server->channel_fd) {
        wls_set_error("HTTP/3 packet-channel kqueue error: %s",
                      strerror((int)events[index].data));
        return WLS_TRANSPORT_SOCKET_ERROR;
      }
      continue;
    }
    if (ready_fd == server->channel_fd) {
      int result = wls_server_drain_channel(
        server, WLS_H3_KQUEUE_EVENT_BATCH);
      if (result < 0) {
        return result;
      }
      if (result > 0) {
        drained += result;
      }
      continue;
    }
  }

  int maintain_result = wls_server_maintain_connections(server, wls_now());
  if (maintain_result != WLS_TRANSPORT_OK) {
    return maintain_result;
  }
  return drained != 0 ? drained : WLS_TRANSPORT_AGAIN;
#endif
}

int wls_h3_server_poll(wls_h3_server *server, int timeout_ms) {
  if (!server) {
    wls_set_error("H3 server is unavailable");
    return WLS_TRANSPORT_NOT_BOUND;
  }
  if (server->datagram_worker_mode) {
    if (timeout_ms < 0) {
      timeout_ms = 0;
    }
    return wls_h3_server_poll_datagram_worker(server, timeout_ms);
  }
  if (server->listener_io.fd < 0) {
    wls_set_error("H3 server is not bound");
    return WLS_TRANSPORT_NOT_BOUND;
  }
  if (timeout_ms < 0) {
    timeout_ms = 0;
  }

  ngtcp2_tstamp now = wls_now();
  int effective_timeout = wls_server_effective_poll_timeout(
    server, timeout_ms, now);

#if defined(__linux__)
  if (server->linux_route_mode) {
    struct pollfd descriptors[2] = {
      {
        .fd = server->connection_io.fd,
        .events = POLLIN,
        .revents = 0,
      },
      {
        .fd = server->listener_io.fd,
        .events = POLLIN,
        .revents = 0,
      },
    };
    int route_poll_result;
    do {
      route_poll_result = poll(descriptors, 2, effective_timeout);
    } while (route_poll_result < 0 && errno == EINTR);
    if (route_poll_result < 0) {
      wls_set_error("poll Linux HTTP/3 route: %s", strerror(errno));
      return WLS_TRANSPORT_SOCKET_ERROR;
    }

    int drained_total = 0;
    wls_h3_io_path *paths[2] = {
      &server->connection_io,
      &server->listener_io,
    };
    for (size_t index = 0; index < 2; ++index) {
      if ((descriptors[index].revents &
           (POLLERR | POLLHUP | POLLNVAL)) != 0) {
        wls_set_error("Linux HTTP/3 UDP route became unavailable");
        return WLS_TRANSPORT_SOCKET_ERROR;
      }
      if ((descriptors[index].revents & POLLIN) == 0) {
        continue;
      }
      int route_drained = wls_server_drain_io_path(
        server, paths[index], WLS_H3_MAX_READ_BATCH);
      if (route_drained < 0) {
        return route_drained;
      }
      if (route_drained > 0) {
        drained_total += route_drained;
      }
    }

    int route_maintain_result =
      wls_server_maintain_connections(server, wls_now());
    if (route_maintain_result != WLS_TRANSPORT_OK) {
      return route_maintain_result;
    }
    return drained_total != 0
      ? drained_total : WLS_TRANSPORT_AGAIN;
  }
#endif
  struct pollfd descriptor = {
    .fd = server->listener_io.fd,
    .events = POLLIN,
    .revents = 0,
  };
  int poll_result;
  do {
    poll_result = poll(&descriptor, 1, effective_timeout);
  } while (poll_result < 0 && errno == EINTR);
  if (poll_result < 0) {
    wls_set_error("poll: %s", strerror(errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }

  int drained = WLS_TRANSPORT_AGAIN;
  if (poll_result > 0 && (descriptor.revents & POLLIN)) {
    drained = wls_server_drain_io_path(server, &server->listener_io,
                                       WLS_H3_MAX_READ_BATCH);
    if (drained < 0) {
      return drained;
    }
  }

  int maintain_result = wls_server_maintain_connections(server, wls_now());
  if (maintain_result != WLS_TRANSPORT_OK) {
    return maintain_result;
  }
  return drained;
}

int wls_h3_server_next_request(wls_h3_server *server,
                               wls_h3_request *request) {
  if (!server || !request || request->struct_size != sizeof(*request)) {
    wls_set_error("wls_h3_request struct_size mismatch");
    return WLS_TRANSPORT_ABI_MISMATCH;
  }
  wls_h3_stream *stream = server->request_head;
  if (!stream) {
    return WLS_TRANSPORT_AGAIN;
  }

  char host[NI_MAXHOST];
  char service[NI_MAXSERV];
  int name_result = getnameinfo(
    (struct sockaddr *)&stream->connection->remote_address,
    stream->connection->remote_address_length, host, sizeof(host),
    service, sizeof(service), NI_NUMERICHOST | NI_NUMERICSERV);
  if (name_result != 0) {
    wls_set_error("getnameinfo peer: %s", gai_strerror(name_result));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  char peer[NI_MAXHOST + NI_MAXSERV + 4];
  int peer_length = stream->connection->remote_address.ss_family == AF_INET6
                      ? snprintf(peer, sizeof(peer), "[%s]:%s", host, service)
                      : snprintf(peer, sizeof(peer), "%s:%s", host, service);
  if (peer_length < 0) {
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }

  request->peer_length = (size_t)peer_length;
  request->raw_request_length = stream->raw_request_length;
  request->token = stream->token;
  request->connection_id = stream->connection->connection_id;
  request->stream_id = stream->stream_id;
  request->end_stream = 1;
  request->flags = WLS_H3_REQUEST_FLAG_END_STREAM;
  if (!request->peer || request->peer_capacity <= (size_t)peer_length ||
      !request->raw_request ||
      request->raw_request_capacity < stream->raw_request_length) {
    wls_set_error("H3 request output buffers are too small");
    return WLS_TRANSPORT_BUFFER_TOO_SMALL;
  }
  memcpy(request->peer, peer, (size_t)peer_length + 1);
  memcpy(request->raw_request, stream->raw_request,
         stream->raw_request_length);
  if (request->raw_request_capacity > stream->raw_request_length) {
    request->raw_request[stream->raw_request_length] = 0;
  }

  server->request_head = stream->queue_next;
  if (!server->request_head) {
    server->request_tail = NULL;
  }
  stream->queue_next = NULL;
  stream->queued = 0;
  if (server->queued_requests != 0) {
    --server->queued_requests;
  }
  return WLS_TRANSPORT_OK;
}

int wls_h3_server_respond(wls_h3_server *server,
                          const wls_h3_response *response) {
  if (!server || !response ||
      response->struct_size != sizeof(*response) ||
      !response->raw_response || response->raw_response_length == 0) {
    wls_set_error("invalid wls_h3_response arguments or struct_size");
    return WLS_TRANSPORT_ABI_MISMATCH;
  }
  wls_h3_stream *stream = wls_token_lookup(server, response->token);
  if (!stream || stream->response_submitted || !stream->connection->http3) {
    wls_set_error("H3 response token was not found or already used");
    return WLS_TRANSPORT_NOT_FOUND;
  }

  size_t separator = SIZE_MAX;
  for (size_t index = 0; index + 3 < response->raw_response_length; ++index) {
    if (response->raw_response[index] == 13 &&
        response->raw_response[index + 1] == 10 &&
        response->raw_response[index + 2] == 13 &&
        response->raw_response[index + 3] == 10) {
      separator = index;
      break;
    }
  }
  if (separator == SIZE_MAX ||
      separator > server->max_request_header_bytes) {
    wls_set_error("raw HTTP response has no bounded header terminator");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  size_t body_offset = separator + 4;
  size_t body_length = response->raw_response_length - body_offset;

  char *header_copy = malloc(separator + 1);
  nghttp3_nv *fields = calloc(separator / 2 + 4, sizeof(*fields));
  if (!header_copy || !fields) {
    free(header_copy);
    free(fields);
    return WLS_TRANSPORT_NOMEM;
  }
  memcpy(header_copy, response->raw_response, separator);
  header_copy[separator] = 0;

  char *first_end = strstr(header_copy, "\r\n");
  if (!first_end) {
    free(header_copy);
    free(fields);
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  *first_end = 0;
  char *status_cursor = strchr(header_copy, 32);
  while (status_cursor && *status_cursor == 32) {
    ++status_cursor;
  }
  if (!status_cursor || strlen(status_cursor) < 3 ||
      status_cursor[0] < 49 || status_cursor[0] > 57 ||
      status_cursor[1] < 48 || status_cursor[1] > 57 ||
      status_cursor[2] < 48 || status_cursor[2] > 57) {
    free(header_copy);
    free(fields);
    wls_set_error("invalid HTTP response status line");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  char status_value[4] = {
    status_cursor[0], status_cursor[1], status_cursor[2], 0
  };
  fields[0].name = (const uint8_t *)":status";
  fields[0].value = (const uint8_t *)status_value;
  fields[0].namelen = 7;
  fields[0].valuelen = 3;
  fields[0].flags = NGHTTP3_NV_FLAG_NONE;
  size_t field_count = 1;
  int has_content_length = 0;
  uint64_t declared_content_length = 0;

  char *line = first_end + 2;
  char *header_end = header_copy + separator;
  while (line < header_end && *line) {
    char *line_end = strstr(line, "\r\n");
    int last_line = line_end == NULL;
    if (!line_end) {
      line_end = header_end;
    }
    *line_end = 0;
    char *colon = strchr(line, 58);
    if (!colon || colon == line) {
      free(header_copy);
      free(fields);
      return WLS_TRANSPORT_INVALID_ARGUMENT;
    }
    *colon = 0;
    char *value = colon + 1;
    while (*value == 32 || *value == 9) {
      ++value;
    }
    char *value_end = value + strlen(value);
    while (value_end > value &&
           (value_end[-1] == 32 || value_end[-1] == 9)) {
      *--value_end = 0;
    }
    for (char *cursor = line; *cursor; ++cursor) {
      unsigned char byte = (unsigned char)*cursor;
      if (byte <= 32 || byte >= 127 || byte == 58) {
        free(header_copy);
        free(fields);
        return WLS_TRANSPORT_INVALID_ARGUMENT;
      }
      if (byte >= 65 && byte <= 90) {
        *cursor = (char)(byte + 32);
      }
    }
    for (char *cursor = value; *cursor; ++cursor) {
      unsigned char byte = (unsigned char)*cursor;
      if (byte == 10 || byte == 13 || byte == 127 ||
          (byte < 32 && byte != 9)) {
        free(header_copy);
        free(fields);
        return WLS_TRANSPORT_INVALID_ARGUMENT;
      }
    }

    int strip = strcasecmp(line, "connection") == 0 ||
                strcasecmp(line, "keep-alive") == 0 ||
                strcasecmp(line, "proxy-connection") == 0 ||
                strcasecmp(line, "upgrade") == 0;
    if (strcasecmp(line, "transfer-encoding") == 0) {
      free(header_copy);
      free(fields);
      wls_set_error("chunked/transfer-encoded response is unsupported on H3");
      return WLS_TRANSPORT_UNSUPPORTED;
    }
    if (strcasecmp(line, "content-length") == 0) {
      if (has_content_length || value[0] == 0) {
        free(header_copy);
        free(fields);
        return WLS_TRANSPORT_INVALID_ARGUMENT;
      }
      for (const unsigned char *cursor = (const unsigned char *)value;
           *cursor; ++cursor) {
        if (*cursor < 48 || *cursor > 57 ||
            declared_content_length >
              (UINT64_MAX - (uint64_t)(*cursor - 48)) / 10) {
          free(header_copy);
          free(fields);
          return WLS_TRANSPORT_INVALID_ARGUMENT;
        }
        declared_content_length = declared_content_length * 10 +
                                  (uint64_t)(*cursor - 48);
      }
      has_content_length = 1;
    }
    if (!strip) {
      fields[field_count].name = (const uint8_t *)line;
      fields[field_count].value = (const uint8_t *)value;
      fields[field_count].namelen = strlen(line);
      fields[field_count].valuelen = strlen(value);
      fields[field_count].flags = NGHTTP3_NV_FLAG_NONE;
      ++field_count;
    }
    if (last_line) {
      break;
    }
    line = line_end + 2;
  }
  if (has_content_length && declared_content_length != body_length) {
    free(header_copy);
    free(fields);
    wls_set_error("HTTP response Content-Length does not match body");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  char content_length_value[32];
  if (!has_content_length) {
    snprintf(content_length_value, sizeof(content_length_value), "%zu",
             body_length);
    fields[field_count].name = (const uint8_t *)"content-length";
    fields[field_count].value = (const uint8_t *)content_length_value;
    fields[field_count].namelen = 14;
    fields[field_count].valuelen = strlen(content_length_value);
    fields[field_count].flags = NGHTTP3_NV_FLAG_NONE;
    ++field_count;
  }

  free(stream->response_body);
  stream->response_body = NULL;
  stream->response_body_length = body_length;
  if (body_length != 0) {
    stream->response_body = malloc(body_length);
    if (!stream->response_body) {
      free(header_copy);
      free(fields);
      return WLS_TRANSPORT_NOMEM;
    }
    memcpy(stream->response_body, response->raw_response + body_offset,
           body_length);
  }
  nghttp3_data_reader reader = {
    .read_data = wls_http3_read_response,
  };
  int submit_result = nghttp3_conn_submit_response(
    stream->connection->http3, stream->stream_id, fields, field_count,
    body_length != 0 ? &reader : NULL);
  free(header_copy);
  free(fields);
  if (submit_result != 0) {
    wls_set_error("nghttp3_conn_submit_response: %s",
                  nghttp3_strerror(submit_result));
    return WLS_TRANSPORT_HTTP3_ERROR;
  }
  stream->response_submitted = 1;
  wls_token_remove(server, stream->token);
  return wls_connection_flush(stream->connection);
}

int wls_h3_server_close_request(wls_h3_server *server, uint64_t token,
                                uint64_t app_error_code) {
  if (!server || token == 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  wls_h3_stream *stream = wls_token_lookup(server, token);
  if (!stream) {
    return WLS_TRANSPORT_NOT_FOUND;
  }
  ngtcp2_conn_shutdown_stream_read(stream->connection->quic, 0,
                                    stream->stream_id, app_error_code);
  ngtcp2_conn_shutdown_stream_write(stream->connection->quic, 0,
                                     stream->stream_id, app_error_code);
  wls_token_remove(server, token);
  stream->response_submitted = 1;
  return wls_connection_flush(stream->connection);
}

int wls_h3_server_get_stats(const wls_h3_server *server,
                            wls_h3_server_stats *stats) {
  if (!server || !stats || stats->struct_size != sizeof(*stats)) {
    wls_set_error("wls_h3_server_stats struct_size mismatch");
    return WLS_TRANSPORT_ABI_MISMATCH;
  }
  stats->received_datagrams = server->received_datagrams;
  stats->accepted_initials = server->accepted_initials;
  stats->active_connections = server->active_connections;
  stats->active_streams = server->active_streams;
  stats->queued_requests = server->queued_requests;
  stats->retry_sent = server->retry_sent;
  stats->retry_validated = server->retry_validated;
  stats->rejected_initials = server->rejected_initials;
  stats->connection_errors = server->connection_errors;
  stats->connection_read_errors = server->connection_read_errors;
  stats->connection_flush_errors = server->connection_flush_errors;
  stats->connection_callback_errors = server->connection_callback_errors;
  stats->connection_expiry_errors = server->connection_expiry_errors;
  stats->draining_reads = server->draining_reads;
  stats->closing_reads = server->closing_reads;
  stats->flush_skipped_draining = server->flush_skipped_draining;
  stats->flush_skipped_closing = server->flush_skipped_closing;
  stats->write_stream_not_found = server->write_stream_not_found;
  stats->connection_rotation_requests = server->connection_rotation_requests;
  stats->connection_rotation_goaways = server->connection_rotation_goaways;
  stats->connection_rotation_completions =
    server->connection_rotation_completions;
  stats->max_connection_request_count = server->max_connection_request_count;
  stats->last_connection_error_stage = server->last_connection_error_stage;
  stats->last_connection_error_code = server->last_connection_error_code;
  stats->capacity_rejections = server->capacity_rejections;
  stats->peer_mismatch_drops = server->peer_mismatch_drops;
  stats->routed_datagrams = server->routed_datagrams;
  stats->channel_drops = server->channel_drops;
  stats->channel_auth_failures = server->channel_auth_failures;
  return WLS_TRANSPORT_OK;
}

void wls_h3_server_destroy(wls_h3_server *server) {
  if (!server) {
    return;
  }
  while (server->connections) {
    wls_h3_connection *connection = server->connections;
    server->connections = connection->next;
    wls_connection_destroy(connection);
  }
  if (server->channel_fd >= 0) {
    close(server->channel_fd);
    server->channel_fd = -1;
  }
  if (server->wait_fd >= 0) {
    close(server->wait_fd);
    server->wait_fd = -1;
  }
  if (server->channel_path[0] != '\0') {
    unlink(server->channel_path);
    server->channel_path[0] = '\0';
  }
#if defined(__linux__)
  if (server->linux_route_mode) {
    wls_linux_h3_route_close(&server->linux_route);
    wls_io_path_close(&server->connection_io);
    server->linux_route_mode = 0;
  }
#endif
  OPENSSL_cleanse(server->channel_key, sizeof(server->channel_key));
  wls_io_path_close(&server->listener_io);
  free(server->cid_slots);
  free(server->token_slots);
  wls_tls_context_release(server->tls_context);
  free(server);
}

static void wls_router_close_endpoints(wls_h3_datagram_router *router) {
  if (!router) {
    return;
  }
  for (size_t index = 0; index < router->endpoint_count; ++index) {
    if (router->endpoints[index].channel_fd >= 0) {
#if defined(__APPLE__)
      if (router->wait_fd >= 0) {
        (void)wls_kqueue_set_read(
          router->wait_fd, router->endpoints[index].channel_fd, 0);
      }
#endif
      close(router->endpoints[index].channel_fd);
      router->endpoints[index].channel_fd = -1;
    }
    if (router->endpoints[index].router_path[0] != '\0') {
      unlink(router->endpoints[index].router_path);
      router->endpoints[index].router_path[0] = '\0';
    }
    OPENSSL_cleanse(router->endpoints[index].channel_key,
                    sizeof(router->endpoints[index].channel_key));
  }
  memset(router->endpoints, 0, sizeof(router->endpoints));
  router->endpoint_count = 0;
}

int wls_h3_datagram_router_new(
  const wls_h3_datagram_router_config *config,
  wls_h3_datagram_router **out_router) {
  if (!config || !out_router || config->struct_size != sizeof(*config) ||
      !config->retry_secret ||
      config->retry_secret_length != WLS_H3_RETRY_SECRET_LENGTH) {
    wls_set_error("invalid HTTP/3 Datagram Router configuration");
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
#if !defined(__APPLE__)
  wls_set_error("HTTP/3 Datagram Router is required only on Darwin");
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  wls_h3_datagram_router *router = calloc(1, sizeof(*router));
  if (!router) {
    return WLS_TRANSPORT_NOMEM;
  }
  router->listener_io.fd = -1;
  router->wait_fd = -1;
  router->authorized_paths = calloc(
    WLS_H3_ROUTER_PATH_CAPACITY, sizeof(*router->authorized_paths));
  if (!router->authorized_paths) {
    free(router);
    return WLS_TRANSPORT_NOMEM;
  }
  router->pending_egress = calloc(
    WLS_H3_ROUTER_EGRESS_QUEUE_CAPACITY,
    sizeof(*router->pending_egress));
  if (!router->pending_egress) {
    free(router->authorized_paths);
    free(router);
    return WLS_TRANSPORT_NOMEM;
  }
  router->max_initial_datagram_bytes =
    config->max_initial_datagram_bytes != 0
      ? config->max_initial_datagram_bytes
      : WLS_H3_DEFAULT_MAX_INITIAL_DATAGRAM_BYTES;
  if (router->max_initial_datagram_bytes >
      WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES) {
    router->max_initial_datagram_bytes =
      WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES;
  }
  router->retry_token_lifetime_ms =
    config->retry_token_lifetime_ms != 0
      ? config->retry_token_lifetime_ms
      : WLS_H3_DEFAULT_RETRY_TOKEN_LIFETIME_MS;
  memcpy(router->retry_secret, config->retry_secret,
         WLS_H3_RETRY_SECRET_LENGTH);
  for (size_t index = 0; index < WLS_H3_MAX_ROUTER_ENDPOINTS; ++index) {
    router->endpoints[index].channel_fd = -1;
  }
  *out_router = router;
  wls_last_error[0] = '\0';
  return WLS_TRANSPORT_OK;
#endif
}

int wls_h3_datagram_router_bind(wls_h3_datagram_router *router,
                              const char *host, uint16_t port) {
#if !defined(__APPLE__)
  (void)router;
  (void)host;
  (void)port;
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  if (!router || !host || router->listener_io.fd >= 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  char service[6];
  snprintf(service, sizeof(service), "%u", (unsigned)port);
  struct addrinfo hints;
  memset(&hints, 0, sizeof(hints));
  hints.ai_family = AF_UNSPEC;
  hints.ai_socktype = SOCK_DGRAM;
  hints.ai_protocol = IPPROTO_UDP;
  hints.ai_flags = AI_NUMERICSERV;
  struct addrinfo *addresses = NULL;
  int lookup_result = getaddrinfo(host, service, &hints, &addresses);
  if (lookup_result != 0) {
    wls_set_error("getaddrinfo HTTP/3 Datagram Router: %s",
                  gai_strerror(lookup_result));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }

  int last_errno = 0;
  for (struct addrinfo *address = addresses; address;
       address = address->ai_next) {
    if (address->ai_addrlen == 0 ||
        address->ai_addrlen > sizeof(struct sockaddr_storage)) {
      last_errno = EINVAL;
      continue;
    }
    struct sockaddr_storage requested_address;
    memset(&requested_address, 0, sizeof(requested_address));
    memcpy(&requested_address, address->ai_addr, address->ai_addrlen);
    int fd = socket(requested_address.ss_family, address->ai_socktype,
                    address->ai_protocol);
    if (fd < 0) {
      last_errno = errno;
      continue;
    }
    int one = 1;
    int receive_buffer = WLS_H3_ROUTER_LISTENER_BUFFER_BYTES;
    int send_buffer = WLS_H3_ROUTER_CHANNEL_BUFFER_BYTES;
    if (setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof(one)) != 0 ||
        setsockopt(fd, SOL_SOCKET, SO_RCVBUF, &receive_buffer,
                   sizeof(receive_buffer)) != 0 ||
        setsockopt(fd, SOL_SOCKET, SO_SNDBUF, &send_buffer,
                   sizeof(send_buffer)) != 0 ||
        wls_enable_destination_address(fd, requested_address.ss_family) !=
          WLS_TRANSPORT_OK ||
        wls_set_nonblocking_cloexec(fd) != 0) {
      last_errno = errno;
      close(fd);
      continue;
    }
    socklen_t receive_buffer_length = sizeof(receive_buffer);
    if (getsockopt(fd, SOL_SOCKET, SO_RCVBUF, &receive_buffer,
                   &receive_buffer_length) != 0 ||
        receive_buffer < WLS_H3_ROUTER_LISTENER_BUFFER_BYTES) {
      last_errno = errno != 0 ? errno : ENOBUFS;
      close(fd);
      continue;
    }
    if (bind(fd, address->ai_addr, address->ai_addrlen) != 0) {
      last_errno = errno;
      close(fd);
      continue;
    }
    struct sockaddr_storage bound;
    socklen_t bound_length = sizeof(bound);
    memset(&bound, 0, sizeof(bound));
    if (getsockname(fd, (struct sockaddr *)&bound, &bound_length) != 0) {
      last_errno = errno;
      close(fd);
      continue;
    }
    router->listener_io.fd = fd;
    router->listener_io.owns_fd = 1;
    router->listener_io.local_address = bound;
    router->listener_io.local_address_length = bound_length;
    uint16_t bound_port = wls_sockaddr_port(&bound);
    if (requested_address.ss_family == AF_INET) {
      ((struct sockaddr_in *)&requested_address)->sin_port =
        htons(bound_port);
    } else if (requested_address.ss_family == AF_INET6) {
      struct sockaddr_in6 *requested_ipv6 =
        (struct sockaddr_in6 *)&requested_address;
      requested_ipv6->sin6_port = htons(bound_port);
      if (wls_ipv6_scope_required(&requested_ipv6->sin6_addr) &&
          requested_ipv6->sin6_scope_id == 0) {
        requested_ipv6->sin6_scope_id =
          wls_interface_index_for_address(&requested_address);
      }
    }
    router->allowed_local_address = requested_address;
    router->allowed_local_address_length = address->ai_addrlen;
    router->filter_local_address =
      !wls_sockaddr_is_wildcard(
        &requested_address, address->ai_addrlen);
    router->bound_port = bound_port;
    break;
  }
  freeaddrinfo(addresses);
  if (router->listener_io.fd < 0) {
    wls_set_error("bind HTTP/3 Datagram Router %s:%u: %s", host,
                  (unsigned)port, strerror(last_errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  int queue_fd = kqueue();
  if (queue_fd < 0 || wls_set_cloexec(queue_fd) != 0 ||
      wls_kqueue_set_router_listener_read(
        queue_fd, router->listener_io.fd, 1) !=
        WLS_TRANSPORT_OK) {
    int saved_errno = errno;
    if (queue_fd >= 0) {
      close(queue_fd);
    }
    wls_io_path_close(&router->listener_io);
    router->bound_port = 0;
    wls_set_error("create HTTP/3 Datagram Router kqueue: %s",
                  strerror(saved_errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  router->wait_fd = queue_fd;
  wls_last_error[0] = '\0';
  return WLS_TRANSPORT_OK;
#endif
}

int wls_h3_datagram_router_publish_workers(
  wls_h3_datagram_router *router, const wls_h3_worker_endpoint *workers,
  size_t worker_count, uint64_t route_epoch) {
#if !defined(__APPLE__)
  (void)router;
  (void)workers;
  (void)worker_count;
  (void)route_epoch;
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  if (!router || route_epoch == 0 ||
      (router->route_epoch != 0 && route_epoch <= router->route_epoch) ||
      worker_count > WLS_H3_MAX_ROUTER_ENDPOINTS ||
      (worker_count != 0 && !workers)) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  wls_h3_router_endpoint prepared[WLS_H3_MAX_ROUTER_ENDPOINTS];
  uint8_t prepared_reused[WLS_H3_MAX_ROUTER_ENDPOINTS];
  uint8_t existing_reused[WLS_H3_MAX_ROUTER_ENDPOINTS];
  memset(prepared, 0, sizeof(prepared));
  memset(prepared_reused, 0, sizeof(prepared_reused));
  memset(existing_reused, 0, sizeof(existing_reused));
  for (size_t index = 0; index < WLS_H3_MAX_ROUTER_ENDPOINTS; ++index) {
    prepared[index].channel_fd = -1;
  }

  for (size_t index = 0; index < worker_count; ++index) {
    const wls_h3_worker_endpoint *source = &workers[index];
    if (source->struct_size != sizeof(*source) || source->worker_id == 0 ||
        source->generation == 0 ||
        !wls_valid_channel_path(source->channel_path) ||
        !source->channel_key ||
        source->channel_key_length != WLS_H3_CHANNEL_KEY_LENGTH) {
      wls_set_error("invalid HTTP/3 Worker endpoint at index %zu", index);
      goto publish_failed;
    }
    uint32_t route_id = wls_route_id_for_key(source->channel_key);
    if (route_id == 0) {
      wls_set_error("unable to derive HTTP/3 Worker route id");
      goto publish_failed;
    }
    for (size_t previous = 0; previous < index; ++previous) {
      if (prepared[previous].worker_id == source->worker_id ||
          prepared[previous].route_id == route_id) {
        wls_set_error("duplicate HTTP/3 Worker endpoint or route id");
        goto publish_failed;
      }
    }
    for (size_t existing = 0; existing < router->endpoint_count;
         ++existing) {
      const wls_h3_router_endpoint *current = &router->endpoints[existing];
      if (current->worker_id != source->worker_id ||
          current->generation != source->generation ||
          strcmp(current->channel_path, source->channel_path) != 0 ||
          CRYPTO_memcmp(current->channel_key, source->channel_key,
                        WLS_H3_CHANNEL_KEY_LENGTH) != 0) {
        continue;
      }
      prepared[index] = *current;
      prepared_reused[index] = 1;
      existing_reused[existing] = 1;
      goto endpoint_prepared;
    }

    char router_path[sizeof(((struct sockaddr_un *)0)->sun_path)];
    memset(router_path, 0, sizeof(router_path));
    if (wls_router_channel_path(source->channel_path, router_path,
                               sizeof(router_path)) != WLS_TRANSPORT_OK) {
      wls_set_error("invalid HTTP/3 Router channel path");
      goto publish_failed;
    }
    for (size_t existing = 0; existing < router->endpoint_count;
         ++existing) {
      if (strcmp(router->endpoints[existing].router_path,
                 router_path) == 0) {
        wls_set_error(
          "HTTP/3 Router channel path is still owned by an active endpoint");
        goto publish_failed;
      }
    }

    int channel_fd = socket(AF_UNIX, SOCK_DGRAM, 0);
    if (channel_fd < 0 || wls_set_nonblocking_cloexec(channel_fd) != 0) {
      if (channel_fd >= 0) {
        close(channel_fd);
      }
      wls_set_error("create HTTP/3 Router channel: %s", strerror(errno));
      goto publish_failed;
    }
    int send_buffer = WLS_H3_ROUTER_CHANNEL_BUFFER_BYTES;
    int receive_buffer = WLS_H3_ROUTER_CHANNEL_BUFFER_BYTES;
    if (setsockopt(channel_fd, SOL_SOCKET, SO_SNDBUF, &send_buffer,
                   sizeof(send_buffer)) != 0 ||
        setsockopt(channel_fd, SOL_SOCKET, SO_RCVBUF, &receive_buffer,
                   sizeof(receive_buffer)) != 0) {
      int saved_errno = errno;
      close(channel_fd);
      wls_set_error("size HTTP/3 Router datagram channel: %s",
                    strerror(saved_errno));
      goto publish_failed;
    }
    socklen_t send_buffer_length = sizeof(send_buffer);
    if (getsockopt(channel_fd, SOL_SOCKET, SO_SNDBUF, &send_buffer,
                   &send_buffer_length) != 0 ||
        send_buffer < (int)(WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES +
                            sizeof(wls_h3_channel_datagram))) {
      close(channel_fd);
      wls_set_error("HTTP/3 Router channel is below one maximum datagram");
      goto publish_failed;
    }
    struct stat router_path_stat;
    if (lstat(router_path, &router_path_stat) == 0) {
      if (!S_ISSOCK(router_path_stat.st_mode) ||
          router_path_stat.st_uid != geteuid() || unlink(router_path) != 0) {
        close(channel_fd);
        wls_set_error("unsafe existing HTTP/3 Router channel path");
        goto publish_failed;
      }
    } else if (errno != ENOENT) {
      int saved_errno = errno;
      close(channel_fd);
      wls_set_error("lstat HTTP/3 Router channel path: %s",
                    strerror(saved_errno));
      goto publish_failed;
    }
    struct sockaddr_un source_address;
    memset(&source_address, 0, sizeof(source_address));
    source_address.sun_family = AF_UNIX;
    memcpy(source_address.sun_path, router_path, strlen(router_path) + 1);
    if (bind(channel_fd, (struct sockaddr *)&source_address,
             sizeof(source_address)) != 0 || chmod(router_path, 0600) != 0) {
      int saved_errno = errno;
      close(channel_fd);
      unlink(router_path);
      wls_set_error("bind HTTP/3 Router channel: %s",
                    strerror(saved_errno));
      goto publish_failed;
    }
    struct sockaddr_un destination;
    memset(&destination, 0, sizeof(destination));
    destination.sun_family = AF_UNIX;
    memcpy(destination.sun_path, source->channel_path,
           strlen(source->channel_path) + 1);
    if (connect(channel_fd, (struct sockaddr *)&destination,
                sizeof(destination)) != 0) {
      int saved_errno = errno;
      close(channel_fd);
      unlink(router_path);
      wls_set_error("connect HTTP/3 Router channel: %s",
                    strerror(saved_errno));
      goto publish_failed;
    }
    if (wls_kqueue_set_read(router->wait_fd, channel_fd, 1) !=
        WLS_TRANSPORT_OK) {
      close(channel_fd);
      unlink(router_path);
      goto publish_failed;
    }
    prepared[index].worker_id = source->worker_id;
    prepared[index].route_id = route_id;
    prepared[index].generation = source->generation;
    prepared[index].channel_fd = channel_fd;
    memcpy(prepared[index].channel_path, source->channel_path,
           strlen(source->channel_path) + 1);
    memcpy(prepared[index].router_path, router_path,
           strlen(router_path) + 1);
    memcpy(prepared[index].channel_key, source->channel_key,
           WLS_H3_CHANNEL_KEY_LENGTH);
endpoint_prepared:
    prepared[index].accepting_new_connections =
      source->accepting_new_connections != 0;
  }

  /*
   * A Worker reports drain completion over a different control channel.  Its
   * terminal close may already be queued on this endpoint, so ownership must
   * move into the Router before the endpoint fd is closed and unlinked.
   */
  for (size_t existing = 0; existing < router->endpoint_count; ++existing) {
    if (existing_reused[existing]) {
      continue;
    }
    int channel_drained = 0;
    for (unsigned drained = 0;
         drained < WLS_H3_ROUTER_ENDPOINT_DRAIN_LIMIT; ++drained) {
      int drain_result = wls_router_receive_egress(
        router, &router->endpoints[existing]);
      if (drain_result == WLS_TRANSPORT_AGAIN) {
        channel_drained = 1;
        break;
      }
      if (drain_result == WLS_TRANSPORT_SOCKET_ERROR) {
        goto publish_failed;
      }
    }
    if (!channel_drained) {
      uint8_t pending_byte;
      ssize_t pending_result;
      do {
        pending_result = recv(
          router->endpoints[existing].channel_fd, &pending_byte,
          sizeof(pending_byte), MSG_PEEK);
      } while (pending_result < 0 && errno == EINTR);
      if (pending_result < 0) {
        int pending_error = errno;
        if (pending_error == EAGAIN || pending_error == EWOULDBLOCK ||
            pending_error == ECONNREFUSED || pending_error == ECONNRESET ||
            pending_error == ENOENT) {
          channel_drained = 1;
        } else {
          wls_set_error(
            "probe HTTP/3 Router Worker datagram channel: %s",
            strerror(pending_error));
          goto publish_failed;
        }
      }
    }
    if (!channel_drained) {
      wls_set_error(
        "HTTP/3 Router endpoint channel did not drain within the bounded fence");
      goto publish_failed;
    }
  }

  for (size_t existing = 0; existing < router->endpoint_count;
       ++existing) {
    if (!existing_reused[existing]) {
      continue;
    }
    router->endpoints[existing].channel_fd = -1;
    router->endpoints[existing].router_path[0] = '\0';
  }
  wls_router_close_endpoints(router);
  memcpy(router->endpoints, prepared,
         worker_count * sizeof(wls_h3_router_endpoint));
  router->endpoint_count = worker_count;
  router->route_epoch = route_epoch;
  wls_router_sweep_authorizations(router, wls_now(), 1);
  OPENSSL_cleanse(prepared, sizeof(prepared));
  return WLS_TRANSPORT_OK;

publish_failed:
  for (size_t index = 0; index < worker_count; ++index) {
    if (prepared[index].channel_fd >= 0 && !prepared_reused[index]) {
      if (router->wait_fd >= 0) {
        (void)wls_kqueue_set_read(router->wait_fd,
                                  prepared[index].channel_fd, 0);
      }
      close(prepared[index].channel_fd);
      if (prepared[index].router_path[0] != '\0') {
        unlink(prepared[index].router_path);
      }
    }
  }
  OPENSSL_cleanse(prepared, sizeof(prepared));
  return WLS_TRANSPORT_INVALID_ARGUMENT;
#endif
}

#if defined(__APPLE__)
static ssize_t wls_router_receive_datagram(
  wls_h3_datagram_router *router, uint8_t *datagram, size_t capacity,
  struct sockaddr_storage *local_address,
  socklen_t *local_address_length,
  struct sockaddr_storage *remote_address,
  socklen_t *remote_address_length) {
#if !defined(__APPLE__)
  (void)router;
  (void)datagram;
  (void)capacity;
  (void)local_address;
  (void)local_address_length;
  (void)remote_address;
  (void)remote_address_length;
  errno = ENOTSUP;
  return -1;
#else
  uint8_t control[256];
  struct iovec vector = {.iov_base = datagram, .iov_len = capacity};
  struct msghdr message;
  memset(&message, 0, sizeof(message));
  memset(control, 0, sizeof(control));
  memset(remote_address, 0, sizeof(*remote_address));
  message.msg_name = remote_address;
  message.msg_namelen = sizeof(*remote_address);
  message.msg_iov = &vector;
  message.msg_iovlen = 1;
  message.msg_control = control;
  message.msg_controllen = sizeof(control);
  ssize_t received = recvmsg(router->listener_io.fd, &message, 0);
  if (received < 0) {
    return received;
  }
  if ((message.msg_flags & (MSG_TRUNC | MSG_CTRUNC)) != 0) {
    errno = EMSGSIZE;
    return -1;
  }
  *remote_address_length = message.msg_namelen;
  memset(local_address, 0, sizeof(*local_address));
  *local_address_length = 0;

  for (struct cmsghdr *item = CMSG_FIRSTHDR(&message); item;
       item = CMSG_NXTHDR(&message, item)) {
#ifdef IP_RECVDSTADDR
    if (item->cmsg_level == IPPROTO_IP &&
        item->cmsg_type == IP_RECVDSTADDR &&
        item->cmsg_len >= CMSG_LEN(sizeof(struct in_addr))) {
      struct sockaddr_in resolved;
      memset(&resolved, 0, sizeof(resolved));
      resolved.sin_family = AF_INET;
      resolved.sin_port = htons(router->bound_port);
      memcpy(&resolved.sin_addr, CMSG_DATA(item), sizeof(resolved.sin_addr));
      memset(local_address, 0, sizeof(*local_address));
      memcpy(local_address, &resolved, sizeof(resolved));
      *local_address_length = sizeof(resolved);
      continue;
    }
#endif
#ifdef IPV6_PKTINFO
    if (item->cmsg_level == IPPROTO_IPV6 &&
        item->cmsg_type == IPV6_PKTINFO &&
        item->cmsg_len >= CMSG_LEN(sizeof(struct in6_pktinfo))) {
      const struct in6_pktinfo *packet_info =
        (const struct in6_pktinfo *)CMSG_DATA(item);
      struct sockaddr_in6 resolved;
      memset(&resolved, 0, sizeof(resolved));
      resolved.sin6_family = AF_INET6;
      resolved.sin6_port = htons(router->bound_port);
      resolved.sin6_addr = packet_info->ipi6_addr;
      resolved.sin6_scope_id =
        wls_ipv6_scope_required(&resolved.sin6_addr)
          ? packet_info->ipi6_ifindex
          : 0;
      memset(local_address, 0, sizeof(*local_address));
      memcpy(local_address, &resolved, sizeof(resolved));
      *local_address_length = sizeof(resolved);
    }
#endif
  }
  return received;
#endif
}

static int wls_router_local_address_allowed(
  const wls_h3_datagram_router *router,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length) {
  if (!router || !local_address || local_address_length == 0 ||
      local_address->ss_family != router->allowed_local_address.ss_family ||
      wls_sockaddr_is_wildcard(local_address, local_address_length)) {
    return 0;
  }
  if (!router->filter_local_address) {
    return 1;
  }
  return wls_sockaddr_equal(
    local_address, local_address_length, &router->allowed_local_address,
    router->allowed_local_address_length);
}

static wls_h3_router_endpoint *wls_router_endpoint_by_route(
  wls_h3_datagram_router *router, uint32_t route_id) {
  if (!router || route_id == 0) {
    return NULL;
  }
  for (size_t index = 0; index < router->endpoint_count; ++index) {
    if (router->endpoints[index].route_id == route_id) {
      return &router->endpoints[index];
    }
  }
  return NULL;
}

static uint64_t wls_router_path_hash(
  uint32_t route_id, const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length) {
  uint8_t material[4 + sizeof(struct sockaddr_storage) * 2];
  size_t length = 0;
  wls_write_u32_be(material, route_id);
  length += 4;
  memcpy(material + length, local_address, local_address_length);
  length += local_address_length;
  memcpy(material + length, remote_address, remote_address_length);
  length += remote_address_length;
  return wls_hash_bytes(material, length);
}

static uint8_t wls_router_source_bucket(
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length) {
  uint8_t material[1 + sizeof(struct in6_addr)];
  size_t length = 1;
  memset(material, 0, sizeof(material));
  material[0] = (uint8_t)remote_address->ss_family;
  if (remote_address->ss_family == AF_INET &&
      remote_address_length >= sizeof(struct sockaddr_in)) {
    memcpy(material + length,
           &((const struct sockaddr_in *)remote_address)->sin_addr,
           sizeof(struct in_addr));
    length += sizeof(struct in_addr);
  } else if (remote_address->ss_family == AF_INET6 &&
             remote_address_length >= sizeof(struct sockaddr_in6)) {
    memcpy(material + length,
           &((const struct sockaddr_in6 *)remote_address)->sin6_addr,
           8);
    length += 8;
  } else {
    return (uint8_t)(wls_hash_bytes(
      (const uint8_t *)remote_address, remote_address_length) & 0xffu);
  }
  return (uint8_t)(wls_hash_bytes(material, length) & 0xffu);
}

static int wls_router_authorization_matches(
  const wls_h3_router_authorized_path *path,
  const wls_h3_router_endpoint *endpoint,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length) {
  return path &&
    (path->state == WLS_H3_ROUTER_PATH_PROVISIONAL ||
     path->state == WLS_H3_ROUTER_PATH_ESTABLISHED ||
     path->state == WLS_H3_ROUTER_PATH_CLOSING) && endpoint &&
    path->route_id == endpoint->route_id &&
    path->generation == endpoint->generation &&
    wls_sockaddr_equal(&path->local_address, path->local_address_length,
                       local_address, local_address_length) &&
    wls_sockaddr_equal(&path->remote_address, path->remote_address_length,
                       remote_address, remote_address_length);
}

static void wls_router_release_authorization(
  wls_h3_datagram_router *router,
  wls_h3_router_authorized_path *path) {
  if (!router || !path ||
      (path->state != WLS_H3_ROUTER_PATH_PROVISIONAL &&
       path->state != WLS_H3_ROUTER_PATH_ESTABLISHED &&
       path->state != WLS_H3_ROUTER_PATH_CLOSING)) {
    return;
  }
  if (path->state == WLS_H3_ROUTER_PATH_PROVISIONAL) {
    if (router->provisional_authorizations != 0) {
      --router->provisional_authorizations;
    }
    if (router->provisional_source_buckets[path->source_bucket] != 0) {
      --router->provisional_source_buckets[path->source_bucket];
    }
  } else if (path->state == WLS_H3_ROUTER_PATH_ESTABLISHED) {
    if (router->established_authorizations != 0) {
      --router->established_authorizations;
    }
  } else {
    if (router->closing_authorizations != 0) {
      --router->closing_authorizations;
    }
    if (path->terminal_pending) {
      if (router->pending_terminal_closes != 0) {
        --router->pending_terminal_closes;
      }
      ++router->terminal_close_drops;
    }
    free(path->terminal_packet);
    path->terminal_packet = NULL;
    path->terminal_packet_length = 0;
  }
  if (router->live_authorizations != 0) {
    --router->live_authorizations;
  }
  size_t mask = WLS_H3_ROUTER_PATH_CAPACITY - 1;
  size_t hole = (size_t)(path - router->authorized_paths);
  size_t cursor = (hole + 1) & mask;
  while (router->authorized_paths[cursor].state !=
         WLS_H3_ROUTER_PATH_EMPTY) {
    wls_h3_router_authorized_path *candidate =
      &router->authorized_paths[cursor];
    size_t ideal = (size_t)candidate->hash & mask;
    size_t candidate_distance = (cursor - ideal) & mask;
    size_t hole_distance = (hole - ideal) & mask;
    if (hole_distance < candidate_distance) {
      router->authorized_paths[hole] = *candidate;
      hole = cursor;
    }
    cursor = (cursor + 1) & mask;
  }
  memset(&router->authorized_paths[hole], 0,
         sizeof(router->authorized_paths[hole]));
}

static int wls_router_authorization_endpoint_live(
  wls_h3_datagram_router *router,
  const wls_h3_router_authorized_path *path) {
  wls_h3_router_endpoint *endpoint =
    wls_router_endpoint_by_route(router, path->route_id);
  return endpoint && endpoint->generation == path->generation;
}

static void wls_router_sweep_authorizations(
  wls_h3_datagram_router *router, ngtcp2_tstamp now, int force) {
  if (!router || !router->authorized_paths ||
      (!force && now < router->next_authorization_sweep_at)) {
    return;
  }
  router->next_authorization_sweep_at =
    now + WLS_H3_ROUTER_PATH_SWEEP_INTERVAL;
  for (size_t index = 0; index < WLS_H3_ROUTER_PATH_CAPACITY;) {
    wls_h3_router_authorized_path *path = &router->authorized_paths[index];
    if ((path->state == WLS_H3_ROUTER_PATH_PROVISIONAL ||
         path->state == WLS_H3_ROUTER_PATH_ESTABLISHED ||
         path->state == WLS_H3_ROUTER_PATH_CLOSING) &&
        (path->expires_at <= now ||
         (path->state != WLS_H3_ROUTER_PATH_CLOSING &&
          !wls_router_authorization_endpoint_live(router, path)))) {
      wls_router_release_authorization(router, path);
      continue;
    }
    ++index;
  }
}

static uint64_t wls_router_authorization_id(
  const wls_h3_datagram_router *router,
  const wls_h3_router_endpoint *endpoint, const ngtcp2_cid *retry_dcid,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length) {
  static const uint8_t context[] = "wls-h3-path-authorization-v3";
  uint8_t material[sizeof(context) - 1 + 4 + 8 + 1 +
                   NGTCP2_MAX_CIDLEN +
                   sizeof(struct sockaddr_storage) * 2];
  size_t length = 0;
  memcpy(material + length, context, sizeof(context) - 1);
  length += sizeof(context) - 1;
  wls_write_u32_be(material + length, endpoint->route_id);
  length += 4;
  wls_write_u64_be(material + length, endpoint->generation);
  length += 8;
  material[length++] = (uint8_t)retry_dcid->datalen;
  memcpy(material + length, retry_dcid->data, retry_dcid->datalen);
  length += retry_dcid->datalen;
  memcpy(material + length, local_address, local_address_length);
  length += local_address_length;
  memcpy(material + length, remote_address, remote_address_length);
  length += remote_address_length;
  uint8_t digest[32];
  unsigned int digest_length = 0;
  unsigned char *result = HMAC(
    EVP_sha256(), router->retry_secret, sizeof(router->retry_secret),
    material, length, digest, &digest_length);
  OPENSSL_cleanse(material, sizeof(material));
  if (!result || digest_length < 8) {
    OPENSSL_cleanse(digest, sizeof(digest));
    return 0;
  }
  uint64_t authorization_id = wls_read_u64_be(digest);
  OPENSSL_cleanse(digest, sizeof(digest));
  return authorization_id == 0 ? 1 : authorization_id;
}

static int wls_router_provision_authorization(
  wls_h3_datagram_router *router, const wls_h3_router_endpoint *endpoint,
  uint64_t authorization_id,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, ngtcp2_tstamp now, int *created) {
  if (!router || !router->authorized_paths || !endpoint ||
      authorization_id == 0 || !created ||
      !local_address || !remote_address || local_address_length == 0 ||
      remote_address_length == 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  *created = 0;
  wls_router_sweep_authorizations(router, now, 0);
  uint64_t hash = wls_router_path_hash(
    endpoint->route_id, local_address, local_address_length,
    remote_address, remote_address_length);
  size_t mask = WLS_H3_ROUTER_PATH_CAPACITY - 1;
search_again: ;
  size_t index = (size_t)hash & mask;
  size_t reusable = SIZE_MAX;
  for (size_t probe = 0; probe < WLS_H3_ROUTER_PATH_CAPACITY; ++probe) {
    wls_h3_router_authorized_path *path = &router->authorized_paths[index];
    if (path->state == WLS_H3_ROUTER_PATH_EMPTY) {
      if (reusable == SIZE_MAX) {
        reusable = index;
      }
      break;
    }
    if (path->expires_at <= now) {
      wls_router_release_authorization(router, path);
      goto search_again;
    } else if (path->state != WLS_H3_ROUTER_PATH_CLOSING &&
               path->hash == hash &&
               path->authorization_id == authorization_id &&
               wls_router_authorization_matches(
                 path, endpoint, local_address, local_address_length,
                 remote_address, remote_address_length)) {
      if (path->state == WLS_H3_ROUTER_PATH_PROVISIONAL) {
        path->expires_at = now + WLS_H3_ROUTER_PROVISIONAL_TTL;
      }
      return WLS_TRANSPORT_OK;
    }
    index = (index + 1) & mask;
  }
  uint8_t source_bucket = wls_router_source_bucket(
    remote_address, remote_address_length);
  if (reusable == SIZE_MAX ||
      router->live_authorizations >= WLS_H3_ROUTER_MAX_LIVE_PATHS ||
      router->provisional_authorizations >=
        WLS_H3_ROUTER_MAX_PROVISIONAL_PATHS ||
      router->provisional_source_buckets[source_bucket] >=
        WLS_H3_ROUTER_MAX_PROVISIONAL_PER_SOURCE_BUCKET) {
    return WLS_TRANSPORT_BUFFER_TOO_SMALL;
  }
  wls_h3_router_authorized_path *path = &router->authorized_paths[reusable];
  memset(path, 0, sizeof(*path));
  path->route_id = endpoint->route_id;
  path->generation = endpoint->generation;
  path->hash = hash;
  path->authorization_id = authorization_id;
  path->source_bucket = source_bucket;
  path->expires_at = now + WLS_H3_ROUTER_PROVISIONAL_TTL;
  path->local_address = *local_address;
  path->local_address_length = local_address_length;
  path->remote_address = *remote_address;
  path->remote_address_length = remote_address_length;
  path->state = WLS_H3_ROUTER_PATH_PROVISIONAL;
  ++router->live_authorizations;
  ++router->provisional_authorizations;
  ++router->provisional_source_buckets[source_bucket];
  *created = 1;
  return WLS_TRANSPORT_OK;
}

static int wls_router_ingress_authorized(
  wls_h3_datagram_router *router, const wls_h3_router_endpoint *endpoint,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, ngtcp2_tstamp now) {
  if (!router || !router->authorized_paths || !endpoint) {
    return 0;
  }
  uint64_t hash = wls_router_path_hash(
    endpoint->route_id, local_address, local_address_length,
    remote_address, remote_address_length);
  size_t mask = WLS_H3_ROUTER_PATH_CAPACITY - 1;
ingress_search_again: ;
  size_t index = (size_t)hash & mask;
  for (size_t probe = 0; probe < WLS_H3_ROUTER_PATH_CAPACITY; ++probe) {
    wls_h3_router_authorized_path *path = &router->authorized_paths[index];
    if (path->state == WLS_H3_ROUTER_PATH_EMPTY) {
      return 0;
    }
    if ((path->state == WLS_H3_ROUTER_PATH_PROVISIONAL ||
         path->state == WLS_H3_ROUTER_PATH_ESTABLISHED) &&
      path->expires_at <= now) {
      wls_router_release_authorization(router, path);
      goto ingress_search_again;
    } else if ((path->state == WLS_H3_ROUTER_PATH_PROVISIONAL ||
                path->state == WLS_H3_ROUTER_PATH_ESTABLISHED) &&
               path->hash == hash &&
               wls_router_authorization_matches(
                 path, endpoint, local_address, local_address_length,
                 remote_address, remote_address_length)) {
      return 1;
    }
    index = (index + 1) & mask;
  }
  return 0;
}

static int wls_router_promote_authorization(
  wls_h3_datagram_router *router, const wls_h3_router_endpoint *endpoint,
  uint64_t authorization_id,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, ngtcp2_tstamp now) {
  uint64_t hash = wls_router_path_hash(
    endpoint->route_id, local_address, local_address_length,
    remote_address, remote_address_length);
  size_t mask = WLS_H3_ROUTER_PATH_CAPACITY - 1;
promote_search_again: ;
  size_t index = (size_t)hash & mask;
  for (size_t probe = 0; probe < WLS_H3_ROUTER_PATH_CAPACITY; ++probe) {
    wls_h3_router_authorized_path *path = &router->authorized_paths[index];
    if (path->state == WLS_H3_ROUTER_PATH_EMPTY) {
      return 0;
    }
    if ((path->state == WLS_H3_ROUTER_PATH_PROVISIONAL ||
         path->state == WLS_H3_ROUTER_PATH_ESTABLISHED) &&
      path->expires_at <= now) {
      wls_router_release_authorization(router, path);
      goto promote_search_again;
    } else if ((path->state == WLS_H3_ROUTER_PATH_PROVISIONAL ||
                path->state == WLS_H3_ROUTER_PATH_ESTABLISHED) &&
               path->hash == hash &&
               path->authorization_id == authorization_id &&
               wls_router_authorization_matches(
                 path, endpoint, local_address, local_address_length,
                 remote_address, remote_address_length)) {
      if (path->state == WLS_H3_ROUTER_PATH_PROVISIONAL) {
        if (router->provisional_authorizations != 0) {
          --router->provisional_authorizations;
        }
        if (router->provisional_source_buckets[path->source_bucket] != 0) {
          --router->provisional_source_buckets[path->source_bucket];
        }
        path->state = WLS_H3_ROUTER_PATH_ESTABLISHED;
        ++router->established_authorizations;
      }
      path->expires_at = now + WLS_H3_ROUTER_ESTABLISHED_TTL;
      return 1;
    }
    index = (index + 1) & mask;
  }
  return 0;
}

static int wls_router_retire_authorization(
  wls_h3_datagram_router *router, const wls_h3_router_endpoint *endpoint,
  uint64_t authorization_id,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length) {
  uint64_t hash = wls_router_path_hash(
    endpoint->route_id, local_address, local_address_length,
    remote_address, remote_address_length);
  size_t mask = WLS_H3_ROUTER_PATH_CAPACITY - 1;
  size_t index = (size_t)hash & mask;
  for (size_t probe = 0; probe < WLS_H3_ROUTER_PATH_CAPACITY; ++probe) {
    wls_h3_router_authorized_path *path = &router->authorized_paths[index];
    if (path->state == WLS_H3_ROUTER_PATH_EMPTY) {
      return 0;
    }
    if (path->hash == hash && path->authorization_id == authorization_id &&
        wls_router_authorization_matches(
          path, endpoint, local_address, local_address_length,
          remote_address, remote_address_length)) {
      if (path->state == WLS_H3_ROUTER_PATH_CLOSING) {
        return 1;
      }
      wls_router_release_authorization(router, path);
      return 1;
    }
    index = (index + 1) & mask;
  }
  return 0;
}

static void wls_router_update_terminal_write_interest(
  wls_h3_datagram_router *router) {
#if !defined(__APPLE__)
  (void)router;
#else
  if (!router || router->wait_fd < 0 || router->listener_io.fd < 0) {
    return;
  }
  uint8_t wanted = router->pending_terminal_closes != 0 ||
                   router->pending_egress_count != 0;
  if (wanted == router->terminal_write_interest) {
    return;
  }
  if (wls_kqueue_set_router_listener_write(
        router->wait_fd, router->listener_io.fd, wanted) ==
      WLS_TRANSPORT_OK) {
    router->terminal_write_interest = wanted;
  } else {
    ++router->terminal_close_drops;
  }
#endif
}

static void wls_router_pop_pending_egress(
  wls_h3_datagram_router *router) {
  if (!router || router->pending_egress_count == 0) {
    return;
  }
  router->pending_egress_head =
    (router->pending_egress_head + 1) %
    WLS_H3_ROUTER_EGRESS_QUEUE_CAPACITY;
  --router->pending_egress_count;
}

static int wls_router_queue_egress_datagram(
  wls_h3_datagram_router *router, const uint8_t *packet,
  size_t packet_length, const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, ngtcp2_tstamp now) {
  if (!router || !router->pending_egress || !packet || packet_length == 0 ||
      packet_length > WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES ||
      !local_address || local_address_length == 0 ||
      local_address_length > sizeof(*local_address) ||
      !remote_address || remote_address_length == 0 ||
      remote_address_length > sizeof(*remote_address)) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  if (router->pending_egress_count >=
      WLS_H3_ROUTER_EGRESS_QUEUE_CAPACITY) {
    ++router->egress_drops;
    ++router->egress_queue_drops;
    return WLS_TRANSPORT_AGAIN;
  }
  size_t tail = (router->pending_egress_head +
                 router->pending_egress_count) %
                WLS_H3_ROUTER_EGRESS_QUEUE_CAPACITY;
  wls_h3_router_egress_datagram *entry =
    &router->pending_egress[tail];
  memcpy(entry->packet, packet, packet_length);
  entry->packet_length = packet_length;
  memcpy(&entry->local_address, local_address, local_address_length);
  entry->local_address_length = local_address_length;
  memcpy(&entry->remote_address, remote_address, remote_address_length);
  entry->remote_address_length = remote_address_length;
  entry->expires_at = now + WLS_H3_ROUTER_EGRESS_MAX_AGE;
  ++router->pending_egress_count;
  ++router->egress_datagrams_queued;
  wls_router_update_terminal_write_interest(router);
  return WLS_TRANSPORT_OK;
}

static int wls_router_flush_pending_egress(
  wls_h3_datagram_router *router, ngtcp2_tstamp now) {
  if (!router || !router->pending_egress ||
      router->pending_egress_count == 0) {
    return 0;
  }
  int activity = 0;
  unsigned attempts = 0;
  while (router->pending_egress_count != 0 &&
         attempts < WLS_H3_ROUTER_EGRESS_FLUSH_BATCH) {
    wls_h3_router_egress_datagram *entry =
      &router->pending_egress[router->pending_egress_head];
    ++attempts;
    if (entry->expires_at <= now) {
      wls_router_pop_pending_egress(router);
      ++router->egress_drops;
      ++router->egress_queue_drops;
      ++activity;
      continue;
    }
    int send_result = wls_io_path_send_datagram(
      &router->listener_io, entry->packet, entry->packet_length,
      (const struct sockaddr *)&entry->local_address,
      entry->local_address_length,
      (const struct sockaddr *)&entry->remote_address,
      entry->remote_address_length, 0,
      "flush queued HTTP/3 Router egress datagram");
    if (send_result == WLS_TRANSPORT_AGAIN) {
      ++router->egress_queue_retries;
      break;
    }
    wls_router_pop_pending_egress(router);
    ++activity;
    if (send_result == WLS_TRANSPORT_OK) {
      ++router->egress_datagrams;
      ++router->egress_queue_sends;
      continue;
    }
    ++router->egress_drops;
    ++router->egress_queue_drops;
  }
  return activity;
}

static int wls_router_terminal_cid_matches(
  const wls_h3_router_authorized_path *path, const uint8_t *cid,
  size_t cid_length) {
  if (!path || path->state != WLS_H3_ROUTER_PATH_CLOSING || !cid ||
      cid_length == 0 || cid_length > NGTCP2_MAX_CIDLEN) {
    return 0;
  }
  for (uint8_t index = 0; index < path->terminal_cid_count; ++index) {
    if (path->terminal_cid_lengths[index] == cid_length &&
        memcmp(path->terminal_cids[index], cid, cid_length) == 0) {
      return 1;
    }
  }
  return 0;
}

static int wls_router_send_cached_terminal_close(
  wls_h3_datagram_router *router, wls_h3_router_authorized_path *path,
  ngtcp2_tstamp now, int respect_resend_limit) {
  if (!router || !path || path->state != WLS_H3_ROUTER_PATH_CLOSING ||
      !path->terminal_packet || path->terminal_packet_length == 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  if (respect_resend_limit &&
      (path->terminal_pending ||
       (path->terminal_sent && now < path->next_terminal_send_at))) {
    ++router->terminal_close_rate_limited;
    return WLS_TRANSPORT_AGAIN;
  }

  int retried = path->terminal_attempted != 0;
  path->terminal_attempted = 1;
  int send_result = wls_io_path_send_datagram(
    &router->listener_io, path->terminal_packet,
    path->terminal_packet_length,
    (const struct sockaddr *)&path->local_address,
    path->local_address_length,
    (const struct sockaddr *)&path->remote_address,
    path->remote_address_length, 0,
    "send cached HTTP/3 terminal close");
  if (send_result == WLS_TRANSPORT_AGAIN) {
    if (!path->terminal_pending) {
      path->terminal_pending = 1;
      ++router->pending_terminal_closes;
    }
    wls_router_update_terminal_write_interest(router);
    return WLS_TRANSPORT_AGAIN;
  }
  if (path->terminal_pending) {
    path->terminal_pending = 0;
    if (router->pending_terminal_closes != 0) {
      --router->pending_terminal_closes;
    }
  }
  path->next_terminal_send_at = now + WLS_H3_ROUTER_CLOSE_RESEND_INTERVAL;
  if (send_result != WLS_TRANSPORT_OK) {
    ++router->terminal_close_drops;
    wls_router_update_terminal_write_interest(router);
    return send_result;
  }
  path->terminal_sent = 1;
  ++router->terminal_close_sends;
  if (retried) {
    ++router->terminal_close_resends;
  }
  wls_router_update_terminal_write_interest(router);
  return WLS_TRANSPORT_OK;
}

static int wls_router_flush_pending_terminal_closes(
  wls_h3_datagram_router *router, ngtcp2_tstamp now) {
  if (!router || router->pending_terminal_closes == 0) {
    wls_router_update_terminal_write_interest(router);
    return 0;
  }
  int activity = 0;
  for (size_t index = 0; index < WLS_H3_ROUTER_PATH_CAPACITY; ++index) {
    wls_h3_router_authorized_path *path = &router->authorized_paths[index];
    if (path->state != WLS_H3_ROUTER_PATH_CLOSING ||
        !path->terminal_pending) {
      continue;
    }
    int result = wls_router_send_cached_terminal_close(
      router, path, now, 0);
    if (result == WLS_TRANSPORT_OK || result < 0) {
      ++activity;
    }
  }
  wls_router_update_terminal_write_interest(router);
  return activity;
}

static int wls_router_cache_terminal_close(
  wls_h3_datagram_router *router, const wls_h3_router_endpoint *endpoint,
  uint64_t authorization_id,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, const uint8_t *packet,
  size_t packet_length, uint8_t terminal_cid_count,
  const uint8_t terminal_cid_lengths[WLS_H3_MAX_TERMINAL_CIDS],
  const uint8_t terminal_cids[WLS_H3_MAX_TERMINAL_CIDS][NGTCP2_MAX_CIDLEN],
  ngtcp2_tstamp now) {
  if (!router || !router->authorized_paths || !endpoint ||
      authorization_id == 0 || !local_address || !remote_address ||
      local_address_length == 0 || remote_address_length == 0 || !packet ||
      packet_length == 0 ||
      packet_length > WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES ||
      terminal_cid_count == 0 ||
      terminal_cid_count > WLS_H3_MAX_TERMINAL_CIDS) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  for (uint8_t cid_index = 0; cid_index < terminal_cid_count; ++cid_index) {
    if (terminal_cid_lengths[cid_index] != WLS_H3_SERVER_SCID_LENGTH ||
        terminal_cid_lengths[cid_index] > NGTCP2_MAX_CIDLEN) {
      return WLS_TRANSPORT_INVALID_ARGUMENT;
    }
  }

  uint8_t *packet_copy = malloc(packet_length);
  if (!packet_copy) {
    ++router->terminal_close_drops;
    return WLS_TRANSPORT_NOMEM;
  }
  memcpy(packet_copy, packet, packet_length);

  uint64_t hash = wls_router_path_hash(
    endpoint->route_id, local_address, local_address_length,
    remote_address, remote_address_length);
  size_t mask = WLS_H3_ROUTER_PATH_CAPACITY - 1;
cache_search_again: ;
  size_t index = (size_t)hash & mask;
  wls_h3_router_authorized_path *path = NULL;
  for (size_t probe = 0; probe < WLS_H3_ROUTER_PATH_CAPACITY; ++probe) {
    wls_h3_router_authorized_path *candidate =
      &router->authorized_paths[index];
    if (candidate->state == WLS_H3_ROUTER_PATH_EMPTY) {
      path = candidate;
      break;
    }
    if (candidate->expires_at <= now) {
      wls_router_release_authorization(router, candidate);
      goto cache_search_again;
    }
    if (candidate->hash == hash &&
        candidate->authorization_id == authorization_id &&
        wls_router_authorization_matches(
          candidate, endpoint, local_address, local_address_length,
          remote_address, remote_address_length)) {
      path = candidate;
      break;
    }
    index = (index + 1) & mask;
  }
  if (!path ||
      (path->state == WLS_H3_ROUTER_PATH_EMPTY &&
       router->live_authorizations >= WLS_H3_ROUTER_MAX_LIVE_PATHS)) {
    free(packet_copy);
    ++router->terminal_close_drops;
    return WLS_TRANSPORT_BUFFER_TOO_SMALL;
  }

  if (path->state == WLS_H3_ROUTER_PATH_EMPTY) {
    memset(path, 0, sizeof(*path));
    path->route_id = endpoint->route_id;
    path->generation = endpoint->generation;
    path->hash = hash;
    path->authorization_id = authorization_id;
    path->local_address = *local_address;
    path->local_address_length = local_address_length;
    path->remote_address = *remote_address;
    path->remote_address_length = remote_address_length;
    ++router->live_authorizations;
    ++router->closing_authorizations;
  } else if (path->state != WLS_H3_ROUTER_PATH_CLOSING) {
    if (path->state == WLS_H3_ROUTER_PATH_PROVISIONAL) {
      if (router->provisional_authorizations != 0) {
        --router->provisional_authorizations;
      }
      if (router->provisional_source_buckets[path->source_bucket] != 0) {
        --router->provisional_source_buckets[path->source_bucket];
      }
    } else if (router->established_authorizations != 0) {
      --router->established_authorizations;
    }
    ++router->closing_authorizations;
  } else {
    if (path->terminal_pending && router->pending_terminal_closes != 0) {
      --router->pending_terminal_closes;
    }
    free(path->terminal_packet);
  }

  path->state = WLS_H3_ROUTER_PATH_CLOSING;
  path->expires_at = now + WLS_H3_ROUTER_CLOSING_TTL;
  path->terminal_pending = 0;
  path->terminal_sent = 0;
  path->terminal_attempted = 0;
  path->terminal_packet = packet_copy;
  path->terminal_packet_length = packet_length;
  path->next_terminal_send_at = now;
  path->terminal_cid_count = terminal_cid_count;
  memset(path->terminal_cid_lengths, 0,
         sizeof(path->terminal_cid_lengths));
  memset(path->terminal_cids, 0, sizeof(path->terminal_cids));
  memcpy(path->terminal_cid_lengths, terminal_cid_lengths,
         terminal_cid_count * sizeof(path->terminal_cid_lengths[0]));
  for (uint8_t cid_index = 0; cid_index < terminal_cid_count; ++cid_index) {
    memcpy(path->terminal_cids[cid_index], terminal_cids[cid_index],
           terminal_cid_lengths[cid_index]);
  }
  ++router->terminal_closes_cached;
  (void)wls_router_send_cached_terminal_close(router, path, now, 0);
  return WLS_TRANSPORT_OK;
}

static wls_h3_router_authorized_path *
wls_router_find_closing_authorization(
  wls_h3_datagram_router *router, uint32_t route_id,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, const uint8_t *cid, size_t cid_length,
  ngtcp2_tstamp now) {
  if (!router || !router->authorized_paths || route_id == 0 || !cid) {
    return NULL;
  }
  uint64_t hash = wls_router_path_hash(
    route_id, local_address, local_address_length,
    remote_address, remote_address_length);
  size_t mask = WLS_H3_ROUTER_PATH_CAPACITY - 1;
closing_search_again: ;
  size_t index = (size_t)hash & mask;
  for (size_t probe = 0; probe < WLS_H3_ROUTER_PATH_CAPACITY; ++probe) {
    wls_h3_router_authorized_path *path = &router->authorized_paths[index];
    if (path->state == WLS_H3_ROUTER_PATH_EMPTY) {
      return NULL;
    }
    if (path->state == WLS_H3_ROUTER_PATH_CLOSING &&
        path->expires_at <= now) {
      wls_router_release_authorization(router, path);
      goto closing_search_again;
    }
    if (path->state == WLS_H3_ROUTER_PATH_CLOSING &&
        path->hash == hash && path->route_id == route_id &&
        wls_sockaddr_equal(
          &path->local_address, path->local_address_length,
          local_address, local_address_length) &&
        wls_sockaddr_equal(
          &path->remote_address, path->remote_address_length,
          remote_address, remote_address_length) &&
        wls_router_terminal_cid_matches(path, cid, cid_length)) {
      return path;
    }
    index = (index + 1) & mask;
  }
  return NULL;
}

static int wls_router_select_endpoint_v2(
  const wls_h3_datagram_router *router, const ngtcp2_pkt_hd *header,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, size_t *endpoint_index) {
  if (!router || router->endpoint_count == 0 || !header ||
      !remote_address || !endpoint_index || header->dcid.datalen == 0) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  size_t accepting_count = 0;
  for (size_t index = 0; index < router->endpoint_count; ++index) {
    accepting_count += router->endpoints[index].accepting_new_connections != 0;
  }
  if (accepting_count == 0) {
    return WLS_TRANSPORT_NOT_FOUND;
  }
  uint8_t material[sizeof(struct sockaddr_storage) + NGTCP2_MAX_CIDLEN];
  size_t length = 0;
  memcpy(material, remote_address, remote_address_length);
  length += remote_address_length;
  memcpy(material + length, header->dcid.data, header->dcid.datalen);
  length += header->dcid.datalen;
  uint8_t digest[32];
  unsigned int digest_length = 0;
  unsigned char *result = HMAC(
    EVP_sha256(), router->retry_secret, sizeof(router->retry_secret),
    material, length, digest, &digest_length);
  OPENSSL_cleanse(material, sizeof(material));
  if (!result || digest_length < 8) {
    OPENSSL_cleanse(digest, sizeof(digest));
    return WLS_TRANSPORT_INTERNAL_ERROR;
  }
  size_t selected = (size_t)(wls_read_u64_be(digest) % accepting_count);
  OPENSSL_cleanse(digest, sizeof(digest));
  for (size_t index = 0; index < router->endpoint_count; ++index) {
    if (!router->endpoints[index].accepting_new_connections) {
      continue;
    }
    if (selected == 0) {
      *endpoint_index = index;
      return WLS_TRANSPORT_OK;
    }
    --selected;
  }
  return WLS_TRANSPORT_NOT_FOUND;
}

static int wls_router_send_retry_v2(
  wls_h3_datagram_router *router, wls_h3_router_endpoint *endpoint,
  const ngtcp2_pkt_hd *header,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, size_t received_length) {
  ngtcp2_cid retry_scid;
  int cid_result = wls_route_cid_generate(
    endpoint->channel_key, endpoint->route_id, &retry_scid);
  if (cid_result != WLS_TRANSPORT_OK) {
    return cid_result;
  }
  uint8_t token[NGTCP2_CRYPTO_MAX_RETRY_TOKENLEN2];
  ngtcp2_ssize token_length = ngtcp2_crypto_generate_retry_token2(
    token, router->retry_secret, sizeof(router->retry_secret),
    header->version, (const ngtcp2_sockaddr *)remote_address,
    remote_address_length, &retry_scid, &header->dcid, wls_now());
  if (token_length < 0) {
    return WLS_TRANSPORT_QUIC_ERROR;
  }
  uint8_t packet[NGTCP2_MAX_UDP_PAYLOAD_SIZE];
  size_t amplification_limit = received_length > sizeof(packet) / 3
                                 ? sizeof(packet)
                                 : received_length * 3;
  ngtcp2_ssize packet_length = ngtcp2_crypto_write_retry(
    packet, amplification_limit, header->version, &header->scid,
    &retry_scid, &header->dcid, token, (size_t)token_length);
  if (packet_length < 0) {
    return WLS_TRANSPORT_QUIC_ERROR;
  }
  int result = wls_io_path_send_datagram(
    &router->listener_io, packet, (size_t)packet_length,
    (const struct sockaddr *)local_address, local_address_length,
    (const struct sockaddr *)remote_address, remote_address_length,
    0,
    "send routed HTTP/3 Retry");
  if (result == WLS_TRANSPORT_OK) {
    ++router->retry_sent;
  }
  return result;
}

static wls_h3_router_endpoint *wls_router_verify_retry_v2(
  wls_h3_datagram_router *router, const ngtcp2_pkt_hd *header,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length) {
  if (!router || !header || !header->token || header->tokenlen == 0 ||
      header->dcid.datalen != WLS_H3_SERVER_SCID_LENGTH ||
      header->dcid.data[0] != WLS_H3_ROUTE_CID_MARKER) {
    return NULL;
  }
  wls_h3_router_endpoint *endpoint = wls_router_endpoint_by_route(
    router, wls_read_u32_be(header->dcid.data + 1));
  if (!endpoint || !wls_route_cid_valid(
        endpoint, header->dcid.data, header->dcid.datalen)) {
    return NULL;
  }
  ngtcp2_cid original_dcid;
  ngtcp2_duration lifetime = router->retry_token_lifetime_ms *
                             NGTCP2_MILLISECONDS;
  int result = ngtcp2_crypto_verify_retry_token2(
    &original_dcid, header->token, header->tokenlen,
    router->retry_secret, sizeof(router->retry_secret), header->version,
    (const ngtcp2_sockaddr *)remote_address, remote_address_length,
    &header->dcid, lifetime, wls_now());
  if (result != 0) {
    return NULL;
  }
  ++router->retry_validated;
  return endpoint;
}

static int wls_router_send_ingress(
  wls_h3_datagram_router *router, wls_h3_router_endpoint *endpoint,
  uint64_t authorization_id,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, const uint8_t *datagram,
  size_t datagram_length) {
  if (!router || !endpoint || endpoint->channel_fd < 0 || !datagram ||
      datagram_length == 0 ||
      datagram_length > WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  size_t payload_length = sizeof(wls_h3_channel_datagram) +
                          datagram_length;
  uint8_t payload[sizeof(wls_h3_channel_datagram) +
                  WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES];
  wls_h3_channel_datagram *envelope =
    (wls_h3_channel_datagram *)payload;
  memset(envelope, 0, sizeof(*envelope));
  envelope->magic = WLS_H3_CHANNEL_MAGIC;
  envelope->version = WLS_H3_CHANNEL_VERSION;
  envelope->header_size = sizeof(*envelope);
  envelope->route_epoch = router->route_epoch;
  envelope->worker_id = endpoint->worker_id;
  envelope->worker_generation = endpoint->generation;
  envelope->authorization_id = authorization_id;
  envelope->datagram_length = (uint32_t)datagram_length;
  envelope->local_address_length = (uint16_t)local_address_length;
  envelope->remote_address_length = (uint16_t)remote_address_length;
  envelope->direction = WLS_H3_CHANNEL_INGRESS;
  memcpy(&envelope->local_address, local_address, local_address_length);
  memcpy(&envelope->remote_address, remote_address,
         remote_address_length);
  memcpy(payload + sizeof(*envelope), datagram, datagram_length);
  int auth_result = wls_channel_authentication_tag(
    endpoint->channel_key, envelope, payload + sizeof(*envelope),
    datagram_length, envelope->authentication_tag);
  if (auth_result != WLS_TRANSPORT_OK) {
    return auth_result;
  }
  ssize_t sent;
  do {
    sent = send(endpoint->channel_fd, payload, payload_length, 0);
  } while (sent < 0 && errno == EINTR);
  if (sent < 0) {
    int send_error = errno;
    if (send_error == EAGAIN || send_error == EWOULDBLOCK ||
        send_error == ENOBUFS) {
      if (router->pending_ingress_length != 0 ||
          payload_length > sizeof(router->pending_ingress_payload)) {
        OPENSSL_cleanse(payload, payload_length);
        ++router->ingress_drops;
        ++router->ingress_queue_drops;
        return WLS_TRANSPORT_AGAIN;
      }
      memcpy(router->pending_ingress_payload, payload, payload_length);
      router->pending_ingress_length = payload_length;
      router->pending_ingress_worker_id = endpoint->worker_id;
      router->pending_ingress_generation = endpoint->generation;
      router->pending_ingress_route_epoch = router->route_epoch;
      router->pending_ingress_queued_at = wls_now();
      struct kevent changes[2];
      EV_SET(&changes[0], (uintptr_t)endpoint->channel_fd, EVFILT_WRITE,
             EV_ADD | EV_ENABLE, 0, 0, endpoint);
      EV_SET(&changes[1], (uintptr_t)router->listener_io.fd, EVFILT_READ,
             EV_DISABLE, 0, 0, NULL);
      int change_result;
      do {
        change_result = kevent(router->wait_fd, changes, 2, NULL, 0, NULL);
      } while (change_result < 0 && errno == EINTR);
      if (change_result < 0) {
        router->pending_ingress_length = 0;
        OPENSSL_cleanse(router->pending_ingress_payload,
                        sizeof(router->pending_ingress_payload));
        OPENSSL_cleanse(payload, payload_length);
        ++router->ingress_drops;
        ++router->ingress_queue_drops;
        wls_set_error("arm HTTP/3 Router ingress backpressure: %s",
                      strerror(errno));
        return WLS_TRANSPORT_SOCKET_ERROR;
      }
      ++router->ingress_datagrams_queued;
      OPENSSL_cleanse(payload, payload_length);
      return WLS_TRANSPORT_OK;
    }
    OPENSSL_cleanse(payload, payload_length);
    ++router->ingress_drops;
    wls_set_error("send HTTP/3 Router ingress datagram: %s",
                  strerror(send_error));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  OPENSSL_cleanse(payload, payload_length);
  if ((size_t)sent != payload_length) {
    ++router->ingress_drops;
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  ++router->routed_datagrams;
  return WLS_TRANSPORT_OK;
}

static int wls_router_route_public_datagram(
  wls_h3_datagram_router *router,
  const struct sockaddr_storage *local_address,
  socklen_t local_address_length,
  const struct sockaddr_storage *remote_address,
  socklen_t remote_address_length, const uint8_t *datagram,
  size_t datagram_length) {
  ngtcp2_pkt_hd header;
  if (ngtcp2_accept(&header, datagram, datagram_length) == 0) {
    if (header.tokenlen == 0) {
      size_t endpoint_index = 0;
      if (wls_router_select_endpoint_v2(
            router, &header, remote_address, remote_address_length,
            &endpoint_index) != WLS_TRANSPORT_OK) {
        ++router->rejected_initials;
        return WLS_TRANSPORT_OK;
      }
      int retry_result = wls_router_send_retry_v2(
        router, &router->endpoints[endpoint_index], &header,
        local_address, local_address_length,
        remote_address, remote_address_length, datagram_length);
      if (retry_result != WLS_TRANSPORT_OK) {
        ++router->rejected_initials;
      }
      return WLS_TRANSPORT_OK;
    }
    wls_h3_router_endpoint *endpoint = wls_router_verify_retry_v2(
      router, &header, remote_address, remote_address_length);
    uint64_t authorization_id = endpoint
      ? wls_router_authorization_id(
          router, endpoint, &header.dcid, local_address,
          local_address_length, remote_address, remote_address_length)
      : 0;
    int created = 0;
    if (!endpoint || authorization_id == 0 ||
        wls_router_provision_authorization(
          router, endpoint, authorization_id, local_address,
          local_address_length, remote_address, remote_address_length,
          wls_now(), &created) != WLS_TRANSPORT_OK) {
      ++router->rejected_initials;
      return WLS_TRANSPORT_OK;
    }
    int ingress_result = wls_router_send_ingress(
      router, endpoint, authorization_id,
      local_address, local_address_length,
      remote_address, remote_address_length, datagram, datagram_length);
    if (created && ingress_result != WLS_TRANSPORT_OK) {
      (void)wls_router_retire_authorization(
        router, endpoint, authorization_id,
        local_address, local_address_length,
        remote_address, remote_address_length);
    }
    return WLS_TRANSPORT_OK;
  }

  ngtcp2_version_cid version_cid;
  if (ngtcp2_pkt_decode_version_cid(
        &version_cid, datagram, datagram_length,
        WLS_H3_SERVER_SCID_LENGTH) != 0 ||
      version_cid.dcidlen != WLS_H3_SERVER_SCID_LENGTH ||
      version_cid.dcid[0] != WLS_H3_ROUTE_CID_MARKER) {
    ++router->ingress_drops;
    return WLS_TRANSPORT_OK;
  }
  uint32_t route_id = wls_read_u32_be(version_cid.dcid + 1);
  ngtcp2_tstamp now = wls_now();
  wls_h3_router_authorized_path *closing =
    wls_router_find_closing_authorization(
      router, route_id, local_address, local_address_length,
      remote_address, remote_address_length,
      version_cid.dcid, version_cid.dcidlen, now);
  if (closing) {
    (void)wls_router_send_cached_terminal_close(router, closing, now, 1);
    return WLS_TRANSPORT_OK;
  }
  wls_h3_router_endpoint *endpoint = wls_router_endpoint_by_route(
    router, route_id);
  if (!endpoint || !wls_route_cid_valid(
        endpoint, version_cid.dcid, version_cid.dcidlen) ||
      !wls_router_ingress_authorized(
        router, endpoint, local_address, local_address_length,
        remote_address, remote_address_length, now)) {
    ++router->ingress_drops;
    return WLS_TRANSPORT_OK;
  }
  (void)wls_router_send_ingress(
    router, endpoint, 0, local_address, local_address_length,
    remote_address, remote_address_length, datagram, datagram_length);
  return WLS_TRANSPORT_OK;
}

static int wls_router_receive_egress(
  wls_h3_datagram_router *router, wls_h3_router_endpoint *endpoint) {
  uint8_t payload[sizeof(wls_h3_channel_datagram) +
                  WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES];
  struct sockaddr_un source;
  socklen_t source_length = sizeof(source);
  memset(&source, 0, sizeof(source));
  ssize_t received;
  do {
    source_length = sizeof(source);
    received = recvfrom(
      endpoint->channel_fd, payload, sizeof(payload), 0,
      (struct sockaddr *)&source, &source_length);
  } while (received < 0 && errno == EINTR);
  if (received < 0) {
    int receive_error = errno;
    if (receive_error == EAGAIN || receive_error == EWOULDBLOCK ||
        receive_error == ECONNREFUSED || receive_error == ECONNRESET ||
        receive_error == ENOENT) {
      return WLS_TRANSPORT_AGAIN;
    }
    ++router->egress_drops;
    wls_set_error("receive HTTP/3 Router Worker datagram channel: %s",
                  strerror(receive_error));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  if ((size_t)received < sizeof(wls_h3_channel_datagram) ||
      !wls_unix_source_path_matches(
        &source, source_length, endpoint->channel_path)) {
    ++router->egress_drops;
    return WLS_TRANSPORT_OK;
  }
  wls_h3_channel_datagram envelope;
  memcpy(&envelope, payload, sizeof(envelope));
  size_t datagram_length = (size_t)received - sizeof(envelope);
  int is_egress = envelope.direction == WLS_H3_CHANNEL_EGRESS;
  int is_retire = envelope.direction == WLS_H3_CHANNEL_PATH_RETIRE;
  int is_terminal_close = envelope.direction == WLS_H3_CHANNEL_PATH_CLOSE;
  if (envelope.magic != WLS_H3_CHANNEL_MAGIC ||
      envelope.version != WLS_H3_CHANNEL_VERSION ||
      envelope.header_size != sizeof(envelope) ||
      (!is_egress && !is_retire && !is_terminal_close) ||
      envelope.worker_id != endpoint->worker_id ||
      envelope.worker_generation != endpoint->generation ||
      envelope.authorization_id == 0 ||
      envelope.datagram_length != datagram_length ||
      ((is_egress || is_terminal_close) && datagram_length == 0) ||
      (is_retire && datagram_length != 0) ||
      (is_terminal_close &&
       (envelope.terminal_cid_count == 0 ||
        envelope.terminal_cid_count > WLS_H3_MAX_TERMINAL_CIDS)) ||
      (!is_terminal_close && envelope.terminal_cid_count != 0) ||
      datagram_length > WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES ||
      envelope.local_address_length == 0 ||
      envelope.remote_address_length == 0 ||
      envelope.local_address_length > sizeof(envelope.local_address) ||
      envelope.remote_address_length > sizeof(envelope.remote_address) ||
      !wls_router_local_address_allowed(
        router, &envelope.local_address,
        envelope.local_address_length)) {
    ++router->egress_drops;
    return WLS_TRANSPORT_OK;
  }
  uint8_t expected_tag[32];
  int auth_result = wls_channel_authentication_tag(
    endpoint->channel_key, &envelope, payload + sizeof(envelope),
    datagram_length, expected_tag);
  if (auth_result != WLS_TRANSPORT_OK ||
      CRYPTO_memcmp(expected_tag, envelope.authentication_tag,
                    sizeof(expected_tag)) != 0) {
    OPENSSL_cleanse(expected_tag, sizeof(expected_tag));
    ++router->channel_auth_failures;
    ++router->egress_drops;
    return WLS_TRANSPORT_OK;
  }
  OPENSSL_cleanse(expected_tag, sizeof(expected_tag));
  if (is_terminal_close) {
    for (uint8_t cid_index = 0;
         cid_index < envelope.terminal_cid_count; ++cid_index) {
      size_t cid_length = envelope.terminal_cid_lengths[cid_index];
      if (cid_length != WLS_H3_SERVER_SCID_LENGTH ||
          cid_length > NGTCP2_MAX_CIDLEN ||
          !wls_route_cid_valid(
            endpoint, envelope.terminal_cids[cid_index], cid_length)) {
        ++router->terminal_close_drops;
        ++router->egress_drops;
        return WLS_TRANSPORT_OK;
      }
    }
    int cache_result = wls_router_cache_terminal_close(
      router, endpoint, envelope.authorization_id,
      &envelope.local_address, envelope.local_address_length,
      &envelope.remote_address, envelope.remote_address_length,
      payload + sizeof(envelope), datagram_length,
      envelope.terminal_cid_count, envelope.terminal_cid_lengths,
      envelope.terminal_cids, wls_now());
    if (cache_result != WLS_TRANSPORT_OK) {
      ++router->egress_drops;
      return WLS_TRANSPORT_OK;
    }
    return WLS_TRANSPORT_OK;
  }
  if (is_retire) {
    (void)wls_router_retire_authorization(
      router, endpoint, envelope.authorization_id,
      &envelope.local_address, envelope.local_address_length,
      &envelope.remote_address, envelope.remote_address_length);
    return WLS_TRANSPORT_OK;
  }
  if (!wls_router_promote_authorization(
        router, endpoint, envelope.authorization_id,
        &envelope.local_address, envelope.local_address_length,
        &envelope.remote_address, envelope.remote_address_length,
        wls_now())) {
    ++router->egress_drops;
    return WLS_TRANSPORT_OK;
  }
  if (router->pending_terminal_closes != 0 ||
      router->pending_egress_count != 0) {
    (void)wls_router_queue_egress_datagram(
      router, payload + sizeof(envelope), datagram_length,
      &envelope.local_address, envelope.local_address_length,
      &envelope.remote_address, envelope.remote_address_length,
      wls_now());
    return WLS_TRANSPORT_OK;
  }
  int send_result = wls_io_path_send_datagram(
    &router->listener_io, payload + sizeof(envelope), datagram_length,
    (const struct sockaddr *)&envelope.local_address,
    envelope.local_address_length,
    (const struct sockaddr *)&envelope.remote_address,
    envelope.remote_address_length, 0,
    "send routed HTTP/3 datagram");
  if (send_result == WLS_TRANSPORT_AGAIN) {
    (void)wls_router_queue_egress_datagram(
      router, payload + sizeof(envelope), datagram_length,
      &envelope.local_address, envelope.local_address_length,
      &envelope.remote_address, envelope.remote_address_length,
      wls_now());
    return WLS_TRANSPORT_OK;
  }
  if (send_result != WLS_TRANSPORT_OK) {
    ++router->egress_drops;
    return WLS_TRANSPORT_OK;
  }
  ++router->egress_datagrams;
  return WLS_TRANSPORT_OK;
}
#endif

uint16_t wls_h3_datagram_router_bound_port(
  const wls_h3_datagram_router *router) {
  return router ? router->bound_port : 0;
}

int wls_h3_datagram_router_dup_fd(const wls_h3_datagram_router *router) {
  if (!router || router->wait_fd < 0) {
    return -1;
  }
#ifdef F_DUPFD_CLOEXEC
  return fcntl(router->wait_fd, F_DUPFD_CLOEXEC, 0);
#else
  int duplicate = dup(router->wait_fd);
  if (duplicate >= 0 && wls_set_cloexec(duplicate) != 0) {
    close(duplicate);
    return -1;
  }
  return duplicate;
#endif
}

int wls_h3_datagram_router_wait_fd(const wls_h3_datagram_router *router) {
  return router ? router->wait_fd : -1;
}

int wls_h3_datagram_router_poll(wls_h3_datagram_router *router,
                              int timeout_ms, uint32_t *processed) {
#if !defined(__APPLE__)
  (void)router;
  (void)timeout_ms;
  (void)processed;
  return WLS_TRANSPORT_UNSUPPORTED;
#else
  if (!router || !processed) {
    return WLS_TRANSPORT_INVALID_ARGUMENT;
  }
  *processed = 0;
  if (router->listener_io.fd < 0 || router->wait_fd < 0) {
    return WLS_TRANSPORT_NOT_BOUND;
  }
  ngtcp2_tstamp now = wls_now();
  int ingress_activity = 0;
  if (router->pending_ingress_length != 0) {
    wls_h3_router_endpoint *pending_endpoint = NULL;
    for (size_t index = 0; index < router->endpoint_count; ++index) {
      wls_h3_router_endpoint *candidate = &router->endpoints[index];
      if (candidate->worker_id == router->pending_ingress_worker_id &&
          candidate->generation == router->pending_ingress_generation &&
          router->route_epoch == router->pending_ingress_route_epoch) {
        pending_endpoint = candidate;
        break;
      }
    }
    int clear_pending = pending_endpoint == NULL ||
      now < router->pending_ingress_queued_at ||
      now - router->pending_ingress_queued_at > WLS_H3_ROUTER_INGRESS_TTL;
    if (!clear_pending) {
      ssize_t sent;
      do {
        sent = send(pending_endpoint->channel_fd,
                    router->pending_ingress_payload,
                    router->pending_ingress_length, 0);
      } while (sent < 0 && errno == EINTR);
      if (sent >= 0 && (size_t)sent == router->pending_ingress_length) {
        ++router->routed_datagrams;
        ++router->ingress_queue_sends;
        ++ingress_activity;
        clear_pending = 1;
      } else if (sent < 0 &&
                 (errno == EAGAIN || errno == EWOULDBLOCK ||
                  errno == ENOBUFS)) {
        ++router->ingress_queue_retries;
      } else {
        ++router->ingress_drops;
        ++router->ingress_queue_drops;
        clear_pending = 1;
      }
    } else {
      ++router->ingress_drops;
      ++router->ingress_queue_drops;
    }
    if (clear_pending) {
      router->pending_ingress_length = 0;
      OPENSSL_cleanse(router->pending_ingress_payload,
                      sizeof(router->pending_ingress_payload));
      if (pending_endpoint && pending_endpoint->channel_fd >= 0) {
        struct kevent remove_write;
        EV_SET(&remove_write, (uintptr_t)pending_endpoint->channel_fd,
               EVFILT_WRITE, EV_DELETE, 0, 0, NULL);
        (void)kevent(router->wait_fd, &remove_write, 1, NULL, 0, NULL);
      }
      struct kevent resume_ingress;
      EV_SET(&resume_ingress, (uintptr_t)router->listener_io.fd,
             EVFILT_READ, EV_ENABLE, 0, 0, NULL);
      int resume_result;
      do {
        resume_result = kevent(router->wait_fd, &resume_ingress, 1,
                               NULL, 0, NULL);
      } while (resume_result < 0 && errno == EINTR);
      if (resume_result < 0) {
        wls_set_error("resume HTTP/3 Router public ingress: %s",
                      strerror(errno));
        return WLS_TRANSPORT_SOCKET_ERROR;
      }
    }
  }
  wls_router_sweep_authorizations(router, now, 0);
  int activity = ingress_activity +
    wls_router_flush_pending_terminal_closes(router, now);
  activity += wls_router_flush_pending_egress(router, now);
  wls_router_update_terminal_write_interest(router);
  if (timeout_ms < 0) {
    timeout_ms = 0;
  }
  struct timespec timeout = {
    .tv_sec = timeout_ms / 1000,
    .tv_nsec = (long)(timeout_ms % 1000) * 1000000L,
  };
  struct kevent events[WLS_H3_ROUTER_KQUEUE_EVENT_BATCH];
  int event_count;
  do {
    event_count = kevent(router->wait_fd, NULL, 0, events,
                         WLS_H3_ROUTER_KQUEUE_EVENT_BATCH, &timeout);
  } while (event_count < 0 && errno == EINTR);
  if (event_count < 0) {
    wls_set_error("kevent HTTP/3 Datagram Router: %s", strerror(errno));
    return WLS_TRANSPORT_SOCKET_ERROR;
  }
  if (event_count == 0) {
    if (activity != 0) {
      *processed = (uint32_t)activity;
      return WLS_TRANSPORT_OK;
    }
    return WLS_TRANSPORT_AGAIN;
  }

  for (int event_index = 0; event_index < event_count; ++event_index) {
    const struct kevent *event = &events[event_index];
    if ((event->flags & EV_ERROR) != 0 && event->data != 0) {
      if ((int)event->ident != router->listener_io.fd) {
        ++router->egress_drops;
        continue;
      }
      wls_set_error("HTTP/3 Datagram Router kqueue event error: %s",
                    strerror((int)event->data));
      return WLS_TRANSPORT_SOCKET_ERROR;
    }
    if (event->filter == EVFILT_WRITE &&
        (int)event->ident == router->listener_io.fd) {
      activity += wls_router_flush_pending_terminal_closes(
        router, wls_now());
      if (router->pending_terminal_closes == 0) {
        activity += wls_router_flush_pending_egress(router, wls_now());
      }
      continue;
    }
    if (event->filter != EVFILT_READ) {
      continue;
    }
    if ((int)event->ident != router->listener_io.fd) {
      wls_h3_router_endpoint *endpoint = NULL;
      for (size_t index = 0; index < router->endpoint_count; ++index) {
        if (router->endpoints[index].channel_fd == (int)event->ident) {
          endpoint = &router->endpoints[index];
          break;
        }
      }
      if (!endpoint) {
        continue;
      }
      for (unsigned read_count = 0;
           read_count < WLS_H3_MAX_READ_BATCH; ++read_count) {
        int egress_result = wls_router_receive_egress(router, endpoint);
        if (egress_result == WLS_TRANSPORT_AGAIN) {
          break;
        }
        if (egress_result < 0) {
          ++router->egress_drops;
          break;
        }
        ++activity;
      }
      continue;
    }
    unsigned read_count = 0;
    while (read_count < WLS_H3_MAX_READ_BATCH) {
      uint8_t datagram[WLS_H3_MAX_CHANNEL_DATAGRAM_BYTES];
      struct sockaddr_storage local_address;
      struct sockaddr_storage remote_address;
      socklen_t local_address_length = sizeof(local_address);
      socklen_t remote_address_length = sizeof(remote_address);
      ssize_t received = wls_router_receive_datagram(
        router, datagram, sizeof(datagram),
        &local_address, &local_address_length, &remote_address,
        &remote_address_length);
      if (received < 0) {
        if (errno == EAGAIN || errno == EWOULDBLOCK) {
          break;
        }
        if (errno == EINTR) {
          continue;
        }
        if (errno == EMSGSIZE) {
          ++read_count;
          ++activity;
          ++router->received_datagrams;
          ++router->rejected_initials;
          continue;
        }
        wls_set_error("recvmsg HTTP/3 Datagram Router: %s",
                      strerror(errno));
        return WLS_TRANSPORT_SOCKET_ERROR;
      }
      ++read_count;
      ++activity;
      ++router->received_datagrams;
      if (!wls_router_local_address_allowed(
            router, &local_address, local_address_length)) {
        ++router->rejected_initials;
        continue;
      }
      if ((size_t)received > router->max_initial_datagram_bytes) {
        ++router->rejected_initials;
        continue;
      }
      int route_result = wls_router_route_public_datagram(
        router, &local_address, local_address_length, &remote_address,
        remote_address_length, datagram, (size_t)received);
      if (route_result < 0) {
        ++router->ingress_drops;
      }
      if (router->pending_ingress_length != 0) {
        break;
      }
    }
  }
  if (activity == 0) {
    wls_router_update_terminal_write_interest(router);
    return WLS_TRANSPORT_AGAIN;
  }
  wls_router_update_terminal_write_interest(router);
  *processed = (uint32_t)activity;
  return WLS_TRANSPORT_OK;
#endif
}

int wls_h3_datagram_router_get_stats(
  const wls_h3_datagram_router *router,
  wls_h3_datagram_router_stats *stats) {
  if (!router || !stats || stats->struct_size != sizeof(*stats)) {
    return WLS_TRANSPORT_ABI_MISMATCH;
  }
  stats->received_datagrams = router->received_datagrams;
  stats->routed_datagrams = router->routed_datagrams;
  stats->ingress_drops = router->ingress_drops;
  stats->pending_ingress_datagrams =
    router->pending_ingress_length == 0 ? 0 : 1;
  stats->ingress_datagrams_queued = router->ingress_datagrams_queued;
  stats->ingress_queue_sends = router->ingress_queue_sends;
  stats->ingress_queue_retries = router->ingress_queue_retries;
  stats->ingress_queue_drops = router->ingress_queue_drops;
  stats->egress_datagrams = router->egress_datagrams;
  stats->egress_drops = router->egress_drops;
  stats->channel_auth_failures = router->channel_auth_failures;
  stats->retry_sent = router->retry_sent;
  stats->retry_validated = router->retry_validated;
  stats->rejected_initials = router->rejected_initials;
  stats->route_epoch = router->route_epoch;
  stats->active_endpoints = router->endpoint_count;
  stats->accepting_endpoints = 0;
  for (size_t index = 0; index < router->endpoint_count; ++index) {
    stats->accepting_endpoints +=
      router->endpoints[index].accepting_new_connections != 0;
  }
  stats->live_authorizations = router->live_authorizations;
  stats->provisional_authorizations = router->provisional_authorizations;
  stats->established_authorizations = router->established_authorizations;
  stats->closing_authorizations = router->closing_authorizations;
  stats->pending_terminal_closes = router->pending_terminal_closes;
  stats->terminal_closes_cached = router->terminal_closes_cached;
  stats->terminal_close_sends = router->terminal_close_sends;
  stats->terminal_close_resends = router->terminal_close_resends;
  stats->terminal_close_drops = router->terminal_close_drops;
  stats->terminal_close_rate_limited =
    router->terminal_close_rate_limited;
  stats->pending_egress_datagrams = router->pending_egress_count;
  stats->egress_datagrams_queued = router->egress_datagrams_queued;
  stats->egress_queue_sends = router->egress_queue_sends;
  stats->egress_queue_retries = router->egress_queue_retries;
  stats->egress_queue_drops = router->egress_queue_drops;
  return WLS_TRANSPORT_OK;
}

void wls_h3_datagram_router_destroy(wls_h3_datagram_router *router) {
  if (!router) {
    return;
  }
  wls_router_close_endpoints(router);
  if (router->wait_fd >= 0) {
    close(router->wait_fd);
    router->wait_fd = -1;
  }
  wls_io_path_close(&router->listener_io);
  if (router->authorized_paths) {
    for (size_t index = 0; index < WLS_H3_ROUTER_PATH_CAPACITY; ++index) {
      free(router->authorized_paths[index].terminal_packet);
      router->authorized_paths[index].terminal_packet = NULL;
    }
  }
  free(router->authorized_paths);
  router->authorized_paths = NULL;
  free(router->pending_egress);
  router->pending_egress = NULL;
  router->pending_egress_head = 0;
  router->pending_egress_count = 0;
  OPENSSL_cleanse(router->retry_secret, sizeof(router->retry_secret));
  free(router);
}
