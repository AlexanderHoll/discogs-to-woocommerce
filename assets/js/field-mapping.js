/* global d2wMapping */
(function () {
    'use strict';

    var draggedField = null;
    var draggedLabel = null;
    var customRowIndex = Date.now();

    document.addEventListener('DOMContentLoaded', function () {
        initSourceChips();
        initDropZones();
        initClearButtons();
        initTemplateInput();
        initCustomMeta();
        initResetLink();
        updateChipStates();
    });

    // -------------------------------------------------------------------------
    // Source chips: make them draggable
    // -------------------------------------------------------------------------
    function initSourceChips() {
        var list = document.getElementById('d2w-source-list');
        if (!list) return;

        list.addEventListener('dragstart', function (e) {
            var chip = e.target.closest('.d2w-source-chip');
            if (!chip) return;
            draggedField = chip.dataset.discogsField;
            draggedLabel = chip.dataset.label || chip.textContent.trim();
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', draggedField);
            chip.classList.add('is-dragging');
        });

        list.addEventListener('dragend', function (e) {
            var chip = e.target.closest('.d2w-source-chip');
            if (chip) chip.classList.remove('is-dragging');
            draggedField = null;
            draggedLabel = null;
        });
    }

    // -------------------------------------------------------------------------
    // Drop zones
    // -------------------------------------------------------------------------
    function initDropZones() {
        document.addEventListener('dragover', function (e) {
            var zone = e.target.closest('.d2w-drop-area');
            if (!zone) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            zone.classList.add('is-drag-over');
        });

        document.addEventListener('dragleave', function (e) {
            var zone = e.target.closest('.d2w-drop-area');
            if (!zone) return;
            if (!zone.contains(e.relatedTarget)) {
                zone.classList.remove('is-drag-over');
            }
        });

        document.addEventListener('drop', function (e) {
            var zone = e.target.closest('.d2w-drop-area');
            if (!zone) return;
            e.preventDefault();
            zone.classList.remove('is-drag-over');

            var field = e.dataTransfer.getData('text/plain') || draggedField;
            var label = draggedLabel || getLabelForField(field);
            if (!field || !accepts(zone, field)) return;

            assignField(zone, field, label);

            // Dropping onto post_title drop zone clears the template input
            if (zone.dataset.wcField === 'post_title') {
                var tpl = document.querySelector('.d2w-template-input');
                if (tpl) tpl.value = '';
            }
        });
    }

    // -------------------------------------------------------------------------
    // Clear (×) buttons
    // -------------------------------------------------------------------------
    function initClearButtons() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.d2w-clear-btn');
            if (!btn) return;
            var zone = btn.closest('.d2w-drop-area');
            if (zone) clearZone(zone);
        });
    }

    // -------------------------------------------------------------------------
    // Template input for post_title. Typing in it clears the drop zone,
    // clicking a token inserts it at the cursor.
    // -------------------------------------------------------------------------
    function initTemplateInput() {
        var tpl = document.querySelector('.d2w-template-input');
        if (!tpl) return;

        tpl.addEventListener('input', clearTitleZoneIfTemplate);

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.d2w-token');
            if (!btn) return;
            e.preventDefault();
            insertAtCursor(tpl, btn.dataset.token || '');
            clearTitleZoneIfTemplate.call(tpl);
        });
    }

    function clearTitleZoneIfTemplate() {
        if (!this.value.trim()) return;
        var zone = document.querySelector('.d2w-drop-area[data-wc-field="post_title"]');
        if (zone && zone.classList.contains('has-field')) {
            clearZone(zone);
        }
    }

    function insertAtCursor(input, text) {
        var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
        var end   = typeof input.selectionEnd === 'number' ? input.selectionEnd : input.value.length;
        input.value = input.value.slice(0, start) + text + input.value.slice(end);
        input.focus();
        input.setSelectionRange(start + text.length, start + text.length);
    }

    // -------------------------------------------------------------------------
    // Custom meta row management
    // -------------------------------------------------------------------------
    function initCustomMeta() {
        var addBtn = document.getElementById('d2w-add-custom-meta');
        if (!addBtn) return;

        addBtn.addEventListener('click', function () {
            var tpl = document.getElementById('d2w-custom-row-tpl');
            if (!tpl) return;

            var clone = tpl.content.cloneNode(true);
            customRowIndex++;

            var zone = clone.querySelector('.d2w-drop-area');
            if (zone) zone.dataset.wcField = 'custom_meta_' + customRowIndex;

            var tbody = document.getElementById('d2w-mapping-tbody');
            if (tbody) tbody.appendChild(clone);

            updateChipStates();
        });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.d2w-remove-custom-row');
            if (!btn) return;
            var row = btn.closest('tr');
            if (row) {
                row.remove();
                updateChipStates();
            }
        });
    }

    // -------------------------------------------------------------------------
    // Reset to defaults link
    // -------------------------------------------------------------------------
    function initResetLink() {
        var btn = document.querySelector('.d2w-reset-btn');
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (confirm('Reset all mappings to defaults?')) {
                window.location.href = btn.href;
            }
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    function assignField(zone, field, label) {
        zone.classList.add('has-field');
        zone.innerHTML =
            '<span class="d2w-assigned-chip">' +
                escHtml(label) +
                '<button type="button" class="d2w-clear-btn" aria-label="Remove">&times;</button>' +
            '</span>' +
            '<input type="hidden" name="' + getInputName(zone) + '" value="' + escAttr(field) + '" class="d2w-field-input" />';
        updateChipStates();
    }

    function clearZone(zone) {
        zone.classList.remove('has-field');
        zone.innerHTML =
            '<span class="d2w-drop-hint">Drop field here</span>' +
            '<input type="hidden" name="' + getInputName(zone) + '" value="" class="d2w-field-input" />';
        updateChipStates();
    }

    function getInputName(zone) {
        var wc = zone.dataset.wcField || '';
        if (wc.indexOf('custom_meta') === 0) {
            return 'mapping[custom_meta][discogs_field][]';
        }
        if (wc.indexOf('tax_') === 0) {
            return 'mapping[taxonomies][' + wc.slice(4) + ']';
        }
        return 'mapping[' + wc + '][discogs_field]';
    }

    // Taxonomy slots take only release list fields (genres, styles), which fit nowhere else.
    function accepts(zone, field) {
        var allowed = zone.dataset.accepts ? zone.dataset.accepts.split(',') : null;
        var chip = document.querySelector('.d2w-source-chip[data-discogs-field="' + field + '"]');
        var releaseOnly = chip && chip.dataset.releaseOnly === '1';
        return allowed ? allowed.indexOf(field) !== -1 : !releaseOnly;
    }

    function getLabelForField(field) {
        var chip = document.querySelector('.d2w-source-chip[data-discogs-field="' + field + '"]');
        return chip ? (chip.dataset.label || chip.textContent.trim()) : field;
    }

    function updateChipStates() {
        var used = {};
        document.querySelectorAll('.d2w-field-input').forEach(function (inp) {
            if (inp.value) used[inp.value] = true;
        });
        document.querySelectorAll('.d2w-source-chip').forEach(function (chip) {
            chip.classList.toggle('is-used', !!used[chip.dataset.discogsField]);
        });
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
}());