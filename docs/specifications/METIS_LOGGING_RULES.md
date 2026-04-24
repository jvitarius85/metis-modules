# METIS LOGGING RULES
Version: 1.0

Defines logging rules for Metis.

## Log Location

`storage/logs`

## Recommended Log Files

- `system.log`
- `security.log`
- `cron.log`
- `webhook.log`

## Events That Must Be Logged

- authentication attempts
- permission denials
- cron executions
- webhook activity
- critical system exceptions

Sensitive secrets must never be logged.
