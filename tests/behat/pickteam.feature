@mod @mod_selfselectadvanced
Feature: Guides pick a listed team
  In order to guide a lab group
  As a guide
  I browse listed, still-forming teams and express interest in one

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
      | guide1   | Gina      | Guide    | guide1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | guide1   | C1     | teacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | eoienabled |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 2             | 5         | 1          |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | title     | leader   |
      | ssa1               | Team Blue | Pendulums | student1 |

  Scenario: A guide sees a listed team in the browse table
    Given I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    And I press "List this team for guides"
    Then I should see "Listed for guides"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    And I follow "Guide dashboard"
    And I follow "Browse listed teams"
    Then I should see "Team Blue" in the ".selfselectadvanced-pickteamtable" "css_element"
    And I should see "Pendulums" in the ".selfselectadvanced-pickteamtable" "css_element"
    And I should see "Sam One" in the ".selfselectadvanced-pickteamtable" "css_element"
    And I should see "Guides interested"
    And I should see "Pick this team"

  Scenario: Filtering the listing by topic narrows the rows
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | title  | leader   |
      | ssa1               | Team Green | Orbits | student2 |
    And I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    And I press "List this team for guides"
    And I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Green"
    And I press "List this team for guides"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    And I follow "Guide dashboard"
    And I follow "Browse listed teams"
    Then I should see "Team Blue" in the ".selfselectadvanced-pickteamtable" "css_element"
    And I should see "Team Green" in the ".selfselectadvanced-pickteamtable" "css_element"
    When I set the field "rq" to "Pendulums"
    And I press "Filter"
    Then I should see "Team Blue" in the ".selfselectadvanced-pickteamtable" "css_element"
    And I should not see "Team Green" in the ".selfselectadvanced-pickteamtable" "css_element"

  Scenario: A sequential activity hides the interested column from browsing guides
    Given the following "activities" exist:
      | activity           | course | name        | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | eoienabled | eoisequential |
      | selfselectadvanced | C1     | Lab groups 2 | ssa2    | 1       | 4       | 1       | 2             | 5         | 1          | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | title   | leader   |
      | ssa2                | Team Gold | Orbits2 | student1 |
    And I am on the "Lab groups 2" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Gold"
    And I press "List this team for guides"
    When I am on the "Lab groups 2" "selfselectadvanced activity" page logged in as guide1
    And I follow "Guide dashboard"
    And I follow "Browse listed teams"
    Then I should see "Team Gold" in the ".selfselectadvanced-pickteamtable" "css_element"
    And I should see "Pick this team"
    And I should not see "Guides interested"

  Scenario: A guide picks a listed team and the leader is notified
    Given I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    And I press "List this team for guides"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    And I follow "Guide dashboard"
    And I follow "Browse listed teams"
    And I follow "Pick this team"
    Then I should see "Express interest in guiding"
    When I set the field "Remarks to the team leader" to "Excited to help with this project."
    And I press "Pick this team"
    Then I should see "Changes saved"
