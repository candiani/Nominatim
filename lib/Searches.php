<?php
	// Describes a possible search interpretation, which is then
	// used to construct a database query.
	class SearchDescription
	{
		private $iSearchRank = 0;
		private $iNamePhrase = -1; //???
		private $sCountryCode = false;
		private $aName = array();
		private $aAddress = array();
		private $aFullNameAddress = array();
		private $aNameNonSearch = array();
		private $aAddressNonSearch = array();
		private $sOperator = false;
		private $aFeatureName = array();
		private $sClass = false;
		private $sType = false;
		private $sHouseNumber = false;
		private $fLat = false;
		private $fLon = false;
		private $fRadius = false;

		function setNearPoint($aNearPoint)
		{
			$this->$fLat = (float) $aNearPoint[0];
			$this->$fLon = (float) $aNearPoint[1];
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

		function clearNames()
		{
			$this->aName = array();
		}

		function addName($iWord)
		{
			$this->aName[$iWord] = $iWord;
		}

		function addNonSearchName($iWord)
		{
			$this->aNameNonSearch[$iWord] = $iWord;
		}

		function addAddress($iWord)
		{
			$this->aAddress[$iWord] = $iWord;
		}

		function addAddresses($aTerms)
		{
			$this->aAddress = array_merge($this->aAddress, $aTerms);
		}

		function addNonSearchAddress($iWord)
		{
			$this->aAddressNonSearch($iWord);
		}

		function addFullAddress($iWord)
		{
			$this->aFullNameAddress[$iWord] = $iWord;
		}

		function getSearchRank()
		{
			return $this->iSearchRank;
		}

		function hasLocationTerm()
		{
			return sizeof($this->aName) || sizeof($this->aAddress) || $this->fLon;
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
			return (bool) $this->fLon && $this->fLat;
		}

		function hasRadius()
		{
			return $this->fRadius;
		}

		function hasClass()
		{
			return (bool) $this->sClass;
		}

		function numNames()
		{
			return sizeof($this->aName);
		}

		function hasNonNames()
		{
			return (bool) sizeof($this->aNameNonSearch);
		}

		function hasAddress()
		{
			return (bool) sizeof($this->aAddress) && $this->aName != $this->aAddress;
		}

		function hasNonAddress()
		{
			return (bool) sizeof($this->aAddressNonSearch);
		}

		function hasFullNameAddress()
		{
			return (bool) sizeof($this->aFullNameAddress);
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

		function getNames()
		{
			return $this->aName;
		}

		function getNameList()
		{
			return join($this->aName, ',');
		}

		function getFirstName()
		{
			return $this->aName[reset($this->aName)];
		}

		function getNonNameList()
		{
			return join($this->aNameNonSearch, ',');
		}

		function getFullNameList()
		{
			return join($this->aFullNameAddress, ',');
		}

		function getAddressList()
		{
			return join($this->aAddress, ',');
		}

		function getNonAddressList()
		{
			return join($this->aAddressNonSearch, ',');
		}

		function getFullAddressList()
		{
			return join(array_merge($this->aAddress, $this->aAddressNonSearch), ',');
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

		static function sortBySearchRank($a, $b)
		{
			if ($a->iSearchRank == $b->iSearchRank)
				return strlen($a->sOperator) + strlen($a->sHouseNumber)
				       - strlen($b->sOperator) - strlen($b->sHouseNumber);
		return ($a->iSearchRank < $b->iSearchRank)?-1:1;
		}
	}
