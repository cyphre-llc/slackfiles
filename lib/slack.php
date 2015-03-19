<?php
/**
 * Author: Ben Collins <ben.c@servergy.com>
 * Copyright (c) 2015 Servergy, Inc.
 */
namespace OC\Files\Storage;

class Slack extends Common {
	private $xoxp;
	private $channel;
	private $team;
	private $limit;
	private $Slack;
	private $id;

	// Cached information about files
	private $dirList;
	private static $userInfo;

	private static $tempFiles = array();

	private function findFile($path) {
		$path = rtrim($path, '/');
		$this->retrieveFiles();

		if (empty($path) or !empty($this->dirList[$path]))
			return false;

		$parts = explode('/', $path, 2);
		if (count($parts) != 2)
			return false;

		if (empty($this->dirList[$parts[0]]['files'][$parts[1]]))
			return false;

		return $this->dirList[$parts[0]]['files'][$parts[1]];
	}

	public function __construct($params = array()) {
		$user = \OCP\User::getUser();
		$config = \OC::$server->getConfig();

		$this->xoxp = $config->getUserValue($user, 'slacknotify', 'xoxp');
		$this->channel = $config->getUserValue($user, 'slacknotify', 'channel');
		$this->team = $config->getUserValue($user, 'slacknotify', 'team_id');
		$this->disList = array();
		$this->Slack = new \OCA\SlackNotify\SlackAPI($this->xoxp);
		$this->id = 'slackfiles::' . $this->channel . '@' . $this->team . '/';

		if (empty($this->xoxp) or empty($this->channel) or empty($this->team))
			throw new \Exception('Must Authenticate with Slack');
	}

	public function test() {
		if (!isset($this->xoxp) or !isset($this->channel) or !isset($this->team))
			return false;

		// TODO Run auth.test here

		return true;
	}

	public function getId() {
		$this->id;
	}

	// Forces a rescan of the files list
	private function clearFiles() {
		$this->dirList = array();
	}

	private function getRealUsername($user) {
		if (empty(self::$userInfo[$user])) {
			$ret = $this->Slack->call('users.info', array('user' => $user));
	                if (empty($ret['ok']) or $ret['ok'] !== true) {
	                        \OCP\Util::writeLog('slackfiles', 'users.info => '.$ret['error'], \OCP\Util::ERROR);
	                        return false;
	                }
			self::$userInfo[$user] = $ret['user']['name'];
		}
		return self::$userInfo[$user];
	}

	private function retrieveFiles() {
		if (!empty($this->dirList))
			return;

		$ret = $this->Slack->call('files.list', array('count' => 0));
		if (empty($ret['ok']) or $ret['ok'] !== true) {
			\OCP\Util::writeLog('slackfiles', 'files.list => '.$ret['error'], \OCP\Util::ERROR);
			return;
		}

		foreach ($ret['files'] as $item) {
			$name = $item['name'];
			$mtime = $item['created'];

			$user = $this->getRealUsername($item['user']);

			$this->dirList[$user]['files'][$name] = $item;

			if (empty($this->dirList[$user]['count']))
				$this->dirList[$user]['count'] = 1;
			else
				$this->dirList[$user]['count']++;

			if (empty($this->dirList[$user]['mtime']))
				$this->dirList[$user]['mtime'] = $mtime;
			else if ($this->dirList[$user]['mtime'] < $mtime)
				$this->dirList[$user]['mtime'] = $mtime;
		}
	}

	public function opendir($path) {
		$path = rtrim($path, '/');
		try {
			$this->retrieveFiles();

			if (!empty($path) and empty($this->dirList[$path]))
				return false;

			$dirStream = array();
			$id = md5('slackdir:' . $path);

			if (empty($path)) {
				// Root directory
				foreach ($this->dirList as $key => $item) {
					$dirStream[] = $key;
				}
			} else {
				// User subdir
				foreach ($this->dirList[$path]['files'] as $file) {
					$dirStream[] = $file['name'];
				}
			}
			\OC\Files\Stream\Dir::register($id, $dirStream);
			return opendir('fakedir://' . $id);
		} catch(\Exception $e) {
		}

		return false;
	}

	public function filetype($path) {
		$path = rtrim($path, '/');
		$this->retrieveFiles();

		if (empty($path) or !empty($this->dirList[$path]))
			return 'dir';

		return 'file';
	}

	public function file_exists($path) {
		$path = rtrim($path, '/');
		$this->retrieveFiles();

		if (empty($path) or !empty($this->dirList[$path]))
			return true;

		return $this->findFile($path) ? true : false;
	}

	public function unlink($path) {
		$item = $this->findFile($path);
		if (!$item)
			return true;

		$ret = $this->Slack->call('files.delete', array('file' => $item['id']));
		if (empty($ret['ok']) or $ret['ok'] !== true) {
			\OCP\Util::writeLog('slackfiles', 'files.delete => '.$ret['error'], \OCP\Util::ERROR);
			return false;
		}

		clearstatcache(false, $this->constructUrl($path));
		$this->clearFiles();
		unset($this->dirList[$path]);

		return true;
	}

	public function fopen($path, $mode) {
		$path = rtrim($path, '/');
		try {
			switch($mode) {
			case 'r':
			case 'rb':
				if (!$this->file_exists($path)) {
					return false;
				}
				return fopen($this->constructUrl($path), $mode);
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				// XXX For now, just don't do this
				return false;

				//emulate these
				$tmpFile = \OC_Helper::tmpFile();
				\OC\Files\Stream\Close::registerCallback($tmpFile, array($this, 'writeBack'));
				if ($this->file_exists($path)) {
					$this->getFile($path, $tmpFile);
				}
				self::$tempFiles[$tmpFile] = $path;
				$this->clearFiles();
				return fopen('close://'.$tmpFile, $mode);
			}
		} catch (\Exception $e) {
		}
		return false;
	}

        public function writeBack($tmpFile) {
		if (!isset(self::$tempFiles[$tmpFile]))
			return;

		self::uploadFile($tmpFile, self::$tempFiles[$tmpFile]);
		unlink($tmpFile);
		unset(self::$tempFiles[$tmpFile]);
        }

	public function uploadFile($path, $target) {
		// XXX Disabled for now because of files_encryption
		return false;

		$cfile = new \CURLFile($path);
		$cfile->setPostFilename($target);

		$ret = $this->Slack->call('files.upload', array(
				'filename' => $target,
				'file' => $cfile,
			), 86400);

		if (empty($ret['ok']) or $ret['ok'] !== true) {
			\OCP\Util::writeLog('slackfiles', 'files.upload => '.$ret['error'], \OCP\Util::ERROR);
			return false;
		}

		$this->dirList[$path] = $ret['file'];
		return true;
	}

	public function stat($path) {
		$path = rtrim($path, '/');
		$this->retrieveFiles();

		$stat = array();
		if (empty($path)) {
			// Get the newest time out of all the entries
			$count = $time = 0;

			foreach ($this->dirList as $user) {
				$time = max($user['mtime'], $time);
				$count++;
			}
			$stat['size'] = $count;
			$stat['mtime'] = $stat['atime'] = $time;
			$stat['mode'] = 0040555;
		} else if (!empty($this->dirList[$path])) {
			$count = $time = 0;

			foreach ($this->dirList[$path]['files'] as $file) {
				$time = max($file['created'], $time);
				$count++;
			}
			$stat['size'] = $count;
			$stat['mtime'] = $stat['atime'] = $time;
			$stat['mode'] = 0040555;
		} else {
			$item = $this->findFile($path);
			if (!$item)
				return false;

			$stat['size'] = $item['size'];
			$stat['mtime'] = $stat['atime'] = $item['created'];
			$stat['mode'] = 0100444;
		}
		return $stat;
	}

	/**
	 * @param string $path
	 */
	public function constructUrl($path) {
		$item = $this->findFile($path);
		if (!empty($item['url_download']))
			return $item['url_download'];
		else
			return $item['url'];
	}

	public function getMimeType_unused($path) {
		$path = rtrim($path, '/');
		$this->retrieveFiles();

		if (empty($path) or !empty($this->dirList[$path]))
                        return 'httpd/unix-directory';

		$item = $this->findFile($path);
		if (empty($item))
			return false;

		return $item['mimetype'];
        }

        /**
         * check if slacknotify is installed
	 * TODO Check for app key/secret
         */
        public static function checkDependencies() {
		return \OCP\App::isEnabled('slacknotify');
        }

	public function mkdir($path)			{ return false; }
	public function rmdir($path)			{ return false; }
	public function touch($path, $mtime = NULL)	{ return false; }
	public function rename($path1, $path2)		{ return false; }
	public function copy($path1, $path2)		{ return false; }
}
