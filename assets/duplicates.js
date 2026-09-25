window.DuplicateReview = class {
    constructor(form, getData, onChange) {
        this.form = form; this.getData = getData; this.onChange = onChange; this.checked = false; this.hasDuplicates = false; this.revision = 0;
        this.status = document.getElementById('duplicates-status');
        this.mode = form.elements.duplicate_mode; this.occurrence = form.elements.duplicate_occurrence;
        for (const event of ['input', 'change']) form.addEventListener(event, e => {
            if ([this.mode, this.occurrence].includes(e.target)) { this.policyChanged(); return; }
            if (e.target.matches('input,select')) this.invalidate();
        });
        form.addEventListener('configuration-ready', () => this.invalidate());
    }
    ready() { return this.checked && (!this.hasDuplicates || this.mode.value === 'delete' || (this.mode.value === 'color' && this.occurrence.value)); }
    policyChanged() {
        document.getElementById('duplicate-occurrence-wrap').hidden = this.mode.value !== 'color';
        this.occurrence.required = this.hasDuplicates && this.mode.value === 'color';
        this.onChange();
    }
    invalidate() {
        clearTimeout(this.timer); this.controller?.abort(); this.revision++; this.checked = false; this.hasDuplicates = false;
        this.mode.value = ''; this.occurrence.value = ''; this.mode.required = false; this.occurrence.required = false;
        document.getElementById('duplicates-choices').hidden = true; document.getElementById('duplicates-examples').replaceChildren();
        AppUI.message(this.status, 'Choisissez les fichiers et leurs clés pour vérifier les doublons.'); this.onChange();
        if (this.getData()) this.timer = setTimeout(() => this.check(), 350);
    }
    async check() {
        const data = this.getData(); if (!data) return;
        const revision = this.revision; this.controller = new AbortController(); data.set('action', 'duplicates');
        AppUI.message(this.status, 'Recherche des clés répétées…', 'loading');
        try {
            const result = await AppUI.json(await fetch(this.form.action, {method:'POST', body:data, signal:this.controller.signal}));
            if (revision !== this.revision) return;
            this.checked = true; this.hasDuplicates = result.hasDuplicates;
            document.getElementById('duplicates-choices').hidden = !this.hasDuplicates; this.mode.required = this.hasDuplicates;
            AppUI.message(this.status, this.hasDuplicates ? `Origine : ${result.origin.repeated} répétition(s). Complément : ${result.extra.repeated} répétition(s). Choisissez comment les traiter ci-dessous.` : 'Aucune clé répétée dans les feuilles choisies.', this.hasDuplicates ? 'info' : 'success');
            const examples = document.getElementById('duplicates-examples'); examples.replaceChildren();
            for (const [side, label] of [['origin','Origine'],['extra','Complément']]) for (const example of result[side].examples) { const p=document.createElement('p'); p.className='help-copy'; p.textContent=`${label} — ${example.key} : ${example.places.join(' ; ')}`; examples.append(p); }
        } catch (error) { if (revision === this.revision && error.name !== 'AbortError') AppUI.message(this.status, AppUI.error(error), 'error'); }
        finally { if (revision === this.revision) this.onChange(); }
    }
};
