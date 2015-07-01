#!/bin/bash
# Don't be strict. Script has own error control handle
set +e

config_file=${JENKINS_HOME}/git_repositories/${BUILD_NUMBER}_${config_file}

echo GIT_COMMIT=${GIT_COMMIT} >> $config_file
echo GIT_PREVIOUS_COMMIT=${GIT_PREVIOUS_COMMIT} >> $config_file