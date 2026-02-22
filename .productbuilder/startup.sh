#!/usr/bin/env bash

apt-get update
apt-get install -yy docker.io docker-compose docker-buildx curl
curl https://mise.run | sh
ln -s ~/.local/bin/mise /usr/local/bin/
