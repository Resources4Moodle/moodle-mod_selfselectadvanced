@mod @mod_selfselectadvanced
Feature: Coordinators do roster and guide work without manage
  In order to carry out the composition change I resolved a ticket about
  As a Group Coordinator who is not an editing teacher
  I reach the staged-move pages and the guide-assignment dashboard, and
  the conflict-of-interest rule still keeps me off my own teams

  Background:
    Given the following "users" exist:
      | username     | firstname | lastname | email                |
      | student1     | Sam       | One      | student1@example.com |
      | student2     | Tara      | Two      | student2@example.com |
      | student3     | Uma       | Three    | student3@example.com |
      | student4     | Vik       | Four     | student4@example.com |
      | coordteacher | Cora      | Ord      | cord@example.com     |
      | teacher1     | Tina      | Teach    | teach1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user         | course | role           |
      | student1     | C1     | student        |
      | student2     | C1     | student        |
      | student3     | C1     | student        |
      | student4     | C1     | student        |
      | coordteacher | C1     | teacher        |
      | teacher1     | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 3       | 1       | 1             |
    # ACTIVITY context (1.20.1): the Group Coordinator role does work
    # inside one activity and is assignable nowhere else, so the table
    # has to sit BELOW the activities table that creates its reference.
    And the following "role assigns" exist:
      | user         | role             | contextlevel    | reference |
      | coordteacher | groupcoordinator | Activity module | ssa1      |

  Scenario: A coordinator reaches the pending-moves and staging pages without manage
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as coordteacher
    Then I should see "Pending moves"
    When I am on the "Lab groups" "mod_selfselectadvanced > stage move" page
    Then I should see "Stage a move"

  Scenario: A coordinator reaches the manager dashboard for guide assignment
    When I am on the "Lab groups" "mod_selfselectadvanced > manage" page logged in as coordteacher
    Then I should see "Manager dashboard"

  Scenario: A coordinator commits a staged move on a team they have nothing to do with
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name   | leader   | state |
      | ssa1               | Team A | student1 | firm  |
      | ssa1               | Team B | student3 | firm  |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Team A   | student2 | confirmed |
      | Team B   | student4 | confirmed |
    And the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | sourcegroup | targetgroup |
      | ssa1               | student2 | Team A      | Team B      |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as coordteacher
    Then I should see "Tara Two"
    When I press "Commit selected moves"
    Then I should see "1 move(s) committed."
    And I should see "No pending moves."

  Scenario: A coordinator is refused a move on a team they guide
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name   | leader   | guide        | state |
      | ssa1               | Team A | student1 | coordteacher | firm  |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name   | leader   | state |
      | ssa1               | Team B | student3 | firm  |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Team A   | student2 | confirmed |
      | Team B   | student4 | confirmed |
    And the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | sourcegroup | targetgroup |
      | ssa1               | student2 | Team A      | Team B      |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as coordteacher
    And I press "Commit selected moves"
    Then I should see "You cannot act on this group because you are the assigned guide of it"
    And I should see "Tara Two"

  Scenario: An editing teacher is not restrained by the conflict-of-interest rule
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name   | leader   | guide    | state |
      | ssa1               | Team A | student1 | teacher1 | firm  |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name   | leader   | state |
      | ssa1               | Team B | student3 | firm  |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Team A   | student2 | confirmed |
      | Team B   | student4 | confirmed |
    And the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | sourcegroup | targetgroup |
      | ssa1               | student2 | Team A      | Team B      |
    When I am on the "Lab groups" "mod_selfselectadvanced > moves" page logged in as teacher1
    And I press "Commit selected moves"
    Then I should see "1 move(s) committed."
