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
      | teacher1 | C1     | editingteacher |
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
    Then I should see "Tara Two"
