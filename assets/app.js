/* Listes multiples : cases à cocher, avec conservation des valeurs envoyées. */
(() => {
    document.querySelectorAll('select[multiple]').forEach(select => {
        const required = select.required;
        select.required = false;
        select.hidden = true;
        const group = document.createElement('fieldset');
        group.className = 'multi-checkboxes'; group.id = select.id + '-choices';
        const legend = document.createElement('legend');
        const label = document.querySelector(`label[for="${select.id}"]`);
        legend.textContent = label?.textContent || 'Votre sélection';
        if (label) label.hidden = true;
        const toolbar = document.createElement('div'); toolbar.className = 'column-toolbar';
        const count = document.createElement('span');
        toolbar.append(count);
        for (const [text, checked] of [['Tout cocher', true], ['Tout décocher', false]]) {
            const button = document.createElement('button'); button.type = 'button';
            button.className = 'secondary-button'; button.textContent = text;
            button.addEventListener('click', () => {
                for (const option of select.options) if (!option.disabled) option.selected = checked;
                select.dispatchEvent(new Event('change', {bubbles: true}));
            });
            toolbar.append(button);
        }
        const list = document.createElement('div'); list.className = 'multi-checkbox-list';
        group.append(legend, toolbar, list); select.after(group);
        const previousAllButton = document.getElementById(select.id === 'add_columns' ? 'multi-all' : select.id === 'add_column' ? 'add-all' : '');
        if (previousAllButton) previousAllButton.hidden = true;
        let signature = '';
        const sync = () => {
            const options = [...select.options];
            const next = JSON.stringify(options.map(o => [o.value, o.text, o.disabled]));
            if (next !== signature) {
                signature = next; list.replaceChildren();
                options.forEach((option, i) => {
                    const item = document.createElement('label'), input = document.createElement('input'), text = document.createElement('span');
                    input.type = 'checkbox'; input.id = select.id + '-choice-' + i;
                    text.textContent = option.text; item.append(input, text); list.append(item);
                    input.addEventListener('change', () => {
                        select.options[i].selected = input.checked;
                        select.dispatchEvent(new Event('change', {bubbles: true}));
                    });
                });
            }
            const inputs = [...list.querySelectorAll('input')];
            inputs.forEach((input, i) => {
                input.checked = options[i].selected;
                input.disabled = select.disabled || options[i].disabled;
                input.setCustomValidity('');
            });
            if (required && !select.disabled && inputs.length && !select.selectedOptions.length)
                inputs.find(input => !input.disabled)?.setCustomValidity('Cochez au moins un élément.');
            toolbar.querySelectorAll('button').forEach(button => button.disabled = select.disabled || !options.length);
            count.textContent = options.length ? `${select.selectedOptions.length} / ${options.length} sélectionné(s)` : 'Importez le fichier pour afficher les choix.';
        };
        new MutationObserver(sync).observe(select, {childList: true, subtree: true, attributes: true});
        select.addEventListener('change', sync);
        select.form?.addEventListener('reset', () => setTimeout(sync, 0));
        sync();
    });
})();

/* Composants communs et messages accessibles. */
window.AppUI = {
    message(element, text, tone = 'info') {
        element.textContent = text;
        element.dataset.tone = tone;
        element.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        element.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
        element.setAttribute('aria-busy', String(tone === 'loading'));
        if (tone === 'error' && window.showFieldError) window.showFieldError(element, text);
    },
    error(error) {
        if (error instanceof TypeError) return 'Connexion interrompue. Vérifiez votre connexion et réessayez. Vos choix sont conservés.';
        if (error instanceof SyntaxError) return 'L’application n’a pas pu terminer la demande. Réessayez avec des fichiers plus petits ou demandez de l’aide.';
        return error.message || 'Le traitement n’a pas abouti. Vérifiez vos fichiers puis réessayez.';
    },
    async json(response) {
        if (!response.headers.get('content-type')?.includes('application/json')) {
            throw new Error(response.status === 413 ? 'Les fichiers dépassent la taille totale autorisée. Réduisez leur taille puis réessayez.' : 'Le serveur n’a pas pu traiter la demande. Réessayez avec des fichiers plus petits ou contactez votre administrateur.');
        }
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'La demande a été refusée. Vérifiez les informations saisies.');
        return data;
    },
    async download(response, fallback) {
        if (!response.ok || !response.headers.get('content-disposition')?.includes('attachment')) {
            if (response.headers.get('content-type')?.includes('application/json')) { const data = await response.json(); throw new Error(data.message || 'Le fichier n’a pas pu être généré.'); }
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            const message = html.querySelector('[data-user-error]')?.textContent || (response.status < 500 ? html.querySelector('p')?.textContent : '');
            throw new Error(message || 'Le serveur n’a pas pu générer le fichier. Réessayez avec des fichiers plus petits ou contactez votre administrateur.');
        }
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a'); link.href = url;
        link.download = response.headers.get('content-disposition')?.match(/filename="?([^";]+)"?/)?.[1] || fallback;
        document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 30000);
    }
};
// Les erreurs restent près des choix concernés et sont réunies au début de la page.
(() => {
    const main = document.getElementById('main-content'); if (!main) return;
    const summary = document.createElement('section'); summary.className = 'status-box error-summary'; summary.hidden = true; summary.setAttribute('role','alert'); summary.tabIndex = -1; main.prepend(summary);
    let serial = 0, previous = '';
    const put = (field, text) => {
        if (field.matches('select[multiple]')) field = document.querySelector('#' + field.id + '-choices input:not(:disabled)') || field;
        if (!field.id) field.id = 'field-' + (++serial);
        let notice = document.getElementById(field.id + '-error');
        if (!notice) { notice = document.createElement('p'); notice.id = field.id+'-error'; notice.className='status-box field-error'; notice.dataset.fieldError=field.id; field.insertAdjacentElement('afterend',notice); }
        notice.textContent=text; notice.dataset.tone='error'; notice.hidden=false;
        for (let parent = field.parentElement; parent; parent = parent.parentElement) { if (parent.tagName === 'DETAILS') parent.open = true; }
        field.setAttribute('aria-invalid','true');
        const descriptions = new Set((field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean)); descriptions.add(notice.id); field.setAttribute('aria-describedby',[...descriptions].join(' '));
    };
    window.showFieldError = (element,text) => {
        const form=element.closest('form'); if (!form || element.classList.contains('field-error')) return;
        const lower=text.toLocaleLowerCase('fr'); let id;
        const side=lower.includes('complément') ? 'extra' : lower.includes('origine') ? 'origin' : lower.includes('partiel') ? 'partiel' : lower.includes('global') ? 'global' : element.id?.match(/^(origin|extra|global|partiel)_status$/)?.[1];
        if (lower.includes('répété') || lower.includes('répétition') || lower.includes('doublon')) id='duplicate_mode';
        else if(side) id=side+(lower.includes('clé') ? '_key' : lower.includes('feuille') ? (form.querySelector('#'+side+'_sheets') ? '_sheets' : '_sheet') : lower.includes('ligne') ? '_header' : '');
        else if(lower.includes('feuille') && form.querySelector('#print-sheets')) id='print-sheets';
        else if(lower.includes('colonne') && form.querySelector('#print-columns')) id='print-columns';
        else if(lower.includes('placement') || lower.includes('position')) id='insert_before';
        else if(lower.includes('colonne à ajouter') || lower.includes('colonnes à ajouter')) id=form.querySelector('#add_columns') ? 'add_columns' : 'add_column';
        const field=(id && form.querySelector('#'+id)) || element.closest('fieldset')?.querySelector('input,select') || (form.querySelectorAll('input[type=file]').length===1 ? (lower.includes('ligne') ? form.querySelector('[name=header]') : form.querySelector('input[type=file]')) : null);
        if(field) put(field,text);
    };
    document.addEventListener('invalid',event=>{
        const field=event.target; if(!field.closest('form')) return;
        let text='Complétez ce champ avant de continuer.';
        if(field.type==='file') text='Choisissez le fichier à utiliser.';
        else if(field.tagName==='SELECT') text='Choisissez une réponse dans cette liste.';
        else if(field.type==='number') text='Saisissez un nombre entier dans les limites indiquées.';
        put(field,text); summary.hidden=false;
    },true);
    for(const event of ['input','change']) document.addEventListener(event,e=>{
        const field=e.target; if(!field.id) return;
        const notice=document.getElementById(field.id+'-error'); if(notice) { notice.hidden=true; notice.dataset.tone='info'; field.removeAttribute('aria-invalid'); }
    },true);
    const sync=()=>{
        const notices=[...main.querySelectorAll('[data-tone="error"]')].filter(e=>e!==summary && !e.hidden);
        const items=[]; const seen=new Set();
        for(const notice of notices) { const text=notice.textContent.trim(); if(!text || seen.has(text)) continue; seen.add(text); if(!notice.id) notice.id='error-'+(++serial); items.push([text,notice.dataset.fieldError || notice.id]); }
        const signature=JSON.stringify(items); if(signature===previous) return; previous=signature;
        summary.hidden=!items.length; summary.dataset.tone=items.length ? 'error':'info'; summary.replaceChildren();
        if(!items.length) return;
        const title=document.createElement('strong'); title.textContent='À corriger avant de continuer'; const list=document.createElement('ul'); summary.append(title,list);
        for(const [text,id] of items) { const li=document.createElement('li'), link=document.createElement('a'); link.href='#'+id; link.textContent=text; link.addEventListener('click',()=>{ const target=document.getElementById(id); for(let parent=target?.parentElement;parent;parent=parent.parentElement) if(parent.tagName==='DETAILS') parent.open=true; target?.focus(); }); li.append(link); list.append(li); }
        summary.scrollIntoView({block:'start',behavior:'smooth'});
    };
    new MutationObserver(sync).observe(main,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['data-tone','hidden']});
})();
(() => {
    const menu = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.querySelector('.backdrop');
    if (menu && sidebar) {
        const mobile = () => window.innerWidth <= 768;
        const sync = () => {
            const open = mobile() ? sidebar.classList.contains('open') : !document.body.classList.contains('menu-closed');
            menu.setAttribute('aria-expanded', String(open));
            sidebar.inert = !open;
            backdrop?.classList.toggle('visible', mobile() && open);
        };
        menu.addEventListener('click', event => {
            event.stopImmediatePropagation();
            if (mobile()) sidebar.classList.toggle('open');
            else document.body.classList.toggle('menu-closed');
            sync();
        }, true);
        const close = () => { sidebar.classList.remove('open'); if (!mobile()) document.body.classList.add('menu-closed'); sync(); menu.focus(); };
        backdrop?.addEventListener('click', event => { event.stopImmediatePropagation(); close(); }, true);
        document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
        window.addEventListener('resize', sync); sync();
    }
    document.querySelectorAll('.file-field input[type=file]').forEach(input => {
        if (!input.getAttribute('aria-label')) input.setAttribute('aria-label', input.closest('.file-field').querySelector('strong')?.textContent || 'Choisir un fichier');
    });
    // Garde le formulaire et ses fichiers disponibles lorsqu’un export échoue.
    document.querySelectorAll('form[action="extraire.php"]:not(#extraction-form),form[action="traitement_classeur.php"],form[action="generer_rapport.php"]').forEach(form => {
        const message = document.createElement('p'); message.className = 'status-box'; message.hidden = true; form.append(message);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (form.dataset.busy) return;
            const data = new FormData(form); const button = form.querySelector('button[type=submit]'); const label = button.textContent;
            form.dataset.busy = 'true'; button.disabled = true; button.textContent = 'Traitement en cours…'; message.hidden = false;
            AppUI.message(message, 'Votre fichier est en préparation. Merci de patienter.', 'loading');
            try { await AppUI.download(await fetch(form.action, { method: 'POST', body: data }), 'resultat'); AppUI.message(message, 'Votre fichier est prêt. Le téléchargement a été lancé.', 'success'); }
            catch (error) { AppUI.message(message, AppUI.error(error), 'error'); }
            finally { delete form.dataset.busy; button.disabled = false; button.textContent = label; }
        });
    });
    const archiveForm = document.querySelector('form[action="archive_store.php"]');
    if (archiveForm) {
        const notice = document.createElement('p'); notice.className = 'status-box'; notice.hidden = true; archiveForm.append(notice);
        archiveForm.addEventListener('submit', async event => {
            event.preventDefault(); if (archiveForm.dataset.busy) return;
            const data = new FormData(archiveForm); const button = archiveForm.querySelector('button[type=submit]');
            archiveForm.dataset.busy = 'true'; button.disabled = true; notice.hidden = false;
            AppUI.message(notice, 'Enregistrement du document…', 'loading');
            try {
                const response = await fetch(archiveForm.action, { method: 'POST', body: data });
                if (response.ok && response.redirected && new URL(response.url).searchParams.get('success') === '1') { window.location.assign(response.url); return; }
                const html = new DOMParser().parseFromString(await response.text(), 'text/html');
                throw new Error(html.querySelector('[data-user-error]')?.textContent || 'Le document n’a pas pu être archivé. Réessayez ou contactez votre administrateur.');
            } catch (error) { AppUI.message(notice, AppUI.error(error), 'error'); }
            finally { delete archiveForm.dataset.busy; button.disabled = false; }
        });
    }
})();
/* Navigation commune : chaque rubrique possedant un sous-menu est repliable. */
document.querySelectorAll('.nav-parent').forEach((parent) => {
    const submenu = parent.nextElementSibling;

    if (!submenu || !submenu.classList.contains('submenu')) {
        return;
    }

    parent.setAttribute('role', 'button');
    parent.setAttribute('tabindex', '0');
    parent.setAttribute('aria-expanded', 'true');
    parent.style.cursor = 'pointer';

    const arrow = document.createElement('span');
    arrow.textContent = 'v';
    arrow.style.cssText = 'float:right;font-size:16px;line-height:1;';
    parent.appendChild(arrow);

    const toggle = () => {
        const isOpen = parent.getAttribute('aria-expanded') === 'true';
        parent.setAttribute('aria-expanded', String(!isOpen));
        submenu.hidden = isOpen;
        parent.classList.toggle('is-collapsed', isOpen);
        arrow.textContent = isOpen ? '>' : 'v';
    };

    parent.addEventListener('click', toggle);
    parent.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggle();
        }
    });
});
