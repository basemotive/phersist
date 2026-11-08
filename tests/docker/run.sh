#!/usr/bin/env bash
#
# Runs the PHersist test suite on one or more database targets, in Docker.
#
# Usage: tests/docker/run.sh [target...] [-- pest arguments]
#        tests/docker/run.sh --down
#
# Without targets, it runs on every Docker target (not 'local'). The generator
# tests don't depend on the database, so they run with the first target only,
# unless pest arguments are given; those are passed to every run unchanged.
# The database containers keep running for quick re-runs; --down stops them.
#
# Targets: see tests/Support/Target.php. 'sqlite-cache' is SQLite with
# ObjectCache enabled. 'local' runs on this machine, outside Docker.
#
# @author Stefan Mensink <stefan@basemotive.nl>
# @copyright Basemotive VOF - https://www.basemotive.nl/
# SPDX-License-Identifier: LGPL-2.1-or-later

set -u

cd "$(dirname "$0")/../.." || exit 1

ALL_TARGETS=(sqlite sqlite-cache mysql-8.0 mysql-8.4 mysql-9 mariadb-10.6 mariadb-10.11 mariadb-11.4 mariadb-11.8 mariadb-11.8-mysql)
COMPOSE=(docker compose -f tests/docker/compose.yml)

export PHERSIST_UID="$(id -u)" PHERSIST_GID="$(id -g)"

if [[ "${1:-}" == "--down" ]]; then
	exec "${COMPOSE[@]}" --profile runner down
fi

targets=()
pest_args=()
while [[ $# -gt 0 ]]; do
	if [[ "$1" == "--" ]]; then
		shift
		pest_args=("$@")
		break
	fi
	targets+=("$1")
	shift
done
[[ ${#targets[@]} -eq 0 ]] && targets=("${ALL_TARGETS[@]}")

# The compose service a target needs, if any
service_of() {
	case "$1" in
		sqlite|sqlite-cache|local) ;;
		mariadb-11.8-mysql) echo mariadb-11.8 ;;
		*) echo "$1" ;;
	esac
}

services=()
for target in "${targets[@]}"; do
	service="$(service_of "$target")"
	[[ -n "$service" && ! " ${services[*]} " =~ " $service " ]] && services+=("$service")
done

"${COMPOSE[@]}" --profile runner build --quiet php || exit 1

if [[ ! -x vendor/bin/pest ]]; then
	"${COMPOSE[@]}" run --rm php composer install --no-interaction || exit 1
fi

if [[ ${#services[@]} -gt 0 ]]; then
	echo "Starting ${services[*]}..."
	"${COMPOSE[@]}" up -d --wait --quiet-pull "${services[@]}" || exit 1
fi

passed=()
failed=()
first=1
for target in "${targets[@]}"; do
	cache=off
	name="$target"
	if [[ "$target" == "sqlite-cache" ]]; then
		name=sqlite
		cache=on
	fi

	args=("${pest_args[@]}")
	if [[ ${#pest_args[@]} -eq 0 && $first -eq 0 ]]; then
		args=(--testsuite=Runtime)
	fi
	first=0

	echo
	echo "=== ${target} ==="
	if [[ "$target" == "local" ]]; then
		PHERSIST_TEST_TARGET=local PHERSIST_TEST_CACHE=$cache vendor/bin/pest "${args[@]}"
	else
		PHERSIST_TEST_TARGET="$name" PHERSIST_TEST_CACHE=$cache \
			"${COMPOSE[@]}" run --rm php vendor/bin/pest "${args[@]}"
	fi
	if [[ $? -eq 0 ]]; then passed+=("$target"); else failed+=("$target"); fi
done

echo
echo "Passed: ${passed[*]:-none}"
echo "Failed: ${failed[*]:-none}"
[[ ${#failed[@]} -eq 0 ]]
