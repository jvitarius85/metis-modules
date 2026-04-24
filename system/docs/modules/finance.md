# Finance

Finance V2 foundation with scheduled org mode switching.

## Routes

- Base route: `/finance`
- `/finance/snapshot` -> `finance.php`
- `/finance/gl_entry` -> `finance.php`
- `/finance/reconciliation` -> `finance.php`
- `/finance/budget` -> `finance.php`
- `/finance/invoicing` -> `finance.php`
- `/finance/reports` -> `finance.php`
- `/finance/settings` -> `finance.php`
- `/finance/stripe_clearing` -> `finance.php`

## UI Components

- **Snapshot** template: `finance.php`
- **Gl Entry** template: `finance.php`
- **Reconciliation** template: `finance.php`
- **Budget** template: `finance.php`
- **Invoicing** template: `finance.php`
- **Reports** template: `finance.php`
- **Settings** template: `finance.php`
- **Stripe Clearing** template: `finance.php`

## APIs

- Dedicated AJAX controller: `finance.ajax.php`

## Database Tables Used

- `deposits` (`metis_deposits`)
- `finance_v2_accounts` (`metis_finance_accounts`)
- `finance_v2_bank_lines` (`metis_finance_bank_lines`)
- `finance_v2_budget_lines` (`metis_finance_budget_lines`)
- `finance_v2_budget_versions` (`metis_finance_budget_versions`)
- `finance_v2_categories` (`metis_finance_categories`)
- `finance_v2_fiscal_periods` (`metis_finance_fiscal_periods`)
- `finance_v2_fiscal_settings` (`metis_finance_fiscal_settings`)
- `finance_v2_gl_entries` (`metis_finance_gl_entries`)
- `finance_v2_invoice_lines` (`metis_finance_invoice_lines`)
- `finance_v2_invoices` (`metis_finance_invoices`)
- `finance_v2_kpi_cache` (`metis_finance_kpi_cache`)
- `finance_v2_mode_switch_jobs` (`metis_finance_mode_switch_jobs`)
- `finance_v2_org_mode` (`metis_finance_org_mode`)
- `finance_v2_recon_column_mappings` (`metis_finance_recon_column_mappings`)
- `finance_v2_recon_matches` (`metis_finance_recon_matches`)
- `finance_v2_recon_month_audit` (`metis_finance_recon_month_audit`)
- `finance_v2_recon_month_items` (`metis_finance_recon_month_items`)
- `finance_v2_recon_months` (`metis_finance_recon_months`)
- `finance_v2_recon_parse_runs` (`metis_finance_recon_parse_runs`)
- `finance_v2_recon_review_queue` (`metis_finance_recon_review_queue`)
- `finance_v2_report_requests` (`metis_finance_report_requests`)
- `finance_v2_stripe_clearing_events` (`metis_finance_stripe_clearing_events`)
- `finance_v2_stripe_payouts` (`metis_finance_stripe_payouts`)
- `people` (`metis_people`)
- `people_roles` (`metis_people_roles`)
- `transaction_refunds` (`metis_transaction_refunds`)
- `transactions` (`metis_transactions`)

## Assets and Extension Hooks

- CSS: `finance.css`
- JS: `finance.js`
