@mod @mod_selfselectadvanced
Feature: Composition quotas with a live deficiency panel
  In order to balance lab groups
  As a manager
  I configure prioritised quota rules that leaders see as buckets

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
      | student3 | Raj       | Three    | student3@example.com |
      | student4 | Nia       | Four     | student4@example.com |
      | teacher1 | Tina      | Teach    | teach1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | gender | department | subdepartment |
      | student1 | Male   | Civil      | Structures    |
      | student2 | Female | Civil      | Hydraulics    |
      | student3 | Male   | Maths      | Algebra       |
      | student4 | Male   | Maths      | Geometry      |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student1 |

  Scenario: The manager creates a rule from the ingested values
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Manager dashboard"
    Then I should see "Awaiting a guide"
    When I am on the "Lab groups" "mod_selfselectadvanced > quotas" page
    Then I should see "No quota rules yet."
    When I follow "Add rule"
    And I set the field "Attribute value" to "Female"
    And I set the field "Minimum members" to "1"
    And I press "Save changes"
    Then I should see "Rule saved."
    And I should see "At least 1 members with Gender = Female"

  Scenario: The submit control is visible but disabled while the composition is unmet
    Given the following "mod_selfselectadvanced > quotas" exist:
      | selfselectadvanced | dimension | value  | mincount |
      | ssa1               | gender    | Female | 1        |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should see "Submit to guide"
    And the "Submit to guide" "button" should be disabled
    And I should see "The group does not yet satisfy the composition quota rules."

  Scenario: A declined invitation stays visible to the leader
    Given the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status   |
      | Team Blue | student2 | declined |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should see "Tara Two" in the ".selfselectadvanced-pendinginvites" "css_element"
    And I should see "Declined" in the ".selfselectadvanced-pendinginvites" "css_element"

  Scenario: The leader sees the deficiency bucket and satisfies it
    Given the following "mod_selfselectadvanced > quotas" exist:
      | selfselectadvanced | dimension | value  | mincount |
      | ssa1               | gender    | Female | 1        |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status  |
      | Team Blue | student2 | invited |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should see "Composition requirements"
    And I should see "Needs 1 more from Gender Female"
    And I should see "invitation(s) are still waiting for an answer"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I press "Accept"
    Then I should see "You have joined the group \"Team Blue\"."
    And I should not see "Needs 1 more from Gender Female"
    And I should see "Satisfied"

  Scenario: A team the seat plan can satisfy only by an exact allocation may submit
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | guide1   | Gita      | Guide    | guide1@example.com |
    And the following "course enrolments" exist:
      | user   | course | role    |
      | guide1 | C1     | teacher |
    And the following "mod_selfselectadvanced > slots" exist:
      | selfselectadvanced | mincount | dimension  | matchtype | value | allowoverlap |
      | ssa1               | 2        | department | value     |       | 0            |
      | ssa1               | 1        | department | value     | Civil | 0            |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |
      | Team Blue | student3 | confirmed |
      | Team Blue | student4 | confirmed |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should see "Composition requirements"
    # The two Maths students share the "any one department" seats, which
    # leaves a Civil student for the Civil seat. Booking the seats in
    # declaration order instead takes the Civil pair first and starves
    # the Civil seat, which is what used to happen here.
    And I should not see "Need 1 more"
    And the "Submit to guide" "button" should be enabled
