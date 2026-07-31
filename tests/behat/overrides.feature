@mod @mod_selfselectadvanced
Feature: Overrides resolved through the single service
  In order to grant exceptions
  As a manager
  I create user, group and guide overrides that every rule check respects

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | teacher1 | Tina      | Teach    | teach1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student1 |

  @javascript
  Scenario: A user max-lead override lifts the student's cap everywhere
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "You lead 1 of 1 groups"
    And I should see "You already lead 1 of 1 groups."
    When I am on the "Lab groups" "mod_selfselectadvanced > overrides" page logged in as teacher1
    Then I should see "No overrides of this kind yet."
    When I follow "Add override"
    And I set the field "User" to "Sam One"
    And I set the field "Maximum groups a student may lead" to "2"
    And I set the field "Maximum group memberships per student" to "2"
    And I press "Save changes"
    Then I should see "Override saved."
    And I should see "Sam One"
    And I should see "Max lead: 2"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "You lead 1 of 2 groups"
    And I should see "Create group"

  Scenario: Deleting the override restores the activity setting
    Given the following "mod_selfselectadvanced > overrides" exist:
      | selfselectadvanced | scope | target   | maxlead | maxmembership |
      | ssa1               | user  | student1 | 3       | 3             |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "You lead 1 of 3 groups"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Overrides"
    And I press "Delete"
    Then I should see "Override deleted."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "You lead 1 of 1 groups"

  @javascript
  Scenario: Move overrides are reviewable and revocable
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student2 | Ravi      | Two      | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Tight labs | ssa2     | 1       | 1       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   |
      | ssa2               | Tight Team | student1 |
    And the following "mod_selfselectadvanced > moves" exist:
      | selfselectadvanced | user     | targetgroup |
      | ssa2               | student2 | Tight Team  |
    When I am on the "Tight labs" "mod_selfselectadvanced > moves" page logged in as teacher1
    Then I should see "L2" in the ".selfselectadvanced-moves" "css_element"
    When I click on "Override this rule…" "link" in the ".ssa-rulechip-L2" "css_element"
    And I press "Stage a move"
    Then I should see "Move staged. It takes effect when committed."
    When I am on the "Tight labs" "mod_selfselectadvanced > overrides" page
    And I follow "Moves"
    Then I should see "Ravi Two"
    And I should see "L2"
    And I should see "Pending"
    When I press "Delete"
    Then I should see "Override deleted."
    And I should see "No overrides of this kind yet."
    When I am on the "Tight labs" "mod_selfselectadvanced > moves" page
    Then I should see "L2" in the ".ssa-rulechip-L2" "css_element"
    And "Override this rule…" "link" should exist in the ".ssa-rulechip-L2" "css_element"
