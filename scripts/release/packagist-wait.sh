#!/usr/bin/env bash
set -euo pipefail

tag=${1:-}
url=${PACKAGIST_URL:-https://repo.packagist.org/p2/astrophp/verse.json}
timeout_seconds=${PACKAGIST_TIMEOUT_SECONDS:-1200}
sleep_seconds=${PACKAGIST_SLEEP_SECONDS:-30}

# Packagist serves this file from a CDN with max-age=900, so allow well over 15 minutes.
for value in "$timeout_seconds" "$sleep_seconds"; do
    case "$value" in ''|*[!0-9]*) printf 'Packagist wait settings must be non-negative integers.\n' >&2; exit 2;; esac
done

deadline=$(($(date +%s) + timeout_seconds))
attempt=0
reason=

while :; do
    attempt=$((attempt + 1))
    poll_url=$url
    case "$url" in http*) poll_url="$url?cb=$(date +%s)";; esac
    if metadata=$(curl --fail --silent --show-error --location "$poll_url" 2>&1); then
        status=0
        printf '%s' "$metadata" | php -r '
            $tag = $argv[1]; $plain = substr($tag, 1);
            preg_match("/^(\\d+)\\.(\\d+)\\.(\\d+)(?:-(.+))?$/", $plain, $parts);
            $normalized = isset($parts[1]) ? "$parts[1].$parts[2].$parts[3].0".(!empty($parts[4]) ? "-$parts[4]" : "") : $plain;
            $data = json_decode(stream_get_contents(STDIN), true);
            if (!is_array($data)) exit(3);
            foreach (($data["packages"] ?? []) as $versions) foreach ($versions as $package) {
                if (($package["version"] ?? "") === $tag || ($package["version"] ?? "") === $plain) exit(0);
                if (($package["version_normalized"] ?? "") === $tag || ($package["version_normalized"] ?? "") === $plain || strcasecmp(($package["version_normalized"] ?? ""), $normalized) === 0) exit(0);
            }
            exit(1);
        ' "$tag" || status=$?
        case "$status" in
            0) printf 'Packagist lists %s.\n' "$tag"; exit 0;;
            3) reason='the Packagist response is not valid JSON';;
            *) reason="the Packagist metadata does not list $tag";;
        esac
    else
        case "$metadata" in
            *404*|*"Couldn't open file"*|*"Could not open file"*) reason='the package is not on Packagist yet (HTTP 404); submit it first, see "One-time Packagist setup" in docs/releasing.md';;
            *) reason="the Packagist request failed: $(printf '%s' "$metadata" | head -n 1)";;
        esac
    fi
    printf 'Packagist does not list %s yet (attempt %s): %s.\n' "$tag" "$attempt" "$reason" >&2
    now=$(date +%s)
    if [ "$now" -ge "$deadline" ]; then break; fi
    pause=$((deadline - now))
    [ "$pause" -le "$sleep_seconds" ] || pause=$sleep_seconds
    sleep "$pause"
done

printf 'Last reason: %s.\nGitHub release exists, but Packagist does not list %s after %s attempts over %s seconds. See docs/releasing.md for the Packagist recovery steps.\n' "$reason" "$tag" "$attempt" "$timeout_seconds" >&2
exit 1
