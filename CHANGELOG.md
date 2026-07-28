# Changelog

All notable changes to this project are documented in this file.

## [0.1.3] - 2026-07-28

### Fixed

- Removed deprecated `ReflectionProperty::setAccessible()` calls for PHP 8.5 compatibility.
- Persist updates when the same entity object is saved more than once.
- Persist required One-to-Many and Many-to-One foreign keys before inserting child entities.
- Flush an already-persisted related entity when a later relation injects another foreign key.
- Restore generated IDs and injected foreign keys when a transaction is rolled back.
- Use portable pivot-table writes on SQLite, MySQL/MariaDB, and PostgreSQL.
- Avoid pivot-table check-then-insert races while only suppressing duplicate-key errors.
- Respect entity-specific `pivotTables` mappings when saving Many-to-Many relations.
- Share driver-specific identifier quoting and pivot-table resolution across persistence and repositories.
- Support repository queries with an offset and no explicit limit.
- Avoid duplicate inserts in `findOrCreateManyBy()` when input values repeat.
- Corrected the README examples, requirements, and Markdown formatting.

### Tests

- Added regression coverage for repeated saves, required foreign keys, cyclic relation graphs,
  rollback recovery, late foreign-key injection, pivot constraint failures, offset-only queries,
  and duplicate bulk input.
- Added PHPStan analysis for both production and test code.
