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
    And the following "role assigns" exist:
      | user   | role             | contextlevel | reference |
      | coord1 | groupcoordinator | Course       | C1        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Blue | student1 | guide1 | firm  | ##yesterday## |

  Scenario: The guide asks for a composition change and the manager works it exclusively
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    And I follow "Team Blue"
    And I set the field "Why is this change needed?" to "Swap in a data specialist"
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
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I set the field "Resolution note (required to resolve or decline)" to "Move staged and committed"
    And I press "Resolve"
    Then I should see "Ticket updated"
    And I should see "Resolved"
    And I should see "Move staged and committed"

  Scenario: A duplicate live request is refused
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    And I follow "Team Blue"
    And I set the field "Why is this change needed?" to "Swap in a data specialist"
    And I press "File request"
    And I set the field "Why is this change needed?" to "Asking twice"
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
    And I set the field "Why is this change needed?" to "Our member has left the course"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord1
    And I press "Take up"
    Then I should see "you are the assigned guide"

  Scenario: A worker does not see their own request in their own queue
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Mine | student1 | coord1 | firm  | ##yesterday## |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as coord1
    And I follow "Team Mine"
    And I set the field "Why is this change needed?" to "One member never turns up"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page
    Then I should not see "Team Mine" in the ".selfselectadvanced-tickets" "css_element"
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Team Mine"

  Scenario: Students cannot open the ticket queue
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Lab groups"
    Then I should not see "Ticket queue"

  Scenario: A direct unfreeze resolves the team's unfreeze request by itself
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I click on "Freeze" "link" in the "Team Blue" "table_row"
    And I press "Freeze"
    Then I should see "frozen into a course group"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    And I set the field "Why is this change needed?" to "Our member left the course"
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Blue"
    And I follow "Unfreeze"
    And I press "Unfreeze"
    Then I should see "unfrozen and restored"
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page
    Then I should see "Resolved automatically"
