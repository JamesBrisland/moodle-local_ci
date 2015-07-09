#!/bin/bash
# Don't be strict. Script has own error control handle
set +e

echo "00. Setup Job start time: ${setup_build_start_time}"
echo Config file: ${config_file}
config_file_path=${JENKINS_HOME}/git_repositories/config_files/${setup_build_start_time}_${config_file}

. ${config_file_path}

if [ ! -z "${GIT_COMMIT}" ]; then
    echo GIT_COMMIT=${GIT_COMMIT} >> $config_file
fi
if [ ! -z "${GIT_PREVIOUS_COMMIT}" ]; then
    echo GIT_PREVIOUS_COMMIT=${GIT_PREVIOUS_COMMIT} >> $config_file
fi

#- Copy the directory into a unique one in /var/www/html so that if we rebuild this job process it's always running
#- on a unique checkout of the code
echo -e "Setup apache www folder /var/www/html/${setup_build_start_time}_${config_file}\n\n"
cp -pR ${gitdir} "/var/www/html/${setup_build_start_time}_${config_file}"