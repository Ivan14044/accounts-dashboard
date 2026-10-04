## Implementation
- [x] Add a database-backed export audit with safe metadata and status counts.
- [x] Record classic CSV/TXT exports before streaming and finalize with actual row counts.
- [x] Record each TXT chunk after fetching rows and before returning JSON.
- [x] Add a paginated journal with date, login and IP filters, linked from the existing action journal.
- [x] Verify syntax and behavior using available local tooling; document deployment and attribution limits.
