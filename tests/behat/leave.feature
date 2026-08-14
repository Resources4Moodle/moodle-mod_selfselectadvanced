@mod @mod_selfselectadvanced
Feature: Leaving a forming team
  In order to change my mind while formation is still open
  As a member I ask to leave, and as the leader I confirm

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
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student1 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |

  Scenario: A member asks to leave and the leader confirms
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    And I press "Ask to leave this group"
    Then I should see "Leave request sent to the leader."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should see "Tara Two" in the ".selfselectadvanced-leaverequests" "css_element"
    When I press "Confirm leave"
    Then I should see "The member has left the group."
    And I should not see "Tara" in the ".selfselectadvanced-roster" "css_element"

  Scenario: A leader is never offered a leave control
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should not see "Ask to leave this group"

  Scenario: A member takes back a request they have not had answered
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    And I press "Ask to leave this group"
    Then I should see "Leave request sent to the leader."
    And "Withdraw my request to leave" "button" should exist
    When I press "Withdraw my request to leave"
    Then I should see "Your request to leave was withdrawn. You are still a member."
    # Back where they started: the ask is offered again, the take-back is not.
    And "Ask to leave this group" "button" should exist
    And "Withdraw my request to leave" "button" should not exist
    # And the leader has nothing left to answer.
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should not see "Confirm leave"

  Scenario: The leader declines a request and the member stays
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    And I press "Ask to leave this group"
    And I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then "Decline" "button" should exist
    When I press "Decline"
    Then I should see "You declined the request. The member stays in the group."
    And I should see "Tara" in the ".selfselectadvanced-roster" "css_element"
    And I should not see "Confirm leave"
    # The member is still a member, and may ask again rather than being
    # barred by having been refused once.
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    Then "Ask to leave this group" "button" should exist
    And "Withdraw my request to leave" "button" should not exist
