# METIS FRONTEND ARCHITECTURE
Version: 1.0

Defines frontend architecture for Metis.

## Asset Locations

Global:
- `assets/js`
- `assets/css`

Module-specific:
- `modules/<module>/assets/js`
- `modules/<module>/assets/css`

## Frontend Service Model

Core JS services live in:
- `assets/js/core/ajax.js`
- `assets/js/core/modal.js`
- `assets/js/core/toast.js`
- `assets/js/core/forms.js`
- `assets/js/core/navigation.js`

Modules must use these services and must not recreate them.

## AJAX Contract

All AJAX must go through the normalized `/api/ajax` route.

On subdirectory installs, the public URL inherits the site base path. Example:

- `/metis/api/ajax`

The direct service wrapper remains `system/ajax.php`, but frontend callers should use the routed `/api/ajax` path.

Standard response:
```json
{
  "status": "success",
  "message": "Contact saved",
  "data": {}
}
```

Error response:
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {}
}
```

## Frontend Rules

- no inline scripts
- no inline styles
- no custom modal framework
- no custom toast framework
- no alternate form submission system
