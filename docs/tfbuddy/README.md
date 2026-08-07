# Spécification TF Buddy

Ce dossier attend **un seul fichier** : `openapi.json`, la spécification de
l'API TF Buddy servie par <https://test.tfbuddy.com/docs/>.

Il est aujourd'hui occupé par un **emplacement réservé** — un document OpenAPI
vide, marqué `"x-merisu-placeholder": true`. Tant que cette clé y figure, le
fichier ne décrit rien et personne ne doit s'y fier.

## Pourquoi ce détour

L'environnement de développement de l'agent sort par une passerelle réseau à
liste blanche. Elle refuse `test.tfbuddy.com` **avant** d'atteindre le serveur :

```
CONNECT test.tfbuddy.com:443 → 403
```

Ce n'est ni une question d'authentification ni de certificat, et aucun réglage
côté application n'y change quoi que ce soit. Le fichier committé ici est le
chemin le plus court : il traverse la seule route ouverte, GitHub.

## Récupérer le fichier

L'écran `/docs/` est une interface Swagger UI : elle n'est pas la
spécification, elle la **charge**. C'est le document JSON sous-jacent qu'il
nous faut.

1. Ouvrez <https://test.tfbuddy.com/docs/> dans votre navigateur, connecté.
2. Ouvrez les outils de développement (F12), onglet **Réseau**, puis rechargez.
3. Repérez la requête qui renvoie du JSON — son nom est presque toujours l'un
   de ceux-ci :

   | Chemin habituel | Cadriciel |
   |---|---|
   | `/openapi.json` | FastAPI, NestJS |
   | `/docs/swagger.json` | Swagger UI monté sous `/docs` |
   | `/swagger/v1/swagger.json` | ASP.NET Core |
   | `/api-docs` · `/v3/api-docs` | Spring, express-swagger |

4. Ouvrez cette URL dans un onglet, enregistrez la page.
5. Remplacez **intégralement** `docs/tfbuddy/openapi.json` par ce contenu, et
   poussez.

Le format YAML convient aussi : nommez alors le fichier `openapi.yaml` et
supprimez le `.json`. Une capture d'écran, en revanche, ne convient pas — les
noms de champs doivent être lisibles par une machine.

> **Avant de pousser, relisez le fichier.** Une spécification embarque parfois
> des serveurs internes, des exemples tirés de données réelles ou des jetons
> collés dans un `example`. **Ce dépôt est public.** Retirez ce qui n'a rien à
> y faire — la structure suffit, les valeurs d'exemple ne servent à rien ici.

## Ce que la spécification doit couvrir

Quatre familles, dans l'ordre où elles débloquent quelque chose. Chacune se
branche derrière une interface d'adaptateur qui existe déjà : le jour où la
spécification arrive, il n'y a qu'une classe à écrire et un alias à changer
dans `symfony/config/services.yaml`. **Aucun écran ne bouge.**

### 1. Catalogue produits — le plus urgent

Rien n'existe encore côté MERISU : la boutique saisit ses produits à la main
dans Admin ▸ Produits. S'ils sont déjà décrits dans TF Buddy, il faut les
importer plutôt que les ressaisir.

Ce que porte une fiche ici, et qu'il s'agit de faire correspondre :

| Champ MERISU | Ce que c'est |
|---|---|
| `code` | clé stable, jamais montrée au vendeur |
| `name` | libellé **par langue** (fr, pl, it, es) |
| `unit` | unité de comptage (pcs, g, ml…) |
| `category` | rayon de production |
| `nature` | matière première, ou composition |
| `shelfLifeDays` | durée de vie, pour la DLC de l'étiquette |
| `ingredients`, `allergens` | mentions de l'étiquette, par langue |

Les libellés par langue sont le point à vérifier en premier : sans eux,
l'exigence i18n du cahier des charges tombe sur le catalogue.

### 2. Ventes — `ShopRankingServiceInterface`

Le tableau de bord et l'écran Réseau affichent aujourd'hui des chiffres de
**démonstration**, et le disent à l'écran. Ils viennent de la caisse, pas des
stocks : ce module ne connaît ni encaissements ni tickets.

```php
performances(string $from, string $to): list<ShopPerformance>
currentShopId(): ?string
```

`ShopPerformance` : `id`, `name`, `country`, `revenue`, `customers`,
`tiramisuSold`, `currency`.

### 3. Recettes et matières — `RecipeServiceInterface`

**Lecture seule.** Le champ `recipeRef` de chaque fiche produit est une clé de
pont, prévue pour l'identifiant du produit côté TF Buddy ; elle ne pointe
encore sur rien.

```php
recipes(array $productIds): array   // [productId][materialId] => qtyPerUnit
materials(): list<Material>          // id, name (par langue), unit
```

Point à vérifier : les quantités doivent être exprimées dans l'unité de la
matière (g, ml, pièce). Le delta technique en dépend.

### 4. Identités et postes — `ConsultantServiceInterface`

Fonctionne aujourd'hui en local (`DbConsultantService`, Admin ▸ Équipe). C'est
donc la moins pressée — mais l'adaptateur est prêt à basculer.

```php
authenticateByPin(string $pin): ?Consultant
consultants(): list<Consultant>
workstations(): list<Workstation>
assignedWorkstation(string $consultantId): ?Workstation
```

⚠️ Les codes PIN sont stockés **hachés** (HMAC-SHA256, voir `PinHasher`).
L'authentification par PIN seul doit retrouver la personne sans savoir qui se
présente ; si TF Buddy expose un mécanisme d'authentification différent, c'est
lui qui fera foi et le hachage local disparaîtra.

## Et si l'API n'expose pas tout ça

Ce n'est pas bloquant. Chaque adaptateur a une implémentation locale qui
continue de tourner (`LocalRecipeService`, `LocalShopRankingService`,
`DbConsultantService`). On branche famille par famille, dans l'ordre ci-dessus,
et ce qui manque reste local — l'écran indique alors que les chiffres sont de
démonstration plutôt que de laisser croire à une mesure.
