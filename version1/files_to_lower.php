<?php

for ($x=1; $x<=12; $x++) {
	//foreach (glob("$x/*.jpeg") as $src) {
	//foreach (glob("$x/*.JPG") as $src) {
	foreach (glob("$x/*.jpg") as $src) {
		$trg = strtolower($src);
		//$trg = preg_replace('/\.jpeg$/i','.jpg', $trg);
		if (preg_match('/[^\/a-z0-9_\-\.]/',$trg)) {
			$rep = array('/ä/'=>'ae','/ö/'=>'oe','/ü/'=>'ue','/ß/'=>'ss','/[\(\)]/'=>'');
			$trg = preg_replace(array_keys($rep),array_values($rep), $trg);
			$trg = preg_replace('/[^\/a-z0-9_\-\.]/','',$trg);
		} else {
			continue;
		}
		print "mv $src $trg .. ";
		print (rename($src,$trg) ? "ok" : "fail");
		print "<br>";
	}
}
