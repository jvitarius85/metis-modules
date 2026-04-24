# METIS ERROR HANDLING
Version: 1.0

Defines error handling behavior across Metis.

## AJAX / API Errors

AJAX and API errors must return standard JSON.

Example:
```json
{
  "status": "error",
  "message": "Unexpected error occurred",
  "errors": {}
}
```

## UI Error Rules

- system feedback → toast
- form validation feedback → inline field errors only

## Production Safety

Production must not expose stack traces or internal exception details.

## Logging

Critical errors must be logged to `storage/logs`.
