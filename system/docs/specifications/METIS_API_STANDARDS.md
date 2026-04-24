# METIS API STANDARDS
Version: 1.0

Defines API and JSON behavior.

## Success Response

```json
{
  "status": "success",
  "message": "Operation completed",
  "data": {}
}
```

## Error Response

```json
{
  "status": "error",
  "message": "Operation failed",
  "errors": {}
}
```

## List Responses

Where list endpoints exist, support:
- `page`
- `limit`
- query filtering where appropriate

## Rules

- use predictable JSON
- avoid raw HTML responses for API-like endpoints
- keep messages clear and non-conflicting
