#!/bin/sh

# Builds custom php extensions in a dedicated container (using PIE)
#
# Uses env vars: PHP_VERSION, APT_PACKAGE_PROXY

# @todo the installation of extensions via PIE requires the presence of `phpize`, even when a "prebuilt archive" is
#       available. phpize in turn is downloaded as an apt package, which brings in gcc and co. as dependencies.
#       Which means a lot of disk bloat and long build times...
#       Can we obviate to that in any way (apart from the 2-stage build we currently use)?
#       Take a look f.e. at https://github.com/mlocati/docker-php-extension-installer

set -e

if [ ! -f /.dockerenv ]; then touch /.dockerenv; fi

if [ -z "$PHP_VERSION" ]; then
    echo "PHP version has to be specified as env var" >&2
    exit 1
fi

PIE_EXTENSIONS="$1"

if [ -z "$PIE_EXTENSIONS" ]; then
    echo "PIE extensions have to be specified as 1st argument" >&2
    exit 1
fi

# Allow the user to specify a proxy for speeding up downloading of apt packages
if [ -n "${APT_PACKAGE_PROXY}" ] && [ "${APT_PACKAGE_PROXY}" != "none" ]; then
    printf "Acquire::http::Proxy \"${APT_PACKAGE_PROXY}\";\nAcquire::https::Proxy \"DIRECT\";\n" > /etc/apt/apt.conf.d/00proxy
fi

cd /root/setup

export DEBIAN_FRONTEND=noninteractive

apt-get update --allow-releaseinfo-change
# @todo g++ is needed for building openswoole - apparently it is not pulled in by pie. Only pull it in if needed...
apt-get install -y curl g++

./setup_php.sh "$PHP_VERSION" -e "cli dev"

EXTDIR=$(php -r 'echo ini_get("extension_dir");')
BUILTIN_EXTS="$(ls $EXTDIR/*.so | tr '\n' ' ')"

# @todo install the github cli to verify the pie download (see f.e. https://linuxcapable.com/how-to-install-github-cli-on-ubuntu-linux/)
#  && gh attestation verify --owner php /tmp/pie.phar \
curl -fL --output /tmp/pie.phar https://github.com/php/pie/releases/latest/download/pie.phar && \
    mv /tmp/pie.phar /usr/local/bin/pie && \
    chmod +x /usr/local/bin/pie

for EXTENSION in $PIE_EXTENSIONS; do
    # @todo allow passing in optional args, eg. for openswoole: --enable-openssl  --enable-sockets --enable-http2 --enable-hook-curl
    pie install --auto-install-build-tools --auto-install-system-dependencies --no-interaction "$EXTENSION"
done

mkdir /usr/lib/php/pie
for EXT in $EXTDIR/*.so; do
    case " $BUILTIN_EXTS " in
        *" $EXT "*) . ;;
        *) mv "$EXT" /usr/lib/php/pie/ ;;
    esac
done
