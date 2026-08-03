@mod @mod_selfselectadvanced
Feature: Finding a guide by the detail the person actually has
  In order to reach the faculty member who agreed to guide them
  As a student, and as a coordinator raising a cap
  I find a guide by employee id or email address, and never see an address

  Background:
    Given the following "users" exist:
      | username | firstname | lastname  | email                         |
      | student1 | Sam       | One       | s1@example.com                |
      | guide1   | Anita     | 21BCE1234 | anita.raman@guidemail.invalid |
      | guide2   | Bala      | Krishnan  | bala.k@othermail.invalid      |
      | teacher1 | Tina      | Teach     | teach1@example.com            |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | guide1   | C1     | teacher        |
      | guide2   | C1     | teacher        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership | maxguided | studentapproach |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 2             | 1         | 0               |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name    | leader   | state   |
      | ssa1               | Seekers | student1 | forming |

  @javascript
  Scenario: A student finds their guide by email address and is shown no address
    # The VIT journey: the student has the address they were given in
    # person and nothing else. 'anita.raman' is a substring of neither
    # guide's name, so this step can only succeed through the address
    # arm - before it existed, behat_form_autocomplete threw "Unable to
    # find ... in the list of options".
    #
    # THE WHOLE ADDRESS IS TYPED ON PURPOSE. Since maintainer decision
    # 41 the address arm engages only for a query containing '@';
    # shortening this to the local part would make the step fail, and
    # correctly so. The rule itself is pinned by
    # guidepickeraddress_test::test_the_address_arm_engages_only_on_a_query_with_an_at().
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Seekers"
    And I set the field "Choose a guide" to "anita.raman@guidemail.invalid"
    Then I should see "Anita 21BCE1234"
    # MATCHING IS NOT DISPLAYING - the browser-level half of the rule.
    And I should not see "guidemail.invalid"
    And I should not see "anita.raman"

  @javascript
  Scenario: The employee id recorded as a surname still finds them
    When I am on the "Lab groups" "selfselectadvanced activity" page logged in as student1
    And I follow "Seekers"
    And I set the field "Choose a guide" to "21BCE1234"
    Then I should see "Anita 21BCE1234"

  @javascript
  Scenario: A coordinator can grant more capacity to a guide who is already full
    # maxguided is 1 and guide1 already guides a firm team, so guide1
    # has no room. Every assignment picker is right to omit them; the
    # OVERRIDE picker used to omit them too, which made the one guide a
    # coordinator opens this page for the one guide they could not
    # choose.
    Given the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | guide  | state |
      | ssa1               | Team Full | student1 | guide1 | firm  |
    When I am on the "Lab groups" "mod_selfselectadvanced > overrides" page logged in as teacher1
    And I follow "Guide overrides"
    And I follow "Add override"
    And I set the field "Guide" to "Anita"
    And I set the field "Maximum groups per guide" to "3"
    And I press "Save changes"
    Then I should see "Override saved."
    And I should see "Anita 21BCE1234"
    And I should not see "guidemail.invalid"
