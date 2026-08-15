@mod @mod_selfselectadvanced
Feature: The ticket becomes a forum-style thread
  In order to have one accountable place a ticket's conversation lives
  As a requester or a member of staff
  I read and act on a ticket from its own thread page

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email               |
      | student1 | Sam       | One         | s1@example.com      |
      | student2 | Sara      | Two         | s2@example.com      |
      | guide1   | Gina      | Guide       | g1@example.com      |
      | teacher1 | Tina      | Teach       | teach1@example.com  |
      | coord1   | Cora      | Coordinator | coord1@example.com  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
      | coord1   | C1     | teacher        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 2             |
    # ACTIVITY context (1.20.1): the Group Coordinator role does work
    # inside one activity and is assignable nowhere else, so the table
    # has to sit BELOW the activities table that creates its reference.
    And the following "role assigns" exist:
      | user   | role             | contextlevel    | reference |
      | coord1 | groupcoordinator | Activity module | ssa1      |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Blue | student1 | guide1 | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |

  Scenario: File, take up, ask a question, answer it, and resolve - all through the thread
    # A confirmed MEMBER files "Leadership help" (decision 71) - the one
    # ticket type a plain student, not the guide or leader, may raise.
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."

    # Staff take it up on the queue, then move to the thread for
    # everything else - the queue itself offers no resolve/decline forms
    # any more (slice B2).
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Leadership help"
    And I press "Take up"
    Then I should see "Ticket taken up"
    And I follow "Open thread"
    Then I should see "Leadership help"
    And I should see "Sara Two"
    And I should see "Our leader has gone quiet"

    # Request more information.
    When I set the field "Question for the requester" to "Has anyone tried reaching them directly?"
    And I press "Send the question"
    Then I should see "Waiting on the requester"

    # The requester sees the question on their own thread AND is led to
    # it from their list without having to already know to look.
    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student2
    Then I should see "Waiting on the requester"
    And I should see "A question is waiting for your answer."
    And I follow "Respond"
    Then I should see "Has anyone tried reaching them directly?"
    And I set the field "Your reply" to "Yes, no answer in three days."
    And I press "Send reply"
    Then I should see "Your reply was sent."

    # Staff resolves from the thread.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I follow "Open thread"
    Then I should see "Yes, no answer in three days."
    And I set the field "Resolution note" to "Successor appointed, all settled."
    And I press "Resolve"
    Then I should see "Resolved"
    And I should see "Successor appointed, all settled."

    # And the requester reads the resolution on their own list.
    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student2
    Then I should see "Resolved"
    And I should see "Successor appointed, all settled."

  Scenario: The requester's thread never names the staff actor while the ticket is claimed
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    And I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I press "Take up"
    And I follow "Open thread"
    And I set the field "Question for the requester" to "Has anyone tried reaching them directly?"
    And I press "Send the question"

    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student2
    And I follow "Respond"
    # The claimant's real name must never appear on the requester's own
    # thread - only the anonymised idiom, even though the question text
    # itself (addressed to the requester) is shown in full.
    Then I should not see "Tina Teach"
    And I should see "Somebody"
    And I should see "Has anyone tried reaching them directly?"

  Scenario: The claimant hands a ticket back to the queue from its thread
    # Restored after orchestrator review (2026-08-15): without this,
    # nothing lets a claimant who cannot handle a ticket give it back
    # before the 1.20.44 refer/escalate ladder exists.
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    And I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I press "Take up"
    And I follow "Open thread"
    Then I should see "Release"
    And I press "Release"
    Then I should see "Open"

    # Genuinely back in the queue, not merely relabelled: a DIFFERENT
    # staff member can take it up.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord1
    Then I should see "Open"
    And I press "Take up"
    Then I should see "Ticket taken up"
