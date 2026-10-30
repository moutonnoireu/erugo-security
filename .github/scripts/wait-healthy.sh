#!/bin/sh
# Usage: wait-healthy.sh <container> <base url>
# Waits up to 3 minutes for /api/health, reports the container logs on failure.
name="$1"; url="$2"
for i in $(seq 1 90); do
  if curl -fsS "$url/api/health" > /dev/null 2>&1; then
    curl -fsS "$url/api/health"; echo
    curl -fsS -o /dev/null -w "home page: %{http_code}\n" "$url/"
    exit 0
  fi
  if [ "$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null)" != "true" ]; then
    echo "container $name stopped"; break
  fi
  sleep 2
done
docker logs "$name" 2>&1 | tail -n 80
LOGS=$(docker logs "$name" 2>&1 | tail -n 25 | sed -e 's/%/%25/g' | sed -e ':a;N;$!ba;s/\n/%0A/g')
echo "::error title=$name did not answer on /api/health::$LOGS"
exit 1
