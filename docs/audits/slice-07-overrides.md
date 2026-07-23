# Slice 7 audit — override subsystem (write path + UI)

**Date:** 2026-07-24 · **Slice contents:** override store (single write path; B5 per-scope field sets enforced as coding errors; one row per (activity, scope, target) with save-as-update; transactional CRUD firing `override_created/updated/deleted` with actor + target + old/new values), `limits_changed` event wired into `lib.php` update path (§4A.8/§14.7, old+new of the five limits), overrides.php (user/group/guide tabs, list with per-field summaries, add/edit/delete; mod_assign pattern), override_form (B5 field sets per mode, optional-value semantics, §4A.7 cross-checks on co-set fields), settings-nav link, generator + behat entity, 31 lang strings, and the **P1–P16 resolver matrix test** with the **S3 boundary re-runs under active overrides**.

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` | PASS (0 findings) |
| PHPUnit **full suite (S3 regression)**, 5.2/PostgreSQL | **PASS 49/49, 264 assertions** |
| PHPUnit full suite, 5.2/MySQL | **PASS 49/49, 264 assertions** |
| Behat non-JS (18 scenarios), m5pg | **PASS 245/245** (first post-init run flaked one scenario; identical code re-ran clean — watched item) |
| Behat non-JS (18 scenarios), m5my | **PASS 245/245** (same first-run flake pattern, same clean rerun) |
| Behat `@javascript` override-creation flow | tagged; runs on GH Actions (autocomplete requires JS) |

## §6.5 / §15.1 coverage delivered this slice

- **P1–P7** date matrix incl. the mixed P7 row (user timedue + group timecutoff) with per-field provenance ✔
- **P8–P11** scope-bound limits; guide-scope independent of user-scope for the same person; fall-through on other targets ✔
- **P12 + P16**: waiver flag; assessment dates use the **leader's** user context — another member's extension does not leak; group override still wins ✔
- **P13** move-bypass parsing · **P14** one-row-per-target with create→update event sequence and old/new payloads · **P15** orthogonality ✔
- **B5 negative**: user-scope `minsize` write = coding_exception ✔
- **S3 re-run**: L3/L4 creation trio under a user override (below/at via new cap, above refused), L2 seats under a group maxsize override, L5 under a per-guide override, quota gate flipped by a P11 exemption — all against the live gatekeeper paths, plus the full 44 earlier tests re-passing with override rows in play ✔
- Behat: generator-arranged override lifts the landing counter ("You lead 1 of 3 groups"); delete restores the activity setting ("1 of 1") ✔

## Incidents during the gate (watchdog log)

1. Fixture reused a (group,user) member row → unique-index violation; corrected to flip the existing row's status (and it re-proved A2's one-row model).
2. `assertSame` vs DB-string types in event payload comparison → `assertEquals`.
3. First-run-after-behat-init flake (1/18, both instances, different scenarios not identifiable post-hoc, immediate reruns clean twice). Watched: if it recurs at slice 8's gate, run with faildump armed from the start.

## Security / native / good-neighbour

- overrides.php `:override`-guarded; edit via formslib POST; delete POST+sesskey; override ids activity-scoped (IDOR) ✔
- Resolver remains the only read path (grep re-run: no consumer touches `selfselectadvanced_override` outside `override\*`) ✔
- Target pickers: core autocomplete (static options) / core selects; tabs = Bootstrap nav; zero new JS ✔

**Gate: GREEN.** The C13 design gate's implementation is complete: every effective value in the plugin now demonstrably flows through the resolver with the full precedence matrix tested. Slice 8 (staged moves) may start.
