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
    # LEGACY ROW, deliberately at COURSE context. Since 1.20.1 the role is
    # assignable at ACTIVITY context only, but assignability is not a
    # grant: a course-level row an administrator recorded before the
    # change still grants, and must still be LISTED and REMOVABLE on this
    # screen. This is the regression pin for that promise - the screen
    # reads {role_assignments} with get_role_users(..., parent = true) and
    # never consults get_role_contextlevels(). Do not "tidy" it to
    # Activity module.
    Given the following "role assigns" exist:
      | user   | role             | contextlevel | reference |
      | guide2 | groupcoordinator | Course       | C1        |
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinators" page logged in as teacher1
    And I set the field "Roles in this course" to "Group Coordinators only"
    And I press "Filter"
    Then I should see "Hari Helper"
    And I should not see "Gina Guide"

  Scenario: A student is visible under every participant but cannot be appointed
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinators" page logged in as teacher1
    And I set the field "Roles in this course" to "Every participant"
    And I press "Filter"
    Then I should see "Sam One"
    And I should see "Not eligible" in the "Sam One" "table_row"
    And "Appoint" "button" should not exist in the "Sam One" "table_row"

  Scenario: A renamed non-editing teacher role still fills the pool
    Given the following "roles" exist:
      | shortname | name  | archetype |
      | tutor     | Tutor | teacher   |
    And the following "users" exist:
      | username | firstname | lastname | email          |
      | tutor1   | Tara      | Tutor    | t1@example.com |
    And the following "course enrolments" exist:
      | user   | course | role  |
      | tutor1 | C1     | tutor |
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinators" page logged in as teacher1
    Then I should see "Tara Tutor"
    When I click on "Appoint" "button" in the "Tara Tutor" "table_row"
    Then I should see "Appointed as a Group Coordinator."

  Scenario: The upload tab offers a sample file
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinators" page logged in as teacher1
    And I follow "Upload a list"
    Then I should see "Sample file (CSV)"
    And I should see "Sample file (Excel)"
