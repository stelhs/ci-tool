<?php
$_CONFIG['ci_dir'] = '/opt/ci-tool/';
$_CONFIG['project_dir'] = '/var/opt/ci-projects/';
$_CONFIG['web'] = '/home/stelhs/projects/ci/web/';
$_CONFIG['ci_repo'] = 'ci-tool.git';
$_CONFIG['ci_projects_repo'] = 'ci-projects.git';
$_CONFIG['ci_servers'] = array(
                                array('host' => '192.168.10.244',
                                      'port' => 22,
                                      'login' => 'ci-tool',
                                      'role' => 'web',
                                      'description' => 'xz',
                                ),

                                array('host' => '192.168.10.2',
                                      'port' => 22,
                                      'login' => 'ci-tool',
                                      'role' => 'build',
                                      'description' => 'xz',
                                ),
                              );

