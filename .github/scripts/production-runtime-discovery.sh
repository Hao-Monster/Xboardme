#!/usr/bin/env bash

# Resolve production from the host Caddy route. In the legacy topology the
# port-owning container is the application itself; in V2 it is the edge
# container and the application/Redis roles live in the same Compose project.

xboard_normalize_label() {
  local value=${1:-}
  if [[ "$value" == '<no value>' ]]; then
    value=''
  fi
  printf '%s\n' "$value"
}

xboard_container_label() {
  local container_id=$1 label=$2 value
  value=$(docker inspect -f "{{ index .Config.Labels \"$label\" }}" "$container_id")
  xboard_normalize_label "$value"
}

xboard_project_service_ids() {
  local project=$1 service=$2
  docker ps -q \
    --filter "label=com.docker.compose.project=$project" \
    --filter "label=com.docker.compose.service=$service"
}

xboard_find_compose_anchor() {
  local anchor_ids=()
  XBOARD_DISCOVERY_ERROR=''
  mapfile -t anchor_ids < <(docker ps -aq --filter label=com.docker.compose.service=xboard)
  if ((${#anchor_ids[@]} != 1)); then
    XBOARD_DISCOVERY_ERROR="compose_anchor_count=${#anchor_ids[@]}"
    return 1
  fi

  XBOARD_ANCHOR_CONTAINER=${anchor_ids[0]}
  XBOARD_ANCHOR_PROJECT=$(xboard_container_label "$XBOARD_ANCHOR_CONTAINER" com.docker.compose.project)
  XBOARD_ANCHOR_WORKDIR=$(xboard_container_label "$XBOARD_ANCHOR_CONTAINER" com.docker.compose.project.working_dir)
  if [[ -z "$XBOARD_ANCHOR_PROJECT" || -z "$XBOARD_ANCHOR_WORKDIR" || ! -d "$XBOARD_ANCHOR_WORKDIR" ]]; then
    XBOARD_DISCOVERY_ERROR=invalid_compose_anchor_metadata
    return 1
  fi
}

xboard_find_caddy_upstream() {
  local caddy_root=${XBOARD_CADDY_ROOT:-/etc/caddy}
  local proxy_files=() active_upstreams=()
  XBOARD_DISCOVERY_ERROR=''

  mapfile -t proxy_files < <(
    grep -RIlE --include='*.conf' --include='Caddyfile' \
      -- 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' "$caddy_root" 2>/dev/null || true
  )
  if ((${#proxy_files[@]} != 1)); then
    XBOARD_DISCOVERY_ERROR="caddy_file_count=${#proxy_files[@]}"
    return 1
  fi

  mapfile -t active_upstreams < <(
    grep -Eo 'reverse_proxy[[:space:]]+127\.0\.0\.1:[0-9]{4,5}' "${proxy_files[0]}" |
      awk '{print $2}' | sort -u
  )
  if ((${#active_upstreams[@]} != 1)); then
    XBOARD_DISCOVERY_ERROR="caddy_upstream_count=${#active_upstreams[@]}"
    return 1
  fi

  XBOARD_CADDY_FILE=${proxy_files[0]}
  XBOARD_ACTIVE_UPSTREAM=${active_upstreams[0]}
  XBOARD_ACTIVE_PORT=${XBOARD_ACTIVE_UPSTREAM##*:}
}

xboard_resolve_active_runtime() {
  local active_port=$1 container_id service project
  local running_ids=() bound_ids=() web_ids=() redis_ids=()
  XBOARD_DISCOVERY_ERROR=''

  if [[ ! "$active_port" =~ ^[0-9]+$ ]] || ((active_port < 1 || active_port > 65535)); then
    XBOARD_DISCOVERY_ERROR=invalid_active_port
    return 1
  fi

  mapfile -t running_ids < <(docker ps -q)
  for container_id in "${running_ids[@]}"; do
    if docker inspect -f '{{range $bindings := .NetworkSettings.Ports}}{{range $bindings}}{{println .HostPort}}{{end}}{{end}}' "$container_id" |
        grep -qx "$active_port"; then
      bound_ids+=("$container_id")
    fi
  done
  if ((${#bound_ids[@]} != 1)); then
    XBOARD_DISCOVERY_ERROR="active_port_container_count=${#bound_ids[@]}"
    return 1
  fi

  XBOARD_BOUND_CONTAINER=${bound_ids[0]}
  service=$(xboard_container_label "$XBOARD_BOUND_CONTAINER" com.docker.compose.service)
  project=$(xboard_container_label "$XBOARD_BOUND_CONTAINER" com.docker.compose.project)
  XBOARD_ACTIVE_PROJECT=$project
  XBOARD_ACTIVE_REDIS=''

  if [[ "$service" == edge ]]; then
    if [[ -z "$project" ]]; then
      XBOARD_DISCOVERY_ERROR=v2_edge_project_missing
      return 1
    fi
    mapfile -t web_ids < <(xboard_project_service_ids "$project" web)
    mapfile -t redis_ids < <(xboard_project_service_ids "$project" redis)
    if ((${#web_ids[@]} != 1)); then
      XBOARD_DISCOVERY_ERROR="v2_web_count=${#web_ids[@]}"
      return 1
    fi
    if ((${#redis_ids[@]} != 1)); then
      XBOARD_DISCOVERY_ERROR="v2_redis_count=${#redis_ids[@]}"
      return 1
    fi
    XBOARD_ACTIVE_TOPOLOGY=v2
    XBOARD_ACTIVE_WEB=${web_ids[0]}
    XBOARD_ACTIVE_REDIS=${redis_ids[0]}
  else
    XBOARD_ACTIVE_TOPOLOGY=legacy
    XBOARD_ACTIVE_WEB=$XBOARD_BOUND_CONTAINER
  fi
}
