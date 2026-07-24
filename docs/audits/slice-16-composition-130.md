# 1.3.0 audit — slot templates, programme, proposals, guide notes (2026-07-24)

Contents: slot-based composition templates (`selfselectadvanced_qslot`,
`quota\slots` engine: greedy booked-member evaluation, one slot per
member, value/distinct match, overlap flag; wired into
`evaluator::evaluate` so submission/approval/freeze gate on it and the
deficiency panel shows slot lines); programme attribute
(`userattr.program`, vocabulary rows `dept.kind='program'`, CSV column
"Type of Program"); ADMIN-LEVEL POLICY REVERSAL: the CSV ingest now
AUTO-CREATES unknown departments/sub-departments/programmes with
warnings (was: reject) — deliberate, per user 2026-07-24; blank CSV
templates (header + one scaffold row per dept/subdept, per-programme
stamp); proposal upload (filearea `proposal`, itemid=group,
`proposalrequired` activity setting gating `can_submit`, pluginfile
serving member/staff-gated, in backup via annotate/add_related_files);
guide rich-text notes (`group.guidenotes/format`, review page editor,
staff-only); limits-after-freeze DEFINED: grandfathered, never
reshaped, out-of-limit groups on the flagged report (help strings +
README); `delete_instance` fix (template + qslot rows were orphaned);
mod_teamrecruit UNINSTALLED from all instances and extracted as a
separate tool (repo kept).

Gate: `RESULT fail=0` at 81 PHPUnit / 32 Behat ×2 DBs; three remote
phpcs rounds (multi-line calls; a docblock split by insertion — new
checklist item: inserting a function above another must not separate
that function from its docblock).
