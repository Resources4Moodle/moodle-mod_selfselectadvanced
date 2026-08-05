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
    Then I should see "returns to firm"
    # Since the mirror is retained across a release, the confirm page
    # must not still promise that it is deleted - the page a manager
    # reads before a destructive-sounding action is not a place for a
    # claim the code stopped honouring.
    And I should see "mirrored course group is KEPT"
    And I should not see "The mirrored course group is deleted"
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

  Scenario: Unfreezing keeps the course group
    Given I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Freeze" "link" in the "Team Blue" "table_row"
    And I press "Freeze"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Blue"
    And I follow "Unfreeze"
    And I press "Unfreeze"
    Then I should see "unfrozen and restored"
    When I am on the "Course 1" "groups" page logged in as teacher1
    Then the "groups" select box should contain "[ssa1] Team Blue (2)"

  Scenario: Manager resynchronises the course group
    Given I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Freeze" "link" in the "Team Blue" "table_row"
    And I press "Freeze"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Blue"
    And I press "Resynchronise course group"
    Then I should see "already in step"

  Scenario: Manager discards the course group after unfreezing
    Given I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Freeze" "link" in the "Team Blue" "table_row"
    And I press "Freeze"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Blue"
    Then "Discard course group" "link" should not exist
    When I follow "Unfreeze"
    And I press "Unfreeze"
    And I follow "Discard course group"
    Then I should see "Delete the course group mirroring"
    When I press "Discard course group"
    Then I should see "The mirrored course group has been deleted."

  Scenario: A coordinator filters and pages the mirror report
    Given the following "users" exist:
      | username     | firstname | lastname | email            |
      | coordteacher | Cora      | Ord      | cord@example.com |
    And the following "course enrolments" exist:
      | user         | course | role    |
      | coordteacher | C1     | teacher |
    And the following "role assigns" exist:
      | user         | role             | contextlevel    | reference |
      | coordteacher | groupcoordinator | Activity module | ssa1      |
    And the following "permission overrides" exist:
      | capability                     | permission | role             | contextlevel    | reference |
      | mod/selfselectadvanced:viewall | Prevent    | groupcoordinator | Activity module | ssa1      |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name          | leader   | state   | timeapproved  |
      | ssa1               | Team 01       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 02       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 03       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 04       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 05       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 06       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 07       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 08       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 09       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 10       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 11       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 12       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 13       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 14       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 15       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 16       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 17       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 18       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 19       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 20       | student1 | firm    | ##yesterday## |
      | ssa1               | Team 21       | student1 | firm    | ##yesterday## |
      | ssa1               | Unique Needle | student1 | forming |               |
    When I am on the "Lab groups" "mod_selfselectadvanced > core sync" page logged in as coordteacher
    Then I should see "Moodle group mirrors"
    And "2" "link" should exist in the ".pagination" "css_element"
    When I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "Team 21"
    When I set the field "Team name or project ID contains" to "Unique"
    And I press "Filter"
    Then I should see "Unique Needle"
    And I should not see "Team 01"
    When I am on the "Course 1" "groups" page logged in as teacher1
    Then the "groups" select box should not contain "[ssa1] Team Blue (2)"
