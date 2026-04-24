<?php
declare(strict_types=1);

namespace Metis\Hermes;

/**
 * HermesSecurityIntegration
 *
 * Registers all Hermes enclave policies with the Secure Enclave at boot.
 * Every Hermes operation that can mutate system state must have a policy
 * registered here before it can execute.
 *
 * Read-only operations do not require enclave policies — they pass through
 * HermesPermissionValidator directly.
 *
 * Policy naming convention: hermes.<domain>.<action>
 *
 * Called once from HermesModule::boot() after the enclave is available.
 */
final class HermesSecurityIntegration {

    /**
     * Registers all Hermes enclave policies.
     * Skips any policy that is already registered (idempotent).
     */
    public static function registerPolicies(): void {
        $enclave = \metis_security_enclave();

        foreach ( self::policies() as $policy ) {
            if ( ! $enclave->has_policy( $policy->operation ) ) {
                $enclave->register_policy( $policy );
            }
        }
    }

    // ------------------------------------------------------------------
    // Policy definitions
    // ------------------------------------------------------------------

    /**
     * @return \Metis_Security_Policy[]
     */
    private static function policies(): array {
        return [

            // ---- Core Hermes action execution (existing) ----------------
            new \Metis_Security_Policy(
                operation:              'hermes.action.execute',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             60,
                rate_window_seconds:    60
            ),

            // ---- Entity write operations (Chunk 3/5) --------------------
            new \Metis_Security_Policy(
                operation:              'hermes.entity.create',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             30,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.update',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             60,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.delete',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             20,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.set_status',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             60,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.archive',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             30,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.restore',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             30,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.assign_user',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             30,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.link',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             60,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.unlink',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             60,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.add_comment',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             60,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.attach_file',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             30,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.remove_attachment',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             30,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.bulk_create',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             5,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.bulk_update',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             5,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.entity.bulk_delete',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             3,
                rate_window_seconds:    60
            ),

            // ---- Notify operations (Chunk 5) ----------------------------
            new \Metis_Security_Policy(
                operation:              'hermes.notify.user',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             20,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.notify.group',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             10,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.notify.webhook',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             10,
                rate_window_seconds:    60
            ),

            // ---- System operations (Chunk 5) ----------------------------
            new \Metis_Security_Policy(
                operation:              'hermes.system.restore',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             3,
                rate_window_seconds:    300
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.system.clear_cache',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             10,
                rate_window_seconds:    60
            ),

            new \Metis_Security_Policy(
                operation:              'hermes.command.run_backup',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             10,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.command.aut_self_heal',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             5,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.command.send_announcement',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             10,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.command.create_user',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             10,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.command.offboard_user',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             10,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.command.manage_user_roles',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             15,
                rate_window_seconds:    60
            ),
            // ---- User operations (Chunk 5) ------------------------------
            new \Metis_Security_Policy(
                operation:              'hermes.user.update',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             30,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.user.enable',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             20,
                rate_window_seconds:    60
            ),

            // ---- Module lifecycle (Chunk 5) -----------------------------
            new \Metis_Security_Policy(
                operation:              'hermes.module.enable',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             5,
                rate_window_seconds:    60
            ),
            new \Metis_Security_Policy(
                operation:              'hermes.module.disable',
                module:                 'hermes',
                permission:             'edit',
                require_authentication: true,
                require_session:        true,
                require_nonce:          true,
                nonce_key:              'metis_ajax:metis_hermes_execute_action',
                rate_limit:             5,
                rate_window_seconds:    60
            ),

        ];
    }
}
