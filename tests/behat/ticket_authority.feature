@mod @mod_selfselectadvanced
Feature: The 1.20.43 settings release - who may raise, responsible mode, and the disclaimer
  In order to control who may raise a ticket and to require a notice be
  read first
  As a teacher I configure who-may-raise checkboxes, a responsible-
  person mode and an optional disclaimer, and as a student I file
  within whatever those settings allow

  # The maintainer's named gap: "Currently, a group leader is not having
  # ability to raise ticket." Until 1.20.43 a leader's only ticket was
  # unfreeze-on-frozen; the general help type closes it.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email               |
      | student1 | Sam       | One         | s1@example.com      |
      | student2 | Sara      | Two         | s2@example.com       |
      | guide1   | Gina      | Guide       | g1@example.com       |
      | teacher1 | Tina      | Teach       | teach1@example.com   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |

  Scenario: A group leader files a help ticket from the group page - the leader gap
    Given the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1                | Team Blue | student1 | forming |
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student1
    And I set the field "Ask the managers and coordinators for help" to "My co-leader has gone quiet, what do I do?"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "General help"
    And I should see "My co-leader has gone quiet, what do I do?"

  Scenario: Responsible-person mode points a member to their leader
    Given the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | ticketresponsiblemode |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             | 1                      |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1                | Team Blue | student1 | forming |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask the managers and coordinators for help" to "Trying to ask on the group's behalf"
    # A confirmed non-leader member is ALSO offered leadership help
    # (decision 71) on this fixture, and both forms share the "File
    # request" button text - scoped to the help form's own, exactly as
    # guide_tickets.feature disambiguates dates from penalty.
    And I click on "File request" "button" in the "//form[.//input[@value='help']]" "xpath_element"
    Then I should see "Your group leader raises tickets for the group."

  Scenario: A disclaimer must be acknowledged before the filing form appears
    Given the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | ticketdisclaimer                                     |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             | Read the group charter before raising any request.  |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1                | Team Blue | student1 | forming |
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Read the group charter before raising any request."
    And I should not see "Ask the managers and coordinators for help"
    When I press "I acknowledge, continue"
    Then I should see "Ask the managers and coordinators for help"
    When I set the field "Ask the managers and coordinators for help" to "Where do I find the charter?"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I follow "Open thread"
    Then I should see "Where do I find the charter?"

  Scenario: The member checkbox off hides the group's help control, and the landing page entry with it
    Given the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | ticketraisemember |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             | 0                  |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1                | Team Blue | student1 | forming |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    Then I should not see "Ask the managers and coordinators for help"
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    Then I should not see "Ask the managers and coordinators for help"

  Scenario: The group page's live requests are visible only to the requester and to staff
    Given the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1                | Team Blue | student1 | guide1 | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"

    # The requester sees their own row.
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    Then I should see "This group's requests"
    And I should see "Leadership help"

    # The leader is party to neither this ticket nor to queue authority,
    # so they see nothing of it - even though the group genuinely has a
    # live request right now (proven above).
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student1
    Then I should not see "This group's requests"
    And I should not see "Leadership help"

    # Staff (an editing teacher, holding :manage) sees the whole live set.
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as teacher1
    Then I should see "This group's requests"
    And I should see "Leadership help"
