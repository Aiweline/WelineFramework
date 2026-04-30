# Frontend Workbench UX

## Current Problem

The workbench has useful controls, but requirement fields, page type selection, generation buttons, autosave, and queue status are spread across large scripts. This makes repeated logic easy to introduce and hard to reason about.

## Target UX

The requirement area becomes a single "Requirements and Skills" panel:

- Site title, tagline, domain, language, requirement text.
- Page type selection.
- Skill multi-select with chips.
- Skill manager drawer for create/edit/disable/search.
- Clear distinction between saving requirements and starting AI generation.

## Queue UX Boundary

Frontend starts queue operations and displays queue/SSE state. It must not implement AI execution, background polling runners, or direct generation fallback.

## Humanized Behavior

- Autosave should show saved/saving/failed state consistently.
- Disabled or missing selected skills should block generation with a clear message.
- Stage2 should display inherited Stage1 skills and allow explicit override only with a warning.
- Duplicate generate buttons should collapse into one primary action per stage.
