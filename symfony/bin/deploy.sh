#!/usr/bin/env bash
#
# Déploiement / mise à jour de MERISU — à exécuter SUR LE SERVEUR.
#
#   ./bin/deploy.sh
#
# Le script est idempotent : il peut être relancé à chaque mise à jour.
# Il ne touche jamais aux données (comptages, audit) ni à .env.local.

set -euo pipefail

cd "$(dirname "$0")/.."
APP_DIR="$(pwd)"

echo "→ Déploiement de MERISU dans $APP_DIR"

# ── 0. Prérequis ────────────────────────────────────────────────────────────
# Vérifiés d'emblée : un échec ici est plus clair qu'une erreur PHP obscure
# trois étapes plus loin.

# Interpréteur à utiliser. Surchargeable pour viser une version précise sans
# toucher au PHP par défaut du système — utile quand d'autres sites du serveur
# tournent encore sur une version plus ancienne :
#
#   PHP_BIN=php8.3 bash bin/deploy.sh
PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "✗ « $PHP_BIN » est introuvable. Installer PHP 8.2 ou supérieur." >&2
    exit 1
fi

PHP_OK=$("$PHP_BIN" -r 'echo PHP_VERSION_ID >= 80200 ? 1 : 0;')
if [ "$PHP_OK" != "1" ]; then
    cat >&2 <<MSG
✗ PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') détecté ; 8.2 minimum requis
  (contrainte de Symfony 7).

  Si une version plus récente est déjà installée à côté, la désigner :
      PHP_BIN=php8.3 bash bin/deploy.sh

  Sinon, voir DEPLOIEMENT.md §0 pour l'installer sans modifier le PHP système.
MSG
    exit 1
fi

# `extension_loaded()` plutôt que « php -m » : les extensions du cœur (json
# depuis PHP 8) ne sont pas toujours listées par -m selon la compilation, ce qui
# faisait échouer la vérification sur des serveurs parfaitement valides.
for ext in json mbstring; do
    if [ "$("$PHP_BIN" -r "echo extension_loaded('$ext') ? 1 : 0;")" != "1" ]; then
        echo "✗ Extension PHP manquante pour $PHP_BIN : $ext" >&2
        exit 1
    fi
done

# Un pilote de base est indispensable : SQLite par défaut, PostgreSQL en option.
HAS_DB=$("$PHP_BIN" -r "echo (extension_loaded('pdo_sqlite') || extension_loaded('pdo_pgsql')) ? 1 : 0;")
if [ "$HAS_DB" != "1" ]; then
    echo "✗ Aucun pilote de base pour $PHP_BIN : installer pdo_sqlite ou pdo_pgsql." >&2
    exit 1
fi

# vendor/ n'est pas versionné : sans Composer, le déploiement depuis Git ne peut
# pas aboutir. Autant le dire tout de suite.
if [ ! -f vendor/autoload_runtime.php ] && ! command -v composer >/dev/null 2>&1; then
    cat >&2 <<'MSG'
✗ Ni vendor/ ni Composer ne sont présents.

  vendor/ n'est volontairement pas versionné. Deux solutions :
    · installer Composer sur le serveur  → https://getcomposer.org/download/
    · ou déployer l'archive fournie, qui embarque déjà vendor/
MSG
    exit 1
fi

# ── 1. Configuration ────────────────────────────────────────────────────────
if [ ! -f .env.local ]; then
    echo "→ .env.local absent : création avec un secret généré"
    SECRET="$("$PHP_BIN" -r 'echo bin2hex(random_bytes(24));')"
    cat > .env.local <<EOF
APP_ENV=prod
APP_SECRET=$SECRET
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/merisu.sqlite"
EOF
    echo "  ⚠️  Base SQLite par défaut. Pour PostgreSQL, éditer DATABASE_URL."
else
    echo "→ .env.local conservé tel quel"
fi

# ── 2. Dépendances ──────────────────────────────────────────────────────────
if command -v composer >/dev/null 2>&1; then
    echo "→ Installation des dépendances (sans les outils de développement)"

    # Composer désactive les plugins quand il tourne en root sans terminal —
    # le cas d'un déploiement automatisé. Or le plugin `symfony/runtime` est
    # celui qui écrit `vendor/autoload_runtime.php`, requis aussi bien par
    # `bin/console` que par `public/index.php` : sans lui, le site répond 500
    # et la console est inutilisable. On lève donc la garde, en connaissance
    # de cause, uniquement lorsqu'on est effectivement root.
    if [ "$(id -u)" = "0" ]; then
        export COMPOSER_ALLOW_SUPERUSER=1
    fi

    "$PHP_BIN" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction

    # Filet : si le fichier manque malgré tout, autant s'arrêter ici avec un
    # message clair plutôt que de laisser un 500 au poste de travail.
    if [ ! -f vendor/autoload_runtime.php ]; then
        echo "✗ vendor/autoload_runtime.php absent : le plugin symfony/runtime n'a pas tourné." >&2
        echo "  Relancer : COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader" >&2
        exit 1
    fi
else
    echo "→ Composer absent : vendor/ fourni par l'archive, étape ignorée"
fi

# ── 3. Dossiers de travail ──────────────────────────────────────────────────
mkdir -p var/data var/cache var/log public/uploads

# ── 4. Cache ────────────────────────────────────────────────────────────────
#
# AVANT toute commande applicative, et non après.
#
# Le conteneur compilé qui traîne dans var/cache/prod date du déploiement
# PRÉCÉDENT : il décrit les services tels qu'ils étaient. Lancer `merisu:seed`
# avant de le reconstruire revient à instancier le code neuf avec le plan de
# l'ancien, et le déploiement s'arrête sur un « Too few arguments » dès qu'un
# constructeur a gagné un argument.
#
# Le dossier est supprimé plutôt que simplement vidé : `cache:clear` doit
# lui-même démarrer le noyau, donc lire ce conteneur périmé. Tant qu'il est là,
# il peut faire échouer la commande censée s'en débarrasser.
echo "→ Reconstruction du cache"
rm -rf var/cache/prod
"$PHP_BIN" bin/console --env=prod cache:clear

# ── 5. Schéma et données ────────────────────────────────────────────────────
echo "→ Application du schéma (idempotente, sans écraser les saisies)"
"$PHP_BIN" bin/console --env=prod merisu:seed

# ── 6. Droits d'écriture ────────────────────────────────────────────────────
#
# APRÈS le cache et le schéma : ces deux étapes tournent en root et créent des
# fichiers qui lui appartiennent — le cache reconstruit, la base à la première
# installation. Attribuer les droits avant les laissait hors de portée du
# serveur web.
#
# Le compte du serveur web doit pouvoir écrire le cache, la base et les photos.
WEB_USER="${WEB_USER:-www-data}"
if id "$WEB_USER" >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
    echo "→ Attribution des droits à $WEB_USER"
    # Utilisateur seul, sans groupe : le groupe ne porte pas toujours le même
    # nom que le compte (www-data/www-data mais nobody/nogroup).
    chown -R "$WEB_USER" var public/uploads
fi
chmod -R u+rwX,g+rwX var public/uploads

cat <<'EOF'

✔ Déploiement terminé.

Racine web à faire pointer sur :  public/
Reste à vérifier (voir DEPLOIEMENT.md) :
  · HTTPS valide — sans lui, pas de mode hors-ligne ni d'installation PWA
  · retrait des comptes de démonstration avant ouverture aux utilisateurs
  · seuil du limiteur de connexion adapté au nombre de postes du site
EOF
