#!/usr/bin/php
<?php

date_default_timezone_set("Europe/Minsk");

$Ci_dir = '/opt/ci-tool/';
require_once($Ci_dir . 'config.php');

require_once($_CONFIG['ci_dir'] . 'lib.php');
require_once($_CONFIG['ci_dir'] . 'tpl.php');
require_once($_CONFIG['ci_dir'] . 'xml.php');
require_once($_CONFIG['ci_dir'] . 'List_projects.php');
require_once($_CONFIG['ci_dir'] . 'CiDateTime.php');

$utility_name = 'ci';
$this_server = array();



/**
 * Formated help output
 * @param $cmd - full command (text string)
 * @param $description - help description about command
 * @param array $sub_commands - list of sub commands
 */
function print_help_commands($cmd, $description, $sub_commands = array())
{
    echo $description . "\n";
    echo "Usage: ci " . $cmd . ' ' . ($sub_commands ? '[command]' : '') . "\n";
    if (!$sub_commands)
        return;

    foreach ($sub_commands as $command => $description)
    {
        echo "\t" . $command . " - " . $description . "\n";
    }
}

function error_exception($exception)
{
    echo 'Error: ' . $exception->getMessage() . "\n";
    exit;
}

/**
 * function returned count of free build slots,
 * if server allocated all build slots - return negative counter
 * @param List_projects $projects
 * @return mixed
 * @throws Exception
 */
function get_free_build_slots(List_projects $projects)
{
    global $this_server;

    $list_sessions = $projects->get_all_sessions(array('created',
                                                       'running_checkout',
                                                       'running_build',
                                                       'running_test'));

    // count sessions in running mode
    $build_processes = count($list_sessions);

    $free_build_slots = $this_server['max_build_slots'] - $build_processes;
    return $free_build_slots;
}

/**
 * Function view on all servers and find last commit hash
 * @return array - commit hash and last session url
 */
function find_previous_session_info($target, $repo_name)
{
    global $_CONFIG;

    // found last commit on all servers
    $last_date = new CiDateTime();
    $last_date->setDate(1984, 1, 1);
    $last_commit_info = array();
    foreach ($_CONFIG['ci_servers'] as $ci_server)
    {
        $rc = ci_run_cmd($ci_server, 'cd ' . $target->get_dir() . ';ci get get_last_commit ' . $repo_name);
        if ($rc['rc'])
            continue;

        $commit_info = explode(';', $rc['log']);
        $date = new CiDateTime();
        $date->from_string($commit_info[0]);

        if ($date > $last_date)
        {
            $last_date = $date;
            $last_commit_info = $commit_info;
        }
    }

    $prev_commit = '';
    $prev_session_url = '';
    if (isset($last_commit_info[1]))
    {
        $prev_commit = $last_commit_info[1];
        $prev_session_url = $last_commit_info[2];
        msg_log(LOG_NOTICE, 'found previous commit hash: ' . $prev_commit);
    }
    else
        msg_log(LOG_WARNING, 'not found previous commit hash, report was created without git-log');

    return array('commit' => $prev_commit, 'url' => $prev_session_url, 'date' => $last_date);
}


function main()
{
    global $argv, $_CONFIG, $this_server;
    $rc = 0;

    set_exception_handler('error_exception');

    $this_server = get_current_ci_server();
    if (!$this_server)
        throw new Exception("Can't detect current server");

    // create list all projects
    $projects = new List_projects($_CONFIG['project_dir']);

    // Check operation object
    $obj_type = 'project_list';

    if (file_exists('.project_desc'))
        $obj_type = 'project';

    if (file_exists('.target_desc'))
        $obj_type = 'target';

    if (file_exists('.session_desc'))
        $obj_type = 'session';

    // Parse path and detect $project_name and $target_name and $session_name
    $target_name = '';
    $project_name = '';
    $session_name = '';
    $dirs = explode('/', getcwd());
    $dirs = array_reverse($dirs);
    switch ($obj_type)
    {
        case 'project_list':
            break;

        case 'project':
            // Find project name
            $project_name = $dirs[0];
            break;

        case 'target':
            // Find target name and project name
            $target_name = $dirs[0];
            $project_name = $dirs[1];
            break;

        case 'session':
            // Find session name target name and project name
            $session_name = $dirs[0];
            $target_name = $dirs[1];
            $project_name = $dirs[2];
            break;
    }

    // get project object
    if ($project_name)
    {
        $project = $projects->find_project($project_name);
        if (!$project)
        {
            msg_log(LOG_ERR, 'project not found');
            return 1;
        }
    }

    // get target object
    if ($target_name)
    {
        $target = $project->find_target($target_name);
        if (!$target)
        {
            msg_log(LOG_ERR, 'target not found');
            return 1;
        }
    }

    // get session object
    if ($session_name)
    {
        $session = $target->find_session($session_name);
        if (!$session)
        {
            msg_log(LOG_ERR, 'session not found');
            return 1;
        }
    }

    // Detect print help mode
    $print_help = false;
    foreach ($argv as $arg)
        if ($arg == '--help' || $arg == '-h')
            $print_help = true;

    // analysis operation
    $op = isset($argv[1]) ? $argv[1] : NULL;
    switch ($op)
    {
        case 'get':
            {
                $param = isset($argv[2]) ? $argv[2] : NULL;
                switch ($param)
                {
                    case 'get_last_commit':
                        $repo = trim(isset($argv[3]) ? $argv[3] : NULL);
                        if (!$repo)
                        {
                            msg_log(LOG_ERR, '"repo name" 1 argument is empty');
                            $print_help = true;
                        }

                        if ($print_help)
                        {
                            print_help_commands('get get_last_commit [repo]', 'get last session and return time and commit hash');
                            return 0;
                        }

                        if ($obj_type != 'target')
                        {
                            msg_log(LOG_ERR, 'This operation permited only from target dir');
                            return 1;
                        }

                        $list_sessions = $target->get_list_sessions();
                        if (!$list_sessions)
                        {
                            echo 'sessions not found';
                            return 1;
                        }

                        $last_session = array();
                        $last_date = new CiDateTime();
                        $last_date->setDate(1984, 1, 1);
                        foreach ($list_sessions as $session)
                        {
                            if ($session->get_repo_name() != $repo)
                                continue;

                            $state = $session->get_state();

                            if ($state != 'finished_build' &&
                                $state != 'finished_test')
                                continue;

                            $created_date = $session->get_date();
                            if ($created_date > $last_date)
                            {
                                $last_session = $session;
                                $last_date = $created_date;
                            }
                        }

                        echo $last_date->to_string() . ';' . $last_session->get_commit() . ';' . $last_session->get_url() . "\n";
                        return 0;

                    case 'free_build_slots':
                        if ($print_help)
                        {
                            print_help_commands('get free_build_slots', 'get count of free build clots');
                            return 0;
                        }

                        $list_pending_sessions = $projects->get_all_sessions(array('pending'));
                        $free_build_slots = get_free_build_slots($projects);
                        $free_build_slots -= count($list_pending_sessions);
                        echo $free_build_slots . "\n";
                        return 0;

                    case 'sessions':
                    {
                        $command = trim(isset($argv[3]) ? $argv[3] : NULL);
                        $repo = trim(isset($argv[4]) ? $argv[4] : NULL);
                        $branch = trim(isset($argv[5]) ? $argv[5] : NULL);

                        switch ($command)
                        {
                            case 'all':
                                if ($print_help)
                                {
                                    print_help_commands('get sessions all [repo] [branch]', 'get list of all sessions');
                                    return 0;
                                }

                                $sessions = $projects->get_all_sessions(array(), $repo, $branch);
                                break;

                            case 'pending':
                                if ($print_help)
                                {
                                    print_help_commands('get sessions pending [repo] [branch]', 'get list of pending sessions');
                                    return 0;
                                }

                                $sessions = $projects->get_all_sessions(array('pending'), $repo, $branch);
                                break;

                            case 'created':
                                if ($print_help)
                                {
                                    print_help_commands('get sessions created [repo] [branch]', 'get list of created sessions');
                                    return 0;
                                }

                                $sessions = $projects->get_all_sessions(array('created'), $repo, $branch);
                                break;

                            case 'finished':
                                if ($print_help)
                                {
                                    print_help_commands('get sessions finished [repo] [branch]', 'get list of finished sessions');
                                    return 0;
                                }

                                $sessions = $projects->get_all_sessions(array('finished_checkout',
                                    'finished_build',
                                    'finished_tests'), $repo, $branch);
                                break;

                            case 'failed':
                                if ($print_help)
                                {
                                    print_help_commands('get sessions failed [repo] [branch]', 'get list of failed sessions');
                                    return 0;
                                }

                                $sessions = $projects->get_all_sessions(array('failed_checkout',
                                                                              'failed_build',
                                                                              'failed_tests'), $repo, $branch);
                                break;

                            case 'aborted':
                                if ($print_help)
                                {
                                    print_help_commands('get sessions aborted [repo] [branch]', 'get list of aborted sessions');
                                    return 0;
                                }

                                $sessions = $projects->get_all_sessions(array('aborted_checkout',
                                                                              'aborted_build',
                                                                              'aborted_tests'), $repo, $branch);
                                break;

                            case 'running':
                                if ($print_help)
                                {
                                    print_help_commands('get sessions running [repo] [branch]', 'get list of running sessions');
                                    return 0;
                                }

                                $sessions = $projects->get_all_sessions(array('running_checkout',
                                    'running_build',
                                    'running_tests'), $repo, $branch);
                                break;

                            default:
                                $sessions = $projects->get_all_sessions(array('running_checkout',
                                    'running_build',
                                    'running_tests',
                                    'pending',
                                    'created'));
                        }

                        if ($print_help)
                        {
                            print_help_commands('get sessions', "get information about all sessions.\n" .
                                "ci get sessions - without command return running, pending and created sessions",
                                array(
                                    'all' => 'get list of all sessions',
                                    'running' => 'get list of running sessions',
                                    'pending' => 'get list of pending sessions',
                                    'created' => 'get list of created sessions',
                                    'finished' => 'get list of finished sessions',
                                    'failed' => 'get list of failed sessions',
                                    'aborted' => 'get list of aborted sessions',
                                ));
                            return 0;
                        }

                        if (!$sessions)
                        {
                            echo "no sessions\n";
                            return 1;
                        }

                        echo "list of sessions:\n";
                        foreach ($sessions as $session)
                            echo $session->get_dir() . "\n";

                        return 0;
                    }

                    case 'session_status':
                        if ($print_help)
                        {
                            print_help_commands('get session_status', 'return current session status');
                            return 0;
                        }

                        if ($obj_type != 'session')
                        {
                            msg_log(LOG_ERR, 'This operation permited only from session dir');
                            return 1;
                        }

                        echo $session->get_state() . "\n";
                        return 0;

                    default:
                        msg_log(LOG_ERR, 'No parameter');
                        $print_help = true;
                }

                if ($print_help)
                {
                    print_help_commands('get', 'get various values',
                        array(
                        'get_last_commit' => 'get last session and return time and commit hash',
                        'free_build_slots' => 'get count of free build clots',
                        'session_status' => 'return current session status',
                        'sessions' => 'get information about all sessions',
                    ));
                    return 0;
                }
            }
            break;

        case 'create':
            {
                $type = isset($argv[2]) ? $argv[2] : NULL;
                $param1 = isset($argv[3]) ? $argv[3] : NULL;

                if (!$type)
                {
                    msg_log(LOG_ERR, '2 argument is empty');
                    $print_help = true;
                }

                if (!$param1)
                {
                    msg_log(LOG_ERR, '3 argument is empty');
                    $print_help = true;
                }

                switch ($type)
                {
                    case 'project':
                        if ($print_help)
                        {
                            print_help_commands('create project [project name] {project_description}', 'create new project');
                            return 0;
                        }

                        $description = isset($argv[4]) ? $argv[4] : NULL;

                        $rc = $projects->add_new_project($param1, $description);
                        break;

                    case 'target':
                        if ($print_help)
                        {
                            print_help_commands('create target [target name] {target_description}', 'create new target');
                            return 0;
                        }

                        if ($obj_type != 'project')
                        {
                            msg_log(LOG_ERR, 'This operation permited only from project dir');
                            return 1;
                        }

                        $description = isset($argv[4]) ? $argv[4] : NULL;

                        $rc = $project->add_new_target($param1, $description);
                        break;

                    case 'session':
                        if ($print_help)
                        {
                            print_help_commands('create session [description]', 'create new session');
                            return 0;
                        }

                        if ($obj_type != 'target')
                        {
                            msg_log(LOG_ERR, 'This operation permited only from project dir');
                            return 1;
                        }

                        $session = $target->add_new_session($param1);
                        echo $session->get_name() . "\n";
                        return 0;

                    default:
                        msg_log(LOG_ERR, 'No specify entity of create');
                        $print_help = true;
                }

                if ($print_help)
                {
                    print_help_commands('create', 'create entity',
                        array(
                            'project' => 'create new project',
                            'target' => 'create new target',
                            'session' => 'create new session',
                        ));
                    return 0;
                }
            }
            break;

        case 'purge':
            $type = isset($argv[2]) ? $argv[2] : NULL;
            if (!$type)
            {
                msg_log(LOG_ERR, '2 argument is empty');
                $print_help = true;
            }

            switch ($type)
            {
                case 'all':
                    if ($print_help)
                    {
                        print_help_commands('purge all', 'delete all not running sessions');
                        return 0;
                    }

                    $sessions = $projects->get_all_sessions(array('aborted_checkout',
                                                                  'aborted_build',
                                                                  'aborted_test',
                                                                  'aborted_pending',
                                                                  'failed_checkout',
                                                                  'failed_build',
                                                                  'failed_test',
                                                                  'finished_checkout',
                                                                  'finished_build',
                                                                  'finished_test',
                                                                 ));

                    if (!$sessions)
                    {
                        echo "no sessions\n";
                        return 0;
                    }

                    foreach ($sessions as $s)
                    {
                        $target = $s->get_target();
                        $target->remove_session($s);
                    }
                    break;
            }

            if ($print_help)
            {
                print_help_commands('purge', 'delete sessions',
                    array(
                        'all' => 'delete all not running sessions',
                        'by_count' => 'delete old sessions',
                        'by_days' => 'delete old sessions',
                        // TODO: //
                    ));
                return 0;
            }
            break;

        case 'all':
            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                $print_help = true;
            }

            $repo = isset($argv[2]) ? $argv[2] : NULL;
            $branch = isset($argv[3]) ? $argv[3] : NULL;
            $commit = isset($argv[4]) ? $argv[4] : NULL;
            $email = isset($argv[5]) ? $argv[5] : NULL;

            if (!$repo)
            {
                msg_log(LOG_ERR, '"repo name" 2 argument is empty');
                $print_help = true;
            }

            if (!$branch)
            {
                msg_log(LOG_ERR, '"branch name" 3 argument is empty');
                $print_help = true;
            }

            if (!$commit)
            {
                msg_log(LOG_ERR, '"commit" 4 argument is empty');
                $print_help = true;
            }

            if (!$email)
            {
                msg_log(LOG_ERR, '"email" 5 argument is empty');
                $print_help = true;
            }

            if ($print_help)
            {
                print_help_commands('all [repo name] [branch name] [commit] [email]',
                    'waiting for free slot and run checkout, build and tests');
                return 1;
            }

            $prev_session_info = find_previous_session_info($target, $repo);

            /*
            * if found pending sessions - switch current session to 'pending' state,
            * and waiting while all pending sessions before current sessions go to build state
            */
            $free_build_slots = get_free_build_slots($projects) + 1;
            // +1 - need because current process run in "created" session

            msg_log(LOG_NOTICE, "Detect " . $free_build_slots . " free build slots on server " .
                $this_server['hostname']);

            if ($free_build_slots <= 0)
            {
                $session->set_status('pending');
                while(true)
                {
                    msg_log(LOG_NOTICE, "waiting while all pending sessions before current session: " .
                        $session->get_info());

                    sleep(10);

                    $free_build_slots = get_free_build_slots($projects);
                    if ($free_build_slots <= 0)
                        continue;

                    /*
                     * if found minimum 1 free build slot
                     * find pending sessions where date less then current session
                     */

                    $pending_sessions = $projects->get_all_sessions('pending');
                    if (!count($pending_sessions))
                    {
                        msg_log(LOG_NOTICE, "end of all pending sessions");
                        break;
                    }

                    // calculate count sessions runned before current session
                    $waiting_count_sessions = 0;
                    foreach ($pending_sessions as $pending_session)
                        if ($pending_session->get_date() < $session->get_date())
                            $waiting_count_sessions++;

                    // if not found pending sessions
                    if (!$waiting_count_sessions)
                    {
                        msg_log(LOG_NOTICE, "end of pending sessions before current session: " . $session->get_info());
                        break;
                    }
                }
            }

            msg_log(LOG_NOTICE, "go to run session: " . $session->get_info());

            $rc = $session->checkout_src($repo, $branch, $commit);
            if (!$rc)
            {
                msg_log(LOG_ERR, 'checkout fail');
                $session->make_report($prev_session_info, $email);
                return 1;
            }

            $rc = $session->build_src();
            if (!$rc)
            {
                msg_log(LOG_ERR, 'build fail');
                $session->make_report($prev_session_info, $email);
                return 1;
            }

            $rc = $session->test_src();
            if (!$rc)
            {
                msg_log(LOG_ERR, 'test fail');
                $session->make_report($prev_session_info, $email);
                return 1;
            }

            $rc = $session->make_report($prev_session_info, $email);
            break;

        case 'checkout':
            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                return 1;
            }

            $repo = isset($argv[2]) ? $argv[2] : NULL;
            $branch = isset($argv[3]) ? $argv[3] : NULL;
            $commit = isset($argv[4]) ? $argv[4] : NULL;
            if (!$repo)
            {
                msg_log(LOG_ERR, '2 argument is empty');
                $print_help = true;
            }

            if (!$branch)
            {
                msg_log(LOG_ERR, '3 argument is empty');
                $print_help = true;
            }

            if (!$commit)
            {
                msg_log(LOG_ERR, '4 argument is empty');
                $print_help = true;
            }

            if ($print_help)
            {
                print_help_commands('checkout [repo] [branch] [commit]',
                    'run checkout sources');
                return 1;
            }

            $rc = $session->checkout_src($repo, $branch, $commit);
            break;

        case 'build':
            if ($print_help)
            {
                print_help_commands('build',
                    'run build sources');
                return 1;
            }

            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                return 1;
            }

            $rc = $session->build_src();
            break;
            
        case 'test':
            if ($print_help)
            {
                print_help_commands('test',
                    'run tests');
                return 1;
            }

            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                return 1;
            }

            $rc = $session->test_src();
            break;

        case 'report':
            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                return 1;
            }

            $email_addr = isset($argv[2]) ? $argv[2] : NULL;

            if ($print_help)
            {
                print_help_commands('report {email address}',
                    'make report');
                return 1;
            }

            $repo = $session->get_repo_name();
            $prev_session_info = find_previous_session_info($target, $repo);
            $rc = $session->make_report($prev_session_info, $email_addr);
            break;

        case 'abort':
            if ($print_help)
            {
                print_help_commands('abort {reason}',
                    'abort current session');
                return 1;
            }

            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                return 1;
            }

            $reason = isset($argv[2]) ? $argv[2] : NULL;

            $rc = $session->abort($reason);
            if (!$rc)
            {
                msg_log(LOG_ERR, 'session not run');
                break;
            }

            $repo = $session->get_repo_name();
            $prev_session_info = find_previous_session_info($target, $repo);
            $session->make_report($prev_session_info);
            break;

        case 'status':
            if ($print_help)
            {
                print_help_commands('status', 'return current build session status');
                return 0;
            }

            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                return 1;
            }

            echo $session->get_state() . "\n";
            return 0;


        default:
            msg_log(LOG_ERR, 'No operation');
            print_help_commands('', 'CI-tool main utility',
                array(
                    'get' => 'get various values',
                    'create' => 'create project or target',
                    'checkout' => 'run checkout sources',
                    'build' => 'run build sources',
                    'test' => 'run tests',
                    'report' => 'make report',
                    'purge' => 'purge old sessions',
                    'abort' => 'abort current session',
                    'status' => 'get build session status',
                    'all' => 'waiting for free slot and run checkout, build and tests',
                ));
            return 1;
    }
    
    return $rc;
}

$rc =  main();
exit($rc);
