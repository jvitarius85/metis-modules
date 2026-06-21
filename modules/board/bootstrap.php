<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Board\BoardModule::boot();

function metis_board_can_view(): bool { return \Metis\Modules\Board\BoardModule::canView(); }
function metis_board_can( string $action ): bool { return \Metis\Modules\Board\Access::can( $action ); }
function metis_board_can_manage(): bool { return \Metis\Modules\Board\BoardModule::canManage(); }
function metis_board_base_url(): string { return \Metis\Modules\Board\BoardModule::baseUrl(); }
function metis_board_meeting_url(string $meeting_code): string { return \Metis\Modules\Board\BoardModule::meetingUrl( $meeting_code ); }
function metis_board_table_exists(string $table): bool { return \Metis\Modules\Board\BoardModule::tableExists( $table ); }
function metis_board_ensure_schema(): void { \Metis\Modules\Board\BoardModule::ensureSchema(); }
function metis_board_seed_workflow_templates(): void { \Metis\Modules\Board\BoardModule::seedWorkflowTemplates(); }
function metis_board_format_datetime(string $mysql_datetime, string $format = 'M j, Y g:i a'): string { return \Metis\Modules\Board\BoardModule::formatDatetime( $mysql_datetime, $format ); }
function metis_board_current_person_id(): int { return \Metis\Modules\Board\BoardModule::currentPersonId(); }
function metis_board_b64url_encode(string $value): string { return \Metis\Modules\Board\BoardModule::b64urlEncode( $value ); }
function metis_board_workspace_settings(): array { return \Metis\Modules\Board\BoardModule::workspaceSettings(); }
function metis_board_google_access_token(array $cfg): array { return \Metis\Modules\Board\BoardModule::googleAccessToken( $cfg ); }
function metis_board_google_request(string $method, string $url, ?array $body, array $cfg, array $extra_headers = []): array { return \Metis\Modules\Board\BoardModule::googleRequest( $method, $url, $body, $cfg, $extra_headers ); }
function metis_board_generate_code(string $prefix, string $table, string $column): string { return \Metis\Modules\Board\BoardModule::generateCode( $prefix, $table, $column ); }
function metis_board_doc_type_label(string $doc_type): string { return \Metis\Modules\Board\BoardModule::docTypeLabel( $doc_type ); }
function metis_board_extract_agenda_decision_points(array $agenda): array { return \Metis\Modules\Board\BoardModule::extractAgendaDecisionPoints( $agenda ); }
function metis_board_sync_decision_points(int $meeting_id, array $agenda): int { return \Metis\Modules\Board\BoardModule::syncDecisionPoints( $meeting_id, $agenda ); }
