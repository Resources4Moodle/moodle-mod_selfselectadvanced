@mod @mod_selfselectadvanced
Feature: The sequential ticket queue for composition changes and unfreezes
  In order to change a settled team accountably
  As a guide or leader I file a request, and one manager or coordinator
  at a time works it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email               |
      | student1 | Sam       | One         | s1@example.com      |
      | guide1   | Gina      | Guide       | g1@example.com      |
      | teacher1 | Tina      | Teach       | teach1@example.com  |
      | coord1   | Cora      | Coordinator | coord1@example.com  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
      | coord1   | C1     | teacher        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 1             |
    # ACTIVITY context (1.20.1): the Group Coordinator role does work
    # inside one activity and is assignable nowhere else, so the table
    # has to sit BELOW the activities table that creates its reference.
    And the following "role assigns" exist:
      | user   | role             | contextlevel    | reference |
      | coord1 | groupcoordinator | Activity module | ssa1      |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Blue | student1 | guide1 | firm  | ##yesterday## |

  Scenario: The guide asks for a composition change and the manager works it exclusively
    # Through the guide dashboard's own Team page link, because since
    # 1.20.1 a stock non-editing teacher holds :viewassignedteams and NOT
    # :viewall, so the landing page's staff "all teams" list is not their
    # route in - the dashboard is.
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Group page" "link" in the "Team Blue" "table_row"
    And I set the field "Request a composition change from the managers" to "Swap in a data specialist"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Composition change"
    And I should see "Swap in a data specialist"
    And I should see "Open"
    When I press "Take up"
    Then I should see "Ticket taken up"
    And I should see "Being handled"
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord1
    Then I should see "Being handled"
    And I should see "Tina Teach" in the ".selfselectadvanced-tickets" "css_element"
    And I should not see "Take up" in the ".selfselectadvanced-tickets" "css_element"
    # Slice B2: resolve/decline moved to the ticket's own thread - the
    # claimant follows their row's "Open thread" link to reach the forms.
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I follow "Open thread"
    And I set the field "Resolution note" to "Move staged and committed"
    And I press "Resolve"
    Then I should see "Ticket updated"
    And I should see "Resolved"
    And I should see "Move staged and committed"

  Scenario: A duplicate live request is refused
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Group page" "link" in the "Team Blue" "table_row"
    And I set the field "Request a composition change from the managers" to "Swap in a data specialist"
    And I press "File request"
    And I set the field "Request a composition change from the managers" to "Asking twice"
    And I press "File request"
    Then I should see "ticket of this kind already exists"

  Scenario: A coordinator cannot take up a request about a team they guide
    # The request has to come from somebody else: a worker's own
    # requests are kept out of their queue entirely, so the refusal is
    # only reachable for a request another person filed.
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | guide  | state  | timeapproved  |
      | ssa1               | Team Own | student1 | coord1 | frozen | ##yesterday## |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Own"
    And I set the field "Request an unfreeze from the managers" to "Our member has left the course"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord1
    # 1.20.20 (seam audit B6): the button asks the gate BEFORE the
    # click - it renders disabled with the conflict reason beside it,
    # so the coordinator reads why instead of being refused after.
    Then the "Take up" "button" should be disabled
    And I should see "you are the assigned guide"

  Scenario: A worker does not see their own request in their own queue
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Mine | student1 | coord1 | firm  | ##yesterday## |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as coord1
    And I follow "Team Mine"
    And I set the field "Request a composition change from the managers" to "One member never turns up"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page
    # Their only request is their own, so their queue is empty.
    Then I should see "No tickets."
    And I should not see "Team Mine"
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Team Mine"

  Scenario: Students cannot open the ticket queue
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Lab groups"
    Then I should not see "Ticket queue"

  Scenario: The queue can be narrowed by type and status
    # A second requester, so there is a second ticket of a DIFFERENT type
    # to tell apart from Team Blue's composition-change request - the
    # confirmed member's "leadership help" ask, filed through the group
    # page exactly as myrequests.feature does it.
    Given the following "users" exist:
      | username | firstname | lastname | email          |
      | student2 | Sara      | Two      | s2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Group page" "link" in the "Team Blue" "table_row"
    And I set the field "Request a composition change from the managers" to "Swap in a data specialist"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Swap in a data specialist"
    And I should see "Our leader has gone quiet"
    # Narrowed to one type: only that ticket's text remains, and the
    # match count says so plainly rather than leaving an unstated total
    # behind a paged, filtered table.
    When I set the field "Request" to "Composition change"
    And I press "Filter"
    Then I should see "1 ticket(s) match"
    And I should see "Swap in a data specialist"
    And I should not see "Our leader has gone quiet"
    # Cleared back to all types: both are visible again.
    When I set the field "Request" to "All types"
    And I press "Filter"
    Then I should see "Swap in a data specialist"
    And I should see "Our leader has gone quiet"

  Scenario: A direct unfreeze resolves the team's unfreeze request by itself
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Freeze" "link" in the "Team Blue" "table_row"
    And I press "Freeze"
    Then I should see "frozen into a course group"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    And I set the field "Request an unfreeze from the managers" to "Our member left the course"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Blue"
    And I follow "Unfreeze"
    And I press "Unfreeze"
    Then I should see "unfrozen and restored"
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page
    Then I should see "Resolved automatically"
