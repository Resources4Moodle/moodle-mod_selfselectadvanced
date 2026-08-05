@mod @mod_selfselectadvanced
Feature: Dissolving a team that can be neither repaired nor deleted
  In order to close a dead-end team
  As a manager holding the override-composition-rules capability
  I dissolve it with a typed reason and every member is parked

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
      | student3 | Nia       | Three    | student3@example.com |
      | teacher1 | Tina      | Teach    | teach1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 2       | 4       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
    # 1.20.6: a firm team accepts a join request only once its guide has
    # released it. Husk is the team a student asks to join in the scenario
    # below, so it carries the flag; Team Up does not, because nothing joins
    # it and the default state is the closed one.
      | selfselectadvanced | name    | leader   | state | releasedbyguide |
      | ssa1               | Team Up | student1 | firm  | 0               |
      | ssa1               | Husk    | student2 | firm  | 1               |

  Scenario: A solo-leader firm team is dissolved with a reason and its leader is parked
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Husk"
    Then I should see "Dissolve team"
    When I follow "Dissolve team"
    Then I should see "Every confirmed member is removed and recorded as parked"
    And I should see "Tara Two"
    When I press "Dissolve team"
    Then I should see "Give a reason for dissolving this team"
    And I should see "Every confirmed member is removed and recorded as parked"
    When I set the field "Reason for the override" to "Only member left the programme"
    And I press "Dissolve team"
    Then I should see "dissolved. Its members have been parked."
    And I should not see "Husk"
    When I am on the "Lab groups" "mod_selfselectadvanced > flagged" page
    Then I should see "Tara Two"

  @javascript
  Scenario: A student who asked to join a dissolved team can still open their requests
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    And I set the field "Team you want to join" to "Husk"
    And I set the field "Why you are asking" to "Nearer my lab"
    And I press "Send the request"
    Then I should see "Your request has gone to the team leader."

    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Husk"
    And I follow "Dissolve team"
    And I set the field "Reason for the override" to "Only member left the programme"
    And I press "Dissolve team"
    Then I should see "dissolved. Its members have been parked."

    # The request row still names a team that no longer exists. Reading
    # it with MUST_EXIST turned the asker's own page into an exception.
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "Team no longer exists"
    And I should not see "Can't find data record"
    And I should see "Only member left the programme"
