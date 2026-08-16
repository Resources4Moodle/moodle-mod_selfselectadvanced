@mod @mod_selfselectadvanced
Feature: Native pagination, sorting and filtering for the landing page's All groups listing
  In order to work with an activity that will grow to thousands of groups
  As a viewall holder or a Group Coordinator
  I want the landing page's group listing to use Moodle's own paged, sortable
  table instead of a hand-rolled panel hard-capped at 20 rows with no route on

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | teacher1 | Tina      | Teach    | teach1@example.com   |
      | coord1   | Cora      | Ord      | coord1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
      | coord1   | C1     | teacher        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber |
      | selfselectadvanced | C1     | Lab groups | ssa1     |
    # ACTIVITY context (1.20.1): the Group Coordinator role does work inside
    # one activity and is assignable nowhere else, so the table has to sit
    # BELOW the activities table that creates its reference.
    And the following "role assigns" exist:
      | user   | role             | contextlevel    | reference |
      | coord1 | groupcoordinator | Activity module | ssa1      |
    # A small per-page keeps this fixture at 15 rows instead of 51+: the
    # default is 50, so a bare 15-group activity would never paginate.
    And the following "user preferences" exist:
      | user     | preference                     | value |
      | teacher1 | mod_selfselectadvanced_perpage | 10    |
      | coord1   | mod_selfselectadvanced_perpage | 10    |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   |
      | ssa1                | Group 01 | student1 |
      | ssa1                | Group 02 | student1 |
      | ssa1                | Group 03 | student1 |
      | ssa1                | Group 04 | student1 |
      | ssa1                | Group 05 | student1 |
      | ssa1                | Group 06 | student1 |
      | ssa1                | Group 07 | student1 |
      | ssa1                | Group 08 | student1 |
      | ssa1                | Group 09 | student1 |
      | ssa1                | Group 10 | student1 |
      | ssa1                | Group 11 | student1 |
      | ssa1                | Group 12 | student1 |
      | ssa1                | Group 13 | student1 |
      | ssa1                | Group 14 | student1 |
      | ssa1                | Group 15 | student1 |

  Scenario: A teacher pages through the native all-groups listing
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    Then I should see "All groups"
    And I should see "Group 01"
    And I should not see "Group 15"
    And "2" "link" should exist in the ".pagination" "css_element"
    When I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "Group 15"
    And I should not see "Group 01"

  Scenario: A teacher re-sorts the native all-groups listing by clicking a column header
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    Then I should see "Group 01"
    And I should not see "Group 15"
    When I click on "Group name" "link"
    Then I should see "Group 15"
    And I should not see "Group 01"

  Scenario: A teacher filters the native all-groups listing by state
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I set the field "State" to "Forming"
    And I press "Filter"
    Then I should see "Group 01"

  Scenario: A Group Coordinator reaches the same paginated listing through the viewall gate
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as coord1
    Then I should see "All groups"
    And I should see "Group 01"
    And I should not see "Group 15"
    And "2" "link" should exist in the ".pagination" "css_element"
    When I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "Group 15"
