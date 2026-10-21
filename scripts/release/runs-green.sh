#!/usr/bin/env bash
# Reads the workflow runs JSON on stdin. Exit codes:
#   0 a matching push run succeeded
#   1 the runs failed, or the JSON is unusable
#   2 a matching run is queued or in progress (poll again)
#   3 no matching push run exists yet (poll again for a short grace period)
set -euo pipefail

workflow=${1:-}
sha=${2:-}
branch=${3:-main}

php -r '
    $workflow = $argv[1]; $sha = $argv[2]; $branch = $argv[3];
    $data = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($data)) { fwrite(STDERR, "$workflow returned invalid runs JSON.\n"); exit(1); }
    $runs = $data["workflow_runs"] ?? [];
    $matches = array_values(array_filter($runs, fn ($run) => ($run["head_sha"] ?? "") === $sha && ($run["head_branch"] ?? "") === $branch && ($run["event"] ?? "") === "push"));
    if ($matches === []) { fwrite(STDERR, "$workflow has no push run for $sha on $branch yet.\n"); exit(3); }
    // The latest run decides. GitHub keeps a re-run under the same run id with a higher
    // run_attempt, so the list normally holds one entry per run and that entry is its latest
    // attempt; a distinct later run for the same commit has a higher run_number. A later failure
    // must not be outvoted by an earlier success, and an earlier failure must not block a later
    // success. Runs that tie on run_number, run_attempt and created_at are all "latest" and must
    // all succeed, which is the cautious reading of an ambiguous list.
    $key = fn ($run) => [(int) ($run["run_number"] ?? 0), (int) ($run["run_attempt"] ?? 1), (string) ($run["created_at"] ?? "")];
    usort($matches, fn ($a, $b) => $key($b) <=> $key($a));
    $latest = array_values(array_filter($matches, fn ($run) => $key($run) === $key($matches[0])));
    foreach ($latest as $run) {
        if (($run["status"] ?? "") !== "completed") { fwrite(STDERR, "$workflow is ".($run["status"] ?? "unknown")." for $sha.\n"); exit(2); }
    }
    $bad = array_values(array_filter($latest, fn ($run) => ($run["conclusion"] ?? "") !== "success"));
    if ($bad === []) { echo "$workflow is successful for $sha.\n"; exit(0); }
    $conclusions = implode(", ", array_unique(array_map(fn ($run) => (string) ($run["conclusion"] ?? "unknown"), $bad)));
    fwrite(STDERR, "$workflow completed without success for $sha: $conclusions.\n"); exit(1);
' "$workflow" "$sha" "$branch"
