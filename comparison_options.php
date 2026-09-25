<details class="help-example"><summary>Options de comparaison des clés</summary>
<label class="field-label" for="key_ignore">Éléments à ignorer dans les clés</label>
<input type="text" id="key_ignore" name="key_ignore" maxlength="2000" placeholder="Exemple : .,-,/" aria-describedby="key-ignore-help">
<p id="key-ignore-help" class="help-copy">Séparez les caractères ou textes par des virgules. Les espaces sont toujours ignorés : 222444 et 222 444 correspondent. Avec un point dans ce champ, 222.444 correspond aussi. <?= !empty($comparisonGrouping) ? 'Les valeurs équivalentes sont réunies dans le même groupe.' : 'Cette règle s’applique aux deux fichiers et aux doublons.' ?> Les valeurs affichées dans vos fichiers restent intactes. Une clé devenue vide ne correspond à aucune ligne.</p>
</details>
