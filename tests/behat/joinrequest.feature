@mod @mod_selfselectadvanced
Feature: Asking to join another team, and the guide releasing a settled one
  In order to end up in the right team without troubling the staff
  As a student I ask, the team's leader answers, and once a team is
  settled its guide releases it before anything changes

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email              |
      | student1 | Sam       | One         | s1@example.com     |
      | student2 | Ravi      | Two         | s2@example.com     |
      | student3 | Nina      | Three       | s3@example.com     |
      | student4 | Omar      | Four        | s4@example.com     |
      | guide1   | Gina      | Guide       | g1@example.com     |
      | teacher1 | Tina      | Teach       | teach1@example.com |
      | coord1   | Cora      | Coordinator | coord1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | guide1   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
      | coord1   | C1     | teacher        |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 4       | 1       | 2             |
      | selfselectadvanced | C1     | Dual labs  | ssa2     | 1       | 4       | 1       | 2             |
    # DECISION 77 changed what these numbers have to be. "Lab groups" used to
    # allow ONE membership, which made every ask-to-join a swap - the everyday
    # path the ruling abolished. With no swap, a student at their limit cannot
    # ask at all, so the ordinary scenarios need headroom. Being AT the cap is
    # its own scenario, on "Dual labs", where student3 holds both memberships
    # the activity allows.
    # ACTIVITY context (1.20.1): the Group Coordinator role does work
    # inside one activity and is assignable nowhere else, so the table
    # has to sit BELOW the activities table that creates its reference.
    # coord1 works "Lab groups" only, which is what the course-level row
    # this replaces silently spread across both instances.
    And the following "role assigns" exist:
      | user   | role             | contextlevel    | reference |
      | coord1 | groupcoordinator | Activity module | ssa1      |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   |
      | ssa1               | Team Blue | student1 | forming |
      | ssa1               | Team Gold | student2 | forming |
    # Distinct creation times, because "the teams you are in" is listed
    # in that order and three teams made in the same second is a tie -
    # which is no order at all on either supported engine.
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state   | timecreated          |
      | ssa2               | Duo Red   | student1 | forming | ##2026-01-01 09:00## |
      | ssa2               | Duo Green | student2 | forming | ##2026-01-02 09:00## |
      | ssa2               | Duo Amber | student4 | forming | ##2026-01-03 09:00## |
    # The groups generator already gives each leader their member row.
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student3 | confirmed |
      | Duo Red   | student3 | confirmed |
      | Duo Amber | student3 | confirmed |

  @javascript
  Scenario: A student asks to join another team and its leader accepts
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in Team Blue at the moment."
    # The question decision 77 abolished is not on the form at all.
    And I should not see "Group you would leave"
    And I set the field "Group you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Closer to my programme"
    And I press "Send the request"
    Then I should see "Your request has gone to the group leader."
    And I should see "You have asked to join Team Gold."

    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student2
    And I follow "Asked of my group"
    Then I should see "Nina Three"
    And I should see "Closer to my programme"
    When I press "Accept"
    Then I should see "Accepted. The student has joined the group."

    # BOTH teams. Before the ruling this read "You are in Team Gold at the
    # moment" - Team Blue's leader lost a member to a decision taken on
    # another team's page.
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in these groups at the moment: Team Blue, Team Gold."

  @javascript
  Scenario: A student at their limit is told what to do about it, not asked to trade
    # THE RULING'S OWN CASE. student3 is in two of the two groups this
    # activity allows. There used to be a "which group will you leave" picker
    # here; now there is a panel naming each group with the control that acts
    # on it, because being told to ask your leader is only useful if you can.
    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in these groups at the moment: Duo Red, Duo Amber."
    And I should see "You are already in as many groups as you may join"
    And I should not see "Group you would leave"
    And I should not see "Keep my groups — join this one as well"
    # A group they are only a member of: a working link to the leave control.
    And I should see "Ask to leave Duo Red"
    And I should see "Ask to leave Duo Amber"
    When I follow "Ask to leave Duo Red"
    Then I should see "Duo Red"

  @javascript
  Scenario: A student below the cap keeps their team and joins another
    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student4
    And I set the field "Group you want to join" to "Duo Green"
    And I set the field "Why you are asking" to "Two projects"
    And I press "Send the request"
    Then I should see "Your request has gone to the group leader."

    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student2
    And I follow "Asked of my group"
    Then I should see "Omar Four"
    When I press "Accept"
    Then I should see "Accepted. The student has joined the group."

    When I am on the "Dual labs" "mod_selfselectadvanced > join" page logged in as student4
    Then I should see "You are in these groups at the moment: Duo Green, Duo Amber."

  @javascript
  Scenario: The form never asks which group the student would leave
    # It asked until decision 77, and pinned the answer in a hidden field when
    # there was only one option - an offer the plugin has no business making.
    # The label, the placeholder and the keep-my-groups option must all be
    # gone, and the request that results must record no group to leave.
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You are in Team Blue at the moment."
    And I should not see "Group you would leave"
    And I should not see "Keep my groups — join this one as well"
    And I should not see "Choose the group you would leave, or choose to keep them all."
    When I set the field "Group you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Closer to my programme"
    And I press "Send the request"
    Then I should see "Your request has gone to the group leader."
    # The student's own history names no group left, for a request that leaves none.
    And I should see "None — an extra group" in the "Team Gold" "table_row"

  @javascript
  Scenario: The source team's leader cannot answer a request made to another team
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    And I set the field "Group you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Please"
    And I press "Send the request"
    Then I should see "Your request has gone to the group leader."

    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student1
    And I follow "Asked of my group"
    Then I should see "Nobody has asked to join your group."

  @javascript
  Scenario: A coordinator can answer when the leader is away
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    And I set the field "Group you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Please"
    And I press "Send the request"

    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as coord1
    # 1.20.6: a coordinator holds no :respond - it is the students'
    # capability - so "Asked of my group" is now their ONLY tab and the page
    # lands them on it. Moodle renders an ACTIVE tab as text rather than an
    # anchor, so the old 'I follow' step had no link to find. The navigation
    # it used to perform is now done by the page itself, which is the point
    # of the fix: staff no longer arrive at a form the service refuses.
    Then I should see "Asked of my group"
    And I should see "Nina Three"
    When I press "Accept"
    Then I should see "Accepted. The student has joined the group."

  Scenario: A student takes back a request nobody has answered
    Given the following "mod_selfselectadvanced > joinrequests" exist:
      | selfselectadvanced | user     | ssagroup  | reason      |
      | ssa1               | student3 | Team Gold | Asked early |
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "You have asked to join Team Gold."
    When I press "Withdraw it"
    Then I should see "Your request has been withdrawn."
    And I should see "Group you want to join"

  Scenario: The leader answers a request on their own team page
    # Decision 53: a forming leader should not have to discover
    # joinrequest.php to learn that somebody has asked. The panel shows
    # the department and sub-department because those are COMPOSITION
    # attributes - what the team is assembled by - and no contact
    # detail at all.
    Given the following "mod_selfselectadvanced > attributes" exist:
      | user     | department | subdepartment |
      | student3 | Science    | Physics       |
    And the following "mod_selfselectadvanced > joinrequests" exist:
      | selfselectadvanced | user     | ssagroup  | reason                 |
      | ssa1               | student3 | Team Gold | Closer to my programme |
    When I am on the "Lab groups > Team Gold" "mod_selfselectadvanced > group" page logged in as student2
    Then I should see "Asked to join this group"
    And I should see "Nina Three"
    And I should see "Closer to my programme"
    # No "would leave" line: a join takes nobody out of anywhere, so the only
    # roster this decision changes is the one the leader is looking at.
    And I should not see "Would leave"
    And I should see "1 of 4 seats filled"
    When I set the field "A word back (optional)" to "Glad to have you"
    And I click on "Accept" "button" in the "//div[contains(@class, 'selfselectadvanced-joinpanel')]" "xpath_element"
    Then I should see "Accepted. The student has joined the group."
    # The queue is empty, so the panel goes with it - no empty scaffolding.
    And I should not see "Asked to join this group"
    # The roster and the composition both moved: Nina is on Team Gold's
    # roster now, with the two dimension columns her seat is judged by.
    And I should see "2 of 4 seats filled"
    And I should see "Science" in the "Three" "table_row"
    And I should see "Physics" in the "Three" "table_row"

  Scenario: A plain member of the asked team is offered no request panel
    Given the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Gold | student4 | confirmed |
    And the following "mod_selfselectadvanced > joinrequests" exist:
      | selfselectadvanced | user     | ssagroup  | reason                 |
      | ssa1               | student3 | Team Gold | Closer to my programme |
    When I am on the "Lab groups > Team Gold" "mod_selfselectadvanced > group" page logged in as student4
    Then I should see "Team Gold"
    And I should not see "Asked to join this group"
    And I should not see "Closer to my programme"
    # The other arm of the same door: a coordinator standing in for an
    # absent leader answers from the same panel.
    When I am on the "Lab groups > Team Gold" "mod_selfselectadvanced > group" page logged in as coord1
    Then I should see "Asked to join this group"
    And I should see "Nina Three"
    When I click on "Decline" "button" in the "//div[contains(@class, 'selfselectadvanced-joinpanel')]" "xpath_element"
    Then I should see "Declined, and the student has been told."
    And I should not see "Asked to join this group"

  Scenario: A guide releases a team they froze, but not one staff froze
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state | timeapproved  |
      | ssa1               | Team Firm | student4 | guide1 | firm  | ##yesterday## |
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page logged in as guide1
    And I follow "Groups I guide"
    And I click on "Freeze" "link" in the "Team Firm" "table_row"
    And I press "Freeze"
    Then I should see "frozen into a course group"
    # Freezing lands on the team page, so the tab has to be reached from
    # the guide's own page again.
    When I am on the "Lab groups" "mod_selfselectadvanced > guide" page
    And I follow "Groups I guide"
    Then I should see "Release"
    When I click on "Release" "link" in the "Team Firm" "table_row"
    And I press "Unfreeze"
    Then I should see "unfrozen"

  @javascript
  Scenario: Staff see the override disclosure and can accept over a failing rule
    Given the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Tight labs | ssa3     | 1       | 1       | 1       | 1             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   | state   |
      | ssa3               | Tight Gold | student2 | forming |
    And the following "mod_selfselectadvanced > joinrequests" exist:
      | selfselectadvanced | user     | ssagroup   | reason                 |
      | ssa3               | student3 | Tight Gold | Closer to my programme |
    When I am on the "Tight labs" "mod_selfselectadvanced > join" page logged in as teacher1
    # 1.20.6: as above - an editing teacher holds :manage but not :respond, so
    # the answer tab is their only one and is already active. See the
    # coordinator scenario for why the 'I follow' step was removed.
    Then I should see "Asked of my group"
    And I should see "Override composition rules…"
    # Without the override the refusal now NAMES the rule and its
    # figures, instead of the general "the rules refused it" (D6-5).
    When I press "Accept"
    Then I should see "L2"
    And I should see "Nina Three"
    When I click on "//summary[contains(., 'Override composition rules')]" "xpath_element"
    And I set the field "Maximum group size / reserved seats (L2)" to "1"
    And I set the field with xpath "//input[@name='note']" to "Agreed with the guide"
    And I press "Accept"
    Then I should see "Accepted."

  # That a STAFF freeze blocks the guide's release is checked in
  # joinrequests_test, for a manager's freeze and a coordinator's alike:
  # it is an authority rule, and the capability archetypes make it
  # awkward to stage through the interface (an editing teacher does not
  # hold :freeze at all - that belongs to the non-editing teacher).

  # Maintainer decision 53: the answering side reads the requester's
  # department and sub-department - COMPOSITION attributes, which is
  # what a team is assembled by - and reads no contact detail of anyone,
  # in either state of the contact-privacy switch. It is also told what
  # is CONFIRMED and what is merely PENDING, never one number that reads
  # as the current roster; and the asking side is shown the same two
  # attributes, so nobody has to guess what a leader sees of them.
  Scenario: Both sides of the join page show the composition attributes and honest seat counts
    Given the following "mod_selfselectadvanced > attributes" exist:
      | user     | department | subdepartment | mobile     |
      | student3 | Science    | Physics       | 9000000003 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status  |
      | Team Gold | student4 | invited |
    And the following "mod_selfselectadvanced > joinrequests" exist:
      | selfselectadvanced | user     | ssagroup  | reason                 |
      | ssa1               | student3 | Team Gold | Closer to my programme |
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student3
    Then I should see "What a group leader sees when they answer you"
    And I should see "Department: Science"
    And I should see "Sub-department: Physics"

    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student2
    And I follow "Asked of my group"
    Then I should see "Nina Three"
    And I should see "Department: Science"
    And I should see "Sub-department: Physics"
    # One seat filled and one invitation out: two facts, kept apart.
    And I should see "1 of 4 seats filled, 1 invitation(s) pending"
    # The cardinal rule holds on this page for the leader, who is a peer
    # and not a connection: no number, no address.
    And I should not see "9000000003"
    And I should not see "s3@example.com"

  # THE STALE-ACCEPTED ROW (maintainer's live report, 2026-08-06) AND
  # THE CONSENT-FIRST PROTOCOL (decision 63) in one journey: a peopled
  # team is never deleted by surprise - the leader requests the wind-up
  # with a reason, the member leaves with one click, Delete opens only
  # then - and afterwards the history says disbanded, not a bare
  # Accepted beside a team that no longer exists.
  @javascript
  Scenario: The full wind-up: request, one-click leave, delete, honest history
    When I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student4
    And I set the field "Group you want to join" to "Team Gold"
    And I set the field "Why you are asking" to "Gold looks right"
    And I press "Send the request"
    And I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Gold"
    And I press "Accept"
    Then I should see "Accepted. The student has joined the group."
    # A peopled team refuses the surprise delete and points at consent.
    And I should see "member(s) remain"
    When I follow "Request disband"
    And I set the field "Why the group should wind up (sent to every member)" to "The pool cannot complete us."
    And I press "Request disband"
    Then I should see "Your request has been sent to every member."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student4
    And I follow "Team Gold"
    Then I should see "The leader has asked this group to wind up"
    And I should see "The pool cannot complete us."
    When I press "Leave this winding-up group"
    Then I should see "You have left the group."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Gold"
    And I follow "Delete group"
    And I press "Delete group"
    And I am on the "Lab groups" "mod_selfselectadvanced > join" page logged in as student4
    Then I should see "Group no longer exists"
    And I should see "Accepted — the group was later disbanded"
