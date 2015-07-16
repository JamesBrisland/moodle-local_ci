<?php

class Test
{
    public $Name;
    public $Path;
    public $XMLFileName;
    public $Emails = [];
    public $Screenshots = [];
}

class ProcessResults
{
    private $workspace_path;
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
[RUN_INFO]
TXT;


    public function __construct()
    {
        date_default_timezone_set('UTC');
        $this->process_command_line_options();
        $this->parse_config_data();
        $this->setup_screenshots();
    }

    public function run()
    {
        $failed_tests = $this->parse_test_data();
        $this->process_and_email_failed_tests($failed_tests);
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

        return ltrim(str_replace($this->gitdir, '', $feature_location), DIRECTORY_SEPARATOR);
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

    private function get_emails_for_failed_file(DirectoryIterator $file_info)
    {
        $rel_feature_location = $this->get_relative_moodle_feature_location($file_info);

        //- See if we have have a successful behat pass at some point in time
        $last_successful_run_commit_id = $this->get_last_successful_git_commit_id($file_info);

        //- At this point we know this feature has failed, we want to collect some info, such as which files have changed
        //- between the last time the commit was successful and now and the people who have editted this file.
        $output = null;
        exec("cd $this->gitdir && git log HEAD...$last_successful_run_commit_id $rel_feature_location | grep Author | sort | uniq | sed -r 's/Author: //'", $output);

        //- If the output is empty then that file hasn't been edited between the last commit and now. We want to get the last author of the file
        if (empty($output)) {
            $output = null;
            exec("cd $this->gitdir && git log -n 1 HEAD $rel_feature_location | grep Author | sed -r 's/Author: //'", $output);

            if (empty($output)) {
                $emails = [$this->config_data['fail_email']];
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
                $emails[$index] = $this->config_data['fail_email'];
            }
        }
        return $emails;
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

    private function parse_test_data()
    {
        //- Loop through all the XML files and find ones with failures
        $failed_tests = [];

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

                //- Always send a copy to the fail_email address
                if( !empty( $this->config_data['fail_email'] ) )
                {
                    $emails[] = $this->config_data['fail_email'];
                }

                $test = new Test();
                $test->Name = basename($rel_feature_location);
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

                $failed_tests[] = $test;
            }
        }

        return $failed_tests;
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

    private function process_run_info_for_email($email_text, $test)
    {
        $email_text = str_replace('[RUN_INFO]', '', $email_text);
        return $email_text;
    }

    private function do_php_mailer($test)
    {
        $email_subject = $this->get_email_subject($test);
        $email_text = $this->get_email_text($test);
        $email_text = $this->process_screenshot_for_email($email_text, $test);
        $email_text = $this->process_run_info_for_email($email_text, $test);

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

        if (!$mail->send()) {
            echo "Failed to send email\n";
            print_r($mail);
            echo "\n\n";
        }
    }

    private function process_and_email_failed_tests($failed_tests)
    {
        /** @var Test $test */
        foreach ($failed_tests as $test) {
            $this->do_php_mailer($test);
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