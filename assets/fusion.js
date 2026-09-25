(() => {
    const form = document.querySelector('#fusion-form');
    const status = document.querySelector('#fusion-status');
    const button = document.querySelector('#fusion-submit');
    const format = document.querySelector('#fusion-format');
    let revision = 0;
    let controller;
    let valid = false;
    let busy = false;
    const columns = document.querySelector('#fusion-columns');
    const selection = side => Object.fromEntries([...columns.querySelectorAll(`fieldset[data-side="${side}"]`)].map(panel => [panel.dataset.table, [...panel.querySelectorAll('input:checked')].map(input => input.value)]));
    const renderColumns = catalogs => {
        columns.replaceChildren();
        for (const side of ['x', 'y']) for (const table of catalogs[side]) {
            const panel = document.createElement('fieldset'); panel.className = 'enrich-panel'; panel.dataset.side = side; panel.dataset.table = table.id;
            const legend = document.createElement('legend'); legend.textContent = `${side.toUpperCase()} — ${table.label}`; panel.append(legend);
            const help = document.createElement('p'); help.className = 'help-copy'; help.textContent = 'Colonnes à conserver, dans leur ordre d’origine.'; panel.append(help);
            const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'secondary-button'; toggle.textContent = 'Tout sélectionner / désélectionner';
            toggle.addEventListener('click', () => { const inputs = [...panel.querySelectorAll('input')]; const checked = !inputs.every(input => input.checked); inputs.forEach(input => input.checked = checked); columns.dispatchEvent(new Event('change')); }); panel.append(toggle);
            const list = document.createElement('div'); list.className = 'column-picker';
            for (const col of table.columns) { const label = document.createElement('label'); const input = document.createElement('input'); input.type = 'checkbox'; input.value = col.id; input.checked = true; label.append(input, document.createTextNode(col.label)); list.append(label); }
            panel.append(list); columns.append(panel);
        }
    };
    columns.addEventListener('change', () => {
        const empty = [...columns.querySelectorAll('fieldset')].some(panel => !panel.querySelector('input:checked'));
        button.disabled = busy || !valid || empty;
        show(empty ? 'Choisissez au moins une colonne dans chaque tableau.' : 'Les colonnes choisies seront alignées de gauche à droite. Si leur nombre diffère, les cases restantes seront vides.', [], empty ? 'error' : 'info');
    });
    const show = (message, details = [], tone = 'info') => {
        AppUI.message(status, message, tone);
        if (details.length) {
            const list = document.createElement('ul');
            details.forEach(text => { const item = document.createElement('li'); item.textContent = text; list.append(item); });
            status.append(list);
        }
    };
    form.querySelectorAll('input[type=file]').forEach(input => input.addEventListener('change', async () => {
        input.closest('.file-field').querySelector('.file-name').textContent = input.files[0]?.name || 'Aucun fichier sélectionné';
        const current = ++revision;
        controller?.abort();
        valid = false; button.disabled = true; format.disabled = true;
        columns.replaceChildren();
        if (!form.elements.x.files.length || !form.elements.y.files.length) {
            show('Sélectionnez les deux fichiers pour vérifier leur conformité.'); return;
        }
        if ([form.elements.x.files[0], form.elements.y.files[0]].some(file => file.size === 0 || file.size > 25 * 1024 * 1024)) {
            show('Fichier vide ou trop volumineux : choisissez des fichiers non vides de 25 Mo maximum chacun.', [], 'error'); return;
        }
        controller = new AbortController();
        show('Vérification des fichiers et des correspondances en cours…', [], 'loading');
        const data = new FormData(form); data.set('action', 'check');
        try {
            const response = await fetch(form.action, { method: 'POST', body: data, signal: controller.signal });
            const result = await AppUI.json(response);
            if (current !== revision) return;
            if (!response.ok || !result.compatible) throw new Error(result.message || 'Vérification impossible.');
            show('Vérification terminée — la fusion peut être lancée.', result.details, 'success');
            format.replaceChildren(...result.formats.map(value => new Option(value.toUpperCase(), value)));
            renderColumns(result.columns);
            valid = true; format.disabled = false; button.disabled = false;
        } catch (error) {
            if (current === revision && error.name !== 'AbortError') show(AppUI.error(error), [], 'error');
        }
    }));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!valid || button.disabled) return;
        const current = revision;
        busy = true;
        button.disabled = true;
        const settings = [...form.querySelectorAll('select, input[type=checkbox], button[type=button]')];
        form.querySelectorAll('input[type=file]').forEach(input => input.disabled = true);
        // Les contrôles désactivés ne sont pas inclus dans FormData.
        const data = new FormData(form);
        data.set('x', form.elements.x.files[0]); data.set('y', form.elements.y.files[0]); data.set('action', 'merge');
        for (const side of ['x', 'y']) data.set(side + '_columns', JSON.stringify(selection(side)));
        settings.forEach(control => control.disabled = true);
        show('Fusion en cours… Les fichiers sont à nouveau contrôlés avant génération.', [], 'loading');
        try {
            const response = await fetch(form.action, { method: 'POST', body: data });
            await AppUI.download(response, 'fusion.' + data.get('format'));
            show('Fusion terminée. Le téléchargement a été lancé.', [], 'success');
        } catch (error) { show(AppUI.error(error), [], 'error'); }
        finally {
            busy = false;
            settings.forEach(control => control.disabled = false);
            form.querySelectorAll('input[type=file]').forEach(input => input.disabled = false);
            button.disabled = !valid || revision !== current;
        }
    });
})();
