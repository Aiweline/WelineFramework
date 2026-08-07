#include "wls_linux_reuseport_runtime.h"

#include <stdint.h>
#include <stdio.h>
#include <string.h>

#if !defined(__linux__)
#error "The WLS HTTP/3 reuseport runtime self-test is Linux-only."
#endif

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
    char error[256];
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
            "activate must reject a disabled route") != 0) {
        return 1;
    }
    if (wls_expect(
            wls_linux_h3_route_insert_cid(
                &route, cid, sizeof(cid), error, sizeof(error)) ==
                WLS_TRANSPORT_INVALID_ARGUMENT,
            "insert_cid must reject a disabled route") != 0) {
        return 1;
    }
    if (wls_expect(
            wls_linux_h3_route_deactivate(&route, error, sizeof(error)) ==
                WLS_TRANSPORT_OK,
            "deactivate must be idempotent on a disabled route") != 0) {
        return 1;
    }
    if (wls_expect(wls_route_is_fail_closed(&route),
                   "argument contracts must not open descriptors") != 0 ||
        wls_expect(route.state == WLS_H3_LINUX_ROUTE_DISABLED,
                   "argument contracts must leave the route disabled") != 0) {
        return 1;
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
    char error[256];
    uint8_t cid[WLS_LINUX_H3_SERVER_CID_LENGTH];
    int bind_result;

    wls_linux_h3_route_init(&route);
    (void)memset(&config, 0, sizeof(config));
    config.struct_size = sizeof(config);
    config.slot = 0;
    config.slot_count = 1;
    config.flags = WLS_H3_LINUX_ROUTE_FLAG_NONE;
    config.owner_epoch = 7;
    config.generation = 3;
    config.namespace_key = "wls-http3-self-test-lifecycle";
    (void)memset(cid, 0x42, sizeof(cid));

    bind_result = wls_linux_h3_route_bind(
        &route, "127.0.0.1", 0, &config, &bound_address, &bound_length,
        &bound_port, error, sizeof(error));
    if (bind_result == WLS_TRANSPORT_OK) {
        if (wls_expect(route.state == WLS_H3_LINUX_ROUTE_STAGED,
                       "successful bind must stage the route") != 0 ||
            wls_expect(route.listener_fd >= 0,
                       "successful bind must open a listener") != 0 ||
            wls_expect(bound_port != 0,
                       "successful bind must publish an ephemeral port") != 0) {
            wls_linux_h3_route_close(&route);
            return 1;
        }

        if (wls_expect(
                wls_linux_h3_route_activate(&route, error, sizeof(error)) ==
                    WLS_TRANSPORT_OK,
                "staged route activation must succeed") != 0 ||
            wls_expect(route.state == WLS_H3_LINUX_ROUTE_ACTIVE,
                       "activation must mark the route active") != 0) {
            wls_linux_h3_route_close(&route);
            return 1;
        }

        if (wls_expect(
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
                       "CID deletion must decrement active_cids") != 0) {
            wls_linux_h3_route_close(&route);
            return 1;
        }

        if (wls_expect(
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
                       "lifecycle close must release all descriptors") != 0) {
            return 1;
        }
        return 0;
    }

    /*
     * Environments without mounted bpffs / CAP_BPF still prove fail-closed
     * cleanup: bind must not leave half-open maps or sockets behind.
     */
    if (wls_expect(bind_result != WLS_TRANSPORT_INVALID_ARGUMENT,
                   "valid bind arguments must not fail as INVALID_ARGUMENT") !=
            0 ||
        wls_expect(
            route.state == WLS_H3_LINUX_ROUTE_FAILED ||
                route.state == WLS_H3_LINUX_ROUTE_DISABLED,
            "failed bind must leave FAILED or DISABLED state") != 0) {
        wls_linux_h3_route_close(&route);
        return 1;
    }
    wls_linux_h3_route_close(&route);
    if (wls_expect(wls_route_is_fail_closed(&route),
                   "failed bind must clean up to fail-closed descriptors") !=
        0) {
        return 1;
    }
    (void)fprintf(
        stderr,
        "wls-http3-reuseport-self-test: bind lifecycle skipped after "
        "environment-limited failure (%s)\n",
        error[0] != 0 ? error : "unknown");
    return 0;
}

int main(void) {
    if (wls_expect(WLS_TRANSPORT_ABI_VERSION == UINT32_C(0x00020009),
                   "unexpected transport ABI") != 0) {
        return 1;
    }
    if (wls_test_init_status_close() != 0 ||
        wls_test_argument_contracts() != 0 ||
        wls_test_bind_lifecycle() != 0) {
        return 1;
    }

    (void)puts("wls-http3-reuseport-self-test: ok");
    return 0;
}
