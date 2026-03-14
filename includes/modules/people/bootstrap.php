<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __DIR__, 2 ) . '/core/autoload.php';

\Metis\Modules\People\PeopleModule::boot();

function metis_people_table_exists( string $table ): bool { return \Metis\Modules\People\PeopleModule::tableExists( $table ); }
function metis_people_column_exists( string $table, string $column ): bool { return \Metis\Modules\People\PeopleModule::columnExists( $table, $column ); }
function metis_people_add_column_if_missing( string $table, string $column, string $definition ): void { \Metis\Modules\People\PeopleModule::addColumnIfMissing( $table, $column, $definition ); }
function metis_people_drop_index_if_exists( string $table, string $index_name ): void { \Metis\Modules\People\PeopleModule::dropIndexIfExists( $table, $index_name ); }
function metis_people_add_index_if_missing( string $table, string $index_name, string $definition ): void { \Metis\Modules\People\PeopleModule::addIndexIfMissing( $table, $index_name, $definition ); }
function metis_people_base_url(): string { return \Metis\Modules\People\PeopleModule::baseUrl(); }
function metis_people_person_url( string $pid = '' ): string { return \Metis\Modules\People\PeopleModule::personUrl( $pid ); }
function metis_people_people_list_url(): string { return \Metis\Modules\People\PeopleModule::peopleListUrl(); }
function metis_people_roles_list_url(): string { return \Metis\Modules\People\PeopleModule::rolesListUrl(); }
function metis_people_permissions_url(): string { return \Metis\Modules\People\PeopleModule::permissionsUrl(); }
function metis_people_access_requests_url(): string { return \Metis\Modules\People\PeopleModule::accessRequestsUrl(); }
function metis_people_templates_url(): string { return \Metis\Modules\People\PeopleModule::templatesUrl(); }
function metis_people_bulk_actions_url(): string { return \Metis\Modules\People\PeopleModule::bulkActionsUrl(); }
function metis_people_activity_url(): string { return \Metis\Modules\People\PeopleModule::activityUrl(); }
function metis_people_workspace_url(): string { return \Metis\Modules\People\PeopleModule::workspaceUrl(); }
function metis_people_role_url( string $role_key = '', string $role_domain = '' ): string { return \Metis\Modules\People\PeopleModule::roleUrl( $role_key, $role_domain ); }
function metis_people_can_manage(): bool { return \Metis\Modules\People\PeopleModule::canManage(); }
function metis_people_can_view(): bool { return \Metis\Modules\People\PeopleModule::canView(); }
function metis_people_can_workspace_manage(): bool { return \Metis\Modules\People\PeopleModule::canWorkspaceManage(); }
function metis_people_ensure_schema(): void { \Metis\Modules\People\PeopleModule::ensureSchema(); }
function metis_people_seed_permissions_and_roles(): void { \Metis\Modules\People\PeopleModule::seedPermissionsAndRoles(); }
function metis_people_get_or_create_current_person(): ?array { return \Metis\Modules\People\PeopleModule::getOrCreateCurrentPerson(); }
function metis_people_can( string $domain, string $action = 'view' ): bool { return \Metis\Modules\People\PeopleModule::can( $domain, $action ); }
function metis_people_get_current_person_id(): int { return \Metis\Modules\People\PeopleModule::getCurrentPersonId(); }
function metis_people_log_activity( ?int $person_id, string $activity_type, string $summary, array $details = [] ): void { \Metis\Modules\People\PeopleModule::logActivity( $person_id, $activity_type, $summary, $details ); }
function metis_people_run_maintenance(): void { \Metis\Modules\People\PeopleModule::runMaintenance(); }
