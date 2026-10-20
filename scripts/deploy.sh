#!/usr/bin/env bash
# Despliegue de educacionbello.gov.co
# Uso (en el servidor): ./scripts/deploy.sh
set -euo pipefail

APP_DIR="/var/www/bello/app/educacionbello/educacionbello"
BACKUP_DIR="$HOME/backups/educacionbello"
KEEP=10
DRUSH="$APP_DIR/vendor/bin/drush"

cd "$APP_DIR"

echo "==> Verificando que no haya cambios locales"
if [ -n "$(git status --porcelain)" ]; then
  echo "ERROR: hay cambios sin commit en el servidor:" >&2
  git status -s >&2
  exit 1
fi

echo "==> Respaldando base de datos"
mkdir -p "$BACKUP_DIR"
STAMP=$(date +%Y%m%d-%H%M%S)
COMMIT=$(git rev-parse --short HEAD)
"$DRUSH" sql:dump --gzip --result-file="$BACKUP_DIR/db-$STAMP-$COMMIT.sql"
ls -1t "$BACKUP_DIR"/db-*.sql.gz | tail -n +$((KEEP + 1)) | xargs -r rm --

echo "==> Activando modo mantenimiento"
"$DRUSH" state:set system.maintenance_mode 1 --input-format=integer
trap '"$DRUSH" state:set system.maintenance_mode 0 --input-format=integer; echo "ERROR: despliegue interrumpido, mantenimiento desactivado. Respaldo: $BACKUP_DIR/db-$STAMP-$COMMIT.sql.gz" >&2' ERR

echo "==> Actualizando código"
git pull --ff-only

echo "==> Instalando dependencias"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> drush deploy (updb + cim + cr + deploy hooks)"
"$DRUSH" deploy -y

echo "==> Desactivando modo mantenimiento"
"$DRUSH" state:set system.maintenance_mode 0 --input-format=integer
trap - ERR
"$DRUSH" cr

echo "==> Despliegue completo: $(git log --oneline -1)"
