#!/bin/bash
# Don't be strict. Script has own error control handle
set +e

# Get all the current vars available to bash and look for the jenkins_ ones
jenkins_vars=$(( set -o posix ; set ) | grep ".*jenkins_.*" )

mkdir ${JENKINS_HOME}/git_repositories/config_files

# Check params passed down
echo "Unique Job ID: ${unique_job_ident}"
echo "Setup build start time: ${setup_build_start_time}"

# Include the config file!
config_file_path=${JENKINS_HOME}/git_repositories/config_files/${setup_build_start_time}_${unique_job_ident}
. ${config_file_path}

touch $config_file_path

# Setup the proxy in the config file
current_date=`date`
echo "#################################################################################################################" >> $config_file_path
echo "# ${current_date} : Setup Config Script Build Number ${BUILD_NUMBER}" >> $config_file_path
echo "#################################################################################################################" >> $config_file_path
echo export http_proxy=wwwcache.open.ac.uk:80 >> $config_file_path
echo export https_proxy=wwwcache.open.ac.uk:80 >> $config_file_path
echo export no_proxy=localhost,127.0.0.0/8,127.0.1.1,127.0.1.1*,local.home >> $config_file_path

# Put these vars manually into the config file even though they are passed about by jenkins because they make the config
# file path name I will need them in the php script later
echo "unique_job_ident=\"${unique_job_ident}\"" >> $config_file_path
echo "setup_build_start_time=\"${setup_build_start_time}\"" >> $config_file_path

# Setup all the jenkins_ vars in the config file
for i in $jenkins_vars;
do
    # Replaces the FIRST jenkins_ with nothing and puts it into the config file
    echo ${i/jenkins_/} >> $config_file_path
done
