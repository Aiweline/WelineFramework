cmake_minimum_required(VERSION 3.20)
project(wls_gateway_native C)

set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_POSITION_INDEPENDENT_CODE ON)
option(WLS_BUILD_TEST_HELPERS "Build native gateway integration-test helpers" OFF)
option(
    WLS_BUILD_HTTP3_TRANSPORT
    "Compile the complete WLS HTTP/3 transport and Linux reuseport gate"
    OFF
)
if(MSVC)
    set(
        CMAKE_MSVC_RUNTIME_LIBRARY
        "MultiThreaded$<$<CONFIG:Debug>:Debug>"
    )
endif()

if(WIN32)
    if(NOT DEFINED WLS_RELEASE_PUBLIC_KEY_HEX)
        set(WLS_RELEASE_PUBLIC_KEY_HEX "0000000000000000000000000000000000000000000000000000000000000000")
    endif()
    find_package(unofficial-sodium CONFIG QUIET)
    if(NOT TARGET unofficial-sodium::sodium)
        find_path(SODIUM_INCLUDE_DIR sodium.h REQUIRED)
        find_library(SODIUM_LIBRARY NAMES sodium libsodium REQUIRED)
    endif()

    add_executable(wls-gateway-broker windows/wls_gateway_broker.c)
    target_compile_definitions(
        wls-gateway-broker
        PRIVATE
        UNICODE
        _UNICODE
        WIN32_LEAN_AND_MEAN
        _WIN32_WINNT=0x0A00
    )
    target_link_libraries(wls-gateway-broker PRIVATE advapi32 bcrypt ntdll shell32 ws2_32)

    add_executable(wls-bounded-command windows/wls_bounded_command.c)
    target_compile_definitions(
        wls-bounded-command
        PRIVATE
        UNICODE
        _UNICODE
        WIN32_LEAN_AND_MEAN
        _WIN32_WINNT=0x0A00
        WLS_NAMED_PIPE_DEADLINE_TRANSPORT=1
    )
    target_link_libraries(wls-bounded-command PRIVATE advapi32 bcrypt)

    add_executable(
        wls-gateway-launcher
        windows/wls_gateway_launcher.c
        windows/wls_gateway_capacity.c
    )
    target_compile_definitions(
        wls-gateway-launcher
        PRIVATE
        UNICODE
        _UNICODE
        WIN32_LEAN_AND_MEAN
        _WIN32_WINNT=0x0A00
        WLS_RELEASE_PUBLIC_KEY_HEX="${WLS_RELEASE_PUBLIC_KEY_HEX}"
    )
    target_link_libraries(
        wls-gateway-launcher
        PRIVATE
        advapi32
        ole32
        shell32
    )
    if(TARGET unofficial-sodium::sodium)
        target_link_libraries(wls-gateway-launcher PRIVATE unofficial-sodium::sodium)
    else()
        target_include_directories(wls-gateway-launcher PRIVATE ${SODIUM_INCLUDE_DIR})
        target_link_libraries(wls-gateway-launcher PRIVATE ${SODIUM_LIBRARY})
    endif()

    add_executable(
        wls-gateway-guardian
        windows/wls_gateway_launcher.c
        windows/wls_gateway_capacity.c
    )
    target_compile_definitions(
        wls-gateway-guardian
        PRIVATE
        UNICODE
        _UNICODE
        WIN32_LEAN_AND_MEAN
        _WIN32_WINNT=0x0A00
        WLS_GUARDIAN_EXECUTABLE=1
        WLS_RELEASE_PUBLIC_KEY_HEX="${WLS_RELEASE_PUBLIC_KEY_HEX}"
    )
    target_link_libraries(
        wls-gateway-guardian
        PRIVATE
        advapi32
        ole32
        shell32
    )
    if(TARGET unofficial-sodium::sodium)
        target_link_libraries(wls-gateway-guardian PRIVATE unofficial-sodium::sodium)
    else()
        target_include_directories(wls-gateway-guardian PRIVATE ${SODIUM_INCLUDE_DIR})
        target_link_libraries(wls-gateway-guardian PRIVATE ${SODIUM_LIBRARY})
    endif()
    if(MSVC)
        target_compile_options(wls-gateway-broker PRIVATE /W4 /WX /sdl)
        # Launcher and Guardian intentionally compile two disjoint entry-point
        # graphs from one release-audited translation unit.  Keep every other
        # level-4 diagnostic fatal while suppressing only the expected
        # variant-local static-function removal notice.
        target_compile_options(
            wls-gateway-launcher PRIVATE /W4 /WX /sdl /wd4505
        )
        target_compile_options(
            wls-gateway-guardian PRIVATE /W4 /WX /sdl /wd4505
        )
        target_compile_options(wls-bounded-command PRIVATE /W4 /WX /sdl)
    else()
        target_compile_options(
            wls-gateway-broker
            PRIVATE
            -Wall
            -Wextra
            -Werror
            -fstack-protector-strong
        )
        target_compile_options(
            wls-gateway-launcher
            PRIVATE
            -Wall
            -Wextra
            -Werror
            -Wno-unused-function
            -fstack-protector-strong
        )
        target_compile_options(
            wls-gateway-guardian
            PRIVATE
            -Wall
            -Wextra
            -Werror
            -Wno-unused-function
            -fstack-protector-strong
        )
        target_compile_options(
            wls-bounded-command
            PRIVATE
            -Wall
            -Wextra
            -Werror
            -fstack-protector-strong
        )
        if(MINGW)
            target_link_options(wls-gateway-broker PRIVATE -municode -static)
            target_link_options(wls-gateway-launcher PRIVATE -municode -static)
            target_link_options(wls-gateway-guardian PRIVATE -municode -static)
            target_link_options(wls-bounded-command PRIVATE -municode -static)
        endif()
    endif()
    if(WLS_BUILD_TEST_HELPERS)
        target_compile_definitions(
            wls-gateway-broker
            PRIVATE
            WLS_NATIVE_TEST_HOOKS=1
        )
        target_compile_definitions(
            wls-gateway-launcher
            PRIVATE
            WLS_NATIVE_TEST_HOOKS=1
        )
        target_compile_definitions(
            wls-gateway-guardian
            PRIVATE
            WLS_NATIVE_TEST_HOOKS=1
        )
        add_executable(
            wls-gateway-test-broker
            windows/wls_gateway_test_broker.c
        )
        target_compile_definitions(
            wls-gateway-test-broker
            PRIVATE
            UNICODE
            _UNICODE
            WIN32_LEAN_AND_MEAN
            _WIN32_WINNT=0x0A00
        )
        if(MSVC)
            target_compile_options(wls-gateway-test-broker PRIVATE /W4 /WX /sdl)
        elseif(MINGW)
            target_compile_options(
                wls-gateway-test-broker
                PRIVATE
                -Wall
                -Wextra
                -Werror
                -fstack-protector-strong
            )
            target_link_options(wls-gateway-test-broker PRIVATE -municode -static)
        endif()
    endif()
    install(
        TARGETS
        wls-gateway-broker
        wls-gateway-launcher
        wls-bounded-command
        RUNTIME DESTINATION bin
    )
    install(
        TARGETS wls-gateway-guardian
        RUNTIME DESTINATION guardian/v1
    )
else()
    find_package(PkgConfig REQUIRED)
    find_package(Threads REQUIRED)
    pkg_check_modules(SODIUM REQUIRED libsodium)
    set(
        WLS_HTTP3_NATIVE_ROOT
        "${CMAKE_CURRENT_SOURCE_DIR}/../../../../Protocol/Http3/Native"
    )

    if(WLS_BUILD_HTTP3_TRANSPORT)
        pkg_check_modules(
            WLS_NGTCP2
            REQUIRED
            IMPORTED_TARGET
            libngtcp2>=1.23.0
        )
        pkg_check_modules(
            WLS_NGTCP2_CRYPTO_OSSL
            REQUIRED
            IMPORTED_TARGET
            libngtcp2_crypto_ossl>=1.23.0
        )
        pkg_check_modules(
            WLS_NGHTTP3
            REQUIRED
            IMPORTED_TARGET
            libnghttp3>=1.16.0
        )
        pkg_check_modules(
            WLS_OPENSSL
            REQUIRED
            IMPORTED_TARGET
            openssl>=3.5.0
        )

        set(WLS_HTTP3_TRANSPORT_SOURCES "${WLS_HTTP3_NATIVE_ROOT}/wls_transport.c")
        if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
            list(
                APPEND
                WLS_HTTP3_TRANSPORT_SOURCES
                "${WLS_HTTP3_NATIVE_ROOT}/wls_linux_reuseport_runtime.c"
            )
        endif()
        add_library(wls-transport SHARED ${WLS_HTTP3_TRANSPORT_SOURCES})
        set_target_properties(wls-transport PROPERTIES OUTPUT_NAME wls_transport)
        target_include_directories(wls-transport PRIVATE "${WLS_HTTP3_NATIVE_ROOT}")
        target_compile_options(
            wls-transport
            PRIVATE
            -O3
            -Wall
            -Wextra
            -Werror
            -fstack-protector-strong
            -fvisibility=hidden
        )
        target_link_libraries(
            wls-transport
            PRIVATE
            PkgConfig::WLS_NGTCP2
            PkgConfig::WLS_NGTCP2_CRYPTO_OSSL
            PkgConfig::WLS_NGHTTP3
            PkgConfig::WLS_OPENSSL
            Threads::Threads
        )
        if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
            target_link_options(
                wls-transport
                PRIVATE
                "-Wl,--version-script=${WLS_HTTP3_NATIVE_ROOT}/wls_transport.map"
                -Wl,--no-undefined
                -Wl,--exclude-libs,ALL
                -Wl,-Bsymbolic-functions
                -Wl,-z,relro,-z,now
            )
        endif()
    endif()

    add_executable(wls-gateway-broker posix/wls_gateway_broker.c)
    target_compile_definitions(wls-gateway-broker PRIVATE _FILE_OFFSET_BITS=64)
    target_compile_options(wls-gateway-broker PRIVATE -Wall -Wextra -Werror -fstack-protector-strong)
    target_include_directories(wls-gateway-broker PRIVATE ${SODIUM_INCLUDE_DIRS})
    target_link_directories(wls-gateway-broker PRIVATE ${SODIUM_LIBRARY_DIRS})
    target_link_libraries(wls-gateway-broker PRIVATE ${SODIUM_LIBRARIES} Threads::Threads)
    if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
        target_compile_definitions(wls-gateway-broker PRIVATE _GNU_SOURCE)
        if(WLS_BUILD_HTTP3_TRANSPORT)
            find_program(
                WLS_BPF_CLANG
                NAMES clang-18
                REQUIRED
            )
            find_program(WLS_BPF_PHP NAMES php REQUIRED)
            set(WLS_BPF_CLANG_MAJOR 18)
            set(
                WLS_HTTP3_BPF_SOURCE
                "${WLS_HTTP3_NATIVE_ROOT}/wls_linux_reuseport_bpf.c"
            )
            file(SHA256 "${WLS_HTTP3_BPF_SOURCE}" WLS_HTTP3_BPF_SOURCE_SHA256)
            set(
                WLS_HTTP3_BPF_OBJECT
                "${CMAKE_CURRENT_BINARY_DIR}/wls_linux_reuseport_bpf.o"
            )
            set(
                WLS_HTTP3_BPF_GENERATOR
                "${CMAKE_CURRENT_SOURCE_DIR}/posix/wls_linux_reuseport_bpf_header_generator.php"
            )
            set(
                WLS_HTTP3_BPF_GENERATED_HEADER
                "${CMAKE_CURRENT_BINARY_DIR}/wls_linux_reuseport_bpf_generated.h"
            )
            set(WLS_BPF_SYSTEM_INCLUDES)
            if(CMAKE_LIBRARY_ARCHITECTURE AND
               IS_DIRECTORY "/usr/include/${CMAKE_LIBRARY_ARCHITECTURE}")
                list(
                    APPEND
                    WLS_BPF_SYSTEM_INCLUDES
                    "-I/usr/include/${CMAKE_LIBRARY_ARCHITECTURE}"
                )
            endif()
            add_custom_command(
                OUTPUT "${WLS_HTTP3_BPF_OBJECT}"
                COMMAND
                    "${WLS_BPF_CLANG}"
                    --target=bpfel
                    -std=c11
                    -O2
                    -g0
                    -Wall
                    -Wextra
                    -Werror
                    ${WLS_BPF_SYSTEM_INCLUDES}
                    -c
                    "${WLS_HTTP3_BPF_SOURCE}"
                    -o
                    "${WLS_HTTP3_BPF_OBJECT}"
                DEPENDS
                    "${WLS_HTTP3_BPF_SOURCE}"
                COMMENT "Compile the production Linux HTTP/3 SK_REUSEPORT eBPF source"
                VERBATIM
            )
            add_custom_target(
                wls-http3-bpf-object
                DEPENDS "${WLS_HTTP3_BPF_OBJECT}"
            )
            add_custom_command(
                OUTPUT "${WLS_HTTP3_BPF_GENERATED_HEADER}"
                COMMAND
                    "${WLS_BPF_PHP}"
                    "${WLS_HTTP3_BPF_GENERATOR}"
                    "--object=${WLS_HTTP3_BPF_OBJECT}"
                    "--source=${WLS_HTTP3_BPF_SOURCE}"
                    "--clang=${WLS_BPF_CLANG}"
                    "--output=${WLS_HTTP3_BPF_GENERATED_HEADER}"
                DEPENDS
                    "${WLS_HTTP3_BPF_OBJECT}"
                    "${WLS_HTTP3_BPF_SOURCE}"
                    "${WLS_HTTP3_BPF_GENERATOR}"
                COMMENT "Regenerate the bounded clang-18 BPF header candidate"
                VERBATIM
            )
            add_custom_target(
                wls-http3-bpf-header-candidate
                DEPENDS "${WLS_HTTP3_BPF_GENERATED_HEADER}"
            )
            add_executable(
                wls-http3-reuseport-self-test
                posix/wls_linux_reuseport_runtime_self_test.c
                "${WLS_HTTP3_NATIVE_ROOT}/wls_linux_reuseport_runtime.c"
            )
            add_dependencies(
                wls-http3-reuseport-self-test
                wls-http3-bpf-object
                wls-http3-bpf-header-candidate
            )
            target_include_directories(
                wls-http3-reuseport-self-test
                PRIVATE
                "${WLS_HTTP3_NATIVE_ROOT}"
            )
            target_compile_options(
                wls-http3-reuseport-self-test
                PRIVATE
                -Wall
                -Wextra
                -Werror
                -fstack-protector-strong
            )
            target_compile_definitions(
                wls-http3-reuseport-self-test
                PRIVATE
                WLS_BPF_EXPECTED_CLANG_MAJOR=${WLS_BPF_CLANG_MAJOR}
                WLS_BPF_EXPECTED_SOURCE_SHA256="${WLS_HTTP3_BPF_SOURCE_SHA256}"
            )
            target_link_libraries(
                wls-http3-reuseport-self-test
                PRIVATE
                wls-transport
            )
            add_custom_target(
                wls-http3-native-gate
                DEPENDS
                    wls-transport
                    wls-http3-bpf-object
                    wls-http3-bpf-header-candidate
                    wls-http3-reuseport-self-test
            )
        endif()
    elseif(CMAKE_SYSTEM_NAME STREQUAL "Darwin")
        target_compile_definitions(wls-gateway-broker PRIVATE _DARWIN_C_SOURCE)
        # proc_pidinfo/proc_pidpath are exported by libproc, not by the
        # default C runtime link set. Keep the process-attestation dependency
        # explicit for both CMake packages and the direct integration build.
        target_link_libraries(wls-gateway-broker PRIVATE proc)
    endif()

    if(NOT DEFINED WLS_RELEASE_PUBLIC_KEY_HEX)
        set(WLS_RELEASE_PUBLIC_KEY_HEX "0000000000000000000000000000000000000000000000000000000000000000")
    endif()
    add_executable(wls-gateway-launcher posix/wls_gateway_launcher.c)
    target_compile_definitions(
        wls-gateway-launcher
        PRIVATE
        _FILE_OFFSET_BITS=64
        WLS_RELEASE_PUBLIC_KEY_HEX="${WLS_RELEASE_PUBLIC_KEY_HEX}"
    )
    target_compile_options(wls-gateway-launcher PRIVATE -Wall -Wextra -Werror -fstack-protector-strong)
    target_include_directories(wls-gateway-launcher PRIVATE ${SODIUM_INCLUDE_DIRS})
    target_link_directories(wls-gateway-launcher PRIVATE ${SODIUM_LIBRARY_DIRS})
    target_link_libraries(wls-gateway-launcher PRIVATE ${SODIUM_LIBRARIES})
    if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
        target_compile_definitions(wls-gateway-launcher PRIVATE _GNU_SOURCE)
    elseif(CMAKE_SYSTEM_NAME STREQUAL "Darwin")
        target_compile_definitions(wls-gateway-launcher PRIVATE _DARWIN_C_SOURCE)
        target_link_libraries(wls-gateway-launcher PRIVATE proc)
    endif()
endif()

if(WIN32)
    install(
        TARGETS wls-gateway-broker wls-gateway-launcher wls-bounded-command
        RUNTIME DESTINATION bin
    )
else()
    install(TARGETS wls-gateway-broker wls-gateway-launcher RUNTIME DESTINATION bin)
endif()
