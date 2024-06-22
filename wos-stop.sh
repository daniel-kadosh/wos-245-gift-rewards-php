#!/bin/bash
set -x
CONTAINER=`sudo docker ps | grep wos-245-gift-rewards-php | head -1 | awk '{print $1}'`
sudo docker kill ${CONTAINER}
