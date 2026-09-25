(() => {
    const form=document.getElementById('print-form'), field=id=>document.getElementById(id), button=field('print-submit'), status=field('print-status'), sheets=field('print-sheets'), panels=field('print-columns');
    let catalog, revision=0, controller, busy=false;
    const names=()=>[...sheets.selectedOptions].map(o=>o.value);
    const columns=()=>Object.fromEntries([...panels.querySelectorAll('fieldset')].filter(p=>names().includes(p.dataset.sheet)).map(p=>[p.dataset.sheet,[...p.querySelectorAll('input:checked')].map(i=>i.value)]));
    const refresh=()=>{ const selected=columns(); button.disabled=busy || !catalog || !names().length || Object.values(selected).some(c=>!c.length); };
    const render=()=>{
        const labels=Object.fromEntries(catalog.columns.map(c=>[c.id,c.label])); panels.replaceChildren();
        for(const sheet of catalog.sheets) {
            const panel=document.createElement('fieldset'); panel.className='enrich-panel'; panel.dataset.sheet=sheet.name;
            const legend=document.createElement('legend'); legend.textContent=sheet.name; panel.append(legend);
            const toggle=document.createElement('button'); toggle.type='button'; toggle.className='secondary-button'; toggle.textContent='Tout sélectionner / désélectionner';
            toggle.addEventListener('click',()=>{ const inputs=[...panel.querySelectorAll('input')]; const checked=!inputs.every(i=>i.checked); inputs.forEach(i=>i.checked=checked); refresh(); }); panel.append(toggle);
            const list=document.createElement('div'); list.className='column-picker';
            for(const [id,letter] of Object.entries(sheet.columns)) { const label=document.createElement('label'),input=document.createElement('input'),text=document.createElement('span'); input.type='checkbox'; input.value=letter; input.checked=true; text.textContent=letter+' — '+labels[id]; label.append(input,text); list.append(label); }
            panel.append(list); panels.append(panel);
        }
        refresh();
    };
    const inspect=async()=>{
        const current=++revision; controller?.abort(); catalog=null; panels.replaceChildren(); sheets.replaceChildren(); sheets.disabled=true; refresh();
        const file=field('excel').files[0];
        if(!file) { AppUI.message(field('file-status'),'Choisissez un fichier Excel.'); return; }
        if(!field('header').checkValidity()) { AppUI.message(field('file-status'),'Indiquez la ligne qui contient les noms des colonnes, par exemple 1 ou 2.','error'); return; }
        if(!/\.(xls|xlsx)$/i.test(file.name) || !file.size || file.size>25*1024*1024) { AppUI.message(field('file-status'),'Choisissez un fichier Excel non vide de 25 Mo maximum.','error'); return; }
        const data=new FormData(form); controller=new AbortController(); AppUI.message(field('file-status'),'Lecture des feuilles et des colonnes…','loading');
        try { const result=await AppUI.json(await fetch('extraction_inspect.php',{method:'POST',body:data,signal:controller.signal})); if(current!==revision) return; catalog=result; sheets.replaceChildren(...result.sheets.map(s=>new Option(s.name,s.name,true,true))); sheets.disabled=false; render(); AppUI.message(field('file-status'),`${result.sheetCount} feuille(s) disponible(s). Choisissez les colonnes à mettre en forme.`,'success'); }
        catch(e) { if(current===revision && e.name!=='AbortError') AppUI.message(field('file-status'),AppUI.error(e),'error'); }
        refresh();
    };
    field('excel').addEventListener('change',inspect);
    let timer; field('header').addEventListener('input',()=>{ clearTimeout(timer); controller?.abort(); revision++; catalog=null; refresh(); timer=setTimeout(inspect,400); });
    sheets.addEventListener('change',()=>{ for(const p of panels.querySelectorAll('fieldset')) p.hidden=!names().includes(p.dataset.sheet); refresh(); });
    panels.addEventListener('change',refresh);
    form.addEventListener('submit',async event=>{
        event.preventDefault(); if(button.disabled || !form.reportValidity()) return;
        const data=new FormData(form); data.set('sheets',JSON.stringify(names())); data.set('columns',JSON.stringify(columns()));
        const controls=[...form.querySelectorAll('input,select,button')], disabled=controls.map(c=>c.disabled); busy=true; controls.forEach(c=>c.disabled=true);
        AppUI.message(status,'Création des bordures et préparation des pages…','loading');
        try { const response=await fetch(form.action,{method:'POST',body:data}); await AppUI.download(response,'pret_a_imprimer.xlsx'); AppUI.message(status,`${response.headers.get('X-Print-Sheets')} feuille(s) préparée(s). Ouvrez le fichier téléchargé puis choisissez Imprimer dans Excel.`,'success'); }
        catch(e) { AppUI.message(status,AppUI.error(e),'error'); }
        finally { busy=false; controls.forEach((c,i)=>c.disabled=disabled[i]); refresh(); }
    });
})();
