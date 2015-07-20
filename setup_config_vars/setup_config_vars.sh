#!/bin/bash
# Don't be strict. Script has own error control handle
set +e

# Get all the current vars available to bash and look for the jenkins_ ones
jenkins_vars=$(( set -o posix ; set ) | grep ".*jenkins_.*" )

mkdir ${JENKINS_HOME}/git_repositories/config_files

# Check params passed down
echo "Unique Job ID: ${unique_job_ident}"
echo "Setup build start time: ${setup_build_start_time}"

config_file_path=${JENKINS_HOME}/git_repositories/config_files/${setup_build_start_time}_${unique_job_ident}

touch $config_file_path

current_date=`date`

# If we are re-running a config file all we want to do is change the setup_build_start_time and behat_by_files_run
# everything else stays as it was
if [[ ! -z $rerun_config_file ]]; then
    echo "Re-running from config ${rerun_config_file} with only one behat file"
    cp $rerun_config_file $config_file_path
    # Setup the proxy in the config file
    echo "#################################################################################################################" >> $config_file_path
    echo "# RERUN : ${current_date} : Setup Config Script Build Number ${BUILD_NUMBER}" >> $config_file_path
    echo "#################################################################################################################" >> $config_file_path
    echo "setup_build_start_time=${setup_build_start_time}" >> $config_file_path
    echo "behat_by_files_run=${jenkins_behat_by_files_run}" >> $config_file_path
else
    # Setup the proxy in the config file
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

    # Before we do anything more check to see if we have multi adhoc runs to file off (multi jenkins_files_to_run)
    # If we do trigger all and restart with the same params asside from the files_to_run just being a single run
    OIFS=$IFS
    IFS=';'
    arr=($jenkins_behat_by_files_run)
    IFS=$OIFS
    uniq=($(printf "%s\n" "${arr[@]}" | sort -u));

    mydir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

    if [[ ${#uniq[@]} > 1  ]]; then
        echo More than one file to be run. Firing off scripts with all params

        for x in "${uniq[@]}"
        do
            echo "Firing off process for '$x'"
            java -jar ${mydir}/../jenkins_cli/jenkins-cli.jar -s ${JENKINS_URL} build "00. Setup Params (OUVLE - Ad-Hoc test runs)" -s -p jenkins_behat_by_files_run="${x}" -p rerun_config_file="${config_file_path}"
        done

        echo "Failing because we do not want to trigger downstream, we want to run the other jobs we have triggered"
        exit 1
    fi
fi