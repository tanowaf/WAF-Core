#!/bin/sh

# Has to be run as root

set -e

echo "Installing base software packages..."

UPDATE_INSTALLED=false

export DEBIAN_FRONTEND=noninteractive

while getopts ":u" opt
do
    case $opt in
        u)
          UPDATE_INSTALLED=true
          ;;
        \?)
          echo "Invalid option: -$OPTARG" >&2
          exit 1
          ;;
    esac
done

if [ ! -d /usr/share/man/man1 ]; then mkdir -p /usr/share/man/man1; fi

# Allow the user to specify a proxy for speeding up downloading of apt packages
if [ "${APT_PACKAGE_PROXY}" != "none" ]; then
    printf "Acquire::http::Proxy \"${APT_PACKAGE_PROXY}\";\nAcquire::https::Proxy \"DIRECT\";\n" > /etc/apt/apt.conf.d/00proxy
fi

# @todo allow the user to specify an ubuntu mirror for speeding up downloading of apt packages

apt-get update --allow-releaseinfo-change

if [ "$UPDATE_INSTALLED" = true ]; then
    apt-get upgrade -y
fi

# Curl is not required atm to run tests, but it is a good tool to run manual tests on the command-line.
# It can query a unix socket too, via option `--unix-socket`
# Alternatives would be netcat, socat
apt-get install -y \
    curl git ncompress sudo unzip wrk

echo "Done installing base software packages"
