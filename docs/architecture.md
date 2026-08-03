# mod_selfselectadvanced — Architecture Plan (Gate 1)

**Version:** 1.1 — **gate 1 APPROVED** (review of 2026-07-24, `/var/www/html/Temp/gate1-review-comment.md`) with blocking items B1–B5 folded in below and S1–S7/M1–M4 recorded against their owning slices in §17. Review-item markers `[B..]/[S..]/[M..]` appear at each amended spot.

**Spec:** `/var/www/html/Temp/moodle-labgroup-plugin-prompt(2).md` (referred to below as "spec"). The spec text uses the working name `labgroup`; the user renamed the plugin on 2026-07-23. Every occurrence of `labgroup` in the spec is to be read as `selfselectadvanced`.

---

## 0. Identity, naming, and user amendments

| Item | Value |
|---|---|
| Plugin type / location | Activity module, `mod/selfselectadvanced` |
| Frankenstyle component | `mod_selfselectadvanced` |
| Display name | **Group self-selection (Advanced)** |
| Table prefix | `selfselectadvanced` (main instance table = module name, per Moodle rule) |
| Capability prefix | `mod/selfselectadvanced:` |
| Event namespace | `\mod_selfselectadvanced\event\` |
| CSS class prefix | `selfselectadvanced-` |
| Lang component | `mod_selfselectadvanced` |
| Compatibility | Moodle 4.5 LTS & 5.x · PHP 8.1+ · MySQL/MariaDB & PostgreSQL (XMLDB only) |
| Licence | GPL v3+ |

**Naming constraint (verified in 5.2 source):** `core_component::is_valid_plugin_name()` enforces `/^[a-z][a-z0-9]*$/` for `mod` plugins — *no underscores* — so the requested `self_select_advanced` is collapsed to `selfselectadvanced` (user-approved 2026-07-23).

**User amendments accepted into this plan** (supplementing the spec; tracked in §18 traceability as U-rows):

- **U1 — Rename:** component is `mod_selfselectadvanced`; display name "Group self-selection (Advanced)".
- **U2 — Icons:** ship the complete icon set Moodle needs (see §1.3).
- **U3 — Selector search:** candidate selection must match on **first name, last name, or email** in every user selector. **[SUPERSEDED 2026-08-02, 1.20.1 wave 3D — the email half is WITHDRAWN in both states of the contact-privacy switch; selectors match names only. Rationale and the decision that replaced S7 are in A14 below.]**
- **U4 — Ingest format:** attribute CSV input columns are **User Name, First name, Last Name, Gender, Department, Sub-Department, Mobile Number**; `mobile` becomes a fourth plugin-local attribute (not a quota dimension).

**Plan-level decisions** (spec-consistent refinements made here; flagged A-n where the spec left detail open):

- **A1 — Plugin group UID format:** `SSA-{COURSESHORTNAME}-{NNNN}` where `NNNN` is the group's own DB id zero-padded to 4+ digits — plugin-wide unique forever, human-readable, assigned inside the creation transaction (insert → derive uid → update). Mapped 1:1 to spec §6.1's `LG-{courseshort}-{seq}` (D3: distinct from the frozen core group's own Moodle id, mapping stored in `coregroupid`).
- **A2 — Membership rows are unique per (group, user):** re-inviting a user who declined reuses the row (status returns to `invited`); full history lives in events. Makes cap counting and race-locking single-row.
- **A3 — One active succession nomination per group at a time** (transfer or step-out), held on the group row (`successorid`, `successortype`, `timenominated`). A second nomination requires cancelling the first. Keeps §6.4 confirmable and atomic.
- **A4 — Staged-move batches are commit-time selections:** each move row is independent while `pending`; the manager selects any set of pending moves and commits them **jointly** — validation runs against the net post-state of all selected moves together (satisfies §7 "swap commits as a set" without a batch table).
- **A5 — Manager-assigns guide mode:** the leader submits without choosing a guide; the group enters `pending_guide` unassigned and appears in the manager's assignment queue; manager assigns a guide (L5-checked); the guide is then notified and reviews as normal. (Leader-selects mode is the spec's §6.5 default flow.)
- **A6 — Snapshot refresh on staged moves into/out of frozen groups:** a committed move that touches a frozen group updates the plugin roster, the mirrored core group, **and appends a fresh snapshot** in the same transaction. "Restore exactly as frozen" (§5/§12) therefore always restores the latest *plugin-authorised* state; only out-of-band core-group edits are discarded. Snapshots are append-only history; unfreeze restores the newest.
- **A7 — Concurrency control:** every race-sensitive gate (invitation acceptance, guide approval, succession confirm, staged-move commit, freeze/unfreeze, auto-grouping) runs inside `$DB->start_delegated_transaction()` **plus** `\core\lock` named locks taken through `\mod_selfselectadvanced\local\locks`, re-validating all counts on rows read *after* acquiring them. Moodle-native, identical on both DBs. There is **one** global lock order, written in code as `locks::ORDER` and repeated here verbatim:

  | rank | resource | what it protects |
  |---|---|---|
  | 1 | `joinrequest:user:{userid}` | one student's request stream |
  | 2 | `joinrequest:{requestid}` | one request |
  | 3 | `ticket:{ticketid}` | one queue ticket |
  | 4 | `guidecap:{userid}` | one guide's cap request stream |
  | 5 | `override:{scope}:{targetid}` | one override row |
  | 6 | `activity:{activityid}` | activity-wide counts (L3/L4), move sets, autogroup runs |
  | 7 | `eoiguide:{guideid}` | one guide's capacity (L5) |
  | 8 | `group:{groupid}` | one team's roster/state — innermost |

  Two rules, and no others: **acquire in ascending rank, release in reverse**; and **`group:` is the only rank that may stack, and then only in ascending numeric group id**. A new lock resource must add its prefix to `locks::ORDER` **and** to this table in the same edit — an unranked prefix throws `coding_exception`.

  **How much of this is actually enforced, measured rather than asserted (corrected 2026-07-31 after the wave-1 audit — the previous wording claimed two things the tree does not have):**

  - *Ordering.* `locks::acquire()` reports a violation at `DEBUG_DEVELOPER` and nothing more. It does **not** fail a build by itself: Moodle turns an unconsumed `debugging()` into an `E_USER_NOTICE`, and PHPUnit only fails on that when it is run with `--fail-on-notice`. **That flag is now in the repository**: `.github/workflows/moodle-ci.yml` runs `moodle-plugin-ci phpunit --fail-on-warning --fail-on-notice`, so every push, every fork and any CI rebuilt from this repo enforces it — it is not a property of one maintainer's machine. The maintainer's gate passes the same pair (`/srv/ci/ops/ci-plugin-par.sh`, as of 2026-07-31). So an unconsumed ordering violation turns a run red wherever the suite is run from this repository's own configuration. In production `debugging()` is a no-op at any level below DEBUG_DEVELOPER, so ordering is a development-time discipline, not a runtime guarantee. Any test that means to pin an ordering or no-mail property must still say so with an explicit `assertDebuggingCalled()` / `assertDebuggingNotCalled()`; do not rely on the ambient flag alone.
  - *Messages under a lock.* Enforced the same way and with the same limits: `notifier::send()` calls `debugging()` when `locks::held_count() > 0`. The property is pinned explicitly by `assertDebuggingNotCalled()` in `tests/races_locking_test.php` and `tests/races_regression_test.php`; the `--fail-on-notice` flag is the backstop, not the primary detector.
  - *Events under a lock.* **This invariant is a goal, not a fact.** A brace-matched scan of `classes/` at 1.20 finds **22 non-grandfathered event trigger sites lexically inside a lock body**: `api.php` (`group_created`, `group_deleted`), `autogroup/engine.php` (`autogroup_run`), `contacts.php` (`contact_sent`), `eoi.php` (`eoi_created`), `handover.php` (`guide_reassigned`), `invitations.php` (`invitation_sent`/`_accepted`/`_declined`/`_withdrawn`/`_expired`), `override/store.php` (one site dispatching `override_created` **or** `override_updated` through a `$eventclass` variable — grep for the event name alone will not find it — plus `override_deleted`), `state.php` (`group_submitted`, `guide_reassigned`, `group_returned`, `group_approved`) and `tickets.php` (`ticket_filed` ×2, `ticket_closed` ×2, `ticket_claimed`). Almost all of them pre-date 1.20 — the same scan on 1.19.1 (`ec07aaf`) finds **21**, the differences being that `freeze.php`'s `group_unfrozen` moved out and `override/store.php` gained a lock it did not have — so this is a **known backlog owned by T-15/T-16**, not evidence of compliance and not mainly a 1.20 regression. `override_created` and `penalty_recomputed` were measured at `locks::held_count() === 1` at dispatch. The scan is *lexical*: a trigger reached dynamically from inside a caller's lock (`store::recheck_pending()`, called under `respond()`'s `joinrequest:` lock — T-08) does not appear above and is a violation just the same. **Corrected 2026-08-01 (T-08 landed):** `override/store.php`'s `$eventclass` site is no longer among the 22 — `save()` now builds its payload inside the critical section and triggers after its commit AND after its own lock release, so the lexical count reads one lower on the current tree; `override_deleted` is untouched and still inside `delete()`'s lock. `recheck_pending()`'s activation event left the per-row `override:` lock with it, but on the join-accept path it still fires inside `respond()`'s `joinrequest:` lock, exactly as the previous sentence says, and `state::approve_auto()`'s nested `store::save()` still fires inside the CALLER's lock and transaction (T-04's handshake). Neither is T-08's to unwind.
  - *Core group API under a lock — **one** call, and it is named here because the commit message that denies it is immutable.* `e7f2942` claims "the core group calls happen after the plugin's lock is released and its transaction committed, so … an event cannot fire while a lock is held". That is true of every core group call in this plugin **except one**: `freeze::sync_core_group()` acquires `group:{id}` and, when a frozen team has no mirror, calls `mint_core_group()` → **`groups_create_group()`** inside it (`classes/local/freeze.php`, the mint branch). Measured with an observer recording `locks::held_count()` at dispatch: **`\core\event\group_created` fires at `locks=1`**, while `group_member_added` (×3), `grouping_created`, `grouping_group_assigned` and the plugin's own `coregroup_synced` and `group_frozen` all fire at `locks=0`. The in-code comment used to call it "a single insert"; it is not. Core's `group/lib.php` also runs `\core_group\customfield\group_handler::instance_form_save()`, `cache_helper::invalidate_by_definition('core', 'groupdata', …)`, `\core_group\visibility::update_hiddengroups_cache()`, possibly `\core_message\api::create_conversation()`, the `group_created` event (arbitrary third-party observers) and a **`\core_group\hook\after_group_created`** dispatch — two course-wide cache rebuilds plus arbitrary observer and hook code, per mint, under a per-team lock. At 1,500 teams with `BULK_FREEZE_INLINE_MAX = 20` mints per inline request that is the one place this plugin's lock hold time is bounded by other people's code. It stays for now because the lock is what closes the double-mint race between an inline caller and the queued adhoc, and because it runs once per team lifetime with no membership writes and no plugin transaction open; moving the mint outside the lock (a compare-and-set on `coregroupid`, deleting the loser's group) is backlog, and this entry is the record that it is a known cost and not an oversight.
  - What *is* true today: the three events grandfathered in 1.19.2 (`move_committed`, `leadership_transferred`, `join_decided`) are deliberately dispatched under a lock and their only consumers are core logstores; and `penalty_recomputed` — which the 1.20 wave-1 diff moved under a new lock — was moved back out in 1.20 and is now dispatched after the release.

  **The rule for new code is unchanged and binding: do not add an event trigger inside a lock body.** Collect the payloads, release, then trigger — `penalty\ledger::recompute_all()` and `moves::commit_set()`'s deferred notifications are the worked examples. Removing an entry from the backlog list above is welcome in any ticket that already touches the file.
- **A8 — Quota rules live on a dedicated management page** (`quotas.php`, `:manage`), not inside `mod_form` — prioritised rule rows with value pickers fed by ingested attribute values need a table+form page (mod_form keeps the scalar settings). Spec §4.7's "activity settings" placement is preserved logically: the page is linked from the activity's settings menu and manager dashboard.
- **A9 — CSV identity handling (U4):** rows match existing users **by username** (mandatory column); if a row's username is blank and an optional `email` column is present, email is the fallback key (keeps spec §8.1's match-on-username/email intent under the U4 format). `First name`/`Last name` are **cross-check columns**: mismatches against the matched Moodle account are reported as row warnings (row still ingested — username is authoritative). Unknown users → row **rejected and reported** (C11; the plugin never creates users). `Mobile Number` is stored plugin-locally, never written to user profiles, and its DISPLAY is decided by `contactprivacy` (1.20.0): the mobile column follows reach (`:viewall`, the assigned guide, the team's own confirmed members) while the NUMBER in it reaches only a viewer connected to its owner who also has that owner's consent.
- **A10 — Group deletion is a hard delete** (allowed only in `forming`): members/invitations rows removed, notifications sent, `group_deleted` event carries the payload. No tombstones — deleted groups release every counted slot (§4A.3/.5).
- **A11 — Guide return clears `guideid`** (frees the L5 slot per §6.5) and stores the mandatory comment on the group (`returncomment`, latest) — full comment history remains in the `group_returned` events.
- **A12 — Penalty ledger holds one current row per group** (unique `groupid`), recomputed in place (settings save + reconciliation task + approval); every recomputation emits an event. Explicit zero rows are stored for on-time groups (exportable). Grade floor is 0 (no negative grades); ledger keeps the uncapped arithmetic in `basis`.
- **A13 — Auto-group sizing is deterministic** *(corrected per review B1 — the earlier decrement step could breach L2)*: with pool size P and effective band [min,max], take `g = ceil(P / max)`; if `g·min > P`, fall back to `g = floor(P / min)`; if `g = 0` the whole pool is residue (§9.4). Groups are filled up to `g·max` and any remainder goes to residue per §9.4 — no group ever exceeds `max_size`. (min 4 / max 6 / pool 7 → one group of 6 + 1 residue student; pool 24 → four groups of 6.) Members are drawn by seeded shuffle (`mt_srand(seed)`) so a stored seed replays the run exactly.
- **A14 — Selector matching and address display (U3; S7 REVERSED by wave 3D):** the candidate search WHERE clause matches the **full core name-field set** (`\core_user\fields::for_name()` — first/last/middle/alternate, mirroring core participants search [S6]) **and nothing else — never the email address, for any viewer, in either state of the contact-privacy switch** (1.20.1 wave 3D, P-5). MATCHING and DISPLAYING are two questions with two different answers: the address is still *appended to a label* when the switch is OFF and the viewer holds `moodle/site:viewuseridentity` or `moodle/course:viewhiddenuserfields` in course context, per core identity-field rules — showing the address of somebody found BY NAME discloses, it does not confirm a guess. **The S7 decision of 2026-07-24 (matching for all inviters, residual disclosure accepted) is withdrawn.** What it accepted as residual is an address ORACLE: a picker that answers found/not-found for a submitted address confirms which named account owns it, one query at a time, whether or not anything is rendered back — available to a student leader and invisible to any review. A per-activity setting decides what an activity *displays*; it was never a decision about what may be *probed*, so gating the match on the switch (or on the viewer) was the same mistake at two strengths. The UI follows: the invite placeholder is unconditionally "Search by name" (`inviteplaceholdername`), because a box must not promise a match the query will not make. Pinned by `candidateaddress_test`, `external_search_test::test_email_match_gated_when_private` (both switch states, service **and** web service), `contactreach_test` (P-5) and, at browser level, `invitations.feature` "An email address finds nobody in the native selector" (switch off) + `contactprivacy.feature` "The invite picker promises only a name search and matches only names" (switch on). The two staff CSV imports (`coordinatorimport::find_user()`, `attributes\csv_importer`) still resolve an operator-supplied address by exact equality and deliberately do not consult the switch — there the address is an input the operator already holds.

Anything not listed above follows the spec verbatim; §13's D1–C15 remain binding and unreopened.

---

## 1. Plugin surface

### 1.1 File tree (final target)

```
mod/selfselectadvanced/
├── version.php                  # component, version, requires 4.5, supported [405,502]
├── lib.php                      # add/update/delete instance, features, grade item, cm info
├── mod_form.php                 # instance settings (§3 below)
├── index.php                    # per-course instance list (mod plugin requirement)
├── view.php                     # capability-routed landing (student/guide/manager)
├── group.php                    # group page (leader/member view + POST actions)
├── groupedit.php                # create/edit group form
├── guide.php                    # guide dashboard (queue, bulk freeze)
├── review.php                   # guide review page for one group
├── manage.php                   # manager dashboard
├── moves.php                    # staged moves list + commit/cancel
├── moveedit.php                 # stage a move (form)
├── overrides.php                # override list (mode=user|group|guide)
├── overrideedit.php             # add/edit override
├── quotas.php                   # quota rule management (list + reorder)
├── quotaedit.php                # add/edit quota rule
├── ledger.php                   # penalty ledger (view + download)
├── flagged.php                  # flagged students report (unplaced/missing attrs)
├── autogroup.php                # manual auto-grouping trigger + run reports
├── attributes.php               # SITE ADMIN attribute page (CSV import + inline edit)
├── settings.php                 # admin settings tree + external attributes page
├── styles.css                   # minimal, all selectors prefixed .selfselectadvanced-
├── pix/                         # §1.3
│   ├── monologo.svg
│   └── icon.svg
├── db/
│   ├── install.xml              # §2 schema
│   ├── upgrade.php              # versioned from day one (§15.4)
│   ├── access.php               # §4 capabilities
│   ├── messages.php             # §12 providers
│   ├── tasks.php                # §12 scheduled tasks
│   ├── services.php             # external functions (§10.4)
│   ├── events.php               # observer: core user_deleted → attr cleanup [M3]
│   └── caches.php               # MUC: ingested attribute value lists (request/app)
├── classes/
│   ├── activity.php             # instance model (settings + helpers, cached)
│   ├── local/
│   │   ├── api.php              # thin application façade used by pages
│   │   ├── candidates.php       # course-level candidate pool + search (U3)
│   │   ├── groups.php           # group CRUD + membership queries (counting bases)
│   │   ├── state.php            # §5 state machine (transitions + guards)
│   │   ├── rules/gatekeeper.php # §7 every L1–L5/quota/window check
│   │   ├── override/resolver.php# §6 single override-resolution service
│   │   ├── override/store.php   # override CRUD (events, uniqueness)
│   │   ├── quota/evaluator.php  # §8 bucket evaluation + compliance
│   │   ├── succession.php       # §6.4 transfer / step-out
│   │   ├── invitations.php      # send/accept/decline/withdraw/expire + cascade
│   │   ├── moves.php            # §7 staged moves (joint validation, commit)
│   │   ├── freeze.php           # §11 freeze/unfreeze/core-group sync + snapshots
│   │   ├── autogroup/engine.php # §9 deterministic engine
│   │   ├── penalty/calculator.php # §10 penalty math (pure)
│   │   ├── penalty/ledger.php   # ledger persistence + gradebook push
│   │   ├── attributes/manager.php     # attr CRUD + value lists
│   │   ├── attributes/csv_importer.php# A9 validation report ingest
│   │   └── notifier.php         # all message sends (providers §12)
│   ├── event/*.php              # §12 events
│   ├── external/search_candidates.php # autocomplete provider (U3)
│   ├── form/*.php               # moodleforms incl. candidate autocomplete element
│   ├── output/*.php             # renderables + renderer
│   ├── table/*.php              # core-table classes (§13 C12)
│   ├── task/*.php               # 4 scheduled tasks
│   └── privacy/provider.php
├── templates/*.mustache         # §11 UI inventory, core-component partials only
├── amd/src/candidateselector.js # sole custom AMD: autocomplete transport
├── backup/moodle2/…             # §15
├── tests/                       # PHPUnit + generator + behat/*.feature
├── .github/workflows/moodle-ci.yml
├── README.md · CHANGELOG.md · LICENSE
```

### 1.2 lib.php feature declarations

| Feature | Value | Why |
|---|---|---|
| `FEATURE_MOD_INTRO` | true | standard description |
| `FEATURE_SHOW_DESCRIPTION` | true | course-page description |
| `FEATURE_GRADE_HAS_GRADE` | true | §10 grade item |
| `FEATURE_BACKUP_MOODLE2` | true | §15 |
| `FEATURE_MOD_PURPOSE` | `MOD_PURPOSE_COLLABORATION` | drives 4.x/5.x icon colour |
| `FEATURE_GROUPS` / `FEATURE_GROUPINGS` | false | the module *produces* groups; it does not run in group mode |
| `FEATURE_COMPLETION_TRACKS_VIEWS` | true | cheap, standard |

### 1.3 Icons (U2) — everything Moodle needs

| File | Purpose | Design |
|---|---|---|
| `pix/monologo.svg` | **Required** activity icon, Moodle 4.0+ (course page, activity chooser); tinted by theme with the purpose colour (`MOD_PURPOSE_COLLABORATION`) | Original hand-authored single-colour SVG (24×24 viewBox, solid `#000` paths, no strokes-only art): three person marks clustered with a check badge — "self-selected group, approved" |
| `pix/icon.svg` | Legacy/fallback icon (pre-4.0 API paths, some plugin lists and third-party themes still request `icon`) | Same art, self-coloured variant |

No PNG needed when SVGs exist. **All other iconography** (edit/delete/approve/lock/warning/etc. across every page) uses **core pix icons** via `$OUTPUT->pix_icon()` (`t/edit`, `t/delete`, `t/approve`, `t/block`, `t/lock`, `t/unlock`, `i/group`, `i/warning`, `i/checked`, `e/undo`, `t/download`…), which keeps C9 (native only), theme/RTL/a11y behaviour, and adds zero image assets. Both SVGs are original works, GPL-licensed in-file — no third-party art (§15.5 "third-party libraries: none").

---

## 2. Database schema (XMLDB, final)

All tables `INTKEY id` + sequence; `foreign` below = declared XMLDB foreign key (not enforced, documented). Longest name `selfselectadvanced_snapshot` (27) ≤ 53-char limit; all columns ≤ 30 chars. No vendor SQL anywhere (C: §14.1).

### 2.1 `selfselectadvanced` — instance (one row per activity)

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| course | int(10) | NN | — | FK course.id, **index** |
| name | char(255) | NN | — | |
| intro / introformat | text / int(4) | NN | | standard |
| grade | int(10) | NN | 100 | activity point value (§10) |
| minsize | int(5) | NN | 1 | **L1** |
| maxsize | int(5) | NN | 6 | **L2** |
| maxlead | int(5) | NN | 1 | **L3** (N) |
| maxmembership | int(5) | NN | 1 | **L4** (n) |
| maxguided | int(5) | NN | 10 | **L5** (M) |
| timeopen / timedue / timecutoff | int(10) | NN | 0 | formation window |
| penaltytype | int(2) | NN | 0 | 0 = percent of grade, 1 = points |
| penaltyperday | number(10,5) | NN | 0 | per late day |
| guidemode | int(2) | NN | 0 | 0 leader-selects, 1 manager-assigns (A5) |
| inviteexpiry | int(5) | NN | 0 | days; 0 = never |
| autogroup | int(1) | NN | 0 | §9 enabled |
| timecreated / timemodified | int(10) | NN | 0 | |

### 2.2 `selfselectadvanced_group`

| Field | Type | Null | Notes |
|---|---|---|---|
| activityid | int(10) | NN | FK instance, **index**; **unique(activityid,name)** |
| pluginuid | char(64) | NN | **unique index** — A1 format; shortname segment sanitised to `[A-Z0-9]`, capped at 12 chars [S4] |
| name | char(255) | NN | student-fixed, unique in activity |
| title | char(255) | NN | title of work |
| brief / briefformat | text / int(4) | NN | core editor content |
| leaderid | int(10) | NN | FK user, **index** — current leader (also has a member row, `isleader=1`) |
| guideid | int(10) | NULL | FK user, **index**; cleared on return (A11) |
| state | char(15) | NN | `forming` \| `pending_guide` \| `firm` \| `frozen`, **index** |
| autoformed | int(1) | NN 0 | §9 flag |
| successorid / successortype / timenominated | int(10) / char(10) / int(10) | NULL | A3 — active nomination (`transfer` \| `stepout`) |
| returncomment | text | NULL | latest guide return comment (history in events) |
| timesubmitted / timeapproved / timefrozen | int(10) | NULL | lifecycle stamps; `timeapproved` drives §10 |
| coregroupid | int(10) | NULL | mirrored core group (D3, ownership tracking §14.5) |
| usermodified / timecreated / timemodified | int(10) | NN | |

### 2.3 `selfselectadvanced_member`

| Field | Type | Null | Notes |
|---|---|---|---|
| groupid | int(10) | NN | FK group; **unique(groupid,userid)** (A2); **index(groupid,status)** |
| userid | int(10) | NN | FK user, **index** |
| status | char(10) | NN | `invited` \| `confirmed` \| `declined` \| `expired` \| `removed` |
| isleader | int(1) | NN 0 | mirrors group.leaderid (leader is a confirmed member) |
| invitedby | int(10) | NULL | FK user |
| timeinvited / timeresponded | int(10) | NULL | expiry computed from timeinvited + effective inviteexpiry |
| leaverequested | int(10) | NULL | timestamp of pending §6.3 leave request |
| timecreated / timemodified | int(10) | NN | |

Counting bases read from this table (always via `local\groups` helpers — single source of truth):
- **L1 basis:** `status='confirmed'` count per group.
- **L2 basis:** `status IN ('confirmed','invited')` count per group (reserved seats, §4A.2).
- **L3 basis:** groups in this activity where `group.leaderid = user` and state in (forming, pending_guide, firm, frozen).
- **L4 basis:** `status='confirmed'` rows for user across the activity's groups (any state).
- **L5 basis:** groups where `guideid = guide` and state in (pending_guide, firm, frozen).

### 2.4 `selfselectadvanced_userattr` — **site-wide** (D8 + U4)

| Field | Type | Null | Notes |
|---|---|---|---|
| userid | int(10) | NN | FK user, **unique index** |
| gender | char(50) | NULL | quota dimension |
| department | char(100) | NULL | quota dimension |
| subdepartment | char(100) | NULL | quota dimension |
| mobile | char(32) | NULL | U4 — contact info, displayed per `contactprivacy` (connection + owner consent; `:viewall` decides the COLUMN, never the number), **not** a quota dimension |
| usermodified / timecreated / timemodified | int(10) | NN | admin audit |

### 2.5 `selfselectadvanced_quota`

| Field | Type | Null | Notes |
|---|---|---|---|
| activityid | int(10) | NN | FK instance, **index**; priority uniqueness enforced by the store (plain index only — a unique index would block two-phase-free reordering on both DBs) [S1] |
| dimension | char(20) | NN | `gender` \| `department` \| `subdepartment` |
| rtype | char(10) | NN | `value` (value,min,max) or `distinct` (≥ k distinct values) |
| value | char(100) | NULL | NULL for `distinct` rules |
| mincount / maxcount | int(5) | NULL | ≥min / ≤max; `distinct` uses mincount = k |
| priority | int(5) | NN | manager-ordered; drives §9 relaxation cascade |
| timecreated / timemodified | int(10) | NN | |

### 2.6 `selfselectadvanced_override` — one row per (activity, scope, target); assign-style

| Field | Type | Null | Notes |
|---|---|---|---|
| activityid | int(10) | NN | **index** |
| scope | char(10) | NN | `user` \| `group` \| `guide` \| `move` |
| userid | int(10) | NULL | user & guide scopes, **index** |
| groupid | int(10) | NULL | group scope, **index** |
| moveid | int(10) | NULL | move scope, **index** |
| timeopen / timedue / timecutoff | int(10) | NULL | date overrides (user, group scopes) |
| maxlead / maxmembership | int(5) | NULL | L3/L4 (user scope) |
| maxguided | int(5) | NULL | L5 (guide scope) |
| minsize / maxsize | int(5) | NULL | L1/L2 (group scope) |
| quotaexempt | int(1) | NULL | group scope |
| penaltywaived | int(1) | NULL | group scope |
| rulesbypassed | char(100) | NULL | move scope: CSV of `L1,L2,L3,L4,L5,QUOTA` |
| usermodified / timecreated / timemodified | int(10) | NN | |

App-level uniqueness per (activityid, scope, target); duplicates impossible via store API, tolerated defensively by resolver (latest id wins + debugging notice) — §6.2 ties row.

### 2.7 `selfselectadvanced_move`

| Field | Type | Null | Notes |
|---|---|---|---|
| activityid | int(10) | NN | **index** |
| userid | int(10) | NN | moved student, **index** |
| sourcegroupid | int(10) | NULL | NULL = groupless (§9.4 residue placement) |
| targetgroupid | int(10) | NULL | spec §7 said a removal is always a move *to* somewhere; **1.20.0 (decision 6, D6-2) relaxed it to nullable** — a NULL target is a staff PARK, a removal with no destination team |
| makeleader | int(1) | NN 0 | §7 leader designation on target |
| successorid | int(10) | NULL | required when moving the source group's leader out |
| status | char(10) | NN | `pending` \| `committed` \| `cancelled`, **index** |
| statusinfo | text | NULL | JSON per-rule validation results (manager UI) |
| usermodified / timecreated / timemodified / timecommitted | int(10) | NN/NULL | |

### 2.8 `selfselectadvanced_snapshot` — append-only (A6)

| Field | Type | Null | Notes |
|---|---|---|---|
| groupid | int(10) | NN | FK group, **index** (latest = max id) |
| coregroupid | int(10) | NN | mirrored group at snapshot time |
| roster | text | NN | JSON `[{userid, isleader}]` |
| takenby | int(10) | NN | actor |
| timecreated | int(10) | NN | |

### 2.9 `selfselectadvanced_penalty` — ledger (D5, A12)

| Field | Type | Null | Notes |
|---|---|---|---|
| activityid | int(10) | NN | **index** |
| groupid | int(10) | NN | **unique index** (one current row) |
| dayslate | int(5) | NN 0 | vs **effective** dates, bounded by cutoff |
| penaltyvalue | number(10,5) | NN 0 | resolved points deduction |
| waived | int(1) | NN 0 | |
| waivereason | char(20) | NULL | `dateoverride` \| `waiver` |
| basis | text | NULL | JSON: effective dates, rate, type, arithmetic (audit/export) |
| timecomputed | int(10) | NN | |

### 2.10 `selfselectadvanced_agrun` — auto-grouping runs (§9 logging)

| Field | Type | Null | Notes |
|---|---|---|---|
| activityid | int(10) | NN | **index** |
| seed | int(10) | NN | replaying seed (A13) |
| triggeredby | int(10) | NN 0 | 0 = scheduled task, else userid |
| timestarted / timefinished | int(10) | NN / NULL | |
| groupsformed / placed / unplaced | int(5) | NN 0 | summary |
| log | text | NULL | JSON decision trail incl. every relaxation (§9.3) |

**Excluded by design:** no cycle/scheduling tables (D1); no user-account fields anywhere (C11). Grade data lives in the gradebook; snapshots/ledger keep plugin history.

---

## 3. Instance settings — `mod_form` layout

| Section | Fields | Validation (§4A.7) |
|---|---|---|
| General | name, intro | core |
| Grade | grade (point value) | int ≥ 0 |
| Group size | minsize, maxsize | `minsize ≥ 1`, `minsize ≤ maxsize`; integers, no 0-sentinels |
| Student limits | maxlead, maxmembership | `maxlead ≥ 1`, `maxlead ≤ maxmembership` |
| Guides | guidemode, maxguided | `maxguided ≥ 1` |
| Formation window | timeopen, timedue, timecutoff | `timeopen ≤ timedue ≤ timecutoff` (each optional-enabled) |
| Penalty | penaltytype, penaltyperday | ≥ 0; shown with grade context |
| Invitations | inviteexpiry (days) | ≥ 0 (0 = never) |
| Auto-grouping | autogroup enable | — |

On save (`lib.php` update): **grandfathering pass** (§4A.8) — `gatekeeper::compliance_report()` recomputes; warnings listed to the manager (notification + flagged view), existing groups stay valid, violation-increasing actions blocked until compliant, `limits_changed` event with old/new values; **penalty ledger recomputed** against new effective dates (§10) and gradebook pushed. Quota rules: managed on `quotas.php` (A8), linked from the form and the activity secondary navigation.

---

## 4. Capability map (spec §3, exact)

| Capability | Context | Archetypes | Risk |
|---|---|---|---|
| `mod/selfselectadvanced:addinstance` | course | editingteacher, manager | RISK_XSS (standard for addinstance) |
| `mod/selfselectadvanced:creategroup` | module | student | — |
| `mod/selfselectadvanced:respond` | module | student | — |
| `mod/selfselectadvanced:guide` | module | teacher | — |
| `mod/selfselectadvanced:freeze` | module | **teacher** (D4) | — |
| `mod/selfselectadvanced:unfreeze` | module | **editingteacher**, **manager** (D4; the manager grant landed in 1.20.0 — the comment in db/access.php had always claimed it) | — |
| `mod/selfselectadvanced:manage` | module | editingteacher, **manager** (1.20.0) | RISK_CONFIG |
| `mod/selfselectadvanced:managecomposition` | module | editingteacher (`clonepermissionsfrom` `:manage`) — **new 1.20.0** | RISK_CONFIG |
| `mod/selfselectadvanced:assignguide` | module | editingteacher (`clonepermissionsfrom` `:manage`) — **new 1.20.0** | RISK_CONFIG |
| `mod/selfselectadvanced:override` | module | editingteacher, **manager** (1.20.0) | RISK_CONFIG |
| `mod/selfselectadvanced:overriderules` | module | editingteacher, manager | RISK_CONFIG \| RISK_DATALOSS |
| `mod/selfselectadvanced:ingestattributes` | **system** | *(none — site admins only)* | RISK_PERSONAL |
| `mod/selfselectadvanced:viewall` | module | editingteacher, **manager** (1.20.0) — `teacher` **removed 1.20.1**, fresh installs only | RISK_PERSONAL |
| `mod/selfselectadvanced:viewassignedteams` | module | teacher (`clonepermissionsfrom` `:guide`) — **new 1.20.1** | RISK_PERSONAL |
| `mod/selfselectadvanced:viewparticipantidentity` | module | *(none — `'archetypes' => []` and no `clonepermissionsfrom`, so it is granted deliberately or not at all)* — **new 1.20.0** | RISK_PERSONAL |

**How the four 1.20 manager grants actually land, corrected 2026-08-01 — the table above used to record them as fact and they reached fresh installs only.** Editing an `archetypes` list changes nothing on an upgrade: core's `update_capabilities()` builds its "new capabilities" set from the file's capabilities **absent from the `capabilities` table**, and only that set reaches `assign_legacy_capabilities()` (`lib/accesslib.php`). `:unfreeze`, `:manage`, `:override` and `:viewall` have all existed since 1.0, so on every site that installed 1.19.x or earlier core saw nothing new and assigned nothing — measured on both engines: delete those four `role_capabilities` rows and run `update_capabilities('mod_selfselectadvanced')`, and the manager holds none of them. They are therefore asserted **explicitly** by a `db/upgrade.php` step at savepoint **2026073150** (`version.php` moved with it), which calls `update_capabilities()` and then `assign_capability(…, CAP_ALLOW, …)` per manager-archetype role at system context with `$overwrite` left **false**, so a permission an administrator has already recorded — allow, prevent or prohibit — is never overruled. `tests/capabilities_upgrade_test.php` pins install, the pre-1.20 state, the fact that `update_capabilities()` alone does not repair it, the repair, and the administrator's exception surviving two runs.

**`archetypes` and `clonepermissionsfrom` are mutually exclusive, and this plugin now relies on knowing which one fires.** Core's `update_capabilities()` (`lib/accesslib.php`) takes the clone branch *only* when the source capability is already a row in `{capabilities}` — and `$existingcaps` is read **before** the new capabilities are inserted. So for a capability declaring both keys:

- **fresh install** — the source capability is being created in the very same pass, so it is absent from `$existingcaps`, the clone is skipped and `archetypes` is honoured (*"we ignore archetype key if we have cloned permissions"* only bites when the clone actually runs);
- **upgrade** — the source exists, the clone runs, and `archetypes` is ignored entirely: the new capability lands on **every role holding the source, at the contextid and with the permission it holds the source with**.

That is why the same version can produce different holder sets by install path, and it is stated rather than glossed for each capability that declares both:

| Capability | Fresh install | Upgrade | Behavioural difference |
|---|---|---|---|
| `:overriderules` (source `:override`) | editingteacher, manager (archetypes) + **Group Coordinator** (`coordinatorrole::ensure()`, 1.20.0 / savepoint 2026073170) | editingteacher, manager, Group Coordinator — every role holding `:override`, which since 1.20.0's manager step is the same set — + Group Coordinator via `ensure()` | none; the two paths were reconciled by **maintainer decision 14** (below) |
| `:managecomposition`, `:assignguide` (source `:manage`) | editingteacher (archetypes) + Group Coordinator (`ensure()`) | every role holding `:manage` — editingteacher, manager, any site-custom role — + Group Coordinator via `ensure()` | **none that any gate can see**: the only holder the fresh path lacks is the *manager*, and every gate these capabilities open is `has_any_capability([':manage', <narrow>])` while the conflict-of-interest guard exempts `:manage` outright. A manager's authority never depended on the narrow capability |
| `:viewassignedteams` (source `:guide`, **1.20.1**) | teacher (archetypes) + Group Coordinator (`ensure()`) | every role holding `:guide`, **with the permission it holds `:guide` with** — `CAP_PREVENT` included — + Group Coordinator via `ensure()` | none on a stock site. The clone source is `:guide` and **not** `:viewall` precisely because a site that has already withdrawn `:viewall` would otherwise upgrade into the same lockout this capability exists to end. `tests/viewassignedteams_test.php` pins all three permissions and the no-row case |

**Maintainer decision 14 (2026-08-01), which closes the `:overriderules` question the previous revision of this paragraph left open.** The Group Coordinator MAY hold `:overriderules` — park, dissolve, bypass — **but only ever at module context**. It is listed in `coordinatorrole::capabilities()` and therefore recorded against the role at system context, which is where Moodle keeps every role *definition*; a definition is not a grant. What makes it module-only is the assignment side, and that is a guarantee this plugin enforces in code: every appointment it writes is a `role_assignments` row at an **activity's** `CONTEXT_MODULE` carrying component `mod_selfselectadvanced` — three writers and no fourth (`coordinatorimport::appoint()`, `coordinatorimport::run()`, `coordinatorrole::migrate_to_module_context()`). Savepoint **2026073160** moved every legacy course-context appointment to module context **before** savepoint 2026073170 granted the capability, so the role never carried it while a course-wide row could have carried it too. Two residues, named because they are real: **corrected 1.20.1** — the role is no longer *offered* at course context at all (maintainer decision: it "does work within our plugin only", so `coordinatorrole::ensure()` sets the context levels to `CONTEXT_MODULE` alone). Assignability is not a grant: a course-context row recorded before that change still grants exactly what it granted, and stays visible and removable on the Coordinators screen, which reads `{role_assignments}` directly and never consults `get_role_contextlevels()`; and a course with no `selfselectadvanced` instance keeps its un-migratable legacy row, which grants nothing *while that stays true* — there is no module context beneath it for a `CONTEXT_MODULE` capability to be asked at — but which is not inert forever, because the migration runs once at its savepoint and an instance added to that course later would be reached by the surviving row. The row is kept anyway: deleting it erases an appointment with no successor. §6.3a's "additive" claim is corrected above: the mechanisms are alternatives, chosen per install path.

Every check is capability-based (`has_capability`), never role/archetype-based (§3). "Manager" in this document = holder of `:manage`; "guide" = holder of `:guide`. The candidate pool (§ Glossary) = users enrolled in the course holding `:respond` — computed via `get_enrolled_users($context, 'mod/selfselectadvanced:respond')`, *not* activity viewers (C10).

---

## 5. State machine — `local\state`

States exactly as spec §5. Transition table (single authority; every transition = one method, one transaction, one event, guards via gatekeeper→resolver):

| # | Transition | Actor (capability) | Guards (all via effective values) | Side effects |
|---|---|---|---|---|
| T1 | *(create)* → `forming` | student `:creategroup` | window open (user-effective dates); L3 free; L4 free | group row + leader member row; uid assigned (A1); `group_created` |
| T2 | `forming` → `pending_guide` (submit) | leader | window; **L1** met (confirmed ≥ eff. minsize); **quota** compliant or exempt; leader-selects: chosen guide has **L5** free (list pre-filtered, §4A.5) | timesubmitted; guideid set (or A5 queue); notify guide/manager; `group_submitted` |
| T3 | `pending_guide` → `forming` (return) | guide (assigned) `:guide` | mandatory comment | guideid cleared → **L5 slot released** (A11); comment stored; notify leader; `group_returned` |
| T4 | `pending_guide` → `firm` (approve) | guide (assigned) `:guide` | atomic re-check under lock: **L5**, **L1**, **quota** — each bypassable only by recorded override | timeapproved; **irreversible**; penalty ledger row (§10); notify; `group_approved` |
| T5 | `firm` → `frozen` (freeze) | guide `:freeze` (single + bulk) | group is `firm`; guide guides it (or `:manage` holder per site config); core-group sync pre-checks | core group created via groups API; grouping ensured; snapshot appended; coregroupid+timefrozen; `group_frozen` |
| T6 | `frozen` → `firm` (unfreeze) | `:unfreeze` | warning if core group referenced by restrictions (list shown) | core group deleted (only if plugin-owned); roster restored to latest snapshot; out-of-band drift discarded+reported; `group_unfrozen` |
| T7 | `forming` → *(deleted)* | leader | forming only | notify members; cancel invitations; hard delete (A10); `group_deleted` |

Membership sub-machine (member rows): `invited → confirmed | declined | expired`; `confirmed → removed` (leave confirmed by leader; staged move; privacy delete). Succession sub-flow (A3) rides on the group row. `firm`: membership immutable except staged moves (§7); `frozen`: additionally mirrored; **students and guides can never alter firm/frozen membership** (§5).

Illegal transitions throw `coding_exception`; user-reachable invalid actions render disabled controls with reasons (§4A.6) — the UI never offers a transition the state machine would refuse.

---

## 6. Override subsystem (§10 — designed before anything is implemented)

### 6.1 Resolution service — the single authority

`\mod_selfselectadvanced\local\override\resolver` — constructed per activity, caches all override rows in one query. **Every** effective-value question anywhere in the plugin goes through it; any other read of override data is a review-blocking defect (§10). Gatekeeper, state machine, invitations, succession, moves, freeze, auto-grouping, penalty calculator, mod_form grandfathering, and all displays consume it.

```php
final class resolver {
    public function __construct(private activity $activity) {}
    // Dates: per-field resolution; group context optional (null when acting outside any group).
    public function effective_dates(int $userid, ?int $groupid = null): effective_dates; // open, due, cutoff + per-field provenance
    // The five limits (§4A) — every value carries provenance for UI badges + audit:
    public function effective_minsize(int $groupid): effective_value;        // L1
    public function effective_maxsize(int $groupid): effective_value;        // L2
    public function effective_maxlead(int $userid): effective_value;         // L3
    public function effective_maxmembership(int $userid): effective_value;   // L4
    public function effective_maxguided(int $guideid): effective_value;      // L5
    public function is_quota_exempt(int $groupid): effective_flag;
    public function is_penalty_waived(int $groupid): effective_flag;
    public function move_bypasses(int $moveid): array;                       // ['L2','QUOTA',…] for that move only
}
// effective_value = { value:int, source: SOURCE_ACTIVITY | SOURCE_USER | SOURCE_GROUP | SOURCE_GUIDE, overrideid:?int }
```

### 6.2 Scope × type matrix and full precedence enumeration

Precedence rule (spec): **group override > user override > activity setting** — applied **per field** (an unset field in a higher-precedence row falls through, exactly like `mod_assign`). Move-scope overrides are not values but *bypasses*, consumed only while validating/committing that move.

| # | Quantity | Group ovr | User/Guide ovr | Effective value |
|---|---|---|---|---|
| P1 | date field F (user in group ctx) | set | set | **group.F** |
| P2 | date field F | set | — | group.F |
| P3 | date field F | — | set | user.F |
| P4 | date field F | — | — | activity.F |
| P5 | date field F (no group ctx: create/join decisions) | n/a | set | user.F |
| P6 | date field F (no group ctx) | n/a | — | activity.F |
| P7 | mixed combination (e.g. user has `timedue`, group has `timecutoff`) | partial | partial | per-field: open→activity, due→user, cutoff→group |
| P8 | L1 minsize / L2 maxsize | set | *(scope invalid)* | group value, else activity |
| P9 | L3 maxlead / L4 maxmembership | *(scope invalid)* | set (user) | user value, else activity |
| P10 | L5 maxguided | *(scope invalid)* | set (guide) | guide value, else activity |
| P11 | quota exemption | set (bool) | *(scope invalid)* | group flag, else not exempt |
| P12 | penalty waiver | set (bool) | *(scope invalid)* | group flag, else not waived; **independently**, approval within an override-extended window ⇒ penalty 0 by arithmetic (behavioural guarantee, §10) — waivereason records which |
| P13 | move bypass | — | — | during that move's validation only: listed rules treated satisfied, logged in statusinfo + event |
| P14 | **tie** (duplicate rows same scope+target — store-prevented) | — | — | deterministic: **oldest *active* row wins, else oldest row** + `debugging()`. Corrected in 1.19.2/1.20 — it used to read "highest id (latest) wins", which is neither what the resolver nor what `store::get()` does. `resolver::load_overrides()` sees only `status='active'` rows and keeps the first in `id ASC`; `store::get()` applies the same oldest-active-then-oldest preference so the read path and the write path can never pick different twins; the 1.19.2 dedupe upgrade keeps the same row (`COALESCE(MIN(CASE WHEN status='active' …), MIN(id))`, repaired at serial 2026073140). Do not "restore" newest-wins. |
| P15 | user+group date override **and** group quota exemption together | set | set | dates per P1–P7; quota per P11 — orthogonal quantities never interact |
| P16 | **group-level assessment dates** (penalty §10, and any date question asked *of a group*) [B2] | set | set (leader) | standard chain with the **leader as the user context**: group.F > *leader's* user.F > activity.F — a leader personally granted an extension who forms within it incurs no penalty; other members' date overrides affect only their own actions (P1–P7), never the group's assessment |

Invalid scope/type combinations (e.g. a user-scope `minsize`) are rejected by `override\store` at write time and, defensively, ignored by the resolver with `debugging()`.

### 6.3 Behavioural guarantees (§10)

1. A group approved within its overridden (effective) window incurs **no penalty** — the calculator only ever sees effective dates (P12).
2. No enforcement point reads raw settings: gatekeeper's constructor takes the resolver; raw-column reads outside `activity.php`/resolver are grep-audited per slice (review gate item).
3. Every override create/update/delete → `override_created/updated/deleted` event with actor, target, old and new values.

### 6.3a Staff override authority (1.20.0, decision 6)

A holder of `:overriderules` (editing teacher and Moodle Manager by default; `clonepermissionsfrom`
`:override`, so no site loses the bypass authority it already granted — and, since 1.20.0 savepoint
2026073170, the **Group Coordinator** role, which by maintainer decision 14 can only ever hold it in
an activity somebody appointed them in; see §4) repairs any non-compliant
roster state through exactly ONE mechanism — the move-scope override — on every staff mutation path:

| Path | Where the authority is enforced | Record |
|---|---|---|
| staged move with a rule bypass | `moves::commit_set()` refuses a bypassed set with no reason | `move_committed.other['bypassedrules']` + `move_rules_overridden` |
| **park** (removal, no destination) | `moves::stage()` — null target needs `:overriderules` | as above, `kind = 'park'` |
| **dissolve** (close a dead-end team) | `api::dissolve_group()` — needs `:manage` AND `:overriderules` | one committed park move row per member + `move_rules_overridden` `kind = 'dissolve'` |
| staff team creation | `api::create_group($staff = true)` — needs `:manage` | `group_created.other['createdbystaff']` |
| join-request acceptance | `joinrequests::respond($bypass)` — checked on the ACTOR | `move_rules_overridden` |
| unfreeze with a restore delta | `freeze::unfreeze()` refuses a delta with no reason | `group_unfrozen.other['added'/'removed'/'reason']` |

Every one of those requires a typed reason, is refused to a conflicted coordinate-shaped actor
(`tickets::require_uninvolved_override()` now covers scope `move` too), and fires its event AFTER
the commit and AFTER every lock has been released. Move-scope rows are listed and revocable on the
Overrides page's Moves tab (revocable only while the move is still pending — revoking the bypass of
a committed move would falsify history).

Three rules are deliberately NOT bypassable: `LEADR` (the remedy is the explicit `replaceleader`
consent), `SUCC` (it guards against promoting a stale successor) and `TGT` (1.20: the student is
already a confirmed member of the target team, so the move gains them nothing while its source half
still deletes a membership). A solo leader who cannot name a successor is told to use **Dissolve
team**, which is the verb that resolves that dead end.

`TGT` lives in `moves::validate_set()` — the layer every caller shares — and not at any one seam,
because staging and committing are separate acts: a set staged against a correct roster can be
committed days later, after the student has reached the target by an invitation, a join request or
another manager's move. `commit_set()` re-runs the whole validation on the roster it reads inside
its own locks, so the stale queue is refused there. `stage()` carries the cheap half of the same
refusal (`refusalmovetargetalready`) plus `errmovesamegroup`, which the form cannot raise for a
BLANK source — a blank source is inferred to the student's only team, which is the target itself in
exactly this case. `joinrequests::do_accept()`'s own guard remains, stating the refusal in the
request workflow's words and leaving the request open for the decider to decline.

### 6.4 Override UI (mod_assign pattern)

`overrides.php?mode=user|group|guide` — three core tables (target, overridden fields with provenance badges, actions) + `overrideedit.php` moodleforms. **Field set per mode is fixed [B5]** — the form can never offer a scope/type pair the store would reject:

| Mode | Target picker | Fields offered |
|---|---|---|
| `user` | user autocomplete (U3-searching) | `timeopen`, `timedue`, `timecutoff` (each enable/disable), `maxlead`, `maxmembership` |
| `group` | plugin-group select | `timeopen`, `timedue`, `timecutoff`, `minsize`, `maxsize`, `quotaexempt`, `penaltywaived` |
| `guide` | guide picker (holders of `:guide`) | `maxguided` only |

Move-scope overrides (`rulesbypassed` only) are attached inside the staged-move UI (§7), not here. All POST + sesskey; `:override` required.

### 6.5 Override test matrix (PHPUnit, slice 7)

Rows P1–P15 each × {value read, gate outcome} + guarantees 1–3 + store rejection of invalid scopes + resolver caching (1 query) + per-limit override boundary (one below/at/above each of L1–L5 overridden and not). The §15.1 boundary suite runs **twice**: with limits from settings and from overrides.

---

## 7. Limits enforcement matrix (§4A × enforcement points × function)

Single choke point: `local\rules\gatekeeper` — pure, unit-testable, resolver-fed. Pages never compute rules; they call `api` which calls gatekeeper; UI renders gatekeeper's structured refusals as disabled-control reasons (§4A.6). ✓ = checked there; **bold** = atomic re-check under lock+transaction (A7).

Every gatekeeper `can_*` method carries an explicit **state precondition** checked before any limit [S2] — pages call the gatekeeper directly, so a stale POST can never act on a group whose state has moved on (e.g. invite into a `pending_guide` group).

| Enforcement point | Function (gatekeeper unless noted) | State req. [S2] | L1 | L2 | L3 | L4 | L5 | Quota | Window |
|---|---|---|---|---|---|---|---|---|---|
| Group creation | `can_create_group(user)` | — | | | ✓ | ✓ | | | ✓ |
| Invitation send | `can_invite(group, invitee)` | `forming` | | ✓ seats | | ✓ invitee | | | ✓ |
| Admission feasibility (1.13.0) | `check_composition_feasibility()` from `can_invite`/`can_accept` | `forming` | | | | | | ✓ reachability within max seats; quota-exempt bypasses | |
| Invitation accept | `can_accept(member)` → `invitations::accept()` | `forming` | | **✓** | | **✓** + cascade | | | ✓ |
| Invitation withdraw/decline/expiry | `invitations::release_seat()` | any (releases only) | | frees seat | | | | | |
| Acceptance cascade (§4A.4) | `invitations::cascade(user)` — same transaction, leaders notified, reason recorded | — | | | | ✓ | | | |
| Leave request confirm | `can_confirm_leave(member)` | `forming` | no L1: forming groups may always shrink (the minimum gates submission; 1.13.0) | | | | | | |
| Succession: nominate | `can_nominate(group, member, type)` | `forming` | ✓ (stepout) | ✓ (replacement seat) | ✓ nominee | | | | ✓ |
| Succession: confirm | `succession::confirm()` | `forming` | **✓** | **✓** | **✓** | | | | |
| Submit to guide | `can_submit(group)` | `forming` | ✓ | | | | ✓ guide list | ✓ | ✓ |
| Guide assignment (A5 / reassign) | `can_assign_guide(group, guide)` | `pending_guide` | | | | | **✓** | | |
| Guide approval | `can_approve(group)` | `pending_guide` | **✓** | ✓ | | | **✓** | **✓** | |
| Guide return | `state::return_group()` | `pending_guide` | (comment mandatory; releases L5) | | | | | | |
| Freeze (single/bulk) | `can_freeze(group)` — defence in depth | `firm` | ✓ | ✓ | | | | ✓ | |
| Unfreeze | `freeze::unfreeze()` | `frozen` | grandfathered (§4A.8) — restored verbatim, flagged if now out-of-limit | | | | | | |
| Group delete | `can_delete_group(group)` | `forming` | | | | | | | |
| Staged-move commit (per selected set, net state) | `moves::validate_set()` | source/target in `firm`/`frozen` (or target `forming+` for §9.4 placement) | **✓ source** | **✓ target** | **✓ if makeleader/successor** | **✓ moved user** | **✓ affected guides** | **✓ both groups** | (dates n/a — manager action) |
| Auto-grouping formation | `autogroup\engine` | — | ✓ band | ✓ band | | ✓ | | ✓ priority cascade | per-user eff. cutoff [B4] |
| Auto-grouping leader pick | `engine::designate_leader()` | — | | | ✓ else manager queue (§9.5) | | | | |
| Auto-grouping guide queue | `can_assign_guide()` per group | `pending_guide` | | | | | ✓ + no-capacity report | | |
| Settings save (grandfather) | `compliance_report(activity)` | — | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Violation-increase block (§4A.8) | `would_increase_violation()` consulted by `can_create_group`, `can_invite`, **`can_accept`**, `can_assign_guide`, **`moves::validate_set()`** [B3] | — | ✓ | ✓ | ✓ | ✓ | ✓ | | |

Display duties (§4A.6) rendered from the same gatekeeper DTOs: student landing counters, leader seat panel + disabled-invite reason, guide-list load figures, guide dashboard header, manager Load/Size/count columns (core tables). One wording source = lang strings fed by gatekeeper reason codes.

---

## 8. Quota engine (§8.2)

`local\quota\evaluator::evaluate(group): bucket_report` — pure function over (confirmed roster’s attributes, ordered rules): per rule `{rule, current, required, satisfied, deficiency_string}`; compliance = all satisfied. Members with missing attributes count toward no `value` rule, appear as "unknown" in buckets, and are flagged (never fatal — §8.1). The same report object renders: leader's live deficiency panel (with non-colour satisfied/deficient markers — WCAG §14.14), guide/manager read-only panels, approval/freeze gate messages, and §9's per-rule fitting. Distinct-rule: count of distinct non-null values ≥ k. Enforcement gates: submit, approve, freeze (each exempt-able per P11); staged moves both sides (§7).

---

## 9. Auto-grouping engine (§9)

`local\autogroup\engine::run(activity, trigger, ?seed)` — scheduled task at `timecutoff` (+ manual manager trigger with confirm). **Pool rule [B4]:** the pool contains only groupless candidates whose **own effective cutoff** (resolver, per-user) has passed — a student holding a `timecutoff` extension is still inside their window and is excluded; the task re-runs as later windows close, sweeping newly-expired students into follow-up runs (each `agrun` records who was processed; manual runs behave identically). Deterministic: seed stored in `agrun`; seeded shuffle orders the pool; A13 sizing (B1-corrected — never exceeds `max_size`, remainder → residue); per group, rules applied strictly in priority order — rule unfillable (no eligible students left) → **bypassed and logged**, cascade continues (§9.3); leftovers fill within `maxcount` limits. Exhaustion (§9.4): unformable residue stays unassigned → flagged list (`flagged.php`) → manager places via **override-backed staged moves** only. Leader designation per §9.5 (L3-checked; none eligible → manager queue, no silent breach). Groups flagged `autoformed=1`, enter guide-assignment queue (A5 path, L5-checked, no-capacity reported). Lateness accrues per ledger; per-group waiver via override. Every decision (assignment, relaxation, exhaustion, leader pick, failure) appended to `agrun.log` JSON + `autogroup_run` event. Whole run in one transaction + activity lock (A7); students confirmed elsewhere between pool snapshot and commit are re-checked and skipped (logged).

---

## 10. Penalty ledger & gradebook (§11, D5)

- `penalty\calculator::compute(group): penalty` — pure: `dayslate = max(0, ceil((timeapproved − eff_due)/DAYSECS))` bounded by eff. cutoff; value = `penaltyperday × dayslate` (percent-type → % of `grade`); waived if P12 says so (reason recorded). Effective dates come from the resolver's standard chain applied with the **leader as user context** (P16 [B2]): group override > leader's user override > activity — a leader granted a personal extension who forms within it incurs no penalty; no quantity gets a narrowed precedence chain (§10's core rule).
- `penalty\ledger` — upsert on approval (T4); full recompute on settings save and via nightly `reconcile_penalties` task; export via core table download on `ledger.php` (§ C12) for external/manual grading.
- **Gradebook:** one grade item (`grade_update` in lib.php). Student grade = `grade − Σ penaltyvalue` over groups where they are a **confirmed** member (each group's own penalty, cumulatively — D5), floored at 0 (A12). Students in no firm/frozen group: grade stays **null** (not zero) and they appear in `flagged.php` until placed (§11).

---

## 11. Freeze / unfreeze & core-group sync (§12)

`local\freeze` — the only code touching core groups, exclusively via `groups_*` API (C: §14.5):

- **One routine.** `freeze::sync_core_group(activity, groupid, actorid, forceremove = [], rethrow = false)` is the only code that writes core-group membership. It is idempotent, diff-based and convergent, and **callers are expected to call it outside every plugin lock and open transaction** — core's groups API fires events, invalidates caches and writes a group conversation per member, none of which belongs inside a 10-second lock budget. Its in-transaction half is `freeze::request_sync()`, which queues a deduped `task\coresync_adhoc` in the SAME transaction as the plugin write: a crash between the plugin commit and the inline sync is repaired by cron, never silently diverged.
  - *Corrected 2026-08-01 (requirement 6).* Until 1.20 the routine **branched on ambient state**: called with a transaction open it returned `status = 'deferred'` silently and wrote nothing. Since `advanced_testcase` opens a delegated transaction before every test on PostgreSQL and none on MariaDB, that made the mirror **run on one engine and be skipped on the other for identical inputs** — measured, `family=postgres tx_started=1 sync_status=deferred coregroupid=0` against `family=mysql tx_started=0 sync_status=synced coregroupid=SET coremembers=3` — with 40 opt-in `preventResetByRollback()` calls as the only compensation. **The branch is gone.** The routine does the same work either way; the *caller's* obligation to be outside the lock and the transaction is a convention this document states, not something the routine now enforces by silently doing nothing. Giving it an explicit mode (`sync_now()` vs `request_sync()`, throwing a `coding_exception` if the inline form is entered with a transaction open) is the design change still owed, and is booked as T-16 follow-up. Pinned by `coresync_test::test_sync_does_the_same_work_inside_and_outside_a_transaction`, which runs the same call twice — once outside a transaction, once inside one it owns — and compares the results field by field.
  - *Status contract.* `'synced'` is set **after the last core write returns**, never before, and is the caller's only licence to report the mirror in step. A throw anywhere in `classify_mirror()` or the add/remove/grouping work leaves `status = 'failed'` with the exception message on `->error`, queues the convergence adhoc from the catch (so `group.php`'s manual resync — previously the one sync entry point with no adhoc behind it — has a retry), and `group.php` shows a warning. Before 1.20 the status was set first, so a failed sync reported `synced` with zero counts and the page chose its green "already in step" branch for a mirror missing a member; the only signal was a `debugging()`, which is a no-op in production.
- **Expected set** = confirmed plugin members **∪ the assigned guide** (decision 7), recomputed by `freeze::expected_core_members()` and never cached. The guide is never written to `selfselectadvanced_member` or the snapshot roster, because unfreeze replays that roster as CONFIRMED members.
- **Ownership is machine-readable.** The course group carries `idnumber = pluginuid` (falling back to no idnumber when a course group already claims it); every membership this plugin writes carries `component = 'mod_selfselectadvanced'` and `itemid` = the plugin group id. A sync removes only rows it owns — plus untagged rows of users who still hold a plugin member row (legacy exports predating tagging), plus rows the caller forces out (GDPR erasure, where the member row is already gone). Everything else is a stranger: **reported, never touched**.
- **Refusals are visible.** `groups_add_member()` returns false for a deleted account and for anyone not enrolled in the course — including a guide holding the capability through a category or system role. Every refusal lands in the result's `refused` list, in a `capaudit` notification to every manager (names only) and in the interactive redirect notice.
- **Freeze:** lock, gates, ONE transaction of plugin writes only (state flip, `timefrozen`, `frozenbystaff`, snapshot, `request_sync`), release, then sync (which mints the core group `[{activity idnumber|name}] {group name}` and ensures the grouping `Self-selection: {activity name}`), then the `group_frozen` event, then the notifications. Re-freezing an already frozen team is a **repair**: no gates, no state flip, just the sync, whose mint recreates an externally deleted mirror — **and no `group_frozen` event and no mail** (corrected 2026-08-01; the trailing block used to run ungated after the try, so a repeat freeze re-fired the event and re-mailed every confirmed member: measured `repeat freeze threw=NULL msgs 4->8 group_frozen 1->2`, which two staff clicking Freeze produce through the lock and `task\bulkfreeze_adhoc` produces on queued overflow).
- **Bulk freeze:** guide dashboard table + explicit selection (nothing pre-checked). The first `freeze::BULK_FREEZE_INLINE_MAX` (20) are frozen inline; the remainder goes to one `task\bulkfreeze_adhoc`.
- **Unfreeze:** `:unfreeze` only (or the team's own guide while no staff freeze stands). Pre-check: core group referenced in course `availability` restrictions → warning list first (§12). The core group and `coregroupid` are **RETAINED** — `state = firm` already distinguishes the two situations, so a later refreeze reuses the same id and availability conditions, grouping links, group calendar events and the group conversation all survive freeze → change → refreeze. The roster is restored to the **latest snapshot** verbatim (A6), out-of-limit vs current settings is flagged (§4A.8), the `group_unfrozen` event fires after commit and release, and the mirror is resynced to the restored roster.
- **Discard:** deleting the mirror is an explicit, capability-gated manager action (`freeze::discard_core_group()`, `mod/selfselectadvanced:manage`, POST + sesskey, confirm page). Refused while frozen — the next sync would simply mint it again. It severs `coregroupid` under the group lock in its own transaction and deletes the course group only after release, so a crash leaves an orphaned but idnumber-marked course group rather than a dangling pointer.
- **Drift** (`freeze::drift()`) is the CORE-MIRROR report and nothing else: `extra` = strangers only, `missing` = expected members absent from the mirror, `repairable` = what a resync would fix. Zero on a healthy frozen team. It is **not** the unfreeze restore delta (snapshot roster vs live confirmed roster), which is a different quantity.
- **Convergence hooks.** Every path that changes confirmed membership or the guide on a group with a mirror calls `request_sync()` inside its transaction and `sync_core_group()` after release: staged moves (`moves::commit_set`, handed back to `joinrequests::do_accept` on the nested accept path), guide handover, manager guide assignment, succession, GDPR erasure (both privacy deletion paths), `user_deleted` and `user_enrolment_deleted`. The unenrolment observer deliberately does NOT sync inline — it fires in bulk — and relies on the queued adhoc.
- **Core UI cannot break a frozen mirror:** `selfselectadvanced_allow_group_member_remove()` (lib.php, called by core's `groups_remove_member_allowed()` for tagged rows only) refuses removal of a plugin-owned membership while the team is FROZEN.
- **Flagged report** surfaces mirror health per activity in three bulk queries: `coregroupmissing` (frozen with no live mirror — also the backup/restore hole), `coregroupincomplete` (expected members absent) and `coregroupstranger` (unowned rows present).
- **Uninstall:** plugin tables dropped; frozen core groups/groupings **left in place** as course data, admin informed via uninstall notice (§14.5). Ownership of the surviving artefacts is now machine-readable through the group `idnumber` and the membership `component`.

---

## 12. Events, messaging, scheduled tasks

**Events** (`\core\event` base, CRUD verbs, objecttable set, module logstore-visible — §14.7): `group_created`, `group_deleted`, `invitation_sent`, `invitation_accepted`, `invitation_declined`, `invitation_expired`, `invitation_withdrawn`, `leadership_transferred`, `group_submitted`, `group_returned`, `group_approved`, `group_frozen`, `group_unfrozen`, `move_staged`, `move_committed`, `move_cancelled`, `autogroup_run`, `attributes_imported`, `attributes_updated`, `limits_changed` (old+new §4A values), `override_created`, `override_updated`, `override_deleted`, `penalty_recomputed` (A12), `leave_requested`, `course_module_viewed`, `coregroup_synced` (mirror converged: added/removed/refused/unowned counts), `coregroup_discarded` (mirror deleted by a manager).

**Message providers** (`db/messages.php`, all user-preference-respecting, deep links — §14.8): `invitation` (→invitee), `invitationresult` (→leader: accept/decline/expire/cascade with reason), `nomination` (→nominee), `nominationresult` (→leader), `leaverequest` (→leader), `leaveresult` (→member), `guidequeue` (→guide on submission / manager in A5 mode), `groupreturned` (→leader, with comment), `groupapproved` (→all confirmed members), `groupfrozen` (→members), `groupunfrozen` (→members+guide), `movecommitted` (→moved student, both leaders), `autogroupresult` (→placed students; →manager summary incl. residue), `deadlinereminder` (→students not yet in a firm group, 24 h before eff. due), `gradepenalty`? — no: penalties surface via gradebook + ledger (not a nag). Sends centralised in `local\notifier`.

**Scheduled tasks** (`db/tasks.php` — §14.9): `expire_invitations` (hourly; effective expiry via resolver; auto-decline + seat release + notify), `run_autogrouping` (5-min cadence; fires per enabled activity whenever unprocessed groupless students exist whose **per-user effective cutoff** has passed [B4] — i.e. it re-runs as override-extended windows close, not only once at activity cutoff), `reconcile_penalties` (nightly full recompute — catches date-override edits), `deadline_reminder` (hourly window scan, per-user effective due, sent-marker via user preference key).

**Observers** (`db/events.php`) [M3]: `\core\event\user_deleted` → delete the user's `userattr` row, scrub pending member/nomination references, purge the distinct-value MUC cache (which is also invalidated on ingest and inline edit), and converge each affected mirror. `\core\event\user_enrolment_deleted` → when that was the user's LAST enrolment in the course (core only purges group memberships then), drop their live memberships in that course's activities and queue the mirror sync; no inline sync, because this event fires in bulk. A removed leader or guide is never auto-reassigned — the flagged reports surface both.

**Adhoc tasks** (no `db/tasks.php` entry): `task\coresync_adhoc` (the mirror convergence backstop, queued inside the mutating transaction, deduped, failures rethrown so core's retry/backoff owns them) and `task\bulkfreeze_adhoc` (the overflow of a bulk freeze).

---

## 13. Pages, templates, tables, AMD (C9/C12/C10, §14.13 UI inventory)

| Page | Who | Built from |
|---|---|---|
| `view.php` landing | all | templates `landing_student` (my groups w/ lead/member counters §4A.6, my invitations+nominations w/ accept/decline, create button w/ reason when disabled), guide/manager panels link onward |
| `groupedit.php` | leader | moodleform: name (unique-in-activity check), title, brief (core editor) |
| `group.php` | members (confirmed or invited), the team's assigned guide, `:manage`, `:viewall` — one predicate, `teamaccess::may_open_team()` | roster template, seat counter, quota bucket panel (§8), invite autocomplete (below), succession controls, submit-to-guide (guide select w/ load figures), leave request; every action POST+sesskey |
| `guide.php` | `:guide` | **core table**: my queue + my groups; filters (state, quota-compliant, approved before/after, department); bulk freeze via checkbox column + `core/checkbox-toggleall`; header load figure |
| `review.php` | assigned guide | group detail + quota panel + approve (confirm modal) / return (mandatory comment form) |
| `manage.php` | `:manage` | **dynamic core table** (participants pattern): all groups — state, size vs band, quota state, guide+Load, pending moves; row actions; links to every manager tool |
| `moves.php` / `moveedit.php` | `:manage` | pending-moves core table w/ per-rule validation chips from `statusinfo`, joint-commit selection, cancel; stage form: student (U3 autocomplete), source (auto), target group, makeleader+successor, attach move-override (`:override`) |
| `overrides.php` / `overrideedit.php` | `:override` | §6.4 |
| `quotas.php` / `quotaedit.php` | `:manage` | rule table ordered by priority (up/down reorder posts), add/edit form — dimension select, value picker fed by **ingested distinct values** (cache), min/max, distinct-k |
| `ledger.php` | `:viewall` | core table + download (all core dataformats) |
| `flagged.php` | `:manage`/`:viewall` | unplaced students, missing-attribute students, out-of-limit (grandfathered) groups, leaderless auto-groups |
| `autogroup.php` | `:manage` | run trigger (confirm modal) + `agrun` history table + per-run log view |
| `attributes.php` | **site admin** (`:ingestattributes`, system ctx, via `admin_externalpage` under Plugins → Activity modules → Group self-selection (Advanced) → Participant attributes) | CSV upload form (U4/A9) + full validation report template + **core table** of all attribute records w/ inline edit form |
| `index.php` | course viewers | standard instance list |

**Candidate selector (C10 + U3):** `form\candidates_selector` — core `autocomplete` element, `ajax` handler = AMD `mod_selfselectadvanced/candidateselector` (thin wrapper of the core form-autocomplete transport, the enrol-manual pattern) → external function `search_candidates` (`db/services.php`, loginrequired, capability-checked): pool = `get_enrolled_users($ctx,'mod/selfselectadvanced:respond')`, WHERE matches the **full core name-field set** (`\core_user\fields::for_name()` — first/last/middle/alternate, mirroring core participants search [S6]) and the cross-DB full-name concat (`$DB->sql_like`), **and never the email address** — for any viewer, in either state of the contact-privacy switch (wave 3D withdrew the `OR email` arm; A14) — minus gatekeeper-ineligible users, each result labelled with its eligibility reason when excluded-but-shown is needed (spec §6.2 "says why"); the address is still *displayed* on the label when the switch is off and the viewer holds a core identity capability, and the element's placeholder says "Search by name" unconditionally. Same element reused for nomination (roster-scoped), override user pick, move stage. **C9 justification on record [S5b]:** core's `form_user_selector`-style providers cannot attach per-candidate eligibility filtering and localized refusal reasons, which §6.2 requires — `candidateselector.js` therefore exists solely as the transport for our provider and adds no UI of its own; per-slice audits treat it as this justified exception.

**styles.css bound [S5a]:** plugin-specific structural selectors only (all prefixed `.selfselectadvanced-`); **no** colour, spacing, or typography values duplicating theme tokens — visual styling comes from the Bootstrap utility classes Moodle ships. Audited each slice.

**Templates:** one Mustache per renderable, core-component partials (notifications, modals via `core/modal_save_cancel`, badges, `core/form_autocomplete_*` internals untouched). No HTML in PHP (renderer + templates only), no inline JS (all behaviour via core AMD or the single custom module), all strings via `get_string`, RTL-safe, WCAG 2.1 AA (labels, aria-live on the deficiency panel, non-colour state markers).

---

## 14. Attributes subsystem (§8.1 + U4/A9)

- Site-wide table §2.4; **read** paths for activity roles exactly where flows need values (§8.1): quota panels, rosters (the mobile COLUMN follows `:viewall`/assigned guide/confirmed member; the NUMBER follows `contactprivacy`), auto-grouping reports, flagged lists — via `attributes\manager::get_for_users()` (bulk, cached per request).
- **CSV ingest** (`csv_importer`, core `csv_import_reader`): validation per §14.12 — size cap, encoding (UTF-8 w/ BOM tolerance), header exactly the U4 set (case/space-insensitive: `username,firstname,lastname,gender,department,subdepartment,mobile`, optional `email`), per-row: user exists (else **reject row**, C11), name cross-check (warn), value normalisation (trim, collapse case-variants report), mobile format sanity (`PARAM_TEXT` + length ≤ 32, digits/+/- keep). Dry-run preview → confirm commit (transaction) → full report (created/updated/warned/rejected with reasons) + `attributes_imported` event (counts in `other`).
- Inline per-user edit via core form on `attributes.php` (`attributes_updated` event, usermodified audit).
- Distinct value lists (for quota pickers) cached in MUC (`db/caches.php`, application cache, invalidated on ingest/edit).
- The plugin **never creates/suspends/deletes users** (C11) — enforced by design (no user-table writes anywhere; audit greps for `user` table writes are part of every slice gate).

---

## 15. Privacy, backup/restore, security

**Privacy provider** (§14.10): metadata for all 10 tables (userid-bearing: group.leaderid/guideid/successorid/usermodified, member.*, userattr.*, override.userid/usermodified, move.userid/successorid/usermodified, snapshot.roster+takenby, agrun.log/triggeredby, penalty via membership); export: per-user groups led/joined (name, title, brief), invitations+responses, nominations, guide decisions on their groups, overrides targeting them, penalty entries of their groups, site-scope userattr record (gender/department/subdepartment/mobile); delete (user/context/users-in-context): member rows removed, attr row removed, leader/guide/successor/actor ids blanked to 0 with group structure kept (course data), snapshot roster entries scrubbed of the user, agrun log entries pseudonymised. A deletion that blanks `leaderid` leaves the group leaderless — such groups are routed to `flagged.php` for manager succession via staged move [M1]. Frozen core-group membership is core data handled by core's own privacy machinery.

**Backup/restore** (§14.11): activity backup = instance + groups + members + quota + overrides (user & group scope; move-scope skipped with its move) + snapshots + penalty. **Excluded and README-documented [M2]:** `agrun` (operational log) and **pending staged moves** (transient manager state — a restore should never replay half-staged edits). `userinfo=false` → structural-only: quota rules + settings survive; groups/members/overrides/snapshots/penalties dropped (user-created content). Restore remaps `coregroupid` via core group mapping (core groups restore first), userids via standard annotation; missing mapped core group → coregroupid nulled + group flagged for re-freeze. Site-wide `userattr` is **not course data — excluded** from course backup (documented in README, §14.11).

**Security checklist** (§14.12) — per-slice gate items: `require_login($course,$cm)` + capability on every page/external; `require_sesskey()` on every POST; all params `PARAM_*`; `$DB` placeholders only (no concatenated SQL — grep-audited); output escaped via Mustache/renderers only; IDOR: every group/member/invitation/move/override id re-fetched server-side and ownership/scope verified against `$cm->instance` + actor (never trusted from the request); race-safety per A7; CSV hardening per §14; no secrets in logs.

---

## 16. Testing & CI (§15)

- **Generator** `tests/generator/lib.php`: create instance (limit shortcuts), group (state fast-forward), member (status), userattr, quota rule, override, move + Behat generator bridge (`behat_mod_selfselectadvanced_generator`) so features arrange data declaratively.
- **PHPUnit** (per slice, boundary trio "below/at/above" for every limit — §15.1): counting bases; reserved seats; acceptance cascade; guide-slot release on return; L5 consumed-between-submit-and-approve race; invitation-acceptance race (two accepts, lock forces loser refusal); quota evaluator + priority order; **override resolver P1–P15 + full §6.5 matrix**; penalty math (percent/points, waiver, date-override zero, recompute); staged-move joint validation (swap set, frozen-target sync, bypass); autogroup determinism (same seed ⇒ same result), relaxation logging, exhaustion residue, leader-slot fallback; freeze snapshot/restore + drift report; grandfathering (§4A.8 warn/keep/block/record); CSV importer (reject-unknown-user, name-mismatch warn, report counts); privacy provider; events payloads; backup/restore roundtrip (with/without userinfo).
- **Behat** (per user-facing flow): create→invite→accept (U3 search by name; the address search is gone and its ABSENCE is what the selector scenarios now assert — A14), decline+withdraw seats, transfer, step-out with replacement, submit+return+approve, freeze/bulk-freeze/unfreeze, staged move UI, overrides UI, quota panel wording, attributes admin (upload report), landing counters/disabled reasons, ledger download page presence. JS scenarios limited to autocomplete + modals (`@javascript` used sparingly — local Selenium constraint noted in docs/testing.md).
- **Local runs:** maintainer testbed 4.5/5.2 × pg/mysql (PHP 8.2/8.4 per branch) for PHPUnit; live m5pg/m5my for Behat; full remote matrix on the CI box (`dev-test.sh`). **Both DBs green = gate condition** (§15.2).
- **GitHub Actions** (`moodle-ci.yml`, moodle-plugin-ci): matrix `{MOODLE_405_STABLE×php8.2, MOODLE_502_STABLE×php8.4} × {pgsql (postgres:16), mariadb:10.11}` — phplint, phpcs (`--max-warnings 0`), phpdoc, savepoints, mustache, grunt, phpunit, behat. Repo self-sufficient for verification (§15.3) + README docker instructions (`moodlehq/moodle-docker`).
- **Per-slice audit** (§15.2): written report per slice — standards, §14.12 checklist, good-neighbour (§14.5), native-components (C9), raw-settings-read grep (§6.3.2), both-DB results — accumulated into `docs/audits/` and consolidated at delivery. The native-components grep covers CDN/vendor references **plus inline `<style>`/`<script>` blocks in templates and any runtime dependency appearing in `package.json`** [M4].

---

## 17. Slice plan (§16.3 — each closes via §15.2 gate before the next starts)

| # | Slice | Contents (review items owned by the slice in brackets) | Key tests |
|---|---|---|---|
| 0 | Skeleton | version/lib/mod_form/index/view stubs, install.xml, access.php, lang, icons (U2) [S5a styles.css bound] | plugin-ci matrix passes |
| 1 | Creation | activity model, groups, state T1/T7, uid A1 [S4 char(64), sanitised ≤12-char segment], landing, group page shell, counters | L3/L4 at creation, window |
| 2 | Invitations | candidates (U3), selector [S5b justification in audit, S6 full name-field set], send/accept/decline/withdraw/expiry task, seats, cascade [S2 state preconditions on all gatekeeper methods; B3 `can_accept` violation-block] | L2/L4 trio, races, cascade, stale-POST state guards |
| 3 | Succession | transfer + step-out (A3), replacement flow | L3 trio, L1 stepout, atomicity |
| 4 | Guide review | submit (T2), return (T3), approve (T4), guide lists+loads, A5 queue | L1/L5/quota gates, L5 race |
| 5 | Attributes | site admin page, CSV ingest (A9/U4), inline edit, caches, flagged missing [M3 user_deleted observer] | importer suite, observer test |
| 6 | Quotas | rule CRUD+priority [S1 store-enforced uniqueness, safe reorder], evaluator, bucket panel, submit/approve gates | evaluator+ordering+reorder suite |
| 7 | **Overrides** | resolver+store+UI+events [B5 per-mode field sets; B2/P16 leader-context assessment dates]; *retro-wire*: slices 1–6 gates now read resolver | §6.5 full matrix incl. P16; **exit condition [S3]: slices 1–6 suites re-run green with overrides active on both DBs** |
| 8 | Staged moves | move stage/list/joint-commit/cancel [B3 violation-block in validate_set], bypass overrides, frozen-target sync (pre-wired to slice 10 by interface) | joint validation suite |
| 9 | Penalty+grade | calculator [B2 leader-context dates], ledger, grade item, recompute+task, ledger page+export | penalty suite incl. leader-extension zero |
| 10 | Freeze/unfreeze | core-group sync, snapshots, drift, restriction warning, uninstall behaviour | snapshot/restore suite |
| 11 | Bulk ops | guide bulk freeze + filters, manager dashboard completion, flagged report | behat bulk flows |
| 12 | Auto-grouping | engine [B1 corrected sizing; B4 per-user cutoff pool + re-runs], task, manual trigger, run log UI, residue placement path | determinism+cascade suite incl. B1 overflow sweep + B4 exclusion |
| 13 | Compliance wrap | privacy provider [M1 leaderless→flagged], backup/restore [M2 documented exclusions], messaging polish, a11y pass | provider+roundtrip suites |
| 14 | Final audit + release | consolidated §15.2 audit report [M4 widened grep], README/CHANGELOG/screenshots (README records S7 + M2), Plugins-Directory package (§15.5), MATURITY_STABLE | full matrix on CI box + GH Actions |

Slices 1–6 depend on the resolver **interface** from day one (constructor-injected; null-object store until slice 7) so §10's "no raw reads" holds from the first line without rework.

---

## 18. Traceability — requirement → component → test

U-rows = user amendments this session. Component paths relative to plugin root; tests are PHPUnit classes unless `behat:`.

| Req | Requirement (short) | Component | Test |
|---|---|---|---|
| §4.1–4.10 | Instance settings incl. window, penalty scheme, guide mode, expiry, autogroup | `mod_form.php`, `activity.php` | `mod_form_test` (§4A.7 rows), behat: settings |
| §4A.1 L1 | min size counting+gates | `local/groups`, `gatekeeper` (§7 matrix rows) | `limits_l1_test` trio |
| §4A.2 L2 | reserved seats | `invitations`, `gatekeeper` | `limits_l2_test`, `invitation_race_test` |
| §4A.3 L3 | lead cap incl. succession/moves/autogroup | `gatekeeper`, `succession`, `moves`, `autogroup` | `limits_l3_test` |
| §4A.4 L4 | membership cap + cascade | `invitations::cascade` | `limits_l4_test`, `cascade_test` |
| §4A.5 L5 | guide load, release-on-return, approve re-check | `gatekeeper`, `state` T3/T4 | `limits_l5_test`, `approve_race_test` |
| §4A.6 | limit displays + disabled reasons | `output/*`, templates, tables | behat: counters/reasons |
| §4A.7 | form validation | `mod_form.php` | `mod_form_test` |
| §4A.8 | grandfathering | `gatekeeper::compliance_report`, `lib.php` | `grandfather_test` |
| §5 | state machine, T1–T7 | `local/state` | `state_test` (+illegal transitions) |
| §6.1 | creation + uid (A1) | `groups`, `api` | `creation_test` |
| §6.2 | invitation-only join, course-level pool, native selector, blocked-reasons, deep-link message | `candidates`, `external/search_candidates`, `invitations`, `notifier` | `candidates_test`, behat: invite |
| §6.3 | leave/deletion/manager-removal-only-via-moves | `groups`, `moves` | `leave_test` |
| §6.4 | transfer/step-out | `succession` (A3) | `succession_test`, behat |
| §6.5 | guide review | `state` T2–T4, `review.php` | `guide_review_test`, behat |
| §7 | staged moves, joint sets, frozen targets, per-move overrides | `local/moves` (A4/A6) | `moves_test`, `move_set_test` |
| §8.1 | plugin-only attrs, admin ingest, no user creation | `attributes/*`, `attributes.php` (C11 greps) | `csv_importer_test` |
| §8.2 | quota rules+priority+panel+gates | `quota/evaluator`, `quotas.php` | `quota_test`, behat: panel |
| §9.1–9.5 | autogroup trigger/algorithm/cascade/exhaustion/result | `autogroup/engine`, `task/run_autogrouping` | `autogroup_test` (seed determinism) |
| §10 | override subsystem | `override/resolver`+`store`, §6 design | `override_resolver_test` P1–P15 |
| §11 | ledger, grade item, recompute, null-until-placed | `penalty/*`, `lib.php` grades | `penalty_test`, `grade_test` |
| §12 | freeze/unfreeze/sync/snapshots/drift/bulk | `local/freeze` | `freeze_test`, `drift_test`, behat: bulk |
| §13 D1–C15 | resolved decisions | this plan §0 (A-map), enforced across components | audit checklist per slice |
| §14.1 | 4.5+5.x, PHP 8.1+, both DBs, XMLDB | `version.php`, CI matrix | CI |
| §14.2 | native components only | all UI per §13 | audit grep (no CDN/vendor) |
| §14.3 | $DB + transactions | A7 in every service | race tests |
| §14.4 | core tables everywhere | `classes/table/*` | behat: sort/filter/download |
| §14.5 | good neighbour | `freeze` ownership, prefixes, uninstall notice | `uninstall_test`, audit |
| §14.6 | schema | §2 (refined: +`agrun`, +U4 `mobile`) | install/upgrade on both DBs |
| §14.7 | events | `classes/event/*` (§12 list) | `events_test` |
| §14.8 | messaging | `db/messages.php`, `notifier` | `notifier_test` |
| §14.9 | 4 scheduled tasks | `classes/task/*` | task tests |
| §14.10 | privacy | `privacy/provider` | `provider_test` |
| §14.11 | backup/restore | `backup/moodle2/*` | `backup_restore_test` |
| §14.12 | security checklist | per-slice audit + patterns §15 | audit reports |
| §14.13 | UI inventory, GET-read-only | §13 pages | behat suite |
| §14.14 | WCAG 2.1 AA, non-colour quota panel | templates | behat a11y checks + audit |
| §15.1–15.5 | tests-with-code, gates, CI self-sufficiency, upgrade-safe, moodle.org package | `tests/*`, workflow, `db/upgrade.php`, release docs | the gates themselves |
| U1 | rename → selfselectadvanced | everywhere | CI (component checks) |
| U2 | full icon set | `pix/` (§1.3), `FEATURE_MOD_PURPOSE` | plugin-ci + manual |
| U3 | search by first/last name; **email matching withdrawn** (wave 3D, A14) | `search_candidates` (A14) | `candidateaddress_test`, `external_search_test`, `contactreach_test`; behat: `invitations.feature` "An email address finds nobody in the native selector", `contactprivacy.feature` "The invite picker promises only a name search and matches only names" |
| U4 | ingest columns incl. mobile | §2.4, `csv_importer` (A9) | `csv_importer_test` |

---

## 19. Surfaced points and their resolutions

1. **`self_select_advanced` → `selfselectadvanced`** — Moodle forbids underscores in mod names; resolved with user 2026-07-23 (U1).
2. **Snapshot vs staged-moves-into-frozen-groups** (§5 "restored to the frozen snapshot" vs §7 moves updating frozen groups): resolved as A6 (moves refresh the snapshot; only out-of-band core edits are discarded). Reviewer concurred (gate-1 review Part 1).
3. **U4 name columns:** cross-check with warnings (A9); standing offer to switch to reject-on-mismatch remains open.
4. **S7 email matching:** decided 2026-07-24 (autonomous, per user instruction) — matching for all inviters, display identity-gated, README-documented. **REVERSED 2026-08-02 (1.20.1 wave 3D):** matching by address is withdrawn for every viewer in both switch states; only the identity-gated *display* survives. The residual disclosure S7 accepted was an address oracle, and the cardinal rule forbids one. Current rationale, and the tests that pin it, are in A14.

**Gate 1 status: APPROVED 2026-07-24** — review verdict "aligned", B1–B5 folded into this v1.1 (see markers), S1–S7/M1–M4 assigned to owning slices in §17. Slice 0 in progress.
