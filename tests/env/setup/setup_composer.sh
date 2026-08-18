#!/bin/sh

# Installs Composer (latest version, to avoid relying on old ones bundled with the OS)
# @todo allow users to lock down to Composer v1 or v2.2 if needed

echo "Installing Composer..."

export DEBIAN_FRONTEND=noninteractive

if dpkg -l composer 2>/dev/null; then
    apt-get remove -y composer
fi

### Code below taken from https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md

EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"

if [ -z "$EXPECTED_CHECKSUM" ]
then
    >&2 echo 'ERROR: Failed downloading composer installer checksum'
    exit 1
fi

php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]
then
    >&2 echo 'ERROR: Invalid installer checksum'
    rm composer-setup.php
    exit 1
fi


php composer-setup.php --quiet
RESULT=$?
rm composer-setup.php

###

if [ "$RESULT" = 0 ]; then
    if [ -f ./composer.phar ]; then
        mv ./composer.phar /usr/local/bin/composer && chmod 755 /usr/local/bin/composer
    fi
    if [ -f /usr/local/bin/composer.phar ]; then
        mv /usr/local/bin/composer.phar /usr/local/bin/composer && chmod 755 /usr/local/bin/composer
    fi

    /usr/local/bin/composer diagnose --no-interaction
fi

echo "Done installing Composer"

exit $RESULT
