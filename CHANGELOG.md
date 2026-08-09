# Changelog

## 1.20.31 — a type name can no longer veto a feature (2026-08-09)

> Serial `2026080904` / `1.20.31`. One column precision change; no data change.

The ticket `type` column was `char(12)`. Every shipped type fits only because the
longest of them happen to be short, and the review of decision 71 found that its
proposed type — `leaderchange` — is **exactly twelve characters**. It would have
fitted with no headroom whatsoever, so the type after it, or any rename, would
have hit a schema change discovered mid-feature on a live table.

Widened to `char(128)` at the maintainer's instruction, which also leaves room
for non-ASCII should a fork want it. The upgrade step changes the precision of
the existing column, so a site that upgrades gets the same width as a fresh
install — a distinction that matters, because `db/install.xml` alone would have
fixed only new sites.

`tests/ticket_type_width_test.php` proves the width where it actually matters:
a real INSERT of a 120-character type into the running table, read back and
compared. Reading the XML would only have restated the intention.

## 1.20.30 — the privacy contracts (2026-08-09)

> Serial `2026080903` / `1.20.30`. No schema change. Maintainer decisions 84,
> 85, 86, 87, 88, 90, 91, 92 and 93.

**Guide notes leave the automatic student export (decision 84).** They were
included on the argument that a data subject is entitled to see what is held on
them — a real principle, and the wrong one here. The field hangs off the
**group**, not off a person, so the software cannot tell which sentence is about
the requester and which is about a teammate; exporting it handed one student the
guide's evaluative prose about everybody else, plus staff deliberation, in a
single block. Filtering to confirmed members was considered and rejected for
exactly that reason. The interface's promise to the guide — *"students never see
them"* — is true again. The metadata declaration **stays**, because the plugin
does store the notes and saying so is what metadata is for; deleting it to match
the export would be the same dishonesty pointing the other way.

**A CSV can no longer set mobile-sharing consent (decision 85).** An upload
could revoke a consent the student had been told was their own, silently, with
nothing recording who did it or on what basis. Grant-only was considered and
rejected: it removes the destructive direction but still leaves one flag with
two owners, and a single boolean cannot then answer who granted it, when, on
what basis, or whether a later student choice overrides an import. The column is
still *accepted*, so older files import cleanly — it simply has no effect, and
`csvformathelp` says so.

**Every student now sees a privacy statement (decisions 91, 92, 93).** The panel
used to render only for someone who happened to have a number on record, so the
better-protected student was told *less* than the exposed one and the silence
was indistinguishable from a broken feature. It is now unconditional and worded
from the **current** setting rather than promising anything permanent — an
editing teacher can switch contact privacy off, and the cardinal rule requires
the student to be informed, not promised. It carries the email guarantee, which
was the strongest protection in the feature and had no presence on any screen,
and it says where the number itself is corrected: the institution owns the
value, the student owns the disclosure.

**Four local corrections.** The quota value picker now names the dependency
instead of failing with a bare "Required", and shows the route to fix it only to
someone who can take it. One join refusal became two, because *awaiting guide*
and *approved* are different situations and the single message told students to
ask their leader for a release no leader can request. A guide's seat location
renders beside them on the page of the group they guide — narrowly, not
searchably — which `lang:74` and the privacy metadata have promised for
releases while git shows the group-page half was never built. And two silent
list caps, ten declined invitations and twenty answered interests, now disclose
what they dropped, matching the landing page's existing practice.

## 1.20.29 — one answer to "why is this button not here?" (2026-08-09)

> Serial `2026080902` / `1.20.29`. No schema change. Maintainer decision 83,
> built as a convention rather than nine fixes.

Two different questions were being answered with the same blank space. *Is this
function for this person at all?* is a **capability** question, and a no means
the control is not drawn — explaining it would describe the permission model to
somebody outside it. *Why can't I do something that normally belongs to me?* is
a **state, rule or timing** question, and a no now means the control is drawn,
disabled, carrying the sentence the gatekeeper already wrote.

Nine surfaces used to decide that for themselves. That is how the plugin came to
contradict itself: a guide refused because staff had enforced a freeze saw a
disabled Release plus *"Frozen by staff — ask through the request queue"* on
their dashboard, and **nothing at all** on the team's own page. Same guide, same
team, two answers. The nine are the unfreeze/release control, the leave request,
the incoming join queue, return-to-forming, the guide-interest response, the
invitation response on both the group page and the landing page, and the
leadership succession tab.

- **Conflict-of-interest refusals are disabled but do not name the
  relationship.** `refusalcoiinvolved` reads *"you cannot act because you are the
  assigned guide of it"*, which discloses something the reader may not be
  entitled to know. Those refusals now show a generic sentence naming who *can*
  act, so recoverability survives without the disclosure. `refusalcoiself` is
  deliberately not shielded: it tells the actor only what they already know
  about themselves.
- **A pending invitation is a fact, not a control.** The prompt now reaches an
  invitee whose `:respond` is prohibited; only the buttons go. Previously both
  were ANDed into one flag, so the page said nothing at all.
- **A solo leader keeps the Leadership succession tab** and is told there is
  nobody to nominate yet, rather than watching the whole tab disappear.
- **A leader who cannot answer the join queue is told it exists** and why they
  cannot, instead of seeing a page with no queue on it. The requests themselves
  stay hidden — a refused decider may not read other students' reasons.

**Two new build checks hold the line.** `control-state.sh` fails on a refusal
collapsed to a boolean at a render site, with an allowlist that requires a
written reason per entry and fails if an entry goes stale. It found two defects
the audit had missed, including one introduced the previous day. It also states
plainly what it cannot see: six of the nine never asked for a refusal at all,
and no static rule distinguishes that from a legitimately simple condition —
those are held by `control_state_test.php`.

**And one thing that had been shipping since 1.20.26.** Two `patch --forward`
artefacts — `classes/local/rules/gatekeeper.php.orig` (51 KB, carrying
pre-capability-split logic) and `lang/en/selfselectadvanced.php.orig` — were
committed by an over-broad `git add -A` and installed on every site. They never
executed, because Moodle's classloader only maps `*.php`, and that is exactly
why nothing caught them: every check in the build filters by extension, so the
one thing none of them could see was a file with the wrong extension.
`no-stray-artefacts.sh` now reads git's index — what actually becomes a release
— rather than the working tree.

## 1.20.28 — the plugin stops telling people things that are not so (2026-08-09)

> Serial `2026080901` / `1.20.28`. No schema change. Thirteen corrections from
> a silent-state audit that verified 25 defects and refuted 6.

The audit asked one question of every screen: *does this page state, promise or
collect anything the code does not honour?* Thirteen answers carried no design
content and are fixed here. The remaining twelve are design forks and are
waiting on a maintainer ruling, not on work.

- **Two screens discarded what they collected or miscounted what they did.**
  The participant attribute editor has offered Seat location and Type of
  programme since 1.3.0 and passed neither to the writer, so both were typed
  in, dropped, and "Participant attributes saved." was shown anyway — which is
  why a site that never imports a CSV has no data for the programme quota
  dimension. And "Message all defaulters" reported the number *listed* as the
  number queued, so on an activity with no penalty-free deadline the manager
  was told reminders had been sent when nothing had been queued at all; the
  page now reports what was queued and says how many were left out and why.
- **Eight sentences said something the code contradicts.** Approval was called
  "irreversible" after decision 62 gave coordinators a return-to-forming that
  clears the approval date. The freeze notice said only a manager can unfreeze,
  when the guide who froze the group can release it themselves. Auto-approval
  was described as ignoring drift "literally and unconditionally" while three
  gates hard-refuse it. The `:coordinate` capability description promised an
  unfreeze that capability does not carry on its own. The CSV format help
  presented a closed column list that omitted `shareconsent` — the column that
  overwrites a participant's own sharing choice — along with `seatlocation` and
  `program`. The defaulter penalty was promised per missing membership without
  saying it cannot reach a student who joined nothing at all. The returned-group
  notification was labelled "(to the members)" and goes only to the leader. And
  `contactprivacy_help` told teachers they see contact details for every
  participant when, with the setting on, no email address reaches anybody.
- **Three states rendered as blank space and now speak.** A roster filter
  matching nobody used to remove the filter box along with the table, leaving a
  heading over nothing and no way to clear the term; a member who had asked to
  leave lost the button and was told nothing while the leader got a whole
  panel; and the guide contact list silently omits guides who are full, on a
  rule whose explaining string had lost its only echo in `7d35a40` while a code
  comment still asserted "the rule is stated in the intro".

**Two maintainer rulings landed with this release.** *Decision 89:* a return is a
lifecycle event of the whole group, so every confirmed member is now notified, not
the leader alone — members left working on something that had already been sent
back was the cost of the old behaviour. The guide's comment stays with the leader;
members get a neutral, group-focused message naming the return and the
coordination, so the fan-out does not turn a notification into a vehicle for
feedback that belongs elsewhere. *Decision 94:* when Submit is blocked by both a
composition shortfall and unanswered invitations, the invitations message wins —
"wait for a reply" is the instruction a leader can act on, and an outstanding
invitation may itself fill the quota gap, so quota-first would send them recruiting
somebody they do not need. No code changed for that one; the behaviour was already
correct and the Behat expectation was not.

**Guarding the class, not just the instances.** `tests/claim_honesty_test.php`
pins every correction, and two of its checks are structural rather than
specific: one derives the canonical attribute list from `manager::set()`'s own
write loop and fails if *any* field the form collects never reaches the writer,
so the next field added cannot be dropped the same way; the other requires the
roster filter form to sit outside the post-filter gate. All seven mutations in
the sweep were caught, including one that initially was not — the first version
of the CSV assertion searched the whole help string, which a neighbouring
sentence could satisfy, and was tightened to the column list itself.

## 1.20.27 — two checks the plugin did not have (2026-08-08)

> Serial `2026080806` / `1.20.27`. Tests only; no behaviour, language or
> schema change.

- **Contact privacy is now checked by value, not by absence of a column.**
  The cardinal rule — a student's email and phone hidden from guides and
  peers except where a connection exists — was implemented and partly tested,
  but nothing walked the whole matrix asserting what a person actually
  receives. A column can be hidden while the value leaks through a data
  attribute, a CSV cell, a web-service response or a message body. The new
  matrix seeds a distinctive address and number and asserts their absence
  from the entire payload for every viewer who must not see them, across both
  settings and every surface it can drive.
- **A public guard that nothing calls now fails the build.** One did: a method
  whose docblock promised stale-POST protection had zero callers anywhere, so
  it had never run and its error string was unreachable — and two independent
  audits read it without asking who called it. It was removed in 1.20.24; this
  stops the next one. The allowlist is empty, deliberately.

**One thing the matrix surfaced, recorded rather than changed:** the email
rule in the code is *stricter* than the project's own summary of it. The
summary says editing teachers and managers are trusted with contact details;
`contactprivacy.php` states that no surface renders, links or exports an email
address to anybody, `:manage` included. Phone follows the trusted-viewer rule;
email does not. The matrix follows the implementation. The `db/install.xml`
comment still describes the older shape and was left alone as out of scope.

## 1.20.26 — creating a group and leading one are separate permissions (2026-08-08)

> Serial `2026080805` / `1.20.26`. Capability and authority split; no schema
> change.

- **`:creategroup` now means only "Create a new group".** The old capability
  had also been the authority for every action an existing leader performs, so
  prohibiting new group creation stranded leaders who already had groups. The
  existing capability name is retained so installed role customisations keep
  their identity.
- **New `mod/selfselectadvanced:lead` owns existing-group leadership.** Fresh
  installs grant it to the student archetype; upgrades clone every role's
  recorded `:creategroup` ALLOW, PREVENT or PROHIBIT so the split changes no
  site's policy by itself. An administrator can then prohibit `:creategroup`
  alone to pause new groups without taking existing leaders' controls away.
- **Leadership appointments refuse an inert nominee.** Nomination preflight,
  succession confirmation, staff-created groups, staged leadership moves and
  auto-grouping all require the person being installed to hold `:lead`. The
  nominee picker consumes the same decision, so it shows the refusal before a
  live nomination can be submitted; staged moves re-check at commit, and
  auto-grouping leaves a planned group unplaced when none of its members may
  lead.
- **Creation remains creation.** Student self-creation still checks
  `:creategroup` in both the page and service; the candidate picker and guide
  contact/search paths that serve an existing leader now use `:lead`. No new
  pause setting was added: the capability split and the existing formation
  window already express the two different controls.

### Also in this release — five maintainer rulings

- **A capability revoked mid-session reads as a notice** (decision 72). All 27
  of the group page's service-calling arms now answer core's permission
  exception the same way they answer a workflow refusal, routed through one
  helper so no arm can print core's "Sorry, but you do not currently have
  permissions…" into a group page as though something had broken. The
  services still throw it, so web services, cron and CLI stay loud, where a
  missing capability really is a fault rather than a race. The
  `workflow_refusal` docblock, which had claimed the opposite since 1.20.21,
  now says what the code does.
- **"Manager assigns the guide" means it** (decision 75). That setting and
  expressions of interest could both be on, and the leader quietly won: a
  guide offered, the leader accepted, and the group went straight to that
  guide instead of the manager's queue. The pair is refused at the settings
  form, greyed out in the form, and refused server-side for activities
  already carrying both.
- **A machine may not rewrite a group's rules on a verdict it cannot prove**
  (decision 79). The seat solver falls back to a heuristic past its size
  envelope, and that heuristic can only under-report. The auto-approval sweep
  used to answer such a shortfall by writing a permanent quota exemption —
  authored by cron, possibly excusing something never true, and afterwards
  indistinguishable from an exception a human granted. It now refuses that
  group and leaves it for a person, which is recoverable.
- **Over-maximum is refused before the effort is spent** (decision 80). A
  group formed at five under an old limit could be submitted, occupy a
  guide's review, be approved, and meet its first objection only at Freeze.
  Submit, Approve and the sweep now all refuse it with the same sentence and
  figures Freeze has always used. Approve and the sweep share one body, so
  they cannot drift.
- **Both join routes give one composition answer** (decision 82). Invitation
  acceptance computed the shared verdict and honoured only its maximum, then
  re-asked a weaker question over a basis that counted *other people's*
  unanswered invitations. It now consumes the same engine tier join
  acceptance always has. Getting there exposed why the drift existed: the
  verdict carried `hardmaxa` beside `hardmaxkey` but nothing beside
  `enginekey`, so a caller could read that tier's sentence and not rebuild
  its refusal. The object is symmetric now.


## 1.20.25 — statuses that say what they mean, and two refusals that stop crashing (2026-08-08)

> Serial `2026080804` / `1.20.25`. Behavioural and presentational; no schema
> change.

- **`Firm` is now `Approved` and `Frozen` is now `Locked`** (decision 76).
  These were the state machine's words, and they are good words for a state
  machine — but a student meets them once, in a badge, with no glossary, and
  neither is guessable. `Forming` and `Awaiting guide` already say what they
  are and keep their names. Stored values, keys and the state machine itself
  are untouched, exactly as with the group vocabulary. The analytics export
  moves from the raw stored value to the label in the same change, so no
  spreadsheet contradicts a screen.
- **A lost lock is no longer a crash.** Two people acting on one group inside
  ten seconds means one of them loses the wait, and that refusal's own
  sentence has always read like a notice — "Another change to this group is
  in progress. Please try again." It was delivered on the fatal error page
  regardless, because the 1.20.22 migration sorted refusals by how their key
  was *spelled* and this one begins `err`. It now travels typed, so the fifty
  or so controller arms already written for this answer it properly. The test
  doubles that inject the timeout were retyped **first**, because they
  asserted only the base class and would have stayed green either way.
- **A join request outliving its student is no longer a crash either.** The
  waiting list is built from open requests and never asked whether the
  requester is still a participant, so a request survives a withdrawal, a
  suspension or an enrolment running out. The leader saw the name, saw a live
  Accept, pressed it, and got the move engine's own participant error — the
  fatal page, for doing the one thing the page offered him. He now reads that
  the student is no longer taking part, and the request stays open so he can
  decline it with a note.

## 1.20.24 — punch-list P1, P2, P3: one vocabulary, sweeps that survive a bad row, two debts paid (2026-08-08)

> Serial `2026080803` / `1.20.24`. Behavioural and presentational; no schema
> change. Punch-list items P1–P3 from `audit_state/PUNCH-LIST-20260808.md`.

### P1 — the plugin speaks one vocabulary (decision 69)

The plugin is *Group self-selection (Advanced)*, so its entity is a **group**;
Moodle core's own row is a **course group**; "team" is gone. Before this the
language file was split roughly 58/42 between the two words for the same
object, which is how a reader ended up being told about "teams" on a page
called Groups.

- **302 string values rewritten**, out of 1,506. Every one keeps its `{$a}`
  placeholders and literal brace tokens, its literal-`\n` versus real-newline
  encoding, and its quoting — checked mechanically before and after, not by
  eye. **No string key, database value, class, constant, capability or event
  name was renamed:** `pickteam`, `viewteam`, `jointeam` and
  `:viewassignedteams` are stable identifiers, and renaming them would be a
  capability and database migration for no reader's benefit.
- Where a sentence names both, they are now told apart — the plugin's group,
  and the **course group** it mirrors into Moodle on freeze.
- `mobilecaution` was reworded rather than swapped: a literal swap would have
  produced "group coordination" in the same breath as "group chats" and the
  **Group Coordinator** role name.
- Behat text repaired in step (62 assertion literals across 17 feature
  files). Fixture group names such as "Team Blue" are data and are untouched.
- **A glossary check now guards it in the gate**: a banned term in any
  user-facing string value, or in any Behat assertion literal that is not
  fixture data the scenario itself created, fails the build. Proven against
  three deliberately broken trees, including one where the check's own
  fixture-matching was too strict — that was fixed in the check, not by
  loosening the tree.

### P2 — a sweep survives one bad row

Seven scheduled sweeps abandoned every remaining activity when a single one
threw: a row deleted between the batch query and the lock that re-reads it, a
lock timing out under a concurrent settings save, or one unreachable message
recipient. Each now contains the failure per item, records the class and
message where an operator can see it, and carries on.

- `expire_invitations`, `expire_eoi`, `reconcile_penalties`,
  `run_autogrouping`, `send_nudges`, `guide_autoapprove`, and the decision-61
  enrolment observer, which was forfeiting the quota exemption for every
  later settled group.
- `send_nudges` is adhoc: when the activity it names has been deleted it now
  logs and returns instead of throwing, which had it re-queued and failing
  forever.
- `guide_autoapprove` stopped reporting a failed *notification* as a skipped
  *approval*. The group was approved; the log said otherwise.
- Observer transactions gained rollback arms. On PostgreSQL an unfinished
  transaction poisons every later query in the request, so a swallowed
  exception turned one failed observer into a cascade.
- A two-lock path released its first handle when the second timed out;
  previously it leaked until its own timeout, blocking every other writer.
- Two dead exception pins removed: `moveedit`'s edit-and-restage caught a
  type `moves::cancel()` stopped throwing in 1.20.22 — so the race it existed
  to swallow was rendered as a form error claiming the stage had failed when
  it had already succeeded, inviting a resubmit and a duplicate move. The
  departments page now answers a concurrently deleted row with a notice
  instead of the error page.

### P3 — two debts paid rather than carried

- **A docblock claimed evidence that did not exist.** The 1.20.23 test said
  the invite arm's page contracts were proven by a live check at release;
  that check was attempted and never obtained. Rather than soften the words,
  the decision itself was given a home: `selfselectadvanced_candidate_name()`
  now owns "name this person only if the activity's candidate pool contains
  them", both invite branches call it, and a real test proves it — a pool
  member is named, an outsider and a suspended enrolment are not.
- **A guard that had never run was removed.** `state::require_state()` had
  zero callers anywhere and its string was unreachable, while advertising
  that stale POSTs "funnel through here". They do not: each service re-reads
  the group inside its lock and re-asks the gatekeeper, which is strictly
  stronger. Wiring it would have added a second copy of a correct check —
  somewhere to drift, not safety — and it threw the wrong kind of exception
  for this codebase. Deleted, with its unreachable string.

## 1.20.23 — audit slice 0: the safety findings (2026-08-08)

> Serial `2026080802` / `1.20.23`. Behavioural changes only; no schema change.
> Source: two independent adversarial audits of 1.20.22, filed under
> `audit_state/external-audits/20260808-1.20.22-adversarial-formation/` and
> `audit_state/FABLE-ADVERSARIAL-AUDIT-20260808.md`. Slice 0 of the reconciled
> plan takes the findings with a live-exposure clock out of the release queue
> first; it needs no product ruling.

- **The invite door asks whether the invitee is a participant at all.** The
  enrolment and `:respond` restriction lived only in the query feeding the
  autocomplete, and core's ajax autocomplete accepts submitted values verbatim
  — it says so itself. So a crafted or merely stale positive id reached the
  service, which put a non-participant on the roster, sent them a real Moodle
  message and showed their name to the course. `can_invite()` now refuses
  anyone outside the pool the search uses, using the same predicate the
  staff-create path already applies to a nominated leader.
- **The refusal notice no longer names arbitrary people.** The invite arm
  resolved any submitted id straight to a full name, so posting `-1`, `-2`,
  `-3` read back the names of people in other courses, suspended accounts and
  staff, one per submit. Names are blessed among *participants*; a name is now
  printed only for somebody this activity's candidate pool contains.
- **The Invite section stops vanishing when its own door refuses.** The tab
  transcribed one arm of the door — "or the seats are full" — so a passed
  cutoff, a not-yet-open window or a winding-up team with seats free hid the
  whole cluster *and* the disabled reason the exporter had just built. That is
  the drift MKT-03 fixed on this very control in 1.20.21, reintroduced for
  three of the door's four arms. A leader of a forming team now always sees
  the section: enabled, or disabled with the door's own sentence.
- **A name reaching the core-group report is escaped at source**, the rule
  this codebase set for its other tables on 2026-08-04; this column arrived
  later and was missed. `fullname()` returns names unescaped and the report
  prints its table as raw HTML.
- **The join-accept consent dialog survives translation.** The message was
  interpolated into a JavaScript string literal, where HTML escaping cannot
  protect it: the parser decodes the entity before the handler compiles, so a
  single apostrophe — near-certain in French — made the dialog a no-op. Since
  the form pre-sets the consent flag, the click then submitted *with consent
  asserted and no dialog shown*, defeating the guard decision 64 added it for.

## 1.20.22 — external-audit wave 1.5: the typed-refusal contract is finished (2026-08-08)

> Serial `2026080801` / `1.20.22`. Behavioural changes only; no schema change.
> Source: the consolidated master audit of 1.20.21
> (`audit_state/external-audits/20260808-1.20.21-consolidated-master/`), which
> verified wave 1's direction and found its architecture unfinished (§4.2):
> the typed class existed at two Delete sites while ~150 expected refusals
> still travelled as bare `moodle_exception`, and 61 broad controller catches
> could disguise a genuine failure as a friendly notice — the mirror image of
> the fatal-page bug. Decision 68.

- **Every expected refusal now travels typed.** All 122 `refusal*`-keyed
  service throws, all 28 gatekeeper-refusal transports, and the rule-refusals
  that carried legacy `err*` keys (dissolve blocked by an award or live
  request, department in use / has children / duplicate name, coordinator
  eligibility and import) construct `workflow_refusal`. Field-level
  validation (`err*required`, name validation, the move form's field-mapped
  family) deliberately stays outside the type.
- **No controller swallows the rest.** The 61 broad `moodle_exception`
  catches across 18 pages are narrowed to the typed class; the four that
  legitimately map validation codes to form fields or notices keep an
  explicit errorcode allowlist and rethrow everything else. A database or
  coding failure on a POST arm is loud again, everywhere.
- **Two stale races the sweep uncovered are closed:** cancelling a move that
  another worker committed meanwhile answered with a raw missing-record
  exception (now `refusalmovegone`, with the sentence naming what happened),
  and an unfreeze whose roster delta appeared after the confirmation page
  rendered demanded its reason via the fatal renderer (now typed).
- **The gate holds the line** (audit §4.3 steps 4–5): a new `refusal-typing`
  static check fails the build on any new untyped `refusal*` throw, untyped
  gatekeeper transport, or swallowing broad catch — proven against four
  deliberately broken trees before wiring in. The same two questions are
  mirrored as PHPUnit asserts so GitHub Actions asks them too.
- **The stale-action harness now covers the matrix** (audit §15.2): fifteen
  service seams — submit, invite, accept, leave, succession, both contact
  doors, EOI, approve, return, freeze, unfreeze, join-accept, handover,
  ticket claim, move cancel, team edit — each proven to answer a
  render-mutate-resubmit race with the typed refusal and an unchanged row.
  The old NOTIFY_ERROR-counting canary (§4.4 named it non-discriminating) is
  replaced by the contract asserts.
- **Maturity now tells the truth** (§10, decision 70): `MATURITY_RC` until
  the remaining waves land and the exact candidate passes the full matrix.

## 1.20.21 — external-audit wave 1: expected refusals never look like crashes (2026-08-07)

> Serial `2026080706` / `1.20.21`. Behavioural changes only; no schema change.
> Source: the external release audit of the 1.20.20 archive
> (`audit_state/external-audits/20260807-1.20.20-deep-release/`), wave 1 of 4.

- **Expected workflow refusals now travel typed** (`workflow_refusal`), so a
  controller can catch exactly the decisions the workflow plans for and let
  genuine failures stay loud. The audit's MKT-02 race is closed: a Delete
  confirmed on a stale page is re-refused under the lock and answered with a
  notice on the team page — never Moodle's fatal error renderer with its dead
  "More information" link. Nine more surfaces got the same contract: the
  freeze and unfreeze preflights, the proposal arm, both team-edit refusals,
  contact-a-guide, the guide's approach review, and staff messaging.
- **The Invite control asks the invite door itself** (MKT-03): a new
  candidate-independent gate predicate supplies both the control's state and
  its disabled reason, so a team full of confirmed members reads the
  confirmed-full sentence instead of advice to withdraw an invitation nobody
  made — and an expired window now disables the control with the reason
  instead of offering a form the service would refuse.
- **Over-maximum is not "full"** (UX-02): a roster the settings outgrew is
  refused with its figures and the remedy — "This team has 3 confirmed
  members, but the current maximum is 2…" — instead of a sentence that
  suggests waiting is enough.
- **Stale-POST action tests** (TEST-01): the new suite drives the exact
  render-mutate-resubmit race at the service seam and pins the typed refusal;
  the old text-count test is demoted to a canary and now covers Delete too.

## 1.20.20 — seam-audit batch B: every control asks its gate (2026-08-07)

> Serial `2026080705` / `1.20.20`. Behavioural changes only; no schema change.
> The remainder of `audit_state/SEAM-AUDIT-20260807.md`, each item fixed after
> its own RCA with a per-fix blast check.

- **Every remaining control asks the gate it posts to** — called, never
  transcribed: the joinrequest Answer tab (leader authority + the decision-65
  conflict rule, with the reason shown where a coordinator may not act); the
  guide queue's Return (its own gate, so it no longer vanishes exactly when an
  approve refusal calls for it); the contact-a-guide link; the leader's
  pending-invitation markers (the full accept gate, matching the invitee's own
  page); the delete control (verdict and sentence from `can_delete_group`);
  the ticket Claim button (the conflict rule, disabled with the reason); the
  one-click disband leave (no stray `:respond` requirement the verb never
  asks); and the coordinator dashboard's involvement card (a new bulk producer
  pinned by test to the per-team predicate).
- **The auto-grouping planner honours distinct rules.** "At least N different
  departments" was invisible to every branch — neither honoured nor logged.
  Satisfiable pools now seat the distinct values; an unfillable rule is
  bypassed **and logged**, spec 9.3's own semantics. Seat-template planning
  remains future work, and the run log now records each formed group's
  template deficits instead of silently claiming compliance.
- **Sentences say what is true for their reader:** the composition-maximum
  refusal names its counting basis; "withdraw an invitation" is advised only
  where an invitation exists and the reader can withdraw it; the consent
  confirmation no longer claims a composition break for a tier that breaks
  nothing (its own message, `refusaljoinconsent`); rule codes (`L2:`,
  `QUOTA:`) stay with staff, whose bypass form they name — students read the
  sentence alone; the reserved-seats consent note speaks consent, not the
  hard refusal's vocabulary; and a parked student with other teams is no
  longer told "you are not in a team".

## 1.20.19 — the seam-audit batch (2026-08-07)

> Serial `2026080704` / `1.20.19`. Behavioural changes only; no schema change.
> Source: the 43-agent seam audit (`audit_state/SEAM-AUDIT-20260807.md`) — the
> five HIGH findings, the whole failure-honesty family, and the completeness
> critic's three, each fixed after its own RCA with a per-fix blast check.

- **The move engine honours the wind-up seal (decision 63).** A staff move
  into a team whose leader has requested disband was the one admission path
  with no check at all. It now refuses by default (`DISB` verdict, re-judged
  at commit inside the locks) and is pierceable **only** by the move-scope
  override with a written reason — the maintainer's ruling verbatim:
  overrides, admin and editing-teacher decisions are always honoured; every
  student door still refuses hard.
- **The override guard measures what the gate measures.** The guide-cap
  reduction guard counted only guided teams while the enforcement gate counts
  commitments (guided + forming pre-assignments) — a reduction could activate
  silently and strand forming teams at submission. All four blocker counts now
  call the single producers.
- **Thirteen POST arms answer refusals with notices** (submit, freeze, four
  succession arms, invitation decline/withdraw, guide assignment, department
  add/rename, approve, move cancel, consent toggle) — no more raw error pages
  anywhere a service can refuse; one guide-picker failure no longer silently
  abandons the pickers after it.
- **Notifications keep their promises.** "{$a->member} has accepted… {$a->size}
  confirmed member(s)" printed literally; the producers now supply every
  placeholder their sentences use.
- **The join-decide door asks the conflict rule.** An involved group
  coordinator can no longer answer requests for their own team (managers and
  editing teachers exempt — the trusted arm, per the same ruling).
- **Restores shift the schedule.** Term-rollover restores carried last term's
  open/due/cutoff verbatim, locking the whole new cohort out; the three dates
  (and per-target override dates) now roll forward like every core module's.
- **The deadline reminder warns on the penalty's own basis.** A student
  confirmed only in a *forming* team was skipped — and then penalised. They
  are now warned with their own honest sentence ("your team is not yet
  settled"); a firm or frozen seat remains shelter.
- **Privacy completeness:** the per-guide reminder markers are declared and
  exported by the privacy provider.

## 1.20.18 — refusals say exactly what is missing (2026-08-07)

> Serial `2026080703` / `1.20.18`. Behavioural change only; no schema,
> capability, message-provider or scheduled-task change.

- **Unreachable-composition refusals name the concrete unmet needs.** Instead
  of "it would still need at least 3 more member(s) who fit the team's rules",
  the sentence now reads "…the team would still need: 2 more from Department
  SCOPE; 3 more different Department value(s)." — the same vocabulary as the
  Composition requirements panel, computed by the same engine over the same
  roster, at every surface (join picker cautions, invite picker reasons, the
  accept door, consent notes).
- **A refused invitation acceptance is a notice, not an error page.** The
  landing page now disables Accept for any refusal it can see coming (asking
  the real gate — every refusal tier, where it previously transcribed only the
  hard maximum), with the reason beside it and Decline still live; and if the
  roster moves between page load and click, the answer is a redirect back with
  the reason as a notification — never the raw Moodle error page with its dead
  "More information about this error" link.
- Verified against the live report that prompted it: an invitation that
  *advances* the team's rules (a new department under a distinct-departments
  rule) stays acceptable — pinned as a regression test either way.

## 1.20.17 — rules are the staff's to declare breakable (2026-08-07)

> Serial `2026080702` / `1.20.17`. Behavioural change only; no schema,
> capability, message-provider or scheduled-task change. Decision 64.

- **A student leader can no longer confirm away a rule refusal when accepting
  a join request.** Observed live (g=44): under "exactly two SCOPE members"
  plus "at least four distinct departments" on five seats, the engine refused
  a second same-department member — and the accept screen offered the leader a
  confirm dialog whose OK click wrote a QUOTA override *in the leader's name*
  and committed the move. Every rule refusal on the accept door — the engine
  tier and the source-team-minimum L1 included — is now a **hard stop for the
  ordinary decider: the accept button is disabled with the reason beside it**,
  and Decline stays live. Bypass exists only through the staff override
  capability with a written reason, exactly as the explicitly posted override
  path always required. The leader's confirm click survives solely for the
  consent notice (pending invitations affected — no rule broken, nothing
  bypassed, no override recorded).
- **Refusals now speak plain language.** "Source keeps 0 confirmed members
  (minimum 1)" became "Their current team would be left with 0 member(s),
  below its minimum of 1."; "could no longer complete its composition" became
  "could never be completed correctly: it would still need at least N more
  member(s) who fit the team's rules, but only M seat(s) would be left."

## 1.20.16 — the candidate picker survives a failed search (2026-08-07)

> Serial `2026080701` / `1.20.16`. JavaScript-only; no schema, capability,
> message-provider or scheduled-task change.

- **One refused search call no longer freezes an autocomplete for the life
  of the page.** Core's form-autocomplete cannot recover from a rejected
  transport: its in-progress latch resets only on the success path, so after
  one failure every later keystroke re-queues itself forever and no request is
  ever sent again — and the loading icon's removal is chained off the resolved
  promise, so the throbber never leaves either. Observed in production on
  2026-08-07: a transient `nopermissions` refusal (identified byte-exact from
  the 300-byte response in the access log) left the invite picker spinning
  silently. All FOUR of the plugin's transports (candidate, participant,
  guide, group pickers) now name the failure in an exception dialog and answer
  the widget with an empty result set, so the spinner clears, the latch
  resets, and the very next keystroke retries — which is exactly what heals a
  transient refusal.

## 1.20.5 — what the column says, the button does (2026-08-05)

> Serial `2026080100` / `1.20.5`. No schema, capability, message-provider or
> scheduled-task change. The serial is also a correction: every serial from
> `2026073200` onwards encoded **2026-07-32**, a day that does not exist —
> the scheme carried its increment into the day field instead of the month.
> Moodle only requires the number to rise, so nothing broke; from here the
> serials are real calendar dates. Landed savepoints are not rewritten.

- **A pending invitation no longer refuses a join request.** Only *confirmed*
  members can put a counting maximum beyond reach. Where an invitation that
  has not been accepted is the only reason a team looks full, the leader now
  gets a **warning naming what is confirmed, what is invited, and what this
  request would make** — and keeps the withdraw-invitation control to make
  room deliberately. A team that is genuinely over its maximum still refuses.
- **The fit verdict and the accept button can no longer disagree.** They are
  produced by one predicate. Previously a request could be shown as *"Meets
  this team's requirements"* and then be refused on accept with a quota
  message — and, in the other direction, a student swapping teams could be
  shown as a poor fit for a membership cap the move engine nets out. Both are
  gone.
- **Refusals say what is true.** The composition refusal states the confirmed
  count against the maximum instead of a projection that read as the current
  roster; a request that joins a team without leaving one no longer reports
  *"quota rules on both groups after the move"* when there is no second group;
  and seat counts keep confirmed and invited apart.
- **Requesters show their department and sub-department** in both join-request
  views and on the leader's own team page — the composition dimensions a
  leader is deciding about. No contact detail is added: the privacy switch is
  untouched.
- **The leader sees incoming requests on their team page**, with accept and
  decline, instead of having to find the join-request screen.
- **A departed nominee's handover now lapses on unenrolment too** — the
  deletion path already did this, and the earlier fix's own test only covered
  deletion.
- **Privacy: discovery and erasure now agree.** A person named inside a queued
  digest is found *and* removed, including when their name carries a non-ASCII
  character, which the stored JSON escapes — the two halves previously used
  different tests, so such a person could be discovered and then left behind.
- Department and programme vocabulary writes are serialised and atomic; the
  guide-handover authority matches the page that offers it; the participant
  search placeholder no longer promises an email match it never performed; and
  the requirements are stated plainly as Moodle 5.2 on PHP 8.4 or later.

## 1.20.4 — a refused message is a fact, not a shrug (2026-08-04)

> No schema, capability, message-provider or scheduled-task change. Serial
> `2026073240` / `1.20.4`: one new event class (`notification_refused`) and
> one new language string need an installed site's caches rebuilt.

Moodle's `message_send()` reports failure by returning false, and this plugin
used to treat that as delivered. Four consequences of believing it, all
closed by execution on both engines with mutation-red proofs:

- **`notifier::send()` returns the outcome and records refusals durably** —
  a `notification_refused` event in the Moodle log (or `error_log` on the
  one defect path where a lock is held). Callers that gate state on delivery
  consume the return; pure announcements may ignore it.
- **A refused reminder no longer silences a user for ever**: the deadline
  reminder's once-only flag and the auto-approve escalation marker are
  written only after a send that reported true. A crash between send and
  flag re-sends once — a duplicate beats a permanent silence, and the code
  says so where it happens.
- **The digest task counts three different things as three different
  things**: submitted, stale-discarded, failed. Stale cleanup is logged as
  cleanup, cannot be counted as a submission, and cannot mask the
  every-recipient-failed escalation. Failed rows stay queued and retry.
- **The manager dashboard offers only what each actor can use** on every
  arm, including the conditional doors (tickets: `:manage` outright or
  `:coordinate`), with Behat pinning both directions.

Wave 2 of the same release (serial `2026073250`) closes the authority and
atomicity register:

- **Every public write service asks its actor's authority itself** —
  contacts, volunteering, templates, department vocabulary, programme
  deletion — with the acting user a required parameter (a service that
  guesses its actor is the defect), a direct-call negative test per method,
  and an audit event per state change. Retreating verbs stay open on
  ownership alone (F3): a withdrawal is never blocked by a lost capability.
- **The review page's last two direct writes joined their services**: guide
  notes save through a locking, re-reading service; the return comment's
  text, format and transition commit in one transaction and one event.
- **Coordinators can find their own powers**: workbench cards for guide
  assignment and composition changes, drawn from the target pages' own
  doors.
- **A departed guide no longer leaves stale assignments**: forming teams
  are released with the leader notified; firm and frozen teams file a
  guide-succession ticket for a coordinator to resolve deliberately; a
  departed *nominee*'s pending handover lapses and the proposing guide is
  told.
- **The privacy provider covers what it stores** — the enumerated context,
  export and deletion gaps closed; pseudonymisation no longer corrupts
  innocent numeric values; and prose in exported rows travels with its
  author, not with every id on the row.
- **The transient digest queue left backup/restore** — a restored course
  no longer replays stale notifications.
- **Requirements stated plainly**: Moodle 5.2 on PHP 8.4 or later, in
  README as in version.php — a promise narrowed on purpose.

## 1.20.3 — authority follows the service (2026-08-04)

> No schema, capability, message-provider or scheduled-task change:
> `db/install.xml`, `db/access.php`, `db/messages.php` and `db/tasks.php` are
> untouched. It carries a version serial — `2026073230` / `1.20.3` — because it
> adds five language strings, three event classes and two service classes, and
> Moodle's string and class caches are rebuilt only by an upgrade.

This release closes the ten findings of the independent 1.20.2 evaluation that
survived confirmation (eight High, two Medium), plus what our own blind audit
of the work found. The pattern applied to those ten paths — and claimed for
**those ten paths, not for the whole plugin**: each state change asks a
service, and each of those screens offers only what its service would allow.
Known paths still outside the pattern (the guide-notes write on the review
page, the return-comment format companion write, and the caller-trusting
services listed in the 1.20.4 backlog) are tracked, not hidden.

- **Four writes authorised on record ownership now ask authority, not
  identity** (AUTH-001..004): listing a team for guide interest, uploading or
  retracting the proposal document, editing the team's title and brief, and
  deciding an expression of interest. Each now runs through a service —
  `eoi::set_listed()`, `proposal::save()`, `api::update_group_details()` — with
  a lock, a re-read, a transaction and an event. A leader whose `:creategroup`
  has been prohibited keeps only the retreating verbs: unlisting the team and
  removing their own proposal.
- **Four reachability gaps closed** (ACT-001..004): the review screen admits
  every actor its own predicate names (`may_review_team()`), managers can
  freeze, coordinators have a Freeze action they can see, and `:assignguide`
  holders can decide interests through the interface — not just the service.
- **The interest decision is offered only to those the service would let
  decide.** The whole ladder — capability, self-dealing, involvement — lives
  in `eoi::decide_refusal()`, one copy, consumed by the page door, the
  renderer and `eoi::respond()` itself. Accepting an interest is also now
  refused while `studentapproach` is on or `eoienabled` is off, the same
  belt-and-braces `express()` always wore; rejecting stays open so a pending
  interest is always clearable.
- **Unfreeze controls are capability-aware** (UX-001): the group table draws
  Unfreeze from the same policy the endpoint applies, so no role sees a
  button that can only refuse.
- **The digest task is bounded** (PERF-001): recipients are processed in
  batches ordered by oldest queued row, with a per-run cap; a recipient who
  is not due costs the run nothing.
- Partial-row reads in the involvement rule now fail loudly instead of
  answering permissively, and two comments that had drifted from the code
  they describe were corrected.

## 1.20.2 — finding a guide (2026-08-04)

> No schema, capability, message-provider or scheduled-task change:
> `db/install.xml`, `db/access.php`, `db/messages.php` and `db/tasks.php` are
> untouched. It does carry a version serial — `2026073220` / `1.20.2` — because
> it adds language strings, and Moodle's string cache is keyed on
> `$CFG->langrev`, which only an upgrade bumps. Without the serial the new
> picker placeholders would render as `[[guidepickerplaceholderany]]` on an
> installed site until somebody purged caches by hand.

- **A student can now find their guide by the detail they actually have.**
  Guide pickers match the typed text against a guide's **email address** as
  well as their name, **when the typed text contains an `@`**; without one they
  match names alone, exactly as before. The employee id already worked, because
  it is recorded as the surname; the address did not, so a student who came
  away from a corridor conversation with nothing but an address had no way to
  complete the submission. This is a deliberate exception, and its shape
  matters: a guide is a member of staff being approached, not a protected
  participant.
  **Nothing renders an address.** No picker, page, export, CSV, web service,
  notification or event payload of this plugin shows or links a guide's
  address — the search returns the same name, department, sub-department and
  load it always did, the row it builds has no address field, and the column is
  not fetched at all unless the query carries an `@`. And the participant side
  is unchanged in both states of the contact-privacy switch: the invite picker,
  the staff move form and the expression-of-interest roster still match on
  names alone, for everybody.
  **What the `@` rule is worth, and what it is not.** A substring match leaks
  the string it matches. With the arm unconditional, a plain enrolled student
  recovered a whole guide address — a local part unrelated to the guide's name
  — in 453 picker calls, extending a fragment one character at a time on
  found/not-found alone. Requiring the `@` does not close that; it removes the
  free sweep, and a determined prober can still anchor on the `@`. The trade
  was taken deliberately: the guide list is a staff directory reachable by
  anyone who can open a guide picker. Exact-address matching was considered and
  not adopted.
- **A coordinator can once again reach the guide an override exists for.**
  The override page's guide picker inherited the assignment pickers' "only
  guides with free slots" filter, which meant the two guides it could never
  offer were a guide who is full and a guide who has not volunteered —
  precisely the two an override is opened to help. The picker on that page now
  offers every guide, and only that page: the assignment and submission
  pickers still list only guides with room, and the service still refuses an
  over-cap assignment. Raising the cap first, then assigning, remains the way
  the deliberate case is expressed; no "assign anyway" bypass was added.
- **The user-scope override picker stops asking for a guide.** It prompted
  "Type a name to find a guide" while searching enrolled participants.

## 1.20.1 — plugins directory review (2026-08-03)

Everything the Moodle plugins directory reviewer raised on 30 July, plus the
things the same rules turned up once they were applied to the whole package
rather than to the lines that happened to be sampled.

- **The package now carries its licence.** A `LICENSE` file at the plugin root,
  the GPL v3 that every file header already claimed. Reported as a blocker.
- **Every global function is Frankenstyle prefixed.** The review named one,
  `clean_param_alphaext()`, which sat inside core's own `clean_param_*` naming
  space. Auditing the package found eight more: a helper prefixed `upgrade_`,
  where core alone declares twenty-three functions, and seven in the maintainer
  tools including a bare `probe()`. All nine renamed, and a test now walks every
  non-namespaced file in the package and fails on the rule rather than on the
  three names that were reported.
- **A message provider without its language string** was reported and was
  already fixed; the check is now a test, because nobody could tell without
  looking and the looking has to happen on every release. Twenty-five providers,
  zero missing.
- **The plugin now claims only what it is tested on: Moodle 5.2, PHP 8.4 or
  later.** It previously declared 4.5 LTS to 5.2 while being gated against 5.2
  alone. Moodle's `version.php` has no field for a PHP minimum, so the floor is
  asserted in `db/install.php` and at the top of `db/upgrade.php` — before any
  savepoint, so a refusal leaves nothing half applied — with a message that
  names both the version required and the version running.
- **Continuous integration now runs the same claim.** The GitHub matrix spanned
  four Moodle branches and three PHP versions while the local gate ran one
  combination; it now runs Moodle 5.2 on PHP 8.4 against both databases, and
  nothing else.

## 1.20.1 (2026-08-01)

> When this section was written `$plugin->release` still read `1.20.0`
> while the version serial had moved, because the serial is what Moodle
> compares to decide whether a site needs upgrading and the release
> string is a separate decision. That decision has since been taken: the
> plugins directory review above ships as **1.20.1**, and the release
> string now says so. The work in this section is part of it.

- **A guide reaches the team they are assigned to without having to see
  every team.** Until now the team page asked one question at the door:
  are you a member of this team, or may you see everything in this
  activity? A guide is never a member of the team they guide, so on a
  site that had withdrawn "See all groups, members, states and the
  penalty ledger" from its non-editing teachers, every guide was refused
  their own team's page — and with it Freeze, Release, the roster, the
  proposal and the ticket forms, while the very same guide could still
  freeze that team in bulk from the dashboard. A new permission, **Open
  the team pages of teams they are the assigned guide of**, answers the
  narrow question; the door now admits a member, the team's own assigned
  guide, a Manage holder or a see-everything holder, and nobody else.
  A Manage holder without the broad permission was refused too, along
  with eight manager-only actions on that page. That is fixed here.
- **Non-editing teachers are no longer unrestricted viewers on a new
  site.** "See all groups, members, states and the penalty ledger" is no
  longer given to the non-editing teacher role on a **fresh install**;
  the new narrow permission is given instead. **On an existing site this
  changes nothing at all** — every permission your site has recorded is
  left exactly as it is, this upgrade never takes one away, and the
  upgrade log names the roles that still hold the broad one so you can
  decide for yourself.
- **What the team page shows now follows the assignment, not the job
  title.** Holding the guide permission said you guide teams; it did not
  say you guide THIS one. The mobile column and the composition
  columns now appear for the team's OWN guide, and the pending-invitation
  list is visible to them too.
- **The review page refuses before it renders.** Any guide could read
  any team's roster, its members' composition attributes, the assigned
  guide's private notes and the proposal filename by editing one number
  in the address bar. Writing was already refused; reading is now
  refused as well, before the page draws.
- **A guide the team turned down loses the team.** The team drill-down
  behind the guide dashboard admitted anyone who had ever expressed an
  interest, whatever the answer, so a guide a leader had rejected — or
  one who had withdrawn — kept the roster for good. It now admits a
  **live** interest only: pending or accepted.
- **A team that changes hands stops being visible to the guide who gave
  it up — but not until the handover is finished.** An accepted interest
  is how a guide becomes a team's guide, and it kept that team's member
  list even after the team had been handed to somebody else or
  reassigned by staff. Sight of the roster now follows the team: while a
  handover is waiting for the new guide's answer the outgoing guide can
  still see it (they are still carrying the team), and the moment the
  new guide accepts — or staff reassign the team outright — it closes.
- **The Back button on a guide's workload page no longer leads to a
  refusal.** It pointed at the flagged report, which needs the
  see-everything permission that a guide on a new site no longer has.
  It now returns them to their own dashboard, and still returns a
  see-everything holder to the report they came from. The dashboard's
  "Team page" link is likewise offered only to somebody the team page
  will actually admit.
- **An empty Actions column is no longer drawn** on the team drill-down
  when no row on it can carry an action.
- **The guide dashboard finally links to the team page**, and a guide can
  open their own workload from the "Teams you guide" card without
  needing to see everybody's.
- **The Group Coordinator role is assignable at ACTIVITY level only.**
  It does work inside one activity, and a course-level assignment
  silently carried see-everything, the override hatch, Manage
  composition and Assign guide into every instance in the course at
  once. Moodle no longer offers the role on a course's Assign roles
  screen. **Assignments you already made are untouched:** a course-level
  one still grants exactly what it granted, and is still listed on, and
  removable from, the plugin's own Coordinators screen.
- **An override can no longer produce a combination that contradicts
  itself.** Overrides fill in field by field: an override that sets only
  a deadline keeps the activity's own opening date and cut-off. Nothing
  ever checked the combination that produced — so "give this student
  until Friday" could be recorded against a cut-off that had already
  passed (the extension was then silently trimmed back to the old
  cut-off, while the late penalty was calculated from the new date), a
  team could be given a maximum size below the activity's minimum and
  become impossible to settle, and a group's opening date could land
  after one of its members' personal deadline. Every override write now
  checks the combination it actually creates — its own values merged
  with the activity's settings, and, for dates, merged with the
  overrides of the teams a student belongs to or the members of a team.
  A contradictory one is held **pending**, exactly as an over-capacity
  reduction already was, with a message naming **both** conflicting
  values and where each came from, and a link to the page that fixes it;
  it starts applying by itself the moment the conflict is resolved. The
  same check runs on the override form, so an administrator is told
  before saving rather than afterwards. **Editing the activity's own
  settings re-examines the overrides already granted**, and holds back
  any that the edit has just contradicted — nothing is deleted, and
  nothing changes for overrides that still make sense.
- **An override form no longer says the same thing twice.** Submitting a
  pair of dates the wrong way round reported the conflict on both
  fields; it is reported once.
- **The blocked-override list is paged, and the re-check that runs with
  it is bounded.** A single settings edit can now hold back an entire
  activity's overrides, which made the overrides page's every-visit
  re-check — and its unpaged list of blocked rows, with a name looked up
  per row — reachable at the size this plugin is built for. The re-check
  now works through a window at a time, resolves its names in two
  queries, asks only for the override rows a row can actually conflict
  with instead of the whole activity's, and skips a row another process
  is busy writing rather than turning a committed move into an error.
  The nightly reconcile, which is the safety net for the blocked rows
  beyond the page's window, still reaches every one of them — a window
  at a time rather than in a single unbounded read.
- **A permission you have PROHIBITED now actually stops the action.**
  Four things a site could forbid on the Permissions page and still
  watch happen. Creating a team and leading it — inviting, withdrawing
  an invitation, confirming a member's departure, deleting the team —
  asked whether you owned the team and whether the rules allowed it,
  and never whether you were permitted to do it at all, so the button
  disappeared from the screen and a direct form post went straight
  through. Answering an invitation, accept or decline, was the same,
  while asking to JOIN a team had required the permission since the day
  it was written. Freezing a team asked a manager or coordinator
  freezing on someone's behalf for their permission, and asked the
  team's own assigned guide for nothing. And a bulk freeze large enough
  to finish on a later cron pass checked the permission when the button
  was pressed and never again — so revoking it, or removing the person's
  role, between the click and the cron run changed nothing.
  All four now ask at the SERVICE, which is the only place a direct
  post, an AJAX call, a web service and a queued task all have to pass
  through; the queued bulk freeze re-asks before every single team and
  logs the ones it therefore skipped. **On an existing site this changes
  nothing** — everyone who could do these things yesterday still can.
  What changes is that withdrawing the permission now works.
- **A team's leadership can no longer be handed over by somebody who is
  not permitted to act.** The list above was drawn up from the things
  the permissions were known to guard, and it missed the one action in
  this plugin that HANDS a person a team. Nominating a successor,
  cancelling that nomination, and the nominee's own Accept and Decline
  all went ahead whatever an administrator had decided: with both
  "Create groups and act as leader" and "Accept or decline invitations
  and nominations" prohibited on the activity, a student could still be
  nominated, confirm, and become the team's leader — by pressing the
  button or by posting to the address directly. Naming or cancelling a
  successor is now the leader's permission, and answering a nomination
  is the respond permission, which is what its name has promised all
  along. The nomination itself stays on screen: a student who may no
  longer answer must still be able to see that their team is waiting on
  them, and their leader can still cancel it.
- **A guide whose permissions have been withdrawn can no longer put a
  mark in the gradebook.** The award and the guide's notes were opened
  to whoever was named as the team's guide, on the name alone, with no
  permission checked at all — so withdrawing "Act as a project guide",
  or the narrow permission that opens the team's pages, closed every
  other door on that team and left the one that writes a grade standing
  open. Writing an award now asks the same two questions the review page
  asks at its door. Managers keep their access, and see-everything
  permission still does not buy a grade write.
- **Approving a team and sending it back now ask the permission that
  names them.** "Act as a project guide: review, return and approve
  groups" names three things, and the fix above reached only the first.
  Further down the very same file, Accept and Return still admitted
  anyone whose name was on the team as its guide and asked no
  permission at all. So the guide who had just been refused the team
  page, the review page and the proposal file could still open their
  dashboard, press the one-click **Accept**, and move the team from
  waiting to firm — writing the approval date and a penalty-ledger row
  on the way. Withdrawing "Act as a project guide" outright did not stop
  it either. Both now ask the same pair of questions the gradebook write
  asks: are you the team's own assigned guide, and are you permitted to
  guide at all. See-everything permission buys neither, and Manage keeps
  the award correction and nothing more. The dashboard draws its Accept
  and Return from the same answer, so where the buttons used to sit a
  guide now reads the reason instead; the automatic approval that runs
  when the decision window lapses is untouched, because a lapsed
  deadline is exactly what stands in for the guide there.
- **Submitting a team to its guide is a leader action, and is now
  permitted like one.** "Create groups and act as leader" had been
  applied to creating, inviting, withdrawing, confirming a departure,
  deleting and the whole succession chain — two passes over the same
  permission, and both walked past
  the one action that ENDS the team's forming stage: the one that
  claims a guide's capacity and mails them. A student whose permission
  had been prohibited was shown neither "Invite members" nor "Delete
  group" and could still press **Submit to guide**, in the browser, with
  nothing crafted. The service now refuses first, before it takes any
  lock, and the page no longer builds the form.
- **Assigning a team's guide asks the permission whose name says so.**
  "Assign or reassign a team's guide and decide expressions of interest"
  covers two actions, and only the second one asked for it. Assigning —
  which changes who supervises a group of students, frees one guide's
  workload slot and consumes another's — checked only that the person
  doing it was not entangled with the team, and left the permission
  entirely to the screen it happened to be reached from. It now asks the
  same pair that screen asks, before the locks. **Nobody who could
  assign a guide yesterday is refused today**; what changes is that a
  direct post, a future caller, or work queued before you withdrew the
  permission now meets the same answer the screen gives.
- **"Is this their team?" is asked in one place.** Four screens and
  services kept their own private copy of that test, comparing the
  team's guide to the person acting and stopping there. None of them was
  a way in — each also asked a permission, or its page did — but one
  question with four answers is precisely how the proposal file came to
  disagree with the page that offered it. The team page's Freeze flag,
  the freeze service's choice between "your own team" and "on the
  guide's behalf", and both halves of a guide handover now call the one
  answer. One test is deliberately left as it was, with the reason
  written beside it: the restraint that stops a guide releasing a team
  an editing teacher froze is the single place where the comparison
  REFUSES rather than admits, and routing it through a permission would
  mean that withdrawing a read permission handed the release back.
- **A control you are not permitted to use is no longer drawn.** With
  the permissions above withdrawn, the screens still offered Invite,
  Delete team, Confirm leave, Accept and Decline, and every one of them
  ended at a "you do not have permission" page. They are now hidden.
  Withdraw on a pending invitation, Freeze on a team you guide, and the
  Accept, Decline and Cancel buttons on a leadership nomination go the
  same way. A pending invitation is still LISTED when you may not answer
  it — you need to know a team is waiting on you, and its leader can
  still withdraw it — and so is a nomination; it is the buttons that go.
- **The file server now asks the same question as the page that offered
  the link.** A team's proposal was served by a rule written out a
  second time inside the file server, and the two copies had drifted
  apart in both directions: a guide assigned to a team, on a site that
  had withdrawn the new narrow permission, was refused every other door
  on that team and still got the file; while a Manage holder — who can
  open the review page, where the proposal is displayed in place — was
  refused the very file that page had just embedded. There is now ONE
  rule, and the screen and the file server both call it. Who may
  download a proposal: the team's confirmed members, the team's assigned
  guide, a Manage or see-everything holder, and a guide the team is
  currently approaching, for as long as that approach is unanswered.
  An invited but unconfirmed person still sees the filename without a
  link — an invitation is not a membership. **A guide a team has just
  approached can now open the proposal they were asked to judge**; the
  page that asks them to decide had always shown the link, and the file
  server had always refused it. **The team page and the file server are
  now tested against each other** for a named set of people, in the
  browser as well as in the unit tests: they already agreed, but nothing
  in the plugin would have noticed if they stopped.
- **The invitation candidate search no longer runs a lookup it cannot
  use.** Once the contact-protection setting became the whole test, the
  picker still performed a connection lookup for the page behind a
  condition that required the setting to be both on and off at once, so
  no row could ever reach it. It is gone. Contact protection is per
  activity and it is a switch, not a scale: with it on, nobody matches
  or sees an address; with it off, your site's own two identity
  permissions decide alone. New tests hold that line for a manager, for
  a role granted "See participants' identity", and for a site
  administrator, on the search as well as on the label.
- **No screen, search, export or label anywhere in this plugin shows or
  matches on an email address while contact protection is on — for
  anybody, including editing teachers, managers and administrators.**
  Two surfaces still exempted a Manage holder. The staff move form's
  participant picker accepted a full address and answered with a name,
  regardless of the setting; the invitation candidate picker did the
  same and printed the address as well. Typing an address in and seeing
  who comes back is an address book run backwards, and it does not stop
  being one because the person doing it is senior. Both are names-only
  now, which is what the expression-of-interest roster has been since
  1.20.0. Both pickers still find people by name, and staff still reach
  a participant with Send a message.
- **The two staff imports keep their email fallback, deliberately.** The
  coordinator upload and the participant-attribute CSV both accept an
  address as the match key for a row with no username. That address is
  one the person running the import typed into their own file, resolved
  once by exact match, and neither import ever puts an address back into
  its report: a matched person is named by full name and username, and
  an unmatched line echoes only what the file said. Removing it would
  break a documented file format to close a door the operator is already
  standing on the other side of.
- **Accepting a join request no longer re-examines blocked overrides
  while holding a lock.** The sweep that lets a blocked override start
  applying once the thing blocking it is gone ran from inside the lock
  that serialises answering a request, so the notice it publishes
  travelled under that lock and it took further locks of its own
  underneath. It now runs after the lock is released, still restricted
  to the student and the two teams the acceptance actually moved — and
  it no longer runs at all when a request is turned DOWN, which moves
  nobody and so can have unblocked nothing.
- **A large composition template can no longer exhaust a page's memory
  and end the request.** Seating a roster into a slot template searches
  for an exact answer, and the memory it used to do that was never
  written down or bounded. Measured on the shapes at the edge of what
  the search accepts, ONE team could allocate 169 MB — against the 128
  MB a Moodle page is given, which nothing on the way in raises — so the
  failure was a fatal error on one team rather than a slow page, on the
  team autocomplete a student types into and on the manager's compliance
  sweep. The search now keeps a fraction of what it kept before and
  stops rather than growing past a stated ceiling; the worst case
  measured over 680 adversarial templates fell from 169 MB to 41 MB.
  The envelope, its measurements and which number to tune are written
  down beside the limit they belong to. **The first ceiling chosen for
  that was too low, and it is corrected below** — the sentence that once
  stood here, that all 680 templates came back with exactly the answer
  they had before, was true of the 680 and not of the shapes outside
  them.
- **A team the composition rules can seat is no longer reported short.**
  The ceiling above was set from a sample
  rather than from the memory it exists to protect, and it was too low:
  ordinary teams reached it — fourteen people against nine one-seat
  rules, nine people against eight four-seat rules — and were handed to
  the fallback, which only ever reports a *lower bound*. One of them was
  told it had seated five people when seven of its members can in fact
  be seated, so a team that meets its composition template was reported
  two seats short. Both are decided exactly again.
- **And a team is now seated the same way whatever its attribute values
  are called.** Correcting that ceiling introduced a worse fault than
  the one it fixed, and this is that fault fixed. The search remembers
  the states it has already worked out and it filed them under a label
  that contained the attribute values themselves — so the working memory
  a team was given depended on how long its course's words are. A course
  that types "Electronics and Communication Engineering" into the
  free-text Department the CSV importer accepts — forty-one characters —
  was given roughly a tenth of the memory of a course that types "eng",
  for the same roster and the same rules, and gave up where the other
  finished. Two different teams, identical in every way that matters,
  seated differently because of their vocabulary. The values are now
  numbered internally and the labels carry the numbers, so what a course
  calls things cannot change what it is told. The numbering itself moves
  no answer: run against the engine as it stood before any memory limit
  existed, with the limit switched off on both sides, the two agree on
  every seat and every person over more than a thousand generated
  cases — three hundred and sixty teams, each solved at four different
  value lengths. Both corpora that were supposed to guard this used
  seven-character values, which is why nothing noticed.
- **The two limits on that memory are now one.** Once a remembered state
  is a bounded size, the number of states and the memory they occupy
  stop being two different questions — and it was the *count* that bound
  first and cost the seats. It is gone. What is left is a single memory
  budget, derived from the whole envelope one team is allowed rather
  than from a sample of runs, and raised from 32 MB to 36 MB because
  measured teams need more than 32. **What is not finished, stated
  plainly:** no test in this plugin can make that memory budget bind. No
  team has been found inside the size guard that needs 36 MB, so nothing
  pins a verdict produced by exhausting it, and removing the check
  altogether leaves the whole suite green — measured, not assumed. It is
  a belt, and that is written down beside the constant rather than left
  to be discovered. Large teams can still be handed to the fallback and
  reported short by the *time* budget, which is a deliberate decision and
  is unchanged. Every answer is now pinned against the engine run with
  no memory budget at all, at four different value lengths, so no future
  change can trade a team's seat for memory without a test saying so.
- **Two comments that were not true are true now.** The move engine said
  every team a commit touches is locked by the commit, when on one of
  its two paths it locks nothing and requires the caller to have done
  it; the caller's obligation is now stated, and checked. The privacy
  provider promised that removing an erased person from a mirrored
  course group would be handed to a queued background task if a
  transaction were open; there is no such hand-off and no such task —
  the work runs inline, and the comment now says what actually happens
  and why it is safe.
- **A refusal no longer leaves behind the work it refused.** Sixteen
  places in this plugin — approaching a guide, granting or removing an
  exception, freezing and releasing a team, the join queue and the
  request queue — do their work inside a database transaction and turn
  a request down from inside it. Each of them decided whether to undo
  that transaction by asking whether *it* had been the one to start the
  outermost one, and simply walked away from its own when the answer was
  no. Moodle undoes the innermost piece of work first and lets the undo
  cascade outwards; a piece abandoned rather than undone blocks the
  cascade, so the caller's own "undo everything" silently did nothing.
  Two live paths run exactly that way — approving a team writes the
  relief exception that goes with it, and accepting a join request mints
  a rule exception for the move — and after a refusal on either, changes
  the plugin believed it had discarded were still there, and every save
  for the rest of that request failed. The scheduled sweep that
  auto-approves teams catches each team separately and carries on, so
  one refusal could leave the rest of a run writing nothing while the log
  filled with plausible "skipped" lines. Every one of the sixteen now
  undoes its own work, whoever started the transaction. The switch that
  decided this was also invisible to testing — under the test harness it
  answers for the harness rather than for the code being tested, and it
  answers differently on PostgreSQL and MariaDB, so each of the sixteen
  took one path per database and the path that was broken was never
  taken by either. It is gone, and the tests now force both paths
  explicitly on both databases and check the result by reading the row
  back out of the database after the undo.
- **A deadline you never set no longer costs marks.** The penalty for
  belonging to fewer teams than the activity asks for checked only
  whether the deadline had passed — and an activity with no deadline
  stores it as zero, which every moment of every day is past. Leaving
  the deadline empty is the ordinary setting on a new activity, so every
  student below the minimum was docked that penalty from the day the
  activity was created: 90.00 instead of 100.00 on a 100-point activity
  asking for two teams, published to the real gradebook and not merely
  shown on a report. The penalty now waits for a deadline that exists. A
  deadline already past still applies exactly as before, and one still to
  come still does not.
- **The defaulters report and the defaulter penalty now count the same
  teams.** The report credited a student for every team they had
  confirmed a place in, whatever state that team was in, while the grade
  counted approved teams only. A student sitting in a team that was still
  being formed was marked down for a shortfall the report told them they
  did not have. Both now count approved teams and nothing else, so the
  list a teacher reads and the marks a student receives now count the
  same memberships. They still answer different questions, deliberately:
  the report is a worklist of students short of approved teams at any
  time, while the grade penalises only once a deadline exists and has
  passed, so a student on an activity with no deadline set is listed and
  is not penalised. Two further consequences follow and are deliberate: a
  student whose only teams are still forming now appears on the
  defaulters list, because they are indeed short of approved teams; and a
  student in no team at all is listed but keeps an empty grade rather
  than a penalised one, exactly as before.
- **A student acting at the very second the deadline falls is no longer
  grouped out from under themselves.** The formation window has always
  included the cut-off second itself — at that instant the pages still
  let a student create or join a team — but the automatic grouping sweep
  treated that same instant as already missed, and would collect that
  student for automatic placement while they were still free to act. The
  sweep now agrees with the window it enforces: the cut-off second is
  inside it, and automatic placement begins the second after. No
  advertised window was shortened to achieve that; every other place in
  the plugin that compares these three dates was checked and already
  agreed.
- **The invite box stops promising a search it will not make.** Its
  placeholder reads "Search by name" for every viewer, in both states of
  the contact-privacy switch, because matching by email address was
  withdrawn earlier in this release and the box was still advertising it
  on activities that are not protecting contact details. The group page
  no longer works out who is allowed an address search: the flag is
  removed rather than narrowed, because a flag whose only correct value
  is a constant is one the next reader will widen again. The design notes
  record the reversal and name the tests that hold it.
- **The browser tests cover the picker again, and now cover what it
  refuses.** The scenario that searched for an invitee by email address
  had been failing on both databases since address matching was
  withdrawn. It searches by name now and keeps every check it had,
  including that the invitation really reaches the person. A second
  scenario submits a full email address to the picker of an activity that
  is *not* protecting contact details and requires that it finds nobody —
  the case where re-adding the match would be easiest to defend and least
  likely to be noticed.
- **Guard rails that a comment could satisfy no longer can.** A check
  that searches a file for a line it insists must be there will happily
  find that line inside a comment — and commenting the old call out and
  writing the new one under it is the edit a developer actually makes.
  Two such checks were measured failing open, on PostgreSQL and MariaDB
  alike: with the auto-grouping permission check commented out, and with
  the flagged-students download's no-phone-numbers setting commented out
  and replaced, both reported success while the thing they guard was
  gone. Every check in the test suite that reads a source file now strips
  its comments first — the two above, the participant search's promise
  never to touch the address column, and the newly added pin on the
  flagged-students download — and each has been watched failing against
  the edit it exists to catch before being trusted. The counterweight is
  pinned too, so a later hardening pass cannot quietly delete the
  disclosure the design requires: the flagged report's on-screen line
  must still show a number to a confirmed connection whose owner
  consented, which is the specification and not an oversight.
- **Accepting a guide's expression of interest could install a guide the
  administrator had forbidden.** Four places in this plugin let somebody
  take charge of a team, and three of them asked whether that person is
  allowed to be a guide. The fourth — a leader accepting an expression of
  interest — asked only how much room the guide had left, which is a
  number and not an answer. So a `Guide teams` permission set to
  *Prohibit* was honoured everywhere except the one line that writes the
  guide onto the team: the team was handed to somebody the site had
  barred, then found every later step refused, with no way back that did
  not need staff. Expressing an interest and accepting one now both ask
  the same authority the other three seams ask. Nothing about capacity
  changed; a guide who is simply full is now turned away with the same
  wording the rest of the plugin already used.
- **Counting rules could be created, edited, reordered and deleted
  without any check on who was doing it.** The service behind the
  composition screen took no account of the acting user at all — the only
  gate was the permission check at the top of that one page, so anything
  that reached the service by any other route was not checked, and no
  test could show otherwise because there was nothing to test with.
  Counting rules decide whether a team is allowed to submit, so this
  reached across the whole activity. All three operations now require
  the Manage permission at the service itself, asked before any database
  work starts, so a refusal changes nothing.
- **The update is now one an existing site can actually see.** The
  plugin's version serial had not moved since the previous step, and
  Moodle decides whether a plugin needs upgrading by comparing that
  number with the one your site has recorded. Until it moved, none of
  this release could reach an installed site. The new step migrates
  nothing — the database schema, the permissions, the message providers
  and the scheduled tasks are unchanged — and it says so in your site's
  upgrade log, which is also how an administrator can tell an upgrade
  that genuinely ran from one that was skipped.
- **The demonstration seeder builds its course again.** The maintainer
  tool that creates the guided how-to course had been failing in three
  separate ways: it left the students-approach setting at its database
  default, which produced the one combination the activity's own settings
  form rejects and stopped the run at the first guide action; it never
  supplied an ID-number field, so every run printed a PHP warning from
  core; and it had been left behind by the permission repair above, so it
  died with a missing-argument error immediately after creating the
  activity and before a single team existed. All three are fixed, the
  tool now runs end to end, and the five demonstration teams are created
  in their intended states so the how-to screenshots can be retaken.
- **A maintainer tool that nothing runs can no longer be broken in
  silence.** The failure above was invisible to every check this project
  has: a syntax check cannot see a wrong number of arguments, and no test
  executes a command-line tool. A new check reads the tools' source and,
  for every call they write as a direct class-and-method call into this
  plugin's code, compares the arguments against the real signature — so
  a service that grows a parameter can no longer leave those call sites
  behind unnoticed. Its reach is stated rather than implied: calls made
  through an object variable are not resolved, and the tools contain
  twenty-six of those. None of them is mismatched today, and closing
  that half is recorded as the next step.
- **The participant search's promise never to touch the address column
  is now checked against the query it really sends.** The old check read
  the source and counted a word; a single call that widens the selected
  fields would have put the address column into the query without the
  word appearing anywhere, and the check would still have reported
  success. That was measured, not supposed. The endpoint itself is
  unchanged; what changed is the evidence. A new test captures the
  statements the search actually sends to the database and requires the
  address column to be absent from them, so the guarantee now rests on
  the query that runs rather than on a word in the source.
- **The Manage exemption on phone numbers was re-examined and kept, and
  is now fenced in.** Staff who manage an activity still see a consenting
  participant's number on screen, which is what the plugin promises that
  participant when they switch sharing on. It is now pinned to the two
  places that are allowed to ask for it, so it cannot quietly spread to a
  third. The Manage permission is granted to the manager role as well as
  to the editing teacher, and those two cannot be told apart by asking
  for the permission. That is deliberate and has been confirmed as such:
  both are trusted with a participant's details. The audiences the
  setting exists to exclude are guides and the participant's peers, and
  they remain excluded unless a connection or the participant's own
  consent admits them.

## 1.20.0 (2026-07-31)

- **Participants' contact details are protected by default, in every
  activity.** A new activity setting, **Protect participant contact
  details**, is on for new activities AND for every activity that
  already exists after this upgrade. An editing teacher (or anyone else
  holding Manage) can switch it off per activity. While it is on, no
  page, link or download in this activity shows a student's email
  address, and a mobile number is shown only to somebody actually
  connected to its owner — a confirmed teammate, the guide assigned to
  their team, or the coordinator or manager holding that person's
  claimed request ticket — and only when that person consented to share
  it.
- **Staff reach a student with Send a message instead of an address.**
  The team drill-down behind the guide dashboard used to show every
  member's email address, a per-member mail link, an "Email the whole
  team" button and contact columns in its download. All of that is
  gone, replaced by a **Send a message** action on the team drill-down,
  the roster and the ticket queue. It composes a Moodle message: the
  sender never sees an address, the recipient never sees the sender's,
  and delivery follows the recipient's own notification preferences.
  **Read this if you relied on that page:** the address removal is
  unconditional, so switching contact protection OFF does not bring the
  addresses back. Turning it off restores the WhatsApp link and the
  legacy mobile rules, nothing more.
- **A person's own sharing consent is no longer overruled by anybody.**
  Seeing every team ("See all groups, members, states and the penalty
  ledger") used to be enough to read every stored mobile number,
  consented or not; the flagged-students report printed them with no
  check at all and put them in its spreadsheet. Both are fixed. **This
  is a change even with contact protection switched off:** a number now
  appears only when its owner shared it, unless a site deliberately
  grants the new **See participants' email addresses and mobile numbers
  inside this activity** permission, which no role holds by default.
- **The invitation picker no longer answers questions about email
  addresses.** Typing a full address into it and getting exactly one
  person back confirmed whose address it was. While contact details are
  protected the picker matches names only, and says so in the search
  box; with protection off it behaves as it always did.
- **This setting never overrides your site.** If participant visibility
  has been withdrawn at site or course level, that still applies —
  everything here can only narrow what a viewer sees, never widen it.
- **A Group Coordinator can now carry out the composition change they
  decided.** Until now a coordinator could claim a ticket asking for a
  student to be moved, agree with it and resolve it — and then had no
  way to make the move, because moving students and assigning guides
  were behind the full Manage permission (settings, quotas, dates,
  auto-grouping). Two new narrow permissions carve out exactly those two
  jobs: **Manage composition** (stage, commit and cancel student moves,
  and use the move form's student picker) and **Assign guide** (assign
  or reassign a team's guide, and decide guides' expressions of
  interest). The Group Coordinator role gets both on upgrade, and so
  does every role that already held Manage — **nobody loses anything,
  and nobody gains Manage.** If your site had deliberately prevented or
  prohibited something for the Group Coordinator role, that decision is
  left exactly as it is.
- **The Group Coordinator role also carries the staff override hatch
  now, and only where somebody was appointed.** Overriding a
  composition rule, parking a student with no destination and
  dissolving a dead-end team are the same one permission (Override
  rules); a coordinator who is trusted to carry out a move needs it to
  carry out the awkward ones. On sites upgrading from 1.19.x the role
  already picked this up indirectly, because it holds Override; a
  freshly installed site did not. Both paths now agree. The grant is
  safe to make because appointments are recorded against the
  **activity** (see below), so it can only ever apply in an activity
  somebody was appointed in — never across a course and never
  site-wide. If you assign the role at course level yourself through
  Moodle's own role screens, that is your decision and it behaves as
  you asked.
- **Coordinators still cannot act on their own teams.** Both new
  permissions are refused on any team the holder is a confirmed member
  of, guides, or is the proposed successor guide of — checked again at
  the moment a move is committed, not only when it is staged, because
  the roster can change in between. A coordinator with an expression of
  interest of their own pending on a team may not decide that team's
  interests at all, their own or a rival's. Holders of Manage are
  unaffected, as with every other conflict-of-interest rule here, and so
  is a student leader answering a request to join their own team.
- **The move form's student picker no longer answers questions about
  email addresses.** It matched on the address as well as the name, so
  typing an address in and getting a name back confirmed who owned it.
  For anyone whose permission is the new narrow one, the address is no
  longer matched at all — names and register numbers only. Nothing this
  picker returns has ever included an email address or a phone number,
  and it now does not fetch them either.
- **The people who do the work now hear about it.** The "a team is
  waiting for a guide" notice reaches holders of Assign guide, and the
  membership-cap and auto-grouping-result notices reach holders of
  Manage composition, alongside the managers who always got them.
  Somebody holding two of these permissions is told once, not twice.
- **Running auto-grouping still needs the full Manage permission.** It
  rewrites the whole roster in one act, so it is not part of either
  narrow permission even though the dashboard it sits on is now reachable
  with them.

- **Accepting a stale join request can no longer cost a student a
  team.** If a student asked to join a team and then got into that same
  team another way — an invitation they accepted, a move a manager made
  — pressing Accept afterwards used to remove them from the team they
  had offered to leave, admit them to a team they were already in, and
  email them that their request had succeeded. They lost a team and
  were told they had gained one. The request is now refused by name at
  answering time and stays open, so the leader can decline it with a
  note.
- **The upgrade that merges duplicate exceptions now keeps the
  exception that is actually in force.** *Read this if you upgraded to
  1.19.2.* That release merged duplicate override rows keeping the
  older row of each pair regardless of its status. Where the older row
  was a parked one and the newer was the one in force, the merge
  deleted the granted exception and kept the invisible parked twin: the
  target silently fell back to the activity's own limits and dates. The
  merge now keeps the oldest **active** row, falling back to the oldest
  row only when none is active, and 1.20.0 re-runs it so every site
  ends up on the same rule. **Rows deleted by the 1.19.2 merge cannot
  be recovered** — nothing recorded their values. If a group, student
  or guide lost an exception at that upgrade, re-grant it. The symptom
  to look for is a target whose limits reverted to the activity
  defaults while a *pending* override row for it still exists.
- **A coordinator editing an exception now edits the row they are
  looking at.** Where duplicates existed, the editor read the value
  from the active row and saved onto the parked one, so the change
  appeared to do nothing while the old limit kept applying.
- **Recomputing penalties after a settings change no longer holds the
  whole activity while it logs.** The recomputation events are now
  recorded after the activity is released rather than during it, so a
  large recompute does not queue up every student's action behind its
  own logging.
- **Moodle Managers now actually get the permissions 1.20 says they
  get — on upgraded sites, not just new ones.** This release grants the
  Manager role Manage, Unfreeze, Override and View all. Adding a role
  to a permission's default list only ever affects a *fresh* install,
  so on every site upgrading from 1.19.x or earlier the Manager was
  left holding none of them. The upgrade now asserts the four grants
  explicitly. **It never overrules a decision you have already
  recorded:** if your site deliberately allowed, prevented or
  prohibited one of these for the Manager role, that setting is left
  exactly as it is.
- **A course group that failed to synchronise is no longer reported as
  "already in step".** If the synchronisation stopped on an error
  partway through — a member whose account had been deleted, for
  instance — the page told the manager everything matched while members
  were still missing from the course group. The failure is now reported
  as a warning, and a repair job is queued to try again.
- **Freezing an already frozen team no longer emails everybody a second
  time.** Re-freezing is a repair of the course group; two staff both
  pressing Freeze, or a large bulk freeze whose overflow is finished by
  cron, used to re-announce the freeze and mail every confirmed member
  again. The repair now does the repair and nothing else.
- **A Group Coordinator now coordinates the activity they were
  appointed in, and no other.** The appointment was recorded against
  the *course*, although it is made from one activity's screen and
  every permission it carries is asked for per activity. In a course
  running two Group self-selection (Advanced) activities, appointing
  somebody in one gave them freeze, unfreeze, exceptions, the request
  queue and "view all" in both. Appointments are now recorded against
  the activity. **On upgrade, every existing appointment is copied to
  every instance in its course** so nobody loses a job they were doing
  — where that is more than one instance, remove the ones you do not
  want from each activity's own Group Coordinators screen. A course
  with no such activity keeps its appointment untouched.
- **Only a non-editing teacher can be appointed a Group Coordinator.**
  The rule was a default filter on the table and nothing more: a POST,
  a "Every participant" filter or a line in an uploaded file could
  appoint a student, who then held "view all" (personal data) and
  "override" (configuration). Both the single appointment and the
  upload now refuse anybody who is not a non-editing teacher, and the
  table says "Not eligible" instead of offering a button that would
  fail. Eligibility is decided by the role's **archetype**, so a site
  that renamed its non-editing-teacher role to Tutor, Demonstrator or
  Facilitator is served correctly — the previous filter matched the
  short name `teacher` literally and showed such a site an empty pool.
  People who already hold the role keep it and can still be removed.
- **A cohort upload of coordinators no longer asks one question per
  line.** Enrolment and eligibility are now resolved once for the whole
  file.

## 1.19.2 (2026-07-31)

- **Two people acting at the same moment now get one answer, not two.**
  A manager's move can no longer overbook a team against an invitation
  accepted in the same instant: the move engine and the team pages had
  been taking different locks, so neither waited for the other, and both
  could believe the last seat was theirs.
- **A leave confirmed on a page that has gone stale is refused.** A
  leader whose group page was open while the team was submitted could
  still confirm a member's departure, shrinking a team already sitting
  in the guide's queue. The team is now read at the moment of the click,
  and the leader is told the team is no longer forming.
- **A guide can no longer release a team a coordinator has just
  re-frozen.** The check used to be made against the page the guide had
  open, so a re-freeze that landed while they read it was invisible.
- **Answering the same approach twice now refuses the second answer.** A
  double click, or two tabs, could leave a guide assigned by the accept
  while the approach - and the team leader's notification - said
  declined.
- **Two coordinators granting the same exception at once produce one
  exception, not two.** Duplicates created before this release are
  merged on upgrade. *Corrected in 1.20.0: as shipped, this merge kept
  the older row of each pair whatever its status, which could delete
  the exception actually in force and keep a parked twin — see the
  1.20.0 notes above.*
- **A large auto-grouping run no longer freezes the rest of the
  activity.** Its notifications used to go out while the run still held
  the activity, so every invitation, team creation and move made during
  a big sweep waited behind the mail.
- **A student in more than one team now says which team they would
  leave.** Where the membership limit allows two teams or more, asking
  to join another used to pick one of the student's current teams for
  them - and the two databases could pick differently. The request form
  now asks, the answer is shown to the leader deciding, and if the
  limit has room the student can instead keep every team they are in
  and join the new one as well. A team the student has left in the
  meantime is refused by name at answering time rather than failing
  with an error nobody could act on.
- **A team whose guide is already over their team limit is no longer
  force-approved when the decision window lapses.** It waits in the
  queue, exactly as it would if the guide had clicked Approve
  themselves. Reassign the team, or raise that guide's limit, and the
  next sweep picks it up.
- **A forced approval and the exception that explains it now stand or
  fall together.** If the approval cannot go through, no exception is
  left behind on the team - previously a team could be left holding a
  relaxed minimum size or a quota waiver nobody had granted it.
- **The nightly forced-approval sweep now works in batches and picks up
  where it left off**, and grades are republished once per activity
  instead of once per team. A single broken activity no longer stops
  every other activity's sweep.

## 1.19.0 (2026-07-30)

- **The guide decides where the guide is looking.** A team awaiting a
  guide always had Accept and Return - behind the Review link, one click
  away from the queue the guide was reading. Both actions are now in the
  queue row itself, Review beside them for reading the proposal first.
  When the gate will not allow an approval the row says so, with the
  gate's own reason, instead of finding out after a click. And a decided
  team no longer vanishes: it stays at the head of the queue with a
  greyed button naming what was done.
- **A student can ask to join another team.** They pick the team and say
  why; the target team's **leader** answers. That is the whole approval
  while a team is still forming - no staff involved. A **Group
  Coordinator can answer any request**, for an absent leader or a
  contested case. Acceptance runs the existing move engine, so the
  composition rules, the seat plan, the locks and the audit trail are
  the ones a coordinator's move already goes through: a request that
  would break the target team is refused at acceptance, naming the rule,
  and stays open. An unanswered request can be withdrawn.
- **A guide can release their own team.** Until now only editing
  teachers and coordinators could unfreeze, so every ordinary change to
  a settled team was staff work. A guide may now release a team they
  guide - but only while no editing teacher or coordinator has enforced
  the freeze, which is recorded when the freeze happens. After a staff
  freeze the guide's release is refused and the unfreeze request is the
  way through, which is what a staff freeze is for. Teams already frozen
  when this arrives count as staff-enforced, since they were frozen
  under the old rule.

## 1.18.3 (2026-07-30)

- **The composition checker called a perfectly ordinary rule set
  impossible.** "Exactly two members from one school" and "at least
  four schools represented" in a team of five was reported as needing
  six members, with a red warning telling the teacher to resolve it
  before students hit an impossible wall. There was no wall: five
  members satisfy it, because the two pinned members supply one of the
  four schools between them. The checker had been adding a distinct
  rule's count of VALUES to a value rule's count of MEMBERS as though
  they were the same number. It now works out the fewest members the
  rules can actually be met by - the pinned members, plus one more for
  each required value they do not already cover - and a rule asking for
  more distinct values than a team can hold members says so in its own
  words.

## 1.18.2 (2026-07-30)

- **No team picker lists every team either.** The move form carried two
  selects holding every team in the activity - three thousand options
  on one form at the fifteen hundred teams this is built for - and the
  overrides page was worse: it built EVERY possible target before
  rendering, which at user scope means every enrolled student, ten
  thousand of them. A client-side autocomplete does not help with that;
  the browser still has to render each option before it can hide one.
  Both now search server-side, matching on **team name or project id**,
  because staff work from whichever they have in front of them. Neither
  page builds a full list any more: they load only the target already
  chosen, so editing still shows what is being edited.

## 1.18.1 (2026-07-30)

Three things the demonstration screenshots caught that the test suite
could not:

- The request queue's own description still said it carried
  composition-change and unfreeze requests. It carries team-limit
  requests too, and now says so.
- A team-limit request was listed under a **Group name** column, which
  it can never have - it is about a guide and a number. The column is
  now named for what it holds.
- On the assignment queue the **Assign** button rendered before the
  picker it acts on, because the enhanced control is built alongside
  the element it replaces. The picker now sits in a wrapper of its own,
  so the control comes first and the button after it.

## 1.18.0 (2026-07-30)

- **No control lists every guide any more.** A school running 1500
  guides could not use a dropdown, and the assignment queue carried one
  on every row - fifty rows of fifteen hundred options on a single
  page. Every guide picker now searches instead: the assignment queue
  (both tabs), the team's submit-to-a-guide control, and the handover
  nomination. Each result shows the guide's department and current
  load, so the choice can be made without opening another page. The
  approach-a-guide chooser, which is a comparison rather than a lookup,
  keeps its table but gained a filter and paging. In students-approach
  mode the load stays hidden from the teams choosing, as it has been
  since 1.16 - the staff assigning work still see it. Note that these
  pickers need JavaScript, as Moodle's own user selectors do.
- **A guide's requests are one queue.** Teams approaching them, and
  handovers proposed to them, used to sit in three different places on
  one long page. There is now a request queue of their own - sortable,
  filterable, paged and downloadable.
- **A guide can ask for a higher team limit.** The request goes to the
  Group Coordinators as a ticket like any other, carrying the number
  asked for. A coordinator grants it in one action, which raises the
  limit and closes the request together, or declines it with a reason.
  A request nobody has picked up can be taken back.
- **Group Coordinators are appointed the way Moodle appoints roles.**
  A participants-style table - filtered to the course's non-editing
  teachers by default, sortable, paged, searchable and downloadable -
  with appoint and remove on each row. Appointing one or two people no
  longer needs a spreadsheet. The bulk upload stays, and now offers a
  **sample file in CSV and Excel** so nobody has to guess the format.
- **Guide loads is a report.** It gained a name filter, a has-room
  filter and the standard download selector, on top of the paging and
  sorting it got in 1.17.
- **Tables no longer stack.** Wherever one table sat under another it
  is now a tab: the manager's page (teams, awaiting a guide, changing a
  guide, guide loads), the guide's page, the coordinator page, the
  approach page, the analytics page and the departments page. On the
  manager's page in particular the assignment tab row itself used to be
  below the fold, which made three lists easy to miss entirely.

## 1.17.0 (2026-07-30)

- The format an editing teacher sets now governs the **project id**,
  not the group name - the earlier setting named the wrong thing. An id
  is built from {prefix}, {course} and {number}, so it can read
  MDP-COURSE-0042 or MDP/0042 as the school prefers. An activity that
  says nothing keeps the shape it has always issued, and ids already
  given out are never rewritten. Group names still have to be unique
  across the course.
- Students-approach mode is now how a new activity starts, and leads
  the settings; team listing and guide volunteering move to the foot of
  the form. Existing activities keep whatever they were set to.
- **Group Coordinators can be appointed in bulk** from a list of
  usernames or email addresses. The upload is checked and reported
  before anything happens, a person must already be enrolled in the
  course (enrolling them is an option), and the file either lists
  changes or replaces the whole list. Appointments and standings-down
  are logged and the people told.
- **Coordinators have a dashboard of their own**, and may now grant
  exceptions - though never to themselves, and never on a team they
  guide, are lined up to guide, or belong to.
- **A team can approach a guide** without either party seeing the
  other's address. The team sees each guide's name, department,
  sub-department and current load; the approach travels as a message
  built from a template; the guide reads the team's proposal on a page
  of their own and accepts or declines, with or without a reason. A
  team may approach a set number of guides.
- **Administering a large enrolment**: teams awaiting a guide, teams
  whose guide may change, and guide loads are now three tabs, each
  sortable, filterable and paged. Group anomalies have their own tab in
  the flagged report rather than sharing the students tab.
- **Notifications read like something a person sent** - greeted by
  name, the news first, the link as a button, and a signature saying
  which activity in which course sent it. Every message people actually
  read was rewritten to suit.
- A queue worker no longer sees the requests they filed themselves.


## 1.16.0 (2026-07-29)

- Students-approach mode: a new activity switch stops guides from
  volunteering capacity or expressing interest in teams. The
  initiative belongs entirely to the students, who pick any guide
  from the full list (no load figures are advertised) and submit
  their group; once the chosen guide approves, the approval is
  binding on the students - only a manager can change the guide
  afterwards. The switch requires expressions of interest, guide
  volunteering and guide-first mode to be off, and the landing page
  states the ground rules to everyone.
- Ticket queue: composition-change requests (from the assigned
  guide of a firm or frozen team) and unfreeze requests (from the
  guide or the team leader of a frozen team) now queue sequentially
  on a new tickets page. A manager or coordinator takes a ticket up
  exclusively - two people cannot claim the same ticket, even
  clicking at the same instant - and resolves, declines or releases
  it with a note. Duplicate live requests are refused, requesters
  are notified at every step (new "tickets" message provider), and
  a direct unfreeze auto-resolves the team's open unfreeze ticket.
  A manager can also release a ticket somebody else is holding, so a
  request taken up by a colleague who has since left the course
  cannot sit there for ever - which would otherwise have blocked the
  team from ever asking again.
- Group name format: the editing teacher can require every new group
  name to match a pattern, with an example shown on refusal. Group
  names are now unique across all instances of the activity in the
  course, whatever the format. Existing names are never rewritten.
- New "Group Coordinators" role (created on install/upgrade,
  assignable at course or activity level to non-editing teachers)
  carrying a new "coordinate" capability: freeze and unfreeze teams
  and work the ticket queue, reached from their own button on the
  activity page. For accountability, a coordinator can never act on
  a team they guide, are nominated to guide, or belong to - another
  coordinator or a manager must take those. They can still serve as
  guides everywhere else. The conflict-of-interest rule restrains
  this new authority only: everyone who could freeze or unfreeze
  before this release still can, including a team's own guide.
- Backup and restore carry the new settings and the ticket queue;
  the privacy provider exports and deletes ticket authorship.


## 1.15.3 (2026-07-28)

- A team's own members now see the composition values on their roster:
  the school and programme columns follow the same audience as the
  mobile column - staff, the guide, and the team's confirmed members -
  so a leader can see at a glance which member fills which seat.
  Students outside the team still see neither.


## 1.15.2 (2026-07-28)

- The coordinator's move form now searches this activity's own
  participants, authorised by the plugin's manage capability in the
  activity. Previously it used Moodle's site-wide user selector, which
  requires "view full user information" at site level - a capability a
  coordinator holding their role inside one course never has, so the
  form could not be used at all on a stock site. Each result also
  shows the team the student is currently in, or that they have none.
- Pinned in tests: a move OUT of a frozen team removes the member from
  the mirrored course group as well, so the course's own group data
  follows the roster in both directions.


## 1.15.1 (2026-07-28)

- The number at the end of a group id has a configurable width: the
  new "Group id digits" setting takes 2 to 10, so ids read
  MDP-COURSE-042 or MDP-COURSE-000042 as the school prefers. A number
  too large for the width keeps all its digits rather than being cut
  short, and like the prefix the setting stamps groups created
  afterwards only.
- A course whose short name carries no letters or digits now names its
  group ids from the course full name instead of falling straight to
  the course number.


## 1.15.0 (2026-07-28)

- Frozen teams stay true in Moodle's own groups: the mirror already
  followed every manager move, freeze and unfreeze; now a deleted user
  account also leaves every roster immediately, with frozen teams
  re-snapshotted so an unfreeze or repair can never resurrect the
  ghost. Out-of-band edits to the core group are still reported as
  drift, never overwritten.
- Good-neighbour membership audit: freezing is the moment a team is
  pushed into the course's groups and groupings, so a roster carrying
  a member over their membership cap (possible only when caps were
  lowered after people joined) is refused at that gate, every manager
  is notified with the member names and counts, and the flagged
  report shows the condition proactively. The plugin never raises a
  cap by itself - that decision stays with the manager, via the
  activity setting or a per-user override.
- The group id prefix (the SSA in SSA-COURSE-0042) is now an activity
  setting: 2-8 letters or digits, upper-cased, applied to groups
  created after the change; existing ids are never rewritten. The
  mirrored core group's name prefix remains the module ID number (or
  activity name) from the common settings.


## 1.14.1 (2026-07-28)

- Measured at 10,000 students on the maintainer testbed
  (docs/tools/scale_scenarios.php, findings in
  docs/audits/rca-scale-10k.md): the manager team table now preloads
  its seat counts (a 1,900-row export drops from ~3,800 queries to 3),
  and guide loads batch their volunteering and commitment lookups
  (200 guides drop from ~600 queries to 5). Same numbers, same
  precedence, pinned by equivalence tests; every capacity gate keeps
  its per-guide locked path.


## 1.14.0 (2026-07-27)

- Guide handover: an assigned guide leaves a submitted, firm or frozen
  team only by nominating another guide with free capacity, and only
  that guide's acceptance — capacity re-checked at that moment —
  completes the exit. Proposals can be declined or cancelled; the
  leader and both guides are notified; a team is never left guideless.
- Managers can reassign the guide of any submitted, firm or frozen
  team from the dashboard's new reassign section; the change is logged,
  everyone affected is told, and any pending handover is superseded.
- Mobile-number consent: each student decides on their landing page
  whether leaders and teammates may see their number. Staff with full
  view always see it; every other surface — rosters, the guide's
  member drill-down, WhatsApp links, exports, the CSV round-trip —
  honours the choice, and a caution notice reminds viewers numbers are
  shared in confidence.
- Guide waitlists: an optional per-team cap on open guide interests;
  guides see their first-come-first-served queue position (only
  "first in line" or "queued" when sequential reveal is on) and their
  remaining picking bandwidth right where they pick.
- Moving people is no longer expert-only: staging errors return to the
  form with the reason instead of a fatal page, a blank source is
  inferred or refused (never a silent second membership), staged moves
  can be edited and restaged, red rows start unticked and the set
  error names the first offender, cancel sits in the row, rosters and
  reports link straight into a prefilled move form, and the toolbar
  says "Move students".
- New reports: full teams without a guide and teams whose leader is no
  longer an active participant are flagged; under-capacity flags list
  the member names; a new group grid shows one row per team — guide
  first, then each member's last name in its own column — filterable
  and exportable.

## 1.13.0 (2026-07-27)

- Leaving a forming group is always possible: the minimum size gates
  submission, not membership, so a team at the minimum can shrink to
  repair its composition.
- Admission feasibility gate: inviting or accepting a member who would
  make the seat plan unreachable within the maximum group size — or who
  breaks a counting-rule maximum, which adding members can never repair
  — is refused with the reason, so a team can no longer fill itself
  into a dead end. Quota-exempt overrides bypass the gate.
- Submit to guide is always visible to the leader of a forming team:
  the button disables with the blocking reason beside it and enables
  when the team complies; a declined invitation stays visible on the
  leader's page instead of vanishing.
- Membership capacity is now honoured on every path: creating a group,
  being auto-grouped or being moved by a manager auto-declines the
  student's rival pending invitations with a message to each inviting
  leader (previously only accepting an invitation did); staged-move
  sets validate the membership cap jointly across the whole set.
- Guide capacity is race-proof: submitting and assigning serialise per
  guide (the same lock the pick-that-team flow uses), re-assigning the
  guide a team already has no longer falsely refuses at capacity, and
  the decision-window sweep no longer auto-approves teams that have no
  guide to stand in for — they stay in the manager queue.
- Manager move verdicts now evaluate the seat plan, not only counting
  rules; a move whose successor is no longer a confirmed member of the
  source team refuses at commit; a successor promoted by a move is
  notified and the transfer is logged like any other leadership change.
- Group deletion notifies every released member.
- The pick-that-team page is a paginated, filterable, sortable table
  built for thousands of listed teams, first come first served by
  default; with sequential reveal on, browsing guides no longer see the
  interest queue depth the leader cannot see.
- Every report download filename carries its generation moment; the
  attribute batch loader chunks its queries at scale.

## 1.12.0 (2026-07-27)

- Seat-plan fix: with the overlap tick off, a member whose value in ANY
  attribute was consumed by an earlier seat rule is excluded from later
  seats — after "2 with Department Computer", a third Computer student
  can no longer fill a distinct-sub-department seat.
- One vocabulary for composition: the page is "Composition", counting
  rules are "Counting rules" and the template is the "Seat plan" with
  "seat rules", in every heading, intro, label and clash message.
- The seat-rule form announces its mode: "Editing seat rule N" when
  editing, "Add seat rule" otherwise.
- The manager dashboard's state filter sits on one line with its button.
- Group size cells read plainly: "5 of exactly 5", "3 of 2 to 6,
  1 invited".
- Every report download is suffixed with its generation moment
  (_YYYYMMDDHHMMSS), so successive downloads never overwrite each other.

## 1.11.1 (2026-07-27)

- The team member drill-down shows first and last name separately, the
  mobile number beside its WhatsApp link, and one sortable, filterable
  column per composition dimension the activity uses, so a guide sees
  how each member satisfies the seat plan.
- The shared proposal is visible immediately on the review page: PDFs
  embed in place and images render in place, with the download link
  kept.

## 1.11.0 (2026-07-27)

- Pick that team: a leader may list their forming team, guides browse
  listed teams and express interest with rich-text remarks, and the
  leader accepts or rejects each interest, so the team always chooses
  its guide and the first contact stays inside Moodle. Accepting
  pre-assigns the guide, auto-declines every other pending interest,
  and the submitted team goes straight to that guide.
- Manager switches govern the flow: a leader-response window after
  which an interest times out and the guide may pick again, a cap on
  each guide's open interests, sequential first-come-first-served
  reveal of interests to the leader, and whether guides can see who
  else is interested. A pre-assigned guide can step out of a forming
  team, which relists it.
- The guide dashboard gained four stat cards with drill-downs: teams
  guided, interests awaiting a leader, timed out, and declined. The
  interest list opens each team's leader, topic and remarks, and its
  members with mail, WhatsApp and whole-team mail links.
- Formation analytics page for managers: funnel counts, median times
  from creation to submission and approval, listing to first interest,
  and interest to response, all exportable.
- The activity settings form is regrouped into named sections, the
  return comment is rich text, staged-move rule verdicts show their
  reason as visible text, and the plugin ships a real stylesheet with
  a distinctive card system.
- Interests are covered by privacy export and delete, backup and
  restore, and the events log; bulk mail tasks continue past a failing
  recipient and report what could not be sent.

## 1.10.0 (2026-07-26)

- The flagged report no longer issues thousands of queries on a large
  course. Per-group membership counts and composition compliance are
  now answered set-based: measured on 10,000 students in 2,000 groups,
  the anomaly scan fell from 4,002 queries to 6 and the compliance
  sweep from 10,001 to 6, with identical results.
- Bulk nudges are handed to a scheduled task instead of being sent
  inside the web request, and the defaulters tab gained a per-row
  action to place a student.
- The missing-attributes and group-anomaly lists became sortable,
  filterable, paginated tables, the last two plain lists in the plugin.
- The activity page's staff group panel is capped with a count and a
  link to the full listing rather than rendering every group.
- Validation messages for the membership minimum and the penalty fields
  render properly instead of showing the raw text in brackets.
- Guide picker labels are one translatable string, participant names
  follow the site's configured name format, and sorting is locale aware.
- Each tab of the flagged report explains itself instead of repeating
  the anomaly notice, and informational counters no longer look like
  disabled buttons.

## 1.9.0 (2026-07-26)

- Fixed the flagged report's defaulters tab, which failed with a
  database error both on screen and on download because its query
  carried one placeholder more than it supplied. A regression test now
  covers the tab.
- Every paginated listing gained a records-per-page selector (10 to
  200), remembered per user across pages and visits.
- The override form accepts a guide capacity of zero, the documented
  "always full" state that the rest of the plugin already honoured.
- Replaced three list scans that cost time proportional to the square
  of the class size, in the flagged report, the auto-grouping engine
  and the deadline reminder.
- Scale work for large cohorts: the nightly gradebook recomputation is
  batched instead of querying per student, roster and attribute
  downloads stream instead of loading whole datasets, historical
  auto-grouping exports chunk their lookups, and bulk nudges can be
  handed to an adhoc task rather than sent inside one web request.

## 1.8.3 (2026-07-26)

- The invite, nominate and submit forms on a group page each name their
  own action button, so every button id is both unique and the same on
  every visit. Previously the ids depended on which other forms the
  page happened to render.

## 1.8.2 (2026-07-26)

- Each form on a group page now renders its own action button id. All
  three previously used id_submitbutton, which is invalid HTML and made
  scripts and assistive technology address the wrong form.

## 1.8.1 (2026-07-26)

- The flagged report's explanation names all four download formats
  instead of only CSV.

## 1.8.0 (2026-07-26)

- The flagged report's guides tab shows each guide's current load beside
  their pending group, so managers can see which guide is the
  bottleneck, and the guide's name opens their full workload for the
  activity with its own multi-format export.
- Managers can message every listed defaulter, or every guide holding an
  overdue decision, in one confirmed action. Recipients are
  de-duplicated, so a guide with several overdue groups receives a
  single message naming the count.
- Guides can switch their notifications to a daily or weekly digest
  instead of one message per group. The preference is per person and
  off by default; queued items are covered by the privacy provider and
  by backup and restore.
- New manager page listing every auto-grouping run with its outcome and
  decision log, exportable both as run summaries and as one row per
  formed group.

## 1.7.1 (2026-07-26)

- Fixed the 1.7.0 upgrade: the new volunteer table declared an index on
  userid that its own foreign key already provides, which stopped the
  upgrade on existing sites. Fresh installations were unaffected.
- Restores the userid index on sites upgraded through the interim
  build, so installed and upgraded schemas match exactly.

## 1.7.0 (2026-07-26)

- New optional setting, guide volunteering: when enabled, a guide only
  becomes available for new assignments after declaring a capacity, up
  to the activity's maximum groups per guide. Guides who have not
  volunteered, or who have volunteered for zero groups, are
  unavailable for new assignments; every existing enforcement point
  and picker inherits this through the override resolver, so an active
  manager guide-scope override always takes precedence over the
  volunteered number.
- Reducing a volunteered capacity below the guide's current load never
  unassigns anything, matching the plugin's established grandfathering
  pattern; the guide dashboard shows the grandfathered note when this
  applies.
- The guide dashboard gained a small form for declaring or updating a
  capacity, with a success notification and a refusal when the chosen
  number is out of range.
- Volunteered capacity is personal data: covered by the privacy
  provider (metadata, export, all delete paths) and by backup/restore.
- Manager override target pickers still list guides who have not
  volunteered, so a manager can grant such a guide capacity directly.

## 1.6.2 (2026-07-26)

- The flagged report's defaulters, guides and quota tabs are rebuilt
  on Moodle's flexible_table/table_sql instead of hand-rolled sort
  links and PHP array sorting, giving them the native table look and
  SQL-side sorting and paging.
- The guides-pending tab joins the guide's name in the same query
  instead of one core_user::get_user() call per row.
- The students tab, its mustache template and the multi-format export
  flow (ODS/Excel/CSV/TXT, admin default, raw values) are unchanged.

## 1.6.1 (2026-07-26)

- Exports feed raw values to the dataformat writers, so names such as
  O'Brien or R&D no longer arrive double-encoded in spreadsheets.
- The notification-preferences page names the automatic-grouping
  message provider instead of showing a placeholder.
- Automatic-grouping notifications are sent only after the database
  transaction commits.
- Guide reminder markers are reset through the preferences API so no
  stale cached marker survives.
- Export file names pass through clean_filename() and the guide
  dashboard's date filter rejects impossible calendar dates.

## 1.6.0 (2026-07-25)

- Composition-template slots can be edited in place, not just added
  and deleted.
- Report downloads (flagged tabs, guide dashboard) moved to Moodle's
  dataformat writers and now offer OpenDocument, Excel, CSV and
  tab-separated TXT, with the default format chosen by the site
  administrator in the new plugin settings page; the earlier CSV
  encoding problem in Excel is gone.
- The flagged report's tabs gained a name filter and sortable column
  headers alongside the existing pagination.
- The guide dashboard's approved-since filter uses a native calendar
  date input and the server validates the date before applying it.
- The quota-rules and composition-template introductions now explain
  the difference between counting rules and the seat plan.

## 1.5.2 (2026-07-25)

- Auto-grouped students are notified of their placement and managers
  receive the run summary (new autogroupresult provider).
- Guide reminder markers reset when a fresh review cycle starts, so
  resubmitted groups get their 50%/90% reminders again.
- The auto-approval sweep defers (with a log) when its recorded relief
  lands on a pending guarded override instead of approving unexplained.
- Auto-formed group names use a monotonic sequence and a localised
  date; an uninstall notice states that frozen core groups remain.

## 1.5.1 (2026-07-25)

- Auto-approval past the guide window now records any size or quota
  relief as a group-scope override before approving, stamps the event
  as automatic and notifies managers; guides receive escalating
  reminders at 50% and 90% of their decision window.
- The privacy provider exports and deletes proposal documents.
- Auto-grouping is a three-state mode (off, manual only, manual and
  automatic at the cutoff); previously enabled activities migrate to
  the full mode. Auto-formed group names are sequence-unique.

## 1.5.0 (2026-07-24)

- Attribute CSV import gains update modes (override with file, or fill
  missing only) plus admin default values for empty cells, both shown
  in and surviving the mandatory preview.
- Guide picker overrides: hide a capability holder from every guide
  picker, or set an explicit guiding cap of 0 (visible, always full).

## 1.4.3 (2026-07-24)

- Flagged report reorganised into paginated tabs (students/anomalies,
  defaulters, guides pending with overdue deadlines, quota-failing
  groups), each downloadable as CSV; composition clash detector on
  the quota page.

## 1.4.2 (2026-07-24)

- Ineligible invitation candidates stay listed with their refusal
  reason; leaders edit title and brief while forming; slot template
  form uses the vocabulary picker; guide dashboard date filter is
  timezone-safe with a CSV download; core grading section (category,
  grade to pass) replaces the bare grade field.

## 1.4.1 (2026-07-24)

- External audit fixes: cross-group cap checks race-safe under the
  activity lock, guarded-override rechecks fire on move commits and
  nightly, course reset support, per-row CSV length guards, defaulter
  penalties honour date overrides, core group names clamped, and more.

## 1.4.0 (2026-07-24)

- Guide decision window with an auto-approve switch: submitted groups
  undecided within the window are automatically counted as accepted.
- Minimum memberships per student: defaulters are listed on the
  flagged report and penalised per missing group after the due date.
- Incomplete-group penalty with a teacher-set leader majority share.
- Sequence-of-joining gradebook decomposition: guide-awarded group
  marks and every penalty bind to the student's groups in joining
  order with stepwise clamping; the full breakdown is published as
  gradebook feedback.

## 1.3.0 (2026-07-24)

- Slot-based composition templates (booked members, value/distinct
  matches, overlap control) gating compliance with the classic rules.
- Programme attribute with its own vocabulary; admin-level CSV ingest
  now auto-creates missing departments, sub-departments and
  programmes; downloadable blank CSV templates per programme.
- Project proposal upload with a per-activity mandate; guide rich-text
  notes on the review page.
- Defined behaviour for size changes after freezing: grandfathered
  groups, flagged-report listing.

## 1.2.0 (2026-07-24)

- Guarded reduction overrides (pending until blockers clear), explicit
  leader-replacement consent on moves, AJAX user selectors at scale,
  guides and roster tables, seat location attribute, bulk department
  updates.

## 1.1.0 (2026-07-24)

- Pre-defined departments: a site-wide category tree in the
  course-categories format (multiple levels) now backs the department
  and sub-department attributes; drop-down selection in the editor,
  strict validation in the CSV importer, automatic seeding from
  existing data on upgrade, and a new admin page to curate the tree.
- Notification templates: a per-activity *Notification templates* page
  lets editing teachers customise the subject and body of every
  message kind, with reset-to-default; overrides are included in
  course backups.
- Wording: numeric ranges are written in words ("2 to 4") instead of
  an en dash.

## 1.0.1 (2026-07-24)

- Notification templates: every message now receives the recipient
  placeholders `{$a->firstname}`, `{$a->lastname}`, `{$a->fullname}`
  and `{$a->url}`, enabling full personalisation through Moodle's
  Language customisation. Invitations additionally expose
  `{$a->expirynote}` and the invitation body was rewritten to address
  the invitee by name, state the expiry date and explain the
  forming-vs-frozen change rules.

## 1.0.0 (2026-07-24)

First stable release. Complete implementation of the binding
specification: invitation-only group formation with reserved seats and
acceptance cascades; five override-resolved numeric limits with
position displays and reasons; leadership succession; guide review
with load limits; plugin-local participant attributes with admin CSV
ingest; prioritised composition quotas with a live deficiency panel;
the central override subsystem (P1–P16 precedence, fully tested);
transactional jointly-validated staged moves; the per-group penalty
ledger and cumulative gradebook integration; freeze/unfreeze with
snapshots, drift reporting and bulk operations; deterministic
auto-grouping with rule relaxation; flagged reports; events,
messaging, scheduled tasks, privacy API and backup/restore.
Built slice-by-slice with per-slice PHPUnit + Behat gates on both
MySQL/MariaDB and PostgreSQL (audit trail in docs/audits/).
