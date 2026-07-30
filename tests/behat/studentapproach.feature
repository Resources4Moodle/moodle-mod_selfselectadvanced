@mod @mod_selfselectadvanced
Feature: Students approach guides, and group names follow the course's format
  In order to keep the initiative with the students
  As an editing teacher I flip the switch, and guides advertise nothing

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | guide1   | Gina      | Guide    | g1@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name        | idnumber | minsize | maxlead | maxmembership | studentapproach | guidevolunteer |
      | selfselectadvanced | C1     | Approached  | ssa1     | 1       | 1       | 2             | 1               | 0              |

  Scenario: The landing page states the ground rules and the chooser shows no loads
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name    | leader   | state   |
      | ssa1               | Seekers | student1 | forming |
    When I am on the "Approached" "selfselectadvanced activity" page logged in as student1
    Then I should see "Guides do not advertise availability here"
    When I follow "Seekers"
    Then I should see "Gina Guide"
    And I should not see "Guiding"

  Scenario: The switch refuses the guide-side modes on the settings form
    When I am on the "Approached" "selfselectadvanced activity editing" page logged in as teacher1
    And I expand all fieldsets
    And I set the field "Guides can pick listed teams" to "1"
    And I press "Save and display"
    Then I should see "Students-approach mode requires expressions of interest to be disabled."

  Scenario: The project id follows the format the teacher chose
    Given the following "activities" exist:
      | activity           | course | name      | idnumber | minsize | maxlead | maxmembership | uidprefix | uiddigits | uidformat          |
      | selfselectadvanced | C1     | Formatted | ssa2     | 1       | 1       | 2             | MDP       | 3         | {prefix}/{number}  |
    When I am on the "Formatted" "selfselectadvanced activity" page logged in as student1
    And I follow "Create group"
    And I set the following fields to these values:
      | Group name    | Wayfinding team    |
      | Title of work | Pendulum study     |
      | Brief of work | We study gravity.  |
    And I press "Create group"
    Then I should see "Wayfinding team"
    And I should see "MDP/"
    And I should not see "MDP-C1-"

  Scenario: A group name already used in the course is refused
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name        | leader   |
      | ssa1               | Taken twice | student1 |
    And the following "activities" exist:
      | activity           | course | name    | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Another | ssa3     | 1       | 1       | 2             |
    When I am on the "Another" "selfselectadvanced activity" page logged in as student1
    And I follow "Create group"
    And I set the following fields to these values:
      | Group name    | Taken twice       |
      | Title of work | Something else    |
      | Brief of work | Another study.    |
    And I press "Create group"
    Then I should see "That group name is already taken in this course."
