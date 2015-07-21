#!/bin/bash

# Include the config file!
config_file_path=${JENKINS_HOME}/git_repositories/config_files/${setup_build_start_time}_${unique_job_ident}
. ${config_file_path}

# Don't be strict. Script has own error control handle
set +e

# Check params passed down
echo "Unique Job ID: ${unique_job_ident}"
echo "Setup build start time: ${setup_build_start_time}"

echo "Cleanup"
rm -Rf ${gitdir}
rm -f ${config_file_path}