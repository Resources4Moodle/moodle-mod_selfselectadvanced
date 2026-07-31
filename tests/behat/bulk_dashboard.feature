@mod @mod_selfselectadvanced
Feature: Bulk operations, the manager dashboard and the flagged report
  In order to run large cohorts
  As a guide and a manager
  I bulk-freeze with filters and read the dashboards

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | student2 | Tara      | Two      | s2@example.com     |
      | student3 | Uma       | Three    | s3@example.com     |
      | guide1   | Gina      | Guide    | g1@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | gender | department | subdepartment |
      | student1 | Male   | Civil      | Structures    |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Fir | student1 | guide1 | firm  | ##yesterday## |
      | ssa1               | Team Oak | student2 | guide1 | firm  | ##yesterday## |

  Scenario: The guide bulk-freezes all matching firm groups
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    Then I should see "Team Fir"
    And I should see "Team Oak"
    When I set the field "Select Team Fir" to "1"
    And I set the field "Select Team Oak" to "1"
    And I press "Freeze selected groups"
    Then I should see "2 group(s) frozen."
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page
    And I follow "Groups I guide"
    Then I should not see "Freeze selected groups"

  Scenario: Nothing is frozen unless the guide ticks a team
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I press "Freeze selected groups"
    Then I should see "0 group(s) frozen."
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page
    And I follow "Groups I guide"
    Then I should see "Freeze selected groups"
    And I should see "Firm" in the "Team Fir" "table_row"

  Scenario: The manager dashboard filters by state and offers unfreeze
    When I am on the "Lab groups" "mod_selfselectadvanced > manage" page logged in as teacher1
    Then I should see "Team Fir" in the "#region-main" "css_element"
    And I should see "1 of 1 to 6"
    When I set the field "statefilter" to "Forming"
    And I press "Filter"
    Then I should see "Nothing to display"

  Scenario: The flagged report lists groupless students and missing attributes
    When I am on the "Lab groups" "mod_selfselectadvanced > flagged" page logged in as teacher1
    Then I should see "Students in no group (1)"
    And I should see "Uma Three"
    And I should see "Attributes missing"
    And I should see "Tara Two" in the ".selfselectadvanced-flagged" "css_element"

  Scenario: Group anomalies have a tab of their own
    When I am on the "Lab groups" "mod_selfselectadvanced > flagged" page logged in as teacher1
    Then I should see "Group anomalies"
    And I should not see "No team is in an anomalous position."
    When I follow "Group anomalies (0)"
    Then I should see "No team is in an anomalous position."
