@mod @mod_selfselectadvanced
Feature: Auto-grouping of groupless students
  In order to leave no one behind at the deadline
  As a manager
  I trigger auto-grouping and read the run summary

  Scenario: The manual trigger forms groups and reports the run
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | student2 | Tara      | Two      | s2@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | autogroup | timecutoff    |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 2       | 3       | 1       | 1             | 1         | ##yesterday## |
    When I am on the "Lab groups" "mod_selfselectadvanced > manage" page logged in as teacher1
    Then I should see "Auto-grouping has not run yet."
    When I press "Run auto-grouping now"
    Then I should see "Auto-grouping complete: 1 group(s) formed, 2 placed, 0 left for placement."
    And I should see "Auto group 1"
    And I should see "Awaiting a guide"
