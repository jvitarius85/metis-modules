# Metis Workspace Instructions

## Performance Requirements

All code in Metis must treat performance and efficiency as core design requirements, not follow-up optimizations.

### Database Access

- Design schema changes with query patterns in mind.
- Add or update indexes for columns that are frequently filtered, joined, sorted, or used for cursor pagination.
- Prefer a small number of well-structured queries over repeated lookups inside loops.
- Use aggregated queries for dashboards, reports, counters, and summaries instead of multiple small queries.
- Paginate or use cursors for large result sets. Do not load full datasets into memory for list views, exports, or batch operations.
- Review query plans for new heavy queries and avoid full-table scans unless the dataset is known to be small and bounded.

### Caching

- Cache repeated calculations, dashboard statistics, and frequently accessed data when the source data does not change on every request.
- Use the application cache layer rather than relying on server-level tuning.
- Set explicit expiration or invalidation rules so cached data stays predictable.
- Consolidate duplicate reads behind shared services or helper functions instead of recalculating the same values in multiple places.

### Background Processing

- Keep normal page requests lightweight.
- Move expensive work out of synchronous request paths and into scheduled jobs or background workers.
- Report generation, bulk exports, bulk email, webhook processing, analytics calculations, and similar heavy tasks must not run inline with user-facing requests unless the workload is trivially small.
- If a request needs deferred work, enqueue it and return control to the user quickly.

### Application Design

- Minimize external service calls and internal module chatter.
- Batch, consolidate, or cache integration calls when results are stable.
- Avoid redundant logic and duplicated functions; prefer shared services with clear ownership.
- Be deliberate about memory usage, especially in loops, imports, exports, and report builders.
- Favor designs that keep Metis responsive and scalable without depending on server-level configuration changes.

### Implementation Standard

- New features should describe their performance-sensitive paths during implementation.
- Changes that introduce new tables, filters, dashboards, reports, or background jobs should include the indexing, pagination, caching, or queueing strategy as part of the change itself.
- If a simpler implementation would create repeated scans, repeated service calls, or heavy synchronous work, choose the more efficient design before merging.
