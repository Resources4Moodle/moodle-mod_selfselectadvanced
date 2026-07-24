@mod @mod_selfselectadvanced
Feature: Editing teachers customise notification templates per activity
  In order to speak to my students in my own words
  As an editing teacher
  I customise the messages the activity sends

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teach    | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber |
      | selfselectadvanced | C1     | Lab groups | ssa1     |

  Scenario: A teacher overrides the invitation template and can reset it
    Given I am on the "Lab groups" "mod_selfselectadvanced > templates" page logged in as teacher1
    Then I should see "Invitation (to the invitee)"
    And I should see "Default text" in the "Invitation (to the invitee)" "table_row"
    When I click on "Edit" "link" in the "Invitation (to the invitee)" "table_row"
    And I set the field "Subject" to "You are wanted, {$a->firstname}!"
    And I set the field "Body" to "Dear {$a->firstname}, the group {$a->group} wants you. Visit {$a->url}."
    And I press "Save changes"
    Then I should see "Changes saved"
    And I should see "Customised" in the "Invitation (to the invitee)" "table_row"
    And I should see "You are wanted" in the "Invitation (to the invitee)" "table_row"
    When I click on "Edit" "link" in the "Invitation (to the invitee)" "table_row"
    And I set the field "Reset to the default text" to "1"
    And I press "Save changes"
    Then I should see "Default text" in the "Invitation (to the invitee)" "table_row"
