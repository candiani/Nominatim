#!/usr/bin/php -Cq
<?php

	require_once(dirname(dirname(__FILE__)).'/lib/init-cmd.php');

	ini_set('memory_limit', '800M');
	ini_set('display_errors', 'stderr');

	$aCMDOptions = array(
			"Import and export special phrases",
			array('help', 'h', 0, 1, 0, 0, false, 'Show Help'),
			array('quiet', 'q', 0, 1, 0, 0, 'bool', 'Quiet output'),
			array('verbose', 'v', 0, 1, 0, 0, 'bool', 'Verbose output'),
			array('countries', '', 0, 1, 0, 0, 'bool', 'Create import script for country codes and names'),
			array('wiki-import', '', 0, 1, 0, 0, 'bool', 'Create import script for search phrases '),
			);
	getCmdOpt($_SERVER['argv'], $aCMDOptions, $aCMDResult, true, true);


	if ($aCMDResult['countries']) {
		fail("Country name import is no longer needed.");
	}

	if ($aCMDResult['wiki-import'])
	{
		$aPairs = array();

		$aBlacklist = explode(',', CONST_Tokenizer_TagBlacklist);
		foreach($aBlacklist as $sKey => $sValue)
		{
			$aBlacklist[$sKey] = explode('|', $sValue);
		}

		$aWhitelist = explode(',', CONST_Tokenizer_TagWhitelist);
		foreach($aWhitelist as $sKey => $sValue)
		{
			$aWhitelist[$sKey] = explode('|', $sValue);
		}

		foreach(explode('|', CONST_Tokenizer_Languages) as $sLanguage)
		{
			$sURL = 'http://wiki.openstreetmap.org/wiki/Special:Export/Nominatim/Special_Phrases/'.strtoupper($sLanguage);
			$sWikiPageXML = file_get_contents($sURL);
			if (preg_match_all('#\\| ([^|]+) \\|\\| ([^|]+) \\|\\| ([^|]+) \\|\\| ([^|]+) \\|\\| ([\\-YN])#', $sWikiPageXML, $aMatches, PREG_SET_ORDER))
			{
				foreach($aMatches as $aMatch)
				{
					$sLabel = trim($aMatch[1]);
					$sClass = trim($aMatch[2]);
					$sType = trim($aMatch[3]);
					# hack around a bug where building=yes was imported with
					# quotes into the wiki
					$sType = preg_replace('/&quot;/', '', $sType);
					# sanity check, in case somebody added garbage in the wiki
					if (preg_match('/^\\w+$/', $sClass) < 1 ||
						preg_match('/^\\w+$/', $sType) < 1)
					{
						fail("Bad class/type for language $sLanguage: $sClass=$sType");
					}
					# blacklisting: disallow certain class/type combinations
					if (isset($aBlacklist[$sClass])
					    && in_array($sType, $aBlacklist[$sClass]))
					{
						# fwrite(STDERR, "Blacklisted: ".$sClass."/".$sType."\n");
						continue;
					}
					# whitelisting: if class is in whitelist
					# allow only tags in the list
					if (isset($aWhitelist)
					    && !in_array($sType, $aWhitelist[$sClass]))
					{
						# fwrite(STDERR, "Non-Whitelisted: ".$sClass."/".$sType."\n");
						continue;
					}
					$aPairs[$sClass.'|'.$sType] = array($sClass, $sType);

					echo Tokenizer::createAmenitySql($sLabel, $sClass, $sType, trim($aMatch[4]));
				}
			}
		}

        echo "create index idx_placex_classtype on placex (class, type);";

		foreach($aPairs as $aPair)
		{
			echo "create table place_classtype_".pg_escape_string($aPair[0])."_".pg_escape_string($aPair[1]);
			if (CONST_Tablespace_Aux_Data)
				echo " tablespace ".CONST_Tablespace_Aux_Data;
			echo " as select place_id as place_id,st_centroid(geometry) as centroid from placex where ";
			echo "class = '".pg_escape_string($aPair[0])."' and type = '".pg_escape_string($aPair[1])."'";
			echo ";\n";

			echo "CREATE INDEX idx_place_classtype_".pg_escape_string($aPair[0])."_".pg_escape_string($aPair[1])."_centroid ";
			echo "ON place_classtype_".pg_escape_string($aPair[0])."_".pg_escape_string($aPair[1])." USING GIST (centroid)";
			if (CONST_Tablespace_Aux_Index)
				echo " tablespace ".CONST_Tablespace_Aux_Index;
			echo ";\n";

			echo "CREATE INDEX idx_place_classtype_".pg_escape_string($aPair[0])."_".pg_escape_string($aPair[1])."_place_id ";
			echo "ON place_classtype_".pg_escape_string($aPair[0])."_".pg_escape_string($aPair[1])." USING btree(place_id)";
			if (CONST_Tablespace_Aux_Index)
				echo " tablespace ".CONST_Tablespace_Aux_Index;
			echo ";\n";

            echo "GRANT SELECT ON place_classtype_".pg_escape_string($aPair[0])."_".pg_escape_string($aPair[1]).' TO "'.CONST_Database_Web_User."\";\n";

		}

        echo "drop index idx_placex_classtype;";
	}
