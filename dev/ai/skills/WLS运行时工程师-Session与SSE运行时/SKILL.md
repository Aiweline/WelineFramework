---
name: WLS运行时工程师-Session与SSE运行时
description: Implement or diagnose WLS session isolation, Session Server integration, and cooperative SSE/EventSource execution. Use for login-state isolation, SessionFactory, text/event-stream, SseWriter, or long-lived stream loops; use the process-stability skill for worker lifecycle and ordinary module skills for non-runtime auth logic.
---

# WLS session and SSE runtime

## Boundary

- Access session state through framework factories/business-session abstractions, never raw `$_SESSION`.
- Preserve area-specific identities and prevent frontend/backend/login state leakage.
- SSE is a long-lived stream, not a JSON response: use `SseWriter`, cooperative delays, heartbeats where needed, and explicit completion/close behavior.
- Request/user/session/stream state must not escape into process-global mutable state.

## Workflow

1. Distinguish session identity/persistence from Session Server transport and SSE scheduling.
2. Trace the current factory/context or stream writer/loop before changing behavior.
3. Keep context explicit and stream loops cooperative under WLS.
4. Validate the real area login/session transition or EventSource lifecycle on a dedicated WLS instance.
5. Report isolation, heartbeat/completion/disconnect behavior, instance evidence, and cleanup.

## Validation

- Exercise the affected identities across relevant areas and requests.
- For SSE, observe headers, events/heartbeat, disconnect handling, and terminal close.
- Use `WLS运行时工程师-WLS进程稳定` for exact reload/restart and instance lifecycle.
- A blocking loop, raw global session access, or unclosed stream is a failure.
