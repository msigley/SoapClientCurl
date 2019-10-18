<?php
/**
 * A wrapper around \SoapClient that uses cURL to make the requests
 * Version: 1.2.0
 */
class SoapClientCurl extends \SoapClient
{
	protected $ch; 
	protected $curl_options;
	protected $headers = [];
	
	function __construct($wsdl, array $options, array $curl_options = [], $headers = [])
	{
		//Setup default curlOptions
		$socket_timeout = ini_get('default_socket_timeout');
		if( empty( $socket_timeout ) )
			$socket_timeout = 60;
		$connection_timeout = $socket_timeout;
		if( !empty( $options['connection_timeout'] ) )
			$connection_timeout = $options['connection_timeout'];

		$this->curl_options = $curl_options + array(
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_TIMEOUT => (int) $socket_timeout,
			CURLOPT_CONNECTTIMEOUT => (int) $connection_timeout,
			CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
		);

		$this->headers = $headers;

		//Initialize new CURL instance
		$this->ch = curl_init();
		
		$wsdl_cache_file = ini_get('soap.wsdl_cache_dir').'/wsdl-'.get_current_user().'-'.md5($wsdl);

		$fp = fopen($wsdl_cache_file, 'w+');
		$file_stats = fstat($fp);
		if( 0 == $file_stats['size'] || $file_stats['ctime'] + ini_get('soap.wsdl_cache_ttl') < time() ) {			
			curl_setopt_array($this->ch, $this->curl_options);
			
			curl_setopt($this->ch, CURLOPT_URL, $wsdl);
			curl_setopt($this->ch, CURLOPT_FILE, $fp);

			curl_exec($this->ch);

			if( function_exists( 'curl_reset' ) )
				curl_reset($this->ch);
			else
				$this->ch = curl_init();
		}
		fclose($fp);
		
		$options['cache_wsdl'] = WSDL_CACHE_MEMORY; //Disk caching is reimplemented with CURL above

		parent::__construct($wsdl_cache_file, $options);
	}

	function __destruct() {
		//Destory CURL instance
		curl_close($this->ch);

		if( method_exists('SoapClient','__destruct') )
			call_user_func('parent::__destruct');
	}


	/**
	 * We override this function from parent to use cURL.
	 *
	 * @param string $request
	 * @param string $location
	 * @param string $action
	 * @param int $version
	 * @param int $one_way
	 * @return string
	 */
	public function __doRequest($request, $location, $action, $version, $one_way = 0) {

		$this->__last_request = $request;

		if(empty($this->headers)) {
			$headers = array( 'Connection: Close' );
		}
		else {
			$headers = $this->headers;
		}

		$soapHeaders = array(
			sprintf('Content-Length: %d', strlen($request))
		);

		switch( $version ) {
			case SOAP_1_1:
				$soapHeaders[] = 'Content-Type: text/xml; charset="utf-8"';
				$soapHeaders[] = sprintf('SOAPAction: "%s"', $action);
				break;
			case SOAP_1_2:
				$soapHeaders[] = sprintf('Content-Type: application/soap+xml; charset="utf-8"; action="%s"', $action);
				break;
		}

		$headers = array_merge($headers, $soapHeaders);

		curl_setopt_array($this->ch, $this->curl_options);

		curl_setopt($this->ch, CURLOPT_URL, $location);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $request);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);

		$output = '';
		
		$response = curl_exec($this->ch);
		if( false === $response )
			error_log( curl_error($this->ch) );
        
		if( !$one_way ) 
			$output = $response;

		if( function_exists( 'curl_reset' ) )
			curl_reset($this->ch);
		else
			$this->ch = curl_init();

		return $output;
	}
}
