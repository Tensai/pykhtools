<?php
/**
 * Dump last games of the strongest players from gokgs.com
 * @author v.fedorov 900dw1n@gmail.com
 */

require_once('simple_html_dom.php');
require_once('php_mailer/class.phpmailer.php');

const TOP100_PAGE = 'http://www.gokgs.com/top100.jsp';
const PLAYER_PAGE = 'http://www.gokgs.com/gameArchives.jsp?user=';
const TMP_DIR = '/tmp/kgsparser/';
const STORAGE_DIR = '/storage/kgs_archives/';
const DELAY_WAIT = 10;

$start = time();
$emails = ['example1@test.com', 'example2@test.com'];
$playersList = ['roln111', 'breakfast', 'backpast', 'twoeye', 'Dom']; // preselected favourite players

$top100Page = file_get_html(TOP100_PAGE);
foreach ($top100Page->find('table.grid') as $tbl) {
	foreach ($tbl->find('tr') as $tr) {
		if ($tdList = $tr->find('td')) {
			list($pos, $name, $rank) = $tdList;
			if ((int) $rank->plaintext[0] >= 7) {
				$playersList[] = $name->plaintext;
			}
		}
	}
}
print 'Players list loaded, ' . count($playersList) . ' items total' . PHP_EOL;

$yesterday = time() - 24 * 3600;
$processed = [];
$urlsDownloaded = [];
$files = [];
$step = 0;
$totalFail = false;
foreach ($playersList as $player) {
	print 'Processing player: ' . $player . ' (#' . (++$step) . ')' . PHP_EOL;
	$processed[$player] = 0;
	if ($playerPage = file_get_html(PLAYER_PAGE . $player . '&year=' . date('Y', $yesterday) . '&month=' . date('m',
			$yesterday))
	) {
		$tbl = $playerPage->find('table.grid');
		if ($tbl && isset($tbl[0])) {
			foreach ($tbl[0]->find('tr') as $tr) {
				$tdList = $tr->find('td');
				if (
					$tdList
					&& count($tdList) == 7
					&& trim($tdList[0]->plaintext) === 'Yes'
					&& date('d.m.Y', $yesterday) === date('d.m.Y', strtotime(trim($tdList[4]->plaintext)))
					&& in_array(trim($tdList[5]->plaintext), ['Free', 'Ranked'])
					&& $gameLink = $tdList[0]->find('a')
				) {
					$gameUrl = $gameLink[0]->href;
					if (in_array($gameUrl, $urlsDownloaded)) {
						continue;
					}
					if ($sgf = file_get_contents($gameLink[0]->href)) {
						$fName = '[' . date('d.m.Y H:i', strtotime(trim($tdList[4]->plaintext))) . '] '
							. trim($tdList[1]->plaintext)
							. ' - '
							. trim($tdList[2]->plaintext)
							. '.sgf';
						if (file_put_contents(TMP_DIR . $fName, $sgf)) {
							$files[] = $fName;
							$urlsDownloaded[] = $gameUrl;
							$processed[$player]++;
						}
					}
				}
			}
		}
	} else {
		print 'Can\'t get games list' . PHP_EOL;
		$totalFail = true;
	}
	print 'Got ' . $processed[$player] . ' games for player ' . $player . ', ' . DELAY_WAIT . 's delay started... ' . PHP_EOL;
	sleep(DELAY_WAIT);
}

print 'Processing complete, got ' . count($files) . ' files' . PHP_EOL;
if (count($files) > 0) {
	print 'Creating Zip archive...' . PHP_EOL;
	$fails = [];
	$processed = [];
	$zip = new ZipArchive();
	$zipFName = date('Y-m-d', $yesterday) . '_KGS_updates.zip';
	if ($zip->open(TMP_DIR . $zipFName, ZipArchive::CREATE) !== true) {
		die('Can\'t open ZIP file, exiting');
	}
	foreach ($files as $file) {
		if (is_readable(TMP_DIR . $file)) {
			$a = $zip->addFile(TMP_DIR . $file, $file);
			$processed[] = '<li>' . $file . '</li>';
			//unlink(TMP_DIR . $file);
		} else {
			$fails[] = $processed[] = '<li>' . $file . '</li>';
			echo 'Can\'t open SGF: ' . $file . PHP_EOL;
		}
	}
	echo 'Zip file created: ' . TMP_DIR . $zipFName . PHP_EOL;
	echo 'Files zipped: ' . $zip->numFiles . PHP_EOL;
	echo 'Zip archive status: ' . $zip->status . PHP_EOL;
	$zip->close();

	$internalBody = '<h3>Today games list</h3><ul>'
		. '<p>Всего: ' . count($processed) . '</p>'
		. implode('', $processed)
		. '</ul>'
	;
	if (count($fails) > 0) {
		$internalBody .= '<br/><h3>Could not make an archive file</h3><ul>'
			. '<p>Всего: ' . count($fails) . '</p>'
			. implode('', $fails)
			. '</ul>'
		;
	}
	@copy(TMP_DIR . $zipFName, STORAGE_DIR . $zipFName);
	foreach ($files as $file) {
		unlink(TMP_DIR . $file);
	}

} else {
	$internalBody = '<h3>' . ($totalFail ? 'Oops! Something went wrong!!!' : 'No new games found') . '</h3>';
}

print 'Sending emails...' . PHP_EOL;

$mailer = new PHPMailer();
$mailer->CharSet   = 'UTF-8';
$mailer->From      = 'noreply@myhomeserver.localhost';
$mailer->FromName  = 'My KGS parser';
$mailer->Subject   = 'KGS games updates for ' . date('d.m.Y', $yesterday);
foreach ($emails as $email) {
	$mailer->addAddress($email);
}
$mailer->addAttachment(TMP_DIR . $zipFName, $zipFName);
$message = '
	<html>
	<head>
	 <title>KGS updates for ' . date('d.m.Y', $yesterday) . '</title>
	</head>
	<body>
	' . $internalBody . '
	<p>Script finished in : ' . (time() - $start) . ' seconds</p>
	</body>
	</html>
';
$mailer->Body = $message;
$mailer->isHTML(true);
if ($mailer->send()) {
	unlink(TMP_DIR . $zipFName);
	print 'Successfully sent' . PHP_EOL;
}

print 'Script finished in ' . (time() - $start) . ' seconds' . PHP_EOL;
