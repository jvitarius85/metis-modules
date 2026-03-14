# Finance

Finance operations dashboard for deposits, settlement activity, campaign performance, ledger activity, reconciliations, and reporting.

## Routes

- Base route: `/finance`
- `/finance/finance` -> `finance.php`
- `/finance/ledger` -> `ledger.php`
- `/finance/reconciliations` -> `reconciliations.php`
- `/finance/reports` -> `reports.php`

## UI Components

- **Finance** template: `finance.php`
- **Ledger** template: `ledger.php`
- **Reconciliations** template: `reconciliations.php`
- **Reports** template: `reports.php`

## APIs

- No dedicated AJAX controller was discovered for this module.

## Database Tables Used

- `campaigns` (`metis_campaigns`)
- `deposits` (`metis_deposits`)
- `finance_accounts` (`metis_finance_accounts`)
- `finance_event_tags` (`metis_finance_event_tags`)
- `finance_events` (`metis_finance_events`)
- `finance_funds` (`metis_finance_funds`)
- `finance_ledger` (`metis_finance_ledger`)
- `finance_reconciliations` (`metis_finance_reconciliations`)
- `finance_tags` (`metis_finance_tags`)
- `transaction_refunds` (`metis_transaction_refunds`)
- `transactions` (`metis_transactions`)

## Assets and Extension Hooks

- CSS: `finance.css`
- JS: `finance.js`
- Registered help topics: `finance.finance`, `finance.ledger`, `finance.reconciliations`, `finance.reports`
