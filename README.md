CommunigateAPI
-------------------

Basic API object for Communigate Mail Server.

```php

$api = new Api(array(
	'host' => '127.0.0.1',
	'login' => 'admin@example.com',
	'password' => 'password'
));

// Get all available domains
$api->get_domains();

// Get all available accounts
$api->get_accounts('example.com');

// Create and remove accounts
$api->create_account('example.com', 'test', 'password');
$api->delete_account('example.com', 'test');

// Set and remove email redirect
$api->set_email_redirect('example.com', 'test', 'dev@null.com');
$api->clear_email_redirect('example.com', 'test');
````