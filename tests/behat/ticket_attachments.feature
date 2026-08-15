@mod @mod_selfselectadvanced @javascript @_file_upload
Feature: Attachments on ticket posts
  In order to provide supporting evidence with a request or its answer
  As a group leader or a member of staff
  I attach a file to my request and to its resolution

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email               |
      | student1 | Sam       | One         | s1@example.com      |
      | teacher1 | Tina      | Teach       | teach1@example.com  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1                | Team Blue | student1 | forming |

  Scenario: A leader attaches a file to their request, and a manager attaches one to the resolution
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student1
    And I set the field "Ask the managers and coordinators for help" to "Please see the attached notes"
    And I upload "lib/tests/fixtures/empty.txt" file to "Attachments (Ask the managers and coordinators for help)" filemanager
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."

    When I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I press "Take up"
    And I follow "Open thread"
    Then I should see "empty.txt"
    And I set the field "Resolution note" to "All sorted, see attached."
    And I upload "lib/tests/fixtures/empty.txt" file to "Attachments (Resolution note)" filemanager
    And I press "Resolve"
    Then I should see "Resolved"
    And I should see "empty.txt"

    # The requester reads both attachments back on their own list.
    When I am on the "Lab groups" "mod_selfselectadvanced > my requests" page logged in as student1
    And I follow "View thread"
    Then I should see "empty.txt"
