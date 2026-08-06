@mod @mod_selfselectadvanced
Feature: Leadership transfer and step-out
  In order to hand over my group
  As a leader
  I nominate a confirmed member who accepts

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student1 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |

  @javascript
  Scenario: The leader nominates and the nominee takes over
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    # The leader action clusters are tabs since 1.20.11; the nominate
    # form lives in the succession pane.
    And I click on "Leadership succession" "link" in the ".selfselectadvanced-leadertabs" "css_element"
    And I set the field "Succession type" to "Transfer leadership (the current leader stays as a member)"
    And I set the field "Successor" to "Tara Two"
    And I press "Nominate"
    Then I should see "Nomination sent"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    Then I should see "Team Blue" in the ".selfselectadvanced-mynominations" "css_element"
    When I follow "Team Blue"
    Then I should see "Tara Two has been nominated as the new leader."
    When I press "Accept"
    Then I should see "You are now the leader of this group."
    When I am on the "Lab groups" "selfselectadvanced activity" page
    Then I should see "You lead 1 of 1 groups"

  Scenario: The nominee declines and the leader is told
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   | successor | successortype |
      | ssa1               | Team Named | student1 | student2  | transfer      |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup   | user     | status    |
      | Team Named | student2 | confirmed |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Named"
    And I press "Decline"
    Then I should see "You declined the nomination."

  Scenario: Step-out is blocked below the minimum size with the reason shown
    Given the following "activities" exist:
      | activity           | course | name      | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Min lab   | ssa2     | 2       | 4       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | successor | successortype |
      | ssa2               | Team Min | student1 | student2  | stepout       |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Team Min | student2 | confirmed |
    When I am on the "Min lab" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Min"
    Then I should see "A replacement member must be invited and confirmed first."
