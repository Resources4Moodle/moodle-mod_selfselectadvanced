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

  # The two lines were rewritten in 1.20.1: they promised the owner that
  # "staff with full view can still see it", which T-07 made false - the
  # consent bypass now asks for :viewparticipantidentity, which no role
  # holds by default. tests/behat/contactprivacy.feature pins the new
  # copy in both modes of the contact-privacy switch; this scenario pins
  # the FLIP, so it asserts the leading clause of each line only.
  Scenario: A student grants consent and the state line flips
    Given I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "Your mobile number is hidden."
    And I should see "Share my number"
    When I press "Share my number"
    Then I should see "Your mobile number is shared with your confirmed group members"
    And I should see "Stop sharing my number"
    When I press "Stop sharing my number"
    Then I should see "Your mobile number is hidden."
    And I should see "Share my number"
