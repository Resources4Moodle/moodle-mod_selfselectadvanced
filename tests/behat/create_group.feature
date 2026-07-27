@mod @mod_selfselectadvanced
Feature: Students create groups under the lead cap
  In order to work in a lab batch
  As a student
  I need to create a group and see my position against the limits

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             |

  Scenario: A student creates a group and sees the seat counter
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "You lead 0 of 1 groups"
    And I should see "No groups yet"
    When I follow "Create group"
    And I set the following fields to these values:
      | Group name    | Team Alpha        |
      | Title of work | Pendulum period   |
      | Brief of work | We study gravity. |
    And I press "Create group"
    Then I should see "Team Alpha"
    And I should see "1 of 4 seats filled"
    And I should see "Sam" in the ".selfselectadvanced-roster" "css_element"
    And I should see "One" in the ".selfselectadvanced-roster" "css_element"
    And I should see "Leader"
    When I am on the "Lab groups" "selfselectadvanced activity" page
    Then I should see "You lead 1 of 1 groups"

  Scenario: A duplicate group name is refused
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student2 |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Create group"
    And I set the following fields to these values:
      | Group name    | Team Blue     |
      | Title of work | Anything      |
      | Brief of work | Some brief.   |
    And I press "Create group"
    Then I should see "That group name is already taken in this activity."

  Scenario: The create control is disabled with the reason at the lead cap
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Full | student1 |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "You lead 1 of 1 groups"
    And I should see "You already lead 1 of 1 groups."

  Scenario: The leader deletes a forming group
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Gone | student1 |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Gone"
    And I follow "Delete group"
    And I press "Delete group"
    Then I should see "deleted"
    And I should see "No groups yet"
