<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class ReadService {
    public static function dashboardSnapshot(): array {
        $db = \metis_db();
        $people_table = \Metis_Tables::get('people');
        $roles_table = \Metis_Tables::get('people_roles');
        $perms_table = \Metis_Tables::get('people_permissions');
        $requests_table = \Metis_Tables::get('people_access_requests');
        $templates_table = \Metis_Tables::get('people_role_templates');
        $activity_table = \Metis_Tables::get('people_activity');
        $documents_table = \Metis_Tables::get('people_documents');
        $activity_cutoff = \metis_runtime_date('Y-m-d H:i:s', \metis_current_time('timestamp') - DAY_IN_SECONDS);

        return [
            'total_people' => (int) $db->scalar("SELECT COUNT(*) FROM {$people_table}"),
            'staff_count' => (int) $db->scalar("SELECT COUNT(*) FROM {$people_table} WHERE is_staff = 1"),
            'board_count' => (int) $db->scalar("SELECT COUNT(*) FROM {$people_table} WHERE is_board = 1"),
            'volunteer_count' => (int) $db->scalar("SELECT COUNT(*) FROM {$people_table} WHERE is_volunteer = 1"),
            'workspace_count' => (int) $db->scalar("SELECT COUNT(*) FROM {$people_table} WHERE is_workspace_user = 1"),
            'stripe_count' => (int) $db->scalar("SELECT COUNT(*) FROM {$people_table} WHERE stripe_role IS NOT NULL AND stripe_role <> ''"),
            'roles_count' => (int) $db->scalar("SELECT COUNT(*) FROM {$roles_table}"),
            'permissions_count' => (int) $db->scalar("SELECT COUNT(*) FROM {$perms_table}"),
            'active_people' => (int) $db->scalar("SELECT COUNT(*) FROM {$people_table} WHERE status = 'active'"),
            'pending_requests' => (int) $db->scalar("SELECT COUNT(*) FROM {$requests_table} WHERE status = 'pending'"),
            'templates_count' => (int) $db->scalar("SELECT COUNT(*) FROM {$templates_table}"),
            'activity_24h' => (int) $db->scalar("SELECT COUNT(*) FROM {$activity_table} WHERE created_at >= %s", [ $activity_cutoff ]),
            'mfa_gaps' => (int) $db->scalar("SELECT COUNT(*) FROM {$people_table} WHERE status='active' AND requires_2fa = 1 AND (totp_enabled = 0 AND passkey_enabled = 0)"),
            'expired_requests' => (int) $db->scalar("SELECT COUNT(*) FROM {$requests_table} WHERE status = 'expired'"),
            'expired_docs' => (int) $db->scalar("SELECT COUNT(*) FROM {$documents_table} WHERE lifecycle_status = 'expired'"),
        ];
    }

    public static function peopleListSnapshot(int $page, int $per_page): array {
        $db = \metis_db();
        $people_table = \Metis_Tables::get('people');
        $roles_table = \Metis_Tables::get('people_roles');
        $user_roles_table = \Metis_Tables::get('people_user_roles');

        $page = max(1, $page);
        $per_page = max(1, $per_page);
        $total_people = (int) $db->scalar("SELECT COUNT(*) FROM {$people_table}");
        $total_pages = max(1, (int) ceil($total_people / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = max(0, ($page - 1) * $per_page);

        $people_rows = $db->fetchAll(
            "SELECT id, pid, auth_provider, email, first_name, last_name, display_name, linked_donor_id, is_workspace_user, workspace_email, stripe_role
             FROM {$people_table}
             ORDER BY display_name ASC, email ASC
             LIMIT %d OFFSET %d",
            [ $per_page, $offset ]
        ) ?: [];
        $roles_rows = $db->fetchAll("SELECT * FROM {$roles_table} WHERE role_domain = 'metis' ORDER BY role_name ASC") ?: [];

        $role_by_id = [];
        $role_by_key = [];
        foreach ($roles_rows as $role_row) {
            $role_id = (int) ($role_row['id'] ?? 0);
            if ($role_id < 1) {
                continue;
            }
            $role_by_id[$role_id] = $role_row;
            $role_by_key[(string) ($role_row['role_key'] ?? '')] = $role_row;
        }

        $person_ids = array_values(array_filter(array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $people_rows), static function (int $id): bool {
            return $id > 0;
        }));

        $assign_rows = [];
        if ($person_ids !== []) {
            $id_list = implode(',', array_map('intval', $person_ids));
            $assign_rows = $db->fetchAll(
                "SELECT ur.person_id, ur.role_id FROM {$user_roles_table} ur WHERE ur.person_id IN ({$id_list})"
            ) ?: [];
        }

        $role_ids_by_person = [];
        foreach ($assign_rows as $assign_row) {
            $person_id = (int) ($assign_row['person_id'] ?? 0);
            $role_id = (int) ($assign_row['role_id'] ?? 0);
            if ($person_id < 1 || $role_id < 1) {
                continue;
            }
            if (!isset($role_ids_by_person[$person_id])) {
                $role_ids_by_person[$person_id] = [];
            }
            $role_ids_by_person[$person_id][] = $role_id;
        }

        $people = [];
        foreach ($people_rows as $person_row) {
            $person_id = (int) ($person_row['id'] ?? 0);
            if ($person_id < 1) {
                continue;
            }

            $role_keys = [];
            foreach ((array) ($role_ids_by_person[$person_id] ?? []) as $role_id) {
                if (!empty($role_by_id[$role_id]['role_key'])) {
                    $role_keys[] = (string) $role_by_id[$role_id]['role_key'];
                }
            }

            $full_name = trim((string) ($person_row['first_name'] ?? '') . ' ' . (string) ($person_row['last_name'] ?? ''));
            if ($full_name === '') {
                $full_name = (string) ($person_row['display_name'] ?? '');
            }

            $people[] = [
                'id' => $person_id,
                'pid' => (string) ($person_row['pid'] ?? ''),
                'auth_provider' => (string) ($person_row['auth_provider'] ?? 'metis'),
                'email' => (string) ($person_row['email'] ?? ''),
                'full_name' => $full_name,
                'linked_donor_id' => (string) ($person_row['linked_donor_id'] ?? ''),
                'is_workspace_user' => !empty($person_row['is_workspace_user']) ? 1 : 0,
                'workspace_email' => (string) ($person_row['workspace_email'] ?? ''),
                'stripe_role' => (string) ($person_row['stripe_role'] ?? ''),
                'roles' => $role_keys,
            ];
        }

        return [
            'page' => $page,
            'per_page' => $per_page,
            'total_people' => $total_people,
            'total_pages' => $total_pages,
            'people' => $people,
            'role_by_key' => $role_by_key,
        ];
    }

    public static function rolesListSnapshot(): array {
        $db = \metis_db();
        $roles_table = \Metis_Tables::get('people_roles');
        $role_perms_table = \Metis_Tables::get('people_role_perms');
        $user_roles_table = \Metis_Tables::get('people_user_roles');

        $roles_rows = $db->fetchAll("SELECT * FROM {$roles_table} ORDER BY role_domain ASC, role_name ASC") ?: [];
        $assign_rows = $db->fetchAll("SELECT role_id FROM {$user_roles_table}") ?: [];
        $perm_rows = $db->fetchAll(
            "SELECT role_id, COUNT(*) AS perm_count
             FROM {$role_perms_table}
             WHERE allow_access = 1
             GROUP BY role_id"
        ) ?: [];

        $role_member_count = [];
        foreach ($assign_rows as $assign_row) {
            $role_id = (int) ($assign_row['role_id'] ?? 0);
            if ($role_id < 1) {
                continue;
            }
            $role_member_count[$role_id] = (int) ($role_member_count[$role_id] ?? 0) + 1;
        }

        $perm_count_by_role = [];
        foreach ($perm_rows as $perm_row) {
            $role_id = (int) ($perm_row['role_id'] ?? 0);
            if ($role_id < 1) {
                continue;
            }
            $perm_count_by_role[$role_id] = (int) ($perm_row['perm_count'] ?? 0);
        }

        $roles_by_domain = [
            'metis' => [],
            'stripe' => [],
            'workspace' => [],
        ];
        foreach ($roles_rows as $role_row) {
            $role_id = (int) ($role_row['id'] ?? 0);
            $domain = (string) ($role_row['role_domain'] ?? 'metis');
            if (!isset($roles_by_domain[$domain])) {
                continue;
            }
            $role_row['permission_count'] = (int) ($perm_count_by_role[$role_id] ?? 0);
            $role_row['member_count'] = (int) ($role_member_count[$role_id] ?? 0);
            $roles_by_domain[$domain][] = $role_row;
        }

        return [
            'roles_by_domain' => $roles_by_domain,
        ];
    }

    public static function bulkActionsSnapshot(): array {
        $db = \metis_db();
        $people_table = \Metis_Tables::get('people');
        $roles_table = \Metis_Tables::get('people_roles');
        $workspace_groups_table = \Metis_Tables::get('people_workspace_groups');
        $workspace_users_table = \Metis_Tables::get('people_workspace_users');
        $positions_table = \Metis_Tables::get('people_positions');

        return [
            'people' => $db->fetchAll("SELECT pid, display_name, email FROM {$people_table} ORDER BY display_name ASC, email ASC LIMIT 400") ?: [],
            'roles' => $db->fetchAll("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain='metis' ORDER BY role_name ASC") ?: [],
            'stripe_roles' => $db->fetchAll("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain='stripe' ORDER BY role_name ASC") ?: [],
            'workspace_groups' => $db->fetchAll("SELECT group_email, group_name FROM {$workspace_groups_table} ORDER BY group_name ASC, group_email ASC") ?: [],
            'workspace_org_units' => $db->fetchAll("SELECT org_unit_path FROM {$workspace_users_table} WHERE org_unit_path IS NOT NULL AND org_unit_path <> '' GROUP BY org_unit_path ORDER BY org_unit_path ASC") ?: [],
            'positions' => $db->fetchAll("SELECT group_key, position_label FROM {$positions_table} ORDER BY group_key ASC, sort_order ASC, position_label ASC") ?: [],
        ];
    }

    public static function templatesSnapshot(): array {
        $db = \metis_db();
        $templates_table = \Metis_Tables::get('people_role_templates');
        $roles_table = \Metis_Tables::get('people_roles');

        return [
            'templates' => $db->fetchAll("SELECT * FROM {$templates_table} ORDER BY template_name ASC") ?: [],
            'metis_roles' => $db->fetchAll("SELECT id, role_key, role_name FROM {$roles_table} WHERE role_domain='metis' ORDER BY role_name ASC") ?: [],
        ];
    }

    public static function permissionsSnapshot(): array {
        $db = \metis_db();
        $perms_table = \Metis_Tables::get('people_permissions');
        $roles_table = \Metis_Tables::get('people_roles');
        $role_perms_table = \Metis_Tables::get('people_role_perms');

        $permissions = $db->fetchAll("SELECT * FROM {$perms_table} ORDER BY module_slug ASC, action_key ASC") ?: [];
        $metis_role_count = (int) $db->scalar("SELECT COUNT(*) FROM {$roles_table} WHERE role_domain = 'metis'");
        $coverage_rows = $db->fetchAll(
            "SELECT rp.permission_id, COUNT(DISTINCT rp.role_id) AS coverage_count
             FROM {$role_perms_table} rp
             INNER JOIN {$roles_table} r ON r.id = rp.role_id
             WHERE rp.allow_access = 1
               AND r.role_domain = 'metis'
             GROUP BY rp.permission_id"
        ) ?: [];

        $coverage_by_permission = [];
        foreach ($coverage_rows as $coverage_row) {
            $permission_id = (int) ($coverage_row['permission_id'] ?? 0);
            if ($permission_id < 1) {
                continue;
            }
            $coverage_by_permission[$permission_id] = (int) ($coverage_row['coverage_count'] ?? 0);
        }

        return [
            'permissions' => $permissions,
            'metis_role_count' => $metis_role_count,
            'coverage_by_permission' => $coverage_by_permission,
        ];
    }

    public static function accessRequestsSnapshot(): array {
        $db = \metis_db();
        $requests_table = \Metis_Tables::get('people_access_requests');
        $people_table = \Metis_Tables::get('people');
        $roles_table = \Metis_Tables::get('people_roles');

        return [
            'rows' => $db->fetchAll(
                "SELECT ar.id, ar.request_code, ar.status, ar.reason, ar.decision_note,
                        ar.required_approvals, ar.approval_count, ar.requested_start_at, ar.requested_end_at, ar.expires_at, ar.created_at,
                        t.pid AS target_pid, t.display_name AS target_name,
                        r.role_key, r.role_name
                 FROM {$requests_table} ar
                 INNER JOIN {$people_table} t ON t.id = ar.target_person_id
                 INNER JOIN {$roles_table} r ON r.id = ar.role_id
                 ORDER BY ar.status='pending' DESC, ar.created_at DESC
                 LIMIT 200"
            ) ?: [],
            'metis_roles' => $db->fetchAll("SELECT role_key, role_name FROM {$roles_table} WHERE role_domain='metis' ORDER BY role_name ASC") ?: [],
        ];
    }

    public static function positionsSnapshot(): array {
        $rows = \metis_db()->fetchAll(
            "SELECT id, group_key, position_label, sort_order
             FROM " . \Metis_Tables::get('people_positions') . "
             WHERE is_active = 1
             ORDER BY group_key ASC, sort_order ASC, position_label ASC"
        ) ?: [];

        $grouped = [
            'board' => [],
            'staff' => [],
            'volunteer' => [],
        ];
        foreach ($rows as $row) {
            $group_key = \metis_key_clean((string) ($row['group_key'] ?? ''));
            if (!isset($grouped[$group_key])) {
                continue;
            }
            $grouped[$group_key][] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => (string) ($row['position_label'] ?? ''),
            ];
        }

        return [
            'grouped' => $grouped,
        ];
    }

    public static function roleDetailSnapshot(string $role_key, string $role_domain, bool $is_new): array {
        $db = \metis_db();
        $roles_table = \Metis_Tables::get('people_roles');
        $perms_table = \Metis_Tables::get('people_permissions');
        $role_perms_table = \Metis_Tables::get('people_role_perms');
        $user_roles_table = \Metis_Tables::get('people_user_roles');
        $role = null;

        if (!$is_new && $role_key !== '') {
            $row = $db->fetchOne(
                "SELECT * FROM {$roles_table} WHERE role_key = %s AND role_domain = %s LIMIT 1",
                [ $role_key, $role_domain ]
            );
            $role = is_array($row) ? $row : null;
        }

        $permissions_rows = $db->fetchAll("SELECT * FROM {$perms_table} ORDER BY module_slug ASC, action_key ASC") ?: [];
        $permissions_by_module = [];
        foreach ($permissions_rows as $permission_row) {
            $module_slug = (string) ($permission_row['module_slug'] ?? '');
            if (!isset($permissions_by_module[$module_slug])) {
                $permissions_by_module[$module_slug] = [];
            }
            $permissions_by_module[$module_slug][] = $permission_row;
        }

        $selected_permission_keys = [];
        $assigned_people = 0;
        $assigned_people_rows = [];
        if ($role) {
            $selected_permission_keys = $db->column(
                "SELECT p.permission_key
                 FROM {$role_perms_table} rp
                 INNER JOIN {$perms_table} p ON p.id = rp.permission_id
                 WHERE rp.role_id = %d AND rp.allow_access = 1",
                [ (int) $role['id'] ]
            ) ?: [];

            $assigned_people = (int) $db->scalar(
                "SELECT COUNT(*) FROM {$user_roles_table} WHERE role_id = %d",
                [ (int) $role['id'] ]
            );

            $people_table = \Metis_Tables::get('people');
            $assigned_people_rows = $db->fetchAll(
                "SELECT p.pid, p.first_name, p.last_name, p.display_name, p.email
                 FROM {$user_roles_table} ur
                 INNER JOIN {$people_table} p ON p.id = ur.person_id
                 WHERE ur.role_id = %d
                 ORDER BY p.display_name ASC, p.email ASC",
                [ (int) $role['id'] ]
            ) ?: [];
        }

        return [
            'role' => $role,
            'permissions_by_module' => $permissions_by_module,
            'selected_permission_keys' => $selected_permission_keys,
            'assigned_people' => $assigned_people,
            'assigned_people_rows' => $assigned_people_rows,
        ];
    }

    public static function workspaceSnapshot(int $sync_page = 1, int $security_page = 1): array {
        $db = \metis_db();
        $workspace_users_table = \Metis_Tables::get('people_workspace_users');
        $workspace_user_roles_table = \Metis_Tables::get('people_workspace_user_roles');
        $workspace_groups_table = \Metis_Tables::get('people_workspace_groups');
        $workspace_group_members_table = \Metis_Tables::get('people_workspace_group_members');
        $people_table = \Metis_Tables::get('people');
        $roles_table = \Metis_Tables::get('people_roles');

        $workspace_users = $db->fetchAll(
            "SELECT wu.*, p.pid AS linked_pid, p.display_name AS linked_name
             FROM {$workspace_users_table} wu
             LEFT JOIN {$people_table} p ON p.id = wu.person_id
             ORDER BY wu.display_name ASC, wu.primary_email ASC
             LIMIT 500"
        ) ?: [];

        $role_rows = $db->fetchAll(
            "SELECT role_key, role_name FROM {$roles_table} WHERE role_domain = 'workspace' ORDER BY role_name ASC"
        ) ?: [];
        $workspace_roles = ['' => 'No Admin Role'];
        foreach ($role_rows as $role_row) {
            $role_key = (string) ($role_row['role_key'] ?? '');
            if ($role_key === '') {
                continue;
            }
            $label = trim((string) ($role_row['role_name'] ?? $role_key));
            if ($label === '') {
                $label = $role_key;
            }
            $workspace_roles[$role_key] = $label;
        }

        $roles_by_user = [];
        $role_assign_rows = $db->fetchAll(
            "SELECT workspace_user_id, role_key FROM {$workspace_user_roles_table}"
        ) ?: [];
        foreach ($role_assign_rows as $role_assign_row) {
            $workspace_user_id = (int) ($role_assign_row['workspace_user_id'] ?? 0);
            $role_key = (string) ($role_assign_row['role_key'] ?? '');
            if ($workspace_user_id < 1 || $role_key === '') {
                continue;
            }
            if (!isset($roles_by_user[$workspace_user_id])) {
                $roles_by_user[$workspace_user_id] = [];
            }
            $roles_by_user[$workspace_user_id][] = $role_key;
        }

        $workspace_groups = $db->fetchAll(
            "SELECT wg.*,
                    (SELECT COUNT(*) FROM {$workspace_group_members_table} gm WHERE gm.group_id = wg.id) AS member_count
             FROM {$workspace_groups_table} wg
             ORDER BY wg.group_name ASC
             LIMIT 500"
        ) ?: [];

        $group_members = $db->fetchAll(
            "SELECT gm.group_id, gm.workspace_user_id, gm.member_role, wu.primary_email, wu.display_name
             FROM {$workspace_group_members_table} gm
             INNER JOIN {$workspace_users_table} wu ON wu.id = gm.workspace_user_id
             ORDER BY gm.group_id ASC, wu.display_name ASC"
        ) ?: [];
        $members_by_group = [];
        $groups_by_user = [];
        foreach ($group_members as $group_member_row) {
            $group_id = (int) ($group_member_row['group_id'] ?? 0);
            $workspace_user_id = (int) ($group_member_row['workspace_user_id'] ?? 0);
            if ($group_id < 1) {
                continue;
            }
            if (!isset($members_by_group[$group_id])) {
                $members_by_group[$group_id] = [];
            }
            $members_by_group[$group_id][] = $group_member_row;
            if ($workspace_user_id > 0) {
                if (!isset($groups_by_user[$workspace_user_id])) {
                    $groups_by_user[$workspace_user_id] = [];
                }
                $groups_by_user[$workspace_user_id][] = $group_id;
            }
        }

        $kpi_total_users = 0;
        foreach ($workspace_users as $workspace_user_row) {
            $workspace_meta = json_decode((string) ($workspace_user_row['metadata_json'] ?? ''), true);
            if (is_array($workspace_meta) && !empty($workspace_meta['ui_hidden'])) {
                continue;
            }
            $kpi_total_users++;
        }

        $activity = WorkspaceActivityService::payload($sync_page, $security_page);
        $kpi_suspended = (int) $db->scalar("SELECT COUNT(*) FROM {$workspace_users_table} WHERE is_suspended = 1");
        $kpi_pending_jobs = WorkspaceActivityService::countQueuedJobs();

        return [
            'workspace_users' => $workspace_users,
            'workspace_roles' => $workspace_roles,
            'roles_by_user' => $roles_by_user,
            'workspace_groups' => $workspace_groups,
            'members_by_group' => $members_by_group,
            'groups_by_user' => $groups_by_user,
            'activity' => $activity,
            'kpi_total_users' => $kpi_total_users,
            'kpi_suspended' => $kpi_suspended,
            'kpi_groups' => count($workspace_groups),
            'kpi_pending_jobs' => $kpi_pending_jobs,
        ];
    }

    public static function personSnapshot(string $pid, bool $is_new): array {
        $db = \metis_db();
        $people_table = \Metis_Tables::get('people');
        $roles_table = \Metis_Tables::get('people_roles');
        $user_roles_table = \Metis_Tables::get('people_user_roles');
        $person = null;

        if (!$is_new && $pid !== '') {
            $row = $db->fetchOne("SELECT * FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ]);
            $person = is_array($row) ? $row : null;
        }

        $roles_rows = $db->fetchAll("SELECT * FROM {$roles_table} ORDER BY role_domain ASC, role_name ASC") ?: [];
        $metis_roles = [];
        $stripe_roles = [ '' => 'No Stripe Access' ];
        $stripe_role_descriptions = [ '' => 'No Stripe dashboard access from this profile.' ];
        $workspace_roles = [ '' => 'No Workspace Role' ];
        foreach ($roles_rows as $role_row) {
            $role_domain = (string) ($role_row['role_domain'] ?? 'metis');
            $role_key = (string) ($role_row['role_key'] ?? '');
            $role_name = (string) ($role_row['role_name'] ?? $role_key);
            $role_description = (string) ($role_row['description'] ?? '');
            if ($role_key === '') {
                continue;
            }
            if ($role_domain === 'stripe') {
                $stripe_roles[$role_key] = $role_name;
                $stripe_role_descriptions[$role_key] = $role_description;
            } elseif ($role_domain === 'workspace') {
                $workspace_roles[$role_key] = $role_name;
            } elseif ($role_domain === 'metis') {
                $metis_roles[] = $role_row;
            }
        }
        if (count($stripe_roles) > 1) {
            $no_access = $stripe_roles[''];
            $no_access_desc = $stripe_role_descriptions[''];
            unset($stripe_roles[''], $stripe_role_descriptions['']);
            asort($stripe_roles, SORT_NATURAL | SORT_FLAG_CASE);
            $stripe_roles = [ '' => $no_access ] + $stripe_roles;
            $stripe_role_descriptions = [ '' => $no_access_desc ] + $stripe_role_descriptions;
        }
        if (count($workspace_roles) > 1) {
            $none = $workspace_roles[''];
            unset($workspace_roles['']);
            asort($workspace_roles, SORT_NATURAL | SORT_FLAG_CASE);
            $workspace_roles = [ '' => $none ] + $workspace_roles;
        }

        $selected_role_keys = [];
        $role_windows_by_key = [];
        if ($person) {
            $assigned_role_rows = $db->fetchAll(
                "SELECT r.role_key, ur.start_at, ur.end_at
                 FROM {$user_roles_table} ur
                 INNER JOIN {$roles_table} r ON r.id = ur.role_id
                 WHERE ur.person_id = %d",
                [ (int) $person['id'] ]
            ) ?: [];
            foreach ($assigned_role_rows as $assigned_role_row) {
                $role_key = (string) ($assigned_role_row['role_key'] ?? '');
                if ($role_key === '') {
                    continue;
                }
                $selected_role_keys[] = $role_key;
                $role_windows_by_key[$role_key] = [
                    'start_at' => (string) ($assigned_role_row['start_at'] ?? ''),
                    'end_at' => (string) ($assigned_role_row['end_at'] ?? ''),
                ];
            }
        }

        $person_email = (string) ($person['email'] ?? '');
        $first_name = (string) ($person['first_name'] ?? '');
        $last_name = (string) ($person['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);
        $display_name = (string) ($person['display_name'] ?? '');
        $avatar_name = trim((string) ($person['display_name'] ?? ''));
        if ($avatar_name === '') {
            $avatar_name = trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? ''));
        }
        $avatar_src = \metis_avatar_url($avatar_name !== '' ? $avatar_name : $person_email, (string) ($person['avatar_url'] ?? ''), 160, (string) ($person['pid'] ?? ''));
        $linked_donor_id = (string) ($person['linked_donor_id'] ?? '');
        $donor_profile_url = $linked_donor_id !== '' ? \metis_portal_url('donations', 'donor') . '?id=' . rawurlencode($linked_donor_id) : '';
        $linked_donor_name = '';
        if ($linked_donor_id !== '') {
            $contacts_table = \Metis_Tables::get('contacts');
            $linked_row = $db->fetchOne("SELECT first_name, last_name, email FROM {$contacts_table} WHERE did = %s LIMIT 1", [ $linked_donor_id ]);
            if ($linked_row) {
                $linked_donor_name = trim((string) ($linked_row['first_name'] ?? '') . ' ' . (string) ($linked_row['last_name'] ?? ''));
                if ($linked_donor_name === '') {
                    $linked_donor_name = (string) ($linked_row['email'] ?? '');
                }
            }
        }

        $current_stripe_role = (string) ($person['stripe_role'] ?? '');
        $known_stripe_roles = array_keys($stripe_roles);
        if ($current_stripe_role !== '' && !in_array($current_stripe_role, $known_stripe_roles, true)) {
            $stripe_roles[$current_stripe_role] = $current_stripe_role;
            $stripe_role_descriptions[$current_stripe_role] = 'Legacy Stripe role value stored on this profile.';
        }
        $current_workspace_role = (string) ($person['workspace_role'] ?? '');
        $known_workspace_roles = array_keys($workspace_roles);
        if ($current_workspace_role !== '' && !in_array($current_workspace_role, $known_workspace_roles, true)) {
            $workspace_roles[$current_workspace_role] = $current_workspace_role;
        }

        $positions_table = \Metis_Tables::get('people_positions');
        $position_rows = [];
        if (\metis_people_table_exists($positions_table)) {
            $position_rows = $db->fetchAll(
                "SELECT id, group_key, position_key, position_label, sort_order
                 FROM {$positions_table}
                 WHERE is_active = 1
                 ORDER BY group_key ASC, sort_order ASC, position_label ASC"
            ) ?: [];
        }
        $position_options = [
            'board' => [],
            'staff' => [],
            'volunteer' => [],
        ];
        foreach ($position_rows as $position_row) {
            $group_key = \metis_key_clean((string) ($position_row['group_key'] ?? ''));
            if (!isset($position_options[$group_key])) {
                continue;
            }
            $position_label = trim((string) ($position_row['position_label'] ?? ''));
            if ($position_label === '') {
                continue;
            }
            $position_options[$group_key][] = [
                'id' => (int) ($position_row['id'] ?? 0),
                'label' => $position_label,
            ];
        }
        $append_legacy_position = static function (array &$options, string $value): void {
            $needle = trim($value);
            if ($needle === '') {
                return;
            }
            foreach ($options as $option) {
                if (strtolower((string) ($option['label'] ?? '')) === strtolower($needle)) {
                    return;
                }
            }
            $options[] = [ 'id' => 0, 'label' => $needle ];
        };
        $append_legacy_position($position_options['board'], (string) ($person['board_position'] ?? ''));
        $append_legacy_position($position_options['staff'], (string) ($person['staff_position'] ?? ''));
        $append_legacy_position($position_options['volunteer'], (string) ($person['volunteer_position'] ?? ''));

        $email_notifications = !isset($person['email_notifications']) || (int) $person['email_notifications'] === 1;
        $requires_2fa = !empty($person['requires_2fa']);
        $mfa_method = (string) ($person['mfa_method'] ?? 'none');
        $totp_enabled = !empty($person['totp_enabled']);
        $passkey_enabled = !empty($person['passkey_enabled']);
        $has_metis_password = false;
        if ($person && !empty($person['id']) && \function_exists('metis_auth_find_user') && \function_exists('metis_auth_password_hash_for_authentication')) {
            $auth_user = \metis_auth_find_user('person_id', (int) $person['id']);
            if (is_array($auth_user)) {
                $has_metis_password = \metis_auth_password_hash_for_authentication($auth_user, $person) !== '';
            }
        }

        $notification_prefs = [];
        if (!empty($person['notification_prefs_json'])) {
            $decoded_notification_prefs = json_decode((string) $person['notification_prefs_json'], true);
            if (is_array($decoded_notification_prefs)) {
                $notification_prefs = $decoded_notification_prefs;
            }
        }

        $notification_events = [
            'contacts' => 'Contacts updates',
            'donations' => 'Donations activity',
            'people_access' => 'People access and role changes',
            'security' => 'Security alerts',
            'system' => 'System announcements',
        ];

        $effective_permissions = [];
        $person_activity_rows = [];
        $person_request_rows = [];
        $person_document_rows = [];
        $person_emergency_rows = [];
        $person_passkey_rows = [];
        $person_lifecycle_tasks = [];
        $permissions_catalog = [];
        $workspace_linked_user_id = 0;
        $workspace_linked_email = '';
        $workspace_linked_suspended = false;
        $workspace_linked_protected = false;
        $workspace_linked_roles = [];
        $workspace_linked_groups = [];
        $workspace_role_name_by_key = [];
        $workspace_role_description_by_key = [];
        $workspace_group_options = [];
        $drive_folder_id = '';
        $drive_folder_name = '';
        $drive_folder_url = '';
        $can_attach_drive_folder = false;
        $drive_shared_id = '';
        $drive_users_root_id = '';
        $drive_users_root_name = 'Users';
        $workspace_groups_table = \Metis_Tables::get('people_workspace_groups');

        if ($workspace_groups_table) {
            $workspace_group_options = $db->fetchAll(
                "SELECT group_email, group_name
                 FROM {$workspace_groups_table}
                 WHERE group_email IS NOT NULL AND group_email <> ''
                 ORDER BY group_name ASC, group_email ASC"
            ) ?: [];
        }

        if ($person && !empty($person['id']) && \function_exists('metis_drive_workspace_settings')) {
            $drive_cfg = \metis_drive_workspace_settings();
            if (!empty($drive_cfg['ok'])) {
                $drive_shared_id = (string) ($drive_cfg['shared_drive_id'] ?? '');
                if (\function_exists('metis_drive_get_users_root_folder')) {
                    $users_root = \metis_drive_get_users_root_folder($drive_cfg, false);
                    if (!empty($users_root['ok'])) {
                        $drive_users_root_id = (string) ($users_root['folder_id'] ?? '');
                        $drive_users_root_name = (string) ($users_root['folder_name'] ?? $drive_users_root_name);
                    }
                }
                $drive_user_folders_table = \Metis_Tables::get('drive_user_folders');
                if ($drive_user_folders_table && $drive_shared_id !== '') {
                    $folder_row = $db->fetchOne(
                        "SELECT folder_id, folder_name
                         FROM {$drive_user_folders_table}
                         WHERE drive_id = %s AND person_id = %d
                         LIMIT 1",
                        [ $drive_shared_id, (int) $person['id'] ]
                    );
                    if ($folder_row) {
                        $drive_folder_id = (string) ($folder_row['folder_id'] ?? '');
                        $drive_folder_name = (string) ($folder_row['folder_name'] ?? '');
                    }
                    if ($drive_folder_id === '' && \function_exists('metis_drive_find_or_create_user_folder') && \function_exists('metis_drive_ensure_schema')) {
                        \metis_drive_ensure_schema();
                        $auto_folder = \metis_drive_find_or_create_user_folder($drive_cfg, (int) $person['id'], true);
                        if (!empty($auto_folder['ok']) && !empty($auto_folder['folder_id'])) {
                            $drive_folder_id = (string) ($auto_folder['folder_id'] ?? '');
                            $drive_folder_name = (string) ($auto_folder['folder_name'] ?? '');
                            if (!empty($auto_folder['created']) && \function_exists('metis_drive_log_action')) {
                                \metis_drive_log_action($drive_cfg, 'create_user_folder', [
                                    'folder_id' => $drive_folder_id,
                                    'item_name' => $drive_folder_name,
                                    'item_type' => 'folder',
                                    'details' => [
                                        'person_id' => (int) $person['id'],
                                        'pid' => (string) ($person['pid'] ?? ''),
                                        'source' => 'people_view_autocreate',
                                    ],
                                ]);
                            }
                        }
                    }
                    if ($drive_folder_id !== '' && \function_exists('metis_portal_url')) {
                        $drive_folder_url = \metis_add_query_arg(
                            [ 'folder_id' => $drive_folder_id ],
                            \metis_portal_url('drive', 'dashboard')
                        );
                    }
                    $can_attach_drive_folder = true;
                }
            }
        }

        if ($person && !empty($person['id'])) {
            $perms_table = \Metis_Tables::get('people_permissions');
            $role_perms_table = \Metis_Tables::get('people_role_perms');
            $now = \metis_current_time('mysql');
            $effective_permissions = $db->fetchAll(
                "SELECT DISTINCT p.permission_key
                 FROM {$user_roles_table} ur
                 INNER JOIN {$role_perms_table} rp ON rp.role_id = ur.role_id AND rp.allow_access = 1
                 INNER JOIN {$perms_table} p ON p.id = rp.permission_id
                 WHERE ur.person_id = %d
                   AND (ur.start_at IS NULL OR ur.start_at <= %s)
                   AND (ur.end_at IS NULL OR ur.end_at >= %s)
                 ORDER BY p.permission_key ASC",
                [ (int) $person['id'], $now, $now ]
            ) ?: [];

            $activity_table = \Metis_Tables::get('people_activity');
            $requests_table = \Metis_Tables::get('people_access_requests');
            $documents_table = \Metis_Tables::get('people_documents');
            $emergency_table = \Metis_Tables::get('people_emergency_access');
            $passkeys_table = \Metis_Tables::get('people_passkeys');
            $tasks_table = \Metis_Tables::get('people_lifecycle_tasks');
            $person_activity_rows = $db->fetchAll(
                "SELECT activity_type, summary, created_at
                 FROM {$activity_table}
                 WHERE person_id = %d
                 ORDER BY created_at DESC
                 LIMIT 15",
                [ (int) $person['id'] ]
            ) ?: [];
            $person_request_rows = $db->fetchAll(
                "SELECT ar.id, ar.request_code, ar.status, ar.reason, ar.created_at, r.role_name
                 FROM {$requests_table} ar
                 INNER JOIN {$roles_table} r ON r.id = ar.role_id
                 WHERE ar.target_person_id = %d
                 ORDER BY ar.created_at DESC
                 LIMIT 15",
                [ (int) $person['id'] ]
            ) ?: [];
            $person_document_rows = $db->fetchAll(
                "SELECT id, doc_type, doc_label, storage_ref, remind_at, expires_at, lifecycle_status, created_at
                 FROM {$documents_table}
                 WHERE person_id = %d
                 ORDER BY created_at DESC
                 LIMIT 15",
                [ (int) $person['id'] ]
            ) ?: [];
            $person_emergency_rows = $db->fetchAll(
                "SELECT id, reason, starts_at, ends_at, revoked_at, created_at
                 FROM {$emergency_table}
                 WHERE person_id = %d
                 ORDER BY created_at DESC
                 LIMIT 15",
                [ (int) $person['id'] ]
            ) ?: [];
            $person_passkey_rows = $db->fetchAll(
                "SELECT id, label, created_at, last_used_at, revoked_at
                 FROM {$passkeys_table}
                 WHERE person_id = %d
                 ORDER BY created_at DESC
                 LIMIT 20",
                [ (int) $person['id'] ]
            ) ?: [];
            $person_lifecycle_tasks = $db->fetchAll(
                "SELECT id, phase, task_label, status, due_at, completed_at
                 FROM {$tasks_table}
                 WHERE person_id = %d
                 ORDER BY phase ASC, status='pending' DESC, due_at ASC, created_at DESC
                 LIMIT 60",
                [ (int) $person['id'] ]
            ) ?: [];
            $permissions_catalog = $db->fetchAll(
                "SELECT module_slug, action_key, permission_key
                 FROM {$perms_table}
                 ORDER BY module_slug ASC, action_key ASC"
            ) ?: [];

            $workspace_users_table = \Metis_Tables::get('people_workspace_users');
            $workspace_user_roles_table = \Metis_Tables::get('people_workspace_user_roles');
            $workspace_group_members_table = \Metis_Tables::get('people_workspace_group_members');
            $workspace_row = $db->fetchOne(
                "SELECT id, primary_email, is_suspended, is_protected
                 FROM {$workspace_users_table}
                 WHERE person_id = %d
                    OR (primary_email = %s AND %s <> '')
                 ORDER BY person_id = %d DESC
                 LIMIT 1",
                [
                    (int) $person['id'],
                    (string) ($person['workspace_email'] ?? ''),
                    (string) ($person['workspace_email'] ?? ''),
                    (int) $person['id'],
                ]
            );
            if ($workspace_row) {
                $workspace_linked_user_id = (int) ($workspace_row['id'] ?? 0);
                $workspace_linked_email = (string) ($workspace_row['primary_email'] ?? '');
                $workspace_linked_suspended = !empty($workspace_row['is_suspended']);
                $workspace_linked_protected = !empty($workspace_row['is_protected']);
                if ($workspace_linked_user_id > 0) {
                    $workspace_linked_roles = $db->column(
                        "SELECT role_key FROM {$workspace_user_roles_table} WHERE workspace_user_id = %d ORDER BY role_key ASC",
                        [ $workspace_linked_user_id ]
                    ) ?: [];
                    $workspace_linked_groups = $db->column(
                        "SELECT wg.group_email
                         FROM {$workspace_group_members_table} gm
                         INNER JOIN {$workspace_groups_table} wg ON wg.id = gm.group_id
                         WHERE gm.workspace_user_id = %d
                         ORDER BY wg.group_email ASC",
                        [ $workspace_linked_user_id ]
                    ) ?: [];
                }
            }
        }

        foreach ($roles_rows as $role_row) {
            $role_domain = (string) ($role_row['role_domain'] ?? '');
            if ($role_domain !== 'workspace') {
                continue;
            }
            $role_key = (string) ($role_row['role_key'] ?? '');
            if ($role_key === '') {
                continue;
            }
            $workspace_role_name_by_key[$role_key] = (string) ($role_row['role_name'] ?? $role_key);
            $workspace_role_description_by_key[$role_key] = (string) ($role_row['description'] ?? '');
        }

        return [
            'person' => $person,
            'roles_rows' => $roles_rows,
            'metis_roles' => $metis_roles,
            'stripe_roles' => $stripe_roles,
            'stripe_role_descriptions' => $stripe_role_descriptions,
            'workspace_roles' => $workspace_roles,
            'selected_role_keys' => $selected_role_keys,
            'role_windows_by_key' => $role_windows_by_key,
            'person_email' => $person_email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'full_name' => $full_name,
            'display_name' => $display_name,
            'avatar_src' => $avatar_src,
            'linked_donor_id' => $linked_donor_id,
            'donor_profile_url' => $donor_profile_url,
            'linked_donor_name' => $linked_donor_name,
            'position_options' => $position_options,
            'email_notifications' => $email_notifications,
            'requires_2fa' => $requires_2fa,
            'mfa_method' => $mfa_method,
            'totp_enabled' => $totp_enabled,
            'passkey_enabled' => $passkey_enabled,
            'has_metis_password' => $has_metis_password,
            'notification_prefs' => $notification_prefs,
            'notification_events' => $notification_events,
            'effective_permissions' => $effective_permissions,
            'person_activity_rows' => $person_activity_rows,
            'person_request_rows' => $person_request_rows,
            'person_document_rows' => $person_document_rows,
            'person_emergency_rows' => $person_emergency_rows,
            'person_passkey_rows' => $person_passkey_rows,
            'person_lifecycle_tasks' => $person_lifecycle_tasks,
            'permissions_catalog' => $permissions_catalog,
            'workspace_linked_user_id' => $workspace_linked_user_id,
            'workspace_linked_email' => $workspace_linked_email,
            'workspace_linked_suspended' => $workspace_linked_suspended,
            'workspace_linked_protected' => $workspace_linked_protected,
            'workspace_linked_roles' => $workspace_linked_roles,
            'workspace_linked_groups' => $workspace_linked_groups,
            'workspace_role_name_by_key' => $workspace_role_name_by_key,
            'workspace_role_description_by_key' => $workspace_role_description_by_key,
            'workspace_group_options' => $workspace_group_options,
            'drive_folder_id' => $drive_folder_id,
            'drive_folder_name' => $drive_folder_name,
            'drive_folder_url' => $drive_folder_url,
            'can_attach_drive_folder' => $can_attach_drive_folder,
            'drive_shared_id' => $drive_shared_id,
            'drive_users_root_id' => $drive_users_root_id,
            'drive_users_root_name' => $drive_users_root_name,
        ];
    }
}
