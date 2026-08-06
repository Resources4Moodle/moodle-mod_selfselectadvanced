@mod @mod_selfselectadvanced
Feature: A composition maximum on confirmed members is a wall, a maximum on projections is a notice
  In order to keep every team inside its own composition rules
  As a leader I am stopped from admitting past a filled maximum,
  informed when only pending invitations are affected, and my
  invitees are told the truth about their Accept button

  # Decision 60, from the maintainer's live breach of 2026-08-06:
  # a leader accepted a walk-up four seconds after an invitation
  # acceptance filled the last SCOPE seat, because the door called the
  # engine's refusal overridable. These scenarios drive the three
  # surfaces of the fix through the real page.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email          |
      | student1 | Diya      | One      | s1@example.com |
      | student2 | Ishaan    | Two      | s2@example.com |
      | student3 | Ananya    | Three    | s3@example.com |
      | student4 | Meera     | Four     | s4@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |
      | student4 | C1     | student |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | MDP groups | ssa1     | 5       | 5       | 1       | 1             |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | gender | department | subdepartment |
      | student1 | Female | SCOPE      | BAI           |
      | student2 | Male   | SCOPE      | BAI           |
      | student3 | Female | SCOPE      | BAI           |
      | student4 | Female | Design     | UX            |
    And the following "mod_selfselectadvanced > quotas" exist:
      | selfselectadvanced | dimension  | value | mincount | maxcount |
      | ssa1               | department | SCOPE | 2        | 2        |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | state   |
      | ssa1               | Alpha | student1 | forming |

  @javascript
  Scenario: The wall - a walk-up past a maximum filled by confirmed members is refused, not confirmed
    Given the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Alpha    | student2 | confirmed |
    And I am on the "MDP groups" "mod_selfselectadvanced > join" page logged in as student3
    And I set the field "Team you want to join" to "Alpha"
    And I set the field "Why you are asking" to "Bad decision to leave"
    And I press "Send the request"
    When I am on the "MDP groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Alpha"
    Then I should see "This team already has 2 confirmed member(s) with \"SCOPE\""
    And the "Accept" "button" should be disabled

  @javascript
  Scenario: The notice - only a pending invitation is affected, and no rule is claimed broken
    Given the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status  |
      | Alpha    | student2 | invited |
    And I am on the "MDP groups" "mod_selfselectadvanced > join" page logged in as student3
    And I set the field "Team you want to join" to "Alpha"
    And I set the field "Why you are asking" to "Room for me?"
    And I press "Send the request"
    When I am on the "MDP groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Alpha"
    Then I should see "pending invitation(s) could then no longer be accepted"
    And I click on "Accept" "button" confirming the dialogue
    And I should see "Accepted. The student has been moved and the team re-composed."

  Scenario: The truth on the invitee's landing page - a blocked invitation says so before the click
    Given the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Alpha    | student3 | confirmed |
      | Alpha    | student2 | invited   |
    When I am on the "MDP groups" "selfselectadvanced activity" page logged in as student2
    Then I should see "This invitation cannot currently be accepted" in the ".selfselectadvanced-myinvitations" "css_element"
    And I should see "already has 2 confirmed member(s)" in the ".selfselectadvanced-myinvitations" "css_element"

  Scenario: The leader's pending list carries the same annotation
    Given the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Alpha    | student3 | confirmed |
      | Alpha    | student2 | invited   |
    When I am on the "MDP groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Alpha"
    Then I should see "This invitation cannot currently be accepted" in the ".selfselectadvanced-pendinginvites" "css_element"
