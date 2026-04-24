# METIS CONFIGURATION RULES
Version: 1.0

Defines configuration handling.

## Rules

- configuration belongs in `config/`
- secrets must not be hard-coded
- environment values load through configuration services
- feature flags and API keys must use configuration, not code literals

## Module Config

Modules may keep module-specific config in:

`modules/<module>/config/`
