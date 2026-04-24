# METIS PERFORMANCE RULES
Version: 1.0

Defines performance expectations.

## Query Rules

- use indexes
- avoid full table scans
- return only needed columns
- avoid `SELECT *`

## Background Work

Long-running tasks must use background or scheduled execution through CronKernel.

Examples:
- imports
- batch emails
- report generation

## Asset Rules

- avoid duplicate libraries
- keep JS/CSS lean
- reuse core services instead of shipping duplicate code
