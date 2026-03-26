# WelineFramework Skill Routing

Source of truth:

- `E:\WelineFramework\DEV-workspace\dev\ai\skills\skill-trigger-reminders\SKILL.md`
- `E:\WelineFramework\DEV-workspace\dev\ai\skills\skill-trigger-reminders\references\development-skill-map.md`

## Fast mapping

- Planning / audit / review: `planning`
- Testing / request verification / e2e: `testing`
- Events / hooks / extends: `extension-points`
- WLS / process / static state / StateManager: `runtime-and-process`
- Session / auth / login: `session-development`
- CSS / JS / phtml / themes: `theme-development` or `frontend-components`
- Notifications / confirmation UI: `friendly-notifications`
- Query / `w_query()`: `unified-query-provider`
- Routing / URL parsing: `weline-routing`
- SSE / streaming: `sse-streaming`
- Skills / rules / AI repo maintenance: `cursor-as-reference`

## Rule

If one general skill and one specific skill both match, start with the specific one.
