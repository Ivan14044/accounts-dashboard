## ADDED Requirements

### Requirement: Export audit
The system SHALL record each authenticated account-data export request with its login, IP, time, source table, format, scope, selected columns, actual row count, per-status counts, and outcome. It SHALL avoid storing exported values and credentials in the audit.

#### Scenario: CSV export
- **WHEN** a user requests a valid CSV export
- **THEN** an audit entry is created before any file data is returned
- **AND** the entry is finalized with the actual row and status counts

#### Scenario: TXT chunk export
- **WHEN** a valid TXT row chunk is returned
- **THEN** the chunk is recorded with the number and statuses of rows returned

#### Scenario: Action journal CSV export
- **WHEN** an authenticated user downloads the action journal as CSV
- **THEN** the request is recorded with its actual row count and source table

#### Scenario: Interrupted export
- **WHEN** export processing stops after an entry is created without successful completion
- **THEN** the entry remains distinguishable from a completed export

### Requirement: Export journal
The system SHALL provide authenticated users a paginated journal of export entries with filters for date, login, and IP.

#### Scenario: Review recent exports
- **WHEN** an authenticated user opens the export journal
- **THEN** recent exports display their time, login, IP, format, scope, row count, status counts, and outcome
