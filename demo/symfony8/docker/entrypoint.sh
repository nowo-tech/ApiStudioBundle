#!/bin/sh
set -e

# FRANKENPHP_MODE: classic | worker (REQ-DEMO-010). Default: worker.
# Set via .env / Compose only — not baked into the image ENV.
# FrankenPHP always runs with --config /etc/frankenphp/Caddyfile.
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		if [ -f /app/Caddyfile.dev ]; then
			cp /app/Caddyfile.dev /etc/frankenphp/Caddyfile
		elif [ -f /app/docker/frankenphp/Caddyfile.dev ]; then
			cp /app/docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		elif [ -f /etc/frankenphp/Caddyfile.dev ]; then
			cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		fi
		;;
	worker)
		if [ -f /app/docker/frankenphp/Caddyfile ]; then
			cp /app/docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
		elif [ -f /app/Caddyfile ]; then
			cp /app/Caddyfile /etc/frankenphp/Caddyfile
		fi
		# else keep image-baked worker Caddyfile
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

git config --global --add safe.directory /app 2>/dev/null || true
git config --global --add safe.directory /var/api-studio-bundle 2>/dev/null || true
mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var 2>/dev/null || true

if [ ! -f /app/vendor/autoload_runtime.php ]; then
  i=0
  until composer install --no-interaction --prefer-dist; do
    i=$((i+1))
    if [ "$i" -ge 5 ]; then echo "composer install failed after 5 attempts" >&2; exit 1; fi
    echo "composer install failed, retry $$i in 8s..." >&2
    sleep 8
  done
fi
if [ -f /app/bin/console ]; then
  php /app/bin/console nowo:api-studio:sync-schema --no-interaction 2>/dev/null || true
  php /app/bin/console nowo:api-studio:seed-demo --no-interaction 2>/dev/null || true
fi
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
