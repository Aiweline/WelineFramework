# Atomic Task Index

Use one atom per implementation request. Each atom is self-contained enough for an AI agent to start without opening the full plan.

| ID | Document | Depends On | Status |
|---|---|---|---|
| A00 | `atoms/A00-baseline.md` | none | done |
| A01 | `atoms/A01-protect-existing-user-changes.md` | A00 | done |
| C01 | `atoms/C01-contract-types-and-meta.md` | A00 | done |
| C02 | `atoms/C02-permission-and-frozen-fields.md` | C01 | done |
| C03 | `atoms/C03-source-contracts-and-qa-gates.md` | C01 | done |
| C04 | `atoms/C04-legacy-contract-adapter.md` | C01 | done |
| AD01 | `atoms/AD01-adapter-selector.md` | C01 | done |
| S01 | `atoms/S01-custom-skill-storage.md` | A00 | done |
| S02 | `atoms/S02-skill-registry-split.md` | S01 | done |
| S03 | `atoms/S03-skill-normalize-and-hash.md` | S02 | done |
| S04 | `atoms/S04-skill-default-and-snapshot.md` | S03 | done |
| S05 | `atoms/S05-skill-disable-and-conflict-rules.md` | S02 | done |
| API01 | `atoms/API01-skill-list-url-and-endpoint.md` | S02 | done |
| API02 | `atoms/API02-skill-save-endpoint.md` | S01,S03 | done |
| API03 | `atoms/API03-skill-disable-delete-errors.md` | S05 | done |
| Q01 | `atoms/Q01-scope-selected-skills.md` | S04 | done |
| Q02 | `atoms/Q02-plan-queue-skill-propagation.md` | Q01 | done |
| Q03 | `atoms/Q03-task-build-skill-inheritance.md` | Q02 | done |
| P01 | `atoms/P01-stage1-contract-context-input.md` | C01,S04,Q02 | done |
| P02 | `atoms/P02-stage1-site-design-page-contracts.md` | P01 | done |
| P03 | `atoms/P03-stage1-block-plan-contract.md` | P02 | done |
| P04 | `atoms/P04-stage1-sanitization-and-tests.md` | P02,P03 | done |
| T01 | `atoms/T01-stage2-confirmed-contract-input.md` | P03 | done |
| T02 | `atoms/T02-stage2-block-visual-task-contracts.md` | T01 | done |
| T03 | `atoms/T03-stage2-frozen-source-validation.md` | T02,C02,C03 | done |
| T04 | `atoms/T04-stage2-no-history-tests.md` | T02 | done |
| B01 | `atoms/B01-build-consumes-task-contracts.md` | T02 | done |
| B02 | `atoms/B02-build-legacy-adapter.md` | C04,B01 | done |
| B03 | `atoms/B03-build-render-data-contract.md` | B01 | done |
| QA01 | `atoms/QA01-design-copy-seo-linters.md` | B03 | done |
| QA02 | `atoms/QA02-contract-linter-and-report.md` | C02,C03 | done |
| RP01 | `atoms/RP01-repair-planner-executor.md` | QA01,QA02 | done |
| F01 | `atoms/F01-needs-form-state-module.md` | Q01 | done |
| F02 | `atoms/F02-autosave-consolidation.md` | F01 | done |
| F03 | `atoms/F03-skill-multiselect.md` | API01,F01 | done |
| F04 | `atoms/F04-skill-manager-drawer.md` | API01,API02,API03 | done |
| F05 | `atoms/F05-queue-state-module.md` | Q02,Q03 | done |
| F06 | `atoms/F06-generation-button-ux.md` | F05 | done |
| F07 | `atoms/F07-stage2-skill-override-ux.md` | Q03,F03 | done |
| F08 | `atoms/F08-frontend-e2e.md` | F03,F04,F05 | done |
| DOC01 | `atoms/DOC01-task-record.md` | implementation batches | done |
| REL01 | `atoms/REL01-phased-integration.md` | implementation batches | done |
| REL02 | `atoms/REL02-final-target-tests.md` | all | done |
