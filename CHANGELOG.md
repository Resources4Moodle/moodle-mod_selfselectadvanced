# Changelog

## 1.20.1 (2026-08-01)

> `$plugin->release` still reads `1.20.0`. The code comments and this
> section name 1.20.1 because that is the release these changes belong
> to, but the release string is owned elsewhere in this cycle and is
> deliberately not touched here.

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
