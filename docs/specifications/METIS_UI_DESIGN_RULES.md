# METIS UI DESIGN RULES
Version: 1.1

Defines the official Metis UI contract.

## Core Philosophy

Metis UI must be:
- clean
- modern
- readable
- predictable
- easy to understand

## Workflow Rule

Multiple-screen workflows are allowed and encouraged when they simplify the user experience.

Prefer:
- step-by-step flows
- staged configuration
- sequential task screens

Do not force complex workflows into a single overloaded page.

## Information Clarity

The UI must avoid:
- conflicting information
- inconsistent terminology
- unclear next steps
- duplicated information presented differently

## Modal Standard

Use the central modal framework only.

Modals are allowed for:
- confirmations
- short forms
- quick edits
- small configuration tasks

Do not use modals for:
- multi-step workflows
- long forms
- dense configuration screens

## Notification Standard

System confirmations and alerts use toast notifications only.

Allowed:
- toast success
- toast warning
- toast info
- non-blocking toast error

Not allowed:
- inline alert boxes
- banners
- page alerts
- browser alert dialogs

Form validation errors are the only inline errors allowed, and they must appear at field level.

## Time Format Standard

All UI time values must display in:

`hh:mm:ss am/pm`

Examples:
- `02:15:30 pm`
- `11:05:10 am`

## Action Button Standard

Primary action:
- rightmost
- clear label
- strongest emphasis

Secondary action:
- left of primary
- neutral styling

Destructive action:
- danger styling
- explicit confirmation
- never disguised as a normal primary action

## Accessibility

UI must support:
- keyboard navigation
- readable contrast
- semantic labels
- screen reader compatibility where practical
