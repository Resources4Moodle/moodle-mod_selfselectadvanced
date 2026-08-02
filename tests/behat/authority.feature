@mod @mod_selfselectadvanced
Feature: A prohibited capability is honoured by the pages, not only by the services
  In order to be able to take an authority away and have it stay taken away
  As an administrator
  The controls disappear when I prohibit the capability behind them

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | student2 | Tara      | Two      | s2@example.com     |
      | guide1   | Gina      | Guide    | g1@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 2       | 2             |

  Scenario: The create control is live until the capability is prohibited
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "Create group"
    Given the following "permission overrides" exist:
      | capability                        | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:creategroup | Prohibit  | student | Activity module | ssa1      |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should not see "Create group"

  Scenario: The bulk freeze control goes with the freeze capability
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Fir | student1 | guide1 | firm  | ##yesterday## |
      | ssa1               | Team Oak | student2 | guide1 | firm  | ##yesterday## |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    Then I should see "Team Fir"
    And I should see "Freeze selected groups"
    Given the following "permission overrides" exist:
      | capability                   | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:freeze | Prohibit  | teacher | Activity module | ssa1      |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    Then I should see "Team Fir"
    And I should not see "Freeze selected groups"

  Scenario: The assigned guide's group mark travels from the page to the ledger
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Mark | student1 | guide1 | firm  | ##yesterday## |
    When I am on the "Lab groups > Team Mark" "mod_selfselectadvanced > review" page logged in as guide1
    Then I should see "Group mark"
    When I set the field "award" to "73.5"
    And I press "Save mark"
    Then I should see "Group mark saved and grades republished."
    When I am on the "Lab groups > Team Mark" "mod_selfselectadvanced > review" page
    Then the field "award" matches value "73.50"
