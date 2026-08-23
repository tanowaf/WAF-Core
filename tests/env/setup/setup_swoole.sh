#!/bin/sh

# Configure the system for either Swoole or OpenSwoole
# Has to be run as root
#
# NB: expects PHP and the (Open)Swoole extension to be already installed (we build it using PIE, using build_pie_extensions.sh)

set -e

echo "Installing (Open)Swoole..."

SCRIPT_DIR="$(dirname -- "$(readlink -f "$0")")"

SWOOLE_USER=swoole
SWOOLE_GROUP=swoole

# @todo... test that either the Swoole or OpenSwoole php extensions (.so) do exist

ln "$(readlink -f /usr/bin/php)" /usr/bin/swoole

# @todo check first if the user already exists
./create_user.sh "$SWOOLE_USER" 2002 2002

if [ ! -d /var/lib/swoole ]; then
    mkdir /var/lib/swoole
fi
chown "$SWOOLE_USER:$SWOOLE_GROUP" /var/lib/swoole

# Openswoole is now installed as php extension
# Available versions: https://launchpad.net/~openswoole/+archive/ubuntu/ppa
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
if [ ! -d /var/log/swoole ]; then
    mkdir /var/log/swoole
fi
# Allow non-owner/root to list swoole log files, same as it is possible for nginx
chmod 755 /var/log/swoole
chown "$SWOOLE_USER:adm" /var/log/roadrunner
if [ ! -d /run/swoole ]; then
    mkdir /run/swoole
fi
chown "$SWOOLE_USER:$SWOOLE_GROUP" /run/swoole

# configure "virtual hosts"
if [ -n "${GITHUB_ACTIONS}" ]; then
    TESTS_ROOT_DIR="$(pwd)"
    echo "WORKER_SCRIPT_DIR=${TESTS_ROOT_DIR}/tests/public" > /etc/default/swoole_server
    echo "WORKER_SCRIPT_DIR=${TESTS_ROOT_DIR}/tests/public" > /etc/default/swoole_waf
    SWOOLE_SVC_SCRIPT_DIR="$(realpath "$SCRIPT_DIR/../config/init.d/")"
else
    cp "$SCRIPT_DIR/../config/init.d/swoole" /etc/init.d/swoole && chmod 755 /etc/init.d/swoole
    SWOOLE_SVC_SCRIPT_DIR=/etc/init.d
fi
ln -s "${SWOOLE_SVC_SCRIPT_DIR}/swoole" "${SWOOLE_SVC_SCRIPT_DIR}/swoole_server"
ln -s "${SWOOLE_SVC_SCRIPT_DIR}/swoole" "${SWOOLE_SVC_SCRIPT_DIR}/swoole_waf"

if [ ! -d /etc/swoole ]; then
    mkdir /etc/swoole
fi
cp "$SCRIPT_DIR/../config/swoole_waf.json" /etc/swoole/
cp "$SCRIPT_DIR/../config/swoole_server.json" /etc/swoole/
cp "$SCRIPT_DIR/../config/swoole_prepend.php" /etc/swoole/prepend.php

echo "Done installing (Open)Swoole"
