# API and AJAX Endpoints

Metis routes most interactive behavior through the shared AJAX endpoint exposed by `includes/core/ajax.php` and routed by `includes/core/router.php`.

## Board

- `POST /api/ajax` with `action=metis_board_delete_agenda_template`
- `POST /api/ajax` with `action=metis_board_delete_decision_template`
- `POST /api/ajax` with `action=metis_board_drive_create_folder`
- `POST /api/ajax` with `action=metis_board_drive_link_document`
- `POST /api/ajax` with `action=metis_board_drive_list`
- `POST /api/ajax` with `action=metis_board_drive_set_meeting_folder`
- `POST /api/ajax` with `action=metis_board_drive_unlink_document`
- `POST /api/ajax` with `action=metis_board_drive_upload`
- `POST /api/ajax` with `action=metis_board_generate_packet_pdf`
- `POST /api/ajax` with `action=metis_board_get_meeting_documents`
- `POST /api/ajax` with `action=metis_board_get_workflow_templates`
- `POST /api/ajax` with `action=metis_board_prepare_meeting_workspace`
- `POST /api/ajax` with `action=metis_board_save_action_item`
- `POST /api/ajax` with `action=metis_board_save_agenda_template`
- `POST /api/ajax` with `action=metis_board_save_announcement`
- `POST /api/ajax` with `action=metis_board_save_committee`
- `POST /api/ajax` with `action=metis_board_save_decision`
- `POST /api/ajax` with `action=metis_board_save_decision_template`
- `POST /api/ajax` with `action=metis_board_save_meeting`
- `POST /api/ajax` with `action=metis_board_sync_decision_points`
- `POST /api/ajax` with `action=metis_board_update_decision`
- `POST /api/ajax` with `action=metis_board_update_meeting_detail`
- `POST /api/ajax` with `action=metis_board_upsert_attendance`

## Calendar

- `POST /api/ajax` with `action=metis_calendar_delete_event`
- `POST /api/ajax` with `action=metis_calendar_list_events`
- `POST /api/ajax` with `action=metis_calendar_save_event`
- `POST /api/ajax` with `action=metis_calendar_sync_worker`

## Contacts

- `POST /api/ajax` with `action=metis_contact_add_additional_email`
- `POST /api/ajax` with `action=metis_contact_add_newsletter`
- `POST /api/ajax` with `action=metis_contact_add_note`
- `POST /api/ajax` with `action=metis_contact_detail_save`
- `POST /api/ajax` with `action=metis_contact_inline_update`
- `POST /api/ajax` with `action=metis_contact_remove_additional_email`
- `POST /api/ajax` with `action=metis_contact_remove_newsletter`
- `POST /api/ajax` with `action=metis_contact_remove_relationship`
- `POST /api/ajax` with `action=metis_contacts_cleanup_merge_notes`
- `POST /api/ajax` with `action=metis_contacts_merge_duplicates`
- `POST /api/ajax` with `action=metis_contacts_save`

## Drive

- `POST /api/ajax` with `action=metis_drive_copy_item`
- `POST /api/ajax` with `action=metis_drive_create_folder`
- `POST /api/ajax` with `action=metis_drive_create_google_file`
- `POST /api/ajax` with `action=metis_drive_list`
- `POST /api/ajax` with `action=metis_drive_move_item`
- `POST /api/ajax` with `action=metis_drive_my_folder`
- `POST /api/ajax` with `action=metis_drive_rename`
- `POST /api/ajax` with `action=metis_drive_sync_worker`
- `POST /api/ajax` with `action=metis_drive_trash`
- `POST /api/ajax` with `action=metis_drive_tree_children`
- `POST /api/ajax` with `action=metis_drive_upload_file`

## Forms

- `POST /api/ajax` with `action=metis_forms_delete`
- `POST /api/ajax` with `action=metis_forms_duplicate`
- `POST /api/ajax` with `action=metis_forms_dynamic_options`
- `POST /api/ajax` with `action=metis_forms_entries`
- `POST /api/ajax` with `action=metis_forms_export`
- `POST /api/ajax` with `action=metis_forms_get`
- `POST /api/ajax` with `action=metis_forms_list`
- `POST /api/ajax` with `action=metis_forms_publish`
- `POST /api/ajax` with `action=metis_forms_save`

## Grandy's Stash

- `POST /api/ajax` with `action=metis_grandys_stash_assign_item`
- `POST /api/ajax` with `action=metis_grandys_stash_contact_search`
- `POST /api/ajax` with `action=metis_grandys_stash_save_case`
- `POST /api/ajax` with `action=metis_grandys_stash_save_item`
- `POST /api/ajax` with `action=metis_grandys_stash_state`

## Newsletter

- `POST /api/ajax` with `action=metis_newsletter_archive_campaign`
- `POST /api/ajax` with `action=metis_newsletter_campaign_status`
- `POST /api/ajax` with `action=metis_newsletter_delete_campaign`
- `POST /api/ajax` with `action=metis_newsletter_giphy_search`
- `POST /api/ajax` with `action=metis_newsletter_klipy_search`
- `POST /api/ajax` with `action=metis_newsletter_queue_campaign`
- `POST /api/ajax` with `action=metis_newsletter_record_event`
- `POST /api/ajax` with `action=metis_newsletter_run_queue`
- `POST /api/ajax` with `action=metis_newsletter_save_campaign`
- `POST /api/ajax` with `action=metis_newsletter_save_defaults`
- `POST /api/ajax` with `action=metis_newsletter_save_list`
- `POST /api/ajax` with `action=metis_newsletter_save_template`
- `POST /api/ajax` with `action=metis_newsletter_search_contacts`
- `POST /api/ajax` with `action=metis_newsletter_sync_google_usage`
- `POST /api/ajax` with `action=metis_newsletter_test_send_campaign`
- `POST /api/ajax` with `action=metis_newsletter_upload_attachment`
- `POST /api/ajax` with `action=metis_newsletter_upload_image`
- `POST /api/ajax` with `action=metis_newsletter_upsert_subscription`

## People

- `POST /api/ajax` with `action=metis_people_add_document`
- `POST /api/ajax` with `action=metis_people_add_lifecycle_task`
- `POST /api/ajax` with `action=metis_people_apply_template`
- `POST /api/ajax` with `action=metis_people_attach_drive_folder`
- `POST /api/ajax` with `action=metis_people_attach_drive_folder_selection`
- `POST /api/ajax` with `action=metis_people_begin_passkey_registration`
- `POST /api/ajax` with `action=metis_people_bulk_role_action`
- `POST /api/ajax` with `action=metis_people_bulk_stripe_role_action`
- `POST /api/ajax` with `action=metis_people_bulk_workspace_group_action`
- `POST /api/ajax` with `action=metis_people_complete_lifecycle_task`
- `POST /api/ajax` with `action=metis_people_complete_passkey_registration`
- `POST /api/ajax` with `action=metis_people_create_access_request`
- `POST /api/ajax` with `action=metis_people_delete_document`
- `POST /api/ajax` with `action=metis_people_drive_folder_picker`
- `POST /api/ajax` with `action=metis_people_generate_totp_secret`
- `POST /api/ajax` with `action=metis_people_grant_emergency_access`
- `POST /api/ajax` with `action=metis_people_offboard_person`
- `POST /api/ajax` with `action=metis_people_reset_mfa`
- `POST /api/ajax` with `action=metis_people_resolve_access_request`
- `POST /api/ajax` with `action=metis_people_revoke_emergency_access`
- `POST /api/ajax` with `action=metis_people_revoke_passkey`
- `POST /api/ajax` with `action=metis_people_save_avatar`
- `POST /api/ajax` with `action=metis_people_save_person`
- `POST /api/ajax` with `action=metis_people_save_role`
- `POST /api/ajax` with `action=metis_people_save_template`
- `POST /api/ajax` with `action=metis_people_search_donor`
- `POST /api/ajax` with `action=metis_people_search_person`
- `POST /api/ajax` with `action=metis_people_simulate_permission`
- `POST /api/ajax` with `action=metis_people_verify_totp_secret`
- `POST /api/ajax` with `action=metis_people_workspace_add_group_member`
- `POST /api/ajax` with `action=metis_people_workspace_delete_group`
- `POST /api/ajax` with `action=metis_people_workspace_full_sync_directory`
- `POST /api/ajax` with `action=metis_people_workspace_get_group_members_matrix`
- `POST /api/ajax` with `action=metis_people_workspace_get_group_permissions`
- `POST /api/ajax` with `action=metis_people_workspace_get_role_map`
- `POST /api/ajax` with `action=metis_people_workspace_import_directory_users`
- `POST /api/ajax` with `action=metis_people_workspace_inspect_user_attributes`
- `POST /api/ajax` with `action=metis_people_workspace_process_queue`
- `POST /api/ajax` with `action=metis_people_workspace_run_security_action`
- `POST /api/ajax` with `action=metis_people_workspace_save_group`
- `POST /api/ajax` with `action=metis_people_workspace_save_group_members_bulk`
- `POST /api/ajax` with `action=metis_people_workspace_save_group_permissions`
- `POST /api/ajax` with `action=metis_people_workspace_save_user`

## Profile

- `POST /api/ajax` with `action=metis_profile_begin_passkey_registration`
- `POST /api/ajax` with `action=metis_profile_change_workspace_password`
- `POST /api/ajax` with `action=metis_profile_complete_passkey_registration`
- `POST /api/ajax` with `action=metis_profile_generate_totp_secret`
- `POST /api/ajax` with `action=metis_profile_get`
- `POST /api/ajax` with `action=metis_profile_revoke_passkey`
- `POST /api/ajax` with `action=metis_profile_save`
- `POST /api/ajax` with `action=metis_profile_save_avatar`
- `POST /api/ajax` with `action=metis_profile_verify_totp_secret`

## Settings

- `POST /api/ajax` with `action=metis_backup_restore_run`
- `POST /api/ajax` with `action=metis_backup_run_now`
- `POST /api/ajax` with `action=metis_drive_sync_now`
- `POST /api/ajax` with `action=metis_release_apply`
- `POST /api/ajax` with `action=metis_release_check_updates`
- `POST /api/ajax` with `action=metis_release_rollback`
- `POST /api/ajax` with `action=metis_scheduler_build_integrity_baseline`
- `POST /api/ajax` with `action=metis_scheduler_run_task_now`
- `POST /api/ajax` with `action=metis_scheduler_update_task_settings`
- `POST /api/ajax` with `action=metis_settings_save_section`

