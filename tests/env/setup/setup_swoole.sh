#!/bin/sh

# Install and configure OpenSwoole
# Has to be run as root
#
# Available versions: https://launchpad.net/~openswoole/+archive/ubuntu/ppa

set -e

echo "Installing OpenSwoole..."

SCRIPT_DIR="$(dirname -- "$(readlink -f "$0")")"

SWOOLE_USER=swoole
SWOOLE_GROUP=swoole

# @todo check first if the user already exists
./create_user.sh "$SWOOLE_USER" 2002 2002

if [ ! -d /var/lib/swoole ]; then
    mkdir /var/lib/swoole
fi
chown "$SWOOLE_USER:$SWOOLE_GROUP" /var/lib/swoole

# Openswoole is installed as php extension via PIE, using setup_php
# @todo... test that the swoole extension (.so) does exist

#VERSION="$1" # 7.4-8.2 available as of 2026/8/16
#if [ -z "$VERSION" ]; then
#    echo "OpenSwoole version (7.4 .. 8.2) is required as 1st argument to this script when running on Github" >&2
#    exit 1
#fi
#export DEBIAN_FRONTEND=noninteractive
#apt-get install -y software-properties-common
#add-apt-repository -y ppa:openswoole/ppa
#apt-get install -y "php${VERSION}-openswoole"

if [ ! -d /var/lib/swoole ]; then
    mkdir /var/lib/swoole
fi
chown "$SWOOLE_USER:$SWOOLE_GROUP" /var/lib/swoole
if [ ! -d /run/swoole ]; then
    mkdir /run/swoole
fi
chown "$SWOOLE_USER:$SWOOLE_GROUP" /run/swoole

# configure virtual hosts
if [ -n "${GITHUB_ACTIONS}" ]; then
    TESTS_ROOT_DIR="$(pwd)"
    sed -r -e "s|^TESTS_ROOT_DIR=.*|TESTS_ROOT_DIR=${TESTS_ROOT_DIR}|g" --in-place "$SCRIPT_DIR/../config/init.d/swoole"
else
    cp "$SCRIPT_DIR/../config/init.d/swoole" /etc/init.d/swoole && chmod 755 /etc/init.d/swoole
fi

if [ ! -d /etc/swoole ]; then
    mkdir /etc/swoole
fi
cp "$SCRIPT_DIR/../config/swoole.json" /etc/swoole/swoole.json

echo "Done installing OpenSwoole"
