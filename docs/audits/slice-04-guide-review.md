# Slice 4 audit — guide review

**Date:** 2026-07-24 · **Slice contents:** state machine transition service (T2 submit / T3 return / T4 approve / A5 assign_guide, each lock+transaction+event+notify), gatekeeper `can_submit`/`can_take_guide`/`can_approve`/`can_return` (S2 preconditions; L1 gate with reason; L5 boundary + atomic re-checks; quota gate wired to the evaluator's compliance signature — vacuously true until slice 6 lets rules exist), guides service (load list, at-capacity exclusion, §4A.6 labels), quota evaluator compliance stub with the final gate signature, 3 events (`group_submitted/returned/approved`), 3 message providers (version 2026072403 + savepoint), submit form (guide select with "Guiding x of y" labels), review.php + review_page (approve confirm page, mandatory-comment return), guide.php dashboard (load header + queue + guided groups), manage.php A5 queue (assign with L5 gate, no-capacity report), landing dashboard links, 49 lang strings (sorted-merge tool added to scratchpad).

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` (65 files) | PASS |
| PHPUnit, 5.2/PostgreSQL | **PASS 36/36, 173 assertions** |
| PHPUnit, 5.2/MySQL | **PASS 36/36, 173 assertions** |
| Behat non-JS (13 scenarios), m5pg/PostgreSQL | **PASS 171/171 steps** |
| Behat non-JS (13 scenarios), m5my/MySQL | **PASS 171/171 steps** |

## §15.1 coverage delivered this slice

- **L1 at submission**: below-minimum refused with current/min figures; at-minimum passes ✔
- **L5 trio**: below (submit OK, slot occupied), at capacity (excluded from the selectable list *and* refused atomically at submit), over a tightened cap (approve refused by the atomic re-check) ✔
- **Guide-slot release on return** (§4A.5/A11): guideid cleared, count_guiding back to 0, resubmission to a different guide verified ✔
- **Approval irreversible**: no return/approve from `firm` (S2), timeapproved set, approval retains guiding load ✔
- **Mandatory return comment**: service-level refusal on blank; comment stored, in the event payload, and delivered to the leader ✔
- **A5 mode**: submit-without-guide queues; manager assignment L5-gated; assigned guide can approve ✔
- Behat: leader submit via load-labelled select; guide dashboard load header + queue; return→leader sees comment; approve→Firm badge — on both DBs ✔

## Incidents during the gate (watchdog log)

1. `core_user\fields::get_required_fields('u')` — argument is `$limitpurposes`, not a table alias; prefixing done manually. Caught by PHPUnit on first run.
2. Behat asserted the guide load line on the landing page; §4A.6 places it on the dashboard header — feature corrected (recurring lesson: assert on the page the plan names).
3. Lang-file maintenance moved to a sorted-merge script after an anchor-insert produced a duplicated block in messages.php (rewritten clean; the script is now the standard tool for lang additions).

## Security / native / good-neighbour

- review/guide/manage pages: `require_login` + `:guide`/`:manage`; group ownership via activity-scoped fetch; approve = confirm page + POST sesskey; return/assign POST sesskey ✔
- Transitions atomic under group lock with in-lock gatekeeper re-check ✔
- UI: core templates, plain selects (guide choice is a load-labelled select, no custom widget); no new JS/CSS ✔
- Raw-settings grep: `guidemode` read via `activity->settings()` in the state service (mode flag, not an override-resolvable quantity — consistent with plan §6) ✔

**Gate: GREEN.** Slice 5 (participant attributes) may start.
