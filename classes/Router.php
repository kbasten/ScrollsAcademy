<?php
	class Router {
		
		private $app;
		
		private $routes = array();
		
		public function __construct(App $app){
			$this->app = $app;
		}
		
		public function match($relUrl){
			foreach ($this->routes as $key => $route){
				if (preg_match($route['match'], $relUrl)){
					echo "Matched with " . $route['match'];
					return new Route($key, $route);
				}
			}
			
			return $this->getRoute("404");
		}
		
		public function getRoute($id){
			foreach ($this->routes as $key => $route){
				if ($key == $id){
					return new Route($key, $route);
				}
			}
			
			return false;
		}
		
		public function addRouteFile($basePath, $filename){
			// check whether route file has already been compiled before
			$cache = $this->app->getCache();
			
			$cachePath = "Routing/" . $filename . ".php";
			$routePath = $basePath . "/" . $filename;
			if ($this->checkCacheValidity($cache, $cachePath, $routePath)){
				// cache is still valid, use this routing file
				
				$this->parseRouteFile($cache->getPathForFile($cachePath));
			} else {
				// cache is not valid anymore, compile routes again
				if (!is_file($routePath)){
					throw new Exception(sprintf("Route file '%s%' not found.", $routePath));
				}
				
				// RouteCompiler adds routes as they are parsed using Router::addRoute
				$routeCompiler = new RouteCompiler($routePath, $this);
				$compiled = $routeCompiler->toPHP($routeCompiler->compile());
				
				// write compiled php code to cache so it can be loaded faster next time
				$cache->save($cachePath, $compiled);
			}
		}
		
		private function parseRouteFile($path){
			require_once $path;
			
			foreach ($routes as $key => $route){
				$this->addRoute($key, $route);
			}
		}
		
		public function addRoute($key, $route){
			$this->routes[$key] = $route;
		}
		
		private function checkCacheValidity($cache, $cachePath, $routePath){
			if ($cache->exists($cachePath)){ // file has been created before
				// check whether route file has been modified since
				return filemtime($cache->getPathForFile($cachePath)) > filemtime($routePath);
			}
			
			return false;
		}
		
	}
	
	class Route {
		
		private $id;
		
		private $data;
		
		public function __construct($id, $data){
			$this->id = $id;
			$this->data = $data;
		}
		
		public function set($key, $d){
			$this->data[$key] = $d;
		}
		
		public function get($key){
			return $this->data[$key];
		}
		
		public function getId(){
			return $this->id;
		}
	}
	
	class RouteCompiler {
	
		private $router;
	
		private $filePath;
	
		public function __construct($path, Router $router){
			$this->router = $router;
			$this->filePath = $path;
		}
		
		public function compile(){
			$json = $this->loadFromFile();
			
			if (!isset($json['routes'])){
				throw new Exception(sprintf("Missing key 'routes' in route file '%s'.", $this->filePath));
			}
			
			$routes = $json['routes'];
			
			foreach ($routes as $key => $route){
				$r = $this->compileIndividual($key, $route);
				$routes[$key] = $r;
				
				$this->router->addRoute($key, $r);
			}
			
			return $routes;
		}
		
		public function toPHP($compiled){
			$total = "<?php \$routes = %s;";
			
			$routes = sprintf($total, $this->compilePHP($compiled));
			
			return $routes;
		}
		
		/**
		 * Recursively write php arrays to string
		 */
		private function compilePHP($item){
			$total = "";
			
			if (is_array($item)){
				$total .= "array(";
			
				$i = 0;
				$len = count($item);
				foreach ($item as $key => $value){
					if ($i > 0){ // first item doesn't need leading comma
						$total .= ",";
					}
					
					$total .= "\"" . $key . "\" => ";
					
					$total .= $this->compilePHP($value);
					$i++;
				}
				$total .= ")";
			} else {
				$total = "\"" . $item . "\"";
			}
			
			return $total;
		}
		
		/**
		 * Rewrites JSON to include regex patterns in route
		 */
		private function compileIndividual($id, $route){
			if (isset($route['requirements'])){
				foreach ($route['requirements'] as $whichKey => $requirement){
					$match = str_replace($this->wrap($whichKey), "(" . $requirement . ")", $route['path']);
					$route['match'] = $this->wrapRegexDelimiter($match);
				}
			} else {
				$route['match'] = $this->wrapRegexDelimiter($route['path']);
			}
			
			return $route;
		}
		
		private function wrap($str){
			return "{" . $str . "}";
		}
		
		private function wrapRegexDelimiter($str){
			$possible = array("#", "~", "&", "%", "@");
			
			$i = 0;
			while (strpos($str, $possible[$i]) !== false){
				$i++;
			}
			
			return sprintf('%2$s^%1$s$%2$s', $str, $possible[$i]);
		}
		
		private function loadFromFile(){
			return json_decode(file_get_contents($this->filePath), true);
		}
		
	}