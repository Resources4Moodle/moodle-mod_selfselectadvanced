# Slice 12 audit — auto-grouping

**Date:** 2026-07-24 · **Slice contents:** deterministic engine — B4 pool (groupless respond-holders whose **per-user effective cutoff** passed; extensions excluded until they lapse), seeded Fisher-Yates shuffle, **B1-corrected A13 sizing** (`g=ceil(P/max)`; fallback `g=floor(P/min)`; balanced fill; overflow impossible; residue when unavoidable), §9.3 priority-ordered relaxation cascade (unfillable min-rules and blocking max-rules bypassed-and-logged for the run), system leader designation under L3, formed groups enter the **A5 guide queue** (`pending_guide`, no guide, `autoformed=1`), full `agrun` decision log with replayable seed + `autogroup_run` event; `run_autogrouping` task (5-min cadence, `sweep_due` guard so identical pools don't spam runs, **re-runs as extended windows close** per B4); manual "Run auto-grouping now" + last-run summary on the manager dashboard; version 2026072408 + savepoint.

## Gate results (CI box `ci-run --reinit`) — `RESULT fail=0`

| Check | Result |
|---|---|
| PHPUnit (65 tests incl. the new autogroup suite), pg + mariadb | PASS |
| Behat incl. @javascript, m5pg + m5my | **PASS 29/29 scenarios, 401 steps each** |
| phpcs / phpdoc / validate / savepoints | PASS |

## §15.1 coverage delivered

- **B1 sweep**: five (min,max) bands × pools 0–30 — every formed group within [min,max], placement+residue always sums to P; the review's min4/max6/P7 case → one group of 6 + 1 residue; balanced 4+3 case ✔
- **Determinism**: identical seed → identical plan; different seed → different shuffle; run stores its seed ✔
- **Cascade**: single-Female pool with a ≥1-Female rule → exactly one group satisfies, rule bypassed-and-logged thereafter ✔
- **B4**: extended student excluded from the pool; guard blocks duplicate sweeps; window lapse re-arms the task, whose next run leaves the solo student as counted residue (flagged report path) ✔
- **End-to-end**: autoformed groups pending_guide/no-guide with valid L3 leaders and sizes in band; event with counts ✔ · Behat: manual trigger → "1 group(s) formed, 2 placed, 0 left", queue populated ✔

## Notes

- Groupless students always have a free L3 slot (caps ≥1), so the §9.5 leaderless fallback is defensive; kept and documented.
- Residue placement path = flagged report → override-backed staged move (proven in slice 8's P13 test).

**Gate: GREEN.** Remaining: slice 13 (privacy/backup/reminder/leave-flow), slice 14 (final audit + release).
