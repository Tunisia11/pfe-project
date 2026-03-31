# Conception et développement d’un moteur d’enrichissement intelligent pour la synchronisation d’archives e-mail (Piler) vers une plateforme de gestion de campagnes Listmonk

## Objectif du MVP
Ce projet implémente un backend PHP 8 avec Fat-Free Framework (style Sukarix) pour :
- exposer des e-mails archivés via une API REST,
- extraire les contacts depuis les e-mails,
- nettoyer et dédupliquer les adresses,
- préparer la future synchronisation vers Listmonk.

Le MVP utilise des données mock pour accélérer le démarrage, tout en préparant une intégration PDO réelle vers la base Piler.

## Architecture (layered)
- **Data Layer**: `app/Repositories` (mock actuel + readiness PDO pour Piler)
- **API Layer**: `public/index.php`, `config/routes.php`, `app/Controllers`
- **Processing Layer**: `app/Services/ContactExtractionService`, `ContactCleaningService`, `ClassificationService`
- **Integration Layer**: `app/Services/ListmonkSyncService` (placeholder)
- **Supporting Layer**: `app/Middlewares/ErrorHandlerMiddleware`, `app/Services/ResponseHelper`, `logs/`, `cli/sync_contacts.php`

## Structure du projet
```text
project-root/
├── app/
│   ├── Controllers/
│   ├── Services/
│   ├── Repositories/
│   ├── Models/
│   └── Middlewares/
├── config/
│   ├── app.php
│   ├── db.php
│   └── routes.php
├── public/
│   ├── index.php
│   └── gui/
│       ├── index.html
│       └── assets/
│           ├── css/dashboard.css
│           └── js/dashboard.js
├── logs/
├── storage/
│   ├── cache/
│   └── logs/
├── tests/
├── cli/
├── vendor/
├── .env.example
├── composer.json
└── README.md
```

## Prérequis
- PHP 8.1+
- Composer

## Installation
```bash
composer install
cp .env.example .env
```

> Par défaut `USE_REAL_DB=false` pour utiliser les données fake.

## Démarrage du serveur local
```bash
php -S localhost:8000 -t public
```

Alternative:
```bash
composer serve
```

## Endpoints disponibles
### 1) Welcome
- `GET /`

### 1-bis) Mini GUI
- `GET /gui`

### 2) Health
- `GET /health`

Réponse attendue:
```json
{
  "success": true,
  "status": "ok"
}
```

### 3) E-mails
- `GET /emails?limit=10&offset=0`
- `GET /emails/@id`
- `GET /emails/search?q=keyword`

### 4) Pièces jointes
- `GET /emails/@id/attachments`

## Exemples de test (curl)
```bash
curl http://localhost:8000/
curl http://localhost:8000/health
curl "http://localhost:8000/emails?limit=3&offset=0"
curl http://localhost:8000/emails/2
curl "http://localhost:8000/emails/search?q=campaign"
curl http://localhost:8000/emails/4/attachments
```

Ouvrir aussi la mini interface web:
```bash
open http://localhost:8000/gui
```

## Pipeline CLI
Exécuter :
```bash
php cli/sync_contacts.php
```

Le script affiche un résumé :
- total emails processed
- total extracted addresses
- valid contacts
- duplicates removed
- ignored invalid/system addresses

Chaque exécution est aussi tracée dans `storage/logs/sync_history.log` (une ligne JSON par run).

## Test sur `piler_backup.sql`
Le projet peut lire directement le dump SQL sans mapper tout le schéma en dur:
- `USE_SQL_DUMP=true`
- `SQL_DUMP_PATH=piler_backup.sql`
- `SQL_DUMP_MAX_EMAILS=0` (0 = charger tout le dump, valeur > 0 = limite)
- `SQL_DUMP_MEMORY_LIMIT=1024M` (recommandé en mode full dump)

Au premier run, un cache est généré dans `storage/cache/piler_dump_cache.json` pour accélérer les appels suivants.

## Database readiness (Piler)
Le projet est prêt pour une migration vers la base réelle :
- `config/db.php` initialise PDO via variables `.env`
- `EmailRepository` et `AttachmentRepository` ont des méthodes `*FromPiler()` avec commentaires TODO

Important :
- Aucun schéma Piler réel n’est supposé dans ce MVP.
- Les noms de tables/colonnes doivent être mappés après inspection réelle du schéma.

## Placeholders MVP
- `ClassificationService`: classification factice (dummy)
- `ListmonkSyncService`: stub TODO (pas de sync réelle)

## Liste de tests Postman (suggestion)
Créer une collection `Piler Extractor MVP` avec ces requêtes :
1. `GET /`
2. `GET /health`
3. `GET /emails`
4. `GET /emails?limit=2&offset=1`
5. `GET /emails/1`
6. `GET /emails/search?q=api`
7. `GET /emails/1/attachments`
8. `GET /emails/999` (test not-found)

## Prochaines étapes
1. Inspecter le schéma Piler réel et mapper les requêtes PDO.
2. Ajouter l’historique de synchronisation dans `storage/` ou table dédiée.
3. Implémenter la synchronisation Listmonk (API token, list IDs, retry).
4. Remplacer la classification dummy par un module NLP réel.
5. Ajouter des tests unitaires (services) et d’intégration (endpoints).
