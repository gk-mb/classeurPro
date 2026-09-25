<section class="mapping-summary" id="duplicates-panel" aria-labelledby="duplicates-title">
    <h2 id="duplicates-title">Vérification des doublons</h2>
    <p id="duplicates-status" class="status-box" role="status" aria-live="polite">Choisissez vos fichiers et leurs clés pour vérifier les lignes répétées.</p>
    <div id="duplicates-examples"></div>
    <div id="duplicates-choices" hidden>
        <label class="field-label" for="duplicate_mode">Que faire des lignes ayant la même clé ?</label>
        <select id="duplicate_mode" name="duplicate_mode"><option value="">Choisir une action…</option><option value="delete">Supprimer les répétitions et garder la première ligne</option><option value="color">Garder les lignes et signaler les doublons en jaune</option></select>
        <div id="duplicate-occurrence-wrap" hidden><label class="field-label" for="duplicate_occurrence">Quelle ligne utiliser pour trouver les valeurs ?</label><select id="duplicate_occurrence" name="duplicate_occurrence"><option value="">Choisir la ligne…</option><option value="first">La première dans l’ordre des feuilles et des lignes</option><option value="complete">La plus remplie (le moins de cases vides)</option></select></div>
        <p class="help-copy">Les fichiers importés ne sont pas modifiés. En cas d’égalité, la première ligne est retenue. Les lignes répétées du complément sont détaillées dans une feuille « Doublons ». Les clés répétées d’origine sont signalées en jaune ; les lignes non trouvées restent en rose.</p>
    </div>
</section>
