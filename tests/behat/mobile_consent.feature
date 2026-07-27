@mod @mod_selfselectadvanced
Feature: Students control mobile-sharing consent
  In order to decide who can see my mobile number
  As a student
  I grant or revoke sharing consent from the landing page

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 1             |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | mobile        |
      | student1 | +91 987654321 |

  Scenario: A student grants consent and the state line flips
    Given I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "Your mobile number is hidden from students. Staff with full view can still see it."
    And I should see "Share my number"
    When I press "Share my number"
    Then I should see "Your mobile number is visible to your team leaders, teammates and guides."
    And I should see "Stop sharing my number"
    When I press "Stop sharing my number"
    Then I should see "Your mobile number is hidden from students. Staff with full view can still see it."
    And I should see "Share my number"
