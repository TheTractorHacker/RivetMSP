# Rivet MSP UI/UX QA scripts (2026-10-21)

Playwright (Python) scripts used for the web UI/UX pass. They run against a private server only:

    # worktree /home/sysadmin/qa-ux-rivetmsp, own DB rivetmsp_qa_ux (clone of rivetmsp_demo, migrated to 2.6.106), 127.0.0.1:8191
    PHP_CLI_SERVER_WORKERS=32 php -S 127.0.0.1:8191 -t <worktree> /home/sysadmin/demos/router.php

Environment: `QA_PYLIBS` (dir with playwright, greenlet, pyee), `QA_AXE` (axe.min.js), `QA_OUT` (output dir), `QA_BASE` (default http://127.0.0.1:8191), `QA_PAGES` (JSON list of paths).
Logins are the fictional demo users listed in `ux_common.py`. Test records are named `QA-UI-...`. Nothing is sent: no invoices, quotes or e-mail.

| Script | Phase | What it does |
|---|---|---|
| `discover.py` | A | BFS over GET links for a role (skips destructive/outbound links) -> page list |
| `phaseA_crawl.py W H area user` | A | Full-page screenshot per page + programmatic checks (h-scroll, clipped text, tiny text, unnamed controls, raw PHP errors, console errors, failed requests, axe-core). `QA_REALDARK=1` for the real dark theme (needs the user's dark preference set in the DB; toggling `data-bs-theme` at runtime is NOT equivalent) |
| `summarize.py` | A | Digest of a crawl JSON |
| `axe_nodes.py` | A | Lists axe violation nodes for chosen rules to find shared root causes |
| `phaseB_click.py` | B | Clicks every deduplicated visible control per page (unsafe/outbound ones skipped), classifies nav/modal/toggle/no-effect |
| `phaseB_crud.py` | B | Creates a `QA-UI-` record through each module's New modal and checks it persists after reload |
| `persona_tech.py`, `persona_msp.py`, `persona_portal.py`, `flow.py` | C | Persona tasks via the navigation with click counts and friction notes |
| `sheet.py` | - | Browser-based contact sheet of screenshots (no PIL needed) |

Reading the results: crawl JSON `agent_<W>x<H>[_dark].json`, `click_admin.json`, `crud.json`, `persona_*.json` (kept in the scratchpad `ux_msp/shots`; the figures are summarised in `UX_REPORT_RIVETMSP.md`). PHP fatals are not displayed (display_errors off), so also grep the server log for `PHP Fatal` after a crawl; that is how the Pipeline/Opportunities and sort-crash defects were found.
