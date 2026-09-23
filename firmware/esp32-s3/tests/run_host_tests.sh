#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
test_binary="$(mktemp -t haccp-device-state.XXXXXX)"
trap 'rm -f "$test_binary"' EXIT
c++ -std=c++17 -Wall -Wextra -Werror -Itests/support -Isrc \
    tests/DeviceStateHostTest.cpp src/DeviceState.cpp -o "$test_binary"
"$test_binary"
