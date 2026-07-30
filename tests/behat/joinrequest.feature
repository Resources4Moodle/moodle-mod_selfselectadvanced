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
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1               | Team Blue | student1 | forming |
      | ssa1               | Team Gold | student2 | forming |
    # The groups generator already gives each leader their member row.
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student3 | confirmed |

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
