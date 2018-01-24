<?php
/**
 * A wrapper around \SoapClient that uses cURL to make the requests
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
			CURL_TIMEOUT => $socket_timeout,
			CURLOPT_CONNECTTIMEOUT => $connection_timeout
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
			curl_reset($this->ch);
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
			$headers = array( 'Connection: Close', 'Content-Type: application/soap+xml' );
		}
		else {
			$headers = $this->headers;
		}

		$soapHeaders = array(
			sprintf('SOAPAction: "%s"', $action),
			sprintf('Content-Length: %d', strlen($request))
		);

		$headers = array_merge($headers, $soapHeaders);

		curl_setopt_array($this->ch, $this->curl_options);

		curl_setopt($this->ch, CURLOPT_URL, $location);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $request);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);

		$output = '';

		if( $one_way ) 
			url_exec($this->ch);
		else
			$output = curl_exec($this->ch);
		curl_reset($this->ch);

		return $output;
	}
}
