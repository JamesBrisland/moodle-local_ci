#!/bin/bash

# Don't be strict. Script has own error control handle
set +e

# Check params passed down
echo "Unique Job ID: ${unique_job_ident}"
echo "Setup build start time: ${setup_build_start_time}"
echo "Display: ${DISPLAY}"
export

# Include the config file!
config_file_path=${JENKINS_HOME}/git_repositories/config_files/${setup_build_start_time}_${unique_job_ident}
. ${config_file_path}

# Grab the display number removing the : from the front
display_number=${DISPLAY/:/}

# Add the behat_workspace and build number to the config file
echo "behat_workspace=\"${WORKSPACE}\"" >> $config_file_path
echo "behat_build_number=${BUILD_NUMBER}" >> $config_file_path
echo "behat_job_name=\"${JOB_NAME}\"" >> $config_file_path

# Check for tags
if [[ (-z "${behat_tags_override}" || "${behat_tags_override}" == " ") && (-z "${behat_do_full_run}" || "${behat_do_full_run}" == " ") && (-z "${behat_tags_partial_run}" || "${behat_tags_partial_run}" == " ") && (-z "${behat_by_files_run}" || "${behat_by_files_run}" == " ") ]] ; then
    echo "No tags specified. Behat execution cancelled. Exiting."
    exit 1;
fi

# Folder to capture execution output
mkdir "${WORKSPACE}/${BUILD_NUMBER}"

# Folder for details of successful runs. Will contain a file for each of the behat features along with the latest commit
# when the last successful run was
mkdir -p "${WORKSPACE}/../behat_success_xml_${unique_job_ident}"

composer_init_output=${WORKSPACE}/${BUILD_NUMBER}/composer_init.txt
behat_init_output=${WORKSPACE}/${BUILD_NUMBER}/behat_init.txt
behat_pretty_full_output=${WORKSPACE}/${BUILD_NUMBER}/behat_pretty_full.txt
behat_pretty_moodle_output=${WORKSPACE}/${BUILD_NUMBER}/behat_pretty_moodle.txt
selenium_hub_output=${WORKSPACE}/${BUILD_NUMBER}/selenium_hub_output.txt
selenium_node_output=${WORKSPACE}/${BUILD_NUMBER}/selenium_node_output.txt

# file where results will be sent
junit_output_folder=${WORKSPACE}/${BUILD_NUMBER}/behat_junit_xml

# calculate some variables
mydir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
installdb=ci_behat_${setup_build_start_time}_${unique_job_ident}
datadirbehat=${datadir}/${setup_build_start_time}_${unique_job_ident}_moodledata_behat
behatfaildump=${datadir}/${setup_build_start_time}_${unique_job_ident}_behatfailedump
datadir=${datadir}/${setup_build_start_time}_${unique_job_ident}_moodledata

# prepare the composer stuff needed to run this job
. ${mydir}/../prepare_composer_stuff/prepare_composer_stuff.sh

# Reset the error controls as we are sourcing prepare_composer_stuff.sh which calls set -e which also resets it in this script.
set +e

# Going to install the $gitbranch database
# Create the database
# Based on $dbtype, execute different DB creation commands (mysqli, pgsql)
if [[ "${dbtype}" == "pgsql" || "${dbtype}" == "ou_pgsql" ]]; then
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
rm -f ${gitdir}/config.php

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
%%BEHATURL%%#${behaturl}/${setup_build_start_time}_${unique_job_ident}
%%MOODLEURL%%#${moodleurl}/${setup_build_start_time}_${unique_job_ident}
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
echo "${text}" > "${gitdir}/config.php"

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
    echo Selenium Hub output: ${selenium_hub_output}
    echo Selenium Node output: ${selenium_node_output}
    echo -e "\n\n---------------------------------------------------------------\n\n"
    date
    echo -e "\n\n---------------------------------------------------------------\n\n"
    echo "Launching Selenium (Hub and Node as needed) and sleeping for 2 seconds to allow time for launch"
    /opt/selenium/selenium_hub.sh > "${selenium_hub_output}" 2>&1 &
    /opt/selenium/selenium_node.sh > "${selenium_node_output}" 2>&1 &

    #- Tweak behat YML file to specific selenium WD port
    # Replace the port number for selenium from 4444 to the current node
    echo "Setting selenium node port to 55${display_number}"
    sed -i -e "s/4444/55${display_number}/g" "${datadirbehat}/behat/behat.yml"
    sleep 2
    # Remove the proxy for behat - for some reason it's hit and miss as to when it works
    unset http_proxy
    unset https_proxy
    unset no_proxy
    echo "Unsetting proxy as it's not needed when running behat tests locally. Moodle config has proxy settings in for anything Moodle does."
    echo -e "\n\n---------------------------------------------------------------\n\n"

    if [ -z $behat_by_files_run ]; then
        if [ "${behat_do_full_run}" = "yes" ]; then
            tags=${behat_tags_full_run}
        else
            tags=${behat_tags_partial_run}
        fi

        # Check to see if we have an override
        if [ ! -z "${behat_tags_override}" -a "${behat_tags_override}" != " " ]; then
            tags=${behat_tags_override}
        fi

        echo -e "====== Executing tags ${tags} ======\n\n"

        run_files_or_tags="--tags=${tags}"
    else
        #- Remove all whitespace and replace with " ${gitdir}"

        behat_by_files_run=${behat_by_files_run// / $gitdir\/}
        run_files_or_tags="${gitdir}/$behat_by_files_run"
    fi

    # This will output the moodle_progress format to the console and write moodle_progress, behat pretty and junit formats to files
    pushd ${gitdir}
    echo "vendor/bin/behat -v --config \"${datadirbehat}/behat/behat.yml\" --format moodle_progress,moodle_progress,pretty,junit --out=,\"${behat_pretty_moodle_output}\",\"${behat_pretty_full_output}\",\"${junit_output_folder}\" --profile=chrome ${run_files_or_tags}"
    vendor/bin/behat -v --config "${datadirbehat}/behat/behat.yml" --format moodle_progress,moodle_progress,pretty,junit --out=,"${behat_pretty_moodle_output}","${behat_pretty_full_output}","${junit_output_folder}" --profile=chrome ${run_files_or_tags}
    exitstatus=${PIPESTATUS[0]}
    echo -e "\nBehat finished. Exit status ${exitstatus}"
    popd
fi

# Look for any stack sent to output if behat returned success as it should lead to failed execution
if [ $exitstatus -eq 0 ]; then
    # Check to see if we had no steps.. if so fail!
    no_steps=$(grep 'No steps' "${behat_pretty_moodle_output}" | wc -l)
    if [[ ${stacks} -gt 0 ]]; then
        echo -e "\n\nERROR: no steps run. Marking build as failed."
        exitstatus=1
    else
        # notices/warnings/errors under simpletest (behat captures them)
        stacks=$(grep -r 'Call Stack:' "${junit_output_folder}" | wc -l)
        if [[ ${stacks} -gt 0 ]]; then
            echo -e "\n\nERROR: uncontrolled notice/warning/error output on execution."
            exitstatus=1
        fi
        # debugging messages
        debugging=$(grep -r 'Debugging:' "${junit_output_folder}" | wc -l)
        if [[ ${debugging} -gt 0 ]]; then
            echo -e "\n\nERROR: uncontrolled debugging output on execution."
            exitstatus=1
        fi
        # general backtrace information
        backtrace=$(grep -r 'line [0-9]* of .*: call to' "${junit_output_folder}" | wc -l)
        if [[ ${backtrace} -gt 0 ]]; then
            echo -e "\n\nERROR: uncontrolled backtrace output on execution."
            exitstatus=1
        fi
        # anything exceptional (not dots and numbers) in the execution lines.
        exceptional=$(grep -rP '^\.|%\)$' "${junit_output_folder}" | grep -vP '^[\.SIEF]*[ \d/\(\)%]*$' | wc -l)
        if [[ ${exceptional} -gt 0 ]]; then
            echo -e "\n\nERROR: uncontrolled exceptional output on execution."
            exitstatus=1
        fi
        # Exceptions.
        exception=$(grep -r 'Exception' "${junit_output_folder}" | wc -l)
        if [[ ${exception} -gt 0 ]]; then
            echo -e "\n\nERROR: exception during behat run."
            exitstatus=1
        fi
    fi
fi

# Drop the databases and delete files
# Based on $dbtype, execute different DB deletion commands (pgsql, mysqli)
if [[ "${dbtype}" == "pgsql" || "${dbtype}" == "ou_pgsql" ]]; then
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

echo "Moving screenshots / html"
mkdir "${WORKSPACE}/${BUILD_NUMBER}/screenshots"
find "${behatfaildump}" -exec cp -p {} "${WORKSPACE}/${BUILD_NUMBER}/screenshots" \;
cp ${config_file_path} "${WORKSPACE}/${BUILD_NUMBER}/config"
cp ${gitdir}/config.php "${WORKSPACE}/${BUILD_NUMBER}"
cp "${datadirbehat}/behat/behat.yml" "${WORKSPACE}/${BUILD_NUMBER}"
chmod -R 775 "${WORKSPACE}/${BUILD_NUMBER}/screenshots"

if [ ${exitstatus} -ne 0 ]; then
    # There has been some errors. Process them
    echo "Errors found during Behat run. Processing and emailing."
    php /var/lib/jenkins/git_repositories/ci_scripts/run_behat/process_results.php -w="${WORKSPACE}/${BUILD_NUMBER}"
fi

echo "Cleanup"
#rm -Rf ${gitdir}
#rm -f ${config_file_path}
#rm -fr ${datadir}
#rm -fr ${datadirbehat}

# If arrived here, return the exitstatus of the php execution
exit $exitstatus