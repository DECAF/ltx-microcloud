<?php
// --- w_main.php - Main worker ---
// Main Worker for intern_main. State 27.10.2020
// Note: json_encode will fail if non-utf8-chars are present
// ToDo: Optimise Call: with cmd/without per includes..
// 'status' <= -1000: Fatal Error!
// Last used ERROR: 145

require_once("../inc/w_istart.inc.php");
// ---------- functions ----------------
header('Content-Type: text/plain; charset=utf-8');

// Call Trigger to send Mail 
function call_trigger($mac, $reason, $xc)
{
	global $xlog;
	$self = $_SERVER['PHP_SELF'];
	$port = $_SERVER['SERVER_PORT'];
	if ($port = 443) $port = 80;	// Map HTTPS to HTTP
	$server = $_SERVER['SERVER_NAME'];
	$rpos = strrpos($self, '/sw'); // Evtl. check for  backslash (only Windows?)
	$tscript = substr($self, 0, $rpos) . "/sw/lxu_trigger.php";
	$arg = "k=" . S_API_KEY . "&r=$reason&s=$mac&xc=" . urlencode($xc);	// Parameter: API-KEY, reason and MAC and extended Content(encoded)

	//$xlog.="(Trigger: $server:$port '$tscript?$arg')";
	$res = ""; // OK
	$fp = @fsockopen($server, $port, $errno, $errstr, 10);    // Try max. 10 seconds 
	if ($fp) {
		stream_set_timeout($fp, 15, 0); // Wait max. 15 sec for a response of trigger

		$out = "GET $tscript?$arg HTTP/1.0\r\n";
		$out .= "Host: $server:$port\r\n"; // Assume: Ssame dir as self
		$out .= "Connection: Close\r\n\r\n";
		$wres = fwrite($fp, $out);
		if ($wres != strlen($out)) {
			$xlog .= "(ERROR; Write to Trigger-Script failed)";
			$res = "-140 ERROR: Write to Trigger-Script failed";
		} else {
			$rres = trim(fgets($fp), "\r\n");
			if (strpos($rres, " 200 ") == false) {
				$xlog .= "(ERROR: '$rres')";
			}
		}
		fclose($fp);
	} else {
		$xlog .= "(ERROR: Trigger-Script open)";
		$res = "-141 ERROR: Trigger-Script open";
	}
	return $res;
}

// --------- MAIN ------------

// Unix Timestamp as Database Time in secs
$dbnow = $pdo->query("SELECT UNIX_TIMESTAMP() as now")->fetch()['now']; // UTC
$dblast = intval(@$_REQUEST['last']);	// Last seen in secs UNIX_SECS
if (!$dblast) {
	$ret['user_name'] = $uname; // 0: FULL Info
	$ret['user_role'] = $urole;
	$ret['user_id'] = $user_id;
}

$xlog = "(User:$user_id)"; // 
$mac = @$_REQUEST['mac'];	// Ensure if MAC is set, it is OK
if (isset($mac)) {		// CMD mit MAC always require ROLE
	$role = 0;	// Assume Role as 0
	if (strlen($mac) != 16) {
		$status = "-115 ERROR: MAC len";
		$cmd = "";
	} else {
		$statement = $pdo->prepare("SELECT * FROM devices WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres != false) {
			$dres = $statement->fetch();
			// Role des Devices entscheidet, was gemacht werden darf.
			$role = @$dres['ow_role'];
		}
	}
}

switch ($cmd) {
	case "addGuestDevice":
		$newmac = strtoupper(@$_REQUEST['newmac']);
		$newtok = strtoupper(@$_REQUEST['newtok']);
		$statement = $pdo->prepare("SELECT * FROM guest_devices WHERE mac = ? AND guest_id = ? ");
		$qres = $statement->execute(array($newmac, $user_id));
		if ($qres == false) {
			$status = "-130 ERROR: CMD '$cmd'";
			break;
		} else {
			$anz = $statement->rowCount();
			if ($anz) {
				$status = "-131 ERROR: MAC already added as Guest Device!";
				break;
			}
			$statement = $pdo->prepare("SELECT * FROM devices WHERE mac = ?");
			$qres = $statement->execute(array($newmac));
			if ($qres == false) {
				$status = "-132 ERROR: CMD '$cmd'";
				break;
			}
			$anz = $statement->rowCount();
			if (!$anz) {
				$status = "-133 ERROR: MAC unknown!";
				break;
			}
			$device_row = $statement->fetch();
			if ($device_row['owner_id'] == $user_id) {
				$status = "-134 ERROR: Can't add own Device as Guest!";
				break;
			}
			if ($device_row['token0'] == $newtok /*|| $device_row['token1']==$newtok || $device_row['token2']==$newtok || $device_row['token3']==$newtok*/) {
				$pdo->exec("INSERT INTO guest_devices ( mac, guest_id, token ) VALUES ( '$newmac', '$user_id', '$newtok' )");
			} else {
				$status = "-135 ERROR: Invalid Token!";
				break;
			}
		}
		$mac = $newmac;	// For loglib
		$xlog .= "(Add Guest Device)";
		add_logfile();
		break;

	case "addDevice":
		$newmac = strtoupper(@$_REQUEST['newmac']);
		$newtok = strtoupper(@$_REQUEST['newtok']);

		$statement = $pdo->prepare("SELECT * FROM guest_devices WHERE mac = ? AND guest_id = ? ");
		$qres = $statement->execute(array($newmac, $user_id));

		if ($qres == false) {
			$status = "-136 ERROR: CMD '$cmd'";
			break;
		} else {
			$anz = $statement->rowCount();
			if ($anz) {
				$status = "-137 ERROR: MAC already added as Guest Device!";
				break;
			}
		}

		$ch = curl_init(KEY_SERVER_URL . "?k=" . KEY_API . "&s=$newmac&t=$newtok");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$result = curl_exec($ch);
		if (curl_errno($ch)) $status = "-100 ERROR: curl:'" . curl_error($ch) . "'";
		else {
			//$ret['curl']=$result;
			$cres = json_decode($result, true); // Result as AssocArray
			$fw_key = strtoupper(@$cres['fwkey']);
			$stres = @$cres['result'];
			if ($stres[0] != '0') $status = $stres;
			else if (strlen($fw_key) != 32) $status = "-101 ERROR: Firmware Key Len";
			else {	// All OK: Add/Update Device to DB
				// Evtl. generate MAC, might fail if already existing
				$pdo->exec("INSERT INTO devices ( mac ) VALUES ( '$newmac' )");
				$qres = $pdo->exec("UPDATE devices SET owner_id='$user_id', fw_key='$fw_key', last_change=NOW() WHERE mac ='$newmac'");
				if ($qres == false) {
					$status = "-102 ERROR: Update DB failed";
				}
			}
		}
		curl_close($ch);

		$mac = $newmac;	// For loglib
		$xlog .= "(Add own Device)";
		add_logfile();
		break;

	case "removeDevice":
		$oldmac = strtoupper(@$_REQUEST['oldmac']);
		$statement = $pdo->prepare("DELETE FROM guest_devices WHERE mac = ? AND guest_id = ? ");
		$qres = $statement->execute(array($oldmac, $user_id));
		if ($qres == false) {
			$status = "-138 ERROR: CMD '$cmd'";
			break;
		} else {
			$anz = $statement->rowCount();
			if ($anz > 0) {
				$mac = $oldmac;	// For loglib
				$xlog .= "(Removed as Guest Device)";
				add_logfile();
			} else {	// Remove Own Device
				$statement = $pdo->prepare("UPDATE devices SET owner_id=NULL WHERE mac = ? AND owner_id = ?");
				$qres = $statement->execute(array($oldmac, $user_id));
				if ($qres == false) {
					$status = "-139 ERROR: Update DB failed";
					break;
				}
				$anz = $statement->rowCount();
				if ($anz > 0) {
					$mac = $oldmac;	// For loglib
					$xlog .= "(Removed as own Device)";
					add_logfile();
				} else {
					$status = "-140 ERROR: No Access to this Device";
				}
			}
		}
		break;

	case "getUser":	// get All User Data
		$statement = $pdo->prepare("SELECT * FROM users WHERE id = ?");
		$qres = $statement->execute(array($user_id));
		if ($qres == false) {
			$status = "-107 ERROR: CMD '$cmd'"; // User unknown?
		} else {
			$user_row = $statement->fetch();
			// Security Filter here
			$user_row['password'] = "*";
			$ret['user'] = $user_row;
		}
		break;
	case "changeUser":	// Change user - Test all Options
		if (!($urole & 32768)) {	// ***** DEMO-USER ***
			$status = "-128 ERROR: Not possible for this User";
			break;
		}
		$new_name = trim(@$_REQUEST['new_name']);
		if (strlen($new_name) >= 4) {
			$statement = $pdo->prepare("SELECT * FROM users WHERE name = ?");
			$qres = $statement->execute(array($new_name));
			if ($qres == false) {
				$status = "-108 ERROR: CMD '$cmd'";
				break;
			} else {
				$anz = $statement->rowCount(); // No of matches = Number of User's Devices
				if ($anz) {
					$status = "-109 ERROR: Username not possible!";
					break;
				}
				$statement = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
				$qres = $statement->execute(array($new_name, $user_id));
				if ($qres == false) {
					$status = "-110 ERROR: CMD '$cmd'"; // Find by Number
					break;
				}
				$ret['user_name'] = $new_name;	// Feedback new Name
				$ret['user_role'] = $urole;
				$ret['user_id'] = $user_id;
			}
		} // other Options...
		break;

		// Problem t.b.d: Check rights to make changes! (user_id==mac.user_id or role&token...)
	case "removeWarnings":
		$statement = $pdo->prepare("UPDATE devices SET warnings_cnt = 0,last_change=NOW() WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres == false) $status = "-104 ERROR: CMD '$cmd'"; // Find by Number
		$xlog .= "(Warnings reset)";
		add_logfile();
		break;
	case "removeErrors":
		$statement = $pdo->prepare("UPDATE devices SET err_cnt = 0,last_change=NOW() WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres == false) $status = "-105 ERROR: CMD '$cmd'"; // Find by Number
		$xlog .= "(Errors reset)";
		add_logfile();
		break;
	case "removeAlarms":
		$statement = $pdo->prepare("UPDATE devices SET alarms_cnt = 0,last_change=NOW() WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres == false) $status = "-106 ERROR: CMD '$cmd'"; // Find by Number
		$xlog .= "(Alarms reset)";
		add_logfile();
		break;

	case "cntReset": // Reset Counter X
		$contNo = @$_REQUEST['contNo'];
		$contCntId = "em_cnt$contNo";
		$statement = $pdo->prepare("UPDATE devices SET $contCntId = 0 WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres == false) $status = "-114 ERROR: Update DB failed";
		$xlog .= "(Mailcounter $contNo reset)";
		add_logfile();

		goto getDevice;
	case "testContact": // Test Contact X, mail prepared by JS
		if (!($urole & 32768)) {	// ***** DEMO-USER ***
			$status = "-128 ERROR: Not possible for this User";
			break;
		}
		// Very simple. Minimum. Like Login. Keep user_name
		$contNo = @$_REQUEST['contNo']; // Contact No (ignored)
		$xcont = @$_REQUEST['xcont']; // 

		$xlog .= "(Test 'Contact #$contNo')";
		$res = call_trigger($mac, 256, $xcont);
		add_logfile();

		// Fall through! MAC already set (update counter)
	case "getDevice":
		getDevice:
		$statement = $pdo->prepare("SELECT * FROM devices WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres == false) {
			$status = "-110 ERROR: CMD '$cmd'";
		} else {
			$user_row = $statement->fetch();
			// Security Filter heres
			$user_row['fw_key'] = "*";
			if ($user_row['cookie'] !== null)	$user_row['sCookie'] = date('Y-m-d H:i:s', $user_row['cookie']);
			else $user_row['sCookie'] = "(unknown)";
			$statement = $pdo->prepare("SELECT COUNT(*) AS anz FROM m$mac"); // Seems not to work as PDO Arg.
			$qres = $statement->execute();

			if ($qres == false) $danz = "(No Data)";
			else {
				$row2 = $statement->fetch();
				$danz = $row2['anz'];
			}
			$user_row['available_cnt'] = $danz; // Add extra-Info
			$ret['device'] = $user_row;
		}
		break;

	case "changeDevice":
		if (!($urole & 32768)) {	// ***** DEMO-USER ***
			$status = "-128 ERROR: Not possible for this User";
			break;
		}
		$statement = $pdo->prepare("UPDATE devices SET last_change=NOW(), 
			utc_offset = ?, timeout_warn = ?, timeout_alarm = ?, flags= ?, role0 = ?, token0 = ?, email0 = ?, cond0 = ?
			WHERE mac = ?");
		$qres = $statement->execute(array(
			@$_REQUEST['new_utcoffset'],
			@$_REQUEST['new_towarn'],
			@$_REQUEST['new_toalarm'],
			@$_REQUEST['new_flags'],
			@$_REQUEST['new_role0'],
			@$_REQUEST['new_token0'],
			@$_REQUEST['new_email0'],
			@$_REQUEST['new_cond0'],
			$mac
		));

		//print_r( $statement->errorInfo() );
		if ($qres == false) {
			$status = "-111 ERROR: CMD '$cmd'"; // Find by Number
		}
		$xlog .= "(Change Server Parameter)";
		add_logfile();

		break;
	case "getParam": // Get current Parameters 
		$fpath = "../" . S_DATA . "/$mac"; // (one extra DIR up)
		$par = @file($fpath . "/put/iparam.lxp", FILE_IGNORE_NEW_LINES); // pending Parameters?
		if ($par != false) {
			$ret['par_pending'] = 1;	// Return - Pending Parameters!
		} else {
			$par = @file($fpath . "/files/iparam.lxp", FILE_IGNORE_NEW_LINES); // No NL, but empty Lines OK
			if ($par == false) {
				$status = "-116 ERROR: No Parameters found for MAC:$mac";
				break;
			}
			$ret['par_pending'] = 0;	// On Dev.
		}
		$ret['iparam'] = $par; // array_map("utf8_encode",$par) ???10/2020 ; // File complete as lines
		$ret['scookie'] = date('Y-m-d H:i:s', @$par[4]); // Special ADDs
		break;

	case "saveParam";
		if (!($urole & 32768)) {	// ***** DEMO-USER ***
			$status = "-128 ERROR: Not possible for this User";
			break;
		}
		$fpath = "../" . S_DATA . "/$mac"; // (one extra DIR up)
		$nparstr = implode("\n", @$_REQUEST['npar']) . "\n";
		$ilen = strlen($nparstr);
		@unlink($fpath . "/cmd/iparam.lxp.pmeta");
		if ($ilen > 32) $slen = file_put_contents($fpath . "/put/iparam.lxp", $nparstr);
		else $slen = -1;
		if ($ilen == $slen) {
			file_put_contents($fpath . "/cmd/iparam.lxp.pmeta", "sent\t0\n");
			$par = @file($fpath . "/put/iparam.lxp", FILE_IGNORE_NEW_LINES); // Set NewName?
			if ($par != false) {
				$statement = $pdo->prepare("UPDATE devices SET name = ? WHERE mac = ?");
				$statement->execute(array(@$par[5], $mac));
			}
			$xlog .= "(New Hardware-Parameter 'iparam.lxp':$ilen)";
		} else {
			$xlog .= "(ERROR: Write 'iparam.lxp':$slen/$ilen Bytes)";
			$status = "-117 ERROR: Write Parameter:$slen/$ilen Bytes";
		}
		add_logfile();
		break;

	case "removePending": // Remove Pending Parameters
		$fpath = "../" . S_DATA . "/$mac"; // (one extra DIR up)
		@unlink($fpath . "/cmd/iparam.lxp.pmeta");
		@unlink($fpath . "/put/iparam.lxp");
		$xlog .= "(Remove waiting Hardware-Parameter'iparam.lxp')";

		$par = @file($fpath . "/files/iparam.lxp", FILE_IGNORE_NEW_LINES); // Set CurrentName?
		if ($par != false) {
			$statement = $pdo->prepare("UPDATE devices SET name = ? WHERE mac = ?");
			$statement->execute(array(@$par[5], $mac));
		}

		add_logfile();
		break;

	case "getInfo":	// Ask for Device Info
		$fpath = "../" . S_DATA . "/$mac"; // (one extra DIR up)
		$dinfo = @file($fpath . "/device_info.dat", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($dinfo == false) {
			$status = "-118 ERROR: No Info found for MAC:$mac";
			break;
		}
		$date0 = @file($fpath . "/date0.dat", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$dinfo[] = "date0\t" . $date0[0];	// Add Date0 to Info

		// Specials
		// Info about Position Update
		$statement = $pdo->prepare("SELECT posflags FROM devices WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres == false) {
			$status = "-124 ERROR: CMD '$cmd'";
			break;
		}
		$user_row = $statement->fetch();
		$dinfo[] = "posflags\t" . $user_row['posflags'];	// Add posflags to Info

		$ret['dinfo'] = $dinfo; // File complete as lines + adds
		break;

	case "clearDevice":	// Table, Notes and WEA-Counters
		$statement = $pdo->prepare("DROP TABLE m$mac");
		$qres = $statement->execute();
		if ($qres == false) {
			$status = "-143 ERROR: No Data";
			break;
		}

		$statement = $pdo->prepare("UPDATE devices SET last_change=NOW(), 
			anz_lines = 0, vals = NULL
			WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres == false) {
			$status = "-144 ERROR: CMD '$cmd'"; // Find by Number
		}

		$statement = $pdo->prepare("UPDATE devices SET warnings_cnt = 0, err_cnt = 0, alarms_cnt = 0, last_change=NOW() WHERE mac = ?");
		$qres = $statement->execute(array($mac));
		if ($qres == false) $status = "-145 ERROR: CMD '$cmd'"; // Find by Number

		$fpath = "../" . S_DATA . "/$mac"; // (one extra DIR up)
		$fname = $fpath . "/info_wea.txt";
		$fname2 = $fpath . "/_info_wea_old.txt";
		@unlink($fname);
		@unlink($fname2);

		$xlog .= "(Clear Device DB)";
		add_logfile();
		break;

	case "setPosUpdate":
		$statement = $pdo->prepare("UPDATE devices SET last_change=NOW(), 
			posflags = ?
			WHERE mac = ?");
		$npf = @$_REQUEST['posflags'];
		$qres = $statement->execute(array($npf, $mac));

		if ($qres == false) {
			$status = "-123 ERROR: CMD '$cmd'"; // Find by Number
		}
		$xlog .= "(Set posflags:$npf)";
		add_logfile();
		break;

	case "getPos":
		$asig = @$_REQUEST['cell'];
		$sqs = CELLOC_SERVER_URL . '?k=' . G_API_KEY . "&s=$mac&mcc=" . $asig['mcc'] . "&net=" . $asig['net'] . "&lac=" . $asig['lac'] . "&cid=" . $asig['cid'];
		$ch = curl_init($sqs);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$result = curl_exec($ch);
		if (curl_errno($ch)) $status = "-119 ERROR: curl:'" . curl_error($ch) . "'";
		curl_close($ch);
		if (!isset($status)) {
			$obj = json_decode($result);
			if (strcmp($obj->code, "OK")) $cres = "ERROR: ";
			else $cres = "OK: ";
			$ret['locinfo'] = $cres . $obj->info;
			$ret['lat'] = $obj->lat;
			$ret['lon'] = $obj->lon;
			$ret['accuracy'] = $obj->accuracy;
		}
		break;

	case "savePos": // GPS to DB
		$npos = @$_REQUEST['newpos'];
		$nLat = floatval($npos['lat']);
		$nLon = floatval($npos['lon']); // !! lng in DB
		$nRad = floatval($npos['rad']);
		if ($nLat < -90 || $nLat > 90 || $nLon < -90 || $nLon > 90 || $nRad < 0 || $nRad > 1000000) {
			$status = "-120 ERROR: Format";
			break;
		}
		$statement = $pdo->prepare("UPDATE devices SET last_change=NOW(), 
			lat = ?, lng = ?, rad = ?, last_gps=NOW()
			WHERE mac = ?");
		$qres = $statement->execute(array($nLat, $nLon, $nRad, $mac));

		if ($qres == false) {
			$status = "-121 ERROR: CMD '$cmd'"; // Find by Number
		}
		$xlog .= "(Set Pos. $nLat,$nLon,$nRad)";
		add_logfile();
		break;

	case "clearPos": // GPS to DB
		$statement = $pdo->prepare("UPDATE devices SET last_change=NOW(), 
			lat = NULL, lng = NULL, rad = NULL, last_gps=NOW()
			WHERE mac = ?");
		$qres = $statement->execute(array($mac));

		if ($qres == false) {
			$status = "-122 ERROR: CMD '$cmd'"; // Find by Number
		}
		$xlog .= "(Clear Pos.)";
		add_logfile();
		break;

	case "getLog": // Get Logfile (or later also other 2-part text files - reverse)
		$fpath = "../" . S_DATA . "/$mac"; // (one extra DIR up)
		$typ = @$_REQUEST['typ'];	// Type of Logfile (0: log.txt)
		if ($typ == 0) {
			$fname = $fpath . "/log.txt";
			$fname2 = $fpath . "/_log_old.txt";
		} else if ($typ == 1) {
			$fname = $fpath . "/conn_log.txt";
			$fname2 = $fpath . "/_conn_log_old.txt";
		} else {
			$status = "-125 ERROR: CMD '$cmd'";
			break;
		}
		$pos0 = @$_REQUEST['pos0'];
		$anz = @$_REQUEST['anz'];

		$logall = @file($fname, FILE_IGNORE_NEW_LINES);

		if ($logall == false || count($logall) < ($anz + $pos0)) {
			$logall2 = @file($fname2, FILE_IGNORE_NEW_LINES);
			if ($logall2 != false) {
				$logall = array_merge($logall2, $logall); // Old First
			}
		}

		if ($logall == false || count($logall) < 1) {
			$status = "-126 ERROR: CMD No Data File";
			break;
		}
		// Fill 
		$lres = array();
		$lidx0 = count($logall) - 1 - $pos0;
		for ($i = 0; $i < $anz; $i++) {
			if ($lidx0 < 0) break;
			//echo $lidx0; echo ": '".$logall[$lidx0]."'<br>";
			$lres[] = $logall[$lidx0];
			$lidx0--;
		}
		$ret['lres'] = $lres;	// Return result
		break;

	case "getWEA": // Get Warnings/Error/Alarms
		$fpath = "../" . S_DATA . "/$mac"; // (one extra DIR up)
		$fname = $fpath . "/info_wea.txt";
		$fname2 = $fpath . "/_info_wea_old.txt";
		$pos0 = @$_REQUEST['pos0'];
		$anz = @$_REQUEST['anz'];

		$logall = @file($fname, FILE_IGNORE_NEW_LINES);

		if ($logall == false || count($logall) < ($anz + $pos0)) {
			$logall2 = @file($fname2, FILE_IGNORE_NEW_LINES);
			if ($logall2 != false) {
				$logall = array_merge($logall2, $logall); // Old First
			}
		}

		// Fill 
		$lres = array();
		if ($logall == false || count($logall) < 1) {
			$lres[] = "(Nothing)";	// Keine <>, das HTML
		} else {
			$lidx0 = count($logall) - 1 - $pos0;
			for ($i = 0; $i < $anz; $i++) {
				if ($lidx0 < 0) break;
				//echo $lidx0; echo ": '".$logall[$lidx0]."'<br>";
				$lres[] = $logall[$lidx0];
				$lidx0--;
			}
		}
		$ret['weares'] = $lres;	// Return result
		break;

	case "";
		break;
	default:
		$status = "-103 ERROR: CMD '$cmd'";
}

if (!isset($status)) {

	// Quick Check all user's devices for changes OWN first
	if ($urole & 65536) $statement = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(last_change) AS lsc FROM devices"); // Admin
	else $statement = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(last_change) AS lsc FROM devices WHERE owner_id = ?"); // Normal User

	$statement->execute(array($user_id));
	$anzo = $statement->rowCount(); // No of matches = Number of User's OWN Devices
	$devices = array();

	for ($i = 0; $i < $anzo; $i++) {
		$user_row = $statement->fetch();
		$lsc = $user_row['lsc'];
		if ($dblast >= $lsc) continue;
		$dev = array('idx' => $i);
		$dev['owner_id'] = $user_id; // Fix!
		$dev['real_owner_id'] = $user_row['owner_id']; // For Admin
		$dev['role'] = $user_row['ow_role'];

		$dev['last_seen'] = $user_row['last_seen'];
		$dev['mac'] = $user_row['mac'];
		$dev['warnings_cnt'] = $user_row['warnings_cnt'];
		$dev['alarms_cnt'] = $user_row['alarms_cnt'];
		$dev['err_cnt'] = $user_row['err_cnt'];
		$dev['anz_lines'] = $user_row['anz_lines'];

		$dev['timeout_warn'] = $user_row['timeout_warn'];
		$dev['timeout_alarm'] = $user_row['timeout_alarm'];
		$dev['flags'] = $user_row['flags'];
		$dev['lines_cnt'] = $user_row['lines_cnt'];
		$dev['name'] = $user_row['name'];
		$dev['units'] = $user_row['units'];
		$dev['vals'] = $user_row['vals'];
		$dev['lat'] = $user_row['lat'];
		$dev['lng'] = $user_row['lng'];
		$dev['rad'] = $user_row['rad'];
		$dev['last_gps'] = $user_row['last_gps'];

		$dev['vbat0'] = $user_row['vbat0'];
		$dev['vbat100'] = $user_row['vbat100'];
		$dev['cbat'] = $user_row['cbat'];

		$devices[] = $dev;
	}

	// Guest Devices
	$statement = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(last_change) AS lsc, guest_devices.token FROM devices INNER JOIN guest_devices ON devices.mac = guest_devices.mac WHERE guest_devices.guest_id = ?");
	$statement->execute(array($user_id));
	$anzg = $statement->rowCount(); // No of matches = Number of Guest Devices
	for ($i = 0; $i < $anzg; $i++) {

		$user_row = $statement->fetch();
		$lsc = $user_row['lsc'];
		if ($dblast >= $lsc) continue;
		$dev = array('idx' => $i + $anzo);	// + own
		$token = $user_row['token'];
		$role = 0;
		if ($token == $user_row['token0']) {
			$role = (int)$user_row['role0']; // Find Guest-Role (else...)
		}
		$dev['token'] = $token;
		$dev['role'] = ($role & (int)$user_row['ow_role']); // Synthesize ROLE as &
		$dev['owner_id'] = $user_row['owner_id'];
		$dev['last_seen'] = $user_row['last_seen'];
		$dev['mac'] = $user_row['mac'];
		$dev['warnings_cnt'] = $user_row['warnings_cnt'];
		$dev['alarms_cnt'] = $user_row['alarms_cnt'];
		$dev['err_cnt'] = $user_row['err_cnt'];
		$dev['anz_lines'] = $user_row['anz_lines'];
		$dev['timeout_warn'] = $user_row['timeout_warn'];
		$dev['timeout_alarm'] = $user_row['timeout_alarm'];
		$dev['flags'] = $user_row['flags'];
		$dev['lines_cnt'] = $user_row['lines_cnt'];
		$dev['name'] = $user_row['name'];
		$dev['units'] = $user_row['units'];
		$dev['vals'] = $user_row['vals'];
		$dev['lat'] = $user_row['lat'];
		$dev['lng'] = $user_row['lng'];
		$dev['rad'] = $user_row['rad'];
		$dev['last_gps'] = $user_row['last_gps'];

		$dev['vbat0'] = $user_row['vbat0'];
		$dev['vbat100'] = $user_row['vbat100'];
		$dev['cbat'] = $user_row['cbat'];

		$devices[] = $dev;
	}
	$ret['anz_devices'] = $anzo + $anzg;

	$ret['devices'] = $devices;
	// sleep(2);	// Slow down reply for test
	$ret['dbnow'] = $dbnow;
}

// Status 0: OK
$mtrun = round((microtime(true) - $mtmain_t0) * 1000, 4);
if (!isset($status)) $status = "0 OK";
$ret['status'] = $status . " ($mtrun msec)";	// plus Time

//$ret['echo']=json_encode($_REQUEST); // Optional Debug-Echo
$ares = json_encode($ret); // assoc array always as object
if (!strlen($ares)) $ares = "Error: json_encode";
echo $ares;
