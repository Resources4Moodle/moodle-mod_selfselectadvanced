@mod @mod_selfselectadvanced
Feature: A group whose leader has gone says so, and staff can repair it
  In order to keep working after a leader's account disappears
  As course staff
  I need the group to admit it has no leader and let me appoint one

  # MUTATIONS CAUGHT (run 2026-08-11), each proved to land before it was run:
  # - removing {{{appointleaderformhtml}}} from group_page.mustache fails the
  #   staff scenario (as a JS-readiness error: the form's autocomplete JS is
  #   still emitted while the element it initialises has gone);
  # - ungating appointexcluded/appointleadernocandidates on the appointing
  #   capability fails the peer steps, which is the leak this feature exists
  #   to prevent.
  #
  # The notice assertions are SCOPED to .selfselectadvanced-leadervacant on
  # purpose. The three refusal messages this vacancy produces open with the
  # same sentence, so an unscoped "I should see" passed before the notice was
  # rendered at all - it was matching a refusal.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
      | student3 | Nina      | Three    | student3@example.com |
      | teacher1 | Tina      | Teach    | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 2       | 3             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name        | leader   | state   |
      | ssa1               | Orphan team | student1 | forming |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup    | user     | status    |
      | Orphan team | student2 | confirmed |
      | Orphan team | student3 | confirmed |
    And the leader of the "Orphan team" group has been removed

  # Everybody who may open the group is told. Before 1.20.35 the page went on
  # naming the deleted account as leader, so a member could see a leader who
  # did not exist and no explanation for why nothing worked.
  Scenario: An ordinary member is told, and is not offered the repair
    When I am on the "Lab groups > Orphan team" "mod_selfselectadvanced > group" page logged in as student2
    # SCOPED TO THE NOTICE, deliberately. The refusal messages on the same page
    # open with the same sentence, so an unscoped "I should see" would pass
    # whether or not the notice was ever rendered.
    Then I should see "This group currently has no leader" in the ".selfselectadvanced-leadervacant" "css_element"
    And "Assign leader" "button" should not exist
    And I should not see "No member of this group can be assigned as leader"

  @javascript
  Scenario: Staff see the control, and the appointment sticks
    When I am on the "Lab groups > Orphan team" "mod_selfselectadvanced > group" page logged in as teacher1
    Then I should see "This group currently has no leader" in the ".selfselectadvanced-leadervacant" "css_element"
    And "Assign leader" "button" should exist
    When I set the field "New leader" to "Tara Two"
    And I press "Assign leader"
    Then I should see "The group has a leader again."
    And ".selfselectadvanced-leadervacant" "css_element" should not exist
    And "Assign leader" "button" should not exist
    # The roster puts first and last name in separate cells, so the row is the
    # unit that can be asserted: Tara's row now carries the leader badge.
    And I should see "Leader" in the "Tara" "table_row"
    And I should not see "Leader" in the "Nina" "table_row"

  # The empty state. Staff hold the power but there is nobody lawful to
  # appoint, so the page says so rather than drawing a control with nothing
  # in it.
  Scenario: With nobody eligible, staff are told rather than shown a blank picker
    Given the following "permission overrides" exist:
      | capability                  | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:lead | Prohibit   | student | Activity module | ssa1      |
    When I am on the "Lab groups > Orphan team" "mod_selfselectadvanced > group" page logged in as teacher1
    Then I should see "No member of this group can be assigned as leader" in the ".selfselectadvanced-leadervacant" "css_element"
    And "Assign leader" "button" should not exist
    And I should see "Tara Two" in the ".selfselectadvanced-leadervacant" "css_element"
    # The same page, the same vacancy, a peer instead of staff. Who is barred
    # from leading and the fact that nobody can are judgements about other
    # people; the peer is told the vacancy exists and nothing further.
    When I am on the "Lab groups > Orphan team" "mod_selfselectadvanced > group" page logged in as student2
    Then I should see "This group currently has no leader" in the ".selfselectadvanced-leadervacant" "css_element"
    And I should not see "No member of this group can be assigned as leader"
    And I should not see "Tara Two" in the ".selfselectadvanced-leadervacant" "css_element"
