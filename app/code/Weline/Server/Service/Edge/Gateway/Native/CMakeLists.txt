cmake_minimum_required(VERSION 3.20)
project(wls_gateway_native C)

set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_POSITION_INDEPENDENT_CODE ON)
option(WLS_BUILD_TEST_HELPERS "Build native gateway integration-test helpers" OFF)
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
    )
    target_link_libraries(wls-bounded-command PRIVATE advapi32 bcrypt)

    add_executable(wls-gateway-launcher windows/wls_gateway_launcher.c)
    target_compile_definitions(
        wls-gateway-launcher
        PRIVATE
        UNICODE
        _UNICODE
        WIN32_LEAN_AND_MEAN
        _WIN32_WINNT=0x0A00
        WLS_RELEASE_PUBLIC_KEY_HEX="${WLS_RELEASE_PUBLIC_KEY_HEX}"
    )
    target_link_libraries(wls-gateway-launcher PRIVATE advapi32)
    if(TARGET unofficial-sodium::sodium)
        target_link_libraries(wls-gateway-launcher PRIVATE unofficial-sodium::sodium)
    else()
        target_include_directories(wls-gateway-launcher PRIVATE ${SODIUM_INCLUDE_DIR})
        target_link_libraries(wls-gateway-launcher PRIVATE ${SODIUM_LIBRARY})
    endif()
    if(MSVC)
        target_compile_options(wls-gateway-broker PRIVATE /W4 /WX /sdl)
        target_compile_options(wls-gateway-launcher PRIVATE /W4 /WX /sdl)
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
            target_link_options(wls-bounded-command PRIVATE -municode -static)
        endif()
    endif()
    if(WLS_BUILD_TEST_HELPERS)
        target_compile_definitions(
            wls-gateway-broker
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
else()
    find_package(PkgConfig REQUIRED)
    find_package(Threads REQUIRED)
    pkg_check_modules(SODIUM REQUIRED libsodium)

    add_executable(wls-gateway-broker posix/wls_gateway_broker.c)
    target_compile_definitions(wls-gateway-broker PRIVATE _FILE_OFFSET_BITS=64)
    target_compile_options(wls-gateway-broker PRIVATE -Wall -Wextra -Werror -fstack-protector-strong)
    target_include_directories(wls-gateway-broker PRIVATE ${SODIUM_INCLUDE_DIRS})
    target_link_directories(wls-gateway-broker PRIVATE ${SODIUM_LIBRARY_DIRS})
    target_link_libraries(wls-gateway-broker PRIVATE ${SODIUM_LIBRARIES} Threads::Threads)
    if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
        target_compile_definitions(wls-gateway-broker PRIVATE _GNU_SOURCE)

        set(
            WLS_HTTP3_NATIVE_ROOT
            "${CMAKE_CURRENT_SOURCE_DIR}/../../../../Protocol/Http3/Native"
        )
        add_executable(
            wls-http3-reuseport-self-test
            posix/wls_linux_reuseport_runtime_self_test.c
            "${WLS_HTTP3_NATIVE_ROOT}/wls_linux_reuseport_runtime.c"
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
