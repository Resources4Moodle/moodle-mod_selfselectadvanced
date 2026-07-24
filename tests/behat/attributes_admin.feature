@mod @mod_selfselectadvanced
Feature: Site administrators manage participant attributes
  In order to drive composition quotas
  As a site administrator
  I ingest and edit plugin-local participant attributes

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
    And the following "mod_selfselectadvanced > departments" exist:
      | name       | parent |
      | Civil      |        |
      | Structures | Civil  |
      | Mechanical |        |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | gender | department | subdepartment | mobile        |
      | student1 | Female | Civil      | Structures    | +91 111 22222 |

  Scenario: The admin sees the attribute listing and edits a record inline
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > Group self-selection (Advanced) > Participant attributes" in site administration
    Then I should see "Sam One"
    And I should see "Structures"
    When I click on "Edit" "link" in the "Sam One" "table_row"
    And I set the field "Department" to "Mechanical"
    And I set the field "Sub-department" to "None"
    And I press "Save changes"
    Then I should see "Participant attributes saved."
    And I should see "Mechanical"

  Scenario: Staff see attribute lines on rosters, students do not
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "users" exist:
      | username | firstname | lastname | email              |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber |
      | selfselectadvanced | C1     | Lab groups | ssa1     |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student1 |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as teacher1
    And I follow "Team Blue"
    Then I should see "Female · Civil · Structures · +91 111 22222"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should not see "Structures"

  Scenario: The admin curates the pre-defined department tree
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > Group self-selection (Advanced) > Departments and sub-departments" in site administration
    Then I should see "Civil"
    And I should see "Structures"
    When I press "Add category"
    And I set the field "Name" to "Humanities"
    And I press "Save changes"
    Then I should see "Humanities"
    When I click on "Delete" "link" in the "Humanities" "table_row"
    Then I should not see "Humanities"
    When I click on "Delete" "link" in the "Civil" "table_row"
    Then I should see "Cannot delete"

  @javascript @_file_upload
  Scenario: An unknown department in the CSV is auto-created at admin level
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > Group self-selection (Advanced) > Participant attributes" in site administration
    And I upload "mod/selfselectadvanced/tests/fixtures/attributes_baddept.csv" file to "CSV file" filemanager
    And I press "Preview import"
    Then I should see "will be created"
