#!/bin/sh

set -e

echo "Creating user account..."

# @todo move to getopts, to allow a custom group name, optional sudoer status...

USERNAME="${1:-docker}"
USER_ID="${2:-2000}"
USER_GID="${3:-2000}"

if [ -z "${GITHUB_ACTIONS}" ]; then
    # adduser is not preinstalled on noble
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        adduser

    # on ubuntu 24 noble at least, user ubuntu has id 1000, which clashes with our custom users later on
    if [ -d /home/ubuntu ]; then
        userdel ubuntu || true
        rm -rf /home/ubuntu
    fi
fi

addgroup --gid "${USER_GID}" "${USERNAME}"
adduser --system --uid="${USER_ID}" --gid="${USER_GID}" --home "/home/${USERNAME}" --shell /bin/bash "${USERNAME}"
adduser "${USERNAME}" "${USERNAME}"

mkdir -p "/home/${USERNAME}/.ssh"
cp -r /etc/skel/.[!.]* "/home/${USERNAME}"

chown -R "${USERNAME}:${USERNAME}" "/home/${USERNAME}"
# in case we later mount the website root under "/home/${USERNAME}", we have to change perms from 750 to 755
chmod 755 "/home/${USERNAME}"

# @todo make this optional instead of disabling it for gha
if [ -z "${GITHUB_ACTIONS}" ]; then
    if [ -f /etc/sudoers ]; then
        adduser "${USERNAME}" sudo
        sed -e "\$ a ${USERNAME}   ALL=\(ALL:ALL\) NOPASSWD: ALL" --in-place  /etc/sudoers
    fi
fi

echo "Done creating user account"
