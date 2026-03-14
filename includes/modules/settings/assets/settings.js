document.addEventListener('DOMContentLoaded', function () {
    function upsertCardDavNotice(form, notice) {
        if (!form) return;

        const existing = form.querySelector('[data-carddav-token-notice]');
        if (existing) {
            existing.remove();
        }

        if (!notice || !notice.token) {
            return;
        }

        const usernameEl = Array.from(form.querySelectorAll('label')).find(function (label) {
            return String(label.textContent || '').trim().toLowerCase() === 'username';
        });
        const afterNode = usernameEl ? usernameEl.closest('.mw-field') : null;
        const noticeEl = document.createElement('div');
        noticeEl.className = 'mw-callout mw-callout-warning';
        noticeEl.setAttribute('data-carddav-token-notice', '1');
        noticeEl.innerHTML =
            'New CardDAV token for <strong>' +
            String(notice.label || 'CardDAV device')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;') +
            '</strong>: <code>' +
            String(notice.token)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;') +
            '</code>';

        if (afterNode && afterNode.parentNode) {
            afterNode.parentNode.insertBefore(noticeEl, afterNode.nextSibling);
        } else {
            form.prepend(noticeEl);
        }
    }

    const showToast = Metis.util.notify;

    document.querySelectorAll('[data-copy-target]').forEach(function (copyBtn) {
        copyBtn.addEventListener('click', function () {
            const targetId = String(copyBtn.getAttribute('data-copy-target') || '');
            const valueEl = targetId ? document.getElementById(targetId) : null;
            if (!valueEl) return;

            const value = String(valueEl.textContent || '').trim();
            navigator.clipboard.writeText(value).then(() => {
                copyBtn.textContent = 'Copied!';
                setTimeout(() => { copyBtn.textContent = 'Copy'; }, 2000);
            }).catch(() => {});
        });
    });

    document.querySelectorAll('[data-theme-color-input]').forEach(function (picker) {
        picker.addEventListener('input', function () {
            const targetId = String(picker.getAttribute('data-theme-color-input') || '');
            const textInput = targetId ? document.getElementById(targetId) : null;
            if (textInput) {
                textInput.value = String(picker.value || '').toUpperCase();
            }
        });
    });

    document.querySelectorAll('[data-theme-color-text]').forEach(function (textInput) {
        textInput.addEventListener('input', function () {
            const value = String(textInput.value || '').trim();
            const row = textInput.closest('.metis-theme-input-row');
            const picker = row ? row.querySelector('[data-theme-color-input]') : null;
            if (picker && /^#[0-9a-fA-F]{6}$/.test(value)) {
                picker.value = value;
            }
        });
    });

    document.addEventListener('click', function (event) {
        const moveBtn = event.target.closest('[data-menu-move]');
        if (!moveBtn) return;

        const item = moveBtn.closest('[data-menu-order-item]');
        const root = moveBtn.closest('[data-menu-order-root]');
        if (!item || !root) return;

        const direction = String(moveBtn.getAttribute('data-menu-move') || '');
        if (direction === 'up' && item.previousElementSibling) {
            root.insertBefore(item, item.previousElementSibling);
        }
        if (direction === 'down' && item.nextElementSibling) {
            root.insertBefore(item.nextElementSibling, item);
        }
    });

    document.querySelectorAll('[data-metis-settings-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const sectionMatch = window.location.pathname.match(/\/settings\/([^/]+)\/?$/);
            const section = sectionMatch && sectionMatch[1] ? String(sectionMatch[1]) : 'general';
            const submitBtn = event.submitter || form.querySelector('button[type="submit"]');
            const originalLabel = submitBtn ? submitBtn.textContent : '';
            const body = new FormData(form);
            if (event.submitter && event.submitter.name) {
                body.append(String(event.submitter.name), String(event.submitter.value || '1'));
            }
            const action = 'metis_settings_save_section';
            body.append('action', action);
            body.append('settings_section', section);
            if (!body.has('nonce') && window.metisAjax && window.metisAjax.nonce) {
                body.append('nonce', window.metisAjax.nonce);
            }
            if (!body.has('metis_action_nonce') && window.metisAjax && window.metisAjax.nonce) {
                body.append('metis_action_nonce', Metis.ajax.nonceFor(action, window.metisAjax.nonce));
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Settings saved.'));
                if (section === 'api' && data.carddav_token_notice && data.carddav_token_notice.token) {
                    upsertCardDavNotice(form, data.carddav_token_notice);
                    return;
                }
                window.setTimeout(function () {
                    if (data.redirect_url) {
                        window.location.assign(String(data.redirect_url));
                        return;
                    }
                    window.location.reload();
                }, 600);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Save failed.');
            }).finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel;
                }
            });
        });
    });

    const driveSyncBtn = document.querySelector('[data-drive-sync-now]');
    if (driveSyncBtn) {
        driveSyncBtn.addEventListener('click', function () {
            const action = 'metis_drive_sync_now';
            const statusEl = document.querySelector('[data-drive-sync-status]');
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = driveSyncBtn.textContent;
            driveSyncBtn.disabled = true;
            driveSyncBtn.textContent = 'Syncing...';
            if (statusEl) {
                statusEl.textContent = 'Running Drive sync...';
            }

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                const message = String(data.message || 'Drive sync finished.');
                showToast('success', message);
                if (statusEl) {
                    statusEl.textContent = message;
                }
            }).catch(function (error) {
                const message = error && error.message ? error.message : 'Drive sync failed.';
                showToast('error', message);
                if (statusEl) {
                    statusEl.textContent = message;
                }
            }).finally(function () {
                driveSyncBtn.disabled = false;
                driveSyncBtn.textContent = originalLabel;
            });
        });
    }

    const backupRunBtn = document.querySelector('[data-backup-run-now]');
    if (backupRunBtn) {
        backupRunBtn.addEventListener('click', function () {
            const action = 'metis_backup_run_now';
            const body = new FormData();
            const statusEl = document.querySelector('[data-backup-action-status]');
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = backupRunBtn.textContent;
            backupRunBtn.disabled = true;
            backupRunBtn.textContent = 'Running...';
            if (statusEl) statusEl.textContent = 'Creating backup...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                const message = String(data.message || 'Backup completed.');
                showToast('success', message);
                if (statusEl) statusEl.textContent = message;
                window.setTimeout(function () {
                    window.location.reload();
                }, 700);
            }).catch(function (error) {
                const message = error && error.message ? error.message : 'Backup failed.';
                showToast('error', message);
                if (statusEl) statusEl.textContent = message;
            }).finally(function () {
                backupRunBtn.disabled = false;
                backupRunBtn.textContent = originalLabel;
            });
        });
    }

    document.querySelectorAll('[data-backup-restore-run]').forEach(function (button) {
        button.addEventListener('click', function () {
            const runUuid = String(button.getAttribute('data-backup-restore-run') || '').trim();
            if (!runUuid) return;
            if (!window.confirm('Restore backup ' + runUuid + '? This will overwrite the current database and files.')) {
                return;
            }

            const action = 'metis_backup_restore_run';
            const body = new FormData();
            body.append('action', action);
            body.append('run_uuid', runUuid);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Restoring...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Restore completed.'));
                window.setTimeout(function () {
                    window.location.reload();
                }, 700);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Restore failed.');
            }).finally(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });
        });
    });

    function postSchedulerUpdate(payload) {
        const action = 'metis_scheduler_update_task_settings';
        const body = new FormData();
        body.append('action', action);
        Object.keys(payload).forEach(function (key) {
            body.append(key, String(payload[key]));
        });
        body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
        body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));
        return Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.');
    }

    document.querySelectorAll('[data-cron-task-row]').forEach(function (row) {
        row.addEventListener('dblclick', function (event) {
            if (!event.target.closest('[data-cron-task-row]')) return;
            if (event.target.closest('input, button, textarea, select, label, a')) return;

            const taskSlug = String(row.getAttribute('data-cron-task-row') || '').trim();
            if (!taskSlug) return;

            const isEnabled = row.getAttribute('data-cron-task-enabled') === '1';
            row.classList.add('is-saving');

            postSchedulerUpdate({
                task_slug: taskSlug,
                enabled: isEnabled ? '0' : '1',
            }).then(function (data) {
                const enabled = !!(data && data.task && data.task.enabled);
                row.setAttribute('data-cron-task-enabled', enabled ? '1' : '0');
                row.classList.toggle('is-enabled', enabled);
                row.classList.toggle('is-disabled', !enabled);
                showToast('success', enabled ? 'Task enabled.' : 'Task disabled.');
                window.setTimeout(function () {
                    window.location.reload();
                }, 350);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Task update failed.');
            }).finally(function () {
                row.classList.remove('is-saving');
            });
        });
    });

    document.querySelectorAll('[data-cron-task-interval]').forEach(function (input) {
        let lastSaved = String(input.value || '').trim();

        function saveInterval() {
            const taskSlug = String(input.getAttribute('data-cron-task-interval') || '').trim();
            const nextValue = String(input.value || '').trim();
            if (!taskSlug || nextValue === '' || nextValue === lastSaved) return;

            const row = input.closest('[data-cron-task-row]');
            if (row) row.classList.add('is-saving');

            postSchedulerUpdate({
                task_slug: taskSlug,
                interval_minutes: nextValue,
            }).then(function (data) {
                if (data && data.task && data.task.interval_minutes) {
                    input.value = String(data.task.interval_minutes);
                    lastSaved = String(data.task.interval_minutes);
                } else {
                    lastSaved = nextValue;
                }
                showToast('success', 'Cadence saved.');
            }).catch(function (error) {
                input.value = lastSaved;
                showToast('error', error && error.message ? error.message : 'Cadence update failed.');
            }).finally(function () {
                if (row) row.classList.remove('is-saving');
            });
        }

        input.addEventListener('change', saveInterval);
        input.addEventListener('blur', saveInterval);
    });

    document.querySelectorAll('[data-cron-run-now]').forEach(function (button) {
        button.addEventListener('click', function () {
            const taskSlug = String(button.getAttribute('data-cron-run-now') || '').trim();
            if (!taskSlug) return;

            const action = 'metis_scheduler_run_task_now';
            const body = new FormData();
            body.append('action', action);
            body.append('task_slug', taskSlug);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Running...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Task finished.'));
                window.setTimeout(function () {
                    window.location.reload();
                }, 500);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Task run failed.');
            }).finally(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });
        });
    });

    const buildBaselineBtn = document.querySelector('[data-integrity-build-baseline]');
    if (buildBaselineBtn) {
        buildBaselineBtn.addEventListener('click', function () {
            const action = 'metis_scheduler_build_integrity_baseline';
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = buildBaselineBtn.textContent;
            buildBaselineBtn.disabled = true;
            buildBaselineBtn.textContent = 'Building...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Baseline built.'));
                window.setTimeout(function () {
                    window.location.reload();
                }, 500);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Baseline build failed.');
            }).finally(function () {
                buildBaselineBtn.disabled = false;
                buildBaselineBtn.textContent = originalLabel;
            });
        });
    }

    const releaseRefreshBtn = document.querySelector('[data-release-check-updates]');
    if (releaseRefreshBtn) {
        releaseRefreshBtn.addEventListener('click', function () {
            const action = 'metis_release_check_updates';
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = releaseRefreshBtn.textContent;
            releaseRefreshBtn.disabled = true;
            releaseRefreshBtn.textContent = 'Refreshing...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Release metadata refreshed.'));
                window.setTimeout(function () {
                    window.location.reload();
                }, 500);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Release refresh failed.');
            }).finally(function () {
                releaseRefreshBtn.disabled = false;
                releaseRefreshBtn.textContent = originalLabel;
            });
        });
    }

    document.querySelectorAll('[data-release-apply-tag]').forEach(function (button) {
        button.addEventListener('click', function () {
            const tag = String(button.getAttribute('data-release-apply-tag') || '').trim();
            if (!tag) return;
            if (!window.confirm('Apply trusted release ' + tag + '? Metis will run an integrity check and create a backup first.')) {
                return;
            }

            const action = 'metis_release_apply';
            const body = new FormData();
            body.append('action', action);
            body.append('tag', tag);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Applying...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Release applied.'));
                window.setTimeout(function () {
                    window.location.reload();
                }, 900);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Release update failed.');
            }).finally(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });
        });
    });

    const releaseRollbackBtn = document.querySelector('[data-release-rollback]');
    if (releaseRollbackBtn) {
        releaseRollbackBtn.addEventListener('click', function () {
            if (!window.confirm('Rollback to the previous trusted release? Metis will create a backup first.')) {
                return;
            }

            const action = 'metis_release_rollback';
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
            body.append('metis_action_nonce', Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || ''));

            const originalLabel = releaseRollbackBtn.textContent;
            releaseRollbackBtn.disabled = true;
            releaseRollbackBtn.textContent = 'Rolling Back...';

            Metis.request.postForm(window.metisAjax || null, action, body, 'Settings AJAX not configured.').then(function (data) {
                showToast('success', String(data.message || 'Rollback completed.'));
                window.setTimeout(function () {
                    window.location.reload();
                }, 900);
            }).catch(function (error) {
                showToast('error', error && error.message ? error.message : 'Rollback failed.');
            }).finally(function () {
                releaseRollbackBtn.disabled = false;
                releaseRollbackBtn.textContent = originalLabel;
            });
        });
    }
});
