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

int main(void) {
    wls_linux_h3_route route;
    wls_h3_linux_route_status status;

    if (wls_expect(WLS_TRANSPORT_ABI_VERSION == UINT32_C(0x00020009),
                   "unexpected transport ABI") != 0) {
        return 1;
    }

    (void)memset(&route, 0xa5, sizeof(route));
    wls_linux_h3_route_init(&route);
    if (wls_expect(route.listener_fd == -1 && route.connection_fd == -1 &&
                       route.wait_fd == -1 && route.listen_map_fd == -1 &&
                       route.worker_map_fd == -1 && route.count_map_fd == -1 &&
                       route.owner_map_fd == -1 && route.program_fd == -1 &&
                       route.lock_fd == -1,
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
    if (wls_expect(route.listener_fd == -1 && route.connection_fd == -1 &&
                       route.wait_fd == -1 && route.listen_map_fd == -1 &&
                       route.worker_map_fd == -1 && route.count_map_fd == -1 &&
                       route.owner_map_fd == -1 && route.program_fd == -1 &&
                       route.lock_fd == -1,
                   "route close did not retain fail-closed descriptors") != 0) {
        return 1;
    }

    (void)puts("wls-http3-reuseport-self-test: ok");
    return 0;
}
