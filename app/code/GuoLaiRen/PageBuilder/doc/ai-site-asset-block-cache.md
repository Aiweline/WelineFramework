# AI Site Asset Block Cache

PageBuilder AI image assets are bound to a per-slot `planning_signature`. The
signature is built from stable planning fields such as slot id, slot type, page,
block, section, prompt brief, size, usage policy, and requirement flags. Runtime
fields such as `final_url`, status, variants, timestamps, and execution tokens
are intentionally excluded.

Generation results are remembered in `scope.asset_block_cache.slots[slot_id]`
with the matching `planning_signature`. During manifest sync, a cached image is
hydrated only when the current slot id and signature match. If the first-stage
plan and block-level image brief are unchanged, the existing generated image is
reused. If the planning signature changes, non-locked generated assets are reset
to pending and must be generated again.

Manual user locks still win over the planning signature. Manual regeneration
uses queue mode `regenerate` and bypasses the reuse shortcut, while queued jobs
carry the expected `planning_signature` so stale jobs cannot generate images for
a changed plan contract.
