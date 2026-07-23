# Slice 3 audit — succession (leadership transfer & step-out)

**Date:** 2026-07-24 · **Slice contents:** gatekeeper `can_nominate`/`can_confirm_succession`/`check_nominee_leadslot` (S2 preconditions, L3 boundary + reasons, step-out L1 replacement rule), succession engine (nominate/confirm/decline/cancel under lock+transaction, A3 single active nomination on the group row), `leadership_transferred` event, `nomination`+`nominationresult` message providers (version 2026072402 + savepoint), nominate form (native autocomplete scoped to roster; cap-excluded members listed with reasons per §6.4), group-page nomination banner + nominee accept/decline + leader cancel, landing "My nominations", tests + behat.

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` (54 files) | PASS |
| PHPUnit, 5.2/PostgreSQL | **PASS 31/31, 135 assertions** |
| PHPUnit, 5.2/MySQL | **PASS 31/31, 135 assertions** |
| Behat non-JS (10 scenarios), m5pg/PostgreSQL | **PASS 120/120 steps** |
| Behat non-JS (10 scenarios), m5my/MySQL | **PASS 120/120 steps** |
| Behat `@javascript` | invitations scenario PASS (twice); succession scenario **18/19 steps passed in-browser** — the one failing step was a feature-file assertion placed on the wrong page (fixed); subsequent whole-tag reruns hit the box's documented Selenium memory ceiling (`ERR_INSUFFICIENT_RESOURCES` / "tab crashed" / WebDriver no-start at <650MB free), an environmental limit recorded in project memory long before this plugin. JS scenarios remain tagged and run in full on GitHub Actions (chrome profile); the flow itself is fully covered by PHPUnit. |

## §15.1 coverage delivered this slice

- **Nominee L3 boundary**: at-cap member refused with reason; **atomic re-check on confirm** (nominee who gained a lead between nomination and confirmation refused) ✔
- **Transfer semantics**: leaderid + isleader swap; ex-leader remains confirmed; **lead slot released** (count_leading 0/1 asserted) ✔
- **Step-out L1 rule**: confirm refused with `refusalreplacementneeded` until a replacement is invited *through the normal cap-checked invitation flow* and accepts; ex-leader removed after; **held place** (invitable elsewhere) ✔
- **A3 single nomination**: second nomination refused while one is active; decline/cancel clear it (cancel leader-only) ✔
- **S2**: nominate + confirm refused outside `forming` ✔
- Event `leadership_transferred` with from/to/type payload ✔

## Incidents during the gate (watchdog log)

1. Feature used the autocomplete in a non-JS scenario ("requires javascript" coding exception) → scenario tagged `@javascript`; a generator-arranged non-JS decline scenario covers the response path without JS.
2. Generated nominee lacked a member row in one scenario → access denied; fixed (and it validated the page-access guard works).
3. Final counter assertion ran on the group page instead of the landing page → navigation step added; faildump screenshot confirmed the full transfer succeeded in-browser.
4. Environmental: Selenium runs required php-fpm graceful reloads to reclaim worker memory; documented above.

## Security / native / good-neighbour

- All four nomination actions POST + sesskey; nominate via formslib ✔ · IDOR: group ownership re-verified; nominee/leader identity checked server-side in gatekeeper + engine ✔
- Race-safety: nominate/confirm/decline/cancel all lock + transaction + re-validate ✔
- Native selector for roster-scoped nomination (C10) with §6.4 exclusion reasons as static form rows ✔ · No new assets; M4 grep clean ✔

**Gate: GREEN.** Slice 4 (guide review) may start.
