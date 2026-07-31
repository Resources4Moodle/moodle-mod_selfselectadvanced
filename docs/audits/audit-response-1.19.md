# Response to the external audit of 2026-07-30

The external audit of `selfselectadvanced-1.19.0-2194252` is recorded at
`_inbox/AuditComments.md`. It was a static audit — it did not run the
code — so every claim is re-checked here against the source before it is
accepted or rejected. Claims that turn out to be wrong are said to be
wrong, with evidence; claims that turn out to be right are accepted
without argument.

This file is the running record. It is not a defence.

## Verified independently, BEFORE the Fable audit

### Accepted — BLK-UPG-001, upgrade savepoint mismatch

Confirmed, and it is ours from earlier the same day. `version.php`
declares `2026073091`; the last savepoint in `db/upgrade.php` is
`2026073090`. The version was raised to make a new message provider
register, and no savepoint step was added with it.

### Accepted — HIGH-SEC-001, state change through GET

Confirmed and worse than a single instance. `departments.php:95`
(`progdelete`) and `departments.php:115` (`delete`, `up`, `down`) gate
on `confirm_sesskey()` **alone**, with no `data_submitted()`. Only one
occurrence of the correct pair exists in that file (`progadd`, line 90).
A GET link therefore deletes a department or reorders the tree.

### Accepted — privacy: contacts declared but never exported

Confirmed. `selfselectadvanced_contact` appears in `get_metadata()` and
in `get_users_in_context()`, but `export_user_data()` never reads it.

### Accepted — privacy: fields missing from metadata

Confirmed. The ticket table declares
`requestedby, claimedby, resolvedby, request, resolution, type, status`
— `requested` is absent. The move table declares only
`userid, successorid` — `reason` and `responsenote` are absent, and both
were added by us in 1.19.0 without touching the provider.

### Found here, MISSED by the external audit

`selfselectadvanced_penalty` is read by `export_user_data()` but is not
declared in `get_metadata()` at all. Moodle requires that anything
exported is declared. (The table is group-scoped rather than user-scoped,
so its absence from the scrub path is defensible; its absence from the
metadata is not.)

### Rejected — "deletion may leave personal data behind" as a blanket claim

Not supported. `delete_data_for_user()` and `delete_data_for_users()`
both delegate to `scrub_user_in_activity()`, which touches eleven
tables: group, ticket, snapshot, contact, agrun, volunteer, override,
move, member, eoi and digestq. User attributes are deleted separately
at system context by `attributes\manager::delete_for_user()`, which is
correct because they are site-wide rather than per-activity.

The tables the scrub does not touch are `selfselectadvanced` itself,
`dept`, `qslot`, `quota` and `template` — configuration, holding no
personal data — and `penalty`, which is keyed by group.

An early reading here suggested deletion touched no tables at all. That
was an artefact of a bad text search, and is recorded because it is
exactly the sort of false critical this response exists to prevent.

## Process defects behind the audit's findings

The maintainer's own diagnosis — *"errors resulted when the DB
migrations were not done, running on outdated sites, false errors, BEHAT
not updated"* — is accurate, and the cause was that nothing forced an
instance to prove it was current.

`/srv/ci/preflight.sh` now runs before every gate and **refuses the
build** unless, for each instance, the installed plugin version equals
the code's, the upgrade ran clean, and the message-provider registry is
populated. It also touches every `.feature` file after syncing, because
Behat caches its parse by mtime and `rsync -a` preserves the source's.

First run:

    ### preflight: code version 2026073091
      m5pg: db=2026073091 MATCHES code, providers=24, upgrade rc=0
      m5my: db=2026073091 MATCHES code, providers=24, upgrade rc=0
    ### preflight RESULT fail=0

## Repairs executed (serial, Opus)

### HIGH-SEC-001 — closed

`departments.php`: the three mutating handlers now require
`data_submitted() && confirm_sesskey()`, and the four links that drove
them (`up`, `down`, `delete`, `progdelete`) are single-button POST forms
via `selfselectadvanced_dept_button()`.

A sweep of every root page for the same shape found **no other GET
mutation** in the plugin. One apparent hit in `guide.php:77` was checked
and is a false positive: it is the inner branch of a block already
guarded by `in_array($action, [...]) && data_submitted() && confirm_sesskey()`.

### Privacy — metadata completed, export repaired

- `ticket.requested` declared (added in 1.18, never declared).
- `move.reason` and `move.responsenote` declared (added in 1.19, never
  declared).
- `selfselectadvanced_penalty` declared — it was being **exported
  without being declared**, which the external audit did not catch.
- The ticket export's `JOIN {selfselectadvanced_group}` is now a
  **LEFT JOIN**. The external audit was exactly right: a team-limit
  ticket carries `groupid = 0`, so an inner join dropped every one of
  them out of the requester's own export. `requested` is exported with
  it, and the group name is exported as null rather than an empty
  string when the ticket is not about a team.
- **Approaches are now exported.** `selfselectadvanced_contact` was
  declared in metadata and scrubbed on deletion, but `export_user_data()`
  never read it. Both parties get the message and the answer, on the
  same reasoning the ticket export already used.

All 66 `privacy:metadata:*` keys resolve to strings.

### Selection logic R1 — a full guide is never offered for a request

The maintainer's rule: *"Guide who is full should not be listed for
request."*

- `contact.php` (approach) filtered to `remaining > 0`.
- `submit_form` now passes `withroom = 1` in **every** mode.

That second change **reverses a 1.16 A decision**, which kept a full
guide listed in students-approach mode so that an absence could not be
read as a load figure. The reversal is deliberate:

1. Submitting to a full guide was always refused at submission, so the
   old behaviour protected nobody — it moved the refusal later, after a
   team had committed to a choice.
2. The privacy point is answered better by publishing the rule than by
   hiding it. The help text states that guides at capacity are not
   listed, so an absence carries no more information than the policy.

And the help string had **already** promised this since before 1.16
("Guides at their capacity are not listed"), which students-approach
mode quietly made untrue. The code now honours its own documented
promise, and the second half of that string — which claimed every entry
shows a load — has been corrected, because that is false in
students-approach mode by design.

### Selection logic R2/R3 — a misfit is shown, and the seat is named

The maintainer's rule: *"student who does not fit the logic of group
formation should be listed with caution that the student will not fit
the requirements, students who are trying to join a group that has the
particular seat that the student will fit if filled, and the like."*

Note that this is the OPPOSITE of R1, and deliberately so. A full guide
is removed from the list, because their being full is not a judgement
about the student and nothing the student says can create capacity. A
team the student does not currently fit stays listed WITH the reason,
because its leader may have good grounds to take them anyway, and
hiding the team would deny the leader that choice and the student the
explanation.

`classes/local/fit.php` answers both, in two shapes:

- `for_person()` — one person, one team, through the real admission
  gate (`can_invite`). Used by the leader's answer table, which gained
  a **Fit** column: either the refusal the student would actually meet,
  or confirmation that they fit, plus the seat they would fill.
- `for_groups()` — one person against up to fifty teams, for the join
  picker, which fires on every keystroke.

The seat is worked out by evaluating the seat plan twice, once without
the candidate and once with, and naming the seat whose **shortfall
drops**. That reuses the booking algorithm the compliance report
already uses rather than a second, subtly different one. (Comparing
shortfalls rather than "is this seat full now" is what makes it correct
on a plan that reserves several seats of one kind — the first draft
compared fullness and would have named the wrong seat there.)

#### Cost, because this plugin is built for fifteen hundred teams

`can_invite()` costs about five queries per team. Calling it for fifty
picker rows on every keystroke would be two hundred and fifty. So
`evaluator::feasibility()` was split: the verdict itself is now
`feasibility_from_data()`, which takes the rules, the seat plan, the
roster and the attributes and runs **no queries at all**. The
per-team gate still calls it through `feasibility()`, so there is
exactly one implementation of the verdict and the picker's advisory
caution can never contradict the refusal that follows it.

`for_groups()` therefore prefetches in four queries — every roster in
one, then the rules, the seat plan and everyone's attributes — and
judges each team with none. `fit_test::test_bulk_cost_does_not_grow_with_team_count`
holds that line: judging four teams must cost no more reads than
judging one, plus a margin of two.

What `for_groups()` deliberately does NOT re-check per team is the
formation window, which resolves per leader and would cost a query a
team. The authoritative gate still applies when the request is made.
That limit is stated in the docblock rather than left for a reader to
discover.

### Accepted — CRIT-ROLE-001, role shortname collision

Confirmed, and the previous code's own comment shows how the mistake
survived review: it claimed to be "deliberately shy about an existing
role" because it passed `overwrite = false` to `assign_capability()`.
But that flag only declines to overrule a setting the role **already
has**. A foreign role has no setting for `mod/selfselectadvanced:*`,
so every one of the six was granted — at SYSTEM context, including
`:override` and `:viewall`. Being shy about overwriting is not the same
as being shy about granting.

The plugin now grants capabilities only to a role whose id it recorded
when it created it. An unrecorded role carrying the shortname is
adopted only if it still looks like ours (teacher archetype, unrenamed)
— the one-time path for sites that installed before ids were recorded.
Anything else is left completely alone, the clash is recorded, and
`coordinators.php` stops with an explanation naming the two choices
that are the administrator's to make. Sharing a shortname is not
consent.

A side benefit: the recorded id is what makes safe uninstall possible
(FC-006 below).

### Accepted — HIGH-FILES-001 and HIGH-FILE-002, proposal files

Both confirmed, with one correction to the audit's framing. Deleting
the **activity** is already safe: core drops the whole module context,
files included. The leaks are in the two paths where the context
survives:

- `api::delete_group()` — the team goes, its proposal attachments stay
  behind under `itemid = groupid` for good. Now removed **after** the
  transaction commits, because file storage is not transactional and
  deleting them earlier would destroy the attachments of a team that a
  rollback then kept alive.
- `selfselectadvanced_reset_userdata()` — every team goes, every
  attachment stays.

HIGH-FILE-002 is the sharper one: `selfselectadvanced_pluginfile()`
admitted confirmed members and `:viewall` holders only, so **the guide
assigned to a team could not open that team's proposal** — the document
they exist to judge. Now allowed for the group's own `guideid`.

### Accepted — FC-006, uninstall did not remove the role

Confirmed. Core drops the plugin's tables, config and capabilities; it
does not drop a role created with `create_role()`, because a role is
site data. README claimed uninstall removed all plugin data.

`db/uninstall.php` now deletes the role when both hold: we created it
(recorded id), and **nobody is assigned to it**. A role people are
using represents a decision an administrator made, and an uninstall
must not revoke anybody's access as a side effect. What is kept is
named on screen rather than passed over.

### Accepted — FC-001 to FC-005, overstated readiness

All corrected. `README.md`: the WCAG claim is withdrawn (the interface
avoids colour-only meaning, but no formal audit was done, so no such
claim is made); "one thin transport module" corrected to four; the
uninstall claim corrected to what the code does.

`docs/audits/final-audit.md` is the 1.0.0 gate record and is kept as
written — a gate record edited afterwards is worth nothing. It now
carries a superseding note, and the two claims that were wrong are
marked wrong where they appear:

- **"GETs render-only" was false when written.** The tick is now a
  cross, with the reason.
- **"every checklist item green"** claimed more than the evidence
  supported. A green gate means the tests that exist passed; it says
  nothing about behaviour no test covers. Privacy export, backup
  coverage and file lifecycle were all defective and all untested.

The maturity level is unchanged, because the defects found were
repaired and are now covered by tests. But the reasoning that produced
that verdict was unsound, and saying so plainly matters more than the
level.

### Accepted — BLK-UPG-001, and the gate gap behind it

Confirmed and closed: version `2026073100`, final savepoint
`2026073100`.

The more useful finding is why the build did not catch it. moodle-cs
has a `savepoints` sniff, it ran on every gate, and it passed — because
it reads `db/upgrade.php` alone and cannot see `version.php`. The
invariant it could not check is now checked by
`/srv/ci/ops/savepoint-tip.sh`, wired into the gate. Its first run
failed on the unfixed tree, naming both numbers, which is the only
self-test a checker like that really gets.

### Found here — the gate was discarding the evidence of its own failures

Not in the external audit, and worth more than several things that
were. `ci-plugin.sh` piped PHPUnit and Behat through `tail -4`, so a
red run reported *that* one scenario failed and destroyed *which*.
Identifying a single failure meant re-running the whole suite by hand.

Full output is now written to `/srv/ci/{phpunit,behat}-<instance>.log`
and the failing feature files are named in the gate log itself. The
first run after the change identified the failure immediately: the
HIGH-SEC-001 repair had turned the department **Delete** control from a
link into a POST button, and `attributes_admin.feature` still clicked a
link. The test was right to fail and was updated to the new markup.

### Tests added

- `fit_test` — the seat waiting for a student is named; a misfit is
  reported with a reason rather than hidden; the picker's bulk verdict
  agrees with the gate's per-person verdict team by team; the picker's
  cost does not grow with the number of teams; a settled team reports
  its state and offers no seat.
- `coordinatorrole_collision_test` — a foreign role is not touched and
  gains no capability; our own role is created once and recorded; a
  role an administrator renames is still recognised as ours and is not
  duplicated.

## The second, independent audit (Fable) — reconciliation

A separate 50-agent audit was run over the same tree, blind to the
external document and to the repairs above, with an adversarial
verification pass on every finding. It raised **67**; **16 were refuted**
by that pass and **24 survived** it. Refuted findings are not listed
here as defects, which is the point of running the pass at all.

Its best work was in an area both the external audit and I had signed
off: **backup and restore**. Four confirmed defects, every one verified
against the source before being accepted.

### Accepted — proposal files were backed up and never restored

The sharpest finding of either audit, and the one I would not have
found by reading, because every visible part of it was right.

`backup_..._stepslib.php` annotates the proposal file area. The restore
step asks for the files with
`add_related_files('mod_selfselectadvanced', 'proposal', 'ssagroup')`.
Both correct. But the group mapping was recorded as

    $this->set_mapping('ssagroup', $oldid, $newid);

and core only links a backed-up file to its new item when the mapping
that created that item was recorded as **owning files** — the third
argument, `restorefiles = true`. Without it the join that moves files
out of the backup pool matches nothing.

So every proposal attachment was dropped on every restore. Silently:
a restore that finds no files to move reports success. The archive
contained them; nothing put them back.

Fixed by adding the flag. What made this possible is that **no test in
the suite had ever round-tripped a file** — the backup tests asserted
on rows. `backup_restore_files_test` now asserts on the restored file's
CONTENT, which is the only assertion that could have caught this.

### Accepted — three more of the same family

- **Intro files were never annotated.** Restore asked for them; backup
  never put them in the pool. A duplicated activity kept its
  description text and lost every image in it, leaving dead
  `@@PLUGINFILE@@` tokens.
- **`override.guidehidden` was not in the backup element list.** The
  field exists, the source query returned it, the restore would have
  stored it — it simply was not listed, so a guide an administrator had
  deliberately hidden from every picker became visible again after a
  restore. Now covered by a test.
- **Only `intro` was registered for link decoding.** Core's transformer
  encodes wwwroot links in *every* string it writes to the archive, so
  group briefs, message templates, ticket text, approach messages, join
  reasons and penalty waivers all came back showing literal
  `$@SELFSELECTADVANCEDVIEWBYID*123@$` to users. Decode rules are now
  registered for all of them, together with the `set_mapping()` calls
  each rule needs — a decode rule whose item name was never mapped
  decodes nothing and fails silently, so the mappings are the fix as
  much as the rules are.

### Accepted — `moves::cancel` could relabel a committed move

`cancel()` read the move with `status = 'pending'`, then wrote
`status = 'cancelled'` with `update_record()`, which matches on id
alone — and took no lock, while `commit_set()` does. A commit landing
between that read and that write was overwritten to 'cancelled' while
its membership changes stood and its `move_committed` event had already
fired: a committed move recorded as cancelled, with two contradictory
events in the log. Now serialised under the same `activity:` lock
`commit_set()` takes, with the row re-read inside it.

### Accepted — and it caught a gap in my own role repair

My CRIT-ROLE-001 fix stopped the plugin adopting a foreign role. It did
not stop `ensure()` — a *provisioning* step that writes capabilities —
from being called on ordinary page views, which `coordinators.php` and
the appoint/remove paths both did.

`assign_capability()` with overwrite off declines to change a setting
that **exists**. "Not set" in the interface is the ABSENCE of a
setting. So a capability an administrator had deliberately removed was
re-granted the next time anybody opened the coordinators page.

Runtime now calls `coordinatorrole::roleid()`, which resolves the
recorded id and writes nothing; `ensure()` is left to install and
upgrade, where provisioning belongs. Pinned by
`test_a_removed_capability_is_not_restored_at_runtime`.

## Accepted, and deliberately NOT done in this release

Stated here rather than quietly carried, because a repair release that
hides its own remainder is the thing this whole exercise is about.

- **MED-DB-001 — `ticket.groupid = 0` against a declared foreign key.**
  Real inconsistency, and the field comment already documents the
  sentinel. Both honest fixes (make it nullable, or drop the declared
  key) need a schema migration, and every practical consequence — the
  privacy export and the restore path — is already repaired. Adding a
  late schema change to a repair release is precisely the risk that
  produced the stale-database failures this round began with. It should
  be the first item of the next release, not the last of this one.
- **The `teacher` archetype on the coordinator role**, which means the
  role accretes capabilities that Moodle later adds to teacher defaults.
  A real design concern; changing an archetype on installed sites is not
  a repair-release change.
- **Remaining privacy breadth** — `get_users_in_context()` does not
  enumerate guides, successors, movers, override targets, inviters or
  snapshot takers, and several declared fields are still unexported.
  The confirmed defects that lost or leaked data are fixed; this is
  completeness work, and it needs its own tests rather than being
  tacked on here.

## Proof that the backup repairs are real

A test that passes both with and without a fix proves nothing, so each
of the two backup tests was run against a deliberately reverted tree
before being accepted. With `restorefiles` removed from the group
mapping and `guidehidden` removed from the override element:

    1) test_proposal_file_survives_restore
    The proposal attachment was dropped by the restore
    Failed asserting that actual size 0 matches expected size 1.

    2) test_hidden_guide_stays_hidden_after_restore
    A hidden guide became visible again after restore
    Failed asserting that null matches expected 1.

Zero files, not a corrupted one — every proposal attachment was being
discarded on every restore, and a restore that finds no files to move
reports success. The fixes were then restored and both tests pass.

That is the standard the rest of this response is meant to meet: the
defect demonstrated, the repair demonstrated, and a guard left behind
that fails if either regresses.

## Correction to this document — an incomplete accounting, and what it hid

The section above reported the Fable findings as applied, naming two
deliberate deferrals. That reads as though the rest were handled. It is
not what happened.

Fable raised 67 findings; 24 survived its adversarial pass. **Nine were
applied. Two were deferred with reasons. Thirteen were never examined
at all** — and the fact that the gate went green on the nine made the
remaining thirteen easy not to look at. A green gate is evidence about
the tests that exist. It is not evidence about findings nobody read.

That is the same reasoning error this response criticised in the 1.0.0
final audit, committed in the document that criticised it.

Going back through the unexamined thirteen turned up an authorisation
defect worse than most of what had already been repaired.

### Accepted — NEW-secgm-1, any guide could set any team's award

`review.php` gates its page with
`require_capability('mod/selfselectadvanced:guide', $context)` over the
**activity**, and takes the team from the `g` URL parameter. The
`approve` handler then gates properly on `can_approve()`, which checks
the assignment.

The `savenotes` and `saveaward` handlers immediately below it checked
nothing. So any holder of `:guide` could post to that page naming any
team in the activity and:

- overwrite another guide's private review notes, and
- **set or change that team's gradebook award.**

Every non-editing teacher holds `:guide`. So does the Group Coordinator
role — which **this plugin creates and grants `:guide` to itself**. The
plugin was therefore manufacturing its own grade-tampering path, and
handing it out with a role designed to help with freeze decisions and
the ticket queue.

`review.php` is the only place in the plugin where an award can be set
at all, which is why nothing else compensated for it.

Fixed by adding `gatekeeper::can_grade_team()`, alongside the
`can_approve()` it should have matched from the start: the assigned
guide, or a manager, and nobody else. A manager keeps access because
correcting an award is their job. The award and notes forms are no
longer rendered to somebody who would be refused on submit — the notes
themselves stay readable, because a guide taking a team over needs to
read what the last one wrote.

Proven by negative control, as with the backup repairs. With the check
removed:

    test_another_guide_may_not_grade_someone_elses_team
    A guide who is not assigned must be refused

    test_a_coordinator_may_not_grade_a_team_they_do_not_guide
    A coordinator who does not guide this team must be refused

    test_an_unguided_team_still_refuses_a_passing_guide
    An unassigned team must not be writable by any passing guide

Three failures; the two positive cases still pass, as they must. The
coordinator case is in there deliberately, so that any future change
granting `:guide` to a role cannot silently re-open this.

Note also what `can_grade_team()` does NOT check: lifecycle state.
Notes are kept while a review is in progress and an award is routinely
corrected after approval, so importing `can_approve()`'s state rule
wholesale would have removed working authority to close a different
hole — the failure mode this project has already hit three times.

## The other twelve, verified and repaired

Having found one authorisation defect in the unexamined pile, the rest
were put through the same line-by-line verification rather than left.
**Nine more were confirmed against source. None were already fixed;
none turned out to be wrong.** All nine are now repaired.

### Data integrity

**`grant_guidecap` wrote the override before validating the note.**
`store::save()` opens and commits its own outermost transaction, and
only afterwards did `close()` reject an empty resolution. So a
coordinator who pressed Grant with a blank note raised the guide's
capacity **permanently**, while the ticket stayed CLAIMED and the guide
was never told. The function's own docblock promises that "the two
halves belong to one another"; the implementation had split them.

Everything `close()` would refuse for is now checked before the
override is written. They are deliberately NOT wrapped in one
transaction: `close()` notifies after committing and outside its lock,
and an outer transaction here would drag that notification back inside
one — the 1.15.0 mistake. A concurrency window remains, and is
documented in the code rather than papered over: it is now a reversible
over-grant under contention instead of a silent, deterministic one.

### Privacy — declaration

Eight columns the plugin actively writes were undeclared:
`member.leaverequested`, `group.returncomment`, `group.guidenotes`,
`group.usermodified`, `userattr.seatlocation`, `userattr.program`,
`override.usermodified` and `agrun.triggeredby`. `seatlocation` is a
physical location. `guidenotes` is free prose that staff write **about
students**, described in the interface as private to staff — which is a
reason to handle it carefully, not a reason to keep it off the register
of what the plugin holds.

### Privacy — disclosure

Fields that were declared and never exported: `group.brief` (SELECTed
and then dropped on the floor between the query and the payload),
`snapshot.roster`, `snapshot.takenby` and `digestq.payload`. Snapshots
were not exported by any path at all, despite being the authoritative
record of who was in a team when it was frozen — so a person asking
what was held about them was told nothing about the record that settles
it. All are exported now, the snapshot filtered to that person's own
entry.

### Privacy — erasure, which was the worst of them

`get_users_in_context()` omitted **six roles** that
`get_contexts_for_userid()` covered: guides, successors, the subjects
of staged moves, override targets, inviters and snapshot takers.

The two APIs disagreeing is worse than it sounds. A user the userlist
omits is never handed to `delete_data_for_users()`, so an administrator
deleting **everybody in a context** silently skipped them — while the
same person's own subject-access request would have found the context
without difficulty. A broken erasure beats a broken disclosure, and
this was a broken erasure that looked like nothing at all.

Also repaired: `member.invitedby` kept an erased user's id on every
invitation they had sent (now de-linked, since the invitation is course
history worth keeping and their id is not); `override.usermodified` and
`group.usermodified` kept the acting staff member's id forever,
including on the group- and move-scope override rows that are never
deleted; and `delete_data_for_all_users_in_context()` — the one
deletion path meant to be unconditional — **never mentioned the agrun
table**, so a purged context kept a full auto-grouping log with raw
user ids in its JSON and the triggering manager's id beside it.

### Interface

A pending invitee was shown the team's proposal as a live link that the
file server then refused, because the page admits invited members and
`selfselectadvanced_pluginfile()` admits only confirmed ones. The
filename now appears without a link and with "Available once you have
joined the team". The filename stays visible because it was already on
their screen and hiding it would say less than the page said before;
what changes is that it is no longer a link that cannot work.

### Tests

`privacybreadth_test` adds what was missing entirely — there were no
metadata or userlist tests at all, which is how a declaration could
drift from the schema unnoticed:

- every declared field must exist as a real column AND resolve to a
  real language string (138 assertions, both directions of drift);
- a person whose only footprint is guiding a team is listed for the
  context, and therefore reachable by bulk deletion;
- an inviter is listed, and after deletion their id is gone from the
  invitation they sent;
- purging a context leaves no auto-grouping log behind.

### Standing count

Of Fable's 24 surviving findings: **21 repaired**, 2 deferred with
reasons (the `teacher` archetype, and `ticket.groupid`'s sentinel
against its declared foreign key), and 1 was a negative finding — an
authorisation sweep that found nothing missing, which is worth
recording precisely because it is the only part of that sweep that came
back clean.

## A missed instruction, not just a finding — the request queue

The external audit filed MED-PERF-001 as a performance note: the ticket
queue "loads all tickets and all groups for an activity, then renders
one custom table". Checking it against the maintainer's own 1.18.0
instruction changes what it is.

That instruction was: guide load tables become **Moodle native with
pagination, downloadable reports, filters**, and *"we are on a really
ambitious large group project. Hence the plugin should be robust and up
to the task."* Twenty-one pages in this plugin now use proper paged
`table_sql` classes. `tickets.php` was still building an `html_table`,
and it was missed rather than exempted.

Two unbounded queries sat behind it:

- `tickets::queue()` returned **every ticket the activity had ever
  seen**. Resolved and declined tickets are never removed, so the queue
  grows all semester — a page that gets slower every week.
- The page then resolved team names by loading **every group in the
  activity**: fifteen hundred rows to label one screenful of tickets.

Both are gone. The queue takes `$limitfrom`/`$limitnum` and the page
uses the same `perpage` control and `paging_bar` as every other table
here; the team's name arrives with its ticket through a LEFT JOIN — left,
because a team-limit request carries `groupid = 0` and is about no team
at all, which is the same lesson the privacy export had to learn a few
sections ago. `coordinator.php` was fetching the whole queue to count
the open ones, and now counts them.

What is NOT claimed: this is a bounded, paged `html_table`, not a
`table_sql` subclass, so it has no column sorting and no download. The
rows carry POST forms driving claim, grant, decline and release, and
rebuilding those inside a `table_sql` is a change to a workflow Behat
covers end to end — worth doing deliberately, not as the last act of a
repair release. The scale defect is fixed; the table is not yet the
native one the instruction asked for, and saying so is the point.

The paging test pins the part that breaks quietly: the position numbers
shown to people waiting in the queue must still be right on page two,
and must not run past the number of tickets actually open.

## MED-UPG-002 — and a checker that nearly shipped broken

The external audit warned that `db/upgrade.php` calls plugin-owned code,
against Moodle's guidance: inside an upgrade step the PHP is already the
NEW code while the schema is still at whatever savepoint that step
begins from, so a class that queries a plugin table behaves differently
depending on where the site is upgrading FROM. The paths that break are
the old ones — which is precisely what nobody tests, because the
current-version upgrade keeps passing.

Checked directly: `coordinatorrole` makes **zero** plugin-table
references. It touches the core `role` table, plugin config and the
capability API, all schema-independent. So the warning does not bite
here in fact — but it held only by luck, and this run had already made
`ensure()` do more, not less.

The invariant is now written on the class and enforced by
`/srv/ci/ops/upgrade-safety.sh`, wired into the gate beside
`savepoint-tip`.

### The part worth recording

The first version of that checker **passed on a tree with a deliberate
violation injected into it**. Shell escaping through a nested heredoc
had destroyed its extraction regex, so it matched nothing, checked
nothing, and printed `upgrade-safety-ok` — a green light meaning only
that it had failed to look. It would have shipped as a permanent pass.

It was caught by running it against a deliberately broken tree, the same
negative control used on the backup repairs and the authorisation fix.
A check nobody has watched fail is not evidence.

The second version then produced a **false positive**: it flagged
`state.php`, whose only appearance in `db/upgrade.php` is the constant
`state::FROZEN`. Reading a constant executes none of the class's code
and is perfectly schema-independent. That matters as much as the first
error — a check that cries wolf gets switched off, which ends in the
same place as a check that never fires.

The version in the gate matches method INVOCATIONS only, and is verified
in both directions: it passes the clean tree while reporting how many
classes it actually examined (so a silent zero-check pass is visible),
and it fails a tree with the violation injected.

Both mistakes are the same mistake this whole response has been about:
a green result that means "nothing was examined" is worth less than a
red one, and the only way to tell them apart is to watch the thing fail
on purpose.

## MED-MSG-001 — notification failures are no longer silent

`notifier::send()` ended with `message_send($message);`, return value
discarded.

This is not hypothetical: earlier in this very run, a new message
provider was added to `db/messages.php` after the version bump, no
upgrade re-read it, and **every notification the plugin sent was
refused** — through a completely green test run, because nothing looked
at what `message_send()` returned. It surfaced three steps later as an
unrelated-looking symptom.

The result is now checked, and a refusal raises a developer-level
debugging message naming the provider and the recipient. A `false` here
means the messaging subsystem refused the message outright — an
unregistered provider, a malformed message — not a user who has turned
that notification off, which `message_send()` handles internally and
still reports as sent. There is no such thing as a routine `false`, so
being loud about it costs nothing and would have saved this run several
hours.

## Rejected — MED-PERF-002, "tasks iterate broad datasets without batching"

Checked against the source, and it does not hold.

`reconcile_penalties.php:47` and `run_autogrouping.php:47` both iterate
`{selfselectadvanced}` — the **activity** table, selecting the `id`
column alone. That is one row per activity instance on the site: tens,
perhaps low hundreds, of single-column rows. It is not a broad dataset,
and no amount of student or team growth makes it one. The finding
appears to have read the table name as a data table.

`send_digests.php` is the one that scales with people, and it is built
for it. It takes the distinct user ids in the digest queue, then fetches
**only that user's rows** per iteration, and **deletes them once sent**
(`:84`). Per-iteration memory is bounded to one person's queued items,
and because processed rows are removed as it goes, a run that is cut
short leaves the remainder in place for the next cron to pick up. It is
resumable by construction rather than by a checkpoint bolted on.

The fair residual: there is no explicit time-limit check, so a very
large backlog could make one cron run long. That is a property of the
work, not a defect in it, and the idempotence above is what makes it
safe.

Recorded because an audit response that only ever agrees is not a
response. Of the external audit's findings this one is simply wrong, and
saying so plainly is the same duty as accepting the ones that were
right — several of which were sharper than anything found here.
