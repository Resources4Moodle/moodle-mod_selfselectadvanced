@mod @mod_selfselectadvanced @javascript
Feature: Contradictory guide-side switches are greyed out, not merely refused
  In order to configure a coherent activity without being scolded
  As a teacher
  I need the switches that students-approach mode forbids to be visibly disabled

  # WHY THIS FEATURE EXISTS (2026-08-11). A maintainer editing a live activity
  # managed to submit studentapproach together with expressions of interest -
  # a pair the validator refuses and three disabledIf rules are supposed to
  # make unbuildable. The server-side refusal worked; what nobody had ever
  # EXECUTED was the greying itself. mod_form.php registers the rules and the
  # page serves the dependency payload, but no test drove a browser at it, so
  # "the form disables the pair" rested entirely on source inspection - and
  # the incident left it genuinely unknown whether the JS acts on an
  # advcheckbox source. This feature is that execution. If it passes, the
  # maintainer's submission came through a stale tab, which server validation
  # exists to catch; if it fails, the greying never worked at all.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teach    | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: Students-approach mode greys out the guide-side switches on the add form
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add a "selfselectadvanced" activity to course "Course 1" section "1"
    And I expand all fieldsets
    # The add form defaults to students-approach mode ON, so all three
    # forbidden switches must arrive already greyed.
    Then the "Students approach guides" "field" should be enabled
    And the "Guides can pick listed groups" "field" should be disabled
    And the "Guides volunteer their own capacity" "field" should be disabled
    And the "Guide selection" "field" should be disabled
    # Releasing the switch releases the controls...
    When I set the field "Students approach guides" to "0"
    Then the "Guides can pick listed groups" "field" should be enabled
    And the "Guides volunteer their own capacity" "field" should be enabled
    And the "Guide selection" "field" should be enabled
    # ...and re-engaging it gates them again, which is the direction a stale
    # or half-updated page would need.
    When I set the field "Students approach guides" to "1"
    Then the "Guides can pick listed groups" "field" should be disabled

  Scenario: Manager-assigned guide mode greys out expressions of interest
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add a "selfselectadvanced" activity to course "Course 1" section "1"
    And I expand all fieldsets
    When I set the field "Students approach guides" to "0"
    And I set the field "Guide selection" to "Manager assigns the guide"
    # Decision 75 greyed as well as refused: a control a teacher can tick and
    # then be told off for is a worse explanation than one that visibly does
    # not apply.
    Then the "Guides can pick listed groups" "field" should be disabled
    When I set the field "Guide selection" to "Leader selects the guide"
    Then the "Guides can pick listed groups" "field" should be enabled
