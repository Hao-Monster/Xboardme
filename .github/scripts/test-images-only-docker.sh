#!/usr/bin/env bash
set -Eeuo pipefail

# REAL local-Docker integration. Only this test's uniquely labelled resources
# are created/removed. Production discovery, GHCR provenance eligibility and
# monitoring time/probes are synthetic test-process boundaries, never workflow
# switches. Schema 3 uses a loopback registry with genuine manifest digests;
# only its registry-name eligibility is normalized to the GHCR fixture boundary.
# Inventory, protected identity, approval, deletion and HOLD logic come from
# the actual image-only executor, including the same-manifest/two-repository
# RepoDigest topology. This is not production load, GHCR signature,
# different-manifest/same-config, daemon-timeout or SSH-loss acceptance.
[[ ${RETENTION_DOCKER_TEST_ALLOW_LOCAL:-false} == true ]] || {
  echo 'IMAGE_ONLY_DOCKER_TEST=NOT_RUN explicit_local_test_opt_in_required' >&2
  exit 2
}
# Real host mount inode checks need the same access as the production executor.
# Docker-group membership alone cannot read /var/lib/docker/volumes metadata.
((EUID == 0)) || { echo 'IMAGE_ONLY_DOCKER_TEST=NOT_RUN root_mount_metadata_access_required' >&2; exit 2; }
[[ -z ${DOCKER_HOST:-} ]] || { echo 'IMAGE_ONLY_DOCKER_TEST=FAIL remote_override_forbidden' >&2; exit 2; }
for tool in bash docker jq tar sha256sum gcc python3 flock timeout curl; do
  command -v "$tool" >/dev/null || { echo "IMAGE_ONLY_DOCKER_TEST=NOT_RUN missing_tool:$tool" >&2; exit 2; }
done
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
[[ $endpoint == unix:///* ]] || { echo 'IMAGE_ONLY_DOCKER_TEST=FAIL non_local_daemon' >&2; exit 2; }
docker info --format '{{.ID}}' >/dev/null
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
fixture_dir="$script_dir/fixtures/images-only-docker"
source "$script_dir/release-state.sh"
source "$script_dir/retention-safety.sh"
source "$script_dir/images-only-approval.sh"
source "$script_dir/image-startup-references.sh"
source "$script_dir/retention-continuity.sh"
source "$script_dir/retention-predelete-recovery.sh"
source "$script_dir/select-images-only-review.sh"

test_root=$(mktemp -d /tmp/retention-images-docker.XXXXXXXX)
test_label="retention-ci-$(date +%s)-$$-$RANDOM"
test_namespace="ghcr.io/hao-monster/xboardme:$test_label"
containers=() images=() registry_pids=() registry_references=() fixture_manifest_digests=() volume='' observer_pid=''
cases=0
active_sha=$(printf 'a%.0s' {1..40})
old_sha=$(printf 'b%.0s' {1..40})
workflow_sha=$(printf 'c%.0s' {1..40})
cleanup() {
  local result=$? id index reference owned registry_pid
  trap - EXIT HUP INT TERM
  if [[ -n $observer_pid ]] && kill -0 "$observer_pid" 2>/dev/null; then
    kill -TERM "$observer_pid"
    wait "$observer_pid" || result=1
  fi
  for registry_pid in "${registry_pids[@]}"; do
    if kill -0 "$registry_pid" 2>/dev/null; then
      kill -TERM "$registry_pid" || result=1
    fi
    wait "$registry_pid" 2>/dev/null || true
  done
  for id in "${containers[@]}"; do
    docker container inspect "$id" >/dev/null 2>&1 || continue
    owned=$(docker inspect -f '{{ index .Config.Labels "codex.retention.test" }}' "$id")
    [[ $owned == "$test_label" ]] || { echo 'IMAGE_ONLY_DOCKER_TEST=FAIL teardown_container_identity' >&2; result=1; continue; }
    if [[ $(docker inspect -f '{{.State.Running}}' "$id") == true ]]; then
      docker container stop --time 5 "$id" >/dev/null || result=1
    fi
    docker container rm "$id" >/dev/null || result=1
  done
  if [[ -n $volume ]] && docker volume inspect "$volume" >/dev/null 2>&1; then
    if [[ $(docker volume inspect -f '{{ index .Labels "codex.retention.test" }}' "$volume") == "$test_label" ]]; then
      docker volume rm "$volume" >/dev/null || result=1
    else result=1; fi
  fi
  for ((index=${#images[@]}-1;index>=0;index--)); do
    id=${images[index]}
    docker image inspect "$id" >/dev/null 2>&1 || continue
    [[ $(docker image inspect -f '{{ index .Config.Labels "codex.retention.test" }}' "$id") == "$test_label" ]] || {
      echo 'IMAGE_ONLY_DOCKER_TEST=FAIL teardown_image_identity' >&2; result=1; continue;
    }
    owned=true
    while IFS= read -r reference; do
      [[ $reference == "$test_namespace"-* || " ${registry_references[*]} " == *" $reference "* ]] || owned=false
    done < <(docker image inspect "$id" | jq -r '.[0].RepoTags[]?')
    while IFS= read -r reference; do
      [[ " ${registry_references[*]} " == *" $reference "* ]] || owned=false
    done < <(docker image inspect "$id" | jq -r '.[0].RepoDigests[]?')
    [[ $owned == true ]] || { result=1; continue; }
    while IFS= read -r reference; do
      docker image inspect "$reference" >/dev/null 2>&1 || continue
      [[ $(docker image inspect -f '{{.Id}}' "$reference") == "$id" ]] || { result=1; continue; }
      docker image rm --no-prune "$reference" >/dev/null || result=1
    done < <(docker image inspect "$id" | jq -r '.[0] | ((.RepoTags // [])+(.RepoDigests // [])|unique)[]')
    if docker image inspect "$id" >/dev/null 2>&1; then
      docker image rm --no-prune "$id" >/dev/null || result=1
    fi
  done
  # Generated local fixture data only, after all bind consumers were removed.
  [[ $test_root == /tmp/* && -d $test_root && ! -L $test_root ]] || exit 1
  rm -rf -- "$test_root"
  exit "$result"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p "$test_root/base-root" "$test_root/archive" "$test_root/data"
gcc -O2 -static -Wall -Wextra -Werror -o "$test_root/base-root/fixture-service" "$fixture_dir/service.c"
tar -C "$test_root/base-root" -cf "$test_root/archive/base-layer.tar" .
base_diff="sha256:$(sha256sum "$test_root/archive/base-layer.tar" | cut -d' ' -f1)"

make_config() {
  local kind=$1 revision=$2 layers=$3 parent=${4:-} config
  config=$(jq -cn --arg label "$test_label" --arg kind "$kind" --arg revision "$revision" \
    --arg parent "$parent" --argjson layers "$layers" '{
      created:"2020-01-01T00:00:00Z",architecture:"amd64",os:"linux",parent:$parent,
      config:{Cmd:["/fixture-service"],ExposedPorts:{"8080/tcp":{}},Labels:{
        "codex.retention.test":$label,"codex.retention.fixture":$kind,
        "org.opencontainers.image.source":"https://github.com/Hao-Monster/Xboardme",
        "org.opencontainers.image.revision":$revision}},
      rootfs:{type:"layers",diff_ids:$layers},
      history:[$layers[]|{created:"2020-01-01T00:00:00Z",created_by:"synthetic CI fixture"}] }')
  local hash
  hash=$(printf '%s' "$config" | sha256sum | cut -d' ' -f1)
  printf '%s' "$config" > "$test_root/archive/$hash.json"
  printf '%s\n' "$hash"
}
base_config=$(make_config base "$active_sha" "[\"$base_diff\"]")
base_image="sha256:$base_config"
jq -cn --arg config "$base_config.json" --arg tag "$test_namespace-base" \
  '[{Config:$config,RepoTags:[$tag],Layers:["base-layer.tar"]}]' > "$test_root/archive/manifest.json"
tar -C "$test_root/archive" -cf "$test_root/base.tar" .
images+=("$base_image")
docker image load --input "$test_root/base.tar" >/dev/null
[[ $(docker image inspect -f '{{.Id}}' "$test_namespace-base") == "$base_image" ]]
volume=$(docker volume create --label "codex.retention.test=$test_label" "$test_label-volume")

# Every protected role is a tiny synthetic server. None is the real application,
# Caddy, Redis or a production service. Genuine Docker role/mount/restart identity
# checks are retained; only host discovery maps to this isolated topology.
active_project="xboard-v2-333-1"
for role in redis web ws edge horizon scheduler; do
  options=()
  [[ $role != web ]] || options=(-p 127.0.0.1::8080)
  id=$(docker run -d --name "$test_label-$role" --label "codex.retention.test=$test_label" \
    --label "com.docker.compose.project=$active_project" --label "com.docker.compose.service=$role" \
    --label "org.opencontainers.image.revision=$active_sha" \
    --mount "type=bind,src=$test_root/data,dst=/data" --mount "type=volume,src=$volume,dst=/redis" \
    "${options[@]}" "$base_image")
  containers+=("$id")
  [[ $role != web ]] || active_container=$id
done
maintenance_id=$(docker run -d --name "$test_label-maintenance" --label "codex.retention.test=$test_label" \
  --mount "type=bind,src=$test_root/data,dst=/data" --mount "type=volume,src=$volume,dst=/redis" "$base_image")
containers+=("$maintenance_id")
anchor_id=$(docker create --name "$test_label-anchor" --label "codex.retention.test=$test_label" \
  --mount "type=bind,src=$test_root/data,dst=/data" --mount "type=volume,src=$volume,dst=/redis" \
  "$base_image" /fixture-service --read-sentinels)
containers+=("$anchor_id")
rollback_id=$(docker create --name "$test_label-rollback" --label "codex.retention.test=$test_label" \
  --label com.docker.compose.project=xboard-v2-222-1 --label com.docker.compose.service=web \
  --mount "type=bind,src=$test_root/data,dst=/data" --mount "type=volume,src=$volume,dst=/redis" \
  "$base_image" /fixture-service --read-sentinels)
containers+=("$rollback_id")
test_port=$(docker inspect "$active_container" | jq -er '.[0].NetworkSettings.Ports["8080/tcp"][0].HostPort')
for ((attempt=0;attempt<50;attempt++)); do
  if curl --fail --silent --max-time 2 "http://127.0.0.1:$test_port/health" > "$test_root/http-ready"; then break; fi
  sleep 0.1
done
[[ $(< "$test_root/http-ready") == synthetic-service-ok$'\r' ]]
docker start -a "$anchor_id" >/dev/null
docker start -a "$rollback_id" >/dev/null
python3 "$fixture_dir/observe_service.py" "$test_port" > "$test_root/observer.log" 2>&1 &
observer_pid=$!
for ((attempt=0;attempt<50;attempt++)); do
  kill -0 "$observer_pid" 2>/dev/null || { cat "$test_root/observer.log" >&2; exit 1; }
  grep -q '^SYNTHETIC_OBSERVER_READY=true$' "$test_root/observer.log" && break
  sleep 0.1
done
grep -q '^SYNTHETIC_OBSERVER_READY=true$' "$test_root/observer.log"
sentinels_before=$(docker exec "$active_container" /fixture-service --read-sentinels)
data_before=$(sha256sum "$test_root/data/record")
volume_before=$(docker volume inspect "$volume" | jq -cS '.[0]|{Name,Driver,Mountpoint,Labels}')
protected_before=$(for id in "${containers[@]}"; do retention_container_snapshot "$id"; done | jq -csS 'sort_by(.id)')

xboard_find_compose_anchor() { XBOARD_ANCHOR_CONTAINER=$anchor_id; XBOARD_ANCHOR_WORKDIR=$workdir; }
xboard_find_caddy_upstream() {
  XBOARD_CADDY_FILE=$workdir/Caddyfile
  XBOARD_ACTIVE_PORT=$test_port
  XBOARD_ACTIVE_UPSTREAM="127.0.0.1:$test_port"
}
xboard_resolve_active_runtime() { XBOARD_ACTIVE_WEB=$active_container; }
# The synthetic role containers are docker-run fixtures, not Compose projects.
# Keep parsing/file hashing genuine; only select the isolated startup source.
_ris_compose_metadata() {
  jq -cn --arg id "$anchor_id" --arg root "$workdir" '[{id:$id,project:"synthetic-anchor",
    workdir:$root,config_files:($root+"/compose.yaml")}]'
}

# Docker load does not supply RepoDigests. Serve the existing fixture's exact
# config and layers from a test-owned loopback-only Registry V2 endpoint, then
# let the real daemon verify and pull the manifest. No image inspect/delete
# operation is replaced, and no registry or image is contacted outside localhost.
fixture_add_manifest_digest() {
  local registry_pid registry_port manifest_digest registry_index registry_log pull_log
  registry_index=${#registry_pids[@]}
  registry_log="$case_root/registry-$registry_index.log"
  pull_log="$case_root/pull-$registry_index.log"
  python3 - "$test_root/archive" "$candidate_config" > "$registry_log" 2>&1 <<'FIXTURE_REGISTRY_PY' &
import gzip
import hashlib
import http.server
import json
import pathlib
import sys
import urllib.parse

archive = pathlib.Path(sys.argv[1])
config = (archive / (sys.argv[2] + ".json")).read_bytes()
blobs = {}

def descriptor(data, media_type):
    digest = "sha256:" + hashlib.sha256(data).hexdigest()
    blobs[digest] = data
    return {"mediaType": media_type, "size": len(data), "digest": digest}

manifest = json.dumps({
    "schemaVersion": 2,
    "mediaType": "application/vnd.docker.distribution.manifest.v2+json",
    "config": descriptor(config, "application/vnd.docker.container.image.v1+json"),
    "layers": [descriptor(gzip.compress((archive / name).read_bytes(), mtime=0),
                          "application/vnd.docker.image.rootfs.diff.tar.gzip")
               for name in ("base-layer.tar", "parent-layer.tar", "child-layer.tar")],
}, sort_keys=True, separators=(",", ":")).encode()
manifest_digest = "sha256:" + hashlib.sha256(manifest).hexdigest()

class Registry(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def respond(self, body):
        path = urllib.parse.urlsplit(self.path).path
        media_type = "application/octet-stream"
        digest = ""
        if path in ("/v2", "/v2/"):
            data = b"{}"
            media_type = "application/json"
        elif path == "/v2/hao-monster/xboardme/manifests/" + manifest_digest:
            data = manifest
            digest = manifest_digest
            media_type = "application/vnd.docker.distribution.manifest.v2+json"
        elif path.startswith("/v2/hao-monster/xboardme/blobs/"):
            digest = path.rsplit("/", 1)[-1]
            data = blobs.get(digest)
            if data is None:
                self.send_error(404)
                return
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header("Docker-Distribution-Api-Version", "registry/2.0")
        self.send_header("Content-Type", media_type)
        self.send_header("Content-Length", str(len(data)))
        if digest:
            self.send_header("Docker-Content-Digest", digest)
        self.end_headers()
        if body:
            self.wfile.write(data)

    def do_GET(self):
        self.respond(True)

    def do_HEAD(self):
        self.respond(False)

server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Registry)
print(json.dumps({"port": server.server_port, "digest": manifest_digest}), flush=True)
server.serve_forever()
FIXTURE_REGISTRY_PY
  registry_pid=$!
  registry_pids+=("$registry_pid")
  for ((attempt=0;attempt<50;attempt++)); do
    kill -0 "$registry_pid" 2>/dev/null || { cat "$registry_log" >&2; return 1; }
    [[ ! -s "$registry_log" ]] || break
    sleep 0.1
  done
  registry_port=$(jq -er '.port|select(type=="number" and .>0 and .<65536)' "$registry_log")
  manifest_digest=$(jq -er '.digest|select(test("^sha256:[a-f0-9]{64}$"))' "$registry_log")
  fixture_restore_prefix="localhost:$registry_port/hao-monster/xboardme@"
  fixture_restore_digest="$fixture_restore_prefix$manifest_digest"
  registry_references+=("$fixture_restore_digest")
  timeout --signal=TERM --kill-after=5s 60s docker image pull "$fixture_restore_digest" > "$pull_log" 2>&1 || {
    cat "$pull_log" >&2; return 1;
  }
  [[ $(docker image inspect -f '{{.Id}}' "$fixture_restore_digest") == "$candidate" ]]
  docker image inspect "$candidate" | jq -e --arg digest "$fixture_restore_digest" \
    '.[0].RepoDigests|index($digest)!=null' >/dev/null
  fixture_manifest_digests+=("$fixture_restore_digest")
}

# Production approval still validates every schema field. Only the actual
# loopback manifest reference's registry-name eligibility is normalized, because
# this isolated fixture deliberately neither publishes to nor pulls from GHCR.
eval "$(declare -f retention_images_review_validate | sed '1s/retention_images_review_validate/fixture_review_validate/')"
retention_images_review_validate() {
  local normalized result
  [[ -f "$1" && ! -L "$1" && $(stat -c %s -- "$1") -le 262144 ]] || return 1
  if [[ $(jq -r '.schema_version' "$1") != 3 ]]; then
    fixture_review_validate "$1"
    return
  fi
  [[ -n ${fixture_restore_prefix:-} ]] || return 1
  jq -e --arg prefix "$fixture_restore_prefix" \
    '.images[0].restore_digest|startswith($prefix) and test("@sha256:[a-f0-9]{64}$")' "$1" >/dev/null || return 1
  normalized=$(mktemp "$case_root/.fixture-ghcr-approval.XXXXXX") || return 1
  if jq --arg prefix "$fixture_restore_prefix" '
      .images[0].restore_digest |= ("ghcr.io/hao-monster/xboardme@" + ltrimstr($prefix))' \
      "$1" > "$normalized" && fixture_review_validate "$normalized"; then
    result=0
  else
    result=1
  fi
  rm -f -- "$normalized"
  return "$result"
}

# Narrow provenance fixture boundary. The real old timestamp, full ID, source,
# revision, labels, exact synthetic namespace and fresh Docker snapshot must
# match. Nothing is patched into the production helper or workflow interface.
retention_image_eligible() {
  local snapshot=$1 manifest=$2 now=$3 age=$4 id created inspected fixture_digests
  id=$(jq -r .id <<< "$snapshot")
  [[ $id == "$candidate" && $age -ge 604800 ]] || return 1
  inspected=$(docker image inspect "$id") || return 1
  fixture_digests=$(printf '%s\n' "${fixture_manifest_digests[@]}" | jq -Rsc 'split("\n")|map(select(length>0))')
  jq -e --arg label "$test_label" --arg prefix "$test_namespace-" --arg revision "$old_sha" \
    --argjson fixture_digests "$fixture_digests" \
    '.[0] | .Config.Labels["codex.retention.test"]==$label and
      .Config.Labels["org.opencontainers.image.source"]=="https://github.com/Hao-Monster/Xboardme" and
      .Config.Labels["org.opencontainers.image.revision"]==$revision and
      (.RepoTags|length)>0 and all(.RepoTags[];startswith($prefix) or
        (. as $reference|$fixture_digests|index($reference)!=null))' <<< "$inspected" >/dev/null || return 1
  [[ $(retention_image_snapshot "$id") == "$snapshot" ]] || return 1
  jq -e --slurpfile review "$manifest" --arg workflow "$EXPECTED_WORKFLOW_SHA" \
    --argjson fixture_digests "$fixture_digests" '
    .revision!=$workflow and
    (.revision as $r|$review[0].protected_image_revisions|index($r)==null) and
    ([.digests[] as $d|$review[0].protected_image_digests|index($d)|select(.!=null)]|length==0) and
    all(.digests[];test("^ghcr\\.io/hao-monster/xboardme@sha256:[a-f0-9]{64}$") or
      (. as $reference|$fixture_digests|index($reference)!=null))
  ' <<< "$snapshot" >/dev/null || return 1
  created=$(date -u -d "$(jq -r .created_at <<< "$snapshot")" +%s) || return 1
  ((now>=created && now-created >= age))
}

# Use the genuine observer state machine and durable HOLD. Only physical time
# and host-probe collection are synthetic; an independent real TCP/HTTP observer
# above watches the actual service during all real Docker operations.
_rc_now() { printf '%s\n' "$RC_FIXTURE_NOW"; }
_rc_wait() { RC_FIXTURE_NOW=$((RC_FIXTURE_NOW+RC_INTERVAL)); _rc_sample; }
_rc_start_observer() { _rc_sample; }
_rc_stop_observer() { :; }
_rc_probe() {
  kill -0 "$observer_pid" 2>/dev/null || return 1
  [[ ! -f "$case_root/probe-failure" ]] || return 1
  if [[ $(jq -r '.monitoring.mode // "legacy"' "$RC_REVIEW") == passive_runtime_v1 ]]; then
    # Genuine secret-free Docker metadata collection for every inventoried
    # container; Caddy, resource sampling and time remain synthetic boundaries.
    _rc_containers || { touch "$case_root/container-probe-failed"; return 1; }
  fi
}
eval "$(declare -f retention_continuity_check | sed '1s/retention_continuity_check/fixture_continuity_check/')"
retention_continuity_check() {
  # Fault injection is at the test monitoring boundary, never a mocked rm.
  # A first alias really disappears in Docker before these fail-stop cases fire.
  if [[ -n ${fault:-} && ! -f "$case_root/fault-fired" ]] &&
     ! docker image inspect "$test_namespace-$case_name-a" >/dev/null 2>&1; then
    touch "$case_root/fault-fired"
    case "$fault" in
      alias) docker tag "$candidate" "$test_namespace-$case_name-unreviewed" ;;
      reference)
        id=$(docker create --name "$test_label-$case_name-reference" --label "codex.retention.test=$test_label" "$candidate")
        printf '%s\n' "$id" > "$case_root/fault-container"
        ;;
      monitor) touch "$case_root/probe-failure"; _rc_sample; return 1 ;;
      protected_identity)
        # Deliberately perturb only the extra CI witness, never the persistent
        # connection's web container or any other original protected member.
        docker start "$identity_witness" >/dev/null
        fixture_wait_unhealthy "$identity_witness"
        docker container stop --time 5 "$identity_witness" >/dev/null
        _rc_sample
        return 1
        ;;
      term) kill -TERM "$BASHPID"; return 1 ;;
      *) return 1 ;;
    esac
  fi
  fixture_continuity_check
}

make_case() {
  case_name=$1
  fixture_restore_prefix='' fixture_restore_digest=''
  fixture_manifest_digests=()
  case_root="$test_root/$case_name"
  workdir=$case_root
  mkdir -p "$case_root/.codex-v2-release/333-1" "$case_root/parent" "$case_root/child"
  chmod 700 "$case_root/.codex-v2-release"
  printf '%s-parent\n' "$case_name" > "$case_root/parent/synthetic-parent"
  printf '%s-child\n' "$case_name" > "$case_root/child/synthetic-child"
  tar -C "$case_root/parent" -cf "$test_root/archive/parent-layer.tar" .
  tar -C "$case_root/child" -cf "$test_root/archive/child-layer.tar" .
  parent_diff="sha256:$(sha256sum "$test_root/archive/parent-layer.tar" | cut -d' ' -f1)"
  child_diff="sha256:$(sha256sum "$test_root/archive/child-layer.tar" | cut -d' ' -f1)"
  parent_config=$(make_config "$case_name-parent" "$old_sha" "[\"$base_diff\",\"$parent_diff\"]" "$base_image")
  parent_image="sha256:$parent_config"
  candidate_config=$(make_config "$case_name-candidate" "$old_sha" "[\"$base_diff\",\"$parent_diff\",\"$child_diff\"]" "$parent_image")
  candidate="sha256:$candidate_config"
  jq -cn --arg base "$base_config.json" --arg base_tag "$test_namespace-base" \
    --arg parent "$parent_config.json" --arg child "$candidate_config.json" \
    --arg tag_a "$test_namespace-$case_name-a" --arg tag_b "$test_namespace-$case_name-b" '[
      {Config:$base,RepoTags:[$base_tag],Layers:["base-layer.tar"]},
      {Config:$parent,RepoTags:[],Layers:["base-layer.tar","parent-layer.tar"]},
      {Config:$child,RepoTags:[$tag_a,$tag_b],Layers:["base-layer.tar","parent-layer.tar","child-layer.tar"]}
    ]' > "$test_root/archive/manifest.json"
  tar -C "$test_root/archive" -cf "$case_root/images.tar" .
  images+=("$parent_image" "$candidate")
  docker image load --input "$case_root/images.tar" >/dev/null
  [[ $(docker image inspect -f '{{.Id}}' "$test_namespace-$case_name-a") == "$candidate" ]]
  [[ $(docker image inspect "$candidate" | jq -r '.[0].RootFS.Layers[0]') == "$base_diff" ]]
  active_release_id=333-1
  active_revision=$active_sha
  active_image_id=$base_image
  active_state_file="$case_root/.codex-v2-release/333-1/state.json"
  active_app_data_path="$test_root/data"
  active_redis_volume=$volume
  active_traffic_state=active_v2
  active_rollback_supported=true
  direct_previous_project=xboard-v2-222-1
  printf 'reverse_proxy 127.0.0.1:%s\n' "$test_port" > "$case_root/Caddyfile"
  printf 'services:\n  web:\n    image: %s\n' "$base_image" > "$case_root/compose.yaml"
  release_state_create "$active_state_file" release_id 333-1 release_sha "$active_sha" \
    project_name "$active_project" traffic_state active_v2 rollback_supported true \
    legacy_release_sha "$active_sha" legacy_web_id "$rollback_id" maintenance_container "$maintenance_id"
  retention_snapshot_file="$case_root/inventory.json"
  retention_startup_file="$case_root/startup.json"
  review_file="$case_root/review.json"
  xboard_find_compose_anchor
  retention_all_snapshots > "$retention_snapshot_file"
  retention_startup_references > "$retention_startup_file"
  resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file")
  local proof now
  proof=$(printf '%s\n' 'Synthetic CI fixture proof, not production approval.' | retention_hash)
  now=$(date +%s)
  jq -cn --arg id "$candidate" --arg image_hash "$(retention_image_snapshot "$candidate" | retention_hash)" \
    --arg sha "$active_sha" --arg fingerprint "$resource_fingerprint" \
    --arg protection "$(retention_live_protection | retention_hash)" \
    --arg startup "$(retention_hash < "$retention_startup_file")" --arg proof "$proof" \
    --arg keep_digest "ghcr.io/hao-monster/xboardme@$base_image" --argjson now "$now" \
    --argjson containers "$(jq -c '[.[]|{id,status,started_at,restart_count}]' <<< "$protected_before")" \
    --arg origin "http://127.0.0.1:$test_port/health" '{
      schema_version:2,status:"approved",operation:"image_cleanup",active_release_id:"333-1",active_release_sha:$sha,
      resource_fingerprint:$fingerprint,protection_fingerprint:$protection,containers:[],
      protected_image_revisions:[$sha],protected_image_digests:[$keep_digest],
      max_image_size_bytes:536870912,audit_completed_at_epoch:($now-1),approval_expires_at_epoch:($now+3600),
      images:[{id:$id,snapshot_sha256:$image_hash,evidence:{startup_reference_review_sha256:$startup}}],
      review_evidence:{backup_review_sha256:$proof,monitoring_review_sha256:$proof,
        authentication_review_sha256:$proof,isolated_docker_review_sha256:$proof},
      monitoring:{baseline_seconds:600,post_delete_observe_seconds:900,sample_interval_seconds:10,
        heartbeat_max_age_seconds:30,
        public:{url:"https://synthetic.example.invalid/readonly",expected_status:200,body_sha256:$proof,max_latency_ms:2000},
        origin:{url:$origin,expected_status:200,body_sha256:$proof,max_latency_ms:2000},
        caddy:{unit:"caddy.service",invocation_id:("e"*32),main_pid:42,active_enter_monotonic_usec:1000000},
        protected_containers:$containers,
        resources:{max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,
          max_io_full_avg10:1,max_cpu_some_avg10:10},
        evidence:{read_only_endpoints_sha256:$proof,production_baseline_sha256:$proof,long_connections_sha256:$proof,
          natural_traffic_sha256:$proof,resource_overhead_sha256:$proof},
        live_signals:{transport:"host_json_v1",source_review_sha256:$proof,max_age_seconds:20}}}
    ' > "$review_file"
  chmod 600 "$review_file"
  cp -- "$active_state_file" "$case_root/state.before.json"
  fault=''
}

make_passive_case() {
  make_case "$1"
  fixture_add_manifest_digest
  retention_all_snapshots > "$retention_snapshot_file"
  resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file")
  jq --arg restore "$fixture_restore_digest" --arg revision "$old_sha" \
    --arg image_hash "$(retention_image_snapshot "$candidate" | retention_hash)" \
    --arg fingerprint "$resource_fingerprint" --argjson port "$test_port" \
    --slurpfile inventory "$retention_snapshot_file" '
    .schema_version=3 |
    .resource_fingerprint=$fingerprint |
    .images[0].restore_digest=$restore | .images[0].revision=$revision |
    .images[0].snapshot_sha256=$image_hash |
    .review_evidence={scope_review_sha256:.review_evidence.monitoring_review_sha256,
      isolated_docker_review_sha256:.review_evidence.isolated_docker_review_sha256} |
    .monitoring.mode="passive_runtime_v1" | .monitoring.active_port=$port |
    .monitoring.baseline_seconds=120 | .monitoring.post_delete_observe_seconds=300 |
    .monitoring.protected_containers=[$inventory[0].containers[]|{id,status,started_at,restart_count}] |
    del(.monitoring.public,.monitoring.origin,.monitoring.live_signals,.monitoring.evidence)
    ' "$review_file" > "$case_root/passive-review.json"
  mv -- "$case_root/passive-review.json" "$review_file"
  chmod 600 "$review_file"
}

fixture_wait_unhealthy() {
  local id=$1 attempt
  for ((attempt=0;attempt<100;attempt++)); do
    [[ $(docker container inspect -f '{{.State.Running}}' "$id") == true ]] || return 1
    if [[ $(docker container inspect -f '{{.State.Health.Status}}' "$id") == unhealthy ]]; then return 0; fi
    sleep 0.1
  done
  echo 'IMAGE_ONLY_DOCKER_TEST=FAIL expected_fixture_unhealthy_not_observed' >&2
  return 1
}

invoke() (
  set -Eeuo pipefail
  EXPECTED_WORKFLOW_SHA=$workflow_sha
  EXPECTED_ACTIVE_RELEASE_ID=$active_release_id
  EXPECTED_ACTIVE_RELEASE_SHA=$active_sha
  EXPECTED_RETENTION_RESOURCE_FINGERPRINT=$resource_fingerprint
  EXPECTED_RETENTION_REVIEW_SHA256=$(retention_hash < "$review_file")
  RETENTION_ACQUIRE_LOCK=true
  RETENTION_IMAGE_MIN_AGE_SECONDS=604800
  XBOARD_ACTIVE_PORT=$test_port
  RC_FIXTURE_NOW=1000
  RC_OBSERVER_PID=''
  exec 9>"$workdir/.codex-v2-release/deploy.lock"
  chmod 600 "$workdir/.codex-v2-release/deploy.lock"
  flock -n 9
  source "$script_dir/cleanup-xboard-images-only.sh"
)
run_case() {
  set +e
  ( set -Eeuo pipefail; invoke ) > "$case_root/result.log" 2>&1
  status=$?
  set -e
  if [[ -f "$case_root/fault-container" ]]; then
    id=$(< "$case_root/fault-container")
    [[ " ${containers[*]} " == *" $id "* ]] || containers+=("$id")
  fi
}
expect_hold() {
  ((status != 0)) || { cat "$case_root/result.log" >&2; return 1; }
  jq -e '.status=="HOLD" and .cleanup_validated==false' "$case_root/.codex-v2-release/image-cleanup-gate.json" >/dev/null
  cmp "$active_state_file" "$case_root/state.before.json"
}
pass() { ((cases+=1)); printf 'IMAGE_ONLY_DOCKER_CASE=PASS %s\n' "$1"; }

make_case success
# Exercise the actual Docker template before negative health cases: a missing
# optional Health key must not be mistaken for a failed health-policy check.
docker container inspect "$active_container" "$maintenance_id" | \
  jq -e 'all(.[]; .State.Status=="running" and (.State|has("Health")|not))' >/dev/null
if ! RC_REVIEW=$review_file _rc_containers > "$case_root/container-template.log" 2>&1; then
  cat "$case_root/container-template.log" >&2; exit 1
fi
pass real_container_template_accepts_absent_health
run_case
if ((status != 0)); then cat "$case_root/result.log" >&2; exit 1; fi
grep -q '^IMAGE_CLEANUP=PASS result=complete ' "$case_root/result.log"
if docker image inspect "$candidate" >/dev/null 2>&1; then
  echo 'IMAGE_ONLY_DOCKER_TEST=FAIL candidate_still_present' >&2; exit 1
fi
docker image inspect "$parent_image" >/dev/null
docker image inspect "$base_image" >/dev/null
jq -e '.status=="COMPLETE" and .cleanup_validated==true and .post_verification=="pending"' \
  "$case_root/.codex-v2-release/image-cleanup-gate.json" >/dev/null
cmp "$active_state_file" "$case_root/state.before.json"
pass multi_tag_image_removed_shared_layers_and_untagged_parent_retained

# The extra witness has an intentionally failing health check. It is not one
# of the original live-role/TCP fixtures. Its stopped historical health must
# not block schema 3, but the same unhealthy state while running must block it.
# This scratch image has no /bin/sh, so CMD-SHELL fails without touching the
# synthetic application's persistent records or accepting additional requests.
identity_witness=$(docker run -d --name "$test_label-passive-witness" \
  --label "codex.retention.test=$test_label" \
  --health-cmd='/fixture-service --intentional-ci-health-failure' \
  --health-interval=1s --health-timeout=1s --health-retries=1 \
  --mount "type=bind,src=$test_root/data,dst=/data" --mount "type=volume,src=$volume,dst=/redis" \
  "$base_image")
containers+=("$identity_witness")
fixture_wait_unhealthy "$identity_witness"

make_passive_case passive_running_unhealthy
before=$(retention_image_snapshot "$candidate")
if RC_REVIEW=$review_file _rc_containers > "$case_root/health-policy.log" 2>&1; then
  echo 'IMAGE_ONLY_DOCKER_TEST=FAIL running_unhealthy_probe_accepted' >&2; exit 1
fi
# The jq health policy is silent on rejection. Any template/inspect error is a
# different failure, not evidence that unhealthy running containers are gated.
if [[ -s "$case_root/health-policy.log" ]]; then
  cat "$case_root/health-policy.log" >&2; exit 1
fi
run_case
if ! expect_hold; then cat "$case_root/result.log" >&2; exit 1; fi
[[ -f "$case_root/container-probe-failed" ]]
[[ $(retention_image_snapshot "$candidate") == "$before" ]]
pass passive_real_running_unhealthy_container_blocks_before_deletion

docker container stop --time 5 "$identity_witness" >/dev/null
[[ $(docker container inspect -f '{{.State.Status}}:{{.State.Health.Status}}' "$identity_witness") == exited:unhealthy ]]
make_passive_case passive_success
run_case
if ((status != 0)); then cat "$case_root/result.log" >&2; exit 1; fi
grep -q '^IMAGE_CLEANUP=PASS result=complete ' "$case_root/result.log"
if docker image inspect "$candidate" >/dev/null 2>&1 ||
   docker image inspect "$fixture_restore_digest" >/dev/null 2>&1; then
  echo 'IMAGE_ONLY_DOCKER_TEST=FAIL passive_candidate_or_digest_still_present' >&2; exit 1
fi
docker image inspect "$parent_image" >/dev/null
docker image inspect "$base_image" >/dev/null
jq -e '.schema_version==2 and .monitoring_mode=="passive_runtime_v1" and
  .status=="COMPLETE" and .cleanup_validated==true and .post_verification=="pending" and
  .baseline_seconds==120 and .observe_seconds==300' \
  "$case_root/.codex-v2-release/image-cleanup-gate.json" >/dev/null
cmp "$active_state_file" "$case_root/state.before.json"
pass passive_real_digest_removed_shared_layers_and_stopped_historical_unhealthy_preserved

# The production candidate topology includes one tag plus the same manifest
# under two repository prefixes. Pull through two distinct live loopback
# registries to make the real daemon create those references for one config ID.
make_passive_case passive_multiple_repository_digests
multi_primary_prefix=$fixture_restore_prefix
multi_primary_digest=$fixture_restore_digest
multi_tag="$test_namespace-$case_name-a"
docker image rm --no-prune "$test_namespace-$case_name-b" >/dev/null
fixture_add_manifest_digest
multi_secondary_digest=$fixture_restore_digest
fixture_restore_prefix=$multi_primary_prefix
fixture_restore_digest=$multi_primary_digest
[[ ${multi_primary_digest%@*} != "${multi_secondary_digest%@*}" &&
   ${multi_primary_digest#*@} == "${multi_secondary_digest#*@}" ]]
multi_aliases=$(jq -cn --arg tag "$multi_tag" --arg first "$multi_primary_digest" \
  --arg second "$multi_secondary_digest" '[$tag,$first,$second]|sort')
docker image inspect "$candidate" | jq '.[0]|{Id,RepoTags,RepoDigests}' > "$case_root/multi-inspect.before.json"
jq -e --arg id "$candidate" --arg tag "$multi_tag" --arg first "$multi_primary_digest" \
  --arg second "$multi_secondary_digest" --argjson aliases "$multi_aliases" '
    .Id==$id and (.RepoDigests|sort)==([$first,$second]|sort) and
    ([.RepoTags[]|select(contains("@")|not)]==[$tag]) and
    ((.RepoTags+.RepoDigests|unique|sort)==$aliases)
  ' "$case_root/multi-inspect.before.json" >/dev/null
for reference in "$multi_tag" "$multi_primary_digest" "$multi_secondary_digest"; do
  [[ $(docker image inspect -f '{{.Id}}' "$reference") == "$candidate" ]]
done

# Keep the exact Docker snapshot, including a digest also reported in RepoTags.
# Only the fixture registry eligibility is adapted; production snapshot fields,
# reviewed hashes, plan comparisons and deletion code are never normalized.
retention_all_snapshots > "$retention_snapshot_file"
resource_fingerprint=$(retention_resource_fingerprint "$retention_snapshot_file")
jq --arg fingerprint "$resource_fingerprint" \
  --arg image_hash "$(retention_image_snapshot "$candidate" | retention_hash)" '
    .resource_fingerprint=$fingerprint | .images[0].snapshot_sha256=$image_hash
  ' "$review_file" > "$case_root/multi-review.json"
mv -- "$case_root/multi-review.json" "$review_file"
chmod 600 "$review_file"
jq -e --arg digest "$multi_primary_digest" '.images[0].restore_digest==$digest' "$review_file" >/dev/null
jq -e --arg id "$candidate" --slurpfile inspected "$case_root/multi-inspect.before.json" '
  [.images[]|select(.id==$id)] as $selected |
  ($selected|length)==1 and $selected[0].tags==($inspected[0].RepoTags|sort) and
  $selected[0].digests==($inspected[0].RepoDigests|sort)
  ' "$retention_snapshot_file" >/dev/null
multi_events_started_at=$(date -u +'%Y-%m-%dT%H:%M:%S.%NZ')
run_case
multi_events_ended_at=$(date -u +'%Y-%m-%dT%H:%M:%S.%NZ')
if ((status != 0)); then cat "$case_root/result.log" >&2; exit 1; fi
[[ $(grep -Fc "IMAGE_CLEANUP=PASS result=complete id=$candidate images=1 " "$case_root/result.log") == 1 ]]
for reference in "$candidate" "$multi_tag" "$multi_primary_digest" "$multi_secondary_digest"; do
  if docker image inspect "$reference" >/dev/null 2>&1; then
    echo 'IMAGE_ONLY_DOCKER_TEST=FAIL multi_repository_candidate_or_alias_still_present' >&2; exit 1
  fi
done
# Read the real daemon's bounded event history, not an intercept of docker rm.
# Alias untagging may remove additional names automatically; the image config
# itself must have exactly one delete event and every approved name must be gone.
timeout --signal=TERM --kill-after=2s 15s docker events --since "$multi_events_started_at" \
  --until "$multi_events_ended_at" --filter type=image --format '{{json .}}' > "$case_root/multi-events.jsonl"
jq -se --arg id "$candidate" '[.[]|select(.Type=="image" and
  (.Action // .status)=="delete" and (.Actor.ID // .id)==$id)]|length==1' \
  "$case_root/multi-events.jsonl" >/dev/null
retention_all_snapshots > "$case_root/multi-inventory.after.json"
jq -e --arg id "$candidate" --slurpfile before "$retention_snapshot_file" '
  .containers==$before[0].containers and .images==[$before[0].images[]|select(.id!=$id)]
  ' "$case_root/multi-inventory.after.json" >/dev/null
docker image inspect "$parent_image" "$base_image" >/dev/null
[[ $(docker exec "$active_container" /fixture-service --read-sentinels) == "$sentinels_before" ]]
[[ $(sha256sum "$test_root/data/record") == "$data_before" ]]
[[ $(docker volume inspect "$volume" | jq -cS '.[0]|{Name,Driver,Mountpoint,Labels}') == "$volume_before" ]]
kill -0 "$observer_pid"
jq -e --arg id "$candidate" '.image_id==$id and .status=="COMPLETE" and
  .cleanup_validated==true and .post_verification=="pending" and
  .baseline_completed_at_uptime-.baseline_started_at_uptime>=120 and
  .observation_completed_at_uptime-.removed_at_uptime>=300' \
  "$case_root/.codex-v2-release/image-cleanup-gate.json" >/dev/null
cmp "$active_state_file" "$case_root/state.before.json"
pass passive_same_manifest_two_repository_digests_one_tag_one_image_delete_all_other_resources_preserved

# Recovery exercises the actual HOLD -> archived HOLD + fresh RUNNING path with
# a real unchanged Docker inventory. Only the existing test clock and external
# probes are synthetic. The production validator/archive code is not replaced.
make_passive_case passive_predelete_recovery
chmod 600 "$retention_snapshot_file" "$retention_startup_file"
before=$(retention_image_snapshot "$candidate")
touch "$case_root/probe-failure"
run_case
if ! expect_hold; then cat "$case_root/result.log" >&2; exit 1; fi
[[ $(retention_image_snapshot "$candidate") == "$before" ]]
recovery_gate="$case_root/.codex-v2-release/image-cleanup-gate.json"
recovery_gate_hash=$(retention_hash < "$recovery_gate")
recovery_session=$(jq -er .session_id "$recovery_gate")
recovery_old_review=$(jq -er .review_sha256 "$recovery_gate")
recovery_plan="$case_root/.codex-v2-release/image-cleanup-plan-$recovery_old_review.json"
recovery_plan_hash=$(retention_hash < "$recovery_plan")
[[ $(stat -c '%a:%h' "$recovery_plan") == 600:1 ]]
if retention_continuity_admit_deployment "$workdir" > "$case_root/admission.log" 2>&1; then exit 1; fi
jq --arg gate "$recovery_gate_hash" --arg plan "$recovery_plan_hash" --arg session "$recovery_session" \
  --arg old_workflow "$workflow_sha" --arg old_review "$recovery_old_review" '
  .recovery={schema_version:1,mode:"predelete_hold_v1",gate_sha256:$gate,plan_sha256:$plan,
    session_id:$session,workflow_sha:$old_workflow,review_sha256:$old_review,image_id:.images[0].id} |
  .sequence={schema_version:1,index:"001",predecessor_review_sha256:null,audit_artifact_sha256:("f"*64)}
  ' "$review_file" > "$case_root/recovery-review.json"
mv -- "$case_root/recovery-review.json" "$review_file"
chmod 600 "$review_file"
saved_workflow_sha=$workflow_sha
workflow_sha=$(printf 'd%.0s' {1..40})
rm -f -- "$case_root/probe-failure"
run_case
if ((status != 0)); then cat "$case_root/result.log" >&2; exit 1; fi
workflow_sha=$saved_workflow_sha
if docker image inspect "$candidate" >/dev/null 2>&1; then
  echo 'IMAGE_ONLY_DOCKER_TEST=FAIL recovered_candidate_still_present' >&2; exit 1
fi
[[ $(retention_hash < "$case_root/.codex-v2-release/image-cleanup-gate-history-$recovery_session.json") == "$recovery_gate_hash" ]]
[[ $(retention_hash < "$recovery_plan") == "$recovery_plan_hash" ]]
jq -e --arg session "$recovery_session" '.status=="COMPLETE" and .post_verification=="pending" and
  .session_id!=$session and .baseline_completed_at_uptime-.baseline_started_at_uptime>=120 and
  .observation_completed_at_uptime-.removed_at_uptime>=300' "$recovery_gate" >/dev/null
if retention_continuity_admit_deployment "$workdir" > "$case_root/admission.log" 2>&1; then exit 1; fi
cmp "$active_state_file" "$case_root/state.before.json"
pass passive_predelete_hold_recovered_with_new_baseline_original_evidence_preserved_and_deployment_still_denied

make_passive_case passive_restore_mismatch
jq --arg digest "${fixture_restore_prefix}sha256:$(printf 'd%.0s' {1..64})" \
  '.images[0].restore_digest=$digest' "$review_file" > "$case_root/invalid-review.json"
mv -- "$case_root/invalid-review.json" "$review_file"
chmod 600 "$review_file"
before=$(retention_image_snapshot "$candidate")
run_case
((status != 0))
grep -q '^IMAGE_CLEANUP_FAIL=restore_identity_not_in_audit$' "$case_root/result.log" || {
  cat "$case_root/result.log" >&2; exit 1;
}
[[ $(retention_image_snapshot "$candidate") == "$before" ]]
pass passive_unmatched_restore_digest_blocks_before_deletion

make_passive_case passive_missing_stopped_rollback
jq --arg id "$rollback_id" '.monitoring.protected_containers|=map(select(.id!=$id))' \
  "$review_file" > "$case_root/invalid-review.json"
mv -- "$case_root/invalid-review.json" "$review_file"
chmod 600 "$review_file"
before=$(retention_image_snapshot "$candidate")
run_case
((status != 0))
grep -q '^IMAGE_CLEANUP_FAIL=monitoring_inventory_mismatch$' "$case_root/result.log" || {
  cat "$case_root/result.log" >&2; exit 1;
}
[[ $(retention_image_snapshot "$candidate") == "$before" ]]
pass passive_monitor_omitting_stopped_rollback_blocks_before_deletion

make_passive_case passive_protected_identity
fault=protected_identity
run_case
if ! expect_hold; then cat "$case_root/result.log" >&2; exit 1; fi
[[ -f "$case_root/fault-fired" && -f "$case_root/container-probe-failed" ]]
if docker image inspect "$test_namespace-$case_name-a" >/dev/null 2>&1; then
  echo 'IMAGE_ONLY_DOCKER_TEST=FAIL first_alias_not_removed' >&2; exit 1
fi
docker image inspect "$test_namespace-$case_name-b" >/dev/null
docker image inspect "$fixture_restore_digest" >/dev/null
before=$(retention_image_snapshot "$candidate")
fault=''
run_case
expect_hold
[[ $(retention_image_snapshot "$candidate") == "$before" ]]
pass passive_real_protected_started_at_drift_durable_hold_blocks_later_aliases_and_reexecution

make_case new_alias
docker tag "$candidate" "$test_namespace-$case_name-unreviewed"
before=$(docker image inspect "$candidate" | jq -cS '.[0]|{Id,RepoTags,RepoDigests}')
run_case
((status != 0))
[[ $(docker image inspect "$candidate" | jq -cS '.[0]|{Id,RepoTags,RepoDigests}') == "$before" ]]
pass unreviewed_alias_rejected_before_deletion

make_case container_reference
id=$(docker create --name "$test_label-$case_name" --label "codex.retention.test=$test_label" "$candidate")
containers+=("$id")
run_case
((status != 0))
docker image inspect "$candidate" >/dev/null
docker container inspect "$id" >/dev/null
pass new_stopped_container_reference_preserved

for injected in alias reference monitor term; do
  make_case "partial_$injected"
  fault=$injected
  run_case
  if ! expect_hold; then cat "$case_root/result.log" >&2; exit 1; fi
  [[ -f "$case_root/fault-fired" ]]
  # First untag really happened. No later call is authorized after the fault.
  ! docker image inspect "$test_namespace-$case_name-a" >/dev/null 2>&1
  docker image inspect "$test_namespace-$case_name-b" >/dev/null
  before=$(docker image inspect "$candidate" | jq -cS '.[0]|{Id,RepoTags,RepoDigests}')
  fault=''
  run_case
  expect_hold
  [[ $(docker image inspect "$candidate" | jq -cS '.[0]|{Id,RepoTags,RepoDigests}') == "$before" ]]
  pass "partial_${injected}_durable_hold_blocks_reexecution"
done

kill -0 "$observer_pid"
kill -TERM "$observer_pid"
wait "$observer_pid"
observer_pid=''
grep -q '^SYNTHETIC_CONTINUITY=PASS samples=.* reconnects=0$' "$test_root/observer.log"
cat "$test_root/observer.log"
[[ $(docker exec "$active_container" /fixture-service --read-sentinels) == "$sentinels_before" ]]
[[ $(sha256sum "$test_root/data/record") == "$data_before" ]]
[[ $(docker volume inspect "$volume" | jq -cS '.[0]|{Name,Driver,Mountpoint,Labels}') == "$volume_before" ]]
after=$(for id in $(jq -r '.[].id' <<< "$protected_before"); do retention_container_snapshot "$id"; done | jq -csS 'sort_by(.id)')
[[ $after == "$protected_before" ]]
pass all_protected_and_rollback_containers_mounts_data_and_tcp_connection_preserved
printf 'IMAGE_ONLY_DOCKER_TEST=PASS cases=%s real_docker=true loopback_registry=true schema3_real_digest=true schema3_multi_repo_digest=true schema3_real_container_probes=true synthetic_discovery=true synthetic_provenance=true fixture_clock=true production_load=false daemon_timeout=false ssh_loss=false\n' "$cases"
