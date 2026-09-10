# dashboard-table Specification

## Purpose
Preserve complete account data and editing state while rendering and refreshing large account tables efficiently.

## Requirements

### Requirement: Consistent virtualization policy
The table SHALL apply one virtualization eligibility policy for initial HTML and AJAX updates and preserve editing, selection and scrolling across density modes.

#### Scenario: Refreshing the same result set
- **WHEN** the same rows and page size are rendered initially and after refresh
- **THEN** virtualization eligibility is identical
- **AND** listeners do not accumulate after repeated updates

### Requirement: Responsive table workspace
The table workspace SHALL remain usable from 360 to 1920 CSS pixels, with intentional table scrolling contained within its own container.

#### Scenario: Narrow screen
- **WHEN** a user opens dashboard, favorites or trash at 360 CSS pixels
- **THEN** controls remain accessible and readable without unintended page-wide horizontal scrolling
- **AND** table columns remain reachable through the table scroll container
