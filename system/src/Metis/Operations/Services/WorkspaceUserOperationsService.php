<?php
declare(strict_types=1);

namespace Metis\Operations\Services;

final class WorkspaceUserOperationsService extends AbstractOperationsService {
    public function operationKeys(): array {
        return [
            'workspace_user_create',
            'workspace_user_update',
            'workspace_user_disable',
            'workspace_user_enable',
            'workspace_user_delete',
            'workspace_user_password_reset',
        ];
    }

    public function family(): string {
        return 'workspace_user';
    }
}
