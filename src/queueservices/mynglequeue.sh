#!/bin/bash

DATE=`date '+%Y-%m-%dT%H-%M-%S'`
LOGFILE='/var/log/queueservices.log'
#LOGFOLDER='/home/duongtc/Data/Temp/myngle_items/AH-3509/log/'
SCRIPTDIR="/data/autoscript/queueservices"
#SCRIPTFILE="/home/duongtc/Data/Temp/myngle_items/AH-3509/restart_bbb"
SCRIPTFILE="mynglequeue.php"

echo "$DATE Queue services is started" >> "${LOGFILE}"

while
  sleep 1;
do
    cd "${SCRIPTDIR}"
    php "${SCRIPTDIR}/${SCRIPTFILE}" >> "${LOGFILE}"
done

echo "$DATE Cannot execute sleep" >> "${LOGFILE}"
