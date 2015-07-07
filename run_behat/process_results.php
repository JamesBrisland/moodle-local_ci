<?php

$shortops = "w::";
$longops = array("workspace::");
$options = getopt($shortops, $longops);

if (empty($options['w']) && empty($options['workspace'])) {
    //- Error, no config file, cannot parse
    exit(1);
} else {
    $config = !empty($options['workspace']) ? $options['workspace'] : $options['w'];
}

//- Grab the vars from the config file - we are hackily suppressing errors as I know there will be some in the file
//- as it's not actually an ini file, but it's close enough for us to extract the data we need
$config_data = @parse_ini_file(__DIR__ . DIRECTORY_SEPARATOR . 'moodle_ci_data/config');
print_r($config_data);

//$workspace = $config_data['behat_workspace'];
$gitdir = str_replace('/', DIRECTORY_SEPARATOR, $config_data['gitdir']);
$workspace = 'c:\\workspace\\james_moodle_ci\\run_behat\\moodle_ci_data';
$local_gitdir = 'c:\\workspace\\sites\\ouvle\\';

//- Setup the info for all the screenshots
//- Iterate over files in the screenshot directory
$screenshots_at_time = [];
foreach (new DirectoryIterator($workspace . DIRECTORY_SEPARATOR . 'screenshots') as $fileInfo) {
    if ($fileInfo->isDot()) continue;
    $file_modified_time = (int)($fileInfo->getMTime() / 100); # (remove the seconds)
    $screenshots_at_time[$file_modified_time][] = $fileInfo->getBasename();
}

//- Get the last commit id from three days ago - we use this if any of the tests haven't had a success
$output = null;
$date = new DateTime();
$di = new DateInterval('P3D');

//- Get the date from 3 days ago.
$date->sub($di);

//- Run the git command to get the last commit id from (now - three days). We use this if any of the tests have never
//- passed and are currently failing
exec('cd ' . $local_gitdir . ' && git rev-list -1 --before="' . $date->format("M j Y") . '" ' . $config_data['gitbranch'], $output);
$commit_id_three_days_ago = $output[0];

//- Loop through all the XML files and find ones with failures
$failed_tests = [];
$success_dir = $workspace . DIRECTORY_SEPARATOR . 'successful';
foreach (new DirectoryIterator($workspace . DIRECTORY_SEPARATOR . 'behat_junit_xml') as $fileInfo) {
    if ($fileInfo->isDot()) continue;

    $file_path = $fileInfo->getPath() . DIRECTORY_SEPARATOR . $fileInfo->getFilename();

    //- Check to see if this file has a failure in
    $xml = simplexml_load_file($file_path);

    if (substr_count(file_get_contents($file_path), '</testsuite>') > 1) {
        echo 'Error: File ' . $fileInfo->getFilename() . ' has more than one testsuite. This should not happen! Something strange has gone wrong. Skipping test.';
        continue;
    }

    $failures_attribute = !empty($xml->attributes()->failures) ? (int)$xml->attributes()->failures : 0;
    $last_successful_run_file = $success_dir . DIRECTORY_SEPARATOR . $fileInfo->getFilename();
    $last_successful_run_commit_id = null;

    if ($failures_attribute == 0) {
        //- Write out a success file that marks this git commit as a success
        file_put_contents($last_successful_run_file, $config_data['GIT_COMMIT']);
    } else {

        //- Work out the path to the feature
        $feature_location = str_replace('TEST', '', $fileInfo->getFilename());
        $feature_location = str_replace('-', DIRECTORY_SEPARATOR, $feature_location);
        $feature_location = str_replace('.xml', '.feature', $feature_location);

        $abs_feature_location = $feature_location;
        $rel_feature_location = ltrim(str_replace($gitdir, '', $feature_location), DIRECTORY_SEPARATOR);

        #DONOTCOMMIT
        $abs_feature_location = 'c:' . DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'ouvle' . $rel_feature_location;
        #DONOTCOMMIT

        $feature_name = basename( $rel_feature_location );

        //- See if we have have a successful behat pass at some point in time
        if (file_exists($last_successful_run_file)) {
            //- Grab the commit number
            $last_successful_run_commit_id = file_get_contents($last_successful_run_file);
        }

        //- Make sure we have a commit id we can use as a comparison
        if (!$last_successful_run_commit_id) {
            $last_successful_run_commit_id = $commit_id_three_days_ago;
        }

        //- At this point we know this feature has failed, we want to collect some info, such as which files have changed
        //- between the last time the commit was successful and now and the people who have editted this file.
        $output = null;
        exec("cd $local_gitdir && git log HEAD...$last_successful_run_commit_id $rel_feature_location | grep Author | sort | uniq | sed -r 's/Author: //'", $output);

        //- If the output is empty then that file hasn't been edited between the last commit and now. We want to get the last author of the file
        if (empty($output)) {
            $output = null;
            exec("cd $local_gitdir && git log -n 1 HEAD $rel_feature_location | grep Author | sed -r 's/Author: //'", $output);

            if( empty( $output ) ) {
                $emails = ['Ray.Guo <ray.guo@open.ac.uk>'];
            } else {
                $emails = $output;
            }
        } else {
            $emails = $output;
        }

        //- Loop through all the emails and check they are OU addresses
        foreach( $emails as $index => $email )
        {
            if( strpos( $email, '@open.ac.uk' ) === false )
            {
                //- This email isn't valid! Replace with Ray's
                $emails[$index] = 'Ray.Guo <ray.guo@open.ac.uk>';
            }
        }

        $failed_tests[$rel_feature_location]['emails'] = array_unique( $emails );

        //- Get a list of files that have changed between the last success commit id and the current commit id

        //- Now we almost have all the info we need we want to check to see if we can match up any screenshots
        //- Get the timestamp of the failed test file
        $file_modified_time = filemtime($file_path);
        $file_modified_time = (int)($file_modified_time / 100); # (remove the seconds)

        //- Check to see if we have any screenshots that were done within the same minute
        if (isset($screenshots_at_time[$file_modified_time])) {
            //- Potential screenshots
            $failed_tests[$rel_feature_location]['screenshots'] = $screenshots_at_time[$file_modified_time];
        }
    }
}

print_r( $failed_tests );
exit();

$matches = [];
preg_match_all('/^.*# (.*\.feature).*$/m', file_get_contents($workspace . DIRECTORY_SEPARATOR . 'behat_pretty_moodle.txt'), $matches);

$info = !empty($matches[0]) ? $matches[0] : [];
$files = array_unique(!empty($matches[1]) ? $matches[1] : []);

//- Get all the last successful git commits for all the tests

//- Get the git info for the feature files
$files_info = [];
foreach ($files as $file) {
    $file_path_temp = str_replace('/var/lib/jenkins/git_repositories/OUVLE/', '', $file);
    $file_path_temp = 'c:\\workspace\\sites\\ouvle\\' . $file_path_temp;
    $fname = basename($file);
    $feature_dir = dirname($file_path_temp);

    $output = null;
    exec("cd $feature_dir && git log $file_path_temp", $output);

    //- Loop through the output and grab the last author
    $to = '';
    foreach ($output as $line) {
        if (strpos($line, 'Author:') !== false) {
            //- We have an author... see if we have an email
            $to = trim(str_replace('Author:', '', $line));

            //- Hacky way to test for an email... Usual form = "User Name <email@email.com>", we are just going to check
            //- for an @ and assume if there is one we can use it to email the user... if not we will skip over to the next
            //- author with an email address
            if (strpos($to, '@') !== false) {
                break; //- We've found the last person who authored that feature file that has an email address... break out
            }
        }
    }

    $files_info[$fname]['author'] = $to;

    //- Now we have the author we want to check if there are any matches to screenshots.

    //- First we need to find the test xml juint file and get the time

    //- Work out the filename of the generated jUnit XML file for this feature
    $junit_file_name = "TEST" . $file;
    $junit_file_name = str_replace('/', '-', $junit_file_name);
    $junit_file_name = str_replace('.feature', '.xml', $junit_file_name);

    //- Get the timestamp from that file
    $file_modified_time = filemtime($workspace . DIRECTORY_SEPARATOR . 'behat_junit_xml' . DIRECTORY_SEPARATOR . $junit_file_name);
    $file_modified_time = (int)($file_modified_time / 100); # (remove the seconds)

    //- Check to see if we have any screenshots that were done within the same minute
    if (isset($screenshots_at_time[$file_modified_time])) {
        //- Potential screenshots
        $files_info[$fname]['screenshots'] = $screenshots_at_time[$file_modified_time];
    }
}

//- Now we should have a list of all the failed tests, along with an author
print_r($files_info);