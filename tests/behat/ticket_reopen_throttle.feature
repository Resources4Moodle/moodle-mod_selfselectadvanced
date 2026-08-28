@mod @mod_selfselectadvanced
Feature: A requester can reopen a resolved request, and staff can slow a flood down
  In order to say that an answer did not settle things, and to keep one
  person's flood from burying the queue
  As the requester who filed a request, and as the teacher who works the queue
  I reply to reopen a resolved request with an explanation, and I set a
  limit on how often one person may file

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | student2 | Sara      | Two      | s2@example.com     |
      | guide1   | Gina      | Guide    | g1@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Blue | student1 | guide1 | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |

  # D-108, ruled 2026-08-27: the second answer to "did this help?" is no
  # longer a recorded "no" but an ACTION - reply to reopen - and the
  # explanation is required. Proven through the browser because the
  # requirement is about what the requester is asked for on the screen.
  Scenario: A requester reopens a resolved request and must explain why
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    # The teacher takes it up and answers.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I press "Take up"
    And I follow "Open thread"
    And I set the field "Resolution note" to "Spoken to the leader, all settled"
    And I press "Resolve"
    Then I should see "Resolved"
    # The requester is asked, and the second button is an action now.
    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student2
    And I follow "View thread"
    Then I should see "Did this help?"
    And I should see "Reply to reopen this request"
    # Reopening with nothing said is refused: "To open a closed ticket,
    # the individual should be asked to explain."
    When I press "Reply to reopen this request"
    Then I should see "Say what is still wrong."
    # With an explanation it goes back to the person who resolved it.
    When I set the field "Add a note (optional to say it helped, required to reopen)" to "Nothing has changed at all"
    And I press "Reply to reopen this request"
    Then I should see "Your request has been reopened"
    # The teacher has it back, with the reason on the thread.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I follow "Open thread"
    Then I should see "Nothing has changed at all"
    And I should see "Being handled"

  # The maintainer's second instruction of 2026-08-27: staff can throttle
  # one person who is flooding the queue.
  Scenario: A teacher limits one requester and the limit refuses their next request
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "First request"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    # The teacher reaches the limits screen from the queue itself.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I follow "Request limits"
    Then I should see "Nobody in this activity is under a request limit."
    # Setting one on the requester, from their own thread.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page
    And I follow "View thread"
    And I follow "Slow this requester down"
    And I set the field "Requests allowed (0 for no count limit)" to "1"
    And I set the field "Every (hours)" to "24"
    And I set the field "What the requester is told" to "Please keep it to one request a day"
    And I press "Apply the limit"
    Then I should see "The limit has been set"
    And I should see "Sara Two"
    # The requester takes their first request back and tries again: the
    # withdrawn one still counts, which is the point of the limit.
    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student2
    And I press "Withdraw"
    Then I should see "Withdrawn"
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page
    And I set the field "Ask for leadership help (say what the group needs):" to "Second request"
    And I press "File request"
    Then I should see "Please keep it to one request a day"
    # And lifting it lets them through again.
    When I am on the "Lab groups" "mod_selfselectadvanced > request limits" page logged in as teacher1
    And I press "Lift the limit"
    Then I should see "The limit on this requester has been lifted."
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Third request"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
