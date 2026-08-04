@mod @mod_selfselectadvanced
Feature: Every authority this activity grants has a control that spends it
  In order to do the job the capability I hold is named for
  As a manager or a Group Coordinator
  I reach the review page, I am offered the Freeze the service accepts from
  me, and I can decide an expression of interest without holding manage

  Background:
    Given the following "users" exist:
      | username     | firstname | lastname | email              |
      | student1     | Sam       | One      | s1@example.com     |
      | student2     | Tara      | Two      | s2@example.com     |
      | student3     | Uma       | Three    | s3@example.com     |
      | student4     | Vik       | Four     | s4@example.com     |
      | guide1       | Gina      | Guide    | g1@example.com     |
      | guide2       | Hari      | Helper   | g2@example.com     |
      | coordteacher | Cora      | Ord      | cord@example.com   |
      | teacher1     | Tina      | Teach    | teach1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user         | course | role           |
      | student1     | C1     | student        |
      | student2     | C1     | student        |
      | student3     | C1     | student        |
      | student4     | C1     | student        |
      | guide1       | C1     | teacher        |
      | guide2       | C1     | teacher        |
      | coordteacher | C1     | teacher        |
      | teacher1     | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | maxguided | eoienabled |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 2       | 2             | 5         | 1          |
    # ACTIVITY context: the Group Coordinator role is assignable nowhere
    # else, so this table sits BELOW the activity that creates it.
    And the following "role assigns" exist:
      | user         | role             | contextlevel    | reference |
      | coordteacher | groupcoordinator | Activity module | ssa1      |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | guide  | state | timeapproved  |
      | ssa1               | Alpha | student1 | guide1 | firm  | ##yesterday## |
      | ssa1               | Beta  | student3 | guide2 | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Alpha    | student2 | confirmed |
      | Beta     | student4 | confirmed |

  # ---------------------------------------------------------------- ACT-001
  # The finding. db/access.php grants :guide to the non-editing teacher
  # archetype alone, and review.php required it on the ACTIVITY before
  # asking its own team-scoped predicate - which names :viewall and
  # :manage in so many words. So the manager was refused at the door of
  # a page written for them.
  Scenario: A manager reaches the review page of a team they do not guide
    When I am on the "Lab groups > Alpha" "mod_selfselectadvanced > review" page logged in as teacher1
    Then I should see "Members"
    And I should see "Tara Two"
    And I should not see "you do not currently have permissions"

  # The guard against over-widening: removing the :guide requirement
  # must not admit anybody the team-scoped predicate refuses.
  Scenario: A guide who guides a different team is still refused
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    Then the "Lab groups > Beta" "review" page refuses me and discloses nothing of "Vik Four"

  # The matched partner: the page still works for the audience it always
  # had, so the scenario above is not passing because the page broke.
  Scenario: The assigned guide still reaches their own team's review page
    When I am on the "Lab groups > Alpha" "mod_selfselectadvanced > review" page logged in as guide1
    Then I should see "Members"
    And I should see "Tara Two"

  # Membership is not a door here - the review page is the guide's
  # decision surface, not the team's.
  Scenario: A member of the team is still refused its review page
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    Then the "Lab groups > Alpha" "review" page refuses me and discloses nothing of "Guide notes"

  # ---------------------------------------------------------------- ACT-002
  # The manager holds :manage, which freeze_group() has accepted on its
  # on-behalf branch since strategy 1.16 D, and does NOT hold :freeze,
  # which is what the button and the POST gate both asked for. The
  # control was absent and the action was refused.
  Scenario: A manager is offered the Freeze the service would have accepted
    When I am on the "Lab groups > Alpha" "mod_selfselectadvanced > group" page logged in as teacher1
    Then "Freeze" "link" should exist
    When I follow "Freeze"
    Then I should see "A Moodle course group is created"
    When I press "Freeze"
    Then I should see "frozen into a course group"
    And I should see "Frozen"

  # ---------------------------------------------------------------- ACT-003
  # The role created to freeze on a guide's behalf, on the dashboard
  # built for it, beside a card counting the teams awaiting a freeze -
  # and no Freeze control anywhere on it.
  Scenario: A coordinator freezes an uninvolved team from their own dashboard
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinator" page logged in as coordteacher
    Then "Freeze" "link" should exist in the "Alpha" "table_row"
    When I click on "Freeze" "link" in the "Alpha" "table_row"
    Then I should see "A Moodle course group is created"
    When I press "Freeze"
    Then I should see "frozen into a course group"

  # The conflict-of-interest guard decides the offer, not just the
  # refusal: a coordinator nominated to take Gamma over is involved in
  # it, so the link is withheld there and offered on the team beside it.
  Scenario: An involved coordinator is offered no Freeze on that team
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | guide  | guidesuccessor | state | timeapproved  |
      | ssa1               | Gamma | student3 | guide2 | coordteacher   | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup | user     | status    |
      | Gamma    | student4 | confirmed |
    When I am on the "Lab groups" "mod_selfselectadvanced > coordinator" page logged in as coordteacher
    Then "Freeze" "link" should not exist in the "Gamma" "table_row"
    And "Freeze" "link" should exist in the "Alpha" "table_row"

  # ---------------------------------------------------------------- ACT-004
  # ":assignguide" is described as "assign or reassign a team's guide and
  # DECIDE EXPRESSIONS OF INTEREST". eoi::respond() has admitted it
  # since 1.20.0; the only screen that offers the choice asked :manage
  # by itself, and the Group Coordinator holds :assignguide and not
  # :manage - so the holder could decide from a test and from nowhere a
  # person could click.
  Scenario: A coordinator decides an expression of interest without manage
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | state   |
      | ssa1               | Delta | student3 | forming |
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide  | status  |
      | ssa1               | Delta    | guide1 | pending |
    When I am on the "Lab groups > Delta" "mod_selfselectadvanced > group" page logged in as coordteacher
    Then I should see "Gina Guide"
    And "Accept" "link" should exist in the ".selfselectadvanced-eoirows" "css_element"
    When I click on "Accept" "link" in the ".selfselectadvanced-eoirows" "css_element"
    And I press "Accept"
    Then I should see "Accepted"

  # The discriminating half. Withdraw the capability and the control
  # goes with it - so the scenario above is passing because of
  # :assignguide and not because the page offers everybody the buttons.
  Scenario: With assignguide withdrawn the coordinator is offered no decision
    Given the following "permission overrides" exist:
      | capability                          | permission | role             | contextlevel | reference |
      | mod/selfselectadvanced:assignguide  | Prevent    | groupcoordinator | Course       | C1        |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name  | leader   | state   |
      | ssa1               | Delta | student3 | forming |
    And the following "mod_selfselectadvanced > eois" exist:
      | selfselectadvanced | ssagroup | guide  | status  |
      | ssa1               | Delta    | guide1 | pending |
    When I am on the "Lab groups > Delta" "mod_selfselectadvanced > group" page logged in as coordteacher
    Then I should see "Gina Guide"
    And "Accept" "link" should not exist in the ".selfselectadvanced-eoirows" "css_element"
    And "Decline" "link" should not exist in the ".selfselectadvanced-eoirows" "css_element"
