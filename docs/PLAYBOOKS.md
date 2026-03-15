# Hermes Playbooks

Hermes playbooks define structured capabilities that the Hermes intelligence layer
can execute within the Métis platform.

Playbooks allow Hermes to perform complex tasks safely by routing requests
through the platform service layer instead of executing uncontrolled logic.

Each playbook defines:

- capability name
- allowed operations
- required permissions
- service endpoints
- audit logging behavior

## Example Playbook Structure

```
playbook:
  name: user.create
  permissions: admin.users.create
  services:
    - peopleService.createUser()
  audit: true
```

## Capability Domains

Hermes playbooks are typically organized by domain.

### Administrative Operations

Capabilities include:

- create users
- modify roles
- grant or revoke permissions
- provision system access

Example:

```
Create a new staff user with Drive access
```

### Data Queries

Hermes can interpret operational questions and retrieve structured information.

Example:

```
List all board members who have permission to create users
```

### Reporting

Playbooks can generate reports using system data.

Examples:

- monthly donations summary
- volunteer activity report
- board meeting attendance

### Workflow Automation

Playbooks may automate routine operations.

Examples:

- scheduled report generation
- reminder notifications
- onboarding workflows

## Execution Safety

All Hermes playbooks must:

- respect role-based permissions
- log actions for auditing
- use platform services
- avoid direct database manipulation

This ensures Hermes enhances operations without bypassing governance controls.
