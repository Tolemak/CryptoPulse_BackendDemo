#!/usr/bin/env bash
set -euo pipefail

image="$1"
work="$(mktemp -d)"
name="cryptopulse-smoke-$$"
trap 'docker rm -f "$name" >/dev/null 2>&1 || true; rm -rf "$work"' EXIT

: > "$work/env"
chmod 0644 "$work/env"

tmpfs="rw,noexec,nosuid,nodev,mode=0700,uid=12345,gid=12345"
flags=(
  --network=none --read-only --cap-drop=ALL --security-opt=no-new-privileges:true
  --user 12345:12345 --memory 512m
  --tmpfs "/run/apache2:$tmpfs,size=8m"
  --tmpfs "/run/lock/apache2:$tmpfs,size=8m"
  --tmpfs "/tmp:$tmpfs,size=64m"
  --tmpfs "/var/www/site/var:$tmpfs,size=64m"
  -e APP_SECRET=smoke-test-secret
  -e REDIS_URL=redis://127.0.0.1:1
)

docker run --rm "${flags[@]}" "$image" sh -c '
  php -m | grep -qx mbstring
  php -r "require \"vendor/autoload.php\";"
  test -r .env
  grep -qx APP_ENV=prod .env
  ! grep -Eq "SECRET|PASSWORD|TOKEN|DSN" .env
  test ! -w composer.json
  . /etc/apache2/envvars
  apache2 -t
'

docker run --rm "${flags[@]}" -v "$work/env:/var/www/site/.env:ro" "$image" php -r "require \"vendor/autoload.php\"; exit(is_readable(\"/var/www/site/.env\") ? 0 : 1);"

docker run -d --name "$name" "${flags[@]}" "$image" >/dev/null

probe='$c = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 5]]);
$body = @file_get_contents("http://127.0.0.1:8080/api/health", false, $c);
$head = implode("\n", $http_response_header ?? []);
$json = json_decode((string) $body, true);
$status = (int) substr($http_response_header[0] ?? "000 000", 9, 3);
echo in_array($status, [200, 503], true) && stripos($head, "Content-Type: application/json") !== false && is_array($json) && array_key_exists("ok", $json) ? "ok" : "fail";'

result=fail
for _ in $(seq 1 30); do
  result="$(docker exec "$name" php -r "$probe" 2>/dev/null || true)"
  [ "$result" = ok ] && break
  sleep 1
done

docker logs "$name"
status="$(docker inspect --format '{{.State.Health.Status}}' "$name")"
echo "kernel probe: $result, health: $status"
test "$result" = ok
