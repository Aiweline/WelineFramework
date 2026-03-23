# Plan - wls-static-cache-hit-and-reload-latency

## Outcome

- Repeated static asset requests can be served from the WLS in-memory static cache and expose `X-WLS-Static-Cache: HIT`.
- `php bin/w server:reload` no longer stalls for a long period before it even starts dispatching the rolling reload flow.

## Steps

- [x] Clarify scope, affected files, and risks
- [ ] Checkpoint the currently pending WLS translation/logging changes before new edits
- [ ] Trace reload pre-dispatch latency and patch the blocking path
- [ ] Trace static-cache MISS/HIT flow and patch cache population/lookup
- [ ] Add or update targeted verification
- [ ] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [ ] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] CLI runtime probe for reload responsiveness
