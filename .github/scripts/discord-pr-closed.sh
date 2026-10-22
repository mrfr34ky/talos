#!/usr/bin/env bash
# Posts a closed PR to Discord. Used by pr-closed-discord.yaml and automerge.yaml (merges made with
# GITHUB_TOKEN don't trigger other workflows, so Automerge has to post its own).
# Env: WEBHOOK, NUMBER, TITLE, URL, MERGED (true/false), MERGED_BY
set -euo pipefail

if [ -z "${WEBHOOK:-}" ]; then
  echo "DISCORD_PR_WEBHOOK is not set, skipping"
  exit 0
fi

if [ "$MERGED" = "true" ]; then
  status="✅ Merged by $MERGED_BY"; color=5763719
else
  status="❌ Closed without merging"; color=15548997
fi

jq -n --arg t "#$NUMBER $TITLE" --arg u "$URL" --arg d "$status" --argjson c "$color" \
  '{embeds: [{title: ($t | .[0:256]), url: $u, description: $d, color: $c}]}' |
  curl -sS -f -H 'Content-Type: application/json' -d @- "$WEBHOOK"
