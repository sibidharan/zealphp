<?php
namespace ZealPHP;
class REST {

    /** @var array<int|string, mixed> */
    public array $_allow = array();
    public string $_content_type = "application/json";
    /** @var mixed */
    public $_request = array();

    private string $_method = "";
    private int $_code = 200;
    /** @var mixed */
    public $_response;
    /**
     * @param mixed $request
     * @param mixed $response
     */
    public function __construct($request, $response){
        $this->_response = RequestContext::instance()->zealphp_response;
        $this->_request = RequestContext::instance()->zealphp_request;
        $this->inputs();
    }

    public function get_referer(): mixed {
        return $this->serverValue('HTTP_REFERER');
    }

    /**
     * @param mixed $data
     * @param int|null $status
     */
    public function response($data, $status): void {
        $this->_code = ($status)?$status:200;
        $this->setHeaders();
        $this->_response->status($this->_code);
        echo $data;
    }

    public function get_request_method(): mixed {
        return $this->serverValue('REQUEST_METHOD', 'GET');
    }

    private function inputs(): void {
        $getData = $this->requestValues('get');
        $postData = $this->requestValues('post');

        switch($this->get_request_method()){
            case "POST":
                $this->_request = $this->cleanInputs(array_merge($getData, $postData));
                break;
            case "GET":
                $this->_request = $this->cleanInputs($getData);
                break;
            case "DELETE":
                $this->_request = $this->cleanInputs($getData);
                break;
            case "PUT":
                parse_str(file_get_contents("php://input"),$this->_request);
                $this->_request = $this->cleanInputs($this->_request);
                break;
            default:
                $this->response('',406);
                break;
        }
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    private function serverValue($key, $default = null){
        $server = RequestContext::instance()->server;
        if (!is_array($server)) {
            return $default;
        }
        return $server[$key] ?? $default;
    }

    /**
     * @param string $key
     * @return array<int|string, mixed>
     */
    private function requestValues($key): array {
        $value = RequestContext::instance()->$key;
        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function cleanInputs($data){
        $clean_input = array();
        if(is_array($data)){
            foreach($data as $k => $v){
                $clean_input[$k] = $this->cleanInputs($v);
            }
        }else{
            //$data = mysqli_real_escape_string(Database::getConnection(), $data);
            //$data = trim(stripslashes($data)); //This reverses the effect of mysqli_real_escape_string so dont use this unless you know what you are doing.
            $data = strip_tags($data);
            $clean_input = trim($data);
        }
        return $clean_input;
    }

    private function setHeaders(): void {
       $this->_response->header("Content-Type",$this->_content_type);
    }

    /**
     * @param string $type
     */
    public function setContentType($type): void {
        $this->_content_type = $type;
    }
}
