# Group self-selection (Advanced) — `mod_selfselectadvanced`

A Moodle activity module for constraint-governed group formation. Students
self-organise into groups under teacher-defined limits and composition
quotas; a project guide reviews, approves and freezes each group; frozen
groups are pushed into Moodle core course groups so every downstream
activity can use them.

**Status: under development.** Built slice by slice against the
architecture plan in [docs/architecture.md](docs/architecture.md); each
slice closes with PHPUnit + Behat green on MySQL/MariaDB *and* PostgreSQL
plus a written audit in `docs/audits/`.

## Requirements

- Moodle 4.5 LTS or 5.x
- PHP 8.1+
- MySQL/MariaDB or PostgreSQL (equal support, XMLDB only)

## Feature outline

- Invitation-only group formation with reserved seats, membership caps and
  lead caps, all enforced atomically
- Guide review workflow: submit → approve/return → freeze into core groups
- Plugin-local participant attributes (gender, department, sub-department,
  mobile) ingested by site administrators via CSV — never touching user
  profiles; the plugin never creates user accounts
- Composition quotas with priority ordering and a live deficiency panel
- A single override-resolution service for dates, all five numeric limits,
  quota exemptions and penalty waivers
- Transactional staged moves for managers
- Per-group penalty ledger feeding one gradebook item
- Deterministic auto-grouping of groupless students at cutoff
- Moodle-native components only: core forms, selectors, tables, Mustache
  templates and AMD modules. **Third-party libraries: none.**

## Documentation

- [docs/architecture.md](docs/architecture.md) — binding architecture plan
  (schema, state machine, capability map, override design, limits matrix,
  traceability)
- [docs/reviews/](docs/reviews/) — gate reviews
- [docs/audits/](docs/audits/) — per-slice audit reports

Install, capability table, admin walkthrough, privacy statement and
backup notes are completed in the release slice.

## License

GPL v3 or later. Copyright 2026 JSP <jsp@jsp.net.in>.
