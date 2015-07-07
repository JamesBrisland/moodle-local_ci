<?php

$shortops = "w::";
$longops = array("workspace::");
$options = getopt($shortops, $longops);

if (empty($options['w']) && empty($options['workspace'])) {
    //- Error, no workspace, cannot parse
    exit(1);
} else {
    $workspace_inc_run_number = !empty($options['workspace']) ? $options['workspace'] : $options['w'];
}

//- Grab the vars from the config file - we are hackily suppressing errors as I know there will be some in the file
//- as it's not actually an ini file, but it's close enough for us to extract the data we need
$config_data = @parse_ini_file($workspace_inc_run_number . DIRECTORY_SEPARATOR . 'config');
print_r($config_data);

$workspace = $config_data['behat_workspace'];
$gitdir = str_replace('/', DIRECTORY_SEPARATOR, $config_data['gitdir']);

//- Setup the info for all the screenshots
//- Iterate over files in the screenshot directory
$screenshots_at_time = [];
foreach (new DirectoryIterator($workspace . DIRECTORY_SEPARATOR . 'screenshots') as $fileInfo) {
    if ($fileInfo->isDot()) continue;
    $screenshots_at_time[$fileInfo->getMTime()][] = $fileInfo->getBasename();
}

//- Get the last commit id from three days ago - we use this if any of the tests haven't had a success
$output = null;
$date = new DateTime();
$di = new DateInterval('P3D');

//- Get the date from 3 days ago.
$date->sub($di);

//- Run the git command to get the last commit id from (now - three days). We use this if any of the tests have never
//- passed and are currently failing
exec('cd ' . $gitdir . ' && git rev-list -1 --before="' . $date->format("M j Y") . '" ' . $config_data['gitbranch'], $output);
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
        $xml_file_name = $fileInfo->getFilename();

        //- Work out the path to the feature
        $feature_location = str_replace('TEST', '', $fileInfo->getFilename());
        $feature_location = str_replace('-', DIRECTORY_SEPARATOR, $feature_location);
        $feature_location = str_replace('.xml', '.feature', $feature_location);

        $abs_feature_location = $feature_location;
        $rel_feature_location = ltrim(str_replace($gitdir, '', $feature_location), DIRECTORY_SEPARATOR);

        #DONOTCOMMIT
        #$abs_feature_location = 'c:' . DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'ouvle' . $rel_feature_location;
        #DONOTCOMMIT

        $feature_name = basename($rel_feature_location);

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
        exec("cd $gitdir && git log HEAD...$last_successful_run_commit_id $rel_feature_location | grep Author | sort | uniq | sed -r 's/Author: //'", $output);

        //- If the output is empty then that file hasn't been edited between the last commit and now. We want to get the last author of the file
        if (empty($output)) {
            $output = null;
            exec("cd $gitdir && git log -n 1 HEAD $rel_feature_location | grep Author | sed -r 's/Author: //'", $output);

            if (empty($output)) {
                $emails = ['Ray.Guo <ray.guo@open.ac.uk>'];
            } else {
                $emails = $output;
            }
        } else {
            $emails = $output;
        }

        //- Loop through all the emails and check they are OU addresses
        foreach ($emails as $index => $email) {
            if (strpos($email, '@open.ac.uk') === false) {
                //- This email isn't valid! Replace with Ray's
                $emails[$index] = 'Ray.Guo <ray.guo@open.ac.uk>';
            }
        }

        $failed_tests[$rel_feature_location]['feature_name'] = $feature_name;
        $failed_tests[$rel_feature_location]['feature_path'] = $rel_feature_location;
        $failed_tests[$rel_feature_location]['feature_xml_file'] = $xml_file_name;
        $failed_tests[$rel_feature_location]['emails'] = array_unique($emails);

        //- Get a list of files that have changed between the last success commit id and the current commit id

        //- Now we almost have all the info we need we want to check to see if we can match up any screenshots
        //- Get the timestamp of the failed test file
        $file_modified_time = filemtime($file_path);

        //- Check to see if we have any screenshots that were done within 20 seconds of the test file
        foreach ($screenshots_at_time as $time => $files) {
            if ($time >= $file_modified_time && $time <= ($file_modified_time + 20)) {
                //- Potential screenshots
                foreach( $files as $file ) {
                    $failed_tests[$rel_feature_location]['screenshots'][] = $file;
                }
            }
        }
    }
}

//- Now we have a list of failed tests along with screenshots
$subject = "Behat failed test [BEHAT_FEATURE_PATH]";
$text = <<<EOT
The Behat test [BEHAT_FEATURE_PATH] has failed.

Please have a look at the output XML file \\\\vle-auto-test\\behat\\[BUILD_NUMBER]\\behat_junit_xml\\[BEHAT_FEATURE_XML]

[FEATURE_SCREENSHOTS]
EOT;

foreach ($failed_tests as $test_file => $info) {
    $email_subject = str_replace('[BEHAT_FEATURE_PATH]', $info['feature_path'], $subject);
    $email_text = str_replace('[BEHAT_FEATURE_PATH]', $info['feature_path'], $text);
    $email_text = str_replace('[BEHAT_FEATURE_XML]', $info['feature_xml_file'], $email_text);
    $email_text = str_replace('[BUILD_NUMBER]', $config_data['behat_build_number'], $email_text);

    if (!empty($info['screenshots'])) {
        $screenshots = "Potential Screenshot/HTML files:\n";
        foreach( $info['screenshots'] as $screenshot ) {
            $screenshots .= '\\\\vle-auto-test\\behat\\' . $config_data['behat_build_number'] . '\\screenshots\\' . $screenshot . "\n";
        }
        $email_text = str_replace('[FEATURE_SCREENSHOTS]', $screenshots, $email_text);
    } else {
        $email_text = str_replace('[FEATURE_SCREENSHOTS]', '', $email_text);
    }

    //- Now email using PHPMailer
    include_once( __DIR__ . '/PHPMailer-5.2.10/PHPMailerAutoload.php' );

    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host = 'smtpmail.open.ac.uk';
    $mail->From = 'from@example.com';
    $mail->FromName = 'Mailer';
    $mail->addAddress('james.brisland@open.ac.uk', 'James Brisland');
    $mail->addReplyTo('info@example.com', 'Information');

    $mail->Subject = $email_subject;
    $mail->Body = $email_text;

    if(!$mail->send()) {
        echo "Failed to send email\n";
        print_r( $mail );
    }
}

//- Finished!
exit(0);