# Progress - Fix backend tree row inline alignment

- 2026-03-24 12:31 Created the task workspace.
- 2026-03-24 12:40 Confirmed the affected screen is `Weline_Acl` role assignment tree (`app/code/Weline/Acl/view/templates/Backend/Acl/Role/assign.phtml`).
- 2026-03-24 12:42 Found the layout root cause: custom `#acl .jstree-anchor { display:flex; }` made the anchor block-level, so jstree expand toggles (`.jstree-ocl`) and anchor content no longer stayed on the same row.
- 2026-03-24 12:44 Updated the node layout so each `jstree` node is a wrapping flex row, keeping expander + anchor inline and forcing child trees onto the next line.
- 2026-03-24 12:48 Verified the template still passes `php -l`; no browser/E2E replay was run for this small CSS-only adjustment.
