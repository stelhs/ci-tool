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
     * abort session
     */
    function abort()
    {
        if($this->get_state() == 'no_process')
            return;

        run_cmd('kill ' . $this->get_pid());
        // make list with child proceses  and kill it

    }

    /**
     * get PID by session script
     * @return int
     */
    function get_pid()
    {
        $session_pid = 0;
        if (is_file($this->dir . '/.pid'))
            $session_pid = (int)file_get_contents($this->dir . '/.pid');

        return $session_pid;
    }

    /**
     * return current session state
     */
    function get_state()
    {
        // - read .staus
     /*   switch ($status)
        {
            case 'running':
                {
                    // check .pid not exist - pizdec
                    // check ps .pid not exist - status set 'aborted', delete .pid - return abort
                }
                break;

            case 'aborted':
                // read from .status
                // if exist .pid - pizdec
            case 'failed':
            case 'finished':
                break;
        }
        */
        $session_pid = $this->get_pid();
        if (!$session_pid)
            return 'no_process';

        // search session process in running proceses
        $list = run_cmd('ps -ax');
        $rows = explode("\n", $list);
        foreach ($rows as $row)
        {
            preg_match('/[ ]*([0-9]+).*/s', $row, $matched);
            if (!$matched)
                continue;

            $pid = (int)$matched[1];

            if ($pid == $session_pid)
                return 'running';
        }

        unlink($this->dir . '/.pid');
        return 'no_process';
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

        $log = run_cmd('cd ' . $this->target->get_dir() . ';' .
            $this->target->get_dir() . '/' . $bash_file . ' ' . $args);

        unlink($this->dir . '/.pid');

        return $log;
    }

    /**
     * run checkout sources
     */
    function checkout_src()
    {
        $log = $this->run_script('.recipe_checkout');
        file_put_contents($this->dir . '/log_checkout', $log);
        // analyze return code, write status

    }

    /**
     * run build sources
     */
    function build_src()
    {
        $log = $this->run_script('.recipe_build');
        file_put_contents($this->dir . '/log_build', $log);
        // analyze return code, write status
    }

    /**
     * run test sources
     */
    function test_src()
    {
        $log = $this->run_script('.recipe_test');
        file_put_contents($this->dir . '/log_test', $log);
        // analyze return code, write status
    }

}
