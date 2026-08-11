#ifndef WLS_LAUNCHER_RECOVERY_LEDGER_H
#define WLS_LAUNCHER_RECOVERY_LEDGER_H

#include <limits.h>
#include <stdio.h>
#include <string.h>

#define WLS_RECOVERY_WINDOW_MILLISECONDS 900000ULL
#define WLS_RECOVERY_FAILURE_LIMIT 10U
#define WLS_RECOVERY_HEALTH_MILLISECONDS 15000ULL
#define WLS_RECOVERY_BASE_DELAY_SECONDS 5ULL
#define WLS_RECOVERY_MAX_DELAY_SECONDS 300ULL
#define WLS_RECOVERY_LEDGER_CAPACITY 4096U
#define WLS_RECOVERY_STATUS_CAPACITY 2048U

struct wls_recovery_ledger {
    char host_boot_id[65];
    char launcher_generation[65];
    char launcher_identity[65];
    char runtime_generation[65];
    char active_slot;
    char phase[24];
    char reason[32];
    char attempt_id[33];
    unsigned int failure_count;
    unsigned int maintenance_attempt;
    unsigned long long failures[WLS_RECOVERY_FAILURE_LIMIT];
    unsigned long long next_retry_monotonic_ms;
    long long next_retry_wall;
    unsigned long long updated_monotonic_ms;
    long long updated_wall;
};

static inline int wls_recovery_hex(const char *value, size_t length)
{
    size_t index;
    if (value == NULL || strlen(value) != length) return 0;
    for (index = 0U; index < length; index++) {
        if (!((value[index] >= '0' && value[index] <= '9')
            || (value[index] >= 'a' && value[index] <= 'f'))) return 0;
    }
    return 1;
}

static inline int wls_recovery_phase_valid(const char *phase)
{
    return phase != NULL
        && (strcmp(phase, "IDLE") == 0
            || strcmp(phase, "BACKOFF") == 0
            || strcmp(phase, "ATTEMPTING") == 0
            || strcmp(phase, "OBSERVING") == 0);
}

static inline int wls_recovery_reason_valid(const char *reason)
{
    return reason != NULL
        && (strcmp(reason, "NONE") == 0
            || strcmp(reason, "IDENTITY_REBOUND") == 0
            || strcmp(reason, "BOOT_REANCHORED") == 0
            || strcmp(reason, "LEDGER_INVALID") == 0
            || strcmp(reason, "INCOMPLETE_ATTEMPT") == 0
            || strcmp(reason, "STARTING") == 0
            || strcmp(reason, "HEALTH_OBSERVATION") == 0
            || strcmp(reason, "BROKER_EXIT") == 0
            || strcmp(reason, "SPAWN_FAILED") == 0
            || strcmp(reason, "SUPERVISION_FAILED") == 0
            || strcmp(reason, "CONTROLLED_STOP") == 0);
}

static inline unsigned long long wls_recovery_delay_seconds(
    unsigned int maintenance_attempt
) {
    unsigned long long delay = WLS_RECOVERY_BASE_DELAY_SECONDS;
    unsigned int index;
    if (maintenance_attempt == 0U) return 0ULL;
    for (index = 1U; index < maintenance_attempt; index++) {
        if (delay >= WLS_RECOVERY_MAX_DELAY_SECONDS) {
            return WLS_RECOVERY_MAX_DELAY_SECONDS;
        }
        delay *= 2ULL;
    }
    return delay > WLS_RECOVERY_MAX_DELAY_SECONDS
        ? WLS_RECOVERY_MAX_DELAY_SECONDS
        : delay;
}

static inline void wls_recovery_initialize(
    struct wls_recovery_ledger *state,
    const char *boot_id,
    const char *launcher_generation,
    const char *launcher_identity,
    const char *runtime_generation,
    char active_slot,
    unsigned long long now_monotonic,
    long long now_wall,
    const char *reason
) {
    memset(state, 0, sizeof(*state));
    memcpy(state->host_boot_id, boot_id, 65U);
    memcpy(state->launcher_generation, launcher_generation, 65U);
    memcpy(state->launcher_identity, launcher_identity, 65U);
    memcpy(state->runtime_generation, runtime_generation, 65U);
    state->active_slot = active_slot;
    memcpy(state->phase, "BACKOFF", 8U);
    snprintf(state->reason, sizeof(state->reason), "%s", reason);
    memset(state->attempt_id, '0', 32U);
    state->attempt_id[32] = '\0';
    state->updated_monotonic_ms = now_monotonic;
    state->updated_wall = now_wall;
}

static inline int wls_recovery_validate(
    const struct wls_recovery_ledger *state
) {
    size_t index;
    unsigned long long previous = 0ULL;
    if (state == NULL
        || !wls_recovery_hex(state->host_boot_id, 64U)
        || !wls_recovery_hex(state->launcher_generation, 64U)
        || !wls_recovery_hex(state->launcher_identity, 64U)
        || !wls_recovery_hex(state->runtime_generation, 64U)
        || (state->active_slot != 'A' && state->active_slot != 'B')
        || !wls_recovery_phase_valid(state->phase)
        || !wls_recovery_reason_valid(state->reason)
        || !wls_recovery_hex(state->attempt_id, 32U)
        || state->failure_count > WLS_RECOVERY_FAILURE_LIMIT
        || state->updated_monotonic_ms == 0ULL
        || state->updated_wall <= 0LL
        || state->next_retry_wall < 0LL) return 0;
    for (index = 0U; index < WLS_RECOVERY_FAILURE_LIMIT; index++) {
        unsigned long long value = state->failures[index];
        if (index < state->failure_count) {
            if (value == 0ULL || (previous != 0ULL && value < previous)) {
                return 0;
            }
            previous = value;
        } else if (value != 0ULL) {
            return 0;
        }
    }
    if (state->maintenance_attempt == 0U
        && (state->next_retry_monotonic_ms != 0ULL
            || state->next_retry_wall != 0LL)) return 0;
    if (strcmp(state->phase, "IDLE") == 0
        && (state->failure_count != 0U
            || state->maintenance_attempt != 0U
            || state->next_retry_monotonic_ms != 0ULL
            || strcmp(state->reason, "NONE") != 0)) return 0;
    return 1;
}

static inline int wls_recovery_format(
    const struct wls_recovery_ledger *state,
    char *output,
    size_t capacity
) {
    int written;
    if (!wls_recovery_validate(state) || output == NULL || capacity == 0U) {
        return -1;
    }
    written = snprintf(
        output,
        capacity,
        "WLS-LAUNCHER-RECOVERY/1\n"
        "host_boot_id=%s\nlauncher_generation=%s\nlauncher_identity=%s\n"
        "runtime_generation=%s\nactive_slot=%c\nphase=%s\nreason=%s\n"
        "attempt_id=%s\nfailure_count=%u\nmaintenance_attempt=%u\n"
        "failure_01_monotonic_ms=%llu\nfailure_02_monotonic_ms=%llu\n"
        "failure_03_monotonic_ms=%llu\nfailure_04_monotonic_ms=%llu\n"
        "failure_05_monotonic_ms=%llu\nfailure_06_monotonic_ms=%llu\n"
        "failure_07_monotonic_ms=%llu\nfailure_08_monotonic_ms=%llu\n"
        "failure_09_monotonic_ms=%llu\nfailure_10_monotonic_ms=%llu\n"
        "next_retry_monotonic_ms=%llu\nnext_retry_wall=%lld\n"
        "updated_monotonic_ms=%llu\nupdated_wall=%lld\n",
        state->host_boot_id,
        state->launcher_generation,
        state->launcher_identity,
        state->runtime_generation,
        state->active_slot,
        state->phase,
        state->reason,
        state->attempt_id,
        state->failure_count,
        state->maintenance_attempt,
        state->failures[0], state->failures[1],
        state->failures[2], state->failures[3],
        state->failures[4], state->failures[5],
        state->failures[6], state->failures[7],
        state->failures[8], state->failures[9],
        state->next_retry_monotonic_ms,
        state->next_retry_wall,
        state->updated_monotonic_ms,
        state->updated_wall
    );
    return written > 0 && (size_t)written < capacity ? written : -1;
}

static inline int wls_recovery_parse(
    const char *contents,
    size_t length,
    struct wls_recovery_ledger *state
) {
    char slot[2];
    int consumed = 0;
    if (contents == NULL || state == NULL || length == 0U
        || length >= WLS_RECOVERY_LEDGER_CAPACITY) return -1;
    memset(state, 0, sizeof(*state));
    memset(slot, 0, sizeof(slot));
    if (sscanf(
            contents,
            "WLS-LAUNCHER-RECOVERY/1\n"
            "host_boot_id=%64[0-9a-f]\nlauncher_generation=%64[0-9a-f]\n"
            "launcher_identity=%64[0-9a-f]\nruntime_generation=%64[0-9a-f]\n"
            "active_slot=%1[AB]\nphase=%23[A-Z_]\nreason=%31[A-Z_]\n"
            "attempt_id=%32[0-9a-f]\nfailure_count=%u\nmaintenance_attempt=%u\n"
            "failure_01_monotonic_ms=%llu\nfailure_02_monotonic_ms=%llu\n"
            "failure_03_monotonic_ms=%llu\nfailure_04_monotonic_ms=%llu\n"
            "failure_05_monotonic_ms=%llu\nfailure_06_monotonic_ms=%llu\n"
            "failure_07_monotonic_ms=%llu\nfailure_08_monotonic_ms=%llu\n"
            "failure_09_monotonic_ms=%llu\nfailure_10_monotonic_ms=%llu\n"
            "next_retry_monotonic_ms=%llu\nnext_retry_wall=%lld\n"
            "updated_monotonic_ms=%llu\nupdated_wall=%lld\n%n",
            state->host_boot_id,
            state->launcher_generation,
            state->launcher_identity,
            state->runtime_generation,
            slot,
            state->phase,
            state->reason,
            state->attempt_id,
            &state->failure_count,
            &state->maintenance_attempt,
            &state->failures[0], &state->failures[1],
            &state->failures[2], &state->failures[3],
            &state->failures[4], &state->failures[5],
            &state->failures[6], &state->failures[7],
            &state->failures[8], &state->failures[9],
            &state->next_retry_monotonic_ms,
            &state->next_retry_wall,
            &state->updated_monotonic_ms,
            &state->updated_wall,
            &consumed
        ) != 24
        || consumed != (int)length) {
        memset(state, 0, sizeof(*state));
        return -1;
    }
    state->active_slot = slot[0];
    if (!wls_recovery_validate(state)) {
        memset(state, 0, sizeof(*state));
        return -1;
    }
    return 0;
}

static inline void wls_recovery_prune(
    struct wls_recovery_ledger *state,
    unsigned long long now_monotonic
) {
    unsigned int read_index;
    unsigned int write_index = 0U;
    unsigned long long threshold = now_monotonic > WLS_RECOVERY_WINDOW_MILLISECONDS
        ? now_monotonic - WLS_RECOVERY_WINDOW_MILLISECONDS
        : 0ULL;
    for (read_index = 0U; read_index < state->failure_count; read_index++) {
        if (state->failures[read_index] >= threshold
            && state->failures[read_index] <= now_monotonic) {
            state->failures[write_index++] = state->failures[read_index];
        }
    }
    while (write_index < WLS_RECOVERY_FAILURE_LIMIT) {
        state->failures[write_index++] = 0ULL;
    }
    state->failure_count = 0U;
    while (state->failure_count < WLS_RECOVERY_FAILURE_LIMIT
        && state->failures[state->failure_count] != 0ULL) {
        state->failure_count++;
    }
}

static inline void wls_recovery_schedule(
    struct wls_recovery_ledger *state,
    unsigned long long now_monotonic,
    long long now_wall
) {
    unsigned long long delay = wls_recovery_delay_seconds(
        state->maintenance_attempt
    );
    if (delay == 0ULL) {
        state->next_retry_monotonic_ms = 0ULL;
        state->next_retry_wall = 0LL;
        return;
    }
    if (now_monotonic > ULLONG_MAX - delay * 1000ULL
        || now_wall > LLONG_MAX - (long long)delay) {
        state->next_retry_monotonic_ms = ULLONG_MAX;
        state->next_retry_wall = LLONG_MAX;
        return;
    }
    state->next_retry_monotonic_ms = now_monotonic + delay * 1000ULL;
    state->next_retry_wall = now_wall + (long long)delay;
}

/* A syntactically damaged root-owned ledger cannot be trusted as an empty
 * history. Rebind it to the currently verified identities at the circuit
 * threshold so platform restart policy cannot create an unmetered loop. */
static inline void wls_recovery_initialize_invalid(
    struct wls_recovery_ledger *state,
    const char *boot_id,
    const char *launcher_generation,
    const char *launcher_identity,
    const char *runtime_generation,
    char active_slot,
    unsigned long long now_monotonic,
    long long now_wall
) {
    unsigned int index;
    wls_recovery_initialize(
        state,
        boot_id,
        launcher_generation,
        launcher_identity,
        runtime_generation,
        active_slot,
        now_monotonic,
        now_wall,
        "LEDGER_INVALID"
    );
    for (index = 0U; index < WLS_RECOVERY_FAILURE_LIMIT; index++) {
        state->failures[index] = now_monotonic;
    }
    state->failure_count = WLS_RECOVERY_FAILURE_LIMIT;
    state->maintenance_attempt = 1U;
    wls_recovery_schedule(state, now_monotonic, now_wall);
}

static inline void wls_recovery_record_failure(
    struct wls_recovery_ledger *state,
    unsigned long long now_monotonic,
    long long now_wall,
    const char *reason
) {
    wls_recovery_prune(state, now_monotonic);
    if (state->failure_count == WLS_RECOVERY_FAILURE_LIMIT) {
        memmove(
            &state->failures[0],
            &state->failures[1],
            (WLS_RECOVERY_FAILURE_LIMIT - 1U)
                * sizeof(state->failures[0])
        );
        state->failure_count--;
    }
    state->failures[state->failure_count++] = now_monotonic;
    if (state->maintenance_attempt > 0U) {
        if (state->maintenance_attempt < UINT_MAX) {
            state->maintenance_attempt++;
        }
    } else if (state->failure_count >= WLS_RECOVERY_FAILURE_LIMIT) {
        state->maintenance_attempt = 1U;
    }
    memcpy(state->phase, "BACKOFF", 8U);
    snprintf(state->reason, sizeof(state->reason), "%s", reason);
    memset(state->attempt_id, '0', 32U);
    state->attempt_id[32] = '\0';
    state->updated_monotonic_ms = now_monotonic;
    state->updated_wall = now_wall;
    wls_recovery_schedule(state, now_monotonic, now_wall);
}

/* Returns 1 when an incomplete persisted attempt was charged as a failure. */
static inline int wls_recovery_reconcile(
    struct wls_recovery_ledger *state,
    int present,
    const char *boot_id,
    const char *launcher_generation,
    const char *launcher_identity,
    const char *runtime_generation,
    char active_slot,
    unsigned long long now_monotonic,
    long long now_wall
) {
    int incomplete = 0;
    unsigned int index;
    if (!present
        || strcmp(state->launcher_generation, launcher_generation) != 0
        || strcmp(state->launcher_identity, launcher_identity) != 0
        || strcmp(state->runtime_generation, runtime_generation) != 0
        || state->active_slot != active_slot) {
        wls_recovery_initialize(
            state, boot_id, launcher_generation, launcher_identity,
            runtime_generation, active_slot, now_monotonic, now_wall,
            "IDENTITY_REBOUND"
        );
        return 0;
    }
    if (strcmp(state->host_boot_id, boot_id) != 0) {
        if (strcmp(state->phase, "ATTEMPTING") == 0
            || strcmp(state->phase, "OBSERVING") == 0) {
            incomplete = 1;
        }
        for (index = 0U; index < state->failure_count; index++) {
            state->failures[index] = now_monotonic;
        }
        memcpy(state->host_boot_id, boot_id, 65U);
        state->updated_monotonic_ms = now_monotonic;
        state->updated_wall = now_wall;
        memcpy(state->phase, "BACKOFF", 8U);
        memcpy(state->reason, "BOOT_REANCHORED", 16U);
        memset(state->attempt_id, '0', 32U);
        state->attempt_id[32] = '\0';
        if (incomplete) {
            wls_recovery_record_failure(
                state, now_monotonic, now_wall, "INCOMPLETE_ATTEMPT"
            );
        } else {
            wls_recovery_schedule(state, now_monotonic, now_wall);
        }
        return incomplete;
    }
    wls_recovery_prune(state, now_monotonic);
    if (strcmp(state->phase, "ATTEMPTING") == 0
        || strcmp(state->phase, "OBSERVING") == 0) {
        wls_recovery_record_failure(
            state, now_monotonic, now_wall, "INCOMPLETE_ATTEMPT"
        );
        return 1;
    }
    state->updated_monotonic_ms = now_monotonic;
    state->updated_wall = now_wall;
    return 0;
}

static inline void wls_recovery_mark_attempt(
    struct wls_recovery_ledger *state,
    const char attempt_id[33],
    unsigned long long now_monotonic,
    long long now_wall
) {
    memcpy(state->phase, "ATTEMPTING", 11U);
    memcpy(state->reason, "STARTING", 9U);
    memcpy(state->attempt_id, attempt_id, 33U);
    state->next_retry_monotonic_ms = 0ULL;
    state->next_retry_wall = 0LL;
    state->updated_monotonic_ms = now_monotonic;
    state->updated_wall = now_wall;
}

static inline void wls_recovery_mark_observing(
    struct wls_recovery_ledger *state,
    unsigned long long now_monotonic,
    long long now_wall
) {
    memcpy(state->phase, "OBSERVING", 10U);
    memcpy(state->reason, "HEALTH_OBSERVATION", 19U);
    state->updated_monotonic_ms = now_monotonic;
    state->updated_wall = now_wall;
}

static inline void wls_recovery_mark_controlled(
    struct wls_recovery_ledger *state,
    unsigned long long now_monotonic,
    long long now_wall
) {
    memcpy(state->phase, "BACKOFF", 8U);
    memcpy(state->reason, "CONTROLLED_STOP", 16U);
    memset(state->attempt_id, '0', 32U);
    state->attempt_id[32] = '\0';
    state->updated_monotonic_ms = now_monotonic;
    state->updated_wall = now_wall;
    wls_recovery_schedule(state, now_monotonic, now_wall);
}

static inline void wls_recovery_mark_healthy(
    struct wls_recovery_ledger *state,
    unsigned long long now_monotonic,
    long long now_wall
) {
    memset(state->failures, 0, sizeof(state->failures));
    state->failure_count = 0U;
    state->maintenance_attempt = 0U;
    state->next_retry_monotonic_ms = 0ULL;
    state->next_retry_wall = 0LL;
    memcpy(state->phase, "IDLE", 5U);
    memcpy(state->reason, "NONE", 5U);
    memset(state->attempt_id, '0', 32U);
    state->attempt_id[32] = '\0';
    state->updated_monotonic_ms = now_monotonic;
    state->updated_wall = now_wall;
}

static inline const char *wls_recovery_stage(
    const struct wls_recovery_ledger *state
) {
    if (strcmp(state->phase, "ATTEMPTING") == 0) return "ATTEMPTING";
    if (strcmp(state->phase, "OBSERVING") == 0) return "HEALTH_OBSERVING";
    if (state->maintenance_attempt > 0U) return "MAINTENANCE_BACKOFF";
    if (strcmp(state->phase, "IDLE") == 0) return "IDLE";
    return "HIGH_FREQUENCY";
}

static inline int wls_recovery_status_format(
    const struct wls_recovery_ledger *state,
    const char *stage_override,
    const char *reason_override,
    char *output,
    size_t capacity
) {
    const char *stage = stage_override != NULL
        ? stage_override : wls_recovery_stage(state);
    const char *reason = reason_override != NULL
        ? reason_override : state->reason;
    int written;
    if (!wls_recovery_validate(state) || output == NULL || capacity == 0U) {
        return -1;
    }
    written = snprintf(
        output,
        capacity,
        "WLS-LAUNCHER-RECOVERY-STATUS/1\n"
        "projection_only=true\nready=false\ncontrol_authority=false\n"
        "stage=%s\nreason=%s\nhost_boot_id=%s\n"
        "launcher_generation=%s\nlauncher_identity=%s\n"
        "runtime_generation=%s\nactive_slot=%c\nfailure_count=%u\n"
        "maintenance_attempt=%u\nnext_retry_monotonic_ms=%llu\n"
        "next_retry_at=%lld\nupdated_at=%lld\n",
        stage,
        reason,
        state->host_boot_id,
        state->launcher_generation,
        state->launcher_identity,
        state->runtime_generation,
        state->active_slot,
        state->failure_count,
        state->maintenance_attempt,
        state->next_retry_monotonic_ms,
        state->next_retry_wall,
        state->updated_wall
    );
    return written > 0 && (size_t)written < capacity ? written : -1;
}

static inline int wls_recovery_state_self_test(void)
{
    static const char boot_a[] =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
    static const char boot_b[] =
        "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";
    static const char launcher_generation[] =
        "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc";
    static const char launcher_identity[] =
        "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd";
    static const char runtime_generation[] =
        "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee";
    static const char attempt_id[] = "0123456789abcdef0123456789abcdef";
    struct wls_recovery_ledger state;
    struct wls_recovery_ledger parsed;
    char encoded[WLS_RECOVERY_LEDGER_CAPACITY];
    unsigned int index;
    int length;
    wls_recovery_initialize(
        &state, boot_a, launcher_generation, launcher_identity,
        runtime_generation, 'A', 1000ULL, 1000LL, "IDENTITY_REBOUND"
    );
    for (index = 0U; index < 9U; index++) {
        wls_recovery_record_failure(
            &state, 2000ULL + index * 1000ULL,
            1001LL + (long long)index, "BROKER_EXIT"
        );
    }
    if (state.failure_count != 9U || state.maintenance_attempt != 0U) return 1;
    wls_recovery_record_failure(
        &state, 12000ULL, 1012LL, "BROKER_EXIT"
    );
    if (state.failure_count != 10U || state.maintenance_attempt != 1U
        || state.next_retry_monotonic_ms != 17000ULL) return 1;
    length = wls_recovery_format(&state, encoded, sizeof(encoded));
    if (length <= 0
        || wls_recovery_parse(encoded, (size_t)length, &parsed) != 0
        || parsed.maintenance_attempt != 1U) return 1;
    wls_recovery_mark_attempt(&parsed, attempt_id, 17000ULL, 1017LL);
    if (wls_recovery_reconcile(
            &parsed, 1, boot_a, launcher_generation, launcher_identity,
            runtime_generation, 'A', 18000ULL, 1018LL
        ) != 1
        || parsed.maintenance_attempt != 2U
        || parsed.next_retry_monotonic_ms != 28000ULL) return 1;
    if (wls_recovery_reconcile(
            &parsed, 1, boot_b, launcher_generation, launcher_identity,
            runtime_generation, 'A', 1000ULL, 1020LL
        ) != 0
        || strcmp(parsed.host_boot_id, boot_b) != 0
        || parsed.next_retry_monotonic_ms != 11000ULL) return 1;
    wls_recovery_initialize_invalid(
        &parsed, boot_b, launcher_generation, launcher_identity,
        runtime_generation, 'A', 11000ULL, 1030LL
    );
    if (parsed.failure_count != WLS_RECOVERY_FAILURE_LIMIT
        || parsed.maintenance_attempt != 1U
        || parsed.next_retry_monotonic_ms != 16000ULL
        || strcmp(parsed.reason, "LEDGER_INVALID") != 0) return 1;
    wls_recovery_mark_healthy(&parsed, 12000ULL, 1031LL);
    return wls_recovery_validate(&parsed)
        && parsed.failure_count == 0U
        && parsed.maintenance_attempt == 0U
        && strcmp(parsed.phase, "IDLE") == 0
        ? 0 : 1;
}

#endif
