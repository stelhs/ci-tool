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
        if($this->get_state() == 'no_process')
            return;

        run_cmd('kill ' . $this->get_pid());
        // TODO: make list with child proceses  and kill it
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
        return get_dot_file_content('.status');
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
                unlink($this->dir . '/.pid');
        }

        file_put_contents($this->dir .'/.status', $status);
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
            case 'running':
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
            case 'finished':
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
            print_error($bash_file . ' is not exist');
            return false;
        }

        file_put_contents($this->dir . '/.pid', getmypid());

        $ret = run_cmd('cd ' . $this->target->get_dir() . ';' .
            $this->target->get_dir() . '/' . $bash_file . ' ' . $args);

        unlink($this->dir . '/.pid');

        return $ret;
    }

    /**
     * run checkout sources
     */
    function checkout_src($commit)
    {
        $this->set_status('running');
        file_put_contents($this->dir . '/.commit', $commit);

        $ret = $this->run_script('.recipe_checkout');
        file_put_contents($this->dir . '/checkout_log', $ret['log']);
        if ($ret['rc'])
        {
            $this->set_status('failed_checkout');
            return false;
        }

        return true;
    }

    /**
     * run build sources
     */
    function build_src()
    {
        $this->set_status('running');
        $ret = $this->run_script('.recipe_build');
        file_put_contents($this->dir . '/build_log', $ret['log']);
        if ($ret['rc'])
        {
            $this->set_status('failed_build');
            return false;
        }

        // TODO: check log file and return status
        // if (error)
        //    $this->set_status('failed_build');

        return true;
    }

    /**
     * run test sources
     */
    function test_src()
    {
        $this->set_status('running');
        $ret = $this->run_script('.recipe_test');
        file_put_contents($this->dir . '/test_log', $ret['log']);
        if ($ret['rc'])
        {
            $this->set_status('failed_test');
            return false;
        }

        // TODO: check log file and return status
        // if (error)
        //    $this->set_status('failed_build');
    }

    function make_report()
    {
        $target = $this->get_target();
        $project = $target->get_project();

        // generate XML report file
        $xml_data = array();
        $xml_data['project_name'] = $project->get_name();
        $xml_data['target_name'] = $target->get_name();
        $xml_data['session_name'] = $this->get_name();
        $xml_data['status'] = $this->get_state();
        $xml_data['path_to_checkout_log'] = $this->dir . '/checkout_log';
        $xml_data['path_to_build_log'] = $this->dir . '/build_log';
        $xml_data['path_to_test_log'] = $this->dir . '/test_log';

        if (file_exists($this->dir . '/.build_result'))
        {
            $content = get_dot_file_content($this->dir . '/.build_result');
            $paths = explode("\n", $content);
            foreach($paths as $i => $path)
                $xml_data['build_result%' . $i] = $this->dir . '/' . $path;
        }

        $xml_content = create_xml($xml_data);
        file_put_contents($this->dir . '/report.xml', $xml_content);
    }
}
