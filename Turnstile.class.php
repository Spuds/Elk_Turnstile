<?php

/**
 * Function icv_turnstile
 *
 * This function is used to add 'Turnstile' verification to the list of known verifications
 * and loads the 'Turnstile' language file.  It is called by hook integrate_control_verification
 *
 * @param array $known_verifications The list of known verifications
 *
 * @return void
 */
function icv_turnstile(&$known_verifications)
{
	// Make sure it is not already there.
	$key = array_search('Turnstile', $known_verifications, true);
	if ($key !== false)
	{
		unset($known_verifications[$key]);
	}

	// Add our verification method.  Requires class name of Verification_Controls_Turnstile
	$known_verifications[] = 'Turnstile';
	loadLanguage('Turnstile');
}

/**
 * Class Turnstile
 */
class Verification_Controls_Turnstile implements Verification_Controls
{
	/** @var array Holds the $verificationOptions passed to the constructor */
	private $_options;

	/** @var null|string turnstile site key */
	private $_site_key;

	/** @var null|string turnstile secret key */
	private $_secret_key;

	/** @var string */
	private $_siteVerifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Turnstile constructor.
	 *
	 * @param null|array $verificationOptions
	 */
	public function __construct($verificationOptions = null)
	{
		global $modSettings;

		$this->_site_key = empty($modSettings['turnstile_site_key']) ? '' : $modSettings['turnstile_site_key'];
		$this->_secret_key = empty($modSettings['turnstile_secret_key']) ? '' : $modSettings['turnstile_secret_key'];

		if (!empty($verificationOptions))
		{
			$this->_options = $verificationOptions;
		}
	}

	/**
	 * Show the verification if its enabled
	 *
	 * @param bool $isNew
	 * @param bool $force_refresh
	 *
	 * @return bool
	 * @throws Elk_Exception
	 */
	public function showVerification($isNew, $force_refresh = true)
	{
		global $modSettings, $context;

		$show_captcha = !empty($modSettings['turnstile_enable']) && !empty($this->_site_key) && !empty($this->_secret_key);

		if ($show_captcha)
		{
			// Language parameter
			$lang = !empty($modSettings['turnstile_language']) ? $modSettings['turnstile_language'] : 'auto';

			loadTemplate('Turnstile');
			loadTemplate('VerificationControls');

			if (!isset($context['html_headers']))
			{
				$context['html_headers'] = '';
			}

			// This needs a true defer, 1.1 does not support that via loadjavascriptfile()
			$context['html_headers'] .= '
	<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=_turnstileCb" defer></script>';

			addInlineJavascript('
			function _turnstileCb() {
				turnstile.render("#TurnstileControl", {
					sitekey: "' . $this->_site_key . '",
					theme: "light",
					language: "' . $lang . '",
					action: "register"
				});
			};');
		}

		return $show_captcha;
	}

	/**
	 * Done by the JS script
	 *
	 * @param bool $refresh
	 */
	public function createTest($refresh = true)
	{
		// Done by the JS which will $POST the results
	}

	/**
	 * Return an array that will be used in VerificationControls.template
	 *
	 * @return array
	 */
	public function prepareContext()
	{
		return [
			'template' => 'Turnstile',
			'values' => [
				'site_key' => $this->_site_key,
			]
		];
	}

	/**
	 * Run the test, return the result
	 *
	 * @return bool|string
	 */
	public function doTest()
	{
		if (!isset($_POST['cf-turnstile-response']) || empty(trim($_POST['cf-turnstile-response'])))
		{
			return 'wrong_captcha_verification';
		}

		$resp = $this->verifyResponse($_POST['cf-turnstile-response']);
		if ($resp['success'] === true)
		{
			return true;
		}

		return $resp['errorCodes'][0] ?? 'wrong_captcha_verification';
	}

	/**
	 * Visible form? you bet it is
	 *
	 * @return bool
	 */
	public function hasVisibleTemplate()
	{
		return true;
	}

	/**
	 * Settings for the ACP
	 *
	 * @return array
	 */
	public function settings()
	{
		global $txt;

		// Visual verification.
		return [
			['title', 'turnstile_verification'],
			['desc', 'turnstile_desc'],
			['check', 'turnstile_enable'],
			['text', 'turnstile_site_key', 40],
			['text', 'turnstile_secret_key', 40],
			['text', 'turnstile_language', 6, 'postinput' => $txt['turnstile_language_desc']],
		];
	}

	/**
	 * Calls the Turnstile API to verify whether the user passed the test.
	 *
	 * @param string $response response string from captcha verification.
	 */
	public function verifyResponse($response)
	{
		global $user_info;

		$turnstileResponse = [];
		$turnstileResponse['success'] = false;

		// Discard empty solution submissions
		if (empty($response))
		{
			$turnstileResponse['errorCodes'] = 'missing-input';

			return $turnstileResponse;
		}

		$getResponse = $this->_submitHTTPPost(
			[
				'secret' => $this->_secret_key,
				'remoteip' => $user_info['ip'],
				'response' => $response
			]
		);

		if ($getResponse === false)
		{
			$turnstileResponse['errorCodes'] = 'failed-verification';

			return $turnstileResponse;
		}

		$answers = json_decode($getResponse, true);

		if (isset($answers) && $answers['success'] === true)
		{
			$turnstileResponse['success'] = true;
		}
		else
		{
			$turnstileResponse['errorCodes'] = $answers['error-codes'];
		}

		return $turnstileResponse;
	}

	/**
	 * Submits an HTTP POST to a Turnstile server.
	 *
	 * @param array $data array of parameters to be sent.
	 */
	private function _submitHTTPPost($data)
	{
		require_once(SUBSDIR . '/Package.subs.php');

		$req = [];
		foreach ($data as $key => $value)
		{
			$req[] = $key . '=' . $value;
		}

		$req = implode('&', $req);

		return fetch_web_data($this->_siteVerifyUrl, $req);
	}
}
