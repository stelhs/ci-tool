#!/usr/bin/php
<?php

$Ci_dir = '/opt/ci-tool/';
require_once($Ci_dir . 'config.php');

require_once($_CONFIG['ci_dir'] . 'lib.php');
require_once($_CONFIG['ci_dir'] . 'xml.php');
require_once($_CONFIG['ci_dir'] . 'List_projects.php');
require_once($_CONFIG['ci_dir'] . 'CiDateTime.php');

$utility_name = 'ci';
$this_server = get_current_ci_server();
if (!$this_server)
    throw new Exception("Can't detect current server");





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
    msg_log(LOG_ERR, $exception->getMessage());
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

    $list_sessions = $projects->get_all_sessions(array('running_checkout',
                                                       'running_build',
                                                       'running_test',
                                                       'pending'));

    // count sessions in running mode
    $build_processes = count($list_sessions);

    $free_build_slots = $this_server['max_build_slots'] - $build_processes;
    return $free_build_slots;
}


function main()
{
    global $argv, $_CONFIG;
    $rc = 0;

    set_exception_handler('error_exception');

    // create list all projects
    $projects = new List_projects($_CONFIG['project_dir']);

    // Check operation object
    $obj_type = '';

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
                    case 'free_build_slots':
                        if ($print_help)
                        {
                            print_help_commands('get free_build_slots', 'get count of free build clots');
                            return 0;
                        }

                        echo get_free_build_slots($projects) . "\n";
                        return 0;

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
                        'free_build_slots' => 'get count of free build clots',
                        'session_status' => 'return current session status',
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
                            print_help_commands('create project [project name]', 'create new project');
                            return 0;
                        }

                        $rc = $projects->add_new_project($param1);
                        break;

                    case 'target':
                        if ($print_help)
                        {
                            print_help_commands('create target [target name]', 'create new target');
                            return 0;
                        }

                        if ($obj_type != 'project')
                        {
                            msg_log(LOG_ERR, 'This operation permited only from project dir');
                            return 1;
                        }

                        $rc = $project->add_new_target($param1);
                        break;

                    case 'session':
                        if ($print_help)
                        {
                            print_help_commands('create session', 'create new session');
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

        case 'all':
            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                $print_help = true;
            }

            $repo = isset($argv[2]) ? $argv[2] : NULL;
            $branch = isset($argv[3]) ? $argv[3] : NULL;
            $commit = isset($argv[4]) ? $argv[4] : NULL;

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

            if ($print_help)
            {
                print_help_commands('all [repo name] [branch name] [commit]',
                    'waiting for free slot and run checkout, build and tests');
                return 1;
            }

            $pending_sessions = $projects->get_all_sessions('pending');

            /*
             * if found pending sessions - switch current session to 'pending' state,
             * and waiting while all pending sessions before current sessions go to build state
             */
            while(count($pending_sessions))
            {
                $session->set_status('pending');

                msg_log(LOG_NOTICE, "waiting while all pending sessions before current session: " .
                    $session->get_info() . " go to build state");

                sleep(10);

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

                if (!$waiting_count_sessions)
                {
                    msg_log(LOG_NOTICE, "end of pending sessions before current session: " . $session->get_info());
                    break;
                }
            }

            msg_log(LOG_NOTICE, "go to run session: " . $session->get_info());


            $rc = $session->checkout_src($commit);
            if ($rc['rc'])
            {
                msg_log(LOG_ERR, 'checkout fail');
                return 1;
            }

            $rc = $session->build_src();
            if ($rc['rc'])
            {
                msg_log(LOG_ERR, 'build fail');
                return 1;
            }

            $rc = $session->test_src();
            if ($rc['rc'])
            {
                msg_log(LOG_ERR, 'test fail');
                return 1;
            }

            $rc = $session->make_report();
            break;

        case 'checkout':
            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                return 1;
            }

            $commit = isset($argv[2]) ? $argv[2] : NULL;
            if (!$commit)
            {
                msg_log(LOG_ERR, '2 argument is empty');
                $print_help = true;
            }

            if ($print_help)
            {
                print_help_commands('checkout [commit]',
                    'run checkout sources');
                return 1;
            }

            $rc = $session->checkout_src($commit);
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
            if ($print_help)
            {
                print_help_commands('report',
                    'make report');
                return 1;
            }

            if ($obj_type != 'session')
            {
                msg_log(LOG_ERR, 'This operation permited only from session dir');
                return 1;
            }

            $rc = $session->make_report();
            break;

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
                    'all' => 'waiting for free slot and run checkout, build and tests',
                ));
            return 1;
    }
    
    return $rc;
}

return main();
