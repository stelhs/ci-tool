<?php
/**
 * Created by Mikhail Kurachkin.
 * Date: 09.10.12
 * Time: 10:13
 * Info: session container
 */
class Session
{
    private $target;
    private $date; // CiDateTime
    private $index;
    private $dir; // session directory

    function __construct(Target $target, $session_dir, CiDateTime $date, $index = 0)
    {
        $this->target = $target;
        $this->dir = $session_dir;
        $this->date = $date;
        $this->index = $index;
    }

    /**
     * Get session name
     * @return string
     */
    function get_name()
    {
        return 'build_session_' . $this->index . '_' . $this->date->to_string();
    }


    /**
     * Get session directory
     * @return CiDateTime
     */
    function get_dir()
    {
        return $this->dir;
    }

    /**
     * Get session date
     * @return CiDateTime
     */
    function get_date()
    {
        return $this->date;
    }

    /**
     * Get target object
     * @return string
     */
    function get_target()
    {
        return $this->target;
    }

    /**
     * Get info about session
     */
    function get_info()
    {
        $project = $this->target->get_project();
        return $project->get_name() . '/' . $this->target->get_name() . '/' . $this->get_name();
    }

    private function set_abort_status($current_state)
    {
        switch ($current_state)
        {
            case 'running_checkout':
                $need_status = 'aborted_checkout';
                break;

            case 'running_build':
                $need_status = 'aborted_build';
                break;

            case 'running_test':
                $need_status = 'aborted_test';
                break;

            case 'pending':
                $need_status = 'aborted_pending';
                break;

            default:
                msg_log(LOG_WARNING, "current session not in running state");
                return false;
        }

        $this->set_status($need_status);
        return $need_status;
    }
        /**
     * abort session
     * return session status or false if session was not aborted
     */
    function abort()
    {
        $current_state = $this->get_state();
        $aborted_state = $this->set_abort_status($current_state);

        $pid = $this->get_pid();
        if ($pid)
        {
            delete_file($this->dir . '/.pid');
            kill_all($pid);
        }

        msg_log(LOG_NOTICE, "session was aborted");
        return $aborted_state;
    }

    /**
     * get PID by session script
     * @return int
     */
    function get_pid()
    {
        $session_pid = 0;
        if (is_file($this->dir . '/.pid'))
            $session_pid = (int)get_dot_file_content($this->dir . '/.pid');

        return $session_pid;
    }

    /**
     * Get commit number
     * @return status name
     */
    private function get_commit()
    {
        if (!file_exists($this->dir . '/.commit'))
            return false;

        return get_dot_file_content($this->dir . '/.commit');
    }

    /**
     * Store status into a file .status
     * and check for valid session
     */
    function set_status($status)
    {
        switch ($status)
        {
            case 'aborted':
                delete_file($this->dir . '/.pid');
        }

        create_file($this->dir .'/.status', $status);
    }

    /**
     * Check for running process
     * @param $pid - PID
     * @return bool true - running, false - not running
     */
    private function check_proc_running($pid)
    {
        $rc = run_cmd('ps -ax');
        if ($rc['rc'])
            throw new Exception("run 'ps -ax': return code - failure");

        $rows = explode("\n", $rc['log']);
        if (!$rows)
            throw new Exception("incorrect output from command: ps -ax");

        foreach ($rows as $row)
        {
            preg_match('/[ ]*([0-9]+).*/s', $row, $matched);
            if (!$matched)
                continue;

            $curr_pid = (int)$matched[1];

            if ($curr_pid == $pid)
                return true;
        }

        return false;
    }

    /**
     * return current session state
     */
    function get_state()
    {
        $pid = $this->get_pid();
        $stored_status = get_dot_file_content($this->dir . '/.status');
        switch ($stored_status)
        {
            case 'pending':
            case 'running_checkout':
            case 'running_build':
            case 'running_test':
                if (!$pid)
                {
                    msg_log(LOG_WARNING, 'session: ' . $this->dir .
                        ' stand in status: "' . $stored_status .
                        '" but .pid file was not found');

                    return $this->set_abort_status($stored_status);
                }

                // if process not nunning
                if (!$this->check_proc_running($pid))
                    return $this->set_abort_status($stored_status);

                return $stored_status;

            case 'aborted_checkout':
            case 'aborted_build':
            case 'aborted_test':
            case 'aborted_pending':
            case 'failed_checkout':
            case 'failed_build':
            case 'failed_test':
            case 'finished_checkout':
            case 'finished_build':
            case 'finished_test':
                if ($pid)
                    msg_log(LOG_WARNING, "file .pid must be deleted from directory: " . $this->dir);

                return $stored_status;
        }

        return false;
    }

    /**
     * Run bash script in new process
     * @param $bash_file - script file name
     * @return log or false
     */
    private function run_script($bash_file, $args = '')
    {
        msg_log(LOG_NOTICE, "run_script " . $bash_file . " in session: " . $this->get_info());

        if (!is_file($this->target->get_dir() . '/' . $bash_file))
        {
            throw new Exception($bash_file . ' is not exist');
        }

        create_file($this->dir . '/.pid', getmypid());

        $ret = run_cmd('cd ' . $this->dir . ';' .
            $this->target->get_dir() . '/' . $bash_file . ' ' . $args);

        delete_file($this->dir . '/.pid');

        return $ret;
    }

    /**
     * run checkout sources
     */
    function checkout_src($commit)
    {
        msg_log(LOG_NOTICE, "start checkout in session: " . $this->get_info());

        $this->set_status('running_checkout');
        create_file($this->dir . '/.commit', $commit);

        $ret = $this->run_script('.recipe_checkout');
        create_file($this->dir . '/checkout_log', $ret['log']);
        if ($ret['rc'])
        {
            msg_log(LOG_ERR, "failed checkout in session: " . $this->get_info());
            $this->set_status('failed_checkout');
            return false;
        }

        $this->set_status('finished_checkout');

        msg_log(LOG_NOTICE, "finished checkout in session: " . $this->get_info());
        return true;
    }

    /**
     * run build sources
     */
    function build_src()
    {
        msg_log(LOG_NOTICE, "start build in session: " . $this->get_info());

        $this->set_status('running_build');
        $ret = $this->run_script('.recipe_build');
        create_file($this->dir . '/build_log', $ret['log']);
        if ($ret['rc'])
        {
            $this->set_status('failed_build');
            msg_log(LOG_ERR, "failed build in session: " . $this->get_info());
            return false;
        }

        // TODO: check log file and return status
        // if (error)
        //    $this->set_status('failed_build');

        $this->set_status('finished_build');
        msg_log(LOG_NOTICE, "finished build in session: " . $this->get_info());
        return true;
    }

    /**
     * run test sources
     */
    function test_src()
    {
        msg_log(LOG_NOTICE, "start tests in session: " . $this->get_info());

        $this->set_status('running_test');
        $ret = $this->run_script('.recipe_test');
        create_file($this->dir . '/test_log', $ret['log']);
        if ($ret['rc'])
        {
            $this->set_status('failed_test');
            msg_log(LOG_ERR, "failed tests in session: " . $this->get_info());
            return false;
        }

        // TODO: check log file and return status
        // if (error)
        //    $this->set_status('failed_build');
        $this->set_status('finished_test');
        msg_log(LOG_NOTICE, "finished tests in session: " . $this->get_info());

        return true;
    }

    function make_report()
    {
        global $this_server;

        msg_log(LOG_NOTICE, "start report creation in session: " . $this->get_info());

        $target = $this->get_target();
        $project = $target->get_project();
        $status = $this->get_state();

        // generate XML report file
        $xml_data = array();
        $xml_data['project_name'] = $project->get_name();
        $xml_data['target_name'] = $target->get_name();
        $xml_data['session_name'] = $this->get_name();
        $xml_data['server'] = $this_server['hostname'];
        $xml_data['commit'] = $this->get_commit();
        $xml_data['status'] = $status;

        if (file_exists($this->dir . '/checkout_log'))
            $xml_data['path_to_checkout_log'] = $this->dir . '/checkout_log';

        if (file_exists($this->dir . '/build_log'))
            $xml_data['path_to_build_log'] = $this->dir . '/build_log';

        if (file_exists($this->dir . '/test_log'))
            $xml_data['path_to_test_log'] = $this->dir . '/test_log';

        if (file_exists($this->dir . '/.build_result'))
        {
            $content = get_dot_file_content($this->dir . '/.build_result');
            $paths = explode("\n", $content);
            foreach($paths as $i => $path)
            {
                if (!trim($path))
                    continue;

                $xml_data['build_result%' . $i] = $this->dir . '/' . $path;
            }
        }

        $xml_content = create_xml($xml_data);
        create_file($this->dir . '/report.xml', $xml_content);

        msg_log(LOG_NOTICE, "report successfully created in session: " . $this->get_info());

        return 0;
        // TODO:
        // scp to web report.xml to "<host>_<project>_<target>_<session>.xml"
    }
}
