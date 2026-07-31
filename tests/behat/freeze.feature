@mod @mod_selfselectadvanced
Feature: Freezing firm groups into course groups
  In order to hand groups to downstream activities
  As a guide I freeze, and as a manager I unfreeze

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | guide1   | Gina      | Guide    | g1@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Blue | student1 | guide1 | firm  | ##yesterday## |

  Scenario: The guide freezes and the manager unfreezes with snapshot semantics
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Freeze" "link" in the "Team Blue" "table_row"
    Then I should see "A Moodle course group is created"
    When I press "Freeze"
    Then I should see "frozen into a course group"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Blue"
    Then I should see "Frozen"
    When I follow "Unfreeze"
    Then I should see "returns to firm exactly as frozen"
    When I press "Unfreeze"
    Then I should see "unfrozen and restored"
    And I should see "Firm"

  Scenario: A guide may release a team they froze, but not one staff froze
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   | guide  | state  | timeapproved  | frozenbystaff |
      | ssa1               | Team Amber | student1 | guide1 | frozen | ##yesterday## | 0             |
      | ssa1               | Team Slate | student1 | guide1 | frozen | ##yesterday## | 1             |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    Then "Release" "link" should exist in the "Team Amber" "table_row"
    And "Release" "link" should not exist in the "Team Slate" "table_row"
    And I should see "Frozen by staff - ask through the request queue" in the "Team Slate" "table_row"
