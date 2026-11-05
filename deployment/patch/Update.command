#!/bin/bash
cd "$(dirname "$0")" || exit 1
read -r -p 'Full path to the existing installed Yodamen folder: ' installed
bash update.sh --installation "$installed"
result=$?
read -r -p 'Press Enter to close. ' ignored
exit "$result"
