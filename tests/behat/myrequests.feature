@mod @mod_selfselectadvanced
Feature: A requester can see the request they made and what came back
  In order to know what happened to a request I sent the coordinators
  As the student, leader or guide who filed it
  I see my own requests, their outcome, and I can take one back while
  nobody has picked it up

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email              |
      | student1 | Sam       | One         | s1@example.com     |
      | student2 | Sara      | Two         | s2@example.com     |
      | guide1   | Gina      | Guide       | g1@example.com     |
      | teacher1 | Tina      | Teach       | teach1@example.com |
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

  # MUTATION CAUGHT (run 2026-08-14): widening tickets::mine()'s scope to
  # `(t.requestedby = :userid OR 1=1)` makes the leader - who filed
  # nothing - see the member's request and its resolution, and this
  # scenario fails on "You have not sent any requests." The privacy
  # property is therefore tested through the page, not only in the unit.
  Scenario: A member sees their own request, the answer to it, and nobody else's
    # Filing it: a confirmed member asks for help with the leadership
    # (decision 71). Until 1.20.39 this is where the trail went cold -
    # the request existed and the requester could see nothing of it.
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    # The way in is offered because there is now something to see.
    When I am on the "Lab groups" "selfselectadvanced activity" page
    Then I should see "My requests"
    When I follow "My requests"
    Then I should see "Leadership help"
    And I should see "Our leader has gone quiet"
    And I should see "Open"
    # The teacher works it and writes the note that used to reach the
    # requester only by message. Slice B2: resolve moved to the ticket's
    # own thread - follow the "Open thread" link the claimed row now
    # carries instead of resolving inline on the queue.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I press "Take up"
    And I follow "Open thread"
    And I set the field "Resolution note" to "Spoken to the leader, all settled"
    And I press "Resolve"
    Then I should see "Resolved"
    # And the requester can read it.
    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student2
    Then I should see "Resolved"
    And I should see "Spoken to the leader, all settled"
    # It is their own list and no one else's: the leader filed nothing.
    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student1
    Then I should see "You have not sent any requests."
    And I should not see "Our leader has gone quiet"
    And I should not see "Spoken to the leader, all settled"

  Scenario: A requester takes back a request nobody has picked up
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Asked too soon"
    And I press "File request"
    And I am on the "Lab groups" "mod_selfselectadvanced > my requests" page
    Then "Withdraw" "button" should exist
    When I press "Withdraw"
    Then I should see "Your request was withdrawn."
    And I should see "Withdrawn"
    # The queue keeps the row - nothing is deleted here - but it is no
    # longer work: it shows as withdrawn and offers no Take up.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Withdrawn"
    And I should not see "Take up" in the ".selfselectadvanced-tickets" "css_element"

  Scenario: Once somebody is handling it there is no take-back button
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    And I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I press "Take up"
    And I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student2
    Then I should see "Being handled"
    And I should see "Somebody is handling this."
    And "Withdraw" "button" should not exist

  Scenario: Somebody who has asked for nothing is offered no way in
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should not see "My requests"

  Scenario: A requester's own list can be searched and filtered by status
    # Two of the requester's own tickets: the first withdrawn, so a
    # second of the same type is allowed (the duplicate guard is by
    # live status), and the two share no words - a search or a status
    # filter can only be finding the ONE it names.
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    And I am on the "Lab groups" "mod_selfselectadvanced > my requests" page
    And I press "Withdraw"
    Then I should see "Your request was withdrawn."
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Completely different wording about deadlines"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."

    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page
    Then I should see "Our leader has gone quiet"
    And I should see "Completely different wording about deadlines"

    # Narrowed by a search term unique to the second ticket's text.
    When I set the field "Search" to "deadlines"
    And I press "Filter"
    Then I should see "1 ticket(s) match"
    And I should see "Completely different wording about deadlines"
    And I should not see "Our leader has gone quiet"

    # Cleared: both are visible again.
    When I set the field "Search" to ""
    And I press "Filter"
    Then I should see "Our leader has gone quiet"
    And I should see "Completely different wording about deadlines"

    # Narrowed by STATUS instead - the same vocabulary the staff queue
    # offers (a "Status" field with "Withdrawn" among its options).
    When I set the field "Status" to "Withdrawn"
    And I press "Filter"
    Then I should see "1 ticket(s) match"
    And I should see "Our leader has gone quiet"
    And I should not see "Completely different wording about deadlines"
    When I set the field "Status" to "All statuses"
    And I press "Filter"
    Then I should see "Our leader has gone quiet"
    And I should see "Completely different wording about deadlines"

    # A search that matches NEITHER ticket must say so truthfully - not
    # the "you have not sent any requests" message, which would be a lie
    # (the requester plainly has two).
    When I set the field "Search" to "zzzznomatchatall"
    And I press "Filter"
    Then I should see "No tickets match this filter."
    And I should not see "You have not sent any requests."
