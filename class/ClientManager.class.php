<?php
class ClientManager {
	private $_db;
	
	public function __construct($db) {
		$this->setDb($db);
	}
	
	public function add(Client $client) {
		// first check that the email isn't contained twice
		$result = pg_prepare($this->_db, "", 'SELECT * FROM client WHERE email = $1');
		$result = pg_execute($this->_db, "", array($client->email()));
		if (pg_num_rows($result) > 0) {
			throw new Exception('Email already exists in the database');
		}
		pg_free_result($result);
		
		// Not already in the database, so we add it
		$result = pg_prepare($this->_db, "", 'INSERT INTO client (email, password) VALUES ($1, $2)');
		$result = pg_execute($this->_db, "", array($client->email(), $client->password())) or die('Query failed: ' . pg_last_error($this->_db));
		pg_free_result($result);
	}
	
	public function get($id) {
		$id = (int) $id;
		$result = pg_prepare($this->_db, '', 'SELECT c.id, c.email, c.password, p.level FROM client c LEFT JOIN privilege p ON c.id = p.client_id WHERE c.id = $1');
		$result = pg_execute($this->_db, '', array($id)) or die('Query failed: ' . pg_last_error($this->_db));
		if (pg_num_rows($result) != 1) {
			throw new Exception('Client does not exist');
		}
		
		$data = pg_fetch_array($result, 0, PGSQL_ASSOC);
		pg_free_result($result);
		
		return new Client($data);
	}
	
	public function persist(Client $client) {
		$result = pg_prepare($this->_db, "", 'UPDATE client SET email = $1, password = $2 WHERE id = $3');
		$result = pg_execute($this->_db, "", array($client->email(), $client->password(), $client->id())) or die('Query failed: ' . pg_last_error($this->_db));
		pg_free_result($result);
		
		$result = pg_prepare($this->_db, "", 'UPDATE privilege SET level = $1 WHERE client_id = $2');
		$result = pg_execute($this->_db, "", array($client->level(), $client->id())) or die('Query failed: ' . pg_last_error($this->_db));
		pg_free_result($result);
		
		$result = pg_prepare($this->_db, "", 'INSERT INTO privilege (client_id, level) SELECT $1, $2 WHERE NOT EXISTS (SELECT 1 FROM privilege WHERE client_id = $1)');
		$result = pg_execute($this->_db, "", array($client->id(), $client->level())) or die('Query failed: ' . pg_last_error($this->_db));
		pg_free_result($result);
	}
	
	public function login($email, $plainPassword) {
		//first get the user
		$result = pg_prepare($this->_db, '', 'SELECT c.id, c.email, c.password, p.level FROM client c LEFT JOIN privilege p ON c.id = p.client_id WHERE c.email = $1');
		$result = pg_execute($this->_db, '', array($email)) or die('Query failed: ' . pg_last_error($this->_db));
		if (pg_num_rows($result) != 1) {
			throw new Exception('Client does not exist');
		}
		
		//then compare the passwords
		$data = pg_fetch_array($result, 0, PGSQL_ASSOC);
		if (!Crypto::verifyPassword($plainPassword, $data['password'])) {
			throw new Exception('Passwords do not match');
		}
		
		//create the "session" with cookie
		$this->onLogin($data['id']);
		
		//return the user
		return new Client($data);
	}
	
	public function setDb($db) {
		$this->_db = $db;
	}
	
	private function onLogin($id) {
		//create and store the token
		$token = array('client_id' => $id, 
					   'token' => Crypto::generateRandomToken());
		$sessionTokenManager = new SessionTokenManager($this->_db);
		$sessionTokenManager->upsert(new SessionToken($token));
		
		//create the cookie
		$cookie = $id . ':' . $token['token'];
		$cookie .= ':' . Crypto::mac($cookie);
		
		setcookie('session', $cookie, time()+60*60*24*365);
	}
	
	public function session() {
		$cookie = isset($_COOKIE['session']) ? $_COOKIE['session'] : '';
		if ($cookie) {
			//first authenthicate the cookie
			list ($id, $token, $mac) = explode(':', $cookie);
			if (!Crypto::compareHash(Crypto::mac($id . ':' . $token), $mac)) {
				throw new Exception("Cookie corrupted");
			}
			
			//second match the cookie against the DB
			$sessionTokenManager = new SessionTokenManager($this->_db);
			$sessionToken = $sessionTokenManager->get($id);
			if (!Crypto::compareHash($sessionToken->token(), $token)) {
				throw new Exception("Session tokens do not match");
			}
			
			//if all successful => connect client
			return $this->get($id);
		}
	}
}