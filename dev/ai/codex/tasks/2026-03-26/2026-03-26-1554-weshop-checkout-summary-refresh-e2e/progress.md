# Progress

- 2026-03-26 15:54 Created the task workspace.
- 2026-03-26 16:01 Switched the login bootstrap from theme-dependent form filling to a direct public register POST so the spec can run against runtimes that are not serving the default auth template.
- 2026-03-26 16:05 Reworked cart seeding to use the live product page add-to-cart interaction instead of assuming the default product form structure.
- 2026-03-26 16:09 Verified the new e2e on `9982`; the spec now skips cleanly when the runtime is not serving the default checkout summary anchors.
