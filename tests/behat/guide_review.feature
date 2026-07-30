@mod @mod_selfselectadvanced
Feature: Guide review of submitted groups
  In order to quality-control lab groups
  As a guide
  I review, return and approve submitted groups

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
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 2       | 4       | 1       | 2             | 5         |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student1 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |

  @javascript
  Scenario: The leader submits to a guide with a free slot
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    # Searchable since 1.18: the control holds no options until a query
    # matches, so a school with 1500 guides is not rendered into a list.
    And I set the field "Choose a guide" to "Gina Guide"
    And I press "Submit to guide"
    Then I should see "Group submitted for guide review."
    And I should see "Awaiting guide"
    And I should see "Guide: Gina Guide"

  Scenario: The guide returns the group with a comment and the leader sees it
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | guide  | state         |
      | ssa1               | Team Rev | student1 | guide1 | pending_guide |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Team Rev | student2 | confirmed |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    And I follow "Guide dashboard"
    Then I should see "You are guiding 1 of 5 groups"
    And I should see "Team Rev" in the ".selfselectadvanced-guidedashboard" "css_element"
    When I click on "Review" "link" in the "Team Rev" "table_row"
    And I set the field "Comment for the leader (required)" to "Please rename the work title."
    And I press "Return to leader"
    Then I should see "returned to its leader"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Rev"
    Then I should see "Returned by the guide with this comment:"
    And I should see "Please rename the work title."

  Scenario: The guide approves and the group becomes firm
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state         |
      | ssa1               | Team Firm | student1 | guide1 | pending_guide |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Firm | student2 | confirmed |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    And I follow "Guide dashboard"
    And I click on "Review" "link" in the "Team Firm" "table_row"
    And I follow "Approve"
    Then I should see "Approval is irreversible"
    When I press "Approve"
    Then I should see "approved"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Firm"
    Then I should see "Firm"
