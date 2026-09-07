# Textile Heritage Design QA

- Source visual truth path: `/var/folders/j_/3f031n9s6493k_nrptr2zck40000gn/T/codex-clipboard-90529a95-0916-41ef-bffb-2707067364aa.png` (available at implementation start; removed by the host before the final comparison)
- Implementation: `https://p05113ef3.test.weline.com:9555/`, homepage `textile-heritage` component immediately after the hero
- Implementation screenshot path: not persisted by the browser surface; captured in Codex Browser at 668 × 674 px and in the verified Weline Chrome profile at 1512 × 700 px
- CSS viewport and density: the Weline Chrome capture reported 1512 × 700 output pixels; the browser surface did not expose a persisted CSS-size/density record
- State: Chinese storefront, default homepage, component at rest, no hover/focus state

**Findings**

- [P2] Final same-input visual comparison is blocked.
  Location: final full-view and focused-region comparison.
  Evidence: the source attachment was visible and inspected at the start, but its temporary filesystem path no longer exists. The final desktop implementation screenshot was captured successfully, but the source could not be reopened in the same comparison input.
  Impact: typography, spacing, color, imagery, and copy cannot be formally signed off against the source under the required comparison rule.
  Fix: reattach or restore the source image, then compare it with a fresh Weline Chrome desktop capture in one input.

**Runtime Evidence**

- The live homepage order is hero → textile heritage → featured products.
- The desktop capture shows six real raster artifacts in one row: 云锦、宋锦、蜀锦、苏绣、妆花、花罗.
- The 668 px narrow capture shows a three-column responsive grid; long provenance text remains on one line and truncates with an ellipsis instead of breaking the card.
- All six local assets return HTTP 200 with JPEG/PNG MIME types.
- Source and license links remain present and keyboard-focusable. No component action was triggered because interaction is not required to view the directory.
- Browser console collection was attempted, but the browser connection timed out before returning a log result; no console claim is made.

**Required Fidelity Surfaces**

- Fonts and typography: the runtime has a centered bilingual title, semibold technique labels, and a smaller provenance line; final source-to-runtime font comparison is blocked.
- Spacing and layout rhythm: the desktop runtime uses one six-card row with consistent gaps, radii, and card heights; the narrow runtime uses a stable three-column grid; final source-normalized measurement comparison is blocked.
- Colors and visual tokens: the runtime uses the Theme surface, text, border, radius, and shadow tokens; final palette comparison is blocked.
- Image quality and asset fidelity: all visible assets are real local raster reproductions tied to named museum, journal, or Commons provenance; no generated pattern, CSS art, SVG simulation, or placeholder is used.
- Copy and content: the bilingual section title and six requested techniques are present; provenance/license copy is intentionally additional to the visual reference.

**Comparison History**

1. Initial runtime inspection found two P2 issues: the component appeared after all product sections, and long provenance text wrapped into uneven multi-line fragments at narrow width.
2. Fixes applied: moved the homepage slot directly after the hero; added single-line overflow and ellipsis behavior to provenance links; bumped the widget stylesheet version.
3. Post-fix runtime evidence: fresh browser reload places the component immediately after the hero; narrow screenshots show stable provenance lines; Weline Chrome shows all six cards in one desktop row.
4. Final reference-to-runtime comparison could not be performed because the source attachment disappeared from its temporary path.

**Implementation Checklist**

- [x] Theme owns the reusable component and catalog.
- [x] Default Hanfu homepage renders the component after the hero.
- [x] Desktop renders six cards in one row.
- [x] Narrow layout remains readable without provenance wrapping.
- [x] Six authentic local raster assets and provenance links are live.
- [ ] Reattach the source image and complete the same-input visual comparison.

**Follow-up Polish**

- None proposed until the source image is restored; avoid making ungrounded visual changes.

final result: blocked
