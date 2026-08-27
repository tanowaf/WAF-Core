#!/bin/sh

# Builds custom php extensions using PIE

set -e

# @todo g++ is needed for building openswoole - apparently it is not pulled in by pie. Only pull it in if needed...
# assumes curl is already installed
apt-get install -y g++

# @todo install the github cli to verify the pie download (see f.e. https://linuxcapable.com/how-to-install-github-cli-on-ubuntu-linux/)
#  && gh attestation verify --owner php /tmp/pie.phar \
curl -fL --output /tmp/pie.phar https://github.com/php/pie/releases/latest/download/pie.phar && \
    mv /tmp/pie.phar /usr/local/bin/pie && \
    chmod +x /usr/local/bin/pie

for EXTENSION in "$@"; do
    # @todo allow passing in optional args, eg. for openswoole: --enable-openssl  --enable-sockets --enable-http2 --enable-hook-curl
    pie install --auto-install-build-tools --auto-install-system-dependencies --no-interaction "$EXTENSION"
done
