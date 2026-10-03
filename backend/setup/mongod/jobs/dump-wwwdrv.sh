#!/bin/sh
# Credentials read from environment - set these before running:
# export MONGO_LOCAL_USER=drvuser
# export MONGO_LOCAL_PASS=your_password

mongodump -d wwwdrv --excludeCollection=cache_gd --excludeCollection=accessTokens \
  --gzip -u "$MONGO_LOCAL_USER" -p "$MONGO_LOCAL_PASS" \
  --ssl --tlsInsecure --archive=/tmp/wwwdrv-`date +%y%m%d`.gz
