#!/usr/bin/env bash

# Get the absolute path of the script itself
SCRIPT_PATH="$(realpath "${BASH_SOURCE[0]}")"

# Get the absolute path of the directory containing the script
SCRIPT_DIR="$(dirname "${SCRIPT_PATH}")"

# Get the absolute path of the parent directory of the script's directory
PARENT_DIR="$(dirname "${SCRIPT_DIR}")"

echo "Script path: ${SCRIPT_PATH}"
echo "Script directory: ${SCRIPT_DIR}"
echo "Parent directory: ${PARENT_DIR}"