@local @local_batchanalytics
Feature: Accessing the Batch Analytics Dashboard
  In order to review batch operations
  As a manager or program manager
  I need to view the Batch Analytics dashboard

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | pmuser   | Program   | Manager  | pm@example.com       |
      | student1 | Learner   | One      | student@example.com  |
    And the following "roles" exist:
      | shortname | name            | archetype |
      | pmrole    | Program Manager | manager   |
    And the following "system role assigns" exist:
      | user   | role   | contextlevel |
      | pmuser | pmrole | System       |

  @javascript
  Scenario: Assigned PM can access Batch Analytics dashboard
    When I log in as "pmuser"
    And I navigate to "local/batchanalytics/index.php"
    Then I should see "Batch Analytics"
    And I should see "Current Running Batches"
    And I should not see "Exception"
