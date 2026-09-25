(() => {
    const form = document.querySelector('#enrich-form');
    const button = document.querySelector('#enrich-submit');
    const status = document.querySelector('#enrich-status');
    const state = {};
    let busy = false;
    let review;
    const field = name => form.elements.namedItem(name);
    const message = (element, text, tone = 'info') => AppUI.message(element, text, tone);
    const label = name => [...field(name).selectedOptions].map(option => option.textContent).join(', ');
    const configured = () => ['origin', 'extra'].every(id => state[id]?.ready && field(id + '_key').value) && field('add_column').value;
    const requestData = () => {
        if (!configured()) return null;
        const data = new FormData(form); data.delete('add_column'); data.set('add_columns', JSON.stringify([...field('add_column').selectedOptions].map(option => option.value))); return data;
    };
    const refresh = () => {
        button.disabled = busy || !configured() || !review?.ready();
        const filesReady = ['origin', 'extra'].every(id => state[id]?.ready);
        document.querySelector('#step-files').classList.toggle('is-complete', filesReady);
        document.querySelector('#step-columns').classList.toggle('is-complete', !button.disabled);
        document.querySelector('#mapping-summary').textContent = configured()
            ? `Origine « ${field('origin_sheet').value} » : ${label('origin_key')} ↔ Complément « ${field('extra_sheet').value} » : ${label('extra_key')}. Colonnes à ajouter : ${label('add_column')}. Position : ${label('insert_before')}.`
            : 'Choisissez les deux colonnes clés et les colonnes à ajouter pour voir le récapitulatif.';
        if (!busy && status.dataset.tone !== 'success' && status.dataset.tone !== 'error') message(status, !button.disabled ? 'Tout est configuré. Vous pouvez générer le fichier enrichi.' : configured() ? 'Vérifiez les doublons et choisissez comment les traiter avant de télécharger.' : filesReady ? 'Choisissez les deux colonnes clés et les colonnes à ajouter.' : 'Importez les fichiers Origine et Complément pour continuer.');
    };
    const columns = id => {
        const sheet = state[id].sheets.find(sheet => sheet.name === field(id + '_sheet').value);
        if (id === 'origin') {
            const select = field('insert_before');
            select.replaceChildren(new Option('Après toutes les colonnes (par défaut)', ''));
            for (let index = 1; index <= (sheet?.lastColumn || 0); index++) {
                let col = ''; let number = index;
                while (number) { number--; col = String.fromCharCode(65 + number % 26) + col; number = Math.floor(number / 26); }
                select.add(new Option(`Avant ${col} — ${sheet.headers[col] || 'sans en-tête'}`, col));
            }
            select.disabled = !sheet;
        }
        for (const name of id === 'extra' ? ['extra_key', 'add_column'] : ['origin_key']) {
            const select = field(name); const previous = select.value;
            select.replaceChildren(...(name === 'add_column' ? [] : [new Option('Choisir une colonne…', '')]), ...Object.entries(sheet?.headers || {}).map(([col, label]) => new Option(col + ' — ' + label, col, name === 'add_column', name === 'add_column')));
            if (name !== 'add_column' && [...select.options].some(option => option.value === previous)) select.value = previous;
            select.disabled = !Object.keys(sheet?.headers || {}).length;
        }
        if (state[id].ready) message(document.querySelector('#' + id + '_status'), Object.keys(sheet?.headers || {}).length ? `${Object.keys(sheet.headers).length} colonne(s) détectée(s) dans « ${sheet.name} ».` : 'Aucun en-tête sur cette ligne. Vérifiez le numéro de ligne ou choisissez une autre feuille.', Object.keys(sheet?.headers || {}).length ? 'success' : 'error');
        refresh();
    };
    for (const id of ['origin', 'extra']) {
        state[id] = { ready: false, revision: 0, sheets: [] };
        const inspect = async () => {
            const item = state[id]; const revision = ++item.revision;
            item.controller?.abort(); item.ready = false; refresh();
            message(status, 'Sélectionnez les deux fichiers et les colonnes.');
            document.querySelector('#step-export').classList.remove('is-complete');
            const notice = document.querySelector('#' + id + '_status');
            for (const name of [id + '_sheet', id + '_key', ...(id === 'extra' ? ['add_column'] : ['insert_before'])]) { field(name).replaceChildren(); field(name).disabled = true; }
            const file = field(id).files[0];
            const fileLabel = document.querySelector('#' + id + '_filename');
            fileLabel.hidden = !file;
            fileLabel.textContent = file ? `${file.name} · ${(file.size / 1024 / 1024).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} Mo` : '';
            if (!file) { message(notice, 'Choisissez un fichier Excel pour continuer.'); return; }
            if (!field(id + '_header').checkValidity()) { message(notice, 'Indiquez un numéro de ligne entier entre 1 et 1 048 576. Exemple : 2 si les en-têtes sont sur la deuxième ligne.', 'error'); return; }
            if (!/\.(xlsx|xls)$/i.test(file.name)) { message(notice, 'Format non accepté. Choisissez un fichier Excel .xlsx ou .xls.', 'error'); return; }
            if (!file.size || file.size > 25 * 1024 * 1024) { message(notice, 'Ce fichier est vide ou dépasse 25 Mo. Choisissez un fichier non vide de taille inférieure.', 'error'); return; }
            message(notice, 'Lecture des feuilles et des colonnes…', 'loading');
            const data = new FormData(); data.set('csrf_token', field('csrf_token').value); data.set('action', 'inspect'); data.set('excel', file); data.set('header', field(id + '_header').value);
            item.controller = new AbortController();
            try {
                const response = await fetch(form.action, { method: 'POST', body: data, signal: item.controller.signal });
                const result = await AppUI.json(response);
                if (revision !== item.revision) return;
                if (!response.ok) throw new Error(result.message);
                item.sheets = result.sheets; item.ready = true;
                field(id + '_sheet').replaceChildren(...item.sheets.map(sheet => new Option(sheet.name, sheet.name)));
                field(id + '_sheet').disabled = false; columns(id);
                form.dispatchEvent(new Event('configuration-ready'));
            } catch (error) {
                if (revision === item.revision && error.name !== 'AbortError') message(notice, AppUI.error(error), 'error');
            }
            refresh();
        };
        field(id).addEventListener('change', inspect);
        let timer;
        field(id + '_header').addEventListener('input', () => {
            clearTimeout(timer); state[id].revision++; state[id].controller?.abort(); state[id].ready = false; refresh();
            timer = setTimeout(inspect, 450);
        });
        field(id + '_sheet').addEventListener('change', () => { message(status, 'Configuration modifiée.'); document.querySelector('#step-export').classList.remove('is-complete'); columns(id); });
        field(id + '_key').addEventListener('change', () => { message(status, 'Configuration modifiée.'); document.querySelector('#step-export').classList.remove('is-complete'); refresh(); });
    }
    document.querySelector('#add-all').addEventListener('click', () => { for (const option of field('add_column').options) option.selected = true; field('add_column').dispatchEvent(new Event('change')); });
    review = new DuplicateReview(form, requestData, refresh);
    field('add_column').addEventListener('change', () => { message(status, 'Configuration modifiée.'); document.querySelector('#step-export').classList.remove('is-complete'); refresh(); });
    field('insert_before').addEventListener('change', () => { message(status, 'Position modifiée.'); document.querySelector('#step-export').classList.remove('is-complete'); refresh(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled || !form.reportValidity()) return;
        const data = new FormData(form); data.set('action', 'merge'); data.delete('add_column'); data.set('add_columns', JSON.stringify([...field('add_column').selectedOptions].map(option => option.value)));
        const controls = [...form.querySelectorAll('input,select,button')];
        busy = true; controls.forEach(control => control.disabled = true);
        button.textContent = 'Enrichissement en cours…';
        message(status, 'Recherche des correspondances et génération du résultat…', 'loading');
        try {
            const response = await fetch(form.action, { method: 'POST', body: data });
            await AppUI.download(response, 'origine_enrichie.xlsx');
            message(status, 'Téléchargement lancé : ' + response.headers.get('X-Enrich-Matched') + ' ligne(s) correspondante(s), ' + response.headers.get('X-Enrich-Missing') + ' ligne(s) non trouvée(s). Retrouvez ces dernières en rose et dans la feuille « Non trouvés ».', 'success');
            document.querySelector('#step-export').classList.add('is-complete');
        } catch (error) { message(status, AppUI.error(error), 'error'); }
        finally { busy = false; button.textContent = 'Enrichir et télécharger'; controls.forEach(control => control.disabled = false); refresh(); }
    });
})();
