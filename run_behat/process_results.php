<?php

abstract class Test
{
    /** @var string The Filename for the Test, for Behat it will be the .feature file, for PHPUnit it will be the php
     * file */
    public $FileName;
    /** @var string The path releative to the top level Moodle directory, including the filename */
    public $Path;
    /** @var string The name of the XML file on the vle-auto-test server, used for generating the links. i.e.
     * TEST-var-www-html-20150716_210000_283_ouvle_overnight_full-admin-tool-acctcheck-tests-behat-run_check.xml */
    public $XMLFileName;
    /** @var array A list of email addresses that the error report will go to. This will be fetched from git and will
     * contain all the people who edited the file since the last successful run, or over the last three days if no
     * successful run, or failing that the last person to edit the file */
    public $Emails = [];
    /** @var array A list of screenshots that we think match this failure */
    public $Screenshots = [];
}

class BehatTest extends Test
{
    /** @var string The name of the feature as defined in the behat file */
    public $Feature;
    /** @var BehatStep[] */
    public $FailedSteps = [];

    public function should_send_email(BehatTest $test)
    {
        //- Check to see if we should send the email out to the file editing user. It will send to the user if:
        //-   1 - We find a step in the behat_moodle_pretty output that isn't a known failure for this test
        //-   2 - We don't find any steps in the behat_moodle_pretty output (most likley because the step was undefined)
        $total_steps = 0;
        $known_failures = 0;
        if (!empty($test->FailedSteps)) {
            foreach ($test->FailedSteps as $scenario_name => $steps) {
                $total_steps += count($steps);
                foreach ($steps as $step_name => $step) {
                    if ($step->KnownFailure) {
                        $known_failures++;
                    }
                }
            }
        }
        return ($total_steps == 0) || $total_steps > $known_failures;
    }
}

class BehatStep
{
    /** @var string The name of the scenario as defined in the behat file */
    public $Scenario;
    /** @var string The name of the step as defined in the behat file */
    public $Step;
    /** @var string The error details as displayed on the console */
    public $ErrorDetails;
    /** @var bool If this step is known to fail and we don't want to send out an email */
    public $KnownFailure = false;
}

class ProcessResults
{
    private $workspace_path;
    /** @var  BehatTest[] */
    private $failed_tests;
    private $config_data;
    private $job_name;
    private $job_name_path;
    private $gitdir;
    private $screenshots_at_time;
    const EMAIL_TEMPLATE_TEXT = <<<TXT
[JOB_NAME] - Failed Test - [TEST_PATH].<br/>
<br/>
Test run on branch [BRANCH].<br/>
<br/>
Output folder <a href="\\\\vle-auto-test\\behat\\[JOB_PATH]\\[BUILD_NUMBER]">[JOB_PATH]\\[BUILD_NUMBER]</a><br/>
Screenshot/HTML folder: <a href="\\\\vle-auto-test\\behat\\{JOB_PATH}\\[BUILD_NUMBER]\\screenshots">[JOB_PATH]\\[BUILD_NUMBER]\\screenshots</a><br/>
<br/>
For more details please have a look at the output XML file for this test <a href="\\\\vle-auto-test\\behat\\[JOB_PATH]\\[BUILD_NUMBER]\\behat_junit_xml\\[TEST_XML]">[TEST_PATH]</a><br/>
<br/>
[FEATURE_SCREENSHOTS]
<br/>
[STEP_INFO]
TXT;

    public function __construct()
    {
        date_default_timezone_set('UTC');
        $this->process_command_line_options();
        $this->parse_config_data();
        $this->setup_screenshots();
        $this->parse_test_data();
        $this->parse_output_find_tests();
        $this->parse_expected_fails();
    }

    public function run()
    {
        $this->process_and_email_failed_tests();
    }

    private function parse_expected_fails()
    {
        //- Load up the expected fails file
        if (($handle = fopen(dirname(__FILE__) . "/beaht_expected_fails.csv", "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                //- Because there is potential the path separators might be incorrect as the data is put into this file
                //- by hand so we are going to make sure they match!
                $moodle_rel_feature_file_path = str_replace(array('\\', '/'), DIRECTORY_SEPARATOR, $data[0]);
                $scenario = $data[1];
                $step = $data[2];

                //- Check to see if we have a fail within this feature file
                if (!empty($this->failed_tests[$moodle_rel_feature_file_path])) {
                    //- Check for the Scenario
                    if (!empty($this->failed_tests[$moodle_rel_feature_file_path]->FailedSteps[$scenario])) {
                        //- Check to find a matching step
                        if (!empty($this->failed_tests[$moodle_rel_feature_file_path]->FailedSteps[$scenario][$step])) {
                            $this->failed_tests[$moodle_rel_feature_file_path]->FailedSteps[$scenario][$step]->KnownFailure = true;
                        }
                    }
                }
            }
            fclose($handle);
        }
    }

    private function parse_test_data()
    {
        //- Loop through all the XML files and find ones with failures
        $this->failed_tests = [];

        foreach (new DirectoryIterator($this->workspace_path . DIRECTORY_SEPARATOR . 'behat_junit_xml') as $file_info) {
            if ($file_info->isDot()) continue;

            $failures_attribute = $this->get_failures($file_info);

            //- We have found more than one testsuite in the file.. this shouldn't happen! Skip.
            if ($failures_attribute === -1) {
                continue;
            } else if ($failures_attribute == 0) {
                $this->write_success_file($file_info);
            } else {
                //- Work out the path to the feature
                $rel_feature_location = $this->get_relative_moodle_feature_location($file_info);

                //- Using git work out who we need to email
                $emails = $this->get_emails_for_failed_file($file_info);

                $test = new BehatTest();
                $test->FileName = basename($rel_feature_location);
                $test->Path = $rel_feature_location;
                $test->XMLFileName = $file_info->getFilename();
                $test->Emails = array_unique($emails);

                //- Now we almost have all the info we need we want to check to see if we can match up any screenshots
                //- Get the timestamp of the failed test file
                $file_modified_time = $file_info->getMTime();

                //- Check to see if we have any screenshots that were done within 40 seconds of the test file
                foreach ($this->screenshots_at_time as $time => $files) {
                    if ($time >= $file_modified_time && $time <= ($file_modified_time + 40)) {
                        //- Potential screenshots
                        foreach ($files as $file) {
                            $test->Screenshots[] = $file;
                        }
                    }
                }

                $this->failed_tests[$test->Path] = $test;
            }
        }
    }

    private function get_failures(DirectoryIterator $file_info)
    {
        $file_path = $file_info->getPath() . DIRECTORY_SEPARATOR . $file_info->getFilename();

        if (substr_count(file_get_contents($file_path), '</testsuite>') > 1) {
            echo "Error: File {$file_info->getFilename()} has more than one testsuite. This should not happen! Something strange has gone wrong. Skipping test.\n";
            return -1;
        }

        //- Check to see if this file has a failure in
        $xml = simplexml_load_file($file_path);
        return !empty($xml->attributes()->failures) ? (int)$xml->attributes()->failures : 0;
    }

    private function write_success_file(DirectoryIterator $file_info)
    {
        //- Write out a success file that marks this git commit as a success
        file_put_contents($this->get_success_file_path($file_info), $this->config_data['GIT_COMMIT']);
    }

    private function get_success_file_path(DirectoryIterator $file_info)
    {
        static $file_paths = [];

        if (!isset($file_paths[$file_info->getFilename()])) {
            //- Workspace = /var/lib/jenkins/workspace/[JOB_NAME/[BUILD_NUMBER]
            //- I want to put the success XML files in
            //-  /var/lib/jenkins/workspace/behat_success_xml_[job_ident]/[moodle_path_with_forwardslashes_replaced_by_undersocrese_to_behat.feature]
            $file_paths[$file_info->getFilename()] = $this->workspace_path . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR .
                    'behat_success_xml_' . $this->config_data['unique_job_ident'] . DIRECTORY_SEPARATOR .
                    $this->clean_xml_filename_to_return_moodle_relative_path($file_info);
        }

        return $file_paths[$file_info->getFilename()];
    }

    private function clean_xml_filename_to_return_moodle_relative_path(DirectoryIterator $file_info)
    {
        //- gitdir = /var/www/html/[datetime]_[ident]
        //- XML filename = TEST-var-www-html-[datetime]_[ident]-mod-quiz-tests-behat-settings_form_fields_disableif.xml
        //- We want to change it to
        //- mod-quiz-tests-behat-settings_form_fields_disableif.xml
        $gitdir_with_slashes_replaced = str_replace(DIRECTORY_SEPARATOR, '-', ($this->config_data['gitdir'] . DIRECTORY_SEPARATOR));
        return str_replace('TEST' . $gitdir_with_slashes_replaced, '', $file_info->getFilename());
    }

    private function get_relative_moodle_feature_location(DirectoryIterator $file_info)
    {
        //- Work out the path to the feature
        $feature_location = $this->clean_xml_filename_to_return_moodle_relative_path($file_info);

        //- Add back in the /'s
        $feature_location = str_replace('-', DIRECTORY_SEPARATOR, $feature_location);
        $feature_location = str_replace('.xml', '.feature', $feature_location);

        return str_replace('.xml', '.feature', $feature_location);
    }

    private function get_emails_for_failed_file(DirectoryIterator $file_info)
    {
        $rel_feature_location = $this->get_relative_moodle_feature_location($file_info);

        //- See if we have have a successful behat pass at some point in time
        $last_successful_run_commit_id = $this->get_last_successful_git_commit_id($file_info);

        //- At this point we know this feature has failed, we want to collect some info, such as which files have changed
        //- between the last time the commit was successful and now and the people who have editted this file.
        $output = null;
        exec("cd $this->gitdir && git log HEAD...$last_successful_run_commit_id $rel_feature_location | grep Author |         sort | uniq | sed -r 's/Author: //'", $output);

        //- If the output is empty then that file hasn't been edited between the last commit and now. We want to get the last author of the file
        $emails = [];
        if (empty($output)) {
            $output = null;
            exec("cd $this->gitdir && git log -n 1 HEAD $rel_feature_location | grep Author | sed -r 's/Author: //'", $output);

            if (empty($output)) {
                if (!empty($this->config_data['fail_email'])) {
                    $emails = [$this->config_data['fail_email']];
                } else {
                    echo "\n\n========\nFound no email address for {$file_info->getFilename()} and no fallback email set. OH NO EMAIL NOT SENT!!!!\n========\n\n";
                }
            } else {
                $emails = $output;
            }
        } else {
            $emails = $output;
        }

        //- Loop through all the emails and check they are OU addresses
        foreach ($emails as $index => $email) {
            if (strpos($email, '@open.ac.uk') === false) {
                //- This email isn't valid! Replace with the fail email set in config
                if (!empty($this->config_data['fail_email'])) {
                    $emails[$index] = $this->config_data['fail_email'];
                } else {
                    echo "\n\n========\nFound Moodle email address ($email) for {$file_info->getFilename()} and no fallback email set. OH NO EMAIL NOT SENT!!!!\n========\n\n";
                    unset($emails[$index]);
                }
            }
        }
        return $emails;
    }

    private function get_last_successful_git_commit_id(DirectoryIterator $file_info)
    {
        $last_successful_run_commit_id = null;
        if (file_exists($this->get_success_file_path($file_info))) {
            //- Grab the commit number
            $last_successful_run_commit_id = file_get_contents($this->get_success_file_path($file_info));
        } else {
            $last_successful_run_commit_id = $this->get_git_commit_from_three_days_ago();
        }

        return $last_successful_run_commit_id;
    }

    private function get_git_commit_from_three_days_ago()
    {
        //- Get the last commit id from three days ago - we use this if any of the tests haven't had a success
        $output = null;
        $date = new DateTime();
        $di = new DateInterval('P3D');

        //- Get the date from 3 days ago.
        $date->sub($di);

        //- Run the git command to get the last commit id from (now - three days). We use this if any of the tests have never
        //- passed and are currently failing
        exec('cd ' . $this->gitdir . ' && git rev-list -1 --before="' . $date->format("M j Y") . '" ' . $this->config_data['gitbranch'], $output);
        return $output[0];
    }

    private function process_command_line_options()
    {
        $shortops = "w::";
        $longops = array("workspace::");
        $options = getopt($shortops, $longops);

        if (empty($options['w']) && empty($options['workspace'])) {
            throw new Exception('No workspace path passed to script');
        } else {
            $this->workspace_path = !empty($options['workspace']) ? $options['workspace'] : $options['w'];
        }
    }

    private function parse_config_data()
    {
        if (empty($this->workspace_path)) {
            throw new Exception('No workspace path set');
        }
        //- Grab the vars from the config file - we are hackily suppressing errors as I know there will be some in the file
        //- as it's not actually an ini file, but it's close enough for us to extract the data we need
        $this->config_data = @parse_ini_file($this->workspace_path . DIRECTORY_SEPARATOR . 'config');

        if (empty($this->config_data)) {
            throw new Exception('No config data set');
        }

        $this->job_name = $this->config_data['behat_job_name'];
        $this->job_name_path = basename($this->config_data['behat_workspace']);
        $this->gitdir = str_replace('/', DIRECTORY_SEPARATOR, $this->config_data['gitdir']);
    }

    private function setup_screenshots()
    {
        //- Setup the info for all the screenshots
        //- Iterate over files in the screenshot directory
        $this->screenshots_at_time = [];
        foreach (new DirectoryIterator($this->workspace_path . DIRECTORY_SEPARATOR . 'screenshots') as $file_info) {
            if ($file_info->isDot()) continue;
            $this->screenshots_at_time[$file_info->getMTime()][] = $file_info->getBasename();
        }
    }

    private function get_email_text(Test $test)
    {
        static $text;
        if (!isset($text)) {
            $text = ProcessResults::EMAIL_TEMPLATE_TEXT;
            $text = str_replace('[JOB_NAME]', $this->job_name, $text);
            $text = str_replace('[JOB_PATH]', $this->job_name_path, $text);
            $text = str_replace('[BRANCH]', $this->config_data['gitbranch'], $text);
        }
        $email_text = str_replace('[TEST_PATH]', $test->Path, $text);
        $email_text = str_replace('[TEST_XML]', $test->XMLFileName, $email_text);
        return str_replace('[BUILD_NUMBER]', $this->config_data['behat_build_number'], $email_text);
    }

    private function get_email_subject(Test $test)
    {
        static $subject;
        if (!isset($subject)) {
            $subject = "{$this->job_name} - Failed Test - [TEST_PATH]";
        }
        return str_replace('[TEST_PATH]', $test->Path, $subject);
    }

    private function process_screenshot_for_email($email_text, $test)
    {
        if (!empty($test->Screenshots)) {
            $screenshots = "Potential Matched Screenshot/HTML files for this test:<br/>\n";
            foreach ($test->Screenshots as $screenshot) {
                $screenshots .= '<a href="\\\\vle-auto-test\\behat\\' . $this->job_name_path . '\\' . $this->config_data['behat_build_number'] . '\\screenshots\\' . $screenshot . '">' . $screenshot . "</a><br/><br/>\n\n";
            }
            $email_text = str_replace('[FEATURE_SCREENSHOTS]', $screenshots, $email_text);
        } else {
            $email_text = str_replace('[FEATURE_SCREENSHOTS]', 'We were unable to match up any screenshots/html files to this failed test.', $email_text);
        }
        return $email_text;
    }

    private function process_step_info_for_email($email_text, BehatTest $test, $include_known_failures = false)
    {
        $step_text = '';
        foreach ($test->FailedSteps as $scenario => $steps) {
            foreach ($steps as $step) {
                if ($include_known_failures || !$step->KnownFailure) {
                    $kf = !$step->KnownFailure ? '' : ' Known Failure ';
                    $step_text .=
                            "-------------------------------{$kf}-------------------------------<br>\n<pre>" .
                            htmlspecialchars($step->ErrorDetails) .
                            "</pre><br>\n-------------------------------{$kf}-------------------------------<br>\n<br>\n<br>\n";
                }
            }
        }

        if (!empty($step_text)) {
            $email_text = str_replace('[STEP_INFO]', "<br>\nFailure Information:<br>\n<br>\n{$step_text}", $email_text);
        } else {
            $email_text = str_replace('[STEP_INFO]', "<br>\nNo failure info for this test in the output file. Please check the XML.", $email_text);
        }
        return $email_text;
    }

    private function do_php_mailer($test)
    {
        $email_subject = $this->get_email_subject($test);
        $email_text = $this->get_email_text($test);
        $email_text = $this->process_screenshot_for_email($email_text, $test);
        $email_text_before_step_process = $email_text;
        $email_text = $this->process_step_info_for_email($email_text, $test);

        //- Now email using PHPMailer
        include_once(__DIR__ . '/PHPMailer-5.2.10/PHPMailerAutoload.php');

        $mail = new PHPMailer();
        $mail->isSMTP();
        $mail->isHTML();
        $mail->Host = 'smtpmail.open.ac.uk';

        //- For some reason I cannot use an open.ac.uk email address as the server tries to validate the address
        $mail->From = 'no-reply@no-reply.com';
        $mail->FromName = 'VLE Jenkins';
        $mail->addReplyTo('no-reply@no-reply.com', 'VLE Jenkins');

        if (!empty($this->config_data['email_override'])) {
            list($name, $email) = explode('<', str_replace('>', '', $this->config_data['email_override']));
            $mail->addAddress(trim($email), trim($name));
        } else {
            foreach ($test->Emails as $email) {
                list($name, $email) = explode('<', str_replace('>', '', $email));
                $mail->addAddress(trim($email), trim($name));
            }
        }

        $mail->Subject = $email_subject;
        $mail->Body = $email_text;
        $mail->AltBody = "Sorry, no text version available.. here is the HTML version!\n\n" . $email_text;

        if ($test->should_send_email($test) && !$mail->send()) {
            echo "NORMAL EMAIL - Failed to send email\n";
            print_r($mail);
            echo "\n\n";
        }

        //- Now send the mail to the "admin" including any known failures
        if (!empty($this->config_data['fail_email'])) {
            //- Send an email to the "admin"
            $email_text = $this->process_step_info_for_email($email_text_before_step_process, $test, true);
            $mail->Subject = "ADMIN - " . $email_subject;
            $mail->Body = $email_text;
            $mail->clearAllRecipients();
            list($name, $email) = explode('<', str_replace('>', '', $this->config_data['fail_email']));
            $mail->addAddress(trim($email), trim($name));
            if (!$mail->send()) {
                echo "ADMIN EMAIL - Failed to send email\n";
                print_r($mail);
                echo "\n\n";
            }
        }
    }

    private function process_and_email_failed_tests()
    {
        /** @var Test $test */
        foreach ($this->failed_tests as $test) {
            $this->do_php_mailer($test);
        }
    }

    private function parse_output_find_tests()
    {
        if (empty($this->failed_tests)) {
            throw new Exception('Must call parse_test_data() first to setup the failed tests');
        }

        //- Load up the output file
        $file = file_get_contents($this->workspace_path . DIRECTORY_SEPARATOR . 'behat_pretty_moodle.txt');
        $matches = [];

        //- Find all the failures
        preg_match_all('/^\d\d+\.\s+(.*?)\n\n(?=(\d\d+\.)|(\d+ scenario))/sm', $file, $matches);

        //- Loop through the matches and find all the necessary details
        foreach ($matches[1] as $match) {
            $failed_step = new BehatStep();

            //- Now we need to find the feature name - we are using strrpos because the feature file name is always at
            //- the end of the string somewhere
            $feature_pos = mb_strrpos($match, '.feature');
            $pos_from_back = mb_strlen($match) - $feature_pos;

            $path = $this->config_data['gitdir'] . DIRECTORY_SEPARATOR;
            $len_path = mb_strlen($path);
            $start_feature_path = mb_strrpos($match, $path, -$pos_from_back);

            $feature_path = substr($match, $start_feature_path + $len_path, ($feature_pos - $start_feature_path) + 8);
            $feature_path = str_replace('\\', DIRECTORY_SEPARATOR, $feature_path);
            $feature_path = str_replace('/', DIRECTORY_SEPARATOR, $feature_path);

            //- Now we need to find the feature name
            $feature_name_matches = null;
            preg_match("/Of feature [`'\"]?(.*?)[`'\"]?\.\s*#.*$/m", $match, $feature_name_matches);
            if (empty($feature_name_matches[1])) {
                //- No match... something strange has happened and we have no feature.
                //- Just continue as normal, we just can't figure out if this feature is
                //- a known failure, and that's fine!
                continue;
            }
            $feature_name = $feature_name_matches[1];

            $feature_scenario_matches = null;
            preg_match("/From scenario [`'\"]?(.*?)[`'\"]?\.\s*#.*$/m", $match, $feature_scenario_matches);
            if (empty($feature_scenario_matches[1])) {
                //- No match... something strange has happened and we have no feature.
                //- Just continue as normal, we just can't figure out if this feature is
                //- a known failure, and that's fine!
                continue;
            }
            $failed_step->Scenario = $feature_scenario_matches[1];

            $feature_step_matches = null;
            preg_match("/In step [`'\"]?(.*?)[`'\"]?\.\s*#.*$/m", $match, $feature_step_matches);
            if (empty($feature_step_matches[1])) {
                //- No match... something strange has happened and we have no feature.
                //- Just continue as normal, we just can't figure out if this feature is
                //- a known failure, and that's fine!
                continue;
            }
            $failed_step->Step = $feature_step_matches[1];
            $failed_step->ErrorDetails = $match;

            //- Now check if we have a failed match for this feature... if we don't something strange has happened
            if (empty($this->failed_tests[$feature_path])) {
                echo "----------------------------------------------------------------------------------------\n";
                echo "ERROR: Found a feature in the output that doesn't have a matching XML file. Details:- \n";
                echo $match . "\n";
                echo "----------------------------------------------------------------------------------------\n\n";
                continue;
            }

            //- Check to see if this is a know fail
            $failed_step->KnownFailure = false;

            //- Add this failed step to the feature. Each Scenario might have more than one failed step?! Just in case!
            $this->failed_tests[$feature_path]->FailedSteps[$failed_step->Scenario][$failed_step->Step] = $failed_step;
        }
    }
}

try {
    $process_results = new ProcessResults();
    $process_results->run();
    exit(0);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}