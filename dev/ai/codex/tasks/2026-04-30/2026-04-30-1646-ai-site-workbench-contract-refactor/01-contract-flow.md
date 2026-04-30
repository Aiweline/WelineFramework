# Contract Flow

## Shared Contract Shape

Every generated contract uses this shared envelope:

- `contract_meta`: id, version, stage, status, agent/adapter, created time.
- `permission_matrix`: what this stage can create, patch, or only read.
- `frozen_fields`: fields downstream stages must not change after confirmation.
- `mutable_fields`: fields later repair stages may patch.
- `source_contracts`: upstream contract ids this contract depends on.
- `qa_gates`: pending/pass/fail/warn state for validation steps.

## Stage Flow

Stage1 produces Site Brief, Design Manifest, Page Contract, and Block Plan. Stage1 is allowed to create site structure and design direction.

Stage2 consumes confirmed Stage1 contracts. It produces Block Visual Contract and Block Task Contract. Stage2 is not allowed to change page list, brand direction, design system, or block order.

Build consumes confirmed Stage2 contracts. It produces render data and theme manifest. Build is not allowed to modify planning contracts.

QA reads build output and contracts. Repair may only patch fields allowed by the permission matrix.

## Failure Rules

- Missing required contract metadata is a hard error.
- Missing `source_contracts` on downstream contracts is a hard error.
- Attempting to change frozen fields is a hard error.
- Old session data should not hard fail if a compatibility adapter can create a temporary contract view.
