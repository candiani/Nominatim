<?php
	require_once(CONST_SitePath.'/modules/transliterate/tokenizer.php');
	require_once(CONST_BasePath.'/lib/Searches.php');

	class Geocode
	{
		protected $oDB;

		protected $aLangPrefOrder = array();

		protected $bIncludeAddressDetails = false;

		protected $bIncludePolygonAsPoints = false;
		protected $bIncludePolygonAsText = false;
		protected $bIncludePolygonAsGeoJSON = false;
		protected $bIncludePolygonAsKML = false;
		protected $bIncludePolygonAsSVG = false;
		protected $fPolygonSimplificationThreshold = 0.0;

		protected $aExcludePlaceIDs = array();
		protected $bDeDupe = true;
		protected $bReverseInPlan = true;

		protected $iLimit = 20;
		protected $iFinalLimit = 10;
		protected $iOffset = 0;
		protected $bFallback = false;

		protected $sCountryCodesSQL = false;
		protected $aNearPoint = false;

		protected $bBoundedSearch = false;
		protected $aViewBox = false;
		protected $sViewboxSmallSQL = false;
		protected $sViewboxLargeSQL = false;
		protected $sViewboxCentreSQL = false;
		protected $aRoutePoints = false;

		protected $iMinAddressRank = 0;
		protected $iMaxAddressRank = 30;
		protected $aAddressRankList = array();
		protected $exactMatchCache = array();

		protected $sAllowedTypesSQLList = false;

		protected $sQuery = false;
		protected $aStructuredQuery = false;

		function Geocode(&$oDB)
		{
			$this->oDB =& $oDB;
		}

		function setReverseInPlan($bReverse)
		{
			$this->bReverseInPlan = $bReverse;
		}

		function setLanguagePreference($aLangPref)
		{
			$this->aLangPrefOrder = $aLangPref;
		}

		function setIncludeAddressDetails($bAddressDetails = true)
		{
			$this->bIncludeAddressDetails = (bool)$bAddressDetails;
		}

		function getIncludeAddressDetails()
		{
			return $this->bIncludeAddressDetails;
		}

		function setIncludePolygonAsPoints($b = true)
		{
			$this->bIncludePolygonAsPoints = $b;
		}

		function getIncludePolygonAsPoints()
		{
			return $this->bIncludePolygonAsPoints;
		}

		function setIncludePolygonAsText($b = true)
		{
			$this->bIncludePolygonAsText = $b;
		}

		function getIncludePolygonAsText()
		{
			return $this->bIncludePolygonAsText;
		}

		function setIncludePolygonAsGeoJSON($b = true)
		{
			$this->bIncludePolygonAsGeoJSON = $b;
		}

		function setIncludePolygonAsKML($b = true)
		{
			$this->bIncludePolygonAsKML = $b;
		}

		function setIncludePolygonAsSVG($b = true)
		{
			$this->bIncludePolygonAsSVG = $b;
		}

		function setPolygonSimplificationThreshold($f)
		{
			$this->fPolygonSimplificationThreshold = $f;
		}

		function setDeDupe($bDeDupe = true)
		{
			$this->bDeDupe = (bool)$bDeDupe;
		}

		function setLimit($iLimit = 10)
		{
			if ($iLimit > 50) $iLimit = 50;
			if ($iLimit < 1) $iLimit = 1;

			$this->iFinalLimit = $iLimit;
			$this->iLimit = $this->iFinalLimit + min($this->iFinalLimit, 10);
		}

		function setOffset($iOffset = 0)
		{
			$this->iOffset = $iOffset;
		}

		function setFallback($bFallback = true)
		{
			$this->bFallback = (bool)$bFallback;
		}

		function setExcludedPlaceIDs($a)
		{
			// TODO: force to int
			$this->aExcludePlaceIDs = $a;
		}

		function getExcludedPlaceIDs()
		{
			return $this->aExcludePlaceIDs;
		}

		function setBounded($bBoundedSearch = true)
		{
			$this->bBoundedSearch = (bool)$bBoundedSearch;
		}

		function setViewBox($fLeft, $fBottom, $fRight, $fTop)
		{
			$this->aViewBox = array($fLeft, $fBottom, $fRight, $fTop);
		}

		function getViewBoxString()
		{
			if (!$this->aViewBox) return null;
			return $this->aViewBox[0].','.$this->aViewBox[3].','.$this->aViewBox[2].','.$this->aViewBox[1];
		}

		function setRoute($aRoutePoints)
		{
			$this->aRoutePoints = $aRoutePoints;
		}

		function setFeatureType($sFeatureType)
		{
			switch($sFeatureType)
			{
			case 'country':
				$this->setRankRange(4, 4);
				break;
			case 'state':
				$this->setRankRange(8, 8);
				break;
			case 'city':
				$this->setRankRange(14, 16);
				break;
			case 'settlement':
				$this->setRankRange(8, 20);
				break;
			}
		}

		function setRankRange($iMin, $iMax)
		{
			$this->iMinAddressRank = (int)$iMin;
			$this->iMaxAddressRank = (int)$iMax;
		}

		function setNearPoint($aNearPoint, $fRadiusDeg = 0.1)
		{
			$this->aNearPoint = array((float)$aNearPoint[0], (float)$aNearPoint[1], (float)$fRadiusDeg);
		}

		function setCountryCodesList($aCountryCodes)
		{
			$this->sCountryCodesSQL = join(',', array_map('addQuotes', $aCountryCodes));
		}

		function setQuery($sQueryString)
		{
			$this->sQuery = $sQueryString;
			$this->aStructuredQuery = false;
		}

		function getQueryString()
		{
			return $this->sQuery;
		}


		function loadParamArray($aParams)
		{
			if (isset($aParams['addressdetails'])) $this->bIncludeAddressDetails = (bool)$aParams['addressdetails'];
			if (isset($aParams['bounded'])) $this->bBoundedSearch = (bool)$aParams['bounded'];
			if (isset($aParams['dedupe'])) $this->bDeDupe = (bool)$aParams['dedupe'];

			if (isset($aParams['limit'])) $this->setLimit((int)$aParams['limit']);
			if (isset($aParams['offset'])) $this->iOffset = (int)$aParams['offset'];

			if (isset($aParams['fallback'])) $this->bFallback = (bool)$aParams['fallback'];

			// List of excluded Place IDs - used for more acurate pageing
			if (isset($aParams['exclude_place_ids']) && $aParams['exclude_place_ids'])
			{
				foreach(explode(',',$aParams['exclude_place_ids']) as $iExcludedPlaceID)
				{
					$iExcludedPlaceID = (int)$iExcludedPlaceID;
					if ($iExcludedPlaceID)
						$aExcludePlaceIDs[$iExcludedPlaceID] = $iExcludedPlaceID;
				}

				if (isset($aExcludePlaceIDs))
					$this->aExcludePlaceIDs = $aExcludePlaceIDs;
			}

			// Only certain ranks of feature
			if (isset($aParams['featureType'])) $this->setFeatureType($aParams['featureType']);
			if (isset($aParams['featuretype'])) $this->setFeatureType($aParams['featuretype']);

			// Country code list
			if (isset($aParams['countrycodes']))
			{
				$aCountryCodes = array();
				foreach(explode(',',$aParams['countrycodes']) as $sCountryCode)
				{
					if (preg_match('/^[a-zA-Z][a-zA-Z]$/', $sCountryCode))
					{
						$aCountryCodes[] = strtolower($sCountryCode);
					}
				}
				$this->setCountryCodesList($aCountryCodes);
			}

			if (isset($aParams['viewboxlbrt']) && $aParams['viewboxlbrt'])
			{
				$aCoOrdinatesLBRT = explode(',',$aParams['viewboxlbrt']);
				$this->setViewBox($aCoOrdinatesLBRT[0], $aCoOrdinatesLBRT[1], $aCoOrdinatesLBRT[2], $aCoOrdinatesLBRT[3]);
			}
			else if (isset($aParams['viewbox']) && $aParams['viewbox'])
			{
				$aCoOrdinatesLTRB = explode(',',$aParams['viewbox']);
				$this->setViewBox($aCoOrdinatesLTRB[0], $aCoOrdinatesLTRB[3], $aCoOrdinatesLTRB[2], $aCoOrdinatesLTRB[1]);
			}

			if (isset($aParams['route']) && $aParams['route'] && isset($aParams['routewidth']) && $aParams['routewidth'])
			{
				$aPoints = explode(',',$aParams['route']);
				if (sizeof($aPoints) % 2 != 0)
				{
					userError("Uneven number of points");
					exit;
				}
				$fPrevCoord = false;
				$aRoute = array();
				foreach($aPoints as $i => $fPoint)
				{
					if ($i%2)
					{
						$aRoute[] = array((float)$fPoint, $fPrevCoord);
					}
					else
					{
						$fPrevCoord = (float)$fPoint;
					}
				}
				$this->aRoutePoints = $aRoute;
			}
		}

		function setQueryFromParams($aParams)
		{
			// Search query
			$sQuery = (isset($aParams['q'])?trim($aParams['q']):'');
			if (!$sQuery)
			{
				$this->setStructuredQuery(@$aParams['amenity'], @$aParams['street'], @$aParams['city'], @$aParams['county'], @$aParams['state'], @$aParams['country'], @$aParams['postalcode']);
				$this->setReverseInPlan(false);
			}
			else
			{
				$this->setQuery($sQuery);
			}
		}

		function loadStructuredAddressElement($sValue, $sKey, $iNewMinAddressRank, $iNewMaxAddressRank, $aItemListValues)
		{
			$sValue = trim($sValue);
			if (!$sValue) return false;
			$this->aStructuredQuery[$sKey] = $sValue;
			if ($this->iMinAddressRank == 0 && $this->iMaxAddressRank == 30)
			{
				$this->iMinAddressRank = $iNewMinAddressRank;
				$this->iMaxAddressRank = $iNewMaxAddressRank;
			}
			if ($aItemListValues) $this->aAddressRankList = array_merge($this->aAddressRankList, $aItemListValues);
			return true;
		}

		function setStructuredQuery($sAmentiy = false, $sStreet = false, $sCity = false, $sCounty = false, $sState = false, $sCountry = false, $sPostalCode = false)
		{
			$this->sQuery = false;

			// Reset
			$this->iMinAddressRank = 0;
			$this->iMaxAddressRank = 30;
			$this->aAddressRankList = array();

			$this->aStructuredQuery = array();
			$this->sAllowedTypesSQLList = '';

			$this->loadStructuredAddressElement($sAmentiy, 'amenity', 26, 30, false);
			$this->loadStructuredAddressElement($sStreet, 'street', 26, 30, false);
			$this->loadStructuredAddressElement($sCity, 'city', 14, 24, false);
			$this->loadStructuredAddressElement($sCounty, 'county', 9, 13, false);
			$this->loadStructuredAddressElement($sState, 'state', 8, 8, false);
			$this->loadStructuredAddressElement($sPostalCode, 'postalcode' , 5, 11, array(5, 11));
			$this->loadStructuredAddressElement($sCountry, 'country', 4, 4, false);

			if (sizeof($this->aStructuredQuery) > 0) 
			{
				$this->sQuery = join(', ', $this->aStructuredQuery);
				if ($this->iMaxAddressRank < 30)
				{
					$sAllowedTypesSQLList = '(\'place\',\'boundary\')';
				}
			}
		}

		function fallbackStructuredQuery()
		{
			if (!$this->aStructuredQuery) return false;

			$aParams = $this->aStructuredQuery;

			if (sizeof($aParams) == 1) return false;

			$aOrderToFallback = array('postalcode', 'street', 'city', 'county', 'state');

			foreach($aOrderToFallback as $sType)
			{
				if (isset($aParams[$sType]))
				{
					unset($aParams[$sType]);
					$this->setStructuredQuery(@$aParams['amenity'], @$aParams['street'], @$aParams['city'], @$aParams['county'], @$aParams['state'], @$aParams['country'], @$aParams['postalcode']);
					return true;
				}
			}

			return false;
		}

		function getDetails($aPlaceIDs)
		{
			if (sizeof($aPlaceIDs) == 0)  return array();

			$sLanguagePrefArraySQL = "ARRAY[".join(',',array_map("getDBQuoted",$this->aLangPrefOrder))."]";

			// Get the details for display (is this a redundant extra step?)
			$sPlaceIDs = join(',',$aPlaceIDs);

			$sImportanceSQL = '';
			if ($this->sViewboxSmallSQL) $sImportanceSQL .= " case when ST_Contains($this->sViewboxSmallSQL, ST_Collect(centroid)) THEN 1 ELSE 0.75 END * ";
			if ($this->sViewboxLargeSQL) $sImportanceSQL .= " case when ST_Contains($this->sViewboxLargeSQL, ST_Collect(centroid)) THEN 1 ELSE 0.75 END * ";

			$sSQL = "select osm_type,osm_id,class,type,admin_level,rank_search,rank_address,min(place_id) as place_id, min(parent_place_id) as parent_place_id, calculated_country_code as country_code,";
			$sSQL .= "get_address_by_language(place_id, $sLanguagePrefArraySQL) as langaddress,";
			$sSQL .= "get_name_by_language(name, $sLanguagePrefArraySQL) as placename,";
			$sSQL .= "get_name_by_language(name, ARRAY['ref']) as ref,";
			$sSQL .= "avg(ST_X(centroid)) as lon,avg(ST_Y(centroid)) as lat, ";
			$sSQL .= $sImportanceSQL."coalesce(importance,0.75-(rank_search::float/40)) as importance, ";
			$sSQL .= "(select max(p.importance*(p.rank_address+2)) from place_addressline s, placex p where s.place_id = min(CASE WHEN placex.rank_search < 28 THEN placex.place_id ELSE placex.parent_place_id END) and p.place_id = s.address_place_id and s.isaddress and p.importance is not null) as addressimportance, ";
			$sSQL .= "(extratags->'place') as extra_place ";
			$sSQL .= "from placex where place_id in ($sPlaceIDs) ";
			$sSQL .= "and (placex.rank_address between $this->iMinAddressRank and $this->iMaxAddressRank ";
			if (14 >= $this->iMinAddressRank && 14 <= $this->iMaxAddressRank) $sSQL .= " OR (extratags->'place') = 'city'";
			if ($this->aAddressRankList) $sSQL .= " OR placex.rank_address in (".join(',',$this->aAddressRankList).")";
			$sSQL .= ") ";
			if ($this->sAllowedTypesSQLList) $sSQL .= "and placex.class in $this->sAllowedTypesSQLList ";
			$sSQL .= "and linked_place_id is null ";
			$sSQL .= "group by osm_type,osm_id,class,type,admin_level,rank_search,rank_address,calculated_country_code,importance";
			if (!$this->bDeDupe) $sSQL .= ",place_id";
			$sSQL .= ",langaddress ";
			$sSQL .= ",placename ";
			$sSQL .= ",ref ";
			$sSQL .= ",extratags->'place' ";

			if (30 >= $this->iMinAddressRank && 30 <= $this->iMaxAddressRank)
			{
				$sSQL .= " union ";
				$sSQL .= "select 'T' as osm_type,place_id as osm_id,'place' as class,'house' as type,null as admin_level,30 as rank_search,30 as rank_address,min(place_id) as place_id, min(parent_place_id) as parent_place_id,'us' as country_code,";
				$sSQL .= "get_address_by_language(place_id, $sLanguagePrefArraySQL) as langaddress,";
				$sSQL .= "null as placename,";
				$sSQL .= "null as ref,";
				$sSQL .= "avg(ST_X(centroid)) as lon,avg(ST_Y(centroid)) as lat, ";
				$sSQL .= $sImportanceSQL."-1.15 as importance, ";
				$sSQL .= "(select max(p.importance*(p.rank_address+2)) from place_addressline s, placex p where s.place_id = min(location_property_tiger.parent_place_id) and p.place_id = s.address_place_id and s.isaddress and p.importance is not null) as addressimportance, ";
				$sSQL .= "null as extra_place ";
				$sSQL .= "from location_property_tiger where place_id in ($sPlaceIDs) ";
				$sSQL .= "and 30 between $this->iMinAddressRank and $this->iMaxAddressRank ";
				$sSQL .= "group by place_id";
				if (!$this->bDeDupe) $sSQL .= ",place_id ";
				$sSQL .= " union ";
				$sSQL .= "select 'L' as osm_type,place_id as osm_id,'place' as class,'house' as type,null as admin_level,30 as rank_search,30 as rank_address,min(place_id) as place_id, min(parent_place_id) as parent_place_id,'us' as country_code,";
				$sSQL .= "get_address_by_language(place_id, $sLanguagePrefArraySQL) as langaddress,";
				$sSQL .= "null as placename,";
				$sSQL .= "null as ref,";
				$sSQL .= "avg(ST_X(centroid)) as lon,avg(ST_Y(centroid)) as lat, ";
				$sSQL .= $sImportanceSQL."-1.10 as importance, ";
				$sSQL .= "(select max(p.importance*(p.rank_address+2)) from place_addressline s, placex p where s.place_id = min(location_property_aux.parent_place_id) and p.place_id = s.address_place_id and s.isaddress and p.importance is not null) as addressimportance, ";
				$sSQL .= "null as extra_place ";
				$sSQL .= "from location_property_aux where place_id in ($sPlaceIDs) ";
				$sSQL .= "and 30 between $this->iMinAddressRank and $this->iMaxAddressRank ";
				$sSQL .= "group by place_id";
				if (!$this->bDeDupe) $sSQL .= ",place_id";
				$sSQL .= ",get_address_by_language(place_id, $sLanguagePrefArraySQL) ";
			}

			$sSQL .= " order by importance desc";
			if (CONST_Debug) { echo "<hr>"; var_dump($sSQL); }
			$aSearchResults = $this->oDB->getAll($sSQL);

			if (PEAR::IsError($aSearchResults))
			{
				failInternalError("Could not get details for place.", $sSQL, $aSearchResults);
			}

			return $aSearchResults;
		}

		private function sqlCountryCodes()
		{
			return " and calculated_country_code in ($this->sCountryCodesSQL)";
		}

		private function queryForClass($sClassType, $sViewbox, $sViewboxCentreSQL)
		{
			$sSQL = 'select place_id from place_classtype_'.$sClassType.' ct';
			if ($this->sCountryCodesSQL) $sSQL .= ' join placex using (place_id)';
			$sSQL .= " where st_contains($sViewbox, ct.centroid)";
			if ($this->sCountryCodesSQL) $sSQL .= $this->sqlCountryCodes();
			if (sizeof($this->aExcludePlaceIDs))
			{
				$sSQL .= " and place_id not in (".join(',',$this->aExcludePlaceIDs).")";
			}
			if ($sViewboxCentreSQL) $sSQL .= " order by st_distance($sViewboxCentreSQL, ct.centroid) asc";
			$sSQL .= " limit $this->iLimit";
			if (CONST_Debug) var_dump($sSQL);

			return $this->oDB->getCol($sSQL);
		}

		private function queryForHousenumber($sHouseNumber, $sTable, $sPlaceIDs)
		{
			$sSQL = 'select place_id from '.$sTable;
			$sSQL.= ' where parent_place_id in ('.$sPlaceIDs.')';
			$sSQL.= "and make_standard_ref(housenumber) ~* E'";
			$sSQL.= '\\\\m'.pg_escape_string($sHouseNumber).'\\\\M'."'";
			if (sizeof($this->aExcludePlaceIDs))
			{
				$sSQL .= " and place_id not in (".join(',',$this->aExcludePlaceIDs).")";
			}
			$sSQL .= " limit $this->iLimit";
			if (CONST_Debug) var_dump($sSQL);
			return $this->oDB->getCol($sSQL);
		}

		private function executeSingleSearch(&$aSearch, $bBoundingBoxSearch, &$oWords)
		{
			if (!$aSearch->hasLocationTerm())
			{
				if ($aSearch->isCountrySearch())
				{
					// Just looking for a country by code - look it up
					if (4 >= $this->iMinAddressRank && 4 <= $this->iMaxAddressRank)
					{
						$sSQL = "select place_id from placex where calculated_country_code='".$aSearch->getCountryCode()."' and rank_search = 4";
						if ($this->sCountryCodesSQL) $sSQL .= $this->sqlCountryCodes();
						if ($bBoundingBoxSearch)
							$sSQL .= " and _st_intersects($this->sViewboxSmallSQL, geometry)";
						$sSQL .= " order by st_area(geometry) desc limit 1";
						if (CONST_Debug) var_dump($sSQL);
						$aPlaceIDs = $this->oDB->getCol($sSQL);
					}
					else
					{
						$aPlaceIDs = array();
					}
				}
				else
				{
					if (!$bBoundingBoxSearch) return array();
					if (!$aSearch->hasClass()) return array();
					// class search
					$sSQL = "select count(*) from pg_tables where tablename = 'place_classtype_".$aSearch->getClassType()."'";
					if ($this->oDB->getOne($sSQL))
					{
						$aPlaceIDs = $this->queryForClass($aSearch->getClassType(), $this->sViewboxSmallSQL, $this->sViewboxCentreSQL);
						// If excluded place IDs are given, it is fair to assume that
						// there have been results in the small box, so no further
						// expansion in that case.
						// Also don't expand if bounded results were requested.
						if (!sizeof($aPlaceIDs) && !sizeof($this->aExcludePlaceIDs) && !$this->bBoundedSearch)
						{
							$aPlaceIDs = $this->queryForClass($aSearch->getClassType(), $this->sViewboxLargeSQL);
						}
					}
					else
					{
						$sSQL = "select place_id from placex where class='".$aSearch->getClass()."' and type='".$aSearch->getType()."'";
						$sSQL .= " and st_contains($this->sViewboxSmallSQL, geometry) and linked_place_id is null";
						if ($this->sCountryCodesSQL) $sSQL .= $this->sqlCountryCodes();
						if ($this->sViewboxCentreSQL)	$sSQL .= " order by st_distance($this->sViewboxCentreSQL, centroid) asc";
						$sSQL .= " limit $this->iLimit";
						if (CONST_Debug) var_dump($sSQL);
						$aPlaceIDs = $this->oDB->getCol($sSQL);
					}
				}
			}
			else
			{
				$aPlaceIDs = array();

				// First we need a position, either aName or fLat or both
				$aTerms = array();
				$aOrder = array();

				if ($aSearch->isHouseNumberSearch())
				{
					$sHouseNumberRegex = '\\\\m'.pg_escape_string($aSearch->getHouseNumber()).'\\\\M';
					$aOrder[] = "exists(select place_id from placex where parent_place_id = search_name.place_id and make_standard_ref(housenumber) ~* E'".$sHouseNumberRegex."' limit 1) desc";
				}

				if ($aSearch->hasTokens(TokenType::Name))
				{
					$aTerms[] = 'name_vector @> ARRAY['.$aSearch->getTokenList(TokenType::Name).']';
				}
				if ($aSearch->hasTokens(TokenType::NonName))
				{
					$aTerms[] = 'array_cat(name_vector,ARRAY[]::integer[]) @> ARRAY['.$aSearch->getTokenList(TokenType::NonName).']';
				}
				if ($aSearch->hasTokens(TokenType::Address))
				{
					// For infrequent name terms disable index usage for address
					if (CONST_Search_NameOnlySearchFrequencyThreshold &&
							sizeof($aSearch->getTokens(TokenType::Name)) == 1 &&
							$oWords->getWordFrequency($aSearch->getFirstName()) < CONST_Search_NameOnlySearchFrequencyThreshold)
					{
						$aTerms[] = 'array_cat(nameaddress_vector,ARRAY[]::integer[]) @> ARRAY['.join(',', array_merge($aSearch->getTokens(TokenType::Address), $aSearch->getTokens(TokenType::NonAddress))).']';
					}
					else
					{
						$aTerms[] = 'nameaddress_vector @> ARRAY['.$aSearch->getTokenList(TokenType::Address).']';
						if ($aSearch->hasTokens(TokenType::NonAddress)) $aTerms[] = 'array_cat(nameaddress_vector,ARRAY[]::integer[]) @> ARRAY['.$aSearch->getTokenList(TokenType::NonAddress).']';
					}
				}
				if ($aSearch->hasCountryCode()) $aTerms[] = "country_code = '".pg_escape_string($aSearch->getCountryCode())."'";
				if ($aSearch->hasHouseNumber())
				{
					$aTerms[] = "address_rank between 16 and 27";
				}
				else
				{
					if ($this->iMinAddressRank > 0)
					{
						$aTerms[] = "address_rank >= ".$this->iMinAddressRank;
					}
					if ($this->iMaxAddressRank < 30)
					{
						$aTerms[] = "address_rank <= ".$this->iMaxAddressRank;
					}
				}
				if ($aSearch->hasNearPoint())
				{
					$aTerms[] = 'ST_DWithin(centroid,'.$aSearch->sqlNearPoint().','.$aSearch->getRadius().')';
					$aOrder[] = 'ST_Distance(centroid,'.$aSearch->sqlNearPoint().') ASC';
				}
				if (sizeof($this->aExcludePlaceIDs))
				{
					$aTerms[] = "place_id not in (".join(',',$this->aExcludePlaceIDs).")";
				}
				if ($this->sCountryCodesSQL)
				{
					$aTerms[] = "country_code in ($this->sCountryCodesSQL)";
				}

				if ($bBoundingBoxSearch) $aTerms[] = "centroid && $this->sViewboxSmallSQL";

				if ($aSearch->hasHouseNumber())
				{
					$sImportanceSQL = '- abs(26 - address_rank) + 3';
				}
				else
				{
					$sImportanceSQL = '(case when importance = 0 OR importance IS NULL then 0.75-(search_rank::float/40) else importance end)';
				}
				if ($this->sViewboxSmallSQL) $sImportanceSQL .= " * case when ST_Contains($this->sViewboxSmallSQL, centroid) THEN 1 ELSE 0.5 END";
				if ($this->sViewboxLargeSQL) $sImportanceSQL .= " * case when ST_Contains($this->sViewboxLargeSQL, centroid) THEN 1 ELSE 0.5 END";

				$aOrder[] = "$sImportanceSQL DESC";
				if ($aSearch->hasTokens(TokenType::Full))
				{
					$sExactMatchSQL = '(select count(*) from (select unnest(ARRAY['.$aSearch->getTokenList(TokenType::Full).']) INTERSECT select unnest(nameaddress_vector))s) as exactmatch';
					$aOrder[] = 'exactmatch DESC';
				} else {
					$sExactMatchSQL = '0::int as exactmatch';
				}

				if (sizeof($aTerms))
				{
					$sSQL = "select place_id, ";
					$sSQL .= $sExactMatchSQL;
					$sSQL .= " from search_name";
					$sSQL .= " where ".join(' and ',$aTerms);
					$sSQL .= " order by ".join(', ',$aOrder);
					if ($aSearch->hasHouseNumber() || $aSearch->hasClass())
						$sSQL .= " limit 20";
					elseif (!$aSearch->hasTokens(TokenType::Name) && !$aSearch->hasTokens(TokenType::Address) && $aSearch->hasClass())
						$sSQL .= " limit 1";
					else
						$sSQL .= " limit ".$this->iLimit;

					if (CONST_Debug) { var_dump($sSQL); }
					$aViewBoxPlaceIDs = $this->oDB->getAll($sSQL);
					if (PEAR::IsError($aViewBoxPlaceIDs))
					{
						failInternalError("Could not get places for search terms.", $sSQL, $aViewBoxPlaceIDs);
					}
					//var_dump($aViewBoxPlaceIDs);
					// Did we have an viewbox matches?
					$aPlaceIDs = array();
					$bViewBoxMatch = false;
					foreach($aViewBoxPlaceIDs as $aViewBoxRow)
					{
						$aPlaceIDs[] = $aViewBoxRow['place_id'];
						$this->exactMatchCache[$aViewBoxRow['place_id']] = $aViewBoxRow['exactmatch'];
					}
				}

				if ($aSearch->hasHouseNumber() && sizeof($aPlaceIDs))
				{
					$aRoadPlaceIDs = $aPlaceIDs;
					$sPlaceIDs = join(',',$aPlaceIDs);
					$sHouseNumberSQL = pg_escape_string($aSearch->getHouseNumber());

					// Now they are indexed look for a house attached to a street we found
					$aPlaceIDs = $this->queryForHousenumber($sHouseNumberSQL,
					                                        'placex', $sPlaceIDs);

					// If not try the aux fallback table
					if (!sizeof($aPlaceIDs))
					{
						$aPlaceIDs = $this->queryForHousenumber($sHouseNumberSQL,
						                                       'location_property_aux',
						                                       $sPlaceIDs);
					}

					// And fall back to tiger data for the us
					if (!sizeof($aPlaceIDs)
						&& (!$aSearch->hasCountryCode() ||
							$aSearch->getCountryCode() == 'us'))
					{
						$aPlaceIDs = $this->queryForHousenumber($sHouseNumberSQL,
						                                       'location_property_tiger',
						                                       $sPlaceIDs);
					}

					// Fallback to the road
					if (!sizeof($aPlaceIDs)
						&& preg_match('/[0-9]+/', $aSearch->getHouseNumber()))
					{
						$aPlaceIDs = $aRoadPlaceIDs;
					}
				}

				if ($aSearch->hasClass() && sizeof($aPlaceIDs))
				{
					$sPlaceIDs = join(',',$aPlaceIDs);
					$aClassPlaceIDs = array();

					if ($aSearch->hasOperator('name'))
					{
						// If they were searching for a named class (i.e. 'Kings Head pub')
						// then we might have an extra match
						$sSQL = "select place_id from placex where place_id in ($sPlaceIDs) and class='".$aSearch->getClass()."' and type='".$aSearch->getType()."'";
						$sSQL .= ' and linked_place_id is null';
						if ($this->sCountryCodesSQL) $sSQL .= $this->sqlCountryCodes();
						$sSQL .= " order by rank_search asc limit $this->iLimit";
						if (CONST_Debug) var_dump($sSQL);
						$aClassPlaceIDs = $this->oDB->getCol($sSQL);
					}

					if ($aSearch->hasOperator('near')) // & in
					{
						$sSQL = "select count(*) from pg_tables where tablename = 'place_classtype_".$aSearch->getClassType()."'";
						$bCacheTable = $this->oDB->getOne($sSQL);

						$sSQL = "select min(rank_search) from placex where place_id in ($sPlaceIDs)";

						if (CONST_Debug) var_dump($sSQL);
						$iMaxRank = ((int)$this->oDB->getOne($sSQL));

						// For state / country level searches the normal radius search doesn't work very well
						$sPlaceGeom = false;
						if ($iMaxRank < 9 && $bCacheTable)
						{
							// Try and get a polygon to search in instead
							$sSQL = "select geometry from placex where place_id in ($sPlaceIDs) and rank_search < $iMaxRank + 5 and st_geometrytype(geometry) in ('ST_Polygon','ST_MultiPolygon') order by rank_search asc limit 1";
							if (CONST_Debug) var_dump($sSQL);
							$sPlaceGeom = $this->oDB->getOne($sSQL);
						}

						if ($sPlaceGeom)
						{
							$sPlaceIDs = false;
						}
						else
						{
							$iMaxRank += 5;
							$sSQL = "select place_id from placex where place_id in ($sPlaceIDs) and rank_search < $iMaxRank";
							if (CONST_Debug) var_dump($sSQL);
							$aPlaceIDs = $this->oDB->getCol($sSQL);
							$sPlaceIDs = join(',',$aPlaceIDs);
						}

						if ($sPlaceIDs || $sPlaceGeom)
						{

							$fRange = 0.01;
							if ($bCacheTable)
							{
								// More efficient - can make the range bigger
								$fRange = 0.05;

								$sOrderBySQL = '';
								if ($aSearch->hasNearPoint()) $sOrderBySQL = "ST_Distance($aSearch->sqlNearPoint(), l.centroid)";
								else if ($sPlaceIDs) $sOrderBySQL = "ST_Distance(l.centroid, f.geometry)";
								else if ($sPlaceGeom) $sOrderBysSQL = "ST_Distance(st_centroid('".$sPlaceGeom."'), l.centroid)";

								$sSQL = "select distinct l.place_id".($sOrderBySQL?','.$sOrderBySQL:'').' from place_classtype_'.$aSearch->getClassType().' as l';
								if ($this->sCountryCodesSQL) $sSQL .= " join placex as lp using (place_id)";
								if ($sPlaceIDs)
								{
									$sSQL .= ",placex as f where ";
									$sSQL .= "f.place_id in ($sPlaceIDs) and ST_DWithin(l.centroid, f.centroid, $fRange) ";
								}
								if ($sPlaceGeom)
								{
									$sSQL .= " where ";
									$sSQL .= "ST_Contains('".$sPlaceGeom."', l.centroid) ";
								}
								if (sizeof($this->aExcludePlaceIDs))
								{
									$sSQL .= " and l.place_id not in (".join(',',$this->aExcludePlaceIDs).")";
								}
								if ($this->sCountryCodesSQL) $sSQL .= " and lp.calculated_country_code in ($this->sCountryCodesSQL)";
								if ($sOrderBySQL) $sSQL .= "order by ".$sOrderBySQL." asc";
								if ($this->iOffset) $sSQL .= " offset $this->iOffset";
								$sSQL .= " limit $this->iLimit";
								if (CONST_Debug) var_dump($sSQL);
								$aClassPlaceIDs = array_merge($aClassPlaceIDs, $this->oDB->getCol($sSQL));
							}
							else
							{
								if ($aSearch->hasRadius()) $fRange = $aSearch->getRadius();

								$sOrderBySQL = '';
								if ($aSearch->hasNearPoint()) $sOrderBySQL = 'ST_Distance('.$aSearch->sqlNearPoint().', l.geometry)';
								else $sOrderBySQL = 'ST_Distance(l.geometry, f.geometry)';

								$sSQL = "select distinct l.place_id".($sOrderBysSQL?','.$sOrderBysSQL:'')." from placex as l,placex as f where ";
								$sSQL .= "f.place_id in ( $sPlaceIDs) and ST_DWithin(l.geometry, f.centroid, $fRange) ";
								$sSQL .= "and l.class='".$aSearch->getClass()."' and l.type='".$aSearch->getType()."' ";
								if (sizeof($this->aExcludePlaceIDs))
								{
									$sSQL .= " and l.place_id not in (".join(',',$this->aExcludePlaceIDs).")";
								}
								if ($this->sCountryCodesSQL) $sSQL .= " and l.calculated_country_code in ($this->sCountryCodesSQL)";
								if ($sOrderBy) $sSQL .= "order by ".$OrderBysSQL." asc";
								if ($this->iOffset) $sSQL .= " offset $this->iOffset";
								$sSQL .= " limit $this->iLimit";
								if (CONST_Debug) var_dump($sSQL);
								$aClassPlaceIDs = array_merge($aClassPlaceIDs, $this->oDB->getCol($sSQL));
							}
						}
					}

					$aPlaceIDs = $aClassPlaceIDs;

				}

			}

			return $aPlaceIDs;
		}

		/* Perform the actual query lookup.

			Returns an ordered list of results, each with the following fields:
			  osm_type: type of corresponding OSM object
							N - node
							W - way
							R - relation
							P - postcode (internally computed)
			  osm_id: id of corresponding OSM object
			  class: general object class (corresponds to tag key of primary OSM tag)
			  type: subclass of object (corresponds to tag value of primary OSM tag)
			  admin_level: see http://wiki.openstreetmap.org/wiki/Admin_level
			  rank_search: rank in search hierarchy
							(see also http://wiki.openstreetmap.org/wiki/Nominatim/Development_overview#Country_to_street_level)
			  rank_address: rank in address hierarchy (determines order in address)
			  place_id: internal key (may differ between different instances)
			  country_code: ISO country code
			  langaddress: localized full address
			  placename: localized name of object
			  ref: content of ref tag (if available)
			  lon: longitude
			  lat: latitude
			  importance: importance of place based on Wikipedia link count
			  addressimportance: cumulated importance of address elements
			  extra_place: type of place (for admin boundaries, if there is a place tag)
			  aBoundingBox: bounding Box
			  label: short description of the object class/type (English only) 
			  name: full name (currently the same as langaddress)
			  foundorder: secondary ordering for places with same importance
		*/
		function lookup()
		{
			if (!$this->sQuery && !$this->aStructuredQuery) return false;

			$sLanguagePrefArraySQL = "ARRAY[".join(',',array_map("getDBQuoted",$this->aLangPrefOrder))."]";
			$sQuery = $this->sQuery;

			// Conflicts between US state abreviations and various words for 'the' in different languages
			if (isset($this->aLangPrefOrder['name:en']))
			{
				$sQuery = preg_replace('/(^|,)\s*il\s*(,|$)/','\1illinois\2', $sQuery);
				$sQuery = preg_replace('/(^|,)\s*al\s*(,|$)/','\1alabama\2', $sQuery);
				$sQuery = preg_replace('/(^|,)\s*la\s*(,|$)/','\1louisiana\2', $sQuery);
			}

			// View Box SQL
			$bBoundingBoxSearch = false;
			if ($this->aViewBox)
			{
				$fHeight = $this->aViewBox[0]-$this->aViewBox[2];
				$fWidth = $this->aViewBox[1]-$this->aViewBox[3];
				$aBigViewBox[0] = $this->aViewBox[0] + $fHeight;
				$aBigViewBox[2] = $this->aViewBox[2] - $fHeight;
				$aBigViewBox[1] = $this->aViewBox[1] + $fWidth;
				$aBigViewBox[3] = $this->aViewBox[3] - $fWidth;

				$this->sViewboxSmallSQL = "ST_SetSRID(ST_MakeBox2D(ST_Point(".(float)$this->aViewBox[0].",".(float)$this->aViewBox[1]."),ST_Point(".(float)$this->aViewBox[2].",".(float)$this->aViewBox[3].")),4326)";
				$this->sViewboxLargeSQL = "ST_SetSRID(ST_MakeBox2D(ST_Point(".(float)$aBigViewBox[0].",".(float)$aBigViewBox[1]."),ST_Point(".(float)$aBigViewBox[2].",".(float)$aBigViewBox[3].")),4326)";
				$bBoundingBoxSearch = $this->bBoundedSearch;
			}

			// Route SQL
			if ($this->aRoutePoints)
			{
				$this->sViewboxCentreSQL = "ST_SetSRID('LINESTRING(";
				$bFirst = true;
				foreach($this->aRoutePoints as $aPoint)
				{
					if (!$bFirst) $this->sViewboxCentreSQL .= ",";
					$this->sViewboxCentreSQL .= $aPoint[0].' '.$aPoint[1];
					$bFirst = false;
				}
				$this->sViewboxCentreSQL .= ")'::geometry,4326)";

				$sSQL = "select st_buffer(".$this->sViewboxCentreSQL.",".(float)($_GET['routewidth']/69).")";
				$this->sViewboxSmallSQL = $this->oDB->getOne($sSQL);
				if (PEAR::isError($this->sViewboxSmallSQL))
				{
					failInternalError("Could not get small viewbox.", $sSQL, $this->sViewboxSmallSQL);
				}
				$this->sViewboxSmallSQL = "'".$this->sViewboxSmallSQL."'::geometry";

				$sSQL = "select st_buffer(".$this->sViewboxCentreSQL.",".(float)($_GET['routewidth']/30).")";
				$this->sViewboxLargeSQL = $this->oDB->getOne($sSQL);
				if (PEAR::isError($this->sViewboxLargeSQL))
				{
					failInternalError("Could not get large viewbox.", $sSQL, $this->sViewboxLargeSQL);
				}
				$this->sViewboxLargeSQL = "'".$this->sViewboxLargeSQL."'::geometry";
				$bBoundingBoxSearch = $this->bBoundedSearch;
			}

			// Do we have anything that looks like a lat/lon pair?
			if ( $aLooksLike = looksLikeLatLonPair($sQuery) ){
				$this->setNearPoint(array($aLooksLike['lat'], $aLooksLike['lon']));
				$sQuery = $aLooksLike['query'];
			}

			$aSearchResults = array();
			if ($sQuery || $this->aStructuredQuery)
			{
				// Start with a blank search
				$aSearches = array(new SearchDescription());

				// Do we have a radius search?
				if ($this->aNearPoint)
				{
					$aSearches[0]->setNearPoint($this->aNearPoint);
				}

				// currently disabled: 'special' terms in the search
				preg_match_all('/\\[(.*)=(.*)\\]/', $sQuery, $aSpecialTermsRaw, PREG_SET_ORDER);
				foreach($aSpecialTermsRaw as $aSpecialTerm)
				{
					$sQuery = str_replace($aSpecialTerm[0], ' ', $sQuery);
				}

				preg_match_all('/\\[([\\w ]*)\\]/u', $sQuery, $aSpecialTermsRaw, PREG_SET_ORDER);
				$aSpecialTerms = array();
				if (isset($this->aStructuredQuery['amenity']) && $this->aStructuredQuery['amenity'])
				{
					$aSpecialTermsRaw[] = array('['.$this->aStructuredQuery['amenity'].']', $this->aStructuredQuery['amenity']);
					unset($this->aStructuredQuery['amenity']);
				}

				// Split query into phrases
				// Commas are used to reduce the search space by indicating where phrases split
				if ($this->aStructuredQuery)
				{
					$oWords =& new Tokenizer($this->oDB, $this->aStructuredQuery, true);
				}
				else
				{
					$oWords =& new Tokenizer($this->oDB, explode(',',$sQuery), false);
				}

				$aSearches = $oWords->getSpecialSearches($aSpecialTermsRaw, $aSearches);

				if ($oWords->hasTokens())
				{
					$aGroupedSearches = $oWords->getGroupedSearches($aSearches);

					if ($this->bReverseInPlan)
					{
						$aReverseGroupedSearches = $oWords->getReverseGroupedSearches($aSearches);

						foreach($aGroupedSearches as $iGroup => $aSearches)
						{
							if (!isset($aReverseGroupedSearches[$iGroup]))
								$aReverseGroupedSearches[$iGroup] = array();
							foreach($aSearches as $aSearch)
							{
								$aReverseGroupedSearches[$iGroup][] = $aSearch;
							}
						}

						$aGroupedSearches = $aReverseGroupedSearches;
						ksort($aGroupedSearches);
					}
				}
				else
				{
					$aGroupedSearches = array();
					foreach($aSearches as $aSearch)
					{
						$iRank = $aSearch->getSearchRank();
						if (!isset($aGroupedSearches[$iRank])) $aGroupedSearches[$iRank] = array();
						$aGroupedSearches[$iRank][] = $aSearch;
					}
					ksort($aGroupedSearches);
				}

				if (CONST_Debug) var_Dump($aGroupedSearches);

				// Filter out duplicate searches
				$aSearchHash = array();
				foreach($aGroupedSearches as $iGroup => $aSearches)
				{
					foreach($aSearches as $iSearch => $aSearch)
					{
						$sHash = serialize($aSearch);
						if (isset($aSearchHash[$sHash]))
						{
							unset($aGroupedSearches[$iGroup][$iSearch]);
							if (sizeof($aGroupedSearches[$iGroup]) == 0) unset($aGroupedSearches[$iGroup]);
						}
						else
						{
							$aSearchHash[$sHash] = 1;
						}
					}
				}

				if (CONST_Debug) _debugDumpGroupedSearches($aGroupedSearches, $oWords);

				$aResultPlaceIDs = array();
				$iGroupLoop = 0;
				$iQueryLoop = 0;
				foreach($aGroupedSearches as $iGroupedRank => $aSearches)
				{
					$iGroupLoop++;
					foreach($aSearches as $aSearch)
					{
						$iQueryLoop++;

						if (CONST_Debug) { echo "<hr><b>Search Loop, group $iGroupLoop, loop $iQueryLoop</b>"; }
						if (CONST_Debug) _debugDumpGroupedSearches(array($iGroupedRank => array($aSearch)), $oWords);

						$aPlaceIDs = $this->executeSingleSearch($aSearch, $bBoundingBoxSearch, $oWords);

						if (PEAR::IsError($aPlaceIDs))
						{
							failInternalError("Could not get place IDs from tokens." ,$sSQL, $aPlaceIDs);
						}

						if (CONST_Debug) { echo "<br><b>Place IDs:</b> "; var_Dump($aPlaceIDs); }

						foreach($aPlaceIDs as $iPlaceID)
						{
							$aResultPlaceIDs[$iPlaceID] = $iPlaceID;
						}
						if ($iQueryLoop > 20) break;
					}

					if (isset($aResultPlaceIDs) && sizeof($aResultPlaceIDs) && ($this->iMinAddressRank != 0 || $this->iMaxAddressRank != 30))
					{
						// Need to verify passes rank limits before dropping out of the loop (yuk!)
						$sSQL = "select place_id from placex where place_id in (".join(',',$aResultPlaceIDs).") ";
						$sSQL .= "and (placex.rank_address between $this->iMinAddressRank and $this->iMaxAddressRank ";
						if (14 >= $this->iMinAddressRank && 14 <= $this->iMaxAddressRank) $sSQL .= " OR (extratags->'place') = 'city'";
						if ($this->aAddressRankList) $sSQL .= " OR placex.rank_address in (".join(',',$this->aAddressRankList).")";
						$sSQL .= ") UNION select place_id from location_property_tiger where place_id in (".join(',',$aResultPlaceIDs).") ";
						$sSQL .= "and (30 between $this->iMinAddressRank and $this->iMaxAddressRank ";
						if ($this->aAddressRankList) $sSQL .= " OR 30 in (".join(',',$this->aAddressRankList).")";
						$sSQL .= ")";
						if (CONST_Debug) var_dump($sSQL);
						$aResultPlaceIDs = $this->oDB->getCol($sSQL);
					}

					//exit;
					if (isset($aResultPlaceIDs) && sizeof($aResultPlaceIDs)) break;
					if ($iGroupLoop > 4) break;
					if ($iQueryLoop > 30) break;
				}

				// Did we find anything?
				if (isset($aResultPlaceIDs) && sizeof($aResultPlaceIDs))
				{
					$aSearchResults = $this->getDetails($aResultPlaceIDs);
				}

			}
			else
			{
				// Just interpret as a reverse geocode
				$iPlaceID = geocodeReverse((float)$this->aNearPoint[0], (float)$this->aNearPoint[1]);
				if ($iPlaceID)
					$aSearchResults = $this->getDetails(array($iPlaceID));
				else
					$aSearchResults = array();
			}

			// No results? Done
			if (!sizeof($aSearchResults))
			{
				if ($this->bFallback)
				{
					if ($this->fallbackStructuredQuery())
					{
						return $this->lookup();
					}
				}

				return array();
			}

			$aClassType = getClassTypesWithImportance();
			$aRecheckWords = preg_split('/\b[\s,\\-]*/u',$sQuery);
			foreach($aRecheckWords as $i => $sWord)
			{
				if (!preg_match('/\pL/', $sWord)) unset($aRecheckWords[$i]);
			}

			if (CONST_Debug) { echo '<i>Recheck words:<\i>'; var_dump($aRecheckWords); }

			foreach($aSearchResults as $iResNum => $aResult)
			{
				// Default
				$fDiameter = 0.0001;

				if (isset($aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['defdiameter'])
						&& $aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['defdiameter'])
				{
					$fDiameter = $aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['defdiameter'];
				}
				elseif (isset($aClassType[$aResult['class'].':'.$aResult['type']]['defdiameter'])
						&& $aClassType[$aResult['class'].':'.$aResult['type']]['defdiameter'])
				{
					$fDiameter = $aClassType[$aResult['class'].':'.$aResult['type']]['defdiameter'];
				}
				$fRadius = $fDiameter / 2;

				if (CONST_Search_AreaPolygons)
				{
					// Get the bounding box and outline polygon
					$sSQL = "select place_id,0 as numfeatures,st_area(geometry) as area,";
					$sSQL .= "ST_Y(centroid) as centrelat,ST_X(centroid) as centrelon,";
					$sSQL .= "ST_YMin(geometry) as minlat,ST_YMax(geometry) as maxlat,";
					$sSQL .= "ST_XMin(geometry) as minlon,ST_XMax(geometry) as maxlon";
					if ($this->bIncludePolygonAsGeoJSON) $sSQL .= ",ST_AsGeoJSON(geometry) as asgeojson";
					if ($this->bIncludePolygonAsKML) $sSQL .= ",ST_AsKML(geometry) as askml";
					if ($this->bIncludePolygonAsSVG) $sSQL .= ",ST_AsSVG(geometry) as assvg";
					if ($this->bIncludePolygonAsText || $this->bIncludePolygonAsPoints) $sSQL .= ",ST_AsText(geometry) as astext";
					$sFrom = " from placex where place_id = ".$aResult['place_id'];
					if ($this->fPolygonSimplificationThreshold > 0)
					{
						$sSQL .= " from (select place_id,centroid,ST_SimplifyPreserveTopology(geometry,".$this->fPolygonSimplificationThreshold.") as geometry".$sFrom.") as plx";
					}
					else
					{
						$sSQL .= $sFrom;
					}

					$aPointPolygon = $this->oDB->getRow($sSQL);
					if (PEAR::IsError($aPointPolygon))
					{
						failInternalError("Could not get outline.", $sSQL, $aPointPolygon);
					}

					if ($aPointPolygon['place_id'])
					{
						if ($this->bIncludePolygonAsGeoJSON) $aResult['asgeojson'] = $aPointPolygon['asgeojson'];
						if ($this->bIncludePolygonAsKML) $aResult['askml'] = $aPointPolygon['askml'];
						if ($this->bIncludePolygonAsSVG) $aResult['assvg'] = $aPointPolygon['assvg'];
						if ($this->bIncludePolygonAsText) $aResult['astext'] = $aPointPolygon['astext'];

						if ($aPointPolygon['centrelon'] !== null && $aPointPolygon['centrelat'] !== null )
						{
							$aResult['lat'] = $aPointPolygon['centrelat'];
							$aResult['lon'] = $aPointPolygon['centrelon'];
						}

						if ($this->bIncludePolygonAsPoints)
						{
							// Translate geometry string to point array
							if (preg_match('#POLYGON\\(\\(([- 0-9.,]+)#',$aPointPolygon['astext'],$aMatch))
							{
								preg_match_all('/(-?[0-9.]+) (-?[0-9.]+)/',$aMatch[1],$aPolyPoints,PREG_SET_ORDER);
							}
							elseif (preg_match('#MULTIPOLYGON\\(\\(\\(([- 0-9.,]+)#',$aPointPolygon['astext'],$aMatch))
							{
								preg_match_all('/(-?[0-9.]+) (-?[0-9.]+)/',$aMatch[1],$aPolyPoints,PREG_SET_ORDER);
							}
							elseif (preg_match('#POINT\\((-?[0-9.]+) (-?[0-9.]+)\\)#',$aPointPolygon['astext'],$aMatch))
							{
								$iSteps = max(8, min(100, ($fRadius * 40000)^2));
								$fStepSize = (2*pi())/$iSteps;
								$aPolyPoints = array();
								for($f = 0; $f < 2*pi(); $f += $fStepSize)
								{
									$aPolyPoints[] = array('',$aMatch[1]+($fRadius*sin($f)),$aMatch[2]+($fRadius*cos($f)));
								}
							}
						}

						// Output data suitable for display (points and a bounding box)
						if ($this->bIncludePolygonAsPoints && isset($aPolyPoints))
						{
							$aResult['aPolyPoints'] = array();
							foreach($aPolyPoints as $aPoint)
							{
								$aResult['aPolyPoints'][] = array($aPoint[1], $aPoint[2]);
							}
						}

						if (abs($aPointPolygon['minlat'] - $aPointPolygon['maxlat']) < 0.0000001)
						{
							$aPointPolygon['minlat'] = $aPointPolygon['minlat'] - $fRadius;
							$aPointPolygon['maxlat'] = $aPointPolygon['maxlat'] + $fRadius;
						}
						if (abs($aPointPolygon['minlon'] - $aPointPolygon['maxlon']) < 0.0000001)
						{
							$aPointPolygon['minlon'] = $aPointPolygon['minlon'] - $fRadius;
							$aPointPolygon['maxlon'] = $aPointPolygon['maxlon'] + $fRadius;
						}
						$aResult['aBoundingBox'] = array((string)$aPointPolygon['minlat'],(string)$aPointPolygon['maxlat'],(string)$aPointPolygon['minlon'],(string)$aPointPolygon['maxlon']);
					}
				}

				if ($aResult['extra_place'] == 'city')
				{
					$aResult['class'] = 'place';
					$aResult['type'] = 'city';
					$aResult['rank_search'] = 16;
				}

				if (!isset($aResult['aBoundingBox']))
				{
					$iSteps = max(8,min(100,$fRadius * 3.14 * 100000));
					$fStepSize = (2*pi())/$iSteps;
					$aPointPolygon['minlat'] = $aResult['lat'] - $fRadius;
					$aPointPolygon['maxlat'] = $aResult['lat'] + $fRadius;
					$aPointPolygon['minlon'] = $aResult['lon'] - $fRadius;
					$aPointPolygon['maxlon'] = $aResult['lon'] + $fRadius;

					// Output data suitable for display (points and a bounding box)
					if ($this->bIncludePolygonAsPoints)
					{
						$aPolyPoints = array();
						for($f = 0; $f < 2*pi(); $f += $fStepSize)
						{
							$aPolyPoints[] = array('',$aResult['lon']+($fRadius*sin($f)),$aResult['lat']+($fRadius*cos($f)));
						}
						$aResult['aPolyPoints'] = array();
						foreach($aPolyPoints as $aPoint)
						{
							$aResult['aPolyPoints'][] = array($aPoint[1], $aPoint[2]);
						}
					}
					$aResult['aBoundingBox'] = array((string)$aPointPolygon['minlat'],(string)$aPointPolygon['maxlat'],(string)$aPointPolygon['minlon'],(string)$aPointPolygon['maxlon']);
				}

				// Is there an icon set for this type of result?
				if (isset($aClassType[$aResult['class'].':'.$aResult['type']]['icon'])
						&& $aClassType[$aResult['class'].':'.$aResult['type']]['icon'])
				{
					$aResult['icon'] = CONST_Website_BaseURL.'images/mapicons/'.$aClassType[$aResult['class'].':'.$aResult['type']]['icon'].'.p.20.png';
				}

				if (isset($aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['label'])
						&& $aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['label'])
				{
					$aResult['label'] = $aClassType[$aResult['class'].':'.$aResult['type'].':'.$aResult['admin_level']]['label'];
				}
				elseif (isset($aClassType[$aResult['class'].':'.$aResult['type']]['label'])
						&& $aClassType[$aResult['class'].':'.$aResult['type']]['label'])
				{
					$aResult['label'] = $aClassType[$aResult['class'].':'.$aResult['type']]['label'];
				}

				if ($this->bIncludeAddressDetails)
				{
					$aResult['address'] = getAddressDetails($this->oDB, $sLanguagePrefArraySQL, $aResult['place_id'], $aResult['country_code']);
					if ($aResult['extra_place'] == 'city' && !isset($aResult['address']['city']))
					{
						$aResult['address'] = array_merge(array('city' => array_shift(array_values($aResult['address']))), $aResult['address']);
					}
				}

				// Adjust importance for the number of exact string matches in the result
				$aResult['importance'] = max(0.001,$aResult['importance']);
				$iCountWords = 0;
				$sAddress = $aResult['langaddress'];
				foreach($aRecheckWords as $i => $sWord)
				{
					if (stripos($sAddress, $sWord)!==false)
					{
						$iCountWords++;
						if (preg_match("/(^|,)\s*".preg_quote($sWord, '/')."\s*(,|$)/", $sAddress)) $iCountWords += 0.1;
					}
				}

				$aResult['importance'] = $aResult['importance'] + ($iCountWords*0.1); // 0.1 is a completely arbitrary number but something in the range 0.1 to 0.5 would seem right

				$aResult['name'] = $aResult['langaddress'];
				// secondary ordering (for results with same importance (the smaller the better):
				//   - approximate importance of address parts
				$aResult['foundorder'] = -$aResult['addressimportance']/10;
				//   - number of exact matches from the query
				if (isset($this->exactMatchCache[$aResult['place_id']]))
					$aResult['foundorder'] -= $this->exactMatchCache[$aResult['place_id']];
				else if (isset($this->exactMatchCache[$aResult['parent_place_id']]))
					$aResult['foundorder'] -= $this->exactMatchCache[$aResult['parent_place_id']];
				//  - importance of the class/type
				if (isset($aClassType[$aResult['class'].':'.$aResult['type']]['importance'])
					&& $aClassType[$aResult['class'].':'.$aResult['type']]['importance'])
				{
					$aResult['foundorder'] += 0.0001 * $aClassType[$aResult['class'].':'.$aResult['type']]['importance'];
				}
				else
				{
					$aResult['foundorder'] += 0.01;
				}
				$aSearchResults[$iResNum] = $aResult;
			}
			uasort($aSearchResults, 'byImportance');

			$aOSMIDDone = array();
			$aClassTypeNameDone = array();
			$aToFilter = $aSearchResults;
			$aSearchResults = array();

			$bFirst = true;
			foreach($aToFilter as $iResNum => $aResult)
			{
				$this->aExcludePlaceIDs[$aResult['place_id']] = $aResult['place_id'];
				if ($bFirst)
				{
					$fLat = $aResult['lat'];
					$fLon = $aResult['lon'];
					if (isset($aResult['zoom'])) $iZoom = $aResult['zoom'];
					$bFirst = false;
				}
				if (!$this->bDeDupe || (!isset($aOSMIDDone[$aResult['osm_type'].$aResult['osm_id']])
							&& !isset($aClassTypeNameDone[$aResult['osm_type'].$aResult['class'].$aResult['type'].$aResult['name'].$aResult['admin_level']])))
				{
					$aOSMIDDone[$aResult['osm_type'].$aResult['osm_id']] = true;
					$aClassTypeNameDone[$aResult['osm_type'].$aResult['class'].$aResult['type'].$aResult['name'].$aResult['admin_level']] = true;
					$aSearchResults[] = $aResult;
				}

				// Absolute limit on number of results
				if (sizeof($aSearchResults) >= $this->iFinalLimit) break;
			}

			return $aSearchResults;

		} // end lookup()


	} // end class

