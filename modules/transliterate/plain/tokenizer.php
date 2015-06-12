<?php
	class Tokenizer
	{
		protected $oDB;

		protected $aPhrases;
		protected $aPhraseTypes;
		protected $bIsStructured;
		protected $aTokens;
		protected $aWordFrequencyScores;

		protected $iMaxRank = 20;

        function getTokens()
        {
            return $this->aTokens;
        }

		static function prepareSetup(&$oDB, $bIgnoreErrors)
		{
			$sTemplate = file_get_contents(CONST_SitePath.'/modules/transliterate/tables.sql');
			$sTemplate = str_replace('{www-user}', CONST_Database_Web_User, $sTemplate);
			$sTemplate = replace_tablespace('{ts:data}',
					CONST_Tablespace_Search_Data, $sTemplate);
			$sTemplate = replace_tablespace('{ts:index}',
					CONST_Tablespace_Search_Index, $sTemplate);
			pgsqlRunScript($sTemplate, !$bIgnoreErrors);
		}

		static function updateFunctions(&$oDB, $bIgnoreErrors, $bEnableDebug)
		{
			$sTemplate = file_get_contents(CONST_SitePath.'/modules/transliterate/functions.sql');
			$sTemplate = str_replace('{modulepath}', CONST_SitePath.'/modules/transliterate', $sTemplate);
			if ($bEnableDebug) $sTemplate = str_replace('--DEBUG:', '', $sTemplate);
			pgsqlRunScript($sTemplate, $bIgnoreErrors);
		}

		static function finishSetup(&$oDB, $bIgnoreErrors)
		{
			// create indices
			$sTemplate = file_get_contents(CONST_SitePath.'/modules/transliterate/indices.sql');
			$sTemplate = replace_tablespace('{ts:index}',
			                     CONST_Tablespace_Search_Index, $sTemplate);
			pgsqlRunScript($sTemplate, !$bIgnoreErrors);

			// make sure the basic country names are available
			pgRun($oDB, "select create_country_term('uk', 'gb')");
			pgRun($oDB, "select create_country_term('united states', 'us')");
			pgRun($oDB, "select count(*) from (select create_country_term(country_code, country_code) from country_name where country_code is not null) as x");

			pgRun($oDB, "select count(*) from (select create_country_term(get_name_by_language(country_name.name,ARRAY['name']), country_code) from country_name where get_name_by_language(country_name.name, ARRAY['name']) is not null) as x");
			foreach(explode('|', CONST_Tokenizer_Languages) as $sLanguage)
			{
				pgRun($oDB, "select count(*) from (select create_country_term(get_name_by_language(country_name.name,ARRAY['name:".$sLanguage."']), country_code) from country_name where get_name_by_language(country_name.name, ARRAY['name:".$sLanguage."']) is not null) as x");
			}
		}

		static function createAmenitySql($sLabel, $sClass, $sType, $sOp)
		{
			switch($sOp)
			{
				case 'near':
					return "select getorcreate_amenityoperator('".pg_escape_string($sLabel)."', '$sClass', '$sType', 'near');\n";
				case 'in':
					return "select getorcreate_amenityoperator('".pg_escape_string($sLabel)."', '$sClass', '$sType', 'in');\n";
				default:
					return "select getorcreate_amenity('".pg_escape_string($sLabel)."', '$sClass', '$sType');\n";
			}
		}

		function Tokenizer(&$oDB, $aPhrases, $bIsStructured)
		{
			$this->bIsStructured = $bIsStructured;
			$this->oDB =& $oDB;

			$this->loadTokenInfo($this->createPhrases($aPhrases));
		}

		function hasTokens()
		{
			return sizeof($this->aTokens) > 0;
		}

		function getSpecialSearches($aSpecialTerms, $aSearches)
		{
			foreach($aSpecialTerms as $aSpecialTerm)
			{
				$sQuery = str_replace($aSpecialTerm[0], ' ', $sQuery);
				$sToken = $this->oDB->getOne("select make_standard_name('".$aSpecialTerm[1]."') as string");
				$sSQL = 'select * from (select word_id,word_token, word, class, type, country_code, operator';
				$sSQL .= ' from word where word_token in (\' '.$sToken.'\')) as x where (class is not null and class not in (\'place\')) or country_code is not null';
				if (CONST_Debug) var_Dump($sSQL);
				$aSearchWords = $this->oDB->getAll($sSQL);
				$aNewSearches = array();
				foreach($aSearches as $aSearch)
				{
					foreach($aSearchWords as $aSearchTerm)
					{
						$aNewSearch = $aSearch;
						if ($aSearchTerm['country_code'])
						{
							$aNewSearch['sCountryCode'] = strtolower($aSearchTerm['country_code']);
							$aNewSearches[] = $aNewSearch;
						}
						if ($aSearchTerm['class'])
						{
							$aNewSearch['sClass'] = $aSearchTerm['class'];
							$aNewSearch['sType'] = $aSearchTerm['type'];
							$aNewSearches[] = $aNewSearch;
						}
					}
				}
				$aSearches = $aNewSearches;
			}

			return $aSearches;
		}

		function getGroupedSearches($aSearches)
		{
			return $this->computeSearches($aSearches, $this->aPhrases, $this->bIsStructured);
		}

		function getReverseGroupedSearches($aSearches)
		{
			// Reverse phrase array and also reverse the order of the wordsets in
			// the first and final phrase. Don't bother about phrases in the middle
			// because order in the address doesn't matter.
			$aPhrases = array_reverse($this->aPhrases);
			$aPhrases[0]['wordsets'] = getInverseWordSets($aPhrases[0]['words'], 0);
			if (sizeof($aPhrases) > 1)
			{
				$aFinalPhrase = end($aPhrases);
				$aPhrases[sizeof($aPhrases)-1]['wordsets'] = getInverseWordSets($aFinalPhrase['words'], 0);
			}

			return $this->computeSearches($aSearches, $sPhrases, false);
		}

		private function getWordSets($aWords, $iDepth)
		{
			$aResult = array(array(join(' ',$aWords)));
			$sFirstToken = '';
			if ($iDepth < 8) {
				while(sizeof($aWords) > 1)
				{
					$sWord = array_shift($aWords);
					$sFirstToken .= ($sFirstToken?' ':'').$sWord;
					$aRest = $this->getWordSets($aWords, $iDepth+1);
					foreach($aRest as $aSet)
					{
						$aResult[] = array_merge(array($sFirstToken),$aSet);
					}
				}
			}
			return $aResult;
		}

		private function getTokensFromSets($aSets)
		{
			$aTokens = array();
			foreach($aSets as $aSet)
			{
				foreach($aSet as $sWord)
				{
					$aTokens[' '.$sWord] = ' '.$sWord;
					$aTokens[$sWord] = $sWord;
				}
			}
			return $aTokens;
		}

		// Split phrases and compute the list of tokens.
		private function createPhrases($aPhrases)
		{
			$aTokens = array();
			foreach($aPhrases as $iPhrase => $sPhrase)
			{
				$aPhrase  = $this->oDB->getRow("select make_standard_name('".pg_escape_string($sPhrase)."') as string");
				if (PEAR::isError($aPhrase))
				{
					userError("Illegal query string (not an UTF-8 string): ".$sPhrase);
					if (CONST_Debug) var_dump($aPhrase);
					exit;
				}
				if (trim($aPhrase['string']))
				{
					$aPhrases[$iPhrase] = $aPhrase;
					$aPhrases[$iPhrase]['words'] = explode(' ',$aPhrases[$iPhrase]['string']);
					$aPhrases[$iPhrase]['wordsets'] = $this->getWordSets($aPhrases[$iPhrase]['words'], 0);
					$aTokens = array_merge($aTokens,
					             $this->getTokensFromSets($aPhrases[$iPhrase]['wordsets']));
				}
				else
				{
					unset($aPhrases[$iPhrase]);
				}
			}

			// Reindex phrases - we make assumptions later on that they are numerically keyed in order
			$this->aPhraseTypes = array_keys($aPhrases);
			$this->aPhrases = array_values($aPhrases);

			return $aTokens;
		}

		private function loadTokenInfo($aRawTokens)
		{
			$this->aTokens = array();
			$this->aWordFrequencyScores = array();

			if (sizeof($aRawTokens) <= 0) return 0;

			// Check which tokens we have, get the ID numbers
			$sSQL = 'select word_id,word_token,word,class,type,country_code,';
			$sSQL .= 'operator,search_name_count from word where word_token in (';
			$sSQL .= join(',',array_map("getDBQuoted",$aRawTokens)).')';

			if (CONST_Debug) var_Dump($sSQL);

			$aDatabaseWords = $this->oDB->getAll($sSQL);
			if (PEAR::IsError($aDatabaseWords))
			{
				failInternalError("Could not get word tokens.", $sSQL, $aDatabaseWords);
			}
			foreach($aDatabaseWords as $aToken)
			{
				// Very special case - require 2 letter country param to match the country code found
				if ($this->bIsStructured && $aToken['country_code'] && !empty($this->aStructuredQuery['country'])
						&& strlen($this->aStructuredQuery['country']) == 2 && strtolower($this->aStructuredQuery['country']) != $aToken['country_code'])
				{
					continue;
				}

				if (isset($this->aTokens[$aToken['word_token']]))
				{
					$this->aTokens[$aToken['word_token']][] = $aToken;
				}
				else
				{
					$this->aTokens[$aToken['word_token']] = array($aToken);
				}
				$this->aWordFrequencyScores[$aToken['word_id']] = $aToken['search_name_count'] + 1;
			}
			if (CONST_Debug) var_Dump($this->aPhrases, $this->aTokens);

			// Try and calculate GB postcodes we might be missing
			foreach($aRawTokens as $sToken)
			{
				// Source of gb postcodes is now definitive - always use
				if (preg_match('/^([A-Z][A-Z]?[0-9][0-9A-Z]? ?[0-9])([A-Z][A-Z])$/', strtoupper(trim($sToken)), $aData))
				{
					if (substr($aData[1],-2,1) != ' ')
					{
						$aData[0] = substr($aData[0],0,strlen($aData[1])-1).' '.substr($aData[0],strlen($aData[1])-1);
						$aData[1] = substr($aData[1],0,-1).' '.substr($aData[1],-1,1);
					}
					$aGBPostcodeLocation = gbPostcodeCalculate($aData[0], $aData[1], $aData[2], $oDB);
					if ($aGBPostcodeLocation)
					{
						$this->aTokens[$sToken] = $aGBPostcodeLocation;
					}
				}
				// US ZIP+4 codes - if there is no token,
				// 	merge in the 5-digit ZIP code
				else if (!isset($this->aTokens[$sToken]) && preg_match('/^([0-9]{5}) [0-9]{4}$/', $sToken, $aData))
				{
					if (isset($this->aTokens[$aData[1]]))
					{
						foreach($this->aTokens[$aData[1]] as $aToken)
						{
							if (!$aToken['class'])
							{
								if (isset($this->aTokens[$sToken]))
								{
									$this->aTokens[$sToken][] = $aToken;
								}
								else
								{
									$this->aTokens[$sToken] = array($aToken);
								}
							}
						}
					}
				}
			}

			foreach($aRawTokens as $sToken)
			{
				// Unknown single word token with a number - assume it is a house number
				if (!isset($this->aTokens[' '.$sToken]) && strpos($sToken,' ') === false && preg_match('/[0-9]/', $sToken))
				{
					$this->aTokens[' '.$sToken] = array(array('class'=>'place','type'=>'house'));
				}
			}

			// Any words that have failed completely?
			// TODO: suggestions
		}
		

		protected function computeSearches($aSearches, $aPhrases, $bStructuredPhrases)
		{
			/*
			   Calculate all searches using aTokens i.e.
			   'Wodsworth Road, Sheffield' =>

			   Phrase Wordset
			   0      0       (wodsworth road)
			   0      1       (wodsworth)(road)
			   1      0       (sheffield)

			   Score how good the search is so they can be ordered
			 */
			foreach($this->aPhrases as $iPhrase => $aPhrase)
			{
				$aNewPhraseSearches = array();
				if ($bStructuredPhrases) $sPhraseType = $this->aPhraseTypes[$iPhrase];
				else $sPhraseType = '';

				foreach($aPhrase['wordsets'] as $iWordSet => $aWordset)
				{
					// Too many permutations - too expensive
					if ($iWordSet > 120) break;

					$aWordsetSearches = $aSearches;

					// Add all words from this wordset
					foreach($aWordset as $iToken => $sToken)
					{
						$aNewWordsetSearches = array();

						foreach($aWordsetSearches as $aCurrentSearch)
						{
							// If the token is valid
							if (isset($this->aTokens[' '.$sToken]))
							{
								foreach($this->aTokens[' '.$sToken] as $aSearchTerm)
								{
									$aSearch = $aCurrentSearch;
									$aSearch['iSearchRank']++;
									if (($sPhraseType == '' || $sPhraseType == 'country') && !empty($aSearchTerm['country_code']) && $aSearchTerm['country_code'] != '0')
									{
										if ($aSearch['sCountryCode'] === false)
										{
											$aSearch['sCountryCode'] = strtolower($aSearchTerm['country_code']);
											// Country is almost always at the end of the string - increase score for finding it anywhere else (optimisation)
											if (($iToken+1 != sizeof($aWordset) || $iPhrase+1 != sizeof($this->aPhrases)))
											{
												$aSearch['iSearchRank'] += 5;
											}
											if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
										}
									}
									elseif (isset($aSearchTerm['lat']) && $aSearchTerm['lat'] !== '' && $aSearchTerm['lat'] !== null)
									{
										if ($aSearch['fLat'] === '')
										{
											$aSearch['fLat'] = $aSearchTerm['lat'];
											$aSearch['fLon'] = $aSearchTerm['lon'];
											$aSearch['fRadius'] = $aSearchTerm['radius'];
											if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
										}
									}
									elseif ($sPhraseType == 'postalcode')
									{
										// We need to try the case where the postal code is the primary element (i.e. no way to tell if it is (postalcode, city) OR (city, postalcode) so try both
										if (isset($aSearchTerm['word_id']) && $aSearchTerm['word_id'])
										{
											// If we already have a name try putting the postcode first
											if (sizeof($aSearch['aName']))
											{
												$aNewSearch = $aSearch;
												$aNewSearch['aAddress'] = array_merge($aNewSearch['aAddress'], $aNewSearch['aName']);
												$aNewSearch['aName'] = array();
												$aNewSearch['aName'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
												if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aNewSearch;
											}

											if (sizeof($aSearch['aName']))
											{
												if ((!$bStructuredPhrases || $iPhrase > 0) && $sPhraseType != 'country' && (!isset($this->aTokens[$sToken]) || strpos($sToken, ' ') !== false))
												{
													$aSearch['aAddress'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
												}
												else
												{
													$aCurrentSearch['aFullNameAddress'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
													$aSearch['iSearchRank'] += 1000; // skip;
												}
											}
											else
											{
												$aSearch['aName'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
												//$aSearch['iNamePhrase'] = $iPhrase;
											}
											if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
										}

									}
									elseif (($sPhraseType == '' || $sPhraseType == 'street') && $aSearchTerm['class'] == 'place' && $aSearchTerm['type'] == 'house')
									{
										if ($aSearch['sHouseNumber'] === '')
										{
											$aSearch['sHouseNumber'] = $sToken;
											// sanity check: if the housenumber is not mainly made
											// up of numbers, add a penalty
											if (preg_match_all("/[^0-9]/", $sToken, $aMatches) > 2) $aSearch['iSearchRank']++;
											// also housenumbers should appear in the first or second phrase
											if ($iPhrase > 1) $aSearch['iSearchRank'] += 1;
											if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
										}
									}
									elseif ($sPhraseType == '' && $aSearchTerm['class'] !== '' && $aSearchTerm['class'] !== null)
									{
										if ($aSearch['sClass'] === '')
										{
											$aSearch['sOperator'] = $aSearchTerm['operator'];
											$aSearch['sClass'] = $aSearchTerm['class'];
											$aSearch['sType'] = $aSearchTerm['type'];
											if (sizeof($aSearch['aName'])) $aSearch['sOperator'] = 'name';
											else $aSearch['sOperator'] = 'near'; // near = in for the moment
											if (strlen($aSearchTerm['operator']) == 0) $aSearch['iSearchRank'] += 1;

											if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
										}
									}
									elseif (isset($aSearchTerm['word_id']) && $aSearchTerm['word_id'])
									{
										if (sizeof($aSearch['aName']))
										{
											if ((!$bStructuredPhrases || $iPhrase > 0) && $sPhraseType != 'country' && (!isset($this->aTokens[$sToken]) || strpos($sToken, ' ') !== false))
											{
												$aSearch['aAddress'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
											}
											else
											{
												$aCurrentSearch['aFullNameAddress'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
												$aSearch['iSearchRank'] += 1000; // skip;
											}
										}
										else
										{
											$aSearch['aName'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
										}
										if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
									}
								}
							}
							// Look for partial matches.
							// Note that there is no point in adding country terms here
							// because country are omitted in the address.
							if (isset($this->aTokens[$sToken]) && $sPhraseType != 'country')
							{
								// Allow searching for a word - but at extra cost
								foreach($this->aTokens[$sToken] as $aSearchTerm)
								{
									if (isset($aSearchTerm['word_id']) && $aSearchTerm['word_id'])
									{
										if ((!$bStructuredPhrases || $iPhrase > 0) && sizeof($aCurrentSearch['aName']) && strpos($sToken, ' ') === false)
										{
											$aSearch = $aCurrentSearch;
											$aSearch['iSearchRank'] += 1;
											if ($this->aWordFrequencyScores[$aSearchTerm['word_id']] < CONST_Max_Word_Frequency)
											{
												$aSearch['aAddress'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
												if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
											}
											elseif (isset($this->aTokens[' '.$sToken])) // revert to the token version?
											{
												$aSearch['aAddressNonSearch'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
												$aSearch['iSearchRank'] += 1;
												if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
												foreach($this->aTokens[' '.$sToken] as $aSearchTermToken)
												{
													if (empty($aSearchTermToken['country_code'])
															&& empty($aSearchTermToken['lat'])
															&& empty($aSearchTermToken['class']))
													{
														$aSearch = $aCurrentSearch;
														$aSearch['iSearchRank'] += 1;
														$aSearch['aAddress'][$aSearchTermToken['word_id']] = $aSearchTermToken['word_id'];
														if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
													}
												}
											}
											else
											{
												$aSearch['aAddressNonSearch'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
												if (preg_match('#^[0-9]+$#', $sToken)) $aSearch['iSearchRank'] += 2;
												if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
											}
										}

										if (!sizeof($aCurrentSearch['aName']) || $aCurrentSearch['iNamePhrase'] == $iPhrase)
										{
											$aSearch = $aCurrentSearch;
											$aSearch['iSearchRank'] += 1;
											if (!sizeof($aCurrentSearch['aName'])) $aSearch['iSearchRank'] += 1;
											if (preg_match('#^[0-9]+$#', $sToken)) $aSearch['iSearchRank'] += 2;
											if ($this->aWordFrequencyScores[$aSearchTerm['word_id']] < CONST_Max_Word_Frequency)
												$aSearch['aName'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
											else
												$aSearch['aNameNonSearch'][$aSearchTerm['word_id']] = $aSearchTerm['word_id'];
											$aSearch['iNamePhrase'] = $iPhrase;
											if ($aSearch['iSearchRank'] < $this->iMaxRank) $aNewWordsetSearches[] = $aSearch;
										}
									}
								}
							}
						}
						// Sort and cut
						usort($aNewWordsetSearches, 'bySearchRank');
						$aWordsetSearches = array_slice($aNewWordsetSearches, 0, 50);
					}

					$aNewPhraseSearches = array_merge($aNewPhraseSearches, $aNewWordsetSearches);
					usort($aNewPhraseSearches, 'bySearchRank');

					$aSearchHash = array();
					foreach($aNewPhraseSearches as $iSearch => $aSearch)
					{
						$sHash = serialize($aSearch);
						if (isset($aSearchHash[$sHash])) unset($aNewPhraseSearches[$iSearch]);
						else $aSearchHash[$sHash] = 1;
					}

					$aNewPhraseSearches = array_slice($aNewPhraseSearches, 0, 50);
				}

				// Re-group the searches by their score, junk anything over 20 as just not worth trying
				$aGroupedSearches = array();
				foreach($aNewPhraseSearches as $aSearch)
				{
					if ($aSearch['iSearchRank'] < $this->iMaxRank)
					{
						if (!isset($aGroupedSearches[$aSearch['iSearchRank']])) $aGroupedSearches[$aSearch['iSearchRank']] = array();
						$aGroupedSearches[$aSearch['iSearchRank']][] = $aSearch;
					}
				}
				ksort($aGroupedSearches);

				$iSearchCount = 0;
				$aSearches = array();
				foreach($aGroupedSearches as $iScore => $aNewSearches)
				{
					$iSearchCount += sizeof($aNewSearches);
					$aSearches = array_merge($aSearches, $aNewSearches);
					if ($iSearchCount > 50) break;
				}

				//if (CONST_Debug) _debugDumpGroupedSearches($aGroupedSearches, $this->aTokens);

			}
			return $aGroupedSearches;

		}

	} // end class Tokenizer
