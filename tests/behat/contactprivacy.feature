@mod @mod_selfselectadvanced
Feature: The activity protects participant contact details
  In order to keep students' contact details out of staff hands they do not belong in
  As a teacher
  I rely on the per-activity contact-privacy setting, on by default

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teach    | teacher1@example.com |
      | guide1   | Gina      | Guide    | guide1@example.com   |
      | guide2   | Owen      | Other    | guide2@example.com   |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | guide1   | C1     | teacher        |
      | guide2   | C1     | teacher        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | eoienabled |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 2             | 5         | 1          |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | title     | leader   | guide  |
      | ssa1               | Alpha | Pendulums | student1 | guide1 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Alpha    | student2 | confirmed |
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide  | status   |
      | ssa1               | Alpha    | guide1 | accepted |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | mobile        | shareconsent |
      | student2 | +91 987654321 | 0            |

  Scenario: The team drill-down shows no address to anybody
    Given I am on the "Lab groups" "mod_selfselectadvanced > eoi list" page logged in as guide1
    When I follow "Group members"
    Then I should see "Two"
    And I should not see "student2@example.com"
    And "Email the whole team" "link" should not exist
    And I should see "Send a message"

  Scenario: An editing teacher sees no address there either
    Given the following "permission overrides" exist:
      | capability                   | permission | role           | contextlevel | reference |
      | mod/selfselectadvanced:guide | Allow      | editingteacher | Course       | C1        |
    # PENDING, not accepted: decision 20 keeps the drill-down for an
    # accepted interest only while its holder is still the team's
    # assigned guide, and Alpha's guide is guide1. A decision still
    # being made is exactly what this drill-down exists to inform, and
    # it is the state an editing teacher looking at a team they do not
    # guide would really be in.
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide    | status  |
      | ssa1               | Alpha    | teacher1 | pending |
    And I am on the "Lab groups" "mod_selfselectadvanced > eoi list" page logged in as teacher1
    When I follow "Group members"
    Then I should see "Two"
    And I should not see "student2@example.com"
    And I should not see "student1@example.com"
    And "Email the whole team" "link" should not exist

  # @javascript is load-bearing, not decoration. Without it Behat drives
  # the non-JavaScript BrowserKit driver, where an UNCHECKED advcheckbox
  # submits nothing at all - so mod_form falls back to its own default
  # of 1 and the switch never moves. Measured 2026-08-01 by logging the
  # value arriving at selfselectadvanced_update_instance(): without the
  # tag it read 1 after the save, with the tag it reads 0. This scenario
  # asserts what happens when protection is OFF, so it has to actually
  # get there.
  @javascript
  Scenario: Turning protection off does not bring the address back
    Given I am on the "Lab groups" "selfselectadvanced activity editing" page logged in as teacher1
    And I expand all fieldsets
    And I set the field "Protect participant contact details" to "0"
    And I press "Save and display"
    When I am on the "Lab groups" "mod_selfselectadvanced > eoi list" page logged in as guide1
    And I follow "Group members"
    Then I should see "Two"
    And I should not see "student2@example.com"

  # The promise the plugin makes to the person whose number it is. Until
  # 1.20.1 this line told the student "Staff with full view can still
  # see it" while the release was busy making that false - nobody below
  # a site administrator bypasses consent now. The copy is pinned here
  # because it is the one place the plugin speaks to the data subject
  # about their own data.
  Scenario: The consent line tells the owner what actually happens to their number
    Given I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    Then I should see "Your mobile number is hidden. Only a site administrator, or staff the site has deliberately allowed to see participant identity fields, can still read it."
    And I should not see "Staff with full view can still see it"
    When I press "Share my number"
    Then I should see "Your mobile number is shared with your confirmed group members, the guide assigned to your group, a staff member handling a request you raised, and the teachers who manage this activity."
    And I should not see "group leaders, group members and guides"
    # With protection OFF the audience is wider, and the line says so
    # rather than claiming an exclusivity the code does not enforce. A
    # SECOND activity carries the switch off, because the consent flag
    # is site-wide while the switch is per activity - which is exactly
    # the pair of states the two strings exist to tell apart.
    Given the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | contactprivacy |
      | selfselectadvanced | C1     | Open lab   | ssa2     | 1       | 4       | 1       | 2             | 5         | 0              |
    When I am on the "Open lab" "selfselectadvanced activity" page logged in as student2
    Then I should see "and staff who can see this activity's participants"
    And I should not see "Nobody else in this activity sees it"

  Scenario: Mobile stays consent-gated for the assigned guide
    Given I am on the "Lab groups" "mod_selfselectadvanced > eoi list" page logged in as guide1
    When I follow "Group members"
    Then I should see "Not shared"
    And I should not see "+91 987654321"

  Scenario: The flagged report never prints an unconsented number
    # flagged.php is the plugin's largest unconsented-mobile surface: it
    # used to pass a hard-coded literal true, so not even the owner's own
    # sharing consent was consulted, on the whole enrolled cohort and in
    # its CSV. This scenario drives the real page, because the PHPUnit
    # test can only replicate the composition and a replica cannot catch
    # the page drifting away from it (measured 2026-08-01: with the
    # replica alone, restoring the literal left all 474 tests green).
    # Negative control: restore the literal true at flagged.php's
    # display_line() and plain_line() call sites - the last step goes red.
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student3 | Raj       | Three    | student3@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student3 | C1     | student |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | mobile        | shareconsent |
      | student3 | +91 777000111 | 0            |
    # flagged.php is a whole-activity report and correctly asks :viewall.
    # T-19 took :viewall off the non-editing teacher ARCHETYPE, so this
    # scenario grants it deliberately: the actor here is a broad-read
    # holder who guides nothing, which is exactly the audience the
    # assertion below is about.
    And the following "permission overrides" exist:
      | capability                     | permission | role    | contextlevel | reference |
      | mod/selfselectadvanced:viewall | Allow      | teacher | Course       | C1        |
    When I am on the "Lab groups" "mod_selfselectadvanced > flagged" page logged in as guide2
    Then I should see "Raj Three"
    And I should not see "+91 777000111"

  Scenario: A consented number reaches the assigned guide and no one else
    # guide2's interest is PENDING, not rejected. T-19's decision 19
    # closes the drill-down to a rejected or withdrawn applicant
    # ENTIRELY (viewassignedteams.feature pins that), so the "no one
    # else" half has to be driven by a LIVE interest - which is the
    # harder case anyway: an applicant who is admitted to the page and
    # still gets no number, because they are not connected to its owner.
    Given the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide  | status  |
      | ssa1               | Alpha    | guide2 | pending |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | mobile        | shareconsent |
      | student1 | +91 900000111 | 1            |
    And I am on the "Lab groups" "mod_selfselectadvanced > eoi list" page logged in as guide1
    When I follow "Group members"
    Then I should see "+91 900000111"
    When I am on the "Lab groups" "mod_selfselectadvanced > eoi list" page logged in as guide2
    And I follow "Group members"
    Then I should see "Not shared"
    And I should not see "+91 900000111"

  @javascript
  Scenario: The invite picker promises only a name search and matches only names
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name | title  | leader   |
      | ssa1               | Beta | Orbits | student2 |
    And I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Beta"
    Then "//input[@placeholder='Search by name']" "xpath_element" should exist
    And "//input[@placeholder='Search by name or email']" "xpath_element" should not exist
    When I click on "//input[@placeholder='Search by name']" "xpath_element"
    And I type "student1@example.com"
    Then I should see "No suggestions"
    And I should not see "Sam One"

  Scenario: Send a message reaches the student without an address
    Given I am on the "Lab groups" "mod_selfselectadvanced > eoi list" page logged in as guide1
    And I follow "Group members"
    And I should not see "@example.com"
    When I click on "Send a message" "link" in the "Tara" "table_row"
    Then I should see "Send a message to Tara Two"
    And I should see "You do not see their email address and they do not see yours."
    And I should not see "@example.com"
    And I set the field "Subject" to "About Thursday"
    And I set the field "Message" to "Please come and see me."
    And I press "Send a message"
    Then I should see "Your message has been sent to Tara Two."
    And I should not see "@example.com"

  @javascript
  Scenario: The message arrives in the student's own notifications
    Given I am on the "Lab groups" "mod_selfselectadvanced > eoi list" page logged in as guide1
    And I follow "Group members"
    And I click on "Send a message" "link" in the "Tara" "table_row"
    And I set the field "Subject" to "About Thursday"
    And I set the field "Message" to "Please come and see me."
    And I press "Send a message"
    Then I should see "Your message has been sent to Tara Two."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I open the notification popover
    Then I should see "About Thursday"
