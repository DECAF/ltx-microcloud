<?php
/***********************************************************
 * w_rad.php - push-cmd-pull worker fuer RemoteADmin 
 *
 * Entwickler: Juergen Wickenhaeuser, joembedded@gmail.com
 *
 * Beispielaufrufe (ggfs. $dbg) setzen):
 *
 * Fuer einfache CMDs per URL (z.B. interne Aufrufe): k kann auch S_API_KEY sein!


---Die Idee ist, dass ueber dieses Script alles Remote ADmin Aufgaben der LTX-CLoud
geloest werden koennen. Genau Funktionalitaet, z.B. Onboaring, Credits, etc..
ist noch zu klaeren---


//Basis-Aufruf / Ausgabe Version
http://localhost/ltx/sw/w_php/w_rad.php?cmd

// Listet alle Devices zu diesem Key auf
http://localhost/ltx/sw/w_php/w_rad.php?k=ABC&cmd=list 

 * Parameter - Koennen per POST oder URL uebergeben werden
 * cmd: Kommando
 * k: AccessKey (aus 'quota_days.dat') (opt.)
 * s: MAC(16-Digits) (opt.)
 * 
 * cmd:
 * '':		Version
 * list:	Alle MACs mit Zugriff auflisten (nur 'k' benoetigt)
 * 
 * Status-Returns:
 * 0:	OK
 * 100: Keine Tabelle mMAC fuer diese MAC
 * 101: Keine Parameter gefunden fuer diese MAC
 * 102: Unbekanntes Kommando cmd
 * ...
 */

define('VERSION', "RAD V0.01 06.11.2023");

error_reporting(E_ALL);
ini_set("display_errors", true);

header("Content-type: application/json; charset=utf-8");
header('Access-Control-Allow-Origin: *');	// CORS enabler

$mtmain_t0 = microtime(true);         // fuer Benchmark
$tzo = timezone_open('UTC');
$now = time();

require_once("../conf/config.inc.php");	// DB Access 
require_once("../conf/api_key.inc.php"); // APIs
require_once("../inc/db_funcs.inc.php"); // Init DB

$dbg = 0; // 1:Dbg, 2:Dbg++
$fpath = "../" . S_DATA;	// Globaler Pfad auf Daten
$xlog = ""; // Log-String

// ------ Write LogFile (carefully and out-of-try()/catch()) -------- (similar to lxu_xxx.php)
function add_logfile()
{
	global $xlog, $dbg, $mac, $now, $fpath;
	if (@filesize("$fpath/log/radlog.txt") > 100000) {	// Main LOG
		@unlink("$fpath/log/_radlog_old.txt");
		rename("$fpath/log/radlog.txt", "$fpath/log/_radlog_old.txt");
		$xlog .= " (Main 'radlog.txt' -> '_radlog_old.txt')";
	}

	if (!isset($mac)) $mac = "UNKNOWN_MAC";
	if ($dbg) $xlog .= "(DBG:$dbg)";

	$log = @fopen("$fpath/log/radlog.txt", 'a');
	if ($log) {
		while (!flock($log, LOCK_EX)) usleep(10000);  // Lock File - Is a MUST
		fputs($log, gmdate("d.m.y H:i:s ", $now) . "UTC " . $_SERVER['REMOTE_ADDR']);        // Write file
		if (strlen($mac)) fputs($log, " MAC:$mac"); // mac only for global lock
		fputs($log, " $xlog\n");        // evt. add extras
		flock($log, LOCK_UN);
		fclose($log);
	}
	// User Logfile - Text
	if (strlen($mac) == 16 && file_exists("$fpath/$mac")) {
		if (@filesize("$fpath/$mac/radlog.txt") > 50000) {	// Device LOG
			@unlink("$fpath/$mac/_radlog_old.txt");
			rename("$fpath/$mac/radlog.txt", "$fpath/$mac/_radlog_old.txt");
		}

		$log = fopen("$fpath/$mac/radlog.txt", 'a');
		if (!$log) return;
		while (!flock($log, LOCK_EX)) usleep(10000);  // Lock File - Is a MUST
		fputs($log, gmdate("d.m.y H:i:s ", $now) . "UTC");
		fputs($log, " $xlog\n");        // evt. add extras
		flock($log, LOCK_UN);
		fclose($log);
	}
}

try {
	// Check Access-Token for this Device
	function checkAccess($lmac, $ckey)
	{
		global $fpath;
		if($ckey == S_API_KEY) return true;	// S_API_KEY valid for ALL
		$quota = @file("$fpath/$lmac/quota_days.dat", FILE_IGNORE_NEW_LINES);
		if (isset($quota[2]) && strlen($quota[2])) {
			$qpar = explode(' ', trim(preg_replace('/\s+/', ' ', $quota[2])));
			if (count($qpar) >= 2) {
				$akey = $ckey;
			}
		}
		if (!isset($akey) || $akey !== $qpar[1]) {
			return false;
		}
		return true;
	}

	// Prueft Zahlenwert auf Grenzen - HELPER
	function nverify($str, $ilow, $ihigh){
		if(!is_numeric($str)) return true;
		$val = intval($str);
		if($val<$ilow || $val>$ihigh) return true;	// Fehler
		return false;
	}
	function nisfloat($str){	// PHP recht relaxed, alles als Float OK, daher mind. 1 char. - HELPER
		if(!is_numeric($str)) return true;
		return false;
	}
	//=========== MAIN ==========
	$retResult = array();

	if ($dbg > 1) print_r($_REQUEST); // Was wollte man DBG (2)

	$cmd = @$_REQUEST['cmd'];
	if (!isset($cmd)) $cmd = "";
	$ckey = @$_REQUEST['k'];	// s always MAC (k: API-Key, r: Reason)
	if($ckey == S_API_KEY) $xlog = "(cmd:'$cmd')"; // internal
	else $xlog = "(cmd:'$cmd', k:'$ckey')";
	

	// --- cmd PreStart - CMD vorfiltern ---
	switch ($cmd) {
		case "":
			$retResult['version'] = VERSION;
			break;

		case "list": // CMD'list' ALLE Devices zu DIESEM PW listen, MAC nicht noetig, Push-URL egal
			db_init(); // Access Ok, erst dann DB oeffnen (init global $pdo)

			$statement = $pdo->prepare("SELECT * FROM devices");
			$qres = $statement->execute();
			if ($qres == false) throw new Exception("DB 'devices'");
			$anz = $statement->rowCount();
			$macarr = array();
			for ($i = 0; $i < $anz; $i++) {
				$ldev = $statement->fetch();
				$lmac = $ldev['mac'];
				if (checkAccess($lmac, $ckey)) {
					$macarr[] = $lmac;
				}
			}
			if (!count($macarr)) throw new Exception("No Access");
			$retResult['list_count'] = count($macarr); // Allowed Devices
			$retResult['list_mac'] = $macarr;
			break;

		default: // Alle anderen CMDs sind Geratespezifsich
			// Im Normalfall erstmal feststellen ob Zugriff auf einzelnen Logger erlaubt
			$mac = @$_REQUEST['s'];	// s ist immer die MAC, muss bekannt sein
			if (!isset($mac)) $mac = "";
			if (strlen($mac) != 16) {
				throw new Exception("MAC len");
			}
			$mac = strtoupper($mac);
			if (!checkAccess($mac, $ckey)) {
				throw new Exception("No Access");
			}
			db_init(); // Access Ok, erst dann DB oeffnen (init global $pdo)

			// Default-Infos fuer diese Teil
			$statement = $pdo->prepare("SELECT * FROM devices WHERE mac = ?");
			$qres = $statement->execute(array($mac));
			if ($qres == false) throw new Exception("MAC $mac not in 'devices'");
			$devres = $statement->fetch(); // $devres[]: 'device(mac)'!

			$ovv = array();	// Overview zu dieser MAC
			$ovv['mac']=$mac;   
			$ovv['db_now'] = $pdo->query("SELECT NOW() as now")->fetch()['now']; // *JETZT* als Datum UTC - Rein zurInfo

			$retResult['overview'] = $ovv;
	} // --- cmd PreEnde ---

	// --- cmd Main Start - CMD auswerten ---
	switch ($cmd) {
		case '': // VERSION
		case 'list': // Liste schon fertig
			break;	

		case 'details':	// Einfach ALLES fuer diese MAC
			$retResult['details'] = $devres;
			break;

/****************************************************************
* AB hier eigene CMDS *todo* 
****************************************************************/

		default:
			$status = "102 Unknown Cmd";
	} // --- cmd Main Ende ---

	// Benchmark am Ende
	$mtrun = round((microtime(true) - $mtmain_t0) * 1000, 4);
	if (!isset($status)) $status = "0 OK";	// Im Normalfall Status '0 OK'
	$retResult['status'] = $status . " ($mtrun msec)";	// plus Time

	$ares = json_encode($retResult); // assoc array always as object
	if (!strlen($ares))  throw new Exception("json_encode()");
	if ($dbg) var_export($retResult);
	else echo $ares;
} catch (Exception $e) {
	$errm = "#ERROR: '" . $e->getMessage() . "'";
	exit("$errm\n");
	$xlog .= "($errm)";
}

if (isset($pdo) && strlen($xlog)) add_logfile(); // // Nur ernsthafte Anfragen loggen
// ***