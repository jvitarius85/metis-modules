<?php
declare(strict_types=1);

namespace Metis\Operations\Services;

final class UserOperationsService extends AbstractOperationsService {
    public function operationKeys(): array {
        return [
            'create_user',
            'update_user',
            'disable_user',
            'enable_user',
            'user_delete',
            'user_unlock',
            'offboard_user',
            'user_password_reset',
            'assign_role',
            'remove_role',
            'manage_user_roles',
            'manage_workspace_groups',
            'reset_user_mfa',
            'link_drive_folder',
        ];
    }

    public function family(): string {
        return 'user';
    }
}
