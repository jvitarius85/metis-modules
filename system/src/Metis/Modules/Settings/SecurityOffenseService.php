<?php
declare(strict_types=1);

namespace Metis\Modules\Settings;

final class SecurityOffenseService {
    public static function summarizeLastDays( int $days = 7 ): array {
        if ( ! class_exists( 'Metis_Tables' ) ) {
            return self::emptySummary();
        }

        $security_table = \Metis_Tables::get( 'audit_security' );
        if ( $security_table === '' ) {
            return self::emptySummary();
        }

        $security_cutoff = function_exists( 'metis_settings_recent_cutoff' )
            ? \metis_settings_recent_cutoff( $days )
            : gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $days ) . ' days' ) );
        $offense_clause = function_exists( 'metis_settings_health_security_offense_clause' )
            ? (string) \metis_settings_health_security_offense_clause()
            : '1=0';
        $offense_exclusion_clause = function_exists( 'metis_settings_health_security_offense_exclusion_clause' )
            ? (string) \metis_settings_health_security_offense_exclusion_clause()
            : '1=1';

        $offense_total = (int) \metis_db()->scalar(
            "SELECT COUNT(*)
             FROM {$security_table}
             WHERE occurred_at >= %s
               AND {$offense_clause}
               AND {$offense_exclusion_clause}",
            [ $security_cutoff ]
        );
        $offense_top_rows = \metis_db()->fetchAll(
            "SELECT action_type, COUNT(*) AS total
             FROM {$security_table}
             WHERE occurred_at >= %s
               AND {$offense_clause}
               AND {$offense_exclusion_clause}
             GROUP BY action_type
             ORDER BY total DESC
             LIMIT 1",
            [ $security_cutoff ]
        );
        $offense_top = '';
        if ( is_array( $offense_top_rows ) && ! empty( $offense_top_rows[0]['action_type'] ) ) {
            $offense_top = (string) $offense_top_rows[0]['action_type'];
        }

        $offense_rows = \metis_db()->fetchAll(
            "SELECT module_slug, action_type, resource_label, COUNT(*) AS total
             FROM {$security_table}
             WHERE occurred_at >= %s
               AND {$offense_clause}
               AND {$offense_exclusion_clause}
             GROUP BY module_slug, action_type, resource_label
             ORDER BY total DESC
             LIMIT 3",
            [ $security_cutoff ]
        ) ?: [];
        $offense_breakdown = [];
        foreach ( $offense_rows as $row ) {
            $module = trim( (string) ( $row['module_slug'] ?? '' ) );
            $action = trim( (string) ( $row['action_type'] ?? '' ) );
            $resource = trim( (string) ( $row['resource_label'] ?? '' ) );
            $count = (int) ( $row['total'] ?? 0 );
            if ( $count < 1 ) {
                continue;
            }
            $descriptor = ( $module !== '' ? $module : 'unknown-module' ) . '/' . ( $action !== '' ? $action : 'unknown-action' );
            if ( $resource !== '' ) {
                $descriptor .= ' [' . $resource . ']';
            }
            $offense_breakdown[] = $descriptor . ': ' . $count;
        }

        return [
            'total' => $offense_total,
            'top' => $offense_top,
            'breakdown' => $offense_breakdown,
            'cutoff' => $security_cutoff,
        ];
    }

    public static function emptySummary(): array {
        return [
            'total' => 0,
            'top' => '',
            'breakdown' => [],
            'cutoff' => '',
        ];
    }
}
