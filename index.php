<?php
/**
 * Files can be input by:
 *   1. CLI: Specifying a filepath as the first command line argument
 *          example: php index.php data/apple-cinemahd-30-dvi
 *   2. CLI: Piping a binary EDID file into STDIN
 *          example: php index.php < data/apple-cinemahd-30-dvi
 *   3. Web: Giving a file name as $_GET['fd'] or $_POST['fd']
 *          example: http://example.com/edid-decode/?fd=data/apple-cinemahd-30-dvi
 *   4. Web: Giving a base64-encoded string as $_GET['raw64'] or $_POST['raw64']
 *   5. Web: Giving a regedit-exported string as $_GET['regexport'] or $_POST['regexport']
 *          note: In the Microsoft Windows registry, EDIDs are located at locations like:
 *             HKLM\SYSTEM\CurrentControlSet\Enum\DISPLAY\*\*\Device Parameters
 *   6. Library: Call EdidDecode::main($input), $input is a path to a binary EDID file 
 *          example:
 *            $edidDecode = new EdidDecode();
 *            $edidDecode->main('data/apple-cinemahd-30-dvi');
 *   7. Library: Call EdidDecode::main($input,true), $input is a binary EDID file
 *          example:
 *            $edidDecode = new EdidDecode();
 *            $edidDecode->main($binaryEDIDString,true);
 */

error_reporting(E_ALL | E_STRICT);
require_once('php-edid-decode.php');

// Turn off output buffering
while (@ob_end_flush());	
ob_implicit_flush();

$inputIsBinary = false;
$edidDecode = new EdidDecode();
$edidDecode->_cli = false;
$input = null;

if (defined('PHP_SAPI') && PHP_SAPI=='cli') {
	$edidDecode->_cli = true;
	$input = isset($GLOBALS['argv'][1]) ? $GLOBALS['argv'][1] : 'php://stdin';
	$edidDecode->main($input);
	exit();
}
else if (isset($_REQUEST['fd']) && is_readable($_REQUEST['fd'])) {
	$input = $_REQUEST['fd'];
}
else if (!empty($_REQUEST['raw'])) {
	
	if (strpos($_REQUEST['raw'],'"EDID"=hex:') !== false) {
		$input = EdidDecode::regedit_decode($_REQUEST['raw']);
	} else {
		$input = base64_decode($_REQUEST['raw']);
	}
	$inputIsBinary = true;
}

$samples = get_edid_sample_files();
$samplesHTML = '';
foreach ($samples as $name => $fd) {
	$selected = isset($_REQUEST['fd']) && ($_REQUEST['fd'] == $fd) ? ' selected=selected' : '';
	$fd = htmlspecialchars($fd);
	$name = htmlspecialchars($name);
	$samplesHTML .= "<option value='$fd'$selected>$name</option>\n";
}
if (!empty($samples)) {
	$samplesHTML = "<select name='fd' onchange='submit()'>\n<option>Select..</option>\n" . $samplesHTML . "</select><br>";
}

$self = $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING'];
?>
<!doctype html>
<html lang=en>
<head>
<meta charset=utf-8>
<title>Online EDID Decoder</title>
</head>
<body>
<form method=post action='<?=$self?>'>
        <?=$samplesHTML?>
        <label>Base64-encoded EDID string</label><br>
        <textarea name=raw cols=80></textarea><br>
        <input type=submit value=Decode>
</form>
<pre>
<?php
if (isset($input)) {
	echo "<h2>Output</h2>";
	$edidDecode->main($input,$inputIsBinary);
}
?>
</pre>
</body>
</html>
<?php


function get_edid_sample_files() {

	$ret = array();
	$path = 'data';
	if ($dir_handle = @opendir($path)) {
	
		while (false !== ($file = readdir($dir_handle))) {
			if ($file == '.' || $file == '..') continue;
			$ret[$file] = "$path/$file";
		}
		closedir($dir_handle);
	}
	
	return $ret;
}
?>