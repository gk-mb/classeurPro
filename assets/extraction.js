(() => {
    const form = document.querySelector('#extraction-form');
    const field = name => form.elements.namedItem(name);
    const status = document.querySelector('#extraction-status');
    const submit = document.querySelector('#extraction-submit');
    const picker = document.querySelector('#global-columns');
    const state = { global: { ready: false, revision: 0 }, partiel: { ready: false, revision: 0 } };
    let busy = false;
    const checked = () => [...picker.querySelectorAll('input:checked:not(:disabled)')].map(input => input.value);
    const hybrid = form.elements.extraction_module.value === 'hybrid';
    const sheetNames = id => [...document.getElementById(id + '_sheets').selectedOptions].map(o => o.value);
    const syncHybrid = () => {
        if (!hybrid) return;
        for (const id of ['global', 'partiel']) {
            const item = state[id]; if (!item.ready) continue;
            let names = sheetNames(id);
            const other = id === 'global' ? 'partiel' : 'global';
            if (field('match_sheets').value === '1' && state[other].ready) names = names.filter(n => sheetNames(other).includes(n));
            AppUI.message(document.getElementById(id+'_status'),names.length ? `${names.length} feuille(s) retenue(s). Choisissez votre clé.` : 'Aucune feuille retenue. Choisissez au moins une feuille et vérifiez les noms si vous demandez leur association.',names.length ? 'success':'error');
            item.columns = item.allColumns.filter(c => c.sheets.some(n => names.includes(n)));
            const ids = item.columns.map(c => c.id);
            for (const option of field(id + '_key').options) option.disabled = !!option.value && !ids.includes(option.value);
            if (!ids.includes(field(id + '_key').value)) field(id + '_key').value = '';
            if (id === 'global') picker.querySelectorAll('input').forEach(input => { input.disabled = !ids.includes(input.value); input.closest('label').hidden = input.disabled; });
            else {
                for (const option of field('insert_before').options) option.disabled = !!option.value && !ids.includes(option.value);
                if (!ids.includes(field('insert_before').value)) field('insert_before').value = '';
            }
        }
    };
    const normalize = text => text.trim().replace(/\s+/gu, ' ').toUpperCase();
    const layout = () => {
        if (!state.global.ready || !state.partiel.ready) return [];
        const selected = checked(); const used = new Set(state.partiel.columns.map(c => normalize(c.label)));
        const additions = state.global.columns.filter(c => selected.includes(c.id)).map(c => {
            let label = c.label;
            if (used.has(normalize(label))) label += ' (GLOBAL)';
            for (let n = 2; used.has(normalize(label)); n++) label = `${c.label} (GLOBAL ${n})`;
            used.add(normalize(label)); return { label, source: 'global' };
        });
        const result = [];
        state.partiel.columns.forEach(c => { if (c.id === field('insert_before').value) result.push(...additions); result.push({ label: c.label, source: 'partiel' }); });
        if (!field('insert_before').value) result.push(...additions);
        return result;
    };
    const refresh = () => {
        const ready = state.global.ready && state.partiel.ready && field('global_key').value && field('partiel_key').value;
        submit.disabled = busy || !ready;
        document.querySelector('#columns-count').textContent = state.global.ready ? `${checked().length} / ${state.global.columns.length} colonne(s) sélectionnée(s)` : 'En attente de GLOBAL';
        document.querySelector('#select-all').disabled = busy || !state.global.ready;
        document.querySelector('#select-none').disabled = busy || !state.global.ready;
        const summary = document.querySelector('#extraction-summary');
        const preview = document.querySelector('#column-preview'); preview.replaceChildren();
        summary.textContent = ready ? `GLOBAL : ${field('global_key').selectedOptions[0].textContent} ↔ PARTIEL : ${field('partiel_key').selectedOptions[0].textContent}. ${checked().length} colonne(s) à ajouter. Position : ${field('insert_before').selectedOptions[0].textContent}.` : 'Choisissez les fichiers et leurs clés pour voir le récapitulatif.';
        if (ready) {
            const columns = layout();
            columns.slice(0, 100).forEach((c, i) => { const tag = document.createElement('span'); tag.className = c.source === 'global' ? 'preview-global' : ''; tag.textContent = `${i + 1}. ${c.label}`; preview.append(tag); });
            if (columns.length > 100) { const more = document.createElement('span'); more.textContent = `… et ${columns.length - 100} autres colonnes`; preview.append(more); }
        }
        if (!busy && !['error', 'success'].includes(status.dataset.tone)) AppUI.message(status, ready ? 'Tout est configuré. Vous pouvez générer le résultat.' : 'Importez les deux fichiers et choisissez une clé dans chacun.');
    };
    const changed = () => { AppUI.message(status, 'Configuration modifiée.'); refresh(); };
    for (const id of ['global', 'partiel']) {
        const item = state[id];
        const inspect = async () => {
            const revision = ++item.revision; item.controller?.abort(); item.ready = false;
            field(id + '_key').replaceChildren(new Option('Chargement des colonnes…', '')); field(id + '_key').disabled = true;
            if (id === 'global') picker.replaceChildren();
            else { field('insert_before').replaceChildren(new Option('Après toutes les colonnes PARTIEL (par défaut)', '')); field('insert_before').disabled = true; }
            changed();
            const file = field(id).files[0]; const notice = document.querySelector('#' + id + '_status');
            const filename = document.querySelector('#' + id + '_filename'); filename.hidden = !file; filename.textContent = file?.name || '';
            if (!file) { field(id + '_key').replaceChildren(new Option('Importez d’abord le fichier', '')); AppUI.message(notice, 'Choisissez un fichier Excel.'); return; }
            if (!field(id + '_header').checkValidity()) { AppUI.message(notice, 'Indiquez le numéro entier de la ligne contenant les noms des colonnes.', 'error'); return; }
            if (!/\.(xlsx|xls)$/i.test(file.name) || !file.size || file.size > 25 * 1024 * 1024) { AppUI.message(notice, 'Choisissez un fichier Excel .xlsx ou .xls non vide de 25 Mo maximum.', 'error'); return; }
            AppUI.message(notice, 'Lecture des feuilles et des colonnes…', 'loading');
            const data = new FormData(); data.set('csrf_token', field('csrf_token').value); data.set('excel', file); data.set('header', field(id + '_header').value); data.set('module', field('extraction_module').value);
            item.controller = new AbortController();
            try {
                const result = await AppUI.json(await fetch('extraction_inspect.php', { method: 'POST', body: data, signal: item.controller.signal }));
                if (revision !== item.revision) return;
                Object.assign(item, result, { ready: true });
                if (hybrid) {
                    item.allColumns = result.columns;
                    const sheets = document.getElementById(id + '_sheets');
                    sheets.replaceChildren(...result.sheets.map(s => new Option(s.name, s.name, true, true))); sheets.disabled = false;
                }
                field(id + '_key').replaceChildren(new Option('Choisir la colonne clé…', ''), ...item.columns.map(c => new Option(c.label, c.id))); field(id + '_key').disabled = false;
                if (id === 'global') {
                    const fragment = document.createDocumentFragment();
                    item.columns.forEach(c => {
                        const label = document.createElement('label'); const input = document.createElement('input'); input.type = 'checkbox'; input.value = c.id; input.checked = true;
                        const text = document.createElement('span'); text.textContent = c.label; label.append(input, text); label.title = `Présente dans ${c.sheets.length} feuille(s) : ${c.sheets.join(', ')}`; fragment.append(label);
                    }); picker.replaceChildren(fragment);
                } else {
                    field('insert_before').replaceChildren(new Option('Après toutes les colonnes PARTIEL (par défaut)', ''), ...item.columns.map(c => new Option('Avant « ' + c.label + ' »', c.id))); field('insert_before').disabled = false;
                }
                AppUI.message(notice, `${item.sheetCount} feuille(s), ${item.columns.length} colonne(s) détectée(s). Choisissez votre clé ci-dessous.`, 'success');
                syncHybrid();
            } catch (error) { if (revision === item.revision && error.name !== 'AbortError') { field(id + '_key').replaceChildren(new Option('Vérifiez le fichier ci-dessus', '')); AppUI.message(notice, AppUI.error(error), 'error'); } }
            refresh();
        };
        field(id).addEventListener('change', inspect);
        let timer;
        field(id + '_header').addEventListener('input', () => { clearTimeout(timer); item.controller?.abort(); item.revision++; item.ready = false; changed(); timer = setTimeout(inspect, 400); });
        field(id + '_key').addEventListener('change', changed);
    }
    picker.addEventListener('change', changed);
    if (hybrid) for (const id of ['global_sheets', 'partiel_sheets', 'match_sheets']) document.getElementById(id).addEventListener('change', () => { syncHybrid(); changed(); });
    field('insert_before').addEventListener('change', changed);
    field('output_mode').addEventListener('change', changed);
    field('append_missing').addEventListener('change', changed);
    field('key_ignore').addEventListener('input', changed);
    document.querySelector('#select-all').addEventListener('click', () => { picker.querySelectorAll('input').forEach(input => input.checked = true); changed(); });
    document.querySelector('#select-none').addEventListener('click', () => { picker.querySelectorAll('input').forEach(input => input.checked = false); changed(); });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy || submit.disabled || !form.reportValidity()) return;
        const data = new FormData(form); data.set('global_columns', JSON.stringify(checked()));
        if (hybrid) for (const id of ['global', 'partiel']) data.set(id + '_sheets', JSON.stringify(sheetNames(id)));
        const controls = [...form.querySelectorAll('input,select,button')]; const disabled = controls.map(c => c.disabled);
        busy = true; controls.forEach(c => c.disabled = true); submit.textContent = 'Extraction en cours…';
        AppUI.message(status, 'Recherche des correspondances et préparation du fichier…', 'loading');
        try {
            const response = await fetch(form.action, { method: 'POST', body: data }); await AppUI.download(response, 'extraction.xlsx');
            AppUI.message(status, `Téléchargement lancé : ${response.headers.get('X-Extraction-Matched')} ligne(s) trouvée(s), ${response.headers.get('X-Extraction-Missing')} non trouvée(s). Consultez aussi les feuilles « Non trouves » et « Rapport ».`, 'success');
        } catch (error) { AppUI.message(status, AppUI.error(error), 'error'); }
        finally { busy = false; controls.forEach((c, i) => c.disabled = disabled[i]); submit.textContent = 'Générer et télécharger'; refresh(); }
    });
})();
