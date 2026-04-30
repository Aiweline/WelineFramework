# Protected Files

These files were already modified before implementation work in this task package. Do not revert, overwrite, or reformat them unless a later atom explicitly owns the file and the change is reviewed against the existing user edit.

```text
app/code/GuoLaiRen/PageBuilder/Queue/AiSiteAssetQueue.php
tests/e2e/specs/backend/pagebuilder-ai-site-workbench.spec.js
```

## Worker Rule

Any worker touching one of these files must first inspect the current file, identify the existing edits, and preserve unrelated user changes.
