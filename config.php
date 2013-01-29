<?php
$_CONFIG['ci_dir'] = '/opt/ci-tool/';
$_CONFIG['project_dir'] = '/var/opt/ci-projects/';
$_CONFIG['web'] = '/home/stelhs/projects/ci/web/';
$_CONFIG['ci_repo'] = 'ci-tool.git';
$_CONFIG['ci_projects_repo_mask'] = 'ci-*';
$_CONFIG['git_server'] = 'git.promwad.com';
$_CONFIG['ci_servers'] = array(
                                array('hostname' => 'sp-build03-lo1.promwad.corp',
                                      'addr' => '192.168.10.2',
                                      'port' => 22,
                                      'login' => 'ci-tool',
                                      'role' => 'build',
                                      'max_build_slots' => 1,
                                      'description' => 'xz',
                                ),

                                array('hostname' => 'ws-127',
                                    'addr' => '192.168.10.244',
                                    'port' => 22,
                                    'login' => 'ci-tool',
                                    'role' => 'web',
                                    'max_build_slots' => 1,
                                    'description' => 'xz',
                                ),
                        );

$_CONFIG['email_header'] = 'From: ci-tool@promwad.com'."\n".
    'Reply-To: ci-tool@promwad.com'."\n".
    'Content-type:text/html; Charset=utf-8'."\r\n";

$_CONFIG['debug_level'] = array(LOG_ERR, LOG_WARNING, LOG_NOTICE);
