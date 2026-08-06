@mod @mod_selfselectadvanced
Feature: The guide request set completed - reduction, date extension, penalty waiver
  In order to guide within my real capacity and stand up for my teams
  As a guide I can ask the coordinators to lower my team limit,
  extend a window or waive a penalty, and the queue treats my
  request like any other ticket

  # Maintainer flows (d) and (e), 2026-08-06. The queue's contract is
  # unchanged: resolving never mutates a team - the claimant acts with
  # the real tools and closes with a note.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email              |
      | student1 | Sam       | One         | s1@example.com     |
      | guide1   | Gina      | Guide       | g1@example.com     |
      | teacher1 | Tina      | Teach       | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             | 2         |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state |
      | ssa1               | Team Firm | student1 | guide1 | firm  |

  Scenario: The assigned guide asks for a date extension and the queue carries it
    When I am on the "Lab groups > Team Firm" "mod_selfselectadvanced > group" page logged in as guide1
    And I set the field "ticketreason-dates" to "One more week for the demo build"
    And I click on "File request" "button" in the "//form[.//input[@value='dates']]" "xpath_element"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Date extension"
    And I should see "Team Firm"

  Scenario: The assigned guide asks for a penalty waiver and the queue carries it
    When I am on the "Lab groups > Team Firm" "mod_selfselectadvanced > group" page logged in as guide1
    And I set the field "ticketreason-penalty" to "The delay was ours, not theirs"
    And I click on "File request" "button" in the "//form[.//input[@value='penalty']]" "xpath_element"
    Then I should see "Your request has been queued for the managers and coordinators."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Penalty waiver"

  Scenario: The guide asks to be relieved entirely, suggesting a successor
    When I am on the "Lab groups" "mod_selfselectadvanced > guide queue" page logged in as guide1
    And I follow "What I have asked for"
    And I set the field "New limit you are asking for (0 = relieve me entirely)" to "0"
    And I set the field "Why, and which guides you suggest instead" to "Sabbatical; suggest Tina Teach"
    And I press "Ask for the reduction"
    Then I should see "Your request has gone to the Group Coordinators."
    And I should see "You have asked to guide at most 0 team(s)."
    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    Then I should see "Team limit reduction"
    And I should see "Gina Guide asks to be relieved of guiding"
