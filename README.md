# MERISU — Module Inventaire & Production

Comptage des stocks à l'ouverture (08:00) et à la clôture (22:00), calcul
automatique de la production à réaliser le lendemain, et rapport de delta
technique (recettes ↔ consommation réelle) pour l'administration.

> ⚠️ **Les données livrées sont des PLACEHOLDERS.** Les 8 produits, les seuils et
> les recettes de démonstration existent uniquement pour rendre l'application
> immédiatement démontrable. Tout se remplace depuis l'interface d'administration,
> sans redéploiement. Voir [« Où renseigner les vraies données »](#où-renseigner-les-vraies-données).

---

## Démarrage rapide (aucune base de données requise)

```bash
npm install
npm run build:domain          # le front et l'API partagent ce paquet

npm run dev:server            # API sur http://localhost:3001
npm run dev:web               # PWA sur http://localhost:5173
```

Ouvrir <http://localhost:5173> et se connecter avec un compte de démonstration :

| Identifiant   | Code | Rôle       |
| ------------- | ---- | ---------- |
| `admin`       | 0000 | ADMIN      |
| `consultant1` | 1111 | CONSULTANT |
| `consultant2` | 2222 | CONSULTANT |

> ⚠️ Ces comptes n'existent que tant que le module Consultant réel n'est pas
> branché. Ne jamais les conserver en production.

Au premier démarrage, l'API crée automatiquement les 8 emplacements produits et
une matrice de seuils fictive, pour que les écrans ne soient pas vides.

## Architecture

```
packages/
  domain/   @merisu/domain  — noyau de calcul pur, SANS dépendance framework
  server/   @merisu/server  — API REST (Express + TypeScript)
  web/      @merisu/web     — PWA (React + TypeScript + Vite + Workbox)
```

Le noyau de calcul est **partagé entre l'API et la PWA**. C'est délibéré : le
consultant et l'administrateur voient ainsi rigoureusement le même chiffre, et
les formules ne sont écrites qu'une seule fois.

| Formule                                   | Fichier                             |
| ----------------------------------------- | ----------------------------------- |
| §5.1 Écart net du jour                     | `packages/domain/src/netVariance.ts` |
| §5.2 Production à réaliser demain          | `packages/domain/src/production.ts`  |
| §5.3 Règles de validation et verrouillage  | `packages/domain/src/validation.ts`  |
| §6 Delta technique                         | `packages/domain/src/delta.ts`       |
| Arrondis paramétrables                     | `packages/domain/src/rounding.ts`    |
| Dates métier, passage de semaine, fuseau   | `packages/domain/src/date.ts`        |

---

## Points de branchement des modules existants

Les modules **Consultant / Stanowisko** et **Product Recipe Technique** ne sont
pas recodés : le module les consomme via deux adaptateurs. Ce sont les **seuls**
fichiers à modifier pour raccorder l'existant — tout le reste du code passe par
leurs interfaces et ignore d'où viennent les données.

### 1. Module Consultant / Poste de travail

**Fichier :** `packages/server/src/adapters/consultantService.ts`

```ts
interface ConsultantService {
  authenticate(credentials): Promise<Consultant | null>;
  getConsultant(id): Promise<Consultant | null>;
  listConsultants(): Promise<Consultant[]>;
  listWorkstations(): Promise<Workstation[]>;
  getWorkstation(id): Promise<Workstation | null>;
  getAssignedWorkstation(consultantId): Promise<Workstation | null>;
}
```

À faire :

1. Écrire une classe implémentant `ConsultantService` qui interroge le module
   existant (HTTP, service partagé ou requête SQL — selon ce qu'il expose).
2. Compléter `mapExternalRole()` avec les libellés de rôles réels (ex. `kierownik`)
   pour les faire correspondre à `CONSULTANT` / `ADMIN`.
3. La substituer à `LocalConsultantService` dans
   `packages/server/src/context.ts` (fonction `buildContext`).
4. Si le module existant gère déjà l'authentification, court-circuiter
   `authenticate()` pour valider **son** jeton au lieu du code PIN local
   (voir aussi `packages/server/src/auth/token.ts`).

### 2. Module Product Recipe Technique (recettes)

**Fichier :** `packages/server/src/adapters/recipeService.ts` — **lecture seule.**

```ts
interface RecipeService {
  getRecipe(productId): Promise<Recipe | null>;
  getRecipes(productIds): Promise<Recipe[]>;
  listMaterials(): Promise<Material[]>;
  getMaterial(id): Promise<Material | null>;
}
```

À faire :

1. Écrire une classe lisant les recettes du module existant.
2. **Faire le pont sur les identifiants** : chaque fiche produit porte un champ
   `recipeRef`, prévu pour stocker l'identifiant du produit côté module Recette.
   Il se renseigne dans _Admin ▸ Produits_.
3. Vérifier l'unité des `qtyPerUnit` : le delta technique suppose qu'elle est
   exprimée dans l'unité de la matière (g, ml, pièce).
4. La substituer à `LocalRecipeService` dans `packages/server/src/context.ts`.

Tant que ces adaptateurs ne sont pas remplacés, l'application fonctionne avec
les données locales de `packages/server/src/seedData.ts`.

---

## Où renseigner les vraies données

Tout se saisit dans l'interface, **rien n'est à coder** :

| Donnée                                    | Écran                          |
| ----------------------------------------- | ------------------------------ |
| Libellés des 8 produits (FR/PL/IT/ES)      | Admin ▸ Produits               |
| Unités, actif/inactif                      | Admin ▸ Produits               |
| Facteur de perte, pas et mode d'arrondi    | Admin ▸ Produits               |
| Référence recette (`recipeRef`)            | Admin ▸ Produits               |
| Matrice des seuils (pièces par jour)       | Admin ▸ Seuils                 |
| Horaires 08:00 / 22:00, fuseau             | Admin ▸ Paramètres             |
| Langue par défaut                          | Admin ▸ Paramètres             |
| Politique photo (obligatoire ? par produit ?) | Admin ▸ Paramètres          |
| Tolérance du delta technique               | Admin ▸ Paramètres             |
| Consommation réelle de matières            | API `POST /api/admin/material-movements` |

Les emplacements produits portent des codes stables `PRODUIT_1` … `PRODUIT_8`,
qui ne changent pas quand l'admin renomme un produit et ne sont jamais affichés
aux utilisateurs.

### Questions restées ouvertes (§10 du cahier des charges)

| Question                          | Réponse actuelle du code                                                                                                     |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| Politique photo                   | Paramétrable : `photoRequired` + `photoPerProduct`. Par défaut : une photo globale exigée le soir.                             |
| Tolérance du delta                | Paramétrable, 5 % par défaut.                                                                                                  |
| Seuils par poste ou globaux ?     | **Les deux sont possibles.** Un seuil défini pour un poste prime sur le seuil global. Sans seuil par poste, tout reste global. L'écran Seuils édite les seuils globaux ; l'API accepte déjà `workstationId`. |

---

## Production

### Base de données PostgreSQL

Le store mémoire convient à la démonstration et aux tests, mais **pas à la
production** : les données ne survivent pas au redémarrage.

```bash
export STORE=postgres
export DATABASE_URL=postgres://user:pass@host:5432/merisu
export AUTH_SECRET="<secret long et aléatoire>"

npm run migrate -w @merisu/server   # applique le schéma (script idempotent)
npm run seed -w @merisu/server      # facultatif : 8 slots + seuils placeholder
npm run build && npm start -w @merisu/server
```

Le schéma est dans `packages/server/migrations/001_init.sql`. Il ne crée aucune
table appartenant aux modules existants : `consultant_id` et `workstation_id`
sont de simples références textuelles, sans clé étrangère vers un schéma que ce
module ne possède pas.

### Variables d'environnement

| Variable               | Défaut     | Rôle                                              |
| ---------------------- | ---------- | ------------------------------------------------- |
| `PORT`                 | `3001`     | Port de l'API                                     |
| `STORE`                | `memory`   | `memory` (démo/tests) ou `postgres` (production)   |
| `DATABASE_URL`         | —          | Requis si `STORE=postgres`                        |
| `AUTH_SECRET`          | dev        | **Obligatoire en production** (signature jetons)   |
| `TOKEN_TTL_SECONDS`    | `43200`    | Durée de vie du jeton (12 h)                      |
| `CORS_ORIGINS`         | `*`        | Origines autorisées, séparées par des virgules    |
| `UPLOAD_DIR`           | `uploads`  | Dossier des photos de comptage                    |
| `MEMORY_SNAPSHOT_FILE` | —          | Persiste le store mémoire dans un fichier JSON    |

Le serveur **refuse de démarrer** en `NODE_ENV=production` si `AUTH_SECRET` est
resté à sa valeur de développement.

### Front

```bash
npm run build -w @merisu/web        # produit packages/web/dist (PWA + service worker)
```

Servir `dist/` derrière un serveur statique, avec `/api` et `/uploads` routés
vers l'API. **HTTPS est requis** pour qu'un service worker s'installe : sans lui,
pas d'installation sur l'écran d'accueil ni de fonctionnement hors-ligne.

---

## Fonctionnement hors-ligne

Le consultant compte debout au poste, parfois sans réseau. Le comportement est
donc le suivant :

1. Toute écriture passe par une **file d'attente persistante** (IndexedDB).
2. Hors-ligne, la saisie est mise en file ; l'entête affiche « Hors ligne » et le
   nombre d'envois en attente.
3. Au retour du réseau, la file est rejouée **dans l'ordre** — indispensable pour
   qu'une validation ne parte jamais avant les quantités qu'elle valide.
4. L'écriture des comptages est **idempotente** (clé : date + poste + produit +
   moment) : rejouer un envoi déjà passé ne crée jamais de doublon.
5. Une erreur métier (4xx) n'est jamais rejouée en boucle : la requête est
   retirée de la file et signalée à l'utilisateur.

Les photos sont redimensionnées (1280 px max) avant d'être mises en file, sans
quoi une photo brute de téléphone saturerait le stockage local.

---

## Tests

```bash
npm test                      # domaine + API
npm run test -w @merisu/domain
```

- **76 tests** sur les formules (`@merisu/domain`) : stock nul, clôture supérieure
  au requis, passage dimanche→lundi, passage de mois et d'année, année bissextile,
  facteur de perte, arrondis par lot, division par zéro dans le delta, données
  partielles.
- **40 tests d'intégration** sur l'API : parcours complet matin → soir →
  validation → plan figé, verrouillage, déverrouillage admin tracé, cloisonnement
  par poste et par rôle, rapports et exports.

---

## API

| Méthode | Route                              | Rôle        | Description                              |
| ------- | ---------------------------------- | ----------- | ---------------------------------------- |
| `POST`  | `/api/auth/login`                  | public      | Connexion, renvoie un jeton              |
| `GET`   | `/api/auth/workstations`           | public      | Postes (id + nom), pour l'écran de connexion |
| `GET`   | `/api/auth/me`                     | authentifié | Consultant, poste et paramètres          |
| `GET`   | `/api/day-sheet`                   | authentifié | Feuille de saisie complète                |
| `PUT`   | `/api/counts`                      | authentifié | Enregistrement en masse (idempotent)      |
| `POST`  | `/api/counts/photos`               | authentifié | Photo par clé métier (compatible hors-ligne) |
| `POST`  | `/api/validate`                    | authentifié | Valide ; le soir, fige le plan du lendemain |
| `POST`  | `/api/unlock`                      | **ADMIN**   | Déverrouille une saisie validée (tracé)   |
| `GET`   | `/api/production-plan`             | authentifié | Plan figé pour une date                   |
| `GET`   | `/api/production-plan/preview`     | authentifié | Aperçu non figé (avant validation)        |
| `GET`   | `/api/admin/products`              | authentifié | Produits (actifs seuls pour un consultant) |
| `PUT`   | `/api/admin/products/:id`          | **ADMIN**   | Paramètres produit                        |
| `GET`   | `/api/admin/par-matrix`            | authentifié | Matrice des seuils                        |
| `PUT`   | `/api/admin/par-matrix`            | **ADMIN**   | Édition des seuils (`null` supprime)      |
| `GET`   | `/api/admin/settings`              | authentifié | Paramètres généraux                       |
| `PUT`   | `/api/admin/settings`              | **ADMIN**   | Modification des paramètres               |
| `POST`  | `/api/admin/material-movements`    | **ADMIN**   | Consommation réelle d'une matière         |
| `GET`   | `/api/admin/audit`                 | **ADMIN**   | Journal d'audit                           |
| `GET`   | `/api/reports/daily-net`           | **ADMIN**   | Écarts nets sur une période               |
| `GET`   | `/api/reports/delta`               | **ADMIN**   | Delta technique                           |
| `GET`   | `/api/reports/matrix`              | **ADMIN**   | Vue matricielle jour × produit            |
| `GET`   | `/api/reports/delta.csv`           | **ADMIN**   | Export CSV (BOM UTF-8, compatible Excel)  |
| `GET`   | `/api/reports/matrix.csv`          | **ADMIN**   | Export CSV de la matrice                  |

Les erreurs renvoient un **code stable**, jamais un message : la PWA le traduit
dans la langue du consultant.

```json
{ "error": { "code": "VALIDATION_FAILED", "details": [{ "code": "MISSING_QTY", "productId": "product-2" }] } }
```

---

## Traçabilité

Chaque saisie, validation, correction et modification de paramètre est
horodatée avec son auteur et son poste, consultable dans _Admin ▸ Historique_ :
`COUNT_SAVED`, `EVENING_VALIDATED`, `COUNT_UNLOCKED`, `PRODUCTION_PLAN_FROZEN`,
`PRODUCT_UPDATED`, `PAR_MATRIX_UPDATED`, `SETTINGS_UPDATED`…

## Données incomplètes

Les rapports n'affichent **jamais** une conclusion sur des données partielles. Un
jour sans comptage du matin n'est pas compté comme un écart nul : il est exclu du
total et signalé. Le bandeau « Données partielles » précise ce qui manque —
plan de production absent, produit sans recette, consommation réelle non saisie.
