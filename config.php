<?php
$_CONFIG['ci_dir'] = '/opt/ci-tool/';
$_CONFIG['project_dir'] = '/var/opt/ci-projects/';
$_CONFIG['web'] = '/home/stelhs/projects/ci/web/';
$_CONFIG['ci_repo'] = 'ci-tool.git';
$_CONFIG['ci_projects_repo'] = 'ci-projects.git';
$_CONFIG['ci_servers'] = array(
                                array('hostname' => 'ws-127',
                                      'addr' => '192.168.10.244',
                                      'port' => 22,
                                      'login' => 'ci-tool',
                                      'role' => 'web',
                                      'max_build_slots' => 6,
                                      'description' => 'xz',
                                ),

                                array('hostname' => 'ws-002',
                                      'addr' => '192.168.10.2',
                                      'port' => 22,
                                      'login' => 'ci-tool',
                                      'role' => 'build',
                                      'max_build_slots' => 5,
                                      'description' => 'xz',
                                ),
                              );

$_CONFIG['debug_level'] = array(LOG_ERR, LOG_WARNING, LOG_NOTICE);
