# WLS Dispatcher Idle Select Wakeup Fix - 2026-07-05

## Problem

`Dispatcher::selectAndProcess()` used a 5ms `socket_select()` timeout even when the dispatcher had no active client connection, pending buffer, maintenance queue, or deferred worker-pool job.

`socket_select()` is still required while idle because it blocks on the listening socket and wakes immediately when a new client connects. The inefficient part was not entering `select`; it was waking every 5ms during pure idle periods.

## Fix

- Active forwarding paths keep the short 250us timeout:
  - active client connections
  - pending client or worker buffers
  - pending maintenance-page queue
  - deferred worker-pool jobs
  - IPC pending writes
- Pure idle dispatcher loops now use a 50ms timeout.

This reduces idle wakeups while preserving immediate wakeup for new client connections. IPC control messages may wait up to the idle timeout because the dispatcher IPC client uses a separate stream socket outside the business `socket_select()` set.

## Follow-Up

A fuller event-loop refactor could merge the dispatcher listening socket and IPC stream into one wait set. That would allow longer idle waits without adding IPC latency, but it is a larger change than this focused CPU reduction.
