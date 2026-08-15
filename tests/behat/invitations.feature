@mod @mod_selfselectadvanced
Feature: Invitation-only joining with reserved seats
  In order to fill my lab group
  As a leader
  I need to invite course peers who accept or decline

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Tara      | Two      | student2@example.com |
      | student3 | Uma       | Three    | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |
    # contactprivacy 0 (LEGACY), and that is what makes the two selector
    # scenarios below worth running rather than an accident of the
    # fixture. Wave 3D withdrew address MATCHING from
    # candidates::search for every viewer in BOTH states of the switch,
    # so this activity - the one with nothing to protect - is where a
    # re-added match would be easiest to defend and least likely to be
    # noticed. The switch-ON half of the same property is pinned by
    # contactprivacy.feature ("The invite picker promises only a name
    # search and matches only names"); the service and web-service
    # levels are pinned in both states by
    # external_search_test::test_email_match_gated_when_private.
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxsize | maxlead | maxmembership | contactprivacy |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 3       | 1       | 1             | 0              |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Blue | student2 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status  |
      | Team Blue | student1 | invited |

  Scenario: An invitee accepts from the landing page
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "Team Blue" in the ".selfselectadvanced-myinvitations" "css_element"
    When I press "Accept"
    Then I should see "You have joined the group \"Team Blue\"."
    When I am on the "Lab groups" "selfselectadvanced activity" page
    Then I should see "You are a member of 1 of 1 groups"
    And I should see "No pending invitations"

  Scenario: An invitee declines and the leader keeps the freed seat
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I press "Decline"
    Then I should see "You declined the invitation to the group \"Team Blue\"."
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    Then I should see "1 of 3 seats filled, 0 invitation(s) pending"

  Scenario: The leader withdraws a pending invitation
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    Then I should see "Sam One" in the ".selfselectadvanced-pendinginvites" "css_element"
    When I press "Withdraw"
    Then I should see "The invitation was withdrawn and its seat released."
    And I should see "1 of 3 seats filled, 0 invitation(s) pending"

  # INV-001 (external audit of 1.20.37): the group page used to draw a
  # live Accept button straight off the :respond capability, without
  # ever asking gatekeeper::can_accept() - the same gate the landing
  # page and the leader's own pending-invites panel both ask. Driving
  # the roster past maxsize AFTER the invitation was issued - exactly
  # the audit's scenario ("the group becomes full ... after the
  # invite") - is the RED case: on the pre-fix tree this page still
  # showed Sam a live Accept button here, and pressing it would have
  # landed on the very refusal asserted below. Decline is pinned
  # separately and must survive: withdrawing from an offer the group
  # has outgrown is cleanup, not the join the gate refuses.
  Scenario: The group page withdraws Accept, not Decline, once the group has outgrown the invitation
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    Then I should see "1 of 3 seats filled, 1 invitation(s) pending"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should see "Accept" in the ".selfselectadvanced-respond" "css_element"
    And I should see "Decline" in the ".selfselectadvanced-respond" "css_element"
    # The roster changes after the invitation was sent - two more
    # confirmed members arrive directly, taking confirmed-plus-invited
    # past maxsize (3), which is what "the group becomes full" means to
    # the gate (gatekeeper::can_accept()'s own seat re-check).
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | student4 | Vic       | Four     | student4@example.com  |
      | student5 | Wes       | Five     | student5@example.com  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student4 | C1     | student |
      | student5 | C1     | student |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student4 | confirmed |
      | Team Blue | student5 | confirmed |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Team Blue"
    Then I should see "Decline" in the ".selfselectadvanced-respond" "css_element"
    And I should not see "Accept" in the ".selfselectadvanced-respond" "css_element"
    And I should see "No free seats: confirmed members and pending invitations hold every seat." in the ".selfselectadvanced-respond" "css_element"

  Scenario: Accepting one invitation auto-declines the others at the cap
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name       | leader   |
      | ssa1               | Team Green | student3 |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup   | user     | status  |
      | Team Green | student1 | invited |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    Then I should see "Team Green" in the ".selfselectadvanced-myinvitations" "css_element"
    When I click on "Accept" "button" in the "Team Blue" "list_item"
    Then I should see "You have joined the group \"Team Blue\"."
    When I am on the "Lab groups" "selfselectadvanced activity" page
    Then I should see "No pending invitations"
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student3
    And I follow "Team Green"
    Then I should see "Sam One" in the ".selfselectadvanced-pendinginvites" "css_element"
    And I should see "Declined" in the ".selfselectadvanced-pendinginvites" "css_element"

  # The purpose this scenario has always had: a leader can find a course
  # peer in the native selector and the invitation really reaches them.
  # It used to search by ADDRESS and was red on both engines from wave
  # 3D onwards, because candidates.php stopped matching addresses and
  # the option therefore no longer existed. The needle is now a name;
  # the assertions after it are unchanged, and the last one can only be
  # satisfied by an invitation that was actually created.
  @javascript
  Scenario: The leader finds an invitee by name in the native selector
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    And I set the field "Invite members" to "Uma Three"
    And I press "Send invitations"
    Then I should see "1 invitation(s) sent."
    And I should see "Uma Three" in the ".selfselectadvanced-pendinginvites" "css_element"

  # THE REMOVED ORACLE, TURNED INTO A PINNED PROPERTY. Nothing at
  # browser level stopped anybody putting `u.email` back into
  # candidates::search - the one feature that would have noticed was the
  # scenario above, and it REQUIRED the match. An oracle leaks without
  # rendering anything: submit a full address, get exactly one person
  # back, and the searcher has confirmed which named account owns that
  # address, one query at a time, where no review can see it. Both halves
  # are asserted - the promise the box makes (the placeholder) and the
  # answer the query gives - because a truthful label over a matching
  # query and a lying label over a names-only query are different
  # defects and only the pair pins the behaviour.
  @javascript
  Scenario: An email address finds nobody in the native selector
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    Then "//input[@placeholder='Search by name']" "xpath_element" should exist
    And "//input[@placeholder='Search by name or email']" "xpath_element" should not exist
    When I click on "//input[@placeholder='Search by name']" "xpath_element"
    And I type "student3@example.com"
    Then I should see "No suggestions"
    And I should not see "Uma Three"

  # THE ANONYMOUS-ZERO REGRESSION (maintainer's live report, 2026-08-06:
  # "The message says that the reason why a member could not be added is
  # given against the name, but it is not so"). Every ineligible pick
  # used to collapse into the same value 0, so the server could name
  # neither the candidate nor the reason and the refusal pointed back at
  # a list that may have been re-queried. The selector now keeps the
  # identity as a negated id, and the answer names both.
  @javascript
  Scenario: Selecting an annotated ineligible candidate is refused by name with the current reason
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   |
      | ssa1               | Team Gold | student1 |
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student2
    And I follow "Team Blue"
    # Sam leads Team Gold and maxmembership is 1, so the list annotates
    # them - and the pick must come back as "Sam One cannot be invited",
    # not as an anonymous complaint about the list.
    And I set the field "Invite members" to "Sam One"
    And I press "Send invitations"
    Then I should see "0 invitation(s) sent."
    And I should see "Sam One cannot be invited:"
