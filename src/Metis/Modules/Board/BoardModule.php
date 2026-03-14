<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class BoardModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Board bootstrap loaded' );

        \metis_add_action( 'init', [ self::class, 'ensureSchema' ], 5 );
        \metis_add_action( 'init', [ self::class, 'seedWorkflowTemplates' ], 7 );
    }

    public static function canView(): bool { return Access::canView(); }
    public static function canManage(): bool { return Access::canManage(); }
    public static function baseUrl(): string { return Support::baseUrl(); }
    public static function meetingUrl( string $meeting_code ): string { return Support::meetingUrl( $meeting_code ); }
    public static function tableExists( string $table ): bool { return SchemaManager::tableExists( $table ); }
    public static function ensureSchema(): void { SchemaManager::ensureSchema(); }
    public static function seedWorkflowTemplates(): void { SchemaManager::seedWorkflowTemplates(); }
    public static function formatDatetime( string $mysql_datetime, string $format = 'M j, Y g:i a' ): string { return Support::formatDatetime( $mysql_datetime, $format ); }
    public static function currentPersonId(): int { return Support::currentPersonId(); }
    public static function b64urlEncode( string $value ): string { return WorkspaceService::b64urlEncode( $value ); }
    public static function workspaceSettings(): array { return WorkspaceService::workspaceSettings(); }
    public static function googleAccessToken( array $cfg ): array { return WorkspaceService::googleAccessToken( $cfg ); }
    public static function googleRequest( string $method, string $url, ?array $body, array $cfg, array $extra_headers = [] ): array { return WorkspaceService::googleRequest( $method, $url, $body, $cfg, $extra_headers ); }
    public static function generateCode( string $prefix, string $table, string $column ): string { return Support::generateCode( $prefix, $table, $column ); }
    public static function docTypeLabel( string $doc_type ): string { return Support::docTypeLabel( $doc_type ); }
    public static function extractAgendaDecisionPoints( array $agenda ): array { return Support::extractAgendaDecisionPoints( $agenda ); }
    public static function syncDecisionPoints( int $meeting_id, array $agenda ): int { return Support::syncDecisionPoints( $meeting_id, $agenda ); }
}
