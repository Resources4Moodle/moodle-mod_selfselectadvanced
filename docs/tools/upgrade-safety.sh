#!/bin/bash
# Classes whose METHODS are called from db/upgrade.php must not query
# plugin tables.
#
# During an upgrade step the PHP is already the new code while the
# schema is still at whatever savepoint that step begins from. A class
# that queries a plugin table therefore behaves differently depending on
# where the site is upgrading FROM - and the only paths that break are
# the old ones, which is exactly what nobody tests, because the
# current-version upgrade keeps passing. (External audit MED-UPG-002.)
#
# A CONSTANT reference (state::FROZEN) is not a method call: it executes
# none of the class's code and is schema-independent, so it is not
# flagged. Flagging it would make this check cry wolf, and a check that
# cries wolf gets switched off - which is the same failure as a check
# that never fires at all.
SRC=${1:?usage: upgrade-safety.sh <plugin-dir>}
UPG="$SRC/db/upgrade.php"
if [ ! -f "$UPG" ]; then
  echo '  upgrade-safety-ok (no db/upgrade.php)'
  exit 0
fi

fail=0
checked=0

# Only method INVOCATIONS: \mod_x\local\thing::method(  — a method name
# starts lower-case; a constant is upper-case and takes no parentheses.
classes=$(grep -oP '\\mod_[a-z_]+(?:\\[A-Za-z0-9_]+)+(?=::[a-z_][A-Za-z0-9_]*\s*\()' "$UPG" | sort -u)

for class in $classes; do
  rel=$(printf '%s' "$class" | sed -E 's#^\\mod_[a-z_]+\\##; s#\\#/#g')
  file="$SRC/classes/$rel.php"
  if [ ! -f "$file" ]; then
    continue
  fi
  checked=$((checked + 1))
  # A plugin table appears as '<table>' or {<table>} in DB calls. The
  # class NAME never appears in that form, so this cannot self-match.
  hits=$(grep -cE "['{]selfselectadvanced_[a-z]+" "$file" || true)
  if [ "$hits" != "0" ]; then
    echo "  upgrade-safety-FAIL: classes/$rel.php has methods called from db/upgrade.php and makes $hits plugin-table reference(s)"
    echo "    Inline the logic in the upgrade step, or keep that class core-only."
    grep -nE "['{]selfselectadvanced_[a-z]+" "$file" | head -5 | sed 's/^/      /'
    fail=1
  fi
done

if [ "$fail" -eq 0 ]; then
  echo "  upgrade-safety-ok ($checked class(es) checked)"
fi
exit $fail
