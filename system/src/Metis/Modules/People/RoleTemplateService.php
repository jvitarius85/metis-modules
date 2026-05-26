<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class RoleTemplateService {
    public static function findTemplateIdByKey( string $template_key ): int {
        $templates_table = \Metis_Tables::get( 'people_role_templates' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$templates_table} WHERE template_key = %s LIMIT 1",
            [ $template_key ]
        );
    }

    public static function saveTemplate( string $template_key, string $template_name, string $description, ?string $checklist_json, ?int $actor_id ): int {
        $templates_table = \Metis_Tables::get( 'people_role_templates' );
        $template_id = self::findTemplateIdByKey( $template_key );

        if ( $template_id > 0 ) {
            \metis_db()->update( $templates_table, [
                'template_name' => $template_name,
                'description' => $description,
                'checklist_json' => $checklist_json,
            ], [ 'id' => $template_id ], [ '%s', '%s', '%s' ], [ '%d' ] );

            return $template_id;
        }

        \metis_db()->insert( $templates_table, [
            'template_key' => $template_key,
            'template_name' => $template_name,
            'description' => $description,
            'checklist_json' => $checklist_json,
            'created_by_person_id' => $actor_id,
        ], [ '%s', '%s', '%s', '%s', '%d' ] );

        return (int) \metis_db()->lastInsertId();
    }

    public static function syncTemplateRoles( int $template_id, array $role_keys ): void {
        $template_roles_table = \Metis_Tables::get( 'people_template_roles' );
        $roles_table = \Metis_Tables::get( 'people_roles' );

        \metis_db()->delete( $template_roles_table, [ 'template_id' => $template_id ], [ '%d' ] );

        foreach ( $role_keys as $role_key ) {
            $role_id = (int) \metis_db()->scalar(
                "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1",
                [ $role_key ]
            );
            if ( $role_id < 1 ) {
                continue;
            }

            \metis_db()->insert( $template_roles_table, [
                'template_id' => $template_id,
                'role_id' => $role_id,
            ], [ '%d', '%d' ] );
        }
    }

    public static function getTemplateByKey( string $template_key ): ?array {
        $templates_table = \Metis_Tables::get( 'people_role_templates' );
        $row = \metis_db()->fetchOne(
            "SELECT id, checklist_json FROM {$templates_table} WHERE template_key = %s LIMIT 1",
            [ $template_key ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function getRoleIdsForTemplate( int $template_id ): array {
        $template_roles_table = \Metis_Tables::get( 'people_template_roles' );

        return \metis_db()->column(
            "SELECT role_id FROM {$template_roles_table} WHERE template_id = %d",
            [ $template_id ]
        ) ?: [];
    }

    public static function assignMissingRolesToPerson( int $person_id, array $role_ids ): int {
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
        $added = 0;

        foreach ( $role_ids as $rid ) {
            $role_id = (int) $rid;
            if ( $role_id < 1 ) {
                continue;
            }

            $exists = (int) \metis_db()->scalar(
                "SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1",
                [ $person_id, $role_id ]
            );
            if ( $exists > 0 ) {
                continue;
            }

            $ok = \metis_db()->insert( $user_roles_table, [
                'person_id' => $person_id,
                'role_id' => $role_id,
            ], [ '%d', '%d' ] );
            if ( $ok ) {
                $added++;
            }
        }

        return $added;
    }

    public static function addMissingOnboardingTasks( int $person_id, array $checklist ): int {
        $tasks_added = 0;

        foreach ( $checklist as $task_label_raw ) {
            $task_label = \metis_text_clean( (string) $task_label_raw );
            if ( $task_label === '' ) {
                continue;
            }

            if ( self::hasOpenOnboardingTask( $person_id, $task_label ) ) {
                continue;
            }

            LifecycleTaskService::addTask( $person_id, 'onboarding', $task_label, null );
            $tasks_added++;
        }

        return $tasks_added;
    }

    private static function hasOpenOnboardingTask( int $person_id, string $task_label ): bool {
        $tasks_table = \Metis_Tables::get( 'people_lifecycle_tasks' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$tasks_table}
             WHERE person_id = %d
               AND phase = 'onboarding'
               AND task_label = %s
               AND status IN ('pending','in_progress')
             LIMIT 1",
            [ $person_id, $task_label ]
        ) > 0;
    }
}
