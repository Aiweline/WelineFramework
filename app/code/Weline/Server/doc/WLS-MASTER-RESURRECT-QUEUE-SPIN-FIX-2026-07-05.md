# WLS Master Resurrect Queue Spin Fix - 2026-07-05

## Problem

`ServiceOrchestrator::runLoop()` scheduled `periodic:resurrect_queue` after startup acceptance even when the resurrect queue was empty or only contained future work.

Because main-loop tasks start with `SchedulerSystem::yield()`, each unnecessary schedule registered an immediate timer and forced the master poll timeout down to the millisecond range. On idle WLS masters this caused repeated Fiber start/resume/destroy churn and avoidable PHP CPU usage.

## Fix

- The master now guards the resurrect queue only when the queue is non-empty.
- `periodic:resurrect_queue` is scheduled only when the queue has due work.
- Future `scheduledAt` entries no longer trigger a Fiber task; they wait for the normal main-loop poll cadence.
- `launching=true` entries are skipped for due-work scheduling, while `guardResurrectQueueTasks()` can still cancel and requeue stalled launch tasks.
- Maintenance queue entries are still cleaned when maintenance mode is off.

## Verification

Focused PHPUnit coverage was added in `ServiceOrchestratorStartupTest` for empty queues, future work, due work, maintenance cleanup, and stalled launch guard behavior.
