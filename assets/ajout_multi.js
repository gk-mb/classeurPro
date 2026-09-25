(() => {
    const form = document.getElementById('multi-form'), button = document.getElementById('multi-submit'), status = document.getElementById('multi-status');
    const field = id => document.getElementById(id), state = {}, selected = id => [...field(id).selectedOptions].map(o => o.value);
    let busy = false, review;
    const configured = () => ['origin','extra'].every(id => state[id]?.ready && selected(id+'_sheets').length && field(id+'_key').value && [...field(id+'_keys').querySelectorAll('select')].every(s=>s.value)) && selected('add_columns').length;
    const data = () => { if (!configured()) return null; const d = new FormData(form); for (const id of ['origin_sheets','extra_sheets','add_columns']) d.set(id,JSON.stringify(selected(id))); for (const id of ['origin','extra']) d.set(id+'_keys',JSON.stringify(Object.fromEntries([...field(id+'_keys').querySelectorAll('select')].map(s=>[s.dataset.sheet,s.value])))); return d; };
    const refresh = () => { button.disabled = busy || !configured() || !review?.ready(); };
    const keyChoices = (id, reset = false) => {
        const panel=field(id+'_keys'), old=Object.fromEntries([...panel.querySelectorAll('select')].map(s=>[s.dataset.sheet,s.value])); panel.replaceChildren();
        if (!state[id]?.ready || !field(id+'_key').value) return;
        const other=id==='origin'?'extra':'origin';
        const sheets=state[id].sheets.filter(s=>selected(id+'_sheets').includes(s.name) && (field('match_sheets').value!=='1' || !state[other]?.ready || selected(other+'_sheets').includes(s.name)));
        const first=sheets.find(s=>s.columns[field(id+'_key').value]); const position=first?.columns[field(id+'_key').value];
        const labels=Object.fromEntries(state[id].columns.map(c=>[c.id,c.label]));
        sheets.forEach((sheet,i)=>{
            const label=document.createElement('label'), select=document.createElement('select'); select.id=id+'-key-sheet-'+i; select.dataset.sheet=sheet.name; select.required=true;
            label.htmlFor=select.id; label.className='field-label'; label.textContent='Clé dans « '+sheet.name+' »';
            select.replaceChildren(new Option('Choisir la colonne…',''),...Object.entries(sheet.columns).map(([key,col])=>new Option(col+' — '+labels[key],col)));
            const chosen=!reset && old[sheet.name] ? old[sheet.name] : position;
            select.value=chosen || ''; panel.append(label,select);
            if (!select.value) { field('multi-options').open = true; AppUI.message(field(id+'_status'), 'Choisissez la colonne clé dans « '+sheet.name+' » : sa position diffère des autres feuilles.', 'error'); }
        });
    };
    const choices = () => {
        for (const id of ['origin','extra']) {
            if (!state[id]?.ready) continue;
            const own = selected(id+'_sheets'), other = id === 'origin' ? 'extra' : 'origin';
            const names = field('match_sheets').value === '1' && state[other]?.ready ? own.filter(n => selected(other+'_sheets').includes(n)) : own;
            if (!names.length) AppUI.message(field(id+'_status'),own.length ? 'Aucune feuille choisie ne porte le même nom des deux côtés. Changez les feuilles ou désactivez leur association.' : 'Choisissez au moins une feuille dans ce fichier.','error');
            else AppUI.message(field(id+'_status'),`${names.length} feuille(s) retenue(s). Choisissez leur clé.`,'success');
            const columns = state[id].columns.filter(c => c.sheets.some(n => names.includes(n)));
            const replace = (select, blank, all = false) => {
                const old = [...select.selectedOptions].map(o => o.value);
                const first = !select.dataset.loaded;
                select.replaceChildren(...(blank ? [new Option(blank,'')] : []), ...columns.map(c => new Option(c.label,c.id,false,all ? (first || old.includes(c.id)) : old.includes(c.id))));
                select.dataset.loaded = '1'; select.disabled = !columns.length;
                if (!all && !columns.some(c => old.includes(c.id))) select.value = '';
            };
            replace(field(id+'_key'),'Choisir la clé…');
            keyChoices(id);
            if (id === 'extra') replace(field('add_columns'),null,true);
            else replace(field('insert_before'),'Après toutes les colonnes');
        }
        refresh();
    };
    review = new DuplicateReview(form, data, refresh);
    for (const id of ['origin','extra']) {
        state[id] = {ready:false, revision:0};
        const inspect = async () => {
            const item = state[id], revision = ++item.revision; item.controller?.abort(); item.ready=false; review.invalidate(); refresh();
            const notice=field(id+'_status'), file=field(id).files[0];
            field(id+'_sheets').replaceChildren(); field(id+'_sheets').disabled=true; field(id+'_key').replaceChildren(new Option('Importez le fichier','')); field(id+'_key').disabled=true;
            if (id === 'extra') { field('add_columns').replaceChildren(); delete field('add_columns').dataset.loaded; }
            if (!file) { AppUI.message(notice,'Choisissez un fichier Excel.'); return; }
            if (!field(id+'_header').checkValidity()) { AppUI.message(notice,'Indiquez la ligne qui contient les noms des colonnes, par exemple 1 ou 2.','error'); return; }
            if (!/\.(xlsx|xls)$/i.test(file.name) || !file.size || file.size>25*1024*1024) { AppUI.message(notice,'Choisissez un fichier Excel non vide de 25 Mo maximum.','error'); return; }
            const d = new FormData(); d.set('csrf_token',form.elements.csrf_token.value); d.set('excel',file); d.set('header',field(id+'_header').value);
            item.controller=new AbortController(); AppUI.message(notice,'Lecture des feuilles et des colonnes…','loading');
            try {
                const result=await AppUI.json(await fetch('extraction_inspect.php',{method:'POST',body:d,signal:item.controller.signal}));
                if (revision!==item.revision) return;
                Object.assign(item,result,{ready:true}); field(id+'_sheets').replaceChildren(...result.sheets.map(s=>new Option(s.name,s.name,true,true))); field(id+'_sheets').disabled=false;
                choices(); AppUI.message(notice,`${result.sheetCount} feuille(s) disponible(s). Choisissez les feuilles et la clé.`,'success'); form.dispatchEvent(new Event('configuration-ready'));
            } catch(e) { if(revision===item.revision && e.name!=='AbortError') AppUI.message(notice,AppUI.error(e),'error'); }
            refresh();
        };
        field(id).addEventListener('change',inspect);
        let timer; field(id+'_header').addEventListener('input',()=>{ clearTimeout(timer); state[id].ready=false; state[id].revision++; state[id].controller?.abort(); refresh(); timer=setTimeout(inspect,400); });
        field(id+'_sheets').addEventListener('change',choices); field(id+'_key').addEventListener('change',()=>{ keyChoices(id,true); refresh(); });
    }
    field('match_sheets').addEventListener('change',choices);
    field('multi-all').addEventListener('click',()=>{ for(const option of field('add_columns').options) option.selected=true; field('add_columns').dispatchEvent(new Event('change',{bubbles:true})); });
    form.addEventListener('submit',async event=>{
        event.preventDefault(); if(button.disabled || !form.reportValidity()) return;
        const d=data(); d.set('action','merge'); const controls=[...form.querySelectorAll('input,select,button')], disabled=controls.map(c=>c.disabled);
        busy=true; controls.forEach(c=>c.disabled=true); AppUI.message(status,'Recherche des correspondances et préparation du résultat…','loading');
        try { const response=await fetch(form.action,{method:'POST',body:d}); await AppUI.download(response,'ajout_multi.xlsx'); AppUI.message(status,`Téléchargement lancé : ${response.headers.get('X-Enrich-Matched')} ligne(s) trouvée(s), ${response.headers.get('X-Enrich-Missing')} non trouvée(s).`,'success'); }
        catch(e) { AppUI.message(status,AppUI.error(e),'error'); }
        finally { busy=false; controls.forEach((c,i)=>c.disabled=disabled[i]); refresh(); }
    });
})();
