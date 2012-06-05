<?php

namespace CommunigateApi;

use CommunigateApi\ApiException;

/**
 * CommunigateApi API
 *
 * Basic, ham-fisted API for CommunigateApi MailServer. Refactored out Mitchell's original
 * base framework class. Adding basic tests.
 *
 * @author Shaun Mitchell
 * @author Tyler Rooney
 * @since January 17, 2005
 * @updated June 5, 2012
 */
class Api {

	/** Commands that this object can run */
	const API_COMMAND_USER = 'USER ';
	const API_COMMAND_PASS = 'PASS ';
	const API_LIST_DOMAINS = 'ListDomains';
	const API_LIST_ACCOUNTS = 'ListAccounts $$';
	const API_GET_ACCOUNT_RULES = 'GetAccountRules $$';
	const API_GET_ACCOUNT_SETTINGS = 'GetAccountSettings $$';
	const API_UPDATE_ACCOUNT_SETTINGS = 'UpdateAccountSettings $account$ $setting$';
	const API_CREATE_ACCOUNT = 'CreateAccount $name$$domain$ {Password = "$password$";}';
	const API_DELETE_ACCOUNT = 'DeleteAccount $$';
	const API_RESET_PASSWORD = 'SetAccountPassword $account$ To "$password$"';
	const API_RENAME_ACCOUNT = 'RenameAccount $old_account$ into $new_account$';
	const API_LIST_FORWARDER = 'ListForwarders $$';
	const API_GET_FORWARDER = 'GetForwarder $forwarder$$domain$';
	const API_GET_ACCOUNT_INFO = 'GetAccountInfo $account$$domain$';
	const API_GET_ACCOUNT_EFF_SETTINGS = 'GetAccountEffectiveSettings $account$$domain$';
	const API_GET_CONTROLLER = 'GetCurrentController';

	/** Rules structures */
	const API_VACATION_STRUCT = '( 2, "#Vacation", (("Human Generated", "---"), (From, "not in", "#RepliedAddresses")), ( ("Reply with", "$$"), ("Remember \'From\' in", RepliedAddresses) ) )';
	const API_EMAIL_REDIRECT_STRUCT = '( 1, "#Redirect", (), (("Mirror to", "$$"), (Discard, "---")) )';

	/**
	 * @var array Connection configuration
	 */
	private $config;

	/**
	 * Socket pointer
	 *
	 * @var socket
	 */
	public $socket;

	/**
	 * @var boolean Is connected?
	 */
	private $connected;

	/**
	 * Cached request responses
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Output
	 *
	 * The output from any command
	 *
	 * @var String
	 */
	public $output;

	/**
	 * Success?
	 *
	 * @var boolean
	 */
	private $success;

	/**
	 * @var \Monolog\Logger
	 */
	private $logger;


	/**
	 * Non critical errors
	 *
	 * These errors are non critical errors from the CLI
	 *
	 * 200 = OK
	 * 300 = Expecting more input
	 * 520 = Account name already exists
	 * 500 = Unknown command
	 * 512 = Unknown secondary domain name
	 * 513 = Unkown user account
	 *
	 * @var Array
	 */
	private $CGC_KNOWN_RESPONSES = Array(
		200 => 'OK',
		201 => 'OK (inline)',
		300 => 'Expecting more input',
		500 => 'failed to read an unquoted string: non a/n data',
		512 => 'Unknown secondary domain name',
		520 => 'Account name already exists',
		513 => 'Unknown user account'
	);

	/**
	 * Connect to API server
	 *
	 * This method will attempt to make a connection to the CommuniGate API server.
	 */
	public function connect($options = array()) {

		$defaults = array(
			'host' => '127.0.0.1',
			'login' => null,
			'password' => null,
			'port' => 106,
			'timeout' => 25,
		);

		if ($options) {
			$this->config = $options + $defaults;
		}

		$this->config = $this->config + $defaults;

		$host = $this->config['host'];
		$port = $this->config['port'];
		$login = $this->config['login'];
		$password = $this->config['password'];
		$timeout = $this->config['timeout'];


		$this->socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

		if (!$this->socket) {
			throw new ApiException("Failed to connect to CommunigateApi at {$host}:{$port}");
		}

		$this->connected = true;
		fgets($this->socket); // chomp welcome string

		$this->sendAndParse(self::API_COMMAND_USER . $login);

		$this->sendAndParse(self::API_COMMAND_PASS . $password);

		/** Set the CLI response to "INLINE", faster repsonse time */
		$this->sendAndParse('INLINE');

		return true;

	}

	public function disconnect() {
		if ($this->socket) {
			fputs($this->socket, 'QUIT' . chr(10));
			fread($this->socket, 1024);
			fclose($this->socket);
			$this->socket = NULL;
		}
		$this->connected = false;
	}

	/**
	 * Class CommuniGate API
	 *
	 * This method is the constructor. It is called when the object is created. It doens't do much
	 * but set the debugging properties of the object.
	 */
	public function __construct(array $options) {

		if (array_key_exists('logger', $options)) {
			$this->logger = $options['logger'];
			unset($options['logger']);
		}

		$this->config = $options;
	}

	/**
	 * Get domains
	 *
	 * Returns a list of domains
	 */
	public function get_domains() {
		
		$this->sendAndParse(self::API_LIST_DOMAINS);

		return $this->success ? $this->output : array();
	}

	/**
	 * Get forwarders
	 *
	 * This method will return a list of all the forwarders for a domain. It
	 * will then get the email address that is being forwarded to.
	 *
	 * @param string $domain
	 * @param bool $just_check
	 * @return array|bool
	 */
	public function get_forwarders($domain, $just_check = FALSE) {

		$this->sendAndParse(str_replace('$$', $domain, self::API_LIST_FORWARDER));

		$forwarders = $this->output;
		$forwader_array = Array();

		if ($just_check && $forwarders) {
			$this->success = TRUE;

			return $this->success;
		}

		if ($forwarders != NULL) {

			foreach ($forwarders as $forwarder) {
				
				$this->send(str_replace('$domain$', '@' . $domain, str_replace('$forwarder$', $forwarder, self::API_GET_FORWARDER)));
				
				$this->parse_response(fgets($this->socket));

				/** Add email forwader to array */
				$forwader_array[$forwarder] = $this->output[0];
			}
		}

		/** If there were forwarders then set success and return array */
		if (count($forwader_array) > 0) {
			$this->success = TRUE;

			return $forwader_array;
		}

		/** Default return false */
		return $this->success = FALSE;
	}

	/**
	 * Get accounts
	 *
	 * This method will return the account list for a domain.
	 *
	 * @var String $domain The domain name to get the account listing for
	 * @return mixed
	 */
	public function get_accounts($domain) {

		$response = $this->send(str_replace('$$', $domain, self::API_LIST_ACCOUNTS));
		$this->parse_response($response);

		$accounts = substr($response, 5, -3); // Chop off the 200 { whatever }
		$accounts = preg_replace('/=.*?;/',';', $accounts);
		$accounts = explode(';', $accounts);

		return $accounts;
	}

	/**
	 * Get account details
	 *
	 * This method will return the details of a single account. The account
	 * details is something like; ExternalINBOX = No, Password = 1234, Rules ={....}
	 *
	 * @param $domain
	 * @param $account
	 * @return String
	 */
	private function get_account_details($domain, $account) {
		$this->sendAndParse(str_replace('$$', $account . '@' . $domain, self::API_GET_ACCOUNT_SETTINGS));

		return $this->success ? $this->output : null;
	}

	/**
	 * Get account rules
	 *
	 * This method will return the rules of a single account.
	 *
	 * @param $domain
	 * @param $account
	 * @return String
	 */
	private function get_account_rules($domain, $account) {
		$this->sendAndParse(str_replace('$$', $account . '@' . $domain, self::API_GET_ACCOUNT_RULES));

		return $this->success ? $this->output : null;
	}

	public function get_account_password($domain, $account) {
		$output = $this->get_account_details($domain, $account);

		$password = null;

		foreach ($output as $value) {
			if (preg_match('/^Password=(.+)/', $value, $matches)) {
				$password = isset($matches[1]) ? $matches[1] : null;
			}
		}
		return $password;
	}
	/**
	 * Get account storage
	 *
	 * This method will get an accounts max storage allowed and used.
	 *
	 * @param $domain
	 * @param $account
	 * @return array
	 */
	public function get_account_storage($domain, $account) {

		$this->sendAndParse(str_replace('$account$', $account, str_replace('$domain$', '@' . $domain, self::API_GET_ACCOUNT_INFO)));

		/** Store the output in local variable */
		$output = $this->output;

		$this->sendAndParse(str_replace('$account$', $account, str_replace('$domain$', '@' . $domain, self::API_GET_ACCOUNT_EFF_SETTINGS)));

		/** Combine the two outputs */
		$this->output = array_merge($output, $this->output);

		$storage_used = 0;
		$max = 0;

		/** Loop through the output to get the storage and maxstorage values */
		foreach ($this->output as $value) {
			if (preg_match('/^storageused/i', $value)) {
				$storage_used = substr($value, strpos($value, '=') + 1);
			}

			if (preg_match('/^maxaccountsize/i', $value)) {
				$max = substr($value, strpos($value, '=') + 1);
			}
		}

		if (preg_match('/M/', $max)) {
			/** convert to megabytes from bytes */
			$storage_used = round(($storage_used / 1024) / 1024, 2);
		}

		$this->success = TRUE;

		return Array('max' => (int) $max, 'used' => (int) $storage_used);
	}

	/**
	 * Delete account
	 *
	 * This method will delete the account
	 *
	 * @param $domain
	 * @param $account
	 * @return bool
	 */
	public function delete_account($domain, $account) {

		$email = "{$account}@{$domain}";

		$this->sendAndParse(str_replace('$$', $email, self::API_DELETE_ACCOUNT));

		return true;
	}

	/**
	 * Create account
	 *
	 * This method will create an account
	 *
	 * @param $domain
	 * @param $account
	 * @param $password
	 * @return bool
	 * @throws ApiException
	 */
	public function create_account($domain, $account, $password) {

		/** Make sure that account name is in correct format */
		if (!preg_match('/^[_a-z0-9-]+(\.[_a-z0-9-]+)*$/', $account)) {
			throw new ApiException('Invalid account name');
		}

		/** Create the command */
		$command = str_replace('$domain$', $domain, self::API_CREATE_ACCOUNT);
		$command = str_replace('$name$', $account . '@', $command);
		$command = str_replace('$password$', $password, $command);

		$this->sendAndParse($command);

		return true;
	}

	/**
	 * Reset password
	 *
	 * This method will reset an accounts password
	 *
	 * @param $domain
	 * @param $account
	 * @param $password
	 * @return bool
	 */
	public function reset_password($domain, $account, $password) {
		
		/** Create the command */
		$command = str_replace('$account$', $account . '@' . $domain, self::API_RESET_PASSWORD);
		$command = str_replace('$password$', $password, $command);

		$this->sendAndParse($command);
		$this->clearCache();

		return true;
	}

	/**
	 * Rename Account
	 *
	 * This method will rename an account.
	 *
	 * @param $domain
	 * @param $account
	 * @param $new_name
	 * @return bool
	 */
	public function rename_account($domain, $account, $new_name) {
		
		/** Create the command */
		$command = str_replace('$old_account$', $account . '@' . $domain, self::API_RENAME_ACCOUNT);
		$command = str_replace('$new_account$', $new_name . '@' . $domain, $command);

		$this->sendAndParse($command);

		return $this->success;
	}

	/**
	 * Get rule
	 *
	 * This method will return a rule. The rule needs to be passed as it looks in the CommuniGate settings file.
	 * Example: A vacation notice is identified like #vacation but the email forwarding is #redirect
	 *
	 * @param string $account The account to get the rule from
	 * @param string $domain The domain the account is registered to
	 * @param string $rule The rule to get
	 * @return array|bool
	 */
	private function get_account_rule($domain, $account, $rule) {
		
		/** Rule found boolean */
		$rule_found = FALSE;

		/** New dictionary to return */
		$new_dict = Array();

		/** Get the account details */
		$setting = $this->get_account_rules($domain, $account);

		/** If there is a rule setting ... */
		if (isset($setting)) {

			/** Loop through the array so we can test the values */
			foreach ($setting as $value) {

				/** Clean up any open barckets */
				$value = str_replace('(', '', $value);
				$value = str_replace('"', '', $value);

				/** If the value is the start of the rule or we have alerady found the rule then... */
				if (preg_match('/'.$rule.'/i', $value) || $rule_found) {
					$rule_found = TRUE;
					$settingKey = substr($value, 0, strpos($value, ','));
					$settingValue = substr($value, strpos($value, ',') + 1);

					/** Add the value to the new dictionary to be returned */
					$new_dict[$settingKey] = $settingValue;
				}
			}

			if ($rule_found) {
				/** Set the success to TRUE */
				$this->success = TRUE;
			} else {
				$this->success = FALSE;
			}
		} else {
			/** Else there is no setting so set the success to FALSE */
			$this->success = FALSE;
		}

		return $this->success ? $new_dict : false;
	}

	/**
	 * Set rule
	 *
	 * This method will set a rule. All rule settings must be passed into the method.
	 *
	 * NOTE: The setting array must be in the following format:
	 * Array(
	 * '#RULE', -- Rule to get, same as the get rule method
	 * '"RULE KEY"', -- The key in the rule to search for
	 * 'RULE_STRUCT' -- The strucutre of the rule, usually contained in a constant.
	 * );
	 *
	 * @param string $domain The domain the account is registerd to
	 * @param string $account The account to set the rule for
	 * @param string $rule The new rule value
	 * @param array $setting An array that contains what rule to look for and the rule strucutre
	 * @return bool success
	 */
	private function set_account_rule($domain, $account, $rule, $setting) {
		
		/** Not using the internal get_rule because it returns the rules value */
		$this->sendAndParse(str_replace('$$', $account . '@' . $domain, self::API_GET_ACCOUNT_SETTINGS));

		/** Only get the rules setting */
		foreach ($this->output as $value) {
			if (preg_match('/^rules/', $value)) {
				$this->output = $value;
			}
		}

		/**
		 * The following for loop is meant to read the "dictionary" format of CommuniGate. It
		 * splits all the rules set into individual array values.
		 */

		/** found = found an openeing bracket ( */
		$found = 0;

		/** New array to contain all the rules */
		$rules_array = Array();

		/** Loop through all the rules */
		if (is_string($this->output)) {
			for ($i = 0; $i < strlen($this->output); $i++) {
				/** If an opening bracket "(" is found then increase the found paramater */
				if (preg_match('/\(/', $this->output[$i])) {
					++$found;
				}

				/** If found a closing bracket ")" then subtract the value of found */
				if (preg_match('/\)/', $this->output[$i])) {
					--$found;
				}

				/**
				 * If we have found two opening brackets and start isn't set meaning this is the first time that
				 * two opening brackets have been found then set start to the current position in the string. Searching
				 * for two open brackets becuase all rules are contained in brackets so the second bracket is the start
				 * of an actual rule.
				 */
				if ($found == 2 && !isset($start)) {
					$start = $i;
				} elseif ($found == 1 && isset($start)) {
					/**
					 * Else if found is down to just one bracket and start as already been set then set the end
					 * variable to the current position of the string. This will correspond to the rules string length.
					 */
					$end = $i;
				}

				/** If the end variable is set then ... */
				if (isset($end)) {
					/** Add the rule to the new rules array */
					$rules_array[] = substr($this->output, ($start + 1), ($end - $start - 1));
					/** Unset end and start to start fresh */
					unset($end);
					unset($start);
				}
			}
		}

		/** Strip carriage return and replace new lines with CommuniGate code \e */
		$rule = str_replace(chr(10), '\e', $rule);
		$rule = str_replace("\r", '', $rule);

		/** Create varible to contain new rules to set */
		$rules = '';

		/** If the rules array contains a rule then ... */
		if (count($rules_array) > 0) {
			/** Loop trhough the rules array */
			for ($i = 0; $i < count($rules_array); $i++) {
				/** If the current rule isn't what we are looking for then just add it to the new rules string */
				if (!preg_match('/'.$setting[1].'/', $rules_array[$i])) {
					$rules .= '(' . $rules_array[$i] . ')';
				} elseif ($rule != '') {
					/**
					 * Else if the new value of the rule is not blank then set the rule found variable to TRUE
					 * and add the rules struct with the new rule value to the new rules string.
					 */
					$rule_found = TRUE;
					$rules .= str_replace('$$', $rule, $setting[2]);
				}

				/** If it's not the last element of the array add a comman to seperate the rules */
				if (($i + 1) != count($rules_array)) {
					$rules .= ',';
				}
			}

			/** If the rules wasn't found and the new value of the rule isn't blank then ... */
			if (!isset($rule_found) && $rule != '') {
				/** If there was other rules then add a comma */
				if ($i != 0) {
					$rules .= ',';
				}
				/** Since the rule didn't already exist then add the rule with the new value to the rules strnig */
				$rules .= str_replace('$$', $rule, $setting[2]);
			}
			/** There is no else because be omitting the rule will delete it. */
		} elseif ($rule != '') {
			/** Else there are no rules set so just add the new one to the rules string */
			$rules = str_replace('$$', $rule, $setting[2]);
		}
		/** There is no else because be omitting the rule will delete it. */

		/** If there is an empty comma at the end of the string then remove it */
		if (preg_match('/,$/', $rules)) {
			$rules = substr($rules, 0, strlen($rules) - 1);
		}

		/** If the rules string contains rles then enclose all the rules in brackets */
		if ($rules != '') {
			$rules = '(' . $rules . ')';
		} else
			/** Else no rules were set so set it to Default = delete setting */ {
			$rules = 'Default';
		}

		/** Setup the command */
		$command = str_replace('$account$', $account . '@' . $domain, self::API_UPDATE_ACCOUNT_SETTINGS);
		$command = str_replace('$setting$', '{Rules=' . $rules . ';}', $command);

		$this->sendAndParse($command);

		return $this->success;
	}

	/**
	 * Set account store
	 *
	 * @param $domain
	 * @param $account
	 * @param int $max_size
	 * @return bool
	 */
	public function set_account_storage($domain, $account, $max_size = 50) {

		if (!preg_match('/m/i', $max_size)) {
			$max_size = "{$max_size}M";
		}

		/** Setup the command */
		$command = str_replace('$account$', $account . '@' . $domain, self::API_UPDATE_ACCOUNT_SETTINGS);
		$command = str_replace('$setting$', '{MaxAccountSize=' . $max_size . ';}', $command);

		$this->sendAndParse($command);
		$this->clearCache();

		return $this->success;
	}

	public function set_account_email_redirect($domain, $account, $email) {
		$this->set_account_rule($domain, $account, $email, Array('#redirect', '"Mirror to",', self::API_EMAIL_REDIRECT_STRUCT));
		$this->clearCache();
		return $this->success;
	}

	public function set_account_vacation_message($domain, $account, $message) {
		$this->set_account_rule($domain, $account, $message, Array('#vacation', '"Reply with",', self::API_VACATION_STRUCT));
		$this->clearCache();
		return $this->success;
	}

	public function get_account_email_redirect($domain, $account) {
		$k = 'Mirror to';
		$r = $this->get_account_rule($domain, $account, '#redirect');
		return (is_array($r) && isset($r[$k])) ? $r[$k] : null;
	}

	public function get_account_vacation_message($domain, $account) {
		$r = $this->get_account_rule($domain, $account, '#vacation');
		$message = null;

		if (is_array($r)) {
			foreach ($r as $key => $value) {
				if (preg_match('/reply with/i', $key)) {
					$str = str_replace('\e', chr(10), $value);
					$message = ($str && strlen($str) > 0) ? $str : null;
				}
			}
		}

		return $message;
	}

	public function clear_account_vacation_message($domain, $account) {
		$this->set_account_vacation_message($domain, $account, null);
		return $this->success;
	}

	public function clear_account_email_redirect($domain, $account) {
		$this->set_account_email_redirect($domain, $account, null);
		return $this->success;
	}

	/**
	 * Send a command and automatically parse the response
	 *
	 * @param $command
	 * @return bool
	 */
	private function sendAndParse($command) {
		$response = $this->send($command);
		return $this->parse_response($response);
	}

	/**
	 * Clear the request response cache
	 */
	public function clearCache() {
		$this->cache = array();
	}

	/**
	 * Send command
	 *
	 * This method will send a command through the socket
	 * to the CG CLI.
	 *
	 * @param $command
	 * @return string
	 */
	private function send($command) {
		if (!$this->connected) {
			$this->connect();
		}

		if ($this->logger && method_exists($this->logger, 'info')) {
			if (!preg_match('/(USER|PASS|INLINE)/i', $command)) {
				$this->logger->info('Communigate API: ' . $command);
			}
		}

		$hash = md5($command);

		if (array_key_exists($hash, $this->cache)) {
			return $this->cache[$hash];
		}

		fputs($this->socket, $command . chr(10));
		return $this->cache[$hash] = fgets($this->socket);
	}

	/**
	 * Parse response
	 *
	 * @param string $output Output from the CLI
	 * @return bool
	 * @throws ApiException
	 */
	private function parse_response($output,$autoSubParse = true) {

		/** Set the internal output to the output from the CLI */
		$this->output = $output;
		$response_code = substr($this->output, 0, 3);
		/** If the output was successfull response then return success */
		if (preg_match('/^200 ok/', $this->output)) {

			$this->success = TRUE;
			return $this->success;

		} elseif (preg_match('/^201/', $this->output)) {

			/** Else if the output starts with 201 (INLINE success) then set success */
			$this->success = TRUE;

			if ($autoSubParse) {
				/** Better parsing of the output is needed for the INLINE response */
				$this->_parse_response();
			}


		} elseif (array_key_exists($response_code, $this->CGC_KNOWN_RESPONSES)) {

			if ($response_code >= 500) {
				$this->success = FALSE;
				$error_string = $this->CGC_KNOWN_RESPONSES[$response_code];
				throw new ApiException("CGC Error {$response_code} - {$error_string}");
			}

		} else {
			throw new ApiException('Unknown error - ' . $this->output);
		}
	}

	/**
	 * _parse response
	 *
	 * This method will modify the CLI output and create an array of each data item
	 */
	private function _parse_response() {
		/** The exploder will identify how to create the array */
		$exploder = '';

		/** If the command wasn't a successfull then return FALSE */
		if (preg_match('/^201 \{\}/', $this->output) || preg_match('/^201 \(\)/', $this->output)) {
			$this->output = '';
			return $this->success = FALSE;
		}


		/** If the output start with a ( = array format then ... */
		if (preg_match('/^201 \(/', $this->output) && !preg_match('/^201 \(\(/', $this->output)) {
			/** The exploder for the array format is a comma */
			$exploder = ',';
			/** Strip the beginning 201 ( and closing ) */
			$this->output = preg_replace(Array('/^201 \(/', '/\)/'), '', $this->output);
		} elseif (preg_match('/^201 \{/', $this->output)) {
			/** Else the output format is a dictionary , the exploder is a semi-colan */
			$exploder = ';';
			/** Strip the beginning 201 { and closing } */
			$this->output = preg_replace(Array('/^201 \{/', '/\}/'), '', $this->output);
		} elseif (preg_match('/^201 \(\(/', $this->output)) {
			/** The exploder for the array format is a comma */
			$exploder = ',(';
			/** Strip the beginning 201 ( and closing ) */
			$this->output = preg_replace(Array('/^201 \(/', '/\)/'), '', $this->output);
		} else {
			/** Else assume that format is a string or int so explode by the space */
			$exploder = ' ';
			/** Strip the beginning 201 and trim the output */
			$this->output = trim(preg_replace('/^201/', '', $this->output));
		}

		/** Set the output to an array that is exploded by the exploder */
		$this->output = explode($exploder, $this->output);

		/** If the last element of the array is blank then pop it off the array */
		if (strlen(trim($this->output[(count($this->output) - 1)])) == 0) {
			array_pop($this->output);
		}

		return $this->success = TRUE;
	}

}