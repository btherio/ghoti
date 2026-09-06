<?php
/*
 * mail.smtp.php - a small, dependency-free SMTP client.
 *
 * This app has no Composer/vendor pipeline, so rather than pull in PHPMailer
 * this speaks just enough SMTP (RFC 5321) to hand a message to a *local*
 * mail server - the intended deployment is Postfix/Exim on the same Arch
 * Linux host (or LAN) as the web server, which is why the defaults are
 * 127.0.0.1:25 with no auth/TLS. Submitting to a public relay that requires
 * STARTTLS or AUTH LOGIN is also supported for flexibility.
 *
 * Deliberately standalone: it depends only on PHP's stream functions, not on
 * $_SESSION, ghotidb, or any other app state, so both the logged-in app
 * (mail.php) and the pre-auth password-reset.php script can construct one
 * directly from a settings array and send mail with it.
 *
 * TLS: certificates are VERIFIED by default. A mail server on the LAN often
 * presents a self-signed certificate that the system CA store knows nothing
 * about, and whose name does not match the IP you dial it on. Rather than
 * turning verification off, point 'tlsCaFile' at that server's certificate
 * (it is its own CA when self-signed) and set 'tlsPeerName' to the name in
 * the certificate. Verification then succeeds for exactly that one server
 * and nothing else. 'tlsVerify' => false exists as a last resort and is
 * logged as insecure wherever it is surfaced to an admin.
 */

class MailSmtpClient{
	private $host;
	private $port;
	private $encryption;   // 'none' | 'tls' (STARTTLS) | 'ssl' (implicit TLS)
	private $username;
	private $password;
	private $fromAddress;
	private $fromName;
	private $tlsVerify;    // bool - verify the server certificate (default true)
	private $tlsCaFile;    // path to a CA/self-signed cert to trust, or ''
	private $tlsPeerName;  // certificate name to expect, or '' to use the host
	private $timeout;
	public $lastError = '';

	public function __construct(array $settings, $timeoutSeconds = 12){
		$this->host        = isset($settings['smtpHost']) ? (string)$settings['smtpHost'] : '127.0.0.1';
		$this->port         = isset($settings['smtpPort']) ? (int)$settings['smtpPort'] : 25;
		$this->encryption   = isset($settings['encryption']) ? (string)$settings['encryption'] : 'none';
		$this->username     = isset($settings['smtpUsername']) ? (string)$settings['smtpUsername'] : '';
		$this->password     = isset($settings['smtpPassword']) ? (string)$settings['smtpPassword'] : '';
		$this->fromAddress  = isset($settings['fromAddress']) ? (string)$settings['fromAddress'] : '';
		$this->fromName     = isset($settings['fromName']) ? (string)$settings['fromName'] : '';
		//Default to verifying: an install that predates these settings (no key
		//present) should get the safe behaviour, not the permissive one.
		$this->tlsVerify    = isset($settings['tlsVerify']) ? (bool)$settings['tlsVerify'] : true;
		$this->tlsCaFile    = isset($settings['tlsCaFile']) ? (string)$settings['tlsCaFile'] : '';
		$this->tlsPeerName  = isset($settings['tlsPeerName']) ? (string)$settings['tlsPeerName'] : '';
		$this->timeout      = max(3, (int)$timeoutSeconds);
	}

	//Sends one plain-text email to a single recipient. Returns true on success,
	//or false with $this->lastError set to a diagnostic message (never shown
	//to end users - callers should log it and show a generic message instead).
	public function send($toAddress, $toName, $subject, $body){
		if($this->fromAddress === ''){
			$this->lastError = 'No "from" address configured.';
			return false;
		}
		$socket = null;
		try{
			$socket = $this->connect();
			$this->expect($socket, 220, 'connect');

			$localHost = isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== '' ? $_SERVER['SERVER_NAME'] : 'localhost';
			$this->command($socket, "EHLO ".$localHost);
			$ehloReply = $this->expect($socket, 250, 'EHLO');

			if($this->encryption === 'tls'){
				$this->command($socket, "STARTTLS");
				$this->expect($socket, 220, 'STARTTLS');
				//Capture OpenSSL's reason instead of discarding it with @ - a bare
				//"negotiation failed" gives an admin nothing to act on, whereas
				//"certificate verify failed" points straight at tlsCaFile/tlsPeerName.
				$cryptoError = '';
				set_error_handler(function($no, $str) use (&$cryptoError){ $cryptoError = $str; return true; });
				$crypto = stream_socket_enable_crypto($socket, true, $this->cryptoMethod());
				restore_error_handler();
				if($crypto !== true){
					throw new Exception('STARTTLS negotiation failed'.($cryptoError !== '' ? ': '.$this->tidyTlsError($cryptoError) : '.'));
				}
				//RFC 3207: state resets after STARTTLS, must re-EHLO.
				$this->command($socket, "EHLO ".$localHost);
				$ehloReply = $this->expect($socket, 250, 'EHLO (post-STARTTLS)');
			}

			if($this->username !== ''){
				if(stripos($ehloReply, 'AUTH') === false){
					throw new Exception('Server does not advertise AUTH support.');
				}
				$this->command($socket, "AUTH LOGIN");
				$this->expect($socket, 334, 'AUTH LOGIN');
				$this->command($socket, base64_encode($this->username));
				$this->expect($socket, 334, 'AUTH username');
				$this->command($socket, base64_encode($this->password));
				$this->expect($socket, 235, 'AUTH password');
			}

			$this->command($socket, "MAIL FROM:<".$this->fromAddress.">");
			$this->expect($socket, 250, 'MAIL FROM');
			$this->command($socket, "RCPT TO:<".$toAddress.">");
			$this->expect($socket, array(250,251), 'RCPT TO');
			$this->command($socket, "DATA");
			$this->expect($socket, 354, 'DATA');
			//The message body legitimately contains many CRLFs (that's how SMTP
			//DATA is line-structured), so it must NOT go through command()'s
			//single-line CRLF-stripping guard - that guard is for one-line
			//commands built from possibly-untrusted values (see command()).
			$this->rawWrite($socket, $this->buildMessage($toAddress, $toName, $subject, $body)."\r\n.\r\n");
			$this->expect($socket, 250, 'message body');
			$this->command($socket, "QUIT");
			@fclose($socket);
			return true;
		}catch (Throwable $e){
			$this->lastError = $e->getMessage();
			if(is_resource($socket)){
				@fwrite($socket, "QUIT\r\n");
				@fclose($socket);
			}
			return false;
		}
	}

	private function cryptoMethod(){
		if(defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')){ return STREAM_CRYPTO_METHOD_TLS_CLIENT; }
		return STREAM_CRYPTO_METHOD_SSLv23_CLIENT; // old PHP fallback name
	}

	private function connect(){
		$scheme = ($this->encryption === 'ssl') ? 'ssl://' : '';
		$errno = 0; $errstr = '';
		//The context matters for 'ssl' (implicit TLS), where the handshake happens
		//inside stream_socket_client; for 'tls'/'none' it is harmless, and for
		//'tls' the same context is reused by stream_socket_enable_crypto().
		$socket = @stream_socket_client(
			$scheme.$this->host.":".$this->port,
			$errno, $errstr, $this->timeout,
			STREAM_CLIENT_CONNECT,
			$this->streamContext()
		);
		if($socket === false){
			$detail = trim((string)$errstr);
			if($detail === '' && $errno !== 0){ $detail = 'errno '.$errno; }
			throw new Exception("Could not connect to $this->host:$this->port".($detail !== '' ? " (".$this->tidyTlsError($detail).")" : ""));
		}
		stream_set_timeout($socket, $this->timeout);
		return $socket;
	}

	//Builds the TLS stream context. Verification is ON unless an admin has
	//explicitly disabled it; a pinned CA file and/or an expected certificate
	//name are the supported way to talk to a self-signed LAN mail server
	//without giving up verification altogether.
	private function streamContext(){
		$ssl = array(
			'verify_peer'         => $this->tlsVerify,
			'verify_peer_name'    => $this->tlsVerify,
			'allow_self_signed'   => !$this->tlsVerify,
			'disable_compression' => true,
			'SNI_enabled'         => true,
		);
		$peerName = $this->tlsPeerName !== '' ? $this->tlsPeerName : $this->host;
		if(filter_var($peerName, FILTER_VALIDATE_IP) !== false){
			//An IP literal is not a legal SNI value and matches no certificate
			//name, so don't send it as one. With verification on and no
			//tlsPeerName configured this will (correctly) fail closed.
			$ssl['SNI_enabled'] = false;
		}else{
			$ssl['peer_name'] = $peerName;
		}
		if($this->tlsVerify && $this->tlsCaFile !== '' && is_readable($this->tlsCaFile)){
			$ssl['cafile'] = $this->tlsCaFile;
		}
		return stream_context_create(array('ssl' => $ssl));
	}

	//OpenSSL errors arrive as a multi-line blob with the PHP function name
	//glued to the front. Flatten it so it fits on one log line.
	private function tidyTlsError($message){
		$message = preg_replace('/^[A-Za-z_]+\(\):\s*/', '', trim($message));
		$message = preg_replace('/\s+/', ' ', $message);
		return $message;
	}

	private function command($socket, $line){
		//CRLF injection guard: an attacker-controlled subject/name that reaches
		//here (already validated upstream, but defense in depth) must not be
		//able to smuggle extra SMTP commands.
		$line = str_replace(array("\r","\n"), '', $line);
		if(@fwrite($socket, $line."\r\n") === false){
			throw new Exception('Connection lost while sending "'.$line.'".');
		}
	}

	//Writes pre-terminated, multi-line data (the DATA payload) verbatim - no
	//CRLF stripping, since CRLFs are exactly what a message body is made of.
	private function rawWrite($socket, $data){
		$len = strlen($data);
		$written = 0;
		while($written < $len){
			$chunk = @fwrite($socket, substr($data, $written));
			if($chunk === false || $chunk === 0){
				throw new Exception('Connection lost while sending the message body.');
			}
			$written += $chunk;
		}
	}

	//Reads one (possibly multi-line) SMTP reply and asserts its status code is
	//in $expectedCodes (an int or array of ints). Returns the full reply text.
	private function expect($socket, $expectedCodes, $stage){
		$expectedCodes = is_array($expectedCodes) ? $expectedCodes : array($expectedCodes);
		$reply = '';
		$code = 0;
		do{
			$line = @fgets($socket, 515);
			if($line === false){
				throw new Exception("Connection lost waiting for a reply to $stage.");
			}
			$reply .= $line;
			$code = (int)substr($line, 0, 3);
			$continues = isset($line[3]) && $line[3] === '-';
		}while($continues);
		if(!in_array($code, $expectedCodes, true)){
			throw new Exception("Unexpected reply to $stage: ".trim($reply));
		}
		return $reply;
	}

	//Builds a minimal RFC 5322 message: headers + body, with the lone leading
	//dot on any body line escaped per RFC 5321 DATA transparency rules.
	private function buildMessage($toAddress, $toName, $subject, $body){
		$encodeHeader = function($value){
			//Fold non-ASCII header values (subject, display names) per RFC 2047
			//rather than sending raw UTF-8 bytes in a header.
			if(preg_match('/[^\x20-\x7E]/', $value)){
				return '=?UTF-8?B?'.base64_encode($value).'?=';
			}
			return $value;
		};
		$foldAddress = function($address, $name) use ($encodeHeader){
			$address = str_replace(array("\r","\n"), '', $address);
			if($name === ''){ return $address; }
			$name = str_replace(array("\r","\n",'"'), '', $name);
			return $encodeHeader($name).' <'.$address.'>';
		};

		$headers = array();
		$headers[] = 'From: '.$foldAddress($this->fromAddress, $this->fromName);
		$headers[] = 'To: '.$foldAddress($toAddress, $toName);
		$headers[] = 'Subject: '.$encodeHeader(str_replace(array("\r","\n"), '', $subject));
		$headers[] = 'Date: '.date('r');
		$headers[] = 'Message-ID: <'.bin2hex(random_bytes(16)).'@'.($this->messageIdHost()).'>';
		$headers[] = 'MIME-Version: 1.0';
		$headers[] = 'Content-Type: text/plain; charset=UTF-8';
		$headers[] = 'Content-Transfer-Encoding: 8bit';

		$normalizedBody = str_replace("\r\n", "\n", (string)$body);
		$normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
		//RFC 5321 4.5.2: a line consisting of a single "." must become "..".
		$lines = explode("\r\n", $normalizedBody);
		foreach($lines as &$line){
			if($line === '.'){ $line = '..'; }
		}
		unset($line);
		$escapedBody = implode("\r\n", $lines);

		return implode("\r\n", $headers)."\r\n\r\n".$escapedBody;
	}

	private function messageIdHost(){
		$host = isset($_SERVER['SERVER_NAME']) ? (string)$_SERVER['SERVER_NAME'] : '';
		$host = preg_replace('/[^A-Za-z0-9.-]/', '', $host);
		return $host !== '' ? $host : 'localhost';
	}
}
?>
