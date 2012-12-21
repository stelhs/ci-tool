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
        return 'build_session_' . $this->date->to_string() . '_' . $this->index;
    }


    /**
     * Get session directory
     * @return CiDateTime
     */
    function get_dir()
    {
        return strip_duplicate_slashes($this->dir);
    }

    /**
     * Get session url
     * @return CiDateTime
     */
    function get_url()
    {
        $target = $this->get_target();
        $project = $target->get_project();
        return get_http_url_projects() . '/' . $project->get_name() . '/' . $target->get_name() . '/' . $this->get_name();
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
    function abort($reason = '')
    {
        $current_state = $this->get_state();
        $aborted_state = $this->set_abort_status($current_state);

        $pid = $this->get_pid();
        if ($pid)
        {
            delete_file($this->dir . '/.pid');
            kill_all($pid);
        }

        if (!$reason)
            $reason = 'not specified';
        add_to_file($this->dir . '/build.log', $this->get_log_header('Session was aborted', 'Reason: ' . $reason));

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
     * @return name
     */
    public function get_commit()
    {
        if (!file_exists($this->dir . '/.commit'))
            return false;

        return get_dot_file_content($this->dir . '/.commit');
    }

    /**
     * Get session description
     * @return name
     */
    public function get_description()
    {
        if (!file_exists($this->dir . '/.session_desc'))
            return false;

        return get_dot_file_content($this->dir . '/.session_desc');
    }

    /**
     * Get triggered repo name
     * @return name
     */
    private function get_repo_name()
    {
        if (!file_exists($this->dir . '/.repo'))
            return false;

        return get_dot_file_content($this->dir . '/.repo');
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

            case 'pending':
                create_file($this->dir . '/.pid', getmypid());
        }

        create_file($this->dir .'/.status', $status);
        msg_log(LOG_NOTICE, "status has been changed to: " . $status);
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
                if (!$pid)
                {
                    msg_log(LOG_WARNING, 'session: ' . $this->dir .
                        ' stand in status: "' . $stored_status .
                        '" but .pid file was not found, re run pending');

                    // TODO: Запустить пендинг если тот упал
                }
                return $stored_status;

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
            case 'created':
                if ($pid)
                {
                    msg_log(LOG_WARNING, "file .pid must be deleted from directory: " . $this->dir);
                    delete_file($this->dir . '/.pid');
                }

                return $stored_status;
        }

        return false;
    }

    /**
     * Run bash recipe script
     * @param $bash_file - script file name
     * @return log or false
     */
    private function run_recipe($bash_file, $args = '', $log_file = '')
    {
        global $_CONFIG;
        msg_log(LOG_NOTICE, "run_recipe " . $bash_file . " in session: " . $this->get_info());

        if (!is_file($this->target->get_dir() . '/' . $bash_file))
        {
            throw new Exception($bash_file . ' is not exist');
        }

        create_file($this->dir . '/.pid', getmypid());

        $ret = run_cmd("function error() {\nexit 1\n}\ntrap error ERR;" .
            'cd ' . $this->dir . ' && ' .
//            $_CONFIG['ci_dir'] . "/run_script.sh " .
            $this->target->get_dir() . '/' . $bash_file . ' ' . $args .
            ($log_file ? (' 2>&1 | tee -a ' . $this->dir . '/' . $log_file) : ''),
            false, '', true);

        dump($ret);

        delete_file($this->dir . '/.pid');

        return $ret;
    }


    private function get_log_header($procedure_name, $addition_text = '')
    {
        return
            "\n========================================\n" .
            "\t" . $procedure_name . "\n" .
            ($addition_text ? ("\t" . $addition_text . "\n") : "") .
            "\tDate: " . date("Y-m-d H:i:s") . "\n" .
            "========================================\n\n";
    }

    /**
     * run checkout sources
     */
    public function checkout_src($repo, $branch, $commit)
    {
        msg_log(LOG_NOTICE, "start checkout in session: " . $this->get_info());

        if ($commit == '')
            $commit = 'HEAD';

        $this->set_status('running_checkout');

        create_file($this->dir . '/.commit', $commit);
        create_file($this->dir . '/.repo', $repo);

        add_to_file($this->dir . '/build.log', $this->get_log_header('Checkout procedure'));

        $ret = $this->run_recipe('.recipe_checkout', $repo . ' ' . $branch . ' ' . $commit, 'build.log');
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
    public function build_src()
    {
        msg_log(LOG_NOTICE, "start build in session: " . $this->get_info());

        add_to_file($this->dir . '/build.log', $this->get_log_header('Build procedure'));

        $this->set_status('running_build');
        $ret = $this->run_recipe('.recipe_build', '', 'build.log');
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
    public function test_src()
    {
        msg_log(LOG_NOTICE, "start tests in session: " . $this->get_info());

        if (!file_exists($this->dir . '/.recipe_test'))
        {
            msg_log(LOG_NOTICE, "ignore tests in session: " . $this->get_info() . ' because .recipe_test is not exist');
            return true;
        }

        add_to_file($this->dir . '/build.log', $this->get_log_header('Test procedure'));

        $this->set_status('running_test');
        $ret = $this->run_recipe('.recipe_test', '', 'build.log');
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

    /**
     * Make finalization report.
     * create report.xml
     * create report.html
     * send report by email
     * @param $prev_commit - previous last commit
     * @param string $email_addr - report recepient email address
     */
    public function make_report($prev_commit, $email_addr = '')
    {
        global $_CONFIG, $this_server;

        msg_log(LOG_NOTICE, "start report creation in session: " . $this->get_info());

        $target = $this->get_target();
        $project = $target->get_project();
        $status = $this->get_state();

        $report_data = array();
        $report_data['project_name'] = $project->get_name();
        $report_data['target_name'] = $target->get_name();
        $report_data['session_name'] = $this->get_name();
        $report_data['session_date'] = $this->get_date()->format('Y-m-d h:i:s');
        $report_data['session_dir'] = $this->get_dir();
        $report_data['session_url'] = $this->get_url();
        $report_data['server_hostname'] = $this_server['hostname'];
        $report_data['server_addr'] = $this_server['addr'];
        $report_data['commit'] = $this->get_commit();
        $report_data['prev_commit'] = $prev_commit;
        $report_data['status'] = $status;
        $report_data['repo_name'] = $this->get_repo_name();
        $report_data['session_description'] = $this->get_description();

        if (file_exists($this->dir . '/build.log'))
            $report_data['url_to_build_log'] = strip_duplicate_slashes($this->dir . '/build.log');

        $build_result_paths = array();
        if (file_exists($this->dir . '/.build_result'))
        {
            $content = get_dot_file_content($this->dir . '/.build_result');
            $paths = explode("\n", $content);
            foreach($paths as $path)
            {
                if (!trim($path))
                    continue;

                $build_result_paths[] = strip_duplicate_slashes($this->get_url() . '/' . $path);
            }
        }

        /*
         * create git-log
         */
        if ($prev_commit)
        {
            $rc = run_cmd('ssh ' . $_CONFIG['git_server'] . ' get-git-log ' .
                $this->get_repo_name() . ' ' . $prev_commit . ' ' . $this->get_commit());

            if ($rc['rc'])
                msg_log(LOG_ERR, 'Can\'t get git-log. script get-git-log say: ' . $rc['log']);
            else
                $report_data['git_log'] = $rc['log'];
        }

        /*
        * generate XML report file
        */
        $xml_data = $report_data;
        if ($build_result_paths)
            foreach($build_result_paths as $i => $path)
                $xml_data['build_result%' . $i] = $path;

        $xml_content = create_xml($xml_data);
        create_file($this->dir . '/.report.xml', $xml_content);


        /*
         * generate html filelfds
         */
        $tpl = new Tpl($_CONFIG['ci_dir'] . '/templates/report.html', array(), TPL_INTERPRETER_MODE, '', false);
        $tpl->assign(0, $report_data);
        if ($build_result_paths)
            foreach($build_result_paths as $path)
                $tpl->assign("result", array('result_url' => $path));

        create_file($this->dir . '/report.html', $tpl->make_result());


        /*
         * generate and send email
         */
        $tpl = new Tpl($_CONFIG['ci_dir'] . '/templates/email_report.html', array(), TPL_INTERPRETER_MODE, '', false);
        $subject_template = trim($tpl->load_block('build_result_subject'));
        $email_template = $tpl->load_block('build_result');

        $subject_tpl = new Tpl();
        $subject_tpl->open_buffer($subject_template);
        $subject_tpl->assign(0, $report_data);
        $subject = $subject_tpl->make_result();

        $email_tpl = new Tpl();
        $email_tpl->open_buffer($email_template);
        $email_tpl->assign(0, $report_data);

        //succesfull_build
        switch ($report_data['status'])
        {
            case 'finished_checkout':
            case 'finished_build':
            case 'finished_test':
                $email_tpl->assign("succesfull_build", array('status' => $report_data['status']));
                break;

            default:
                $email_tpl->assign("fail_build", array('status' => $report_data['status']));
                break;
        }

        if ($build_result_paths)
            foreach($build_result_paths as $path)
                $email_tpl->assign("result", array('result_url' => $path));
        $email_body = $email_tpl->make_result();

        // get list email addresses from target settings
        $target = $this->get_target();
        $email_list = $target->get_email_list();
        if (!$email_list)
            $email_list = array();

        // added personal address to list addresses
        if ($email_addr)
            $email_list[] = $email_addr;

        // send email to all address list
        if ($email_list)
            foreach ($email_list as $m_addr)
                mail($m_addr, $subject, $email_body, $_CONFIG['email_header']);

        msg_log(LOG_NOTICE, "report successfully created in session: " . $this->get_info());
        return 0;
    }
}
