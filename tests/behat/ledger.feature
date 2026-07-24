@mod @mod_selfselectadvanced
Feature: The penalty ledger
  In order to grade fairly
  As a manager or guide
  I read each group's authoritative penalty

  Scenario: The ledger lists approved groups with their penalties
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state | timeapproved   |
      | ssa1               | Team Blue | student1 | firm  | ##yesterday##  |
    When I am on the "Lab groups" "mod_selfselectadvanced > ledger" page logged in as teacher1
    Then I should see "Penalty ledger"
