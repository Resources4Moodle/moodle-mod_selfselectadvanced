# Strategy 1.16.0: student-approach mode, ticket queue, name format, Group Coordinators (2026-07-29)

Four maintainer requirements, each analysed against the shipped 1.15.3
code before any change. Assumptions that shape the build are marked
**ASSUMPTION** and are safe to correct before or after the build — each
is isolated behind one setting or one gate.

## A. The student-approach switch

**Requirement.** A switch that prevents potential guides from
volunteering their availability; students must approach a guide. Once a
guide approves, students cannot reject the guide in this workflow.

**What exists.** `guidevolunteer` lets guides declare their own
capacity; `eoienabled` + pick-that-team lets teams list themselves and
guides come to them; `guidemode` 0 means the leader submits to a guide
of their choice. The submit form's guide chooser shows each guide's
load ("Guiding 2 of 3").

**Design.** One new activity setting, `studentapproach` (default 0).
When ON:

1. Guide capacity volunteering is refused server-side
   (`volunteering::set` throws `refusalstudentapproach`) and the
   dashboard block disappears. Capacity comes from the activity's
   `maxguided` and per-guide overrides only.
2. Team listing and guide interest are refused server-side
   (`eoi::express` and the listing toggle throw the same refusal), and
   the settings validator forces `eoienabled=0` and `guidemode=0`
   (leader selects) so the forms cannot describe a contradictory
   activity.
3. **The guide chooser hides load figures.** "Guiding 2 of 3" IS
   advertised availability; in this mode the leader sees names only.
   Capacity is still enforced at submission — a full guide refuses
   with the existing reason — but nothing is advertised beforehand.
   (This subtlety is the reason a mere `eoienabled=0` is not the
   feature.)
4. **Binding approval.** Audit of every path that detaches a guide
   from an approved team: return (guide acts), handover
   (guide-to-guide), manager reassign (staff), unfreeze (staff). There
   is NO student-driven detach path in any mode today — the
   requirement is structurally satisfied — so it is PINNED, not built:
   a truth-table row and a unit test assert that a leader attempting
   any guide-changing action on a firm team is refused. After a guide
   RETURNS a team it is forming again and may approach a different
   guide; that is the guide's act, not a student rejection.

**Improvement (included).** When the switch is on, the activity page
tells students plainly: "Guides do not advertise availability here.
Choose a guide and submit; the guide decides." Sets expectations and
reduces mail to coordinators.

## B. The ticket queue

**Requirement.** Composition-change requests by guides and unfreeze
requests are listed sequentially; when one manager takes a request up,
another cannot take it in parallel; race conditions handled.

**Design.** New table `selfselectadvanced_ticket`:
`id, activityid, groupid, type ('compchange'|'unfreeze'), status
('open'|'claimed'|'resolved'|'declined'|'withdrawn'), requestedby,
request + requestformat, claimedby, timeclaimed, resolvedby,
timeresolved, resolution + resolutionformat, timecreated,
timemodified`.

- **Filing.** The assigned guide of a firm/frozen team files a
  composition-change request from the team page; the guide or leader
  of a FROZEN team files an unfreeze request. Free-text reason is
  required. One OPEN ticket per (group, type) — a duplicate is
  refused with a pointer to the existing one.
- **The queue.** tickets.php lists open tickets first-come first-served
  (timecreated, id), then claimed, then closed; visible to holders of
  `manage` or the new `coordinate` capability.
- **Exclusive claim.** Claim runs under `locks::acquire('ticket:'.id)`
  in a delegated transaction, re-reads the row, and only an OPEN
  ticket becomes claimed; the UPDATE also carries `WHERE status='open'`
  and its affected-row count is checked (belt and braces). The loser
  of a race gets `refusalticketclaimed` naming the claimant. Only the
  claimant may resolve, decline, or release the ticket back to open;
  a `manage` holder may force-release (claimant left, ticket stuck).
- **Resolution.** Resolving does not itself mutate the team — the
  claimant performs the actual move/unfreeze with the existing,
  already-locked tools, then closes the ticket with a resolution note.
  A direct manager unfreeze auto-resolves any open unfreeze ticket for
  that group, so the queue never shows stale work.
- **Notifications.** New provider `tickets`: requester notified on
  claim and on close; manage/coordinate holders notified on filing.
- **Races pinned by test.** Two sequential claims (second refused);
  claim-vs-resolve interleavings; the lock serialises true concurrency
  the same way every group mutation already does (lock rule A7).

## C. Project-name format

**Requirement.** The name format is given by the editing teacher
setting up the task; "if any pattern exists within the course, it
should be rejected."

**ASSUMPTION.** Read as two rules: (1) names must match the teacher's
format; (2) a name already used ANYWHERE in the course — any
self-selection activity, not just this one — is rejected, making
project names unique course-wide. If instead "pattern" meant
"reject names that merely RESEMBLE existing ones", say so and the
comparison swaps from equality to pattern match; the seam is one
function.

**Design.** New setting `nameformat` (PCRE fragment, empty = no
constraint), validated on save (must compile), applied anchored and
case-sensitively at group create/rename with refusal
`refusalnameformat` showing the required format and an example the
teacher supplies in `nameformatexample`. `groups::name_taken()` widens
from activity scope to course scope (join through the activity table).
Default empty format + the fact that current courses hold one activity
means zero behaviour change until a teacher opts in.

## D. Group Coordinators

**Requirement.** A role for non-editing teachers who handle freeze and
unfreeze, can serve as guides, and cannot modify activities where they
are involved — for accountability.

**Design.**

- New capability `mod/selfselectadvanced:coordinate` (module context):
  claim/work tickets, freeze a firm team on the guide's behalf,
  unfreeze. It is NOT `manage` — no settings, no overrides, no moves.
- The role itself: `groupcoordinator` ("Group Coordinator"), created
  by db/install.php and the upgrade step (plugins may create roles in
  code, not in access.php), archetype teacher, assignable at course
  and module context, granting `coordinate` + `guide` + `respond`
  view-level needs.
- **Conflict-of-interest guard.** An actor whose authority comes from
  `coordinate` (and who does not hold `manage`) is refused
  freeze/unfreeze/claim on any group where they are the assigned
  guide, the nominated successor guide, or a confirmed member —
  refusal `refusalcoiinvolved` naming the involvement. `manage`
  holders stay exempt: editing teachers already hold full power and
  narrowing them would break existing workflows. **Improvement
  (offered, not built):** a site setting extending the guard to
  `manage` as well, for institutions that want no exemptions.

## Test plan (explicit, in build order)

1. **Unit** — settings validator (studentapproach forces eoienabled=0,
   guidemode=0; nameformat must compile); volunteering refusal; EOI
   refusal; chooser hides loads; binding-approval pins; ticket
   lifecycle incl. double-claim refusal, duplicate-open refusal,
   force-release, auto-resolve on direct unfreeze; name format +
   course-wide duplicate; role created by upgrade; coordinator can
   freeze/unfreeze a stranger team; COI refusals (guide / member /
   successor); manager exemption.
2. **Behat** — new: settings fields; ticket queue claim flow;
   coordinator assigned via "role assigns" generator unfreezes a team;
   adversarial: student opens tickets page (nopermissions), coordinator
   unfreezes their own guided team (refusal text), guide sees no
   volunteering block when the switch is on, leader sees no load
   figures in the chooser. At-risk existing scenarios: everything
   keyed on volunteering/EOI/chooser text — the switch defaults OFF so
   they stand; course-wide name uniqueness — audited for any feature
   using one course with two activities sharing group names (none
   found; re-checked in the build).
3. **Scale (SCALE10KC — a third namespace; SCALE10K and SCALE10KB are
   preserved)** — probes: ticket file + claim + racing second claim
   (tripwire: must refuse) + resolve; nameformat cost on create_group;
   coordinator unfreeze; studentapproach flip + a submission with
   hidden loads. All previous probes rerun unchanged.
4. **Ship** — strict phpcs/phpdoc, full `ci-run --reinit` (new table,
   settings, capability, role) fail=0 on both DBs, deploy thinkinghat,
   GitHub push + matrix + tag v1.16.0, deck gains a "How a team gets
   its guide when guides do not volunteer" section and the ticket
   workflow, credentials removed after tagging.
