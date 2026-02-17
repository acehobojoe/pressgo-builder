#!/bin/bash
# Build PressGo pages from pre-generated JSON configs
# Usage: bash test/build-from-configs.sh [id1 id2 ...]

WP_PATH="/var/www/wp.pressgo.app/htdocs"
SITE="https://wp.pressgo.app"
CONFIG_DIR="$(dirname "$0")/configs"

# Title lookup (portable — no associative arrays needed)
get_title() {
  case "$1" in
    hvac-01)       echo "ComfortZone HVAC" ;;
    daycare-01)    echo "Sunshine Kids Academy" ;;
    moving-01)     echo "SwiftMove Relocation" ;;
    roofing-01)    echo "Summit Roofing Co" ;;
    landscape-01)  echo "GreenScape Design" ;;
    therapy-01)    echo "Recover Physical Therapy" ;;
    church-01)     echo "Grace Community Church" ;;
    camp-01)       echo "Trailblazer Summer Camp" ;;
    spa-01)        echo "Serenity Day Spa" ;;
    insurance-01)  echo "TrustShield Insurance" ;;
    supplement-01) echo "VitaForce Supplements" ;;
    fashion-01)    echo "Thread & Needle" ;;
    candle-01)     echo "Lumiere Candle Co" ;;
    mattress-01)   echo "DreamCloud Sleep" ;;
    saas-05)       echo "HireWise Recruiting" ;;
    saas-06)       echo "DataPulse Analytics" ;;
    saas-07)       echo "Chatly Support" ;;
    app-01)        echo "ParkEasy App" ;;
    saas-08)       echo "EmailCraft" ;;
    crypto-01)     echo "BlockVault Wallet" ;;
    saas-09)       echo "DesignDeck" ;;
    saas-10)       echo "LogiTrack Fleet" ;;
    ai-01)         echo "WriteGenius AI" ;;
    *)             echo "$1" ;;
  esac
}

# Use args or default to all configs
if [ $# -gt 0 ]; then
  IDS=("$@")
else
  IDS=()
  for f in "$CONFIG_DIR"/*.json; do
    id=$(basename "$f" .json)
    IDS+=("$id")
  done
fi

OK=0
FAIL=0

for id in "${IDS[@]}"; do
  config="$CONFIG_DIR/${id}.json"
  title="$(get_title "$id")"
  slug="pressgo-test-${id}"

  if [ ! -f "$config" ]; then
    echo "[SKIP] $id: no config file"
    continue
  fi

  echo ""
  echo "[CFG] ${id}: \"${title}\""

  # Delete existing page if any
  existing=$(ssh digitalocean "wp --path=${WP_PATH} post list --post_type=page --name=${slug} --field=ID --allow-root 2>/dev/null" 2>/dev/null | head -1)
  if [ -n "$existing" ] && [ "$existing" -gt 0 ] 2>/dev/null; then
    echo "      Deleting existing (ID ${existing})..."
    ssh digitalocean "wp --path=${WP_PATH} post delete ${existing} --force --allow-root" 2>/dev/null
  fi

  # Upload config
  scp "$config" "digitalocean:/tmp/pressgo-config-${id}.json" 2>/dev/null

  # Build page
  output=$(ssh digitalocean "wp --path=${WP_PATH} pressgo generate --config=/tmp/pressgo-config-${id}.json --title='${title}' --allow-root 2>&1")

  # Extract post ID
  post_id=$(echo "$output" | grep -o 'Post ID: [0-9]*' | grep -o '[0-9]*')
  sec_count=$(echo "$output" | grep -o '[0-9]* sections' | head -1 | grep -o '[0-9]*')
  wgt_count=$(echo "$output" | grep -o '[0-9]* total widgets' | grep -o '[0-9]*')

  if [ -n "$post_id" ]; then
    # Set slug
    ssh digitalocean "wp --path=${WP_PATH} post update ${post_id} --post_name=${slug} --allow-root" 2>/dev/null
    echo "      OK: ${sec_count} sections, ${wgt_count} widgets → ${SITE}/${slug}/"
    OK=$((OK + 1))
  else
    echo "      FAIL"
    echo "$output" | tail -3
    FAIL=$((FAIL + 1))
  fi

  # Cleanup remote config
  ssh digitalocean "rm -f /tmp/pressgo-config-${id}.json" 2>/dev/null
done

echo ""
echo "=== Results: ${OK} OK, ${FAIL} Failed ==="
