# METIS CORE SERVICES
Version: 1.0

Defines the authoritative core service layer.

All shared platform behavior must use these services before any new service is created.

## Core Service Registry

### Backend
- `Router`
- `ModuleLoader`
- `SecureEnclave`
- `ActionDispatcher`
- `CronKernel`
- `ShellKernel`
- `ConfigService`
- `LogService`

### Frontend-facing
- `AjaxService`
- `ModalService`
- `ToastService`
- `FormService`
- `NavigationService`

## Rules

- services must be module-agnostic
- services must not duplicate each other
- modules must use existing services where available
- new core services require owner approval
