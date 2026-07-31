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

if ! command -v php >/dev/null 2>&1; then
    echo "✗ PHP est introuvable. Installer PHP 8.2 ou supérieur." >&2
    exit 1
fi

PHP_OK=$(php -r 'echo PHP_VERSION_ID >= 80200 ? 1 : 0;')
if [ "$PHP_OK" != "1" ]; then
    echo "✗ PHP $(php -r 'echo PHP_VERSION;') détecté ; 8.2 minimum requis." >&2
    exit 1
fi

for ext in json mbstring; do
    if ! php -m | grep -qix "$ext"; then
        echo "✗ Extension PHP manquante : $ext" >&2
        exit 1
    fi
done

# Un pilote de base est indispensable : SQLite par défaut, PostgreSQL en option.
if ! php -m | grep -qix "pdo_sqlite" && ! php -m | grep -qix "pdo_pgsql"; then
    echo "✗ Aucun pilote de base disponible : installer pdo_sqlite ou pdo_pgsql." >&2
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
    SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
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
    composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "→ Composer absent : vendor/ fourni par l'archive, étape ignorée"
fi

# ── 3. Droits d'écriture ────────────────────────────────────────────────────
mkdir -p var/data var/cache var/log public/uploads

# Le compte du serveur web doit pouvoir écrire le cache, la base et les photos.
WEB_USER="${WEB_USER:-www-data}"
if id "$WEB_USER" >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
    echo "→ Attribution des droits à $WEB_USER"
    # Utilisateur seul, sans groupe : le groupe ne porte pas toujours le même
    # nom que le compte (www-data/www-data mais nobody/nogroup).
    chown -R "$WEB_USER" var public/uploads
fi
chmod -R u+rwX,g+rwX var public/uploads

# ── 4. Schéma et données ────────────────────────────────────────────────────
echo "→ Application du schéma (idempotente, sans écraser les saisies)"
php bin/console --env=prod merisu:seed

# ── 5. Cache ────────────────────────────────────────────────────────────────
echo "→ Reconstruction du cache"
php bin/console --env=prod cache:clear

cat <<'EOF'

✔ Déploiement terminé.

Racine web à faire pointer sur :  public/
Reste à vérifier (voir DEPLOIEMENT.md) :
  · HTTPS valide — sans lui, pas de mode hors-ligne ni d'installation PWA
  · retrait des comptes de démonstration avant ouverture aux utilisateurs
  · seuil du limiteur de connexion adapté au nombre de postes du site
EOF
