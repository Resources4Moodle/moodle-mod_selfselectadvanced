@mod @mod_selfselectadvanced
Feature: A guide's request queue and asking for a higher team limit
  In order to carry the work I can actually take on
  As a guide I see everything waiting for me in one queue, and can ask
  the coordinators to raise my limit; they grant or refuse it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email              |
      | student1 | Sam       | One         | s1@example.com     |
      | guide1   | Gina      | Guide       | g1@example.com     |
      | guide2   | Hari      | Helper      | g2@example.com     |
      | teacher1 | Tina      | Teach       | teach1@example.com |
      | coord1   | Cora      | Coordinator | coord1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | guide1   | C1     | teacher        |
      | guide2   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
      | coord1   | C1     | teacher        |
    And the following "role assigns" exist:
      | user   | role             | contextlevel | reference |
      | coord1 | groupcoordinator | Course       | C1        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership | maxguided |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 1             | 2         |

  Scenario: A guide asks for a higher limit and a manager grants it in one action
    When I am on the "Lab groups" "mod_selfselectadvanced > guide queue" page logged in as guide1
    And I follow "What I have asked for"
    Then I should see "your limit is 2"
    When I set the field "Teams asked for" to "5"
    And I set the field "Reason" to "Two final-year cohorts this term"
    And I press "Send the request"
    Then I should see "Your request has gone to the Group Coordinators."
    And I should see "You have asked to guide up to 5 teams."

    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Team limit"
    And I should see "asking for 5"
    When I press "Take up"
    And I set the field with xpath "//input[@name='resolution']" to "Agreed for this term"
    And I press "Grant 5"
    Then I should see "Granted: the limit has been raised and the request closed."

    When I am on the "Lab groups" "mod_selfselectadvanced > guide queue" page logged in as guide1
    And I follow "What I have asked for"
    Then I should see "your limit is 5"
    And I should see "Agreed for this term"

  Scenario: A guide takes back a request nobody has picked up
    When I am on the "Lab groups" "mod_selfselectadvanced > guide queue" page logged in as guide1
    And I follow "What I have asked for"
    And I set the field "Teams asked for" to "4"
    And I set the field "Reason" to "Asked too soon"
    And I press "Send the request"
    Then I should see "You have asked to guide up to 4 teams."
    When I press "Withdraw it"
    Then I should see "Your request has been withdrawn."
    And I should see "Ask for a higher limit"

  Scenario: A coordinator does not see their own request in the queue they work
    When I am on the "Lab groups" "mod_selfselectadvanced > guide queue" page logged in as coord1
    And I follow "What I have asked for"
    And I set the field "Teams asked for" to "6"
    And I set the field "Reason" to "My own request"
    And I press "Send the request"
    Then I should see "Your request has gone to the Group Coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as coord1
    Then I should not see "My own request"

  Scenario: The queue shows a team's approach and links to it
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1               | Team Blue | student1 | forming |
    And the following "mod_selfselectadvanced > contacts" exist:
      | selfselectadvanced | ssagroup  | guide  | sentby   | message              |
      | ssa1               | Team Blue | guide1 | student1 | Please guide us      |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide queue" page logged in as guide1
    Then I should see "A team has approached you"
    And I should see "Team Blue"
    And I should see "Please guide us"
