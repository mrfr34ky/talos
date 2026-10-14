#!/bin/sh
# apcupsd -> Discord notifications for the Proxmox host.
#
# Installed on Proxmox (as root):
#   /etc/apcupsd/discord-notify.sh             this file (chmod 700)
#   /etc/apcupsd/discord.env                   DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/...
#                                              (chmod 600; never put it in git)
#   /etc/apcupsd/<event> -> discord-notify.sh  one symlink per event, originals kept as <event>.orig
# apccontrol runs /etc/apcupsd/<event> on every UPS event; the symlink's name tells this script which
# event fired. It always exits 0 (never 99), so apcupsd's own actions (wall message, shutdown) still run.
#
# Test: /etc/apcupsd/discord-notify.sh test
set -u

event=$(basename "$0")
[ "$event" = "discord-notify.sh" ] && event="${1:-test}"

ENV_FILE=/etc/apcupsd/discord.env
[ -r "$ENV_FILE" ] && . "$ENV_FILE"
if [ -z "${DISCORD_WEBHOOK_URL:-}" ]; then
  logger -t apcupsd-discord "DISCORD_WEBHOOK_URL not set in $ENV_FILE, not sending '$event'"
  exit 0
fi

case "$event" in
  onbattery)   color=15158332; title="⚡ Power failure, the UPS is on battery" ;;
  offbattery)  color=3066993;  title="✅ Power is back, the UPS is on mains again" ;;
  commfailure) color=15105570; title="⚠️ Lost communication with the UPS" ;;
  commok)      color=3066993;  title="✅ Communication with the UPS restored" ;;
  changeme)    color=15105570; title="🔋 The UPS battery needs replacing" ;;
  doshutdown)  color=15158332; title="🛑 UPS battery critical, Proxmox is shutting down" ;;
  test)        color=3447003;  title="🧪 Test notification from apcupsd" ;;
  *)           color=3447003;  title="ℹ️ UPS event: $event" ;;
esac

APCACCESS=$(command -v apcaccess || echo /sbin/apcaccess)
status=$("$APCACCESS" status 2>/dev/null || true)

# Value of one apcaccess field, without characters that would break the JSON
field() {
  value=$(printf '%s\n' "$status" | sed -n "s/^$1 *: *//p" | head -n 1 | tr -d '"\\')
  printf '%s' "${value:-n/a}"
}

payload=$(cat <<EOF
{"username": "UPS", "embeds": [{
  "title": "$title",
  "color": $color,
  "fields": [
    {"name": "Status", "value": "$(field STATUS)", "inline": true},
    {"name": "Battery", "value": "$(field BCHARGE)", "inline": true},
    {"name": "Runtime left", "value": "$(field TIMELEFT)", "inline": true},
    {"name": "Load", "value": "$(field LOADPCT)", "inline": true},
    {"name": "Line voltage", "value": "$(field LINEV)", "inline": true},
    {"name": "Last transfer", "value": "$(field LASTXFER)", "inline": true}
  ],
  "footer": {"text": "$(hostname) · $(field MODEL)"},
  "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}]}
EOF
)

# Retry a few times (the network may be flaky during a power cut), except when shutting down
attempts=3
[ "$event" = "doshutdown" ] && attempts=1
i=1
while [ "$i" -le "$attempts" ]; do
  if curl -fsS --max-time 10 -H 'Content-Type: application/json' -d "$payload" "$DISCORD_WEBHOOK_URL" >/dev/null; then
    exit 0
  fi
  i=$((i + 1))
  [ "$i" -le "$attempts" ] && sleep 5
done

logger -t apcupsd-discord "could not send '$event' to Discord"
exit 0
