#!/bin/bash
# Don't be strict. Script has own error control handle
set +e

# Get all the current vars available to bash and look for the jenkins_ ones
jenkins_vars=$(( set -o posix ; set ) | grep ".*jenkins_.*" )

mkdir ${JENKINS_HOME}/git_repositories/config_files;
config_file=${JENKINS_HOME}/git_repositories/config_files/${BUILD_NUMBER}_${config_file}
echo Config File: ${config_file}

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