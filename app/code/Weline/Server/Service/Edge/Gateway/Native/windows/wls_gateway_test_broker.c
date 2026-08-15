#define _CRT_SECURE_NO_WARNINGS
#include <windows.h>
#include <stddef.h>
#include <stdio.h>
#include <stdlib.h>
#include <wchar.h>

#define WLS_TEST_PATH_CHARS 32768U

static const wchar_t *wls_test_argument(
    int argc,
    wchar_t **argv,
    const wchar_t *name
)
{
    int index;
    for (index = 1; index + 1 < argc; index++) {
        if (wcscmp(argv[index], name) == 0) return argv[index + 1];
    }
    return NULL;
}

static int wls_test_join(
    wchar_t *output,
    size_t capacity,
    const wchar_t *root,
    const wchar_t *relative
)
{
    size_t length;
    if (output == NULL || root == NULL || relative == NULL) return 1;
    length = wcslen(root);
    return _snwprintf_s(
        output,
        capacity,
        _TRUNCATE,
        length > 0U && (root[length - 1U] == L'\\' || root[length - 1U] == L'/')
            ? L"%ls%ls"
            : L"%ls\\%ls",
        root,
        relative
    ) < 0 ? 1 : 0;
}

static int wls_test_record_start(const wchar_t *path)
{
    static const char line[] = "started\r\n";
    HANDLE file = CreateFileW(
        path,
        FILE_APPEND_DATA,
        FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
        NULL,
        OPEN_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH,
        NULL
    );
    DWORD written = 0U;
    int result = 1;
    if (file != INVALID_HANDLE_VALUE
        && WriteFile(file, line, (DWORD)(sizeof(line) - 1U), &written, NULL)
        && written == (DWORD)(sizeof(line) - 1U)
        && FlushFileBuffers(file)) {
        result = 0;
    }
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return result;
}

static int wls_test_make_directory(const wchar_t *path)
{
    return CreateDirectoryW(path, NULL)
        || GetLastError() == ERROR_ALREADY_EXISTS ? 0 : 1;
}

static int wls_test_write_identity(
    const wchar_t *home,
    DWORD pid,
    const FILETIME *created
) {
    wchar_t pid_directory[WLS_TEST_PATH_CHARS];
    wchar_t pid_path[WLS_TEST_PATH_CHARS];
    wchar_t identity_path[WLS_TEST_PATH_CHARS];
    char pid_payload[32];
    char identity_payload[256];
    ULARGE_INTEGER creation;
    HANDLE file = INVALID_HANDLE_VALUE;
    DWORD written = 0U;
    int pid_length;
    int identity_length;
    int result = 1;
    creation.LowPart = created->dwLowDateTime;
    creation.HighPart = created->dwHighDateTime;
    if (wls_test_join(
            pid_directory, WLS_TEST_PATH_CHARS, home, L"nginx-pid"
        ) != 0
        || wls_test_join(
            pid_path, WLS_TEST_PATH_CHARS, pid_directory, L"nginx.pid"
        ) != 0
        || wls_test_join(
            identity_path,
            WLS_TEST_PATH_CHARS,
            home,
            L"trust\\test-nginx-process.identity"
        ) != 0
        || wls_test_make_directory(pid_directory) != 0) {
        return 1;
    }
    pid_length = snprintf(
        pid_payload, sizeof(pid_payload), "%lu\r\n", (unsigned long)pid
    );
    identity_length = snprintf(
        identity_payload,
        sizeof(identity_payload),
        "WLS-TEST-NGINX-PROCESS/1\r\npid=%lu\r\ncreation_time=%llu\r\n",
        (unsigned long)pid,
        creation.QuadPart
    );
    if (pid_length <= 0 || (size_t)pid_length >= sizeof(pid_payload)
        || identity_length <= 0
        || (size_t)identity_length >= sizeof(identity_payload)) return 1;
    file = CreateFileW(
        pid_path, GENERIC_WRITE, FILE_SHARE_READ, NULL, CREATE_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH, NULL
    );
    if (file == INVALID_HANDLE_VALUE
        || !WriteFile(file, pid_payload, (DWORD)pid_length, &written, NULL)
        || written != (DWORD)pid_length || !FlushFileBuffers(file)) goto cleanup;
    CloseHandle(file);
    file = CreateFileW(
        identity_path, GENERIC_WRITE, FILE_SHARE_READ, NULL, CREATE_ALWAYS,
        FILE_ATTRIBUTE_NORMAL | FILE_FLAG_WRITE_THROUGH, NULL
    );
    written = 0U;
    if (file == INVALID_HANDLE_VALUE
        || !WriteFile(
            file, identity_payload, (DWORD)identity_length, &written, NULL
        )
        || written != (DWORD)identity_length || !FlushFileBuffers(file)) goto cleanup;
    result = 0;
cleanup:
    if (file != INVALID_HANDLE_VALUE) CloseHandle(file);
    return result;
}

static int wls_test_start_nginx(
    const wchar_t *home,
    DWORD adopted_pid,
    const wchar_t *data_plane_job_name
) {
    wchar_t nginx[WLS_TEST_PATH_CHARS];
    wchar_t command[WLS_TEST_PATH_CHARS + 64U];
    STARTUPINFOW startup;
    PROCESS_INFORMATION process;
    HANDLE data_plane_job = NULL;
    HANDLE adopted = NULL;
    FILETIME created;
    FILETIME exited;
    FILETIME kernel;
    FILETIME user;
    DWORD pid = adopted_pid;
    if (home == NULL || data_plane_job_name == NULL
        || wcsncmp(
            data_plane_job_name,
            L"Global\\WelineWlsGatewayV2DataPlane-",
            35U
        ) != 0) {
        return 1;
    }
    if (adopted_pid > 0U) {
        adopted = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, adopted_pid);
        if (adopted == NULL
            || !GetProcessTimes(adopted, &created, &exited, &kernel, &user)) {
            if (adopted != NULL) CloseHandle(adopted);
            return 1;
        }
        CloseHandle(adopted);
        return wls_test_write_identity(home, adopted_pid, &created);
    }
    if (wls_test_join(
            nginx, WLS_TEST_PATH_CHARS, home, L"slots\\A\\bin\\nginx.exe"
        ) != 0
        || _snwprintf_s(
            command,
            sizeof(command) / sizeof(command[0]),
            _TRUNCATE,
            L"\"%ls\" --fake-nginx",
            nginx
        ) < 0) {
        return 1;
    }
    ZeroMemory(&startup, sizeof(startup));
    ZeroMemory(&process, sizeof(process));
    startup.cb = sizeof(startup);
    data_plane_job = OpenJobObjectW(
        JOB_OBJECT_ASSIGN_PROCESS, FALSE, data_plane_job_name
    );
    if (data_plane_job == NULL
        || !CreateProcessW(
            nginx, command, NULL, NULL, FALSE,
            CREATE_NO_WINDOW | CREATE_SUSPENDED,
            NULL, NULL, &startup, &process
        )
        || !AssignProcessToJobObject(data_plane_job, process.hProcess)
        || ResumeThread(process.hThread) == (DWORD)-1) {
        if (process.hProcess != NULL) {
            (void)TerminateProcess(process.hProcess, 1U);
            CloseHandle(process.hProcess);
        }
        if (process.hThread != NULL) CloseHandle(process.hThread);
        if (data_plane_job != NULL) CloseHandle(data_plane_job);
        return 1;
    }
    CloseHandle(process.hThread);
    CloseHandle(data_plane_job);
    data_plane_job = NULL;
    pid = process.dwProcessId;
    if (!GetProcessTimes(process.hProcess, &created, &exited, &kernel, &user)
        || wls_test_write_identity(home, pid, &created) != 0) {
        TerminateProcess(process.hProcess, 1U);
        CloseHandle(process.hProcess);
        return 1;
    }
    CloseHandle(process.hProcess);
    return 0;
}

int wmain(int argc, wchar_t **argv)
{
    const wchar_t *home = wls_test_argument(argc, argv, L"--home");
    const wchar_t *stop_event_name = wls_test_argument(argc, argv, L"--stop-event");
    const wchar_t *ready_event_name = wls_test_argument(argc, argv, L"--ready-event");
    const wchar_t *data_plane_job_name = wls_test_argument(
        argc, argv, L"--data-plane-job"
    );
    const wchar_t *adopted_pid_text = wls_test_argument(
        argc, argv, L"--adopted-nginx-pid"
    );
    wchar_t marker[WLS_TEST_PATH_CHARS];
    wchar_t hold[WLS_TEST_PATH_CHARS];
    wchar_t fail_first[WLS_TEST_PATH_CHARS];
    DWORD marker_attributes;
    DWORD marker_error = ERROR_SUCCESS;
    DWORD fail_attributes;
    DWORD fail_error;
    ULONGLONG fail_deadline;
    int marker_existed;
    HANDLE stop_event;
    HANDLE ready_event;
    DWORD wait_result;
    DWORD adopted_pid = 0U;
    if (argc == 2 && wcscmp(argv[1], L"--fake-nginx") == 0) {
        Sleep(INFINITE);
        return 0;
    }
    if (adopted_pid_text != NULL) {
        wchar_t *end = NULL;
        unsigned long long value = _wcstoui64(adopted_pid_text, &end, 10);
        if (end == adopted_pid_text || *end != L'\0' || value > MAXDWORD) return 2;
        adopted_pid = (DWORD)value;
    }
    if (home == NULL || stop_event_name == NULL || ready_event_name == NULL
        || data_plane_job_name == NULL
        || wls_test_join(
            marker,
            WLS_TEST_PATH_CHARS,
            home,
            L"state\\test-starts.log"
        ) != 0
        || wls_test_join(
            hold,
            WLS_TEST_PATH_CHARS,
            home,
            L"state\\test-hold"
        ) != 0
        || wls_test_join(
            fail_first,
            WLS_TEST_PATH_CHARS,
            home,
            L"state\\test-fail-first"
        ) != 0) {
        return 2;
    }
    marker_attributes = GetFileAttributesW(marker);
    marker_existed = marker_attributes != INVALID_FILE_ATTRIBUTES;
    if (!marker_existed) {
        marker_error = GetLastError();
        if (marker_error != ERROR_FILE_NOT_FOUND
            && marker_error != ERROR_PATH_NOT_FOUND) {
            return 2;
        }
    }
    if (wls_test_record_start(marker) != 0) return 2;
    /* The initial failure still precedes all data-plane PID authority, but it
     * must happen only after SCM has observed SERVICE_RUNNING.  Otherwise
     * Windows correctly treats it as a start failure and does not apply the
     * configured post-running service recovery action. */
    if (!marker_existed) {
        ready_event = OpenEventW(
            EVENT_MODIFY_STATE | SYNCHRONIZE, FALSE, ready_event_name
        );
        if (ready_event == NULL || !SetEvent(ready_event)) {
            if (ready_event != NULL) CloseHandle(ready_event);
            return 3;
        }
        CloseHandle(ready_event);
        fail_deadline = GetTickCount64() + 40000ULL;
        for (;;) {
            fail_attributes = GetFileAttributesW(fail_first);
            if (fail_attributes == INVALID_FILE_ATTRIBUTES) {
                fail_error = GetLastError();
                if (fail_error == ERROR_FILE_NOT_FOUND
                    || fail_error == ERROR_PATH_NOT_FOUND) {
                    break;
                }
                return 2;
            }
            if ((fail_attributes & (FILE_ATTRIBUTE_DIRECTORY
                    | FILE_ATTRIBUTE_REPARSE_POINT)) != 0U) {
                return 2;
            }
            if (GetTickCount64() >= fail_deadline) return 6;
            Sleep(50U);
        }
        return 7;
    }
    if (wls_test_start_nginx(
            home, adopted_pid, data_plane_job_name
        ) != 0) return 3;
    ready_event = OpenEventW(EVENT_MODIFY_STATE | SYNCHRONIZE, FALSE, ready_event_name);
    if (ready_event == NULL || !SetEvent(ready_event)) {
        if (ready_event != NULL) CloseHandle(ready_event);
        return 3;
    }
    CloseHandle(ready_event);
    if (GetFileAttributesW(hold) == INVALID_FILE_ATTRIBUTES) return 0;
    stop_event = OpenEventW(SYNCHRONIZE, FALSE, stop_event_name);
    if (stop_event == NULL) return 3;
    wait_result = WaitForSingleObject(stop_event, 120000U);
    CloseHandle(stop_event);
    /* Explicit SCM stop owns this deliberately non-zero Broker result. */
    return wait_result == WAIT_OBJECT_0 ? 5 : 4;
}
