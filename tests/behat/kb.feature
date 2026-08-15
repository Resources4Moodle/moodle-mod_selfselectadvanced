@mod @mod_selfselectadvanced
Feature: The knowledgebank grows out of resolved tickets
  In order to answer common questions without repeating the queue's work
  As staff and as a student
  I publish a resolved ticket as an FAQ, add articles directly, and see
  matching articles offered before I file a new ticket

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email               |
      | student1 | Sam       | One         | s1@example.com      |
      | student2 | Sara      | Two         | s2@example.com      |
      | teacher1 | Tina      | Teach       | teach1@example.com  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity           | course | name       | idnumber | minsize | maxlead | maxmembership |
      | selfselectadvanced | C1     | Lab groups | ssa1     | 1       | 1       | 2             |
    And the following "mod_selfselectadvanced > groups" exist:
      | selfselectadvanced | name      | leader   | state | timeapproved  |
      | ssa1                | Team Blue | student1 | firm  | ##yesterday## |
    And the following "mod_selfselectadvanced > members" exist:
      | ssagroup  | user     | status    |
      | Team Blue | student2 | confirmed |

  Scenario: Resolve a ticket, publish it as an FAQ, edit the wording, and find it in the student list
    # A confirmed MEMBER files "Leadership help" - the same journey
    # ticket_thread.feature's own first scenario drives, up to Resolve.
    When I am on the "Lab groups > Team Blue" "mod_selfselectadvanced > group" page logged in as student2
    And I set the field "Ask for leadership help (say what the group needs):" to "Our leader has gone quiet"
    And I press "File request"
    And I am on the "Lab groups" "mod_selfselectadvanced > tickets" page logged in as teacher1
    And I press "Take up"
    And I follow "Open thread"
    And I set the field "Resolution note" to "A successor was appointed and the team is settled again."
    And I set the field "Publish as FAQ" to "1"
    And I press "Resolve"
    # Publishing is a SECOND deliberate step: this lands on the pre-filled
    # DRAFT form, not straight back on the thread.
    Then I should see "Publish as FAQ"
    And the field "Title" matches value "Leadership help"
    And I set the field "Title" to "What happens if our leader goes quiet?"
    And I set the field "Answer" to "Ask the coordinators for leadership help from your group page; they will appoint a successor."
    And I press "Save"
    Then I should see "The article has been saved."

    # The published article shows up for a student browsing the
    # knowledgebank - anonymised: no mention of Sara (the requester) or
    # Team Blue. Not checked for "Sam One" here: the VIEWER is student1
    # (Sam One), and a logged-in user's own name is naturally on the
    # page chrome (user menu) regardless of anything this feature tests.
    When I am on the "Lab groups" "mod_selfselectadvanced > kb" page logged in as student1
    Then I should see "What happens if our leader goes quiet?"
    And I should see "Ask the coordinators for leadership help"
    And I should not see "Sara Two"
    And I should not see "Team Blue"

  Scenario: Adding an article directly, with no ticket behind it
    When I am on the "Lab groups" "mod_selfselectadvanced > kb" page logged in as teacher1
    And I follow "Add an article"
    Then I should see "Add an article"
    And I set the field "Title" to "How do groups get frozen?"
    And I set the field "Question" to "What does freezing a group mean?"
    And I set the field "Answer" to "A frozen group's roster is locked; only a coordinator can change it from there."
    And I press "Save"
    Then I should see "The article has been saved."

    When I am on the "Lab groups" "mod_selfselectadvanced > kb" page logged in as student1
    Then I should see "How do groups get frozen?"

  Scenario: The filing screen offers a matching article before a new ticket is raised, and "continue anyway" still files
    Given I am on the "Lab groups" "mod_selfselectadvanced > kb" page logged in as teacher1
    And I follow "Add an article"
    And I set the field "Title" to "Can I ask the managers for general help?"
    And I set the field "Question" to "Who do I contact for a general question?"
    And I set the field "Answer" to "Use the general help request from the landing page or your group page."
    And I set the field "Type" to "General help"
    And I press "Save"

    When I am on the "Lab groups" "mod_selfselectadvanced > file help" page logged in as student1
    Then I should see "These may answer your question"
    And I should see "Can I ask the managers for general help?"
    # NO forced block: the form is not on this screen yet - checked
    # against the intro paragraph the form branch alone renders (the
    # page heading and the textarea's own label both reuse the SAME
    # "Ask the managers..." string, so that text is on the page either
    # way and cannot tell the two states apart).
    And I should not see "Have a question or an issue"
    When I follow "My question is different - continue"
    Then I should see "Have a question or an issue"
    And I set the field "Ask the managers and coordinators for help" to "I still have a different question entirely."
    And I press "File request"
    Then I should see "Your request has been queued for the managers and coordinators."

  # @javascript is load-bearing, not decoration (same gotcha
  # contactprivacy.feature documents for its own advcheckbox scenario).
  # Without it Behat drives the non-JavaScript BrowserKit driver, where
  # an UNCHECKED advcheckbox submits nothing at all - kb_form.php's
  # 'published' checkbox would fall back to its own default of 1 and
  # the entry would publish anyway, which is exactly the state this
  # scenario exists to rule out.
  @javascript
  Scenario: An unpublished entry stays on the staff list but is invisible to a student
    Given I am on the "Lab groups" "mod_selfselectadvanced > kb" page logged in as teacher1
    And I follow "Add an article"
    And I set the field "Title" to "A draft nobody should see yet"
    And I set the field "Question" to "Is this ready?"
    And I set the field "Answer" to "Not yet - still being drafted."
    And I set the field "Published" to "0"
    And I press "Save"
    Then I should see "A draft nobody should see yet"

    When I am on the "Lab groups" "mod_selfselectadvanced > kb" page logged in as student1
    Then I should not see "A draft nobody should see yet"

    When I am on the "Lab groups" "mod_selfselectadvanced > kb" page logged in as teacher1
    Then I should see "A draft nobody should see yet"
