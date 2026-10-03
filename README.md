# football
## Import d'une édition (`import:edition`)

Importe la saison finale d'un championnat (classement, play-off de championnat,
finale du barrage de promotion/relégation, renommages de clubs, champion)
directement dans les tables `edition`, `standing`, `game`, `note`,
`competition_entry`, `team_name` et `winner`, en combinant deux sources
complémentaires plutôt qu'une seule :

- **claudionicoletti.eu** (`source_url`, obligatoire) : classement général
  (position, J/G/N/P/BP/BC/Pts), matches de play-off de championnat et de
  barrage promotion/relégation, renommages de clubs.
- **Wikipedia** (`wikipedia_url`, optionnel) : détail domicile/extérieur
  (hw/hd/hl/hgf/hga/aw/ad/al/agf/aga) via la grille "Home \ Away" publiée
  sur la page de la saison.

      php bin/console import:edition "tournament_id=13 country=ALBANIA season=2023-24 lang=sq iso=AL source_url=https://www.claudionicoletti.eu/soccer-statistics/campionati-nazionali-in-europa/all-final-tables/albania-league-final-tables/alb-2021-30/ wikipedia_url=https://en.wikipedia.org/wiki/2023-24_Kategoria_Superiore"

- `tournament_id` : id de `tournament`
- `country` : intitulé pays tel qu'il apparaît dans la source (ex. `ALBANIA`)
- `season` : libellé de saison source (ex. `2023-24`), converti en `2023/24` pour `edition.name`
- `lang` / `iso` : langue et code pays ISO utilisés pour les nouveaux `team_name` (renommages)
- `source_url` : URL de la page (ou directement du `.htm` embarqué) de claudionicoletti.eu
- `wikipedia_url` : URL de l'article Wikipedia de la saison (optionnel — sans lui, les colonnes home/away restent vides, comme avant)

### Format "Final Four"

Certaines saisons remplacent le play-off de championnat à élimination
directe par un mini-championnat en round-robin entre les meilleurs du
classement général (ex. Albanie à partir de 2025/26 : "Regular season" à
10 équipes, puis "Final Four" entre les 4 premiers, qui décide directement
du titre — le vainqueur du Final Four n'est pas forcément le leader de la
saison régulière).

L'import détecte ce bloc ("Final Four" dans la source) et l'importe sous un
stage dédié `Final Four`, séparé du `Final Table` de la saison régulière.
Point important : `teams_number` sur les lignes `standing` n'est PAS le
nombre de lignes du stage concerné — c'est le nombre total d'équipes de
l'édition (celui de la saison régulière), répété à l'identique sur tous
les stages. C'est la même convention déjà utilisée ailleurs dans la base
pour un partage "Championship group"/"Relegation group" (ex. édition 8993 :
12 équipes en "First round", puis `teams_number` reste à 12 même sur les
groupes de 6 qui suivent).

La ligne de totaux en bas du tableau "Final Four" (une ligne sans position
ni nom d'équipe) est explicitement écartée : une fois les cellules vides
filtrées, elle peut avoir autant de colonnes qu'une vraie ligne d'équipe,
mais son "nom" n'est jamais qu'un nombre — contrairement à un vrai nom de
club.

### Pourquoi pas Soccerway pour le home/away ?

Testé et abandonné : Soccerway (infra Flashscore) ne renvoie aucune donnée de
classement dans le HTML brut, même avec les cookies de session. Le tableau
(overall/home/away) est calculé côté client par du JavaScript qui interroge
une API GraphQL interne (`2020.ds.lsapp.eu/pq_graphql`) avec des "persisted
queries" à hash opaque, sans documentation publique. Un simple appel HTTP
(Guzzle) ne peut pas récupérer ces chiffres ; il faudrait un vrai navigateur
(Chrome headless), une dépendance beaucoup plus lourde. Les pages Wikipedia,
elles, contiennent leurs tableaux directement dans le HTML servi par le
serveur — d'où le choix.

La grille "Home \ Away" de Wikipedia donne, pour chaque équipe, le score de
chacun de ses matches à domicile (les autres équipes étant en colonnes, sous
forme d'abréviation). Certaines saisons publient deux grilles ("First half
of season" / "Second half of season", chacune un aller-retour complet) :
`import:edition` additionne toutes les grilles trouvées sur la page, donc
ça fonctionne aussi bien avec une seule qu'avec plusieurs. Le calcul a été
vérifié sur la saison 2023/24 albanaise : les totaux domicile+extérieur
recalculés correspondent exactement au classement officiel publié sur la
même page.

Les noms d'équipes utilisés par Wikipedia (souvent des libellés courts, ex.
`Dinamo`, `Tirana`) sont rapprochés des noms déjà résolus côté
claudionicoletti.eu pour cette édition (ex. `KS Dinamo Tiranë`, `KF Tirana`)
par inclusion de sous-chaîne après normalisation, avec un repli Levenshtein
en dernier recours — jamais par correspondance stricte de la chaîne entière,
qui échouerait presque toujours vu la différence de longueur des libellés.
Une équipe qui ne peut pas être rapprochée d'un côté ou de l'autre voit
simplement ses colonnes home/away laissées vides ; ça n'interrompt jamais
l'import (contrairement à un nom introuvable côté classement général, qui
lui bloque tout — voir "Nouvelles équipes" ci-dessous).

### Nouvelles équipes

L'import ne crée jamais de `team`/`team_name` de sa propre initiative pour
un nom qu'il ne parvient pas à rapprocher avec confiance d'une équipe
existante (recherche exacte, puis floue par distance de Levenshtein).

Quand la commande tourne dans un terminal interactif, un nom non résolu
déclenche une question à l'écran plutôt qu'un arrêt immédiat :

```
"AF Elbasani (2021)" n'a pas pu être rapproché automatiquement. Comment le traiter ?
  [n] Nouvelle équipe (jamais vue dans la base)
  [r] Renommage d'une équipe existante (un des candidats ci-dessus)
  [s] Ignorer — laisser non résolu (l'import s'arrêtera avant d'écrire quoi que ce soit)
Choix [n/r/s]: n
Nom à enregistrer en base (Entrée pour garder "AF Elbasani (2021)") :
Nom officiel (official_name, optionnel, Entrée pour laisser vide) :
Nom complet (full_name, optionnel, Entrée pour laisser vide) :
```

- `n` demande d'abord le nom à enregistrer (Entrée = garder tel quel le nom
  scrapé, utile quand il contient une annotation comme "(2021)" qu'on ne
  veut pas voir en base), puis `official_name` et `full_name` (tous deux
  optionnels, Entrée = laisser `NULL`), puis crée la `team` (niveau 3,
  rattachée à la fédération de l'édition, `team.name` **et**
  `team_name.name` renseignés avec le nom choisi — à la différence des
  autres clubs déjà en base, qui ont tous `team.name = NULL` et portent
  leur nom uniquement via `team_name`) et son premier `team_name`
  (`version = 1`, avec `official_name`/`full_name` si fournis). La ville
  indiquée est cherchée dans `geoname` (nom exact) ; si elle n'existe pas,
  on peut réessayer avec un autre nom ou fournir un `geoname_id` à la
  main.
- `r` propose la liste des candidats déjà rapprochés par distance de
  Levenshtein ; le choix fait tourner la version de `team_name` sur le
  `team_id` existant, exactement comme un renommage annoncé par la source
  (note `"Ancien nom => Nouveau nom"` incluse).
- `s`, une entrée standard fermée (script, CI, pipe), ou tout environnement
  non interactif retombent sur le comportement d'origine : la commande
  s'arrête **avant de valider quoi que ce soit** et affiche la liste des
  noms en échec avec leurs candidats les plus proches.

Tout — création/renommage interactif compris — fait partie de la même
transaction que le reste de l'import : si un autre nom reste non résolu, ou
si une étape plus tardive échoue, les équipes créées ou renommées pendant
cette tentative sont annulées avec le reste.

Pour créer un club à la main plutôt qu'au prompt, la marche à suivre reste
possible :

1. Créer la ligne `team` (et son `team_name` avec `version = 1`,
   `last_version = 1`) à la main.
2. Relancer `import:edition` : le nom sera alors résolu normalement.

Un renommage de club annoncé par la source (ligne `Ancien nom => Nouveau nom`)
est traité différemment : il ne crée pas de nouvelle équipe, il fait
tourner la version de `team_name` sur le même `team_id`. Le nom **actuel**
de la saison importée reste l'ancien nom (le changement ne prend effet qu'à
partir de la saison suivante sur claudionicoletti.eu) ; une note
`"Ancien nom => Nouveau nom"` est ajoutée dans `note`.
