#!/bin/bash
# ============================================
# BOUS'TACOM — Script de sync FTP
# ============================================
# Usage : ./sync.sh
# Uploade les fichiers modifies vers le serveur
# ============================================

# === REMPLIS TES INFOS FTP ICI ===
FTP_HOST="za121.ftp.infomaniak.com"
FTP_USER="za121_boustacom25111999"
FTP_PASS="MB.pro.com.1999."
FTP_REMOTE_DIR="/sites/boustacom.fr/app"
# =================================

LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

# Couleurs
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo ""
echo "=========================================="
echo "  BOUS'TACOM — Sync vers le serveur"
echo "=========================================="
echo ""

# Liste des fichiers a synchroniser
FILES=(
    "app/index.php"
    "app/config.php"
    "app/includes/functions.php"
    "app/api/track-keywords.php"
    "app/api/grid.php"
    "app/api/keywords.php"
    "app/api/locations.php"
    "app/api/reviews.php"
    "app/api/reviews-all.php"
    "app/api/posts.php"
    "app/api/scan-async.php"
    "app/assets/js/app.js"
    "app/assets/css/style.css"
    "app/cron/scan-grids.php"
    "app/cron/sync-locations.php"
    "app/cron/sync-reviews.php"
    "app/api/stats.php"
    "app/api/prospects.php"
    "app/cron/sync-stats.php"
    "app/cron/auto-reply-reviews.php"
    "app/cron/fix-columns.php"
    "app/tmp/.htaccess"
    "app/views/sidebar.php"
    "app/views/dashboard.php"
    "app/views/client.php"
    "app/views/reviews-all.php"
    "app/views/reports.php"
    "app/views/acquisition.php"
    "app/views/settings.php"
    "app/views/locations.php"
)

# Verification des fichiers locaux
echo -e "${YELLOW}Verification des fichiers locaux...${NC}"
for f in "${FILES[@]}"; do
    if [ ! -f "$LOCAL_DIR/$f" ]; then
        echo -e "${RED}  ✗ MANQUANT : $f${NC}"
        exit 1
    fi
    SIZE=$(wc -c < "$LOCAL_DIR/$f" | tr -d ' ')
    echo -e "  ✓ $f (${SIZE} octets)"
done
echo ""

# Upload via curl (FTP)
echo -e "${YELLOW}Upload vers $FTP_HOST...${NC}"
ERRORS=0

for f in "${FILES[@]}"; do
    # Determiner le chemin distant
    # Si FTP_REMOTE_DIR = /app, on enleve le prefixe "app/" du fichier
    RELATIVE="${f#app/}"
    REMOTE_PATH="${FTP_REMOTE_DIR}/${RELATIVE}"

    echo -n "  ↑ $f → $REMOTE_PATH ... "

    # Upload via curl FTP avec TLS explicite (STARTTLS port 21)
    RESULT=$(curl -s -S --ftp-create-dirs --ftp-pasv \
        --ftp-ssl --insecure \
        --connect-timeout 30 \
        -T "$LOCAL_DIR/$f" \
        "ftp://${FTP_HOST}${REMOTE_PATH}" \
        --user "${FTP_USER}:${FTP_PASS}" \
        2>&1)

    if [ $? -eq 0 ]; then
        echo -e "${GREEN}OK${NC}"
    else
        echo -e "${RED}ERREUR${NC}"
        echo "    $RESULT"
        ERRORS=$((ERRORS + 1))
    fi
done

echo ""
if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}=========================================="
    echo -e "  ✓ Tous les fichiers ont ete uploades !"
    echo -e "==========================================${NC}"
else
    echo -e "${RED}=========================================="
    echo -e "  ✗ $ERRORS fichier(s) en erreur"
    echo -e "==========================================${NC}"
fi
echo ""
