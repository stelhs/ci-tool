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
     * Get target object
     * @return string
     */
    function get_target()
    {
        return $this->target;
    }

    /**
     * abort session
     */
    function abort()
    {
        if($this->get_state() != 'running')
            return;

        kill_all($this->get_pid());
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
     * Get stored session status
     * @return status name
     */
    private function get_stored_status()
    {
        return get_dot_file_content($this->dir . '/.status');
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
        $list = run_cmd('ps -ax');
        $rows = explode("\n", $list);
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
        $stored_status = $this->get_stored_status();
        switch ($stored_status)
        {
            case 'pending':
            case 'running_checkout':
            case 'running_build':
            case 'running_test':
                if (!$pid)
                {
                    throw new Exception('file .pid not exist');
                }

                // if process not nunning
                if (!check_proc_running($pid))
                {
                    $this->set_status('aborted');
                    return 'aborted';
                }
                return $stored_status;

            case 'aborted_checkout':
            case 'aborted_build':
            case 'aborted_test':
            case 'failed_checkout':
            case 'failed_build':
            case 'failed_test':
            case 'finished_checkout':
            case 'finished_build':
            case 'finished_test':
                if ($pid)
                {
                    throw new Exception("file .pid must be deleted");
                }
                return $stored_status;
        }
    }

    /**
     * Run bash script in new process
     * @param $bash_file - script file name
     * @return log or false
     */
    private function run_script($bash_file, $args = '')
    {
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
        $this->set_status('running_checkout');
        create_file($this->dir . '/.commit', $commit);

        $ret = $this->run_script('.recipe_checkout');
        create_file($this->dir . '/checkout_log', $ret['log']);
        if ($ret['rc'])
        {
            $this->set_status('failed_checkout');
            return false;
        }

        $this->set_status('finished_checkout');
        return true;
    }

    /**
     * run build sources
     */
    function build_src()
    {
        $this->set_status('running_build');
        $ret = $this->run_script('.recipe_build');
        create_file($this->dir . '/build_log', $ret['log']);
        if ($ret['rc'])
        {
            $this->set_status('failed_build');
            return false;
        }

        // TODO: check log file and return status
        // if (error)
        //    $this->set_status('failed_build');

        $this->set_status('finished_build');
        return true;
    }

    /**
     * run test sources
     */
    function test_src()
    {
        $this->set_status('running_test');
        $ret = $this->run_script('.recipe_test');
        create_file($this->dir . '/test_log', $ret['log']);
        if ($ret['rc'])
        {
            $this->set_status('failed_test');
            return false;
        }

        // TODO: check log file and return status
        // if (error)
        //    $this->set_status('failed_build');
        $this->set_status('finished_test');
        return true;
    }

    function make_report()
    {
        global $this_server;

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
        $xml_data['path_to_checkout_log'] = $this->dir . '/checkout_log';
        $xml_data['path_to_build_log'] = $this->dir . '/build_log';
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

        // TODO:
        // scp to web report.xml to "<host>_<project>_<target>_<session>.xml"
    }
}
