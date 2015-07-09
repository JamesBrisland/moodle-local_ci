#!/bin/bash

# Don't be strict. Script has own error control handle
set +e

# Include the config file!
echo "00. Setup Job start time: ${setup_build_start_time}"
echo Unique Job Ident: ${unique_job_ident}
config_file_path=${JENKINS_HOME}/git_repositories/config_files/${setup_build_start_time}_${unique_job_ident}
. ${config_file_path}

if [ ! -z "${GIT_COMMIT}" ]; then
    echo GIT_COMMIT=${GIT_COMMIT} >> $config_file_path
fi
if [ ! -z "${GIT_PREVIOUS_COMMIT}" ]; then
    echo GIT_PREVIOUS_COMMIT=${GIT_PREVIOUS_COMMIT} >> $config_file_path
fi

#- Copy the directory into a unique one in /var/www/html so that if we rebuild this job process it's always running
#- on a unique checkout of the code
echo -e "Setup apache www folder /var/www/html/${setup_build_start_time}_${unique_job_ident}\n\n"
cp -pR ${gitdir} "/var/www/html/${setup_build_start_time}_${unique_job_ident}"