#!/bin/bash
# Don't be strict. Script has own error control handle
set +e

config_file=${JENKINS_HOME}/git_repositories/config_files/${setup_build_number}_${config_file}
echo Config file: ${config_file}

if [ ! -z "${GIT_COMMIT}" ]; then
    echo GIT_COMMIT=${GIT_COMMIT} >> $config_file
fi
if [ ! -z "${GIT_PREVIOUS_COMMIT}" ]; then
    echo GIT_PREVIOUS_COMMIT=${GIT_PREVIOUS_COMMIT} >> $config_file
fi