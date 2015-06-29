<?php
	// Properties of a search token.
	class TokenType
	{
		const Name = 0;         // part of name, searchable
		const NonName = 1;     // part of name, non-searchable
		const Address = 2;      // part of address, searchable
		const NonAddress = 3;  // part of address, non-searchable
		const Full = 4;         // full word terms in query
	}

	// A single interpretation of a search query.
	//
	// A tokenizer must create and fill this structure.
	class SearchDescription
	{
		// Search rank that determines the order in which searches will be handled.
		private $iSearchRank = 0;
		// Id of the last name phrase (aka number of phrase parts in name)
		private $iNamePhrase = -1; //???
		// Country the search should be restricted to
		private $sCountryCode = false;
		// Search terms as word tokens ordered by their type
		private $aTokens = array(
		                    TokenType::Name => array(),
		                    TokenType::NonName => array(),
		                    TokenType::Address => array(),
		                    TokenType::NonAddress => array(),
		                    TokenType::Full => array(),
		                   );
		// Special term search
		private $sOperator = false;
		private $sClass = false;
		private $sType = false;
		// House number search
		private $sHouseNumber = false;
		// Restrict search to a geographic point
		private $sNearPoint = false;
		private $fRadius = false;

		function setNearPoint($aNearPoint)
		{
			$this->sNearPoint  = 'ST_SetSRID(ST_Point(';
			$this->sNearPoint .= (float)$this->aNearPoint[1].',';
			$this->sNearPoint .= (float)$this->aNearPoint[0].'),4326)';
			$this->$fRadius = (float) $aNearPoint[2];
		}

		function setCountryCode($sCountryCode)
		{
			$this->sCountryCode = strtolower($sCountryCode);
		}

		function setNamePhrase($iPhrase)
		{
			$this->iNamePhrase = $iPhrase;
		}

		function setHouseNumber($sNumber)
		{
			$this->sHouseNumber = $sNumber;
		}

		function incSearchRank($iAdd = 1)
		{
			$this->iSearchRank += $iAdd;
		}

		function isPlausible()
		{
			return $this->iSearchRank < CONST_MaxSearchDepth;
		}

		function setClassType($sClass, $sType)
		{
			$this->sClass = $sClass;
			$this->sType = $sType;
		}

		function setOperator($sOperator)
		{
			$this->sOperator = $sOperator;
		}

		function clearTokens($eType)
		{
			$this->aTokens[$eType] = array();
		}

		function addToken($eType, $iWord)
		{
			$this->aTokens[$eType][$iWord] = $iWord;
		}

		function addTokens($eType, $aTerms)
		{
			$this->aTokens[$eType] = array_merge($this->aTokens[$eType], $aTerms);
		}

		function getSearchRank()
		{
			return $this->iSearchRank;
		}

		function hasLocationTerm()
		{
			return sizeof($this->aTokens[TokenType::Name])
			       || sizeof($this->aTokens[TokenType::Address])
			       || $this->sNearPoint;
		}

		function isCountrySearch()
		{
			return $this->sCountryCode && !$this->sClass && !$this->sHouseNumber;
		}

		function isHouseNumberSearch()
		{
			return $this->sHouseNumber && sizeof($this->aAddress);
		}

		function hasOperator($sType)
		{
			return !$this->sOperator || $this->sOperator == $sType;
		}

		function hasNearPoint()
		{
			return (bool) $this->sNearPoint;
		}

		function hasRadius()
		{
			return $this->fRadius;
		}

		function hasClass()
		{
			return (bool) $this->sClass;
		}

		function hasTokens($eType)
		{
			return (bool) sizeof($this->aTokens[$eType]);
		}

		function hasCountryCode()
		{
			return (bool) $this->sCountryCode;
		}

		function hasHouseNumber()
		{
			return (bool) $this->sHouseNumber;
		}

		function getClassType()
		{
			return $this->sClass.'_'.$this->sType;
		}

		function getClass()
		{
			return $this->sClass;
		}

		function getType()
		{
			return $this->sType;
		}

		function getHouseNumber()
		{
			return $this->sHouseNumber;
		}

		function getOperator()
		{
			return $this->sOperator;
		}

		function getTokens($eType)
		{
			return $this->aTokens[$eType];
		}

		function getTokenList($eType)
		{
			return join($this->aTokens[$eType], ',');
		}

		function getFirstName()
		{
			return reset($this->aTokens[TokenType::Name]);
		}

		function getCountryCode()
		{
			return $this->sCountryCode;
		}

		function getRadius()
		{
			return $this->fRadius;
		}

		function getNamePhrase()
		{
			return $this->iNamePhrase;
		}

		function sqlNearPoint()
		{
			return $this->sNearPoint;
		}

		function getDebugTokenList($eType, $aWordIDs)
		{
			$sOut = '';
			$sSep = '';
			foreach($this->aTokens[$eType] as $iWordID)
			{
				$sOut .= $sSep.'#'.$aWordIDs[$iWordID].'#';
				$sSep = ', ';
			}
			return $sOut;
		}

		static function sortBySearchRank($a, $b)
		{
			if ($a->iSearchRank == $b->iSearchRank)
				return strlen($a->sOperator) + strlen($a->sHouseNumber)
				       - strlen($b->sOperator) - strlen($b->sHouseNumber);
		return ($a->iSearchRank < $b->iSearchRank)?-1:1;
		}
	}
