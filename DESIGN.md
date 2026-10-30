# Monitoring AI visual system

## Approved overview replacement — 2026-10-30

The selected-project overview now follows the user-approved `docs/design-mockups/video-reference-home-v3.png`, based on the final Damir video frame. This supersedes charcoal/teal for overview only. Tokens are scoped by `.overview-frame` in `layout/overviewShell.css`: background #151515, sidebar #101010, surface #1b1b1b, border #303030, text #f1f1f1, muted #aaaaaa, neutral action #e5e5e5. Sidebar256px, tablet216px; page padding32px desktop16px mobile. Dashboard uses16px horizontal24px vertical gaps and14px panel radii. Month title uses normal500 weight, up to38px. Color is reserved for real progress/coverage, not navigation decoration.

`features/lifecycle/overview.css` and `OverviewDashboard.tsx` define the new composition. Current progress replaces the mockup's unsupported time-series chart; no fake graph ships. Existing identity/language controls and capability navigation remain. Final browser visual comparison is pending due automation failure; see `docs/acceptance/home-video-v3.md`. The rest of this document describes the unchanged earlier system for other routes.

## Product and visual direction

Monitoring AI is a local operational workspace for reading project evidence and acting within the user's permissions. The interface uses the **Operate** mode: predictable navigation, stable information density, clear source context, and explicit limitations. The neutral charcoal and restrained teal direction follows `docs/design-mockups/it-admin-overview-employees-v1.png`; the mockup's illustrative metrics and administration features are not product requirements.

The implementation reference is `frontend/spa/src/styles.css`, with shell markup in `frontend/spa/src/layout/WorkspaceShell.tsx` and shared evidence presentation in `frontend/spa/src/features/readWorkspace.tsx`. Update this document when those shared decisions change.

## Color roles

| Token | Value | Role |
| --- | --- | --- |
| `--background` | `#101214` | Page and form-field background |
| `--sidebar` | `#151719` | Navigation and top header |
| `--surface` | `#191c1f` | Record, progress, login, and history panels |
| `--surface-hover` | `#22272b` | Neutral interactive hover |
| `--surface-selected` | `#1d302d` | Selected navigation and language |
| `--border` | `#30363c` | Panel boundaries, separators, progress track |
| `--text` | `#f2f4f6` | Primary text |
| `--muted` | `#adb4bd` | Labels, metadata, supporting text, placeholders |
| `--accent` | `#6bd3b5` | Primary action, links, selection, icons, progress fill |
| `--accent-hover` | `#8ce0c7` | Primary-action and link hover |
| `--accent-ink` | `#10241e` | Text on solid teal |
| `--warning-surface` | `#2a2418` | Unknown, partial, unavailable, and warning backgrounds |
| `--warning-border` | `#72603b` | Warning boundary |
| `--warning-text` | `#f1cf85` | Warning text |
| `--error-surface` | `#2c1e22` | Read-error background |
| `--error-border` | `#82505c` | Read-error boundary |
| `--error-text` | `#ffb1bb` | Error copy and invalid-field border |
| `--focus` | `#93e5ce` | Keyboard focus |

Supporting treatments are equally restrained: fields use a `#59636c` boundary; selected controls use `#35544c`; badges use `#22312d` with `#b6e8d9` text; ordinary coverage uses `#1c2724` with `#c5e6da` text. Meaning must also be written in the label or message. Teal alone does not assert that evidence is complete, accepted, or available.

Surfaces are flat. Use a single 1px border and 12px panel radius, with no decorative gradients, shadows, or thick colored alert stripe. Small controls and badges use a 6px radius.

## Typography

Use `"Segoe UI", system-ui, -apple-system, sans-serif` without a network font dependency. This is an operational local application that must remain usable offline. The body root is 16px; dense operational labels, navigation, table headers, metadata, badges, and control text are 14px. Font synthesis is disabled.

| Role | Size | Weight | Line height |
| --- | --- | --- | --- |
| Page heading | 30px desktop / 26px mobile | 650 | 1.25 |
| Section heading | 20px | 600 | 1.4 |
| Record heading | 16px | 600 | 1.5 |
| Ordinary body | 16px | Normal | 1.6 |
| Dense labels and metadata | 14px | Normal or 600 by role | Usually 1.6 |
| Progress value | 28px | 650 | 1.3 |
| Button label | 14px | 600 | 1.4 |

Page and section headings use modest negative tracking (`-0.025em` and `-0.015em`). Headings balance wrapping; paragraphs use progressive `text-wrap: pretty`. Supporting prose, coverage explanations, and error explanations stay within 75ch where their components define a measure. Avoid uppercase eyebrows above headings. Read-only status belongs in a badge beside the page title and wraps below it when necessary.

Use tabular numerals for tables, record values, and progress quantities. Consolas/Courier New is reserved for technical codes; identifiers and long source values wrap rather than forcing the page wider. Do not apply monospace to ordinary operational prose.

## Layout and spacing

The shared spacing roles are 8px tight, 16px group, 24px section, and 32px page. Related labels and values stay close; headings and major evidence sections receive greater separation. Intermediate 4px, 12px, and 20px gaps support navigation, field rows, and grids.

Desktop uses a 232px sidebar and a header with a 72px minimum height. The header has 32px horizontal padding, project context on the left, and identity/language controls on the right. Its project name may truncate with an ellipsis and retains a title attribute. The full project name remains in the main workspace context. The main container is centered, has a 1280px maximum width including its 32px padding, and uses a shrinkable content column.

Sidebar items have a 44px minimum height, 20px stroke icons, 12px icon/label gaps, and 4px separation. Selected navigation combines a filled surface, fine border, teal text, and heavier weight. The brand uses a 24px authored SVG mark. Keep icons within this consistent line-based family.

## Components and factual states

- **Workspace heading:** page title and adjacent read-only badge, then project/timezone context and a technical-details disclosure. Privacy and visibility limits remain directly visible below that context.
- **Record panels:** 24px desktop padding, 12px radius, 1px border. Record grids auto-fit columns with a 320px preferred minimum. Definition rows pair muted labels with readable values and use fine separators. Nested data remains available.
- **Technical detail:** version, provenance, and identifier detail can sit behind an explicit disclosure. Human-readable reasons, source prose, warnings, and semantic state stay visible. Technical reason codes remain directly beneath the corresponding explanation.
- **Coverage:** always show the supplied state and visibility explanation. Unknown, partial, and unavailable states share the warning palette, with explicit text distinguishing them. Synthetic and AI provenance badges reflect source data.
- **Progress:** show only values supplied by the application. The native progress element has an 8px charcoal track and teal fill, with explicit Chromium and Firefox styling. A real zero remains zero; unavailable evidence must not be rendered as a reassuring zero or invented percentage.
- **Errors:** use readable error text, a restrained error surface and border where the read-error component applies, and the existing recovery action. Preserve request identifiers when supplied.
- **Empty and loading:** retain the real empty explanation, recovery controls, pending text, and busy/disabled behavior. Decorative metrics or sample records must not fill an empty state. Synthetic QA fixtures must remain clearly identified.
- **Forms:** input, select, and textarea surfaces follow the dark palette globally. Labels, hints, validation, byte counts, and submission restrictions remain part of the interaction. The primary action is solid teal with dark text; secondary actions use neutral bordered surfaces.
- **Tables:** muted 14px headers on `#202428`, 16px desktop cell padding, fine row separators, and restrained row hover. A labeled, focusable table region owns horizontal scrolling; the page reflows around it. Preserve the existing ReUI grid behavior.

## Responsive behavior

At widths up to 760px, the sidebar becomes a fixed drawer with a width of `min(85vw, 300px)`, viewport-bounded vertical scrolling, and an explicit close action. The shell preserves its existing modal, inert-background, Escape, focus-trap, and focus-return behavior.

The mobile header is a two-column grid: navigation and language controls occupy the first row; authenticated identity and logout occupy a second row. Empty identity content is removed. The repeated header project context is hidden, while the full project context stays visible in the main content.

Mobile main padding is 24px vertically and 16px horizontally. Record grids become one column, panel padding becomes 16px, context metadata stacks, and title/badge pairs wrap. Record label/value rows retain their two-column structure with shrinkable values. Table cell padding becomes 12px and remaining tables keep a 520px minimum width inside their own scrolling region. Lifecycle grid content also retains its existing mobile-card adaptation.

Between 761px and 1000px, header gaps narrow and identity text wraps within a bounded width. Safe-area padding is respected at the header top and main bottom. Long content must not cause viewport-wide horizontal overflow; verify this with real copy at a 375px viewport and with larger text.

## Controls and keyboard behavior

Buttons, inputs, selects, textareas, navigation rows, language buttons, and disclosure summaries provide at least 44px control height. Hover, pressed, selected, disabled, invalid, and busy states retain distinct treatments. Disabled controls use reduced opacity and a not-allowed cursor; disabled buttons inside busy regions use a wait cursor.

Keyboard controls use a 3px focus outline with a 3px offset. Programmatically focused headings use a 3px outline with a 4px offset. When the skip link focuses the main region, a fine outline marks its heading rather than surrounding the entire content area. The skip link becomes visible on focus. Text selection uses teal with dark text, caret color follows the accent, and supported scrollbars use the neutral palette. Reduced-motion preferences disable smooth scrolling.

These are implementation conventions, not an accessibility certification. Review keyboard operation, contrast, zoom, localization, long content, and actual unavailable/error states when changing shared components.
