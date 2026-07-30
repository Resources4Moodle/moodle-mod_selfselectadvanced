# 1.19.0 — work order of 2026-07-30 (third)

Two items from the maintainer working the live site, and one of them
turned out to be half a misunderstanding worth recording.

## A. The guide's accept, where the guide is looking

**The Accept button was never missing.** A team awaiting a guide shows
in that guide's *Awaiting my review* tab, each row carrying a **Review**
link, and the review page has *Approve* and *Return with a comment* —
verified against the live team Beta_1, where `can_approve()` returns
YES. What was missing is an action **in the queue itself**: the guide
saw a list and a link, not a decision.

So the queue row gains **Accept** and **Return**. Review stays, because
reading the proposal before deciding is the normal case and the return
comment is mandatory.

And the part of the item that was plainly right: **the button must grey,
not vanish.** Today a decided team simply leaves the queue, so the guide
cannot see what they just did. A row that has been decided stays for the
rest of the page view with a disabled button naming the outcome.

## B. Moving between teams, and who says yes

The maintainer's rule, in their words: *self-service till leader
accepts; the leader approves before a guide is chosen; once fixed, the
guide can release for team changes, and it falls to the team leader to
approve the move and re-compose the group; the same guide takes over if
all the rules are working; the coordinator role can approve in all
cases.*

That gives one workflow with a gate that moves as the team settles:

1. **A student asks to join a team.** They pick the target and give a
   reason. Nothing is staff work.
2. **The target team's LEADER accepts or declines.** That is the whole
   approval while the team is still forming — no coordinator needed.
3. **Once the team is settled**, a request cannot simply be accepted:
   the team is frozen, and its **guide releases it** first (C below).
   Then the leader approves exactly as before, and the team
   re-composes.
4. **The guide stays.** A re-composed team keeps the guide it had, so
   long as the composition rules still pass — releasing a team to admit
   somebody is not a reason to lose its guide.
5. **A coordinator may approve any of them**, at any point, which is
   the escape hatch when a leader is absent or the case is contested.

Every accepted request runs through the **existing move engine** —
`moves::stage()` then validate and commit — so the composition rules,
the seat plan, the locks and the audit trail are the ones already in
place. A request is a `selfselectadvanced_move` row in a new
`requested` status, so nothing about committing a move is duplicated.

## C. A guide releasing their own team

Today a guide can freeze but **not** unfreeze: only an editing teacher,
and the coordinator role, hold `:unfreeze`. A guide must file a request
and wait.

The maintainer's rule: *a guide unfreezes until an editing teacher or
coordinator enforces a freeze; after that a request has to be posted.*

So a freeze now records **whether staff enforced it**. A guide may
release a team they guide while that flag is clear. Once an editing
teacher or a coordinator has frozen it, the guide's release is refused
and the existing unfreeze request is the only way — which is exactly
what a staff freeze is for: to hold.

## What this is not

A student cannot move themselves. Nothing here lets anybody bypass the
composition rules, the seat plan, or the caps: acceptance runs the same
validation a coordinator's move runs, and a request that would break
the target team is refused at acceptance with the reason.

## What the round found

**The Accept button existed; the report was still right.** It sat
behind a Review link, one click from where the guide was looking, and a
decided team vanished from the queue without a word. Both halves of the
item were worth doing even though the premise — "there is no accept
button" — was not literally true. Checking the live gate
(`can_approve()` returning YES on Beta_1) before building saved
inventing a mechanism that already worked.

**A message provider that was never registered.** The join-request
notifications named a provider `joinrequests` that did not exist in
`db/messages.php`. Moodle does not fail on that — it emits a debugging
line and **silently sends nothing**, so the leader would never have
heard that somebody asked to join. Only PHPUnit's *Unexpected
debugging() call* surfaced it; no test asserted on the message, and no
page would have looked wrong.

The rule that follows: **a new `notifier::send()` channel means a new
entry in `db/messages.php`, a `messageprovider:` string, and a version
bump so it registers.** Three things, not one.

**A leader is a member of their own team.** The general "you are
already in that team" answer fired before the precise "you already lead
that team", so the more useful message was unreachable. Order the
refusals from most specific to least.

**And then I broke the rule I had just written.** The provider was
added to `db/messages.php` AFTER the version had already been bumped to
2026073090 and both test databases had been built at that version. No
upgrade runs for an unchanged version, so `message_update_providers()`
never fired and the registry still held 23 providers with
`joinrequests` absent — the notifications went on silently failing
through a full green-looking test run.

This is the same shape as the 1.17 finding about adding a db artefact
to an already-installed version, and it generalises past the database:
**anything Moodle registers at upgrade time — capabilities, message
providers, scheduled tasks — needs a version the site has not yet
installed.** Checking the file is not enough; check the registry:
`SELECT name FROM {message_providers} WHERE component = 'mod_...'`.
