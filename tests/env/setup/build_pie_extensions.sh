#!/bin/sh

# Builds custom php extensions in a dedicated container (using PIE), leaves them in /usr/lib/php/pie/
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

./setup_php.sh "$PHP_VERSION" -e "cli dev"

EXTDIR=$(php -r 'echo ini_get("extension_dir");')
BUILTIN_EXTS="$(ls $EXTDIR/*.so | tr '\n' ' ')"

./setup_pie_extensions.sh $PIE_EXTENSIONS

mkdir /usr/lib/php/pie
for EXT in $EXTDIR/*.so; do
    case " $BUILTIN_EXTS " in
        *" $EXT "*) . ;;
        *) mv "$EXT" /usr/lib/php/pie/ ;;
    esac
done
