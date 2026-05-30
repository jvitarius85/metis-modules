(function () {
  const adminBoot = parseJsonScript('metis-forms-admin-data');
  const builderRoot = document.querySelector('[data-metis-forms-builder="1"]');
  const entriesRoot = document.querySelector('[data-metis-forms-entries="1"]');
  const publicRoot = document.querySelector('[data-metis-forms-public="1"]');
  const publicBoot = parseJsonScript('metis-forms-public-data');
  const formsRichSelectionStore = {};
  const formsRichColorOptions = [
    {value: '#1f2740', label: 'Default', color: '#1f2740'},
    {value: '#4a5ec9', label: 'Accent', color: '#4a5ec9'},
    {value: '#0f766e', label: 'Teal', color: '#0f766e'},
    {value: '#b45309', label: 'Amber', color: '#b45309'},
    {value: '#b91c1c', label: 'Red', color: '#b91c1c'}
  ];

  if (builderRoot && adminBoot && adminBoot.mode === 'builder') {
    initBuilder(builderRoot, adminBoot);
  }

  if (entriesRoot && adminBoot && adminBoot.mode === 'entries') {
    initEntries(entriesRoot, adminBoot);
  }

  if (publicRoot && publicBoot && publicBoot.mode === 'public') {
    initPublic(publicRoot, publicBoot);
  }

  function initBuilder(root, boot) {
    const ajax = window.metisFormsAjax || {};
    const coreAjax = window.metisAjax || {};
    const options = Object.assign(
      {
        payment_defaults: {fee_percent: 2.9, fee_fixed: 0.3, cover_fees_label: 'I would like to cover the processing fees.'},
        field_types: [],
        users: [],
        roles: [],
        modules: [],
        module_flows: {},
        campaigns: [],
        datasets: {}
      },
      boot.options || {}
    );

    const state = {
      form: hydrateForm(clone(boot.form || {}), options.payment_defaults || {}),
      selection: null,
      step: ['build', 'settings', 'publish'].includes(String(boot.default_step || 'build')) ? String(boot.default_step || 'build') : 'build',
      editorSection: 'basics',
      pickerOpen: false,
      drag: null,
      saving: false
    };

    const actions = {
      save: 'metis_forms_save',
      publish: 'metis_forms_publish',
      duplicate: 'metis_forms_duplicate',
      del: 'metis_forms_delete',
      get: 'metis_forms_get'
    };

    render();
    document.addEventListener('click', handleDocumentClick);

    root.addEventListener('click', async (event) => {
      const richToggle = event.target.closest('[data-rich-toggle="menu"]');
      if (richToggle) {
        event.preventDefault();
        event.stopPropagation();
        const dropdown = richToggle.closest('.metis-se-rich-dropdown');
        root.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach((node) => {
          if (node !== dropdown) {
            node.classList.remove('is-open');
          }
        });
        if (dropdown) {
          dropdown.classList.toggle('is-open');
        }
        return;
      }

      const richAction = event.target.closest('[data-rich-action]');
      if (richAction) {
        event.preventDefault();
        const editor = findRichEditorForControl(richAction);
        if (editor) {
          applyRichAction(
            editor,
            String(richAction.dataset.richAction || ''),
            String(richAction.dataset.richValue || ''),
            String(richAction.dataset.richColor || ''),
            state
          );
        }
        closeRichMenus(root);
        return;
      }

      const richButton = event.target.closest('[data-rich-cmd]');
      if (richButton) {
        event.preventDefault();
        const editor = findRichEditorForControl(richButton);
        if (editor) {
          applyRichCommand(editor, String(richButton.dataset.richCmd || ''), undefined, state);
        }
        closeRichMenus(root);
        return;
      }

      const stepButton = event.target.closest('[data-step]');
      if (stepButton) {
        state.step = String(stepButton.dataset.step || 'build');
        render();
        return;
      }

      if (event.target.closest('[data-open-picker]')) {
        state.pickerOpen = true;
        render();
        return;
      }

      if (event.target.closest('[data-close-picker]') || event.target.matches('[data-picker-backdrop]')) {
        state.pickerOpen = false;
        render();
        return;
      }

      const addField = event.target.closest('[data-add-field]');
      if (addField) {
        const type = String(addField.dataset.addField || 'text');
        if (type === 'payment' && state.form.schema.some((field) => field.type === 'payment')) {
          notify('error', 'This form already has a payment field.');
          return;
        }
        const field = createField(type, options.payment_defaults || {});
        state.form.schema.push(field);
        state.selection = {kind: 'field', fieldId: field.id};
        state.editorSection = type === 'payment' ? 'payment' : type === 'repeater' ? 'repeater' : fieldUsesChoices(type) ? 'choices' : 'basics';
        state.pickerOpen = false;
        render();
        return;
      }

      const selectField = event.target.closest('[data-select-field]');
      if (selectField) {
        state.selection = {kind: 'field', fieldId: String(selectField.dataset.selectField || '')};
        state.editorSection = 'basics';
        render();
        return;
      }

      const selectSubfield = event.target.closest('[data-select-subfield]');
      if (selectSubfield) {
        state.selection = {
          kind: 'subfield',
          fieldId: String(selectSubfield.dataset.parentField || ''),
          subfieldId: String(selectSubfield.dataset.selectSubfield || '')
        };
        state.editorSection = 'basics';
        render();
        return;
      }

      const accordion = event.target.closest('[data-editor-section]');
      if (accordion) {
        state.editorSection = String(accordion.dataset.editorSection || 'basics');
        render();
        return;
      }

      const toggleWidth = event.target.closest('[data-toggle-width]');
      if (toggleWidth) {
        const target = lookupSelectionTarget(toggleWidth.dataset.toggleWidth || '', state.form.schema);
        if (target) {
          target.item.width = target.item.type === 'payment' ? 'full' : nextWidth(target.item.width);
          refreshTitle();
          renderFieldList();
          renderEditor();
          renderPreview();
        }
        return;
      }

      const moveNode = event.target.closest('[data-move-node]');
      if (moveNode) {
        const target = lookupSelectionTarget(moveNode.dataset.moveNode || '', state.form.schema);
        if (target) {
          moveInCollection(target.collection, target.index, Number(moveNode.dataset.moveDelta || 0));
          render();
        }
        return;
      }

      if (event.target.closest('[data-remove-selected]')) {
        removeSelectedNode(state);
        render();
        return;
      }

      if (event.target.closest('[data-back-to-repeater]')) {
        if (state.selection && state.selection.kind === 'subfield') {
          state.selection = {kind: 'field', fieldId: state.selection.fieldId};
          state.editorSection = 'repeater';
          render();
        }
        return;
      }

      if (event.target.closest('[data-add-condition]')) {
        const node = getSelectedNode(state.form.schema, state.selection);
        if (!node) return;
        node.item.conditions = Array.isArray(node.item.conditions) ? node.item.conditions : [];
        node.item.conditions.push({field: '', operator: 'equals', value: ''});
        renderEditor();
        return;
      }

      const removeCondition = event.target.closest('[data-remove-condition]');
      if (removeCondition) {
        const node = getSelectedNode(state.form.schema, state.selection);
        if (!node) return;
        const index = Number(removeCondition.dataset.removeCondition || -1);
        node.item.conditions.splice(index, 1);
        renderEditor();
        renderPreview();
        return;
      }

      if (event.target.closest('[data-add-subfield]')) {
        const node = getSelectedNode(state.form.schema, state.selection);
        const repeater = node && node.kind === 'field' && node.item.type === 'repeater' ? node.item : null;
        if (!repeater) return;
        const field = createField('text', options.payment_defaults || {});
        repeater.subfields.push(field);
        state.selection = {kind: 'subfield', fieldId: repeater.id, subfieldId: field.id};
        state.editorSection = 'basics';
        render();
        return;
      }

      const addRoute = event.target.closest('[data-add-route]');
      if (addRoute) {
        const routes = state.form.settings.notifications.internal.routes;
        routes.push({value: '', user_id: 0});
        renderSettings();
        return;
      }

      const removeRoute = event.target.closest('[data-remove-route]');
      if (removeRoute) {
        const index = Number(removeRoute.dataset.removeRoute || -1);
        state.form.settings.notifications.internal.routes.splice(index, 1);
        renderSettings();
        return;
      }

      if (event.target.closest('[data-save-form]')) {
        await saveForm(false);
        return;
      }

      if (event.target.closest('[data-publish-form]')) {
        await saveForm(true);
        return;
      }

      if (event.target.closest('[data-duplicate-form]')) {
        if (!state.form.id) return;
        try {
          const response = await request(actions.duplicate, {form_id: String(state.form.id)});
          if (response.form && response.form.id) {
            notify('success', 'Form duplicated.');
            window.location.assign(String((boot.urls && boot.urls.home) || '/forms/build/').replace(/\/$/, '') + '/build/?form_id=' + response.form.id);
            return;
          }
          notify('success', 'Form duplicated.');
          window.location.assign(String((boot.urls && boot.urls.home) || '/forms/'));
        } catch (error) {
          notify('error', error.message || 'Duplicate failed.');
        }
        return;
      }

      if (event.target.closest('[data-delete-form]')) {
        if (!state.form.id) return;
        const confirmed = await confirmAction('Delete this form and all of its entries?');
        if (!confirmed) return;
        try {
          await request(actions.del, {form_id: String(state.form.id)});
          notify('success', 'Form deleted.');
          window.location.assign(String((boot.urls && boot.urls.home) || '/forms/'));
        } catch (error) {
          notify('error', error.message || 'Delete failed.');
        }
      }
    });

    root.addEventListener('input', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.matches('[data-rich-setting-path]')) {
        updateRichSettingProperty(target, state);
        renderPublish();
        return;
      }

      if (target.matches('[data-form-prop]')) {
        updateFormProperty(target);
        refreshTitle();
        renderPreview();
        renderPublish();
        return;
      }

      if (target.matches('[data-setting-path]')) {
        updateSettingProperty(target);
        renderPreview();
        renderPublish();
        return;
      }

      if (target.matches('[data-field-prop], [data-field-validation], [data-field-source], [data-payment-prop]')) {
        updateSelectedField(target, false);
        renderFieldList();
        renderPreview();
        renderPublish();
        return;
      }

      if (target.matches('[data-condition-field], [data-condition-operator], [data-condition-value]')) {
        updateCondition(target);
        renderPreview();
        return;
      }

      if (target.matches('[data-route-value], [data-route-user], [data-notification-user]')) {
        updateNotificationRoutes(target);
        return;
      }
    });

    root.addEventListener('change', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.matches('[data-setting-role]')) {
        const role = String(target.getAttribute('data-setting-role') || '');
        const checked = Boolean(readElementValue(target));
        const set = new Set(state.form.settings.access.roles || []);
        if (checked) set.add(role); else set.delete(role);
        state.form.settings.access.roles = Array.from(set).filter(Boolean);
        return;
      }

      if (target.matches('[data-form-prop]')) {
        updateFormProperty(target);
        if (target.matches('[data-form-prop="slug"], [data-form-prop="status"], [data-form-prop="binding.module"]')) {
          render();
        } else {
          renderPreview();
          renderPublish();
        }
        return;
      }

      if (target.matches('[data-setting-path]')) {
        updateSettingProperty(target);
        if (target.matches('[data-setting-path="access.mode"], [data-setting-path="schedule.enabled"], [data-setting-path="binding.module"], [data-setting-path="notifications.internal.routing_field"], [data-setting-path="notifications.submitter.recipient_field"]')) {
          render();
        } else {
          renderPreview();
          renderPublish();
        }
        return;
      }

      if (target.matches('[data-field-prop], [data-field-validation], [data-field-source], [data-payment-prop]')) {
        const rerender = updateSelectedField(target, true);
        renderFieldList();
        renderPreview();
        renderPublish();
        if (rerender) {
          render();
        }
        return;
      }

      if (target.matches('[data-condition-field], [data-condition-operator], [data-condition-value]')) {
        updateCondition(target);
        renderPreview();
        return;
      }
    });

    root.addEventListener('focusin', (event) => {
      const editor = event.target instanceof HTMLElement ? event.target.closest('[data-rich-setting-path]') : null;
      if (editor) {
        saveRichSelection(editor);
      }
    });

    root.addEventListener('mouseup', (event) => {
      const editor = event.target instanceof HTMLElement ? event.target.closest('[data-rich-setting-path]') : null;
      if (editor) {
        saveRichSelection(editor);
      }
    });

    root.addEventListener('keyup', (event) => {
      const editor = event.target instanceof HTMLElement ? event.target.closest('[data-rich-setting-path]') : null;
      if (editor) {
        saveRichSelection(editor);
      }
    });

    root.addEventListener('blur', (event) => {
      const editor = event.target instanceof HTMLElement ? event.target.closest('[data-rich-setting-path]') : null;
      if (editor) {
        updateRichSettingProperty(editor, state);
      }
    }, true);

    root.addEventListener('mousedown', (event) => {
      if (event.target.closest('[data-rich-cmd], [data-rich-action], [data-rich-toggle="menu"]')) {
        event.preventDefault();
      }
    });

    root.addEventListener('dragstart', (event) => {
      const item = event.target.closest('[data-drag-item]');
      if (!item) return;
      state.drag = {
        scope: String(item.dataset.dragScope || ''),
        fieldId: String(item.dataset.dragField || ''),
        subfieldId: String(item.dataset.dragSubfield || '')
      };
      item.classList.add('is-dragging');
    });

    root.addEventListener('dragend', (event) => {
      const item = event.target.closest('[data-drag-item]');
      if (item) item.classList.remove('is-dragging');
      state.drag = null;
    });

    root.addEventListener('dragover', (event) => {
      const item = event.target.closest('[data-drop-item]');
      if (!item || !state.drag) return;
      const scope = String(item.dataset.dropScope || '');
      if (scope !== state.drag.scope) return;
      event.preventDefault();
    });

    root.addEventListener('drop', (event) => {
      const item = event.target.closest('[data-drop-item]');
      if (!item || !state.drag) return;
      event.preventDefault();

      if (state.drag.scope === 'fields') {
        const fromIndex = state.form.schema.findIndex((field) => field.id === state.drag.fieldId);
        const toIndex = state.form.schema.findIndex((field) => field.id === String(item.dataset.dropField || ''));
        moveInCollection(state.form.schema, fromIndex, toIndex - fromIndex);
        render();
        return;
      }

      if (state.drag.scope === 'subfields') {
        const parentId = String(item.dataset.parentField || '');
        const parent = state.form.schema.find((field) => field.id === parentId && field.type === 'repeater');
        if (!parent) return;
        const fromIndex = parent.subfields.findIndex((field) => field.id === state.drag.subfieldId);
        const toIndex = parent.subfields.findIndex((field) => field.id === String(item.dataset.dropSubfield || ''));
        moveInCollection(parent.subfields, fromIndex, toIndex - fromIndex);
        render();
      }
    });

    function handleDocumentClick(event) {
      if (!root.contains(event.target)) {
        closeRichMenus(root);
      }
    }

    async function saveForm(publish) {
      if (state.saving) return;
      state.saving = true;
      try {
        const wasNew = !state.form.id;
        const payload = buildSavePayload(state.form, publish ? 'published' : state.form.status || 'draft');
        const response = await request(publish ? actions.publish : actions.save, {
          form_id: payload.id ? String(payload.id) : '',
          form: JSON.stringify(payload)
        });
        state.form = hydrateForm(clone(response.form || state.form), options.payment_defaults || {});
        if (wasNew && state.form.id) {
          const url = new URL(window.location.href);
          url.searchParams.set('form_id', String(state.form.id));
          if (state.step !== 'build') {
            url.searchParams.set('step', state.step);
          } else {
            url.searchParams.delete('step');
          }
          window.history.replaceState({}, '', url.toString());
        }
        state.pickerOpen = false;
        notify('success', publish ? 'Form published.' : 'Form saved.');
        render();
      } catch (error) {
        notify('error', error.message || (publish ? 'Publish failed.' : 'Save failed.'));
      } finally {
        state.saving = false;
      }
    }

    function updateFormProperty(target) {
      const prop = String(target.getAttribute('data-form-prop') || '');
      const value = readElementValue(target);
      if (prop === 'name') {
        state.form.name = String(value);
        if (!state.form.slug) {
          state.form.slug = slugify(String(value));
        }
        return;
      }
      if (prop === 'slug') {
        state.form.slug = slugify(String(value));
        target.value = state.form.slug;
        return;
      }
      if (prop === 'description') {
        state.form.description = String(value);
        return;
      }
      if (prop === 'status') {
        state.form.status = ['draft', 'published', 'archived'].includes(String(value)) ? String(value) : 'draft';
      }
    }

    function updateSettingProperty(target) {
      const path = String(target.getAttribute('data-setting-path') || '');
      const value = readElementValue(target);
      const settings = state.form.settings;
      switch (path) {
        case 'binding.module':
          settings.binding.module = String(value);
          settings.binding.flow = '';
          return;
        case 'binding.flow':
          settings.binding.flow = String(value);
          return;
        case 'access.mode':
          settings.access.mode = String(value);
          return;
        case 'access.password':
          settings.access.password = String(value);
          return;
        case 'access.denied_message':
          settings.access.denied_message = String(value);
          return;
        case 'schedule.enabled':
          settings.schedule.enabled = Boolean(value);
          return;
        case 'schedule.start_at':
          settings.schedule.start_at = String(value);
          return;
        case 'schedule.end_at':
          settings.schedule.end_at = String(value);
          return;
        case 'schedule.closed_message':
          settings.schedule.closed_message = String(value);
          return;
        case 'confirmation.message':
          settings.confirmation.message = String(value);
          return;
        case 'notifications.submitter.enabled':
          settings.notifications.submitter.enabled = Boolean(value);
          return;
        case 'notifications.submitter.recipient_field':
          settings.notifications.submitter.recipient_field = String(value);
          return;
        case 'notifications.submitter.from_name':
          settings.notifications.submitter.from_name = String(value);
          return;
        case 'notifications.submitter.from_email':
          settings.notifications.submitter.from_email = String(value);
          return;
        case 'notifications.submitter.include_submission_data':
          settings.notifications.submitter.include_submission_data = Boolean(value);
          return;
        case 'notifications.submitter.subject':
          settings.notifications.submitter.subject = String(value);
          return;
        case 'notifications.submitter.message':
          settings.notifications.submitter.message = String(value);
          return;
        case 'notifications.internal.enabled':
          settings.notifications.internal.enabled = Boolean(value);
          return;
        case 'notifications.internal.general_email':
          settings.notifications.internal.general_email = String(value);
          return;
        case 'notifications.internal.from_name':
          settings.notifications.internal.from_name = String(value);
          return;
        case 'notifications.internal.from_email':
          settings.notifications.internal.from_email = String(value);
          return;
        case 'notifications.internal.include_submission_data':
          settings.notifications.internal.include_submission_data = Boolean(value);
          return;
        case 'notifications.internal.routing_field':
          settings.notifications.internal.routing_field = String(value);
          return;
        case 'notifications.internal.subject':
          settings.notifications.internal.subject = String(value);
          return;
        case 'notifications.internal.message':
          settings.notifications.internal.message = String(value);
          return;
        default:
          break;
      }
    }

    function updateSelectedField(target, isChangeEvent) {
      const node = getSelectedNode(state.form.schema, state.selection);
      if (!node) return false;
      const field = node.item;
      const value = readElementValue(target);
      let rerender = false;

      if (target.matches('[data-field-prop]')) {
        const prop = String(target.getAttribute('data-field-prop') || '');
        switch (prop) {
          case 'type':
            if (String(value) === 'payment' && state.form.schema.some((item) => item.type === 'payment' && item.id !== field.id)) {
              notify('error', 'This form already has a payment field.');
              target.value = field.type;
              return false;
            }
            field.type = String(value);
            field.width = field.type === 'payment' ? 'full' : field.width;
            if (!fieldUsesChoices(field.type)) {
              field.options = [];
              field.options_source = {type: 'static', parent_field: '', items: []};
              field.searchable = false;
            }
            if (field.type !== 'repeater') {
              field.subfields = [];
              field.repeat_limit = 5;
            }
            if (field.type !== 'payment') {
              field.payment = defaultPayment(options.payment_defaults || {});
            }
            state.editorSection = defaultEditorSection(field);
            rerender = true;
            break;
          case 'width':
            field.width = field.type === 'payment' ? 'full' : normalizeWidthValue(value);
            break;
          case 'label':
            field.label = String(value);
            break;
          case 'key':
            field.key = slugifyKey(String(value));
            if (target instanceof HTMLInputElement) target.value = field.key;
            rerender = true;
            break;
          case 'help':
            field.help = String(value);
            break;
          case 'placeholder':
            field.placeholder = String(value);
            break;
          case 'required':
            field.required = Boolean(value);
            break;
          case 'searchable':
            field.searchable = Boolean(value);
            break;
          case 'repeat_limit':
            field.repeat_limit = clamp(Number(value) || 1, 1, 25);
            break;
          default:
            break;
        }
      }

      if (target.matches('[data-field-validation]')) {
        const prop = String(target.getAttribute('data-field-validation') || '');
        field.validation = field.validation || {};
        field.validation[prop] = value === '' ? null : Number(value);
      }

      if (target.matches('[data-field-source]')) {
        const prop = String(target.getAttribute('data-field-source') || '');
        field.options_source = field.options_source || {type: 'static', parent_field: '', items: []};
        if (prop === 'type') {
          field.options_source.type = String(value);
          field.options_source.parent_field = '';
          if (field.options_source.type === 'grandys_categories') {
            field.options_source.items = clone((options.datasets && options.datasets.grandys_categories) || []);
            field.options = clone(field.options_source.items);
          } else if (field.options_source.type === 'grandys_items') {
            field.options_source.items = clone((options.datasets && options.datasets.grandys_items) || []);
            field.options = [];
          } else if (field.options_source.type === 'campaigns') {
            field.options_source.items = clone((options.campaigns || []).map((item) => ({
              label: String(item.label || ''),
              value: String(item.value || ''),
              category: ''
            })));
            field.options = clone(field.options_source.items);
          } else {
            field.options_source.type = 'static';
            field.options_source.items = clone(field.options || []);
          }
          rerender = isChangeEvent;
        } else if (prop === 'parent_field') {
          field.options_source.parent_field = String(value);
          if (field.options_source.type === 'grandys_items') {
            field.options = field.options_source.parent_field ? [] : clone(field.options_source.items || []);
          }
        } else if (prop === 'options_text') {
          field.options = parseOptionsText(String(value));
          if (field.options_source.type === 'static') {
            field.options_source.items = clone(field.options);
          }
        }
      }

      if (target.matches('[data-payment-prop]')) {
        field.payment = field.payment || defaultPayment(options.payment_defaults || {});
        const prop = String(target.getAttribute('data-payment-prop') || '');
        if (prop === 'campaign_code') field.payment.campaign_code = String(value);
        if (prop === 'donation_amounts') field.payment.donation_amounts = parseMoneyChoices(String(value));
        if (prop === 'allow_custom_amount') field.payment.allow_custom_amount = Boolean(value);
        if (prop === 'custom_amount_label') field.payment.custom_amount_label = String(value);
        if (prop === 'cover_fees_enabled') field.payment.cover_fees_enabled = Boolean(value);
        if (prop === 'cover_fees_label') field.payment.cover_fees_label = String(value);
        if (prop === 'summary_label') field.payment.summary_label = String(value);
        if (prop === 'success_message') field.payment.success_message = String(value);
      }

      return rerender;
    }

    function updateCondition(target) {
      const node = getSelectedNode(state.form.schema, state.selection);
      if (!node) return;
      const index = Number(target.getAttribute('data-condition-index') || -1);
      if (index < 0 || index >= node.item.conditions.length) return;
      const condition = node.item.conditions[index];
      if (target.matches('[data-condition-field]')) condition.field = String(readElementValue(target));
      if (target.matches('[data-condition-operator]')) condition.operator = String(readElementValue(target));
      if (target.matches('[data-condition-value]')) condition.value = String(readElementValue(target));
    }

    function updateNotificationRoutes(target) {
      const internal = state.form.settings.notifications.internal;
      if (target.matches('[data-notification-user]')) {
        const id = Number(target.getAttribute('data-notification-user') || 0);
        const checked = Boolean(readElementValue(target));
        const set = new Set(internal.default_user_ids || []);
        if (checked) set.add(id); else set.delete(id);
        internal.default_user_ids = Array.from(set).filter((value) => value > 0);
        return;
      }

      const index = Number(target.getAttribute('data-route-index') || -1);
      if (index < 0) return;
      internal.routes = Array.isArray(internal.routes) ? internal.routes : [];
      if (!internal.routes[index]) {
        internal.routes[index] = {value: '', user_id: 0};
      }
      if (target.matches('[data-route-value]')) internal.routes[index].value = String(readElementValue(target));
      if (target.matches('[data-route-user]')) internal.routes[index].user_id = Number(readElementValue(target) || 0);
    }

    function render() {
      root.innerHTML = renderBuilderShell(state, boot, options);
      bindIconFallbacks(root);
      if (window.Metis && Metis.ui && Metis.ui.select) {
        Metis.ui.select.init(root);
      }
    }

    function renderFieldList() {
      const node = root.querySelector('[data-ui="field-list"]');
      if (node) node.innerHTML = renderFieldListHtml(state.form.schema, state.selection);
    }

    function renderEditor() {
      const node = root.querySelector('[data-ui="field-editor"]');
      if (node) {
        node.innerHTML = renderEditorHtml(state, options);
        bindIconFallbacks(node);
        if (window.Metis && Metis.ui && Metis.ui.select) {
          Metis.ui.select.init(node);
        }
      }
    }

    function renderPreview() {
      const node = root.querySelector('[data-ui="preview"]');
      if (node) {
        node.innerHTML = renderPreviewHtml(state.form);
        if (window.Metis && Metis.ui && Metis.ui.select) {
          Metis.ui.select.init(node);
        }
      }
    }

    function renderSettings() {
      const node = root.querySelector('[data-ui="settings"]');
      if (node) {
        node.innerHTML = renderSettingsHtml(state.form, options);
        if (window.Metis && Metis.ui && Metis.ui.select) {
          Metis.ui.select.init(node);
        }
      }
    }

    function renderPublish() {
      const node = root.querySelector('[data-ui="publish"]');
      if (node) {
        node.innerHTML = renderPublishHtml(state.form, boot);
        if (window.Metis && Metis.ui && Metis.ui.select) {
          Metis.ui.select.init(node);
        }
      }
    }

    function refreshTitle() {
      const title = root.querySelector('[data-ui="title"]');
      if (title) title.textContent = state.form.name || 'Untitled form';
    }

    async function request(action, body) {
      return performAjaxRequest(action, body);
    }
  }

  function initEntries(root, boot) {
    const canExport = !!boot.can_export;
    root.addEventListener('click', async (event) => {
      const trigger = event.target.closest('[data-forms-export]');
      if (!trigger) return;
      if (!canExport) return;
      try {
        const payload = await adminRequest('metis_forms_export', {
          form_id: String(boot.form_id || 0)
        });
        downloadText(String(payload.filename || `form-${boot.form_id}-entries.csv`), String(payload.csv || ''));
        notify('success', 'Export ready.');
      } catch (error) {
        notify('error', error.message || 'Export failed.');
      }
    });
  }

  function initPublic(root, boot) {
    const form = boot.form || {};
    const schema = Array.isArray(form.schema) ? form.schema : [];
    const formEl = root.querySelector('#metis-forms-public-form');
    const alertEl = root.querySelector('#metis-forms-public-alert');
    const submitButton = root.querySelector('#metis-forms-submit-button');
    const successOverlay = root.querySelector('#metis-forms-success-overlay');
    if (!formEl) return;

    const paymentField = schema.find((field) => field && field.type === 'payment') || null;
    initFieldGroup(formEl, schema);

    root.addEventListener('click', (event) => {
      const close = event.target.closest('[data-success-close]');
      if (!close || !successOverlay) return;
      successOverlay.hidden = true;
    });

    if (!paymentField) {
      formEl.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearPublicErrors(formEl, alertEl);
        setPublicSubmitting(submitButton, true, 'Submitting...');

        try {
          const payload = formDataToObject(new FormData(formEl));
          const result = await publicRequest(form.submit_url || window.location.href, payload);
          if (!result.ok) {
            applyPublicErrors(formEl, alertEl, result);
            return;
          }

          formEl.reset();
          formEl.dispatchEvent(new Event('change', {bubbles: true}));
          showPublicAlert(alertEl, '', 'success');
          hidePublicAlert(alertEl);
          showSuccessOverlay(root, result.message || 'Thanks, your submission has been received.');
        } catch (error) {
          showPublicAlert(alertEl, error.message || 'Unable to submit the form right now.', 'error');
        } finally {
          setPublicSubmitting(submitButton, false);
        }
      });

      return;
    }

    const paymentState = {
      stripe: null,
      elements: null,
      clientSecret: '',
      sessionKey: '',
      confirmButton: root.querySelector('#metis-forms-stripe-confirm'),
      stripePanel: root.querySelector('.metis-forms-stripe-panel'),
      paymentMount: root.querySelector('#metis-forms-stripe-mount')
    };

    const updateTotals = () => {
      const payment = paymentField.payment || {};
      const selected = selectedDonationAmount(formEl);
      const cover = Boolean(formEl.querySelector('input[name="_cover_fees"]')?.checked);
      const totals = calculateGrossTotals(
        selected,
        Number(payment.fee_percent || 0),
        Number(payment.fee_fixed || 0),
        cover
      );
      setText(root, '[data-total-base]', formatMoney(totals.base, payment.currency || 'usd'));
      setText(root, '[data-total-fee]', formatMoney(totals.fee, payment.currency || 'usd'));
      setText(root, '[data-total-grand]', formatMoney(totals.total, payment.currency || 'usd'));
      const hidden = formEl.querySelector('input[name="_donation_amount"]');
      if (hidden) hidden.value = selected > 0 ? String(selected) : '';
    };

    formEl.addEventListener('change', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (target.matches('input[name="_donation_amount_choice"], input[name="_cover_fees"], input[name="_donation_amount_custom"]')) {
        updateTotals();
      }
    });

    formEl.addEventListener('input', (event) => {
      const target = event.target;
      if (target instanceof HTMLElement && target.matches('input[name="_donation_amount_custom"]')) {
        const radios = formEl.querySelectorAll('input[name="_donation_amount_choice"]');
        radios.forEach((radio) => { radio.checked = false; });
        updateTotals();
      }
    });

    formEl.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearPublicErrors(formEl, alertEl);
      updateTotals();
      const amount = selectedDonationAmount(formEl);
      if (amount <= 0) {
        showPublicAlert(alertEl, 'Choose a donation amount to continue.', 'error');
        return;
      }

      const payload = formDataToObject(new FormData(formEl));
      payload.mode = 'prepare_payment';

      try {
        const response = await publicRequest(form.submit_url || window.location.href, payload);
        if (!response.ok) {
          showPublicAlert(alertEl, response.message || 'Unable to start payment.', 'error');
          return;
        }
        paymentState.clientSecret = String(response.client_secret || '');
        paymentState.sessionKey = String(response.payment_session || '');
        paymentState.stripe = window.Stripe ? window.Stripe(String(response.publishable_key || '')) : null;
        if (!paymentState.stripe) {
          showPublicAlert(alertEl, 'Stripe is unavailable.', 'error');
          return;
        }
        paymentState.elements = paymentState.stripe.elements({clientSecret: paymentState.clientSecret});
        paymentState.paymentMount.innerHTML = '';
        const paymentElement = paymentState.elements.create('payment');
        paymentElement.mount(paymentState.paymentMount);
        if (paymentState.stripePanel) paymentState.stripePanel.hidden = false;
        showPublicAlert(alertEl, 'Payment details are ready. Complete the payment to finish the form.', 'success');
      } catch (error) {
        showPublicAlert(alertEl, error.message || 'Unable to start payment.', 'error');
      }
    });

    if (paymentState.confirmButton) {
      paymentState.confirmButton.addEventListener('click', async () => {
        if (!paymentState.stripe || !paymentState.elements || !paymentState.sessionKey) return;
        const confirmation = await paymentState.stripe.confirmPayment({
          elements: paymentState.elements,
          redirect: 'if_required'
        });
        if (confirmation.error) {
          showPublicAlert(alertEl, confirmation.error.message || 'Payment could not be completed.', 'error');
          return;
        }

        try {
          const result = await publicRequest(form.submit_url || window.location.href, {
            mode: 'finalize_payment',
            payment_session: paymentState.sessionKey,
            payment_intent_id: confirmation.paymentIntent ? confirmation.paymentIntent.id : ''
          });
          if (!result.ok) {
            applyPublicErrors(formEl, alertEl, result);
          } else {
            formEl.reset();
            formEl.dispatchEvent(new Event('change', {bubbles: true}));
            if (paymentState.stripePanel) paymentState.stripePanel.hidden = true;
            updateTotals();
            hidePublicAlert(alertEl);
            showSuccessOverlay(root, result.message || 'Payment complete.');
          }
        } catch (error) {
          showPublicAlert(alertEl, error.message || 'Payment could not be finalized.', 'error');
        }
      });
    }

    updateTotals();
  }

  function initFieldGroup(container, schema) {
    const allFields = Array.isArray(schema) ? schema : [];
    allFields.forEach((field) => {
      if (field.type === 'repeater') {
        initRepeater(container, field);
      }
    });

    const update = () => {
      applyVisibility(container, allFields);
      syncDependentSelects(container, allFields);
    };

    container.addEventListener('change', update);
    container.addEventListener('input', update);
    update();
  }

  function initRepeater(container, field) {
    const repeater = container.querySelector(`[data-repeater-key="${cssEscape(field.key)}"]`);
    if (!repeater) return;
    const rowsHost = repeater.querySelector('.metis-forms-repeater-rows');
    const template = repeater.querySelector('template');
    const addButton = repeater.querySelector('[data-add-row]');
    if (!rowsHost || !template || !addButton) return;

    let index = 0;
    const addRow = () => {
      const html = template.innerHTML.replace(/__INDEX__/g, String(index));
      index += 1;
      const wrapper = document.createElement('div');
      wrapper.innerHTML = html;
      const row = wrapper.firstElementChild;
      if (!row) return;
      rowsHost.appendChild(row);
      initFieldGroup(row, field.subfields || []);
    };

    addButton.addEventListener('click', () => {
      const limit = Number(repeater.getAttribute('data-repeat-limit') || 1);
      if (rowsHost.children.length >= limit) return;
      addRow();
    });

    rowsHost.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-remove-row]');
      if (!remove) return;
      const row = remove.closest('.metis-forms-repeater-row');
      if (row) row.remove();
    });

    if (rowsHost.children.length === 0) {
      addRow();
    }
  }

  function applyVisibility(container, schema) {
    const context = captureFieldValues(container);
    schema.forEach((field) => {
      const section = container.querySelector(`[data-field-key="${cssEscape(field.key)}"]`);
      if (!section) return;
      if (!Array.isArray(field.conditions) || field.conditions.length === 0) {
        section.hidden = false;
        return;
      }
      section.hidden = !field.conditions.every((condition) => conditionPasses(condition, context));
    });
  }

  function syncDependentSelects(container, schema) {
    const context = captureFieldValues(container);
    schema.forEach((field) => {
      if (!fieldUsesChoices(field.type)) return;
      const source = field.options_source || {};
      const parentKey = String(source.parent_field || '');
      if (!parentKey) return;
      const section = container.querySelector(`[data-field-key="${cssEscape(field.key)}"]`);
      if (!section) return;
      const select = section.querySelector('select');
      if (!(select instanceof HTMLSelectElement)) return;
      const parentValue = String(context[parentKey] || '');
      const items = Array.isArray(source.items) && source.items.length
        ? source.items
        : (Array.isArray(field.options) ? field.options : []);
      const options = parentValue ? items.filter((item) => String(item.category || '') === parentValue) : [];
      const currentValue = select.value;
      select.innerHTML = '<option value="">Select…</option>' + options.map((option) => {
        return `<option value="${escapeAttr(String(option.value || ''))}">${escapeHtml(String(option.label || ''))}</option>`;
      }).join('');
      select.disabled = parentValue === '';
      if (options.some((option) => String(option.value) === currentValue)) {
        select.value = currentValue;
      }
    });
  }

  function renderBuilderShell(state, boot, options) {
    const canManage = !!boot.can_manage;
    const canDelete = !!boot.can_delete;
    const canPublish = !!boot.can_publish;
    const title = escapeHtml(state.form.name || 'Untitled form');
    const currentField = getSelectedNode(state.form.schema, state.selection);

    return `
      <div class="metis-forms-admin">
        <header class="metis-forms-admin__header">
          <div>
            <a class="metis-btn metis-btn-xs metis-btn-ghost" href="${escapeAttr(String(boot.urls?.home || '/forms/'))}">Back to Forms</a>
            <h1 class="metis-page-title" data-ui="title">${title}</h1>
          </div>
          <div class="metis-forms-admin__actions">
            ${state.form.id ? `<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-duplicate-form ${!canManage ? 'disabled' : ''}>Duplicate</button>` : ''}
            ${canPublish ? '<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-publish-form>Publish</button>' : ''}
            <button type="button" class="metis-btn metis-btn-xs" data-save-form ${!canManage ? 'disabled' : ''}>Save</button>
            ${state.form.id && canDelete ? `<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-delete-form>Delete</button>` : ''}
          </div>
        </header>

        <nav class="metis-forms-steps" aria-label="Form steps">
          ${renderStepButton('build', 'Build', state.step)}
          ${renderStepButton('settings', 'Settings', state.step)}
          ${renderStepButton('publish', 'Publish', state.step)}
        </nav>

        <section class="metis-forms-panel ${state.step === 'build' ? '' : 'is-hidden'}" data-panel="build">
          <div class="metis-forms-builder-grid">
            <section class="metis-forms-surface">
              <div class="metis-forms-surface__head">
                <h2>Fields</h2>
                <button type="button" class="metis-btn metis-btn-xs" data-open-picker ${!canManage ? 'disabled' : ''}>Add field</button>
              </div>
              <div data-ui="field-list">${renderFieldListHtml(state.form.schema, state.selection)}</div>
            </section>

            <section class="metis-forms-surface">
              <div class="metis-forms-surface__head">
                <h2>${currentField ? 'Editor' : 'Field editor'}</h2>
                ${currentField ? `<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-remove-selected ${!canManage ? 'disabled' : ''}>Remove</button>` : ''}
              </div>
              <div data-ui="field-editor">${renderEditorHtml(state, options)}</div>
            </section>

            <section class="metis-forms-surface">
              <div class="metis-forms-surface__head">
                <h2>Preview</h2>
              </div>
              <div data-ui="preview">${renderPreviewHtml(state.form)}</div>
            </section>
          </div>
        </section>

        <section class="metis-forms-panel ${state.step === 'settings' ? '' : 'is-hidden'}" data-panel="settings">
          <div data-ui="settings">${renderSettingsHtml(state.form, options)}</div>
        </section>

        <section class="metis-forms-panel ${state.step === 'publish' ? '' : 'is-hidden'}" data-panel="publish">
          <div data-ui="publish">${renderPublishHtml(state.form, boot)}</div>
        </section>

        ${renderFieldPicker(state, options)}
      </div>
    `;
  }

  function renderStepButton(step, label, current) {
    return `<button type="button" class="metis-forms-step${step === current ? ' is-active' : ''}" data-step="${escapeAttr(step)}">${escapeHtml(label)}</button>`;
  }

  function renderFieldListHtml(schema, selection) {
    if (!Array.isArray(schema) || schema.length === 0) {
      return `<div class="metis-forms-empty">No fields yet. Add the first field to start the form.</div>`;
    }

    return schema.map((field) => {
      const selected = selection && selection.kind === 'field' && selection.fieldId === field.id;
      return `
        <article class="metis-forms-field-card${selected ? ' is-selected' : ''}">
          <div class="metis-forms-field-card__drag" draggable="true" data-drag-item="1" data-drag-scope="fields" data-drag-field="${escapeAttr(field.id)}" aria-hidden="true">⋮⋮</div>
          <button type="button" class="metis-forms-field-card__body" data-select-field="${escapeAttr(field.id)}" data-drop-item="1" data-drop-scope="fields" data-drop-field="${escapeAttr(field.id)}">
            <span class="metis-forms-field-card__label">${escapeHtml(field.label || 'Untitled field')}</span>
            <span class="metis-forms-field-card__meta">${escapeHtml(field.key || '')}</span>
          </button>
          <div class="metis-forms-field-card__tail">
            <span class="metis-forms-pill">${escapeHtml(fieldLabel(field.type))}</span>
            <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-toggle-width="field:${escapeAttr(field.id)}">${escapeHtml(widthLabel(field.width))}</button>
          </div>
        </article>
      `;
    }).join('');
  }

  function renderEditorHtml(state, options) {
    const node = getSelectedNode(state.form.schema, state.selection);
    if (!node) {
      return `<div class="metis-forms-empty">Select a field to edit it.</div>`;
    }

    const field = node.item;
    const sections = editorSections(field);
    const active = sections.includes(state.editorSection) ? state.editorSection : 'basics';
    const header = node.kind === 'subfield'
      ? `<div class="metis-forms-editor-note"><button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-back-to-repeater>Back to repeater</button><span>Editing subfield inside ${escapeHtml(node.parent.label || 'Repeater')}</span></div>`
      : '';

    return `
      <div class="metis-forms-editor">
        ${header}
        <div class="metis-forms-editor-tools">
          <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-move-node="${escapeAttr(selectionKey(node))}" data-move-delta="-1">Move earlier</button>
          <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-move-node="${escapeAttr(selectionKey(node))}" data-move-delta="1">Move later</button>
        </div>
        <div class="metis-forms-accordion">
          ${sections.map((section) => renderEditorSection(section, active === section, field, node, state.form, options)).join('')}
        </div>
      </div>
    `;
  }

  function renderEditorSection(section, open, field, node, form, options) {
    const titleMap = {basics: 'Basics', choices: 'Choices', visibility: 'Visibility', repeater: 'Repeater', payment: 'Payment'};
    return `
      <section class="metis-forms-accordion__section">
        <button type="button" class="metis-forms-accordion__toggle${open ? ' is-open' : ''}" data-editor-section="${escapeAttr(section)}">
          <span>${escapeHtml(titleMap[section] || section)}</span>
          <span class="metis-forms-accordion__chevron" aria-hidden="true">⌄</span>
        </button>
        ${open ? `<div class="metis-forms-accordion__content">${renderEditorSectionBody(section, field, node, form, options)}</div>` : ''}
      </section>
    `;
  }

  function renderEditorSectionBody(section, field, node, form, options) {
    if (section === 'basics') return renderBasicsSection(field, node);
    if (section === 'choices') return renderChoicesSection(field, node);
    if (section === 'visibility') return renderVisibilitySection(field, node);
    if (section === 'repeater') return renderRepeaterSection(field, node);
    if (section === 'payment') return renderPaymentSection(field, options);
    return '';
  }

  function renderBasicsSection(field, node) {
    const typeOptions = node.kind === 'subfield'
      ? ['text', 'email', 'number', 'textarea', 'select', 'radio', 'checkbox', 'date']
      : ['text', 'email', 'number', 'textarea', 'select', 'radio', 'checkbox', 'date', 'repeater', 'payment'];
    return `
      <div class="metis-forms-grid">
        <label class="metis-forms-control">
          <span>Type</span>
          <select class="metis-select" data-field-prop="type">
            ${typeOptions.map((type) => `<option value="${escapeAttr(type)}"${field.type === type ? ' selected' : ''}>${escapeHtml(fieldLabel(type))}</option>`).join('')}
          </select>
        </label>
        <label class="metis-forms-control">
          <span>Width</span>
          <select class="metis-select" data-field-prop="width" ${field.type === 'payment' ? 'disabled' : ''}>
            <option value="full"${normalizeWidthValue(field.width) === 'full' ? ' selected' : ''}>Wide</option>
            <option value="half"${field.width === 'half' ? ' selected' : ''}>Half</option>
            <option value="narrow"${field.width === 'narrow' ? ' selected' : ''}>Narrow</option>
          </select>
        </label>
        <label class="metis-forms-control">
          <span>Label</span>
          <input class="metis-input" type="text" value="${escapeAttr(field.label || '')}" data-field-prop="label">
        </label>
        <label class="metis-forms-control">
          <span>Key</span>
          <input class="metis-input" type="text" value="${escapeAttr(field.key || '')}" data-field-prop="key">
        </label>
        <label class="metis-forms-control metis-forms-control--full">
          <span>Help text</span>
          <textarea class="metis-input" rows="3" data-field-prop="help">${escapeHtml(field.help || '')}</textarea>
        </label>
        ${fieldSupportsPlaceholder(field.type) ? `
          <label class="metis-forms-control metis-forms-control--full">
            <span>Placeholder</span>
            <input class="metis-input" type="text" value="${escapeAttr(field.placeholder || '')}" data-field-prop="placeholder">
          </label>
        ` : ''}
        ${field.type === 'number' ? `
          <label class="metis-forms-control">
            <span>Minimum value</span>
            <input class="metis-input" type="number" step="0.01" value="${field.validation.min_value ?? ''}" data-field-validation="min_value">
          </label>
          <label class="metis-forms-control">
            <span>Maximum value</span>
            <input class="metis-input" type="number" step="0.01" value="${field.validation.max_value ?? ''}" data-field-validation="max_value">
          </label>
        ` : ''}
        ${field.type !== 'payment' ? `
          <label class="metis-forms-check">
            <input type="checkbox" ${field.required ? 'checked' : ''} data-field-prop="required">
            <span>Required</span>
          </label>
        ` : ''}
        ${fieldUsesChoices(field.type) && field.type === 'select' ? `
          <label class="metis-forms-check">
            <input type="checkbox" ${field.searchable ? 'checked' : ''} data-field-prop="searchable">
            <span>Searchable</span>
          </label>
        ` : ''}
      </div>
    `;
  }

  function renderChoicesSection(field, node) {
    const collection = node.collection.filter((candidate) => candidate.id !== field.id && fieldUsesChoices(candidate.type));
    const sourceType = String((field.options_source && field.options_source.type) || 'static') || 'static';
    return `
      <div class="metis-forms-grid">
        <label class="metis-forms-control">
          <span>Options source</span>
          <select class="metis-select" data-field-source="type">
            <option value="static"${sourceType === 'static' || sourceType === '' ? ' selected' : ''}>Custom list</option>
            <option value="grandys_categories"${sourceType === 'grandys_categories' ? ' selected' : ''}>Grandy's categories</option>
            <option value="grandys_items"${sourceType === 'grandys_items' ? ' selected' : ''}>Grandy's items</option>
            <option value="campaigns"${sourceType === 'campaigns' ? ' selected' : ''}>Campaigns</option>
          </select>
        </label>
        <label class="metis-forms-control">
          <span>Parent field</span>
          <select class="metis-select" data-field-source="parent_field">
            <option value="">No parent field</option>
            ${collection.map((candidate) => `<option value="${escapeAttr(candidate.key)}"${candidate.key === field.options_source.parent_field ? ' selected' : ''}>${escapeHtml(candidate.label || candidate.key)}</option>`).join('')}
          </select>
        </label>
        ${sourceType === 'static' || sourceType === '' ? `
          <label class="metis-forms-control metis-forms-control--full">
            <span>Options</span>
            <textarea class="metis-input" rows="8" data-field-source="options_text">${escapeHtml(toOptionsText(field.options || []))}</textarea>
          </label>
        ` : `
          <div class="metis-forms-inline-note metis-forms-control--full">${escapeHtml(previewOptionCount(field, sourceType))}</div>
        `}
      </div>
    `;
  }

  function renderVisibilitySection(field, node) {
    const siblings = node.collection.filter((candidate) => candidate.id !== field.id && candidate.type !== 'payment');
    const conditions = Array.isArray(field.conditions) ? field.conditions : [];
    return `
      <div class="metis-forms-editor-tools">
        <button type="button" class="metis-btn metis-btn-xs" data-add-condition>Add rule</button>
      </div>
      ${conditions.length === 0 ? '<div class="metis-forms-empty">This field is always visible.</div>' : `
        <div class="metis-forms-condition-list">
          ${conditions.map((condition, index) => `
            <div class="metis-forms-condition-row">
              <select class="metis-select" data-condition-field="1" data-condition-index="${index}">
                <option value="">Choose field</option>
                ${siblings.map((candidate) => `<option value="${escapeAttr(candidate.key)}"${candidate.key === condition.field ? ' selected' : ''}>${escapeHtml(candidate.label || candidate.key)}</option>`).join('')}
              </select>
              <select class="metis-select" data-condition-operator="1" data-condition-index="${index}">
                ${['equals', 'not_equals', 'contains', 'empty', 'not_empty'].map((operator) => `<option value="${operator}"${operator === condition.operator ? ' selected' : ''}>${operator.replace(/_/g, ' ')}</option>`).join('')}
              </select>
              <input class="metis-input" type="text" value="${escapeAttr(Array.isArray(condition.value) ? condition.value.join(', ') : String(condition.value || ''))}" data-condition-value="1" data-condition-index="${index}">
              <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-remove-condition="${index}">Remove</button>
            </div>
          `).join('')}
        </div>
      `}
    `;
  }

  function renderRepeaterSection(field, node) {
    if (field.type !== 'repeater') {
      return '<div class="metis-forms-empty">Select the repeater container to manage its subfields.</div>';
    }

    return `
      <div class="metis-forms-grid">
        <label class="metis-forms-control">
          <span>Repeat limit</span>
          <input class="metis-input" type="number" min="1" max="25" value="${escapeAttr(String(field.repeat_limit || 5))}" data-field-prop="repeat_limit">
        </label>
      </div>
      <div class="metis-forms-editor-tools">
        <button type="button" class="metis-btn metis-btn-xs" data-add-subfield>Add subfield</button>
      </div>
      ${field.subfields.length === 0 ? '<div class="metis-forms-empty">No subfields yet.</div>' : `
        <div class="metis-forms-subfield-stack">
          ${field.subfields.map((subfield) => {
            const selected = node.selection && node.selection.kind === 'subfield' && node.selection.subfieldId === subfield.id;
            return `
              <article class="metis-forms-subfield-card${selected ? ' is-selected' : ''}">
                <div class="metis-forms-field-card__drag" draggable="true" data-drag-item="1" data-drag-scope="subfields" data-drag-subfield="${escapeAttr(subfield.id)}" data-parent-field="${escapeAttr(field.id)}" aria-hidden="true">⋮⋮</div>
                <button type="button" class="metis-forms-field-card__body" data-select-subfield="${escapeAttr(subfield.id)}" data-parent-field="${escapeAttr(field.id)}" data-drop-item="1" data-drop-scope="subfields" data-drop-subfield="${escapeAttr(subfield.id)}">
                  <span class="metis-forms-field-card__label">${escapeHtml(subfield.label || 'Untitled subfield')}</span>
                  <span class="metis-forms-field-card__meta">${escapeHtml(subfield.key || '')}</span>
                </button>
                <div class="metis-forms-field-card__tail">
                  <span class="metis-forms-pill">${escapeHtml(fieldLabel(subfield.type))}</span>
                  <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-toggle-width="subfield:${escapeAttr(field.id)}:${escapeAttr(subfield.id)}">${escapeHtml(widthLabel(subfield.width))}</button>
                </div>
              </article>
            `;
          }).join('')}
        </div>
      `}
    `;
  }

  function renderPaymentSection(field, options) {
    const payment = Object.assign(defaultPayment(options.payment_defaults || {}), field.payment || {});
    return `
      <div class="metis-forms-grid">
        <label class="metis-forms-control">
          <span>Campaign</span>
          <select class="metis-select" data-payment-prop="campaign_code">
            <option value="">Select a campaign</option>
            ${(options.campaigns || []).map((campaign) => `<option value="${escapeAttr(String(campaign.value || ''))}"${String(campaign.value || '') === String(payment.campaign_code || '') ? ' selected' : ''}>${escapeHtml(String(campaign.label || ''))}</option>`).join('')}
          </select>
        </label>
        <label class="metis-forms-control">
          <span>Suggested amounts</span>
          <input class="metis-input" type="text" value="${escapeAttr((payment.donation_amounts || []).join(', '))}" data-payment-prop="donation_amounts">
        </label>
        <label class="metis-forms-check">
          <input type="checkbox" ${payment.allow_custom_amount ? 'checked' : ''} data-payment-prop="allow_custom_amount">
          <span>Allow custom amount</span>
        </label>
        <label class="metis-forms-control">
          <span>Custom amount label</span>
          <input class="metis-input" type="text" value="${escapeAttr(payment.custom_amount_label || 'Other amount')}" data-payment-prop="custom_amount_label">
        </label>
        <label class="metis-forms-check">
          <input type="checkbox" ${payment.cover_fees_enabled ? 'checked' : ''} data-payment-prop="cover_fees_enabled">
          <span>Let donors choose to cover fees</span>
        </label>
        <label class="metis-forms-control">
          <span>Cover fees label</span>
          <input class="metis-input" type="text" value="${escapeAttr(payment.cover_fees_label || '')}" data-payment-prop="cover_fees_label">
        </label>
        <label class="metis-forms-control">
          <span>Total label</span>
          <input class="metis-input" type="text" value="${escapeAttr(payment.summary_label || 'Total')}" data-payment-prop="summary_label">
        </label>
        <label class="metis-forms-control metis-forms-control--full">
          <span>Success message</span>
          <textarea class="metis-input" rows="4" data-payment-prop="success_message">${escapeHtml(payment.success_message || '')}</textarea>
        </label>
      </div>
    `;
  }

  function renderSettingsHtml(form, options) {
    const settings = form.settings;
    const access = settings.access;
    const schedule = settings.schedule;
    const submitter = settings.notifications.submitter;
    const internal = settings.notifications.internal;
    const emailFields = form.schema.filter((field) => field.type === 'email');
    const routeFields = form.schema.filter((field) => field.type !== 'payment').map((field) => ({key: field.key, label: field.label || field.key}));
    const flows = options.module_flows && options.module_flows[settings.binding.module] ? options.module_flows[settings.binding.module] : [];
    const mergeTokens = buildMergeTokenOptions(form.schema || []);

    return `
      <div class="metis-forms-settings-grid">
        <section class="metis-forms-surface">
          <div class="metis-forms-surface__head"><h2>Basics</h2></div>
          <div class="metis-forms-grid">
            <label class="metis-forms-control">
              <span>Form name</span>
              <input class="metis-input" type="text" value="${escapeAttr(form.name || '')}" data-form-prop="name">
            </label>
            <label class="metis-forms-control">
              <span>Public slug</span>
              <input class="metis-input" type="text" value="${escapeAttr(form.slug || '')}" data-form-prop="slug">
            </label>
            <label class="metis-forms-control metis-forms-control--full">
              <span>Description</span>
              <textarea class="metis-input" rows="4" data-form-prop="description">${escapeHtml(form.description || '')}</textarea>
            </label>
          </div>
        </section>

        <section class="metis-forms-surface">
          <div class="metis-forms-surface__head"><h2>Binding</h2></div>
          <div class="metis-forms-grid">
            <label class="metis-forms-control">
              <span>Assigned module</span>
              <select class="metis-select" data-setting-path="binding.module">
                ${(options.modules || []).map((item) => `<option value="${escapeAttr(String(item.value || ''))}"${String(item.value || '') === String(settings.binding.module || '') ? ' selected' : ''}>${escapeHtml(String(item.label || ''))}</option>`).join('')}
              </select>
            </label>
            <label class="metis-forms-control">
              <span>Module flow</span>
              <select class="metis-select" data-setting-path="binding.flow">
                <option value="">None</option>
                ${flows.map((item) => `<option value="${escapeAttr(String(item.value || ''))}"${String(item.value || '') === String(settings.binding.flow || '') ? ' selected' : ''}>${escapeHtml(String(item.label || ''))}</option>`).join('')}
              </select>
            </label>
          </div>
        </section>

        <section class="metis-forms-surface">
          <div class="metis-forms-surface__head"><h2>Access</h2></div>
          <div class="metis-forms-grid">
            <label class="metis-forms-control">
              <span>Who can access this form</span>
              <select class="metis-select" data-setting-path="access.mode">
                <option value="public"${access.mode === 'public' ? ' selected' : ''}>Public</option>
                <option value="logged_in"${access.mode === 'logged_in' ? ' selected' : ''}>Logged in users</option>
                <option value="password"${access.mode === 'password' ? ' selected' : ''}>Password protected</option>
                <option value="role"${access.mode === 'role' ? ' selected' : ''}>Specific internal roles</option>
              </select>
            </label>
            ${access.mode === 'password' ? `
              <label class="metis-forms-control">
                <span>Password</span>
                <input class="metis-input" type="text" value="${escapeAttr(access.password || '')}" data-setting-path="access.password">
              </label>
            ` : ''}
            <label class="metis-forms-control metis-forms-control--full">
              <span>Closed or restricted message</span>
              <textarea class="metis-input" rows="4" data-setting-path="access.denied_message">${escapeHtml(access.denied_message || '')}</textarea>
            </label>
            ${access.mode === 'role' ? `
              <div class="metis-forms-control metis-forms-control--full">
                <span>Allowed roles</span>
                <div class="metis-forms-check-grid">
                  ${(options.roles || []).map((role) => {
                    const checked = Array.isArray(access.roles) && access.roles.includes(String(role.value || ''));
                    return `<label class="metis-forms-check"><input type="checkbox" data-setting-role="${escapeAttr(String(role.value || ''))}" ${checked ? 'checked' : ''}><span>${escapeHtml(String(role.label || ''))}</span></label>`;
                  }).join('')}
                </div>
              </div>
            ` : ''}
          </div>
        </section>

        <section class="metis-forms-surface">
          <div class="metis-forms-surface__head"><h2>Schedule</h2></div>
          <div class="metis-forms-grid">
            <label class="metis-forms-check metis-forms-control--full">
              <input type="checkbox" ${schedule.enabled ? 'checked' : ''} data-setting-path="schedule.enabled">
              <span>Schedule this form</span>
            </label>
            ${schedule.enabled ? `
              <label class="metis-forms-control">
                <span>Start date</span>
                <input class="metis-input" type="datetime-local" value="${escapeAttr(schedule.start_at || '')}" data-setting-path="schedule.start_at">
              </label>
              <label class="metis-forms-control">
                <span>End date</span>
                <input class="metis-input" type="datetime-local" value="${escapeAttr(schedule.end_at || '')}" data-setting-path="schedule.end_at">
              </label>
              <label class="metis-forms-control metis-forms-control--full">
                <span>Scheduled closed message</span>
                <textarea class="metis-input" rows="4" data-setting-path="schedule.closed_message">${escapeHtml(schedule.closed_message || '')}</textarea>
              </label>
            ` : ''}
          </div>
        </section>

        <section class="metis-forms-surface">
          <div class="metis-forms-surface__head"><h2>Submitter confirmation</h2></div>
          <div class="metis-forms-grid">
            <label class="metis-forms-check metis-forms-control--full">
              <input type="checkbox" ${submitter.enabled ? 'checked' : ''} data-setting-path="notifications.submitter.enabled">
              <span>Send a confirmation email</span>
            </label>
            <label class="metis-forms-control">
              <span>Email field</span>
              <select class="metis-select" data-setting-path="notifications.submitter.recipient_field">
                <option value="">Pick an email field</option>
                ${emailFields.map((field) => `<option value="${escapeAttr(field.key)}"${field.key === submitter.recipient_field ? ' selected' : ''}>${escapeHtml(field.label || field.key)}</option>`).join('')}
              </select>
            </label>
            <label class="metis-forms-control">
              <span>From name</span>
              <input class="metis-input" type="text" value="${escapeAttr(submitter.from_name || '')}" data-setting-path="notifications.submitter.from_name">
            </label>
            <label class="metis-forms-control">
              <span>From email</span>
              <input class="metis-input" type="email" value="${escapeAttr(submitter.from_email || '')}" data-setting-path="notifications.submitter.from_email">
            </label>
            <label class="metis-forms-control">
              <span>Subject</span>
              <input class="metis-input" type="text" value="${escapeAttr(submitter.subject || '')}" data-setting-path="notifications.submitter.subject">
            </label>
            <label class="metis-forms-check metis-forms-control--full">
              <input type="checkbox" ${submitter.include_submission_data ? 'checked' : ''} data-setting-path="notifications.submitter.include_submission_data">
              <span>Include submitted information in the email</span>
            </label>
            <div class="metis-forms-control metis-forms-control--full">
              <span>Message</span>
              ${renderRichSettingEditor('notifications.submitter.message', submitter.message || '', mergeTokens)}
            </div>
          </div>
        </section>

        <section class="metis-forms-surface">
          <div class="metis-forms-surface__head"><h2>Internal alerts</h2></div>
          <div class="metis-forms-grid">
            <label class="metis-forms-check metis-forms-control--full">
              <input type="checkbox" ${internal.enabled ? 'checked' : ''} data-setting-path="notifications.internal.enabled">
              <span>Send internal alerts</span>
            </label>
            <label class="metis-forms-control">
              <span>General recipient email</span>
              <input class="metis-input" type="email" value="${escapeAttr(internal.general_email || '')}" data-setting-path="notifications.internal.general_email">
            </label>
            <label class="metis-forms-control">
              <span>From name</span>
              <input class="metis-input" type="text" value="${escapeAttr(internal.from_name || '')}" data-setting-path="notifications.internal.from_name">
            </label>
            <label class="metis-forms-control">
              <span>From email</span>
              <input class="metis-input" type="email" value="${escapeAttr(internal.from_email || '')}" data-setting-path="notifications.internal.from_email">
            </label>
            <label class="metis-forms-control">
              <span>Routing field</span>
              <select class="metis-select" data-setting-path="notifications.internal.routing_field">
                <option value="">No routing field</option>
                ${routeFields.map((field) => `<option value="${escapeAttr(field.key)}"${field.key === internal.routing_field ? ' selected' : ''}>${escapeHtml(field.label)}</option>`).join('')}
              </select>
            </label>
            <div class="metis-forms-control metis-forms-control--full">
              <span>Default internal recipients</span>
              <div class="metis-forms-check-grid">
                ${(options.users || []).map((user) => {
                  const checked = Array.isArray(internal.default_user_ids) && internal.default_user_ids.includes(Number(user.value || 0));
                  return `<label class="metis-forms-check"><input type="checkbox" data-notification-user="${escapeAttr(String(user.value || ''))}" ${checked ? 'checked' : ''}><span>${escapeHtml(String(user.label || ''))}</span></label>`;
                }).join('')}
              </div>
            </div>
            <label class="metis-forms-control">
              <span>Subject</span>
              <input class="metis-input" type="text" value="${escapeAttr(internal.subject || '')}" data-setting-path="notifications.internal.subject">
            </label>
            <label class="metis-forms-check metis-forms-control--full">
              <input type="checkbox" ${internal.include_submission_data ? 'checked' : ''} data-setting-path="notifications.internal.include_submission_data">
              <span>Include submitted information in the email</span>
            </label>
            <div class="metis-forms-control metis-forms-control--full">
              <span>Message</span>
              ${renderRichSettingEditor('notifications.internal.message', internal.message || '', mergeTokens)}
            </div>
            <div class="metis-forms-control metis-forms-control--full">
              <div class="metis-forms-editor-tools">
                <span>Routing rules</span>
                <button type="button" class="metis-btn metis-btn-xs" data-add-route>Add route</button>
              </div>
              ${Array.isArray(internal.routes) && internal.routes.length ? internal.routes.map((route, index) => `
                <div class="metis-forms-route-row">
                  <input class="metis-input" type="text" value="${escapeAttr(String(route.value || ''))}" data-route-value="1" data-route-index="${index}" placeholder="Match value">
                  <select class="metis-select" data-route-user="1" data-route-index="${index}">
                    <option value="">Choose user</option>
                    ${(options.users || []).map((user) => `<option value="${escapeAttr(String(user.value || ''))}"${Number(user.value || 0) === Number(route.user_id || 0) ? ' selected' : ''}>${escapeHtml(String(user.label || ''))}</option>`).join('')}
                  </select>
                  <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-remove-route="${index}">Remove</button>
                </div>
              `).join('') : '<div class="metis-forms-empty">No routing rules yet.</div>'}
            </div>
          </div>
        </section>
      </div>
    `;
  }

  function renderPublishHtml(form, boot) {
    const versions = Array.isArray(form.versions) ? form.versions.slice(0, 8) : [];
    return `
      <div class="metis-forms-two-column">
        <section class="metis-forms-surface">
          <div class="metis-forms-surface__head"><h2>Publish</h2></div>
          <div class="metis-forms-grid">
            <label class="metis-forms-control">
              <span>Status</span>
              <select class="metis-select" data-form-prop="status">
                <option value="draft"${form.status === 'draft' ? ' selected' : ''}>Draft</option>
                <option value="published"${form.status === 'published' ? ' selected' : ''}>Published</option>
                <option value="archived"${form.status === 'archived' ? ' selected' : ''}>Archived</option>
              </select>
            </label>
          </div>
          <dl class="metis-forms-definition-list">
            <div><dt>Public URL</dt><dd>${escapeHtml(form.public_url || boot.urls?.public || '')}</dd></div>
            <div><dt>Fields</dt><dd>${escapeHtml(String((form.schema || []).length))}</dd></div>
            <div><dt>Payment field</dt><dd>${form.schema.some((field) => field.type === 'payment') ? 'Present' : 'Not added'}</dd></div>
          </dl>
        </section>

        <section class="metis-forms-surface">
          <div class="metis-forms-surface__head"><h2>Versions</h2></div>
          ${versions.length ? `
            <ul class="metis-forms-version-list">
              ${versions.map((version) => `<li><strong>v${escapeHtml(String(version.version_number || 0))}</strong><span>${version.is_published ? 'Published' : 'Draft'}</span><time>${escapeHtml(String(version.created_at || ''))}</time></li>`).join('')}
            </ul>
          ` : '<div class="metis-forms-empty">No saved versions yet.</div>'}
        </section>
      </div>
    `;
  }

  function renderFieldPicker(state, options) {
    return `
      <div class="metis-forms-picker${state.pickerOpen ? ' is-open' : ''}" ${state.pickerOpen ? '' : 'hidden'} data-picker-backdrop>
        <aside class="metis-forms-picker__sheet" role="dialog" aria-modal="true" aria-label="Add field">
          <div class="metis-forms-picker__head">
            <h2>Add field</h2>
            <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-close-picker>Close</button>
          </div>
          <div class="metis-forms-picker__grid">
            ${(options.field_types || []).map((field) => `
              <button type="button" class="metis-forms-picker__item" data-add-field="${escapeAttr(String(field.type || 'text'))}">
                <span class="metis-forms-picker__icon">${escapeHtml(String(field.icon || '•'))}</span>
                <span class="metis-forms-picker__meta">
                  <strong>${escapeHtml(String(field.label || field.type || 'Field'))}</strong>
                </span>
              </button>
            `).join('')}
          </div>
        </aside>
      </div>
    `;
  }

  function renderPreviewHtml(form) {
    return `
      <div class="metis-forms-preview-card">
        <div class="metis-forms-preview-card__head">
          <h3>${escapeHtml(form.name || 'Untitled form')}</h3>
          ${form.description ? `<p>${escapeHtml(form.description)}</p>` : ''}
        </div>
        <div class="metis-forms-preview-card__body">
          ${(form.schema || []).map((field) => renderPreviewField(field)).join('')}
        </div>
      </div>
    `;
  }

  function renderPreviewField(field) {
    if (field.type === 'payment') {
      const payment = field.payment || {};
      return `
        <section class="metis-forms-preview-field is-full">
          <label>${escapeHtml(field.label || 'Payment')}</label>
          <div class="metis-forms-preview-payment">
            ${(payment.donation_amounts || []).map((amount) => `<span class="metis-forms-preview-pill">$${escapeHtml(Number(amount).toFixed(2))}</span>`).join('')}
            ${payment.allow_custom_amount ? `<input class="metis-input" type="text" value="${escapeAttr(payment.custom_amount_label || 'Other amount')}" disabled>` : ''}
            ${payment.cover_fees_enabled ? `<label class="metis-forms-check"><input type="checkbox" disabled><span>${escapeHtml(payment.cover_fees_label || '')}</span></label>` : ''}
          </div>
        </section>
      `;
    }

    if (field.type === 'repeater') {
      return `
        <section class="metis-forms-preview-field is-${normalizeWidthValue(field.width)}">
          <label>${escapeHtml(field.label || 'Repeater')}</label>
          <div class="metis-forms-preview-repeater">
            ${(field.subfields || []).map((subfield) => `<div class="metis-forms-preview-subfield is-${normalizeWidthValue(subfield.width)}"><span>${escapeHtml(subfield.label || subfield.key)}</span></div>`).join('')}
          </div>
        </section>
      `;
    }

    if (fieldUsesChoices(field.type)) {
      const choices = (field.options_source && field.options_source.parent_field) ? [] : (field.options || []);
      return `
        <section class="metis-forms-preview-field is-${normalizeWidthValue(field.width)}">
          <label>${escapeHtml(field.label || field.key)}</label>
          <select class="metis-select" disabled>
            <option>Select…</option>
            ${choices.map((option) => `<option>${escapeHtml(option.label || option.value || '')}</option>`).join('')}
          </select>
        </section>
      `;
    }

    return `
      <section class="metis-forms-preview-field is-${normalizeWidthValue(field.width)}">
        <label>${escapeHtml(field.label || field.key)}</label>
        ${field.type === 'textarea'
          ? '<textarea class="metis-input" rows="3" disabled></textarea>'
          : `<input class="metis-input" type="${field.type === 'email' ? 'email' : field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}" disabled>`}
      </section>
    `;
  }

  function hydrateForm(input, paymentDefaults) {
    const defaults = defaultForm(paymentDefaults);
    const form = Object.assign({}, defaults, input || {});
    form.name = String(form.name || defaults.name);
    form.slug = String(form.slug || '');
    form.description = String(form.description || '');
    form.status = ['draft', 'published', 'archived'].includes(String(form.status || 'draft')) ? String(form.status || 'draft') : 'draft';
    form.public_url = String(form.public_url || '');
    form.settings = hydrateSettings(form.settings || {}, paymentDefaults);
    form.schema = Array.isArray(form.schema) ? form.schema.map((field) => hydrateField(field, paymentDefaults)) : [];
    form.versions = Array.isArray(form.versions) ? form.versions : [];
    return form;
  }

  function hydrateSettings(input, paymentDefaults) {
    const defaults = defaultForm(paymentDefaults).settings;
    const settings = clone(defaults);
    const source = input || {};

    settings.binding.module = String(source.binding?.module || '');
    settings.binding.flow = String(source.binding?.flow || '');
    settings.binding.campaign_code = String(source.binding?.campaign_code || '');
    settings.access.mode = String(source.access?.mode || 'public');
    settings.access.password = String(source.access?.password || '');
    settings.access.denied_message = String(source.access?.denied_message || defaults.access.denied_message);
    settings.access.roles = Array.isArray(source.access?.roles) ? source.access.roles.map((role) => String(role || '')) : [];
    settings.schedule.enabled = Boolean(source.schedule?.enabled);
    settings.schedule.start_at = String(source.schedule?.start_at || '');
    settings.schedule.end_at = String(source.schedule?.end_at || '');
    settings.schedule.closed_message = String(source.schedule?.closed_message || defaults.schedule.closed_message);
    settings.confirmation.message = String(source.confirmation?.message || defaults.confirmation.message);
    settings.notifications.submitter.enabled = Boolean(source.notifications?.submitter?.enabled);
    settings.notifications.submitter.recipient_field = String(source.notifications?.submitter?.recipient_field || '');
    settings.notifications.submitter.from_name = String(source.notifications?.submitter?.from_name || '');
    settings.notifications.submitter.from_email = String(source.notifications?.submitter?.from_email || '');
    settings.notifications.submitter.include_submission_data = Boolean(source.notifications?.submitter?.include_submission_data);
    settings.notifications.submitter.subject = String(source.notifications?.submitter?.subject || defaults.notifications.submitter.subject);
    settings.notifications.submitter.message = String(source.notifications?.submitter?.message || defaults.notifications.submitter.message);
    settings.notifications.internal.enabled = Boolean(source.notifications?.internal?.enabled);
    settings.notifications.internal.general_email = String(source.notifications?.internal?.general_email || '');
    settings.notifications.internal.from_name = String(source.notifications?.internal?.from_name || '');
    settings.notifications.internal.from_email = String(source.notifications?.internal?.from_email || '');
    settings.notifications.internal.include_submission_data = Boolean(source.notifications?.internal?.include_submission_data);
    settings.notifications.internal.default_user_ids = Array.isArray(source.notifications?.internal?.default_user_ids)
      ? source.notifications.internal.default_user_ids.map((value) => Number(value || 0)).filter((value) => value > 0)
      : [];
    settings.notifications.internal.routing_field = String(source.notifications?.internal?.routing_field || '');
    settings.notifications.internal.subject = String(source.notifications?.internal?.subject || defaults.notifications.internal.subject);
    settings.notifications.internal.message = String(source.notifications?.internal?.message || defaults.notifications.internal.message);
    settings.notifications.internal.routes = Array.isArray(source.notifications?.internal?.routes)
      ? source.notifications.internal.routes.map((route) => ({value: String(route?.value || ''), user_id: Number(route?.user_id || 0)}))
      : [];
    settings.payments = hydratePaymentSettings(source.payments || {}, paymentDefaults);
    return settings;
  }

  function hydratePaymentSettings(input, paymentDefaults) {
    const defaults = defaultPayment(paymentDefaults);
    const source = input || {};
    return {
      enabled: Boolean(source.enabled),
      mode: String(source.mode || defaults.mode),
      campaign_code: String(source.campaign_code || ''),
      currency: String(source.currency || defaults.currency),
      donation_amounts: Array.isArray(source.donation_amounts)
        ? source.donation_amounts.map((value) => Number(value || 0)).filter((value) => Number.isFinite(value) && value > 0)
        : defaults.donation_amounts.slice(),
      allow_custom_amount: source.allow_custom_amount === undefined ? defaults.allow_custom_amount : Boolean(source.allow_custom_amount),
      custom_amount_label: String(source.custom_amount_label || defaults.custom_amount_label),
      cover_fees_enabled: Boolean(source.cover_fees_enabled),
      cover_fees_label: String(source.cover_fees_label || defaults.cover_fees_label),
      fee_percent: source.fee_percent !== undefined && source.fee_percent !== null && source.fee_percent !== ''
        ? Number(source.fee_percent)
        : defaults.fee_percent,
      fee_fixed: source.fee_fixed !== undefined && source.fee_fixed !== null && source.fee_fixed !== ''
        ? Number(source.fee_fixed)
        : defaults.fee_fixed,
      summary_label: String(source.summary_label || defaults.summary_label),
      success_message: String(source.success_message || defaults.success_message)
    };
  }

  function hydrateField(input, paymentDefaults) {
    const raw = input || {};
    const type = allowedFieldTypes().includes(String(raw.type || 'text')) ? String(raw.type || 'text') : 'text';
    return {
      id: slugifyKey(String(raw.id || raw.key || createId('fld_'))),
      key: slugifyKey(String(raw.key || raw.id || createId('field_'))),
      type,
      label: String(raw.label || ''),
      help: String(raw.help || ''),
      placeholder: String(raw.placeholder || ''),
      required: Boolean(raw.required),
      width: type === 'payment' ? 'full' : normalizeWidthValue(raw.width || 'full'),
      searchable: Boolean(raw.searchable),
      validation: {
        min_value: raw.validation && raw.validation.min_value !== undefined && raw.validation.min_value !== null && raw.validation.min_value !== '' ? Number(raw.validation.min_value) : null,
        max_value: raw.validation && raw.validation.max_value !== undefined && raw.validation.max_value !== null && raw.validation.max_value !== '' ? Number(raw.validation.max_value) : null
      },
      conditions: Array.isArray(raw.conditions) ? raw.conditions.map((condition) => ({
        field: String(condition?.field || ''),
        operator: String(condition?.operator || 'equals'),
        value: Array.isArray(condition?.value) ? condition.value.join(', ') : String(condition?.value || '')
      })) : [],
      options: Array.isArray(raw.options) ? raw.options.map((option) => ({
        label: String(option?.label || ''),
        value: String(option?.value || ''),
        category: String(option?.category || '')
      })) : [],
      options_source: {
        type: String(raw.options_source?.type || 'static') || 'static',
        parent_field: String(raw.options_source?.parent_field || ''),
        items: Array.isArray(raw.options_source?.items) ? raw.options_source.items.map((option) => ({
          label: String(option?.label || ''),
          value: String(option?.value || ''),
          category: String(option?.category || '')
        })) : []
      },
      repeat_limit: clamp(Number(raw.repeat_limit || 5), 1, 25),
      subfields: Array.isArray(raw.subfields) ? raw.subfields.map((subfield) => hydrateField(subfield, paymentDefaults)) : [],
      payment: Object.assign(defaultPayment(paymentDefaults), raw.payment || {})
    };
  }

  function defaultForm(paymentDefaults) {
    return {
      id: 0,
      form_uuid: '',
      slug: '',
      name: 'Untitled form',
      description: '',
      status: 'draft',
      public_url: '',
      schema: [],
      settings: {
        binding: {module: '', flow: '', campaign_code: ''},
        access: {mode: 'public', password: '', denied_message: 'This form is not currently available.', roles: []},
        schedule: {enabled: false, start_at: '', end_at: '', closed_message: 'This form is not accepting submissions right now.'},
        confirmation: {message: 'Thanks, your submission has been received.'},
        notifications: {
          submitter: {enabled: false, recipient_field: '', from_name: '', from_email: '', include_submission_data: false, subject: 'We received your submission', message: '<p>Thank you for your submission.</p>'},
          internal: {enabled: false, general_email: '', from_name: '', from_email: '', include_submission_data: true, default_user_ids: [], routing_field: '', subject: 'New form submission', message: '<p>A new form submission has been received.</p>', routes: []}
        },
        payments: defaultPayment(paymentDefaults)
      },
      versions: []
    };
  }

  function defaultPayment(paymentDefaults) {
    const defaults = paymentDefaults || {};
    return {
      enabled: false,
      mode: 'donation',
      campaign_code: '',
      currency: 'usd',
      donation_amounts: [25, 50, 100],
      allow_custom_amount: true,
      custom_amount_label: 'Other amount',
      cover_fees_enabled: false,
      cover_fees_label: String(defaults.cover_fees_label || 'I would like to cover the processing fees.'),
      fee_percent: Number(defaults.fee_percent ?? 2.9),
      fee_fixed: Number(defaults.fee_fixed ?? 0.3),
      summary_label: 'Total',
      success_message: 'Thanks, your submission has been received.'
    };
  }

  function createField(type, paymentDefaults) {
    return hydrateField({
      id: createId('field_'),
      key: createId('field_'),
      type
    }, paymentDefaults);
  }

  function buildSavePayload(form, status) {
    const snapshot = clone(form);
    snapshot.status = status;
    snapshot.slug = slugify(snapshot.slug || snapshot.name || 'form');
    snapshot.schema = normalizeFieldTree(snapshot.schema || []);
    const paymentField = (snapshot.schema || []).find((field) => field && field.type === 'payment') || null;
    snapshot.settings = snapshot.settings || {};
    snapshot.settings.payments = paymentField
      ? hydratePaymentSettings(paymentField.payment || {}, snapshot.settings.payments || {})
      : Object.assign({}, hydratePaymentSettings(snapshot.settings.payments || {}, snapshot.settings.payments || {}), {enabled: false});
    snapshot.settings.notifications.internal.routes = (snapshot.settings.notifications.internal.routes || [])
      .filter((route) => route && String(route.value || '').trim() !== '' && Number(route.user_id || 0) > 0);
    snapshot.settings.notifications.internal.default_user_ids = Array.from(new Set((snapshot.settings.notifications.internal.default_user_ids || []).map((value) => Number(value || 0)).filter((value) => value > 0)));
    if (snapshot.settings.access.mode !== 'role') {
      snapshot.settings.access.roles = [];
    }
    return snapshot;
  }

  function renderRichSettingEditor(path, html, mergeTokens) {
    const key = String(path || '');
    const colorMenu = formsRichColorOptions.map((row) => `<button type="button" class="metis-se-rich-menu-item metis-se-rich-menu-item--color" data-rich-action="color" data-rich-target="${escapeAttr(key)}" data-rich-value="${escapeAttr(row.value)}" data-rich-color="${escapeAttr(row.color)}"><span class="metis-se-color-swatch" style="background:${escapeAttr(row.color)}"></span><span>${escapeHtml(row.label)}</span></button>`).join('');
    const blockMenu = [
      ['P', 'Paragraph'],
      ['H1', 'Heading 1'],
      ['H2', 'Heading 2'],
      ['H3', 'Heading 3'],
      ['H4', 'Heading 4'],
      ['PRE', 'Code']
    ].map((row) => `<button type="button" class="metis-se-rich-menu-item" data-rich-action="block" data-rich-target="${escapeAttr(key)}" data-rich-value="${escapeAttr(row[0])}">${escapeHtml(row[1])}</button>`).join('');
    const sizeMenu = [
      ['default', 'Default'],
      ['sm', 'Small'],
      ['lg', 'Large'],
      ['xl', 'Large+']
    ].map((row) => `<button type="button" class="metis-se-rich-menu-item" data-rich-action="size" data-rich-target="${escapeAttr(key)}" data-rich-value="${escapeAttr(row[0])}">${escapeHtml(row[1])}</button>`).join('');
    const weightMenu = [
      ['600', 'Semi Bold'],
      ['700', 'Bold'],
      ['800', 'Extra Bold']
    ].map((row) => `<button type="button" class="metis-se-rich-menu-item" data-rich-action="weight" data-rich-target="${escapeAttr(key)}" data-rich-value="${escapeAttr(row[0])}">${escapeHtml(row[1])}</button>`).join('');
    const mergeMenu = (mergeTokens || []).map((token) => `<button type="button" class="metis-se-rich-menu-item" data-rich-action="merge" data-rich-target="${escapeAttr(key)}" data-rich-value="${escapeAttr(token.token || '')}">${escapeHtml(token.label || token.token || '')}</button>`).join('');
    const buttons = [
      ['italic', 'italic', 'Italic'],
      ['underline', 'text-underline', 'Underline'],
      ['strikeThrough', 'text-strikethrough', 'Strike Through'],
      ['justifyLeft', 'text-align-left', 'Align Left'],
      ['justifyCenter', 'text-align-center', 'Align Center'],
      ['justifyRight', 'text-align-right', 'Align Right'],
      ['createLink', 'link', 'Insert Link'],
      ['unlink', 'close-outline', 'Remove Link'],
      ['insertDivider', 'divider', 'Insert Divider'],
      ['undo', 'undo', 'Undo'],
      ['redo', 'redo', 'Redo']
    ].map((row) => `<button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="${escapeAttr(row[0])}" data-rich-target="${escapeAttr(key)}" title="${escapeAttr(row[2])}" aria-label="${escapeAttr(row[2])}"><img src="${escapeAttr(iconUrl(row[1]))}" data-icon-fallback="${escapeAttr(iconFallbackUrl(row[1]))}" alt="" aria-hidden="true"></button>`).join('');

    return `
      <div class="metis-forms-rich" data-rich-editor-key="${escapeAttr(path)}">
        <div class="metis-se-rich-tools">
          <div class="metis-se-rich-toolbar metis-forms-rich-toolbar">
            <div class="metis-se-rich-group metis-se-rich-group--actions">
              ${renderRichToolbarDropdown('h1', blockMenu, 'metis-se-rich-dropdown--format', 'Paragraph')}
              ${renderRichToolbarDropdown('text-scale', sizeMenu, 'metis-se-rich-dropdown--size', 'Text Size')}
              ${renderRichToolbarDropdown('text-color', colorMenu, 'metis-se-rich-dropdown--color', 'Text Color')}
              ${renderRichToolbarDropdown('text-bold', weightMenu, 'metis-se-rich-dropdown--weight', 'Weight')}
              ${buttons}
              ${renderRichToolbarDropdown('code', mergeMenu, 'metis-se-rich-dropdown--merge', 'Merge Tags')}
            </div>
          </div>
        </div>
        <div class="metis-forms-rich-editor metis-input" contenteditable="true" data-rich-setting-path="${escapeAttr(path)}">${sanitizeRichHtml(html)}</div>
      </div>
    `;
  }

  function buildMergeTokenOptions(schema) {
    const tokens = [
      {token: '{{form_name}}', label: 'Form name'},
      {token: '{{submission_key}}', label: 'Submission key'},
      {token: '{{submitter_email}}', label: 'Submitter email'},
      {token: '{{ticket_code}}', label: 'Ticket code'},
      {token: '{{ticket_id}}', label: 'Ticket ID'}
    ];
    (schema || []).forEach((field) => {
      appendMergeToken(tokens, field, '');
    });
    return tokens;
  }

  function appendMergeToken(tokens, field, prefixLabel, prefixToken) {
    if (!field || field.type === 'payment') return;
    const key = String(field.key || '').trim();
    const label = String(field.label || field.key || '').trim();
    if (key) {
      tokens.push({
        token: `{{${prefixToken ? `${prefixToken}.${key}` : key}}}`,
        label: prefixLabel ? `${prefixLabel} - ${label}` : label
      });
    }
    if (field.type === 'repeater' && Array.isArray(field.subfields)) {
      field.subfields.forEach((subfield) => appendMergeToken(tokens, subfield, label || prefixLabel, key || prefixToken));
    }
  }

  function renderRichToolbarDropdown(icon, menuHtml, extraClass, label) {
    return `<div class="metis-se-rich-dropdown ${escapeAttr(extraClass || '')}">
      <button type="button" class="metis-se-toolbtn metis-se-rich-menu-trigger metis-se-rich-icon-btn" data-rich-toggle="menu" title="${escapeAttr(label || 'Menu')}" aria-label="${escapeAttr(label || 'Menu')}">
        <img src="${escapeAttr(iconUrl(icon))}" data-icon-fallback="${escapeAttr(iconFallbackUrl(icon))}" alt="" aria-hidden="true">
      </button>
      <div class="metis-se-rich-menu">${menuHtml}</div>
    </div>`;
  }

  function sanitizeRichHtml(html) {
    return normalizeRichTextCharacters(String(html || ''));
  }

  function updateRichSettingProperty(target, state) {
    const path = String(target.getAttribute('data-rich-setting-path') || '');
    if (!path) return;
    const normalizedHtml = normalizeRichTextCharacters(String(target.innerHTML || ''));
    if (String(target.innerHTML || '') !== normalizedHtml) {
      target.innerHTML = normalizedHtml;
      placeCaretAtEnd(target);
    }
    setValueAtPath(state.form.settings, path, normalizedHtml);
    saveRichSelection(target);
  }

  function findRichEditorForControl(target) {
    const container = target.closest('[data-rich-editor-key]');
    return container ? container.querySelector('[data-rich-setting-path]') : null;
  }

  function closeRichMenus(root) {
    (root || document).querySelectorAll('.metis-se-rich-dropdown.is-open').forEach((node) => {
      node.classList.remove('is-open');
    });
  }

  function saveRichSelection(editor) {
    const key = String(editor.getAttribute('data-rich-setting-path') || '');
    const selection = window.getSelection ? window.getSelection() : null;
    if (!key || !selection || !selection.rangeCount) return;
    const range = selection.getRangeAt(0);
    if (!editor.contains(range.commonAncestorContainer)) return;
    formsRichSelectionStore[key] = range.cloneRange();
  }

  function restoreRichSelection(editor) {
    const key = String(editor.getAttribute('data-rich-setting-path') || '');
    const selection = window.getSelection ? window.getSelection() : null;
    const range = key ? formsRichSelectionStore[key] : null;
    if (!selection || !range) return null;
    selection.removeAllRanges();
    selection.addRange(range);
    return range;
  }

  function placeCaretAtEnd(editor) {
    editor.focus();
    const range = document.createRange();
    range.selectNodeContents(editor);
    range.collapse(false);
    const selection = window.getSelection ? window.getSelection() : null;
    if (!selection) return;
    selection.removeAllRanges();
    selection.addRange(range);
    saveRichSelection(editor);
  }

  function applyRichCommand(editor, command, value, state) {
    if (!editor || !command) return;
    editor.focus();
    if (!restoreRichSelection(editor)) {
      placeCaretAtEnd(editor);
    }
    if (command === 'createLink') {
      const url = window.prompt('Enter a URL');
      if (!url) return;
      document.execCommand('createLink', false, url);
    } else if (command === 'insertDivider') {
      document.execCommand('insertHTML', false, '<hr class="metis-inline-divider">');
    } else if (command === 'formatBlock') {
      document.execCommand('formatBlock', false, value === 'P' ? '<p>' : '<' + String(value || 'P').toLowerCase() + '>');
    } else if (command === 'justifyLeft' || command === 'justifyCenter' || command === 'justifyRight') {
      applyRichAlignment(editor, command === 'justifyCenter' ? 'center' : (command === 'justifyRight' ? 'right' : 'left'));
    } else {
      document.execCommand(command, false, null);
    }
    updateRichSettingProperty(editor, state);
  }

  function applyRichAction(editor, action, value, color, state) {
    if (!editor || !action) return;
    editor.focus();
    if (!restoreRichSelection(editor)) {
      placeCaretAtEnd(editor);
    }
    if (action === 'merge') {
      document.execCommand('insertText', false, value);
    } else if (action === 'block') {
      document.execCommand('formatBlock', false, value === 'P' ? '<p>' : '<' + String(value || 'P').toLowerCase() + '>');
    } else if (action === 'size') {
      const sizeMap = {sm: '0.92rem', lg: '1.12rem', xl: '1.28rem'};
      if (value === 'default') {
        document.execCommand('removeFormat', false, null);
      } else {
        applyRichSpanStyle(editor, 'font-size:' + (sizeMap[value] || '1rem'));
      }
    } else if (action === 'color') {
      applyRichSpanStyle(editor, 'color:' + (color || value));
    } else if (action === 'weight') {
      applyRichSpanStyle(editor, 'font-weight:' + value);
    }
    updateRichSettingProperty(editor, state);
  }

  function selectedRichHtml(range) {
    if (!range) return '';
    const div = document.createElement('div');
    div.appendChild(range.cloneContents());
    return div.innerHTML;
  }

  function wrapRichSelection(editor, html) {
    if (!editor) return;
    restoreRichSelection(editor);
    document.execCommand('insertHTML', false, html);
    editor.innerHTML = normalizeRichTextCharacters(String(editor.innerHTML || ''));
    saveRichSelection(editor);
  }

  function applyRichSpanStyle(editor, styleText) {
    if (!editor) return;
    const selection = window.getSelection ? window.getSelection() : null;
    const range = restoreRichSelection(editor) || (selection && selection.rangeCount ? selection.getRangeAt(0) : null);
    if (!range || range.collapsed) return;
    const html = selectedRichHtml(range);
    wrapRichSelection(editor, '<span style="' + escapeAttr(styleText) + '">' + html + '</span>');
  }

  function richTopLevelNodeForRange(editor, range) {
    if (!editor || !range) return null;
    let node = range.commonAncestorContainer;
    if (!node) return null;
    if (node.nodeType === Node.TEXT_NODE) node = node.parentNode;
    while (node && node.parentNode && node.parentNode !== editor) {
      node = node.parentNode;
    }
    if (node && node !== editor && node.nodeType === Node.ELEMENT_NODE) return node;
    return null;
  }

  function applyRichAlignment(editor, align) {
    if (!editor || !align) return;
    const selection = window.getSelection ? window.getSelection() : null;
    const range = restoreRichSelection(editor) || (selection && selection.rangeCount ? selection.getRangeAt(0) : null);
    const target = richTopLevelNodeForRange(editor, range);
    if (target) {
      target.style.textAlign = align;
    } else {
      editor.style.textAlign = align;
    }
    saveRichSelection(editor);
  }

  function setValueAtPath(target, path, value) {
    const parts = String(path || '').split('.').filter(Boolean);
    if (!parts.length || parts.some(isUnsafeObjectKey)) return;
    let cursor = target;
    for (let index = 0; index < parts.length - 1; index += 1) {
      const key = parts[index];
      const existing = ownValue(cursor, key);
      if (existing === undefined || existing === null || typeof existing !== 'object') {
        setOwnValue(cursor, key, createPlainRecord());
      }
      cursor = ownValue(cursor, key);
    }
    setOwnValue(cursor, parts[parts.length - 1], value);
  }

  function isUnsafeObjectKey(key) {
    return key === '__proto__' || key === 'prototype' || key === 'constructor';
  }

  function createPlainRecord() {
    return Object.create(null);
  }

  function ownValue(target, key) {
    const descriptor = Object.getOwnPropertyDescriptor(target, String(key));
    return descriptor ? descriptor.value : undefined;
  }

  function setOwnValue(target, key, value) {
    Object.defineProperty(target, String(key), {
      value,
      writable: true,
      enumerable: true,
      configurable: true
    });
  }

  function normalizeFieldTree(fields) {
    return (fields || []).map((field) => {
      const normalized = clone(field);
      normalized.id = slugifyKey(normalized.id || normalized.key || createId('field_'));
      normalized.key = slugifyKey(normalized.key || normalized.id || createId('field_'));
      normalized.label = String(normalized.label || '');
      normalized.help = String(normalized.help || '');
      normalized.placeholder = String(normalized.placeholder || '');
      normalized.width = normalized.type === 'payment' ? 'full' : normalizeWidthValue(normalized.width);
      normalized.repeat_limit = clamp(Number(normalized.repeat_limit || 5), 1, 25);
      normalized.validation = normalizeValidationPayload(normalized.validation || {});
      if (!fieldUsesChoices(normalized.type)) {
        normalized.options = [];
        normalized.options_source = {type: '', parent_field: '', items: []};
        normalized.searchable = false;
      } else {
        normalized.options_source = normalized.options_source || {type: '', parent_field: '', items: []};
        normalized.options_source.type = normalizeChoiceSourceType(normalized.options_source.type);
        normalized.options_source.parent_field = slugifyKey(String(normalized.options_source.parent_field || ''));
        normalized.options_source.items = clone(normalized.options_source.items || []);
        if (normalized.options_source.type === 'static' || !normalized.options_source.type) {
          normalized.options_source.type = 'static';
          normalized.options_source.items = clone(normalized.options || []);
        }
      }
      if (normalized.type !== 'repeater') {
        normalized.subfields = [];
      } else {
        normalized.subfields = normalizeFieldTree(normalized.subfields || []);
      }
      if (normalized.type !== 'payment') {
        normalized.payment = {};
      } else {
        normalized.payment = hydratePaymentSettings(normalized.payment || {}, normalized.payment || {});
        normalized.width = 'full';
      }
      normalized.conditions = normalizeConditionPayload(normalized.conditions || []);
      return normalized;
    });
  }

  function normalizeValidationPayload(validation) {
    const source = validation || {};
    const minLength = Number(source.min_length || 0);
    const maxLength = Number(source.max_length || 0);
    const minValue = source.min_value === undefined || source.min_value === null || source.min_value === '' ? null : Number(source.min_value);
    const maxValue = source.max_value === undefined || source.max_value === null || source.max_value === '' ? null : Number(source.max_value);
    return {
      min_length: Number.isFinite(minLength) && minLength > 0 ? Math.floor(minLength) : 0,
      max_length: Number.isFinite(maxLength) && maxLength > 0 ? Math.floor(maxLength) : 0,
      min_value: Number.isFinite(minValue) ? minValue : null,
      max_value: Number.isFinite(maxValue) ? maxValue : null
    };
  }

  function normalizeConditionPayload(conditions) {
    const allowed = new Set(['equals', 'not_equals', 'contains', 'empty', 'not_empty']);
    return (conditions || []).map((condition) => ({
      field: slugifyKey(String(condition?.field || '')),
      operator: allowed.has(String(condition?.operator || 'equals')) ? String(condition.operator || 'equals') : 'equals',
      value: Array.isArray(condition?.value) ? condition.value.slice() : String(condition?.value || '')
    })).filter((condition) => condition.field);
  }

  function normalizeChoiceSourceType(value) {
    const type = String(value || '');
    return ['static', 'grandys_categories', 'grandys_items', 'campaigns'].includes(type) ? type : '';
  }

  function getSelectedNode(schema, selection) {
    if (!selection) return null;
    const field = schema.find((item) => item.id === selection.fieldId);
    if (!field) return null;
    if (selection.kind === 'field') {
      return {
        kind: 'field',
        item: field,
        collection: schema,
        index: schema.findIndex((item) => item.id === field.id),
        parent: null,
        selection
      };
    }
    if (selection.kind === 'subfield' && field.type === 'repeater') {
      const subfield = (field.subfields || []).find((item) => item.id === selection.subfieldId);
      if (!subfield) return null;
      return {
        kind: 'subfield',
        item: subfield,
        collection: field.subfields,
        index: field.subfields.findIndex((item) => item.id === subfield.id),
        parent: field,
        selection
      };
    }
    return null;
  }

  function removeSelectedNode(state) {
    const node = getSelectedNode(state.form.schema, state.selection);
    if (!node) return;
    node.collection.splice(node.index, 1);
    state.selection = null;
    state.editorSection = 'basics';
  }

  function lookupSelectionTarget(raw, schema) {
    const parts = String(raw || '').split(':');
    if (parts[0] === 'field' && parts[1]) {
      return getSelectedNode(schema, {kind: 'field', fieldId: parts[1]});
    }
    if (parts[0] === 'subfield' && parts[1] && parts[2]) {
      return getSelectedNode(schema, {kind: 'subfield', fieldId: parts[1], subfieldId: parts[2]});
    }
    return null;
  }

  function selectionKey(node) {
    return node.kind === 'field' ? `field:${node.item.id}` : `subfield:${node.parent.id}:${node.item.id}`;
  }

  function editorSections(field) {
    const sections = ['basics'];
    if (fieldUsesChoices(field.type)) sections.push('choices');
    if (field.type !== 'payment') sections.push('visibility');
    if (field.type === 'repeater') sections.push('repeater');
    if (field.type === 'payment') sections.push('payment');
    return sections;
  }

  function defaultEditorSection(field) {
    if (field.type === 'payment') return 'payment';
    if (field.type === 'repeater') return 'repeater';
    if (fieldUsesChoices(field.type)) return 'choices';
    return 'basics';
  }

  function fieldUsesChoices(type) {
    return ['select', 'radio', 'checkbox'].includes(String(type || ''));
  }

  function fieldSupportsPlaceholder(type) {
    return ['text', 'email', 'number', 'textarea'].includes(String(type || ''));
  }

  function allowedFieldTypes() {
    return ['text', 'email', 'number', 'textarea', 'select', 'radio', 'checkbox', 'date', 'repeater', 'payment'];
  }

  function normalizeWidthValue(value) {
    const width = String(value || 'full');
    return ['full', 'half', 'narrow'].includes(width) ? width : 'full';
  }

  function nextWidth(value) {
    const current = normalizeWidthValue(value);
    if (current === 'full') return 'half';
    if (current === 'half') return 'narrow';
    return 'full';
  }

  function widthLabel(value) {
    const width = normalizeWidthValue(value);
    if (width === 'half') return 'Half';
    if (width === 'narrow') return 'Narrow';
    return 'Wide';
  }

  function fieldLabel(type) {
    const labels = {
      text: 'Text',
      email: 'Email',
      number: 'Number',
      textarea: 'Long text',
      select: 'Dropdown',
      radio: 'Radio group',
      checkbox: 'Checkboxes',
      date: 'Date',
      repeater: 'Repeater',
      payment: 'Payment'
    };
    return labels[type] || 'Field';
  }

  function parseOptionsText(raw) {
    return String(raw || '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((line) => {
      const parts = line.split('|').map((part) => part.trim());
      return {
        label: parts[0] || '',
        value: slugifyKey(parts[1] || parts[0] || ''),
        category: slugifyKey(parts[2] || '')
      };
    }).filter((option) => option.label && option.value);
  }

  function toOptionsText(options) {
    return (options || []).map((option) => {
      const parts = [option.label || '', option.value || ''];
      if (option.category) parts.push(option.category);
      return parts.join('|');
    }).join('\n');
  }

  function previewOptionCount(field, sourceType) {
    if (sourceType === 'grandys_categories') {
      return `${(field.options_source.items || []).length} category options ready.`;
    }
    if (sourceType === 'grandys_items') {
      return `${(field.options_source.items || []).length} item options loaded and filtered by category when the form runs.`;
    }
    if (sourceType === 'campaigns') {
      return `${(field.options_source.items || []).length} campaign options ready.`;
    }
    return '';
  }

  function parseMoneyChoices(raw) {
    return String(raw || '')
      .split(',')
      .map((part) => Number(String(part).trim()))
      .filter((value) => Number.isFinite(value) && value > 0)
      .map((value) => Math.round(value * 100) / 100);
  }

  function calculateGrossTotals(amount, feePercent, feeFixed, coverFees) {
    const base = amount > 0 ? roundMoney(amount) : 0;
    if (!coverFees || base <= 0) {
      return {base, fee: 0, total: base};
    }
    const percentDecimal = Math.max(0, Number(feePercent || 0)) / 100;
    const fixed = Math.max(0, Number(feeFixed || 0));
    const gross = Math.ceil((((base + fixed) / Math.max(0.000001, (1 - percentDecimal))) * 100)) / 100;
    return {base, fee: roundMoney(gross - base), total: roundMoney(gross)};
  }

  function selectedDonationAmount(form) {
    const custom = Number(form.querySelector('input[name="_donation_amount_custom"]')?.value || 0);
    if (custom > 0) return roundMoney(custom);
    const checked = form.querySelector('input[name="_donation_amount_choice"]:checked');
    return checked ? roundMoney(Number(checked.value || 0)) : 0;
  }

  async function publicRequest(url, payload) {
    const body = new URLSearchParams();
    body.set('payload', JSON.stringify(payload || {}));
    const response = await fetch(String(url || window.location.href), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body
    });
    const data = await response.json();
    return data;
  }

  async function adminRequest(action, body) {
    return performAjaxRequest(action, body);
  }

  async function performAjaxRequest(action, body) {
    if (!(window.Metis && Metis.ui && Metis.ui.ajax && typeof Metis.ui.ajax.post === 'function')) {
      throw new Error('Centralized AJAX service is unavailable.');
    }

    const payload = await Metis.ui.ajax.post(Object.assign({}, body || {}, {action: String(action || '')}));
    if (!payload || payload.success === false) {
      throw new Error(
        payload?.data?.message ||
        payload?.message ||
        'Request failed.'
      );
    }
    return payload.data || {};
  }

  function captureFieldValues(container) {
    const data = {};
    container.querySelectorAll('[data-field-key]').forEach((section) => {
      if (!(section instanceof HTMLElement)) return;
      const key = String(section.getAttribute('data-field-key') || '');
      if (!key) return;
      const inputs = Array.from(section.querySelectorAll('input, select, textarea'));
      if (inputs.length === 0) return;
      const checkboxGroup = inputs.filter((input) => input instanceof HTMLInputElement && input.type === 'checkbox');
      const radioGroup = inputs.filter((input) => input instanceof HTMLInputElement && input.type === 'radio');
      if (checkboxGroup.length > 1) {
        data[key] = checkboxGroup.filter((input) => input.checked).map((input) => input.value || '1').join(',');
        return;
      }
      if (radioGroup.length > 0) {
        const selected = radioGroup.find((input) => input.checked);
        data[key] = selected ? (selected.value || '') : '';
        return;
      }
      const first = inputs[0];
      if (first instanceof HTMLInputElement && first.type === 'checkbox') {
        data[key] = first.checked ? (first.value || '1') : '';
        return;
      }
      data[key] = first.value || '';
    });
    return data;
  }

  function conditionPasses(condition, context) {
    const field = String(condition.field || '');
    const actual = String(context[field] || '');
    const expected = String(condition.value || '');
    switch (String(condition.operator || 'equals')) {
      case 'not_equals': return actual !== expected;
      case 'contains': return actual.includes(expected);
      case 'empty': return actual === '';
      case 'not_empty': return actual !== '';
      default: return actual === expected;
    }
  }

  function formDataToObject(formData) {
    const output = {};
    for (const [key, value] of formData.entries()) {
      assignNestedFormValue(output, key, value);
    }
    return output;
  }

  function readElementValue(target) {
    if (target instanceof HTMLInputElement) {
      if (target.type === 'checkbox') return target.checked;
      if (target.type === 'number') return target.value;
      return target.value;
    }
    if (target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement) {
      return target.value;
    }
    return '';
  }

  function notify(kind, message) {
    const level = kind === 'success' ? 'success' : kind === 'warning' ? 'warning' : 'error';
    if (window.Metis && Metis.ui && Metis.ui.toast && typeof Metis.ui.toast[level] === 'function') {
      Metis.ui.toast[level](String(message || ''));
      return;
    }
    if (window.console && typeof window.console.warn === 'function') {
      window.console.warn(String(message || ''));
    }
  }

  function showPublicAlert(node, message, kind) {
    if (!node) return;
    node.hidden = false;
    node.className = `metis-alert ${kind === 'success' ? 'metis-alert-success' : 'metis-alert-error'}`;
    node.textContent = String(message || '');
  }

  function hidePublicAlert(node) {
    if (!node) return;
    node.hidden = true;
    node.textContent = '';
    node.className = 'metis-alert';
  }

  function clearPublicErrors(formEl, alertEl) {
    hidePublicAlert(alertEl);
    formEl.querySelectorAll('.metis-forms-field-error').forEach((node) => node.remove());
    formEl.querySelectorAll('[aria-invalid="true"]').forEach((node) => node.removeAttribute('aria-invalid'));
    formEl.querySelectorAll('.is-error').forEach((node) => node.classList.remove('is-error'));
  }

  function applyPublicErrors(formEl, alertEl, result) {
    showPublicAlert(alertEl, result.message || result.error || 'Unable to submit the form.', 'error');
    const errors = result && typeof result === 'object' ? (result.errors || {}) : {};
    Object.entries(errors).forEach(([key, message]) => {
      const section = formEl.querySelector(`[data-field-key="${cssEscape(key)}"]`);
      if (!(section instanceof HTMLElement)) return;
      section.classList.add('is-error');
      const control = section.querySelector('input, select, textarea');
      if (control instanceof HTMLElement) {
        control.setAttribute('aria-invalid', 'true');
      }
      const error = document.createElement('p');
      error.className = 'metis-forms-field-error';
      error.textContent = String(message || 'Please review this field.');
      section.appendChild(error);
    });
  }

  function setPublicSubmitting(button, submitting, pendingLabel = 'Submitting...') {
    if (!(button instanceof HTMLButtonElement)) return;
    if (window.Metis && Metis.ui && Metis.ui.form && typeof Metis.ui.form.setSubmitting === 'function') {
      Metis.ui.form.setSubmitting(button, submitting, {loadingLabel: pendingLabel});
      return;
    }
    if (!button.dataset.originalLabel) {
      button.dataset.originalLabel = button.textContent || 'Submit';
    }
    button.disabled = Boolean(submitting);
    button.setAttribute('aria-busy', submitting ? 'true' : 'false');
    button.textContent = submitting ? pendingLabel : (button.dataset.originalLabel || 'Submit');
  }

  function showSuccessOverlay(root, message) {
    const overlay = root.querySelector('#metis-forms-success-overlay');
    if (!(overlay instanceof HTMLElement)) return;
    const messageNode = overlay.querySelector('[data-success-message]');
    if (messageNode) {
      messageNode.textContent = String(message || 'Thanks, your submission has been received.');
    }
    overlay.hidden = false;
  }

  function assignNestedFormValue(target, rawKey, value) {
    const parts = parseFormKey(rawKey);
    if (parts.length === 0) return;
    let cursor = target;
    for (let index = 0; index < parts.length; index += 1) {
      const part = parts[index];
      if (isUnsafeObjectKey(part)) return;
      const last = index === parts.length - 1;
      const next = parts[index + 1];
      const nextIsIndex = /^\d+$/.test(String(next || ''));

      if (last) {
        if (Array.isArray(cursor)) {
          const position = /^\d+$/.test(part) ? Number(part) : cursor.length;
          const slot = String(position);
          const existing = ownValue(cursor, slot);
          if (existing === undefined) {
            setOwnValue(cursor, slot, value);
          } else if (Array.isArray(existing)) {
            existing.push(value);
          } else {
            setOwnValue(cursor, slot, [existing, value]);
          }
        } else {
          const existing = ownValue(cursor, part);
          if (existing !== undefined) {
            if (Array.isArray(existing)) {
              existing.push(value);
            } else {
              setOwnValue(cursor, part, [existing, value]);
            }
          } else {
            setOwnValue(cursor, part, value);
          }
        }
        return;
      }

      if (Array.isArray(cursor)) {
        const position = /^\d+$/.test(part) ? Number(part) : cursor.length;
        const slot = String(position);
        const existing = ownValue(cursor, slot);
        if (existing === undefined || existing === null || typeof existing !== 'object') {
          setOwnValue(cursor, slot, nextIsIndex ? [] : createPlainRecord());
        }
        cursor = ownValue(cursor, slot);
      } else {
        const existing = ownValue(cursor, part);
        if (existing === undefined || existing === null || typeof existing !== 'object') {
          setOwnValue(cursor, part, nextIsIndex ? [] : createPlainRecord());
        }
        cursor = ownValue(cursor, part);
      }
    }
  }

  function parseFormKey(rawKey) {
    const matches = String(rawKey || '').match(/([^[\]]+)/g);
    return matches ? matches.map((part) => String(part)) : [];
  }

  function confirmAction(message) {
    if (window.Metis && Metis.ui && Metis.ui.confirm && typeof Metis.ui.confirm.open === 'function') {
      return Metis.ui.confirm.open({message});
    }
    return Promise.resolve(false);
  }

  function downloadText(filename, text) {
    const blob = new Blob([text], {type: 'text/csv;charset=utf-8'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
  }

  function formatMoney(value, currency) {
    try {
      return new Intl.NumberFormat(undefined, {style: 'currency', currency: String(currency || 'USD').toUpperCase()}).format(Number(value || 0));
    } catch (_) {
      return `$${Number(value || 0).toFixed(2)}`;
    }
  }

  function roundMoney(value) {
    return Math.round(Number(value || 0) * 100) / 100;
  }

  function moveInCollection(collection, index, delta) {
    if (!Array.isArray(collection) || index < 0 || index >= collection.length || delta === 0) return;
    const nextIndex = clamp(index + delta, 0, collection.length - 1);
    if (nextIndex === index) return;
    const [item] = collection.splice(index, 1);
    collection.splice(nextIndex, 0, item);
  }

  function slugify(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  function slugifyKey(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/[^a-z0-9_]+/g, '_')
      .replace(/^_+|_+$/g, '');
  }

  function createId(prefix) {
    return `${prefix}${Math.random().toString(36).slice(2, 10)}`;
  }

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function parseJsonScript(id) {
    const node = document.getElementById(id);
    if (!node) return null;
    try {
      return JSON.parse(node.textContent || 'null');
    } catch (_) {
      return null;
    }
  }

  function setText(root, selector, text) {
    const node = root.querySelector(selector);
    if (node) node.textContent = String(text || '');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function escapeAttr(value) {
    return escapeHtml(value);
  }

  function appBasePath() {
    const ajax = String(window.metisFormsAjax || '').trim();
    if (ajax) {
      try {
        const url = new URL(ajax, window.location.origin);
        const path = String(url.pathname || '').replace(/\/api\/ajax\/?$/i, '');
        return path.replace(/\/+$/, '');
      } catch (_error) {}
    }
    const pathname = String(window.location.pathname || '');
    const adminPos = pathname.toLowerCase().indexOf('/admin/');
    if (adminPos > -1) return pathname.slice(0, adminPos).replace(/\/+$/, '');
    return '';
  }

  function iconUrl(slug) {
    return appBasePath() + '/svg/' + encodeURIComponent(String(slug || '').replace(/_/g, '-'));
  }

  function iconFallbackUrl(slug) {
    return appBasePath() + '/assets/Images/icons/' + encodeURIComponent(String(slug || '')) + '.svg';
  }

  function bindIconFallbacks(scope) {
    const rootNode = scope && scope.querySelectorAll ? scope : document;
    rootNode.querySelectorAll('img[data-icon-fallback]').forEach((img) => {
      if (img.getAttribute('data-fallback-bound') === '1') return;
      img.setAttribute('data-fallback-bound', '1');
      img.addEventListener('error', () => {
        const fallback = String(img.getAttribute('data-icon-fallback') || '').trim();
        if (!fallback || img.getAttribute('src') === fallback) return;
        img.setAttribute('src', fallback);
      });
    });
  }

  function normalizeRichTextCharacters(value) {
    return String(value || '')
      .replace(/\u00a0/g, ' ')
      .replace(/Â /g, ' ')
      .replace(/Â(?=\s|<|$)/g, '');
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(String(value || ''));
    }
    return String(value || '').replace(/["\\#.;:?+*~\[\]()=><|/]/g, '\\$&');
  }
})();
