#!/bin/sh
set -eu
mkdir -p /data/export
odvr_metadata=$(find /data/export -name '*.overall_export_metadata' -type f -print -quit)
# 公式emulatorのshutdown入口がexportを完了するまで待ち、SIGKILLへ依存しない。
stop_emulator() {
  trap '' TERM INT
  if ! python3 -c 'import urllib.request; data=b"{\"database\":\"projects/odvr-local/databases/(default)\",\"exportDirectory\":\"/data/export\",\"exportName\":\"odvr-local\"}"; urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:8085/emulator/v1/projects/odvr-local/databases/(default):export",data=data,headers={"Content-Type":"application/json"}),timeout=40).read(); urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:8085/shutdown",data=b"",method="POST"),timeout=5).read()'; then
    kill -TERM "$odvr_pid" 2>/dev/null || true
    wait "$odvr_pid" || true
    exit 1
  fi
  wait "$odvr_pid" || true
  exit 0
}
trap stop_emulator TERM INT
if [ -n "$odvr_metadata" ]; then
  java -Duser.language=en -cp /google-cloud-sdk/platform/cloud-firestore-emulator/cloud-firestore-emulator.jar com.google.cloud.datastore.emulator.firestore.CloudFirestore start --host=0.0.0.0 --port=8085 --database-mode=firestore-native --project_id=odvr-local --single_project_mode=true --import-data="$odvr_metadata" &
else
  java -Duser.language=en -cp /google-cloud-sdk/platform/cloud-firestore-emulator/cloud-firestore-emulator.jar com.google.cloud.datastore.emulator.firestore.CloudFirestore start --host=0.0.0.0 --port=8085 --database-mode=firestore-native --project_id=odvr-local --single_project_mode=true &
fi
odvr_pid=$!
wait "$odvr_pid"
