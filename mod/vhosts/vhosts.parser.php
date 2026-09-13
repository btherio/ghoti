<?php
/*
 * vhosts.parser.php - reads <VirtualHost> blocks out of Apache's config.
 *
 * Read-only and unprivileged: everything it touches is world-readable config
 * (0644 root:root), so this half of the module works with no helper installed.
 *
 * Two sources, treated differently on purpose:
 *
 *   MANAGED  - one file per vhost under the drop-in directory (conf.d/*.conf,
 *              pulled in by httpd.conf's trailing "IncludeOptional
 *              conf/conf.d/*.conf"). These the module may rewrite.
 *
 *   EXTERNAL - the consolidated httpd-vhosts.conf and anything else. Shown for
 *              context but never written. That file declares itself the single
 *              source of truth and carries an explicit ordering contract: the
 *              first <VirtualHost> per port is the default for unmatched Host
 *              headers, and smurfius.com is deliberately first on :80 and :443.
 *              Because conf.d is included AFTER it, vhosts this module creates
 *              can never displace that default - which is exactly why new
 *              vhosts go there instead of being appended to the big file.
 *
 * This is a pragmatic line-oriented parser, not an Apache config engine: it
 * understands the directives the UI displays and ignores the rest verbatim.
 * Authoritative validation is always "apachectl configtest" via the helper.
 */

class VhostsParser{
	private $settings;

	//Directives lifted out of a block for display. Everything else is kept in
	//the raw body so nothing is lost when a block is shown or re-saved.
	private static $captured = array(
		'servername', 'serveralias', 'serveradmin', 'documentroot',
		'errorlog', 'customlog', 'sslengine', 'sslcertificatefile',
		'sslcertificatekeyfile', 'sslcertificatechainfile', 'rewriterule',
	);

	public function __construct($settings){
		$this->settings = $settings;
	}

	/*
	 * Every vhost the module can see, managed first, as an array of associative
	 * arrays. Each entry carries 'managed' => bool so the UI can gate the
	 * edit/delete controls without re-deriving it.
	 *
	 * Managed entries are MERGED: one drop-in file normally holds a :80 and a
	 * :443 block for the same site, and they are one thing to an admin - one
	 * card, one form, one delete. Editing them as separate blocks would mean a
	 * form opened on the :80 half could silently drop the :443 half on save.
	 *
	 * External entries are NOT merged: they are shown exactly as the file
	 * declares them, because this module never rewrites that file and the
	 * per-block line numbers are how an admin finds them on disk.
	 */
	public function listVhosts(){
		$out = array();
		foreach($this->managedFiles() as $file){
			$blocks = $this->parseFile($file, true);
			if($blocks){ $out[] = self::mergeBlocks($blocks); }
		}
		$external = (string)$this->settings['readOnlyConf'];
		if($external !== '' && is_file($external) && is_readable($external)){
			foreach($this->parseFile($external, false) as $vhost){
				$out[] = $vhost;
			}
		}
		return $out;
	}

	/*
	 * Fold every <VirtualHost> block of one managed file into a single logical
	 * vhost: the union of its ports, TLS on if any block enables it, the
	 * certificate from whichever block carries one, and the redirect flag from
	 * whichever block rewrites to https. Identity fields come from the first
	 * block, which is the :80 one in everything this module writes.
	 */
	private static function mergeBlocks($blocks){
		$merged = $blocks[0];
		$bodies = array();
		foreach($blocks as $block){
			foreach($block['ports'] as $port){
				if(!in_array($port, $merged['ports'], true)){ $merged['ports'][] = $port; }
			}
			foreach($block['aliases'] as $alias){
				if(!in_array($alias, $merged['aliases'], true)){ $merged['aliases'][] = $alias; }
			}
			if($block['sslEngine']){ $merged['sslEngine'] = true; }
			if($block['redirectsToSsl']){ $merged['redirectsToSsl'] = true; }
			foreach(array('certFile','certKeyFile','certChainFile','documentRoot','serverAdmin') as $field){
				if($merged[$field] === '' && $block[$field] !== ''){ $merged[$field] = $block[$field]; }
			}
			if($merged['serverName'] === '(no ServerName)' && $block['serverName'] !== '(no ServerName)'){
				$merged['serverName'] = $block['serverName'];
			}
			$bodies[] = "<VirtualHost ".$block['addresses'].">\n".$block['body']."\n</VirtualHost>";
		}
		sort($merged['ports']);
		//The raw view of a merged vhost is the whole file's worth of blocks, so
		//printVhostForm() shows what is actually on disk rather than one half.
		$merged['body'] = implode("\n\n", $bodies);
		$merged['addresses'] = '';
		return $merged;
	}

	//Absolute paths of the module-managed drop-in files, sorted by name (which
	//is also the order Apache's glob include applies them in).
	public function managedFiles(){
		$dir = (string)$this->settings['dropInDir'];
		if($dir === '' || !is_dir($dir) || !is_readable($dir)){
			return array();
		}
		$files = glob(rtrim($dir, '/').'/*.conf');
		if(!is_array($files)){ return array(); }
		sort($files);
		return $files;
	}

	//Absolute path of the drop-in file for a vhost name, or '' if the name is
	//not a safe file stem. The name is validated by the caller too; this is the
	//single place the path is assembled, so traversal can't slip in sideways.
	public function managedPath($name){
		if(!self::isSafeName($name)){ return ''; }
		return rtrim((string)$this->settings['dropInDir'], '/').'/'.$name.'.conf';
	}

	//A drop-in file stem: hostname-ish, no dots-only tricks, no separators.
	public static function isSafeName($name){
		return is_string($name)
			&& $name !== ''
			&& strlen($name) <= 64
			&& preg_match('/^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$/', $name)
			&& strpos($name, '..') === false;
	}

	//Raw text of one managed drop-in file, or '' if it is absent/unreadable.
	public function readManaged($name){
		$path = $this->managedPath($name);
		if($path === '' || !is_file($path) || !is_readable($path)){ return ''; }
		$text = @file_get_contents($path);
		return $text === false ? '' : $text;
	}

	/*
	 * Pull every <VirtualHost ...> block out of one file.
	 *
	 * Nested sections (<Directory>, <IfModule>, ...) are tracked so a stray
	 * </VirtualHost>-looking line inside them can't close the block early, and
	 * directives inside them are NOT hoisted into the summary - a DocumentRoot
	 * inside a <Directory> belongs to that section, not to the vhost.
	 */
	private function parseFile($file, $managed){
		$text = @file_get_contents($file);
		if($text === false){ return array(); }

		$out = array();
		$lines = preg_split('/\r\n|\r|\n/', $text);
		$inBlock = false;
		$depth = 0;
		$current = null;

		foreach($lines as $lineNo => $raw){
			$line = trim($raw);
			$bare = preg_replace('/^#.*$/', '', $line); //comments never open/close a block

			if(!$inBlock){
				if(preg_match('/^<VirtualHost\s+([^>]+)>/i', $bare, $m)){
					$inBlock = true;
					$depth = 0;
					$current = self::newVhost($file, $managed, trim($m[1]), $lineNo + 1);
				}
				continue;
			}

			if(preg_match('#^</VirtualHost\s*>#i', $bare)){
				if($depth === 0){
					$inBlock = false;
					$out[] = self::finishVhost($current);
					$current = null;
					continue;
				}
			}

			//Track nested <Section> ... </Section> so we only read top-level
			//directives and only close on the matching </VirtualHost>.
			if(preg_match('/^<\s*\/\s*[A-Za-z]/', $bare)){
				if($depth > 0){ $depth--; }
			}elseif(preg_match('/^<\s*[A-Za-z][A-Za-z0-9]*/', $bare)){
				$depth++;
			}

			$current['body'][] = $raw;
			if($depth === 0 && $bare !== ''){
				self::captureDirective($current, $bare);
			}
		}
		return $out;
	}

	private static function newVhost($file, $managed, $addresses, $startLine){
		return array(
			'file'       => $file,
			'fileName'   => basename($file),
			'name'       => $managed ? preg_replace('/\.conf$/', '', basename($file)) : '',
			'managed'    => (bool)$managed,
			'addresses'  => $addresses,
			'ports'      => self::portsFrom($addresses),
			'startLine'  => $startLine,
			'serverName' => '',
			'aliases'    => array(),
			'serverAdmin'=> '',
			'documentRoot' => '',
			'errorLog'   => '',
			'customLog'  => '',
			'sslEngine'  => false,
			'certFile'   => '',
			'certKeyFile'=> '',
			'certChainFile' => '',
			//Whether this block rewrites everything to https. Read from the
			//config rather than assumed, so the edit form's "Redirect HTTP to
			//HTTPS" box reflects what is actually on disk even when someone has
			//hand-edited a managed file.
			'redirectsToSsl' => false,
			'body'       => array(),
		);
	}

	private static function finishVhost($vhost){
		$vhost['body'] = implode("\n", $vhost['body']);
		if($vhost['serverName'] === ''){
			$vhost['serverName'] = '(no ServerName)';
		}
		return $vhost;
	}

	//Split "*:80 10.0.0.10:443" into a de-duplicated list of port numbers.
	private static function portsFrom($addresses){
		$ports = array();
		foreach(preg_split('/\s+/', trim($addresses)) as $address){
			if($address === ''){ continue; }
			$pos = strrpos($address, ':');
			$port = $pos === false ? '80' : substr($address, $pos + 1);
			if(ctype_digit($port) && !in_array($port, $ports, true)){
				$ports[] = $port;
			}
		}
		return $ports;
	}

	//Read one top-level directive into the summary fields, if we display it.
	private static function captureDirective(&$vhost, $line){
		if(!preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s+(.*)$/', $line, $m)){ return; }
		$directive = strtolower($m[1]);
		if(!in_array($directive, self::$captured, true)){ return; }
		$value = trim($m[2]);

		switch($directive){
			case 'servername':
				$vhost['serverName'] = self::unquote($value);
				break;
			case 'serveralias':
				foreach(preg_split('/\s+/', $value) as $alias){
					if($alias !== ''){ $vhost['aliases'][] = self::unquote($alias); }
				}
				break;
			case 'serveradmin':     $vhost['serverAdmin'] = self::unquote($value); break;
			case 'documentroot':    $vhost['documentRoot'] = self::unquote($value); break;
			case 'errorlog':        $vhost['errorLog'] = self::unquote($value); break;
			//CustomLog takes "path format"; only the path is interesting here.
			case 'customlog':       $vhost['customLog'] = self::unquote(preg_split('/\s+/', $value)[0]); break;
			case 'sslengine':       $vhost['sslEngine'] = strtolower(self::unquote($value)) === 'on'; break;
			case 'sslcertificatefile':      $vhost['certFile'] = self::unquote($value); break;
			case 'sslcertificatekeyfile':   $vhost['certKeyFile'] = self::unquote($value); break;
			case 'sslcertificatechainfile': $vhost['certChainFile'] = self::unquote($value); break;
			//"RewriteRule ^ https://..." - the shape this module writes, and the
			//conventional one. A rule rewriting to https by any other spelling
			//still counts; anything not aimed at https does not.
			case 'rewriterule':
				if(stripos($value, 'https://') !== false){ $vhost['redirectsToSsl'] = true; }
				break;
		}
	}

	private static function unquote($value){
		$value = trim($value);
		if(strlen($value) >= 2){
			$first = $value[0];
			if(($first === '"' || $first === "'") && substr($value, -1) === $first){
				return substr($value, 1, -1);
			}
		}
		return $value;
	}
}
?>
