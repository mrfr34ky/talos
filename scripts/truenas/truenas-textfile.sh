#!/usr/bin/env bash
# TrueNAS facts for node-exporter's textfile collector (node-exporter runs as a TrueNAS custom app
# with --collector.textfile.directory pointing at this directory). Scraped by the
# "truenas-node-exporter" ScrapeConfig; used by the kromgo TrueNAS badge and the truenas PrometheusRule.
#
# Run as root from a TrueNAS cron job (System > Advanced Settings > Cron Jobs), every 5 minutes:
#   bash /mnt/main/apps/node-exporter/truenas-textfile.sh
set -euo pipefail

dir="${1:-/mnt/main/apps/node-exporter}"
out="$dir/truenas.prom"

version="$(midclt call system.version_short)"
pools="$(zpool list -Hp -o name,size,allocated)"

# Write to a temp file that doesn't end in .prom (the collector ignores it), then rename atomically.
tmp="$(mktemp "$out.XXXXXX")"
trap 'rm -f "$tmp"' EXIT
{
  echo "# HELP truenas_info TrueNAS version, always 1."
  echo "# TYPE truenas_info gauge"
  echo "truenas_info{version=\"$version\"} 1"
  echo "# HELP truenas_zpool_size_bytes Pool size as reported by zpool list (includes parity)."
  echo "# TYPE truenas_zpool_size_bytes gauge"
  while IFS=$'\t' read -r name size _; do
    echo "truenas_zpool_size_bytes{zpool=\"$name\"} $size"
  done <<<"$pools"
  echo "# HELP truenas_zpool_allocated_bytes Pool allocated space as reported by zpool list (includes parity)."
  echo "# TYPE truenas_zpool_allocated_bytes gauge"
  while IFS=$'\t' read -r name _ alloc; do
    echo "truenas_zpool_allocated_bytes{zpool=\"$name\"} $alloc"
  done <<<"$pools"
} >"$tmp"
chmod 0644 "$tmp"
mv "$tmp" "$out"
