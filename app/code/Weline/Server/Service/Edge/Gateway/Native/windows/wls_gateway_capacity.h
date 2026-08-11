#ifndef WLS_GATEWAY_CAPACITY_H
#define WLS_GATEWAY_CAPACITY_H

#include <windows.h>

int wls_windows_capacity_requested(int argc, wchar_t **argv);
int wls_windows_capacity_command(int argc, wchar_t **argv);
int wls_windows_capacity_contract_self_test(int emit_evidence);
int wls_windows_programdata_authority(void);

#endif
