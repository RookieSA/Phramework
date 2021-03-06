<?php
	/**
	 * PHP-Phrame. A modular PHP framework
	 * 
	 * Copyright (C) 2016 Ruan Lamprecht
	 * 
	 * This file is part of PHP-Phrame.
	 * 
	 * PHP-Phrame is free software: you can redistribute it and/or modify it under the terms of the
	 * GNU General Public License as published by the Free Software Foundation, either version 2 of the
	 * License, or (at your option) any later version.
	 * 
	 * PHP-Phrame is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
	 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
	 * 
	 * See the GNU General Public License for more details. You should have received a copy of the GNU
	 * General Public License along with PHP-Phrame. If not, see <http://www.gnu.org/licenses/>.
	 * 
	 * Authors: TODO
	 */
	 
	namespace Phrame;
	
	require_once("phrame-config.php");
	class phrame {
		
		public function __construct($autoload=true) {
			global $phrame_modules, $phrame_config;
			
			if($autoload):
				$this->autoload();
			endif;
		}
		
		protected function autoload() {
			// iterate through modules' location for present modules
			$modules = glob(LOC_MODULES . '/*' , GLOB_ONLYDIR);
			// Loop through discovered modules
			foreach($modules as $module):
				$module = str_replace(LOC_MODULES, "", $module);
				// Load discovered module
				$this->load_module($module);
			endforeach;
			
		}
		
		public function load_module($module_path) {
			global $phrame_modules, $phrame_config;
			
			/*
			*	First do some housework on the requested module
			*	The procedure is as followed:
			*	- First check if the module path ($module_path) is local or an external URL
			*	- If the module path is external:
			*		- Check if the module already exists
			*		- If the module already exists:
			*			- Check if the auto_update setting in its config file is set to true
			*			- If set to true, download and overwrite the existing module with the newly downloaded one
			*			- If set to false, do nothing
			*       - If the module doesn't exist, download and create the new module
			*	- Continue to load both local and newly downloaded modules
			*/
			
			// Check if $module_path is a URL
			if(filter_var($module_path, FILTER_VALIDATE_URL)):
				// Extract the module name from the ZIP file name
				$module_name = basename($module_path);
				$module_name = preg_replace('/\\.[^.\\s]{3,4}$/', '', $module_name);
				
				// Check if the module already exists
				if(file_exists(LOC_MODULES."/".$module_name)):
					// If the module already exists, see if auto_update in config file is set to true
					$curr_conf_path = realpath(LOC_MODULES."/".$module_name."/config.xml");
					$curr_conf = (array)simplexml_load_file($curr_conf_path);
					
					// If auto_update is true, overwrite existing module with new one
					if($curr_conf["auto_update"] == "true"):
						// Get current module version number
						$curr_version = $curr_conf["version"];
						/* 
						* Pass the version number along with the download request. If the version numbers don't match, proceed to download, else ignore
						* Refer to the load_external_module() method
						*/
						$this->load_external_module($module_path, $curr_version);
					else:
						// If auto_update is false, do nothing
					endif;
				else:
					// If module doesn't exist, download and create the module
					$this->load_external_module($module_path);
				endif;
				
				/*
				*	Re-set the $module_path to the newly created module directory
				*	The newly created directory name matches the zip file name of the remote module; however we need to remove the .zip extension
				*/
				$module_path = basename($module_name);
				$module_path = preg_replace('/\\.[^.\\s]{3,4}$/', '', $module_name);
				
			endif;
			
			/*
			*	Proceed to iterate through and load all modules
			*/
			$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\LOC_MODULES."/".$module_path));
			// Loop through discovered modules
			foreach($rii as $file):
				if($file->isDir()):
					$module["root"] = realpath(\LOC_MODULES."/".$module_path);
					continue;
				endif;
				
				// Get file path
				$fpath = $file->getPathname();
				
				// Get module configuration file
				$config_f_path = realpath($module["root"]."/config.xml");
				if(file_exists($config_f_path) !== false):
					$config = (array)simplexml_load_file($config_f_path);
					$module["config"] = $config;
					$phrame_config = $config;
				else:
					throw new \Exception(sprintf(\ERR_NO_CONFIG_FILE, $module["root"]), 1);
				endif;
				
				// Get module README
				if(strpos(strtolower($fpath), "readme.md") !== false):
					$module["readme"] = mysql_escape_string(file_get_contents($fpath));
				endif;
				
				// Populate module's files
				$module["files"][] = realpath($fpath);
				
				// Get file info
				$finfo = pathinfo($fpath);
				// Get file extension
				$fext = $finfo["extension"];
				// Get file name
				$fname = $finfo["filename"];
				
				// Only pull 'PHP' files, with '.inc.' in filename
				if(strpos($fpath, ".inc.") !== false && $fext == "php"):
					$class_name = strtok($fname,  '.');
					$class = array();
					$class["namespace"] = $config["namespace"]."\\".$class_name;
					$class["name"] = $class_name;
					$class["path"] = realpath($fpath);
					//$module["classes"][] = $class;
					
					// Require class file to initiate new class
					require_once($class["path"]);
					
					// Get qualified namespace of class and create a new instance of the class
					$class_name = $class["namespace"];
					$init_class = new $class_name;
					
					// Create empty function array to populate function structure - see below
					$function = array();
					
					// Iterate through the class' methods
					$methods = get_class_methods($init_class);
					foreach($methods as $method):
						// Pupulate fuction[] array with method's structure
						$function["name"] = $method;
						// Get method's arguments
						$ref_args = new \ReflectionMethod($class_name, $method);
						$ref_args = $ref_args->getParameters();
						// Create array to populate method arguments
						$method_args = array();
						foreach ($ref_args as $arg):
							// Pupulate method argument array
							$method_args[] = $arg->getName();
						endforeach;
						
						// Set current method's argument value
						$function["args"] = $method_args;
						// Create example usage of method instantiation
						if(count($method_args) > 0):
							$function["method"] = $class_name."::".$method."($".implode(", $", $method_args).")";
						else:
							$function["method"] = $class_name."::".$method."()";
						endif;
						// Update $phrame_modules array's methods with the list of functions
						$module["classes"][] = $function;
					endforeach;
					
				endif;
				
				
			endforeach;
			
			$phrame_modules[basename($module_path)] = $module;
		}
		
		// Retreive a list of currently loaded modules
		public function get_modules() {
			global $phrame_modules;
			return $phrame_modules;
		}
		
		// Retreive a specific module
		public function get_module($name) {
			global $phrame_modules;
			return $phrame_modules[$name];
		}
		
		// Retreive a specific module
		public function get_module_classes($name) {
			global $phrame_modules;
			return $phrame_modules[$name]["classes"];
		}
		
		// Retreive a module's methods
		public function get_module_methods($name) {
			global $phrame_modules;
			$methods = array();
			foreach($phrame_modules[$name]["classes"] as $method):
				$methods[] = $method["methods"];
			endforeach;
			return $methods;
		}
		
		// Load external modules from remote provider
		private function load_external_module($url, $cur_version=NULL) {
			
			$tmp_zip = basename($url);
			$r_zip = LOC_MODULES.'/'.$tmp_zip;
			
			// Download remote module and save to local module directory
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$data = curl_exec($ch);
			file_put_contents($r_zip, $data);
			
			// Peek inside the zip archive for config file
			$zip = new \ZipArchive;
			if ($zip->open($r_zip) === TRUE):
				
				// Sanitize the ZIP filename to a valid module name
				$module_root = preg_replace('/\\.[^.\\s]{3,4}$/', '', $tmp_zip);
				
				// Read the config file
				$conf_data = $zip->getFromName("$module_root/config.xml", 0);
				
				// If the config file does not exist, throw an exception
				if(!$conf_data):
					throw new \Exception(sprintf(\ERR_MODULE_INVALID, $tmp_zip), 2);
				else:
					// Create a temp config file to compare with the current config file
					$tmp_config_loc = LOC_MODULES."/".uniqid().".xml";
					$tmp_config = file_put_contents($tmp_config_loc, $conf_data);
					$config = (array)simplexml_load_file($tmp_config_loc);
					
					// Break down the file versioning
					$current_versioning = explode(".", $cur_version);
					$versioning = explode(".", $config["version"]);
					
					// Check if the versioning is valid
					if(count($versioning) != 3):
						throw new \Exception(\ERR_CONFIG_VERSION_INVALID, 2);
					else:
						// Current config file version
						$cur_ver_major = $current_versioning[0];
						$cur_ver_minor = $current_versioning[1];
						$cur_ver_patch = $current_versioning[2];
						
						// New config file version
						$ver_major = $versioning[0];
						$ver_minor = $versioning[1];
						$ver_patch = $versioning[2];
						
						// Match the current and new config file versions and overwrite existing module if newer
						if($ver_major > $cur_ver_major || $ver_minor > $cur_ver_minor || $ver_patch > $cur_ver_patch):
							$zip->extractTo(realpath(LOC_MODULES));
							// Delete the temp config file
							unlink($tmp_config_loc);
						endif;
					
					endif;
					
				endif;
				
				$zip->close();
				unlink($r_zip);
			else:
				throw new \Exception(sprintf(\ERR_MODULE_EXTRACT, $tmp_zip), 3);
			endif;
		}
	}
