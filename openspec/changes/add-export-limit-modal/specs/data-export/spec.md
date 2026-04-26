## ADDED Requirements
### Requirement: Limit Export by Custom Count
The system SHALL allow users to specify an exact number of records to export from the currently filtered dataset.

#### Scenario: User exports exact number of records
- **WHEN** user requests to export data and specifies a count limit of "50"
- **THEN** the system generates a file containing exactly the first 50 records matching the current filters and sort order

#### Scenario: Custom count exceeds available records
- **WHEN** user requests to export 500 records but only 300 match the filter
- **THEN** the system generates a file containing the 300 available records
