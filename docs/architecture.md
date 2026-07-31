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
- **U3 — Selector search:** candidate selection must match on **first name, last name, or email** in every user selector.
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

  Two rules, and no others: **acquire in ascending rank, release in reverse**; and **`group:` is the only rank that may stack, and then only in ascending numeric group id**. `locks::acquire()` reports any violation at `DEBUG_DEVELOPER`, which PHPUnit turns into a failure. A new lock resource must add its prefix to `locks::ORDER` **and** to this table in the same edit — an unranked prefix throws `coding_exception`. Messages are never sent under any of these locks (`notifier::send()` guards it at runtime); events are not triggered under one either, except the three grandfathered in 1.19.2 (`move_committed`, `leadership_transferred`, `join_decided`), whose only consumers are core logstores.
- **A8 — Quota rules live on a dedicated management page** (`quotas.php`, `:manage`), not inside `mod_form` — prioritised rule rows with value pickers fed by ingested attribute values need a table+form page (mod_form keeps the scalar settings). Spec §4.7's "activity settings" placement is preserved logically: the page is linked from the activity's settings menu and manager dashboard.
- **A9 — CSV identity handling (U4):** rows match existing users **by username** (mandatory column); if a row's username is blank and an optional `email` column is present, email is the fallback key (keeps spec §8.1's match-on-username/email intent under the U4 format). `First name`/`Last name` are **cross-check columns**: mismatches against the matched Moodle account are reported as row warnings (row still ingested — username is authoritative). Unknown users → row **rejected and reported** (C11; the plugin never creates users). `Mobile Number` is stored plugin-locally, shown only to `:viewall` holders, never written to user profiles.
- **A10 — Group deletion is a hard delete** (allowed only in `forming`): members/invitations rows removed, notifications sent, `group_deleted` event carries the payload. No tombstones — deleted groups release every counted slot (§4A.3/.5).
- **A11 — Guide return clears `guideid`** (frees the L5 slot per §6.5) and stores the mandatory comment on the group (`returncomment`, latest) — full comment history remains in the `group_returned` events.
- **A12 — Penalty ledger holds one current row per group** (unique `groupid`), recomputed in place (settings save + reconciliation task + approval); every recomputation emits an event. Explicit zero rows are stored for on-time groups (exportable). Grade floor is 0 (no negative grades); ledger keeps the uncapped arithmetic in `basis`.
- **A13 — Auto-group sizing is deterministic** *(corrected per review B1 — the earlier decrement step could breach L2)*: with pool size P and effective band [min,max], take `g = ceil(P / max)`; if `g·min > P`, fall back to `g = floor(P / min)`; if `g = 0` the whole pool is residue (§9.4). Groups are filled up to `g·max` and any remainder goes to residue per §9.4 — no group ever exceeds `max_size`. (min 4 / max 6 / pool 7 → one group of 6 + 1 residue student; pool 24 → four groups of 6.) Members are drawn by seeded shuffle (`mt_srand(seed)`) so a stored seed replays the run exactly.
- **A14 — Email-matching visibility (U3, S7 decided):** the candidate search WHERE clause matches name fields/email for everyone allowed to invite; the email is *displayed* in results only when the viewer holds `moodle/site:viewuseridentity` (or `moodle/course:viewhiddenuserfields`) in course context, per core identity-field rules. **S7 decision (2026-07-24, autonomous per user instruction):** email *matching* stays available to all inviters — U3 explicitly requires students to select peers by email ID, and the capability-gated alternative would break that exact requirement for the primary audience. Residual disclosure (a student can confirm an address belongs to a named course peer) is accepted and documented in the README privacy statement. Name matching covers the **full core name-field set** (`\core_user\fields::for_name()` — first/last/middle/alternate), mirroring core participants search [S6].

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
| mobile | char(32) | NULL | U4 — contact info, `:viewall` display only, **not** a quota dimension |
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
| targetgroupid | int(10) | NN | spec §7: removal is always a move *to* somewhere |
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
| `mod/selfselectadvanced:unfreeze` | module | **editingteacher** (D4) | — |
| `mod/selfselectadvanced:manage` | module | editingteacher | RISK_CONFIG |
| `mod/selfselectadvanced:override` | module | editingteacher | RISK_CONFIG |
| `mod/selfselectadvanced:ingestattributes` | **system** | *(none — site admins only)* | RISK_PERSONAL |
| `mod/selfselectadvanced:viewall` | module | editingteacher, teacher | RISK_PERSONAL |

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
| P14 | **tie** (duplicate rows same scope+target — store-prevented) | — | — | deterministic: highest id (latest) wins + `debugging()`; covered by test |
| P15 | user+group date override **and** group quota exemption together | set | set | dates per P1–P7; quota per P11 — orthogonal quantities never interact |
| P16 | **group-level assessment dates** (penalty §10, and any date question asked *of a group*) [B2] | set | set (leader) | standard chain with the **leader as the user context**: group.F > *leader's* user.F > activity.F — a leader personally granted an extension who forms within it incurs no penalty; other members' date overrides affect only their own actions (P1–P7), never the group's assessment |

Invalid scope/type combinations (e.g. a user-scope `minsize`) are rejected by `override\store` at write time and, defensively, ignored by the resolver with `debugging()`.

### 6.3 Behavioural guarantees (§10)

1. A group approved within its overridden (effective) window incurs **no penalty** — the calculator only ever sees effective dates (P12).
2. No enforcement point reads raw settings: gatekeeper's constructor takes the resolver; raw-column reads outside `activity.php`/resolver are grep-audited per slice (review gate item).
3. Every override create/update/delete → `override_created/updated/deleted` event with actor, target, old and new values.

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

- **Freeze:** create core group `[{activity idnumber|name}] {group name}`; add confirmed members (`groups_add_member`); ensure grouping `Self-selection: {activity name}` (created once per activity, id remembered); append snapshot (roster JSON); set coregroupid/timefrozen. Idempotent: existing owned core group is reconciled member-by-member; core group deleted externally → recreated on re-freeze (§12 drift rule).
- **Bulk freeze:** guide dashboard core table filtered (state, quota-compliant, approved before/after, department) + select-all-matching → per-group freeze loop, per-group transaction, summary report.
- **Unfreeze:** `:unfreeze` only. Pre-check: core group referenced in course `availability` restrictions → warning list first (§12). Delete **only plugin-owned** core group (`coregroupid` match), remove from grouping, restore roster to **latest snapshot** verbatim (A6), state `firm`, flag out-of-limit vs current settings (§4A.8). Out-of-band core-group membership drift (vs snapshot) is **reported** (manager notice + event payload), never merged (§14.5).
- **Uninstall:** plugin tables dropped; frozen core groups/groupings **left in place** as course data, admin informed via uninstall notice (§14.5).

---

## 12. Events, messaging, scheduled tasks

**Events** (`\core\event` base, CRUD verbs, objecttable set, module logstore-visible — §14.7): `group_created`, `group_deleted`, `invitation_sent`, `invitation_accepted`, `invitation_declined`, `invitation_expired`, `invitation_withdrawn`, `leadership_transferred`, `group_submitted`, `group_returned`, `group_approved`, `group_frozen`, `group_unfrozen`, `move_staged`, `move_committed`, `move_cancelled`, `autogroup_run`, `attributes_imported`, `attributes_updated`, `limits_changed` (old+new §4A values), `override_created`, `override_updated`, `override_deleted`, `penalty_recomputed` (A12), `leave_requested`, `course_module_viewed`.

**Message providers** (`db/messages.php`, all user-preference-respecting, deep links — §14.8): `invitation` (→invitee), `invitationresult` (→leader: accept/decline/expire/cascade with reason), `nomination` (→nominee), `nominationresult` (→leader), `leaverequest` (→leader), `leaveresult` (→member), `guidequeue` (→guide on submission / manager in A5 mode), `groupreturned` (→leader, with comment), `groupapproved` (→all confirmed members), `groupfrozen` (→members), `groupunfrozen` (→members+guide), `movecommitted` (→moved student, both leaders), `autogroupresult` (→placed students; →manager summary incl. residue), `deadlinereminder` (→students not yet in a firm group, 24 h before eff. due), `gradepenalty`? — no: penalties surface via gradebook + ledger (not a nag). Sends centralised in `local\notifier`.

**Scheduled tasks** (`db/tasks.php` — §14.9): `expire_invitations` (hourly; effective expiry via resolver; auto-decline + seat release + notify), `run_autogrouping` (5-min cadence; fires per enabled activity whenever unprocessed groupless students exist whose **per-user effective cutoff** has passed [B4] — i.e. it re-runs as override-extended windows close, not only once at activity cutoff), `reconcile_penalties` (nightly full recompute — catches date-override edits), `deadline_reminder` (hourly window scan, per-user effective due, sent-marker via user preference key).

**Observer** (`db/events.php`) [M3]: `\core\event\user_deleted` → delete the user's `userattr` row, scrub pending member/nomination references, purge the distinct-value MUC cache (which is also invalidated on ingest and inline edit).

---

## 13. Pages, templates, tables, AMD (C9/C12/C10, §14.13 UI inventory)

| Page | Who | Built from |
|---|---|---|
| `view.php` landing | all | templates `landing_student` (my groups w/ lead/member counters §4A.6, my invitations+nominations w/ accept/decline, create button w/ reason when disabled), guide/manager panels link onward |
| `groupedit.php` | leader | moodleform: name (unique-in-activity check), title, brief (core editor) |
| `group.php` | leader/members (+viewall read) | roster template, seat counter, quota bucket panel (§8), invite autocomplete (below), succession controls, submit-to-guide (guide select w/ load figures), leave request; every action POST+sesskey |
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

**Candidate selector (C10 + U3):** `form\candidates_selector` — core `autocomplete` element, `ajax` handler = AMD `mod_selfselectadvanced/candidateselector` (thin wrapper of the core form-autocomplete transport, the enrol-manual pattern) → external function `search_candidates` (`db/services.php`, loginrequired, capability-checked): pool = `get_enrolled_users($ctx,'mod/selfselectadvanced:respond')`, WHERE matches the **full core name-field set** (`\core_user\fields::for_name()` — first/last/middle/alternate, mirroring core participants search [S6]) **OR email** (`$DB->sql_like` + cross-DB concat), minus gatekeeper-ineligible users, each result labelled with its eligibility reason when excluded-but-shown is needed (spec §6.2 "says why"); email match per A14/S7, email display identity-gated. Same element reused for nomination (roster-scoped), override user pick, move stage. **C9 justification on record [S5b]:** core's `form_user_selector`-style providers cannot attach per-candidate eligibility filtering and localized refusal reasons, which §6.2 requires — `candidateselector.js` therefore exists solely as the transport for our provider and adds no UI of its own; per-slice audits treat it as this justified exception.

**styles.css bound [S5a]:** plugin-specific structural selectors only (all prefixed `.selfselectadvanced-`); **no** colour, spacing, or typography values duplicating theme tokens — visual styling comes from the Bootstrap utility classes Moodle ships. Audited each slice.

**Templates:** one Mustache per renderable, core-component partials (notifications, modals via `core/modal_save_cancel`, badges, `core/form_autocomplete_*` internals untouched). No HTML in PHP (renderer + templates only), no inline JS (all behaviour via core AMD or the single custom module), all strings via `get_string`, RTL-safe, WCAG 2.1 AA (labels, aria-live on the deficiency panel, non-colour state markers).

---

## 14. Attributes subsystem (§8.1 + U4/A9)

- Site-wide table §2.4; **read** paths for activity roles exactly where flows need values (§8.1): quota panels, rosters (mobile `:viewall`-only), auto-grouping reports, flagged lists — via `attributes\manager::get_for_users()` (bulk, cached per request).
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
- **Behat** (per user-facing flow): create→invite→accept (U3 search by lastname & email included), decline+withdraw seats, transfer, step-out with replacement, submit+return+approve, freeze/bulk-freeze/unfreeze, staged move UI, overrides UI, quota panel wording, attributes admin (upload report), landing counters/disabled reasons, ledger download page presence. JS scenarios limited to autocomplete + modals (`@javascript` used sparingly — local Selenium constraint noted in docs/testing.md).
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
| U3 | search by first/last name or email | `search_candidates` (A14) | `candidates_test`, behat: search-by-email |
| U4 | ingest columns incl. mobile | §2.4, `csv_importer` (A9) | `csv_importer_test` |

---

## 19. Surfaced points and their resolutions

1. **`self_select_advanced` → `selfselectadvanced`** — Moodle forbids underscores in mod names; resolved with user 2026-07-23 (U1).
2. **Snapshot vs staged-moves-into-frozen-groups** (§5 "restored to the frozen snapshot" vs §7 moves updating frozen groups): resolved as A6 (moves refresh the snapshot; only out-of-band core edits are discarded). Reviewer concurred (gate-1 review Part 1).
3. **U4 name columns:** cross-check with warnings (A9); standing offer to switch to reject-on-mismatch remains open.
4. **S7 email matching:** decided 2026-07-24 (autonomous, per user instruction) — matching for all inviters, display identity-gated, README-documented; rationale in A14.

**Gate 1 status: APPROVED 2026-07-24** — review verdict "aligned", B1–B5 folded into this v1.1 (see markers), S1–S7/M1–M4 assigned to owning slices in §17. Slice 0 in progress.
