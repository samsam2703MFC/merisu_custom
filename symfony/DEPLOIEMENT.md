# Déploiement — MERISU Inventaire & Production

L'application fonctionne indifféremment à la racine d'un domaine ou dans un
sous-répertoire (`/merisu/`, `/kitchen/merisu/`…) : le manifeste PWA, le service
worker et toutes les URL suivent automatiquement le préfixe d'installation.

---

## 0. Déploiement depuis Git (recommandé)

Le dépôt est **public** : aucun identifiant n'est nécessaire pour le cloner.

```bash
# Sur le serveur, à l'emplacement voulu
git clone https://github.com/samsam2703MFC/merisu_custom.git /var/www/merisu-src
cd /var/www/merisu-src/symfony

bash bin/deploy.sh
```

`bin/deploy.sh` vérifie les prérequis (PHP ≥ 8.2, extensions, pilote de base,
Composer), crée `.env.local` avec un secret généré s'il est absent, installe les
dépendances, pose les droits, applique le schéma et reconstruit le cache.

Reste à faire pointer la racine web sur `/var/www/merisu-src/symfony/public`
(voir §5), puis à parcourir la liste de contrôle du §7.

### Mises à jour

```bash
cd /var/www/merisu-src && git pull
cd symfony && bash bin/deploy.sh
```

Le script ne touche jamais à `.env.local` ni aux données : comptages, photos et
journal d'audit sont préservés.

> **Prérequis sur le serveur** : PHP 8.2 ou supérieur, Composer, et l'extension
> `pdo_sqlite` (ou `pdo_pgsql`). `vendor/` n'est volontairement pas versionné.
> Si Composer n'est pas installable sur le serveur, utiliser l'archive de
> production fournie, qui embarque déjà les dépendances — la suite de ce
> document décrit cette voie.

### Installer PHP 8.3 sur Ubuntu 22.04 sans casser l'existant

Ubuntu 22.04 livre PHP 8.1, insuffisant pour Symfony 7 — et **PHP 8.1 ne reçoit
plus de correctifs de sécurité depuis fin 2025**. Le dépôt `ondrej/php` permet
d'installer 8.3 **à côté** de 8.1, sans toucher aux autres sites du serveur :

```bash
apt update
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update

# 8.3 s'installe en parallèle de 8.1, qui reste le PHP par défaut du système.
apt install -y php8.3-cli php8.3-fpm php8.3-mbstring php8.3-xml \
               php8.3-sqlite3 php8.3-intl php8.3-curl php8.3-zip
```

`php8.3-intl` n'est pas strictement obligatoire (le code sait s'en passer), mais
sans lui les dates longues perdent leur nom de jour traduit en PL, IT et ES.

Déployer ensuite en désignant explicitement l'interpréteur :

```bash
PHP_BIN=php8.3 bash bin/deploy.sh
```

⚠️ **Ne pas changer le PHP par défaut** (`update-alternatives`) si d'autres
applications tournent sur ce serveur : elles pourraient cesser de fonctionner.
Il suffit de faire pointer le *pool* FPM de MERISU sur 8.3 dans la
configuration du serveur web (§5) :

```nginx
fastcgi_pass unix:/run/php/php8.3-fpm.sock;
```

Sous Apache avec `mod_php`, deux versions ne peuvent pas cohabiter : passer par
PHP-FPM pour ce site précis.

```apache
<FilesMatch \.php$>
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
</FilesMatch>
```

**Autre voie possible**, si la version de PHP ne peut pas bouger : rétrograder
l'application vers Symfony 6.4 LTS, qui accepte PHP 8.1. C'est un travail réel
(ajustement des dépendances, des classes `readonly` et nouvelle campagne de
tests) et cela adosserait le module à un PHP sans support de sécurité — à ne
retenir qu'en dernier recours.

---

## 1. Déposer les fichiers (voie manuelle, sans Git)

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

### Apache (+ PHP-FPM)

`public/.htaccess` est fourni et gère seul le sous-répertoire. Configuration
complète pour servir l'application sous `/merisu/` d'un site existant, en la
faisant tourner sur PHP 8.3 **sans toucher au PHP des autres sites** :

```apache
# /etc/apache2/conf-available/merisu.conf
Alias /merisu /var/www/merisu-src/symfony/public

<Directory /var/www/merisu-src/symfony/public>
    AllowOverride All
    Require all granted
    Options -MultiViews +FollowSymLinks

    # Ce répertoire seulement passe par PHP 8.3 ; les autres sites du serveur
    # continuent d'utiliser leur propre version.
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
</Directory>
```

Activation :

```bash
a2enmod rewrite proxy proxy_fcgi setenvif
a2enconf merisu
apachectl configtest && systemctl reload apache2
```

Placer le fichier dans `conf-available/` plutôt que dans un vhost précis le
rend actif sur les vhosts HTTP **et** HTTPS du serveur, sans les éditer.

> ⚠️ **Ne jamais mettre de bloc `<Directory>` dans un `.htaccess`** : la
> directive y est interdite et Apache renvoie alors une erreur 500 sur toutes
> les requêtes (`<Directory not allowed here`). Les blocs `<Directory>`
> n'appartiennent qu'à la configuration du serveur, comme ci-dessus.

Les photos téléversées sont protégées par `public/uploads/.htaccess`, qui
neutralise tout gestionnaire hérité et refuse les extensions exécutables :
un fichier `.php` déposé dans ce dossier renvoie 403, une image reste servie
normalement.

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

- [ ] **Changer les codes PIN et masquer leur rappel.** Les valeurs par défaut
      sont publiées dans le dépôt : tant qu'elles sont en place, quiconque
      atteint la page de connexion est administrateur. Dans `.env.local` :

      ```dotenv
      MERISU_SHOW_DEMO_ACCOUNTS=0
      MERISU_ADMIN_PIN=418302
      MERISU_CONSULTANT1_PIN=735914
      MERISU_CONSULTANT2_PIN=260487
      ```

      Puis `php bin/console cache:clear --env=prod`. Les codes doivent rester
      **uniques** entre comptes : ils constituent à eux seuls l'identité, et
      l'audit deviendrait faux si deux personnes partageaient le même.
      Générer des codes : `php -r 'echo random_int(100000, 999999), PHP_EOL;'`

      Ces comptes disparaîtront de toute façon au branchement du vrai module
      Consultant (voir README).
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
