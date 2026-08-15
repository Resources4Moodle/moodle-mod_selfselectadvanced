@mod @mod_selfselectadvanced
Feature: The handling ladder - refer and escalate
  In order to hand a ticket to someone better placed to answer it, or to
  raise it above the coordinator tier
  As a Group Coordinator or an editing teacher
  I refer a claimed ticket to another coordinator, or escalate it to the
  editing-teacher/manager tier

  # Maintainer intent (verbatim): "The forum also does not have a feature
  # where a group coordinator can request another group coordinator to
  # respond or have the same or raise it to someone above them."

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email               |
      | student1 | Sam       | One         | s1@example.com      |
      | guide1   | Gina      | Guide       | g1@example.com      |
      | teacher1 | Tina      | Teach       | teach1@example.com  |
      | coord1   | Cora      | Coordone    | coord1@example.com  |
      | coord2   | Nora      | Coordtwo    | coord2@example.com  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
      | coord1   | C1     | teacher        |
      | coord2   | C1     | teacher        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 2             |
    # ACTIVITY context (1.20.1): the Group Coordinator role does work
    # inside one activity and is assignable nowhere else.
    And the following "role assigns" exist:
      | user   | role             | contextlevel    | reference |
      | coord1 | groupcoordinator | Activity module | ssa1      |
      | coord2 | groupcoordinator | Activity module | ssa1      |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1                | Team Blue | student1 | forming |

  Scenario: A claimant refers a ticket to another coordinator
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student1
    And I set the field "Ask the managers and coordinators for help" to "Someone who knows this area would help"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."

    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord1
    And I press "Take up"
    And I follow "Open thread"
    Then I should see "Refer to another coordinator"
    And I set the field "Refer to" to "Nora Coordtwo"
    And I set the field "Note for the coordinator you are referring this to" to "You know this area better than I do"
    And I press "Refer"
    Then I should see "Ticket referred."

    # Genuinely handed over, not merely relabelled: the NEW claimant sees
    # the claimant's own forms (resolve/decline/release) on the thread.
    # "Resolution note" - not "Resolve" - is the assertion: the resolve
    # form is a moodleform now (1.20.44 part 2, for its filemanager), and
    # its submit button is an <input type="submit" value="Resolve">,
    # whose value never joins the page's rendered TEXT the way the
    # <button>Resolve</button> it replaced did; the textarea's own
    # <label> is real text content and proves the same thing.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord2
    And I follow "Open thread"
    Then I should see "Resolution note"
    And I should see "Release"

    # The original claimant no longer holds it - their queue row offers
    # only "View thread" now (mine, tickets.php's own distinction),
    # never the claimant's "Open thread" primary button.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord1
    And I follow "View thread"
    Then I should not see "Resolution note"

  Scenario: Escalating releases a mere coordinator's claim and blocks Take up until a manager claims it
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student1
    And I set the field "Ask the managers and coordinators for help" to "This is above my authority"
    And I press "File request"
    And I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord1
    And I press "Take up"
    And I follow "Open thread"
    Then I should see "Escalate"
    And I set the field "Why does this need to go to an editing teacher or manager?" to "Needs a manager's decision"
    And I press "Escalate"
    Then I should see "Ticket escalated."
    And I should see "Escalated"
    And I should see "Open"

    # A different coordinator sees the badge and is refused Take up.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord2
    Then I should see "Escalated"
    And I should see "This request has been escalated"

    # An editing teacher (manage-level) may still take it up.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I press "Take up"
    Then I should see "Ticket taken up"
