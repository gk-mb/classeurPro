# Application PHP - Extraction Excel

## Modules

- Extraction mono-feuille : GLOBAL et PARTIEL doivent avoir un seul onglet.
- Extraction multifeuilles : accepte toutes les combinaisons mono/multi et
  produit une sortie consolidee ou une sortie par feuille GLOBAL.
- Les deux extractions chargent automatiquement les colonnes de chaque fichier.
  Choisissez une cle dans GLOBAL et une autre dans PARTIEL ; leurs noms peuvent
  differer. La ligne d en-tetes est configurable par fichier (1 par defaut).
- Toutes les colonnes PARTIEL restent presentes. Les colonnes GLOBAL a ajouter
  et leur emplacement sont regroupes dans « Plus d'options », replie au depart.
  Importer les fichiers et choisir les cles suffit : toutes les colonnes GLOBAL
  sont ajoutees a la fin. En multifeuilles, le choix de sortie est en premier dans Plus d options ;
  la sortie par feuille est selectionnee par defaut.
  Les colonnes GLOBAL a ajouter
  sont toutes cochees par defaut ; vous pouvez en choisir certaines ou aucune.
  Les ajouts vont a la fin ou, ensemble, avant une colonne PARTIEL choisie.
  Un apercu affiche leur ordre. Les noms communs portent le suffixe (GLOBAL).
- En multifeuilles, toutes les colonnes sont reunies par nom et occurrence,
  quel que soit leur emplacement. Les colonnes absentes restent vides.
  Les cles vides et les lignes sans colonne cle vont dans Non trouves.
- Eclatement : cree une feuille par valeur d une colonne choisie.
- Regroupement : consolide toutes les feuilles d un classeur.
- Rapport documentaire : analyse Excel, DOCX ou PDF et produit un PDF ou DOCX.
- Fusion de fichiers : ajoute Y sous X, avec controle automatique avant export.
  Acces : fusion.php, depuis le menu Traitement > Fusion de fichiers.
  Excel XLS/XLSX : deux feuilles uniques peuvent avoir des noms differents.
  Sinon, seules les feuilles de meme nom (casse et espaces compris) sont
  fusionnees, quel que soit leur ordre. Les feuilles sans correspondante sont
  conservees sans modification dans X et ignorees dans Y. Sans feuille commune,
  aucune donnee n est ajoutee a X. Le controle affiche les feuilles ignorees.
  Sortie XLSX : toutes les lignes sont conservees, avec une ligne vide noire.
  Les colonnes restent en position ; les formules de Y deviennent des valeurs.
  Word DOCX et PDF peuvent etre combines, avec sortie DOCX ou PDF.
  Textes separes par un espace vide ; presence de tableaux : separation noire.
  Extraction du corps de document : images, en-tetes, pieds de page et mise en
  page originale non conserves. PDF : tableaux reconnus uniquement lorsque
  leurs colonnes sont separees par tabulations ; sinon conserves en texte.
  Les PDF sans texte extractible sont refuses (OCR prealable necessaire).
  Les anciens fichiers Word DOC doivent etre convertis en DOCX.
  Chaque requete renvoie les deux fichiers : les limites PHP upload_max_filesize
  et post_max_size doivent permettre respectivement 25 Mo et plus de 50 Mo.

Les cles de rapprochement se choisissent dans les listes propres a chaque
fichier. La comparaison ignore casse et espaces superflus.
Si les deux cles se nomment `Noms`, une correspondance incomplete est retenue seulement si elle est
unique : `MBATA KASA` peut correspondre a `MBATA KASA JOHN`.

## Enrichissement par cle

Module Enrichissement par cle (enrichissement.php) :
- Choisissez dans chaque fichier Excel la feuille et la ligne d en-tetes,
  puis la cle origine, la cle complement et la colonne a ajouter.
- La nouvelle colonne est ajoutee a droite par defaut. Le choix « Ou placer
  la nouvelle colonne ? » permet de l inserer avant une colonne de l origine.
  Dans ce cas, les colonnes suivantes et leurs references sont decalees.
  Les lignes gardent leur ordre. Les autres feuilles sont conservees.
- Pour les origines XLSX, les formules originales, leurs attributs et leurs
  liens externes sont preserves lors de l export. Les references sont adaptees
  en cas d insertion. Aucun lien externe n est actualise par ce traitement.
- Comparaison exacte apres normalisation de la casse et des espaces ; aucune
  correspondance approximative. Les cles vides sont considerees non trouvees.
- Les lignes non trouvees restent en place en rose clair et sont copiees dans
  la feuille Non trouvés. Les lignes entierement vides ne sont pas traitees.
- Les doublons du complement avec des valeurs differentes bloquent l export.
  Les doublons avec la meme valeur sont acceptes. Les formules ajoutees sont
  exportees en valeurs, avec leur format numerique. Sortie XLSX.
- Une feuille Non trouvés deja presente doit etre renommee avant traitement.
- Limite : 25 Mo par fichier et limite totale d import configuree dans PHP.

## Prerequis

- XAMPP avec PHP 8.2 ou plus recent
- Composer
- PhpSpreadsheet

## Installation

Dans ce dossier, executez :

    composer install

Ouvrez ensuite :

    http://localhost/extraction_excel/

## Resultats et securite

- Interface commune : couleurs du logo (violet #1A093E, bleu #009FE3,
  jaune #FFD600, rouge #E41513), groupes de menus pliables, affichage
  adapte aux mobiles et reperes de navigation au clavier.
- Enrichissement : parcours en trois etapes, aide illustree pour la ligne
  d en-tetes et recapitulatif des colonnes choisies avant generation.
- Les erreurs s affichent dans le formulaire et conservent les fichiers
  selectionnes pour permettre un nouvel essai. Les limites d import du
  serveur sont distinguees des erreurs de session.
- Tests interface dans Chrome local : node tests/ui_browser_test.cjs
- Guide utilisateur complet : guide.php, accessible depuis tous les modules.
  Le guide peut etre imprime ou enregistre en PDF depuis le navigateur.
- Tests des formules et de leurs liens : php tests/enrichissement_formula_test.php

- Les exports Excel contiennent une feuille `Non trouves` et, si necessaire,
  une feuille `Rapport`.
- Les colonnes du PARTIEL sont conservees et completees par les colonnes
  GLOBAL selectionnees, a la position choisie.
- Tests extraction : php tests/extraction_test.php et
  php tests/extraction_http_test.php (application locale accessible).
- Les fichiers envoyes sont limites a 25 Mo et controles avant lecture.
- Les exports sont proteges de l acces direct et supprimes automatiquement
  apres sept jours.

## Options de colonnes et de tableaux

- Enrichissement : une ou plusieurs colonnes du complement, toutes par defaut.
  Elles sont inserees ensemble ; les formules origine sont decalees du nombre
  de colonnes ajoutees. Les lignes non trouvees restent en rose et dans le rapport.
- Fusion : selection independante des colonnes de chaque feuille Excel ou tableau
  Word/PDF reconnu. Alignement dans leur ordre source ; cases vides si les nombres
  different. Les feuilles sans paire restent intactes dans X.
  Plus d options : ligne noire et en-tete de Y, actives par defaut.
  La premiere ligne est consideree comme en-tete. Une projection partielle de X
  conserve les valeurs et styles des cellules ; ses formules deviennent des valeurs.
- Extraction : option pour ajouter les non trouves de chaque feuille PARTIEL
  sous le resultat GLOBAL de meme nom, avec ligne noire et en-tetes. Une seule
  feuille GLOBAL ou une sortie unique recoit tous les non trouves. Sans feuille
  correspondante, ils restent seulement dans Non trouves. Aucun lot n est repete.
  Option desactivee par defaut ; la feuille Non trouves reste toujours disponible.
- Guide : guide.php partage son contenu avec guide_pdf.php pour le telechargement
  direct d un PDF complet, avec exemples et sommaire. Source : guide_content.php.
- Performances : les styles des cellules sont reutilises via spreadsheet_styles.php.
  Mesure locale sur 1500 lignes / 12 colonnes, generation XLSX comprise : extraction
  9,819 s avant / 3,902 s apres ; enrichissement 7,267 s avant / 2,632 s apres.
  Ces temps dependent du contenu et de la machine. Reproduire avec
  php tests/performance_benchmark.php ; controle PDF : php tests/guide_pdf_test.php.

## Nouveaux outils

- extraction_hybride.php : selection des feuilles, association facultative par nom,
  recherche globale ou par paire ; resultat groupe par feuille PARTIEL.
- ajout_multi.php : enrichissement sur plusieurs feuilles, cles par nom de colonne,
  selection des feuilles et colonnes, association facultative, sortie mono/multi.
- Ajouter colonne et Ajout multi : controle des doublons apres choix des cles ;
  choix obligatoire supprimer (premiere) ou colorer (premiere / plus remplie).
  Rapport Doublons et protection des formules apres suppression et insertion.
- impression_mono.php / impression_multi.php : bordures et ajustements sur les
  colonnes choisies ; toutes les donnees restent imprimees. Zone complete,
  titres repetes, A4/A3, portrait/paysage, une page en largeur.
- Les erreurs sont reprises au-dessus du formulaire et pres des champs concernes.

Comparaison des clés : espaces ignorés par défaut ; éléments à ignorer séparés par une virgule dans les options (exemple : .,-,/). Même règle pour les correspondances, doublons et groupes, sans changer les cellules.
Interface : réglages secondaires dans Plus d’options.
Impression : répétition des en-têtes au choix. Zone complète pour toutes les feuilles du résultat ; sélection des feuilles et colonnes pour la mise en forme.

Sélections multiples : cases à cocher, compteur et boutons Tout cocher / Tout décocher. Guide : chemins complets du menu pour chaque outil, disponibles aussi dans le PDF.
Sorties Excel : largeurs calculées sur le contenu affiché, sans recalcul global ; textes longs avec retour à la ligne. Impression : largeur plafonnée plus compacte et zone recalculée après ajustement. Tableaux PDF : largeur adaptée au contenu ; Word utilise son ajustement automatique.
