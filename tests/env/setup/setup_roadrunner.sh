#!/bin/sh

# Has to be run as root

ROADRUNNER_USER=roadrunner
ROADRUNNER_GROUP=roadrunner

# @todo check first if the user already exists
./create_user.sh "$ROADRUNNER_USER" 2001 2001

if [ ! -d /etc/roadrunner ]; then
    mkdir /etc/roadrunner
fi
if [ ! -d /var/lib/roadrunner ]; then
    mkdir /var/lib/roadrunner
fi
chown "$ROADRUNNER_USER:$ROADRUNNER_GROUP" /var/lib/roadrunner
if [ ! -d /var/log/roadrunner ]; then
    mkdir /var/log/roadrunner
fi
# Allow non-owner/root to list roadrunner log files, same as it is possible for nginx
chmod 755 /var/log/roadrunner
chown "$ROADRUNNER_USER:adm" /var/log/roadrunner
if [ ! -d /run/roadrunner ]; then
    mkdir /run/roadrunner
fi
chown "$ROADRUNNER_USER:$ROADRUNNER_GROUP" /run/roadrunner

# configure virtual hosts

cp -f "$SCRIPT_DIR/../config/rr.yaml" /etc/roadrunner/rr.yaml

if [ -n "${GITHUB_ACTIONS}" ]; then
    ### @todo...
    TESTS_ROOT_DIR="$(pwd)"
    # @todo...
    ###sed -e "s|^ *root .*|    root ${TESTS_ROOT_DIR}/tests/public|g" --in-place /etc/roadrunner/rr.yaml
    ###sed -r -e "s|^ *output +stdout.*|        output file /var/log/frankenphp/frankenphp.log|g" --in-place /etc/roadrunner/rr.yaml
else
    setcap 'cap_net_bind_service=+ep' /usr/bin/rr

    cp "$SCRIPT_DIR/../config/init.d/roadrunner" /etc/init.d/roadrunner && chmod 755 /etc/init.d/roadrunner
fi

if [ ! -d /tmp/rrsetup ]; then  mkdir /tmp/rrsetup; fi
cd /tmp/rrsetup

composer require spiral/roadrunner-cli
./vendor/bin/rr get-binary --no-interaction --location /usr/bin

#cd
#rm -rf /tmp/rrsetup
