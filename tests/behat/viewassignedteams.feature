@mod @mod_selfselectadvanced
Feature: A guide reaches the team they are assigned to without seeing everything
  In order to run a site where a non-editing teacher is not an unrestricted viewer
  As an administrator who has withdrawn mod/selfselectadvanced:viewall
  The guide still reaches, and acts on, the teams they are assigned to, and only those

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Sam       | One      | s1@example.com     |
      | student2 | Tara      | Two      | s2@example.com     |
      | student3 | Uma       | Three    | s3@example.com     |
      | student4 | Vik       | Four     | s4@example.com     |
      | student5 | Will      | Five     | s5@example.com     |
      | guide1   | Gina      | Guide    | g1@example.com     |
      | guide2   | Hari      | Helper   | g2@example.com     |
      | teacher1 | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | student5 | C1     | student        |
      | guide1   | C1     | teacher        |
      | guide2   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | eoienabled |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 2             | 5         | 1          |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | guide  | state | timeapproved  |
      | ssa1               | Alpha | student1 | guide1 | firm  | ##yesterday## |
      | ssa1               | Beta  | student3 | guide2 | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Alpha    | student2 | confirmed |
      | Beta     | student4 | confirmed |

  # The positive control. If this fails, every scenario below it is
  # meaningless: they all assert that the override changes nothing.
  Scenario: Without the override the guide reaches the team page
    When I am on the "Lab groups > Alpha" "mod_selfselectadvanced > group" page logged in as guide1
    Then I should see "Members"
    # First and last name are separate sortable columns on this roster,
    # so the pair is asserted as a row and not as one string.
    And I should see "Two" in the "Tara" "table_row"

  Scenario: With viewall withdrawn the assigned guide still reaches the team page and acts on it
    Given the following "permission overrides" exist:
      | capability                     | permission | role    | contextlevel | reference |
      | mod/selfselectadvanced:viewall | Prevent    | teacher | Course       | C1        |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Group page" "link" in the "Alpha" "table_row"
    Then I should see "Members"
    And I should see "Two" in the "Tara" "table_row"
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page
    And I follow "Groups I guide"
    And I click on "Freeze" "link" in the "Alpha" "table_row"
    Then I should see "A Moodle course group is created"
    When I press "Freeze"
    Then I should see "frozen into a course group"
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page
    And I follow "Groups I guide"
    And I click on "Release" "link" in the "Alpha" "table_row"
    Then I should see "returns to firm"

  Scenario: The same guide is refused a team they do not guide
    Given the following "permission overrides" exist:
      | capability                     | permission | role    | contextlevel | reference |
      | mod/selfselectadvanced:viewall | Prevent    | teacher | Course       | C1        |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    Then the "Lab groups > Beta" "group" page refuses me and discloses nothing of "Uma"

  Scenario: An unassigned guide is refused the review page
    Given the following "permission overrides" exist:
      | capability                     | permission | role    | contextlevel | reference |
      | mod/selfselectadvanced:viewall | Prevent    | teacher | Course       | C1        |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    Then the "Lab groups > Beta" "review" page refuses me and discloses nothing of "Vik Four"
    # The matched partner: Beta's own guide still reviews Beta.
    When I am on the "Lab groups > Beta" "mod_selfselectadvanced > review" page logged in as guide2
    Then I should see "Members"
    And I should see "Vik Four"

  Scenario: A manager without viewall reaches the team page
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | mod/selfselectadvanced:viewall | Prevent    | editingteacher | Course       | C1        |
    When I am on the "Lab groups > Alpha" "mod_selfselectadvanced > group" page logged in as teacher1
    Then I should see "Members"
    And I should see "Two" in the "Tara" "table_row"
    And I should see "Upload or replace the proposal"

  # Step 4 widens group.php's door; step 5 narrows what is behind it.
  # Shipping the first without the second would trade a lockout for a
  # disclosure, so the pairing is pinned on the REAL page and not only in
  # the renderable's unit test. The discriminating actor is a guide who
  # is ADMITTED to a team they do not guide - here by :manage, with
  # :viewall withdrawn - because :guide alone used to be enough to open
  # the mobile and composition-dimension columns on anybody's team.
  Scenario: An admitted guide who guides nothing gets names and nothing else
    Given the following "permission overrides" exist:
      | capability                     | permission | role    | contextlevel | reference |
      | mod/selfselectadvanced:viewall | Prevent    | teacher | Course       | C1        |
      | mod/selfselectadvanced:manage  | Allow      | teacher | Course       | C1        |
    And the following "mod_selfselectadvanced > attributes" exist:
      | user     | department | mobile        | shareconsent |
      | student2 | Physics    | +91 900000222 | 1            |
    When I am on the "Lab groups > Alpha" "mod_selfselectadvanced > group" page logged in as guide1
    Then I should see "Department"
    And I should see "Mobile number"
    And I should see "Physics"
    When I am on the "Lab groups > Alpha" "mod_selfselectadvanced > group" page logged in as guide2
    Then I should see "Members"
    And I should see "Two" in the "Tara" "table_row"
    But I should not see "Department"
    And I should not see "Mobile number"
    And I should not see "Physics"
    And I should not see "+91 900000222"

  # The Back button on the workload page led to flagged.php, which
  # requires :viewall - the capability step 1 stopped granting the
  # non-editing teacher. On a stock 1.20.1 install that made it a dead
  # end in the DEFAULT configuration, so the scenario follows it.
  Scenario: A guide reaches their own workload but not a colleague's, and Back is not a dead end
    Given the following "permission overrides" exist:
      | capability                     | permission | role    | contextlevel | reference |
      | mod/selfselectadvanced:viewall | Prevent    | teacher | Course       | C1        |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups you guide"
    Then I should see "Groups guided by Gina Guide"
    And I should see "Alpha"
    When I click on "Back" "link" in the ".selfselectadvanced-guideloadfooter" "css_element"
    Then I should see "Groups I guide"
    And I should not see "you do not currently have permissions"
    Then the "Lab groups > guide2" "guide load" page refuses me

  # The matched partner: a viewer who DOES hold the broad capability is
  # still returned to the report they came from.
  Scenario: A viewall holder is sent back to the flagged report
    When I am on the "Lab groups > guide1" "mod_selfselectadvanced > guide load" page logged in as teacher1
    Then I should see "Groups guided by Gina Guide"
    When I click on "Back" "link" in the ".selfselectadvanced-guideloadfooter" "css_element"
    Then I should see "Submitted groups awaiting a guide decision"
    And I should not see "you do not currently have permissions"

  # Step 6's other half: a site that PREVENTs the new capability must
  # not be shown a link to a page it will be refused.
  Scenario: The dashboard offers no team link when the capability is withdrawn
    Given the following "permission overrides" exist:
      | capability                              | permission | role    | contextlevel | reference |
      | mod/selfselectadvanced:viewall          | Prevent    | teacher | Course       | C1        |
      | mod/selfselectadvanced:viewassignedteams | Prevent   | teacher | Course       | C1        |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    Then I should see "Alpha"
    And "Group page" "link" should not exist in the "Alpha" "table_row"
    # And the refusal it would have led to is real.
    Then the "Lab groups > Alpha" "group" page refuses me and discloses nothing of "Tara"

  # The guard against over-widening: the door admits members, the
  # assigned guide, viewall and manage, and nobody else. Both shapes of
  # non-member are asserted, because the membership read is keyed on
  # groupid AND userid and a fixture that only ever tried one of them
  # would not notice a predicate that dropped the groupid.
  Scenario: A student who is in no team is still refused
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student5
    Then the "Lab groups > Alpha" "group" page refuses me and discloses nothing of "Tara"

  Scenario: A student who is in a different team is still refused
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student4
    Then the "Lab groups > Alpha" "group" page refuses me and discloses nothing of "Tara"

  Scenario: A rejected applicant loses the team while a pending one keeps it
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | state   |
      | ssa1               | Gamma | student1 | forming |
      | ssa1               | Delta | student5 | forming |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Gamma    | student2 | confirmed |
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide  | status   |
      | ssa1               | Gamma    | guide2 | rejected |
      | ssa1               | Delta    | guide2 | pending  |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide2
    Then the "Lab groups > Gamma" "eoi members" page refuses me and discloses nothing of "Tara"
    # The matched partner is not optional: a refusal scenario alone would
    # pass just as well if the page were broken.
    When I am on the "Lab groups > Delta" "mod_selfselectadvanced > eoi members" page
    Then I should see "Group members"
    And I should see "Five" in the "Will" "table_row"
    And I should not see "s5@example.com"
    # No row here can carry an action - a guide still awaiting a
    # decision guides nobody on this roster and holds neither :manage
    # nor :viewall - so the column is not offered at all. An empty
    # column headed "Actions" is an invitation to widen the gate that
    # emptied it.
    And I should not see "Send a message"
    And I should not see "Actions"

  # DECISION 20, state one, through the real drill-down page: the
  # handover has been PROPOSED and not answered, so guide1 is still the
  # team's guide and still sees its members' names.
  Scenario: An outgoing guide keeps the roster while the handover is pending
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | guide  | guidesuccessor | state | timeapproved  |
      | ssa1               | Echo  | student1 | guide1 | guide2         | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Echo     | student2 | confirmed |
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide  | status   |
      | ssa1               | Echo     | guide1 | accepted |
    When I am on the "Lab groups > Echo" "mod_selfselectadvanced > eoi members" page logged in as guide1
    Then I should see "Group members"
    And I should see "Two" in the "Tara" "table_row"

  # DECISION 20, state two: the SAME fixture, with the handover
  # completed through the real Accept button on the guide dashboard.
  # The interest row still reads 'accepted' afterwards, which is why
  # the status on its own was never the right question.
  Scenario: Accepting the handover ends the outgoing guide's roster in the same act
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | guide  | guidesuccessor | state | timeapproved  |
      | ssa1               | Echo  | student1 | guide1 | guide2         | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Echo     | student2 | confirmed |
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide  | status   |
      | ssa1               | Echo     | guide1 | accepted |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide2
    And I follow "Guide handover"
    Then I should see "Gina Guide proposed handing this group over to Hari Helper."
    When I press "Accept handover"
    Then I should see "Changes saved"
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    Then the "Lab groups > Echo" "eoi members" page refuses me and discloses nothing of "Tara"

  # DECISION 20, state three - the case with NO handover record at all:
  # staff reassigned the team, so guideid moved and nothing was ever
  # proposed. The old guide's accepted interest is still on file.
  Scenario: A guide displaced by a staff reassignment loses the roster at once
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | guide  | state | timeapproved  |
      | ssa1               | Echo  | student1 | guide2 | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Echo     | student2 | confirmed |
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide  | status   |
      | ssa1               | Echo     | guide1 | accepted |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    Then the "Lab groups > Echo" "eoi members" page refuses me and discloses nothing of "Tara"
    # The matched partner: the team's CURRENT guide is not locked out of
    # the team itself, only out of a drill-down that belongs to an
    # interest they never expressed.
    When I am on the "Lab groups > Echo" "mod_selfselectadvanced > group" page logged in as guide2
    Then I should see "Members"
    And I should see "Two" in the "Tara" "table_row"

  Scenario: The Group Coordinator role is not offered at course level
    When I am on the "C1" "mod_selfselectadvanced > course role assign" page logged in as admin
    Then I should not see "Group Coordinators"
    # The matched partner: assignability moved, it did not vanish.
    When I am on the "Lab groups" "selfselectadvanced activity roles" page
    Then I should see "Group Coordinators"
