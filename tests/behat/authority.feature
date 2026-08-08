@mod @mod_selfselectadvanced
Feature: A prohibited capability is honoured by the pages, not only by the services
  In order to be able to take an authority away and have it stay taken away
  As an administrator
  The controls disappear when I prohibit the capability behind them

  # The first three scenarios below were green against the PRE-WAVE page
  # code: their two controls (Create group, Freeze selected groups) were
  # already gated before 1.20's authority wave, so this file's
  # browser-level coverage of that wave was zero (audit F-5). Everything
  # from "A prohibited leader..." down was written to FAIL against the
  # HEAD copies of landing.php, group_page.php and group.php, and each
  # one was watched failing in the instance before it was counted.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | student2 | Tara      | Two      | s2@example.com     |
      | guide1   | Gina      | Guide    | g1@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 2       | 2             |

  Scenario: The create control is live until the capability is prohibited
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "Create group"
    Given the following "permission overrides" exist:
      | capability                        | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:creategroup | Prohibit  | student | Activity module | ssa1      |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should not see "Create group"

  Scenario: The bulk freeze control goes with the freeze capability
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Fir | student1 | guide1 | firm  | ##yesterday## |
      | ssa1               | Team Oak | student2 | guide1 | firm  | ##yesterday## |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    Then I should see "Team Fir"
    And I should see "Freeze selected groups"
    Given the following "permission overrides" exist:
      | capability                   | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:freeze | Prohibit  | teacher | Activity module | ssa1      |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    Then I should see "Team Fir"
    And I should not see "Freeze selected groups"

  Scenario: The assigned guide's group mark travels from the page to the ledger
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Mark | student1 | guide1 | firm  | ##yesterday## |
    When I am on the "Lab groups > Team Mark" "mod_selfselectadvanced > review" page logged in as guide1
    Then I should see "Group mark"
    When I set the field "award" to "73.5"
    And I press "Save mark"
    Then I should see "Group mark saved and grades republished."
    When I am on the "Lab groups > Team Mark" "mod_selfselectadvanced > review" page
    Then the field "award" matches value "73.50"

  # F-5: the leader's OWN team page. Before the wave every control here
  # was drawn from the leaderid column and the lifecycle state alone, so
  # a PROHIBIT left all three exactly where they were and turned each
  # into a form that ends at a no-permission page.
  Scenario: A prohibited leader is offered no leader control on their own team
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   |
      | ssa1               | Team Ash | student1 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    | leaverequested |
      | Team Ash | student2 | confirmed | ##yesterday##  |
    When I am on the "Lab groups > Team Ash" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Invite members"
    And I should see "Delete group"
    And I should see "Confirm leave"
    Given the following "permission overrides" exist:
      | capability                         | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:creategroup | Prohibit   | student | Activity module | ssa1      |
    When I am on the "Lab groups > Team Ash" "mod_selfselectadvanced > group" page logged in as student1
    # Still their team, still forming, still on the page: the only thing
    # that moved is the administrator's decision.
    Then I should see "Team Ash"
    And I should see "Two" in the "Tara" "table_row"
    And I should not see "Invite members"
    And I should not see "Delete group"
    And I should not see "Confirm leave"

  # F-5: the invitation must stay LISTED. A student who may no longer
  # answer still has to be able to see that a team is waiting on them -
  # their leader can withdraw it and the expiry task can expire it, and
  # neither of those is something they can ask for if the row vanishes.
  Scenario: A prohibited invitee keeps the invitation and loses the two buttons
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   |
      | ssa1               | Team Elm | student1 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status  |
      | Team Elm | student2 | invited |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    Then I should see "Team Elm" in the ".selfselectadvanced-myinvitations" "css_element"
    And I should see "Accept" in the ".selfselectadvanced-myinvitations" "css_element"
    And I should see "Decline" in the ".selfselectadvanced-myinvitations" "css_element"
    Given the following "permission overrides" exist:
      | capability                     | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:respond | Prohibit   | student | Activity module | ssa1      |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    Then I should see "Team Elm" in the ".selfselectadvanced-myinvitations" "css_element"
    And I should not see "Accept" in the ".selfselectadvanced-myinvitations" "css_element"
    And I should not see "Decline" in the ".selfselectadvanced-myinvitations" "css_element"
    # And the same pair on the team page itself, which draws its own.
    When I am on the "Lab groups > Team Elm" "mod_selfselectadvanced > group" page logged in as student2
    Then I should see "Team Elm"
    And I should not see "You are invited to join this group."

  # F-1: leadership can be ACQUIRED as well as created, and this is the
  # control that acquires it. The banner stays for the same reason the
  # invitation row does; the two buttons go.
  Scenario: A prohibited nominee sees the nomination and is offered no answer
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | successor | successortype |
      | ssa1               | Team Oak | student1 | student2  | transfer      |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Team Oak | student2 | confirmed |
    When I am on the "Lab groups > Team Oak" "mod_selfselectadvanced > group" page logged in as student2
    Then I should see "Tara Two has been nominated as the new leader."
    And I should see "Accept" in the ".selfselectadvanced-nomination" "css_element"
    And I should see "Decline" in the ".selfselectadvanced-nomination" "css_element"
    Given the following "permission overrides" exist:
      | capability                     | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:respond | Prohibit   | student | Activity module | ssa1      |
    When I am on the "Lab groups > Team Oak" "mod_selfselectadvanced > group" page logged in as student2
    Then I should see "Tara Two has been nominated as the new leader."
    And I should not see "Accept" in the ".selfselectadvanced-nomination" "css_element"
    And I should not see "Decline" in the ".selfselectadvanced-nomination" "css_element"

  # F-1, the leader's half of the same banner: cancelling a nomination
  # is a leader verb, so it goes with the leader capability.
  Scenario: A prohibited leader cannot cancel the nomination they raised
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | successor | successortype |
      | ssa1               | Team Yew | student1 | student2  | transfer      |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Team Yew | student2 | confirmed |
    When I am on the "Lab groups > Team Yew" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Cancel nomination"
    Given the following "permission overrides" exist:
      | capability                         | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:creategroup | Prohibit   | student | Activity module | ssa1      |
    When I am on the "Lab groups > Team Yew" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Tara Two has been nominated as the new leader."
    And I should not see "Cancel nomination"

  # F-4: the proposal LINK, in a browser. The unit tests compare the
  # page's exported answer with teamaccess::may_read_proposal() and with
  # the file server; this is the half of that invariant a user can see -
  # the invitee, whom the page admits and the file refuses, gets the
  # filename and no link.
  @javascript @_file_upload
  Scenario: The proposal link is drawn for exactly the audience the file server serves
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   | guide  |
      | ssa1               | Team Paper | student1 | guide1 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup   | user     | status  |
      | Team Paper | student2 | invited |
    When I am on the "Lab groups > Team Paper" "mod_selfselectadvanced > group" page logged in as student1
    And I press "Upload or replace the proposal"
    And I upload "mod/assign/feedback/editpdf/fixtures/blank.pdf" file to "Project proposal" filemanager
    And I press "Save changes"
    Then I should see "Proposal saved."
    And "blank.pdf" "link" should exist
    When I am on the "Lab groups > Team Paper" "mod_selfselectadvanced > group" page logged in as student2
    Then I should see "blank.pdf"
    And I should see "Available once you have joined the group"
    And "blank.pdf" "link" should not exist
    When I am on the "Lab groups > Team Paper" "mod_selfselectadvanced > group" page logged in as guide1
    Then "blank.pdf" "link" should exist
    When I am on the "Lab groups > Team Paper" "mod_selfselectadvanced > group" page logged in as teacher1
    Then "blank.pdf" "link" should exist

  # D1: the browser half of the approve/return hole, taken as the audit
  # took it. With :viewassignedteams prohibited the guide is refused
  # review.php, the team page and the proposal file - and the DASHBOARD
  # still listed the team, drew a live Accept and honoured the press.
  # Both halves are here: the queue decides on can_approve(), so the
  # controls go; the review page the button used to lead to refuses the
  # same actor at its door, as it already did before this wave.
  Scenario: A guide refused the team page is offered no queue decision
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state         |
      | ssa1               | Team Pine | student1 | guide1 | pending_guide |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    Then I should see "Team Pine"
    And I should see "Return" in the "Team Pine" "table_row"
    And I should not see "Only the assigned guide can do this."
    Given the following "permission overrides" exist:
      | capability                               | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:viewassignedteams | Prohibit   | teacher | Activity module | ssa1      |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    # Still their team and still in their queue: the only thing that
    # moved is the administrator's decision.
    Then I should see "Team Pine"
    And I should see "Only the assigned guide can do this." in the "Team Pine" "table_row"
    And I should not see "Return" in the "Team Pine" "table_row"
    And the "Lab groups > Team Pine" "review" page refuses me

  # D1, the positive control the scenario above needs to mean anything:
  # the queue's one-click decision still works for the guide the team
  # belongs to. A gate that refuses everybody passes every refusal test.
  Scenario: The assigned guide still accepts from the queue
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   | guide  | state         |
      | ssa1               | Team Cedar | student1 | guide1 | pending_guide |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I press "Accept"
    Then I should see "approved"
    When I am on the "Lab groups > Team Cedar" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Firm"

  # D1, the same team with "act as a project guide" itself withdrawn -
  # the capability whose string names review, return AND approve, and
  # which neither of the last two verbs consulted. The dashboard is
  # gated on it, so the whole page goes.
  Scenario: A guide prohibited from guiding reaches no dashboard at all
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   | guide  | state         |
      | ssa1               | Team Fig | student1 | guide1 | pending_guide |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    Then I should see "Accept" in the "Team Fig" "table_row"
    Given the following "permission overrides" exist:
      | capability                   | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:guide | Prohibit   | teacher | Activity module | ssa1      |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as guide1
    Then the "Lab groups" "guide" page refuses me
    And the "Lab groups > Team Fig" "review" page refuses me

  # D2: submitting to a guide is the leader verb both previous waves
  # walked past. Before this wave the leader of a forming team was shown
  # neither Invite members nor Delete group and was still shown - and
  # still obeyed on - Submit to guide.
  Scenario: A prohibited leader is offered no submit control
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name     | leader   |
      | ssa1               | Team Bay | student1 |
    When I am on the "Lab groups > Team Bay" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Submit to guide"
    And I should see "Invite members"
    Given the following "permission overrides" exist:
      | capability                         | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:creategroup | Prohibit   | student | Activity module | ssa1      |
    When I am on the "Lab groups > Team Bay" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Team Bay"
    And I should not see "Submit to guide"
    And I should not see "Invite members"

  # AUTH-002 and AUTH-003 (external review, 1.20.2). Two more controls
  # drawn from the raw leaderid, which decision 38 leaves in place for a
  # prohibited leader: "Edit title and brief", whose write was an inline
  # update_record() on groupedit.php, and "Upload or replace the
  # proposal", whose write was an inline file_save_draft_area_files() on
  # group.php. Neither had a service; both do now, and both services
  # refuse the actor the buttons were being offered to.
  #
  # The upload control goes ENTIRELY here rather than turning into
  # "Remove the proposal", because this team has no proposal to remove:
  # the F3 exception keeps a control only while there is something of
  # the actor's own to take down.
  Scenario: A prohibited leader is offered neither the edit nor the proposal control
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | title  | leader   |
      | ssa1               | Team Larch | Optics | student1 |
    When I am on the "Lab groups > Team Larch" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Edit title and brief"
    And I should see "Upload or replace the proposal"
    Given the following "permission overrides" exist:
      | capability                         | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:creategroup | Prohibit   | student | Activity module | ssa1      |
    When I am on the "Lab groups > Team Larch" "mod_selfselectadvanced > group" page logged in as student1
    # Still their team and still forming: the only thing that moved is
    # the administrator's decision.
    Then I should see "Optics"
    And I should not see "Edit title and brief"
    And I should not see "Upload or replace the proposal"
    # The staff repair path is untouched, which is the regression this
    # fix had to avoid: :creategroup is a STUDENT capability an editing
    # teacher never held, and demanding it above the branch is what made
    # the manager path unreachable in the first place (D6-4).
    When I am on the "Lab groups > Team Larch" "mod_selfselectadvanced > group" page logged in as teacher1
    Then I should see "Edit title and brief"
    And I should see "Upload or replace the proposal"

  # AUTH-001, and the one scenario in this file where a control
  # deliberately SURVIVES the prohibition. Listing a team PUBLISHES it
  # to every guide in the activity; withdrawing the listing takes it
  # back down, and F3 says an actor is never blocked from making
  # themselves less visible. One flag used to draw both buttons, so the
  # fix had to split them rather than gate them - and a blanket gate
  # here would strand a published team on a page full of guides with
  # nobody but staff able to withdraw it.
  Scenario: The listing button goes and the withdrawal button stays
    Given the following "activities" exist:
      | activity           | course | name          | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | eoienabled |
      | selfselectadvanced | C1     | Interest lab  | ssa2     | 1       | 4       | 2       | 2             | 5         | 1          |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | title  | leader   |
      | ssa2               | Team Lime | Radar  | student1 |
      | ssa2               | Team Leaf | Sonar  | student1 |
    When I am on the "Interest lab > Team Lime" "mod_selfselectadvanced > group" page logged in as student1
    And I press "List this group for guides"
    Then I should see "Listed for guides"
    And I should see "Withdraw from listing"
    When I am on the "Interest lab > Team Leaf" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "List this group for guides"
    Given the following "permission overrides" exist:
      | capability                         | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:creategroup | Prohibit   | student | Activity module | ssa2      |
    When I am on the "Interest lab > Team Leaf" "mod_selfselectadvanced > group" page logged in as student1
    Then I should not see "List this group for guides"
    When I am on the "Interest lab > Team Lime" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Listed for guides"
    And I should see "Withdraw from listing"

  # AUTH-004: accepting an expression of interest INSTALLS a guide on
  # the team and auto-declines every rival, so it is leader authority
  # and eoi::respond() now asks for it. The interest itself stays on the
  # screen - the leader still has to be able to see who is waiting on
  # them, the same rule the invitation list follows.
  Scenario: A prohibited leader is offered no decision on an expression of interest
    Given the following "activities" exist:
      | activity           | course | name          | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | eoienabled |
      | selfselectadvanced | C1     | Interest lab  | ssa2     | 1       | 4       | 2       | 2             | 5         | 1          |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | title  | leader   |
      | ssa2               | Team Lily | Lasers | student1 |
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup  | guide  | status  |
      | ssa2               | Team Lily | guide1 | pending |
    When I am on the "Interest lab > Team Lily" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Gina Guide" in the ".selfselectadvanced-eoirows" "css_element"
    And I should see "Accept" in the ".selfselectadvanced-eoirows" "css_element"
    And I should see "Decline" in the ".selfselectadvanced-eoirows" "css_element"
    Given the following "permission overrides" exist:
      | capability                         | permission | role    | contextlevel    | reference |
      | mod/selfselectadvanced:creategroup | Prohibit   | student | Activity module | ssa2      |
    When I am on the "Interest lab > Team Lily" "mod_selfselectadvanced > group" page logged in as student1
    Then I should see "Gina Guide" in the ".selfselectadvanced-eoirows" "css_element"
    And I should not see "Accept" in the ".selfselectadvanced-eoirows" "css_element"
    And I should not see "Decline" in the ".selfselectadvanced-eoirows" "css_element"
