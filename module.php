<?php
	/**
	 *  +------------------------------------------------------------+
	 *  | apnscp                                                     |
	 *  +------------------------------------------------------------+
	 *  | Copyright (c) Apis Networks                                |
	 *  +------------------------------------------------------------+
	 *  | Licensed under Artistic License 2.0                        |
	 *  +------------------------------------------------------------+
	 *  | Author: Matt Saladna (msaladna@apisnetworks.com)           |
	 *  +------------------------------------------------------------+
	 */

	use Module\Support\Webapps;
	use Module\Support\Webapps\App\Type\Bookstack\Handler;
	use Opcenter\Auth\Password;
	use Opcenter\Auth\Shadow;

	/**
	 * BookStack management
	 *
	 * @package core
	 */
	class Bookstack_Module extends Laravel_Module
	{
		const DEFAULT_VERSION_LOCK = 'major';
		const APP_NAME = Handler::NAME;
		const VALIDITY_FILE = null;

		protected $aclList = array(
			'min' => array(
				'bootstrap/cache',
				'storage',
				'public/uploads'
			),
			'max' => array(
				'bootstrap/cache',
				'storage',
				'public/uploads'
			)
		);

		public function install(string $hostname, string $path = '', array $opts = array()): bool
		{
			return parent::install($hostname, $path, $opts);
		}

		public function get_version(string $hostname, string $path = ''): ?string
		{
			$approot = $this->getAppRoot($hostname, $path);
			if (!$this->file_exists("{$approot}/version")) {
				return null;
			}

			return trim($this->file_get_file_contents("{$approot}/version"), "v\n");
		}

		public function update_all(string $hostname, string $path = '', string $version = null): bool
		{
			return parent::update_all($hostname, $path, $version);
		}

		public function update(string $hostname, string $path = '', string $version = null): bool
		{
			$docroot = $this->getDocumentRoot($hostname, $path);
			parent::setInfo($docroot, [
				'failed' => true
			]);

			if (!$docroot) {
				return error('update failed');
			}
			$approot = $this->getAppRoot($hostname, $path);
			$oldversion = $this->get_version($hostname, $path) ?? $version;

			$ret = $this->downloadVersion($approot, $version);
			if ($version && $oldversion === $version || !$ret) {
				return error("Failed to update %(name)s from `%(old)s' to `%(new)s', check composer.json for version restrictions",
					['name' => static::APP_NAME, 'old' => $oldversion, 'new' => $version]
				);
			}
			$ret = $this->execComposer($approot, 'install -o --no-dev');

			$this->postUpdate($hostname, $path);
			parent::setInfo($docroot, [
				'version' => $oldversion,
				'failed'  => !$ret['success']
			]);

			return $ret['success'] ?: error($ret['stderr']);
		}


		protected function postInstall(string $hostname, string $path): bool
		{
			$approot = $this->getAppRoot($hostname, $path);
			$this->execComposer($approot, 'install --no-dev');
			foreach(['key:generate'] as $task) {
				$ret = $this->execPhp($approot, static::BINARY_NAME . ' %s', [$task]);
			}
			if (!$ret['success']) {
				return error("Failed to finish %(name)s install: %(err)s", [
					'name' => static::APP_NAME, 'err' => coalesce($ret['stderr'], $ret['stdout'])
				]);
			}

			return $this->postUpdate($hostname, $path);
		}

		protected function postUpdate(string $hostname, string $path): bool
		{
			$approot = $this->getAppRoot($hostname, $path);
			$this->execComposer($approot, 'install --no-dev');
			foreach (['migrate', 'cache:clear', 'vendor:publish'] as $directive) {
				$this->execPhp($approot, static::BINARY_NAME . ' ' . $directive);
			}

			return true;
		}

		protected function notifyInstalled(string $hostname, string $path = '', array $args = []): bool
		{
			$args['login'] ??= $args['user'];
			$this->change_admin($hostname, $path, ['user' => $args['user'], 'email' => $args['email'], 'password' => $args['password']]);
			return parent::notifyInstalled($hostname, $path, $args);
		}

		/**
		 * Get BookStack framework versions
		 *
		 * @return array
		 */
		public function get_versions(): array
		{
			return array_column($this->fetchPackages(), 'version');
		}

		protected function fetchPackages(): array
		{
			$key = "{$this->getAppName()}.versions";
			$cache = Cache_Super_Global::spawn();
			if (false !== ($ver = $cache->get($key))) {
				return (array)$ver;
			}
			$versions = array_filter(
				(new Webapps\VersionFetcher\Github)->setVersionField('tag_name')->fetch(
					'BookStackApp/BookStack',
					function(&$item) {
						$version = $item['version'];
						if ($version[0] !== 'v' || $version === 'v24.02.1') {
							return false;
						}

						$item['version'] = substr($version, 1);
						return;
					}
				)
			);
			$cache->set($key, $versions, 43200);
			return $versions;
		}

		protected function parseInstallOptions(array &$options, string $hostname, string $path = ''): bool
		{
			if (!isset($options['user'])) {
				$options['user'] = $this->username;
			}
			info("setting admin user to `%s'", $options['user']);
			if (!isset($options['password'])) {
				info("autogenerated password `%s'",
					$options['password'] = Password::generate()
				);
			}

			return parent::parseInstallOptions($options, $hostname, $path);
		}

		private function downloadVersion(string $approot, string $version): bool
		{
			if (null === ($meta = $this->versionMeta($version))) {
				return error("Cannot locate %(app)s version %(version)s", [
					'app'     => self::APP_NAME,
					'version' => $version
				]);
			}
			$dlUrl = array_first($meta['assets'], static function ($asset) {
				return substr($asset['name'], -4) === '.zip';
			});
			$dlUrl = $dlUrl['browser_download_url'] ?? $meta['zipball_url'];
			$this->download($dlUrl, "$approot/bookstack-tmp/bookstack.zip");
			$root = $this->file_get_directory_contents("{$approot}/bookstack-tmp/")[0]['filename'];

			if (!$this->file_copy("{$approot}/bookstack-tmp/{$root}/",
					$approot) || !$this->file_delete("$approot/bookstack-tmp", true)) {
				return false;
			}

			return true;
		}

		/**
		 * Release meta
		 *
		 * @param string $version
		 * @return array|null
		 */
		private function versionMeta(string $version): ?array
		{
			return array_first($this->fetchPackages(), static function ($meta) use ($version) {
				return version_compare($meta['version'], $version, '=');
			});
		}

		protected function createProject(string $docroot, string $package, string $version, array $opts = []): bool
		{
			return $this->downloadVersion($docroot, $version);
		}

		public function get_admin(string $hostname, string $path = ''): ?string
		{
			$db = $this->db_config($hostname, $path);
			$mysql = Webapps::connectorFromCredentials($db);
			$query = "SELECT email FROM users JOIN {$db['prefix']}role_user ON ({$db['prefix']}role_user.user_id = {$db['prefix']}users.id) JOIN {$db['prefix']}roles ON ({$db['prefix']}roles.id = {$db['prefix']}role_user.role_id) WHERE {$db['prefix']}roles.system_name = 'admin' LIMIT 1";
			$rs = $mysql->query($query);
			return $rs->rowCount() === 1 ? $rs->fetchObject()->email : null;
		}

		/**
		 * @param string $hostname
		 * @param string $path
		 * @param array  $fields available option: password, user, email
		 * @return bool
		 */
		public function change_admin(string $hostname, string $path, array $fields): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return warn('failed to change administrator information');
			}
			$admin = $this->get_admin($hostname, $path);

			if (!$admin) {
				return error('cannot determine admin of install');
			}

			if (isset($fields['password'])) {
				if (!Shadow::crypted($fields['password'])) {
					if (!Password::strong($fields['password'])) {
						return error("Password is insufficient strength");
					}
					$fields['password'] = password_hash($fields['password'], CRYPT_BLOWFISH);
				} else if (!Shadow::valid_crypted($fields['password'])) {
					// error generated from fn
					return false;
				}
			}

			if (isset($fields['email']) && !preg_match(Regex::EMAIL, $fields['email'])) {
				return error("Invalid email");
			}

			if (isset($fields['user']) && !preg_match(Regex::USERNAME, $fields['user'])) {
				return error("Invalid user");
			}

			$valid = [
				'user'     => 'name',
				'email'    => 'email',
				'password' => 'password'
			];

			if ($unrecognized = array_diff_key($fields, $valid)) {
				return error("Unrecognized fields: %s", implode(array_keys($unrecognized)));
			}

			if (!$match = array_intersect_key($valid, $fields)) {
				return warn("No fields updated");
			}

			$fields = array_intersect_key($fields, $match);
			$db = $this->db_config($hostname, $path);
			$admin = $this->get_admin($hostname, $path);
			$mysql = Webapps::connectorFromCredentials($db);
			$query = "UPDATE {$db['prefix']}users SET " .
				implode(', ', array_key_map(static fn($k, $v) => $valid[$k] . ' = ' . $mysql->quote($v), $fields)) . " WHERE email = " . $mysql->quote($admin);

			$rs = $mysql->query($query);
			return $rs->rowCount() > 0 ? true : error("Failed to update admin `%(admin)s', error: %(err)s",
				['admin' => $admin, 'err' => $rs->errorInfo()]);
		}

		public function valid(string $hostname, string $path = ''): bool
		{
			if ($hostname[0] === '/') {
				if (!($path = realpath($this->domain_fs_path($hostname)))) {
					return false;
				}
				$approot = \dirname($path);
			} else {
				$approot = $this->getAppRoot($hostname, $path);
				if (!$approot) {
					return false;
				}
				$approot = $this->domain_fs_path($approot);
			}

			return file_exists($approot . '/app/Repos/BookRepo.php') || file_exists($approot . '/app/Entities/Repos/BookRepo.php');
		}
	}