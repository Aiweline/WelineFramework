/*
 * Windows Recovery Guardian service runner.
 *
 * The immutable Guardian owns the SCM lifetime and the generation-head lock.
 * It is the only process that may turn an authenticated transition request
 * into probation, rollback observation, or a final acknowledgement.  The
 * mutable Launcher is always created suspended, assigned to the Guardian job,
 * verified against the selected generation while the head lock is retained,
 * and only then resumed.
 */

#ifndef WLS_GATEWAY_GUARDIAN_RUNTIME_INC
#define WLS_GATEWAY_GUARDIAN_RUNTIME_INC

#define WLS_GUARDIAN_STARTUP_MILLISECONDS 60000ULL
#define WLS_GUARDIAN_MONITOR_MILLISECONDS 5000U
#define WLS_GUARDIAN_LOCK_RETRY_MILLISECONDS 5000ULL
#define WLS_GUARDIAN_CORE_FAILURE_LIMIT 3U
#define WLS_GUARDIAN_DATA_PLANE_HEARTBEAT_MILLISECONDS 15000ULL
#define WLS_GUARDIAN_DRAIN_MILLISECONDS 300000ULL

enum wls_guardian_selected_generation {
    WLS_GUARDIAN_SELECTED_STABLE = 0,
    WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL = 1,
    WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION = 2,
    WLS_GUARDIAN_SELECTED_CANDIDATE_STABLE = 3,
    WLS_GUARDIAN_SELECTED_RECOVERY_PENDING = 4,
    WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING = 5,
    WLS_GUARDIAN_SELECTED_RECOVERY_STABLE = 6
};

enum wls_guardian_prepare_result {
    WLS_GUARDIAN_PREPARE_OK = 0,
    WLS_GUARDIAN_PREPARE_FAILED = 1,
    WLS_GUARDIAN_PREPARE_RESTORE = 2
};

enum wls_guardian_monitor_result {
    WLS_GUARDIAN_MONITOR_STOPPED = 0,
    WLS_GUARDIAN_MONITOR_FAILED = 1,
    WLS_GUARDIAN_MONITOR_RESTORE = 2,
    WLS_GUARDIAN_MONITOR_RESTART_CHILD = 3
};

struct wls_guardian_runtime_selection {
    enum wls_guardian_selected_generation selected;
    int request_present;
    struct wls_guardian_transition_request request;
    char generation_id[65];
    char launcher_sha256[65];
    char ca_sha256[65];
    char runtime_generation[65];
    char host_id[33];
    unsigned long long launcher_size;
};

struct wls_guardian_runtime_observation {
    int initialized;
    int reset_required;
    unsigned long long last_issued_monotonic_ms;
    unsigned long long required_promotion_since_floor;
    char last_receipt_sha256[65];
    char identity_sha256[65];
};

/* Implemented by wls_gateway_guardian_recovery.inc.  Recovery deliberately
 * acquires and retains a fresh protocol lock for its complete 26-step
 * transaction; the runner never carries a stale reconcile lock into it. */
static int wls_guardian_recovery_restore(
    const wchar_t *home,
    const wchar_t *run_directory,
    const struct wls_guardian_transition_request *request
);

static int wls_guardian_recovery_roots_verify(
    const wchar_t *home,
    const struct wls_guardian_transition_request *request
);

static int wls_guardian_platform_live_verify(
    const wchar_t *home,
    const wchar_t *run_directory,
    const struct wls_guardian_transition_request *request
);

static SRWLOCK wls_guardian_service_child_event_lock = SRWLOCK_INIT;

static void wls_guardian_service_child_events_publish(
    const struct wls_guardian_child_process *child
) {
    AcquireSRWLockExclusive(&wls_guardian_service_child_event_lock);
    wls_guardian_service_child_stop_event = child != NULL
        ? child->stop_event : NULL;
    wls_guardian_service_child_reload_event = child != NULL
        ? child->reload_event : NULL;
    ReleaseSRWLockExclusive(&wls_guardian_service_child_event_lock);
}

static void wls_guardian_service_child_events_clear(
    const struct wls_guardian_child_process *child
) {
    AcquireSRWLockExclusive(&wls_guardian_service_child_event_lock);
    if (child == NULL
        || wls_guardian_service_child_stop_event == child->stop_event) {
        wls_guardian_service_child_stop_event = NULL;
    }
    if (child == NULL
        || wls_guardian_service_child_reload_event == child->reload_event) {
        wls_guardian_service_child_reload_event = NULL;
    }
    ReleaseSRWLockExclusive(&wls_guardian_service_child_event_lock);
}

static void wls_guardian_service_child_control_signal(int reload)
{
    HANDLE event = NULL;
    AcquireSRWLockShared(&wls_guardian_service_child_event_lock);
    event = reload
        ? wls_guardian_service_child_reload_event
        : wls_guardian_service_child_stop_event;
    if (event != NULL && event != INVALID_HANDLE_VALUE) {
        (void)SetEvent(event);
    }
    ReleaseSRWLockShared(&wls_guardian_service_child_event_lock);
}

static int wls_guardian_active_is_candidate(
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request
) {
    return head != NULL && request != NULL
        && sodium_memcmp(
            head->active_generation_id,
            request->candidate_generation_id,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_launcher_sha256,
            request->candidate_launcher_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_ca_sha256,
            request->candidate_ca_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_runtime_generation,
            request->candidate_runtime_generation,
            64U
        ) == 0;
}

static int wls_guardian_active_is_recovery(
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request
) {
    return head != NULL && request != NULL
        && sodium_memcmp(
            head->active_generation_id,
            request->recovery_generation_id,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_launcher_sha256,
            request->recovery_launcher_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_ca_sha256,
            request->recovery_ca_sha256,
            64U
        ) == 0
        && sodium_memcmp(
            head->active_runtime_generation,
            request->recovery_runtime_generation,
            64U
        ) == 0;
}

static int wls_guardian_runtime_add_milliseconds(
    unsigned long long value,
    unsigned long long addition,
    unsigned long long *result
) {
    if (result == NULL || value > ULLONG_MAX - addition
        || value + addition > (unsigned long long)LLONG_MAX) return 1;
    *result = value + addition;
    return 0;
}

static int wls_guardian_protocol_reconcile_retry(
    const wchar_t *home,
    struct wls_guardian_protocol_lock *lock,
    struct wls_guardian_generation_head *head,
    struct wls_guardian_transition_request *request,
    int *request_present
) {
    unsigned long long started = 0ULL;
    unsigned long long now = 0ULL;
    if (wls_guardian_monotonic(&started) != 0) return 1;
    for (;;) {
        if (wls_guardian_protocol_reconcile_and_select(
                home, lock, head, request, request_present
            ) == 0) return 0;
        if (InterlockedCompareExchange(
                &wls_service_stop_requested, 0, 0
            ) != 0
            || wls_guardian_monotonic(&now) != 0
            || now < started
            || now - started >= WLS_GUARDIAN_LOCK_RETRY_MILLISECONDS) {
            return 1;
        }
        Sleep(50U);
    }
}

static int wls_guardian_runtime_selection_copy(
    struct wls_guardian_runtime_selection *selection,
    enum wls_guardian_selected_generation selected,
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request,
    int request_present
) {
    const char *generation;
    const char *launcher;
    const char *ca;
    const char *runtime;
    unsigned long long launcher_size = 0ULL;
    if (selection == NULL || head == NULL
        || (request_present && request == NULL)) return 1;
    if (selected == WLS_GUARDIAN_SELECTED_STABLE) {
        generation = head->active_generation_id;
        launcher = head->active_launcher_sha256;
        ca = head->active_ca_sha256;
        runtime = head->active_runtime_generation;
    } else if (selected == WLS_GUARDIAN_SELECTED_RECOVERY_PENDING
        || selected == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING
        || selected == WLS_GUARDIAN_SELECTED_RECOVERY_STABLE) {
        generation = request->recovery_generation_id;
        launcher = request->recovery_launcher_sha256;
        ca = request->recovery_ca_sha256;
        runtime = request->recovery_runtime_generation;
        launcher_size = request->recovery_launcher_size;
    } else {
        generation = request->candidate_generation_id;
        launcher = request->candidate_launcher_sha256;
        ca = request->candidate_ca_sha256;
        runtime = request->candidate_runtime_generation;
        launcher_size = request->candidate_launcher_size;
    }
    SecureZeroMemory(selection, sizeof(*selection));
    selection->selected = selected;
    selection->request_present = request_present;
    memcpy(selection->generation_id, generation, 65U);
    memcpy(selection->launcher_sha256, launcher, 65U);
    memcpy(selection->ca_sha256, ca, 65U);
    memcpy(selection->runtime_generation, runtime, 65U);
    memcpy(selection->host_id, head->host_id, 33U);
    selection->launcher_size = launcher_size;
    if (request_present) selection->request = *request;
    return 0;
}

static int wls_guardian_runtime_publish_failure_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request,
    int failed_closed,
    int recovery_active
) {
    char boot_id[65];
    struct wls_guardian_generation_head published;
    const char *phase = failed_closed ? "FAILED_CLOSED" : "ROLLBACK_PENDING";
    int result = 1;
    sodium_memzero(boot_id, sizeof(boot_id));
    SecureZeroMemory(&published, sizeof(published));
    if (home == NULL || lock == NULL || head == NULL || request == NULL
        || wls_boot_id(boot_id) != 0
        || wls_guardian_head_publish_locked(
            home,
            lock,
            head,
            request,
            phase,
            boot_id,
            0ULL,
            0ULL,
            recovery_active,
            &published
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(boot_id, sizeof(boot_id));
    SecureZeroMemory(&published, sizeof(published));
    return result;
}

static int wls_guardian_runtime_prepare_child(
    const wchar_t *home,
    const wchar_t *run_directory,
    struct wls_guardian_child_process *child,
    struct wls_guardian_runtime_selection *selection
) {
    struct wls_guardian_protocol_lock lock;
    struct wls_guardian_generation_head head;
    struct wls_guardian_transition_request request;
    enum wls_guardian_selected_generation selected;
    int request_present = 0;
    int candidate_active;
    int recovery_active;
    int result = WLS_GUARDIAN_PREPARE_FAILED;
    SecureZeroMemory(&lock, sizeof(lock));
    SecureZeroMemory(&head, sizeof(head));
    SecureZeroMemory(&request, sizeof(request));
    lock.pin.handle = INVALID_HANDLE_VALUE;
    if (child == NULL || selection == NULL
        || wls_guardian_protocol_reconcile_retry(
            home, &lock, &head, &request, &request_present
        ) != 0) goto cleanup;
    if (!request_present) {
        if (strcmp(head.phase, "STABLE") != 0) goto cleanup;
        selected = WLS_GUARDIAN_SELECTED_STABLE;
    } else {
        candidate_active = wls_guardian_active_is_candidate(&head, &request);
        recovery_active = wls_guardian_active_is_recovery(&head, &request);
        if (strcmp(head.phase, "FAILED_CLOSED") == 0) goto cleanup;
        if (strcmp(head.phase, "ROLLBACK_PENDING") == 0) {
            struct wls_guardian_recovery_transaction transaction;
            int transaction_state;
            SecureZeroMemory(&transaction, sizeof(transaction));
            transaction_state = wls_guardian_recovery_transaction_read(
                home, &transaction
            );
            if (transaction_state != 0
                || wls_guardian_recovery_transaction_binding(
                    home, &transaction, &request
                ) != 0
                || transaction.sequence < 24ULL
                || (strcmp(transaction.phase, "RESTORED") != 0
                    && strcmp(transaction.phase, "OBSERVING") != 0)
                || wls_guardian_recovery_roots_verify(
                    home, &request
                ) != 0
                || wls_guardian_platform_live_verify(
                    home, run_directory, &request
                ) != 0) {
                SecureZeroMemory(&transaction, sizeof(transaction));
                result = WLS_GUARDIAN_PREPARE_RESTORE;
                goto cleanup;
            }
            SecureZeroMemory(&transaction, sizeof(transaction));
            selected = WLS_GUARDIAN_SELECTED_RECOVERY_PENDING;
        } else if (strcmp(head.phase, "PROBATIONARY_COMMITTED") == 0) {
            if (!candidate_active) goto cleanup;
            selected = WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION;
        } else if (strcmp(head.phase, "ROLLBACK_OBSERVING") == 0) {
            if (!recovery_active) goto cleanup;
            selected = WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING;
        } else if (strcmp(head.phase, "STABLE") == 0
            && head.sequence == request.expected_head_sequence
            && sodium_memcmp(
                head.raw_sha256, request.expected_head_sha256, 64U
            ) == 0) {
            selected = WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL;
        } else if (strcmp(head.phase, "STABLE") == 0
            && candidate_active) {
            selected = WLS_GUARDIAN_SELECTED_CANDIDATE_STABLE;
            if (wls_guardian_ack_publish_locked(
                    home, &lock, &request, &head, 0
                ) != 0) goto cleanup;
        } else if (strcmp(head.phase, "STABLE") == 0
            && recovery_active) {
            selected = WLS_GUARDIAN_SELECTED_RECOVERY_STABLE;
            if (wls_guardian_recovery_transaction_mark_locked(
                    home, &lock, &request, "STABLE"
                ) != 0
                || wls_guardian_ack_publish_locked(
                    home, &lock, &request, &head, 1
                ) != 0) goto cleanup;
        } else {
            goto cleanup;
        }
    }
    if (wls_guardian_runtime_selection_copy(
            selection,
            selected,
            &head,
            request_present ? &request : NULL,
            request_present
        ) != 0) goto cleanup;
    if (wls_guardian_generation_closure(
            home,
            selection->launcher_sha256,
            selection->launcher_size,
            selection->ca_sha256,
            selection->runtime_generation,
            selection->generation_id
        ) != 0) {
        if (selected == WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL
            || selected == WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION) {
            if (wls_guardian_runtime_publish_failure_locked(
                    home, &lock, &head, &request, 0, 0
                ) == 0) result = WLS_GUARDIAN_PREPARE_RESTORE;
        } else if (selected == WLS_GUARDIAN_SELECTED_RECOVERY_PENDING
            || selected == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING) {
            (void)wls_guardian_runtime_publish_failure_locked(
                home,
                &lock,
                &head,
                &request,
                1,
                selected == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING
            );
        }
        goto cleanup;
    }
    /* The generation lock remains held through suspended CreateProcess,
     * exact image re-open, job assignment and ResumeThread. */
    if (wls_guardian_child_spawn(
            home,
            run_directory,
            selection->launcher_sha256,
            child
        ) != 0) {
        if (selected == WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL
            || selected == WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION) {
            if (wls_guardian_runtime_publish_failure_locked(
                    home, &lock, &head, &request, 0, 0
                ) == 0) result = WLS_GUARDIAN_PREPARE_RESTORE;
        } else if (selected == WLS_GUARDIAN_SELECTED_RECOVERY_PENDING
            || selected == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING) {
            (void)wls_guardian_runtime_publish_failure_locked(
                home,
                &lock,
                &head,
                &request,
                1,
                selected == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING
            );
        }
        goto cleanup;
    }
    result = WLS_GUARDIAN_PREPARE_OK;
cleanup:
    wls_guardian_protocol_unlock(&lock);
    SecureZeroMemory(&head, sizeof(head));
    SecureZeroMemory(&request, sizeof(request));
    if (result != WLS_GUARDIAN_PREPARE_OK
        && result != WLS_GUARDIAN_PREPARE_RESTORE) {
        SecureZeroMemory(selection, sizeof(*selection));
    }
    return result;
}

static int wls_guardian_runtime_head_matches_selection(
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_runtime_selection *selection
) {
    if (head == NULL || selection == NULL
        || sodium_memcmp(
            head->host_id, selection->host_id, 32U
        ) != 0) return 0;
    if (selection->selected == WLS_GUARDIAN_SELECTED_STABLE) {
        return !selection->request_present
            && strcmp(head->phase, "STABLE") == 0
            && sodium_memcmp(
                head->active_generation_id,
                selection->generation_id,
                64U
            ) == 0;
    }
    if (!selection->request_present) return 0;
    if (selection->selected
            == WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL) {
        return strcmp(head->phase, "STABLE") == 0
            && head->sequence
                == selection->request.expected_head_sequence
            && sodium_memcmp(
                head->raw_sha256,
                selection->request.expected_head_sha256,
                64U
            ) == 0;
    }
    if (selection->selected
            == WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION) {
        return strcmp(head->phase, "PROBATIONARY_COMMITTED") == 0
            && wls_guardian_active_is_candidate(
                head, &selection->request
            );
    }
    if (selection->selected
            == WLS_GUARDIAN_SELECTED_CANDIDATE_STABLE) {
        return strcmp(head->phase, "STABLE") == 0
            && wls_guardian_active_is_candidate(
                head, &selection->request
            );
    }
    if (selection->selected
            == WLS_GUARDIAN_SELECTED_RECOVERY_PENDING) {
        return strcmp(head->phase, "ROLLBACK_PENDING") == 0
            && wls_guardian_active_is_candidate(
                head, &selection->request
            );
    }
    if (selection->selected
            == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING) {
        return strcmp(head->phase, "ROLLBACK_OBSERVING") == 0
            && wls_guardian_active_is_recovery(
                head, &selection->request
            );
    }
    return selection->selected == WLS_GUARDIAN_SELECTED_RECOVERY_STABLE
        && strcmp(head->phase, "STABLE") == 0
        && wls_guardian_active_is_recovery(head, &selection->request);
}

static int wls_guardian_runtime_probation_reset_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    const struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request,
    int recovery_active,
    unsigned long long now,
    unsigned long long duration,
    struct wls_guardian_generation_head *published
) {
    char boot_id[65];
    unsigned long long deadline = 0ULL;
    const char *phase = recovery_active
        ? "ROLLBACK_OBSERVING" : "PROBATIONARY_COMMITTED";
    int result = 1;
    sodium_memzero(boot_id, sizeof(boot_id));
    if (wls_guardian_runtime_add_milliseconds(
            now, duration, &deadline
        ) != 0
        || wls_boot_id(boot_id) != 0
        || wls_guardian_head_publish_locked(
            home,
            lock,
            head,
            request,
            phase,
            boot_id,
            now,
            deadline,
            recovery_active,
            published
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    sodium_memzero(boot_id, sizeof(boot_id));
    return result;
}

static int wls_guardian_runtime_sample_accept(
    struct wls_guardian_runtime_observation *observation,
    const struct wls_guardian_continuity_sample *sample
) {
    struct wls_guardian_runtime_observation next;
    int result = 1;
    SecureZeroMemory(&next, sizeof(next));
    if (observation == NULL || sample == NULL
        || (sample->state
                != WLS_GUARDIAN_CONTINUITY_PROMOTION_HEALTHY
            && sample->state != WLS_GUARDIAN_CONTINUITY_ONLY)
        || sample->issued_monotonic_ms == 0ULL
        || !wls_is_hex(sample->raw_sha256, 64U)
        || !wls_is_hex(sample->observation_identity_sha256, 64U)
        || (sample->state == WLS_GUARDIAN_CONTINUITY_PROMOTION_HEALTHY
            && (sample->promotion_healthy_since_monotonic_ms == 0ULL
                || sample->promotion_healthy_since_monotonic_ms
                    > sample->issued_monotonic_ms))
        || (sample->state == WLS_GUARDIAN_CONTINUITY_ONLY
            && sample->promotion_healthy_since_monotonic_ms != 0ULL)) {
        return 1;
    }
    next = *observation;
    if (next.last_issued_monotonic_ms != 0ULL) {
        if (sample->issued_monotonic_ms
                < next.last_issued_monotonic_ms
            || (sample->issued_monotonic_ms
                    == next.last_issued_monotonic_ms
                && sodium_memcmp(
                    sample->raw_sha256,
                    next.last_receipt_sha256,
                    64U
                ) != 0)) goto cleanup;
    }
    next.last_issued_monotonic_ms = sample->issued_monotonic_ms;
    memcpy(
        next.last_receipt_sha256, sample->raw_sha256, 65U
    );
    if (sample->state == WLS_GUARDIAN_CONTINUITY_ONLY) {
        if (sample->issued_monotonic_ms
            > next.required_promotion_since_floor) {
            next.required_promotion_since_floor
                = sample->issued_monotonic_ms;
        }
        next.initialized = 0;
        next.reset_required = 1;
        sodium_memzero(next.identity_sha256, 65U);
    } else if (sample->promotion_healthy_since_monotonic_ms
            < next.required_promotion_since_floor) {
        goto cleanup;
    }
    *observation = next;
    result = 0;
cleanup:
    SecureZeroMemory(&next, sizeof(next));
    return result;
}

static int wls_guardian_runtime_self_test(void)
{
    struct wls_guardian_runtime_observation observation;
    struct wls_guardian_runtime_observation before;
    struct wls_guardian_continuity_sample sample;
    unsigned long long added = 0ULL;
    int result = 1;
    SecureZeroMemory(&observation, sizeof(observation));
    SecureZeroMemory(&before, sizeof(before));
    SecureZeroMemory(&sample, sizeof(sample));
    memset(sample.raw_sha256, 'a', 64U);
    sample.raw_sha256[64] = '\0';
    memset(sample.observation_identity_sha256, '1', 64U);
    sample.observation_identity_sha256[64] = '\0';
    sample.state = WLS_GUARDIAN_CONTINUITY_ONLY;
    sample.issued_monotonic_ms = 100ULL;
    if (wls_guardian_runtime_add_milliseconds(10ULL, 20ULL, &added) != 0
        || added != 30ULL
        || wls_guardian_runtime_add_milliseconds(
            ULLONG_MAX, 1ULL, &added
        ) == 0
        || wls_guardian_runtime_sample_accept(
            &observation, &sample
        ) != 0
        || observation.required_promotion_since_floor != 100ULL
        || !observation.reset_required) goto cleanup;
    before = observation;
    sample.state = WLS_GUARDIAN_CONTINUITY_PROMOTION_HEALTHY;
    sample.promotion_healthy_since_monotonic_ms = 99ULL;
    sample.issued_monotonic_ms = 101ULL;
    memset(sample.raw_sha256, 'b', 64U);
    if (wls_guardian_runtime_sample_accept(
            &observation, &sample
        ) == 0
        || sodium_memcmp(
            &observation, &before, sizeof(observation)
        ) != 0) goto cleanup;
    sample.promotion_healthy_since_monotonic_ms = 100ULL;
    if (wls_guardian_runtime_sample_accept(
            &observation, &sample
        ) != 0) goto cleanup;
    before = observation;
    memset(sample.raw_sha256, 'c', 64U);
    if (wls_guardian_runtime_sample_accept(
            &observation, &sample
        ) == 0
        || sodium_memcmp(
            &observation, &before, sizeof(observation)
        ) != 0) goto cleanup;
    result = 0;
cleanup:
    SecureZeroMemory(&observation, sizeof(observation));
    SecureZeroMemory(&before, sizeof(before));
    SecureZeroMemory(&sample, sizeof(sample));
    return result;
}

/* The caller owns the protocol lock for the complete receipt/live/head
 * linearization.  CONTINUITY_ONLY is healthy enough to avoid rollback but it
 * clears all accumulated promotion time. */
static int wls_guardian_runtime_health_transition_locked(
    const wchar_t *home,
    const struct wls_guardian_protocol_lock *lock,
    struct wls_guardian_generation_head *head,
    const struct wls_guardian_transition_request *request,
    struct wls_guardian_runtime_selection *selection,
    struct wls_guardian_runtime_observation *observation,
    const struct wls_guardian_continuity_sample *sample
) {
    struct wls_guardian_generation_head published;
    char boot_id[65];
    unsigned long long now = 0ULL;
    int recovery_active = 0;
    int result = 1;
    SecureZeroMemory(&published, sizeof(published));
    sodium_memzero(boot_id, sizeof(boot_id));
    if (home == NULL || lock == NULL || head == NULL || request == NULL
        || selection == NULL || observation == NULL || sample == NULL
        || !wls_guardian_protocol_lock_valid(home, lock)
        || wls_guardian_monotonic(&now) != 0
        || wls_boot_id(boot_id) != 0
        || wls_guardian_runtime_sample_accept(
            observation, sample
        ) != 0) goto cleanup;
    if (sample->state == WLS_GUARDIAN_CONTINUITY_ONLY) {
        result = 0;
        goto cleanup;
    }
    if (selection->selected
            == WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL) {
        if (strcmp(head->phase, "STABLE") != 0
            || head->sequence != request->expected_head_sequence
            || sodium_memcmp(
                head->raw_sha256, request->expected_head_sha256, 64U
            ) != 0
            || wls_guardian_runtime_probation_reset_locked(
                home,
                lock,
                head,
                request,
                0,
                now,
                WLS_GUARDIAN_PROBATION_MILLISECONDS,
                &published
            ) != 0) goto cleanup;
        selection->selected = WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION;
        observation->initialized = 1;
        observation->reset_required = 0;
        memcpy(
            observation->identity_sha256,
            sample->observation_identity_sha256,
            65U
        );
        result = 0;
        goto cleanup;
    }
    if (selection->selected
            == WLS_GUARDIAN_SELECTED_RECOVERY_PENDING) {
        if (strcmp(head->phase, "ROLLBACK_PENDING") != 0
            || wls_guardian_recovery_transaction_mark_locked(
                home, lock, request, "OBSERVING"
            ) != 0
            || wls_guardian_runtime_probation_reset_locked(
                home,
                lock,
                head,
                request,
                1,
                now,
                WLS_GUARDIAN_ROLLBACK_OBSERVATION_MILLISECONDS,
                &published
            ) != 0) goto cleanup;
        selection->selected = WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING;
        observation->initialized = 1;
        observation->reset_required = 0;
        memcpy(
            observation->identity_sha256,
            sample->observation_identity_sha256,
            65U
        );
        result = 0;
        goto cleanup;
    }
    if (selection->selected
            != WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION
        && selection->selected
            != WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING) {
        result = 0;
        goto cleanup;
    }
    recovery_active = selection->selected
        == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING;
    if ((!recovery_active
            && strcmp(head->phase, "PROBATIONARY_COMMITTED") != 0)
        || (recovery_active
            && strcmp(head->phase, "ROLLBACK_OBSERVING") != 0)) goto cleanup;
    /* Guardian restart, publisher/process replacement, boot change, or any
     * prior continuity-only sample starts a complete new window.  A signed
     * Broker promotion-since newer than the persisted head catches a short
     * non-HEALTHY interval that occurred between Guardian samples. */
    if (!observation->initialized
        || observation->reset_required
        || sodium_memcmp(
            head->host_boot_id, boot_id, 64U
        ) != 0
        || sodium_memcmp(
            observation->identity_sha256,
            sample->observation_identity_sha256,
            64U
        ) != 0
        || sample->promotion_healthy_since_monotonic_ms
            > head->probation_started_monotonic_ms) {
        if (wls_guardian_runtime_probation_reset_locked(
                home,
                lock,
                head,
                request,
                recovery_active,
                now,
                recovery_active
                    ? WLS_GUARDIAN_ROLLBACK_OBSERVATION_MILLISECONDS
                    : WLS_GUARDIAN_PROBATION_MILLISECONDS,
                &published
            ) != 0) goto cleanup;
        observation->initialized = 1;
        observation->reset_required = 0;
        memcpy(
            observation->identity_sha256,
            sample->observation_identity_sha256,
            65U
        );
        result = 0;
        goto cleanup;
    }
    if (now < head->probation_deadline_monotonic_ms) {
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_head_publish_locked(
            home,
            lock,
            head,
            request,
            "STABLE",
            boot_id,
            0ULL,
            0ULL,
            recovery_active,
            &published
        ) != 0
        || (recovery_active
            && wls_guardian_recovery_transaction_mark_locked(
                home, lock, request, "STABLE"
            ) != 0)
        || wls_guardian_ack_publish_locked(
            home, lock, request, &published, recovery_active
        ) != 0) goto cleanup;
    selection->selected = recovery_active
        ? WLS_GUARDIAN_SELECTED_RECOVERY_STABLE
        : WLS_GUARDIAN_SELECTED_CANDIDATE_STABLE;
    result = 0;
cleanup:
    SecureZeroMemory(&published, sizeof(published));
    sodium_memzero(boot_id, sizeof(boot_id));
    return result;
}

/* 0=authoritative live continuity, 1=invalid/transiently unavailable,
 * 2=authenticated generation state changed and the stable child must be
 * reselected.  The protocol lock linearizes Broker publication, live birth
 * checks and the optional head commit. */
static int wls_guardian_runtime_probe_tick(
    const wchar_t *home,
    const wchar_t *run_directory,
    struct wls_guardian_child_process *child,
    struct wls_guardian_runtime_selection *selection,
    struct wls_guardian_runtime_observation *observation
) {
    struct wls_guardian_protocol_lock lock;
    struct wls_guardian_generation_head head;
    struct wls_guardian_transition_request request;
    struct wls_guardian_continuity_sample sample;
    int request_present = 0;
    int transitional;
    int result = 1;
    SecureZeroMemory(&lock, sizeof(lock));
    SecureZeroMemory(&head, sizeof(head));
    SecureZeroMemory(&request, sizeof(request));
    SecureZeroMemory(&sample, sizeof(sample));
    lock.pin.handle = INVALID_HANDLE_VALUE;
    if (home == NULL || run_directory == NULL || child == NULL
        || selection == NULL || observation == NULL
        || wls_guardian_protocol_reconcile_retry(
            home, &lock, &head, &request, &request_present
        ) != 0) goto cleanup;
    if (request_present != selection->request_present) {
        result = 2;
        goto cleanup;
    }
    if ((request_present
            && sodium_memcmp(
                request.raw_sha256,
                selection->request.raw_sha256,
                64U
            ) != 0)
        || !wls_guardian_runtime_head_matches_selection(
            &head, selection
        )) {
        result = 2;
        goto cleanup;
    }
    if (wls_guardian_probation_health(
            home,
            run_directory,
            selection->host_id,
            selection->runtime_generation,
            child->job,
            &sample
        ) != 0) goto cleanup;
    transitional = selection->selected
            == WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL
        || selection->selected
            == WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION
        || selection->selected
            == WLS_GUARDIAN_SELECTED_RECOVERY_PENDING
        || selection->selected
            == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING;
    if (transitional) {
        if (!request_present
            || wls_guardian_runtime_health_transition_locked(
                home,
                &lock,
                &head,
                &request,
                selection,
                observation,
                &sample
            ) != 0) goto cleanup;
    } else if (wls_guardian_runtime_sample_accept(
            observation, &sample
        ) != 0) {
        goto cleanup;
    }
    result = 0;
cleanup:
    wls_guardian_protocol_unlock(&lock);
    SecureZeroMemory(&head, sizeof(head));
    SecureZeroMemory(&request, sizeof(request));
    SecureZeroMemory(&sample, sizeof(sample));
    return result;
}

static int wls_guardian_runtime_failure_transition(
    const wchar_t *home,
    const struct wls_guardian_runtime_selection *selection
) {
    struct wls_guardian_protocol_lock lock;
    struct wls_guardian_generation_head head;
    struct wls_guardian_transition_request request;
    int request_present = 0;
    int failed_closed;
    int recovery_active;
    int result = 1;
    SecureZeroMemory(&lock, sizeof(lock));
    SecureZeroMemory(&head, sizeof(head));
    SecureZeroMemory(&request, sizeof(request));
    lock.pin.handle = INVALID_HANDLE_VALUE;
    if (selection == NULL || !selection->request_present
        || wls_guardian_protocol_reconcile_retry(
            home, &lock, &head, &request, &request_present
        ) != 0
        || !request_present
        || sodium_memcmp(
            request.raw_sha256, selection->request.raw_sha256, 64U
        ) != 0
        || !wls_guardian_runtime_head_matches_selection(
            &head, selection
        )) goto cleanup;
    failed_closed = selection->selected
            == WLS_GUARDIAN_SELECTED_RECOVERY_PENDING
        || selection->selected
            == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING;
    recovery_active = selection->selected
        == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING;
    if (failed_closed) {
        if ((recovery_active
                && strcmp(head.phase, "ROLLBACK_OBSERVING") != 0)
            || (!recovery_active
                && strcmp(head.phase, "ROLLBACK_PENDING") != 0)) {
            goto cleanup;
        }
    } else if (selection->selected
            == WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL) {
        if (strcmp(head.phase, "STABLE") != 0) goto cleanup;
    } else if (selection->selected
            == WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION) {
        if (strcmp(head.phase, "PROBATIONARY_COMMITTED") != 0) goto cleanup;
    } else {
        goto cleanup;
    }
    if (wls_guardian_runtime_publish_failure_locked(
            home,
            &lock,
            &head,
            &request,
            failed_closed,
            recovery_active
        ) != 0) goto cleanup;
    result = failed_closed ? 1 : 2;
cleanup:
    wls_guardian_protocol_unlock(&lock);
    SecureZeroMemory(&head, sizeof(head));
    SecureZeroMemory(&request, sizeof(request));
    return result;
}

static int wls_guardian_runtime_is_transitional(
    const struct wls_guardian_runtime_selection *selection
) {
    return selection != NULL
        && (selection->selected
                == WLS_GUARDIAN_SELECTED_CANDIDATE_INITIAL
            || selection->selected
                == WLS_GUARDIAN_SELECTED_CANDIDATE_PROBATION
            || selection->selected
                == WLS_GUARDIAN_SELECTED_RECOVERY_PENDING
            || selection->selected
                == WLS_GUARDIAN_SELECTED_RECOVERY_OBSERVING);
}

static int wls_guardian_runtime_monitor_child(
    const wchar_t *home,
    const wchar_t *run_directory,
    struct wls_guardian_child_process *child,
    struct wls_guardian_runtime_selection *selection
) {
    struct wls_guardian_runtime_observation observation;
    unsigned int core_failures = 0U;
    unsigned long long now = 0ULL;
    DWORD wait_result;
    DWORD child_exit = 1U;
    int health_result;
    int transition_result;
    int data_plane_heartbeat;
    int result = WLS_GUARDIAN_MONITOR_FAILED;
    SecureZeroMemory(&observation, sizeof(observation));
    if (home == NULL || run_directory == NULL || child == NULL
        || selection == NULL || child->process.hProcess == NULL) return result;
    for (;;) {
        if (InterlockedCompareExchange(
                &wls_service_stop_requested, 0, 0
            ) != 0) {
            result = WLS_GUARDIAN_MONITOR_STOPPED;
            goto cleanup;
        }
        wait_result = WaitForSingleObject(
            child->process.hProcess, WLS_GUARDIAN_MONITOR_MILLISECONDS
        );
        if (wait_result == WAIT_OBJECT_0) {
            if (!GetExitCodeProcess(child->process.hProcess, &child_exit)) {
                child_exit = 1U;
            }
            if (wls_guardian_runtime_is_transitional(selection)) {
                transition_result = wls_guardian_runtime_failure_transition(
                    home, selection
                );
                result = transition_result == 2
                    ? WLS_GUARDIAN_MONITOR_RESTORE
                    : WLS_GUARDIAN_MONITOR_FAILED;
            } else if (child_exit == WLS_CONTROL_TREE_RELOAD
                || child_exit == WLS_SERVICE_TREE_RESTART) {
                if (child_exit == WLS_CONTROL_TREE_RELOAD) {
                    InterlockedExchange(
                        &wls_service_reload_consumed,
                        InterlockedCompareExchange(
                            &wls_service_reload_generation, 0, 0
                        )
                    );
                }
                result = WLS_GUARDIAN_MONITOR_RESTART_CHILD;
            }
            goto cleanup;
        }
        if (wait_result != WAIT_TIMEOUT
            || wls_guardian_monotonic(&now) != 0) goto cleanup;
        data_plane_heartbeat = WaitForSingleObject(
            child->health_event, 0U
        ) == WAIT_OBJECT_0;
        if (data_plane_heartbeat) {
            child->last_health_monotonic_ms = now;
        }
        health_result = WaitForSingleObject(child->ready_event, 0U)
                == WAIT_OBJECT_0
            ? wls_guardian_runtime_probe_tick(
                home,
                run_directory,
                child,
                selection,
                &observation
            )
            : 1;
        if (health_result == 2) {
            if (!wls_guardian_runtime_is_transitional(selection)) {
                result = WLS_GUARDIAN_MONITOR_RESTART_CHILD;
            }
            goto cleanup;
        }
        if (health_result == 0) {
            core_failures = 0U;
            child->last_health_monotonic_ms = now;
            if (!child->ready) {
                child->ready = 1;
                if (InterlockedCompareExchange(
                        &wls_service_ready_reported, 1, 0
                    ) == 0) {
                    wls_report_service(SERVICE_RUNNING, NO_ERROR, 0U);
                }
            }
            continue;
        }
        ++core_failures;
        /* The signed Launcher reports this heartbeat only after re-opening
         * Nginx from the selected slot and verifying both nested Jobs.  For a
         * stable generation it proves DATA_PLANE_UP while the Controller or
         * Broker continuity publisher is recovering; preserve traffic and do
         * not restart the process tree.  Transitional generations still need
         * the authenticated continuity receipt before probation can advance. */
        if (!wls_guardian_runtime_is_transitional(selection)
            && child->last_health_monotonic_ms != 0ULL
            && now >= child->last_health_monotonic_ms
            && now - child->last_health_monotonic_ms
                <= WLS_GUARDIAN_DATA_PLANE_HEARTBEAT_MILLISECONDS) {
            core_failures = 0U;
            continue;
        }
        if (!child->ready
            && now >= child->started_monotonic_ms
            && now - child->started_monotonic_ms
                < WLS_GUARDIAN_STARTUP_MILLISECONDS) {
            wls_report_service_pending(SERVICE_START_PENDING, 30000U);
            continue;
        }
        if (core_failures < WLS_GUARDIAN_CORE_FAILURE_LIMIT) continue;
        if (wls_guardian_runtime_is_transitional(selection)) {
            transition_result = wls_guardian_runtime_failure_transition(
                home, selection
            );
            result = transition_result == 2
                ? WLS_GUARDIAN_MONITOR_RESTORE
                : WLS_GUARDIAN_MONITOR_FAILED;
        }
        goto cleanup;
    }
cleanup:
    SecureZeroMemory(&observation, sizeof(observation));
    return result;
}

static int wls_guardian_runtime_stop_child(
    struct wls_guardian_child_process *child
) {
    unsigned long long started = 0ULL;
    unsigned long long now = 0ULL;
    if (child == NULL || child->process.hProcess == NULL
        || child->stop_event == NULL
        || wls_guardian_monotonic(&started) != 0) return 1;
    (void)SetEvent(child->stop_event);
    for (;;) {
        DWORD wait_result = WaitForSingleObject(
            child->process.hProcess, 1000U
        );
        if (wait_result == WAIT_OBJECT_0) return 0;
        if (wait_result != WAIT_TIMEOUT
            || wls_guardian_monotonic(&now) != 0
            || now < started) return 1;
        if (now - started >= WLS_GUARDIAN_DRAIN_MILLISECONDS) break;
        if (((now - started) % 10000ULL) < 1000ULL) {
            wls_report_service_pending(SERVICE_STOP_PENDING, 30000U);
        }
    }
    return TerminateJobObject(child->job, WLS_SERVICE_TREE_RESTART)
        && WaitForSingleObject(child->process.hProcess, 5000U)
            == WAIT_OBJECT_0 ? 0 : 1;
}

static int wls_guardian_service_run(
    const wchar_t *requested_home,
    const wchar_t *requested_run
) {
    wchar_t home[WLS_PATH_CHARS];
    wchar_t run_directory[WLS_PATH_CHARS];
    wchar_t guardian[WLS_PATH_CHARS];
    char guardian_digest[65];
    struct wls_guardian_child_process child;
    struct wls_guardian_runtime_selection selection;
    struct wls_guardian_transition_request restore_request;
    HANDLE singleton = NULL;
    int prepared;
    int monitored;
    int result = (int)WLS_SERVICE_TREE_RESTART;
    ZeroMemory(home, sizeof(home));
    ZeroMemory(run_directory, sizeof(run_directory));
    ZeroMemory(guardian, sizeof(guardian));
    sodium_memzero(guardian_digest, sizeof(guardian_digest));
    SecureZeroMemory(&child, sizeof(child));
    SecureZeroMemory(&selection, sizeof(selection));
    SecureZeroMemory(&restore_request, sizeof(restore_request));
    if (wls_guardian_fixed_runtime_paths(
            requested_home,
            requested_run,
            home,
            run_directory
        ) != 0
        || wls_guardian_local_system_process() != 0) goto cleanup;
    if (wls_guardian_service_stop_intent_active(run_directory)) {
        result = 0;
        goto cleanup;
    }
    if (wls_guardian_immutable_identity(
            home, guardian, guardian_digest
        ) != 0
        || wls_guardian_scm_definition_valid(
            home, run_directory, guardian
        ) != 0
        || (singleton = wls_guardian_singleton()) == NULL) goto cleanup;
    for (;;) {
        SecureZeroMemory(&child, sizeof(child));
        SecureZeroMemory(&selection, sizeof(selection));
        prepared = wls_guardian_runtime_prepare_child(
            home, run_directory, &child, &selection
        );
        if (prepared == WLS_GUARDIAN_PREPARE_RESTORE) {
            struct wls_guardian_protocol_lock lock;
            struct wls_guardian_generation_head head;
            int request_present = 0;
            SecureZeroMemory(&lock, sizeof(lock));
            SecureZeroMemory(&head, sizeof(head));
            SecureZeroMemory(&restore_request, sizeof(restore_request));
            lock.pin.handle = INVALID_HANDLE_VALUE;
            if (wls_guardian_protocol_reconcile_retry(
                    home,
                    &lock,
                    &head,
                    &restore_request,
                    &request_present
                ) != 0
                || !request_present
                || strcmp(head.phase, "ROLLBACK_PENDING") != 0) {
                wls_guardian_protocol_unlock(&lock);
                SecureZeroMemory(&head, sizeof(head));
                goto cleanup;
            }
            wls_guardian_protocol_unlock(&lock);
            SecureZeroMemory(&head, sizeof(head));
            wls_report_service_pending(SERVICE_START_PENDING, 30000U);
            if (wls_guardian_recovery_restore(
                    home, run_directory, &restore_request
                ) != 0) goto cleanup;
            SecureZeroMemory(&restore_request, sizeof(restore_request));
            continue;
        }
        if (prepared != WLS_GUARDIAN_PREPARE_OK) goto cleanup;
        wls_guardian_service_child_events_publish(&child);
        monitored = wls_guardian_runtime_monitor_child(
            home, run_directory, &child, &selection
        );
        wls_guardian_service_child_events_clear(&child);
        if (monitored == WLS_GUARDIAN_MONITOR_STOPPED) {
            result = wls_guardian_runtime_stop_child(&child) == 0
                ? 0 : (int)WLS_SERVICE_TREE_RESTART;
            wls_guardian_child_process_close(&child);
            goto cleanup;
        }
        if (monitored == WLS_GUARDIAN_MONITOR_RESTORE) {
            restore_request = selection.request;
            (void)wls_guardian_child_stop(&child, 30000U);
            wls_guardian_child_process_close(&child);
            wls_report_service_pending(SERVICE_START_PENDING, 30000U);
            if (wls_guardian_recovery_restore(
                    home, run_directory, &restore_request
                ) != 0) goto cleanup;
            SecureZeroMemory(&restore_request, sizeof(restore_request));
            continue;
        }
        if (monitored == WLS_GUARDIAN_MONITOR_RESTART_CHILD) {
            (void)wls_guardian_child_stop(&child, 30000U);
            wls_guardian_child_process_close(&child);
            continue;
        }
        wls_guardian_child_process_close(&child);
        goto cleanup;
    }
cleanup:
    wls_guardian_service_child_events_clear(NULL);
    if (child.process.hProcess != NULL) {
        (void)wls_guardian_child_stop(&child, 5000U);
        wls_guardian_child_process_close(&child);
    }
    if (singleton != NULL) {
        (void)ReleaseMutex(singleton);
        CloseHandle(singleton);
    }
    SecureZeroMemory(home, sizeof(home));
    SecureZeroMemory(run_directory, sizeof(run_directory));
    SecureZeroMemory(guardian, sizeof(guardian));
    sodium_memzero(guardian_digest, sizeof(guardian_digest));
    SecureZeroMemory(&selection, sizeof(selection));
    SecureZeroMemory(&restore_request, sizeof(restore_request));
    return result;
}

#endif /* WLS_GATEWAY_GUARDIAN_RUNTIME_INC */
