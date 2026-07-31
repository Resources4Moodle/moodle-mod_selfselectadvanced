# RCA: frozen-group core sync, membership-cap good-neighbour flag, group-id prefix (2026-07-28)

Scope: four maintainer questions, answered from the code as it stands
(1.14.1, gate-green), each with root-cause analysis before any change.

## Q1 — Is a change to a frozen group reflected in Moodle's core group?

**Current behaviour, path by path.** The plugin owns the mirror in
`classes/local/freeze.php` and touches core only through the official
groups API, only for groups it created (tracked by `coregroupid`):

| Change to a frozen group | Reflected in core? | Mechanism |
|---|---|---|
| Manager staged move OUT of the group | YES | `moves.php` calls `freeze::sync_membership_change(remove)` in the same transaction, plus a fresh snapshot |
| Manager staged move INTO the group | YES | same, `add` direction |
| Freeze itself (the push) | YES | full roster reconciliation (add missing, remove extras), grouping ensured |
| Unfreeze | YES | owned core group deleted; plugin roster restored from the newest snapshot |
| Re-freeze after out-of-band core deletion | YES | repair path recreates and reconciles (drift rule) |
| Guide reassignment / handover | n/a | core groups carry no guide concept; no core impact by design |
| Leadership succession via move | n/a | core groups carry no leader concept; roster sync above still covers the membership side |
| Group rename | impossible | the plugin has NO rename path anywhere — names are fixed at creation, so the core name cannot drift from the plugin side |
| Student leave / invitation flows | impossible | the gatekeeper refuses every self-service mutation outside FORMING |
| Group deletion | impossible | `can_delete_group` refuses any state but FORMING; a frozen group can never be deleted, so no orphaned core group |
| Core-side (out-of-band) edits | reported, not overwritten | `freeze::drift()` reports extra/missing members; unfreeze discards them with a drift report (spec 14.5: never silently fight the course staff) |

**Root cause of the one real gap.** `observer::user_deleted` cleans the
user's plugin ATTRIBUTES only. If a deleted account was a confirmed
member, the plugin roster keeps counting it: core removes the core-group
membership rows itself, so a frozen mirror shows drift; a later
re-freeze reconciliation would try to `groups_add_member()` a deleted
user; seat counts and compliance keep pricing a ghost.

**Fix (minimal, good-neighbour).** The observer now also marks every
confirmed/invited membership of the deleted user as removed, and for
frozen groups appends a snapshot so unfreeze restores the true roster.
Leader-gone and guideless anomalies are already surfaced by the
flagged reports (a deleted leader IS "leader no longer an active
participant"), so no further reach is taken.

## Q2 — If the membership cap is violated, may the plugin raise it?

**No — by design, and it stays that way.** The cap is L4
(`maxmembership`, default 1, per-user overridable): the maximum number
of groups a person may belong to in the activity. Every join path
(create, invite, accept, auto-group, staged move set) enforces it, so a
violation can only arise by GRANDFATHERING: the manager lowers the cap
(or deletes an override) after people already joined; unfreeze also
restores rosters "even if current limits would now reject the roster"
(spec 4A.8). The plugin never silently alters an activity setting or an
override — raising a cap is the manager's decision, not the plugin's.

## Q3 — Good-neighbour flag before pushing to core groups/groupings

**Root cause of the current behaviour.** `can_freeze()` checks state,
size band and seat-plan compliance — it never audits L4. So a
grandfathered over-cap member is pushed into the course's
groups/grouping mechanism silently, and the course staff learn nothing.

**Fix.** Freeze IS the push moment, so it gains a membership-cap audit
with two faces:

- **The gate**: `freeze_group()` (non-repair path only — repairs of an
  already-frozen mirror stay grandfathered) refuses when any confirmed
  member's plugin membership count exceeds their effective cap, naming
  the members and their counts (`refusalmembershipaudit`).
- **The flag**: the same refusal notifies every holder of
  `mod/selfselectadvanced:manage` (new `capaudit` message provider)
  with the affected members and a link to the dashboard, so the
  manager can raise the activity cap or grant per-user overrides
  BEFORE the group is pushed. The flagged-anomalies report also gains
  a proactive "over membership cap" row type, so managers see the
  condition before any guide hits the gate.

The plugin still never changes the cap itself. Repair freezes and
unfreeze grandfathering are untouched.

## Q4 — How is the group-id prefix fixed? Can the manager control it?

**Root cause.** Two prefixes exist:

- The plugin group id (`pluginuid`): `groups::build_pluginuid()`
  hardcodes `SSA-{COURSESHORT sanitised, ≤12}-{DB id, 4+ digits}` at
  creation time (decision A1). Nothing is configurable.
- The mirrored core group NAME: `[cm idnumber, or the activity name]
  groupname` — this one is ALREADY manager-controllable via the
  module's common "ID number" field, which the fix documents rather
  than duplicates.

**Fix.** New activity setting `uidprefix` (2–8 characters A–Z/0–9,
default `SSA`, upper-cased on save) consumed by `build_pluginuid()`.
The prefix applies to groups created AFTER the change: a pluginuid is
minted once and stored, and its uniqueness-forever guarantee (the DB id
component) must never be re-written retroactively, so existing ids
keep their stamp. Backup/restore carries the setting; the field is
NOT NULL DEFAULT 'SSA' so upgraded sites behave identically.

## Regression stance

- Every new gate lives in the freeze path only; no join/leave/invite
  flow changes, so the 1.13.0/1.14.x capacity semantics are untouched.
- The audit compares numbers the resolver and `count_memberships()`
  already produce; no new counting definition is introduced.
- `build_pluginuid()` keeps its shape and its `%04d` id component; only
  the literal prefix becomes data-driven.
- The observer fix only REMOVES ghost rows (status flip + snapshot),
  the same operations a staged move performs.
- New PHPUnit coverage: freeze-audit refusal + manager flag + repair
  bypass; prefix round-trip incl. default and sanitisation; deleted
  user leaves rosters and frozen mirrors clean. The 10k harness gains
  freeze/audit probes and a fresh full run (new shortname; the held
  SCALE10K course is preserved untouched).

## Verified at 10,000 users (SCALE10KB run, 2026-07-28)

A full fresh 10k run under a second namespace (the first 10k course
kept intact beside it, doubling the site's data):

| 1.15.0 step | Time | Reads | Writes |
|---|---|---|---|
| freeze: push 5 members to core groups (group, members, grouping, snapshot, messages) | 0.17s | 251 | 48 |
| freeze audit refusal at a lowered cap | 0.02s | 23 | 5 |
| observer: delete a frozen member's account (roster + snapshot clean, tripwired) | 0.07s | 88 | 50 |
| uidprefix stamps new groups (tripwired) | 0.01s | 16 | 5 |

Every pre-1.15.0 probe repeated its baseline query count; the flagged
report stayed constant-query (12 reads) with the over-cap detection
in. Two apparent anomalies were chased and cleared: the invite probe
read 593 once immediately after upgrade.php purged all caches (511
again on the warm rerun - cold-cache artefact), and the table probes'
~1s wall time was process-lifecycle noise - in isolation, with BOTH
10k courses in the database, the activity-scoped aggregate answers in
7ms and a full 50-row render in 48ms.

## Adversarial audit of the patch (27 agents, 2026-07-28)

Three review lenses over the diff, every finding judged by two
independent skeptics. Four distinct defects confirmed, all fixed the
same day; six candidates refuted (notably: no pre-1.15.0 backfill is
needed - core silently skips deleted users at add-member, and the new
observer discipline heals older ghosts on the next unfreeze).

- **Observer without the roster discipline** (the one deviation from
  lock rule A7): user_deleted flipped memberships and appended
  snapshots with no group lock and no transaction - a concurrent
  unfreeze could re-confirm the ghost from the pre-deletion snapshot,
  and a crash between flip and snapshot left a frozen mirror that
  would resurrect it. Now: per-group lock, one transaction per group,
  status re-read under the lock.
- **Ghost via staged moves (major)**: the deleted student's PENDING
  staged moves survived and a later commit re-inserted the ghost as a
  member. Deletion now cancels them under the same activity lock the
  commit path holds (a deleted SUCCESSOR was already covered - the
  commit-time SUCC verdict refuses once the membership flip lands).
- **Manager flags under the group lock**: the capaudit notifications
  drove synchronous mail while holding 'group:{id}' (10s lock
  timeout), so a slow relay times the manager count could starve
  concurrent operations into errlocktimeout. The push now lives in
  its own locked transaction (push_to_core); the flag and the refusal
  fire after the lock releases.
- **Wrong flag text on frozen groups**: the proactive over-cap row
  said "cannot be frozen" on groups that already ARE - a frozen group
  is grandfathered; its next push after unfreezing is what waits. A
  state-aware string now says exactly that.

## Correction appended 2026-07-31 (1.20, T-16)

The row above reading "Guide reassignment / handover | n/a | core groups
carry no guide concept; no core impact by design" is **superseded, not
deleted** — it records what was true when this audit was written.

Decision 7 changed the expected membership of a mirrored course group to
**confirmed plugin members UNION the assigned guide**. Core groups still
have no guide *concept*, which is why the guide is carried as an ordinary
membership row and is never written into `selfselectadvanced_member` or a
snapshot roster; but guide reassignment and handover DO have a core
impact now, and both call `freeze::request_sync()` inside their
transaction and `freeze::sync_core_group()` after the lock release. One
sync swaps the outgoing guide out (they are in neither the confirmed set
nor `guideid`, so they fall in the owned-removal set) and the incoming
guide in.

The same ticket also retired `push_to_core()` — the "own locked
transaction" mentioned above no longer exists. Core group API calls now
run entirely outside the plugin's locks and transactions; the state flip
commits alone, and `request_sync()` queues the convergence job in that
same transaction so a crash in between is repaired by cron.
