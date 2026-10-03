#!/bin/sh
# Credentials read from environment - set these before running:
# export MONGO_ATLAS_HOST=cluster0.xxxxx.mongodb.net
# export MONGO_ATLAS_USER=your_atlas_user
# export MONGO_ATLAS_PASS=your_atlas_password

mongorestore "mongodb+srv://$MONGO_ATLAS_HOST" \
  -u "$MONGO_ATLAS_USER" -p "$MONGO_ATLAS_PASS" \
  --gzip --drop --archive=/tmp/wwwdrv-`date +%y%m%d`.gz
#rm /tmp/wwwdrv-`date +%y%m%d`.gz
