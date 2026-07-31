@mod @mod_selfselectadvanced
Feature: Transactional staged moves
  In order to fix group compositions
  As a manager
  I stage moves that commit atomically as a jointly-valid set

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
      | student3 | Uma       | Three    | student3@example.com |
      | student4 | Vik       | Four     | student4@example.com |
      | student5 | Wei       | Five     | student5@example.com |
      | teacher1 | Tina      | Teach    | teach1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | student5 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "role capabilities" exist:
      | role           | moodle/user:viewalldetails |
      | editingteacher | allow                      |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 2       | 2       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name   | leader   | state |
      | ssa1               | Team A | student1 | firm  |
      | ssa1               | Team B | student3 | firm  |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Team A   | student2 | confirmed |
      | Team B   | student4 | confirmed |

  Scenario: A swap is refused alone and commits as a set
    Given the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | sourcegroup | targetgroup |
      | ssa1               | student2 | Team A      | Team B      |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as teacher1
    Then I should see "Tara" in the ".selfselectadvanced-moves" "css_element"
    And I should see "Tara Two"
    And I set the field "Select Tara Two" to "1"
    When I press "Commit selected moves"
    Then I should see "do not jointly satisfy"
    Given the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | sourcegroup | targetgroup |
      | ssa1               | student4 | Team B      | Team A      |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page
    And I press "Commit selected moves"
    Then I should see "2 move(s) committed."
    And I should see "No pending moves."

  Scenario: Cancelling a pending move leaves groups untouched
    Given the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | sourcegroup | targetgroup |
      | ssa1               | student2 | Team A      | Team B      |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as teacher1
    And I press "Cancel move of Tara Two"
    Then I should see "Move cancelled."
    And I should see "No pending moves."
    When I am on the "Lab groups" "selfselectadvanced activity" page
    And I follow "Team A"
    Then I should see "Tara" in the ".selfselectadvanced-roster" "css_element"
    And I should see "Two" in the ".selfselectadvanced-roster" "css_element"

  @javascript
  Scenario: A refused stage keeps the form's input, and the moves list offers to edit and restage
    Given the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | sourcegroup | targetgroup |
      | ssa1               | student2 | Team A      | Team B      |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as teacher1
    Then I should see "Edit and restage"
    When I click on "Edit and restage" "link"
    Then I should see "Stage a move"
    And I should see "Tara Two"
    When I set the field "To group" to "Team A"
    And I press "Stage a move"
    Then I should see "Source and target must differ."
    And I should see "Stage a move"
    And I should see "Tara Two"
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page
    Then I should see "Edit and restage"

  @javascript
  Scenario: A manager overrides a failing rule with a typed reason
    Given the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | targetgroup |
      | ssa1               | student5 | Team A      |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as teacher1
    Then I should see "L2" in the ".selfselectadvanced-moves" "css_element"
    When I click on "Override this rule…" "link" in the ".ssa-rulechip-L2" "css_element"
    Then I should see "Stage a move"
    When I press "Stage a move"
    Then I should see "Move staged. It takes effect when committed."
    When I set the field "Select Wei Five" to "1"
    And I press "Commit selected moves"
    Then I should see "Confirm a commit that overrides the rules"
    And I should see "L2"
    And I should see "Wei Five"
    When I press "Commit with override"
    Then I should see "Overriding a composition rule needs a typed reason"
    When I set the field "Select Wei Five" to "1"
    And I press "Commit selected moves"
    And I set the field "Reason for the override" to "Agreed with the guide"
    And I press "Commit with override"
    Then I should see "move(s) committed."
    And I should see "No pending moves."

  Scenario: Pending moves paginate
    Given the following "mod_selfselectadvanced > pendingmoves" exist:
      | selfselectadvanced | user     | targetgroup | count | timecreated          |
      | ssa1               | student4 | Team A      | 50    | ##2026-01-01 09:00## |
      | ssa1               | student2 | Team B      | 10    | ##2026-06-01 09:00## |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as teacher1
    Then I should see "Pending moves"
    And I should see "Vik Four"
    And I should not see "Tara Two"
    And "2" "link" should exist in the ".pagination" "css_element"
    When I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "Tara Two"

  @javascript
  Scenario: A manager parks a student and the flagged report offers to re-place them
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I am on the "Lab groups" "mod_selfselectadvanced > stage move" page
    And I set the field "Student" to "Vik Four"
    And I set the field "Remove without a destination team (park)" to "1"
    And I set the field "Minimum group size (L1)" to "1"
    And I press "Stage a move"
    Then I should see "Move staged. It takes effect when committed."
    And I should see "No team (removal)"
    When I set the field "Select Vik Four" to "1"
    And I press "Commit selected moves"
    Then I should see "Confirm a commit that overrides the rules"
    When I set the field "Reason for the override" to "Left the programme"
    And I press "Commit with override"
    Then I should see "move(s) committed."
    When I am on the "Lab groups" "mod_selfselectadvanced > flagged" page
    Then I should see "Vik Four"
