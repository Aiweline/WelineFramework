#ifndef WLS_TRANSPORT_ABI_H
#define WLS_TRANSPORT_ABI_H
#include <stddef.h>
#include <stdint.h>

#if defined(_WIN32)
#  define WLS_EXPORT __declspec(dllexport)
#else
#  define WLS_EXPORT __attribute__((visibility("default")))
#endif

#ifdef __cplusplus
extern "C" {
#endif

#define WLS_TRANSPORT_ABI_VERSION 0x00020009u
#define WLS_TRANSPORT_ABI_MAJOR(v) ((uint32_t)(v) >> 16)

typedef struct wls_tls_context wls_tls_context;
typedef struct wls_h3_server wls_h3_server;
typedef struct wls_h3_datagram_router wls_h3_datagram_router;

enum wls_transport_result {
  WLS_TRANSPORT_OK = 0,
  WLS_TRANSPORT_AGAIN = 1,
  WLS_TRANSPORT_UNSUPPORTED = 2,
  WLS_TRANSPORT_INVALID_ARGUMENT = -1,
  WLS_TRANSPORT_ABI_MISMATCH = -2,
  WLS_TRANSPORT_NOMEM = -3,
  WLS_TRANSPORT_TLS_ERROR = -4,
  WLS_TRANSPORT_SOCKET_ERROR = -5,
  WLS_TRANSPORT_QUIC_ERROR = -6,
  WLS_TRANSPORT_HTTP3_ERROR = -7,
  WLS_TRANSPORT_BUFFER_TOO_SMALL = -8,
  WLS_TRANSPORT_NOT_BOUND = -9,
  WLS_TRANSPORT_NOT_FOUND = -10,
  WLS_TRANSPORT_INTERNAL_ERROR = -11
};

enum wls_h3_connection_error_stage {
  WLS_H3_CONNECTION_ERROR_STAGE_NONE = 0,
  WLS_H3_CONNECTION_ERROR_STAGE_READ_PKT = 1,
  WLS_H3_CONNECTION_ERROR_STAGE_FLUSH = 2,
  WLS_H3_CONNECTION_ERROR_STAGE_CALLBACK = 3,
  WLS_H3_CONNECTION_ERROR_STAGE_EXPIRY = 4
};

enum wls_tls_capability {
  WLS_TLS_CAP_TLS13 = 1u << 0,
  WLS_TLS_CAP_ALPN = 1u << 1,
  WLS_TLS_CAP_QUIC = 1u << 2,
  WLS_TLS_CAP_TCP = 1u << 3,
  WLS_TLS_CAP_SHARED_TICKET_RING = 1u << 4,
  WLS_TLS_CAP_SESSION_REUSE_STATS = 1u << 5
};

enum wls_tls_context_stats_flag {
  WLS_TLS_STATS_RING_ACTIVE = 1u << 0,
  WLS_TLS_STATS_EARLY_DATA_DISABLED = 1u << 1
};

enum wls_h3_request_flag {
  WLS_H3_REQUEST_FLAG_END_STREAM = 1u << 0
};

enum wls_h3_linux_route_state {
  WLS_H3_LINUX_ROUTE_DISABLED = 0,
  WLS_H3_LINUX_ROUTE_STAGED = 1,
  WLS_H3_LINUX_ROUTE_ACTIVE = 2,
  WLS_H3_LINUX_ROUTE_DRAINING = 3,
  WLS_H3_LINUX_ROUTE_FAILED = 4
};

enum wls_h3_linux_route_flag {
  WLS_H3_LINUX_ROUTE_FLAG_NONE = 0,
  WLS_H3_LINUX_ROUTE_FLAG_REQUIRE_BPFFS = 1u << 0
};

typedef struct wls_transport_versions {
  uint32_t struct_size;
  uint32_t abi_version;
  uint32_t ngtcp2_compile;
  uint32_t ngtcp2_runtime;
  uint32_t nghttp3_compile;
  uint32_t nghttp3_runtime;
  uint64_t openssl_compile;
  uint64_t openssl_runtime;
} wls_transport_versions;

typedef struct wls_tls_context_config {
  uint32_t struct_size;
  const char *certificate_file;
  const char *private_key_file;
  const uint8_t *alpn_wire;
  size_t alpn_wire_length;
  int32_t min_tls_version;
  int32_t max_tls_version;
  uint32_t flags;
} wls_tls_context_config;

typedef struct wls_tls_ticket_ring {
  uint32_t struct_size;
  const uint8_t *current_key;
  size_t current_key_length;
  const uint8_t *previous_key;
  size_t previous_key_length;
  uint64_t epoch;
  const char *digest;
  const uint8_t *session_context;
  size_t session_context_length;
  uint32_t ticket_lifetime_seconds;
  uint32_t flags;
} wls_tls_ticket_ring;

typedef struct wls_tls_context_stats {
  uint32_t struct_size;
  uint32_t flags;
  uint64_t ticket_epoch;
  uint64_t handshakes_completed;
  uint64_t full_handshakes;
  uint64_t resumed_handshakes;
  uint64_t tickets_encrypted;
  uint64_t tickets_decrypted_current;
  uint64_t tickets_decrypted_previous;
  uint64_t tickets_rejected;
  uint64_t ticket_errors;
  uint32_t ticket_lifetime_seconds;
  uint32_t reserved32;
  char ticket_digest[65];
} wls_tls_context_stats;

typedef struct wls_h3_server_config {
  uint32_t struct_size;
  uint8_t disable_active_migration;
  uint8_t reserved8[7];
  uint64_t max_idle_timeout_ms;
  uint64_t initial_max_data;
  uint64_t initial_max_stream_data;
  uint64_t initial_max_streams_bidi;
  uint64_t max_connections;
  uint64_t max_active_streams;
  uint64_t retry_token_lifetime_ms;
  const uint8_t *retry_secret;
  size_t retry_secret_length;
  uint32_t max_request_header_bytes;
  uint32_t max_request_body_bytes;
} wls_h3_server_config;
typedef struct wls_h3_linux_route_config {
  uint32_t struct_size;
  uint32_t slot;
  uint32_t slot_count;
  uint32_t flags;
  uint64_t owner_epoch;
  uint64_t generation;
  const char *namespace_key;
} wls_h3_linux_route_config;

typedef struct wls_h3_linux_route_status {
  uint32_t struct_size;
  uint32_t state;
  uint32_t slot;
  uint32_t slot_count;
  uint64_t owner_epoch;
  uint64_t generation;
  uint64_t listener_cookie;
  uint64_t connection_cookie;
  uint64_t active_cids;
  uint32_t program_id;
  uint32_t listen_map_id;
  uint32_t worker_map_id;
  uint32_t count_map_id;
  uint32_t owner_map_id;
  uint32_t reserved32;
  char pin_namespace[256];
} wls_h3_linux_route_status;

typedef struct wls_h3_request {
  uint32_t struct_size;
  uint32_t flags;
  uint64_t token;
  char *peer;
  size_t peer_capacity;
  size_t peer_length;
  uint8_t *raw_request;
  size_t raw_request_capacity;
  size_t raw_request_length;
  uint64_t connection_id;
  int64_t stream_id;
  uint32_t end_stream;
  uint32_t reserved32;
} wls_h3_request;

typedef struct wls_h3_response {
  uint32_t struct_size;
  uint32_t flags;
  uint64_t token;
  const uint8_t *raw_response;
  size_t raw_response_length;
} wls_h3_response;

typedef struct wls_h3_server_stats {
  uint32_t struct_size;
  uint32_t reserved32;
  uint64_t received_datagrams;
  uint64_t accepted_initials;
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
} wls_h3_server_stats;

typedef struct wls_h3_datagram_worker_config {
  uint32_t struct_size;
  uint32_t worker_id;
  uint64_t generation;
  uint16_t public_port;
  uint16_t reserved16;
  uint32_t reserved32;
  const char *channel_path;
  const uint8_t *channel_key;
  size_t channel_key_length;
} wls_h3_datagram_worker_config;

typedef struct wls_h3_datagram_router_config {
  uint32_t struct_size;
  uint32_t max_initial_datagram_bytes;
  uint64_t retry_token_lifetime_ms;
  const uint8_t *retry_secret;
  size_t retry_secret_length;
} wls_h3_datagram_router_config;

typedef struct wls_h3_worker_endpoint {
  uint32_t struct_size;
  uint32_t worker_id;
  uint64_t generation;
  uint8_t accepting_new_connections;
  uint8_t reserved8[7];
  const char *channel_path;
  const uint8_t *channel_key;
  size_t channel_key_length;
} wls_h3_worker_endpoint;

typedef struct wls_h3_datagram_router_stats {
  uint32_t struct_size;
  uint32_t reserved32;
  uint64_t received_datagrams;
  uint64_t routed_datagrams;
  uint64_t ingress_drops;
  uint64_t egress_datagrams;
  uint64_t egress_drops;
  uint64_t channel_auth_failures;
  uint64_t retry_sent;
  uint64_t retry_validated;
  uint64_t rejected_initials;
  uint64_t route_epoch;
  uint64_t active_endpoints;
  uint64_t accepting_endpoints;
  uint64_t live_authorizations;
  uint64_t provisional_authorizations;
  uint64_t established_authorizations;
  uint64_t closing_authorizations;
  uint64_t pending_terminal_closes;
  uint64_t terminal_closes_cached;
  uint64_t terminal_close_sends;
  uint64_t terminal_close_resends;
  uint64_t terminal_close_drops;
  uint64_t terminal_close_rate_limited;
  uint64_t pending_egress_datagrams;
  uint64_t egress_datagrams_queued;
  uint64_t egress_queue_sends;
  uint64_t egress_queue_retries;
  uint64_t egress_queue_drops;
  uint64_t pending_ingress_datagrams;
  uint64_t ingress_datagrams_queued;
  uint64_t ingress_queue_sends;
  uint64_t ingress_queue_retries;
  uint64_t ingress_queue_drops;
} wls_h3_datagram_router_stats;

WLS_EXPORT uint32_t wls_transport_abi_version(void);
WLS_EXPORT const char *wls_transport_build_id(void);
WLS_EXPORT const char *wls_transport_last_error(void);
WLS_EXPORT int wls_transport_get_versions(wls_transport_versions *versions);

WLS_EXPORT int wls_tls_context_new(const wls_tls_context_config *config,
                                   wls_tls_context **out_context);
WLS_EXPORT void wls_tls_context_retain(wls_tls_context *context);
WLS_EXPORT void wls_tls_context_release(wls_tls_context *context);
WLS_EXPORT uint64_t
wls_tls_context_capabilities(const wls_tls_context *context);
WLS_EXPORT int wls_tls_context_set_ticket_ring(
  wls_tls_context *context, const wls_tls_ticket_ring *ticket_ring);
WLS_EXPORT int wls_tls_context_get_stats(
  const wls_tls_context *context, wls_tls_context_stats *stats);

WLS_EXPORT int wls_h3_server_new(wls_tls_context *tls_context,
                                 const wls_h3_server_config *config,
                                 wls_h3_server **out_server);
WLS_EXPORT int wls_h3_server_bind(wls_h3_server *server, const char *host,
                                  uint16_t port, int reuse_port);
WLS_EXPORT int wls_h3_server_bind_linux_route(
  wls_h3_server *server, const char *host, uint16_t port,
  const wls_h3_linux_route_config *config);
WLS_EXPORT int wls_h3_server_activate_linux_route(wls_h3_server *server);
WLS_EXPORT int wls_h3_server_get_linux_route_status(
  const wls_h3_server *server, wls_h3_linux_route_status *status);
WLS_EXPORT int wls_h3_server_fd(const wls_h3_server *server);
WLS_EXPORT int wls_h3_server_dup_fd(const wls_h3_server *server);
WLS_EXPORT int wls_h3_server_wait_fd(const wls_h3_server *server);
WLS_EXPORT int wls_h3_server_dup_wait_fd(const wls_h3_server *server);
WLS_EXPORT uint16_t wls_h3_server_bound_port(const wls_h3_server *server);
WLS_EXPORT int wls_h3_server_bind_datagram_worker(
  wls_h3_server *server, const wls_h3_datagram_worker_config *config);
WLS_EXPORT int wls_h3_server_begin_drain(wls_h3_server *server);
WLS_EXPORT int wls_h3_server_poll(wls_h3_server *server, int timeout_ms);
WLS_EXPORT int wls_h3_server_next_request(wls_h3_server *server,
                                          wls_h3_request *request);
WLS_EXPORT int wls_h3_server_respond(wls_h3_server *server,
                                     const wls_h3_response *response);
WLS_EXPORT int wls_h3_server_close_request(wls_h3_server *server,
                                           uint64_t token,
                                           uint64_t app_error_code);
WLS_EXPORT int wls_h3_server_get_stats(const wls_h3_server *server,
                                       wls_h3_server_stats *stats);
WLS_EXPORT void wls_h3_server_destroy(wls_h3_server *server);

WLS_EXPORT int wls_h3_datagram_router_new(
  const wls_h3_datagram_router_config *config,
  wls_h3_datagram_router **out_router);
WLS_EXPORT int wls_h3_datagram_router_bind(
  wls_h3_datagram_router *router, const char *host, uint16_t port);
WLS_EXPORT int wls_h3_datagram_router_publish_workers(
  wls_h3_datagram_router *router, const wls_h3_worker_endpoint *workers,
  size_t worker_count, uint64_t route_epoch);
WLS_EXPORT uint16_t wls_h3_datagram_router_bound_port(
  const wls_h3_datagram_router *router);
WLS_EXPORT int wls_h3_datagram_router_dup_fd(
  const wls_h3_datagram_router *router);
WLS_EXPORT int wls_h3_datagram_router_wait_fd(
  const wls_h3_datagram_router *router);
WLS_EXPORT int wls_h3_datagram_router_poll(
  wls_h3_datagram_router *router, int timeout_ms, uint32_t *processed);
WLS_EXPORT int wls_h3_datagram_router_get_stats(
  const wls_h3_datagram_router *router,
  wls_h3_datagram_router_stats *stats);
WLS_EXPORT void wls_h3_datagram_router_destroy(
  wls_h3_datagram_router *router);

#ifdef __cplusplus
}
#endif

#endif
