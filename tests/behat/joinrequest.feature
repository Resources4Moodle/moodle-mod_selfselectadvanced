@mod @mod_selfselectadvanced
Feature: Asking to join another team, and the guide releasing a settled one
  In order to end up in the right team without troubling the staff
  As a student I ask, the team's leader answers, and once a team is
  settled its guide releases it before anything changes

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email              |
      | student1 | Sam       | One         | s1@example.com     |
      | student2 | Ravi      | Two         | s2@example.com     |
      | student3 | Nina      | Three       | s3@example.com     |
      | student4 | Omar      | Four        | s4@example.com     |
      | guide1   | Gina      | Guide       | g1@example.com     |
      | teacher1 | Tina      | Teach       | teach1@example.com |
      | coord1   | Cora      | Coordinator | coord1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
      | coord1   | C1     | teacher        |
    And the following "role assigns" exist:
      | user   | role             | contextlevel | reference |
      | coord1 | groupcoordinator | Course       | C1        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             |
      | selfselectadvanced | C1     | Dual labs  | ssa2     | 1       | 4       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1               | Team Blue | student1 | forming |
      | ssa1               | Team Gold | student2 | forming |
    # Distinct creation times, because "the teams you are in" is listed
    # in that order and three teams made in the same second is a tie -
    # which is no order at all on either supported engine.
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   | timecreated          |
      | ssa2               | Duo Red   | student1 | forming | ##2026-01-01 09:00## |
      | ssa2               | Duo Green | student2 | forming | ##2026-01-02 09:00## |
      | ssa2               | Duo Amber | student4 | forming | ##2026-01-03 09:00## |
    # The groups generator already gives each leader their member row.
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student3 | confirmed |
      | Duo Red   | student3 | confirmed |
      | Duo Amber | student3 | confirmed |

  @javascript
  Scenario: A student asks to join another team and its leader accepts
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in Team Blue at the moment."
    And I set the field "Team you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Closer to my programme"
    And I press "Send the request"
    Then I should see "Your request has gone to the team leader."
    And I should see "You have asked to join Team Gold."

    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student2
    And I follow "Asked of my team"
    Then I should see "Nina Three"
    And I should see "Closer to my programme"
    When I press "Accept"
    Then I should see "Accepted. The student has been moved and the team re-composed."

    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in Team Gold at the moment."

  @javascript
  Scenario: A student in two teams must say which one they leave
    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in these teams at the moment: Duo Red, Duo Amber."
    And I should see "Team you would leave"
    And I should not see "Keep my teams — join this one as well"
    And I set the field "Team you want to join" to "Duo Green"
    And I set the field "Why you are asking" to "Nearer my lab"
    And I press "Send the request"
    Then I should see "Choose the team you would leave, or choose to keep them all."
    When I set the field "Team you would leave" to "Duo Amber"
    And I press "Send the request"
    Then I should see "Your request has gone to the team leader."
    And I should see "Duo Amber" in the "Duo Green" "table_row"

    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student2
    And I follow "Asked of my team"
    Then I should see "Would leave Duo Amber."
    When I press "Accept"
    Then I should see "Accepted. The student has been moved and the team re-composed."

    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in these teams at the moment: Duo Red, Duo Green."

  @javascript
  Scenario: A student below the cap keeps their team and joins another
    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student4
    And I set the field "Team you want to join" to "Duo Green"
    And I set the field "Why you are asking" to "Two projects"
    And I set the field "Team you would leave" to "Keep my teams — join this one as well"
    And I press "Send the request"
    Then I should see "Your request has gone to the team leader."

    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student2
    And I follow "Asked of my team"
    Then I should see "Would leave no team — this would be an extra membership."
    When I press "Accept"
    Then I should see "Accepted. The student has been moved and the team re-composed."

    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student4
    Then I should see "You are in these teams at the moment: Duo Green, Duo Amber."

  @javascript
  Scenario: A student in one team sees no extra question
    # One team and no headroom: the student is TOLD which team makes
    # way, not asked. The label is present with a fixed value; the
    # choice - the select, its placeholder and the keep-my-teams option
    # - is what must be absent.
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in Team Blue at the moment."
    And I should see "Team you would leave"
    And I should see "Team Blue"
    And I should not see "Keep my teams — join this one as well"
    And I should not see "Choose the team you would leave, or choose to keep them all."
    When I set the field "Team you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Closer to my programme"
    And I press "Send the request"
    Then I should see "Your request has gone to the team leader."
    And I should see "Team Blue" in the "Team Gold" "table_row"

  @javascript
  Scenario: The source team's leader cannot answer a request made to another team
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    And I set the field "Team you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Please"
    And I press "Send the request"
    Then I should see "Your request has gone to the team leader."

    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student1
    And I follow "Asked of my team"
    Then I should see "Nobody has asked to join your team."

  @javascript
  Scenario: A coordinator can answer when the leader is away
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    And I set the field "Team you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Please"
    And I press "Send the request"

    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as coord1
    And I follow "Asked of my team"
    Then I should see "Nina Three"
    When I press "Accept"
    Then I should see "Accepted. The student has been moved and the team re-composed."

  Scenario: A student takes back a request nobody has answered
    Given the following "mod_selfselectadvanced > joinrequests" exist:
      | selfselectadvanced | user     | ssagroup  | reason      |
      | ssa1               | student3 | Team Gold | Asked early |
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You have asked to join Team Gold."
    When I press "Withdraw it"
    Then I should see "Your request has been withdrawn."
    And I should see "Team you want to join"

  Scenario: A guide releases a team they froze, but not one staff froze
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Firm | student4 | guide1 | firm  | ##yesterday## |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Freeze" "link" in the "Team Firm" "table_row"
    And I press "Freeze"
    Then I should see "frozen into a course group"
    # Freezing lands on the team page, so the tab has to be reached from
    # the guide's own page again.
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page
    And I follow "Groups I guide"
    Then I should see "Release"
    When I click on "Release" "link" in the "Team Firm" "table_row"
    And I press "Unfreeze"
    Then I should see "unfrozen"

  # That a STAFF freeze blocks the guide's release is checked in
  # joinrequests_test, for a manager's freeze and a coordinator's alike:
  # it is an authority rule, and the capability archetypes make it
  # awkward to stage through the interface (an editing teacher does not
  # hold :freeze at all - that belongs to the non-editing teacher).
