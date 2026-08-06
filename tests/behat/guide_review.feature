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
      | teacher1 | Tess      | Teacher  | teacher1@example.com |
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
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | guideautoapprove | guidewindow |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 2       | 4       | 1       | 2             | 5         | 1                | 86400       |
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
    # The leader action clusters are tabs since 1.20.11; submit lives in
    # its own pane, so under JavaScript it must be brought forward.
    And I click on "Submit to guide" "link" in the ".selfselectadvanced-leadertabs" "css_element"
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
    And the Moodle group mirror for "Team Firm" in "Lab groups" should contain "student1, student2, guide1"

  Scenario: A lapsed decision window firms the team and records the exception
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state         | timesubmitted |
      | ssa1               | Team Late | student1 | guide1 | pending_guide | -3 days       |
    And I run the scheduled task "\mod_selfselectadvanced\task\guide_autoapprove"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Late"
    Then I should see "Firm"

  Scenario: A guide already over their team limit keeps the team in the queue
    Given the following "mod_selfselectadvanced > overrides" exist:
      | selfselectadvanced | scope | target | maxguided |
      | ssa1               | guide | guide1 | 1         |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   | guide  | state         | timesubmitted |
      | ssa1               | Team Late  | student1 | guide1 | pending_guide | -3 days       |
      | ssa1               | Team Later | student2 | guide1 | pending_guide | -3 days       |
    And I run the scheduled task "\mod_selfselectadvanced\task\guide_autoapprove"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Late"
    Then I should see "Awaiting guide"

  # 1.20.6 item A: a guide landing on the activity used to get a
  # student-shaped page - a student-addressed approach notice, a
  # "Joining another team" button that ended at a permission exception,
  # and one small dashboard link near the bottom. Their own decisions
  # appeared nowhere. The panel now leads the page, and the dashboard
  # link inside it is the SAME link the six existing "Guide dashboard"
  # steps follow, which the last two steps here re-prove.
  Scenario: A guide lands on their own work, not on the student page
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state         | timesubmitted |
      | ssa1               | Team Wait | student1 | guide1 | pending_guide | -3 days       |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    Then I should see "Your guide work"
    And I should see "You are guiding 1 of 5 groups"
    And I should see "Team Wait" in the ".selfselectadvanced-guidepanel" "css_element"
    And I should see "(overdue)" in the ".selfselectadvanced-guidepanel" "css_element"
    And I should not see "Joining another team"
    When I follow "Guide dashboard"
    Then I should see "You are guiding 1 of 5 groups"
