@mod @mod_selfselectadvanced
Feature: All-groups listing at scale and across every lifecycle state
  In order to catch what a maintainer would otherwise only find by clicking
  through a live course by hand
  As a viewall holder or a Group Coordinator
  I want the landing page's native listing to keep paging, sorting, filtering
  and linking correctly once it holds more rows and more states than a
  15-row demo fixture ever exercises

  # allgroups_native.feature (1.20.47) already proves the table exists and
  # is reachable at 15 rows/perpage 10. This file does not repeat that: it
  # pushes past one page at a larger size (28 rows, perpage 20), asserts on
  # the actual FIRST ROW rather than page-level text after a sort, exercises
  # every state the lifecycle can reach (forming, pending_guide, firm,
  # frozen) through the filter rather than just one, and proves the group
  # name is a real link to a real destination rather than merely present.
  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | student1 | Sam       | One      | student1@example.com |
      | teacher1 | Tina      | Teach    | teach1@example.com   |
      | coord1   | Cora      | Ord      | coord1@example.com   |
      | guide1   | Gina      | Guide    | guide1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
      | coord1   | C1     | teacher        |
      | guide1   | C1     | teacher        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber |
      | selfselectadvanced | C1     | Lab groups | ssa1     |
    # ACTIVITY context (1.20.1): the Group Coordinator role does work inside
    # one activity and is assignable nowhere else, so the table has to sit
    # BELOW the activities table that creates its reference.
    And the following "role assigns" exist:
      | user   | role             | contextlevel    | reference |
      | coord1 | groupcoordinator | Activity module | ssa1      |
    # perpage 20 with 28 fixture groups below pages onto a visible second
    # page at a size closer to a live course than allgroups_native.feature's
    # own 15-row/perpage-10 fixture.
    And the following "user preferences" exist:
      | user     | preference                     | value |
      | teacher1 | mod_selfselectadvanced_perpage | 20    |
      | coord1   | mod_selfselectadvanced_perpage | 20    |
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
      | ssa1                | Group 16 | student1 |
      | ssa1                | Group 17 | student1 |
      | ssa1                | Group 18 | student1 |
      | ssa1                | Group 19 | student1 |
      | ssa1                | Group 20 | student1 |
      | ssa1                | Group 21 | student1 |
      | ssa1                | Group 22 | student1 |
      | ssa1                | Group 23 | student1 |
      | ssa1                | Group 24 | student1 |
      | ssa1                | Group 25 | student1 |
    # One group per lifecycle state besides forming, each named so it sorts
    # alphabetically AFTER every "Group NN" row above (a digit sorts before
    # any letter) and so each state's filtered result is exactly one row -
    # no fixture here can land on a page a filter scenario never visits.
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name               | leader   | guide  | state         |
      | ssa1                | Group Guide Review | student1 | guide1 | pending_guide |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name               | leader   | guide  | state  | timeapproved  |
      | ssa1                | Group Firm Ready   | student1 | guide1 | firm   | ##yesterday## |
      | ssa1                | Group Locked Down  | student1 | guide1 | frozen | ##yesterday## |

  Scenario: Paging at 28 groups surfaces a page-2 group the page-1 view never renders
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    Then I should see "All groups"
    And I should see "Group 01"
    And I should not see "Group 21"
    And I should not see "Group Locked Down"
    And "2" "link" should exist in the ".pagination" "css_element"
    When I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "Group 21"
    And I should see "Group Locked Down"
    And I should not see "Group 01"

  # #ssaallgroups_r0 is the id core's flexible_table gives the FIRST rendered
  # row of the CURRENT page (uniqueid . '_r' . currentrow, currentrow always
  # starting at 0 for a fresh request). Asserting inside that one element,
  # rather than "should see"/"should not see" against the whole page, is
  # what makes this a claim about row 1 specifically and not just about
  # what text happens to be on the page somewhere.
  Scenario: Clicking the Group name header changes the actual first row, not just the page
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    Then I should see "Group 01" in the "#ssaallgroups_r0" "css_element"
    When I click on "Group name" "link"
    Then I should see "Group Locked Down" in the "#ssaallgroups_r0" "css_element"
    And I should not see "Group 01" in the "#ssaallgroups_r0" "css_element"

  Scenario: The state filter narrows the all-groups listing to Forming and hides the other states
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I set the field "State" to "Forming"
    And I press "Filter"
    Then I should see "Group 01"
    And I should not see "Group Guide Review"
    And I should not see "Group Firm Ready"
    And I should not see "Group Locked Down"

  Scenario: The state filter narrows the all-groups listing to Awaiting guide and hides the other states
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I set the field "State" to "Awaiting guide"
    And I press "Filter"
    Then I should see "Group Guide Review"
    And I should not see "Group 01"
    And I should not see "Group Firm Ready"
    And I should not see "Group Locked Down"

  Scenario: The state filter narrows the all-groups listing to Approved and hides the other states
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I set the field "State" to "Approved"
    And I press "Filter"
    Then I should see "Group Firm Ready"
    And I should not see "Group 01"
    And I should not see "Group Guide Review"
    And I should not see "Group Locked Down"

  Scenario: The state filter narrows the all-groups listing to Locked and hides the other states
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I set the field "State" to "Locked"
    And I press "Filter"
    Then I should see "Group Locked Down"
    And I should not see "Group 01"
    And I should not see "Group Guide Review"
    And I should not see "Group Firm Ready"

  Scenario: A group's name in the all-groups listing is a real link to that group's own page
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I click on "Group 01" "link"
    Then I should see "Group 01" in the ".selfselectadvanced-grouppage" "css_element"

  Scenario: A Group Coordinator, not only an editing teacher, reaches the paged listing at the same scale
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as coord1
    Then I should see "All groups"
    And I should see "Group 01"
    And I should not see "Group 21"
    And "2" "link" should exist in the ".pagination" "css_element"
    When I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "Group 21"
    And I should not see "Group 01"
