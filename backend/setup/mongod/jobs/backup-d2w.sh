#!/bin/sh
# Credentials read from environment - set these before running:
# export MONGO_LOCAL_USER=drvuser
# export MONGO_LOCAL_PASS=your_password
# export MONGO_ATLAS_HOST=cluster0.xxxxx.mongodb.net
# export MONGO_ATLAS_USER=your_atlas_user
# export MONGO_ATLAS_PASS=your_atlas_password

mongodump -d wwwdrv --excludeCollection=cache_gd --excludeCollection=accessTokens \
  --gzip -u "$MONGO_LOCAL_USER" -p "$MONGO_LOCAL_PASS" \
  --ssl --tlsInsecure --archive=/tmp/wwwdrv-`date +%y%m%d`.gz

mongorestore "mongodb+srv://$MONGO_ATLAS_HOST" \
  -u "$MONGO_ATLAS_USER" -p "$MONGO_ATLAS_PASS" \
  --gzip --drop --numInsertionWorkersPerCollection=8 \
  --archive=/tmp/wwwdrv-`date +%y%m%d`.gz

wait
rm /tmp/wwwdrv-`date +%y%m%d`.gz
