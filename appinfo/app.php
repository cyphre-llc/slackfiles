<?php
/**
 * Author: Ben Collins <bem.c@servergy.com>
 * Copyright (c) 2015 Servergy, Inc.
 */

if (\OCP\App::isEnabled('files_external') and \OCP\App::isEnabled('slacknotify')) {
	$l = \OC_L10N::get('slackfiles');

	OC::$CLASSPATH['OC\Files\Storage\Slack'] = 'slackfiles/lib/slack.php';

	OC_Mount_Config::registerBackend('\OC\Files\Storage\Slack', array(
		'backend' => 'Slack Files',
		'priority' => 100,
		'configuration' => array(
			'justme' => '!'.$l->t('Only my files'),
			'auto' => '>',
		),
		'has_dependencies' => true));
}
