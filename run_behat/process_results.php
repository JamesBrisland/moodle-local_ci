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
$config_data = @parse_ini_file(__DIR__ . '/moodle_ci_data/config');
print_r( $config_data );

$workspace = $config_data['behat_workspace'];

$matches = [];
preg_match_all( '/^.*# (.*\.feature).*$/m', file_get_contents( __DIR__ . '/moodle_ci_data/behat_pretty_moodle.txt' ), $matches );

$info = !empty( $matches[0] ) ? $matches[0] : [];
$files = array_unique( !empty( $matches[1] ) ? $matches[1] : [] );

//- Setup the info for all the screenshots
//- Iterate over files in the screenshot directory
$screenshots_at_time = [];
foreach (new DirectoryIterator( __DIR__ . '/moodle_ci_data/screenshots') as $fileInfo) {
    if($fileInfo->isDot()) continue;
    $file_modified_time = (int) ($fileInfo->getMTime() / 100); # (remove the seconds)
    $screenshots_at_time[$file_modified_time][] = $fileInfo->getBasename();
}

//- Get all the last successful git commits for all the tests

//- Get the git info for the feature files
$files_info = [];
foreach( $files as $file )
{
    $file_path_temp = str_replace( '/var/lib/jenkins/git_repositories/OUVLE/', '', $file );
    $file_path_temp = 'c:\\workspace\\sites\\ouvle\\' . $file_path_temp;
    $fname = basename( $file );
    $feature_dir = dirname( $file_path_temp );

    $output = null;
    exec( "cd $feature_dir && git log $file_path_temp", $output );

    //- Loop through the output and grab the last author
    $to = '';
    foreach( $output as $line ) {
        if( strpos( $line, 'Author:' ) !== false )
        {
            //- We have an author... see if we have an email
            $to = trim( str_replace( 'Author:', '', $line ) );

            //- Hacky way to test for an email... Usual form = "User Name <email@email.com>", we are just going to check
            //- for an @ and assume if there is one we can use it to email the user... if not we will skip over to the next
            //- author with an email address
            if( strpos( $to, '@' ) !== false ) {
                break; //- We've found the last person who authored that feature file that has an email address... break out
            }
        }
    }

    $files_info[$fname]['author'] = $to;

    //- Now we have the author we want to check if there are any matches to screenshots.

    //- First we need to find the test xml juint file and get the time

    //- Work out the filename of the generated jUnit XML file for this feature
    $junit_file_name = "TEST" . $file;
    $junit_file_name = str_replace( '/', '-', $junit_file_name );
    $junit_file_name = str_replace( '.feature', '.xml', $junit_file_name );

    //- Get the timestamp from that file
    $file_modified_time = filemtime( __DIR__ . '/moodle_ci_data/behat_junit_xml/' . $junit_file_name );
    $file_modified_time = (int) ($file_modified_time / 100); # (remove the seconds)

    //- Check to see if we have any screenshots that were done within the same minute
    if( isset( $screenshots_at_time[$file_modified_time] ) )
    {
        //- Potential screenshots
        $files_info[$fname]['screenshots'] = $screenshots_at_time[$file_modified_time];
    }
}

//- Now we should have a list of all the failed tests, along with an author
print_r( $files_info );