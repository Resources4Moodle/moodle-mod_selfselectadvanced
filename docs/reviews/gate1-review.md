# Gate 1 review — `mod_selfselectadvanced` Architecture Plan v1.0

**Verdict: aligned, approve subject to the five blocking items below.** The counting bases for L1–L5 are correct, the resolver-as-sole-authority design honours C13, the §7 matrix genuinely maps §4A onto functions, and raising A6/A9 in §19 rather than quietly working around them is exactly the behaviour the spec asks for. Items are numbered for the plan revision to answer point by point.

---

## Part 1 — Three verification checks

### Q1. Are overrides wired in for group size and dates? **Yes for both, with two holes.**

**Group size (L1/L2)** is wired end to end: storage `minsize`/`maxsize` at group scope (§2.6); resolver methods `effective_minsize(groupid)` / `effective_maxsize(groupid)` (§6.1); precedence row P8 (§6.2); every size check flows through the resolver-fed gatekeeper, and §6.3.2 forbids raw reads; boundary tests run twice, with and without overrides (§6.5). Nothing further needed except **B5**.

**Dates** are wired end to end and, on the precedence question, better than the spec required: `effective_dates(userid, ?groupid)` with **per-field** fallthrough and provenance, and P1–P7 enumerating mixed combinations (user holds `timedue`, group holds `timecutoff`) the way `mod_assign` behaves. The Window column in §7 covers creation, invitation send, acceptance, nomination and submission; `expire_invitations` and `deadline_reminder` both resolve effective dates rather than raw ones (§12).

Two date holes: **B2** (penalty resolution silently drops user overrides) and **B4** (the auto-grouping pool ignores per-user cutoff overrides).

### Q2. Are Moodle-native elements baked in? **Yes.**

C9 is enforced at four independent levels, which is the right number: design (§13 — core `autocomplete`, core tables, `core/modal_save_cancel`, `core/checkbox-toggleall`, core-component partials, no HTML in PHP, no inline JS); assets (§1.3 — two original GPL SVGs for the activity icon, every other glyph from core `pix_icon`, zero image dependencies); tooling (§16 — phpcs, mustache lint, grunt in the CI matrix); and per-slice audit (§16 — an explicit native-components grep for CDN/vendor references). §15.5's "third-party libraries: none" is therefore a claim the build can actually substantiate.

Three loose threads: **S5** (`styles.css` and the one custom AMD module are the only places C9 can erode — tighten the rule and record the justification), **M4** (widen the audit grep).

### Q3. Is selection by first name, last name or email baked in? **Yes.**

U3 is carried at every level: A14 states the rule and its identity-display caveat; §13 specifies the WHERE clause matching firstname, lastname, the full-name concat and email via `$DB->sql_like` with `sql_fullname()`/`sql_concat()` so it behaves identically on MySQL and PostgreSQL; the same element is reused for nomination, override user pick and move staging, so the behaviour is not confined to invitations; §16 names Behat coverage for search by last name *and* by email; §18 carries the U3 traceability row. The pool itself is `get_enrolled_users($ctx, ':respond')` — course enrolment, not activity participation, so C10 holds.

Two refinements: **S6** (name-field coverage) and **S7** (one privacy decision for you to make, not a defect).

---

## Part 2 — Blocking (resolve before slice 0)

**B1 — A13's sizing rule can produce groups larger than `max_size`.** The decrement step makes groups *bigger*, breaching L2. With min 4 / max 6 / pool 7: `g = ceil(7/6) = 2`, `7 < 8`, decrement to `g = 1` → one group of 7. Swept across min/max 2–8 and pools 1–39, 154 combinations overflow. Fix, preserving the "fewest, fullest" intent: take `g = ceil(P/max)`; if `g·min > P`, fall back to `g = floor(P/min)`, fill up to `g·max`, and send the remainder to residue per §9.4. Pool 7 → one group of 6 plus one residue student; pool 24 → four groups of 6, unchanged.

**B2 — §10 drops user date overrides from penalty resolution.** The plan asserts "user overrides don't apply to a group-level assessment", but spec §11 says only that groups approved under a date override incur no penalty, and §10's precedence is group > user > activity with no carve-out. A leader personally granted an extension who forms within it is penalised under the plan's reading. Either resolve group dates as `group override > leader's user override > activity` and state whose user override counts, or keep the exclusion and move it into §19 as a surfaced deviation for a decision. Narrowing precedence for one quantity inside the resolver is the single thing §10 forbids.

**B3 — `would_increase_violation()` is not wired to every increasing action.** §7 consults it in `can_create_group`, `can_invite` and `can_assign_guide`. Acceptance also increases group size: after `max_size` is tightened, an invitation already outstanding to an oversized group commits through `can_accept`. Add `can_accept` and `moves::validate_set()` to that row.

**B4 — The auto-grouping pool ignores per-user cutoff overrides.** §12's `run_autogrouping` fires "per activity where eff. cutoff passed" and §9 sweeps everyone confirmed in no group at `timecutoff`. A student holding a user-scope `timecutoff` extension is still inside their own formation window and must not be swept in. The engine's pool query must exclude users whose effective cutoff has not passed, and the task must re-run for them as their windows close.

**B5 — The override form's field scoping is unspecified.** §6.4 lists "date fields, cap fields, flags" as one undifferentiated set. Since gate 1 *is* the override design gate (C13), state the field set per mode explicitly: user scope → dates + `maxlead` + `maxmembership`; group scope → dates + `minsize` + `maxsize` + `quotaexempt` + `penaltywaived`; guide scope → `maxguided` only. `store` already rejects invalid scope/type pairs (§6.2) — the form must not be able to offer them in the first place.

---

## Part 3 — Non-blocking, fix in the owning slice

**S1 — `unique(activityid, priority)` blocks quota reordering.** Swapping two rules transiently duplicates a priority; neither MySQL nor PostgreSQL defers a plain unique index. Reorder in two phases via a negative temp value, or drop the constraint and enforce uniqueness in the store. (Slice 6.)

**S2 — The §7 matrix has no state column.** Spec §5 locks membership at `pending_guide`, but invitation send, leave confirm and nominate are guarded on limits only, with the state guard implicit in `local\state`. Since pages call the gatekeeper directly, add an explicit state precondition per row so a stale `group.php` POST cannot invite into a submitted group. (Slice 2.)

**S3 — Slice 7 needs a regression re-run, not just wiring.** Slices 1–6 pass their §15.2 gates against a null override store; once the real resolver lands those gates are no longer evidence. Make "re-run slices 1–6 suites with overrides active" an explicit exit condition of slice 7. §6.5 already runs the boundary suite twice — this only makes it a gate item.

**S4 — `pluginuid char(32)` will truncate.** Course shortnames reach 255 characters and carry spaces and unicode. Uniqueness survives (the id carries it), readability does not. Sanitise to `[A-Z0-9]`, cap the shortname segment near 12 characters, widen the column to 64. (Slice 1.)

**S5 — Close the two C9 gaps.** (a) Bound `styles.css` explicitly: plugin-specific structure only, no colour, spacing or typography values that duplicate theme tokens — use the Bootstrap utility classes shipped with Moodle. (b) Record in §13 *why* core's `core_user/form_user_selector` is insufficient (per-candidate eligibility filtering and refusal reasons), so the per-slice audit reads `candidateselector.js` as a justified exception rather than a C9 breach. (Slices 0 and 2.)

**S6 — Widen name matching.** `sql_fullname()` honours `fullnamedisplay`, but sites using middle or alternate names will miss matches. Core's participants search matches across the full set of name fields; mirror that field list so U3 behaves the same here as in core lists. (Slice 2.)

**S7 — One decision for you on email matching.** A14 argues that since candidates are course peers, matching on email leaks nothing beyond enrolment. Nearly right: it also confirms that a *specific email address belongs to a specific named person* in this course, to a student who may not hold `moodle/site:viewuseridentity`. Two defensible options — accept it and document it (email match for all inviters, display still identity-gated), or restrict email matching to viewers holding the identity capability and leave students matching on names only. Your call; I have implemented neither in the plan.

---

## Part 4 — Minor

**M1** — Privacy deletion blanks `leaderid` to 0, leaving a group with no leader and breaking the "leader is a confirmed member" invariant. Route those groups to `flagged.php`.
**M2** — Pending staged moves are absent from the backup set. Defensible as transient state, but document it beside the `agrun` exclusion in the README.
**M3** — The MUC distinct-value cache needs invalidating on user deletion, not only on ingest and inline edit.
**M4** — Extend the native-components audit grep beyond CDN and vendor paths to cover inline `<style>`/`<script>` blocks in templates and any runtime dependency added to `package.json`.

---

## Gate decision

Fold B1–B5 into plan v1.1 and record S1–S7 and M1–M4 against their owning slices in §17. No schema change is implied beyond S4's column width; B1–B4 are logic corrections inside `autogroup\engine`, `penalty\calculator`, `rules\gatekeeper` and `task\run_autogrouping` respectively, and B5 is a §6.4 specification detail. With those in, gate 1 is approved and slice 0 may start.
