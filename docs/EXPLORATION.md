# Absolute — exploration

Direction validée : vue du dessus 2D inspirée de HeartGold, monde partagé avec les autres joueurs visibles. Les routes, bâtiments et grottes se rejoignent en marchant. React gère les dialogues, combats et menus contextuels ; Canvas dessine le monde et la caméra.

Cette première version construit une boucle jouable : compte existant ou inscription, starter au laboratoire, exploration, rencontres dans les hautes herbes, combat au tour par tour, capture, équipe, soins et sauvegarde. Les Pokémon, statistiques, attaques, sprites, comptes et collections viennent du fork. Les nouvelles zones forment une région originale, sans reproduire les cartes officielles.

Le serveur PHP expose une API JSON dédiée. Les déplacements, collisions, rencontres, dégâts, captures et consommations sont validés côté serveur. Les sauvegardes utilisent une table additive, `exploration_saves`, et des révisions pour protéger les actions concurrentes. Les captures rejoignent la table `pokemon` existante. Les présences des joueurs sont actualisées régulièrement ; cette première version ne comprend pas encore de combats entre joueurs ni d'échanges dans le monde.

L'ancien moteur de combat dépend fortement des sessions et du HTML. Les données sont réutilisées, mais la première boucle d'exploration possède un résolveur de combat dédié : attaques de dégâts, types, précision, vitesse, PP, expérience, capture et soins. Les talents, effets de statut, évolution et scénario long restent des extensions futures, pas des fonctionnalités annoncées comme terminées.

## Construction

Le frontend utilise React, TypeScript et Vite. Node 22 sert uniquement à la compilation et ne remplace pas le Node du chat. `frontend/package-lock.json` fixe les dépendances.

Exécuter `./scripts/build-exploration.sh` depuis le dépôt. Les fichiers statiques sont générés dans `app/adventure/`, avec des noms de bundles contenant leur empreinte. Le serveur existant les sert sous `/adventure/`.

Appliquer uniquement `migrations/sql/002_exploration.sql` à la base Absolute si elle n'est pas encore enregistrée. Ne pas réimporter les migrations initiales. La migration est additive et idempotente.

## Validation

Les tests doivent couvrir : connexion et session, starter unique, persistance après rechargement, traversée des passages, collisions et actions invalides, capture unique, PP et inventaire, présence de deux comptes distincts, puis un parcours réel sous Firefox.
