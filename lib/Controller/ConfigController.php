<?php
/**
 * Nextcloud - mastodon
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Mastodon\Controller;

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Mastodon\Service\MastodonAPIService;
use OCA\Mastodon\AppInfo\Application;

class ConfigController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var IL10N
	 */
	private $l;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var MastodonAPIService
	 */
	private $mastodonAPIService;
	/**
	 * @var string|null
	 */
	private $userId;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IL10N $l,
								LoggerInterface $logger,
								MastodonAPIService $mastodonAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->appName = $appName;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->l = $l;
		$this->logger = $logger;
		$this->mastodonAPIService = $mastodonAPIService;
		$this->userId = $userId;
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}

		if (isset($values['token']) && $values['token'] === '') {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'client_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'client_secret', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'instance_image_hostname', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'instance_contact_image_hostname', '');
		}

		return new DataResponse(1);
	}

	/**
	 * receive oauth code and get oauth access token
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $code
	 * @return RedirectResponse
	 */
	public function oauthRedirect(string $code = ''): RedirectResponse {
		$mastodonUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');
		$clientID = $this->config->getUserValue($this->userId, Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getUserValue($this->userId, Application::APP_ID, 'client_secret');
		$redirect_uri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');

		if ($mastodonUrl !== '' && $clientID !== '' && $clientSecret !== '' && $code !== '') {
			$result = $this->mastodonAPIService->requestOAuthAccessToken($mastodonUrl, [
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code',
				'scope' => 'read write follow'
			], 'POST');
			if (isset($result['access_token'])) {
				$accessToken = $result['access_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				// get user info accounts/verify_credentials
				$info = $this->mastodonAPIService->request($mastodonUrl, $accessToken, 'accounts/verify_credentials');
				if (isset($info['id'], $info['username'], $info['avatar'])) {
					$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $info['id']);
					$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['username']);
					$aUrl = parse_url($info['avatar']);
					$instanceImageHostname = $aUrl['host'] ?? '';
					$this->config->setUserValue($this->userId, Application::APP_ID, 'instance_image_hostname', $instanceImageHostname);
					$instanceInfo = $this->mastodonAPIService->request($mastodonUrl, $accessToken, 'instance');
					if (isset($instanceInfo['contact_account'], $instanceInfo['contact_account']['avatar'])) {
						$aUrl = parse_url($instanceInfo['contact_account']['avatar']);
						$instanceContactImageHostname = $aUrl['host'] ?? '';
						$this->config->setUserValue($this->userId, Application::APP_ID, 'instance_contact_image_hostname', $instanceContactImageHostname);
					}
				}
				return new RedirectResponse(
					$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
					'?mastodonToken=success'
				);
			} else {
				$warning = 'Mastodon OAuth get token error : code=' . $code . ' ; url=' . $mastodonUrl
					. ' ; clientId=' . $clientID . ' ; clientSecret=' . $clientSecret . ' ; redirect_uri=' . $redirect_uri;
				$this->logger->warning($warning, ['app' => $this->appName]);
				$result = $this->l->t('Error getting OAuth access token') . ' ' . ($result['error'] ?? 'undefined token');
			}
		} else {
			$warning = 'Mastodon OAuth redirect error : code=' . $code . ' ; url=' . $mastodonUrl
				. ' ; clientId=' . $clientID . ' ; clientSecret=' . $clientSecret . ' ; redirect_uri=' . $redirect_uri;
			$this->logger->warning($warning, ['app' => $this->appName]);
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
			'?mastodonToken=error&message=' . urlencode($result)
		);
	}
}
