<?php
/*
 * vhosts.import.php - adopting hand-written vhosts into ghoti management.
 *
 * Turns the consolidated httpd-vhosts.conf into one drop-in file per site, so
 * the module can manage certificates and deletions for vhosts it did not write.
 *
 * Two rules shape everything here.
 *
 * 1. VERBATIM. Each <VirtualHost> block is copied byte for byte. The real file
 *    on this server uses <FilesMatch>, <Directory>, Alias, SSLOptions and
 *    "Include /etc/letsencrypt/options-ssl-apache.conf" - none of which the
 *    edit form has fields for. Rebuilding blocks from parsed fields would
 *    silently strip TLS hardening and PHP handlers from live sites. So the
 *    import never regenerates; it copies, and marks the result "adopted" so
 *    the form stays read-only (see VhostsParser::MARKER).
 *
 * 2. ORDER IS PRESERVED. Apache treats the FIRST <VirtualHost> on a port as the
 *    default for any request whose Host header matches nothing - and on this
 *    server smurfius.com is deliberately first on both :80 and :443, with
 *    hro/staging relying on that https fallback. Apache sorts the conf.d glob
 *    lexically (verified against this build), so each file gets a zero-padded
 *    numeric prefix following the source file's own order. Alphabetical naming
 *    would have made adv.vantools.ca the default vhost.
 *
 * Blocks that share a ServerName and sit next to each other (the usual :80 +
 * :443 pair) go into one file, matching how generated vhosts are laid out.
 */

class VhostsImporter{
	//Prefix step. Leaving gaps means a vhost can later be slotted between two
	//existing ones by hand without renaming everything after it.
	const PREFIX_STEP = 10;
	//Three digits of prefix keeps the lexical sort honest up to 99 groups.
	const MAX_GROUPS = 99;

	/*
	 * Work out what the import would do, without doing any of it.
	 *
	 * Returns array('ok'=>bool, 'error'=>string, 'files'=>list). Each file entry
	 * is array('name','fileName','serverName','ports','blocks','content'), where
	 * content is the finished drop-in file. The caller renders this as a preview
	 * and, unchanged, hands it to the helper.
	 */
	public static function plan($vhosts, $sourceFile){
		$external = array();
		foreach($vhosts as $vhost){
			//Only the read-only consolidated file is imported; anything already
			//in the drop-in directory is by definition already managed.
			if(!$vhost['managed']){ $external[] = $vhost; }
		}
		if(!$external){
			return array('ok' => false, 'error' => "There are no external vhosts to import.", 'files' => array());
		}

		//Group consecutive blocks sharing a ServerName - a :80/:443 pair is one
		//site to an admin, and splitting them across files would let a later
		//edit move one half relative to the other.
		$groups = array();
		$last = null;
		foreach($external as $vhost){
			$serverName = $vhost['serverName'];
			if($last !== null && $groups[$last]['serverName'] === $serverName){
				$groups[$last]['blocks'][] = $vhost;
				continue;
			}
			$groups[] = array('serverName' => $serverName, 'blocks' => array($vhost));
			$last = count($groups) - 1;
		}
		if(count($groups) > self::MAX_GROUPS){
			return array('ok' => false, 'error' => "This panel imports at most ".self::MAX_GROUPS." vhosts at once.", 'files' => array());
		}

		$files = array();
		$used = array();
		$index = 0;
		foreach($groups as $group){
			$index += self::PREFIX_STEP;
			$stem = self::stemFor($group['serverName'], $used);
			if($stem === ''){
				return array('ok' => false, 'error' => "Could not derive a filename for \"".$group['serverName']."\".", 'files' => array());
			}
			$used[$stem] = true;
			$name = sprintf('%03d-%s', $index, $stem);
			if(!VhostsParser::isSafeName($name)){
				return array('ok' => false, 'error' => "Derived an unusable filename \"".$name."\".", 'files' => array());
			}

			$ports = array();
			foreach($group['blocks'] as $block){
				foreach($block['ports'] as $port){
					if(!in_array($port, $ports, true)){ $ports[] = $port; }
				}
			}
			sort($ports);

			$files[] = array(
				'name'       => $name,
				'fileName'   => $name.'.conf',
				'serverName' => $group['serverName'],
				'ports'      => $ports,
				'blocks'     => count($group['blocks']),
				'content'    => self::renderAdopted($group, $sourceFile),
			);
		}
		return array('ok' => true, 'error' => '', 'files' => $files);
	}

	/*
	 * A filename stem from a ServerName. Lowercased, anything outside the safe
	 * set collapsed to a dash, and de-duplicated against stems already taken -
	 * two non-adjacent groups can legitimately share a ServerName.
	 */
	private static function stemFor($serverName, $used){
		$stem = strtolower(trim((string)$serverName));
		$stem = preg_replace('/[^a-z0-9._-]+/', '-', $stem);
		$stem = trim($stem, '-._');
		if($stem === '' || $stem === 'no-servername'){ $stem = 'unnamed'; }
		$stem = substr($stem, 0, 50);
		if(!isset($used[$stem])){ return $stem; }
		for($i = 2; $i <= 99; $i++){
			if(!isset($used[$stem.'-'.$i])){ return $stem.'-'.$i; }
		}
		return '';
	}

	//One drop-in file: the adopted marker, a short provenance header, then the
	//original blocks exactly as they were written.
	private static function renderAdopted($group, $sourceFile){
		$o  = "#############################################################################\n";
		$o .= VhostsParser::MARKER." ".VhostsParser::ORIGIN_ADOPTED."\n";
		$o .= "#  ".$group['serverName']."\n";
		$o .= "#\n";
		$o .= "#  Imported verbatim from ".$sourceFile." on ".date('Y-m-d').".\n";
		$o .= "#  The blocks below are byte-for-byte as they were written there; this\n";
		$o .= "#  module does NOT regenerate them, because they may use directives its\n";
		$o .= "#  edit form has no fields for. Edit this file directly.\n";
		$o .= "#\n";
		$o .= "#  The numeric filename prefix preserves the original vhost order, which\n";
		$o .= "#  decides the default vhost per port. Renaming this file can silently\n";
		$o .= "#  change which site answers unmatched requests.\n";
		$o .= "#############################################################################\n\n";
		foreach($group['blocks'] as $block){
			$o .= "<VirtualHost ".$block['addresses'].">\n".$block['body']."\n</VirtualHost>\n\n";
		}
		return rtrim($o, "\n")."\n";
	}

	/*
	 * Serialise a plan for the helper's `import` verb.
	 *
	 * Framed rather than one-file-per-call so the helper can apply the whole
	 * migration, configtest once, and roll every file back together - there is
	 * never a moment where half the vhosts have moved.
	 */
	public static function encode($files){
		$payload = '';
		foreach($files as $file){
			$payload .= "===GHOTI-IMPORT-FILE ".$file['fileName']."\n";
			$payload .= $file['content'];
			if(substr($file['content'], -1) !== "\n"){ $payload .= "\n"; }
		}
		$payload .= "===GHOTI-IMPORT-END\n";
		return $payload;
	}
}
?>
