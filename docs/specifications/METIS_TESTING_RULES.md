# METIS TESTING RULES
Version: 1.0

Defines testing expectations.

## Test Areas

- core tests
- module tests
- integration tests

## Structure

```text
tests/
    core/
    modules/<module>/
```

## Rules

- tests must not modify production data
- new critical features should include tests
- security-sensitive behavior should be covered by tests where practical
