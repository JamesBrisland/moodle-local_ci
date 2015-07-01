#!/bin/bash
# $gitcmd: Path to the git CLI executable
# $gitdir: Directory containing git repo
# $phpcmd: Path to php CLI exectuable
#
# Based on GIT_PREVIOUS_COMMIT and GIT_COMMIT will list all changed php
# files and run lint on them.
#
set -e

latest_stable="${WORKSPACE}/latest_stable.txt"

# Verify everything is set
required="gitcmd gitdir phpcmd"
for var in $required; do
    if [ -z "${!var}" ]; then
        echo "Error: ${var} environment variable is not defined. See the script comments."
        exit 1
    fi
done

# calculate some variables
mydir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

fulllint=0

# Grab the last stable
if [ -e "$latest_stable" ]; then
    GIT_STABLE_COMMIT=`cat "$latest_stable"`
fi

if [[ -z "${GIT_STABLE_COMMIT}" ]] || [[ -z "${GIT_COMMIT}" ]] ; then
    # No git diff information. Lint all php files.
    fulllint=1
fi

if [[ ${fulllint} -ne 1 ]]; then
    # We don't need to do a full lint create the variables required by
    # list_changed_files.sh and invoke it
    export initialcommit=${GIT_STABLE_COMMIT}
    export finalcommit=${GIT_COMMIT}
    if mfiles=$(${mydir}/../list_changed_files/list_changed_files.sh)
    then
        echo "Running php syntax check from $initialcommit to $finalcommit:"
    else
        echo "Problems getting the list of changed files. Defaulting to full lint"
        fulllint=1
    fi
fi

if [[ ${fulllint} -eq 1 ]]; then
    mfiles=$(find $gitdir/ -name \*.php ! -path \*/vendor/\* | sed "s|$gitdir/||")
    echo "Running php syntax check on all files:"
fi

# Verify all the changed files.
errorfound=0
for mfile in ${mfiles} ; do
    # Only run on php files.
    if [[ "${mfile}" =~ ".php" ]] ; then
        fullpath=$gitdir/$mfile

        # Ignore some of the fixture files in moodle as they have errors
        # Ignore a couple of help files with have the BOM in them
        dir=`dirname "$mfile"`
        if [ "local/codechecker/moodle/tests/fixtures" = "$dir" ]; then
            echo "$fullpath - SKIPPED moodle test fixture files"
        else
            if [ -e $fullpath ] ; then
                if LINTERRORS=$(($phpcmd -l $fullpath >/dev/null) 2>&1)
                then
                    # We don't care if the file is good... only echo errors.
                    #echo "$fullpath - OK"
                    echo -n
                else
                    errorfound=1
                    # Filter out the paths from errors:
                    ERRORS=$(echo $LINTERRORS | sed "s#$gitdir##")x
                    echo "$fullpath - ERROR: $ERRORS"
                fi
                if grep -q $'\xEF\xBB\xBF' $fullpath
                then
                    echo "$fullpath - ERROR: BOM character found"
                    errorfound=1
                fi
            else
                # This is a bit of a hack, we should really be using git to
                # get actual file contents from the latest commit to avoid
                # this situation. But in the end we are checking against the
                # current state of the codebase, so its no bad thing..
                echo -e "\n$fullpath - SKIPPED (file no longer exists)"
            fi
        fi
    fi
done

if [[ ${errorfound} -eq 0 ]]; then
    # No syntax errors found, all good.
    echo "No PHP syntax errors found"
    # Log this git commit as latest stable
    echo $GIT_COMMIT > "$latest_stable"
    exit 0
fi

echo "PHP syntax errors found."
exit 1
