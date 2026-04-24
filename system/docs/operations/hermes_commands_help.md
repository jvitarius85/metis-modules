# Hermes Command Help

This is the current phrase guide for Hermes commands.

## Secure Commands (Approval Required)

| You can say | Hermes command |
|---|---|
| `run a backup` | Run System Backup |
| `sync drive` | Sync Drive |
| `sync calendar` | Sync Calendar |
| `clear cache` | Clear Cache |
| `create user Riley Vitarius riley@example.com` | Create User |
| `offboard user Riley Vitarius` | Offboard User |
| `add JD Vitarius to board role` | Manage User Roles |
| `add Codex to workspace group staff@workspace.org` | Manage Workspace Groups |
| `reset workspace password for Codex` | Reset Workspace Password |
| `reset metis password for Codex` | Reset Metis Password |
| `reset password for Codex` | Clarify Password Reset Target |
| `reset codex's mfa` | Reset User MFA |
| `disable mfa for Codex` | Reset User MFA |
| `revoke passkeys for Codex` | Reset User MFA |
| `update JD's phone to 254-677-0337` | Update Contact |
| `set JD's birthday to 06/10/1985` | Update Contact |
| `add Meg Wallace to newsletter list Volunteers` | Update Contact List Membership |
| `link drive folder for JD Vitarius` | Link Drive Folder |
| `create post titled "Quarterly Update"` | Create Post |
| `publish post "Quarterly Update"` | Publish Post |
| `install update` | Install Update |
| `run self-heal` | Run Self-Heal |

## Read Commands (No Approval Required)

| You can say | Hermes command |
|---|---|
| `what is JD's email?` | Entity Attribute Lookup |
| `what is JD's phone number?` | Entity Attribute Lookup |
| `what is JD's address?` | Entity Attribute Lookup |
| `is JD a volunteer?` | Entity Attribute Lookup |
| `whose email is this? jd@vitarius.org` | Reverse Identifier Lookup |
| `whose number is this 254-677-0337` | Reverse Identifier Lookup |
| `what is JD's contact info?` | Profile Lookup |
| `what newsletters is JD registered for?` | Profile Lookup (contact-biased) |
| `how much has Meg Wallace donated this year?` | Profile Lookup (donor-biased) |
| `how much money have we raised this year?` | Giving Summary |
| `what permissions does JD have?` | Diagnose Permissions |
| `who can create users?` | Capability Actor Lookup |
| `check for updates` | Update Check |

## Password Reveal Flow

1. Approve the password reset action.
2. Hermes returns `Credential package hidden`.
3. Click `Reveal once` to view the generated password.
4. The reveal token expires after 10 minutes and can only be viewed once by the approving operator.
