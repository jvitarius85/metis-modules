(function () {
  const adminBoot = parseJson('#metis-forms-admin-data', null);
  const builderRoot = document.querySelector('[data-builder-view="1"]');
  const entriesRoot = document.querySelector('[data-entries-view="1"]');
  const settingsRoot = document.querySelector('[data-settings-view="1"]');
  const publicRoot = document.querySelector('[data-metis-forms-public="1"]');

  if (builderRoot && adminBoot) initBuilder(builderRoot, adminBoot);
  if (entriesRoot) initEntries(entriesRoot);
  if (settingsRoot && adminBoot) initSettings(settingsRoot, adminBoot);
  if (publicRoot) initPublic(publicRoot);

  function initBuilder(root, boot) {
    const ajax = window.metisFormsAjax || {};
    const canManage = root.dataset.canManage === '1';
    const canDelete = root.dataset.canDelete === '1';
    const state = {
      current: boot.selected || freshForm(),
      selectedFieldId: null,
      draggedFieldId: null,
      draggedType: null,
      draggedSubfieldId: null,
      fieldLibrary: Array.isArray(boot.field_library) ? boot.field_library : []
    };

    const ui = {
      alert: qs(root, '#metis-forms-alert'),
      palette: qs(root, '#metis-forms-palette'),
      canvas: qs(root, '#metis-forms-canvas-list'),
      versions: qs(root, '#metis-forms-versions'),
      name: qs(root, '#metis-forms-name'),
      slug: qs(root, '#metis-forms-slug'),
      status: qs(root, '#metis-forms-status'),
      publicLink: qs(root, '#metis-forms-public-link'),
      fieldModal: qs(root, '#metis-forms-field-modal'),
      inspectorEmpty: qs(root, '#metis-forms-inspector-empty'),
      inspectorPanel: qs(root, '#metis-forms-inspector-panel'),
      fieldTypeNote: qs(root, '#metis-forms-field-type-note'),
      fieldLabel: qs(root, '#metis-forms-field-label'),
      fieldKey: qs(root, '#metis-forms-field-key'),
      fieldHelp: qs(root, '#metis-forms-field-help'),
      fieldPlaceholder: qs(root, '#metis-forms-field-placeholder'),
      fieldRequired: qs(root, '#metis-forms-field-required'),
      fieldHalf: qs(root, '#metis-forms-field-half'),
      fieldFormat: qs(root, '#metis-forms-field-format'),
      fieldMinLength: qs(root, '#metis-forms-field-min-length'),
      fieldMaxLength: qs(root, '#metis-forms-field-max-length'),
      fieldMin: qs(root, '#metis-forms-field-min'),
      fieldMax: qs(root, '#metis-forms-field-max'),
      fieldOptions: qs(root, '#metis-forms-field-options'),
      fieldSource: qs(root, '#metis-forms-field-source'),
      fieldSourceItems: qs(root, '#metis-forms-field-source-items'),
      fieldSearchable: qs(root, '#metis-forms-field-searchable'),
      fieldDependsOn: qs(root, '#metis-forms-field-depends-on'),
      sourcePreview: qs(root, '#metis-forms-source-preview'),
      conditionList: qs(root, '#metis-forms-condition-list'),
      addCondition: qs(root, '#metis-forms-add-condition'),
      pricingEnabled: qs(root, '#metis-forms-pricing-enabled'),
      pricingType: qs(root, '#metis-forms-pricing-type'),
      pricingAmount: qs(root, '#metis-forms-pricing-amount'),
      pricingChoiceList: qs(root, '#metis-forms-pricing-choice-list'),
      repeatLimit: qs(root, '#metis-forms-repeat-limit'),
      subfieldList: qs(root, '#metis-forms-subfield-list'),
      addSubfield: qs(root, '#metis-forms-add-subfield'),
      trashZone: qs(root, '#metis-forms-trash-zone'),
      inspectorScoped: qsa(root, '[data-inspector-for]'),
      pricingScoped: qsa(root, '[data-pricing-scope]')
    };

    const request = async (action, body) => {
      const params = new URLSearchParams({ action, nonce: ajax.nonce || '' });
      Object.entries(body || {}).forEach(([key, value]) => params.append(key, value));
      const response = await fetch(ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: params });
      const payload = await response.json();
      if (!payload?.success) throw new Error(payload?.data?.message || 'Request failed');
      return payload.data || {};
    };

    if (state.current?.schema?.length) {
      state.selectedFieldId = state.current.schema[0].id;
    }

    render();

    root.addEventListener('click', async (event) => {
      const openFieldSettings = event.target.closest('[data-open-field-settings]');
      if (openFieldSettings) {
        event.preventDefault();
        event.stopPropagation();
        flushSelectedFieldState();
        state.selectedFieldId = openFieldSettings.dataset.openFieldSettings || null;
        renderCanvas();
        renderInspector();
        openModal(ui.fieldModal);
        return;
      }

      const fieldCard = event.target.closest('[data-field-id]');
      if (fieldCard) {
        flushSelectedFieldState();
        state.selectedFieldId = fieldCard.dataset.fieldId || null;
        if (ui.fieldModal?.classList.contains('metis-open')) renderInspector();
        renderCanvas();
        return;
      }

      const removeButton = event.target.closest('#metis-forms-delete-field');
      if (removeButton && canManage) {
        state.current.schema = (state.current.schema || []).filter((field) => field.id !== state.selectedFieldId);
        state.selectedFieldId = state.current.schema[0]?.id || null;
        renderCanvas();
        renderInspector();
        return;
      }

      if (event.target.closest('#metis-forms-save') && canManage) {
        try {
          flushSelectedFieldState();
          syncMeta();
          const data = await request('metis_forms_save', { payload: JSON.stringify(state.current) });
          state.current = data.form || state.current;
          state.selectedFieldId = state.current.schema[0]?.id || null;
          render();
          showAlert('Form saved.');
        } catch (error) {
          showAlert(error.message, 'error');
        }
      }

      if (event.target.closest('#metis-forms-duplicate') && state.current?.id) {
        try {
          const data = await request('metis_forms_duplicate', { form_id: String(state.current.id) });
          location.href = `${location.pathname}?form_id=${encodeURIComponent(String(data.form.id))}`;
        } catch (error) {
          showAlert(error.message, 'error');
        }
      }

      if (event.target.closest('#metis-forms-publish') && state.current?.id) {
        try {
          flushSelectedFieldState();
          syncMeta();
          await request('metis_forms_save', { payload: JSON.stringify(state.current) });
          const data = await request('metis_forms_publish', { form_id: String(state.current.id) });
          state.current = data.form || state.current;
          render();
          showAlert('Form published.');
        } catch (error) {
          showAlert(error.message, 'error');
        }
      }

      if (event.target.closest('#metis-forms-delete') && state.current?.id && canDelete) {
        if (!window.confirm(`Delete ${state.current.name}? This removes versions and submissions.`)) return;
        try {
          await request('metis_forms_delete', { form_id: String(state.current.id) });
          location.href = root.dataset.formsHomeUrl || location.href;
        } catch (error) {
          showAlert(error.message, 'error');
        }
      }
    });

    ui.name?.addEventListener('input', () => {
      if (!ui.slug?.dataset.manual) ui.slug.value = slugify(ui.name.value);
    });
    ui.slug?.addEventListener('input', () => { ui.slug.dataset.manual = '1'; });

    ['input', 'change'].forEach((eventName) => {
      [ui.fieldLabel, ui.fieldKey, ui.fieldHelp, ui.fieldPlaceholder, ui.fieldRequired, ui.fieldHalf, ui.fieldFormat, ui.fieldMinLength, ui.fieldMaxLength, ui.fieldMin, ui.fieldMax, ui.fieldOptions, ui.fieldSource, ui.fieldSourceItems, ui.fieldSearchable, ui.fieldDependsOn].forEach((node) => {
        node?.addEventListener(eventName, () => {
          const field = syncSelectedFieldBasics();
          if (!field) return;
          field.options = parseOptions(ui.fieldOptions.value);
          field.options_source = { type: ui.fieldSource.value, items: parseOptions(ui.fieldSourceItems.value), parent_field: ui.fieldDependsOn?.value || '' };
          field.searchable = !!ui.fieldSearchable?.checked;
          field.depends_on = ui.fieldDependsOn?.value || '';
          if (eventName === 'change') {
            renderInspector();
          }
          renderCanvas();
        });
      });
    });

    ui.addCondition?.addEventListener('click', () => {
      const field = selectedField();
      if (!field) return;
      field.conditions = Array.isArray(field.conditions) ? field.conditions : [];
      field.conditions.push({ source: '', operator: 'equals', value: '' });
      renderConditionRows(field.conditions);
    });

    ['input', 'change'].forEach((eventName) => {
      [ui.pricingEnabled, ui.pricingType, ui.pricingAmount, ui.repeatLimit].forEach((node) => {
        node?.addEventListener(eventName, () => {
          const field = selectedField();
          if (!field) return;
          syncConditionsFromDom(field);
          syncPricingFromDom(field);
          syncSubfieldsFromDom(field);
          if (eventName === 'change') renderInspector();
          renderCanvas();
        });
      });
    });

    qs(root, '#metis-forms-preview-source')?.addEventListener('click', async () => {
      try {
        const source = { type: ui.fieldSource.value, items: parseOptions(ui.fieldSourceItems.value) };
        const data = await request('metis_forms_dynamic_options', { source: JSON.stringify(source) });
        const preview = (data.options || []).slice(0, 10).map((item) => item.label).join(', ');
        ui.sourcePreview.textContent = preview ? `Preview: ${preview}` : 'No options resolved.';
      } catch (error) {
        ui.sourcePreview.textContent = error.message;
      }
    });

    ui.conditionList?.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-remove-condition]');
      if (!remove) return;
      remove.closest('[data-condition-index]')?.remove();
      const field = selectedField();
      if (!field) return;
      syncConditionsFromDom(field);
      renderCanvas();
    });

    ui.conditionList?.addEventListener('input', () => {
      const field = selectedField();
      if (!field) return;
      syncConditionsFromDom(field);
      renderCanvas();
    });

    ui.conditionList?.addEventListener('change', () => {
      const field = selectedField();
      if (!field) return;
      syncConditionsFromDom(field);
      renderCanvas();
    });

    ui.pricingChoiceList?.addEventListener('input', () => {
      const field = selectedField();
      if (!field) return;
      syncPricingFromDom(field);
      renderCanvas();
    });

    ui.addSubfield?.addEventListener('click', () => {
      const field = selectedField();
      if (!field || field.type !== 'repeater') return;
      field.subfields = Array.isArray(field.subfields) ? field.subfields : [];
      field.subfields.push(blankSubfield(field.subfields.length + 1));
      renderSubfieldRows(field.subfields);
    });

    ui.subfieldList?.addEventListener('input', () => {
      const field = selectedField();
      if (!field) return;
      syncSubfieldsFromDom(field);
      renderCanvas();
    });

    ui.subfieldList?.addEventListener('change', () => {
      const field = selectedField();
      if (!field) return;
      syncSubfieldsFromDom(field);
      renderCanvas();
    });

    ui.subfieldList?.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-remove-subfield]');
      if (!remove) return;
      remove.closest('[data-subfield-id]')?.remove();
      const field = selectedField();
      if (!field) return;
      syncSubfieldsFromDom(field);
      renderSubfieldRows(field.subfields || []);
      renderCanvas();
    });

    ui.subfieldList?.addEventListener('dragstart', (event) => {
      const row = event.target.closest('[data-subfield-id]');
      if (!row || !canManage) return;
      state.draggedSubfieldId = row.dataset.subfieldId || null;
      event.dataTransfer.setData('text/plain', state.draggedSubfieldId || '');
    });

    ui.subfieldList?.addEventListener('dragover', (event) => {
      const row = event.target.closest('[data-subfield-id]');
      if (!row) return;
      event.preventDefault();
      qsa(ui.subfieldList, '[data-subfield-id]').forEach((node) => node.classList.remove('is-over'));
      row.classList.add('is-over');
    });

    ui.subfieldList?.addEventListener('dragleave', (event) => {
      event.target.closest('[data-subfield-id]')?.classList.remove('is-over');
    });

    ui.subfieldList?.addEventListener('drop', (event) => {
      const row = event.target.closest('[data-subfield-id]');
      const field = selectedField();
      if (!row || !field || !state.draggedSubfieldId) return;
      event.preventDefault();
      qsa(ui.subfieldList, '[data-subfield-id]').forEach((node) => node.classList.remove('is-over'));
      syncSubfieldsFromDom(field);
      const items = [...(field.subfields || [])];
      const from = items.findIndex((item) => item.id === state.draggedSubfieldId);
      const to = items.findIndex((item) => item.id === row.dataset.subfieldId);
      if (from < 0 || to < 0 || from === to) return;
      const [moved] = items.splice(from, 1);
      items.splice(to, 0, moved);
      field.subfields = items;
      state.draggedSubfieldId = null;
      renderSubfieldRows(field.subfields);
      renderCanvas();
    });

    ui.palette?.addEventListener('dragstart', (event) => {
      const card = event.target.closest('[data-field-type]');
      if (!card || !canManage) return;
      state.draggedType = card.dataset.fieldType || null;
      event.dataTransfer.setData('text/plain', state.draggedType || '');
    });

    ui.canvas?.addEventListener('dragstart', (event) => {
      const card = event.target.closest('[data-field-id]');
      if (!card || !canManage) return;
      state.draggedFieldId = card.dataset.fieldId || null;
      event.dataTransfer.setData('text/plain', state.draggedFieldId || '');
    });

    ui.canvas?.addEventListener('contextmenu', (event) => {
      const card = event.target.closest('[data-field-id]');
      if (!card || !canManage) return;
      event.preventDefault();
      const fieldId = card.dataset.fieldId || '';
      const field = (state.current.schema || []).find((item) => item.id === fieldId);
      if (!field) return;
      if (!window.confirm(`Delete "${field.label || field.key}"?`)) return;
      state.current.schema = (state.current.schema || []).filter((item) => item.id !== fieldId);
      state.selectedFieldId = state.current.schema[0]?.id || null;
      renderCanvas();
      renderInspector();
    });

    ui.canvas?.addEventListener('dragover', (event) => {
      event.preventDefault();
      const slot = event.target.closest('[data-drop-index]');
      qsa(ui.canvas, '[data-drop-index]').forEach((node) => node.classList.remove('is-over'));
      if (slot) slot.classList.add('is-over');
    });

    ui.canvas?.addEventListener('dragleave', () => {
      qsa(ui.canvas, '[data-drop-index]').forEach((node) => node.classList.remove('is-over'));
    });

    ui.canvas?.addEventListener('drop', (event) => {
      event.preventDefault();
      const slot = event.target.closest('[data-drop-index]');
      const dropIndex = Number(slot?.dataset.dropIndex || state.current.schema.length);
      qsa(ui.canvas, '[data-drop-index]').forEach((node) => node.classList.remove('is-over'));

      if (state.draggedType && canManage) {
        const field = blankField(state.draggedType, state.current.schema.length + 1);
        state.current.schema.splice(dropIndex, 0, field);
        state.selectedFieldId = field.id;
        state.draggedType = null;
        render();
        openModal(ui.fieldModal);
        return;
      }

      if (state.draggedFieldId) {
        const fields = [...state.current.schema];
        const from = fields.findIndex((field) => field.id === state.draggedFieldId);
        if (from < 0) return;
        const [field] = fields.splice(from, 1);
        const to = Math.min(dropIndex, fields.length);
        fields.splice(to, 0, field);
        state.current.schema = fields;
        state.selectedFieldId = field.id;
        state.draggedFieldId = null;
        renderCanvas();
        renderInspector();
      }
    });

    ui.trashZone?.addEventListener('dragover', (event) => {
      event.preventDefault();
      ui.trashZone.classList.add('is-over');
    });
    ui.trashZone?.addEventListener('dragleave', () => ui.trashZone.classList.remove('is-over'));
    ui.trashZone?.addEventListener('drop', (event) => {
      event.preventDefault();
      ui.trashZone.classList.remove('is-over');
      if (!state.draggedFieldId) return;
      state.current.schema = (state.current.schema || []).filter((field) => field.id !== state.draggedFieldId);
      state.selectedFieldId = state.current.schema[0]?.id || null;
      state.draggedFieldId = null;
      renderCanvas();
      renderInspector();
    });

    ui.fieldModal?.addEventListener('click', (event) => {
      if (event.target === ui.fieldModal || event.target.closest('[data-close-modal="metis-forms-field-modal"]')) {
        flushSelectedFieldState();
        closeModal(ui.fieldModal);
      }
    });

    function render() {
      renderPalette();
      renderMeta();
      renderCanvas();
      renderInspector();
    }

    function renderPalette() {
      ui.palette.innerHTML = state.fieldLibrary.map((item) => `
        <button type="button" class="mw-list-sidebar-nav-item metis-forms-library-item" draggable="${canManage}" data-field-type="${escapeHtml(item.type)}">
          <span>${escapeHtml(item.label)}</span>
          <small>${escapeHtml(fieldHint(item.type))}</small>
        </button>
      `).join('');
    }

    function renderMeta() {
      const form = state.current || freshForm();
      if (ui.name) ui.name.value = form.name || '';
      if (ui.slug) ui.slug.value = form.slug || '';
      if (ui.status) ui.status.value = form.status || 'draft';
      if (ui.publicLink) {
        ui.publicLink.href = form.public_url || '#';
        ui.publicLink.textContent = form.public_url || 'Open public form';
      }
      if (ui.versions) {
        ui.versions.innerHTML = (form.versions || []).map((version) => `
          <div class="metis-forms-version-badge">
            <strong>v${version.version_number}</strong>
            <small>${escapeHtml(version.created_at || '')}${version.is_published ? ' • published' : ''}</small>
          </div>
        `).join('') || '<div class="mw-muted">No versions yet.</div>';
      }
    }

    function renderCanvas() {
      const fields = state.current?.schema || [];
      const rows = [];
      for (let index = 0; index <= fields.length; index += 1) {
        rows.push(`<div class="metis-forms-drop-slot" data-drop-index="${index}"><span>Drop field here</span></div>`);
        if (fields[index]) {
          const field = fields[index];
          rows.push(`
            <article class="metis-forms-canvas-card ${field.id === state.selectedFieldId ? 'is-active' : ''}" draggable="${canManage}" data-field-id="${field.id}">
              <div class="metis-forms-canvas-card-head">
                <div>
                  <strong>${escapeHtml(field.label || field.key)}</strong>
                  <small>${escapeHtml(field.key)}</small>
                </div>
                <div class="metis-forms-canvas-card-tools">
                  <span class="mw-chip">${escapeHtml(field.type)}</span>
                  <button type="button" class="metis-forms-field-cog" data-open-field-settings="${escapeHtml(field.id)}" aria-label="Edit field settings">⚙</button>
                </div>
              </div>
              <div class="metis-forms-canvas-card-meta">
                <span>${field.required ? 'Required' : 'Optional'}</span>
                <span>${field.width === 'half' ? 'Half width' : 'Full width'}</span>
                <span>${field.pricing?.enabled ? 'Priced' : 'No pricing'}</span>
                <span>${field.conditions?.length ? 'Conditional' : 'Always visible'}</span>
              </div>
            </article>
          `);
        }
      }
      ui.canvas.innerHTML = rows.join('') || '<div class="mw-muted">Drag a field into the canvas to begin.</div>';
    }

    function renderInspector() {
      const field = selectedField();
      if (!field) {
        if (ui.inspectorEmpty) ui.inspectorEmpty.style.display = '';
        if (ui.inspectorPanel) ui.inspectorPanel.style.display = 'none';
        return;
      }

      if (ui.inspectorEmpty) ui.inspectorEmpty.style.display = 'none';
      if (ui.inspectorPanel) ui.inspectorPanel.style.display = '';
      if (ui.fieldTypeNote) ui.fieldTypeNote.textContent = inspectorNote(field.type);
      if (ui.fieldLabel) ui.fieldLabel.value = field.label || '';
      if (ui.fieldKey) ui.fieldKey.value = field.key || '';
      if (ui.fieldHelp) ui.fieldHelp.value = field.help || '';
      if (ui.fieldPlaceholder) ui.fieldPlaceholder.value = field.placeholder || '';
      if (ui.fieldRequired) ui.fieldRequired.checked = !!field.required;
      if (ui.fieldHalf) ui.fieldHalf.checked = field.width === 'half';
      if (ui.fieldFormat) ui.fieldFormat.value = field.format || '';
      if (ui.fieldMinLength) ui.fieldMinLength.value = field.validation?.min_length || '';
      if (ui.fieldMaxLength) ui.fieldMaxLength.value = field.validation?.max_length || '';
      if (ui.fieldMin) ui.fieldMin.value = field.min || '';
      if (ui.fieldMax) ui.fieldMax.value = field.max || '';
      if (ui.fieldOptions) ui.fieldOptions.value = formatOptions(field.options || []);
      if (ui.fieldSource) ui.fieldSource.value = field.options_source?.type || '';
      if (ui.fieldSourceItems) ui.fieldSourceItems.value = formatOptions(field.options_source?.items || []);
      if (ui.fieldSearchable) ui.fieldSearchable.checked = !!field.searchable;
      populateFieldSelect(
        ui.fieldDependsOn,
        (state.current.schema || [])
          .filter((item) => item.id !== field.id && ['select', 'radio', 'checkbox'].includes(item.type))
          .map((item) => ({ value: item.key, label: item.label || item.key })),
        field.depends_on || field.options_source?.parent_field || '',
        'No parent field'
      );
      renderConditionRows(field.conditions || []);
      renderPricingControls(field);
      renderSubfieldRows(field.subfields || []);
      updateInspectorScope(field);
    }

    function selectedField() {
      return (state.current?.schema || []).find((field) => field.id === state.selectedFieldId) || null;
    }

    function syncMeta() {
      state.current.name = ui.name?.value.trim() || 'Untitled form';
      state.current.slug = slugify(ui.slug?.value || ui.name?.value || 'untitled-form');
      state.current.status = ui.status?.value || 'draft';
      state.current.public_url = state.current.slug ? `${location.origin}/public/forms/${state.current.slug}` : '#';
      if (ui.publicLink) {
        ui.publicLink.href = state.current.public_url;
        ui.publicLink.textContent = state.current.public_url;
      }
    }

    function syncSelectedFieldBasics() {
      const field = selectedField();
      if (!field) return null;
      field.label = ui.fieldLabel.value.trim();
      field.key = slugify(ui.fieldKey.value || field.label);
      field.help = ui.fieldHelp.value;
      field.placeholder = ui.fieldPlaceholder.value;
      field.required = ui.fieldRequired.checked;
      field.width = ui.fieldHalf.checked ? 'half' : 'full';
      field.format = ui.fieldFormat.value;
      field.validation = {
        min_length: Number(ui.fieldMinLength.value || 0),
        max_length: Number(ui.fieldMaxLength.value || 0),
        pattern: ''
      };
      field.min = ui.fieldMin.value.trim();
      field.max = ui.fieldMax.value.trim();
      field.searchable = !!ui.fieldSearchable?.checked;
      field.depends_on = ui.fieldDependsOn?.value || '';
      field.options_source = field.options_source || { type: '', items: [], parent_field: '' };
      field.options_source.parent_field = field.depends_on;
      return field;
    }

    function flushSelectedFieldState() {
      const field = syncSelectedFieldBasics();
      if (!field) return null;
      field.options = parseOptions(ui.fieldOptions?.value || '');
      field.options_source = {
        type: ui.fieldSource?.value || '',
        items: parseOptions(ui.fieldSourceItems?.value || ''),
        parent_field: ui.fieldDependsOn?.value || ''
      };
      field.searchable = !!ui.fieldSearchable?.checked;
      field.depends_on = ui.fieldDependsOn?.value || '';
      field.options_source.parent_field = field.depends_on;
      syncConditionsFromDom(field);
      syncPricingFromDom(field);
      syncSubfieldsFromDom(field);
      return field;
    }

    function syncConditionsFromDom(field) {
      field.conditions = qsa(ui.conditionList, '[data-condition-index]').map((row) => ({
        source: qs(row, '[data-condition-field="source"]')?.value || '',
        operator: qs(row, '[data-condition-field="operator"]')?.value || 'equals',
        value: qs(row, '[data-condition-field="value"]')?.value || ''
      })).filter((rule) => rule.source);
    }

    function syncPricingFromDom(field) {
      if (!fieldSupportsPricing(field.type) || !ui.pricingEnabled?.checked) {
        field.pricing = { enabled: false, type: 'fixed', amount: 0, choice_amounts: {} };
        return;
      }
      field.pricing = {
        enabled: true,
        type: ui.pricingType?.value || 'fixed',
        amount: Number(ui.pricingAmount?.value || 0),
        choice_amounts: qsa(ui.pricingChoiceList, '[data-choice-amount]').reduce((carry, input) => {
          const key = input.dataset.choiceAmount || '';
          carry[key] = Number(input.value || 0);
          return carry;
        }, {})
      };
    }

    function syncSubfieldsFromDom(field) {
      if (field.type !== 'repeater') {
        field.subfields = [];
        return;
      }
      field.repeat_limit = Math.max(1, Number(ui.repeatLimit?.value || 10));
      field.subfields = qsa(ui.subfieldList, '[data-subfield-id]').map((row, index) => {
        const type = qs(row, '[data-subfield-field="type"]')?.value || 'text';
        return {
          id: row.dataset.subfieldId || blankSubfield(index + 1).id,
          type,
          key: slugify(qs(row, '[data-subfield-field="key"]')?.value || qs(row, '[data-subfield-field="label"]')?.value || `subfield_${index + 1}`),
          label: qs(row, '[data-subfield-field="label"]')?.value || '',
          required: !!qs(row, '[data-subfield-field="required"]')?.checked,
          width: qs(row, '[data-subfield-field="width"]')?.value || 'half',
          help: '',
          placeholder: '',
          options: ['select', 'checkbox', 'radio'].includes(type) ? [{ label: 'Option 1', value: 'option_1' }] : [],
          options_source: { type: '', items: [] },
          conditions: [],
          pricing: { enabled: false, type: 'fixed', amount: 0, choice_amounts: {} }
        };
      });
    }

    function renderConditionRows(conditions) {
      if (!ui.conditionList) return;
      const candidates = (state.current.schema || [])
        .filter((item) => item.id !== state.selectedFieldId)
        .map((item) => ({ value: item.key, label: item.label || item.key }));
      ui.conditionList.innerHTML = (conditions || []).map((condition, index) => `
        <div class="metis-forms-rule-row" data-condition-index="${index}">
          <select class="mw-select" data-condition-field="source">${fieldOptionsMarkup(candidates, condition.source || '', 'Choose field')}</select>
          <select class="mw-select" data-condition-field="operator">
            <option value="equals" ${condition.operator === 'equals' ? 'selected' : ''}>is</option>
            <option value="not_equals" ${condition.operator === 'not_equals' ? 'selected' : ''}>is not</option>
            <option value="contains" ${condition.operator === 'contains' ? 'selected' : ''}>contains</option>
            <option value="gt" ${condition.operator === 'gt' ? 'selected' : ''}>is greater than</option>
            <option value="lt" ${condition.operator === 'lt' ? 'selected' : ''}>is less than</option>
          </select>
          <input class="mw-input" data-condition-field="value" type="text" value="${escapeHtml(condition.value || '')}" placeholder="Expected value">
          <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-remove-condition>Remove</button>
        </div>
      `).join('') || '<div class="mw-muted">This field always shows right now.</div>';
    }

    function renderPricingControls(field) {
      if (!ui.pricingEnabled) return;
      const pricing = field.pricing || {};
      ui.pricingEnabled.checked = !!pricing.enabled;
      ui.pricingType.value = pricing.type || 'fixed';
      ui.pricingAmount.value = pricing.amount || '';
      const options = resolvedOptionRows(field);
      ui.pricingChoiceList.innerHTML = options.length
        ? options.map((option) => `
          <div class="metis-forms-rule-row">
            <strong>${escapeHtml(option.label)}</strong>
            <input class="mw-input" type="number" min="0" step="0.01" data-choice-amount="${escapeHtml(option.value)}" value="${escapeHtml(String(pricing.choice_amounts?.[option.value] ?? ''))}" placeholder="0.00">
          </div>
        `).join('')
        : '<div class="mw-muted">Add choices above before setting option-specific amounts.</div>';
      updatePricingScope();
    }

    function renderSubfieldRows(subfields) {
      if (!ui.subfieldList || !ui.repeatLimit) return;
      const field = selectedField();
      ui.repeatLimit.value = field?.repeat_limit || 10;
      ui.subfieldList.innerHTML = (subfields || []).map((subfield, index) => `
        <div class="metis-forms-rule-row metis-forms-subfield-row" draggable="${canManage}" data-subfield-id="${escapeHtml(subfield.id || `sub_${index}`)}">
          <div class="metis-forms-sidebar-label">Subfield ${index + 1}</div>
          <input class="mw-input" data-subfield-field="label" type="text" value="${escapeHtml(subfield.label || '')}" placeholder="Label">
          <input class="mw-input" data-subfield-field="key" type="text" value="${escapeHtml(subfield.key || '')}" placeholder="Key">
          <select class="mw-select" data-subfield-field="type">
            ${subfieldTypeOptions(subfield.type || 'text')}
          </select>
          <select class="mw-select" data-subfield-field="width">
            <option value="half" ${subfield.width !== 'full' ? 'selected' : ''}>Half width</option>
            <option value="full" ${subfield.width === 'full' ? 'selected' : ''}>Full width</option>
          </select>
          <label><input type="checkbox" data-subfield-field="required" ${subfield.required ? 'checked' : ''}> Required</label>
          <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-remove-subfield>Remove</button>
        </div>
      `).join('') || '<div class="mw-muted">No subfields yet. Add the repeated fields for each row here.</div>';
    }

    function updatePricingScope() {
      const type = ui.pricingType?.value || 'fixed';
      ui.pricingScoped.forEach((node) => {
        const allowed = String(node.dataset.pricingScope || '').split(/\s+/).filter(Boolean);
        node.style.display = allowed.includes(type) ? '' : 'none';
      });
    }

    function showAlert(message, type = 'success') {
      if (!ui.alert) return;
      ui.alert.className = `mw-alert mw-alert-${type}`;
      ui.alert.textContent = message;
      ui.alert.style.display = '';
    }

    function freshForm() {
      return {
        id: 0,
        name: 'Untitled form',
        slug: '',
        description: '',
        status: 'draft',
        schema: [],
        versions: [],
        settings: {
          confirmation: { message: 'Thanks, your submission has been received.' },
          notifications: {
            submitter: { enabled: true, recipient_field: '', subject: 'We received your submission', message: 'Thank you for your submission.' },
            receiver: { enabled: true, emails: [], subject: 'New form submission received', message: 'A new submission has been received.', rules: [] },
            webhook_url: ''
          },
          payments: {
            enabled: false,
            currency: 'usd',
            discounts: [],
            allow_discount_code: false,
            total_source: 'calculated',
            total_field_key: '',
            processing_fees: { enabled: false, mode: 'pass_through', percent: 0, fixed: 0, apply_to: 'net' }
          },
          design: {
            accent_color: '#126497',
            button_bg: '#126497',
            button_text: '#ffffff',
            field_radius: '14',
            surface_style: 'clean'
          }
        }
      };
    }

    function blankField(type, index) {
      return {
        id: `fld_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`,
        type,
        key: slugify(`${type}_${index}`),
        label: prettifyType(type),
        help: '',
        placeholder: '',
        required: false,
        width: 'full',
        format: '',
        validation: { min_length: 0, max_length: 0, pattern: '' },
        min: '',
        max: '',
        options: ['select', 'checkbox', 'radio'].includes(type) ? [{ label: 'Option 1', value: 'option_1' }] : [],
        options_source: { type: '', items: [], parent_field: '' },
        searchable: type === 'select',
        depends_on: '',
        conditions: [],
        pricing: { enabled: false, type: 'fixed', amount: 0, choice_amounts: {} },
        repeat_limit: type === 'repeater' ? 10 : 0,
        subfields: type === 'repeater' ? [blankSubfield(1)] : []
      };
    }

    function updateInspectorScope(field) {
      const type = field?.type || '';
      ui.inspectorScoped.forEach((node) => {
        const scope = node.dataset.inspectorFor || '';
        let visible = true;
        if (scope === 'choice') visible = ['select', 'checkbox', 'radio'].includes(type);
        if (scope === 'select-enhancements') visible = type === 'select';
        if (scope === 'placeholder') visible = ['text', 'email', 'number', 'textarea', 'date'].includes(type);
        if (scope === 'formatting') visible = ['text', 'email', 'number'].includes(type);
        if (scope === 'length') visible = ['text', 'textarea', 'email'].includes(type);
        if (scope === 'numberish') visible = ['number', 'date'].includes(type);
        if (scope === 'pricing') visible = fieldSupportsPricing(type);
        if (scope === 'repeaters') visible = type === 'repeater';
        if (scope === 'conditions') visible = !['repeater', 'payment'].includes(type);
        node.style.display = visible ? '' : 'none';
      });
      if (ui.fieldHalf) ui.fieldHalf.closest('label').style.display = type === 'payment' ? 'none' : '';
      if (ui.fieldRequired) ui.fieldRequired.closest('label').style.display = type === 'payment' ? 'none' : '';
      updatePricingScope();
    }
  }

  function initEntries(root) {
    const ajax = window.metisFormsAjax || {};
    const formId = Number(root.dataset.formId || 0);
    qs(root, '#metis-forms-export')?.addEventListener('click', async () => {
      const params = new URLSearchParams({ action: 'metis_forms_export', nonce: ajax.nonce || '', form_id: String(formId) });
      const response = await fetch(ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: params });
      const payload = await response.json();
      if (!payload?.success) return;
      const blob = new Blob([payload.data?.csv || ''], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `form-${formId}-entries.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    });
  }

  function initSettings(root, boot) {
    const ajax = window.metisFormsAjax || {};
    const canManage = root.dataset.canManage === '1';
    const state = {
      current: boot.selected || null,
      activeSection: 'confirmation',
      activeRichEditor: null,
      parkedSections: new Map()
    };
    if (!state.current) return;

    const ui = {
      alert: qs(root, '#metis-forms-alert'),
      navButtons: qsa(root, '[data-settings-nav]'),
      cards: qsa(root, '[data-settings-card]'),
      openSectionButtons: qsa(root, '[data-open-settings-section]'),
      modal: qs(root, '#metis-forms-settings-modal'),
      modalTitle: qs(root, '#metis-forms-settings-modal-title'),
      modalBody: qs(root, '#metis-forms-settings-modal-body'),
      editors: qsa(root, '.metis-forms-settings-editor'),
      description: qs(root, '#metis-forms-description'),
      confirmation: qs(root, '#metis-forms-confirmation'),
      confirmationEditor: qs(root, '#metis-forms-confirmation-editor'),
      webhook: qs(root, '#metis-forms-webhook'),
      submitterEnabled: qs(root, '#metis-forms-submitter-enabled'),
      submitterRecipient: qs(root, '#metis-forms-submitter-recipient'),
      submitterSubject: qs(root, '#metis-forms-submitter-subject'),
      submitterMessage: qs(root, '#metis-forms-submitter-message'),
      submitterMessageEditor: qs(root, '#metis-forms-submitter-message-editor'),
      receiverEnabled: qs(root, '#metis-forms-receiver-enabled'),
      receiverEmails: qs(root, '#metis-forms-receiver-emails'),
      receiverSubject: qs(root, '#metis-forms-receiver-subject'),
      receiverMessage: qs(root, '#metis-forms-receiver-message'),
      receiverMessageEditor: qs(root, '#metis-forms-receiver-message-editor'),
      ruleList: qs(root, '#metis-forms-rule-list'),
      addRule: qs(root, '#metis-forms-add-rule'),
      paymentsEnabled: qs(root, '#metis-forms-payments-enabled'),
      paymentEmpty: qs(root, '#metis-forms-payment-empty'),
      paymentSettings: qs(root, '#metis-forms-payment-settings'),
      allowDiscount: qs(root, '#metis-forms-allow-discount'),
      currency: qs(root, '#metis-forms-currency'),
      totalSource: qs(root, '#metis-forms-total-source'),
      totalFieldKey: qs(root, '#metis-forms-total-field-key'),
      discounts: qs(root, '#metis-forms-discounts'),
      feeEnabled: qs(root, '#metis-forms-fee-enabled'),
      feeMode: qs(root, '#metis-forms-fee-mode'),
      feePercent: qs(root, '#metis-forms-fee-percent'),
      feeFixed: qs(root, '#metis-forms-fee-fixed'),
      feeApplyTo: qs(root, '#metis-forms-fee-apply-to'),
      designAccent: qs(root, '#metis-forms-design-accent'),
      designButtonBg: qs(root, '#metis-forms-design-button-bg'),
      designButtonText: qs(root, '#metis-forms-design-button-text'),
      designFieldRadius: qs(root, '#metis-forms-design-field-radius'),
      designSurfaceStyle: qs(root, '#metis-forms-design-surface-style'),
      stylePreview: qs(root, '#metis-forms-style-preview'),
      mergeTagGroups: qsa(root, '[data-merge-tags]'),
      richToolbars: qsa(root, '[data-rich-toolbar]'),
      save: qs(root, '#metis-forms-save-settings')
    };

    const request = async (action, body) => {
      const params = new URLSearchParams({ action, nonce: ajax.nonce || '' });
      Object.entries(body || {}).forEach(([key, value]) => params.append(key, value));
      const response = await fetch(ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: params });
      const payload = await response.json();
      if (!payload?.success) throw new Error(payload?.data?.message || 'Request failed');
      return payload.data || {};
    };

    render();

    ui.navButtons.forEach((button) => {
      button.addEventListener('click', () => openSettingsSection(button.dataset.settingsNav || 'confirmation'));
    });

    ui.openSectionButtons.forEach((button) => {
      button.addEventListener('click', () => openSettingsSection(button.dataset.openSettingsSection || 'confirmation'));
    });

    ui.addRule?.addEventListener('click', () => {
      const rules = currentRules();
      rules.push({ source: '', operator: 'equals', value: '', emails: [], subject: '', message: '' });
      renderRules(rules);
    });

    ui.ruleList?.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-remove-rule]');
      if (!remove) return;
      const row = remove.closest('[data-rule-index]');
      if (!row) return;
      row.remove();
      renumberRules();
    });

    ui.richToolbars.forEach((toolbar) => {
      toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('[data-rich-command]');
        if (!button || !state.activeRichEditor) return;
        event.preventDefault();
        state.activeRichEditor.focus();
        document.execCommand(button.dataset.richCommand || '', false, null);
        syncRichEditors();
      });
    });

    [ui.confirmationEditor, ui.submitterMessageEditor, ui.receiverMessageEditor].forEach((editor) => {
      editor?.addEventListener('focus', () => { state.activeRichEditor = editor; });
      editor?.addEventListener('input', syncRichEditors);
      editor?.addEventListener('blur', syncRichEditors);
    });

    ui.mergeTagGroups.forEach((group) => {
      group.addEventListener('click', (event) => {
        const button = event.target.closest('[data-merge-tag]');
        if (!button) return;
        insertMergeTag(button.dataset.mergeTag || '');
      });
    });

    ui.save?.addEventListener('click', async () => {
      if (!canManage) return;
      try {
        syncRichEditors();
        syncSettings();
        const data = await request('metis_forms_save', { payload: JSON.stringify(state.current) });
        state.current = data.form || state.current;
        render();
        showAlert('Settings saved.');
      } catch (error) {
        showAlert(error.message, 'error');
      }
    });

    [ui.designAccent, ui.designButtonBg, ui.designButtonText, ui.designFieldRadius, ui.designSurfaceStyle].forEach((node) => {
      node?.addEventListener('input', renderStylePreview);
      node?.addEventListener('change', renderStylePreview);
    });

    ui.totalSource?.addEventListener('change', updatePaymentVisibility);

    function render() {
      const settings = state.current.settings || {};
      const notifications = settings.notifications || {};
      const submitter = notifications.submitter || {};
      const receiver = notifications.receiver || {};
      const payments = settings.payments || {};
      const design = settings.design || {};
      const paymentFieldPresent = hasPaymentField(state.current.schema || []);

      ui.description.value = state.current.description || '';
      ui.confirmation.value = settings.confirmation?.message || '';
      setRichEditor(ui.confirmationEditor, ui.confirmation.value);
      ui.webhook.value = notifications.webhook_url || '';
      ui.submitterEnabled.checked = !!submitter.enabled;
      populateFieldSelect(ui.submitterRecipient, emailFieldOptions(state.current.schema || []), submitter.recipient_field || '');
      ui.submitterSubject.value = submitter.subject || '';
      ui.submitterMessage.value = submitter.message || '';
      setRichEditor(ui.submitterMessageEditor, ui.submitterMessage.value);
      ui.receiverEnabled.checked = !!receiver.enabled;
      ui.receiverEmails.value = Array.isArray(receiver.emails) ? receiver.emails.join(', ') : '';
      ui.receiverSubject.value = receiver.subject || '';
      ui.receiverMessage.value = receiver.message || '';
      setRichEditor(ui.receiverMessageEditor, ui.receiverMessage.value);
      renderRules(receiver.rules || []);
      ui.paymentsEnabled.checked = paymentFieldPresent;
      ui.allowDiscount.checked = !!payments.allow_discount_code;
      ui.currency.value = payments.currency || 'usd';
      ui.totalSource.value = payments.total_source || 'calculated';
      populateFieldSelect(ui.totalFieldKey, numberFieldOptions(state.current.schema || []), payments.total_field_key || '', 'Choose a number field');
      ui.discounts.value = formatDiscounts(payments.discounts || []);
      ui.feeEnabled.checked = !!payments.processing_fees?.enabled;
      ui.feeMode.value = payments.processing_fees?.mode || 'pass_through';
      ui.feePercent.value = payments.processing_fees?.percent || '';
      ui.feeFixed.value = payments.processing_fees?.fixed || '';
      ui.feeApplyTo.value = payments.processing_fees?.apply_to || 'net';
      ui.designAccent.value = design.accent_color || '#126497';
      ui.designButtonBg.value = design.button_bg || '#126497';
      ui.designButtonText.value = design.button_text || '#ffffff';
      ui.designFieldRadius.value = String(design.field_radius || '14');
      ui.designSurfaceStyle.value = design.surface_style || 'clean';
      renderMergeTagButtons();
      updateSettingsCards(paymentFieldPresent);
      updatePaymentVisibility();
      renderStylePreview();
    }

    function syncSettings() {
      state.current.description = ui.description.value;
      state.current.settings = state.current.settings || {};
      state.current.settings.confirmation = { message: ui.confirmation.value || 'Thanks, your submission has been received.' };
      state.current.settings.notifications = {
        submitter: {
          enabled: !!ui.submitterEnabled.checked,
          recipient_field: ui.submitterRecipient.value || '',
          subject: ui.submitterSubject.value || 'We received your submission',
          message: ui.submitterMessage.value || 'Thank you for your submission.'
        },
        receiver: {
          enabled: !!ui.receiverEnabled.checked,
          emails: String(ui.receiverEmails.value || '').split(/[,\s;]+/).filter(Boolean),
          subject: ui.receiverSubject.value || 'New form submission received',
          message: ui.receiverMessage.value || 'A new submission has been received.',
          rules: currentRules()
        },
        webhook_url: ui.webhook.value.trim()
      };
      state.current.settings.payments = {
        enabled: hasPaymentField(state.current.schema || []),
        currency: (ui.currency.value || 'usd').trim().toLowerCase(),
        discounts: parseDiscounts(ui.discounts.value || ''),
        allow_discount_code: !!ui.allowDiscount.checked,
        total_source: ui.totalSource.value || 'calculated',
        total_field_key: ui.totalFieldKey.value || '',
        processing_fees: {
          enabled: !!ui.feeEnabled.checked,
          mode: ui.feeMode.value || 'pass_through',
          percent: Number(ui.feePercent.value || 0),
          fixed: Number(ui.feeFixed.value || 0),
          apply_to: ui.feeApplyTo.value || 'net'
        }
      };
      state.current.settings.design = {
        accent_color: ui.designAccent.value || '#126497',
        button_bg: ui.designButtonBg.value || '#126497',
        button_text: ui.designButtonText.value || '#ffffff',
        field_radius: ui.designFieldRadius.value || '14',
        surface_style: ui.designSurfaceStyle.value || 'clean'
      };
    }

    function renderRules(rules) {
      ui.ruleList.innerHTML = (rules || []).map((rule, index) => `
        <div class="metis-forms-rule-row" data-rule-index="${index}">
          <select class="mw-select" data-rule-field="source">${fieldOptionsMarkup((state.current.schema || []).map((field) => ({ value: field.key, label: field.label || field.key })), rule.source || '', 'Choose field')}</select>
          <select class="mw-select" data-rule-field="operator">
            <option value="equals" ${rule.operator === 'equals' ? 'selected' : ''}>is</option>
            <option value="not_equals" ${rule.operator === 'not_equals' ? 'selected' : ''}>is not</option>
            <option value="contains" ${rule.operator === 'contains' ? 'selected' : ''}>contains</option>
          </select>
          <input class="mw-input" data-rule-field="value" type="text" value="${escapeHtml(rule.value || '')}" placeholder="When answer is...">
          <input class="mw-input" data-rule-field="emails" type="text" value="${escapeHtml(Array.isArray(rule.emails) ? rule.emails.join(', ') : '')}" placeholder="Send to these emails">
          <input class="mw-input" data-rule-field="subject" type="text" value="${escapeHtml(rule.subject || '')}" placeholder="Optional subject override">
          <textarea class="mw-input" data-rule-field="message" placeholder="Optional message override">${escapeHtml(rule.message || '')}</textarea>
          <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-remove-rule>Remove</button>
        </div>
      `).join('') || '<div class="mw-muted">No routing rules yet.</div>';
    }

    function currentRules() {
      return qsa(ui.ruleList, '[data-rule-index]').map((row) => ({
        source: qs(row, '[data-rule-field="source"]')?.value || '',
        operator: qs(row, '[data-rule-field="operator"]')?.value || 'equals',
        value: qs(row, '[data-rule-field="value"]')?.value || '',
        emails: String(qs(row, '[data-rule-field="emails"]')?.value || '').split(/[,\s;]+/).filter(Boolean),
        subject: qs(row, '[data-rule-field="subject"]')?.value || '',
        message: qs(row, '[data-rule-field="message"]')?.value || ''
      })).filter((rule) => rule.source);
    }

    function renumberRules() {
      qsa(ui.ruleList, '[data-rule-index]').forEach((row, index) => { row.dataset.ruleIndex = String(index); });
    }

    function updatePaymentVisibility() {
      const paymentFieldPresent = hasPaymentField(state.current.schema || []);
      if (ui.paymentEmpty) ui.paymentEmpty.style.display = paymentFieldPresent ? 'none' : '';
      if (ui.paymentSettings) ui.paymentSettings.style.display = paymentFieldPresent ? '' : 'none';
      const useFieldValue = ui.totalSource?.value === 'field_value';
      const totalFieldSection = ui.totalFieldKey?.closest('.metis-forms-inspector-section');
      if (totalFieldSection) totalFieldSection.style.display = paymentFieldPresent && useFieldValue ? '' : 'none';
    }

    function renderStylePreview() {
      const card = qs(ui.stylePreview, '.metis-forms-style-preview-card');
      if (!card) return;
      const radius = ui.designFieldRadius?.value || '14';
      const style = ui.designSurfaceStyle?.value || 'clean';
      card.style.setProperty('--metis-form-accent', ui.designAccent?.value || '#126497');
      card.style.setProperty('--metis-form-button-bg', ui.designButtonBg?.value || '#126497');
      card.style.setProperty('--metis-form-button-text', ui.designButtonText?.value || '#ffffff');
      card.style.setProperty('--metis-form-radius', `${radius}px`);
      card.dataset.surfaceStyle = style;
      card.classList.toggle('is-soft', style === 'soft');
      card.classList.toggle('is-outline', style === 'outline');
    }

    function renderMergeTagButtons() {
      const tags = mergeTagOptions(state.current.schema || []);
      ui.mergeTagGroups.forEach((group) => {
        group.innerHTML = tags.map((tag) => (
          `<button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-merge-tag="${escapeHtml(tag.value)}">${escapeHtml(tag.label)}</button>`
        )).join('');
      });
    }

    function syncRichEditors() {
      if (ui.confirmation && ui.confirmationEditor) ui.confirmation.value = ui.confirmationEditor.innerHTML.trim();
      if (ui.submitterMessage && ui.submitterMessageEditor) ui.submitterMessage.value = ui.submitterMessageEditor.innerHTML.trim();
      if (ui.receiverMessage && ui.receiverMessageEditor) ui.receiverMessage.value = ui.receiverMessageEditor.innerHTML.trim();
    }

    function setRichEditor(editor, value) {
      if (editor) editor.innerHTML = value || '';
    }

    function insertMergeTag(tag) {
      if (!tag || !state.activeRichEditor) return;
      state.activeRichEditor.focus();
      document.execCommand('insertText', false, tag);
      syncRichEditors();
    }

    function updateSettingsCards(paymentFieldPresent) {
      const paymentCard = qs(root, '[data-settings-card="payments"]');
      if (paymentCard) paymentCard.style.display = paymentFieldPresent ? '' : '';
      ui.cards.forEach((card) => {
        const section = card.dataset.settingsCard || '';
        card.classList.toggle('is-active', section === state.activeSection);
      });
      ui.navButtons.forEach((button) => {
        button.classList.toggle('is-active', (button.dataset.settingsNav || '') === state.activeSection);
      });
      const paymentButton = ui.navButtons.find((button) => (button.dataset.settingsNav || '') === 'payments');
      if (paymentButton) paymentButton.textContent = paymentFieldPresent ? 'Payments' : 'Payments (add field)';
    }

    function openSettingsSection(sectionName) {
      state.activeSection = sectionName;
      updateSettingsCards(hasPaymentField(state.current.schema || []));
      const section = ui.editors.find((node) => node.dataset.settingsSection === sectionName);
      if (!section || !ui.modalBody) return;
      closeSettingsSectionModal();
      state.parkedSections.set(sectionName, section.parentNode);
      ui.modalBody.appendChild(section);
      if (ui.modalTitle) ui.modalTitle.textContent = section.querySelector('.metis-contacts-modal-title')?.textContent || 'Settings';
      openModal(ui.modal);
    }

    function closeSettingsSectionModal() {
      ui.editors.forEach((section) => {
        const origin = state.parkedSections.get(section.dataset.settingsSection || '');
        if (origin && origin !== ui.modalBody && section.parentNode === ui.modalBody) {
          origin.appendChild(section);
        }
      });
    }

    ui.modal?.addEventListener('click', (event) => {
      if (event.target === ui.modal || event.target.closest('[data-close-modal="metis-forms-settings-modal"]')) {
        closeModal(ui.modal);
        closeSettingsSectionModal();
      }
    });

    function showAlert(message, type = 'success') {
      const node = ui.alert;
      if (!node) return;
      node.className = `mw-alert mw-alert-${type}`;
      node.textContent = message;
      node.style.display = '';
    }
  }

  function initPublic(root) {
    const boot = parseJson('#metis-forms-public-data', {});
    const form = qs(root, '#metis-forms-public-form');
    const alert = qs(root, '#metis-forms-public-alert');
    const stripeMount = qs(root, '#metis-forms-stripe-mount');
    const stripeButton = qs(root, '#metis-forms-stripe-confirm');
    const schema = boot.form?.schema || [];
    const settings = boot.form?.settings || {};
    let stripeRef = null;
    let stripeElements = null;

    qsa(form, 'input, select, textarea').forEach((input) => {
      input.addEventListener('input', () => {
        applyInputFormatting(input);
        applyConditions(form, schema);
        computeTotals(root, form, schema, settings);
      });
      input.addEventListener('change', () => {
        applyInputFormatting(input);
        applyConditions(form, schema);
        computeTotals(root, form, schema, settings);
      });
    });

    setupRepeaters(form);
    setupSearchableSelects(form);
    setupChainedSelects(form);

    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = new FormData(form);
      const response = await fetch(boot.form?.submit_url || form.action, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body
      });
      const payload = await response.json();
      if (!payload.ok) {
        setPublicAlert(alert, payload.error || 'Submission failed.', 'error');
        return;
      }

      setPublicAlert(alert, payload.message || 'Submission received.', 'success');

      if (payload.payment?.client_secret && payload.payment?.publishable_key && stripeMount && stripeButton) {
        try {
          if (!window.Stripe) {
            await loadScript('https://js.stripe.com/v3/');
          }
          stripeRef = window.Stripe(payload.payment.publishable_key);
          stripeElements = stripeRef.elements({ clientSecret: payload.payment.client_secret });
          stripeMount.innerHTML = '';
          const paymentElement = stripeElements.create('payment');
          paymentElement.mount(stripeMount);
          stripeMount.closest('.metis-forms-stripe-panel')?.classList.add('is-active');
          stripeButton.onclick = async () => {
            const url = new URL(window.location.href);
            url.searchParams.set('submission', payload.submission_key);
            url.searchParams.set('payment_return', '1');
            await stripeRef.confirmPayment({ elements: stripeElements, confirmParams: { return_url: url.toString() } });
          };
        } catch (error) {
          setPublicAlert(alert, error.message || 'Payment setup failed.', 'error');
        }
        return;
      }

      form.reset();
      qsa(form, '.metis-form-repeater-rows').forEach((rows) => { rows.innerHTML = ''; });
      setupRepeaters(form);
      setupSearchableSelects(form);
      setupChainedSelects(form);
      applyConditions(form, schema);
      computeTotals(root, form, schema, settings);
    });

    applyConditions(form, schema);
    computeTotals(root, form, schema, settings);
  }

  function applyConditions(form, schema) {
    schema.forEach((field) => {
      const node = qs(form, `[data-field-key="${cssEscape(field.key)}"]`);
      if (!node) return;
      const visible = (field.conditions || []).every((condition) => {
        const input = qsa(form, `[name="${cssEscape(condition.source)}"], [name="${cssEscape(condition.source)}[]"]`);
        const values = input.filter((el) => el.type !== 'checkbox' || el.checked).map((el) => el.value);
        const actual = values.join(',');
        switch (condition.operator) {
          case 'not_equals': return actual !== condition.value;
          case 'contains': return actual.toLowerCase().includes(String(condition.value || '').toLowerCase());
          case 'gt': return Number(actual || 0) > Number(condition.value || 0);
          case 'lt': return Number(actual || 0) < Number(condition.value || 0);
          default: return actual === String(condition.value || '');
        }
      });
      node.style.display = visible ? '' : 'none';
    });
  }

  function computeTotals(root, form, schema, settings) {
    let subtotal = 0;
    schema.forEach((field) => {
      if (!field.pricing?.enabled) return;
      const card = qs(form, `[data-field-key="${cssEscape(field.key)}"]`);
      if (card && card.style.display === 'none') return;
      const values = qsa(form, `[name="${cssEscape(field.key)}"], [name="${cssEscape(field.key)}[]"]`);
      const selected = values.filter((node) => node.type !== 'checkbox' && node.type !== 'radio' ? true : node.checked).map((node) => node.value);
      const primary = values[0]?.type === 'checkbox' ? selected : (selected[0] || values[0]?.value || '');
      if (field.pricing.type === 'quantity') {
        subtotal += Number(primary || 0) * Number(field.pricing.amount || 0);
      } else if (field.pricing.type === 'choice') {
        (Array.isArray(primary) ? primary : selected).forEach((choice) => {
          subtotal += Number(field.pricing.choice_amounts?.[choice] || 0);
        });
      } else if (primary || selected.length) {
        subtotal += Number(field.pricing.amount || 0);
      }
    });

    if (settings.payments?.total_source === 'field_value' && settings.payments?.total_field_key) {
      const sourceField = settings.payments.total_field_key;
      const sourceInput = qs(form, `[name="${cssEscape(sourceField)}"]`);
      subtotal = Number(sourceInput?.value || 0);
    }

    const code = qs(form, '[name="_discount_code"]')?.value?.trim().toUpperCase() || '';
    let discount = 0;
    (settings.payments?.discounts || []).forEach((item) => {
      if (item.code !== code) return;
      discount = item.type === 'percent' ? subtotal * (Number(item.amount || 0) / 100) : Math.min(subtotal, Number(item.amount || 0));
    });

    let fee = 0;
    if (settings.payments?.processing_fees?.enabled && settings.payments?.processing_fees?.mode === 'pass_through') {
      const base = settings.payments?.processing_fees?.apply_to === 'subtotal' ? subtotal : Math.max(0, subtotal - discount);
      fee = (base * Number(settings.payments?.processing_fees?.percent || 0) / 100) + Number(settings.payments?.processing_fees?.fixed || 0);
    }

    const total = Math.max(0, subtotal - discount + fee);
    const currency = (settings.payments?.currency || 'usd').toUpperCase();
    setText(root, '[data-total-subtotal]', `${currency} ${subtotal.toFixed(2)}`);
    setText(root, '[data-total-discount]', `${currency} ${discount.toFixed(2)}`);
    setText(root, '[data-total-fee]', `${currency} ${fee.toFixed(2)}`);
    setText(root, '[data-total-grand]', `${currency} ${total.toFixed(2)}`);
  }

  function setupRepeaters(form) {
    qsa(form, '[data-repeater-key]').forEach((repeater) => {
      const rows = qs(repeater, '.metis-form-repeater-rows');
      const template = qs(repeater, 'template');
      const limit = Number(repeater.dataset.repeatLimit || 10);
      const addButton = qs(repeater, '[data-add-row]');

      const addRow = () => {
        const index = rows.children.length;
        if (index >= limit) return;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = (template.innerHTML || '').replaceAll('__INDEX__', String(index)).trim();
        const row = wrapper.firstElementChild;
        if (!row) return;
        rows.appendChild(row);
        qsa(row, '[data-remove-row]').forEach((button) => {
          button.addEventListener('click', () => row.remove());
        });
      };

      addButton?.addEventListener('click', addRow);
      if (!rows.children.length) addRow();
    });
  }

  function setupSearchableSelects(form) {
    qsa(form, '.metis-form-select-search').forEach((input) => {
      const select = qs(form, `[name="${cssEscape(input.dataset.selectSearchFor || '')}"]`);
      if (!select) return;
      const applyFilter = () => {
        const query = String(input.value || '').trim().toLowerCase();
        Array.from(select.options).forEach((option, index) => {
          if (index === 0) {
            option.hidden = false;
            return;
          }
          const label = String(option.textContent || '').toLowerCase();
          option.hidden = query !== '' && !label.includes(query);
        });
      };
      input.addEventListener('input', applyFilter);
      applyFilter();
    });
  }

  function setupChainedSelects(form) {
    qsa(form, '.metis-form-field[data-field-type="select"][data-depends-on]').forEach((fieldNode) => {
      const parentKey = fieldNode.dataset.dependsOn || '';
      const select = qs(fieldNode, 'select');
      const parent = qs(form, `[name="${cssEscape(parentKey)}"]`);
      if (!select || !parent) return;
      const sync = () => {
        const selectedParent = String(parent.value || '').trim().toLowerCase();
        let currentHidden = false;
        Array.from(select.options).forEach((option, index) => {
          if (index === 0) {
            option.hidden = false;
            return;
          }
          const category = String(option.dataset.category || '').trim().toLowerCase();
          const visible = selectedParent === '' || category === '' || category === selectedParent;
          option.hidden = !visible;
          if (!visible && option.selected) currentHidden = true;
        });
        if (currentHidden) select.value = '';
      };
      parent.addEventListener('change', sync);
      parent.addEventListener('input', sync);
      sync();
    });
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.onload = resolve;
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  function setPublicAlert(node, message, type) {
    if (!node) return;
    node.className = `mw-alert mw-alert-${type}`;
    node.textContent = message;
    node.style.display = '';
  }

  function parseJson(selector, fallback) {
    const node = document.querySelector(selector);
    if (!node) return fallback;
    try {
      return JSON.parse(node.textContent || 'null') ?? fallback;
    } catch (_) {
      return fallback;
    }
  }

  function parseOptions(raw) {
    return String(raw || '').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
      const [label, value, category] = line.split('|');
      return { label: (label || '').trim(), value: (value || label || '').trim(), category: (category || '').trim() };
    }).filter((item) => item.label && item.value);
  }

  function formatOptions(options) {
    return (options || []).map((item) => `${item.label}|${item.value}${item.category ? `|${item.category}` : ''}`).join('\n');
  }

  function parseConditions(raw) {
    return String(raw || '').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
      const [source, operator, value] = line.split('|');
      return { source: slugify(source || ''), operator: (operator || 'equals').trim(), value: (value || '').trim() };
    }).filter((item) => item.source);
  }

  function formatConditions(conditions) {
    return (conditions || []).map((item) => `${item.source}|${item.operator}|${item.value}`).join('\n');
  }

  function parsePricing(raw) {
    const line = String(raw || '').trim();
    if (!line) return { enabled: false, type: 'fixed', amount: 0, choice_amounts: {} };
    const [type, rest] = line.split('|');
    if ((type || '').trim() === 'choice') {
      const choice_amounts = {};
      String(rest || '').split(',').map((part) => part.trim()).filter(Boolean).forEach((part) => {
        const [key, value] = part.split('=');
        if (key) choice_amounts[key.trim()] = Number(value || 0);
      });
      return { enabled: true, type: 'choice', amount: 0, choice_amounts };
    }
    return { enabled: true, type: (type || 'fixed').trim(), amount: Number(rest || 0), choice_amounts: {} };
  }

  function formatPricing(pricing) {
    if (!pricing?.enabled) return '';
    if (pricing.type === 'choice') {
      const pairs = Object.entries(pricing.choice_amounts || {}).map(([key, value]) => `${key}=${value}`).join(',');
      return `choice|${pairs}`;
    }
    return `${pricing.type || 'fixed'}|${pricing.amount || 0}`;
  }

  function parseDiscounts(raw) {
    return String(raw || '').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
      const [code, type, amount] = line.split('|');
      return { code: (code || '').trim().toUpperCase(), type: (type || 'fixed').trim(), amount: Number(amount || 0) };
    }).filter((item) => item.code);
  }

  function formatDiscounts(discounts) {
    return (discounts || []).map((item) => `${item.code}|${item.type}|${item.amount}`).join('\n');
  }

  function blankSubfield(index) {
    return {
      id: `sub_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`,
      type: 'text',
      key: `subfield_${index}`,
      label: `Subfield ${index}`,
      help: '',
      placeholder: '',
      required: false,
      width: 'half',
      options: [],
      options_source: { type: '', items: [] },
      conditions: [],
      pricing: { enabled: false, type: 'fixed', amount: 0, choice_amounts: {} }
    };
  }

  function fieldSupportsPricing(type) {
    return ['number', 'select', 'checkbox', 'radio'].includes(type);
  }

  function hasPaymentField(schema) {
    return (schema || []).some((field) => field && field.type === 'payment');
  }

  function mergeTagOptions(schema) {
    const tags = [
      { label: 'Submission key', value: '{{submission_key}}' },
      { label: 'Total amount', value: '{{amount_total}}' },
      { label: 'Subtotal', value: '{{subtotal}}' }
    ];
    (schema || []).forEach((field) => {
      if (!field?.key) return;
      tags.push({ label: field.label || field.key, value: `{{${field.key}}}` });
    });
    return tags;
  }

  function resolvedOptionRows(field) {
    if (field.options_source?.type === 'custom') {
      return Array.isArray(field.options_source.items) ? field.options_source.items : [];
    }
    if (field.options_source?.type) {
      return [];
    }
    return Array.isArray(field.options) ? field.options : [];
  }

  function subfieldTypeOptions(selected) {
    return ['text', 'email', 'number', 'textarea', 'date', 'file'].map((type) => (
      `<option value="${escapeHtml(type)}" ${type === selected ? 'selected' : ''}>${escapeHtml(prettifyType(type))}</option>`
    )).join('');
  }

  function inspectorNote(type) {
    return {
      text: 'Short answer field with optional formatting and length limits.',
      email: 'Collects and validates a valid email address.',
      number: 'Best for counts, quantities, and any field used in totals.',
      textarea: 'Long answer field for notes or explanations.',
      select: 'Single-select dropdown with static or dynamic options.',
      checkbox: 'Multi-select choice field with optional per-choice pricing.',
      radio: 'Single choice group with optional per-choice pricing.',
      file: 'Upload field for attachments and supporting documents.',
      date: 'Calendar date field with optional min and max values.',
      repeater: 'Repeat a group of subfields for attendees, items, or family members.',
      payment: 'Stripe payment field. Add this only when the form needs a payment step.'
    }[type] || 'Configure this field using the options below.';
  }

  function emailFieldOptions(schema) {
    return (schema || []).filter((field) => field.type === 'email').map((field) => ({ value: field.key, label: field.label || field.key }));
  }

  function numberFieldOptions(schema) {
    return (schema || []).filter((field) => field.type === 'number').map((field) => ({ value: field.key, label: field.label || field.key }));
  }

  function populateFieldSelect(select, options, selected, placeholder = 'Choose a field') {
    if (!select) return;
    select.innerHTML = fieldOptionsMarkup(options, selected, placeholder);
  }

  function fieldOptionsMarkup(options, selected, placeholder) {
    const rows = [`<option value="">${escapeHtml(placeholder)}</option>`];
    (options || []).forEach((option) => {
      rows.push(`<option value="${escapeHtml(option.value)}" ${option.value === selected ? 'selected' : ''}>${escapeHtml(option.label)}</option>`);
    });
    return rows.join('');
  }

  function parseNotificationRules(raw) {
    return String(raw || '').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
      const [source, operator, value, emails, subject, message] = line.split('|');
      return {
        source: slugify(source || ''),
        operator: (operator || 'equals').trim(),
        value: (value || '').trim(),
        emails: String(emails || '').split(/[,\s;]+/).filter(Boolean),
        subject: (subject || '').trim(),
        message: (message || '').trim()
      };
    }).filter((rule) => rule.source);
  }

  function formatNotificationRules(rules) {
    return (rules || []).map((rule) => [
      rule.source || '',
      rule.operator || 'equals',
      rule.value || '',
      Array.isArray(rule.emails) ? rule.emails.join(',') : '',
      rule.subject || '',
      rule.message || ''
    ].join('|')).join('\n');
  }

  function slugify(value) {
    return String(value || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
  }

  function prettifyType(type) {
    return String(type || 'Field').replace(/_/g, ' ').replace(/\b\w/g, (match) => match.toUpperCase());
  }

  function fieldHint(type) {
    return {
      text: 'Short answer',
      email: 'Validated email',
      number: 'Quantities or counts',
      textarea: 'Long form response',
      select: 'One selection',
      checkbox: 'Multiple selections',
      radio: 'Single choice group',
      file: 'Upload files',
      date: 'Calendar date',
      repeater: 'Repeatable row set',
      payment: 'Stripe payment step'
    }[type] || 'Custom field';
  }

  function openModal(node) {
    if (!node) return;
    node.classList.add('metis-open');
    node.setAttribute('aria-hidden', 'false');
    node.style.display = 'flex';
  }

  function closeModal(node) {
    if (!node) return;
    node.classList.remove('metis-open');
    node.setAttribute('aria-hidden', 'true');
    node.style.display = 'none';
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value));
    return String(value).replace(/"/g, '\\"');
  }

  function qs(root, selector) {
    return root ? root.querySelector(selector) : null;
  }

  function qsa(root, selector) {
    return root ? Array.from(root.querySelectorAll(selector)) : [];
  }

  function setText(root, selector, text) {
    const node = qs(root, selector);
    if (node) node.textContent = text;
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[char]));
  }

  function applyInputFormatting(input) {
    const container = input.closest('[data-field-format]');
    const format = container?.dataset.fieldFormat || '';
    if (!format || input.type === 'checkbox' || input.type === 'radio' || input.type === 'file') return;
    const digits = String(input.value || '').replace(/\D+/g, '');
    if (format === 'phone_us') {
      input.value = digits.length <= 3
        ? digits
        : digits.length <= 6
          ? `${digits.slice(0, 3)}-${digits.slice(3, 6)}`
          : `${digits.slice(0, 3)}-${digits.slice(3, 6)}-${digits.slice(6, 10)}`;
    } else if (format === 'ssn') {
      input.value = digits.length <= 3
        ? digits
        : digits.length <= 5
          ? `${digits.slice(0, 3)}-${digits.slice(3, 5)}`
          : `${digits.slice(0, 3)}-${digits.slice(3, 5)}-${digits.slice(5, 9)}`;
    } else if (format === 'zip') {
      input.value = digits.length <= 5 ? digits : `${digits.slice(0, 5)}-${digits.slice(5, 9)}`;
    } else if (format === 'uppercase') {
      input.value = String(input.value || '').toUpperCase();
    } else if (format === 'integer') {
      input.value = digits;
    }
  }
})();
