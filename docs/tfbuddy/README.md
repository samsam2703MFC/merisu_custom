# Spécification TF Buddy — ce qu'elle donne

`openapi.json` contient désormais la vraie spécification, récupérée depuis
<https://test.tfbuddy.com/docs/> : **OpenAPI 3.0, 924 chemins, 1 229 opérations,
238 schémas**, un seul serveur déclaré (`https://test.tfbuddy.com`,
« Środowisko testowe »).

Relue avant d'être versionnée dans ce dépôt public : aucun jeton JWT, aucune
clé Stripe ou AWS, aucun mot de passe d'exemple, aucun hôte interne. La seule
adresse qui y figure est `billing@example.invalid`.

## La particularité à connaître avant tout

**Seules 329 des 1 229 opérations décrivent un contrat.** Les 900 autres sont
des routes relevées automatiquement dans le code, et le disent elles-mêmes :

> *Runtime route registered from a static route source. Request, authorization
> and response contracts are not inferred by coverage generation.*

Elles donnent le **verbe, le chemin et les paramètres d'URL** — pas la forme
des données. Elles prouvent que l'endpoint existe ; elles ne disent pas ce
qu'il renvoie.

La couverture est très inégale selon ce qui nous intéresse :

| Famille | Contrats décrits | Routes sans contrat |
|---|--:|--:|
| **Identités et postes** | 18 | 2 |
| **Ventes** | 4 | 1 |
| Check-lists | 3 | 0 |
| Catalogue produits | 1 | 35 |
| Recettes | 0 | 14 |
| Inventaire et stock | 0 | 11 |

Autrement dit : la famille la moins pressée est la mieux décrite, et les trois
qui nous manquaient le plus sont celles dont on ne connaît que l'existence.

## Ce qui est exploitable immédiatement

### Authentification — `bearerAuth`, JWT

`POST /api/v1/employees/authenticate` est **entièrement décrit**. Il accepte
l'un ou l'autre de deux couples :

```json
{ "login": "…", "password": "…" }
{ "email": "…", "pin":      "…" }
```

> *At least one non-empty credential pair is required. When both are supplied,
> runtime authenticates with login/password.*

Réponse : `{ token, refresh_token, success, user { id, name, email, role_name,
positions[], permissions[] } }`.

⚠️ **Le PIN seul n'authentifie pas.** TF Buddy exige `email + pin`. La PWA
boutique, elle, identifie les personnes par leur code à six chiffres et rien
d'autre — c'est tout son intérêt sur un appareil partagé au comptoir. Voir
« Décisions en attente ».

Existent aussi, sans contrat : `POST /api/v1/devices/authenticate` et
`/api/v1/device/authenticate` — une authentification par APPAREIL, qui serait
la bonne maille pour un terminal de boutique.

### Personnel — `GET /api/v1/employees`

Décrit, et directement exploitable par `ConsultantServiceInterface` :

| Champ TF Buddy | Correspondance MERISU |
|---|---|
| `id`, `id_shop` | `Consultant::id`, boutique |
| `name`, `surname`, `display_name` | `firstName`, `lastName`, `displayName()` |
| `email`, `phone`, `login` | `email` |
| `lang_code` | `Consultant::locale` |
| `id_role`, `franchise_role` | `Role` (CONSULTANT / ADMIN) |
| `workstations[]` | `Workstation[]` |
| `competencies[]`, `positions[]` | — sans usage ici |

`PUT /api/v1/employees/{employeeId}/workstations` remplace les rattachements
(`{"workstation_ids": [1, 2]}`). `GET /api/v1/shops/workstations` existe mais
n'est pas décrit.

### Ventes — décrites, mais opaques

`GET /api/v1/consultant/shops/sales-kpis`, `/monthly-sales` et
`/category-sales` acceptent `date_from` / `date_to`, gèrent l'ETag et le 304 —
et déclarent leur réponse comme `object, additionalProperties: true`. Le
contrat existe donc pour l'appel, pas pour la lecture du résultat.

Il suffira d'**une réponse réelle** de chacun pour écrire l'adaptateur : les
noms de champs se lisent alors directement.

## Ce dont on ne connaît que l'existence

Les chemins ci-dessous répondent, mais leur charge utile n'est pas décrite.

**Catalogue** — `GET /api/v1/products`, `/products/{id}`,
`/api/v1/product-categories`, `/product-category/{id}/products`. Deux points à
vérifier en priorité :

* `GET /api/v1/products/aliases` et `PATCH /api/v1/products/{id}/aliases` —
  les « aliases » sont très probablement le mécanisme de traduction. S'ils
  portent bien les libellés par langue, l'exigence i18n est couverte côté
  catalogue ; sinon, elle reste à notre charge.
* `GET /api/v1/products/{id}/technical-sheet` — fiche technique, sans doute la
  source des ingrédients et allergènes de l'étiquette.

**Recettes** — `GET /api/v1/recipes`, `/recipes/flatten`, `/recipes/{id}/cost`,
`GET /api/v1/shops/{id}/recipes`. `recipes/flatten` est celui qui nous
intéresse : le delta technique a besoin de `[produit][matière] => quantité`.

**Inventaire** — et c'est le plus important pour la suite du projet :

```
GET   /api/v1/shops/{id}/material-inventory
PATCH /api/v1/shops/{id}/material-inventory/{materialId}
PATCH /api/v1/shops/{id}/products/{productId}/inventory
POST  /api/v1/shops/{id}/materials/stocktakings
GET   /api/v1/shops/{id}/products/waste
GET   /api/v1/shops/{id}/reports/sales/production-planning/products/pdf
```

TF Buddy sait déjà tenir un inventaire de boutique, enregistrer un
stocktaking, suivre la casse et sortir un plan de production. **Ce module
recouvre donc en partie un existant** — question à trancher avec le client,
pas à décider ici.

De même, `GET /api/v1/consultant/shops/{shopId}/checklists` et
`/checklists/progress` recouvrent le menu des tâches.

## Décisions en attente

1. **Comment la PWA identifie ses vendeurs.** PIN local (situation actuelle),
   `email + pin` contre TF Buddy, ou authentification par appareil plus PIN
   local pour désigner la personne.
2. **Ce que devient le comptage.** Module autonome, ou source qui pousse ses
   comptages vers l'inventaire TF Buddy existant.

## Comment avancer sans attendre

Rien n'est bloqué. Chaque adaptateur a une implémentation locale qui continue
de tourner (`LocalRecipeService`, `LocalShopRankingService`,
`DbConsultantService`), et l'écran indique quand les chiffres sont de
démonstration plutôt que de laisser croire à une mesure.

Pour les familles sans contrat, **une réponse JSON réelle vaut mieux que la
spécification** : un `curl` authentifié sur `/api/v1/products` et
`/api/v1/recipes/flatten`, collé dans `docs/tfbuddy/reponses/`, suffit à écrire
l'adaptateur correspondant. Anonymisez au besoin — seuls les noms de champs
comptent.
