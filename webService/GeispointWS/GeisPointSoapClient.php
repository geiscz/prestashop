<?php
/**
 * @service GeisPointSoapClient
 */
class GeisPointSoapClient{
	/**
	 * The WSDL URI
	 *
	 * @var string
	 */
	public static $_WsdlUri='http://plugin.geispoint.cz/index.php?WSDL';
	/**
	 * The PHP SoapClient object
	 *
	 * @var object
	 */
	public static $_Server=null;

	/**
	 * Send a SOAP request to the server
	 *
	 * @param string $method The method name
	 * @param array $param The parameters
	 * @return mixed The server response
	 */
	public static function _Call($method,$param){
		if(is_null(self::$_Server))
			self::$_Server=new SoapClient(self::$_WsdlUri);
		return self::$_Server->__soapCall($method,$param);
	}

	/**
	 * @param string $countryCode
	 * @return string
	 */
	public function getRegions($countryCode){
		return self::_Call('getRegions',Array(
			$countryCode
		));
	}

	/**
	 * @param string $countryCode
	 * @param int $idRegion
	 * @return string
	 */
	public function getCities($countryCode,$idRegion){
		return self::_Call('getCities',Array(
			$countryCode,
			$idRegion
		));
	}

	/**
	 * @param string $idGP
	 * @return string
	 */
	public function getGPDetail($idGP){
		return self::_Call('getGPDetail',Array(
			$idGP
		));
	}

	/**
	 * @param string $countryCode
	 * @param string $city
	 * @param string $postcode
	 * @param string $idGP
	 * @return string
	 */
	public function searchGP($countryCode,$postcode,$city,$idGP){
		return self::_Call('searchGP',Array(
			$countryCode,
			$postcode,
			$city,
			$idGP
		));
	}
}