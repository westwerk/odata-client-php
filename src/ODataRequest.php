<?php

namespace SaintSystems\OData;

use SaintSystems\OData\Exception\ODataException;

/**
 * The base request class.
 */
class ODataRequest implements IODataRequest
{
    /**
     * The URL for the request
     *
     * @var string
     */
    protected $requestUrl;

    /**
     * An array of headers to send with the request
     *
     * @var array(string => string)
     */
    protected $headers;

    /**
     * The body of the request (optional)
     *
     * @var string
     */
    protected $requestBody;

    /**
     * The type of request to make ("GET", "POST", etc.)
     *
     * @var object
     */
    protected $method;

    /**
     * True if the response should be returned as
     * a stream
     *
     * @var bool
     */
    protected $returnsStream;

    /**
     * The return type to cast the response as
     *
     * @var object
     */
    protected $returnType;

    /**
     * @var IODataClient
     */
    private $client;

    /**
     * Constructs a new ODataRequest object
     * @param string       $method     The HTTP method to use, e.g. "GET" or "POST"
     * @param string       $requestUrl The URL for the OData request
     * @param IODataClient $client     The ODataClient used to make the request
     * @param [type]       $returnType Optional return type for the OData request (defaults to Entity)
     *
     * @throws ODataException
     */
    public function __construct(
        $method,
        $requestUrl,
        IODataClient $client,
        $returnType = null
    ) {
        $this->method = $method;
        $this->requestUrl = $requestUrl;
        $this->client = $client;
        $this->setReturnType($returnType);

        if (empty($this->requestUrl)) {
            throw new ODataException(Constants::REQUEST_URL_MISSING);
        }
        $this->headers = $this->getDefaultHeaders();
    }

    /**
     * Sets the return type of the response object
     *
     * @param mixed $returnClass The object class to use
     *
     * @return ODataRequest object
     */
    public function setReturnType($returnClass)
    {
        if (is_null($returnClass)) return $this;
        $this->returnType = $returnClass;
        if (strcasecmp($this->returnType, 'stream') == 0) {
            $this->returnsStream  = true;
        } else {
            $this->returnsStream = false;
        }
        return $this;
    }

    /**
     * Adds custom headers to the request
     *
     * @param array $headers An array of custom headers
     *
     * @return ODataRequest object
     */
    public function addHeaders($headers)
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * Get the request headers
     *
     * @return array of headers
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Attach a body to the request. Will JSON encode
     * any SaintSystems\OData\Entity objects as well as arrays
     *
     * @param mixed $obj The object to include in the request
     *
     * @return ODataRequest object
     */
    public function attachBody($obj)
    {
        // Attach streams & JSON automatically
        if (is_string($obj) || is_a($obj, 'GuzzleHttp\\Psr7\\Stream')) {
            $this->requestBody = $obj;
        }
        // JSON-encode the model object's property dictionary
        else if (method_exists($obj, 'getProperties')) {
            $class = get_class($obj);
            $class = explode("\\", $class);
            $model = strtolower(end($class));

            $body = $this->flattenDictionary($obj->getProperties());
            $this->requestBody = "{" . $model . ":" . json_encode($body) . "}";
        }
        // By default, JSON-encode (i.e. arrays)
        else {
            $this->requestBody = json_encode($obj);
        }
        return $this;
    }

    /**
     * Get the body of the request
     *
     * @return mixed request body of any type
     */
    public function getBody()
    {
        return $this->requestBody;
    }

    /**
     * Executes the HTTP request using Guzzle
     *
     * @throws ODataException if response is invalid
     *
     * @return ODataResponse
     */
    public function execute()
    {
        if (empty($this->requestUrl))
        {
            throw new ODataException(Constants::REQUEST_URL_MISSING);
        }

        $request = $this->getHttpRequestMessage();
        $request->body = $this->requestBody;

        $this->authenticateRequest($request);

        $result = $this->client->getHttpProvider()->send($request);

        //Send back the bare response
        if ($this->returnsStream) {
            return $result;
        }

        if (strpos($this->requestUrl, '/$count') !== false) {
            return $result->getBody()->getContents();
        }

        // Wrap response in ODataResponse layer
        try {
            $response = new ODataResponse(
                $this,
                $result->getBody()->getContents(),
                $result->getStatusCode(),
                $result->getHeaders()
            );
        } catch (\Exception $e) {
            throw new ODataException(Constants::UNABLE_TO_PARSE_RESPONSE);
        }

        return $response;
    }

    /**
     * Get a list of headers for the request
     *
     * @return array The headers for the request
     */
    private function getDefaultHeaders()
    {
        $headers = [
            //RequestHeader::HOST => $this->client->getBaseUrl(),
            RequestHeader::CONTENT_TYPE => 'application/json',
            RequestHeader::ODATA_MAX_VERSION => Constants::MAX_ODATA_VERSION,
            RequestHeader::ODATA_VERSION => Constants::ODATA_VERSION,
//            RequestHeader::PREFER => Preference::RETURN_REPRESENTATION,
            RequestHeader::USER_AGENT => 'odata-sdk-php-' . Constants::SDK_VERSION,
            //RequestHeader::AUTHORIZATION => 'Bearer ' . $this->accessToken
        ];
        return $headers;
    }

    /**
     * Gets the <see cref="HttpRequestMessage"/> representation of the request.
     *
     * <returns>The <see cref="HttpRequestMessage"/> representation of the request.</returns>
     */
    public function getHttpRequestMessage()
    {
        $request = new HttpRequestMessage(new HttpMethod($this->method), $this->requestUrl);

        $this->addHeadersToRequest($request);

        return $request;
    }

    /**
     * Adds all of the headers from the header collection to the request.
     * @param HttpRequestMessage $request The HttpRequestMessage representation of the request.
     */
    private function addHeadersToRequest(HttpRequestMessage $request)
    {
        $request->headers = array_merge($this->headers, $request->headers);
    }

    /**
     * Adds the authentication header to the request.
     *
     * @param HttpRequestMessage $request The representation of the request.
     *
     * @return
     */
    private function authenticateRequest(HttpRequestMessage $request)
    {
        $authenticationProvider = $this->client->getAuthenticationProvider();
        if ( ! is_null($authenticationProvider)) {
            return $authenticationProvider->authenticateRequest($request);
        }
    }

    /**
     * Flattens the property dictionaries into
     * JSON-friendly arrays
     *
     * @param mixed $obj the object to flatten
     *
     * @return array flattened object
     */
    protected function flattenDictionary($obj) {
        foreach ($obj as $arrayKey => $arrayValue) {
            if (method_exists($arrayValue, 'getProperties')) {
                $data = $arrayValue->getProperties();
                $obj[$arrayKey] = $data;
            } else {
                $data = $arrayValue;
            }
            if (is_array($data)) {
                $newItem = $this->flattenDictionary($data);
                $obj[$arrayKey] = $newItem;
            }
        }
        return $obj;
    }
}
