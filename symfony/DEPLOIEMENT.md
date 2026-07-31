# Déploiement — MERISU Inventaire & Production

L'application fonctionne indifféremment à la racine d'un domaine ou dans un
sous-répertoire (`/merisu/`, `/kitchen/merisu/`…) : le manifeste PWA, le service
worker et toutes les URL suivent automatiquement le préfixe d'installation.

---

## 1. Déposer les fichiers

Copier le contenu de `symfony/` sur le serveur, par exemple dans
`/var/www/merisu/`. **La racine web doit pointer sur `symfony/public/`**, jamais
sur `symfony/` : tout le reste (code, base, configuration) doit rester hors de
portée du navigateur.

```
/var/www/merisu/
├── public/     ← racine web
├── src/  config/  templates/  translations/  vendor/
└── var/        ← cache et base SQLite (à rendre inscriptible)
```

Si `vendor/` n'est pas inclus :

```bash
composer install --no-dev --optimize-autoloader
```

## 2. Configurer

Créer `.env.local` à côté de `.env` :

```dotenv
APP_ENV=prod
APP_SECRET=<chaîne aléatoire de 32+ caractères>

# SQLite convient pour un site unique ; PostgreSQL au-delà.
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/merisu.sqlite"
# DATABASE_URL="postgresql://user:motdepasse@localhost:5432/merisu"
```

Générer un secret : `php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'`

> ⚠️ Le serveur **refuse de démarrer** en `APP_ENV=prod` avec le secret de
> développement laissé en place.

## 3. Droits d'écriture

```bash
chown -R www-data:www-data var public/uploads
chmod -R u+rwX var public/uploads
```

## 4. Installer le schéma et les données de démonstration

```bash
php bin/console merisu:seed
php bin/console cache:clear --env=prod
```

`merisu:seed` est idempotent : il crée le schéma, les 8 emplacements produits et
une matrice de seuils **fictive**, sans jamais écraser des saisies réelles.

## 5. Serveur web

### Apache

`public/.htaccess` est fourni et gère seul le sous-répertoire. Il faut
simplement `mod_rewrite` activé et `AllowOverride All` sur le répertoire :

```apache
<Directory /var/www/merisu/public>
    AllowOverride All
    Require all granted
</Directory>
```

Pour servir l'application sous `/merisu/` d'un site existant :

```apache
Alias /merisu /var/www/merisu/public
```

### Nginx

```nginx
location ^~ /merisu/ {
    alias /var/www/merisu/public/;

    # Les fichiers existants sont servis directement.
    try_files $uri @merisu;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/merisu/public/index.php;
        # SCRIPT_NAME porte le préfixe : c'est lui qui permet à l'application
        # de construire les bonnes URL en sous-répertoire.
        fastcgi_param SCRIPT_NAME /merisu/index.php;
    }
}

location @merisu {
    rewrite ^/merisu/(.*)$ /merisu/index.php last;
}

# Les photos de comptage ne doivent jamais être exécutées.
location ~ ^/merisu/uploads/.*\.php$ { deny all; }
```

## 6. HTTPS — obligatoire

Un service worker ne s'installe **que** sur une origine sécurisée. Sans HTTPS
valide :

- pas d'installation sur l'écran d'accueil ;
- **pas de fonctionnement hors-ligne** — or c'est une exigence du cahier des
  charges : le consultant compte au poste, parfois sans réseau.

Un certificat auto-signé ne suffit pas : le navigateur refusera l'installation.
Prévoir Let's Encrypt, ce qui suppose un nom de domaine — une adresse IP nue ne
peut pas obtenir de certificat reconnu.

## 7. Avant d'ouvrir aux utilisateurs

- [ ] **Retirer les comptes de démonstration.** Ils sont dans
      `src/Adapter/LocalConsultantService.php` et disparaîtront au branchement du
      vrai module Consultant. Tant qu'ils existent, n'importe qui connaissant
      `000000` est administrateur.
- [ ] Supprimer le rappel des codes sur l'écran de connexion
      (`templates/security/login.html.twig`, bloc `login.demoTitle`).
- [ ] Ajuster le limiteur de tentatives (`config/packages/rate_limiter.yaml`)
      selon le nombre de postes partageant l'adresse IP publique du site.
- [ ] Remplacer les 8 produits et la matrice de seuils fictifs par les vraies
      données, dans _Admin ▸ Produits_ et _Admin ▸ Seuils_.
- [ ] Sauvegarder `var/data/merisu.sqlite` (ou la base PostgreSQL) : elle
      contient les comptages et le journal d'audit.

## 8. Mise à jour

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console merisu:seed        # idempotent : applique les évolutions de schéma
```

Le service worker porte un numéro de version (`VERSION` dans
`templates/pwa/sw.js.twig`) : l'incrémenter à chaque changement d'assets force
les navigateurs à recharger le cache.
