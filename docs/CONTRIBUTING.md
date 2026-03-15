# Contributing to Métis

Métis is a modular operational platform. Contributions should follow
the architectural standards defined within the project.

## Development Guidelines

### Avoid Duplicate Logic

Shared functionality should be implemented within the core services layer.

### Use Core Services

Modules should interact with shared services whenever possible.

Examples:

- authentication
- permissions
- API services
- logging
- caching

### Modular Structure

Example:

```
module/
├── controller
├── service
├── api
├── ui
```

Large components should be broken into manageable units.

## Error Handling

Errors should be handled by the centralized router or error service
and return proper HTTP status codes.

## UI Consistency

Frontend development should follow shared design standards:

- shared core CSS
- responsive components
- accessibility compatibility

## Performance

Developers should prioritize:

- efficient database queries
- caching
- asynchronous background tasks
- minimal external API calls
