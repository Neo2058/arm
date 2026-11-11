#!/bin/zsh
# Launch from Finder or Terminal; no credentials and no network calls.
SCRIPT_DIR=${0:A:h}
/usr/bin/python3 "$SCRIPT_DIR/tools/mix_audio.py" "$@"
RESULT=$?
if [[ -t 0 ]]; then
  printf '\nНажмите Enter, чтобы закрыть окно…'
  read -r
fi
exit "$RESULT"
