<?php
namespace wcf\system\session;
use ParagonIE\ConstantTime\Hex;
use wcf\data\session\Session as LegacySession;
use wcf\data\session\SessionEditor;
use wcf\data\user\User;
use wcf\data\user\UserEditor;
use wcf\system\application\ApplicationHandler;
use wcf\system\cache\builder\SpiderCacheBuilder;
use wcf\system\cache\builder\UserGroupOptionCacheBuilder;
use wcf\system\cache\builder\UserGroupPermissionCacheBuilder;
use wcf\system\database\DatabaseException;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\event\EventHandler;
use wcf\system\exception\PermissionDeniedException;
use wcf\system\page\PageLocationManager;
use wcf\system\request\RouteHandler;
use wcf\system\user\storage\UserStorageHandler;
use wcf\system\SingletonFactory;
use wcf\system\WCF;
use wcf\system\WCFACP;
use wcf\util\CryptoUtil;
use wcf\util\HeaderUtil;
use wcf\util\UserUtil;

/**
 * Handles sessions.
 * 
 * @author	Tim Duesterhus, Alexander Ebert
 * @copyright	2001-2020 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	WoltLabSuite\Core\System\Session
 *
 * @property-read	string		$sessionID		unique textual identifier of the session
 * @property-read	integer|null	$userID			id of the user the session belongs to or `null` if the session belongs to a guest
 * @property-read	integer|null	$pageID			id of the latest page visited
 * @property-read	integer|null	$pageObjectID		id of the object the latest page visited belongs to
 * @property-read	integer|null	$parentPageID		id of the parent page of latest page visited
 * @property-read	integer|null	$parentPageObjectID	id of the object the parent page of latest page visited belongs to
 * @property-read	integer		$spiderID		id of the spider the session belongs to
 */
final class SessionHandler extends SingletonFactory {
	/**
	 * prevents update on shutdown
	 * @var	boolean
	 */
	protected $doNotUpdate = false;
	
	/**
	 * disables page tracking
	 * @var	boolean
	 */
	protected $disableTracking = false;
		
	/**
	 * group data and permissions
	 * @var	mixed[][]
	 */
	protected $groupData = null;
	
	/**
	 * true if within ACP or WCFSetup
	 * @var boolean
	 */
	protected $isACP = false;
	
	/**
	 * language id for active user
	 * @var	integer
	 */
	protected $languageID = 0;
	
	/**
	 * language ids for active user
	 * @var	integer[]
	 */
	protected $languageIDs = null;
	
	/**
	 * @var	string
	 */
	private $sessionID;
	
	/**
	 * session object
	 * @var	\wcf\data\acp\session\ACPSession
	 */
	protected $session = null;
	
	/**
	 * @var LegacySession
	 */
	protected $legacySession = null;
	
	/**
	 * style id
	 * @var	integer
	 */
	protected $styleID = null;
	
	/**
	 * user object
	 * @var	User
	 */
	protected $user = null;
	
	/**
	 * session variables
	 * @var	array
	 */
	protected $variables = [];
	
	/**
	 * indicates if session variables changed and must be saved upon shutdown
	 * @var	boolean
	 */
	protected $variablesChanged = false;
	
	/**
	 * true if this is a new session
	 * @var	boolean
	 */
	protected $firstVisit = false;
	
	/**
	 * list of names of permissions only available for users
	 * @var	string[]
	 */
	protected $usersOnlyPermissions = [];
	
	/**
	 * @var	string
	 */
	private $xsrfToken;
	
	private const ACP_SESSION_LIFETIME = 2 * 3600;
	private const GUEST_SESSION_LIFETIME = 2 * 3600;
	private const USER_SESSION_LIFETIME = 14 * 86400;
	
	private const CHANGE_USER_AFTER_MULTIFACTOR_KEY = self::class."\0__changeUserAfterMultifactor__";
	private const PENDING_USER_LIFETIME = 15 * 60;
	
	private const REAUTHENTICATION_KEY = self::class."\0__reauthentication__";
	private const REAUTHENTICATION_HARD_LIMIT = 12 * 3600;
	private const REAUTHENTICATION_SOFT_LIMIT = 2 * 3600;
	private const REAUTHENTICATION_GRACE_PERIOD = 15 * 60;
	
	/**
	 * Provides access to session data.
	 * 
	 * @param	string		$key
	 * @return	mixed
	 */
	public function __get($key) {
		switch ($key) {
			case 'sessionID':
				return $this->sessionID;
			case 'userID':
				return $this->user->userID;
			case 'spiderID':
				return $this->getSpiderID(UserUtil::getUserAgent());
			case 'pageID':
			case 'pageObjectID':
			case 'parentPageID':
			case 'parentPageObjectID':
				return $this->legacySession->{$key};

			/** @deprecated	5.4 - The below values are deprecated. */
			case 'ipAddress':
				return UserUtil::getIpAddress();
			case 'userAgent':
				return UserUtil::getUserAgent();
			case 'requestURI':
				return UserUtil::getRequestURI();
			case 'requestMethod':
				return !empty($_SERVER['REQUEST_METHOD']) ? substr($_SERVER['REQUEST_METHOD'], 0, 7) : '';
			case 'lastActivityTime':
				return TIME_NOW;
			
			default:
				return null;
		}
	}
	
	/**
	 * @inheritDoc
	 */
	protected function init() {
		$this->isACP = (class_exists(WCFACP::class, false) || !PACKAGE_ID);
		$this->usersOnlyPermissions = UserGroupOptionCacheBuilder::getInstance()->getData([], 'usersOnlyOptions');
	}
	
	/**
	 * @deprecated 5.4 - This method is a noop. The cookie suffix is determined automatically.
	 */
	public function setCookieSuffix() { }
	
	/**
	 * @deprecated 5.4 - This method is a noop. Cookie handling works automatically.
	 */
	public function setHasValidCookie($hasValidCookie) { }
	
	/**
	 * Parses the session cookie value, returning an array with the stored fields.
	 * 
	 * The return array is guaranteed to have a `sessionId` key.
	 */
	private function parseCookie(string $value): array {
		$length = \mb_strlen($value, '8bit');
		if ($length < 1) {
			throw new \InvalidArgumentException(\sprintf(
				'Expected at least 1 Byte, %d given.',
				$length
			));
		}
		
		$version = \unpack('Cversion', $value)['version'];
		if (!in_array($version, [1], true)) {
			throw new \InvalidArgumentException(\sprintf(
				'Unknown version %d',
				$version
			));
		}
		
		if ($version === 1) {
			if ($length !== 26) {
				throw new \InvalidArgumentException(\sprintf(
					'Expected exactly 26 Bytes, %d given.',
					$length
				));
			}
			$data = \unpack('Cversion/A20sessionId/Ctimestep/NuserId', $value);
			$data['sessionId'] = Hex::encode($data['sessionId']);
			
			return $data;
		}
		
		throw new \LogicException('Unreachable');
	}
	
	/**
	 * Extracts the data from the cookie identified by the `$isACP` parameter.
	 * If the `$isACP` parameter is `null` the current environment is assumed.
	 * 
	 * @see SessionHandler::parseCookie()
	 */
	private function getParsedCookieData(?bool $isACP = null): ?array {
		if ($isACP === null) {
			$isACP = $this->isACP;
		}
		
		$cookieName = COOKIE_PREFIX.($isACP ? 'acp' : 'user')."_session";
		
		if (!empty($_COOKIE[$cookieName])) {
			if (!PACKAGE_ID) {
				return [
					'sessionId' => $_COOKIE[$cookieName],
				];
			}
			
			$cookieData = CryptoUtil::getValueFromSignedString($_COOKIE[$cookieName]);
			
			// Check whether the sessionId was correctly signed.
			if (!$cookieData) {
				return null;
			}
			
			try {
				return $this->parseCookie($cookieData);
			}
			catch (\InvalidArgumentException $e) {
				return null;
			}
		}
		
		return null;
	}
	
	/**
	 * Returns the session ID stored in the session cookie or `null`.
	 */
	private function getSessionIdFromCookie(): ?string {
		$cookieData = $this->getParsedCookieData();
		
		if ($cookieData) {
			return $cookieData['sessionId'];
		}
		
		return null;
	}
	
	/**
	 * Returns the current time step. The time step changes
	 * every 6 hours.
	 */
	private function getCookieTimestep(): int {
		return floor(TIME_NOW / (6 * 3600)) & 0xFF;
	}
	
	/**
	 * Returns the signed session data for use in a cookie.
	 */
	private function getCookieValue(): string {
		if (!PACKAGE_ID) {
			return $this->sessionID;
		}
		
		return CryptoUtil::createSignedString(\pack(
			'CA20CN',
			1,
			Hex::decode($this->sessionID),
			$this->getCookieTimestep(),
			$this->user->userID ?: 0
		));
	}
	
	/**
	 * Returns true if client provided a valid session cookie.
	 * 
	 * @return	boolean
	 * @since	3.0
	 */
	public function hasValidCookie(): bool {
		return $this->getSessionIdFromCookie() === $this->sessionID;
	}
	
	/**
	 * @deprecated 5.4 - Sessions are managed automatically. Use loadFromCookie().
	 */
	public function load($sessionEditorClassName, $sessionID) {
		$hasSession = false;
		if (!empty($sessionID)) {
			$hasSession = $this->getExistingSession($sessionID);
		}
		
		// create new session
		if (!$hasSession) {
			$this->create();
		}
	}
	
	/**
	 * Loads the session matching the session cookie.
	 */
	public function loadFromCookie() {
		$sessionID = $this->getSessionIdFromCookie();
		
		$hasSession = false;
		if ($sessionID) {
			$hasSession = $this->getExistingSession($sessionID);
		}
		
		// create new session
		if (!$hasSession) {
			$this->create();
		}
	}
	
	/**
	 * Initializes session system.
	 */
	public function initSession() {
		$this->defineConstants();
		
		// assign language and style id
		$this->languageID = ($this->getVar('languageID') === null) ? $this->user->languageID : $this->getVar('languageID');
		$this->styleID = ($this->getVar('styleID') === null) ? $this->user->styleID : $this->getVar('styleID');
		
		// https://github.com/WoltLab/WCF/issues/2568
		if ($this->getVar('__wcfIsFirstVisit') === true) {
			$this->firstVisit = true;
			$this->unregister('__wcfIsFirstVisit');
		}
	}
	
	/**
	 * Disables update on shutdown.
	 */
	public function disableUpdate() {
		$this->doNotUpdate = true;
	}
	
	/**
	 * Disables page tracking.
	 */
	public function disableTracking() {
		$this->disableTracking = true;
	}
	
	/**
	 * Defines global wcf constants related to session.
	 */
	protected function defineConstants() {
		// security token
		if (!defined('SECURITY_TOKEN')) define('SECURITY_TOKEN', $this->getSecurityToken());
		if (!defined('SECURITY_TOKEN_INPUT_TAG')) define('SECURITY_TOKEN_INPUT_TAG', '<input type="hidden" name="t" value="'.$this->getSecurityToken().'">');
	}
	
	/**
	 * Initializes security token.
	 */
	protected function initSecurityToken() {
		$xsrfToken = '';
		if (!empty($_COOKIE['XSRF-TOKEN'])) {
			// We intentionally do not extract the signed value and instead just verify the correctness.
			//
			// The reason is that common JavaScript frameworks can use the contents of the `XSRF-TOKEN` cookie as-is,
			// without performing any processing on it, improving interoperability. Leveraging this JavaScript framework
			// feature requires the author of the controller to check the value within the `X-XSRF-TOKEN` request header
			// instead of the WoltLab Suite specific `t` parameter, though.
			//
			// The only reason we sign the cookie is that an XSS vulnerability or a rogue application on a subdomain
			// is not able to create a valid `XSRF-TOKEN`, e.g. by setting the `XSRF-TOKEN` cookie to the static
			// value `1234`, possibly allowing later exploitation.
			if (!PACKAGE_ID || CryptoUtil::validateSignedString($_COOKIE['XSRF-TOKEN'])) {
				$xsrfToken = $_COOKIE['XSRF-TOKEN'];
			}
		}
		
		if (!$xsrfToken) {
			if (PACKAGE_ID) {
				$xsrfToken = CryptoUtil::createSignedString(\random_bytes(16));
			}
			else {
				$xsrfToken = Hex::encode(\random_bytes(16));
			}
			
			// We construct the cookie manually instead of using HeaderUtil::setCookie(), because:
			// 1) We don't want the prefix. The `XSRF-TOKEN` cookie name is a standard name across applications
			//    and it is supported by default in common JavaScript frameworks.
			// 2) We want to set the SameSite=strict parameter.
			// 3) We don't want the HttpOnly parameter.
			$sameSite = $cookieDomain = '';
			
			if (ApplicationHandler::getInstance()->isMultiDomainSetup()) {
				// We need to specify the cookieDomain in a multi domain set-up, because
				// otherwise no cookies are sent to subdomains.
				$cookieDomain = HeaderUtil::getCookieDomain();
				$cookieDomain = ($cookieDomain !== null ? '; domain='.$cookieDomain : '');
			}
			else {
				// SameSite=strict is not supported in a multi domain set-up, because
				// it breaks cross-application requests.
				$sameSite = '; SameSite=strict';
			}
			
			\header('set-cookie: XSRF-TOKEN='.\rawurlencode($xsrfToken).'; path=/'.$cookieDomain.(RouteHandler::secureConnection() ? '; secure' : '').$sameSite, false);
		}
		
		$this->xsrfToken = $xsrfToken;
	}
	
	/**
	 * Returns security token.
	 * 
	 * @return	string
	 */
	public function getSecurityToken() {
		if ($this->xsrfToken === null) {
			$this->initSecurityToken();
		}
		
		return $this->xsrfToken;
	}
	
	/**
	 * Validates the given security token, returns false if
	 * given token is invalid.
	 * 
	 * @param	string		$token
	 * @return	boolean
	 */
	public function checkSecurityToken($token) {
		// The output of CryptoUtil::createSignedString() is not url-safe. For compatibility
		// reasons the SECURITY_TOKEN in URLs might not be encoded, turning the '+' into a space.
		// Convert it back before comparing.
		$token = \str_replace(' ', '+', $token);
		return \hash_equals($this->getSecurityToken(), $token);
	}
	
	/**
	 * Registers a session variable.
	 * 
	 * @param	string		$key
	 * @param	mixed		$value
	 */
	public function register($key, $value) {
		$this->variables[$key] = $value;
		$this->variablesChanged = true;
	}
	
	/**
	 * Unsets a session variable.
	 * 
	 * @param	string		$key
	 */
	public function unregister($key) {
		unset($this->variables[$key]);
		$this->variablesChanged = true;
	}
	
	/**
	 * Returns the value of a session variable or `null` if the session
	 * variable does not exist.
	 * 
	 * @param	string		$key
	 * @return	mixed
	 */
	public function getVar($key) {
		if (isset($this->variables[$key])) {
			return $this->variables[$key];
		}
		
		return null;
	}
	
	/**
	 * Returns the user object of this session.
	 * 
	 * @return	User	$user
	 */
	public function getUser() {
		return $this->user;
	}
	
	/**
	 * Tries to read existing session identified by the given session id. Returns whether
	 * a session could be found.
	 */
	protected function getExistingSession(string $sessionID): bool {
		$sql = "SELECT	*
			FROM	wcf".WCF_N."_".($this->isACP ? 'acp' : 'user')."_session
			WHERE	sessionID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([
			$sessionID
		]);
		$row = $statement->fetchSingleRow();
		
		if (!$row) {
			return false;
		}
		
		// Check whether the session technically already expired.
		$lifetime =
			($this->isACP   ? self::ACP_SESSION_LIFETIME  :
			($row['userID'] ? self::USER_SESSION_LIFETIME :
			(                 self::GUEST_SESSION_LIFETIME)));
		if ($row['lastActivityTime'] < (TIME_NOW - $lifetime)) {
			return false;
		}
		
		$variables = @\unserialize($row['sessionVariables']);
		// Check whether the session variables became corrupted.
		if (!\is_array($variables)) {
			return false;
		}
		
		$this->sessionID = $sessionID;
		$this->user = new User($row['userID']);
		$this->variables = $variables;
		
		$sql = "UPDATE	wcf".WCF_N."_".($this->isACP ? 'acp' : 'user')."_session
			SET	ipAddress = ?,
				userAgent = ?,
				lastActivityTime = ?
			WHERE	sessionID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([
			UserUtil::getIpAddress(),
			UserUtil::getUserAgent(),
			TIME_NOW,
			$this->sessionID,
		]);
		
		// Refresh cookie.
		if ($this->user->userID && !$this->isACP) {
			HeaderUtil::setCookie(
				($this->isACP ? 'acp' : 'user') . '_session',
				$this->getCookieValue(),
				TIME_NOW + 86400 * 14
			);
		}
		
		// Fetch legacy session.
		if (!$this->isACP) {
			$condition = new PreparedStatementConditionBuilder();
			
			if ($row['userID']) {
				// The `userID IS NOT NULL` condition technically is redundant, but is added for
				// clarity and consistency with the guest case below.
				$condition->add('userID IS NOT NULL');
				$condition->add('userID = ?', [$row['userID']]);
			}
			else {
				$condition->add('userID IS NULL');
				$condition->add('(sessionID = ? OR spiderID = ?)', [
					$row['sessionID'],
					$this->getSpiderID(UserUtil::getUserAgent()),
				]);
			}
			
			$sql = "SELECT	*
				FROM	wcf".WCF_N."_session
				".$condition;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($condition->getParameters());
			$this->legacySession = $statement->fetchSingleObject(LegacySession::class);
			
			if (!$this->legacySession) {
				$this->createLegacySession();
			}
		}
		
		return true;
	}
	
	/**
	 * Creates a new session.
	 */
	protected function create() {
		$this->sessionID = Hex::encode(\random_bytes(20));
		
		// Create new session.
		$sql = "INSERT INTO     wcf".WCF_N."_".($this->isACP ? 'acp' : 'user')."_session
			                (sessionID, ipAddress, userAgent, lastActivityTime, sessionVariables)
			VALUES          (?, ?, ?, ?, ?)";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([
			$this->sessionID,
			UserUtil::getIpAddress(),
			UserUtil::getUserAgent(),
			TIME_NOW,
			\serialize([]),
		]);
		
		$this->variables = [];
		$this->user = new User(null);
		$this->firstVisit = true;
		
		HeaderUtil::setCookie(
			($this->isACP ? 'acp' : 'user')."_session",
			$this->getCookieValue()
		);
		
		// Maintain legacy session table for users online list.
		if (!$this->isACP) {
			$this->createLegacySession();
		}
	}
	
	private function createLegacySession() {
		$spiderID = $this->getSpiderID(UserUtil::getUserAgent());
		
		// save session
		$sessionData = [
			'sessionID' => $this->sessionID,
			'userID' => $this->user->userID,
			'ipAddress' => UserUtil::getIpAddress(),
			'userAgent' => UserUtil::getUserAgent(),
			'lastActivityTime' => TIME_NOW,
			'requestURI' => UserUtil::getRequestURI(),
			'requestMethod' => !empty($_SERVER['REQUEST_METHOD']) ? substr($_SERVER['REQUEST_METHOD'], 0, 7) : ''
		];
		
		if ($spiderID !== null) $sessionData['spiderID'] = $spiderID;
		
		$this->legacySession = SessionEditor::create($sessionData);
	}
	
	/**
	 * Returns the value of the permission with the given name.
	 * 
	 * @param	string		$permission
	 * @return	mixed		permission value
	 */
	public function getPermission($permission) {
		// check if a users only permission is checked for a guest and return
		// false if that is the case
		if (!$this->user->userID && in_array($permission, $this->usersOnlyPermissions)) {
			return false;
		}
		
		$this->loadGroupData();
		
		if (!isset($this->groupData[$permission])) return false;
		return $this->groupData[$permission];
	}
	
	/**
	 * Returns true if a permission was set to 'Never'. This is required to preserve
	 * compatibility, while preventing ACLs from overruling a 'Never' setting.
	 * 
	 * @param       string          $permission
	 * @return      boolean
	 */
	public function getNeverPermission($permission) {
		$this->loadGroupData();
		
		return (isset($this->groupData['__never'][$permission]));
	}
	
	/**
	 * Checks if the active user has the given permissions and throws a
	 * PermissionDeniedException if that isn't the case.
	 * 
	 * @param	string[]	$permissions	list of permissions where each one must pass
	 * @throws	PermissionDeniedException
	 */
	public function checkPermissions(array $permissions) {
		foreach ($permissions as $permission) {
			if (!$this->getPermission($permission)) {
				throw new PermissionDeniedException();
			}
		}
	}
	
	/**
	 * Loads group data from cache.
	 */
	protected function loadGroupData() {
		if ($this->groupData !== null) return;
		
		// work-around for setup process (package wcf does not exist yet)
		if (!PACKAGE_ID) {
			$sql = "SELECT	groupID
				FROM	wcf".WCF_N."_user_to_group
				WHERE	userID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute([$this->user->userID]);
			$groupIDs = $statement->fetchAll(\PDO::FETCH_COLUMN);
		}
		else {
			$groupIDs = $this->user->getGroupIDs();
		}
		
		// get group data from cache
		$this->groupData = UserGroupPermissionCacheBuilder::getInstance()->getData($groupIDs);
		if (isset($this->groupData['groupIDs']) && $this->groupData['groupIDs'] != $groupIDs) {
			$this->groupData = [];
		}
	}
	
	/**
	 * Returns language ids for active user.
	 * 
	 * @return	integer[]
	 */
	public function getLanguageIDs() {
		$this->loadLanguageIDs();
		
		return $this->languageIDs;
	}
	
	/**
	 * Loads language ids for active user.
	 */
	protected function loadLanguageIDs() {
		if ($this->languageIDs !== null) return;
		
		$this->languageIDs = [];
		
		if (!$this->user->userID) {
			return;
		}
		
		// work-around for setup process (package wcf does not exist yet)
		if (!PACKAGE_ID) {
			$sql = "SELECT	languageID
				FROM	wcf".WCF_N."_user_to_language
				WHERE	userID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute([$this->user->userID]);
			$this->languageIDs = $statement->fetchAll(\PDO::FETCH_COLUMN);
		}
		else {
			$this->languageIDs = $this->user->getLanguageIDs();
		}
	}
	
	/**
	 * If multi-factor authentication is enabled for the given user then
	 * - the userID will be stored in the session variables, and
	 * - `true` is returned.
	 * Otherwise,
	 * - `changeUser()` will be called, and
	 * - `false` is returned.
	 * 
	 * If `true` is returned you should perform a redirect to `MultifactorAuthenticationForm`.
	 */
	public function changeUserAfterMultifactorAuthentication(User $user): bool {
		if ($user->multifactorActive) {
			$this->register(self::CHANGE_USER_AFTER_MULTIFACTOR_KEY, [
				'userId' => $user->userID,
				'expires' => TIME_NOW + self::PENDING_USER_LIFETIME,
			]);
			$this->setLanguageID($user->languageID);
			
			return true;
		}
		else {
			$this->changeUser($user);
			
			return false;
		}
	}
	
	/**
	 * Applies the pending user change, calling `changeUser()` for the user returned
	 * by `getPendingUserChange()`.
	 * 
	 * As a safety check you must provide the `$expectedUser` as a parameter, it must match the
	 * data stored within the session.
	 *
	 * @throws \RuntimeException If the `$expectedUser` does not match.
	 * @throws \BadMethodCallException If `getPendingUserChange()` returns `null`.
	 */
	public function applyPendingUserChange(User $expectedUser): void {
		$user = $this->getPendingUserChange();
		$this->clearPendingUserChange();
		
		if ($user->userID !== $expectedUser->userID) {
			throw new \RuntimeException('Mismatching expectedUser.');
		}
		
		if (!$user) {
			throw new \BadMethodCallException('No pending user change.');
		}
		
		$this->changeUser($user);
	}
	
	/**
	 * Returns the pending user change initiated by changeUserAfterMultifactor().
	 */
	public function getPendingUserChange(): ?User {
		$data = $this->getVar(self::CHANGE_USER_AFTER_MULTIFACTOR_KEY);
		if (!$data) {
			return null;
		}
		
		$userId = $data['userId'];
		$expires = $data['expires'];
		
		if ($expires < TIME_NOW) {
			return null;
		}
		
		$user = new User($userId);
		
		if (!$user->userID) {
			return null;
		}
		
		return $user;
	}
	
	/**
	 * Clears a pending user change, reverses the effects of changeUserAfterMultifactor().
	 */
	public function clearPendingUserChange(): void {
		$this->unregister(self::CHANGE_USER_AFTER_MULTIFACTOR_KEY);
	}
	
	/**
	 * Stores a new user object in this session, e.g. a user was guest because not
	 * logged in, after the login his old session is used to store his full data.
	 * 
	 * @param	User		$user
	 * @param	boolean		$hideSession	if true, database won't be updated
	 */
	public function changeUser(User $user, $hideSession = false) {
		$eventParameters = ['user' => $user, 'hideSession' => $hideSession];
		
		EventHandler::getInstance()->fireAction($this, 'beforeChangeUser', $eventParameters);
		
		$user = $eventParameters['user'];
		$hideSession = $eventParameters['hideSession'];
		
		// skip changeUserVirtual, if session will not be persistent anyway
		if (!$hideSession) {
			$this->changeUserVirtual($user);
		}
		
		// update user reference
		$this->user = $user;
		
		// reset caches
		$this->groupData = null;
		$this->languageIDs = null;
		$this->languageID = $this->user->languageID;
		$this->styleID = $this->user->styleID;
		
		// change language
		WCF::setLanguage($this->languageID ?: 0);
		
		// in some cases the language id can be stuck in the session variables
		$this->unregister('languageID');
		
		EventHandler::getInstance()->fireAction($this, 'afterChangeUser');
	}
	
	/**
	 * Changes the user stored in the session.
	 * 
	 * @param	User	$user
	 * @throws	DatabaseException
	 */
	protected function changeUserVirtual(User $user) {
		// We must delete the old session to not carry over any state across different users.
		$this->delete();
		
		// If the target user is a registered user ...
		if ($user->userID) {
			// ... we create a new session with a new session ID ...
			$this->create();
			
			// ... delete the newly created legacy session ...
			if (!$this->isACP) {
				$sql = "DELETE FROM wcf".WCF_N."_session
					WHERE	sessionID = ?";
				$statement = WCF::getDB()->prepareStatement($sql);
				$statement->execute([$this->sessionID]);
			}
			
			// ... perform the login ...
			$sql = "UPDATE	wcf".WCF_N."_".($this->isACP ? 'acp' : 'user')."_session
				SET	userID = ?
				WHERE	sessionID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute([
				$user->userID,
				$this->sessionID,
			]);
			
			// ... and reload the session with the updated information.
			$hasSession = $this->getExistingSession($this->sessionID);
			
			if (!$hasSession) {
				throw new \LogicException('Unreachable');
			}
		}
	}
	
	/**
	 * Checks whether the user needs to authenticate themselves once again
	 * to access a security critical area.
	 * 
	 * If `true` is returned you should perform a redirect to `ReAuthenticationForm`,
	 * otherwise the user is sufficiently authenticated and may proceed.
	 *
	 * @throws \BadMethodCallException If the current user is a guest.
	 */
	public function needsReauthentication(): bool {
		if (!$this->getUser()->userID) {
			throw new \BadMethodCallException('The current user is a guest.');
		}
		
		// Reauthentication for third party authentication is not supported.
		if ($this->getUser()->authData) {
			return false;
		}
		
		$data = $this->getVar(self::REAUTHENTICATION_KEY);
		
		// Request a new authentication if no stored information is available.
		if (!$data) {
			return true;
		}
		
		$lastAuthentication = $data['lastAuthentication'];
		$lastCheck = $data['lastCheck'];
		
		// Request a new authentication if the hard limit since the last authentication
		// is exceeded.
		if ($lastAuthentication < (TIME_NOW - self::REAUTHENTICATION_HARD_LIMIT)) {
			return true;
		}
		
		// Request a new authentication if the soft limit since the last authentication
		// is exceeded ...
		if ($lastAuthentication < (TIME_NOW - self::REAUTHENTICATION_SOFT_LIMIT)) {
			// ... and the grace period since the last check is also exceeded.
			if ($lastCheck < (TIME_NOW - self::REAUTHENTICATION_GRACE_PERIOD)) {
				return true;
			}
		}
		
		// If we reach this point we determined that a new authentication is not necessary.
		\assert(
			($lastAuthentication >= TIME_NOW - self::REAUTHENTICATION_SOFT_LIMIT) ||
			($lastAuthentication >= TIME_NOW - self::REAUTHENTICATION_HARD_LIMIT &&
				$lastCheck >= TIME_NOW - self::REAUTHENTICATION_GRACE_PERIOD)
		);
		
		// Update the lastCheck timestamp to make sure that the grace period works properly.
		// 
		// The grace period allows the user to complete their action if the soft limit
		// expires between loading a form and actually submitting that form, provided that
		// the user does not take longer than the grace period to fill in the form.
		$data['lastCheck'] = TIME_NOW;
		$this->register(self::REAUTHENTICATION_KEY, $data);
		
		return false;
	}
	
	/**
	 * Registers that the user performed reauthentication successfully.
	 * 
	 * This method should be considered to be semi-public and is intended to be used
	 * by `ReAuthenticationForm` only.
	 * 
	 * @throws \BadMethodCallException If the current user is a guest.
	 */
	public function registerReauthentication(): void {
		if (!$this->getUser()->userID) {
			throw new \BadMethodCallException('The current user is a guest.');
		}
		
		$this->register(self::REAUTHENTICATION_KEY, [
			'lastAuthentication' => TIME_NOW,
			'lastCheck' => TIME_NOW,
		]);
	}
	
	/**
	 * Updates user session on shutdown.
	 */
	public function update() {
		if ($this->doNotUpdate) return;
		
		if ($this->variablesChanged) {
			$sql = "UPDATE	wcf".WCF_N."_".($this->isACP ? 'acp' : 'user')."_session
				SET	sessionVariables = ?
				WHERE	sessionID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute([
				\serialize($this->variables),
				$this->sessionID,
			]);
			
			// Reset the flag, because the variables are no longer dirty.
			$this->variablesChanged = false;
		}
		
		if (!$this->isACP) {
			$data = [
				'ipAddress' => $this->ipAddress,
				'userAgent' => $this->userAgent,
				'requestURI' => $this->requestURI,
				'requestMethod' => $this->requestMethod,
				'lastActivityTime' => TIME_NOW,
				'userID' => $this->user->userID,
				'sessionID' => $this->sessionID,
			];
			if (!\class_exists('wcf\system\CLIWCF', false) && !$this->disableTracking) {
				$pageLocations = PageLocationManager::getInstance()->getLocations();
				if (isset($pageLocations[0])) {
					$data['pageID'] = $pageLocations[0]['pageID'];
					$data['pageObjectID'] = ($pageLocations[0]['pageObjectID'] ?: null);
					$data['parentPageID'] = null;
					$data['parentPageObjectID'] = null;
					
					for ($i = 1, $length = count($pageLocations); $i < $length; $i++) {
						if (!empty($pageLocations[$i]['useAsParentLocation'])) {
							$data['parentPageID'] = $pageLocations[$i]['pageID'];
							$data['parentPageObjectID'] = ($pageLocations[$i]['pageObjectID'] ?: null);
							break;
						}
					}
				}
			}
			
			if ($this->legacySession) {
				$sessionEditor = new SessionEditor($this->legacySession);
				$sessionEditor->update($data);
			}
		}
	}
	
	/**
	 * @deprecated 5.4 - This method is a noop. The lastActivityTime is always updated immediately after loading.
	 */
	public function keepAlive() { }
	
	/**
	 * Deletes this session and its related data.
	 */
	public function delete() {
		// clear storage
		if ($this->user->userID) {
			self::resetSessions([$this->user->userID]);
			
			// update last activity time
			if (!$this->isACP) {
				$editor = new UserEditor($this->user);
				$editor->update(['lastActivityTime' => TIME_NOW]);
			}
		}
		
		if ($this->isACP) {
			$this->deleteAcpSession($this->sessionID);
		}
		else {
			$this->deleteUserSession($this->sessionID);
		}
	}
	
	/**
	 * Prunes expired sessions.
	 */
	public function prune() {
		// Prevent the sessions from expiring while the development mode is active.
		if (!ENABLE_DEBUG_MODE || !ENABLE_DEVELOPER_TOOLS) {
			$sql = "DELETE FROM	wcf".WCF_N."_user_session
				WHERE		lastActivityTime < ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute([
				TIME_NOW - self::ACP_SESSION_LIFETIME,
			]);
		}
		
		$sql = "DELETE FROM	wcf".WCF_N."_user_session
			WHERE		(lastActivityTime < ? AND userID IS NULL)
				OR	(lastActivityTime < ? AND userID IS NOT NULL)";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([
			TIME_NOW - self::GUEST_SESSION_LIFETIME,
			TIME_NOW - self::USER_SESSION_LIFETIME,
		]);
		
		// Legacy sessions live 120 minutes, they will be re-created on demand.
		$sql = "DELETE FROM	wcf".WCF_N."_session
			WHERE		lastActivityTime < ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([
			TIME_NOW - (3600 * 2),
		]);
	}
	
	/**
	 * Deletes this session if:
	 * - it is newly created in this request, and
	 * - it belongs to a guest.
	 * 
	 * This method is useful if you have controllers that are likely to be
	 * accessed by a user agent that is not going to re-use sessions (e.g.
	 * curl in a cronjob). It immediately remove the session that was created
	 * just for that request and that is not going to be used ever again.
	 * 
	 * @since 5.2
	 */
	public function deleteIfNew() {
		if ($this->isFirstVisit() && !$this->getUser()->userID) {
			$this->delete();
		}
	}
	
	/**
	 * Returns currently active language id.
	 * 
	 * @return	integer
	 */
	public function getLanguageID() {
		return $this->languageID;
	}
	
	/**
	 * Sets the currently active language id.
	 * 
	 * @param	integer		$languageID
	 */
	public function setLanguageID($languageID) {
		$this->languageID = $languageID;
		$this->register('languageID', $this->languageID);
	}
	
	/**
	 * Returns currently active style id.
	 * 
	 * @return	integer
	 */
	public function getStyleID() {
		return $this->styleID;
	}
	
	/**
	 * Sets the currently active style id.
	 * 
	 * @param	integer		$styleID
	 */
	public function setStyleID($styleID) {
		$this->styleID = $styleID;
		$this->register('styleID', $this->styleID);
	}
	
	/**
	 * Resets session-specific storage data.
	 * 
	 * @param	integer[]	$userIDs
	 */
	public static function resetSessions(array $userIDs = []) {
		if (!empty($userIDs)) {
			UserStorageHandler::getInstance()->reset($userIDs, 'groupIDs');
			UserStorageHandler::getInstance()->reset($userIDs, 'languageIDs');
		}
		else {
			UserStorageHandler::getInstance()->resetAll('groupIDs');
			UserStorageHandler::getInstance()->resetAll('languageIDs');
		}
	}
	
	/**
	 * Returns the spider id for given user agent.
	 * 
	 * @param	string		$userAgent
	 * @return	mixed
	 */
	protected function getSpiderID($userAgent) {
		$spiderList = SpiderCacheBuilder::getInstance()->getData();
		$userAgent = strtolower($userAgent);
		
		foreach ($spiderList as $spider) {
			if (strpos($userAgent, $spider->spiderIdentifier) !== false) {
				return $spider->spiderID;
			}
		}
		
		return null;
	}
	
	/**
	 * Returns true if this is a new session.
	 * 
	 * @return	boolean
	 */
	public function isFirstVisit() {
		return $this->firstVisit;
	}
	
	/**
	 * Returns all user sessions for a specific user.
	 *
	 * @return      Session[]
	 * @throws      \InvalidArgumentException if the given user is a guest.
	 * @since       5.4
	 */
	public function getUserSessions(User $user): array {
		return $this->getSessions($user, false);
	}
	
	/**
	 * Returns all acp sessions for a specific user.
	 * 
	 * @return      Session[]
	 * @throws      \InvalidArgumentException if the given user is a guest.
	 * @since       5.4
	 */
	public function getAcpSessions(User $user): array {
		return $this->getSessions($user, true);
	}
	
	/**
	 * Returns all sessions for a specific user.
	 * 
	 * @return      Session[]
	 * @throws      \InvalidArgumentException if the given user is a guest.
	 * @since       5.4
	 */
	private function getSessions(User $user, bool $isAcp): array {
		if (!$user->userID) {
			throw new \InvalidArgumentException("The given user is a guest.");
		}
		
		$sql = "SELECT          *
			FROM            wcf".WCF_N."_". ($isAcp ? 'acp' : 'user') ."_session
			WHERE           userID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([$user->userID]);
		
		$sessions = [];
		while ($row = $statement->fetchArray()) {
			$sessions[] = new Session($row, $isAcp);
		}
		
		return $sessions;
	}
	
	/**
	 * Deletes the user sessions for a specific user, except the session with the given session id.
	 * 
	 * If the given session id is `null` or unknown, all sessions of the user will be deleted.
	 * 
	 * @throws      \InvalidArgumentException if the given user is a guest.
	 * @since       5.4
	 */
	public function deleteUserSessionsExcept(User $user, ?string $sessionID = null): void {
		$this->deleteSessionsExcept($user, $sessionID, false);
	}
	
	/**
	 * Deletes the acp sessions for a specific user, except the session with the given session id.
	 * 
	 * If the given session id is `null` or unknown, all acp sessions of the user will be deleted.
	 * 
	 * @throws      \InvalidArgumentException if the given user is a guest.
	 * @since       5.4
	 */
	public function deleteAcpSessionsExcept(User $user, ?string $sessionID = null): void {
		$this->deleteSessionsExcept($user, $sessionID, true);
	}
	
	/**
	 * Deletes the sessions for a specific user, except the session with the given session id.
	 *
	 * If the given session id is `null` or unknown, all acp sessions of the user will be deleted.
	 *
	 * @throws      \InvalidArgumentException if the given user is a guest.
	 * @since       5.4
	 */
	private function deleteSessionsExcept(User $user, ?string $sessionID, bool $isAcp): void {
		if (!$user->userID) {
			throw new \InvalidArgumentException("The given user is a guest.");
		}
		
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('userID = ?', [$user->userID]);
		
		if ($sessionID !== null) {
			$conditionBuilder->add('sessionID <> ?', [$sessionID]);
		}
		
		$sql = "DELETE FROM     wcf".WCF_N."_". ($isAcp ? 'acp' : 'user') ."_session
			". $conditionBuilder;
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		
		if (!$isAcp) {
			// Delete legacy session.
			$sql = "DELETE FROM     wcf".WCF_N."_session
			". $conditionBuilder;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
		}
	}
	
	/**
	 * Deletes a user session with the given session ID.
	 * 
	 * @since       5.4
	 */
	public function deleteUserSession(string $sessionID): void {
		$this->deleteSession($sessionID, false);
	}
	
	/**
	 * Deletes an acp session with the given session ID.
	 * 
	 * @since       5.4
	 */
	public function deleteAcpSession(string $sessionID): void {
		$this->deleteSession($sessionID, true);
	}
	
	/**
	 * Deletes a session with the given session ID.
	 *
	 * @since       5.4
	 */
	private function deleteSession(string $sessionID, bool $isAcp): void {
		$sql = "DELETE FROM     wcf".WCF_N."_". ($isAcp ? 'acp' : 'user') ."_session
			WHERE	        sessionID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute([$sessionID]);
		
		if (!$isAcp) {
			// Delete legacy session.
			$sql = "DELETE FROM     wcf".WCF_N."_session
				WHERE	        sessionID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute([$sessionID]);
		}   
	}
}
