<!-- origem: d1ee6a6209d68a22db0ba092a88f18e01d2948ef -->
<!-- parte 1 -->
# ⌚ Relojoeiro — Relógios 2

🌐 [Português](README.md) · [English](README.en.md) · [中文](README.zh-CN.md) · [हिन्दी](README.hi.md) · [Español](README.es.md) · **Français**

> **La rotation intelligente d'une collection de montres.** Chaque jour, il dit quelle montre va au poignet, rappelle de
> remonter, de charger, de mettre au soleil et de changer la pile, et fait porter toute la collection, pas seulement la
> préférée.

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)
![SQLite, MySQL/MariaDB, PostgreSQL ou Firebird](https://img.shields.io/badge/base%20de%20donn%C3%A9es-SQLite%20%7C%20MySQL%20%7C%20PostgreSQL%20%7C%20Firebird-336791)
![sans dépendances](https://img.shields.io/badge/d%C3%A9pendances-aucune-2ea44f)
![API REST](https://img.shields.io/badge/API-REST%20%2B%20JSON%2FXML-0366d6)
[![licence](https://img.shields.io/badge/licence-code%20source%20disponible-orange)](#licença)

![La page Aujourd'hui : la montre du jour, quoi faire aujourd'hui, le planning des prochains jours et le panneau d'une montre](docs/telas/hoje.png)

<sub>La page **Aujourd'hui** avec une collection de démonstration de 8 montres (des modèles courants, six semaines de port
saisies par l'API) : celle du jour (la Casio, au poignet depuis 7 h), quoi faire aujourd'hui, le planning intelligent des
prochains jours et, à droite, le panneau de la Tissot, ouvert à côté (dans la Configuration, il peut s'ouvrir en fenêtre
flottante, par-dessus la page).</sub>

| | |
|---|---|
| **[Pourquoi il existe](#por-que-isso-existe)** · [L'idée centrale](#a-ideia-central-o-sistema-não-sabe-nada-de-relógios) · [Comment ça marche](#como-funciona) · [Une journée avec lui](#um-dia-com-o-relógios-2) | **[Essayez en 1 minute](#experimente-em-1-minuto)** · [Les écrans](#as-telas) · [L'API](#a-api-uma-porta-só-para-tudo) · [Installation](#instalação) |
| [Questions fréquentes](#perguntas-frequentes) · [Comment c'est à l'intérieur](#como-é-por-dentro) | [Comment contribuer](#como-contribuir) · [Licence](#licença) |

---

## Pourquoi cela existe {#por-que-isso-existe}

Qui collectionne les montres connaît la scène :

- **La préférée va au poignet tous les jours**, et celle que vous avez achetée avec tant d'amour dort dans le tiroir depuis
  deux mois.
- **L'automatique s'arrête** parce que personne ne l'a portée le week-end, et vous voilà à régler à nouveau l'heure, la date
  et la phase de lune.
- **La solaire se décharge dans le noir** de la boîte, sans que vous le remarquiez.
- **La pile s'épuise sans prévenir**, justement sur la montre que vous vouliez porter aujourd'hui.
- **La smartwatch se réveille sans batterie** le jour où c'était son tour.
- **La révision de la mécanique** (« tous les 5 ans ») et **la garantie** (« elle expire en mars ? ») restent dans la mémoire,
  c'est-à-dire nulle part.

Un tableur règle la moitié du problème : il se souvient, mais il ne décide pas. **Relógios 2** décide. Il sait depuis combien
de temps chaque montre est au repos, quelle charge elle a maintenant, à quel point vous l'aimez et combien elle a été portée
par rapport aux autres. Avec cela, il construit le plan des prochains jours, ou des deux prochaines années, avec les rappels
déjà au bon endroit.

Le matin arrive un message sur Telegram : *« Aujourd'hui : Seiko 5. Remonter l'Orient (la réserve s'épuise à 15 h). »*
Le soir, un autre : *« Demain : Xiaomi Band. Charger avant de dormir : elle atteint 20 % demain à 14 h. »*
Et Google Agenda contient le changement de pile de la Casio en novembre et la révision de la Tissot en 2027.

<!-- parte 2 -->
## Essayez en 1 minute {#experimente-em-1-minuto}

Il suffit de PHP 8.1+ avec l'extension `sqlite3` : pas de serveur web ni de serveur de base de données.

```sh
git clone https://github.com/Challado/relojoeiro.git && cd relojoeiro
cp config.exemplo.php config.php
```

Dans le `config.php`, changez deux lignes pour utiliser SQLite (le fichier de la base est placé à côté du dossier) :

```php
define("DB_TIPO", "sqlite");
define("DB_ARQUIVO", __DIR__ . "/../relogios.sqlite");
```

```sh
php instalar.php --lingua=fr  # la structure et l'ensemble initial (en français)
php criar_usuario.php vous    # demande le mot de passe
php -S localhost:8000         # et ouvrez http://localhost:8000
```

Enregistrez quelques montres dans **Aujourd'hui → Nouvelle montre**. Pour construire le planning sans attendre le cron, lancez
`php cron.php --forcar`.

> Le serveur intégré de PHP (`php -S`) sert seulement à essayer : il livre n'importe quel fichier du dossier, y compris le
> `config.php`. Pour un vrai usage, suivez l'[Installation](#instalação).

---

<!-- parte 3 -->
## L'API : une seule porte pour tout {#a-api-uma-porta-só-para-tudo}

Le `api.php` est le **seul back-end**. Les pages ne sont que l'écran : le JavaScript de chacune appelle l'API et construit le
HTML, sans faire de calcul (les résumés, les pourcentages, les totaux et les situations, comme le résumé du Plan et la
situation du cron, arrivent prêts de l'API ; l'écran ne garde que ce qui lui revient : filtrer, trier et additionner ce que
les filtres du tableau laissent visible). C'est pourquoi **tout ce que fait l'écran, un script peut le faire aussi** :
automatisations, raccourcis sur le téléphone, intégration avec Home Assistant, un widget.

L'API fait aussi ce qui vient avant et autour des écrans : elle **installe la base** (`recurso=instalacao`), **crée le premier
utilisateur** (`recurso=usuarios`, avec le jeton), **importe l'ancien système** (`recurso=importacao`) et fait la **connexion
des pages** (`recurso=entrar` : les pages ne vérifient rien, elles livrent seulement le squelette ; qui arrive sans connexion
est envoyé à l'API, qui demande l'utilisateur et le mot de passe et le renvoie à la page). Les scripts en ligne de commande
(`instalar.php`, `criar_usuario.php`, `importar.php`) sont des raccourcis : ils appellent les mêmes opérations de l'API.

En dehors de l'API, il n'y a que le **cron** (`cron.php`, l'horloge du système : il construit le plan, ouvre et ferme la
session du jour, envoie les messages, synchronise l'agenda, déclenche les événements et s'occupe des copies des documents),
qui utilise les mêmes fonctions que l'API ; ce qu'on peut demander sur le moment a une action dans l'API (`testar_manha`,
`testar_noite`, `sincronizar`, `resortear`...), et ce qu'il a fait apparaît dans `recurso=cron`.

**Authentification :** le jeton (`X-Api-Token: ...` ou `?token=...`) ou la connexion du site (HTTP Basic).
**Format :** JSON par défaut, ou XML avec `formato=xml`. **Langue :** `lingua=fr` (ou l'en-tête `Accept-Language`) : les
messages, l'aide et la reconstruction sortent dans cette langue.

```sh
# tout ce qui est dans le système, sans les photos
curl -u utilisateur:motdepasse "http://serveur/relojoeiro/api.php?foto=nao"

# ce que montre la page Aujourd'hui
curl -H "X-Api-Token: $TOKEN" "http://serveur/relojoeiro/api.php?recurso=hoje"

# les montres qui s'arrêtent dans les prochaines 24 heures, seulement le nom et quand
curl -u utilisateur:motdepasse -g "http://serveur/relojoeiro/api.php?recurso=autonomia&f[relogios][acaba_em_segundos][ate]=86400&mostrar[relogios]=nome,acaba_em_datacomtz"

# saisir un relevé de charge (et mesurer la consommation)
curl -u utilisateur:motdepasse -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 http://serveur/relojoeiro/api.php

# mettre au soleil maintenant... et retirer plus tard
curl -u utilisateur:motdepasse -d recurso=lancamento -d acao=iniciar  -d relogio_id=4 -d tipo=sol http://serveur/relojoeiro/api.php
curl -u utilisateur:motdepasse -d recurso=lancamento -d acao=encerrar -d relogio_id=4 -d tipo=sol http://serveur/relojoeiro/api.php

# tester une formule sur deux montres, sans enregistrer
curl -u utilisateur:motdepasse -G "http://serveur/relojoeiro/api.php" -d recurso=calcular --data-urlencode "expressao=energia * 2" -d relogio=10,12
```

**Consultations** (`GET`, avec `recurso=`) :

| Ressource | Renvoie |
|---|---|
| *(aucune)* | tout ce qui est enregistré : l'arbre, les champs, les types de saisie, les formules, les alertes, les montres (avec les valeurs, les formules calculées, les alertes, les saisies, la prévision, la photo et les données des documents), les catégories des documents, les modes, le plan, la configuration, les événements, l'agenda, les critères, le cron, les utilisateurs (avec le hash du mot de passe : le mot de passe n'existe pas dans le système) et les migrations. `foto=nao` laisse les photos de côté. |
| `hoje` | ce que montre la page Aujourd'hui : la montre du jour, les alertes, les prochains jours, les modes, le tableau de la collection et ses totaux (combien de montres, disponibles, en usage et le prix payé) |
| `ficha` | ce que montre le panneau d'une montre (`relogio=<id>` ; sans lui, seulement la fiche d'une nouvelle montre) : maintenant, charge, note, prévision, consommation de la batterie, combien de documents dans chaque catégorie, marquages récents, fiche |
| `proximos` | les Prochains jours de la page Aujourd'hui, une page (`pagina=`, `por_pagina=` ; sans lui, le « Afficher jusqu'à » de la Configuration), avec les jours libres au milieu |
| `rodizio_manual` | la Rotation manuelle : les jours d'une période (`de=`, `ate=`) 7 par 7 (`pagina=`), chacun avec ce que le plan a maintenant et le choix de départ du sélecteur (la montre choisie à la main, `livre` ou `auto` : Décider pour moi), les montres disponibles et le critère du Décider pour moi |
| `plano` | le plan enregistré, jour par jour, avec la raison de chaque choix, le résumé de la période tel que le montre la page Plan (combien de jours a chaque montre, le pourcentage, le prochain jour, combien à la main, qui est resté sans jour) et le `dia_a_dia` une page à la fois (`pagina=`, `por_pagina=`, `relogio=`). `de=` et `ate=` (AAAA-MM-JJ ; sans eux, tout le plan) ou `dias=N` (les N prochains jours ; `dias=todos`, d'aujourd'hui à la fin) |
| `avisos` | les alertes de toutes les montres, de la plus urgente à la plus lointaine |
| `previsao` | la prévision de la batterie des montres avec relevé de charge (`relogio=` un id ou plusieurs séparés par des virgules ; sans lui, toutes) |
| `autonomia` | combien de temps tient chaque montre, en secondes, épuré pour les systèmes extérieurs (`relogio=` un id ou plusieurs ; sans lui, toutes) |
| `historico` | la chronologie d'une montre, avec le temps dans chaque état. `relogio=` (un id ou plusieurs ; `relogio_id` vaut aussi ; sans lui, toutes), `de=` et `ate=` (date, ou date et heure : la période qui croise l'intervalle entre), `estado=` (`rodizio`, `pulso`, `repouso`, `marca` ou un type de session comme `winder` et `sol` ; un ou plusieurs ; `linha_estado` vaut aussi), `ordem=` (`desc`, par défaut, ou `asc`), `limite=` (par défaut 100, maximum 1000) et `pagina=` |
| `criterios` | les ensembles de critères et la note de chaque montre avec le calcul |
| `eventos`, `agenda` | les événements personnalisés ; ce qui doit être dans Google Agenda |
| `config`, `cron` | la configuration (avec les messages, l'aperçu et la situation du cron : jamais exécuté, arrêté ou en marche) ; les exécutions du cron, avec le résumé de la période. Dans le `cron` : `de=` et `ate=` (date, ou date et heure), `situacao=` (`atividade`, par défaut ; `erro`, `nada` ou `todas`), `busca=` (texte dans le journal), `limite=` (par défaut 100, maximum 1000) et `pagina=` ; chacun vaut aussi avec `cron_` devant (`cron_de`...) |
| `arvore`, `cadastros` | les groupes ; tout ce que montre la page Définitions |
| `calcular` | le résultat d'une formule (`expressao=`) sur les montres (`relogio=` un id ou plusieurs ; sans lui, toutes), sans enregistrer, avec les parties du calcul ; formule mal écrite : 400 avec les erreurs |
| `foto` | la photo d'une montre (`relogio=<id>`) : l'image, pas du JSON |
| `documentos` | les documents d'une montre (`relogio=<id>` ; sans lui, de toutes), avec les catégories, le résumé de la NF-e de chaque XML, le dossier et la limite |
| `documento` | le fichier d'un document (`id=<id>`) : pour afficher (image, vidéo, audio, PDF, texte) ou pour télécharger (le reste, ou avec `baixar=1`) ; `mini=1` est la vignette de la photo. Il sert des morceaux (Range), pour que la vidéo avance |
| `usuarios`, `migracoes` | les identifiants, chacun avec le hash du mot de passe (pour migrer avec le même mot de passe : voir [Les utilisateurs et les mots de passe](#os-usuários-e-as-senhas)) ; les migrations qui restent à appliquer |
| `instalacao` | la situation de l'installation : la base, si elle est vide, installée et à jour, combien d'utilisateurs et la prochaine étape. Répond aussi avec la base vide, avec le jeton |
| `backup` | la sauvegarde de la base : si elle est activée, si le dossier convient, combien sont gardées, la dernière et les copies du dossier ; `arquivo=` en télécharge une |
| `importacao` | ce qu'utilise l'importation de l'ancien système : le serveur de l'ancienne base (sans le mot de passe), si PHP a MySQL et si cette base a déjà des montres |
| `entrar` | la connexion des pages : sans connexion, 401 (le navigateur demande l'utilisateur et le mot de passe) ; avec connexion, retourne à la page de `volta=` (302) ou dit qui s'est connecté |
| `ajuda` | toute la documentation : chaque consultation, chaque écriture, chaque champ de chaque réponse et les fonctions du moteur |
| `manual` (ou `ajuda&parte=manual`) | le texte de la page **Aide** (les sections de ce README pour les utilisateurs : de l'idée centrale aux questions fréquentes), en markdown, entier et section par section, avec le titre, le niveau et l'ancre de chacune, et déjà en HTML avec le sommaire (la page Aide se construit à partir de lui) |
| `reconstrucao` (ou `ajuda&parte=reconstrucao`) | le guide pour **réécrire tout le système à partir de zéro** (voir [Réécrire le système à partir de zéro](#reescrever-o-sistema-do-zero)) |
| `codigo` (ou `ajuda&parte=codigo`) | **le code du système tel qu'il est maintenant**, lu sur le disque au moment même : chaque fichier avec la couche, ce que c'est, le langage, la taille, les lignes, le SHA-256 et la date, et ce qui est laissé de côté exprès (`nao_mostrados`). `arquivo=<nom>` apporte le contenu (l'image en base64 ; pour une page, aussi le HTML que reçoit le navigateur) ; `cru=1` apporte le fichier brut, sans JSON, pour le lire dans le navigateur ; `conteudo=1` les apporte tous (voir [Réécrire le système à partir de zéro](#reescrever-o-sistema-do-zero)) |

**Écritures** (`POST`, avec `recurso=` et `acao=`), toutes avec les mêmes validations et messages que l'écran :

| Ressource | Actions |
|---|---|
| `relogio` | `salvar` (créer ou modifier), `excluir` (avec les documents), `foto`, `remover_foto` |
| `documentos` | `enviar` (un ou plusieurs fichiers, avec la catégorie, le titre, la date, la description et, pour une photo, la vignette), `alterar`, `excluir` |
| `documento_categorias` | `nova`, `alterar`, `excluir` (seulement sans documents) |
| `lancamento` | `lancar` (remontage, charge, pile...), `iniciar` et `encerrar` (mettre et retirer du poignet, du remontoir, du soleil, du chargeur), `alternar` (ferme la session si elle est ouverte, l'ouvre si elle est fermée : un seul bouton sur le téléphone), `periodo` (une période déjà passée), `alterar`, `excluir`. Dans toutes, `relogio_id=hoje` est la montre du jour |
| `rodizio` | `usando` (je porte celle-ci aujourd'hui), `trocar_dia` (la montre d'un jour), `periodo` (les montres d'une période, dans l'ordre : `de`, `ate`, `relogio_ids[]`, `dias_cada`), `periodo_sortear` (efface ce que contient la période et refait le tirage), `periodo_livre` (efface et laisse les jours libres, sans tirage), `manual` (la Rotation manuelle : `de`, `ate`, `dias[AAAA-MM-JJ]` = l'id de la montre, `auto` ou `livre`), `apagar_tudo` (toute la programmation à partir de demain disparaît ; `hoje=1` : à partir d'aujourd'hui ; le mode reconstruit le plan), `resortear`, `resortear_hoje`, `proxima_semana`, `modo` (changer le mode) |
| `modos` | `salvar`, `ativar`, `excluir` |
| `arvore` | `novo`, `renomear`, `mover`, `ordem`, `excluir` (groupes) et `relogios` (le groupe de chaque montre) |
| `campos`, `avisos`, `lancamento_tipos` | `novo`, `alterar`, `excluir` (et `ordem` dans les champs) |
| `formulas` | `nova`, `alterar`, `excluir` |
| `criterios` | `conjunto_criar`, `conjunto_excluir`, `param_novo`, `param_excluir`, `param_pesos`, `sub_novo`, `sub_excluir`, `sub_medida`, `sub_mover`, `sub_pesos`, `faixas`, `ordem`, `restaurar` |
| `config` | `salvar`, `evento_salvar`, `evento_excluir`, `testar_manha`, `testar_noite`, `teste_agenda_criar`, `teste_agenda_remover`, `sincronizar` |
| `usuarios` | `salvar` (avec le `senha`, ou avec le `senha_hash` d'un autre système ; le premier utilisateur, avec le jeton), `excluir` |
| `migracoes` | `aplicar` |
| `instalacao` | `instalar` (seulement sur une base vide, avec le jeton) |
| `backup` | `fazer` (une sauvegarde maintenant), `restaurar` (`arquivo=` : ramène la base à cette copie, en gardant d'abord une copie de ce qui existe maintenant) |
| `importacao` | `importar` (`banco=` le nom de l'ancienne base ; `substituir=1` quand cette base a déjà des montres ; arrêté en cours de route, rien n'est enregistré) |

**Les réponses d'erreur** disent la raison : `{"erro": "..."}` dans les consultations, et `{"ok": false, "mensagem", "erros": [...]}`
(une erreur par raison) dans une écriture refusée :

| Code | Quand |
|---|---|
| `400` | écriture refusée par les validations (les erreurs disent pourquoi), ou une formule mal écrite dans le `calcular` |
| `302` | `recurso=entrar` avec la bonne connexion et `volta=` : va à la page |
| `401` | sans le jeton ni la connexion du site, ou avec eux erronés ; sans aucun utilisateur (ou avec la base vide), la réponse dit quoi faire (`sem_usuarios` et `como`) |
| `404` | ressource inconnue (la réponse apporte la liste des ressources), ou la montre, la photo, le document (ou son fichier) ou le `README.md` (dans le `manual`) qui n'existe pas, ou un fichier qui ne sort pas par le `codigo` (avec `o_que_ver` pour ce qui est laissé de côté exprès, comme le `config.php`) |
| `409` | la base a refusé l'écriture : un enregistrement qu'un autre utilise encore, une valeur répétée, ou une valeur hors des règles de validation de la base (le nom de la règle vient avec) |
| `413` | l'envoi a dépassé la limite du PHP du serveur (`post_max_size`) : un fichier trop grand |
| `500` | système arrêté (l'`API_TOKEN` ou le `FUSO` du `config.php` invalide) ou une erreur interne |
| `503` | base pas à jour (seul `recurso=migracoes` répond, et l'écriture `migracoes`/`aplicar` ; et le `manual` et le `codigo`, qui n'utilisent pas la base), base vide (avant l'installation seul `instalacao` répond, et le `manual` et le `codigo`) ou base hors service |

### Les utilisateurs et les mots de passe {#os-usuários-e-as-senhas}

Le mot de passe n'est gardé nulle part : seul son **hash** l'est, fait par le `password_hash` de PHP (bcrypt). Le hash a 60
caractères, `$2y$12$...` : le `$2y$`, le coût (12 veut dire 2¹² tours), le sel (22 caractères, tiré au sort pour chaque mot
de passe) et le résultat (31). Le hash ne redevient pas un mot de passe : à la connexion, le système calcule le bcrypt du mot
de passe tapé avec le sel et le coût qui sont dans le hash et compare (`password_verify`). Le même mot de passe donne des
hashs différents à chaque fois, et tous correspondent.

**Pour emmener les utilisateurs vers un autre système**, emportez l'identifiant et le `senha_hash` de chacun
(`recurso=usuarios`) et, là-bas, vérifiez le mot de passe tapé contre le hash avec le bcrypt du langage : chacun se connecte
avec le même mot de passe que toujours. `$2y$` (PHP) et `$2b$` (les autres langages) sont le même calcul ; si la bibliothèque
refuse le `$2y$`, changez le début en `$2b$`. **Pour faire venir des utilisateurs d'un autre système**, envoyez le hash de
là-bas : `recurso=usuarios`, `acao=salvar`, `login` et `senha_hash` (bcrypt `$2y$`, `$2b$` ou `$2a$`, ou argon2).

| Langage | Vérifier le mot de passe | Faire le hash |
|---|---|---|
| PHP | `password_verify($senha, $hash)` | `password_hash($senha, PASSWORD_DEFAULT)` |
| Python (`pip install bcrypt`) | `bcrypt.checkpw(senha.encode(), hash.encode())` | `bcrypt.hashpw(senha.encode(), bcrypt.gensalt()).decode()` |
| Node (`npm install bcryptjs`) | `require("bcryptjs").compareSync(senha, hash)` | `require("bcryptjs").hashSync(senha, 12)` |
| Java (Spring Security) | `new BCryptPasswordEncoder().matches(senha, hash)` | `new BCryptPasswordEncoder().encode(senha)` |
| C# (BCrypt.Net-Next) | `BCrypt.Net.BCrypt.Verify(senha, hash)` | `BCrypt.Net.BCrypt.HashPassword(senha)` |

```sh
# le catalogue : identifiant et hash de chacun
curl -u utilisateur:motdepasse "http://serveur/relojoeiro/api.php?recurso=usuarios"

# faire venir un utilisateur d'un autre système, avec le mot de passe qu'il avait déjà
curl -u utilisateur:motdepasse --data-urlencode recurso=usuarios --data-urlencode acao=salvar --data-urlencode login=maria \
     --data-urlencode 'senha_hash=$2b$12$...' http://serveur/relojoeiro/api.php
```

**Les filtres génériques** marchent sur n'importe quelle liste de n'importe quelle réponse :

| Paramètre | Fait |
|---|---|
| `incluir=relogios,plano` / `excluir=motor` | seulement ces parties, ou toutes sauf celles-ci |
| `f[lista][campo]=v` | égal à `v` (ou à l'une de plusieurs : `v1,v2`) |
| `f[lista][campo][de]=` / `[ate]=` | intervalle de nombres ou de dates |
| `f[lista][campo][contem]=` / `[diferente]=` / `[vazio]=1` | texte, exclusion, vide |
| `busca[lista]=texto` | le texte dans n'importe quel champ |
| `ordem[lista]=-campo` | trie (le `-` inverse) |
| `limite[lista]=20&pagina[lista]=2` | pagination |
| `mostrar[lista]=id,nome` | seulement ces champs |

Les champs imbriqués marchent avec un point, comme `formulas.energia.valor`, `campos.loja.valor` et `compra.valor`. Les
textes sont comparés sans distinguer majuscules et accents.

📖 **La documentation complète** (chaque consultation, chaque écriture avec un exemple et le dictionnaire de tous les champs
des réponses) est dans l'en-tête du [`api.php`](api.php) et dans l'API elle-même : `api.php?recurso=ajuda` (dans la langue de
la requête). Le dictionnaire a une seule source, celui de l'aide ; celui de l'en-tête est généré à partir de lui
(`php testes/dicionario.php --gravar`).

---

## Installation {#instalação}

**Prérequis :** PHP 8.1+ avec `curl` et `openssl` (celui-ci seulement pour Google Agenda), un serveur web (nginx ou Apache), le
cron et **une de ces bases de données**, chacune par son extension PHP :

| Base | Extension PHP | Version | Quand la choisir |
|---|---|---|---|
| **SQLite** | `sqlite3` | 3.35+ (testé en 3.53) | la plus simple : un seul fichier, sans serveur de base. Largement suffisant pour une collection personnelle, même sur un Raspberry Pi |
| **MySQL / MariaDB** | `mysqli` | avec utf8mb4 (testé sur MariaDB 11.4) | votre hébergement l'a déjà, ou vous l'utilisez déjà |
| **PostgreSQL** | `pgsql` | 12+, avec ICU, par défaut (testé en 18) | vous avez déjà un Postgres qui tourne |
| **Firebird** | `pdo_firebird` | 3.0+ (testé en 3.0.11, avec PHP 8.4) | vous avez déjà un Firebird qui tourne |

Le système se comporte de la même façon sur les quatre. C'est vérifié par le test de parité ([`testes/`](testes/)), qui
exécute le même scénario sur les quatre bases et compare tout ce que renvoie l'API.

```sh
# 1. le code
git clone https://github.com/Challado/relojoeiro.git /var/www/relojoeiro
cd /var/www/relojoeiro

# 2. la configuration : la base (DB_TIPO), le jeton de l'API, le fuseau, les messages, le dossier des documents
cp config.exemplo.php config.php
nano config.php
#    le dossier des documents (DOCUMENTOS_PASTA), hors du dossier publié, accessible en écriture à l'utilisateur de PHP :
#    mkdir -p /var/lib/relogios2/documentos && chown www-data /var/lib/relogios2/documentos

# 3. la base vide (seulement sur MySQL, Postgres et Firebird ; SQLite crée le fichier tout seul)
#    MySQL:    mysql -u root -p -e "CREATE DATABASE relogios2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
#    Postgres: createdb -U postgres relogios2
#    Firebird: echo "CREATE DATABASE 'localhost:/var/lib/firebird/3.0/data/relogios2.fdb' USER 'SYSDBA' PASSWORD '...'
#                    PAGE_SIZE 16384 DEFAULT CHARACTER SET UTF8;" | isql-fb -q

# 4. l'installation : la structure et l'ensemble initial, traduits pour la base du config.php
php instalar.php --lingua=fr
#    l'ensemble initial sort dans cette langue, qui devient celle par défaut de l'installation (sans --lingua : portugais)
#    ou par l'API, avec le jeton du config.php (la langue : lingua=fr, comme dans toute requête) :
#    curl -H "X-Api-Token: $TOKEN" -d recurso=instalacao -d acao=instalar -d lingua=fr http://serveur/relojoeiro/api.php

# 5. le premier utilisateur (le mot de passe est demandé sans s'afficher)
php criar_usuario.php votre_identifiant
#    ou par l'API, avec le jeton :
#    curl -H "X-Api-Token: $TOKEN" -d recurso=usuarios -d acao=salvar -d login=votre_identifiant -d senha=... http://serveur/relojoeiro/api.php

# 6. le cron, chaque minute (il est muet : il n'écrit rien et n'envoie pas de courriel)
( crontab -l; echo "* * * * * php /var/www/relojoeiro/cron.php" ) | crontab -
```

Le `cron.php` a deux options, pour le lancer à la main : `--forcar` exécute maintenant le passage du matin et celui du soir,
même s'ils ont déjà eu lieu aujourd'hui (il construit le planning, envoie les messages et synchronise l'agenda, s'ils sont
activés), et `-v` affiche à l'écran le journal qu'il enregistre (le même que la page Exécutions du cron). Ex. :
`php cron.php --forcar -v`.

Sur SQLite, mettez le fichier de la base (`DB_ARQUIVO`) **hors du dossier que publie le serveur web**, dans un dossier où
l'utilisateur de PHP peut écrire (SQLite crée à côté les fichiers `-wal` et `-shm`).

Sur Firebird, la base doit être créée en **UTF8** (le `DEFAULT CHARACTER SET UTF8` ci-dessus). PHP a besoin de l'extension
`pdo_firebird` : sur Debian et Ubuntu, le paquet `php8.4-interbase` (son ancien nom) et le client Firebird (`libfbclient2`),
qui vient avec ; dans le `php.ini`, `extension=pdo_firebird`. Le serveur, le paquet `firebird3.0-server` (ou l'installateur
du site de Firebird). Dans le `config.php`, `DB_NOME` est le chemin du fichier `.fdb` **sur le serveur de la base** (ou un
alias du `databases.conf`), et `DB_USUARIO`/`DB_SENHA` un utilisateur Firebird (le `SYSDBA` ou un utilisateur créé pour le
système).

**7. Le serveur web.** Par le web, seules les pages, le `api.php` et ce qu'elles chargent (`estilo.css`, les `.js`, les images)
peuvent s'ouvrir. Tout le reste est bloqué : le noyau (`lib.php`, `banco.php`, `operacoes.php`), le `config.php`, le cron, les
scripts en ligne de commande, les tests, les fichiers `.sql`, `.json`, `.md` et `.sqlite`, et tout ce qui commence par un
point, y compris le dossier `.git`, qui vient avec le `git clone`. Le projet apporte cela prêt pour les deux serveurs :

- **Apache :** le [`.htaccess`](.htaccess) est déjà dans le dossier et marche tout seul. Le dossier a seulement besoin de
  `AllowOverride All` dans la configuration d'Apache, le réglage par défaut de presque tout hébergement ; sans lui, le
  `.htaccess` est ignoré et rien n'est bloqué. Il fait aussi marcher la connexion du site quand PHP tourne en FastCGI
  (PHP-FPM, la plupart des hébergements), cas où Apache ne transmettrait pas le mot de passe à PHP.
- **nginx :** copiez le bloc du [`nginx-relogios.conf`](nginx-relogios.conf) dans le `server` de votre site (ajustez
  `/relojoeiro/` et l'adresse de PHP-FPM). Il apporte aussi le `client_max_body_size 512m`, pour l'envoi des documents (voir
  [La taille des envois](#o-tamanho-dos-envios-os-documentos)).

Pour vérifier, ouvrez `https://votre-serveur/relojoeiro/config.php` et `https://votre-serveur/relojoeiro/.git/config` : les deux
doivent donner **403**.

**8. Ouvrez-le dans le navigateur** et connectez-vous avec l'utilisateur créé. L'ensemble initial est déjà là : enregistrez vos
montres dans **Aujourd'hui → Nouvelle montre**, choisissez le groupe de chacune et remplissez les champs.

### Le `config.php` {#o-configphp}

| Constante | Pour quoi |
|---|---|
| `DB_TIPO` | la base : `mysql` (par défaut, vaut pour MariaDB), `pgsql`, `sqlite` ou `firebird` |
| `DB_HOST`, `DB_PORTA`, `DB_NOME`, `DB_USUARIO`, `DB_SENHA` | le serveur de la base (MySQL, Postgres et Firebird) ; `DB_PORTA` 0 est le port par défaut. Sur Firebird, `DB_NOME` est le chemin du fichier `.fdb` sur le serveur (ex. : `/var/lib/firebird/3.0/data/relogios2.fdb`) ou un alias du `databases.conf`, et un `DB_HOST` vide ouvre le fichier directement, sans serveur (le Firebird embarqué) |
| `DB_ARQUIVO` | le fichier de la base (SQLite), hors du dossier publié |
| `API_TOKEN` | **obligatoire**, avec 10 caractères ou plus. Sans lui, tout le système s'arrête et dit pourquoi. |
| `FUSO` | le fuseau horaire, comme `America/Sao_Paulo`. Vide ou absent : celui de PHP (`date.timezone` dans le php.ini). Il vaut pour tout, y compris pour les dates que la base enregistre |
| `MSG_ENDPOINT`, `MSG_DESTINATARIO`, `MSG_TITULO` | les messages (voir ci-dessous). Sans l'adresse, rien n'est envoyé. |
| `DOCUMENTOS_PASTA` | le dossier des fichiers des documents (le manuel, la facture, les photos, les vidéos), **hors du dossier publié**, accessible en écriture à l'utilisateur de PHP. Sans lui, la page Documents signale qu'il manque et n'accepte pas d'envois. Ex. : `define("DOCUMENTOS_PASTA", "/var/lib/relogios2/documentos");` |
| `DOCUMENTOS_COPIA_BANCO` | facultatif : la sauvegarde des documents dans la base, pour tout le système. `true` : tout fichier va aussi dans la base ; `false` : aucun n'y va ; sans elle : chaque montre et chaque fichier décident. Voir [Les documents de la montre](#os-documentos-do-relógio). |
| `BACKUP_PASTA`, `BACKUP_MANTER`, `BACKUP_DOCUMENTOS` | facultatifs : la sauvegarde de la base (voir [Sauvegarde](#cópia-de-segurança)). Le dossier, **hors du dossier publié** (sans lui, pas de copie automatique) ; combien sont gardées (par défaut 7) ; et si elle emporte le contenu des documents gardé dans la base (par défaut : non). |
| `BACKUP_ATIVO` | facultatif : `false` désactive la sauvegarde : pas de copie automatique, pas de **Faire une copie maintenant** et pas de cadre **Sauvegarde** dans la Configuration (ni l'avertissement que le dossier manque). Sans elle : activée |
| `DOCUMENTOS_LIMITE` | facultatif : le plus grand document accepté, en octets. Sans elle, 100 Mo (`104857600`) ; `0` ou `-1`, pas de limite du système. `MANUAL_LIMITE`, l'ancien nom, vaut encore quand elle n'existe pas. Les limites de PHP et de nginx s'appliquent toujours (voir [La taille des envois](#o-tamanho-dos-envios-os-documentos)). Ex. : `define("DOCUMENTOS_LIMITE", 524288000);` pour 500 Mo |
| `ANTIGO_HOST`, `ANTIGO_PORTA`, `ANTIGO_USUARIO`, `ANTIGO_SENHA` | facultatifs, seulement pour le `importar.php` avec ce système sur Postgres, SQLite ou Firebird : où se trouve le MySQL de l'ancien système |
| `ALEATORIO_RANDOMORG_URL`, `ALEATORIO_ANU_URL` | facultatifs, seulement pour les tests : les adresses des sources des nombres aléatoires (`https://www.random.org/integers/` et `https://qrng.anu.edu.au/API/jsonI.php`). Changées, les tirages demandent les nombres à un serveur de test |
| `GOOGLE_TOKEN_URL`, `GOOGLE_API_URL` | facultatifs, seulement pour les tests : les adresses de Google qu'utilise l'agenda (celle du jeton, `https://oauth2.googleapis.com/token`, et celle de l'API, `https://www.googleapis.com/calendar/v3`). Changées, la synchronisation de l'agenda parle à un serveur de test au lieu de Google |
| `TEXTOS_ESTRITO` | facultatif, pour qui traduit : `true` fait qu'une traduction manquante s'arrête sur une erreur (voir [Les langues](#os-idiomas)) |

Le système lit le `config.php` de son dossier. Une variable d'environnement du serveur, `RELOGIOS_CONFIG`, désigne un autre
fichier à la place : c'est ce qu'utilise le test de parité (`testes/paridade.sh`, avec le
[`testes/config.teste.php`](testes/config.teste.php)), pour tourner sans toucher au vrai `config.php`. Sur une installation
normale, ne la définissez pas.

### Les messages par Telegram {#mensagens-pelo-telegram}

Le système ne parle pas directement à l'API de Telegram : il appelle **une adresse HTTP à vous** (`MSG_ENDPOINT`) avec
`?destinatario=...&titulo=...&mensagem=...` et considère le message envoyé si la réponse est 2xx. Cela vous laisse utiliser
votre bot, un webhook, n8n, Home Assistant ou n'importe quel relais de messages. Les longs messages sont découpés en parties.
Dans la **Configuration** se trouvent les boutons **Envoyer maintenant** (matin et soir) pour tester.

### Google Agenda {#google-agenda}

1. Dans Google Cloud, créez un **compte de service** et téléchargez la clé JSON.
2. Gardez la clé **sur le serveur, hors du dossier public et hors du dépôt** (le `.gitignore` bloque déjà les noms courants).
3. Partagez votre agenda avec l'adresse du compte de service, avec la permission de modifier les événements.
4. Dans la **Configuration**, indiquez l'ID de l'agenda et le chemin de la clé, activez l'agenda et cliquez sur
   **Synchroniser maintenant**.

Le système crée, met à jour et supprime seulement les événements qu'il a créés lui-même, sans doublons. La routine (charger,
remonter, soleil) entre dans la fenêtre d'anticipation, et l'entretien (pile, révision, garantie) entre à n'importe quelle
date.

### La taille des envois (les documents) {#o-tamanho-dos-envios-os-documentos}

Chaque document monte en un envoi (plusieurs fichiers choisis ensemble montent un par un), et trois limites du serveur peuvent
refuser un gros fichier avant le système. Le système accepte jusqu'à **100 Mo** par fichier ; le `DOCUMENTOS_LIMITE` du
`config.php` change cela (en octets ; `0` ou `-1` : pas de limite du système). Pour les vidéos de téléphone, pensez à environ
500 Mo :

| Où | La limite | Par défaut | Pour 500 Mo |
|---|---|---|---|
| `php.ini` | `upload_max_filesize` (le fichier) et `post_max_size` (l'envoi entier) | 2M et 8M | `upload_max_filesize = 500M` et `post_max_size = 512M` |
| nginx | `client_max_body_size` | 1m | `512m` (déjà dans le [`nginx-relogios.conf`](nginx-relogios.conf)) |
| `config.php` | `DOCUMENTOS_LIMITE` | 100 Mo | `524288000` |

Le fichier va directement dans le dossier des documents ; la copie dans la base va en morceaux de 4 Mo, lus un à la fois : le
`memory_limit` de PHP et le `max_allowed_packet` de MySQL (16 Mo ou plus) ne limitent pas la taille du fichier. La page
Documents montre la limite qui s'applique vraiment (celle du système, ou moins à cause du `php.ini` ; sans aucune limite, « de
toute taille »). Un fichier au-dessus est refusé avec le message de la taille ; un envoi au-dessus du `post_max_size` revient
avec le code `413` et la limite du serveur. Sur Apache, il n'y a pas de limite à toucher, à part le `php.ini`.

### Les mises à jour de la base {#atualizações-do-banco}

Après la mise à jour du code, s'il y a une nouvelle migration (`migracao_v*.sql`), l'API répond `503` et la page
**Configuration** montre le bouton pour l'appliquer. Le cron s'arrête aussi et note la raison, au lieu de tourner à moitié. Sur
une nouvelle installation, le `schema.sql` apporte déjà toutes les migrations.

Le `schema.sql` et chaque migration sont **un seul fichier pour les quatre bases** : la structure (`CREATE TABLE`,
`ALTER TABLE`) est écrite dans le dialecte de MySQL, et le [`banco.php`](banco.php) la traduit pour Postgres, SQLite et
Firebird (`AUTO_INCREMENT`, `ENUM`, `TINYINT`, `DATETIME`, les `INDEX` dans la table, le `AFTER` et le `MODIFY COLUMN`, qui sur
SQLite refait la table). Les données (`INSERT`, `UPDATE`) vont en SQL commun aux quatre : pas de `GROUP_CONCAT`, `IF()` ni
`FIND_IN_SET`. `INSERT IGNORE`, `REPLACE INTO`, `NOW()`, `LEAST`, `GREATEST`, `LENGTH`, `DATE()`, `<=>`, `LIMIT`/`OFFSET`, le
`SELECT` sans `FROM`, l'`INSERT` de plusieurs lignes et `CONCAT` sont permis (le `CONCAT` sans argument NULL : sur Postgres le
NULL devient du texte vide, sur MySQL le résultat devient NULL). Les migrations v2 à v8 datent d'avant et n'existent que pour
mettre à jour d'anciennes bases MySQL : une base Postgres, SQLite ou Firebird naît déjà dans la version actuelle.

Deux commandes qui n'existent ainsi dans aucune base, le `banco.php` les comprend et les fait à la manière de chacune :

| Commande | Ce qu'elle fait |
|---|---|
| `ALTER TABLE t ALTER FOREIGN KEY (coluna) REFERENCES outra(id) ON DELETE CASCADE` (ou `SET NULL`) | remplace la clé étrangère de la colonne (ou la crée, s'il n'y en avait pas). Sur MySQL, Postgres et Firebird, cherche dans le catalogue le nom de l'ancienne clé, la supprime et crée la nouvelle (sur Firebird, dans une transaction) ; sur SQLite, refait la table. |
| `ALTER TABLE t DROP COLUMN coluna` | supprime la colonne avec sa clé étrangère et ses index (SQLite refait la table ; Firebird supprime aussi d'abord les règles qui utilisent la colonne). |
| `ALTER TABLE t ADD CONSTRAINT nome CHECK (condição)` | la règle de validation, avec un nom ; s'il en existe déjà une avec ce nom, elle est remplacée. Les lignes existantes doivent respecter la nouvelle règle, sinon la base refuse et la règle d'avant reste (sur Postgres et Firebird, dans une transaction ; sur MySQL, la nouvelle entre d'abord sous un nom provisoire ; sur SQLite, la table est refaite dans une transaction). |

Et, pour qu'une migration arrêtée en cours de route puisse tourner à nouveau avec le même bouton : l'`ADD COLUMN` d'une colonne
qui existe déjà et l'`ADD INDEX` avec le nom d'un index qui existe déjà ne font rien.

Une migration peut aussi avoir une **étape en PHP**, pour ce que le SQL commun aux quatre ne fait pas (la v16 découpe le texte
des options d'un champ, une par ligne, en lignes de la table `campo_opcao` ; la v20, sur MySQL/MariaDB, passe en
`utf8mb4_unicode_ci` les tables créées dans une autre collation). Elle tourne juste après le SQL, par le bouton de la
Configuration, et aussi dans le `instalar.php`.

### Les différences entre les bases que vous pouvez remarquer {#diferenças-entre-os-bancos-que-você-pode-notar}

- **L'ordre des textes sur MySQL/MariaDB :** toute table naît en `utf8mb4_unicode_ci`, quel que soit le réglage par défaut de
  la base. MariaDB vient avec `utf8mb4_general_ci`, qui trie autrement que les autres bases (il met `dias_desde_compra` avant
  `dias_de_carga`) ; une ancienne installation créée ainsi est convertie par la migration v20.
- **Majuscules et accents :** comme sur MySQL, « Relógio » et « RELOGIO » sont le même nom, dans la recherche, dans le tri et à
  la connexion. Sur Postgres, cela vient d'une collation ICU (`ci`) que crée le `instalar.php`. Sur SQLite, d'une fonction de
  PHP ; c'est pourquoi, dans un programme extérieur (le `sqlite3` en ligne de commande, DB Browser), trier ou comparer ces
  colonnes donne l'erreur « no such collation sequence: ci ». Lire et exporter marchent normalement. Sur Firebird, de la
  collation `UNICODE_CI_AI`, sur toutes les colonnes de texte.
- **Sur Firebird :**
  - les textes longs (`TEXT` dans le `schema.sql` : les formules, les messages, le journal du cron) vont jusqu'à **8191
    caractères** (sur MySQL, 65535 octets), car ils deviennent `VARCHAR(8191)` (le `BLOB` de texte revient vide comme nul en
    PHP) ;
  - les noms d'index et de règle de plus de 31 caractères gardent seulement un morceau du md5 du nom entier à la fin, et la
    table `no` est un mot réservé : dans un programme extérieur (`isql-fb`, FlameRobin), écrivez `"NO"`, entre guillemets et
    en majuscules ;
  - la clé unique de plusieurs colonnes où l'une peut rester vide (la `canal_aviso`) devient un index unique sur une
    expression, car sur Firebird deux lignes avec la même valeur et l'autre colonne vide comptent comme répétées (sur MySQL,
    non) ;
  - l'heure du `NOW()` vient de PHP (Firebird n'a pas de fuseau par connexion), dans le `FUSO` du `config.php`, comme pour
    les autres.
- **L'ordre des colonnes** d'une migration avec `AFTER` ne vaut que sur MySQL : sur les autres, la nouvelle colonne va à la fin
  de la table (seul change l'ordre des clés dans le JSON de l'API).
- **Les ids** peuvent sauter des numéros différents sur chaque base (MySQL réserve des ids par bloc dans certains
  `INSERT ... SELECT`).

### En venant de l'ancien système {#vindo-do-sistema-anterior}

`php importar.php <ancienne_base>` (ou, par l'API, `recurso=importacao`, `acao=importar`, `banco=<ancienne_base>`) apporte
l'arbre, les montres, les photos, les utilisateurs, les champs et tout l'historique (converti en saisies), en une seule
transaction : s'il s'arrête en cours de route, rien n'est enregistré. Avec `--substituir` (dans l'API, `substituir=1`), il
efface d'abord ce qui existe déjà dans cette base. Le [`PORTE.md`](PORTE.md) note, point par point, comment chaque
comportement de l'ancien système a été porté.

---

<!-- parte 4 -->
## Comment c'est à l'intérieur {#como-é-por-dentro}

- **PHP 8.1+ pur, avec SQLite, MySQL/MariaDB, PostgreSQL ou Firebird.** Sans framework, sans Composer et sans dépendances, pour
  tourner sur n'importe quel hébergement avec PHP. Chaque base par son extension PHP (`sqlite3`, `mysqli`, `pgsql`,
  `pdo_firebird`) ; le SQL du système est écrit une seule fois et le [`banco.php`](banco.php) traduit ce qui change d'une base
  à l'autre.
- **Un moteur de formules maison**, avec analyse syntaxique, fonctions d'historique, versions par groupe et dépendances entre
  formules.
- **Simulation :** le planning et les prévisions simulent des saisies futures et recalculent les formules jour par jour, sans
  rien enregistrer.
- **Front-end en HTML et JavaScript purs.** Chaque page PHP livre seulement le squelette (elle ne vérifie même pas la
  connexion : qui arrive sans connexion est envoyé à la connexion de l'API) ; son `.js` appelle l'API et construit l'écran.
  Les scripts portent la date du fichier dans l'URL, pour que le cache du navigateur ne serve pas une ancienne version.
- **Cron muet et traçable :** il tourne chaque minute, n'écrit rien en sortie et note chaque exécution dans la base (sans
  activité, 7 jours ; avec activité ou erreur, 1 an). Une erreur fatale va dans la base ou, si même la base ne répond pas,
  dans un fichier temporaire.
- **Documents à l'épreuve des pertes :** chaque fichier reste dans le dossier et, si on le demande (le système, la montre ou le
  fichier), entier, dans la base ; le cron recrée ce qui disparaît, copie ce qui manque et nettoie ce qui est en trop (voir
  [Les documents de la montre](#os-documentos-do-relógio)).
- **Une base qui se défend :** relationnelle et normalisée, avec des clés étrangères et des règles de validation (`CHECK`,
  `NOT NULL`, `UNIQUE`) sur les quatre bases : une écriture erronée est refusée par la base elle-même (voir
  [Le modèle de la base](#o-modelo-do-banco)).
- **À l'épreuve des erreurs :** sans jeton valide ou avec la base pas à jour, le système s'arrête et explique la raison, au
  lieu de tourner à moitié.
- **Six langues :** tout texte passe par `t()`, avec le portugais comme clé et un fichier par langue (voir
  [Les langues](#os-idiomas)).

### Le modèle de la base {#o-modelo-do-banco}

La base est relationnelle et normalisée (depuis la migration v16) : chaque chose dans une table, chaque lien entre tables une
**clé étrangère**, et aucune liste gardée comme texte dans un champ. Supprimer directement dans la base ne laisse rien en
suspens :

| Le lien | À la suppression du parent | Où |
|---|---|---|
| obligatoire (la ligne n'existe pas sans le parent) | **disparaît avec lui** (`ON DELETE CASCADE`) | un groupe emporte les groupes qu'il contient, les montres, les champs, les types de saisie, les formules et ses alertes ; une montre emporte les saisies, les valeurs, les photos, les documents, le plan et ses propres critères ; une saisie emporte la mesure de consommation ; et ainsi de suite |
| facultatif (la colonne accepte le vide) | **le lien se défait** (`ON DELETE SET NULL`) | la montre d'un événement personnalisé, le groupe et la montre fixe d'un bloc de mode, le bloc d'un jour du plan, le type de saisie qui résout une alerte, la montre d'un accessoire (l'accessoire devient indépendant) |

Par l'écran (**Groupes**, Supprimer), supprimer un groupe passe toujours d'abord ce qui lui appartenait (les groupes qu'il
contient, les montres, les champs, les types, les formules et les alertes) au groupe du dessus ; la cascade vaut pour qui
supprime directement dans la base.

Les listes sont devenues des tables : les jours d'un bloc de mode (`modo_bloco_dia`) et d'un événement hebdomadaire
(`evento_dia`), les types de fichier qu'accepte une catégorie de documents (`documento_categoria_aceita`), les options d'un
champ de liste (`campo_opcao`) et, pour chaque canal et chaque type d'alerte, s'il part, s'il a son propre message et son texte
(`canal_aviso`). Le mode en usage est la colonne `ativo` du mode, et la mesure de consommation arrive à la montre par la saisie
qui l'a fermée. La table `config` ne garde que des valeurs isolées (horaires, seuils, marques).

Trois références restent **par le nom**, exprès : les formules et les critères citent les champs et les formules par
l'identifiant (c'est le texte que vous écrivez, comme `HORAS("pulso"; 30)`), et le `canal_aviso` cite l'alerte par
l'identifiant, car une alerte peut avoir plusieurs versions (une par groupe) et le canal vaut pour toutes. Quand la dernière
version d'une alerte est supprimée, le système supprime avec elle ses lignes dans le `canal_aviso`.

**La base valide ce qu'elle garde** (depuis la migration v17), avec les outils du SQL standard, et pas seulement le système :
une écriture erronée est refusée d'où qu'elle vienne (l'écran, l'API, quelqu'un qui touche directement la base). L'API répond
`409`, avec le nom de la règle enfreinte dans le détail.

| Outil | Ce qu'il garantit |
|---|---|
| `NOT NULL` | ce qui est obligatoire est rempli (l'origine de chaque saisie et de chaque jour du plan, par exemple) |
| `CHECK` | chaque valeur dans ce qui est valable. Il y a 54 règles, chacune avec un nom (`ck_<table>_<quoi>`) |
| listes fermées (`ENUM`, qui sur les autres bases devient `CHECK ... IN`) | les valeurs qu'accepte une colonne : le format du type de saisie, la répétition de l'événement, l'origine de la saisie (`manual`, `rodizio`, `importado`) et du jour du plan (`sorteio`, `manual`)... |
| `UNIQUE` | ce qui ne peut pas se répéter : l'identifiant d'un champ, d'un type, d'une catégorie ; le fichier d'un document ; **au plus un mode en usage** |
| `FOREIGN KEY` | les liens (ci-dessus) |

Les règles `CHECK`, par sujet :

| Sujet | La base refuse |
|---|---|
| Textes | nom, identifiant, titre, texte d'alerte, formule et clé de la configuration vides ou seulement des espaces |
| Marques oui/non | toute valeur autre que 0 et 1 (disponible, active, exclusive, mesure la consommation, utilisée, envoie, copie dans la base, seulement pour les désirées...) |
| Montres | une situation dans la collection hors de la liste (`colecao`, `desejado`, `emprestado`, `vendido`, `arquivado`) ; une montre disponible hors de la collection |
| Types de saisie | session exclusive hors du format session ; « mesure la consommation » hors du format avec valeur ; « se ferme à » hors du format session |
| Saisies et mesures | fin avant le début ; consommation mesurée (taux) hors de 0 à 100 % ; heures négatives |
| Alertes | anticipation hors de 0 à 3650 jours ; heures simulées dans le planning hors de 0 à 48 |
| Critères | poids hors de 0 à 100 % ; note hors de 0 à 100 ; un ensemble de critères d'un groupe **et** d'une montre en même temps ; une tranche qui n'est ni numérique (avec « de ») ni de catégorie ; « de » négatif ; « à » qui ne dépasse pas le « de » |
| Modes de rotation | planning intelligent hors de 7 à 730 jours ; jour de la semaine hors de 1 à 7 |
| Événements personnalisés | ce que la répétition n'utilise pas (le jour du mois hors du mensuel, l'intervalle hors de « tous les N jours », la date hors de « une fois » et de « tous les N jours ») et l'absence de ce qu'elle utilise ; jour du mois hors de 1 à 31 ; intervalle hors de 1 à 3650 jours ; jour de la semaine hors de 1 à 7 |
| Accessoires | nom vide ; un type hors de la liste (`pulseira`, `caixa`, `estojo`, `ferramenta`, `carregador`, `peca`, `outro`) |
| Documents | taille zéro ; SHA-256 qui n'a pas 64 caractères ; partie de la copie dans la base en dessous de -1 (la -1 est la vignette) |
| Photo de la montre | un type qui n'est pas une image |
| Exécutions du cron | fin avant le début ; durée négative |

**Au plus un mode en usage :** la marque `ativo` du mode vaut 1 pour celui en usage et vide (`NULL`) pour les autres, et la
colonne a une clé unique. Le SQL standard permet de répéter le vide dans une clé unique, mais pas le 1 : deux modes en usage,
la base refuse.

Deux règles sont restées de côté à cause de MySQL 8, qui n'accepte pas de `CHECK` sur la colonne `id` (auto-incrément) ni sur
une colonne de clé étrangère avec `ON DELETE SET NULL` : « un groupe n'est pas son propre parent » (le système le vérifie, et
vérifie aussi les cycles plus grands, qu'aucun `CHECK` n'atteint) et celles des liens facultatifs.

### Fichiers {#arquivos}

| Fichier | Ce que c'est |
|---|---|
| [`api.php`](api.php) | l'API, la porte de toute lecture et écriture (l'authentification, le format, l'ordre des ressources, les filtres, la réponse), avec la documentation complète dans l'en-tête |
| [`api/`](api/) | les grosses ressources de l'API, un fichier chacune (`hoje.php`, `ficha.php`, `configuracao.php`, `desejos.php`, `estatisticas.php`, `hora_certa.php`, `passaporte.php`, `galeria.php`, `ajuda.php`...), plus ses fonctions (`funcoes.php`) et les écritures (`escrita.php`) : elles tournent dans le `api.php`, et ouvertes directement elles ne font rien (le `.htaccess` et le `nginx-relogios.conf` bloquent le dossier) |
| [`lib.php`](lib.php) | le noyau : connexion, arbre, moteur de formules, alertes, critères, rotation, planning, messages, agenda |
| [`banco.php`](banco.php) | la base : la connexion à MySQL, Postgres, SQLite ou Firebird, et la traduction du SQL de l'une à l'autre |
| [`operacoes.php`](operacoes.php) | les règles de chaque écriture : validations et messages |
| [`cron.php`](cron.php) | le plan, la session du jour, les passages du matin et du soir, les événements, l'agenda et l'entretien des documents |
| `index.php`, `plano.php`, `desejos.php`, `estatisticas.php`, `hora.php`, `acessorios.php`, `galeria.php`, `passaporte.php`, `ficha.php`, `documentos.php`, `historico.php`, `configuracao.php`, `criterios.php`, `grupos.php`, `cadastros.php`, `execucoes.php`, `usuarios.php` | les pages (seulement le squelette) |
| `ajuda.php` | la page d'aide : seulement le squelette ; le `ajuda.js` demande le texte à l'API (`recurso=manual` : les sections du README pour les utilisateurs, déjà en HTML, converties dans le `lib.php`) |
| [`pagina.php`](pagina.php) | le haut avec le menu et la fin des pages (la connexion appartient à l'API : `recurso=entrar`) |
| `api.js`, `hoje.js`, `painel.js`, `tabela.js`, `foto.js`, ... | les écrans, construits dans le navigateur à partir de l'API |
| [`estilo.css`](estilo.css) | l'apparence |
| [`textos/`](textos/) | les langues : `funcoes.php` (la fonction `t()`, la liste des langues, la langue de la requête, les dates et les nombres de chaque langue) et un fichier par langue (`en.php`, `zh-CN.php`, `hi.php`, `es.php`, `fr.php` : le texte en portugais => la traduction, par partie) ; ouverts directement, ils ne font rien |
| [`schema.sql`](schema.sql) | la structure de la base et l'ensemble initial (groupes, champs, formules, alertes, modes, critères) |
| `migracao_v2.sql` … `migracao_v24.sql` | les migrations, appliquées par la page Configuration |
| [`config.exemplo.php`](config.exemplo.php) | le modèle du `config.php` |
| [`instalar.php`](instalar.php) | installe le `schema.sql` sur la base du `config.php`, n'importe laquelle (la même chose que `recurso=instalacao`) |
| [`criar_usuario.php`](criar_usuario.php) | crée un utilisateur ou change le mot de passe, en ligne de commande (la même chose que `recurso=usuarios`, `acao=salvar`) |
| [`importar.php`](importar.php) | importe les données de l'ancien système (la même chose que `recurso=importacao`) |
| [`reconstrucao.php`](reconstrucao.php) | le guide pour réécrire le système à partir de zéro (le `recurso=reconstrucao` de l'API) |
| [`.htaccess`](.htaccess) | la protection des fichiers internes sur Apache (et la connexion du site avec PHP en FastCGI) |
| [`nginx-relogios.conf`](nginx-relogios.conf) | le bloc nginx qui protège les fichiers internes |
| [`testes/`](testes/) | le test de parité : le même scénario par l'API sur chaque base, et le comparateur des réponses ; la vérification de la mise en page de tous les écrans, du téléphone à l'ordinateur (`layout.cjs` ; `LINGUA=en` vérifie dans une langue) ; celle des dictionnaires des langues (`textos.php`) ; et l'assemblage du README de chaque langue (`readme.php`) |
| [`docs/telas/`](docs/telas/) | les captures d'écran de ce README |
| `README.md`, `README.en.md`, `README.zh-CN.md`, `README.hi.md`, `README.es.md`, `README.fr.md` | ce README : l'original, en portugais, et les autres langues. Ils ne se modifient pas à la main : le [`testes/readme.php`](testes/readme.php) assemble chacun en joignant le manuel traduit (`textos/manual/<langue>.md`, les sections que montre l'Aide) et le reste traduit (`textos/readme/<langue>.md` : le début, Essayez, l'API, l'Installation, Comment c'est à l'intérieur, Comment contribuer et la Licence) |
| [`PORTE.md`](PORTE.md) | le registre du portage de la version précédente |

---

## Comment contribuer {#como-contribuir}

Les améliorations sont les bienvenues, et le chemin passe toujours par ce dépôt :

1. Pour un gros changement, **ouvrez d'abord une issue** et dites ce que vous comptez faire : cela évite un travail qui
   n'entrera pas.
2. Faites un fork sur GitHub, créez une branche et faites le changement. Le fork sert seulement à préparer la proposition (voir
   la [licence](#licença)).
3. **Suivez la manière du code :** PHP et JavaScript purs, sans dépendances, commentaires et messages en portugais (les
   traductions vont dans `textos/`). Ce qui est connaissance sur les montres va dans les définitions (`schema.sql`), pas dans le
   code.
4. Vous avez changé la base ? Créez une `migracao_vN.sql` et enregistrez-la dans `$MIGRACOES` dans le `lib.php` : la structure
   dans le dialecte de MySQL, les données en SQL commun aux quatre bases (voir [Les mises à jour de la base](#atualizações-do-banco)).
5. Vous avez changé l'API ? **Expliquez chaque nouveau champ** dans le dictionnaire de l'aide : le `"campos"` du
   `recurso=ajuda`, dans le `api/ajuda.php` (la section de la ressource, avec le chemin du champ comme clé). C'est la source
   unique : le dictionnaire de l'en-tête du `api.php` est généré à partir de lui, avec `php testes/dicionario.php --gravar`
   (n'écrivez pas dans l'en-tête à la main).
6. **Lancez le test de parité** ([`testes/`](testes/)) sur les quatre bases avant d'envoyer (GitHub le lance aussi, à chaque
   push sur `main` : le test de parité, la documentation et la mise en page, dans
   [`.github/workflows/testes.yml`](.github/workflows/testes.yml) ; le résultat apparaît dans l'onglet **Actions** et dans le
   ✓ / ✗ à côté de chaque commit) : `testes/paridade.sh`, avec les bases en marche, doit finir par `Tudo igual.` Il vérifie
   aussi la documentation : l'en-tête est ce que génère l'aide, et chaque champ des réponses est expliqué. Vous avez touché aux
   écrans ? Lancez aussi la vérification de la mise en page (`testes/paridade.sh --layout sqlite`) : `0 problemas`.
7. **Tout nouveau texte passe par `t()`** (le `api.js` et le `textos/funcoes.php` ont la fonction), avec le portugais comme clé
   et les paramètres entre accolades : `t("Excluir {1}?", {1: nome})`, jamais `"Excluir " + nome + "?"`. Ensuite, traduisez-le
   dans les cinq fichiers de `textos/` et lancez `php testes/textos.php` (le test de parité le lance aussi) : il doit finir sans
   rien de manquant. Vous avez changé le README ? Ses traductions portent la marque de la version qu'elles traduisent (la
   première ligne de `textos/manual/<langue>.md` et de `textos/readme/<langue>.md`), et `php testes/textos.php` signale celle qui
   est restée en arrière : traduisez le passage changé, mettez la nouvelle marque (`php testes/textos.php --manual` et
   `php testes/readme.php --original` donnent l'original avec elle) et lancez `php testes/readme.php`, qui assemble à nouveau les
   `README.<langue>.md`.
8. Ouvrez la pull request en disant ce qui change pour l'utilisateur et comment vous avez testé.

---

## Licence {#licença}

Relojoeiro est **à code source disponible**, pas un logiciel libre : le code est visible, mais il a un propriétaire et des
règles d'utilisation. En résumé (ce qui fait foi est le texte du [`LICENSE`](LICENSE), en portugais) :

| ✅ Vous pouvez | ❌ Vous ne pouvez pas |
|---|---|
| l'installer et l'utiliser pour vous, votre famille ou votre entreprise | créer un nouveau projet basé sur ce code, en tout ou en partie, ni réécrit dans un autre langage |
| modifier le code de votre installation | distribuer le code, original ou modifié, en dehors de ce dépôt |
| proposer des améliorations par pull request ici | proposer le système comme service à d'autres personnes |
| faire un fork sur GitHub pour préparer ces propositions | garder un fork comme projet séparé |

Pour tout autre usage, demandez une autorisation par GitHub ([Challado](https://github.com/Challado)).

> 💡 **Contribuez, ne copiez pas.** Être contributeur de Relojoeiro vaut bien mieux qu'un fork : votre amélioration profite à
> tous, avec votre nom dans l'historique, et vous ne courez aucun risque. Un fork qui devient un projet à part viole la
> licence, et les droits sur ce code sont défendus : demandes de retrait sur les plateformes, action en justice avec
> dommages-intérêts et même action pénale (la loi brésilienne 9.609/98 traite la violation du droit d'auteur sur un logiciel
> comme un délit). Même qui croit avoir raison peut devoir se défendre, et cela coûte du temps et des avocats. **Dans le doute,
> demandez d'abord :** la réponse est gratuite, la dispute après ne l'est pas.

---

<p align="center"><sub>Fait pour ceux qui ont plus de montres que de poignets. ⌚</sub></p>
