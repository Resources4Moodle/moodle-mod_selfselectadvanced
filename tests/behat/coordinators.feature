@mod @mod_selfselectadvanced
Feature: Appointing Group Coordinators from a participants table
  In order to appoint one or two people without a spreadsheet
  As an editing teacher I use a table of the course's participants and
  appoint or remove them a person at a time

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | guide1   | Gina      | Guide    | g1@example.com     |
      | guide2   | Hari      | Helper   | g2@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | guide1   | C1     | teacher        |
      | guide2   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber |
      | selfselectadvanced | C1     | Lab groups | ssa1     |

  Scenario: An editing teacher appoints and then removes one coordinator
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinators" page logged in as teacher1
    Then I should see "Gina Guide"
    And I should see "Hari Helper"
    But I should not see "Sam One"

    When I click on "Appoint" "button" in the "Gina Guide" "table_row"
    Then I should see "Appointed as a Group Coordinator."
    And I should see "Holding the role now (1)"

    When I click on "Remove" "button" in the "Gina Guide" "table_row"
    Then I should see "The Group Coordinator role has been taken away."
    And I should see "Holding the role now (0)"

  Scenario: The table filters to those already holding the role
    Given the following "role assigns" exist:
      | user   | role             | contextlevel | reference |
      | guide2 | groupcoordinator | Course       | C1        |
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinators" page logged in as teacher1
    And I set the field "Roles in this course" to "Group Coordinators only"
    And I press "Filter"
    Then I should see "Hari Helper"
    And I should not see "Gina Guide"

  Scenario: A student is out of scope by default but reachable when needed
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinators" page logged in as teacher1
    And I set the field "Roles in this course" to "Every participant"
    And I press "Filter"
    Then I should see "Sam One"

  Scenario: The upload tab offers a sample file
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinators" page logged in as teacher1
    And I follow "Upload a list"
    Then I should see "Sample file (CSV)"
    And I should see "Sample file (Excel)"
