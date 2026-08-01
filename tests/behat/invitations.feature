@mod @mod_selfselectadvanced
Feature: Invitation-only joining with reserved seats
  In order to fill my lab group
  As a leader
  I need to invite course peers who accept or decline

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
      | student3 | Uma       | Three    | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |
    # contactprivacy 0 (legacy): the selector scenario below searches by
    # address, which is a privilege of viewers the identity gate admits
    # once contact details are protected - and a student leader is not
    # one. The protected behaviour is pinned in contactprivacy.feature
    # and in external_search_test::test_email_match_gated_when_private.
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | contactprivacy |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 3       | 1       | 1             | 0              |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student2 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status  |
      | Team Blue | student1 | invited |

  Scenario: An invitee accepts from the landing page
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "Team Blue" in the ".selfselectadvanced-myinvitations" "css_element"
    When I press "Accept"
    Then I should see "You have joined the group \"Team Blue\"."
    When I am on the "Lab groups" "selfselectadvanced activity" page
    Then I should see "You are a member of 1 of 1 groups"
    And I should see "No pending invitations"

  Scenario: An invitee declines and the leader keeps the freed seat
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I press "Decline"
    Then I should see "You declined the invitation to the group \"Team Blue\"."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    Then I should see "1 of 3 seats filled, 0 invitation(s) pending"

  Scenario: The leader withdraws a pending invitation
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    Then I should see "Sam One" in the ".selfselectadvanced-pendinginvites" "css_element"
    When I press "Withdraw"
    Then I should see "The invitation was withdrawn and its seat released."
    And I should see "1 of 3 seats filled, 0 invitation(s) pending"

  Scenario: Accepting one invitation auto-declines the others at the cap
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   |
      | ssa1               | Team Green | student3 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup   | user     | status  |
      | Team Green | student1 | invited |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "Team Green" in the ".selfselectadvanced-myinvitations" "css_element"
    When I click on "Accept" "button" in the "Team Blue" "list_item"
    Then I should see "You have joined the group \"Team Blue\"."
    When I am on the "Lab groups" "selfselectadvanced activity" page
    Then I should see "No pending invitations"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student3
    And I follow "Team Green"
    Then I should see "Sam One" in the ".selfselectadvanced-pendinginvites" "css_element"
    And I should see "Declined" in the ".selfselectadvanced-pendinginvites" "css_element"

  @javascript
  Scenario: The leader finds an invitee by email in the native selector
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    And I set the field "Invite members" to "student3@example.com"
    And I press "Send invitations"
    Then I should see "1 invitation(s) sent."
    And I should see "Uma Three" in the ".selfselectadvanced-pendinginvites" "css_element"
