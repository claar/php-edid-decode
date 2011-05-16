<?php
/*
* Copyright 2006-2009 Red Hat, Inc.
*
* Permission is hereby granted, free of charge, to any person obtaining a
* copy of this software and associated documentation files (the "Software"),
* to deal in the Software without restriction, including without limitation
* on the rights to use, copy, modify, merge, publish, distribute, sub
* license, and/or sell copies of the Software, and to permit persons to whom
* the Software is furnished to do so, subject to the following conditions:
*
* The above copyright notice and this permission notice (including the next
* paragraph) shall be included in all copies or substantial portions of the
* Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
* IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
* FITNESS FOR A PARTICULAR PURPOSE AND NON-INFRINGEMENT.  IN NO EVENT SHALL
* THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
* IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
* CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/
/* Author: Adam Jackson <ajax@nwnk.net> */
/* PHP-port by: Ben Claar <ben.claar@gmail.com> */

error_reporting(E_ALL | E_STRICT);
// Turn off output buffering
while (@ob_end_flush());	
ob_implicit_flush();

/**
 * Files can be input by (all paths absolute or relative to php-edit-decode.php):
 *   1. CLI: Specifying a filepath as the first command line argument
 *          example: php php-edid-decode.php data/apple-cinemahd-30-dvi
 *   2. CLI: Piping a binary EDID file into STDIN
 *          example: php php-edid-decode.php < data/apple-cinemahd-30-dvi
 *   3. Web: Giving a file name as $_GET['fd']
 *          example: http://example.com/php-edid-decode.php?fd=data/apple-cinemahd-30-dvi
 *   4. Web: Giving a base64-encoded string as $_GET['raw64'] or $_POST['raw64']
 *   5. Library: Call EdidDecode::main($input), $input is a path to a binary EDID file 
 *          example:
 *            $edidDecode = new EdidDecode();
 *            $edidDecode->main('data/apple-cinemahd-30-dvi');
 *   6. Library: Call EdidDecode::main($input,true), $input is a binary EDID file
 *          example:
 *            $edidDecode = new EdidDecode();
 *            $edidDecode->main($binaryEDIDString,true);
 */
if (defined('PHP_SAPI') && PHP_SAPI=='cli') {
	$edidDecode = new EdidDecode();
	$edidDecode->_cli = true;
	$edidDecode->main();
}
else if (isset($_GET['fd']) && is_readable($_GET['fd'])) {
	$edidDecode = new EdidDecode();
	$edidDecode->main($_GET['fd']);
}
else if (!empty($_REQUEST['raw64'])) {
	$edidDecode = new EdidDecode();
	$edidDecode->main(base64_decode($_REQUEST['raw64']),true);
}
else if (isset($_REQUEST['showform'])) {
	$self = $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING'];
	echo <<<END
<!doctype html>
<html lang=en>
<head>
<meta charset=utf-8>
<title>Online EDID Decoder</title>
</head>
<body>
<form method=post action='$self'>
	<label>Base64-encoded EDID string</label><br>
	<textarea name=raw64 cols=80></textarea><br>
	<input type=submit value=Decode>
</form>
</body>
</html>
END;
}

class EdidDecode {

	public $_debug = false;
	public $_cli = false;

	public $claims_one_point_oh = 0;
	public $claims_one_point_two = 0;
	public $claims_one_point_three = 0;
	public $claims_one_point_four = 0;
	public $nonconformant_digital_display = 0;
	public $nonconformant_extension = 0;
	public $did_detailed_timing = 0;
	public $has_name_descriptor = 0;
	public $name_descriptor_terminated = 0;
	public $has_range_descriptor = 0;
	public $has_preferred_timing = 0;
	public $has_valid_checksum = 1;
	public $has_valid_cvt = 1;
	public $has_valid_dummy_block = 1;
	public $has_valid_week = 0;
	public $has_valid_year = 0;
	public $has_valid_detailed_blocks = 0;
	public $has_valid_extension_count = 0;
	public $has_valid_descriptor_ordering = 1;
	public $has_valid_descriptor_pad = 1;
	public $has_valid_range_descriptor = 1;
	public $has_valid_max_dotclock = 1;
	public $manufacturer_name_well_formed = 0;
	public $seen_non_detailed_descriptor = 0;
	
	public $warning_excessive_dotclock_correction = 0;
	public $warning_zero_preferred_refresh = 0;
	
	public $conformant = 1;
	
	public function manufacturer_name($x)
	{
		$name = chr(((ord($x[0]) & 0x7C) >> 2) + ord('@'));
		$name .= chr(((ord($x[0]) & 0x03) << 3) + ((ord($x[1]) & 0xE0) >> 5) + ord('@'));
		$name .= chr((ord($x[1]) & 0x1F) + ord('@'));
		
		if ($this->isupper($name))
			$this->manufacturer_name_well_formed = 1;
		
		return $name;
	}
	
	public function detailed_cvt_descriptor($x, $first)
	{
		$empty = array( 0, 0, 0 );
		$names = array( "50", "60", "75", "85" );
		$valid = 1;
		$fifty = 0; $sixty = 0; $seventyfive = 0; $eightyfive = 0; $reduced = 0;
		
		if (!$first && !$this->memcmp($x, $empty, 3))
		return $valid;
		
		$height = ord($x[0]);
		$height |= (ord($x[1]) & 0xf0) << 4;
		$height++;
		$height *= 2;
		
		switch (ord($x[1]) & 0x0c) {
		case 0x00:
			$width = ($height * 4) / 3; break;
		case 0x04:
			$width = ($height * 16) / 9; break;
		case 0x08:
			$width = ($height * 16) / 10; break;
		case 0x0c:
			$width = ($height * 15) / 9; break;
		}
		
		if (ord($x[1]) & 0x03)    $valid = 0;
		if (ord($x[2]) & 0x80)    $valid = 0;
		if (!(ord($x[2]) & 0x1f)) $valid = 0;
		
		$fifty       = (ord($x[2]) & 0x10);
		$sixty       = (ord($x[2]) & 0x08);
		$seventyfive = (ord($x[2]) & 0x04);
		$eightyfive  = (ord($x[2]) & 0x02);
		$reduced     = (ord($x[2]) & 0x01);
		
		if (!$valid) {
			printf("    (broken)\n");
		} else {
			printf("    %dx%d @ ( %s%s%s%s%s) Hz (%s%s preferred)\n", $width, $height,
			$fifty ? "50 " : "",
			$sixty ? "60 " : "",
			$seventyfive ? "75 " : "",
			$eightyfive ? "85 " : "",
			$reduced ? "60RB " : "",
			$names[(ord($x[2]) & 0x60) >> 5],
			(((ord($x[2]) & 0x60) == 0x20) && $reduced) ? "RB" : "");
		}
		
		return $valid;
	}
	
	/* 1 means valid data */
	public function detailed_block($x, $in_extension)
	{
		static $name;
		#int ha, hbl, hso, hspw, hborder, va, vbl, vso, vspw, vborder;
		#int i;
		#char phsync, pvsync, *$syncmethod;
		
		if ($this->_debug) {
			printf("Hex of detail: ");
			for ($i = 0; $i < 18; $i++) printf("%02x", ord($x[$i]));
			printf("\n");
		}
		
		if (ord($x[0]) == 0 && ord($x[1]) == 0) {
			/* Monitor descriptor block, not detailed timing descriptor. */
			if (ord($x[2]) != 0) {
				/* 1.3, 3.10.3 */
				printf("Monitor descriptor block has byte 2 nonzero (0x%02x)\n",
				ord($x[2]));
				$this->has_valid_descriptor_pad = 0;
			}
			if (ord($x[3]) != 0xfd && ord($x[4]) != 0x00) {
				/* 1.3, 3.10.3 */
				printf("Monitor descriptor block has byte 4 nonzero (0x%02x)\n",
				ord($x[4]));
				$this->has_valid_descriptor_pad = 0;
			}
			
			$this->seen_non_detailed_descriptor = 1;
			if (ord($x[3]) <= 0xF) {
				/*
				* in principle we can decode these, if we know what they are.
				* 0x0f seems to be common in laptop panels.
				* 0x0e is used by EPI: http://www.epi-standard.org/
				*/
				printf("Manufacturer-specified data, tag %d\n", ord($x[3]));
				return 1;
			}
			switch (ord($x[3])) {
			case 0x10:
				printf("Dummy block\n");
				for ($i = 5; $i < 18; $i++)
				if (ord($x[$i]) != 0x00)
				$this->has_valid_dummy_block = 0;
				return 1;
			case 0xF7:
				/* TODO */
				printf("Established timings III\n");
				return 1;
			case 0xF8:
				{
					$valid_cvt = 1; /* just this block */
					printf("CVT 3-byte code descriptor:\n");
					if (ord($x[5]) != 0x01) {
						$this->has_valid_cvt = 0;
						return 0;
					}
					for ($i = 0; $i < 4; $i++)
						$valid_cvt &= $this->detailed_cvt_descriptor($x + 6 + (i * 3), ($i == 0));
					$this->has_valid_cvt &= $valid_cvt;
					return $valid_cvt;
				}
			case 0xF9:
				/* TODO */
				printf("Color management data\n");
				return 1;
			case 0xFA:
				/* TODO */
				printf("More standard timings\n");
				return 1;
			case 0xFB:
				/* TODO */
				printf("Color point\n");
				return 1;
			case 0xFC:
				/* XXX should check for spaces after the \n */
				/* XXX check: terminated with 0x0A, padded with 0x20 */
				$this->has_name_descriptor = 1;
				if (strchr($name, "\n")) return 1;
				$name = substr($x,5,13);
				if (strchr($name, "\n")) {
					$this->name_descriptor_terminated = 1;
					printf("Monitor name: %s", $name);
				}
				return 1;
			case 0xFD:
				{
					$h_max_offset = 0;
					$h_min_offset = 0;
					$v_max_offset = 0;
					$v_min_offset = 0;
					$is_cvt = 0;
					$this->has_range_descriptor = 1;
					/* 
			* XXX todo: implement feature flags, vtd blocks
			* XXX check: ranges are well-formed; block termination if no vtd
			*/
					if ($this->claims_one_point_four) {
						if (ord($x[4]) & 0x02) {
							$v_max_offset = 255;
							if (ord($x[4]) & 0x01) {
								$v_min_offset = 255;
							}
						}
						if (ord($x[4]) & 0x04) {
							$h_max_offset = 255;
							if (ord($x[4]) & 0x03) {
								$h_min_offset = 255;
							}
						}
					} else if (ord($x[4])) {
						$this->has_valid_range_descriptor = 0;
					}
					
					/*
			* despite the values, this is not a bitfield.
			* XXX only 0x00 and 0x02 are legal for pre-1.4
			*/
					switch (ord($x[10])) {
					case 0x00: /* default gtf */
						break;
					case 0x01: /* range limits only */
						break;
					case 0x02: /* secondary gtf curve */
						break;
					case 0x04: /* cvt */
						$is_cvt = 1;
						break;
					default: /* invalid */
						break;
					}
					
					if (ord($x[5]) + $v_min_offset > ord($x[6]) + $v_max_offset)
					$this->has_valid_range_descriptor = 0;
					if (ord($x[7]) + $h_min_offset > ord($x[8]) + $h_max_offset)
					$this->has_valid_range_descriptor = 0;
					printf("Monitor ranges: %d-%dHZ vertical, %d-%dkHz horizontal",
					ord($x[5]) + $v_min_offset, ord($x[6]) + $v_max_offset,
					ord($x[7]) + $h_min_offset, ord($x[8]) + $h_max_offset);
					if (ord($x[9]))
					printf(", max dotclock %dMHz\n", ord($x[9]) * 10);
					else {
						if ($this->claims_one_point_four)
						$this->has_valid_max_dotclock = 0;
						printf("\n");
					}
					
					if ($is_cvt) {
						$max_h_pixels = 0;
						
						printf("CVT version %d.%d\n", ord($x[11]) & 0xf0 >> 4, ord($x[11]) & 0x0f);
						
						if (ord($x[12]) & 0xfc) {
							$raw_offset = (ord($x[12]) & 0xfc) >> 2;
							printf("Real max dotclock: %.2fMHz\n",
							(ord($x[9]) * 10) - ($raw_offset * 0.25));
							if ($raw_offset >= 40)
							$this->warning_excessive_dotclock_correction = 1;
						}
						
						$max_h_pixels = ord($x[12]) & 0x03;
						$max_h_pixels <<= 8;
						$max_h_pixels |= ord($x[13]);
						$max_h_pixels *= 8;
						if ($max_h_pixels)
						printf("Max active pixels per line: %d\n", $max_h_pixels);
						
						printf("Supported aspect ratios: %s %s %s %s %s\n",
						ord($x[14]) & 0x80 ? "4:3" : "",
						ord($x[14]) & 0x40 ? "16:9" : "",
						ord($x[14]) & 0x20 ? "16:10" : "",
						ord($x[14]) & 0x10 ? "5:4" : "",
						ord($x[14]) & 0x08 ? "15:9" : "");
						if (ord($x[14]) & 0x07)
						$this->has_valid_range_descriptor = 0;
						
						printf("Preferred aspect ratio: ");
						switch((ord($x[15]) & 0xe0) >> 5) {
						case 0x00: printf("4:3"); break;
						case 0x01: printf("16:9"); break;
						case 0x02: printf("16:10"); break;
						case 0x03: printf("5:4"); break;
						case 0x04: printf("15:9"); break;
						default: printf("(broken)"); break;
						}
						printf("\n");
						
						if (ord($x[15]) & 0x04)
						printf("Supports CVT standard blanking\n");
						if (ord($x[15]) & 0x10)
						printf("Supports CVT reduced blanking\n");
						
						if (ord($x[15]) & 0x07)
						$this->has_valid_range_descriptor = 0;
						
						if (ord($x[16]) & 0xf0) {
							printf("Supported display scaling:\n");
							if (ord($x[16]) & 0x80)
							printf("    Horizontal shrink\n");
							if (ord($x[16]) & 0x40)
							printf("    Horizontal stretch\n");
							if (ord($x[16]) & 0x20)
							printf("    Vertical shrink\n");
							if (ord($x[16]) & 0x10)
							printf("    Vertical stretch\n");
						}
						
						if (ord($x[16]) & 0x0f)
						$this->has_valid_range_descriptor = 0;
						
						if (ord($x[17]))
						printf("Preferred vertical refresh: %d Hz\n", ord($x[17]));
						else
						$this->warning_zero_preferred_refresh = 1;
					}
					
					/*
			* Slightly weird to return a global, but I've never seen any
			* EDID block wth two range descriptors, so it's harmless.
			*/
					return $this->has_valid_range_descriptor;
				}
			case 0xFE:
				/*
				* TODO: Two of these in a row, in the third and fouth slots,
				* seems to be specified by SPWG: http://www.spwg.org/
				*/
				/* XXX check: terminated with 0x0A, padded with 0x20 */
				printf("ASCII string: %s", substr($x,5,(strpos($x,"\x0A")-(5-1))));
				return 1;
			case 0xFF:
				/* XXX check: terminated with 0x0A, padded with 0x20 */
				printf("Serial number: %s", substr($x,5,(strpos($x,"\x0A")-(5-1))));
				return 1;
			default:
				printf("Unknown monitor description type %d\n", ord($x[3]));
				return 0;
			}
		}
		
		if ($this->seen_non_detailed_descriptor && !in_extension) {
			$this->has_valid_descriptor_ordering = 0;
		}
		
		$this->did_detailed_timing = 1;
		$ha = (ord($x[2]) + ((ord($x[4]) & 0xF0) << 4));
		$hbl = (ord($x[3]) + ((ord($x[4]) & 0x0F) << 8));
		$hso = (ord($x[8]) + ((ord($x[11]) & 0xC0) << 2));
		$hspw = (ord($x[9]) + ((ord($x[11]) & 0x30) << 4));
		$hborder = ord($x[15]);
		$va = (ord($x[5]) + ((ord($x[7]) & 0xF0) << 4));
		$vbl = (ord($x[6]) + ((ord($x[7]) & 0x0F) << 8));
		$vso = ((ord($x[10]) >> 4) + ((ord($x[11]) & 0x0C) << 2));
		$vspw = ((ord($x[10]) & 0x0F) + ((ord($x[11]) & 0x03) << 4));
		$vborder = ord($x[16]);
		switch ((ord($x[17]) & 0x18) >> 3) {
		case 0x00:
			$syncmethod = " analog composite";
			break;
		case 0x01:
			$syncmethod = " bipolar analog composite";
			break;
		case 0x02:
			$syncmethod = " digital composite";
			break;
		case 0x03:
			$syncmethod = "";
			break;
		}
		$pvsync = (ord($x[17]) & (1 << 2)) ? '+' : '-';
		$phsync = (ord($x[17]) & (1 << 1)) ? '+' : '-';
		
		printf("Detailed mode: Clock %.3f MHz, %d mm x %d mm\n" .
		"               %4d %4d %4d %4d hborder %d\n" .
		"               %4d %4d %4d %4d vborder %d\n" .
		"               %shsync %svsync%s%s\n",
		(ord($x[0]) + (ord($x[1]) << 8)) / 100.0,
		(ord($x[12]) + ((ord($x[14]) & 0xF0) << 4)),
		(ord($x[13]) + ((ord($x[14]) & 0x0F) << 8)),
		$ha, $ha + $hso, $ha + $hso + $hspw, $ha + $hbl, $hborder,
		$va, $va + $vso, $va + $vso + $vspw, $va + $vbl, $vborder,
		$phsync, $pvsync, $syncmethod, (ord($x[17]) & 0x80) ? " interlaced" : ""
		);
		/* XXX flag decode */
		
		return 1;
	}
	
	public function do_checksum($x)
	{
		printf("Checksum: 0x%x", ord($x[0x7f]));
		{
			$sum = 0;
			for ($i = 0; $i < 128; $i++) {
				$sum += ord($x[$i]);
				if ($sum > 255) $sum -= 256; // emulate unsigned char $sum -- range is 0 to 255
				// printf("%03d: x[i]: %04d, sum: %04d\n",$i,ord($x[$i]),$sum);
			}
			if ($sum) {
				printf(" (should be 0x%x)", (ord($x[0x7f]) - $sum));
				$this->has_valid_checksum = 0;
			}
		}
		printf("\n");
	}
	
	/* CEA extension */
	
	public function cea_video_block($x)
	{
		$length = ord($x[0]) & 0x1f;
		
		for ($i = 1; $i < $length; $i++)
		printf("    VIC %02d %s\n", ord($x[$i]) & 0x7f, ord($x[$i]) & 0x80 ? "(native)" : "");
	}
	
	public function cea_hdmi_block($x)
	{
		$length = ord($x[0]) & 0x1f;
		
		printf(" (HDMI)\n");
		printf("    Source physical address %d.%d.%d.%d\n", ord($x[4]) >> 4, ord($x[4]) & 0x0f,
		ord($x[5]) >> 4, ord($x[5]) & 0x0f);
		
		if ($length > 5) {
			if (ord($x[6]) & 0x80)
			printf("    Supports_AI\n");
			if (ord($x[6]) & 0x40)
			printf("    DC_48bit\n");
			if (ord($x[6]) & 0x20)
			printf("    DC_36bit\n");
			if (ord($x[6]) & 0x10)
			printf("    DC_30bit\n");
			if (ord($x[6]) & 0x08)
			printf("    DC_Y444\n");
			/* two reserved */
			if (ord($x[6]) & 0x01)
			printf("    DVI_Dual\n");
		}
		
		if ($length > 6)
		printf("    Maximum TMDS clock: %dMHz\n", ord($x[7]) * 5);
		
		/* latency info */
	}
	
	public function cea_block($x)
	{
		$oui;
		
		switch ((ord($x[0]) & 0xe0) >> 5) {
		case 0x01:
			printf("  Audio data block\n");
			break;
		case 0x02:
			printf("  Video data block\n");
			$this->cea_video_block($x);
			break;
		case 0x03:
			/* yes really, endianness lols */
			$oui = (ord($x[3]) << 16) + (ord($x[2]) << 8) + ord($x[1]);
			printf("  Vendor-specific data block, OUI %06x", oui);
			if ($oui == 0x000c03)
			$this->cea_hdmi_block($x);
			else
			printf("\n");
			break;
		case 0x04:
			printf("  Speaker allocation data block\n");
			break;
		case 0x05:
			printf("  VESA DTC data block\n");
			break;
		case 0x07:
			printf("  Extended tag: ");
			switch (ord($x[1])) {
			case 0x00:
				printf("video capability data block\n");
				break;
			case 0x01:
				printf("vendor-specific video data block\n");
				break;
			case 0x02:
				printf("VESA video display device information data block\n");
				break;
			case 0x03:
				printf("VESA video data block\n");
				break;
			case 0x04:
				printf("HDMI video data block\n");
				break;
			case 0x05:
				printf("Colorimetry data block\n");
				break;
			case 0x10:
				printf("CEA miscellaneous audio fields\n");
				break;
			case 0x11:
				printf("Vendor-specific audio data block\n");
				break;
			case 0x12:
				printf("HDMI audio data block\n");
				break;
			default:
				if (ord($x[1]) >= 6 && ord($x[1]) <= 15)
				printf("Reserved video block (%02x)\n", ord($x[1]));
				else if (ord($x[1]) >= 19 && ord($x[1]) <= 31)
				printf("Reserved audio block (%02x)\n", ord($x[1]));
				else
				printf("Unknown (%02x)\n", ord($x[1]));
				break;
			}
			break;
		default:
			{
				$tag = ($x & 0xe0) >> 5;
				$length = $x & 0x1f;
				printf("  Unknown tag %d, length %d (raw %02x)\n", $tag, $length, $x);
				break;
			}
		}
	}
	
	public function parse_cea($x)
	{
		$ret = 0;
		$version = ord($x[1]);
		$offset = ord($x[2]);
		
		if ($version >= 1) do {
			if ($version == 1 && ord($x[3]) != 0)
			$ret = 1;
			
			if ($offset < 4)
			break;
			
			if ($version < 3) {
				printf("%d 8-byte timing descriptors\n", ($offset - 4) / 8);
				if ($offset - 4 > 0)
				/* do stuff */ ;
			} else if ($version == 3) {
				printf("%d bytes of CEA data\n", $offset - 4);
				for ($i = 4; $i < $offset; $i += (ord($x[$i]) & 0x1f) + 1) {
					$this->cea_block($x + i);
				}
			}
			
			if ($version >= 2) {    
				if (ord($x[3]) & 0x80)
				printf("Underscans PC formats by default\n");
				if (ord($x[3]) & 0x40)
				printf("Basic audio support\n");
				if (ord($x[3]) & 0x20)
				printf("Supports YCbCr 4:4:4\n");
				if (ord($x[3]) & 0x10)
				printf("Supports YCbCr 4:2:2\n");
				printf("%d native detailed modes\n", ord($x[3]) & 0x0f);
			}
			
			for ($detailed = $x + $offset; $detailed + 18 < $x + 127; $detailed += 18)
			if ($detailed[0])
			$this->detailed_block($detailed, 1);
		} while (0);
		
		$this->do_checksum($x);
		
		return $ret;
	}
	
	/* generic extension code */
	
	public function extension_version($x)
	{
		printf("Extension version: %d\n", ord($x[1]));
	}
	
	public function parse_extension($x)
	{
		printf("\n");
		
		switch(ord($x[0])) {
		case 0x02:
			printf("CEA extension block\n");
			$this->extension_version($x);
			$conformant_extension = $this->parse_cea($x);
			break;
		case 0x10: printf("VTB extension block\n"); break;
		case 0x40: printf("DI extension block\n"); break;
		case 0x50: printf("LS extension block\n"); break;
		case 0x60: printf("DPVL extension block\n"); break;
		case 0xF0: printf("Block map\n"); break;
		case 0xFF: printf("Manufacturer-specific extension block\n");
		default:
			printf("Unknown extension block\n");
			break;
		}
		
		printf("\n");
		
		return $conformant_extension;
	}
	
	public $edid_lines = 0;
	
	public function extract_edid($fd)
	{
		$state = 0;
		$lines = 0;
		$out_index = 0;

		$ret = file_get_contents($fd);
		return $ret; // good enough for now.
		
		$start = strstr($ret, "EDID_DATA:");
		if ($start === FALSE) {
			$start = strstr($ret, "EDID:");
		}
		/* Look for xrandr --verbose output (8 lines of 16 hex bytes) */
		if ($start !== FALSE) {
			$indentation1 = "                ";
			$indentation2 = "\t\t";
			
			for ($i = 0; $i < 8; $i++) {
				
				/* Get the next start of the line of EDID hex. */
				$s = strstr($start, $indentation = $indentation1);
				if (!$s)
				$s = strstr($start, $indentation = $indentation2);
				if ($s == NULL) {
					return NULL;
				}
				$start = $s + strlen($indentation);
				
				$c = $start;
				for ($j = 0; $j < 16; $j++) {
					$buf;
					/* Read a %02x from the log */
					if (!isxdigit($c[0]) || !isxdigit($c[1])) {
						return NULL;
					}
					$buf[0] = $c[0];
					$buf[1] = $c[1];
					$buf[2] = 0;
					$out[$out_index++] = strtol($buf, NULL, 16);
					$c += 2;
				}
			}
			
			return $out;
		}
		
		/* wait, is this a log file? */
		for ($i = 0; $i < 8; $i++) {
			if (!isascii($ret[$i])) {
				$this->edid_lines = $len / 16;
				return $ret;
			}
		}
		
		/* i think it is, let's go scanning */
		if (!($start = strstr($ret, "EDID (in hex):")))
		return $ret;
		if (!($start = strstr($start, "(II)")))
		return $ret;
		
		for ($c = $start; $c; $c++) {
			if ($state == 0) {
				/* skip ahead to the : */
				if (!($c = strstr($c, ": \t")))
				break;
				/* and find the first number */
				while (!isxdigit($c[1]))
				$c++;
				$state = 1;
				$lines++;
			} else if ($state == 1) {
				$buf = array();
				/* Read a %02x from the log */
				if (!isxdigit($c)) {
					$state = 0;
					continue;
				}
				$buf[0] = $c[0];
				$buf[1] = $c[1];
				$buf[2] = 0;
				$out[$out_index++] = strtol($buf, NULL, 16);
				$c++;
			}
		}
		
		$this->edid_lines = $lines;
		
		return $out;
	}
	
	public $established_timings = array(
		/* 0x23 bit 7 - 0 */
		array(720, 400, 70), // x, y, refresh;
		array(720, 400, 88),
		array(640, 480, 60),
		array(640, 480, 67),
		array(640, 480, 72),
		array(640, 480, 75),
		array(800, 600, 56),
		array(800, 600, 60),
		/* 0x24 bit 7 - 0 */
		array(800, 600, 72),
		array(800, 600, 75),
		array(832, 624, 75),
		array(1280, 768, 87),
		array(1024, 768, 60),
		array(1024, 768, 70),
		array(1024, 768, 75),
		array(1280, 1024, 75),
		/* 0x25 bit 7 */
		array(1152, 870, 75),
	);
	
	public function print_subsection($name, $edid, $start, $end)
	{
		$i;
		
		printf("%s:", $name);
		for ($i = strlen($name); $i < 15; $i++)
		printf(" ");
		for ($i = $start; $i <= $end; $i++)
		printf(" %02x", ord($edid[$i]));
		printf("\n");
	}
	
	public function dump_breakdown($edid)
	{
		printf("Extracted contents:\n");
		$this->print_subsection("header", $edid, 0, 7);
		$this->print_subsection("serial number", $edid, 8, 17);
		$this->print_subsection("version", $edid,18, 19);
		$this->print_subsection("basic params", $edid, 20, 24);
		$this->print_subsection("chroma info", $edid, 25, 34);
		$this->print_subsection("established", $edid, 35, 37);
		$this->print_subsection("standard", $edid, 38, 53);
		$this->print_subsection("descriptor 1", $edid, 54, 71);
		$this->print_subsection("descriptor 2", $edid, 72, 89);
		$this->print_subsection("descriptor 3", $edid, 90, 107);
		$this->print_subsection("descriptor 4", $edid, 108, 125);
		$this->print_subsection("extensions", $edid, 126, 126);
		$this->print_subsection("checksum", $edid, 127, 127);
		printf("\n");
	}
	
	public function memcmp($a,$b,$len)
	{
		for ($i = 0; $i < $len; ++$i) {
			if (!isset($a[$i]) || !isset($b[$i]) || $a[$i] !== $b[$i]) {
				return false;
			}
		}
		return true;
	}
	
	public function isupper($i)
	{
		return (strtoupper($i) === $i);
	}
	
	public function islower($i) {
		return (strtolower($i) === $i);
	}
	
	public function main($input=null,$inputIsBinaryEDID=false)
	{
		if (!isset($input) && $this->_cli) {
			// Command line -- use filename if given, STDIN otherwise
			$input = isset($GLOBALS['argv'][1]) ? $GLOBALS['argv'][1] : 'php://stdin';
		} else {
			echo <<<END
<!doctype html>
<html lang=en>
<head>
<meta charset=utf-8>
<title>EDID Decoder Output</title>
</head>
<body>
<pre>
END;
		}

		if ($inputIsBinaryEDID) {
			$edid = $input;
		} else {
			$edid = $this->extract_edid($input);
			if (empty($edid)) {
				fprintf(stderr, "edid extract failed\n");
				return 1;
			}
		}
		
		$this->dump_breakdown($edid);
		
		if (empty($edid) || !$this->memcmp($edid, "\x00\xFF\xFF\xFF\xFF\xFF\xFF\x00", 8)) {
			printf("No header found\n");
			// return 1;
		}
		
		printf("Manufacturer: %s Model %x Serial Number %u\n",
		$this->manufacturer_name(substr($edid,0x08)),
		(ord($edid[0x0A]) + (ord($edid[0x0B]) << 8)),
		(ord($edid[0x0C]) + (ord($edid[0x0D]) << 8)
		+ (ord($edid[0x0E]) << 16) + (ord($edid[0x0F]) << 24)));
		/* XXX need manufacturer ID table */
		
		$ptm = localtime(time(),true);
		if (ord($edid[0x10]) < 55 || ord($edid[0x10]) == 0xff) {
			$this->has_valid_week = 1;
			if (ord($edid[0x11]) > 0x0f) {
				if (ord($edid[0x10]) == 0xff) {
					$this->has_valid_year = 1;
					printf("Made week %d of model year %d\n", ord($edid[0x10]),
					ord($edid[0x11]));
				} else if (ord($edid[0x11]) + 90 <= $ptm['tm_year']) {
					$this->has_valid_year = 1;
					printf("Made week %d of %d\n", ord($edid[0x10]), ord($edid[0x11]) + 1990);
				}
			}
		}
		
		printf("EDID version: %d.%d\n", ord($edid[0x12]), ord($edid[0x13]));
		if (ord($edid[0x12]) == 1) {
			if (ord($edid[0x13]) > 4) {
				printf("Claims > 1.4, assuming 1.4 conformance\n");
				#ord($edid[0x13]) = 4;
			}
			switch (ord($edid[0x13])) {
			case 4:
				$this->claims_one_point_four = 1;
			case 3:
				$this->claims_one_point_three = 1;
			case 2:
				$this->claims_one_point_two = 1;
			default:
				break;
			}
			$this->claims_one_point_oh = 1;
		}
		
		/* display section */
		
		if (ord($edid[0x14]) & 0x80) {
			$analog = 0;
			printf("Digital display\n");
			if ($this->claims_one_point_four) {
				$conformance_mask = 0;
				if ((ord($edid[0x14]) & 0x70) == 0x00)
				printf("Color depth is undefined\n");
				else if ((ord($edid[0x14]) & 0x70) == 0x70)
				$this->nonconformant_digital_display = 1;
				else
				printf("%d bits per primary color channel\n",
				((ord($edid[0x14]) & 0x70) >> 3) + 4);
				
				switch (ord($edid[0x14]) & 0x0f) {
				case 0x00: printf("Digital interface is not defined\n"); break;
				case 0x01: printf("DVI interface\n"); break;
				case 0x02: printf("HDMI-a interface\n"); break;
				case 0x03: printf("HDMI-b interface\n"); break;
				case 0x04: printf("MDDI interface\n"); break;
				case 0x05: printf("DisplayPort interface\n"); break;
				default:
					$this->nonconformant_digital_display = 1;
				}
			} else if ($this->claims_one_point_two) {
				$conformance_mask = 0x7E;
				if (ord($edid[0x14]) & 0x01) {
					printf("DFP 1.x compatible TMDS\n");
				}
			} else $conformance_mask = 0x7F;
			if (!$this->nonconformant_digital_display)
			$this->nonconformant_digital_display = ord($edid[0x14]) & $conformance_mask;
		} else {
			$analog = 1;
			$voltage = (ord($edid[0x14]) & 0x60) >> 5;
			$sync = (ord($edid[0x14]) & 0x0F);
			printf("analog display, Input voltage level: %s V\n",
			$voltage == 3 ? "0.7/0.7" :
			$voltage == 2 ? "1.0/0.4" :
			$voltage == 1 ? "0.714/0.286" :
			"0.7/0.3");
			
			if ($this->claims_one_point_four) {
				if (ord($edid[0x14]) & 0x10)
				printf("Blank-to-black setup/pedestal\n");
				else
				printf("Blank level equals black level\n");
			} else if (ord($edid[0x14]) & 0x10) {
				/*
			* XXX this is just the $x text.  1.3 says "if set, display expects
			* a blank-to-black setup or pedestal per appropriate Signal
			* Level Standard".  Whatever _that_ means.
			*/
				printf("Configurable signal levels\n");
			}
			
			printf("Sync: %s%s%s%s\n", $sync & 0x08 ? "Separate " : "",
			$sync & 0x04 ? "Composite " : "",
			$sync & 0x02 ? "SyncOnGreen " : "",
			$sync & 0x01 ? "Serration " : "");
		}
		
		if (ord($edid[0x15]) && ord($edid[0x16]))
		printf("Maximum image size: %d cm x %d cm\n", ord($edid[0x15]), ord($edid[0x16]));
		else if ($this->claims_one_point_four && (ord($edid[0x15]) || ord($edid[0x16]))) {
			if (ord($edid[0x15]))
			printf("Aspect ratio is %f (landscape)\n", 100.0/(ord($edid[0x16]) + 99));
			else
			printf("Aspect ratio is %f (portrait)\n", 100.0/(ord($edid[0x15]) + 99));
		} else {
			/* Either or both can be zero for 1.3 and before */
			printf("Image size is variable\n");
		}
		
		if (ord($edid[0x17]) == 0xff) {
			if ($this->claims_one_point_four)
			printf("Gamma is defined in an extension block\n");
			else
			/* XXX Technically 1.3 doesn't say this... */
			printf("Gamma: 1.0\n");
		} else printf("Gamma: %.2f\n", ((ord($edid[0x17]) + 100.0) / 100.0));
		
		if (ord($edid[0x18]) & 0xE0) {
			printf("DPMS levels:");
			if (ord($edid[0x18]) & 0x80) printf(" Standby");
			if (ord($edid[0x18]) & 0x40) printf(" Suspend");
			if (ord($edid[0x18]) & 0x20) printf(" Off");
			printf("\n");
		}
		
		/* FIXME: this is from 1.4 spec, check earlier */
		if ($analog) {
			switch (ord($edid[0x18]) & 0x18) {
			case 0x00: printf("Monochrome or grayscale display\n"); break;
			case 0x08: printf("RGB color display\n"); break;
			case 0x10: printf("Non-RGB color display\n"); break;
			case 0x18: printf("Undefined display color type\n");
			}
		} else {
			printf("Supported color formats: RGB 4:4:4");
			if (ord($edid[0x18]) & 0x10)
			printf(", YCrCb 4:4:4");
			if (ord($edid[0x18]) & 0x08)
			printf(", YCrCb 4:2:2");
			printf("\n");
		}
		
		if (ord($edid[0x18]) & 0x04)
		printf("Default (sRGB) color space is primary color space\n");
		if (ord($edid[0x18]) & 0x02) {
			printf("First detailed timing is preferred timing\n");
			$this->has_preferred_timing = 1;
		}
		if (ord($edid[0x18]) & 0x01)
		printf("Supports GTF timings within operating range\n");
		
		/* XXX color section */
		
		printf("Established timings supported:\n");
		for ($i = 0; $i < 17; $i++) {
			if (ord($edid[0x23 + $i / 8]) & (1 << (7 - $i % 8))) {
				printf("  %dx%d@%dHz\n", $this->established_timings[$i][0],
				$this->established_timings[$i][1], $this->established_timings[$i][2]);
			}
		}
		
		printf("Standard timings supported:\n");
		for ($i = 0; $i < 8; $i++) {
			$b1 = ord($edid[0x26 + $i * 2]);
			$b2 = ord($edid[0x26 + $i * 2 + 1]);
			
			if ($b1 == 0x01 && $b2 == 0x01)
			continue;
			
			if ($b1 == 0) {
				printf("non-conformant standard timing (0 horiz)\n");
				continue;
			}
			$x = ($b1 + 31) * 8;
			switch (($b2 >> 6) & 0x3) {
			case 0x00:
				$y = $x * 10 / 16;
				break;
			case 0x01:
				$y = $x * 3 / 4;
				break;
			case 0x02:
				$y = $x * 4 / 5;
				break;
			case 0x03:
				$y = $x * 9 / 15;
				break;
			}
			$refresh = 60 + ($b2 & 0x3f);
			
			printf("  %dx%d@%dHz\n", $x, $y, $refresh);
		}
		
		/* detailed timings */
		$this->has_valid_detailed_blocks = $this->detailed_block(substr($edid,0x36), 0);
		if ($this->has_preferred_timing && !$this->did_detailed_timing)
		$this->has_preferred_timing = 0; /* not really accurate... */
		$this->has_valid_detailed_blocks &= $this->detailed_block(substr($edid,0x48), 0);
		$this->has_valid_detailed_blocks &= $this->detailed_block(substr($edid,0x5A), 0);
		$this->has_valid_detailed_blocks &= $this->detailed_block(substr($edid,0x6C), 0);
		
		/* check this, 1.4 verification guide says otherwise */
		if (ord($edid[0x7e])) {
			printf("Has %d extension blocks\n", ord($edid[0x7e]));
			/* 2 is impossible because of the block map */
			if (ord($edid[0x7e]) != 2)
			$this->has_valid_extension_count = 1;
		} else {
			$this->has_valid_extension_count = 1;
		}
		
		$this->do_checksum($edid);
		
		$x = ord($edid);
		for ($this->edid_lines /= 8; $this->edid_lines > 1; $this->edid_lines--) {
			$x += 128;
			$this->nonconformant_digital_display += $this->parse_extension($x);
		}
		
		if ($this->claims_one_point_three) {
			if ($this->nonconformant_digital_display ||
					!$this->has_valid_descriptor_pad ||
					!$this->has_name_descriptor ||
					!$this->name_descriptor_terminated ||
					!$this->has_preferred_timing ||
					!$this->has_range_descriptor)
			$this->conformant = 0;
			if (!$this->conformant)
			printf("EDID block does NOT conform to EDID 1.3!\n");
			if ($this->nonconformant_digital_display)
			printf("\tDigital display field contains garbage: %x\n",
			$this->nonconformant_digital_display);
			if (!$this->has_name_descriptor)
			printf("\tMissing name descriptor\n");
			else if (!$this->name_descriptor_terminated)
			printf("\tName descriptor not terminated with a newline\n");
			if (!$this->has_preferred_timing)
			printf("\tMissing preferred timing\n");
			if (!$this->has_range_descriptor)
			printf("\tMissing monitor ranges\n");
			if (!$this->has_valid_descriptor_pad) /* Might be more than just 1.3 */
			printf("\tInvalid descriptor block padding\n");
		} else if ($this->claims_one_point_two) {
			if ($this->nonconformant_digital_display ||
					($this->has_name_descriptor && !$this->name_descriptor_terminated))
			$this->conformant = 0;
			if (!$this->conformant)
			printf("EDID block does NOT conform to EDID 1.2!\n");
			if ($this->nonconformant_digital_display)
			printf("\tDigital display field contains garbage: %x\n",
			$this->nonconformant_digital_display);
			if ($this->has_name_descriptor && !$this->name_descriptor_terminated)
			printf("\tName descriptor not terminated with a newline\n");
		} else if ($this->claims_one_point_oh) {
			if ($this->seen_non_detailed_descriptor)
			$this->conformant = 0;
			if (!$this->conformant)
			printf("EDID block does NOT conform to EDID 1.0!\n");
			if ($this->seen_non_detailed_descriptor)
			printf("\tHas descriptor blocks other than detailed timings\n");
		}
		
		if ($this->nonconformant_extension ||
				!$this->has_valid_checksum ||
				!$this->has_valid_cvt ||
				!$this->has_valid_year ||
				!$this->has_valid_week ||
				!$this->has_valid_detailed_blocks ||
				!$this->has_valid_dummy_block ||
				!$this->has_valid_extension_count ||
				!$this->has_valid_descriptor_ordering ||
				!$this->has_valid_range_descriptor ||
				!$this->manufacturer_name_well_formed) {
			$this->conformant = 0;
			printf("EDID block does not conform at all!\n");
			if ($this->nonconformant_extension)
			printf("\tHas at least one nonconformant extension block\n");
			if (!$this->has_valid_checksum)
			printf("\tBlock has broken checksum\n");
			if (!$this->has_valid_cvt)
			printf("\tBroken 3-byte CVT blocks\n");
			if (!$this->has_valid_year)
			printf("\tBad year of manufacture\n");
			if (!$this->has_valid_week)
			printf("\tBad week of manufacture\n");
			if (!$this->has_valid_detailed_blocks)
			printf("\tDetailed blocks filled with garbage\n");
			if (!$this->has_valid_dummy_block)
			printf("\tDummy block filled with garbage\n");
			if (!$this->has_valid_extension_count)
			printf("\tImpossible extension block count\n");
			if (!$this->manufacturer_name_well_formed)
			printf("\tManufacturer name field contains garbage\n");
			if (!$this->has_valid_descriptor_ordering)
			printf("\tInvalid detailed timing descriptor ordering\n");
			if (!$this->has_valid_range_descriptor)
			printf("\tRange descriptor contains garbage\n");
			if (!$this->has_valid_max_dotclock)
			printf("\tEDID 1.4 block does not set max dotclock\n");
		}
		
		if ($this->warning_excessive_dotclock_correction)
		printf("Warning: CVT block corrects dotclock by more than 9.75MHz\n");
		if ($this->warning_zero_preferred_refresh)
		printf("Warning: CVT block does not set preferred refresh rate\n");
		
		return !$this->conformant;
	}
}
