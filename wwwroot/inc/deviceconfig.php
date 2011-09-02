<?php

// Read provided output of "show cdp neighbors detail" command and
// return a list of records with (translated) local port name,
// remote device name and (translated) remote port name.
function ios12ReadCDPStatus ($input)
{
	$ret = array();
	foreach (explode ("\n", $input) as $line)
	{
		$matches = array();
		switch (TRUE)
		{
		case preg_match ('/^Device ID:\s*([A-Za-z0-9][A-Za-z0-9\.\-]*)/', $line, $matches):
		case preg_match ('/^System Name:\s*([A-Za-z0-9][A-Za-z0-9\.\-]*)/', $line, $matches):
			$ret['current']['device'] = $matches[1];
			break;
		case preg_match ('/^Interface: (.+),  ?Port ID \(outgoing port\): (.+)$/', $line, $matches):
			if (array_key_exists ('device', $ret['current']))
				$ret[ios12ShortenIfName ($matches[1])][] = array
				(
					'device' => $ret['current']['device'],
					'port' => ios12ShortenIfName ($matches[2]),
				);
			unset ($ret['current']);
			break;
		default:
		}
	}
	unset ($ret['current']);
	return $ret;
}

function ios12ReadLLDPStatus ($input)
{
	$ret = array();
	$got_header = FALSE;
	foreach (explode ("\n", $input) as $line)
	{
		if (preg_match ("/^Device ID/", $line))
			$got_header = TRUE;

		if (!$got_header)
			continue;

		$matches = preg_split ('/\s+/', $line);
		
		switch (count ($matches))
		{
		case 5:
			list ($remote_name, $local_port, $ttl, $caps, $remote_port) = $matches;
			$local_port = ios12ShortenIfName ($local_port);
			$remote_port = ios12ShortenIfName ($remote_port);
			$ret[$local_port][] = array
			(
				'device' => $remote_name,
				'port' => $remote_port,
			);
			break;
		default:
		}
	}
	return $ret;
}

function xos12ReadLLDPStatus ($input)
{
	$ret = array();
	foreach (explode ("\n", $input) as $line)
	{
		$matches = array();
		switch (TRUE)
		{
		case preg_match ('/^LLDP Port ([[:digit:]]+) detected \d+ neighbor$/', $line, $matches):
			$ret['current']['local_port'] = ios12ShortenIfName ($matches[1]);
			break;
		case preg_match ('/^      Port ID     : "(.+)"$/', $line, $matches):
			$ret['current']['remote_port'] = ios12ShortenIfName ($matches[1]);
			break;
		case preg_match ('/^    - System Name: "(.+)"$/', $line, $matches):
			if
			(
				array_key_exists ('current', $ret) and
				array_key_exists ('local_port', $ret['current']) and
				array_key_exists ('remote_port', $ret['current'])
			)
				$ret[$ret['current']['local_port']][] = array
				(
					'device' => $matches[1],
					'port' => $ret['current']['remote_port'],
				);
			unset ($ret['current']);
		default:
		}
	}
	unset ($ret['current']);
	return $ret;
}

function vrp5xReadLLDPStatus ($input)
{
	$ret = array();
	$valid_subtypes = array
	(
		'interfaceName',
		'interfaceAlias',
		'local',
	);
	foreach (explode ("\n", $input) as $line)
	{
		$matches = array();
		switch (TRUE)
		{
		case preg_match ('/^(.+) has \d+ neighbors:$/', $line, $matches):
			$ret['current']['local_port'] = ios12ShortenIfName ($matches[1]);
			break;
		case preg_match ('/^Port ?ID ?(?:sub)?type\s*:\s*([^ ]+)/i', $line, $matches):
			$ret['current']['PortIdSubtype'] = $matches[1];
			break;
		case preg_match ('/^Port ?ID\s*:\s*(.+)$/i', $line, $matches):
			$ret['current']['PortId'] = $matches[1];
			break;
		case preg_match ('/^Sys(?:tem)? ?name\s*:\s*(.+)$/i', $line, $matches):
			if
			(
				array_key_exists ('current', $ret) and
				array_key_exists ('PortIdSubtype', $ret['current']) and
				in_array ($ret['current']['PortIdSubtype'], $valid_subtypes) and
				array_key_exists ('PortId', $ret['current']) and
				array_key_exists ('local_port', $ret['current'])
			)
				$ret[$ret['current']['local_port']][] = array
				(
					'device' => $matches[1],
					'port' => ios12ShortenIfName ($ret['current']['PortId']),
				);
			unset ($ret['current']);
			break;
		default:
		}
	}
	unset ($ret['current']);
	return $ret;
}

function nxos4ReadLLDPStatus ($input)
{
	$ret = array();
	$current = array();
	$if_name = NULL;
	foreach (explode ("\n", $input) as $line)
	{
		$line = trim ($line);
		if ($line == '')
		{
			if (isset ($if_name) and isset ($current['port']) and isset ($current['device']))
				$ret[$if_name][] = $current;
			$current = array();
			$if_name = NULL;
		}
		else
		{
			$pair = explode (': ', $line);
			if (count ($pair) == 2 and isset ($pair[1]))
			{
				list ($key, $value) = $pair;
				$value = trim ($value, "\x06\x10\x13");
				switch ($key)
				{
					case 'Port id':
						$current['port'] = ios12ShortenIfName ($value);
						break;
					case 'System Name':
						$current['device'] = $value;
						break;
					case 'Local Port id':
						$if_name = ios12ShortenIfName ($value);
						break;
				}
			}
		}
	}
	return $ret;
}

function ftos8ReadLLDPStatus ($input)
{
	$ret = array();
	$valid_subtypes = array
	(
		'Interface name (5)',
		'Interface Alias (1)',
	);
	foreach (explode ("\n", $input) as $line)
	{
		$matches = array();
		switch (TRUE)
		{
		case preg_match ('/^ Local Interface (.+) has \d+ neighbor/', $line, $matches):
			$ret['current']['local_port'] = strtolower (str_replace (' ', '', $matches[1])); # "Gi 0/19" => "gi0/19"
			break;
		case preg_match ('/^    Remote Port Subtype:  (.+)$/', $line, $matches):
			$ret['current']['remote_subtype'] = $matches[1];
			break;
		case preg_match ('/^    Remote Port ID:  (.+)$/i', $line, $matches):
			$ret['current']['remote_port'] = $matches[1];
			break;
		case preg_match ('/^    Remote System Name:  (.+)$/', $line, $matches):
			if
			(
				array_key_exists ('current', $ret) and
				array_key_exists ('remote_subtype', $ret['current']) and
				in_array ($ret['current']['remote_subtype'], $valid_subtypes) and
				array_key_exists ('remote_port', $ret['current']) and
				array_key_exists ('local_port', $ret['current'])
			)
				$ret[$ret['current']['local_port']][] = array
				(
					'device' => $matches[1],
					'port' => ios12ShortenIfName ($ret['current']['remote_port']),
				);
			unset ($ret['current']['remote_subtype']);
			unset ($ret['current']['remote_port']);
			break;
		default:
		}
	}
	unset ($ret['current']);
	return $ret;
}

function ios12ReadVLANConfig ($input)
{
	$ret = array
	(
		'vlanlist' => array(),
		'portdata' => array(),
		'portconfig' => array(),
	);
	global $breedfunc;
	$nextfunc = 'ios12-get8021q-top';
	foreach (explode ("\n", $input) as $line)
		$nextfunc = $breedfunc[$nextfunc] ($ret, $line);
	return $ret;
}

function ios12ScanTopLevel (&$work, $line)
{
	$matches = array();
	switch (TRUE)
	{
	case (preg_match ('@^interface ((Ethernet|FastEthernet|GigabitEthernet|TenGigabitEthernet|Port-channel)[[:digit:]]+(/[[:digit:]]+)*)$@', $line, $matches)):
		$port_name = ios12ShortenIfName ($matches[1]);
		$work['current'] = array ('port_name' => $port_name);
		$work['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
		return 'ios12-get8021q-readport'; // switch to interface block reading
	case (preg_match ('/^VLAN Name                             Status    Ports$/', $line, $matches)):
		return 'ios12-get8021q-readvlan';
	default:
		return 'ios12-get8021q-top'; // continue scan
	}
}

function ios12PickSwitchportCommand (&$work, $line)
{
	$port_name = $work['current']['port_name'];
	if ($line[0] != ' ') // end of interface section
	{
		$work['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
		// save work, if it makes sense
		switch (@$work['current']['mode'])
		{
		case 'access':
			if (!array_key_exists ('access vlan', $work['current']))
				$work['current']['access vlan'] = 1;
			$work['portdata'][$port_name] = array
			(
				'mode' => 'access',
				'allowed' => array ($work['current']['access vlan']),
				'native' => $work['current']['access vlan'],
			);
			break;
		case 'trunk':
			if (!array_key_exists ('trunk native vlan', $work['current']))
				$work['current']['trunk native vlan'] = 1;
			if (!array_key_exists ('trunk allowed vlan', $work['current']))
				$work['current']['trunk allowed vlan'] = range (VLAN_MIN_ID, VLAN_MAX_ID);
			// Having configured VLAN as "native" doesn't mean anything
			// as long as it's not listed on the "allowed" line.
			$effective_native = in_array
			(
				$work['current']['trunk native vlan'],
				$work['current']['trunk allowed vlan']
			) ? $work['current']['trunk native vlan'] : 0;
			$work['portdata'][$port_name] = array
			(
				'mode' => 'trunk',
				'allowed' => $work['current']['trunk allowed vlan'],
				'native' => $effective_native,
			);
			break;
		case 'SKIP':
			break;
		case 'IP':
		default:
			// dot1q-tunnel, dynamic, private-vlan or even none --
			// show in returned config and let user decide, if they
			// want to fix device config or work around these ports
			// by means of VST.
			$work['portdata'][$port_name] = array
			(
				'mode' => 'none',
				'allowed' => array(),
				'native' => 0,
			);
			break;
		}
		unset ($work['current']);
		return 'ios12-get8021q-top';
	}
	// not yet
	$matches = array();
	$line_class = 'line-8021q';
	switch (TRUE)
	{
	case (preg_match ('@^\s*switchport\s*$@', $line, $matches)):
		// treat switchport-only interfaces as access ports by default
		if (! isset ($work['current']['mode']))
			$work['current']['mode'] = 'access';
		break;
	case (preg_match ('@^ switchport mode (.+)$@', $line, $matches)):
		$work['current']['mode'] = $matches[1];
		break;
	case (preg_match ('@^ switchport access vlan (.+)$@', $line, $matches)):
		$work['current']['access vlan'] = $matches[1];
		break;
	case (preg_match ('@^ switchport trunk native vlan (.+)$@', $line, $matches)):
		$work['current']['trunk native vlan'] = $matches[1];
		break;
	case (preg_match ('@^ switchport trunk allowed vlan add (.+)$@', $line, $matches)):
		$work['current']['trunk allowed vlan'] = array_merge
		(
			$work['current']['trunk allowed vlan'],
			iosParseVLANString ($matches[1])
		);
		break;
	case (preg_match ('@^ switchport trunk allowed vlan (.+)$@', $line, $matches)):
		$work['current']['trunk allowed vlan'] = iosParseVLANString ($matches[1]);
		break;
	case preg_match ('@^ channel-group @', $line):
	// port-channel subinterface config follows that of the master interface
		$work['current']['mode'] = 'SKIP';
		break;
	case preg_match ('@^ ip address @', $line):
	// L3 interface does no switchport functions
		$work['current']['mode'] = 'IP';
		break;
	default: // suppress warning on irrelevant config clause
		$line_class = 'line-other';
	}
	$work['portconfig'][$port_name][] = array ('type' => $line_class, 'line' => $line);
	return 'ios12-get8021q-readport';
}

function ios12PickVLANCommand (&$work, $line)
{
	$matches = array();
	switch (TRUE)
	{
	case ($line == '---- -------------------------------- --------- -------------------------------'):
		// ignore the rest of VLAN table header;
		break;
	case (preg_match ('@! END OF VLAN LIST$@', $line)):
		return 'ios12-get8021q-top';
	case (preg_match ('@^([[:digit:]]+) {1,4}.{32} active    @', $line, $matches)):
		if (!array_key_exists ($matches[1], $work['vlanlist']))
			$work['vlanlist'][] = $matches[1];
		break;
	default:
	}
	return 'ios12-get8021q-readvlan';
}

// Another finite automata to read a dialect of Foundry configuration.
function fdry5ReadVLANConfig ($input)
{
	$ret = array
	(
		'vlanlist' => array(),
		'portdata' => array(),
		'portconfig' => array(),
	);
	global $breedfunc;
	$nextfunc = 'fdry5-get8021q-top';
	foreach (explode ("\n", $input) as $line)
		$nextfunc = $breedfunc[$nextfunc] ($ret, $line);
	return $ret;
}

function fdry5ScanTopLevel (&$work, $line)
{
	$matches = array();
	switch (TRUE)
	{
	case (preg_match ('@^vlan ([[:digit:]]+)( name .+)? (by port)$@', $line, $matches)):
		if (!array_key_exists ($matches[1], $work['vlanlist']))
			$work['vlanlist'][] = $matches[1];
		$work['current'] = array ('vlan_id' => $matches[1]);
		return 'fdry5-get8021q-readvlan';
	case (preg_match ('@^interface ethernet ([[:digit:]]+/[[:digit:]]+/[[:digit:]]+)$@', $line, $matches)):
		$port_name = 'e' . $matches[1];
		$work['current'] = array ('port_name' => $port_name);
		$work['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
		return 'fdry5-get8021q-readport';
	default:
		return 'fdry5-get8021q-top';
	}
}

function fdry5PickVLANSubcommand (&$work, $line)
{
	if ($line[0] != ' ') // end of VLAN section
	{
		unset ($work['current']);
		return 'fdry5-get8021q-top';
	}
	// not yet
	$matches = array();
	switch (TRUE)
	{
	case (preg_match ('@^ tagged (.+)$@', $line, $matches)):
		// add current VLAN to 'allowed' list of each mentioned port
		foreach (fdry5ParsePortString ($matches[1]) as $port_name)
			if (array_key_exists ($port_name, $work['portdata']))
				$work['portdata'][$port_name]['allowed'][] = $work['current']['vlan_id'];
			else
				$work['portdata'][$port_name] = array
				(
					'mode' => 'trunk',
					'allowed' => array ($work['current']['vlan_id']),
					'native' => 0, // can be updated later
				);
			$work['portdata'][$port_name]['mode'] = 'trunk';
		break;
	case (preg_match ('@^ untagged (.+)$@', $line, $matches)):
		// replace 'native' column of each mentioned port with current VLAN ID
		foreach (fdry5ParsePortString ($matches[1]) as $port_name)
		{
			if (array_key_exists ($port_name, $work['portdata']))
			{
				$work['portdata'][$port_name]['native'] = $work['current']['vlan_id'];
				$work['portdata'][$port_name]['allowed'][] = $work['current']['vlan_id'];
			}
			else
				$work['portdata'][$port_name] = array
				(
					'mode' => 'access',
					'allowed' => array ($work['current']['vlan_id']),
					'native' => $work['current']['vlan_id'],
				);
			// Untagged ports are initially assumed to be access ports, and
			// when this assumption is right, this is the final port mode state.
			// When the port is dual-mode one, this is detected and justified
			// later in "interface" section of config text.
			$work['portdata'][$port_name]['mode'] = 'access';
		}
		break;
	default: // nom-nom
	}
	return 'fdry5-get8021q-readvlan';
}

function fdry5PickInterfaceSubcommand (&$work, $line)
{
	$port_name = $work['current']['port_name'];
	if ($line[0] != ' ') // end of interface section
	{
		$work['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
		if (array_key_exists ('dual-mode', $work['current']))
		{
			if (array_key_exists ($port_name, $work['portdata']))
				// update existing record
				$work['portdata'][$port_name]['native'] = $work['current']['dual-mode'];
			else
				// add new
				$work['portdata'][$port_name] = array
				(
					'allowed' => array ($work['current']['dual-mode']),
					'native' => $work['current']['dual-mode'],
				);
			// a dual-mode port is always considered a trunk port
			// (but not in the IronWare's meaning of "trunk") regardless of
			// number of assigned tagged VLANs
			$work['portdata'][$port_name]['mode'] = 'trunk';
		}
		unset ($work['current']);
		return 'fdry5-get8021q-top';
	}
	$matches = array();
	switch (TRUE)
	{
	case (preg_match ('@^ dual-mode( +[[:digit:]]+ *)?$@', $line, $matches)):
		// default VLAN ID for dual-mode command is 1
		$work['current']['dual-mode'] = strlen (trim ($matches[1])) ? trim ($matches[1]) : 1;
		break;
	// FIXME: trunk/link-aggregate/ip address pulls port from 802.1Q field
	default: // nom-nom
	}
	$work['portconfig'][$port_name][] = array ('type' => 'line-other', 'line' => $line);
	return 'fdry5-get8021q-readport';
}

function fdry5ParsePortString ($string)
{
	$ret = array();
	$tokens = explode (' ', trim ($string));
	while (count ($tokens))
	{
		$letters = array_shift ($tokens); // "ethe", "to"
		$numbers = array_shift ($tokens); // "x", "x/x", "x/x/x"
		switch ($letters)
		{
		case 'ethe':
			if ($prev_numbers != NULL)
				$ret[] = 'e' . $prev_numbers;
			$prev_numbers = $numbers;
			break;
		case 'to':
			$ret = array_merge ($ret, fdry5GenPortRange ($prev_numbers, $numbers));
			$prev_numbers = NULL; // no action on next token
			break;
		default: // ???
			return array();
		}
	}
	// flush delayed item
	if ($prev_numbers != NULL)
		$ret[] = 'e' . $prev_numbers;
	return $ret;
}

// Take two indices in form "x", "x/x" or "x/x/x" and return the range of
// ports spanning from the first to the last. The switch software makes it
// easier to perform, because "ethe x/x/x to y/y/y" ranges never cross
// unit/slot boundary (every index except the last remains constant).
function fdry5GenPortRange ($from, $to)
{
	$matches = array();
	if (1 !== preg_match ('@^([[:digit:]]+/)?([[:digit:]]+/)?([[:digit:]]+)$@', $from, $matches))
		return array();
	$prefix = 'e' . $matches[1] . $matches[2];
	$from_idx = $matches[3];
	if (1 !== preg_match ('@^([[:digit:]]+/)?([[:digit:]]+/)?([[:digit:]]+)$@', $to, $matches))
		return array();
	$to_idx = $matches[3];
	for ($i = $from_idx; $i <= $to_idx; $i++)
		$ret[] = $prefix . $i;
	return $ret;
}

// an implementation for Huawei syntax
function vrp53ReadVLANConfig ($input)
{
	$ret = array
	(
		'vlanlist' => array(),
		'portdata' => array(),
		'portconfig' => array(),
	);
	global $breedfunc;
	$nextfunc = 'vrp53-get8021q-top';
	foreach (explode ("\n", $input) as $line)
		$nextfunc = $breedfunc[$nextfunc] ($ret, $line);
	return $ret;
}

function vrp53ScanTopLevel (&$work, $line)
{
	$matches = array();
	switch (TRUE)
	{
	case (preg_match ('@^ vlan batch (.+)$@', $line, $matches)):
		foreach (vrp53ParseVLANString ($matches[1]) as $vlan_id)
			$work['vlanlist'][] = $vlan_id;
		return 'vrp53-get8021q-top';
	case (preg_match ('@^interface ((GigabitEthernet|XGigabitEthernet|Eth-Trunk)([[:digit:]]+(/[[:digit:]]+)*))$@', $line, $matches)):
		$port_name = ios12ShortenIfName ($matches[1]);
		$work['current'] = array ('port_name' => $port_name);
		$work['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
		return 'vrp53-get8021q-readport';
	default:
		return 'vrp53-get8021q-top';
	}
}

function vrp53ParseVLANString ($string)
{
	$string = preg_replace ('/ to /', '-', $string);
	$string = preg_replace ('/ /', ',', $string);
	return iosParseVLANString ($string);
}

function vrp53PickInterfaceSubcommand (&$work, $line)
{
	$port_name = $work['current']['port_name'];
	if ($line[0] == '#') // end of interface section
	{
		$work['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
		// Configuration Guide - Ethernet 3.3.4:
		// "By default, the interface type is hybrid."
		if (!array_key_exists ('link-type', $work['current']))
			$work['current']['link-type'] = 'hybrid';
		if (!array_key_exists ('allowed', $work['current']))
			$work['current']['allowed'] = array();
		if (!array_key_exists ('native', $work['current']))
			$work['current']['native'] = 0;
		switch ($work['current']['link-type'])
		{
		case 'access':
			// VRP does not assign access ports to VLAN1 by default,
			// leaving them blocked.
			$work['portdata'][$port_name] =
				$work['current']['native'] ? array
				(
					'allowed' => $work['current']['allowed'],
					'native' => $work['current']['native'],
					'mode' => 'access',
				) : array
				(
					'mode' => 'none',
					'allowed' => array(),
					'native' => 0,
				);
			break;
		case 'trunk':
			$work['portdata'][$port_name] = array
			(
				'allowed' => $work['current']['allowed'],
				'native' => 0,
				'mode' => 'trunk',
			);
			break;
		case 'hybrid':
			$work['portdata'][$port_name] = array
			(
				'allowed' => $work['current']['allowed'],
				'native' => $work['current']['native'],
				'mode' => 'trunk',
			);
			break;
		case 'SKIP':
		default: // dot1q-tunnel ?
		}
		unset ($work['current']);
		return 'vrp53-get8021q-top';
	}
	$matches = array();
	$line_class = 'line-8021q';
	switch (TRUE)
	{
	case (preg_match ('@^ port default vlan ([[:digit:]]+)$@', $line, $matches)):
		$work['current']['native'] = $matches[1];
		if (!array_key_exists ('allowed', $work['current']))
			$work['current']['allowed'] = array();
		if (!in_array ($matches[1], $work['current']['allowed']))
			$work['current']['allowed'][] = $matches[1];
		break;
	case (preg_match ('@^ port link-type (.+)$@', $line, $matches)):
		$work['current']['link-type'] = $matches[1];
		break;
	case (preg_match ('@^ port trunk allow-pass vlan (.+)$@', $line, $matches)):
		if (!array_key_exists ('allowed', $work['current']))
			$work['current']['allowed'] = array();
		foreach (vrp53ParseVLANString ($matches[1]) as $vlan_id)
			if (!in_array ($vlan_id, $work['current']['allowed']))
				$work['current']['allowed'][] = $vlan_id;
		break;
	case preg_match ('/^\s*eth-trunk \d+/', $line):
		$work['current']['link-type'] = 'SKIP';
		break;
	default: // nom-nom
		$line_class = 'line-other';
	}
	$work['portconfig'][$port_name][] = array('type' => $line_class, 'line' => $line);
	return 'vrp53-get8021q-readport';
}

function vrp55Read8021QConfig ($input)
{
	$ret = array
	(
		'vlanlist' => array (1), // VRP 5.50 hides VLAN1 from config text
		'portdata' => array(),
		'portconfig' => array(),
	);
	foreach (explode ("\n", $input) as $line)
	{
		$matches = array();
		// top level
		if (!array_key_exists ('current', $ret))
		{
			switch (TRUE)
			{
			case (preg_match ('@^ vlan batch (.+)$@', $line, $matches)):
				foreach (vrp53ParseVLANString ($matches[1]) as $vlan_id)
					$ret['vlanlist'][] = $vlan_id;
				break;
			case (preg_match ('@^interface ((GigabitEthernet|XGigabitEthernet|Eth-Trunk)([[:digit:]]+(/[[:digit:]]+)*))$@', $line, $matches)):
				$port_name = ios12ShortenIfName ($matches[1]);
				$ret['current'] = array ('port_name' => $port_name);
				$ret['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
				break;
			}
			continue;
		}
		$port_name = $ret['current']['port_name'];
		// inside an interface block
		$line_class = 'line-8021q';
		switch (TRUE)
		{
		case preg_match ('/^ port (link-type )?hybrid /', $line):
			throw new RTGatewayError ("unsupported hybrid link-type for $port_name: ${line}");
		case preg_match ('/^ port link-type (.+)$/', $line, $matches):
			$ret['current']['link-type'] = $matches[1];
			break;
		// Native VLAN is configured differently for each link-type case, but
		// VRP is known to filter off clauses, which don't make sense for
		// current link-type. This way any interface section should contain
		// only one kind of "set native" clause (but if this constraint breaks,
		// we get a problem).
		case preg_match ('/^ port (default|trunk pvid) vlan ([[:digit:]]+)$/', $line, $matches):
			$ret['current']['native'] = $matches[2];
			if (!array_key_exists ('allowed', $ret['current']))
				$ret['current']['allowed'] = array();
			if (!in_array ($ret['current']['native'], $ret['current']['allowed']))
				$ret['current']['allowed'][] = $ret['current']['native'];
			break;
		case preg_match ('/^ port trunk allow-pass vlan (.+)$/', $line, $matches):
			if (!array_key_exists ('allowed', $ret['current']))
				$ret['current']['allowed'] = array();
			foreach (vrp53ParseVLANString ($matches[1]) as $vlan_id)
				if (!in_array ($vlan_id, $ret['current']['allowed']))
					$ret['current']['allowed'][] = $vlan_id;
			break;
		case $line == ' undo portswitch':
		case preg_match ('/^ ip address /', $line):
		case preg_match ('/^ service type /', $line):
			$ret['current']['link-type'] = 'IP';
			break;
		case preg_match ('/^ eth-trunk /', $line):
			$ret['current']['link-type'] = 'SKIP';
			break;
		case substr ($line, 0, 1) == '#': // end of interface section
			$line_class = 'line-header';
			if (!array_key_exists ('link-type', $ret['current']))
				$ret['current']['link-type'] = 'hybrid';
			if (!array_key_exists ('allowed', $ret['current']))
				$ret['current']['allowed'] = array();
			if (!array_key_exists ('native', $ret['current']))
				$ret['current']['native'] = 0;
			switch ($ret['current']['link-type'])
			{
			case 'access':
				// In VRP 5.50 an access port has default VLAN ID == 1
				$ret['portdata'][$port_name] =
					$ret['current']['native'] ? array
					(
						'mode' => 'access',
						'allowed' => $ret['current']['allowed'],
						'native' => $ret['current']['native'],
					) : array
					(
						'mode' => 'access',
						'allowed' => array (VLAN_DFL_ID),
						'native' => VLAN_DFL_ID,
					);
				break;
			case 'trunk':
				$ret['portdata'][$port_name] = array
				(
					'mode' => 'trunk',
					'allowed' => $ret['current']['allowed'],
					'native' => $ret['current']['native'],
				);
				break;
			case 'hybrid': // hybrid ports are not supported
			case 'IP':
				$ret['portdata'][$port_name] = array
				(
					'mode' => 'none',
					'allowed' => array(),
					'native' => 0,
				);
				break;
			case 'SKIP':
			default: // dot1q-tunnel ?
			}
			unset ($ret['current']);
			break;
		default: // nom-nom
			$line_class = 'line-other';
		}
		$ret['portconfig'][$port_name][] = array ('type' => $line_class, 'line' => $line);
	}
	return $ret;
}

function nxos4Read8021QConfig ($input)
{
	$ret = array
	(
		'vlanlist' => array(),
		'portdata' => array(),
		'portconfig' => array(),
	);
	global $breedfunc;
	$nextfunc = 'nxos4-get8021q-top';
	foreach (explode ("\n", $input) as $line)
		$nextfunc = $breedfunc[$nextfunc] ($ret, $line);
	return $ret;
}

function nxos4ScanTopLevel (&$work, $line)
{
	$matches = array();
	switch (TRUE)
	{
	case (preg_match ('@^interface ((Ethernet|Port-channel)[[:digit:]]+(/[[:digit:]]+)*)$@i', $line, $matches)):
		$port_name = ios12ShortenIfName ($matches[1]);
		$work['current'] = array ('port_name' => $port_name);
		$work['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
		return 'nxos4-get8021q-readport';
	case (preg_match ('@^vlan (.+)$@', $line, $matches)):
		foreach (iosParseVLANString ($matches[1]) as $vlan_id)
			$work['vlanlist'][] = $vlan_id;
		return 'nxos4-get8021q-top';
	default:
		return 'nxos4-get8021q-top'; // continue scan
	}
}

function nxos4PickSwitchportCommand (&$work, $line)
{
	$port_name = $work['current']['port_name'];
	if ($line == '') // end of interface section
	{
		$work['portconfig'][$port_name][] = array ('type' => 'line-header', 'line' => $line);
		// fill in defaults
		if (!array_key_exists ('mode', $work['current']))
			$work['current']['mode'] = 'access';
		// save work, if it makes sense
		switch ($work['current']['mode'])
		{
		case 'access':
			if (!array_key_exists ('access vlan', $work['current']))
				$work['current']['access vlan'] = 1;
			$work['portdata'][$port_name] = array
			(
				'mode' => 'access',
				'allowed' => array ($work['current']['access vlan']),
				'native' => $work['current']['access vlan'],
			);
			break;
		case 'trunk':
			if (!array_key_exists ('trunk native vlan', $work['current']))
				$work['current']['trunk native vlan'] = 1;
			// FIXME: NX-OS reserves VLANs 3968 through 4047 plus 4094 for itself
			if (!array_key_exists ('trunk allowed vlan', $work['current']))
				$work['current']['trunk allowed vlan'] = range (VLAN_MIN_ID, VLAN_MAX_ID);
			// Having configured VLAN as "native" doesn't mean anything
			// as long as it's not listed on the "allowed" line.
			$effective_native = in_array
			(
				$work['current']['trunk native vlan'],
				$work['current']['trunk allowed vlan']
			) ? $work['current']['trunk native vlan'] : 0;
			$work['portdata'][$port_name] = array
			(
				'mode' => 'trunk',
				'allowed' => $work['current']['trunk allowed vlan'],
				'native' => $effective_native,
			);
			break;
		case 'SKIP':
		case 'fex-fabric': // associated port-channel
			break;
		default:
			// dot1q-tunnel, dynamic, private-vlan
			$work['portdata'][$port_name] = array
			(
				'mode' => 'none',
				'allowed' => array(),
				'native' => 0,
			);
			// unset (routed), dot1q-tunnel, dynamic, private-vlan --- skip these
		}
		unset ($work['current']);
		return 'nxos4-get8021q-top';
	}
	// not yet
	$matches = array();
	$line_class = 'line-8021q';
	switch (TRUE)
	{
	case (preg_match ('@^  switchport mode (.+)$@', $line, $matches)):
		$work['current']['mode'] = $matches[1];
		break;
	case (preg_match ('@^  switchport access vlan (.+)$@', $line, $matches)):
		$work['current']['access vlan'] = $matches[1];
		break;
	case (preg_match ('@^  switchport trunk native vlan (.+)$@', $line, $matches)):
		$work['current']['trunk native vlan'] = $matches[1];
		break;
	case (preg_match ('@^  switchport trunk allowed vlan add (.+)$@', $line, $matches)):
		$work['current']['trunk allowed vlan'] = array_merge
		(
			$work['current']['trunk allowed vlan'],
			iosParseVLANString ($matches[1])
		);
		break;
	case (preg_match ('@^  switchport trunk allowed vlan (.+)$@', $line, $matches)):
		$work['current']['trunk allowed vlan'] = iosParseVLANString ($matches[1]);
		break;
	case preg_match ('/^ +channel-group /', $line):
		$work['current']['mode'] = 'SKIP';
		break;
	default: // suppress warning on irrelevant config clause
		$line_class = 'line-other';
	}
	$work['portconfig'][$port_name][] = array ('type' => $line_class, 'line' => $line);
	return 'nxos4-get8021q-readport';
}

// most of the commands are compatible with IOS12, so are generated by ios12TranslatePushQueue
// Only Nexus-specific commands are generated here (eg., lldp)
function nxos4TranslatePushQueue ($dummy_object_id, $queue, $dummy_vlan_names)
{
	$ret = '';

	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
		case 'getlldpstatus':
			$ret .= "show lldp neighbors detail\n";
			break;
		default:
			$ret .= ios12TranslatePushQueue ($dummy_object_id, array ($cmd), $dummy_vlan_names);
			break;
		}
	return $ret;
}

// Get a list of VLAN management pseudo-commands and return a text
// of real vendor-specific commands, which implement the work.
// This work is done in two rounds:
// 1. For "add allowed" and "rem allowed" commands detect continuous
//    sequences of VLAN IDs and replace them with ranges of form "A-B",
//    where B>A.
// 2. Iterate over the resulting list and produce real CLI commands.
function ios12TranslatePushQueue ($dummy_object_id, $queue, $dummy_vlan_names)
{
	$ret = '';
	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
		case 'create VLAN':
			$ret .= "vlan ${cmd['arg1']}\nexit\n";
			break;
		case 'destroy VLAN':
			$ret .= "no vlan ${cmd['arg1']}\n";
			break;
		case 'add allowed':
		case 'rem allowed':
			$clause = $cmd['opcode'] == 'add allowed' ? 'add' : 'remove';
			$ret .= "interface ${cmd['port']}\n";
			foreach (listToRanges ($cmd['vlans']) as $range)
				$ret .= "switchport trunk allowed vlan ${clause} " .
					($range['from'] == $range['to'] ? $range['to'] : "${range['from']}-${range['to']}") .
					"\n";
			$ret .= "exit\n";
			break;
		case 'set native':
			$ret .= "interface ${cmd['arg1']}\nswitchport trunk native vlan ${cmd['arg2']}\nexit\n";
			break;
		case 'unset native':
			$ret .= "interface ${cmd['arg1']}\nno switchport trunk native vlan ${cmd['arg2']}\nexit\n";
			break;
		case 'set access':
			$ret .= "interface ${cmd['arg1']}\nswitchport access vlan ${cmd['arg2']}\nexit\n";
			break;
		case 'unset access':
			$ret .= "interface ${cmd['arg1']}\nno switchport access vlan\nexit\n";
			break;
		case 'set mode':
			$ret .= "interface ${cmd['arg1']}\n";
			if ($cmd['arg2'] == 'trunk')
				$ret .= "switchport trunk encapsulation dot1q\n";
			$ret .= "switchport mode ${cmd['arg2']}\n";
			if ($cmd['arg2'] == 'trunk')
				$ret .= "no switchport trunk native vlan\nswitchport trunk allowed vlan none\n";
			$ret .= "exit\n";
			break;
		case 'begin configuration':
			$ret .= "configure terminal\n";
			break;
		case 'end configuration':
			$ret .= "end\n";
			break;
		case 'save configuration':
			$ret .= "copy running-config startup-config\n\n";
			break;
		case 'cite':
			$ret .= $cmd['arg1'];
			break;
		// query list
		case 'get8021q':
			$ret .=
'show run
! END OF CONFIG
show vlan brief
! END OF VLAN LIST
';
			break;
		case 'getcdpstatus':
			$ret .= "show cdp neighbors detail\n";
			break;
		case 'getlldpstatus':
			$ret .= "show lldp neighbors\n";
			break;
		case 'getportstatus':
			$ret .= "show int status\n";
			break;
		case 'getmaclist':
			$ret .= "show mac address-table dynamic\n";
			break;
		default:
			throw new InvalidArgException ('opcode', $cmd['opcode']);
		}
	return $ret;
}

function fdry5TranslatePushQueue ($dummy_object_id, $queue, $dummy_vlan_names)
{
	$ret = '';
	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
		case 'create VLAN':
			$ret .= "vlan ${cmd['arg1']}\nexit\n";
			break;
		case 'destroy VLAN':
			$ret .= "no vlan ${cmd['arg1']}\n";
			break;
		case 'add allowed':
			foreach ($cmd['vlans'] as $vlan_id)
				$ret .= "vlan ${vlan_id}\ntagged ${cmd['port']}\nexit\n";
			break;
		case 'rem allowed':
			foreach ($cmd['vlans'] as $vlan_id)
				$ret .= "vlan ${vlan_id}\nno tagged ${cmd['port']}\nexit\n";
			break;
		case 'set native':
			$ret .= "interface ${cmd['arg1']}\ndual-mode ${cmd['arg2']}\nexit\n";
			break;
		case 'unset native':
			$ret .= "interface ${cmd['arg1']}\nno dual-mode ${cmd['arg2']}\nexit\n";
			break;
		case 'set access':
			$ret .= "vlan ${cmd['arg2']}\nuntagged ${cmd['arg1']}\nexit\n";
			break;
		case 'unset access':
			$ret .= "vlan ${cmd['arg2']}\nno untagged ${cmd['arg1']}\nexit\n";
			break;
		case 'set mode': // NOP
			break;
		case 'begin configuration':
			$ret .= "conf t\n";
			break;
		case 'end configuration':
			$ret .= "end\n";
			break;
		case 'save configuration':
			$ret .= "write memory\n";
			break;
		case 'cite':
			$ret .= $cmd['arg1'];
			break;
		// query list
		case 'get8021q':
			$ret .= "show running-config\n";
			break;
		default:
			throw new InvalidArgException ('opcode', $cmd['opcode']);
		}
	return $ret;
}

function vrp53TranslatePushQueue ($dummy_object_id, $queue, $dummy_vlan_names)
{
	$ret = '';
	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
		case 'create VLAN':
			$ret .= "vlan ${cmd['arg1']}\nquit\n";
			break;
		case 'destroy VLAN':
			$ret .= "undo vlan ${cmd['arg1']}\n";
			break;
		case 'add allowed':
		case 'rem allowed':
			$clause = $cmd['opcode'] == 'add allowed' ? '' : 'undo ';
			$ret .= "interface ${cmd['port']}\n";
			foreach (listToRanges ($cmd['vlans']) as $range)
				$ret .=  "${clause}port trunk allow-pass vlan " .
					($range['from'] == $range['to'] ? $range['to'] : "${range['from']} to ${range['to']}") .
					"\n";
			$ret .= "quit\n";
			break;
		case 'set native':
		case 'set access':
			$ret .= "interface ${cmd['arg1']}\nport default vlan ${cmd['arg2']}\nquit\n";
			break;
		case 'unset native':
		case 'unset access':
			$ret .= "interface ${cmd['arg1']}\nundo port default vlan\nquit\n";
			break;
		case 'set mode':
			$modemap = array ('access' => 'access', 'trunk' => 'hybrid');
			$ret .= "interface ${cmd['arg1']}\nport link-type " . $modemap[$cmd['arg2']] . "\n";
			if ($cmd['arg2'] == 'hybrid')
				$ret .= "undo port default vlan\nundo port trunk allow-pass vlan all\n";
			$ret .= "quit\n";
			break;
		case 'begin configuration':
			$ret .= "system-view\n";
			break;
		case 'end configuration':
			$ret .= "return\n";
			break;
		case 'save configuration':
			$ret .= "save\nY\n";
			break;
		case 'cite':
			$ret .= $cmd['arg1'];
			break;
		// query list
		case 'get8021q':
			$ret .= "display current-configuration\n";
			break;
		case 'getlldpstatus':
			$ret .= "display lldp neighbor\n";
			break;
		case 'getportstatus':
			$ret .= "display interface brief\n";
			break;
		case 'getmaclist':
			$ret .= "display mac-address dynamic\n";
			break;
		default:
			throw new InvalidArgException ('opcode', $cmd['opcode']);
		}
	return $ret;
}

function vrp55TranslatePushQueue ($dummy_object_id, $queue, $dummy_vlan_names)
{
	$ret = '';
	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
		case 'create VLAN':
			if ($cmd['arg1'] != 1)
				$ret .= "vlan ${cmd['arg1']}\nquit\n";
			break;
		case 'destroy VLAN':
			if ($cmd['arg1'] != 1)
				$ret .= "undo vlan ${cmd['arg1']}\n";
			break;
		case 'add allowed':
		case 'rem allowed':
			$undo = $cmd['opcode'] == 'add allowed' ? '' : 'undo ';
			$ret .= "interface ${cmd['port']}\n";
			foreach (listToRanges ($cmd['vlans']) as $range)
				$ret .=  "${undo}port trunk allow-pass vlan " .
					($range['from'] == $range['to'] ? $range['to'] : "${range['from']} to ${range['to']}") .
					"\n";
			$ret .= "quit\n";
			break;
		case 'set native':
			$ret .= "interface ${cmd['arg1']}\nport trunk pvid vlan ${cmd['arg2']}\nquit\n";
			break;
		case 'set access':
			$ret .= "interface ${cmd['arg1']}\nport default vlan ${cmd['arg2']}\nquit\n";
			break;
		case 'unset native':
			$ret .= "interface ${cmd['arg1']}\nundo port trunk pvid vlan\nquit\n";
			break;
		case 'unset access':
			$ret .= "interface ${cmd['arg1']}\nundo port default vlan\nquit\n";
			break;
		case 'set mode':
			// VRP 5.50's meaning of "trunk" is much like the one of IOS
			// (unlike the way VRP 5.30 defines "trunk" and "hybrid"),
			// but it is necessary to undo configured VLANs on a port
			// for mode change command to succeed.
			$undo = array
			(
				'access' => "undo port trunk allow-pass vlan all\n" .
					"port trunk allow-pass vlan 1\n" .
					"undo port trunk pvid vlan\n",
				'trunk' => "undo port default vlan\n",
			);
			$ret .= "interface ${cmd['arg1']}\n" . $undo[$cmd['arg2']];
			$ret .= "port link-type ${cmd['arg2']}\nquit\n";
			break;
		case 'begin configuration':
			$ret .= "system-view\n";
			break;
		case 'end configuration':
			$ret .= "return\n";
			break;
		case 'save configuration':
			$ret .= "save\nY\n";
			break;
		case 'cite':
			$ret .= $cmd['arg1'];
			break;
		// query list
		case 'get8021q':
			$ret .= "display current-configuration\n";
			break;
		case 'getlldpstatus':
			$ret .= "display lldp neighbor\n";
			break;
		case 'getportstatus':
			$ret .= "display interface brief\n";
			break;
		case 'getmaclist':
			$ret .= "display mac-address dynamic\n";
			break;
		default:
			throw new InvalidArgException ('opcode', $cmd['opcode']);
		}
	return $ret;
}

function xos12TranslatePushQueue ($dummy_object_id, $queue, $dummy_vlan_names)
{
	$ret = '';
	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
		case 'create VLAN':
			$ret .= "create vlan VLAN${cmd['arg1']}\n";
			$ret .= "configure vlan VLAN${cmd['arg1']} tag ${cmd['arg1']}\n";
			break;
		case 'destroy VLAN':
			$ret .= "delete vlan VLAN${cmd['arg1']}\n";
			break;
		case 'add allowed':
			foreach ($cmd['vlans'] as $vlan_id)
			{
				$vlan_name = $vlan_id == 1 ? 'Default' : "VLAN${vlan_id}";
				$ret .= "configure vlan ${vlan_name} add ports ${cmd['port']} tagged\n";
			}
			break;
		case 'rem allowed':
			foreach ($cmd['vlans'] as $vlan_id)
			{
				$vlan_name = $vlan_id == 1 ? 'Default' : "VLAN${vlan_id}";
				$ret .= "configure vlan ${vlan_name} delete ports ${cmd['port']}\n";
			}
			break;
		case 'set native':
			$vlan_name = $cmd['arg2'] == 1 ? 'Default' : "VLAN${cmd['arg2']}";
			$ret .= "configure vlan ${vlan_name} delete ports ${cmd['arg1']}\n";
			$ret .= "configure vlan ${vlan_name} add ports ${cmd['arg1']} untagged\n";
			break;
		case 'unset native':
			$vlan_name = $cmd['arg2'] == 1 ? 'Default' : "VLAN${cmd['arg2']}";
			$ret .= "configure vlan ${vlan_name} delete ports ${cmd['arg1']}\n";
			$ret .= "configure vlan ${vlan_name} add ports ${cmd['arg1']} tagged\n";
			break;
		case 'set access':
			$vlan_name = $cmd['arg2'] == 1 ? 'Default' : "VLAN${cmd['arg2']}";
			$ret .= "configure vlan ${vlan_name} add ports ${cmd['arg1']} untagged\n";
			break;
		case 'unset access':
			$vlan_name = $cmd['arg2'] == 1 ? 'Default' : "VLAN${cmd['arg2']}";
			$ret .= "configure vlan ${vlan_name} delete ports ${cmd['arg1']}\n";
			break;
		case 'set mode':
		case 'begin configuration':
		case 'end configuration':
			break; // NOP
		case 'save configuration':
			$ret .= "save configuration\ny\n";
			break;
		case 'cite':
			$ret .= $cmd['arg1'];
			break;
		// query list
		case 'get8021q':
			$ret .= 'show configuration "vlan"' . "\n";
			break;
		case 'getlldpstatus':
			$ret .= "show lldp neighbors detailed\n";
			break;
		default:
			throw new InvalidArgException ('opcode', $cmd['opcode']);
		}
	return $ret;
}

function jun10TranslatePushQueue ($dummy_object_id, $queue, $vlan_names)
{
	$ret = '';

	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
		case 'create VLAN':
			$ret .= "set vlans VLAN${cmd['arg1']} vlan-id ${cmd['arg1']}\n";
			break;
		case 'destroy VLAN':
			if (isset ($vlan_names[$cmd['arg1']]))
				$ret .= "delete vlans " . $vlan_names[$cmd['arg1']] . "\n";
			break;
		case 'add allowed':
		case 'rem allowed':
			$del = ($cmd['opcode'] == 'rem allowed');
			$pre = ($del ? 'delete' : 'set') .
				" interfaces ${cmd['port']} unit 0 family ethernet-switching vlan members";
			if (count ($cmd['vlans']) > VLAN_MAX_ID - VLAN_MIN_ID)
				$ret .= "$pre " . ($del ? '' : 'all') . "\n";
			else
				while (! empty ($cmd['vlans']))
				{
					$vlan = array_shift ($cmd['vlans']);
					$ret .= "$pre $vlan\n";
					if ($del and isset ($vlan_names[$vlan]))
						$ret .= "$pre ${vlan_names[$vlan]}\n";
				}
			break;
		case 'set native':
			$ret .= "set interfaces ${cmd['arg1']} unit 0 family ethernet-switching native-vlan-id ${cmd['arg2']}\n";
			$pre = "delete interfaces ${cmd['arg1']} unit 0 family ethernet-switching vlan members";
			$vlan = $cmd['arg2'];
			$ret .= "$pre $vlan\n";
			if (isset ($vlan_names[$vlan]))
				$ret .= "$pre ${vlan_names[$vlan]}\n";
			break;
		case 'unset native':
			$ret .= "delete interfaces ${cmd['arg1']} unit 0 family ethernet-switching native-vlan-id\n";
			$pre = "interfaces ${cmd['arg1']} unit 0 family ethernet-switching vlan members";
			$vlan = $cmd['arg2'];
			if (isset ($vlan_names[$vlan]))
				$ret .= "delete $pre ${vlan_names[$vlan]}\n";
			$ret .= "set $pre $vlan\n";
			break;
		case 'set access':
			$ret .= "set interfaces ${cmd['arg1']} unit 0 family ethernet-switching vlan members ${cmd['arg2']}\n";
			break;
		case 'unset access':
			$ret .= "delete interfaces ${cmd['arg1']} unit 0 family ethernet-switching vlan members\n";
			break;
		case 'set mode':
			$ret .= "set interfaces ${cmd['arg1']} unit 0 family ethernet-switching port-mode ${cmd['arg2']}\n";
			break;
		case 'begin configuration':
			$ret .= "configure exclusive\n";
			break;
		case 'end configuration':
			$ret .= "commit confirmed 120\n";
			break;
		case 'save configuration':
			break; // JunOS can`t apply configuration without saving it
		case 'cite':
			$ret .= $cmd['arg1'];
			break;
		// query list
		case 'get8021q':
			$ret .=
'show vlans detail
# END OF VLAN LIST
show configuration groups
# END OF GROUP LIST
show configuration interfaces
# END OF CONFIG
';
			break;
		default:
			throw new InvalidArgException ('opcode', $cmd['opcode']);
		}
	return $ret;
}

function ftos8TranslatePushQueue ($dummy_object_id, $queue, $vlan_names)
{
	$ret = '';
	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
		case 'begin configuration':
			$ret .= "configure terminal\n";
			break;
		case 'end configuration':
			$ret .= "end\n";
			break;
		case 'save configuration':
			$ret .= "write memory\n";
			break;
		case 'cite':
			$ret .= $cmd['arg1'];
			break;
		case 'getlldpstatus':
			$ret .= "show lldp neighbors detail\n";
			break;
		case 'getportstatus':
			$ret .= "show interfaces status\n";
			break;
		case 'getmaclist':
			$ret .= "show mac-address-table dynamic\n";
			break;
		case 'get8021q':
			$ret .= "show running-config interface\n";
			break;
		case 'create VLAN':
			$ret .= "int vlan ${cmd['arg1']}\nexit\n";
			break;
		case 'destroy VLAN':
			if (isset ($vlan_names[$cmd['arg1']]))
				$ret .= "no int vlan ${cmd['arg1']}\n";
			break;
		case 'rem allowed':
			while (! empty ($cmd['vlans']))
			{
				$vlan = array_shift ($cmd['vlans']);
				$ret .= "int vlan $vlan\n";
				$ret .= "no tagged ${cmd['port']}\n";
				$ret .= "exit\n";
			}
			break;
		case 'add allowed':
			while (! empty ($cmd['vlans']))
			{
				$vlan = array_shift ($cmd['vlans']);
				$ret .= "int vlan $vlan\n";
				$ret .= "no untagged ${cmd['port']}\n"; // redundant, switch often responses with error
				$ret .= "tagged ${cmd['port']}\n";
				$ret .= "exit\n";
			}
			break;
		case 'unset native':
			$ret .= "int vlan ${cmd['arg2']}\n";
			$ret .= "no untagged ${cmd['arg1']}\n";
			$ret .= "tagged ${cmd['arg1']}\n";
			$ret .= "exit\n";
			break;
		case 'unset access':
			$ret .= "int vlan ${cmd['arg2']}\n";
			$ret .= "no untagged ${cmd['arg1']}\n";
			$ret .= "exit\n";
			break;
		case 'set native':
		case 'set access':
			$ret .= "int vlan ${cmd['arg2']}\n";
			$ret .= "no tagged ${cmd['arg1']}\n"; // redundant, switch often responses with error
			$ret .= "untagged ${cmd['arg1']}\n";
			$ret .= "exit\n";
			break;
		case 'set mode':
			break;
		default:
			throw new InvalidArgException ('opcode', $cmd['opcode']);
		}
	return $ret;
}

function xos12Read8021QConfig ($input)
{
	$ret = array
	(
		'vlanlist' => array (1),
		'portdata' => array(),
		'portconfig' => array(),
	);
	foreach (explode ("\n", $input) as $line)
	{
		$matches = array();
		switch (TRUE)
		{
		case (preg_match ('/^create vlan "([[:alnum:]]+)"$/', $line, $matches)):
			if (!preg_match ('/^VLAN[[:digit:]]+$/', $matches[1]))
				throw new RTGatewayError ('unsupported VLAN name ' . $matches[1]);
			break;
		case (preg_match ('/^configure vlan ([[:alnum:]]+) tag ([[:digit:]]+)$/', $line, $matches)):
			if (strtolower ($matches[1]) == 'default')
				throw new RTGatewayError ('default VLAN tag must be 1');
			if ($matches[1] != 'VLAN' . $matches[2])
				throw new RTGatewayError ("VLAN name ${matches[1]} does not match its tag ${matches[2]}");
			$ret['vlanlist'][] = $matches[2];
			break;
		case (preg_match ('/^configure vlan ([[:alnum:]]+) add ports (.+) (tagged|untagged) */', $line, $matches)):
			$submatch = array();
			if ($matches[1] == 'Default')
				$matches[1] = 'VLAN1';
			if (!preg_match ('/^VLAN([[:digit:]]+)$/', $matches[1], $submatch))
				throw new RTGatewayError ('unsupported VLAN name ' . $matches[1]);
			$vlan_id = $submatch[1];
			foreach (iosParseVLANString ($matches[2]) as $port_name)
			{
				if (!array_key_exists ($port_name, $ret['portdata']))
					$ret['portdata'][$port_name] = array
					(
						'mode' => 'trunk',
						'allowed' => array(),
						'native' => 0,
					);
				$ret['portdata'][$port_name]['allowed'][] = $vlan_id;
				if ($matches[3] == 'untagged')
					$ret['portdata'][$port_name]['native'] = $vlan_id;
			}
			break;
		default:
		}
	}
	return $ret;
}

function jun10Read8021QConfig ($input)
{
	$ret = array
	(
		'vlanlist' => array (1),
		'vlannames' => array (1 => 'default'),
		'portdata' => array(),
		'portconfig' => array(),
	);
	$lines = explode ("\n", $input);
	
	// get vlan list
	$vlans = array('default' => 1);
	$names = array();
	while (count ($lines))
	{
		$line = trim (array_shift ($lines));
		if (FALSE !== strpos ($line, '# END OF VLAN LIST'))
			break;
		if (preg_match ('/^VLAN: (.*), 802.1Q Tag: (\d+)/', $line, $m))
		{
			$ret['vlannames'][$m[2]] = $m[1];
			$vlans[$m[1]] = $m[2];
		}
	}
	$ret['vlanlist'] = array_values	($vlans);

	// get config groups list - throw an exception if a group contains ether-switching config
	$current_group = NULL;
	while (count ($lines))
	{
		$line = array_shift ($lines);
		if (FALSE !== strpos ($line, '# END OF GROUP LIST'))
			break;
		elseif (preg_match ('/^(\S+)(?:\s+{|;)$/', $line, $m))
			$current_group = $m[1];
		elseif (isset ($current_group) and preg_match ('/^\s*family ethernet-switching\b/', $line))
			throw new RTGatewayError ("Config-group '$current_group' contains switchport commands, which is not supported");
	}

	// get interfaces config
	$current = array
	(
		'is_range' => FALSE,
		'is_ethernet' => FALSE,
		'name' => NULL,
		'config' => NULL,
		'indent' => NULL,
	);
	while (count ($lines))
	{
		$line = array_shift ($lines);
		$line_class = 'line-other';
		if (preg_match ('/# END OF CONFIG|^(interface-range )?(\S+)\s+{$/', $line, $m)) // line starts with interface name
		{ // found interface section opening, or end-of-file
			if (isset ($current['name']) and $current['is_ethernet'])
			{ 
				// add previous interface to the results
				if (! isset ($current['config']['mode']))
					$current['config']['mode'] = 'access';
				if (! isset ($current['config']['native']))
					$current['config']['native'] = $current['config']['native'] = 0;
				if (! isset ($current['config']['allowed']))
				{
					if ($current['config']['mode'] == 'access')
						$current['config']['allowed'] = array (1);
					else
						$current['config']['allowed'] = array();
				}
				if (
					$current['config']['mode'] == 'trunk' and
					$current['config']['native'] != 0 and
					! in_array ($current['config']['native'], $current['config']['allowed'])
				)
					$current['config']['allowed'][] = $current['config']['native'];
				elseif ($current['config']['mode'] == 'access')
					$current['config']['native'] = $current['config']['allowed'][0];
				$ret['portdata'][$current['name']] = $current['config'];
			}

			if (! empty ($m[2]))
			{ // new interface section begins
				$current['is_ethernet'] = FALSE;
				$current['is_range'] = ! empty ($m[1]);
				$current['name'] = $m[2];
				$current['config'] = array (
					'mode' => NULL,
					'allowed' => NULL,
					'native' => NULL,
					'config' => array(),
				);
				$line_class = 'line-header';
				$current['indent'] = NULL;
			}
		}
		elseif (preg_match ('/^(\s+)family ethernet-switching\b/', $line, $m))
		{
			if ($current['is_range'])
				throw new RTGatewayError ("interface-range '${current['name']}' contains switchport commands, which is not supported");
			$current['is_ethernet'] = TRUE;
			$current['indent'] = $m[1];
		}
		elseif (isset ($current['indent']) and $line == $current['indent'] . '}')
			$current['indent'] = NULL;
		elseif ($current['is_ethernet'] and isset ($current['indent']))
		{
			$line_class = 'line-8021q';
			if (preg_match ('/^\s+port-mode (trunk|access);/', $line, $m))
				$current['config']['mode'] = $m[1];
			elseif (preg_match ('/^\s+native-vlan-id (\d+);/', $line, $m))
				$current['config']['native'] = $m[1];
			elseif (preg_match ('/^\s+members \[?(.*)\]?;$/', $line, $m))
			{
				$members = array();
				foreach (explode (' ', $m[1]) as $item)
				{
					$item = trim ($item);
					if (preg_match ('/^(\d+)(?:-(\d+))?$/', $item, $m))
					{
						if (isset ($m[2]) and $m[2] > $m[1])
							$members = array_merge (range ($m[1], $m[2]), $members);
						else
							$members[] = $m[1];
					}
					elseif (isset ($vlans[$item]))
						$members[] = $vlans[$item];
					elseif ($item == 'all')
						$members = array_merge (range (VLAN_MIN_ID, VLAN_MAX_ID), $members);
				}
				$current['config']['allowed'] = array_unique ($members);
			}
			else
				$line_class = 'line-other';
		}
		if (isset ($current['name']))
		{
			if ($line == '}')
				$line_class = 'line-header';
			$ret['portconfig'][$current['name']][] = array ('type' => $line_class, 'line' => $line);
		}
	}
	
	return $ret;
}

function ftos8Read8021QConfig ($input)
{
	$ret = array
	(
		'vlanlist' => array (),
		'vlannames' => array (),
		'portdata' => array(),
		'portconfig' => array(),
	);
	$lines = explode ("\n", $input);
	$iface = NULL;
	foreach (explode ("\n", $input) as $line)
	{
		if (preg_match ('/^interface (\S.*?)\s*$/', $line, $m))
		{
			$iface = array
			(
				'name' => ios12ShortenIfName (str_replace (' ', '', $m[1])),
				'lines' => array(),
				'is_switched' => FALSE,
				'vlan' => 0,
			);
			$name_parts = explode (' ', $m[1]);
			$iface['vlan'] = (count ($name_parts) == 2 and $name_parts[0] == 'Vlan') ? $name_parts[1] : 0;
		}
		if (isset ($iface))
		{
			$iface['lines'][] = array ('type' => 'line-other', 'line' => $line);

			if ($line == ' switchport')
			{
				$iface['is_switched'] = TRUE;
				$ret['portdata'][$iface['name']] = array
				(
					'allowed' => array (),
					'native' => 0,
					'mode' => 'access',
				);
			}
			elseif ($line == '!')
			{
				$ret['portconfig'][$iface['name']] = $iface['lines'];
				unset ($iface);
			}
			elseif ($iface['vlan'])
			{
				$ret['vlanlist'][] = $iface['vlan'];
				if (preg_match ('/^[ !](un)?tagged (\S+) (\S+)/', $line, $m))
				{
					list ($untagged, $pref, $list) = array ($m[1], $m[2], $m[3]);
					if (preg_match ('#^(\d+/)#', $list, $m))
					{
						$pref .= $m[1];
						$list = substr ($list, strlen ($m[1]));
					}
					foreach (explode (',', $list) as $range)
					{
						$constraints = explode ('-', $range);
						if (count ($constraints) == 1)
							$constraints[] = $constraints[0];
						if ($constraints[0] <= $constraints[1])
							for ($i = $constraints[0]; $i <= $constraints[1]; $i++)
							{
								$if_name = ios12ShortenIfName ($pref . $i);
								$ret['portdata'][$if_name]['allowed'][] = $iface['vlan'];
								if ($untagged)
									$ret['portdata'][$if_name]['native'] = $iface['vlan'];
								else
									$ret['portdata'][$if_name]['mode'] = 'trunk';
							}
					}
				}
			}
		}
	}
	return $ret;
}

function ciscoReadInterfaceStatus ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
	{
		switch ($state)
		{
			case 'headerSearch':
				if (preg_match('/^Port\s+Name\s+Status/', $line))
				{
					$name_field_borders = getColumnCoordinates($line, 'Name');
					if (isset ($name_field_borders['from']))
						$state = 'readPort';
				}
				break;
			case 'readPort':
				$portname = ios12ShortenIfName (trim (substr ($line, 0, $name_field_borders['from'])));
				$rest = trim (substr ($line, $name_field_borders['from'] + $name_field_borders['length'] + 1));
				$field_list = preg_split('/\s+/', $rest);
				if (count ($field_list) < 4)
					break;
				list ($status_raw, $vlan, $duplex, $speed) = $field_list;
				if ($status_raw == 'connected' || $status_raw == 'up')
					$status = 'up';
				elseif (0 === strpos ($status_raw, 'notconn') || $status_raw == 'down' || $status_raw == 'sfpAbsent')
					$status = 'down';
				else
					$status = 'disabled';
				$result[$portname] = array
				(
					'status' => $status,
					'speed' => $speed,
					'duplex' => $duplex,
				);
				break;
		}
	}
	return $result;
}

function vrpReadInterfaceStatus ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
	{
		switch ($state)
		{
			case 'headerSearch':
				if (preg_match('/^Interface\s+Phy\w*\s+Protocol/i', $line))
					$state = 'readPort';
				break;
			case 'readPort':
				if (preg_match('/[\$><\]]/', $line))
					break 2;
				$field_list = preg_split('/\s+/', $line);
				if (count ($field_list) < 7)
					break;
				if ($field_list[0] == '')
					array_shift ($field_list);
				list ($portname, $status_raw) = $field_list;
				$portname = ios12ShortenIfName ($portname);

				if ($status_raw == 'up' || $status_raw == 'down')
					$status = $status_raw;
				else
					$status = 'disabled';
				$result[$portname] = array
				(
					'status' => $status,
				);
				break;
		}
	}
	return $result;
}

function ftos8ReadInterfaceStatus ($text)
{
	$result = array();
	$table_schema = array();
	foreach (explode ("\n", $text) as $line)
		if (empty ($table_schema))
		{
			if (preg_match('/^Port\s+Description\s+Status\s+Speed\s+Duplex\b/', $line))
				$table_schema = guessTableStructure ($line);
		}
		else
		{
			$fields = explodeTableLine ($line, $table_schema);
			if (! empty ($fields['Port']) and ! empty ($fields['Speed']) and ! empty ($fields['Duplex']))
			{
				$status = strtolower ($fields['Status']);
				if ($status != 'up' && $status != 'down')
					$status = 'disabled';
				$portname = ios12ShortenIfName (str_replace (' ', '', $fields['Port']));
				$result[$portname] = array
				(
					'status' => $status,
					'speed' => $fields['Speed'],
					'duplex' => $fields['Duplex'],
				);
			}
		}
	return $result;
}

function maclist_sort ($a, $b)
{
	if ($a['vid'] == $b['vid'])
		return 0;
	return ($a['vid'] < $b['vid']) ? -1 : 1;
}

function ios12ReadMacList ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
	{
		switch ($state)
		{
			case 'headerSearch':
				if (preg_match('/Vlan\s+Mac Address\s+Type.*Ports?\s*$/i', $line))
					$state = 'readPort';
				break;
			case 'readPort':
				if (! preg_match ('/(\d+)\s+([a-f0-9]{4}\.[a-f0-9]{4}\.[a-f0-9]{4})\s.*?(\S+)$/', trim ($line), $matches))
					break;
				$portname = ios12ShortenIfName ($matches[3]);
				$result[$portname][] = array
				(
					'mac' => $matches[2],
					'vid' => $matches[1],
				);
				break;
		}
	}
	foreach ($result as $portname => &$maclist)
		usort ($maclist, 'maclist_sort');
	return $result;
}

function nxos4ReadMacList ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
	{
		switch ($state)
		{
			case 'headerSearch':
				if (preg_match('/VLAN\s+MAC Address\s+Type\s+age\s+Secure\s+NTFY\s+Ports/i', $line))
					$state = 'readPort';
				break;
			case 'readPort':
				if (! preg_match ('/(\d+)\s+([a-f0-9]{4}\.[a-f0-9]{4}\.[a-f0-9]{4})\s.*?(\S+)$/', trim ($line), $matches))
					break;
				$portname = ios12ShortenIfName ($matches[3]);
				$result[$portname][] = array
				(
					'mac' => $matches[2],
					'vid' => $matches[1],
				);
				break;
		}
	}
	foreach ($result as $portname => &$maclist)
		usort ($maclist, 'maclist_sort');
	return $result;
}

function vrp53ReadMacList ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
	{
		switch ($state)
		{
			case 'headerSearch':
				if (preg_match('/MAC Address\s+VLAN\/VSI\s+Port/i', $line))
					$state = 'readPort';
				break;
			case 'readPort':
				if (! preg_match ('/([a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4})\s+(\d+)\s+(\S+)/', trim ($line), $matches))
					break;
				$portname = ios12ShortenIfName ($matches[3]);
				$result[$portname][] = array
				(
					'mac' => str_replace ('-', '.', $matches[1]),
					'vid' => $matches[2],
				);
				break;
		}
	}
	foreach ($result as $portname => &$maclist)
		usort ($maclist, 'maclist_sort');
	return $result;
}

function vrp55ReadMacList ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
	{
		switch ($state)
		{
			case 'headerSearch':
				if (preg_match('/^MAC Address\s+VLAN/i', $line))
					$state = 'readPort';
				break;
			case 'readPort':
				if (! preg_match ('/([a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4})\s+(\d+)(?:(?:\s+\S+){2}|\/\S*)\s+(\S+)/', trim ($line), $matches))
					break;
				$portname = ios12ShortenIfName ($matches[3]);
				$result[$portname][] = array
				(
					'mac' => str_replace ('-', '.', $matches[1]),
					'vid' => $matches[2],
				);
				break;
		}
	}
	foreach ($result as $portname => &$maclist)
		usort ($maclist, 'maclist_sort');
	return $result;
}

function ftos8ReadMacList ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
		switch ($state)
		{
			case 'headerSearch':
				if (preg_match('/^VlanId\s+Mac Address\s+Type\s+Interface\s+State/', $line))
					$state = 'readPort';
				break;
			case 'readPort':
				if (! preg_match ('/^(\d+)\s+((?:[a-f0-9]{2}:){5}[a-f0-9]{2})\s+Dynamic\s+(\S+ (?:\S+)?)/', trim ($line), $matches))
					break;
				$portname = ios12ShortenIfName (str_replace (' ', '', $matches[3]));
				$mac = preg_replace ('/([a-f0-9]{2}):([a-f0-9]{2})/', '$1$2', $matches[2]);
				$result[$portname][] = array
				(
					'mac' => str_replace (':', '.', $mac),
					'vid' => $matches[1],
				);
				break;
		}
	foreach ($result as $portname => &$maclist)
		usort ($maclist, 'maclist_sort');
	return $result;
}

?>
