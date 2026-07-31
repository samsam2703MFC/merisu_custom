# MERISU — Module Inventaire & Production

Comptage des stocks à l'ouverture (08:00) et à la clôture (22:00), calcul
automatique de la production à réaliser le lendemain, et rapport de delta
technique (recettes ↔ consommation réelle) pour l'administration.

**Implémentation : Symfony 7 + Twig, sans JavaScript de framework.** Les écrans
sont rendus côté serveur ; un petit script natif ajoute les boutons tactiles,
l'indicateur réseau et la file d'attente hors-ligne.

> ⚠️ **Les données livrées sont des PLACEHOLDERS.** Les 8 produits, les seuils et
> les recettes de démonstration existent uniquement pour rendre l'application
> démontrable. Tout se remplace depuis l'interface d'administration, sans
> redéploiement. Voir [« Où renseigner les vraies données »](#où-renseigner-les-vraies-données).

---

## Démarrage rapide (aucune base de données à installer)

```bash
cd symfony
composer install

php bin/console merisu:seed        # schéma SQLite + 8 slots + seuils fictifs
php -S 127.0.0.1:8000 -t public bin/dev-server.php
```

Ouvrir <http://127.0.0.1:8000> et se connecter :

| Identifiant   | Code | Rôle       |
| ------------- | ---- | ---------- |
| `admin`       | 0000 | ADMIN      |
| `consultant1` | 1111 | CONSULTANT |
| `consultant2` | 2222 | CONSULTANT |

> ⚠️ Ces comptes n'existent que tant que le module Consultant réel n'est pas
> branché. Ne jamais les conserver en production.

Le routeur `bin/dev-server.php` sert uniquement au serveur intégré de PHP, qui
sans lui transmet les `.svg` et `.png` au contrôleur frontal. En production,
Nginx ou Apache s'en charge et ce fichier n'est jamais utilisé.

---

## Organisation

```
symfony/
  src/Domain/       noyau de calcul PUR (aucune dépendance framework)
  src/Store/        persistance Doctrine DBAL + schéma portable
  src/Adapter/      ← BRANCHEMENT des modules existants
  src/Service/      orchestration de la saisie et des rapports
  src/Controller/   écrans consultant et administration
  src/Security/     session, rôles, cloisonnement par poste, langue
  templates/        vues Twig
  translations/     messages.{fr,pl,it,es}.yaml
  public/assets/    styles.css, app.js — restylables sans toucher au PHP
  tests/            65 tests PHPUnit sur les formules
```

| Formule                                  | Fichier                            |
| ---------------------------------------- | ---------------------------------- |
| §5.1 Écart net du jour                    | `src/Domain/NetVariance.php`       |
| §5.2 Production à réaliser demain         | `src/Domain/Production.php`        |
| §5.3 Validation et verrouillage           | `src/Domain/EveningValidation.php` |
| §6 Delta technique                        | `src/Domain/Delta.php`             |
| Arrondis paramétrables                    | `src/Domain/Rounding.php`          |
| Dates métier, passage de semaine, fuseau  | `src/Domain/BusinessDate.php`      |

Le dossier `packages/` contient une **implémentation antérieure en Node/TypeScript**
(noyau de calcul + API REST), conservée à la demande. Elle n'est pas nécessaire
au fonctionnement du module Symfony, qui est autonome.

---

## Points de branchement des modules existants

Les modules **Consultant / Stanowisko** et **Product Recipe Technique** ne sont
pas recodés. Deux interfaces les représentent, et **un seul fichier est à
modifier pour les raccorder** : `config/services.yaml`.

```yaml
# config/services.yaml
Merisu\Inventory\Adapter\ConsultantServiceInterface:
    alias: App\Stanowisko\VotreService      # au lieu de LocalConsultantService

Merisu\Inventory\Adapter\RecipeServiceInterface:
    alias: App\Recipe\VotreService          # au lieu de LocalRecipeService
```

### 1. Module Consultant / Poste de travail

`src/Adapter/ConsultantServiceInterface.php`

```php
authenticate(string $login, string $secret): ?Consultant
consultant(string $id): ?Consultant
consultants(): array
workstations(): array
workstation(string $id): ?Workstation
assignedWorkstation(string $consultantId): ?Workstation
```

À faire :

1. Implémenter l'interface en interrogeant le module existant (service injecté,
   appel HTTP, ou requête sur ses tables).
2. Faire correspondre ses rôles à `CONSULTANT` / `ADMIN`.
3. Si l'application hôte possède déjà un pare-feu Symfony, remplacer
   `CurrentUser::login()` par la lecture de son utilisateur connecté : le reste
   du code ne dépend que de `consultant()` et `workstationId()`.

### 2. Module Product Recipe Technique — **lecture seule**

`src/Adapter/RecipeServiceInterface.php`

```php
recipes(array $productIds): array   // [productId][materialId] => qtyPerUnit
materials(): array
material(string $id): ?Material
```

À faire :

1. Implémenter l'interface en lisant les recettes du module existant.
2. **Faire le pont sur les identifiants** : chaque fiche produit porte un champ
   `recipeRef`, prévu pour stocker l'identifiant du produit côté module Recette.
   Il se renseigne dans _Admin ▸ Produits_.
3. Vérifier l'unité des quantités : le delta technique suppose qu'elles sont
   exprimées dans l'unité de la matière (g, ml, pièce).

---

## Où renseigner les vraies données

Tout se saisit dans l'interface, **rien n'est à coder** :

| Donnée                                        | Écran                          |
| --------------------------------------------- | ------------------------------ |
| Libellés des 8 produits (FR/PL/IT/ES)          | Admin ▸ Produits               |
| Unités, actif/inactif                          | Admin ▸ Produits               |
| Facteur de perte, pas et mode d'arrondi        | Admin ▸ Produits               |
| Référence recette (`recipeRef`)                | Admin ▸ Produits               |
| Matrice des seuils (pièces requises par jour)  | Admin ▸ Seuils                 |
| Horaires 08:00 / 22:00, fuseau                 | Admin ▸ Paramètres             |
| Langue par défaut                              | Admin ▸ Paramètres             |
| Politique photo (obligatoire ? par produit ?)  | Admin ▸ Paramètres             |
| Tolérance du delta technique                   | Admin ▸ Paramètres             |
| Consommation réelle de matières                | Admin ▸ Delta technique        |

Les emplacements portent des codes stables `PRODUIT_1` … `PRODUIT_8`, qui ne
changent pas quand l'admin renomme un produit et ne sont jamais affichés aux
utilisateurs.

### Questions restées ouvertes (§10 du cahier des charges)

| Question                      | Réponse actuelle du code                                                                                                                                              |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Politique photo               | Paramétrable : `photoRequired` + `photoPerProduct`. Par défaut, une photo globale exigée le soir.                                                                        |
| Tolérance du delta            | Paramétrable, 5 % par défaut.                                                                                                                                           |
| Seuils par poste ou globaux ? | **Les deux fonctionnent.** Un seuil défini pour un poste prime sur le seuil global ; sans seuil par poste, tout reste global. L'écran Seuils édite les seuils globaux. |

---

## Restylage des vues (aller-retour avec un outil de design)

Les gabarits sont écrits pour être restylés sans risque :

- **aucun style en ligne** — tout le CSS vit dans `public/assets/styles.css`,
  qui peut être remplacé intégralement ;
- **aucun texte en dur** — tout passe par `trans` et `translations/*.yaml` ;
- **classes stables et sémantiques**, listées en tête de `styles.css` ;
- les motifs répétés (bandeau, pastille, champ de comptage, filtre de période)
  sont des macros dans `templates/components/_macros.html.twig` : les modifier
  là suffit à changer tous les écrans ;
- `app.js` ne contient ni style ni libellé : il lit les textes traduits dans des
  attributs `data-` posés par les gabarits.

Points d'accroche à **conserver** lors d'un restylage, sinon le comportement
casse : `data-count-form`, `data-qty-step`, `data-connectivity`,
`data-connectivity-label`, `data-connectivity-pending`, `data-offline-banner`,
`data-confirm`, `data-autosubmit`, et les classes `qty__input`, `count-item`.

---

## Production

### PostgreSQL

Le module tourne en SQLite pour la démonstration ; en production, viser PostgreSQL :

```bash
export DATABASE_URL="postgresql://user:pass@localhost:5432/merisu"
export APP_ENV=prod
export APP_SECRET="<secret long et aléatoire>"

composer install --no-dev --optimize-autoloader
php bin/console merisu:seed        # applique le schéma (idempotent)
```

Le schéma est créé via l'abstraction DBAL, donc identique sur les deux moteurs.
`migrations/001_init.sql` reste fourni pour les équipes qui préfèrent appliquer
le schéma PostgreSQL en SQL brut.

Aucune table appartenant aux modules existants n'est créée : `consultant_id` et
`workstation_id` sont de simples références textuelles, sans clé étrangère vers
un schéma qui n'appartient pas à ce module.

### Serveur web

Faire pointer la racine sur `symfony/public/`. **HTTPS est requis** pour qu'un
service worker s'installe : sans lui, pas d'installation sur l'écran d'accueil
ni de fonctionnement hors-ligne.

---

## Fonctionnement hors-ligne

Le consultant compte debout au poste, parfois sans réseau :

1. Les écrans sont des formulaires HTML classiques : **en ligne, tout fonctionne
   sans JavaScript**.
2. Hors-ligne, `app.js` intercepte l'envoi et le met en file dans IndexedDB ;
   l'entête affiche « Hors ligne » et le nombre d'envois en attente.
3. Au retour du réseau, la file est rejouée **dans l'ordre** — indispensable pour
   qu'une validation ne parte jamais avant les quantités qu'elle valide.
4. L'écriture des comptages est **idempotente** (clé date + poste + produit +
   moment) : rejouer un envoi déjà passé ne crée jamais de doublon.
5. Une erreur métier (4xx) n'est pas rejouée en boucle : l'envoi est retiré de la
   file.

---

## Tests

```bash
cd symfony
./vendor/bin/phpunit
```

**65 tests** sur les formules : stock nul, clôture supérieure au requis, passage
dimanche→lundi, passage de mois et d'année, année bissextile, facteur de perte,
arrondis par lot, division par zéro dans le delta, données partielles, règles de
validation et de verrouillage.

Vérifications complémentaires effectuées au navigateur : parcours matin → soir →
validation → plan figé, verrouillage après validation, bascule de langue,
rapports, exports CSV, cloisonnement par rôle (403), et file hors-ligne
(saisies conservées pendant la coupure puis rejouées à la reconnexion).

---

## Traçabilité

Chaque saisie, validation, correction et modification de paramètre est
horodatée avec son auteur et son poste, consultable dans _Admin ▸ Historique_ :
`LOGIN`, `COUNT_SAVED`, `EVENING_VALIDATED`, `COUNT_UNLOCKED`,
`PRODUCTION_PLAN_FROZEN`, `PRODUCT_UPDATED`, `PAR_MATRIX_UPDATED`,
`SETTINGS_UPDATED`…

## Données incomplètes

Les rapports n'affichent **jamais** une conclusion sur des données partielles. Un
jour sans comptage du matin n'est pas compté comme un écart nul : il est exclu du
total et signalé. Le bandeau « Données partielles » précise ce qui manque — plan
de production absent, produit sans recette, consommation réelle non saisie.
