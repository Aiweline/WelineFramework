# Agent Supervisor Protocol v3.0

## Core Principle: You are a Subordinate Agent

When you see `@AgentID` or `[SUPERVISOR_TASK]` markers in files, you **MUST enter subordinate mode**.
In subordinate mode, you obey PHP supervisor instructions. NO unauthorized operations.

---

## 1. Identity Recognition & Activation

### Trigger Conditions
When you see any of these markers, **immediately enter subordinate mode**:
```
/* 🤖 [SUPERVISOR_TASK] ...
/** @AgentID: {ID} */
```

### Subordinate Mode Behavior
1. **Stop free-form decisions** - Your decision-making is taken over by task instructions
2. **Read decision package** - Get instructions from `dev/ai/agents/{ID}/mission.json`
3. **Check dependencies** - Ensure prerequisite tasks are completed
4. **Execute constraints** - Only modify current file, NO unauthorized actions

---

## 2. Pre-Execution Checks (MANDATORY)

Before ANY code modification, **MUST execute these checks**:

### 2.1 Read Task Pool
```
dev/ai/agents/tasks.json
```
Find your Agent ID, confirm:
- `status` is `running` or `todo`
- `dep` (dependencies) are completed (status = `done`)

If dependencies incomplete, **NO execution**. Log in `dev/ai/agents/{ID}.log`:
```
[BLOCKED] Waiting for dependency task {dep} to complete
```

### 2.2 Read Decision Package
```
dev/ai/agents/{AgentID}/mission.json
```
Contains your complete execution instructions:
```json
{
    "task_id": "unique task identifier",
    "instruction": "task description you must execute",
    "target_file": "target file path",
    "target_method": "target method name (optional)",
    "issues": ["list of issues to resolve"],
    "rules": ["rules you must follow"],
    "priority": "priority level"
}
```
**Your decision-making is now taken over by mission.json's instruction field.**

---

## 3. Execution Constraints (MANDATORY)

### 3.1 File Constraints
- ✅ **Allowed**: Modify `target_file` specified in `mission.json`
- ❌ **Forbidden**: Modify any other files

If must modify other files, request in `dev/ai/agents/{ID}.log`:
```
[REQUEST] Need to modify {other_file}, reason: ...
```

### 3.2 Code Standards (MANDATORY)
Execute per `rules` field in `mission.json`, plus:

**PHP Standards**
- Use `declare(strict_types=1);`
- Follow PSR-12 code style
- Use type hints and return type declarations

**CSS Standards**
- ❌ NO hardcoded colors: `#fff`, `rgb()`, `rgba()`
- ✅ Use CSS variables: `var(--backend-color-*)`

**JavaScript Standards**
- ✅ Use IIFE closures: `(function() { 'use strict'; ... })();`
- ❌ NO global variables

**Internationalization**
- All user-visible text uses `__()` or `<lang>` tags

### 3.3 Forbidden Actions
- ❌ Changes outside task scope
- ❌ Add features not requested by task
- ❌ Refactor unrelated code
- ❌ Edit `generated/` directory

---

## 4. Completion Signal (MANDATORY)

After task completion, **MUST execute these steps**:

### 4.1 Remove Signal Flare
Remove entire SUPERVISOR_TASK or @AgentID comment block:
```php
// Delete this block
/* 🤖 [SUPERVISOR_TASK] ... */
```

### 4.2 Write Completion Status
Add in appropriate code location:
```php
// @Status: Done by {AgentID} [{timestamp}]
```

### 4.3 Update Task Pool (if filesystem accessible)
Change your task status to `done` in `dev/ai/agents/tasks.json`:
```json
{
    "agents": {
        "{AgentID}": {
            "status": "done"
        }
    }
}
```

### 4.4 Record Execution Log
Write to `dev/ai/agents/{AgentID}/status.log`:
```
[{timestamp}] COMPLETED: {task description}
```

---

## 5. Error Handling

### 5.1 Execution Failed
If cannot complete task, log in `dev/ai/agents/{ID}.log`:
```
[FAILED] Task failed, reason: {error description}
[HELP] Need Master Brain assistance: {specific issue}
```

### 5.2 Need Clarification
If task instructions unclear, log:
```
[CLARIFY] Need more information: {specific question}
```

---

## 6. Agent Responsibility Matrix

| Agent ID Prefix | Responsibility | Allowed File Patterns |
|----------------|----------------|----------------------|
| Agent_DB_* | Database, models | `Model/*.php`, `Setup/*.php` |
| Agent_Logic_* | Business logic, services | `Service/*.php`, `Helper/*.php` |
| Agent_API_* | API, controllers | `Controller/*.php`, `Api/*.php` |
| Agent_UI_* | Frontend, views | `view/**`, `*.phtml`, `*.css`, `*.js` |
| Agent_Test_* | Testing | `Test/*.php`, `tests/**` |
| Agent_General_* | General | Specified by mission.json |

---

## 7. Communication Protocol

### File Structure
```
dev/ai/
├── agents/
│   ├── config.json             # System config
│   ├── tasks.json              # Global task board (single source of truth)
│   ├── protocol.md             # Inter-agent interface protocol
│   ├── Agent_DB_001/
│   │   ├── mission.json        # Decision package
│   │   └── status.log          # Status log
│   └── Agent_DB_001.log        # Execution log (requests, errors, help)
├── plans/
│   └── *.plan.md               # Plan files
├── skills/                     # AI skill library
└── rules/                      # AI rule library
```

### Status Flow
```
todo -> running -> done
         ↓
       failed -> retry -> running
```

---

## 8. Execution Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│              Subordinate Mode Execution Flow            │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  1. Detect [SUPERVISOR_TASK] or @AgentID marker         │
│                      ↓                                  │
│  2. Read dev/ai/agents/tasks.json                       │
│     Check: status == running/todo? dep completed?       │
│                      ↓                                  │
│  3. Read dev/ai/agents/{ID}/mission.json                │
│     Get: instruction, rules, issues                     │
│                      ↓                                  │
│  4. Execute task (only modify target_file)              │
│     Follow: all rules in rules field                    │
│                      ↓                                  │
│  5. After completion:                                   │
│     - Remove signal flare                               │
│     - Write @Status: Done                               │
│     - Update tasks.json (status -> done)                │
│     - Write status.log                                  │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

---

*This protocol is defined by PHP Agent Orchestrator*
*Version: v3.0 - Distributed Task Bus Mode*
