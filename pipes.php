<?php

namespace pipes;

/// Return the current options hash
function options($newOptions=null) {
    static $options;
    if (!isset($options) || isset($newOptions))
        $options = $newOptions ?: new Hash();
    return $options;
}

/// Include a PHP file if it exists. Values in $context will be extracted
/// into the file's local context, if specified.
function php($filename, $context=array()) {
    if (file_exists($filename)) {
        extract($context instanceof Hash ? $context->toArray() : $context);
        include $filename;
        return true;
    }
    else
        return false;
}

/// Like php(), but assumes path is relative to the views folder, and
/// returns the output as a string. Returns false if no file was found.
function render($template, $locals=array()) {
    $context = new Hash(array(
        'response' => response(),
        'request' => request(),
        'route' => route(),
    ));
    $context->params = $context->request->params;
    $context->merge($locals);
    $views = options()->get('views', __DIR__.'/views');
    $filename = realpath("{$views}/{$template}");
    ob_start();
    $included = php($filename, $context);
    $output = ob_get_clean();
    return $included ? $output : false;
}

/// Find the matching route and invoke its function
function run($options=array()) {
    $options = options()->merge($options);
    $request = request();
    $response = response();
    foreach (routes() as $route) {
        if ($route->matches($request, $matches)) {
            route($route);
            $path = isset($matches['path']) ? $matches['path'] : $request->path;
            $data = $route->run($path, $matches);
            if ($data)
                $response->write($data);
            if ($options->get('flush', true))
                $response->flush();
            return $route;
        }
    }
    return null;
}

// Kills the current route and returns the response immediately. It can
// optionally be passed a parameter to use as the response.
//
// pipes\halt();
// pipes\halt(404);             // replaces the status of the response
// pipes\halt('OHNOES!');       // replaces the body of the response
// pipes\halt(404, 'OHNOES!');  // replaces both the status and the body
function halt() {
    $args = func_get_args();
    throw new HaltException($args);
}

// This is thrown by halt() to break out to the route handler.
class HaltException extends \Exception {
    function __construct($args=null) {
        if (!empty($args)) {
            $response = response();
            if (is_int($args)) {
                $response->status = $args;
            } else if (is_array($args)) {
                $response->status = $args[0];
                $response->length = strlen($args[1]);
                $response->body = array($args[1]);
            } else if (is_string($args)) {
                $response->body = array($args[1]);
            } else if ($value instanceof Response) {
                response($args);
            }
        }
    }
}

// Sets the appropriate headers to redirect the user, then halts the request.
function redirect($url, $status=302) {
    $response = response();
    $response->status = $status;
    $response->headers['Location'] = $url;
    halt();
}

/// Return the route that matches the current request
function route($newRoute=null) {
    static $route;
    if (isset($newRoute))
        $route = $newRoute;
    return $route;
}

/// Return the array of defined routes
function &routes($newRoutes=null) {
    static $routes = array();
    if (isset($newRoutes))
        $routes = $newRoutes;
    return $routes;
}

/// Define a new route for any HTTP method
function any($pattern, $options) {
    $route = new Route($pattern, $options);
    array_push(routes(), $route);
    return $route;
}

/// Define a new route, limited to the DELETE HTTP method.
function delete($pattern, $options) {
    $route = any($pattern, $options);
    $route->options->method = 'DELETE';
    return $route;
}

/// Define a new route, limited to the GET HTTP method.
function get($pattern, $options) {
    $route = any($pattern, $options);
    $route->options->method = 'GET';
    return $route;
}

/// Define a new route, limited to the POST HTTP method.
function post($pattern, $options) {
    $route = any($pattern, $options);
    $route->options->method = 'POST';
    return $route;
}

/// Define a new route, limited to the PUT HTTP method.
function put($pattern, $options) {
    $route = any($pattern, $options);
    $route->options->method = 'PUT';
    return $route;
}

/// Describes an individual route handler.
class Route {
    function __construct($pattern, $options) {
        $this->pattern = null;
        $this->rawPattern = $pattern;
        $this->options = new Hash();
        if (is_callable($options) && is_object($options))
            $options = array('callback'=>$options);
        $this->options->merge($options);
        $this->options->paths = (array)$this->options->get('paths', $this->options->path);
        $this->options->delete('path');
        if (empty($this->options->paths) && empty($this->options->callback))
            throw new \Exception('paths or callback required for route');
    }
    
    /// Compiles the route pattern into an actual regex.
    function compile() {
        $this->pattern = preg_replace('/:(\w+)/', '(?<$1>\w+)', $this->rawPattern);
        $this->pattern = '/^'.str_replace('/', '\\/', $this->pattern.'/?').'$/';
        return $this->pattern;
    }
    
    /// Returns true if this route matches the given request.
    function matches($request, &$matches=null) {
        if ($this->options->method && $request->method !== $this->options->method)
            return false;
        if (is_null($this->pattern))
            $this->compile();
        return (boolean)preg_match($this->pattern, $request->path, $matches);
    }
    
    /// Executes the appropriate handler, either invoking a callback function or
    /// including a PHP file.
    function run($path, $matches) {
        $request = request();
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                if (!isset($request->params->$key))
                    $request->params->$key = $value;
                unset($matches[$key]);
            }
        }
        try {
            $request->params->captures = array_slice($matches, 1);
            if ($this->options->callback)
                return $this->runCallback(array($request->params, $path));
            else if ($this->options->paths)
                return $this->runPaths($path);
            else
                throw new \Exception('paths or callback required for route');
        } catch (HaltException $err) {
            // pass
        }
    }
    
    function runCallback($args) {
        return call_user_func_array($this->options->callback, $args);
    }
    
    /// Iterates over the paths supplied in the route options and includes any
    /// PHP files that match the request route path.
    function runPaths($tail) {
        $buffer = '';
        $context = new Hash(array(
            'response' => response(),
            'request' => request(),
            'route' => $this
        ));
        $context->params = $context->request->params;
        $included = false;
        $this->bubble = $this->options->get('bubble', true);
        foreach ($this->options->paths as $path) {
            $filename = "{$path}/{$tail}.php";
            $filename = realpath($filename);
            if (!$filename) {
                continue;
            }
            if (strncmp($filename, $path, strlen($path)) !== 0)
                throw new \Exception("route tried to access unsafe path");
            ob_start();
            if (php($filename, $context))
                $included = true;
            $buffer .= ob_get_clean();
            if (!$this->bubble)
                break;
        }
        if (!$included)
            throw new \Exception("no matching files in route paths");
        return $buffer;
    }    
}

/// Return the current Request object
function request($newRequest=null) {
    static $request;
    if (!isset($request) || isset($newRequest))
        $request = $newRequest ?: new Request();
    return $request;
}

/// Wraps the HTTP request information (URI, method, params).
class Request {
    function __construct($uri=null, $method=null, $params=null) {
        $this->server = new Hash();
        $this->server->merge($_SERVER);
        
        // set uri, method, and params from $_SERVER and $_REQUEST
        $this->uri = $uri ?: $this->server->get('REQUEST_URI', '/');
        $this->params = new Hash();
        $this->params->merge($params ?: $_REQUEST);
        $this->method = $method ?: $this->server->get('REQUEST_METHOD', 'GET');
        $this->method = strtoupper($this->method);
        if ($this->method === 'POST' && options()->get('requestMethodOverride', true)) {
            $method = strtoupper($this->params->get('_method', ''));
            if (in_array($method, array('DELETE', 'GET', 'PUT', 'POST')))
                $this->method = $method;
        }
        
        // strip basePath from uri, if it is set and matches
        $basePath = rtrim(options()->get('requestBasePath', ''), '/');
        if ($basePath && strpos($this->uri, $basePath) === 0)
            $this->uri = substr($this->uri, strlen($basePath)) ?: '/';
        
        // split the uri into route and format
        $info = pathinfo($this->uri);
        $this->path = rtrim($info['dirname'], '/').'/'.$info['filename'];
        if (!empty($info['extension']))
            $this->params->format = strtolower($info['extension']);
        else if (!$this->params->format)
            $this->params->format = 'html';
        $this->format = $this->params->format;
    }
    
    /// Returns true if this is an AJAX request.
    function ajax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }
}

/// Return the current Response object
function response($newResponse=null) {
    static $response;
    if (!isset($response) || isset($newResponse))
        $response = $newResponse ?: new Response();
    return $response;
}

/// Wraps the HTTP response, buffering output and headers until flushed.
class Response {
    function __construct() {
        $this->body = array();
        $this->headers = new Hash();
        $this->status = null;
        $this->length = 0;
    }
    
    /// Send the headers, then echo the body.
    function flush() {
        if (!is_null($this->status))
            header('.', true, $this->status);
        foreach ($this->headers as $key => $value)
            header("$key: $value");
        echo implode("", $this->body);
    }
    
    /// Append a string to the body.
    function write($string) {
        $string = (string)$string;
        $this->body[] = $string;
        $this->length += strlen($string);
    }
}

/// Extended array that tries to pretend it's a Ruby hash.
class Hash extends \ArrayObject {
    function __construct($values=array()) {
        parent::__construct($values, \ArrayObject::ARRAY_AS_PROPS);
    }
    
    function delete($key, $defaultValue=null) {
        $value = $this->get($key, $defaultValue);
        if (isset($this->$key))
            unset($this->$key);
        return $value;
    }
    
    function get($key, $defaultValue=null) {
        return parent::offsetExists($key) ?
               parent::offsetGet($key) : $defaultValue;
    }
    
    function merge($values) {
        foreach ($values as $key => $value)
            $this[$key] = $value;
        return $this;
    }

    function offsetGet($key) {
        return $this->get($key);
    }
    
    function toArray() {
        return $this->getArrayCopy();
    }    
}

