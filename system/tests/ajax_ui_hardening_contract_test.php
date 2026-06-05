<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$system = dirname( __DIR__ );
$root = dirname( $system );
$failures = [];
$rawSqlPattern = '/\bSELECT\s+.+\bFROM\b|\bINSERT\s+INTO\b|\bUPDATE\s+\S+\s+SET\b|\bDELETE\s+FROM\b/us';

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $system ): string {
    $contents = @file_get_contents( $system . '/' . ltrim( $relative, '/\\' ) );
    return is_string( $contents ) ? $contents : '';
};

$responseRuntime = $read( 'src/Metis/Core/Runtime/ResponseRuntime.php' );
$coreJs = $read( 'assets/core.js' );
$coreCss = $read( 'assets/core.css' );
$formsJs = $read( 'modules/forms/assets/forms.js' );
$formsRenderer = $read( 'src/Metis/Modules/Forms/FormRenderer.php' );
$websiteThemeView = $read( 'modules/website/views/theme.php' );
$newsletterJs = $read( 'modules/newsletter/assets/newsletter.js' );
$simpleEditorJs = $read( 'assets/js/editor/simple-editor.js' );
$depositsView = $read( 'modules/donations/views/deposits.php' );
$campaignView = $read( 'modules/donations/views/campaign.php' );
$formsRepository = $read( 'src/Metis/Modules/Forms/Concerns/SharedRepositoryLogic.php' );
$sanitizationRuntime = $read( 'src/Metis/Core/Runtime/SanitizationRuntime.php' );
$donationsCampaignService = $read( 'src/Metis/Modules/Donations/CampaignService.php' );
$donationsReadService = $read( 'src/Metis/Modules/Donations/ReadService.php' );
$peopleReadService = $read( 'src/Metis/Modules/People/ReadService.php' );
$newsletterReadService = $read( 'src/Metis/Modules/Newsletter/ReadService.php' );
$governanceChecker = $read( '../tools/governance/check-ajax-ui-hardening.php' );
$grandyStashAjax = $read( 'modules/grandys_stash/assets/grandys_stash.ajax.php' );
$contactsRelationshipsAjax = $read( 'modules/contacts/ajax/relationships.ajax.php' );
$peopleTemplatesAjax = $read( 'modules/people/ajax/templates.ajax.php' );
$hermesAjax = $read( 'modules/hermes/assets/hermes.ajax.php' );
$boardAjax = $read( 'modules/board/assets/board.ajax.php' );
$boardBylawsService = $read( 'src/Metis/Modules/Board/BylawsService.php' );
$boardDecisionAttendanceService = $read( 'src/Metis/Modules/Board/DecisionAttendanceService.php' );
$boardWorkflowTemplateService = $read( 'src/Metis/Modules/Board/WorkflowTemplateService.php' );
$financeAjax = $read( 'modules/finance/assets/finance.ajax.php' );
$financeService = $read( 'src/Metis/Modules/Finance/FinanceV2Service.php' );
$donationsNotesAjax = $read( 'modules/donations/assets/notes.ajax.php' );
$donationsTransactionMutationService = $read( 'src/Metis/Modules/Donations/TransactionMutationService.php' );
$donationsBootstrap = $read( 'modules/donations/bootstrap.php' );
$donationsModule = $read( 'src/Metis/Modules/Donations/DonationsModule.php' );
$helpService = $read( 'src/Metis/Core/HelpService.php' );
$helpSearchStore = $read( 'src/Metis/Core/HelpSearchStore.php' );
$helpArticleSave = $read( 'enclave/help/article/save.php' );
$contactMutationService = $read( 'src/Metis/Modules/Contacts/ContactMutationService.php' );
$contactsAjax = $read( 'modules/contacts/ajax/contacts.ajax.php' );
$recurringDonationsService = $read( 'src/Metis/Modules/Donations/RecurringDonationsService.php' );
$websiteAjax = $read( 'modules/website/ajax/website.ajax.php' );
$revisionTimelineService = $read( 'src/Metis/Modules/Website/Services/RevisionTimelineService.php' );

$assert( str_contains( $responseRuntime, 'function metis_runtime_send_json_success' ), 'Response runtime must expose the canonical JSON success helper.' );
$assert( str_contains( $sanitizationRuntime, 'function metis_text_raw_clean' ) && str_contains( $sanitizationRuntime, 'metis_runtime_normalize_text_encoding' ), 'Sanitization runtime must expose canonical Unicode-preserving text normalization helpers.' );
$assert( str_contains( $responseRuntime, "'message' => \$message" ), 'Structured JSON responses must include message.' );
$assert( str_contains( $responseRuntime, "'data'    => \$data" ) || str_contains( $responseRuntime, "'data'    => \$payload" ), 'Structured JSON responses must include data.' );
$assert( str_contains( $responseRuntime, "'errors'  => []" ) && str_contains( $responseRuntime, "'success' => true" ), 'Structured JSON success responses must include errors and success fields.' );
$assert( str_contains( $responseRuntime, "'request_id' => \$request_id !== '' ? \$request_id : null" ), 'Structured JSON responses must include request_id.' );

$assert( str_contains( $coreJs, 'Metis.ui.ajax = Metis.ajax;' ), 'Core UI runtime must expose Metis.ui.ajax alias.' );
$assert( str_contains( $coreJs, 'Metis.ui.toast = Metis.toast;' ), 'Core UI runtime must expose Metis.ui.toast alias.' );
$assert( str_contains( $coreJs, 'Metis.ui.modal = Metis.modal;' ), 'Core UI runtime must expose Metis.ui.modal alias.' );
$assert( str_contains( $coreJs, 'Metis.ui.confirm = Metis.confirm;' ), 'Core UI runtime must expose Metis.ui.confirm alias.' );
$assert( str_contains( $coreJs, 'Metis.ui.loading = (function() {' ), 'Core UI runtime must expose Metis.ui.loading helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.form = (function() {' ), 'Core UI runtime must expose Metis.ui.form helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.select = (function() {' ), 'Core UI runtime must expose Metis.ui.select helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.dropdown = {' ), 'Core UI runtime must expose Metis.ui.dropdown helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.modal.confirm = function(options)' ), 'Core UI runtime must expose Metis.ui.modal.confirm helper.' );
$assert( str_contains( $coreJs, 'Metis.ui.modal.form = function(target)' ), 'Core UI runtime must expose Metis.ui.modal.form helper.' );
$assert( ! str_contains( $coreCss, '.metis-theme-selectx' ) && ! str_contains( $coreCss, '.metis-ui-selectx' ), 'Shared core styles must not retain legacy selectx systems.' );

$assert( str_contains( $formsRepository, 'CampaignService::getActiveCampaignOptions()' ), 'Form Builder campaign options must route through the canonical campaign service.' );
$assert( str_contains( $donationsCampaignService, 'getActiveCampaignOptions' ), 'Donations campaign service must expose active campaign options.' );
$assert( str_contains( $formsJs, 'Metis.ui.ajax.post' ), 'Form Builder admin requests must delegate through Metis.ui.ajax.post.' );
$assert( str_contains( $formsJs, 'Metis.ui.form.setSubmitting' ), 'Form Builder public submit state must delegate through Metis.ui.form.' );
$assert( str_contains( $formsJs, 'metisRoot.forms.initPublicEmbeds = initPublicEmbeds;' ) && str_contains( $formsJs, "root.dataset.metisFormsPublicInit = '1';" ), 'Public forms runtime must expose idempotent embed initialization.' );
$assert( str_contains( $formsJs, 'findAdjacentPublicBoot(root)' ) && str_contains( $formsJs, 'hideSuccessOverlay(successOverlay);' ), 'Public forms runtime must resolve scoped boot data and close the confirmation modal through the governed helper.' );
$assert( str_contains( $formsJs, 'Metis.ui.select.init(root);' ), 'Form Builder render cycle must reinitialize the canonical select helper.' );
$assert( ! preg_match( '/(^|[^A-Za-z0-9_])alert\s*\(|(^|[^A-Za-z0-9_])confirm\s*\(/u', $formsJs ), 'Form Builder must not use browser-native alert/confirm fallbacks.' );
$assert( str_contains( $formsRenderer, 'data-metis-forms-public-data' ) && str_contains( $formsRenderer, 'Metis.forms.initPublicEmbeds(document);' ), 'Public form renderer must keep boot data with the embed and request embed reinitialization after script load.' );
$assert( ! str_contains( $websiteThemeView, 'metis-theme-selectx' ), 'Website theme UI must not keep the legacy private selectx system.' );
$assert( ! str_contains( $websiteThemeView, 'rebuildStyledSelects' ), 'Website theme UI must not rebuild private styled selects.' );
$assert( str_contains( $websiteThemeView, 'data-metis-ui-select="1"' ) && str_contains( $websiteThemeView, 'refreshThemeSelects' ), 'Website theme UI must use the shared select helper for preview-capable selects.' );
$assert( ! str_contains( $newsletterJs, 'metis-theme-selectx' ), 'Newsletter theme UI must not keep the legacy private selectx system.' );
$assert( str_contains( $newsletterJs, 'Metis.ui.select.refresh(select);' ), 'Newsletter theme UI must use the shared select helper.' );
$assert( ! str_contains( $simpleEditorJs, 'metis-modal-overlay' ), 'Simple editor must not retain legacy modal overlay markup.' );
$assert( ! str_contains( $depositsView, 'metis-modal-overlay' ), 'Deposits view must not retain legacy modal overlay markup.' );
$assert( ! str_contains( $campaignView, 'metis-modal-overlay' ), 'Campaign view must not retain legacy modal overlay markup.' );
$assert( str_contains( $simpleEditorJs, "Metis.ui.modal.form('metis-v2-confirm-modal');" ), 'Simple editor confirm flow must use the shared modal runtime.' );
$assert( str_contains( $campaignView, "Metis.ui.modal.form('metis-goal-modal');" ), 'Campaign goal editor must use the shared modal runtime.' );

$assert( str_contains( $donationsReadService, 'public static function dashboardSnapshot()' ), 'Donations read service must expose dashboardSnapshot().' );
$assert( str_contains( $grandyStashAjax, "metis_textarea_clean( metis_runtime_unslash( metis_request_post()['content'] ?? '' ) )" ) && str_contains( $grandyStashAjax, "metis_text_clean( metis_runtime_unslash( metis_request_post()['subject'] ?? '' ) )" ), 'Grandy\'s Stash message submission must normalize note/reply text through canonical cleaners.' );
$assert( str_contains( $contactsRelationshipsAjax, "metis_textarea_clean( metis_runtime_unslash( metis_request_post()['notes'] ) )" ), 'Contacts relationship notes must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $peopleTemplatesAjax, "metis_textarea_clean(metis_runtime_unslash(metis_request_post()['description']))" ), 'People template descriptions must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $hermesAjax, "metis_textarea_clean( metis_runtime_unslash( metis_request_post()['note'] ?? '' ) )" ), 'Hermes approval notes must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $boardAjax, "metis_text_raw_clean(metis_runtime_unslash(\$post['source_text'] ?? ''))" ), 'Board bylaws formatting endpoint must normalize raw UTF-8 source text through canonical raw cleaner.' );
$assert( str_contains( $boardBylawsService, "\\metis_text_raw_clean( \\metis_runtime_unslash( \$post['source_text'] ?? '' ) )" ), 'Board bylaws save service must normalize raw UTF-8 source text through canonical raw cleaner.' );
$assert( str_contains( $boardDecisionAttendanceService, "\\metis_textarea_clean( \\metis_runtime_unslash( \$post['notes'] ?? '' ) )" ), 'Board attendance notes must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $boardWorkflowTemplateService, "\\metis_textarea_clean( \\metis_runtime_unslash( \$post['description'] ?? '' ) )" ), 'Board workflow template descriptions must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $financeAjax, "'decision_notes' => metis_textarea_clean( (string) metis_finance_ajax_post_value( 'decision_notes', '' ) )" ), 'Finance reconciliation review notes must preserve multiline Unicode via textarea cleaner at the AJAX boundary.' );
$assert( str_contains( $financeService, "\$decisionNotes = metis_textarea_clean( (string) ( \$input['decision_notes'] ?? '' ) );" ) && str_contains( $financeService, "\$notes = metis_textarea_clean( (string) ( \$input['notes'] ?? '' ) );" ), 'Finance service notes fields must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $donationsNotesAjax, 'TransactionMutationService::ensureSupportingTables();' ) && str_contains( $donationsNotesAjax, 'TransactionMutationService::addTransactionNote(' ) && str_contains( $donationsNotesAjax, 'TransactionMutationService::recordTransactionRefund(' ) && str_contains( $donationsNotesAjax, 'TransactionMutationService::updateTransactionCampaign(' ), 'Donations transaction note/refund/campaign writes must delegate through the canonical mutation service.' );
$assert( str_contains( $donationsTransactionMutationService, "'note'       => \$note" ) && str_contains( $donationsTransactionMutationService, "'notes'       => \$notes !== '' ? \$notes : null" ), 'Donations mutation service must preserve transaction note and refund notes text through canonical payloads.' );
$assert( str_contains( $donationsNotesAjax, 'metis_update_batch_note( $id, $batch, $text )' ) && str_contains( $donationsNotesAjax, 'metis_delete_batch_note( $id, $batch )' ), 'Donations batch note AJAX handlers must delegate writes through the donations module path.' );
$assert( str_contains( $donationsBootstrap, 'function metis_update_batch_note' ) && str_contains( $donationsBootstrap, 'function metis_delete_batch_note' ), 'Donations bootstrap must expose canonical batch note mutation helpers.' );
$assert( str_contains( $donationsModule, 'public static function updateBatchNote' ) && str_contains( $donationsModule, 'public static function deleteBatchNote' ), 'Donations module must own batch note update and delete persistence.' );
$assert( str_contains( $helpService, "return metis_text_clean( \$value );" ), 'Help plain-text normalization must delegate to the canonical text cleaner.' );
$assert( str_contains( $helpSearchStore, "\\metis_text_raw_clean( \$content )" ) && str_contains( $helpSearchStore, "\\metis_runtime_kses_post( \$content )" ), 'Help article content normalization must preserve UTF-8 text and sanitize through the canonical rich-text path.' );
$assert( str_contains( $helpArticleSave, "metis_text_raw_clean( metis_runtime_unslash( metis_request_post()['content'] ?? '' ) )" ), 'Help article save handler must normalize raw submitted content through the canonical raw cleaner.' );
$assert( str_contains( $contactMutationService, "\\metis_textarea_clean( \$note )" ), 'Contact note mutation service must defensively normalize note text through the canonical textarea cleaner.' );
$assert( str_contains( $contactsAjax, "metis_textarea_clean( (string) ( \$entry['notes'] ?? '' ) )" ), 'Contacts relationship-note normalization must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $recurringDonationsService, "\\metis_textarea_clean( \$message )" ), 'Recurring donation inquiry messages must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $websiteAjax, "metis_textarea_clean( (string) metis_runtime_unslash( metis_request_post()['revision_note'] ) )" ), 'Website revision notes must preserve multiline Unicode via textarea cleaner at the AJAX boundary.' );
$assert( str_contains( $revisionTimelineService, "'revision_note' => metis_textarea_clean( \$note )" ), 'Website revision timeline persistence must preserve multiline Unicode via textarea cleaner.' );
$assert( str_contains( $peopleReadService, 'public static function workspaceSnapshot' ) && str_contains( $peopleReadService, 'public static function personSnapshot' ), 'People read service must expose workspace and person snapshots.' );
$assert( str_contains( $peopleReadService, "'current_workspace_role' => \$current_workspace_role" ) && str_contains( $peopleReadService, "'current_stripe_role' => \$current_stripe_role" ), 'People person snapshot must expose the current workspace and Stripe role values explicitly for view rendering.' );
$assert( str_contains( $newsletterReadService, 'public static function campaignsSnapshot' ) && str_contains( $newsletterReadService, 'public static function dashboardSnapshot' ), 'Newsletter read service must expose canonical campaign/dashboard snapshots.' );

$viewExpectations = [
    'modules/donations/views/dashboard.php' => 'ReadService::dashboardSnapshot()',
    'modules/people/views/workspace.php' => 'ReadService::workspaceSnapshot(',
    'modules/people/views/person.php' => 'ReadService::personSnapshot(',
    'modules/newsletter/views/campaigns.php' => 'ReadService::campaignsSnapshot(',
    'modules/newsletter/views/editor.php' => 'ReadService::editorId(',
];

foreach ( $viewExpectations as $relative => $needle ) {
    $contents = $read( $relative );
    $assert( $contents !== '', 'Expected hardened view is missing: ' . $relative );
    $assert( str_contains( $contents, $needle ), 'Hardened view must delegate through canonical read service: ' . $relative );
    $assert( preg_match( $rawSqlPattern, $contents ) !== 1, 'Hardened view must not embed raw SQL: ' . $relative );
}

$handlerExpectations = [
    'modules/board/assets/board.ajax.php',
    'modules/contacts/ajax/contacts.ajax.php',
    'modules/donations/assets/deposits.ajax.php',
    'modules/newsletter/assets/newsletter.ajax.php',
    'modules/people/ajax/people.ajax.php',
    'modules/people/ajax/workspace.ajax.php',
];

foreach ( $handlerExpectations as $relative ) {
    $contents = $read( $relative );
    $assert( $contents !== '', 'Expected hardened AJAX handler is missing: ' . $relative );
    $assert( str_contains( $contents, 'metis_ajax_register_controller(' ), 'Hardened AJAX handler must register controller metadata: ' . $relative );
    $assert( preg_match( $rawSqlPattern, $contents ) !== 1, 'Hardened AJAX handler must not embed request-path raw SQL: ' . $relative );
}

$legacyUiCallers = [
    'modules/website/assets/website.js',
    'modules/website/views/webparts.php',
    'modules/website/views/theme.php',
    'modules/website/views/redirects.php',
    'modules/donations/views/recurring.php',
    'modules/donations/views/transaction.php',
    'modules/import/assets/import.js',
];

foreach ( $legacyUiCallers as $relative ) {
    $contents = $read( $relative );
    $assert( $contents !== '', 'Expected hardened UI module is missing: ' . $relative );
    $assert( ! str_contains( $contents, 'window.metis_toast' ), 'Hardened UI module must not call legacy toast alias directly: ' . $relative );
    $assert( ! str_contains( $contents, 'window.metis_confirm' ), 'Hardened UI module must not call legacy confirm alias directly: ' . $relative );
}

$assert( str_contains( $governanceChecker, 'No governance issues detected.' ), 'Governance checker must report a clean state when no issues remain.' );

$governanceOutput = [];
$governanceCode = 0;
exec( 'php ' . escapeshellarg( $root . '/tools/governance/check-ajax-ui-hardening.php' ) . ' 2>&1', $governanceOutput, $governanceCode );
$governanceText = implode( PHP_EOL, $governanceOutput );
$assert( $governanceCode === 0, 'Governance checker must exit cleanly. Output: ' . $governanceText );
$assert( str_contains( $governanceText, 'No governance issues detected.' ), 'Governance checker must report no issues. Output: ' . $governanceText );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "AJAX/UI hardening contract checks passed.\n" );
