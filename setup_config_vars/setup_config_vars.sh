#!/bin/bash
# Don't be strict. Script has own error control handle
set +e

# Get all the current vars available to bash and look for the jenkins_ ones
jenkins_vars=$(( set -o posix ; set ) | grep ".*jenkins_.*" )

mkdir ${JENKINS_HOME}/git_repositories/config_files

setup_job_start_time=`date +%Y%m%d_%H%M%S`
config_file=${JENKINS_HOME}/git_repositories/config_files/${setup_job_start_time}_${config_file}

#- Export the start time for use in other scripts
export setup_job_start_time=${setup_job_start_time}

echo "00. Setup Job start time: ${setup_job_start_time}"
echo Config file: ${config_file}
export -p

# Delete the config file and create a new one
rm -f $config_file
touch $config_file

# Setup the proxy in the config file
current_date=`date`
echo "#################################################################################################################" >> $config_file
echo "# ${current_date} : Setup Config Script Build Number ${BUILD_NUMBER}" >> $config_file
echo "#################################################################################################################" >> $config_file
echo export http_proxy=wwwcache.open.ac.uk:80 >> $config_file
echo export https_proxy=wwwcache.open.ac.uk:80 >> $config_file
echo export no_proxy=localhost,127.0.0.0/8,127.0.1.1,127.0.1.1*,local.home >> $config_file

# Setup all the jenkins_ vars in the config file
for i in $jenkins_vars;
do
    # Replaces the FIRST jenkins_ with nothing and puts it into the config file
    echo ${i/jenkins_/} >> $config_file
done
