#!/bin/bash

# Include the config file!
config_file=${JENKINS_HOME}/git_repositories/config_files/${setup_build_number}_${config_file}
echo Config File: ${config_file}

# Add the behat_workspace and build number to the config file
echo "behat_workspace=\"${WORKSPACE}\"" >> $config_file
echo "behat_build_number=${BUILD_NUMBER}" >> $config_file

. ${config_file}

# Don't be strict. Script has own error control handle
set +e

# Folder to capture execution output
mkdir "${WORKSPACE}/${BUILD_NUMBER}"

# Folder to for successful behat test pass git commit numbers
mkdir "${WORKSPACE}/successful"

# Folder for details of successful runs. Will contain a file for each of the behat features along with the latest commit
# when the last successful run was
mkdir "${WORKSPACE}/successful"

composer_init_output=${WORKSPACE}/${BUILD_NUMBER}/composer_init.txt
behat_init_output=${WORKSPACE}/${BUILD_NUMBER}/behat_init.txt
behat_pretty_full_output=${WORKSPACE}/${BUILD_NUMBER}/behat_pretty_full.txt
behat_pretty_moodle_output=${WORKSPACE}/${BUILD_NUMBER}/behat_pretty_moodle.txt
selenium_output=${WORKSPACE}/${BUILD_NUMBER}/selenium.txt

# file where results will be sent
junit_output_folder=${WORKSPACE}/${BUILD_NUMBER}/behat_junit_xml

# calculate some variables
mydir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
installdb=ci_behat_${BUILD_NUMBER}_${EXECUTOR_NUMBER}
datadirbehat=${datadir}/moodledata_behat_${BUILD_NUMBER}_${EXECUTOR_NUMBER}
behatfaildump=${datadir}/behatfailedump_${BUILD_NUMBER}_${EXECUTOR_NUMBER}
datadir=${datadir}/moodledata_${BUILD_NUMBER}_${EXECUTOR_NUMBER}

# prepare the composer stuff needed to run this job
. ${mydir}/../prepare_composer_stuff/prepare_composer_stuff.sh

# Reset the error controls as we are sourcing prepare_composer_stuff.sh which calls set -e which also resets it in this script.
set +e

# Going to install the $gitbranch database
# Create the database
# Based on $dbtype, execute different DB creation commands (mysqli, pgsql)
if [[ "${dbtype}" == "pgsql" ]]; then
    export PGPASSWORD=${dbpass}
    ${psqlcmd} -h ${dbhost} -U ${dbuser} -d template1 \
        -c "CREATE DATABASE ${installdb} ENCODING 'utf8'"
elif [[ "${dbtype}" == "mysqli" ]]; then
    ${mysqlcmd} --user=${dbuser} --password=${dbpass} --host=${dbhost} \
        --execute="CREATE DATABASE ${installdb} CHARACTER SET utf8 COLLATE utf8_bin"
else
    echo "Error: Incorrect dbtype=${dbtype}"
    exit 1
fi
# Error creating DB, we cannot continue. Exit
exitstatus=${PIPESTATUS[0]}
if [ $exitstatus -ne 0 ]; then
    echo "Error creating database $installdb to run behat tests"
    exit $exitstatus
fi

# Do the moodle install
cd $gitdir && git reset --hard $gitbranch
rm -fr config.php

# To execute the behat tests we don't need a real site installed, just the behat-prefixed one.
# For now we are using one template config.php containing all the required vars and then we run the init shell script
# But surely all those vars will be configured via params soon (stage 4/5 of migration to behat)
# So, until then, let's create the config.php based on template
replacements="%%DBLIBRARY%%#${dblibrary}
%%DBTYPE%%#${dbtype}
%%DBHOST%%#${dbhost}
%%DBUSER%%#${dbuser}
%%DBPASS%%#${dbpass}
%%DBNAME%%#${installdb}
%%DATADIR%%#${datadir}
%%DATADIRBEHAT%%#${datadirbehat}
%%BEHATPREFIX%%#${behatprefix}
%%BEHATURL%%#${behaturl}/jenkins_${BUILD_NUMBER}
%%MOODLEURL%%#${moodleurl}/jenkins_${BUILD_NUMBER}
%%BEHATFAILDUMP%%#${behatfaildump}
%%TIMEZONE%%#${timezone}
"

# Apply template transformations
text="$( cat ${mydir}/config.php.template )"
for i in ${replacements}; do
    text=$( echo "${text}" | sed "s#${i}#g" )
done

# Apply extra configuration separatedly (multiline...)
text=$( echo "${text}" | perl -0pe "s!%%EXTRACONFIG%%!${extraconfig}!g" )


# Save the config.php into destination
echo "${text}" > ${gitdir}/config.php

# Create the moodledata dir
mkdir -p "${datadir}"
mkdir -p "${datadirbehat}"
mkdir -p "${behatfaildump}"

# Run the behat init script
${phpcmd} ${gitdir}/admin/tool/behat/cli/init.php 2>&1 | tee "${behat_init_output}"
exitstatus=${PIPESTATUS[0]}
if [ $exitstatus -ne 0 ]; then
    echo "Error installing database $installdb to run behat tests"
fi

# Execute the behat utility
# Conditionally
if [ $exitstatus -eq 0 ]; then
    echo -e "\n\n---------------------------------------------------------------\n\n"
    echo Datadir in ${datadirbehat}
    echo Behat init output: ${behat_init_output}
    echo Behat pretty output: ${behat_pretty_output}
    echo Selenium output: ${selenium_output}
    echo -e "\n\n---------------------------------------------------------------\n\n"
    date
    echo -e "\n\n---------------------------------------------------------------\n\n"
    echo "Setup apache www folder /var/www/html/jenkins_${BUILD_NUMBER}"
    ln -s ${gitdir} /var/www/html/jenkins_${BUILD_NUMBER}
    echo "Launching Selenium and sleeping for 2 seconds to allow time for launch"
    /opt/selenium/selenium.sh > "${selenium_output}" 2>&1 &
    sleep 2
    # Remove the proxy for behat - for some reason it's hit and miss as to when it works
    unset http_proxy
    unset https_proxy
    unset no_proxy
    echo "Unsetting proxy as it's not needed when runnig behat tests locally. Moodle config has proxy settings in for anything Moodle does."
    echo -e "\n\n---------------------------------------------------------------\n\n"

    # Setup the tags
    if [ "${behat_do_full_run}" = "yes" ]; then
        tags=${behat_tags_full_run}
    else
        tags=${behat_tags_partial_run}
    fi

    # Check to see if we have an override
    if [ ! -z "${behat_tags_override}" -a "${behat_tags_override}" != " " ]; then
        tags=${behat_tags_override}
    fi

    echo Execuiting tags ${tags}

    # This will output the moodle_progress format to the console and write moodle_progress, behat pretty and junit formats to files
    ${gitdir}/vendor/bin/behat -v --config "${datadirbehat}/behat/behat.yml" --format moodle_progress,moodle_progress,pretty,junit --out=,"${behat_pretty_moodle_output}","${behat_pretty_full_output}","${junit_output_folder}" --tags=${tags} --profile=chrome
    exitstatus=${PIPESTATUS[0]}
    echo "Behat finished. Exit status ${exitstatus}"
fi

# Look for any stack sent to output, it will lead to failed execution
# Conditionally
if [ $exitstatus -ne 0 ]; then
    # notices/warnings/errors under simpletest (behat captures them)
    stacks=$(grep -r 'Call Stack:' "${junit_output_folder}" | wc -l)
    if [[ ${stacks} -gt 0 ]]; then
        echo -e "\n\n"
        echo "ERROR: uncontrolled notice/warning/error output on execution."
        grep -r 'Call Stack:' "${junit_output_folder}"
        exitstatus=1
    fi
    # debugging messages
    debugging=$(grep -r 'Debugging:' "${junit_output_folder}" | wc -l)
    if [[ ${debugging} -gt 0 ]]; then
        echo -e "\n\n"
        echo "ERROR: uncontrolled debugging output on execution."
        grep -r 'Debugging:' "${junit_output_folder}"
        exitstatus=1
    fi
    # general backtrace information
    backtrace=$(grep -r 'line [0-9]* of .*: call to' "${junit_output_folder}" | wc -l)
    if [[ ${backtrace} -gt 0 ]]; then
        echo -e "\n\n"
        echo "ERROR: uncontrolled backtrace output on execution."
        grep -r 'line [0-9]* of .*: call to' "${junit_output_folder}"
        exitstatus=1
    fi
    # anything exceptional (not dots and numbers) in the execution lines.
    exceptional=$(grep -rP '^\.|%\)$' "${junit_output_folder}" | grep -vP '^[\.SIEF]*[ \d/\(\)%]*$' | wc -l)
    if [[ ${exceptional} -gt 0 ]]; then
        echo -e "\n\n"
        echo "ERROR: uncontrolled exceptional output on execution."
        grep -rP '^\.|%\)$' "${junit_output_folder}" | grep -vP '^[\.SIEF]*[ \d/\(\)%]*$'
        exitstatus=1
    fi
    # Exceptions.
    exception=$(grep -r 'Exception' "${junit_output_folder}" | wc -l)
    if [[ ${exception} -gt 0 ]]; then
        echo -e "\n\n"
        echo "ERROR: exception during behat run."
        grep -r 'Exception' "${junit_output_folder}"
        exitstatus=1
    fi
fi

# Drop the databases and delete files
# Based on $dbtype, execute different DB deletion commands (pgsql, mysqli)
if [[ "${dbtype}" == "pgsql" ]]; then
    export PGPASSWORD=${dbpass}
    ${psqlcmd} -h ${dbhost} -U ${dbuser} -d template1 \
        -c "DROP DATABASE ${installdb}"
elif [[ "${dbtype}" == "mysqli" ]]; then
    ${mysqlcmd} --user=${dbuser} --password=${dbpass} --host=${dbhost} \
        --execute="DROP DATABASE ${installdb}"
else
    echo "Error: Incorrect dbtype=${dbtype}"
    exit 1
fi

mkdir "${WORKSPACE}/${BUILD_NUMBER}/screenshots"
find "${behatfaildump}" -exec cp -p {} "${WORKSPACE}/${BUILD_NUMBER}/screenshots" \;
cp ${config_file} "${WORKSPACE}/${BUILD_NUMBER}/config"
chmod 665 "${WORKSPACE}/${BUILD_NUMBER}/screenshots"
#rm -fr config.php
rm -fr ${datadir}
rm -fr ${datadirbehat}

if [ ${exitstatus} -ne 0 ]; then
    # There has been some errors. Process them
    php /var/lib/jenkins/git_repositories/ci_scripts/run_behat/process_results.php -w="${WORKSPACE}/${BUILD_NUMBER}"
fi

# If arrived here, return the exitstatus of the php execution
exit $exitstatus