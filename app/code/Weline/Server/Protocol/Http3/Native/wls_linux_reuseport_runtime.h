#ifndef WLS_LINUX_REUSEPORT_RUNTIME_H
#define WLS_LINUX_REUSEPORT_RUNTIME_H

#include "wls_transport_abi.h"

#if defined(__linux__)

#include <netinet/in.h>
#include <stdint.h>
#include <sys/socket.h>

#define WLS_LINUX_H3_MAX_ROUTE_SLOTS 64u
#define WLS_LINUX_H3_SERVER_CID_LENGTH 20u
#define WLS_LINUX_H3_PIN_PATH_CAPACITY 512u

typedef struct wls_linux_h3_route_owner {
  uint64_t owner_epoch;
  uint64_t generation;
  uint64_t listener_cookie;
} wls_linux_h3_route_owner;

typedef struct wls_linux_h3_route {
  int listener_fd;
  int connection_fd;
  int wait_fd;
  int listen_map_fd;
  int worker_map_fd;
  int count_map_fd;
  int owner_map_fd;
  int program_fd;
  int lock_fd;
  uint32_t state;
  uint32_t slot;
  uint32_t slot_count;
  uint32_t flags;
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
  char pin_namespace[WLS_LINUX_H3_PIN_PATH_CAPACITY];
  char lock_path[WLS_LINUX_H3_PIN_PATH_CAPACITY];
} wls_linux_h3_route;

void wls_linux_h3_route_init(wls_linux_h3_route *route);
int wls_linux_h3_route_bind(
  wls_linux_h3_route *route, const char *host, uint16_t port,
  const wls_h3_linux_route_config *config,
  struct sockaddr_storage *bound_address, socklen_t *bound_length,
  uint16_t *bound_port, char *error, size_t error_capacity);
int wls_linux_h3_route_activate(
  wls_linux_h3_route *route, char *error, size_t error_capacity);
int wls_linux_h3_route_deactivate(
  wls_linux_h3_route *route, char *error, size_t error_capacity);
int wls_linux_h3_route_insert_cid(
  wls_linux_h3_route *route, const uint8_t *cid, size_t cid_length,
  char *error, size_t error_capacity);
void wls_linux_h3_route_delete_cid(
  wls_linux_h3_route *route, const uint8_t *cid, size_t cid_length);
void wls_linux_h3_route_get_status(
  const wls_linux_h3_route *route, wls_h3_linux_route_status *status);
void wls_linux_h3_route_close(wls_linux_h3_route *route);

#endif
#endif
