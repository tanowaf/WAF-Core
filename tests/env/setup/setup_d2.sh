#!/bin/sh

echo "Installing D2..."

export DEBIAN_FRONTEND=noninteractive

apt-get install -y make

curl -fsSL https://d2lang.com/install.sh | sh -s --

echo "Done Installing D2"
