#!/bin/sh

# Has to be run as root

set -e

echo "Installing base software packages..."

UPDATE_INSTALLED=false

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
shift $((OPTIND-1))

export DEBIAN_FRONTEND=noninteractive

if [ ! -d /usr/share/man/man1 ]; then mkdir -p /usr/share/man/man1; fi

apt-get update --allow-releaseinfo-change

if [ "$UPDATE_INSTALLED" = true ]; then
    apt-get upgrade -y
fi

# @todo allow passing in the list of packages to install via an env var / cli option

# Curl is not required atm to run tests, but it is a good tool to run manual tests on the command-line.
# It can query a unix socket too, via option `--unix-socket`
# Alternatives would be netcat, socat
apt-get install -y \
    curl git ncompress sudo unzip wrk

echo "Done installing base software packages"
