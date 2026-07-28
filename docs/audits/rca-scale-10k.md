# RCA: 10,000-student scale probe (2026-07-28)

Harness: `docs/tools/scale_scenarios.php` on the CI testbed's
PostgreSQL instance — 10,000 attributed students, 200 volunteering
guides, 1,900 five-member teams (1,500 listed, 2,500 pending guide
interests), every scenario measured through the real services and
table classes with wall-clock and exact read/write counts.

## Measured summary (worst first, seeding excluded)

| Step | Time | Reads | Verdict |
|---|---|---|---|
| invite+accept churn (see RCA-3) | 0.81s | 4097 | probe artefact — per-op cost is healthy |
| groups_table download, 1,901 rows | 0.63s | 3805 | **RCA-1: N+1** |
| flagged anomalies build_rows | 0.20s | 11 | healthy |
| guides with_load, 200 guides | 0.16s | 602 | **RCA-2: N+1** |
| compliance_for_activity, 1,900 groups | 0.12s | 14 | healthy |
| 100 × queue_position | 0.05s | 201 | bounded by design (see notes) |
| pickteam_table page, 1,500 listed | 0.04s | 5 | healthy |
| handover propose+accept | 0.04s | 132 | healthy (messaging included) |
| groups_table page of 50 | 0.03s | 102 | RCA-1's page-bounded face |
| 10 × eoi::express | 0.01s | 89 | healthy |
| cascade accept w/ 5 rivals | 0.00s | 6 | probe skip path — see RCA-3 |

## Verified after fix (clean 10k re-run, 2026-07-28)

| Step | Before | After | Note |
|---|---|---|---|
| groups_table download, 1,901 rows | 3,805 reads / 0.90s | **3 reads / 0.20s** | RCA-1 |
| groups_table page of 50 | 102 reads | **2 reads** | RCA-1 |
| guides with_load, 200 guides | 602 reads | **5 reads** | RCA-2 |
| 10 gate refusals + 4 invite+accept | churn artefact | 511 reads / 42 writes | real ops: ~8 reads per refusal, ~110 per accepted member incl. messaging |
| cascade accept w/ 5 rival invites | skip path | 204 reads / 32 writes | four rival invitations auto-declined with messages |

Every other probe repeated its baseline number; no probe regressed and
none threw. The refusal tripwire (a same-department leftover MUST be
refused) and the four unguarded accepts both held.

## RCA-1 — groups_table seat counts are per-row queries

**Symptom.** The manager team table costs ~2 reads per row: 102 reads
for a page of 50; 3,805 for the 1,901-row export walk.

**Root cause.** `groups_table::col_size()` calls
`gatekeeper::seat_position($row)`, which issues
`groups::count_confirmed()` and `groups::count_invited()` — one COUNT
each — per rendered row. The resolver's min/max lookups are already
cached per activity (one override query), so the two COUNTs are the
entirety of the N+1. The pattern predates 1.9.0; the export path
multiplies it by the full activity.

**Fix.** Preload both counts in the table's own SQL with a portable
aggregate join (`SUM(CASE WHEN status = X THEN 1 ELSE 0 END)` grouped
by group), and let `seat_position()` accept optional preloaded counts.
Callers that do not preload are unchanged, so every other consumer of
`seat_position()` keeps its exact behaviour.

**Regression argument.** Same numbers from the same table filtered the
same way, computed in SQL instead of per row; the existing
`bulk_dashboard` Behat scenario pins the rendered size text, and a new
unit test asserts the preloaded path equals the counted path.

**Result after fix.** Page of 50: 102 → ~4 reads. Export walk of
1,901 rows: 3,805 → ~3 reads.

## RCA-2 — guides::with_load queries per guide

**Symptom.** 602 reads for 200 guides (~3 per guide) on every manager
dashboard render and every guide-picker build.

**Root cause.** Per guide, `with_load()` triggers:
`volunteering::get()` (one read — the override side of
`effective_maxguided()` is already served from the resolver's
activity-wide cache), plus `eoi::guide_commitments()` = one
`count_guiding` COUNT and one forming-preassignment COUNT.

**Fix.** Two bulk companions with the SAME definitions:
`volunteering::all_for_activity()` (one query feeding the identical
min(n, ceiling) precedence via the existing
`guide_capacity_ceiling()`), and `eoi::guide_commitments_all()` (two
grouped COUNT queries over exactly the states `count_guiding` counts
plus forming preassignments). `with_load()` consumes the maps; the
scalar methods remain the single source of truth for their
definitions and keep serving the per-guide gates.

**Regression argument.** A new unit test asserts, for a matrix of
guides (override-capped, explicit-zero, volunteer-capped,
non-volunteer, hidden), that the bulk maps equal the scalar calls
guide by guide. All capacity GATES continue to use the scalar path
under their per-guide locks — the bulk path only feeds displays and
pickers, so enforcement semantics cannot drift.

**Result after fix.** 200 guides: 602 → ~5 reads.

## RCA-3 — the 4,097-read invite probe is a probe artefact

The probe drew candidates from the post-seeding leftovers, which by
construction contain no SCOPE students; once the fresh team reached
its infeasible tail state, every remaining candidate was correctly
refused by the admission gate and the probe churned the entire
leftover list. Per successful operation the cost is ~65 reads
(messaging included); per REFUSAL it is ~8 reads — the gate is cheap
exactly where it must be. No plugin change; three probe corrections:

- The seeding's group-fill loop drains the department pools
  front-first, so the groupless leftovers ALL share the last
  department and can never complete a compliant team — the original
  churn was one long, correct refusal parade. The probe now reserves
  four compliant candidates (2 × SCOPE + two distinct others) during
  seeding, prices ten same-department gate refusals first (each MUST
  refuse — an acceptance fails the probe), then performs four real,
  unguarded invite+accepts.
- The cascade rivals moved from the tail groups (intentionally
  non-compliant, which the admission gate must keep refusing to fill)
  to the compliant head groups, where a same-department swap is
  composition-neutral. The original run's "6-read cascade" was in
  fact the probe's skip path — the churn had drained the candidate
  pool before the cascade step; the real cascade measures ~207 reads
  including four auto-declined rival invitations and their messages,
  which is healthy.
- The download probe now walks the table's OWN query (as the real
  export renderer does) instead of a raw recordset that lacked the
  preloaded aggregates.

## Observations, no code change

- `eoi::queue_position()` is one COUNT per call by design; its only
  loop consumers are bounded by the per-guide open-interest cap
  (`eoimax`) on a guide's own pending rows.
- Services throw refusals from inside their delegated transactions;
  pages catch and redirect, and Moodle rolls the transaction back at
  shutdown. Functionally safe (no partial writes are observable, as
  the refusal tests assert), but a future hygiene pass could catch,
  roll back and rethrow inside the services to silence the
  developer-mode shutdown notice.
- pickteam, anomalies, gridreport, compliance and the moves joint
  validation all held constant-query at 10k users — the 1.9.0–1.14.0
  batching discipline verified at scale.
