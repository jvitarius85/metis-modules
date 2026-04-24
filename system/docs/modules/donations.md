# Donations

Track donors, gifts, deposits, and fundraising activity.

## Routes

- Base route: `/donations`
- `/donations/dashboard` -> `dashboard.php`
- `/donations/donors` -> `donors.php`
- `/donations/donor` -> `donor.php`
- `/donations/batch` -> `batch-detail.php`
- `/donations/transactions` -> `transactions.php`
- `/donations/transaction` -> `transaction.php`
- `/donations/deposits` -> `deposits.php`
- `/donations/deposit` -> `deposit.php`
- `/donations/campaigns` -> `campaigns.php`
- `/donations/campaign` -> `campaign.php`
- `/donations/reports` -> `reports.php`

## UI Components

- **Dashboard** template: `dashboard.php`
- **Donors** template: `donors.php`
- **Donor** template: `donor.php`
- **Batch** template: `batch-detail.php`
- **Transactions** template: `transactions.php`
- **Transaction** template: `transaction.php`
- **Deposits** template: `deposits.php`
- **Deposit** template: `deposit.php`
- **Campaigns** template: `campaigns.php`
- **Campaign** template: `campaign.php`
- **Reports** template: `reports.php`

## APIs

- No dedicated AJAX controller was discovered for this module.

## Database Tables Used

- `batch_audit` (`metis_batch_audit`)
- `batch_notes` (`metis_batch_notes`)
- `batches` (`metis_batches`)
- `campaigns` (`metis_campaigns`)
- `contacts` (`metis_contacts`)
- `deposits` (`metis_deposits`)
- `entity_registry` (`metis_entity_registry`)
- `transactions` (`metis_transactions`)

## Assets and Extension Hooks

- CSS: `donations.css`
- JS: `donations.js`
- Registered help topics: `donations.dashboard`, `donations.donors`, `donations.transactions`, `donations.deposits`, `donations.reports`, `donations.campaigns`
