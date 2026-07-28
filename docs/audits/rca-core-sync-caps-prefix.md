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
